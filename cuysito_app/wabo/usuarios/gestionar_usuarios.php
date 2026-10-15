<?php
require_once __DIR__ . '/../core/admin_header.php';
?>

<style>
    @keyframes fadeInUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
    @keyframes countUp { from { opacity:0; transform:scale(0.8); } to { opacity:1; transform:scale(1); } }
    @keyframes shimmer { 0% { background-position:-200% 0; } 100% { background-position:200% 0; } }
    @keyframes pulseGlow { 0%,100% { box-shadow:0 0 0 0 rgba(0,178,255,0.3); } 50% { box-shadow:0 0 12px 4px rgba(0,178,255,0.15); } }
    .anim-fade-up { animation: fadeInUp 0.4s ease-out both; }
    .anim-d1 { animation-delay:.05s } .anim-d2 { animation-delay:.1s } .anim-d3 { animation-delay:.15s } .anim-d4 { animation-delay:.2s }
    .anim-count { animation: countUp 0.5s ease-out both; }
    .skeleton { background: linear-gradient(90deg, rgba(128,128,128,0.1) 25%, rgba(128,128,128,0.2) 50%, rgba(128,128,128,0.1) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 6px; }
    .kpi-card { transition: transform 0.2s, box-shadow 0.2s; } .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px -5px rgba(0,0,0,0.15); }
    .modal-enter { animation: fadeInUp 0.3s ease-out; }
    .tab-pill { transition: all 0.25s; } .tab-pill.active-pill { background: linear-gradient(135deg,#00B2FF,#C13CFF); color: white !important; box-shadow: 0 4px 15px -3px rgba(0,178,255,0.4); } .tab-pill:not(.active-pill):hover { background: rgba(128,128,128,0.1); }
    .field-group { transition: all 0.2s; border-radius:8px; padding:6px 8px; } .field-group:hover { background: rgba(128,128,128,0.05); }
    .btn-modern { transition: all 0.2s; overflow: hidden; } .btn-modern:active { transform: scale(0.97); }
    .table-row-anim { animation: fadeInUp 0.3s ease-out both; }
    .status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .tooltip-wrap { position:relative; } .tooltip-wrap .tt { visibility:hidden; opacity:0; position:absolute; bottom:100%; left:50%; transform:translateX(-50%); background:#1a1a1a; color:white; padding:4px 10px; border-radius:6px; font-size:11px; white-space:nowrap; transition:opacity 0.2s; z-index:100; margin-bottom:6px; pointer-events:none; }
    .tooltip-wrap .tt::after { content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%); border:5px solid transparent; border-top-color:#1a1a1a; } .tooltip-wrap:hover .tt { visibility:visible; opacity:1; }
    .input-m { transition: border-color 0.2s, box-shadow 0.2s; } .input-m:focus { border-color:#00B2FF; box-shadow:0 0 0 3px rgba(0,178,255,0.15); }
    #userModal .modal-body { max-height:75vh; overflow-y:auto; scrollbar-width:thin; }
    #userModal .modal-body::-webkit-scrollbar { width:6px; } #userModal .modal-body::-webkit-scrollbar-thumb { background:rgba(128,128,128,0.3); border-radius:3px; }
</style>

<div class="max-w-[1400px] mx-auto">

<!-- KPI PANEL -->
<div id="kpiPanel" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="skeleton h-20 rounded-xl"></div><div class="skeleton h-20 rounded-xl"></div><div class="skeleton h-20 rounded-xl"></div><div class="skeleton h-20 rounded-xl"></div>
</div>

<!-- HEADER + SEARCH -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-5 gap-3 anim-fade-up">
    <div>
        <h2 class="text-2xl font-bold dark:text-white flex items-center gap-2"><i class="fas fa-users text-fortnite-purple"></i> Usuarios Registrados</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Administra cuentas, datos, saldos y permisos.</p>
    </div>
    <button onclick="loadKPIs()" class="tooltip-wrap btn-modern px-3 py-2 rounded-lg border dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 text-sm"><i class="fas fa-sync-alt"></i><span class="tt">Refrescar KPIs</span></button>
</div>

<div class="card rounded-xl p-4 mb-5 anim-fade-up anim-d1">
    <div class="flex flex-col md:flex-row gap-3">
        <div class="relative flex-grow">
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
            <input type="text" id="searchInput" placeholder="Buscar por nombre, nickname, email, codigo, telefono..." class="input-m w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white focus:outline-none text-sm">
        </div>
        <select id="statusFilter" class="input-m py-2.5 px-3 border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm text-gray-700 dark:text-gray-200 focus:outline-none min-w-[140px]">
            <option value="">Todos</option><option value="active">Activos</option><option value="suspended">Suspendidos</option><option value="banned">Baneados</option>
        </select>
        <button onclick="loadUsers(1)" class="btn-modern bg-gradient-to-r from-fortnite-blue to-fortnite-purple text-white px-6 py-2.5 rounded-lg font-medium text-sm shadow-lg hover:shadow-blue-500/25"><i class="fas fa-search mr-1.5"></i> Buscar</button>
        <button onclick="toggleAdvFilters()" id="advToggle" class="btn-modern px-3 py-2.5 rounded-lg border dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 text-sm"><i class="fas fa-sliders-h mr-1.5"></i>Filtros</button>
    </div>

    <!-- FILTROS AVANZADOS -->
    <div id="advFilters" class="hidden mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <div>
                <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1 uppercase">Orden</label>
                <select id="sortFilter" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm text-gray-700 dark:text-gray-200">
                    <option value="recent">Recientes primero</option>
                    <option value="oldest">Antiguos primero</option>
                    <option value="spent">Mayor gasto</option>
                    <option value="orders">Más compras</option>
                    <option value="verified">Verificados primero</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1 uppercase">Verificado</label>
                <select id="verifiedFilter" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm text-gray-700 dark:text-gray-200">
                    <option value="">Todos</option>
                    <option value="1">Solo verificados</option>
                    <option value="0">No verificados</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1 uppercase">Compras desde</label>
                <input type="date" id="pdateFrom" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm text-gray-700 dark:text-gray-200">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1 uppercase">Compras hasta</label>
                <input type="date" id="pdateTo" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm text-gray-700 dark:text-gray-200">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1 uppercase">Mín. compras</label>
                <input type="number" min="0" id="minOrders" placeholder="ej. 1" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm text-gray-700 dark:text-gray-200">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1 uppercase">Mín. gasto (S/)</label>
                <input type="number" min="0" step="0.01" id="minSpent" placeholder="ej. 50" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm text-gray-700 dark:text-gray-200">
            </div>
        </div>
        <div class="flex items-center gap-4 mt-3 flex-wrap">
            <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" id="hasManualFilter" class="rounded border-gray-300 text-fortnite-blue focus:ring-fortnite-blue">
                Solo con compras manuales
            </label>
            <span class="text-[11px] text-gray-400"><i class="fas fa-info-circle mr-1"></i>Mín. compras/gasto suman pedidos web + compras manuales (según el rango de fechas). Útil para ver "aptos por filtro".</span>
            <button onclick="resetFilters()" class="ml-auto btn-modern text-xs px-3 py-1.5 rounded-lg border dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-eraser mr-1"></i>Limpiar</button>
        </div>
    </div>
</div>

<!-- TABLE -->
<div class="card rounded-xl overflow-hidden anim-fade-up anim-d2">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase">Usuario</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase">Display Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase hidden lg:table-cell">Contacto</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase">Estado</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase hidden md:table-cell">Pais</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase">Coins</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase hidden xl:table-cell">Registro</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody id="usersTableBody" class="divide-y divide-gray-200 dark:divide-gray-700"></tbody>
        </table>
    </div>
    <div id="pagination" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-center gap-2 bg-gray-50 dark:bg-gray-800/50"></div>
</div>
</div>

<!-- MODAL -->
<div id="userModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
<div class="flex items-start justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" onclick="closeModal()"></div>
    <div class="relative w-full max-w-3xl my-8 modal-enter">
        <div class="card rounded-2xl overflow-hidden shadow-2xl border dark:border-gray-600">

            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-fortnite-blue/10 to-fortnite-purple/10 dark:from-fortnite-blue/5 dark:to-fortnite-purple/5 px-6 py-5 border-b dark:border-gray-700">
                <div class="flex justify-between items-start">
                    <div class="flex items-center gap-4">
                        <div id="modalAvatar" class="h-14 w-14 rounded-xl bg-gradient-to-br from-fortnite-blue to-fortnite-purple flex items-center justify-center text-white text-xl font-bold shadow-lg">?</div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2" id="modalDisplayName">Cargando...</h3>
                            <div class="flex items-center gap-3 mt-1 flex-wrap">
                                <span class="text-xs text-gray-500 dark:text-gray-400 font-mono bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded" id="modalPublicCode">...</span>
                                <span id="modalVerifiedBadge" class="hidden text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"><i class="fas fa-check-circle mr-1"></i>Verificado</span>
                                <span id="modalStatusBadge" class="text-xs font-bold px-2 py-0.5 rounded-full">...</span>
                            </div>
                        </div>
                    </div>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1 rounded-lg hover:bg-gray-200/50 dark:hover:bg-gray-700/50"><i class="fas fa-times text-lg"></i></button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="px-6 pt-4 pb-0 flex flex-wrap gap-2" id="modalTabs">
                <button onclick="switchTab('overview')" data-tab="overview" class="tab-pill active-pill px-4 py-2 rounded-full text-xs font-semibold text-gray-600 dark:text-gray-300"><i class="fas fa-eye mr-1.5"></i>General</button>
                <button onclick="switchTab('identity')" data-tab="identity" class="tab-pill px-4 py-2 rounded-full text-xs font-semibold text-gray-600 dark:text-gray-300"><i class="fas fa-id-card mr-1.5"></i>Identidad</button>
                <button onclick="switchTab('security')" data-tab="security" class="tab-pill px-4 py-2 rounded-full text-xs font-semibold text-gray-600 dark:text-gray-300"><i class="fas fa-shield-alt mr-1.5"></i>Seguridad</button>
                <button onclick="switchTab('coins')" data-tab="coins" class="tab-pill px-4 py-2 rounded-full text-xs font-semibold text-gray-600 dark:text-gray-300"><i class="fas fa-coins mr-1.5"></i>Monedas</button>
                <button onclick="switchTab('orders')" data-tab="orders" class="tab-pill px-4 py-2 rounded-full text-xs font-semibold text-gray-600 dark:text-gray-300"><i class="fas fa-shopping-bag mr-1.5"></i>Pedidos</button>
                <button onclick="switchTab('purchases')" data-tab="purchases" class="tab-pill px-4 py-2 rounded-full text-xs font-semibold text-gray-600 dark:text-gray-300"><i class="fas fa-receipt mr-1.5"></i>Compras Manuales</button>
                <button onclick="switchTab('meta')" data-tab="meta" class="tab-pill px-4 py-2 rounded-full text-xs font-semibold text-gray-600 dark:text-gray-300"><i class="fas fa-database mr-1.5"></i>Metadata</button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body px-6 py-5">

                <!-- TAB: OVERVIEW -->
                <div id="tab-overview" class="tab-content">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                        <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-3 text-center"><p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Saldo</p><p class="text-lg font-bold text-yellow-500" id="ovBalance">-</p></div>
                        <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-3 text-center"><p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Bonus Spins</p><p class="text-lg font-bold text-fortnite-purple" id="ovSpins">-</p></div>
                        <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-3 text-center"><p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Pedidos</p><p class="text-lg font-bold text-fortnite-blue" id="ovOrders">-</p></div>
                        <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-3 text-center"><p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Ultimo Login</p><p class="text-sm font-semibold text-gray-700 dark:text-gray-200" id="ovLastLogin">-</p></div>
                    </div>
                    <!-- Resumen de elegibilidad (web + manual) -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                        <div class="rounded-xl p-3 text-center bg-gradient-to-br from-emerald-500/10 to-green-600/5 border border-emerald-200/40 dark:border-emerald-800/30"><p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">Compras Totales</p><p class="text-lg font-bold text-emerald-600 dark:text-emerald-400" id="ovTotalOrders">-</p><p class="text-[10px] text-gray-400" id="ovOrdersBreak">web 0 · man 0</p></div>
                        <div class="rounded-xl p-3 text-center bg-gradient-to-br from-emerald-500/10 to-green-600/5 border border-emerald-200/40 dark:border-emerald-800/30"><p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">Gasto Total</p><p class="text-lg font-bold text-emerald-600 dark:text-emerald-400" id="ovTotalSpent">-</p><p class="text-[10px] text-gray-400" id="ovSpentBreak">web 0 · man 0</p></div>
                        <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-3 text-center"><p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">Compras Manuales</p><p class="text-lg font-bold text-amber-500" id="ovManualOrders">-</p></div>
                        <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-3 text-center"><p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">Última Compra Web</p><p class="text-sm font-semibold text-gray-700 dark:text-gray-200" id="ovLastPurchase">-</p></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
                        <div class="field-group"><label class="text-xs font-semibold text-gray-400 uppercase">Game Username</label><p class="text-sm font-medium text-gray-800 dark:text-gray-100 mt-0.5" id="ovGameUser">-</p></div>
                        <div class="field-group"><label class="text-xs font-semibold text-gray-400 uppercase">Display Name</label><p class="text-sm font-medium text-gray-800 dark:text-gray-100 mt-0.5" id="ovDispName">-</p></div>
                        <div class="field-group"><label class="text-xs font-semibold text-gray-400 uppercase">Email</label><p class="text-sm font-medium text-gray-800 dark:text-gray-100 mt-0.5" id="ovEmail">-</p></div>
                        <div class="field-group"><label class="text-xs font-semibold text-gray-400 uppercase">WhatsApp</label><p class="text-sm font-medium text-gray-800 dark:text-gray-100 mt-0.5" id="ovPhone">-</p></div>
                        <div class="field-group"><label class="text-xs font-semibold text-gray-400 uppercase">Pais</label><p class="text-sm font-medium text-gray-800 dark:text-gray-100 mt-0.5" id="ovCountry">-</p></div>
                        <div class="field-group"><label class="text-xs font-semibold text-gray-400 uppercase">Registro</label><p class="text-sm font-medium text-gray-800 dark:text-gray-100 mt-0.5" id="ovCreated">-</p></div>
                    </div>
                    <div class="mt-5 pt-4 border-t dark:border-gray-700 flex flex-wrap gap-2">
                        <button onclick="switchTab('security')" class="btn-modern text-xs px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-shield-alt mr-1.5"></i>Editar Estado</button>
                        <button onclick="switchTab('identity')" class="btn-modern text-xs px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-edit mr-1.5"></i>Editar Datos</button>
                        <button onclick="switchTab('coins')" class="btn-modern text-xs px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-coins mr-1.5"></i>Ajustar Coins</button>
                    </div>
                </div>

                <!-- TAB: IDENTITY -->
                <div id="tab-identity" class="tab-content hidden">
                    <p class="text-xs text-gray-400 mb-4"><i class="fas fa-info-circle mr-1"></i>Modifica los datos de identidad del usuario.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Game Username</label><input type="text" id="fldGameUsername" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Game Name Mode</label><input type="text" id="fldGameNameMode" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Public Display Name</label><input type="text" id="fldDisplayName" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Public Name Mode</label><input type="text" id="fldPublicNameMode" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Email</label><input type="email" id="fldEmail" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">WhatsApp (E.164)</label><input type="text" id="fldPhone" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none" placeholder="+51999999999"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Phone Mode</label><input type="text" id="fldPhoneMode" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Pais (ISO2)</label><input type="text" id="fldCountry" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none" placeholder="PE" maxlength="2"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Avatar ID</label><input type="number" id="fldAvatarId" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Bonus Spins</label><input type="number" id="fldBonusSpins" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none" min="0"></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mt-5 mb-3">Bloqueos de campos</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <label class="flex items-center gap-2 cursor-pointer bg-gray-50 dark:bg-gray-700/40 rounded-lg px-3 py-2.5"><input type="checkbox" id="fldGameLocked" class="rounded border-gray-300 text-fortnite-blue focus:ring-fortnite-blue"><span class="text-xs text-gray-700 dark:text-gray-200">Game Username Locked</span></label>
                        <label class="flex items-center gap-2 cursor-pointer bg-gray-50 dark:bg-gray-700/40 rounded-lg px-3 py-2.5"><input type="checkbox" id="fldPublicLocked" class="rounded border-gray-300 text-fortnite-blue focus:ring-fortnite-blue"><span class="text-xs text-gray-700 dark:text-gray-200">Public Name Locked</span></label>
                        <label class="flex items-center gap-2 cursor-pointer bg-gray-50 dark:bg-gray-700/40 rounded-lg px-3 py-2.5"><input type="checkbox" id="fldWhatsappLocked" class="rounded border-gray-300 text-fortnite-blue focus:ring-fortnite-blue"><span class="text-xs text-gray-700 dark:text-gray-200">WhatsApp Locked</span></label>
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 dark:bg-gray-700/40 rounded-lg px-3 py-2.5 mt-3"><input type="checkbox" id="fldBenefits" class="rounded border-gray-300 text-fortnite-blue focus:ring-fortnite-blue"><span class="text-xs text-gray-700 dark:text-gray-200">Benefits Enabled</span></label>
                    <div class="mt-5 flex justify-end"><button onclick="saveUserFields()" class="btn-modern bg-gradient-to-r from-fortnite-blue to-fortnite-purple text-white px-6 py-2.5 rounded-lg font-medium text-sm shadow-lg hover:shadow-blue-500/25"><i class="fas fa-save mr-1.5"></i> Guardar Identidad</button></div>
                </div>

                <!-- TAB: SECURITY -->
                <div id="tab-security" class="tab-content hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Estado de Cuenta</label>
                            <select id="modalStatus" class="input-m w-full py-2.5 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"><option value="active">Activo</option><option value="suspended">Suspendido</option><option value="banned">Baneado</option></select></div>
                        <div><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Verificacion</label>
                            <button id="btnVerify" onclick="toggleVerify()" class="btn-modern w-full inline-flex justify-center items-center rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2.5 bg-white dark:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 cursor-pointer">...</button></div>
                    </div>
                    <div class="mt-4"><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Ban Until (opcional)</label><input type="datetime-local" id="modalBanUntil" class="input-m w-full py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></div>
                    <div class="mt-4"><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Motivo (Baneo/Suspension)</label><textarea id="modalReason" rows="3" placeholder="Razon obligatoria si se suspende o banea..." class="input-m w-full p-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></textarea></div>
                    <div class="mt-4"><label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Notas de Verificacion</label><textarea id="modalVerifyNotes" rows="2" placeholder="Notas internas..." class="input-m w-full p-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none"></textarea></div>
                    <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">Info de Seguridad</p>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div><span class="text-gray-400">Ultimo Login:</span> <span class="text-gray-700 dark:text-gray-200" id="secLastLogin">-</span></div>
                            <div><span class="text-gray-400">IP:</span> <span class="text-gray-700 dark:text-gray-200 font-mono" id="secLastIP">-</span></div>
                            <div><span class="text-gray-400">Baneado el:</span> <span class="text-gray-700 dark:text-gray-200" id="secBannedAt">-</span></div>
                            <div><span class="text-gray-400">Verificado por:</span> <span class="text-gray-700 dark:text-gray-200" id="secVerifiedBy">-</span></div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end"><button onclick="saveUserStatus()" class="btn-modern bg-gradient-to-r from-fortnite-blue to-fortnite-purple text-white px-6 py-2.5 rounded-lg font-medium text-sm shadow-lg hover:shadow-blue-500/25"><i class="fas fa-save mr-1.5"></i> Guardar Seguridad</button></div>
                </div>

                <!-- TAB: COINS -->
                <div id="tab-coins" class="tab-content hidden">
                    <div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/10 dark:to-amber-900/10 p-5 rounded-xl mb-5 flex justify-between items-center border border-yellow-200/50 dark:border-yellow-800/30">
                        <div><p class="text-xs font-semibold text-yellow-700 dark:text-yellow-300 uppercase">Saldo Actual</p><p class="text-3xl font-bold text-yellow-600 dark:text-yellow-400 mt-1" id="modalBalance">0.00</p></div>
                        <button onclick="toggleCoinAdjust()" class="btn-modern text-sm bg-yellow-500 text-white px-4 py-2.5 rounded-lg hover:bg-yellow-600 shadow-lg font-medium"><i class="fas fa-sliders-h mr-1.5"></i> Ajustar</button>
                    </div>
                    <div id="coinAdjustForm" class="hidden bg-gray-50 dark:bg-gray-700/40 p-4 rounded-xl mb-5 border dark:border-gray-600 anim-fade-up">
                        <h4 class="text-xs font-bold uppercase mb-3 text-gray-500 dark:text-gray-400">Nuevo Ajuste</h4>
                        <div class="flex flex-col gap-3">
                            <div class="flex gap-3"><input type="number" id="adjustAmount" placeholder="Cantidad (+/-)" class="input-m w-1/3 p-2.5 rounded-lg border dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm focus:outline-none"><input type="text" id="adjustNote" placeholder="Razon (ej. Reembolso)" class="input-m w-2/3 p-2.5 rounded-lg border dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm focus:outline-none"></div>
                            <button onclick="submitCoinAdjust()" class="btn-modern w-full bg-gray-800 dark:bg-gray-900 text-white py-2.5 rounded-lg text-sm hover:bg-black font-medium">Confirmar</button>
                        </div>
                    </div>
                    <h4 class="font-bold text-gray-700 dark:text-gray-300 text-xs uppercase mb-3">Historial</h4>
                    <div class="overflow-y-auto max-h-52 border rounded-xl dark:border-gray-600">
                        <table class="min-w-full text-xs"><thead class="bg-gray-100 dark:bg-gray-700 sticky top-0"><tr><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Fecha</th><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Motivo</th><th class="px-3 py-2.5 text-right font-semibold dark:text-gray-200">Monto</th></tr></thead>
                        <tbody id="transactionsTable" class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800"></tbody></table>
                    </div>
                </div>

                <!-- TAB: ORDERS -->
                <div id="tab-orders" class="tab-content hidden">
                    <h4 class="font-bold text-gray-700 dark:text-gray-300 text-xs uppercase mb-3">Ultimos Pedidos</h4>
                    <div class="overflow-y-auto max-h-72 border rounded-xl dark:border-gray-600">
                        <table class="min-w-full text-xs"><thead class="bg-gray-100 dark:bg-gray-700 sticky top-0"><tr><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Codigo</th><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Fecha</th><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Estado</th><th class="px-3 py-2.5 text-right font-semibold dark:text-gray-200">Total</th></tr></thead>
                        <tbody id="userOrdersTable" class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800"></tbody></table>
                    </div>
                </div>

                <!-- TAB: PURCHASES (Compras Manuales - Punto 5) -->
                <div id="tab-purchases" class="tab-content hidden">
                    <div class="flex items-start justify-between gap-3 mb-4 flex-wrap">
                        <div>
                            <h4 class="font-bold text-gray-700 dark:text-gray-300 text-sm">Compras Manuales (dinero real por chat)</h4>
                            <p class="text-xs text-gray-400 mt-1 max-w-xl"><i class="fas fa-info-circle mr-1"></i>Registra aquí las compras hechas por WhatsApp/Messenger. Suman a la elegibilidad de los sorteos según su <b>fecha de compra</b>. No otorgan estado VIP ni beneficios económicos.</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[11px] text-gray-400 uppercase font-semibold">Total Manual</p>
                            <p class="text-lg font-bold text-amber-500"><span id="mpCount">0</span> · S/ <span id="mpSum">0.00</span></p>
                        </div>
                    </div>

                    <!-- Formulario -->
                    <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-4 mb-4 border dark:border-gray-600">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3 uppercase"><i class="fas fa-plus-circle mr-1 text-emerald-500"></i>Agregar compra manual</p>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <div><label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Fecha de compra</label><input type="date" id="mpDate" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white"></div>
                            <div><label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Monto (S/)</label><input type="number" min="0" step="0.01" id="mpAmount" placeholder="0.00" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white"></div>
                            <div><label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Canal</label>
                                <select id="mpChannel" class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white">
                                    <option value="whatsapp">WhatsApp</option><option value="messenger">Messenger</option><option value="manual">Manual</option><option value="other">Otro</option>
                                </select></div>
                            <div><label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Descripción (opcional)</label><input type="text" id="mpNote" maxlength="255" placeholder="Ref. / comprobante..." class="input-m w-full py-2 px-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded-lg text-sm text-gray-900 dark:text-white"></div>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button onclick="addManualPurchase()" class="btn-modern bg-gradient-to-r from-emerald-500 to-green-600 text-white px-5 py-2 rounded-lg font-medium text-sm shadow-lg hover:shadow-emerald-500/25"><i class="fas fa-save mr-1.5"></i>Registrar compra</button>
                        </div>
                    </div>

                    <!-- Historial -->
                    <div class="overflow-y-auto max-h-72 border rounded-xl dark:border-gray-600">
                        <table class="min-w-full text-xs"><thead class="bg-gray-100 dark:bg-gray-700 sticky top-0"><tr><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Fecha</th><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Canal</th><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Descripción</th><th class="px-3 py-2.5 text-right font-semibold dark:text-gray-200">Monto</th><th class="px-3 py-2.5 text-center font-semibold dark:text-gray-200"></th></tr></thead>
                        <tbody id="manualPurchasesTable" class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800"></tbody></table>
                    </div>
                </div>

                <!-- TAB: METADATA -->
                <div id="tab-meta" class="tab-content hidden">
                    <p class="text-xs text-gray-400 mb-4"><i class="fas fa-info-circle mr-1"></i>Campos de solo lectura y datos internos del sistema.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3" id="metaFieldsGrid"></div>
                    <div class="mt-5"><h4 class="font-bold text-gray-700 dark:text-gray-300 text-xs uppercase mb-3">Log Admin</h4>
                        <div class="overflow-y-auto max-h-48 border rounded-xl dark:border-gray-600">
                            <table class="min-w-full text-xs"><thead class="bg-gray-100 dark:bg-gray-700 sticky top-0"><tr><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Fecha</th><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Accion</th><th class="px-3 py-2.5 text-left font-semibold dark:text-gray-200">Detalles</th></tr></thead>
                            <tbody id="adminLogTable" class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800"></tbody></table>
                        </div>
                    </div>
                </div>

            </div><!-- /modal-body -->
        </div>
    </div>
</div>
</div>

<!-- TOAST -->
<div id="customToast" class="fixed top-6 right-6 z-[1100] hidden">
    <div class="flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl border text-sm font-medium anim-fade-up" id="toastInner"><i id="toastIcon" class="fas fa-check-circle"></i><span id="toastMsg">OK</span></div>
</div>

<script>
const API='/wabo/usuarios/api_usuarios.php';
let currentUserData=null, currentPage=1;

document.addEventListener('DOMContentLoaded',()=>{
    loadKPIs(); loadUsers(1);
    document.getElementById('searchInput').addEventListener('keypress',e=>{if(e.key==='Enter')loadUsers(1)});
    document.getElementById('statusFilter').addEventListener('change',()=>loadUsers(1));
    ['sortFilter','verifiedFilter','hasManualFilter'].forEach(id=>{const el=document.getElementById(id);if(el)el.addEventListener('change',()=>loadUsers(1));});
    ['pdateFrom','pdateTo','minOrders','minSpent'].forEach(id=>{const el=document.getElementById(id);if(el)el.addEventListener('keypress',e=>{if(e.key==='Enter')loadUsers(1)});});
});

function toggleAdvFilters(){const f=document.getElementById('advFilters');f.classList.toggle('hidden');document.getElementById('advToggle').classList.toggle('bg-fortnite-blue/10');}
function resetFilters(){
    ['searchInput','pdateFrom','pdateTo','minOrders','minSpent','statusFilter','verifiedFilter'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    document.getElementById('sortFilter').value='recent';
    const hm=document.getElementById('hasManualFilter');if(hm)hm.checked=false;
    loadUsers(1);
}

function fmtDate(d){if(!d)return'-';try{return new Date(d).toLocaleDateString('es-PE',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch{return d}}
function fmtShort(d){if(!d)return'-';try{return new Date(d).toLocaleDateString('es-PE',{day:'2-digit',month:'short',year:'2-digit'})}catch{return d}}

function showToast(msg,type='success'){
    const el=document.getElementById('customToast'),inner=document.getElementById('toastInner'),icon=document.getElementById('toastIcon');
    document.getElementById('toastMsg').textContent=msg;
    let cls='flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl border text-sm font-medium anim-fade-up ';
    if(type==='success'){cls+='bg-green-50 dark:bg-green-900/30 border-green-200 dark:border-green-800 text-green-800 dark:text-green-200';icon.className='fas fa-check-circle text-green-500';}
    else if(type==='error'){cls+='bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200';icon.className='fas fa-exclamation-circle text-red-500';}
    else{cls+='bg-blue-50 dark:bg-blue-900/30 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-200';icon.className='fas fa-info-circle text-blue-500';}
    inner.className=cls;el.classList.remove('hidden');clearTimeout(el._t);el._t=setTimeout(()=>el.classList.add('hidden'),3500);
}

// KPIs
async function loadKPIs(){
    try{const res=await fetch(`${API}?action=get_kpis`);const data=await res.json();if(data.status!=='success')return;const k=data.kpis;
    const cards=[
        {l:'Total Usuarios',v:k.total_users,i:'fa-users',c:'text-fortnite-blue',bg:'from-blue-500/10 to-blue-600/5'},
        {l:'Activos',v:k.active_users,i:'fa-circle-check',c:'text-green-500',bg:'from-green-500/10 to-green-600/5'},
        {l:'Verificados',v:k.verified_users,i:'fa-user-check',c:'text-blue-500',bg:'from-blue-500/10 to-cyan-500/5',s:`${k.total_users>0?Math.round(k.verified_users/k.total_users*100):0}%`},
        {l:'Baneados / Susp.',v:`${k.banned_users} / ${k.suspended_users}`,i:'fa-ban',c:'text-red-500',bg:'from-red-500/10 to-orange-500/5'},
        {l:'Nuevos Hoy',v:k.new_today,i:'fa-user-plus',c:'text-fortnite-purple',bg:'from-purple-500/10 to-pink-500/5',s:`${k.new_this_week} esta semana`},
        {l:'Coins Circulacion',v:k.total_coins.toLocaleString(),i:'fa-coins',c:'text-yellow-500',bg:'from-yellow-500/10 to-amber-500/5'},
        {l:'Bonus Spins',v:k.with_bonus_spins,i:'fa-dice',c:'text-fortnite-orange',bg:'from-orange-500/10 to-yellow-500/5'},
        {l:'Paises',v:k.countries,i:'fa-globe',c:'text-teal-500',bg:'from-teal-500/10 to-cyan-500/5'}
    ];
    const p=document.getElementById('kpiPanel');p.className='grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6';
    p.innerHTML=cards.map((c,i)=>`<div class="kpi-card card rounded-xl p-4 bg-gradient-to-br ${c.bg} anim-fade-up anim-d${i%5}"><div class="flex items-start justify-between"><div><p class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">${c.l}</p><p class="text-xl font-bold mt-1 ${c.c} anim-count">${c.v}</p>${c.s?`<p class="text-[10px] text-gray-400 mt-0.5">${c.s}</p>`:''}</div><div class="p-2 rounded-lg bg-white/60 dark:bg-gray-800/60"><i class="fas ${c.i} ${c.c} text-lg"></i></div></div></div>`).join('');
    }catch(e){console.error('KPI fail',e)}
}

// TABLE
function gv(id){const el=document.getElementById(id);return el?el.value:'';}
async function loadUsers(page){
    currentPage=page;
    const search=gv('searchInput'),status=gv('statusFilter');
    const sort=gv('sortFilter')||'recent',verified=gv('verifiedFilter');
    const from=gv('pdateFrom'),to=gv('pdateTo'),minOrders=gv('minOrders'),minSpent=gv('minSpent');
    const hm=document.getElementById('hasManualFilter');const hasManual=hm&&hm.checked?'1':'';
    const tbody=document.getElementById('usersTableBody');
    tbody.innerHTML=`<tr><td colspan="8" class="text-center py-10 text-gray-400"><i class="fas fa-circle-notch fa-spin mr-2"></i>Cargando...</td></tr>`;
    const qs=new URLSearchParams({action:'get_users',page,search,status,sort,verified,pdate_from:from,pdate_to:to,min_orders:minOrders,min_spent:minSpent,has_manual:hasManual});
    try{const res=await fetch(`${API}?${qs.toString()}`);const data=await res.json();
    if(data.status==='success'){tbody.innerHTML='';
        if(!data.users.length){tbody.innerHTML=`<tr><td colspan="8" class="text-center py-10 text-gray-400"><i class="fas fa-search mr-2"></i>Sin resultados.</td></tr>`;renderPag(0,1,0);return;}
        data.users.forEach((u,i)=>{
            const vf=u.is_verified==1?'<i class="fas fa-check-circle text-blue-500 ml-1" title="Verificado"></i>':'';
            const dn=u.public_display_name||u.game_username||'-';
            let sc='',sl='',dc='';
            if(u.account_status==='active'){sc='bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';sl='ACTIVO';dc='bg-green-500';}
            else if(u.account_status==='banned'){sc='bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';sl='BANEADO';dc='bg-red-500';}
            else{sc='bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';sl='SUSPENDIDO';dc='bg-yellow-500';}
            const ct=u.country_iso2?u.country_iso2.toUpperCase():'-';
            const cr=fmtShort(u.created_at);
            const ini=(u.game_username||'?').charAt(0).toUpperCase();
            const wO=parseInt(u.web_orders||0),mO=parseInt(u.manual_orders||0),tO=wO+mO;
            const wS=parseFloat(u.web_spent||0),mS=parseFloat(u.manual_spent||0),tS=wS+mS;
            const mBadge=mO>0?`<span class="ml-1 text-amber-500" title="${mO} compra(s) manual(es)"><i class="fas fa-receipt text-[9px]"></i>${mO}</span>`:'';
            const pBadge=tO>0?`<div class="text-[10px] text-emerald-600 dark:text-emerald-400 mt-0.5" title="Web: ${wO} (S/${wS.toFixed(2)}) · Manual: ${mO} (S/${mS.toFixed(2)})"><i class="fas fa-bag-shopping mr-0.5"></i>${tO} · S/${tS.toFixed(2)}${mBadge}</div>`:'';
            tbody.innerHTML+=`<tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors table-row-anim" style="animation-delay:${i*30}ms">
                <td class="px-4 py-3"><div class="flex items-center gap-3"><div class="h-9 w-9 rounded-lg bg-gradient-to-br from-fortnite-blue/20 to-fortnite-purple/20 flex items-center justify-center text-fortnite-blue font-bold text-sm flex-shrink-0">${ini}</div><div class="min-w-0"><div class="text-sm font-semibold text-gray-900 dark:text-white flex items-center truncate">${u.game_username||'-'} ${vf}</div><div class="text-[11px] text-gray-400 font-mono truncate">${u.public_code}</div>${pBadge}</div></div></td>
                <td class="px-4 py-3"><span class="text-sm font-medium text-fortnite-purple dark:text-purple-300 truncate block max-w-[160px]" title="${dn}">${dn}</span></td>
                <td class="px-4 py-3 hidden lg:table-cell"><div class="text-xs text-gray-600 dark:text-gray-300 truncate max-w-[180px]">${u.email||'<span class="italic text-gray-400">-</span>'}</div><div class="text-[11px] text-gray-400 mt-0.5"><i class="fab fa-whatsapp text-green-500 mr-0.5"></i>${u.whatsapp_phone_e164||'-'}</div></td>
                <td class="px-4 py-3"><span class="inline-flex items-center gap-1.5 px-2 py-0.5 text-[11px] font-bold rounded-full ${sc}"><span class="status-dot ${dc}"></span>${sl}</span></td>
                <td class="px-4 py-3 hidden md:table-cell"><span class="text-xs font-mono text-gray-600 dark:text-gray-300">${ct}</span></td>
                <td class="px-4 py-3 text-right"><span class="text-sm font-bold text-yellow-500 flex items-center justify-end gap-1"><i class="fas fa-coins text-[10px]"></i>${parseFloat(u.coins_balance).toFixed(2)}</span></td>
                <td class="px-4 py-3 hidden xl:table-cell"><span class="text-[11px] text-gray-400">${cr}</span></td>
                <td class="px-4 py-3 text-center"><button onclick="openUserModal(${u.id})" class="btn-modern inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-fortnite-blue/10 text-fortnite-blue hover:bg-fortnite-blue hover:text-white text-xs font-semibold transition-colors"><i class="fas fa-cog text-[10px]"></i>Gestionar</button></td>
            </tr>`;
        });renderPag(data.pages,page,data.total);}}catch(e){console.error(e);tbody.innerHTML=`<tr><td colspan="8" class="text-center py-6 text-red-500"><i class="fas fa-exclamation-triangle mr-2"></i>Error API</td></tr>`;}
}

function renderPag(tp,cur,total){
    const d=document.getElementById('pagination');
    if(tp<=1){d.innerHTML=`<span class="text-xs text-gray-500 dark:text-gray-400">${total||0} usuario(s)</span>`;return;}
    let pb='';const mx=5;let s=Math.max(1,cur-Math.floor(mx/2)),e=Math.min(tp,s+mx-1);if(e-s<mx-1)s=Math.max(1,e-mx+1);
    for(let i=s;i<=e;i++){const a=i===cur;pb+=`<button onclick="loadUsers(${i})" class="px-3 py-1 rounded-lg text-xs font-medium transition-colors ${a?'bg-fortnite-blue text-white shadow':'bg-white dark:bg-gray-700 border dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600'}">${i}</button>`;}
    d.innerHTML=`<span class="text-xs text-gray-500 dark:text-gray-400">${total} usuarios &middot; Pag <b>${cur}</b>/<b>${tp}</b></span><div class="flex gap-1">${cur>1?`<button onclick="loadUsers(${cur-1})" class="px-2.5 py-1 bg-white dark:bg-gray-700 border dark:border-gray-600 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 text-xs"><i class="fas fa-chevron-left"></i></button>`:''}${pb}${cur<tp?`<button onclick="loadUsers(${cur+1})" class="px-2.5 py-1 bg-white dark:bg-gray-700 border dark:border-gray-600 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 text-xs"><i class="fas fa-chevron-right"></i></button>`:''}</div>`;
}

// MODAL
async function openUserModal(uid){
    document.getElementById('userModal').classList.remove('hidden');document.body.style.overflow='hidden';
    switchTab('overview');document.getElementById('coinAdjustForm').classList.add('hidden');
    document.getElementById('modalDisplayName').textContent='Cargando...';
    try{const res=await fetch(`${API}?action=get_user_details&user_id=${uid}`);if(!res.ok)throw new Error(`HTTP ${res.status}`);
    const data=await res.json();if(data.status!=='success'){showToast(data.message,'error');return;}
    const u=data.user;currentUserData=u;
    document.getElementById('modalAvatar').textContent=(u.game_username||'?').charAt(0).toUpperCase();
    document.getElementById('modalDisplayName').textContent=u.public_display_name||u.game_username||'Sin nombre';
    document.getElementById('modalPublicCode').textContent=u.public_code;
    const vb=document.getElementById('modalVerifiedBadge');u.is_verified==1?vb.classList.remove('hidden'):vb.classList.add('hidden');
    const sb=document.getElementById('modalStatusBadge');
    if(u.account_status==='active'){sb.className='text-xs font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300';sb.textContent='ACTIVO';}
    else if(u.account_status==='banned'){sb.className='text-xs font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';sb.textContent='BANEADO';}
    else{sb.className='text-xs font-bold px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300';sb.textContent='SUSPENDIDO';}

    // Overview
    document.getElementById('ovBalance').textContent=parseFloat(u.coins_balance).toFixed(2);
    document.getElementById('ovSpins').textContent=u.bonus_spins||'0';
    document.getElementById('ovOrders').textContent=data.orders?data.orders.length:'0';
    document.getElementById('ovLastLogin').textContent=u.last_login_at?fmtShort(u.last_login_at):'Nunca';
    document.getElementById('ovGameUser').textContent=u.game_username||'-';
    document.getElementById('ovDispName').textContent=u.public_display_name||'-';
    document.getElementById('ovEmail').textContent=u.email||'-';
    document.getElementById('ovPhone').textContent=u.whatsapp_phone_e164||'-';
    document.getElementById('ovCountry').textContent=u.country_iso2?u.country_iso2.toUpperCase():'-';
    document.getElementById('ovCreated').textContent=fmtDate(u.created_at);

    // Resumen de compras (web + manual)
    const ps=data.purchase_summary||{web_orders:0,web_spent:0,web_last:null,manual_orders:0,manual_spent:0,total_orders:0,total_spent:0};
    document.getElementById('ovTotalOrders').textContent=ps.total_orders;
    document.getElementById('ovOrdersBreak').textContent=`web ${ps.web_orders} · man ${ps.manual_orders}`;
    document.getElementById('ovTotalSpent').textContent='S/ '+parseFloat(ps.total_spent).toFixed(2);
    document.getElementById('ovSpentBreak').textContent=`web ${parseFloat(ps.web_spent).toFixed(2)} · man ${parseFloat(ps.manual_spent).toFixed(2)}`;
    document.getElementById('ovManualOrders').textContent=ps.manual_orders;
    document.getElementById('ovLastPurchase').textContent=ps.web_last?fmtShort(ps.web_last):'-';

    // Identity
    document.getElementById('fldGameUsername').value=u.game_username||'';
    document.getElementById('fldGameNameMode').value=u.game_name_mode||'';
    document.getElementById('fldDisplayName').value=u.public_display_name||'';
    document.getElementById('fldPublicNameMode').value=u.public_name_mode||'';
    document.getElementById('fldEmail').value=u.email||'';
    document.getElementById('fldPhone').value=u.whatsapp_phone_e164||'';
    document.getElementById('fldPhoneMode').value=u.phone_mode||'';
    document.getElementById('fldCountry').value=u.country_iso2||'';
    document.getElementById('fldAvatarId').value=u.avatar_id||'';
    document.getElementById('fldBonusSpins').value=u.bonus_spins||0;
    document.getElementById('fldGameLocked').checked=u.game_username_locked==1;
    document.getElementById('fldPublicLocked').checked=u.public_name_locked==1;
    document.getElementById('fldWhatsappLocked').checked=u.whatsapp_locked==1;
    document.getElementById('fldBenefits').checked=u.benefits_enabled==1;

    // Security
    document.getElementById('modalStatus').value=u.account_status;
    document.getElementById('modalReason').value=u.ban_reason||'';
    document.getElementById('modalBanUntil').value=u.ban_until?u.ban_until.replace(' ','T').substring(0,16):'';
    document.getElementById('modalVerifyNotes').value=u.verified_notes||'';
    document.getElementById('secLastLogin').textContent=u.last_login_at?fmtDate(u.last_login_at):'-';
    document.getElementById('secLastIP').textContent=u.last_login_ip||'-';
    document.getElementById('secBannedAt').textContent=u.banned_at?fmtDate(u.banned_at):'-';
    document.getElementById('secVerifiedBy').textContent=u.verified_by||'-';
    const bv=document.getElementById('btnVerify');
    if(u.is_verified==1){bv.innerHTML='<i class="fas fa-check-circle mr-2 text-green-500"></i>Verificado (Clic para quitar)';bv.className='btn-modern w-full inline-flex justify-center items-center rounded-lg border-2 border-green-500 bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200 py-2.5 text-sm font-medium hover:bg-green-100 cursor-pointer';}
    else{bv.innerHTML='<i class="far fa-circle mr-2 text-gray-400"></i>No Verificado (Clic para aprobar)';bv.className='btn-modern w-full inline-flex justify-center items-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 py-2.5 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-600 cursor-pointer';}

    // Coins
    document.getElementById('modalBalance').textContent=parseFloat(u.coins_balance).toFixed(2);
    fillTrans(data.transactions);fillOrders(data.orders);fillMeta(u,data.admin_log);
    fillManualPurchases(data.manual_purchases||[]);
    // Reset del formulario de compra manual (fecha por defecto = hoy)
    document.getElementById('mpDate').value=new Date().toISOString().substring(0,10);
    document.getElementById('mpAmount').value='';document.getElementById('mpNote').value='';document.getElementById('mpChannel').value='whatsapp';
    }catch(e){console.error(e);showToast('Error cargando usuario','error');}
}

const CHAN_LABEL={whatsapp:'WhatsApp',messenger:'Messenger',manual:'Manual',other:'Otro'};
function fillManualPurchases(list){
    const tb=document.getElementById('manualPurchasesTable');tb.innerHTML='';
    let sum=0;
    if(list&&list.length){list.forEach(p=>{sum+=parseFloat(p.amount||0);
        const note=(p.note&&p.note.length)?p.note:'<span class="italic text-gray-400">-</span>';
        tb.innerHTML+=`<tr class="dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/30">
            <td class="px-3 py-2 font-medium">${fmtShort(p.purchase_date)}</td>
            <td class="px-3 py-2"><span class="text-[11px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700">${CHAN_LABEL[p.channel]||p.channel}</span></td>
            <td class="px-3 py-2 truncate max-w-[220px]">${note}</td>
            <td class="px-3 py-2 text-right font-bold text-emerald-600 dark:text-emerald-400">S/ ${parseFloat(p.amount).toFixed(2)}</td>
            <td class="px-3 py-2 text-center"><button onclick="deleteManualPurchase(${p.id})" class="text-red-500 hover:text-red-700" title="Eliminar"><i class="fas fa-trash-alt text-[11px]"></i></button></td>
        </tr>`;});}
    else tb.innerHTML='<tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Sin compras manuales registradas</td></tr>';
    document.getElementById('mpCount').textContent=list?list.length:0;
    document.getElementById('mpSum').textContent=sum.toFixed(2);
}

async function addManualPurchase(){
    if(!currentUserData)return;
    const date=document.getElementById('mpDate').value,amount=document.getElementById('mpAmount').value;
    const channel=document.getElementById('mpChannel').value,note=document.getElementById('mpNote').value;
    if(!date){showToast('Indica la fecha de compra.','error');return;}
    if(amount===''||parseFloat(amount)<0){showToast('Indica un monto válido (>= 0).','error');return;}
    const fd=new FormData();fd.append('action','mp_add');fd.append('user_id',currentUserData.id);
    fd.append('purchase_date',date);fd.append('amount',amount);fd.append('channel',channel);fd.append('note',note);
    try{const res=await fetch(API,{method:'POST',body:fd});const data=await res.json();
    if(data.status==='success'){showToast('Compra manual registrada');openUserModal(currentUserData.id);switchTab('purchases');loadUsers(currentPage);}
    else showToast(data.message||'Error','error');}
    catch(e){console.error(e);showToast('Error de conexión','error');}
}

async function deleteManualPurchase(id){
    if(!confirm('¿Eliminar esta compra manual?'))return;
    const fd=new FormData();fd.append('action','mp_delete');fd.append('id',id);
    try{const res=await fetch(API,{method:'POST',body:fd});const data=await res.json();
    if(data.status==='success'){showToast('Compra eliminada');if(currentUserData){openUserModal(currentUserData.id);switchTab('purchases');loadUsers(currentPage);}}
    else showToast(data.message||'Error','error');}
    catch(e){console.error(e);showToast('Error de conexión','error');}
}

function closeModal(){document.getElementById('userModal').classList.add('hidden');document.body.style.overflow='';currentUserData=null;}

function switchTab(t){document.querySelectorAll('.tab-content').forEach(e=>e.classList.add('hidden'));document.getElementById(`tab-${t}`).classList.remove('hidden');document.querySelectorAll('.tab-pill').forEach(b=>{b.dataset.tab===t?b.classList.add('active-pill'):b.classList.remove('active-pill')});}

function fillTrans(tx){const tb=document.getElementById('transactionsTable');tb.innerHTML='';
    if(tx&&tx.length){tx.forEach(t=>{const c=t.amount>0?'text-green-600 dark:text-green-400':'text-red-600 dark:text-red-400';const s=t.amount>0?'+':'';
    tb.innerHTML+=`<tr class="dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/30"><td class="px-3 py-2">${fmtShort(t.created_at)}</td><td class="px-3 py-2 truncate max-w-[200px]">${t.reason||'-'}</td><td class="px-3 py-2 text-right font-bold ${c}">${s}${parseFloat(t.amount).toFixed(2)}</td></tr>`;});}
    else tb.innerHTML='<tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">Sin movimientos</td></tr>';}

function fillOrders(ord){const tb=document.getElementById('userOrdersTable');tb.innerHTML='';
    if(ord&&ord.length){ord.forEach(o=>{let bc='text-gray-500';if(o.estado==='completado')bc='text-green-600';if(o.estado==='pendiente')bc='text-yellow-600';if(o.estado==='cancelado')bc='text-red-600';
    tb.innerHTML+=`<tr class="dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/30"><td class="px-3 py-2 font-mono text-[11px]">${o.codigo_pedido}</td><td class="px-3 py-2">${fmtShort(o.fecha_creacion)}</td><td class="px-3 py-2 font-bold ${bc} uppercase text-[11px]">${o.estado}</td><td class="px-3 py-2 text-right font-medium">S/ ${parseFloat(o.total_pagar).toFixed(2)}</td></tr>`;});}
    else tb.innerHTML='<tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">Sin pedidos</td></tr>';}

function fillMeta(u,log){
    const metaFields=[
        ['ID (interno)',u.id],['Public Code',u.public_code],['Game Username Norm',u.game_username_norm],['Game Username Updated',fmtDate(u.game_username_updated_at)],
        ['Public Display Name Norm',u.public_display_name_norm],['Public Display Name Updated',fmtDate(u.public_display_name_updated_at)],
        ['Phone Updated',fmtDate(u.phone_updated_at)],['Account Status',u.account_status],['Ban Until',fmtDate(u.ban_until)],['Banned At',fmtDate(u.banned_at)],
        ['Ban Reason',u.ban_reason],['Benefits Enabled',u.benefits_enabled==1?'Si':'No'],['Is Verified',u.is_verified==1?'Si':'No'],
        ['Verified At',fmtDate(u.verified_at)],['Verified By',u.verified_by],['Verified Notes',u.verified_notes],
        ['Avatar ID',u.avatar_id],['Avatar Updated',fmtDate(u.avatar_updated_at)],['Country',u.country_iso2],['Country Updated',fmtDate(u.country_updated_at)],
        ['Bonus Spins',u.bonus_spins],['Created At',fmtDate(u.created_at)],['Updated At',fmtDate(u.updated_at)],
        ['Last Login',fmtDate(u.last_login_at)],['Last Login IP',u.last_login_ip],['Coins Balance',u.coins_balance]
    ];
    const g=document.getElementById('metaFieldsGrid');
    g.innerHTML=metaFields.map(([l,v])=>`<div class="field-group"><label class="text-[10px] font-semibold text-gray-400 uppercase">${l}</label><p class="text-xs text-gray-700 dark:text-gray-200 mt-0.5 font-mono">${v||'-'}</p></div>`).join('');

    const lt=document.getElementById('adminLogTable');lt.innerHTML='';
    if(log&&log.length){log.forEach(l=>{lt.innerHTML+=`<tr class="dark:text-gray-300"><td class="px-3 py-2">${fmtShort(l.created_at)}</td><td class="px-3 py-2 font-semibold">${l.action||'-'}</td><td class="px-3 py-2 truncate max-w-[200px]">${l.details||'-'}</td></tr>`;});}
    else lt.innerHTML='<tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">Sin registros</td></tr>';
}

// ACTIONS
async function saveUserStatus(){
    if(!currentUserData)return;
    const st=document.getElementById('modalStatus').value,reason=document.getElementById('modalReason').value,banUntil=document.getElementById('modalBanUntil').value;
    if((st==='banned'||st==='suspended')&&reason.trim().length<3){showToast('Escribe un motivo para la suspension o baneo.','error');return;}
    const fd=new FormData();fd.append('action','update_status');fd.append('user_id',currentUserData.id);fd.append('status',st);fd.append('reason',reason);if(banUntil)fd.append('ban_until',banUntil);
    try{const res=await fetch(API,{method:'POST',body:fd});const data=await res.json();
    if(data.status==='success'){showToast('Estado actualizado');loadUsers(currentPage);openUserModal(currentUserData.id);}else showToast(data.message,'error');}
    catch(e){console.error(e);showToast('Error de conexion','error');}
}

async function toggleVerify(){
    if(!currentUserData)return;const nv=currentUserData.is_verified==1?0:1;const msg=nv?'APROBAR verificacion':'QUITAR verificacion';
    if(!confirm(`Confirmas ${msg}?`))return;
    const fd=new FormData();fd.append('action','toggle_verification');fd.append('user_id',currentUserData.id);fd.append('verified',nv);
    const notes=document.getElementById('modalVerifyNotes').value;if(notes)fd.append('notes',notes);
    try{const res=await fetch(API,{method:'POST',body:fd});const data=await res.json();
    if(data.status==='success'){showToast('Verificacion actualizada');openUserModal(currentUserData.id);loadUsers(currentPage);}
    }catch(e){showToast('Error de red','error');}
}

function toggleCoinAdjust(){document.getElementById('coinAdjustForm').classList.toggle('hidden');}

async function submitCoinAdjust(){
    if(!currentUserData)return;const amount=document.getElementById('adjustAmount').value,note=document.getElementById('adjustNote').value;
    if(!amount||amount==0){showToast('Monto invalido','error');return;}if(!note){showToast('Escribe una nota','error');return;}
    if(!confirm(`Ajustar saldo por ${amount} coins?`))return;
    const fd=new FormData();fd.append('action','adjust_coins');fd.append('user_id',currentUserData.id);fd.append('amount',amount);fd.append('note',note);
    try{const res=await fetch(API,{method:'POST',body:fd});const data=await res.json();
    if(data.status==='success'){showToast('Saldo ajustado');document.getElementById('adjustAmount').value='';document.getElementById('adjustNote').value='';toggleCoinAdjust();openUserModal(currentUserData.id);loadUsers(currentPage);}
    else showToast(data.message,'error');}catch(e){console.error(e);showToast('Error de conexion','error');}
}

async function saveUserFields(){
    if(!currentUserData)return;
    const fd=new FormData();fd.append('action','update_user_fields');fd.append('user_id',currentUserData.id);
    fd.append('game_username',document.getElementById('fldGameUsername').value);
    fd.append('game_name_mode',document.getElementById('fldGameNameMode').value);
    fd.append('public_display_name',document.getElementById('fldDisplayName').value);
    fd.append('public_name_mode',document.getElementById('fldPublicNameMode').value);
    fd.append('email',document.getElementById('fldEmail').value);
    fd.append('whatsapp_phone_e164',document.getElementById('fldPhone').value);
    fd.append('phone_mode',document.getElementById('fldPhoneMode').value);
    fd.append('country_iso2',document.getElementById('fldCountry').value);
    fd.append('avatar_id',document.getElementById('fldAvatarId').value);
    fd.append('bonus_spins',document.getElementById('fldBonusSpins').value);
    fd.append('game_username_locked',document.getElementById('fldGameLocked').checked?1:0);
    fd.append('public_name_locked',document.getElementById('fldPublicLocked').checked?1:0);
    fd.append('whatsapp_locked',document.getElementById('fldWhatsappLocked').checked?1:0);
    fd.append('benefits_enabled',document.getElementById('fldBenefits').checked?1:0);
    try{const res=await fetch(API,{method:'POST',body:fd});const data=await res.json();
    if(data.status==='success'){showToast('Datos actualizados');openUserModal(currentUserData.id);loadUsers(currentPage);}
    else showToast(data.message,'error');}catch(e){console.error(e);showToast('Error de conexion','error');}
}
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>
