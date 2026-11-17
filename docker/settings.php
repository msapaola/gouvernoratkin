<?php

/**
 * @file
 * Réglages de Drupal dans le conteneur Docker.
 *
 * Aucun secret ici : tout vient des variables d'environnement (fichier .env
 * à côté de compose.yaml, jamais versionné). Voir docker/LISEZMOI.md.
 */

$env = static function (string $nom, ?string $defaut = NULL): ?string {
  $valeur = getenv($nom);
  return ($valeur === FALSE || $valeur === '') ? $defaut : $valeur;
};

$databases['default']['default'] = [
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  'host' => $env('DB_HOST', 'db'),
  'port' => $env('DB_PORT', '3306'),
  'database' => $env('DB_NAME', 'drupal'),
  'username' => $env('DB_USER', 'drupal'),
  'password' => $env('DB_PASSWORD', ''),
  // Préfixe des tables : « cu68_ » pour une base importée telle quelle depuis
  // l'hébergement actuel, vide pour une base neuve.
  'prefix' => $env('DB_PREFIX', ''),
  'collation' => 'utf8mb4_general_ci',
  'init_commands' => [
    'wait_timeout' => 'SET SESSION wait_timeout = 600',
  ],
];

$settings['hash_salt'] = $env('HASH_SALT', '');
$settings['config_sync_directory'] = '/var/www/config/sync';
$settings['file_private_path'] = '/var/www/private';
$settings['file_temp_path'] = '/tmp';
$settings['update_free_access'] = FALSE;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['skip_permissions_hardening'] = TRUE;

// Noms de domaine acceptés (séparés par des virgules).
$settings['trusted_host_patterns'] = array_map(
  static fn (string $hote): string => '^' . preg_quote(trim($hote)) . '$',
  explode(',', $env('DOMAINES', 'localhost'))
);

// Nginx Proxy Manager transmet les visites par le réseau Docker « proxy ».
// Son adresse IP change d'un redémarrage à l'autre : Drupal croit les
// en-têtes X-Forwarded-* seulement si la connexion vient d'une plage privée
// Docker. Il génère alors des adresses en https://, reconnaît le HTTPS (pas
// de boucle de redirection, pas de contenu mixte) et enregistre l'adresse
// réelle du visiteur (journaux, limites anti-abus du contact et de
// l'assistant). Une connexion d'ailleurs ne peut pas usurper ces en-têtes.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['172.16.0.0/12', '10.0.0.0/8'];
$settings['reverse_proxy_trusted_headers'] =
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;

// Site de test : pas d'indexation par les moteurs de recherche.
$settings['gouvernorat_noindex'] = $env('NOINDEX', '0') === '1';

// Secrets : mot de passe SMTP (site@kinshasa.cd) et clé de l'assistant.
if ($motDePasse = $env('SMTP_PASSWORD')) {
  $config['smtp.settings']['smtp_password'] = $motDePasse;
}
if ($cle = $env('ANTHROPIC_API_KEY')) {
  $settings['gouvernorat_assistant_cle_api'] = $cle;
}

// Ollama, joint en interne par le réseau Docker « ia » (sans passer par le
// proxy, sans clé). Réglage mis à disposition du chatbot : l'assistant actuel
// n'utilise que l'API d'Anthropic et ne le lit pas encore.
$settings['gouvernorat_assistant_ollama_url'] = $env('OLLAMA_BASE_URL', 'http://ollama:11434');

// Tâches planifiées : lancées par le conteneur (docker/cron.sh). Le cron
// « automatique » de Drupal, déclenché pendant la visite d'un internaute,
// ferait doublon et ralentirait cette visite : il est coupé.
$config['automated_cron.settings']['interval'] = 0;

// Production : fichiers CSS/JS regroupés, erreurs cachées aux visiteurs.
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
$config['system.logging']['error_level'] = $env('AFFICHER_ERREURS', '0') === '1' ? 'verbose' : 'hide';

// Réglages propres à un serveur, si besoin (non versionnés).
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
