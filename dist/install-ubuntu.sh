#!/usr/bin/env bash
# =============================================================================
# install-ubuntu.sh — Instalación automatizada de ÁTICA Calidad en Ubuntu Server
#
# Compatible: Ubuntu Server 26.04 LTS (y versiones posteriores de Ubuntu LTS)
# Arquitecturas: x86_64, aarch64
#
# Uso (desde el directorio del paquete descargado):
#   sudo bash install-ubuntu.sh
#
# Uso (descarga directa):
#   curl -fsSL https://raw.githubusercontent.com/reasol-edu/atica-calidad/main/dist/install-ubuntu.sh \
#     | sudo bash
#
# Qué instala este script:
#   1. PostgreSQL (paquete oficial de Ubuntu) + base de datos y usuario
#   2. Cortafuegos UFW con los puertos mínimos abiertos (SSH, y HTTP/HTTPS salvo
#      que se use Cloudflare Tunnel, ver más abajo)
#   3. Usuario del sistema «aticacalidad» y directorio /opt/atica-calidad
#   4. Binario de ÁTICA Calidad (última versión publicada en GitHub Releases)
#   5. Fichero de configuración (.env.local)
#   6. Si el servidor está detrás de un NAT/cortafuegos sin puertos abiertos:
#      Cloudflare Tunnel (cloudflared), en vez de exponer 80/443 directamente
#   7. Scripts de arranque del servidor y del worker de mensajería
#   8. Servicios systemd atica-calidad y atica-calidad-worker (arranque automático)
#
# Requisitos previos:
#   - Ubuntu Server 26.04 LTS (o posterior) con acceso a Internet
#   - Ejecutar como root o con: sudo bash install-ubuntu.sh
#   - Un nombre de dominio (p. ej. atica.tucentro.es) apuntando a este servidor,
#     necesario para que FrankenPHP/Caddy obtenga el certificado TLS automático —
#     salvo que el servidor esté detrás de un NAT/cortafuegos sin puertos abiertos,
#     en cuyo caso el script puede configurar Cloudflare Tunnel en su lugar (hace
#     falta un token de túnel ya creado, ver docs/despliegue/cloudflare-tunnel.md)
# =============================================================================
set -euo pipefail

# ── colores y helpers ──────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

step() { echo -e "\n${CYAN}${BOLD}▶  $*${NC}"; }
ok()   { echo -e "   ${GREEN}✔${NC}  $*"; }
warn() { echo -e "   ${YELLOW}⚠${NC}   $*"; }
die()  { echo -e "\n${RED}✘  Error: $*${NC}" >&2; exit 1; }

# ── verificaciones previas ────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Ejecuta el script como root:  sudo bash $0"

if [[ -f /etc/os-release ]]; then
    # shellcheck source=/dev/null
    source /etc/os-release
    if [[ "${ID:-}" != "ubuntu" ]]; then
        warn "Este script está diseñado para Ubuntu. Tu sistema es: ${PRETTY_NAME:-desconocido}."
        warn "Puede funcionar en distribuciones derivadas, pero no está garantizado."
        read -rp "   ¿Continuar de todas formas? [s/N] " FORCE </dev/tty
        [[ "${FORCE:-N}" =~ ^[Ss]$ ]] || { echo "Instalación cancelada."; exit 0; }
    fi
fi

ARCH=$(uname -m)
case "$ARCH" in
    x86_64)  ASSET_ARCH="linux-x86_64"  ;;
    aarch64) ASSET_ARCH="linux-aarch64" ;;
    *)       die "Arquitectura no soportada: ${ARCH}. Solo x86_64 y aarch64." ;;
esac

# ── banner ────────────────────────────────────────────────────────────────────
echo -e "
${BOLD}╔══════════════════════════════════════════════════════╗
║       ÁTICA Calidad — Instalación en Ubuntu Server      ║
╚══════════════════════════════════════════════════════╝${NC}

Este script instalará ÁTICA Calidad con:
  •  FrankenPHP como servidor web (HTTPS automático vía Let's Encrypt,
     o Cloudflare Tunnel si el servidor está detrás de un NAT/cortafuegos)
  •  PostgreSQL como base de datos
  •  Dos o tres servicios systemd con arranque automático al reiniciar
