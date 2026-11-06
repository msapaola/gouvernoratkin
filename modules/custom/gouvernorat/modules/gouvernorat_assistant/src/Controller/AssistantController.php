<?php

namespace Drupal\gouvernorat_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\gouvernorat_assistant\Service\Assistant;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Point d'entrée de l'assistant : POST /assistant/message.
 *
 * Reçoit en JSON la conversation tenue par le navigateur et renvoie la
 * réponse. Les questions ne sont pas enregistrées sur le site.
 *
 * Protections :
 * - appels acceptés seulement depuis les pages du site (en-tête propre au
 *   script de l'assistant, origine vérifiée) ;
 * - taille des messages et de l'historique bornée ;
 * - limite de questions par heure et par visiteur, et par jour pour tout le
 *   site (réglables), pour maîtriser la facture.
 */
class AssistantController extends ControllerBase {

  const LONGUEUR_QUESTION = 800;
  const LONGUEUR_REPONSE = 4000;
  const ECHANGES_MAX = 10;

  public function __construct(
    protected Assistant $assistant,
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gouvernorat_assistant.assistant'),
      $container->get('flood'),
    );
  }

  /**
   * Répond à une question.
   */
  public function message(Request $request): JsonResponse {
    if (!Assistant::disponible()) {
      return $this->json(['erreur' => "L'assistant n'est pas disponible pour le moment."], 503);
    }

    // Seul le script de l'assistant, sur les pages du site, envoie cet
    // en-tête ; un navigateur ne peut pas l'ajouter depuis un autre site
    // sans autorisation (CORS), que le site ne donne pas.
    if ($request->headers->get('X-Assistant') !== '1' || !str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
      return $this->json(['erreur' => 'Requête refusée.'], 400);
    }
    $origine = $request->headers->get('Origin');
    if ($origine && $origine !== $request->getSchemeAndHttpHost()) {
      return $this->json(['erreur' => 'Requête refusée.'], 403);
    }

    $echanges = $this->valider(json_decode($request->getContent(), TRUE));
    if ($echanges === NULL) {
      return $this->json(['erreur' => 'Message invalide ou trop long.'], 400);
    }

    $config = $this->config('gouvernorat_assistant.settings');
    $ip = $request->getClientIp();
    $limite_heure = max(1, (int) $config->get('limite_heure'));
    $limite_jour = max(1, (int) $config->get('limite_jour_total'));
    if (!$this->flood->isAllowed('gouvernorat_assistant.visiteur', $limite_heure, 3600, $ip)) {
      return $this->json(['erreur' => "Vous avez posé beaucoup de questions en peu de temps. Merci de réessayer dans un moment, ou d'écrire au Gouvernorat par la page Contact."], 429);
    }
    if (!$this->flood->isAllowed('gouvernorat_assistant.site', $limite_jour, 86400, 'site')) {
      return $this->json(['erreur' => "L'assistant a atteint sa limite de questions pour aujourd'hui. Merci de réessayer demain, ou d'écrire au Gouvernorat par la page Contact."], 429);
    }
    $this->flood->register('gouvernorat_assistant.visiteur', 3600, $ip);
    $this->flood->register('gouvernorat_assistant.site', 86400, 'site');

    try {
      return $this->json(['reponse' => $this->assistant->repondre($echanges)]);
    }
    catch (\RuntimeException $e) {
      return $this->json(['erreur' => $e->getMessage()], 502);
    }
  }

  /**
   * Vérifie la conversation reçue.
   *
   * @return array|null
   *   Les échanges prêts à envoyer, ou NULL si la requête est invalide.
   */
  protected function valider(mixed $donnees): ?array {
    if (!is_array($donnees) || !isset($donnees['messages']) || !is_array($donnees['messages'])) {
      return NULL;
    }
    // Seuls les derniers échanges sont repris : le contexte suffit, et la
    // requête reste courte.
    $messages = array_slice(array_values($donnees['messages']), -self::ECHANGES_MAX);

    $echanges = [];
    $attendu = 'user';
    foreach ($messages as $message) {
      if (!is_array($message) || !isset($message['role'], $message['content']) || !is_string($message['content'])) {
        return NULL;
      }
      $texte = trim(preg_replace('/[^\P{C}\n]+/u', '', $message['content']));
      $maximum = $message['role'] === 'user' ? self::LONGUEUR_QUESTION : self::LONGUEUR_REPONSE;
      if ($texte === '' || mb_strlen($texte) > $maximum) {
        return NULL;
      }
      // La conversation commence par une question : un historique tronqué
      // qui commencerait par une réponse perd cette réponse.
      if (!$echanges && $message['role'] === 'assistant') {
        continue;
      }
      if ($message['role'] !== $attendu) {
        return NULL;
      }
      $echanges[] = ['role' => $attendu, 'content' => $texte];
      $attendu = $attendu === 'user' ? 'assistant' : 'user';
    }

    if (!$echanges || end($echanges)['role'] !== 'user') {
      return NULL;
    }
    return $echanges;
  }

  /**
   * Réponse JSON jamais mise en cache.
   */
  protected function json(array $donnees, int $statut = 200): JsonResponse {
    $reponse = new JsonResponse($donnees, $statut);
    $reponse->headers->set('Cache-Control', 'no-store, private');
    $reponse->headers->set('X-Robots-Tag', 'noindex');
    return $reponse;
  }

}
