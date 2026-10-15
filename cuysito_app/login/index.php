<?php
require_once __DIR__ . '/../api/session_security.php';

$redirect_to = null;
if (isset($_GET['redirect_to'])) {
    $redirect_to = banano_sanitize_internal_return_url($_GET['redirect_to']);
} elseif (!isset($_SESSION['return_url']) && isset($_SERVER['HTTP_REFERER'])) {
    $redirect_to = banano_sanitize_internal_return_url($_SERVER['HTTP_REFERER']);
}

if ($redirect_to) {
    $_SESSION['return_url'] = $redirect_to;
}

$google_login_href = 'google_login.php';
if (isset($_SESSION['return_url'])) {
    $google_login_href .= '?redirect_to=' . urlencode($_SESSION['return_url']);
}

// Si ya está logueado, lo mandamos directo al panel
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in_client']) && $_SESSION['logged_in_client'] === true) {
    header('Location: ' . banano_consume_return_url('/cuenta/'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Banano Store</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn2.unrealengine.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Barlow', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        app: {
                            bg: '#0b0f14',
                            surface: '#111827',
                            surface2: '#0f172a',
                            border: '#1f2937',
                            text: '#e5e7eb',
                            muted: '#94a3b8',
                        },
                        accent: {
                            DEFAULT: '#f59e0b',
                            hover: '#d97706',
                        },
                    },
                    boxShadow: {
                        card: '0 18px 40px rgba(0, 0, 0, 0.55)',
                    },
                    keyframes: {
                        fadeUp: {
                            '0%': { opacity: '0', transform: 'translateY(12px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                    },
                    animation: {
                        'fade-up': 'fadeUp 520ms cubic-bezier(0.21, 1.02, 0.73, 1) both',
                        'fade-up-1': 'fadeUp 520ms cubic-bezier(0.21, 1.02, 0.73, 1) 120ms both',
                        'fade-up-2': 'fadeUp 520ms cubic-bezier(0.21, 1.02, 0.73, 1) 240ms both',
                        'fade-up-3': 'fadeUp 520ms cubic-bezier(0.21, 1.02, 0.73, 1) 360ms both',
                    },
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-app-bg bg-cover bg-center bg-no-repeat text-app-text font-sans">

    <div id="smart-bg-root" class="relative min-h-screen">
        <main class="relative z-10 min-h-screen grid place-items-center p-6 sm:p-8">
            <section class="w-full max-w-md">
                <div class="rounded-2xl bg-app-surface p-8 sm:p-10 border border-app-border shadow-card">
                    <header class="animate-fade-up">
                        <div class="flex items-center justify-center">
                            <div class="h-12 w-12 rounded-xl bg-app-surface2 border border-app-border flex items-center justify-center">
                                <span class="text-lg font-semibold">B</span>
                            </div>
                        </div>

                        <div class="mt-5 text-center">
                            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">Inicia sesión</h1>
                            <p class="mt-2 text-sm text-app-muted leading-relaxed">
                                Accede a tus pedidos, monedas y beneficios exclusivos.
                            </p>
                        </div>
                    </header>

                    <section class="mt-8 animate-fade-up-1" aria-label="Opciones de inicio de sesión">
                        <a href="<?php echo htmlspecialchars($google_login_href, ENT_QUOTES, 'UTF-8'); ?>"
                            class="w-full inline-flex items-center justify-center gap-3 rounded-xl bg-white px-4 py-3 border border-slate-200 text-slate-900 font-semibold shadow-sm transition-transform duration-150 hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent/25 focus-visible:ring-offset-2 focus-visible:ring-offset-app-surface">
                            <svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                                <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z" />
                                <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z" />
                                <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z" />
                                <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z" />
                            </svg>
                            <span>Continuar con Google</span>
                        </a>

                        <p class="mt-4 text-center text-xs text-app-muted">
                            Usamos tu cuenta de Google solo para autenticación.
                        </p>
                    </section>

                    <footer class="mt-8 pt-6 border-t border-app-border animate-fade-up-2">
                        <p class="text-center text-xs text-app-muted">Al continuar, aceptas nuestros</p>
                        <nav class="mt-3 flex items-center justify-center gap-3 text-xs font-semibold" aria-label="Políticas">
                            <a href="/terminos"
                                class="rounded-sm text-app-text/90 hover:text-app-text hover:underline underline-offset-4 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent/25 focus-visible:ring-offset-2 focus-visible:ring-offset-app-surface">Términos</a>
                            <span class="text-app-muted" aria-hidden="true">·</span>
                            <a href="/privacidad"
                                class="rounded-sm text-app-text/90 hover:text-app-text hover:underline underline-offset-4 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent/25 focus-visible:ring-offset-2 focus-visible:ring-offset-app-surface">Privacidad</a>
                        </nav>
                    </footer>
                </div>

                <p class="mt-6 text-center text-xs text-app-muted animate-fade-up-3">
                    <a href="/" class="font-semibold text-app-text/90 hover:text-app-text hover:underline underline-offset-4 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent/25 focus-visible:ring-offset-2 focus-visible:ring-offset-app-bg rounded-sm">Volver a la tienda</a>
                </p>
            </section>
        </main>
    </div>

    <script>
        (() => {
            const BASE_URL = 'https://cdn2.unrealengine.com/sk-Billboard_VictoryVibes_SectionBG-edae1985.png';
            const MAX_RESOLUTION = 2560;
            const RESIZE_DELAY_MS = 240;

            const debounce = (fn, delayMs) => {
                let t = null;
                const debounced = (...args) => {
                    if (t) window.clearTimeout(t);
                    t = window.setTimeout(() => fn(...args), delayMs);
                };
                debounced.cancel = () => {
                    if (!t) return;
                    window.clearTimeout(t);
                    t = null;
                };
                return debounced;
            };

            class SmartDynamicBackground {
                constructor({ container, targetEl }) {
                    this.container = container;
                    this.targetEl = targetEl;
                    this.appliedW = 0;
                    this.appliedH = 0;
                    this.requestedW = 0;
                    this.requestedH = 0;
                    this.latestJobId = 0;
                    this.observer = null;
                    this.windowResizeHandler = null;
                    this.fetchController = null;
                    this.activeBlobUrl = null;

                    this.scheduleUpdate = debounce(() => void this.update(), RESIZE_DELAY_MS);
                }

                init() {
                    void this.update();
                    if (typeof ResizeObserver === 'function') {
                        this.observer = new ResizeObserver(() => this.scheduleUpdate());
                        this.observer.observe(this.container);
                        return;
                    }

                    this.windowResizeHandler = () => this.scheduleUpdate();
                    window.addEventListener('resize', this.windowResizeHandler, { passive: true });
                }

                /**
                 * Destroys the instance, revoking the last applied Blob URL to prevent memory leaks.
                 */
                destroy() {
                    if (this.observer) this.observer.disconnect();
                    this.observer = null;
                    if (this.windowResizeHandler) {
                        window.removeEventListener('resize', this.windowResizeHandler);
                        this.windowResizeHandler = null;
                    }
                    /**
                     * Important: Blob URLs persist in memory until revoked.
                     * We revoke the last applied Blob URL here to prevent memory leaks.
                     */
                    if (this.fetchController) this.fetchController.abort();
                    this.fetchController = null;
                    if (this.activeBlobUrl) URL.revokeObjectURL(this.activeBlobUrl);
                    this.activeBlobUrl = null;
                    if (this.scheduleUpdate && this.scheduleUpdate.cancel) this.scheduleUpdate.cancel();
                }

                buildUrl(w, h) {
                    const url = new URL(BASE_URL);
                    url.searchParams.set('resize', '1');
                    url.searchParams.set('w', String(w));
                    url.searchParams.set('h', String(h));
                    url.searchParams.set('quality', 'high');
                    return url.toString();
                }

                /**
                 * Fetches the image and applies it as a Blob URL to obfuscate the real CDN URL/params from DOM inspection.
                 * Uses AbortController to cancel stale requests and revokes old Blob URLs to prevent memory leaks.
                 */
                async update() {

                    const clientW = this.container.clientWidth;
                    const clientH = this.container.clientHeight;
                    const dpr = window.devicePixelRatio || 1;

                    if (!clientW || !clientH) return;

                    const rawW = Math.max(1, Math.round(clientW * dpr));
                    const rawH = Math.max(1, Math.round(clientH * dpr));
                    const scale = Math.min(1, MAX_RESOLUTION / rawW, MAX_RESOLUTION / rawH);

                    const targetW = Math.max(1, Math.round(rawW * scale));
                    const targetH = Math.max(1, Math.round(rawH * scale));
                    if (targetW === this.appliedW && targetH === this.appliedH) return;
                    if (this.fetchController && targetW === this.requestedW && targetH === this.requestedH) return;

                    this.requestedW = targetW;
                    this.requestedH = targetH;

                    const nextUrl = this.buildUrl(targetW, targetH);
                    const jobId = ++this.latestJobId;

                    if (this.fetchController) this.fetchController.abort();
                    const controller = new AbortController();
                    this.fetchController = controller;

                    try {
                        const res = await fetch(nextUrl, {
                            method: 'GET',
                            signal: controller.signal,
                            cache: 'force-cache',
                        });
                        if (!res.ok) return;

                        const blob = await res.blob();
                        if (jobId !== this.latestJobId) return;

                        const blobUrl = URL.createObjectURL(blob);
                        const prevUrl = this.activeBlobUrl;
                        this.appliedW = targetW;
                        this.appliedH = targetH;
                        this.activeBlobUrl = blobUrl;
                        this.targetEl.style.backgroundImage = `url("${blobUrl}")`;
                        if (prevUrl) URL.revokeObjectURL(prevUrl);
                    } catch (err) {
                        if (err && err.name === 'AbortError') return;

                        return;
                    } finally {
                        if (this.fetchController === controller) this.fetchController = null;
                    }
                }
            }

            const start = (root) => {
                const target = document.body;
                if (!target) return;

                const instance = new SmartDynamicBackground({ container: root, targetEl: target });
                instance.init();

                window.addEventListener('pagehide', () => instance.destroy(), { once: true });
            };

            const tryStart = () => {
                const root = document.getElementById('smart-bg-root');
                if (!root) return false;
                start(root);
                return true;
            };

            if (!tryStart()) {
                document.addEventListener('DOMContentLoaded', () => tryStart(), { once: true });
            }
        })();
    </script>

</body>
</html>