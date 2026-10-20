"""FastAPI wrapper around the pipeline. Stateless: Laravel owns persistence.

    uvicorn app.api.main:app --port 8001
"""

import base64
import binascii
import hmac
import logging

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse

from app.api.schemas import (
    AnalyzeJobRequest,
    AnalyzeJobResponse,
    GenerateApplicationRequest,
    ReplyAnalyzeRequest,
    ReplyDraftRequest,
    ResumeReviewRequest,
)
from app.config import get_settings
from app.documents.extract import UnsupportedDocumentError, load_resume_bytes
from app.llm.client import GeminiClient, LLMClient, LLMOutputError
from app.models.application import MatchPreview, PipelineResult
from app.pipeline import run_match, run_pipeline
from app.pipelines.job_analysis import analyze_job
from app.prompts import job_analysis as job_analysis_prompt
from app.models.reply import ReplyAnalysis, ReplyDraft
from app.pipelines.replies import analyze_reply, draft_reply
from app.models.resume_review import ResumeReview
from app.pipelines.resume_review import review_resume
from app.rules.loader import load_rules
from app.rules.validator import RulesError

logger = logging.getLogger(__name__)

app = FastAPI(title="JobHunter AI Service", version="0.1.0")


def require_token(x_service_token: str | None = Header(default=None)) -> None:
    expected = get_settings().ai_service_token
    if not expected:
        raise HTTPException(status_code=503, detail="AI_SERVICE_TOKEN is not configured")
    if not x_service_token or not hmac.compare_digest(x_service_token, expected):
        raise HTTPException(status_code=401, detail="Invalid service token")


def get_llm() -> LLMClient:
    settings = get_settings()
    if not settings.gemini_api_key:
        raise HTTPException(status_code=503, detail="GEMINI_API_KEY is not configured")
    return GeminiClient(settings.gemini_api_key, settings.gemini_model)


@app.exception_handler(LLMOutputError)
def _llm_error(_: Request, exc: LLMOutputError) -> JSONResponse:
    logger.warning("LLM output error: %s", exc)
    return JSONResponse(status_code=502, content={"detail": "The AI model returned invalid output; try again."})


@app.exception_handler(UnsupportedDocumentError)
@app.exception_handler(RulesError)
def _input_error(_: Request, exc: Exception) -> JSONResponse:
    return JSONResponse(status_code=422, content={"detail": str(exc)})


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


@app.post("/v1/jobs/analyze", response_model=AnalyzeJobResponse, dependencies=[Depends(require_token)])
def analyze(request: AnalyzeJobRequest, llm: LLMClient = Depends(get_llm)) -> AnalyzeJobResponse:
    analysis, warnings = analyze_job(request.job, llm)
    return AnalyzeJobResponse(
        analysis=analysis, warnings=warnings, model=llm.model, prompt_version=job_analysis_prompt.PROMPT_VERSION
    )


def _resumes(files) -> list:
    resumes = []
    for file in files:
        try:
            content = base64.b64decode(file.content_base64, validate=True)
        except (binascii.Error, ValueError):
            raise HTTPException(status_code=422, detail=f"Resume {file.id} is not valid base64")
        resumes.append(load_resume_bytes(file.id, file.filename, content))
    return resumes


@app.post("/v1/jobs/match", response_model=MatchPreview, dependencies=[Depends(require_token)])
def match_job(request: GenerateApplicationRequest, llm: LLMClient = Depends(get_llm)) -> MatchPreview:
    """Job analysis + fit against the profile and resumes. Nothing is drafted."""
    return run_match(request.job, request.profile, _resumes(request.resumes), llm, user_rules=request.user_rules)


@app.post("/v1/applications/generate", response_model=PipelineResult, dependencies=[Depends(require_token)])
def generate(request: GenerateApplicationRequest, llm: LLMClient = Depends(get_llm)) -> PipelineResult:
    return run_pipeline(
        request.job, request.profile, _resumes(request.resumes), llm,
        user_rules=request.user_rules, stored_analysis=request.job_analysis,
    )


@app.post("/v1/replies/analyze", response_model=ReplyAnalysis, dependencies=[Depends(require_token)])
def analyze_reply_endpoint(request: ReplyAnalyzeRequest, llm: LLMClient = Depends(get_llm)) -> ReplyAnalysis:
    """Classify an employer's reply and pull out what they ask for (with quotes as evidence)."""
    result = analyze_reply(request.job, request.sent_body, request.reply_text, llm)
    return result.model_copy(update={"usage": llm.usage})


@app.post("/v1/replies/draft", response_model=ReplyDraft, dependencies=[Depends(require_token)])
def draft_reply_endpoint(request: ReplyDraftRequest, llm: LLMClient = Depends(get_llm)) -> ReplyDraft:
    """Draft the candidate's answer. Missing facts come back as [[placeholders]] to fill in."""
    rules, _, _ = load_rules(request.country, user_rules=request.user_rules)
    draft = draft_reply(
        request.job, request.profile, request.sent_subject, request.sent_body, request.reply_text,
        ReplyAnalysis.model_validate(request.analysis), request.notes, rules, llm,
    )
    return draft.model_copy(update={"usage": llm.usage})


@app.post("/v1/resumes/review", response_model=ResumeReview, dependencies=[Depends(require_token)])
def review_resume_endpoint(request: ResumeReviewRequest, llm: LLMClient = Depends(get_llm)) -> ResumeReview:
    """Checklist against the course norms plus a few AI suggestions. Suggestions only; the CV is not changed."""
    rules, _, _ = load_rules(None, user_rules=request.user_rules)
    return review_resume(_resumes([request.resume])[0], rules.cv_rules, llm, request.candidate_name)
