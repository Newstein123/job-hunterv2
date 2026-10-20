"""Resume review: course-norm checklist (deterministic) + up to 5 AI suggestions (quotes verified).

The checklist reflects what the Career Coaching Center's sample resumes include (measured locally on 70 of
them); the file-name check is general good practice. Nothing here edits the CV.
"""

import re
from pathlib import Path

from app.llm.client import LLMClient
from app.models.resume import Resume
from app.models.resume_review import CheckItem, ResumeReview, ResumeSuggestionsLLM, Suggestion
from app.pipelines.evidence import _norm
from app.prompts import resume_review as prompt
from app.rules.models import CVRules

COURSE_MAX_PAGES = 2
MIN_READABLE_WORDS = 50
MAX_SUMMARY_WORDS = 150
MAX_TEXT_CHARS = 8000

_HEADINGS = re.compile(
    r"^[^\w\n]*(?:(?:career|professional|personal|work|employment|technical|key|core|academic|educational|other)\s+)?"
    r"(summary|profile|objective|about|experience|history|education|qualifications?|skills|expertise|competencies|"
    r"languages?|info|information|details|references?|declaration|projects|certifications?|trainings?|achievements?|"
    r"responsibilities|strengths|interests|hobbies)\b[^\n]{0,25}$",
    re.I | re.M,
)
_SUMMARY_HEADING = re.compile(
    r"^[^\w\n]*(?:(?:career|professional|personal|executive)\s+)?(summary|profile|objective|about(?: me)?)\s*:?\s*$", re.I | re.M)
_RANGE = re.compile(r"((?:19|20)\d{2})\)?\s*(?:\([a-z]+\))?\s*(?:[-–—‐~∼]+|to)\s*(?:\(?[a-z]+,?\)?\s*)?\(?((?:19|20)\d{2}|present|current|now|till date|to date)", re.I)
_EDUCATION = re.compile(r"universit|college|diploma|degree|bachelor|master|school|course|education|institute|academy|b\.?sc|b\.?e\b|b\.?a\b", re.I)
_JOB_TITLE = re.compile(r"developer|engineer|teacher|manager|assistant|intern|officer|executive|surveyor|accountant|technician|"
                        r"analyst|designer|supervisor|coordinator|drafter|draftsman|consultant|specialist|lead|director|operator", re.I)
_LEVELS = r"(native|mother tongue|fluent|proficient|advanced|upper[- ]intermediate|intermediate|basic|elementary|conversational|good|excellent|fair)"
_LANGUAGE_LEVEL = re.compile(
    r"\b(english|burmese|myanmar|thai|chinese|mandarin|cantonese|malay|bahasa|hindi|tamil|arabic|japanese|korean|french)\b"
    r"[^\n]{0,20}?\b" + _LEVELS + r"\b", re.I)
_FILE_JUNK = re.compile(r"copy|\(\d+\)|\bfinal\b|\bupdated?\b|\bdraft\b|\bnew\b|\bcompressed\b|\bccb\s*\d+|\b\d{1,2}[.\-]\d{1,2}[.\-]\d{2,4}\b|\bv\d+\b", re.I)


def _line(text: str, match: re.Match | None) -> str | None:
    if not match:
        return None
    start = text.rfind("\n", 0, match.start()) + 1
    end = text.find("\n", match.end())
    return text[start: end if end != -1 else len(text)].strip()[:140] or None


def _check(id_: str, label: str, pattern: str | re.Pattern, text: str, missing: str, flags=re.I) -> CheckItem:
    match = (pattern if isinstance(pattern, re.Pattern) else re.compile(pattern, flags)).search(text)
    return CheckItem(id=id_, label=label, status="pass" if match else "missing",
                     detail="" if match else missing, evidence=_line(text, match))


def _summary_words(text: str) -> int | None:
    """Words in the summary paragraph: from its heading to the next heading or the first blank line after it."""
    heading = _SUMMARY_HEADING.search(text)
    if not heading:
        return None
    nxt = _HEADINGS.search(text, heading.end())
    body = text[heading.end(): nxt.start() if nxt else len(text)].strip()
    paragraph = re.split(r"\n\s*\n", body, maxsplit=1)[0]
    return len(paragraph.split())


