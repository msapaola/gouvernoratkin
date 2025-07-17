document.addEventListener('DOMContentLoaded', function() {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const element = entry.target;
            const delay = element.getAttribute('data-delay') || '0';
            element.style.transitionDelay = `${delay}ms`;
            element.classList.add('visible');
            observer.unobserve(element); // Arrête de surveiller après l'animation
          }
        });
      }, {
        threshold: 0.1 // Déclenche quand 10% de l'élément est visible
      });

      const elementsToAnimate = document.querySelectorAll('[class*="animate-"]');
      elementsToAnimate.forEach(el => observer.observe(el));
    });