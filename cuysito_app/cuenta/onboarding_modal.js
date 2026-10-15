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

function createOnboardingState() {
    return {
        overlayEl: /** @type {HTMLDivElement|null} */ (null),
        injected: false,
        currentApiEndpoint: 'j5r',
        avatarsState: /** @type {any[]} */ ([]),
        avatarIndex: 0,
        carousel: null,
        form: {
            game_username: '',
            public_name: '',
            country_iso2: '',
            whatsapp: ''
        },
        userSnapshot: /** @type {any} */ (null),
        prevBodyOverflow: '',
        abort: /** @type {AbortController|null} */ (null),
        prevActiveEl: /** @type {Element|null} */ (null),
        inertTargets: /** @type {HTMLElement[]} */ ([]),
        tooltipAnchor: /** @type {HTMLElement|null} */ (null),
        dashboardData: /** @type {any} */ (null)
    };
}

class AvatarCarousel {
    constructor({ state, trackEl, slotsEl, signal }) {
        this.state = state;
        this.trackEl = trackEl;
        this.slotsEl = slotsEl;
        this.signal = signal;

        this._activePointerId = null;
        this._startX = 0;
        this._startY = 0;
        this._moved = false;
        this._pressRel = null;
        this._suppressClick = false;
    }

    bind() {
        if (!this.trackEl) return;

        if (this.slotsEl) {
            this.slotsEl.addEventListener('click', (e) => {
                if (this._suppressClick) return;
                const rel = this._getSlotRelFromEvent(e);
                if (rel === null) return;
                this._applyRel(rel);
            }, { passive: true, signal: this.signal });
        }

        this.trackEl.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            e.preventDefault();
            const delta = e.key === 'ArrowLeft' ? -1 : 1;
            this._applyRel(delta);
        }, { signal: this.signal });

        this.trackEl.addEventListener('pointerdown', (e) => {
            if (!(e.target instanceof Element)) return;
            this._activePointerId = e.pointerId;
            this._startX = e.clientX;
            this._startY = e.clientY;
            this._moved = false;

            const slot = e.target.closest('.avatar-slot');
            if (slot instanceof HTMLElement) {
                const raw = slot.dataset?.rel ?? slot.getAttribute('data-rel');
                const rel = Number(raw);
                this._pressRel = Number.isFinite(rel) ? rel : null;
            } else {
                this._pressRel = null;
            }

            try {
                this.trackEl.setPointerCapture(e.pointerId);
            } catch (err) {
            }
        }, { passive: true, signal: this.signal });

        this.trackEl.addEventListener('pointermove', (e) => {
            if (this._activePointerId === null || e.pointerId !== this._activePointerId) return;
            const dx = e.clientX - this._startX;
            const dy = e.clientY - this._startY;
            if (Math.abs(dx) < 6) return;
            if (Math.abs(dx) < Math.abs(dy)) return;
            this._moved = true;
        }, { passive: true, signal: this.signal });

        this.trackEl.addEventListener('pointerup', (e) => {
            if (this._activePointerId === null || e.pointerId !== this._activePointerId) return;
            const dx = e.clientX - this._startX;
            const dy = e.clientY - this._startY;
            this._activePointerId = null;

            if (!this._moved) {
                if (this._pressRel !== null) {
                    this._suppressClick = true;
                    window.setTimeout(() => { this._suppressClick = false; }, 0);
                    this._applyRel(this._pressRel);
                }
                this._pressRel = null;
                return;
            }

            if (Math.abs(dx) < Math.abs(dy)) return;
            if (Math.abs(dx) < CONSTANTS.ui.swipeThresholdPx) return;

            const delta = dx < 0 ? 1 : -1;
            this._applyRel(delta);
            this._pressRel = null;
        }, { passive: true, signal: this.signal });

        this.trackEl.addEventListener('pointercancel', (e) => {
            if (this._activePointerId === null || e.pointerId !== this._activePointerId) return;
            this._activePointerId = null;
            this._pressRel = null;
        }, { passive: true, signal: this.signal });
    }

    next() {
        this._applyRel(1);
    }

    prev() {
        this._applyRel(-1);
    }

    getValue() {
        const st = this.state;
        if (!st.avatarsState || !st.avatarsState.length) return null;
        return st.avatarsState[modulo(st.avatarIndex, st.avatarsState.length)] || null;
    }

    _applyRel(rel) {
        if (!Number.isFinite(rel)) return;
        const st = this.state;
        if (!st.avatarsState || !st.avatarsState.length) return;
        st.avatarIndex = modulo(st.avatarIndex + rel, st.avatarsState.length);
    }

    _getSlotRelFromEvent(e) {
        const path = typeof e?.composedPath === 'function' ? e.composedPath() : null;
        if (Array.isArray(path)) {
            for (const node of path) {
                if (node instanceof HTMLElement && node.classList.contains('avatar-slot')) {
                    const raw = node.dataset?.rel ?? node.getAttribute('data-rel');
                    const rel = Number(raw);
                    return Number.isFinite(rel) ? rel : null;
                }
            }
        }
        if (!(e?.target instanceof Element)) return null;
        const slot = e.target.closest('.avatar-slot');
        if (!(slot instanceof HTMLElement)) return null;
        const raw = slot.dataset?.rel ?? slot.getAttribute('data-rel');
        const rel = Number(raw);
        return Number.isFinite(rel) ? rel : null;
    }
}

class OnboardingModal {
    constructor() {
        this.state = this._createReactiveState(createOnboardingState());
        this.elements = null;
    }

    _cacheElements() {
        const o = this.state.overlayEl;
        if (!o) {
            this.elements = null;
            return;
        }

        this.elements = {
            overlayEl: o,
            email: o.querySelector('#onboardingEmail'),
            saveBtn: o.querySelector('#onboardingSave'),
            epic: o.querySelector('#onboardingEpic'),
            pub: o.querySelector('#onboardingPublic'),
            country: o.querySelector('#onboardingCountry'),
            wa: o.querySelector('#onboardingWhatsapp'),
            epicBox: o.querySelector('#onboardingEpicBox'),
            pubBox: o.querySelector('#onboardingPublicBox'),
            countryBox: o.querySelector('#onboardingCountryBox'),
            countryList: o.querySelector('#onboardingCountryList'),
            waBox: o.querySelector('#onboardingWhatsappBox'),
            carouselTrack: o.querySelector('.carousel-track'),
            carouselSlots: o.querySelector(`#${CONSTANTS.ids.carouselSlots}`),
            lockedBoxes: o.querySelectorAll('.input-box.locked'),
            error: o.querySelector(`#${CONSTANTS.ids.error}`),
            live: o.querySelector(`#${CONSTANTS.ids.live}`),
            tooltip: o.querySelector(`#${CONSTANTS.ids.tooltip}`)
        };
    }

    _createReactiveState(raw) {
        const proxyCache = new WeakMap();

        const isPlainObject = (v) => {
            if (!v || typeof v !== 'object') return false;
            const proto = Object.getPrototypeOf(v);
            return proto === Object.prototype || proto === null;
        };

        const isProxyable = (v) => Array.isArray(v) || isPlainObject(v);

        const isDomNode = (v) => {
            try {
                return v instanceof Node;
            } catch (e) {
                return false;
            }
        };

        const wrap = (obj, path) => {
            if (!obj || typeof obj !== 'object') return obj;
            if (!isProxyable(obj)) return obj;
            if (isDomNode(obj)) return obj;
            if (proxyCache.has(obj)) return proxyCache.get(obj);

            const p = new Proxy(obj, {
                get: (target, prop) => {
                    const v = target[prop];
                    if (!v || typeof v !== 'object') return v;
                    if (!isProxyable(v)) return v;
                    if (isDomNode(v)) return v;
                    const nextPath = path ? `${path}.${String(prop)}` : String(prop);
                    return wrap(v, nextPath);
                },
                set: (target, prop, value) => {
                    const key = String(prop);
                    const nextPath = path ? `${path}.${key}` : key;
                    const prev = target[prop];
                    target[prop] = value;
                    if (prev !== value) this._onStateChange(nextPath, value, prev);
                    return true;
                },
                deleteProperty: (target, prop) => {
                    const key = String(prop);
                    const nextPath = path ? `${path}.${key}` : key;
                    const prev = target[prop];
                    const ok = delete target[prop];
                    if (ok) this._onStateChange(nextPath, undefined, prev);
                    return ok;
                }
            });

            proxyCache.set(obj, p);
            return p;
        };

        return wrap(raw, '');
    }

    _onStateChange(path, value, prev) {
        const s = this.state;

        if (String(path || '').startsWith('form.')) {
            this._patchValidationUi();
            return;
        }

        const key = String(path || '').split('.').pop();

        if (key === 'overlayEl') {
            if (value && s.avatarsState && s.avatarsState.length) {
                renderCarouselSlots(s, this.elements);
            }
            return;
        }

        if (key === 'avatarsState') {
            if (s.overlayEl && s.avatarsState && s.avatarsState.length) {
                renderCarouselSlots(s, this.elements);
            }
            return;
        }

        if (key === 'avatarIndex') {
            if (!s.overlayEl) return;
            if (!s.avatarsState || !s.avatarsState.length) return;
            renderCarouselSlots(s, this.elements, prev);
            const selected = s.avatarsState[modulo(s.avatarIndex, s.avatarsState.length)];
            if (selected) announce(`Avatar cambiado a ${String(selected.name || 'Avatar')}`, s, this.elements);
        }
    }

    _patchValidationUi() {
        const el = this.elements;
        if (!el) return;

        const epic = el.epic;
        const pub = el.pub;
        const country = el.country;
        const wa = el.wa;
        const saveBtn = el.saveBtn;
        const epicBox = el.epicBox;
        const pubBox = el.pubBox;
        const countryBox = el.countryBox;
        const waBox = el.waBox;
        // country/countryBox may be null when the country selector is hidden
        // (e.g. for Peru users) — that's fine, treat as valid.
        if (!epic || !pub || !wa || !saveBtn || !epicBox || !pubBox || !waBox) return;

        const countryHidden = !country;
        const epicLocked = epic.disabled === true;
        const pubLocked = pub.disabled === true;
        const countryLocked = countryHidden || country.disabled === true;
        const waLocked = wa.disabled === true;

        const epicOk = epicLocked ? true : VALIDATION_SCHEMA.game_username.validate(this.state.form.game_username);
        const pubOk = pubLocked ? true : VALIDATION_SCHEMA.public_name.validate(this.state.form.public_name);
        const countryOk = countryHidden ? true : (countryLocked ? true : VALIDATION_SCHEMA.country_iso2.validate(this.state.form.country_iso2));
        const waOk = waLocked ? true : VALIDATION_SCHEMA.whatsapp.validate(this.state.form.whatsapp);

        if (!epicLocked) {
            epicBox.classList.toggle('is-valid', epicOk);
            epicBox.classList.toggle('is-invalid', !epicOk && !isBlank(epic.value));
            epic.setAttribute('aria-invalid', epicOk ? 'false' : 'true');
        } else {
            epicBox.classList.remove('is-valid', 'is-invalid');
            epic.setAttribute('aria-invalid', 'false');
        }

        if (!pubLocked) {
            pubBox.classList.toggle('is-valid', pubOk);
            pubBox.classList.toggle('is-invalid', !pubOk && !isBlank(pub.value));
            pub.setAttribute('aria-invalid', pubOk ? 'false' : 'true');
        } else {
            pubBox.classList.remove('is-valid', 'is-invalid');
            pub.setAttribute('aria-invalid', 'false');
        }

        if (!countryHidden) {
            if (!countryLocked) {
                countryBox.classList.toggle('is-valid', countryOk);
                countryBox.classList.toggle('is-invalid', !countryOk && !isBlank(country.dataset.value || ''));
                country.setAttribute('aria-invalid', countryOk ? 'false' : 'true');
            } else {
                countryBox.classList.remove('is-valid', 'is-invalid');
                country.setAttribute('aria-invalid', 'false');
            }
        }

        if (!waLocked) {
            waBox.classList.toggle('is-valid', waOk);
            waBox.classList.toggle('is-invalid', !waOk && !isBlank(wa.value));
            wa.setAttribute('aria-invalid', waOk ? 'false' : 'true');
        } else {
            waBox.classList.remove('is-valid', 'is-invalid');
            wa.setAttribute('aria-invalid', 'false');
        }

        const allOk = epicOk && pubOk && countryOk && waOk;
        saveBtn.disabled = !allOk;
        saveBtn.setAttribute('aria-disabled', allOk ? 'false' : 'true');
    }

    ensure({ dashboardData, apiEndpoint }) {
        try {
            if (!dashboardData || dashboardData.status !== 'success') return;

            const s = this.state;
            s.currentApiEndpoint = apiEndpoint || s.currentApiEndpoint;
            s.dashboardData = dashboardData;

            const user = dashboardData.user || {};
            s.userSnapshot = user;

            // When the country selector is hidden (e.g. Peru, auto-detected),
            // a blank country_iso2 in the DB should NOT be treated as "incomplete".
            const countryAutoDetected = shouldHideCountrySelectorOnboarding(
                user?.country_iso2, dashboardData
            );
            const required = isBlank(user.game_username) || isPlaceholderUsername(user.game_username)
                || isBlank(user.public_display_name) || isPlaceholderUsername(user.public_display_name)
                || (!countryAutoDetected && isBlank(user.country_iso2))
                || isBlank(user.whatsapp_phone_e164);

            if (!required) {
                hideOverlay(s, this.elements);
                this.elements = null;
                return;
            }

            s.avatarsState = Array.isArray(dashboardData.avatars_list) ? dashboardData.avatars_list : [];
            if (!s.avatarsState.length) return;

            if (!s.overlayEl) {
                setAvatarIndexByAvatarId(s, user.avatar_id);
            }

            showOverlay(s, user);
            this._cacheElements();

            if (this.elements?.epic) {
                const v = VALIDATION_SCHEMA.game_username.normalize(this.elements.epic.value || '');
                if (this.elements.epic.value !== v) this.elements.epic.value = v;
                s.form.game_username = v;
            }
            if (this.elements?.pub) {
                const v = VALIDATION_SCHEMA.public_name.normalize(this.elements.pub.value || '');
                if (this.elements.pub.value !== v) this.elements.pub.value = v;
                s.form.public_name = v;
            }
            if (this.elements?.country) {
                const v = VALIDATION_SCHEMA.country_iso2.normalize(this.elements.country.dataset.value || '');
                this.elements.country.dataset.value = v;
                s.form.country_iso2 = v;
            }
            if (this.elements?.wa) {
                const v = VALIDATION_SCHEMA.whatsapp.normalize(this.elements.wa.value || '');
                if (this.elements.wa.value !== v) this.elements.wa.value = v;
                s.form.whatsapp = v;
            }

            this._patchValidationUi();
            bindEvents(s, this.elements);
        } catch (e) {
            try {
                console.error('Onboarding modal failed:', e);
            } catch (err) {
            }
            hideOverlay(this.state, this.elements);
            this.elements = null;
        }
    }

    destroy() {
        hideOverlay(this.state, this.elements);
        this.elements = null;
        this.state = this._createReactiveState(createOnboardingState());
    }
}

let onboardingModalSingleton = null;

function getOnboardingModalSingleton() {
    if (!onboardingModalSingleton) onboardingModalSingleton = new OnboardingModal();
    return onboardingModalSingleton;
}

