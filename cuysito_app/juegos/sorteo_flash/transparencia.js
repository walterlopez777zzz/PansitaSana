// transparencia.js — Animaciones de entrada (pop-in) al hacer scroll.
// Sin dependencias: IntersectionObserver con fallback para navegadores antiguos.
(function () {
    'use strict';

    var paneles = document.querySelectorAll('.panel-anim');

    if (!('IntersectionObserver' in window)) {
        paneles.forEach(function (p) { p.classList.add('en-vista'); });
        return;
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('en-vista');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    paneles.forEach(function (p) { observer.observe(p); });
})();
