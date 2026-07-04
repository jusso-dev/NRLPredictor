FROM php:8.3-cli

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libonig-dev \
        libpng-dev libjpeg-dev libfreetype6-dev \
        default-mysql-client curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath zip intl gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Node is only needed if you want to compile assets inside the container.
# Vite build is typically run on the host; we include npm here for convenience.
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# OpenAI Codex CLI — the AI review pass and chat shell out to `codex exec`.
# Auth is NOT baked in: docker-compose mounts the host's ~/.codex at /root/.codex.
RUN npm install -g @openai/codex

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY . .

RUN composer dump-autoload --optimize \
    && npm run build \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
