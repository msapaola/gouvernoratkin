/**
 * Barre latérale des services aux citoyens : dépliage des rubriques.
 *
 * Chaque rubrique de 2e niveau porte un lien (afficher toutes ses démarches)
 * et, à côté, un bouton chevron qui déplie ses sous-rubriques. Le script ne
 * gère que ce bouton : le lien navigue normalement.
 *
 * Le gabarit (layout/partials/services-sidebar.html.twig) pose la classe
 * .expanded et aria-expanded au chargement pour la rubrique courante.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var boutons = document.querySelectorAll('.services-sidebar .sidebar-parent-toggle');

    Array.prototype.forEach.call(boutons, function (bouton) {
      bouton.addEventListener('click', function () {
        var parent = bouton.closest('.sidebar-parent-item');
        if (!parent || !parent.querySelector('.sidebar-child-list')) {
          return;
        }

        var ouvert = parent.classList.toggle('expanded');
        bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');

        var icone = bouton.querySelector('i');
        if (icone) {
          icone.className = ouvert ? 'fas fa-chevron-down' : 'fas fa-chevron-right';
        }
      });
    });
  });
})();
