from app.llm.client import FakeLLMClient
from app.models.reply import ReplyAnalysis, ThreadMessage
from app.pipelines.replies import analyze_reply, draft_reply
from app.rules.models import Rules

REPLY = """Hi Alex,

Thanks for applying. We'd like to invite you to a 30-minute video interview with our engineering lead.
Could you share your availability next week? Please also send your expected salary.

Best,
Jane"""

SENT = "Dear Merlion Pay Hiring Team, I am applying for the Senior Backend Engineer role..."


def _analysis(**over):
    data = {
        "category": "interview_invite",
        "summary": "They invite Alex to a video interview and ask for availability and salary expectations.",
        "requests": ["availability next week", "expected salary"],
        "questions": ["Could you share your availability next week?"],
        "dates_mentioned": ["next week"],
        "evidence_quotes": ["We'd like to invite you to a 30-minute video interview", "We will hire you tomorrow"],
    }
    return {**data, **over}


def test_analysis_keeps_only_real_quotes_and_suggests_status(backend_analysis):
    result = analyze_reply(backend_analysis, SENT, REPLY, FakeLLMClient([_analysis()]))
    assert result.category == "interview_invite"
    assert result.suggested_status == "interview"
    assert result.evidence_quotes == ["We'd like to invite you to a 30-minute video interview"]
    assert any("not in the reply" in w for w in result.warnings)


def test_rejection_and_acknowledgement_suggestions(backend_analysis):
    rejected = analyze_reply(backend_analysis, SENT, "Unfortunately we have decided not to proceed.",
                             FakeLLMClient([_analysis(category="rejection", evidence_quotes=["decided not to proceed"])]))
    assert rejected.suggested_status == "rejected"
    ack = analyze_reply(backend_analysis, SENT, "We received your application.",
                        FakeLLMClient([_analysis(category="acknowledgement", evidence_quotes=["We received your application"])]))
    assert ack.suggested_status is None


def _draft(paragraphs, needs=()):
    return {"greeting": "Dear Jane,", "paragraphs": paragraphs, "closing": "Kind regards,", "needs_input": list(needs)}


def test_draft_with_placeholder_is_blocked_until_filled(backend_analysis, profile):
    analysis = ReplyAnalysis.model_validate({**_analysis(evidence_quotes=[]), "suggested_status": "interview"})
    llm = FakeLLMClient([_draft(
        ["Thank you for the invitation. I would be glad to join a video interview.",
         "I am available [[your available times]]. My salary expectation is [[your expected salary]]."],
        needs=["your available times", "your expected salary"],
    )])
    draft = draft_reply(backend_analysis, profile, "Senior Backend Engineer - Application - Alex Tan", SENT, REPLY,
                        analysis, "", Rules(), llm)
    assert draft.subject == "Re: Senior Backend Engineer - Application - Alex Tan"
    assert "[[your available times]]" in draft.body
    assert draft.needs_input == ["your available times", "your expected salary"]  # no duplicates
    assert any("placeholder" in e for e in draft.validation.errors)
    assert draft.body.rstrip().endswith("alex.tan@example.com\n+65 8000 0000") or "alex.tan@example.com" in draft.body


def test_draft_uses_notes_and_blocks_invented_claims(backend_analysis, profile):
    analysis = ReplyAnalysis.model_validate({**_analysis(evidence_quotes=[]), "suggested_status": "interview"})
    notes = "Available Tue and Wed 2-5pm SGT. Expected salary SGD 9,000 per month."
    bad = _draft(["I have 12 years of Kubernetes experience.", "I am available Tue and Wed 2-5pm SGT."])
    good = _draft(["Thank you for the invitation.",
                   "I am available Tue and Wed 2-5pm SGT, and my expected salary is SGD 9,000 per month."])
    llm = FakeLLMClient([bad, good])
    draft = draft_reply(backend_analysis, profile, "Subject", SENT, REPLY, analysis, notes, Rules(), llm)
    assert draft.validation.ok, draft.validation.errors
    assert draft.validation.attempts == 2
    assert "Kubernetes" in llm.calls[1]["prompt"]  # repair prompt named the violation
    assert "SGD 9,000" in draft.body  # salary allowed because the candidate wrote it in the notes


FOLLOW_UP = "Hi Alex, Tuesday 3pm works. Please also bring your portfolio. Best, Jane"
HISTORY = [
    ThreadMessage(sender="employer", at="2026-10-05", text=REPLY),
    ThreadMessage(sender="candidate", at="2026-10-06",
                  text="Thank you. I am available Tue and Wed 2-5pm SGT. My expected salary is SGD 9,000 per month."),
]


def test_analysis_sees_earlier_messages_but_quotes_only_the_newest(backend_analysis):
    llm = FakeLLMClient([_analysis(evidence_quotes=["Tuesday 3pm works", "Could you share your availability next week?"])])
    result = analyze_reply(backend_analysis, SENT, FOLLOW_UP, llm, history=HISTORY)
    prompt = llm.calls[0]["prompt"]
    assert "Tue and Wed 2-5pm SGT" in prompt and prompt.index("Tue and Wed") < prompt.index("Tuesday 3pm works")
    assert result.evidence_quotes == ["Tuesday 3pm works"]  # the quote from the older message is dropped


def test_draft_may_repeat_what_the_candidate_already_said(backend_analysis, profile):
    analysis = ReplyAnalysis.model_validate({**_analysis(evidence_quotes=[]), "suggested_status": "interview"})
    llm = FakeLLMClient([_draft(["Thank you, Tuesday 3pm is confirmed. I will bring my portfolio.",
                                 "As mentioned, my expected salary is SGD 9,000 per month."])])
    draft = draft_reply(backend_analysis, profile, "Subject", SENT, FOLLOW_UP, analysis, "", Rules(), llm,
                        history=HISTORY)
    assert draft.validation.ok, draft.validation.errors  # the amount came from the candidate's earlier answer
    assert "EARLIER MESSAGES" in llm.calls[0]["prompt"] and "Tue and Wed 2-5pm SGT" in llm.calls[0]["prompt"]

    # An amount nobody wrote is still blocked.
    llm = FakeLLMClient([_draft(["My expected salary is SGD 12,000 per month."])] * 2)
    draft = draft_reply(backend_analysis, profile, "Subject", SENT, FOLLOW_UP, analysis, "", Rules(), llm,
                        history=HISTORY)
    assert any("SGD 12,000" in e for e in draft.validation.errors)


def test_history_keeps_the_newest_messages_within_limits():
    from app.prompts import thread_history

    many = [ThreadMessage(sender="employer", text=f"message {i} " + "x" * 3000) for i in range(15)]
    rendered = thread_history.render(many)
    assert "message 14" in rendered and "message 0 " not in rendered
    assert len(rendered) <= thread_history.MAX_TOTAL_CHARS + 200 and "[...]" in rendered
    assert thread_history.render([]) == "(none)"
