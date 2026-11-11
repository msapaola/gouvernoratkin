<?php

/**
 * @file
 * Mises à jour de contenu de la lettre d'information.
 */

use Drupal\Component\Utility\Html;

/**
 * Branche le formulaire d'abonnement du pied de page sur la lettre.
 *
 * Le formulaire est écrit dans un bloc de contenu : il gardait l'adresse
 * d'envoi du thème d'origine. Il envoie désormais vers /lettre/inscription
 * (utile si JavaScript est désactivé ; sinon le script du thème s'en charge).
 */
function gouvernorat_lettre_deploy_formulaire_pied_de_page(): string {
  $stockage = \Drupal::entityTypeManager()->getStorage('block_content');
  $ids = \Drupal::entityQuery('block_content')
    ->accessCheck(FALSE)
    ->condition('body.value', 'newsletter-form', 'CONTAINS')
    ->execute();
  $corriges = [];
  foreach ($stockage->loadMultiple($ids) as $bloc) {
    $valeur = $bloc->get('body')->value;
    $dom = Html::load($valeur);
    $change = FALSE;
    foreach ($dom->getElementsByTagName('form') as $formulaire) {
      if (!str_contains(' ' . $formulaire->getAttribute('class') . ' ', ' newsletter-form ')) {
        continue;
      }
      $formulaire->setAttribute('action', base_path() . 'lettre/inscription');
      $formulaire->setAttribute('method', 'post');
      $formulaire->removeAttribute('target');
      foreach ($formulaire->getElementsByTagName('input') as $champ) {
        if ($champ->getAttribute('type') === 'email' || strtoupper($champ->getAttribute('name')) === 'EMAIL') {
          $champ->setAttribute('name', 'courriel');
          $champ->setAttribute('type', 'email');
          $champ->setAttribute('required', 'required');
          $champ->setAttribute('autocomplete', 'email');
        }
      }
      $change = TRUE;
    }
    if ($change) {
      $bloc->get('body')->value = Html::serialize($dom);
      $bloc->setNewRevision(TRUE);
      $bloc->setRevisionLogMessage("Formulaire d'abonnement branché sur la lettre d'information.");
      $bloc->save();
      $corriges[] = $bloc->label();
    }
  }
  return $corriges
    ? "Formulaire d'abonnement branché dans : " . implode(', ', $corriges) . '.'
    : "Aucun formulaire d'abonnement trouvé dans les blocs de contenu (le script du thème branche quand même ceux de la page).";
}
