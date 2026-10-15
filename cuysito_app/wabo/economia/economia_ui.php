<?php
// /wabo/economia/economia_ui.php
// Cabecera de sección compartida por las 4 vistas de Economía (pestañas + estilos + helpers JS).
// Cada vista sigue siendo una página independiente; esto solo evita repetir la navegación interna.
$ecoTabs = [
    'resumen' => ['href' => 'resumen.php', 'icon' => 'fa-coins', 'label' => 'Resumen'],
    'movimientos' => ['href' => 'movimientos.php', 'icon' => 'fa-book', 'label' => 'Movimientos'],
    'ranking' => ['href' => 'ranking.php', 'icon' => 'fa-trophy', 'label' => 'Ranking'],
    'reglas' => ['href' => 'reglas.php', 'icon' => 'fa-scale-balanced', 'label' => 'Reglas económicas'],
];
$ecoCurrent = $ecoCurrent ?? '';
?>
<style>
    @keyframes ecoFadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    @keyframes ecoShimmer { 0% { background-position:-200% 0; } 100% { background-position:200% 0; } }
    .eco-fade { animation: ecoFadeUp .35s ease-out both; }
    .eco-d1 { animation-delay:.05s } .eco-d2 { animation-delay:.1s } .eco-d3 { animation-delay:.15s }
    .eco-skel { background: linear-gradient(90deg, rgba(128,128,128,.1) 25%, rgba(128,128,128,.2) 50%, rgba(128,128,128,.1) 75%); background-size:200% 100%; animation: ecoShimmer 1.4s infinite; border-radius:8px; }
    .eco-kpi { transition: transform .2s, box-shadow .2s; } .eco-kpi:hover { transform: translateY(-2px); box-shadow: 0 8px 25px -6px rgba(0,0,0,.2); }
    .eco-tab { display:inline-flex; align-items:center; gap:.45rem; padding:.5rem .9rem; border-radius:.6rem; font-size:.85rem; font-weight:600; color: var(--text-secondary); border:1px solid transparent; transition: all .2s; white-space:nowrap; }
    .eco-tab:hover { background: rgba(128,128,128,.08); color: var(--text-primary); }
    .eco-tab.is-active { background: linear-gradient(135deg,#f59e0b,#f97316); color:#fff !important; box-shadow: 0 4px 14px -4px rgba(245,158,11,.5); }
    .eco-input { transition: border-color .2s, box-shadow .2s; } .eco-input:focus { border-color:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.18); outline:none; }
    .eco-btn { transition: all .2s; } .eco-btn:active { transform: scale(.97); }
    .eco-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.15rem .55rem; border-radius:999px; font-size:.68rem; font-weight:700; letter-spacing:.02em; }
    .eco-chip-manual { background: rgba(239,68,68,.14); color:#f87171; }
    .eco-chip-tienda { background: rgba(59,130,246,.14); color:#60a5fa; }
    .eco-chip-sorteos { background: rgba(245,158,11,.16); color:#fbbf24; }
    .eco-chip-giro { background: rgba(168,85,247,.16); color:#c084fc; }
    .eco-chip-videos { background: rgba(16,185,129,.16); color:#34d399; }
    .eco-chip-otros { background: rgba(148,163,184,.16); color:#94a3b8; }
    .eco-pos { color:#4ade80; } .eco-neg { color:#f87171; }
    .eco-bar { height: 8px; border-radius: 999px; background: rgba(128,128,128,.15); overflow:hidden; } .eco-bar > span { display:block; height:100%; border-radius:999px; transition: width .6s ease; }
    .eco-row { animation: ecoFadeUp .25s ease-out both; }
    .eco-table th { font-size:.68rem; letter-spacing:.06em; text-transform:uppercase; font-weight:700; color: var(--text-secondary); padding:.7rem .9rem; text-align:left; white-space:nowrap; }
    .eco-table td { padding:.6rem .9rem; font-size:.84rem; vertical-align:middle; }
    .eco-table tbody tr { border-top:1px solid var(--border-color); } .eco-table tbody tr:hover { background: rgba(128,128,128,.05); }
    .eco-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; background:#222; flex-shrink:0; }
    .eco-modal { animation: ecoFadeUp .25s ease-out; }
    [data-theme="light"] .eco-chip-manual { color:#b91c1c; } [data-theme="light"] .eco-chip-tienda { color:#1d4ed8; } [data-theme="light"] .eco-chip-sorteos { color:#b45309; }
    [data-theme="light"] .eco-chip-giro { color:#7e22ce; } [data-theme="light"] .eco-chip-videos { color:#047857; } [data-theme="light"] .eco-chip-otros { color:#475569; }
    [data-theme="light"] .eco-pos { color:#15803d; } [data-theme="light"] .eco-neg { color:#b91c1c; }
</style>

<div class="flex flex-col lg:flex-row lg:items-end justify-between gap-3 mb-4 eco-fade">
    <div>
        <h2 class="text-2xl font-bold flex items-center gap-2"><i class="fas fa-coins" style="color: var(--accent);"></i> Economía <span class="text-base font-medium" style="color: var(--text-secondary);">· <?php echo htmlspecialchars($ecoTabs[$ecoCurrent]['label'] ?? ''); ?></span></h2>
        <p class="text-sm mt-0.5" style="color: var(--text-secondary);"><?php echo htmlspecialchars($ecoSubtitle ?? 'Control de la moneda (Coins): circulación, emisión, consumo y saldos.'); ?></p>
    </div>
    <nav class="flex gap-1 overflow-x-auto pb-1 -mx-1 px-1">
        <?php foreach ($ecoTabs as $k => $t): ?>
        <a href="<?php echo $t['href']; ?>" class="eco-tab <?php echo $k === $ecoCurrent ? 'is-active' : ''; ?>"><i class="fas <?php echo $t['icon']; ?>"></i><?php echo $t['label']; ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<div id="ecoToast" class="fixed top-4 right-4 z-[1100] hidden">
    <div id="ecoToastInner" class="flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl border text-sm font-medium eco-fade"><i id="ecoToastIcon" class="fas fa-check-circle"></i><span id="ecoToastMsg">OK</span></div>
</div>

<script>
    const ECO_API = 'economia/api_economia.php';
    const ECO_AVATAR_BASE = '../assets/avatars/';
    const ecoEsc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const ecoNum = (n, d = 2) => Number(n || 0).toLocaleString('es-PE', { minimumFractionDigits: d, maximumFractionDigits: d });
    const ecoInt = (n) => Number(n || 0).toLocaleString('es-PE');
    const ecoSigned = (n) => (Number(n) > 0 ? '+' : '') + ecoNum(n);
    const ecoDate = (s) => { if (!s) return '—'; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('es-PE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }); };
    const ecoAvatar = (f) => `<img class="eco-avatar" src="${ecoEsc(ECO_AVATAR_BASE + (f || 'default.png'))}" alt="">`;
    const ecoUserCell = (u) => `<div class="flex items-center gap-2 min-w-0">${ecoAvatar(u.avatar)}<div class="min-w-0"><div class="font-semibold truncate">${ecoEsc(u.game_username || ('#' + u.user_id))}</div><div class="text-[11px] truncate" style="color: var(--text-secondary);">${ecoEsc(u.public_display_name || '')}${u.public_code ? ' · ' + ecoEsc(u.public_code) : ''}</div></div></div>`;
    const ecoChip = (cat, label) => `<span class="eco-chip eco-chip-${ecoEsc(cat || 'otros')}">${ecoEsc(label || cat)}</span>`;
    function ecoToast(msg, ok = true) {
        const el = document.getElementById('ecoToast'), inner = document.getElementById('ecoToastInner'), icon = document.getElementById('ecoToastIcon');
        document.getElementById('ecoToastMsg').textContent = msg;
        inner.className = 'flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl border text-sm font-medium eco-fade ' + (ok ? 'bg-emerald-600 border-emerald-500 text-white' : 'bg-red-600 border-red-500 text-white');
        icon.className = 'fas ' + (ok ? 'fa-check-circle' : 'fa-exclamation-triangle');
        el.classList.remove('hidden');
        clearTimeout(ecoToast._t); ecoToast._t = setTimeout(() => el.classList.add('hidden'), 3200);
    }
    async function ecoGet(params) {
        const qs = new URLSearchParams(params).toString();
        const r = await fetch(ECO_API + '?' + qs, { credentials: 'same-origin' });
        const j = await r.json();
        if (j.status !== 'success') throw new Error(j.message || 'Error');
        return j;
    }
    async function ecoPost(params) {
        const fd = new FormData();
        Object.entries(params).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(ECO_API, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (j.status !== 'success') throw new Error(j.message || 'Error');
        return j;
    }
    const ecoToday = () => new Date().toISOString().slice(0, 10);
    const ecoDaysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); };
    const ecoMonthStart = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01'; };
</script>