const CONSTANTS = Object.freeze({
    ids: Object.freeze({
        overlay: 'onboardingOverlay',
        title: 'onboardingTitle',
        email: 'onboardingEmail',
        epic: 'onboardingEpic',
        epicBox: 'onboardingEpicBox',
        pub: 'onboardingPublic',
        pubBox: 'onboardingPublicBox',
        country: 'onboardingCountry',
        countryBox: 'onboardingCountryBox',
        countryList: 'onboardingCountryList',
        whatsapp: 'onboardingWhatsapp',
        whatsappBox: 'onboardingWhatsappBox',
        save: 'onboardingSave',
        error: 'onboardingError',
        live: 'onboardingLive',
        tooltip: 'onboardingTooltip',
        carouselSlots: 'onboardingCarouselSlots'
    }),
    validation: Object.freeze({
        epicMin: 3,
        epicMax: 16,
        publicMin: 3,
        publicMax: 20,
        whatsappDigits: 9
    }),
    ui: Object.freeze({
        debounceMs: 300,
        swipeThresholdPx: 32
    })
});

const VALIDATION_SCHEMA = Object.freeze({
    game_username: Object.freeze({
        normalize: (v) => normalizeUserText(v),
        validate: (v) => validateEpicUsername(v)
    }),
    public_name: Object.freeze({
        normalize: (v) => normalizePublicName(v),
        validate: (v) => validatePublicName(v)
    }),
    country_iso2: Object.freeze({
        normalize: (v) => normalizeCountryIso2(v),
        validate: (v) => validateCountryIso2(v)
    }),
    whatsapp: Object.freeze({
        normalize: (v) => normalizeWhatsappDigits(v),
        validate: (v) => validateWhatsapp(v)
    })
});

const COUNTRIES = Object.freeze([
    { iso2: 'pe', name: 'Perú', flag: '🇵🇪', dial: '+51' },
    { iso2: 'mx', name: 'México', flag: '🇲🇽', dial: '+52' },
    { iso2: 'cl', name: 'Chile', flag: '🇨🇱', dial: '+56' },
    { iso2: 'co', name: 'Colombia', flag: '🇨🇴', dial: '+57' },
    { iso2: 'ar', name: 'Argentina', flag: '🇦🇷', dial: '+54' },
    { iso2: 'ec', name: 'Ecuador', flag: '🇪🇨', dial: '+593' },
    { iso2: 'bo', name: 'Bolivia', flag: '🇧🇴', dial: '+591' },
    { iso2: 'uy', name: 'Uruguay', flag: '🇺🇾', dial: '+598' },
    { iso2: 'py', name: 'Paraguay', flag: '🇵🇾', dial: '+595' },
    { iso2: 'br', name: 'Brasil', flag: '🇧🇷', dial: '+55' },
    { iso2: 've', name: 'Venezuela', flag: '🇻🇪', dial: '+58' },
    { iso2: 'pa', name: 'Panamá', flag: '🇵🇦', dial: '+507' },
    { iso2: 'cr', name: 'Costa Rica', flag: '🇨🇷', dial: '+506' },
    { iso2: 'gt', name: 'Guatemala', flag: '🇬🇹', dial: '+502' },
    { iso2: 'sv', name: 'El Salvador', flag: '🇸🇻', dial: '+503' },
    { iso2: 'hn', name: 'Honduras', flag: '🇭🇳', dial: '+504' },
    { iso2: 'ni', name: 'Nicaragua', flag: '🇳🇮', dial: '+505' },
    { iso2: 'do', name: 'República Dominicana', flag: '🇩🇴', dial: '+1' },
    { iso2: 'pr', name: 'Puerto Rico', flag: '🇵🇷', dial: '+1' },
    { iso2: 'es', name: 'España', flag: '🇪🇸', dial: '+34' },
    { iso2: 'us', name: 'Estados Unidos', flag: '🇺🇸', dial: '+1' },
    { iso2: 'ca', name: 'Canadá', flag: '🇨🇦', dial: '+1' },
    { iso2: 'gb', name: 'Reino Unido', flag: '🇬🇧', dial: '+44' },
    { iso2: 'fr', name: 'Francia', flag: '🇫🇷', dial: '+33' },
    { iso2: 'de', name: 'Alemania', flag: '🇩🇪', dial: '+49' },
    { iso2: 'it', name: 'Italia', flag: '🇮🇹', dial: '+39' },
    { iso2: 'pt', name: 'Portugal', flag: '🇵🇹', dial: '+351' },
    { iso2: 'nl', name: 'Países Bajos', flag: '🇳🇱', dial: '+31' },
    { iso2: 'be', name: 'Bélgica', flag: '🇧🇪', dial: '+32' },
    { iso2: 'ch', name: 'Suiza', flag: '🇨🇭', dial: '+41' },
    { iso2: 'se', name: 'Suecia', flag: '🇸🇪', dial: '+46' },
    { iso2: 'no', name: 'Noruega', flag: '🇳🇴', dial: '+47' },
    { iso2: 'dk', name: 'Dinamarca', flag: '🇩🇰', dial: '+45' },
    { iso2: 'fi', name: 'Finlandia', flag: '🇫🇮', dial: '+358' },
    { iso2: 'ie', name: 'Irlanda', flag: '🇮🇪', dial: '+353' },
    { iso2: 'pl', name: 'Polonia', flag: '🇵🇱', dial: '+48' },
    { iso2: 'cz', name: 'Chequia', flag: '🇨🇿', dial: '+420' },
    { iso2: 'at', name: 'Austria', flag: '🇦🇹', dial: '+43' },
    { iso2: 'au', name: 'Australia', flag: '🇦🇺', dial: '+61' },
    { iso2: 'nz', name: 'Nueva Zelanda', flag: '🇳🇿', dial: '+64' },
    { iso2: 'jp', name: 'Japón', flag: '🇯🇵', dial: '+81' },
    { iso2: 'kr', name: 'Corea del Sur', flag: '🇰🇷', dial: '+82' },
    { iso2: 'cn', name: 'China', flag: '🇨🇳', dial: '+86' },
    { iso2: 'in', name: 'India', flag: '🇮🇳', dial: '+91' },
    { iso2: 'ru', name: 'Rusia', flag: '🇷🇺', dial: '+7' },
    { iso2: 'ua', name: 'Ucrania', flag: '🇺🇦', dial: '+380' },
    { iso2: 'ro', name: 'Rumanía', flag: '🇷🇴', dial: '+40' },
    { iso2: 'bg', name: 'Bulgaria', flag: '🇧🇬', dial: '+359' },
    { iso2: 'gr', name: 'Grecia', flag: '🇬🇷', dial: '+30' },
    { iso2: 'hu', name: 'Hungría', flag: '🇭🇺', dial: '+36' },
    { iso2: 'sk', name: 'Eslovaquia', flag: '🇸🇰', dial: '+421' },
    { iso2: 'si', name: 'Eslovenia', flag: '🇸🇮', dial: '+386' },
    { iso2: 'hr', name: 'Croacia', flag: '🇭🇷', dial: '+385' },
    { iso2: 'ba', name: 'Bosnia y Herzegovina', flag: '🇧🇦', dial: '+387' },
    { iso2: 'rs', name: 'Serbia', flag: '🇷🇸', dial: '+381' },
    { iso2: 'me', name: 'Montenegro', flag: '🇲🇪', dial: '+382' },
    { iso2: 'al', name: 'Albania', flag: '🇦🇱', dial: '+355' },
    { iso2: 'mk', name: 'Macedonia del Norte', flag: '🇲🇰', dial: '+389' },
    { iso2: 'lt', name: 'Lituania', flag: '🇱🇹', dial: '+370' },
    { iso2: 'lv', name: 'Letonia', flag: '🇱🇻', dial: '+371' },
    { iso2: 'ee', name: 'Estonia', flag: '🇪🇪', dial: '+372' },
    { iso2: 'by', name: 'Bielorrusia', flag: '🇧🇾', dial: '+375' },
    { iso2: 'is', name: 'Islandia', flag: '🇮🇸', dial: '+354' },
    { iso2: 'lu', name: 'Luxemburgo', flag: '🇱🇺', dial: '+352' },
    { iso2: 'za', name: 'Sudáfrica', flag: '🇿🇦', dial: '+27' },
    { iso2: 'ng', name: 'Nigeria', flag: '🇳🇬', dial: '+234' },
    { iso2: 'ke', name: 'Kenia', flag: '🇰🇪', dial: '+254' },
    { iso2: 'eg', name: 'Egipto', flag: '🇪🇬', dial: '+20' },
    { iso2: 'ma', name: 'Marruecos', flag: '🇲🇦', dial: '+212' },
    { iso2: 'dz', name: 'Argelia', flag: '🇩🇿', dial: '+213' },
    { iso2: 'tn', name: 'Túnez', flag: '🇹🇳', dial: '+216' },
    { iso2: 'gh', name: 'Ghana', flag: '🇬🇭', dial: '+233' },
    { iso2: 'sn', name: 'Senegal', flag: '🇸🇳', dial: '+221' },
    { iso2: 'ci', name: 'Costa de Marfil', flag: '🇨🇮', dial: '+225' },
    { iso2: 'cm', name: 'Camerún', flag: '🇨🇲', dial: '+237' },
    { iso2: 'ug', name: 'Uganda', flag: '🇺🇬', dial: '+256' },
    { iso2: 'tz', name: 'Tanzania', flag: '🇹🇿', dial: '+255' },
    { iso2: 'rw', name: 'Ruanda', flag: '🇷🇼', dial: '+250' },
    { iso2: 'ao', name: 'Angola', flag: '🇦🇴', dial: '+244' },
    { iso2: 'na', name: 'Namibia', flag: '🇳🇦', dial: '+264' },
    { iso2: 'bw', name: 'Botsuana', flag: '🇧🇼', dial: '+267' },
    { iso2: 'zm', name: 'Zambia', flag: '🇿🇲', dial: '+260' },
    { iso2: 'zw', name: 'Zimbabue', flag: '🇿🇼', dial: '+263' },
    { iso2: 'mz', name: 'Mozambique', flag: '🇲🇿', dial: '+258' },
    { iso2: 'mg', name: 'Madagascar', flag: '🇲🇬', dial: '+261' },
    { iso2: 'mu', name: 'Mauricio', flag: '🇲🇺', dial: '+230' },
    { iso2: 'sd', name: 'Sudán', flag: '🇸🇩', dial: '+249' },
    { iso2: 'so', name: 'Somalia', flag: '🇸🇴', dial: '+252' },
    { iso2: 'ly', name: 'Libia', flag: '🇱🇾', dial: '+218' },
    { iso2: 'ml', name: 'Malí', flag: '🇲🇱', dial: '+223' },
    { iso2: 'ne', name: 'Níger', flag: '🇳🇪', dial: '+227' },
    { iso2: 'bf', name: 'Burkina Faso', flag: '🇧🇫', dial: '+226' },
    { iso2: 'gn', name: 'Guinea', flag: '🇬🇳', dial: '+224' },
    { iso2: 'sl', name: 'Sierra Leona', flag: '🇸🇱', dial: '+232' },
    { iso2: 'lr', name: 'Liberia', flag: '🇱🇷', dial: '+231' },
    { iso2: 'tg', name: 'Togo', flag: '🇹🇬', dial: '+228' },
    { iso2: 'bj', name: 'Benín', flag: '🇧🇯', dial: '+229' },
    { iso2: 'ga', name: 'Gabón', flag: '🇬🇦', dial: '+241' },
    { iso2: 'cg', name: 'República del Congo', flag: '🇨🇬', dial: '+242' },
    { iso2: 'cd', name: 'República Democrática del Congo', flag: '🇨🇩', dial: '+243' },
    { iso2: 'cf', name: 'República Centroafricana', flag: '🇨🇫', dial: '+236' },
    { iso2: 'td', name: 'Chad', flag: '🇹🇩', dial: '+235' },
    { iso2: 'gq', name: 'Guinea Ecuatorial', flag: '🇬🇶', dial: '+240' },
    { iso2: 'et', name: 'Etiopía', flag: '🇪🇹', dial: '+251' },
    { iso2: 'ae', name: 'Emiratos Árabes Unidos', flag: '🇦🇪', dial: '+971' },
    { iso2: 'sa', name: 'Arabia Saudita', flag: '🇸🇦', dial: '+966' },
    { iso2: 'qa', name: 'Catar', flag: '🇶🇦', dial: '+974' },
    { iso2: 'kw', name: 'Kuwait', flag: '🇰🇼', dial: '+965' },
    { iso2: 'om', name: 'Omán', flag: '🇴🇲', dial: '+968' },
    { iso2: 'jo', name: 'Jordania', flag: '🇯🇴', dial: '+962' },
    { iso2: 'lb', name: 'Líbano', flag: '🇱🇧', dial: '+961' },
    { iso2: 'il', name: 'Israel', flag: '🇮🇱', dial: '+972' },
    { iso2: 'tr', name: 'Turquía', flag: '🇹🇷', dial: '+90' },
    { iso2: 'ir', name: 'Irán', flag: '🇮🇷', dial: '+98' },
    { iso2: 'iq', name: 'Irak', flag: '🇮🇶', dial: '+964' },
    { iso2: 'ye', name: 'Yemen', flag: '🇾🇪', dial: '+967' },
    { iso2: 'pk', name: 'Pakistán', flag: '🇵🇰', dial: '+92' },
    { iso2: 'bd', name: 'Bangladés', flag: '🇧🇩', dial: '+880' },
    { iso2: 'lk', name: 'Sri Lanka', flag: '🇱🇰', dial: '+94' },
    { iso2: 'np', name: 'Nepal', flag: '🇳🇵', dial: '+977' },
    { iso2: 'mm', name: 'Myanmar', flag: '🇲🇲', dial: '+95' },
    { iso2: 'th', name: 'Tailandia', flag: '🇹🇭', dial: '+66' },
    { iso2: 'vn', name: 'Vietnam', flag: '🇻🇳', dial: '+84' },
    { iso2: 'kh', name: 'Camboya', flag: '🇰🇭', dial: '+855' },
    { iso2: 'la', name: 'Laos', flag: '🇱🇦', dial: '+856' },
    { iso2: 'my', name: 'Malasia', flag: '🇲🇾', dial: '+60' },
    { iso2: 'sg', name: 'Singapur', flag: '🇸🇬', dial: '+65' },
    { iso2: 'id', name: 'Indonesia', flag: '🇮🇩', dial: '+62' },
    { iso2: 'ph', name: 'Filipinas', flag: '🇵🇭', dial: '+63' },
    { iso2: 'mn', name: 'Mongolia', flag: '🇲🇳', dial: '+976' },
    { iso2: 'kz', name: 'Kazajistán', flag: '🇰🇿', dial: '+7' },
    { iso2: 'uz', name: 'Uzbekistán', flag: '🇺🇿', dial: '+998' },
    { iso2: 'af', name: 'Afganistán', flag: '🇦🇫', dial: '+93' },
    { iso2: 'az', name: 'Azerbaiyán', flag: '🇦🇿', dial: '+994' },
    { iso2: 'cu', name: 'Cuba', flag: '🇨🇺', dial: '+53' },
    { iso2: 'ht', name: 'Haití', flag: '🇭🇹', dial: '+509' },
    { iso2: 'jm', name: 'Jamaica', flag: '🇯🇲', dial: '+1' },
    { iso2: 'tt', name: 'Trinidad y Tobago', flag: '🇹🇹', dial: '+1' },
    { iso2: 'bz', name: 'Belice', flag: '🇧🇿', dial: '+501' },
    { iso2: 'fj', name: 'Fiyi', flag: '🇫🇯', dial: '+679' },
    { iso2: 'pg', name: 'Papúa Nueva Guinea', flag: '🇵🇬', dial: '+675' },
    { iso2: 'ws', name: 'Samoa', flag: '🇼🇸', dial: '+685' },
    { iso2: 'to', name: 'Tonga', flag: '🇹🇴', dial: '+676' },
    { iso2: 'sb', name: 'Islas Salomón', flag: '🇸🇧', dial: '+677' }
]);

