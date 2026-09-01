#!/usr/bin/env bash
# ÁTICA Calidad - arranque con datos de demostración (Linux / macOS)
# Carga el centro de demostración (IES Ada Lovelace) y arranca la aplicación.
# Uso: ./demo.sh [puerto]          (por defecto: 8080)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

export LOAD_DEMO_DATA=true
exec "${ROOT}/start.sh" "$@"
