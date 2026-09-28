# Foodtruck - image PHP-FPM
FROM php:8.4-fpm-alpine

# Les workers PHP tournent avec l'UID/GID de l'utilisateur de la VM,
# pour que les fichiers créés dans ./src lui appartiennent.
ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_mysql intl zip gd exif bcmath opcache \
 && apk add --no-cache curl git unzip shadow \
 && groupmod -o -g "${GID}" www-data \
 && usermod -o -u "${UID}" -g "${GID}" www-data \
 && apk del shadow

COPY php.ini /usr/local/etc/php/conf.d/zz-foodtruck.ini

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/html
