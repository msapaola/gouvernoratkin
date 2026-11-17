#!/bin/bash
# « drush » dans le conteneur, toujours sous l'utilisateur du serveur web
# (sinon les fichiers créés appartiendraient à root et Apache ne pourrait plus
# les modifier). Exemple : docker compose exec site drush cr
cd /var/www/html || exit 1
if [ "$(id -u)" = "0" ]; then
  exec runuser -u www-data -- /var/www/html/vendor/bin/drush "$@"
fi
exec /var/www/html/vendor/bin/drush "$@"
