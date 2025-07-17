/*
document.addEventListener('DOMContentLoaded', function() {
    // Sélectionner le conteneur du carousel
    let $carouselContainer = $('#block-gouvernoratkin-views-block-last-news-block-1 .view-content');
    
    // Ajouter la classe owl-carousel si elle n'existe pas déjà
    if (!$carouselContainer.hasClass('service-slider-area-1') && !$carouselContainer.hasClass('owl-carousel') ) {
        $carouselContainer.addClass('service-slider-area-1');
        $carouselContainer.addClass('owl-carousel');
    }
    
});
*/

document.addEventListener('DOMContentLoaded', function() {
      AOS.init({
        once: true,
        offset: 100,
        duration: 800
      });
      
      // Initialisation du carousel
      var carousel = new bootstrap.Carousel(document.getElementById('aboutCarousel'), {
        interval: 5000,
        wrap: true,
        keyboard: true
      });
    });