#!/bin/bash
set -e

# =============================================================================
# WHOISDIG — Docker Entrypoint
# Configures Apache port at runtime for Cloud Run compatibility
# =============================================================================

# Cloud Run injects PORT env var — default to 8080
PORT="${PORT:-8080}"

# ── Configure Apache to listen on the correct port ───────────────────────────
sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# ── Ensure storage directories exist and are writable ────────────────────────
# (Handles fresh volume mounts where dirs might not exist yet)
mkdir -p /app/storage/cache /app/storage/logs /app/storage/uploads
chown -R www-data:www-data /app/storage

echo "[WHOISDIG] Starting on port ${PORT}..."

# ── Start Apache in foreground ───────────────────────────────────────────────
exec apache2-foreground
