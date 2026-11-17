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
      'field_chiffres_source' => '',
      'reusable' => TRUE,
    ])->save();
    $messages[] = 'Bloc « Chiffres clés » créé.';
  }

  return $messages ? implode(' ', $messages) : 'Les blocs de l\'accueil existaient déjà : rien à faire.';
}

/**
 * Crée une carte (paragraphe sec_carte) pour l'accueil.
 */
function _gouvernorat_accueil_carte(string $icone, string $titre, string $texte, ?array $lien = NULL, ?string $couleur = NULL): Paragraph {
  $valeurs = [
    'type' => 'sec_carte',
    'field_sec_icone' => $icone,
    'field_sec_titre' => $titre,
    'field_sec_corps' => ['value' => '<p>' . $texte . '</p>', 'format' => 'basic_html'],
  ];
  if ($lien) {
    $valeurs['field_sec_liens'] = [$lien];
  }
  if ($couleur) {
    $valeurs['field_sec_couleur'] = $couleur;
  }
  $carte = Paragraph::create($valeurs);
  $carte->save();
  return $carte;
}

/**
 * Crée une section (paragraphe sec_accueil) de l'accueil.
 */
function _gouvernorat_accueil_section(string $cle, array $valeurs, array $cartes = []): Paragraph {
  $section = Paragraph::create(['type' => 'sec_accueil', 'field_sec_cle' => $cle] + $valeurs);
  if ($cartes) {
    $section->set('field_sec_elements', $cartes);
  }
  $section->save();
  return $section;
}

/**
 * Recopie les autres textes de l'accueil dans le bloc « Page d'accueil ».
 *
 * Bandeau, accès rapide, titres des sections, À propos, démarches les plus
 * demandées et appel final : les textes qui étaient écrits dans
 * page--front.html.twig, mot pour mot, pour que l'accueil reste identique.
 * Si un bloc de ce type existe déjà, rien n'est fait.
 */
