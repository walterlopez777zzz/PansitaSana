<?php
require_once __DIR__ . '/../core/admin_header.php';
?>

<style>
    #svPanel {
        --sv-surface: #0b0d0c;
        --sv-surface-2: #101412;
        --sv-border: rgba(255,255,255,.1);
        --sv-ink: #f7fbf8;
        --sv-muted: #9aa5a0;
        --sv-accent: #34d399;
        --sv-accent-soft: rgba(52,211,153,.12);
        --sv-gold: #fbbf24;
        --sv-rose: #fb7185;
        color: var(--sv-ink);
        max-width: 1560px;
        margin: 0 auto;
    }
    [data-theme="light"] #svPanel {
        --sv-surface: #fff;
        --sv-surface-2: #f4f7f5;
        --sv-border: rgba(15,23,42,.12);
        --sv-ink: #101714;
        --sv-muted: #55615b;
        --sv-accent: #059669;
        --sv-accent-soft: rgba(5,150,105,.1);
        --sv-gold: #b45309;
        --sv-rose: #e11d48;
    }
    [data-theme="glitch"] #svPanel {
        --sv-surface: rgba(13,13,13,.94);
        --sv-surface-2: rgba(26,26,26,.94);
        --sv-border: rgba(255,0,255,.38);
        --sv-ink: #fff;
        --sv-muted: #e5e7eb;
    }
    #svPanel * { box-sizing: border-box; }
    .sv-top { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; margin-bottom:1rem; }
    .sv-eyebrow { color:var(--sv-accent); font-size:.68rem; font-weight:850; letter-spacing:.15em; text-transform:uppercase; }
    .sv-title { margin:.25rem 0; font-size:clamp(1.55rem,3vw,2.2rem); font-weight:850; letter-spacing:-.04em; }
    .sv-subtitle { color:var(--sv-muted); font-size:.8rem; }
    .sv-card { background:var(--sv-surface); border:1px solid var(--sv-border); border-radius:1rem; padding:1rem; margin-bottom:.85rem; }
    .sv-card h2 { margin:0 0 .8rem; font-size:1rem; font-weight:800; }
    .sv-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.75rem; }
    .sv-field { display:grid; gap:.35rem; color:var(--sv-muted); font-size:.72rem; font-weight:700; }
    .sv-input,.sv-select { width:100%; min-height:2.45rem; padding:.55rem .7rem; color:var(--sv-ink); background:var(--sv-surface-2); border:1px solid var(--sv-border); border-radius:.6rem; }
    .sv-toggle-row { display:flex; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem; }
    .sv-toggle { display:flex; align-items:center; gap:.55rem; color:var(--sv-ink); font-weight:800; font-size:.85rem; }
    .sv-toggle input { width:1.15rem; height:1.15rem; accent-color:var(--sv-accent); }
    .sv-chip { display:inline-flex; align-items:center; gap:.35rem; border:1px solid var(--sv-border); border-radius:999px; padding:.32rem .65rem; color:var(--sv-muted); font-size:.68rem; font-weight:750; }
    .sv-chip.is-open { color:var(--sv-accent); border-color:var(--sv-accent); background:var(--sv-accent-soft); }
    .sv-actions { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
    .sv-btn { display:inline-flex; align-items:center; gap:.4rem; min-height:2.35rem; padding:.5rem .75rem; border:1px solid var(--sv-border); border-radius:.6rem; color:var(--sv-ink); background:var(--sv-surface-2); font-size:.72rem; font-weight:800; cursor:pointer; }
    .sv-btn--primary { color:var(--sv-accent); border-color:var(--sv-accent); background:var(--sv-accent-soft); }
    .sv-btn--danger { color:var(--sv-rose); }
    .sv-btn--small { min-height:1.9rem; padding:.3rem .5rem; font-size:.65rem; }
    .sv-meme { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:.55rem; margin-top:.4rem; }
    .sv-preview { max-height:130px; max-width:min(100%,360px); display:block; margin:.65rem 0; border-radius:.65rem; object-fit:contain; }
    .sv-table-wrap { overflow:auto; }
    .sv-table { width:100%; border-collapse:collapse; min-width:920px; font-size:.7rem; }
    .sv-table th,.sv-table td { padding:.58rem .5rem; text-align:left; vertical-align:middle; border-bottom:1px solid var(--sv-border); }
    .sv-table th { color:var(--sv-muted); font-size:.63rem; text-transform:uppercase; letter-spacing:.06em; }
    .sv-user { display:flex; align-items:center; gap:.5rem; min-width:135px; }
    .sv-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; background:var(--sv-surface-2); }
    .sv-items { display:flex; flex-wrap:wrap; gap:.3rem; min-width:230px; max-width:420px; }
    .sv-item { display:flex; align-items:center; gap:.35rem; padding:.23rem .35rem; background:var(--sv-surface-2); border:1px solid var(--sv-border); border-radius:.45rem; max-width:190px; }
    .sv-item img { width:28px; height:28px; object-fit:contain; }
    .sv-item span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:135px; }
    .sv-votes { min-width:108px; }
    .sv-bar { height:5px; overflow:hidden; background:var(--sv-surface-2); border-radius:99px; margin-top:.35rem; }
    .sv-bar i { display:block; height:100%; background:var(--sv-accent); border-radius:inherit; }
    .sv-badge { display:inline-block; padding:.25rem .45rem; border-radius:99px; background:var(--sv-surface-2); color:var(--sv-muted); font-size:.64rem; font-weight:800; }
    .sv-badge--ganadora { color:var(--sv-gold); background:rgba(251,191,36,.12); }
    .sv-badge--rechazada { color:var(--sv-rose); background:rgba(251,113,133,.12); }
    .sv-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.65rem; }
    .sv-stat { background:var(--sv-surface-2); border:1px solid var(--sv-border); border-radius:.7rem; padding:.7rem; min-width:0; }
    .sv-stat h3 { margin:0 0 .4rem; color:var(--sv-accent); font-size:.72rem; }
    .sv-stat ol,.sv-stat ul { margin:0; padding-left:1.1rem; color:var(--sv-muted); font-size:.67rem; line-height:1.65; }
    .sv-stat b { color:var(--sv-ink); }
    .sv-empty { color:var(--sv-muted); padding:1rem; text-align:center; font-size:.75rem; }
    @media(max-width:850px) { .sv-grid,.sv-stats { grid-template-columns:1fr; } .sv-top { align-items:flex-start; flex-direction:column; } .sv-meme { grid-template-columns:1fr; } }
