"""Stage 6 — Application Strategy (deterministic, from rules + prior stages)."""

import re
from datetime import datetime, time, timedelta
from zoneinfo import ZoneInfo

from app.models.application import ApplicationStrategy, SkillStrategy
from app.models.job import JobInput
from app.rules.models import Rules, SendingRules

_WEEKDAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
_COVER_LETTER_PATTERN = re.compile(r"cover\s*letter", re.IGNORECASE)


def _hhmm(value: str) -> time:
    hours, minutes = value.split(":")
    return time(int(hours), int(minutes))


def earliest_allowed_send_time(now: datetime, rules: SendingRules) -> datetime:
    """Earliest moment the sending window allows. This is a constraint, not a 'best time'."""
    tz = ZoneInfo(rules.timezone)
    local_now = now.astimezone(tz)
    start, end = _hhmm(rules.start_time), _hhmm(rules.end_time)
    for offset in range(8):
        day = (local_now + timedelta(days=offset)).date()
        if _WEEKDAYS[day.weekday()] not in rules.allowed_days:
            continue
        window_start = datetime.combine(day, start, tz)
        window_end = datetime.combine(day, end, tz)
        if offset == 0 and local_now >= window_end:
            continue
        return max(window_start, local_now) if offset == 0 else window_start
    raise ValueError("Sending rules allow no sending days")


def _cover_letter_decision(mode: str, description: str) -> tuple[bool, str]:
    if mode == "always":
        return True, "Rules require a cover letter for every application"
    if mode == "never":
        return False, "Rules disable cover letters"
    if _COVER_LETTER_PATTERN.search(description):
        return True, "The job posting mentions a cover letter"
    return False, "The job posting does not ask for a cover letter"


def build_application_strategy(
    job: JobInput,
    skill_strategy: SkillStrategy,
    rules: Rules,
    now: datetime,
) -> ApplicationStrategy:
    email_rules, app_rules = rules.email_rules, rules.application_rules
    include_cover_letter, cover_reason = _cover_letter_decision(app_rules.cover_letter, job.description)
    return ApplicationStrategy(
        language=email_rules.language,
        tone=email_rules.tone,
        email_length="short" if email_rules.max_words <= 200 else "medium",
        paragraph_count=email_rules.paragraph_count,
        max_words=email_rules.max_words,
        focus=skill_strategy.emphasize + skill_strategy.supporting_skills,
        relevant_experience=skill_strategy.relevant_experience,
        relevant_project=skill_strategy.relevant_project,
        cta=app_rules.cta,
        include_cover_letter=include_cover_letter,
        cover_letter_reason=cover_reason,
        include_linkedin=email_rules.include_linkedin,
        include_phone=email_rules.include_phone,
        include_salary=email_rules.include_salary,
        claims_to_avoid=app_rules.claims_to_avoid,
        earliest_allowed_send_time=earliest_allowed_send_time(now, rules.sending_rules),
        send_timezone=rules.sending_rules.timezone,
        sending_rules=rules.sending_rules,
        letter_style="speculative" if job.kind == "speculative" else email_rules.letter_style,
        job_source=job.source,
    )
