<?php

/**
 * @file
 * Reprise des membres de l'exécutif et du cabinet en fiches « Membre ».
 *
 * À lancer une fois, après l'import de la configuration :
 *   vendor/bin/drush deploy:hook -y
 */

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Crée une fiche « Membre » par personne des anciens blocs 10 et 23.
 *
 * Les données sont celles des blocs « Exécutif provincial » (n° 10) et
 * « Cabinet du gouverneur 2 » (n° 23), relues le 30 octobre 2026, telles
 * quelles : seuls « Monsieur » en tête des noms et le point final de
 * certaines fonctions ont été retirés. Les photos génériques (user1.png)
 * ne sont pas reprises : la carte affiche alors les initiales.
 *
 * Les anciens blocs ne sont pas supprimés. Si des fiches « Membre » existent
 * déjà, rien n'est fait.
 */
function gouvernorat_institutions_deploy_reprise_membres() {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if ($storage->loadByProperties(['type' => 'membre'])) {
    return 'Des fiches « Membre » existent déjà : rien à faire.';
  }

  // [nom, fonction, instance, photo (dans public://), description, mot]
  $membres = [
    ['Daniel Bumba Lubaki', 'Gouverneur', 'gouvernorat', 'inline-images/bumba.JPG',
      "En tant que Gouverneur de la ville-province de Kinshasa, il est responsable de la direction générale de l'administration provinciale, de la représentation de la province au niveau national et international, et de la mise en œuvre des politiques de développement."],
    ['Eddy Iyeli Molangi', 'Vice-Gouverneur', 'gouvernorat', 'inline-images/eddy_iyeli.JPG',
      "Le Vice-Gouverneur assiste le Gouverneur dans ses fonctions et le remplace en cas d'absence. Il est également chargé de superviser certains secteurs spécifiques de l'administration provinciale selon les attributions définies par le Gouverneur."],

    ['Thierry Tshitenga Kabuya', "Ministre de l'Education, Formation Professionnelle, Citoyenneté, Genre et Famille", 'ministres', '2026-03/IMG_0924.jpg',
      "Responsable de la politique éducative, de la formation professionnelle, de la promotion de la citoyenneté, ainsi que de la protection du genre et de la famille dans la ville-province."],
    ['Magloire KABEMBA OKANDJA', 'Ministre des Finances, Économie et Numérique', 'ministres', '2026-03/DSC02530.JPG',
      "Responsable des politiques économiques, financières et du développement numérique de la province."],
    ['Alain Tshilungu', 'Ministre des Infrastructures et Travaux publics', 'ministres', 'inline-images/alain1.jpeg',
      "Responsable du développement et de l'entretien des infrastructures provinciales et des travaux publics."],
    ['Jésus-Noël Sheke Wa Domene', 'Ministre du Plan, Budget, Fonction Publique, Emploi, Tourisme, Transports et Mobilité Urbaine', 'ministres', 'inline-images/jesus1.jpeg',
      "Responsable de la planification, du budget provincial, des politiques d'emploi, du développement touristique et de la mobilité urbaine."],
    ['Léon Mulumba Mwana Nshiya', "Ministre de l'Environnement, Propreté publique, Embellissement de la Ville et Aménagement", 'ministres', 'inline-images/leon.jpeg',
      "Responsable de la protection de l'environnement, de la gestion de la propreté publique, de l'embellissement urbain et de l'aménagement de la ville-province."],
    ['Bob Amisso Yoka', 'Ministre des Sports, des Loisirs, de la Jeunesse, de la Culture et des Arts', 'ministres', 'inline-images/bob1.jpeg',
      "Responsable du développement sportif, de l'épanouissement de la jeunesse, ainsi que de la promotion des loisirs, de la culture et des arts dans la province."],
    ['Jeannot Canon Larose', "Ministre de l'Éducation et Citoyenneté", 'ministres', 'inline-images/jeannot1.jpeg',
      "Responsable de la politique éducative et de la promotion de la citoyenneté dans la province."],
    ['Fiston Lukwebo Musengo', 'Ministre du Partenariat Public-Privé, Commerce et Artisanat', 'ministres', 'inline-images/fiston1.jpeg',
      "Responsable du développement des partenariats entre secteurs public et privé, et de la promotion du commerce et de l'artisanat."],
    ['Israël Kabenda Kayuwa', 'Ministre de la Jeunesse, Hydrocarbures, Culture et Arts', 'ministres', 'inline-images/kabenda.jpeg',
      "Responsable des politiques de jeunesse, de la gestion des hydrocarbures, et de la promotion de la culture et des arts."],
    ['Patricien Gongo Abakazi', 'Ministre de la Santé, Hygiène et Prévention', 'ministres', 'inline-images/patricien.jpeg',
      "Responsable de la politique de santé publique, d'hygiène et de prévention sanitaire dans la province."],

    ['Israël MUTALA LUKUSA', 'Secrétaire Exécutif', 'secretariat', 'inline-images/israel_0.jpeg',
      "Responsable de la coordination des services, du suivi des décisions du Conseil des Ministres et de l'administration générale du gouvernement provincial."],
    ['Samuel KIMBUKUSU LUNGUNGU', 'Secrétaire Exécutif Adjoint', 'secretariat', NULL,
      "Responsable de l'assistance à la coordination des services, du suivi administratif des dossiers et de l'exécution des directives du gouvernement provincial."],
    ['Jean Pierre MPASI MAZOWA', 'Secrétaire Exécutif Adjoint', 'secretariat', NULL,
      "Responsable de l'assistance à la coordination des services, du suivi administratif des dossiers et de l'exécution des directives du gouvernement provincial."],

    ['Elie Luba Kalowa', 'Directeur de Cabinet', 'cabinet', '2026-03/IMG_0900.jpg',
      'Supervise la mise en œuvre de la vision du gouverneur.',
      "Nous sommes dévoués à la mise en œuvre du programme « Kinshasa ezo bonga » pour une ville moderne et prospère. Notre équipe travaille avec passion et détermination pour transformer la vision du Gouverneur Daniel Bumba en réalité concrète, au bénéfice de tous les Kinois."],
    ['Marilyn Yema Mputu', 'Directrice Adjointe de Cabinet', 'cabinet', NULL,
      'Chargée des questions économiques, financières, de gouvernance, techniques et numériques.'],
    ['Godefroid Motuke Andoma', 'Directeur Adjoint de Cabinet', 'cabinet', 'inline-images/motuke.JPG',
      'Responsable des questions politiques, juridiques et administratives.'],
  ];

  $ordre = [];
  $sans_photo = [];
  foreach ($membres as $donnees) {
    [$nom, $fonction, $instance, $photo, $description] = $donnees;
    $ordre[$instance] = ($ordre[$instance] ?? 0) + 10;

    $membre = Node::create([
      'type' => 'membre',
      'title' => $nom,
      'field_membre_fonction' => $fonction,
      'field_membre_instance' => $instance,
      'field_membre_description' => $description,
      'field_membre_mot' => $donnees[5] ?? NULL,
      'field_membre_ordre' => $ordre[$instance],
      'status' => 1,
      'uid' => 1,
    ]);

    if ($photo && ($fichier = _gouvernorat_institutions_fichier('public://' . $photo))) {
      $membre->set('field_membre_photo', ['target_id' => $fichier->id(), 'alt' => "Portrait de $nom, $fonction"]);
    }
    elseif ($photo) {
      $sans_photo[] = "$nom ($photo introuvable)";
    }

    $membre->save();
  }

  $message = count($membres) . ' fiches « Membre » créées.';
  if ($sans_photo) {
    $message .= ' Photos manquantes : ' . implode(', ', $sans_photo) . '.';
  }
  return $message;
}

/**
 * Renvoie l'entité fichier d'une image déjà présente sur le serveur.
 *
 * Les photos ont été téléversées dans l'éditeur : la plupart ont déjà une
 * entité fichier. Les autres, déposées à la main, en reçoivent une.
 */
function _gouvernorat_institutions_fichier(string $uri): ?File {
  $fichiers = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  if ($fichiers) {
    $fichier = reset($fichiers);
  }
  elseif (file_exists($uri)) {
    $fichier = File::create(['uri' => $uri, 'uid' => 1]);
  }
  else {
    return NULL;
  }
  $fichier->setPermanent();
  $fichier->save();
  return $fichier;
}