const COUNTRIES_BY_ISO2 = (() => {
    const m = Object.create(null);
    for (const c of COUNTRIES) {
        const k = normalizeCountryIso2(c.iso2);
        if (k) m[k] = c;
    }
    return m;
})();

function getCountryLabel(iso2) {
    const k = normalizeCountryIso2(iso2);
    const c = COUNTRIES_BY_ISO2[k];
    if (!c) return '';
    return `${c.name}`;
}

function createCountryFlagImg(iso2, { lazy = false } = {}) {
    const code = normalizeCountryIso2(iso2).slice(0, 2);
    const img = document.createElement('img');
    img.className = 'country-flag';
    img.alt = '';
    img.setAttribute('aria-hidden', 'true');
    img.width = 20;
    img.height = 15;
    img.decoding = 'async';
    if (lazy) img.loading = 'lazy';
    img.src = `https://flagcdn.com/${code}.svg`;
    let triedFallback = false;
    img.addEventListener('error', () => {
        if (triedFallback) {
            img.style.visibility = 'hidden';
            return;
        }
        triedFallback = true;
        img.src = `https://flagcdn.com/w20/${code}.png`;
        img.srcset = `https://flagcdn.com/w40/${code}.png 2x`;
    });
    return img;
}

function renderCountryLabelEl(labelEl, iso2, { placeholder = '' } = {}) {
    if (!(labelEl instanceof HTMLElement)) return;
    const k = normalizeCountryIso2(iso2);
    const c = COUNTRIES_BY_ISO2[k];

    while (labelEl.firstChild) labelEl.removeChild(labelEl.firstChild);

    if (!c) {
        labelEl.textContent = String(placeholder || '');
        return;
    }

    const img = createCountryFlagImg(c.iso2, { lazy: false });
    const text = document.createElement('span');
    text.className = 'country-trigger-text';
    text.textContent = c.name;
    labelEl.appendChild(img);
    labelEl.appendChild(text);
}

function detectUserCountryOnboarding(dashboardData) {
    const backend = typeof dashboardData?.country === 'string' ? dashboardData.country.toLowerCase().trim() : '';
    if (/^[a-z]{2}$/.test(backend)) return backend;

    try {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (typeof tz === 'string' && /^America\/Lima$/i.test(tz.trim())) return 'pe';
    } catch (_) { }

    return 'pe';
}

const DETECTED_COUNTRY_ONBOARDING = detectUserCountryOnboarding({});

function shouldHideCountrySelectorOnboarding(savedCountryIso2, dashboardData) {
    const saved = normalizeCountryIso2(savedCountryIso2);
    if (saved === 'pe') return true;
    const detected = detectUserCountryOnboarding(dashboardData);
    return detected === 'pe';
}

function debounce(delayMs, fn) {
    let t = 0;
    return (...args) => {
        if (t) window.clearTimeout(t);
        t = window.setTimeout(() => fn(...args), delayMs);
    };
}

function getFocusableElements(root) {
    const els = root.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    return Array.from(els).filter((el) => {
        if (!(el instanceof HTMLElement)) return false;
        if (el.hasAttribute('disabled')) return false;
        return el.tabIndex >= 0;
    });
}

function announce(message, state, elements) {
    const liveEl = elements?.live || state.overlayEl?.querySelector?.(`#${CONSTANTS.ids.live}`);
    if (!liveEl) return;
    liveEl.textContent = '';
    window.setTimeout(() => {
        liveEl.textContent = String(message || '');
    }, 20);
}

function hideTooltip(state, elements) {
    const tip = elements?.tooltip || state.overlayEl?.querySelector?.(`#${CONSTANTS.ids.tooltip}`);
    if (!tip) return;
    tip.textContent = '';
    tip.classList.add('is-hidden');
    if (state.tooltipAnchor) {
        try {
            state.tooltipAnchor.removeAttribute('aria-describedby');
        } catch (e) {
        }
    }
    state.tooltipAnchor = null;
}

function showTooltipForAnchor(anchorEl, state, elements) {
    const tip = elements?.tooltip || state.overlayEl?.querySelector?.(`#${CONSTANTS.ids.tooltip}`);
    if (!tip) return;
    const msg = String(anchorEl?.dataset?.tooltip || '').trim();
    if (!msg) return;

    tip.textContent = msg;
    tip.classList.remove('is-hidden');
    tip.style.left = '0px';
    tip.style.top = '0px';

    const pad = 12;
    const offset = 10;
    const rect = anchorEl.getBoundingClientRect();
    const tipRect = tip.getBoundingClientRect();

    let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
    left = Math.max(pad, Math.min(left, window.innerWidth - pad - tipRect.width));

    let top = rect.top - offset - tipRect.height;
    let placement = 'top';
    if (top < pad) {
        top = rect.bottom + offset;
        placement = 'bottom';
    }
    top = Math.max(pad, Math.min(top, window.innerHeight - pad - tipRect.height));

    tip.style.left = `${left}px`;
    tip.style.top = `${top}px`;

    try {
        tip.setAttribute('data-placement', placement);
    } catch (e) {
    }

    try {
        let arrowX = (rect.left + (rect.width / 2)) - left;
        arrowX = Math.max(12, Math.min(arrowX, tipRect.width - 12));
        tip.style.setProperty('--onboarding-tooltip-arrow-x', `${arrowX}px`);
    } catch (e) {
    }

    state.tooltipAnchor = anchorEl;
    try {
        anchorEl.setAttribute('aria-describedby', CONSTANTS.ids.tooltip);
    } catch (e) {
    }
}

function setInertOnBackground(enabled, state) {
    const targets = [];
    const nav = document.querySelector('nav');
    const main = document.querySelector('main');
    if (nav instanceof HTMLElement) targets.push(nav);
    if (main instanceof HTMLElement) targets.push(main);

    if (enabled) {
        state.inertTargets = targets;
        targets.forEach((el) => {
            try {
                el.setAttribute('inert', '');
                el.setAttribute('aria-hidden', 'true');
            } catch (e) {
            }
        });
        return;
    }

    (state.inertTargets || []).forEach((el) => {
        try {
            el.removeAttribute('inert');
            el.removeAttribute('aria-hidden');
        } catch (e) {
        }
    });
    state.inertTargets = [];
}

function isBlank(v) {
    return v === null || v === undefined || String(v).trim() === '';
}

function setInlineError(message, state, elements) {
    const el = elements?.error || state.overlayEl?.querySelector?.(`#${CONSTANTS.ids.error}`);
    if (!el) return;

    const msg = String(message || '').trim();
    if (!msg) {
        el.textContent = '';
        el.classList.add('is-hidden');
        announce('', state, elements);
        return;
    }

    el.textContent = msg;
    el.classList.remove('is-hidden');
    announce(msg, state, elements);
}

function isPlaceholderUsername(v) {
    if (v === null || v === undefined) return false;
    const s = String(v).trim();
    if (!s) return false;
    return /^Usuario_[a-f0-9]{4,}$/i.test(s);
}

function stripInvisibleChars(v) {
    return String(v ?? '').replace(/[\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/g, '');
}

function normalizeUserText(v) {
    return stripInvisibleChars(v).trim();
}

function setButtonLoading(btn, loading) {
    if (!btn) return;
    if (loading) {
        if (!btn.dataset.originalText) btn.dataset.originalText = btn.textContent || '';
        btn.textContent = 'Guardando…';
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '16');
        svg.setAttribute('height', '16');
        svg.setAttribute('aria-hidden', 'true');
        svg.style.marginLeft = '8px';
        try {
            const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!reduce) svg.style.animation = 'onboarding-spin 0.9s linear infinite';
        } catch (e) {
            svg.style.animation = 'onboarding-spin 0.9s linear infinite';
        }

        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '12');
        circle.setAttribute('cy', '12');
        circle.setAttribute('r', '9');
        circle.setAttribute('fill', 'none');
        circle.setAttribute('stroke', 'currentColor');
        circle.setAttribute('stroke-width', '3');
        circle.setAttribute('stroke-linecap', 'round');
        circle.setAttribute('stroke-dasharray', '42');
        circle.setAttribute('stroke-dashoffset', '12');
        svg.appendChild(circle);
        btn.appendChild(svg);
        return;
    }

    const nextText = btn.dataset.originalText || 'Guardar y continuar';
    btn.textContent = nextText;
}

function normalizeInitialName(v) {
    if (isPlaceholderUsername(v)) return '';
    return normalizeUserText(v);
}

function validateEpicUsername(v) {
    const s = normalizeUserText(v);
    return s.length >= CONSTANTS.validation.epicMin && s.length <= CONSTANTS.validation.epicMax;
}

function normalizePublicName(v) {
    return stripInvisibleChars(v).replace(/[^a-zA-Z0-9 _.]/g, '').replace(/\s{2,}/g, ' ').trim();
}

function validatePublicName(v) {
    const s = normalizePublicName(v);
    if (s.length < CONSTANTS.validation.publicMin || s.length > CONSTANTS.validation.publicMax) return false;
    if (!/[a-zA-Z]/.test(s)) return false;
    return true;
}

function normalizeWhatsappDigits(v) {
    return stripInvisibleChars(v).replace(/\D+/g, '').slice(0, CONSTANTS.validation.whatsappDigits);
}

function normalizeCountryIso2(v) {
    return String(v ?? '').toLowerCase().replace(/[^a-z]/g, '').slice(0, 5);
}

function foldSearchText(v) {
    const s = String(v ?? '');
    try {
        return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    } catch (e) {
        return s.toLowerCase();
    }
}

function validateWhatsapp(v) {
    const s = normalizeWhatsappDigits(v);
    return s.length === CONSTANTS.validation.whatsappDigits;
}

function validateCountryIso2(v) {
    const s = normalizeCountryIso2(v);
    if (s.length !== 2) return false;
    return Boolean(COUNTRIES_BY_ISO2[s]);
}

function getFieldRule(user, field) {
    const rules = user?.edit_rules || {};
    return rules?.[field] || null;
}

function shouldLockPrefilledField(user, payloadFieldName, ruleKey) {
    const raw = user?.[payloadFieldName];
    if (isBlank(raw)) return false;
    if (isPlaceholderUsername(raw)) return false;
    return true;
}

function buildLockTooltip(user, ruleKey) {
    const rule = getFieldRule(user, ruleKey);
    const next = String(rule?.next || '').trim();
    if (next) return `Disponible el: ${next}`;
    return 'Disponible el: Próximamente';
}

function createSvgEl(tag) {
    return document.createElementNS('http://www.w3.org/2000/svg', tag);
}

function buildFilterDefsSvg() {
    const svg = createSvgEl('svg');
    svg.setAttribute('width', '0');
    svg.setAttribute('height', '0');
    svg.style.position = 'absolute';

    const defs = createSvgEl('defs');
    const filter = createSvgEl('filter');
    filter.setAttribute('id', 'filter0_d_2720_2296');
    filter.setAttribute('x', '0.938774');
    filter.setAttribute('y', '0.938774');
    filter.setAttribute('width', '106.123');
    filter.setAttribute('height', '106.123');
    filter.setAttribute('filterUnits', 'userSpaceOnUse');
    filter.setAttribute('color-interpolation-filters', 'sRGB');

    const feFlood = createSvgEl('feFlood');
    feFlood.setAttribute('flood-opacity', '0');
    feFlood.setAttribute('result', 'BackgroundImageFix');

    const feColorMatrix1 = createSvgEl('feColorMatrix');
    feColorMatrix1.setAttribute('in', 'SourceAlpha');
    feColorMatrix1.setAttribute('type', 'matrix');
    feColorMatrix1.setAttribute('values', '0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0');
    feColorMatrix1.setAttribute('result', 'hardAlpha');

    const feMorphology = createSvgEl('feMorphology');
    feMorphology.setAttribute('radius', '0.912711');
    feMorphology.setAttribute('operator', 'dilate');
    feMorphology.setAttribute('in', 'SourceAlpha');
    feMorphology.setAttribute('result', 'effect1_dropShadow_2720_2296');

    const feOffset = createSvgEl('feOffset');

    const feGaussianBlur = createSvgEl('feGaussianBlur');
    feGaussianBlur.setAttribute('stdDeviation', '2.28178');

    const feColorMatrix2 = createSvgEl('feColorMatrix');
    feColorMatrix2.setAttribute('type', 'matrix');
    feColorMatrix2.setAttribute('values', '0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.8 0');

    const feBlend1 = createSvgEl('feBlend');
    feBlend1.setAttribute('mode', 'normal');
    feBlend1.setAttribute('in2', 'BackgroundImageFix');
    feBlend1.setAttribute('result', 'effect1_dropShadow_2720_2296');

    const feBlend2 = createSvgEl('feBlend');
    feBlend2.setAttribute('mode', 'normal');
    feBlend2.setAttribute('in', 'SourceGraphic');
    feBlend2.setAttribute('in2', 'effect1_dropShadow_2720_2296');
    feBlend2.setAttribute('result', 'shape');

    filter.appendChild(feFlood);
    filter.appendChild(feColorMatrix1);
    filter.appendChild(feMorphology);
    filter.appendChild(feOffset);
    filter.appendChild(feGaussianBlur);
    filter.appendChild(feColorMatrix2);
    filter.appendChild(feBlend1);
    filter.appendChild(feBlend2);

    defs.appendChild(filter);
    svg.appendChild(defs);
    return svg;
}

function sanitizeAvatarFilename(filename) {
    const str = String(filename ?? '');
    return str.replace(/[^a-zA-Z0-9._-]/g, '');
}

const STYLES_CACHE = {
    injected: false,
    /** @type {CSSStyleSheet|null} */
    sheet: null,
    /** @type {HTMLStyleElement|null} */
    styleEl: null
};

