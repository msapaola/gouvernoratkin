# Site du Gouvernorat de Kinshasa — préparer le VPS (Docker)

Résumé complet pour installer le site sur un serveur privé virtuel (VPS).
Le guide détaillé est dans `docker/LISEZMOI.md` (même dossier).

---

## 1. En bref

- Le site (Drupal 11, PHP 8.3) tourne dans **un seul conteneur Docker** qui
  contient tout :

  | Service | Rôle |
  |---|---|
  | Caddy | ports 80 et 443, HTTPS automatique (Let's Encrypt) |
  | Apache + PHP 8.3 | fait tourner Drupal (les `.htaccess` du projet s'appliquent) |
  | MariaDB 10.11 | base de données (désactivée si la base est externe) |
  | cron | `drush cron` toutes les heures |

- **Le code est dans l'image** (reconstruite à chaque mise à jour).
  **Les données sont dans 4 volumes** qui survivent aux mises à jour :

  | Volume Docker | Contenu |
  |---|---|
  | `gouvernorat_fichiers` | images et documents téléversés |
  | `gouvernorat_prives` | fichiers privés |
  | `gouvernorat_base` | base de données |
  | `gouvernorat_certificats` | certificats HTTPS |

- **Aucun secret dans le dépôt** : tout est dans le fichier `docker/.env` du
  serveur (jamais versionné, jamais envoyé par message).
- Les courriels partent toujours par `mail.kinshasa.cd` (compte
  site@kinshasa.cd), pas depuis le VPS.

---

## 2. Ce qu'il faut avant de commencer

### Le serveur

| Élément | Valeur conseillée |
|---|---|
| Système | Ubuntu 24.04 LTS |
| Processeur / mémoire | 2 vCPU, 4 Go de RAM (minimum 2 Go) |
| Disque | 40 Go |
| Ports entrants ouverts | 22 (SSH), 80 (HTTP), 443 (HTTPS, TCP et UDP) |
| Connexions sortantes nécessaires | `mail.kinshasa.cd` port **465** (courriels) ; `api.anthropic.com` port 443 (assistant) ; `github.com` (code) ; registre Docker et dépôts Debian (construction de l'image) |

Certains hébergeurs bloquent l'envoi de courriels par défaut : demander
l'ouverture du port 465 sortant si besoin.

### Les informations à rassembler

| Information | Où la trouver | Va dans |
|---|---|---|
| **Sel de hachage** (`hash_salt`) | `settings.php` du site actuel : `grep hash_salt sites/default/settings.php` | `HASH_SALT` |
| **Mot de passe de site@kinshasa.cd** | gestionnaire de la messagerie kinshasa.cd | `SMTP_PASSWORD` |
| **Clé de l'API de l'assistant** | `settings.php` actuel, ou console Anthropic | `ANTHROPIC_API_KEY` |
| **Mot de passe de la base** | à créer : `openssl rand -base64 24` | `DB_PASSWORD` |
| **Préfixe des tables** | `cu68_` (base actuelle) | `DB_PREFIX` |
| **Noms de domaine** | `kinshasa.cd`, `www.kinshasa.cd` | `DOMAINES`, `SITE_ADDRESS` |
| **Accès au dépôt GitHub** (privé) | clé de déploiement, voir 3.2 | — |
| **Accès au gestionnaire DNS** de kinshasa.cd | registraire du domaine | bascule (étape 3.7) |

Reprendre le **même** `hash_salt` : les liens de réinitialisation de mot de
passe déjà envoyés restent valables.

---

## 3. Installation pas à pas

### 3.1 Préparer le serveur

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git ufw fail2ban unattended-upgrades

# Pare-feu : SSH, HTTP, HTTPS seulement.
sudo ufw allow OpenSSH
sudo ufw allow 80
sudo ufw allow 443
sudo ufw enable

# Docker (dépôt officiel), puis se déconnecter et se reconnecter.
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
```

Se connecter au serveur **par clé SSH uniquement** (mot de passe et
connexion root désactivés).

### 3.2 Récupérer le projet (dépôt privé)

```bash
# Clé de déploiement, en lecture seule.
ssh-keygen -t ed25519 -f ~/.ssh/gouvernoratkin -N ""
cat ~/.ssh/gouvernoratkin.pub
# → la coller dans GitHub › msapaola/gouvernoratkin › Settings › Deploy keys
#   (sans cocher « Allow write access »).

GIT_SSH_COMMAND='ssh -i ~/.ssh/gouvernoratkin' \
  git clone -b claude/jolly-bohr-l0c6sp git@github.com:msapaola/gouvernoratkin.git
git -C gouvernoratkin config core.sshCommand 'ssh -i ~/.ssh/gouvernoratkin'
```

### 3.3 Remplir les réglages

```bash
cd gouvernoratkin/docker
cp .env.exemple .env
chmod 600 .env
nano .env
```

Contenu de `docker/.env` :

| Variable | Valeur pour la mise en service | Rôle |
|---|---|---|
| `SITE_ADDRESS` | `:80` pendant les essais, puis `kinshasa.cd, www.kinshasa.cd` | adresse servie par Caddy ; un nom de domaine déclenche le HTTPS automatique |
| `DOMAINES` | `kinshasa.cd,www.kinshasa.cd` (+ l'adresse IP du VPS pendant les essais) | noms acceptés par Drupal ; un autre nom reçoit une erreur 400 |
| `NOINDEX` | `0` (site public) ; `1` pour un serveur de test | interdit l'indexation par les moteurs de recherche |
| `DB_HOST` | `127.0.0.1` | base dans le conteneur ; autre valeur = base externe |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `drupal` | |
| `DB_USER` | `drupal` | |
| `DB_PASSWORD` | mot de passe généré | obligatoire |
| `DB_PREFIX` | `cu68_` | préfixe des tables de la base actuelle |
| `HASH_SALT` | valeur du site actuel | obligatoire |
| `CRON_INTERVALLE` | `3600` | secondes entre deux passages du cron |
| `AFFICHER_ERREURS` | `0` | `1` seulement pour un dépannage |
| `SMTP_PASSWORD` | mot de passe de site@kinshasa.cd | envoi des courriels |
| `ANTHROPIC_API_KEY` | clé de l'assistant | vide = assistant masqué |

Sans `HASH_SALT` ou `DB_PASSWORD`, le conteneur refuse de démarrer.

### 3.4 Exporter la base et les fichiers du site actuel

Sur l'hébergement actuel (dans `public_html`) :

```bash
vendor/bin/drush sql:dump --gzip --result-file=../export-site.sql
tar czf ../fichiers-site.tar.gz -C sites/default/files \
  --exclude=./css --exclude=./js --exclude=./php --exclude=./styles \
  --exclude='./config_*' .
```

Copier `export-site.sql.gz` et `fichiers-site.tar.gz` sur le VPS (`scp`),
dans le dossier qui contient `gouvernoratkin/`.

### 3.5 Démarrer et importer

```bash
# La base : importée automatiquement au premier démarrage (base vide).
mv ~/export-site.sql.gz ~/gouvernoratkin/docker/import/

cd ~/gouvernoratkin/docker
docker compose up -d --build            # 5 à 10 minutes la première fois
docker compose logs -f site             # attendre « import de export-site.sql.gz »
                                        # puis Ctrl+C

# Les fichiers : copiés dans le volume.
docker compose cp ~/fichiers-site.tar.gz site:/tmp/
docker compose exec site bash -c \
  'tar xzf /tmp/fichiers-site.tar.gz -C /var/www/html/sites/default/files \
   && chown -R www-data:www-data /var/www/html/sites/default/files \
   && rm /tmp/fichiers-site.tar.gz'

# Mises à jour de la base, configuration, caches, index de recherche.
docker compose exec site drush deploy -y
docker compose exec site drush search:index

# Les exports contiennent toute la base : les supprimer.
rm ~/gouvernoratkin/docker/import/export-site.sql.gz ~/fichiers-site.tar.gz
```

### 3.6 Essayer avant de basculer

Ouvrir `http://ADRESSE_IP_DU_VPS` (adresse IP ajoutée à `DOMAINES`, puis
`docker compose up -d`). Vérifier :

- [ ] l'accueil, un article, la recherche, la page Contact ;
- [ ] l'envoi d'un message depuis `/contact` (il doit arriver à contact@gouv.kinshasa.cd) ;
- [ ] l'assistant virtuel ;
- [ ] la connexion à l'administration (`/user/login`) ;
- [ ] `docker compose exec site drush config:status` → aucune différence ;
- [ ] `docker compose exec site supervisorctl status` → 4 services `RUNNING`.

### 3.7 Basculer le domaine (HTTPS)

1. La veille : baisser la durée de cache (TTL) des enregistrements DNS
   (par exemple 300 secondes).
2. Faire pointer `kinshasa.cd` et `www.kinshasa.cd` (enregistrements A, et
   AAAA si IPv6) vers l'adresse IP du VPS.
3. Dans `.env` : `SITE_ADDRESS=kinshasa.cd, www.kinshasa.cd` et retirer
   l'adresse IP de `DOMAINES`, puis `docker compose up -d`.
4. Caddy obtient les certificats en quelques secondes ; `http://` redirige
   vers `https://`.
5. Garder l'ancien hébergement quelques jours en secours.

Vérifier aussi que les enregistrements **SPF, DKIM et DMARC** de kinshasa.cd
autorisent `mail.kinshasa.cd`, sinon les courriels du site risquent d'arriver
en indésirables.

---

## 4. Au quotidien

Toutes les commandes se lancent dans `~/gouvernoratkin/docker`.

| Besoin | Commande |
|---|---|
| Mettre à jour le site (nouveau code) | `./deployer.sh` (pull, reconstruction, affiche `config:status`, demande confirmation avant `drush deploy`) |
| Commande drush | `docker compose exec site drush cr` (ou `cst`, `cim`, `uli`, `watchdog:show`…) |
| Journaux en direct | `docker compose logs -f site` |
| État des services | `docker compose exec site supervisorctl status` |
| Redémarrer | `docker compose restart site` |
| Arrêter | `docker compose down` (les données restent) |
| Sauvegarder | `./sauvegarder.sh` |
| Mises à jour de sécurité (PHP, Apache, MariaDB, Caddy) | une fois par mois : `docker compose build --pull && docker compose up -d` |

**Ne jamais lancer `docker compose down -v`** : `-v` supprime les volumes,
donc la base et les fichiers.

### Sauvegardes

Automatiques chaque nuit (`crontab -e`) :

```
17 2 * * * cd /home/UTILISATEUR/gouvernoratkin/docker && ./sauvegarder.sh >> sauvegardes/journal.txt 2>&1
```

- Résultat : `docker/sauvegardes/base-DATE.sql.gz` et
  `fichiers-DATE.tar.gz`, gardés 14 jours.
- **Copier ce dossier hors du serveur** (autre serveur, stockage objet) :
  une sauvegarde restée sur le VPS disparaît avec lui.

Restaurer :

```bash
docker compose cp sauvegardes/base-DATE.sql.gz site:/tmp/
docker compose exec site bash -c 'drush sql:drop -y && gunzip -c /tmp/base-DATE.sql.gz | drush sql:cli && rm /tmp/base-DATE.sql.gz'
docker compose exec -T site tar xzf - -C /var/www/html/sites/default < sauvegardes/fichiers-DATE.tar.gz
docker compose exec site drush cr
```

---

## 5. En cas de problème

| Symptôme | Cause probable | Que faire |
|---|---|---|
| Le conteneur s'arrête aussitôt | `HASH_SALT` ou `DB_PASSWORD` vide | remplir `.env`, puis `docker compose up -d` |
| « Bad Request » (erreur 400) | le nom ou l'adresse utilisés ne sont pas dans `DOMAINES` | les ajouter, puis `docker compose up -d` |
| Pas de certificat HTTPS | DNS pas encore propagé, ou ports 80/443 fermés | `dig kinshasa.cd`, vérifier le pare-feu, puis `docker compose logs site` |
| La base n'a pas été importée | le volume `gouvernorat_base` contenait déjà une base | l'import n'a lieu que sur une base vide ; à la toute première installation seulement, recommencer avec `docker compose down -v` (efface tout) |
| Les courriels ne partent pas | port 465 sortant bloqué, ou mauvais `SMTP_PASSWORD` | `docker compose exec site drush watchdog:show --type=smtp` |
| L'assistant n'apparaît pas | `ANTHROPIC_API_KEY` vide ou sortie vers api.anthropic.com bloquée | vérifier `.env` et le pare-feu de l'hébergeur |
| Page d'erreur générique | voir le détail | `docker compose exec site drush watchdog:show --count=20` |

---

## 6. Fichiers du dossier `docker/`

| Fichier | Rôle |
|---|---|
| `Dockerfile` | recette de l'image (PHP 8.3 + Apache, extensions, MariaDB, Caddy, supervisord) |
| `compose.yaml` | lancement : service `site`, ports 80/443, 4 volumes, `.env` |
| `.env.exemple` | modèle des réglages à copier en `.env` |
| `settings.php` | réglages Drupal du conteneur, lus dans `.env` (proxy, noms de domaine, secrets, cron automatique coupé) |
| `entrypoint.sh` | démarrage : vérifications, création de la base, import au premier démarrage |
| `supervisord.conf` | les 4 services du conteneur |
| `Caddyfile`, `apache-site.conf`, `php.ini` | réglages de Caddy, d'Apache et de PHP (512 Mo, envois 64 Mo, 90 s par requête) |
| `cron.sh`, `drush.sh` | cron horaire ; `drush` toujours exécuté par l'utilisateur du serveur web |
| `deployer.sh` | mise à jour du site |
| `sauvegarder.sh` | sauvegarde de la base et des fichiers |
| `import/` | dépôt de l'export de base pour le premier démarrage |
| `LISEZMOI.md` | guide détaillé |
| `.htaccess` | interdit l'accès web à ce dossier sur l'hébergement actuel |
