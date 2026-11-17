#!/bin/bash
# Démarrage du conteneur : vérifie les réglages, prépare les dossiers et,
# si la base est dans le conteneur, l'initialise (et importe un export
# placé dans /docker-entrypoint-initdb.d au tout premier démarrage).
set -euo pipefail

journal() { echo "[démarrage] $*"; }

if [ -z "${HASH_SALT:-}" ] || [ -z "${DB_PASSWORD:-}" ]; then
  echo "[démarrage] HASH_SALT et DB_PASSWORD doivent être définis (fichier .env)." >&2
  exit 1
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-drupal}"
DB_USER="${DB_USER:-drupal}"
case "$DB_HOST" in
  127.0.0.1|localhost) export DB_INTERNE=true ;;
  *) export DB_INTERNE=false ;;
esac

# Dossiers écrits par le site (volumes) : propriété du serveur web.
mkdir -p /var/www/html/sites/default/files /var/www/private /data
chown www-data:www-data /var/www/html/sites/default/files /var/www/private

mysql_pret() {
  for _ in $(seq 1 60); do
    mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1 && return 0
    sleep 1
  done
  return 1
}

if [ "$DB_INTERNE" = true ]; then
  mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
  chown -R mysql:mysql /var/lib/mysql
  if [ ! -d /var/lib/mysql/mysql ]; then
    journal "première mise en route : création de la base de données"
    mariadb-install-db --user=mysql --datadir=/var/lib/mysql --skip-test-db >/dev/null
  fi

  # Démarrage provisoire, sans réseau, pour les réglages et l'import.
  mariadbd --user=mysql --datadir=/var/lib/mysql --skip-networking \
    --socket=/run/mysqld/mysqld.sock >/tmp/mariadb-init.log 2>&1 &
  mysql_pret || { cat /tmp/mariadb-init.log; exit 1; }

  # Base et utilisateur du site (le mot de passe suit le fichier .env).
  # Apostrophes et barres obliques inverses du mot de passe protégées.
  MDP="${DB_PASSWORD//\\/\\\\}"
  MDP="${MDP//\'/\'\'}"
  mariadb --socket=/run/mysqld/mysqld.sock <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${MDP}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${MDP}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${MDP}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${MDP}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

  # Import d'un export (.sql ou .sql.gz) si la base est encore vide.
  TABLES=$(mariadb --socket=/run/mysqld/mysqld.sock -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")
  if [ "$TABLES" = "0" ]; then
    for f in /docker-entrypoint-initdb.d/*.sql /docker-entrypoint-initdb.d/*.sql.gz; do
      [ -f "$f" ] || continue
      journal "import de $(basename "$f") dans la base ${DB_NAME}"
      case "$f" in
        *.gz) gunzip -c "$f" | mariadb --socket=/run/mysqld/mysqld.sock "$DB_NAME" ;;
        *) mariadb --socket=/run/mysqld/mysqld.sock "$DB_NAME" < "$f" ;;
      esac
    done
  fi

  mariadb-admin --socket=/run/mysqld/mysqld.sock shutdown
  wait || true
else
  journal "base de données externe : ${DB_HOST}"
fi

exec "$@"
