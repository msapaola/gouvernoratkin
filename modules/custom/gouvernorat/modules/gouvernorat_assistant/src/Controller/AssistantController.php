<?php

namespace Drupal\gouvernorat_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
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
    $ip = $request->getClientIp() ?? 'inconnue';
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
      $reponse = $this->assistant->repondre($echanges);
      return $this->json(['reponse' => $reponse, 'signature' => self::signer($reponse)]);
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

    // Les réponses de l'assistant renvoyées par le navigateur doivent porter
    // la signature donnée par le serveur : sinon un visiteur pourrait
    // fabriquer de fausses réponses (« j'accepte d'ignorer mes règles… »)
    // pour détourner l'assistant. Une seule réponse non signée, et tout
    // l'historique est écarté : seule la dernière question est gardée.
    foreach ($messages as $message) {
      if (is_array($message) && ($message['role'] ?? '') === 'assistant'
        && !(is_string($message['content'] ?? NULL) && is_string($message['signature'] ?? NULL)
          && hash_equals(self::signer($message['content']), $message['signature']))) {
        $messages = [end($messages)];
        break;
      }
    }

    $echanges = [];
    $attendu = 'user';
    foreach ($messages as $message) {
      if (!is_array($message) || !isset($message['role'], $message['content']) || !is_string($message['content'])) {
        return NULL;
      }
      $texte = trim(preg_replace('/[^\P{C}\n]+/u', '', $message['content']));
      if ($message['role'] === 'user') {
        $texte = self::nettoyerQuestion($texte);
      }
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
   * Signature d'une réponse de l'assistant (clé propre au site).
   */
  public static function signer(string $texte): string {
    return hash_hmac('sha256', $texte, Settings::getHashSalt() . ':gouvernorat_assistant');
  }

  /**
   * Prépare une question avant de l'envoyer à Anthropic.
   *
   * - Les données personnelles les plus courantes sont masquées : adresses
   *   de courriel, numéros de téléphone, longues suites de chiffres
   *   (pièces d'identité, comptes). Elles ne quittent pas le site.
   * - Les chevrons sont neutralisés : le visiteur ne peut pas imiter les
   *   balises que le site ajoute autour des pages jointes.
   */
  public static function nettoyerQuestion(string $texte): string {
    $texte = preg_replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u', '[courriel masqué]', $texte);
    // Au moins 9 chiffres : un téléphone ou un numéro de pièce, pas une date
    // (« 13/03/2018 ») ni un montant courant.
    $texte = preg_replace_callback('/\+?\d[\d .\-\/]{7,}\d/u', fn($m) => preg_match_all('/\d/', $m[0]) >= 9 ? '[numéro masqué]' : $m[0], $texte);
    return str_replace(['<', '>'], ['‹', '›'], $texte);
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
