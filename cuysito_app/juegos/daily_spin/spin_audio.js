/*
    ═══════════════════════════════════════════════════════════
    SpinAudioEngine — Web Audio API synthesizer for Daily Spin
    ═══════════════════════════════════════════════════════════
    
    Generates short percussive "tick" sounds procedurally using
    oscillators + gain envelopes. Zero external file dependencies.
    
    Design decisions:
    - Each tick is a fresh oscillator (allows unlimited overlap).
    - Pitch is parameterized so it can rise as the spin decelerates,
      creating the classic "slot machine winding down" feel.
    - AudioContext is lazily created on first user gesture to comply
      with browser autoplay policies.
    - Singleton pattern: only one AudioContext is ever allocated.

    Usage:
      SpinAudioEngine.playTick()          // default pitch
      SpinAudioEngine.playTick(800)       // custom frequency Hz
      SpinAudioEngine.playTick(600, 0.3)  // custom freq + volume
*/

const SpinAudioEngine = (() => {
    'use strict';

    /* ── Private state ── */
    let _ctx = null;
    let _masterGain = null;
    let _isReady = false;

    /* ── Configuration constants ── */
    const CONFIG = Object.freeze({
        /** Base frequency (Hz) for the tick oscillator */
        BASE_FREQ: 600,
        /** Duration of the gain attack phase (seconds) */
        ATTACK_TIME: 0.003,
        /** Duration of the gain decay/release phase (seconds) */
        DECAY_TIME: 0.06,
        /** Peak gain during attack (0–1, relative to master) */
        PEAK_GAIN: 0.85,
        /** Master volume ceiling (0–1) */
        MASTER_VOLUME: 0.45,
        /** Oscillator waveform type */
        WAVE_TYPE: 'triangle',
        /** High-pass filter cutoff (Hz) — removes sub bass rumble */
        HP_FREQ: 400,
        /** Click body bandpass center (Hz) */
        BP_FREQ: 2800,
        /** Click body bandpass Q */
        BP_Q: 1.8,
    });

    /* ══════════════════════════════════
       Core: lazy AudioContext bootstrap
       ══════════════════════════════════ */

    /**
     * Ensures the AudioContext is created and resumed.
     * Safe to call repeatedly — only initializes once.
     * @returns {boolean} true if the context is usable
     */
    function _ensureContext() {
        if (_isReady && _ctx && _ctx.state === 'running') return true;

        try {
            if (!_ctx) {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return false;

                _ctx = new AudioCtx();

                /* Master gain → destination (single output node) */
                _masterGain = _ctx.createGain();
                _masterGain.gain.value = CONFIG.MASTER_VOLUME;
                _masterGain.connect(_ctx.destination);
            }

            /* Resume if suspended (autoplay policy) */
            if (_ctx.state === 'suspended') {
                _ctx.resume();
            }

            _isReady = true;
            return true;
        } catch {
            return false;
        }
    }

    /* ══════════════════════════════════
       Tick sound synthesis
       ══════════════════════════════════ */

    /**
     * Plays a single short percussive tick.
     *
     * Architecture:
     *   Oscillator → BandPass → HighPass → GainEnvelope → MasterGain → Destination
     *
     * The oscillator runs for a tiny duration and self-disposes.
     * No cleanup needed — the browser GC handles disconnected nodes.
     *
     * @param {number} [freq=CONFIG.BASE_FREQ] - Oscillator frequency in Hz
     * @param {number} [volume=1] - Relative volume multiplier (0–1)
     */
    function playTick(freq, volume) {
        if (!_ensureContext()) return;

        const now = _ctx.currentTime;
        const f = typeof freq === 'number' ? freq : CONFIG.BASE_FREQ;
        const vol = typeof volume === 'number' ? Math.max(0, Math.min(1, volume)) : 1;

        /* ── Oscillator (tone source) ── */
        const osc = _ctx.createOscillator();
        osc.type = CONFIG.WAVE_TYPE;
        osc.frequency.setValueAtTime(f, now);
        /* Slight downward pitch sweep for a "clicky" transient feel */
        osc.frequency.exponentialRampToValueAtTime(
            f * 0.4,
            now + CONFIG.ATTACK_TIME + CONFIG.DECAY_TIME
        );

        /* ── Bandpass filter (shapes the click body) ── */
        const bp = _ctx.createBiquadFilter();
        bp.type = 'bandpass';
        bp.frequency.value = CONFIG.BP_FREQ;
        bp.Q.value = CONFIG.BP_Q;

        /* ── High-pass filter (removes low-end mud) ── */
        const hp = _ctx.createBiquadFilter();
        hp.type = 'highpass';
        hp.frequency.value = CONFIG.HP_FREQ;

        /* ── Gain envelope (sharp attack → fast decay) ── */
        const env = _ctx.createGain();
        env.gain.setValueAtTime(0.0001, now);
        env.gain.linearRampToValueAtTime(CONFIG.PEAK_GAIN * vol, now + CONFIG.ATTACK_TIME);
        env.gain.exponentialRampToValueAtTime(0.0001, now + CONFIG.ATTACK_TIME + CONFIG.DECAY_TIME);

        /* ── Signal chain ── */
        osc.connect(bp);
        bp.connect(hp);
        hp.connect(env);
        env.connect(_masterGain);

        /* ── Schedule lifecycle ── */
        osc.start(now);
        osc.stop(now + CONFIG.ATTACK_TIME + CONFIG.DECAY_TIME + 0.01);
    }

    /* ══════════════════════════════════
       Win sound synthesis
       ══════════════════════════════════ */

    /**
     * Plays a short celebratory "win" arpeggio.
     *
     * Three rapid ascending tones create a satisfying
     * reward jingle without any external audio files.
     *
     * @param {number} [volume=1] - Relative volume multiplier (0–1)
     */
    function playWin(volume) {
        if (!_ensureContext()) return;

        const vol = typeof volume === 'number' ? Math.max(0, Math.min(1, volume)) : 1;
        const notes = [523.25, 659.25, 783.99]; // C5, E5, G5
        const noteGap = 0.09;
        const noteDuration = 0.18;

        notes.forEach((freq, i) => {
            const start = _ctx.currentTime + i * noteGap;

            const osc = _ctx.createOscillator();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, start);

            const env = _ctx.createGain();
            env.gain.setValueAtTime(0.0001, start);
            env.gain.linearRampToValueAtTime(0.7 * vol, start + 0.008);
            env.gain.exponentialRampToValueAtTime(0.0001, start + noteDuration);

            osc.connect(env);
            env.connect(_masterGain);

            osc.start(start);
            osc.stop(start + noteDuration + 0.01);
        });
    }

    /* ══════════════════════════════════
       Context warm-up (call on user gesture)
       ══════════════════════════════════ */

    /**
     * Warms up the AudioContext. Call this from a click/touch handler
     * to guarantee the context is in 'running' state before the first
     * programmatic playTick() call.
     */
    function warmUp() {
        _ensureContext();
    }

    /**
     * Permanently close the AudioContext and release resources.
     * Only needed if the module is being torn down.
     */
    function dispose() {
        if (_ctx) {
            _ctx.close().catch(() => { });
            _ctx = null;
            _masterGain = null;
            _isReady = false;
        }
    }

    /* ══════════════════════════════════
       Public API
       ══════════════════════════════════ */

    return Object.freeze({
        playTick,
        playWin,
        warmUp,
        dispose,
        /** Expose config for debugging/inspection (immutable) */
        CONFIG,
    });
})();
