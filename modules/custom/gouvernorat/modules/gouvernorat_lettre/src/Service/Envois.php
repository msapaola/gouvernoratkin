<?php

namespace Drupal\gouvernorat_lettre\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Envoi d'une lettre à tous les abonnés, par lots.
 *
 * Au départ, le texte de la lettre est figé (table des éditions) et une ligne
 * par abonné est placée dans la file. La file est vidée tout de suite depuis
 * la page d'envoi, puis par le cron pour ce qui reste, sans dépasser le
 * nombre de courriels par heure autorisé par l'hébergeur.
 */
class Envois {

  /**
   * Un échec est retenté jusqu'à ce nombre d'essais.
   */
  const ESSAIS_MAX = 3;

  public function __construct(
    protected Connection $database,
    protected Expediteur $expediteur,
    protected Courriel $courriel,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Envoie la lettre à une seule adresse, pour la relire.
   */
  public function essai(NodeInterface $lettre, string $adresse): bool {
    $message = $this->courriel->lettre($lettre);
    $lien = Url::fromRoute('gouvernorat_lettre.page', [], ['absolute' => TRUE])->toString();
    return $this->expediteur->envoyer(
      $adresse,
      '[Essai] ' . $message['sujet'],
      str_replace(Courriel::DESABONNEMENT, $lien, $message['html']),
      str_replace(Courriel::DESABONNEMENT, $lien, $message['texte'])
    );
  }

  /**
   * Met la lettre en file pour les inscrits qui ne l'ont pas encore reçue.
   *
   * @return int
   *   Nombre de destinataires ajoutés à la file.
   */
  public function programmer(NodeInterface $lettre, int $uid): int {
    $nid = (int) $lettre->id();
    $deja = $this->database->select('gouvernorat_lettre_envoi', 'e')
      ->fields('e', ['aid'])
      ->condition('nid', $nid)
      ->execute()
      ->fetchCol();
    $requete = $this->database->select(Abonnes::TABLE, 'a')
      ->fields('a', ['aid'])
      ->condition('statut', 1);
    if ($deja) {
      $requete->condition('aid', $deja, 'NOT IN');
    }
    $destinataires = $requete->execute()->fetchCol();
    if (!$destinataires) {
      return 0;
    }

    $message = $this->courriel->lettre($lettre);
    $eid = $this->database->insert('gouvernorat_lettre_edition')->fields([
      'nid' => $nid,
      'sujet' => $message['sujet'],
      'html' => $message['html'],
      'texte' => $message['texte'],
      'uid' => $uid,
      'cree' => $this->time->getRequestTime(),
    ])->execute();

    foreach (array_chunk($destinataires, 500) as $lot) {
      $insertion = $this->database->insert('gouvernorat_lettre_envoi')
        ->fields(['eid', 'aid', 'nid', 'statut', 'essais', 'envoye']);
      foreach ($lot as $aid) {
        $insertion->values([$eid, $aid, $nid, 0, 0, 0]);
      }
      $insertion->execute();
    }
    $this->logger->notice('Lettre « @t » mise en file pour @n abonnés.', ['@t' => $message['sujet'], '@n' => count($destinataires)]);
    return count($destinataires);
  }

  /**
   * Envoie les courriels en attente, dans la limite du temps et du quota.
   *
   * @return array{envoyes: int, echecs: int, restants: int, quota: bool}
   *   Bilan du passage.
   */
  public function traiter(int $secondes = 20, int $maximum = 0): array {
    $fin = microtime(TRUE) + $secondes;
    $bilan = ['envoyes' => 0, 'echecs' => 0, 'restants' => 0, 'quota' => FALSE];
    $editions = [];

    while (microtime(TRUE) < $fin && (!$maximum || $bilan['envoyes'] + $bilan['echecs'] < $maximum)) {
      $disponible = $this->disponible();
      if ($disponible <= 0) {
        $bilan['quota'] = TRUE;
        break;
      }
      $requete = $this->database->select('gouvernorat_lettre_envoi', 'e');
      $requete->join(Abonnes::TABLE, 'a', 'a.aid = e.aid');
      $lignes = $requete->fields('e', ['eid', 'aid', 'essais'])
        ->fields('a', ['courriel', 'jeton'])
        ->condition('e.statut', 0)
        ->condition('a.statut', 1)
        ->orderBy('e.eid')
        ->orderBy('e.aid')
        ->range(0, min(10, $disponible))
        ->execute()
        ->fetchAll();
      if (!$lignes) {
        break;
      }
      foreach ($lignes as $ligne) {
        if (!isset($editions[$ligne->eid])) {
          $editions[$ligne->eid] = $this->database->select('gouvernorat_lettre_edition', 'd')
            ->fields('d', ['sujet', 'html', 'texte'])
            ->condition('eid', $ligne->eid)
            ->execute()
            ->fetchObject();
        }
        $edition = $editions[$ligne->eid];
        $lien = Url::fromRoute('gouvernorat_lettre.desabonner', ['jeton' => $ligne->jeton], ['absolute' => TRUE])->toString();
        $ok = $edition && $this->expediteur->envoyer(
          $ligne->courriel,
          $edition->sujet,
          str_replace(Courriel::DESABONNEMENT, $lien, $edition->html),
          str_replace(Courriel::DESABONNEMENT, $lien, $edition->texte),
          $lien
        );
        $this->compter();
        $essais = (int) $ligne->essais + 1;
        $this->database->update('gouvernorat_lettre_envoi')
          ->fields([
            'statut' => $ok ? 1 : ($essais >= self::ESSAIS_MAX ? 2 : 0),
            'essais' => $essais,
            'envoye' => $ok ? $this->time->getCurrentTime() : 0,
          ])
          ->condition('eid', $ligne->eid)
          ->condition('aid', $ligne->aid)
          ->execute();
        $bilan[$ok ? 'envoyes' : 'echecs']++;
        if (!$ok && !$this->expediteur->estPret()) {
          // Inutile d'insister sans mot de passe.
          break 2;
        }
      }
    }
    $bilan['restants'] = $this->restants();
    return $bilan;
  }

  /**
   * Courriels encore à envoyer, toutes lettres confondues.
   */
  public function restants(): int {
    $requete = $this->database->select('gouvernorat_lettre_envoi', 'e');
    $requete->join(Abonnes::TABLE, 'a', 'a.aid = e.aid');
    return (int) $requete->condition('e.statut', 0)
      ->condition('a.statut', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * État des envois d'une lettre : à envoyer, envoyés, échecs.
   */
  public function bilan(int $nid): array {
    $totaux = $this->database->query(
      'SELECT statut, COUNT(*) FROM {gouvernorat_lettre_envoi} WHERE nid = :nid GROUP BY statut',
      [':nid' => $nid]
    )->fetchAllKeyed();
    return [
      'attente' => (int) ($totaux[0] ?? 0),
      'envoyes' => (int) ($totaux[1] ?? 0),
      'echecs' => (int) ($totaux[2] ?? 0),
    ];
  }

  /**
   * Dernières lettres parties.
   */
  public function editions(int $nombre = 10): array {
    return $this->database->queryRange(
      'SELECT d.eid, d.nid, d.sujet, d.cree, SUM(CASE WHEN e.statut = 1 THEN 1 ELSE 0 END) AS envoyes, COUNT(e.aid) AS total
       FROM {gouvernorat_lettre_edition} d LEFT JOIN {gouvernorat_lettre_envoi} e ON e.eid = d.eid
       GROUP BY d.eid, d.nid, d.sujet, d.cree ORDER BY d.eid DESC',
      0,
      $nombre
    )->fetchAll();
  }

  /**
   * Courriels encore permis pendant l'heure en cours.
   */
  public function disponible(): int {
    $quota = (int) $this->configFactory->get('gouvernorat_lettre.settings')->get('quota_heure') ?: 300;
    $compteur = $this->state->get('gouvernorat_lettre.compteur', ['heure' => 0, 'n' => 0]);
    $heure = intdiv($this->time->getCurrentTime(), 3600);
    return $compteur['heure'] === $heure ? max(0, $quota - $compteur['n']) : $quota;
  }

  /**
   * Compte un courriel parti pendant l'heure en cours.
   */
  protected function compter(): void {
    $heure = intdiv($this->time->getCurrentTime(), 3600);
    $compteur = $this->state->get('gouvernorat_lettre.compteur', ['heure' => 0, 'n' => 0]);
    if ($compteur['heure'] !== $heure) {
      $compteur = ['heure' => $heure, 'n' => 0];
    }
    $compteur['n']++;
    $this->state->set('gouvernorat_lettre.compteur', $compteur);
  }

}
