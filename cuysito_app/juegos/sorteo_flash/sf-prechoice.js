/**
 * SfPreChoice — flujo "Si llegas a ganar, ¿qué prefieres?"
 *
 * Asistente por pasos dentro de UN solo <dialog> persistente
 * (#sf-prechoice-dialog) gestionado por SfDialog: el velo, el scroll-lock,
 * la contención de foco y Escape los resuelve el diálogo; aquí solo vive la
 * máquina de estados y el render de cada vista sobre los `[data-slot]`.
 *
 * Responsabilidades separadas:
 *   - datos      → `model` (lo arma sorteo_flash.js a partir del estado del sorteo)
 *   - dominio    → `describe(model)`: espacio de decisión (opciones reales y
 *                  pantallas de detalle que exige cada una). Es la única fuente
 *                  de verdad sobre si el flujo tiene sentido; la ruta de vistas
 *                  y el riel de pasos se derivan de él y de la elección actual.
 *   - persistencia → `save(choice, extra)` inyectada, devuelve Promise<boolean>
 *   - estado     → `state` (vista, elección, modo, lado, vitrina, ocupado, error)
 *   - vista      → funciones render*, puras respecto a `state` + `model` +
 *                  `compact` (densidad de pantalla: en teléfonos la redacción
 *                  se poda de redundancias sin cambiar el tono)
 *   - ciclo de vida → un AbortController por apertura; nada sobrevive al cierre
 *
 * API (global, script clásico):
 *   SfPreChoice.describe(model) → PreChoiceSpace (puro, sin DOM)
 *   SfPreChoice.open({ dialog, model, save, opener, chainFrom })
 *     → Promise<{ choice, extra } | null>   null = cerrado sin guardar
 *       (se resuelve en null sin abrir si el modelo no ofrece nada que decidir)
 *   SfPreChoice.close()
 *   SfPreChoice.isOpen()
 *
 * @typedef {Object} PreChoiceOption
 * @property {string} key            option_key del pool
 * @property {string} name           nombre visible
 * @property {string} cardAttrs      atributos de tarjeta de tienda (data-* + style)
 * @property {string} cardInner      HTML interno de tarjeta de tienda
 *
 * @typedef {Object} PreChoicePosition
 * @property {string} key            puesto normalizado ("1", "2", …)
 * @property {string} label          etiqueta corta del puesto ("1°")
 * @property {string} title          etiqueta del pool ("Elige tu skin")
 * @property {PreChoiceOption[]} options
 *
 * @typedef {Object} PreChoiceModel
 * @property {string} prizeName
 * @property {{ image: string, vars: string }} prizeThumb
 * @property {{ positions: PreChoicePosition[], saved: Record<string,string> }|null} pool
 * @property {{ percent: number, coins: number }|null} cashback
 * @property {{ bonusCoins: number, chooseMode: boolean, fixedMode: string,
 *              saved: { mode: string|null, side: string|null } }|null} gamble
 * @property {string|null} current   elección ya guardada ('original'|'cashback'|'gamble')
 * @property {{ bananos: (size:number)=>string, coin: (heads:boolean,size:number)=>string,
 *              roulette: (size:number)=>string, slot: (size:number)=>string }} assets
 *
 * @typedef {Object} PreChoiceSpace
 * @property {string[]} choices        opciones realmente disponibles, en orden de menú
 * @property {string|null} soleChoice  la única opción cuando no hay alternativa
 * @property {boolean} hasAlternatives hay más de una opción entre las que elegir
 * @property {boolean} hasDecision     el flujo tiene algo que decidir (alternativas
 *                                     o, al menos, un detalle configurable)
 * @property {{ fresh: string, freshShort: string, change: string }} cta
 *                                     etiquetas del botón de acceso (`freshShort`
 *                                     es la variante para pantallas ≤ 768px)
 */