def _job_ranges(text: str) -> list[tuple[str, str]]:
    """Date ranges of jobs: skips ones beside education (a degree's 2016–2020 isn't a job) unless the line names a role."""
    ranges = []
    for m in _RANGE.finditer(text):
        line_start = text.rfind("\n", 0, m.start()) + 1
        context_start = text.rfind("\n", 0, max(0, text.rfind("\n", 0, max(0, line_start - 1)))) + 1
        line = text[line_start: m.end()]
        if _EDUCATION.search(text[context_start: m.end()]) and not _JOB_TITLE.search(line):
            continue
        ranges.append((m.group(1), m.group(2)))
    return ranges


def _file_name_check(filename: str, candidate_name: str | None) -> CheckItem:
    stem = Path(filename).stem
    words = [w for w in re.split(r"[\s_\-.]+", (candidate_name or "").lower()) if len(w) > 1]
    flat = re.sub(r"[^a-z]", "", stem.lower())
    has_name = bool(words) and all(w in flat for w in words)
    junk = _FILE_JUNK.search(stem)
    example = (re.sub(r"\s+", "_", candidate_name.strip()) if candidate_name else "Your_Name") + "_Job_Title.pdf"
    if junk:
        return CheckItem(id="file_name", label="File name", status="check", evidence=filename,
                         detail=f"Employers see the attachment's name. Remove \"{junk.group(0).strip()}\"; "
                                f"use your name and the role, e.g. {example}")
    if candidate_name and not has_name:
        return CheckItem(id="file_name", label="File name", status="check", evidence=filename,
                         detail=f"Put your name in the file name so HR can find it later, e.g. {example}")
    return CheckItem(id="file_name", label="File name", status="pass", evidence=filename)


def is_readable(resume: Resume) -> bool:
    return len(resume.text.split()) >= MIN_READABLE_WORDS


UNREADABLE = CheckItem(
    id="readable", label="Text can be read", status="missing", group="ats",
    detail="Almost no text could be read from this file. It is probably a scanned image or a picture saved as "
           "PDF; employers' application systems can't read it either. Export it again as a text PDF from Word or Canva.",
)


