/**
 * @file
 * Lecteur YouTube chargé au clic.
 *
 * Chaque vidéo s'affiche d'abord sous forme de miniature avec un bouton de
 * lecture (.video-lecteur). Le lecteur YouTube n'est inséré qu'au clic : une
 * page qui en intégrerait cinq d'emblée chargerait plusieurs mégaoctets de
 * scripts, même si personne ne regarde. L'adresse du lecteur, en mode
 * confidentialité renforcée (youtube-nocookie.com), est fournie par le
 * gabarit dans data-lecteur.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.gouvernoratVideos = {
    attach(context) {
      once('video-lecteur', '.video-lecteur__bouton', context).forEach((bouton) => {
        bouton.addEventListener('click', () => {
          const lecteur = bouton.closest('.video-lecteur');
          const iframe = document.createElement('iframe');
          iframe.src = lecteur.dataset.lecteur;
          iframe.title = lecteur.dataset.titre || Drupal.t('Vidéo YouTube');
          iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
          iframe.referrerPolicy = 'strict-origin-when-cross-origin';
          iframe.allowFullscreen = true;
          lecteur.replaceChildren(iframe);
          lecteur.classList.add('video-lecteur--actif');
          iframe.focus();
        });
      });
    },
  };
})(Drupal, once);
