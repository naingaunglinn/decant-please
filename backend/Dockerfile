# Dev image: PHP 8.3 CLI + the extensions Laravel 13/Filament v5 need + composer.
# Code is bind-mounted by docker-compose.yml, nothing is copied in.
# gd (with jpeg/webp): image uploads are real photos, and the test suite's
# UploadedFile::fake()->image() refuses to run without it.
# libpq-dev: pdo_pgsql won't build without it. pdo_mysql needed no equivalent, so
# swapping the driver alone gets you a build failure, not a working image.
FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
       libpng-dev libjpeg62-turbo-dev libwebp-dev libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
