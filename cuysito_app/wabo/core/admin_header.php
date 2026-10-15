<?php
// Archivo: admin_header.php (v7.0 - Sidebar oculto por defecto, sin header superior, menú por familias)

// 1. SEGURIDAD CENTRALIZADA (Reemplaza a session_start)
// Sube dos niveles: de 'core' a 'wabo' (..), de 'wabo' a raíz (..), y entra a 'api'
require_once __DIR__ . '/../../api/session_security.php';

// 2. Verificación de Login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Usamos ruta absoluta (/wabo/login.php) para evitar errores si este archivo
    // se incluye desde carpetas profundas.
    header('Location: /wabo/login.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Banano Store</title>
    <base href="/wabo/">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/avatar-fallback.js?v=20260914"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        fortnite: {
                            blue: '#00B2FF',
                            purple: '#C13CFF',
                            yellow: '#FFD700',
                            green: '#00CC66',
                            red: '#FF3E3E',
                            orange: '#FF9900'
                        }
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.3s ease-in-out',
                        'slide-up': 'slideUp 0.3s ease-out'
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideUp: { '0%': { transform: 'translateY(20px)', opacity: '0' }, '100%': { transform: 'translateY(0)', opacity: '1' } }
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        const savedTheme = localStorage.getItem('theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);
        document.documentElement.classList.toggle('dark', savedTheme !== 'light');
    </script>

    <style>
        /* --- ESTILOS (Sin cambios) --- */
        :root {
            --primary-light: #f1f3f5; --secondary-light: #ffffff; --text-primary-light: #212529; --text-secondary-light: #495057; --accent-light: #f59e0b; --border-light: #e9ecef;
            --primary-dark: #050505; --secondary-dark: #050505; --text-primary-dark: #f1f5f9; --text-secondary-dark: #94a3b8; --accent-dark: #f59e0b; --border-dark: #1a1a1a;
            --primary-glitch: #0d0d0d; --secondary-glitch: #1a1a1a; --text-primary-glitch: #f0f0f0; --accent-glitch: #ff00ff; --border-glitch: #ff00ff;
        }
        [data-theme="light"] { --primary: var(--primary-light); --secondary: var(--secondary-light); --text-primary: var(--text-primary-light); --text-secondary: var(--text-secondary-light); --accent: var(--accent-light); --border-color: var(--border-light); }
        [data-theme="dark"] { --primary: var(--primary-dark); --secondary: var(--secondary-dark); --text-primary: var(--text-primary-dark); --text-secondary: var(--text-secondary-dark); --accent: var(--accent-dark); --border-color: var(--border-dark); }
        [data-theme="glitch"] { --primary: var(--primary-glitch); --secondary: var(--secondary-glitch); --text-primary: var(--text-primary-glitch); --accent: var(--accent-glitch); --border-color: var(--border-glitch); }
        body { background-color: var(--primary); color: var(--text-primary); font-family: 'Inter', sans-serif; display: flex; }

        /* --- CONTROLES NATIVOS: los desplegables heredan el tema (evita option blanco sobre blanco) --- */
        [data-theme="light"] { color-scheme: light; }
        [data-theme="dark"], [data-theme="glitch"] { color-scheme: dark; }
        [data-theme="dark"] select, [data-theme="dark"] select option, [data-theme="dark"] select optgroup,
        [data-theme="glitch"] select, [data-theme="glitch"] select option, [data-theme="glitch"] select optgroup { background-color: #111111; color: var(--text-primary); }
        [data-theme="dark"] select option:checked, [data-theme="glitch"] select option:checked { background-color: var(--accent); color: #111111; }
        [data-theme="dark"] select:disabled, [data-theme="glitch"] select:disabled { color: var(--text-secondary); }
        [data-theme="dark"] .swal2-popup select, [data-theme="glitch"] .swal2-popup select { background-color: #111111; color: var(--text-primary); border-color: var(--border-color); }
        #main-container { display: flex; width: 100%; height: 100%; }

        /* --- SIDEBAR (oculto por defecto, superpuesto al abrir) --- */
        #sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 17rem; max-width: 92vw; background-color: var(--secondary); border-right: 1px solid var(--border-color); transform: translateX(-100%); transition: transform .28s cubic-bezier(.4,0,.2,1), box-shadow .28s; z-index: 60; box-shadow: none; }
        body.sidebar-open #sidebar { transform: translateX(0); box-shadow: 0 0 40px rgba(0,0,0,.35); }
        #sidebar-overlay { position: fixed; inset: 0; background-color: rgba(0,0,0,.45); backdrop-filter: blur(1px); z-index: 55; opacity: 0; pointer-events: none; transition: opacity .28s ease; }
        body.sidebar-open #sidebar-overlay { opacity: 1; pointer-events: auto; }

        /* --- CONTENIDO (ocupa el 100%; sin header superior) --- */
        #content-wrapper { flex-grow: 1; display: flex; flex-direction: column; overflow: hidden; height: 100vh; width: 100%; transition: margin-left .28s cubic-bezier(.4,0,.2,1); }
        #content-wrapper > main { flex-grow: 1; overflow-y: auto; padding: 1.5rem; padding-top: 3.1rem; }

        /* --- BOTÓN DISCRETO PARA INVOCAR EL MENÚ (esquina superior izquierda) --- */
        #sidebar-toggle { position: fixed; top: .55rem; left: .55rem; width: 2.1rem; height: 2.1rem; border-radius: .6rem; display: flex; align-items: center; justify-content: center; gap: 3px; flex-direction: column; background-color: var(--secondary); border: 1px solid var(--border-color); color: var(--text-secondary); z-index: 50; cursor: pointer; opacity: .78; transition: opacity .2s, transform .2s, border-color .2s, color .2s; animation: sidebarTogglePulse 3.6s ease-in-out infinite; }
        #sidebar-toggle span { display: block; width: 14px; height: 2px; border-radius: 2px; background: currentColor; transition: width .2s; }
        #sidebar-toggle span:nth-child(2) { width: 10px; }
        #sidebar-toggle:hover, #sidebar-toggle:focus-visible { opacity: 1; color: var(--text-primary); border-color: var(--accent); transform: scale(1.06); animation: none; outline: none; }
        #sidebar-toggle:hover span { width: 14px; }
        body.sidebar-open #sidebar-toggle, body.sidebar-pinned #sidebar-toggle { opacity: 0; pointer-events: none; transform: scale(.7); animation: none; }
        @keyframes sidebarTogglePulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0); } 50% { box-shadow: 0 0 0 4px rgba(245,158,11,.14); } }

        /* --- MODO FIJADO (solo escritorio): el menú se queda visible y empuja el contenido --- */
        @media (min-width: 1024px) {
            body.sidebar-pinned #sidebar { transform: translateX(0); box-shadow: none; }
            body.sidebar-pinned #sidebar-overlay { opacity: 0 !important; pointer-events: none !important; }
            body.sidebar-pinned #content-wrapper { margin-left: 17rem; }
            body.sidebar-pinned #content-wrapper > main { padding-top: 1.5rem; }
            body.sidebar-pinned #sidebar-close { display: none; }
        }
        @media (max-width: 1023px) { #sidebar-pin { display: none; } }

        .card { background-color: var(--secondary); border: 1px solid var(--border-color); }
        .sidebar-link { display: flex; align-items: center; padding: .55rem .75rem; border-radius: .5rem; transition: all .18s; font-weight: 500; font-size: .875rem; color: var(--text-secondary); }
        .sidebar-link:hover { background-color: var(--primary); color: var(--text-primary); }
        .sidebar-link.active { background-color: var(--accent); color: white; }
        .sidebar-link i { margin-right: .8rem; width: 1.15rem; text-align: center; font-size: .9rem; }
        .sidebar-group { margin: .35rem 0 .75rem; }
        .sidebar-section-title { font-size: .64rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 700; color: var(--text-secondary); opacity: .7; padding: 0 .75rem; margin-bottom: .3rem; display: flex; align-items: center; gap: .5rem; }
        .sidebar-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }
        .sidebar-badge { font-size: .6rem; margin-left: .4rem; padding: .05rem .35rem; border-radius: .3rem; background: rgba(245,158,11,.15); color: var(--accent); font-weight: 700; letter-spacing: .04em; }
        .sidebar-link.active .sidebar-badge { background: rgba(255,255,255,.25); color: #fff; }
        .sidebar-foot-btn { width: 2.25rem; height: 2.25rem; border-radius: .5rem; display: inline-flex; align-items: center; justify-content: center; color: var(--text-secondary); transition: all .18s; border: 1px solid transparent; }
        .sidebar-foot-btn:hover { background-color: var(--primary); color: var(--text-primary); border-color: var(--border-color); }
        .sidebar-foot-btn.is-on { color: var(--accent); border-color: var(--accent); }
        #sidebar nav { scrollbar-width: thin; }
        #sidebar nav::-webkit-scrollbar { width: 5px; } #sidebar nav::-webkit-scrollbar-thumb { background: rgba(128,128,128,.3); border-radius: 3px; }
        [data-theme="dark"] #sidebar { box-shadow: none !important; }
        [data-theme="dark"] .sidebar-link:hover, [data-theme="dark"] .sidebar-foot-btn:hover { background-color: #0a0a0a; }
        [data-theme="dark"] .sidebar-section-title::after { background: #222; }
        @media (prefers-reduced-motion: reduce) { #sidebar-toggle { animation: none; } }
        .dynamic-image{display:none;} [data-theme="light"] .light-image{display:block;} [data-theme="dark"] .dark-image{display:block;} [data-theme="glitch"] .glitch-image{display:block;} @keyframes glitch-bg{0%{background-position:0 0}10%{background-position:4px -6px}20%{background-position:-8px 8px}30%{background-position:10px -12px}100%{background-position:0 0}} [data-theme="glitch"] #content-wrapper > main{background-image:url(https://media.giphy.com/media/1Aef12uV15o2Y/giphy.gif);background-size:cover;animation:glitch-bg 0.3s infinite linear alternate-reverse;} @keyframes glitch-icon-anim{0%,100%{transform:translate(0,0)}20%{transform:translate(-1px,1px)}40%{transform:translate(1px,-1px)}60%{transform:translate(-1px,-1px)}80%{transform:translate(1px,1px)}} .glitch-icon{display:inline-block;animation:glitch-icon-anim 1s infinite;}.glitch-icon .fa-question{text-shadow:-1px 0 red,1px 0 cyan;} [data-theme="glitch"] .glitch-effect::before,[data-theme="glitch"] .glitch-effect::after{content:attr(data-text);position:absolute;top:0;left:0;width:100%;height:100%;background:var(--secondary-glitch);clip-path:inset(50% 0 30% 0);} [data-theme="glitch"] .glitch-effect::before{left:2px;text-shadow:-1px 0 #ff00c1;animation:glitch-text-anim 2s infinite linear alternate-reverse;} [data-theme="glitch"] .glitch-effect::after{left:-2px;text-shadow:-1px 0 #00fff9;animation:glitch-text-anim 2s infinite linear alternate;} @keyframes glitch-text-anim{to{clip-path:inset(80% 0 10% 0)}} @keyframes glitch-image-shake{0%,100%{transform:translate(0,0)}25%{transform:translate(-8px,8px)}50%{transform:translate(8px,-8px)}75%{transform:translate(-8px,-8px)}} @keyframes hue-rotate-chaos{to{filter:hue-rotate(360deg)}} @keyframes glitch-card-bg{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}} [data-theme="glitch"] .card{background:linear-gradient(90deg,#ff00ff,#00ffff,#ffff00,#ff00ff,#ff00c1,#00fff9);background-size:400% 400%;animation:glitch-card-bg 4s ease infinite,hue-rotate-chaos 3s linear infinite reverse;} [data-theme="glitch"] #sidebar{background:linear-gradient(90deg,#f0f,#0ff,#ff0,#f0f);background-size:200% auto;animation:glitch-card-bg 8s linear infinite;} [data-theme="glitch"] #acciones-rapidas-container{display:none;} [data-theme="glitch"] .glitch-image{animation:glitch-image-shake .3s infinite;}
    </style>
</head>
<body>
    <script>
        document.body.classList.toggle('light-mode', document.documentElement.getAttribute('data-theme') === 'light');
        if (window.innerWidth >= 1024 && localStorage.getItem('sidebarPinned') === 'true') { document.body.classList.add('sidebar-pinned'); }
    </script>
    <audio id="glitchSound" src="https://www.myinstants.com/media/sounds/glitch-sound-effect.mp3" preload="auto"></audio>
    <audio id="clapSound" src="https://www.myinstants.com/media/sounds/sound-effect-hd-4k.mp3" preload="auto"></audio>

    <?php
    // Menú lateral en 8 familias. Cada destino es una página independiente.
    // 'match' = fragmento de la URL que marca el enlace como activo.
    $waboNav = [
        ['title' => 'Operación', 'items' => [
            ['href' => 'dashboard.php', 'match' => 'dashboard.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Inicio'],
            ['href' => 'pedidos/gestionar_pedidos.php', 'match' => 'pedidos/gestionar_pedidos.php', 'icon' => 'fa-shopping-cart', 'label' => 'Pedidos'],
        ]],
        ['title' => 'Usuarios', 'items' => [
            ['href' => 'usuarios/gestionar_usuarios.php', 'match' => 'usuarios/gestionar_usuarios.php', 'icon' => 'fa-users', 'label' => 'Directorio'],
            ['href' => 'verificacion_usuarios/gestionar_verificacionesusu.php', 'match' => 'verificacion_usuarios/', 'icon' => 'fa-user-check', 'label' => 'Verificaciones'],
        ]],
        ['title' => 'Economía', 'items' => [
            ['href' => 'economia/resumen.php', 'match' => 'economia/resumen.php', 'icon' => 'fa-coins', 'label' => 'Resumen'],
            ['href' => 'economia/movimientos.php', 'match' => 'economia/movimientos.php', 'icon' => 'fa-book', 'label' => 'Movimientos'],
            ['href' => 'economia/ranking.php', 'match' => 'economia/ranking.php', 'icon' => 'fa-trophy', 'label' => 'Ranking'],
            ['href' => 'economia/reglas.php', 'match' => 'economia/reglas.php', 'icon' => 'fa-scale-balanced', 'label' => 'Reglas económicas'],
        ]],
        ['title' => 'Sorteo Flash', 'items' => [
            ['href' => 'sorteos_flash/gestionar_sorteos.php', 'match' => 'sorteos_flash/gestionar_sorteos.php', 'icon' => 'fa-bolt', 'label' => 'Sorteos Flash'],
            ['href' => 'sorteos_flash/sala_mando.php', 'match' => 'sorteos_flash/sala_mando.php', 'icon' => 'fa-satellite-dish', 'label' => 'Sala de Mando'],
            ['href' => 'sorteos_flash/estadisticas.php', 'match' => 'sorteos_flash/estadisticas.php', 'icon' => 'fa-chart-line', 'label' => 'Estadísticas'],
            ['href' => 'sorteos_flash/pase_bienvenida.php', 'match' => 'sorteos_flash/pase_bienvenida.php', 'icon' => 'fa-ticket', 'label' => 'Pase de Bienvenida'],
            ['href' => 'compras_manuales/gestionar_compras.php', 'match' => 'compras_manuales/', 'icon' => 'fa-hand-holding-usd', 'label' => 'Compras Manuales'],
            ['href' => 'sorteos_flash/baneos_expulsados.php', 'match' => 'sorteos_flash/baneos_expulsados.php', 'icon' => 'fa-gavel', 'label' => 'Baneos y Expulsados'],
        ]],
        ['title' => 'Pistas musicales', 'items' => [
            ['href' => 'videos_comunidad/gestionar_videos_comunidad.php', 'match' => 'videos_comunidad/', 'icon' => 'fa-users', 'label' => 'Pistas Comunidad'],
            ['href' => 'pistas_musicales/gestionar_pistas.php', 'match' => 'pistas_musicales/', 'icon' => 'fa-music', 'label' => 'Vídeos de Pistas (clásico)'],
        ]],
        ['title' => 'Promociones y Juegos', 'items' => [
            ['href' => 'cupones/gestionar_cupones.php', 'match' => 'cupones/', 'icon' => 'fa-ticket-alt', 'label' => 'Cupones'],
            ['href' => 'giro_diario/gestionar_girodiario.php', 'match' => 'giro_diario/gestionar_girodiario.php', 'icon' => 'fa-dice', 'label' => 'Giro Diario · Configuración'],
            ['href' => 'giro_diario/usuarios_girodiario.php', 'match' => 'giro_diario/usuarios_girodiario.php', 'icon' => 'fa-user-clock', 'label' => 'Giro Diario · Usuarios'],
            ['href' => 'descuentos_manuales/gestionar_descuentos.php', 'match' => 'descuentos_manuales/', 'icon' => 'fa-tags', 'label' => 'Descuentos Manuales'],
            ['href' => 'ofertas_automaticas/gestionar_ofertas_auto.php', 'match' => 'ofertas_automaticas/', 'icon' => 'fa-robot', 'label' => 'Constructor de Ofertas Automáticas'],
        ]],
        ['title' => 'Catálogo y Contenido', 'items' => [
            ['href' => 'productos_estaticos/gestionar_productos.php', 'match' => 'productos_estaticos/', 'icon' => 'fa-box-open', 'label' => 'Productos Estáticos'],
            ['href' => 'videos_objetos/gestionar_videos_objetos.php', 'match' => 'videos_objetos/', 'icon' => 'fa-film', 'label' => 'Vídeos de Objetos'],
            ['href' => 'fondos_secciones/gestionar_fondos.php', 'match' => 'fondos_secciones/', 'icon' => 'fa-panorama', 'label' => 'Fondos de Secciones'],
            ['href' => 'avatars/gestionar_avatares.php', 'match' => 'avatars/', 'icon' => 'fa-user-astronaut', 'label' => 'Avatares'],
        ]],
        ['title' => 'Sistema', 'items' => [
            ['href' => 'configuracion/gestionar_configuracion.php', 'match' => 'configuracion/gestionar_configuracion.php', 'icon' => 'fa-cogs', 'label' => 'Ajustes de Tienda'],
            ['href' => 'configuracion_usuarios/gestionar_configuracion_usuarios.php', 'match' => 'configuracion_usuarios/', 'icon' => 'fa-sliders-h', 'label' => 'Ajustes Avanzados'],
            ['href' => 'visual_juegos/gestionar_visual_juegos.php', 'match' => 'visual_juegos/', 'icon' => 'fa-palette', 'label' => 'Visual de Juegos'],
            ['href' => 'data_layer/gestionar_data_layer.php', 'match' => 'data_layer/', 'icon' => 'fa-layer-group', 'label' => 'Capa de Datos'],
        ]],
    ];
    $waboUri = $_SERVER['REQUEST_URI'] ?? '';
    $waboIsActive = function (string $match) use ($waboUri, $currentPage): bool {
        if (strpos($match, '/') === false) return $currentPage === $match;
        return strpos($waboUri, $match) !== false;
    };
    ?>

    <button id="sidebar-toggle" type="button" aria-label="Abrir menú" title="Menú (Ctrl+B)"><span></span><span></span><span></span></button>

    <div id="main-container">
        <aside id="sidebar" class="flex flex-col" aria-label="Menú principal">
            <div class="px-4 py-3 flex items-center justify-between border-b" style="border-color: var(--border-color);">
                <a href="dashboard.php" class="flex items-center min-w-0"><i class="fas fa-banana text-xl" style="color: var(--accent);"></i><span class="font-bold text-lg sidebar-brand-text whitespace-nowrap ml-2">Banano Panel</span></a>
                <div class="flex items-center gap-1">
                    <button id="sidebar-pin" type="button" class="sidebar-foot-btn" title="Fijar menú (siempre visible)"><i class="fas fa-thumbtack text-sm"></i></button>
                    <button id="sidebar-close" type="button" class="sidebar-foot-btn" title="Ocultar menú" aria-label="Ocultar menú"><i class="fas fa-times"></i></button>
                </div>
            </div>

            <nav class="px-2 pt-2 flex-grow overflow-y-auto">
                <?php foreach ($waboNav as $group): ?>
                <div class="sidebar-group">
                    <p class="sidebar-section-title"><?php echo htmlspecialchars($group['title']); ?></p>
                    <?php foreach ($group['items'] as $item): ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>" class="sidebar-link <?php echo $waboIsActive($item['match']) ? 'active' : ''; ?>"><i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i><span class="sidebar-text"><?php echo htmlspecialchars($item['label']); ?></span></a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </nav>

            <div class="px-3 py-2 border-t" style="border-color: var(--border-color);">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center min-w-0">
                        <img src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1751611110/BANANO-ADMIN_su5ecl.webp" alt="User" class="w-7 h-7 rounded-full flex-shrink-0">
                        <span class="ml-2 text-sm font-semibold truncate"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <button id="toggleTheme" type="button" class="sidebar-foot-btn" title="Tema claro / oscuro"><i class="fas fa-sun hidden"></i><i class="fas fa-moon"></i></button>
                        <button id="toggleGlitch" type="button" class="sidebar-foot-btn" title="???"><span class="glitch-icon"><i class="fas fa-question text-xs"></i></span></button>
                        <a href="logout.php" class="sidebar-foot-btn text-red-500 hover:!bg-red-500 hover:!text-white" title="Cerrar sesión"><i class="fas fa-sign-out-alt"></i></a>
                    </div>
                </div>
            </div>
        </aside>

        <div id="sidebar-overlay"></div>

        <div id="content-wrapper">
            <main>
