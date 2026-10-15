'use strict';

/**
 * Tests unitarios de la lógica pura de ShopCarousel (geometría canónica de
 * snap-points y física free-snap). Sin DOM: se ejecutan en Node copiando
 * módulo + test a un directorio temporal sin package.json (el package.json
 * del repo es `type: module` y estos archivos usan require()):
 *
 *     tmp=$(mktemp -d) && cp public_html/carousel-interaction{,.test}.js "$tmp" \
 *       && node "$tmp/carousel-interaction.test.js"
 */
(function () {
    const isNode = typeof module !== 'undefined' && !!module.exports;

    let api;
    if (isNode) {
        api = require('./carousel-interaction.js')._pure;
    } else {
        api = typeof ShopCarousel !== 'undefined' ? ShopCarousel._pure : null;
    }

    function fail(message) {
        throw new Error(message);
    }

    function assertEqual(label, actual, expected) {
        if (actual !== expected) {
            fail(label + ': expected ' + expected + ' but got ' + actual);
        }
    }

    function assertClose(label, actual, expected, tolerance) {
        const tol = tolerance === undefined ? 1e-9 : tolerance;
        if (!(Math.abs(actual - expected) <= tol)) {
            fail(label + ': expected ~' + expected + ' but got ' + actual);
        }
    }

    function assertArrayClose(label, actual, expected, tolerance) {
        if (!Array.isArray(actual) || actual.length !== expected.length) {
            fail(label + ': length mismatch. expected [' + expected + '] but got [' + actual + ']');
        }
        for (let i = 0; i < actual.length; i++) {
            assertClose(label + ' (index ' + i + ')', actual[i], expected[i], tolerance);
        }
    }

    /** Offsets naturales de N slides con paso `step` (offsetLeft − padding). */
    function offsets(count, step) {
        return Array.from({ length: count }, (_, i) => Math.round(i * step));
    }

    function runTests() {
        if (!api) {
            fail('ShopCarousel pure API not found');
        }

        const {
            canonicalPoints, nearestIndex, project, rubberband,
            inverseEaseOutCubic, exitVelocity, EPSILON_PX,
        } = api;

        // ── canonicalPoints: contrato canónico ─────────────────────────

        // Sin overflow (todos los slides visibles): un único punto en 0.
        assertArrayClose('no overflow', canonicalPoints(offsets(4, 250), 0), [0]);
        assertArrayClose('negative max clamps to [0]', canonicalPoints(offsets(4, 250), -3), [0]);
        assertArrayClose('empty track', canonicalPoints([], 0), [0]);

        // Caso limpio: el último punto natural coincide con max.
        assertArrayClose(
            'exact fit',
            canonicalPoints(offsets(5, 250), 500),
            [0, 250, 500]
        );

        // Residuo subpíxel r: el punto natural cercano al tope SE FUSIONA con
        // max (no coexisten) — el bug de frontera original, por construcción
        // imposible. Cubre r ∈ {0.4, 1, 3}.
        [0.4, 1, 3].forEach((r) => {
            const points = canonicalPoints(offsets(6, 247), 3 * 247 + r);
            assertArrayClose('residue r=' + r + ' fuses boundary', points, [0, 247, 494, 741 + r]);
        });

        // Residuo justo por debajo de medio paso: aún se fusiona.
        assertArrayClose(
            'residue just under half a step fuses',
            canonicalPoints(offsets(6, 240), 2 * 240 + 119),
            [0, 240, 480 + 119]
        );

        // Residuo de al menos medio paso: es una página real y se conserva.
        assertArrayClose(
            'residue of half a step is a real page',
            canonicalPoints(offsets(6, 240), 2 * 240 + 121),
            [0, 240, 480, 480 + 121]
        );

        // Dedupe con epsilon: offsets clampeados duplicados colapsan.
        const clamped = canonicalPoints(offsets(8, 250), 700);
        assertArrayClose('clamped duplicates dedupe + fuse', clamped, [0, 250, 500, 700]);
        for (let i = 1; i < clamped.length; i++) {
            if (!(clamped[i] - clamped[i - 1] > EPSILON_PX)) {
                fail('strictly increasing beyond EPSILON violated at ' + i);
            }
        }

        // Anchos fraccionales reales (tile 247.33px redondeado por el DOM).
        const fractional = canonicalPoints(
            [0, 263, 527, 790, 1054, 1317],
            1319
        );
        assertEqual('fractional widths: last point is exactly max',
            fractional[fractional.length - 1], 1319);
        assertArrayClose('fractional widths canonical list', fractional, [0, 263, 527, 790, 1054, 1319]);

        // Una sola tarjeta con overflow diminuto.
        assertArrayClose('single slide, tiny overflow', canonicalPoints([0], 2), [0, 2]);

        // ── nearestIndex ────────────────────────────────────────────────

        const points = [0, 250, 500, 741];
        assertEqual('nearest at start', nearestIndex(points, 0), 0);
        assertEqual('nearest below zero (overscroll)', nearestIndex(points, -40), 0);
        assertEqual('nearest mid rounds down', nearestIndex(points, 124), 0);
        assertEqual('nearest mid rounds up', nearestIndex(points, 126), 1);
        assertEqual('nearest tie goes to lower index', nearestIndex(points, 125), 0);
        assertEqual('nearest at end', nearestIndex(points, 741), 3);
        assertEqual('nearest past end (overscroll)', nearestIndex(points, 900), 3);
        assertEqual('single point list', nearestIndex([0], 300), 0);

        // Paginación por índices: desde el final, un paso atrás es una página
        // completa (no un residuo).
        const backIndex = nearestIndex(points, 741) - 1;
        assertEqual('first prev from end is a full page', points[backIndex], 500);

        // ── Physics.project (keen: dist = v²/f, f = base + |v|/L) ───────

        assertClose('project zero velocity distance', project(0, 1000).distance, 0);
        assertClose('project zero velocity duration', project(0, 1000).duration, 0);
        const v = 0.0025;
        const friction = 1.47e-7 + v / 1000;
        const forward = project(v, 1000);
        assertClose('project distance', forward.distance, (v * v) / friction);
        assertClose('project duration', forward.duration, (v / friction) * 2);
        const backward = project(-v, 1000);
        assertClose('project is odd in v', backward.distance, -forward.distance);
        assertClose('project duration is even in v', backward.duration, forward.duration);
        if (!(project(v, 100).distance < forward.distance)) {
            fail('smaller L must brake harder (overshoot)');
        }

        // ── Physics.rubberband ──────────────────────────────────────────

        assertClose('rubberband inside bounds is identity', rubberband(12, 0, 1000), 12);
        assertClose('rubberband attenuates quadratically', rubberband(10, 125, 1000), 10 * 0.75 * 0.75);
        assertClose('rubberband dies at half window', rubberband(10, 500, 1000), 0);
        assertClose('rubberband beyond half window stays 0', rubberband(10, 900, 1000), 0);

        // ── Physics.inverseEaseOutCubic ─────────────────────────────────

        assertClose('inverse easeOutCubic at 0', inverseEaseOutCubic(0), 0);
        assertClose('inverse easeOutCubic at 1', inverseEaseOutCubic(1), 1);
        const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);
        [0.25, 0.5, 0.9].forEach((ratio) => {
            assertClose('inverse easeOutCubic roundtrip ' + ratio,
                easeOutCubic(inverseEaseOutCubic(ratio)), ratio);
        });
        assertClose('inverse easeOutCubic clamps ratio', inverseEaseOutCubic(1.5), 1);

        // ── Physics.exitVelocity ────────────────────────────────────────

        const now = 10000;
        assertClose('exitVelocity with no samples', exitVelocity([], now), 0);
        assertClose('exitVelocity with one sample (no Δt)', exitVelocity([{ time: now - 50, delta: 8 }], now), 0);

        // Muestras uniformes: 10px cada 16ms → 0.625 px/ms; la PRIMERA
        // muestra de distancia cuenta (Δt solo entre timestamps).
        const uniform = [0, 16, 32, 48].map((t) => ({ time: now - 60 + t, delta: 10 }));
        assertClose('exitVelocity uniform samples', exitVelocity(uniform, now), 40 / 48);

        // Muestras fuera de la ventana de 200ms se descartan.
        const stale = [{ time: now - 500, delta: 100 }].concat(uniform);
        assertClose('exitVelocity ignores stale samples', exitVelocity(stale, now), 40 / 48);

        // Inversión de signo resetea el acumulado.
        const reversed = [
            { time: now - 64, delta: 12 },
            { time: now - 48, delta: 12 },
            { time: now - 32, delta: -10 },
            { time: now - 16, delta: -10 },
        ];
        assertClose('exitVelocity resets on sign flip', exitVelocity(reversed, now), -20 / 16);
    }

    try {
        runTests();
        if (typeof console !== 'undefined' && console.log) {
            console.log('[carousel-interaction.test] All tests passed');
        }
    } catch (err) {
        if (typeof console !== 'undefined' && console.error) {
            console.error('[carousel-interaction.test] FAILED:', err && err.message ? err.message : err);
        }
        if (isNode) {
            process.exitCode = 1;
        }
    }
})();
