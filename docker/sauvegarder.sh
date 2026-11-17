#!/bin/bash
# Sauvegarde de la base et des fichiers du site, dans docker/sauvegardes/.
#
#   cd /opt/stacks/gouvernorat/docker && ./sauvegarder.sh
#
# À lancer chaque nuit (crontab du VPS, voir LISEZMOI.md), puis à copier HORS
# du VPS : une sauvegarde restée sur le serveur disparaît avec lui.
set -euo pipefail
cd "$(dirname "$0")"

DOSSIER=sauvegardes
GARDER_JOURS="${GARDER_JOURS:-14}"
DATE=$(date +%Y-%m-%d_%H%M)
mkdir -p "$DOSSIER"
chmod 700 "$DOSSIER"
umask 077

echo "Base de données (service db)…"
# Identifiants lus dans le conteneur db (variables MARIADB_*), jamais affichés.
docker compose exec -T db sh -c \
  'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump -u"$MARIADB_USER" --single-transaction --quick --routines --triggers "$MARIADB_DATABASE"' \
  | gzip > "$DOSSIER/base-$DATE.sql.gz.part"
mv "$DOSSIER/base-$DATE.sql.gz.part" "$DOSSIER/base-$DATE.sql.gz"

echo "Fichiers publics et privés (service web)…"
docker compose exec -T web tar czf - \
  --exclude=files/css --exclude=files/js --exclude=files/php --exclude=files/styles \
  -C /var/www/html/sites/default files -C /var/www private \
  > "$DOSSIER/fichiers-$DATE.tar.gz.part"
mv "$DOSSIER/fichiers-$DATE.tar.gz.part" "$DOSSIER/fichiers-$DATE.tar.gz"

find "$DOSSIER" -maxdepth 1 -type f \( -name 'base-*.sql.gz' -o -name 'fichiers-*.tar.gz' \) -mtime +"$GARDER_JOURS" -delete
ls -lh "$DOSSIER" | tail -n 4
