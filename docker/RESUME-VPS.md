# Site du Gouvernorat de Kinshasa — résumé pour le VPS

Tout ce qu'il faut pour installer le site sur le VPS (Nginx Proxy Manager,
réseaux `proxy` et `ia`, projets dans `/opt/stacks/`). Guide détaillé :
`docker/LISEZMOI.md`.

---

## 1. Architecture

| Conteneur | Image | Réseaux | Mémoire | Ports publiés |
|---|---|---|---|---|
| `gouvernorat-web` | construite (`php:8.3.36-apache-bookworm`) : Apache + PHP 8.3 + cron horaire (supervisord) | `proxy` (ext.), `ia` (ext.), `interne` | 3 Go | aucun (Apache sur 80, joint par NPM) |
| `gouvernorat-db` | `mariadb:10.11.19` | `interne` seulement | 1 Go | aucun |

- HTTPS et certificats : **Nginx Proxy Manager**, Proxy Host vers
  `gouvernorat-web:80`.
- Volumes (projet Compose `gouvernorat`) : `gouvernorat_fichiers`
  (téléversements), `gouvernorat_prives` (fichiers privés), `gouvernorat_base`
  (base de données).
- Secrets : uniquement dans `/opt/stacks/gouvernorat/docker/.env` (chmod 600,
  jamais dans Git).
- Courriels : par `mail.kinshasa.cd:465` (compte site@kinshasa.cd).
- **Ne jamais lancer `docker compose down -v`** (supprime base et fichiers).

## 2. À rassembler avant de commencer

| Information | Où la trouver | Variable de `.env` |
|---|---|---|
| Sel de hachage | ancien serveur : `grep hash_salt sites/default/settings.php` | `HASH_SALT` |
| Mot de passe de site@kinshasa.cd | messagerie kinshasa.cd | `SMTP_PASSWORD` |
| Clé de l'assistant | ancien `settings.php` ou console Anthropic | `ANTHROPIC_API_KEY` |
| Mot de passe de la base | `openssl rand -base64 24` | `DB_PASSWORD` |
| Export de la base + archive des fichiers | ancien serveur (étape 4) | — |
| Accès en lecture au dépôt | clé de déploiement GitHub (étape 3) | — |
| Accès DNS de kinshasa.cd et à l'interface de NPM | — | — |

Sorties nécessaires depuis le VPS : `mail.kinshasa.cd:465`,
`api.anthropic.com:443`, `github.com`, et pour construire l'image le registre
Docker, les dépôts Debian et `pecl.php.net`.

## 3. Installation

```bash
# 3.1 Réseaux partagés (une fois pour tout le VPS, s'ils manquent)
docker network create proxy
docker network create ia

# 3.2 Code, avec une clé de déploiement en lecture seule
sudo mkdir -p /opt/stacks/gouvernorat && sudo chown "$USER": /opt/stacks/gouvernorat
ssh-keygen -t ed25519 -f ~/.ssh/gouvernorat_deploy -N ""
cat ~/.ssh/gouvernorat_deploy.pub
#   → GitHub › msapaola/gouvernoratkin › Settings › Deploy keys (sans « write access »)
GIT_SSH_COMMAND='ssh -i ~/.ssh/gouvernorat_deploy' \
  git clone -b main git@github.com:msapaola/gouvernoratkin.git /opt/stacks/gouvernorat
git -C /opt/stacks/gouvernorat config core.sshCommand 'ssh -i ~/.ssh/gouvernorat_deploy'

# 3.3 Réglages
cd /opt/stacks/gouvernorat/docker
cp .env.exemple .env && chmod 600 .env && nano .env
```

Contenu de `.env` pour la phase d'essai :

```
DOMAINES=staging.kinshasa.cd
NOINDEX=1
DB_HOST=db
DB_PORT=3306
DB_NAME=drupal
DB_USER=drupal
DB_PASSWORD=<généré>
DB_PREFIX=cu68_
HASH_SALT=<valeur de l'ancien settings.php>
CRON_INTERVALLE=3600
AFFICHER_ERREURS=0
SMTP_PASSWORD=<mot de passe de site@kinshasa.cd>
ANTHROPIC_API_KEY=<clé de l'assistant>
OLLAMA_BASE_URL=http://ollama:11434
```

## 4. Reprise des données

Sur l'ancien serveur (`public_html`) :

