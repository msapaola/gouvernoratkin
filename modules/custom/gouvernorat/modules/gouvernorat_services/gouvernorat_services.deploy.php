<?php

/**
 * @file
 * Import des démarches « Droits, Taxes et Redevances ».
 *
 * À lancer après l'import de la configuration :
 *   vendor/bin/drush deploy:hook -y
 * Chaque hook ne s'exécute qu'une fois.
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Normalise un libellé pour le comparer : casse, accents, apostrophes, espaces.
 */
function _gouvernorat_services_cle(string $texte): string {
  $texte = str_replace(['’', '‘', '«', '»'], ["'", "'", '', ''], $texte);
  $texte = \Drupal::service('transliteration')->transliterate($texte, 'fr');
  return trim(preg_replace('/\s+/', ' ', mb_strtolower($texte)));
}

/**
 * Importe un fichier de démarches extrait d'un document Word.
 *
 * Le fichier (data/*.json) décrit des secteurs, chacun avec sa présentation
 * et ses articles : titre, résumé (champ « Résumé ») et corps.
 *
 * - Une démarche déjà présente (retrouvée par son titre) reçoit le nouveau
 *   texte dans une nouvelle révision ; son titre, son adresse et son statut
 *   ne changent pas.
 * - Une démarche absente est créée et publiée, dans l'ordre du document.
 * - Un secteur absent est créé sous la rubrique si $creer_secteurs est vrai ;
 *   sinon ses démarches sont signalées et ignorées.
 * - La présentation du secteur devient la description de son terme, si
 *   celle-ci est vide.
 */
function _gouvernorat_services_importer(string $fichier, bool $creer_secteurs): string {
  $chemin = \Drupal::service('extension.list.module')->getPath('gouvernorat_services') . '/data/' . $fichier;
  $donnees = json_decode(file_get_contents($chemin), TRUE);

  $termes = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'service_au_citoyen']);
  $par_nom = [];
  foreach ($termes as $terme) {
    $par_nom[_gouvernorat_services_cle($terme->label())] = $terme;
  }
  $rubrique = $par_nom[_gouvernorat_services_cle($donnees['rubrique'])] ?? NULL;

  $par_titre = [];
  foreach (\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'service_au_citoyen']) as $node) {
    $par_titre[_gouvernorat_services_cle($node->label())] = $node;
  }

  $rapport = ['maj' => 0, 'creees' => 0, 'secteurs' => 0, 'descriptions' => 0];
  $problemes = [];
  // La liste des services est triée de la plus récente à la plus ancienne :
  // les démarches créées reçoivent des dates décroissantes, de sorte que la
  // liste suive l'ordre du document.
  $date = \Drupal::time()->getRequestTime();

  foreach ($donnees['secteurs'] as $position => $secteur) {
    $terme = $par_nom[_gouvernorat_services_cle($secteur['secteur'])] ?? NULL;

    if (!$terme && $creer_secteurs && $rubrique) {
      $terme = Term::create([
        'vid' => 'service_au_citoyen',
        'name' => $secteur['secteur'],
        'parent' => $rubrique->id(),
        'weight' => 10 + $position,
      ]);
      $terme->save();
      $par_nom[_gouvernorat_services_cle($terme->label())] = $terme;
      $rapport['secteurs']++;
    }
    if (!$terme) {
      $problemes[] = 'secteur introuvable : ' . $secteur['secteur'] . ' (' . count($secteur['articles']) . ' démarches non importées)';
      continue;
    }

    if ($secteur['introduction'] && trim((string) $terme->getDescription()) === '') {
      $terme->setDescription($secteur['introduction'])->setFormat('full_html')->save();
      $rapport['descriptions']++;
    }

    foreach ($secteur['articles'] as $article) {
      $node = NULL;
      foreach (array_filter([$article['titre_existant'], $article['titre_nouveau'], $article['titre_document']]) as $titre) {
        if ($node = $par_titre[_gouvernorat_services_cle($titre)] ?? NULL) {
          break;
        }
      }

      if ($node) {
        // Les doubles espaces de certains titres saisis à la main disparaissent.
        $node->setTitle(trim(preg_replace('/\s+/u', ' ', $node->label())));
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage('Texte mis à jour depuis ' . $donnees['source'] . '.');
        $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        $rapport['maj']++;
      }
      else {
        $node = Node::create([
          'type' => 'service_au_citoyen',
          'title' => $article['titre_nouveau'] ?? $article['titre_document'],
          'uid' => 1,
          'status' => 1,
          'created' => $date--,
        ]);
        $node->setRevisionLogMessage('Créée depuis ' . $donnees['source'] . '.');
        $par_titre[_gouvernorat_services_cle($node->label())] = $node;
        $rapport['creees']++;
      }

      $node->set('body', [
        'value' => $article['corps'],
        'summary' => $article['resume'],
        'format' => 'full_html',
      ]);
      $node->set('field_service_au_citoyen', $terme->id());
      $node->save();
    }
  }

  $message = sprintf('%s : %d démarches mises à jour, %d créées, %d secteurs créés, %d descriptions de secteur ajoutées.',
    $donnees['source'], $rapport['maj'], $rapport['creees'], $rapport['secteurs'], $rapport['descriptions']);
  if ($problemes) {
    $message .= ' À vérifier : ' . implode(' ; ', $problemes) . '.';
  }
  return $message;
}

/**
 * Importe les 19 démarches du document Articles_Taxes_Ville_de_Kinshasa_v2.
 *
 * Secteurs Économie, Justice, Finances, Travaux publics, déjà présents sur le
 * site. Les 13 démarches déjà publiées sont retrouvées par leur titre actuel
 * (titre_existant) ; les lignes « [À COMPLÉTER] » n'ont pas été reprises.
 */
function gouvernorat_services_deploy_droits_taxes_redevances() {
  return _gouvernorat_services_importer('droits_taxes_redevances.json', FALSE);
}

/**
 * Importe les 159 démarches du document « Secteurs complémentaires ».
 *
 * 22 secteurs et « Impôts et autres recettes de la Ville », créés sous
 * « Droits, Taxes et Redevances » s'ils n'existent pas. Deux intitulés
 * officiels de plus de 255 caractères reçoivent un titre abrégé ; l'intitulé
 * complet figure en tête de leur texte.
 */
function gouvernorat_services_deploy_secteurs_complementaires() {
  return _gouvernorat_services_importer('secteurs_complementaires.json', TRUE);
}
