<?php

namespace Drupal\gouvernorat_lettre\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\gouvernorat_lettre\Service\Abonnes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administration : abonnés et lettres envoyées.
 */
class AdminController extends ControllerBase {

  /**
   * Page « Contenu › Lettre d'information ».
   */
  public function abonnes(Request $request): array {
    $abonnes = \Drupal::service('gouvernorat_lettre.abonnes');
    $envois = \Drupal::service('gouvernorat_lettre.envois');
    $expediteur = \Drupal::service('gouvernorat_lettre.expediteur');
    $formatter = \Drupal::service('date.formatter');
    $totaux = $abonnes->compter();
    $build = ['#cache' => ['max-age' => 0]];

    if (!$expediteur->estPret()) {
      $build['alerte'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => ["Le mot de passe de la boîte lettre@kinshasa.cd n'est pas renseigné dans settings.php : aucun courriel ne peut partir (ni confirmation d'inscription, ni lettre)."]],
        '#status_headings' => ['warning' => 'Attention'],
      ];
    }

    $build['resume'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => sprintf(
        '<strong>%d</strong> abonné%s confirmé%s, <strong>%d</strong> en attente de confirmation. Courriels encore en file : <strong>%d</strong>.',
        $totaux['inscrits'], $totaux['inscrits'] > 1 ? 's' : '', $totaux['inscrits'] > 1 ? 's' : '',
        $totaux['attente'], $envois->restants()
      ),
    ];

    // Lettres envoyées.
    $lignes = [];
    foreach ($envois->editions(10) as $edition) {
      $lignes[] = [
        $formatter->format($edition->cree, 'short'),
        Link::fromTextAndUrl($edition->sujet, Url::fromRoute('gouvernorat_lettre.envoi', ['node' => $edition->nid])),
        $edition->envoyes . ' / ' . $edition->total,
      ];
    }
    $build['editions'] = [
      '#type' => 'details',
      '#title' => 'Dernières lettres envoyées',
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => ['Date', 'Lettre', 'Envoyés'],
        '#rows' => $lignes,
        '#empty' => "Aucune lettre envoyée pour l'instant. Rédigez-en une avec le bouton « Rédiger une lettre », puis ouvrez son onglet « Envoyer ».",
      ],
    ];

    // Abonnés.
    $recherche = trim((string) $request->query->get('q', ''));
    $requete = \Drupal::database()->select(Abonnes::TABLE, 'a')
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    $requete->fields('a')->orderBy('aid', 'DESC');
    if ($recherche !== '') {
      $requete->condition('courriel', '%' . \Drupal::database()->escapeLike(mb_strtolower($recherche)) . '%', 'LIKE');
    }
    $lignes = [];
    foreach ($requete->execute() as $abonne) {
      $lignes[] = [
        $abonne->courriel,
        (int) $abonne->statut === 1 ? 'Confirmé' : 'En attente',
        $abonne->origine === 'ajout' ? 'Ajouté par la rédaction' : 'Formulaire du site',
        $formatter->format($abonne->cree, 'short'),
        Link::fromTextAndUrl('Supprimer', Url::fromRoute('gouvernorat_lettre.supprimer', ['aid' => $abonne->aid])),
      ];
    }
    $build['abonnes'] = [
      '#type' => 'details',
      '#title' => 'Abonnés',
      '#open' => TRUE,
      'recherche' => [
        '#type' => 'html_tag',
        '#tag' => 'form',
        '#attributes' => ['method' => 'get', 'class' => ['form--inline', 'clearfix']],
        'champ' => [
          '#type' => 'html_tag',
          '#tag' => 'input',
          '#attributes' => [
            'type' => 'search',
            'name' => 'q',
            'value' => $recherche,
            'placeholder' => 'Rechercher une adresse',
            'class' => ['form-text', 'form-element'],
            'aria-label' => 'Rechercher une adresse',
          ],
        ],
        'bouton' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => 'Rechercher',
          '#attributes' => ['type' => 'submit', 'class' => ['button']],
        ],
      ],
      'export' => [
        '#type' => 'link',
        '#title' => 'Exporter la liste (CSV)',
        '#url' => Url::fromRoute('gouvernorat_lettre.export'),
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => ['Adresse', 'Statut', 'Origine', 'Inscrit le', ''],
        '#rows' => $lignes,
        '#empty' => 'Aucun abonné.',
      ],
      'pager' => ['#type' => 'pager'],
    ];
    return $build;
  }

  /**
   * Liste des abonnés confirmés au format CSV.
   */
  public function export(): StreamedResponse {
    $reponse = new StreamedResponse(function () {
      $sortie = fopen('php://output', 'w');
      fwrite($sortie, "\xEF\xBB\xBF");
      fputcsv($sortie, ['courriel', 'statut', 'origine', 'inscrit_le', 'confirme_le'], ';');
      $resultat = \Drupal::database()->select(Abonnes::TABLE, 'a')
        ->fields('a')
        ->orderBy('aid')
        ->execute();
      foreach ($resultat as $abonne) {
        fputcsv($sortie, [
          $abonne->courriel,
          (int) $abonne->statut === 1 ? 'confirme' : 'en_attente',
          $abonne->origine,
          date('Y-m-d H:i', (int) $abonne->cree),
          $abonne->confirme ? date('Y-m-d H:i', (int) $abonne->confirme) : '',
        ], ';');
      }
      fclose($sortie);
    });
    $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $reponse->headers->set('Content-Disposition', 'attachment; filename="abonnes-lettre-' . date('Y-m-d') . '.csv"');
    $reponse->headers->set('Cache-Control', 'no-store');
    return $reponse;
  }

}
