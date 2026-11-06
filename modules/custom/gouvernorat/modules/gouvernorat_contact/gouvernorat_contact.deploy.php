<?php

/**
 * @file
 * Contenu de départ du bloc des coordonnées de la page Contact.
 *
 * À lancer une fois, après l'import de la configuration :
 *   vendor/bin/drush deploy:hook -y
 */

use Drupal\block_content\Entity\BlockContent;

/**
 * Crée le bloc « Coordonnées du Gouvernorat » avec l'adresse du pied de page.
 *
 * Un bloc déjà créé n'est pas touché. Le téléphone et les horaires restent
 * vides : ils seront saisis dans Contenu › Blocs quand ils seront connus.
 */
function gouvernorat_contact_deploy_coordonnees_initiales() {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  if ($storage->loadByProperties(['type' => 'coordonnees'])) {
    return 'Bloc des coordonnées déjà présent : rien à faire.';
  }

  BlockContent::create([
    'type' => 'coordonnees',
    'info' => 'Contact : coordonnées du Gouvernorat',
    'field_coord_adresse' => "Avenue Colonel Ebeya, Commune de la Gombe\nKinshasa, République démocratique du Congo",
    'field_coord_courriel' => 'contact@gouv.kinshasa.cd',
    'reusable' => TRUE,
  ])->save();

  return 'Bloc « Coordonnées du Gouvernorat » créé : téléphone et horaires à compléter dans Contenu › Blocs.';
}
