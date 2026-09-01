#!/bin/sh
set -e

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
