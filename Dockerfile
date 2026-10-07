FROM php:8.4-cli

# System tools and PHP extensions needed by the app (PostgreSQL driver, queue signals).
COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && apt-get clean \
    && install-php-extensions pdo_pgsql pcntl bcmath zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first: this layer is cached until composer.json / composer.lock change.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
