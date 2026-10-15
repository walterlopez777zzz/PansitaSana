'use strict';

(function () {
  const isNode = typeof module !== 'undefined' && !!module.exports;

  let api;
  if (isNode) {
    api = require('./SectionSpy.js')._pure;
  } else {
    api = typeof SectionSpy !== 'undefined' ? SectionSpy._pure : null;
  }

  function fail(message) {
    throw new Error(message);
  }

  function assertEqual(label, actual, expected) {
    if (actual !== expected) {
      fail(label + ': expected ' + expected + ' but got ' + actual);
    }
  }

  function assertArrayEqual(label, actual, expected) {
    if (!Array.isArray(actual) || !Array.isArray(expected) || actual.length !== expected.length) {
      fail(label + ': length mismatch. expected [' + expected + '] but got [' + actual + ']');
    }
    for (let i = 0; i < actual.length; i++) {
      if (actual[i] !== expected[i]) {
        fail(label + ': index ' + i + ' expected ' + expected[i] + ' but got ' + actual[i]);
      }
    }
  }

  function runTests() {
    if (!api) {
      fail('SectionSpy pure API not found');
    }

    const { computeBoundaries, resolveActiveIndex } = api;

    // computeBoundaries: punto medio de cada hueco
    assertArrayEqual('no sections', computeBoundaries([]), []);
    assertArrayEqual('single section', computeBoundaries([{ top: 0, bottom: 100 }]), []);
    assertArrayEqual(
      'two sections with gap',
      computeBoundaries([{ top: 0, bottom: 100 }, { top: 148, bottom: 300 }]),
      [124]
    );
    assertArrayEqual(
      'three sections',
      computeBoundaries([
        { top: 0, bottom: 100 },
        { top: 148, bottom: 300 },
        { top: 348, bottom: 500 }
      ]),
      [124, 324]
    );
    assertArrayEqual(
      'adjacent sections without gap',
      computeBoundaries([{ top: 0, bottom: 200 }, { top: 200, bottom: 400 }]),
      [200]
    );

    // resolveActiveIndex: partición total — cualquier focal pertenece a una sección
    const boundaries = [124, 324];
    assertEqual('empty partition', resolveActiveIndex([], 50, -1, 12) >= 0, true);
    assertEqual('focal in first section', resolveActiveIndex(boundaries, 50, -1, 12), 0);
    assertEqual('focal exactly at boundary (no current)', resolveActiveIndex(boundaries, 124, -1, 12), 1);
    assertEqual('focal in second section', resolveActiveIndex(boundaries, 200, -1, 12), 1);
    assertEqual('focal in gap belongs to a section', resolveActiveIndex(boundaries, 123.9, -1, 12), 0);
    assertEqual('focal in third section', resolveActiveIndex(boundaries, 400, -1, 12), 2);
    assertEqual('focal before all content clamps to first', resolveActiveIndex(boundaries, -500, -1, 12), 0);
    assertEqual('focal past all content clamps to last', resolveActiveIndex(boundaries, 99999, -1, 12), 2);

    // Histéresis simétrica: dentro de ±h de la frontera se conserva el actual
    assertEqual('hysteresis holds downward', resolveActiveIndex(boundaries, 130, 0, 12), 0);
    assertEqual('hysteresis holds upward', resolveActiveIndex(boundaries, 118, 1, 12), 1);
    assertEqual('beyond hysteresis switches down', resolveActiveIndex(boundaries, 137, 0, 12), 1);
    assertEqual('beyond hysteresis switches up', resolveActiveIndex(boundaries, 111, 1, 12), 0);
    assertEqual('hysteresis ignores non-adjacent jump', resolveActiveIndex(boundaries, 400, 0, 12), 2);
    assertEqual('zero hysteresis switches at boundary', resolveActiveIndex(boundaries, 124, 0, 0), 1);
  }

  try {
    runTests();
    if (typeof console !== 'undefined' && console.log) {
      console.log('[SectionSpy.test] All tests passed');
    }
  } catch (err) {
    if (typeof console !== 'undefined' && console.error) {
      console.error('[SectionSpy.test] FAILED:', err && err.message ? err.message : err);
    }
    if (isNode) {
      process.exitCode = 1;
    }
  }
})();
