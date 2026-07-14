# Dev image: PHP 8.3 CLI + the extensions Laravel 13/Filament v5 need + composer.
# Code is bind-mounted by docker-compose.yml, nothing is copied in.
FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
