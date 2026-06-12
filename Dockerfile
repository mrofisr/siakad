FROM dunglas/frankenphp:latest-php8.3-alpine

# Install required extensions and tools
RUN apk add --no-cache curl sqlite \
    && install-php-extensions pdo pdo_sqlite fileinfo \
    && adduser -D -u 1000 appuser

# FrankenPHP serves from /app/public by default
# Copy app files directly into /app/public
WORKDIR /app/public

COPY --chown=appuser:appuser . .

# Create required directories with proper permissions
RUN mkdir -p logs uploads/landing data \
    && chmod -R 755 /app/public \
    && chmod -R 770 /app/public/logs /app/public/uploads /app/public/data \
    && chown -R appuser:appuser /app/public

USER appuser

ENV SERVER_NAME=":8080"
ENV CADDY_SERVER_EXTRA_DIRECTIVES="root * /app/public"

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD curl -f http://localhost:8080/index.php?page=health || exit 1
