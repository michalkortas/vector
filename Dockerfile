FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev default-mysql-client curl ca-certificates nodejs npm \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/vector-entrypoint
RUN chmod +x /usr/local/bin/vector-entrypoint

EXPOSE 8000
ENTRYPOINT ["vector-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
