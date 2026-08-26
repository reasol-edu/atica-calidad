# ── Etapa 1: construcción de dependencias y assets ────────────────────────────
FROM dunglas/frankenphp:php8.4-alpine AS builder

RUN apk add --no-cache git

RUN install-php-extensions \
    gd \
    intl \
    opcache \
    pdo_pgsql \
    pdo_sqlite \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Instalar dependencias PHP sin scripts para aprovechar la caché de capas
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .

RUN composer dump-autoload \
    --optimize \
    --no-dev \
    --classmap-authoritative

# Compilar assets
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV DATABASE_URL="sqlite:////tmp/ci-build.db"
ENV APP_SECRET="build-placeholder-secret-00000000"

RUN php bin/console importmap:install
RUN php bin/console tailwind:build --minify
RUN php bin/console asset-map:compile

# ── Etapa 2: imagen de producción ────────────────────────────────────────────
FROM dunglas/frankenphp:php8.4-alpine AS production

RUN install-php-extensions \
    gd \
    intl \
    opcache \
    pdo_pgsql \
    pdo_sqlite \
    zip

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /app

COPY --from=builder /app /app

# Limpiar artefactos del build (var/ se monta como volumen en tiempo de ejecución)
RUN rm -rf var/ && mkdir -p var/cache var/log

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV SERVER_NAME=":80"

VOLUME /app/var

ENTRYPOINT ["/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
