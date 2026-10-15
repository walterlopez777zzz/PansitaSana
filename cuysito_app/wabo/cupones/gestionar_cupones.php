<?php
// Archivo: wabo/cupones/gestionar_cupones.php (Versión 2.7 - Funcionalidad Total)
require_once __DIR__ . '/../core/admin_header.php';
?>
<style>
    .price-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
    .price-item { text-align: center; } .price-item .label { font-size: 0.7rem; color: var(--text-secondary); } .price-item .value { font-size: 1rem; font-weight: 700; }
    .item-card.selected { box-shadow: 0 0 0 3px var(--accent) !important; transform: translateY(-2px); }
    #coupon-cart-floater { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1000; transform: translateY(200%); transition: all .3s ease-in-out; }
    #coupon-cart-floater.visible { transform: translateY(0); }
    .section-header { background-image: linear-gradient(to right, rgba(0,0,0,0.5), rgba(0,0,0,0.1)); border-left: 4px solid var(--accent); }
    .vbucks-container { background: rgba(0,0,0,.6); backdrop-filter: blur(4px); }
    .card-hover:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -5px rgba(0,0,0,.1), 0 8px 8px -5px rgba(0,0,0,.04); }
    .modal-input, .modal-select { background-color: var(--primary) !important; border-color: var(--border-color) !important; color: var(--text-primary) !important; }
    /* Estilos para las notificaciones Toast */
