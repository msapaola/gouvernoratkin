# Site du Gouvernorat de Kinshasa — Docker (VPS avec Nginx Proxy Manager)

Le site tourne dans **deux conteneurs**, derrière Nginx Proxy Manager (NPM),
qui est le seul point d'entrée du VPS (ports 80 et 443, certificats HTTPS) :

```
Internet ──https──▶ NPM ──(réseau « proxy »)──▶ gouvernorat-web :80
                                                   │  Apache + PHP 8.3 + cron
                                                   │──(réseau « ia »)──▶ ollama:11434
                                                   └──(réseau privé « interne »)──▶ gouvernorat-db :3306
                                                                                     MariaDB 10.11
```

| Conteneur | Image | Réseaux | Mémoire | Rôle |
|---|---|---|---|---|
| `gouvernorat-web` | construite depuis `docker/Dockerfile` (`php:8.3.36-apache-bookworm`) | `proxy`, `ia`, `interne` | 3 Go | Drupal (Apache lit les `.htaccess` du projet) et `drush cron` toutes les heures, pilotés par supervisord |
| `gouvernorat-db` | `mariadb:10.11.19` | `interne` seulement | 1 Go | base de données |

Aucun port n'est publié. Les **données** sont dans trois volumes, qui
survivent aux mises à jour :

| Volume | Contenu |
|---|---|
| `gouvernorat_fichiers` | images et documents téléversés (`sites/default/files`) |
| `gouvernorat_prives` | fichiers privés |
| `gouvernorat_base` | base de données (`/var/lib/mysql` de `db`) |

Le nom de projet est fixé (`name: gouvernorat` dans `compose.yaml`) : ces noms
ne dépendent pas du dossier d'où la commande est lancée.

**Ne jamais lancer `docker compose down -v`** : `-v` supprime les volumes,
donc la base et les fichiers.

Fichiers de ce dossier :

| Fichier | Rôle |
|---|---|
| `Dockerfile` | image du service `web` |
| `compose.yaml` | services `web` et `db`, réseaux, volumes, limites mémoire |
| `.env.exemple` | réglages à copier en `.env` (secrets : jamais dans Git) |
| `settings.php` | réglages Drupal du conteneur, lus dans `.env` (proxy, domaines, secrets) |
| `entrypoint.sh` | démarrage de `web` : vérifications, attente de la base et du site |
| `supervisord.conf` | Apache et cron dans `web` |
| `apache-site.conf`, `php.ini` | réglages d'Apache et de PHP (512 Mo, envois 64 Mo, 90 s) |
| `cron.sh`, `drush.sh` | cron horaire ; `drush` toujours exécuté par l'utilisateur du serveur web |
| `deployer.sh` | mise à jour du site (ne touche jamais à la base) |
| `sauvegarder.sh`, `restaurer.sh` | sauvegarde et restauration de la base et des fichiers |
| `import/` | export de base à importer au premier démarrage |
| `RESUME-VPS.md` | résumé d'une page pour préparer le serveur |
| `.htaccess` | interdit l'accès web à ce dossier sur l'hébergement actuel |

---

## 1. Prérequis sur le VPS

Déjà en place sur le VPS (rien à installer ici) : Docker, le pare-feu,
Nginx Proxy Manager et Ollama.

Les réseaux Docker partagés doivent exister (une fois pour tout le VPS) :

```bash
docker network ls | grep -E ' (proxy|ia) '
# s'ils manquent :
docker network create proxy
docker network create ia
```

NPM doit être branché sur `proxy`, Ollama sur `ia`.

Sorties nécessaires depuis le VPS : `mail.kinshasa.cd` port **465**
(courriels du site, via site@kinshasa.cd), `api.anthropic.com` port 443
(assistant), `github.com` (code), et pour construire l'image : registre
Docker, dépôts Debian, `pecl.php.net`.

## 2. Récupérer le projet

Le dépôt est privé : accès en lecture par une **clé de déploiement**.

