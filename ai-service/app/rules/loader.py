"""Load rules as layered data: default < country profile < user rules."""

import json
from pathlib import Path
from typing import Any

from app.rules.validator import validate_rules

PROFILES_DIR = Path(__file__).parent / "profiles"


def _deep_merge(base: dict[str, Any], override: dict[str, Any]) -> dict[str, Any]:
    merged = dict(base)
    for key, value in override.items():
        if isinstance(value, dict) and isinstance(merged.get(key), dict):
            merged[key] = _deep_merge(merged[key], value)
        else:
            merged[key] = value
    return merged


def _country_slug(country: str) -> str:
    return country.strip().lower().replace(" ", "_")


def available_countries(profiles_dir: Path = PROFILES_DIR) -> list[str]:
    return sorted(p.stem for p in profiles_dir.glob("*.json") if p.stem != "default")


def load_rules(
    country: str | None,
    user_rules_path: Path | None = None,
    profiles_dir: Path = PROFILES_DIR,
    user_rules: dict[str, Any] | None = None,
):
    """Return (Rules, sources, warnings). User rules come from a file or an inline dict."""
    warnings: list[str] = []
    sources = [str(profiles_dir / "default.json")]
    data = json.loads((profiles_dir / "default.json").read_text())

    if country:
        country_path = profiles_dir / f"{_country_slug(country)}.json"
        if country_path.exists():
            data = _deep_merge(data, json.loads(country_path.read_text()))
            sources.append(str(country_path))
        else:
            warnings.append(
                f"No curated rules profile for country {country!r}; using defaults "
                f"(available: {', '.join(available_countries(profiles_dir)) or 'none'})"
            )
        data["country"] = country

    if user_rules_path:
        data = _deep_merge(data, json.loads(Path(user_rules_path).read_text()))
        sources.append(str(user_rules_path))
    if user_rules:
        data = _deep_merge(data, user_rules)
        sources.append("user_rules:inline")

    return validate_rules(data), sources, warnings
