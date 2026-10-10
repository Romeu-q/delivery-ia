# --- 1) Front-end: compila Vue/Tailwind com Vite ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# --- 2) Dependências PHP (sem pacotes de desenvolvimento) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

# --- 3) Runtime: FrankenPHP (Caddy + PHP) — atende requisições concorrentes e suporta streaming ---
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_mysql opcache intl zip bcmath pcntl

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && mkdir -p "$PHP_INI_DIR/app.conf.d" \
    && printf "output_buffering=0\nzlib.output_compression=0\nexpose_php=0\nopcache.enable=1\nmemory_limit=256M\n" > "$PHP_INI_DIR/app.conf.d/app.ini"

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN rm -rf node_modules tests .env \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
