FROM php:8.3-apache

RUN apt-get update && apt-get install -y curl && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && adduser --disabled-password --gecos "" --uid 1000 appuser \
    && mkdir -p /var/www/html/logs \
    && chown -R appuser:appuser /var/www/html \
    && chown -R appuser:appuser /var/run/apache2 /var/lock/apache2 /var/log/apache2

WORKDIR /var/www/html

COPY --chown=appuser:appuser . .

RUN chmod -R 755 /var/www/html \
    && chmod -R 770 /var/www/html/logs

USER appuser

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD curl -f http://localhost/index.php?page=health || exit 1

EXPOSE 80
