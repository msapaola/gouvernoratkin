<?php

namespace Drupal\gouvernorat_securite\EventSubscriber;

use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de réponse : version de Drupal masquée, site de test non indexé.
 */
class ReponseSubscriber implements EventSubscriberInterface {

  /**
   * Hôtes considérés comme des sites de test, jamais indexés.
   *
   * Un autre environnement peut être ajouté sans toucher au code, dans son
   * settings.php : $settings['gouvernorat_noindex'] = TRUE;
   */
  const HOTES_TEST = '/^(www\.)?(test|staging|preprod|recette)\./i';

  public function __construct(protected Settings $settings) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Après FinishResponseSubscriber (priorité 0), qui pose X-Generator.
    return [KernelEvents::RESPONSE => ['surReponse', -100]];
  }

  /**
   * Ajuste les en-têtes de chaque réponse de Drupal.
   */
  public function surReponse(ResponseEvent $event): void {
    $reponse = $event->getResponse();

    // S5 — Drupal annonce « X-Generator: Drupal 11 (https://www.drupal.org) »
    // et PHP sa version exacte (« X-Powered-By: PHP/8.3… »). Le .htaccess les
    // retire aussi ; le faire ici couvre un serveur qui l'ignorerait.
    $reponse->headers->remove('X-Generator');
    if (!headers_sent()) {
      header_remove('X-Powered-By');
    }

    // S6 — Le site de test ne doit pas apparaître dans les moteurs de
    // recherche, où il ferait concurrence au site officiel.
    $hote = $event->getRequest()->getHost();
    if ($this->settings->get('gouvernorat_noindex', FALSE) || preg_match(self::HOTES_TEST, $hote)) {
      $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
  }

}
