/**
 * Barre latérale des services aux citoyens : dépliage des catégories.
 *
 * Ce script vivait en clair dans page--services-aux-citoyens.html.twig. Il
 * rejoint le thème pour être versionné et chargé une seule fois, avec la
 * bibliothèque gouvernoratkin/services.
 *
 * Trois corrections par rapport à la version d'origine :
 * - l'entête de catégorie était un <div> muni d'un écouteur de clic, donc
 *   inatteignable au clavier ; c'est désormais un <button> annoncé par
 *   aria-expanded, que le gabarit produit ;
 * - l'état déplié n'était porté que par un style en ligne ; il passe par la
 *   classe .expanded, ce qui laisse la feuille de styles décider du rendu ;
 * - le script s'exécutait même sur les pages sans barre latérale.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var entetes = document.querySelectorAll('.services-sidebar .sidebar-parent-header');

    Array.prototype.forEach.call(entetes, function (entete) {
      entete.addEventListener('click', function () {
        var parent = entete.closest('.sidebar-parent-item');
        var enfants = parent ? parent.querySelector('.sidebar-child-list') : null;
        if (!enfants) {
          return;
        }

        var ouvert = parent.classList.toggle('expanded');
        entete.setAttribute('aria-expanded', ouvert ? 'true' : 'false');

        var icone = entete.querySelector('.cat-toggle-icon i');
        if (icone) {
          icone.className = ouvert ? 'fas fa-chevron-down' : 'fas fa-chevron-right';
        }
      });
    });
  });
})();
