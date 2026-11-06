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

/**
 * Corrige les défauts relevés par le diagnostic dans les textes enregistrés.
 *
 * Ces textes vivent dans la base (blocs du pied de page, corps des
 * contenus) et non dans le code :
 * - U1 : le logo du pied de page pointait vers /acceuil (faute) → 404 ;
 * - U3 : bouton « Abonez-vous » et champ « Your Email » ;
 * - S10 : liens ouverts dans un nouvel onglet (target="_blank", comme celui
 *   de Google Maps) sans rel="noopener noreferrer" ;
 * - U2 : « Source : à compléter » affiché sous les chiffres clés de
 *   l'accueil ; la ligne est vidée, donc masquée, jusqu'à ce qu'une vraie
 *   source soit saisie dans Contenu › Blocs.
 * Un texte déjà correct n'est pas modifié.
 */
function gouvernorat_seo_deploy_corrections_contenus() {
  $corriger = function (string $html): string {
    $html = preg_replace('#href=(["\'])(?:https?://[^"\']*kinshasa\.cd)?/acceuil/?\1#i', 'href=$1/$1', $html);
    $html = str_replace(['Abonez-vous', 'Abonez vous'], 'Abonnez-vous', $html);
    $html = preg_replace('#placeholder=(["\'])Your Email\1#i', 'placeholder=$1Votre adresse de courriel$1', $html);
    return preg_replace_callback('#<a\b[^>]*>#i', function ($m) {
      $balise = $m[0];
      if (!preg_match('#\btarget\s*=\s*["\']?_blank#i', $balise)) {
        return $balise;
      }
      if (preg_match('#\brel\s*=\s*(["\'])(.*?)\1#i', $balise, $rel)) {
        $valeurs = preg_split('/\s+/', trim($rel[2]));
        $ajout = array_diff(['noopener', 'noreferrer'], $valeurs);
        return $ajout ? str_replace($rel[0], 'rel="' . trim(implode(' ', array_merge($valeurs, $ajout))) . '"', $balise) : $balise;
      }
      return preg_replace('#\s*/?>$#', ' rel="noopener noreferrer"$0', $balise, 1);
    }, $html);
  };

  $corriges = [];
  $gestionnaire = \Drupal::entityTypeManager();
  foreach (['block_content', 'node'] as $type) {
    $stockage = $gestionnaire->getStorage($type);
    foreach ($stockage->loadMultiple($stockage->getQuery()->accessCheck(FALSE)->execute()) as $entite) {
      $modifie = FALSE;
      foreach ($entite->getFields() as $nom => $champ) {
        if (!in_array($champ->getFieldDefinition()->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)) {
          continue;
        }
        foreach ($champ as $element) {
          $avant = (string) $element->value;
          $apres = $corriger($avant);
          if ($apres !== $avant) {
            $element->value = $apres;
            $modifie = TRUE;
          }
        }
      }
      if ($type === 'block_content' && $entite->bundle() === 'chiffres_cles' && $entite->hasField('field_chiffres_source')
        && str_contains((string) $entite->get('field_chiffres_source')->value, 'à compléter')) {
        $entite->set('field_chiffres_source', NULL);
        $modifie = TRUE;
      }
      if ($modifie) {
        $entite->save();
        $corriges[] = $type . ' ' . $entite->id() . ' (' . $entite->label() . ')';
      }
    }
  }

  return $corriges ? 'Textes corrigés : ' . implode(', ', $corriges) . '.' : 'Aucun texte à corriger.';
}