def run_checks(resume: Resume, cv_rules: CVRules, candidate_name: str | None = None) -> list[CheckItem]:
    if not is_readable(resume):
        return [UNREADABLE, _file_name_check(resume.filename, candidate_name)]
    # Designer CVs space out headings ("P R O F I L E"); join them so headings are recognised.
    text = re.sub(r"\b(?:[A-Za-z] ){2,}[A-Za-z]\b", lambda m: m.group(0).replace(" ", ""), resume.text.replace("\u00a0", " "))
    checks = [
        _check("email", "Email address", r"[\w.+-]+@[\w-]+\.[\w.-]+", text, "Add your email address."),
        _check("phone", "Phone with country code", r"\+\s?\d{1,3}[\s\d()-]{6,}", text,
               "Write your phone with the country code, e.g. +95 9 …, so employers abroad can call."),
    ]

    whatsapp = re.search(r"whats\s?app", text, re.I)
    other_app = re.search(r"\b(line|viber|telegram)\b", text, re.I)
    checks.append(CheckItem(
        id="whatsapp", label="WhatsApp number",
        status="pass" if whatsapp else ("check" if other_app else "missing"),
        evidence=_line(text, whatsapp or other_app),
        detail="" if whatsapp else ("Employers abroad mostly use WhatsApp; mark which number has it."
                                    if other_app else "Mark the number that has WhatsApp, e.g. +95 9 … (WhatsApp)."),
    ))

    dob = re.search(r"date of birth|\bd\.?o\.?b\b|born on", text, re.I)
    nationality = re.search(r"nationality", text, re.I)
    checks.append(CheckItem(
        id="personal_info", label="Personal info (date of birth, nationality)",
        status="pass" if dob and nationality else "missing", evidence=_line(text, dob or nationality),
        detail="" if dob and nationality else "Add a Personal info section with date of birth and nationality, as the course resumes do.",
    ))
    checks += [
        _check("passport", "Passport", r"passport", text, "Add your passport number or status; employers abroad need it for the work pass."),
        _check("salary", "Expected salary", r"expected salary|salary expectation|expected pay", text,
               "Add your expected salary (e.g. 2,500 SGD, negotiable), as the course resumes do."),
        _check("availability", "Availability", r"\bavailab(le|ility)\b|notice period|can start", text,
               "Say when you can start, e.g. Availability: Immediately."),
    ]

    lang = re.search(r"\blanguages?\b", text, re.I) or re.search(r"\benglish\b", text, re.I)
    level = _LANGUAGE_LEVEL.search(text)
    checks.append(CheckItem(
        id="languages", label="Languages with levels",
        status="pass" if lang and level else ("check" if lang else "missing"), evidence=_line(text, level or lang),
        detail="" if lang and level else ("Add a level to each language, e.g. English (Intermediate)."
                                          if lang else "Add a Languages section with levels, e.g. English (Intermediate)."),
    ))

    words = _summary_words(text)
    checks.append(CheckItem(
        id="summary", label=f"Short summary (≤{MAX_SUMMARY_WORDS} words)",
        status="missing" if words is None else ("check" if words > MAX_SUMMARY_WORDS else "pass"),
        evidence=_line(text, _SUMMARY_HEADING.search(text)),
        detail="Add a short summary at the top: who you are, years of experience, main skills."
        if words is None else (f"Your summary is about {words} words; keep it under {MAX_SUMMARY_WORDS}." if words > MAX_SUMMARY_WORDS else ""),
    ))

    ranges = _job_ranges(text)
    starts = [int(s) for s, _ in ranges]
    ordered = starts == sorted(starts, reverse=True)
    checks.append(CheckItem(
        id="dates", label="Experience dates, newest first",
        status="missing" if not ranges else ("pass" if ordered else "check"),
        evidence=", ".join(f"{s}–{e}" for s, e in ranges[:4]) or None,
        detail="Add start and end dates to each job." if not ranges else
        ("" if ordered else "List your most recent job first (two-column PDFs can also scramble this; check the order)."),
    ))

    max_pages = cv_rules.max_pages or COURSE_MAX_PAGES
    long = (resume.page_count or 0) > max_pages or (resume.page_count is None and len(text.split()) > 1000)
    checks.append(CheckItem(
        id="length", label=f"Length (about {max_pages} pages)", status="check" if long else "pass",
        evidence=f"{resume.page_count} page(s)" if resume.page_count else f"{len(text.split())} words",
        detail=f"Keep it to {max_pages} pages; HR skims the first page." if long else "",
    ))
    checks.append(_check("declaration", "Declaration", r"declar|hereby|true and correct|best of my knowledge|yours sincerely", text,
                         "End with a short declaration and your name, as the course resumes do."))

    checks += _ats_checks(resume, text)

    preferred = cv_rules.preferred_formats or ["pdf"]
    checks.append(CheckItem(
        id="format", label="PDF format", group="ats", status="pass" if resume.format in preferred else "check",
        evidence=resume.format.upper(),
        detail="" if resume.format in preferred else "Send a PDF so the layout looks the same on every computer.",
    ))
    checks.append(_file_name_check(resume.filename, candidate_name))
    return checks


# Verbs a rephrasing may introduce; any other new content word counts as a possible new claim.
_ACTION_VERBS = set("""achieved administered analysed analyzed assisted built calculated checked collaborated completed
coordinated created delivered designed developed drafted estimated evaluated handled implemented improved inspected
maintained measured monitored negotiated organised organized performed planned prepared prepared produced provided
reduced reviewed supervised supported tracked trained verified wrote""".split())
MAX_NEW_WORDS = 3


def _new_words(example: str, cv_text: str) -> list[str]:
    cv = set(re.findall(r"[a-z]+", cv_text.lower()))
    stems = {w[:6] for w in cv}
    return [w for w in re.findall(r"[a-z]+", example.lower())
            if len(w) >= 5 and w not in cv and w[:6] not in stems and w not in _ACTION_VERBS]


