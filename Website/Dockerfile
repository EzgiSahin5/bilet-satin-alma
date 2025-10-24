FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        zlib1g-dev \
        libonig-dev \
        autoconf \
        dpkg-dev \
        file \
        g++ \
        gcc \
        libc-dev \
        make \
        pkg-config \
        re2c \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-source extract \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-source delete

RUN docker-php-ext-install -j$(nproc) pdo_sqlite mbstring zip

RUN a2enmod rewrite

COPY . /var/www/html/

RUN chown www-data:www-data /var/www/html/database.db \
    && chmod 664 /var/www/html/database.db \
    && chown www-data:www-data /var/www/html \
    && chmod 775 /var/www/html

WORKDIR /var/www/html

CMD ["apache2-foreground"]