function injectStyles(state) {
    const existing = document.getElementById('onboarding-modal-styles');
    if (existing) {
        state.injected = true;
        STYLES_CACHE.injected = true;
        STYLES_CACHE.styleEl = /** @type {HTMLStyleElement} */ (existing);
        return;
    }
    if (STYLES_CACHE.injected || state.injected) {
        state.injected = true;
        return;
    }
    state.injected = true;
    STYLES_CACHE.injected = true;

    const cssText = (`
        .onboarding-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            --onboarding-overlay-bg: rgba(0,0,0,0.70);
            --onboarding-container-bg: rgb(37, 40, 44);
            --onboarding-container-shadow: 0px 8px 16px 0px rgba(0,0,0,0.14), 0px 0px 2px 0px rgba(0,0,0,0.12);

            --onboarding-text: #ffffff;
            --onboarding-text-muted: rgba(255, 255, 255, 0.85);

            --dropdown-bg: #252525;
            --dropdown-text: #ffffff;
            --dropdown-border: #3f3f46;
            --dropdown-hover-bg: rgba(255, 255, 255, 0.10);
            --dropdown-active-bg: rgba(255, 255, 255, 0.10);
            --dropdown-selected-bg: rgba(255, 255, 255, 0.14);

            --onboarding-pill-border: rgba(255,255,255, 0.4);

            --onboarding-input-bg: rgb(37, 40, 44);
            --onboarding-input-border: rgb(107, 117, 132);
            --onboarding-input-hover-border: rgba(255,255,255,0.8);

            --onboarding-tooltip-bg: #1f1f1f;
            --onboarding-tooltip-text: #ffffff;
            --onboarding-tooltip-arrow: #1f1f1f;

            --onboarding-ring-stroke: #DBDBDB;

            --onboarding-btn-bg: #007738;
            --onboarding-btn-hover-bg: #008746;
            --onboarding-btn-text: #ffffff;

            --onboarding-btn-disabled-bg: #174930;
            --onboarding-btn-disabled-text: #739183;
            --onboarding-btn-disabled-border: #174930;

            background-color: var(--onboarding-overlay-bg);
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            color: var(--onboarding-text);
            font-family: "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif;
        }

        :root[data-theme="light"] .onboarding-overlay {
            --onboarding-overlay-bg: rgba(0,0,0,0.35);
            --onboarding-container-bg: var(--dash-card-bg, #ffffff);
            --onboarding-container-shadow: 0px 16px 40px 0px rgba(0,0,0,0.16), 0px 0px 0px 1px rgba(0,0,0,0.08);

            --onboarding-text: var(--dash-text-strong, #09090b);
            --onboarding-text-muted: var(--dash-text, #27272a);

            --dropdown-bg: #ffffff;
            --dropdown-text: var(--dash-text-strong, #09090b);
            --dropdown-border: var(--dash-card-border, #e4e4e7);
            --dropdown-hover-bg: #f0f0f0;
            --dropdown-active-bg: #f0f0f0;
            --dropdown-selected-bg: var(--dash-surface-3, #e4e4e7);

            --onboarding-pill-border: var(--dash-border, rgba(0,0,0, 0.18));

            --onboarding-input-bg: var(--dash-surface-2, #f3f3f3);
            --onboarding-input-border: var(--dash-border-strong, #d4d4d8);
            --onboarding-input-hover-border: var(--dash-input-focus, #a1a1aa);

            --onboarding-tooltip-bg: #111827;
            --onboarding-tooltip-text: #ffffff;
            --onboarding-tooltip-arrow: #111827;

            --onboarding-ring-stroke: var(--dash-text-strong, #111827);

            --onboarding-btn-bg: #007738;
            --onboarding-btn-hover-bg: #008746;
            --onboarding-btn-text: #ffffff;

            --onboarding-btn-disabled-bg: #d7efe0;
            --onboarding-btn-disabled-text: rgba(0, 0, 0, 0.45);
            --onboarding-btn-disabled-border: #cfe9d8;
        }

        :root[data-theme="light"] .onboarding-overlay .highlight-ring g {
            filter: none !important;
        }

        .onboarding-overlay * {
            box-sizing: border-box;
        }

        .onboarding-overlay .onboarding-modal-container {
            width: 440px;
            min-height: 400px;
            background-color: var(--onboarding-container-bg);
            border-radius: 12px;
            padding: 32px 15px;
            position: relative;
            box-shadow: var(--onboarding-container-shadow);
            display: flex;
            flex-direction: column;
        }

        .onboarding-overlay .header {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 16px;
        }

        .onboarding-overlay .email-pill {
            align-self: center;
            border: 1px solid var(--onboarding-pill-border);
            border-radius: 9999px;
            padding: 7px 16px;
            font-size: 12px;
            text-align: center;
            width: fit-content;
            margin: 0 auto;
        }

        .onboarding-overlay .text-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
        }

        .onboarding-overlay h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            text-align: center;
        }

        .onboarding-overlay p.caption {
            font-size: 14px;
            line-height: 20px;
            text-align: center;
            margin: 0;
            padding: 0 15px;
        }

        .onboarding-overlay .carousel-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        .onboarding-overlay .carousel-track {
            height: 110px;
            width: 100%;
            max-width: 100%;
            margin: 15px 0;
            display: flex;
            align-items: center;
            overflow: hidden;
            position: relative;
            justify-content: center;
            touch-action: pan-y;
        }

        .onboarding-overlay .carousel-slots {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            width: 100%;
        }

        .onboarding-overlay .spacer {
            width: 250px;
            height: 30px;
            flex-shrink: 0;
        }

        .onboarding-overlay .avatar-slot {
            flex-shrink: 0;
            position: relative;
            width: calc(72px * var(--avatar-scale, 1));
            height: calc(72px * var(--avatar-scale, 1));
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            cursor: pointer;
            will-change: transform;
            transition: opacity 240ms cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        .onboarding-overlay .avatar-slot img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
            box-shadow: 0px 4px 20px 0px rgba(0,0,0,0.50);
        }

        .onboarding-overlay .scale-0 { --avatar-scale: 0; opacity: 0; }
        .onboarding-overlay .scale-02 { --avatar-scale: 0.2; opacity: 0.2; }
        .onboarding-overlay .scale-047 { --avatar-scale: 0.47; opacity: 0.4; }
        .onboarding-overlay .scale-073 { --avatar-scale: 0.73; opacity: 0.7; }

        .onboarding-overlay .scale-1 {
            --avatar-scale: 1;
            opacity: 1;
            z-index: 10;
            cursor: pointer;
        }

        .onboarding-overlay .highlight-ring {
            position: absolute;
            width: 120%;
            height: 120%;
            top: -10%;
            left: -10%;
            pointer-events: none;
            opacity: 0;
            transition: opacity 180ms cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        .onboarding-overlay .avatar-slot.is-center .highlight-ring {
            opacity: 1;
        }

        .onboarding-overlay .field-group {
            position: relative;
            width: 100%;
            margin-bottom: 20px;
            padding-bottom: 20px;
        }

        .onboarding-overlay .label-title {
            display: inline;
            font-size: 14px;
            font-weight: 700;
            color: var(--onboarding-text);
        }

        .onboarding-overlay .label-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
        }

        .onboarding-overlay .field-info-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            flex-shrink: 0;
            margin-left: auto;
        }

        .onboarding-overlay .field-info-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: var(--onboarding-text-muted);
            cursor: pointer;
            padding: 0;
            transition: color 0.15s ease, opacity 0.15s ease;
            flex-shrink: 0;
            opacity: 0.5;
        }

        .onboarding-overlay .field-info-icon svg {
            display: block;
        }

        .onboarding-overlay .field-info-icon:hover,
        .onboarding-overlay .field-info-icon:focus-visible {
            color: var(--onboarding-text);
            opacity: 0.85;
            outline: none;
        }

        .onboarding-overlay .input-box.has-info input {
            padding-right: 0;
        }

        .onboarding-overlay .field-info-bubble {
            position: absolute;
            right: 0;
            bottom: calc(100% + 12px);
            transform: none;
            background: var(--onboarding-tooltip-bg);
            color: var(--onboarding-tooltip-text);
            font-size: 12px;
            font-weight: 400;
            font-style: normal;
            line-height: 17px;
            padding: 8px 12px;
            border-radius: 8px;
            white-space: normal;
            width: max-content;
            max-width: 260px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            z-index: 20;
            pointer-events: auto;
        }

        .onboarding-overlay .field-info-bubble::after {
            content: "";
            position: absolute;
            right: 4px;
            top: 100%;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid var(--onboarding-tooltip-bg);
        }

        .onboarding-overlay .field-info-bubble.is-hidden {
            display: none;
        }

        .onboarding-overlay .input-box input::placeholder {
            color: var(--onboarding-text-muted);
            opacity: 0.45;
            font-style: normal;
        }

        .onboarding-overlay .field-error {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            gap: 6px;
            color: #d04040;
        }

        .onboarding-overlay .field-error.is-hidden {
            display: none;
        }

        .onboarding-overlay .field-error-icon {
            flex-shrink: 0;
            color: #d04040;
        }

        .onboarding-overlay .field-error-text {
            font-size: 12.5px;
            line-height: 16px;
            font-weight: 500;
            color: #d04040;
        }

        :root[data-theme="dark"] .onboarding-overlay .field-error,
        :root[data-theme="dark"] .onboarding-overlay .field-error-icon,
        :root[data-theme="dark"] .onboarding-overlay .field-error-text {
            color: #f07070;
        }

        :root[data-theme="light"] .onboarding-overlay .field-error,
        :root[data-theme="light"] .onboarding-overlay .field-error-icon,
        :root[data-theme="light"] .onboarding-overlay .field-error-text {
            color: #c03030;
        }

        .onboarding-overlay .input-box {
            display: flex;
            flex-direction: row;
            background-color: var(--onboarding-input-bg);
            border: 1px solid var(--onboarding-input-border);
            border-radius: 8px;
            padding: 0 7px 0 15px;
            height: 28px;
            min-height: 40px;
            align-items: center;
            gap: 7px;
        }

        .onboarding-overlay .input-box.locked,
        .onboarding-overlay .input-box.locked:hover {
            border-color: var(--onboarding-input-border) !important;
            cursor: not-allowed;
        }

        .onboarding-overlay .input-box.locked:focus,
        .onboarding-overlay .input-box.locked:focus-visible {
            outline: none;
            box-shadow: none;
            border-color: var(--onboarding-input-border) !important;
        }

        .onboarding-overlay .input-box.locked input {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .onboarding-overlay .input-box.locked {
            position: relative;
        }

        .onboarding-overlay .input-box.locked::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 50%;
            bottom: calc(100% + 10px);
            transform: translateX(-50%) translateY(2px);
            background: var(--onboarding-tooltip-bg);
            color: var(--onboarding-tooltip-text);
            font-size: 12px;
            line-height: 16px;
            padding: 6px 10px;
            border-radius: 8px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease, transform 0.15s ease;
            z-index: 10;
        }

        .onboarding-overlay .input-box.locked::before {
            content: "";
            position: absolute;
            left: 50%;
            bottom: calc(100% + 4px);
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid var(--onboarding-tooltip-arrow);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease;
            z-index: 10;
        }

        .onboarding-overlay .input-box.locked:hover::after,
        .onboarding-overlay .input-box.locked:hover::before,
        .onboarding-overlay .input-box.locked:focus::after,
        .onboarding-overlay .input-box.locked:focus::before {
            opacity: 1;
        }

        .onboarding-overlay .input-box.locked:hover::after,
        .onboarding-overlay .input-box.locked:focus::after {
            transform: translateX(-50%) translateY(0);
        }

        .onboarding-overlay .input-box:hover:not(.is-valid):not(.is-invalid) {
            border-color: var(--onboarding-input-hover-border);
        }

        .onboarding-overlay .input-box.is-valid {
            border-color: rgba(0, 135, 70, 0.95);
        }

        .onboarding-overlay .input-box.is-invalid {
            border-color: rgba(160, 60, 60, 0.95);
        }

        .onboarding-overlay .input-box input {
            flex-grow: 1;
            background: transparent;
            border: none;
            outline: none;
            font-size: 16px;
            color: var(--onboarding-text);
            padding: 14px 15px 14px 0;
        }

        .onboarding-overlay .country-trigger {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            font-size: 16px;
            color: var(--onboarding-text);
            padding: 14px 15px 14px 0;
            cursor: pointer;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .onboarding-overlay .country-trigger-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
        }

        .onboarding-overlay .country-flag {
            width: 20px;
            height: 15px;
            object-fit: cover;
            border-radius: 3px;
            flex-shrink: 0;
            background: color-mix(in srgb, var(--dropdown-border) 45%, transparent);
        }

        .onboarding-overlay .country-trigger-text {
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .onboarding-overlay .country-trigger:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .onboarding-overlay .country-menu {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 10px);
            background-color: var(--dropdown-bg);
            border: 1px solid var(--dropdown-border);
            border-radius: 16px;
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.60);
            z-index: 30;
            max-height: 260px;
            overflow: auto;
            opacity: 0;
            transform: scale(0.95);
            transform-origin: top;
            transition: opacity 150ms ease, transform 150ms cubic-bezier(0.2, 0.8, 0.2, 1);
            will-change: opacity, transform;
            pointer-events: none;
        }

        .onboarding-overlay .country-menu.is-open {
            opacity: 1;
            transform: scale(1);
            pointer-events: auto;
        }

        .onboarding-overlay .country-menu.is-closing {
            opacity: 0;
            transform: scale(0.95);
            pointer-events: none;
        }

        .onboarding-overlay .country-menu.is-hidden {
            display: none;
        }

        .onboarding-overlay .country-search {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--dropdown-bg);
            padding: 8px;
            border-bottom: 1px solid var(--dropdown-border);
        }

        .onboarding-overlay .country-search-inner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--onboarding-input-bg);
            border: 1px solid var(--onboarding-input-border);
            border-radius: 12px;
            padding: 10px 12px;
        }

        .onboarding-overlay .country-search-inner:focus-within {
            border-color: rgba(255, 255, 255, 0.32);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.08);
        }

        .onboarding-overlay .country-search-icon {
            width: 16px;
            height: 16px;
            opacity: 0.75;
            flex-shrink: 0;
        }

        .onboarding-overlay .country-search-input {
            flex: 1;
            min-width: 0;
            background: transparent;
            border: 0;
            outline: none;
            color: var(--onboarding-text);
            font-size: 14px;
        }

        .onboarding-overlay .country-search-clear {
            border: 0;
            background: transparent;
            color: var(--onboarding-text);
            opacity: 0.7;
            padding: 4px;
            border-radius: 10px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .onboarding-overlay .country-search-clear:hover,
        .onboarding-overlay .country-search-clear:focus-visible {
            opacity: 1;
            outline: none;
            background: var(--dropdown-hover-bg);
        }

        .onboarding-overlay .country-search-clear.is-hidden {
            display: none;
        }

        .onboarding-overlay .country-options {
            position: relative;
            padding: 6px;
            pointer-events: auto;
        }

        .onboarding-overlay .country-no-results {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
            font-size: 13px;
            pointer-events: none;
        }

        .onboarding-overlay .country-no-results.is-hidden {
            display: none;
        }

        .onboarding-overlay .country-option {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: transparent;
            border: 0;
            text-align: left;
            color: var(--dropdown-text);
            cursor: pointer !important;
            font-size: 14px;
            pointer-events: auto;
        }

        .onboarding-overlay .country-option-text {
            min-width: 0;
        }

        .onboarding-overlay .country-option.is-hidden {
            display: none;
        }

        .onboarding-overlay .country-option:hover,
        .onboarding-overlay .country-option:focus-visible {
            outline: none;
            background: var(--dropdown-hover-bg);
        }

        .onboarding-overlay .country-option[aria-selected="true"] {
            background: var(--dropdown-selected-bg);
        }

        .onboarding-overlay .country-option.is-active {
            background: var(--dropdown-active-bg);
        }

        .onboarding-overlay .country-menu::-webkit-scrollbar {
            width: 8px;
        }

        .onboarding-overlay .country-menu::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.22);
            border-radius: 10px;
        }

        .onboarding-overlay .country-menu::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.32);
        }

        .onboarding-overlay .suffix {
            color: var(--onboarding-text);
            opacity: 0.65;
            font-size: 14px;
            align-self: center;
        }

        .onboarding-overlay .status-icon {
            color: var(--onboarding-text);
            opacity: 0.65;
            display: flex;
            align-items: center;
        }

        .onboarding-overlay .highlight-ring circle {
            stroke: var(--onboarding-ring-stroke) !important;
        }

        .onboarding-overlay .footer-actions {
            margin-top: auto;
            display: flex;
            flex-direction: row;
            gap: 16px;
            justify-content: flex-end;
            padding: 15px;
        }

        .onboarding-overlay .btn-primary-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-width: 140px;
            box-sizing: border-box;

            padding: 8px;

            border-radius: 8px;
            border-width: 1px;
            border-style: solid;

            font-family: "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 14px;
            font-weight: 600;
            line-height: 20px;
            text-align: center;
            text-decoration: none;

            outline: none;
            appearance: none;
            transition: all 0.2s ease;
        }

        .onboarding-overlay .btn-primary-action:disabled {
            background-color: var(--onboarding-btn-disabled-bg);
            color: var(--onboarding-btn-disabled-text);
            border-color: var(--onboarding-btn-disabled-border);

            cursor: default;
            pointer-events: none;
        }

        .onboarding-overlay .btn-primary-action:not(:disabled) {
            background-color: var(--onboarding-btn-bg);
            color: var(--onboarding-btn-text);
            border-color: transparent;
            cursor: pointer;
        }

        .onboarding-overlay .btn-primary-action:not(:disabled):hover {
            background-color: var(--onboarding-btn-hover-bg);
        }

        .onboarding-overlay .onboarding-error {
            width: 100%;
            padding: 0 15px;
            margin-top: 6px;
            min-height: 18px;
            font-size: 12px;
            line-height: 16px;
            color: var(--onboarding-text-muted);
            text-align: center;
        }

        .onboarding-overlay .onboarding-error.is-hidden {
            visibility: hidden;
        }

        .onboarding-overlay .legal-links {
            display: none;
            justify-content: center;
            gap: 12px;
            font-size: 12px;
            margin-top: 10px;
        }

        .onboarding-overlay .legal-links a {
            text-decoration: none;
            color: var(--onboarding-text);
            opacity: 0.8;
        }

        .onboarding-overlay .input-box.locked::before,
        .onboarding-overlay .input-box.locked::after {
            content: none !important;
        }

        .onboarding-overlay .onboarding-tooltip {
            position: fixed;
            max-width: min(280px, calc(100vw - 24px));
            padding: 8px 10px;
            border-radius: 8px;
            background: var(--onboarding-tooltip-bg);
            color: var(--onboarding-tooltip-text);
            font-size: 12px;
            line-height: 16px;
            z-index: 10000;
            box-shadow: 0px 10px 30px rgba(0,0,0,0.35);
        }

        .onboarding-overlay .onboarding-tooltip::after {
            content: "";
            position: absolute;
            left: var(--onboarding-tooltip-arrow-x, 50%);
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 7px solid transparent;
            border-right: 7px solid transparent;
        }

        .onboarding-overlay .onboarding-tooltip[data-placement="top"]::after {
            top: 100%;
            border-top: 7px solid var(--onboarding-tooltip-arrow);
        }

        .onboarding-overlay .onboarding-tooltip[data-placement="bottom"]::after {
            bottom: 100%;
            border-bottom: 7px solid var(--onboarding-tooltip-arrow);
        }

        .onboarding-overlay .onboarding-tooltip.is-hidden {
            display: none;
        }

        @media (prefers-reduced-motion: reduce) {
            .onboarding-overlay * {
                transition: none !important;
                scroll-behavior: auto !important;
            }
        }

        @media (max-width: 768px) {
            .onboarding-overlay .footer-actions {
                padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
                margin-bottom: 8px;
            }

            .onboarding-overlay .onboarding-modal-container {
                padding-bottom: 16px;
            }
        }

        @keyframes onboarding-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    `).split('.onboarding-overlay').join('#' + CONSTANTS.ids.overlay);

    try {
        if ('adoptedStyleSheets' in document && 'replaceSync' in CSSStyleSheet.prototype) {
            const sheet = new CSSStyleSheet();
            sheet.replaceSync(cssText);
            document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
            STYLES_CACHE.sheet = sheet;
            return;
        }
    } catch (e) {
    }

    const style = document.createElement('style');
    style.id = 'onboarding-modal-styles';
    style.textContent = cssText;
    document.head.appendChild(style);
    STYLES_CACHE.styleEl = style;
}

