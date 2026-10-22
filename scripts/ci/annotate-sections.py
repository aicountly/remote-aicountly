"""Turn `=== <name>` sections of a text file into GitHub Actions annotations (one per section).

    python3 scripts/ci/annotate-sections.py <file> <title prefix>

Identical in Remote and Connect. The run's raw log is not always available to the people (and tools) who need the result, but a
job's annotations are, so the TURN staging check reports through them. The input is the output of scripts/ci/turn-staging-check.sh or of
bin/ops/apply-turn-config.sh / inject-turn-secrets.sh — none of which ever prints a secret. A section whose probe exited non-zero, or that
says FAIL / REFUSED-when-it-should-not / Refused, becomes a warning; everything else a notice. Never fails the step.
"""
import re
import sys


def escape(text: str) -> str:
    return text.replace('%', '%25').replace('\r', '%0D').replace('\n', '%0A')


def main() -> int:
    path, prefix = sys.argv[1], sys.argv[2]
    try:
        text = open(path, encoding='utf-8', errors='replace').read()
    except OSError:
        print(f'::warning title={prefix}::no output was captured')
        return 0
    parts = re.split(r'^=== (\S+)[^\n]*\n', text, flags=re.M)
    if len(parts) == 1:
        parts = ['', 'result', text]
    for i in range(1, len(parts), 2):
        name, body = parts[i], parts[i + 1].strip()
        bad = re.search(r'\(exit [1-9]\)|\bFAIL\b|^Refused|not configured|missing', body, flags=re.M | re.I)
        level = 'warning' if bad else 'notice'
        print(f'::{level} title={prefix}: {name}::{escape(body[:3800]) or "(empty)"}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
