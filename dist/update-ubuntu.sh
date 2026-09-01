#!/usr/bin/env bash
# =============================================================================
# update-ubuntu.sh — Actualiza una instalación de ÁTICA Calidad en Ubuntu Server
# (systemd + FrankenPHP + PostgreSQL, ver install-ubuntu.sh) a la última
# versión publicada en GitHub Releases.
#
# Uso (en el servidor, si ya tienes el paquete):
#   sudo bash update-ubuntu.sh [--force]
#
# Uso (descarga y ejecución directa, sin bajar el paquete):
#   curl -fsSL https://raw.githubusercontent.com/reasol-edu/atica-calidad/main/dist/update-ubuntu.sh \
#     | sudo bash -s -- [--force]
#
# --force reinstala aunque la versión publicada parezca igual o anterior a la
# instalada (útil tras una re-release que mueve la misma etiqueta a un commit
# distinto, p. ej. v1.0.0 -f).
#
# Qué hace:
#   1. Comprueba que existe una instalación previa en /opt/atica-calidad
#      (creada con install-ubuntu.sh).
#   2. Consulta la última versión publicada en GitHub Releases y la compara
#      con la instalada (fichero .version). Si coinciden y no se usa
#      --force, no hace nada.
#   3. Si hay una versión más reciente (o se ha pasado --force): para los
#      servicios, descarga y extrae el paquete nuevo sobre /opt/atica-calidad
#      (data/ y .env.local no forman parte del paquete, así que se conservan
#      intactos) y vuelve a arrancar los servicios. atica-calidad-start.sh
#      aplica las migraciones pendientes y regenera la caché en el arranque.
#
# Es seguro ejecutarlo repetidamente (p. ej. desde un cron o un systemd
# timer): si ya está en la última versión, no hace nada y termina en 0. Ver
# la guía de despliegue continuo para automatizarlo:
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

# ── argumentos ─────────────────────────────────────────────────────────────────
FORCE=false
for arg in "$@"; do
    case "$arg" in
        --force) FORCE=true ;;
        *)       die "Argumento desconocido: ${arg}. Uso: $0 [--force]" ;;
    esac
done

# ── verificaciones previas ────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Ejecuta el script como root:  sudo bash $0"

REPO="reasol-edu/atica-calidad"
INSTALL_DIR="/opt/atica-calidad"

[[ -f "${INSTALL_DIR}/frankenphp" ]] \
    || die "No se encuentra ${INSTALL_DIR}/frankenphp. ¿Está instalado ÁTICA Calidad? Para una instalación nueva usa install-ubuntu.sh."
id aticacalidad &> /dev/null \
    || die "No existe el usuario del sistema 'aticacalidad'. ¿Está instalado ÁTICA Calidad? Para una instalación nueva usa install-ubuntu.sh."

ARCH=$(uname -m)
case "$ARCH" in
    x86_64)  ASSET_ARCH="linux-x86_64"  ;;
    aarch64) ASSET_ARCH="linux-aarch64" ;;
    *)       die "Arquitectura no soportada: ${ARCH}. Solo x86_64 y aarch64." ;;
esac

# ── comprobar si hay una versión más reciente ─────────────────────────────────
step "Comprobando la última versión disponible"

REMOTE_TAG=$(curl -fsSL "https://api.github.com/repos/${REPO}/releases/latest" \
    | grep '"tag_name"' | head -1 | sed 's/.*"tag_name": *"\([^"]*\)".*/\1/')
[[ -n "$REMOTE_TAG" ]] || die "No se pudo obtener la última versión desde GitHub."

LOCAL_TAG="$(cat "${INSTALL_DIR}/.version" 2>/dev/null || echo "")"

if [[ -n "$LOCAL_TAG" ]]; then
    ok "Instalada: ${LOCAL_TAG} · Disponible: ${REMOTE_TAG}"
else
    warn "No se encontró ${INSTALL_DIR}/.version; se actualizará de todas formas."
fi

if [[ "$LOCAL_TAG" == "$REMOTE_TAG" ]]; then
    if [[ "$FORCE" == true ]]; then
        warn "Ya estás en la última versión (${REMOTE_TAG}), pero se reinstala por --force."
    else
        ok "Ya estás en la última versión (${REMOTE_TAG}). Nada que hacer."
        exit 0
    fi
fi

# ── descargar ─────────────────────────────────────────────────────────────────
step "Descargando ÁTICA Calidad ${REMOTE_TAG} (${ASSET_ARCH})"

TARBALL_URL="https://github.com/${REPO}/releases/download/${REMOTE_TAG}/atica-calidad-${REMOTE_TAG}-${ASSET_ARCH}.tar.gz"
TMP_FILE="$(mktemp)"
trap 'rm -f "$TMP_FILE"' EXIT

curl -fsSL "$TARBALL_URL" -o "$TMP_FILE" || die "No se pudo descargar ${TARBALL_URL}."
chmod 644 "$TMP_FILE"
ok "Descargado"

# ── parar, extraer y arrancar ─────────────────────────────────────────────────
step "Deteniendo los servicios"
systemctl stop atica-calidad-worker atica-calidad
ok "Servicios detenidos"

step "Extrayendo sobre ${INSTALL_DIR}"
sudo -u aticacalidad tar xzf "$TMP_FILE" -C "$INSTALL_DIR" --strip-components=1
ok "ÁTICA Calidad actualizado a ${REMOTE_TAG}"

step "Arrancando los servicios"
systemctl start atica-calidad atica-calidad-worker
ok "Servicios activos"

echo -e "
${GREEN}${BOLD}✔  Actualización a ${REMOTE_TAG} completada${NC}

  data/ (base de datos, secretos, caché) y .env.local se han conservado
  intactos: no forman parte del paquete descargado.

  Comprobar estado:  sudo systemctl status atica-calidad atica-calidad-worker
  Ver logs:          sudo journalctl -u atica-calidad -f
"
