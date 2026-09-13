# =============================================================================
# PrimePower HRIS — one container for Hostforge
# =============================================================================
#
# One app, built in two stages. `frontend/` and `backend/` separate the
# dependencies, not the deployment: the React pages are Inertia pages that
# Laravel renders, so the JavaScript is compiled here and handed to Laravel
# rather than served on its own.
#
# The directory layout inside the build mirrors the repository (`/app/frontend`
# beside `/app/backend`) on purpose. `frontend/vite.config.js` writes its output
# to `../backend/public/build`, and keeping the same shape means that relative
# path lands in the same place here as it does on a laptop.
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1 — compile the frontend
# -----------------------------------------------------------------------------
FROM node:20-alpine AS frontend

WORKDIR /app/frontend

# Dependencies first, so a code change does not re-download every package.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY frontend/ ./

# Tailwind only emits classes it can see, and the Blade shell is markup too —
# `tailwind.config.js` scans `../backend/resources/views`. Without these files
# in the build, classes used only there vanish from the stylesheet silently.
COPY backend/resources/views /app/backend/resources/views

# Writes to /app/backend/public/build — NOT to frontend/dist. See the note on
# `publicDirectory` in frontend/vite.config.js.
RUN npm run build


# -----------------------------------------------------------------------------
# Stage 2 — the Laravel app that serves it
# -----------------------------------------------------------------------------
FROM php:8.3-cli-alpine

# Build headers for the extensions below, plus the runtime libraries they need.
RUN apk add --no-cache \
        curl \
        git \
        unzip \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        postgresql-dev

# Only extensions the official image does NOT already include. `pdo`,
# `mbstring`, `openssl`, `ctype`, `fileinfo` and `tokenizer` are compiled into
# php:8.3 already, and asking docker-php-ext-install to build one of those
# again is a common way for this exact step to fail.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# PHP dependencies first, for the same caching reason as npm above.
# `--no-scripts` because artisan is not in the image yet at this point.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY backend/ ./

# The compiled frontend goes into public/build — beside index.php, not over
# the top of the whole public/ directory, which would delete Laravel's entry
# point. This is where the Blade's @vite() directive reads manifest.json from.
COPY --from=frontend /app/backend/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction

# Laravel writes sessions, cache, compiled views and logs here.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Hostforge expects port 8000 and a health check on /up (Laravel's built-in
# health route, registered in bootstrap/app.php).
EXPOSE 8000

# Several PHP workers rather than one: `artisan serve` handles one request at
# a time otherwise, and a single slow page would stall every other visitor.
ENV PHP_CLI_SERVER_WORKERS=4

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT:-8000}/up" || exit 1

# Start-up steps live in docker/start.sh: storage link, optional migrations
# (RUN_MIGRATIONS=true), config/route/view caching, then the server.
COPY docker/start.sh /usr/local/bin/start-hris
RUN sed -i 's/\r$//' /usr/local/bin/start-hris && chmod +x /usr/local/bin/start-hris

CMD ["start-hris"]
