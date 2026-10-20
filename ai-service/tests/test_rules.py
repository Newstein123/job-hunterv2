import json
from datetime import datetime, timezone

import pytest

from app.models.job import JobAnalysis
from app.pipelines.application_strategy import earliest_allowed_send_time
from app.pipelines.email_generation import render_subject
from app.rules.loader import load_rules
from app.rules.models import SendingRules
from app.rules.validator import RulesError


def test_merge_precedence(tmp_path):
    user = tmp_path / "user.json"
    user.write_text(json.dumps({"email_rules": {"max_words": 150}, "sending_rules": {"start_time": "10:00"}}))
    rules, sources, warnings = load_rules("Singapore", user)
    assert rules.email_rules.max_words == 150  # user
    assert rules.email_rules.paragraph_count == 3  # default
    assert rules.sending_rules.timezone == "Asia/Singapore"  # country
    assert rules.sending_rules.start_time == "10:00"  # user over default
    assert len(sources) == 3 and not warnings


def test_unknown_country_falls_back_with_warning():
    rules, _, warnings = load_rules("Atlantis")
    assert rules.sending_rules.timezone == "UTC"
    assert warnings and "Atlantis" in warnings[0]


def test_invalid_rules_rejected(tmp_path):
    user = tmp_path / "user.json"
    user.write_text(json.dumps({"email_rules": {"max_wrds": 100}}))
    with pytest.raises(RulesError):
        load_rules(None, user)
    user.write_text(json.dumps({"sending_rules": {"timezone": "Mars/Base"}}))
    with pytest.raises(RulesError):
        load_rules(None, user)


def test_subject_rendering():
    job = JobAnalysis(title="Senior Backend Engineer", company="Merlion Pay", seniority="", location="",
                      country="", required_skills=[], preferred_skills=[], responsibilities=[], keywords=[],
                      requirements=[])
    subject, warnings = render_subject("{position} - Application - {candidate_name}", job, "Alex Tan")
    assert subject == "Senior Backend Engineer - Application - Alex Tan" and not warnings
    subject, warnings = render_subject("{position} ({team})", job, "Alex Tan")
    assert subject == "Senior Backend Engineer ({team})" and warnings


SG = SendingRules(timezone="Asia/Singapore")


@pytest.mark.parametrize(
    "now_utc, expected_local",
    [
        # Wed 2026-09-30 02:00 UTC = 10:00 SGT, inside the window -> now
        (datetime(2026, 9, 30, 2, 0, tzinfo=timezone.utc), "2026-09-30T10:00:00+08:00"),
        # Wed 22:00 UTC = Thu 06:00 SGT, before window -> Thu 09:00
        (datetime(2026, 9, 30, 22, 0, tzinfo=timezone.utc), "2026-10-01T09:00:00+08:00"),
        # Fri 2026-10-02 10:00 UTC = 18:00 SGT, after window -> Mon 09:00
        (datetime(2026, 10, 2, 10, 0, tzinfo=timezone.utc), "2026-10-05T09:00:00+08:00"),
    ],
)
def test_send_window(now_utc, expected_local):
    assert earliest_allowed_send_time(now_utc, SG).isoformat() == expected_local


@pytest.mark.parametrize(
    "country, timezone",
    [("United Arab Emirates", "Asia/Dubai"), ("Australia", "Australia/Sydney"),
     ("Singapore", "Asia/Singapore"), ("Thailand", "Asia/Bangkok"),
     ("Hong Kong", "Asia/Hong_Kong"), ("Malaysia", "Asia/Kuala_Lumpur")],
)
def test_target_market_profiles(country, timezone):
    """The web app's countries table sends these names; each needs a curated profile."""
    rules, _, warnings = load_rules(country)
    assert rules.sending_rules.timezone == timezone and not warnings
