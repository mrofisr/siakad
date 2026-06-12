FROM php:8.3-apache
COPY . /var/www/html/
RUN apt-get update && apt-get install -y curl && rm -rf /var/lib/apt/lists/*
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD curl -f http://localhost/index.php?page=health || exit 1
EXPOSE 80
