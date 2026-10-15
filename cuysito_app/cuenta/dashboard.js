import { renderOrderProductRow, buildFortniteGradientVarsFromDb } from '/shared/js/order-panels-shared.js';
import { ensureOnboardingSetupModal } from '/cuenta/onboarding_modal.js';

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

function showDashboardToast(message, type = 'success') {
    const text = String(message ?? '').trim();
    if (!text) return;

    let container = document.getElementById('dashboard-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'dashboard-toast-container';
        container.className = 'fixed bottom-6 right-6 z-[200] flex flex-col gap-3';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    const base = 'rounded-xl px-4 py-3 shadow-2xl border text-sm font-semibold transition-all duration-300';
    const palette = type === 'error'
        ? 'bg-red-600 text-white border-red-500'
        : 'bg-emerald-500 text-emerald-950 border-emerald-400';
    toast.className = `${base} ${palette}`;
    toast.textContent = text;
    container.appendChild(toast);

    window.setTimeout(() => {
        toast.classList.add('opacity-0', 'translate-y-2');
    }, 2600);
    window.setTimeout(() => {
        try { toast.remove(); } catch (e) {}
    }, 3100);
}

function redirectToLogin() {
    const path = window.location.pathname || '/cuenta/';
    const search = window.location.search || '';
    const redirectTo = `${path}${search}`;
    window.location.assign(`/login/?redirect_to=${encodeURIComponent(redirectTo)}`);
}

// UTC-FIRST: el backend SIEMPRE envía fechas en UTC (formato MySQL "YYYY-MM-DD HH:MM:SS" o ISO).
// Normalizamos a un instante UTC real para que toLocaleString/toLocaleDateString las muestre
// en la zona horaria local del navegador del cliente (Perú, España, donde sea) de forma automática.
function parseServerDateUTC(dateString) {
    if (!dateString) return null;
    if (dateString instanceof Date) return dateString;
    let s = String(dateString).trim();
    if (!s) return null;
    // Si ya trae zona horaria explícita (Z u offset ±HH:MM), se respeta tal cual.
    if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(s)) return new Date(s);
    // "YYYY-MM-DD HH:MM:SS" -> ISO 8601 con sufijo Z (UTC) para un parseo fiable en todos los navegadores.
    s = s.replace(' ', 'T');
    return new Date(s + 'Z');
}

function detectUserCountry() {
    const init = typeof window !== 'undefined' && window.INIT_DATA ? window.INIT_DATA : {};
    const backend = typeof init.country === 'string' ? init.country.toLowerCase().trim() : '';
    if (/^[a-z]{2}$/.test(backend)) return backend;

    try {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (typeof tz === 'string' && /^America\/Lima$/i.test(tz.trim())) return 'pe';
    } catch (_) {}

    return 'pe';
}

const DETECTED_COUNTRY = detectUserCountry();

function foldSearchText(v) {
    const s = String(v ?? '');
    try {
        return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    } catch (e) {
        return s.toLowerCase();
    }
}

const DASH_COUNTRIES = [
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
];

const DASH_COUNTRIES_BY_ISO2 = (() => {
    const m = Object.create(null);
    DASH_COUNTRIES.forEach((c) => {
        const k = String(c.iso2 || '').toLowerCase().replace(/[^a-z]/g, '').slice(0, 5);
        if (k) m[k] = c;
    });
    return m;
})();

class DashboardController {
    constructor(options = {}) {
        this.apiEndpoint = options.apiEndpoint || 'j5r';

        this.state = {
            data: null,
            view: 'settings'
        };

        this.el = {
            header: null,
            sidebar: null,
            content: null,
            modals: null,
            notifList: null,
            notifBadge: null,
            notifBell: null,
            notifPanel: null,
            navUsername: null,
            navCode: null
        };

        this.handlePopState = this.handlePopState.bind(this);

        this._countryDocListener = null;
        this._countryOpen = false;
    }

    init() {
        document.addEventListener('DOMContentLoaded', () => {
            this.cacheElements();
            window.addEventListener('popstate', this.handlePopState);
            this.initThemeToggle();
            this.run();
        });
    }

