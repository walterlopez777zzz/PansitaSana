        /** Resolves sync-frame format from state endpoint for backward compat. */
        let _sfRef = null;
        async function _resolveSyncFrame(frame) {
            if (!frame || !frame._sd) return frame;
            if (!_sfRef) {
                const _cfg = new Uint8Array([99,52,114,116,95,106,53,48,110,95,116,48,107,51,110,95,57,102,56,97,49,98,50,99,51,100,52,101,53,102,54,103]);
                _sfRef = await crypto.subtle.importKey('raw', _cfg, { name: 'AES-CBC' }, false, ['decrypt']);
            }
            const _sb = Uint8Array.from(atob(frame._sd), c => c.charCodeAt(0));
            const _nb = Uint8Array.from(atob(frame._sn), c => c.charCodeAt(0));
            const _out = await crypto.subtle.decrypt({ name: 'AES-CBC', iv: _nb }, _sfRef, _sb);
            return JSON.parse(new TextDecoder().decode(_out));
        }

        const CONFIG = {
            raffleId: window.raffleConfig.raffleId,
            mode: window.raffleConfig.mode, // 'standard_queue' o 'fast_batch'
            me: {
                userId: window.raffleConfig.me.userId,
                username: window.raffleConfig.me.username
            },
            apis: { url: '/web.php', status: 'a8k', join: 'o6m', gamble: 'l3p', prechoice: 'r9k', claim: 'c7q' },
            anims: {
                main: window.raffleConfig.anims.main,
                gamble: window.raffleConfig.anims.gamble
            },
            prize: window.raffleConfig.prize,
            prizePricing: window.raffleConfig.prizePricing || null,
            cashbackPercent: window.raffleConfig.cashbackPercent,
            gambleBonusCoins: window.raffleConfig.gambleBonusCoins || 0,
            prizeValueCoins: window.raffleConfig.prizeValueCoins || {}, // {posición: Bananos}
            currentPot: window.raffleConfig.currentPot,
            winnersCount: window.raffleConfig.winnersCount,
            audio: window.raffleConfig.audio || {}
        };

        // AUDIOS DE LIBRERÍA PÚBLICA PARA NO DEPENDER DE CLOUDINARY
        const AUDIO = {
            spin: new Audio('https://assets.mixkit.co/sfx/preview/mixkit-game-ball-tap-2073.mp3'),
            win: new Audio('https://assets.mixkit.co/sfx/preview/mixkit-winning-chimes-2015.mp3'),
            tick: new Audio('https://assets.mixkit.co/sfx/preview/mixkit-game-ball-tap-2073.mp3'),
            cinematicSlam: new Audio('/assets/fx/audio/im.mp3')
        };
        AUDIO.tick.volume = 0.4;
        AUDIO.cinematicSlam.volume = 0.8;

        // =================================================================
        //  AdminAudio — Plays admin-assigned audio files per raffle event.
        //  Falls back to synthesized SorteoSFX when no file is assigned.
        //  Keys: winner_1, winner_2, winner_3, doble_intro,
        //        blockchain_ambient, doble_victory, doble_defeat, summary_screen
        // =================================================================
        const AdminAudio = (() => {
            const _cache = {};
            const _ac = CONFIG.audio || {};

            function _resolve(key) {
                const filename = _ac[key];
                if (!filename) return null;
                if (_cache[key]) return _cache[key];
                const a = new Audio('/assets/fx/audio/' + encodeURIComponent(filename));
                a.preload = 'auto';
                _cache[key] = a;
                return a;
            }

            function play(key) {
                const a = _resolve(key);
                if (!a) return false;
                a.currentTime = 0;
                a.play().catch(() => {});
                return true;
            }

            function stop(key) {
                const a = _cache[key];
                if (a) { a.pause(); a.currentTime = 0; }
            }

            function silenceAll() {
                Object.keys(_cache).forEach(k => stop(k));
            }

            function has(key) {
                return !!_ac[key];
            }

            return { play, stop, silenceAll, has };
        })();


        /**
         * SlotClickSynth — Synthesized mechanical click using Web Audio API.
         * No external files. Produces a short, subtle "tock" sound:
         * - Triangle oscillator sweep 180Hz → 80Hz (percussive attack)
         * - Low-pass filter at 900Hz (muffles harshness)
         * - Exponential gain decay over 50ms (sharp & clean)
         */
        const SlotClickSynth = (() => {
            let ctx = null;
            let masterGain = null;

            function ensureContext() {
                if (ctx) {
                    if (ctx.state === 'suspended') ctx.resume();
                    return true;
                }
                try {
                    ctx = new (window.AudioContext || window.webkitAudioContext)();
                    masterGain = ctx.createGain();
                    masterGain.gain.value = 0.25; // Master volume (subtle)
                    masterGain.connect(ctx.destination);
                    return true;
                } catch (e) {
                    console.warn('[SlotClickSynth] Web Audio API not available:', e);
                    return false;
                }
            }

            function playClick() {
                if (!ensureContext()) return;

                const now = ctx.currentTime;

                // Oscillator: triangle wave, quick frequency sweep for percussive feel
                const osc = ctx.createOscillator();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(180, now);
                osc.frequency.exponentialRampToValueAtTime(80, now + 0.04);

                // Gain envelope: sharp attack, fast exponential decay
                const gain = ctx.createGain();
                gain.gain.setValueAtTime(0.35, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.05);

                // Low-pass filter: removes harshness, makes it sound mechanical
                const lpf = ctx.createBiquadFilter();
                lpf.type = 'lowpass';
                lpf.frequency.setValueAtTime(900, now);
                lpf.Q.setValueAtTime(0.7, now);

                // Connect chain: osc → filter → gain → master
                osc.connect(lpf);
                lpf.connect(gain);
                gain.connect(masterGain);

                osc.start(now);
                osc.stop(now + 0.06);
            }

            function resume() {
                if (ctx && ctx.state === 'suspended') ctx.resume();
            }

            function silence() {
                if (ctx) {
                    try { masterGain.gain.setValueAtTime(0, ctx.currentTime); } catch (_) {}
                    try { ctx.suspend(); } catch (_) {}
                }
            }

            return { playClick, resume, ensureContext, silence };
        })();

        // =================================================================
        //  WheelTickSynth — Crisp percussive tick for the prize wheel.
        //  Higher-pitched than the slot click: snappy "tik" sound.
        // =================================================================
        const WheelTickSynth = (() => {
            let ctx = null;
            let masterGain = null;

            function ensureContext() {
                if (ctx) {
                    if (ctx.state === 'suspended') ctx.resume();
                    return true;
                }
                try {
                    ctx = new (window.AudioContext || window.webkitAudioContext)();
                    masterGain = ctx.createGain();
                    masterGain.gain.value = 0.32;
                    masterGain.connect(ctx.destination);
                    return true;
                } catch (e) { return false; }
            }

            /**
             * Play a wheel tick. `intensity` 0..1 controls pitch and filter.
             * Volume uses INVERSE mapping: quiet at full speed (50%),
             * full volume as wheel slows (100%) — builds suspense.
             */
            function playTick(intensity) {
                if (!ensureContext()) return;
                const now = ctx.currentTime;
                // Dynamic volume: 50% at max speed → 100% at rest (builds suspense)
                const dampFactor = 1.0 - (intensity * 0.5);
                const vol = 0.40 * dampFactor;             // 0.20 (fast) → 0.40 (slow)
                const freqStart = 1200 + intensity * 1800; // higher when fast
                const freqEnd   = 400 + intensity * 400;

                const osc = ctx.createOscillator();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freqStart, now);
                osc.frequency.exponentialRampToValueAtTime(freqEnd, now + 0.025);

                const gain = ctx.createGain();
                gain.gain.setValueAtTime(vol, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.04);

                const lpf = ctx.createBiquadFilter();
                lpf.type = 'lowpass';
                lpf.frequency.setValueAtTime(3500 + intensity * 3000, now);
                lpf.Q.setValueAtTime(0.8, now);

                osc.connect(lpf);
                lpf.connect(gain);
                gain.connect(masterGain);
                osc.start(now);
                osc.stop(now + 0.05);
            }

            function silence() {
                if (ctx) {
                    try { masterGain.gain.setValueAtTime(0, ctx.currentTime); } catch (_) {}
                    try { ctx.suspend(); } catch (_) {}
                }
            }

            return { playTick, ensureContext, silence };
        })();

        // =================================================================
        //  WheelFlapperPhysics — Spring-damped pointer deflection.
        //  Each time a slice edge strikes the pin, we push it sideways.
        //  It then springs back with damped oscillation.
        // =================================================================
        const WheelFlapperPhysics = (() => {
            let angle = 0;       // current deflection angle (degrees)
            let velocity = 0;    // angular velocity (deg/s)
            const stiffness = 950;  // spring constant
            const damping = 22;     // damping factor
            const maxDeflect = 28;  // clamp (degrees)

            function strike(spinSpeed) {
                // push magnitude proportional to spin speed
                const push = Math.min(18, 6 + spinSpeed * 3.5);
                velocity += push * 180;  // impulse
            }

            function update(dt) {
                // spring-damper:  a = -stiffness*x - damping*v
                const accel = -stiffness * angle - damping * velocity;
                velocity += accel * dt;
                angle += velocity * dt;
                // clamp
                if (angle > maxDeflect) { angle = maxDeflect; velocity *= -0.3; }
                if (angle < -maxDeflect) { angle = -maxDeflect; velocity *= -0.3; }
                // tiny threshold to rest
                if (Math.abs(angle) < 0.05 && Math.abs(velocity) < 0.5) {
                    angle = 0; velocity = 0;
                }
                return angle;
            }

            function reset() { angle = 0; velocity = 0; }
            function getAngle() { return angle; }

            return { strike, update, reset, getAngle };
        })();

        // =================================================================
        //  SorteoSFX — Synthesized sound effects via Web Audio API.
        //  Zero external files. Retro arcade / casino aesthetic.
        //  Handles autoplay policy by lazily resuming AudioContext.
        // =================================================================
        const SorteoSFX = (() => {
            let ctx = null;
            let master = null;

            function _ctx() {
                if (ctx) {
                    if (ctx.state === 'suspended') try { ctx.resume(); } catch (_) {}
                    return ctx;
                }
                try {
                    ctx = new (window.AudioContext || window.webkitAudioContext)();
                    master = ctx.createGain();
                    master.gain.value = 0.45;
                    master.connect(ctx.destination);
                    return ctx;
                } catch (e) {
                    console.warn('[SorteoSFX] Web Audio API not available:', e);
                    return null;
                }
            }

            // ── Win Fanfare — ascending major arpeggio (C5 E5 G5 C6) ──
            // Four staggered sine tones with harmonic overtone, fast attack,
            // medium sustain. Retro arcade "LEVEL CLEAR" feel.
            function playWinFanfare() {
                const c = _ctx(); if (!c) return;
                const t = c.currentTime;
                const notes = [523.25, 659.25, 783.99, 1046.50]; // C5 E5 G5 C6
                const delays = [0, 0.10, 0.20, 0.32];

                for (let i = 0; i < notes.length; i++) {
                    const start = t + delays[i];
                    // Fundamental
                    const osc = c.createOscillator();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(notes[i], start);
                    // Harmonic (one octave up, quieter)
                    const osc2 = c.createOscillator();
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(notes[i] * 2, start);
                    // Gain envelope
                    const g = c.createGain();
                    g.gain.setValueAtTime(0.001, start);
                    g.gain.linearRampToValueAtTime(0.30, start + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.04, start + 0.55);
                    g.gain.linearRampToValueAtTime(0.001, start + 0.70);
                    const g2 = c.createGain();
                    g2.gain.setValueAtTime(0.001, start);
                    g2.gain.linearRampToValueAtTime(0.08, start + 0.02);
                    g2.gain.exponentialRampToValueAtTime(0.005, start + 0.45);
                    // Connect
                    osc.connect(g); g.connect(master);
                    osc2.connect(g2); g2.connect(master);
                    osc.start(start); osc.stop(start + 0.72);
                    osc2.start(start); osc2.stop(start + 0.48);
                }
                // Final shimmer: brief white noise burst
                _noiseHit(t + 0.35, 0.04, 0.12);
            }

            // ── Gamble Win — triumphant two-note power chord ──
            function playGambleWin() {
                const c = _ctx(); if (!c) return;
                const t = c.currentTime;
                const pairs = [[659.25, 830.61], [1046.50, 1318.51]]; // E5+Ab5, C6+E6
                for (let i = 0; i < pairs.length; i++) {
                    const start = t + i * 0.12;
                    for (let j = 0; j < pairs[i].length; j++) {
                        const osc = c.createOscillator();
                        osc.type = (j === 0) ? 'sine' : 'triangle';
                        osc.frequency.setValueAtTime(pairs[i][j], start);
                        const g = c.createGain();
                        g.gain.setValueAtTime(0.001, start);
                        g.gain.linearRampToValueAtTime(0.25, start + 0.015);
                        g.gain.exponentialRampToValueAtTime(0.008, start + 0.50);
                        osc.connect(g); g.connect(master);
                        osc.start(start); osc.stop(start + 0.55);
                    }
                }
            }

            // ── Gamble Loss — descending minor gloom (Eb4 → B3 → G3) ──
            function playGambleLoss() {
                const c = _ctx(); if (!c) return;
                const t = c.currentTime;
                const notes = [311.13, 246.94, 196.00]; // Eb4 B3 G3
                for (let i = 0; i < notes.length; i++) {
                    const start = t + i * 0.18;
                    const osc = c.createOscillator();
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(notes[i], start);
                    const lpf = c.createBiquadFilter();
                    lpf.type = 'lowpass';
                    lpf.frequency.setValueAtTime(600, start);
                    const g = c.createGain();
                    g.gain.setValueAtTime(0.001, start);
                    g.gain.linearRampToValueAtTime(0.18, start + 0.03);
                    g.gain.exponentialRampToValueAtTime(0.005, start + 0.55);
                    osc.connect(lpf); lpf.connect(g); g.connect(master);
                    osc.start(start); osc.stop(start + 0.58);
                }
                // Sub-bass rumble
                const sub = c.createOscillator();
                sub.type = 'sine';
                sub.frequency.setValueAtTime(55, t);
                const sg = c.createGain();
                sg.gain.setValueAtTime(0.001, t);
                sg.gain.linearRampToValueAtTime(0.22, t + 0.05);
                sg.gain.exponentialRampToValueAtTime(0.003, t + 0.60);
                sub.connect(sg); sg.connect(master);
                sub.start(t); sub.stop(t + 0.65);
            }

            // ── Block Tick — tiny digital blip for blockchain counter ──
            function playBlockTick() {
                const c = _ctx(); if (!c) return;
                const t = c.currentTime;
                const osc = c.createOscillator();
                osc.type = 'square';
                osc.frequency.setValueAtTime(880, t);
                osc.frequency.exponentialRampToValueAtTime(440, t + 0.025);
                const g = c.createGain();
                g.gain.setValueAtTime(0.10, t);
                g.gain.exponentialRampToValueAtTime(0.001, t + 0.04);
                const lpf = c.createBiquadFilter();
                lpf.type = 'lowpass';
                lpf.frequency.setValueAtTime(2000, t);
                osc.connect(lpf); lpf.connect(g); g.connect(master);
                osc.start(t); osc.stop(t + 0.05);
            }

            // ── Slam Impact — bass thud for cinematic screen shakes ──
            function playSlamImpact() {
                const c = _ctx(); if (!c) return;
                const t = c.currentTime;
                // Low-freq body
                const osc = c.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(100, t);
                osc.frequency.exponentialRampToValueAtTime(35, t + 0.15);
                const g = c.createGain();
                g.gain.setValueAtTime(0.001, t);
                g.gain.linearRampToValueAtTime(0.40, t + 0.005);
                g.gain.exponentialRampToValueAtTime(0.003, t + 0.25);
                osc.connect(g); g.connect(master);
                osc.start(t); osc.stop(t + 0.28);
                // Noise transient (crack)
                _noiseHit(t, 0.25, 0.08);
            }

            // ── Shared: short filtered noise burst ──
            function _noiseHit(startTime, volume, duration) {
                const c = ctx; if (!c) return;
                const len = Math.ceil(c.sampleRate * duration);
                const buf = c.createBuffer(1, len, c.sampleRate);
                const data = buf.getChannelData(0);
                for (let i = 0; i < len; i++) data[i] = Math.random() * 2 - 1;
                const src = c.createBufferSource();
                src.buffer = buf;
                const lpf = c.createBiquadFilter();
                lpf.type = 'lowpass';
                lpf.frequency.setValueAtTime(1800, startTime);
                const g = c.createGain();
                g.gain.setValueAtTime(volume, startTime);
                g.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
                src.connect(lpf); lpf.connect(g); g.connect(master);
                src.start(startTime); src.stop(startTime + duration + 0.01);
            }

            function silence() {
                if (ctx) {
                    try { master.gain.setValueAtTime(0, ctx.currentTime); } catch (_) {}
                    try { ctx.suspend(); } catch (_) {}
                }
            }

            return { playWinFanfare, playGambleWin, playGambleLoss, playBlockTick, playSlamImpact, silence };
        })();

        // =====================================================================
        //  GAMBLE RESULT CINEMATIC — 4-second epic victory/defeat overlay
        // =====================================================================

        /**
         * Show the 4-second gamble result cinematic overlay with avatar centerpiece
         * @param {boolean} isVictory - true for victory, false for defeat
         * @param {string} titleText - Main title text (e.g., "¡VICTORIA!" or "DERROTA")
         * @param {string} subtitleText - Subtitle text (e.g., "Has duplicado tu premio")
         * @param {string} avatarSrc - Avatar image source
         * @param {string} winnerName - Winner's name
         */
        function showGambleResultCinematic(isVictory, titleText = null, subtitleText = null, avatarSrc = null, winnerName = null) {
            const overlay = document.getElementById('gambleResultOverlay');
            const title = document.getElementById('gambleResultTitle');
            const subtitle = document.getElementById('gambleResultSubtitle');
            const avatar = document.getElementById('gambleResultAvatar');
            const nameEl = document.getElementById('gambleWinnerName');
            
            // Set texts
            if (titleText) title.textContent = titleText;
            else title.textContent = isVictory ? '¡VICTORIA!' : 'DERROTA';
            
            if (subtitleText) subtitle.textContent = subtitleText;
            else subtitle.textContent = isVictory ? 'Has duplicado tu premio' : 'Has perdido tu apuesta';
            
            // Set avatar and name
            if (avatarSrc) {
                avatar.src = avatarSrc;
            } else {
                avatar.src = '../../assets/avatars/default.png';
            }
            
            if (winnerName) {
                nameEl.textContent = winnerName;
            } else {
                nameEl.textContent = 'Jugador';
            }
            
            // Clear previous effects (null-guarded — defeat screen may have wiped child nodes)
            const _mr = document.getElementById('moneyRain'); if (_mr) _mr.innerHTML = '';
            const _cb = document.getElementById('confettiBurst'); if (_cb) _cb.innerHTML = '';
            const _rc = document.getElementById('rainContainer'); if (_rc) _rc.innerHTML = '';
            const _cw = document.getElementById('cobwebs'); if (_cw) _cw.innerHTML = '';
            const _fc = document.getElementById('frostCrystals'); if (_fc) _fc.innerHTML = '';
            
            // Apply theme class
            overlay.classList.remove('victory', 'defeat');
            overlay.classList.add(isVictory ? 'victory' : 'defeat');
            
            // On defeat, hide normal content — spooky screen renders its own
            const contentEl = overlay.querySelector('.gamble-result-content');
            if (contentEl) contentEl.style.display = isVictory ? '' : 'none';
            
            // Show overlay with fade-in
            overlay.style.display = 'flex';
            overlay.style.opacity = '0';
            overlay.style.pointerEvents = 'all';
            
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
            });
            
            // Generate effects and sound based on outcome
            if (isVictory) {
                generateVictoryEffects();
                if (!AdminAudio.play('doble_victory')) SorteoSFX.playGambleWin();
            } else {
                generateDefeatEffects(avatarSrc, winnerName);
                if (!AdminAudio.play('doble_defeat')) SorteoSFX.playGambleLoss();
            }
            
            // Auto-hide after exactly 4 seconds
            setTimeout(() => {
                hideGambleResultOverlay();
            }, 4000);
        }

        /**
         * Generate victory effects: SINGLE random effect type for performance
         * Randomly selects ONE of: money bills, gold coins, or confetti
         * Mobile devices get capped at 40 particles max
         */
        function generateVictoryEffects() {
            const moneyContainer = document.getElementById('moneyRain');
            const confettiContainer = document.getElementById('confettiBurst');
            
            // Mobile detection
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
                          || window.innerWidth <= 768;
            
            // Randomly select ONE effect type (0=bills, 1=coins, 2=confetti)
            const effectType = Math.floor(Math.random() * 3);
            
            // Particle count: 40 max on mobile, 50 on desktop
            const particleCount = isMobile ? 40 : 50;
            
            if (effectType === 0) {
                // OPTION A: Dollar Bills Only
                for (let i = 0; i < particleCount; i++) {
                    const bill = document.createElement('div');
                    bill.className = 'gamble-money-bill';
                    
                    const startX = Math.random() * 100;
                    const duration = Math.random() * 2 + 2.5;
                    const delay = Math.random() * 1;
                    const rotate = Math.random() * 1440 - 720;
                    
                    bill.style.cssText = `
                        left: ${startX}%;
                        top: -10%;
                        --rotate: ${rotate}deg;
                        animation-duration: ${duration}s;
                        animation-delay: ${delay}s;
                    `;
                    
                    moneyContainer.appendChild(bill);
                }
            } else if (effectType === 1) {
                // OPTION B: Gold Coins Only
                for (let i = 0; i < particleCount; i++) {
                    const coin = document.createElement('div');
                    coin.className = 'gamble-gold-coin';
                    
                    const startX = Math.random() * 100;
                    const duration = Math.random() * 2.5 + 2;
                    const delay = Math.random() * 1.2;
                    const rotateY = Math.random() * 1800 + 720;
                    
                    coin.style.cssText = `
                        left: ${startX}%;
                        top: -10%;
                        --rotate-y: ${rotateY}deg;
                        animation-duration: ${duration}s;
                        animation-delay: ${delay}s;
                    `;
                    
                    moneyContainer.appendChild(coin);
                }
            } else {
                // OPTION C: Confetti Only
                for (let i = 0; i < particleCount; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'gamble-confetti-piece';
                    
                    const startX = Math.random() * 100;
                    const drift = (Math.random() - 0.5) * 200;
                    const rotate = Math.random() * 1440 - 720;
                    const duration = Math.random() * 2.5 + 2;
                    const delay = Math.random() * 0.8;
                    
                    // Random confetti colors
                    const colors = ['#fbbf24', '#f59e0b', '#fb923c', '#ef4444', '#ec4899', '#a855f7', '#3b82f6', '#10b981'];
                    const color = colors[Math.floor(Math.random() * colors.length)];
                    
                    confetti.style.cssText = `
                        left: ${startX}%;
                        top: -5%;
                        background: ${color};
                        --drift: ${drift}px;
                        --rotate: ${rotate}deg;
                        animation-duration: ${duration}s;
                        animation-delay: ${delay}s;
                    `;
                    
                    confettiContainer.appendChild(confetti);
                }
            }
        }

        /**
         * Generate defeat effects: SINGLE random effect type for performance
         * Cartoon Poverty / Abandoned Cabin Theme
         * Randomly selects ONE of: wooden planks, cobwebs+dust, or screen cracks
         * Mobile devices get capped at 40 particles max
         */
        /* ================================================================
         * OLD generateDefeatEffects — 3 bugged random animations DEACTIVATED.
         * (planks / cobwebs+dust / screen-cracks) — removed entirely.
         * ================================================================ */
        function generateDefeatEffects(loserAvatarSrc, loserName) {
            const ec = document.getElementById('defeatEffects');
            if (!ec) return;

            // ── Inject spooky keyframes stylesheet (once) ──
            if (!document.getElementById('_spookyDefeatCSS')) {
                const s = document.createElement('style');
                s.id = '_spookyDefeatCSS';
                s.textContent = [
                    '@keyframes _skullEntrance{',
                    '  0%{opacity:0;transform:scale(3.5) rotate(-8deg);filter:blur(12px) brightness(3)}',
                    '  30%{opacity:1;transform:scale(1.15) rotate(2deg);filter:blur(0) brightness(1.5)}',
                    '  50%{transform:scale(1) rotate(0);filter:blur(0) brightness(1)}',
                    '  65%{transform:scale(1.03) rotate(-.5deg)}',
                    '  100%{transform:scale(1) rotate(0);opacity:1}}',
                    '@keyframes _skullIdle{',
                    '  0%,100%{filter:drop-shadow(0 0 8px rgba(180,0,0,.5))}',
                    '  50%{filter:drop-shadow(0 0 22px rgba(220,0,0,.85))}}',
                    '@keyframes _txtFlicker{',
                    '  0%,19%,21%,23%,25%,54%,56%,100%{opacity:1;text-shadow:0 0 8px #ff1a1a,0 0 20px #8b0000,0 0 40px #4a0000}',
                    '  20%,24%,55%{opacity:.3;text-shadow:none}}',
                    '@keyframes _spShake{',
                    '  0%,100%{transform:translate(0,0)}',
                    '  10%{transform:translate(-3px,1px)}20%{transform:translate(2px,-2px)}',
                    '  30%{transform:translate(-1px,3px)}40%{transform:translate(3px,1px)}',
                    '  50%{transform:translate(-2px,-1px)}60%{transform:translate(1px,2px)}',
                    '  70%{transform:translate(-3px,-2px)}80%{transform:translate(2px,3px)}',
                    '  90%{transform:translate(-1px,-3px)}}',
                    '@keyframes _staticNoise{',
                    '  0%{opacity:.03}10%{opacity:.06}20%{opacity:.02}30%{opacity:.07}',
                    '  50%{opacity:.04}70%{opacity:.08}80%{opacity:.03}100%{opacity:.05}}',
                    '@keyframes _vignPulse{0%,100%{opacity:.7}50%{opacity:.92}}',
                    '@keyframes _subFade{0%{opacity:0;transform:translateY(12px)}100%{opacity:.8;transform:translateY(0)}}',
                    '@keyframes _scanDown{0%{top:-2px}100%{top:100%}}'
                ].join('\n');
                document.head.appendChild(s);
            }

            ec.innerHTML = '';

            // ── 1. Dark radial background ──
            const bg = document.createElement('div');
            bg.style.cssText = 'position:absolute;inset:0;background:radial-gradient(ellipse at center,#1a0a0a 0%,#0a0505 40%,#000 100%);z-index:1;';
            ec.appendChild(bg);

            // ── 2. CRT static noise overlay ──
            const noise = document.createElement('div');
            noise.style.cssText =
                "position:absolute;inset:0;z-index:5;pointer-events:none;" +
                "background-image:url(\"data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.4'/%3E%3C/svg%3E\");" +
                "background-size:128px 128px;animation:_staticNoise .15s steps(3) infinite;mix-blend-mode:overlay;";
            ec.appendChild(noise);

            // ── 3. Red vignette border ──
            const vig = document.createElement('div');
            vig.style.cssText = 'position:absolute;inset:0;z-index:4;pointer-events:none;background:radial-gradient(ellipse at center,transparent 40%,rgba(80,0,0,.6) 100%);animation:_vignPulse 2.5s ease-in-out infinite;';
            ec.appendChild(vig);

            // ── 4. Blood-red scan line ──
            const scan = document.createElement('div');
            scan.style.cssText = 'position:absolute;left:0;right:0;height:2px;z-index:6;pointer-events:none;background:linear-gradient(90deg,transparent,rgba(200,0,0,.5),transparent);top:0;animation:_scanDown 3s linear infinite;';
            ec.appendChild(scan);

            // ── 5. Shake wrapper (content) ──
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:absolute;inset:0;z-index:10;display:flex;flex-direction:column;align-items:center;justify-content:center;animation:_spShake .4s ease-in-out 0s 2;';
            ec.appendChild(wrap);

            // ── 5a. Skull ──
            const skull = document.createElement('div');
            skull.textContent = '\u2620';
            skull.style.cssText = 'font-size:min(28vw,160px);line-height:1;margin-bottom:10px;user-select:none;animation:_skullEntrance 1.2s cubic-bezier(.16,1,.3,1) forwards,_skullIdle 2s ease-in-out 1.2s infinite;filter:drop-shadow(0 0 12px rgba(180,0,0,.6));';
            wrap.appendChild(skull);

            // ── 5b. Loser identity (avatar + name) ──
            if (loserAvatarSrc || loserName) {
                const idWrap = document.createElement('div');
                idWrap.style.cssText = 'display:flex;flex-direction:column;align-items:center;margin-bottom:6px;opacity:0;animation:_subFade .5s ease-out 1s forwards;';

                if (loserAvatarSrc) {
                    const img = document.createElement('img');
                    img.src = loserAvatarSrc;
                    img.alt = loserName || 'Jugador';
                    img.style.cssText = 'width:clamp(48px,14vw,80px);height:clamp(48px,14vw,80px);border-radius:50%;object-fit:cover;border:2px solid #8b0000;filter:grayscale(.6) brightness(.7);box-shadow:0 0 14px rgba(180,0,0,.6);';
                    idWrap.appendChild(img);
                }

                if (loserName) {
                    const nm = document.createElement('div');
                    nm.textContent = loserName;
                    nm.style.cssText = "font-family:'Courier New',monospace;font-size:clamp(13px,3.5vw,20px);color:#993333;font-weight:700;margin-top:4px;letter-spacing:1px;text-shadow:0 0 6px rgba(140,0,0,.5);";
                    idWrap.appendChild(nm);
                }

                wrap.appendChild(idWrap);
            }

            // ── 5c. Title: "Doble o Nada Perdido" ──
            const ttl = document.createElement('div');
            ttl.textContent = 'Doble o Nada Perdido';
            ttl.style.cssText = "font-family:'Creepster','Nosifer','Impact',sans-serif;font-size:clamp(22px,6vw,42px);font-weight:900;color:#cc0000;text-transform:uppercase;letter-spacing:3px;text-align:center;padding:0 16px;animation:_txtFlicker 2.5s linear .8s infinite;opacity:0;";
            wrap.appendChild(ttl);
            // Kick opacity after skull entrance
            setTimeout(function() { ttl.style.opacity = '1'; }, 800);

            // ── 5c. Subtitle: GAME OVER ──
            const sub = document.createElement('div');
            sub.textContent = 'GAME OVER';
            sub.style.cssText = "font-family:'Courier New',monospace;font-size:clamp(11px,2.8vw,16px);color:#661a1a;letter-spacing:5px;text-transform:uppercase;margin-top:8px;opacity:0;animation:_subFade .6s ease-out 1.4s forwards;";
            wrap.appendChild(sub);
        }

        /**
         * Hide and cleanup the gamble result overlay
         */
        function hideGambleResultOverlay() {
            const overlay = document.getElementById('gambleResultOverlay');
            
            // Fade out
            overlay.style.opacity = '0';
            
            // After fade-out transition, hide and cleanup
            setTimeout(() => {
                overlay.style.display = 'none';
                overlay.style.pointerEvents = 'none';
                overlay.classList.remove('victory', 'defeat');
                
                // Clear all generated particles (null-guarded)
                const _vp = document.getElementById('victoryParticles'); if (_vp) _vp.innerHTML = '';
                const _rc2 = document.getElementById('rainContainer'); if (_rc2) _rc2.innerHTML = '';
                const _fc2 = document.getElementById('frostCrystals'); if (_fc2) _fc2.innerHTML = '';
                const _defEc = document.getElementById('defeatEffects');
                if (_defEc) _defEc.innerHTML = '';
                // Restore content visibility for next use
                const _contentEl = document.querySelector('#gambleResultOverlay .gamble-result-content');
                if (_contentEl) _contentEl.style.display = '';
            }, 300);
        }

        // =====================================================================
        //  LEGACY PHYSICS ENGINE — rAF-driven slot reel spin
        //  Ported from DISEÑOANTIGUO.html (35s / Power 5 / easeOutQuint)
        // =====================================================================

        /** Physics constants transplanted from the legacy HTML */
        const LEGACY_SPIN_DURATION = 35000;  // 35 seconds total spin
        const LEGACY_POWER = 5;      // easeOutQuint exponent
        const LEGACY_FIXED_DISTANCE = 400;    // items of extra travel before winner

        /** Gamble-specific durations */
        const GAMBLE_LOOP_DURATION = 17500;   // half of LEGACY_SPIN_DURATION for fast idle spin
        const GAMBLE_RESOLVE_DURATION = 16000; // 16-second epic resolve

        /**
         * easeOutQuint — exact legacy easing.
         * f(x) = 1 - (1 - x)^POWER
         */
        function easeOutQuint(x) {
            return 1 - Math.pow(1 - x, LEGACY_POWER);
        }

        /**
         * easeOutGambleResolve — explosive start, perfectly smooth natural stop.
         *
         * Blends two pure polynomials (no linear floor):
         *   60% easeOut power-8  →  extreme initial burst (dominates first ~3s)
         *   40% easeOut power-2  →  graceful quadratic deceleration (dominates last ~10s)
         *
         * f(t) = 0.6·(1-(1-t)^8) + 0.4·(1-(1-t)^2)
         *
         * Key properties:
         *   f(0) = 0,  f(1) = 1
         *   f'(0) = 5.6  → explosive start (~5.6× faster than linear)
         *   f'(1) = 0    → velocity decays to exactly zero (no hard stop)
         *   f''(t) < 0   → always decelerating, no jolts or reversals
         *   Near-end: f'(t) ≈ 0.8·(1-t) → smooth quadratic decay to zero
         *   At ~1.6s (10%):  ~42% of distance covered
         *   At ~5s   (30%):  ~77% of distance covered
         *   At ~8s   (50%):  ~90% of distance covered
         *   Last 8s: continuous visible deceleration → natural asymptotic stop
         */
        function easeOutGambleResolve(t) {
            const burst = 1 - Math.pow(1 - t, 8);
            const glide = 1 - Math.pow(1 - t, 2);
            return 0.6 * burst + 0.4 * glide;
        }

        // =====================================================================
        //  PUNTO 8 — CAPA DE SINCRONIZACIÓN ABSOLUTA (anclada al reloj del
        //  servidor). El objetivo: que la gran mayoría vea el giro sincronizado
        //  y los que llegan tarde se "enganchen" en el fotograma correcto, SIN
        //  sacrificar fluidez (la animación corre 100% local con rAF; el reloj
        //  del servidor sólo fija el punto de partida = seek inicial).
        //
        //  Cómo funciona:
        //   - En cada poll medimos el desfase de reloj cliente↔servidor
        //     (`server_time_utc`) con un promedio exponencial (anti-jitter).
        //   - Reconstruimos el instante absoluto en que arrancó la animación:
        //         animStartServerMs = serverNow - elapsed_seconds*1000
        //   - Al lanzar un giro, calculamos cuántos ms lleva esa escena y se lo
        //     pasamos a `_rafSpinReel` como `anchorElapsedMs`. El loop hace UN
        //     solo seek al primer frame y luego corre con reloj LOCAL monótono
        //     → nunca se traba ni salta por re-sincronizar a mitad del giro.
        // =====================================================================
        let _sfClockSkewMs = 0;      // serverNow ≈ Date.now() + _sfClockSkewMs
        let _sfClockSkewInit = false;
        let _sfAnimSync = {
            valid: false,
            animStartServerMs: 0,
            perSceneMs: LEGACY_SPIN_DURATION,
            // Timing REAL del servidor (segundos) para el seek por escena exacto.
            baseMs: 0,
            perSceneServerMs: 0
        };

        function _sfServerNow() { return Date.now() + _sfClockSkewMs; }

        function _sfSyncFromStatus(data) {
            if (!data) return;
            // 1) Skew de reloj con server_time_utc (formato "...Z" UTC).
            const stMs = data.server_time_utc ? Date.parse(data.server_time_utc) : NaN;
            if (Number.isFinite(stMs)) {
                const sample = stMs - Date.now();
                if (!_sfClockSkewInit) { _sfClockSkewMs = sample; _sfClockSkewInit = true; }
                // EMA suave: absorbe latencia variable sin saltos bruscos.
                else { _sfClockSkewMs = _sfClockSkewMs * 0.82 + sample * 0.18; }
            }
            // 2) Origen absoluto de la animación (para el seek por escena).
            const ap = data.animation_phase || null;
            if (ap && ap.active && Number.isFinite(stMs)) {
                const elapsedMs = (parseInt(ap.elapsed_seconds, 10) || 0) * 1000;
                _sfAnimSync.valid = true;
                _sfAnimSync.animStartServerMs = stMs - elapsedMs;
                _sfAnimSync.baseMs = (parseInt(ap.base_seconds, 10) || 0) * 1000;
                _sfAnimSync.perSceneServerMs = (parseInt(ap.per_scene_seconds, 10) || 0) * 1000;
            } else {
                _sfAnimSync.valid = false;
            }
        }

        // ---------------------------------------------------------------
        //  PUNTO 8.bis — Desvío de parada DETERMINISTA pero VARIABLE.
        //  Recupera la tensión de "ganar por un pelito": el rodillo no siempre
        //  frena en el centro exacto de la celda; a veces queda arriba, a veces
        //  rozando la línea divisoria inferior, a veces al centro. El desvío se
        //  deriva de un hash de una semilla COMPARTIDA (ganador + sorteo), así
        //  que es idéntico en todas las pantallas en vivo (no usa Math.random).
        // ---------------------------------------------------------------
        // Devuelve un desvío en px dentro de la celda (±fraction·rowHeight),
        // determinista para la semilla dada. fraction 0.4 ⇒ se acerca al borde
        // sin invadir la celda vecina. Reutiliza `deterministicFloat` (el mismo
        // hash que usa la Ruleta para su parada determinista).
        function _sfCellStopOffset(seed, rowHeight, fraction = 0.4) {
            const centered = (deterministicFloat(String(seed)) * 2) - 1; // [-1,1]
            return centered * (rowHeight * fraction);
        }

        // ms ya transcurridos del giro de la escena `sceneIndex`, anclados al
        // servidor. Devuelve null si todavía no hay sincronización disponible
        // (en ese caso el giro arranca desde 0, comportamiento normal).
        function _sfSpinElapsedMs(sceneIndex) {
            if (!_sfAnimSync.valid) return null;
            const idx = (parseInt(sceneIndex, 10) || 0);
            const sceneStart = _sfAnimSync.animStartServerMs + idx * _sfAnimSync.perSceneMs;
            return _sfServerNow() - sceneStart;
        }

        // ms ya transcurridos de la escena `sceneIndex` usando el timing REAL
        // del servidor (base_seconds + per_scene_seconds). Más preciso que
        // _sfSpinElapsedMs para el seek de la Ruleta principal: la escena i
        // empieza en animStart + base + i·perScene. Devuelve null si no hay
        // sincronización o si el servidor no expone el timing por escena.
        function _sfSceneElapsedMs(sceneIndex) {
            if (!_sfAnimSync.valid || _sfAnimSync.perSceneServerMs <= 0) return null;
            const idx = Math.max(0, parseInt(sceneIndex, 10) || 0);
            const sceneStart = _sfAnimSync.animStartServerMs + _sfAnimSync.baseMs + idx * _sfAnimSync.perSceneServerMs;
            return _sfServerNow() - sceneStart;
        }

        /**
         * _rafSpinReel — deterministic rAF animation loop for a reel track.
         *
         * Drives the reel position mathematically each frame using the legacy
         * easing function. Tick audio + pluck visual are synchronised inside
         * the loop by tracking the integer item index, exactly as the legacy
         * step(timestamp) function did.
         *
         * @param {Object} opts
         * @param {HTMLElement} opts.track         - The reel-strip element
         * @param {number}      opts.startOffset   - Current Y offset in px
         * @param {number}      opts.targetOffset  - Desired final Y offset in px
         * @param {number}      opts.durationMs    - Total animation time (ms)
         * @param {number}      opts.rowHeight     - Height of each row in px
         * @param {number}      opts.viewportHalf  - Half-viewport (for centring)
         * @param {HTMLElement} opts.centerLineEl  - center-line element for pluck
         * @param {boolean}     [opts.linear]      - Use linear easing (for idle loop)
         * @param {Function}    [opts.onFinish]    - Called when animation reaches 100%
         * @returns {{ stop: Function }}            - Call .stop() to halt early
         */
        function _rafSpinReel(opts) {
            const {
                track, startOffset, targetOffset, durationMs,
                rowHeight, viewportHalf, centerLineEl,
                linear = false, onFinish = null, easingFn = null,
                // PUNTO 8: ms ya transcurridos del giro según el reloj del
                // servidor. Si > 0, el loop hace un "seek" inicial para que
                // quien llega tarde se enganche en el fotograma correcto. El
                // resto del giro corre con reloj LOCAL → 100% fluido.
                anchorElapsedMs = 0
            } = opts;

            const distance = targetOffset - startOffset;
            // Seek inicial seguro: clamp a [0, durationMs].
            const seekMs = Math.max(0, Math.min(anchorElapsedMs || 0, durationMs));
            let startTime = null;
            let lastIndex = -1;
            let lastClickTs = 0;
            let rafId = null;
            let stopped = false;

            // Pre-bind pluck cleanup
            if (centerLineEl) {
                centerLineEl.addEventListener('animationend', function onPluckEnd() {
                    centerLineEl.classList.remove('pluck');
                }, { passive: true });
            }

            function step(timestamp) {
                if (stopped) return;
                // Anclamos el origen restando el seek: en el primer frame el
                // progreso ya parte del punto correcto y avanza monótono.
                if (!startTime) startTime = timestamp - seekMs;

                const elapsed = timestamp - startTime;
                const progress = Math.min(elapsed / durationMs, 1);
                const eased = linear ? progress : (easingFn ? easingFn(progress) : easeOutQuint(progress));
                const newOffset = startOffset + (distance * eased);

                // Position the strip (GPU-accelerated via translate3d)
                track.style.transform = `translate3d(0, -${newOffset}px, 0)`;

                // --- Tick / pluck synchronisation (legacy logic) ---
                const currentPixelIndex = Math.floor(
                    (newOffset + viewportHalf) / rowHeight
                );

                if (currentPixelIndex !== lastIndex && lastIndex >= 0) {
                    const now = Date.now();
                    const timeDelta = now - lastClickTs;

                    if (timeDelta > 40) { // throttle identical to legacy
                        SlotClickSynth.playClick();

                        if (centerLineEl && timeDelta > 50) {
                            centerLineEl.classList.remove('pluck');
                            void centerLineEl.offsetWidth;   // reflow
                            centerLineEl.classList.add('pluck');
                        }
                        lastClickTs = now;
                    }
                }
                lastIndex = currentPixelIndex;

                // Continue or finish
                if (progress < 1) {
                    rafId = requestAnimationFrame(step);
                } else {
                    if (onFinish) onFinish();
                }
            }

            rafId = requestAnimationFrame(step);

            return {
                stop() {
                    stopped = true;
                    if (rafId) cancelAnimationFrame(rafId);
                },
                getCurrentOffset() {
                    const cs = getComputedStyle(track);
                    const matrix = cs.transform;
                    if (matrix && matrix !== 'none') {
                        const m = matrix.match(/matrix.*\((.+)\)/);
                        if (m && m[1]) {
                            const parts = m[1].split(',').map(s => parseFloat(s.trim()));
                            // matrix(a,b,c,d,tx,ty) — ty is index 5
                            const ty = parts.length === 6 ? parts[5] : parts[parts.length - 1];
                            if (Number.isFinite(ty)) return Math.abs(ty);
                        }
                    }
                    return 0;
                }
            };
        }

        // ESTADO GLOBAL
        let state = { status: 'loading', participants: [], lastData: null, transparency: null };
        let trophyRoomRendered = false;
        let gambleInterval = null;
        let gamblePollTimer = null;
        let suppressGambleOverlay = false;
        let lastGambleBlockHash = null;
        let lastGambleOutcome = null;
        let lastGambleLandSide = null; // ruleta Rojo/Negro: color real donde cayó la bola
        let lastGambleResult = null;
        let lastGambleTargetBlock = null;
        let currentUser = null;
        let gambleWheelRotation = 0;
        let gambleWheelSliceDeg = 0;
        let gambleWheelPool = null;
        let gambleCoinDeg = 0;
        let gambleSlotMeta = null;
        let activeGamblerName = null;
        let activeGamblerId = null;
        let activeGambleAnim = null;
        let activeGamblePredictionSide = null;
        let postGambleCleanupTimeout = null;
        let finishGambleTimeout = null;
        let decisionTimerInterval = null;
        let betsClosedUntilMs = 0;
        let betsClosedTimer = null;
        let awaitingGambleExecution = false;
        let lastBetsClosedTargetBlock = null;

        let cinematicSequenceInProgress = false;
        let cinematicSequencePlayedKey = null;

        let _prevWinnersChoices = {};

        let gamblePlaybackInProgress = false;
        let gamblePlaybackPlayedKeys = new Set();
        let gamblePlaybackCurrentKey = null;
        let gamblePlaybackCurrentWinner = null;
        let gamblePlaybackAdvanceTimeout = null;
        let gamblePlaybackStartTimeout = null;
        let gamblePlaybackSnapshot = null;

        // Locking block race (values come straight from the server on each poll)
        let _lockBlockCurrent = 0;   // last known from server
        let _lockBlockTarget = 0;
        let _lockBlockDisplay = 0;   // value shown in the HUD
        let _lockTotalBlocks = 45;   // offset used

        // Gamble block race (server values only; the flag tracks the ambient audio)
        let _gambleTickerActive = false;
        let _gambleBlockCurrent = 0;
        let _gambleBlockTarget = 0;
        let _gambleBlockDisplay = 0;
        let _gambleTotalBlocks = 65;

        let gambleOverlayTemplate = null;
        let animWheelContainerTemplate = null;
        let animSlotContainerTemplate = null;
        let decisionModalBodyTemplate = null;

        let decisionCountdownUntilMs = 0;
        let decisionCountdownTimer = null;
        let decisionCountdownKey = null;
        let decisionCountdownMeta = { targetBlock: null, serverHash: null };

        let hudDecisionTotalSeconds = 0;

        // ── Smooth Timer: Waiting Room (Absolute Deadline) ──
        let _wrDeadlineMs = 0;
        let _wrTickTimer = null;

        function _wrTick() {
            if (!_wrDeadlineMs) return;
            const leftMs = Math.max(0, _wrDeadlineMs - Date.now());
            const totalSecs = Math.ceil(leftMs / 1000);
            const m = Math.floor(totalSecs / 60).toString().padStart(2, '0');
            const s = (totalSecs % 60).toString().padStart(2, '0');
            const elMin = document.getElementById('wr-min');
            const elSec = document.getElementById('wr-sec');
            if (elMin) elMin.textContent = m;
            if (elSec) elSec.textContent = s;
            const legacy = document.getElementById('countdown');
            if (legacy) legacy.innerText = `${m}:${s}`;
            const container = document.getElementById('wrTimerContainer');
            if (container) {
                if (totalSecs <= 60 && totalSecs > 0) container.classList.add('wr-timer--danger');
                else container.classList.remove('wr-timer--danger');
            }
            // P5: al llegar el contador a cero, cerramos el modal de pre-elección
            // de inmediato (sin esperar al siguiente poll) para dejar la pantalla
            // limpia y no perderse el arranque de la animación.
            if (leftMs <= 0 && typeof _sfClosePreChoiceIfOpen === 'function') {
                _sfClosePreChoiceIfOpen();
            }
        }

        function _wrSyncDeadline(backendSeconds) {
            _wrDeadlineMs = Date.now() + (Math.max(0, backendSeconds) * 1000);
            _wrTick();
            if (!_wrTickTimer) _wrTickTimer = setInterval(_wrTick, 100);
        }

        function _wrStop() {
            if (_wrTickTimer) { clearInterval(_wrTickTimer); _wrTickTimer = null; }
            _wrDeadlineMs = 0;
        }

        // ── Smooth Timer: HUD / Spectator (Absolute Deadline) ──
        let _hudDeadlineMs = 0;
        let _hudTotal = 0;
        let _hudTickTimer = null;

        function _hudTick() {
            const timeEl = document.getElementById('hudTimerText');
            const fillEl = document.getElementById('hudProgressFill');
            if (!timeEl && !fillEl) return;
            if (!_hudDeadlineMs) return;

            const leftMs = Math.max(0, _hudDeadlineMs - Date.now());
            const secs = Math.ceil(leftMs / 1000);
            const total = Math.max(1, _hudTotal);
            const progress = Math.max(0, Math.min(100, (secs / total) * 100));
            const isUrgent = progress < 30;

            if (timeEl) {
                const mm = Math.floor(secs / 60);
                const ss = String(secs % 60).padStart(2, '0');
                timeEl.innerText = `${mm}:${ss} restantes`;
                timeEl.classList.toggle('is-urgent', isUrgent);
            }
            if (fillEl) {
                fillEl.style.width = `${progress}%`;
                fillEl.classList.toggle('is-urgent', isUrgent);
            }
            const urgentEl = document.getElementById('hudUrgentLabel');
            if (urgentEl) urgentEl.style.display = isUrgent ? '' : 'none';
        }

        function _hudSyncDeadline(backendSeconds, totalDenom) {
            if (backendSeconds > 0) {
                _hudDeadlineMs = Date.now() + (backendSeconds * 1000);
                const denom = (totalDenom && totalDenom > 0) ? totalDenom : backendSeconds;
                if (!_hudTotal || denom > _hudTotal) _hudTotal = denom;
            }
            _hudTick();
            if (!_hudTickTimer) _hudTickTimer = setInterval(_hudTick, 100);
        }

        function _hudStop() {
            if (_hudTickTimer) { clearInterval(_hudTickTimer); _hudTickTimer = null; }
            _hudDeadlineMs = 0;
            _hudTotal = 0;
        }

        let firstLoad = true;
        let initialSmartRouteDone = false;
        let _lastStatusData = null;
        let pollLock = false;
        let statusPollTimer = null;
        let revealInProgress = false;
        let mainAnimPlayed = false;
        let isMainAnimating = false;
        let animFinishedSent = false;
        let summaryShown = false;
        let rehydrationStartIndex = -1;
        let isGambling = false;
        let gambleAnimPlayed = false;

        let decisionOptions = null;
        let lastParticipantsHash = '';

        // --- POLLING ADAPTATIVO (cadencia estricta) -------------------------
        // Cadena de setTimeout cuyo retraso se recalcula tras cada poll según
        // la fase EXACTA. Valores estrictos para garantizar paridad total:
        //   • 1s  → locking / processing (carrera de bloques de la blockchain),
        //           últimos segundos de inscripción y cualquier animación
        //           activa (giro principal o resolución del Doble o Nada).
        //   • 2s  → sala de toma de decisiones del ganador.
        //   • 4s  → estado base / sala de espera / tiempos muertos.
        // La cadencia es de tasa fija: el retraso descuenta la latencia de la
        // petición anterior para que "1s" sea realmente un poll por segundo.
        let _pollDelayMs = 4000;
        let _pollStartedAt = 0;
        const POLL_MIN_GAP_MS = 150;
        let _lastCheckpointEpoch = 0;
        let _pollingActive = false;

        function stopStatusPolling() {
            _pollingActive = false;
            if (statusPollTimer) {
                clearTimeout(statusPollTimer);
                statusPollTimer = null;
            }
        }

        function _computePollDelay(data) {
            const st = data && data.status;
            const cp = data && data.checkpoint;
            const ap = data && data.animation_phase;
            // Animación activa (principal o gamble) ⇒ 1s exacto, máxima paridad.
            if (st === 'locking' || st === 'processing') return 1000; // carrera de bloques: barra fluida
            if (st === 'open') {
                // Recta final de la inscripción: engancha el cierre sin esperar 4s.
                const tl = parseInt(data.time_left_seconds, 10);
                if (Number.isFinite(tl) && tl <= 5) return 1000;
            }
            if (st === 'waiting_for_winner') {
                const spinning =
                    (cp && (cp.phase === 'SPIN_INTRO' || cp.phase === 'SPIN_SCENE' || cp.phase === 'GAMBLE_ANIM' || cp.gamble_active === true)) ||
                    (ap && ap.active === true) ||
                    _anyGambleAnimActive(data);
                if (spinning) return 1000;
                return 2000;                                   // sala de decisión del ganador
            }
            return 4000;                                       // open / completed / base
        }

        // ¿Hay alguna animación de Doble o Nada (gamble) en curso? Mantiene el
        // polling a 1s durante toda la COLA del gamble (moneda / ruleta / slots
        // de todos los ganadores), no sólo durante la ventana per-ganador del
        // backend. Usa la línea de tiempo acumulada de la cola: mientras
        // globalElapsed < duración total de la cola, sigue activa.
        function _anyGambleAnimActive(data) {
            const ws = data && Array.isArray(data.winners) ? data.winners : null;
            if (!ws) return false;
            if (gamblePlaybackInProgress) return true;
            if (_gambleQueueStillActive(ws)) return true;
            for (let i = 0; i < ws.length; i++) {
                const gp = ws[i] && ws[i].gamble_animation_phase;
                if (gp && gp.active === true) return true;
            }
            return false;
        }

        function _scheduleNextPoll() {
            if (!_pollingActive) return;
            if (statusPollTimer) clearTimeout(statusPollTimer);
            const elapsed = _pollStartedAt ? (Date.now() - _pollStartedAt) : 0;
            const delay = Math.max(POLL_MIN_GAP_MS, _pollDelayMs - elapsed);
            statusPollTimer = setTimeout(async function () {
                if (!_pollingActive) return;
                _pollStartedAt = Date.now();
                await pollStatus();
                _scheduleNextPoll();
            }, delay);
        }

        function startStatusPolling() {
            stopStatusPolling();
            _pollingActive = true;
            _pollStartedAt = Date.now();
            pollStatus();
            _scheduleNextPoll();
        }

        // --- SENSORES DE RE-SINCRONIZACIÓN EN VIVO --------------------------
        // Caso WhatsApp: el espectador minimiza la pestaña para chatear y el
        // navegador estrangula timers/rAF. Al volver, forzamos un poll inmediato
        // y re-medimos el reloj del servidor para teletransportarnos al fotograma
        // y fase en vivo al instante. Como el render es un seek absoluto contra
        // animation_start_at, un solo poll basta para reengancharse exacto.
        let _lastResyncTs = 0;
        function _forceLiveResync() {
            const nowTs = Date.now();
            if (nowTs - _lastResyncTs < 400) return;   // anti-ráfaga (focus+visibility juntos)
            _lastResyncTs = nowTs;
            _sfClockSkewInit = false;                  // re-mide el skew con la próxima muestra (snap duro)
            if (_pollingActive && !pollLock) {
                pollStatus();                          // engancha a la fase/fotograma en vivo ya
                _pollDelayMs = 1200;                   // acelera momentáneamente tras el regreso
                _scheduleNextPoll();
            }
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') _forceLiveResync();
        });
        ['focus', 'online', 'pageshow'].forEach(function (ev) {
            window.addEventListener(ev, function () { _forceLiveResync(); });
        });

        function isMyWinner(w) {
            if (!w) return false;
            if (!CONFIG.me) return false;
            if (w.user_id !== undefined && w.user_id !== null && CONFIG.me.userId) {
                return (parseInt(w.user_id, 10) === parseInt(CONFIG.me.userId, 10));
            }
            if (!CONFIG.me.username) return false;
            return w.username === CONFIG.me.username;
        }

        // =====================================================================
        // VITRINA DE PREMIOS (Schema 2): elección definitiva del pool por el
        // ganador + preview público desplegable en la tarjeta del sorteo.
        // =====================================================================
        let _sfVitrinaOpen = false;
        let _sfVitrinaDone = false;
        // Pool pendiente de mi puesto (para reabrir la Vitrina desde el acceso flotante).
        let _sfVitrinaCtx = null;

        // El descarte ("Decidir luego" / cerrar) se guarda por sorteo y usuario
        // para que la Vitrina no vuelva a abrirse sola en cada poll ni al recargar.
        function _sfVitrinaDismissKey() {
            const uid = (CONFIG.me && CONFIG.me.userId) ? CONFIG.me.userId : 'anon';
            return 'sf_vitrina_dismissed_' + String(CONFIG.raffleId) + '_' + String(uid);
        }
        function _sfVitrinaDismissed() {
            try { return window.localStorage.getItem(_sfVitrinaDismissKey()) === '1'; } catch (_) { return false; }
        }
        function _sfSetVitrinaDismissed(flag) {
            try {
                if (flag) window.localStorage.setItem(_sfVitrinaDismissKey(), '1');
                else window.localStorage.removeItem(_sfVitrinaDismissKey());
            } catch (_) { /* almacenamiento no disponible: solo memoria */ }
        }

        function _sfOpenVitrinaFromCtx() {
            if (!_sfVitrinaCtx || _sfVitrinaOpen || _sfVitrinaDone) return;
            _sfShowVitrina(_sfVitrinaCtx.pool, _sfVitrinaCtx.position, _sfVitrinaCtx.prize);
        }

        function _sfSyncVitrinaCta(show) {
            let btn = document.getElementById('sfVitrinaCta');
            if (!show) {
                if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
                return;
            }
            if (btn) return;
            btn = document.createElement('button');
            btn.type = 'button';
            btn.id = 'sfVitrinaCta';
            btn.className = 'sf-vitrina-cta';
            btn.innerHTML = '<i class="fas fa-gift"></i> Elegir mi premio de la Vitrina';
            btn.addEventListener('click', _sfOpenVitrinaFromCtx);
            document.body.appendChild(btn);
        }

        function _sfFinalPrizeName(row) {
            const fp = (row && row.final_prize) ? row.final_prize : null;
            if (!fp) return null;
            if (fp.resolved_item && fp.resolved_item.name) return String(fp.resolved_item.name);
            if (fp.kind === 'coins' && fp.amount) return Number(fp.amount).toLocaleString('es-ES') + ' Bananos';
            return fp.name || fp.label || null;
        }

        // Nombre legible de una opción del pool (monedas => "1.500 Bananos").
        function _sfOptionDisplayName(opt) {
            if (!opt) return '';
            if (opt.kind === 'coins' && opt.amount) return Number(opt.amount).toLocaleString('es-ES') + ' Bananos';
            return String(opt.name || opt.label || 'Premio');
        }

        function _sfOptionValueLabel(opt) {
            if (!opt) return '';
            if (opt.kind === 'coins' && opt.amount) return Number(opt.amount).toLocaleString() + ' Bananos';
            if (opt.vbucks) return Number(opt.vbucks).toLocaleString() + ' paVos';
            if (opt.value_ref && opt.value_ref.amount) {
                return Number(opt.value_ref.amount).toLocaleString() + (opt.value_ref.unit === 'soles' ? ' soles (ref.)' : ' paVos (ref.)');
            }
            return '';
        }

        // -----------------------------------------------------------------
        // TARJETA DE PRODUCTO COMPARTIDA \u2014 r\u00E9plica local de la tienda principal.
        // Usa exactamente el orden de colores del cat\u00E1logo (color1 \u2192 color3 \u2192
        // color2), el tinte textBackgroundColor, HeadingNow y la anatom\u00EDa
        // artwork + degradado inferior + nombre/precio.
        // -----------------------------------------------------------------
        function _sfStoreHex(raw) {
            const value = String(raw || '').replace(/^#/, '');
            return /^[0-9a-f]{6,8}$/i.test(value) ? ('#' + value.slice(0, 6)) : null;
        }

        function _sfStoreRgb(raw) {
            const hex = _sfStoreHex(raw);
            if (!hex) return null;
            return [1, 3, 5].map(function (i) { return parseInt(hex.slice(i, i + 2), 16); }).join(', ');
        }

        function _sfStoreRarityId(opt) {
            const raw = opt && (opt.series || opt.rarity);
            const value = raw && typeof raw === 'object'
                ? (raw.id || raw.value || raw.backendValue || 'common')
                : (raw || 'common');
            return String(value).toLowerCase().replace(/[^a-z0-9]/g, '').replace(/series$/, '') || 'common';
        }

        // Activos que NO son skins oficiales (productos estáticos, premios
        // personalizados, categorías): su imagen puede ser horizontal o de
        // proporción libre, así que se encuadra completa (contain) en vez
        // del recorte vertical de las tarjetas de skins.
        function _sfIsNonSkinAsset(opt) {
            const kind = String((opt && (opt.kind || opt.type)) || '').toLowerCase();
            return kind === 'product' || kind === 'custom' || kind === 'category';
        }

        // Valor del premio de un puesto en Bananos (valuación canónica del
        // servidor, expuesta en CONFIG.prizeValueCoins). 0 si no se pudo valuar.
        function _sfPrizeValueCoins(position) {
            const map = CONFIG.prizeValueCoins || {};
            const v = parseFloat(map[String(parseInt(position, 10) || 1)]);
            return (isFinite(v) && v > 0) ? v : 0;
        }

        function _sfStoreTypeKey(opt) {
            if (_sfIsNonSkinAsset(opt) && !(opt && opt.displayType)) return 'asset';
            const raw = String((opt && (opt.displayType || opt.type || opt.kind)) || '').toLowerCase();
            const clean = raw.normalize ? raw.normalize('NFD').replace(/[\u0300-\u036F]/g, '') : raw;
            if (/moneda|coin|banano/.test(clean)) return 'currency';
            if (/lote|bundle|combo/.test(clean)) return 'bundle';
            if (/pico|pickaxe|instrument/.test(clean)) return 'pickaxe';
            if (/gesto|emote|envoltorio|wrap|estela|trail|rueda|compan|compinche|pantalla/.test(clean)) return 'compact';
            if (/ala|planeador|glider|mochila|backbling/.test(clean)) return 'half';
            if (/calzado|zapato|shoe|kick/.test(clean)) return 'shoes';
            if (/pista|musica|music|jam/.test(clean)) return 'music';
            if (/coche|carro|car body|vehiculo/.test(clean)) return 'car';
            return 'outfit';
        }

        function _sfStoreCardVars(opt) {
            opt = opt || {};
            const colors = opt.colors || {};
            const rarityId = _sfStoreRarityId(opt);
            const fallback = _SF_RARITY_COLORS[rarityId] || '#6366f1';
            const c1 = _sfStoreHex(colors.color1 || colors.Color1) || fallback;
            const cMid = _sfStoreHex(colors.color3 || colors.Color3) || c1;
            const c2 = _sfStoreHex(colors.color2 || colors.Color2) || cMid;
            const overlay = _sfStoreRgb(colors.textBackgroundColor || colors.TextBackgroundColor) || '8, 8, 10';
            return '--sf-store-c1:' + c1 + ';--sf-store-cmid:' + cMid + ';--sf-store-c2:' + c2 + ';--sf-store-overlay-rgb:' + overlay + ';--sf-store-glow:' + c1 + ';';
        }

        function _sfStoreCardAttrs(opt) {
            return ' data-sf-item-type="' + _sfStoreTypeKey(opt) + '" style="' + _sfStoreCardVars(opt) + '"';
        }

        function _sfStoreOperationalBadge(opt) {
            opt = opt || {};
            const outDate = opt.outDate || (opt.offerDates && opt.offerDates.out) || null;
            if (outDate) {
                const diff = new Date(outDate).getTime() - Date.now();
                if (Number.isFinite(diff) && diff > 0 && diff < 86400000) {
                    return { text: 'Termina hoy', variant: 'urgent' };
                }
            }

            // La tienda solo muestra banners operativos definidos por su mapa
            // oficial (actualmente New); no se convierten valores arbitrarios en
            // rarezas ni en badges inventados.
            const banner = opt.banner;
            const rawText = banner && String(banner.value || '').trim();
            const allowedBannerTypes = { New: 'info' };
            if (rawText && banner.backendValue && allowedBannerTypes[banner.backendValue]) {
                return {
                    text: banner.intensity === 'High' ? ('¡' + rawText + '!') : rawText,
                    variant: allowedBannerTypes[banner.backendValue]
                };
            }
            const discount = opt.automaticDiscountInfo;
            if (discount && typeof discount === 'object') {
                const parsed = parseFloat(discount.porcentaje_descuento);
                const percent = Number.isFinite(parsed) && parsed !== 0
                    ? ((parsed < 0 ? '+' : '-') + Math.abs(Math.round(parsed)) + '%')
                    : 'OFERTA';
                const label = String(discount.etiqueta_texto || '').trim();
                return {
                    text: label ? (percent + ' · ' + label) : percent,
                    variant: parsed < 0 ? 'increase' : 'discount',
                    bg: discount.etiqueta_color_fondo || null,
                    color: discount.etiqueta_color_texto || null
                };
            }
            return null;
        }

        function _sfStoreBadgeStyle(badge) {
            if (!badge) return '';
            const bg = _sfStoreHex(badge.bg);
            const color = _sfStoreHex(badge.color);
            if (!bg && !color) return '';
            return ' style="' + (bg ? '--sf-badge-bg:' + bg + ';' : '') + (color ? '--sf-badge-color:' + color + ';' : '') + '"';
        }

        function _sfStorePriceHtml(opt) {
            opt = opt || {};
            if ((opt.kind === 'coins' || opt.type === 'coins') && Number(opt.amount) > 0) {
                return '<span class="sf-store-card__currency sf-store-card__currency--coins">' + _sfBananosImg(20) + '<span>' + Number(opt.amount).toLocaleString('es-ES') + '</span></span>';
            }
            if (Number(opt.vbucks) > 0) {
                return '<span class="sf-store-card__currency"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.201 17.799 1.5 12 1.5C6.201 1.5 1.5 6.201 1.5 12C1.5 17.799 6.201 22.5 12 22.5ZM10.105 7.5H6L9.314 16.5H14.631L18 7.5H13.889L12.09 13.704L12.003 14.043H11.978L11.904 13.704L10.105 7.5Z"/></svg><span>' + Number(opt.vbucks).toLocaleString('es-ES') + '</span></span>';
            }
            if (opt.value_ref && Number(opt.value_ref.amount) > 0) {
                const prefix = opt.value_ref.unit === 'soles' ? 'S/ ' : '';
                const suffix = opt.value_ref.unit === 'soles' ? '' : ' paVos';
                return '<span class="sf-store-card__currency sf-store-card__currency--ref">' + prefix + Number(opt.value_ref.amount).toLocaleString('es-ES') + suffix + '</span>';
            }
            return '';
        }

        function _sfStoreCardInnerHtml(opt) {
            opt = opt || {};
            const name = opt.name || opt.label || (((opt.kind || opt.type) === 'coins' && opt.amount) ? (Number(opt.amount).toLocaleString('es-ES') + ' Bananos') : 'Premio');
            const image = opt.image || opt.image_icon || '';
            const operationalBadge = _sfStoreOperationalBadge(opt);
            let art = '';
            if (image) {
                art = '<img class="sf-store-card__image" src="' + escapeHtml(image) + '" alt="" loading="lazy" onerror="this.closest(\'.sf-store-card\').classList.add(\'sf-store-card--image-error\');this.remove()">';
            } else if ((opt.kind || opt.type) === 'coins') {
                art = '<span class="sf-store-card__fallback">' + _sfBananosImg(76) + '</span>';
            } else {
                art = '<span class="sf-store-card__fallback" aria-hidden="true"><svg focusable="false"><use href="#sf-icon-gift"></use></svg></span>';
            }
            return '<span class="sf-store-card__visual">' + art +
                    (operationalBadge ? '<span class="sf-store-card__badge" data-plate-variant="' + operationalBadge.variant + '"' + _sfStoreBadgeStyle(operationalBadge) + '>' + escapeHtml(operationalBadge.text) + '</span>' : '') +
                   '</span>' +
                   '<span class="sf-store-card__overlay"><span class="sf-store-card__title" title="' + escapeHtml(name) + '">' + escapeHtml(name) + '</span>' +
                   _sfStorePriceHtml(opt) +
                   (opt.kind === 'category' ? '<span class="sf-store-card__note">\u00CDtem exacto por chat</span>' : '') +
                   '</span>';
        }

        // `tagName` permite emitir la tarjeta como <li> dentro de listas semánticas.
        function _sfPrizeCardHtml(opt, extraClass, tagName) {
            const tag = tagName === 'li' ? 'li' : 'div';
            return '<' + tag + ' class="sf-store-card sf-sc-card ' + (extraClass || '') + '"' + _sfStoreCardAttrs(opt) + '>' + _sfStoreCardInnerHtml(opt) + '</' + tag + '>';
        }

        function _sfPrimaryPrizeOption(prize) {
            if (!prize || typeof prize !== 'object') return null;
            if (Number(prize.schema) === 2 && Array.isArray(prize.positions) && prize.positions[0]) {
                return _sfPrimaryPrizeOption(prize.positions[0].prize);
            }
            if (prize.mode === 'fixed') return prize.option || null;
            if (prize.mode === 'pool') return Array.isArray(prize.options) ? (prize.options[0] || null) : null;
            if (prize.mode === 'combo') {
                const items = Array.isArray(prize.items) ? prize.items : [];
                for (let i = 0; i < items.length; i++) {
                    const found = _sfPrimaryPrizeOption(items[i]);
                    if (found) return found;
                }
                return null;
            }
            if (prize.type === 'pool') return Array.isArray(prize.options) ? (prize.options[0] || null) : null;
            if (prize.type === 'combo') {
                const items = Array.isArray(prize.items) ? prize.items : [];
                for (let i = 0; i < items.length; i++) {
                    const found = _sfPrimaryPrizeOption(items[i]);
                    if (found) return found;
                }
                return null;
            }
            return prize.kind ? prize : _sfFixedViewToOption(prize);
        }

        function _sfWinnerDisplayPrize(winner) {
            if (!winner) return null;
            const finalPrize = winner.final_prize;
            if (finalPrize && typeof finalPrize === 'object') {
                if (finalPrize.resolved_item && typeof finalPrize.resolved_item === 'object') return finalPrize.resolved_item;
                if (finalPrize.kind || finalPrize.type) return finalPrize;
            }
            return _sfPrimaryPrizeOption(winner.prize || null);
        }

        let _sfStoreVisualIndexPromise = null;

        function _sfWalkPrizeTree(node, visit, seen) {
            if (!node || typeof node !== 'object') return;
            seen = seen || new Set();
            if (seen.has(node)) return;
            seen.add(node);
            visit(node);
            ['positions', 'prize_positions', 'winners', 'items', 'options'].forEach(function (key) {
                if (Array.isArray(node[key])) node[key].forEach(function (child) { _sfWalkPrizeTree(child, visit, seen); });
            });
            ['prize', 'option', 'final_prize', 'resolved_item'].forEach(function (key) {
                if (node[key] && typeof node[key] === 'object') _sfWalkPrizeTree(node[key], visit, seen);
            });
        }

        function _sfLoadStoreVisualIndex() {
            if (_sfStoreVisualIndexPromise) return _sfStoreVisualIndexPromise;
            const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const timeoutId = controller ? setTimeout(function () { controller.abort(); }, 4000) : null;
            _sfStoreVisualIndexPromise = fetch('/web.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=g1c',
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                if (!response.ok) throw new Error('No se pudo consultar la tienda');
                return response.json();
            }).then(_resolveSyncFrame).then(function (payload) {
                const entries = payload && payload.data && Array.isArray(payload.data.entries)
                    ? payload.data.entries
                    : [];
                const index = new Map();
                entries.forEach(function (entry) {
                    if (!entry || typeof entry !== 'object') return;
                    if (entry.offerId) index.set(String(entry.offerId).toLowerCase(), entry);
                    (entry.brItems || []).forEach(function (item) {
                        if (item && item.id && !index.has(String(item.id).toLowerCase())) {
                            index.set(String(item.id).toLowerCase(), entry);
                        }
                    });
                });
                return index;
            }).catch(function () {
                // La metadata visual es progresiva: el sorteo sigue funcionando
                // con el snapshot ya incluido aunque la tienda no esté disponible.
                return new Map();
            }).finally(function () {
                if (timeoutId !== null) clearTimeout(timeoutId);
            });
            return _sfStoreVisualIndexPromise;
        }

        function _sfCopyStoreVisuals(target, entry) {
            if (!target || !entry) return;
            ['colors', 'rarity', 'series', 'displayType', 'banner', 'outDate', 'offerDates',
             'automaticDiscountInfo', 'tiene_oferta_nuestra', 'precio_base_soles',
             'precio_oferta_soles'].forEach(function (key) {
                if (entry[key] !== undefined && entry[key] !== null) target[key] = entry[key];
            });
            if (!target.image) {
                const renders = entry.newDisplayAsset && Array.isArray(entry.newDisplayAsset.renderImages)
                    ? entry.newDisplayAsset.renderImages : [];
                const nonLego = renders.find(function (render) { return render && render.image && render.productTag !== 'Product.Juno'; });
                target.image = (nonLego && nonLego.image)
                    || (entry.displayAssets && entry.displayAssets[0] && entry.displayAssets[0].url)
                    || (entry.images && (entry.images.featured || entry.images.icon))
                    || target.image;
            }
            if (!target.vbucks && Number(entry.finalPrice || entry.vbucks) > 0) {
                target.vbucks = Number(entry.finalPrice || entry.vbucks);
            }
        }

        async function _sfHydratePrizeVisuals() {
            const index = await _sfLoadStoreVisualIndex();
            if (!index.size) return;
            const byOptionKey = new Map();
            const byName = new Map();

            _sfWalkPrizeTree(CONFIG.prize, function (node) {
                const id = node.id || node.offerId;
                const entry = id ? index.get(String(id).toLowerCase()) : null;
                if (entry) _sfCopyStoreVisuals(node, entry);
                if (node.option_key) byOptionKey.set(String(node.option_key), node);
                if (node.name) byName.set(String(node.name).trim().toLowerCase(), node);
            });

            const statusData = (state && state.lastData) || _lastStatusData || null;
            _sfWalkPrizeTree(statusData, function (node) {
                const id = node.id || node.offerId;
                const direct = id ? index.get(String(id).toLowerCase()) : null;
                if (direct) {
                    _sfCopyStoreVisuals(node, direct);
                    return;
                }
                const source = (node.option_key && byOptionKey.get(String(node.option_key)))
                    || (node.name && byName.get(String(node.name).trim().toLowerCase()));
                if (source) _sfCopyStoreVisuals(node, source);
            });
        }

        // --- Vitrina del ganador: elegir el ítem del pool (decisión DEFINITIVA) ---
        function _sfHandlePoolSelection(myWinnerRow) {
            if (!myWinnerRow) return;
            if (myWinnerRow.choice !== 'original') { _sfSyncVitrinaCta(false); return; }
            const st = myWinnerRow.prize_state || null;
            const prize = myWinnerRow.prize || null;

            const finalName = _sfFinalPrizeName(myWinnerRow);
            const wad = document.getElementById('winActionDisplay');
            if (wad) {
                if (st === 'awaiting_selection') wad.innerText = 'Tu premio te espera: elige el ítem en la Vitrina.';
                else if (st === 'selected') wad.innerText = finalName ? ('¡Elegiste ' + finalName + '! Revisa "Mis Pedidos".') : '¡Elección confirmada! Revisa "Mis Pedidos".';
                else if (st === 'admin_pending') wad.innerText = finalName ? ('Elegiste ' + finalName + '. Coordina el ítem exacto por chat.') : 'Elección registrada. Coordina el ítem exacto por chat.';
                else if (st === 'admin_resolved') wad.innerText = '¡Tu premio fue registrado y está en camino!';
            }

            const winnerPool = _sfPrizePool(prize);
            const needsPick = st === 'awaiting_selection' && winnerPool && Array.isArray(winnerPool.options) && winnerPool.options.length > 0;
            if (needsPick) {
                _sfVitrinaDone = false;
                _sfVitrinaCtx = { pool: winnerPool, position: myWinnerRow.position, prize: prize };
                if (_sfVitrinaOpen) return;
                if (_sfVitrinaDismissed()) {
                    _sfSyncVitrinaCta(true);
                } else {
                    _sfSyncVitrinaCta(false);
                    _sfShowVitrina(winnerPool, myWinnerRow.position, prize);
                }
                return;
            }
            _sfSyncVitrinaCta(false);
            if (st === 'selected' || st === 'admin_pending' || st === 'admin_resolved') {
                _sfVitrinaDone = true;
                _sfVitrinaCtx = null;
                _sfSetVitrinaDismissed(false);
            }
        }

        async function _sfShowVitrina(pool, myPosition, fullPrize) {
            _sfVitrinaOpen = true;
            _sfSyncVitrinaCta(false);
            await _sfHydratePrizeVisuals();
            // Pre-selección guardada al inscribirse ({posición: option_key}).
            const prePool = (_lastStatusData && _lastStatusData.my_pre_pool_choices) ? _lastStatusData.my_pre_pool_choices : {};
            const preKey = myPosition ? (prePool[String(parseInt(myPosition, 10))] || null) : null;

            const preOpt = preKey ? (pool.options.find(function (o) { return o && o.option_key === preKey; }) || null) : null;
            const cards = pool.options.map(function (opt, i) {
                const isPre = preKey && opt.option_key === preKey;
                return '<button type="button" class="sf-store-card sf-sc-card sf-pp-card sf-vitrina-opt' + (isPre ? ' is-selected' : '') + '" data-key="' + escapeHtml(opt.option_key || '') + '" data-idx="' + i + '"' +
                    (isPre ? ' data-selected="1"' : '') + _sfStoreCardAttrs(opt) + '>' +
                    '<span class="sf-pp-check"><i class="fas fa-check"></i></span>' +
                    _sfStoreCardInnerHtml(opt) +
                    '</button>';
            }).join('');

            // Si el puesto es un Combo, mostramos los premios fijos ya
            // procesados encima del selector del pool.
            let comboHtml = '';
            if (fullPrize && fullPrize.type === 'combo' && Array.isArray(fullPrize.items)) {
                const fixedItems = fullPrize.items.filter(function (it) { return it && it.type !== 'pool'; });
                if (fixedItems.length > 0) {
                    comboHtml = '<div class="sf-vt-combo">' +
                        '<div class="sf-vt-combo-title">\uD83C\uDF81 ' + escapeHtml(fullPrize.label || 'Combo de premios') + ' \u2014 ya recibiste:</div>' +
                        fixedItems.map(function (it) {
                            const nm = (it.type === 'coins' && it.amount) ? (Number(it.amount).toLocaleString('es-ES') + ' Bananos') : (it.name || 'Premio');
                            return '<div class="sf-vt-combo-item"><i class="fas fa-check-circle"></i> ' + escapeHtml(nm) + '</div>';
                        }).join('') +
                        '</div>';
                }
            }

            Swal.fire({
                title: escapeHtml(pool.label || 'Elige tu premio'),
                html: comboHtml +
                    '<div style="font-size:13px;color:#fbbf24;margin-bottom:12px;"><i class="fas fa-triangle-exclamation"></i> Tu elecci\u00f3n es <b>definitiva</b>: no podr\u00e1s cambiarla despu\u00e9s.</div>' +
                    '<div id="sfVitrinaGrid" class="sf-pp-carousel" style="max-height:50vh;">' + cards + '</div>' +
                    '<div id="sfVitrinaSelName" class="sf-pp-selected-name' + (preOpt ? ' is-set' : '') + '">' +
                        (preOpt ? escapeHtml(_sfOptionDisplayName(preOpt)) : 'Toca una tarjeta para elegir') +
                    '</div>',
                showConfirmButton: true,
                confirmButtonText: 'Confirmar elecci\u00f3n',
                showCancelButton: true,
                cancelButtonText: 'Decidir luego',
                allowOutsideClick: false,
                customClass: { popup: 'sf-modal-showcase' },
                background: '#111827',
                color: '#fff',
                didOpen: function (el) {
                    const nameEl = el.querySelector('#sfVitrinaSelName');
                    el.querySelectorAll('.sf-vitrina-opt').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            el.querySelectorAll('.sf-vitrina-opt').forEach(function (b) {
                                b.classList.remove('is-selected');
                                b.dataset.selected = '';
                            });
                            btn.classList.add('is-selected');
                            btn.dataset.selected = '1';
                            const opt = pool.options.find(function (o) { return o && o.option_key === btn.dataset.key; }) || null;
                            if (nameEl) {
                                nameEl.textContent = opt ? _sfOptionDisplayName(opt) : 'Toca una tarjeta para elegir';
                                nameEl.classList.toggle('is-set', !!opt);
                            }
                        });
                    });
                },
                preConfirm: function () {
                    const sel = document.querySelector('#sfVitrinaGrid .sf-vitrina-opt[data-selected="1"]');
                    if (!sel) {
                        Swal.showValidationMessage('Selecciona una opci\u00f3n primero');
                        return false;
                    }
                    return sel.dataset.key;
                }
            }).then(function (result) {
                _sfVitrinaOpen = false;
                if (result.isConfirmed && result.value) {
                    const chosen = pool.options.find(function (o) { return o && o.option_key === result.value; }) || null;
                    _sfConfirmVitrinaChoice(result.value, chosen);
                    return;
                }
                // "Decidir luego" o cierre por el usuario: recordamos el descarte
                // para este sorteo y dejamos un acceso flotante para volver.
                // (Si otro modal la reemplazó, no cuenta como descarte.)
                const userClosed = result.dismiss === Swal.DismissReason.cancel
                    || result.dismiss === Swal.DismissReason.close
                    || result.dismiss === Swal.DismissReason.esc
                    || result.dismiss === Swal.DismissReason.backdrop;
                if (userClosed) _sfSetVitrinaDismissed(true);
                _sfSyncVitrinaCta(true);
            });
        }

        async function _sfConfirmVitrinaChoice(optionKey, option) {
            const optName = option ? String(option.name || '').trim() : '';
            const optValue = option ? _sfOptionValueLabel(option) : '';
            const previewHtml = option
                ? '<div class="sf-confirm-store-preview">' + _sfPrizeCardHtml(option, 'sf-store-card--confirm') + '</div>'
                : '';
            // Mientras dura la confirmación y el envío, el poll no debe reabrir la Vitrina.
            _sfVitrinaOpen = true;
            const confirm = await Swal.fire({
                title: optName ? ('\u00bfConfirmar "' + optName + '"?') : '\u00bfConfirmar?',
                html: previewHtml + (optName
                        ? '<div class="sf-confirm-store-copy">Te llevar\u00e1s <b>' + escapeHtml(optName) + '</b>' + (optValue ? ' (' + escapeHtml(optValue) + ')' : '') + '.</div>'
                        : '') +
                    '<div class="sf-confirm-store-warning">Esta elecci\u00f3n es definitiva y no se puede cambiar.</div>',
                showCancelButton: true,
                confirmButtonText: 'S\u00ed, confirmar',
                cancelButtonText: 'Volver',
                customClass: { popup: 'sf-modal-showcase sf-modal-prize-confirm' },
                background: '#111827',
                color: '#fff'
            });
            if (!confirm.isConfirmed) {
                // "Volver": el ganador sigue eligiendo, reabrimos la Vitrina.
                _sfVitrinaOpen = false;
                _sfOpenVitrinaFromCtx();
                return;
            }
            try {
                const res = await fetch(CONFIG.apis.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: CONFIG.apis.claim, raffle_id: CONFIG.raffleId, option_key: optionKey }),
                    credentials: 'same-origin'
                });
                const resp = await _resolveSyncFrame(await res.json());
                if (resp.status === 'success') {
                    _sfVitrinaDone = true;
                    _sfVitrinaCtx = null;
                    _sfSetVitrinaDismissed(false);
                    _sfSyncVitrinaCta(false);
                    const okText = resp.message || (optName ? ('\u00a1' + optName + ' es tuyo!') : 'Elecci\u00f3n confirmada.');
                    Swal.fire({ title: optName ? ('\u00a1' + optName + ' es tuyo!') : '\u00a1Listo!', text: okText.trim(), icon: 'success', background: '#111827', color: '#fff' });
                    // Refresca de inmediato: el backend ya pudo marcar el sorteo
                    // como completado tras esta elección y el podio debe mostrar
                    // el ítem elegido.
                    trophyRoomRendered = false;
                    pollStatus();
                } else {
                    if (/definitiva|Ya elegiste/i.test(resp.message || '')) _sfVitrinaDone = true;
                    const errMsg = resp.message || (resp.error ? ('Servicio no disponible (' + resp.error + ').') : 'No se pudo confirmar.');
                    await Swal.fire({ title: 'Error', text: errMsg, icon: 'error', background: '#111827', color: '#fff' });
                }
            } catch (e) {
                console.error(e);
                await Swal.fire('Error', 'Fallo de conexi\u00f3n', 'error');
            } finally {
                _sfVitrinaOpen = false;
                if (!_sfVitrinaDone && _sfVitrinaCtx) _sfSyncVitrinaCta(true);
            }
        }

        // =====================================================================
        // VITRINA DE PREMIOS EN JUEGO — modal estilo juego abierto desde el
        // icono del regalo del centro de la ruleta. Muestra los premios por
        // puesto (Schema 2) en tarjetas con brillo por rareza, badges de paVos
        // y etiquetas de puesto. Un único premio fijo usa el mismo diálogo
        // ("Premio en juego", tarjeta centrada); el lightbox clásico sólo queda
        // como respaldo cuando el diálogo nativo no está disponible.
        // =====================================================================
        function _sfShowcasePositions() {
            const d = _lastStatusData || {};
            const positions = Array.isArray(d.prize_positions) ? d.prize_positions : [];
            return positions.filter(function (p) { return p && p.prize; });
        }

        // El pool elegible de un premio: el propio pool, o el pool dentro de un
        // combo (un combo tiene como máximo un pool).
        function _sfPrizePool(prize) {
            if (!prize) return null;
            if (prize.type === 'pool') return prize;
            if (prize.type === 'combo' && Array.isArray(prize.items)) {
                for (let i = 0; i < prize.items.length; i++) {
                    if (prize.items[i] && prize.items[i].type === 'pool') return prize.items[i];
                }
            }
            return null;
        }

        function _sfShowcaseQualifies() {
            const withContent = _sfShowcasePositions();
            if (withContent.length === 0) return false;
            const hasPool = withContent.some(function (p) { return p.prize.type === 'pool' || p.prize.type === 'combo'; });
            return hasPool || withContent.length > 1;
        }

        const _SF_RARITY_COLORS = {
            common: '#9ca3af', uncommon: '#22c55e', rare: '#38bdf8',
            epic: '#c084fc', legendary: '#f59e0b', mythic: '#facc15',
            icon: '#67e8f9', marvel: '#ef4444', dc: '#60a5fa',
            gaminglegends: '#818cf8', starwars: '#e2e8f0'
        };

        function _sfOrdinal(n) { return parseInt(n, 10) + '\u00b0'; }

        function _sfFixedViewToOption(prize) {
            return {
                kind: prize.type, name: prize.name || ((prize.type === 'coins' && prize.amount) ? (Number(prize.amount).toLocaleString('es-ES') + ' Bananos') : 'Premio'),
                image: prize.image, image_icon: prize.image_icon, amount: prize.amount, vbucks: prize.vbucks,
                rarity: prize.rarity, colors: prize.colors, displayType: prize.displayType, value_ref: prize.value_ref
            };
        }

        // ── Diálogos nativos (SfDialog + window.Backdrop) ─────────────────
        // Los paneles viven en index.php; aquí sólo se rellenan sus slots.
        const SF_DIALOG_ID = Object.freeze({
            prizes: 'sf-prizes-dialog',
            joined: 'sf-joined-dialog',
            removed: 'sf-removed-dialog',
            enrolled: 'sf-enrolled-dialog',
            prechoice: 'sf-prechoice-dialog'
        });

        function _sfDialog(id) {
            const el = document.getElementById(id);
            return (window.SfDialog && typeof HTMLDialogElement === 'function' && el instanceof HTMLDialogElement) ? el : null;
        }

        function _sfSetSlotText(root, slot, text) {
            const el = root.querySelector('[data-slot="' + slot + '"]');
            if (el) el.textContent = text;
        }

        function _sfSvgIcon(id, extraClass) {
            return '<svg' + (extraClass ? ' class="' + extraClass + '"' : '') +
                ' aria-hidden="true" focusable="false"><use href="#sf-icon-' + id + '"></use></svg>';
        }

        function _sfPlural(n, singular, plural) {
            return n + ' ' + (n === 1 ? singular : plural);
        }

        // ── Vitrina "Premios en juego": datos → grupos → HTML ─────────────
        // Un puesto se describe como una lista de grupos homogéneos:
        //   { icon, title, count, options, single }
        // combo → [incluidos fijos] + [pool "el ganador elige"]
        // pool  → [pool]     ·     fijo → [tarjeta protagonista]
        function _sfPrizeGroupsFor(prize) {
            if (!prize) return [];
            if (prize.type === 'combo') {
                const items = Array.isArray(prize.items) ? prize.items : [];
                const fixed = items.filter(function (it) { return it && it.type !== 'pool'; });
                const pool = _sfPrizePool(prize);
                const groups = [];
                if (fixed.length > 0) {
                    groups.push({
                        icon: 'layers',
                        title: prize.label || 'Pack de premios',
                        count: _sfPlural(fixed.length, 'premio', 'premios'),
                        options: fixed.map(_sfFixedViewToOption),
                        single: fixed.length === 1
                    });
                }
                if (pool) groups.push(_sfPoolGroup(pool));
                return groups;
            }
            if (prize.type === 'pool') return [_sfPoolGroup(prize)];
            return [{ icon: null, title: null, count: null, options: [_sfFixedViewToOption(prize)], single: true }];
        }

        function _sfPoolGroup(pool) {
            const options = Array.isArray(pool.options) ? pool.options.filter(Boolean) : [];
            return {
                icon: 'pointer',
                title: pool.label || 'El ganador elige uno',
                count: _sfPlural(options.length, 'opción', 'opciones'),
                options: options,
                single: false
            };
        }

        // Premios que recibe el ganador del puesto (un pool cuenta como uno).
        function _sfPositionPrizeCount(prize) {
            if (!prize) return 0;
            if (prize.type !== 'combo') return 1;
            const items = Array.isArray(prize.items) ? prize.items : [];
            return items.filter(Boolean).length;
        }

        function _sfPrizeGroupHtml(group) {
            const heading = group.title
                ? '<h3 class="sf-prize-group__title">' +
                    (group.icon ? _sfSvgIcon(group.icon) : '') +
                    '<span>' + escapeHtml(group.title) + '</span>' +
                    (group.count ? '<span class="sf-badge">' + escapeHtml(group.count) + '</span>' : '') +
                  '</h3>'
                : '';
            const cards = group.options.map(function (opt) { return _sfPrizeCardHtml(opt, '', 'li'); }).join('');
            return '<section class="sf-prize-group">' + heading +
                '<ul class="sf-prize-grid' + (group.single ? ' sf-prize-grid--single' : '') + '" role="list">' + cards + '</ul>' +
                '</section>';
        }

        function _sfPrizePanelHtml(position, index, tabbed) {
            const groups = _sfPrizeGroupsFor(position.prize).map(_sfPrizeGroupHtml).join('');
            const roleAttrs = tabbed
                ? ' role="tabpanel" id="sf-prizes-panel-' + index + '" aria-labelledby="sf-prizes-tab-' + index + '" tabindex="0"'
                : '';
            return '<div class="sf-prize-panel"' + roleAttrs + (tabbed && index !== 0 ? ' hidden' : '') + '>' + groups + '</div>';
        }

        function _sfPrizeTabHtml(position, index) {
            return '<button type="button" class="sf-tab" role="tab" id="sf-prizes-tab-' + index + '"' +
                ' aria-controls="sf-prizes-panel-' + index + '"' +
                ' aria-selected="' + (index === 0 ? 'true' : 'false') + '"' +
                ' tabindex="' + (index === 0 ? '0' : '-1') + '" data-tab-index="' + index + '">' +
                _sfSvgIcon('trophy') + '<span>Puesto ' + _sfOrdinal(position.position) + '</span>' +
                '</button>';
        }

        function _sfPrizesSummaryText(positions) {
            const total = positions.reduce(function (sum, p) { return sum + _sfPositionPrizeCount(p.prize); }, 0);
            if (positions.length > 1) {
                return _sfPlural(positions.length, 'puesto premiado', 'puestos premiados') + ' \u00b7 ' + _sfPlural(total, 'premio', 'premios');
            }
            return 'Puesto ' + _sfOrdinal(positions[0].position) + ' \u00b7 ' + _sfPlural(total, 'premio', 'premios');
        }

        // Pestañas (roving tabindex): un solo par de listeners delegados por
        // vida del documento; no se acumulan al reabrir.
        function _sfSelectPrizeTab(dialog, index, moveFocus) {
            const tabs = dialog.querySelectorAll('[role="tab"]');
            const panels = dialog.querySelectorAll('[role="tabpanel"]');
            if (!tabs[index]) return;
            tabs.forEach(function (tab, i) {
                const selected = i === index;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
                if (selected && moveFocus) tab.focus({ preventScroll: true });
            });
            panels.forEach(function (panel, i) { panel.hidden = i !== index; });
        }

        let _sfPrizesDialogWired = false;
        function _sfWirePrizesDialog(dialog) {
            if (_sfPrizesDialogWired) return;
            _sfPrizesDialogWired = true;
            const tablist = dialog.querySelector('[data-slot="tabs"]');
            if (!tablist) return;

            tablist.addEventListener('click', function (event) {
                const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
                if (tab) _sfSelectPrizeTab(dialog, Number(tab.dataset.tabIndex), false);
            });

            tablist.addEventListener('keydown', function (event) {
                const tabs = tablist.querySelectorAll('[role="tab"]');
                const current = Array.prototype.indexOf.call(tabs, document.activeElement);
                if (current < 0 || tabs.length === 0) return;
                let next = -1;
                if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                if (next < 0) return;
                event.preventDefault();
                _sfSelectPrizeTab(dialog, next, true);
            });
        }

        function _sfCanOpenPrizeShowcase() {
            return _sfShowcasePositions().length > 0 && !!_sfDialog(SF_DIALOG_ID.prizes);
        }

        function _sfOpenPrizeShowcase() {
            const positions = _sfShowcasePositions();
            const dialog = _sfDialog(SF_DIALOG_ID.prizes);
            if (positions.length === 0 || !dialog) return false;

            const tabbed = positions.length > 1;
            const tablist = dialog.querySelector('[data-slot="tabs"]');
            const panels = dialog.querySelector('[data-slot="panels"]');
            if (!tablist || !panels) return false;

            _sfWirePrizesDialog(dialog);
            const singlePrize = !_sfShowcaseQualifies();
            const titleEl = dialog.querySelector('#sf-prizes-title');
            if (titleEl) titleEl.textContent = singlePrize ? 'Premio en juego' : 'Premios en juego';
            dialog.classList.toggle('sf-dialog--showcase-single', singlePrize);
            _sfSetSlotText(dialog, 'summary', _sfPrizesSummaryText(positions));
            tablist.innerHTML = tabbed ? positions.map(_sfPrizeTabHtml).join('') : '';
            tablist.hidden = !tabbed;
            panels.innerHTML = positions.map(function (p, i) { return _sfPrizePanelHtml(p, i, tabbed); }).join('');
            panels.scrollTop = 0;

            SfDialog.open(dialog, { initialFocus: tabbed ? '[role="tab"][aria-selected="true"]' : null });
            return true;
        }

        async function playSequentialReveal(winners, startIndex = 0) {
            for (let i = startIndex; i < winners.length; i++) {
                const w = winners[i];
                await playMainAnimation(w, 'main', i); // i = índice de escena (seek por servidor)

                const avatar = w.avatar || 'default.png';
                document.getElementById('winnerNameDisplay').innerText = w.username;
                document.getElementById('winNameModal').innerText = w.username;
                document.getElementById('winAvatarMain').src = `../../assets/avatars/${avatar}`;
                document.getElementById('winAvatarModal').src = `../../assets/avatars/${avatar}`;
                document.getElementById('winActionDisplay').innerText = `Ganador #${w.position}`;

                // Dynamic messaging based on remaining winners
                const remainingWinners = winners.length - (i + 1);
                const mainTitleEl = document.getElementById('winnerMainTitle');
                const submessageEl = document.getElementById('winnerSubmessage');
                
                if (mainTitleEl) {
                    mainTitleEl.innerText = winners.length === 1 ? '¡NUESTRO GANADOR!' : '¡NUESTRO GANADOR!';
                }
                
                if (submessageEl) {
                    if (remainingWinners === 1) {
                        submessageEl.innerText = 'En breve conoceremos al siguiente ganador...';
                        submessageEl.style.display = 'block';
                    } else if (remainingWinners > 1) {
                        submessageEl.innerText = 'En breve conoceremos a los demás ganadores...';
                        submessageEl.style.display = 'block';
                    } else {
                        submessageEl.style.display = 'none';
                    }
                }

                const vw = document.getElementById('viewWinner');
                if (vw) vw.classList.remove('hidden');

                // Epic celebration effects
                { const _wk = i < 3 ? 'winner_' + (i+1) : 'winner_3'; if (!AdminAudio.play(_wk)) SorteoSFX.playWinFanfare(); }
                launchMassiveConfetti();
                generateWinnerParticles();

                // 4-second celebration (upgraded from 3 seconds)
                await sleep(4000);

                if (vw) vw.classList.add('hidden');
                clearWinnerParticles();
            }
        }

        /**
         * Launch massive confetti explosion covering the entire screen
         */
        function launchMassiveConfetti() {
            const duration = 4000;
            const animationEnd = Date.now() + duration;
            const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 10000 };

            function randomInRange(min, max) {
                return Math.random() * (max - min) + min;
            }

            const interval = setInterval(function() {
                const timeLeft = animationEnd - Date.now();

                if (timeLeft <= 0) {
                    return clearInterval(interval);
                }

                const particleCount = 50 * (timeLeft / duration);
                
                // Burst from multiple points for full coverage
                confetti({
                    ...defaults,
                    particleCount,
                    origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 }
                });
                confetti({
                    ...defaults,
                    particleCount,
                    origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 }
                });
            }, 250);

            // Initial massive burst
            confetti({
                particleCount: 200,
                spread: 160,
                origin: { y: 0.6 },
                colors: ['#fbbf24', '#f59e0b', '#ef4444', '#ec4899', '#8b5cf6', '#3b82f6']
            });
        }

        /**
         * Generate floating particles around the winner
         */
        function generateWinnerParticles() {
            const container = document.getElementById('winnerParticles');
            if (!container) return;

            const colors = ['#fbbf24', '#f59e0b', '#ef4444', '#ec4899', '#8b5cf6'];
            const particleCount = 40;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'winner-particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.bottom = '-10px';
                particle.style.background = colors[Math.floor(Math.random() * colors.length)];
                particle.style.setProperty('--drift', (Math.random() - 0.5) * 200 + 'px');
                particle.style.animationDuration = (2 + Math.random() * 2) + 's';
                particle.style.animationDelay = Math.random() * 0.5 + 's';
                container.appendChild(particle);
            }
        }

        /**
         * Clear particle system
         */
        function clearWinnerParticles() {
            const container = document.getElementById('winnerParticles');
            if (container) {
                container.innerHTML = '';
            }
        }

        function showSummaryModal(winners) {
            const rows = (winners || []).map(w => {
                let result = w.choice;
                if (w.choice === 'original') result = 'Premio';
                else if (w.choice === 'cashback') result = 'Canje';
                else if (w.choice === 'double_won') result = 'Doble (x2)';
                else if (w.choice === 'double_lost') result = 'Perdió (&#128128;)';
                return `<tr><td style="padding:6px 10px; border-bottom:1px solid #222;">#${w.position}</td><td style="padding:6px 10px; border-bottom:1px solid #222;">${w.username}</td><td style="padding:6px 10px; border-bottom:1px solid #222;">${result}</td></tr>`;
            }).join('');

            const html = `<div style="text-align:left;"><table style="width:100%; font-size:12px; border-collapse:collapse;"><thead><tr><th style="padding:6px 10px; border-bottom:1px solid #333;">Pos</th><th style="padding:6px 10px; border-bottom:1px solid #333;">Usuario</th><th style="padding:6px 10px; border-bottom:1px solid #333;">Resultado</th></tr></thead><tbody>${rows}</tbody></table></div>`;
            Swal.fire({ title: 'Resumen', html, background: '#111', color: '#fff', confirmButtonColor: '#eab308' });
        }

        function sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (ch) => {
                if (ch === '&') return '&amp;';
                if (ch === '<') return '&lt;';
                if (ch === '>') return '&gt;';
                if (ch === '"') return '&quot;';
                return '&#39;';
            });
        }

        function formatSide(side) {
            if (!side) return null;
            const s = String(side).toLowerCase();
            if (s === 'heads') return 'Cara (heads)';
            if (s === 'tails') return 'Sello (tails)';
            if (s === 'red') return 'Rojo';
            if (s === 'black') return 'Negro';
            return '-';
        }

        // Ruleta Rojo/Negro (Doble o Nada): colores canónicos de casino.
        const ROULETTE_RED = '#D3223A';
        const ROULETTE_BLACK = '#17171F';
        function _sfRouletteSideLabel(side) {
            return side === 'black' ? 'NEGRO' : side === 'red' ? 'ROJO' : null;
        }

        function _sfFormatCoins(value) {
            const amount = Number(value);
            if (!Number.isFinite(amount)) return '0';
            return amount.toLocaleString('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        function _sfWinnerFulfillment(w) {
            return (w && w.fulfillment && typeof w.fulfillment === 'object') ? w.fulfillment : {};
        }

        function _sfAutomaticCreditCoins(w) {
            const fulfillment = _sfWinnerFulfillment(w);
            const amount = Number(fulfillment.automatic_credit_coins ?? w?.automatic_credit_coins ?? w?.cashback_credited_coins ?? w?.double_bonus_coins ?? 0);
            return Number.isFinite(amount) && amount > 0 ? amount : 0;
        }

        function _sfWinnerPrizeLabel(w) {
            const fp = w && w.final_prize ? w.final_prize : null;
            if (fp) {
                const resolved = fp.resolved_item && fp.resolved_item.name ? fp.resolved_item : fp;
                if (resolved.kind === 'coins' && resolved.amount) return _sfFormatCoins(resolved.amount) + ' Bananos';
                if (resolved.name || resolved.label) return String(resolved.name || resolved.label);
            }
            const prize = w && w.prize ? w.prize : null;
            if (prize) {
                if (prize.type === 'coins' && prize.amount) return _sfFormatCoins(prize.amount) + ' Bananos';
                if (prize.type === 'combo') return prize.label || 'Combo de premios';
                if (prize.type === 'pool') return prize.label || 'Premio de la Vitrina';
                if (prize.name) return String(prize.name);
            }
            return 'Premio del sorteo';
        }

        // Nombre concreto del premio que se llevó (o se jugaba) el ganador: ítem
        // final si ya existe, ítem preelegido de la Vitrina si aún no, o el premio
        // fijo del puesto. Nunca devuelve el genérico "Premio".
        function _sfWinnerConcretePrizeName(w) {
            const label = _sfWinnerPrizeLabel(w);
            const prize = w && w.prize ? w.prize : null;
            const isPool = !!(prize && (prize.type === 'pool' || _sfPrizePool(prize)));
            if (isPool && !(w && w.final_prize)) {
                const pre = (w && w.pre_pool_item && w.pre_pool_item.name) ? String(w.pre_pool_item.name) : null;
                if (pre) return pre;
            }
            return label;
        }

        // Icono SVG distintivo del Doble o Nada (dado) para la etiqueta de elección.
        function _sfGambleSvgIcon(size) {
            const s = parseInt(size, 10) || 16;
            return `<svg class="tr-gamble-svg" width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">` +
                `<rect x="3" y="3" width="18" height="18" rx="4"/>` +
                `<circle cx="8" cy="8" r="1.4" fill="currentColor" stroke="none"/>` +
                `<circle cx="16" cy="8" r="1.4" fill="currentColor" stroke="none"/>` +
                `<circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/>` +
                `<circle cx="8" cy="16" r="1.4" fill="currentColor" stroke="none"/>` +
                `<circle cx="16" cy="16" r="1.4" fill="currentColor" stroke="none"/>` +
                `</svg>`;
        }

        function renderTrophyRoomWinners(winners) {
            const container = document.getElementById('resultsWinners');
            if (!container) return;

            const list = Array.isArray(winners) ? winners : [];

            // CONDICIÓN SINGULAR / PLURAL AUTOMÁTICA
            const titleEl = document.querySelector('#viewResults .tr-title');
            const subtitleEl = document.querySelector('#viewResults .tr-subtitle');

            if (list.length === 1) {
                if (titleEl) titleEl.innerText = '¡TENEMOS GANADOR!';
                if (subtitleEl) subtitleEl.innerText = 'Felicidades a nuestro afortunado participante';
            } else {
                if (titleEl) titleEl.innerText = '¡TENEMOS GANADORES!';
                if (subtitleEl) subtitleEl.innerText = 'Felicidades a nuestros afortunados participantes';
            }

            // Widen shell when 2+ winners for better desktop layout
            const shell = document.querySelector('#viewResults .tr-shell');
            if (shell) shell.classList.toggle('tr-shell--wide', list.length >= 2);

            if (list.length === 0) {
                container.innerHTML = '';
                return;
            }

            // Gamble type label helper
            function getGambleTypeLabel(w) {
                const mode = String(w.selected_animation_mode || CONFIG?.anims?.gamble || '').toLowerCase();
                const side = String(w.coin_choice || w.prediction_side || '').toLowerCase();
                if (mode === 'coin') {
                    if (side === 'heads') return 'Moneda<span class="tr-hide-mobile"> (Cara)</span>';
                    if (side === 'tails') return 'Moneda<span class="tr-hide-mobile"> (Sello)</span>';
                    return 'Moneda';
                }
                if (mode === 'roulette') {
                    if (side === 'red') return 'Ruleta<span class="tr-hide-mobile"> (Rojo)</span>';
                    if (side === 'black') return 'Ruleta<span class="tr-hide-mobile"> (Negro)</span>';
                    return 'Ruleta';
                }
                if (mode === 'slots' || mode === 'slot') return 'Slots';
                return '';
            }

            // Build choice + result metadata per winner
            function getWinnerStory(w) {
                const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                const base = _sfWinnerPrizeLabel(w);

                // En modo "Moneda" mostramos el ícono de Banano (mismo asset que
                // la opción Cashback de la pre-elección) en vez de la corona.
                const _gambleIcon = (function () {
                    const mode = String(w.selected_animation_mode || CONFIG?.anims?.gamble || '').toLowerCase();
                    if (mode === 'coin') return _sfBananosImg(20);
                    return '\u{1F3B2}';
                })();

                if (c === 'original') {
                    // Schema 2: premio del puesto + elección del pool si la hay.
                    let label = base;
                    let sub = 'Reclamó su premio';
                    const wp = w.prize || null;
                    if (wp && wp.type === 'combo') {
                        label = wp.label || 'Combo de premios';
                    } else if (wp && wp.type && wp.type !== 'pool') {
                        if (wp.type === 'coins' && wp.amount) label = `${Number(wp.amount).toLocaleString()} Bananos`;
                        else if (wp.name) label = wp.name;
                    }
                    const fp = w.final_prize || null;
                    const st = w.prize_state || null;
                    if (fp) {
                        const fpName = _sfFinalPrizeName(w);
                        if (fpName) label = fpName;
                        if (st === 'admin_pending') sub = 'Eligió categoría — ítem por confirmar';
                        else sub = 'Eligió su premio de la vitrina';
                    } else if (st === 'awaiting_selection') {
                        sub = 'Eligiendo ítem de la vitrina...';
                        const preItem = (w.pre_pool_item && w.pre_pool_item.name) ? String(w.pre_pool_item.name) : null;
                        label = preItem ? (preItem + ' (por confirmar)') : 'Por elegir en la Vitrina';
                    }
                    return {
                        choiceLabel: _sfPrizePool(wp) ? 'Premio de la Vitrina' : 'Premio original',
                        choiceIcon: '🎁',
                        resultLabel: label,
                        resultSub: sub,
                        cls: 'tr-story--original'
                    };
                }
                if (c === 'cashback') {
                    const credited = _sfAutomaticCreditCoins(w);
                    return {
                        choiceLabel: 'Cashback en Bananos',
                        choiceIcon: _sfBananosImg(20),
                        resultLabel: credited > 0 ? `${_sfFormatCoins(credited)} Bananos` : 'Bananos acreditados',
                        resultSub: 'Acreditación automática',
                        cls: 'tr-story--cashback'
                    };
                }
                if (c === 'double_won') {
                    const gambleType = getGambleTypeLabel(w);
                    const choiceSuffix = gambleType ? ` — ${gambleType}` : '';
                    const credited = _sfAutomaticCreditCoins(w);
                    const prizeName = _sfWinnerConcretePrizeName(w);
                    return {
                        choiceLabel: 'Doble o nada' + choiceSuffix,
                        choiceIcon: _gambleIcon,
                        choiceBadge: _sfGambleSvgIcon(15),
                        resultLabel: credited > 0 ? `${prizeName} + ${_sfFormatCoins(credited)} Bananos` : `${prizeName} + Bananos`,
                        resultSub: '¡Ganó el doble!',
                        cls: 'tr-story--won'
                    };
                }
                if (c === 'double_lost') {
                    const gambleType = getGambleTypeLabel(w);
                    const choiceSuffix = gambleType ? ` — ${gambleType}` : '';
                    return {
                        choiceLabel: 'Doble o nada' + choiceSuffix,
                        choiceIcon: _gambleIcon,
                        choiceBadge: _sfGambleSvgIcon(15),
                        resultLabel: 'Perdió todo el premio 💀',
                        resultSub: 'Perdió en Doble o Nada',
                        cls: 'tr-story--lost'
                    };
                }
                if (c === 'gamble_pending') {
                    const gambleType = getGambleTypeLabel(w);
                    const choiceSuffix = gambleType ? ` — ${gambleType}` : '';
                    return {
                        choiceLabel: 'Doble o nada' + choiceSuffix,
                        choiceIcon: _gambleIcon,
                        choiceBadge: _sfGambleSvgIcon(15),
                        resultLabel: 'Pendiente…',
                        resultSub: 'Esperando resolución',
                        cls: 'tr-story--pending'
                    };
                }
                // pending / unknown
                return {
                    choiceLabel: 'Decidiendo…',
                    choiceIcon: '🤔',
                    resultLabel: 'Por definir',
                    resultSub: 'Aún no eligió',
                    cls: 'tr-story--thinking'
                };
            }

            // Dynamic grid modifier based on winner count
            container.classList.remove('tr-grid-few', 'tr-grid-many');
            container.classList.add(list.length <= 2 ? 'tr-grid-few' : 'tr-grid-many');

            container.innerHTML = list.map((w, idx) => {
                const uname = escapeHtml(w && w.username ? w.username : '');
                const avatar = escapeHtml(w && w.avatar ? w.avatar : 'default.png');
                const story = getWinnerStory(w);

                const fulfillment = _sfWinnerFulfillment(w);
                const manualDelivery = fulfillment.manual_delivery_required === true;
                const creditedCoins = _sfAutomaticCreditCoins(w);
                const dStatus = (manualDelivery && w && w.delivery_status) ? String(w.delivery_status) : null;
                // El caso double_won ya queda descrito en RESULTADO (nombre del
                // premio + Bananos), por eso no lleva pastilla adicional.
                let automaticState = '';
                if (String(w.choice || '') === 'cashback') {
                    automaticState = `<div class="tr-reward-info tr-reward-info--automatic">
                        <span class="tr-reward-check">✅</span>
                        ${creditedCoins > 0 ? _sfFormatCoins(creditedCoins) : '0'} Bananos acreditados automáticamente
                        ${_sfBananosImg(20)}
                    </div>`;
                }
                const pendingDeliveryLabel = String(w.choice || '') === 'double_won' ? 'Estado del premio original' : 'Estado de premio';
                const rewardBtn = dStatus ? `
                        <button type="button" class="tr-reward-btn ${dStatus === 'delivered' ? 'is-delivered' : 'is-pending'}" onclick="openDeliveryProofModal(${idx})">
                            <span class="tr-reward-dot"></span>
                            ${dStatus === 'delivered' ? 'Recompensa entregada' : pendingDeliveryLabel}
                        </button>` : '';

                return `
                <div class="tr-winner-card" style="--card-i:${idx}">
                    <div class="tr-winner-glow"></div>
                    <div class="tr-winner-border"></div>
                    <div class="tr-winner-inner">
                        <div class="tr-avatar-wrap">
                            <div class="tr-avatar-ring"></div>
                            <div class="tr-avatar">
                                <img src="../../assets/avatars/${avatar}" alt="${uname}" />
                            </div>
                        </div>
                        <div class="tr-username">${uname}</div>
                        <div class="tr-story ${story.cls}">
                            <div class="tr-story-row">
                                <span class="tr-story-key">Elección</span>
                                <span class="tr-story-val"><span class="tr-story-icon">${story.choiceIcon}</span> ${story.choiceLabel}${story.choiceBadge ? `<span class="tr-story-badge tr-story-badge--gamble" title="Doble o Nada" aria-label="Doble o Nada">${story.choiceBadge}</span>` : ''}</span>
                            </div>
                            <div class="tr-story-divider"></div>
                            <div class="tr-story-row tr-story-row--result">
                                <span class="tr-story-key">Resultado</span>
                                <span class="tr-story-val tr-story-val--result">${escapeHtml(story.resultLabel).replace(/🔥/, '<span class="tr-fire">🔥</span>').replace(/💀/, '<span class="tr-skull">💀</span>').replace(/🤑/, '<span class="tr-fire">🤑</span>')}</span>
                            </div>
                        </div>
                        <button type="button" class="tr-prize-btn" onclick="showWinnerDetails(${idx})">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <span class="tr-prize-btn-label">Ver detalles</span>
                            <svg class="tr-prize-btn-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        ${automaticState}
                        ${rewardBtn}
                    </div>
                </div>
            `;
            }).join('');
        }

        function getGamblePlaybackKey(w) {
            if (!w) return null;
            const idPart = (w.user_id !== undefined && w.user_id !== null)
                ? String(parseInt(w.user_id, 10))
                : (w.username ? String(w.username) : null);
            if (!idPart) return null;
            const tgt = (w.gamble_block_target !== undefined && w.gamble_block_target !== null) ? String(w.gamble_block_target) : '';
            const c = (w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
            return `${CONFIG.raffleId}|${idPart}|${c}|${tgt}`;
        }

        function getNormalizedGambleResult(gr) {
            if (!gr) return null;
            if (typeof gr === 'object') return gr;
            if (typeof gr === 'string') {
                try {
                    return JSON.parse(gr);
                } catch (e) {
                    return null;
                }
            }
            return null;
        }

        function resetGamblePlaybackState() {
            gamblePlaybackInProgress = false;
            gamblePlaybackCurrentKey = null;
            gamblePlaybackCurrentWinner = null;

            stopGamblePolling();
            stopDecisionCountdown();

            const mo = document.getElementById('mainAnimOverlay');
            if (mo) mo.classList.add('hidden');
            const go = document.getElementById('gambleOverlay');
            if (go) go.classList.add('hidden');

            isGambling = false;
            activeGamblerName = null;
            activeGamblerId = null;
            activeGambleAnim = null;
            activeGamblePredictionSide = null;

            if (!summaryShown) {
                summaryShown = true;
            }
        }

        function markResolvedGamblesAsPlayed(winners) {
            const list = Array.isArray(winners) ? winners : [];

            // Línea de tiempo de la cola: una franja por ganador sólo se marca
            // como "ya vista" si globalElapsed superó su franja COMPLETA
            // (resolución + cinemática). Así, al reenganchar en una cola de
            // múltiples apuestas, sólo se saltan los ganadores realmente
            // pasados y el selector aterriza en la animación viva (bug 1).
            const queue = _gambleQueue(list);
            const ge = _gambleGlobalElapsedMs(list);
            if (ge !== null) {
                for (const item of queue) {
                    if (ge >= item.offsetMs + item.slotMs) {
                        gamblePlaybackPlayedKeys.add(item.key);
                    }
                }
                return;
            }

            // Sin ancla del servidor: respaldo al flag per-ganador del backend.
            for (const w of list) {
                if (!w) continue;
                const c = (w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                if (c !== 'double_won' && c !== 'double_lost') continue;
                const gap = w.gamble_animation_phase || {};
                if (gap.active === true) {
                    console.log(`[GAMBLE-REHYDRATE] Skipping mark-as-played for ${w.username} — gamble anim still active (${gap.remaining_seconds}s left)`);
                    continue;
                }
                const k = getGamblePlaybackKey(w);
                if (k) gamblePlaybackPlayedKeys.add(k);
            }
        }

        function maybeStartSequentialGamblePlayback(data) {
            if (!data) return false;
            if (!mainAnimPlayed || isMainAnimating) return false;
            const winners = Array.isArray(data.winners) ? data.winners : [];
            gamblePlaybackSnapshot = winners;
            if (gamblePlaybackInProgress) return true;

            const hasPendingDecisions = winners.some(w => w && (w.choice === 'pending' || w.hud_state === 'thinking'));
            if (hasPendingDecisions) return false;

            const hasActive = winners.some(w => w && (w.hud_state === 'spinning' || w.hud_state === 'ready_queue' || w.choice === 'gamble_pending'));
            if (hasActive) return false;

            // Selector consciente de la cola: salta las franjas ya vencidas
            // (las marca como vistas) y arranca en la animación viva con su
            // seek exacto. Resuelve el bug 1 (entrar tarde a partir del 2º/3º
            // ganador) porque el seek descuenta la duración de los anteriores.
            const pick = _pickCurrentGambleAndSeek(winners);
            if (!pick) return false;
            if (Array.isArray(pick.skipKeys)) pick.skipKeys.forEach(k => gamblePlaybackPlayedKeys.add(k));
            if (!pick.w) return false; // toda la cola ya transcurrió
            startSequentialGamblePlaybackForWinner(pick.w, pick.key, pick.seekMs);
            return true;
        }

        function startSequentialGamblePlaybackForWinner(w, key, seekMs) {
            if (!w || !key) return;
            gamblePlaybackInProgress = true;
            gamblePlaybackCurrentKey = key;
            gamblePlaybackCurrentWinner = w;

            const gr = getNormalizedGambleResult(w.gamble_result);
            const outcomeSide = gr ? (gr.outcome_side || gr.outcomeSide || gr.outcome || null) : null;
            const won = (w.choice === 'double_won');

            lastGambleBlockHash = w.gamble_block_hash || lastGambleBlockHash;
            lastGambleOutcome = outcomeSide || lastGambleOutcome;

            hardResetGambleAnimationDOM();
            rebuildGambleAnimationDOM();
            isGambling = true;

            activeGamblerName = w.username || null;
            activeGamblerId = (w.user_id !== undefined && w.user_id !== null) ? parseInt(w.user_id, 10) : null;
            activeGambleAnim = (w.selected_animation_mode || (gr && gr.animation_pref) || (CONFIG?.anims?.gamble) || null);
            activeGamblePredictionSide = w.prediction_side || w.coin_choice || (gr ? gr.prediction_side : null);

            // SEEK ABSOLUTO DEL GAMBLE: ms ya transcurridos del giro de
            // resolución de ESTA franja de la cola según la línea de tiempo
            // global (serverNow − T0 − offset_N). Lo calcula el selector
            // (_pickCurrentGambleAndSeek) descontando la duración de los
            // ganadores anteriores; quien entra tarde o vuelve de minimizar se
            // engancha al fotograma exacto en vez de saltar al resultado o
            // reiniciar desde cero, para cualquier índice de ganador.
            const _gSeekMs = (Number.isFinite(seekMs) && seekMs > 0) ? seekMs : 0;

            runGambleAnimation({
                ...w,
                gamble_result: gr || w.gamble_result || { animation_pref: activeGambleAnim }
            });

            if (gamblePlaybackStartTimeout) {
                clearTimeout(gamblePlaybackStartTimeout);
                gamblePlaybackStartTimeout = null;
            }
            gamblePlaybackStartTimeout = setTimeout(() => {
                gamblePlaybackStartTimeout = null;
                finishGambleAnimation(won, w.username, isMyWinner(w), outcomeSide, _gSeekMs);
            }, 50);
        }

        // ====================================================================
        //  LÍNEA DE TIEMPO DE LA COLA DEL DOBLE O NADA (multi-ganador)
        //  --------------------------------------------------------------
        //  Los gambles se resuelven en el backend casi simultáneamente, pero el
        //  cliente reproduce sus animaciones EN COLA (una tras otra). Para que
        //  el seek y el reenganche funcionen con cualquier índice de ganador,
        //  anclamos TODA la cola a una sola línea de tiempo compartida:
        //    • T0 = máximo de los timestamps de resolución (idéntico en todos
        //      los clientes ⇒ determinista, sin depender de cuándo cada uno
        //      empezó a reproducir).
        //    • Cada ganador ocupa una franja [offset, offset+slot) en orden de
        //      posición; slot = resolución + cinemática (4s).
        //    • globalElapsed = serverNow − T0.  seek_N = globalElapsed − offset_N.
        //  Así el ganador N descuenta la duración de los N−1 anteriores.
        // ====================================================================

        // Duración de la franja de cada modo: giro de resolución + cinemática.
        //   coin     16s + 4s = 20s
        //   roulette 22.5s + 4s = 26.5s
        //   slots    17s + 4s = 21s
        const GAMBLE_CINEMATIC_MS = 4000;
        const GAMBLE_SLOT_MS = { coin: 20000, roulette: 26500, slots: 21000 };
        const GAMBLE_RESOLVE_MS = { coin: 16000, roulette: 22500, slots: 17000 };

        function _gambleModeOf(w) {
            const gr = getNormalizedGambleResult(w && w.gamble_result);
            const m = String(
                (w && w.selected_animation_mode) ||
                (gr && gr.animation_pref) ||
                (CONFIG && CONFIG.anims && CONFIG.anims.gamble) ||
                'coin'
            ).toLowerCase();
            if (m === 'roulette') return 'roulette';
            if (m === 'slots' || m === 'slot') return 'slots';
            return 'coin';
        }

        // Cola ordenada (por posición) de los ganadores que eligieron Doble o
        // Nada y ya resolvieron. Cada item incluye su modo, duración de franja
        // y offset acumulado en la línea de tiempo de la cola.
        function _gambleQueue(snapshot) {
            const list = (Array.isArray(snapshot) ? snapshot : [])
                .filter(w => w && (w.choice === 'double_won' || w.choice === 'double_lost'))
                .map(w => ({ w, key: getGamblePlaybackKey(w) }))
                .filter(x => x.key)
                .sort((a, b) => {
                    const pa = (a.w.position !== undefined && a.w.position !== null) ? parseInt(a.w.position, 10) : 0;
                    const pb = (b.w.position !== undefined && b.w.position !== null) ? parseInt(b.w.position, 10) : 0;
                    return pa - pb;
                });
            let offset = 0;
            for (const item of list) {
                item.mode = _gambleModeOf(item.w);
                item.slotMs = GAMBLE_SLOT_MS[item.mode] || GAMBLE_SLOT_MS.coin;
                item.resolveMs = GAMBLE_RESOLVE_MS[item.mode] || GAMBLE_RESOLVE_MS.coin;
                item.offsetMs = offset;
                offset += item.slotMs;
            }
            return list;
        }

        // T0 compartido: máximo timestamp de resolución (phase_started_at) entre
        // los ganadores de la cola. Devuelve null si el servidor aún no expone
        // los timestamps (entonces se cae al comportamiento clásico: seek 0).
        function _gamblePlaybackAnchorMs(queue) {
            let t0 = null;
            for (const item of queue) {
                const gap = item.w.gamble_animation_phase;
                const ps = (gap && gap.phase_started_at) ? Date.parse(gap.phase_started_at) : NaN;
                if (Number.isFinite(ps)) t0 = (t0 === null) ? ps : Math.max(t0, ps);
            }
            return t0;
        }

        // Duración total de la cola del gamble (ms). 0 si no hay cola.
        function _gambleQueueTotalMs(snapshot) {
            const q = _gambleQueue(snapshot);
            if (q.length === 0) return 0;
            const last = q[q.length - 1];
            return last.offsetMs + last.slotMs;
        }

        // ms transcurridos en la línea de tiempo global de la cola (serverNow−T0).
        // null si no hay ancla disponible.
        function _gambleGlobalElapsedMs(snapshot) {
            const q = _gambleQueue(snapshot);
            if (q.length === 0) return null;
            const t0 = _gamblePlaybackAnchorMs(q);
            if (t0 === null) return null;
            return Math.max(0, _sfServerNow() - t0);
        }

        // ¿La cola del gamble todavía tiene fotogramas por mostrar? Consciente
        // de la línea de tiempo acumulada (no del flag per-ganador del backend).
        function _gambleQueueStillActive(snapshot) {
            const total = _gambleQueueTotalMs(snapshot);
            if (total <= 0) return false;
            const ge = _gambleGlobalElapsedMs(snapshot);
            if (ge === null) {
                // Sin ancla del servidor: usa el flag per-ganador como respaldo.
                return (Array.isArray(snapshot) ? snapshot : []).some(
                    w => w && w.gamble_animation_phase && w.gamble_animation_phase.active === true
                );
            }
            return ge < total;
        }

        // Selecciona el ganador que debe reproducirse AHORA según la línea de
        // tiempo de la cola, salta (marca como vistas) las franjas ya vencidas,
        // y devuelve el seek exacto dentro de la franja viva.
        //   → { w, key, seekMs, skipKeys } | null
        function _pickCurrentGambleAndSeek(snapshot) {
            const queue = _gambleQueue(snapshot);
            if (queue.length === 0) return null;
            const ge = _gambleGlobalElapsedMs(snapshot);
            // Sin ancla del servidor ⇒ comportamiento clásico: primer no-visto, seek 0.
            if (ge === null) {
                const first = queue.find(x => !gamblePlaybackPlayedKeys.has(x.key));
                return first ? { w: first.w, key: first.key, seekMs: 0, skipKeys: [] } : null;
            }
            const skipKeys = [];
            for (const item of queue) {
                if (gamblePlaybackPlayedKeys.has(item.key)) continue;
                const localElapsed = ge - item.offsetMs;
                if (localElapsed >= item.slotMs) {
                    // Franja completamente vencida (resolución + cinemática) ⇒
                    // saltar sin renderizar (quedó en el pasado de la cola).
                    skipKeys.push(item.key);
                    continue;
                }
                return { w: item.w, key: item.key, seekMs: Math.max(0, localElapsed), skipKeys };
            }
            // Todo visto / vencido.
            return { w: null, key: null, seekMs: 0, skipKeys };
        }

        function hardResetMainAnimationDOM() {
            // Cancel any active rAF wheel spin
            if (typeof _wheelRafId !== 'undefined' && _wheelRafId) {
                cancelAnimationFrame(_wheelRafId);
                _wheelRafId = null;
            }

            const wheel = document.getElementById('mainWheel');
            if (wheel) {
                wheel.style.transition = 'none';
                wheel.style.transform = 'rotate(0deg)';
                wheel.offsetHeight;
            }

            const strip1 = document.getElementById('reel-strip');
            if (strip1) {
                strip1.style.transition = 'none';
                strip1.style.transform = 'translateY(0px)';
                strip1.offsetHeight;
            }

            const strip2 = document.getElementById('reel-strip-2');
            if (strip2) {
                strip2.style.transition = 'none';
                strip2.style.transform = 'translateY(0px)';
                strip2.offsetHeight;
            }
        }

        function hardResetGambleAnimationDOM() {
            if (postGambleCleanupTimeout) {
                clearTimeout(postGambleCleanupTimeout);
                postGambleCleanupTimeout = null;
            }

            if (finishGambleTimeout) {
                clearTimeout(finishGambleTimeout);
                finishGambleTimeout = null;
            }

            if (betsClosedTimer) {
                clearInterval(betsClosedTimer);
                betsClosedTimer = null;
            }
            betsClosedUntilMs = 0;
            awaitingGambleExecution = false;

            // Clean up turn indicator
            hideGambleTurnIndicator();

            stopGamblePolling();

            // Reset gamble block ticker
            _gambleBlockCurrent = 0;
            _gambleBlockTarget = 0;
            _gambleBlockDisplay = 0;
            const raceReset = document.getElementById('gambleBlockRace');
            if (raceReset) raceReset.classList.add('hidden');

            isGambling = false;
            gambleAnimPlayed = false;

            if (typeof Swal !== 'undefined' && Swal.isVisible()) {
                Swal.close();
            }

            if (gambleInterval) {
                clearInterval(gambleInterval);
                gambleInterval = null;
            }

            gambleWheelRotation = 0;
            gambleWheelSliceDeg = 0;
            gambleWheelPool = null;
            gambleCoinDeg = 0;
            gambleSlotMeta = null;

            const overlay = document.getElementById('gambleOverlay');
            if (overlay) overlay.classList.add('hidden');

            const mainOverlay = document.getElementById('mainAnimOverlay');
            if (mainOverlay) mainOverlay.classList.add('hidden');

            const coin = document.getElementById('theCoin');
            if (coin) {
                coin.style.transition = 'none';
                coin.style.transform = 'rotateY(0deg)';
                coin.offsetHeight;
            }

            const wheel = document.getElementById('mainWheel');
            if (wheel) {
                wheel.style.transition = 'none';
                wheel.style.transform = 'rotate(0deg)';
                wheel.offsetHeight;
            }

            const track = document.getElementById('reel-strip');
            if (track) {
                track.style.transition = 'none';
                track.style.transform = 'translateY(0px)';
                track.offsetHeight;
            }
        }

        function captureGambleAnimationTemplates() {
            const go = document.getElementById('gambleOverlay');
            const wc = document.getElementById('animWheelContainer');
            const sc = document.getElementById('animSlotContainer');
            if (go) gambleOverlayTemplate = go.cloneNode(true);
            if (wc) animWheelContainerTemplate = wc.cloneNode(true);
            if (sc) animSlotContainerTemplate = sc.cloneNode(true);
        }

        function rebuildGambleAnimationDOM() {
            const parent = document.getElementById('mainAnimOverlay');
            if (!parent) return;

            const go = document.getElementById('gambleOverlay');
            if (go && gambleOverlayTemplate) {
                parent.replaceChild(gambleOverlayTemplate.cloneNode(true), go);
            }

            const wc = document.getElementById('animWheelContainer');
            if (wc && animWheelContainerTemplate) {
                parent.replaceChild(animWheelContainerTemplate.cloneNode(true), wc);
            }

            const sc = document.getElementById('animSlotContainer');
            if (sc && animSlotContainerTemplate) {
                parent.replaceChild(animSlotContainerTemplate.cloneNode(true), sc);
            }

            const newGO = document.getElementById('gambleOverlay');
            if (newGO) void newGO.offsetWidth;
            const newWheel = document.getElementById('mainWheel');
            if (newWheel) void newWheel.offsetWidth;
            const newCoin = document.getElementById('theCoin');
            if (newCoin) void newCoin.offsetWidth;
            const newTrack = document.getElementById('reel-strip');
            if (newTrack) void newTrack.offsetWidth;
        }

        /** Tope de espera del velo de arranque (body.sf-booting) antes de destaparse solo. */
        const BOOT_VEIL_MAX_MS = 8000;

        /**
         * Consume (una sola vez) la lectura de estado que index.php lanzó desde
         * <head> en paralelo con la descarga de la página, para que el primer
         * poll no tenga que esperar a que cargue todo el JS.
         * @returns {Promise<Object|null>|null}
         */
        function _sfTakeBootStatus() {
            const boot = window.__sfBootStatus;
            window.__sfBootStatus = null;
            return (boot && typeof boot.then === 'function') ? boot : null;
        }

        /** Destapa la página: el primer estado real ya enrutó a la fase vigente. */
        function _sfLiftBootVeil() {
            if (!document.body.classList.contains('sf-booting')) return;
            document.body.classList.remove('sf-booting');
            const veil = document.querySelector('.sf-boot-veil');
            if (veil) veil.remove();
        }

        document.addEventListener('DOMContentLoaded', () => {
            _sfLoadStoreVisualIndex();
            captureGambleAnimationTemplates();
            const modalBody = document.getElementById('decisionModalBody');
            if (modalBody) decisionModalBodyTemplate = modalBody.innerHTML;

            // Red de seguridad: si el primer poll no llega (red caída, error del
            // servidor) el velo se levanta igual y se muestra lo que haya.
            setTimeout(_sfLiftBootVeil, BOOT_VEIL_MAX_MS);
            startStatusPolling();
        });

        // --- 1. POLLING INTELIGENTE (ADAPTADO V3) ---
        async function pollStatus() {
            if (pollLock) return;
            pollLock = true;
            try {
                const ts = Date.now();
                const pollBody = { action: CONFIG.apis.status, id: CONFIG.raffleId, nocache: ts };
                if (mainAnimPlayed && !animFinishedSent) { pollBody.anim_finished = true; animFinishedSent = true; }
                let raw = firstLoad ? await _sfTakeBootStatus() : null;
                if (!raw) {
                    const res = await fetch(CONFIG.apis.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(pollBody) });
                    raw = await res.json();
                }
                const data = await _resolveSyncFrame(raw);
                if (data.status === 'error') return;

                state.lastData = data;
                _sfLiftBootVeil();
                state.transparency = data.transparency || null;

                // PUNTO 8: actualizar skew de reloj y origen absoluto de la
                // animación en cada poll (alimenta el seek sincronizado).
                _sfSyncFromStatus(data);

                // CHECKPOINT — anti-regresión: si llega una respuesta atrasada o
                // fuera de orden (epoch menor al último visto), la descartamos para
                // que la UI nunca retroceda a una fase ya superada.
                const _cp = data.checkpoint || null;
                if (_cp && Number.isFinite(_cp.epoch)) {
                    if (!firstLoad && _cp.epoch < _lastCheckpointEpoch) {
                        return; // frame viejo: ignóralo (no toca la UI)
                    }
                    if (_cp.epoch > _lastCheckpointEpoch) _lastCheckpointEpoch = _cp.epoch;
                }

                // Cadencia adaptativa: recalcula el retraso del próximo poll según la fase.
                _pollDelayMs = _computePollDelay(data);

                // RETIRO EN VIVO: si el polling detecta que fui expulsado de este
                // sorteo o baneado de la plataforma, lanzo el modal bloqueante.
                if (data.my_banned) {
                    _sfShowExpelledModal('banned');
                } else if (data.my_review_status === 'expelled') {
                    _sfShowExpelledModal('expelled');
                }

                // Sincronizar Configuración Global
                if (data.mode) CONFIG.mode = data.mode;
                if (data.animations) { CONFIG.anims.main = data.animations.main; CONFIG.anims.gamble = data.animations.gamble; }
                if (data.endgame) currentUser = data.endgame.is_me;

                state.status = data.status;
                const isDecisionState = (data.status === 'waiting_for_winner' || data.status === 'waiting_for_decision' || data.status === 'waiting_for_winners');
                const isDone = (data.status === 'completed' || isDecisionState);

                if (!initialSmartRouteDone) {
                    initialSmartRouteDone = true;
                    const winnersNow = Array.isArray(data.winners) ? data.winners : [];
                    const allFinalized = winnersNow.length > 0 && winnersNow.every(w => {
                        const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                        return !(c === '' || c === 'pending' || c === 'gamble_pending');
                    });

                    const myWinnerRowNow = winnersNow.find(w => isMyWinner(w)) || null;

                    const myChoiceNow = (myWinnerRowNow && myWinnerRowNow.choice !== undefined && myWinnerRowNow.choice !== null)
                        ? String(myWinnerRowNow.choice)
                        : '';
                    const iAmResolved = (myChoiceNow === 'original' || myChoiceNow === 'cashback' || myChoiceNow === 'double_won' || myChoiceNow === 'double_lost');

                    // =============================================================
                    // SMART REHYDRATION: Multi-phase animation synchronization.
                    // The server returns a granular animation_phase with scene_index,
                    // elapsed_seconds, and global_decision_active so refreshing
                    // clients land on the exact current animation frame.
                    // =============================================================
                    const animPhase = data.animation_phase || {
                        active: false, remaining_seconds: 0, elapsed_seconds: 0,
                        total_duration: 0, scene_index: -1, scene_count: 0,
                        global_decision_active: false
                    };
                    // CHECKPOINT: la fase de decisión también se infiere de
                    // `decision_active` (algún ganador ya tiene su ventana abierta)
                    // y del checkpoint canónico, cerrando el punto ciego donde el
                    // flag global aún no se había marcado.
                    const _decisionByCheckpoint = (data.decision_active === true)
                        || (data.checkpoint && (data.checkpoint.phase === 'DECISION' || data.checkpoint.phase === 'GAMBLE_ANIM' || data.checkpoint.phase === 'WINNERS_FINAL'));
                    const globalAnimStillActive = animPhase.active && animPhase.remaining_seconds > 0 && !_decisionByCheckpoint;
                    const forceDecision = (animPhase.global_decision_active === true) || _decisionByCheckpoint;

                    // Determine rehydration entry point from server scene_index
                    if (globalAnimStillActive && !forceDecision && animPhase.scene_index >= 0) {
                        // Mid-animation refresh: skip to the current winner scene
                        rehydrationStartIndex = Math.max(0, animPhase.scene_index);
                        console.log(`[REHYDRATE] Animation active. Resuming from winner #${rehydrationStartIndex + 1} (elapsed=${animPhase.elapsed_seconds}s, scene=${animPhase.scene_index}/${animPhase.scene_count})`);
                    } else if (globalAnimStillActive && !forceDecision && animPhase.scene_index < 0) {
                        // Still in base/intro phase — play full animation from start
                        rehydrationStartIndex = 0;
                        console.log('[REHYDRATE] Animation in intro phase. Playing from start.');
                    }

                    const shouldSkipMainReveal = (winnersNow.length > 0) && (isDecisionState || data.status === 'completed');
                    if (shouldSkipMainReveal && (!globalAnimStillActive || forceDecision)) {
                        mainAnimPlayed = true;
                        revealInProgress = false;
                        isMainAnimating = false;
                    }

                    if (winnersNow.length > 0) {
                        markResolvedGamblesAsPlayed(winnersNow);
                    }

                    // GAMBLE REHYDRATION: Check if the gamble queue still has
                    // frames to show. Consciente de la línea de tiempo acumulada
                    // de TODA la cola (no de la ventana per-ganador), para no
                    // saltar al estado final mientras quedan ganadores por
                    // reproducir en una cola de múltiples apuestas.
                    const anyGambleAnimActive = _gambleQueueStillActive(winnersNow);

                    if ((data.status === 'completed' || allFinalized || iAmResolved) && winnersNow.length > 0 && (!globalAnimStillActive || forceDecision) && !anyGambleAnimActive) {
                        gamblePlaybackInProgress = false;
                        gamblePlaybackCurrentKey = null;
                        gamblePlaybackCurrentWinner = null;

                        stopGamblePolling();
                        stopDecisionCountdown();

                        // FIX: Mark cinematic as already played when game is fully
                        // done on initial load.  Without this, the second poll
                        // (firstLoad=false) sees the key unset and erroneously
                        // triggers playCinematicTransition().
                        // Only set when truly finalized — if only iAmResolved but
                        // others are still pending, leave the key unset so the
                        // cinematic can still play when all decisions arrive.
                        if (data.status === 'completed' || allFinalized) {
                            cinematicSequencePlayedKey = 'cinematic_' + CONFIG.raffleId;
                        }
                        if (data.status === 'completed') {
                            // Game is fully over — stop the polling interval to
                            // eliminate any lingering timers that could cause
                            // state overwrites.  The current pollStatus() call
                            // will finish and render the final results below.
                            stopStatusPolling();
                        }

                        const mo = document.getElementById('mainAnimOverlay');
                        if (mo) mo.classList.add('hidden');
                        const go = document.getElementById('gambleOverlay');
                        if (go) go.classList.add('hidden');
                        const co = document.getElementById('cinematicOverlay');
                        if (co) co.classList.add('hidden');

                        isGambling = false;
                        activeGamblerName = null;
                        activeGamblerId = null;
                        activeGambleAnim = null;
                        activeGamblePredictionSide = null;

                        if (!summaryShown) {
                            summaryShown = true;
                        }
                    }

                    // GAMBLE REHYDRATION: If gamble animations are still within their
                    // server-side window, bypass ALL downstream rendering and immediately
                    // start the gamble playback system. This is the ONLY path that runs
                    // on firstLoad=true for active gamble animations.
                    if (anyGambleAnimActive) {
                        console.log('[GAMBLE-REHYDRATE] Active gamble animations on initial load. Triggering immediate playback.');
                        mainAnimPlayed = true;
                        // Prevent cinematic from firing mid-gamble on subsequent polls
                        cinematicSequencePlayedKey = 'cinematic_' + CONFIG.raffleId;
                        // Start the resolve animation NOW (bypasses !firstLoad guard)
                        maybeStartSequentialGamblePlayback(data);
                        updateUI(data);
                        firstLoad = false;
                        return;
                    }
                }

                // --- FASE A: REVELACIÓN PRINCIPAL ---
                // Si el sorteo terminó y hay ganadores que no hemos animado
                if (isDone && !mainAnimPlayed && !revealInProgress && data.winners.length > 0) {
                    revealInProgress = true;
                    state.participants = data.participants_list || [];

                    // SMART REHYDRATION: Use rehydrationStartIndex to skip already-revealed winners
                    const startIdx = Math.max(0, rehydrationStartIndex);
                    rehydrationStartIndex = -1; // Reset after use

                    if (CONFIG.mode === 'fast_batch' && data.winners.length >= 2) {
                        await playFastBatchAnimation([data.winners[0], data.winners[1]]);
                    } else {
                        await playSequentialReveal(data.winners, startIdx);
                    }

                    mainAnimPlayed = true;
                    revealInProgress = false;
                }

                // --- FASE B: HUD GLOBAL (DECISIONES) ---
                if (!cinematicSequenceInProgress) {
                    updateHUD(data.winners);
                }

                // --- FASE B2: PEER DECISION ALERTS ---
                if (!firstLoad && (data.status === 'waiting_for_winner' || data.status === 'waiting_for_decision' || data.status === 'waiting_for_winners') && !cinematicSequenceInProgress) {
                    _checkPeerDecisionAlerts(data.winners || []);
                }

                // --- FASE C: MI TURNO (SI SOY GANADOR) ---
                if (data.endgame && data.endgame.is_me) {
                    // Busco mi estado actual en la lista de ganadores
                    const myState = data.winners.find(w => w.position === data.endgame.my_position); // Necesitas que el backend diga tu posición o buscar por nombre
                    // Simplificación: Asumimos que el backend filtra o frontend busca por nombre/id si lo tienes
                    // Si estoy en 'thinking', mostrar modal. Si estoy 'ready_queue', mostrar aviso de espera.
                }

                // --- FASE D: ANIMACIÓN DE GAMBLE (COLA) ---
                if (isDecisionState) {
                    const winners = Array.isArray(data.winners) ? data.winners : [];
                    const myWinnerRow = winners.find(w => isMyWinner(w)) || null;

                    const hasPendingDecisions = winners.some(w => w && (w.choice === 'pending' || w.hud_state === 'thinking'));

                    if (!hasPendingDecisions && !isDecisionCountdownActive()) {
                        const refTargetWinner = winners.find(w => {
                            const t = (w && w.gamble_block_target !== undefined && w.gamble_block_target !== null) ? parseInt(w.gamble_block_target, 10) : 0;
                            return Number.isFinite(t) && t > 0;
                        }) || null;

                        if (refTargetWinner) {
                            const tgtNum = parseInt(refTargetWinner.gamble_block_target, 10);
                            if (Number.isFinite(tgtNum) && tgtNum > 0) {
                                const serverHash = refTargetWinner.gamble_server_hash ? String(refTargetWinner.gamble_server_hash) : getBatchServerHashFromUI();
                                startDecisionCountdown({
                                    key: `${CONFIG.raffleId}|${tgtNum}`,
                                    seconds: 7,
                                    targetBlock: tgtNum,
                                    serverHash
                                });
                            }
                        }
                    }

                    const hasPending = winners.some(w => w && (w.choice === 'pending' || w.hud_state === 'thinking'));
                    const hasActive = winners.some(w => w && (w.hud_state === 'spinning' || w.hud_state === 'ready_queue' || w.choice === 'gamble_pending'));
                    if (hasPending || hasActive || state.status !== 'completed') {
                        // Only nuke state when gamble race is NOT actively running.
                        // When hasActive is true, the block race UI is live — preserve
                        // the ticker and overlays to avoid flicker every poll cycle.
                        // Also skip nuke when awaitingGambleExecution is true — the UI was
                        // bootstrapped instantly from confirmGamble() and stale poll data
                        // hasn't caught up yet.
                        if (!hasActive && !awaitingGambleExecution && !cinematicSequenceInProgress) {
                            stopGamblePolling();
                            if (isGambling) {
                                hardResetGambleAnimationDOM();
                            }
                            const go = document.getElementById('gambleOverlay');
                            if (go) go.classList.add('hidden');
                            const mo = document.getElementById('mainAnimOverlay');
                            if (mo) mo.classList.add('hidden');
                        }
                    }

                    if (myWinnerRow) {
                        const myChoice = myWinnerRow.choice;
                        // Gamble resolution is now handled server-side by raffle_status.php (Trigger 3).
                        // No need to poll raffle_gamble.php — pollStatus() drives everything.
                        const shouldStopGamblePolling = (myChoice === 'original' || myChoice === 'cashback' || myChoice === 'double_won' || myChoice === 'double_lost');
                        if (shouldStopGamblePolling) {
                            if (gamblePollTimer) stopGamblePolling();
                        }
                    }

                    if (!suppressGambleOverlay) {
                        // --- CINEMATIC TRANSITION GATE ---
                        // If the cinematic is currently playing, freeze this branch
                        if (cinematicSequenceInProgress) {
                            updateUI(data);
                            firstLoad = false;
                            return;
                        }

                        // Detect the moment all decisions are in —
                        // trigger the cinematic recap BEFORE any post-decision UI.
                        // If someone gambled: Recap (5s) + DOBLE O NADA (5s) = 10s.
                        // If no one gambled: Recap only (5s), then normal completion.
                        if (!firstLoad && shouldPlayCinematic(winners)) {
                            const gambleDetected = winnersHaveGambler(winners);
                            playCinematicTransition(winners, gambleDetected).then(() => {
                                // Force an immediate poll after cinematic ends
                                pollStatus();
                            });
                            updateUI(data);
                            firstLoad = false;
                            return;
                        }

                        if (awaitingGambleExecution && betsClosedUntilMs && Date.now() < betsClosedUntilMs) {
                            updateUI(data);
                            firstLoad = false;
                            return;
                        }

                        if (!firstLoad && maybeStartSequentialGamblePlayback(data)) {
                            updateUI(data);
                            firstLoad = false;
                            return;
                        }

                        // --- MULTI-WINNER GUARD ---
                        // Block Race UI must NOT render until every winner has decided.
                        // Without this, one gamble_pending winner triggers the race
                        // overlay while others are still thinking.
                        const allWinnersDecided = winners.every(w => {
                            const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                            return c !== '' && c !== 'pending';
                        });

                        const refTargetWinner = winners.find(w => {
                            const t = (w && w.gamble_block_target !== undefined && w.gamble_block_target !== null) ? parseInt(w.gamble_block_target, 10) : 0;
                            return Number.isFinite(t) && t > 0;
                        }) || null;

                        if (refTargetWinner && allWinnersDecided) {
                            const tgtNum = parseInt(refTargetWinner.gamble_block_target, 10);
                            if (Number.isFinite(tgtNum) && tgtNum > 0) {
                                lastGambleTargetBlock = tgtNum;
                                if (tgtNum !== lastBetsClosedTargetBlock && !awaitingGambleExecution && (!betsClosedUntilMs || betsClosedUntilMs <= Date.now())) {
                                    hardResetGambleAnimationDOM();
                                    rebuildGambleAnimationDOM();
                                    isGambling = true;

                                    const serverHash = refTargetWinner.gamble_server_hash ? String(refTargetWinner.gamble_server_hash) : null;
                                    startBetsClosedBuffer({ targetBlock: tgtNum, serverHash, opponentLabel: null, bscBlockCurrent: data.bsc_block_current || _gambleBlockCurrent || null });

                                    // Render gamblers immediately so avatars appear the
                                    // instant the block-race UI is shown, instead of
                                    // waiting for the betsClosedBuffer to expire.
                                    {
                                        const gr = data.gamble_race || null;
                                        const immGamblers = gr ? gr.gamblers : winners
                                            .filter(w => w && w.choice === 'gamble_pending' && w.gamble_block_target && parseInt(w.gamble_block_target, 10) > 0)
                                            .map(w => ({ username: w.username || 'Jugador', avatar: w.avatar ? '../../assets/avatars/' + w.avatar : 'default.png' }));
                                        if (immGamblers && immGamblers.length > 0) {
                                            const normalized = immGamblers.map(g => ({
                                                username: g.username || 'Jugador',
                                                avatar: g.avatar && !g.avatar.includes('/') ? '../../assets/avatars/' + g.avatar : (g.avatar || '../../assets/avatars/default.png')
                                            }));
                                            _gambleRenderGamblers(normalized);
                                        }
                                    }

                                    updateUI(data);
                                    firstLoad = false;
                                    return;
                                }
                            }
                        }

                        const activeGambleRow = winners.find(w => w && (w.hud_state === 'spinning' || w.hud_state === 'ready_queue' || w.choice === 'gamble_pending'));
                        if (activeGambleRow && allWinnersDecided && !cinematicSequenceInProgress) {
                            isGambling = true;
                            activeGamblerName = null;
                            activeGamblerId = null;
                            activeGambleAnim = null;
                            activeGamblePredictionSide = null;

                            if (gambleInterval) {
                                clearInterval(gambleInterval);
                                gambleInterval = null;
                            }

                            const overlay = document.getElementById('gambleOverlay');
                            if (overlay) overlay.classList.remove('hidden');
                            const mo = document.getElementById('mainAnimOverlay');

                            if (mo) mo.classList.remove('hidden');
                            const wc = document.getElementById('animWheelContainer');
                            if (wc) wc.classList.add('hidden');
                            const sc = document.getElementById('animSlotContainer');
                            if (sc) sc.classList.add('hidden');
                            const scene = document.getElementById('sceneCoin');
                            if (scene) scene.style.display = 'none';

                            // Show block race UI (don't overwrite with text)
                            const raceEl = document.getElementById('gambleBlockRace');
                            if (raceEl) raceEl.classList.remove('hidden');

                            // --- UNIFIED SYNC: Initialize gamble block ticker from raffle_status data ---
                            // All clients (active player + spectators) use this path.
                            // Resolution is handled server-side; pollGambleStatus is no longer started.
                            {
                                // Source 1: gamble_race object (preferred, aggregated by backend)
                                const gr = data.gamble_race || null;
                                // Source 2: Fall back to individual winner data + global bsc_block_current
                                const spectatorTarget = gr ? parseInt(gr.target_block, 10)
                                    : (activeGambleRow.gamble_block_target ? parseInt(activeGambleRow.gamble_block_target, 10) : 0);
                                const spectatorCurrent = gr ? (gr.bsc_block_current ? parseInt(gr.bsc_block_current, 10) : 0)
                                    : (data.bsc_block_current ? parseInt(data.bsc_block_current, 10) : 0);

                                if (spectatorTarget > 0) {
                                    _gambleBlockTarget = spectatorTarget;
                                    const tgtEl = document.getElementById('gambleBlockTargetVal');
                                    if (tgtEl) tgtEl.innerText = '#' + spectatorTarget.toLocaleString();
                                }
                                if (spectatorCurrent > 0) {
                                    _gambleBlockCurrent = spectatorCurrent;
                                    if (_gambleBlockDisplay === 0 || _gambleBlockDisplay < spectatorCurrent) {
                                        _gambleBlockDisplay = spectatorCurrent;
                                    }
                                }
                                if (_gambleBlockTarget > 0 && _gambleBlockDisplay > 0) {
                                    _gambleUpdateDisplay();
                                    _gambleTickerStart();
                                }

                                // Populate gamble server hash sealed badge (spectator path)
                                const spectatorHashEl = document.getElementById('gambleSealHash');
                                if (spectatorHashEl) {
                                    const sHash = (gr && gr.gamble_server_hash) ? String(gr.gamble_server_hash)
                                        : (activeGambleRow.gamble_server_hash ? String(activeGambleRow.gamble_server_hash) : null);
                                    spectatorHashEl.innerText = sHash || '---';
                                }

                                // Render gamblers avatars from gamble_race or build from winners
                                const spectatorGamblers = gr ? gr.gamblers : winners
                                    .filter(w => w && w.choice === 'gamble_pending' && w.gamble_block_target && parseInt(w.gamble_block_target, 10) > 0)
                                    .map(w => ({ username: w.username || 'Jugador', avatar: w.avatar ? '../../assets/avatars/' + w.avatar : 'default.png' }));
                                if (spectatorGamblers && spectatorGamblers.length > 0) {
                                    const normalized = spectatorGamblers.map(g => ({
                                        username: g.username || 'Jugador',
                                        avatar: g.avatar && !g.avatar.includes('/') ? '../../assets/avatars/' + g.avatar : (g.avatar || '../../assets/avatars/default.png')
                                    }));
                                    _gambleRenderGamblers(normalized);
                                }
                            }

                            if (activeGamblerName) {
                                const ghostGamble = document.getElementById('ghostGamble');
                                if (ghostGamble) ghostGamble.classList.remove('hidden');
                            }
                        } else if (isGambling && !gamblePlaybackInProgress && !awaitingGambleExecution && !cinematicSequenceInProgress) {
                            const overlay = document.getElementById('gambleOverlay');
                            if (overlay) overlay.classList.add('hidden');
                            const mo = document.getElementById('mainAnimOverlay');
                            if (mo) mo.classList.add('hidden');
                            isGambling = false;
                        }
                    }
                }

                if (data.status === 'completed') {
                    // --- CINEMATIC GATE (completed) ---
                    if (cinematicSequenceInProgress) {
                        updateUI(data);
                        firstLoad = false;
                        return;
                    }

                    // Recap trigger for no-gamble case: status jumped directly
                    // to 'completed' (all chose prize/cashback). Show the recap
                    // before revealing the trophy room.
                    const completedWinners = Array.isArray(data.winners) ? data.winners : [];
                    if (!firstLoad && shouldPlayCinematic(completedWinners)) {
                        const gambleDetected = winnersHaveGambler(completedWinners);
                        playCinematicTransition(completedWinners, gambleDetected).then(() => {
                            pollStatus();
                        });
                        updateUI(data);
                        firstLoad = false;
                        return;
                    }

                    if (!firstLoad && maybeStartSequentialGamblePlayback(data)) {
                        updateUI(data);
                        firstLoad = false;
                        return;
                    }

                    const hasGamble = (data.winners || []).some(w => w && (w.choice === 'double_won' || w.choice === 'double_lost'));
                    // GAMBLE REHYDRATION: Do NOT mark summary as shown while gamble
                    // animations are still unplayed or actively playing. This prevents
                    // the results view from rendering before all animations finish.
                    const hasUnplayedGamble = gamblePlaybackInProgress || (data.winners || []).some(w => {
                        if (!w || (w.choice !== 'double_won' && w.choice !== 'double_lost')) return false;
                        const k = getGamblePlaybackKey(w);
                        return k && !gamblePlaybackPlayedKeys.has(k);
                    });
                    if (hasGamble && !summaryShown && !hasUnplayedGamble) {
                        summaryShown = true;
                    }
                }

                updateUI(data);

                firstLoad = false;

            } catch (e) { console.error("Poll Error:", e); }
            finally { pollLock = false; }
        }

        // === Locking Block Ticker Helpers ===
        function _lockUpdateDisplay() {
            const curEl = document.getElementById('lockingBlockCurrentVal');
            if (curEl && _lockBlockDisplay) {
                curEl.innerText = '#' + _lockBlockDisplay.toLocaleString();
                // Pulse animation on number change
                curEl.closest('.locking-race-number')?.classList.remove('locking-race-tick');
                void curEl.closest('.locking-race-number')?.offsetWidth; // reflow
                curEl.closest('.locking-race-number')?.classList.add('locking-race-tick');
            }
            // Progress bar
            if (_lockBlockTarget && _lockBlockDisplay) {
                const startBlock = _lockBlockTarget - _lockTotalBlocks;
                const elapsed = Math.max(0, _lockBlockDisplay - startBlock);
                const pct = Math.min(100, (elapsed / _lockTotalBlocks) * 100);
                const fillEl = document.getElementById('lockingProgressFill');
                const glowEl = document.getElementById('lockingProgressGlow');
                const leftEl = document.getElementById('lockingBlocksLeft');
                if (fillEl) fillEl.style.width = pct + '%';
                if (glowEl) glowEl.style.left = pct + '%';
                const remaining = Math.max(0, _lockBlockTarget - _lockBlockDisplay);
                if (leftEl) leftEl.innerText = remaining + (remaining === 1 ? ' bloque restante' : ' bloques restantes');
            }
        }

        // Contador + lista discreta de tickets expulsados en la sala de bloqueo.
        // Fuente: expelled_list (nombre + ticket) o, en su defecto, los tickets
        // anulados del snapshot de auditoría.
        let _lockExpelledKey = '';
        function _lockRenderExpelled(data) {
            const box = document.getElementById('lockingExpelled');
            if (!box) return;
            const list = Array.isArray(data.expelled_list) ? data.expelled_list.filter(Boolean) : [];
            const auditTickets = (data.audit && Array.isArray(data.audit.expelled_tickets))
                ? data.audit.expelled_tickets.map(Number).filter(Number.isFinite) : [];
            const items = list.length > 0
                ? list.map(p => ({ ticket: parseInt(p.ticket, 10), name: p.username || p.public_display_name || '' }))
                : auditTickets.map(t => ({ ticket: t, name: '' }));
            items.sort((a, b) => a.ticket - b.ticket);

            const key = JSON.stringify(items);
            if (key === _lockExpelledKey) return;
            _lockExpelledKey = key;

            if (items.length === 0) { box.classList.add('hidden'); return; }
            box.classList.remove('hidden');

            const countEl = document.getElementById('lockingExpelledCount');
            const labelEl = document.getElementById('lockingExpelledLabel');
            const chipsEl = document.getElementById('lockingExpelledChips');
            if (countEl) countEl.innerText = items.length;
            if (labelEl) labelEl.innerText = items.length === 1 ? 'ticket expulsado' : 'tickets expulsados';
            if (chipsEl) {
                const MAX_CHIPS = 12;
                const shown = items.slice(0, MAX_CHIPS);
                let html = shown.map(it =>
                    `<span class="locking-expelled-chip" title="Ticket anulado por revisión">#${Number.isFinite(it.ticket) ? it.ticket : '?'}` +
                    (it.name ? ` · ${escapeHtml(it.name)}` : '') + `</span>`
                ).join('');
                if (items.length > MAX_CHIPS) {
                    html += `<span class="locking-expelled-chip locking-expelled-chip--more">+${items.length - MAX_CHIPS} más</span>`;
                }
                chipsEl.innerHTML = html;
            }
        }

        function syncParticipantsSidebarVisibility() {
            const shouldShowSidebar = state.status === 'open';
            const sidebar = document.querySelector('.participants-sidebar');
            const mainContainer = document.querySelector('.main-container');

            if (sidebar) {
                sidebar.style.display = shouldShowSidebar ? '' : 'none';
            }

            if (mainContainer) {
                mainContainer.style.paddingRight = shouldShowSidebar ? '' : '0';
            }
        }

        function updateUI(data) {
            _lastStatusData = data;
            syncParticipantsSidebarVisibility();

            const privateBadge = document.getElementById('sfPrivateBadge');
            if (privateBadge && typeof data.is_private === 'boolean') privateBadge.hidden = !data.is_private;

            // HASH en Footer y Modal
            const transparencyServerHash = (data.transparency && data.transparency.server_seed_hash) ? data.transparency.server_seed_hash : null;
            const rawHash = transparencyServerHash || data.server_seed_hash || data.server_hash || 'Esperando inicio...';
            const fmHash = document.getElementById('fmServerHash'); if (fmHash) fmHash.innerText = rawHash;
            const footerHash = document.getElementById('serverHashValue'); if (footerHash) footerHash.innerText = rawHash;
            const secHash = document.getElementById('securityHashValue'); if (secHash) secHash.innerText = rawHash;
            state.winners = Array.isArray(data.winners) ? data.winners : [];

            // Modal Auditoría
            const navLabel = document.getElementById('navFairnessLabel');
            if (navLabel) {
                navLabel.innerText = (state.status === 'completed') ? 'Auditoría de Justicia' : 'Provably Fair';
            }

            const revealedSection = document.getElementById('fmRevealedSection');
            if (revealedSection) {
                if (state.status === 'completed' && data.transparency) {
                    revealedSection.classList.remove('hidden');
                    const tr = data.transparency;
                    const fmSecret = document.getElementById('fmServerSecret');
                    if (fmSecret && tr.server_seed_secret) fmSecret.innerText = tr.server_seed_secret;
                    const fmMainHash = document.getElementById('fmMainBlockHash');
                    if (fmMainHash && tr.bsc_block_hash) fmMainHash.innerText = tr.bsc_block_hash;
                    const blockNum = tr.bsc_block_number || tr.bsc_block_target || null;
                    const fmBlockNum = document.getElementById('fmBlockNumber');
                    if (fmBlockNum && blockNum) fmBlockNum.innerText = '#' + Number(blockNum).toLocaleString();
                    const fmLink = document.getElementById('fmBlockLink');
                    if (fmLink && blockNum) fmLink.href = 'https://bscscan.com/block/' + blockNum;
                } else {
                    revealedSection.classList.add('hidden');
                }
            }
            // Fecha del sorteo
            const fmFechaSection = document.getElementById('fmFechaSection');
            if (fmFechaSection) {
                const fechaRaw = (data.end_time || data.created_at || (data.transparency && data.transparency.created_at) || null);
                if (fechaRaw) {
                    fmFechaSection.classList.remove('hidden');
                    const fmFecha = document.getElementById('fmFecha');
                    if (fmFecha) {
                        const d = new Date(fechaRaw);
                        fmFecha.innerText = !isNaN(d.getTime()) ? d.toLocaleString('es-PE') : fechaRaw;
                    }
                } else {
                    fmFechaSection.classList.add('hidden');
                }
            }

            if (state.status === 'completed' && gamblePlaybackInProgress) {
                return;
            }

            if (state.status === 'completed' && trophyRoomRendered) {
                return;
            }

            // Vistas
            ['viewTimer', 'viewLocking', 'viewProcessing', 'viewWinner', 'viewResults'].forEach(v => {
                const el = document.getElementById(v);
                if (el) el.classList.add('hidden');
            });

            // P5: en cuanto el sorteo deja de estar "open" (contador a cero →
            // bloqueo/animación), cerramos el modal de pre-elección si seguía
            // abierto, para no tapar el inicio de la animación en vivo.
            if (state.status !== 'open') _sfClosePreChoiceIfOpen();

            // Stop smooth ticks for inactive states
            if (state.status !== 'open') _wrStop();
            const _isDecision = (state.status === 'waiting_for_winner' || state.status === 'waiting_for_decision' || state.status === 'waiting_for_winners');
            if (!_isDecision) _hudStop();

            if (state.status === 'open') {
                document.body.classList.remove('game-active');
                const vt = document.getElementById('viewTimer');
                if (vt) vt.classList.remove('hidden');
                _wrSyncDeadline(data.time_left_seconds);

                // Hide legacy pot/cost badges (price shown on join button instead)
                const potCtr = document.getElementById('potContainer');
                if (potCtr) potCtr.style.display = 'none';

                // Aforo badge (participant limit indicator)

                const _aforoBadge = document.getElementById('aforoPill');
                if (_aforoBadge) {
                    if (data.slots_total > 0) {
                        _aforoBadge.style.display = 'inline-flex';
                        document.getElementById('aforoCount').innerText = data.participants;
                        document.getElementById('aforoTotal').innerText = data.slots_total;
                        const _fillPct = (data.participants / data.slots_total) * 100;
                        _aforoBadge.classList.remove('aforo-badge--hot', 'aforo-badge--full');
                        if (_fillPct >= 100) _aforoBadge.classList.add('aforo-badge--full');
                        else if (_fillPct >= 80) _aforoBadge.classList.add('aforo-badge--hot');
                    } else {
                        _aforoBadge.style.display = 'none';
                    }
                }
            }

            else if (state.status === 'locking') {
                const vl = document.getElementById('viewLocking');
                if (vl) vl.classList.remove('hidden');

                // Cinema mode: hide sidebar, remove right padding, center content
                document.body.classList.add('game-active');

                // Sync server values
                if (data.bsc_block_target) _lockBlockTarget = data.bsc_block_target;
                if (data.bsc_block_current) {
                    _lockBlockCurrent = data.bsc_block_current;
                    _lockBlockDisplay = data.bsc_block_current;
                }

                // Update DOM — target (static)
                const tgtEl = document.getElementById('lockingBlockTargetVal');
                if (tgtEl && _lockBlockTarget) tgtEl.innerText = '#' + _lockBlockTarget.toLocaleString();

                // Update DOM — current (advances only with each poll, no interpolation)
                _lockUpdateDisplay();

                // Server seed hash
                const seedEl = document.getElementById('lockingSeedHash');
                if (seedEl) seedEl.innerText = data.server_seed_hash || '---';

                // Participant count
                const partEl = document.getElementById('lockingParticipants');
                if (partEl) partEl.innerText = data.participants || 0;

                _lockRenderExpelled(data);
            }

            else if (state.status === 'waiting_for_winner' || state.status === 'waiting_for_decision' || state.status === 'waiting_for_winners') {
                // Si soy el ganador, SIEMPRE veo mi modal
                const myWinnerRow = (data.winners || []).find(w => isMyWinner(w)) || null;

                if (myWinnerRow) {
                    // LIFECYCLE FIX: Hide celebration immediately when choice is made
                    // The celebration should ONLY appear during playSequentialReveal (4-second burst)
                    // Once a winner makes ANY choice, the celebration must be hidden
                    const vw = document.getElementById('viewWinner');
                    if (myWinnerRow.choice && myWinnerRow.choice !== 'pending') {
                        // Winner has made a decision - ensure celebration is hidden
                        if (vw) vw.classList.add('hidden');
                        clearWinnerParticles();
                    }
                    // Note: We do NOT show viewWinner here - it's only shown in playSequentialReveal

                    // AFK MODAL AUTO-CLOSE FIX: If server advanced state past game selection phase,
                    // automatically close the decision modal (user went AFK and server made default choice)
                    const dm = document.getElementById('decisionModal');
                    if (dm && !dm.classList.contains('hidden') && myWinnerRow.choice !== 'pending') {
                        // Modal is open but choice is no longer pending - server made a decision
                        dm.classList.add('hidden');
                        if (decisionTimerInterval) {
                            clearInterval(decisionTimerInterval);
                            decisionTimerInterval = null;
                        }
                    }

                    if (mainAnimPlayed && myWinnerRow.choice === 'pending') {
                        let ttd = (myWinnerRow.time_to_decide !== undefined && myWinnerRow.time_to_decide !== null)
                            ? parseInt(myWinnerRow.time_to_decide, 10)
                            : (data.endgame ? parseInt(data.endgame.time_to_decide || 0, 10) : 0);

                        const opts = (data.endgame && data.endgame.options) ? data.endgame.options : {
                            allow_cashback: false,
                            cashback_percent: 0,
                            allow_double: !!data.allow_double_or_nothing,
                            allow_winner_animation_choice: data.allow_winner_animation_choice || 0
                        };
                        // Sin alternativas al premio original no hay decisión que
                        // tomar: el servidor lo reclama solo al arrancar la fase
                        // (rr_claim_original_prize). No se abre ningún modal.
                        const rules = _sfChoiceRules(data, opts);
                        if (ttd > 0 && (rules.cashback || rules.gamble)) {
                            showDecisionModal({
                                time_to_decide: ttd,
                                decision_timeout: parseInt(data.decision_timeout || 0, 10),
                                options: opts,
                                pre_apply_seconds: (myWinnerRow.pre_apply_seconds !== undefined) ? myWinnerRow.pre_apply_seconds : null,
                                pre_apply_cancelled: (myWinnerRow.pre_apply_cancelled === true || myWinnerRow.pre_apply_cancelled === 1)
                            });
                        }
                    }

                    const activePos = (data.endgame && data.endgame.active_position !== undefined && data.endgame.active_position !== null)
                        ? parseInt(data.endgame.active_position, 10)
                        : null;
                    const myPos = (myWinnerRow.position !== undefined && myWinnerRow.position !== null)
                        ? parseInt(myWinnerRow.position, 10)
                        : null;

                    if (myWinnerRow.choice === 'gamble_pending' || myWinnerRow.hud_state === 'ready_queue') {
                        document.getElementById('winActionDisplay').innerText = `Decisión registrada. Esperando ejecución (#${myPos || '?'})...`;
                    } else if (myWinnerRow.choice === 'double_won' || myWinnerRow.choice === 'double_lost') {
                        document.getElementById('winActionDisplay').innerText = (myWinnerRow.choice === 'double_won')
                            ? '¡Ganaste el doble!'
                            : 'Perdiste en Doble o Nada (&#128128;)';
                    } else if (activePos !== null && myPos !== null && myPos > activePos) {
                        document.getElementById('winActionDisplay').innerText = `¡Ganaste! Eres el #${myPos}. Esperando al ganador #${activePos}...`;
                    } else {
                        document.getElementById('winActionDisplay').innerText = '¡Ganaste! Esperando tu turno...';
                    }

                    // Vitrina Schema 2: si reclamó el premio original y su puesto
                    // tiene un pool, debe elegir su ítem (decisión definitiva).
                    _sfHandlePoolSelection(myWinnerRow);
                }
            }
            else if (state.status === 'completed') {
                const mo = document.getElementById('mainAnimOverlay');
                if (mo) mo.classList.add('hidden');
                const go = document.getElementById('gambleOverlay');
                if (go) go.classList.add('hidden');

                const hc = document.getElementById('hudContainer');
                if (hc) hc.classList.add('hidden');
                const _fl1 = document.getElementById('dbFloatingLayer');
                if (_fl1) _fl1.classList.add('hidden');

                document.body.classList.remove('game-active');

                const vr = document.getElementById('viewResults');
                if (vr) vr.classList.remove('hidden');
                const firstTrophyRender = !trophyRoomRendered;
                renderTrophyRoomWinners(data.winners);
                trophyRoomRendered = true;

                // Vitrina Schema 2 también en sorteo completado: el ganador puede
                // entrar después a elegir su ítem del pool.
                const _myPoolWinner = (data.winners || []).find(w => isMyWinner(w)) || null;
                if (_myPoolWinner) _sfHandlePoolSelection(_myPoolWinner);
                if (firstTrophyRender) AdminAudio.play('summary_screen');
                stopStatusPolling();

                // Near-miss: si participé y no gané, mostrar qué tan cerca estuve.
                // Retraso de 3s tras terminar el sorteo para no interrumpir el
                // flujo visual del cierre (el modal aparece de forma natural).
                if (data.near_miss && !nearMissShown) {
                    nearMissShown = true;
                    setTimeout(() => showNearMissModal(data.near_miss), 3000);
                }

                const btnVerify = document.getElementById('btnVerifyResults');
                if (btnVerify) btnVerify.onclick = openMainAuditModal;
            }
            else {
                const vp = document.getElementById('viewProcessing');
                if (vp) vp.classList.remove('hidden');
            }

            if (data.winners.length > 0) {
                const myWinnerRow = (data.winners || []).find(w => isMyWinner(w)) || null;
                const w = myWinnerRow || data.winners[0];
                document.getElementById('winnerNameDisplay').innerText = w.username;
                document.getElementById('winNameModal').innerText = w.username;
                document.getElementById('winAvatarMain').src = `../../assets/avatars/${w.avatar}`;
                document.getElementById('winAvatarModal').src = `../../assets/avatars/${w.avatar}`;
            }

            // BOTÓN FINAL
            const btnLegacy = document.getElementById('btnAction');
            const btnModern = document.getElementById('join-btn');
            const btn = btnLegacy || btnModern;

            if (btn) {
                _sfRenderWelcomeHint(btn, data);
                // El modificador "cambiar elección" se reasigna en cada poll; lo
                // limpiamos siempre y sólo lo volvemos a poner en su rama.
                btn.classList.remove('join-button--change', 'join-button--expelled');
                if (state.status === 'completed') {
                    btn.disabled = true;
                    if (btnLegacy) {
                        btn.className = "w-full py-4 rounded-xl font-bold text-lg bg-gray-800 text-gray-500 cursor-not-allowed border border-gray-700";
                    }
                    btn.innerHTML = 'SORTEO FINALIZADO';
                } else {
                    if (!data.is_logged_in) {
                        btn.disabled = false;
                        if (btnLegacy) {
                            btn.className = "w-full py-4 rounded-xl font-bold text-lg shadow-lg bg-gray-700 text-gray-300 border border-gray-600";
                        }
                        btn.innerHTML = '<i class="fas fa-lock mr-2"></i> INICIAR SESIÓN';
                        btn.onclick = () => window.location.href = '/login';
                    } else if (_sfIsExpelledFromRaffle(data)) {
                        // Expulsado o baneado: el botón queda inerte y nunca vuelve
                        // a ofrecer la entrada ni abre el flujo de inscripción.
                        btn.disabled = true;
                        btn.onclick = null;
                        if (btnLegacy) {
                            btn.className = "w-full py-4 rounded-xl font-bold text-lg bg-gray-800 text-gray-500 cursor-not-allowed border border-gray-700 opacity-60 pointer-events-none";
                        } else {
                            btn.classList.add('join-button--expelled');
                        }
                        btn.innerHTML = 'EXPULSADO';
                    } else if (state.status === 'open') {
                        document.body.classList.remove('game-active');
                        const myId = CONFIG.me && CONFIG.me.userId ? parseInt(CONFIG.me.userId, 10) : 0;
                        const alreadyIn = myId > 0 && Array.isArray(data.participants_list) && data.participants_list.some(p => p && parseInt(p.user_id, 10) === myId);
                        if (alreadyIn) {
                            // P4: si hay algo que decidir (alternativas reales o
                            // un ítem de vitrina por elegir) y el sorteo sigue
                            // abierto, en vez de un botón muerto "Ya estás
                            // participando" mostramos un CTA clicable que abre la
                            // pre-elección. Con una sola opción y sin detalle el
                            // modal no existe, así que tampoco el CTA.
                            const space = _sfPreChoiceSpace(data);
                            if (_sfPreChoiceAvailable(data, space)) {
                                const label = data.my_pre_choice ? space.cta.change : space.cta.fresh;
                                const labelShort = data.my_pre_choice ? space.cta.change : (space.cta.freshShort || space.cta.fresh);
                                btn.disabled = false;
                                if (btnLegacy) {
                                    btn.className = "w-full py-4 rounded-xl font-bold text-lg shadow-lg bg-gradient-to-r from-violet-600 to-fuchsia-500 text-white";
                                } else {
                                    btn.classList.add('join-button--change');
                                }
                                btn.innerHTML = '<span class="join-button__inner"><i class="fas fa-exchange-alt" aria-hidden="true"></i>'
                                    + '<span class="join-button__label join-button__label--full">' + escapeHtml(label) + '</span>'
                                    + '<span class="join-button__label join-button__label--short">' + escapeHtml(labelShort) + '</span></span>';
                                btn.onclick = () => _sfOpenPreChoice(_lastStatusData || data || {}, { opener: btn });
                            } else {
                                btn.disabled = true;
                                btn.onclick = null;
                                if (btnLegacy) {
                                    btn.className = "w-full py-4 rounded-xl font-bold text-lg bg-gray-800 text-gray-500 cursor-not-allowed border border-gray-700";
                                }
                                btn.innerHTML = 'YA ESTÁS PARTICIPANDO';
                            }
                        } else {
                            btn.disabled = false;
                            if (btnLegacy) {
                                btn.className = "w-full py-4 rounded-xl font-bold text-lg shadow-lg bg-gradient-to-r from-yellow-600 to-yellow-500 text-white";
                            }
                            btn.innerHTML = data.economics.entry_cost > 0 ? `ENTRAR (${data.economics.entry_cost})` : '¡ENTRAR GRATIS!';
                            btn.onclick = joinRaffle;
                        }
                    } else {
                        btn.disabled = true;
                        if (btnLegacy) {
                            btn.className = "w-full py-4 rounded-xl font-bold text-lg bg-gray-800 text-gray-500 cursor-not-allowed";
                        }
                        btn.innerHTML = 'EN CURSO...';
                    }
                }
            }
            renderParticipants(data.participants_list, data.expelled_list);
            document.getElementById('partCountDesk').innerText = data.participants;
            document.getElementById('partCountMob').innerText = data.participants;
        }

        // Usuario actual retirado de este sorteo (ticket expulsado en revisión,
        // presente en expelled_list) o baneado de la plataforma.
        function _sfIsExpelledFromRaffle(data) {
            if (!data || !data.is_logged_in) return false;
            if (data.my_banned) return true;
            if (data.my_review_status === 'expelled') return true;
            const myId = CONFIG.me && CONFIG.me.userId ? parseInt(CONFIG.me.userId, 10) : 0;
            if (myId <= 0 || !Array.isArray(data.expelled_list)) return false;
            return data.expelled_list.some(p => p && parseInt(p.user_id, 10) === myId);
        }

        // --- ANIMACIONES ---

        function playMainAnimation(winner, mode, sceneIndex = 0) {
            return new Promise(resolve => {
                console.log(`[ANIM] playMainAnimation called. winner=${winner?.username}, CONFIG.anims.main=${CONFIG.anims.main}`);
                hardResetMainAnimationDOM();

                // --- CINEMA MODE: Full-screen immersive experience ---
                document.body.classList.add('game-active');
                document.getElementById('viewTimer').classList.add('hidden');
                // ----------------------------------------------------

                isMainAnimating = true;
                const overlay = document.getElementById('mainAnimOverlay');
                const slot2 = document.getElementById('fastBatchSlot2');
                if (slot2) slot2.classList.add('hidden');
                overlay.classList.remove('hidden');

                document.getElementById('animTitle').innerText = '¡Sorteando!';

                const done = () => { isMainAnimating = false; resolve(); };

                if (CONFIG.anims.main === 'roulette') {
                    console.log('[ANIM] Taking ROULETTE path');
                    runWheelAnimation(winner, 'main', done, null, sceneIndex);
                } else {
                    console.log('[ANIM] Taking SLOT path');
                    document.getElementById('animSlotContainer').classList.remove('hidden');
                    document.getElementById('animWheelContainer').classList.add('hidden');

                    setTimeout(() => {
                        runSlotAnimation(winner, 'main', done, null, sceneIndex);
                    }, 50);
                }
            });
        }

        function playFastBatchAnimation(winners) {
            return new Promise(resolve => {

                // --- CINEMA MODE: Full-screen immersive experience ---
                document.body.classList.add('game-active');
                document.getElementById('viewTimer').classList.add('hidden');
                // ----------------------------------------------------

                isMainAnimating = true;
                const overlay = document.getElementById('mainAnimOverlay');
                overlay.classList.remove('hidden');
                document.getElementById('animTitle').innerText = '¡Sorteando!';

                document.getElementById('animWheelContainer').classList.add('hidden');
                document.getElementById('animSlotContainer').classList.remove('hidden');

                const slot2Wrap = document.getElementById('fastBatchSlot2');
                if (slot2Wrap) slot2Wrap.classList.remove('hidden');

                const w1 = winners && winners[0] ? winners[0] : null;
                const w2 = winners && winners[1] ? winners[1] : null;
                const pool = state.participants;

                let doneCount = 0;
                const onDone = () => {
                    doneCount += 1;
                    if (doneCount >= 2) {
                        document.getElementById('mainAnimOverlay').classList.add('hidden');
                        isMainAnimating = false;
                        resolve();
                    }
                };

                setTimeout(() => {
                    runMainSlotAnimationOnTrack('reel-strip', w1 || { username: '...' }, pool, onDone);
                    runMainSlotAnimationOnTrack('reel-strip-2', w2 || { username: '...' }, pool, onDone);
                }, 50);
            });
        }

        function runMainSlotAnimationOnTrack(trackId, winner, pool, callback) {
            const track = document.getElementById(trackId);
            if (!track) {
                if (callback) callback();
                return;
            }

            // Build HTML
            const rowsToScroll = 160;
            const winPosition = 140;

            let winnerIdx = pool.findIndex(p => p.username === winner.username);
            if (winnerIdx === -1) winnerIdx = 0;

            let startOffset = winnerIdx - (winPosition % pool.length);
            if (startOffset < 0) startOffset += pool.length;

            let renderPool = pool;

            track.style.transition = 'none';
            track.style.transform = 'translate3d(0, 0px, 0)';
            track.offsetHeight;

            let html = '';

            for (let i = 0; i < rowsToScroll; i++) {
                // Selección matemática: Nunca repite hasta dar la vuelta completa
                let pIndex = (startOffset + i) % renderPool.length;
                let p = renderPool[pIndex];

                // Renderizado
                const username = escapeHtml(p && (p.username || p.public_display_name) ? (p.username || p.public_display_name) : 'Anónimo');
                const avatar = (p && p.avatar) ? String(p.avatar) : '';
                const avatarSrc = avatar
                    ? (avatar.startsWith('http') ? avatar : `../../assets/avatars/${escapeHtml(avatar)}`)
                    : '';
                const iconHtml = avatar
                    ? `<img src="${avatarSrc}" alt="${username}" loading="lazy">`
                    : `
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                </svg>`;

                html += `
            <div class="slot-reel-item participant-card">
                <div class="slot-reel-item-inner">
                    <div class="slot-reel-item-icon">${iconHtml}</div>
                    <span class="slot-reel-item-name">${username}</span>
                </div>
            </div>`;
            }

            track.innerHTML = html;
            _freezeAvatarsIn(track); // congela avatares animados durante el giro

            // --- CÁLCULO DE ALTURA EXACTO ---
            // Altura fila (CSS) = 34px + 2px borde = ~36px
            const rowHeight = (() => {
                const rowEl = track.querySelector('.slot-reel-item');
                const h = rowEl ? rowEl.offsetHeight : 0;
                return (h && Number.isFinite(h)) ? h : 100;
            })();
            const viewportEl = (trackId === 'reel-strip-2')
                ? document.getElementById('reel-viewport-2')
                : document.getElementById('reel-viewport');
            const indicatorY = viewportEl ? (viewportEl.clientHeight / 2) : 350;
            const centerOffset = indicatorY - (rowHeight / 2);

            if (callback) {
                const viewportH = viewportEl ? viewportEl.clientHeight : 700;
                const targetPixel = (winPosition * rowHeight) - (viewportH / 2) + (rowHeight / 2);
                // PUNTO 8.bis: desvío DETERMINISTA dentro de la celda (tensión de
                // "ganar por un pelito") idéntico para todos los espectadores.
                const stopSeed = (winner && winner.username ? winner.username : 'w')
                    + ':' + (CONFIG.raffleId || '0') + ':' + trackId;
                const finalTargetPos = targetPixel + _sfCellStopOffset(stopSeed, rowHeight);

                SlotClickSynth.ensureContext();

                const centerLineEl = (trackId === 'reel-strip-2')
                    ? document.getElementById('center-line-2')
                    : document.getElementById('center-line');

                track.offsetHeight;

                // Seek anclado al servidor (escena 0 en el batch simultáneo).
                const anchorElapsedMs = _sfSpinElapsedMs(0);
                setTimeout(() => {
                    _rafSpinReel({
                        track,
                        startOffset:  0,
                        targetOffset: finalTargetPos,
                        durationMs:   LEGACY_SPIN_DURATION,
                        rowHeight,
                        viewportHalf: indicatorY,
                        centerLineEl,
                        linear: false,
                        anchorElapsedMs: (anchorElapsedMs != null ? anchorElapsedMs : 0),
                        onFinish: () => {
                            setTimeout(() => {
                                document.getElementById('mainAnimOverlay').classList.add('hidden');
                                callback();
                            }, 500);
                        }
                    });
                }, 50);
            }
        }

        // ================================================================
        //  PREMIUM CANVAS WHEEL — anti-aliased, elegant palette
        // ================================================================

        /** Vibrant trivia game-show palette — 16 bright, cheerful, distinct hues */
        const WHEEL_PALETTE = [
            '#FF6B6B', // cherry red
            '#4ECDC4', // vivid teal
            '#FFD93D', // sunny yellow
            '#6C5CE7', // electric purple
            '#FF8A5C', // tangerine
            '#2ED573', // emerald green
            '#54A0FF', // sky blue
            '#FF6EC7', // hot pink
            '#1DD1A1', // mint pop
            '#F368E0', // orchid
            '#48DBFB', // cyan splash
            '#FF9F43', // mango
            '#A29BFE', // soft violet
            '#FECA57', // gold
            '#00D2D3', // turquoise
            '#EE5A24', // bold orange
        ];

        /** Image cache for wheel avatars */
        const _wheelImgCache = new Map();
        function _preloadWheelAvatar(src) {
            if (_wheelImgCache.has(src)) return _wheelImgCache.get(src);
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.src = src;
            _wheelImgCache.set(src, img);
            return img;
        }

        // ================================================================
        //  AVATAR FREEZE — congela avatares animados (webp/gif) a su primer
        //  fotograma para evitar lag en móviles durante slots/ruleta.
        //  Se cachea por fuente; sólo se congela una vez por avatar.
        // ================================================================
        const _avatarFreezeCache = new Map(); // src -> { ready, canvas, url, listeners }
        function _getFrozenAvatar(src) {
            if (!src) return null;
            let e = _avatarFreezeCache.get(src);
            if (e) return e;
            e = { ready: false, canvas: null, url: '', listeners: [] };
            _avatarFreezeCache.set(src, e);
            const img = new Image();
            img.crossOrigin = 'anonymous';
            const finish = () => {
                const fns = e.listeners; e.listeners = [];
                fns.forEach(fn => { try { fn(e); } catch (_) {} });
            };
            img.onload = () => {
                try {
                    const w = img.naturalWidth || 96;
                    const h = img.naturalHeight || 96;
                    const c = document.createElement('canvas');
                    c.width = w; c.height = h;
                    c.getContext('2d').drawImage(img, 0, 0); // captura sólo el 1er frame
                    e.canvas = c;
                    e.url = c.toDataURL('image/png'); // estático, sin animación
                } catch (err) {
                    // canvas "tainted" (cross-origin sin CORS): usar la fuente viva
                    e.canvas = null; e.url = src;
                }
                e.ready = true; finish();
            };
            img.onerror = () => { e.ready = true; e.canvas = null; e.url = src; finish(); };
            img.src = src;
            return e;
        }

        /** Reemplaza los <img> de avatar dentro de un riel por su 1er frame congelado. */
        function _freezeAvatarsIn(rootEl) {
            if (!rootEl) return;
            rootEl.querySelectorAll('.slot-reel-item-icon img').forEach(imgEl => {
                const s = imgEl.getAttribute('src');
                if (!s) return;
                const fe = _getFrozenAvatar(s);
                const apply = e => { if (e && e.url && imgEl.src !== e.url) imgEl.src = e.url; };
                if (fe.ready) apply(fe); else fe.listeners.push(apply);
            });
        }

        /** State for resize-driven redraws */
        let _lastWheelPool = null;
        let _lastWheelMode = null;
        let _wheelResizeDebounce = null;

        function setupWheel(pool, mode) {
            // Store pool/mode for resize redraws
            _lastWheelPool = [...pool];
            _lastWheelMode = mode;

            const wheelEl = document.getElementById('mainWheel');
            wheelEl.innerHTML = '';
            wheelEl.style.background = 'none';
            wheelEl.style.boxShadow = 'none';

            let renderPool = [...pool];
            const count = renderPool.length;
            const sliceDeg = 360 / count;
            const sliceRad = (2 * Math.PI) / count;
            // Modo casino (ruleta Rojo/Negro): las casillas traen su color fijo.
            const isCasino = renderPool.some(p => p && p.color);
            const dividerCol = isCasino ? '#D4AF37' : '#1a1a2e';

            // ── Responsive wheel sizing based on viewport ──
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const isMobile = vw < 768;

            let idealSize;
            if (isMobile) {
                idealSize = (mode === 'gamble')
                    ? Math.min(vw * 0.96, vh * 0.60)
                    : Math.min(vw * 0.96, vh * 0.55);
            } else {
                idealSize = Math.min((vw - 64) * 0.75, (vh - 80) * 0.82, 780);
            }
            idealSize = Math.max(240, Math.round(idealSize));

            // Apply size to wheel element and parent container
            wheelEl.style.width = idealSize + 'px';
            wheelEl.style.height = idealSize + 'px';
            const outerWrap = wheelEl.closest('.wheel-outer');
            if (outerWrap) {
                outerWrap.style.width = idealSize + 'px';
                outerWrap.style.height = idealSize + 'px';
                outerWrap.style.maxWidth = 'none';
                outerWrap.style.maxHeight = 'none';
            }

            const wheelSize = idealSize;
            const dpr = Math.min(window.devicePixelRatio || 1, 2);
            const canvasSize = wheelSize;
            const cx = canvasSize / 2;
            const cy = canvasSize / 2;
            const R = canvasSize / 2;

            // Create hi-DPI canvas
            let canvas = wheelEl.querySelector('canvas#wheelCanvas');
            if (!canvas) {
                canvas = document.createElement('canvas');
                canvas.id = 'wheelCanvas';
                canvas.style.width = '100%';
                canvas.style.height = '100%';
                canvas.style.borderRadius = '50%';
                canvas.style.display = 'block';
            }
            canvas.width = canvasSize * dpr;
            canvas.height = canvasSize * dpr;
            const ctxC = canvas.getContext('2d');
            ctxC.scale(dpr, dpr);
            ctxC.imageSmoothingEnabled = true;
            ctxC.imageSmoothingQuality = 'high';

            // ── Responsive border & ring thickness ──
            const borderWidth = isMobile
                ? Math.max(1.5, canvasSize * 0.004)
                : Math.max(2, canvasSize * 0.005);
            const outerRingWidth = isMobile
                ? Math.max(6, canvasSize * 0.016)
                : Math.max(10, canvasSize * 0.028);
            const innerR = R - outerRingWidth;
            const hubR = 0; // no center obstruction — slices meet at center point

            // ------- Draw outer premium ring -------
            ctxC.save();
            ctxC.beginPath();
            ctxC.arc(cx, cy, R, 0, 2 * Math.PI);
            const ringGrad = ctxC.createLinearGradient(cx - R, cy - R, cx + R, cy + R);
            if (isCasino) {
                // Aro de madera barnizada con vetas cálidas (mesa de casino).
                ringGrad.addColorStop(0, '#4A2C17');
                ringGrad.addColorStop(0.25, '#6B4226');
                ringGrad.addColorStop(0.5, '#8B5A2B');
                ringGrad.addColorStop(0.75, '#6B4226');
                ringGrad.addColorStop(1, '#3E2412');
            } else {
                ringGrad.addColorStop(0, '#2C3E50');
                ringGrad.addColorStop(0.3, '#34495E');
                ringGrad.addColorStop(0.5, '#4A6274');
                ringGrad.addColorStop(0.7, '#34495E');
                ringGrad.addColorStop(1, '#2C3E50');
            }
            ctxC.fillStyle = ringGrad;
            ctxC.fill();

            // Inner bevel highlight on the ring
            ctxC.beginPath();
            ctxC.arc(cx, cy, R - 1, 0, 2 * Math.PI);
            ctxC.strokeStyle = 'rgba(255,255,255,0.15)';
            ctxC.lineWidth = 1.5;
            ctxC.stroke();

            // Outer edge shadow
            ctxC.beginPath();
            ctxC.arc(cx, cy, R - 0.5, 0, 2 * Math.PI);
            ctxC.strokeStyle = 'rgba(0,0,0,0.3)';
            ctxC.lineWidth = 1;
            ctxC.stroke();

            // LED-style pegs on the ring
            const pegCount = Math.min(count * 2, 48);
            for (let i = 0; i < pegCount; i++) {
                const a = (i / pegCount) * 2 * Math.PI;
                const pr = R - outerRingWidth / 2;
                const px = cx + pr * Math.cos(a);
                const py = cy + pr * Math.sin(a);
                const pegR = Math.max(2.8, outerRingWidth * 0.2);

                // Glow behind peg
                ctxC.beginPath();
                ctxC.arc(px, py, pegR + 2, 0, 2 * Math.PI);
                ctxC.fillStyle = (i % 2 === 0) ? 'rgba(255,215,0,0.25)' : 'rgba(255,255,255,0.15)';
                ctxC.fill();

                // Peg body
                ctxC.beginPath();
                ctxC.arc(px, py, pegR, 0, 2 * Math.PI);
                const pegGrad = ctxC.createRadialGradient(px - pegR * 0.3, py - pegR * 0.3, 0, px, py, pegR);
                pegGrad.addColorStop(0, (i % 2 === 0) ? '#FFD700' : '#FFFFFF');
                pegGrad.addColorStop(1, (i % 2 === 0) ? '#DAA520' : '#C0C0C0');
                ctxC.fillStyle = pegGrad;
                ctxC.fill();
                ctxC.strokeStyle = 'rgba(0,0,0,0.25)';
                ctxC.lineWidth = 0.5;
                ctxC.stroke();
            }
            ctxC.restore();

            // ------- Draw slices -------
            ctxC.save();
            ctxC.beginPath();
            ctxC.arc(cx, cy, innerR, 0, 2 * Math.PI);
            ctxC.clip();

            for (let i = 0; i < count; i++) {
                const startA = (i * sliceRad) - Math.PI / 2;
                const endA = startA + sliceRad;
                const color = (renderPool[i] && renderPool[i].color) ? renderPool[i].color : WHEEL_PALETTE[i % WHEEL_PALETTE.length];

                // Fill the slice — extends fully to center
                ctxC.beginPath();
                ctxC.moveTo(cx, cy);
                ctxC.arc(cx, cy, innerR, startA, endA);
                ctxC.closePath();
                ctxC.fillStyle = color;
                ctxC.fill();

                // Bright highlight near the outer edge for depth
                const midA = startA + sliceRad / 2;
                const hlGrad = ctxC.createRadialGradient(cx, cy, innerR * 0.25, cx, cy, innerR);
                hlGrad.addColorStop(0, 'rgba(255,255,255,0.18)');
                hlGrad.addColorStop(0.6, 'rgba(255,255,255,0.04)');
                hlGrad.addColorStop(1, 'rgba(0,0,0,0.10)');
                ctxC.beginPath();
                ctxC.moveTo(cx, cy);
                ctxC.arc(cx, cy, innerR, startA, endA);
                ctxC.closePath();
                ctxC.fillStyle = hlGrad;
                ctxC.fill();
            }

            // Anti-aliased BLACK divider lines — from center to edge
            ctxC.lineCap = 'round';
            for (let i = 0; i < count; i++) {
                const a = (i * sliceRad) - Math.PI / 2;
                ctxC.beginPath();
                ctxC.moveTo(cx, cy);
                ctxC.lineTo(cx + innerR * Math.cos(a), cy + innerR * Math.sin(a));
                ctxC.strokeStyle = dividerCol;
                ctxC.lineWidth = borderWidth;
                ctxC.stroke();
            }

            // Crisp inner circle outline at the edge of slices
            ctxC.beginPath();
            ctxC.arc(cx, cy, innerR - 0.5, 0, 2 * Math.PI);
            ctxC.strokeStyle = dividerCol;
            ctxC.lineWidth = borderWidth * 0.8;
            ctxC.stroke();
            ctxC.restore();

            // ------- Draw text labels (smart orientation) -------
            // Tangential for few slices, radial for many — prevents collision
            const useRadialText = count > 6;
            const textR = useRadialText ? innerR * 0.52 : innerR * 0.58;

            // Dynamic font sizing based on participant count
            let fontSize;
            if (count <= 6) {
                fontSize = Math.max(11, Math.min(18, Math.round(canvasSize * 0.026)));
            } else if (count <= 12) {
                fontSize = Math.max(9, Math.min(15, Math.round(canvasSize * 0.022)));
            } else if (count <= 20) {
                fontSize = Math.max(8, Math.min(12, Math.round(canvasSize * 0.018)));
            } else {
                fontSize = Math.max(7, Math.min(10, Math.round(canvasSize * 0.014)));
            }

            // Max label characters based on count
            const maxChars = count <= 6 ? 12 : count <= 12 ? 10 : count <= 20 ? 8 : 6;

            ctxC.save();
            ctxC.textBaseline = 'middle';
            ctxC.font = `800 ${fontSize}px "Nunito", system-ui, sans-serif`;

            for (let i = 0; i < count; i++) {
                const midA = (i * sliceRad) + (sliceRad / 2) - Math.PI / 2;
                const p = renderPool[i];

                // Determine text color based on background
                const bgCol = (p && p.color) ? p.color : WHEEL_PALETTE[i % WHEEL_PALETTE.length];
                const lum = _colorLuminance(bgCol);
                const textCol = lum < 0.55 ? '#ffffff' : '#1a1a2e';

                ctxC.save();

                if (useRadialText) {
                    // Radial layout: text runs along the radius for max legibility
                    const tx = cx + textR * Math.cos(midA);
                    const ty = cy + textR * Math.sin(midA);
                    ctxC.translate(tx, ty);

                    // Flip text on left half so it's never upside-down
                    const normA = ((midA % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
                    const flip = normA > Math.PI / 2 && normA < 3 * Math.PI / 2;
                    ctxC.rotate(flip ? midA + Math.PI : midA);
                    ctxC.textAlign = 'center';
                } else {
                    // Tangential layout: text perpendicular to radius (spacious slices)
                    const tx = cx + textR * Math.cos(midA);
                    const ty = cy + textR * Math.sin(midA);
                    ctxC.translate(tx, ty);
                    ctxC.rotate(midA + Math.PI / 2);
                    ctxC.textAlign = 'center';
                }

                let label = '';
                const isCasinoSlice = (p.avatar === 'red' || p.avatar === 'black');
                const isGambleIcon = (p.avatar === 'x2' || p.avatar === 'skull');
                if (isCasinoSlice) {
                    label = String(p.username || '');
                    ctxC.fillStyle = '#F5E6C8';
                } else if (p.avatar === 'x2') {
                    label = 'x2';
                    ctxC.fillStyle = '#4ade80';
                } else if (p.avatar === 'skull') {
                    label = String.fromCodePoint(0x1F480);
                    ctxC.fillStyle = '#f87171';
                } else {
                    label = String(p.username || '').substring(0, maxChars);
                    ctxC.fillStyle = textCol;
                }

                if (isGambleIcon) {
                    const gFs = Math.max(28, Math.round(canvasSize * 0.075));
                    ctxC.font = `900 ${gFs}px "Nunito", system-ui, sans-serif`;
                } else if (isCasinoSlice) {
                    const cFs = Math.max(12, Math.round(canvasSize * 0.028));
                    ctxC.font = `900 ${cFs}px "Nunito", system-ui, sans-serif`;
                }

                ctxC.shadowColor = 'rgba(0,0,0,0.55)';
                ctxC.shadowBlur = isGambleIcon ? 8 : 4;
                ctxC.shadowOffsetX = 0;
                ctxC.shadowOffsetY = isGambleIcon ? 2 : 1;
                ctxC.fillText(label, 0, 0);

                if (isGambleIcon || isCasinoSlice) {
                    ctxC.font = `800 ${fontSize}px "Nunito", system-ui, sans-serif`;
                }
                ctxC.restore();
            }
            ctxC.restore();

            // ------- Draw avatars -------
            const avatarR = innerR * 0.82;
            const avatarSize = Math.max(22, Math.min(48, Math.round(canvasSize * 0.056)));
            const avatarHalf = avatarSize / 2;

            // Preload all avatars (congelados a su 1er frame para no animar en la rueda)
            const avatarImgs = renderPool.map(p => {
                if (!p.avatar || p.avatar === 'x2' || p.avatar === 'skull' || p.avatar === 'red' || p.avatar === 'black') return null;
                const src = p.avatar.startsWith('http') ? p.avatar : `../../assets/avatars/${p.avatar}`;
                return _getFrozenAvatar(src);
            });

            // Draw avatars (some may not be loaded yet — redraw on load)
            function drawAvatars() {
                for (let i = 0; i < count; i++) {
                    const fe = avatarImgs[i];
                    if (!fe || !fe.ready || !fe.canvas) continue;
                    const img = fe.canvas;
                    const midA = (i * sliceRad) + (sliceRad / 2) - Math.PI / 2;
                    const ax = cx + avatarR * Math.cos(midA);
                    const ay = cy + avatarR * Math.sin(midA);

                    ctxC.save();
                    ctxC.beginPath();
                    ctxC.arc(ax, ay, avatarHalf, 0, 2 * Math.PI);
                    ctxC.clip();
                    ctxC.drawImage(img, ax - avatarHalf, ay - avatarHalf, avatarSize, avatarSize);
                    ctxC.restore();

                    // Avatar border ring
                    ctxC.beginPath();
                    ctxC.arc(ax, ay, avatarHalf + 1.5, 0, 2 * Math.PI);
                    ctxC.strokeStyle = 'rgba(255,255,255,0.9)';
                    ctxC.lineWidth = 2;
                    ctxC.stroke();
                }
            }
            drawAvatars();

            // Retry avatars that weren't loaded
            let retries = 0;
            const retryAvatarDraw = setInterval(() => {
                retries++;
                drawAvatars();
                if (retries > 20) clearInterval(retryAvatarDraw);
            }, 200);

            // ------- Center pin (tiny, clean meeting point) -------
            const pinR = Math.max(4, canvasSize * 0.012);
            ctxC.save();
            ctxC.beginPath();
            ctxC.arc(cx, cy, pinR + 2, 0, 2 * Math.PI);
            ctxC.fillStyle = '#1a1a2e';
            ctxC.fill();
            ctxC.beginPath();
            ctxC.arc(cx, cy, pinR, 0, 2 * Math.PI);
            const pinGrad = ctxC.createRadialGradient(cx - pinR * 0.3, cy - pinR * 0.3, 0, cx, cy, pinR);
            pinGrad.addColorStop(0, '#FFFFFF');
            pinGrad.addColorStop(0.5, '#E0E0E0');
            pinGrad.addColorStop(1, '#B0B0B0');
            ctxC.fillStyle = pinGrad;
            ctxC.fill();
            ctxC.restore();

            wheelEl.appendChild(canvas);

            // ------- Setup Flapper Pointer -------
            const arrow = document.querySelector('#animWheelContainer .wheel-arrow');
            if (arrow) {
                arrow.dataset.bigPointer = '1';
                arrow.style.width = '60px';
                arrow.style.height = '80px';
                arrow.style.top = '-18px';
                arrow.style.left = '50%';
                arrow.style.transform = 'translateX(-50%)';
                arrow.style.transformOrigin = '50% 8px';
                arrow.style.border = 'none';
                arrow.style.background = 'transparent';
                arrow.style.filter = 'drop-shadow(0 4px 10px rgba(0,0,0,0.5))';
                arrow.style.zIndex = '100';
                arrow.style.transition = 'none';
                arrow.innerHTML = `
                    <svg width="60" height="80" viewBox="0 0 60 80" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="ptrGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#FF3B3B"/>
                                <stop offset="50%" stop-color="#E01E1E"/>
                                <stop offset="100%" stop-color="#B01515"/>
                            </linearGradient>
                            <filter id="ptrShadow" x="-20%" y="-10%" width="140%" height="130%">
                                <feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#000" flood-opacity="0.35"/>
                            </filter>
                        </defs>
                        <path d="M30 76 L12 16 Q30 2 48 16 Z" fill="url(#ptrGrad)" stroke="#FFFFFF" stroke-width="2.5" stroke-linejoin="round" filter="url(#ptrShadow)"/>
                        <ellipse cx="30" cy="20" rx="5" ry="5" fill="#FFFFFF" opacity="0.92"/>
                        <ellipse cx="28" cy="18" rx="2" ry="1.5" fill="#FFFFFF" opacity="0.4"/>
                    </svg>`;
            }

            // Remove old decorations
            const outer = wheelEl.closest('.wheel-outer');
            if (outer) {
                const oldGlow = outer.querySelector('[data-wheel-deco="glow"]');
                if (oldGlow) oldGlow.remove();
                const oldDots = outer.querySelector('[data-wheel-deco="dots"]');
                if (oldDots) oldDots.remove();
            }

            return { sliceDeg, renderPool };
        }

        // ── Resize listener: fluid wheel redraw on viewport change ──
        window.addEventListener('resize', function () {
            clearTimeout(_wheelResizeDebounce);
            _wheelResizeDebounce = setTimeout(function () {
                if (!_lastWheelPool || !_lastWheelMode) return;
                // Skip redraw during active spin animation
                if (typeof _wheelRafId !== 'undefined' && _wheelRafId) return;
                const wheelEl = document.getElementById('mainWheel');
                if (!wheelEl || !wheelEl.querySelector('canvas')) return;
                // Preserve current rotation transform
                const curTransform = wheelEl.style.transform || '';
                setupWheel(_lastWheelPool, _lastWheelMode);
                wheelEl.style.transform = curTransform;
            }, 250);
        });

        // ========================================
        // TURN INDICATOR INJECTION (Doble o Nada)
        // ========================================
        function showGambleTurnIndicator(activeGambler, effectiveAnim) {
            // Remove any existing indicator first
            hideGambleTurnIndicator();

            if (!activeGambler) return;

            // Count participants who are gambling
            const gamblingParticipants = (state.winners || []).filter(w =>
                w && (w.choice === 'gamble_pending' || w.choice === 'double_won' || w.choice === 'double_lost')
            );
            const participantCount = gamblingParticipants.length;

            // Determine game type
            const isCoin = (effectiveAnim === 'coin');
            const isRoulette = (effectiveAnim === 'roulette');
            const isSlots = (!isCoin && !isRoulette);

            // Get participant data
            const username = activeGambler.username || 'Jugador';
            const avatar = activeGambler.avatar || 'default.png';
            const avatarSrc = avatar.startsWith('http') ? avatar : `../../assets/avatars/${avatar}`;

            // === COIN: Cinematic player badge + stakes text ===
            if (isCoin) {
                const titleText = participantCount === 1 ? 'La suerte de:' : 'Doble o Nada de:';
                const rawChoiceSide = String(activeGambler.prediction_side || activeGambler.coin_choice || activeGamblePredictionSide || '').toLowerCase();
                const choiceSide = (rawChoiceSide === 'heads' || rawChoiceSide === 'tails') ? rawChoiceSide : null;
                const choiceLabel = choiceSide === 'tails' ? 'SELLO' : (choiceSide === 'heads' ? 'CARA' : 'SIN DEFINIR');
                const oppositeLabel = choiceSide === 'tails' ? 'CARA' : 'SELLO';

                const indicatorHTML = `
                    <div id="gambleTurnIndicator" class="gamble-turn-indicator gamble-turn-indicator--coin">
                        <div class="gamble-turn-title gamble-coin-title">${titleText}</div>
                        <div class="gamble-coin-badge">
                            <img src="${avatarSrc}" alt="${username}" class="gamble-coin-badge-avatar">
                            <span class="gamble-coin-badge-name">${username}</span>
                        </div>
                    </div>
                `;

                const stakesHTML = `
                    <div id="gambleCoinStakes" class="gamble-coin-stakes">
                        <div class="gamble-coin-stakes-choice">
                            <span class="gamble-coin-stakes-choice-prefix">El participante eligió:</span>
                            <span class="gamble-coin-stakes-choice-value">${choiceLabel}</span>
                        </div>
                        <div class="gamble-coin-stakes-divider"></div>
                        <div class="gamble-coin-stakes-outcomes">
                            <div class="gamble-coin-stakes-row gamble-coin-stakes-row--win">
                                <span class="gamble-coin-stakes-icon"><i class="fas fa-crown"></i></span>
                                <span class="gamble-coin-stakes-label">${choiceSide ? `Si toca ${choiceLabel}:` : 'Si acierta su lado:'}</span>
                                <span class="gamble-coin-stakes-result">Duplica el premio</span>
                            </div>
                            <div class="gamble-coin-stakes-row gamble-coin-stakes-row--lose">
                                <span class="gamble-coin-stakes-icon"><i class="fas fa-skull"></i></span>
                                <span class="gamble-coin-stakes-label">${choiceSide ? `Si toca ${oppositeLabel}:` : 'Si falla su lado:'}</span>
                                <span class="gamble-coin-stakes-result">Lo pierde todo</span>
                            </div>
                        </div>
                    </div>
                `;

                const overlay = document.getElementById('gambleOverlay');

                if (overlay) {
                    overlay.insertAdjacentHTML('afterbegin', indicatorHTML);
                    overlay.insertAdjacentHTML('beforeend', stakesHTML);
                }

                setTimeout(() => {
                    const indicator = document.getElementById('gambleTurnIndicator');
                    if (indicator) indicator.classList.remove('gamble-turn-indicator--hidden');
                }, 50);
                return;
            }

            // === ROULETTE / SLOTS: Original logic ===
            let titleText = '';
            if (isRoulette) {
                titleText = participantCount === 1 ? 'La suerte de:' : 'Turno de:';
            } else {
                titleText = participantCount === 1 ? 'La suerte de:' : 'Turno de:';
            }

            // Determine variant class
            let variantClass = '';
            if (isSlots) {
                variantClass = 'gamble-turn-indicator--floating';
            } else if (isRoulette) {
                variantClass = 'gamble-turn-indicator--roulette';
            }

            // Ruleta Rojo/Negro: chip minimalista con la elección del jugador.
            let rouletteChoiceHtml = '';
            if (isRoulette) {
                const rawSide = String(activeGambler.prediction_side || activeGambler.coin_choice || activeGamblePredictionSide || '').toLowerCase();
                const sideLabel = _sfRouletteSideLabel(rawSide);
                if (sideLabel) {
                    rouletteChoiceHtml = `
                        <div class="gamble-roulette-choice">
                            <span class="gamble-roulette-choice-label">Apostó a</span>
                            <span class="gamble-roulette-choice-chip gamble-roulette-choice-chip--${rawSide}">${sideLabel}</span>
                        </div>`;
                }
            }

            // Create indicator HTML
            const indicatorHTML = `
                <div id="gambleTurnIndicator" class="gamble-turn-indicator ${variantClass}">
                    <div class="gamble-turn-title">${titleText}</div>
                    <div class="gamble-turn-player">
                        <img src="${avatarSrc}" alt="${username}" class="gamble-turn-avatar">
                        <span class="gamble-turn-username">${username}</span>
                    </div>
                    ${rouletteChoiceHtml}
                </div>
            `;

            // Inject into appropriate container
            if (isSlots) {
                document.body.insertAdjacentHTML('beforeend', indicatorHTML);
            } else {
                const overlay = document.getElementById('gambleOverlay');
                if (overlay) {
                    overlay.insertAdjacentHTML('afterbegin', indicatorHTML);
                }
            }

            // Trigger animation by removing hidden class after a brief delay
            setTimeout(() => {
                const indicator = document.getElementById('gambleTurnIndicator');
                if (indicator) indicator.classList.remove('gamble-turn-indicator--hidden');
            }, 50);
        }

        function hideGambleTurnIndicator() {
            const indicator = document.getElementById('gambleTurnIndicator');
            if (indicator) {
                indicator.classList.add('gamble-turn-indicator--hidden');
                setTimeout(() => {
                    if (indicator && indicator.parentNode) {
                        indicator.parentNode.removeChild(indicator);
                    }
                }, 300); // Wait for fade-out animation
            }
            // Also remove coin stakes text if present
            const stakes = document.getElementById('gambleCoinStakes');
            if (stakes && stakes.parentNode) {
                stakes.parentNode.removeChild(stakes);
            }
        }

        function runGambleAnimation(activeGambler) {
            isGambling = true;
            const effectiveAnim = (activeGambler && activeGambler.selected_animation_mode)
                ? activeGambler.selected_animation_mode
                : ((activeGambler && activeGambler.gamble_result && activeGambler.gamble_result.animation_pref) || (CONFIG?.anims?.gamble) || null);

            if (gambleInterval) {
                clearInterval(gambleInterval);
                gambleInterval = null;
            }

            // 1. Preparar Overlays
            const overlay = document.getElementById('gambleOverlay');
            if (overlay) overlay.classList.remove('hidden');
            const mo = document.getElementById('mainAnimOverlay');

            if (mo) mo.classList.remove('hidden');

            if (overlay) {
                overlay.classList.remove('gamble-transparent');
                overlay.classList.remove('gamble-coin-layout');
                overlay.classList.remove('gamble-roulette-layout');
                if (effectiveAnim === 'coin') overlay.classList.add('gamble-coin-layout');
                else overlay.classList.add('gamble-transparent');
            }

            // 2. Limpiar estado anterior (Ocultar todo)
            const wc = document.getElementById('animWheelContainer');
            if (wc) wc.classList.add('hidden');
            const sc = document.getElementById('animSlotContainer');
            if (sc) sc.classList.add('hidden');
            const scene = document.getElementById('sceneCoin');
            if (scene) scene.style.display = 'none';

            // Hide block race — resolution animation takes over
            _gambleTickerStop();
            const raceHide = document.getElementById('gambleBlockRace');
            if (raceHide) raceHide.classList.add('hidden');

            // 3. Show Turn Indicator
            showGambleTurnIndicator(activeGambler, effectiveAnim);

            if (effectiveAnim === 'coin') {
                const sceneCoin = document.getElementById('sceneCoin');
                if (sceneCoin) sceneCoin.style.display = 'block';
                const coin = document.getElementById('theCoin');
                if (coin) {
                    const startDeg = gambleCoinDeg || 0;
                    coin.style.transition = 'transform 0.1s linear';
                    coin.style.transform = `rotateY(${startDeg}deg)`;
                    coin.offsetHeight;
                }

                let deg = gambleCoinDeg || 0;
                gambleInterval = setInterval(() => {
                    deg += 90;
                    gambleCoinDeg = deg;
                    const c = document.getElementById('theCoin');
                    if (c) c.style.transform = `rotateY(${deg}deg)`;
                }, 120);
                return;
            }

            if (effectiveAnim === 'roulette') {
                if (wc) wc.classList.remove('hidden');
                if (overlay) overlay.classList.add('gamble-roulette-layout');
                // Ruleta Rojo/Negro estilo casino: 12 casillas alternadas. El
                // ganador acierta si la bola cae en el color que eligió; si no
                // hay elección (filas legadas), rojo = victoria (heads = rojo).
                const predSideRaw = String((activeGambler && (activeGambler.prediction_side || activeGambler.coin_choice)) || activeGamblePredictionSide || '').toLowerCase();
                const predSide = (predSideRaw === 'red' || predSideRaw === 'black') ? predSideRaw : null;
                const wheelPool = Array.from({ length: 12 }).map((_, i) => {
                    const sideColor = (i % 2 === 0) ? 'red' : 'black';
                    const isWin = predSide ? (sideColor === predSide) : (sideColor === 'red');
                    return {
                        username: _sfRouletteSideLabel(sideColor),
                        avatar: sideColor,
                        color: sideColor === 'red' ? ROULETTE_RED : ROULETTE_BLACK,
                        side: sideColor,
                        isWin
                    };
                });
                gambleWheelPool = wheelPool;
                gambleWheelSliceDeg = 360 / wheelPool.length;
                gambleWheelRotation = 0;
                runWheelAnimation({ username: wheelPool[0].username }, 'gamble', null, wheelPool);
                return;
            }

            if (sc) sc.classList.remove('hidden');
            const slotPool = Array.from({ length: 18 }).map((_, i) => {
                const isWin = (i % 2 === 0);
                return { username: isWin ? 'x2' : 'Nada', avatar: isWin ? 'x2' : 'skull', isWin };
            });
            runSlotAnimation({ username: slotPool[0].username, avatar: slotPool[0].avatar }, 'gamble', null, slotPool);
        }

        function finishGambleAnimation(won, winnerName, isMe, outcomeSide, seekMs) {
            const effectiveAnim = activeGambleAnim || CONFIG.anims.gamble;
            // Seek absoluto: ms ya transcurridos del giro de resolución. 0 para
            // el flujo en vivo normal; > 0 cuando se entra tarde / se vuelve de
            // segundo plano. Cada rama aplica el seek con un único salto fluido.
            const _seek = (Number.isFinite(seekMs) && seekMs > 0) ? seekMs : 0;

            if (gambleInterval) {
                clearInterval(gambleInterval);
                gambleInterval = null;
            }

            const resolvedOutcomeSide = (lastGambleOutcome === 'heads' || lastGambleOutcome === 'tails')
                ? lastGambleOutcome
                : ((activeGamblePredictionSide === 'heads' || activeGamblePredictionSide === 'tails')
                    ? (won
                        ? activeGamblePredictionSide
                        : (activeGamblePredictionSide === 'heads' ? 'tails' : 'heads'))
                    : (won ? 'heads' : 'tails'));

            if (effectiveAnim === 'coin') {
                const coin = document.getElementById('theCoin');
                if (!coin) {
                    handleFinishGamble(won, winnerName, isMe);
                    return;
                }

                const COIN_RESOLVE_MS = 16000; // 13s transición + 3s settle
                const COIN_TRANS_MS = 13000;
                const current = gambleCoinDeg || 0;
                const currentMod = ((current % 360) + 360) % 360;
                const targetMod = (resolvedOutcomeSide === 'tails') ? 180 : 0;
                const delta = (targetMod - currentMod + 360) % 360;
                const finalDeg = current + (12 * 360) + delta;
                // Si el giro ya terminó en el servidor, aterriza al instante y
                // pasa al resultado (sin reproducir el giro completo).
                if (_seek >= COIN_RESOLVE_MS) {
                    coin.style.transition = 'none';
                    coin.style.transform = `rotateY(${finalDeg}deg)`;
                    gambleCoinDeg = finalDeg;
                    handleFinishGamble(won, winnerName, isMe);
                    return;
                }
                coin.style.transition = 'transform 13s cubic-bezier(0.06, 0.9, 0.12, 1)';
                // Negative transition-delay ⇒ la transición se considera
                // iniciada en el pasado: la moneda salta al fotograma correcto
                // y continúa fluida desde ahí.
                coin.style.transitionDelay = (-Math.min(_seek, COIN_TRANS_MS)) + 'ms';
                coin.style.transform = `rotateY(${finalDeg}deg)`;
                gambleCoinDeg = finalDeg;
                finishDelay(Math.max(0, COIN_RESOLVE_MS - _seek));
                return;
            }

            if (effectiveAnim === 'roulette') {
                if (_wheelRafId) {
                    cancelAnimationFrame(_wheelRafId);
                    _wheelRafId = null;
                }
                const wheel = document.getElementById('mainWheel');
                if (!wheel) {
                    handleFinishGamble(won, winnerName, isMe);
                    return;
                }
                const pool = Array.isArray(gambleWheelPool) && gambleWheelPool.length > 0 ? gambleWheelPool : null;
                const sliceDeg = gambleWheelSliceDeg || (pool ? (360 / pool.length) : 60);
                let current = gambleWheelRotation || 0;
                const cs = window.getComputedStyle(wheel);
                const tr = cs.transform;
                if (tr && tr !== 'none') {
                    const m2 = tr.match(/matrix\(([^)]+)\)/);
                    const m3 = !m2 ? tr.match(/matrix3d\(([^)]+)\)/) : null;
                    const raw = m2 ? m2[1] : (m3 ? m3[1] : null);
                    if (raw) {
                        const parts = raw.split(',').map(s => parseFloat(s.trim()));
                        const a = parts[0];
                        const b = parts[1];
                        if (Number.isFinite(a) && Number.isFinite(b)) {
                            current = (Math.atan2(b, a) * 180) / Math.PI;
                        }
                    }
                }
                // Casilla de aterrizaje. En modo Rojo/Negro se aterriza en el
                // COLOR real del resultado (heads=rojo, tails=negro), coherente
                // con la predicción del jugador y el booleano ganado/perdido.
                const _grPredSide = (activeGamblePredictionSide === 'red' || activeGamblePredictionSide === 'black')
                    ? activeGamblePredictionSide : null;
                let landSide = null;
                if (pool && pool.some(p => p && (p.side === 'red' || p.side === 'black'))) {
                    if (lastGambleOutcome === 'heads') landSide = 'red';
                    else if (lastGambleOutcome === 'tails') landSide = 'black';
                    else if (_grPredSide) landSide = won ? _grPredSide : (_grPredSide === 'red' ? 'black' : 'red');
                    else landSide = won ? 'red' : 'black';
                    lastGambleLandSide = landSide;
                }
                let winIndex = 0;
                if (pool) {
                    const indices = [];
                    for (let i = 0; i < pool.length; i++) {
                        if (landSide !== null) {
                            if (pool[i].side === landSide) indices.push(i);
                        } else if (!!pool[i].isWin === !!won) {
                            indices.push(i);
                        }
                    }
                    if (indices.length > 0) {
                        // Determinista pero variado: la casilla exacta sale de un
                        // hash del nombre (idéntico en todas las pantallas/replay).
                        const idxSeed = (winnerName || 'g') + ':slice:' + indices.length;
                        winIndex = indices[Math.floor(deterministicFloat(idxSeed) * indices.length) % indices.length];
                    }
                }
                const gambleSeed = (winnerName || 'g') + ':' + winIndex + ':' + (pool ? pool.length : 0);
                const gt = deterministicFloat(gambleSeed);
                const gMargin = Math.max(1.5, sliceDeg * 0.05);
                const targetAngle = (winIndex * sliceDeg) + gMargin + gt * (sliceDeg - 2 * gMargin);
                const stopMod = ((360 - targetAngle) % 360 + 360) % 360;
                const currentMod = ((current % 360) + 360) % 360;
                const delta = (stopMod - currentMod + 360) % 360;
                const finalRotation = current + (6 * 360) + delta;
                const WHEEL_RESOLVE_MS = 22500; // 16.5s transición + settle
                const WHEEL_TRANS_MS = 16500;
                // Si la ruleta del gamble ya terminó en el servidor, fija el
                // resultado al instante y pasa al desenlace (sin reproducir).
                if (_seek >= WHEEL_RESOLVE_MS) {
                    wheel.style.transition = 'none';
                    wheel.style.transform = `rotate(${finalRotation}deg)`;
                    gambleWheelRotation = finalRotation;
                    handleFinishGamble(won, winnerName, isMe);
                    return;
                }
                wheel.style.transition = 'transform 16.5s cubic-bezier(0.08, 0.95, 0.18, 1)';
                // Negative transition-delay ⇒ salto fluido al fotograma exacto
                // del giro y continuación local a 60fps.
                wheel.style.transitionDelay = (-Math.min(_seek, WHEEL_TRANS_MS)) + 'ms';
                wheel.style.transform = `rotate(${finalRotation}deg)`;
                gambleWheelRotation = finalRotation;

                // ── Tick + Flapper monitor during CSS transition ──
                const _grArrow = document.querySelector('#animWheelContainer .wheel-arrow');
                const _grPoolLen = pool ? pool.length : 6;
                let _grLastSlice = -1;
                let _grLastTime = null;
                let _grLastAngle = null;
                let _grRaf = null;

                WheelTickSynth.ensureContext();
                WheelFlapperPhysics.reset();

                function _grMonitor(ts) {
                    if (!_grLastTime) _grLastTime = ts;

                    // Read live rotation from computed matrix
                    const cs2 = window.getComputedStyle(wheel);
                    const tr2 = cs2.transform;
                    let ang = 0;
                    if (tr2 && tr2 !== 'none') {
                        const mx = tr2.match(/matrix\(([^)]+)\)/);
                        if (mx && mx[1]) {
                            const pp = mx[1].split(',').map(s => parseFloat(s.trim()));
                            ang = (Math.atan2(pp[1], pp[0]) * 180) / Math.PI;
                        }
                    }
                    ang = ((ang % 360) + 360) % 360;

                    const curSlice = Math.floor(ang / sliceDeg) % _grPoolLen;

                    if (_grLastSlice >= 0 && curSlice !== _grLastSlice) {
                        const dtSec = (ts - _grLastTime) / 1000;
                        let aDelta = ang - (_grLastAngle !== null ? _grLastAngle : ang);
                        if (aDelta < -180) aDelta += 360;
                        if (aDelta > 180) aDelta -= 360;
                        const speed = dtSec > 0 ? Math.abs(aDelta) / dtSec : 0;
                        const intensity = Math.min(1, speed / 720);

                        WheelTickSynth.playTick(intensity);
                        WheelFlapperPhysics.strike(intensity);
                    }
                    _grLastSlice = curSlice;

                    // Update flapper spring
                    const dtFlap = Math.min(0.05, (ts - _grLastTime) / 1000);
                    _grLastTime = ts;
                    _grLastAngle = ang;
                    const fAng = WheelFlapperPhysics.update(dtFlap);
                    if (_grArrow) _grArrow.style.transform = `translateX(-50%) rotate(${fAng}deg)`;

                    _grRaf = requestAnimationFrame(_grMonitor);
                }
                _grRaf = requestAnimationFrame(_grMonitor);

                // Stop monitor after CSS transition + settle (descontando el seek)
                setTimeout(() => {
                    if (_grRaf) { cancelAnimationFrame(_grRaf); _grRaf = null; }
                    if (_grArrow) _grArrow.style.transform = 'translateX(-50%) rotate(0deg)';
                }, Math.max(0, 18500 - _seek));

                finishDelay(Math.max(0, WHEEL_RESOLVE_MS - _seek));
                return;
            }

            // Slot
            const track = document.getElementById('reel-strip');
            if (!track) {
                handleFinishGamble(won, winnerName, isMe);
                return;
            }
            const meta = gambleSlotMeta;
            const rowHeight = meta?.rowHeight || 36;
            const pool = meta?.pool || null;
            const startOffset = meta?.startOffset || 0;
            const rowsToScroll = meta?.rowsToScroll || 160;
            const centerOffset = meta?.centerOffset || 18;

            const cs = window.getComputedStyle(track);
            const tr = cs.transform;
            let curY = 0;
            if (tr && tr !== 'none') {
                const m = tr.match(/matrix\(([^)]+)\)/);
                if (m && m[1]) {
                    const parts = m[1].split(',').map(s => parseFloat(s.trim()));
                    if (parts.length === 6 && !Number.isNaN(parts[5])) curY = parts[5];
                }
            }

            track.style.transition = 'none';
            track.style.transform = 'translateY(0px)';
            track.offsetHeight;

            let currentI = Math.round(((-curY) + centerOffset) / rowHeight);
            if (!Number.isFinite(currentI) || currentI < 0) currentI = 0;

            let targetI = currentI + 300;
            if (pool && pool.length > 0) {
                let guard = 0;
                while (guard < 50) {
                    const pIndex = (startOffset + targetI) % pool.length;
                    if (!!pool[pIndex]?.isWin === !!won) break;
                    targetI += 1;
                    guard += 1;
                }
            }

            const maxI = Math.max(0, rowsToScroll - 3);
            if (targetI > maxI) targetI = maxI;

            // PUNTO 8.bis: desvío DETERMINISTA dentro de la celda (tensión del
            // Doble o Nada) idéntico en todas las pantallas (sin Math.random).
            const _gambleSeed = (won ? 'win' : 'lose') + ':' + (CONFIG.raffleId || '0') + ':' + targetI;
            const _gambleStopOffset = _sfCellStopOffset(_gambleSeed, rowHeight);
            const finalY = -((targetI * rowHeight) - centerOffset - _gambleStopOffset);

            // Stop the idle loop if running
            if (_activeMainReelRaf) {
                _activeMainReelRaf.stop();
                _activeMainReelRaf = null;
            }

            // Find center line for pluck feedback
            const centerLineEl = document.getElementById('center-line');

            // Get current offset from track transform
            const currentReelOffset = (() => {
                const cs2 = window.getComputedStyle(track);
                const tr2 = cs2.transform;
                if (tr2 && tr2 !== 'none') {
                    const m2 = tr2.match(/matrix.*\((.+)\)/);
                    if (m2 && m2[1]) {
                        const parts2 = m2[1].split(',').map(s => parseFloat(s.trim()));
                        const ty2 = parts2.length === 6 ? parts2[5] : parts2[parts2.length - 1];
                        if (Number.isFinite(ty2)) return Math.abs(ty2);
                    }
                }
                return 0;
            })();

            // rAF-driven gamble resolve — uses main raffle easeOutQuint for consistent physics.
            // anchorElapsedMs aplica el seek absoluto: _rafSpinReel hace UN salto
            // al fotograma exacto y continúa local a 60fps (mismo motor que los
            // Slots del sorteo principal). Si el seek supera la duración, aterriza
            // de inmediato en la celda y dispara onFinish.
            _activeMainReelRaf = _rafSpinReel({
                track,
                startOffset:  currentReelOffset,
                targetOffset: Math.abs(finalY),
                durationMs:   GAMBLE_RESOLVE_DURATION,
                rowHeight,
                viewportHalf: centerOffset,
                centerLineEl,
                linear: false,
                easingFn: easeOutGambleResolve, // Dual-polynomial: explosive burst → smooth quadratic decay to zero
                anchorElapsedMs: _seek,
                onFinish: () => {
                    _activeMainReelRaf = null;
                }
            });

            finishDelay(Math.max(0, (GAMBLE_RESOLVE_DURATION + 1000) - _seek));

            function finishDelay(ms) {
                if (finishGambleTimeout) clearTimeout(finishGambleTimeout);
                finishGambleTimeout = setTimeout(() => {
                    activeGambleAnim = null;
                    activeGamblePredictionSide = null;
                    finishGambleTimeout = null;
                    handleFinishGamble(won, winnerName, isMe);
                }, ms);
            }
        }

        function handleFinishGamble(won, winnerName, isMe) {
            stopGamblePolling();
            
            // Hide turn indicator when animation concludes
            hideGambleTurnIndicator();
            
            const proofHtml = lastGambleBlockHash ? `<div class="mt-3 text-[10px] text-gray-400 font-mono break-all">Block Hash: ${lastGambleBlockHash}</div>` : '';
            const outcomeHtml = lastGambleOutcome ? `<div class="mt-1 text-xs text-gray-300">Outcome: ${lastGambleOutcome}</div>` : '';

            // ============================================================
            // CINEMATIC RESULT STAGE — 4-second epic overlay with avatar
            // ============================================================
            const displayName = winnerName || 'Jugador';
            const landSide = (lastGambleLandSide === 'red' || lastGambleLandSide === 'black') ? lastGambleLandSide : null;
            lastGambleLandSide = null;
            let cinematicTitle, cinematicSubtitle;

            if (landSide) {
                // Ruleta Rojo/Negro: anuncia el color real donde cayó la bola.
                cinematicTitle = landSide === 'red' ? '¡CAYÓ EN ROJO!' : '¡CAYÓ EN NEGRO!';
                cinematicSubtitle = won ? '¡Acertó y duplica el premio!' : 'Falló el color… lo pierde todo';
            } else if (won) {
                // Victory: Action-focused title (no name overflow on mobile)
                cinematicTitle = '¡GANÓ EL DOBLE!';
                cinematicSubtitle = ''; // Removed redundant text
            } else {
                // Defeat: Dramatic announcement
                cinematicTitle = '¡SALIÓ CALAVERA!';
                cinematicSubtitle = ''; // Removed redundant text
            }
            
            // Get avatar source
            let avatarSrc = '../../assets/avatars/default.png';
            
            // Try to get avatar from gamblePlaybackCurrentWinner (playback mode)
            if (gamblePlaybackCurrentWinner && gamblePlaybackCurrentWinner.avatar) {
                const avatar = gamblePlaybackCurrentWinner.avatar;
                avatarSrc = avatar.startsWith('http') ? avatar : `../../assets/avatars/${avatar}`;
            }
            // Try to get from state.winners (active player or spectator)
            else if (state && Array.isArray(state.winners) && state.winners.length > 0) {
                const winner = state.winners.find(w => w && w.username === winnerName);
                if (winner && winner.avatar) {
                    const avatar = winner.avatar;
                    avatarSrc = avatar.startsWith('http') ? avatar : `../../assets/avatars/${avatar}`;
                }
            }
            // Fallback: try to get from winAvatarMain element (if already set)
            else {
                const mainAvatar = document.getElementById('winAvatarMain');
                if (mainAvatar && mainAvatar.src && !mainAvatar.src.includes('default.png')) {
                    avatarSrc = mainAvatar.src;
                }
            }
            
            // Show the 4-second cinematic overlay with avatar
            showGambleResultCinematic(won, cinematicTitle, cinematicSubtitle, avatarSrc, displayName);
            
            // Launch confetti for victories (starts immediately, runs during cinematic)
            if (won) {
                launchConfetti();
            }

            if (gamblePlaybackInProgress) {
                const label = gamblePlaybackCurrentWinner && gamblePlaybackCurrentWinner.username ? gamblePlaybackCurrentWinner.username : (winnerName || '');
                const title = won ? `${escapeHtml(label)} GANÓ!` : `${escapeHtml(label)} PERDIÓ!`;
                const body = landSide
                    ? (landSide === 'red' ? 'La bola cayó en ROJO.' : 'La bola cayó en NEGRO.')
                    : (won ? 'Se llevó el premio DOBLE.' : 'Salió la calavera.');
                setGambleOverlayText(`<div class="flex flex-col items-center gap-1"><div class="text-yellow-400 text-sm font-black">${title}</div><div class="text-gray-300 text-xs font-bold">${body}</div>${outcomeHtml}${proofHtml}</div>`);

                if (gamblePlaybackCurrentKey) gamblePlaybackPlayedKeys.add(gamblePlaybackCurrentKey);
                gamblePlaybackCurrentKey = null;
                gamblePlaybackCurrentWinner = null;

                if (gamblePlaybackAdvanceTimeout) clearTimeout(gamblePlaybackAdvanceTimeout);
                gamblePlaybackAdvanceTimeout = null;

                const snapshot = Array.isArray(gamblePlaybackSnapshot) ? gamblePlaybackSnapshot : [];
                const hasRemaining = snapshot.some(w => {
                    if (!w || (w.choice !== 'double_won' && w.choice !== 'double_lost')) return false;
                    const k = getGamblePlaybackKey(w);
                    return k && !gamblePlaybackPlayedKeys.has(k);
                });

                if (hasRemaining) {
                    // Esperar a que termine la cinemática (4s) antes de avanzar.
                    // El selector se recalcula con el reloj fresco para descontar
                    // la franja recién mostrada y saltar las que vencieron durante
                    // la cinemática, evitando la parálisis/salto abrupto (bug 2).
                    gamblePlaybackAdvanceTimeout = setTimeout(() => {
                        gamblePlaybackAdvanceTimeout = null;
                        const next = _pickCurrentGambleAndSeek(gamblePlaybackSnapshot);
                        if (next && Array.isArray(next.skipKeys)) {
                            next.skipKeys.forEach(k => gamblePlaybackPlayedKeys.add(k));
                        }
                        if (next && next.w) {
                            startSequentialGamblePlaybackForWinner(next.w, next.key, next.seekMs);
                            return;
                        }
                        // Nada vivo por mostrar: finalizar la cola.
                        gamblePlaybackInProgress = false;
                        const mo = document.getElementById('mainAnimOverlay');
                        if (mo) mo.classList.add('hidden');
                        const go = document.getElementById('gambleOverlay');
                        if (go) go.classList.add('hidden');
                        isGambling = false;
                        if (!summaryShown && snapshot.length > 0) summaryShown = true;
                    }, GAMBLE_CINEMATIC_MS);
                    return;
                }

                gamblePlaybackInProgress = false;

                const hasPending = snapshot.some(w => w && (w.choice === 'pending' || w.hud_state === 'thinking'));
                const hasActive = snapshot.some(w => w && (w.hud_state === 'spinning' || w.hud_state === 'ready_queue' || w.choice === 'gamble_pending'));
                if (hasPending || hasActive || state.status !== 'completed') {
                    // Wait for cinematic before cleanup
                    setTimeout(() => {
                        const mo = document.getElementById('mainAnimOverlay');
                        if (mo) mo.classList.add('hidden');
                        const go = document.getElementById('gambleOverlay');
                        if (go) go.classList.add('hidden');
                        isGambling = false;
                    }, 4000);
                    return;
                }

                // Wait for cinematic before final cleanup
                setTimeout(() => {
                    const mo = document.getElementById('mainAnimOverlay');
                    if (mo) mo.classList.add('hidden');
                    const go = document.getElementById('gambleOverlay');
                    if (go) go.classList.add('hidden');
                    isGambling = false;

                    if (!summaryShown && snapshot.length > 0) {
                        summaryShown = true;
                    }
                }, 4000);
                return;
            }

            // Non-playback scenario: Wait for 4-second cinematic before cleanup
            setTimeout(() => {
                if (isMe) {
                    const mo = document.getElementById('mainAnimOverlay');
                    if (mo) mo.classList.add('hidden');
                    const go = document.getElementById('gambleOverlay');
                    if (go) go.classList.add('hidden');
                    const dm = document.getElementById('decisionModal');
                    if (dm) dm.classList.add('hidden');
                    isGambling = false;
                    startStatusPolling();
                } else {
                    // Spectator cleanup with additional safety timeout
                    const mo = document.getElementById('mainAnimOverlay');
                    if (mo) mo.classList.add('hidden');
                    const go = document.getElementById('gambleOverlay');
                    if (go) go.classList.add('hidden');
                    isGambling = false;
                    startStatusPolling();
                    
                    // Safety reload for spectators
                    if (postGambleCleanupTimeout) clearTimeout(postGambleCleanupTimeout);
                    postGambleCleanupTimeout = setTimeout(() => {
                        const mo2 = document.getElementById('mainAnimOverlay');
                        if (mo2) mo2.classList.add('hidden');
                        const go2 = document.getElementById('gambleOverlay');
                        if (go2) go2.classList.add('hidden');
                        isGambling = false;
                        startStatusPolling();
                    }, 3000);
                }
            }, 4000);
        }
        // [LEGACY ENGINE] SLOT ANIMATION: rAF Physics + easeOutQuint + Tick Sync
        // Active rAF handle for the main reel (so gamble resolve can .stop() it)
        let _activeMainReelRaf = null;

        function runSlotAnimation(winner, mode, callback, customPool = null, sceneIndex = 0) {
            document.getElementById('animSlotContainer').classList.remove('hidden');
            let pool = customPool || state.participants;
            if (!pool || pool.length === 0) pool = [{ username: '...', avatar: 'default.png' }];

            // Legacy uses FIXED_DISTANCE_ITEMS = 400 for the dramatic long spin.
            const isGambleLoop = (mode === 'gamble' && !callback);
            const rowsToScroll = isGambleLoop ? 2400 : (LEGACY_FIXED_DISTANCE + 50);

            // --- LEGACY TARGET CALCULATION ---
            let winnerIdx = pool.findIndex(p => p.username === winner.username);
            if (winnerIdx === -1) winnerIdx = 0;

            let targetItemIndex = LEGACY_FIXED_DISTANCE;
            const targetMod = targetItemIndex % pool.length;
            let diff = winnerIdx - targetMod;
            if (diff < 0) diff += pool.length;
            targetItemIndex += diff;

            const winPosition = targetItemIndex;
            const totalRows = Math.max(rowsToScroll, winPosition + 20);
            const startRROffset = 0;

            const track = document.getElementById('reel-strip');
            track.style.transition = 'none';
            track.style.transform = 'translate3d(0, 0px, 0)';
            track.offsetHeight;

            let html = '';

            for (let i = 0; i < totalRows; i++) {
                let pIndex = (startRROffset + i) % pool.length;
                let p = pool[pIndex];

                let iconHtml = '';
                if (mode === 'gamble') {
                    if (p.isWin) iconHtml = '<div class="gamble-item-x2">&#128081;</div>';
                    else iconHtml = '<div class="gamble-item-skull">&#128128;</div>';
                } else {
                    const username = escapeHtml(p && (p.username || p.public_display_name) ? (p.username || p.public_display_name) : 'Anónimo');
                    const avatar = (p && p.avatar) ? String(p.avatar) : '';
                    const avatarSrc = avatar
                        ? (avatar.startsWith('http') ? avatar : `../../assets/avatars/${avatar}`)
                        : '';
                    iconHtml = avatar
                        ? `<img src="${avatarSrc}" alt="${username}" loading="lazy">`
                        : `
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                    </svg>`;
                }

                const displayName = escapeHtml(p && (p.username || p.public_display_name) ? (p.username || p.public_display_name) : 'Anónimo');

                html += `
            <div class="slot-reel-item participant-card">
                <div class="slot-reel-item-inner">
                    <div class="slot-reel-item-icon">${iconHtml}</div>
                    <span class="slot-reel-item-name">${displayName}</span>
                </div>
            </div>`;
            }

            track.innerHTML = html;
            _freezeAvatarsIn(track); // congela avatares animados durante el giro

            const rowHeight = (() => {
                const rowEl = track.querySelector('.slot-reel-item');
                const h = rowEl ? rowEl.offsetHeight : 0;
                return (h && Number.isFinite(h)) ? h : 100;
            })();
            const viewportEl = document.getElementById('reel-viewport');
            const viewportH = viewportEl ? viewportEl.clientHeight : 700;
            const indicatorY = viewportH / 2;
            const centerOffset = indicatorY - (rowHeight / 2);

            if (isGambleLoop) {
                gambleSlotMeta = { rowHeight, rowsToScroll: totalRows, startOffset: startRROffset, pool: [...pool], centerOffset };
            }

            // --- LEGACY TARGET PIXEL CALCULATION ---
            const targetPixel = (winPosition * rowHeight) - (viewportH / 2) + (rowHeight / 2);
            // PUNTO 8.bis: desvío DETERMINISTA dentro de la celda (tensión de
            // "ganar por un pelito"), idéntico para todos los espectadores.
            const _stopSeed = (winner && winner.username ? winner.username : 'w')
                + ':' + (CONFIG.raffleId || '0') + ':' + (parseInt(sceneIndex, 10) || 0);
            const finalTargetPos = targetPixel + _sfCellStopOffset(_stopSeed, rowHeight);

            SlotClickSynth.ensureContext();
            const centerLineEl = document.getElementById('center-line');
            track.offsetHeight;

            // PUNTO 8 (seek): ms ya transcurridos del giro según el reloj del
            // servidor para esta escena. Quien llega tarde se engancha en el
            // fotograma correcto (no aplica al loop idle del Doble o Nada).
            const _mainAnchorMs = _sfSpinElapsedMs(sceneIndex);

            // --- LAUNCH rAF ANIMATION ---
            setTimeout(() => {
                if (isGambleLoop) {
                    const loopTarget = (totalRows - 80) * rowHeight;
                    _activeMainReelRaf = _rafSpinReel({
                        track,
                        startOffset:  0,
                        targetOffset: loopTarget,
                        durationMs:   GAMBLE_LOOP_DURATION,
                        rowHeight,
                        viewportHalf: indicatorY,
                        centerLineEl,
                        linear: true,      // constant high speed throughout idle loop
                        easingFn: null,          // linear takes precedence
                        onFinish: null
                    });
                } else {
                    _activeMainReelRaf = _rafSpinReel({
                        track,
                        startOffset:  0,
                        targetOffset: finalTargetPos,
                        durationMs:   LEGACY_SPIN_DURATION,
                        rowHeight,
                        viewportHalf: indicatorY,
                        centerLineEl,
                        linear: false,
                        anchorElapsedMs: (_mainAnchorMs != null ? _mainAnchorMs : 0),
                        onFinish: () => {
                            _activeMainReelRaf = null;
                            if (callback) {
                                setTimeout(() => {
                                    document.getElementById('mainAnimOverlay').classList.add('hidden');
                                    callback();
                                }, 500);
                            }
                        }
                    });
                }
            }, 50);
        }

        /**
         * Deterministic pseudo-random float in [0,1] from a string seed.
         * Uses FNV-1a 32-bit hash. Every client with the same seed gets
         * the exact same result — no Math.random() involved.
         */
        function deterministicFloat(seed) {
            let h = 0x811c9dc5;
            for (let i = 0; i < seed.length; i++) {
                h ^= seed.charCodeAt(i);
                h = Math.imul(h, 0x01000193);
            }
            return ((h >>> 0) % 10000) / 10000;
        }

        // =================================================================
        //  runWheelAnimation — rAF physics-driven premium wheel spin.
        //  Features:
        //    • Exponential friction for heavy-feeling deceleration
        //    • Deterministic final stop within winning slice (seeded hash)
        //    • Physical flapper pointer with spring deflection
        //    • Synthesized tick audio synced to slice boundaries
        // =================================================================

        /** Active rAF id for the wheel so we can cancel */
        let _wheelRafId = null;

        function runWheelAnimation(winner, mode, callback, customPool = null, sceneIndex = 0) {
            document.getElementById('animWheelContainer').classList.remove('hidden');

            // Mobile: zero overlay padding & disable scale; wheel sized at 96vw handles the gap
            const _isMobile = window.innerWidth < 768;
            const mainOverlay = document.getElementById('mainAnimOverlay');
            const gambleOverlay = document.getElementById('gambleOverlay');
            const wheelContainer = document.getElementById('animWheelContainer');
            if (_isMobile) {
                if (mainOverlay) { mainOverlay.style.paddingLeft = '0'; mainOverlay.style.paddingRight = '0'; }
                if (gambleOverlay) { gambleOverlay.style.paddingLeft = '0'; gambleOverlay.style.paddingRight = '0'; }
                if (wheelContainer) { wheelContainer.classList.remove('scale-110'); }
            } else {
                if (mainOverlay) { mainOverlay.style.paddingLeft = ''; mainOverlay.style.paddingRight = ''; }
                if (gambleOverlay) { gambleOverlay.style.paddingLeft = ''; gambleOverlay.style.paddingRight = ''; }
                if (wheelContainer && !wheelContainer.classList.contains('scale-110')) { wheelContainer.classList.add('scale-110'); }
            }

            let pool = customPool || state.participants;
            if (!pool || pool.length === 0) pool = [{ username: 'Nadie', avatar: 'default.png' }];
            const wheel = document.getElementById('mainWheel');
            wheel.innerHTML = '';

            // Remove CSS transition — we drive via rAF
            wheel.style.transition = 'none';
            wheel.style.transform = 'rotate(0deg)';
            wheel.offsetHeight;

            const { sliceDeg, renderPool } = setupWheel(pool, mode);

            // Find winning slice
            let winIndex = 0;
            if (mode === 'main') {
                winIndex = renderPool.findIndex(p => p.username === winner.username);
                if (winIndex === -1) winIndex = 0;
            }

            // Deterministic stop within winning slice — seeded hash for visual variety
            // All clients compute identical offset from winner.username (no Math.random).
            const sliceStart = winIndex * sliceDeg;
            const hashSeed = (winner && winner.username) ? winner.username + ':' + renderPool.length : 'default';
            const t = deterministicFloat(hashSeed); // 0..1 deterministic
            const margin = Math.max(1.5, sliceDeg * 0.05); // 5% safety margin from slice dividers
            const stopAngle = sliceStart + margin + t * (sliceDeg - 2 * margin);

            // Physics constants
            const isInfinite = (mode === 'gamble' && !callback);
            const totalRevolutions = isInfinite ? 80 : 10; // MUST be integer
            const totalAngle = isInfinite
                ? 360 * totalRevolutions
                : (360 * totalRevolutions) + (360 - stopAngle); // land ON the slice

            // Timing
            const spinDuration = isInfinite ? 120000 : 22000; // Fixed for cross-client consistency
            const initialSpeed = totalAngle / (spinDuration / 1000); // deg/sec

            // SEEK ABSOLUTO (Ruleta principal): ms ya transcurridos de la escena
            // según el reloj del servidor (mismo motor que los Slots). Quien
            // entra tarde o vuelve de minimizar hace UN salto al fotograma
            // exacto y continúa local a 60fps; en vivo arranca en 0. No aplica
            // al modo idle infinito del Doble o Nada (ese seek lo hace el resolve).
            const _wheelAnchorRaw = (mode === 'main' && !isInfinite)
                ? (_sfSceneElapsedMs(sceneIndex) != null ? _sfSceneElapsedMs(sceneIndex) : _sfSpinElapsedMs(sceneIndex))
                : null;
            const _wheelSeekMs = (_wheelAnchorRaw != null)
                ? Math.max(0, Math.min(_wheelAnchorRaw, spinDuration))
                : 0;

            // Easing: custom power curve for heavy object feel
            // f(t) = 1 - (1 - t)^power   where power=6 gives long cruise + agonizing crawl
            const easingPower = isInfinite ? 1 : 6;
            function easeWheel(t) {
                if (isInfinite) return t; // linear for infinite mode
                return 1 - Math.pow(1 - t, easingPower);
            }

            // State
            let startTime = null;
            let lastSliceIndex = -1;
            let currentRotation = 0;

            // Ensure audio context
            WheelTickSynth.ensureContext();
            WheelFlapperPhysics.reset();

            const arrowEl = document.querySelector('#animWheelContainer .wheel-arrow');
            let lastFrameTime = null;

            if (_wheelRafId) {
                cancelAnimationFrame(_wheelRafId);
                _wheelRafId = null;
            }

            function wheelStep(timestamp) {
                if (!startTime) {
                    // Un único seek al primer frame: retrocede el origen del
                    // reloj para saltar al fotograma en vivo sin trabar el rAF.
                    startTime = timestamp - _wheelSeekMs;
                    lastFrameTime = timestamp;
                }

                const elapsed = timestamp - startTime;
                const progress = Math.min(elapsed / spinDuration, 1);
                const eased = easeWheel(progress);
                currentRotation = totalAngle * eased;

                // Apply rotation (GPU-accelerated)
                wheel.style.transform = `rotate(${currentRotation}deg)`;
                gambleWheelRotation = currentRotation;

                // Tick detection: which slice is under the pointer?
                // The pointer is at the TOP (12-o'clock). The wheel rotates clockwise.
                // The slice under the pointer = floor(rotation / sliceDeg) mod count
                const normalizedAngle = ((currentRotation % 360) + 360) % 360;
                const currentSliceIndex = Math.floor(normalizedAngle / sliceDeg) % renderPool.length;

                if (currentSliceIndex !== lastSliceIndex && lastSliceIndex >= 0) {
                    // Compute instantaneous speed for intensity (0..1)
                    const dt = (timestamp - lastFrameTime) / 1000;
                    const currentSpeed = dt > 0 ? Math.abs(currentRotation - (totalAngle * easeWheel(Math.max(0, progress - 0.002)))) / dt : 0;
                    const speedRatio = Math.min(1, currentSpeed / (initialSpeed * 0.8));

                    // Play tick sound
                    WheelTickSynth.playTick(speedRatio);

                    // Strike the flapper
                    WheelFlapperPhysics.strike(speedRatio);
                }
                lastSliceIndex = currentSliceIndex;

                // Update flapper physics
                const dtSec = Math.min(0.05, (timestamp - (lastFrameTime || timestamp)) / 1000);
                lastFrameTime = timestamp;
                const flapAngle = WheelFlapperPhysics.update(dtSec);
                if (arrowEl) {
                    arrowEl.style.transform = `translateX(-50%) rotate(${flapAngle}deg)`;
                }

                // Continue or finish
                if (progress < 1) {
                    _wheelRafId = requestAnimationFrame(wheelStep);
                } else {
                    _wheelRafId = null;
                    // Settle flapper animation (continue spring for 1.5s)
                    let settleStart = null;
                    function settleFlapper(ts) {
                        if (!settleStart) settleStart = ts;
                        const dt2 = Math.min(0.05, (ts - (settleStart || ts)) / 1000);
                        settleStart = ts;
                        const a = WheelFlapperPhysics.update(dt2);
                        if (arrowEl) arrowEl.style.transform = `translateX(-50%) rotate(${a}deg)`;
                        if (ts - settleStart < 1500 && Math.abs(a) > 0.1) {
                            requestAnimationFrame(settleFlapper);
                        } else {
                            if (arrowEl) arrowEl.style.transform = 'translateX(-50%) rotate(0deg)';
                        }
                    }
                    requestAnimationFrame(settleFlapper);

                    // Fire callback after settle
                    if (callback) {
                        setTimeout(() => {
                            document.getElementById('mainAnimOverlay').classList.add('hidden');
                            callback();
                        }, 2200);
                    }
                }
            }

            _wheelRafId = requestAnimationFrame(wheelStep);
        }

        function _colorLuminance(hex) {
            const h = String(hex || '').replace('#', '');
            if (h.length !== 6) return 0.5;
            const r = parseInt(h.slice(0, 2), 16) / 255;
            const g = parseInt(h.slice(2, 4), 16) / 255;
            const b = parseInt(h.slice(4, 6), 16) / 255;
            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        }

        /** No-op — ticking is now driven by the rAF loop */
        function playTickSoundSequence(totalTime) { /* handled by rAF wheel physics */ }

        function highlightGhostChoice(choice) {
            ['ghostOriginal', 'ghostCashback', 'ghostGamble'].forEach(id => document.getElementById(id).className = 'ghost-btn p-3 rounded-lg border border-gray-500/30 bg-gray-900/20 flex items-center gap-3');
            if (choice === 'original') document.getElementById('ghostOriginal').classList.add('ghost-selected');
            if (choice === 'cashback') document.getElementById('ghostCashback').classList.add('ghost-selected');
            if (choice === 'gamble_pending' || choice === 'double_won' || choice === 'double_lost') document.getElementById('ghostGamble').classList.add('ghost-selected');
        }
        let _lastPartCount = 0;
        function renderParticipants(list, expelledInput) {
            const participants = Array.isArray(list) ? list : [];
            // Expulsados: siguen en la lista (en gris) para que quede constancia,
            // pero no cuentan como participantes activos.
            const expelled = Array.isArray(expelledInput) ? expelledInput.filter(Boolean) : [];
            const desktopList = document.getElementById('participants-list');
            const mobileList = document.getElementById('participants-list-mobile');
            const legacyMobList = document.getElementById('participantsListMob');

            const participantsCount = document.getElementById('participants-count');
            const partCountDesk = document.getElementById('partCountDesk');
            const partCountMob = document.getElementById('partCountMob');

            const count = participants.length;

            // Update counters
            if (participantsCount) participantsCount.textContent = count;
            if (partCountDesk) partCountDesk.textContent = count;
            if (partCountMob) partCountMob.textContent = count;

            // Update modal title
            const pmTitle = document.getElementById('pmModalTitle');
            if (pmTitle) pmTitle.textContent = `PARTICIPANTES (${count})`;

            // Pop animation when count increases
            if (count > _lastPartCount) {
                const vpBtn = document.getElementById('viewParticipantsBtn');
                if (vpBtn) {
                    vpBtn.classList.remove('anim-pop-click');
                    void vpBtn.offsetWidth;
                    vpBtn.classList.add('anim-pop-click');
                    setTimeout(() => vpBtn.classList.remove('anim-pop-click'), 300);
                }
            }
            _lastPartCount = count;

            // Create hash of participants to detect changes
            const currentHash = (participants.length > 0 || expelled.length > 0)
                ? JSON.stringify(participants.concat(expelled).map(p => {
                    if (!p) return null;
                    return {
                        userId: p.user_id ?? null,
                        username: p.username ?? p.public_display_name ?? null,
                        ticket: p.ticket ?? null,
                        out: p.expelled === true
                    };
                }))
                : '';

            // Skip re-render if participants haven't changed
            if (currentHash === lastParticipantsHash && desktopList && desktopList.children.length > 0) {
                return;
            }
            lastParticipantsHash = currentHash;

            if (participants.length === 0 && expelled.length === 0) {
                const emptyHTML = `
                <div style="text-align: center; padding: 32px 0; color: var(--color-muted-foreground);">
                    <p style="font-size: 16px; margin-bottom: 4px;">Nadie se ha unido todavía</p>
                    <p style="font-size: 14px;">¡Sé el primero!</p>
                </div>
            `;
                if (desktopList) desktopList.innerHTML = emptyHTML;
                if (mobileList) mobileList.innerHTML = emptyHTML;
                if (legacyMobList) legacyMobList.innerHTML = emptyHTML;
                return;
            }

            const cardsHTML = participants.map((p) => {
                const isCurrentUser = CONFIG.me && CONFIG.me.userId && p.user_id && (parseInt(p.user_id, 10) === parseInt(CONFIG.me.userId, 10));
                const username = p.username || p.public_display_name || 'Anónimo';
                const initial = username.charAt(0).toUpperCase();
                const avatar = escapeHtml(p && p.avatar ? p.avatar : '');
                const ticketNumber = p.ticket;
                const avatarSrc = avatar
                    ? (avatar.startsWith('http') ? avatar : `../../assets/avatars/${avatar}`)
                    : '';

                return `
                <div class="participant-card ${isCurrentUser ? 'is-current-user' : ''}">
                    <div class="participant-avatar">
                        ${avatar ? `<img src="${avatarSrc}" alt="${escapeHtml(username)}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">` : escapeHtml(initial)}
                    </div>
                    <span class="participant-username">
                        ${escapeHtml(username)}${isCurrentUser ? ' <span style="font-size: 12px; font-weight: normal; opacity: 0.75;">(Tú)</span>' : ''}
                    </span>
                    <span class="participant-number">#${ticketNumber}</span>
                </div>
            `;
            }).join('') + _sfExpelledCardsHtml(expelled);

            if (desktopList) desktopList.innerHTML = cardsHTML;
            if (mobileList) mobileList.innerHTML = cardsHTML;
            if (legacyMobList) legacyMobList.innerHTML = cardsHTML;
        }

        // Tarjetas de expulsados al final de la lista de participantes: sin
        // etiquetas, sólo la tarjeta apagada en gris y el nombre tachado.
        function _sfExpelledCardsHtml(expelled) {
            if (!Array.isArray(expelled) || expelled.length === 0) return '';
            const cards = expelled.map((p) => {
                const username = p.username || p.public_display_name || 'Anónimo';
                const initial = username.charAt(0).toUpperCase();
                const avatar = escapeHtml(p && p.avatar ? p.avatar : '');
                const avatarSrc = avatar
                    ? (avatar.startsWith('http') ? avatar : `../../assets/avatars/${avatar}`)
                    : '';
                const isMe = CONFIG.me && CONFIG.me.userId && p.user_id && (parseInt(p.user_id, 10) === parseInt(CONFIG.me.userId, 10));
                return `
                <div class="participant-card participant-card--expelled" title="Ticket anulado por revisión">
                    <div class="participant-avatar">
                        ${avatar ? `<img src="${avatarSrc}" alt="${escapeHtml(username)}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">` : escapeHtml(initial)}
                    </div>
                    <span class="participant-username">
                        ${escapeHtml(username)}${isMe ? ' <span style="font-size: 12px; font-weight: normal; opacity: 0.75;">(Tú)</span>' : ''}
                    </span>
                    <span class="participant-number">#${parseInt(p.ticket, 10) || 0}</span>
                </div>`;
            }).join('');
            return cards;
        }

        function toggleParticipantsModal() {
            const modal = document.getElementById('participants-modal');
            if (modal) {
                modal.classList.toggle('active');
                return;
            }
            const legacy = document.getElementById('participantsModal');
            if (legacy) {
                legacy.classList.toggle('hidden');
            }
        }
        async function openPrizeModal() {
            await _sfHydratePrizeVisuals();
            // Un premio o varios: siempre el mismo diálogo oscuro ("Premio(s) en
            // juego"). El lightbox clásico sólo se usa si el diálogo no existe.
            if (_sfCanOpenPrizeShowcase() && _sfOpenPrizeShowcase()) return;
            const modal = document.getElementById('prizeShowcaseModal');
            if (!modal) return;
            const rawPrize = (typeof CONFIG !== 'undefined' && CONFIG.prize) ? CONFIG.prize : {};
            const pc = _sfPrimaryPrizeOption(rawPrize) || rawPrize;
            const prizeType = pc.type || pc.kind || 'unknown';
            const prizeName = pc.name || pc.title || null;
            const prizeImage = pc.image || pc.image_icon || null;
            const displayImage = prizeImage || (prizeType === 'coins' ? '/assets/img/moneda.webp' : null);
            const prizeAmount = pc.amount ? parseFloat(pc.amount) : 0;

            // Name
            const nameEl = document.getElementById('lb-name');
            if (nameEl) {
                if (prizeType === 'coins' && prizeAmount > 0) nameEl.textContent = prizeAmount.toLocaleString('es-ES') + ' Bananos';
                else if (prizeName) nameEl.textContent = prizeName;
                else nameEl.textContent = 'Premio del Sorteo';
            }

            // Imagen y colores: misma fórmula que createItemCardNode() en tienda.
            const cardEl = modal.querySelector('.lightbox-card');
            const imgWrap = document.getElementById('lightbox-img-wrap');
            const imgEl = document.getElementById('lightbox-img');
            const c1 = _sfStoreHex(pc.colors?.color1);
            const c2 = _sfStoreHex(pc.colors?.color2);
            const c3 = _sfStoreHex(pc.colors?.color3);
            if (cardEl) {
                cardEl.classList.add('sf-store-lightbox');
                cardEl.setAttribute('data-sf-item-type', _sfStoreTypeKey(pc));
                cardEl.style.cssText = _sfStoreCardVars(pc);
            }
            if (imgWrap) {
                imgWrap.style.cssText = _sfStoreCardVars(pc) + (c1 ? `--fn-color-1:${c1};--fn-color-2:${c3 || c1};--fn-color-3:${c2 || c3 || c1};` : '');
            }
            if (imgWrap) imgWrap.classList.toggle('is-placeholder', !displayImage);
            if (imgEl) {
                if (displayImage) {
                    imgEl.src = displayImage;
                    imgEl.alt = prizeName || 'Premio';
                    imgEl.classList.remove('hidden');
                } else {
                    imgEl.removeAttribute('src');
                    imgEl.classList.add('hidden');
                }
            }

            // Prices — same layout/typography as the checkout lightbox.
            // Soles read straight from the master cache (CONFIG.prizePricing); base
            // price shown struck-through when there is an active offer ("adaptativo").
            const pricing = (typeof CONFIG !== 'undefined' && CONFIG.prizePricing) ? CONFIG.prizePricing : null;
            const priceEl = document.getElementById('lb-price');
            const baseEl = document.getElementById('lb-base');
            const fmt = (n) => 'S/ ' + Number(n).toFixed(2);
            if (priceEl) {
                if (prizeType === 'coins' && prizeAmount > 0) {
                    priceEl.textContent = prizeAmount.toLocaleString('es-ES') + ' Bananos';
                } else if (pricing && pricing.final_price != null && pricing.final_price > 0) {
                    priceEl.textContent = fmt(pricing.final_price);
                } else {
                    priceEl.textContent = '';
                }
            }
            if (baseEl) {
                const showBase = pricing && pricing.has_offer && pricing.price_base != null
                    && pricing.final_price != null && pricing.price_base > pricing.final_price;
                if (showBase) { baseEl.textContent = fmt(pricing.price_base); baseEl.classList.remove('hidden'); }
                else { baseEl.classList.add('hidden'); }
            }

            // V-Bucks row (pavos SVG + amount)
            const vbWrap = document.getElementById('lb-vbucks-wrap');
            const vbEl = document.getElementById('lb-vbucks');
            const vbucks = (pricing && pricing.vbucks != null) ? parseInt(pricing.vbucks, 10)
                : (pc.vbucks ? parseInt(pc.vbucks, 10) : 0);
            if (vbWrap) vbWrap.classList.toggle('hidden', !(vbucks > 0));
            if (vbEl) vbEl.textContent = vbucks > 0 ? vbucks.toLocaleString('en-US') : '';

            // Cabecera exterior del modal: solo aparece al abrir el regalo del HUD.
            let modalHeading = modal.querySelector('.sf-prize-modal-heading');
            if (!modalHeading) {
                modalHeading = document.createElement('h2');
                modalHeading.className = 'sf-prize-modal-heading';
                modalHeading.textContent = 'PREMIO EN JUEGO';
                if (cardEl) modal.insertBefore(modalHeading, cardEl);
            }

            // Pie oficial: nombre arriba; paVos a la izquierda y Soles a la
            // derecha. Reutilizamos los nodos existentes para no duplicar IDs.
            const bodyEl = cardEl ? cardEl.querySelector('.lb-body') : null;
            const priceCol = cardEl ? cardEl.querySelector('.lb-price-col') : null;
            let storePriceRow = cardEl ? cardEl.querySelector('.sf-store-price-row') : null;
            if (bodyEl && vbWrap && priceCol) {
                if (!storePriceRow) {
                    storePriceRow = document.createElement('div');
                    storePriceRow.className = 'sf-store-price-row';
                    bodyEl.appendChild(storePriceRow);
                }
                storePriceRow.appendChild(vbWrap);
                storePriceRow.appendChild(priceCol);
            }

            // Nunca se muestra rareza. Solo replicamos las placas operativas que
            // ya vengan en los datos de tienda (¡Nuevo!, oferta, termina hoy…).
            const flagEl = modal.querySelector('.lb-prize-flag');
            const operationalBadge = _sfStoreOperationalBadge(pc)
                || (pricing && pricing.has_offer ? { text: 'OFERTA', variant: 'discount' } : null);
            if (flagEl && cardEl) {
                flagEl.classList.toggle('hidden', !operationalBadge);
                if (operationalBadge) {
                    flagEl.textContent = operationalBadge.text;
                    flagEl.dataset.plateVariant = operationalBadge.variant;
                    const badgeBg = _sfStoreHex(operationalBadge.bg);
                    const badgeColor = _sfStoreHex(operationalBadge.color);
                    flagEl.style.removeProperty('--sf-badge-bg');
                    flagEl.style.removeProperty('--sf-badge-color');
                    if (badgeBg) flagEl.style.setProperty('--sf-badge-bg', badgeBg);
                    if (badgeColor) flagEl.style.setProperty('--sf-badge-color', badgeColor);
                    const visualHost = cardEl.firstElementChild;
                    if (visualHost && flagEl.parentNode !== visualHost) visualHost.appendChild(flagEl);
                }
            }

            modal.classList.remove('hidden');
            requestAnimationFrame(() => modal.classList.add('active'));
            document.body.style.overflow = 'hidden';
        }

        function closePrizeModal() {
            const modal = document.getElementById('prizeShowcaseModal');
            if (!modal) return;
            modal.classList.remove('active');
            document.body.style.overflow = '';
            setTimeout(() => modal.classList.add('hidden'), 340);
        }

        // ===== Security Hash Transparency Modal =====
        function openSecurityHashModal() {
            const modal = document.getElementById('securityHashModal');
            if (!modal) return;
            modal.classList.remove('hidden');
            requestAnimationFrame(() => modal.classList.add('sh-visible'));
        }

        function closeSecurityHashModal() {
            const modal = document.getElementById('securityHashModal');
            if (!modal) return;
            modal.classList.remove('sh-visible');
            setTimeout(() => modal.classList.add('hidden'), 300);
        }

        function copySecurityHash() {
            const el = document.getElementById('securityHashValue');
            const btn = document.getElementById('securityHashCopy');
            if (!el) return;
            const text = (el.innerText || '').trim();
            const done = () => {
                if (!btn) return;
                btn.classList.add('sh-copy--done');
                const lbl = btn.querySelector('span');
                const prev = lbl ? lbl.textContent : '';
                if (lbl) lbl.textContent = '¡Copiado!';
                setTimeout(() => { btn.classList.remove('sh-copy--done'); if (lbl) lbl.textContent = prev; }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(() => {});
            } else {
                const ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(ta);
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closePrizeModal(); closeSecurityHashModal(); }
        });

        // =================================================================
        //  MODAL "AUDITORÍA & BLOCKCHAIN" — Estilo manga/cómic.
        //  Cabecera: tarjetas minimalistas con los datos públicos del sorteo.
        //  Cuerpo: diagrama vertical paso a paso (ingredientes → HMAC →
        //  decimal → módulo → validación) que recalcula el resultado en el
        //  navegador con el mismo motor provably fair del servidor,
        //  ramificando expulsados/redibujos y adaptándose a N ganadores.
        // =================================================================
        const _ADX_MAX_TRACE_ATTEMPTS = 200;

        function _adxCard(label, valueHtml, mod) {
            return `<div class="sf-adx-card${mod ? ' ' + mod : ''}">
                <span class="sf-adx-card-label">${label}</span>
                <div class="sf-adx-card-value">${valueHtml}</div>
            </div>`;
        }

        function _adxMono(v) {
            return `<code class="sf-adx-mono">${escapeHtml(String(v))}</code>`;
        }

        const _ADX_ARROW_SVG = '<svg class="sf-adx-arrow-svg" width="23" height="27" viewBox="0 0 26 30" fill="none" aria-hidden="true"><path d="M13 2v18" stroke-width="5" stroke-linecap="round"/><path d="M4 16l9 11 9-11" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        function _adxArrow(text) {
            return `<div class="sf-adx-arrow" aria-hidden="true">${_ADX_ARROW_SVG}${text ? `<span class="sf-adx-arrow-txt">${text}</span>` : ''}</div>`;
        }

        // Recalcula TODO el sorteo (quemas + premios) con trazas por intento,
        // usando el mismo motor determinista de "Ver Suplentes".
        async function _adxBuildTrace(base) {
            const masterSeed = await _scnSha256Hex(base.seed + base.blockHash);
            const maxTickets = base.line.length;
            const acceptLimit = _SCN_RAND_SPACE - (_SCN_RAND_SPACE % maxTickets);
            const valid = new Map();
            base.line.forEach(function (p, t) { if (p) valid.set(t, p); });
            const totalSlots = base.burnCount + base.winnersCount;
            const slots = [];
            for (let i = 0; i < totalSlots; i++) {
                if (valid.size === 0) break;
                const attempts = [];
                let ticket = -1, attempt = 0, dec = 0;
                while (attempt < _ADX_MAX_TRACE_ATTEMPTS) {
                    const label = (attempt === 0) ? ('salt_index_' + i) : ('salt_index_' + i + '_r' + attempt);
                    const hex = await _scnHmacHex(masterSeed, label);
                    dec = parseInt(hex.slice(0, 8), 16);
                    attempt++;
                    const rec = { label: label, hex: hex, hex8: hex.slice(0, 8), dec: dec };
                    if (dec >= acceptLimit) {
                        rec.status = 'bias';
                        attempts.push(rec);
                        continue;
                    }
                    rec.cand = dec % maxTickets;
                    if (!valid.has(rec.cand)) {
                        rec.status = base.expelled.has(rec.cand) ? 'expelled'
                            : (base.line[rec.cand] ? 'taken' : 'hole');
                        rec.user = base.line[rec.cand] || null;
                        attempts.push(rec);
                        continue;
                    }
                    rec.status = 'ok';
                    rec.user = valid.get(rec.cand);
                    attempts.push(rec);
                    ticket = rec.cand;
                    break;
                }
                if (ticket < 0) {
                    // Guardarraíl determinista del servidor (caso patológico).
                    const remaining = Array.from(valid.keys()).sort((a, b) => a - b);
                    ticket = remaining[dec % remaining.length];
                }
                const pick = base.line[ticket] || valid.get(ticket) || null;
                const uid = pick ? parseInt(pick.user_id, 10) : -1;
                Array.from(valid.entries()).forEach(function (ent) {
                    if (parseInt(ent[1].user_id, 10) === uid) valid.delete(ent[0]);
                });
                slots.push({
                    slot: i,
                    isBurn: i < base.burnCount,
                    position: i - base.burnCount + 1,
                    ticket: ticket,
                    user: pick,
                    attempts: attempts
                });
            }
            return { masterSeed: masterSeed, slots: slots, maxTickets: maxTickets };
        }

        function _adxUserCardHtml(user, ticket) {
            const name = _scnUserName(user);
            return `<div class="sf-adx-winner">
                <img class="sf-adx-winner-avatar" src="${escapeHtml(_scnAvatar(user))}" alt="" loading="lazy">
                <div class="sf-adx-winner-info">
                    <span class="sf-adx-winner-tag">🏆 ¡TICKET GANADOR #${ticket}!</span>
                    <span class="sf-adx-winner-name">${escapeHtml(name)}</span>
                </div>
            </div>`;
        }

        // Diagrama de UN slot (quema o premio): cadena de intentos con
        // ramificaciones (expulsado / anti-sesgo / ya premiado) y desenlace.
        function _adxSlotHtml(slotTrace, base, maxTickets) {
            const isBurn = slotTrace.isBurn;
            const head = isBurn
                ? `<div class="sf-adx-slot-head sf-adx-slot-head--burn">🔥 QUEMA ${slotTrace.slot + 1}<span class="sf-adx-slot-sub">elimina un ticket — sin premio</span></div>`
                : `<div class="sf-adx-slot-head">🏆 PREMIO ${slotTrace.position}<span class="sf-adx-slot-sub">mensaje: ${_adxMono('salt_index_' + slotTrace.slot)}</span></div>`;

            let html = `<section class="sf-adx-slot${isBurn ? ' sf-adx-slot--burn' : ''}">${head}`;

            slotTrace.attempts.forEach(function (a, idx) {
                if (idx > 0) {
                    html += _adxArrow(`🔄 Redibujo automático ${_adxMono(a.label)}`);
                }
                html += `<div class="sf-adx-step sf-adx-step--calc">
                    <span class="sf-adx-step-tag">🌀 MEZCLA CRIPTOGRÁFICA</span>
                    <div class="sf-adx-formula">HMAC-SHA256(mezcla, ${_adxMono(a.label)}) = ${_adxMono(a.hex)}</div>
                </div>`;
                html += _adxArrow('primeros 8 caracteres');
                html += `<div class="sf-adx-step sf-adx-step--calc">
                    <span class="sf-adx-step-tag">🔢 CONVERSIÓN DECIMAL</span>
                    <div class="sf-adx-formula">${_adxMono(a.hex8)} (hex) = <strong>${a.dec.toLocaleString('es-PE')}</strong></div>
                </div>`;
                if (a.status === 'bias') {
                    html += _adxArrow();
                    html += `<div class="sf-adx-step sf-adx-branch sf-adx-branch--bias">
                        <span class="sf-adx-branch-badge">⚖️ ¡FUERA DE RANGO!</span>
                        <p>Número fuera del rango justo del módulo: se descarta y se relanza para mantener 1/N exacto.</p>
                    </div>`;
                    return;
                }
                html += _adxArrow();
                html += `<div class="sf-adx-step sf-adx-step--calc">
                    <span class="sf-adx-step-tag">➗ EL MÓDULO</span>
                    <div class="sf-adx-formula">${a.dec.toLocaleString('es-PE')} mod ${maxTickets} = <span class="sf-adx-ticket-chip">🎟️ TICKET #${a.cand}</span></div>
                </div>`;
                html += _adxArrow();
                if (a.status === 'expelled') {
                    html += `<div class="sf-adx-step sf-adx-branch sf-adx-branch--expelled">
                        <span class="sf-adx-branch-badge">❌ ¡TICKET EXPULSADO!</span>
                        <p>El ticket <strong>#${a.cand}</strong> estaba anulado desde el cierre: el tiro no vale.</p>
                    </div>`;
                } else if (a.status === 'taken') {
                    const nm = a.user ? escapeHtml(_scnUserName(a.user)) : '';
                    html += `<div class="sf-adx-step sf-adx-branch sf-adx-branch--taken">
                        <span class="sf-adx-branch-badge">🎁 ¡YA PREMIADO!</span>
                        <p>El ticket <strong>#${a.cand}</strong>${nm ? ' (' + nm + ')' : ''} ya salió sorteado: se relanza para no repetir.</p>
                    </div>`;
                } else if (a.status === 'hole') {
                    html += `<div class="sf-adx-step sf-adx-branch sf-adx-branch--expelled">
                        <span class="sf-adx-branch-badge">🪑 ¡ASIENTO VACÍO!</span>
                        <p>El ticket <strong>#${a.cand}</strong> no está ocupado: el tiro no vale.</p>
                    </div>`;
                } else if (isBurn) {
                    html += `<div class="sf-adx-step sf-adx-branch sf-adx-branch--burned">
                        <span class="sf-adx-branch-badge">🔥 ¡TICKET QUEMADO!</span>
                        <p>El ticket <strong>#${a.cand}</strong>${a.user ? ' (' + escapeHtml(_scnUserName(a.user)) + ')' : ''} queda eliminado. Sin premio.</p>
                    </div>`;
                } else {
                    html += _adxUserCardHtml(a.user, a.cand);
                }
            });

            html += '</section>';
            return html;
        }

        async function _adxRenderFlow(container) {
            const base = _scnPrepare();
            if (!base) {
                container.innerHTML = '<div class="sf-adx-locked">🔒 La verificación gráfica se desbloquea al terminar el sorteo, cuando la semilla secreta se revela.</div>';
                return;
            }
            try {
                const trace = await _adxBuildTrace(base);
                let html = '<h3 class="sf-adx-flow-title">⚡ ASÍ SALIÓ EL RESULTADO</h3>';

                html += `<div class="sf-adx-step sf-adx-step--ingredients">
                    <span class="sf-adx-step-tag">🧪 PASO 1 · LOS INGREDIENTES</span>
                    <div class="sf-adx-ing-row">
                        <div class="sf-adx-ing"><span>🔑 Semilla revelada</span>${_adxMono(base.seed)}</div>
                        <div class="sf-adx-ing-plus">+</div>
                        <div class="sf-adx-ing"><span>⛓️ Hash del bloque BSC</span>${_adxMono(base.blockHash)}</div>
                    </div>
                </div>`;
                html += _adxArrow('se pegan y se pasan por SHA-256');
                html += `<div class="sf-adx-step sf-adx-step--calc">
                    <span class="sf-adx-step-tag">🌀 MEZCLA MAESTRA</span>
                    <div class="sf-adx-formula">sha256(semilla + hash) = ${_adxMono(trace.masterSeed)}</div>
                </div>`;
                html += _adxArrow();

                trace.slots.forEach(function (s, idx) {
                    if (idx > 0) html += _adxArrow('siguiente sorteo');
                    html += _adxSlotHtml(s, base, trace.maxTickets);
                });

                container.innerHTML = html;
            } catch (e) {
                container.innerHTML = '<div class="sf-adx-locked">⚠️ No se pudo generar la verificación en este navegador. Los datos públicos de arriba siguen siendo verificables a mano con la guía de transparencia.</div>';
            }
        }

        function openMainAuditModal() {
            const data = (state && state.lastData) ? state.lastData : _lastStatusData;
            const tr = (state && state.transparency) ? state.transparency : (data && data.transparency ? data.transparency : null);
            const audit = (data && data.audit) ? data.audit : null;

            const serverSeedHash = (tr && tr.server_seed_hash) ? String(tr.server_seed_hash)
                : (audit && audit.server_seed_hash) ? String(audit.server_seed_hash)
                : (data && (data.server_seed_hash || data.server_hash)) ? String(data.server_seed_hash || data.server_hash)
                    : null;
            const serverSeedSecret = (tr && tr.server_seed_secret) ? String(tr.server_seed_secret)
                : (audit && audit.server_seed) ? String(audit.server_seed) : null;
            const blockNumber = (tr && (tr.bsc_block_number || tr.bsc_block_target)) ? String(tr.bsc_block_number || tr.bsc_block_target)
                : (data && data.bsc_block_target) ? String(data.bsc_block_target) : null;
            const blockHash = (tr && tr.bsc_block_hash) ? String(tr.bsc_block_hash) : null;

            const frozenRaw = (audit && audit.frozen_at) ? String(audit.frozen_at) : null;
            const frozen = (() => {
                if (!frozenRaw) return null;
                const d = new Date(frozenRaw.replace(' ', 'T') + 'Z');
                if (Number.isNaN(d.getTime())) return { date: frozenRaw, time: '' };
                return {
                    date: d.toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }),
                    time: d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
                };
            })();

            const totalTickets = audit ? (parseInt(audit.max_tickets, 10) || 0) : 0;
            const expelledList = audit && Array.isArray(audit.expelled_tickets)
                ? audit.expelled_tickets.map(Number).sort(function (a, b) { return a - b; }) : [];

            const blockLink = blockNumber ? `https://bscscan.com/block/${blockNumber}` : null;
            const bscSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';

            let cards = '';
            if (totalTickets > 0) {
                const expelledHtml = expelledList.length
                    ? expelledList.map(function (t) { return `<span class="sf-adx-chip sf-adx-chip--out">🎟️ #${t} ✕</span>`; }).join('')
                    : '<span class="sf-adx-chip sf-adx-chip--ok">Ninguno ✅</span>';
                cards += _adxCard('🎟️ Tickets inscritos', `<strong class="sf-adx-big">${totalTickets}</strong>`);
                cards += _adxCard('❌ Tickets expulsados / anulados', `<div class="sf-adx-chips">${expelledHtml}</div>`);
            }
            cards += _adxCard('🔒 Huella de la semilla (SHA-256)', serverSeedHash ? _adxMono(serverSeedHash) : '<em>Esperando inicio…</em>', 'sf-adx-card--wide');
            cards += _adxCard('🔑 Semilla revelada', serverSeedSecret ? _adxMono(serverSeedSecret) : '<em>🔒 Se revela al finalizar el sorteo</em>', 'sf-adx-card--wide');
            cards += _adxCard('⛓️ Bloque BSC target',
                `<strong class="sf-adx-big">${blockNumber ? '#' + Number(blockNumber).toLocaleString() : 'Pendiente…'}</strong>` +
                (blockLink ? ` <a href="${escapeHtml(blockLink)}" target="_blank" rel="noopener" class="sf-adx-bsc-ico" title="Ver el bloque en BscScan" aria-label="Ver el bloque en BscScan">${bscSvg}</a>` : ''));
            if (frozen) {
                cards += _adxCard('❄️ Lista congelada el',
                    `<span class="sf-adx-date">🗓️ ${escapeHtml(frozen.date)}${frozen.time ? ` <span class="sf-adx-date-time">· ${escapeHtml(frozen.time)}</span>` : ''}</span>`,
                    'sf-adx-card--fit');
            }
            if (blockHash) {
                cards += _adxCard('🧾 Hash del bloque BSC', _adxMono(blockHash), 'sf-adx-card--wide');
            }

            const html = `<div class="sf-adx">
                <div class="sf-adx-cards">${cards}</div>
                <div id="sfAdxFlow" class="sf-adx-flow"><div class="sf-adx-locked">⏳ Generando verificación paso a paso…</div></div>
            </div>`;

            Swal.fire({
                title: '',
                html,
                background: 'rgba(255, 255, 255, 0.97)',
                color: '#1e293b',
                confirmButtonText: 'Cerrar',
                confirmButtonColor: '#6366f1',
                showCloseButton: true,
                width: 'min(760px, 94vw)',
                customClass: {
                    popup: 'tr-swal-popup sf-adx-popup',
                    title: 'tr-swal-title',
                    confirmButton: 'tr-swal-btn',
                    container: 'tr-swal-backdrop'
                },
                didOpen: () => {
                    const flow = document.getElementById('sfAdxFlow');
                    if (flow) _adxRenderFlow(flow);
                }
            });
        }

        function openWinnerModal() { document.getElementById('winnerModal').classList.remove('hidden'); launchConfetti(); }
        function closeWinnerModal() { document.getElementById('winnerModal').classList.add('hidden'); }
        function openFairnessModal() { document.getElementById('fairnessModal').classList.remove('hidden'); }
        function closeFairnessModal() { document.getElementById('fairnessModal').classList.add('hidden'); }
        // Las caras HD del Doble o Nada se conservan; los iconos compactos de
        // Bananos usan el asset local oficial.
        const SF_ASSET_COIN_HEADS = 'https://res.cloudinary.com/dq3xsjgwi/image/upload/v1769835633/c_fj4zze.webp';
        const SF_ASSET_COIN_TAILS = 'https://res.cloudinary.com/dq3xsjgwi/image/upload/v1769835628/s_jhmuvv.webp';
        const SF_ASSET_BANANOS    = '/assets/img/moneda.webp';

        // Imagen real de la moneda (Cara/Sello) — reemplaza al SVG genérico.
        function _sfCoinImg(isHeads, size) {
            const s = size || 56;
            return '<img class="sf-coin-img" src="' + (isHeads ? SF_ASSET_COIN_HEADS : SF_ASSET_COIN_TAILS) +
                   '" width="' + s + '" height="' + s + '" alt="' + (isHeads ? 'Cara' : 'Sello') + '" loading="lazy" />';
        }

        // Hint visible para espectadores: qué pre-eligió el GANADOR mientras
        // "decide". Solo devuelve algo si la pre-elección es válida y se va a
        // aplicar de verdad (respeta la config del sorteo y la completitud del
        // Doble o Nada), para no mostrar algo que luego caerá en premio original.
        function _sfWinnerPreChoiceHint(w, data) {
            if (!w) return null;
            if (data && data.allow_pre_choice === false) return null;
            const pre = w.pre_choice ? String(w.pre_choice).toLowerCase() : '';
            if (!pre) return null;

            // DRAMATISMO (P7): si el ganador en vivo canceló su preselección
            // ("prefiero elegir manualmente"), lo mostramos a los espectadores.
            if (w.pre_apply_cancelled === true || w.pre_apply_cancelled === 1) {
                return { emoji: '\u26A1', text: '\u00A1Cancel\u00F3 su preselecci\u00F3n! Decide en vivo\u2026', cancelled: true };
            }

            // Indicador de cuenta atrás de auto-aplicación. Sin ventana activa
            // (pre-elección incompleta/no aplicable) el ganador decide en vivo
            // con todo su tiempo: no mostramos texto de pre-elección.
            const sec = (w.pre_apply_seconds !== undefined && w.pre_apply_seconds !== null)
                ? parseInt(w.pre_apply_seconds, 10) : null;
            const applying = (sec !== null && sec >= 0);
            if (!applying) return null;
            const verb = 'Aplicando';
            const tail = (sec > 0) ? (' en ' + sec + 's') : '';

            if (pre === 'original') {
                const preItemName = (w.pre_pool_item && w.pre_pool_item.name) ? String(w.pre_pool_item.name) : 'Premio Original';
                return { emoji: '\u{1F381}', text: verb + ': ' + preItemName + tail, applying: applying };
            }
            if (pre === 'cashback') {
                const allowCb = data ? (data.allow_cashback === true || data.allow_cashback === 1) : true;
                const pct = data ? parseInt(data.cashback_percent || 0, 10) : 1;
                if (!allowCb || !(pct > 0)) return null;
                return { emoji: _sfBananosImg(16), text: verb + ': Cashback en Bananos' + tail, applying: applying };
            }
            if (pre === 'gamble') {
                const allowDouble = data ? (data.allow_double_or_nothing === true || data.allow_double_or_nothing === 1) : true;
                if (!allowDouble) return null;
                const anim = w.pre_gamble_anim ? String(w.pre_gamble_anim).toLowerCase() : '';
                if (!anim) return null; // incompleto → caerá en original, no marcamos
                let suffix = '';
                if (anim === 'coin') {
                    const side = w.pre_gamble_side ? String(w.pre_gamble_side).toLowerCase() : '';
                    if (side === 'heads') suffix = ' (Cara)';
                    else if (side === 'tails') suffix = ' (Sello)';
                    else return null; // moneda sin lado → incompleto
                }
                const base = applying ? 'Aplicando DOBLE O NADA' : '\u00A1Eligi\u00F3 DOBLE O NADA!';
                return { emoji: '\u{1F525}', text: base + suffix + tail, applying: applying };
            }
            return null;
        }

        // Imagen del ícono de Bananos (moneda interna).
        function _sfBananosImg(size) {
            const s = size || 44;
            return '<img class="sf-bananos-img" src="' + SF_ASSET_BANANOS +
                   '" width="' + s + '" height="' + s + '" alt="Bananos" loading="lazy" />';
        }

        // Íconos gamer (ruleta / tragamonedas): símbolos knockout del sprite
        // local (#sf-icon-roulette, #sf-icon-slot), blancos vía currentColor.
        function _sfGamerSvg(kind, size) {
            const s = size || 52;
            return '<svg class="sf-gamer-svg sf-gamer-svg--' + kind + '" width="' + s + '" height="' + s +
                   '" aria-hidden="true" focusable="false"><use href="#sf-icon-' + kind + '"></use></svg>';
        }

        function _sfRouletteSvg(size) {
            return _sfGamerSvg('roulette', size);
        }

        function _sfSlotMachineSvg(size) {
            return _sfGamerSvg('slot', size);
        }

        async function showWinnerDetails(index) {
            const winners = (state && Array.isArray(state.winners)) ? state.winners : [];
            const idx = parseInt(index, 10);
            const w = (Number.isFinite(idx) && idx >= 0) ? (winners[idx] || null) : null;
            if (!w) {
                Swal.fire('Sin datos', 'No se encontró información para este ganador.', 'info');
                return;
            }

            const choice = String(w.choice || '');
            const isGambler = choice === 'double_won' || choice === 'double_lost' || choice === 'gamble_pending';
            const isOriginal = choice === 'original';
            const isCashback = choice === 'cashback';

            const statusData = (state && state.lastData) ? state.lastData : _lastStatusData;
            const cbPct = statusData ? (parseInt(statusData.cashback_percent, 10) || 0) : 0;
            const wp = _sfWinnerDisplayPrize(w) || w.prize || null;

            // Panel de decisiones: las 3 opciones de La Vitrina, en verde la elegida.
            const coinImg = (cls) => `<img class="${cls}" src="/assets/img/moneda.webp" alt="" loading="lazy">`;
            const wdOpt = (icon, label, shortLabel, chosen) => `<div class="tr-wd-opt${chosen ? ' is-chosen' : ''}">
                <span class="tr-wd-opt-icon">${icon}</span>
                <span class="tr-wd-opt-label tr-wd-opt-label--full">${label}</span>
                <span class="tr-wd-opt-label tr-wd-opt-label--short">${shortLabel}</span>
            </div>`;
            let html = `<div class="tr-detail-modal">
                <div class="tr-wd-opts">
                    ${wdOpt('🎁', 'Premio Original', 'Original', isOriginal)}
                    ${wdOpt(coinImg('tr-wd-opt-img'), cbPct > 0 ? `Cashback ${cbPct}%` : 'Cashback', 'Cashback', isCashback)}
                    ${wdOpt('🎲', 'Doble o Nada', 'Doble', isGambler)}
                </div>`;

            if ((isOriginal || choice === 'double_won') && wp) {
                const wdAssetCls = _sfStoreTypeKey(wp) === 'asset' ? ' tr-wd-store-prize--asset' : '';
                html += '<div class="tr-wd-store-prize' + wdAssetCls + '">' + _sfPrizeCardHtml(wp, 'sf-store-card--detail') + '</div>';
            } else if (isOriginal) {
                html += `<div class="tr-wd-simple">Conservó su premio original. 🏆</div>`;
            }

            if (isCashback) {
                const cbAmount = _sfAutomaticCreditCoins(w);
                html += `<div class="tr-wd-cashback">
                    ${coinImg('tr-wd-cashback-img')}
                    <div class="tr-wd-cashback-info">
                        <div class="tr-wd-cashback-pct">Cashback ${cbPct > 0 ? cbPct + '%' : ''}</div>
                        <div class="tr-wd-cashback-amount">✅ ${_sfFormatCoins(cbAmount)} Bananos acreditados automáticamente</div>
                    </div>
                </div>`;
            }

            // Diagrama paso a paso de la resolución del Doble o Nada.
            if (isGambler) {
                const mode = String(w.selected_animation_mode || CONFIG?.anims?.gamble || '').toLowerCase();
                const isCoin = mode === 'coin';
                const isRoulette = mode === 'roulette';
                const sideRaw = String(w.coin_choice || w.prediction_side || '').toLowerCase();
                const gr = getNormalizedGambleResult(w.gamble_result);
                const outcomeSideRaw = gr ? String(gr.outcome_side || gr.outcomeSide || gr.outcome || '').toLowerCase() : '';
                const won = choice === 'double_won';
                const lost = choice === 'double_lost';
                const hasOutcome = outcomeSideRaw === 'heads' || outcomeSideRaw === 'tails';

                const targetBlock = (w.gamble_block_target !== undefined && w.gamble_block_target !== null)
                    ? w.gamble_block_target
                    : (gr ? (gr.target_block || gr.targetBlock || gr.target || null) : null);
                const blockHash = w.gamble_block_hash || (gr ? (gr.block_hash || gr.blockHash || null) : null);
                // Huella (SHA-256) publicada antes de la apuesta y semilla revelada
                // después; solo la revelada permite reproducir el HMAC.
                const seedHash = w.gamble_server_hash
                    || (gr ? (gr.server_seed_hash || gr.serverSeedHash || gr.server_hash || gr.serverHash || null) : null);
                const realSeed = w.gamble_server_seed
                    || (gr ? (gr.server_seed || gr.serverSeed || null) : null);
                const winnerId = (w.winner_id !== undefined && w.winner_id !== null)
                    ? w.winner_id
                    : (gr ? (gr.winner_id || gr.winnerId || null) : null);

                const wdArrow = (txt) => `<div class="tr-wd-arrow" aria-hidden="true"><span class="tr-wd-arrow-glyph">↓</span>${txt ? `<span class="tr-wd-arrow-txt">${txt}</span>` : ''}</div>`;
                const bscSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
                const gameLabel = isCoin ? 'la Moneda' : isRoulette ? 'la Ruleta' : 'su apuesta';

                // Etiquetas de lado/color según el modo (PAR → Cara/Rojo, IMPAR → Sello/Negro).
                const isRB = isRoulette && (sideRaw === 'red' || sideRaw === 'black');
                const evenLabel = isRB ? 'ROJO' : 'CARA';
                const oddLabel = isRB ? 'NEGRO' : 'SELLO';
                const pickLabel = isRB
                    ? (sideRaw === 'red' ? 'ROJO' : 'NEGRO')
                    : (sideRaw === 'tails' ? 'SELLO' : 'CARA');
                const outLabel = hasOutcome ? (outcomeSideRaw === 'heads' ? evenLabel : oddLabel) : null;
                // Chip visual del lado: ficha SVG roja/negra en ruleta, moneda en coin.
                const wdDot = (isEven) => isRB
                    ? `<svg class="tr-wd-dot tr-wd-dot--${isEven ? 'red' : 'black'}" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10.5"/><circle class="tr-wd-dot-shine" cx="8.6" cy="8.4" r="2.6"/></svg>`
                    : `<span class="tr-wd-dot tr-wd-dot--coin">${_sfCoinImg(isEven, 20)}</span>`;
                const wdSide = (isEven) => `<span class="tr-wd-side">${wdDot(isEven)}<strong>${isEven ? evenLabel : oddLabel}</strong></span>`;

                // Recálculo en vivo del HMAC (Key = semilla, Message = semilla +
                // hash del bloque + winner id), igual que el servidor.
                let hmacHex = null;
                let hmacDec = null;
                let auditMsg = null;
                if (realSeed && blockHash && winnerId !== null && winnerId !== undefined) {
                    auditMsg = String(realSeed) + String(blockHash) + String(winnerId);
                    try {
                        hmacHex = await _scnHmacHex(String(realSeed), auditMsg);
                        hmacDec = parseInt(hmacHex.slice(0, 8), 16);
                    } catch (e) { hmacHex = null; hmacDec = null; }
                }

                html += `<div class="tr-wd-flow">
                    <div class="tr-wd-flow-title">Así se resolvió ${gameLabel}</div>`;

                // Paso 1 · La regla de la jugada.
                if (sideRaw) {
                    const pickIsEven = pickLabel === evenLabel;
                    html += `<div class="tr-wd-box">
                        <div class="tr-wd-box-tag tr-wd-box-tag--ribbon">📋 Paso 1 · La regla de la jugada</div>
                        <div class="tr-wd-rule">Elección: ${wdSide(pickIsEven)}</div>
                        <div class="tr-wd-rule-map">
                            <span class="tr-wd-rule-chip${pickIsEven ? ' is-picked' : ''}">${wdDot(true)}PAR → ${evenLabel}</span>
                            <span class="tr-wd-rule-chip${!pickIsEven ? ' is-picked' : ''}">${wdDot(false)}IMPAR → ${oddLabel}</span>
                        </div>
                        <p class="tr-wd-note">Gana si el decimal final es <strong>${pickLabel === evenLabel ? 'PAR' : 'IMPAR'}</strong>.</p>
                    </div>` + wdArrow();
                }

                // Paso 2 · Semilla del gamble.
                if (seedHash || realSeed) {
                    html += `<div class="tr-wd-box">
                        <div class="tr-wd-box-tag tr-wd-box-tag--ribbon">🔑 Paso 2 · Semilla del gamble</div>
                        ${seedHash ? `<div class="tr-wd-sub">Huella de la semilla (SHA-256)</div>
                        <div class="tr-detail-tech-hash tr-detail-tech-hash--blue">${escapeHtml(String(seedHash))}</div>
                        <p class="tr-wd-note">Publicada antes de la apuesta.</p>` : ''}
                        ${realSeed ? `<div class="tr-wd-sub${seedHash ? ' tr-wd-sub--gap' : ''}">Semilla revelada</div>
                        <div class="tr-detail-tech-hash tr-detail-tech-hash--amber">${escapeHtml(String(realSeed))}</div>
                        <p class="tr-wd-note">Fijada por el sistema antes de la apuesta.</p>` : `<p class="tr-wd-note">La semilla se revela al resolver la apuesta.</p>`}
                    </div>` + wdArrow();
                }

                // Paso 3 · Bloque objetivo BSC.
                if (targetBlock || blockHash) {
                    const bscLink = targetBlock ? `https://bscscan.com/block/${targetBlock}` : null;
                    html += `<div class="tr-wd-box">
                        <div class="tr-wd-box-tag tr-wd-box-tag--ribbon">⛓️ Paso 3 · Bloque objetivo BSC</div>
                        ${targetBlock ? `<div class="tr-wd-block">#${Number(targetBlock).toLocaleString()}${bscLink ? ` <a href="${escapeHtml(bscLink)}" target="_blank" rel="noopener" class="tr-bsc-link">${bscSvg}Ver en BscScan</a>` : ''}</div>` : ''}
                        ${blockHash ? `<div class="tr-detail-tech-hash tr-detail-tech-hash--blue">${escapeHtml(String(blockHash))}</div>` : ''}
                        <p class="tr-wd-note">Nadie conoce su hash hasta que la blockchain lo mina.</p>
                    </div>` + wdArrow('semilla + hash del bloque deciden el resultado');
                }

                // Paso 4 · Fórmula HMAC y conversión decimal, en cuadros de proceso.
                if (auditMsg) {
                    html += `<div class="tr-wd-box">
                        <div class="tr-wd-box-tag tr-wd-box-tag--ribbon">🌀 Paso 4 · Cadena a verificar (HMAC-SHA256)</div>
                        <div class="tr-detail-tech-hash tr-detail-tech-hash--amber">${escapeHtml(auditMsg)}</div>
                        ${hmacHex ? `<div class="tr-wd-eq">HMAC-SHA256 = <span class="tr-wd-hexchip">${escapeHtml(hmacHex)}</span></div>` : ''}
                    </div>` + wdArrow();

                    if (hmacHex && Number.isFinite(hmacDec)) {
                        html += `<div class="tr-wd-box">
                            <div class="tr-wd-box-tag">🔢 PRIMEROS 8 HEX → NÚMERO DECIMAL</div>
                            <div class="tr-wd-eq"><span class="tr-wd-hexchip">${escapeHtml(hmacHex.slice(0, 8))}</span> = <strong>${hmacDec.toLocaleString('es-PE')}</strong></div>
                        </div>` + wdArrow();

                        const isEven = hmacDec % 2 === 0;
                        html += `<div class="tr-wd-box">
                            <div class="tr-wd-box-tag">⚖️ EVALUACIÓN PAR / IMPAR</div>
                            <div class="tr-wd-eq">Decimal <strong>${hmacDec.toLocaleString('es-PE')}</strong> es <strong>${isEven ? 'PAR' : 'IMPAR'}</strong></div>
                            <div class="tr-wd-eq">Cayó en ${wdSide(isEven)}</div>
                        </div>` + wdArrow();
                    }
                }

                // Paso 5 · Resultado final: elección vs caída + veredicto.
                html += `<div class="tr-wd-box tr-wd-box--final">
                    <div class="tr-wd-box-tag tr-wd-box-tag--ribbon">🏁 Paso 5 · Resultado final</div>`;
                if (isCoin && sideRaw) {
                    const pickIsHeads = sideRaw === 'heads';
                    const outcomeIsHeads = outcomeSideRaw === 'heads';
                    const coinSvg = (isHeads) => _sfCoinImg(isHeads, 64);
                    html += `<div class="tr-detail-coins">
                        <div class="tr-detail-coin-group">
                            <div class="tr-detail-coin-label">Eligió</div>
                            <div class="tr-detail-coin ${pickIsHeads ? 'tr-detail-coin--heads' : 'tr-detail-coin--tails'}">${coinSvg(pickIsHeads)}</div>
                        </div>
                        ${hasOutcome ? `
                        <div class="tr-detail-coin-vs">vs</div>
                        <div class="tr-detail-coin-group">
                            <div class="tr-detail-coin-label">Cayó</div>
                            <div class="tr-detail-coin ${outcomeIsHeads ? 'tr-detail-coin--heads' : 'tr-detail-coin--tails'}">${coinSvg(outcomeIsHeads)}</div>
                        </div>` : ''}
                    </div>`;
                } else if (isRB) {
                    const outcomeColor = outcomeSideRaw === 'heads' ? 'red' : 'black';
                    const chip = (side) => `<span class="tr-detail-rchip tr-detail-rchip--${side}">${_sfRouletteSideLabel(side)}</span>`;
                    html += `<div class="tr-detail-coins">
                        <div class="tr-detail-coin-group">
                            <div class="tr-detail-coin-label">Eligió</div>
                            ${chip(sideRaw)}
                        </div>
                        ${hasOutcome ? `
                        <div class="tr-detail-coin-vs">vs</div>
                        <div class="tr-detail-coin-group">
                            <div class="tr-detail-coin-label">Cayó en</div>
                            ${chip(outcomeColor)}
                        </div>` : ''}
                    </div>`;
                }
                if (outLabel && sideRaw) {
                    html += `<div class="tr-wd-eq tr-wd-eq--vs">Eligió ${wdSide(pickLabel === evenLabel)} <span class="tr-wd-eq-vs">vs</span> Cayó en ${wdSide(outLabel === evenLabel)}</div>`;
                }
                html += `<div class="tr-detail-result ${won ? 'tr-detail-result--won' : lost ? 'tr-detail-result--lost' : 'tr-detail-result--pending'}">
                    ${won ? '¡GANÓ EL DOBLE!' : lost ? 'Premio no alcanzado' : 'En curso...'}
                </div>`;
                if (won) {
                    // Botín total: el premio original + su equivalente en Bananos
                    // (rr_deliver_double_win). El monto viene en gamble_result.double_bonus_coins;
                    // si falta (filas antiguas) se usa el valor del premio (pavos / Bananos).
                    const lootName = _sfWinnerPrizeLabel(w);
                    const bonus = _sfAutomaticCreditCoins(w);
                    const bonusTxt = bonus > 0 ? _sfFormatCoins(bonus) : null;
                    const lootImg = (wp && wp.image)
                        ? `<img class="tr-wd-loot-img" src="${escapeHtml(String(wp.image))}" alt="" loading="lazy">`
                        : `<span class="tr-wd-loot-img tr-wd-loot-img--ph">🎁</span>`;
                    html += `<div class="tr-wd-loot">
                        <div class="tr-wd-loot-title">Se llevó todo esto</div>
                        <div class="tr-wd-loot-row">
                            <div class="tr-wd-loot-item tr-wd-loot-item--prize"${wp ? _sfStoreCardAttrs(wp) : ''}>
                                ${lootImg}
                                <div class="tr-wd-loot-txt">
                                    <span class="tr-wd-loot-kicker">Premio original</span>
                                    <span class="tr-wd-loot-name">${escapeHtml(lootName)}</span>
                                </div>
                            </div>
                            <div class="tr-wd-loot-plus">+</div>
                            <div class="tr-wd-loot-item tr-wd-loot-item--coins">
                                ${coinImg('tr-wd-loot-img')}
                                <div class="tr-wd-loot-txt">
                                    <span class="tr-wd-loot-kicker">Acreditación automática</span>
                                    <span class="tr-wd-loot-name">${bonusTxt ? `+${bonusTxt} Bananos acreditados` : 'Bananos equivalentes acreditados'}</span>
                                </div>
                            </div>
                        </div>
                    </div>`;
                }
                html += `</div>`;

                html += `</div>`;
            }

            html += `</div>`;

            Swal.fire({
                title: 'DETALLES DEL PREMIO',
                html,
                background: 'rgba(255, 255, 255, 0.94)',
                color: '#1e293b',
                confirmButtonText: 'CERRAR',
                confirmButtonColor: '#6366f1',
                customClass: {
                    popup: 'tr-swal-popup tr-wd-popup',
                    title: 'tr-swal-title tr-wd-title',
                    confirmButton: 'tr-swal-btn tr-wd-btn',
                    container: 'tr-swal-backdrop'
                }
            });
        }

        // Prueba de Entrega (transparencia pública). Modal limpio: solo estado y,
        // si fue entregado, la imagen de prueba (+ nota opcional). Los datos llegan
        // del backend SOLO cuando el sorteo está completado (ver raffle_status.php).
        function openDeliveryProofModal(index) {
            const winners = (state && Array.isArray(state.winners)) ? state.winners : [];
            const idx = parseInt(index, 10);
            const w = (Number.isFinite(idx) && idx >= 0) ? (winners[idx] || null) : null;
            if (!w || _sfWinnerFulfillment(w).manual_delivery_required !== true) {
                return;
            }

            const delivered = String(w.delivery_status || '') === 'delivered';

            // Soporta varias imágenes (collage apilado vertical, ya que las
            // capturas del juego son horizontales). Compatibilidad con el campo
            // antiguo de una sola imagen.
            let proofUrls = Array.isArray(w.delivery_proof_urls) ? w.delivery_proof_urls.slice() : [];
            if (!proofUrls.length && w.delivery_proof_url) proofUrls = [w.delivery_proof_url];

            // Modal minimalista: solo el estado, la(s) imagen(es) y cerrar.
            // Sin avatar/nombre (ya se intuye desde la tarjeta del ganador).
            let html = `<div class="tr-deliv-modal">
                <div class="tr-deliv-badge ${delivered ? 'is-delivered' : 'is-pending'}">
                    <span class="tr-deliv-dot"></span>
                    ${delivered ? 'Entregado' : 'Pendiente'}
                </div>`;

            if (delivered && proofUrls.length) {
                const imgs = proofUrls.map(u => `<img src="${escapeHtml(u)}" alt="Prueba de entrega del premio" />`).join('');
                html += `<div class="tr-deliv-proof ${proofUrls.length > 1 ? 'is-collage' : ''}">${imgs}</div>`;
            } else if (delivered) {
                html += `<div class="tr-deliv-msg">El premio fue entregado. ¡Gracias por participar!</div>`;
            } else {
                html += `<div class="tr-deliv-msg">La entrega está en proceso. Pronto publicaremos aquí la prueba para total transparencia.</div>`;
            }

            html += `</div>`;

            Swal.fire({
                title: 'Estado del premio',
                html,
                width: '46rem',
                background: 'rgba(255, 255, 255, 0.96)',
                color: '#1e293b',
                confirmButtonText: 'Cerrar',
                confirmButtonColor: '#6366f1',
                customClass: {
                    popup: 'tr-swal-popup tr-deliv-popup',
                    title: 'tr-swal-title',
                    confirmButton: 'tr-swal-btn',
                    container: 'tr-swal-backdrop'
                }
            });
        }
        window.openDeliveryProofModal = openDeliveryProofModal;

        function launchConfetti() { confetti({ particleCount: 150, spread: 80, origin: { y: 0.6 }, colors: ['#eab308', '#ffffff'] }); }

        // Subtle, optimized celebration fired only when a user joins successfully.
        // Scales down on mobile and is skipped entirely under reduced-motion.
        function launchJoinConfetti() {
            if (typeof confetti !== 'function') return;
            try {
                if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                const isMobile = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
                const colors = ['#22c55e', '#fbbf24', '#4ecdc4', '#ffffff'];
                const count = isMobile ? 90 : 150;
                confetti({ particleCount: Math.round(count * 0.45), spread: isMobile ? 70 : 90, startVelocity: 38, scalar: isMobile ? 0.85 : 1, ticks: 180, origin: { y: 0.62 }, colors, disableForReducedMotion: true });
                confetti({ particleCount: Math.round(count * 0.28), angle: 60, spread: 55, startVelocity: 45, ticks: 180, origin: { x: 0, y: 0.7 }, colors, disableForReducedMotion: true });
                confetti({ particleCount: Math.round(count * 0.28), angle: 120, spread: 55, startVelocity: 45, ticks: 180, origin: { x: 1, y: 0.7 }, colors, disableForReducedMotion: true });
            } catch (_) { }
        }
        // ── Near-miss: retención post-sorteo para quienes no ganaron ──
        let nearMissShown = false;
        function showNearMissModal(nm) {
            if (!nm || typeof Swal === 'undefined') return;
            const mine = parseInt(nm.my_ticket, 10);
            const win = parseInt(nm.winning_ticket, 10);
            if (isNaN(mine) || isNaN(win)) return;
            const diff = Math.abs(isNaN(nm.diff) ? (mine - win) : nm.diff);
            let headline;
            if (diff <= 3) headline = '¡Uf, casi!';
            else if (diff <= 10) headline = '¡Estuviste cerca!';
            else headline = '¡Estuviste lejos!';
            const unit = (diff === 1) ? 'ticket' : 'tickets';
            const html =
                '<div class="nm-wrap">' +
                    '<div class="nm-tickets">' +
                        '<div class="nm-ticket nm-ticket--mine"><span class="nm-ticket__label">Tu ticket</span><span class="nm-ticket__num">#' + mine + '</span></div>' +
                        '<div class="nm-vs">vs</div>' +
                        '<div class="nm-ticket nm-ticket--win"><span class="nm-ticket__label">Ticket ganador</span><span class="nm-ticket__num">#' + win + '</span></div>' +
                    '</div>' +
                    '<p class="nm-diff">Te quedaste a <strong>' + diff + '</strong> ' + unit + ' de ganar. ¡Suerte para la próxima!</p>' +
                '</div>';
            Swal.fire({
                title: headline,
                html,
                icon: undefined,
                background: 'rgba(255, 255, 255, 0.96)',
                color: '#1e293b',
                confirmButtonText: 'Ta bien',
                confirmButtonColor: '#6366f1',
                showCloseButton: true,
                allowOutsideClick: true,
                customClass: { popup: 'nm-swal-popup', title: 'nm-swal-title', confirmButton: 'nm-swal-btn' }
            });
        }
        function copyHash() { navigator.clipboard.writeText(document.getElementById('fmServerHash').innerText); alert("Hash copiado"); }

        // ── Smart Join: Cost confirmation + restriction-aware error modals ──
        function _sfShowRestrictionModal(title, emoji, bodyHtml, btnText) {
            Swal.fire({
                title: emoji + ' ' + title,
                html: bodyHtml,
                confirmButtonText: btnText || 'Entendido',
                showCloseButton: false,
                showCancelButton: true,
                cancelButtonText: '<i class="fas fa-arrow-left"></i> Volver',
                customClass: { popup: 'sf-modal-restriction', cancelButton: 'sf-modal-cancel' },
                allowOutsideClick: true
            });
        }

        // ── Bajo Revisión: modal amigable para quien compró por chat ──
        // El sistema no detectó compras web. En vez de bloquear, ofrecemos
        // entrar "bajo revisión": si confirma, entra y el admin lo audita en
        // vivo. Tono amigable pero firme (sin lenguaje de "declaración jurada").
        async function _sfShowDeclarationModal(minDate) {
            const desde = minDate ? minDate : 'el inicio del periodo';
            const result = await Swal.fire({
                title: '\u00BFCompraste por chat?',
                html: '<div class="sf-chat-body">' +
                      '<p class="sf-chat-p">No detectamos compras web tuyas desde el <b>' + desde + '</b>.</p>' +
                      '<p class="sf-chat-p">Si compraste por <b>WhatsApp</b> o <b>Messenger</b>, puedes entrar al sorteo.</p>' +
                      '<div class="sf-chat-warn">' +
                      '\u26A0\uFE0F Si no hiciste ninguna compra, por favor no entres: podr\u00EDas ser <b>expulsado de la sala</b>. ' +
                      'Si reincides varias veces, podr\u00EDas ser <b>baneado de los sorteos</b>.' +
                      '</div>' +
                      '<p class="sf-chat-ok">Si est\u00E1s seguro de que tienes compras, \u00A1adelante!</p>' +
                      '</div>',
                showCloseButton: false,
                showCancelButton: true,
                confirmButtonText: 'Entrar',
                cancelButtonText: 'Salir',
                reverseButtons: true,
                buttonsStyling: false,
                customClass: { popup: 'sf-modal-chat', confirmButton: 'sf-chat-confirm', cancelButton: 'sf-chat-cancel' },
                allowOutsideClick: true
            });
            if (result.isConfirmed) {
                await _sfExecuteJoin({ declared: true });
            }
        }

        // ── Ticket de Bienvenida: chip informativo bajo el botón de inscripción ──
        // Solo se muestra si el backend confirma que el ticket está disponible
        // (primer sorteo del usuario, califica, no consumido) Y aplica a ESTE
        // sorteo (tiene requisitos de compras que el ticket saltaría). Así el
        // aviso siempre va seguido del modal de confirmación al pulsar ENTRAR.
        function _sfRenderWelcomeHint(btn, data) {
            const wt = (data && data.my_welcome_ticket) || {};
            const wrapper = btn.parentElement;
            if (!wrapper) return;
            let hint = wrapper.querySelector('.sf-welcome-hint');
            if (!wt.available || !wt.applies_here) {
                if (hint) hint.hidden = true;
                return;
            }
            if (!hint) {
                hint = document.createElement('div');
                hint.className = 'sf-welcome-hint';
                hint.innerHTML = '<span>\u{1F39F}\uFE0F</span><span>Tienes un Ticket de Bienvenida disponible</span>';
                wrapper.appendChild(hint);
            }
            hint.hidden = false;
        }

        // ── Ticket de Bienvenida: confirmación explícita antes de gastarlo ──
        // El backend NO consume el ticket hasta recibir use_welcome_ticket=1,
        // que solo se envía si el usuario confirma este modal.
        async function _sfShowWelcomeTicketModal(info) {
            info = info || {};
            const result = await Swal.fire({
                title: '\u00BFQuieres usar tu Ticket de Bienvenida para ingresar a este sorteo?',
                html: '<div class="sf-chat-body sf-welcome-body">' +
                      '<div class="sf-welcome-ticket">\u{1F39F}\uFE0F</div>' +
                      '<p class="sf-chat-p">Tu compra te da acceso a este sorteo <b>sin cumplir los requisitos est\u00E1ndar</b>.</p>' +
                      '<div class="sf-chat-warn sf-welcome-warn">' +
                      '<b>Nota:</b> Tu Ticket de Bienvenida es de uso \u00FAnico. Si decides usarlo ahora, para participar en sorteos futuros deber\u00E1s cumplir con los requisitos est\u00E1ndar de compras.' +
                      '</div>' +
                      '</div>',
                showCloseButton: false,
                showCancelButton: true,
                confirmButtonText: 'S\u00ED, usar mi Ticket',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
                buttonsStyling: false,
                customClass: { popup: 'sf-modal-chat sf-modal-welcome', confirmButton: 'sf-chat-confirm', cancelButton: 'sf-chat-cancel' },
                allowOutsideClick: false
            });
            if (result.isConfirmed) {
                await _sfExecuteJoin({ useWelcomeTicket: true });
            }
        }

        // ── Diálogo BLOQUEANTE de retiro (expulsado / suspendido) vía polling ──
        // Se dispara una sola vez cuando el polling detecta el cambio de estado.
        // No se cierra con Escape ni con clic fuera: sólo con el CTA.
        const SF_REMOVAL_COPY = Object.freeze({
            expelled: {
                tone: 'warning',
                icon: 'warning',
                badge: 'Sorteo en revisi\u00F3n',
                title: 'Te retiramos del sorteo',
                desc: 'Tu entrada a este sorteo se retir\u00F3 durante la revisi\u00F3n.'
            },
            banned: {
                tone: 'danger',
                icon: 'ban',
                badge: 'Cuenta suspendida',
                title: 'Tu cuenta est\u00E1 suspendida',
                desc: 'Tu cuenta ya no puede participar en los sorteos.'
            }
        });

        let _sfRetiroShown = false;
        function _sfShowExpelledModal(kind) {
            if (_sfRetiroShown) return;
            _sfRetiroShown = true;

            const state = kind === 'banned' ? 'banned' : 'expelled';
            const copy = SF_REMOVAL_COPY[state];
            const dialog = _sfDialog(SF_DIALOG_ID.removed);
            if (!dialog) return;

            dialog.dataset.state = state;
            const glyph = dialog.querySelector('[data-slot="glyph"]');
            const glyphIcon = dialog.querySelector('[data-slot="glyph-icon"]');
            const badge = dialog.querySelector('[data-slot="badge"]');
            if (glyph) glyph.dataset.tone = copy.tone;
            if (glyphIcon) glyphIcon.setAttribute('href', '#sf-icon-' + copy.icon);
            if (badge) badge.dataset.tone = copy.tone;
            _sfSetSlotText(dialog, 'badge', copy.badge);
            _sfSetSlotText(dialog, 'title', copy.title);
            _sfSetSlotText(dialog, 'desc', copy.desc);

            SfDialog.open(dialog, { dismissible: false });
        }

        // ── Diálogo "Ya participas": estado del sorteo + acceso a los premios ──
        function _sfShowAlreadyJoinedModal() {
            const dialog = _sfDialog(SF_DIALOG_ID.joined);
            if (!dialog) return;

            const d = _lastStatusData || {};
            const participants = Number(d.participants);
            const slotsTotal = Number(d.slots_total);
            const winners = Number(CONFIG.winnersCount) || _sfShowcasePositions().length;
            const hasParticipants = Number.isFinite(participants) && participants >= 0;

            _sfSetSlotText(dialog, 'participants', hasParticipants ? participants.toLocaleString('es-ES') : '\u2014');
            _sfSetSlotText(dialog, 'slots', slotsTotal > 0
                ? (hasParticipants ? participants.toLocaleString('es-ES') + ' / ' : '') + slotsTotal.toLocaleString('es-ES')
                : 'Sin l\u00EDmite');
            _sfSetSlotText(dialog, 'winners', winners > 0 ? winners.toLocaleString('es-ES') : '\u2014');

            SfDialog.open(dialog).then(function (action) {
                if (action === 'prizes') openPrizeModal();
            });
        }

        function _sfHandleJoinError(msg, code) {
            const m = (msg || '').toLowerCase();
            if (code === 'private_not_invited' || m.includes('privado y exclusivo')) {
                Swal.fire({
                    title: '\u{1F512} Sorteo Privado',
                    html: '<div style="text-align:center;">' +
                        '<p style="font-size:1.1em;font-weight:700;margin-bottom:8px;">Este sorteo es privado y exclusivo</p>' +
                        '<p>' + (msg || 'No te encuentras en la lista de invitados.') + '</p>' +
                        '<p style="margin-top:10px;font-size:0.9em;opacity:0.8;">Solo pueden participar los usuarios invitados por el organizador.</p>' +
                        '</div>',
                    confirmButtonText: 'Ver otros juegos',
                    showCloseButton: false,
                    showCancelButton: true,
                    cancelButtonText: '<i class="fas fa-arrow-left"></i> Volver',
                    customClass: { popup: 'sf-modal-restriction', cancelButton: 'sf-modal-cancel' },
                    allowOutsideClick: true
                }).then(function(result) {
                    if (result.isConfirmed) window.location.href = '/juegos';
                });
                return;
            }
            if (m.includes('verificado')) {
                Swal.fire({
                    title: '\u{1F451} Solo VIPs',
                    html: '<div style="text-align:center;">' +
                        '<p style="font-size:1.1em;font-weight:700;margin-bottom:8px;">Este sorteo es exclusivo para usuarios verificados</p>' +
                        '<p>Verifica tu cuenta desde tu <b>perfil</b> para desbloquear sorteos VIP y beneficios especiales.</p>' +
                        '</div>',
                    confirmButtonText: 'Ir a Verificar',
                    showCloseButton: false,
                    showCancelButton: true,
                    cancelButtonText: '<i class="fas fa-arrow-left"></i> Volver',
                    customClass: { popup: 'sf-modal-restriction', cancelButton: 'sf-modal-cancel' },
                    allowOutsideClick: true
                }).then(function(result) {
                    if (result.isConfirmed) window.location.href = '/cuenta';
                });
            } else if (m.includes('ticket de bienvenida')) {
                _sfShowRestrictionModal('Ticket de Bienvenida', '\u{1F39F}\uFE0F',
                    '<div style="text-align:center;">' +
                    '<p style="font-size:1.05em;font-weight:700;margin-bottom:8px;">No se pudo usar tu Ticket de Bienvenida</p>' +
                    '<p>' + (msg || 'Tu Ticket de Bienvenida no est\u00E1 disponible.') + '</p>' +
                    '</div>', 'Entendido');
            } else if (m.includes('compra') || m.includes('orders') || m.includes('min_orders') || m.includes('mínimo')) {
                _sfShowRestrictionModal('Compras Requeridas', '\u{1F6D2}',
                    '<div style="text-align:center;">' +
                    '<p style="font-size:1.05em;font-weight:700;margin-bottom:8px;">Necesitas compras recientes para participar</p>' +
                    '<p>' + (msg || 'Realiza compras en la tienda para desbloquear este sorteo.') + '</p>' +
                    '</div>', 'Explorar Tienda');
            } else if (m.includes('gasto') || m.includes('histórico') || m.includes('spent')) {
                _sfShowRestrictionModal('Nivel de Gasto', '\u{1F4B0}',
                    '<div style="text-align:center;">' +
                    '<p style="font-size:1.05em;font-weight:700;margin-bottom:8px;">Tu historial de compras a\u00FAn no alcanza el m\u00EDnimo</p>' +
                    '<p>' + (msg || 'Sigue comprando para alcanzar el nivel requerido.') + '</p>' +
                    '</div>', 'Ver Tienda');
            } else if (m.includes('saldo insuficiente') || m.includes('necesitas')) {
                _sfShowRestrictionModal('Bananos Insuficientes', '',
                    '<div style="text-align:center;">' +
                    '<div class="sf-restriction-coin">' + _sfBananosImg(58) + '</div>' +
                    '<p style="font-size:1.1em;font-weight:700;margin-bottom:8px;">No tienes suficientes Bananos</p>' +
                    '<p>' + (msg || 'Consigue m\u00E1s Bananos para entrar a este sorteo.') + '</p>' +
                    '<p style="margin-top:10px;font-size:0.88em;color:#94a3b8;">\u{1F4A1} Tip: Gana Bananos con la Ruleta Diaria o comprando en la tienda.</p>' +
                    '</div>', 'Entendido');
            } else if (m.includes('lleno') || m.includes('cupos')) {
                _sfShowRestrictionModal('Sorteo Lleno', '\u{1F6AB}',
                    '<div style="text-align:center;">' +
                    '<p style="font-size:1.1em;font-weight:700;margin-bottom:8px;">\u00A1Todos los cupos est\u00E1n ocupados!</p>' +
                    '<p>Llegaste un poco tarde, pero no te preocupes... \u00A1habr\u00E1 m\u00E1s sorteos pronto!</p>' +
                    '</div>', 'OK');
            } else if (m.includes('ya est')) {
                _sfShowAlreadyJoinedModal();
            } else if (m.includes('sesi\u00F3n') || m.includes('login') || m.includes('iniciar')) {
                window.location.href = '/login';
            } else {
                _sfShowRestrictionModal('No Puedes Entrar', '\u{1F914}',
                    '<div style="text-align:center;"><p>' + (msg || 'Ocurri\u00F3 un error inesperado.') + '</p></div>',
                    'Entendido');
            }
        }

        // Huella de dispositivo ligera (no usa librerías de tracking): combina
        // señales estables del navegador en un hash corto. Sirve para reforzar
        // el límite anti-multicuenta sin frustrar a usuarios reales.
        function _sfDeviceFingerprint() {
            try {
                const n = navigator || {};
                const s = screen || {};
                const parts = [
                    n.userAgent || '',
                    n.language || '',
                    (n.languages || []).join(','),
                    (Intl.DateTimeFormat().resolvedOptions().timeZone) || '',
                    new Date().getTimezoneOffset(),
                    s.width + 'x' + s.height,
                    s.colorDepth || '',
                    n.platform || '',
                    n.hardwareConcurrency || '',
                    n.deviceMemory || '',
                    (n.maxTouchPoints || 0)
                ].join('|');
                // Hash FNV-1a de 32 bits -> hex (ligero, sin dependencias).
                let h = 0x811c9dc5;
                for (let i = 0; i < parts.length; i++) {
                    h ^= parts.charCodeAt(i);
                    h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0;
                }
                return ('00000000' + h.toString(16)).slice(-8);
            } catch (e) {
                return '';
            }
        }

        let _sfJoinInFlight = false;
        async function _sfExecuteJoin(opts) {
            opts = opts || {};
            if (_sfJoinInFlight) return;
            _sfJoinInFlight = true;
            const btn = document.getElementById('btnAction') || document.getElementById('join-btn');
            if (btn) { btn.disabled = true; btn.innerText = 'Procesando...'; }
            try {
                const fd = new FormData();
                fd.append('action', CONFIG.apis.join);
                fd.append('raffle_id', CONFIG.raffleId);
                fd.append('fp', _sfDeviceFingerprint());
                if (opts.declared) fd.append('declared', '1');
                if (opts.useWelcomeTicket) fd.append('use_welcome_ticket', '1');
                const res = await fetch(CONFIG.apis.url, { method: 'POST', body: fd });
                const data = await _resolveSyncFrame(await res.json());
                // El backend NO inscribe: pide confirmar el uso del Ticket de
                // Bienvenida. Reintentamos solo si el usuario confirma el modal.
                if (data.status === 'needs_welcome_ticket_confirmation' || data.needs_welcome_ticket_confirmation === true) {
                    if (btn) { btn.disabled = false; }
                    _sfJoinInFlight = false;
                    await _sfShowWelcomeTicketModal(data.welcome_ticket || {});
                    return;
                }
                if (data.status === 'needs_declaration' || data.needs_declaration === true) {
                    if (btn) { btn.disabled = false; }
                    _sfJoinInFlight = false;
                    await _sfShowDeclarationModal(data.min_date || '');
                    return;
                }
                if (data.status === 'success') {
                    launchJoinConfetti();
                    _sfOpenEnrolledDialog(data, btn);
                } else {
                    _sfHandleJoinError(data.message, data.code);
                }
            } catch (e) {
                _sfShowRestrictionModal('Error de Conexi\u00F3n', '\u{1F4E1}',
                    '<p style="text-align:center;">No se pudo conectar con el servidor. Revisa tu conexi\u00F3n e intenta de nuevo.</p>',
                    'Reintentar');
            } finally {
                _sfJoinInFlight = false;
                if (btn) btn.disabled = false;
                pollStatus();
            }
        }

        // =================================================================
        //  INSCRIPCIÓN CONFIRMADA ("¡Estás dentro!") — diálogo nativo persistente
        //  (#sf-enrolled-dialog). Muestra ticket, costo de entrada y recompensa
        //  early-bird, y encadena de forma atómica con la pre-elección.
        // =================================================================
        function _sfEnrolledStatHtml(term, detailHtml) {
            return '<div class="sf-stat"><dt>' + term + '</dt><dd>' + detailHtml + '</dd></div>';
        }

        function _sfEnrolledEntryHtml(data) {
            const cost = parseFloat(data.cost) || 0;
            if (data.welcome_ticket_used) return 'Ticket de bienvenida';
            if (cost > 0) {
                return '<span class="sf-stat__coins">' + _sfBananosImg(18) + '<span>' + cost.toLocaleString('es-ES') + '</span></span>';
            }
            return 'Gratis';
        }

        /**
         * @param {Object} data     respuesta del backend al inscribirse
         * @param {HTMLElement|null} opener  botón que disparó la inscripción
         */
        function _sfOpenEnrolledDialog(data, opener) {
            const dialog = _sfDialog(SF_DIALOG_ID.enrolled);
            if (!dialog) return;
            data = data || {};
            const earlyBird = data.early_bird || null;
            const ticket = data.ticket != null ? String(data.ticket) : '';

            const stats = [];
            stats.push(_sfEnrolledStatHtml('Ticket', '#' + escapeHtml(ticket)));
            stats.push(_sfEnrolledStatHtml('Entrada', _sfEnrolledEntryHtml(data)));
            if (earlyBird && earlyBird.status !== 'pending' && Number(earlyBird.amount) > 0) {
                stats.push(_sfEnrolledStatHtml('Recompensa',
                    '<span class="sf-stat__coins">' + _sfBananosImg(18) + '<span>+' + Number(earlyBird.amount).toLocaleString('es-ES') + '</span></span>'));
            }
            const statsEl = dialog.querySelector('[data-slot="stats"]');
            if (statsEl) {
                statsEl.innerHTML = stats.join('');
                statsEl.dataset.count = String(stats.length);
            }

            _sfSetSlotText(dialog, 'badge', earlyBird
                ? ('Madrugador #' + (parseInt(earlyBird.position, 10) || ''))
                : 'Inscripción confirmada');
            _sfSetSlotText(dialog, 'desc', earlyBird
                ? 'Entraste entre los primeros y tu lugar ya está asegurado. Guarda tu número de ticket: con él anunciaremos al ganador.'
                : 'Tu lugar en este sorteo ya está asegurado. Guarda tu número de ticket: con él anunciaremos al ganador.');

            const notice = dialog.querySelector('[data-slot="notice"]');
            if (notice) {
                const pending = !!(earlyBird && earlyBird.status === 'pending');
                notice.hidden = !pending;
                if (pending) {
                    _sfSetSlotText(dialog, 'notice-text',
                        'Entraste en la ventana rápida. Tu parte del pozo se reparte en partes iguales y se abona al cerrar la ventana (' +
                        (parseInt(earlyBird.window_seconds, 10) || 0) + ' s). Te avisamos con una notificación.');
                }
            }

            const primary = dialog.querySelector('[data-slot="primary"]');
            const secondary = dialog.querySelector('[data-slot="secondary"]');
            const space = _sfPreChoiceSpace(_lastStatusData || {});
            const offersPreChoice = _sfPreChoiceEligible(_lastStatusData || {}, space);
            if (primary) {
                primary.textContent = offersPreChoice ? space.cta.fresh : 'Seguir en el sorteo';
                primary.classList.remove('is-busy');
                primary.disabled = false;
                if (offersPreChoice) {
                    primary.removeAttribute('data-dialog-action');
                    primary.dataset.act = 'prechoice';
                } else {
                    delete primary.dataset.act;
                    primary.dataset.dialogAction = 'confirm';
                }
            }
            if (secondary) secondary.hidden = !offersPreChoice;

            const controller = new AbortController();
            dialog.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target.closest('[data-act="prechoice"]') : null;
                if (!target || target.disabled) return;
                target.disabled = true;
                target.classList.add('is-busy');
                _sfOpenPreChoice(_lastStatusData || {}, { opener: opener || null, chainFrom: dialog })
                    .finally(() => {
                        target.disabled = false;
                        target.classList.remove('is-busy');
                    });
            }, { signal: controller.signal });

            SfDialog.open(dialog, { opener: opener instanceof HTMLElement ? opener : null }).then((action) => {
                controller.abort();
                if (offersPreChoice && action !== 'handoff') _sfPreChoiceOffered = true;
            });
        }

        // =================================================================
        //  FASE 2 — PRE-SELECCIÓN DE PREMIO ("Si llegas a ganar, ¿qué
        //  prefieres?"). El flujo por pasos vive en SfPreChoice (sf-prechoice.js);
        //  aquí se arma el modelo a partir del estado del sorteo y se inyecta
        //  la persistencia. Las opciones disponibles las manda el backend.
        // =================================================================
        let _sfPreChoiceOffered = false;

        // Si el sorteo deja de estar abierto (bloqueo o arranque de la
        // animación en vivo), cerramos la pre-elección para no tapar el evento.
        function _sfClosePreChoiceIfOpen() {
            if (window.SfPreChoice && SfPreChoice.isOpen()) SfPreChoice.close();
        }

        async function _sfSavePreChoice(choice, extra) {
            extra = extra || {};
            try {
                const fd = new FormData();
                fd.append('action', CONFIG.apis.prechoice);
                fd.append('raffle_id', CONFIG.raffleId);
                fd.append('choice', choice);
                if (extra.animation_mode) fd.append('animation_mode', extra.animation_mode);
                if (extra.coin_choice) fd.append('coin_choice', extra.coin_choice);
                if (extra.pool_choices) fd.append('pool_choices', JSON.stringify(extra.pool_choices));
                const res = await fetch(CONFIG.apis.url, { method: 'POST', body: fd });
                const data = await _resolveSyncFrame(await res.json());
                if (data && data.status === 'success') {
                    if (_lastStatusData) {
                        _lastStatusData.my_pre_choice = choice;
                        _lastStatusData.my_pre_gamble_anim = data.pre_gamble_anim || extra.animation_mode || null;
                        _lastStatusData.my_pre_gamble_side = data.pre_gamble_side || extra.coin_choice || null;
                        _lastStatusData.my_pre_pool_choices = data.pre_pool_choices || extra.pool_choices || null;
                    }
                    return true;
                }
                return false;
            } catch (e) {
                return false;
            }
        }

        /**
         * Alternativas al premio original vigentes según la configuración del
         * sorteo. Misma lectura que el servidor (rr_raffle_*_enabled): Cashback
         * exige porcentaje > 0; Doble o Nada no existe en fast_batch. Mantener
         * ambos lados idénticos: el servidor auto-reclama el premio original
         * cuando aquí tampoco hay alternativas.
         * @param {Object} d  último estado del sorteo
         * @param {Object} [opts]  `endgame.options` del servidor (fase de ganador)
         */
        function _sfChoiceRules(d, opts) {
            d = d || {};
            const src = opts || {
                allow_cashback: d.allow_cashback,
                cashback_percent: d.cashback_percent,
                allow_double: d.allow_double_or_nothing
            };
            const cashbackPercent = parseInt(src.cashback_percent || CONFIG.cashbackPercent || 0, 10) || 0;
            const mode = d.mode || CONFIG.mode;
            return {
                cashbackPercent,
                cashback: !!src.allow_cashback && cashbackPercent > 0,
                gamble: !!src.allow_double && mode !== 'fast_batch'
            };
        }

        /**
         * Espacio de decisión del participante (ver SfPreChoice.describe): qué
         * opciones existen de verdad, si hay algo que decidir y los textos del
         * CTA. Usa un modelo mínimo (sin HTML de tarjetas) para poder evaluarse
         * en cada refresco de la UI sin coste.
         * @returns {{choices: string[], soleChoice: string|null, hasAlternatives: boolean, hasDecision: boolean, cta: {fresh: string, change: string}}}
         */
        function _sfPreChoiceSpace(d) {
            const rules = _sfChoiceRules(d);
            const positions = _sfShowcasePositions().map((position) => {
                const pool = _sfPrizePool(position.prize);
                const options = (pool && Array.isArray(pool.options))
                    ? pool.options.filter((opt) => opt && opt.option_key)
                    : [];
                return options.length > 0 ? { options } : null;
            }).filter(Boolean);
            const model = {
                pool: positions.length > 0 ? { positions } : null,
                cashback: rules.cashback ? {} : null,
                gamble: rules.gamble ? {} : null
            };
            if (window.SfPreChoice) return SfPreChoice.describe(model);
            // Sin el módulo no hay modal que abrir: espacio sin decisión.
            return {
                choices: ['original'], soleChoice: 'original', hasAlternatives: false, hasDecision: false,
                cta: { fresh: 'Elegir qué prefiero si gano', change: 'Cambiar elección' }
            };
        }

        /**
         * ¿Procede ofrecer la pre-elección automáticamente tras inscribirse?
         * Nunca cuando no hay nada que decidir (una sola opción sin detalle).
         */
        function _sfPreChoiceEligible(d, space) {
            d = d || {};
            if (_sfPreChoiceOffered) return false;
            if (!_sfPreChoiceAvailable(d, space)) return false;
            if (d.my_pre_choice) return false;                      // ya eligió antes
            return true;
        }

        /** ¿El participante puede abrir (o reabrir) la pre-elección ahora mismo? */
        function _sfPreChoiceAvailable(d, space) {
            d = d || {};
            if (d.allow_pre_choice === false) return false;         // toggle admin desactivado
            if (!CONFIG.me || !CONFIG.me.userId) return false;      // sólo logueados
            if (!_sfPreChoiceRaffleOpen(d)) return false;
            return (space || _sfPreChoiceSpace(d)).hasDecision;
        }

        function _sfPreChoiceRaffleOpen(d) {
            return !d.status || d.status === 'open' || d.status === 'upcoming';
        }

        function _sfPoolPositionModel(position) {
            const pool = _sfPrizePool(position.prize);
            if (!pool || !Array.isArray(pool.options) || pool.options.length === 0) return null;
            const options = pool.options
                .filter((opt) => opt && opt.option_key)
                .map((opt) => ({
                    key: String(opt.option_key),
                    name: _sfOptionDisplayName(opt),
                    cardAttrs: _sfStoreCardAttrs(opt),
                    cardInner: _sfStoreCardInnerHtml(opt)
                }));
            if (options.length === 0) return null;
            return {
                key: String(parseInt(position.position, 10)),
                label: _sfOrdinal(position.position),
                title: pool.label || 'Elige tu premio',
                options,
                rawOptions: pool.options
            };
        }

        /** Modelo de datos de la pre-elección (ver PreChoiceModel en sf-prechoice.js). */
        function _sfBuildPreChoiceModel(d) {
            d = d || {};
            const rawPrize = CONFIG.prize || {};
            const prize = _sfPrimaryPrizeOption(rawPrize) || rawPrize;
            const prizeName = prize.name || 'Premio del sorteo';
            const rules = _sfChoiceRules(d);
            const cbPercent = rules.cashbackPercent;
            // Valor del 1er puesto en Bananos: misma valuación que usa el
            // servidor para pagar Cashback y Doble o Nada.
            const prizeValueCoins = _sfPrizeValueCoins(1);

            const positions = _sfShowcasePositions().map(_sfPoolPositionModel).filter(Boolean);
            const savedRaw = (d.my_pre_choice === 'original' && d.my_pre_pool_choices && typeof d.my_pre_pool_choices === 'object')
                ? d.my_pre_pool_choices : {};
            const saved = {};
            Object.keys(savedRaw).forEach((k) => { saved[String(parseInt(k, 10))] = String(savedRaw[k]); });

            // Miniatura de la opción "original": el ítem de vitrina ya guardado
            // (si sigue en el pool) o el premio fijo. Con pool y sin elección,
            // se muestra un regalo genérico: nunca la primera skin como si fuera
            // EL premio.
            let prizeThumb = { image: '', vars: '' };
            if (positions.length === 0) {
                prizeThumb = { image: prize.image_icon || prize.image || '', vars: _sfStoreCardVars(prize) };
            } else {
                const firstSaved = positions.map((p) => {
                    const key = saved[p.key];
                    return key ? (p.rawOptions.find((o) => o && String(o.option_key) === key) || null) : null;
                }).find(Boolean);
                if (firstSaved) {
                    prizeThumb = { image: firstSaved.image_icon || firstSaved.image || '', vars: _sfStoreCardVars(firstSaved) };
                }
            }
            positions.forEach((p) => { delete p.rawOptions; });

            const allowAnimChoice = parseInt(d.allow_winner_animation_choice || 0, 10) === 1;
            const adminGambleMode = (d.animations && d.animations.gamble)
                ? d.animations.gamble
                : ((CONFIG.anims && CONFIG.anims.gamble) ? CONFIG.anims.gamble : 'coin');

            return {
                prizeName,
                prizeThumb,
                pool: positions.length > 0 ? { positions, saved } : null,
                cashback: rules.cashback
                    ? { percent: cbPercent, coins: Math.round(prizeValueCoins * cbPercent / 100) }
                    : null,
                gamble: rules.gamble
                    ? {
                        bonusCoins: parseInt(CONFIG.gambleBonusCoins || 0, 10) || Math.round(prizeValueCoins),
                        chooseMode: allowAnimChoice,
                        fixedMode: adminGambleMode,
                        saved: { mode: d.my_pre_gamble_anim || null, side: d.my_pre_gamble_side || null }
                    }
                    : null,
                current: d.my_pre_choice || null,
                assets: {
                    bananos: _sfBananosImg,
                    coin: _sfCoinImg,
                    roulette: _sfRouletteSvg,
                    slot: _sfSlotMachineSvg
                }
            };
        }

        /**
         * Abre la pre-elección con los datos más recientes del sorteo.
         * @param {Object} d  último estado conocido
         * @param {{ opener?: HTMLElement|null, chainFrom?: HTMLDialogElement|null }} [options]
         * @returns {Promise<Object|null>}
         */
        async function _sfOpenPreChoice(d, options) {
            options = options || {};
            const dialog = _sfDialog(SF_DIALOG_ID.prechoice);
            if (!dialog || !window.SfPreChoice) return null;
            if (!_sfPreChoiceAvailable((state && state.lastData) || _lastStatusData || d || {})) return null;
            _sfPreChoiceOffered = true;
            await _sfHydratePrizeVisuals();
            // Se re-evalúa con el estado más reciente: la configuración o la fase
            // del sorteo pueden haber cambiado mientras cargaban las imágenes.
            const latest = (state && state.lastData) || _lastStatusData || d || {};
            if (!_sfPreChoiceAvailable(latest)) return null;
            // Si el diálogo origen se cerró mientras cargaban las imágenes, el
            // usuario ya decidió no seguir: no forzamos la apertura.
            if (options.chainFrom && !SfDialog.isOpen(options.chainFrom)) return null;
            return SfPreChoice.open({
                dialog,
                model: _sfBuildPreChoiceModel(latest),
                save: _sfSavePreChoice,
                opener: options.opener || null,
                chainFrom: options.chainFrom || null
            });
        }

        function _sfEscapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        async function joinRaffle() {
            if (_sfIsExpelledFromRaffle(_lastStatusData)) return;
            const eco = (_lastStatusData && _lastStatusData.economics) ? _lastStatusData.economics : {};
            const cost = parseFloat(eco.entry_cost) || 0;

            if (cost > 0) {
                const result = await Swal.fire({
                    title: '\u{1F3B0} Confirmar Entrada',
                    html: '<div style="text-align:center;">' +
                          '<p style="font-size:1.05em;margin-bottom:14px;">Este sorteo tiene costo de entrada</p>' +
                          '<div style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:16px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.25);">' +
                          '<span class="sf-inline-coin-wrap">' + _sfBananosImg(36) + '</span>' +
                          '<span style="font-family:\'Changa One\',sans-serif;font-size:1.8em;color:#92400e;">' + cost + '</span>' +
                          '<span style="font-size:0.85em;color:#b45309;font-weight:700;">Bananos</span>' +
                          '</div>' +
                          '<p style="margin-top:14px;font-size:0.88em;color:#64748b;">Se descontar\u00E1n de tu saldo al confirmar</p>' +
                          '</div>',
                    showCloseButton: false,
                    showCancelButton: true,
                    confirmButtonText: '\u{1F680} \u00A1Entrar!',
                    cancelButtonText: '<i class="fas fa-arrow-left"></i> Volver',
                    reverseButtons: true,
                    customClass: { popup: 'sf-modal-gameshow', cancelButton: 'sf-modal-cancel' },
                    allowOutsideClick: true
                });
                if (result.isConfirmed) {
                    await _sfExecuteJoin();
                }
            } else {
                await _sfExecuteJoin();
            }
        }

        async function submitAction(action) {
            _sfCancelAutoPreChoice();
            const modalBody = document.getElementById('decisionModalBody');
            if (modalBody) modalBody.innerHTML = '<div class="text-yellow-500 font-bold animate-pulse">Procesando...<br>Espera...</div>';

            if (decisionTimerInterval) {
                clearInterval(decisionTimerInterval);
                decisionTimerInterval = null;
            }

            stopStatusPolling();
            stopGamblePolling();

            try {
                const payload = { action: CONFIG.apis.gamble, sub: 'submit_gamble', id: CONFIG.raffleId, choice: action };
                const res = await fetch(CONFIG.apis.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin'
                });
                const resp = await _resolveSyncFrame(await res.json());

                if (resp.status === 'error') {
                    Swal.fire('Error', resp.message || 'Error', 'error');
                    startStatusPolling();
                    return;
                }

                const dm = document.getElementById('decisionModal');
                if (dm) dm.classList.add('hidden');

                startStatusPolling();
            } catch (e) {
                console.error(e);
                Swal.fire('Error', 'Fallo de conexión', 'error');
                startStatusPolling();
            }
        }

        async function confirmGamble(side, animationMode = null) {
            _sfCancelAutoPreChoice();
            const modalBody = document.getElementById('decisionModalBody');
            if (modalBody) modalBody.innerHTML = '<div class="text-yellow-500 font-bold animate-pulse">Procesando...<br>Espera...</div>';

            if (decisionTimerInterval) {
                clearInterval(decisionTimerInterval);
                decisionTimerInterval = null;
            }

            stopStatusPolling();
            stopGamblePolling();

            try {
                const payload = { action: CONFIG.apis.gamble, sub: 'submit_gamble', id: CONFIG.raffleId, choice: 'double' };

                const allowedModes = ['coin', 'slot', 'roulette'];
                const normalizedMode = (animationMode && allowedModes.includes(String(animationMode))) ? String(animationMode) : null;
                const effectiveMode = normalizedMode || ((CONFIG && CONFIG.anims && CONFIG.anims.gamble) ? CONFIG.anims.gamble : 'coin');

                if (normalizedMode) payload.animation_mode = normalizedMode;
                if (effectiveMode === 'coin') {
                    payload.coin_choice = (side === 'tails') ? 'tails' : 'heads';
                } else if (effectiveMode === 'roulette') {
                    payload.coin_choice = (side === 'black') ? 'black' : 'red';
                }

                const res = await fetch(CONFIG.apis.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin'
                });
                const resp = await _resolveSyncFrame(await res.json());

                if (resp.status === 'error') {
                    Swal.fire('Error', resp.message || 'Error', 'error');
                    startStatusPolling();
                    return;
                }

                if (resp.target_block !== undefined && resp.target_block !== null) lastGambleTargetBlock = resp.target_block;
                if (resp.target !== undefined && resp.target !== null) lastGambleTargetBlock = resp.target;

                // Seed current block from submit_gamble response so the ticker is ready
                if (resp.bsc_block_current) {
                    _gambleBlockCurrent = parseInt(resp.bsc_block_current, 10);
                    _gambleBlockDisplay = _gambleBlockCurrent;
                }

                const dm = document.getElementById('decisionModal');
                if (dm) dm.classList.add('hidden');

                // The cinematic sequence (Recap + DOBLE O NADA) will be triggered
                // by pollStatus when it detects all decisions are in. The block race
                // UI is bootstrapped AFTER the cinematic finishes, ensuring the active
                // player gets the full epic show before seeing the race.

                startStatusPolling();
            } catch (e) {
                console.error(e);
                Swal.fire('Error', 'Fallo de conexión', 'error');
                startStatusPolling();
            }
        }

        async function sendDecision(action) {
            if (action === 'gamble') {
                const allowChoice = !!(decisionOptions && parseInt(decisionOptions.allow_winner_animation_choice || 0, 10) === 1);
                const defaultMode = (CONFIG && CONFIG.anims && CONFIG.anims.gamble) ? CONFIG.anims.gamble : 'coin';

                const modalBody = document.getElementById('decisionModalBody');
                if (!modalBody) return;

                if (allowChoice) {
                    modalBody.innerHTML = `
                    <div class="dm-inner-screen modal-enter">
                        <h3 class="dm-title" style="font-size:22px;">Elige tu modo de juego</h3>
                        <p class="dm-subtitle">Selecciona cómo quieres arriesgarlo todo</p>
                        <div class="dm-cards">
                            <button class="dm-card dm-card--coin" onclick="window.showGambleCoinPicker()">
                                <div class="dm-card__icon">${_sfBananosImg(44)}</div>
                                <div><div class="dm-card__label">Moneda</div></div>
                            </button>
                            <button class="dm-card dm-card--roulette" onclick="window.showGambleRoulettePicker()">
                                <div class="dm-card__icon dm-card__icon--gamer">${_sfRouletteSvg(44)}</div>
                                <div><div class="dm-card__label">Ruleta</div></div>
                            </button>
                            <button class="dm-card dm-card--slot" onclick="window.confirmGamble(null, 'slot')">
                                <div class="dm-card__icon dm-card__icon--gamer">${_sfSlotMachineSvg(44)}</div>
                                <div><div class="dm-card__label">Slots</div></div>
                            </button>
                        </div>
                        <button class="dm-back-btn" onclick="window.goBackToMainDecision()" style="margin-top: 16px; width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #9ca3af; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                            <i class="fas fa-arrow-left" style="margin-right: 8px;"></i>Volver atrás
                        </button>
                        <p class="dm-footer-hint">Al elegir, entrarás en la cola de espera.</p>
                    </div>
                `;
                    return;
                }

                if (defaultMode === 'coin') {
                    window.showGambleCoinPicker();
                    return;
                }

                if (defaultMode === 'roulette') {
                    window.showGambleRoulettePicker();
                    return;
                }

                await window.confirmGamble(null, defaultMode);
                return;
            }

            await submitAction(action);
        }

        let _dmDeadlineMs = 0;
        let _dmTotalSeconds = 0;
        const DM_CIRCLE_CIRCUMFERENCE = 2 * Math.PI * 52; // r=52 → 326.73

        // Nombre corto del premio del puesto `pos` para el ganador actual: el
        // ítem preseleccionado de la Vitrina si hay pool, o el premio fijo.
        function _dmPrizeDisplayName(pos) {
            const rawPrize = (CONFIG && CONFIG.prize) ? CONFIG.prize : {};
            const posEntry = _sfShowcasePositions().find(p => parseInt(p.position, 10) === pos) || null;
            const posPrize = posEntry ? posEntry.prize : rawPrize;
            const pool = _sfPrizePool(posPrize);
            if (pool && Array.isArray(pool.options) && pool.options.length > 0) {
                const pp = (_lastStatusData && _lastStatusData.my_pre_pool_choices) || {};
                const key = pp[String(pos)] || null;
                const chosen = key ? pool.options.find(o => o && o.option_key === key) : null;
                return chosen ? _sfOptionDisplayName(chosen) : 'tu premio de la Vitrina';
            }
            const opt = _sfPrimaryPrizeOption(posPrize) || posPrize || {};
            return opt.name || (opt.label ? String(opt.label) : 'tu premio');
        }

        // S1.1 / S1.2: homologa los íconos del modal del ganador con la
        // pre-elección — el "Premio Original" muestra la imagen real del premio
        // sobre su fondo estilizado, y el "Cashback" usa el ícono de Bananos.
        function _dmPopulatePrizeIcons() {
            const prizeIcon = document.getElementById('dmPrizeIcon');
            if (prizeIcon) {
                const rawPrize = (CONFIG && CONFIG.prize) ? CONFIG.prize : {};
                let prize = _sfPrimaryPrizeOption(rawPrize) || rawPrize;
                // Vitrina: el ganador no tiene "un" premio fijo; mostramos su
                // ítem preseleccionado o un regalo genérico, nunca la 1.ª skin.
                const dmWinner = ((_lastStatusData && _lastStatusData.winners) || []).find(w => isMyWinner(w)) || null;
                const dmPos = parseInt((dmWinner && dmWinner.position) || 1, 10);
                const dmPosEntry = _sfShowcasePositions().find(p => parseInt(p.position, 10) === dmPos) || null;
                const dmPool = _sfPrizePool(dmPosEntry ? dmPosEntry.prize : rawPrize);
                if (dmPool && Array.isArray(dmPool.options) && dmPool.options.length > 0) {
                    const pp = (_lastStatusData && _lastStatusData.my_pre_pool_choices) || {};
                    const key = pp[String(dmPos)] || null;
                    prize = (key && dmPool.options.find(o => o && o.option_key === key)) || {};
                }
                // El Premio Original usa el mismo color, borde, glow y artwork
                // enfocado que la tarjeta de la tienda, reducido al HUD del modal.
                const microSrc = prize.image_icon || prize.image;
                if (microSrc) {
                    prizeIcon.classList.add('dm-card__icon--photo', 'dm-card__icon--store');
                    prizeIcon.setAttribute('data-sf-item-type', _sfStoreTypeKey(prize));
                    prizeIcon.setAttribute('style', _sfStoreCardVars(prize));
                    prizeIcon.innerHTML = '<span class="dm-store-thumb__art"><img class="dm-prize-img" src="' + escapeHtml(microSrc) +
                        '" alt="" onerror="this.closest(\'.dm-card__icon\').classList.remove(\'dm-card__icon--photo\',\'dm-card__icon--store\');' +
                        'this.parentNode.parentNode.innerHTML=\'<span class=&quot;dm-card__emoji&quot;>&#127873;</span>\'"></span>' +
                        '<span class="dm-store-thumb__shade"></span>';
                }
            }
            const cbIcon = document.getElementById('dmCashbackIcon');
            if (cbIcon) {
                // Mismo ícono de Bananos que usa la pre-elección.
                cbIcon.innerHTML = _sfBananosImg(48);
            }
        }

        // Markup del anillo de tiempo (reutilizable para reinyectarlo en los
        // sub-paneles del Doble o Nada, donde el innerHTML del cuerpo se
        // reemplaza — así el timer SIEMPRE está visible, S1.4).
        function _dmTimerRingHtml() {
            return '<div class="dm-timer-ring" aria-label="Tiempo restante">' +
                '<svg class="dm-timer-svg" viewBox="0 0 120 120">' +
                    '<circle class="dm-timer-track" cx="60" cy="60" r="52"></circle>' +
                    '<circle class="dm-timer-fill" id="dmTimerCircle" cx="60" cy="60" r="52"></circle>' +
                '</svg>' +
                '<div class="dm-timer-label">' +
                    '<span class="dm-timer-seconds" id="decisionTimer">0</span>' +
                    '<span class="dm-timer-unit">seg</span>' +
                '</div>' +
            '</div>';
        }
        function _dmEnsureTimerVisible() {
            if (document.getElementById('decisionTimer')) return;
            const body = document.getElementById('decisionModalBody');
            if (!body) return;
            const host = body.querySelector('.dm-inner-screen') || body;
            host.insertAdjacentHTML('afterbegin', _dmTimerRingHtml());
        }

        // S1.4: alerta sonora sutil generada por código (Web Audio API, sin
        // archivos externos). Sólo se ejecuta dentro del modal del ganador, por
        // lo que únicamente la oye el ganador en vivo.
        let _dmAudioCtx = null;
        let _dmLastBeepSec = -1;
        function _dmWarnBeep(freq, durSec, peak) {
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                if (!_dmAudioCtx) _dmAudioCtx = new AC();
                const ctx = _dmAudioCtx;
                if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
                const now = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, now);
                gain.gain.setValueAtTime(0.0001, now);
                gain.gain.exponentialRampToValueAtTime(peak, now + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + durSec);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(now);
                osc.stop(now + durSec + 0.03);
            } catch (_) { /* best-effort: audio bloqueado por el navegador */ }
        }
        function _dmMaybeWarnSound(secs) {
            const WARN_AT = 10; // últimos 10 segundos
            if (secs > WARN_AT) { _dmLastBeepSec = -1; return; }
            if (secs > 0 && secs !== _dmLastBeepSec) {
                _dmLastBeepSec = secs;
                const urgent = secs <= 3; // un poco más intenso en el tramo final
                _dmWarnBeep(urgent ? 880 : 620, urgent ? 0.14 : 0.08, urgent ? 0.05 : 0.03);
            }
        }

        function _dmTickTimer() {
            const modal = document.getElementById('decisionModal');
            if (!modal || modal.classList.contains('hidden')) {
                clearInterval(decisionTimerInterval);
                decisionTimerInterval = null;
                return;
            }

            const leftMs = Math.max(0, _dmDeadlineMs - Date.now());
            const secs = Math.ceil(leftMs / 1000);

            // S1.4: garantizar que el timer esté presente aunque el ganador haya
            // navegado a un sub-panel que reemplazó el cuerpo del modal.
            _dmEnsureTimerVisible();

            const timerEl = document.getElementById('decisionTimer');
            const circleEl = document.getElementById('dmTimerCircle');
            if (timerEl) timerEl.innerText = String(secs);

            if (circleEl) {
                const total = Math.max(1, _dmTotalSeconds);
                const progress = Math.max(0, Math.min(1, secs / total));
                circleEl.style.strokeDasharray = String(DM_CIRCLE_CIRCUMFERENCE);
                circleEl.style.strokeDashoffset = String(DM_CIRCLE_CIRCUMFERENCE * (1 - progress));
                if (progress < 0.25) {
                    circleEl.classList.add('dm-timer--warning');
                } else {
                    circleEl.classList.remove('dm-timer--warning');
                }
            }

            _dmMaybeWarnSound(secs);

            // S1.3: al expirar el tiempo, el modal SIEMPRE se cierra de forma
            // automática (incluso si el ganador estaba en un sub-panel como
            // "Doble o Nada"), para no bloquearle la vista de la sala.
            if (secs <= 0) {
                clearInterval(decisionTimerInterval);
                decisionTimerInterval = null;
                _sfCancelAutoPreChoice();
                modal.classList.add('hidden');
                const body = document.getElementById('decisionModalBody');
                if (body && decisionModalBodyTemplate) body.innerHTML = decisionModalBodyTemplate;
            }
        }

        // =================================================================
        //  FASE 2c — AUTO-APLICAR LA PRE-ELECCIÓN DEL GANADOR (cliente).
        //  Si el ganador YA eligió su premio al inscribirse (my_pre_choice),
        //  mostramos pronto (1.5s) un overlay que simula su elección y, a los
        //  ~15s, la confirmamos automáticamente (el contador GLOBAL sigue su
        //  cuenta 60→0). Si no eligió (null), se mantiene el flujo manual con
        //  el tiempo completo.
        // =================================================================
        let _autoPreChoiceTimers = [];
        let _autoPreChoiceFired = false;
        // El ganador en vivo pidió decidir a mano: ya no se reprograma el
        // auto-submit en este sorteo (ni al reabrir el modal ni al recargar).
        let _autoPreChoiceCancelled = false;

        function _sfCancelAutoPreChoice() {
            _autoPreChoiceTimers.forEach(t => clearTimeout(t));
            _autoPreChoiceTimers = [];
            const ov = document.getElementById('autoPreChoiceOverlay');
            if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
        }

        // "Prefiero elegir manualmente": frena el auto-submit del cliente al
        // instante y avisa al backend (`cancel_auto`) para que tampoco aplique la
        // pre-elección al vencer la ventana corta. El deadline global
        // (`decision_expires_at`) no cambia: el ganador conserva todo el tiempo
        // restante del sorteo para decidir en vivo.
        function _sfLiveChoiceRequested() {
            _autoPreChoiceCancelled = true;
            _sfCancelAutoPreChoice();
            _sfCancelAutoPreChoiceServer();
        }

        async function _sfCancelAutoPreChoiceServer() {
            try {
                const fd = new FormData();
                fd.append('action', CONFIG.apis.prechoice);
                fd.append('sub', 'cancel_auto');
                fd.append('raffle_id', CONFIG.raffleId);
                await fetch(CONFIG.apis.url, { method: 'POST', body: fd });
            } catch (_) { /* best-effort: el cliente ya frenó su auto-submit */ }
            // Refresco inmediato: espectadores y HUD ven la cancelación sin
            // esperar al siguiente intervalo adaptativo.
            try { pollStatus(); } catch (_) { /* noop */ }
        }

        function _sfScheduleAutoPreChoice(options, sync) {
            _sfCancelAutoPreChoice();
            _autoPreChoiceFired = false;
            // Si el admin desactivó la pre-elección, el ganador elige en vivo
            // (tiempo completo) aunque existiera una preferencia previa.
            if (_lastStatusData && _lastStatusData.allow_pre_choice === false) return;
            const pre = (_lastStatusData && _lastStatusData.my_pre_choice) ? _lastStatusData.my_pre_choice : null;
            if (!pre) return; // sin pre-elección → flujo manual normal (tiempo completo)
            // El servidor manda: si el ganador ya canceló (en esta pestaña o antes
            // de recargar) o no hay ventana de auto-aplicación activa, se decide
            // en vivo con el tiempo completo.
            if (_autoPreChoiceCancelled) return;
            if (sync && sync.pre_apply_cancelled) { _autoPreChoiceCancelled = true; return; }
            const serverSecs = (sync && sync.pre_apply_seconds !== undefined && sync.pre_apply_seconds !== null)
                ? parseInt(sync.pre_apply_seconds, 10) : null;
            if (sync && serverSecs === null) return;

            // Respetar la configuración vigente: una elección guardada cuando la
            // opción existía no se auto-aplica si el admin la desactivó después.
            const rules = _sfChoiceRules(_lastStatusData || {}, options || null);
            if (pre === 'cashback' && !rules.cashback) return;
            if (pre === 'gamble' && !rules.gamble) return;

            const labels = {
                original: { t: 'Premio Original', i: '\u{1F3C6}' },
                cashback: { t: 'Cashback en Bananos', i: _sfBananosImg(44) },
                gamble:   { t: 'DOBLE O NADA', i: '\u{1F3B2}' }
            };
            const meta = labels[pre] || labels.original;

            // Ventana de auto-aplicación ~15s (P7): el contador GLOBAL sigue
            // mostrando el tiempo total (60→45). Mostramos el overlay pronto
            // (1.5s) para que el ganador en vivo tenga toda la ventana para
            // cancelar y elegir a mano. La barra se llena durante esos ~15s.
            // Al recargar a mitad de ventana se respeta el tiempo que resta en
            // el servidor (mínimo unos segundos para poder cancelar).
            const APC_APPLY_MS = (serverSecs !== null) ? Math.max(3000, serverSecs * 1000) : 15000;
            const APC_SHOW_MS = Math.min(1500, Math.max(0, APC_APPLY_MS - 2500));
            _autoPreChoiceTimers.push(setTimeout(() => {
                const body = document.getElementById('decisionModalBody');
                if (!body) return;
                const ov = document.createElement('div');
                ov.id = 'autoPreChoiceOverlay';
                ov.className = 'auto-prechoice-overlay';
                ov.innerHTML =
                    '<div class="auto-prechoice-card">' +
                        '<div class="auto-prechoice-icon">' + meta.i + '</div>' +
                        '<div class="auto-prechoice-title">Aplicando tu preselecci\u00F3n</div>' +
                        '<div class="auto-prechoice-choice">' + meta.t + '</div>' +
                        '<div class="auto-prechoice-bar"><span></span></div>' +
                        '<div class="auto-prechoice-hint">Si no eliges nada, aplicaremos tu preselecci\u00F3n autom\u00E1ticamente.</div>' +
                        '<button type="button" class="auto-prechoice-cancel" id="autoPreChoiceCancel">Prefiero elegir manualmente</button>' +
                    '</div>';
                body.appendChild(ov);
                // Sincronizamos la barra con el tiempo real restante hasta aplicar.
                const bar = ov.querySelector('.auto-prechoice-bar > span');
                if (bar) {
                    const remaining = Math.max(300, APC_APPLY_MS - APC_SHOW_MS);
                    bar.style.animation = 'none';
                    bar.style.transition = 'width ' + remaining + 'ms linear';
                    bar.style.width = '0';
                    requestAnimationFrame(() => { requestAnimationFrame(() => { bar.style.width = '100%'; }); });
                }
                const cancelBtn = document.getElementById('autoPreChoiceCancel');
                if (cancelBtn) cancelBtn.addEventListener('click', _sfLiveChoiceRequested);
            }, APC_SHOW_MS));

            // A los ~15s: confirmamos la elección si no fue cancelada.
            _autoPreChoiceTimers.push(setTimeout(() => {
                const m = document.getElementById('decisionModal');
                if (!m || m.classList.contains('hidden')) return;
                if (_autoPreChoiceFired) return;
                _autoPreChoiceFired = true;
                if (pre === 'gamble') {
                    // Aplicamos EXACTAMENTE lo que el usuario configuró al
                    // inscribirse (animación + lado), no un valor por defecto.
                    const savedAnim = (_lastStatusData && _lastStatusData.my_pre_gamble_anim) ? _lastStatusData.my_pre_gamble_anim : null;
                    const savedSide = (_lastStatusData && _lastStatusData.my_pre_gamble_side) ? _lastStatusData.my_pre_gamble_side : null;
                    const mode = savedAnim || ((CONFIG && CONFIG.anims && CONFIG.anims.gamble) ? CONFIG.anims.gamble : 'coin');
                    const side = (mode === 'coin') ? (savedSide || 'heads') : null;
                    window.confirmGamble(side, mode);
                } else {
                    window.sendDecision(pre); // 'original' | 'cashback'
                }
            }, APC_APPLY_MS));
        }

        function showDecisionModal(data) {
            const m = document.getElementById('decisionModal');
            if (!m) return;
            const body = document.getElementById('decisionModalBody');

            const wasHidden = m.classList.contains('hidden');
            if (wasHidden) {
                if (body && decisionModalBodyTemplate) body.innerHTML = decisionModalBodyTemplate;
                m.classList.remove('hidden');
                _dmLastBeepSec = -1; // reinicia la alerta sonora (S1.4)
                _dmPopulatePrizeIcons(); // S1.1 / S1.2: homologar íconos con la pre-elección
            }

            decisionOptions = (data && data.options) ? data.options : decisionOptions;

            const ttd = Math.max(1, parseInt(data && data.time_to_decide, 10) || 60);
            const totalTimeout = (data && data.decision_timeout && parseInt(data.decision_timeout, 10) > 0)
                ? Math.max(1, parseInt(data.decision_timeout, 10))
                : ttd;
            if (wasHidden) {
                _dmTotalSeconds = totalTimeout;
            } else {
                _dmTotalSeconds = Math.max(_dmTotalSeconds || 0, totalTimeout);
            }
            _dmDeadlineMs = Date.now() + (ttd * 1000);

            if (wasHidden) {
                const timerEl = document.getElementById('decisionTimer');
                if (timerEl) timerEl.innerText = String(ttd);

                const circleEl = document.getElementById('dmTimerCircle');
                if (circleEl) {
                    circleEl.style.strokeDasharray = String(DM_CIRCLE_CIRCUMFERENCE);
                    circleEl.style.strokeDashoffset = '0';
                    circleEl.classList.remove('dm-timer--warning');
                }
            }

            // Valor del premio del puesto del ganador (valuación del servidor) y
            // nombre corto para los textos de Cashback / Doble o Nada.
            const dmWinnerRow = ((_lastStatusData && _lastStatusData.winners) || []).find(w => isMyWinner(w)) || null;
            const dmWinnerPos = parseInt((dmWinnerRow && dmWinnerRow.position) || 1, 10) || 1;
            const dmValueCoins = _sfPrizeValueCoins(dmWinnerPos) || _sfPrizeValueCoins(1);
            const dmPrizeName = _dmPrizeDisplayName(dmWinnerPos);
            const dmFmt = (n) => Math.round(n).toLocaleString('es-ES');

            if (data.options && data.options.allow_cashback) {
                const btnCashback = document.getElementById('btnCashback');
                if (btnCashback) btnCashback.classList.remove('hidden');
                const cbPct = parseInt(data.options.cashback_percent, 10) || 0;
                const lbl = document.getElementById('lblCashbackVal');
                if (lbl) lbl.innerText = String(cbPct);
                const cbDesc = document.getElementById('dmCashbackDesc');
                if (cbDesc) {
                    const cbCoins = Math.round(dmValueCoins * cbPct / 100);
                    cbDesc.innerHTML = cbCoins > 0
                        ? ('Recibe <strong>' + dmFmt(cbCoins) + '</strong> Bananos (' + cbPct + '% del valor del premio).')
                        : ('Recibe el ' + cbPct + '% del valor del premio en Bananos.');
                }
            }
            if (data.options && data.options.allow_double) {
                const btnGamble = document.getElementById('btnGamble');
                if (btnGamble) btnGamble.classList.remove('hidden');
                // Probabilidad explícita (50 %, apuesta individual) + nombre del
                // premio + valor real de la apuesta en Bananos (servidor).
                const descEl = document.getElementById('dmGambleDesc');
                if (descEl) {
                    const gb = (dmWinnerPos === 1 ? (parseInt(CONFIG.gambleBonusCoins || 0, 10) || 0) : 0) || Math.round(dmValueCoins);
                    descEl.innerHTML = gb > 0
                        ? ('<strong>50%</strong> de ganar <strong id="dmGambleBonus">' + _sfEscapeHtml(dmPrizeName) + ' + ' + dmFmt(gb) + ' Bananos</strong>\u2026 o perderlo todo.')
                        : ('<strong>50%</strong> de duplicar <strong id="dmGambleBonus">' + _sfEscapeHtml(dmPrizeName) + '</strong>\u2026 o perderlo todo.');
                }
            }

            if (decisionTimerInterval) {
                clearInterval(decisionTimerInterval);
                decisionTimerInterval = null;
            }
            _dmTickTimer();
            decisionTimerInterval = setInterval(_dmTickTimer, 250);

            // FASE 2c: programar la auto-aplicación de la pre-elección al abrir.
            if (wasHidden) {
                _sfScheduleAutoPreChoice(decisionOptions, data);
            } else if (data && (data.pre_apply_cancelled || data.pre_apply_seconds === null) && _autoPreChoiceTimers.length) {
                // El backend ya no aplicará la pre-elección (cancelada desde otra
                // pestaña o ventana cerrada): el cliente tampoco debe auto-enviar.
                _autoPreChoiceCancelled = _autoPreChoiceCancelled || !!data.pre_apply_cancelled;
                _sfCancelAutoPreChoice();
            }
        }

        function _gambleUpdateDisplay() {
            const curEl = document.getElementById('gambleBlockCurrentVal');
            const numBox = document.getElementById('gambleBlockCurrentNum');
            if (curEl && _gambleBlockDisplay) {
                curEl.innerText = '#' + _gambleBlockDisplay.toLocaleString();
                if (numBox) { numBox.classList.remove('locking-race-tick'); void numBox.offsetWidth; numBox.classList.add('locking-race-tick'); }
            }
            if (_gambleBlockTarget > 0 && _gambleBlockDisplay > 0) {
                const total = _gambleTotalBlocks || 35;
                const done = Math.max(0, total - (_gambleBlockTarget - _gambleBlockDisplay));
                const pct = Math.min(100, Math.max(0, (done / total) * 100));
                const fill = document.getElementById('gambleProgressFill');
                const glow = document.getElementById('gambleProgressGlow');
                const left = document.getElementById('gambleBlocksLeft');
                if (fill) fill.style.width = pct + '%';
                if (glow) glow.style.left = pct + '%';
                const remaining = Math.max(0, _gambleBlockTarget - _gambleBlockDisplay);
                if (left) left.innerText = remaining + (remaining === 1 ? ' bloque restante' : ' bloques restantes');
            }
        }

        // The block counter of the gamble waiting room is driven only by the
        // server value received on each poll; these helpers just own the
        // ambient audio of the room.
        function _gambleTickerStop() {
            AdminAudio.stop('blockchain_ambient');
            _gambleTickerActive = false;
        }

        function _gambleTickerStart() {
            if (_gambleTickerActive) return;
            _gambleTickerActive = true;
            AdminAudio.play('blockchain_ambient');
        }

        function _gambleRenderGamblers(gamblers) {
            const container = document.getElementById('gambleGamblersAvatars');
            if (!container) return;
            // Only render once — skip if avatars are already drawn to avoid flicker
            if (container.children.length > 0) return;
            if (!Array.isArray(gamblers) || gamblers.length === 0) {
                return;
            }
            
            // PLURALIZATION: Update title based on number of gamblers
            const titleEl = document.getElementById('gambleGamblersTitleText');
            if (titleEl) {
                if (gamblers.length === 1) {
                    // Singular: one person gambling
                    titleEl.innerText = '¡Se la está jugando por el Doble o Perderlo Todo!';
                } else {
                    // Plural: multiple people gambling
                    titleEl.innerText = '¡Se la están jugando por el Doble o Perderlo Todo!';
                }
            }
            
            const html = gamblers.map(g => {
                const avatarSrc = g.avatar || '/assets/img/default-avatar.png';
                const name = g.username || 'Jugador';
                return `<div class="gamble-gambler-card">
                    <div class="gamble-gambler-fire-ring">
                        <img src="${avatarSrc}" alt="${name}" class="gamble-gambler-avatar">
                    </div>
                    <span class="gamble-gambler-name">${name}</span>
                </div>`;
            }).join('');
            container.innerHTML = html;
        }

        function stopGamblePolling() {
            if (gamblePollTimer) {
                clearInterval(gamblePollTimer);
                gamblePollTimer = null;
            }
            _gambleTickerStop();
        }

        function startGamblePolling() {
            stopGamblePolling();
            pollGambleStatus();
            gamblePollTimer = setInterval(pollGambleStatus, 2000);
        }

        async function pollGambleStatus() {
            try {
                if (isDecisionCountdownActive()) {
                    return;
                }
                if (awaitingGambleExecution && betsClosedUntilMs && Date.now() >= betsClosedUntilMs) {
                    awaitingGambleExecution = false;
                    betsClosedUntilMs = 0;
                }

                if (awaitingGambleExecution && betsClosedUntilMs && Date.now() < betsClosedUntilMs) {
                    return;
                }

                const ts = Date.now();
                const res = await fetch(CONFIG.apis.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: CONFIG.apis.gamble, sub: 'poll_status', id: CONFIG.raffleId, nocache: ts }), credentials: 'same-origin' });
                const data = await _resolveSyncFrame(await res.json());

                if (data.status === 'waiting_for_others') {
                    return;
                }

                if (data.status === 'waiting_for_block') {
                    if (data.target !== undefined && data.target !== null) lastGambleTargetBlock = data.target;
                    const tgtNum = (data.target !== undefined && data.target !== null) ? parseInt(data.target, 10) : null;
                    if (tgtNum && tgtNum !== lastBetsClosedTargetBlock && !awaitingGambleExecution && (!betsClosedUntilMs || betsClosedUntilMs <= Date.now())) {
                        startBetsClosedBuffer({ targetBlock: tgtNum, serverHash: getBatchServerHashFromUI(), opponentLabel: null, bscBlockCurrent: data.bsc_block_current || null });
                        return;
                    }

                    // Ensure block race UI is visible (idempotent, no repaint if already shown)
                    const raceEl = document.getElementById('gambleBlockRace');
                    if (raceEl) raceEl.classList.remove('hidden');
                    const scene = document.getElementById('sceneCoin');
                    if (scene) scene.style.display = 'none';

                    // Smooth sync: update ground truth, only advance display forward
                    if (data.target) _gambleBlockTarget = parseInt(data.target, 10);
                    if (data.bsc_block_current) {
                        const serverBlock = parseInt(data.bsc_block_current, 10);
                        _gambleBlockCurrent = serverBlock;
                        if (_gambleBlockDisplay === 0 || _gambleBlockDisplay < serverBlock) {
                            _gambleBlockDisplay = serverBlock;
                        }
                    }

                    // Target (static, only write if changed)
                    const tgtEl = document.getElementById('gambleBlockTargetVal');
                    if (tgtEl && _gambleBlockTarget) tgtEl.innerText = '#' + _gambleBlockTarget.toLocaleString();

                    // Repaint with the server block; the ambient audio starts once
                    _gambleUpdateDisplay();
                    _gambleTickerStart();

                    // Render gamblers (safe — skips if already drawn)
                    if (data.gamblers) _gambleRenderGamblers(data.gamblers);
                    return;
                }

                if (data.status === 'resolving') {
                    _gambleTickerStop();
                    const raceHide1 = document.getElementById('gambleBlockRace');
                    if (raceHide1) raceHide1.classList.add('hidden');
                    return;
                }

                if (data.status === 'just_resolved' || data.status === 'finished') {
                    stopGamblePolling();
                    const raceHide2 = document.getElementById('gambleBlockRace');
                    if (raceHide2) raceHide2.classList.add('hidden');

                    if (data.block_hash) lastGambleBlockHash = data.block_hash;
                    if (data.outcome) lastGambleOutcome = data.outcome;
                    if (data.result) lastGambleResult = data.result;

                    startStatusPolling();

                    if (typeof document !== 'undefined') {
                        const sec = document.getElementById('fmBatchSection');
                        if (sec) sec.classList.remove('hidden');
                        const tgtEl = document.getElementById('fmBatchTarget');
                        if (tgtEl && lastGambleTargetBlock) tgtEl.innerText = lastGambleTargetBlock;
                        const hashEl = document.getElementById('fmBatchBlockHash');
                        if (hashEl && lastGambleBlockHash) hashEl.innerText = lastGambleBlockHash;
                        const batchLink = document.getElementById('fmBatchBlockLink');
                        if (batchLink && lastGambleTargetBlock) batchLink.href = 'https://bscscan.com/block/' + lastGambleTargetBlock;
                    }
                    return;
                }

                if (data.status === 'error') {
                    stopGamblePolling();
                }
            } catch (e) {
                console.error(e);
            }
        }

        function setGambleOverlayText(html) {
            // No-op: legacy fallback removed. Block race UI is the primary display.
        }

        function getBatchServerHashFromUI() {
            const el = document.getElementById('fmBatchServerHash');
            if (!el) return null;
            const txt = (el.innerText || '').trim();
            if (!txt || txt === '...' || txt === '?') return null;
            return txt;
        }

        // ============================================================
        // DECISION ALERT ENGINE (Peer pressure toasts)
        // Detects when a peer winner's choice changes from 'pending'
        // and fires a provocative, themed toast notification.
        // ============================================================
        // --- ACTIVE DECIDER taunts (2nd person — YOU are still deciding) ---
        const _DA_ACTIVE_GAMBLE = [
            '💀 <strong>{name}</strong> acaba de apostar TODO! ¿TÚ te arriesgaras?',
            '🔥 <strong>{name}</strong> Eligio Doble o Nada! ¿Vas a quedarte mirando?',
            '🎰 <strong>{name}</strong> lo arriesgó TODO. La presión está en TI ahora...',
            '💣 <strong>{name}</strong> activó modo KAMIKAZE. ¿Te atreves a seguirlo?',
            '👀 <strong>{name}</strong> Decidió jugársela. TU TURNO. No decepciones.',
            '🔥 <strong>{name}</strong> no le tiene miedo a nada. ¿Tú sí, o qué?'
        ];
        const _DA_ACTIVE_SAFE = [
            `${_sfBananosImg(16)} <strong>{name}</strong> aseguró la bolsa. ¿Tú también juegas a lo seguro?`,
            '🏦 <strong>{name}</strong> tomó el dinero y salió corriendo. ¿Eres más valiente que eso?',
            '✅ <strong>{name}</strong> eligió lo seguro. Pero la gloria espera a los que se atreven...',
            '🛡️ <strong>{name}</strong> jugó conservador. Los ganadores eligen diferente... ¿y tú?',
            '😏 <strong>{name}</strong> no se la jugó. ¿Vas a ser otro más?'
        ];
        // --- SPECTATOR taunts (3rd person — hype commentator) ---
        const _DA_SPEC_GAMBLE = [
            '🔥 <strong>{name}</strong> lo arriesgó TODO! ¿Qué harán los demás jugadores?',
            '💀 <strong>{name}</strong> fue con todo en Doble o Nada!',
            '⚡ <strong>{name}</strong> eligió el camino peligroso! ¿Quién lo seguirá?',
            '💣 <strong>{name}</strong> activó DOBLE O NADA! ¿Habrá otro valiente?',
            '🐉 <strong>{name}</strong> no le tiembla el pulso! ¿Los demás se atreverán?'
        ];
        const _DA_SPEC_SAFE = [
            '🛡️ <strong>{name}</strong> aseguró su premio. ¿Los demás arriesgarán?',
            `${_sfBananosImg(16)} <strong>{name}</strong> jugó a lo seguro. ¿Alguien tendrá el valor de ir por todo?`,
            '🤝 <strong>{name}</strong> se llevó lo suyo. La pregunta es... ¿quién va por más?',
            '🏦 <strong>{name}</strong> cobró y se fue. ¿Habrá alguien con sangre fría para apostar?'
        ];

        // --- LAST DECIDER taunts (singular, maximum pressure — YOU are the ONLY one left) ---
        const _DA_LAST_GAMBLE = [
            '🔥 <strong>{name}</strong> acaba de apostar TODO! Eres el último. ¿Qué harás TÚ?',
            '💀 <strong>{name}</strong> se la jugó por el doble. Todos los ojos están en TI ahora.',
            '⚡ <strong>{name}</strong> eligió el riesgo. Eres el único que queda. ¿Tienes el coraje?',
            '🎲 <strong>{name}</strong> tiró los dados. La decisión final es TUYA.',
            '💥 <strong>{name}</strong> lo arriesgó todo. ¿Serás lo suficientemente valiente para seguirlo?'
        ];
        const _DA_LAST_SAFE = [
            `${_sfBananosImg(16)} <strong>{name}</strong> aseguró su premio. Eres el último en decidir. ¿Juegas seguro o te arriesgas?`,
            '🛡️ <strong>{name}</strong> eligió la seguridad. Ahora todo depende de TI. ¿Qué harás?',
            '🔒 <strong>{name}</strong> cerró el trato. Eres el único que queda. ¿Te atreves a ir por el doble?',
            '💼 <strong>{name}</strong> se llevó lo suyo. La última decisión es TUYA. ¿Seguro o arriesgado?',
            '🏦 <strong>{name}</strong> cobró y salió. Eres el último. ¿Seguirás su ejemplo o te la juegas?',
            '✅ <strong>{name}</strong> jugó conservador. Todos esperan TU movimiento final.'
        ];

        function _removeDecisionAlerts(immediate = false, persistentOnly = false) {
            const alerts = Array.from(document.querySelectorAll('.decision-alert')).filter(el => {
                return !persistentOnly || el.classList.contains('da-persistent');
            });

            alerts.forEach(el => {
                if (el._decisionAlertDismissTimer) {
                    clearTimeout(el._decisionAlertDismissTimer);
                    el._decisionAlertDismissTimer = null;
                }
                if (el._decisionAlertRemoveTimer) {
                    clearTimeout(el._decisionAlertRemoveTimer);
                    el._decisionAlertRemoveTimer = null;
                }

                if (immediate) {
                    el.remove();
                    return;
                }

                if (el.classList.contains('da-exit')) return;
                el.classList.add('da-exit');
                el._decisionAlertRemoveTimer = setTimeout(() => {
                    el.remove();
                }, 500);
            });
        }

        function _fireDecisionAlert(username, avatar, choice, perspective, pendingCount = 2) {
            const container = document.getElementById('decisionAlertContainer');
            if (!container) return;

            _removeDecisionAlerts(true);

            const isGamble = (choice === 'gamble_pending');
            const isPersistent = (perspective === 'active');
            const isLastDecider = (pendingCount === 1);
            
            // Select appropriate taunts based on context
            let taunts;
            if (perspective === 'active') {
                // Active decider: use singular pressure if they're the last one
                if (isLastDecider) {
                    taunts = isGamble ? _DA_LAST_GAMBLE : _DA_LAST_SAFE;
                } else {
                    taunts = isGamble ? _DA_ACTIVE_GAMBLE : _DA_ACTIVE_SAFE;
                }
            } else {
                // Spectator: use standard 3rd person taunts
                taunts = isGamble ? _DA_SPEC_GAMBLE : _DA_SPEC_SAFE;
            }
            
            const msg = taunts[Math.floor(Math.random() * taunts.length)].replace('{name}', escapeHtml(username));
            const icon = isGamble ? '🔥' : _sfBananosImg(18);
            const theme = isGamble ? 'gamble' : 'safe';
            const enterClass = isGamble ? ' da-enter' : '';
            const persistClass = isPersistent ? ' da-persistent' : '';
            const avatarSrc = avatar && avatar.includes('/')
                ? avatar
                : '../../assets/avatars/' + (avatar || 'default.png');

            const el = document.createElement('div');
            el.className = `decision-alert decision-alert--${theme}${enterClass}${persistClass}`;
            el.innerHTML = `
                <div class="da-avatar-ring">
                    <img src="${escapeHtml(avatarSrc)}" alt="${escapeHtml(username)}">
                    <div class="da-icon-badge">${icon}</div>
                </div>
                <div class="da-body">
                    <div class="da-body-text">${msg}</div>
                </div>
                <div class="da-progress"><div class="da-progress-bar"></div></div>
            `;
            container.appendChild(el);

            if (!isPersistent) {
                // Spectator: auto-dismiss after 4.5s
                el._decisionAlertDismissTimer = setTimeout(() => {
                    el.classList.add('da-exit');
                    el._decisionAlertRemoveTimer = setTimeout(() => el.remove(), 500);
                }, 4500);
            }
        }

        function _clearPersistentAlerts() {
            _removeDecisionAlerts(true, true);
        }

        function _checkPeerDecisionAlerts(winners) {
            if (!Array.isArray(winners) || winners.length === 0) return;

            // STRICT RULE: Disable Decision Alert system entirely when only 1 winner
            // There is no one else to pressure, so no notifications should fire.
            if (winners.length === 1) {
                _clearPersistentAlerts();
                return;
            }

            // GUARD: If all decisions are in, don't fire any alerts (cinematic is about to start)
            // This prevents the "last player" bug where a toast fires right as the recap begins
            const allDecided = winners.every(w => {
                const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                return c !== '' && c !== 'pending';
            });
            if (allDecided) {
                _clearPersistentAlerts();
                return;
            }

            // CONTEXT-AWARE GUARD: Count remaining pending winners
            // Fire alerts as long as there's at least ONE pending winner to receive the message
            // Only shut down when pendingCount === 0 (everyone has decided)
            const pendingCount = winners.filter(w => {
                const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                return c === '' || c === 'pending';
            }).length;

            if (pendingCount === 0) {
                _clearPersistentAlerts();
                return;
            }

            // Determine perspective: am I a winner still deciding?
            const myRow = winners.find(w => w && isMyWinner(w));
            const iAmStillDeciding = myRow && (myRow.choice === 'pending' || myRow.hud_state === 'thinking');
            const perspective = iAmStillDeciding ? 'active' : 'spectator';

            // Auto-clear persistent alerts when the active decider finally decides
            if (!iAmStillDeciding) {
                _clearPersistentAlerts();
            }

            // Compare current choices to previous snapshot
            winners.forEach(w => {
                if (!w || !w.user_id) return;
                if (myRow && isMyWinner(w)) return; // Skip self

                const prevChoice = _prevWinnersChoices[w.user_id] || 'pending';
                const currChoice = w.choice || 'pending';

                // Detect transition from 'pending' to a finalized state
                if (prevChoice === 'pending' && currChoice !== 'pending') {
                    _fireDecisionAlert(
                        w.username || 'Jugador',
                        w.avatar || 'default.png',
                        currChoice,
                        perspective,
                        pendingCount  // Pass pendingCount for singular grammar detection
                    );
                }
            });

            // Update snapshot
            const snap = {};
            winners.forEach(w => { if (w && w.user_id) snap[w.user_id] = w.choice || 'pending'; });
            _prevWinnersChoices = snap;
        }

        // ============================================================
        // CINEMATIC TRANSITION ENGINE
        // Scene 1 (0-5s): Decision Recap cards
        // Scene 2 (5-10s): DOBLE O NADA Mortal Kombat slam
        // ============================================================
        function _cinSpawnParticles(container, count) {
            if (!container) return;
            for (let i = 0; i < count; i++) {
                const p = document.createElement('div');
                const isEmber = Math.random() > 0.4;
                p.className = 'cinematic-particle ' + (isEmber ? 'cinematic-particle--ember' : 'cinematic-particle--spark');
                p.style.left = (Math.random() * 100) + '%';
                p.style.bottom = (-10 - Math.random() * 20) + '%';
                p.style.setProperty('--spark-x', ((Math.random() - 0.5) * 120) + 'px');
                const dur = 1.5 + Math.random() * 2;
                p.style.animationDuration = dur + 's';
                p.style.animationDelay = (Math.random() * 3) + 's';
                container.appendChild(p);
                setTimeout(() => p.remove(), (dur + 4) * 1000);
            }
        }

        function _cinBuildRecapCards(winners) {
            const container = document.getElementById('cinematicRecapCards');
            if (!container) return;
            container.innerHTML = '';

            const list = Array.isArray(winners) ? winners : [];
            container.className = 'cinematic-recap-cards';
            if (list.length === 1) container.classList.add('cinematic-recap-cards--single');
            if (list.length > 1) container.classList.add('cinematic-recap-cards--multi');

            const titleEl = document.getElementById('cinematicRecapTitle');
            if (titleEl) {
                titleEl.innerText = (list.length === 1) ? 'Decisión Tomada' : 'Decisiones Tomadas';
            }
            list.forEach((w, idx) => {
                const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                let emoji = '', label = '', cssClass = '';
                if (c === 'original' || c === 'premio') {
                    emoji = '\uD83C\uDF81'; label = 'PREMIO'; cssClass = 'original';
                } else if (c === 'cashback') {
                    emoji = _sfBananosImg(44); label = 'CASHBACK'; cssClass = 'cashback';
                } else if (c === 'gamble_pending' || c === 'double_won' || c === 'double_lost') {
                    emoji = '\uD83D\uDD25'; label = 'DOBLE O NADA'; cssClass = 'gamble';
                } else {
                    emoji = '\u2705'; label = 'DECIDIDO'; cssClass = 'original';
                }

                const avatar = (w && w.avatar) ? w.avatar : 'default.png';
                const name = (w && w.username) ? escapeHtml(w.username) : 'Jugador';
                const isGamble = cssClass === 'gamble';

                const card = document.createElement('div');
                card.className = 'cinematic-recap-card' + (isGamble ? ' cinematic-recap-card--gamble' : '');
                card.style.animationDelay = (0.4 + idx * 0.15) + 's';
                card.innerHTML = `
                    <div class="cinematic-recap-emoji">${emoji}</div>
                    <img src="../../assets/avatars/${escapeHtml(avatar)}" alt="${name}" class="cinematic-recap-avatar">
                    <div class="cinematic-recap-name">${name}</div>
                    <div class="cinematic-recap-choice cinematic-recap-choice--${cssClass}">${label}</div>
                `;
                container.appendChild(card);
            });
        }

        function playCinematicTransition(winners, hasGambler) {
            return new Promise(resolve => {
                cinematicSequenceInProgress = true;
                const overlay = document.getElementById('cinematicOverlay');
                const scene1 = document.getElementById('cinematicScene1');
                const scene2 = document.getElementById('cinematicScene2');
                if (!overlay || !scene1 || !scene2) {
                    cinematicSequenceInProgress = false;
                    resolve();
                    return;
                }

                // Hide gamble overlay & HUD during cinematic
                const go = document.getElementById('gambleOverlay');
                if (go) go.classList.add('hidden');
                const mo = document.getElementById('mainAnimOverlay');
                if (mo) mo.classList.add('hidden');
                const hc = document.getElementById('hudContainer');
                if (hc) hc.classList.add('hidden');
                const _fl2 = document.getElementById('dbFloatingLayer');
                if (_fl2) _fl2.classList.add('hidden');

                // Reset block-race state so it can be re-bootstrapped after cinematic
                awaitingGambleExecution = false;
                lastBetsClosedTargetBlock = null;
                betsClosedUntilMs = 0;

                // === SCENE 1: Decision Recap ===
                _cinBuildRecapCards(winners);
                overlay.classList.remove('hidden');
                scene1.classList.remove('hidden');
                scene2.classList.add('hidden');

                // Audio placeholder: recap whoosh
                // AUDIO.cinematicRecap?.play().catch(() => {});

                const SCENE1_DURATION = 5000;

                if (!hasGambler) {
                    // === NO GAMBLE: Recap only (5s), then resolve elegantly ===
                    setTimeout(() => {
                        overlay.classList.add('hidden');
                        scene1.classList.add('hidden');
                        cinematicSequenceInProgress = false;
                        resolve();
                    }, SCENE1_DURATION);
                    return;
                }

                // === SCENE 2: DOBLE O NADA (5s - 10s) ===
                setTimeout(() => {
                    scene1.classList.add('hidden');
                    scene2.classList.remove('hidden');

                    // Force re-trigger CSS animations by cloning the text container
                    const textEl = document.getElementById('cinematicKombatText');
                    const subEl = document.getElementById('cinematicKombatSub');
                    if (textEl) {
                        const clone = textEl.cloneNode(true);
                        textEl.parentNode.replaceChild(clone, textEl);
                    }
                    if (subEl) {
                        const clone = subEl.cloneNode(true);
                        subEl.parentNode.replaceChild(clone, subEl);
                    }

                    // White flash on transition
                    const flash = document.getElementById('cinematicFlash');
                    if (flash) {
                        flash.classList.remove('flash-active');
                        void flash.offsetWidth;
                        flash.classList.add('flash-active');
                    }

                    // Screen shake on first text slam
                    setTimeout(() => {
                        overlay.classList.add('cinematic-shake');
                        // Audio: heavy impact
                        try { if (!AdminAudio.play('doble_intro')) SorteoSFX.playSlamImpact(); } catch(_) {}
                        setTimeout(() => overlay.classList.remove('cinematic-shake'), 500);
                    }, 300);

                    // Second shake on "NADA!"
                    setTimeout(() => {
                        overlay.classList.add('cinematic-shake');
                        // Audio: second impact
                        try { if (!AdminAudio.play('doble_intro')) SorteoSFX.playSlamImpact(); } catch(_) {}
                        setTimeout(() => overlay.classList.remove('cinematic-shake'), 500);
                    }, 1000);

                    // Spawn fire particles
                    const particlesEl = document.getElementById('cinematicParticles');
                    _cinSpawnParticles(particlesEl, 30);
                    setTimeout(() => _cinSpawnParticles(particlesEl, 20), 1500);
                    setTimeout(() => _cinSpawnParticles(particlesEl, 15), 3000);

                }, SCENE1_DURATION);

                // === END: Clean up and resolve (10s) ===
                setTimeout(() => {
                    overlay.classList.add('hidden');
                    scene1.classList.add('hidden');
                    scene2.classList.add('hidden');

                    // Clean up particles
                    const particlesEl = document.getElementById('cinematicParticles');
                    if (particlesEl) particlesEl.innerHTML = '';

                    cinematicSequenceInProgress = false;
                    resolve();
                }, SCENE1_DURATION + 5000);
            });
        }

        function shouldPlayCinematic(winners) {
            if (cinematicSequenceInProgress) return false;
            if (!Array.isArray(winners) || winners.length === 0) return false;

            // All decisions must be in (no one still thinking)
            const allDecided = winners.every(w => {
                const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                return c !== '' && c !== 'pending';
            });
            if (!allDecided) return false;

            // Keyed on raffle ID only — persists across status changes
            // (e.g. gamble_pending → double_won) so it never replays
            const key = 'cinematic_' + CONFIG.raffleId;
            if (cinematicSequencePlayedKey === key) return false;

            cinematicSequencePlayedKey = key;
            return true;
        }

        function winnersHaveGambler(winners) {
            if (!Array.isArray(winners)) return false;
            return winners.some(w => {
                const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                return c === 'gamble_pending' || c === 'double_won' || c === 'double_lost';
            });
        }

        function startBetsClosedBuffer({ targetBlock = null, serverHash = null, opponentLabel = null, bscBlockCurrent = null } = {}) {
            hardResetGambleAnimationDOM();
            rebuildGambleAnimationDOM();
            isGambling = true;
            const overlay = document.getElementById('gambleOverlay');
            if (overlay) overlay.classList.remove('hidden');
            const mo = document.getElementById('mainAnimOverlay');
            if (mo) mo.classList.remove('hidden');
            const wc = document.getElementById('animWheelContainer');
            if (wc) wc.classList.add('hidden');
            const sc = document.getElementById('animSlotContainer');
            if (sc) sc.classList.add('hidden');
            const scene = document.getElementById('sceneCoin');
            if (scene) scene.style.display = 'none';

            // Show the block race UI immediately
            const raceEl = document.getElementById('gambleBlockRace');
            if (raceEl) raceEl.classList.remove('hidden');

            // Populate gamble server hash sealed badge
            const gambleSealEl = document.getElementById('gambleSealHash');
            if (gambleSealEl) gambleSealEl.innerText = serverHash || '---';

            awaitingGambleExecution = true;
            if (targetBlock !== null && targetBlock !== undefined && targetBlock !== '') {
                const tb = parseInt(targetBlock, 10);
                if (Number.isFinite(tb) && tb > 0) {
                    lastGambleTargetBlock = tb;
                    lastBetsClosedTargetBlock = tb;
                    _gambleBlockTarget = tb;
                    // Set target on the race card
                    const tgtEl = document.getElementById('gambleBlockTargetVal');
                    if (tgtEl) tgtEl.innerText = '#' + tb.toLocaleString();
                }
            }

            // Seed current block so the ticker renders from second zero
            const bc = bscBlockCurrent || _gambleBlockCurrent;
            if (bc) {
                _gambleBlockCurrent = parseInt(bc, 10);
                _gambleBlockDisplay = _gambleBlockCurrent;
                _gambleUpdateDisplay();
                _gambleTickerStart();
            }

            if (!betsClosedUntilMs || betsClosedUntilMs <= Date.now()) {
                betsClosedUntilMs = Date.now() + 6000;
            }
            if (betsClosedTimer) {
                clearInterval(betsClosedTimer);
                betsClosedTimer = null;
            }
            betsClosedTimer = setInterval(() => {
                if (!awaitingGambleExecution || !betsClosedUntilMs) {
                    clearInterval(betsClosedTimer);
                    betsClosedTimer = null;
                    return;
                }
                if (Date.now() >= betsClosedUntilMs) {
                    awaitingGambleExecution = false;
                    betsClosedUntilMs = 0;
                    clearInterval(betsClosedTimer);
                    betsClosedTimer = null;
                    return;
                }
            }, 250);
        }

        function isDecisionCountdownActive() {
            return !!(decisionCountdownUntilMs && Date.now() < decisionCountdownUntilMs);
        }

        function stopDecisionCountdown() {
            if (decisionCountdownTimer) {
                clearInterval(decisionCountdownTimer);
                decisionCountdownTimer = null;
            }
            decisionCountdownUntilMs = 0;
            decisionCountdownKey = null;
            decisionCountdownMeta = { targetBlock: null, serverHash: null };
        }

        function startDecisionCountdown({ key, seconds = 7, targetBlock = null, serverHash = null } = {}) {
            if (!key) return;
            if (decisionCountdownKey === key) return;

            if (decisionCountdownTimer) {
                clearInterval(decisionCountdownTimer);
                decisionCountdownTimer = null;
            }

            decisionCountdownKey = key;
            decisionCountdownMeta = { targetBlock, serverHash };
            decisionCountdownUntilMs = Date.now() + (Math.max(1, parseInt(seconds, 10) || 7) * 1000);

            decisionCountdownTimer = setInterval(() => {
                if (!isDecisionCountdownActive()) {
                    clearInterval(decisionCountdownTimer);
                    decisionCountdownTimer = null;
                    decisionCountdownUntilMs = 0;
                    pollStatus();
                }
            }, 250);
        }

        function updateHUD(winners) {
            // Solo actuar si estamos en fase de decisión/final
            const container = document.getElementById('hudContainer'); // Necesitas crear este DIV en tu HTML
            if (!container) {
                _hudStop();
                return;
            }

            const isDecisionState = (state.status === 'waiting_for_winner' || state.status === 'waiting_for_decision' || state.status === 'waiting_for_winners');
            const list = Array.isArray(winners) ? winners : [];

            if (!isDecisionState || list.length === 0) {
                container.innerHTML = '';
                container.classList.add('hidden');
                const fl = document.getElementById('dbFloatingLayer');
                if (fl) fl.classList.add('hidden');
                hudDecisionTotalSeconds = 0;
                _hudStop();
                return;
            }

            const isThinkingWinner = (w) => {
                if (!w) return false;
                if (w.hud_state === 'thinking') return true;
                if (w.choice === 'pending') return true;
                if (w.choice === null || w.choice === undefined) return true;
                return false;
            };

            const decidedCount = list.filter(w => !isThinkingWinner(w) && w.choice !== null && w.choice !== undefined).length;
            const totalPlayers = list.length;

            const data = (state && state.lastData) ? state.lastData : null;
            let backendSeconds = 0;

            // Priority 1: First pending/thinking winner's time_to_decide (bulletproof for spectators)
            const firstPending = list.find(w => w && String(w.choice) === 'pending')
                || list.find(w => w && w.hud_state === 'thinking')
                || null;
            if (firstPending && firstPending.time_to_decide !== undefined && firstPending.time_to_decide !== null) {
                const t = parseInt(firstPending.time_to_decide, 10);
                if (Number.isFinite(t) && t > 0) backendSeconds = t;
            }

            // Priority 2: Fallback to maximum time_to_decide available in winners array
            if (!backendSeconds) {
                const allTimes = list
                    .map(w => parseInt((w && w.time_to_decide !== undefined && w.time_to_decide !== null) ? w.time_to_decide : 0, 10))
                    .filter(t => Number.isFinite(t) && t > 0);
                if (allTimes.length) backendSeconds = Math.max.apply(null, allTimes);
            }

            // Priority 3: data.endgame.time_to_decide (fallback, may be 0 for spectators)
            if (!backendSeconds && data && data.endgame && data.endgame.time_to_decide !== undefined && data.endgame.time_to_decide !== null) {
                const t = parseInt(data.endgame.time_to_decide, 10);
                if (Number.isFinite(t) && t > 0) backendSeconds = t;
            }

            // Priority 4: data.decision_timeout (root-level fallback from DB)
            // LAZY START guard: only use raw timeout if at least one winner has
            // a real time_to_decide (server timer activated). Otherwise the timer
            // is still paused and showing this value would cause a visual jump.
            const _anyTimerActive = list.some(w => w && w.time_to_decide !== undefined && w.time_to_decide !== null && parseInt(w.time_to_decide, 10) > 0);
            if (!backendSeconds && data && data.decision_timeout && _anyTimerActive) {
                const t = parseInt(data.decision_timeout, 10);
                if (Number.isFinite(t) && t > 0) backendSeconds = t;
            }

            // Priority 5: Decision countdown fallback
            if (!backendSeconds && isDecisionCountdownActive()) {
                const leftMs = Math.max(0, (decisionCountdownUntilMs || 0) - Date.now());
                backendSeconds = Math.ceil(leftMs / 1000);
            }

            if (!Number.isFinite(backendSeconds) || backendSeconds < 0) backendSeconds = 0;

            // Sync the smooth HUD deadline from backend data
            const _decisionTotalDenom = (data && data.decision_timeout) ? Math.max(1, parseInt(data.decision_timeout, 10)) : 0;
            if (backendSeconds > 0) _hudSyncDeadline(backendSeconds, _decisionTotalDenom || backendSeconds);

            // Derive display values from the deadline for the initial HTML build
            const secondsLeft = (_hudDeadlineMs > 0)
                ? Math.ceil(Math.max(0, _hudDeadlineMs - Date.now()) / 1000)
                : backendSeconds;
            const totalSeconds = Math.max(1, _hudTotal || 1);

            hudDecisionTotalSeconds = totalSeconds;

            const progress = Math.max(0, Math.min(100, (secondsLeft / totalSeconds) * 100));
            const isUrgent = progress < 30;

            const mm = Math.floor(secondsLeft / 60);
            const ss = String(secondsLeft % 60).padStart(2, '0');
            const timeLabel = `${mm}:${ss} restantes`;

            const countdownHtml = `
            <div class="decision-countdown" aria-label="Cuenta regresiva">
                <div class="decision-countdown__track">
                    <div id="hudProgressFill" class="decision-countdown__fill ${isUrgent ? 'is-urgent' : ''}" style="width:${progress}%;"></div>
                </div>
                <div class="decision-countdown__meta">
                    <span id="hudTimerText" class="decision-countdown__time ${isUrgent ? 'is-urgent' : ''}">${escapeHtml(timeLabel)}</span>
                    <span id="hudUrgentLabel" class="decision-countdown__urgent" style="${isUrgent ? '' : 'display:none;'}">¡Tiempo casi agotado!</span>
                </div>
            </div>
        `;

            const cardHtml = list.map(w => {
                const unameRaw = (w && w.username) ? String(w.username) : '';
                const uname = escapeHtml(unameRaw || '');
                const avatar = escapeHtml(w && w.avatar ? w.avatar : 'default.png');

                const thinking = isThinkingWinner(w);
                const hasDecided = !thinking && w && w.choice !== null && w.choice !== undefined;

                let decisionEmoji = '';
                let decisionText = '';

                const c = (w && w.choice !== undefined && w.choice !== null) ? String(w.choice) : '';
                if (c === 'original' || c === 'premio') {
                    decisionEmoji = '🎁';
                    decisionText = 'Eligió Premio Original';
                    // Vitrina (pool): mostramos el ítem concreto o, mientras el
                    // ganador lo escoge en vivo, que está eligiendo.
                    const wPool = _sfPrizePool(w && w.prize ? w.prize : null);
                    if (wPool) {
                        const fpName = _sfFinalPrizeName(w);
                        if (fpName) {
                            decisionText = 'Eligió ' + fpName;
                        } else if (w.prize_state === 'awaiting_selection') {
                            decisionEmoji = '🛍️';
                            decisionText = 'Eligiendo ítem de la vitrina...';
                        }
                    }
                } else if (c === 'cashback') {
                    decisionEmoji = _sfBananosImg(16);
                    decisionText = 'Eligió Canjear (Cashback)';
                } else if (c === 'doble_nada' || c === 'double_nothing' || c === 'gamble' || c === 'gamble_pending' || c === 'double_won' || c === 'double_lost') {
                    decisionEmoji = '🔥';
                    decisionText = '¡Eligió DOBLE O NADA!';
                } else if (hasDecided) {
                    decisionEmoji = 'âœ…';
                    decisionText = 'Decisión registrada';
                }

                // Marca visual para los espectadores: si el ganador dejó una
                // pre-elección VÁLIDA que se aplicará, la mostramos mientras
                // "decide" (simula su decisión en vivo). Solo casos que de
                // verdad se aplicarán, para no engañar a quien mira.
                const preHint = thinking ? _sfWinnerPreChoiceHint(w, data) : null;
                let preHintClass = 'player-card__status--prechoice';
                if (preHint && preHint.cancelled) preHintClass += ' player-card__status--cancelled';
                else if (preHint && preHint.applying) preHintClass += ' player-card__status--applying';
                const statusLine = hasDecided
                    ? `<span class="player-card__status">${decisionEmoji} ${escapeHtml(decisionText)}</span>`
                    : (preHint
                        ? `<span class="player-card__status ${preHintClass}">${preHint.emoji} ${escapeHtml(preHint.text)}</span>`
                        : `<span class="player-card__status player-card__status--thinking">Pensando...</span>`);

                return `
                <div class="player-card ${hasDecided ? 'player-card--decided' : 'player-card--thinking'}">
                    <div class="player-card__avatar-wrap ${thinking ? 'is-thinking' : ''}">
                        <img src="../../assets/avatars/${avatar}" alt="${uname}" class="player-card__avatar-img" />
                        ${thinking ? `
                            <span class="player-card__thinking-dot">
                                <span class="player-card__thinking-ping"></span>
                                <span class="player-card__thinking-core"></span>
                            </span>
                        ` : ''}
                    </div>
                    <div class="player-card__info">
                        <span class="player-card__name">${uname}</span>
                        ${statusLine}
                    </div>
                    ${hasDecided ? '<div class="player-card__celebrate">🎉</div>' : ''}
                </div>
            `;
            }).join('');

            // P7: aviso GLOBAL bajo el contador. Mientras la pre-elección de
            // algún ganador se está auto-aplicando (ventana ≈15s) lo anunciamos;
            // y si alguien canceló la suya, lo destacamos con dramatismo.
            let _applyingCount = 0, _cancelledCount = 0;
            list.forEach(w => {
                if (!isThinkingWinner(w)) return;
                if (w && (w.pre_apply_cancelled === true || w.pre_apply_cancelled === 1)) { _cancelledCount++; return; }
                const h = _sfWinnerPreChoiceHint(w, data);
                if (h && h.applying) _applyingCount++;
            });
            let preApplyBannerHtml = '';
            if (_applyingCount > 0) {
                preApplyBannerHtml +=
                    '<div class="decision-preapply"><i class="fas fa-bolt" aria-hidden="true"></i> ' +
                    'Aplicando preselecci\u00F3n \u2014 si no eligen nada, se aplicar\u00E1 autom\u00E1ticamente.</div>';
            }
            if (_cancelledCount > 0) {
                preApplyBannerHtml +=
                    '<div class="decision-preapply decision-preapply--cancel"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' +
                    _cancelledCount + (_cancelledCount === 1 ? ' ganador cancel\u00F3' : ' ganadores cancelaron') +
                    ' su preselecci\u00F3n \u2014 \u00A1decide(n) en vivo!</div>';
            }

            // DYNAMIC PLURALIZATION: Main header and counter
            const headerText = (totalPlayers === 1)
                ? '¡El ganador está tomando una decisión!'
                : '¡Los ganadores están eligiendo!';
            
            const counterHtml = (totalPlayers === 1)
                ? '<div class="decision-badge" style="opacity: 0.6;">Esperando decisión...</div>'
                : `<div class="decision-badge">${decidedCount}/${totalPlayers} Decidieron</div>`;

            const html = `
            <div class="decision-board__inner">
                <header class="decision-header">
                    ${countdownHtml}
                    ${preApplyBannerHtml}
                    <h1 class="decision-title">${headerText}</h1>
                    ${counterHtml}
                </header>

                <section class="decision-players" aria-label="Lista de ganadores">
                    ${cardHtml}
                </section>

                <footer class="decision-legend" aria-label="Leyenda">
                    <div class="decision-legend__title">¿Qué pueden elegir los ganadores?</div>
                    <div class="decision-legend__items ${totalPlayers === 1 ? 'single-winner-layout' : 'multi-winner-layout'}">
                        <div class="decision-legend__card decision-legend__card--premio">
                            <div class="decision-legend__icon"><img src="https://res.cloudinary.com/daqenngt6/image/upload/v1773164765/PNGREGA_gbkgvo.png" alt="" aria-hidden="true" class="decision-legend__icon-img" /></div>
                            <div class="decision-legend__label">Premio Original</div>
                            <div class="decision-legend__desc">Se lleva el premio original directamente a su inventario.</div>
                            <div class="decision-legend__tooltip">Se lleva el premio original directamente a su inventario.</div>
                        </div>
                        <div class="decision-legend__card decision-legend__card--cashback">
                            <div class="decision-legend__icon">${_sfBananosImg(44)}</div>
                            <div class="decision-legend__label">Cashback ${CONFIG.cashbackPercent || 50}%</div>
                            <div class="decision-legend__desc">Obtiene el ${CONFIG.cashbackPercent || 50}% del valor del premio en Bananos de forma segura.</div>
                            <div class="decision-legend__tooltip">Obtiene el ${CONFIG.cashbackPercent || 50}% del valor del premio en Bananos de forma segura.</div>
                        </div>
                        <div class="decision-legend__card decision-legend__card--doble">
                            <div class="decision-legend__icon"><i class="fas fa-skull" aria-hidden="true"></i></div>
                            <div class="decision-legend__label">Doble o Nada</div>
                            <div class="decision-legend__desc">Arriesga todo: 50% de probabilidad de duplicar el premio o perderlo.</div>
                            <div class="decision-legend__tooltip">Arriesga todo: 50% de probabilidad de duplicar el premio o perderlo.</div>
                        </div>
                    </div>
                </footer>
            </div>
        `;

            container.innerHTML = html;
            container.classList.remove('hidden');

            // Show the independent static floating icons layer (lives outside hudContainer)
            const fl = document.getElementById('dbFloatingLayer');
            if (fl) fl.classList.remove('hidden');
        }

        window.sendDecision = sendDecision;
        window.showGambleCoinPicker = function showGambleCoinPicker() {
            const modalBody = document.getElementById('decisionModalBody');
            if (!modalBody) return;
            modalBody.innerHTML = `
            <div class="dm-inner-screen modal-enter">
                <h3 class="dm-title" style="font-size:22px;">Elige tu bando</h3>
                <p class="dm-subtitle">¿Cara o Sello? Confía en tu instinto</p>
                <div class="dm-cards dm-cards--row">
                    <button class="dm-card dm-card--heads" onclick="window.confirmGamble('heads', 'coin')">
                        <div class="dm-card__icon" style="width: 101px; height: 101px; border-radius: 50%; overflow: hidden; background: url('https://res.cloudinary.com/dq3xsjgwi/image/upload/v1769835633/c_fj4zze.webp') center/cover no-repeat; border: 2px solid rgba(59, 130, 246, 0.4); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);"></div>
                        <div><div class="dm-card__label">Cara</div></div>
                    </button>
                    <button class="dm-card dm-card--tails" onclick="window.confirmGamble('tails', 'coin')">
                        <div class="dm-card__icon" style="width: 101px; height: 101px; border-radius: 50%; overflow: hidden; background: url('https://res.cloudinary.com/dq3xsjgwi/image/upload/v1769835628/s_jhmuvv.webp') center/cover no-repeat; border: 2px solid rgba(249, 115, 22, 0.4); box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);"></div>
                        <div><div class="dm-card__label">Sello</div></div>
                    </button>
                </div>
                <button class="dm-back-btn" onclick="window.goBackToGameModeSelection()" style="margin-top: 16px; width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #9ca3af; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                    <i class="fas fa-arrow-left" style="margin-right: 8px;"></i>Volver atrás
                </button>
                <p class="dm-footer-hint">Al elegir, entrarás en la cola de espera.</p>
            </div>
        `;
        };

        window.showGambleRoulettePicker = function showGambleRoulettePicker() {
            const modalBody = document.getElementById('decisionModalBody');
            if (!modalBody) return;
            modalBody.innerHTML = `
            <div class="dm-inner-screen modal-enter">
                <h3 class="dm-title" style="font-size:22px;">Elige tu color</h3>
                <p class="dm-subtitle">¿Rojo o Negro? La ruleta decidirá tu destino</p>
                <div class="dm-cards dm-cards--row">
                    <button class="dm-card dm-card--red" onclick="window.confirmGamble('red', 'roulette')">
                        <div class="dm-card__icon dm-roulette-chip dm-roulette-chip--red"><span>ROJO</span></div>
                        <div><div class="dm-card__label">Rojo</div><div class="dm-card__sublabel">¡Todo al rojo! 🗣️</div></div>
                    </button>
                    <button class="dm-card dm-card--black" onclick="window.confirmGamble('black', 'roulette')">
                        <div class="dm-card__icon dm-roulette-chip dm-roulette-chip--black"><span>NEGRO</span></div>
                        <div><div class="dm-card__label">Negro</div></div>
                    </button>
                </div>
                <button class="dm-back-btn" onclick="window.goBackToGameModeSelection()" style="margin-top: 16px; width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #9ca3af; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                    <i class="fas fa-arrow-left" style="margin-right: 8px;"></i>Volver atrás
                </button>
                <p class="dm-footer-hint">Al elegir, entrarás en la cola de espera.</p>
            </div>
        `;
        };

        // Back navigation: Return to main decision screen (3 options: Premio, Cashback, Doble o Nada)
        window.goBackToMainDecision = function goBackToMainDecision() {
            const modalBody = document.getElementById('decisionModalBody');
            if (!modalBody || !decisionModalBodyTemplate) return;
            modalBody.innerHTML = decisionModalBodyTemplate;
            
            // Re-apply option visibility based on decisionOptions
            if (decisionOptions && decisionOptions.allow_cashback) {
                const btnCashback = document.getElementById('btnCashback');
                if (btnCashback) btnCashback.classList.remove('hidden');
                const lbl = document.getElementById('lblCashbackVal');
                if (lbl && decisionOptions.cashback_percent) lbl.innerText = decisionOptions.cashback_percent;
            }
            if (decisionOptions && decisionOptions.allow_double) {
                const btnGamble = document.getElementById('btnGamble');
                if (btnGamble) btnGamble.classList.remove('hidden');
            }
        };
        
        // Back navigation: Return to game mode selection (Moneda, Ruleta, Tragamonedas)
        window.goBackToGameModeSelection = function goBackToGameModeSelection() {
            const allowChoice = !!(decisionOptions && parseInt(decisionOptions.allow_winner_animation_choice || 0, 10) === 1);
            if (!allowChoice) {
                // If choice is not allowed, go back to main decision
                window.goBackToMainDecision();
                return;
            }
            
            const modalBody = document.getElementById('decisionModalBody');
            if (!modalBody) return;
            modalBody.innerHTML = `
                <div class="dm-inner-screen modal-enter">
                    <h3 class="dm-title" style="font-size:22px;">Elige tu modo de juego</h3>
                    <p class="dm-subtitle">Selecciona cómo quieres arriesgarlo todo</p>
                    <div class="dm-cards">
                        <button class="dm-card dm-card--coin" onclick="window.showGambleCoinPicker()">
                            <div class="dm-card__icon">${_sfBananosImg(44)}</div>
                            <div><div class="dm-card__label">Moneda</div></div>
                        </button>
                        <button class="dm-card dm-card--roulette" onclick="window.showGambleRoulettePicker()">
                            <div class="dm-card__icon dm-card__icon--gamer">${_sfRouletteSvg(44)}</div>
                            <div><div class="dm-card__label">Ruleta</div></div>
                        </button>
                        <button class="dm-card dm-card--slot" onclick="window.confirmGamble(null, 'slot')">
                            <div class="dm-card__icon dm-card__icon--gamer">${_sfSlotMachineSvg(44)}</div>
                            <div><div class="dm-card__label">Slots</div></div>
                        </button>
                    </div>
                    <button class="dm-back-btn" onclick="window.goBackToMainDecision()" style="margin-top: 16px; width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #9ca3af; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-arrow-left" style="margin-right: 8px;"></i>Volver atrás
                    </button>
                    <p class="dm-footer-hint">Al elegir, entrarás en la cola de espera.</p>
                </div>
            `;
        };
        
        window.confirmGamble = confirmGamble;
        window.submitAction = submitAction;

        // ═══════════════════════════════════════════════════════════════
        // SKIP BRIDGE — Consumed by skip_controller.js
        // Exposes read-only flags + a single fastForward() action.
        // ═══════════════════════════════════════════════════════════════
        window.__SorteoSkip = {
            getFlags: function () {
                return {
                    isMainAnimating: isMainAnimating,
                    cinematicSequenceInProgress: cinematicSequenceInProgress,
                    gamblePlaybackInProgress: gamblePlaybackInProgress,
                    mainAnimPlayed: mainAnimPlayed,
                    revealInProgress: revealInProgress,
                    summaryShown: summaryShown,
                    trophyRoomRendered: trophyRoomRendered,
                    firstLoad: firstLoad,
                    initialSmartRouteDone: initialSmartRouteDone,
                    isGambling: isGambling,
                    status: state.status,
                    hasWinners: !!(state.lastData && state.lastData.winners && state.lastData.winners.length > 0),
                    animationPhase: (state.lastData && state.lastData.animation_phase) ? state.lastData.animation_phase : null,
                    serverTimeUtc: (state.lastData && state.lastData.server_time_utc) ? state.lastData.server_time_utc : null,
                    decisionPhaseReachedAt: (state.lastData && state.lastData.decision_phase_reached_at) ? state.lastData.decision_phase_reached_at : null,
                    earliestGambleResolvedAt: (state.lastData && state.lastData.earliest_gamble_resolved_at) ? state.lastData.earliest_gamble_resolved_at : null
                };
            },
            fastForward: function () {
                console.log('[SKIP-BRIDGE] fastForward() invoked.');

                // 0. HARD SILENCE — kill all audio immediately
                try {
                    Object.keys(AUDIO).forEach(function (k) {
                        var a = AUDIO[k];
                        if (a && typeof a.pause === 'function') {
                            a.pause();
                            a.currentTime = 0;
                        }
                    });
                    SlotClickSynth.silence();
                    WheelTickSynth.silence();
                    SorteoSFX.silence(); AdminAudio.silenceAll();
                } catch (_) {}

                // 1. Kill ALL running timers & animations
                hardResetMainAnimationDOM();
                hardResetGambleAnimationDOM();
                if (gamblePlaybackAdvanceTimeout) { clearTimeout(gamblePlaybackAdvanceTimeout); gamblePlaybackAdvanceTimeout = null; }
                if (gamblePlaybackStartTimeout) { clearTimeout(gamblePlaybackStartTimeout); gamblePlaybackStartTimeout = null; }
                if (postGambleCleanupTimeout) { clearTimeout(postGambleCleanupTimeout); postGambleCleanupTimeout = null; }
                if (finishGambleTimeout) { clearTimeout(finishGambleTimeout); finishGambleTimeout = null; }
                stopGamblePolling();
                stopDecisionCountdown();
                stopStatusPolling();
                _hudStop();
                _wrStop();

                // 2. Set definitive post-animation state
                mainAnimPlayed = true;
                revealInProgress = false;
                isMainAnimating = false;
                animFinishedSent = true;
                cinematicSequenceInProgress = false;
                cinematicSequencePlayedKey = 'cinematic_' + CONFIG.raffleId;

                // 3. Mark ALL gamble playbacks as played
                if (state.lastData && Array.isArray(state.lastData.winners)) {
                    for (var i = 0; i < state.lastData.winners.length; i++) {
                        var w = state.lastData.winners[i];
                        if (!w) continue;
                        var c = String(w.choice || '');
                        if (c === 'double_won' || c === 'double_lost') {
                            var k = getGamblePlaybackKey(w);
                            if (k) gamblePlaybackPlayedKeys.add(k);
                        }
                    }
                }
                gamblePlaybackInProgress = false;
                gamblePlaybackCurrentKey = null;
                gamblePlaybackCurrentWinner = null;

                // 4. Clean up gamble state
                isGambling = false;
                activeGamblerName = null;
                activeGamblerId = null;
                activeGambleAnim = null;
                activeGamblePredictionSide = null;
                gambleAnimPlayed = false;
                awaitingGambleExecution = false;
                betsClosedUntilMs = 0;

                // 5. Hide ALL overlays
                var overlayIds = ['mainAnimOverlay', 'gambleOverlay', 'cinematicOverlay', 'hudContainer', 'dbFloatingLayer', 'viewWinner'];
                for (var j = 0; j < overlayIds.length; j++) {
                    var el = document.getElementById(overlayIds[j]);
                    if (el) el.classList.add('hidden');
                }
                clearWinnerParticles();

                // 6. Reset render flags so the next poll renders fresh
                summaryShown = false;
                trophyRoomRendered = false;
                firstLoad = true;
                initialSmartRouteDone = false;
                pollLock = false;

                // 7. Re-trigger polling (the initial smart route will resolve state)
                startStatusPolling();
                return true;
            }
        };

        // =====================================================================
        //  VER SUPLENTES — Simulador post-sorteo 100% provably fair.
        //  Reproduce en el navegador EXACTAMENTE el mismo motor del servidor
        //  (SHA-256 + HMAC-SHA256, rechazo de sesgo de módulo, huecos de
        //  expulsados y redibujos deterministas) usando la semilla revelada y
        //  el hash del bloque BSC para mostrar la cadena de suplentes.
        // =====================================================================
        const _scn = {
            open: false,
            base: null,              // datos preparados del sorteo real
            subsByPrize: null,       // {position: [{n, ticket, user}]} suplentes por premio
            subPrize: 1,             // premio (posición) visible en la pestaña de suplentes
            subIdx: 0,
            subMine: null,           // mejor rango del usuario: {prize, n} | null
            decCache: new Map(),     // label -> decVal (HMAC cacheado; misma seed)
            masterSeed: null
        };

        const _SCN_RAND_SPACE = 4294967296; // 2^32 (idéntico al servidor)
        const _SCN_MAX_ATTEMPTS = 100000;

        async function _scnSha256Hex(str) {
            const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
            return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        async function _scnHmacHex(keyStr, msg) {
            const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(keyStr),
                { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
            const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(msg));
            return Array.from(new Uint8Array(sig)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        // decVal determinista de un slot label (primeros 8 hex del HMAC → entero).
        async function _scnDec(label) {
            if (_scn.decCache.has(label)) return _scn.decCache.get(label);
            const hex = await _scnHmacHex(_scn.masterSeed, label);
            const dec = parseInt(hex.slice(0, 8), 16);
            _scn.decCache.set(label, dec);
            return dec;
        }

        // ---------------------------------------------------------------------
        //  Preparación de datos: recta numérica real (con huecos), ganadores y
        //  quemados reales, y verificación de que la semilla ya fue revelada.
        // ---------------------------------------------------------------------
        function _scnPrepare() {
            const d = _lastStatusData;
            if (!d || d.status !== 'completed') return null;
            const audit = d.audit || {};
            const seed = audit.server_seed || (d.transparency && d.transparency.server_seed_secret) || null;
            const blockHash = audit.bsc_block_hash || (d.transparency && d.transparency.bsc_block_hash) || null;
            if (!seed || !blockHash) return null;

            const parts = Array.isArray(d.participants_list) ? d.participants_list : [];
            if (parts.length === 0) return null;

            // Tamaño de la recta: snapshot congelado (incluye huecos); fallback
            // defensivo al mayor ticket visible + huecos por expulsión.
            let maxTickets = parseInt(audit.max_tickets, 10) || 0;
            let maxSeen = -1;
            const byTicket = new Map();
            parts.forEach(function (p) {
                const t = parseInt(p.ticket, 10);
                if (isNaN(t) || t < 0) return;
                if (t > maxSeen) maxSeen = t;
                byTicket.set(t, p);
            });
            const expelled = new Set((audit.expelled_tickets || []).map(Number));
            expelled.forEach(function (t) { if (t > maxSeen) maxSeen = t; });
            if (maxTickets <= 0) maxTickets = maxSeen + 1;
            if (maxTickets <= 0) return null;

            // Recta real: índice = ticket físico; huecos = null (expulsados o vacíos).
            const line = [];
            for (let t = 0; t < maxTickets; t++) line.push(byTicket.get(t) || null);

            const winnersRows = Array.isArray(d.winners) ? d.winners : [];
            const burned = winnersRows.filter(w => (w.choice || '') === 'burned');
            const realWinners = winnersRows.filter(w => (w.choice || '') !== 'burned')
                .slice().sort((a, b) => (a.position || 0) - (b.position || 0));
            if (realWinners.length === 0) return null;

            return {
                seed: seed,
                blockHash: blockHash,
                line: line,
                maxTickets: maxTickets,
                expelled: expelled,
                burnCount: burned.length,
                winnersCount: realWinners.length,
                realWinners: realWinners,
                myUid: parseInt(CONFIG.me && CONFIG.me.userId, 10) || 0
            };
        }

        // ---------------------------------------------------------------------
        //  MOTOR: sorteo determinista sobre una recta arbitraria.
        //  lineArr: array donde el índice ES el ticket; null = hueco.
        //  Devuelve picks [{slot, ticket, user}] — los primeros burnCount son
        //  quemados y el resto ganadores (idéntico al servidor).
        // ---------------------------------------------------------------------
        async function _scnDraw(lineArr, totalSlots) {
            const maxTickets = lineArr.length;
            if (maxTickets <= 0) return [];
            const valid = new Map();
            const seenUsers = new Set();
            lineArr.forEach(function (p, t) {
                if (!p) return;
                valid.set(t, p);
                seenUsers.add(parseInt(p.user_id, 10));
            });
            if (seenUsers.size === 0) return [];

            const acceptLimit = _SCN_RAND_SPACE - (_SCN_RAND_SPACE % maxTickets);
            const picks = [];
            for (let i = 0; i < totalSlots; i++) {
                if (valid.size === 0) break;
                let attempt = 0, ticket = -1, dec = 0;
                while (attempt < _SCN_MAX_ATTEMPTS) {
                    const label = (attempt === 0) ? ('salt_index_' + i) : ('salt_index_' + i + '_r' + attempt);
                    dec = await _scnDec(label);
                    attempt++;
                    if (dec >= acceptLimit) continue;          // sesgo de módulo
                    const cand = dec % maxTickets;
                    if (!valid.has(cand)) continue;            // hueco / expulsado
                    ticket = cand;
                    break;
                }
                if (ticket < 0) {
                    // Guardarraíl determinista del servidor (caso patológico).
                    const remaining = Array.from(valid.keys()).sort((a, b) => a - b);
                    ticket = remaining[dec % remaining.length];
                }
                const pick = valid.get(ticket);
                const uid = parseInt(pick.user_id, 10);
                Array.from(valid.entries()).forEach(function (ent) {
                    if (parseInt(ent[1].user_id, 10) === uid) valid.delete(ent[0]);
                });
                // dec/attempts se exponen para mostrar la fórmula en vivo en la UI.
                picks.push({ slot: i, ticket: ticket, user: pick, dec: dec, attempts: attempt, maxTickets: maxTickets });
            }
            return picks;
        }

        // Recorta burn/winners igual que el servidor cuando quedan pocos usuarios.
        function _scnSlotPlan(lineArr) {
            const b = _scn.base;
            const users = new Set();
            lineArr.forEach(function (p) { if (p) users.add(parseInt(p.user_id, 10)); });
            const totalUsers = users.size;
            if (totalUsers === 0) return { burn: 0, winners: 0 };
            let burn = b.burnCount;
            if (burn >= totalUsers) burn = Math.max(0, totalUsers - 1);
            const winners = Math.min(b.winnersCount, Math.max(1, totalUsers - burn));
            return { burn: burn, winners: winners };
        }

        // Sorteo completo de una recta: devuelve solo los GANADORES por posición.
        async function _scnWinnersFor(lineArr) {
            const plan = _scnSlotPlan(lineArr);
            const picks = await _scnDraw(lineArr, plan.burn + plan.winners);
            return picks.slice(plan.burn).map(function (pk, idx) {
                return { position: idx + 1, ticket: pk.ticket, user: pk.user, dec: pk.dec, attempts: pk.attempts, maxTickets: pk.maxTickets };
            });
        }

        // ---------------------------------------------------------------------
        //  UI: apertura / cierre / navegación del modal
        // ---------------------------------------------------------------------
        function openScenariosModal() {
            const root = document.getElementById('scenariosModal');
            if (!root) return;
            _scn.base = _scnPrepare();
            _scn.open = true;
            root.classList.remove('hidden');
            document.body.classList.add('sf-scn-lock');
            if (_scn.base) {
                _scnSha256Hex(_scn.base.seed + _scn.base.blockHash).then(function (ms) {
                    if (_scn.masterSeed !== ms) { // seed nueva → caché inválida
                        _scn.decCache.clear();
                        _scn.subsByPrize = null;
                    }
                    _scn.masterSeed = ms;
                    _scnRender();
                    if (!_scn.subsByPrize) _scnComputeSubs();
                });
            }
            _scnRender();
        }

        function closeScenariosModal() {
            const root = document.getElementById('scenariosModal');
            if (root) root.classList.add('hidden');
            document.body.classList.remove('sf-scn-lock');
            _scn.open = false;
        }

        function _scnUserName(u) { return (u && u.username) ? u.username : 'Jugador'; }
        function _scnAvatar(u) {
            const a = (u && u.avatar) ? String(u.avatar) : 'default.png';
            return a.startsWith('http') ? a : ('../../assets/avatars/' + a);
        }
        function _scnIsMe(u) { return _scn.base && _scn.base.myUid > 0 && u && parseInt(u.user_id, 10) === _scn.base.myUid; }
        function _scnOrd(n) { return n + '°'; }

        // ---------------------------------------------------------------------
        //  RENDER PRINCIPAL
        // ---------------------------------------------------------------------
        function _scnRender() {
            const root = document.getElementById('scenariosModal');
            if (!root || !_scn.open) return;

            let body;
            if (!_scn.base) {
                body = '<div class="sf-scn-empty">🔒<h3>Aún no hay datos públicos</h3><p>Los suplentes se desbloquean cuando el sorteo termina y la semilla del servidor se revela.</p></div>';
            } else if (!_scn.masterSeed) {
                body = '<div class="sf-scn-empty">⏳<h3>Preparando suplentes…</h3><p>Cargando el motor provably fair.</p></div>';
            } else {
                body = _scnSubsHtml();
            }

            root.innerHTML =
                '<div class="sf-scn-backdrop" onclick="closeScenariosModal()"></div>' +
                '<div class="sf-scn-sheet">' +
                '  <button type="button" class="sf-scn-close" onclick="closeScenariosModal()" aria-label="Cerrar"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>' +
                '  <div class="sf-scn-body">' + body + '</div>' +
                '</div>';
        }

        // ---------------------------------------------------------------------
        //  MÓDULO 1: VER SUPLENTES
        // ---------------------------------------------------------------------
        const _SCN_SUBS_CAP = 50; // profundidad máxima de suplentes por premio

        // Suplentes POR PREMIO: el suplente k del premio p es quien ocuparía la
        // posición p si el ganador oficial (y los suplentes anteriores de ese
        // premio) no cumplieran los requisitos. La recta NO se renumera — un
        // descalificado no borra su ticket físico; simplemente deja de ser
        // elegible y el redibujo determinista (_rN) continúa la cadena, igual
        // que en el servidor.
        async function _scnComputeSubs() {
            const b = _scn.base;
            const byPrize = {};
            // Los ganadores reales de CUALQUIER premio no cuentan como suplentes:
            // ya tienen el suyo. Cada premio arranca su cadena sin ellos.
            const realWinnerIds = b.realWinners.map(function (w) { return parseInt(w.user_id, 10); });
            for (const rw of b.realWinners) {
                const pos = parseInt(rw.position, 10);
                const ineligible = new Set(realWinnerIds);
                const list = [];
                for (let k = 1; k <= _SCN_SUBS_CAP; k++) {
                    const holed = b.line.map(function (p) {
                        return (p && ineligible.has(parseInt(p.user_id, 10))) ? null : p;
                    });
                    const winners = await _scnWinnersFor(holed);
                    const cand = winners.find(function (w) { return w.position === pos; });
                    if (!cand) break;
                    list.push({ n: k, ticket: cand.ticket, user: cand.user });
                    ineligible.add(parseInt(cand.user.user_id, 10));
                }
                byPrize[pos] = list;
            }
            _scn.subsByPrize = byPrize;
            _scn.subPrize = parseInt(b.realWinners[0].position, 10);
            _scn.subIdx = 0; // SIEMPRE arrancar en el Suplente #1
            // ¿Soy suplente de algún premio? Guardar mi MEJOR rango (menor n).
            _scn.subMine = null;
            if (b.myUid > 0) {
                Object.keys(byPrize).forEach(function (pos) {
                    const i = byPrize[pos].findIndex(function (s) { return parseInt(s.user.user_id, 10) === b.myUid; });
                    if (i >= 0 && (!_scn.subMine || i + 1 < _scn.subMine.n)) {
                        _scn.subMine = { prize: parseInt(pos, 10), n: i + 1 };
                    }
                });
            }
            if (_scn.open) _scnRender();
        }

        function _scnSubList() {
            return (_scn.subsByPrize && _scn.subsByPrize[_scn.subPrize]) || [];
        }
        // Navegación de suplentes: parche solo del cuerpo (sin re-render del
        // modal completo → cambio instantáneo, sin parpadeos).
        function _scnSubsPatch() {
            const body = document.querySelector('#scenariosModal .sf-scn-body');
            if (body) body.innerHTML = _scnSubsHtml(); else _scnRender();
        }
        function _scnSubNav(delta) {
            const list = _scnSubList();
            if (list.length === 0) return;
            _scn.subIdx = Math.min(list.length - 1, Math.max(0, _scn.subIdx + delta));
            _scnSubsPatch();
        }
        function _scnSubJump(i) { _scn.subIdx = i; _scnSubsPatch(); }
        function _scnSubPrizeGo(pos) { _scn.subPrize = pos; _scn.subIdx = 0; _scnSubsPatch(); }

        function _scnSubsHtml() {
            const b = _scn.base;
            if (!_scn.subsByPrize) {
                return '<div class="sf-scn-empty">🎲<h3>Sorteando suplentes…</h3><p>Ejecutando la cadena provably fair.</p></div>';
            }
            const multi = b.realWinners.length > 1;

            // Banner inteligente: mi MEJOR rango de suplente entre todos los premios.
            let meBadge = '';
            if (_scn.subMine) {
                const where = multi ? (' del ' + _scnOrd(_scn.subMine.prize) + ' premio') : ' de este sorteo';
                meBadge = _scn.subMine.n <= 20
                    ? '<div class="sf-scn-mebadge">🎉 ¡Felicidades! Eres el <strong>Suplente ' + _scn.subMine.n + '</strong>' + where + '</div>'
                    : '<div class="sf-scn-mebadge sf-scn-mebadge--lol">😂 Jajaja… eres el <strong>Suplente ' + _scn.subMine.n + '</strong>' + where + '. Casi casi.</div>';
            }

            // Pestañas por premio (solo si hay más de un ganador).
            let prizeTabs = '';
            if (multi) {
                prizeTabs = '<div class="sf-scn-chips sf-scn-ptabs">' + b.realWinners.map(function (rw) {
                    const pos = parseInt(rw.position, 10);
                    return '<button type="button" class="sf-scn-chip' + (pos === _scn.subPrize ? ' is-active' : '') + '" onclick="_scnSubPrizeGo(' + pos + ')">🏆 Premio ' + _scnOrd(pos) + '</button>';
                }).join('') + '</div>';
            }

            const list = _scnSubList();
            if (list.length === 0) {
                return '<header class="sf-scn-hero sf-scn-hero--compact"><h2 class="sf-scn-title">🥈 Ver Suplentes</h2></header>' + meBadge + prizeTabs +
                    '<div class="sf-scn-empty">🤷<h3>No hay suplentes posibles</h3><p>No quedan participantes elegibles para este premio.</p></div>';
            }
            const s = list[Math.min(_scn.subIdx, list.length - 1)];
            const isMe = _scnIsMe(s.user);

            let chips = '';
            const chipCount = Math.min(8, list.length);
            for (let i = 0; i < chipCount; i++) {
                chips += '<button type="button" class="sf-scn-chip' + (i === _scn.subIdx ? ' is-active' : '') + '" onclick="_scnSubJump(' + i + ')">' + (i + 1) + '</button>';
            }
            if (b.myUid > 0) {
                const mine = list.findIndex(function (x) { return parseInt(x.user.user_id, 10) === b.myUid; });
                if (mine >= chipCount) chips += '<button type="button" class="sf-scn-chip sf-scn-chip--me' + (mine === _scn.subIdx ? ' is-active' : '') + '" onclick="_scnSubJump(' + mine + ')">YO · ' + (mine + 1) + '</button>';
            }

            return '' +
                '<header class="sf-scn-hero sf-scn-hero--compact">' +
                '  <h2 class="sf-scn-title">🥈 Ver Suplentes</h2>' +
                '  <p class="sf-scn-sub">La misma fórmula del sorteo, un puesto más allá. Así habría seguido la lista.</p>' +
                '</header>' +
                meBadge +
                prizeTabs +
                '<div class="sf-scn-subcard-wrap">' +
                '  <button type="button" class="sf-scn-arrow" onclick="_scnSubNav(-1)" ' + (_scn.subIdx === 0 ? 'disabled' : '') + '>‹</button>' +
                '  <div class="sf-scn-subcard' + (isMe ? ' is-me' : '') + '">' +
                '    <div class="sf-scn-subrank">SUPLENTE <span>' + s.n + '</span>' + (multi ? ' · PREMIO ' + _scnOrd(_scn.subPrize) : '') + '</div>' +
                '    <img class="sf-scn-subavatar" src="' + escapeHtml(_scnAvatar(s.user)) + '" alt="">' +
                '    <div class="sf-scn-subname">' + escapeHtml(_scnUserName(s.user)) + (isMe ? ' <span class="sf-scn-you">TÚ</span>' : '') + '</div>' +
                '    <div class="sf-scn-subticket">🎟️ Ticket #' + s.ticket + '</div>' +
                '  </div>' +
                '  <button type="button" class="sf-scn-arrow" onclick="_scnSubNav(1)" ' + (_scn.subIdx >= list.length - 1 ? 'disabled' : '') + '>›</button>' +
                '</div>' +
                '<div class="sf-scn-chips">' + chips + '</div>' +
                '<div class="sf-scn-note">ℹ️ Lista solo por diversión: el suplente aplica únicamente si el ganador no cumple los requisitos del sorteo.</div>';
        }