function gouvernorat_accueil_deploy_sections_accueil(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  if ($storage->loadByProperties(['type' => 'page_accueil'])) {
    return "Le bloc « Page d'accueil » existe déjà : rien à faire.";
  }
  $html = fn (string ...$paragraphes): array => [
    'value' => '<p>' . implode('</p><p>', $paragraphes) . '</p>',
    'format' => 'basic_html',
  ];

  $sections = [];

  $sections[] = _gouvernorat_accueil_section('bandeau', [
    'field_sec_surtitre' => 'Bienvenue à',
    'field_sec_titre' => 'Kinshasa',
    'field_sec_corps' => $html("Capitale de la République Démocratique du Congo, la Ville-Province se réinvente chaque jour, mêlant tradition et modernité pour construire une ville tournée vers l'avenir."),
    'field_sec_liens' => [
      ['uri' => 'internal:/services-aux-citoyens', 'title' => 'Vos démarches administratives'],
      ['uri' => 'internal:/actualites', 'title' => 'Actualités du Gouvernorat'],
    ],
  ]);

  $sections[] = _gouvernorat_accueil_section('acces', [
    'field_sec_titre' => 'Accéder aux rubriques du site',
  ], [
    _gouvernorat_accueil_carte('fas fa-hands-helping', 'Services aux citoyens', 'Démarches, pièces à fournir et lieux de dépôt, expliqués pas à pas.', ['uri' => 'internal:/services-aux-citoyens', 'title' => 'Services aux citoyens'], 'bleu'),
    _gouvernorat_accueil_carte('fas fa-newspaper', 'Actualités', 'Communiqués, décisions et annonces officielles du Gouvernorat.', ['uri' => 'internal:/actualites', 'title' => 'Actualités'], 'rouge'),
    _gouvernorat_accueil_carte('fas fa-landmark', 'Gouvernance', "L'exécutif provincial, le cabinet du gouverneur et les structures de la ville.", ['uri' => 'internal:/executif-provincial', 'title' => 'Gouvernance'], 'vert'),
    _gouvernorat_accueil_carte('fas fa-monument', 'Culture et patrimoine', "L'histoire, les lieux et la vie culturelle de la capitale.", ['uri' => 'internal:/culture-et-patrimoine', 'title' => 'Culture et patrimoine'], 'jaune'),
  ]);

  $sections[] = _gouvernorat_accueil_section('actualites', [
    'field_sec_surtitre' => 'Vie de la ville',
    'field_sec_titre' => 'Dernières nouvelles de Kinshasa',
    'field_sec_liens' => [['uri' => 'internal:/actualites', 'title' => 'Toutes les actualités']],
  ]);

  $sections[] = _gouvernorat_accueil_section('videos', [
    'field_sec_surtitre' => 'En vidéo',
    'field_sec_titre' => 'Le Gouvernorat en vidéo',
    'field_sec_liens' => [
      ['uri' => 'https://www.youtube.com/@GouvernoratdeKinshasa', 'title' => 'Voir la chaîne'],
      ['uri' => 'internal:/videos', 'title' => 'Toutes les vidéos'],
    ],
  ]);

  $sections[] = _gouvernorat_accueil_section('apropos', [
    'field_sec_surtitre' => 'À propos',
    'field_sec_titre' => 'Une capitale au service de ses habitants',
    'field_sec_corps' => $html(
      "Située sur la rive sud du majestueux fleuve Congo, Kinshasa est une métropole vibrante de plus de dix-sept millions d'habitants, à la fois capitale politique, cœur économique et pôle culturel de la République Démocratique du Congo.",
      "Le Gouvernorat conduit la gestion et le développement de la Ville-Province avec une ambition simple : <strong>améliorer la qualité de vie des Kinoises et des Kinois</strong>, en transformant la ville en un pôle économique, culturel et technologique de premier plan en Afrique centrale."
    ),
    'field_sec_liens' => [['uri' => 'internal:/a-propos', 'title' => 'En savoir plus']],
    'field_sec_legende' => "Kinshasa, métropole de plus de dix-sept millions d'habitants",
  ], [
    _gouvernorat_accueil_carte('fas fa-bullseye', 'Notre mission', 'Assurer une gouvernance transparente et participative pour un développement durable et équilibré de Kinshasa, tout en servant la population avec intégrité et efficacité.'),
    _gouvernorat_accueil_carte('fas fa-eye', 'Notre vision', 'Faire de Kinshasa une métropole moderne, inclusive et résiliente, reconnue comme un pôle de développement économique, culturel et technologique africain.'),
    _gouvernorat_accueil_carte('fas fa-hand-holding-heart', 'Nos valeurs', 'Transparence, innovation, proximité, excellence et responsabilité guident chacune des actions menées pour le bien-être des citoyens et le rayonnement de la ville.'),
  ]);

  $sections[] = _gouvernorat_accueil_section('chiffres', [
    'field_sec_surtitre' => 'Kinshasa en bref',
    'field_sec_titre' => 'Quelques repères',
  ]);

  $sections[] = _gouvernorat_accueil_section('galerie', [
    'field_sec_surtitre' => 'En images',
    'field_sec_titre' => 'Galerie photo du Gouvernorat',
    'field_sec_liens' => [['uri' => 'internal:/all-gallery', 'title' => 'Toute la galerie']],
  ]);

  $sections[] = _gouvernorat_accueil_section('demarches', [
    'field_sec_surtitre' => 'Kinshasa à votre service',
    'field_sec_titre' => 'Les démarches les plus demandées',
  ], [
    _gouvernorat_accueil_carte('fas fa-users', 'Famille, étapes de la vie et vie sociale', 'Naissance, mariage, décès, état civil : les démarches qui accompagnent la vie des ménages kinois.', ['uri' => 'internal:/services-aux-citoyens/19', 'title' => 'Voir les démarches'], 'bleu'),
    _gouvernorat_accueil_carte('fas fa-home', 'Habitat et logement', 'Autorisations de bâtir, titres et occupation : les démarches liées au logement et au foncier.', ['uri' => 'internal:/services-aux-citoyens/25', 'title' => 'Voir les démarches'], 'rouge'),
    _gouvernorat_accueil_carte('fas fa-futbol', 'Loisirs et sports', 'Manifestations, équipements sportifs et vie associative : autorisations et accompagnement.', ['uri' => 'internal:/services-aux-citoyens/22', 'title' => 'Voir les démarches'], 'vert'),
  ]);

  $sections[] = _gouvernorat_accueil_section('appel', [
    'field_sec_titre' => 'Une démarche à effectuer ?',
    'field_sec_corps' => $html("Conditions, pièces justificatives et lieux de dépôt : retrouvez l'ensemble des démarches proposées par les services de la Ville-Province."),
    'field_sec_liens' => [
      ['uri' => 'internal:/services-aux-citoyens', 'title' => 'Voir les démarches'],
      ['uri' => 'internal:/contact', 'title' => 'Nous contacter'],
    ],
  ]);

  $bloc = BlockContent::create([
    'type' => 'page_accueil',
    'info' => "Page d'accueil",
    'reusable' => TRUE,
    'field_accueil_sections' => $sections,
  ]);
  $bloc->save();
  return "Bloc « Page d'accueil » créé avec " . count($sections) . ' sections (Contenu › Blocs).';
}

