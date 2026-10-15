/**
 * BANANO STORE — AVATAR FALLBACK (cliente)
 * Red de seguridad global: ninguna <img> de avatar queda rota ni sin src.
 *  - Escucha `error` en fase de captura (los errores de <img> no burbujean).
 *  - Si la imagen apunta a /assets/avatars/ o lleva data-avatar-img, la
 *    reemplaza por un SVG inline (silueta) y marca el nodo para no repetir.
 *  - Un MutationObserver rellena avatares insertados sin src / con src vacío.
 * El backend (core/AvatarCatalog.php) ya entrega avatares válidos; esto sólo
 * cubre carreras (archivo borrado entre respuesta y carga) y caché antigua.
 */
(function () {
    'use strict';
    if (window.__bananoAvatarFallbackReady) return;
    window.__bananoAvatarFallbackReady = true;

    var AVATAR_PATH = '/assets/avatars/';
    var FALLBACK_SRC =
        'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%2032%2032%22%3E' +
        '%3Crect%20width%3D%2232%22%20height%3D%2232%22%20fill%3D%22%2327272a%22/%3E' +
        '%3Cpath%20fill%3D%22%239ca3af%22%20d%3D%22M16%2015.5a5%205%200%201%200%200-10%205%205%200%200%200%200%2010zm0%202.2c-6.7%200-11%203.7-11%205.5v3.4h22v-3.4c0-2.2-4.1-5.5-11-5.5z%22/%3E%3C/svg%3E';

    function isAvatarImg(el) {
        if (!el || el.tagName !== 'IMG') return false;
        if (el.hasAttribute('data-avatar-img')) return true;
        var src = el.getAttribute('src') || '';
        if (src.indexOf(AVATAR_PATH) !== -1) return true;
        var cls = el.className || '';
        return /avatar/i.test(typeof cls === 'string' ? cls : '');
    }

    function applyFallback(img) {
        if (!img || img.dataset.avatarFallbackApplied === '1') return;
        img.dataset.avatarFallbackApplied = '1';
        img.onerror = null; // handlers inline heredados (p.ej. default.png) no deben re-romperla
        img.src = FALLBACK_SRC;
        img.style.backgroundImage = '';
    }

    document.addEventListener('error', function (e) {
        var t = e.target;
        if (isAvatarImg(t)) applyFallback(t);
    }, true);

    function fixEmpty(img) {
        if (!isAvatarImg(img)) return;
        var src = img.getAttribute('src');
        if (src === null || src.trim() === '') applyFallback(img);
    }

    function scan(root) {
        if (!root || !root.querySelectorAll) return;
        var imgs = root.querySelectorAll('img');
        for (var i = 0; i < imgs.length; i++) fixEmpty(imgs[i]);
    }

    function boot() {
        scan(document);
        if (!('MutationObserver' in window)) return;
        var mo = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var r = records[i];
                if (r.type === 'attributes') { fixEmpty(r.target); continue; }
                for (var j = 0; j < r.addedNodes.length; j++) {
                    var n = r.addedNodes[j];
                    if (n.nodeType !== 1) continue;
                    if (n.tagName === 'IMG') fixEmpty(n); else scan(n);
                }
            }
        });
        mo.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
    }

    window.__bananoAvatarFallbackSrc = FALLBACK_SRC;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
