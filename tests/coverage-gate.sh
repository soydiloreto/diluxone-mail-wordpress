#!/usr/bin/env bash
#
# The unit tests' coverage threshold.
#
# It reads the Clover report PHPUnit left behind and fails if line coverage is
# below the minimum. The minimum is a ratchet: it is set just under what is
# there and only ever goes up. A high invented number measures nothing; a real
# number that cannot go down does.
#
# Usage:   tests/coverage-gate.sh build/clover.xml 94
#
set -euo pipefail

CLOVER="${1:-build/clover.xml}"
MIN="${2:-94}"

[ -f "$CLOVER" ] || { echo "$CLOVER does not exist" >&2; exit 1; }

python3 - "$CLOVER" "$MIN" <<'PY'
import sys, xml.etree.ElementTree as ET
clover, minimum = sys.argv[1], float(sys.argv[2])
m = ET.parse(clover).getroot().find("project/metrics")
total, covered = int(m.get("statements")), int(m.get("coveredstatements"))
pct = 100.0 * covered / total if total else 0.0
print(f"Line coverage (unit): {pct:.1f}% ({covered}/{total}) — minimum {minimum:.0f}%")
if pct < minimum:
    print("✖ Below the minimum.", file=sys.stderr); sys.exit(1)
print("✔ Within the threshold.")
PY
