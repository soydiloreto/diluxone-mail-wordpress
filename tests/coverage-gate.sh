#!/usr/bin/env bash
#
# El umbral de cobertura de los tests unitarios.
#
# Lee el informe Clover que dejó PHPUnit y falla si la cobertura de líneas
# está por debajo del mínimo. El mínimo es un trinquete: se fija apenas por
# debajo de lo que hay y sólo se sube. Un número inventado alto no mide nada;
# un número real que no puede bajar sí.
#
# Uso:   tests/coverage-gate.sh build/clover.xml 94
#
set -euo pipefail

CLOVER="${1:-build/clover.xml}"
MIN="${2:-94}"

[ -f "$CLOVER" ] || { echo "no existe $CLOVER" >&2; exit 1; }

python3 - "$CLOVER" "$MIN" <<'PY'
import sys, xml.etree.ElementTree as ET
clover, minimum = sys.argv[1], float(sys.argv[2])
m = ET.parse(clover).getroot().find("project/metrics")
total, covered = int(m.get("statements")), int(m.get("coveredstatements"))
pct = 100.0 * covered / total if total else 0.0
print(f"Cobertura de líneas (unitarios): {pct:.1f}% ({covered}/{total}) — mínimo {minimum:.0f}%")
if pct < minimum:
    print(f"✖ Por debajo del mínimo.", file=sys.stderr); sys.exit(1)
print("✔ Dentro del umbral.")
PY
