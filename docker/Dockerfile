# syntax=docker/dockerfile:1
#
# Site du Gouvernorat de Kinshasa — image du service « web ».
#
# Apache + PHP 8.3 (les .htaccess du projet s'appliquent) et le cron horaire
# de Drupal, pilotés par supervisord. La base de données est un service à
# part (« db », image officielle MariaDB) ; le HTTPS est assuré en amont par
# Nginx Proxy Manager. Mode d'emploi : docker/LISEZMOI.md.
#
# Construction depuis la racine du projet : docker build -f docker/Dockerfile .

FROM php:8.3.36-apache-bookworm

# --- Paquets système : client MariaDB (drush sql:*, attente de la base),
#     supervisord, bibliothèques des extensions PHP.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      mariadb-client supervisor curl ca-certificates \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
      libzip-dev libicu-dev \
 && rm -rf /var/lib/apt/lists/*

# --- Extensions PHP demandées par Drupal 11 et les modules du site.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql opcache intl zip exif bcmath \
 && docker-php-source extract \
 && mkdir -p /usr/src/php/ext/apcu \
 && curl -fsSL https://pecl.php.net/get/apcu-5.1.24.tgz | tar xz --strip-components=1 -C /usr/src/php/ext/apcu \
 && docker-php-ext-install apcu \
 && docker-php-source delete

# --- Réglages d'Apache, de PHP et de supervisord.
RUN a2enmod rewrite headers expires \
 && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/gouvernorat.conf \
 && a2enconf gouvernorat
COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-gouvernorat.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/gouvernorat.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint-gouvernorat
COPY docker/cron.sh /usr/local/bin/cron-drupal
COPY docker/drush.sh /usr/local/bin/drush
RUN chmod +x /usr/local/bin/entrypoint-gouvernorat /usr/local/bin/cron-drupal /usr/local/bin/drush

# --- Code du site (vendor/ est versionné : pas de composer install).
WORKDIR /var/www/html
COPY . /var/www/html/

# Configuration exportée : rangée hors de la racine web et hors du volume des
# fichiers (sinon le volume la masquerait). Réglages Drupal du conteneur.
RUN mkdir -p /var/www/config /var/www/private \
 && mv sites/default/files/config_*/sync /var/www/config/sync \
 && rm -rf sites/default/files/* docker \
 && chown -R www-data:www-data sites/default/files /var/www/private
COPY docker/settings.php /var/www/html/sites/default/settings.php

# Fichiers téléversés et fichiers privés.
VOLUME ["/var/www/html/sites/default/files", "/var/www/private"]

EXPOSE 80

HEALTHCHECK --interval=1m --timeout=10s --start-period=2m \
  CMD curl -fsS -o /dev/null http://127.0.0.1/robots.txt || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint-gouvernorat"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
