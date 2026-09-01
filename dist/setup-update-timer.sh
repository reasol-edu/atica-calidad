#!/usr/bin/env bash
# =============================================================================
# setup-update-timer.sh — Activa el despliegue continuo por sondeo periódico
# (sin webhooks) en una instalación de ÁTICA Calidad en Ubuntu Server
# (systemd + FrankenPHP + PostgreSQL, ver install-ubuntu.sh).
#
# Uso (en el servidor):
#   sudo bash setup-update-timer.sh
#
# Uso (descarga y ejecución directa):
#   curl -fsSL https://raw.githubusercontent.com/reasol-edu/atica-calidad/main/dist/setup-update-timer.sh \
#     | sudo bash
#
# Qué hace:
#   1. Descarga update-ubuntu.sh a /opt/atica-calidad/atica-calidad-update.sh.
#   2. Crea el servicio systemd atica-calidad-update.service, que lo ejecuta.
#   3. Crea el timer systemd atica-calidad-update.timer, que dispara ese
#      servicio cada 15 minutos (con un margen aleatorio de hasta 60s) y
#      también a los 2 minutos de cada arranque del servidor.
#   4. Activa el timer con «systemctl enable --now».
#
# Es idempotente: puede volver a ejecutarse (p. ej. para refrescar
# atica-calidad-update.sh a su última versión) sin duplicar nada.
#
# Es la opción de despliegue continuo que NO requiere abrir ningún puerto
# adicional ni configurar un webhook en GitHub — ver la guía completa:
# https://github.com/reasol-edu/atica-calidad/blob/main/docs/despliegue/despliegue-continuo.md
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

INSTALL_DIR="/opt/atica-calidad"
UPDATE_SCRIPT="${INSTALL_DIR}/atica-calidad-update.sh"

[[ -f "${INSTALL_DIR}/frankenphp" ]] \
    || die "No se encuentra ${INSTALL_DIR}/frankenphp. ¿Está instalado ÁTICA Calidad? Para una instalación nueva usa install-ubuntu.sh."
id aticacalidad &> /dev/null \
    || die "No existe el usuario del sistema 'aticacalidad'. ¿Está instalado ÁTICA Calidad? Para una instalación nueva usa install-ubuntu.sh."

# ── descargar el script de actualización ──────────────────────────────────────
step "Descargando atica-calidad-update.sh"

curl -fsSL https://raw.githubusercontent.com/reasol-edu/atica-calidad/main/dist/update-ubuntu.sh \
    -o "$UPDATE_SCRIPT" || die "No se pudo descargar update-ubuntu.sh."
chmod +x "$UPDATE_SCRIPT"
ok "Guardado en ${UPDATE_SCRIPT}"

# ── crear el servicio y el timer de systemd ───────────────────────────────────
step "Creando el servicio y el timer de systemd"

tee /etc/systemd/system/atica-calidad-update.service > /dev/null << UNIT
[Unit]
Description=ÁTICA Calidad — actualización automática
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
# update-ubuntu.sh para y arranca los servicios systemd y escribe en
# ${INSTALL_DIR} (propiedad de aticacalidad), así que necesita root — igual
# que al ejecutarlo a mano con «sudo bash».
User=root
ExecStart=${UPDATE_SCRIPT}
StandardOutput=journal
StandardError=journal
UNIT

tee /etc/systemd/system/atica-calidad-update.timer > /dev/null << 'UNIT'
[Unit]
Description=ÁTICA Calidad — comprueba actualizaciones cada 15 minutos

[Timer]
OnBootSec=2min
OnUnitActiveSec=15min
RandomizedDelaySec=60

[Install]
WantedBy=timers.target
UNIT

ok "Unidades creadas"

# ── activar el timer ──────────────────────────────────────────────────────────
step "Activando el timer"

systemctl daemon-reload
systemctl enable --now atica-calidad-update.timer
ok "Timer activo"

echo -e "
${GREEN}${BOLD}✔  Despliegue continuo activado${NC}

  El servidor comprobará si hay una versión nueva cada 15 minutos (y 2
  minutos después de cada arranque) y se actualizará solo si la hay.

  Ver el timer:          systemctl list-timers atica-calidad-update.timer
  Forzar una comprobación ahora mismo:
                          sudo systemctl start atica-calidad-update.service
                          journalctl -u atica-calidad-update.service -n 20
"
