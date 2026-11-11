(function ($) {
    "use strict";
    
    
     $(".js-view-dom-id-fcd8b2fe750b83aa3f8678e64996c5fd13f6af87a8bd9eb66af6c83408818f51 .view-content").addClass("service-slider-area-1");

    // for sticky navbar
    /*
    $(window).scroll(function () {
        if ($(window).scrollTop() > 0) {
            $(".navbar-area").addClass("sticky");
        } else {
            $(".navbar-area").removeClass("sticky");
        }
    });
    
    $(window).scroll(function () {
        if ($(window).scrollTop() > 0) {
            $(".navbar-area .main-nav").addClass("sticky");
        } else {
            $(".navbar-area .main-nav").removeClass("sticky");
        }
    }); 
    */

    // popup button
    $('.popup-button').click(function () {
        $('.popup').css('visibility', 'visible');
        $('.popup-content').addClass('hi');
    })
    $('#popup-close').click(function () {
        $('.popup').css('visibility', 'hidden');
        $('.popup-content').removeClass('hi');
    })

    // Mean Menu
    $(".mean-menu").meanmenu({
        meanScreenWidth: "1199",
    });
    
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

   $(document).ready(function() {
        // Initialisation du carousel
        $(".service-slider-area-1").owlCarousel({
            autoplayHoverPause: true,
            autoplaySpeed: 2000,
            autoplay: true,
            loop: true,
            dots: false,
            nav: true,
            navText: ['<i class="fas fa-long-arrow-alt-left"></i>', '<i class="fas fa-long-arrow-alt-right"></i>'],
            responsive: {
                0: {
                    items: 1,
                },
                768: {
                    items: 2,
                },
                992: {
                    items: 2,
                },
                1200: {
                    items: 3,
                },
            }
        });
    });
    
     $(".gallery-slider").owlCarousel({
    loop: true,
    margin: 20,
    nav: true,
    dots: true,
    autoplay: true,
    autoplayTimeout: 5000,
    autoplayHoverPause: true,
    navText: ['<span>&lt;</span>', '<span>&gt;</span>'],
    responsive: {
        0: {
            items: 1
        },
        600: {
            items: 2
        },
        1000: {
            items: 3
        }
    },
    touchDrag: true,
    mouseDrag: true
});

       

    $(".news-slider-area-1").owlCarousel({
        autoplayHoverPause: true,
        autoplaySpeed: 2000,
        autoplay: true,
        loop: true,
        dots: false,
        nav: true,
        navText: ['<i class="fas fa-long-arrow-alt-left"></i>', '<i class="fas fa-long-arrow-alt-right"></i>'],
        responsive: {
            0: {
                items: 1,
            },
            768: {
                items: 2,
            },
            992: {
                items: 2,
            },
            1200: {
                items: 3,
            },
        }
    });

    $(".service-slider-area").owlCarousel({
        autoplayHoverPause: true,
        autoplaySpeed: 2000,
        autoplay: true,
        loop: true,
        dots: false,
        margin: 30,
        nav: true,
        navText: ['<i class="fas fa-long-arrow-alt-left"></i>', '<i class="fas fa-long-arrow-alt-right"></i>'],
        responsive: {
            0: {
                items: 1,
            },
            768: {
                items: 2,
            },
            992: {
                items: 3,
            },
            1200: {
                items: 3,
            },
        }
    });

    $(".events-slider-area").owlCarousel({
        autoplayHoverPause: true,
        autoplaySpeed: 2000,
        autoplay: true,
        loop: true,
        dots: true,
        margin: 30,
        nav: true,
        navText: ['<i class="fas fa-long-arrow-alt-left"></i>', '<i class="fas fa-long-arrow-alt-right"></i>'],
        responsive: {
            0: {
                items: 1,
            },
            768: {
                items: 2,
            },
            992: {
                items: 2,
            },
            1200: {
                items: 3,
            },
        }
    });

    $(".feedback-slider-area").owlCarousel({
        autoplayHoverPause: true,
        autoplaySpeed: 2000,
        autoplay: true,
        loop: true,
        dots: false,
        nav: true,
        navText: ['<i class="fas fa-long-arrow-alt-left"></i>', '<i class="fas fa-long-arrow-alt-right"></i>'],
        responsive: {
            0: {
                items: 1,
            },
            768: {
                items: 2,
            },
            992: {
                items: 2,
            },
        }
    });

    $(".feedback-slider-area-2").owlCarousel({
        autoplayHoverPause: true,
        autoplaySpeed: 2000,
        autoplay: true,
        loop: true,
        dots: true,
        responsive: {
            0: {
                items: 1,
            },
            768: {
                items: 2,
            },
            992: {
                items: 3,
            },
            1200: {
                items: 3,
            }
        }
    });
    
    

    // Video PopUp
    $(".video-popup").magnificPopup({
        type: "iframe",
    });

    // $('input[type="number"]').niceNumber();
    
    // language select
    $("select").niceSelect();

    // TweenMax JS
    $('.main-banner').mousemove(function(e){
        var wx = $(window).width();
        var wy = $(window).height();
        var x = e.pageX - this.offsetLeft;
        var y = e.pageY - this.offsetTop;
        var newx = x - wx/2;
        var newy = y - wy/2;
        $('.shape3,.shape4,.shape5').each(function(){
            var speed = $(this).attr('data-speed');
            if($(this).attr('data-revert')) speed *= -.4;
            TweenMax.to($(this), 1, {x: (1 - newx*speed), y: (1 - newy*speed)});
        });
    });

    $('.home-banner').mousemove(function(e){
        var wx = $(window).width();
        var wy = $(window).height();
        var x = e.pageX - this.offsetLeft;
        var y = e.pageY - this.offsetTop;
        var newx = x - wx/2;
        var newy = y - wy/2;
        $('.shape1,.shape2,.shape3').each(function(){
            var speed = $(this).attr('data-speed');
            if($(this).attr('data-revert')) speed *= -.4;
            TweenMax.to($(this), 1, {x: (1 - newx*speed), y: (1 - newy*speed)});
        });
    });

    // Formulaire d'abonnement du pied de page : envoyé au module
    // « Lettre d'information » (gouvernorat_lettre), qui répond en JSON.
    // L'adresse reçoit un courriel pour confirmer l'inscription.
    $(".newsletter-form input[type=email], .newsletter-form input[name=EMAIL], .newsletter-form input[name=courriel]")
        .attr("placeholder", "Votre adresse de courriel")
        .attr("aria-label", "Votre adresse de courriel")
        .attr("autocomplete", "email");
    $(".newsletter-form").on("submit", function (event) {
        event.preventDefault();
        var $form = $(this);
        var $champ = $form.find("input[type=email], input[name=EMAIL], input[name=courriel]").first();
        var $bouton = $form.find("button, input[type=submit]").first();
        if (!$form.find(".newsletter-statut").length) {
            $form.append('<p class="newsletter-statut" role="status" aria-live="polite"></p>');
        }
        var afficher = function (message, ok) {
            $form.find(".newsletter-statut").text(message).toggleClass("newsletter-statut--erreur", !ok);
            $("#validator-newsletter, #validator-newsletter-2").removeClass()
                .addClass(ok ? "validation-success" : "validation-danger").text("");
        };
        var adresse = $.trim($champ.val() || "");
        if (!adresse || ($champ[0].checkValidity && !$champ[0].checkValidity())) {
            afficher("Indiquez une adresse de courriel valide.", false);
            $champ.trigger("focus");
            return;
        }
        var base = (window.drupalSettings && window.drupalSettings.path && window.drupalSettings.path.baseUrl) || "/";
        $bouton.prop("disabled", true);
        afficher("Envoi en cours…", true);
        $.ajax({
            url: base + "lettre/inscription",
            method: "POST",
            data: { courriel: adresse },
            dataType: "json",
            headers: { "X-Requested-With": "XMLHttpRequest" }
        }).done(function (reponse) {
            afficher(reponse.message, true);
            $champ.val("");
        }).fail(function (xhr) {
            var message = (xhr.responseJSON && xhr.responseJSON.message) ||
                "L'inscription n'a pas pu aboutir pour le moment. Merci de réessayer plus tard.";
            afficher(message, false);
        }).always(function () {
            $bouton.prop("disabled", false);
        });
    });

    // Liens ouverts dans un nouvel onglet (constat S10 du diagnostic) : la page
    // ouverte ne doit pas pouvoir agir sur celle du site. Le script de
    // déploiement corrige les textes enregistrés ; ceci couvre les formats de
    // texte qui retirent l'attribut rel à l'affichage.
    $('a[target="_blank"]').each(function () {
        var rel = (this.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
        ['noopener', 'noreferrer'].forEach(function (valeur) {
            if (rel.indexOf(valeur) === -1) { rel.push(valeur); }
        });
        this.setAttribute('rel', rel.join(' '));
    });

    // Go to Top
    $(function () {
        // Scroll Event
        $(window).on("scroll", function () {
            var scrolled = $(window).scrollTop();
            if (scrolled > 600) $(".go-top").addClass("active");
            if (scrolled < 600) $(".go-top").removeClass("active");
        });
        // Click Event
        $(".go-top").on("click", function () {
            $("html, body").animate({ scrollTop: "0" }, 0);
        });
    });

    $(".odometer").appear(function (e) {
        var odo = $(".odometer");
        odo.each(function () {
            var countNumber = $(this).attr("data-count");
            $(this).html(countNumber);
        });
    });

    // WOW Animation JS
    if ($(".wow").length) {
        var wow = new WOW({
            mobile: false,
        });
        wow.init();
    }
     // Single Post Gallery 

})(jQuery);