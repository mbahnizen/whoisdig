# =============================================================================
# WHOISDIG — Dockerfile
# Optimized for Google Cloud Run (also works on any Docker host)
# =============================================================================

FROM php:8.2-apache AS production

# ── System dependencies & PHP extensions ─────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install \
        intl \
        curl \
        mbstring \
    && apt-get purge -y --auto-remove libicu-dev libcurl4-openssl-dev libonig-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# ── Apache configuration ─────────────────────────────────────────────────────
RUN a2enmod rewrite headers

# Set document root to public/
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    && sed -ri -e 's!/var/www/!/app/!g' /etc/apache2/apache2.conf

# Allow .htaccess overrides (for storage protection)
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' \
    /etc/apache2/apache2.conf

# ── Application code ─────────────────────────────────────────────────────────
COPY . /app
WORKDIR /app

# ── Storage directories & permissions ────────────────────────────────────────
RUN mkdir -p /app/storage/cache /app/storage/logs /app/storage/uploads \
    && chown -R www-data:www-data /app/storage \
    && chmod -R 755 /app/storage

# ── Entrypoint (handles Cloud Run PORT injection) ────────────────────────────
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# ── Cloud Run default port ───────────────────────────────────────────────────
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
