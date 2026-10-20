import json

import pytest

from app.models.job import JobAnalysis
from app.pipelines.email_generation import render_subject
from app.rules.loader import load_rules
from app.rules.validator import RulesError


def test_merge_precedence(tmp_path):
    user = tmp_path / "user.json"
    user.write_text(json.dumps({"email_rules": {"max_words": 150}}))
    rules, sources, warnings = load_rules("Singapore", user)
    assert rules.email_rules.max_words == 150  # user
    assert rules.email_rules.paragraph_count == 3  # default
    assert rules.country == "Singapore"
    assert len(sources) == 3 and not warnings


def test_unknown_country_falls_back_with_warning():
    rules, _, warnings = load_rules("Atlantis")
    assert rules.email_rules.max_words == 200
    assert warnings and "Atlantis" in warnings[0]


def test_invalid_rules_rejected(tmp_path):
    user = tmp_path / "user.json"
    user.write_text(json.dumps({"email_rules": {"max_wrds": 100}}))
    with pytest.raises(RulesError):
        load_rules(None, user)
    # The sending window was removed: old stored rules that still carry it are rejected, not ignored.
    user.write_text(json.dumps({"sending_rules": {"timezone": "Asia/Singapore"}}))
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


@pytest.mark.parametrize(
    "country", ["United Arab Emirates", "Australia", "Singapore", "Thailand", "Hong Kong", "Malaysia"],
)
def test_target_market_profiles(country):
    """The web app's countries table sends these names; each needs a curated profile."""
    rules, _, warnings = load_rules(country)
    assert rules.country == country and not warnings
