#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

set_env() {
  key="$1"
  value="$2"
  if grep -q "^${key}=" .env; then
    sed -i "s#^${key}=.*#${key}=${value}#" .env
  else
    printf '%s=%s\n' "$key" "$value" >> .env
  fi
}

set_env APP_NAME "${APP_NAME:-Vector}"
set_env APP_URL "${APP_URL:-http://localhost:8000}"
set_env DB_CONNECTION "${DB_CONNECTION:-mysql}"
set_env DB_HOST "${DB_HOST:-mariadb}"
set_env DB_PORT "${DB_PORT:-3306}"
set_env DB_DATABASE "${DB_DATABASE:-vector}"
set_env DB_USERNAME "${DB_USERNAME:-vector}"
set_env DB_PASSWORD "${DB_PASSWORD:-vector}"
set_env CACHE_STORE "${CACHE_STORE:-redis}"
set_env QUEUE_CONNECTION "${QUEUE_CONNECTION:-redis}"
set_env REDIS_CLIENT "${REDIS_CLIENT:-predis}"
set_env REDIS_HOST "${REDIS_HOST:-redis}"

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

npm install --include=optional

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

php artisan config:clear

until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
  sleep 2
done

if [ -n "${DB_TEST_DATABASE:-}" ]; then
  mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_ROOT_USERNAME:-root}" -p"${DB_ROOT_PASSWORD:-${DB_PASSWORD}}" \
    -e "CREATE DATABASE IF NOT EXISTS \`${DB_TEST_DATABASE}\`; GRANT ALL PRIVILEGES ON \`${DB_TEST_DATABASE}\`.* TO '${DB_USERNAME}'@'%'; FLUSH PRIVILEGES;" >/dev/null
fi

if [ "${APP_AUTO_SETUP:-true}" = "true" ]; then
  php artisan migrate --force
  php artisan db:seed --force
  npm run build
fi

exec "$@"
