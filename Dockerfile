FROM php:8.3-apache

# pdo_pgsql for Supabase; no pdo_mysql — this app is Postgres-only now.
# opcache matters more than usual here: Render's free tier spins instances
# down, so every wake-up re-compiles the whole framework otherwise.
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    && docker-php-ext-install \
    pdo_pgsql \
    mbstring \
    bcmath \
    zip \
    exif \
    opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=0'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    # The code never changes inside a running container, so skip the stat()
    # per include. A deploy builds a new image, so this can't serve stale code.
    echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, in their own layer: composer.json/lock change far less
# often than app code, so image rebuilds skip the install most of the time.
# --no-scripts because artisan isn't copied in yet.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi

# .dockerignore strips these out of the build context, so recreate the ones
# the framework writes into at runtime.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' \
    /etc/apache2/sites-available/000-default.conf

RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    >> /etc/apache2/apache2.conf

EXPOSE 10000

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

CMD ["/usr/local/bin/entrypoint.sh"]
