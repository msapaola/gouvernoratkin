<?php

/**
 * @file
 * Adresses lisibles des contenus qui n'en avaient pas.
 *
 * À lancer une fois, après l'import de la configuration :
 *   vendor/bin/drush deploy:hook -y
 */

/**
 * Crée l'alias des contenus qui n'en ont pas encore.
 *
 * Les fiches « Service au citoyen », les pages simples et la page « À
 * propos » n'avaient aucun motif Pathauto : elles répondaient sous /node/N.
 * Les motifs motif_demarche et motif_page sont importés avec la
 * configuration ; ce script les applique aux contenus existants. Un contenu
 * qui a déjà une adresse n'est pas touché, même si elle a été saisie à la
 * main. Les anciennes adresses /node/N restent valables : elles renvoient
 * (301) vers la nouvelle.
 */
function gouvernorat_seo_deploy_alias_manquants(&$sandbox) {
  $stockage = \Drupal::entityTypeManager()->getStorage('node');
  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = array_values($stockage->getQuery()->accessCheck(FALSE)->sort('nid')->execute());
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['crees'] = 0;
  }

  $depot = \Drupal::service('path_alias.repository');
  $generateur = \Drupal::service('pathauto.generator');
  $lot = array_splice($sandbox['ids'], 0, 50);
  foreach ($stockage->loadMultiple($lot) as $node) {
    $systeme = '/node/' . $node->id();
    if ($depot->lookupBySystemPath($systeme, $node->language()->getId())
      || $depot->lookupBySystemPath($systeme, 'und')) {
      continue;
    }
    if ($generateur->createEntityAlias($node, 'insert')) {
      $sandbox['crees']++;
    }
  }

  $sandbox['#finished'] = $sandbox['total'] ? 1 - count($sandbox['ids']) / $sandbox['total'] : 1;
  if ($sandbox['#finished'] >= 1) {
    return sprintf('%d adresse(s) créée(s) sur %d contenus examinés.', $sandbox['crees'], $sandbox['total']);
  }
}
