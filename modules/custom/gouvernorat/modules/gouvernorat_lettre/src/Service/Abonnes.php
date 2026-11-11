<?php

namespace Drupal\gouvernorat_lettre\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;

/**
 * Inscriptions, confirmations et désabonnements.
 *
 * Inscription en deux temps (« double opt-in ») : l'adresse saisie ne reçoit
 * la lettre qu'après avoir cliqué sur le lien de confirmation. Personne ne
 * peut donc inscrire l'adresse d'un autre.
 */
class Abonnes {

  const TABLE = 'gouvernorat_lettre_abonne';

  /**
   * Durée de validité d'une demande non confirmée.
   */
  const DELAI_CONFIRMATION = 7 * 86400;

  public function __construct(
    protected Connection $database,
    protected Expediteur $expediteur,
    protected Courriel $courriel,
    protected EmailValidatorInterface $emailValidator,
    protected TimeInterface $time,
  ) {}

  /**
   * Met une adresse au format attendu, ou renvoie NULL si elle est invalide.
   */
  public function normaliser(string $courriel): ?string {
    $courriel = mb_strtolower(trim($courriel));
    if ($courriel === '' || mb_strlen($courriel) > 254 || !$this->emailValidator->isValid($courriel)) {
      return NULL;
    }
    return $courriel;
  }

  /**
   * Enregistre une demande d'inscription et envoie le courriel de confirmation.
   *
   * Pour ne pas révéler qui est abonné, la réponse au visiteur est la même
   * que l'adresse soit nouvelle ou déjà inscrite.
   *
   * @return bool
   *   FALSE si le courriel de confirmation n'a pas pu partir.
   */
  public function demander(string $courriel): bool {
    $existant = $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->condition('courriel', $courriel)
      ->execute()
      ->fetchObject();
    if ($existant && (int) $existant->statut === 1) {
      return TRUE;
    }
    if ($existant) {
      $jeton = $existant->jeton;
    }
    else {
      $jeton = Crypt::randomBytesBase64(24);
      $this->database->insert(self::TABLE)->fields([
        'courriel' => $courriel,
        'statut' => 0,
        'jeton' => $jeton,
        'cree' => $this->time->getRequestTime(),
        'origine' => 'formulaire',
      ])->execute();
    }
    $lien = Url::fromRoute('gouvernorat_lettre.confirmer', ['jeton' => $jeton], ['absolute' => TRUE])->toString();
    $message = $this->courriel->confirmation($lien);
    return $this->expediteur->envoyer($courriel, $message['sujet'], $message['html'], $message['texte']);
  }

  /**
   * Abonné correspondant à un jeton, ou NULL.
   */
  public function parJeton(string $jeton): ?object {
    return $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->condition('jeton', $jeton)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  /**
   * Valide une inscription.
   */
  public function confirmer(string $jeton): bool {
    $abonne = $this->parJeton($jeton);
    if (!$abonne) {
      return FALSE;
    }
    if ((int) $abonne->statut !== 1) {
      $this->database->update(self::TABLE)
        ->fields(['statut' => 1, 'confirme' => $this->time->getRequestTime()])
        ->condition('aid', $abonne->aid)
        ->execute();
    }
    return TRUE;
  }

  /**
   * Désabonne : l'adresse est effacée, ainsi que les envois en attente.
   */
  public function desabonner(string $jeton): bool {
    $abonne = $this->parJeton($jeton);
    if (!$abonne) {
      return FALSE;
    }
    $this->supprimer((int) $abonne->aid);
    return TRUE;
  }

  /**
   * Efface un abonné.
   */
  public function supprimer(int $aid): void {
    $this->database->delete('gouvernorat_lettre_envoi')
      ->condition('aid', $aid)
      ->condition('statut', 1, '<>')
      ->execute();
    $this->database->delete(self::TABLE)->condition('aid', $aid)->execute();
  }

  /**
   * Ajoute directement des abonnés ayant déjà donné leur accord.
   *
   * @return int
   *   Nombre d'adresses ajoutées ou confirmées.
   */
  public function ajouter(array $courriels): int {
    $nombre = 0;
    $maintenant = $this->time->getRequestTime();
    foreach ($courriels as $courriel) {
      $statut = $this->database->select(self::TABLE, 'a')
        ->fields('a', ['statut'])
        ->condition('courriel', $courriel)
        ->execute()
        ->fetchField();
      if ($statut === FALSE) {
        $this->database->insert(self::TABLE)->fields([
          'courriel' => $courriel,
          'statut' => 1,
          'jeton' => Crypt::randomBytesBase64(24),
          'cree' => $maintenant,
          'confirme' => $maintenant,
          'origine' => 'ajout',
        ])->execute();
        $nombre++;
      }
      elseif ((int) $statut !== 1) {
        $this->database->update(self::TABLE)
          ->fields(['statut' => 1, 'confirme' => $maintenant, 'origine' => 'ajout'])
          ->condition('courriel', $courriel)
          ->execute();
        $nombre++;
      }
    }
    return $nombre;
  }

  /**
   * Efface les demandes jamais confirmées.
   */
  public function purger(): void {
    $this->database->delete(self::TABLE)
      ->condition('statut', 0)
      ->condition('cree', $this->time->getRequestTime() - self::DELAI_CONFIRMATION, '<')
      ->execute();
  }

  /**
   * Nombre d'abonnés par statut.
   *
   * @return array{inscrits: int, attente: int}
   *   Les totaux.
   */
  public function compter(): array {
    $totaux = $this->database->query('SELECT statut, COUNT(*) FROM {' . self::TABLE . '} GROUP BY statut')->fetchAllKeyed();
    return ['inscrits' => (int) ($totaux[1] ?? 0), 'attente' => (int) ($totaux[0] ?? 0)];
  }

}
