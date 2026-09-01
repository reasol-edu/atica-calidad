#!/usr/bin/env bash
# ÁTICA Calidad - script de arranque (Linux / macOS)
# Uso: ./start.sh [puerto]          (por defecto: 8080)
set -euo pipefail

PORT="${1:-${PORT:-8080}}"

# -- Rutas absolutas ------------------------------------------------------------
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA="${ROOT}/data"
APP="${ROOT}/app"
FP="${ROOT}/frankenphp"

# -- macOS: eliminar la cuarentena de Gatekeeper --------------------------------
if [[ "$(uname)" == "Darwin" ]]; then
    xattr -d com.apple.quarantine "${FP}" 2>/dev/null || true
fi

# -- Variables de entorno para la aplicación ------------------------------------
export APP_ENV=prod
export APP_DEBUG=0
export DOCUMENT_ROOT="${APP}/public"
export SERVER_ADDR=":${PORT}"
export DATABASE_URL="sqlite:///${DATA}/atica-calidad.db"
export MIGRATIONS_PATH=migrations/sqlite
export DEFAULT_URI="http://localhost:${PORT}"
export APP_LOG="${APP_LOG:-false}"
export APP_LOG_RETENTION_DAYS="${APP_LOG_RETENTION_DAYS:-90}"
export APP_EXTERNAL_ENABLED="${APP_EXTERNAL_ENABLED:-true}"
export APP_EXTERNAL_URL="${APP_EXTERNAL_URL:-https://seneca.juntadeandalucia.es/seneca/jsp/ComprobarUsuarioExt.jsp}"
export APP_EXTERNAL_URL_FORCE_SECURITY="${APP_EXTERNAL_URL_FORCE_SECURITY:-true}"
export MAILER_DSN="${MAILER_DSN:-null://null}"
export MAILER_FROM="${MAILER_FROM:-no-responder@example.com}"
export MESSENGER_TRANSPORT_DSN="${MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}"

# -- Carpeta de datos -----------------------------------------------------------
mkdir -p "${DATA}"

# -- APP_SECRET: generar en el primer arranque ----------------------------------
if [[ ! -f "${DATA}/.secret" ]]; then
    echo "Generando APP_SECRET..."
    "${FP}" php-cli -r 'echo bin2hex(random_bytes(32));' 2>/dev/null > "${DATA}/.secret"
fi
export APP_SECRET="$(cat "${DATA}/.secret")"

# -- .env: exponer variables a PHP (bootEnv requiere el fichero .env) -----------
cat > "${APP}/.env" <<EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}
DATABASE_URL=${DATABASE_URL}
MIGRATIONS_PATH=${MIGRATIONS_PATH}
DEFAULT_URI=${DEFAULT_URI}
APP_LOG=${APP_LOG}
APP_LOG_RETENTION_DAYS=${APP_LOG_RETENTION_DAYS}
APP_EXTERNAL_ENABLED=${APP_EXTERNAL_ENABLED}
APP_EXTERNAL_URL=${APP_EXTERNAL_URL}
APP_EXTERNAL_URL_FORCE_SECURITY=${APP_EXTERNAL_URL_FORCE_SECURITY}
MAILER_DSN=${MAILER_DSN}
MAILER_FROM=${MAILER_FROM}
MESSENGER_TRANSPORT_DSN=${MESSENGER_TRANSPORT_DSN}
EOF

# -- Caché: limpiar posibles compilaciones parciales de arranques anteriores -----
rm -rf "${APP}/var/cache/"

# -- Base de datos SQLite --------------------------------------------------------
cd "${APP}"
echo "Precalentando caché. Espere por favor.."
"${FP}" php-cli bin/console cache:warmup --no-interaction

echo "Aplicando migraciones..."
"${FP}" php-cli bin/console doctrine:migrations:migrate --no-interaction

# -- Datos iniciales: el centro de demostración (IES Ada Lovelace) o, si no se
# pide, solo la cuenta admin/admin por defecto de app:setup. Son mutuamente
# excluyentes: app:load-demo-data falla si el usuario "admin" ya existe, así
# que nunca se llama a app:setup primero cuando se cargan datos de demo.
if [[ "${LOAD_DEMO_DATA:-false}" == "true" ]]; then
    echo "Cargando datos de demostración..."
    "${FP}" php-cli bin/console app:load-demo-data --no-interaction || true
else
    echo "Inicializando datos por defecto..."
    "${FP}" php-cli bin/console app:setup --no-interaction || true
fi

# -- Worker de Messenger (envío de emails en segundo plano) ---------------------
# FrankenPHP es monoproceso y el Caddyfile no puede supervisar workers, así que
# lanzamos el consumidor en segundo plano. El --time-limit recicla el proceso
# periódicamente; el bucle lo relanza si termina o falla.
cd "${APP}"
(
    while true; do
        "${FP}" php-cli bin/console messenger:consume async scheduler_default --time-limit=3600 --memory-limit=128M --quiet || true
        sleep 2
    done
) &
WORKER_PID=$!

cleanup() {
    if [[ -n "${WORKER_PID:-}" ]]; then
        pkill -P "${WORKER_PID}" 2>/dev/null || true
        kill "${WORKER_PID}" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

# -- Arrancar servidor ----------------------------------------------------------
cd "${ROOT}"
echo ""
echo "  ÁTICA Calidad disponible en → http://localhost:${PORT}"
echo "  Pulsa Ctrl+C para detener."
echo ""
"${FP}" run --config Caddyfile
