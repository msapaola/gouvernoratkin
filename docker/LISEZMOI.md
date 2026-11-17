# Site du Gouvernorat de Kinshasa — Docker

Le site tourne dans **un seul conteneur**, qui contient :

| Service | Rôle |
|---|---|
| **Caddy** | Reçoit le trafic (ports 80 et 443) et gère le HTTPS : certificats Let's Encrypt obtenus et renouvelés automatiquement |
| **Apache + PHP 8.3** | Fait tourner Drupal. Apache lit les `.htaccess` du projet (redirections, dossiers interdits, protection des fichiers) |
| **MariaDB** | La base de données. Il ne démarre pas si `DB_HOST` désigne un serveur externe |
| **cron** | Lance `drush cron` toutes les heures (recherche, nettoyage). Le cron « automatique » de Drupal est coupé dans le conteneur |

Le code est **dans l'image** : chaque mise à jour reconstruit l'image. Les
**données** sont dans des **volumes**, qui survivent aux mises à jour :

| Volume | Contenu |
|---|---|
| `fichiers` | images et documents téléversés (`sites/default/files`) |
| `prives` | fichiers privés |
| `base` | base de données |
| `certificats` | certificats HTTPS |

Fichiers de ce dossier :

| Fichier | Rôle |
|---|---|
| `Dockerfile` | recette de l'image |
| `compose.yaml` | lancement du conteneur, ports, volumes |
| `.env.exemple` | réglages à copier en `.env` (secrets : jamais dans Git) |
| `settings.php` | réglages Drupal du conteneur, lus dans `.env` |
| `deployer.sh` | mise à jour du site |
| `sauvegarder.sh` | sauvegarde de la base et des fichiers |
| `import/` | export de base à importer au premier démarrage |

---

## 1. Préparer le serveur (VPS)

Conseillé : Ubuntu 24.04 LTS, 2 vCPU, 4 Go de RAM, 40 Go de disque.

```bash
# Connexion par clé SSH uniquement, puis :
sudo apt update && sudo apt upgrade -y
sudo apt install -y git ufw fail2ban unattended-upgrades

# Pare-feu : SSH, HTTP, HTTPS seulement.
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443
sudo ufw enable

# Docker (dépôt officiel).
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"   # puis se reconnecter
```

Vérifier que le serveur peut joindre `mail.kinshasa.cd` sur le port 465
(courriels du site) et `api.anthropic.com` (assistant). Certains hébergeurs
bloquent l'envoi de courriels par défaut.

## 2. Récupérer le projet et le régler

Le dépôt est privé : le serveur a besoin d'un accès en lecture. Le plus
simple est une **clé de déploiement** : sur le serveur,
`ssh-keygen -t ed25519 -f ~/.ssh/gouvernoratkin`, puis coller la clé publique
(`~/.ssh/gouvernoratkin.pub`) dans GitHub › dépôt › Settings › Deploy keys,
en lecture seule.

```bash
GIT_SSH_COMMAND='ssh -i ~/.ssh/gouvernoratkin' \
  git clone -b claude/jolly-bohr-l0c6sp git@github.com:msapaola/gouvernoratkin.git
git -C gouvernoratkin config core.sshCommand 'ssh -i ~/.ssh/gouvernoratkin'
cd gouvernoratkin/docker
cp .env.exemple .env
chmod 600 .env
nano .env
```

Dans `.env` :

- **`DB_PASSWORD`** : un mot de passe long (`openssl rand -base64 24`).
- **`HASH_SALT`** : reprendre la valeur actuelle du site, dans le
  `settings.php` de l'hébergement actuel. Ainsi les liens de réinitialisation
  de mot de passe déjà envoyés restent valables.