.toast {
    padding: 1rem 1.5rem;
    border-radius: 0.5rem;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    opacity: 0;
    transform: translateX(100%);
    transition: all 0.3s ease-in-out;
}
.toast.show {
    opacity: 1;
    transform: translateX(0);
}
.toast.success { background-color: #10B981; color: white; } /* Verde */
.toast.error { background-color: #EF4444; color: white; }   /* Rojo */
.toast-icon { font-size: 1.25rem; }
/* --- AÑADE ESTOS ESTILOS PARA EL INTERRUPTOR --- */
.switch {
    position: relative;
    display: inline-block;
    width: 48px; /* Ancho del interruptor */
    height: 24px; /* Alto del interruptor */
}
.switch input { 
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 18px; /* Tamaño del círculo */
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: #22C55E; /* Color verde cuando está activo */
}
input:checked + .slider:before {
    transform: translateX(24px); /* Distancia que se mueve el círculo */
}
#toggle_requiere_login:checked + .slider { background-color: #06B6D4; }
#toggle_requiere_verificacion:checked + .slider { background-color: #EAB308; }
.access-toggle-card {
    background: rgba(0,0,0,0.2);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    transition: border-color 0.3s, background 0.3s;
}
.access-toggle-card:has(input:checked) { border-color: rgba(255,255,255,0.15); background: rgba(0,0,0,0.35); }
.access-toggle-card .toggle-label-icon { font-size: 1.1rem; width: 1.5rem; text-align: center; }
.access-toggle-card .toggle-label-text { font-size: 0.8rem; font-weight: 600; line-height: 1.2; }
.access-toggle-card .toggle-label-hint { font-size: 0.65rem; font-weight: 400; opacity: 0.55; margin-top: 1px; }
</style>

<div class="space-y-8">
    <header><h1 class="text-3xl font-bold">Gestor de Cupones</h1></header>
    <!-- Tabla de Cupones Creados -->
    <section class="card rounded-xl shadow-md p-6">
        <div class="flex justify-between items-center mb-4"><h2 class="text-xl font-semibold">Cupones Creados</h2><button id="openNewCouponBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm"><i class="fas fa-plus mr-2"></i>Crear Cupón</button></div>
        <div class="overflow-x-auto"><table class="min-w-full divide-y" style="border-color: var(--border-color);"><thead style="background-color: var(--primary);">
    <tr>
        <th class="p-3 text-left text-xs uppercase">Código</th>
        <th class="p-3 text-left text-xs uppercase">Valor</th>
        <th class="p-3 text-left text-xs uppercase">Usos</th>
        <th class="p-3 text-left text-xs uppercase">Aplicable a</th> <!-- NUEVO -->
        <th class="p-3 text-left text-xs uppercase">Estado</th> <!-- NUEVO -->
        <th class="p-3 text-right text-xs uppercase">Acciones</th>
    </tr>
</thead><tbody id="couponsTableBody" class="divide-y" style="border-color: var(--border-color);"></tbody></table></div>
    </section>
    <!-- Galería de la Tienda -->
    <section><div class="flex justify-between items-center mb-4"><h2 class="text-xl font-semibold">Crear Cupón desde la Tienda</h2></div><div id="shopItemsContainer" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"></div></section>
</div>
<!-- Pon esto en algún lugar al principio de tu HTML, fuera del flujo principal -->
<div id="toast-container" class="fixed top-5 right-5 z-[9999] space-y-3"></div>
<!-- Floater del Carrito de Cupones -->
<div id="coupon-cart-floater"><button id="createCouponFromCartBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-full shadow-lg flex items-center gap-3"><i class="fas fa-ticket-alt"></i><span>Crear Cupón para <span id="couponCartCount">0</span> objetos</span></button></div>

<!-- Modal Definitivo Actualizado -->
<div id="couponModal" class="fixed inset-0 z-50 flex items-center justify-center hidden bg-black/60">
    <div class="card rounded-xl shadow-xl w-full max-w-4xl mx-4 z-10 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b">
            <h3 class="text-xl font-semibold" id="couponModalTitle"></h3>
            <button type="button" id="closeCouponModalBtn" class="p-2 rounded-full hover:bg-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto space-y-4">
            <form id="couponForm" class="space-y-4">
                <input type="hidden" name="id">

                <!-- Fila 1: Código y Descripción -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs mb-1">Código</label>
                        <input type="text" name="codigo" class="modal-input p-2 border w-full rounded-lg uppercase font-bold tracking-wider" required>
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Nota Interna (Admin)</label>
                        <input type="text" name="descripcion" class="modal-input p-2 border w-full rounded-lg" placeholder="Descripción solo para ti">
                    </div>
                </div>

                <!-- Fila 2: Datos Económicos -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs mb-1">Tipo</label>
                        <select name="tipo_descuento" class="modal-select p-2 border w-full rounded-lg">
                            <option value="porcentaje">% Porcentaje</option>
                            <option value="fijo">S/ Monto Fijo</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Valor</label>
                        <input type="number" name="valor_descuento" step="0.01" class="modal-input p-2 border w-full rounded-lg font-bold text-green-400" required>
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Gasto Mín. (S/)</label>
                        <input type="number" name="gasto_minimo" step="0.01" class="modal-input p-2 border w-full rounded-lg" placeholder="Opcional">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Dscto. Máx. (S/)</label>
                        <input type="number" name="descuento_maximo" step="0.01" class="modal-input p-2 border w-full rounded-lg" placeholder="Opcional">
                    </div>
                </div>

                <!-- Fila 3 y 4: Límites Técnicos -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 bg-black/10 p-2 rounded-lg">
                    <div><label class="block text-xs mb-1">Usos Totales</label><input type="number" name="limite_usos" class="modal-input p-2 border w-full rounded-lg" placeholder="∞"></div>
                    <div><label class="block text-xs mb-1">Usos por Usuario</label><input type="number" name="limite_por_usuario" class="modal-input p-2 border w-full rounded-lg" value="1"></div>
                    <div><label class="block text-xs mb-1">Límite por IP</label><input type="number" name="limite_por_ip" class="modal-input p-2 border w-full rounded-lg" placeholder="∞"></div>
                    <div><label class="block text-xs mb-1">Límite por WhatsApp</label><input type="number" name="limite_por_whatsapp" class="modal-input p-2 border w-full rounded-lg" placeholder="∞"></div>
                    <div><label class="block text-xs mb-1">Compras Mínimas</label><input type="number" name="compras_minimas_requeridas" class="modal-input p-2 border w-full rounded-lg" placeholder="0"></div>
                    <div><label class="block text-xs mb-1">Cant. Mín. Items</label><input type="number" name="cantidad_minima_compra" class="modal-input p-2 border w-full rounded-lg" placeholder="1" value="1"></div>
                    <div class="md:col-span-2"><label class="block text-xs mb-1">Tiempo para Pagar (Reserva)</label><div class="grid grid-cols-2 gap-2"><input type="number" name="limite_pago_horas" class="modal-input p-2 border w-full rounded-lg" placeholder="Horas"><input type="number" name="limite_pago_minutos_ux" class="modal-input p-2 border w-full rounded-lg" placeholder="Minutos"></div></div>
                </div>

                <!-- Fila 5: Fechas -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="tipo_inicio" class="block text-xs mb-1">Inicio del Cupón</label>
                        <select name="tipo_inicio" id="tipo_inicio" class="modal-select p-2 border w-full rounded-lg mb-2"><option value="inmediato">Inmediato</option><option value="especifico">Fecha y Hora Específica</option></select>
                        <input type="datetime-local" name="fecha_inicio" id="fecha_inicio_input" class="modal-input p-2 border w-full rounded-lg hidden">
                    </div>
                    <div>
                        <label for="tipo_expiracion" class="block text-xs mb-1">Expiración del Cupón</option></label>
                        <select name="tipo_expiracion" id="tipo_expiracion" class="modal-select p-2 border w-full rounded-lg mb-2"><option value="nunca">Nunca</option><option value="especifica">Fecha Específica (Fin del día)</option><option value="temporizador">Temporizador (Horas/Minutos)</option><option value="exacta">Fecha y Hora Exacta</option></select>
                        <div id="expiracion_fields_container" class="space-y-2">
                            <input type="date" name="fecha_expiracion_especifica" class="modal-input p-2 border w-full rounded-lg hidden">
                            <div id="temporizador_container" class="grid grid-cols-2 gap-2 hidden"><input type="number" name="temporizador_horas" class="modal-input p-2 border w-full rounded-lg" placeholder="Horas"><input type="number" name="temporizador_minutos" class="modal-input p-2 border w-full rounded-lg" placeholder="Minutos"></div>
                            <input type="datetime-local" name="fecha_expiracion_exacta" class="modal-input p-2 border w-full rounded-lg hidden">
                        </div>
                    </div>
                </div>

<!-- ========================================== -->
<!-- NUEVA SECCIÓN: GAMIFICACIÓN Y MULTIMEDIA (MEJORADA CON PREVIEW) -->
<!-- ========================================== -->
<div class="border border-purple-500/30 rounded-lg p-4 bg-purple-900/10 mt-4 relative overflow-hidden">
    <div class="absolute top-0 right-0 bg-purple-600 text-xs px-2 py-1 rounded-bl-lg text-white font-bold">ZONA FX</div>
    <h4 class="font-semibold text-sm uppercase mb-3 text-purple-400"><i class="fas fa-gamepad mr-2"></i>Efectos y Mensajes (Trolleo/Premios)</h4>
    
    <!-- Multimedia -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        
        <!-- AUDIO -->
        <div>
            <label class="block text-xs mb-1">Audio (MP3/WAV)</label>
            <div class="flex gap-2">
                <select id="audio_selector" class="modal-select p-2 border w-full rounded-lg text-xs">
                    <option value="">Sin Audio</option>
                    <!-- Se llena con JS -->
                </select>
                <button type="button" id="previewAudioBtn" class="bg-purple-600 text-white p-2 rounded hover:bg-purple-500"><i class="fas fa-play"></i></button>
            </div>
            <!-- Input oculto que guarda el valor real para la BD -->
            <input type="text" name="audio_url" id="audio_url_input" class="modal-input p-2 border w-full rounded-lg text-xs mt-1 hidden" placeholder="O pega URL externa aquí...">
        </div>

        <!-- STICKER -->
        <div>
            <label class="block text-xs mb-1">Imagen/Sticker</label>
            <div class="flex gap-2 items-center">
                <select id="sticker_selector" class="modal-select p-2 border w-full rounded-lg text-xs">
                    <option value="">Sin Imagen</option>
                    <!-- Se llena con JS -->
                </select>
                <div id="sticker_preview_container" class="w-8 h-8 border border-gray-600 bg-black/50 rounded flex items-center justify-center overflow-hidden">
                    <i class="fas fa-image text-gray-500 text-xs"></i>
                </div>
            </div>
            <input type="text" name="imagen_url" id="imagen_url_input" class="modal-input p-2 border w-full rounded-lg text-xs mt-1 hidden" placeholder="O pega URL externa aquí...">
        </div>

        <!-- MODO -->
        <div>
            <label class="block text-xs mb-1">Modo de Efecto Visual</label>
            <select name="modo_efecto" class="modal-select p-2 border w-full rounded-lg text-sm">
                <option value="ninguno">Ninguno</option>
                <option value="lluvia_stickers">🌧️ Lluvia de Stickers</option>
                <option value="imagen_centro">🖼️ Imagen Central (Susto/Popup)</option>
            </select>
        </div>
    </div>

    <!-- Mensajes Personalizados -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs mb-1 text-green-400 font-bold"><i class="fas fa-check mr-1"></i>Mensaje Éxito (Frontend)</label>
            <textarea name="msg_exito" rows="2" class="modal-input p-2 border w-full rounded-lg text-sm" placeholder="¡Felicidades! Cupón aplicado."></textarea>
        </div>
        <div>
            <label class="block text-xs mb-1 text-red-400 font-bold"><i class="fas fa-skull mr-1"></i>Mensaje Error/Troll</label>
            <textarea name="msg_error" rows="2" class="modal-input p-2 border w-full rounded-lg text-sm" placeholder="Mensaje si falla o para asustar..."></textarea>
        </div>
        <div>
            <label class="block text-xs mb-1 text-blue-400 font-bold"><i class="fas fa-envelope mr-1"></i>Mensaje Confirmación</label>
            <textarea name="msg_confirmacion" rows="2" class="modal-input p-2 border w-full rounded-lg text-sm" placeholder="Para correo o ThankYou Page"></textarea>
        </div>
    </div>
</div>
                <!-- ========================================== -->
<!-- ========================================== -->
<!-- NUEVA SECCIÓN: CONFIGURADOR DE QUIZ (TRIVIA) -->
<!-- ========================================== -->
<div class="border border-yellow-500/30 rounded-lg p-4 bg-yellow-900/10 mt-4 relative">
    <div class="absolute top-0 right-0 bg-yellow-600 text-xs px-2 py-1 rounded-bl-lg text-black font-bold">GAMIFICACIÓN</div>
    
    <!-- Interruptor Principal -->
    <label class="flex items-center gap-2 text-sm font-bold text-yellow-400 mb-2 cursor-pointer w-fit select-none">
        <input type="checkbox" id="enable_quiz_checkbox" class="h-5 w-5 rounded text-yellow-500 focus:ring-yellow-500 bg-gray-800 border-gray-600">
        <i class="fas fa-brain"></i> Activar Modo Quiz (Interceptor)
    </label>

    <!-- Contenedor Configuración (Oculto por defecto) -->
    <div id="quiz_config_container" class="hidden space-y-4 mt-4 border-t border-yellow-500/20 pt-4">
        
        <!-- Pregunta -->
        <div>
            <label class="block text-xs mb-1 text-yellow-200">Pregunta del Trivial</label>
            <textarea id="quiz_question" rows="2" class="modal-input p-2 border w-full rounded-lg text-sm bg-gray-900" placeholder="Ej: ¿Cuál es la rareza de la skin Banano?"></textarea>
        </div>

        <!-- Respuestas -->
        <div class="bg-black/20 p-3 rounded-lg border border-dashed border-gray-700">
            <div class="flex justify-between items-center mb-2">
                <label class="block text-xs text-yellow-200">Respuestas (Marca la correcta a la derecha)</label>
                <button type="button" id="add_quiz_answer_btn" class="text-xs bg-yellow-600/20 hover:bg-yellow-600/40 text-yellow-400 px-2 py-1 rounded transition"><i class="fas fa-plus mr-1"></i>Añadir Opción</button>
            </div>
            <div id="quiz_answers_list" class="space-y-2">
                <!-- Aquí se inyectarán los inputs con JS -->
            </div>
        </div>

        <!-- Ajustes de Tiempo y Mezcla -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-xs mb-1">Tiempo Límite (seg)</label>
                <input type="number" id="quiz_time_limit" class="modal-input p-2 border w-full rounded-lg text-center font-mono" value="30">
            </div>
            <div>
                <label class="block text-xs mb-1">Intentos Máximos</label>
                <input type="number" id="quiz_max_attempts" class="modal-input p-2 border w-full rounded-lg text-center font-mono" value="1">
            </div>
            <div class="col-span-2 flex items-center h-full pt-4">
                 <label class="flex items-center gap-2 text-xs cursor-pointer select-none text-gray-300 hover:text-white">
                    <input type="checkbox" id="quiz_randomize" class="h-4 w-4 rounded bg-gray-700">
                    <i class="fas fa-random text-gray-500"></i> Mezclar orden de respuestas
                </label>
            </div>
        </div>

        <!-- Lógica de Castigo -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-red-900/10 p-3 rounded-lg border border-red-900/30">
            <div>
                <label class="block text-xs mb-1 text-red-300">Si falla, ¿Qué pasa?</label>
                <select id="quiz_penalty_mode" class="modal-select p-2 border w-full rounded-lg text-sm">
                    <option value="block">🚫 Bloqueo Definitivo (Game Over)</option>
                    <option value="cooldown">⏳ Cooldown (Esperar para reintentar)</option>
                </select>
            </div>
            <div id="quiz_cooldown_field" class="hidden">
                <label class="block text-xs mb-1 text-red-300">Tiempo de Espera (segundos)</label>
                <input type="number" id="quiz_cooldown_seconds" class="modal-input p-2 border w-full rounded-lg text-center" value="20">
            </div>
        </div>

        <!-- Mensajes del Juego -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs mb-1 text-orange-400">Mensaje al Fallar (Intento perdido)</label>
                <input type="text" id="quiz_msg_fail" class="modal-input p-2 border w-full rounded-lg text-xs" placeholder="Respuesta incorrecta...">
            </div>
            <div>
                <label class="block text-xs mb-1 text-red-500">Mensaje Game Over (Sin intentos)</label>
                <input type="text" id="quiz_msg_lose" class="modal-input p-2 border w-full rounded-lg text-xs" placeholder="Te quedaste sin intentos :(">
            </div>
        </div>
    </div>
    
    <!-- Input oculto donde guardaremos todo el JSON para enviarlo a PHP -->
    <input type="hidden" name="quiz_config" id="quiz_config_input">
</div>

<!-- ========================================== -->
<!-- SECCIÓN: CONTROL DE ACCESO POR SESIÓN -->
<!-- ========================================== -->
<div class="border border-cyan-500/30 rounded-lg p-4 bg-cyan-900/10 mt-4 relative overflow-hidden">
    <div class="absolute top-0 right-0 bg-cyan-600 text-xs px-2 py-1 rounded-bl-lg text-white font-bold">ACCESO</div>
    <h4 class="font-semibold text-sm uppercase mb-3 text-cyan-400"><i class="fas fa-shield-alt mr-2"></i>Control de Acceso por Sesión</h4>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div class="access-toggle-card">
            <div class="flex items-center gap-3">
                <span class="toggle-label-icon text-cyan-400"><i class="fas fa-sign-in-alt"></i></span>
                <div>
                    <div class="toggle-label-text" style="color: var(--text-primary);">Solo Usuarios Logeados</div>
                    <div class="toggle-label-hint" style="color: var(--text-secondary);">Exige inicio de sesión para usar el cupón</div>
                </div>
            </div>
            <label class="switch" style="flex-shrink:0;">
                <input type="checkbox" name="requiere_login" value="1" id="toggle_requiere_login">
                <span class="slider"></span>
            </label>
        </div>
        <div class="access-toggle-card">
            <div class="flex items-center gap-3">
                <span class="toggle-label-icon text-yellow-400"><i class="fas fa-user-check"></i></span>
                <div>
                    <div class="toggle-label-text" style="color: var(--text-primary);">Solo Cuentas Verificadas</div>
                    <div class="toggle-label-hint" style="color: var(--text-secondary);">Requiere login + verificación de cuenta</div>
                </div>
            </div>
            <label class="switch" style="flex-shrink:0;">
                <input type="checkbox" name="requiere_verificacion" value="1" id="toggle_requiere_verificacion">
                <span class="slider"></span>
            </label>
        </div>
    </div>
</div>

                <!-- Checks simples -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center mt-2">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="permite_pago_automatico" value="1" class="h-4 w-4 rounded bg-gray-700 border-gray-600"> Permitir Pago Automático (IziPay)</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="aplicar_a_un_solo_item" value="1" class="h-4 w-4 rounded bg-gray-700 border-gray-600"> Aplicar solo al 1er ítem</label>
                </div>
                
                <hr class="border-gray-700 my-4">

                <!-- Reglas de aplicabilidad -->
                <div>
                    <div class="flex justify-between items-center mb-2">
                         <h4 class="font-semibold text-sm uppercase">Reglas de Restricción</h4>
                         <label id="comboCheckboxContainer" class="hidden flex items-center gap-2 text-sm text-yellow-400 font-bold bg-yellow-400/10 px-2 py-1 rounded">
                            <input type="checkbox" name="requiere_todos_los_productos" value="1" class="h-4 w-4 rounded text-yellow-500 focus:ring-yellow-500"> MODO COMBO (Requerir todo)
                         </label>
                    </div>
                    <div id="ruleBuilderContainer" class="space-y-2 bg-black/20 p-3 rounded-lg border border-dashed border-gray-700 min-h-[50px]"></div>
                    <button type="button" id="addRuleBtn" class="mt-2 text-sm text-blue-400 hover:text-blue-300 flex items-center gap-2 px-2 py-1 hover:bg-blue-400/10 rounded transition"><i class="fas fa-plus-circle"></i>Añadir Regla</button>
                </div>

            </form>
        </div>
        <div class="p-4 border-t flex justify-end bg-black/20">
            <button type="button" id="saveCouponBtn" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-8 py-2 rounded-lg shadow-lg transition transform hover:scale-105">Guardar Cupón</button>
        </div>
    </div>
</div>

<script>
// REEMPLAZA TODO TU BLOQUE <script> CON ESTO
document.addEventListener("DOMContentLoaded", () => {
    // --- Definiciones de DOM, estado y constantes ---
    const dom = { 
        tableBody: document.getElementById('couponsTableBody'), 
        shopContainer: document.getElementById('shopItemsContainer'), 
        modal: document.getElementById('couponModal'), 
        openBtn: document.getElementById('openNewCouponBtn'), 
        closeBtn: document.getElementById('closeCouponModalBtn'), 
        form: document.getElementById('couponForm'), 
        title: document.getElementById('couponModalTitle'), 
        saveBtn: document.getElementById('saveCouponBtn'), 
        ruleBuilder: document.getElementById('ruleBuilderContainer'), 
        addRuleBtn: document.getElementById('addRuleBtn'), 
        floater: document.getElementById('coupon-cart-floater'), 
        cartCount: document.getElementById('couponCartCount'), 
        createFromCartBtn: document.getElementById('createCouponFromCartBtn'),
        tipoInicioSelect: document.getElementById('tipo_inicio'),
        inicioInput: document.getElementById('fecha_inicio_input'),
        tipoExpiracionSelect: document.getElementById('tipo_expiracion'),
        expiracionFieldsContainer: document.getElementById('expiracion_fields_container'),
        toastContainer: document.getElementById('toast-container'),
        // --- NUEVO ELEMENTO DOM PARA EL CHECKBOX DE COMBO ---
        comboCheckboxContainer: document.getElementById('comboCheckboxContainer')
    };
    let appData = { cupones: [], shopData: { sections: [] }, productosEstaticos: [], apiCategories: [], categoriasEstaticas: [] };
    let couponCart = new Set();
    const PRECIO_VENTA_MULT = 1.5; const PRECIO_BASE_MULT = 1.1;

    // --- Funciones de API & Inicialización (sin cambios) ---
    const apiCall = async (action, data = {}, method = 'POST') => {
        const url = `cupones/api/api_cupones.php`; const options = { method }; const formData = new FormData();
        formData.append('action', action); for (const key in data) { formData.append(key, data[key]); }
        if (method === 'POST') { options.body = formData; } else { options.url = `${url}?action=${action}&` + new URLSearchParams(data); }
        try { const response = await fetch(options.url || url, options); const result = await response.json(); if (!response.ok) throw new Error(result.message || 'Error en la petición'); return result; }
        catch (e) { console.error(`Error en apiCall(${action}):`, e); showToast(e.message, 'error'); throw e; }
    };
    
    const showToast = (message, type = 'success') => {
        const toast = document.createElement('div'); toast.className = `toast ${type}`;
        const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-times-circle';
        toast.innerHTML = `<i class="fas ${iconClass} toast-icon"></i><span>${message}</span>`;
        dom.toastContainer.appendChild(toast); toast.getBoundingClientRect(); toast.classList.add('show');
        setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
    };

    const initializePage = async () => {
        try {
            // Agregamos un timestamp '_: Date.now()' para evitar el caché del navegador
const result = await apiCall('get_initial_data', { _: Date.now() }, 'GET');
            appData = result.data;
            appData.cupones.forEach(c => c.reglas_aplicabilidad = JSON.parse(c.reglas_aplicabilidad || '[]'));
            renderAll();
        } catch (e) { /* El error ya se muestra en apiCall */ }
    };

    const renderAll = () => { 
        renderCouponsTable(); 
        renderShopGallery(); 
        populateFxSelectors();
        setupQuizLogic();
        setupAccessToggles();
    };

// --- PEGA ESTO JUSTO DEBAJO DE LA FUNCIÓN renderAll ---
    
    const populateFxSelectors = () => {
        const audioSel = document.getElementById('audio_selector');
        const stickerSel = document.getElementById('sticker_selector');
        
        // Limpiar selectores (manteniendo opciones base)
        audioSel.innerHTML = '<option value="">Sin Audio</option><option value="custom">🔗 URL Personalizada...</option>';
        stickerSel.innerHTML = '<option value="">Sin Imagen</option><option value="custom">🔗 URL Personalizada...</option>';

        // Llenar Audios (si existen en la respuesta de la API)
        if (appData.fxFiles && appData.fxFiles.audios) {
            appData.fxFiles.audios.forEach(file => {
                const name = file.split('/').pop(); // Extraer solo el nombre del archivo
                // Decodificar caracteres especiales (espacios, tildes) para que se vea bien
                const cleanName = decodeURIComponent(name); 
                audioSel.innerHTML += `<option value="${file}">🎵 ${cleanName}</option>`;
            });
        }

        // Llenar Stickers (si existen en la respuesta de la API)
        if (appData.fxFiles && appData.fxFiles.stickers) {
            appData.fxFiles.stickers.forEach(file => {
                const name = file.split('/').pop();
                const cleanName = decodeURIComponent(name);
                stickerSel.innerHTML += `<option value="${file}">🖼️ ${cleanName}</option>`;
            });
        }
        
        // Configurar los eventos de clic y cambio
        setupFxListeners();
    };

    const setupFxListeners = () => {
        const audioSel = document.getElementById('audio_selector');
        const audioInput = document.getElementById('audio_url_input');
        const stickerSel = document.getElementById('sticker_selector');
        const stickerInput = document.getElementById('imagen_url_input');
        const previewBtn = document.getElementById('previewAudioBtn');
        const stickerPreview = document.getElementById('sticker_preview_container');

        // Lógica Audio: Si eligen "custom", mostramos el input manual
        audioSel.onchange = () => {
            if (audioSel.value === 'custom') {
                audioInput.classList.remove('hidden');
                audioInput.focus();
            } else {
                audioInput.classList.add('hidden');
                audioInput.value = audioSel.value;
            }
        };
        
        // Lógica Sticker: Preview de imagen y input manual
        stickerSel.onchange = () => {
            if (stickerSel.value === 'custom') {
                stickerInput.classList.remove('hidden');
                stickerInput.focus();
                stickerPreview.innerHTML = '<i class="fas fa-link text-gray-500"></i>';
            } else {
                stickerInput.classList.add('hidden');
                stickerInput.value = stickerSel.value;
                if(stickerSel.value) {
                    stickerPreview.innerHTML = `<img src="${stickerSel.value}" class="w-full h-full object-cover">`;
                } else {
                    stickerPreview.innerHTML = '<i class="fas fa-image text-gray-500 text-xs"></i>';
                }
            }
        };

        // Botón Play
        previewBtn.onclick = () => {
            // Reproducir desde el input (si es custom) o desde el select
            const url = audioInput.value || audioSel.value; 
            if (url && url !== 'custom') {
                const audio = new Audio(url);
                audio.volume = 0.5;
                audio.play().catch(e => alert("Error al reproducir: " + e.message));
            }
        };
    };
    
    // ==========================================
    // === LÓGICA DEL CREADOR DE QUIZ (NUEVO) ===
    // ==========================================
    
    // 1. Inicializar Eventos del Quiz
    const setupQuizLogic = () => {
        const check = document.getElementById('enable_quiz_checkbox');
        const container = document.getElementById('quiz_config_container');
        const addBtn = document.getElementById('add_quiz_answer_btn');
        const penaltySel = document.getElementById('quiz_penalty_mode');
        
        // Toggle visibilidad general
        check.addEventListener('change', () => {
            container.classList.toggle('hidden', !check.checked);
            // Si se activa y no hay respuestas, añadir 2 por defecto
            if(check.checked && document.getElementById('quiz_answers_list').children.length === 0) {
                addQuizAnswerRow(); addQuizAnswerRow();
            }
        });

        // Toggle visibilidad del cooldown
        penaltySel.addEventListener('change', () => {
            document.getElementById('quiz_cooldown_field').classList.toggle('hidden', penaltySel.value !== 'cooldown');
        });

        // Añadir respuesta
        addBtn.addEventListener('click', () => addQuizAnswerRow());
    };

    // 2. Crear una fila de respuesta (HTML dinámico)
    const addQuizAnswerRow = (text = '', isCorrect = false) => {
        const list = document.getElementById('quiz_answers_list');
        const rowId = 'q_ans_' + Date.now() + Math.random().toString(36).substr(2, 5);
        
        const row = document.createElement('div');
        row.className = 'quiz-ans-row flex gap-2 items-center';
        row.innerHTML = `
            <input type="text" class="quiz-ans-text modal-input p-2 border w-full rounded-lg text-sm" placeholder="Respuesta..." value="${text.replace(/"/g, '&quot;')}">
            <label class="flex items-center gap-1 cursor-pointer bg-black/40 px-2 py-2 rounded border border-gray-600 hover:border-green-500 transition" title="Marcar como correcta">
                <input type="radio" name="quiz_correct_radio" class="quiz-ans-correct h-4 w-4 text-green-500 focus:ring-green-500 bg-gray-700 border-gray-500" ${isCorrect ? 'checked' : ''}>
                <span class="text-xs font-bold text-green-400">OK</span>
            </label>
            <button type="button" class="text-red-400 hover:text-red-300 p-2" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>
        `;
        list.appendChild(row);
    };

    // 3. Recopilar datos y generar JSON (Para Guardar)
    const getQuizConfigJSON = () => {
        if (!document.getElementById('enable_quiz_checkbox').checked) return null;

        const answers = [];
        document.querySelectorAll('.quiz-ans-row').forEach(row => {
            const text = row.querySelector('.quiz-ans-text').value.trim();
            if (text) {
                answers.push({
                    text: text,
                    correct: row.querySelector('.quiz-ans-correct').checked
                });
            }
        });

        // Validación básica: Si activa el quiz, debe tener pregunta y respuestas
        const question = document.getElementById('quiz_question').value.trim();
        if (!question && answers.length === 0) return null; // Si está vacío, no guardamos nada

        return JSON.stringify({
            enabled: true,
            question: question,
            answers: answers,
            time_limit: parseInt(document.getElementById('quiz_time_limit').value) || 30,
            max_attempts: parseInt(document.getElementById('quiz_max_attempts').value) || 1,
            randomize: document.getElementById('quiz_randomize').checked,
            penalty_mode: document.getElementById('quiz_penalty_mode').value,
            cooldown_seconds: parseInt(document.getElementById('quiz_cooldown_seconds').value) || 20,
            msg_fail: document.getElementById('quiz_msg_fail').value.trim(),
            msg_lose: document.getElementById('quiz_msg_lose').value.trim()
        });
    };

    // 4. Cargar datos desde JSON (Para Editar)
    const loadQuizConfig = (jsonString) => {
        // Resetear formulario
        document.getElementById('enable_quiz_checkbox').checked = false;
        document.getElementById('quiz_config_container').classList.add('hidden');
        document.getElementById('quiz_answers_list').innerHTML = '';
        
        // Valores por defecto
        document.getElementById('quiz_question').value = '';
        document.getElementById('quiz_time_limit').value = 30;
        document.getElementById('quiz_max_attempts').value = 1;
        document.getElementById('quiz_randomize').checked = true;
        document.getElementById('quiz_penalty_mode').value = 'block';
        document.getElementById('quiz_cooldown_field').classList.add('hidden');
        document.getElementById('quiz_cooldown_seconds').value = 20;
        document.getElementById('quiz_msg_fail').value = '';
        document.getElementById('quiz_msg_lose').value = '';

        if (!jsonString) return;

        try {
            const config = JSON.parse(jsonString);
            if (config && config.enabled) {
                document.getElementById('enable_quiz_checkbox').checked = true;
                document.getElementById('quiz_config_container').classList.remove('hidden');
                
                document.getElementById('quiz_question').value = config.question || '';
                document.getElementById('quiz_time_limit').value = config.time_limit || 30;
                document.getElementById('quiz_max_attempts').value = config.max_attempts || 1;
                document.getElementById('quiz_randomize').checked = !!config.randomize;
                document.getElementById('quiz_penalty_mode').value = config.penalty_mode || 'block';
                document.getElementById('quiz_cooldown_seconds').value = config.cooldown_seconds || 20;
                document.getElementById('quiz_msg_fail').value = config.msg_fail || '';
                document.getElementById('quiz_msg_lose').value = config.msg_lose || '';

                if (config.penalty_mode === 'cooldown') {
                    document.getElementById('quiz_cooldown_field').classList.remove('hidden');
                }

                if (config.answers && Array.isArray(config.answers)) {
                    config.answers.forEach(ans => addQuizAnswerRow(ans.text, ans.correct));
                }
            }
        } catch (e) { console.error("Error parsing quiz config", e); }
    };
    // ==========================================
    // === LÓGICA DE CONTROL DE ACCESO (TOGGLES) ===
    // ==========================================
    const setupAccessToggles = () => {
        const loginToggle = document.getElementById('toggle_requiere_login');
        const verifyToggle = document.getElementById('toggle_requiere_verificacion');

        verifyToggle.addEventListener('change', () => {
            if (verifyToggle.checked) {
                loginToggle.checked = true;
                loginToggle.disabled = true;
            } else {
                loginToggle.disabled = false;
            }
        });

        loginToggle.addEventListener('change', () => {
            if (!loginToggle.checked) {
                verifyToggle.checked = false;
            }
        });
    };

    const syncAccessTogglesState = () => {
        const loginToggle = document.getElementById('toggle_requiere_login');
        const verifyToggle = document.getElementById('toggle_requiere_verificacion');
        if (verifyToggle.checked) {
            loginToggle.checked = true;
            loginToggle.disabled = true;
        } else {
            loginToggle.disabled = false;
        }
    };

    // --- Funciones de Renderizado (sin cambios en su mayoría) ---
    const renderCouponsTable = () => {
        dom.tableBody.innerHTML = appData.cupones.map(c => {
            const getReglasDesc = (coupon) => {
                if (!coupon.reglas_aplicabilidad || coupon.reglas_aplicabilidad.length === 0) return 'Ninguna';
                const primeraRegla = coupon.reglas_aplicabilidad[0]; let desc = primeraRegla.valor;
                const opcionGlobal = dom.form.querySelector(`select[name="estrategia_seleccion"] option[value="${primeraRegla.tipo}"]`);
                if (opcionGlobal) { desc = opcionGlobal.textContent; }
                if (coupon.reglas_aplicabilidad.length > 1) { desc += ` (+${coupon.reglas_aplicabilidad.length - 1} más)`; }
                return `<div class="truncate text-sm" title="${desc}">${desc}</div>`;
            };
            const isChecked = parseInt(c.activo) === 1 ? 'checked' : '';
            const statusToggleHTML = `<label class="switch"><input type="checkbox" class="toggle-status-btn" data-id="${c.id}" data-status="${c.activo}" ${isChecked}><span class="slider"></span></label>`;
            return `<tr>
                <td class="p-3"><div class="font-bold">${c.codigo}</div><div class="text-xs text-gray-400">${c.descripcion||''}</div></td>
                <td class="p-3">${c.tipo_descuento === 'fijo' ? `S/ ${c.valor_descuento}` : `${c.valor_descuento}%`}</td><td class="p-3">${c.usos_actuales} / ${c.limite_usos || '∞'}</td>
                <td class="p-3">${getReglasDesc(c)}</td><td class="p-3">${statusToggleHTML}</td>
                <td class="p-3 text-right"><button data-id="${c.id}" class="edit-coupon-btn text-blue-400 p-2"><i class="fas fa-edit"></i></button><button data-id="${c.id}" class="delete-coupon-btn text-red-400 p-2"><i class="fas fa-trash"></i></button></td></tr>`;
        }).join('');
    };
    const renderShopGallery = () => { dom.shopContainer.innerHTML = appData.shopData.sections.map(section => `<div class="section-header col-span-full p-3 rounded-md mb-4 mt-6 flex justify-between items-center"><h2 class="text-xl font-bold text-white">${section.name}</h2><button data-section-name="${section.name}" class="apply-to-section-btn bg-yellow-500 text-black text-xs font-bold py-1 px-3 rounded-full hover:bg-yellow-400">Cupón para sección</button></div> ${section.entries.map(entry => renderItemCard(entry, section.name)).join('')}`).join(''); };
    const renderItemCard = (entry, sectionName) => {
        const item = entry.items[0]; const isSelected = couponCart.has(entry.offerId); const appliedCoupon = findCouponForProduct(entry, sectionName);
        const precioVenta = (entry.finalPrice / 100) * PRECIO_VENTA_MULT; const precioBase = (entry.finalPrice / 100) * PRECIO_BASE_MULT;
        let precioFinal = precioVenta;
        if (appliedCoupon) {
            let descuento = (appliedCoupon.tipo_descuento === 'porcentaje') ? precioVenta * (parseFloat(appliedCoupon.valor_descuento) / 100) : parseFloat(appliedCoupon.valor_descuento);
            if (appliedCoupon.descuento_maximo && descuento > parseFloat(appliedCoupon.descuento_maximo)) { descuento = parseFloat(appliedCoupon.descuento_maximo); }
            precioFinal = Math.max(0, precioVenta - descuento);
        }
        const ganancia = precioFinal - precioBase; let backgroundStyle = '';
        if (item.cardColors && item.cardColors[0]) { backgroundStyle = `style="background-image: linear-gradient(to bottom, ${item.cardColors[0]}, ${item.cardColors[1] || item.cardColors[0]});"`; }
        const buttonsHTML = appliedCoupon ? `<button data-coupon-id="${appliedCoupon.id}" class="edit-item-coupon-btn flex-1 bg-blue-600 text-white rounded-md px-3 py-1.5 text-sm">Editar Cupón</button><button data-coupon-id="${appliedCoupon.id}" class="delete-coupon-btn flex-initial bg-red-600 text-white rounded-md p-1.5 ml-2"><i class="fas fa-trash"></i></button>` : `<button data-offer-id="${entry.offerId}" class="add-item-coupon-btn w-full bg-green-600 text-white rounded-md px-3 py-1.5 text-sm">Añadir Cupón</button>`;
        const precioDisplayHTML = appliedCoupon ? `<div class="value text-green-400 font-bold">S/${precioFinal.toFixed(2)}</div><div class="text-xs line-through opacity-60">S/${precioVenta.toFixed(2)}</div>` : `<div class="value">S/${precioFinal.toFixed(2)}</div>`;
        return `<div class="item-card card rounded-xl shadow-md overflow-hidden transition-all card-hover border ${isSelected ? 'selected' : ''}" data-offer-id="${entry.offerId}"><div class="relative" ${backgroundStyle}><img src="${item.images.featured}" class="w-full aspect-square object-cover"><div class="absolute bottom-2 left-2 vbucks-container text-white px-2 py-1 rounded-md flex items-center gap-2"><img src="https://fortnite-api.com/images/vbuck.png" class="w-5 h-5"><span>${entry.finalPrice}</span></div>${appliedCoupon ? `<span class="absolute top-2 right-2 bg-purple-500 text-white text-xs font-bold px-2 py-1 rounded-md shadow-lg">${appliedCoupon.codigo}</span>` : ''}</div><div class="p-4 flex flex-col justify-between" style="min-height: 160px;"><div><h3 class="font-bold text-base truncate">${item.name}</h3><p class="text-xs mb-2" style="color:var(--text-secondary);">${item.type.displayValue}</p><div class="price-row"><div class="price-item"><div class="label">Precio</div>${precioDisplayHTML}</div><div class="price-item"><div class="label">Base</div><div class="value">S/${precioBase.toFixed(2)}</div></div><div class="price-item"><div class="label">Ganancia</div><div class="value ${ganancia >= 0 ? 'text-green-500' : 'text-red-500'}">S/${ganancia.toFixed(2)}</div></div></div></div><div class="mt-4 flex">${buttonsHTML}</div></div></div>`;
    };
    const findCouponForProduct = (entry, sectionName) => {
        const offerId = entry.offerId; const itemType = entry.items[0]?.type?.displayValue;
        const ruleCheckers = {
            isSpecific: r => r.tipo === 'producto_especifico' && r.valor === offerId,
            isSection: r => r.tipo === 'seccion_tienda' && r.valor === sectionName,
            isCategory: r => { if (r.tipo !== 'categoria_api') return false; if (r.valor === 'Lote' && itemType?.toLowerCase().includes('lote')) return true; return r.valor === itemType; },
            isGlobal: r => r.tipo === 'global',
            isGlobalNoStatic: r => r.tipo === 'global_sin_estaticos'
        };
        for (const checker of Object.values(ruleCheckers)) { const coupon = appData.cupones.find(c => c.reglas_aplicabilidad?.some(checker)); if (coupon) return coupon; }
        return null;
    };
    
    // --- LÓGICA DE INTERACCIÓN MODIFICADA ---
    
    const toggleCartItem = (offerId) => {
        const card = document.querySelector(`[data-offer-id="${offerId}"]`);
        if (couponCart.has(offerId)) { couponCart.delete(offerId); card.classList.remove('selected'); } 
        else { couponCart.add(offerId); card.classList.add('selected'); }
        dom.cartCount.textContent = couponCart.size; dom.floater.classList.toggle('visible', couponCart.size > 0);
    };

const openModalForEdit = (couponId) => {
        const coupon = appData.cupones.find(c => c.id == couponId);
        if (!coupon) return showToast("Cupón no encontrado", "error");
        
        dom.form.reset(); 
        dom.title.textContent = "Editar Cupón";
        
        // Rellenado básico
        for (const key in coupon) { 
            if (dom.form.elements[key]) {
                if(dom.form.elements[key].type === 'checkbox') {
                    dom.form.elements[key].checked = !!parseInt(coupon[key]);
                } else {
                    dom.form.elements[key].value = coupon[key];
                }
            }
        }
        
        // Lógica de fechas (Tu código original)
        dom.form.elements.id.value = coupon.id;
        const totalMinutosPago = parseInt(coupon.tiempo_limite_pago_minutos) || 0;
        if (totalMinutosPago > 0) {
            dom.form.elements.limite_pago_horas.value = Math.floor(totalMinutosPago / 60);
            dom.form.elements.limite_pago_minutos_ux.value = totalMinutosPago % 60;
        }
        dom.tipoInicioSelect.value = coupon.fecha_inicio ? 'especifico' : 'inmediato';
        dom.inicioInput.value = coupon.fecha_inicio ? coupon.fecha_inicio.replace(' ', 'T').slice(0, 16) : '';
        dom.tipoExpiracionSelect.value = coupon.fecha_expiracion ? 'exacta' : 'nunca';
        dom.expiracionFieldsContainer.querySelector('[name="fecha_expiracion_exacta"]').value = coupon.fecha_expiracion ? coupon.fecha_expiracion.replace(' ', 'T').slice(0, 16) : '';
        handleDateSelectorsLogic();

        // Reglas (Tu código original)
        dom.ruleBuilder.innerHTML = '';
        (coupon.reglas_aplicabilidad?.length > 0 ? coupon.reglas_aplicabilidad : [{tipo: 'global'}]).forEach(rule => createRuleRow(rule.tipo, rule.valor));
        updateComboCheckboxVisibility();

        // --- NUEVO: Lógica para Audio y Stickers al Editar ---
        const audioVal = coupon.audio_url || '';
        const stickerVal = coupon.imagen_url || '';
        const audioSel = document.getElementById('audio_selector');
        const stickerSel = document.getElementById('sticker_selector');

        // Si el valor guardado existe en la lista, lo seleccionamos. Si no, ponemos "custom".
        const audioExists = [...audioSel.options].some(o => o.value === audioVal);
        audioSel.value = audioExists ? audioVal : (audioVal ? 'custom' : '');
        // Forzamos el input manual si es custom
        if (!audioExists && audioVal) document.getElementById('audio_url_input').classList.remove('hidden');
        else document.getElementById('audio_url_input').classList.add('hidden');

        // Lo mismo para Stickers
        const stickerExists = [...stickerSel.options].some(o => o.value === stickerVal);
        stickerSel.value = stickerExists ? stickerVal : (stickerVal ? 'custom' : '');
        if (!stickerExists && stickerVal) document.getElementById('imagen_url_input').classList.remove('hidden');
        else document.getElementById('imagen_url_input').classList.add('hidden');

        // Actualizar la miniatura de la imagen
        const previewDiv = document.getElementById('sticker_preview_container');
        if(stickerVal) previewDiv.innerHTML = `<img src="${stickerVal}" class="w-full h-full object-cover">`;
        else previewDiv.innerHTML = '<i class="fas fa-image text-gray-500 text-xs"></i>';
        // ---------------------------------------------------
                // --- NUEVO: Cargar Configuración del Quiz ---
        loadQuizConfig(coupon.quiz_config);
        syncAccessTogglesState();
        dom.modal.classList.remove('hidden');
    };
    
    const openModalForNew = (config = {}) => {
        dom.form.reset(); dom.title.textContent = "Añadir Nuevo Cupón"; dom.ruleBuilder.innerHTML = ''; dom.form.elements.id.value = '';
        if (config.rules?.length > 0) {
            config.rules.forEach(rule => createRuleRow(rule.tipo, rule.valor));
            couponCart.clear(); document.querySelectorAll('.item-card.selected').forEach(card => card.classList.remove('selected'));
            dom.floater.classList.remove('visible');
        } else {
            createRuleRow(config.estrategia || 'global', config.valorInicial);
        }
        handleDateSelectorsLogic();
        updateComboCheckboxVisibility(); // <-- Actualizar visibilidad al abrir
        dom.modal.classList.remove('hidden');
                // --- NUEVO: Limpiar Quiz ---
        loadQuizConfig(null);
        syncAccessTogglesState();
    };

    // --- NUEVA FUNCIÓN: GESTIONA LA VISIBILIDAD DEL CHECKBOX DE COMBO ---
// REEMPLAZA ÚNICAMENTE ESTA FUNCIÓN EN TU JAVASCRIPT

const updateComboCheckboxVisibility = () => {
    // 1. Simplemente contamos cuántas filas de reglas hay en total.
    const totalRuleCount = dom.ruleBuilder.querySelectorAll('.rule-row').length;
    
    // 2. Mostramos la opción de "Combo" si hay 2 o más reglas, sin importar de qué tipo sean.
    dom.comboCheckboxContainer.classList.toggle('hidden', totalRuleCount < 2);
};

    // --- FUNCIÓN MODIFICADA: `createRuleRow` AHORA CREA UN SELECTOR DE PRODUCTOS ---
    const createRuleRow = (type = 'global', value = '') => {
        const row = document.createElement('div');
        row.className = 'grid grid-cols-[1fr,2fr,auto] gap-2 items-center rule-row';
        row.innerHTML = `<select name="estrategia_seleccion" class="modal-select p-2 border rounded-lg"></select><div class="valor-wrapper"></div><button type="button" class="delete-rule-btn text-red-400 p-2"><i class="fas fa-trash"></i></button>`;
        const estrategiaSelect = row.querySelector('[name="estrategia_seleccion"]');
        const valorWrapper = row.querySelector('.valor-wrapper');
        const estrategias = {'global': 'Global (Toda la tienda)', 'producto_especifico': 'Prod. Específico', 'seccion_tienda': 'Sección Tienda', 'categoria_api': 'Cat. API', 'global_sin_estaticos': 'Global (Sin Prod. Estáticos)', 'categoria_estatica': 'Cat. Estática', 'producto_estatico': 'Prod. Estático' };
        estrategiaSelect.innerHTML = Object.entries(estrategias).map(([key, text]) => `<option value="${key}">${text}</option>`).join('');
        estrategiaSelect.value = type;

        const updateValorOptions = () => {
            const currentType = estrategiaSelect.value; valorWrapper.innerHTML = '';
            
            // --- LÓGICA MEJORADA PARA "PRODUCTO ESPECÍFICO" ---
            if (currentType === 'producto_especifico') {
                const select = document.createElement('select');
                select.name = 'valor_seleccion'; select.className = 'modal-select p-2 border rounded-lg w-full';
                
                // Opción por defecto
                let optionsHTML = '<option value="">-- Selecciona un producto --</option>';

                // Agrupar productos por sección de tienda
                appData.shopData.sections.forEach(section => {
                    optionsHTML += `<optgroup label="${section.name}">`;
                    section.entries.forEach(entry => {
                        const itemName = entry.items[0].name;
                        optionsHTML += `<option value="${entry.offerId}">${itemName}</option>`;
                    });
                    optionsHTML += `</optgroup>`;
                });
                select.innerHTML = optionsHTML;
                select.value = value; // Pre-seleccionar si se pasó un valor
                valorWrapper.appendChild(select);

            } else if (currentType !== 'global' && currentType !== 'global_sin_estaticos') {
                const optionsMap = { 'categoria_estatica': appData.categoriasEstaticas.map(c => ({v: c, t: c})), 'categoria_api': appData.apiCategories.map(c => ({v: c, t: c})), 'seccion_tienda': appData.shopData.sections.map(s => ({v: s.name, t: s.name})), 'producto_estatico': appData.productosEstaticos.map(p => ({v: p.id, t: p.name})) };
                const select = document.createElement('select');
                select.name = 'valor_seleccion'; select.className = 'modal-select p-2 border rounded-lg w-full';
                select.innerHTML = (optionsMap[currentType] || []).map(o => `<option value="${o.v}">${o.t}</option>`).join('');
                select.value = value;
                valorWrapper.appendChild(select);
            }
            updateComboCheckboxVisibility(); // Actualizar al cambiar el tipo
        };
        
        estrategiaSelect.addEventListener('change', updateValorOptions);
        row.querySelector('.delete-rule-btn').addEventListener('click', () => {
            row.remove();
            updateComboCheckboxVisibility(); // Actualizar al borrar
        });
        
        updateValorOptions(); dom.ruleBuilder.appendChild(row);
    };
    
    const saveCoupon = async () => {
        // --- NUEVO: Recopilar JSON del Quiz ---
        const quizJson = getQuizConfigJSON();
        document.getElementById('quiz_config_input').value = quizJson || '';
        const formData = new FormData(dom.form);
        const horas = parseInt(formData.get('limite_pago_horas')) || 0; const minutos = parseInt(formData.get('limite_pago_minutos_ux')) || 0;
        const totalMinutos = (horas * 60) + minutos;
        if (totalMinutos > 0) { formData.append('tiempo_limite_pago_minutos', totalMinutos); }
        formData.delete('limite_pago_horas'); formData.delete('limite_pago_minutos_ux');
        
        const rulesData = Array.from(dom.ruleBuilder.querySelectorAll('.rule-row')).map(row => {
            const tipo = row.querySelector('[name="estrategia_seleccion"]').value;
            let valor = row.querySelector('[name="valor_seleccion"]')?.value || tipo; // Simplificado
            return { tipo, valor };
        }).filter(rule => rule.valor); // Filtrar reglas sin valor seleccionado
        
        formData.append('reglas_aplicabilidad', JSON.stringify(rulesData));

        try { const result = await apiCall('save_coupon', Object.fromEntries(formData)); showToast(result.message); if (result.status === 'success') { closeModal(); initializePage(); }
        } catch(e) { /* Error ya mostrado */ }
    };
    
    const deleteCoupon = async (id) => { if (!id) return; try { const result = await apiCall('delete_coupon', { id }); showToast(result.message); if (result.status === 'success') initializePage(); } catch(e) { /* Error ya mostrado */ } };
    
    const closeModal = () => dom.modal.classList.add('hidden');
    const handleDateSelectorsLogic = () => {
        dom.inicioInput.classList.toggle('hidden', dom.tipoInicioSelect.value === 'inmediato');
        Array.from(dom.expiracionFieldsContainer.children).forEach(child => child.classList.add('hidden'));
        switch(dom.tipoExpiracionSelect.value) {
            case 'especifica': dom.expiracionFieldsContainer.querySelector('[name="fecha_expiracion_especifica"]').classList.remove('hidden'); break;
            case 'temporizador': document.getElementById('temporizador_container').classList.remove('hidden'); break;
            case 'exacta': dom.expiracionFieldsContainer.querySelector('[name="fecha_expiracion_exacta"]').classList.remove('hidden'); break;
        }
    };

    // --- Delegación de Eventos ---
    dom.openBtn.addEventListener('click', () => openModalForNew());
    dom.closeBtn.addEventListener('click', closeModal);
    dom.saveBtn.addEventListener('click', saveCoupon);
    dom.addRuleBtn.addEventListener('click', () => {
        createRuleRow();
        updateComboCheckboxVisibility(); // Actualizar al añadir
    });
    dom.createFromCartBtn.addEventListener('click', () => {
        const rules = Array.from(couponCart).map(offerId => ({ tipo: 'producto_especifico', valor: offerId }));
        openModalForNew({ rules: rules });
    });
    dom.tipoInicioSelect.addEventListener('change', handleDateSelectorsLogic);
    dom.tipoExpiracionSelect.addEventListener('change', handleDateSelectorsLogic);
    
    document.body.addEventListener('click', async (e) => {
        const target = e.target;
        if (target.classList.contains('toggle-status-btn')) {
            const couponId = target.dataset.id; const newStatus = target.checked ? 1 : 0;
            try {
                const response = await apiCall('toggle_status', { id: couponId, status: newStatus });
                const couponIndex = appData.cupones.findIndex(c => c.id == couponId);
                if (couponIndex > -1) appData.cupones[couponIndex].activo = newStatus;
                showToast(response.message);
            } catch (err) { target.checked = !target.checked; }
            return;
        }
        
        const editBtn = target.closest('.edit-coupon-btn, .edit-item-coupon-btn');
        if (editBtn) { e.preventDefault(); return openModalForEdit(editBtn.dataset.id || editBtn.dataset.couponId); }
        
        const deleteBtn = target.closest('.delete-coupon-btn');
        if (deleteBtn) { e.preventDefault(); if (confirm('¿Estás seguro de que quieres eliminar este cupón?')) { return deleteCoupon(deleteBtn.dataset.id || deleteBtn.dataset.couponId); } }

        const sectionBtn = target.closest('.apply-to-section-btn');
        if (sectionBtn) { e.preventDefault(); return openModalForNew({ estrategia: 'seccion_tienda', valorInicial: sectionBtn.dataset.sectionName }); }

        const itemAddBtn = target.closest('.add-item-coupon-btn');
        if (itemAddBtn) { e.preventDefault(); return openModalForNew({ estrategia: 'producto_especifico', valorInicial: itemAddBtn.dataset.offerId }); }

        const itemCard = target.closest('.item-card');
        if (itemCard && !target.closest('button')) { e.preventDefault(); toggleCartItem(itemCard.dataset.offerId); }
    });

    initializePage();
});
</script>

<?php 
include __DIR__ . '/../core/admin_footer.php'; 
?>