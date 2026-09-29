# Production image for Render (Web Service → Docker, Root Directory: backend).
FROM php:8.3-cli

# gd: PhpSpreadsheet (Excel reports), dompdf images and profile photos (JPEG/PNG/WebP).
# exif: turns phone photos upright.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql zip intl bcmath gd exif \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first so this layer is cached between code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && sed -i 's/\r$//' docker/start.sh \
    && chmod +x docker/start.sh \
    && chown -R www-data:www-data storage bootstrap/cache

# Render sets $PORT; 8000 is the local fallback.
ENV PORT=8000
EXPOSE 8000

CMD ["docker/start.sh"]
