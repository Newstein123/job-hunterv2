from app.models.reply import ThreadMessage
from app.prompts import thread_history

PROMPT_VERSION = "reply_analysis.v2"

SYSTEM = """You read a reply from an employer to a job application and classify it.
Rules:
- Classify the NEWEST reply. Earlier messages are context only: use them to understand short replies
  (e.g. "Tuesday works" after the candidate offered interview times is an interview_invite).
- Use only what the messages say. Do not guess intentions that are not written.
- category: interview_invite if they want a call/interview/meeting or ask for availability for one;
  info_request if they ask for documents, details, availability, salary expectations, work rights, etc. without
  inviting to interview; rejection if they decline; offer if they offer the job; acknowledgement if they only
  confirm receipt; auto_reply for automatic/out-of-office messages; otherwise other.
- requests and questions: only things the newest reply actually asks for, not ones already answered earlier.
- dates_mentioned: copy dates/times/deadlines exactly as written.
- evidence_quotes: 1-3 short, exact quotes copied character for character from the NEWEST reply."""

TEMPLATE = """Job: {job}

Application email that was sent:
<<<
{sent}
>>>

Earlier messages in this thread, after the application email (oldest first):
<<<
{history}
>>>

Employer's NEWEST reply, the one to classify:
<<<
{reply}
>>>

Classify the reply."""


def build_prompt(job: str, sent: str, reply: str, history: list[ThreadMessage] | None = None) -> str:
    return TEMPLATE.format(job=job, sent=sent.strip()[:4000], history=thread_history.render(history or []),
                           reply=reply.strip()[:6000])
