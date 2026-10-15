<?php
// juegos/sorteo_flash/transparencia.php
// PORTAL DE TRANSPARENCIA "PROVABLY FAIR" — Estilo manga/cómic narrado por Banano.
// Página estática (sin datos sensibles): explica el sistema con paneles tipo viñeta.
// Las imágenes de la mascota se resuelven desde config/banano_slots.json
// (gestionado desde el panel admin), con slots separados para escritorio y
// móvil. Un slot sin imagen simplemente no se renderiza.

header('Content-Type: text/html; charset=UTF-8');

/**
 * Carga (una sola vez) la config de slots de Banano desde el JSON del admin.
 * Usa una caché estática en lugar de una variable global: la página puede
 * incluirse desde el router dentro de una función, donde las variables de
 * nivel superior no son globales.
 */
function bananoSlotsConfig(): array
{
    static $slots = null;
    if ($slots !== null) {
        return $slots;
    }
    $slots = ['desktop' => [], 'mobile' => [], 'pages' => []];
    $configFile = dirname(__DIR__, 2) . '/config/banano_slots.json';
    if (file_exists($configFile)) {
        $decoded = json_decode((string)file_get_contents($configFile), true);
        if (is_array($decoded) && isset($decoded['slots']) && is_array($decoded['slots'])) {
            foreach (['desktop', 'mobile'] as $dev) {
                if (isset($decoded['slots'][$dev]) && is_array($decoded['slots'][$dev])) {
                    $slots[$dev] = $decoded['slots'][$dev];
                }
            }
        }
        if (is_array($decoded) && isset($decoded['pages']) && is_array($decoded['pages'])) {
            $slots['pages'] = $decoded['pages'];
        }
    }
    return $slots;
}

/**
 * URL de una "página manga" completa subida desde el admin (ej. la que
 * reemplaza la tabla de "¿Quién podría hacer trampa?"). '' si no hay imagen.
 */
function bananoPageUrl(string $key): string
{
    $cfg = bananoSlotsConfig();
    $page = $cfg['pages'][$key] ?? null;
    if (is_array($page) && !empty($page['url']) && is_string($page['url'])) {
        return trim($page['url']);
    }
    return '';
}

/**
 * Renderiza la imagen de la mascota para un slot (escritorio o móvil).
 * Aplica los ajustes visuales (escala, rotación, offset X/Y) guardados en el
 * JSON como transform inline. Si el slot no tiene imagen, no renderiza nada.
 */