```bash
sudo mkdir -p /opt/stacks/gouvernorat
sudo chown "$USER": /opt/stacks/gouvernorat

ssh-keygen -t ed25519 -f ~/.ssh/gouvernorat_deploy -N ""
cat ~/.ssh/gouvernorat_deploy.pub
# → GitHub › msapaola/gouvernoratkin › Settings › Deploy keys › Add deploy key
#   (lecture seule : NE PAS cocher « Allow write access »).

GIT_SSH_COMMAND='ssh -i ~/.ssh/gouvernorat_deploy' \
  git clone -b main git@github.com:msapaola/gouvernoratkin.git /opt/stacks/gouvernorat
git -C /opt/stacks/gouvernorat config core.sshCommand 'ssh -i ~/.ssh/gouvernorat_deploy'
```

## 3. Régler le fichier .env

```bash
cd /opt/stacks/gouvernorat/docker
cp .env.exemple .env
chmod 600 .env
nano .env
```

| Variable | Valeur | Remarque |
|---|---|---|
| `DOMAINES` | `staging.kinshasa.cd` pendant les essais, puis `kinshasa.cd,www.kinshasa.cd` | tout autre nom reçoit une erreur 400 |
| `NOINDEX` | `1` pendant les essais, `0` pour le site public | |
| `DB_HOST` | `db` | ne pas changer |
| `DB_PORT`, `DB_NAME`, `DB_USER` | `3306`, `drupal`, `drupal` | |
| `DB_PASSWORD` | `openssl rand -base64 24` | **obligatoire** ; fixé à la création de la base |
| `DB_PREFIX` | `cu68_` | préfixe des tables de la base reprise |
| `HASH_SALT` | valeur du `settings.php` actuel (`grep hash_salt sites/default/settings.php` sur l'ancien serveur) | **obligatoire** ; garde sessions et liens déjà envoyés |
| `CRON_INTERVALLE` | `3600` | secondes |
| `AFFICHER_ERREURS` | `0` | `1` seulement pour un dépannage |
| `SMTP_PASSWORD` | mot de passe de site@kinshasa.cd | envoi par mail.kinshasa.cd:465 |
| `ANTHROPIC_API_KEY` | clé de l'assistant | vide = assistant masqué |
| `OLLAMA_BASE_URL` | `http://ollama:11434` | réseau `ia` ; pas utilisé par l'assistant actuel |

Sans `HASH_SALT` ou `DB_PASSWORD`, le conteneur `web` refuse de démarrer
(et `docker compose` refuse de lancer `db` sans `DB_PASSWORD`).

Le mot de passe root de MariaDB est généré au hasard à la création de la base
et n'est jamais utilisé (`MARIADB_RANDOM_ROOT_PASSWORD`).

## 4. Reprendre la base et les fichiers du site actuel

Sur l'hébergement actuel (dans `public_html`) :

```bash
vendor/bin/drush sql:dump --gzip --result-file=../export-site.sql
tar czf ../fichiers-site.tar.gz -C sites/default/files \
  --exclude=./css --exclude=./js --exclude=./php --exclude=./styles \
  --exclude='./config_*' .
```

Copier `export-site.sql.gz` et `fichiers-site.tar.gz` sur le VPS (`scp`), puis :

```bash
cd /opt/stacks/gouvernorat/docker

# La base : importée automatiquement par « db » au premier démarrage, si la
# base est vide. Le fichier doit être lisible par MariaDB.
mv ~/export-site.sql.gz import/
chmod 644 import/export-site.sql.gz

docker compose up -d --build
docker compose logs -f db       # attendre « ready for connections » après
                                # « running /docker-entrypoint-initdb.d/export-site.sql.gz »
docker compose logs -f web      # attendre « démarrage d'Apache et du cron »

# Les fichiers : copiés dans le volume.
docker compose cp ~/fichiers-site.tar.gz web:/tmp/
docker compose exec web bash -c \
  'tar xzf /tmp/fichiers-site.tar.gz -C /var/www/html/sites/default/files \
   && chown -R www-data:www-data /var/www/html/sites/default/files \
   && rm /tmp/fichiers-site.tar.gz'

# Mises à jour de la base, configuration, caches, index de recherche.
docker compose exec web drush deploy -y
docker compose exec web drush search:index
docker compose exec web drush config:status    # attendu : aucune différence

# L'export contient toute la base : le supprimer.
rm import/export-site.sql.gz ~/fichiers-site.tar.gz
```

Pendant l'import, `db` reste « starting » et `web` attend : ni drush ni le
cron ne touchent à la base tant que l'import n'est pas fini. `web` attend en
plus que la table `cu68_users` existe.

**Site neuf, sans reprise** (pas d'export dans `import/`) : `web` démarre puis
attend l'installation (message « table cu68_users absente ») ; les commandes
`exec` restent possibles :

```bash
docker compose exec web drush site:install --existing-config -y
docker compose exec web drush cim -y
docker compose exec web drush deploy:hook -y   # textes, pages, fiches de départ
```

Une quinzaine de différences mineures restent ensuite dans `config:status`
(réglages ajoutés par Drupal à une installation neuve) : sans effet.

## 5. Essais sur staging.kinshasa.cd

1. **DNS** : enregistrement A `staging.kinshasa.cd` → adresse IP du VPS.
2. **NPM** › Hosts › Proxy Hosts › Add Proxy Host :
   - Domain Names : `staging.kinshasa.cd`
   - Scheme : `http` — Forward Hostname : `gouvernorat-web` — Forward Port : `80`
   - Cocher **Block Common Exploits** et **Websockets Support**
   - Onglet SSL : *Request a new SSL Certificate*, cocher **Force SSL** et
     **HTTP/2 Support**
3. Dans `.env` : `DOMAINES=staging.kinshasa.cd` et `NOINDEX=1`, puis
   `docker compose up -d web`.
4. Vérifier :
   - [ ] l'accueil, un article, la recherche, la page Contact ;
   - [ ] les adresses du site sont en `https://` (pas d'avertissement de contenu mixte, pas de boucle de redirection) ;
   - [ ] l'envoi d'un message depuis `/contact` (il doit arriver à contact@gouv.kinshasa.cd) ;
   - [ ] l'assistant virtuel ;
   - [ ] la connexion à l'administration ;
   - [ ] *Rapports › Messages récents* : l'adresse IP des visiteurs est la vraie (pas celle de NPM) ;
   - [ ] `docker compose ps` : `db` « healthy », `web` « healthy ».

## 6. Bascule finale

1. La veille : baisser la durée de cache (TTL) des enregistrements DNS.
2. DNS : `kinshasa.cd` et `www.kinshasa.cd` → adresse IP du VPS.
3. NPM : ajouter `kinshasa.cd` et `www.kinshasa.cd` au Proxy Host (ou en
   créer un dédié vers `gouvernorat-web:80`), demander un **nouveau
   certificat SSL** couvrant ces noms, **Force SSL**.
4. Dans `.env` : `DOMAINES=kinshasa.cd,www.kinshasa.cd` (garder
   `staging.kinshasa.cd` si le Proxy Host de test reste) et **`NOINDEX=0`**,
   puis `docker compose up -d web`.
5. Vérifier que les enregistrements SPF, DKIM et DMARC de kinshasa.cd
   autorisent `mail.kinshasa.cd`.
6. Garder l'ancien hébergement quelques jours en secours.

## 7. Au quotidien

Toutes les commandes se lancent dans `/opt/stacks/gouvernorat/docker`.

| Besoin | Commande |
|---|---|
| Mettre à jour le site | `./deployer.sh` (pull, reconstruction de `web` seul, affiche `config:status`, demande avant `drush deploy`) |
| Commande drush | `docker compose exec web drush cr` (ou `cst`, `cim`, `uli`, `watchdog:show`…) |
| État | `docker compose ps` |
| Journaux | `docker compose logs -f web` (Apache, PHP, cron) — `docker compose logs -f db` |
| Redémarrer le site | `docker compose restart web` |
| Console MariaDB | `docker compose exec web drush sql:cli` |
| Sauvegarder | `./sauvegarder.sh` |
| Restaurer | `./restaurer.sh sauvegardes/base-….sql.gz [sauvegardes/fichiers-….tar.gz]` |
| Mises à jour de sécurité de l'image `web` | `docker compose build --pull web && docker compose up -d web` |
| Nouvelle version de MariaDB | changer le numéro (10.11.x) dans `compose.yaml`, puis `docker compose up -d db` |

**Ne jamais lancer `docker compose down -v`.** `docker compose down` (sans
`-v`) arrête les conteneurs et garde les données.

### Sauvegardes

Chaque nuit (`crontab -e`) :

```
17 2 * * * cd /opt/stacks/gouvernorat/docker && ./sauvegarder.sh >> sauvegardes/journal.txt 2>&1
```

- `sauvegardes/base-DATE.sql.gz` : `mariadb-dump` de la base, depuis `db` ;
- `sauvegardes/fichiers-DATE.tar.gz` : fichiers publics (`files/`) et privés
  (`private/`), sans les caches régénérables ;
- conservés 14 jours (`GARDER_JOURS=30 ./sauvegarder.sh` pour changer) ;
- **à copier hors du VPS** : une sauvegarde restée sur le serveur disparaît
  avec lui.

### Restaurer

```bash
./restaurer.sh sauvegardes/base-AAAA-MM-JJ_HHMM.sql.gz sauvegardes/fichiers-AAAA-MM-JJ_HHMM.tar.gz
```

La base est remplacée (après confirmation) ; les fichiers de l'archive sont
remis en place (les fichiers ajoutés depuis la sauvegarde ne sont pas
supprimés).

## 8. En cas de problème

| Symptôme | Cause probable | Que faire |
|---|---|---|
| `web` redémarre en boucle | `HASH_SALT` ou `DB_PASSWORD` vide | remplir `.env`, puis `docker compose up -d` |
| `web` reste sur « table cu68_users absente » | import pas fini, import échoué, ou site neuf pas installé | `docker compose logs db` ; « Permission denied » : `chmod 644 import/*.sql.gz` puis, **à la toute première installation seulement**, `docker compose down`, `docker volume rm gouvernorat_base`, `docker compose up -d` |
| 502 Bad Gateway (NPM) | `web` arrêté, ou NPM pas sur le réseau `proxy` | `docker compose ps` ; `docker network inspect proxy` |
| « Bad Request » (400) | nom de domaine absent de `DOMAINES` | l'ajouter, puis `docker compose up -d web` |
| Adresses en `http://`, boucle de redirection | NPM n'envoie pas `X-Forwarded-Proto`, ou ne joint pas `web` par le réseau Docker | joindre `gouvernorat-web:80` par le réseau `proxy` (adresse privée Docker) |
| Les courriels ne partent pas | port 465 sortant bloqué, ou `SMTP_PASSWORD` | `docker compose exec web drush watchdog:show --type=smtp` |
| Erreur générique | voir le détail | `docker compose logs web` et `docker compose exec web drush watchdog:show --count=20` |

## Bon à savoir

- **Derrière le proxy** : Drupal ne croit les en-têtes `X-Forwarded-*` que
  si la connexion vient d'une adresse privée Docker (172.16.0.0/12,
  10.0.0.0/8). Apache ne réécrit pas l'adresse de la connexion (sinon Drupal
  ne reconnaîtrait plus le HTTPS) ; son journal d'accès affiche l'adresse
  annoncée du visiteur suivie de celle du proxy.
- **Configuration exportée** : rangée dans l'image hors du site
  (`/var/www/config/sync`), depuis `sites/default/files/config_…/sync` du
  dépôt ; mise à jour à chaque reconstruction de `web`.
- **Changer `DB_PASSWORD` après coup** : MariaDB garde l'ancien. Le changer
  d'abord dans la base (`docker compose exec web drush sql:query "SET
  PASSWORD = PASSWORD('nouveau')"`), puis dans `.env`, puis
  `docker compose up -d web`.
- **Mot de passe root de MariaDB** : généré au hasard et affiché une seule
  fois dans `docker compose logs db` (« GENERATED ROOT PASSWORD »). Il n'est
  pas utilisé ; les journaux de Docker ne doivent être lisibles que par les
  administrateurs du VPS.
- **Ollama** : `OLLAMA_BASE_URL` est transmis à Drupal
  (`$settings['gouvernorat_assistant_ollama_url']`), prêt pour un fournisseur
  Ollama ; l'assistant actuel utilise uniquement l'API d'Anthropic.
