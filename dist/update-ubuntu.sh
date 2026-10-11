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
#   curl -fsSL https://github.com/reasol-edu/atica-calidad/releases/latest/download/update-ubuntu.sh \
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
#   3. Si hay una versión más reciente (o se ha pasado --force): descarga el
#      paquete y comprueba su suma SHA256 contra la de la release (si no
#      coincide, aborta sin tocar nada), para los servicios, extrae el
#      paquete nuevo sobre /opt/atica-calidad
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

REPO="reasol-edu/atica-calidad"
INSTALL_DIR="/opt/atica-calidad"

# ── comprobación de integridad ────────────────────────────────────────────────
# Cada release publica, junto a los paquetes y a este script (update-ubuntu.sh), un fichero
# SHA256SUMS. Nada que se descargue se usa sin comprobar su suma contra ese fichero.
#
# Qué protege y qué no: detecta una descarga corrupta o alterada por el camino, y que el
# script o el paquete no sean los de esa release. NO protege frente a quien pueda publicar
# una release, porque las sumas viajan con ella: eso requeriría una firma independiente.

# Etiqueta de la última release publicada (p. ej. v1.18.0), o nada si no se puede obtener.
latest_tag() {
    curl -fsSL --max-time 30 "https://api.github.com/repos/${REPO}/releases/latest" 2> /dev/null \
        | grep '"tag_name"' | head -1 | sed 's/.*"tag_name": *"\([^"]*\)".*/\1/'
}

# download_sums <etiqueta> <fichero destino>
download_sums() {
    curl -fsSL --max-time 30 "https://github.com/${REPO}/releases/download/$1/SHA256SUMS" -o "$2" 2> /dev/null
}

# expected_sha <SHA256SUMS> <nombre del fichero>  → imprime su suma, o nada si no figura
expected_sha() {
    awk -v f="$2" '{ n = $2; sub(/^\*/, "", n); if (n == f) { print $1; exit } }' "$1"
}

sha256_of() {
    sha256sum "$1" | awk '{ print $1 }'
}

# verify_file <fichero> <SHA256SUMS> <nombre en SHA256SUMS>  → 0 solo si figura y coincide
verify_file() {
    local expected
    expected="$(expected_sha "$2" "$3")"
    [[ -n "$expected" && "$(sha256_of "$1")" == "$expected" ]]
}

# ── autoactualización del script ──────────────────────────────────────────────
# La copia local que ejecuta el temporizador de systemd (atica-calidad-update.sh)
# se descarga una sola vez, al activar el despliegue continuo, y no se renueva
# sola: una copia antigua se quedaría sin las correcciones posteriores (por
# ejemplo, el borrado de ficheros huérfanos de app/, sin el cual una clase
# eliminada en una versión rompe el arranque). Antes de nada se descarga el
# script publicado con la última release (no el de la rama main: solo llega a los
# servidores lo que se ha publicado) y se comprueba contra SHA256SUMS; si es
# distinto, se sustituye y se vuelve a lanzar con los mismos argumentos. Si algo
# falla —sin red, sin SHA256SUMS, suma distinta— se sigue con la copia actual. No
# se hace al ejecutarlo con «curl | bash» (no hay fichero que sustituir) ni en la
# segunda pasada (ATICA_UPDATE_REEXEC evita bucles).
SELF="${BASH_SOURCE[0]:-}"
if [[ -z "${ATICA_UPDATE_REEXEC:-}" && -n "$SELF" && -f "$SELF" && -w "$SELF" ]]; then
    SELF="$(readlink -f "$SELF")"
    SELF_TAG="$(latest_tag || true)"
    # En el mismo directorio, para que el `mv` final sea atómico y no toque el
    # fichero que bash está leyendo ahora mismo.
    NEW_SELF="$(mktemp "${SELF}.new.XXXXXX" 2>/dev/null || true)"
    SELF_SUMS="$(mktemp 2>/dev/null || true)"
    if [[ -n "$NEW_SELF" && -n "$SELF_SUMS" && -n "$SELF_TAG" ]]; then
        if download_sums "$SELF_TAG" "$SELF_SUMS" \
            && curl -fsSL --max-time 30 "https://github.com/${REPO}/releases/download/${SELF_TAG}/update-ubuntu.sh" -o "$NEW_SELF" 2> /dev/null \
            && [[ -s "$NEW_SELF" ]] \
            && verify_file "$NEW_SELF" "$SELF_SUMS" "update-ubuntu.sh" \
            && head -n 1 "$NEW_SELF" | grep -q '^#!.*bash' \
            && bash -n "$NEW_SELF" 2>/dev/null; then
            if ! cmp -s "$NEW_SELF" "$SELF"; then
                if chmod --reference="$SELF" "$NEW_SELF" && mv -f "$NEW_SELF" "$SELF"; then
                    echo -e "${CYAN}${BOLD}▶  Script de actualización renovado a la versión ${SELF_TAG}${NC}"
                    rm -f "$SELF_SUMS"
                    export ATICA_UPDATE_REEXEC=1
                    exec bash "$SELF" "$@"
                fi
                warn "No se pudo sustituir el script de actualización; se sigue con esta copia."
            fi
        else
            warn "No se pudo renovar el script de actualización (sin red, sin comprobar o suma distinta); se sigue con esta copia."
        fi
    fi
    rm -f "${NEW_SELF:-}" "${SELF_SUMS:-}"
fi

# ── verificaciones previas ────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Ejecuta el script como root:  sudo bash $0"

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

TARBALL_NAME="atica-calidad-${REMOTE_TAG}-${ASSET_ARCH}.tar.gz"
TARBALL_URL="https://github.com/${REPO}/releases/download/${REMOTE_TAG}/${TARBALL_NAME}"
TMP_FILE="$(mktemp)"
SUMS_FILE="$(mktemp)"
STAGE_DIR=""
trap 'rm -f "$TMP_FILE" "$SUMS_FILE"; [[ -n "$STAGE_DIR" ]] && rm -rf "$STAGE_DIR"' EXIT

curl -fsSL "$TARBALL_URL" -o "$TMP_FILE" || die "No se pudo descargar ${TARBALL_URL}."
ok "Descargado"

# Nada se extrae ni se instala sin comprobar la suma del paquete contra SHA256SUMS: si falta
# o no coincide, se aborta aquí, antes de parar los servicios, sin tocar la instalación.
download_sums "$REMOTE_TAG" "$SUMS_FILE" \
    || die "No se pudo descargar SHA256SUMS de ${REMOTE_TAG}: no se puede comprobar el paquete. No se ha tocado la instalación."
verify_file "$TMP_FILE" "$SUMS_FILE" "$TARBALL_NAME" \
    || die "La suma de verificación de ${TARBALL_NAME} no coincide con SHA256SUMS (o no figura): la descarga está corrupta o ha sido manipulada. No se ha tocado la instalación."
ok "Suma de verificación correcta"

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
