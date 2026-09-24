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
#      intactos), borra de app/ lo que la versión nueva ya no trae y vuelve
#      a arrancar los servicios. atica-calidad-start.sh
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
STAGE_DIR=""
trap 'rm -f "$TMP_FILE"; [[ -n "$STAGE_DIR" ]] && rm -rf "$STAGE_DIR"' EXIT

curl -fsSL "$TARBALL_URL" -o "$TMP_FILE" || die "No se pudo descargar ${TARBALL_URL}."
ok "Descargado"

# ── parar, extraer y arrancar ─────────────────────────────────────────────────
step "Deteniendo los servicios"
systemctl stop atica-calidad-worker atica-calidad
ok "Servicios detenidos"

step "Extrayendo sobre ${INSTALL_DIR}"
# Se extrae primero a un directorio de preparación, propiedad de
# "aticacalidad", en vez de directamente sobre ${INSTALL_DIR}: así se puede
# comparar qué había antes con lo que trae el paquete nuevo y borrar lo que ya
# no exista (ver más abajo), algo que un `tar` directo no puede hacer.
#
# El fichero temporal lo crea `mktemp` con permisos 600, propiedad de root. En
# vez de hacerlo legible para "aticacalidad" (un ACL por defecto en /tmp puede
# impedirlo igualmente), root lo abre aquí —la redirección la resuelve el
# propio bash— y le pasa el descriptor ya abierto al `tar` de "aticacalidad".
STAGE_DIR="$(mktemp -d)"
chown aticacalidad:aticacalidad "$STAGE_DIR"
# El `cp -a` final copia también los permisos del directorio de preparación
# (700, de mktemp) sobre ${INSTALL_DIR}: se igualan antes a los actuales.
chmod --reference="$INSTALL_DIR" "$STAGE_DIR"
sudo -u aticacalidad tar xzf - -C "$STAGE_DIR" --strip-components=1 < "$TMP_FILE"
[[ -f "${STAGE_DIR}/app/bin/console" ]] \
    || die "El paquete descargado no tiene la estructura esperada (falta app/bin/console). No se ha tocado la instalación."

# tar (y cp) solo añaden y sobrescriben: un fichero eliminado del código en una
# versión (p. ej. una clase renombrada o una migración retirada) se quedaría
# huérfano en el servidor para siempre, y Symfony puede fallar al arrancar si
# encuentra uno bajo app/src/ o app/config/. Se compara el app/ instalado con
# el del paquete nuevo y se borra lo que ya no exista en este último. Se
# excluyen var/ (caché y logs) y .env, que los genera atica-calidad-start.sh en
# cada arranque y no forman parte del paquete.
if [[ -d "${INSTALL_DIR}/app" ]]; then
    REMOVED=0
    while IFS= read -r -d '' rel; do
        [[ "$rel" == "var" || "$rel" == var/* || "$rel" == ".env" ]] && continue
        # Ya borrado con su carpeta.
        [[ -e "${INSTALL_DIR}/app/${rel}" || -L "${INSTALL_DIR}/app/${rel}" ]] || continue
        if [[ ! -e "${STAGE_DIR}/app/${rel}" && ! -L "${STAGE_DIR}/app/${rel}" ]]; then
            rm -rf -- "${INSTALL_DIR}/app/${rel}"
            REMOVED=$((REMOVED + 1))
        fi
    done < <(cd "${INSTALL_DIR}/app" && find . -mindepth 1 -printf '%P\0' 2>/dev/null)
    (( REMOVED == 0 )) || ok "Eliminados ${REMOVED} ficheros o carpetas que ya no forman parte de la aplicación"
fi

# data/ llega vacío dentro del paquete: copiar sobre un directorio existente no
# borra su contenido, así que la base de datos, los secretos y .env.local
# (que no está en el paquete) se conservan.
sudo -u aticacalidad cp -a "${STAGE_DIR}/." "$INSTALL_DIR"
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
