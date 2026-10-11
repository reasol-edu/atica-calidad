#!/bin/sh
set -e

# Negarse a arrancar con un secreto que sigue siendo el valor de ejemplo: compose ya exige que
# DB_PASSWORD y APP_SECRET existan, pero no puede saber que alguien copió .env.example sin editarlo
# (o que viene de la antigua contraseña por defecto «changeme»).
case "${DATABASE_URL:-}" in
    *:changeme@*|*:cambia_esto_por_una_contrasena_segura@*)
        echo "[atica-calidad] ERROR: la contraseña de la base de datos es un valor de ejemplo." >&2
        echo "[atica-calidad] Define una DB_PASSWORD propia en .env.local (ver .env.example y la sección «Actualización» del manual)." >&2
        exit 1
        ;;
esac
case "${APP_SECRET:-}" in
    cambia_esto_*)
        echo "[atica-calidad] ERROR: APP_SECRET es el valor de ejemplo. Genera uno con: php -r 'echo bin2hex(random_bytes(32));'" >&2
        exit 1
        ;;
esac

echo "[atica-calidad] Aplicando migraciones de base de datos..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

# Datos iniciales: el centro de demostración (IES Ada Lovelace) o, si no se
# pide, solo la cuenta admin/admin por defecto de app:setup. Son mutuamente
# excluyentes: app:load-demo-data falla si el usuario "admin" ya existe, así
# que nunca se llama a app:setup primero cuando se cargan datos de demo.
if [ "${LOAD_DEMO_DATA:-false}" = "true" ]; then
    echo "[atica-calidad] Cargando datos de demostración..."
    php bin/console app:load-demo-data --no-interaction --env=prod || true
else
    echo "[atica-calidad] Inicializando datos por defecto..."
    php bin/console app:setup --no-interaction --env=prod
fi

echo "[atica-calidad] Regenerando caché..."
php bin/console cache:clear --env=prod --no-interaction

echo "[atica-calidad] Iniciando FrankenPHP..."
exec "$@"
