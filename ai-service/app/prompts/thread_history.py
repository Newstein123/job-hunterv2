"""Renders the earlier messages of a reply thread for the reply prompts."""

from app.models.reply import ThreadMessage

MAX_MESSAGES = 10  # the most recent ones; older context is in the application email
MAX_CHARS_PER_MESSAGE = 1500
MAX_TOTAL_CHARS = 8000


def render(history: list[ThreadMessage]) -> str:
    """Oldest first, newest kept when the total is over the limit. "(none)" when there is no history."""
    blocks: list[str] = []
    total = 0
    for message in reversed(history[-MAX_MESSAGES:]):
        text = message.text.strip()
        if len(text) > MAX_CHARS_PER_MESSAGE:
            text = text[:MAX_CHARS_PER_MESSAGE].rstrip() + " [...]"
        who = "EMPLOYER" if message.sender == "employer" else "CANDIDATE"
        block = f"[{who}{', ' + message.at if message.at else ''}]\n{text}"
        if total + len(block) > MAX_TOTAL_CHARS:
            break
        blocks.append(block)
        total += len(block)
    return "\n\n".join(reversed(blocks)) or "(none)"
