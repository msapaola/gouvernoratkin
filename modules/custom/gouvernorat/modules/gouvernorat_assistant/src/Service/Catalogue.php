<?php

namespace Drupal\gouvernorat_assistant\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Catalogue des pages publiées du site, et recherche dans ce catalogue.
 *
 * L'assistant ne connaît du site que ce que ce service lui donne :
 * - la liste de toutes les pages utiles (titre, adresse, résumé), envoyée à
 *   chaque question pour qu'il puisse orienter le visiteur ;
 * - le texte complet des quelques pages les plus proches de la question,
 *   pour qu'il réponde sur le fond (pièces à fournir, montants, lieux…).
 *
 * Le catalogue est mis en cache et recalculé dès qu'un contenu ou une
 * rubrique change. Seuls les contenus publiés y figurent.
 */
class Catalogue {

  /**
   * Types de contenu repris, avec leur libellé et le nombre maximal retenu
   * (les plus récents d'abord ; NULL = tous).
   */
  const TYPES = [
    'service_au_citoyen' => ['Démarche', NULL],
    'page' => ['Page', NULL],
    'page_a_propos' => ['Page', NULL],
    'membre' => ['Fiche officielle d\'un membre (exécutif provincial ou cabinet)', NULL],
    'article' => ['Actualité', 120],
    'evenement' => ['Événement', 30],
    'video' => ['Vidéo', 30],
  ];

  /**
   * Pages principales du site, ajoutées si elles existent.
   */
  const PAGES = [
    '/' => ['Accueil du site', "Page d'accueil du Gouvernorat de Kinshasa."],
    '/services-aux-citoyens' => ['Services aux citoyens', 'Toutes les démarches administratives, classées par rubrique, avec un moteur de recherche.'],
    '/actualites' => ['Actualités', 'Communiqués, décisions et annonces officielles du Gouvernorat.'],
    '/videos' => ['Vidéos', 'Vidéos officielles du Gouvernorat (chaîne YouTube).'],
    '/all-gallery' => ['Galerie', 'Photos des activités du Gouvernorat.'],
    '/executif-provincial' => ['Exécutif provincial', 'Le gouverneur, le vice-gouverneur et les ministres provinciaux.'],
    '/cabinet-du-gouverneur' => ['Cabinet du gouverneur', 'Les membres du cabinet du gouverneur.'],
    '/structuresville' => ['Structures et services de la ville', 'Les structures et services rattachés à la Ville-Province.'],
    '/culture-et-patrimoine' => ['Culture et patrimoine', "L'histoire, les lieux et la vie culturelle de la capitale."],
    '/contact' => ['Contact', 'Formulaire pour écrire au Gouvernorat, adresse et coordonnées.'],
  ];

  /**
   * Pages principales dont le texte affiché est repris dans le catalogue
   * (les autres sont des listes : actualités, démarches, vidéos…).
   */
  const PAGES_A_LIRE = ['/', '/executif-provincial', '/cabinet-du-gouverneur', '/structuresville', '/culture-et-patrimoine'];

  /**
   * Types de contenu de référence : quand ils répondent à la question, ils
   * passent devant les actualités.
   */
  const TYPES_DE_REFERENCE = ['Page principale', 'Page', 'Démarche', "Fiche officielle d'un membre (exécutif provincial ou cabinet)", 'Rubrique des services aux citoyens'];

  /**
   * Mots trop courants pour aider à la recherche.
   */
  const MOTS_VIDES = ['les', 'des', 'une', 'pour', 'que', 'qui', 'est', 'sur', 'dans', 'par', 'avec', 'pas', 'plus', 'son', 'ses', 'aux', 'mon', 'mes', 'votre', 'vos', 'nous', 'vous', 'comment', 'quoi', 'quel', 'quelle', 'quels', 'quelles', 'faire', 'faut', 'peux', 'peut', 'puis', 'avoir', 'etre', 'cette', 'ces', 'tout', 'tous', 'bonjour', 'merci', 'svp', 'ville', 'kinshasa', 'gouvernorat', 'veux', 'voudrais', 'aimerais', 'besoin', 'savoir', 'obtenir', 'donne', 'moi', 'leur', 'elle', 'ils', 'sont', 'ont', 'aussi', 'mais', 'ou', 'et', 'je', 'il', 'la', 'le', 'du', 'de', 'un'];

