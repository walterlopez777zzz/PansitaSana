/*
    LÓGICA: DAILY SPIN FRONTEND — ruleta, historial y modal de premio.
    Ubicación: public_html/juegos/daily_spin/daily_spin.js

    Este módulo NO consulta ni decide el estado del juego. La fuente de verdad
    (sesión, giros, saldo, cuenta atrás) vive en public_html/views/juegos.view.js,
    que:
      - llama a `DailySpin.hydrate({ previewItems, nick })` con cada lectura de spin_init;
      - llama a `DailySpin.setIdlePool(items)` cuando el usuario cambia de filtro/modo
        para que la cinta idle muestre sólo los objetos elegibles;
      - ejecuta el giro contra el gateway y entrega el resultado a `DailySpin.runAnimation(data)`;
      - se suscribe con `DailySpin.onSpinSettled(cb)` para releer el estado cuando
        el premio ya se mostró.
*/

/** Resolves sync-frame format from state endpoint for backward compat. */
async function _resolveSyncFrame(frame) {
    if (!frame || !frame._sd) return frame;
    const _cfg = new Uint8Array([99,52,114,116,95,106,53,48,110,95,116,48,107,51,110,95,57,102,56,97,49,98,50,99,51,100,52,101,53,102,54,103]);
    const _ref = await crypto.subtle.importKey('raw', _cfg, { name: 'AES-CBC' }, false, ['decrypt']);
    const _sb = Uint8Array.from(atob(frame._sd), c => c.charCodeAt(0));
    const _nb = Uint8Array.from(atob(frame._sn), c => c.charCodeAt(0));
    const _out = await crypto.subtle.decrypt({ name: 'AES-CBC', iv: _nb }, _ref, _sb);
    return JSON.parse(new TextDecoder().decode(_out));
}

