# Gouvernorat de Kinshasa — modules du site

Tout le code propre au site du Gouvernorat est rangé dans ce dossier.

Le module principal, **Gouvernorat de Kinshasa** (`gouvernorat_kinshasa`),
ne contient pas de code : il active l'ensemble des sous-modules ci-dessous,
qui apparaissent groupés dans *Extension* sous « Gouvernorat de Kinshasa ».

Chaque sous-module fait une seule chose. On peut ainsi corriger ou
désactiver une fonction sans toucher aux autres.

| Sous-module | Rôle |
|---|---|
| `gouvernorat_accueil` | Mot du gouverneur et chiffres clés de l'accueil, modifiables dans Contenu › Blocs |
| `gouvernorat_assistant` | Assistant virtuel (Claude, modèle Haiku) qui répond aux visiteurs et les oriente vers les pages du site |
| `gouvernorat_contact` | Page Contact : coordonnées modifiables, formulaire allégé |
| `gouvernorat_institutions` | Fiches « Membre », pages Exécutif provincial et Cabinet du gouverneur |
| `gouvernorat_pages` | Pages institutionnelles en « Page de base » à sections (À propos…) : textes dans la base, plus dans les gabarits |
| `gouvernorat_partage` | Image et description de repli au partage sur les réseaux sociaux |
| `gouvernorat_securite` | En-têtes de sécurité, `noindex` sur les sites de test |
| `gouvernorat_seo` | Redirections 301, adresses lisibles, `sitemap.xml`, données JSON-LD |
| `gouvernorat_services` | Services aux citoyens : secteurs, import des droits, taxes et redevances |
| `gouvernorat_videos` | Vidéos YouTube : page /videos et section de l'accueil |

La mise en page (gabarits, styles) reste dans le thème `gouvernoratkin`.

## Ajouter une fonction

- Elle prolonge une fonction existante : la placer dans le sous-module
  concerné.
- Elle est nouvelle : créer `modules/gouvernorat_<nom>/` avec son fichier
  `gouvernorat_<nom>.info.yml` (`package: 'Gouvernorat de Kinshasa'`), puis
  ajouter `- gouvernorat_<nom>` aux dépendances de
  `gouvernorat_kinshasa.info.yml` et l'activer (`drush en gouvernorat_<nom>`,
  puis `drush cex` pour l'inscrire dans la configuration).

## Désinstaller

Désinstaller un sous-module arrête la fonction mais **ne supprime aucune
donnée** : les contenus, blocs, champs et adresses qu'il a créés sont des
éléments ordinaires de Drupal et restent en place. Ne retirez pas une ligne
`gouvernorat_…` de `core.extension.yml` sans le vouloir : un `drush cim`
désinstallerait le module correspondant.
