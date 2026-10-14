#!/bin/sh
set -e

# Railway (e outras plataformas) informam a porta em $PORT
export SERVER_NAME=":${PORT:-8080}"

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Cria/atualiza o schema e, se a base fictícia estiver vazia ou velha, recria os dados
php artisan migrate --force
php artisan demo:refresh --if-stale

# Agendador do Laravel (renovação diária da base fictícia) em segundo plano
php artisan schedule:work &

exec frankenphp php-server --root public --listen "$SERVER_NAME"
