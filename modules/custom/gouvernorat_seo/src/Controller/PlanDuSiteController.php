<?php

namespace Drupal\gouvernorat_seo\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plan du site pour les moteurs de recherche : /sitemap.xml.
 *
 * Il recense :
 * - les pages principales du site (accueil, actualités, services…) ;
 * - tous les contenus publiés, avec leur date de dernière modification ;
 * - les rubriques publiées des services aux citoyens.
 *
 * Il est recalculé dès qu'un contenu ou une rubrique change (étiquettes de
 * cache node_list et taxonomy_term_list). Les adresses sont celles du
 * domaine qui sert la page : le plan d'un site de test pointe donc vers le
 * site de test, celui de la production vers la production.
 */
class PlanDuSiteController extends ControllerBase {

  /**
   * Pages principales, ajoutées si elles existent sur le site.
   */
  const PAGES = [
    '/' => 'daily',
    '/actualites' => 'daily',
    '/services-aux-citoyens' => 'weekly',
    '/videos' => 'weekly',
    '/all-gallery' => 'weekly',
    '/a-propos' => 'monthly',
    '/executif-provincial' => 'monthly',
    '/cabinet-du-gouverneur' => 'monthly',
    '/structuresville' => 'monthly',
    '/culture-et-patrimoine' => 'monthly',
    '/contact' => 'yearly',
  ];

  public function __construct(
    protected Connection $base,
    protected PathValidatorInterface $validateur,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('path.validator'),
    );
  }

  /**
   * Construit le document XML.
   */
  public function xml(): CacheableResponse {
    $entrees = [];

    foreach (self::PAGES as $chemin => $frequence) {
      $url = $chemin === '/' ? Url::fromRoute('<front>') : $this->validateur->getUrlIfValidWithoutAccessCheck($chemin);
      if ($url) {
        $entrees[] = [$url, NULL, $frequence];
      }
    }

    $nodes = $this->base->select('node_field_data', 'n')
      ->fields('n', ['nid', 'changed'])
      ->condition('n.status', 1)
      ->condition('n.default_langcode', 1)
      ->orderBy('n.changed', 'DESC')
      ->execute();
    foreach ($nodes as $node) {
      $entrees[] = [Url::fromRoute('entity.node.canonical', ['node' => $node->nid]), (int) $node->changed, NULL];
    }

    $termes = $this->base->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'changed'])
      ->condition('t.vid', 'service_au_citoyen')
      ->condition('t.status', 1)
      ->condition('t.default_langcode', 1)
      ->execute();
    foreach ($termes as $terme) {
      $entrees[] = [Url::fromUserInput('/services-aux-citoyens/' . $terme->tid), (int) $terme->changed, 'weekly'];
    }

    $cache = (new CacheableMetadata())
      ->setCacheTags(['node_list', 'taxonomy_term_list'])
      ->setCacheContexts(['url.site'])
      ->setCacheMaxAge(86400);

    $vues = [];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($entrees as [$url, $modifie, $frequence]) {
      $genere = $url->setAbsolute()->toString(TRUE);
      $cache->addCacheableDependency($genere);
      $adresse = $genere->getGeneratedUrl();
      if (isset($vues[$adresse])) {
        continue;
      }
      $vues[$adresse] = TRUE;
      $xml .= '  <url><loc>' . htmlspecialchars($adresse, ENT_XML1) . '</loc>';
      if ($modifie) {
        $xml .= '<lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $modifie) . '</lastmod>';
      }
      if ($frequence) {
        $xml .= '<changefreq>' . $frequence . '</changefreq>';
      }
      $xml .= "</url>\n";
    }
    $xml .= "</urlset>\n";

    $reponse = new CacheableResponse($xml, 200, [
      'Content-Type' => 'application/xml; charset=utf-8',
    ]);
    $reponse->addCacheableDependency($cache);
    return $reponse;
  }

}