function bananoSlot(string $device, int $slot, string $posClass = ''): string
{
    $bananoSlots = bananoSlotsConfig();
    $cfg = $bananoSlots[$device][(string)$slot] ?? null;
    if (!is_array($cfg) || empty($cfg['url']) || !is_string($cfg['url'])) {
        return '';
    }
    $scale  = max(50, min(200, (int)($cfg['scale'] ?? 100)));
    $rotate = max(-30, min(30, (int)($cfg['rotate'] ?? 0)));
    $x      = max(-120, min(120, (int)($cfg['x'] ?? 0)));
    $y      = max(-120, min(120, (int)($cfg['y'] ?? 0)));
    $style  = sprintf('transform: translate(%dpx, %dpx) rotate(%ddeg) scale(%.2f);', $x, $y, $rotate, $scale / 100);
    $animate = !array_key_exists('animate', $cfg) || !empty($cfg['animate']);
    $devCls = $device === 'mobile' ? 'banano-slot-movil' : 'banano-slot-escritorio';
    $cls    = trim('banano-slot ' . $devCls . ($animate ? '' : ' banano-estatico') . ' ' . htmlspecialchars($posClass, ENT_QUOTES));
    return '<div class="' . $cls . '" data-slot="' . htmlspecialchars($device . '-' . $slot, ENT_QUOTES) . '">'
         . '<img src="' . htmlspecialchars(trim($cfg['url']), ENT_QUOTES) . '" style="' . $style . '" alt="Banano, la mascota" loading="lazy" width="300" height="300">'
         . '</div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transparencia · Sorteo Flash — Sistema a prueba de trampas</title>
<meta name="description" content="Cómo funciona el sistema Provably Fair del Sorteo Flash: semilla sellada, blockchain de Binance y verificación pública. Explicado panel a panel por Banano.">
<meta property="og:title" content="Sorteo Flash: Sistema a prueba de trampas 🍌">
<meta property="og:description" content="Nadie puede amañar el sorteo — ni siquiera nosotros. Banano te lo explica en modo manga.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bangers&family=Nunito:ital,wght@0,400;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="transparencia.css">
</head>
<body>
<div class="manga-bg" aria-hidden="true"></div>

<main class="pliego" id="pliego">

    <!-- ============ CABECERA — TÍTULO DEL CAPÍTULO ============ -->
    <header class="cabecera panel-anim">
        <div class="titulo-wrap">
            <p class="titulo-kicker">EXPLICACIÓN</p>
            <h1 class="titulo-capitulo">SORTEO FLASH:<br class="br-movil"> SISTEMA A PRUEBA<br class="br-movil"> DE TRAMPAS</h1>
            <p class="titulo-sub">Nuestro sistema <strong>Provably Fair</strong> (demostrablemente justo) — verificable por cualquiera, en cualquier momento.<span class="solo-escritorio-inline"> ¡Te damos la bienvenida a nuestra <strong>guía de transparencia</strong>! Aquí te explicamos paso a paso cómo funciona el sorteo, para que despejes cualquier duda con total tranquilidad.</span><span class="solo-movil-inline"> Aquí te explicamos, paso a paso, cómo funciona el sorteo.</span></p>
        </div>
    </header>

    <!-- ============ PANEL 1 — REGLAS ============ -->
    <section class="panel panel-ancho panel-anim" id="panel-reglas">
        <span class="tag-colgante">¡ATENCIÓN!</span>
        <h2 class="panel-titulo">REGLAS DEL SORTEO</h2>
        <div class="panel-body">
            <p class="texto-reglas">
                El <em>Sorteo Flash</em> reparte <strong>tickets numerados</strong> (0, 1, 2, 3…) por orden de inscripción.
                Al cerrar, un número gana. Y la regla de oro es una sola:
            </p>
            <div class="cuadro-dato cuadro-dato-grande">
                <span class="cuadro-dato-label">REGLA DE ORO</span>
                <span class="cuadro-dato-valor">TODOS LOS TICKETS VALEN EXACTAMENTE LO MISMO</span>
            </div>
            <p class="texto-reglas texto-reglas-peque">
                El ticket <strong>#0</strong>, el <strong>del medio</strong> y el <strong>último</strong> tienen la MISMA probabilidad de ganar: <strong>1 entre N</strong>. Sin favoritos, sin ventajas ocultas, sin letra pequeña.
            </p>
        </div>
    </section>

    <!-- ============ PANEL 2 — LA ESTADÍSTICA NO MIENTE ============ -->
    <section class="panel panel-medio panel-anim" id="panel-probabilidad">
        <h2 class="panel-titulo panel-titulo-compacto">EJEMPLO 1: LA ESTADÍSTICA NO MIENTE <span class="emoji-titulo">🎲</span></h2>
        <div class="panel-body">
            <p class="texto-reglas texto-reglas-peque">Imagina un sorteo donde el ticket ganador se decide lanzando <strong>DOS dados</strong> y sumando. Habría tickets del <strong>2 al 12</strong> (el ticket #1 ni existiría: con dos dados nunca suma 1 😅). ¿Todos ganarían igual? <strong>¡Para nada!</strong></p>
            <div class="dados-3d" aria-hidden="true">
                <div class="dado3d-escena">
                    <div class="dado3d dado3d-a">
                        <div class="cara3d c1"><span class="pip"></span></div>
                        <div class="cara3d c2"><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c3"><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c4"><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c5"><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c6"><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                    </div>
                </div>
                <span class="dados-mas">+</span>
                <div class="dado3d-escena">
                    <div class="dado3d dado3d-b">
                        <div class="cara3d c1"><span class="pip"></span></div>
                        <div class="cara3d c2"><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c3"><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c4"><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c5"><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                        <div class="cara3d c6"><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span><span class="pip"></span></div>
                    </div>
                </div>
                <span class="dados-resultado">= <strong>7</strong></span>
            </div>
            <div class="suma-dados stagger" role="img" aria-label="Gráfico de barras: la suma 7 tiene 6 combinaciones de 36; las sumas 2 y 12 solo 1 de 36">
                <div class="sbar" style="--h:16%"><i></i><span>2</span></div>
                <div class="sbar" style="--h:32%"><i></i><span>3</span></div>
                <div class="sbar" style="--h:48%"><i></i><span>4</span></div>
                <div class="sbar" style="--h:64%"><i></i><span>5</span></div>
                <div class="sbar" style="--h:80%"><i></i><span>6</span></div>
                <div class="sbar sbar-rey" style="--h:100%"><i></i><span>7</span></div>
                <div class="sbar" style="--h:80%"><i></i><span>8</span></div>
                <div class="sbar" style="--h:64%"><i></i><span>9</span></div>
                <div class="sbar" style="--h:48%"><i></i><span>10</span></div>
                <div class="sbar" style="--h:32%"><i></i><span>11</span></div>
                <div class="sbar" style="--h:16%"><i></i><span>12</span></div>
            </div>
            <div class="combos-fila">
                <div class="combo-tarjeta combo-rey">
                    <span class="combo-num">🎟️ 7</span>
                    <span class="combo-detalle">1+6 · 2+5 · 3+4 · 4+3 · 5+2 · 6+1</span>
                    <span class="combo-prob">6 de 36 → 16.7%</span>
                </div>
                <div class="combo-tarjeta">
                    <span class="combo-num">🎟️ 12</span>
                    <span class="combo-detalle">solo 6+6</span>
                    <span class="combo-prob">1 de 36 → 2.8%</span>
                </div>
            </div>
            <p class="texto-reglas texto-reglas-peque">El dueño del ticket <strong>7</strong> ganaría <em>6 veces más</em> que el del 12, sin que nadie haga trampa: unos tickets <strong>nacen con ventaja</strong>. Un sorteo así estaría roto de fábrica.</p>
            <div class="igualdad-bloque">
                <p class="igualdad-titulo">⚡ NUESTRO SORTEO NO ES ASÍ ⚡</p>
                <div class="tickets-igualdad stagger" role="img" aria-label="Tres barras idénticas: el primer ticket, el del medio y el último tienen la misma probabilidad 1/N">
                    <div class="tbar"><i></i><span>🎟️ #0<br><small>el primero</small></span></div>
                    <div class="tbar"><i></i><span>🎟️ medio<br><small>cualquiera</small></span></div>
                    <div class="tbar"><i></i><span>🎟️ último<br><small>el N-1</small></span></div>
                </div>
                <p class="igualdad-nota">Aquí no se suma ni se combina nada por ticket: hay <strong>UNA sola extracción uniforme</strong> y cada ticket cuenta exactamente <strong>una vez</strong>. Ni más, ni menos.</p>
            </div>
            <div class="cuadro-dato">
                <span class="cuadro-dato-label">EN NUESTRO SORTEO</span>
                <span class="cuadro-dato-valor">ENTRAR PRIMERO, AL MEDIO O AL FINAL NO CAMBIA NADA: 1/N</span>
            </div>
        </div>
    </section>

    <!-- ============ COLUMNA DERECHA: PASO 1 + PASO 2 ============ -->
    <div class="columna-pasos">

    <!-- ============ PANEL 3 — EL SOBRE LACRADO ============ -->
    <section class="panel panel-medio panel-anim" id="panel-sobre">
        <h2 class="panel-titulo">PASO 1: LA PROMESA SELLADA <span class="emoji-titulo">🔒</span></h2>
        <div class="panel-body">
            <div class="mini-vinetas">
                <div class="mini-vineta">
                    <div class="sobre-visual">
                        <svg viewBox="0 0 120 90" class="svg-sobre" aria-hidden="true">
                            <rect x="10" y="20" width="100" height="60" rx="4" fill="#fff" stroke="#000" stroke-width="4"/>
                            <path d="M10 24 L60 58 L110 24" fill="none" stroke="#000" stroke-width="4"/>
                            <circle cx="60" cy="52" r="14" fill="#ffd23f" stroke="#000" stroke-width="4"/>
                            <rect x="54" y="48" width="12" height="10" rx="2" fill="#000"/>
                            <rect x="57" y="42" width="6" height="8" rx="3" fill="none" stroke="#000" stroke-width="3"/>
                        </svg>
                    </div>
                    <p class="mini-vineta-texto">Al crear cada sorteo, el sistema genera su <strong>ingrediente secreto</strong> (la semilla) y lo guarda en un sobre con candado…</p>
                </div>
                <div class="mini-flecha" aria-hidden="true">➜</div>
                <div class="mini-vineta">
                    <div class="tablon-visual">
                        <p class="tablon-titulo">📌 TABLÓN PÚBLICO</p>
                        <code class="hash-chip">5180207f…c2b664af</code>
                        <p class="tablon-nota">La HUELLA del secreto,<br>visible desde la sala de espera</p>
                    </div>
                    <p class="mini-vineta-texto">…y su <strong>huella digital</strong> aparece en la parte superior del sorteo desde la sala de espera. ¡Puedes tomarle captura antes de que empiece!</p>
                </div>
            </div>
            <div class="linea-tiempo" aria-hidden="true">
                <span class="lt-punto">🔒 Sobre sellado</span>
                <span class="lt-linea"></span>
                <span class="lt-punto">👥 Se abren inscripciones</span>
            </div>
            <div class="cuadro-dato cuadro-dato-mini">
                <span class="cuadro-dato-valor-mini">¿Por qué publicamos la huella y no el secreto? La huella <strong>SHA-256</strong> queda congelada a la vista de todos ANTES de empezar: garantiza que la semilla <strong>no se alteró a mitad del sorteo</strong> (cambiar una sola letra rompería la coincidencia). Y aunque el sistema conoce la semilla, eso no da ninguna ventaja: el resultado también depende de un <strong>bloque futuro de Binance</strong> que nadie puede predecir.</span>
            </div>
        </div>
        <p class="nota-panel">💡 Al finalizar, la semilla se revela: pásala por SHA-256 y verás que coincide con la huella publicada desde el inicio. Si la hubiéramos cambiado, no coincidiría… y quedaríamos en evidencia al instante.</p>
    </section>

    <!-- ============ PANEL 4 — LA LISTA SE CONGELA ============ -->
    <section class="panel panel-medio panel-anim" id="panel-congelamiento">
        <span class="tag-colgante tag-azul">¡MOMENTO CLAVE!</span>
        <h2 class="panel-titulo">PASO 2: CONGELAMIENTO <span class="emoji-titulo">❄️</span></h2>
        <div class="panel-body">
            <div class="hielo-visual">
                <div class="hielo-bloque">
                    <p class="hielo-titulo">❄️ LISTA CONGELADA</p>
                    <div class="tickets-lista">
                        <span class="ticket-mini">🎟️ 0</span><span class="ticket-mini">🎟️ 1</span><span class="ticket-mini">🎟️ 2</span><span class="ticket-mini">🎟️ 3</span><span class="ticket-mini ticket-etc">…</span><span class="ticket-mini">🎟️ N</span>
                    </div>
                    <div class="hielo-brillo" aria-hidden="true"></div>
                </div>
                <div class="hielo-meta">
                    <span class="reloj-cierre">⏰ ¡CIERRE!</span>
                    <code class="hash-chip hash-chip-azul">huella de la lista:<br>f671aef0…ea868cd8</code>
                </div>
            </div>
            <ul class="lista-checks stagger">
                <li>✅ Al cerrar las inscripciones, la lista de boletos asignados queda <strong>congelada</strong> y se publica su propia huella (el <em>roster hash</em>).</li>
                <li>🚫 A partir de ese instante: ni añadir, ni quitar, ni mover a nadie.</li>
                <li>🎲 Y lo más importante: <strong>el dado todavía NO se ha lanzado.</strong></li>
            </ul>
        </div>
        <p class="nota-panel">📸 Como una foto instantánea: clic y nadie se mueve. Retocar la foto cambiaría su huella digital delante de todos.</p>
    </section>

    </div><!-- /columna-pasos -->

    <!-- ============ PANEL 5 — BSC LANZA EL DADO (PROTAGONISTA) ============ -->
    <section class="panel panel-ancho panel-destacado panel-anim" id="panel-bsc">
        <h2 class="panel-titulo">PASO 3: BINANCE SMART CHAIN LANZA EL DADO <span class="emoji-titulo">🌐</span></h2>
        <div class="panel-body">
            <div class="game-flow stagger" role="img" aria-label="Diagrama: la lista congelada apunta a un bloque futuro; los validadores del mundo crean el bloque con su hash único, que queda listo para la mezcla del paso 4">
                <div class="gf-caja gf-paso1"><span class="gf-icono">❄️</span>LISTA<br>CONGELADA</div>
                <div class="gf-flecha" aria-hidden="true">➜</div>
                <div class="gf-caja gf-paso2"><span class="gf-icono">🎯</span>SE APUNTA AL<br>BLOQUE FUTURO <strong>#X</strong></div>
                <div class="gf-flecha" aria-hidden="true">➜</div>
                <div class="gf-caja gf-paso3 gf-pulso"><span class="gf-icono">⛓️</span>VALIDADORES REPARTIDOS<br>POR EL MUNDO</div>
                <div class="gf-flecha" aria-hidden="true">➜</div>
                <div class="gf-caja gf-paso4"><span class="gf-icono">🧊</span>EL BLOQUE #X NACE<br>CON SU HASH ÚNICO</div>
                <div class="gf-flecha" aria-hidden="true">➜</div>
                <div class="gf-caja gf-paso5 gf-ganador"><span class="gf-icono">🔑</span>HASH PÚBLICO LISTO<br>PARA LA MEZCLA (PASO 4)</div>
            </div>
            <div class="datos-fila">
                <div class="cuadro-dato">
                    <span class="cuadro-dato-label">DATO CLAVE</span>
                    <span class="cuadro-dato-valor">EL BLOQUE ELEGIDO <u>NO EXISTE</u> CUANDO SE CONGELA LA LISTA</span>
                </div>
                <div class="cuadro-dato">
                    <span class="cuadro-dato-label">¿QUIÉN LO FABRICA?</span>
                    <span class="cuadro-dato-valor">LA RED DE BINANCE — UN TERCERO INDEPENDIENTE, VISIBLE EN BSCSCAN.COM</span>
                </div>
            </div>
        </div>
        <?php echo bananoSlot('desktop', 2, 'banano-hakari'); ?>
        <?php echo bananoSlot('mobile', 2, 'banano-hakari'); ?>
        <div class="bocadillo bocadillo-hakari bocadillo-clave">
            <p class="solo-escritorio">Aquí está la clave: el resultado depende de un bloque que <strong>AÚN NO EXISTE</strong> cuando la lista se congela. Su hash lo fabrica la red de Binance — no nosotros — y nadie puede conocerlo por adelantado.</p>
            <p class="solo-movil">El resultado depende de un bloque que <strong>AÚN NO EXISTE</strong>: nadie puede predecirlo ni manipularlo.</p>
        </div>
    </section>

    <!-- ============ PANEL 6 — LA MEZCLA FINAL ============ -->
    <section class="panel panel-medio panel-anim" id="panel-mezcla">
        <h2 class="panel-titulo">PASO 4: LA FÓRMULA DEL GANADOR</h2>
        <div class="panel-body">
            <div class="licuadora-flow" role="img" aria-label="Diagrama: la semilla y el hash del bloque se mezclan con SHA-256 y HMAC; se toman los primeros 8 caracteres, se convierten a decimal, se calcula el resto entre el total de tickets y se comprueba que el asiento sea válido">
                <div class="lf-ingredientes">
                    <div class="lf-item">🔑 Semilla<br>revelada</div>
                    <div class="lf-mas">+</div>
                    <div class="lf-item">⛓️ Hash del<br>bloque BSC</div>
                </div>
                <div class="lf-flecha" aria-hidden="true">⬇</div>
                <div class="lf-licuadora">🌀 MEZCLA CRIPTOGRÁFICA<br><span class="lf-sub">(SHA-256 + HMAC — imposible de revertir o predecir)</span></div>
                <div class="lf-flecha" aria-hidden="true">⬇</div>
                <div class="lf-numero">🔢 PRIMEROS 8 CARACTERES → NÚMERO DECIMAL<br><span class="lf-sub">ejemplo real: 666d2203 → 1.718.428.163</span></div>
                <div class="lf-flecha" aria-hidden="true">⬇</div>
                <div class="lf-numero">➗ DECIMAL mod TOTAL DE TICKETS<br><span class="lf-sub">el resto de la división = número de ticket</span></div>
                <div class="lf-flecha" aria-hidden="true">⬇</div>
                <div class="lf-resultado">🪑 ¿ASIENTO VÁLIDO?<span class="lf-res-flecha" aria-hidden="true"> → </span>🏆 ¡TICKET GANADOR!<br><span class="lf-sub">si está anulado: redibujo automático con la misma fórmula</span></div>
            </div>
            <div class="cuadro-dato">
                <span class="cuadro-dato-label">PROBABILIDAD DE CADA TICKET</span>
                <span class="cuadro-dato-valor">1/N — SIN EXCEPCIONES</span>
            </div>
        </div>
        <p class="nota-panel">🌊 Cambiar UNA SOLA LETRA de cualquier ingrediente cambia TODO el resultado — se llama <em>efecto avalancha</em>.</p>
    </section>

    <div class="columna-pasos">

    <!-- ============ PANEL 7 — ASIENTOS VACÍOS / REDIBUJO ============ -->
    <section class="panel panel-medio panel-anim" id="panel-huecos">
        <h2 class="panel-titulo">CASO ESPECIAL: TICKETS ANULADOS <span class="emoji-titulo solo-escritorio-inline">🚃</span></h2>
        <div class="panel-body">
            <div class="anulados-historia stagger" role="img" aria-label="Historia en 3 escenas: 1, el asiento del expulsado queda vacío y nadie se mueve; 2, si el sorteo cae en el asiento vacío, el tiro no vale y se relanza; 3, el redibujo se hace entre todos por igual y sale un ganador válido">
                <div class="anulado-escena">
                    <span class="anulado-escena-num">1</span>
                    <p class="anulado-escena-titulo">EL ASIENTO QUEDA VACÍO</p>
                    <div class="anulado-asientos">
                        <div class="asiento">42</div>
                        <div class="asiento asiento-anulado"><span class="asiento-etiqueta etiqueta-anulado">ANULADO</span>43<span class="asiento-x">✕</span></div>
                        <div class="asiento">44</div>
                    </div>
                    <p class="anulado-escena-texto">Si alguien es expulsado por romper las reglas, su asiento queda <strong>vacío</strong>. Nadie se mueve de sitio: cada ticket conserva su número original.</p>
                </div>
                <div class="anulado-escena">
                    <span class="anulado-escena-num">2</span>
                    <p class="anulado-escena-titulo">SI CAE AHÍ, NO VALE</p>
                    <div class="anulado-asientos">
                        <div class="asiento asiento-anulado asiento-rebote"><span class="asiento-etiqueta etiqueta-rebote">¡NO VALE!</span>43<span class="asiento-x">✕</span></div>
                    </div>
                    <p class="anulado-escena-texto">Si el número sorteado cae en un asiento vacío, ese tiro <strong>se descarta</strong> y se <strong>relanza automáticamente</strong> con la siguiente fórmula de la cadena.</p>
                </div>
                <div class="anulado-escena">
                    <span class="anulado-escena-num">3</span>
                    <p class="anulado-escena-titulo">REDIBUJO ENTRE TODOS</p>
                    <div class="anulado-asientos">
                        <div class="asiento asiento-anulado"><span class="asiento-etiqueta etiqueta-anulado">ANULADO</span>43<span class="asiento-x">✕</span></div>
                        <div class="asiento">44</div>
                        <span class="asientos-puntos" aria-hidden="true">…</span>
                        <div class="asiento asiento-ganador"><span class="asiento-etiqueta etiqueta-ganador">🏆 GANADOR</span>7</div>
                    </div>
                    <p class="anulado-escena-texto">El relanzamiento se sortea <strong>entre TODOS por igual</strong>: puede ganar cualquier asiento válido de la sala (aquí, el 7). El vecino del asiento vacío <strong>NO hereda</strong> el premio ni gana ventaja.</p>
                </div>
            </div>
        </div>
        <p class="nota-panel">🔁 El redibujo también es verificable: cada relanzamiento sigue una fórmula fija y pública, reproducible rebote a rebote.</p>
    </section>

    <!-- ============ PANEL 8 — PÁGINA MANGA: NADIE PUEDE HACER TRAMPA ============ -->
    <?php $mangaUrl = bananoPageUrl('manga_trampa'); if ($mangaUrl !== ''): ?>
    <section class="panel panel-ancho panel-anim panel-pagina-manga" id="panel-imposibles">
        <div class="pagina-manga-wrap">
            <img src="<?php echo htmlspecialchars($mangaUrl, ENT_QUOTES); ?>" alt="Página manga: los resultados del sorteo no se pueden predecir ni alterar — dependen de un bloque futuro en la blockchain" loading="lazy">
        </div>
    </section>
    <?php endif; ?>

    </div><!-- /columna-pasos -->

    <!-- ============ PANEL 9 — LA REVELACIÓN ============ -->
    <section class="panel panel-medio panel-anim" id="panel-revelacion">
        <h2 class="panel-titulo">AL TERMINAR: TODAS LAS CARTAS SOBRE LA MESA <span class="emoji-titulo">🃏</span></h2>
        <div class="panel-body">
            <div class="revelacion-visual">
                <div class="sobre-abierto" aria-hidden="true">
                    <svg viewBox="0 0 120 90" class="svg-sobre" aria-hidden="true">
                        <path d="M10 40 L60 8 L110 40" fill="#ffd23f" stroke="#000" stroke-width="4" stroke-linejoin="round"/>
                        <rect x="10" y="36" width="100" height="46" rx="4" fill="#fff" stroke="#000" stroke-width="4"/>
                        <path d="M46 58 l10 11 l20 -22" fill="none" stroke="#3ecf6e" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p class="sobre-abierto-texto">SOBRE ABIERTO:<br>SECRETO REVELADO</p>
                </div>
                <ul class="revelacion-checks">
                    <li><span class="check-badge">✅</span> La <strong>semilla secreta</strong>, revelada — comprueba que su huella coincide con la publicada al inicio.</li>
                    <li><span class="check-badge">✅</span> El <strong>número y hash del bloque BSC</strong> — búscalo tú mismo en BscScan.com.</li>
                    <li><span class="check-badge">✅</span> La <strong>lista congelada</strong> de tickets, con su huella publicada al cierre.</li>
                </ul>
            </div>
        </div>
        <p class="nota-panel">👇 Al terminar cada sorteo publicamos TODO. ¿Quieres comprobarlo con uno real? Mira el siguiente panel.</p>
    </section>

    <!-- ============ PANEL 10 — EJEMPLO REAL PASO A PASO ============ -->
    <section class="panel panel-ancho panel-destacado panel-anim" id="panel-ejemplo">
        <span class="tag-colgante tag-dorada">¡SORTEO REAL!</span>
        <h2 class="panel-titulo">EJEMPLO: VERIFICA UN SORTEO PASO A PASO</h2>
        <div class="panel-body">
            <p class="texto-reglas texto-reglas-peque">Datos públicos de un sorteo real ya finalizado (64 tickets, asientos 43 y 59 anulados). Solo necesitas una calculadora <strong>SHA-256</strong> y una <strong>HMAC-SHA256</strong> online — gratis y en cualquier buscador. Cada paso es una sola operación: sin prisa, uno a uno.</p>
            <ol class="pasos-verificacion stagger">
                <li class="paso-item">
                    <span class="paso-num">1</span>
                    <div class="paso-contenido">
                        <h3>Comprueba que la semilla no se cambió</h3>
                        <p>Pega la semilla revelada <code class="hash-chip">682c3c67…e04a91fe</code> en una calculadora SHA-256:</p>
                        <p class="paso-resultado">sha256(semilla) = <code class="hash-chip hash-chip-ok">5180207f…c2b664af</code> ✅ Coincide con la huella publicada ANTES de empezar.</p>
                        <p class="paso-nota">Esto demuestra que el "ingrediente secreto" quedó fijado desde el inicio y nunca se tocó.</p>
                    </div>
                </li>
                <li class="paso-item">
                    <span class="paso-num">2</span>
                    <div class="paso-contenido">
                        <h3>Comprueba el bloque en la blockchain</h3>
                        <p>Busca el bloque <strong>#114995519</strong> en <strong>BscScan.com</strong> y verifica su hash: <code class="hash-chip">0x88018650…c7f5bb63</code>.</p>
                        <p class="paso-nota">Ese dato lo produjo la red de Binance — no nosotros — y es público para todo el mundo.</p>
                    </div>
                </li>
                <li class="paso-item">
                    <span class="paso-num">3</span>
                    <div class="paso-contenido">
                        <h3>Calcula la mezcla maestra</h3>
                        <p>Escribe la semilla del paso 1 seguida (sin espacios) del hash del paso 2, y pasa ese texto por SHA-256:</p>
                        <p class="paso-resultado">mezcla = sha256(semilla + hash del bloque) = <code class="hash-chip">563caeff…d57551f5</code></p>
                        <p class="paso-nota">Esta mezcla combina nuestro compromiso inicial con el azar de la blockchain: ninguna de las dos partes la controla sola.</p>
                    </div>
                </li>
                <li class="paso-item">
                    <span class="paso-num">4</span>
                    <div class="paso-contenido">
                        <h3>Genera el código del sorteo</h3>
                        <p>En una calculadora HMAC-SHA256, usa como <strong>clave</strong> la mezcla del paso 3 y como <strong>mensaje</strong> el texto <code class="hash-chip">salt_index_0</code>:</p>
                        <p class="paso-resultado">HMAC-SHA256 = <code class="hash-chip">666d2203…</code></p>
                        <p class="paso-nota">"salt_index_0" solo significa "primer premio del sorteo". Es un texto fijo y público.</p>
                    </div>
                </li>
                <li class="paso-item">
                    <span class="paso-num">5</span>
                    <div class="paso-contenido">
                        <h3>Convierte el código en un número</h3>
                        <p>Toma solo los <strong>primeros 8 caracteres</strong> del resultado (<code class="hash-chip">666d2203</code>) y conviértelos de hexadecimal a decimal (cualquier conversor online sirve):</p>
                        <p class="paso-resultado">666d2203 (hex) = <strong>1.718.428.163</strong> (decimal)</p>
                    </div>
                </li>
                <li class="paso-item">
                    <span class="paso-num">6</span>
                    <div class="paso-contenido">
                        <h3>Aplica el módulo y encuentra al ganador</h3>
                        <p>Divide ese número entre el total de tickets (64) y quédate con el <strong>resto</strong> de la división:</p>
                        <p class="paso-resultado paso-resultado-final">1.718.428.163 mod 64 = <span class="ticket-ganador-final">🏆 TICKET #3</span></p>
                        <p class="paso-nota">Si hubiera caído en un asiento anulado (43 o 59), se repetirían los pasos 4-6 con el mensaje <code class="hash-chip">salt_index_0_r1</code>, <code class="hash-chip">_r2</code>… hasta caer en uno válido. Misma cadena pública para todos.</p>
                    </div>
                </li>
            </ol>
            <div class="cuadro-dato cuadro-dato-mini">
                <span class="cuadro-dato-label">EXTRA: ¿POR QUÉ "salt_index_0"?</span>
                <span class="cuadro-dato-valor-mini">"salt_index_0" <strong>no añade azar ni es un dato externo</strong>: es una etiqueta fija y pública que solo indica qué premio se está calculando (premio 1 = <code class="hash-chip">_0</code>, premio 2 = <code class="hash-chip">_1</code>, redibujo = <code class="hash-chip">_r1</code>…). Todo el azar viene de la clave (semilla + bloque BSC). Como la etiqueta es idéntica para todos y se conoce de antemano, cualquiera que la escriba en su calculadora obtiene exactamente el mismo resultado.</span>
            </div>
        </div>
        <?php echo bananoSlot('desktop', 4, 'banano-borde-abajo banano-derecha'); ?>
        <?php echo bananoSlot('mobile', 3, 'banano-borde-abajo banano-derecha'); ?>
    </section>

    <!-- ============ PIE ============ -->
    <footer class="pie-pliego panel-anim">
        <p class="solo-escritorio">Nuestro sistema combina dos técnicas criptográficas estándar: <strong>commit-reveal</strong> (la semilla se compromete públicamente antes y se revela después) y un <strong>beacon público de aleatoriedad</strong> (los bloques de Binance Smart Chain). Ambas son técnicas ampliamente documentadas y utilizadas en los sistemas Provably Fair.</p>
        <p class="solo-movil">Usamos dos técnicas estándar: <strong>commit-reveal</strong> (la semilla se compromete antes y se revela después) y los bloques de <strong>Binance Smart Chain</strong> como fuente pública de azar.</p>
        <p class="pie-mini">Blockchain: Binance Smart Chain (BscScan.com) · SHA-256 / HMAC-SHA256</p>
        <a class="pie-volver" href="./">⬅ Volver al Sorteo Flash</a>
    </footer>

</main>

<script src="transparencia.js" defer></script>
</body>
</html>