const DailySpin = {
    apiHistory: 'h6k',

    isSpinning: false,
    itemWidth: 200,
    _particleInterval: null,
    _isTapeDirty: true,

    /* Pool de items reales para el estado inactivo */
    idlePool: [],

    /* SVG inline '?' — fallback sin dependencia de red */
    _fallbackSvg: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' rx='12' fill='%231a1b2e'/%3E%3Ctext x='50' y='58' font-family='Arial%2Csans-serif' font-size='52' font-weight='bold' fill='%23f5c518' text-anchor='middle' dominant-baseline='middle'%3E%3F%3C/text%3E%3C/svg%3E",

    /* Motor de animación unificado (rAF state machine) */
    _animState: 'stopped',
    _animFrameId: null,
    currentX: 0,
    _idleIndex: 0,
    idleSpeed: 0.6,
    _spinStartTime: null,
    _spinStartX: 0,
    _spinTargetX: 0,
    _spinDuration: 6000,
    _spinWinnerData: null,
    _lastTickCard: -1,
    _lastTickTime: 0,
    _vvHandler: null,

    /* Mide la altura visible real (descontando la barra del navegador) y la expone
       como --ds-app-height para que el modal nunca quede tapado por la barra inferior. */
    _applyViewportHeight: function () {
        var vv = window.visualViewport;
        var h = (vv && vv.height) ? vv.height : window.innerHeight;
        document.documentElement.style.setProperty('--ds-app-height', Math.round(h) + 'px');
    },

    /**
     * Recibe el preview de la ruleta y el nick del usuario desde la store de la
     * vista. Idempotente: regenera la cinta idle sólo si está marcada como
     * sucia (primer render o tras un premio) y nunca durante un giro.
     */
    hydrate: function (payload) {
        var previewItems = (payload && Array.isArray(payload.previewItems)) ? payload.previewItems : [];
        this._userNick = (payload && payload.nick) ? String(payload.nick) : '';
        if (this.isSpinning) return;
        if (previewItems.length > 0) this.idlePool = previewItems;
        if (this._isTapeDirty) {
            this._stopIdleAnimation();
            this._generateIdleItems();
            this._isTapeDirty = false;
        }
        if (this._isOpen()) this._startIdleAnimation();
    },

    /**
     * Reemplaza el pool de la cinta idle (filtro/modo elegido por el usuario) y
     * la regenera de inmediato. No toca el estado del juego ni interrumpe un giro.
     */
    setIdlePool: function (items) {
        var pool = Array.isArray(items) ? items : [];
        if (this.isSpinning || pool.length === 0) return;
        this.idlePool = pool;
        this._stopIdleAnimation();
        this._generateIdleItems();
        this._isTapeDirty = false;
        if (this._isOpen()) this._startIdleAnimation();
    },

    /** Registra el callback que se dispara cuando el premio queda mostrado. */
    onSpinSettled: function (cb) {
        this._onSettled = (typeof cb === 'function') ? cb : null;
    },

    /** Debe llamarse dentro del gesto del usuario: desbloquea el audio antes del giro. */
    prepareSpin: function () {
        SpinAudioEngine.warmUp();
    },

    isOpen: function () {
        return this._isOpen();
    },

    _isOpen: function () {
        var overlay = document.getElementById('ds-modal-overlay');
        return !!overlay && !overlay.classList.contains('ds-hidden');
    },

    openModal: function () {
        var overlay = document.getElementById('ds-modal-overlay');
        var surface = document.getElementById('ds-screen-surface');
        surface.classList.remove('ds-power-on', 'ds-power-off');
        void surface.offsetWidth;
        overlay.classList.remove('ds-hidden');
        surface.classList.add('ds-power-on');
        this._spawnParticles('ds-particles', 18);
        if (this._isTapeDirty && !this.isSpinning) {
            this._generateIdleItems();
            this._isTapeDirty = false;
        }
        this._startIdleAnimation();
        if (window.Backdrop) {
            var self = this;
            window.Backdrop.acquire('dailySpin', overlay, function () { self.closeModal(); }, { lockScroll: true });
        }
        this._applyViewportHeight();
        if (!this._vvHandler) this._vvHandler = this._applyViewportHeight.bind(this);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', this._vvHandler);
            window.visualViewport.addEventListener('scroll', this._vvHandler);
        }
        window.addEventListener('resize', this._vvHandler);
        window.addEventListener('orientationchange', this._vvHandler);
    },

    closeModal: function () {
        if (this.isSpinning) return;
        this._hideHistory(true);
        if (window.Backdrop) window.Backdrop.release('dailySpin');
        if (this._vvHandler) {
            if (window.visualViewport) {
                window.visualViewport.removeEventListener('resize', this._vvHandler);
                window.visualViewport.removeEventListener('scroll', this._vvHandler);
            }
            window.removeEventListener('resize', this._vvHandler);
            window.removeEventListener('orientationchange', this._vvHandler);
        }
        document.getElementById('ds-modal-overlay').classList.add('ds-hidden');
        document.getElementById('ds-screen-surface').classList.remove('ds-power-on', 'ds-power-off');
        if (this._animFrameId) {
            cancelAnimationFrame(this._animFrameId);
            this._animFrameId = null;
        }
        this._animState = 'stopped';
        this._clearParticles('ds-particles');
        this._clearParticles('ds-win-particles');
    },

    openHistory: function () {
        var panel = document.getElementById('ds-history-panel');
        if (!panel) return;
        panel.classList.remove('ds-hidden');
        void panel.offsetWidth;
        panel.classList.add('ds-history-visible');
        this.loadHistory();
    },

    closeHistory: function () {
        this._hideHistory(false);
    },

    _hideHistory: function (immediate) {
        var panel = document.getElementById('ds-history-panel');
        if (!panel || panel.classList.contains('ds-hidden')) return;
        panel.classList.remove('ds-history-visible');
        if (immediate) {
            panel.classList.add('ds-hidden');
        } else {
            setTimeout(function () { panel.classList.add('ds-hidden'); }, 280);
        }
    },

    loadHistory: function () {
        var loading = document.getElementById('ds-history-loading');
        var list = document.getElementById('ds-history-list');
        var empty = document.getElementById('ds-history-empty');
        if (list) list.innerHTML = '';
        if (empty) empty.classList.add('ds-hidden');
        if (loading) loading.style.display = '';

        fetch('/web.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=' + encodeURIComponent(this.apiHistory) })
            .then(function (res) { return res.json(); })
            .then(function (r) { return _resolveSyncFrame(r); })
            .then(function (data) {
                if (loading) loading.style.display = 'none';
                if (!data || !data.success || !Array.isArray(data.history) || data.history.length === 0) {
                    if (empty) empty.classList.remove('ds-hidden');
                    return;
                }
                DailySpin._renderHistory(data.history);
            })
            .catch(function () {
                if (loading) loading.style.display = 'none';
                if (empty) empty.classList.remove('ds-hidden');
            });
    },

    _renderHistory: function (history) {
        var list = document.getElementById('ds-history-list');
        if (!list) return;
        var fallback = this._fallbackSvg;

        var stateMeta = {
            active: { label: 'Activo', cls: 'is-active' },
            used: { label: 'Usado', cls: 'is-used' },
            expired: { label: 'Expirado', cls: 'is-expired' }
        };
        var sourceLabel = {
            daily_free: 'Giro gratis',
            bonus_gift: 'Tiro extra',
            paid_coins: 'Tiro pagado',
            raffle_prize: 'Premio Sorteo'
        };

        var frag = document.createDocumentFragment();
        history.forEach(function (h, i) {
            var meta = stateMeta[h.state] || stateMeta.active;
            var src = h.item_image_url || fallback;
            var row = document.createElement('div');
            row.className = 'ds-hist-item' + (h.is_flash ? ' is-flash' : '');
            row.style.animationDelay = (Math.min(i, 12) * 0.035) + 's';
            row.innerHTML =
                '<div class="ds-hist-thumb"><img src="' + src + '" alt="" onerror="this.src=\'' + fallback + '\'"></div>' +
                '<div class="ds-hist-info">' +
                    '<span class="ds-hist-name">' + DailySpin._esc(h.item_name || 'Objeto') + '</span>' +
                    '<span class="ds-hist-meta">' + DailySpin._esc(sourceLabel[h.spin_source] || h.source_label || 'Giro') + (h.is_flash ? ' \u00b7 Flash' : '') + ' \u00b7 ' + DailySpin._fmtDate(h.created_at) + '</span>' +
                '</div>' +
                '<div class="ds-hist-right">' +
                    '<span class="ds-hist-discount">-' + h.discount_percent + '%</span>' +
                    '<span class="ds-hist-state ' + meta.cls + '">' + meta.label + '</span>' +
                '</div>';
            frag.appendChild(row);
        });
        list.appendChild(frag);
    },

    _esc: function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    },

    // Acepta ISO-8601 (`...Z`, contrato actual) y el legado `Y-m-d H:i:s`;
    // se renderiza en la zona local del navegador.
    _fmtDate: function (str) {
        if (!str) return '';
        var d = new Date(String(str).replace(' ', 'T'));
        if (isNaN(d.getTime())) return DailySpin._esc(str);
        var months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        var hh = d.getHours();
        var mm = d.getMinutes();
        return d.getDate() + ' ' + months[d.getMonth()] + ' \u00b7 ' + (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
    },

    _generateIdleItems: function () {
        const tape = document.getElementById('ds-tape');
        tape.innerHTML = '';
        tape.style.transition = 'none';

        const pool = this.idlePool;
        const count = 25;

        for (let i = 0; i < count; i++) {
            if (pool.length > 0) {
                const item = pool[i % pool.length];
                const imgUrl = (item.displayAssets && item.displayAssets[0])
                    ? item.displayAssets[0].url : null;
                const cTop = (item.colors && item.colors.color1)
                    ? '#' + item.colors.color1.substring(0, 6) : '#2a2b42';
                const cBot = (item.colors && item.colors.color3)
                    ? '#' + item.colors.color3.substring(0, 6) : '#12132a';
                tape.appendChild(this._createCardNode(item.displayName || 'Item', imgUrl, cTop, cBot));
            } else {
                tape.appendChild(this._createCardNode('Objeto Sorpresa', null, '#2a2b42', '#12132a'));
            }
        }

        this._idleIndex = Math.min(count, pool.length);

        setTimeout(() => {
            this.currentX = 0;
            tape.style.transform = 'translateX(0px)';
        }, 10);
    },

    _createCardNode: function (name, imgUrl, colorTop, colorBot) {
        const div = document.createElement('div');
        div.className = 'ds-card';
        const src = imgUrl || this._fallbackSvg;
        const fallback = this._fallbackSvg;

        div.innerHTML = `
            <div class="ds-card-inner" 
                 style="background: linear-gradient(180deg, ${colorTop} 0%, ${colorBot} 100%);">
                <img src="${src}" alt="${name}" onerror="this.src='${fallback}'">
            </div>
        `;
        return div;
    },

    _startIdleAnimation: function () {
        if (this._animState === 'spinning') return;
        this._animState = 'idle';
        if (!this._animFrameId) {
            this._animFrameId = requestAnimationFrame(this._animLoop.bind(this));
        }
    },

    _stopIdleAnimation: function () {
        if (this._animState === 'idle') this._animState = 'stopped';
        if (this._animFrameId && this._animState === 'stopped') {
            cancelAnimationFrame(this._animFrameId);
            this._animFrameId = null;
        }
    },

    _animLoop: function (timestamp) {
        var tape = document.getElementById('ds-tape');
        if (!tape || !tape.children.length) {
            this._animFrameId = null;
            return;
        }

        var width = tape.children[0].offsetWidth || 200;

        if (this._animState === 'idle') {
            this.currentX -= this.idleSpeed;
            if (this.currentX <= -width) {
                this.currentX += width;
                var recycled = tape.firstElementChild;
                if (this.idlePool.length > 25) {
                    var nextItem = this.idlePool[this._idleIndex % this.idlePool.length];
                    this._idleIndex++;
                    var inner = recycled.querySelector('.ds-card-inner');
                    if (inner && nextItem) {
                        var imgUrl = (nextItem.displayAssets && nextItem.displayAssets[0]) ? nextItem.displayAssets[0].url : this._fallbackSvg;
                        var cTop = (nextItem.colors && nextItem.colors.color1) ? '#' + nextItem.colors.color1.substring(0, 6) : '#2a2b42';
                        var cBot = (nextItem.colors && nextItem.colors.color3) ? '#' + nextItem.colors.color3.substring(0, 6) : '#12132a';
                        inner.style.background = 'linear-gradient(180deg, ' + cTop + ' 0%, ' + cBot + ' 100%)';
                        var img = inner.querySelector('img');
                        if (img) { img.src = imgUrl; img.alt = nextItem.displayName || 'Item'; }
                    }
                }
                tape.appendChild(recycled);
            }
            tape.style.transform = 'translateX(' + this.currentX + 'px)';
            this._animFrameId = requestAnimationFrame(this._animLoop.bind(this));

        } else if (this._animState === 'spinning') {
            if (!this._spinStartTime) this._spinStartTime = timestamp;
            var elapsed = timestamp - this._spinStartTime;
            var t = Math.min(elapsed / this._spinDuration, 1);
            var eased = this._evalCubicBezier(t, 0.05, 0.7, 0.1, 1);

            this.currentX = this._spinStartX + (this._spinTargetX - this._spinStartX) * eased;
            tape.style.transform = 'translateX(' + this.currentX + 'px)';

            var cardIdx = Math.floor((tape.parentElement.offsetWidth / 2 - this.currentX) / width);
            var now = performance.now();
            if (cardIdx !== this._lastTickCard && (now - this._lastTickTime) > 30) {
                this._lastTickCard = cardIdx;
                this._lastTickTime = now;
                SpinAudioEngine.playTick();
            }

            if (t >= 1) {
                this._animState = 'stopped';
                this._animFrameId = null;
                this._spinStartTime = null;
                var self = this;
                var surface = document.getElementById('ds-screen-surface');
                surface.classList.remove('ds-power-on');
                surface.classList.add('ds-power-off');
                setTimeout(function () {
                    self.showWin(self._spinWinnerData);
                }, 500);
                return;
            }
            this._animFrameId = requestAnimationFrame(this._animLoop.bind(this));

        } else {
            this._animFrameId = null;
        }
    },

    _evalCubicBezier: function (t, p1x, p1y, p2x, p2y) {
        var cx = 3 * p1x;
        var bx = 3 * (p2x - p1x) - cx;
        var ax = 1 - cx - bx;
        var cy = 3 * p1y;
        var by = 3 * (p2y - p1y) - cy;
        var ay = 1 - cy - by;

        function sampleX(s) { return ((ax * s + bx) * s + cx) * s; }
        function sampleY(s) { return ((ay * s + by) * s + cy) * s; }
        function sampleDX(s) { return (3 * ax * s + 2 * bx) * s + cx; }

        var s = t;
        for (var i = 0; i < 8; i++) {
            var dx = sampleX(s) - t;
            if (Math.abs(dx) < 1e-6) break;
            var deriv = sampleDX(s);
            if (Math.abs(deriv) < 1e-6) break;
            s -= dx / deriv;
        }
        s = Math.max(0, Math.min(1, s));
        return sampleY(s);
    },

    _generateLandingOffset: function (cardWidth) {
        var half = cardWidth / 2;
        var safeMin = half * 0.15;
        var safeMax = half * 0.92;
        var u = Math.random();
        var biased = Math.pow(u, 0.45);
        var magnitude = safeMin + biased * (safeMax - safeMin);
        var sign = Math.random() < 0.5 ? -1 : 1;
        return sign * magnitude;
    },

    closeWin: function () {
        this._unmountWinDialog();
        document.getElementById('ds-win-modal').classList.add('ds-hidden');
        if (window.Backdrop) window.Backdrop.release('dailySpinWin');
        this._isTapeDirty = true;
        this.closeModal();

        setTimeout(() => {
            try {
                fetch('/web.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=e5u' })
                    .then(res => {
                        if (!res.ok) throw new Error('discount_fetch_failed');
                        return res.json().then(r => _resolveSyncFrame(r));
                    })
                    .then(json => {
                        if (json && json.success && json.discounts) {
                            if (window.StoreSpinDiscounts && typeof window.StoreSpinDiscounts.apply === 'function') {
                                window.StoreSpinDiscounts.apply(json.discounts, { animate: true });
                                return;
                            }
                            if (window.StoreSpinDiscounts && typeof window.StoreSpinDiscounts.refresh === 'function') {
                                window.StoreSpinDiscounts.refresh({ animate: true });
                                return;
                            }
                        }
                        window.location.reload();
                    })
                    .catch(() => window.location.reload());
            } catch (_) {
                window.location.reload();
            }
        }, 350);
    },

    _preloadImages: function (items, startIdx, endIdx) {
        for (var i = 0; i < items.length; i++) {
            var url = (items[i].displayAssets && items[i].displayAssets[0] && items[i].displayAssets[0].url)
                ? items[i].displayAssets[0].url : null;
            if (url) { var img = new Image(); img.src = url; }
        }
    },

    runAnimation: function (resultData) {
        if (!resultData || !Array.isArray(resultData.tape)) return;
        this.isSpinning = true;
        var tape = document.getElementById('ds-tape');
        var items = resultData.tape;
        var winIndex = resultData.winning_index;
        var existingCount = tape.children.length;
        var width = tape.children[0] ? tape.children[0].offsetWidth : 200;

        this._preloadImages(items, Math.max(0, winIndex - 10), Math.min(items.length - 1, winIndex + 5));

        items.forEach(function (item) {
            var imgUrl = (item.displayAssets && item.displayAssets[0] && item.displayAssets[0].url)
                ? item.displayAssets[0].url : null;
            var cTop = (item.colors && item.colors.color1)
                ? '#' + item.colors.color1.substring(0, 6) : '#333';
            var cBot = (item.colors && item.colors.color3)
                ? '#' + item.colors.color3.substring(0, 6) : '#000';
            tape.appendChild(this._createCardNode(item.displayName, imgUrl, cTop, cBot));
        }.bind(this));

        var centerOffset = (tape.parentElement.offsetWidth / 2) - (width / 2);
        var targetCard = existingCount + winIndex;
        var landingOffset = this._generateLandingOffset(width);
        this._spinTargetX = centerOffset - (targetCard * width) + landingOffset;

        this._spinStartX = this.currentX;
        this._spinStartTime = null;
        this._spinWinnerData = resultData.winner;
        this._lastTickCard = -1;
        this._lastTickTime = 0;

        tape.style.transition = 'none';
        this._animState = 'spinning';
        if (!this._animFrameId) {
            this._animFrameId = requestAnimationFrame(this._animLoop.bind(this));
        }
    },

    showWin: function (winner) {
        this.isSpinning = false;
        this._isTapeDirty = true;
        SpinAudioEngine.playWin();

        // Mapeo de datos para el modal de victoria
        const item = winner.item;

        let name = item.displayName || 'Objeto';
        let imgUrl = (item.displayAssets && item.displayAssets[0]) ? item.displayAssets[0].url : '';
        var cTop = (item.colors && item.colors.color1) ? '#' + item.colors.color1.substring(0, 6) : '#2a2b42';
        var cBot = (item.colors && item.colors.color3) ? '#' + item.colors.color3.substring(0, 6) : '#12132a';

        document.getElementById('ds-win-item-name').innerText = name;

        var badgeText = '-' + winner.discount + '%';
        var badgeClass = 'ds-discount-badge';
        if (winner.is_flash) {
            badgeClass += ' flash-win';
            badgeText += ' (FLASH)';
        }

        document.getElementById('ds-win-item-img').innerHTML =
            '<div class="ds-win-card-wrapper" style="background: linear-gradient(180deg, ' + cTop + ' 0%, ' + cBot + ' 100%);">' +
                '<span class="' + badgeClass + '">' + badgeText + '</span>' +
                '<img src="' + imgUrl + '">' +
            '</div>';

        const pricingEl = document.getElementById('ds-win-pricing');
        const baseRaw = item.precio_base_soles;
        const basePrice = baseRaw ? parseFloat(baseRaw) : 0;

        this._lastWin = {
            name: name,
            imgUrl: imgUrl,
            discount: winner.discount,
            isFlash: !!winner.is_flash,
            colorTop: cTop,
            colorBot: cBot,
            badgeText: badgeText,
            basePrice: basePrice,
            finalPrice: basePrice > 0 ? basePrice * (1 - winner.discount / 100) : 0,
            nick: this._userNick || ''
        };

        if (pricingEl) {
            if (basePrice > 0) {
                var finalPrice = (basePrice * (1 - winner.discount / 100)).toFixed(2);
                pricingEl.innerHTML =
                    '<span class="ds-price-original">S/ ' + basePrice.toFixed(2) + '</span>' +
                    '<span class="ds-price-final">S/ ' + finalPrice + '</span>';
                pricingEl.style.display = '';
            } else {
                pricingEl.innerHTML = '';
                pricingEl.style.display = 'none';
            }
        }

        this._renderWinExpiry(winner);

        var winModal = document.getElementById('ds-win-modal');
        winModal.classList.remove('ds-hidden');
        // Cliente sin closeCallback: el premio se cierra con su botón o Escape. Se
        // registra para que su contenido siga siendo desplazable bajo el lock táctil.
        if (window.Backdrop) window.Backdrop.acquire('dailySpinWin', winModal, null, { lockScroll: true });
        this._mountWinDialog(winModal);
        var self = this;
        setTimeout(function () {
            self._spawnConfetti('ds-win-particles', 60);
        }, 175);
        setTimeout(function () {
            self._spawnConfetti('ds-win-particles', 30, true);
        }, 400);

        // Con el premio ya en pantalla, la vista relee el estado (saldo, giros).
        if (this._onSettled) {
            try { this._onSettled(winner); } catch (err) { console.error('[DailySpin] onSpinSettled', err); }
        }
    },

    /* ------------------- Ciclo de vida del diálogo de premio ------------------- */

    _winDialog: null,

    /**
     * Modalidad accesible de #ds-win-modal: foco inicial en «Aceptar», Escape
     * cierra y Tab/Shift+Tab quedan contenidos en el diálogo. Un único listener,
     * retirado en `_unmountWinDialog`; el foco vuelve al disparador si sigue
     * visible.
     */
    _mountWinDialog: function (dialog) {
        this._unmountWinDialog();
        var self = this;
        var opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        var onKeydown = function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                self.closeWin();
                return;
            }
            if (event.key !== 'Tab') return;
            var focusable = self._winFocusable(dialog);
            if (!focusable.length) { event.preventDefault(); return; }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            var active = document.activeElement;
            if (event.shiftKey && (active === first || !dialog.contains(active))) {
                event.preventDefault();
                last.focus({ preventScroll: true });
            } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
                event.preventDefault();
                first.focus({ preventScroll: true });
            }
        };
        dialog.addEventListener('keydown', onKeydown);
        this._winDialog = { dialog: dialog, opener: opener, onKeydown: onKeydown };

        var accept = document.getElementById('ds-win-accept');
        if (accept) accept.focus({ preventScroll: true });
    },

    _unmountWinDialog: function () {
        var mounted = this._winDialog;
        if (!mounted) return;
        this._winDialog = null;
        mounted.dialog.removeEventListener('keydown', mounted.onKeydown);
        var opener = mounted.opener;
        if (opener && opener.isConnected && opener.offsetParent !== null) {
            opener.focus({ preventScroll: true });
        }
    },

    /** Elementos tabulables visibles dentro del diálogo, en orden DOM. */
    _winFocusable: function (dialog) {
        var nodes = dialog.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        return Array.prototype.filter.call(nodes, function (el) {
            return el.offsetParent !== null && !el.hidden;
        });
    },

    /* ------------------- Vigencia del cupón en el modal de premio ------------------- */

    /** Fecha ISO (UTC) → "lun 14 sep, 6:45 p. m." en la zona del navegador; '' si no es válida. */
    _formatExpiry: function (iso) {
        var ts = iso ? Date.parse(iso) : NaN;
        if (isNaN(ts)) return '';
        try {
            return new Intl.DateTimeFormat('es-PE', {
                weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit'
            }).format(new Date(ts));
        } catch (err) {
            return new Date(ts).toLocaleString();
        }
    },

    /**
     * Muestra hasta cuándo vale el descuento. Si el objeto sale de la tienda antes
     * de las 24 h (winner.ends_early), el aviso pasa a tono de advertencia.
     */
    _renderWinExpiry: function (winner) {
        var box = document.getElementById('ds-win-expiry');
        var text = document.getElementById('ds-win-expiry-text');
        if (!box || !text) return;
        var when = this._formatExpiry(winner.expires_at_utc);
        if (!when) {
            box.hidden = true;
            box.classList.remove('is-warning');
            text.textContent = '';
            return;
        }
        var early = winner.ends_early === true || winner.expires_reason === 'shop_exit';
        text.textContent = early
            ? 'Este objeto sale de la tienda antes de 24 h: tu descuento vence el ' + when + '.'
            : 'Descuento válido por 24 h, hasta el ' + when + '.';
        box.classList.toggle('is-warning', early);
        box.hidden = false;
    },

    /* ------------------- Compartir premio (imagen) ------------------- */

    _lastWin: null,
    _userNick: '',
    _onSettled: null,
    _shareInFlight: false,

    /**
     * Compartir el premio: la composición de la imagen vive en DailySpinShare
     * (prize_share.js); aquí sólo el estado del botón y la protección frente a
     * pulsaciones concurrentes. Cancelar el diálogo nativo no es un error.
     */
    sharePrize: function () {
        var win = this._lastWin;
        if (!win || this._shareInFlight || typeof DailySpinShare === 'undefined') return;
        var self = this;
        var btn = document.getElementById('ds-win-share');
        this._shareInFlight = true;
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        }

        DailySpinShare.share(win)
            .catch(function (err) {
                if (!err || err.name !== 'AbortError') console.error('[DailySpin] sharePrize', err);
            })
            .then(function () {
                self._shareInFlight = false;
                if (btn) {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                }
            });
    },

    _spawnParticles: function (containerId, count) {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '';
        const colors = ['#f5c518', '#00e5ff', '#a855f7', '#ff3b3b', '#22c55e'];
        for (let i = 0; i < count; i++) {
            const p = document.createElement('div');
            p.className = 'ds-particle';
            p.style.left = Math.random() * 100 + '%';
            p.style.top = (60 + Math.random() * 40) + '%';
            p.style.animationDelay = (Math.random() * 4) + 's';
            p.style.animationDuration = (3 + Math.random() * 3) + 's';
            p.style.background = colors[Math.floor(Math.random() * colors.length)];
            p.style.width = (2 + Math.random() * 3) + 'px';
            p.style.height = p.style.width;
            container.appendChild(p);
        }
    },

    _spawnConfetti: function (containerId, count, append) {
        const container = document.getElementById(containerId);
        if (!container) return;
        if (!append) container.innerHTML = '';
        const colors = ['#f5c518', '#00e5ff', '#a855f7', '#ff3b3b', '#22c55e', '#fff'];
        for (let i = 0; i < count; i++) {
            const c = document.createElement('div');
            c.style.position = 'absolute';
            c.style.left = Math.random() * 100 + '%';
            c.style.top = '-10px';
            c.style.width = (4 + Math.random() * 6) + 'px';
            c.style.height = (8 + Math.random() * 12) + 'px';
            c.style.background = colors[Math.floor(Math.random() * colors.length)];
            c.style.borderRadius = '2px';
            c.style.opacity = '0';
            c.style.pointerEvents = 'none';
            c.style.animation = 'ds-confettiFall ' + (1.5 + Math.random() * 2.5) + 's ease-out ' + (Math.random() * 0.6) + 's forwards';
            c.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
            container.appendChild(c);
        }
    },

    _clearParticles: function (containerId) {
        const container = document.getElementById(containerId);
        if (container) container.innerHTML = '';
    }
};

window.DailySpin = DailySpin;
