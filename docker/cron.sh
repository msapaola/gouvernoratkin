#!/bin/bash
# Tâches planifiées de Drupal (index de recherche, nettoyage…), lancées à
# intervalle régulier : CRON_INTERVALLE secondes (3600 par défaut).
INTERVALLE="${CRON_INTERVALLE:-3600}"
sleep 120
while true; do
  /usr/local/bin/drush cron --quiet || echo "cron : échec (voir drush watchdog:show)"
  sleep "$INTERVALLE"
done
