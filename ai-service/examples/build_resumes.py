"""Build example PDF/DOCX resumes from examples/resume_sources/*.md.

    python -m examples.build_resumes   (needs requirements-dev.txt for fpdf2)
"""

from pathlib import Path

HERE = Path(__file__).parent
SOURCES = HERE / "resume_sources"
OUT = HERE / "resumes"


def write_pdf(text: str, path: Path) -> None:
    from fpdf import FPDF

    pdf = FPDF()
    pdf.add_page()
    pdf.set_font("Helvetica", size=11)
    for line in text.splitlines():
        # Core PDF fonts are latin-1 only.
        pdf.multi_cell(0, 6, line.replace("—", "-").replace("–", "-") or " ", new_x="LMARGIN", new_y="NEXT")
    pdf.output(str(path))


def write_docx(text: str, path: Path) -> None:
    import docx

    document = docx.Document()
    for line in text.splitlines():
        document.add_paragraph(line)
    document.save(str(path))


def main() -> None:
    OUT.mkdir(exist_ok=True)
    write_pdf((SOURCES / "backend_developer.md").read_text(), OUT / "backend_developer.pdf")
    write_docx((SOURCES / "fullstack_developer.md").read_text(), OUT / "fullstack_developer.docx")
    print(f"Wrote resumes to {OUT}")


if __name__ == "__main__":
    main()
