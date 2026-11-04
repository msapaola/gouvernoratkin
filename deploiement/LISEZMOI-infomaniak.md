# Mise en production chez Infomaniak

Marche à suivre pour installer le site du Gouvernorat de Kinshasa sur
l'hébergement Infomaniak, à partir du site de test (Hostinger).

Ce dossier est fermé aux visiteurs (`.htaccess` : `Require all denied`).

| Fichier | Rôle |
|---|---|
| `settings.production.php.exemple` | Modèle des réglages du serveur (base, SMTP, domaines), **sans secret** |
| `cron.sh` | Tâches planifiées de Drupal, à lancer toutes les heures |
| `sauvegarde.sh` | Sauvegarde quotidienne de la base et des fichiers, hors du web |
| `../.well-known/security.txt` | Contact pour signaler une faille (norme RFC 9116) |

---

## 1. Préparer l'hébergement (Manager Infomaniak)

- [ ] Créer le site pour **kinshasa.cd** et **www.kinshasa.cd**, avec un
      certificat SSL (Let's Encrypt) couvrant les deux adresses.
- [ ] Version de PHP : **8.3** (identique au site de test).
- [ ] Activer l'accès **SSH** et noter l'utilisateur et le chemin du site.
- [ ] Créer la **base MySQL** et son utilisateur ; noter le serveur, le nom,
      l'utilisateur et le mot de passe.
- [ ] Créer la boîte **contact@…** (ou une adresse d'expédition dédiée) et
      noter son mot de passe : il servira à l'envoi des courriels du site.
- [ ] Créer la boîte **securite@kinshasa.cd** (ou équivalent) pour
      `security.txt`, et désigner qui la relève.

## 2. Copier le site

Sur le serveur Infomaniak, dans le dossier du site :

```bash
git clone -b <branche> https://github.com/msapaola/gouvernoratkin.git .
```

Depuis Hostinger, exporter la base et les fichiers téléversés :

```bash
cd ~/domains/test.kinshasa.cd/public_html
vendor/bin/drush sql:dump --gzip --result-file=$HOME/migration.sql
tar -czf $HOME/fichiers.tar.gz --exclude=sites/default/files/css \
  --exclude=sites/default/files/js --exclude=sites/default/files/php \
  --exclude=sites/default/files/styles sites/default/files
```

Les transférer vers Infomaniak (`scp`), **jamais** par le dossier web.
Puis, chez Infomaniak :

```bash
tar -xzf ~/fichiers.tar.gz
gunzip -c ~/migration.sql.gz | vendor/bin/drush sql:cli
```

## 3. Réglages du serveur

1. Copier `settings.php` depuis Hostinger (il contient le `hash_salt` à
   garder) vers `sites/default/settings.php`.
2. Copier `deploiement/settings.production.php.exemple` vers
   `sites/default/settings.local.php` et remplir les valeurs `<…>`.
3. Vérifier que la fin de `settings.php` inclut `settings.local.php`.
4. `chmod 440 sites/default/settings.php sites/default/settings.local.php`
5. `vendor/bin/drush cr` puis `vendor/bin/drush status` : la base doit
   être « Connected ».

## 4. Domaine

Décider de l'adresse principale : **kinshasa.cd** ou **www.kinshasa.cd**.
Dans `.htaccess`, section « R8 — Une seule adresse par page », décommenter
la paire de lignes correspondante (a ou b). L'autre adresse sera redirigée
(301) vers la principale.

Dans `.well-known/security.txt`, garder seulement la ligne `Canonical`
de l'adresse principale.

## 5. Vérifications de sécurité

- [ ] `/admin/config/development/settings` : **toutes les cases décochées**
      (sinon les caches sont coupés et le site est lent).
- [ ] `/admin/reports/status` : aucune erreur rouge.
- [ ] En-têtes : `curl -sI https://kinshasa.cd | sort` ne doit montrer ni
      `X-Powered-By`, ni `X-Generator`, ni en-tête propre à l'hébergeur ;
      `X-Robots-Tag: noindex` ne doit **pas** apparaître en production.
- [ ] `https://kinshasa.cd/core/install.php` → 403.
- [ ] `https://kinshasa.cd/.well-known/security.txt` → le fichier s'affiche.
- [ ] `https://kinshasa.cd/deploiement/` → 403.

## 6. Courriels

Ouvrir `/admin/config/system/smtp`, envoyer un courriel de test, puis
envoyer un message depuis `/contact` et vérifier sa réception.

## 7. Tâches planifiées (Manager › Tâches planifiées)

| Commande | Fréquence |
|---|---|
| `bash <chemin>/deploiement/cron.sh` | toutes les heures |
| `bash <chemin>/deploiement/sauvegarde.sh` | chaque nuit (ex. 2 h) |

Puis, dans Drupal, `/admin/config/system/cron` : « Exécuter cron tous les »
→ **Jamais**.

Tester une fois la sauvegarde à la main, et **tester une restauration**
sur une base vide : une sauvegarde jamais restaurée n'est pas une garantie.

## 8. Site de test

Une fois la production ouverte, fermer `test.kinshasa.cd` ou le protéger
par mot de passe (Manager › Site › Protection par mot de passe, ou un
`.htpasswd` placé hors du dossier web).

## 9. Après l'ouverture

- Déclarer le site dans Google Search Console et y soumettre
  `https://kinshasa.cd/sitemap.xml` (une fois le plan du site installé).
- Renouveler la date `Expires` de `security.txt` chaque année
  (prochaine échéance : 4 novembre 2027).
