<?php
// Archivo: admin_footer.php (v7.0 - Sidebar superpuesto, tema en el pie del menú)
?>
            </main>
        </div>
    </div>
    
    <!-- El contenedor del Toast va aquí, fuera de #main-container, pero antes de la etiqueta final de body -->
    <div id="toastContainer" class="fixed bottom-6 right-6 z-[1050] flex flex-col gap-3"></div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const htmlEl = document.documentElement;
            const body = document.body;
            const glitchSound = document.getElementById('glitchSound');
            const clapSound = document.getElementById('clapSound');

            // Menú lateral: oculto por defecto, se superpone al abrir y se cierra al navegar,
            // al pulsar fuera o con Escape. Ctrl+B alterna. "Fijar" (solo escritorio) lo deja visible.
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebarClose = document.getElementById('sidebar-close');
            const sidebarPin = document.getElementById('sidebar-pin');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            if (sidebar && sidebarToggle && sidebarOverlay) {
                const isPinned = () => body.classList.contains('sidebar-pinned') && window.innerWidth >= 1024;
                const openSidebar = () => { body.classList.add('sidebar-open'); };
                const closeSidebar = () => { body.classList.remove('sidebar-open'); };
                const toggleSidebar = () => { if (isPinned()) return; body.classList.contains('sidebar-open') ? closeSidebar() : openSidebar(); };

                const syncPinUI = () => {
                    const on = body.classList.contains('sidebar-pinned');
                    if (sidebarPin) { sidebarPin.classList.toggle('is-on', on); sidebarPin.title = on ? 'Dejar de fijar (volver a modo oculto)' : 'Fijar menú (siempre visible)'; }
                };

                sidebarToggle.addEventListener('click', toggleSidebar);
                sidebarOverlay.addEventListener('click', closeSidebar);
                if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
                sidebar.querySelectorAll('nav a.sidebar-link').forEach(a => a.addEventListener('click', () => { if (!isPinned()) closeSidebar(); }));
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && body.classList.contains('sidebar-open')) { closeSidebar(); return; }
                    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'b' || e.key === 'B')) {
                        const t = e.target; if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
                        e.preventDefault(); toggleSidebar();
                    }
                });
                if (sidebarPin) {
                    sidebarPin.addEventListener('click', () => {
                        const on = !body.classList.contains('sidebar-pinned');
                        body.classList.toggle('sidebar-pinned', on);
                        localStorage.setItem('sidebarPinned', on ? 'true' : 'false');
                        if (on) closeSidebar();
                        syncPinUI();
                    });
                }
                syncPinUI();
            }

            // Lógica de tema y gráfico
            const toggleThemeBtn = document.getElementById('toggleTheme');
            if (toggleThemeBtn) {
                const toggleGlitchBtn = document.getElementById('toggleGlitch');
                
                const updateThemeUI = (theme) => {
                    const sunIcon = toggleThemeBtn.querySelector('.fa-sun');
                    const moonIcon = toggleThemeBtn.querySelector('.fa-moon');
                    if (theme === 'light') { 
                        sunIcon.classList.add('hidden'); 
                        moonIcon.classList.remove('hidden'); 
                    } else { 
                        moonIcon.classList.add('hidden'); 
                        sunIcon.classList.remove('hidden'); 
                    }
                    if (window.activityChartInstance) { 
                        window.activityChartInstance.destroy(); 
                        renderActivityChart();
                    }
                };
                
                const setTheme = (theme) => {
                    htmlEl.setAttribute('data-theme', theme);
                    htmlEl.classList.toggle('dark', theme !== 'light');
                    body.classList.toggle('light-mode', theme === 'light');
                    localStorage.setItem('theme', theme);
                    updateThemeUI(theme);
                };
                
                toggleThemeBtn.addEventListener('click', () => {
                    const newTheme = htmlEl.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    setTheme(newTheme);
                });

                if (toggleGlitchBtn) {
                    toggleGlitchBtn.addEventListener('click', () => { 
                        glitchSound.play(); 
                        setTimeout(() => { clapSound.play(); }, 300); 
                        setTheme('glitch'); 
                    });
                }
                
                updateThemeUI(htmlEl.getAttribute('data-theme'));
            }

            // Lógica del gráfico (solo si existe el canvas)
            const renderActivityChart = () => {
                const chartEl = document.getElementById('activityChart');
                if (!chartEl) return;
                const ctx = chartEl.getContext('2d');
                const theme = document.documentElement.getAttribute('data-theme');
                const colors = {
                    light: {grid:'rgba(226,232,240,1)',font:'#1e293b',accent:'rgba(245,158,11,1)',bg:'rgba(245,158,11,0.5)'},
                    dark: {grid:'rgba(71,85,105,0.5)',font:'#f1f5f9',accent:'rgba(245,158,11,1)',bg:'rgba(245,158,11,0.5)'},
                    glitch: {grid:'#ff00ff',font:'#f0f0f0',accent:'rgba(255,0,255,1)',bg:'rgba(255,0,255,0.5)'}
                };
                const c = colors[theme] || colors.dark;
                window.activityChartInstance = new Chart(ctx, { /* ... (código del gráfico sin cambios) ... */ type: 'bar', data: { labels:['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct'], datasets:[{ label: 'Ventas', data: [120, 190, 150, 250, 220, 300, 280, 260, 290, 340], backgroundColor: c.bg, borderColor: c.accent, borderWidth: 2, borderRadius: 5, hoverBackgroundColor: c.accent }] }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ grid:{ color:c.grid, drawBorder:false }, ticks:{ color:c.font, padding:10 } }, x:{ grid:{ display:false }, ticks:{ color:c.font, padding:10 } } }, plugins:{ legend:{ display:false } } } });
            }
            if (document.getElementById('activityChart')) {
                renderActivityChart();
            }

            // Título de la pestaña del navegador según el enlace activo (ya no hay header superior)
            const activeLink = document.querySelector('.sidebar-link.active .sidebar-text');
            if (activeLink) {
                document.title = activeLink.textContent.trim() + ' · Banano Panel';
            }
        });
    </script>
</body>
</html>