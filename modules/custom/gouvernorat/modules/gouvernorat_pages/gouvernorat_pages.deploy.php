<?php

/**
 * @file
 * Reprise, mot pour mot, des textes écrits dans les gabarits du thème.
 *
 * À lancer une fois, après l'import de la configuration :
 *   vendor/bin/drush deploy:hook -y
 * Une page déjà présente à la même adresse n'est pas touchée.
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Crée une section (paragraphe) et ses éléments.
 */
function _gouvernorat_pages_section(string $type, array $valeurs, array $elements = []): Paragraph {
  if ($elements) {
    $valeurs['field_sec_elements'] = array_map(fn($e) => Paragraph::create($e), $elements);
  }
  return Paragraph::create(['type' => $type] + $valeurs);
}

/**
 * Copie une image du thème dans les fichiers du site, pour la rendre
 * remplaçable depuis le formulaire de la page.
 */
function _gouvernorat_pages_image(string $fichier, string $alt): ?array {
  $source = \Drupal::service('extension.list.theme')->getPath('gouvernoratkin') . '/assets/images/' . $fichier;
  if (!file_exists($source)) {
    return NULL;
  }
  $systeme = \Drupal::service('file_system');
  $dossier = 'public://pages';
  $systeme->prepareDirectory($dossier, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $uri = $systeme->copy($source, $dossier . '/' . $fichier, FileExists::Rename);
  $file = File::create(['uri' => $uri, 'status' => 1]);
  $file->save();
  [$largeur, $hauteur] = @getimagesize($systeme->realpath($uri)) ?: [NULL, NULL];
  return ['target_id' => $file->id(), 'alt' => $alt, 'width' => $largeur, 'height' => $hauteur];
}

/**
 * Crée une page de base à une adresse donnée, si elle n'existe pas déjà.
 *
 * @return string
 *   Message pour le journal du déploiement.
 */
function _gouvernorat_pages_creer(string $adresse, array $valeurs, array $sections): string {
  $alias = \Drupal::service('path_alias.repository')->lookupByAlias($adresse, 'fr')
    ?? \Drupal::service('path_alias.repository')->lookupByAlias($adresse, 'und');
  if ($alias && preg_match('#^/node/\d+$#', $alias['path'])) {
    return "Une page existe déjà à l'adresse $adresse : rien à faire.";
  }

  $node = Node::create([
    'type' => 'page',
    'status' => 1,
    'uid' => 1,
    'langcode' => 'fr',
    'field_page_sections' => $sections,
    // Adresse fixe, sans motif automatique : elle doit rester celle d'avant.
    'path' => ['alias' => $adresse, 'pathauto' => 0],
  ] + $valeurs);
  $node->save();
  return "Page « {$node->label()} » créée à l'adresse $adresse (contenu {$node->id()}).";
}

/**
 * Page « À propos » : reprise du gabarit page--a-propos.html.twig.
 */
function gouvernorat_pages_deploy_a_propos() {
  $sections = [];

  $sections[] = _gouvernorat_pages_section('sec_texte', [
    'field_sec_surtitre' => 'Notre rôle',
    'field_sec_titre' => 'Qui sommes-nous ?',
    'field_sec_chapo' => "Nous œuvrons chaque jour pour améliorer la qualité de vie des Kinoises et des Kinois, en transformant Kinshasa en une métropole moderne, dynamique et accueillante, tout en préservant son riche patrimoine culturel et historique.",
    'field_sec_corps' => ['format' => 'basic_html', 'value' => "<p>En tant qu'autorité administrative de la Ville-Province, le Gouvernorat de Kinshasa coordonne l'ensemble des services publics et met en œuvre les politiques de développement urbain. Notre action s'articule autour de cinq axes prioritaires :</p>\n<ul>\n<li>Développement des infrastructures urbaines</li>\n<li>Amélioration des services sociaux de base</li>\n<li>Gestion durable de l'environnement</li>\n<li>Promotion de l'entreprenariat local</li>\n<li>Renforcement de la gouvernance participative</li>\n</ul>\n<p>Nous travaillons en étroite collaboration avec les différentes parties prenantes — les communes, la société civile, le secteur privé et les partenaires internationaux — afin d'assurer un développement harmonieux et inclusif de notre capitale.</p>"],
    'field_sec_image' => _gouvernorat_pages_image('apropos-institution.webp', "Séance de travail dans la salle du conseil de l'Hôtel de Ville de Kinshasa"),
    'field_sec_legende' => "Séance de travail à l'Hôtel de Ville, siège du Gouvernorat",
    'field_sec_liens' => [['uri' => 'internal:/executif-provincial', 'title' => "Découvrir l'exécutif provincial"]],
    'field_sec_sommaire' => 'Qui sommes-nous ?',
  ]);

  $sections[] = _gouvernorat_pages_section('sec_chiffres', [
    'field_sec_surtitre' => 'Kinshasa en bref',
    'field_sec_titre' => 'Quelques repères',
  ], [
    ['type' => 'chiffre_cle', 'field_chiffre_valeur' => '9 965 km²', 'field_chiffre_libelle' => 'de superficie, dont plus de 90 % de zones rurales et forestières'],
    ['type' => 'chiffre_cle', 'field_chiffre_valeur' => '17 M', 'field_chiffre_libelle' => "d'habitants en 2024, première agglomération francophone au monde"],
    ['type' => 'chiffre_cle', 'field_chiffre_valeur' => '24', 'field_chiffre_libelle' => 'communes réparties en 4 districts administratifs'],
    ['type' => 'chiffre_cle', 'field_chiffre_valeur' => 'Français', 'field_chiffre_libelle' => 'langue officielle, aux côtés du lingala parlé au quotidien'],
  ]);

  $sections[] = _gouvernorat_pages_section('sec_cartes', [
    'field_sec_surtitre' => 'Ce qui nous guide',
    'field_sec_titre' => 'Nos piliers fondamentaux',
    'field_sec_disposition' => 'piliers',
    'field_sec_sommaire' => 'Nos piliers',
  ], [
    ['type' => 'sec_carte', 'field_sec_titre' => 'Notre mission', 'field_sec_icone' => 'fas fa-bullseye', 'field_sec_corps' => ['format' => 'basic_html', 'value' => "<p>Servir la population kinoise avec intégrité et efficacité, en assurant une gouvernance transparente et participative pour le développement durable et équilibré de la capitale.</p>\n<p>Nous nous engageons à faciliter l'accès aux services essentiels et à créer un environnement propice à l'épanouissement de tous les citoyens.</p>"]],
    ['type' => 'sec_carte', 'field_sec_titre' => 'Notre vision', 'field_sec_icone' => 'fas fa-eye', 'field_sec_corps' => ['format' => 'basic_html', 'value' => "<p>Faire de Kinshasa une métropole moderne, inclusive et résiliente, reconnue comme un pôle de développement économique, culturel et technologique majeur en Afrique centrale.</p>\n<p>Nous aspirons à une ville où il fait bon vivre, dotée d'infrastructures de qualité et d'opportunités pour tous.</p>"]],
    ['type' => 'sec_carte', 'field_sec_titre' => 'Nos valeurs', 'field_sec_icone' => 'fas fa-hand-holding-heart', 'field_sec_corps' => ['format' => 'basic_html', 'value' => "<ul>\n<li><strong>Transparence</strong> — nous agissons avec intégrité et ouverture dans toutes nos décisions.</li>\n<li><strong>Innovation</strong> — nous encourageons les solutions créatives face aux défis urbains.</li>\n<li><strong>Proximité</strong> — nous restons à l'écoute des besoins de la population.</li>\n<li><strong>Excellence</strong> — nous visons constamment l'amélioration de nos services.</li>\n</ul>"]],
  ]);

  $sections[] = _gouvernorat_pages_section('sec_chronologie', [
    'field_sec_surtitre' => 'Depuis 1881',
    'field_sec_titre' => 'Notre évolution historique',
    'field_sec_chapo' => "De poste commercial à métropole de dix-sept millions d'habitants, Kinshasa a connu une évolution marquée par des étapes qui ont façonné la capitale et son administration.",
    'field_sec_sommaire' => 'Notre histoire',
  ], [
    ['type' => 'sec_etape', 'field_sec_date' => '1881', 'field_sec_titre' => 'Fondation de Léopoldville', 'field_sec_texte' => "Fondée par Henry Morton Stanley, la ville est d'abord un poste commercial et administratif sous le nom de Léopoldville."],
    ['type' => 'sec_etape', 'field_sec_date' => '1923', 'field_sec_titre' => 'Capitale du Congo belge', 'field_sec_texte' => 'Léopoldville devient la capitale administrative du Congo belge, ce qui stimule son développement urbain.'],
    ['type' => 'sec_etape', 'field_sec_date' => '1960', 'field_sec_titre' => 'Indépendance de la RDC', 'field_sec_texte' => 'La ville devient la capitale de la République Démocratique du Congo indépendante.'],
    ['type' => 'sec_etape', 'field_sec_date' => '1966', 'field_sec_titre' => 'Renommage en Kinshasa', 'field_sec_texte' => "La ville est rebaptisée Kinshasa, affirmant ainsi l'identité nationale."],
    ['type' => 'sec_etape', 'field_sec_date' => '2006', 'field_sec_titre' => 'Décentralisation', 'field_sec_texte' => "La mise en œuvre de la politique de décentralisation renforce l'autonomie administrative et financière du Gouvernorat."],
    ['type' => 'sec_etape', 'field_sec_date' => '2022', 'field_sec_titre' => 'Transformation numérique', 'field_sec_texte' => "Lancement du programme de digitalisation des services administratifs, pour améliorer l'efficacité et la transparence de la gouvernance locale."],
  ]);

  $sections[] = _gouvernorat_pages_section('sec_cartes', [
    'field_sec_surtitre' => 'Territoire',
    'field_sec_titre' => 'Organisation administrative',
    'field_sec_chapo' => 'Kinshasa est une ville-province unique, organisée en 24 communes regroupées en 4 districts.',
    'field_sec_disposition' => 'districts',
    'field_sec_sommaire' => 'Organisation',
  ], [
    ['type' => 'sec_carte', 'field_sec_titre' => 'District de la Lukunga', 'field_sec_compte' => '7 communes', 'field_sec_liste' => ['Barumbu', 'Gombe', 'Kinshasa', 'Kintambo', 'Lingwala', 'Mont Ngafula', 'Ngaliema']],
    ['type' => 'sec_carte', 'field_sec_titre' => 'District de la Funa', 'field_sec_compte' => '7 communes', 'field_sec_liste' => ['Bandalungwa', 'Bumbu', 'Kalamu', 'Kasa-Vubu', 'Makala', 'Ngiri-Ngiri', 'Selembao']],
    ['type' => 'sec_carte', 'field_sec_titre' => 'District du Mont-Amba', 'field_sec_compte' => '5 communes', 'field_sec_liste' => ['Kisenso', 'Lemba', 'Limete', 'Matete', 'Ngaba']],
    ['type' => 'sec_carte', 'field_sec_titre' => 'District de la Tshangu', 'field_sec_compte' => '5 communes', 'field_sec_liste' => ['Kimbanseke', 'Maluku', 'Masina', 'Ndjili', 'Nsele']],
  ]);

  $sections[] = _gouvernorat_pages_section('sec_cartes', [
    'field_sec_surtitre' => 'Rayonnement',
    'field_sec_titre' => 'Économie et culture',
    'field_sec_disposition' => 'cartes',
    'field_sec_sommaire' => 'Économie et culture',
  ], [
    ['type' => 'sec_carte', 'field_sec_titre' => 'Économie', 'field_sec_icone' => 'fas fa-chart-line', 'field_sec_corps' => ['format' => 'basic_html', 'value' => "<p>Kinshasa est le cœur économique de la RDC, avec des secteurs prospères comme le commerce, l'industrie légère (textile, agroalimentaire) et les services (banques, télécommunications). Le quartier de la Gombe, véritable centre d'affaires, abrite de nombreuses entreprises et institutions financières, faisant de la ville un pôle économique incontournable.</p>"]],
    ['type' => 'sec_carte', 'field_sec_titre' => 'Culture', 'field_sec_icone' => 'fas fa-music', 'field_sec_corps' => ['format' => 'basic_html', 'value' => "<p>Kinshasa est un carrefour culturel majeur en Afrique, berceau de la rumba congolaise et foyer de créativité. Avec des institutions prestigieuses comme l'Université de Kinshasa et le Stade des Martyrs, la ville rayonne par sa vitalité artistique et son riche patrimoine.</p>"]],
  ]);

  $sections[] = _gouvernorat_pages_section('sec_texte', [
    'field_sec_surtitre' => "Le siège de l'institution",
    'field_sec_titre' => "Le rôle de l'Hôtel de Ville",
    'field_sec_chapo' => "L'Hôtel de Ville de Kinshasa, siège du Gouvernorat, est au cœur de la gestion et du développement de la Ville-Province.",
    'field_sec_corps' => ['format' => 'basic_html', 'value' => "<h3>Responsabilités</h3>\n<ul>\n<li>Gestion des finances publiques de la Ville-Province</li>\n<li>Planification et aménagement urbains</li>\n<li>Coordination des 24 communes</li>\n<li>Services techniques : voirie, assainissement, transports</li>\n</ul>\n<h3>Initiatives récentes</h3>\n<ul>\n<li>Programme « Kinshasa ezo bonga » pour une ville moderne, salubre et sécurisée</li>\n<li>Mesures d'amélioration de l'assainissement et de la mobilité</li>\n<li>Régulation du secteur publicitaire et harmonisation de l'aménagement urbain</li>\n</ul>"],
    'field_sec_image' => _gouvernorat_pages_image('apropos-initiatives.webp', "Agents d'assainissement devant un camion aux couleurs du programme Kinshasa ezo bonga"),
    'field_sec_legende' => 'Le programme « Kinshasa ezo bonga » pour une ville moderne, salubre et sécurisée',
    'field_sec_sommaire' => 'Hôtel de Ville',
  ]);

  $sections[] = _gouvernorat_pages_section('sec_appel', [
    'field_sec_titre' => "Suivre l'action du Gouvernorat",
    'field_sec_texte' => "Vous souhaitez en savoir plus sur nos réalisations, nos projets en cours et nos perspectives d'avenir ? Les communiqués et les décisions de la Ville-Province sont publiés au fil de l'actualité.",
    'field_sec_liens' => [
      ['uri' => 'internal:/actualites', 'title' => 'Les actualités'],
      ['uri' => 'internal:/contact', 'title' => 'Nous contacter'],
    ],
  ]);

  return _gouvernorat_pages_creer('/a-propos', [
    'title' => 'À propos de la Ville-Province de Kinshasa',
    'field_page_surtitre' => 'Le Gouvernorat',
    'field_page_chapo' => "Institution dédiée à la gestion et au développement de la capitale de la République Démocratique du Congo, le Gouvernorat coordonne les services publics de la Ville-Province et conduit les politiques de développement urbain au service des Kinoises et des Kinois.",
  ], $sections);
}

/**
 * Pages « Culture et patrimoine » et « Structures et services de la ville ».
 *
 * Leur corps est déjà dans la base : un bloc de contenu « basic » affiché par
 * une vue (culture_et_patrimoine, structuresville). Il est recopié tel quel,
 * mise en forme comprise, dans le corps d'une page de base ; l'entête et
 * l'encadré final, écrits dans les gabarits, deviennent les champs et la
 * section « appel » de la page. Le bloc d'origine n'est pas supprimé.
 */
function gouvernorat_pages_deploy_culture_structures() {
  $pages = [
    [
      'vue' => 'culture_et_patrimoine',
      'adresse' => '/culture-et-patrimoine',
      'classe' => 'cu-corps',
      'title' => 'Culture et patrimoine',
      'field_page_surtitre' => 'Kinshasa vivante',
      'field_page_chapo' => "Berceau de la rumba congolaise et carrefour culturel de l'Afrique centrale, Kinshasa se raconte par sa musique, ses langues, ses festivals et le patrimoine bâti d'une capitale en mouvement.",
      'appel' => [
        'field_sec_titre' => 'Vivre la ville',
        'field_sec_texte' => "L'actualité du Gouvernorat et les images de la capitale prolongent cette découverte.",
        'field_sec_liens' => [
          ['uri' => 'internal:/actualites', 'title' => 'Les actualités'],
          ['uri' => 'internal:/all-gallery', 'title' => 'La galerie photo'],
        ],
      ],
    ],
    [
      'vue' => 'structuresville',
      'adresse' => '/structuresville',
      'classe' => 'st-corps',
      'title' => 'Structures et services de la ville',
      'field_page_surtitre' => 'Gouvernance',
      'field_page_chapo' => "Le Gouvernorat de Kinshasa s'appuie sur des institutions, des ministères et des régies pour assurer le fonctionnement, le développement et le bien-être de la capitale. Voici les services à votre disposition.",
      'appel' => [
        'field_sec_titre' => 'Poursuivre la découverte',
        'field_sec_texte' => "L'exécutif provincial et le cabinet du gouverneur complètent l'organisation du Gouvernorat de Kinshasa.",
        'field_sec_liens' => [
          ['uri' => 'internal:/executif-provincial', 'title' => "L'exécutif provincial"],
          ['uri' => 'internal:/cabinet-du-gouverneur', 'title' => 'Le cabinet du gouverneur'],
        ],
      ],
    ],
  ];

  $messages = [];
  foreach ($pages as $page) {
    // Le bloc affiché par la vue : son numéro est dans le filtre de la vue.
    $vue = \Drupal::config('views.view.' . $page['vue'])->get('display.default.display_options.filters.id.value.value');
    $bloc = $vue ? \Drupal::entityTypeManager()->getStorage('block_content')->load($vue) : NULL;
    $corps = $bloc && $bloc->hasField('body') && !$bloc->get('body')->isEmpty() ? (string) $bloc->get('body')->value : '';
    if ($corps === '') {
      $messages[] = "Bloc de la vue {$page['vue']} introuvable ou vide : page {$page['adresse']} créée sans corps.";
    }

    $messages[] = _gouvernorat_pages_creer($page['adresse'], [
      'title' => $page['title'],
      'field_page_surtitre' => $page['field_page_surtitre'],
      'field_page_chapo' => $page['field_page_chapo'],
      // Le balisage d'origine (cartes, sections) est gardé tel quel : il est
      // enveloppé de la classe que ses styles attendent, au format HTML
      // complet pour qu'aucune classe ne soit retirée.
      'body' => $corps !== '' ? ['value' => '<div class="' . $page['classe'] . '">' . "\n" . $corps . "\n</div>", 'format' => 'full_html'] : NULL,
    ], [_gouvernorat_pages_section('sec_appel', $page['appel'])]);
  }
  return implode(' ', $messages);
}

/**
 * Retire du pied de page tout ce qui parle d'abonnement à la newsletter.
 *
 * La partie (hérité du thème d'origine) est écrite dans un bloc de contenu :
 * titre « S'abonner », phrase « Abonnez-vous à notre Newsletter », champ
 * « Votre adresse de courriel » et bouton « Abonnez-vous ». Sont retirés :
 * les formulaires d'abonnement, puis tout élément dont le texte ne parle que
 * d'abonnement. Le reste du bloc (liens, réseaux sociaux…) est conservé ;
 * l'ancienne version reste dans les révisions du bloc. Si le bloc devient
 * vide, sa colonne disparaît du pied de page (gabarit du thème).
 */
function gouvernorat_pages_deploy_retrait_newsletter(): string {
  $motif = "/newsletter|abonn|abonez|lettre d.information|inscri|adresse de courriel|your email|subscribe/iu";
  $stockage = \Drupal::entityTypeManager()->getStorage('block_content');
  $ids = [];
  foreach (['newsletter', 'abonn', 'Abonn', 'abonez', 'Abonez', 'subscribe'] as $mot) {
    $ids += \Drupal::entityQuery('block_content')
      ->accessCheck(FALSE)
      ->condition('body.value', $mot, 'CONTAINS')
      ->execute();
  }
  $rapport = [];
  foreach ($stockage->loadMultiple($ids) as $bloc) {
    $dom = \Drupal\Component\Utility\Html::load($bloc->get('body')->value);
    $xpath = new \DOMXPath($dom);
    $retires = [];

    // 1. Formulaires d'abonnement (champ de courriel).
    foreach (iterator_to_array($xpath->query('//form[contains(@class, "newsletter") or .//input[@type="email" or @name="EMAIL"]]')) as $formulaire) {
      $formulaire->parentNode->removeChild($formulaire);
      $retires[] = 'formulaire';
    }

    // 2. Éléments dont tout le texte parle d'abonnement, sans lien ni image
    // (les plus englobants d'abord).
    $ne_parle_que_d_abonnement = function (\DOMNode $element) use ($xpath, $motif): bool {
      if ($xpath->query('.//a|.//img|.//iframe|.//video', $element)->length > 0) {
        return FALSE;
      }
      $trouve = FALSE;
      foreach ($xpath->query('.//text()', $element) as $texte) {
        $valeur = trim($texte->nodeValue);
        if ($valeur === '') {
          continue;
        }
        if (!preg_match($motif, $valeur)) {
          return FALSE;
        }
        $trouve = TRUE;
      }
      return $trouve;
    };
    foreach (iterator_to_array($xpath->query('//body//*')) as $element) {
      // Déjà retiré avec un parent ?
      if (!$element->parentNode || $xpath->query('ancestor::body', $element)->length === 0) {
        continue;
      }
      if ($ne_parle_que_d_abonnement($element)) {
        $retires[] = '« ' . mb_substr(trim(preg_replace('/\s+/u', ' ', $element->textContent)), 0, 80) . ' »';
        $element->parentNode->removeChild($element);
      }
    }

    // 3. Conteneurs devenus vides.
    foreach (array_reverse(iterator_to_array($xpath->query('//body//div|//body//section'))) as $element) {
      if ($element->parentNode && trim($element->textContent) === '' && $xpath->query('.//img|.//a|.//iframe|.//video|.//i', $element)->length === 0) {
        $element->parentNode->removeChild($element);
      }
    }

    if ($retires) {
      $bloc->get('body')->value = \Drupal\Component\Utility\Html::serialize($dom);
      $bloc->setNewRevision(TRUE);
      $bloc->setRevisionLogMessage("Partie « S'abonner » (newsletter) retirée.");
      $bloc->save();
      $rapport[] = $bloc->label() . ' : ' . implode(', ', $retires);
    }
  }
  return $rapport
    ? 'Abonnement à la newsletter retiré de ' . implode(' ; ', $rapport) . '. Ancienne version dans les révisions du bloc.'
    : "Aucune mention d'abonnement trouvée dans les blocs de contenu.";
}
