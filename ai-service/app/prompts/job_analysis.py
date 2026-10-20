import re

PROMPT_VERSION = "job_analysis.v2"

SYSTEM = """You extract structured information from job postings.
Rules:
- Use only information stated in the posting. Never infer or invent requirements.
- If a field is not stated, return an empty string or an empty list.
- required_skills: software, tools and technical competencies the job needs, for any industry, e.g.
  "AutoCAD", "Revit", "Bill of Quantities", "ACMV", "MYOB", "Laravel", "progress claims", "site supervision".
  Use the posting's own wording, as a short noun phrase (1-4 words), not a sentence.
  Do not list personal qualities or soft skills (communication, teamwork, problem-solving, interpersonal,
  analytical, organised, self-motivated); they cannot be checked against a CV.
  List each competency once: no near-duplicates (not both "switchgear" and "switchgear and power distribution").
  At most 12 required and 8 preferred; keep the most specific ones.
  Items in a general requirements list with no "preferred"/"nice to have"/"bonus" wording count as required.
- preferred_skills: the same kind of items explicitly marked preferred, nice-to-have, plus, or bonus.
- requirements: qualifications that are not a single skill: degree and field, years of experience (keep the
  number and the field, e.g. "3+ years of quantity surveying experience"), certifications or licences,
  languages, work-pass/eligibility, and job conditions (weekend work, site visits, travel). One per item.
- responsibilities: short phrases, at most 8.
- keywords: at most 10 domain terms (e.g. "fintech", "payments") not already listed as skills."""

TEMPLATE = """Job metadata provided by the user (may be empty):
{metadata}

Job posting:
<<<
{description}
>>>

Return the structured job analysis."""


def clean(description: str) -> str:
    """Collapse pasted-page whitespace (nbsp, runs of blank lines) that can send the model into a loop."""
    text = re.sub(r"[ \t\u00a0]+", " ", description)
    return re.sub(r"\n\s*\n+", "\n\n", text).strip()


def build_prompt(description: str, metadata: str) -> str:
    return TEMPLATE.format(description=clean(description), metadata=metadata or "none")
