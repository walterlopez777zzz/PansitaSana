<?php
// /wabo/economia/reglas.php — Vista de SOLO LECTURA de los parámetros económicos vigentes.
// No edita nada: cada regla se sigue configurando en su propio módulo (enlace "Editar en…").
require_once __DIR__ . '/../core/admin_header.php';
$ecoCurrent = 'reglas';
$ecoSubtitle = 'Resumen de lectura de cómo se generan y consumen Coins. Cada módulo conserva y edita sus propias reglas.';
require_once __DIR__ . '/economia_ui.php';
?>

<div class="max-w-[1300px] mx-auto">

<div class="rounded-xl p-3 mb-4 text-xs flex items-start gap-2 eco-fade" style="background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3);">
    <i class="fas fa-eye mt-0.5" style="color: var(--accent);"></i>
    <div><b>Solo lectura.</b> Esta página muestra los valores actuales para entender la economía de un vistazo. Para cambiar cualquier regla usa el botón «Editar en…» de cada bloque: las configuraciones de Pedidos, Sorteos, Giro Diario y Vídeos no se centralizan aquí.</div>
</div>

<div id="rlGroups" class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <?php for ($i = 0; $i < 4; $i++) echo '<div class="eco-skel h-56"></div>'; ?>
</div>

<div class="card rounded-xl overflow-hidden eco-fade eco-d2">
    <div class="p-4 border-b" style="border-color: var(--border-color);">
        <h3 class="font-bold text-sm uppercase tracking-wide" style="color: var(--text-secondary);"><i class="fas fa-tags mr-1"></i>Motivos que aparecen en el libro mayor</h3>
        <p class="text-[11px] mt-0.5" style="color: var(--text-secondary);">Cada motivo (<code>reason</code>) lo escribe el módulo que genera el movimiento. Así se clasifican en Resumen y Movimientos.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="eco-table w-full">
            <thead><tr><th>Código</th><th>Significado</th><th>Origen</th></tr></thead>
            <tbody id="rlReasons"></tbody>
        </table>
    </div>
</div>

</div>

<script>
async function rlLoad() {
    try {
        const j = await ecoGet({ action: 'rules' });
        document.getElementById('rlGroups').innerHTML = j.data.groups.map((g, gi) => `
            <div class="card rounded-xl overflow-hidden eco-fade eco-d${(gi % 3) + 1}">
                <div class="px-4 py-3 border-b flex items-center justify-between gap-2" style="border-color: var(--border-color);">
                    <h3 class="font-bold flex items-center gap-2"><i class="fas ${ecoEsc(g.icon)}" style="color: var(--accent);"></i>${ecoEsc(g.title)}</h3>
                    <a href="${ecoEsc(g.edit_href)}" class="eco-btn text-[11px] px-2.5 py-1.5 rounded-lg border whitespace-nowrap" style="border-color: var(--border-color); color: var(--text-secondary);" title="${ecoEsc(g.edit_label)}"><i class="fas fa-arrow-up-right-from-square mr-1"></i>Editar en ${ecoEsc(g.edit_label.split(' (')[0])}</a>
                </div>
                <dl class="divide-y" style="border-color: var(--border-color);">
                    ${g.items.map(it => `<div class="px-4 py-2.5 flex items-center justify-between gap-3" style="border-color: var(--border-color);"><dt class="min-w-0"><div class="text-sm font-medium">${ecoEsc(it.label)}</div><div class="text-[10px] font-mono truncate" style="color: var(--text-secondary);">${ecoEsc(it.hint || '')}</div></dt><dd class="font-mono font-bold text-sm text-right whitespace-nowrap ${it.value === null || it.value === undefined ? 'opacity-50' : ''}">${it.value === null || it.value === undefined ? 'no definido' : ecoEsc(it.value)}</dd></div>`).join('')}
                </dl>
                ${g.note ? `<div class="px-4 py-2.5 border-t text-[11px] leading-relaxed" style="border-color: var(--border-color); color: var(--text-secondary);"><i class="fas fa-info-circle mr-1"></i>${ecoEsc(g.note)}</div>` : ''}
            </div>`).join('');
        document.getElementById('rlReasons').innerHTML = j.data.reasons.map(r => `<tr class="eco-row"><td class="font-mono text-xs">${ecoEsc(r.code)}</td><td>${ecoEsc(r.label)}</td><td>${ecoChip(r.category, r.category_label)}</td></tr>`).join('');
    } catch (e) { document.getElementById('rlGroups').innerHTML = `<div class="col-span-2 text-center text-red-400 py-6">${ecoEsc(e.message)}</div>`; }
}
rlLoad();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>
