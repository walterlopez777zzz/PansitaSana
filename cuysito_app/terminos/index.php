<?php
require_once __DIR__ . '/../api/session_security.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Términos y Condiciones - Banano Store</title>
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
        html{scroll-behavior:smooth;scroll-padding-top:100px}
        body{background:#0a0a12;color:#d0d0d0;font-family:'Inter',system-ui,sans-serif;overflow-x:hidden}

        /* ── Progress Bar ── */
        #progress-bar{position:fixed;top:0;left:0;height:3px;background:linear-gradient(90deg,#00B2FF,#00e5ff,#FFD700);z-index:1000;transition:width .1s linear;border-radius:0 2px 2px 0;box-shadow:0 0 12px rgba(0,178,255,.5)}

        /* ── Animated Background ── */
        .bg-orbs{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
        .orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.12;animation:orb-float 22s ease-in-out infinite}
        .orb-1{width:600px;height:600px;background:#00B2FF;top:-8%;left:-8%}
        .orb-2{width:500px;height:500px;background:#FFD700;bottom:-8%;right:-8%;animation-delay:-8s}
        .orb-3{width:400px;height:400px;background:#7c3aed;top:45%;left:55%;animation-delay:-15s}
        @keyframes orb-float{
            0%,100%{transform:translate(0,0) scale(1)}
            33%{transform:translate(60px,-40px) scale(1.08)}
            66%{transform:translate(-30px,50px) scale(.94)}
        }

        /* ── Glass ── */
        .glass{background:rgba(16,16,28,.65);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.06);border-radius:16px}
        .glass-subtle{background:rgba(16,16,28,.4);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.04);border-radius:12px}

        /* ── Section Cards ── */
        .section-card{transition:transform .3s,box-shadow .3s,border-color .3s}
        .section-card:hover{transform:translateY(-2px);box-shadow:0 8px 36px rgba(0,178,255,.07);border-color:rgba(0,178,255,.14)}

        /* ── Fade-up animation ── */
        .fade-up{opacity:0;transform:translateY(28px);transition:opacity .55s ease-out,transform .55s ease-out}
        .fade-up.visible{opacity:1;transform:translateY(0)}

        /* ── TOC ── */
        .toc-link{display:flex;align-items:center;gap:10px;padding:7px 12px;border-radius:8px;color:#777;font-size:.78rem;font-weight:500;text-decoration:none;transition:all .25s;border-left:2px solid transparent}
        .toc-link:hover{color:#bbb;background:rgba(255,255,255,.03)}
        .toc-link.active{color:#00B2FF;background:rgba(0,178,255,.08);border-left-color:#00B2FF}
        .toc-dot{width:6px;height:6px;border-radius:50%;background:#3a3a4a;transition:all .3s;flex-shrink:0}
        .toc-link.active .toc-dot{background:#00B2FF;box-shadow:0 0 8px rgba(0,178,255,.6)}

        /* ── Section Icon ── */
        .section-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;background:linear-gradient(135deg,rgba(0,178,255,.14),rgba(0,178,255,.04));color:#00B2FF;border:1px solid rgba(0,178,255,.1)}

        /* ── Legal Lists ── */
        .legal-list{list-style:none;padding:0}
        .legal-list li{position:relative;padding-left:20px;margin-bottom:10px;line-height:1.75}
        .legal-list li::before{content:'';position:absolute;left:0;top:11px;width:6px;height:6px;border-radius:50%;background:#00B2FF}

        /* ── Sub-items A B C ── */
        .legal-sub{list-style:none;padding:0;counter-reset:sub}
        .legal-sub li{position:relative;padding-left:28px;margin-bottom:10px;line-height:1.75}
        .legal-sub li::before{content:counter(sub,upper-alpha) '.';counter-increment:sub;position:absolute;left:0;color:#FFD700;font-weight:700;font-size:.85rem}

        /* ── Cross-links ── */
        .cross-link{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.05);color:#999;text-decoration:none;transition:all .25s;font-size:.82rem}
        .cross-link:hover{background:rgba(0,178,255,.06);border-color:rgba(0,178,255,.16);color:#00B2FF}

        /* ── Highlight ── */
        .highlight{color:#FFD700;font-weight:700}

        /* ── Back to top ── */
        #back-to-top{position:fixed;bottom:24px;right:24px;width:44px;height:44px;border-radius:12px;background:rgba(0,178,255,.12);backdrop-filter:blur(10px);border:1px solid rgba(0,178,255,.18);color:#00B2FF;font-size:1.1rem;cursor:pointer;opacity:0;transform:translateY(20px);transition:all .3s;z-index:100;display:flex;align-items:center;justify-content:center}
        #back-to-top.show{opacity:1;transform:translateY(0)}
        #back-to-top:hover{background:rgba(0,178,255,.22);transform:translateY(-2px)}

        /* ── Mobile TOC ── */
        .toc-toggle{display:none;position:fixed;bottom:24px;left:24px;width:44px;height:44px;border-radius:12px;background:rgba(0,178,255,.12);backdrop-filter:blur(10px);border:1px solid rgba(0,178,255,.18);color:#00B2FF;font-size:1.1rem;cursor:pointer;z-index:100;align-items:center;justify-content:center;transition:all .3s}
        .toc-toggle:hover{background:rgba(0,178,255,.22)}

        @media(max-width:1023px){
            .toc-toggle{display:flex}
            #toc-sidebar{position:fixed;bottom:80px;left:24px;width:260px;max-height:65vh;overflow-y:auto;z-index:99;opacity:0;transform:translateY(20px) scale(.96);pointer-events:none;transition:all .3s ease}
            #toc-sidebar.open{opacity:1;transform:translateY(0) scale(1);pointer-events:all}
        }

        @media print{
            .bg-orbs,#progress-bar,#back-to-top,.toc-toggle,#toc-sidebar{display:none!important}
            body{background:#fff;color:#222}
            .glass,.glass-subtle{background:#fff;border:1px solid #ddd;backdrop-filter:none}
            .section-icon{background:#f0f0f0;color:#333}
        }
    </style>
</head>
<body class="min-h-screen">

    <div id="progress-bar" style="width:0%"></div>

    <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>

    <div class="relative z-10">

        <!-- ════ Header ════ -->
        <header class="pt-8 pb-4 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
            <nav class="flex items-center gap-2 text-xs text-gray-500 mb-6">
                <a href="/" class="hover:text-white transition-colors"><i class="fas fa-store mr-1"></i>Banano Store</a>
                <i class="fas fa-chevron-right text-[.55rem] opacity-40"></i>
                <span class="text-gray-400">Términos y Condiciones</span>
            </nav>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-400/10 border border-blue-500/15 flex items-center justify-center">
                    <i class="fas fa-file-contract text-xl text-blue-400"></i>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Términos y Condiciones</h1>
                    <p class="text-sm text-gray-500 mt-1">
                        <i class="far fa-clock mr-1"></i>~4 min de lectura
                        <span class="mx-2 opacity-30">|</span>
                        <i class="far fa-calendar mr-1"></i>29 de marzo de 2026
                    </p>
                </div>
            </div>
        </header>

        <!-- ════ Main Grid ════ -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20 lg:grid lg:grid-cols-[230px_1fr] lg:gap-8">

            <!-- ── TOC Sidebar ── -->
            <aside id="toc-sidebar" class="glass p-4 lg:sticky lg:top-6 lg:self-start lg:max-h-[calc(100vh-48px)] lg:overflow-y-auto mb-6 lg:mb-0">
                <p class="text-[.65rem] font-bold text-gray-500 uppercase tracking-widest mb-3 px-3">Contenido</p>
                <nav class="space-y-0.5" id="toc-nav">
                    <a href="#s1" class="toc-link" data-s="s1"><span class="toc-dot"></span>Independencia de Marca</a>
                    <a href="#s2" class="toc-link" data-s="s2"><span class="toc-dot"></span>Marco Legal</a>
                    <a href="#s3" class="toc-link" data-s="s3"><span class="toc-dot"></span>Requisitos de Uso</a>
                    <a href="#s4" class="toc-link" data-s="s4"><span class="toc-dot"></span>Proceso de Entrega</a>
                    <a href="#s5" class="toc-link" data-s="s5"><span class="toc-dot"></span>Pagos y Comprobantes</a>
                    <a href="#s6" class="toc-link" data-s="s6"><span class="toc-dot"></span>Reembolsos</a>
                    <a href="#s7" class="toc-link" data-s="s7"><span class="toc-dot"></span>Precios</a>
                    <a href="#s8" class="toc-link" data-s="s8"><span class="toc-dot"></span>Responsabilidad</a>
                    <a href="#s9" class="toc-link" data-s="s9"><span class="toc-dot"></span>Modificaciones</a>
                </nav>
                <div class="border-t border-white/5 mt-4 pt-4 space-y-2">
                    <a href="/privacidad/" class="cross-link"><i class="fas fa-shield-halved"></i><span>Política de Privacidad</span></a>
                    <a href="/" class="cross-link"><i class="fas fa-store"></i><span>Volver a la Tienda</span></a>
                </div>
            </aside>

            <!-- ── Content ── -->
            <article class="space-y-5 min-w-0">

                <!-- Intro -->
                <div class="glass-subtle p-5 fade-up">
                    <p class="text-sm leading-relaxed text-gray-300">Bienvenido a Banano Store (banano.pe). Al utilizar nuestro sitio web y servicios, aceptas los presentes términos y condiciones. Te recomendamos leerlos con atención, ya que regulan la relación de consumo bajo el marco legal peruano.</p>
                </div>

                <!-- §1 Independencia Total de Marca -->
                <section id="s1" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-fingerprint"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 1</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Independencia Total de Marca</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 space-y-3 text-sm leading-relaxed">
                        <p>Banano Store es una plataforma de intermediación independiente. No tenemos ninguna afiliación, vínculo, patrocinio ni respaldo de Epic Games, Inc., ni de ninguna otra desarrolladora de videojuegos.</p>
                        <ul class="legal-list">
                            <li>No utilizamos recursos protegidos de forma indebida; cualquier referencia a nombres de juegos o ítems se hace exclusivamente con fines descriptivos para el consumidor.</li>
                            <li>Nuestra plataforma no modifica el software de los juegos ni interviene en sus sistemas internos. Operamos dentro de las funciones públicas y autorizadas por las plataformas oficiales.</li>
                        </ul>
                    </div>
                </section>

                <!-- §2 Marco Legal -->
                <section id="s2" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-scale-balanced"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 2</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Marco Legal y Protección al Consumidor</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 text-sm leading-relaxed">
                        <p>En cumplimiento con la Ley N° 29571 (Código de Protección y Defensa del Consumidor), Banano Store ofrece productos y servicios idóneos destinados al entretenimiento. Como proveedores, garantizamos transparencia en la información para que el usuario tome decisiones libres e informadas.</p>
                    </div>
                </section>

                <!-- §3 Requisitos de Uso y Registro -->
                <section id="s3" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-user-check"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 3</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Requisitos de Uso y Registro</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 text-sm leading-relaxed">
                        <ul class="legal-list">
                            <li>No es obligatorio registrarse; puedes comprar como invitado proporcionando tu Nickname de Epic Games y un número de WhatsApp para el pedido.</li>
                            <li>El usuario declara ser mayor de edad o contar con permiso de sus tutores. Es responsable de la exactitud de los datos proporcionados.</li>
                            <li><strong class="text-white">Conducta:</strong> Queda prohibido el uso de nombres públicos ofensivos, con insultos o que promocionen spam. Banano Store se reserva el derecho de bloquear el acceso a cualquier usuario que infrinja esta norma de convivencia.</li>
                        </ul>
                    </div>
                </section>

                <!-- §4 Proceso de Entrega -->
                <section id="s4" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-truck-fast"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 4</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Proceso de Entrega</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 text-sm leading-relaxed">
                        <ul class="legal-list">
                            <li>El usuario debe agregar a nuestras cuentas de envío como amigos dentro del juego.</li>
                            <li>Debido a las políticas de las plataformas de juegos, existe un tiempo mínimo de amistad requerido para poder recibir regalos.</li>
                            <li>El objetivo es completar los pedidos con la mayor rapidez, pero los tiempos pueden variar por limitaciones de la plataforma, alto volumen de pedidos o problemas técnicos ajenos a nosotros.</li>
                        </ul>
                    </div>
                </section>

                <!-- §5 Pagos y Comprobantes -->
                <section id="s5" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-credit-card"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 5</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Pagos y Comprobantes</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 space-y-3 text-sm leading-relaxed">
                        <p>Operamos con métodos de pago locales (Yape, Plin y transferencias).</p>
                        <ul class="legal-list">
                            <li>Es <span class="highlight">REQUISITO OBLIGATORIO</span> enviar la captura del comprobante de pago para validar la transacción e iniciar el proceso de entrega.</li>
                            <li>El titular de la cuenta de pago debe ser, preferentemente, quien realiza el pedido para evitar inconvenientes de validación.</li>
                        </ul>
                    </div>
                </section>

                <!-- §6 Política de Reembolsos y Flexibilidad -->
                <section id="s6" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-rotate-left"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 6</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Política de Reembolsos y Flexibilidad</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 space-y-3 text-sm leading-relaxed">
                        <p>Buscamos la satisfacción del cliente, por lo que aplicamos las siguientes reglas:</p>
                        <ul class="legal-sub">
                            <li>Si el producto ya fue enviado o la compra se hizo efectiva con éxito, no hay lugar a reembolsos.</li>
                            <li>Se aceptan reembolsos si: el usuario ya tiene el objeto, se arrepiente antes del envío o hay un error técnico de nuestra parte.</li>
                            <li><strong class="text-white">Gestión:</strong> El reembolso puede demorar hasta 24 horas. El usuario debe facilitar su número de cuenta o datos de Yape/Plin. Si han pasado más de 6 horas desde tu solicitud, puedes enviarnos un recordatorio para agilizar el trámite.</li>
                        </ul>
                    </div>
                </section>

                <!-- §7 Precios y Errores de Listado -->
                <section id="s7" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-tags"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 7</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Precios y Errores de Listado</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 text-sm leading-relaxed">
                        <p>Los precios pueden variar según la rotación de la tienda oficial del juego. Si se detecta un error evidente en un precio, nos comunicaremos contigo para confirmar el precio correcto o procederemos con la cancelación y reembolso total del pedido.</p>
                    </div>
                </section>

                <!-- §8 Limitación de Responsabilidad -->
                <section id="s8" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-triangle-exclamation"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 8</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Limitación de Responsabilidad</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 text-sm leading-relaxed">
                        <p>Como Banano Store es un servicio de intermediación, el usuario acepta que el uso de los productos adquiridos está sujeto a las reglas y términos de las plataformas oficiales (como Epic Games). Banano Store cumple con los procesos autorizados de envío de regalos y no se responsabiliza por cambios futuros en las políticas de uso o funcionamiento que dichas empresas ajenas puedan implementar de forma unilateral.</p>
                    </div>
                </section>

                <!-- §9 Modificaciones -->
                <section id="s9" class="glass section-card p-6 fade-up">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="section-icon"><i class="fas fa-pen-to-square"></i></div>
                        <div>
                            <span class="text-[.65rem] font-bold text-blue-400/50 uppercase tracking-widest">Sección 9</span>
                            <h2 class="text-lg font-bold text-white mt-0.5">Modificaciones</h2>
                        </div>
                    </div>
                    <div class="sm:pl-14 text-sm leading-relaxed">
                        <p>Nos reservamos el derecho de actualizar estos términos en cualquier momento para mejorar el servicio o adaptarnos a cambios legales. El uso de la web implica la aceptación de la versión vigente de estos términos.</p>
                    </div>
                </section>

                <!-- CTA -->
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-4 fade-up">
                    <a href="/" class="inline-flex items-center gap-2 px-7 py-3 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/20 hover:shadow-blue-500/30 hover:scale-[1.03] transition-all duration-200">
                        <i class="fas fa-store"></i>Volver a la Tienda
                    </a>
                    <a href="/privacidad/" class="inline-flex items-center gap-2 px-7 py-3 rounded-xl glass text-gray-300 font-medium text-sm hover:text-white hover:border-blue-500/20 transition-all duration-200">
                        <i class="fas fa-shield-halved"></i>Ver Política de Privacidad
                    </a>
                </div>

            </article>
        </div>

        <!-- ════ Footer ════ -->
        <footer class="border-t border-white/5 py-8 text-center text-xs text-gray-600">
            <p>&copy; 2026 Banano Store (banano.pe) &mdash; Todos los derechos reservados.</p>
            <div class="mt-2 flex justify-center gap-6">
                <a href="/privacidad/" class="hover:text-blue-400 transition-colors">Privacidad</a>
                <a href="/" class="hover:text-blue-400 transition-colors">Tienda</a>
            </div>
        </footer>

    </div>

    <!-- Mobile TOC toggle -->
    <button class="toc-toggle" id="toc-btn" aria-label="Tabla de contenido"><i class="fas fa-list"></i></button>

    <!-- Back to top -->
    <button id="back-to-top" aria-label="Volver arriba"><i class="fas fa-arrow-up"></i></button>

    <script>
    (function(){
        /* ── Scroll-spy & progress bar ── */
        const sections = document.querySelectorAll('section[id]');
        const tocLinks = document.querySelectorAll('.toc-link[data-s]');
        const bar = document.getElementById('progress-bar');
        const btt = document.getElementById('back-to-top');

        function onScroll(){
            const st = window.scrollY;
            const dh = document.documentElement.scrollHeight - window.innerHeight;
            bar.style.width = dh > 0 ? (st/dh*100)+'%' : '0%';
            btt.classList.toggle('show', st > 400);

            let current = '';
            sections.forEach(s=>{
                if(s.getBoundingClientRect().top < 160) current = s.id;
            });
            tocLinks.forEach(l=>{
                l.classList.toggle('active', l.dataset.s === current);
            });
        }
        window.addEventListener('scroll', onScroll, {passive:true});
        onScroll();

        /* ── Fade-up on scroll ── */
        const io = new IntersectionObserver(entries=>{
            entries.forEach(e=>{ if(e.isIntersecting){ e.target.classList.add('visible'); io.unobserve(e.target); }});
        },{threshold:.12});
        document.querySelectorAll('.fade-up').forEach(el=>io.observe(el));

        /* ── Mobile TOC toggle ── */
        const tocBtn = document.getElementById('toc-btn');
        const tocSide = document.getElementById('toc-sidebar');
        tocBtn.addEventListener('click',()=>tocSide.classList.toggle('open'));
        tocSide.querySelectorAll('a[href^="#"]').forEach(a=>{
            a.addEventListener('click',()=>tocSide.classList.remove('open'));
        });

        /* ── Back to top ── */
        btt.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));
    })();
    </script>
</body>
</html>