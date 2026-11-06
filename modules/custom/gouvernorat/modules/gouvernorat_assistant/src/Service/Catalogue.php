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
    'membre' => ['Membre du gouvernement provincial', NULL],
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
    '/a-propos' => ['À propos', 'Présentation de la Ville-Province de Kinshasa et du Gouvernorat.'],
    '/executif-provincial' => ['Exécutif provincial', 'Le gouverneur, le vice-gouverneur et les ministres provinciaux.'],
    '/cabinet-du-gouverneur' => ['Cabinet du gouverneur', 'Les membres du cabinet du gouverneur.'],
    '/structuresville' => ['Structures et services de la ville', 'Les structures et services rattachés à la Ville-Province.'],
    '/culture-et-patrimoine' => ['Culture et patrimoine', "L'histoire, les lieux et la vie culturelle de la capitale."],
    '/contact' => ['Contact', 'Formulaire pour écrire au Gouvernorat, adresse et coordonnées.'],
  ];

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
        $entrees[] = $this->entree($titre, $url->toString(), 'Page principale', '', $resume, $resume);
      }
    }

    $entrees = array_merge($entrees, $this->rubriques(), $this->contenus());

    $this->cache->set(self::CLE_CACHE, $entrees, Cache::PERMANENT, ['node_list', 'taxonomy_term_list']);
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
    return array_fill_keys(array_column($this->entrees(), 'url'), TRUE);
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

    $scores = [];
    foreach ($this->entrees() as $i => $e) {
      $score = 0;
      foreach ($mots as $mot) {
        if (str_contains($e['cle_titre'], $mot)) {
          $score += 6;
        }
        if (str_contains($e['cle_rubrique'], $mot)) {
          $score += 3;
        }
        $score += min(4, substr_count($e['cle'], $mot));
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
   * Rubriques publiées des services aux citoyens.
   */
  protected function rubriques(): array {
    $entrees = [];
    $stockage = $this->entityTypeManager->getStorage('taxonomy_term');
    $arbre = $stockage->loadTree('service_au_citoyen', 0, NULL, FALSE);
    $noms = array_column(array_map(fn($t) => ['tid' => $t->tid, 'name' => $t->name], $arbre), 'name', 'tid');
    foreach ($arbre as $terme) {
      if (isset($terme->status) && !$terme->status) {
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
      $morceaux[] = $node->get('field_membre_fonction')->value;
    }
    foreach (['field_membre_description', 'body', 'field_membre_biographie'] as $champ) {
      if ($node->hasField($champ) && !$node->get($champ)->isEmpty()) {
        $morceaux[] = $node->get($champ)->value;
      }
    }
    foreach (['field_service_au_citoyen', 'field_categorie'] as $champ) {
      if ($node->hasField($champ) && ($terme = $node->get($champ)->entity)) {
        $rubrique = $terme->label();
        break;
      }
    }

    $texte = $this->texteBrut(implode("\n\n", $morceaux));
    $resume = Unicode::truncate($texte, 140, TRUE, TRUE);
    if ($libelle === 'Actualité') {
      $resume = trim(date('d/m/Y', $node->getCreatedTime()) . ' ' . $resume);
    }

    return $this->entree($node->label(), $node->toUrl()->toString(), $libelle, $rubrique, $resume, $texte);
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
