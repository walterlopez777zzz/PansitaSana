<?php
require_once __DIR__ . '/../api/session_security.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidad - Banano Store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
    tailwind.config = {
        theme: { extend: { fontFamily: { sans: ['Inter','system-ui','sans-serif'] } } }
    }
    </script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{background:#0a0a12;color:#d0d0d0;font-family:'Inter',system-ui,sans-serif;overflow-x:hidden}

        /* ── Animated Background ── */
        .bg-orbs{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
        .orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.12;animation:orb-float 22s ease-in-out infinite}
        .orb-1{width:550px;height:550px;background:#7c3aed;top:-10%;right:-5%;animation-delay:0s}
        .orb-2{width:450px;height:450px;background:#00B2FF;bottom:-10%;left:-5%;animation-delay:-8s}
        .orb-3{width:350px;height:350px;background:#10b981;top:50%;left:40%;animation-delay:-15s}
        @keyframes orb-float{
            0%,100%{transform:translate(0,0) scale(1)}
            33%{transform:translate(50px,-35px) scale(1.08)}
            66%{transform:translate(-25px,45px) scale(.94)}
        }

        /* ── Glass ── */
        .glass{background:rgba(16,16,28,.65);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.06);border-radius:16px}
        .glass-subtle{background:rgba(16,16,28,.4);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.04);border-radius:12px}

        /* ── Shield animation ── */
        .shield-wrap{position:relative;width:88px;height:88px;margin:0 auto}
        .shield-ring{position:absolute;inset:-6px;border-radius:50%;border:2px solid transparent;border-top-color:#00B2FF;animation:shield-spin 3s linear infinite}
        .shield-ring-2{position:absolute;inset:-12px;border-radius:50%;border:2px solid transparent;border-bottom-color:rgba(0,178,255,.3);animation:shield-spin 5s linear infinite reverse}
        @keyframes shield-spin{to{transform:rotate(360deg)}}
        .shield-icon{width:88px;height:88px;border-radius:50%;background:linear-gradient(135deg,rgba(0,178,255,.18),rgba(124,58,237,.12));border:1px solid rgba(0,178,255,.15);display:flex;align-items:center;justify-content:center;font-size:2.2rem;color:#00B2FF}

        /* ── Key-point cards ── */
        .kp-card{transition:transform .3s,box-shadow .3s,border-color .3s}
        .kp-card:hover{transform:translateY(-3px);box-shadow:0 8px 32px rgba(0,178,255,.08);border-color:rgba(0,178,255,.14)}
        .kp-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}

        /* ── Paragraph blocks ── */
        .para-block{position:relative;padding-left:20px;border-left:2px solid rgba(0,178,255,.15)}
        .para-block::before{content:'';position:absolute;left:-5px;top:0;width:8px;height:8px;border-radius:50%;background:#00B2FF;box-shadow:0 0 8px rgba(0,178,255,.5)}

        /* ── Cross-links ── */
        .cross-link{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.05);color:#999;text-decoration:none;transition:all .25s;font-size:.82rem}
        .cross-link:hover{background:rgba(0,178,255,.06);border-color:rgba(0,178,255,.16);color:#00B2FF}

        /* ── Fade-up ── */
        .fade-up{opacity:0;transform:translateY(28px);transition:opacity .55s ease-out,transform .55s ease-out}
        .fade-up.visible{opacity:1;transform:translateY(0)}

        /* ── Stagger delays ── */
        .delay-1{transition-delay:.1s}
        .delay-2{transition-delay:.2s}
        .delay-3{transition-delay:.3s}

        @media print{
            .bg-orbs{display:none!important}
            body{background:#fff;color:#222}
            .glass,.glass-subtle{background:#fff;border:1px solid #ddd;backdrop-filter:none}
        }
    </style>
</head>
<body class="min-h-screen">

    <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>

    <div class="relative z-10 max-w-3xl mx-auto px-4 sm:px-6 py-10 sm:py-16">

        <!-- ════ Breadcrumb ════ -->
        <nav class="flex items-center gap-2 text-xs text-gray-500 mb-10">
            <a href="/" class="hover:text-white transition-colors"><i class="fas fa-store mr-1"></i>Banano Store</a>
            <i class="fas fa-chevron-right text-[.55rem] opacity-40"></i>
            <span class="text-gray-400">Política de Privacidad</span>
        </nav>

        <!-- ════ Hero ════ -->
        <div class="text-center mb-12 fade-up">
            <div class="shield-wrap mb-6">
                <div class="shield-ring"></div>
                <div class="shield-ring-2"></div>
                <div class="shield-icon"><i class="fas fa-shield-halved"></i></div>
            </div>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight mb-2">Política de Privacidad</h1>
            <p class="text-sm text-gray-500">
                <i class="far fa-calendar mr-1"></i>Última actualización: 29 de marzo de 2026
                <span class="mx-2 opacity-30">|</span>
                <i class="far fa-clock mr-1"></i>~1 min de lectura
            </p>
        </div>

        <!-- ════ Key Points at a Glance ════ -->
        <div class="grid sm:grid-cols-3 gap-4 mb-10">
            <div class="glass kp-card p-5 text-center fade-up delay-1">
                <div class="kp-icon mx-auto mb-3 bg-gradient-to-br from-blue-500/15 to-cyan-400/10 border border-blue-500/10 text-blue-400">
                    <i class="fas fa-database"></i>
                </div>
                <p class="text-xs font-semibold text-white mb-1">Datos Mínimos</p>
                <p class="text-[.7rem] text-gray-500 leading-relaxed">Nickname, contacto y comprobantes de pago</p>
            </div>
            <div class="glass kp-card p-5 text-center fade-up delay-2">
                <div class="kp-icon mx-auto mb-3 bg-gradient-to-br from-emerald-500/15 to-green-400/10 border border-emerald-500/10 text-emerald-400">
                    <i class="fas fa-lock"></i>
                </div>
                <p class="text-xs font-semibold text-white mb-1">Uso Exclusivo</p>
                <p class="text-[.7rem] text-gray-500 leading-relaxed">Solo para procesar y entregar tus pedidos</p>
            </div>
            <div class="glass kp-card p-5 text-center fade-up delay-3">
                <div class="kp-icon mx-auto mb-3 bg-gradient-to-br from-violet-500/15 to-purple-400/10 border border-violet-500/10 text-violet-400">
                    <i class="fas fa-handshake"></i>
                </div>
                <p class="text-xs font-semibold text-white mb-1">Tu Consentimiento</p>
                <p class="text-[.7rem] text-gray-500 leading-relaxed">Al usar el sitio, aceptas esta política</p>
            </div>
        </div>

        <!-- ════ Full Policy Text ════ -->
        <div class="glass p-6 sm:p-8 space-y-6 fade-up">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500/15 to-cyan-400/8 border border-blue-500/10 flex items-center justify-center">
                    <i class="fas fa-file-lines text-sm text-blue-400"></i>
                </div>
                <h2 class="text-base font-bold text-white">Texto Completo</h2>
            </div>

            <div class="para-block text-sm leading-[1.8] text-gray-300">
                <p>Banano Store (banano.pe) valora su privacidad y la protección de sus datos. Esta Política de Privacidad explica de manera simplificada que la información que recopilamos (como su Nickname de Epic Games, número de contacto y comprobantes de pago) se utiliza exclusivamente para el procesamiento, validación y entrega de sus pedidos.</p>
            </div>

            <div class="para-block text-sm leading-[1.8] text-gray-300">
                <p>Al visitar banano.pe y utilizar nuestros servicios o realizar una compra, usted acepta las prácticas descritas en esta Política de Privacidad. Su uso del sitio también está sujeto de manera íntegra a nuestros <a href="/terminos/" class="text-blue-400 hover:text-blue-300 underline decoration-blue-400/30 hover:decoration-blue-300/60 transition-colors">Términos y Condiciones</a>.</p>
            </div>

            <div class="para-block text-sm leading-[1.8] text-gray-300">
                <p>En este documento, &ldquo;nosotros&rdquo; o &ldquo;nuestro&rdquo; se refiere a Banano Store, y &ldquo;usted&rdquo;, &ldquo;usuario&rdquo; o &ldquo;cliente&rdquo; se refiere a la persona que utiliza nuestra plataforma.</p>
            </div>
        </div>

        <!-- ════ Navigation ════ -->
        <div class="mt-8 space-y-3 fade-up">
            <div class="grid sm:grid-cols-2 gap-3">
                <a href="/terminos/" class="cross-link glass-subtle group">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500/12 to-cyan-400/6 border border-blue-500/10 flex items-center justify-center group-hover:border-blue-500/25 transition-colors">
                        <i class="fas fa-file-contract text-blue-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-white">Términos y Condiciones</p>
                        <p class="text-[.65rem] text-gray-500">Reglas de uso del servicio</p>
                    </div>
                </a>
                <a href="/" class="cross-link glass-subtle group">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500/12 to-green-400/6 border border-emerald-500/10 flex items-center justify-center group-hover:border-emerald-500/25 transition-colors">
                        <i class="fas fa-store text-emerald-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-white">Volver a la Tienda</p>
                        <p class="text-[.65rem] text-gray-500">Continuar comprando</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- ════ Footer ════ -->
        <footer class="border-t border-white/5 mt-14 pt-8 text-center text-xs text-gray-600">
            <p>&copy; 2026 Banano Store (banano.pe) &mdash; Todos los derechos reservados.</p>
            <div class="mt-2 flex justify-center gap-6">
                <a href="/terminos/" class="hover:text-blue-400 transition-colors">Términos</a>
                <a href="/" class="hover:text-blue-400 transition-colors">Tienda</a>
            </div>
        </footer>

    </div>

    <script>
    (function(){
        const io = new IntersectionObserver(entries=>{
            entries.forEach(e=>{ if(e.isIntersecting){ e.target.classList.add('visible'); io.unobserve(e.target); }});
        },{threshold:.15});
        document.querySelectorAll('.fade-up').forEach(el=>io.observe(el));
    })();
    </script>
</body>
</html>