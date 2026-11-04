#!/bin/bash
#
# Sauvegarde quotidienne du site : base de données + fichiers téléversés.
#
# À déclarer dans le Manager Infomaniak › Hébergement › Tâches planifiées
# (Cron), une fois par jour la nuit, avec la commande :
#   bash <CHEMIN_DU_SITE>/deploiement/sauvegarde.sh
#
# Les archives sont rangées HORS du dossier web (une sauvegarde contient des
# données personnelles et les mots de passe chiffrés des comptes) et
# conservées 14 jours. Le code n'est pas sauvegardé ici : il est dans Git.
#
# Infomaniak garde aussi ses propres sauvegardes de l'hébergement ; celles-ci
# en sont une copie indépendante, à récupérer de temps en temps hors du
# serveur (kDrive, disque de la DSI…).

set -euo pipefail

SITE="$(cd "$(dirname "$0")/.." && pwd)"
DESTINATION="${SAUVEGARDE_DESTINATION:-$HOME/sauvegardes}"
CONSERVATION_JOURS="${SAUVEGARDE_JOURS:-14}"
HORODATAGE="$(date +%Y-%m-%d_%H%M)"

case "$DESTINATION" in
  "$SITE"*) echo "Refus : la destination est dans le dossier du site, donc publique." >&2; exit 1 ;;
esac

mkdir -p "$DESTINATION"
chmod 700 "$DESTINATION"
cd "$SITE"

# Base de données. Les tables de cache, de journal et de sessions ne sont
# sauvegardées que vides (structure seule) ; les « * » couvrent le préfixe
# des tables (cu68_ sur Hostinger).
php vendor/bin/drush sql:dump --gzip --structure-tables-list='*cache*,*watchdog,*sessions' \
  --result-file="$DESTINATION/base_$HORODATAGE.sql" --quiet

# Fichiers téléversés (images, documents), sans les caches régénérables.
tar -czf "$DESTINATION/fichiers_$HORODATAGE.tar.gz" \
  --exclude='sites/default/files/css' \
  --exclude='sites/default/files/js' \
  --exclude='sites/default/files/php' \
  --exclude='sites/default/files/styles' \
  sites/default/files

# Réglages du serveur (mots de passe compris) : c'est la seule copie hors Git.
tar -czf "$DESTINATION/reglages_$HORODATAGE.tar.gz" sites/default/settings*.php
chmod 600 "$DESTINATION"/*_"$HORODATAGE"*

# Rotation.
find "$DESTINATION" -maxdepth 1 -type f \( -name 'base_*' -o -name 'fichiers_*' -o -name 'reglages_*' \) \
  -mtime +"$CONSERVATION_JOURS" -delete

echo "Sauvegarde terminée : $DESTINATION (*_$HORODATAGE*)"
