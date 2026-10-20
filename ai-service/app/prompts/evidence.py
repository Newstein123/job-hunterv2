PROMPT_VERSION = "evidence.v1"

SYSTEM = """You check a candidate's real evidence against a job's requirements.
For each requirement:
- Find where the CANDIDATE SOURCES prove it. A source counts only if it really shows the requirement
  (same competency, tool, qualification or field), not just a related word.
- quotes: copy 3-25 words EXACTLY, character for character, from one source, and give that source's id.
  Do not paraphrase, combine sources, or fix spelling. Prefer the most specific line.
- status: met (clear evidence), partial (related but weaker, e.g. similar role or fewer details),
  not_met (no evidence), not_applicable (a job condition, not a qualification: weekend work, site visits,
  travel, location, start date).
- Never assume experience the sources do not show.
Return one item per requirement, in the same order, with the requirement text unchanged."""

TEMPLATE = """JOB: {job}

REQUIREMENTS:
{requirements}

CANDIDATE SOURCES:
{sources}

Check each requirement."""


def build_prompt(job: str, requirements: list[str], sources: list[tuple[str, str]]) -> str:
    reqs = "\n".join(f"{i + 1}. {r}" for i, r in enumerate(requirements))
    srcs = "\n\n".join(f"[{sid}]\n{text}" for sid, text in sources)
    return TEMPLATE.format(job=job, requirements=reqs, sources=srcs)
