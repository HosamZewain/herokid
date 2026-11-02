# Synthetic local test runtime only; never use production configuration/media.
FROM laravelsail/php83-composer:latest
RUN apt-get update && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libmagickwand-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo_mysql \
    && pecl install imagick-3.8.0 && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*
