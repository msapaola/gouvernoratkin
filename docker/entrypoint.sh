#!/bin/bash
# Démarrage du service « web » : vérifie les réglages, prépare les dossiers,
# puis attend que la base (service « db ») réponde ET contienne le site
# avant de lancer Apache et le cron.
#
# Pourquoi attendre une table : au premier démarrage, « db » importe l'export
# de docker/import/ ; tant que l'import n'est pas fini, ni drush ni le cron ne
# doivent toucher à la base. Pour un site neuf (sans export), la table
# n'existe qu'après « drush site:install » : le conteneur attend, et
# « docker compose exec web drush … » reste possible pendant ce temps.
set -euo pipefail

journal() { echo "[démarrage] $*"; }

if [ -z "${HASH_SALT:-}" ] || [ -z "${DB_PASSWORD:-}" ]; then
  echo "[démarrage] HASH_SALT et DB_PASSWORD doivent être définis (fichier .env)." >&2
  exit 1
fi

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-drupal}"
DB_USER="${DB_USER:-drupal}"
DB_PREFIX="${DB_PREFIX:-}"
TABLE_TEMOIN="${DB_PREFIX}users"

# Dossiers écrits par le site (volumes) : propriété du serveur web.
mkdir -p /var/www/html/sites/default/files /var/www/private
chown www-data:www-data /var/www/html/sites/default/files /var/www/private

# Le mot de passe passe par l'environnement, pas par la ligne de commande
# (qui serait visible dans la liste des processus).
export MYSQL_PWD="$DB_PASSWORD"
client=(mariadb -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --connect-timeout=5 -N -B)

journal "attente de la base ${DB_NAME} sur ${DB_HOST}:${DB_PORT}…"
until "${client[@]}" -e 'SELECT 1' "$DB_NAME" >/dev/null 2>&1; do
  sleep 3
done

journal "base joignable ; attente de la table ${TABLE_TEMOIN} (import ou installation)…"
attente=0
until [ "$("${client[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${TABLE_TEMOIN}'" "$DB_NAME" 2>/dev/null || echo 0)" = "1" ]; do
  if [ $((attente % 60)) -eq 0 ]; then
    journal "table ${TABLE_TEMOIN} absente : import en cours, ou site à installer (voir docker/LISEZMOI.md)"
  fi
  sleep 5
  attente=$((attente + 5))
done
unset MYSQL_PWD

journal "site présent dans la base : démarrage d'Apache et du cron"
exec "$@"