- **`DB_PREFIX=cu68_`** : garder ce préfixe si la base est importée telle quelle.
- **`SMTP_PASSWORD`** et **`ANTHROPIC_API_KEY`** : les mêmes qu'aujourd'hui.
- **`NOINDEX=1`** pour un site de test (les moteurs de recherche ne
  l'indexent pas), **`NOINDEX=0`** pour le site public.
- **`SITE_ADDRESS`** : laisser `:80` pour les essais. Mettre
  `kinshasa.cd, www.kinshasa.cd` le jour de la bascule (voir l'étape 5).

## 3. Reprendre la base et les fichiers du site actuel

Sur l'hébergement actuel :

```bash
vendor/bin/drush sql:dump --gzip --result-file=../export-site.sql
tar czf ../fichiers-site.tar.gz -C sites/default/files \
  --exclude=./css --exclude=./js --exclude=./php --exclude=./styles --exclude='./config_*' .
```

Copier ces deux fichiers sur le VPS (`scp`), puis :

```bash
# La base : importée automatiquement au premier démarrage.
mv export-site.sql.gz gouvernoratkin/docker/import/

cd gouvernoratkin/docker
docker compose up -d --build
docker compose logs -f site        # attendre « import de export-site.sql.gz »

# Les fichiers : copiés dans le volume.
docker compose cp ../../fichiers-site.tar.gz site:/tmp/
docker compose exec site bash -c \
  'tar xzf /tmp/fichiers-site.tar.gz -C /var/www/html/sites/default/files \
   && chown -R www-data:www-data /var/www/html/sites/default/files \
   && rm /tmp/fichiers-site.tar.gz'

# Mise à niveau, configuration, caches.
docker compose exec site drush deploy -y
```

Ensuite, supprimer l'export (`rm import/export-site.sql.gz`). Il contient
toute la base.

**Site neuf, sans reprise :**

```bash
docker compose exec site drush site:install --existing-config -y
docker compose exec site drush cim -y
docker compose exec site drush deploy:hook -y
```

- `cim` remet les libellés que l'installation des traductions a modifiés.
  Une quinzaine de différences mineures restent ensuite affichées par
  `config:status` (réglages que Drupal ajoute de lui-même à une installation
  neuve) : elles sont sans effet sur le site.
- `deploy:hook` crée les contenus de départ : textes de l'accueil et des
  autres pages, page À propos, mot du gouverneur, chiffres clés,
  coordonnées, fiches des membres. **Sans lui, l'accueil et les en-têtes
  restent vides.** Les photos des membres sont à ajouter ensuite dans leurs
  fiches.

## 4. Essayer avant de basculer

Ouvrir `http://ADRESSE_IP_DU_VPS` (ajouter l'adresse IP à `DOMAINES` dans
`.env`, puis `docker compose up -d`). Vérifier :

- l'accueil, un article, la recherche, la page Contact ;
- l'envoi d'un message depuis `/contact` ;
- l'assistant ;
- la connexion à l'administration.

## 5. Basculer le domaine (HTTPS)

1. La veille, baisser la durée de cache (TTL) des enregistrements DNS.
2. Faire pointer `kinshasa.cd` et `www.kinshasa.cd` vers l'adresse IP du VPS.
3. Dans `.env` : `SITE_ADDRESS=kinshasa.cd, www.kinshasa.cd`, puis
   `docker compose up -d`.
4. Caddy obtient les certificats en quelques secondes. Le site est alors en
   HTTPS, et `http://` redirige vers `https://`.

Garder l'ancien hébergement quelques jours en secours.

## 6. Au quotidien

| Besoin | Commande (depuis `docker/`) |
|---|---|
| Mettre à jour le site | `./deployer.sh` |
| Commande drush | `docker compose exec site drush cr` (ou `cst`, `cim`, `uli`…) |
| Journaux en direct | `docker compose logs -f site` |
| État des services | `docker compose exec site supervisorctl status` |
| Redémarrer | `docker compose restart site` |
| Sauvegarder | `./sauvegarder.sh` |

`./deployer.sh` montre `config:status` et demande confirmation avant
d'importer, comme le cycle « cr / config:status / cim » habituel.

**Sauvegarde automatique chaque nuit** (`crontab -e` sur le serveur) :

```
17 2 * * * cd /home/UTILISATEUR/gouvernoratkin/docker && ./sauvegarder.sh >> sauvegardes/journal.txt 2>&1
```

Copier ensuite `docker/sauvegardes/` **hors du serveur** (autre serveur,
stockage objet). Une sauvegarde restée sur le serveur disparaît avec lui.

**Restaurer une sauvegarde :**

```bash
docker compose cp sauvegardes/base-DATE.sql.gz site:/tmp/
docker compose exec site bash -c 'drush sql:drop -y && gunzip -c /tmp/base-DATE.sql.gz | drush sql:cli && rm /tmp/base-DATE.sql.gz'
docker compose exec -T site tar xzf - -C /var/www/html/sites/default < sauvegardes/fichiers-DATE.tar.gz
docker compose exec site drush cr
```

La commande `tar` remet le dossier `files`. Pour remettre aussi les fichiers
privés, relancer `tar` avec `-C /var/www` à la place de
`-C /var/www/html/sites/default`.

**Mises à jour de sécurité** de PHP, Apache, MariaDB et Caddy : une fois par
mois, `docker compose build --pull && docker compose up -d`.

## 7. Variante : base de données externe

Pour une base gérée à part (service de l'hébergeur, autre serveur), indiquer
son adresse dans `DB_HOST` (et `DB_PORT`, `DB_NAME`, `DB_USER`,
`DB_PASSWORD`). MariaDB ne démarre alors pas dans le conteneur, et le volume
`base` reste vide.

## Bon à savoir

- **Configuration exportée** : dans l'image, elle est rangée hors du site
  (`/var/www/config/sync`). Elle vient de
  `sites/default/files/config_…/sync` du dépôt. Elle se met à jour à chaque
  reconstruction de l'image.
- **`settings.php`** du conteneur : `docker/settings.php`. Pour un réglage
  propre à un serveur, créer `sites/default/settings.local.php` dans l'image
  ou le monter en volume.
- **Courriels** : ils partent par `mail.kinshasa.cd` (module SMTP), pas
  depuis le VPS. Les enregistrements SPF, DKIM et DMARC de `kinshasa.cd`
  restent valables.
