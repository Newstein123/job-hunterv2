"""Deterministic skill normalization and text matching.

The alias table is a small curated vocabulary, not an exhaustive taxonomy.
Unknown skills still work: they are matched by their own name.
"""

import re

# canonical name -> variants (lowercase). The canonical name itself is always a variant.
ALIASES: dict[str, list[str]] = {
    "JavaScript": ["javascript", "js", "ecmascript"],
    "TypeScript": ["typescript"],
    "Node.js": ["node.js", "nodejs"],
    "React": ["react", "react.js", "reactjs"],
    "Vue.js": ["vue", "vue.js", "vuejs"],
    "Angular": ["angular", "angularjs"],
    "PHP": ["php"],
    "Laravel": ["laravel"],
    "Symfony": ["symfony"],
    "Python": ["python"],
    "Django": ["django"],
    "FastAPI": ["fastapi"],
    "Go": ["golang"],
    "Java": ["java"],
    "Spring Boot": ["spring boot", "springboot"],
    "C#": ["c#", "csharp"],
    ".NET": [".net", "dotnet"],
    "Ruby on Rails": ["ruby on rails", "rails"],
    "REST API": ["rest api", "rest apis", "restful", "restful api", "restful apis"],
    "GraphQL": ["graphql"],
    "MySQL": ["mysql"],
    "PostgreSQL": ["postgresql", "postgres"],
    "MongoDB": ["mongodb", "mongo"],
    "Redis": ["redis"],
    "Elasticsearch": ["elasticsearch"],
    "Docker": ["docker"],
    "Kubernetes": ["kubernetes", "k8s"],
    "Terraform": ["terraform"],
    "AWS": ["aws", "amazon web services"],
    "GCP": ["gcp", "google cloud", "google cloud platform"],
    "Azure": ["azure", "microsoft azure"],
    "CI/CD": ["ci/cd", "cicd", "continuous integration"],
    "Git": ["git"],
    "Linux": ["linux"],
    "Microservices": ["microservices", "microservice"],
    "HTML": ["html", "html5"],
    "CSS": ["css", "css3"],
    "Tailwind CSS": ["tailwind", "tailwind css", "tailwindcss"],
    "Blockchain": ["blockchain"],
    # --- Construction, engineering and other trades (from the Career Coaching Center resume set) ---
    "AutoCAD": ["autocad", "auto cad", "autocad 2d", "autocad 3d", "autocad mep"],
    "Revit": ["revit", "autodesk revit", "revit mep", "revit architecture", "revit structure"],
    "Navisworks": ["navisworks", "navis works"],
    "Tekla": ["tekla", "tekla structures"],
    "ETABS": ["etabs"],
    "SAFE": ["safe"],  # CSI SAFE (foundation design); matched in capitals only, see CASE_SENSITIVE
    "SAP2000": ["sap2000", "sap 2000"],
    "STAAD Pro": ["staad", "staad pro", "staad.pro"],
    "SketchUp": ["sketchup", "sketch up"],
    "Lumion": ["lumion"],
    "3ds Max": ["3ds max", "3dsmax", "3d max"],
    "ArchiCAD": ["archicad"],
    "MicroStation": ["microstation", "bentley microstation"],
    "Civil 3D": ["civil 3d", "autocad civil 3d"],
    "SolidWorks": ["solidworks", "solid works"],
    "Mastercam": ["mastercam"],
    "BIM": ["bim", "building information modelling", "building information modeling"],
    "M&E": ["m&e", "m & e", "mep", "m&e services", "mechanical and electrical", "mechanical & electrical",
            "mechanical, electrical and plumbing", "mechanical, electrical & plumbing", "building services"],
    "ACMV": ["acmv", "hvac", "air-conditioning and mechanical ventilation", "air conditioning and mechanical ventilation"],
    "ELV": ["elv", "extra low voltage"],
    "CCTV": ["cctv"],
    "Fire Protection": ["fire protection", "fire alarm", "fire fighting", "firefighting", "sprinkler"],
    "Plumbing & Sanitary": ["plumbing", "sanitary", "plumbing and sanitary"],
    "Piping": ["piping", "pipe fitting", "pipeline"],
    "Steel Structure": ["steel structure", "steel structures", "structural steel"],
    "Shop Drawings": ["shop drawing", "shop drawings"],
    "As-built Drawings": ["as-built", "as built drawing", "as-built drawings", "as built drawings"],
    "Bill of Quantities": ["bill of quantities", "bills of quantities", "boq", "bq", "bqs"],
    "Quantity Take-off": ["quantity take-off", "quantity takeoff", "quantities take off", "take off quantities", "taking off"],
    "Cost Estimation": ["cost estimation", "cost estimate", "cost estimates", "estimating", "quantity estimates"],
    "Tendering": ["tender", "tenders", "tendering", "tender documents"],
    "Progress Claims": ["progress claim", "progress claims", "payment claims"],
    "Variation Orders": ["variation order", "variation orders", "variations", "vo"],
    "Site Supervision": ["site supervision", "supervise site", "site supervisor"],
    "QA/QC": ["qa/qc", "qaqc", "quality assurance", "quality control"],
    "Primavera P6": ["primavera", "primavera p6", "p6"],
    "MS Project": ["ms project", "microsoft project"],
    "PLC": ["plc", "plcs"],
    "Solar PV": ["solar pv", "solar", "photovoltaic"],
    "Workplace Safety": ["wsh", "hse", "workplace safety", "safety management", "nebosh"],
    # --- Accounting & finance ---
    "MYOB": ["myob"],
    "Xero": ["xero"],
    "QuickBooks": ["quickbooks", "quick books"],
    "Tally": ["tally", "tally erp"],
    "SAP": ["sap", "sap erp", "sap fico", "sap b1"],
    "ERP": ["erp", "erp systems", "erp system"],
    "ACCA": ["acca"],
    "CPA": ["cpa"],
    "IFRS": ["ifrs", "sfrs"],
    "Bank Reconciliation": ["bank reconciliation", "bank reconciliations"],
    "Accounts Payable/Receivable": ["accounts payable", "accounts receivable", "ap/ar", "ar/ap"],
    "Payroll": ["payroll"],
    "Taxation": ["taxation", "gst", "tax filing"],
    "Microsoft Excel": ["excel", "ms excel", "microsoft excel"],
    "Microsoft Office": ["microsoft office", "ms office", "office 365"],
    "Power BI": ["power bi", "powerbi"],
    # --- IT & networks ---
    "CCNA": ["ccna"],
    "Cisco": ["cisco"],
    "MikroTik": ["mikrotik"],
    "Active Directory": ["active directory"],
    "Windows Server": ["windows server"],
    "Network Troubleshooting": ["network troubleshooting", "troubleshooting network"],
}