_STANDARD_SECTIONS = {
    "Work Experience": r"experience|employment|work history|career history",
    "Education": r"education|qualifications?|academic",
    "Skills": r"skills|competenc",
}


def _ats_checks(resume: Resume, text: str) -> list[CheckItem]:
    """What applicant tracking systems (ATS) commonly misread. Guidance, not a pass/fail gate."""
    layout = resume.layout
    checks = []
    missing = [name for name, pattern in _STANDARD_SECTIONS.items()
               if not re.search(rf"^[^\w\n]*(?:[a-z]+\s+)?(?:{pattern})\b[^\n]{{0,25}}$", text, re.I | re.M)]
    checks.append(CheckItem(
        id="ats_headings", label="Standard section headings", group="ats",
        status="check" if missing else "pass",
        detail=f"Use the usual heading names so the system can find each part: {', '.join(missing)}." if missing else "",
    ))
    if layout is not None and resume.format == "pdf":
        checks.append(CheckItem(
            id="ats_columns", label="One-column layout", group="ats",
            status="check" if layout.multi_column else "pass",
            detail="Two-column or sidebar designs can be read across the columns, mixing your sections together. "
                   "Keep this design for email if you like, and use a one-column version for online job portals."
            if layout.multi_column else "",
        ))
    if layout is not None and resume.format == "docx":
        boxed = layout.tables + layout.text_boxes
        checks.append(CheckItem(
            id="ats_boxes", label="No text boxes or tables", group="ats", status="check" if boxed else "pass",
            detail=f"This file has {layout.text_boxes} text box(es) and {layout.tables} table(s). Many systems skip "
                   "text inside them. Save it as a PDF, or move that text into normal paragraphs." if boxed else "",
        ))
        checks.append(CheckItem(
            id="ats_header_contact", label="Contact details in the page body", group="ats",
            status="check" if layout.contact_only_in_header else "pass",
            detail="Your email or phone is only in the page header/footer, which many systems ignore. "
                   "Put it at the top of the page instead." if layout.contact_only_in_header else "",
        ))
    return checks


def _numbers(text: str) -> set[str]:
    return set(re.findall(r"\d+(?:[.,]\d+)?", text))


def suggest(resume: Resume, checks: list[CheckItem], llm: LLMClient) -> tuple[list[Suggestion], list[str]]:
    shown = "\n".join(f"- {c.label}: {c.status}" for c in checks if c.status != "pass")
    raw = llm.generate_structured(
        prompt.SYSTEM, prompt.build_prompt(resume.text[:MAX_TEXT_CHARS], resume.filename, shown),
        ResumeSuggestionsLLM, task="resume_review", think=False, max_tokens=3000,
    )
    source = _norm(resume.text)
    cv_numbers = _numbers(resume.text)
    suggestions, warnings = [], []
    for s in raw.suggestions[:5]:
        quote = s.quote.strip() or None
        if quote and _norm(quote) not in source:
            warnings.append(f"Dropped a suggestion quoting text that isn't in the CV: {quote[:60]!r}")
            continue
        example = s.example.strip() or None
        # An example may only rephrase: no figures the CV lacks, and no more than a few new content words.
        if example and (not _numbers(example) <= cv_numbers or len(_new_words(example, resume.text)) > MAX_NEW_WORDS):
            example = None
        suggestions.append(Suggestion(title=s.title.strip(), detail=s.detail.strip(), quote=quote, example=example))
    return suggestions, warnings


def review_resume(resume: Resume, cv_rules: CVRules, llm: LLMClient, candidate_name: str | None = None) -> ResumeReview:
    checks = run_checks(resume, cv_rules, candidate_name)
    # Nothing to suggest on unreadable text, and no point paying for the call.
    suggestions, warnings = suggest(resume, checks, llm) if is_readable(resume) else ([], [])
    return ResumeReview(
        checks=checks, suggestions=suggestions, word_count=len(resume.text.split()), page_count=resume.page_count,
        warnings=warnings, model=llm.model, prompt_version=prompt.PROMPT_VERSION, usage=llm.usage,
    )