"

# ── solicitar configuración ───────────────────────────────────────────────────
step "Configuración"

while true; do
    read -rp "   Nombre de dominio (p.ej. atica.tucentro.es): " DOMAIN </dev/tty
    [[ -n "$DOMAIN" && "$DOMAIN" != *" "* ]] && break
    warn "El dominio no puede estar vacío ni contener espacios."
done

while true; do
    read -rsp "   Contraseña de la base de datos (mín. 12 caracteres, sin comillas ni \\ \$ \`): " DB_PASS </dev/tty
    echo
    if [[ ${#DB_PASS} -ge 12 ]] \
        && [[ "$DB_PASS" != *"'"* ]] \
        && [[ "$DB_PASS" != *'"'* ]] \
        && [[ "$DB_PASS" != *'\'* ]] \
        && [[ "$DB_PASS" != *'$'* ]] \
        && [[ "$DB_PASS" != *'`'* ]]; then
        break
    fi
    warn "Contraseña inválida: mínimo 12 caracteres, sin comillas simples/dobles, barra invertida (\\), signo dólar (\$) ni acento grave (\`)."
done

read -rp "   Dirección de correo remitente [no-responder@${DOMAIN}]: " MAIL_FROM </dev/tty
MAIL_FROM="${MAIL_FROM:-no-responder@${DOMAIN}}"

echo -e "
   Si este servidor está detrás de un NAT o de un cortafuegos sobre el que no
   tienes control (no puedes pedir la apertura de los puertos 80/443), puedes
   usar Cloudflare Tunnel: el servidor abre una conexión saliente hacia
   Cloudflare y no hace falta abrir ningún puerto entrante. Hace falta tener
   ya creado el túnel en el dashboard de Cloudflare (Zero Trust → Networks →
   Tunnels → Create a tunnel → Cloudflared) y el token que se muestra en el
   comando 'cloudflared service install <token>'. Más detalles en
   docs/despliegue/cloudflare-tunnel.md.
"
read -rp "   ¿Usar Cloudflare Tunnel en vez de exponer 80/443 directamente? [s/N] " USE_TUNNEL_RAW </dev/tty
if [[ "${USE_TUNNEL_RAW:-N}" =~ ^[Ss]$ ]]; then
    USE_TUNNEL=true
    while true; do
        read -rsp "   Token del túnel de Cloudflare: " TUNNEL_TOKEN </dev/tty
        echo
        [[ -n "$TUNNEL_TOKEN" ]] && break
        warn "El token no puede estar vacío."
    done
else
    USE_TUNNEL=false
fi

echo -e "
   ${BOLD}Dominio:${NC}   ${DOMAIN}
   ${BOLD}Base BD:${NC}   atica  (usuario: atica)
   ${BOLD}Correo:${NC}    ${MAIL_FROM}
   ${BOLD}Acceso:${NC}    $([[ "$USE_TUNNEL" == true ]] && echo "Cloudflare Tunnel" || echo "HTTPS directo (Let's Encrypt)")
"
read -rp "   ¿Empezar la instalación? [S/n] " CONFIRM </dev/tty
[[ "${CONFIRM:-S}" =~ ^[Ss]?$ ]] || { echo "Instalación cancelada."; exit 0; }

# ── 1. PostgreSQL ─────────────────────────────────────────────────────────────
step "1/8 · Instalar PostgreSQL"
DEBIAN_FRONTEND=noninteractive apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq postgresql postgresql-client curl
ok "PostgreSQL instalado"

step "    Crear base de datos y usuario"
sudo -u postgres psql -v ON_ERROR_STOP=1 << SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'atica') THEN
    CREATE USER atica WITH PASSWORD '${DB_PASS}';
  ELSE
    ALTER USER atica WITH PASSWORD '${DB_PASS}';
  END IF;
END
\$\$;
SELECT 'CREATE DATABASE atica' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'atica')\gexec
GRANT ALL PRIVILEGES ON DATABASE atica TO atica;
ALTER DATABASE atica OWNER TO atica;
SQL
ok "Base de datos 'atica' y usuario 'atica' listos"

# ── 2. Cortafuegos ────────────────────────────────────────────────────────────
step "2/8 · Configurar cortafuegos (UFW)"
ufw allow OpenSSH  > /dev/null
if [[ "$USE_TUNNEL" == true ]]; then
    # Con Cloudflare Tunnel la conexión es siempre saliente: no hace falta
    # abrir ningún puerto entrante más que SSH.
    ufw --force enable > /dev/null
    ok "UFW activo: solo SSH abierto (Cloudflare Tunnel no necesita 80/443)"
else
    ufw allow 80/tcp   > /dev/null
    ufw allow 443/tcp  > /dev/null
    ufw allow 443/udp  > /dev/null   # HTTP/3 (QUIC)
    ufw --force enable > /dev/null
    ok "UFW activo: SSH, HTTP y HTTPS abiertos"
fi

# ── 3. Usuario del sistema ────────────────────────────────────────────────────
step "3/8 · Crear usuario del sistema 'aticacalidad'"
if ! id aticacalidad &> /dev/null; then
    useradd -r -d /opt/atica-calidad -s /usr/sbin/nologin aticacalidad
    ok "Usuario 'aticacalidad' creado"
else
    ok "Usuario 'aticacalidad' ya existía"
fi
mkdir -p /opt/atica-calidad
chown aticacalidad:aticacalidad /opt/atica-calidad

# ── 4. Binario de ÁTICA Calidad ─────────────────────────────────────────────────
step "4/8 · Descargar el binario de ÁTICA Calidad (última versión)"
VERSION=$(curl -fsSL https://api.github.com/repos/reasol-edu/atica-calidad/releases/latest \
    | grep '"tag_name"' | sed 's/.*"v\([^"]*\)".*/\1/')
[[ -n "$VERSION" ]] || die "No se pudo obtener la versión más reciente desde GitHub."
TARBALL_URL="https://github.com/reasol-edu/atica-calidad/releases/download/v${VERSION}/atica-calidad-v${VERSION}-${ASSET_ARCH}.tar.gz"
echo "   Descargando atica-calidad v${VERSION} (${ASSET_ARCH})..."
curl -fsSL "$TARBALL_URL" | sudo -u aticacalidad tar xzf - -C /opt/atica-calidad --strip-components=1
ok "ÁTICA Calidad v${VERSION} extraído en /opt/atica-calidad"

# ── 5. Configuración ─────────────────────────────────────────────────────────
step "5/8 · Crear fichero de configuración (.env.local)"
if [[ -f /opt/atica-calidad/.env.local ]]; then
    warn ".env.local ya existe; se conserva. Revisa que DATABASE_URL y SERVER_ADDR sean correctos."
else
if [[ "$USE_TUNNEL" == true ]]; then
    # Caddy escucha solo en local sin TLS automático: lo termina Cloudflare.
    # SYMFONY_TRUSTED_PROXIES es necesario para que la aplicación lea la IP
    # real del visitante y el esquema https:// originales, que cloudflared
    # reenvía como cabeceras X-Forwarded-* a través de Caddy en localhost.
    SERVER_ADDR_VALUE="127.0.0.1:8080"
    TRUSTED_PROXIES_LINE='SYMFONY_TRUSTED_PROXIES="127.0.0.1"'
else
    SERVER_ADDR_VALUE="${DOMAIN}"
    TRUSTED_PROXIES_LINE=""
fi
sudo -u aticacalidad tee /opt/atica-calidad/.env.local > /dev/null << ENVFILE
# ÁTICA Calidad — configuración de producción en Ubuntu Server
# Generado automáticamente el $(date '+%Y-%m-%d %H:%M:%S')
# Edita este fichero para cambiar dominio, correo, etc.
# Después: sudo systemctl restart atica-calidad atica-calidad-worker

SERVER_ADDR="${SERVER_ADDR_VALUE}"
DEFAULT_URI="https://${DOMAIN}"
DATABASE_URL="postgresql://atica:${DB_PASS}@localhost:5432/atica?serverVersion=16&charset=utf8"
MIGRATIONS_PATH="migrations/postgresql"
MAILER_DSN="null://null"
MAILER_FROM="${MAIL_FROM}"
APP_EXTERNAL_ENABLED="true"
${TRUSTED_PROXIES_LINE}
ENVFILE
chmod 600 /opt/atica-calidad/.env.local
ok ".env.local creado con permisos 600"
fi

# ── 6. Cloudflare Tunnel ──────────────────────────────────────────────────────
if [[ "$USE_TUNNEL" == true ]]; then
    step "6/8 · Instalar y configurar Cloudflare Tunnel"
    mkdir -p --mode=0755 /usr/share/keyrings
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
        | tee /usr/share/keyrings/cloudflare-main.gpg > /dev/null
    echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared $(lsb_release -cs 2>/dev/null || echo noble) main" \
        | tee /etc/apt/sources.list.d/cloudflared.list > /dev/null
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq cloudflared
    cloudflared service install "${TUNNEL_TOKEN}"
    ok "cloudflared instalado y arrancado como servicio systemd"
    warn "Falta configurar el «Public Hostname» del túnel en el dashboard de Cloudflare"
    warn "(pestaña Public Hostname del túnel → ${DOMAIN} → HTTP → localhost:8080), si no lo has hecho ya."
else
    step "6/8 · Cloudflare Tunnel (omitido)"
fi

# ── 7. Scripts de arranque ────────────────────────────────────────────────────
step "7/8 · Crear scripts de arranque"

# — atica-calidad-start.sh (servidor web) ————————————————————————————————————
sudo -u aticacalidad tee /opt/atica-calidad/atica-calidad-start.sh > /dev/null << 'STARTSCRIPT'
#!/usr/bin/env bash
# atica-calidad-start.sh — arranca FrankenPHP leyendo la configuración de .env.local
# No modifiques directamente las variables hardcodeadas de start.sh original;
# usa .env.local para toda la configuración de este servidor.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA="${ROOT}/data"
APP="${ROOT}/app"
FP="${ROOT}/frankenphp"

# Cargar configuración local (anula los valores por defecto del binario)
set -a
# shellcheck source=/dev/null
[[ -f "${ROOT}/.env.local" ]] && source "${ROOT}/.env.local"
set +a

# Valores requeridos
: "${SERVER_ADDR:?Falta SERVER_ADDR en .env.local (nombre de dominio o :puerto)}"
: "${DATABASE_URL:?Falta DATABASE_URL en .env.local}"
: "${DEFAULT_URI:?Falta DEFAULT_URI en .env.local}"

# Valores con defecto
export APP_ENV=prod
export APP_DEBUG=0
export DOCUMENT_ROOT="${APP}/public"
export MIGRATIONS_PATH="${MIGRATIONS_PATH:-migrations/postgresql}"
export APP_LOG="${APP_LOG:-true}"
export APP_LOG_RETENTION_DAYS="${APP_LOG_RETENTION_DAYS:-90}"
export APP_EXTERNAL_ENABLED="${APP_EXTERNAL_ENABLED:-true}"
export APP_EXTERNAL_URL="${APP_EXTERNAL_URL:-https://seneca.juntadeandalucia.es/seneca/jsp/ComprobarUsuarioExt.jsp}"
export APP_EXTERNAL_URL_FORCE_SECURITY="${APP_EXTERNAL_URL_FORCE_SECURITY:-true}"
export MAILER_DSN="${MAILER_DSN:-null://null}"
export MAILER_FROM="${MAILER_FROM:-no-responder@example.com}"
export MESSENGER_TRANSPORT_DSN="${MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}"

mkdir -p "${DATA}"

# Generar APP_SECRET en el primer arranque y guardarlo en data/.secret.
# Se usa -s (existe Y no está vacío) en vez de -f: una ejecución anterior
# interrumpida a mitad de camino (p.ej. por un fallo posterior en las
# migraciones) puede dejar el fichero creado pero con 0 bytes, y un simple
# -f nunca lo regeneraría, propagando un secreto vacío en cada arranque.
if [[ ! -s "${DATA}/.secret" ]]; then
    "${FP}" php-cli -r 'echo bin2hex(random_bytes(32));' > "${DATA}/.secret"
fi
export APP_SECRET="$(< "${DATA}/.secret")"

# Escribir app/.env para que Symfony lo lea vía bootEnv (requerido por el binario)
cat > "${APP}/.env" << EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}
DATABASE_URL=${DATABASE_URL}
MIGRATIONS_PATH=${MIGRATIONS_PATH}
DEFAULT_URI=${DEFAULT_URI}
SERVER_ADDR=${SERVER_ADDR}
APP_LOG=${APP_LOG}
APP_LOG_RETENTION_DAYS=${APP_LOG_RETENTION_DAYS}
APP_EXTERNAL_ENABLED=${APP_EXTERNAL_ENABLED}
APP_EXTERNAL_URL=${APP_EXTERNAL_URL}
APP_EXTERNAL_URL_FORCE_SECURITY=${APP_EXTERNAL_URL_FORCE_SECURITY}
MAILER_DSN=${MAILER_DSN}
MAILER_FROM=${MAILER_FROM}
MESSENGER_TRANSPORT_DSN=${MESSENGER_TRANSPORT_DSN}
EOF

# Inicializar: migraciones, datos por defecto y caché de producción
cd "${APP}"
rm -rf var/cache/
"${FP}" php-cli bin/console doctrine:migrations:migrate --no-interaction
"${FP}" php-cli bin/console app:setup --no-interaction || true
"${FP}" php-cli bin/console cache:warmup --no-interaction

# Arrancar FrankenPHP en primer plano (systemd gestiona el ciclo de vida)
cd "${ROOT}"
exec "${FP}" run --config Caddyfile
STARTSCRIPT

# — atica-calidad-worker.sh (worker de Messenger) —————————————————————————————
sudo -u aticacalidad tee /opt/atica-calidad/atica-calidad-worker.sh > /dev/null << 'WORKERSCRIPT'
#!/usr/bin/env bash
# atica-calidad-worker.sh — consumidor de mensajes (emails y avisos programados)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA="${ROOT}/data"
APP="${ROOT}/app"
FP="${ROOT}/frankenphp"

set -a
# shellcheck source=/dev/null
[[ -f "${ROOT}/.env.local" ]] && source "${ROOT}/.env.local"
set +a

export APP_ENV=prod
export APP_DEBUG=0
export DOCUMENT_ROOT="${APP}/public"
export MIGRATIONS_PATH="${MIGRATIONS_PATH:-migrations/postgresql}"
export APP_LOG="${APP_LOG:-true}"
export APP_LOG_RETENTION_DAYS="${APP_LOG_RETENTION_DAYS:-90}"
export APP_EXTERNAL_ENABLED="${APP_EXTERNAL_ENABLED:-true}"
export APP_EXTERNAL_URL="${APP_EXTERNAL_URL:-https://seneca.juntadeandalucia.es/seneca/jsp/ComprobarUsuarioExt.jsp}"
export APP_EXTERNAL_URL_FORCE_SECURITY="${APP_EXTERNAL_URL_FORCE_SECURITY:-true}"
export MAILER_DSN="${MAILER_DSN:-null://null}"
export MAILER_FROM="${MAILER_FROM:-no-responder@example.com}"
export MESSENGER_TRANSPORT_DSN="${MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}"

# Esperar a que atica-calidad-start.sh haya generado el secreto (primer arranque).
# -s (existe Y no está vacío), no solo -f: ver el comentario equivalente en
# atica-calidad-start.sh.
until [[ -s "${DATA}/.secret" ]]; do
    sleep 1
done
export APP_SECRET="$(< "${DATA}/.secret")"

cat > "${APP}/.env" << EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}
DATABASE_URL=${DATABASE_URL}
MIGRATIONS_PATH=${MIGRATIONS_PATH}
DEFAULT_URI=${DEFAULT_URI}
SERVER_ADDR=${SERVER_ADDR}
APP_LOG=${APP_LOG}
APP_LOG_RETENTION_DAYS=${APP_LOG_RETENTION_DAYS}
APP_EXTERNAL_ENABLED=${APP_EXTERNAL_ENABLED}
APP_EXTERNAL_URL=${APP_EXTERNAL_URL}
APP_EXTERNAL_URL_FORCE_SECURITY=${APP_EXTERNAL_URL_FORCE_SECURITY}
MAILER_DSN=${MAILER_DSN}
MAILER_FROM=${MAILER_FROM}
MESSENGER_TRANSPORT_DSN=${MESSENGER_TRANSPORT_DSN}
EOF

cd "${APP}"
exec "${FP}" php-cli bin/console messenger:consume async scheduler_default \
    --time-limit=3600 --memory-limit=128M --quiet
WORKERSCRIPT

chmod +x /opt/atica-calidad/atica-calidad-start.sh /opt/atica-calidad/atica-calidad-worker.sh
ok "atica-calidad-start.sh y atica-calidad-worker.sh creados"

# ── 8. Servicios systemd ──────────────────────────────────────────────────────
step "8/8 · Instalar y arrancar los servicios systemd"

tee /etc/systemd/system/atica-calidad.service > /dev/null << 'UNIT'
[Unit]
Description=ÁTICA Calidad (FrankenPHP)
Documentation=https://reasol-edu.github.io/atica-calidad/
After=network-online.target postgresql.service
Wants=network-online.target
Requires=postgresql.service

[Service]
Type=simple
User=aticacalidad
Group=aticacalidad
WorkingDirectory=/opt/atica-calidad
ExecStart=/opt/atica-calidad/atica-calidad-start.sh
Restart=on-failure
RestartSec=5
TimeoutStopSec=30
LimitNOFILE=65536
# Permite escuchar en los puertos 80 y 443 sin ejecutar como root
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
UNIT

tee /etc/systemd/system/atica-calidad-worker.service > /dev/null << 'UNIT'
[Unit]
Description=ÁTICA Calidad Worker (Messenger + Scheduler)
After=atica-calidad.service
Requires=atica-calidad.service

[Service]
Type=simple
User=aticacalidad
Group=aticacalidad
WorkingDirectory=/opt/atica-calidad
ExecStart=/opt/atica-calidad/atica-calidad-worker.sh
Restart=always
RestartSec=10
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now atica-calidad atica-calidad-worker
ok "Servicios activos y habilitados para el arranque automático"

# ── resultado ─────────────────────────────────────────────────────────────────
if [[ "$USE_TUNNEL" == true ]]; then
    TUNNEL_NOTE="
  ${YELLOW}⚠  Revisa que el Public Hostname del túnel esté configurado en el
     dashboard de Cloudflare (${DOMAIN} → HTTP → localhost:8080); sin eso el
     dominio no responderá aunque el servicio esté activo.${NC}"
    STATUS_CMD="sudo systemctl status atica-calidad atica-calidad-worker cloudflared"
else
    TUNNEL_NOTE="
  ${YELLOW}⚠  En el primer arranque FrankenPHP solicita el certificado TLS.
     Puede tardar 30-60 segundos hasta que HTTPS esté disponible.${NC}"
    STATUS_CMD="sudo systemctl status atica-calidad atica-calidad-worker"
fi

echo -e "
${GREEN}${BOLD}╔══════════════════════════════════════════════════════╗
║              ✔  Instalación completada               ║
╚══════════════════════════════════════════════════════╝${NC}

  URL de acceso: ${BOLD}https://${DOMAIN}${NC}
  Usuario:       ${BOLD}admin${NC}
  Contraseña:    ${BOLD}admin${NC}  ← cámbiala ahora en Perfil
${TUNNEL_NOTE}

  Comandos útiles:
    Ver estado:   ${STATUS_CMD}
    Ver logs:     sudo journalctl -u atica-calidad -f
    Reiniciar:    sudo systemctl restart atica-calidad atica-calidad-worker
    Configurar:   sudo nano /opt/atica-calidad/.env.local
"