# Short words that are also ordinary English ("safe", "sap", "tally"): match only exactly as written here.
CASE_SENSITIVE = {"safe": "SAFE", "sap": "SAP", "tally": "Tally", "vo": "VO", "p6": "P6", "bq": "BQ", "bqs": "BQs"}

_VARIANT_TO_CANONICAL: dict[str, str] = {}
for _canonical, _variants in ALIASES.items():
    for _v in [_canonical.lower(), *_variants]:
        _VARIANT_TO_CANONICAL[_v] = _canonical


def _clean(term: str) -> str:
    return re.sub(r"\s+", " ", term.strip()).strip(" .,;:")


def normalize_skill(term: str) -> str:
    """Return the canonical display name for a skill (or the cleaned input)."""
    cleaned = _clean(term)
    return _VARIANT_TO_CANONICAL.get(cleaned.lower(), cleaned)


def normalize_skills(terms: list[str]) -> list[str]:
    """Normalize and de-duplicate, keeping first-seen order."""
    seen: dict[str, str] = {}
    for t in terms:
        canonical = normalize_skill(t)
        if canonical and canonical.lower() not in seen:
            seen[canonical.lower()] = canonical
    return list(seen.values())


def variants_of(skill: str) -> list[str]:
    canonical = normalize_skill(skill)
    return sorted({canonical.lower(), *ALIASES.get(canonical, [])}, key=len, reverse=True)


def _variant_pattern(variant: str) -> re.Pattern[str]:
    # Boundaries that tolerate symbols like "C#", ".NET", "Node.js", "CI/CD".
    if variant in CASE_SENSITIVE:
        return re.compile(rf"(?<![A-Za-z0-9]){re.escape(CASE_SENSITIVE[variant])}(?![A-Za-z0-9#+])")
    body = re.escape(variant)
    flags = 0 if len(variant) <= 2 else re.IGNORECASE
    if len(variant) <= 2:
        # Short variants ("Go", "JS") match only as upper/title case, so "go" and "to go" don't count.
        body = "(?:" + "|".join(re.escape(v) for v in {variant.upper(), variant.title()}) + ")"
    return re.compile(rf"(?<![A-Za-z0-9]){body}(?![A-Za-z0-9#+])", flags)


def text_mentions(text: str, skill: str) -> bool:
    return any(_variant_pattern(v).search(text) for v in variants_of(skill))


def find_skills_in_text(text: str, skills: list[str]) -> list[str]:
    """Return the canonical names of `skills` that the text mentions."""
    return [s for s in normalize_skills(skills) if text_mentions(text, s)]


def known_vocabulary() -> list[str]:
    return list(ALIASES.keys())
