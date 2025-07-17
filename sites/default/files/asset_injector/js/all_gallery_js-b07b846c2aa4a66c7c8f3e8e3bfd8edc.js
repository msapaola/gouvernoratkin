$(document).ready(function() {
    // Initialiser tous les sliders sans dépendre d'une classe spécifique
    $('.image-slider ul').each(function(index) {
        var $slider = $(this);
        
        // Configuration du slider avec un délai différent pour chaque slider
        $slider.slick({
            dots: true,
            arrows: true,
            infinite: true,
            speed: 500,
            autoplay: true,
            autoplaySpeed: 3000 + (index * 500), // Différents délais pour chaque slider
            slidesToShow: 1,
            slidesToScroll: 1,
            adaptiveHeight: false,
            prevArrow: '<button type="button" class="slick-prev"><i class="bi bi-chevron-left"></i></button>',
            nextArrow: '<button type="button" class="slick-next"><i class="bi bi-chevron-right"></i></button>',
            pauseOnHover: false,
            pauseOnFocus: false
        });
        
        // Empêcher la navigation lorsqu'on clique sur les contrôles du slider
        $('.slick-prev, .slick-next, .slick-dots li button', $slider.parent()).on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });
    });
    
    // Empêcher la propagation des clics sur les sliders
    $('.image-slider').on('click', function(e) {
        e.stopPropagation();
    });
});