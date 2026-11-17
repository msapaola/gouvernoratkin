#!/bin/bash
# Mise à jour du site sur le VPS.
#
#   cd /opt/stacks/gouvernorat/docker && ./deployer.sh
#
# 1. récupère le code (branche suivie, en avance rapide seulement) ;
# 2. reconstruit l'image du service « web » et ne relance QUE lui : la base
#    (« db ») n'est ni reconstruite ni recréée, ses données ne bougent pas ;
# 3. affiche les différences de configuration et demande confirmation avant
#    « drush deploy » (mises à jour de la base, import de la configuration,
#    scripts de déploiement, vidage des caches).
set -euo pipefail
cd "$(dirname "$0")"

[ -f .env ] || { echo "Fichier docker/.env absent : copier .env.exemple en .env et le remplir."; exit 1; }

git -C .. pull --ff-only
docker compose build web
docker compose up -d --no-deps web

echo "Attente du démarrage du site…"
pret=0
for _ in $(seq 1 60); do
  if docker compose exec -T web drush status --field=bootstrap 2>/dev/null | grep -q Successful; then
    pret=1
    break
  fi
  sleep 3
done
[ "$pret" = 1 ] || { echo "Le site ne répond pas : voir « docker compose logs web »."; exit 1; }

docker compose exec -T web drush config:status || true
read -r -p "Appliquer les mises à jour et importer la configuration ? (o/N) " reponse
if [ "${reponse,,}" = "o" ]; then
  docker compose exec -T web drush deploy -y
else
  echo "Rien importé. Pour le faire plus tard : docker compose exec web drush deploy -y"
fi