  const CLE_CACHE = 'gouvernorat_assistant:catalogue';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $base,
    protected CacheBackendInterface $cache,
    protected PathValidatorInterface $validateur,
  ) {}

  /**
   * Toutes les entrées du catalogue.
   *
   * @return array[]
   *   Chaque entrée : titre, url, type, rubrique, resume, texte (texte brut
   *   complet, pour la recherche et les extraits), cle (texte de recherche
   *   normalisé).
   */
  public function entrees(): array {
    if ($cache = $this->cache->get(self::CLE_CACHE)) {
      return $cache->data;
    }

    $entrees = [];
    foreach (self::PAGES as $chemin => [$titre, $resume]) {
      $url = $chemin === '/' ? Url::fromRoute('<front>') : $this->validateur->getUrlIfValidWithoutAccessCheck($chemin);
      if ($url) {
        // Pages institutionnelles : leur texte est écrit dans les gabarits du
        // thème, pas dans la base ; il est lu tel qu'un visiteur le voit.
        $texte = in_array($chemin, self::PAGES_A_LIRE, TRUE) ? $this->texteAffiche($chemin) : '';
        $entrees[] = $this->entree($titre, $url->toString(), 'Page principale', '', $resume, trim($resume . "\n" . $texte));
      }
    }

    $entrees = array_merge($entrees, $this->rubriques(), $this->contenus());

    // block_content_list : le texte des pages institutionnelles comprend des
    // blocs modifiables (chiffres clés, mot du gouverneur, coordonnées).
    $this->cache->set(self::CLE_CACHE, $entrees, Cache::PERMANENT, ['node_list', 'taxonomy_term_list', 'block_content_list']);
    return $entrees;
  }

  /**
   * Catalogue mis en forme pour le modèle : une ligne par page.
   */
  public function texte(): string {
    $lignes = [];
    foreach ($this->entrees() as $e) {
      $ligne = '- [' . $e['titre'] . '](' . $e['url'] . ') — ' . $e['type'];
      if ($e['rubrique'] !== '') {
        $ligne .= ' — ' . $e['rubrique'];
      }
      if ($e['resume'] !== '') {
        $ligne .= ' : ' . $e['resume'];
      }
      $lignes[] = $ligne;
    }
    return implode("\n", $lignes);
  }

  /**
   * Adresses de toutes les pages du catalogue.
   *
   * @return array
   *   Les chemins en clés.
   */
  public function adresses(): array {
    return array_fill_keys(array_map('rawurldecode', array_column($this->entrees(), 'url')), TRUE);
  }

  /**
   * Les pages les plus proches d'une question.
   *
   * Recherche par mots : un mot trouvé dans le titre compte plus que dans
   * la rubrique, qui compte plus que dans le texte.
   *
   * @return array[]
   *   Au plus $nombre entrées, la plus pertinente d'abord.
   */
  public function rechercher(string $question, int $nombre = 5): array {
    $mots = $this->mots($question);
    if (!$mots) {
      return [];
    }

    // Question sur une personne en fonction : les fiches des membres font
    // foi, les actualités (datées, parfois reprises de la presse) passent
    // après.
    $personne = (bool) preg_match('/\b(qui|ministre|gouverneur|vice|nom|appel\w*|directeur|directrice|cabinet|secretaire|conseiller|responsable|titulaire|charge)\b/', $this->normaliser($question));

    // Règles de classement :
    // 1. score de base : mots de la question trouvés dans le titre (6), la
    //    rubrique ou la fonction (5), le texte (1 par occurrence, 4 au plus) ;
    // 2. une page de référence (page institutionnelle, page, démarche, fiche
    //    d'un membre, rubrique) passe devant les actualités, MAIS seulement
    //    si elle répond à la question : elle doit contenir au moins la moitié
    //    des mots importants de la question. Sinon elle garde son score de
    //    base, et une actualité plus pertinente reste devant elle ;
    // 3. les actualités pertinentes restent utilisables, en second rang.
    $seuil = max(1, (int) ceil(count($mots) / 2));
    $scores = [];
    foreach ($this->entrees() as $i => $e) {
      $score = 0;
      $trouves = 0;
      foreach ($mots as $mot) {
        $present = FALSE;
        if (str_contains($e['cle_titre'], $mot)) {
          $score += 6;
          $present = TRUE;
        }
        if (str_contains($e['cle_rubrique'], $mot)) {
          $score += 5;
          $present = TRUE;
        }
        $occurrences = substr_count($e['cle'], $mot);
        $score += min(4, $occurrences);
        if ($present || $occurrences > 0) {
          $trouves++;
        }
      }
      $repond = $trouves >= $seuil;
      if ($score > 0 && $repond) {
        if (in_array($e['type'], self::TYPES_DE_REFERENCE, TRUE)) {
          $score = (int) round($score * 1.5) + 8;
        }
        elseif ($e['type'] === self::TYPES['article'][0] || $e['type'] === self::TYPES['evenement'][0]) {
          $score = (int) round($score * 0.8);
        }
      }
      if ($score > 0 && $personne) {
        if ($e['type'] === self::TYPES['membre'][0]) {
          $score = $score * 2 + 10;
        }
        elseif ($e['type'] === self::TYPES['article'][0]) {
          $score = intdiv($score, 2);
        }
      }
      if ($score > 0) {
        $scores[$i] = $score;
      }
    }

    arsort($scores);
    $entrees = $this->entrees();
    return array_map(fn($i) => $entrees[$i], array_slice(array_keys($scores), 0, $nombre));
  }

  /**
   * Recherche en direct dans tous les contenus publiés (outil de l'assistant).
   *
   * Contrairement au catalogue, sans limite de date ni de nombre : un
   * article ancien est trouvé. La requête lit la base au moment de la
   * question ; seuls les contenus publiés sont concernés.
   *
   * @param string $requete
   *   Mots-clés choisis par l'assistant.
   * @param string $type
   *   « tout », « demarche », « actualite », « page » ou « membre ».
   *
   * @return array[]
   *   Au plus 8 résultats : titre, url, type, date, resume.
   */
  public function rechercherDansLeSite(string $requete, string $type = 'tout'): array {
    $mots = array_slice($this->mots($requete), 0, 6);
    if (!$mots) {
      return [];
    }
    $types = [
      'demarche' => ['service_au_citoyen'],
      'actualite' => ['article', 'evenement'],
      'page' => ['page', 'page_a_propos'],
      'membre' => ['membre'],
    ][$type] ?? array_keys(self::TYPES);

    $sql = $this->base->select('node_field_data', 'n');
    $sql->leftJoin('node__body', 'b', 'b.entity_id = n.nid AND b.deleted = 0');
    $sql->fields('n', ['nid', 'title', 'type', 'created']);
    $sql->addField('b', 'body_value', 'corps');
    $sql->condition('n.status', 1)
      ->condition('n.default_langcode', 1)
      ->condition('n.type', $types, 'IN');
    $ou = $sql->orConditionGroup();
    foreach ($mots as $mot) {
      $motif = '%' . $this->base->escapeLike($mot) . '%';
      $ou->condition('n.title', $motif, 'LIKE')->condition('b.body_value', $motif, 'LIKE');
    }
    $sql->condition($ou)->orderBy('n.created', 'DESC')->range(0, 300);

    $resultats = [];
    foreach ($sql->execute() as $ligne) {
      $titre = $this->normaliser($ligne->title);
      $corps = $this->normaliser($this->texteBrut((string) $ligne->corps));
      $score = 0;
      foreach ($mots as $mot) {
        $score += (str_contains($titre, $mot) ? 6 : 0) + min(4, substr_count($corps, $mot));
      }
      if ($score > 0) {
        $resultats[] = [$score, $ligne];
      }
    }
    usort($resultats, fn($a, $b) => $b[0] <=> $a[0] ?: $b[1]->created <=> $a[1]->created);

    $libelles = array_map(fn($t) => $t[0], self::TYPES);
    $sortie = [];
    $vues = [];

    // D'abord les pages de référence qui répondent (pages institutionnelles
    // comprises, dont le texte n'est pas dans la base), selon le même
    // classement que les pages jointes à la question.
    if ($type === 'tout' || $type === 'page') {
      foreach ($this->rechercher($requete, 4) as $e) {
        if (in_array($e['type'], self::TYPES_DE_REFERENCE, TRUE)) {
          $sortie[] = ['titre' => $e['titre'], 'url' => $e['url'], 'type' => $e['type'], 'date' => '', 'resume' => Unicode::truncate($e['texte'], 220, TRUE, TRUE)];
          $vues[$e['url']] = TRUE;
        }
      }
    }

    foreach ($resultats as [, $ligne]) {
      if (count($sortie) >= 8) {
        break;
      }
      $url = Url::fromRoute('entity.node.canonical', ['node' => $ligne->nid])->toString();
      if (isset($vues[$url])) {
        continue;
      }
      $sortie[] = [
        'titre' => $ligne->title,
        'url' => $url,
        'type' => $libelles[$ligne->type] ?? $ligne->type,
        'date' => date('d/m/Y', (int) $ligne->created),
        'resume' => Unicode::truncate($this->texteBrut((string) $ligne->corps), 220, TRUE, TRUE),
      ];
    }
    return $sortie;
  }

  /**
   * Texte complet d'une page publiée du site (outil de l'assistant).
   *
   * @param string $chemin
   *   Adresse de la page sur le site, par exemple /demarches/….
   *
   * @return array|null
   *   titre, url, type, date, texte ; NULL si la page n'existe pas ou n'est
   *   pas publiée.
   */
  public function lirePage(string $chemin): ?array {
    $chemin = '/' . ltrim(rawurldecode((string) strtok(trim($chemin), '?#')), '/');
    if (str_contains($chemin, '\\') || str_contains($chemin, '..')) {
      return NULL;
    }

    // Une rubrique des services : sa présentation et la liste de ses
    // démarches.
    if (preg_match('#^/services-aux-citoyens/(\d+)$#', $chemin, $m)) {
      return $this->lireRubrique((int) $m[1]);
    }

    $systeme = \Drupal::service('path_alias.manager')->getPathByAlias($chemin);
    if (preg_match('#^/node/(\d+)$#', $systeme, $m)) {
      $node = $this->entityTypeManager->getStorage('node')->load($m[1]);
      if (!$node instanceof NodeInterface || !$node->isPublished() || !isset(self::TYPES[$node->bundle()])) {
        return NULL;
      }
      $entree = $this->entreeContenu($node, self::TYPES[$node->bundle()][0]);
      return [
        'titre' => $entree['titre'],
        'url' => $entree['url'],
        'type' => $entree['type'] . ($entree['rubrique'] !== '' ? ' — ' . $entree['rubrique'] : ''),
        'date' => date('d/m/Y', $node->getCreatedTime()),
        'texte' => Unicode::truncate($this->texteBrut(implode("\n\n", array_filter([
          $node->hasField('field_membre_fonction') ? $node->get('field_membre_fonction')->value : '',
          $node->hasField('field_membre_description') ? $node->get('field_membre_description')->value : '',
          $node->hasField('body') ? $node->get('body')->value : '',
          $node->hasField('field_membre_biographie') ? $node->get('field_membre_biographie')->value : '',
        ]))), 15000, TRUE, TRUE),
      ];
    }

    // Pages de l'exécutif et du cabinet : la liste des membres en fonction.
    $instances = [
      '/executif-provincial' => ['Exécutif provincial', ['gouvernorat', 'ministres', 'secretariat']],
      '/cabinet-du-gouverneur' => ['Cabinet du gouverneur', ['cabinet']],
    ];
    if (isset($instances[$chemin])) {
      [$titre, $valeurs] = $instances[$chemin];
      $stockage = $this->entityTypeManager->getStorage('node');
      $ids = $stockage->getQuery()->accessCheck(FALSE)
        ->condition('type', 'membre')->condition('status', 1)
        ->condition('field_membre_instance', $valeurs, 'IN')
        ->sort('field_membre_ordre')->sort('title')->execute();
      $lignes = [];
      foreach ($stockage->loadMultiple($ids) as $membre) {
        $lignes[] = '- [' . $membre->label() . '](' . $membre->toUrl()->toString() . ') : ' . $membre->get('field_membre_fonction')->value;
      }
      return ['titre' => $titre, 'url' => $chemin, 'type' => 'Page principale', 'date' => '', 'texte' => "Membres en fonction :\n" . implode("\n", $lignes)];
    }

    // Une page principale (liste, page institutionnelle) : sa description.
    foreach ($this->entrees() as $entree) {
      if (rawurldecode($entree['url']) === $chemin) {
        return ['titre' => $entree['titre'], 'url' => $entree['url'], 'type' => $entree['type'], 'date' => '', 'texte' => $entree['texte']];
      }
    }
    return NULL;
  }

  /**
   * Présentation d'une rubrique et liste de ses démarches publiées.
   */
  protected function lireRubrique(int $tid): ?array {
    $stockage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terme = $stockage->load($tid);
    if (!$terme || $terme->bundle() !== 'service_au_citoyen' || !$terme->isPublished()) {
      return NULL;
    }
    $tids = [$tid];
    foreach ($stockage->loadTree('service_au_citoyen', $tid, NULL, FALSE) as $enfant) {
      $tids[] = (int) $enfant->tid;
    }
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_au_citoyen')
      ->condition('status', 1)
      ->condition('field_service_au_citoyen', $tids, 'IN')
      ->sort('title')
      ->range(0, 80)
      ->execute();
    $lignes = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      $lignes[] = '- [' . $node->label() . '](' . $node->toUrl()->toString() . ')';
    }
    return [
      'titre' => $terme->label(),
      'url' => Url::fromUserInput('/services-aux-citoyens/' . $tid)->toString(),
      'type' => 'Rubrique des services aux citoyens',
      'date' => '',
      'texte' => trim($this->texteBrut((string) $terme->getDescription()) . "\n\nDémarches de cette rubrique :\n" . ($lignes ? implode("\n", $lignes) : 'aucune')),
    ];
  }

  /**
   * Texte visible d'une page du site, tel qu'un visiteur anonyme le voit.
   *
   * La page est construite par une sous-requête interne, sous l'identité
   * d'un visiteur anonyme ; seul le contenu principal (<main>) est gardé.
   * Les messages en attente de la personne connectée sont préservés. En
   * cas d'échec, le texte est vide et seule la description de la page sert.
   */
  protected function texteAffiche(string $chemin): string {
    $permutateur = \Drupal::service('account_switcher');
    $messager = \Drupal::messenger();
    $messages = $messager->all();
    $permutateur->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
    // Drupal retient, le temps d'une requête, si la page en cours est
    // l'accueil. Sans remise à zéro, après la lecture de l'accueil, toutes
    // les pages suivantes seraient construites avec le gabarit de l'accueil.
    // Le service est un décorateur (path_alias) qui enveloppe celui du
    // cœur : chacun garde sa propre mémoire, les deux sont remises à zéro.
    $oublierAccueil = static function () {
      $objet = \Drupal::service('path.matcher');
      for ($niveau = 0; $objet && $niveau < 5; $niveau++) {
        try {
          $objet = \Closure::bind(function () {
            if (property_exists($this, 'isCurrentFrontPage')) {
              $this->isCurrentFrontPage = NULL;
            }
            return property_exists($this, 'decorated') ? $this->decorated : NULL;
          }, $objet, get_class($objet))();
        }
        catch (\Throwable $e) {
          // Structure différente dans une future version de Drupal : sans
          // conséquence grave, seul le texte des pages en pâtirait.
          $objet = NULL;
        }
      }
    };
    $oublierAccueil();
    try {
      $courante = \Drupal::request();
      // Seule l'adresse est reprise : recopier les variables serveur de la
      // requête en cours ferait servir la page d'accueil pour toute adresse.
      $requete = \Symfony\Component\HttpFoundation\Request::create($courante->getSchemeAndHttpHost() . $courante->getBasePath() . $chemin, 'GET');
      $reponse = \Drupal::service('http_kernel')->handle($requete, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST, FALSE);
      $html = $reponse->getStatusCode() === 200 ? (string) $reponse->getContent() : '';
    }
    catch (\Throwable $e) {
      $html = '';
    }
    finally {
      $oublierAccueil();
      $permutateur->switchBack();
      $messager->deleteAll();
      foreach ($messages as $type => $liste) {
        foreach ($liste as $message) {
          $messager->addMessage($message, $type);
        }
      }
    }

    if ($html === '') {
      return '';
    }
    // Seul le texte propre de la page compte : les listes automatiques
    // (dernières actualités, vidéos, galerie, rendues par des vues) sont
    // retirées, sans quoi l'accueil « contiendrait » le titre de chaque
    // actualité récente et passerait devant l'article lui-même.
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new \DOMXPath($document);
    $principal = $xpath->query('//main')->item(0);
    if (!$principal) {
      return '';
    }
    $a_retirer = $xpath->query('.//script | .//style | .//noscript | .//svg | .//nav | .//form | .//*[contains(concat(" ", normalize-space(@class), " "), " views-element-container ")] | .//*[contains(concat(" ", normalize-space(@class), " "), " view ")]', $principal);
    foreach (iterator_to_array($a_retirer) as $noeud) {
      $noeud->parentNode?->removeChild($noeud);
    }
    $morceaux = [];
    foreach ($xpath->query('.//text()', $principal) as $texte) {
      $morceaux[] = $texte->nodeValue;
    }
    $texte = trim(preg_replace('/\s+/u', ' ', implode(' ', $morceaux)));
    return Unicode::truncate($texte, 8000, TRUE, TRUE);
  }

  /**
   * Rubriques publiées des services aux citoyens.
   */
  protected function rubriques(): array {
    $entrees = [];
    $stockage = $this->entityTypeManager->getStorage('taxonomy_term');
    $arbre = $stockage->loadTree('service_au_citoyen', 0, NULL, FALSE);
    $noms = array_column(array_map(fn($t) => ['tid' => $t->tid, 'name' => $t->name], $arbre), 'name', 'tid');
    $pleines = $this->rubriquesPleines($arbre);
    foreach ($arbre as $terme) {
      // Rubrique dépubliée, ou sans aucune démarche publiée : sa page
      // afficherait « Aucune démarche trouvée ».
      if ((isset($terme->status) && !$terme->status) || !isset($pleines[(int) $terme->tid])) {
        continue;
      }
      $parent = (int) ($terme->parents[0] ?? 0);
      $entrees[] = $this->entree(
        $terme->name,
        Url::fromUserInput('/services-aux-citoyens/' . $terme->tid)->toString(),
        'Rubrique des services aux citoyens',
        $parent && isset($noms[$parent]) ? $noms[$parent] : '',
        '',
        strip_tags((string) $terme->description__value),
      );
    }
    return $entrees;
  }

  /**
   * Rubriques qui contiennent au moins une démarche publiée, sous-rubriques
   * comprises.
   *
   * @return array
   *   Identifiants des rubriques non vides, en clés.
   */
  protected function rubriquesPleines(array $arbre): array {
    $requete = $this->base->select('node__field_service_au_citoyen', 'r');
    $requete->join('node_field_data', 'n', 'n.nid = r.entity_id AND n.default_langcode = 1');
    $requete->condition('n.status', 1);
    $requete->condition('r.deleted', 0);
    $requete->addField('r', 'field_service_au_citoyen_target_id', 'tid');
    $requete->distinct();
    $directes = $requete->execute()->fetchCol();

    $parents = [];
    foreach ($arbre as $terme) {
      $parents[(int) $terme->tid] = array_filter(array_map('intval', $terme->parents));
    }
    $pleines = [];
    $pile = array_map('intval', $directes);
    while ($pile) {
      $tid = array_pop($pile);
      if (isset($pleines[$tid])) {
        continue;
      }
      $pleines[$tid] = TRUE;
      foreach ($parents[$tid] ?? [] as $parent) {
        $pile[] = $parent;
      }
    }
    return $pleines;
  }

  /**
   * Contenus publiés des types retenus.
   */
  protected function contenus(): array {
    $entrees = [];
    $stockage = $this->entityTypeManager->getStorage('node');

    foreach (self::TYPES as $type => [$libelle, $maximum]) {
      $requete = $stockage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $type)
        ->condition('status', 1)
        ->sort('created', 'DESC');
      if ($maximum) {
        $requete->range(0, $maximum);
      }
      $ids = $requete->execute();

      foreach (array_chunk($ids, 50) as $lot) {
        foreach ($stockage->loadMultiple($lot) as $node) {
          $entrees[] = $this->entreeContenu($node, $libelle);
        }
        $stockage->resetCache($lot);
      }
    }
    return $entrees;
  }

  /**
   * Prépare l'entrée d'un contenu.
   */
  protected function entreeContenu(NodeInterface $node, string $libelle): array {
    $morceaux = [];
    $rubrique = '';

    if ($node->hasField('field_membre_fonction') && !$node->get('field_membre_fonction')->isEmpty()) {
      // La fonction d'un membre sert de rubrique : « ministre des travaux
      // publics » doit trouver la fiche du ministre, pas un article.
      $rubrique = $node->get('field_membre_fonction')->value;
      $morceaux[] = $rubrique;
    }
    foreach (['field_page_surtitre', 'field_page_chapo', 'field_membre_description', 'body', 'field_membre_biographie'] as $champ) {
      if ($node->hasField($champ) && !$node->get($champ)->isEmpty()) {
        $morceaux[] = $node->get($champ)->value;
      }
    }
    // Pages à sections : le texte de chaque section, dans l'ordre.
    if ($node->hasField('field_page_sections')) {
      foreach ($node->get('field_page_sections')->referencedEntities() as $section) {
        $morceaux[] = $this->texteParagraphe($section);
      }
    }
    foreach (['field_service_au_citoyen', 'field_categorie'] as $champ) {
      if ($rubrique === '' && $node->hasField($champ) && ($terme = $node->get($champ)->entity)) {
        $rubrique = $terme->label();
        break;
      }
    }

    $texte = $this->texteBrut(implode("\n\n", $morceaux));
    $resume = Unicode::truncate($texte, 140, TRUE, TRUE);
    if ($libelle === 'Actualité') {
      $date = date('d/m/Y', $node->getCreatedTime());
      $resume = trim($date . ' ' . $resume);
      $texte = 'Publié le ' . $date . ".\n" . $texte;
    }

    return $this->entree($node->label(), $node->toUrl()->toString(), $libelle, $rubrique, $resume, $texte);
  }

  /**
   * Texte d'une section de page et de ses éléments (cartes, étapes,
   * chiffres), dans l'ordre d'affichage.
   */
  protected function texteParagraphe($paragraphe, int $profondeur = 0): string {
    $morceaux = [];
    foreach (['field_sec_surtitre', 'field_sec_titre', 'field_sec_date', 'field_sec_compte', 'field_chiffre_valeur', 'field_chiffre_libelle', 'field_sec_chapo', 'field_sec_texte', 'field_sec_corps', 'field_sec_legende'] as $champ) {
      if ($paragraphe->hasField($champ) && !$paragraphe->get($champ)->isEmpty()) {
        $morceaux[] = $paragraphe->get($champ)->value;
      }
    }
    if ($paragraphe->hasField('field_sec_liste') && !$paragraphe->get('field_sec_liste')->isEmpty()) {
      $morceaux[] = implode(', ', array_column($paragraphe->get('field_sec_liste')->getValue(), 'value'));
    }
    if ($profondeur < 2 && $paragraphe->hasField('field_sec_elements')) {
      foreach ($paragraphe->get('field_sec_elements')->referencedEntities() as $element) {
        $morceaux[] = $this->texteParagraphe($element, $profondeur + 1);
      }
    }
    return implode("\n", $morceaux);
  }

  /**
   * Construit une entrée et ses clés de recherche.
   */
  protected function entree(string $titre, string $url, string $type, string $rubrique, string $resume, string $texte): array {
    $titre = trim(preg_replace('/\s+/u', ' ', $titre));
    $resume = trim(preg_replace('/\s+/u', ' ', str_replace(['[', ']'], '', $resume)));
    return [
      'titre' => str_replace(['[', ']'], ['(', ')'], $titre),
      'url' => $url,
      'type' => $type,
      'rubrique' => $rubrique,
      'resume' => $resume,
      'texte' => Unicode::truncate($texte, 6000, TRUE, TRUE),
      'cle_titre' => $this->normaliser($titre),
      'cle_rubrique' => $this->normaliser($rubrique),
      'cle' => $this->normaliser($titre . ' ' . $rubrique . ' ' . $texte),
    ];
  }

  /**
   * Texte brut d'un champ HTML.
   */
  protected function texteBrut(string $html): string {
    $html = preg_replace('#<(br|/p|/li|/h[1-6]|/tr|/div)\b[^>]*>#i', "\n", $html);
    $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texte = preg_replace('/[ \t\x{00A0}]+/u', ' ', $texte);
    return trim(preg_replace('/\n\s*\n+/u', "\n", $texte));
  }

  /**
   * Minuscules sans accents, pour comparer les mots.
   */
  protected function normaliser(string $texte): string {
    $texte = mb_strtolower($texte, 'UTF-8');
    $texte = strtr($texte, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', '’' => "'"]);
    return $texte;
  }

  /**
   * Mots significatifs d'une question.
   */
  protected function mots(string $question): array {
    $mots = preg_split("/[^a-z0-9]+/", $this->normaliser($question), -1, PREG_SPLIT_NO_EMPTY);
    $mots = array_filter($mots, fn($m) => strlen($m) >= 3 && !in_array($m, self::MOTS_VIDES, TRUE));
    // Pluriels simples : « taxes » trouve aussi « taxe ».
    $mots = array_map(fn($m) => strlen($m) > 4 ? rtrim($m, 's') : $m, $mots);
    return array_values(array_unique($mots));
  }

}