function createHeader(user) {
    const header = document.createElement('div');
    header.className = 'header';

    const emailPill = document.createElement('p');
    emailPill.className = 'email-pill';
    emailPill.id = CONSTANTS.ids.email;
    emailPill.textContent = String(user?.email || '');

    const textGroup = document.createElement('div');
    textGroup.className = 'text-group';

    const title = document.createElement('h1');
    title.id = CONSTANTS.ids.title;
    title.textContent = 'Edite su perfil';

    const caption = document.createElement('p');
    caption.className = 'caption';
    caption.textContent = 'Elige tu avatar e ingresa tu usuario de Epic para recibir tus compras, tu nombre público para eventos y tu número de WhatsApp para contactarte.';

    textGroup.appendChild(title);
    textGroup.appendChild(caption);
    header.appendChild(emailPill);
    header.appendChild(textGroup);
    return header;
}

function createBodyWrap() {
    const bodyWrap = document.createElement('div');
    bodyWrap.style.flex = '1';
    bodyWrap.style.display = 'flex';
    bodyWrap.style.flexDirection = 'column';
    bodyWrap.style.alignItems = 'center';
    return bodyWrap;
}

function createCarousel() {
    const carouselWrapper = document.createElement('div');
    carouselWrapper.className = 'carousel-wrapper';

    const carouselTrack = document.createElement('div');
    carouselTrack.className = 'carousel-track';
    carouselTrack.setAttribute('role', 'listbox');
    carouselTrack.tabIndex = 0;

    const carouselSlots = document.createElement('div');
    carouselSlots.id = CONSTANTS.ids.carouselSlots;
    carouselSlots.className = 'carousel-slots';

    carouselTrack.appendChild(carouselSlots);
    carouselWrapper.appendChild(carouselTrack);
    return carouselWrapper;
}

function createInputGroup({
    labelText,
    boxId,
    inputId,
    value,
    maxLength,
    minLength,
    locked,
    tooltip,
    normalizeValue,
    inputMode,
    placeholder,
    infoText,
    fieldLabel
}) {
    const group = document.createElement('div');
    group.className = 'field-group';

    const labelRow = document.createElement('div');
    labelRow.className = 'label-row';

    const label = document.createElement('label');
    label.className = 'label-title';
    label.textContent = labelText;

    labelRow.appendChild(label);

    const box = document.createElement('div');
    box.className = `input-box${locked ? ' locked' : ''}${infoText ? ' has-info' : ''}`;
    box.id = boxId;
    if (locked) {
        box.tabIndex = 0;
        box.dataset.tooltip = String(tooltip || '');
    }

    const input = document.createElement('input');
    input.id = inputId;
    input.type = 'text';
    input.value = normalizeValue ? normalizeValue(value) : String(value ?? '');
    input.setAttribute('aria-required', 'true');
    input.spellcheck = false;
    input.maxLength = maxLength;
    if (minLength) input.minLength = minLength;
    if (inputMode) input.inputMode = inputMode;
    if (placeholder) input.placeholder = placeholder;
    if (locked) input.disabled = true;

    label.htmlFor = input.id;

    box.appendChild(input);

    if (infoText) {
        const infoWrap = document.createElement('span');
        infoWrap.className = 'field-info-wrap';

        const infoBtn = document.createElement('button');
        infoBtn.type = 'button';
        infoBtn.className = 'field-info-icon';
        infoBtn.setAttribute('aria-label', `Información sobre ${labelText}`);
        infoBtn.tabIndex = -1;

        const SVG_NS = 'http://www.w3.org/2000/svg';
        const infoSvg = document.createElementNS(SVG_NS, 'svg');
        infoSvg.setAttribute('viewBox', '0 0 20 20');
        infoSvg.setAttribute('width', '16');
        infoSvg.setAttribute('height', '16');
        infoSvg.setAttribute('aria-hidden', 'true');
        const infoCircle = document.createElementNS(SVG_NS, 'circle');
        infoCircle.setAttribute('cx', '10');
        infoCircle.setAttribute('cy', '10');
        infoCircle.setAttribute('r', '9');
        infoCircle.setAttribute('fill', 'none');
        infoCircle.setAttribute('stroke', 'currentColor');
        infoCircle.setAttribute('stroke-width', '1.5');
        const infoDot = document.createElementNS(SVG_NS, 'circle');
        infoDot.setAttribute('cx', '10');
        infoDot.setAttribute('cy', '6.5');
        infoDot.setAttribute('r', '1');
        infoDot.setAttribute('fill', 'currentColor');
        const infoLine = document.createElementNS(SVG_NS, 'path');
        infoLine.setAttribute('d', 'M10 9.5v4.5');
        infoLine.setAttribute('stroke', 'currentColor');
        infoLine.setAttribute('stroke-width', '1.8');
        infoLine.setAttribute('stroke-linecap', 'round');
        infoLine.setAttribute('fill', 'none');
        infoSvg.appendChild(infoCircle);
        infoSvg.appendChild(infoDot);
        infoSvg.appendChild(infoLine);
        infoBtn.appendChild(infoSvg);

        const infoBubble = document.createElement('span');
        infoBubble.className = 'field-info-bubble is-hidden';
        infoBubble.setAttribute('role', 'tooltip');
        infoBubble.textContent = infoText;

        infoBtn.addEventListener('pointerdown', (e) => {
            e.preventDefault();
        });

        infoBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const isHidden = infoBubble.classList.contains('is-hidden');
            document.querySelectorAll('.onboarding-overlay .field-info-bubble').forEach((b) => {
                b.classList.add('is-hidden');
            });
            if (isHidden) {
                infoBubble.classList.remove('is-hidden');
                infoBtn.setAttribute('aria-expanded', 'true');
            } else {
                infoBubble.classList.add('is-hidden');
                infoBtn.setAttribute('aria-expanded', 'false');
            }
        });

        infoWrap.appendChild(infoBtn);
        infoWrap.appendChild(infoBubble);
        box.appendChild(infoWrap);
    }

    const errorEl = document.createElement('div');
    errorEl.className = 'field-error is-hidden';
    errorEl.setAttribute('role', 'alert');
    errorEl.dataset.for = inputId;
    if (fieldLabel) errorEl.dataset.fieldLabel = fieldLabel;

    const errorSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    errorSvg.setAttribute('viewBox', '0 0 20 20');
    errorSvg.setAttribute('width', '14');
    errorSvg.setAttribute('height', '14');
    errorSvg.setAttribute('aria-hidden', 'true');
    errorSvg.classList.add('field-error-icon');
    const errorCircle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    errorCircle.setAttribute('cx', '10');
    errorCircle.setAttribute('cy', '10');
    errorCircle.setAttribute('r', '9');
    errorCircle.setAttribute('fill', 'none');
    errorCircle.setAttribute('stroke', 'currentColor');
    errorCircle.setAttribute('stroke-width', '1.8');
    const errorExcl = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    errorExcl.setAttribute('d', 'M10 5.5v5M10 13.5v.5');
    errorExcl.setAttribute('fill', 'none');
    errorExcl.setAttribute('stroke', 'currentColor');
    errorExcl.setAttribute('stroke-width', '1.8');
    errorExcl.setAttribute('stroke-linecap', 'round');
    errorSvg.appendChild(errorCircle);
    errorSvg.appendChild(errorExcl);

    const errorText = document.createElement('span');
    errorText.className = 'field-error-text';

    errorEl.appendChild(errorSvg);
    errorEl.appendChild(errorText);

    group.appendChild(labelRow);
    group.appendChild(box);
    group.appendChild(errorEl);
    return group;
}