    initThemeToggle() {
        const btn = document.getElementById('themeToggle');
        if (!btn) return;

        const root = document.documentElement;
        const withoutTransitions = (fn) => {
            if (!root) return fn();
            root.classList.add('theme-no-transitions');
            try {
                fn();
            } finally {
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        root.classList.remove('theme-no-transitions');
                    });
                });
            }
        };

        const apply = (theme) => {
            const safeTheme = theme === 'dark' ? 'dark' : 'light';
            withoutTransitions(() => {
                root.setAttribute('data-theme', safeTheme);
                root.style.colorScheme = safeTheme;

                const icon = document.getElementById('themeToggleIcon');
                const text = document.getElementById('themeToggleText');

                const isDark = safeTheme === 'dark';
                btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                btn.setAttribute('aria-label', isDark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');

                if (icon) {
                    icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                }
                if (text) {
                    text.textContent = isDark ? 'Claro' : 'Oscuro';
                }
            });
        };

        const getStored = () => {
            try {
                const t = localStorage.getItem('banano_account_theme');
                return t === 'dark' ? 'dark' : 'light';
            } catch (e) {
                return 'light';
            }
        };

        apply(getStored());

        btn.addEventListener('click', () => {
            const next = (document.documentElement.getAttribute('data-theme') === 'dark') ? 'light' : 'dark';
            try {
                localStorage.setItem('banano_account_theme', next);
            } catch (e) {
            }
            apply(next);
        });
    }

    cacheElements() {
        this.el.header = document.getElementById('dashboard-header');
        this.el.sidebar = document.getElementById('dashboard-sidebar');
        this.el.content = document.getElementById('dashboard-content');
        this.el.modals = document.getElementById('dashboard-modals');

        this.el.notifList = document.getElementById('notifList');
        this.el.notifBadge = document.getElementById('notifBadge');
        this.el.notifBell = document.getElementById('notifBell');
        this.el.notifPanel = document.getElementById('notifPanel');

        this.el.navUsername = document.getElementById('navUsername');
        this.el.navCode = document.getElementById('navCode');
    }

    getViewFromUrl() {
        const path = String(window.location.pathname || '').replace(/\/+$/, '');
        if (path === '/cuenta/ordenes') return 'orders';
        if (path === '/cuenta/ajustes') return 'settings';

        const params = new URLSearchParams(window.location.search);
        const view = params.get('view');
        if (view === 'orders') return 'orders';
        if (view === 'settings') return 'settings';
        return 'settings';
    }

    handlePopState() {
        this.state.view = this.getViewFromUrl();
        this.renderAll();
    }

    async run() {
        this.state.view = this.getViewFromUrl();

        try {
            await this.loadDashboard();
            this.renderAll();
        } catch (err) {
            this.renderFatalError('No se pudo cargar la información');
        }
    }

    async loadDashboard() {
        const res = await fetch('/web.php', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: new URLSearchParams({ action: this.apiEndpoint, sub: 'd2' })
        });

        if (res.status === 401) {
            redirectToLogin();
            return;
        }

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const data = await _resolveSyncFrame(await res.json());
        if (!data || data.status !== 'success') {
            throw new Error('API error');
        }

        this.state.data = data;
    }

    renderAll() {
        if (!this.state.data) {
            this.renderFatalError('No se pudo cargar la información');
            return;
        }

        ensureOnboardingSetupModal({
            dashboardData: this.state.data,
            apiEndpoint: this.apiEndpoint
        });

        this.renderSidebar();
        this.renderMobileNav();
        this.renderHeader();
        this.renderNotifications();
        this.renderContent();
        this.renderModals();
        this.bindEvents();
        this.refreshSmartVerificationWidget();
    }

    // Barra de navegación inferior estilo app nativa (solo teléfonos, < md).
    // El escritorio/tablet sigue usando la barra lateral (#dashboard-sidebar).
    renderMobileNav() {
        const el = document.getElementById('dashboard-mobile-nav');
        if (!el) return;

        const view = this.state.view;
        const item = (href, icon, label, active) => `
            <a href="${href}" class="flex flex-col items-center justify-center gap-1 flex-1 py-2.5 transition-colors ${active ? 'text-amber-400' : 'text-zinc-400 hover:text-zinc-200'}">
                <i class="fas ${icon} text-lg" aria-hidden="true"></i>
                <span class="text-[10px] font-bold tracking-wide text-center whitespace-nowrap">${label}</span>
            </a>`;

        // 3 accesos simétricos. "Cerrar sesión" ya no vive aquí (se movió a Ajustes).
        el.innerHTML = `
            <nav class="md:hidden fixed bottom-0 inset-x-0 z-50 bg-zinc-950 border-t border-zinc-800 flex items-stretch px-1" style="padding-bottom:env(safe-area-inset-bottom);" aria-label="Navegación de la cuenta">
                ${item('/cuenta/ajustes', 'fa-user-cog', 'Ajustes', view === 'settings')}
                ${item('/cuenta/ordenes', 'fa-shopping-bag', 'Pedidos', view === 'orders')}
                ${item('/', 'fa-store', 'Volver a Tienda', false)}
            </nav>
        `;
    }

    renderFatalError(message) {
        if (this.el.content) {
            this.el.content.innerHTML = `
                <div class="dashboard-card rounded-xl p-8">
                    <h2 class="text-sm font-bold tracking-[0.18em] uppercase text-zinc-200 mb-2">Error</h2>
                    <p class="text-sm text-zinc-400">${DashboardController.escapeHtml(message)}</p>
                </div>
            `;
        }
    }

    renderSidebar() {
        if (!this.el.sidebar) return;

        const view = this.state.view;
        const isSettings = view === 'settings';
        const isOrders = view === 'orders';

        const activeClass = 'bg-zinc-800 text-zinc-100 border border-zinc-700';
        const inactiveClass = 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200';

        this.el.sidebar.innerHTML = `
            <div class="mb-4">
                <span class="text-xs font-bold tracking-[0.22em] uppercase text-zinc-500">Cuenta</span>
            </div>

            <nav class="space-y-1" id="dashboard-sidebar-nav">
                <a href="/cuenta/ajustes" data-nav="settings" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${isSettings ? activeClass : inactiveClass}">
                    <i class="fas fa-user-cog w-5 text-center text-zinc-400" aria-hidden="true"></i>
                    <span class="text-sm font-semibold">Ajustes</span>
                </a>

                <a href="/cuenta/ordenes" data-nav="orders" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${isOrders ? activeClass : inactiveClass}">
                    <i class="fas fa-shopping-bag w-5 text-center text-zinc-400" aria-hidden="true"></i>
                    <span class="text-sm font-semibold">Mis Pedidos</span>
                </a>

                <div class="pt-4 mt-4 border-t border-zinc-800">
                    <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200">
                        <i class="fas fa-sign-out-alt w-5 text-center" aria-hidden="true"></i>
                        <span class="text-sm font-semibold">Cerrar Sesión</span>
                    </a>
                </div>
            </nav>
        `;
    }

    renderHeader() {
        if (!this.el.header) return;

        const u = this.state.data.user || {};

        if (this.el.navUsername) this.el.navUsername.textContent = u.game_username || '...';
        if (this.el.navCode) this.el.navCode.textContent = u.public_code || '...';

        this.el.header.innerHTML = `
            <div class="dashboard-card rounded-xl p-6" id="user-profile-header">
                <div class="flex flex-col md:flex-row items-center md:items-start gap-6">
                    <button type="button" data-action="open-avatar" class="dashboard-avatar" aria-label="Editar avatar">
                        <img id="currentAvatarImg" src="" class="dashboard-avatar__image hidden" alt="Avatar" />
                        <i id="defaultAvatarIcon" class="fas fa-user text-2xl text-zinc-500" aria-hidden="true"></i>
                    </button>

                    <div class="flex-1 w-full">
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                            <div class="text-center md:text-left">
                                <h1 id="headerName" class="text-2xl font-bold text-zinc-100">${DashboardController.escapeHtml(u.game_username || '...')}</h1>
                                <div class="mt-1 text-sm text-zinc-400" id="headerEmail">${DashboardController.escapeHtml(u.email || '...')}</div>
                                <div id="badgesContainer" class="mt-3 flex flex-wrap gap-2 justify-center md:justify-start"></div>
                            </div>

                            <div class="dashboard-card rounded-xl px-5 py-4 w-full md:w-auto min-w-[220px]">
                                <div class="text-xs font-bold tracking-[0.22em] uppercase text-zinc-500 text-center">Saldo Disponible</div>
                                <div class="mt-2 flex flex-col items-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <img src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1768631193/bananos_cfqagf.webp" alt="" aria-hidden="true" class="h-9 w-auto shrink-0" />
                                        <span id="headerCoins" class="text-3xl font-bold leading-none text-zinc-100">${DashboardController.escapeHtml(DashboardController.formatCoins(u.coins))}</span>
                                    </div>
                                    <div class="mt-1 text-xs font-bold leading-none tracking-[0.22em] uppercase text-zinc-500 text-center">Bananos</div>
                                </div>
                            </div>
                        </div>

                        <div id="verification-widget" class="hidden d-none mt-6">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-bold tracking-[0.18em] uppercase text-zinc-200">Proceso de Verificación</h3>
                                    <p class="mt-1 text-xs text-zinc-500">Realiza compras para desbloquear beneficios exclusivos.</p>
                                </div>
                                <span id="verification-review-badge" class="hidden px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-400 text-amber-950 border border-amber-300">Estado: En Revisión</span>
                                <span id="verification-rejected-badge" class="hidden px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-zinc-600 text-zinc-200 border border-zinc-500">Estado: Rechazado</span>
                            </div>

                            <div class="mt-3 flex items-center gap-3">
                                <span id="verification-percent-text" class="text-zinc-100 font-bold text-sm whitespace-nowrap">0%</span>
                                <div class="flex-1">
                                    <div class="w-full h-3 rounded-full bg-zinc-800 overflow-hidden">
                                        <div id="verification-progress-fill" class="h-full bg-emerald-500 transition-all duration-500" style="width:0%"></div>
                                    </div>
                                </div>
                            </div>

                            <p id="verification-rejected-hint" class="hidden max-sm:!hidden mt-3 text-xs text-zinc-500 leading-relaxed">Por favor, completa tu verificación automáticamente llenando la barra de progreso con tus compras en la web.</p>

                            <div class="mt-6 flex">
                                <button id="verification-request-btn" type="button" class="dashboard-button ml-auto inline-flex w-auto items-center justify-center whitespace-nowrap !py-2 !px-4 text-sm opacity-60 cursor-not-allowed" disabled>Solicitar Verificación</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.applyVerificationProgress();
        this.applyAvatar(u.avatar_id);
    }

    renderContent() {
        if (!this.el.content) return;

        this.el.content.classList.toggle('space-y-6', this.state.view !== 'orders');

        if (this.state.view === 'orders') {
            this.el.content.innerHTML = this.renderOrdersView();
            this.renderOrdersRows();
            return;
        }

        this.el.content.innerHTML = this.renderSettingsView();
        this.applyInputRules();
    }

    renderSettingsView() {
        const hideCountry = DashboardController.shouldHideCountrySelector(this.state.data.user?.country_iso2);
        return `
            <h2 class="text-sm font-bold tracking-[0.22em] uppercase text-zinc-500 mb-6">Ajustes de Cuenta</h2>

            <div class="dashboard-card rounded-xl p-8">
                <h3 class="text-sm font-bold tracking-[0.18em] uppercase text-zinc-200 mb-6 flex items-center gap-3 border-b border-zinc-800 pb-4">
                    <i class="fas fa-id-card text-zinc-400" aria-hidden="true"></i>
                    <span class="text-sm font-semibold">Información Personal</span>
                </h3>

                <div class="grid grid-cols-1 gap-6 max-w-2xl">
                    <div>
                        <label class="block text-xs font-bold tracking-[0.18em] uppercase text-zinc-500 mb-2">Nickname Epic Games</label>
                        <div class="flex gap-3">
                            <input type="text" id="inputGameName" disabled class="dashboard-input dashboard-input--dark" />
                            <button type="button" id="btnSaveNick" class="dashboard-button dashboard-button--save hidden" data-save="game_username" aria-label="Guardar Nickname"><i class="fas fa-save" aria-hidden="true"></i></button>
                        </div>
                        <p id="msgNick" class="text-sm text-zinc-500 mt-2"></p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold tracking-[0.18em] uppercase text-zinc-500 mb-2">Nombre Público (Sorteos)</label>
                        <div class="flex gap-3">
                            <input type="text" id="inputPublicName" disabled class="dashboard-input dashboard-input--dark" />
                            <button type="button" id="btnSavePublic" class="dashboard-button dashboard-button--save hidden" data-save="public_name" aria-label="Guardar Nombre Público"><i class="fas fa-save" aria-hidden="true"></i></button>
                        </div>
                        <p id="msgPublic" class="text-sm text-zinc-500 mt-2"></p>
                    </div>

                    ${hideCountry ? '' : `
                    <div>
                        <label class="block text-xs font-bold tracking-[0.18em] uppercase text-zinc-500 mb-2">País</label>
                        <div class="flex gap-3">
                            <div class="relative w-full">
                                <button type="button" id="inputCountry" class="dashboard-input dashboard-input--dark dashboard-country-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="countryOptions">
                                    <span data-role="country-label">Selecciona tu país</span>
                                    <i class="fas fa-chevron-down text-xs text-zinc-400" aria-hidden="true"></i>
                                </button>
                                <div id="countryOptions" class="dashboard-country-options hidden" aria-label="País">
                                    <div class="dashboard-country-search">
                                        <div class="dashboard-country-search-inner">
                                            <i class="fas fa-search text-xs text-zinc-400" aria-hidden="true"></i>
                                            <input id="countrySearch" type="text" class="dashboard-country-search-input" placeholder="Buscar país" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="countryOptionsList" />
                                            <button type="button" id="countrySearchClear" class="dashboard-country-search-clear hidden" aria-label="Limpiar búsqueda"><i class="fas fa-times" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                    <div id="countryOptionsList" class="dashboard-country-options-list" role="listbox" tabindex="-1">
                                        <div id="countryOptionsEmpty" class="dashboard-country-empty hidden">No se encontraron resultados</div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" id="btnSaveCountry" class="dashboard-button dashboard-button--save hidden" data-save="country_iso2" aria-label="Guardar País"><i class="fas fa-save" aria-hidden="true"></i></button>
                        </div>
                        <p id="msgCountry" class="text-sm text-zinc-500 mt-2"></p>
                    </div>
                    `}

                    <div>
                        <label class="block text-xs font-bold tracking-[0.18em] uppercase text-zinc-500 mb-2">WhatsApp</label>
                        <div class="flex gap-3">
                            <input type="text" id="inputWhatsapp" placeholder="+51..." disabled class="dashboard-input dashboard-input--dark" />
                            <button type="button" id="btnSaveWa" class="dashboard-button dashboard-button--save hidden" data-save="whatsapp" aria-label="Guardar WhatsApp"><i class="fas fa-save" aria-hidden="true"></i></button>
                        </div>
                        <p id="msgWa" class="text-sm text-zinc-500 mt-2"></p>
                    </div>
                </div>
            </div>

            <!-- Cerrar sesión: solo en móvil (en escritorio vive en la barra lateral). -->
            <div class="md:hidden mt-6">
                <button type="button" data-action="open-logout-confirm" class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl text-sm font-bold tracking-wide text-red-400 bg-red-500/5 border border-red-500/30 hover:bg-red-500/10 transition-colors">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    Cerrar Sesión
                </button>
            </div>
        `;
    }

    renderOrdersView() {
        return `
            <h2 class="text-sm font-bold tracking-[0.22em] uppercase text-zinc-500 mb-6">Historial de Pedidos</h2>

            <!-- Escritorio / tablet: tabla completa -->
            <div class="dashboard-card rounded-xl overflow-hidden hidden md:block">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-zinc-950 text-xs font-bold tracking-[0.18em] uppercase text-zinc-500 border-b border-zinc-800">
                            <tr>
                                <th class="px-6 py-4">Código</th>
                                <th class="px-6 py-4">Detalle</th>
                                <th class="px-6 py-4">Fecha</th>
                                <th class="px-6 py-4 text-right">Total</th>
                                <th class="px-6 py-4 text-center">Estado</th>
                                <th class="px-6 py-4 text-right">Detalles</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody" class="divide-y divide-zinc-800 text-sm">
                            <tr><td colspan="6" class="px-6 py-8 text-center text-zinc-500">Cargando historial...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Móvil: lista de una sola línea (nombre + total + ver detalles) -->
            <div id="ordersMobileList" class="md:hidden space-y-2">
                <div class="text-center text-zinc-500 text-sm py-8">Cargando historial...</div>
            </div>
        `;
    }

    renderOrdersRows() {
        const tbody = document.getElementById('ordersTableBody');
        const mobileList = document.getElementById('ordersMobileList');
        if (!tbody && !mobileList) return;

        const orders = Array.isArray(this.state.data.orders) ? this.state.data.orders : [];
        if (tbody) tbody.innerHTML = '';
        let mobileHtml = '';

        if (orders.length === 0) {
            if (tbody) tbody.innerHTML = `<tr><td colspan="6" class="px-6 py-12 text-center text-zinc-500"><div class="flex flex-col items-center gap-2"><i class="fas fa-shopping-basket text-3xl text-zinc-700" aria-hidden="true"></i><span class="text-sm">Aún no tienes pedidos.</span></div></td></tr>`;
            if (mobileList) mobileList.innerHTML = `<div class="dashboard-card rounded-xl px-4 py-12 text-center text-zinc-500"><div class="flex flex-col items-center gap-2"><i class="fas fa-shopping-basket text-3xl text-zinc-700" aria-hidden="true"></i><span class="text-sm">Aún no tienes pedidos.</span></div></div>`;
            return;
        }

        orders.forEach((o) => {
            const parsedDate = parseServerDateUTC(o.fecha_creacion);
            const date = parsedDate ? parsedDate.toLocaleDateString() : '';
            const count = Number(o.c || 0);
            const subText = count > 1 ? `+ ${count - 1} más` : '';
            const rawStatus = String(o.estado || '');
            const statusClass = DashboardController.getOrderStatusClass(rawStatus);
            const statusText = DashboardController.escapeHtml(rawStatus.replace('_', ' '));
            const code = String(o.codigo_pedido || '');

            // Cashback discreto en la columna "Total": solo cuando el pedido está
            // completado y ganó Bananos. Minimalista: fuente pequeña, sin icono ni texto.
            const cashback = Number(o.cashback_earned || 0) || 0;
            const cashbackChip = (rawStatus === 'completado' && cashback > 0)
                ? `<div class="text-[10px] leading-none mt-1 font-semibold text-amber-400/80">+${DashboardController.escapeHtml(cashback.toLocaleString('es-ES', { maximumFractionDigits: 0 }))}</div>`
                : '';

            if (tbody) tbody.innerHTML += `
                <tr class="hover:bg-zinc-900 transition-colors">
                    <td class="px-6 py-4 text-zinc-400 font-bold tracking-wider">#${DashboardController.escapeHtml(code)}</td>
                    <td class="px-6 py-4">
                        <div class="font-semibold text-zinc-100">${DashboardController.escapeHtml(o.f || '')}</div>
                        <div class="text-xs text-zinc-500">${DashboardController.escapeHtml(subText)}</div>
                    </td>
                    <td class="px-6 py-4 text-zinc-400">${DashboardController.escapeHtml(date)}</td>
                    <td class="px-6 py-4 text-right font-semibold text-zinc-100 whitespace-nowrap">
                        <div class="whitespace-nowrap">S/ ${DashboardController.escapeHtml(DashboardController.formatMoney(o.total_pagar))}</div>
                        ${cashbackChip}
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="status-badge px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider ${statusClass}">${statusText}</span>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <button type="button" data-action="open-order-details" data-order-code="${DashboardController.escapeHtml(code)}" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-bold tracking-[0.18em] uppercase bg-zinc-900 text-zinc-200 border border-zinc-800 hover:bg-zinc-800 hover:border-zinc-700 transition-colors">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                            Ver Detalles
                        </button>
                    </td>
                </tr>
            `;

            // Móvil: tarjeta a 2 columnas. Toda la tarjeta abre el modal de detalles.
            // Izquierda: estado (punto) + fecha discreta, nombre del 1er producto y "+X más".
            // Derecha: total y, debajo, los Bananos ganados (solo si completado y > 0).
            const dotClass = DashboardController.getOrderStatusDotClass(rawStatus);
            const mobileCashback = (rawStatus === 'completado' && cashback > 0)
                ? `<div class="mt-1 flex items-center justify-end gap-1 text-[11px] font-semibold text-amber-400/90 whitespace-nowrap">
                        <img src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1768631193/bananos_cfqagf.webp" alt="" aria-hidden="true" class="h-3.5 w-auto shrink-0" />
                        +${DashboardController.escapeHtml(cashback.toLocaleString('es-ES', { maximumFractionDigits: 0 }))} Bananos
                   </div>`
                : '';
            const mobileSub = subText
                ? `<div class="text-xs text-zinc-500 mt-0.5 truncate">${DashboardController.escapeHtml(subText)}</div>`
                : '';
            mobileHtml += `
                <button type="button" data-action="open-order-details" data-order-code="${DashboardController.escapeHtml(code)}" class="w-full flex items-center gap-4 dashboard-card rounded-xl px-4 py-3.5 text-left hover:bg-zinc-900 transition-colors">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="shrink-0 w-1.5 h-1.5 rounded-full ${dotClass}" aria-hidden="true"></span>
                            <span class="text-[11px] tracking-wide text-zinc-500">${DashboardController.escapeHtml(date)}</span>
                        </div>
                        <div class="text-sm font-semibold text-zinc-100 truncate">${DashboardController.escapeHtml(o.f || '—')}</div>
                        ${mobileSub}
                    </div>
                    <div class="shrink-0 flex flex-col items-end justify-center text-right">
                        <div class="text-base font-bold text-zinc-100 whitespace-nowrap">S/ ${DashboardController.escapeHtml(DashboardController.formatMoney(o.total_pagar))}</div>
                        ${mobileCashback}
                    </div>
                </button>
            `;
        });

        if (mobileList) mobileList.innerHTML = mobileHtml;
    }

    renderNotifications() {
        const notifs = Array.isArray(this.state.data.notifications) ? this.state.data.notifications : [];
        if (!this.el.notifList || !this.el.notifBadge) return;

        if (notifs.length === 0) {
            this.el.notifList.innerHTML = `
                <div class="px-4 py-8 text-center">
                    <i class="far fa-bell-slash text-2xl text-zinc-700 mb-2" aria-hidden="true"></i>
                    <p class="text-xs text-zinc-500">Sin novedades por ahora.</p>
                </div>`;
            this.el.notifBadge.classList.add('hidden');
            return;
        }

        let unread = 0;
        this.el.notifList.innerHTML = '';

        notifs.forEach((n) => {
            const isUnread = Number(n.is_read) === 0;
            if (isUnread) unread++;
            const icon = n.type === 'coins' ? 'fa-coins text-amber-400' : 'fa-info-circle text-zinc-400';

            this.el.notifList.innerHTML += `
                <div class="p-3 border-b border-zinc-800 hover:bg-zinc-900 transition-colors ${isUnread ? 'bg-amber-500/[0.04]' : ''}">
                    <div class="flex items-start gap-2.5">
                        <i class="fas ${icon} mt-0.5" aria-hidden="true"></i>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold tracking-wider text-zinc-200">${DashboardController.escapeHtml(n.title || '')}</p>
                            <p class="text-xs text-zinc-400 break-words">${DashboardController.escapeHtml(n.message || '')}</p>
                        </div>
                        ${isUnread ? '<span class="shrink-0 mt-1.5 w-2 h-2 rounded-full bg-amber-400" aria-label="No leída"></span>' : ''}
                    </div>
                </div>
            `;
        });

        if (unread > 0) {
            this.el.notifBadge.textContent = unread > 9 ? '9+' : String(unread);
            this.el.notifBadge.classList.remove('hidden');
        } else {
            this.el.notifBadge.classList.add('hidden');
        }
    }

    renderModals() {
        if (!this.el.modals) return;

        this.el.modals.innerHTML = `
            <div id="avatarModal" class="fixed inset-0 z-[100] bg-black hidden items-center justify-center transition-opacity opacity-0">
                <div class="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-2xl m-4 flex flex-col max-h-[80vh] shadow-2xl transform scale-95 transition-transform duration-300" id="avatarModalContent">
                    <div class="p-6 border-b border-zinc-800 flex justify-between items-center">
                        <h3 class="text-sm font-bold tracking-[0.18em] uppercase text-zinc-200">Elige tu Avatar</h3>
                        <button type="button" data-action="close-avatar" class="text-zinc-400 hover:text-zinc-200 transition-colors" aria-label="Cerrar"><i class="fas fa-times text-lg" aria-hidden="true"></i></button>
                    </div>
                    <div class="p-6 overflow-y-auto custom-scrollbar">
                        <div id="avatarGrid" class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-4"></div>
                    </div>
                </div>
            </div>

            <div id="orderDetailsModal" class="fixed inset-0 z-[110] bg-black/70 hidden items-center justify-center transition-opacity opacity-0">
                <div class="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-2xl mx-2 my-2 md:m-4 flex flex-col max-h-[94vh] md:max-h-[80vh] shadow-2xl transform scale-95 transition-transform duration-300" id="orderDetailsModalContent">
                    <div class="p-4 md:p-6 border-b border-zinc-800 flex justify-between items-start gap-4">
                        <div class="min-w-0">
                            <h3 class="text-sm font-bold tracking-[0.18em] uppercase text-zinc-200">Detalles del Pedido</h3>
                        </div>
                        <button type="button" data-action="close-order-details" class="text-zinc-400 hover:text-zinc-200 transition-colors" aria-label="Cerrar"><i class="fas fa-times text-lg" aria-hidden="true"></i></button>
                    </div>
                    <div class="p-4 md:p-6 overflow-y-auto custom-scrollbar" id="orderDetailsBody"></div>
                </div>
            </div>

            <div id="orderImageLightbox" class="fixed inset-0 z-[130] bg-black/80 backdrop-blur-sm hidden items-center justify-center p-4 opacity-0">
                <div class="lightbox-stage relative w-full max-w-md transform scale-95">
                    <button type="button" data-action="close-image-lightbox" class="absolute top-3 right-3 z-10 grid place-items-center w-9 h-9 rounded-full bg-black/50 text-white hover:bg-black/70 transition-colors" aria-label="Cerrar"><i class="fas fa-times text-lg" aria-hidden="true"></i></button>
                    <div id="orderImageLightboxStage" class="fortnite-gradient w-full aspect-square rounded-2xl border border-zinc-800 overflow-hidden grid place-items-center p-6 shadow-2xl">
                        <img id="orderImageLightboxImg" src="" alt="" class="max-w-full max-h-full object-contain drop-shadow-2xl">
                    </div>
                    <p id="orderImageLightboxName" class="mt-3 text-center text-sm font-semibold text-zinc-100 truncate"></p>
                </div>
            </div>

            <div id="verificationRequestModal" class="fixed inset-0 z-[120] bg-black/70 hidden items-center justify-center transition-opacity opacity-0">
                <div class="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-lg m-4 flex flex-col shadow-2xl transform scale-95 transition-transform duration-300" id="verificationRequestModalContent">
                    <div class="px-6 py-4 border-b border-zinc-800 flex justify-between items-start gap-4">
                        <div class="min-w-0">
                            <h3 class="text-lg font-bold tracking-[0.18em] uppercase text-zinc-200">Verificación Manual</h3>
                        </div>
                        <button type="button" data-action="close-verification-request" class="text-zinc-400 hover:text-zinc-200 transition-colors" aria-label="Cerrar"><i class="fas fa-times text-xl" aria-hidden="true"></i></button>
                    </div>
                    <div class="p-6 max-h-[70vh] overflow-y-auto">
                        <div class="space-y-4 text-sm text-zinc-300 leading-relaxed">
                            <p>La <strong class="text-zinc-100">Verificación Manual</strong> está diseñada <strong class="text-amber-400 uppercase tracking-wide">exclusivamente</strong> para aquellos clientes frecuentes que suelen realizar sus compras a través del chat <span class="text-zinc-400">(WhatsApp/Redes)</span> y no mediante la web. Si este es tu caso y quieres verificar tu cuenta para acceder a sorteos y beneficios especiales, debes cumplir con lo siguiente:</p>
                            <ul class="list-disc list-outside ml-5 space-y-2">
                                <li>Haber concretado al menos <strong class="text-zinc-100">5 compras significativas</strong> a lo largo del <strong class="text-zinc-100">2026</strong> con tu Nickname actual.</li>
                                <li><em class="text-zinc-400">Nota:</em> Si adquiriste artículos de alto valor <span class="text-zinc-400">(como Lotes completos o recargas grandes)</span>, tu solicitud puede ser aprobada con un número menor de compras.</li>
                            </ul>
                            <p><strong class="text-emerald-400">Si cumples con este perfil, ¡confirma tu solicitud!</strong> Las peticiones que no alcancen este nivel de actividad serán rechazadas.</p>
                            <hr class="border-zinc-800">
                            <div class="bg-zinc-900/50 border border-zinc-800 rounded-lg p-3 space-y-2">
                                <p class="text-[13px] font-semibold text-zinc-200">¿Aún no cumples los requisitos? <span class="text-cyan-400">¡Usa la Verificación Automática!</span></p>
                                <p class="text-xs text-zinc-400 leading-relaxed">No es necesario que envíes esta solicitud. A medida que realices tus compras regulares directamente en la página, verás cómo tu barra de porcentaje va subiendo. Una vez que alcance el <strong class="text-zinc-300">100%</strong>, tu cuenta se verificará de manera totalmente automática, sin esperas ni revisiones manuales.</p>
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" data-action="cancel-verification-request" class="dashboard-button inline-flex w-auto items-center justify-center whitespace-nowrap !py-2 !px-4 text-sm">Cancelar</button>
                            <button type="button" id="btnConfirmVerificationRequest" class="dashboard-button dashboard-button--save inline-flex w-auto items-center justify-center whitespace-nowrap !py-2 !px-4 text-sm">Confirmar y Enviar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="logoutConfirmModal" class="fixed inset-0 z-[120] bg-black/70 hidden items-center justify-center p-4 transition-opacity opacity-0">
                <div class="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-sm shadow-2xl transform scale-95 transition-transform duration-300" id="logoutConfirmModalContent">
                    <div class="p-6 text-center">
                        <div class="mx-auto grid place-items-center w-12 h-12 rounded-full bg-red-500/10 text-red-400 mb-4"><i class="fas fa-sign-out-alt text-lg" aria-hidden="true"></i></div>
                        <h3 class="text-sm font-bold tracking-[0.18em] uppercase text-zinc-200">¿Cerrar sesión?</h3>
                        <p class="mt-2 text-sm text-zinc-400">Se cerrará tu sesión en este dispositivo.</p>
                        <div class="mt-6 flex gap-3">
                            <button type="button" data-action="cancel-logout" class="flex-1 inline-flex items-center justify-center px-5 py-2.5 rounded-xl font-bold text-sm border border-zinc-800 text-zinc-200 hover:bg-zinc-900 transition-colors">Cancelar</button>
                            <a href="logout.php" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm bg-red-500 text-white hover:bg-red-400 transition-colors"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.renderAvatarGrid();
    }

    async refreshSmartVerificationWidget() {
        const widget = document.getElementById('verification-widget');
        if (!widget) return;

        const fill = document.getElementById('verification-progress-fill');
        const badge = document.getElementById('verification-review-badge');
        const percentText = document.getElementById('verification-percent-text');
        const btn = document.getElementById('verification-request-btn');

        if (!fill || !badge || !percentText || !btn) return;

        try {
            const res = await fetch('/web.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: 'action=i3n'
            });

            if (res.status === 401) {
                redirectToLogin();
                return;
            }

            const data = await _resolveSyncFrame(await res.json());
            if (!data || data.success !== true) {
                widget.classList.add('hidden', 'd-none');
                return;
            }

            if (data.is_verified === true) {
                widget.classList.add('hidden', 'd-none');
                if (data.just_auto_verified === true) {
                    showDashboardToast('¡Felicidades! Tu historial ha sido reconocido. Ahora eres Verificado.', 'success');
                }
                return;
            }

            widget.classList.remove('hidden', 'd-none');
            const percent = Math.max(0, Math.min(100, Number(data.progress_percent || 0)));
            fill.style.width = `${percent}%`;
            percentText.textContent = `${percent}%`;

            if (data.has_pending_request === true) {
                badge.classList.remove('hidden');
                btn.classList.add('hidden');
                btn.disabled = true;
                return;
            }

            if (data.verification_rejected === true) {
                badge.classList.add('hidden');
                const rejBadge = document.getElementById('verification-rejected-badge');
                const rejHint = document.getElementById('verification-rejected-hint');
                if (rejBadge) rejBadge.classList.remove('hidden');
                if (rejHint) rejHint.classList.remove('hidden');
                btn.classList.add('hidden');
                btn.disabled = true;
                return;
            }

            badge.classList.add('hidden');
            btn.classList.remove('hidden');

            if (data.can_request_manual === true) {
                btn.disabled = false;
                btn.classList.remove('opacity-60', 'cursor-not-allowed');
                btn.classList.add('dashboard-button--save');
            } else {
                btn.disabled = true;
                btn.classList.add('opacity-60', 'cursor-not-allowed');
                btn.classList.remove('dashboard-button--save');
            }
        } catch (e) {
            widget.classList.add('hidden', 'd-none');
        }
    }

    renderAvatarGrid() {
        const grid = document.getElementById('avatarGrid');
        if (!grid) return;

        const avatars = Array.isArray(this.state.data.avatars_list) ? this.state.data.avatars_list : [];
        const currentAvatarId = this.state.data.user?.avatar_id;

        grid.innerHTML = avatars.map((av) => {
            const isSelected = String(currentAvatarId) === String(av.id);
            const borderClass = isSelected ? 'border-zinc-200' : 'border-zinc-800';
            const src = DashboardController.avatarSrc(av);

            return `
                <div data-avatar-id="${DashboardController.escapeHtml(av.id)}" class="w-16 h-16 rounded-full cursor-pointer overflow-hidden relative bg-zinc-900 border ${borderClass} hover:border-zinc-500 transition-colors">
                    <img src="${DashboardController.escapeHtml(src)}" data-avatar-img class="w-full h-full object-cover" alt="${DashboardController.escapeHtml(av.name || 'Avatar')}" />
                    ${isSelected ? '<div class="absolute inset-0 ring-2 ring-zinc-200 ring-offset-2 ring-offset-zinc-950 rounded-full"></div>' : ''}
                </div>
            `;
        }).join('');
    }

    applyAvatar(avatarId) {
        const img = document.getElementById('currentAvatarImg');
        const icon = document.getElementById('defaultAvatarIcon');
        if (!img || !icon) return;

        const avatars = Array.isArray(this.state.data.avatars_list) ? this.state.data.avatars_list : [];
        const av = avatars.find(a => String(a.id) === String(avatarId));
        const user = this.state.data.user || {};
        const isCurrentUser = String(avatarId) === String(user.avatar_id);
        const src = av
            ? DashboardController.avatarSrc(av)
            : (isCurrentUser && typeof user.avatar_url === 'string' && user.avatar_url.trim() ? user.avatar_url.trim() : '');

        if (src) {
            img.src = src;
            img.classList.remove('hidden');
            icon.classList.add('hidden');
        } else {
            img.classList.add('hidden');
            icon.classList.remove('hidden');
        }
    }

    applyVerificationProgress() {
        const u = this.state.data.user || {};
        const badges = document.getElementById('badgesContainer');

        if (!badges) return;

        if (Number(u.is_verified) === 1 || u.is_verified === true) {
            badges.innerHTML = '<span class="px-3 py-1 rounded-full text-xs font-bold tracking-wider uppercase bg-emerald-500 text-emerald-950 border border-emerald-400 flex items-center"><i class="fas fa-check-circle mr-2" aria-hidden="true"></i> Verificado</span>';
            return;
        }
        badges.innerHTML = '<span class="px-3 py-1 rounded-full text-xs font-bold tracking-wider uppercase bg-zinc-800 text-zinc-200 border border-zinc-700">No verificado</span>';
    }

    applyInputRules() {
        const u = this.state.data.user || {};

        const map = {
            game_username: {
                inputId: 'inputGameName',
                btnId: 'btnSaveNick',
                msgId: 'msgNick',
                value: u.game_username,
                rules: u.edit_rules?.game_username
            },
            whatsapp: {
                inputId: 'inputWhatsapp',
                btnId: 'btnSaveWa',
                msgId: 'msgWa',
                value: u.whatsapp_phone_e164,
                rules: u.edit_rules?.whatsapp
            },
            public_name: {
                inputId: 'inputPublicName',
                btnId: 'btnSavePublic',
                msgId: 'msgPublic',
                value: u.public_display_name,
                rules: u.edit_rules?.public_name
            },
            country_iso2: {
                inputId: 'inputCountry',
                btnId: 'btnSaveCountry',
                msgId: 'msgCountry',
                value: u.country_iso2,
                rules: u.edit_rules?.country_iso2
            }
        };

        Object.keys(map).forEach((field) => {
            const cfg = map[field];

            const input = document.getElementById(cfg.inputId);
            const btn = document.getElementById(cfg.btnId);
            const msg = document.getElementById(cfg.msgId);
            if (!input || !btn || !msg) return;

            if (field === 'country_iso2') {
                const normalized = DashboardController.normalizeCountryIso2(cfg.value);
                input.dataset.value = normalized;
                const labelEl = input.querySelector('[data-role="country-label"]');
                if (labelEl) {
                    DashboardController.renderCountryLabelEl(labelEl, normalized, { placeholder: 'Selecciona tu país' });
                }
            } else {
                input.value = cfg.value || '';
            }

            const rules = cfg.rules || { can_change: false, next: '' };

            if (rules.can_change) {
                input.disabled = false;
                btn.classList.remove('hidden');
                msg.innerHTML = '<span class="text-zinc-400"><i class="fas fa-check mr-1" aria-hidden="true"></i> Disponible para editar</span>';
            } else {
                input.disabled = true;
                btn.classList.add('hidden');
                msg.innerHTML = `<span class="text-zinc-500"><i class="fas fa-clock mr-1" aria-hidden="true"></i> Cambio disponible: ${DashboardController.escapeHtml(rules.next || '')}</span>`;
            }
        });
    }

    bindEvents() {
        this.bindSidebarNavigation();
        this.bindAvatarEvents();
        this.bindSaveButtons();
        this.bindCountryDropdown();
        this.bindOrdersTableEvents();
        this.bindOrderDetailsModalEvents();
        this.bindSmartVerificationWidgetEvents();
        this.bindLogoutConfirmEvents();
        this.bindNotificationsEvents();
    }

    bindNotificationsEvents() {
        // El campanario y el panel son estáticos (no se re-renderizan), así que
        // enlazamos los listeners una sola vez para evitar duplicados entre renders.
        if (this._notifEventsBound) return;
        this._notifEventsBound = true;

        const bell = this.el.notifBell;
        const panel = this.el.notifPanel;
        if (!bell || !panel) return;

        bell.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (panel.classList.contains('hidden')) {
                this.openNotifications();
            } else {
                this.closeNotifications();
            }
        });

        const closeBtn = panel.querySelector('[data-action="close-notifs"]');
        if (closeBtn) {
            closeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.closeNotifications();
            });
        }

        // Clic fuera del panel y de la campana -> cerrar.
        document.addEventListener('click', (e) => {
            if (panel.classList.contains('hidden')) return;
            if (panel.contains(e.target) || bell.contains(e.target)) return;
            this.closeNotifications();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !panel.classList.contains('hidden')) this.closeNotifications();
        });
    }

    openNotifications() {
        const panel = this.el.notifPanel;
        const bell = this.el.notifBell;
        if (!panel) return;
        panel.classList.remove('hidden');
        if (bell) bell.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(() => {
            panel.classList.remove('opacity-0', '-translate-y-2');
        });
        // Al abrir, marcamos como leídas (el backend es la fuente de la verdad)
        // y limpiamos el contador para que deje de quedar "pegajoso".
        this.markNotificationsRead();
    }

    closeNotifications() {
        const panel = this.el.notifPanel;
        const bell = this.el.notifBell;
        if (!panel) return;
        panel.classList.add('opacity-0', '-translate-y-2');
        if (bell) bell.setAttribute('aria-expanded', 'false');
        window.setTimeout(() => panel.classList.add('hidden'), 200);
    }

    async markNotificationsRead() {
        const notifs = Array.isArray(this.state.data.notifications) ? this.state.data.notifications : [];
        const hasUnread = notifs.some((n) => Number(n.is_read) === 0);
        if (!hasUnread) return;

        // Optimista: limpiamos el contador y el estado local de inmediato.
        notifs.forEach((n) => { n.is_read = 1; });
        if (this.el.notifBadge) this.el.notifBadge.classList.add('hidden');

        try {
            const res = await fetch('/web.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({ action: this.apiEndpoint, sub: 'n6' })
            });
            if (res.status === 401) {
                redirectToLogin();
                return;
            }
        } catch (_) {
            // Silencioso: si falla la red, el contador se recalcula en el próximo dashboard.
        }
    }

    bindLogoutConfirmEvents() {
        const openBtn = document.querySelector('[data-action="open-logout-confirm"]');
        if (openBtn) {
            openBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.openLogoutConfirmModal();
            }, { passive: false });
        }

        const modal = document.getElementById('logoutConfirmModal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) this.closeLogoutConfirmModal();
            });
        }

        const cancelBtn = document.querySelector('[data-action="cancel-logout"]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.closeLogoutConfirmModal();
            }, { passive: false });
        }

        if (!this._logoutKeydownBound) {
            this._logoutKeydownBound = true;
            document.addEventListener('keydown', (e) => {
                const m = document.getElementById('logoutConfirmModal');
                if (e.key === 'Escape' && m && !m.classList.contains('hidden')) this.closeLogoutConfirmModal();
            });
        }
    }

    openLogoutConfirmModal() {
        const modal = document.getElementById('logoutConfirmModal');
        const content = document.getElementById('logoutConfirmModalContent');
        if (!modal || !content) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        window.setTimeout(() => {
            modal.classList.remove('opacity-0');
            content.classList.remove('scale-95');
        }, 10);
    }

    closeLogoutConfirmModal() {
        const modal = document.getElementById('logoutConfirmModal');
        const content = document.getElementById('logoutConfirmModalContent');
        if (!modal || !content) return;
        modal.classList.add('opacity-0');
        content.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    bindSmartVerificationWidgetEvents() {
        const btn = document.getElementById('verification-request-btn');
        if (btn) {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                if (btn.disabled === true) return;
                this.openVerificationRequestModal();
            }, { passive: false });
        }

        const modal = document.getElementById('verificationRequestModal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) this.closeVerificationRequestModal();
            });
        }

        const closeBtn = document.querySelector('[data-action="close-verification-request"]');
        if (closeBtn) {
            closeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.closeVerificationRequestModal();
            }, { passive: false });
        }

        const cancelBtn = document.querySelector('[data-action="cancel-verification-request"]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.closeVerificationRequestModal();
            }, { passive: false });
        }

        const confirmBtn = document.getElementById('btnConfirmVerificationRequest');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.submitManualVerificationRequest();
            }, { passive: false });
        }
    }

    openVerificationRequestModal() {
        const modal = document.getElementById('verificationRequestModal');
        const content = document.getElementById('verificationRequestModalContent');
        if (!modal || !content) return;

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        window.setTimeout(() => {
            modal.classList.remove('opacity-0');
            content.classList.remove('scale-95');
        }, 10);
    }

    closeVerificationRequestModal() {
        const modal = document.getElementById('verificationRequestModal');
        const content = document.getElementById('verificationRequestModalContent');
        if (!modal || !content) return;

        modal.classList.add('opacity-0');
        content.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    async submitManualVerificationRequest() {
        const confirmBtn = document.getElementById('btnConfirmVerificationRequest');
        if (!(confirmBtn instanceof HTMLButtonElement)) return;
        if (confirmBtn.disabled === true) return;

        const originalText = confirmBtn.textContent;
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Enviando...';

        try {
            const res = await fetch('/web.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: 'action=request_verification'
            });

            if (res.status === 401) {
                redirectToLogin();
                return;
            }

            const data = await _resolveSyncFrame(await res.json());
            if (!data || data.success !== true) {
                alert((data && data.message) ? data.message : 'Error');
                return;
            }

            this.closeVerificationRequestModal();
            showDashboardToast('Solicitud enviada correctamente. Revisaremos tu historial pronto.', 'success');
            await this.refreshSmartVerificationWidget();
        } catch (e) {
            alert('Error de conexión');
        } finally {
            confirmBtn.disabled = false;
            confirmBtn.textContent = originalText || 'Confirmar y Enviar';
        }
    }

    bindCountryDropdown() {
        if (this._countryDocListener) {
            document.removeEventListener('click', this._countryDocListener, true);
            this._countryDocListener = null;
        }
        this._countryOpen = false;

        const trigger = document.getElementById('inputCountry');
        const popover = document.getElementById('countryOptions');
        const list = document.getElementById('countryOptionsList');
        const searchInput = document.getElementById('countrySearch');
        const clearBtn = document.getElementById('countrySearchClear');
        const empty = document.getElementById('countryOptionsEmpty');
        if (!trigger || !popover || !list || !(searchInput instanceof HTMLInputElement) || !empty) return;

        const wrap = trigger.parentElement;
        if (!(wrap instanceof HTMLElement)) return;

        let activeOpt = null;
        let allOpts = [];

        let closeTimer = null;
        let closeSeq = 0;
        let closeTransitionHandler = null;

        const cancelPendingClose = () => {
            closeSeq++;
            if (closeTimer) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }
            if (closeTransitionHandler) {
                popover.removeEventListener('transitionend', closeTransitionHandler);
                closeTransitionHandler = null;
            }
            popover.classList.remove('is-closing');
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

            empty.classList.toggle('hidden', visible !== 0);
            if (clearBtn) {
                clearBtn.classList.toggle('hidden', String(searchInput.value || '').length === 0);
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
            const iso2 = DashboardController.normalizeCountryIso2(opt.getAttribute('data-iso2'));
            if (!iso2) return;

            trigger.dataset.value = iso2;
            const labelEl = trigger.querySelector('[data-role="country-label"]');
            if (labelEl) {
                DashboardController.renderCountryLabelEl(labelEl, iso2, { placeholder: 'Selecciona tu país' });
            }
            for (const node of allOpts) {
                node.setAttribute('aria-selected', node === opt ? 'true' : 'false');
            }
            close();
            try { trigger.focus(); } catch (err) {
            }
        };

        const buildOptionsOnce = () => {
            if (list.dataset.built === 'true') return;
            list.dataset.built = 'true';

            list.innerHTML = '';
            list.appendChild(empty);
            DASH_COUNTRIES.forEach((c) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dashboard-country-option';
                btn.dataset.iso2 = c.iso2;
                btn.setAttribute('role', 'option');
                btn.id = `countryOptionsList-${c.iso2}`;

                const img = DashboardController.createCountryFlagImg(c.iso2, { lazy: true });
                const text = document.createElement('span');
                text.className = 'dashboard-country-option-text';
                text.textContent = c.name;
                btn.appendChild(img);
                btn.appendChild(text);

                const dial = String(c.dial || '');
                btn.dataset.search = foldSearchText(`${c.name} ${c.iso2} ${dial} ${dial.replace(/^\+/, '')}`);

                btn.addEventListener('pointerdown', (e) => {
                    if (!this._countryOpen) return;
                    e.preventDefault();
                    e.stopPropagation();
                    if (btn.classList.contains('is-hidden')) return;
                    selectOpt(btn);
                });

                btn.addEventListener('click', (e) => {
                    if (!this._countryOpen) return;
                    e.preventDefault();
                    e.stopPropagation();
                    if (btn.classList.contains('is-hidden')) return;
                    selectOpt(btn);
                });
                list.appendChild(btn);
            });

            allOpts = Array.from(list.querySelectorAll('[data-iso2]'));
        };

        const hidePopover = (seq) => {
            if (seq !== closeSeq) return;
            popover.classList.add('hidden');
            popover.classList.remove('is-closing');
        };

        const close = () => {
            if (!this._countryOpen && popover.classList.contains('hidden')) return;
            this._countryOpen = false;
            cancelPendingClose();

            popover.classList.remove('is-open');
            popover.classList.add('is-closing');
            trigger.setAttribute('aria-expanded', 'false');
            searchInput.setAttribute('aria-expanded', 'false');
            searchInput.value = '';
            if (clearBtn) clearBtn.classList.add('hidden');
            setActiveOpt(null);
            if (this._countryDocListener) {
                document.removeEventListener('click', this._countryDocListener, true);
                this._countryDocListener = null;
            }

            const seq = closeSeq;
            closeTransitionHandler = (e) => {
                if (seq !== closeSeq) return;
                if (e.target !== popover) return;
                if (e.propertyName !== 'opacity') return;
                if (closeTransitionHandler) {
                    popover.removeEventListener('transitionend', closeTransitionHandler);
                    closeTransitionHandler = null;
                }
                hidePopover(seq);
            };

            popover.addEventListener('transitionend', closeTransitionHandler);
            closeTimer = window.setTimeout(() => {
                if (closeTransitionHandler) {
                    popover.removeEventListener('transitionend', closeTransitionHandler);
                    closeTransitionHandler = null;
                }
                hidePopover(seq);
            }, 180);
        };

        const open = () => {
            if (this._countryOpen) return;
            if (trigger.disabled === true) return;
            cancelPendingClose();
            buildOptionsOnce();
            this._countryOpen = true;
            popover.classList.remove('hidden');
            popover.classList.remove('is-closing');
            trigger.setAttribute('aria-expanded', 'true');
            searchInput.setAttribute('aria-expanded', 'true');

            searchInput.value = '';
            applyFilter('');

            const selected = DashboardController.normalizeCountryIso2(trigger.dataset.value);
            allOpts.forEach((node) => {
                const iso2 = DashboardController.normalizeCountryIso2(node.getAttribute('data-iso2'));
                node.setAttribute('aria-selected', iso2 && iso2 === selected ? 'true' : 'false');
            });

            const selectedNode = selected ? list.querySelector(`[data-iso2="${selected}"]`) : null;
            if (selectedNode instanceof HTMLElement && !selectedNode.classList.contains('is-hidden')) {
                setActiveOpt(selectedNode);
            }

            window.requestAnimationFrame(() => {
                popover.classList.add('is-open');
            });

            window.requestAnimationFrame(() => {
                try { searchInput.focus(); } catch (e) {
                }
            });

            this._countryDocListener = (e) => {
                const t = e.target;
                if (!(t instanceof Node)) return;
                if (wrap.contains(t)) return;
                close();
            };

            window.setTimeout(() => {
                if (!this._countryDocListener) return;
                document.addEventListener('click', this._countryDocListener, true);
            }, 0);
        };

        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            if (this._countryOpen) close();
            else open();
        });

        trigger.addEventListener('keydown', (e) => {
            if (trigger.disabled === true) return;
            if (e.key === 'Escape') {
                if (!this._countryOpen) return;
                e.preventDefault();
                close();
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });

        searchInput.addEventListener('input', () => {
            applyFilter(searchInput.value);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.preventDefault();
                searchInput.value = '';
                applyFilter('');
                try { searchInput.focus(); } catch (err) {
                }
            });
        }

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (!this._countryOpen) return;
                e.preventDefault();
                close();
                try { trigger.focus(); } catch (err) {
                }
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Home' || e.key === 'End') {
                e.preventDefault();
                if (!this._countryOpen) open();
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
                if (!this._countryOpen) return;
                e.preventDefault();
                const visible = getVisibleOpts();
                if (!visible.length) return;
                selectOpt(activeOpt || visible[0]);
            }
        });

        list.addEventListener('keydown', (e) => {
            const active = document.activeElement;
            if (!(active instanceof HTMLElement)) return;

            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                try { trigger.focus(); } catch (err) {
                }
                return;
            }

            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
            e.preventDefault();

            const opts = getVisibleOpts();
            if (!opts.length) return;
            const idx = opts.findIndex((n) => n === active);
            let next = idx;
            if (e.key === 'ArrowDown') next = Math.min(opts.length - 1, (idx < 0 ? 0 : idx + 1));
            if (e.key === 'ArrowUp') next = Math.max(0, (idx < 0 ? 0 : idx - 1));
            if (e.key === 'Home') next = 0;
            if (e.key === 'End') next = opts.length - 1;

            const toFocus = opts[next];
            if (toFocus instanceof HTMLElement) {
                try { toFocus.focus(); } catch (err) {
                }
            }
        });

        list.addEventListener('focusin', (e) => {
            const t = e.target;
            const el = (t instanceof Element) ? t : (t instanceof Node ? t.parentElement : null);
            const opt = el?.closest?.('[data-iso2]');
            if (!(opt instanceof HTMLElement)) return;
            if (opt.classList.contains('is-hidden')) return;
            setActiveOpt(opt);
        });

        list.addEventListener('click', (e) => {
            if (e.defaultPrevented) return;
            const t = e.target;
            const el = (t instanceof Element) ? t : (t instanceof Node ? t.parentElement : null);
            const opt = el?.closest?.('[data-iso2]');
            if (!(opt instanceof HTMLElement)) return;
            if (opt.classList.contains('is-hidden')) return;
            selectOpt(opt);
        });
    }

    bindSidebarNavigation() {
        const nav = document.getElementById('dashboard-sidebar-nav');
        if (!nav) return;

        nav.addEventListener('click', (e) => {
            const a = e.target?.closest('a');
            if (!a) return;

            const target = a.getAttribute('data-nav');
            if (!target) return;

            e.preventDefault();

            const nextUrl = target === 'orders' ? '/cuenta/ordenes' : '/cuenta/ajustes';
            window.history.pushState({}, '', nextUrl);
            this.state.view = this.getViewFromUrl();
            this.renderAll();
        }, { passive: false });
    }

    bindAvatarEvents() {
        const openTriggers = document.querySelectorAll('[data-action="open-avatar"]');
        openTriggers.forEach((el) => {
            el.addEventListener('click', () => this.openAvatarModal());
        });

        const closeTrigger = document.querySelector('[data-action="close-avatar"]');
        if (closeTrigger) {
            closeTrigger.addEventListener('click', () => this.closeAvatarModal());
        }

        const modal = document.getElementById('avatarModal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) this.closeAvatarModal();
            });
        }

        const grid = document.getElementById('avatarGrid');
        if (grid) {
            grid.addEventListener('click', (e) => {
                const item = e.target?.closest('[data-avatar-id]');
                if (!item) return;
                const id = item.getAttribute('data-avatar-id');
                if (!id) return;
                this.selectAvatar(id);
            });
        }
    }

    openAvatarModal() {
        const modal = document.getElementById('avatarModal');
        const content = document.getElementById('avatarModalContent');
        if (!modal || !content) return;

        this.renderAvatarGrid();

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            content.classList.remove('scale-95');
        }, 10);
    }

    closeAvatarModal() {
        const modal = document.getElementById('avatarModal');
        const content = document.getElementById('avatarModalContent');
        if (!modal || !content) return;

        modal.classList.add('opacity-0');
        content.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    bindOrdersTableEvents() {
        // Delegación a nivel del contenedor: cubre tanto la tabla de escritorio
        // (#ordersTableBody) como la lista de una sola línea de móvil (#ordersMobileList).
        const container = this.el.content || document.getElementById('dashboard-content');
        if (!container) return;

        container.addEventListener('click', (e) => {
            const btn = e.target?.closest?.('[data-action="open-order-details"]');
            if (!btn || !container.contains(btn)) return;

            e.preventDefault();
            const orderCode = String(btn.getAttribute('data-order-code') || '').trim();
            if (!orderCode) return;
            this.openOrderDetailsModal(orderCode);
        }, { passive: false });
    }

    bindOrderDetailsModalEvents() {
        const closeBtn = document.querySelector('[data-action="close-order-details"]');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeOrderDetailsModal());
        }

        const modal = document.getElementById('orderDetailsModal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) this.closeOrderDetailsModal();
            });
        }

        this.bindImageLightboxEvents();
    }

    bindImageLightboxEvents() {
        const body = document.getElementById('orderDetailsBody');
        const lightbox = document.getElementById('orderImageLightbox');
        if (!body || !lightbox) return;

        // Clic en la miniatura (con degradado) -> abrir imagen a pantalla completa.
        body.addEventListener('click', (e) => {
            const thumb = e.target?.closest?.('.order-product-row .fortnite-gradient');
            if (!thumb || !body.contains(thumb)) return;
            const wrap = thumb.closest('[data-lightbox]');
            if (!wrap) return;
            let data = {};
            try { data = JSON.parse(wrap.getAttribute('data-lightbox') || '{}'); } catch (_) { data = {}; }
            if (!data.img) return;
            this.openImageLightbox(data);
        });

        const closeBtn = lightbox.querySelector('[data-action="close-image-lightbox"]');
        if (closeBtn) closeBtn.addEventListener('click', () => this.closeImageLightbox());
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) this.closeImageLightbox();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !lightbox.classList.contains('hidden')) this.closeImageLightbox();
        });
    }

    openImageLightbox(data) {
        const lightbox = document.getElementById('orderImageLightbox');
        const stage = document.getElementById('orderImageLightboxStage');
        const img = document.getElementById('orderImageLightboxImg');
        const name = document.getElementById('orderImageLightboxName');
        if (!lightbox || !stage || !img) return;

        // Mismo degradado dinámico que la miniatura, leyendo los colores del backend.
        stage.setAttribute('style', buildFortniteGradientVarsFromDb(data.c1, data.c2, data.c3));
        img.src = data.img || '';
        img.alt = data.name || '';
        if (name) name.textContent = data.name || '';

        lightbox.classList.remove('hidden');
        lightbox.classList.add('flex');
        requestAnimationFrame(() => lightbox.classList.add('is-open'));
    }

    closeImageLightbox() {
        const lightbox = document.getElementById('orderImageLightbox');
        if (!lightbox) return;
        lightbox.classList.remove('is-open');
        setTimeout(() => {
            lightbox.classList.add('hidden');
            lightbox.classList.remove('flex');
            const img = document.getElementById('orderImageLightboxImg');
            if (img) img.src = '';
        }, 250);
    }

    async openOrderDetailsModal(orderCode) {
        const modal = document.getElementById('orderDetailsModal');
        const content = document.getElementById('orderDetailsModalContent');
        const body = document.getElementById('orderDetailsBody');
        if (!modal || !content || !body) return;

        body.innerHTML = `
            <div class="flex items-center justify-center py-10 text-zinc-400">
                <i class="fas fa-circle-notch fa-spin text-lg mr-3" aria-hidden="true"></i>
                <span class="text-sm">Cargando detalles...</span>
            </div>
        `;

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            content.classList.remove('scale-95');
        }, 10);

        try {
            const res = await fetch('/web.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({ action: this.apiEndpoint, sub: 'o3', order_code: String(orderCode) })
            });

            if (res.status === 401) {
                redirectToLogin();
                return;
            }

            const data = await _resolveSyncFrame(await res.json());
            if (!data || data.status !== 'success') {
                throw new Error(data?.message || 'API error');
            }

            const order = data.order || {};
            const recipients = Array.isArray(data.recipients) ? data.recipients : [];
            const products = Array.isArray(data.products) ? data.products : [];

            const rawStatus = String(order.estado || '');
            const statusClass = DashboardController.getOrderStatusClass(rawStatus);
            const statusText = DashboardController.escapeHtml(rawStatus.replace('_', ' '));
            const parsedOrderDate = parseServerDateUTC(order.fecha_creacion);
            const date = parsedOrderDate ? parsedOrderDate.toLocaleString() : '';

            const orderedRecipients = [];
            const productsByRecipient = new Map();
            products.forEach((p) => {
                const recipient = (p && p.nickname_destinatario)
                    ? String(p.nickname_destinatario)
                    : String(order.nickname_fortnite_cliente || '');
                const key = recipient || '—';
                if (!productsByRecipient.has(key)) {
                    productsByRecipient.set(key, []);
                    orderedRecipients.push(key);
                }
                productsByRecipient.get(key).push(p);
            });

            const productsHtml = (products.length && orderedRecipients.length)
                ? `<div class="space-y-6">${orderedRecipients.map((recipient) => {
                    const list = productsByRecipient.get(recipient) || [];
                    const rows = list.map((p) => {
                        // Datos mínimos para el lightbox de la miniatura (imagen + degradado).
                        const lb = DashboardController.escapeHtml(JSON.stringify({
                            img: p?.imagen_producto_url || '',
                            name: p?.nombre_producto || '',
                            c1: p?.color_1 || '', c2: p?.color_2 || '', c3: p?.color_3 || ''
                        }));
                        return `<div class="account-order-row-wrap" data-lightbox="${lb}">${renderOrderProductRow(p)}</div>`;
                    }).join('');
                    return `
                        <div>
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div class="text-xs font-bold tracking-[0.22em] uppercase text-zinc-500">Para</div>
                                <span class="px-3 py-1 rounded-full text-xs font-bold tracking-wider uppercase bg-zinc-900 text-zinc-200 border border-zinc-800">${DashboardController.escapeHtml(recipient)}</span>
                            </div>
                            <div class="space-y-2">${rows}</div>
                        </div>
                    `;
                }).join('')}</div>`
                : `<p class="text-sm text-zinc-500">—</p>`;

            const subtotalFromOrder = Number(order.subtotal);
            const computedSubtotal = products.reduce((acc, p) => {
                const qty = Number(p?.cantidad || 0) || 0;
                const price = Number(p?.precio_final || 0) || 0;
                return acc + (qty * price);
            }, 0);
            const subtotal = Number.isFinite(subtotalFromOrder) ? subtotalFromOrder : computedSubtotal;
            const discount = Number(order.monto_descuento || 0) || 0;
            const coupon = String(order.cupon_usado_codigo || '').trim();
            const coinsRedeemed = Number(order.coins_redeemed || 0) || 0;
            const coinsMoney = Number(order.coins_value_money || 0) || 0;

            // Recompensa completa "+X Bananos ganados": solo si está completado y generó cashback.
            const cashbackEarned = Number(order.cashback_earned || 0) || 0;
            const cashbackCell = (rawStatus === 'completado' && cashbackEarned > 0)
                ? `
                            <div>
                                <div class="text-xs font-bold tracking-[0.18em] uppercase text-zinc-500">Recompensa</div>
                                <div class="mt-1 inline-flex items-center gap-1.5 text-sm font-bold text-amber-400">
                                    <img src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1768631193/bananos_cfqagf.webp" alt="" aria-hidden="true" class="h-5 w-auto shrink-0" />
                                    +${DashboardController.escapeHtml(cashbackEarned.toLocaleString('es-ES', { maximumFractionDigits: 2 }))} Bananos ganados
                                </div>
                            </div>`
                : '';

            body.innerHTML = `
                <div class="space-y-6">
                    <div class="dashboard-card rounded-xl p-4 md:p-5 border border-zinc-800 bg-zinc-950">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <div class="text-xs font-bold tracking-[0.18em] uppercase text-zinc-500">Código</div>
                                <div class="mt-1 text-sm font-bold tracking-wider text-zinc-100">#${DashboardController.escapeHtml(order.codigo_pedido || '')}</div>
                            </div>
                            <div>
                                <div class="text-xs font-bold tracking-[0.18em] uppercase text-zinc-500">Fecha</div>
                                <div class="mt-1 text-sm text-zinc-300">${DashboardController.escapeHtml(date)}</div>
                            </div>
                            <div>
                                <div class="text-xs font-bold tracking-[0.18em] uppercase text-zinc-500">Estado</div>
                                <div class="mt-1">
                                    <span class="status-badge px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider ${statusClass}">${statusText}</span>
                                </div>
                            </div>${cashbackCell}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-bold tracking-[0.22em] uppercase text-zinc-500 mb-3">Productos</div>
                        ${productsHtml}
                    </div>

                    <div class="dashboard-card rounded-xl p-4 md:p-5 border border-zinc-800 bg-zinc-950">
                        <div class="text-xs font-bold tracking-[0.22em] uppercase text-zinc-500 mb-4">Resumen</div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-zinc-400">Subtotal</span><span class="text-zinc-100 font-semibold">S/ ${DashboardController.escapeHtml(DashboardController.formatMoney(subtotal))}</span></div>
                            ${Math.abs(discount) >= 0.009 ? `<div class="flex justify-between"><span class="text-zinc-400">Descuento</span><span class="text-emerald-400 font-semibold">- S/ ${DashboardController.escapeHtml(DashboardController.formatMoney(discount))}</span></div>` : ''}
                            ${coupon ? `<div class="flex justify-between"><span class="text-zinc-400">Cupón</span><span class="text-zinc-100 font-semibold font-mono">${DashboardController.escapeHtml(coupon)}</span></div>` : ''}
                            ${coinsRedeemed > 0 ? `<div class="flex justify-between"><span class="text-zinc-400">Monedas usadas</span><span class="text-amber-300 font-semibold">${DashboardController.escapeHtml(coinsRedeemed.toLocaleString('es-ES'))} (${`- S/ ${DashboardController.escapeHtml(DashboardController.formatMoney(coinsMoney))}`})</span></div>` : ''}
                            <div class="flex justify-between pt-2 mt-2 border-t border-zinc-800"><span class="text-zinc-200 font-bold">Total</span><span class="text-zinc-100 font-bold">S/ ${DashboardController.escapeHtml(DashboardController.formatMoney(order.total_pagar))}</span></div>
                        </div>
                    </div>
                </div>
            `;
        } catch (e) {
            body.innerHTML = `
                <div class="dashboard-card rounded-xl p-6 border border-zinc-800 bg-zinc-950">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-exclamation-triangle text-zinc-400 mt-1" aria-hidden="true"></i>
                        <div>
                            <div class="text-sm font-bold text-zinc-200">No se pudo cargar el detalle</div>
                            <div class="mt-1 text-sm text-zinc-500">Intenta nuevamente en unos segundos.</div>
                        </div>
                    </div>
                </div>
            `;
        }
    }

    closeOrderDetailsModal() {
        const modal = document.getElementById('orderDetailsModal');
        const content = document.getElementById('orderDetailsModalContent');
        if (!modal || !content) return;

        modal.classList.add('opacity-0');
        content.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    async selectAvatar(id) {
        try {
            this.applyAvatar(id);
            this.closeAvatarModal();

            const fd = new FormData();
            fd.append('action', this.apiEndpoint);
            fd.append('sub', 'u4');
            fd.append('field', 'avatar_id');
            fd.append('value', id);
            const res = await fetch('/web.php', {
                method: 'POST',
                credentials: 'include',
                body: fd
            });

            if (res.status === 401) {
                redirectToLogin();
                return;
            }

            const data = await _resolveSyncFrame(await res.json());
            if (!data || data.status !== 'success') {
                throw new Error('API error');
            }

            await this.loadDashboard();
            this.renderAll();
        } catch (e) {
            this.renderFatalError('No se pudo cargar la información');
        }
    }

    bindSaveButtons() {
        const buttons = document.querySelectorAll('[data-save]');
        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const field = btn.getAttribute('data-save');
                if (!field) return;
                this.saveField(field);
            });
        });
    }

    async saveField(fieldName) {
        const inputMap = {
            game_username: 'inputGameName',
            whatsapp: 'inputWhatsapp',
            public_name: 'inputPublicName',
            country_iso2: 'inputCountry'
        };

        const input = document.getElementById(inputMap[fieldName]);
        const btn = document.querySelector(`[data-save="${fieldName}"]`);
        if (!input || !btn) return;

        const value = (() => {
            if (fieldName === 'country_iso2') {
                if (DashboardController.shouldHideCountrySelector(this.state.data.user?.country_iso2)) {
                    return 'pe';
                }
                return String(input.dataset.value || '').trim();
            }
            return String(input.value || '').trim();
        })();

        if (!value) {
            alert('El campo no puede estar vacío');
            return;
        }

        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i>';
        btn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('action', this.apiEndpoint);
            fd.append('sub', 'u4');
            fd.append('field', fieldName);
            fd.append('value', value);

            const res = await fetch('/web.php', {
                method: 'POST',
                credentials: 'include',
                body: fd
            });

            if (res.status === 401) {
                redirectToLogin();
                return;
            }

            const data = await _resolveSyncFrame(await res.json());
            if (data.status !== 'success') {
                alert(data.message || 'Error');
            } else {
                await this.loadDashboard();
                this.renderAll();
            }
        } catch (e) {
            alert('Error de conexión');
        }

        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }

    static escapeHtml(value) {
        const str = String(value ?? '');
        return str.replace(/[&<>"']/g, (ch) => {
            switch (ch) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                default: return ch;
            }
        });
    }

    static formatMoney(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) return '0.00';
        return num.toFixed(2);
    }

    static sanitizeAvatarFilename(filename) {
        const str = String(filename ?? '');
        return str.replace(/[^a-zA-Z0-9._-]/g, '');
    }

    // URL entregada por el backend (AvatarCatalog); el filename sólo es respaldo.
    static avatarSrc(av) {
        const url = typeof av?.url === 'string' ? av.url.trim() : '';
        if (url) return url;
        const safeFilename = DashboardController.sanitizeAvatarFilename(av?.filename);
        return safeFilename ? `/assets/avatars/${safeFilename}` : '';
    }

    static buildFortniteGradientStyleFromDb(color1, color2, color3) {
        return buildFortniteGradientVarsFromDb(color1, color2, color3);
    }

    static getOrderStatusClass(status) {
        switch (String(status || '').toLowerCase()) {
            case 'pendiente':
                return 'status-badge--pending';
            case 'pagado':
                return 'status-badge--paid';
            case 'completado':
                return 'status-badge--completed';
            case 'cancelado':
                return 'status-badge--canceled';
            default:
                return '';
        }
    }

    // Punto de color para el estado en la lista móvil de pedidos.
    static getOrderStatusDotClass(status) {
        switch (String(status || '').toLowerCase()) {
            case 'pendiente':
                return 'bg-amber-400';
            case 'pagado':
                return 'bg-sky-400';
            case 'completado':
                return 'bg-emerald-400';
            case 'cancelado':
                return 'bg-red-400';
            default:
                return 'bg-zinc-500';
        }
    }

    static formatCoins(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) return '0.00';
        const fixed = num.toFixed(2);
        if (fixed.endsWith('.00')) return fixed.slice(0, -3);
        return fixed;
    }

    static normalizeCountryIso2(value) {
        return String(value ?? '').toLowerCase().replace(/[^a-z]/g, '').slice(0, 5);
    }

    static createCountryFlagImg(iso2, { lazy = false } = {}) {
        const code = DashboardController.normalizeCountryIso2(iso2).slice(0, 2);
        const img = document.createElement('img');
        img.className = 'dashboard-country-flag';
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

    static renderCountryLabelEl(labelEl, iso2, { placeholder = '' } = {}) {
        if (!(labelEl instanceof HTMLElement)) return;
        const key = DashboardController.normalizeCountryIso2(iso2);
        const c = key ? DASH_COUNTRIES_BY_ISO2[key] : null;

        while (labelEl.firstChild) labelEl.removeChild(labelEl.firstChild);

        if (!c) {
            labelEl.textContent = String(placeholder || '');
            return;
        }

        const img = DashboardController.createCountryFlagImg(c.iso2, { lazy: false });
        const text = document.createElement('span');
        text.className = 'dashboard-country-label-text';
        text.textContent = c.name;
        labelEl.appendChild(img);
        labelEl.appendChild(text);
    }

    static getCountryLabel(iso2) {
        const key = DashboardController.normalizeCountryIso2(iso2);
        const c = DASH_COUNTRIES_BY_ISO2[key];
        if (!c) return '';
        return `${c.name}`;
    }

    static shouldHideCountrySelector(savedCountryIso2) {
        const saved = DashboardController.normalizeCountryIso2(savedCountryIso2);
        if (saved === 'pe') return true;
        return DETECTED_COUNTRY === 'pe';
    }
}

const controller = new DashboardController();
controller.init();
