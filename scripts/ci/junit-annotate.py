"""Turn a PHPUnit JUnit report's failures and errors into GitHub Actions error annotations.

    python3 scripts/ci/junit-annotate.py <junit.xml> <title prefix>

Identical in Remote and Connect. A job's raw log is not always readable by the people (and tools) who need
the result, but its annotations are; without this a red test step says only "exit code 1". One annotation
per failing test (capped at 40), carrying the test, the file and line, and the first lines of the message.
Test output never contains a secret (the suites use fixtures). Never fails the step.
"""
import sys
import xml.etree.ElementTree as ET


def escape(text: str) -> str:
    return text.replace('%', '%25').replace('\r', '%0D').replace('\n', '%0A')


def main() -> int:
    path, prefix = sys.argv[1], sys.argv[2]
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError) as error:
        print(f'::warning title={prefix}::no JUnit report to read ({error})')
        return 0
    shown = 0
    total = 0
    for case in root.iter('testcase'):
        for kind in ('failure', 'error'):
            for node in case.findall(kind):
                total += 1
                if shown >= 40:
                    continue
                shown += 1
                name = f"{case.get('class') or case.get('classname') or ''}::{case.get('name')}"
                where = f"{case.get('file', '')}:{case.get('line', '')}"
                body = '\n'.join(((node.text or node.get('message') or '').strip().splitlines())[:12])
                print(f'::error title={escape(prefix)} {kind}: {escape(name)}::{escape(where + chr(10) + body)}')
    if total == 0:
        print(f'::notice title={prefix}::no failing test in the report')
    elif total > shown:
        print(f'::error title={prefix}::{total - shown} more failing test(s) not shown')
    return 0


if __name__ == '__main__':
    sys.exit(main())