function createCountryGroup({
    labelText,
    boxId,
    inputId,
    listId,
    value,
    locked,
    tooltip
}) {
    const group = document.createElement('div');
    group.className = 'field-group';
    group.style.position = 'relative';

    const label = document.createElement('label');
    label.className = 'label-title';
    label.textContent = labelText;

    const box = document.createElement('div');
    box.className = `input-box${locked ? ' locked' : ''}`;
    box.id = boxId;
    if (locked) {
        box.tabIndex = 0;
        box.dataset.tooltip = String(tooltip || '');
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'country-trigger';
    btn.id = inputId;
    btn.setAttribute('aria-required', 'true');
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-controls', listId);

    const searchId = `${listId}-search`;
    const optionsId = `${listId}-options`;
    const emptyId = `${listId}-empty`;

    const normalized = VALIDATION_SCHEMA.country_iso2.normalize(value);
    btn.dataset.value = normalized;

    const labelSpan = document.createElement('span');
    labelSpan.className = 'country-trigger-label';
    renderCountryLabelEl(labelSpan, normalized, { placeholder: 'Selecciona tu país' });

    const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    chevron.setAttribute('viewBox', '0 0 20 20');
    chevron.setAttribute('width', '16');
    chevron.setAttribute('height', '16');
    chevron.setAttribute('aria-hidden', 'true');
    chevron.style.flexShrink = '0';

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M5 7.5L10 12.5L15 7.5');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '2');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    chevron.appendChild(path);

    btn.appendChild(labelSpan);
    btn.appendChild(chevron);

    if (locked) {
        btn.disabled = true;
        btn.tabIndex = -1;
    }

    const menu = document.createElement('div');
    menu.className = 'country-menu is-hidden';
    menu.id = listId;

    const searchWrap = document.createElement('div');
    searchWrap.className = 'country-search';

    const searchInner = document.createElement('div');
    searchInner.className = 'country-search-inner';

    const SVG_NS = 'http://www.w3.org/2000/svg';
    const searchIcon = document.createElementNS(SVG_NS, 'svg');
    searchIcon.setAttribute('viewBox', '0 0 24 24');
    searchIcon.setAttribute('aria-hidden', 'true');
    searchIcon.classList.add('country-search-icon');
    const searchPath = document.createElementNS(SVG_NS, 'path');
    searchPath.setAttribute('d', 'M21 21l-4.35-4.35m1.35-5.15a7 7 0 11-14 0 7 7 0 0114 0z');
    searchPath.setAttribute('fill', 'none');
    searchPath.setAttribute('stroke', 'currentColor');
    searchPath.setAttribute('stroke-width', '2');
    searchPath.setAttribute('stroke-linecap', 'round');
    searchPath.setAttribute('stroke-linejoin', 'round');
    searchIcon.appendChild(searchPath);

    const searchInput = document.createElement('input');
    searchInput.id = searchId;
    searchInput.type = 'text';
    searchInput.className = 'country-search-input';
    searchInput.placeholder = 'Buscar país';
    searchInput.autocomplete = 'off';
    searchInput.spellcheck = false;
    searchInput.setAttribute('role', 'combobox');
    searchInput.setAttribute('aria-autocomplete', 'list');
    searchInput.setAttribute('aria-controls', optionsId);
    searchInput.setAttribute('aria-expanded', 'false');

    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'country-search-clear is-hidden';
    clearBtn.setAttribute('aria-label', 'Limpiar búsqueda');
    const clearSvg = document.createElementNS(SVG_NS, 'svg');
    clearSvg.setAttribute('viewBox', '0 0 20 20');
    clearSvg.setAttribute('width', '16');
    clearSvg.setAttribute('height', '16');
    clearSvg.setAttribute('aria-hidden', 'true');
    const clearPath = document.createElementNS(SVG_NS, 'path');
    clearPath.setAttribute('d', 'M6 6l8 8M14 6l-8 8');
    clearPath.setAttribute('fill', 'none');
    clearPath.setAttribute('stroke', 'currentColor');
    clearPath.setAttribute('stroke-width', '2');
    clearPath.setAttribute('stroke-linecap', 'round');
    clearPath.setAttribute('stroke-linejoin', 'round');
    clearSvg.appendChild(clearPath);
    clearBtn.appendChild(clearSvg);

    if (locked) {
        searchInput.disabled = true;
        clearBtn.disabled = true;
        searchInput.tabIndex = -1;
        clearBtn.tabIndex = -1;
    }

    searchInner.appendChild(searchIcon);
    searchInner.appendChild(searchInput);
    searchInner.appendChild(clearBtn);
    searchWrap.appendChild(searchInner);

    const options = document.createElement('div');
    options.className = 'country-options';
    options.id = optionsId;
    options.setAttribute('role', 'listbox');
    options.setAttribute('tabindex', '-1');

    const empty = document.createElement('div');
    empty.className = 'country-no-results is-hidden';
    empty.id = emptyId;
    empty.textContent = 'No se encontraron resultados';
    empty.setAttribute('role', 'presentation');
    empty.setAttribute('aria-live', 'polite');
    options.appendChild(empty);

    const selected = VALIDATION_SCHEMA.country_iso2.normalize(normalized);

    const frag = document.createDocumentFragment();
    for (const c of COUNTRIES) {
        const opt = document.createElement('button');
        opt.type = 'button';
        opt.className = 'country-option';
        opt.dataset.iso2 = c.iso2;
        opt.setAttribute('role', 'option');
        opt.id = `${optionsId}-${c.iso2}`;

        const img = createCountryFlagImg(c.iso2, { lazy: true });
        const text = document.createElement('span');
        text.className = 'country-option-text';
        text.textContent = c.name;
        opt.appendChild(img);
        opt.appendChild(text);

        const dial = String(c.dial || '');
        opt.dataset.search = foldSearchText(`${c.name} ${c.iso2} ${dial} ${dial.replace(/^\+/, '')}`);

        const iso2 = VALIDATION_SCHEMA.country_iso2.normalize(c.iso2);
        opt.setAttribute('aria-selected', iso2 === selected ? 'true' : 'false');

        frag.appendChild(opt);
    }
    options.appendChild(frag);

    menu.appendChild(searchWrap);
    menu.appendChild(options);

    label.htmlFor = btn.id;

    box.appendChild(btn);
    group.appendChild(label);
    group.appendChild(box);
    group.appendChild(menu);
    return group;
}

function createFooterActions() {
    const footer = document.createElement('div');
    footer.className = 'footer-actions';

    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn-primary-action';
    saveBtn.type = 'button';
    saveBtn.id = CONSTANTS.ids.save;
    saveBtn.disabled = true;
    saveBtn.setAttribute('aria-disabled', 'true');
    saveBtn.textContent = 'Guardar y continuar';

    footer.appendChild(saveBtn);
    return footer;
}

function createErrorEl() {
    const errorEl = document.createElement('div');
    errorEl.className = 'onboarding-error is-hidden';
    errorEl.id = CONSTANTS.ids.error;
    errorEl.setAttribute('role', 'status');
    errorEl.setAttribute('aria-live', 'polite');
    return errorEl;
}

function createLiveEl() {
    const liveEl = document.createElement('div');
    liveEl.id = CONSTANTS.ids.live;
    liveEl.setAttribute('aria-live', 'polite');
    liveEl.setAttribute('aria-atomic', 'true');
    liveEl.style.position = 'absolute';
    liveEl.style.width = '1px';
    liveEl.style.height = '1px';
    liveEl.style.padding = '0';
    liveEl.style.margin = '-1px';
    liveEl.style.overflow = 'hidden';
    liveEl.style.clip = 'rect(0, 0, 0, 0)';
    liveEl.style.whiteSpace = 'nowrap';
    liveEl.style.border = '0';
    return liveEl;
}

function createTooltipEl() {
    const tooltipEl = document.createElement('div');
    tooltipEl.className = 'onboarding-tooltip is-hidden';
    tooltipEl.id = CONSTANTS.ids.tooltip;
    tooltipEl.setAttribute('role', 'tooltip');
    return tooltipEl;
}

function createLegalLinks() {
    const legalLinks = document.createElement('div');
    legalLinks.className = 'legal-links';
    const legal1 = document.createElement('a');
    legal1.href = '#';
    legal1.textContent = 'Condiciones de uso';
    const legal2 = document.createElement('a');
    legal2.href = '#';
    legal2.textContent = 'Privacidad y cookies';
    legalLinks.appendChild(legal1);
    legalLinks.appendChild(legal2);
    return legalLinks;
}

function buildOverlayDom(user, state) {
    const epicLocked = shouldLockPrefilledField(user, 'game_username', 'game_username');
    const publicLocked = shouldLockPrefilledField(user, 'public_display_name', 'public_name');
    const whatsappLocked = shouldLockPrefilledField(user, 'whatsapp_phone_e164', 'whatsapp');

    const countryRule = getFieldRule(user, 'country_iso2');
    const countryLocked = Boolean(countryRule && countryRule.can_change === false);

    const epicTooltip = epicLocked ? buildLockTooltip(user, 'game_username') : '';
    const publicTooltip = publicLocked ? buildLockTooltip(user, 'public_name') : '';
    const whatsappTooltip = whatsappLocked ? buildLockTooltip(user, 'whatsapp') : '';
    const countryTooltip = countryLocked ? buildLockTooltip(user, 'country_iso2') : '';

    const frag = document.createDocumentFragment();
    frag.appendChild(buildFilterDefsSvg());

    const container = document.createElement('div');
    container.className = 'onboarding-modal-container';

    const header = createHeader(user);
    const bodyWrap = createBodyWrap();

    const carouselWrapper = createCarousel();

    const epicGroup = createInputGroup({
        labelText: 'Usuario de Epic',
        boxId: CONSTANTS.ids.epicBox,
        inputId: CONSTANTS.ids.epic,
        value: user?.game_username,
        maxLength: CONSTANTS.validation.epicMax,
        minLength: CONSTANTS.validation.epicMin,
        locked: epicLocked,
        tooltip: epicTooltip,
        normalizeValue: normalizeInitialName,
        placeholder: 'BananoNinja777',
        infoText: 'Tu nombre de usuario en Epic Games. Es necesario para enviarte los objetos de Fortnite a tu cuenta.',
        fieldLabel: 'Usuario de Epic'
    });

    const publicGroup = createInputGroup({
        labelText: 'Nombre público',
        boxId: CONSTANTS.ids.pubBox,
        inputId: CONSTANTS.ids.pub,
        value: user?.public_display_name,
        maxLength: CONSTANTS.validation.publicMax,
        minLength: CONSTANTS.validation.publicMin,
        locked: publicLocked,
        tooltip: publicTooltip,
        normalizeValue: normalizeInitialName,
        placeholder: 'Banano777',
        infoText: 'Este nombre se mostrará en sorteos y rankings públicos. Puede ser diferente a tu usuario de Epic.',
        fieldLabel: 'Nombre público'
    });

    const countryGroup = shouldHideCountrySelectorOnboarding(user?.country_iso2, state.dashboardData)
        ? null
        : createCountryGroup({
            labelText: 'País',
            boxId: CONSTANTS.ids.countryBox,
            inputId: CONSTANTS.ids.country,
            listId: CONSTANTS.ids.countryList,
            value: user?.country_iso2 || 'pe',
            locked: countryLocked,
            tooltip: countryTooltip
        });

    const whatsappGroup = createInputGroup({
        labelText: 'Número de WhatsApp',
        boxId: CONSTANTS.ids.whatsappBox,
        inputId: CONSTANTS.ids.whatsapp,
        value: user?.whatsapp_phone_e164 || '',
        maxLength: CONSTANTS.validation.whatsappDigits,
        locked: whatsappLocked,
        tooltip: whatsappTooltip,
        normalizeValue: normalizeWhatsappDigits,
        inputMode: 'numeric',
        placeholder: 'Ej: 987654321',
        infoText: 'Tu número de WhatsApp sin código de país. Lo usaremos para coordinar la entrega de tus pedidos.',
        fieldLabel: 'Número de WhatsApp'
    });

    const footer = createFooterActions();
    const errorEl = createErrorEl();
    const liveEl = createLiveEl();
    const tooltipEl = createTooltipEl();
    const legalLinks = createLegalLinks();

    bodyWrap.appendChild(carouselWrapper);
    bodyWrap.appendChild(epicGroup);
    bodyWrap.appendChild(publicGroup);
    if (countryGroup) bodyWrap.appendChild(countryGroup);
    bodyWrap.appendChild(whatsappGroup);
    bodyWrap.appendChild(footer);
    bodyWrap.appendChild(errorEl);
    bodyWrap.appendChild(liveEl);
    bodyWrap.appendChild(tooltipEl);
    bodyWrap.appendChild(legalLinks);

    container.appendChild(header);
    container.appendChild(bodyWrap);
    frag.appendChild(container);
    return frag;
}

function getAvatarSrc(av) {
    const url = typeof av?.url === 'string' ? av.url.trim() : '';
    if (url) return url;
    const safeFilename = sanitizeAvatarFilename(av?.filename);
    return `/assets/avatars/${safeFilename}`;
}

function setAvatarIndexByAvatarId(state, avatarId) {
    const idx = state.avatarsState.findIndex((a) => String(a.id) === String(avatarId));
    // Sin avatar asignado (o ya no existe): arrancar en uno aleatorio, nunca en el último subido.
    state.avatarIndex = idx >= 0 ? idx : Math.floor(Math.random() * state.avatarsState.length);
}

function modulo(n, m) {
    return ((n % m) + m) % m;
}

function clearChildren(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
}

function renderCarouselSlots(state, elements, prevIndexArg) {
    const cont = elements?.carouselSlots || state.overlayEl?.querySelector?.(`#${CONSTANTS.ids.carouselSlots}`);
    if (!cont) return;

    if (!state.avatarsState.length) {
        clearChildren(cont);
        try {
            delete cont.__onboardingSlots;
        } catch (e) {
        }
        return;
    }

    const len = state.avatarsState.length;
    const scales = ['scale-047', 'scale-073', 'scale-1', 'scale-073', 'scale-047'];

    const reduceMotion = (() => {
        try {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    })();

    const ensureSlots = () => {
        const existing = cont.__onboardingSlots;
        if (Array.isArray(existing) && existing.length === 5) return existing;

        clearChildren(cont);

        const SVG_NS = 'http://www.w3.org/2000/svg';
        const frag = document.createDocumentFragment();
        const slots = [];

        for (let i = 0; i < 5; i++) {
            const slot = document.createElement('div');
            slot.className = 'avatar-slot';

            const img = document.createElement('img');
            img.width = 72;
            img.height = 72;
            slot.appendChild(img);

            const svg = document.createElementNS(SVG_NS, 'svg');
            svg.setAttribute('class', 'highlight-ring');
            svg.setAttribute('viewBox', '0 0 108 108');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('aria-hidden', 'true');

            const g = document.createElementNS(SVG_NS, 'g');
            g.setAttribute('filter', 'url(#filter0_d_2720_2296)');

            const circle = document.createElementNS(SVG_NS, 'circle');
            circle.setAttribute('cx', '54');
            circle.setAttribute('cy', '54');
            circle.setAttribute('r', '46.6727');
            circle.setAttribute('stroke', '#DBDBDB');
            circle.setAttribute('stroke-width', '1.82542');

            g.appendChild(circle);
            svg.appendChild(g);
            slot.appendChild(svg);

            slot.__img = img;
            slots.push(slot);
            frag.appendChild(slot);
        }

        cont.appendChild(frag);
        cont.__onboardingSlots = slots;
        return slots;
    };

    const setSlotAvatar = (slot, av) => {
        const img = slot.__img || slot.querySelector('img');
        if (!(img instanceof HTMLImageElement)) return;
        const nextSrc = getAvatarSrc(av);
        const currSrc = img.getAttribute('src');
        if (currSrc !== nextSrc) img.setAttribute('src', nextSrc);
        img.alt = String(av?.name || 'Avatar');
    };

    const applySlotMeta = (slots) => {
        for (let idx = 0; idx < slots.length; idx++) {
            const rel = idx - 2;
            const isCenter = idx === 2;
            const slot = slots[idx];

            slot.dataset.rel = String(rel);
            slot.classList.remove('scale-047', 'scale-073', 'scale-1', 'scale-02', 'scale-0');
            slot.classList.add(scales[idx]);

            slot.classList.toggle('is-center', isCenter);
            if (isCenter) slot.setAttribute('aria-selected', 'true');
            else slot.removeAttribute('aria-selected');
        }
    };

    const slots = ensureSlots();

    const prevIndex = Number.isFinite(Number(prevIndexArg)) ? Number(prevIndexArg) : null;

    if (prevIndex === null || reduceMotion || typeof cont.animate !== 'function') {
        for (let idx = 0; idx < 5; idx++) {
            const rel = idx - 2;
            const av = state.avatarsState[modulo(state.avatarIndex + rel, len)];
            setSlotAvatar(slots[idx], av);
        }
        applySlotMeta(slots);
        return;
    }

    const nextIndex = modulo(Number(state.avatarIndex), len);
    const prevIdx = modulo(prevIndex, len);
    const forward = modulo(nextIndex - prevIdx, len);
    const backward = modulo(prevIdx - nextIndex, len);
    const dir = forward <= backward ? 1 : -1;
    const steps = Math.min(forward, backward);

    if (!steps || steps > 2) {
        for (let idx = 0; idx < 5; idx++) {
            const rel = idx - 2;
            const av = state.avatarsState[modulo(state.avatarIndex + rel, len)];
            setSlotAvatar(slots[idx], av);
        }
        applySlotMeta(slots);
        return;
    }

    const first = new Map();
    for (const slot of slots) {
        try {
            slot.getAnimations?.().forEach((a) => a.cancel());
        } catch (e) {
        }
        first.set(slot, slot.getBoundingClientRect());
    }

    for (let k = 1; k <= steps; k++) {
        if (dir === 1) {
            const moved = slots.shift();
            slots.push(moved);
            cont.appendChild(moved);
            const entering = state.avatarsState[modulo(prevIdx + k + 2, len)];
            setSlotAvatar(moved, entering);
        } else {
            const moved = slots.pop();
            slots.unshift(moved);
            cont.insertBefore(moved, cont.firstChild);
            const entering = state.avatarsState[modulo(prevIdx - k - 2, len)];
            setSlotAvatar(moved, entering);
        }
    }

    applySlotMeta(slots);

    const last = new Map();
    for (const slot of slots) {
        last.set(slot, slot.getBoundingClientRect());
    }

    const duration = 240;
    const easing = 'cubic-bezier(0.2, 0.8, 0.2, 1)';

    for (const slot of slots) {
        const a = first.get(slot);
        const b = last.get(slot);
        if (!a || !b) continue;

        const dx = a.left - b.left;
        const dy = a.top - b.top;
        const sx = a.width ? (a.width / (b.width || a.width)) : 1;
        const sy = a.height ? (a.height / (b.height || a.height)) : 1;

        if (!Number.isFinite(dx) || !Number.isFinite(dy) || !Number.isFinite(sx) || !Number.isFinite(sy)) continue;
        if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5 && Math.abs(sx - 1) < 0.01 && Math.abs(sy - 1) < 0.01) continue;

        try {
            slot.animate(
                [
                    { transform: `translate(${dx}px, ${dy}px) scale(${sx}, ${sy})` },
                    { transform: 'none' }
                ],
                { duration, easing }
            );
        } catch (e) {
        }
    }
}

async function postUpdate(state, field, value) {
    await ApiService.updateProfileField({
        endpoint: state.currentApiEndpoint,
        field,
        value
    });
}

class ApiService {
    static async updateProfileField({ endpoint, field, value }) {
        const fd = new FormData();
        fd.append('action', endpoint);
        fd.append('sub', 'u4');
        fd.append('field', field);
        fd.append('value', value);

        const res = await this._fetchWithTimeout('/web.php', {
            method: 'POST',
            credentials: 'include',
            body: fd
        }, 15000);

        if (res.status === 401) {
            const path = window.location.pathname || '/cuenta/';
            const search = window.location.search || '';
            const redirectTo = `${path}${search}`;
            window.location.assign(`/login/?redirect_to=${encodeURIComponent(redirectTo)}`);
            return;
        }

        const data = await this._readJsonSafe(res);

        if (!res.ok) {
            throw new Error(data?.message || `HTTP ${res.status}`);
        }

        if (!data || data.status !== 'success') {
            throw new Error(data?.message || 'Error');
        }
    }

    static async _fetchWithTimeout(url, options, timeoutMs) {
        const ctrl = new AbortController();
        const t = window.setTimeout(() => {
            try { ctrl.abort(); } catch (e) {
            }
        }, timeoutMs);

        try {
            return await fetch(url, { ...options, signal: ctrl.signal });
        } catch (e) {
            if (ctrl.signal.aborted) throw new Error('Tiempo de espera agotado');
            throw e;
        } finally {
            window.clearTimeout(t);
        }
    }

    static async _readJsonSafe(res) {
        try {
            return await _resolveSyncFrame(await res.json());
        } catch (e) {
            return null;
        }
    }

    static async batchUpdateProfile({ endpoint, updates }) {
        const fd = new FormData();
        fd.append('action', endpoint);
        fd.append('sub', 'u4');
        fd.append('updates', JSON.stringify(updates));

        const res = await this._fetchWithTimeout('/web.php', {
            method: 'POST',
            credentials: 'include',
            body: fd
        }, 15000);

        if (res.status === 401) {
            const path = window.location.pathname || '/cuenta/';
            const search = window.location.search || '';
            const redirectTo = `${path}${search}`;
            window.location.assign(`/login/?redirect_to=${encodeURIComponent(redirectTo)}`);
            return { status: 'redirect' };
        }

        const data = await this._readJsonSafe(res);

        if (!res.ok || !data || data.status !== 'success') {
            return {
                status: 'error',
                field_errors: data?.field_errors || {},
                message: data?.message || `HTTP ${res.status}`
            };
        }

        return { status: 'success' };
    }
}

async function saveAllRequired(state, elements) {
    const overlayEl = elements?.overlayEl || state.overlayEl;
    const epic = elements?.epic || overlayEl?.querySelector?.('#onboardingEpic');
    const pub = elements?.pub || overlayEl?.querySelector?.('#onboardingPublic');
    const country = elements?.country || overlayEl?.querySelector?.('#onboardingCountry');
    const wa = elements?.wa || overlayEl?.querySelector?.('#onboardingWhatsapp');
    const btn = elements?.saveBtn || overlayEl?.querySelector?.('#onboardingSave');

    // country element may be null when the country selector is hidden (Peru users)
    if (!epic || !pub || !wa || !btn) return;

    setInlineError('', state, elements);

    const countryHidden = !country;
    const epicLocked = epic.disabled === true;
    const pubLocked = pub.disabled === true;
    const countryLocked = countryHidden || country.disabled === true;
    const waLocked = wa.disabled === true;

    const gameUsername = state.form?.game_username ?? normalizeUserText(epic.value || '');
    const publicName = state.form?.public_name ?? normalizeUserText(pub.value || '');
    const countryIso2 = shouldHideCountrySelectorOnboarding(state.userSnapshot?.country_iso2, { country: state.dashboardData?.country })
        ? 'pe'
        : (state.form?.country_iso2 ?? normalizeCountryIso2(country?.dataset.value || ''));
    const whatsapp = state.form?.whatsapp ?? normalizeWhatsappDigits(wa.value || '');

    // Show ALL validation errors at once, not just a generic message
    let hasClientError = false;
    if (!epicLocked && !VALIDATION_SCHEMA.game_username.validate(gameUsername)) {
        const msg = getFieldTouchedError(epic, 'game_username');
        if (msg) { showFieldError(epic, msg); hasClientError = true; }
    }
    if (!pubLocked && !VALIDATION_SCHEMA.public_name.validate(publicName)) {
        const msg = getFieldTouchedError(pub, 'public_name');
        if (msg) { showFieldError(pub, msg); hasClientError = true; }
    }
    if (!countryLocked && !countryHidden && !VALIDATION_SCHEMA.country_iso2.validate(countryIso2)) {
        if (country) { showFieldError(country, 'Selecciona un país válido.'); hasClientError = true; }
    }
    if (!waLocked && !VALIDATION_SCHEMA.whatsapp.validate(whatsapp)) {
        const msg = getFieldTouchedError(wa, 'whatsapp');
        if (msg) { showFieldError(wa, msg); hasClientError = true; }
    }
    if (hasClientError) {
        setInlineError('Revisa los campos marcados.', state, elements);
        return;
    }

    const selectedAvatar = state.carousel?.getValue?.()
        || (state.avatarsState.length ? state.avatarsState[modulo(state.avatarIndex, state.avatarsState.length)] : null);
    if (!selectedAvatar) {
        setInlineError('Selecciona un avatar para continuar.', state, elements);
        return;
    }

    btn.disabled = true;
    btn.setAttribute('aria-disabled', 'true');
    setButtonLoading(btn, true);

    try {
        overlayEl?.setAttribute?.('aria-busy', 'true');
    } catch (e) {
    }
    announce('Guardando…', state, elements);

    try {
        const curr = state.userSnapshot || {};
        const updates = /** @type {[string, string][]} */ ([]);

        const currPublic = !isBlank(curr.public_name) ? curr.public_name : curr.public_display_name;
        const currWhatsappRaw = !isBlank(curr.whatsapp) ? curr.whatsapp : curr.whatsapp_phone_e164;
        const currWhatsapp = normalizeWhatsappDigits(currWhatsappRaw || '');

        if (!epicLocked) {
            if (isBlank(curr.game_username) || String(curr.game_username) !== gameUsername) {
                updates.push(['game_username', gameUsername]);
            }
        }

        if (!pubLocked) {
            if (isBlank(currPublic) || String(currPublic) !== publicName) {
                updates.push(['public_name', publicName]);
            }
        }

        // For hidden-country users (Peru), STILL save the auto-detected value
        // so the DB has the correct country and future checks don't re-trigger.
        const isCountryHiddenForSave = shouldHideCountrySelectorOnboarding(state.userSnapshot?.country_iso2, { country: state.dashboardData?.country });
        if (!countryLocked && !isCountryHiddenForSave) {
            const currCountry = normalizeCountryIso2(curr.country_iso2 || '');
            if (isBlank(currCountry) || currCountry !== countryIso2) {
                updates.push(['country_iso2', countryIso2]);
            }
        } else if (isCountryHiddenForSave && isBlank(normalizeCountryIso2(curr.country_iso2 || ''))) {
            // Country is auto-detected as PE but never saved — persist it now
            updates.push(['country_iso2', 'pe']);
        }

        if (!waLocked) {
            if (isBlank(currWhatsappRaw) || currWhatsapp !== whatsapp) {
                updates.push(['whatsapp', whatsapp]);
            }
        }

        if (String(curr.avatar_id || '') !== String(selectedAvatar.id)) {
            updates.push(['avatar_id', String(selectedAvatar.id)]);
        }

        // Map backend field keys to their corresponding UI input elements
        const fieldToInput = {
            game_username: epic,
            public_name: pub,
            whatsapp: wa,
            country_iso2: country
        };

        if (updates.length > 0) {
            const result = await ApiService.batchUpdateProfile({
                endpoint: state.currentApiEndpoint,
                updates: updates.map(([field, value]) => ({ field, value }))
            });

            if (result.status === 'redirect') return;

            if (result.status === 'error') {
                const fieldErrors = result.field_errors || {};
                let shownFieldError = false;

                for (const [errField, errorMsg] of Object.entries(fieldErrors)) {
                    const targetInput = fieldToInput[errField];
                    if (targetInput) {
                        showFieldError(targetInput, String(errorMsg));
                        shownFieldError = true;
                    }
                }

                if (!shownFieldError) {
                    setInlineError(result.message || 'Error al guardar', state, elements);
                } else {
                    setInlineError('', state, elements);
                }

                throw new Error(result.message || 'Error al guardar');
            }
        }

        // After successful save, update UI dynamically instead of always reloading.
        // On /cuenta/ pages, dashboard.js manages its own state so a reload is appropriate.
        const pathname = window.location.pathname || '';
        const isCuentaPage = /^\/cuenta(\/|$)/i.test(pathname);
        if (isCuentaPage) {
            window.location.reload();
        } else {
            // Hide the onboarding overlay
            hideOverlay(state, elements);
            // Refresh the global header nav state (avatar, name, etc.)
            if (typeof window.__banano_refreshNavState === 'function') {
                try { await window.__banano_refreshNavState(); } catch (_) { }
            }
        }
    } catch (e) {
        // Only show the global inline error if the error was NOT already
        // mapped to a specific field input (i.e. avatar_id or unexpected errors)
        const fieldInputs = [epic, pub, wa, country].filter(Boolean);
        const alreadyShowingFieldError = fieldInputs.some(
            (el) => el.closest('.field-group')?.querySelector('.input-box.is-invalid')
        );
        if (!alreadyShowingFieldError) {
            setInlineError(String(e?.message || 'Error'), state, elements);
        }
    } finally {
        try {
            overlayEl?.setAttribute?.('aria-busy', 'false');
        } catch (e) {
        }
        setButtonLoading(btn, false);
        btn.disabled = false;
        btn.setAttribute('aria-disabled', btn.disabled ? 'true' : 'false');
    }
}

function getFieldTouchedError(inputEl, fieldKey) {
    const val = inputEl.value || '';
    if (fieldKey === 'whatsapp') {
        const digits = normalizeWhatsappDigits(val);
        if (digits.length === 0) return 'El campo Número de WhatsApp es obligatorio.';
        if (digits.length < CONSTANTS.validation.whatsappDigits) return 'El número debe tener exactamente 9 dígitos.';
        return '';
    }
    const normalized = fieldKey === 'public_name' ? normalizePublicName(val) : normalizeUserText(val);
    const errorEl = inputEl.closest('.field-group')?.querySelector('.field-error');
    const label = errorEl?.dataset?.fieldLabel || '';
    if (normalized.length === 0) return `El campo ${label} es obligatorio.`;
    if (fieldKey === 'game_username' && normalized.length < CONSTANTS.validation.epicMin) {
        return `Introduce un ${label} con al menos ${CONSTANTS.validation.epicMin} caracteres.`;
    }
    if (fieldKey === 'public_name') {
        if (normalized.length < CONSTANTS.validation.publicMin) {
            return `Introduce un ${label} con al menos ${CONSTANTS.validation.publicMin} caracteres.`;
        }
        if (!/[a-zA-Z]/.test(normalized)) {
            return `${label} debe contener al menos una letra.`;
        }
    }
    return '';
}

function showFieldError(inputEl, message) {
    const group = inputEl.closest('.field-group');
    if (!group) return;
    const errorEl = group.querySelector('.field-error');
    if (!errorEl) return;
    const textEl = errorEl.querySelector('.field-error-text');
    if (textEl) textEl.textContent = message;
    errorEl.classList.remove('is-hidden');
    const box = group.querySelector('.input-box');
    if (box) box.classList.add('is-invalid');
}

function clearFieldError(inputEl) {
    const group = inputEl.closest('.field-group');
    if (!group) return;
    const errorEl = group.querySelector('.field-error');
    if (!errorEl) return;
    errorEl.classList.add('is-hidden');
    const textEl = errorEl.querySelector('.field-error-text');
    if (textEl) textEl.textContent = '';
    const box = group.querySelector('.input-box');
    if (box) box.classList.remove('is-invalid');
}

function bindEvents(state, elements) {
    if (!state.overlayEl) return;
    if (state.abort) {
        try { state.abort.abort(); } catch (e) {
        }
    }
    state.abort = new AbortController();
    const signal = state.abort.signal;

    const overlayEl = elements?.overlayEl || state.overlayEl;
    const saveBtn = elements?.saveBtn || overlayEl?.querySelector?.('#onboardingSave');
    const epic = elements?.epic || overlayEl?.querySelector?.('#onboardingEpic');
    const pub = elements?.pub || overlayEl?.querySelector?.('#onboardingPublic');
    const country = elements?.country || overlayEl?.querySelector?.('#onboardingCountry');
    const wa = elements?.wa || overlayEl?.querySelector?.('#onboardingWhatsapp');
    const carouselTrack = elements?.carouselTrack || overlayEl?.querySelector?.('.carousel-track');
    const carouselSlots = elements?.carouselSlots || overlayEl?.querySelector?.(`#${CONSTANTS.ids.carouselSlots}`);
    const epicBox = elements?.epicBox || overlayEl?.querySelector?.('#onboardingEpicBox');
    const pubBox = elements?.pubBox || overlayEl?.querySelector?.('#onboardingPublicBox');
    const countryBox = elements?.countryBox || overlayEl?.querySelector?.('#onboardingCountryBox');
    const countryList = elements?.countryList || overlayEl?.querySelector?.('#onboardingCountryList');
    const waBox = elements?.waBox || overlayEl?.querySelector?.('#onboardingWhatsappBox');

    overlayEl.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        if (e.target.closest('.field-info-wrap')) return;
        overlayEl.querySelectorAll('.field-info-bubble').forEach((b) => {
            b.classList.add('is-hidden');
        });
        overlayEl.querySelectorAll('.field-info-icon').forEach((btn) => {
            btn.setAttribute('aria-expanded', 'false');
        });
    }, { signal });

    if (saveBtn) {
        saveBtn.addEventListener('click', () => {
            if (saveBtn.disabled) return;
            saveAllRequired(state, elements);
        }, { signal });
    }

    const lockedBoxes = elements?.lockedBoxes || overlayEl?.querySelectorAll?.('.input-box.locked');
    if (lockedBoxes && lockedBoxes.length) {
        lockedBoxes.forEach((box) => {
            box.addEventListener('pointerdown', () => {
                try {
                    box.focus();
                } catch (e) {
                }
            }, { passive: true, signal });

            box.addEventListener('pointerenter', () => {
                if (!(box instanceof HTMLElement)) return;
                showTooltipForAnchor(box, state, elements);
            }, { passive: true, signal });

            box.addEventListener('pointerleave', () => {
                hideTooltip(state, elements);
            }, { passive: true, signal });

            box.addEventListener('focus', () => {
                if (!(box instanceof HTMLElement)) return;
                showTooltipForAnchor(box, state, elements);
            }, { signal });

            box.addEventListener('blur', () => {
                hideTooltip(state, elements);
            }, { signal });
        });
    }

    const blurred = { epic: false, pub: false, wa: false };

    if (epic) {
        epic.addEventListener('blur', () => {
            blurred.epic = true;
            const err = getFieldTouchedError(epic, 'game_username');
            if (err) { showFieldError(epic, err); } else { clearFieldError(epic); }
        }, { signal });

        epic.addEventListener('input', () => {
            setInlineError('', state, elements);
            const v = VALIDATION_SCHEMA.game_username.normalize(epic.value || '');
            if (epic.value !== v) epic.value = v;
            state.form.game_username = v;
            if (blurred.epic) {
                const err = getFieldTouchedError(epic, 'game_username');
                if (err) { showFieldError(epic, err); } else { clearFieldError(epic); }
            }
        }, { signal });
    }

    if (pub) {
        pub.addEventListener('blur', () => {
            blurred.pub = true;
            const err = getFieldTouchedError(pub, 'public_name');
            if (err) { showFieldError(pub, err); } else { clearFieldError(pub); }
        }, { signal });

        pub.addEventListener('input', () => {
            setInlineError('', state, elements);
            const v = VALIDATION_SCHEMA.public_name.normalize(pub.value || '');
            if (pub.value !== v) pub.value = v;
            state.form.public_name = v;
            if (blurred.pub) {
                const err = getFieldTouchedError(pub, 'public_name');
                if (err) { showFieldError(pub, err); } else { clearFieldError(pub); }
            }
        }, { signal });
    }

    if (country && countryList) {
        let isOpen = false;

        let closeTimer = null;
        let closeSeq = 0;
        let closeTransitionHandler = null;

        const menuId = String(countryList.id || '');
        const searchInput = countryList.querySelector(`#${CSS.escape(menuId)}-search`);
        const clearBtn = countryList.querySelector(`.country-search-clear`);
        const options = countryList.querySelector(`#${CSS.escape(menuId)}-options`);
        const empty = countryList.querySelector(`#${CSS.escape(menuId)}-empty`);
        if (!(searchInput instanceof HTMLInputElement) || !(options instanceof HTMLElement) || !(empty instanceof HTMLElement)) return;

        const allOpts = Array.from(options.querySelectorAll('.country-option'));
        let activeOpt = null;

        const cancelPendingClose = () => {
            closeSeq++;
            if (closeTimer) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }
            if (closeTransitionHandler) {
                countryList.removeEventListener('transitionend', closeTransitionHandler);
                closeTransitionHandler = null;
            }
            countryList.classList.remove('is-closing');
        };

        const setActiveOpt = (opt) => {
            if (activeOpt && activeOpt !== opt) activeOpt.classList.remove('is-active');
            activeOpt = opt;
            if (activeOpt) {
                activeOpt.classList.add('is-active');
                searchInput.setAttribute('aria-activedescendant', activeOpt.id);
                try {
                    activeOpt.scrollIntoView({ block: 'nearest' });
                } catch (e) {
                }
            } else {
                searchInput.removeAttribute('aria-activedescendant');
            }
        };

        const getVisibleOpts = () => allOpts.filter((n) => !n.classList.contains('is-hidden'));

        const applyFilter = (qRaw) => {
            const q = foldSearchText(String(qRaw || '')).trim();
            let visible = 0;
            let first = null;

            for (const node of allOpts) {
                const hay = String(node.dataset.search || '');
                const match = !q || hay.includes(q);
                node.classList.toggle('is-hidden', !match);
                if (match) {
                    visible++;
                    if (!first) first = node;
                }
            }

            empty.classList.toggle('is-hidden', visible !== 0);
            if (clearBtn instanceof HTMLElement) {
                clearBtn.classList.toggle('is-hidden', String(searchInput.value || '').length === 0);
            }

            if (activeOpt && activeOpt.classList.contains('is-hidden')) {
                setActiveOpt(first);
            }

            if (!activeOpt) {
                setActiveOpt(first);
            }
        };

        const selectOpt = (opt) => {
            if (!(opt instanceof HTMLElement)) return;
            const iso2 = normalizeCountryIso2(opt.dataset.iso2 || '');
            if (!iso2) return;

            country.dataset.value = iso2;
            const labelSpan = country.querySelector('.country-trigger-label');
            if (labelSpan instanceof HTMLElement) {
                renderCountryLabelEl(labelSpan, iso2, { placeholder: 'Selecciona tu país' });
            }

            for (const node of allOpts) {
                node.setAttribute('aria-selected', node === opt ? 'true' : 'false');
            }

            state.form.country_iso2 = iso2;
            close();
            try { country.focus(); } catch (_) {
            }
        };

        for (const opt of allOpts) {
            opt.addEventListener('pointerdown', (e) => {
                if (!isOpen) return;
                e.preventDefault();
                e.stopPropagation();
                if (opt.classList.contains('is-hidden')) return;
                selectOpt(opt);
            }, { signal });

            opt.addEventListener('click', (e) => {
                if (!isOpen) return;
                e.preventDefault();
                e.stopPropagation();
                if (opt.classList.contains('is-hidden')) return;
                selectOpt(opt);
            }, { signal });
        }

        const hidePopover = (seq) => {
            if (seq !== closeSeq) return;
            countryList.classList.add('is-hidden');
            countryList.classList.remove('is-closing');
        };

        const close = () => {
            if (!isOpen && countryList.classList.contains('is-hidden')) return;
            isOpen = false;
            cancelPendingClose();

            countryList.classList.remove('is-open');
            countryList.classList.add('is-closing');
            country.setAttribute('aria-expanded', 'false');
            searchInput.setAttribute('aria-expanded', 'false');
            setActiveOpt(null);

            const seq = closeSeq;
            closeTransitionHandler = (e) => {
                if (seq !== closeSeq) return;
                if (e.target !== countryList) return;
                if (e.propertyName !== 'opacity') return;
                if (closeTransitionHandler) {
                    countryList.removeEventListener('transitionend', closeTransitionHandler);
                    closeTransitionHandler = null;
                }
                hidePopover(seq);
            };

            countryList.addEventListener('transitionend', closeTransitionHandler);
            closeTimer = window.setTimeout(() => {
                if (closeTransitionHandler) {
                    countryList.removeEventListener('transitionend', closeTransitionHandler);
                    closeTransitionHandler = null;
                }
                hidePopover(seq);
            }, 180);
        };

        const open = () => {
            if (isOpen) return;
            if (country.disabled === true) return;
            cancelPendingClose();
            isOpen = true;
            countryList.classList.remove('is-hidden');
            countryList.classList.remove('is-closing');
            country.setAttribute('aria-expanded', 'true');

            searchInput.setAttribute('aria-expanded', 'true');
            searchInput.value = '';
            applyFilter('');

            const selected = normalizeCountryIso2(country.dataset.value || '');
            const selectedNode = selected ? options.querySelector(`#${CSS.escape(options.id)}-${CSS.escape(selected)}`) : null;
            if (selectedNode instanceof HTMLElement && !selectedNode.classList.contains('is-hidden')) {
                setActiveOpt(selectedNode);
            }

            window.requestAnimationFrame(() => {
                countryList.classList.add('is-open');
            });

            window.requestAnimationFrame(() => {
                try { searchInput.focus(); } catch (e) {
                }
            });
        };

        country.addEventListener('click', (e) => {
            e.preventDefault();
            setInlineError('', state, elements);
            if (isOpen) {
                close();
            } else {
                open();
            }
        }, { signal });

        country.addEventListener('keydown', (e) => {
            if (country.disabled === true) return;

            if (e.key === 'Escape') {
                if (!isOpen) return;
                e.preventDefault();
                close();
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        }, { signal });

        searchInput.addEventListener('input', () => {
            setInlineError('', state, elements);
            applyFilter(searchInput.value);
        }, { signal });

        if (clearBtn instanceof HTMLElement) {
            clearBtn.addEventListener('click', (e) => {
                e.preventDefault();
                searchInput.value = '';
                applyFilter('');
                try { searchInput.focus(); } catch (err) {
                }
            }, { signal });
        }

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (!isOpen) return;
                e.preventDefault();
                close();
                try { country.focus(); } catch (err) {
                }
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Home' || e.key === 'End') {
                e.preventDefault();
                if (!isOpen) open();
                const visible = getVisibleOpts();
                if (!visible.length) return;

                const idx = activeOpt ? visible.indexOf(activeOpt) : -1;
                let next = idx;
                if (e.key === 'ArrowDown') next = Math.min(visible.length - 1, idx < 0 ? 0 : idx + 1);
                if (e.key === 'ArrowUp') next = Math.max(0, idx < 0 ? 0 : idx - 1);
                if (e.key === 'Home') next = 0;
                if (e.key === 'End') next = visible.length - 1;
                setActiveOpt(visible[next]);
                return;
            }

            if (e.key === 'Enter') {
                if (!isOpen) return;
                e.preventDefault();
                const visible = getVisibleOpts();
                if (!visible.length) return;
                selectOpt(activeOpt || visible[0]);
            }
        }, { signal });

        options.addEventListener('focusin', (e) => {
            const t = e.target;
            if (!(t instanceof Element)) return;
            const opt = t.closest('.country-option');
            if (!(opt instanceof HTMLElement)) return;
            if (opt.classList.contains('is-hidden')) return;
            setActiveOpt(opt);
        }, { signal });

        options.addEventListener('keydown', (e) => {
            const active = document.activeElement;
            if (!(active instanceof HTMLElement)) return;

            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                try { country.focus(); } catch (err) {
                }
                return;
            }

            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
            e.preventDefault();

            const visible = getVisibleOpts();
            if (!visible.length) return;

            const idx = visible.findIndex((n) => n === active);
            let next = idx;
            if (e.key === 'ArrowDown') next = Math.min(visible.length - 1, (idx < 0 ? 0 : idx + 1));
            if (e.key === 'ArrowUp') next = Math.max(0, (idx < 0 ? 0 : idx - 1));
            if (e.key === 'Home') next = 0;
            if (e.key === 'End') next = visible.length - 1;

            const toFocus = visible[next];
            if (toFocus instanceof HTMLElement) {
                try { toFocus.focus(); } catch (err) {
                }
            }
        }, { signal });

        options.addEventListener('click', (e) => {
            if (e.defaultPrevented) return;
            const t = e.target;
            const el = (t instanceof Element) ? t : (t instanceof Node ? t.parentElement : null);
            if (!(el instanceof Element)) return;
            const opt = el.closest('.country-option');
            if (!(opt instanceof HTMLElement)) return;
            if (opt.classList.contains('is-hidden')) return;
            selectOpt(opt);
        }, { signal });

        document.addEventListener('pointerdown', (e) => {
            const t = e.target;
            if (!(t instanceof Node)) return;
            if (!isOpen) return;
            if (countryBox && countryBox.contains(t)) return;
            if (countryList.contains(t)) return;
            close();
        }, { passive: true, signal });

        document.addEventListener('focusin', (e) => {
            const t = e.target;
            if (!(t instanceof Node)) return;
            if (!isOpen) return;
            if (countryBox && countryBox.contains(t)) return;
            if (countryList.contains(t)) return;
            close();
        }, { signal });
    }

    if (wa) {
        wa.addEventListener('blur', () => {
            blurred.wa = true;
            const err = getFieldTouchedError(wa, 'whatsapp');
            if (err) { showFieldError(wa, err); } else { clearFieldError(wa); }
        }, { signal });

        wa.addEventListener('input', () => {
            const normalized = VALIDATION_SCHEMA.whatsapp.normalize(wa.value || '');
            if (wa.value !== normalized) wa.value = normalized;
            setInlineError('', state, elements);
            state.form.whatsapp = normalized;
            if (blurred.wa) {
                const err = getFieldTouchedError(wa, 'whatsapp');
                if (err) { showFieldError(wa, err); } else { clearFieldError(wa); }
            }
        }, { signal });
    }

    state.carousel = null;
    if (carouselTrack) {
        state.carousel = new AvatarCarousel({
            state,
            trackEl: carouselTrack,
            slotsEl: carouselSlots,
            signal
        });
        state.carousel.bind();
    }

    state.overlayEl.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const focusables = getFocusableElements(state.overlayEl);
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = document.activeElement;
        if (!state.overlayEl.contains(active)) {
            e.preventDefault();
            (e.shiftKey ? last : first).focus();
            return;
        }
        if (e.shiftKey) {
            if (active === first || !state.overlayEl.contains(active)) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (active === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }, { signal });

    window.addEventListener('scroll', () => {
        if (!state.tooltipAnchor) return;
        showTooltipForAnchor(state.tooltipAnchor, state, elements);
    }, { passive: true, signal });

    window.addEventListener('resize', () => {
        if (!state.tooltipAnchor) return;
        showTooltipForAnchor(state.tooltipAnchor, state, elements);
    }, { passive: true, signal });
}

