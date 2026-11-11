<?php

namespace Drupal\gouvernorat_lettre\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Url;
use Drupal\gouvernorat_lettre\Service\Abonnes;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inscription depuis le pied de page et désabonnement « en un clic ».
 */
class LettreController extends ControllerBase {

  /**
   * Demandes permises par adresse IP et par heure.
   */
  const LIMITE_IP = 10;

  /**
   * Courriels de confirmation permis par adresse et par jour.
   */
  const LIMITE_ADRESSE = 3;

  public function __construct(
    protected Abonnes $abonnes,
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('gouvernorat_lettre.abonnes'),
      $container->get('flood'),
    );
  }

  /**
   * Formulaire du pied de page : réponse JSON, ou retour sur la page.
   */
  public function inscription(Request $request): Response {
    $saisie = '';
    foreach (['courriel', 'EMAIL', 'email', 'mail'] as $nom) {
      if (is_string($request->request->get($nom)) && $request->request->get($nom) !== '') {
        $saisie = $request->request->get($nom);
        break;
      }
    }
    [$code, $message] = $this->traiterDemande($saisie, $request);

    if ($request->isXmlHttpRequest() || in_array('application/json', $request->getAcceptableContentTypes(), TRUE)) {
      return new JsonResponse(['ok' => $code === 200, 'message' => $message], $code);
    }
    $code === 200 ? $this->messenger()->addStatus($message) : $this->messenger()->addError($message);
    return new RedirectResponse(Url::fromRoute('gouvernorat_lettre.page')->toString(), 303);
  }

  /**
   * Contrôle une demande d'inscription et envoie la confirmation.
   *
   * @return array{0: int, 1: string}
   *   Code HTTP et message pour le visiteur.
   */
  public function traiterDemande(string $saisie, Request $request): array {
    $courriel = $this->abonnes->normaliser($saisie);
    if (!$courriel) {
      return [400, "Cette adresse de courriel n'est pas valide."];
    }
    $ip = (string) $request->getClientIp();
    $adresse = hash('sha256', $courriel);
    if (!$this->flood->isAllowed('gouvernorat_lettre.ip', self::LIMITE_IP, 3600, $ip)
      || !$this->flood->isAllowed('gouvernorat_lettre.adresse', self::LIMITE_ADRESSE, 86400, $adresse)) {
      return [429, 'Trop de demandes. Merci de réessayer dans quelques heures.'];
    }
    $this->flood->register('gouvernorat_lettre.ip', 3600, $ip);
    $this->flood->register('gouvernorat_lettre.adresse', 86400, $adresse);

    if (!$this->abonnes->demander($courriel)) {
      return [503, "L'inscription n'a pas pu aboutir pour le moment. Merci de réessayer plus tard."];
    }
    return [200, "Merci ! Un courriel de confirmation vient de vous être envoyé. Cliquez sur le lien qu'il contient pour valider votre inscription."];
  }

  /**
   * Désabonnement demandé par la messagerie du lecteur (RFC 8058).
   */
  public function unClic(string $jeton): Response {
    $this->abonnes->desabonner($jeton);
    return new Response('Vous êtes désabonné.', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
  }

}
