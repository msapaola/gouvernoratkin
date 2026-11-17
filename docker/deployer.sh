#!/bin/bash
# Mise à jour du site sur le serveur Docker.
#
#   ./deployer.sh            (depuis le dossier docker/)
#
# 1. récupère le code de la branche suivie ;
# 2. reconstruit l'image et relance le conteneur (les données sont dans des
#    volumes : elles ne bougent pas) ;
# 3. affiche les différences de configuration et demande confirmation avant
#    « drush deploy » (mises à jour de la base, import de la configuration,
#    scripts de déploiement, vidage des caches).
set -euo pipefail
cd "$(dirname "$0")"

[ -f .env ] || { echo "Fichier .env absent : copier .env.exemple en .env et le remplir."; exit 1; }

git -C .. pull --ff-only
docker compose build
docker compose up -d

echo "Attente du démarrage du site…"
for _ in $(seq 1 60); do
  if docker compose exec -T site drush status --field=bootstrap 2>/dev/null | grep -q Successful; then
    break
  fi
  sleep 3
done

docker compose exec -T site drush config:status || true
read -r -p "Appliquer les mises à jour et importer la configuration ? (o/N) " reponse
if [ "${reponse,,}" = "o" ]; then
  docker compose exec -T site drush deploy -y
else
  echo "Rien importé. Pour le faire plus tard : docker compose exec site drush deploy -y"
fi
