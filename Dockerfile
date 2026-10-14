FROM composer:2.10.3 AS composer
FROM php:8.5.10-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && docker-php-ext-install pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/entrypoint.sh /usr/local/bin/bgm-entrypoint
RUN chmod +x /usr/local/bin/bgm-entrypoint \
    && rm -f /usr/local/etc/php/conf.d/docker-php-ext-pdo_sqlite.ini /usr/local/etc/php/conf.d/docker-php-ext-sqlite3.ini
WORKDIR /app
ENTRYPOINT ["bgm-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8088"]
