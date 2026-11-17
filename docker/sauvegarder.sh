#!/bin/bash
# Sauvegarde de la base et des fichiers du site, dans docker/sauvegardes/.
#
#   ./sauvegarder.sh         (depuis le dossier docker/)
#
# À lancer chaque nuit (crontab du serveur, voir LISEZMOI.md) puis à copier
# HORS du serveur : une sauvegarde restée sur le serveur disparaît avec lui.
set -euo pipefail
cd "$(dirname "$0")"

DOSSIER=sauvegardes
GARDER_JOURS="${GARDER_JOURS:-14}"
DATE=$(date +%Y-%m-%d_%H%M)
mkdir -p "$DOSSIER"
chmod 700 "$DOSSIER"

echo "Base de données…"
docker compose exec -T site drush sql:dump --gzip --result-file=/tmp/base.sql >/dev/null
docker compose cp site:/tmp/base.sql.gz "$DOSSIER/base-$DATE.sql.gz"
docker compose exec -T site rm -f /tmp/base.sql.gz

echo "Fichiers téléversés et privés…"
docker compose exec -T site tar czf - \
  --exclude=files/css --exclude=files/js --exclude=files/php --exclude=files/styles \
  -C /var/www/html/sites/default files -C /var/www private \
  > "$DOSSIER/fichiers-$DATE.tar.gz"

find "$DOSSIER" -type f -mtime +"$GARDER_JOURS" -delete
ls -lh "$DOSSIER" | tail -n 4
