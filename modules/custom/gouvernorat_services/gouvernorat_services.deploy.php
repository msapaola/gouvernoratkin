<?php

/**
 * @file
 * Import des démarches « Droits, Taxes et Redevances ».
 *
 * À lancer une fois, après l'import de la configuration :
 *   vendor/bin/drush deploy:hook -y
 */

use Drupal\node\Entity\Node;

/**
 * Normalise un libellé pour le comparer : casse, accents, apostrophes, espaces.
 */
function _gouvernorat_services_cle(string $texte): string {
  $texte = str_replace(['’', '‘', '«', '»'], ["'", "'", '', ''], $texte);
  $texte = \Drupal::service('transliteration')->transliterate($texte, 'fr');
  return trim(preg_replace('/\s+/', ' ', mb_strtolower($texte)));
}

/**
 * Importe les 19 démarches du document Articles_Taxes_Ville_de_Kinshasa_v2.
 *
 * Les données sont dans data/droits_taxes_redevances.json, extraites du
 * document Word : résumé (champ « Résumé »), corps (rubriques en intertitres,
 * tableaux conservés) et secteur. Les lignes « [À COMPLÉTER] » du document
 * n'ont pas été reprises.
 *
 * - Une démarche déjà publiée (retrouvée par son titre actuel) reçoit le
 *   nouveau texte dans une nouvelle révision ; son titre, son adresse et son
 *   statut ne changent pas. L'ancien texte reste dans ses révisions.
 * - Une démarche absente est créée et publiée.
 * - La présentation de chaque secteur devient la description de son terme,
 *   si celle-ci est vide.
 */
function gouvernorat_services_deploy_droits_taxes_redevances() {
  $chemin = \Drupal::service('extension.list.module')->getPath('gouvernorat_services') . '/data/droits_taxes_redevances.json';
  $donnees = json_decode(file_get_contents($chemin), TRUE);

  $termes = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'service_au_citoyen']);
  $par_nom = [];
  foreach ($termes as $terme) {
    $par_nom[_gouvernorat_services_cle($terme->label())] = $terme;
  }

  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $par_titre = [];
  foreach ($storage->loadByProperties(['type' => 'service_au_citoyen']) as $node) {
    $par_titre[_gouvernorat_services_cle($node->label())] = $node;
  }

  $rapport = ['mises à jour' => 0, 'créées' => 0, 'descriptions' => 0];
  $problemes = [];

  foreach ($donnees['secteurs'] as $secteur) {
    $terme = $par_nom[_gouvernorat_services_cle($secteur['secteur'])] ?? NULL;
    if (!$terme) {
      $problemes[] = 'secteur introuvable : ' . $secteur['secteur'] . ' (' . count($secteur['articles']) . ' démarches non importées)';
      continue;
    }

    if ($secteur['introduction'] && trim((string) $terme->getDescription()) === '') {
      $terme->setDescription($secteur['introduction'])->setFormat('full_html')->save();
      $rapport['descriptions']++;
    }

    foreach ($secteur['articles'] as $article) {
      $node = NULL;
      foreach (array_filter([$article['titre_existant'], $article['titre_nouveau'], $article['titre_document']]) as $titre) {
        if ($node = $par_titre[_gouvernorat_services_cle($titre)] ?? NULL) {
          break;
        }
      }

      if ($node) {
        // Les doubles espaces de certains titres saisis à la main disparaissent.
        $node->setTitle(trim(preg_replace('/\s+/u', ' ', $node->label())));
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage('Texte mis à jour depuis ' . $donnees['source'] . '.');
        $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        $rapport['mises à jour']++;
      }
      else {
        $node = Node::create([
          'type' => 'service_au_citoyen',
          'title' => $article['titre_nouveau'] ?? $article['titre_document'],
          'uid' => 1,
          'status' => 1,
        ]);
        $node->setRevisionLogMessage('Créée depuis ' . $donnees['source'] . '.');
        $rapport['créées']++;
      }

      $node->set('body', [
        'value' => $article['corps'],
        'summary' => $article['resume'],
        'format' => 'full_html',
      ]);
      $node->set('field_service_au_citoyen', $terme->id());
      $node->save();
    }
  }

  $message = sprintf('Droits, Taxes et Redevances : %d démarches mises à jour, %d créées, %d descriptions de secteur ajoutées.', $rapport['mises à jour'], $rapport['créées'], $rapport['descriptions']);
  if ($problemes) {
    $message .= ' À vérifier : ' . implode(' ; ', $problemes) . '.';
  }
  return $message;
}
