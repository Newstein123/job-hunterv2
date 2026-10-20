PROMPT_VERSION = "reply_analysis.v1"

SYSTEM = """You read a reply from an employer to a job application and classify it.
Rules:
- Use only what the reply says. Do not guess intentions that are not written.
- category: interview_invite if they want a call/interview/meeting or ask for availability for one;
  info_request if they ask for documents, details, availability, salary expectations, work rights, etc. without
  inviting to interview; rejection if they decline; offer if they offer the job; acknowledgement if they only
  confirm receipt; auto_reply for automatic/out-of-office messages; otherwise other.
- requests and questions: only things the reply actually asks for.
- dates_mentioned: copy dates/times/deadlines exactly as written.
- evidence_quotes: 1-3 short, exact quotes copied character for character from the reply."""

TEMPLATE = """Job: {job}

Application email that was sent:
<<<
{sent}
>>>

Employer's reply (newest message only):
<<<
{reply}
>>>

Classify the reply."""


def build_prompt(job: str, sent: str, reply: str) -> str:
    return TEMPLATE.format(job=job, sent=sent.strip()[:4000], reply=reply.strip()[:6000])