function showOverlay(state, user) {
    injectStyles(state);

    if (!state.overlayEl) {
        try {
            state.prevActiveEl = document.activeElement;
        } catch (e) {
            state.prevActiveEl = null;
        }
    }

    if (!state.overlayEl) {
        const overlayEl = document.createElement('div');
        overlayEl.id = CONSTANTS.ids.overlay;
        overlayEl.className = 'onboarding-overlay';
        overlayEl.setAttribute('role', 'dialog');
        overlayEl.setAttribute('aria-modal', 'true');
        overlayEl.setAttribute('aria-labelledby', CONSTANTS.ids.title);
        overlayEl.appendChild(buildOverlayDom(user, state));
        document.body.appendChild(overlayEl);
        state.overlayEl = overlayEl;
        setInertOnBackground(true, state);
    } else {
        const emailEl = state.overlayEl.querySelector('#onboardingEmail');
        if (emailEl) emailEl.textContent = String(user.email || '');
    }

    try {
        state.prevBodyOverflow = document.body.style.overflow;
    } catch (e) {
        state.prevBodyOverflow = '';
    }
    document.body.style.overflow = 'hidden';

    try {
        const focusPreference = [
            CONSTANTS.ids.epic,
            CONSTANTS.ids.pub,
            CONSTANTS.ids.country,
            CONSTANTS.ids.whatsapp,
            CONSTANTS.ids.save
        ];

        let toFocus = null;
        for (const id of focusPreference) {
            const el = state.overlayEl?.querySelector?.(`#${id}`);
            if (!(el instanceof HTMLElement)) continue;
            if (el.hasAttribute('disabled')) continue;
            if ('disabled' in el && el.disabled === true) continue;
            if (el.tabIndex < 0) continue;
            toFocus = el;
            break;
        }

        if (!toFocus) {
            const focusables = getFocusableElements(state.overlayEl);
            toFocus = focusables[0] || null;
        }

        if (toFocus) toFocus.focus();
    } catch (e) {
    }
}

function hideOverlay(state, elements) {
    if (state.abort) {
        try { state.abort.abort(); } catch (e) {
        }
        state.abort = null;
    }

    state.carousel = null;

    hideTooltip(state, elements);

    if (state.overlayEl) {
        state.overlayEl.remove();
        state.overlayEl = null;
    }

    setInertOnBackground(false, state);

    try {
        document.body.style.overflow = state.prevBodyOverflow || '';
    } catch (e) {
    }

    try {
        if (state.prevActiveEl && state.prevActiveEl instanceof HTMLElement) {
            state.prevActiveEl.focus();
        }
    } catch (e) {
    }
    state.prevActiveEl = null;
}

export function ensureOnboardingSetupModal({ dashboardData, apiEndpoint }) {
    getOnboardingModalSingleton().ensure({ dashboardData, apiEndpoint });
}