</style>

<div id="svPanel">
    <div class="sv-top">
        <div>
            <div class="sv-eyebrow">Sorteo Flash · Comunidad</div>
            <h1 class="sv-title">Votación Comunidad</h1>
            <div class="sv-subtitle">Administra propuestas de combos, votaciones y estadísticas comunitarias.</div>
        </div>
        <div class="sv-actions"><button class="sv-btn" id="svReload"><i class="fas fa-rotate"></i> Actualizar</button></div>
    </div>

    <section class="sv-card">
        <h2>Configuración del ciclo</h2>
        <div class="sv-toggle-row">
            <label class="sv-toggle"><input type="checkbox" id="svOpen"> Abrir propuestas y votación</label>
            <span class="sv-chip" id="svCycleChip">Sin ciclo activo</span>
        </div>
        <div class="sv-grid">
            <label class="sv-field">Cupos máximos de propuestas<input class="sv-input" type="number" min="1" max="50" id="svMax" value="4"></label>
            <label class="sv-field">Recompensa en Bananos<input class="sv-input" type="number" min="0" max="1000" step=".01" id="svReward" value="20"></label>
            <label class="sv-field">Límite de paVos por combo<input class="sv-input" type="number" min="100" max="10000" step="100" id="svPavos" value="1500"></label>
        </div>
        <label class="sv-field" style="margin-top:.75rem">URL del meme de la fase
            <div class="sv-meme"><input class="sv-input" id="svMemeUrl" type="url" placeholder="/assets/sorteo_memes/meme.webp"><div class="sv-actions"><button class="sv-btn" id="svUploadBtn" type="button"><i class="fas fa-upload"></i> Subir imagen</button><button class="sv-btn sv-btn--danger" id="svClearMeme" type="button">Quitar</button><input id="svMemeFile" type="file" accept=".jpg,.jpeg,.png,.gif,.webp" hidden></div></div>
        </label>
        <img class="sv-preview" id="svMemePreview" alt="Vista previa del meme" hidden>
        <div class="sv-actions" style="margin-top:.8rem"><button class="sv-btn sv-btn--primary" id="svSave"><i class="fas fa-floppy-disk"></i> Guardar configuración</button></div>
    </section>

    <section class="sv-card">
        <div class="sv-actions" style="justify-content:space-between;margin-bottom:.75rem">
            <h2 style="margin:0">Propuestas del ciclo</h2>
            <label class="sv-field" style="display:flex;align-items:center;gap:.45rem;flex-direction:row">Historial
                <select class="sv-select" id="svCycleSelect" style="min-width:190px"></select>
            </label>
        </div>
        <div class="sv-table-wrap"><table class="sv-table"><thead><tr><th>#</th><th>Creador</th><th>Artículos</th><th>Total paVos</th><th>Votos</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody id="svProposals"></tbody></table></div>
    </section>

    <section class="sv-card">
        <div class="sv-actions" style="justify-content:space-between;margin-bottom:.75rem"><h2 style="margin:0">Estadísticas comunitarias</h2><button class="sv-btn" id="svRecalc"><i class="fas fa-arrows-rotate"></i> Recalcular</button></div>
        <div class="sv-stats" id="svStats"></div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    const API = 'sorteos_flash/api_votacion.php';
    const AVATAR = '/assets/avatars/';
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = value => new Intl.NumberFormat('es-PE').format(Number(value || 0));
    const date = value => value ? new Date(value).toLocaleString('es-PE') : '—';
    let selectedCycle = 0;
    const get = async (action, params = {}) => {
        const url = new URL(API, document.baseURI);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
        const response = await fetch(url, { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'No se pudo consultar el panel.');
        return data;
    };
    const post = async (action, values = {}) => {
        const body = new URLSearchParams({ action, ...values });
        const response = await fetch(API, { method: 'POST', credentials: 'same-origin', body });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'No se pudo guardar.');
        return data;
    };
    const load = async (fresh = false) => {
        try {
            const data = await get('sv_dashboard', { ...(selectedCycle ? { ciclo_id: selectedCycle } : {}), fresh: fresh ? '1' : '0' });
            const s = data.settings;
            document.getElementById('svOpen').checked = !!s.votacion_abierta;
            document.getElementById('svMax').value = s.max_propuestas;
            document.getElementById('svReward').value = s.recompensa_bananos;
            document.getElementById('svPavos').value = s.max_pavos;
            document.getElementById('svMemeUrl').value = s.meme_url || '';
            setMemePreview(s.meme_url);
            const cycle = data.open_cycle;
            const chip = document.getElementById('svCycleChip');
            chip.textContent = cycle ? `Ciclo #${cycle.id} abierto desde ${date(cycle.opened_at)}` : 'Sin ciclo activo';
            chip.classList.toggle('is-open', !!cycle);
            const select = document.getElementById('svCycleSelect');
            select.innerHTML = data.cycles.length ? data.cycles.map(c => `<option value="${Number(c.id)}" ${Number(c.id) === Number(data.selected_cycle_id) ? 'selected' : ''}>Ciclo #${Number(c.id)} · ${esc(c.estado)} · ${Number(c.propuestas_count)} propuestas / ${Number(c.votos_count)} votos</option>`).join('') : '<option value="0">Sin ciclos anteriores</option>';
            selectedCycle = Number(data.selected_cycle_id || 0);
            renderProposals(data.propuestas || []);
            renderStats(data.stats || {});
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'No se pudo cargar', text: error.message });
        }
    };
    const setMemePreview = url => {
        const image = document.getElementById('svMemePreview');
        image.hidden = !url;
        image.src = url || '';
    };
    const renderProposals = proposals => {
        const tbody = document.getElementById('svProposals');
        if (!proposals.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="sv-empty">Este ciclo todavía no tiene propuestas.</td></tr>';
            return;
        }
        tbody.innerHTML = proposals.map(p => {
            const avatar = p.avatar ? AVATAR + encodeURIComponent(p.avatar) : AVATAR + 'default.png';
            const items = (p.items || []).map(item => `<div class="sv-item"><img src="${esc(item.image || '')}" alt=""><span title="${esc(item.name)}">${esc(item.name)} · ${fmt(item.pavos)}</span></div>`).join('');
            const state = String(p.estado || '');
            const badge = state === 'ganadora' ? 'sv-badge--ganadora' : (state === 'rechazada' ? 'sv-badge--rechazada' : '');
            return `<tr>
                <td>#${Number(p.id)}</td>
                <td><div class="sv-user"><img class="sv-avatar" src="${esc(avatar)}" alt=""><span>${esc(p.name)}${p.is_verified ? ' <i class="fas fa-circle-check" title="Verificado"></i>' : ''}</span></div></td>
                <td><div class="sv-items">${items || '—'}</div></td>
                <td>${fmt(p.total_pavos)}</td>
                <td class="sv-votes"><b>${Number(p.votos_count)}</b> · ${Number(p.pct)}%<div class="sv-bar"><i style="width:${Math.max(0,Math.min(100,Number(p.pct)))}%"></i></div></td>
                <td><span class="sv-badge ${badge}">${esc(state)}</span></td>
                <td>${esc(date(p.created_at))}</td>
                <td><div class="sv-actions">
                    <button class="sv-btn sv-btn--small" data-voters="${Number(p.id)}">Votantes</button>
                    ${state !== 'rechazada' ? `<button class="sv-btn sv-btn--small" data-winner="${Number(p.id)}">Ganadora</button><button class="sv-btn sv-btn--small sv-btn--danger" data-reject="${Number(p.id)}">Rechazar</button>` : ''}
                </div></td>
            </tr>`;
        }).join('');
        tbody.querySelectorAll('[data-voters]').forEach(button => button.addEventListener('click', () => showVoters(Number(button.dataset.voters))));
        tbody.querySelectorAll('[data-winner]').forEach(button => button.addEventListener('click', () => markWinner(Number(button.dataset.winner))));
        tbody.querySelectorAll('[data-reject]').forEach(button => button.addEventListener('click', () => rejectProposal(Number(button.dataset.reject))));
    };
    const names = (rows, type) => (rows || []).map(row => {
        let count = Number(row.wins ?? 0), detail = 'victorias';
        if (type === 'participantes') { count = Number(row.participations || 0); detail = `participaciones · ${Number(row.wins || 0)} victorias`; }
        if (type === 'rachas') { count = Number(row.best_streak || 0); detail = `rachas seguidas · actual: ${Number(row.current_streak || 0)}`; }
        if (type === 'peor') { count = Number(row.participations || 0); detail = 'participaciones · 0 victorias'; }
        return `<li><b>${esc(row.name)}</b> · ${count} ${detail}</li>`;
    }).join('') || '<li>Sin datos</li>';
    const renderStats = stats => {
        const choices = stats.elecciones || {};
        const latest = stats.ultimas_elecciones || {};
        document.getElementById('svStats').innerHTML = `
            <article class="sv-stat"><h3>Top ganadores</h3><ol>${names(stats.top_ganadores, 'ganadores')}</ol></article>
            <article class="sv-stat"><h3>Top rachas · ${Number(stats.total_sorteos || 0)} sorteos</h3><ol>${names(stats.top_rachas, 'rachas')}</ol></article>
            <article class="sv-stat"><h3>Top participantes</h3><ol>${names(stats.top_participantes, 'participantes')}</ol></article>
            <article class="sv-stat"><h3>Peor suerte</h3><ol>${names(stats.peor_suerte, 'peor')}</ol></article>
            <article class="sv-stat"><h3>Elecciones de premio</h3><ul><li>Original: <b>${Number(choices.original || 0)}</b> (${Number(choices.pct?.original || 0)}%)</li><li>Cashback: <b>${Number(choices.cashback || 0)}</b> (${Number(choices.pct?.cashback || 0)}%)</li><li>Doble: <b>${Number(choices.doble || 0)}</b> (${Number(choices.pct?.doble || 0)}%) · ganados ${Number(choices.doble_ganados || 0)}, perdidos ${Number(choices.doble_perdidos || 0)} · tasa ${Number(choices.doble_win_rate || 0)}%</li></ul></article>
            <article class="sv-stat"><h3>Últimas elecciones</h3><ul>${['original','cashback','doble'].map(k => { const v=latest[k]; return `<li>${esc(k)}: <b>${esc(v?.name || '—')}</b>${v ? ` · ${esc(v.title)} · ${esc(date(v.fecha))}${v.resultado ? ` · ${esc(v.resultado)}` : ''}` : ''}</li>`; }).join('')}</ul></article>`;
    };
    const showVoters = async id => {
        try {
            const data = await get('sv_votes', { propuesta_id: id });
            const html = data.voters.length ? `<ul style="text-align:left;padding-left:1.2rem">${data.voters.map(v => `<li><img src="${esc(AVATAR + encodeURIComponent(v.avatar || 'default.png'))}" alt="" style="width:26px;height:26px;border-radius:50%;vertical-align:middle"> <b>${esc(v.name)}</b> · ${esc(date(v.created_at))} · IP ${esc(v.ip_address || '—')}</li>`).join('')}</ul>` : '<p>Aún no hay votos.</p>';
            await Swal.fire({ title: `Votantes · propuesta #${id}`, html, width: 650 });
        } catch (error) { Swal.fire({ icon: 'error', title: 'No se pudo cargar', text: error.message }); }
    };
    const markWinner = async id => {
        const answer = await Swal.fire({ icon: 'question', title: '¿Marcar como ganadora?', text: 'La ganadora anterior del ciclo volverá a estar activa.', showCancelButton: true, confirmButtonText: 'Marcar ganadora', cancelButtonText: 'Cancelar' });
        if (!answer.isConfirmed) return;
        try { await post('sv_set_winner', { propuesta_id: id }); await load(); }
        catch (error) { Swal.fire({ icon: 'error', title: 'No se pudo actualizar', text: error.message }); }
    };
    const rejectProposal = async id => {
        const answer = await Swal.fire({ icon: 'warning', title: '¿Rechazar esta propuesta?', text: 'Se eliminarán sus votos y se revertirá la recompensa de Bananos acreditada.', showCancelButton: true, confirmButtonText: 'Rechazar propuesta', cancelButtonText: 'Cancelar', confirmButtonColor: '#e11d48' });
        if (!answer.isConfirmed) return;
        try { await post('sv_reject', { propuesta_id: id }); await load(); }
        catch (error) { Swal.fire({ icon: 'error', title: 'No se pudo rechazar', text: error.message }); }
    };
    document.getElementById('svSave').addEventListener('click', async () => {
        try {
            await post('sv_save_settings', {
                votacion_abierta: document.getElementById('svOpen').checked ? '1' : '0',
                max_propuestas: document.getElementById('svMax').value,
                recompensa_bananos: document.getElementById('svReward').value,
                max_pavos: document.getElementById('svPavos').value,
                meme_url: document.getElementById('svMemeUrl').value
            });
            await load();
            Swal.fire({ icon: 'success', title: 'Configuración guardada', timer: 1300, showConfirmButton: false });
        } catch (error) { Swal.fire({ icon: 'error', title: 'No se pudo guardar', text: error.message }); }
    });
    document.getElementById('svCycleSelect').addEventListener('change', event => { selectedCycle = Number(event.target.value); load(); });
    document.getElementById('svReload').addEventListener('click', () => load());
    document.getElementById('svRecalc').addEventListener('click', () => load(true));
    document.getElementById('svMemeUrl').addEventListener('input', event => setMemePreview(event.target.value.trim()));
    document.getElementById('svUploadBtn').addEventListener('click', () => document.getElementById('svMemeFile').click());
    document.getElementById('svMemeFile').addEventListener('change', async event => {
        const file = event.target.files[0];
        if (!file) return;
        const form = new FormData();
        form.append('action', 'sv_upload_meme');
        form.append('meme_image', file);
        try {
            const response = await fetch(API, { method: 'POST', credentials: 'same-origin', body: form });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'No se pudo subir la imagen.');
            await load();
            Swal.fire({ icon: 'success', title: 'Imagen cargada', timer: 1300, showConfirmButton: false });
        } catch (error) { Swal.fire({ icon: 'error', title: 'No se pudo subir', text: error.message }); }
        finally { event.target.value = ''; }
    });
    document.getElementById('svClearMeme').addEventListener('click', async () => {
        const answer = await Swal.fire({ icon: 'question', title: '¿Quitar el meme de la fase?', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar' });
        if (!answer.isConfirmed) return;
        try { await post('sv_clear_meme'); await load(); }
        catch (error) { Swal.fire({ icon: 'error', title: 'No se pudo quitar', text: error.message }); }
    });
    load();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>