window.SfPreChoice = (() => {
    'use strict';

    const View = Object.freeze({
        MENU: 'menu',
        POOL: 'pool',
        MODE: 'mode',
        SIDE: 'side',
        CONFIRM: 'confirm',
        SAVED: 'saved'
    });

    const Choice = Object.freeze({
        ORIGINAL: 'original',
        CASHBACK: 'cashback',
        GAMBLE: 'gamble'
    });

    /** Orden canónico del menú; una opción sólo existe si el modelo la habilita. */
    const CHOICE_ORDER = Object.freeze([Choice.ORIGINAL, Choice.CASHBACK, Choice.GAMBLE]);

    /** Etiqueta del paso que representa cada vista en el riel de progreso. */
    const STEP_LABEL = Object.freeze({
        [View.MENU]: 'Opción',
        [View.POOL]: 'Ítem',
        [View.MODE]: 'Modo',
        [View.SIDE]: 'Lado',
        [View.CONFIRM]: 'Confirmar'
    });

    function stepLabel(view, state) {
        if (view === View.SIDE && state.mode === 'roulette') return 'Color';
        return STEP_LABEL[view];
    }

    const MODE_LABEL = Object.freeze({ coin: 'Moneda', roulette: 'Ruleta', slot: 'Tragamonedas' });
    const SIDE_LABEL = Object.freeze({ heads: 'Cara', tails: 'Sello', red: 'Rojo', black: 'Negro' });
    const SIDES_OF_MODE = Object.freeze({ coin: ['heads', 'tails'], roulette: ['red', 'black'] });

    const SAVE_ERROR_TEXT = 'No pudimos guardar tu elección. Revisa tu conexión e inténtalo de nuevo.';

    /**
     * Pantallas compactas (teléfonos): el diálogo ocupa todo el viewport y los
     * textos usan su variante corta. Debe coincidir con el breakpoint móvil del
     * flujo en sf-dialog.css.
     */
    const COMPACT_MEDIA = '(max-width: 40em)';

    /** Prefijo de los `name` de los radios nativos (un grupo por campo / puesto). */
    const RADIO_NAME = 'sf-prechoice-';

    /** @type {{ dialog: HTMLDialogElement, model: PreChoiceModel, save: Function,
     *           state: Object, slots: Object, controller: AbortController,
     *           compactMedia: MediaQueryList, result: Object|null } | null} */
    let session = null;

    // ────────────────────────────── utilidades ───────────────────────────

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtCoins(n) {
        return Math.round(Number(n) || 0).toLocaleString('es-ES');
    }

    function icon(id, className) {
        return '<svg class="' + (className || '') + '" aria-hidden="true" focusable="false"><use href="#' + id + '"></use></svg>';
    }

    function badge(text, tone) {
        return '<span class="sf-badge"' + (tone ? ' data-tone="' + tone + '"' : '') + '>' + esc(text) + '</span>';
    }

    /** Redacción por densidad: la versión corta poda redundancias, no el tono. */
    function copy(compact, full, short) {
        return compact ? short : full;
    }

    /**
     * Control de selección: mismo marcado que el checkbox del modal de filtros
     * (label.custom-checkbox > input + .checkbox-visual > CheckmarkIcon).
     * `attrs` va sobre el <input>; el campo viaja en data-field y el grupo en name.
     */
    function radioHtml(field, group, value, checked, attrs) {
        return '<input type="radio" name="' + RADIO_NAME + esc(group) + '" value="' + esc(value) + '"'
            + ' data-field="' + field + '"' + (attrs || '') + (checked ? ' checked' : '') + '>'
            + '<span class="checkbox-visual" aria-hidden="true">'
            + '<svg viewBox="0 0 24 24" focusable="false"><use href="#sf-icon-checkmark"></use></svg>'
            + '</span>';
    }

    function collectSlots(root) {
        /** @type {Record<string, HTMLElement>} */
        const slots = {};
        root.querySelectorAll('[data-slot]').forEach((el) => { slots[el.dataset.slot] = el; });
        return slots;
    }

    // ───────────────────────────── dominio ───────────────────────────────

    function isChoiceAvailable(model, choice) {
        if (choice === Choice.ORIGINAL) return true;
        if (choice === Choice.CASHBACK) return !!model.cashback;
        if (choice === Choice.GAMBLE) return !!model.gamble;
        return false;
    }

    function modeNeedsSide(mode) {
        return Object.prototype.hasOwnProperty.call(SIDES_OF_MODE, mode);
    }

    /** La vitrina sólo es una decisión si algún puesto ofrece más de un ítem. */
    function poolIsDecidable(model) {
        return !!model.pool && model.pool.positions.some((p) => p.options.length > 1);
    }

    /**
     * Pantallas de detalle que exige `choice`. Para "Doble o nada" el lado sólo
     * entra en la ruta cuando el modo (fijo o ya elegido) lo necesita: así el
     * riel nunca promete un paso que quizá no exista.
     */
    function detailViewsFor(model, choice, mode) {
        if (choice === Choice.ORIGINAL) return poolIsDecidable(model) ? [View.POOL] : [];
        if (choice === Choice.GAMBLE && model.gamble) {
            const views = model.gamble.chooseMode ? [View.MODE] : [];
            const effectiveMode = model.gamble.chooseMode ? mode : model.gamble.fixedMode;
            if (modeNeedsSide(effectiveMode)) views.push(View.SIDE);
            return views;
        }
        return [];
    }

    /**
     * Espacio de decisión del modelo (puro). Con una única opción sin detalle
     * configurable el flujo carece de sentido y `hasDecision` es false: quien
     * llama no debe ofrecerlo ni abrirlo.
     * @param {PreChoiceModel} model
     * @returns {PreChoiceSpace}
     */
    function describe(model) {
        const safeModel = model || {};
        const choices = CHOICE_ORDER.filter((choice) => isChoiceAvailable(safeModel, choice));
        const hasAlternatives = choices.length > 1;
        const soleChoice = choices.length === 1 ? choices[0] : null;
        const soleDetail = soleChoice ? detailViewsFor(safeModel, soleChoice, null) : [];
        const onlyPool = !hasAlternatives && soleDetail[0] === View.POOL;
        return Object.freeze({
            choices: Object.freeze(choices),
            soleChoice,
            hasAlternatives,
            hasDecision: hasAlternatives || soleDetail.length > 0,
            cta: Object.freeze(onlyPool
                ? { fresh: 'Elegir mi ítem de la vitrina', freshShort: 'Elegir ítem', change: 'Cambiar ítem' }
                : { fresh: 'Elegir qué prefiero si gano', freshShort: 'Elegir premio', change: 'Cambiar elección' })
        });
    }

    /**
     * Ruta de vistas que recorre el flujo con la elección actual: el menú (sólo
     * si hay alternativas), los detalles de la opción y la confirmación. Es la
     * fuente de "siguiente", "anterior" y del riel de pasos, de modo que los
     * tres siempre cuentan la misma historia.
     */
    function routeFor(state, model) {
        const space = describe(model);
        const choice = space.soleChoice || state.choice;
        const route = space.hasAlternatives ? [View.MENU] : [];
        return route.concat(detailViewsFor(model, choice, state.mode), [View.CONFIRM]);
    }

    /** Vista sobre la que se posiciona `view` dentro de la ruta (SAVED ≙ CONFIRM). */
    function routeIndex(route, view) {
        return route.indexOf(view === View.SAVED ? View.CONFIRM : view);
    }

    /** Vista siguiente al pulsar "Continuar" desde la vista actual. */
    function nextView(state, model) {
        const route = routeFor(state, model);
        const index = routeIndex(route, state.view);
        return index === -1 ? null : (route[index + 1] || null);
    }

    /** Vista anterior (retorno contextual); desde "guardado" vuelve al inicio de la ruta. */
    function previousView(state, model) {
        const route = routeFor(state, model);
        if (state.view === View.SAVED) return route[0];
        const index = routeIndex(route, state.view);
        return index > 0 ? route[index - 1] : null;
    }

    // ───────────────────────────── estado ────────────────────────────────

    function initialState(model) {
        const space = describe(model);
        const pool = model.pool;
        const poolSelection = {};
        if (pool) {
            pool.positions.forEach((position) => {
                const saved = pool.saved[position.key];
                if (saved && position.options.some((o) => o.key === saved)) {
                    poolSelection[position.key] = saved;
                } else if (position.options.length === 1) {
                    // Un puesto con un solo ítem no se elige: queda fijado.
                    poolSelection[position.key] = position.options[0].key;
                }
            });
        }
        // Una elección guardada que el sorteo ya no permite se descarta: el
        // usuario vuelve a decidir entre lo que hoy existe de verdad.
        const current = isChoiceAvailable(model, model.current) ? model.current : null;
        const choice = space.soleChoice || current;
        const gamble = model.gamble;
        const savedGamble = (gamble && current === Choice.GAMBLE && gamble.saved) ? gamble.saved : {};
        let mode = null;
        if (gamble) mode = gamble.chooseMode ? (savedGamble.mode || null) : gamble.fixedMode;
        const side = mode && SIDES_OF_MODE[mode] && SIDES_OF_MODE[mode].includes(savedGamble.side)
            ? savedGamble.side : null;
        const state = {
            view: View.MENU,
            choice,
            mode,
            side,
            poolSelection,
            poolTab: 0,
            busy: false,
            error: ''
        };
        state.view = routeFor(state, model)[0];
        return state;
    }

    function canContinue(state) {
        switch (state.view) {
            case View.MENU: return !!state.choice;
            case View.MODE: return !!state.mode;
            case View.SIDE: return !!state.side;
            default: return true;
        }
    }

    /** Payload de persistencia derivado del estado (misma forma que el backend r9k). */
    function buildPayload(state) {
        const extra = {};
        if (state.choice === Choice.GAMBLE) {
            if (state.mode) extra.animation_mode = state.mode;
            if (state.side) extra.coin_choice = state.side;
        }
        if (state.choice === Choice.ORIGINAL && Object.keys(state.poolSelection).length > 0) {
            extra.pool_choices = { ...state.poolSelection };
        }
        return { choice: state.choice, extra };
    }

    // ───────────────────────── datos derivados ───────────────────────────

    function poolOption(model, positionKey, optionKey) {
        if (!model.pool || !optionKey) return null;
        const position = model.pool.positions.find((p) => p.key === positionKey);
        return position ? (position.options.find((o) => o.key === optionKey) || null) : null;
    }

    /** Nombres de los ítems de vitrina elegidos, con prefijo de puesto si hay varios. */
    function poolSelectionNames(state, model) {
        if (!model.pool) return [];
        const many = model.pool.positions.length > 1;
        return model.pool.positions
            .map((p) => {
                const opt = poolOption(model, p.key, state.poolSelection[p.key]);
                return opt ? ((many ? (p.label + ': ') : '') + opt.name) : null;
            })
            .filter(Boolean);
    }

    /** Nombre corto de lo que está en juego (premio o ítem de vitrina). */
    function stakeName(state, model) {
        if (model.pool) {
            const names = poolSelectionNames(state, model);
            return names.length ? names.join(' / ') : 'tu premio de la vitrina';
        }
        return model.prizeName;
    }

    /** Etiqueta final de la elección, para el resumen y la vista "guardado". */
    function choiceSummary(state, model) {
        switch (state.choice) {
            case Choice.CASHBACK:
                return 'Cashback ' + model.cashback.percent + '%'
                    + (model.cashback.coins > 0 ? ' · ' + fmtCoins(model.cashback.coins) + ' Bananos' : '');
            case Choice.GAMBLE: {
                const parts = ['Doble o nada'];
                if (state.mode) parts.push(MODE_LABEL[state.mode] || state.mode);
                if (state.side) parts.push(SIDE_LABEL[state.side] || state.side);
                return parts.join(' · ');
            }
            case Choice.ORIGINAL: {
                if (!model.pool) return model.prizeName;
                const names = poolSelectionNames(state, model);
                return names.length ? names.join(' / ') : 'Premio de la vitrina (asignación automática)';
            }
            default:
                return '';
        }
    }

    // ────────────────────────────── vistas ───────────────────────────────

    function prizeThumbHtml(model) {
        const thumb = model.prizeThumb || {};
        const art = thumb.image
            ? '<img src="' + esc(thumb.image) + '" alt="" loading="lazy">'
            : icon('sf-icon-gift');
        return '<span class="sf-option__thumb"' + (thumb.vars ? ' style="' + esc(thumb.vars) + '"' : '') + '>' + art + '</span>';
    }

    /** Grupo nativo de radios: <fieldset> con leyenda solo para lectores de pantalla. */
    function groupHtml(className, legend, itemsHtml) {
        return '<fieldset class="' + className + '"><legend class="sf-legend">' + esc(legend) + '</legend>'
            + itemsHtml + '</fieldset>';
    }

    function optionHtml(value, checked, thumbHtml, title, text, badges, extraClass) {
        return '<label class="sf-option custom-checkbox' + (extraClass ? ' ' + extraClass : '') + '">'
            + radioHtml('choice', 'choice', value, checked)
            + thumbHtml
            + '<span class="sf-option__body">'
            +   '<span class="sf-option__title">' + title + '</span>'
            +   '<span class="sf-option__text">' + text + '</span>'
            +   (badges ? '<span class="sf-option__meta">' + badges + '</span>' : '')
            + '</span>'
            + '</label>';
    }

    function renderMenu(state, model, compact) {
        const items = [];
        const isCurrent = (value) => model.current === value ? badge('Actual', 'accent') : '';

        const names = poolSelectionNames(state, model);
        const originalTitle = model.pool ? 'Ítem de la vitrina' : 'Premio original';
        const originalText = model.pool
            ? (names.length ? esc(names.join(' / ')) : 'Elige exactamente qué te llevas si ganas.')
            : esc(model.prizeName);
        items.push(optionHtml(Choice.ORIGINAL, state.choice === Choice.ORIGINAL, prizeThumbHtml(model),
            originalTitle, originalText, badge('Sin riesgo') + isCurrent(Choice.ORIGINAL)));

        if (model.cashback) {
            const cb = model.cashback;
            const text = cb.coins > 0
                ? '<strong>' + fmtCoins(cb.coins) + ' Bananos</strong>'
                    + copy(compact, ' en tu saldo, listos para gastar en la tienda.', ' en tu saldo, listos para la tienda.')
                : 'El ' + cb.percent + '% del valor del premio en Bananos'
                    + copy(compact, ', listos para gastar.', '.');
            items.push(optionHtml(Choice.CASHBACK, state.choice === Choice.CASHBACK,
                '<span class="sf-option__thumb sf-option__thumb--asset">' + model.assets.bananos(44) + '</span>',
                'Cashback ' + cb.percent + '%', text, badge('Flexible') + isCurrent(Choice.CASHBACK)));
        }

        if (model.gamble) {
            const stake = esc(stakeName(state, model));
            const bonus = model.gamble.bonusCoins;
            const reward = '<strong>' + stake
                + (bonus > 0 ? ' + ' + fmtCoins(bonus) + ' Bananos' : ' por duplicado') + '</strong>';
            const text = copy(compact,
                '<strong>50 %</strong> de probabilidad de ganar ' + reward + ', o quedarte sin premio.',
                'Al <strong>50 %</strong>: ganas ' + reward + ' o te quedas sin premio.');
            items.push(optionHtml(Choice.GAMBLE, state.choice === Choice.GAMBLE,
                '<span class="sf-option__thumb sf-option__thumb--dice">' + icon('sf-icon-dice') + '</span>',
                'Doble o nada', text, badge('Alto riesgo', 'danger') + isCurrent(Choice.GAMBLE), 'sf-option--risk'));
        }

        return groupHtml('sf-options', 'Opciones si ganas', items.join(''));
    }

    function renderPool(state, model, compact) {
        const positions = model.pool.positions;
        const tabbed = positions.length > 1;
        let html = '';

        if (tabbed) {
            html += '<div class="sf-tabs sf-tabs--flush" role="tablist" aria-label="Puestos">'
                + positions.map((p, i) =>
                    '<button type="button" role="tab" class="sf-tab" id="sf-prechoice-tab-' + i + '"'
                    + ' aria-selected="' + (i === state.poolTab) + '" aria-controls="sf-prechoice-panel-' + i + '"'
                    + ' tabindex="' + (i === state.poolTab ? '0' : '-1') + '" data-act="tab" data-index="' + i + '">'
                    + icon('sf-icon-trophy') + '<span>Puesto ' + esc(p.label) + '</span></button>'
                ).join('')
                + '</div>';
        }

        html += positions.map((p, i) =>
            '<section class="sf-prize-panel" id="sf-prechoice-panel-' + i + '"'
            + (tabbed ? ' role="tabpanel" aria-labelledby="sf-prechoice-tab-' + i + '"' : '')
            + (i === state.poolTab ? '' : ' hidden') + '>'
            + '<h3 class="sf-prize-group__title">' + icon('sf-icon-pointer') + '<span>' + esc(p.title) + '</span>'
            +   badge(p.options.length + (p.options.length === 1 ? ' opción' : ' opciones')) + '</h3>'
            + groupHtml('sf-prize-grid sf-pick-grid', p.title, p.options.map((opt) =>
                '<label class="sf-store-card sf-sc-card sf-pick-card custom-checkbox"' + opt.cardAttrs + '>'
                + radioHtml('pool', 'pool-' + p.key, opt.key, state.poolSelection[p.key] === opt.key,
                    ' data-position="' + esc(p.key) + '"')
                + opt.cardInner
                + '</label>'
            ).join(''))
            + '</section>'
        ).join('');

        html += '<div class="sf-notice">' + icon('sf-icon-pointer', 'sf-notice__icon')
            + '<p class="sf-notice__text">' + copy(compact,
                'Sin ítem marcado, si ganas y estás en vivo eliges en ese momento; si no estás, te asignamos el primero de la lista.',
                'Sin ítem marcado, eliges en vivo si ganas; si no estás, te asignamos el primero de la lista.')
            + '</p></div>';
        return html;
    }

    function tileHtml(field, value, checked, visualHtml, label) {
        return '<label class="sf-tile custom-checkbox">'
            + radioHtml(field, field, value, checked)
            + '<span class="sf-tile__visual">' + visualHtml + '</span>'
            + '<span class="sf-tile__label">' + esc(label) + '</span>'
            + '</label>';
    }

    function renderMode(state, model) {
        const a = model.assets;
        return groupHtml('sf-tiles sf-tiles--3', 'Modo de juego',
            tileHtml('mode', 'coin', state.mode === 'coin', a.coin(true, 56), MODE_LABEL.coin)
            + tileHtml('mode', 'roulette', state.mode === 'roulette', a.roulette(56), MODE_LABEL.roulette)
            + tileHtml('mode', 'slot', state.mode === 'slot', a.slot(56), MODE_LABEL.slot));
    }

    function renderSide(state, model) {
        const a = model.assets;
        if (state.mode === 'roulette') {
            return groupHtml('sf-tiles', 'Color de la ruleta',
                tileHtml('side', 'red', state.side === 'red',
                    '<span class="sf-tile__chip sf-tile__chip--red">Rojo</span>', SIDE_LABEL.red)
                + tileHtml('side', 'black', state.side === 'black',
                    '<span class="sf-tile__chip sf-tile__chip--black">Negro</span>', SIDE_LABEL.black));
        }
        return groupHtml('sf-tiles', 'Lado de la moneda',
            tileHtml('side', 'heads', state.side === 'heads', a.coin(true, 72), SIDE_LABEL.heads)
            + tileHtml('side', 'tails', state.side === 'tails', a.coin(false, 72), SIDE_LABEL.tails));
    }

    function summaryRow(term, detail) {
        return '<div class="sf-summary__row"><dt>' + term + '</dt><dd>' + detail + '</dd></div>';
    }

    function renderConfirm(state, model, compact) {
        const rows = [];
        let notice = '';
        const stake = esc(stakeName(state, model));

        if (state.choice === Choice.GAMBLE) {
            const bonus = model.gamble.bonusCoins;
            rows.push(summaryRow('Arriesgas', stake));
            rows.push(summaryRow('Si aciertas', stake + (bonus > 0 ? ' + ' + fmtCoins(bonus) + ' Bananos' : ' por duplicado')));
            rows.push(summaryRow('Si fallas', 'Te quedas sin premio'));
            const play = [MODE_LABEL[state.mode] || 'Modo del sorteo', SIDE_LABEL[state.side]].filter(Boolean).join(' · ');
            rows.push(summaryRow('Jugada', esc(play)));
            notice = '<div class="sf-notice" data-tone="warning">' + icon('sf-icon-warning', 'sf-notice__icon')
                + '<p class="sf-notice__text"><strong>Jugada individual al 50 %</strong>' + copy(compact,
                    'No es un nuevo sorteo entre participantes. Puedes cambiar esta decisión hasta que cierre la inscripción.',
                    'No es otro sorteo entre participantes. Puedes cambiarla hasta que cierre la inscripción.')
                + '</p></div>';
        } else if (state.choice === Choice.CASHBACK) {
            const cb = model.cashback;
            rows.push(summaryRow('Cambias', stake));
            rows.push(summaryRow('Recibes', cb.coins > 0
                ? fmtCoins(cb.coins) + ' Bananos <span class="sf-summary__hint">(' + cb.percent + '% del valor)</span>'
                : 'El ' + cb.percent + '% del valor del premio en Bananos'));
            notice = '<div class="sf-notice">' + icon('sf-icon-check', 'sf-notice__icon')
                + '<p class="sf-notice__text">Los Bananos se abonan a tu saldo en cuanto se anuncie al ganador.</p></div>';
        } else {
            const names = poolSelectionNames(state, model);
            if (model.pool && names.length === 0) {
                rows.push(summaryRow('Te llevas', 'Un ítem de la vitrina'));
                notice = '<div class="sf-notice">' + icon('sf-icon-pointer', 'sf-notice__icon')
                    + '<p class="sf-notice__text"><strong>Asignación automática</strong>' + copy(compact,
                        'Si ganas y estás en vivo, eliges en ese momento; si no estás, te asignamos el primero de la lista.',
                        'Eliges en vivo si ganas; si no estás, te asignamos el primero de la lista.')
                    + '</p></div>';
            } else {
                rows.push(summaryRow('Te llevas', esc(names.length ? names.join(' / ') : model.prizeName)));
                notice = '<div class="sf-notice">' + icon('sf-icon-check', 'sf-notice__icon')
                    + '<p class="sf-notice__text">Sin riesgo: si ganas, este premio es tuyo tal cual.</p></div>';
            }
        }

        return '<dl class="sf-summary">' + rows.join('') + '</dl>' + notice;
    }

    function renderSaved(state, model) {
        const cta = describe(model).cta.change;
        return '<dl class="sf-summary">'
            + summaryRow('Si ganas, aplicamos', esc(choiceSummary(state, model)))
            + '</dl>'
            + '<div class="sf-notice">' + icon('sf-icon-check', 'sf-notice__icon')
            + '<p class="sf-notice__text">Puedes cambiarlo desde el botón <strong>' + esc(cta) + '</strong> mientras la inscripción siga abierta.</p></div>';
    }

    /**
     * Acciones secundarias del pie: etiqueta y acción viajan juntas (nunca un
     * botón sin texto). `short` es la redacción para teléfono, donde el botón
     * debe leerse en una sola línea desde 320 px.
     */
    const SECONDARY = Object.freeze({
        later: Object.freeze({ label: 'Decidir más tarde', short: 'Decidir más tarde', act: 'dismiss' }),
        keep: Object.freeze({ label: 'Mantener mi elección actual', short: 'Mantener mi elección', act: 'dismiss' }),
        safe: Object.freeze({ label: 'Prefiero la opción segura', short: 'Prefiero lo seguro', act: 'safe' })
    });

    function secondaryAction(entry, compact) {
        return { label: copy(compact, entry.label, entry.short), act: entry.act };
    }

    /** Acción secundaria del menú: conservar lo ya guardado o posponer la decisión. */
    function menuSecondary(model, compact) {
        return secondaryAction(hasSavedChoice(model) ? SECONDARY.keep : SECONDARY.later, compact);
    }

    /**
     * Cabecera, pasos, cuerpo y pie de cada vista en una sola estructura.
     * `compact` selecciona la redacción corta en pantallas de teléfono.
     */
    function viewSpec(state, model, compact) {
        const back = backLabelFor(previousView(state, model));
        switch (state.view) {
            case View.MENU:
                return {
                    glyph: 'sf-icon-gift', tone: 'accent',
                    title: 'Si llegas a ganar, ¿qué prefieres?',
                    desc: copy(compact,
                        'Decide ahora y queda registrado. Si ganas y no estás en vivo, aplicamos esta elección por ti.',
                        'Queda registrado: si ganas y no estás en vivo, la aplicamos por ti.'),
                    body: renderMenu(state, model, compact),
                    primary: 'Continuar',
                    secondary: menuSecondary(model, compact),
                    back: null
                };
            case View.POOL:
                return {
                    glyph: 'sf-icon-layers', tone: 'accent',
                    title: 'Elige tu ítem de la vitrina',
                    desc: copy(compact,
                        'Marca lo que quieres llevarte si ganas. Puedes dejarlo sin marcar y decidir en vivo.',
                        'Marca lo que te llevas si ganas, o déjalo sin marcar y decide en vivo.'),
                    body: renderPool(state, model, compact),
                    primary: 'Continuar',
                    secondary: back ? null : menuSecondary(model, compact),
                    back
                };
            case View.MODE:
                return {
                    glyph: 'sf-icon-dice', tone: 'danger',
                    title: 'Doble o nada: elige el modo',
                    desc: copy(compact,
                        'Así se resolverá tu apuesta en vivo si resultas ganador.',
                        'Así se resuelve tu apuesta en vivo si ganas.'),
                    body: renderMode(state, model),
                    primary: 'Continuar',
                    secondary: secondaryAction(SECONDARY.safe, compact),
                    back
                };
            case View.SIDE:
                return {
                    glyph: 'sf-icon-dice', tone: 'danger',
                    title: state.mode === 'roulette' ? '¿Rojo o negro?' : '¿Cara o sello?',
                    desc: state.mode === 'roulette'
                        ? 'Ganas si la bola cae en tu color.'
                        : 'Ganas si la moneda cae de tu lado.',
                    body: renderSide(state, model),
                    primary: 'Continuar',
                    secondary: secondaryAction(SECONDARY.safe, compact),
                    back
                };
            case View.CONFIRM: {
                const risky = state.choice === Choice.GAMBLE;
                return {
                    glyph: risky ? 'sf-icon-dice' : 'sf-icon-check', tone: risky ? 'danger' : 'accent',
                    title: risky ? 'Confirma tu apuesta' : 'Confirma tu elección',
                    desc: risky
                        ? 'Revisa lo que está en juego antes de guardar.'
                        : 'Revisa el resumen antes de guardar.',
                    body: renderConfirm(state, model, compact),
                    primary: risky
                        ? copy(compact, 'Sí, arriesgar mi premio', 'Arriesgar mi premio')
                        : 'Guardar elección',
                    secondary: risky ? secondaryAction(SECONDARY.safe, compact) : null,
                    back
                };
            }
            case View.SAVED:
                return {
                    glyph: 'sf-icon-check', tone: 'success',
                    title: 'Preferencia guardada',
                    desc: copy(compact,
                        'Listo. Si ganas, aplicamos tu elección aunque no estés en vivo.',
                        'Si ganas, la aplicamos aunque no estés en vivo.'),
                    body: renderSaved(state, model),
                    primary: 'Volver al sorteo',
                    secondary: null,
                    back: describe(model).hasAlternatives ? 'Cambiar elección' : back
                };
            default:
                return null;
        }
    }

    /** Hay una elección guardada que el sorteo sigue permitiendo. */
    function hasSavedChoice(model) {
        return !!model.current && isChoiceAvailable(model, model.current);
    }

    /** Etiqueta del botón "atrás" según la vista destino; null cuando no hay adonde volver. */
    function backLabelFor(view) {
        switch (view) {
            case View.MENU: return 'Volver a opciones';
            case View.POOL: return 'Cambiar ítem';
            case View.MODE: return 'Cambiar modo';
            case View.SIDE: return 'Cambiar jugada';
            default: return null;
        }
    }

    // ───────────────────────────── render ────────────────────────────────

    function render() {
        if (!session) return;
        const { state, model, slots, dialog } = session;
        const spec = viewSpec(state, model, session.compactMedia.matches);
        if (!spec) return;

        dialog.dataset.view = state.view;

        if (slots['glyph-icon']) slots['glyph-icon'].setAttribute('href', '#' + spec.glyph);
        if (slots.glyph) slots.glyph.dataset.tone = spec.tone;
        slots.title.textContent = spec.title;
        slots.desc.textContent = spec.desc;

        renderSteps();

        slots.view.innerHTML = spec.body;
        slots.view.scrollTop = 0;

        slots.primary.textContent = spec.primary;
        slots.primary.disabled = !canContinue(state);

        slots.secondary.hidden = !spec.secondary;
        slots.secondary.textContent = spec.secondary ? spec.secondary.label : '';
        if (spec.secondary) slots.secondary.dataset.act = spec.secondary.act;
        else delete slots.secondary.dataset.act;

        slots.back.hidden = !spec.back;
        slots['back-label'].textContent = spec.back || '';

        renderAlert();
        renderBusy();
        focusView();
    }

    /**
     * Riel de pasos derivado de la ruta actual: se reconstruye sólo cuando la
     * ruta cambia (elegir otra opción o modo) y en el resto de renders apenas
     * se actualizan los estados, sin tocar la estructura del DOM.
     */
    function renderSteps() {
        const { state, model, slots } = session;
        const route = routeFor(state, model);
        const labels = route.map((view) => stepLabel(view, state));
        const signature = labels.join('>');
        if (session.stepsSignature !== signature) {
            session.stepsSignature = signature;
            slots.steps.innerHTML = route.map((view, index) =>
                '<li class="sf-steps__item" data-step="' + view + '">'
                + '<span class="sf-steps__index" aria-hidden="true">' + (index + 1) + '</span>'
                + '<span class="sf-steps__label">' + esc(labels[index]) + '</span></li>'
            ).join('');
        }

        const finished = state.view === View.SAVED;
        const currentIndex = finished ? route.length : routeIndex(route, state.view);
        Array.prototype.forEach.call(slots.steps.children, (item, index) => {
            const isCurrent = index === currentIndex;
            const nextState = index < currentIndex ? 'done' : (isCurrent ? 'current' : 'todo');
            if (item.dataset.state !== nextState) item.dataset.state = nextState;
            if (isCurrent) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });
        slots.steps.setAttribute('aria-label', finished
            ? 'Progreso de tu elección: completado'
            : 'Progreso de tu elección: paso ' + (currentIndex + 1) + ' de ' + route.length);
    }

    function renderAlert() {
        const { alert } = session.slots;
        alert.hidden = !session.state.error;
        alert.textContent = session.state.error;
    }

    /** Estado ocupado: bloquea toda interacción del panel mientras persiste. */
    function renderBusy() {
        const { state, slots, dialog } = session;
        const panel = dialog.querySelector('.sf-dialog__panel');
        if (panel) panel.setAttribute('aria-busy', state.busy ? 'true' : 'false');
        slots.view.toggleAttribute('inert', state.busy);
        slots.primary.disabled = state.busy || !canContinue(state);
        slots.primary.classList.toggle('is-busy', state.busy);
        slots.secondary.disabled = state.busy;
        slots.back.disabled = state.busy;
    }

    /** Radio marcado entre los visibles (o el primero); si no hay radios, el CTA. */
    function preferredFocus() {
        const { slots } = session;
        const radios = Array.from(slots.view.querySelectorAll('input[type="radio"]'))
            .filter((el) => el.closest('[hidden]') === null);
        return radios.find((el) => el.checked) || radios[0] || slots.primary;
    }

    function focusView() {
        const target = preferredFocus();
        if (target && !target.disabled) target.focus({ preventScroll: true });
    }

    // ─────────────────────────── transiciones ────────────────────────────

    function setState(patch) {
        Object.assign(session.state, patch);
        render();
    }

    function goTo(view) {
        setState({ view, error: '' });
    }

    /**
     * Sincroniza el estado con un radio nativo recién marcado. El DOM ya
     * refleja la selección (grupo por `name`), así que la vista no se
     * re-renderiza; sólo el riel de pasos, que sigue a la ruta elegida.
     */
    function pick(field, value) {
        const { state, model } = session;
        const patch = { [field]: value, error: '' };
        if (field === 'mode' && value !== state.mode) patch.side = null;
        if (field === 'choice' && value === Choice.GAMBLE && model.gamble && !model.gamble.chooseMode) {
            patch.mode = model.gamble.fixedMode;
        }
        Object.assign(state, patch);
        session.slots.primary.disabled = state.busy || !canContinue(state);
        renderSteps();
    }

    function pickPool(positionKey, optionKey) {
        session.state.poolSelection = { ...session.state.poolSelection, [positionKey]: optionKey };
    }

    function selectPoolTab(index) {
        const { slots, state } = session;
        state.poolTab = index;
        slots.view.querySelectorAll('[role="tab"]').forEach((tab) => {
            const selected = Number(tab.dataset.index) === index;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });
        slots.view.querySelectorAll('[role="tabpanel"]').forEach((panel) => {
            panel.hidden = panel.id !== 'sf-prechoice-panel-' + index;
        });
    }

    async function persist() {
        const current = session;
        const { state, model } = current;
        if (state.busy || !state.choice) return;
        state.busy = true;
        state.error = '';
        renderAlert();
        renderBusy();

        const payload = buildPayload(state);
        let ok = false;
        try {
            ok = await current.save(payload.choice, payload.extra);
        } catch (e) {
            ok = false;
        }
        // El diálogo pudo cerrarse durante la espera: no tocar el DOM huérfano.
        if (session !== current || !SfDialog.isOpen(current.dialog)) return;

        state.busy = false;
        if (ok) {
            current.result = payload;
            model.current = payload.choice;
            goTo(View.SAVED);
        } else {
            setState({ error: SAVE_ERROR_TEXT });
        }
    }

    function continueFlow() {
        const { state, model } = session;
        if (!canContinue(state) || state.busy) return;
        if (state.view === View.CONFIRM) { persist(); return; }
        if (state.view === View.SAVED) { SfDialog.close(session.dialog, 'confirm'); return; }
        const next = nextView(state, model);
        if (next) goTo(next);
    }

    // ────────────────────────────── eventos ──────────────────────────────

    /** Radios nativos (opción, modo, lado, vitrina): un solo listener delegado. */
    function onChange(event) {
        if (!session || session.state.busy) return;
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'radio' || !input.checked) return;
        if (!session.slots.view.contains(input)) return;

        if (input.dataset.field === 'pool') pickPool(input.dataset.position, input.value);
        else pick(input.dataset.field, input.value);
    }

    function onClick(event) {
        if (!session || session.state.busy) return;
        const target = event.target instanceof Element ? event.target.closest('[data-act]') : null;
        if (!target || !session.dialog.contains(target)) return;

        switch (target.dataset.act) {
            case 'tab':
                selectPoolTab(Number(target.dataset.index));
                break;
            case 'continue':
                continueFlow();
                break;
            case 'back': {
                const prev = previousView(session.state, session.model);
                if (prev) goTo(prev);
                break;
            }
            case 'safe': {
                const safeState = { ...session.state, choice: Choice.ORIGINAL };
                setState({ view: routeFor(safeState, session.model)[0], choice: Choice.ORIGINAL, error: '' });
                break;
            }
            case 'dismiss':
                SfDialog.close(session.dialog, 'dismiss');
                break;
            default:
                break;
        }
    }

    function onCompactChange() {
        if (session) render();
    }

    /** Flechas en el tablist de puestos (roving tabindex); los radios son nativos. */
    function onKeydown(event) {
        if (!session || session.state.busy) return;
        const key = event.key;
        if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End'].includes(key)) return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.getAttribute('role') !== 'tab') return;

        const tablist = target.closest('[role="tablist"]');
        if (!tablist || !session.slots.view.contains(tablist)) return;
        const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
        const index = tabs.indexOf(target);
        if (index === -1) return;

        event.preventDefault();
        let next = index;
        if (key === 'ArrowRight' || key === 'ArrowDown') next = (index + 1) % tabs.length;
        else if (key === 'ArrowLeft' || key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length;
        else if (key === 'Home') next = 0;
        else if (key === 'End') next = tabs.length - 1;

        tabs[next].focus();
        selectPoolTab(Number(tabs[next].dataset.index));
    }

    // ─────────────────────────── ciclo de vida ───────────────────────────

    /**
     * @param {{ dialog: HTMLDialogElement, model: PreChoiceModel,
     *           save: (choice: string, extra: Object) => Promise<boolean>,
     *           opener?: HTMLElement|null, chainFrom?: HTMLDialogElement|null }} options
     * @returns {Promise<{ choice: string, extra: Object } | null>}
     */
    function open(options) {
        const { dialog, model, save } = options || {};
        if (!(dialog instanceof HTMLDialogElement) || !model || typeof save !== 'function' || !window.SfDialog) {
            return Promise.resolve(null);
        }
        // Sin nada que decidir (una sola opción y sin detalle configurable) el
        // flujo no se abre: la única opción se aplica sola al ganar.
        if (!describe(model).hasDecision) {
            return Promise.resolve(null);
        }
        if (session) {
            return session.done;
        }

        const controller = new AbortController();
        const slots = collectSlots(dialog);
        const compactMedia = window.matchMedia(COMPACT_MEDIA);
        session = {
            dialog,
            model,
            save,
            slots,
            controller,
            compactMedia,
            state: initialState(model),
            stepsSignature: '',
            result: null,
            done: null
        };

        render();

        dialog.addEventListener('click', onClick, { signal: controller.signal });
        dialog.addEventListener('change', onChange, { signal: controller.signal });
        dialog.addEventListener('keydown', onKeydown, { signal: controller.signal });
        // Cruzar el breakpoint (giro, ventana redimensionada) re-renderiza la
        // vista actual desde `state`: el DOM nunca es fuente de verdad.
        compactMedia.addEventListener('change', onCompactChange, { signal: controller.signal });

        const dialogOptions = { initialFocus: preferredFocus(), dismissible: true };
        if (options.opener instanceof HTMLElement) dialogOptions.opener = options.opener;

        const opened = options.chainFrom instanceof HTMLDialogElement && SfDialog.isOpen(options.chainFrom)
            ? SfDialog.swap(options.chainFrom, dialog, dialogOptions)
            : SfDialog.open(dialog, dialogOptions);

        session.done = opened.then(() => {
            const result = session ? session.result : null;
            teardown();
            return result;
        });
        return session.done;
    }

    function teardown() {
        if (!session) return;
        const { controller, slots, dialog } = session;
        controller.abort();
        slots.view.innerHTML = '';
        slots.view.removeAttribute('inert');
        slots.steps.innerHTML = '';
        slots.alert.hidden = true;
        slots.alert.textContent = '';
        delete dialog.dataset.view;
        session = null;
    }

    function close() {
        if (session) SfDialog.close(session.dialog, 'interrupted');
    }

    function isOpen() {
        return session !== null;
    }

    return Object.freeze({ describe, open, close, isOpen });
})();
