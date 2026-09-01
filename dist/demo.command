#!/usr/bin/env bash
# ÁTICA Calidad - arranque con datos de demostración (macOS, doble clic)
# Pensado para abrirse con doble clic en el Finder: arranca la aplicación con
# el centro de demostración ya cargado. Equivale a ejecutar ./demo.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${ROOT}"
exec "${ROOT}/demo.sh"