```bash
vendor/bin/drush sql:dump --gzip --result-file=../export-site.sql
tar czf ../fichiers-site.tar.gz -C sites/default/files \
  --exclude=./css --exclude=./js --exclude=./php --exclude=./styles --exclude='./config_*' .
```

Sur le VPS, après `scp` des deux fichiers dans `~` :

```bash
cd /opt/stacks/gouvernorat/docker
mv ~/export-site.sql.gz import/ && chmod 644 import/export-site.sql.gz   # lisible par MariaDB

docker compose up -d --build
docker compose logs -f db      # import, puis « ready for connections »
docker compose logs -f web     # « démarrage d'Apache et du cron »

docker compose cp ~/fichiers-site.tar.gz web:/tmp/
docker compose exec web bash -c 'tar xzf /tmp/fichiers-site.tar.gz -C /var/www/html/sites/default/files && chown -R www-data:www-data /var/www/html/sites/default/files && rm /tmp/fichiers-site.tar.gz'

docker compose exec web drush deploy -y
docker compose exec web drush search:index
docker compose exec web drush config:status     # attendu : aucune différence

rm import/export-site.sql.gz ~/fichiers-site.tar.gz
```

Pendant l'import, `db` reste « starting » et `web` attend (ni drush ni cron).

## 5. Essais : staging.kinshasa.cd

1. DNS : A `staging.kinshasa.cd` → IP du VPS.
2. NPM › Proxy Host : `staging.kinshasa.cd` → `http` · `gouvernorat-web` · `80` ;
   Block Common Exploits, Websockets Support ; SSL : nouveau certificat,
   Force SSL, HTTP/2.
3. Vérifier : accueil, article, recherche, Contact (message reçu à
   contact@gouv.kinshasa.cd), assistant, connexion admin, adresses en
   `https://` sans boucle, vraie IP des visiteurs dans *Rapports › Messages
   récents*, `docker compose ps` : les deux « healthy ».

## 6. Bascule

1. TTL DNS baissé la veille ; puis A `kinshasa.cd` et `www.kinshasa.cd` → IP
   du VPS.
2. NPM : ajouter ces domaines (Proxy Host vers `gouvernorat-web:80`),
   nouveau certificat SSL, Force SSL.
3. `.env` : `DOMAINES=kinshasa.cd,www.kinshasa.cd` (+ `staging.kinshasa.cd`
   si conservé) et `NOINDEX=0` ; puis `docker compose up -d web`.
4. SPF, DKIM, DMARC de kinshasa.cd : autoriser `mail.kinshasa.cd`.
5. Garder l'ancien hébergement quelques jours.

## 7. Au quotidien (dans `/opt/stacks/gouvernorat/docker`)

| Besoin | Commande |
|---|---|
| Mettre à jour le site | `./deployer.sh` (ne reconstruit que `web`, ne touche jamais à `db`) |
| drush | `docker compose exec web drush …` |
| État, journaux | `docker compose ps` · `docker compose logs -f web` · `docker compose logs -f db` |
| Redémarrer | `docker compose restart web` |
| Sauvegarder | `./sauvegarder.sh` (base par `mariadb-dump` + fichiers publics et privés, 14 jours) |
| Restaurer | `./restaurer.sh sauvegardes/base-….sql.gz [sauvegardes/fichiers-….tar.gz]` |
| Sécurité de l'image `web` | `docker compose build --pull web && docker compose up -d web` |

Sauvegarde de nuit (`crontab -e`), à copier ensuite **hors du VPS** :

```
17 2 * * * cd /opt/stacks/gouvernorat/docker && ./sauvegarder.sh >> sauvegardes/journal.txt 2>&1
```

## 8. Dépannage rapide

| Symptôme | Que faire |
|---|---|
| `web` redémarre en boucle | `HASH_SALT` ou `DB_PASSWORD` vide dans `.env` |
| `web` attend « table cu68_users absente » | `docker compose logs db` ; « Permission denied » → `chmod 644 import/*.sql.gz`, puis (1re installation seulement) `docker compose down && docker volume rm gouvernorat_base && docker compose up -d` |
| 502 dans NPM | `docker compose ps` ; NPM bien sur le réseau `proxy` |
| Erreur 400 | ajouter le nom à `DOMAINES`, puis `docker compose up -d web` |
| Adresses en `http://` / boucle | NPM doit joindre `gouvernorat-web:80` par le réseau `proxy` |
| Courriels | port 465 sortant ; `docker compose exec web drush watchdog:show --type=smtp` |
