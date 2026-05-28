FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    git curl unzip libpq-dev icu-dev \
    # ca-certificates installe le bundle Mozilla des autorités de certification racines.
    # Nécessaire pour que PHP (openssl) et curl puissent valider les chaînes SSL/TLS
    # des sites à certificat strict (ex: Resartis, Let's Encrypt intermédiaires récents).
    # update-ca-certificates génère /etc/ssl/certs/ca-certificates.crt à partir du bundle.
    ca-certificates \
    && update-ca-certificates \
    && docker-php-ext-install pdo pdo_pgsql intl opcache

# Indiquer à PHP où trouver le bundle CA pour les deux couches qui en ont besoin :
#   - openssl.cafile → utilisé par les flux PHP natifs (file_get_contents, stream_socket…)
#   - curl.cainfo   → utilisé par l'extension curl et donc par Symfony HTTP Client
# Sans ces directives, ini_get('openssl.cafile') et ini_get('curl.cainfo') retournent ""
# et PHP refuse les connexions HTTPS vers des sites avec chaîne SSL complète.
RUN echo "openssl.cafile=/etc/ssl/certs/ca-certificates.crt" > /usr/local/etc/php/conf.d/ssl-certs.ini \
    && echo "curl.cainfo=/etc/ssl/certs/ca-certificates.crt" >> /usr/local/etc/php/conf.d/ssl-certs.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html