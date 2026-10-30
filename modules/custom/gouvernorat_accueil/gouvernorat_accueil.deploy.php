<?php

/**
 * @file
 * Contenu de départ des blocs de la page d'accueil.
 *
 * À lancer une fois, après l'import de la configuration :
 *   vendor/bin/drush deploy:hook -y
 * (drush deploy l'exécute aussi de lui-même). Drupal note qu'il a tourné et
 * ne le relance plus.
 */

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Recopie le mot du gouverneur et les chiffres clés dans leurs blocs.
 *
 * Ce sont les textes qui étaient écrits dans page--front.html.twig, pour que
 * l'accueil reste identique après la mise à jour. Un bloc déjà créé n'est pas
 * touché.
 */
function gouvernorat_accueil_deploy_contenu_initial() {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $messages = [];

  if (!$storage->loadByProperties(['type' => 'mot_gouverneur'])) {
    $bloc = BlockContent::create([
      'type' => 'mot_gouverneur',
      'info' => 'Accueil : mot du gouverneur',
      'field_gouverneur_nom' => 'Daniel Bumba',
      'field_gouverneur_fonction' => 'Gouverneur de la Ville-Province de Kinshasa',
      'field_gouverneur_citation' => "Chers Kinois, chères Kinoises, nous sommes à l'aube d'une nouvelle ère pour Kinshasa. Nous nous engageons dans un voyage vers l'amélioration de notre ville bien-aimée.\n\nChaque projet, chaque effort est un pas vers cette transformation : faire de Kinshasa une ville qui s'améliore continuellement, pour nous et pour les générations futures.",
      'reusable' => TRUE,
    ]);

    // Le portrait était un fichier du thème : il est copié dans les fichiers
    // du site pour devenir remplaçable depuis le formulaire du bloc.
    $source = \Drupal::service('extension.list.theme')->getPath('gouvernoratkin') . '/assets/images/bumba01.png';
    if (file_exists($source)) {
      $file_system = \Drupal::service('file_system');
      $dossier = 'public://accueil';
      $file_system->prepareDirectory($dossier, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $uri = $file_system->copy($source, $dossier . '/gouverneur.png', FileExists::Rename);
      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();
      $bloc->set('field_gouverneur_portrait', [
        'target_id' => $file->id(),
        'alt' => 'Daniel Bumba, gouverneur de la Ville-Province de Kinshasa',
      ]);
    }

    $bloc->save();
    $messages[] = 'Bloc « Mot du gouverneur » créé.';
  }

  if (!$storage->loadByProperties(['type' => 'chiffres_cles'])) {
    $chiffres = [
      ['17,7 M', "habitants dans l'aire urbaine"],
      ['9 965 km²', 'de superficie, 24 communes'],
      ['90 %', 'de taux de scolarisation primaire'],
      ['60 %', 'de la population à moins de 25 ans'],
    ];
    $paragraphes = [];
    foreach ($chiffres as [$valeur, $libelle]) {
      // Non enregistrés ici : le champ de référence les enregistre avec le
      // bloc, en notant le bloc comme parent.
      $paragraphes[] = Paragraph::create([
        'type' => 'chiffre_cle',
        'field_chiffre_valeur' => $valeur,
        'field_chiffre_libelle' => $libelle,
      ]);
    }

    BlockContent::create([
      'type' => 'chiffres_cles',
      'info' => 'Accueil : chiffres clés',
      'field_chiffres' => $paragraphes,
      'field_chiffres_source' => 'Source : à compléter — institut et année de référence.',
      'reusable' => TRUE,
    ])->save();
    $messages[] = 'Bloc « Chiffres clés » créé.';
  }

  return $messages ? implode(' ', $messages) : 'Les blocs de l\'accueil existaient déjà : rien à faire.';
}
