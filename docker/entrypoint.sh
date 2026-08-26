#!/bin/sh
set -e

echo "[gestconv-plus] Aplicando migraciones de base de datos..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

echo "[gestconv-plus] Inicializando datos por defecto..."
php bin/console app:setup --no-interaction --env=prod

if [ "${LOAD_FIXTURES:-false}" = "true" ]; then
    echo "[gestconv-plus] Cargando datos de demostración..."
    php bin/console doctrine:fixtures:load --no-interaction --append --env=prod
fi

echo "[gestconv-plus] Regenerando caché..."
php bin/console cache:clear --env=prod --no-interaction

echo "[gestconv-plus] Iniciando FrankenPHP..."
exec "$@"
