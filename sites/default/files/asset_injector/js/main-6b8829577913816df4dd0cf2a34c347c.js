function limitText(selector, limit = 70) {
  // Sélectionne tous les éléments correspondant au sélecteur
  document.querySelectorAll(selector).forEach(element => {
    let text = element.textContent;
    if (text.length > limit) {
      // Tronque le texte à 'limit' caractères et ajoute '...'
      element.textContent = text.slice(0, limit) + (text.length > limit ? '...' : '');
    }
  });
}

// Utilisation de la fonction
document.addEventListener('DOMContentLoaded', function() {
  limitText('.events-card .events-card-text h4 a');
});