/**
 * Recopie les textes des autres pages dans un bloc « Textes du site ».
 *
 * En-têtes des pages Actualités, Galerie, Vidéos, Services aux citoyens,
 * Contact, Exécutif provincial, Cabinet du gouverneur, Connexion et Mot de
 * passe oublié ; encadrés et « Poursuivre la découverte » ; mention du pied
 * de page. Les réseaux sociaux de l'accueil rejoignent le bloc « Page
 * d'accueil ». Textes repris mot pour mot des gabarits. Une partie déjà
 * présente dans un bloc n'est pas recréée.
 */
function gouvernorat_accueil_deploy_textes_autres_pages(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $existantes = function_exists('gouvernorat_accueil_sections') ? array_keys(gouvernorat_accueil_sections()) : [];
  $html = fn (string ...$paragraphes): array => [
    'value' => '<p>' . implode('</p><p>', $paragraphes) . '</p>',
    'format' => 'basic_html',
  ];
  $entete = fn (string $surtitre, string $titre, string $texte, array $liens = []): array => [
    'field_sec_surtitre' => $surtitre,
    'field_sec_titre' => $titre,
    'field_sec_corps' => $html($texte),
    'field_sec_liens' => $liens,
  ];

  $parties = [
    'entete_actualites' => $entete('Vie de la ville', 'Actualités du Gouvernorat', "Communiqués, décisions et annonces officielles de la Ville-Province de Kinshasa, publiés au fil de l'actualité de la capitale."),
    'entete_galerie' => $entete('En images', 'Galerie photo du Gouvernorat', 'Les activités et les événements de la Ville-Province de Kinshasa, racontés en images.'),
    'entete_videos' => $entete('En vidéo', 'Le Gouvernorat en vidéo', 'Événements, inaugurations, discours et reportages de la Ville-Province de Kinshasa, publiés sur la chaîne YouTube du Gouvernorat.', [
      ['uri' => 'https://www.youtube.com/@GouvernoratdeKinshasa', 'title' => 'Voir la chaîne YouTube'],
    ]),
    'entete_video' => ['field_sec_surtitre' => 'Vidéo'],
    'entete_services' => $entete('Vos démarches', 'Services aux citoyens', "Retrouvez l'ensemble des démarches et services proposés par le Gouvernorat de Kinshasa à la population kinoise : pièces à fournir, étapes et lieux de dépôt."),
    'services_aide' => [
      'field_sec_titre' => "Besoin d'aide ?",
      'field_sec_corps' => $html('Contactez nos agents pour vous accompagner dans vos démarches.'),
      'field_sec_liens' => [['uri' => 'internal:/contact', 'title' => 'Nous contacter']],
    ],
    'entete_contact' => $entete('Nous écrire', 'Contacter le Gouvernorat', 'Une question sur une démarche, une suggestion ou un signalement ? Écrivez-nous : votre message est transmis aux services du Gouvernorat de Kinshasa, qui vous répondent par courriel.'),
    'contact_coordonnees' => ['field_sec_titre' => 'Nos coordonnées'],
    'contact_demarche' => [
      'field_sec_titre' => 'Une démarche administrative ?',
      'field_sec_corps' => $html('Pièces à fournir, coûts et lieux de dépôt sont décrits dans les fiches des services aux citoyens.'),
      'field_sec_liens' => [['uri' => 'internal:/services-aux-citoyens', 'title' => 'Voir les services aux citoyens']],
    ],
    'contact_formulaire' => ['field_sec_titre' => 'Envoyer un message'],
    'entete_executif' => $entete('Gouvernance', "L'exécutif provincial", "Le gouverneur et les ministres provinciaux conduisent l'action de la Ville-Province de Kinshasa. Chacun porte un portefeuille et répond des politiques publiques menées dans son domaine auprès des Kinoises et des Kinois."),
    'suite_executif' => [
      'field_sec_titre' => 'Poursuivre la découverte',
      'field_sec_corps' => $html("Le cabinet du gouverneur et les structures de la Ville-Province complètent l'organisation de l'exécutif provincial."),
      'field_sec_liens' => [
        ['uri' => 'internal:/cabinet-du-gouverneur', 'title' => 'Le cabinet du gouverneur'],
        ['uri' => 'internal:/structuresville', 'title' => 'Les structures de la ville'],
      ],
    ],
    'entete_cabinet' => $entete('Gouvernance', 'Le cabinet du gouverneur', "Le cabinet assiste le gouverneur dans la conduite de l'action publique provinciale : préparation des décisions, coordination des services et suivi de leur mise en œuvre au bénéfice des Kinoises et des Kinois."),
    'cabinet_mot' => ['field_sec_titre' => 'Le mot du cabinet'],
    'suite_cabinet' => [
      'field_sec_titre' => 'Poursuivre la découverte',
      'field_sec_corps' => $html("L'exécutif provincial et les structures de la Ville-Province complètent l'organisation du Gouvernorat de Kinshasa."),
      'field_sec_liens' => [
        ['uri' => 'internal:/executif-provincial', 'title' => "L'exécutif provincial"],
        ['uri' => 'internal:/structuresville', 'title' => 'Les structures de la ville'],
      ],
    ],
    'entete_connexion' => $entete('Espace de la rédaction', 'Connexion', 'Espace réservé aux équipes du Gouvernorat qui publient et mettent à jour le site.'),
    'entete_mot_de_passe' => $entete('Espace de la rédaction', 'Mot de passe oublié', "Indiquez l'adresse de courriel ou le nom d'utilisateur de votre compte : un lien pour choisir un nouveau mot de passe vous sera envoyé."),
    'compte_aide' => [
      'field_sec_corps' => $html('Vous êtes citoyen et cherchez une information ou une démarche ?'),
      'field_sec_liens' => [
        ['uri' => 'internal:/services-aux-citoyens', 'title' => 'Services aux citoyens'],
        ['uri' => 'internal:/contact', 'title' => 'Nous écrire'],
      ],
    ],
    'pied_credit' => ['field_sec_corps' => $html('powered by Agence pour le Développement du Numérique de Kinshasa (APDNK)')],
  ];

  $sections = [];
  foreach ($parties as $cle => $valeurs) {
    if (!in_array($cle, $existantes, TRUE)) {
      $sections[] = _gouvernorat_accueil_section($cle, $valeurs);
    }
  }
  $messages = [];
  if ($sections) {
    $storage->create([
      'type' => 'page_accueil',
      'info' => 'Textes des autres pages',
      'reusable' => TRUE,
      'field_accueil_sections' => $sections,
    ])->save();
    $messages[] = "Bloc « Textes des autres pages » créé avec " . count($sections) . ' sections.';
  }

  // Réseaux sociaux de l'accueil : ajoutés au bloc « Page d'accueil ».
  if (!in_array('reseaux', $existantes, TRUE)) {
    $accueil = $storage->loadByProperties(['type' => 'page_accueil', 'info' => "Page d'accueil"]);
    if ($accueil = reset($accueil)) {
      $accueil->get('field_accueil_sections')->appendItem(_gouvernorat_accueil_section('reseaux', [
        'field_sec_surtitre' => 'Nous suivre',
        'field_sec_liens' => [
          ['uri' => 'https://x.com/gouvernoratk', 'title' => 'X'],
          ['uri' => 'https://www.facebook.com/GouvKinshasa', 'title' => 'Facebook'],
          ['uri' => 'https://www.instagram.com/gouvernoratk', 'title' => 'Instagram'],
          ['uri' => 'https://www.youtube.com/@GouvernoratdeKinshasa', 'title' => 'YouTube'],
          ['uri' => 'https://www.tiktok.com/@gouvernoratk', 'title' => 'TikTok'],
        ],
      ]));
      $accueil->setNewRevision(TRUE);
      $accueil->save();
      $messages[] = "Section « Réseaux sociaux » ajoutée au bloc « Page d'accueil ».";
    }
  }
  drupal_static_reset('gouvernorat_accueil_sections');
  return $messages ? implode(' ', $messages) : 'Toutes les parties existent déjà : rien à faire.';
}
