<?php

namespace Drupal\gouvernorat_lettre\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Met en forme les courriels de la lettre (HTML et texte brut).
 */
class Courriel {

  /**
   * Marque remplacée, pour chaque abonné, par son lien de désabonnement.
   */
  const DESABONNEMENT = '%%LIEN_DESABONNEMENT%%';

  public function __construct(
    protected RendererInterface $renderer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ThemeExtensionList $themeList,
  ) {}

  /**
   * Courriel demandant de confirmer l'inscription.
   *
   * @return array{sujet: string, html: string, texte: string}
   *   Le courriel.
   */
  public function confirmation(string $lien): array {
    $sujet = "Confirmez votre inscription à la lettre d'information";
    $corps = '<p>Bonjour,</p>'
      . "<p>Vous avez demandé à recevoir la lettre d'information du Gouvernorat de la Ville-Province de Kinshasa. Pour valider votre inscription, cliquez sur le bouton ci-dessous.</p>";
    $apres = "<p>Si vous n'êtes pas à l'origine de cette demande, ignorez simplement ce message : vous ne recevrez rien.</p>";
    return $this->composer($sujet, $corps, [], ['texte' => 'Confirmer mon inscription', 'url' => $lien], $apres, FALSE);
  }

  /**
   * Courriel d'une lettre. Le lien de désabonnement est une marque à remplacer.
   *
   * @return array{sujet: string, html: string, texte: string}
   *   Le courriel.
   */
  public function lettre(NodeInterface $lettre): array {
    $corps = '';
    if ($lettre->hasField('body') && !$lettre->get('body')->isEmpty()) {
      $element = $lettre->get('body')->view(['label' => 'hidden']);
      $corps = (string) $this->renderer->renderInIsolation($element);
    }
    $articles = [];
    if ($lettre->hasField('field_lettre_articles')) {
      foreach ($lettre->get('field_lettre_articles')->referencedEntities() as $article) {
        if ($article instanceof NodeInterface && $article->isPublished()) {
          $articles[] = $this->article($article);
        }
      }
    }
    $bouton = ['texte' => 'Toutes les actualités', 'url' => $this->absolu('/actualites')];
    return $this->composer($lettre->label(), $corps, $articles, $bouton, '', TRUE);
  }

  /**
   * Assemble le courriel à partir du gabarit.
   */
  protected function composer(string $sujet, string $corps, array $articles, ?array $bouton, string $apres, bool $avecDesabonnement): array {
    $accueil = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    $corps = $this->adapter($corps, $accueil);
    $apres = $this->adapter($apres, $accueil);

    $element = [
      '#theme' => 'gouvernorat_lettre_courriel',
      '#titre' => $sujet,
      '#corps' => Markup::create($corps),
      '#articles' => $articles,
      '#bouton' => $bouton,
      '#apres' => Markup::create($apres),
      '#accueil' => $accueil,
      '#logo' => $this->absolu('/' . $this->themeList->getPath('gouvernoratkin') . '/assets/images/logo1.png'),
      '#desabonnement' => $avecDesabonnement ? self::DESABONNEMENT : NULL,
      '#annee' => date('Y'),
    ];
    $html = (string) $this->renderer->renderInIsolation($element);

    // Version texte : lue par les messageries qui n'affichent pas le HTML.
    $texte = $sujet . "\n\n" . MailFormatHelper::htmlToText($corps);
    foreach ($articles as $article) {
      $texte .= "\n* " . $article['titre'] . "\n  " . $article['url'] . "\n";
    }
    if ($bouton) {
      $texte .= "\n" . $bouton['texte'] . ' : ' . $bouton['url'] . "\n";
    }
    if ($apres !== '') {
      $texte .= "\n" . MailFormatHelper::htmlToText($apres);
    }
    $texte .= "\n--\nGouvernorat de la Ville-Province de Kinshasa\n" . $accueil . "\n";
    if ($avecDesabonnement) {
      $texte .= "Se désabonner : " . self::DESABONNEMENT . "\n";
    }
    return ['sujet' => $sujet, 'html' => $html, 'texte' => $texte];
  }

  /**
   * Résumé d'un article pour la lettre.
   */
  protected function article(NodeInterface $article): array {
    $image = NULL;
    if ($article->hasField('field_image_media') && ($media = $article->get('field_image_media')->entity)) {
      $fid = $media->getSource()->getSourceFieldValue($media);
      $fichier = $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
      if ($fichier) {
        $style = $this->entityTypeManager->getStorage('image_style')->load('large');
        $image = $style ? $style->buildUrl($fichier->getFileUri()) : $this->fileUrlGenerator->generateAbsoluteString($fichier->getFileUri());
      }
    }
    $resume = '';
    if ($article->hasField('body') && !$article->get('body')->isEmpty()) {
      $valeur = $article->get('body')->first();
      $resume = trim((string) $valeur->summary) ?: (string) $valeur->value;
      $resume = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($resume), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
      $resume = Unicode::truncate($resume, 220, TRUE, TRUE);
    }
    return [
      'titre' => $article->label(),
      'url' => $article->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'image' => $image,
      'resume' => $resume,
      'date' => \Drupal::service('date.formatter')->format($article->getCreatedTime(), 'custom', 'j F Y', NULL, 'fr'),
    ];
  }

  /**
   * Adresses absolues et styles en ligne, comme l'exigent les messageries.
   */
  protected function adapter(string $html, string $accueil): string {
    if (trim($html) === '') {
      return '';
    }
    $html = Html::transformRootRelativeUrlsToAbsolute($html, rtrim($accueil, '/'));
    $dom = Html::load($html);
    $styles = [
      'p' => 'margin:0 0 16px;font-size:16px;line-height:1.65;color:#2f3540;',
      'h2' => 'margin:28px 0 12px;font-size:21px;line-height:1.3;color:#104293;',
      'h3' => 'margin:24px 0 10px;font-size:18px;line-height:1.3;color:#1b1f26;',
      'h4' => 'margin:20px 0 8px;font-size:14px;letter-spacing:1px;text-transform:uppercase;color:#00719a;',
      'ul' => 'margin:0 0 16px;padding-left:22px;',
      'ol' => 'margin:0 0 16px;padding-left:22px;',
      'li' => 'margin:0 0 6px;font-size:16px;line-height:1.6;color:#2f3540;',
      'a' => 'color:#104293;font-weight:bold;',
      'blockquote' => 'margin:20px 0;padding:14px 18px;border-left:4px solid #ce1126;background:#f4f7fc;font-style:italic;',
      'img' => 'max-width:100%;height:auto;border:0;border-radius:8px;',
      'table' => 'width:100%;border-collapse:collapse;margin:0 0 18px;font-size:14px;',
      'th' => 'background:#104293;color:#ffffff;padding:8px;text-align:left;',
      'td' => 'border-top:1px solid #e6ebf2;padding:8px;',
      'figcaption' => 'font-size:13px;color:#5b6472;font-style:italic;text-align:center;',
    ];
    foreach ($styles as $balise => $style) {
      foreach ($dom->getElementsByTagName($balise) as $noeud) {
        $noeud->setAttribute('style', $style . $noeud->getAttribute('style'));
        if ($balise === 'img') {
          $noeud->removeAttribute('loading');
        }
      }
    }
    return Html::serialize($dom);
  }

  /**
   * Adresse absolue d'un chemin du site.
   */
  protected function absolu(string $chemin): string {
    return Url::fromUserInput($chemin, ['absolute' => TRUE])->toString();
  }

}
