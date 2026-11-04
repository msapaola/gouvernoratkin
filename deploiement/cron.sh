#!/bin/bash
#
# Tâches planifiées de Drupal (index de recherche, nettoyage, file d'attente).
#
# À déclarer dans le Manager Infomaniak › Hébergement › Tâches planifiées
# (Cron), toutes les heures, avec la commande :
#   bash <CHEMIN_DU_SITE>/deploiement/cron.sh
#
# Ensuite, dans Drupal : Configuration › Système › Cron, régler « Exécuter
# cron tous les » sur « Jamais » : le cron « automatique » de Drupal se
# déclenche pendant la visite d'un internaute et la ralentit.

set -euo pipefail

SITE="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SITE"

php vendor/bin/drush cron --quiet
