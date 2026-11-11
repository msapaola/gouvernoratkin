<?php

namespace Drupal\gouvernorat_lettre\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envoie les courriels de la lettre depuis son adresse propre.
 *
 * La lettre a sa boîte (lettre@kinshasa.cd), distincte de celle qui envoie
 * les messages du site (site@kinshasa.cd, module SMTP) : elle se connecte
 * donc elle-même au serveur de messagerie.
 *
 * Le mot de passe ne se trouve que dans settings.php :
 * @code
 * $settings['gouvernorat_lettre_mot_de_passe'] = '…';
 * @endcode
 */
class Expediteur {

  /**
   * Connexion ouverte, réutilisée pour tout un lot d'envois.
   */
  protected ?TransportInterface $transport = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Le mot de passe de la boîte est-il renseigné ?
   */
  public function estPret(): bool {
    return (string) Settings::get('gouvernorat_lettre_dsn', '') !== ''
      || (string) Settings::get('gouvernorat_lettre_mot_de_passe', '') !== '';
  }

  /**
   * Envoie un courriel. Renvoie FALSE en cas d'échec (consigné au journal).
   *
   * @param string $destinataire
   *   Adresse du destinataire.
   * @param string $sujet
   *   Objet du courriel.
   * @param string $html
   *   Version mise en forme.
   * @param string $texte
   *   Version texte brut.
   * @param string|null $desabonnement
   *   Adresse de désabonnement, pour les en-têtes List-Unsubscribe.
   */
  public function envoyer(string $destinataire, string $sujet, string $html, string $texte, ?string $desabonnement = NULL): bool {
    if (!$this->estPret()) {
      $this->logger->error("Lettre d'information : mot de passe de la boîte d'envoi absent de settings.php.");
      return FALSE;
    }
    $config = $this->configFactory->get('gouvernorat_lettre.settings');

    $courriel = (new Email())
      ->from(new Address($config->get('expediteur'), (string) $config->get('nom_expediteur')))
      ->to($destinataire)
      ->subject($sujet)
      ->text($texte)
      ->html($html);
    if ($config->get('repondre_a')) {
      $courriel->replyTo($config->get('repondre_a'));
    }
    if ($desabonnement) {
      // Lien « Se désabonner » affiché par les messageries (Gmail, Outlook…),
      // en un clic (RFC 8058).
      $courriel->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $desabonnement . '/un-clic>');
      $courriel->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    try {
      $this->transport()->send($courriel);
      return TRUE;
    }
    catch (\Throwable $e) {
      // Connexion peut-être coupée par le serveur : on la rouvrira.
      $this->transport = NULL;
      $this->logger->error("Lettre d'information : échec d'envoi à @a (@m).", [
        '@a' => $destinataire,
        '@m' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Ouvre (une fois) la connexion au serveur de messagerie.
   */
  protected function transport(): TransportInterface {
    if ($this->transport) {
      return $this->transport;
    }
    // Réservé aux essais : un autre serveur, par exemple smtp://127.0.0.1:2525.
    $dsn = (string) Settings::get('gouvernorat_lettre_dsn', '');
    if ($dsn === '') {
      $config = $this->configFactory->get('gouvernorat_lettre.settings');
      $dsn = sprintf(
        '%s://%s:%s@%s:%d',
        $config->get('chiffrement') === 'ssl' ? 'smtps' : 'smtp',
        rawurlencode((string) $config->get('utilisateur')),
        rawurlencode((string) Settings::get('gouvernorat_lettre_mot_de_passe', '')),
        $config->get('hote'),
        (int) $config->get('port')
      );
    }
    return $this->transport = Transport::fromDsn($dsn);
  }

}
