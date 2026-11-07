/**
 * @file
 * Fenêtre de l'assistant virtuel.
 *
 * Construit la bulle et la fenêtre de discussion, envoie la conversation à
 * /assistant/message et affiche les réponses. Le texte reçu est toujours
 * échappé : seuls le gras, les listes et les liens vers des pages du site
 * sont mis en forme.
 *
 * La conversation est gardée le temps de l'onglet (sessionStorage), pour
 * survivre à un changement de page, et jamais au-delà.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var CLE = 'gouvernoratAssistant.conversation';
  var MAX_QUESTION = 800;

  function lire() {
    try {
      var donnees = JSON.parse(window.sessionStorage.getItem(CLE) || '[]');
      return Array.isArray(donnees) ? donnees : [];
    }
    catch (e) {
      return [];
    }
  }

  function ecrire(conversation) {
    try {
      window.sessionStorage.setItem(CLE, JSON.stringify(conversation.slice(-20)));
    }
    catch (e) {
      // Stockage indisponible (navigation privée) : la conversation vit
      // seulement le temps de la page.
    }
  }

  function echapper(texte) {
    return texte
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * Mise en forme minimale et sûre : paragraphes, listes « - », **gras**,
   * liens [texte](/chemin) vers le site uniquement.
   */
  function miseEnForme(texte) {
    var html = '';
    var liste = false;
    echapper(texte).split(/\n/).forEach(function (ligne) {
      ligne = ligne.trim();
      var element = ligne.match(/^[-•*]\s+(.*)$/);
      if (element) {
        if (!liste) {
          html += '<ul>';
          liste = true;
        }
        html += '<li>' + enLigne(element[1]) + '</li>';
        return;
      }
      if (liste) {
        html += '</ul>';
        liste = false;
      }
      if (ligne !== '') {
        html += '<p>' + enLigne(ligne) + '</p>';
      }
    });
    if (liste) {
      html += '</ul>';
    }
    return html;
  }

  function enLigne(texte) {
    return texte
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\[([^\]]+)\]\((\/[^)\s]*)\)/g, function (tout, libelle, chemin) {
        // Chemins internes seulement : « / » mais ni « // » ni « \ », que
        // certains navigateurs lisent comme l'adresse d'un autre site.
        if (chemin.indexOf('//') === 0 || chemin.indexOf('\\') !== -1) {
          return libelle;
        }
        return '<a href="' + chemin + '">' + libelle + '</a>';
      });
  }

  Drupal.behaviors.gouvernoratAssistant = {
    attach: function (context) {
      var reglages = drupalSettings.gouvernoratAssistant;
      if (!reglages || !once('gouvernorat-assistant', 'body', context).length) {
        return;
      }
      document.body.classList.add('avec-assistant');

      var conversation = lire();
      var occupe = false;

      var racine = document.createElement('div');
      racine.className = 'assistant';
      racine.innerHTML =
        '<button type="button" class="assistant__bulle" aria-expanded="false" aria-controls="assistant-fenetre">' +
          '<span class="assistant__bulle-icone" aria-hidden="true"><i class="fas fa-comments"></i></span>' +
          '<span class="assistant__bulle-texte">' + Drupal.t('Une question ?') + '</span>' +
        '</button>' +
        '<section class="assistant__fenetre" id="assistant-fenetre" role="dialog" aria-modal="false" aria-labelledby="assistant-titre" hidden>' +
          '<header class="assistant__entete">' +
            '<div>' +
              '<h2 class="assistant__titre" id="assistant-titre">' + Drupal.t('Assistant du Gouvernorat') + '</h2>' +
              '<p class="assistant__sous-titre">' + Drupal.t('Réponses automatiques, à vérifier sur le site') + '</p>' +
            '</div>' +
            '<button type="button" class="assistant__fermer" aria-label="' + Drupal.t('Fermer l’assistant') + '"><i class="fas fa-times" aria-hidden="true"></i></button>' +
          '</header>' +
          '<div class="assistant__fil" role="log" aria-live="polite" aria-relevant="additions"></div>' +
          '<form class="assistant__formulaire">' +
            '<label class="visually-hidden" for="assistant-question">' + Drupal.t('Votre question') + '</label>' +
            '<textarea id="assistant-question" class="assistant__champ" rows="1" maxlength="' + MAX_QUESTION + '" placeholder="' + Drupal.t('Écrivez votre question…') + '"></textarea>' +
            '<button type="submit" class="assistant__envoyer" aria-label="' + Drupal.t('Envoyer') + '"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>' +
          '</form>' +
        '</section>';
      document.body.appendChild(racine);

      var bulle = racine.querySelector('.assistant__bulle');
      var fenetre = racine.querySelector('.assistant__fenetre');
      var fil = racine.querySelector('.assistant__fil');
      var formulaire = racine.querySelector('.assistant__formulaire');
      var champ = racine.querySelector('.assistant__champ');
      var envoyer = racine.querySelector('.assistant__envoyer');

      function ajouter(role, texte, classe) {
        var message = document.createElement('div');
        message.className = 'assistant__message assistant__message--' + role + (classe ? ' ' + classe : '');
        if (role === 'assistant') {
          message.innerHTML = miseEnForme(texte);
        }
        else {
          message.textContent = texte;
        }
        fil.appendChild(message);
        fil.scrollTop = fil.scrollHeight;
        return message;
      }

      function afficherConversation() {
        fil.innerHTML = '';
        ajouter('assistant', reglages.accueil);
        conversation.forEach(function (m) {
          ajouter(m.role, m.content);
        });
        if (!conversation.length && (reglages.suggestions || []).length) {
          var liste = document.createElement('ul');
          liste.className = 'assistant__suggestions';
          liste.setAttribute('aria-label', Drupal.t('Questions suggérées'));
          reglages.suggestions.forEach(function (texte) {
            var element = document.createElement('li');
            var bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = 'assistant__suggestion';
            bouton.textContent = texte;
            bouton.addEventListener('click', function () {
              liste.remove();
              champ.value = texte;
              formulaire.requestSubmit();
            });
            element.appendChild(bouton);
            liste.appendChild(element);
          });
          fil.appendChild(liste);
        }
      }

      // Téléphones : fenêtre plein écran, ajustée à la zone visible.
      var telephone = window.matchMedia('(max-width: 575.98px)');
      var vue = window.visualViewport;

      // Quand le clavier s'ouvre, la zone visible rétrécit : la fenêtre la
      // suit pour que le champ de saisie et la dernière réponse restent
      // visibles au-dessus du clavier.
      function ajuster() {
        if (!telephone.matches || !vue || fenetre.hidden) {
          fenetre.style.height = '';
          fenetre.style.top = '';
          return;
        }
        fenetre.style.height = vue.height + 'px';
        fenetre.style.top = vue.offsetTop + 'px';
        fil.scrollTop = fil.scrollHeight;
      }
      if (vue) {
        vue.addEventListener('resize', ajuster);
        vue.addEventListener('scroll', ajuster);
      }

      function ouvrir() {
        fenetre.hidden = false;
        bulle.setAttribute('aria-expanded', 'true');
        racine.classList.add('assistant--ouvert');
        document.body.classList.toggle('assistant-plein-ecran', telephone.matches);
        afficherConversation();
        ajuster();
        // Sur téléphone, le clavier n'est pas ouvert d'office : il cacherait
        // le message d'accueil et les questions suggérées.
        if (telephone.matches) {
          racine.querySelector('.assistant__fermer').focus();
        }
        else {
          champ.focus();
        }
      }

      function fermer() {
        fenetre.hidden = true;
        bulle.setAttribute('aria-expanded', 'false');
        racine.classList.remove('assistant--ouvert');
        document.body.classList.remove('assistant-plein-ecran');
        ajuster();
        bulle.focus();
      }

      bulle.addEventListener('click', function () {
        if (fenetre.hidden) {
          ouvrir();
        }
        else {
          fermer();
        }
      });
      racine.querySelector('.assistant__fermer').addEventListener('click', fermer);
      fenetre.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          fermer();
        }
      });

      // Entrée envoie, Maj+Entrée passe à la ligne ; le champ grandit avec
      // le texte.
      champ.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          formulaire.requestSubmit();
        }
      });
      champ.addEventListener('input', function () {
        champ.style.height = 'auto';
        champ.style.height = Math.min(champ.scrollHeight, 120) + 'px';
      });

      formulaire.addEventListener('submit', function (e) {
        e.preventDefault();
        var question = champ.value.trim();
        if (!question || occupe) {
          return;
        }
        occupe = true;
        envoyer.disabled = true;
        var suggestions = fil.querySelector('.assistant__suggestions');
        if (suggestions) {
          suggestions.remove();
        }
        champ.value = '';
        champ.style.height = 'auto';

        ajouter('user', question);
        conversation.push({ role: 'user', content: question });
        var attente = ajouter('assistant', Drupal.t('Je cherche…'), 'assistant__message--attente');

        fetch(reglages.adresse, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Assistant': '1' },
          body: JSON.stringify({ messages: conversation })
        })
          .then(function (r) {
            return r.json().catch(function () {
              return { erreur: Drupal.t('L’assistant n’a pas pu répondre. Merci de réessayer.') };
            });
          })
          .then(function (donnees) {
            attente.remove();
            if (donnees.reponse) {
              ajouter('assistant', donnees.reponse);
              // La signature du serveur accompagne la réponse quand elle est
              // renvoyée avec la suite de la conversation.
              conversation.push({ role: 'assistant', content: donnees.reponse, signature: donnees.signature || '' });
            }
            else {
              // La question restée sans réponse est retirée de l'historique
              // pour que la conversation garde l'alternance question/réponse.
              conversation.pop();
              ajouter('assistant', donnees.erreur || Drupal.t('L’assistant n’a pas pu répondre. Merci de réessayer.'), 'assistant__message--erreur');
            }
            ecrire(conversation);
          })
          .catch(function () {
            attente.remove();
            conversation.pop();
            ajouter('assistant', Drupal.t('Connexion impossible. Vérifiez votre accès à Internet et réessayez.'), 'assistant__message--erreur');
          })
          .finally(function () {
            occupe = false;
            envoyer.disabled = false;
            champ.focus();
          });
      });
    }
  };

})(Drupal, drupalSettings, once);
