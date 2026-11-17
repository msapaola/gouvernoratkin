#!/bin/bash
# Restauration d'une sauvegarde faite par sauvegarder.sh.
#
#   cd /opt/stacks/gouvernorat/docker
#   ./restaurer.sh sauvegardes/base-AAAA-MM-JJ_HHMM.sql.gz [sauvegardes/fichiers-AAAA-MM-JJ_HHMM.tar.gz]
#
# La base actuelle est REMPLACÉE par la sauvegarde (après confirmation).
set -euo pipefail
cd "$(dirname "$0")"

BASE="${1:-}"
FICHIERS="${2:-}"
[ -f "$BASE" ] || { echo "Usage : ./restaurer.sh sauvegardes/base-….sql.gz [sauvegardes/fichiers-….tar.gz]"; exit 1; }

read -r -p "Remplacer la base actuelle par $(basename "$BASE") ? (o/N) " reponse
[ "${reponse,,}" = "o" ] || { echo "Abandon."; exit 0; }

echo "Base : suppression des tables, puis import…"
docker compose exec -T web drush sql:drop -y
gunzip -c "$BASE" | docker compose exec -T db sh -c \
  'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE"'

if [ -n "$FICHIERS" ]; then
  [ -f "$FICHIERS" ] || { echo "Archive $FICHIERS introuvable."; exit 1; }
  echo "Fichiers publics et privés…"
  # L'archive contient deux dossiers : files/ (publics) et private/.
  docker compose exec -T web tar xzf - -C /var/www/html/sites/default files < "$FICHIERS"
  docker compose exec -T web tar xzf - -C /var/www private < "$FICHIERS"
  docker compose exec -T web chown -R www-data:www-data /var/www/html/sites/default/files /var/www/private
fi

docker compose exec -T web drush cr
echo "Restauration terminée."
