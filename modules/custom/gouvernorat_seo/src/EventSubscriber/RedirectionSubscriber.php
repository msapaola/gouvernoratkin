<?php

namespace Drupal\gouvernorat_seo\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirige en 301 vers l'adresse officielle d'un contenu ou d'une rubrique.
 *
 * Constats S7, U5 et R8 du diagnostic : une même page répondait sous
 * plusieurs adresses (/node/15 et son alias, /taxonomy/term/12 et la page
 * des services), ce qui disperse le référencement et donne des liens
 * illisibles. Désormais :
 * - /node/N et /taxonomy/term/N renvoient (301) vers leur alias s'il existe ;
 * - une rubrique des services aux citoyens renvoie (301) vers
 *   /services-aux-citoyens/N, la page qui liste ses démarches. Le thème
 *   faisait cette redirection lui-même en 302, avec un exit() qui coupait
 *   court au traitement normal de la requête.
 *
 * Seules les consultations (GET, HEAD) sont concernées ; la chaîne de
 * requête est conservée.
 */
class RedirectionSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Après le routage (32), avant le contrôleur.
    return [KernelEvents::REQUEST => ['rediriger', 30]];
  }

  /**
   * Envoie la redirection si l'adresse demandée n'est pas l'adresse officielle.
   */
  public function rediriger(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $requete = $event->getRequest();
    if (!$requete->isMethodCacheable()) {
      return;
    }
    // Si un contenu devient un jour la page d'accueil, « / » ne doit pas être
    // redirigé vers son alias.
    if ($requete->getPathInfo() === '/') {
      return;
    }

    $route = $this->routeMatch->getRouteName();
    $entite = match ($route) {
      'entity.node.canonical' => $this->routeMatch->getParameter('node'),
      'entity.taxonomy_term.canonical' => $this->routeMatch->getParameter('taxonomy_term'),
      default => NULL,
    };
    if (!$entite instanceof ContentEntityInterface) {
      return;
    }

    if ($entite instanceof TermInterface && $entite->bundle() === 'service_au_citoyen') {
      $cible = Url::fromUserInput('/services-aux-citoyens/' . $entite->id());
    }
    else {
      $cible = $entite->toUrl();
    }

    $cible_texte = $cible->setAbsolute(FALSE)->toString(TRUE);
    $chemin_cible = parse_url($cible_texte->getGeneratedUrl(), PHP_URL_PATH);
    $chemin_demande = $requete->getBasePath() . $requete->getPathInfo();
    if ($chemin_cible === NULL || rawurldecode($chemin_cible) === rawurldecode($chemin_demande)) {
      return;
    }

    $adresse = $cible_texte->getGeneratedUrl();
    if (($chaine = $requete->getQueryString()) !== NULL) {
      $adresse .= '?' . $chaine;
    }

    $reponse = new LocalRedirectResponse($adresse, 301);
    $reponse->addCacheableDependency($entite);
    $reponse->addCacheableDependency($cible_texte);
    $reponse->addCacheableDependency((new CacheableMetadata())->setCacheContexts(['url.path', 'url.query_args']));
    $event->setResponse($reponse);
  }

}
