<?php
/**
 * LAYOUT — PIE DE PÁGINA + MODAL DE ACCESO
 */
$usuarioPie = usuario_actual();
?>
</main>

<!-- ===================== FOOTER ===================== -->
<footer class="relative z-10 border-t border-brand-gold/15 bg-[#060C17]/80 no-print">
    <div class="max-w-[1600px] mx-auto px-4 lg:px-6 py-12">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 pb-10 border-b border-brand-gold/10">

            <div class="space-y-3">
                <div class="flex items-center gap-2.5">
                    <img src="assets/img/logo-willaq.svg" alt="" width="30" height="30" class="rounded-lg">
                    <span class="text-base tracking-[0.28em] font-light">WILLAQ</span>
                </div>
                <p class="text-[0.72rem] text-slate-400 leading-relaxed">
                    Quechua: <em>el que avisa</em>. Inteligencia territorial y alerta temprana
                    del Fenómeno El Niño para América Latina.
                </p>
                <div class="flex flex-wrap gap-1.5 pt-1">
                    <span class="wq-chip">PHP 8</span>
                    <span class="wq-chip">Open-Meteo</span>
                    <span class="wq-chip">GloFAS</span>
                    <span class="wq-chip">NASA GIBS</span>
                </div>
            </div>

            <div>
                <h4 class="wq-eyebrow mb-3">Plataforma</h4>
                <ul class="space-y-2 text-[0.78rem] text-slate-400">
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('home')) ?>">Sala de situación</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('mapa')) ?>">Mapa de riesgo</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('alertas')) ?>">Alertas y protocolos</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('planificacion')) ?>">Zonificación territorial</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('simulador')) ?>">Simulador de escenarios</a></li>
                </ul>
            </div>

            <div>
                <h4 class="wq-eyebrow mb-3">Transparencia</h4>
                <ul class="space-y-2 text-[0.78rem] text-slate-400">
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('ayuda')) ?>#metodologia">Metodología del índice</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('ayuda')) ?>#fuentes">Fuentes de datos</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('ayuda')) ?>#limites">Límites y alcance</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('api_riesgo')) ?>">API pública (JSON)</a></li>
                    <li><a class="hover:text-brand-gold transition-colors" href="<?= e(url('ayuda')) ?>#reutilizacion">Qué es nuevo</a></li>
                </ul>
            </div>

            <div>
                <h4 class="wq-eyebrow mb-3">Aviso importante</h4>
                <p class="text-[0.7rem] text-slate-500 leading-relaxed">
                    WILLAQ es una <strong class="text-slate-300">herramienta de apoyo a la decisión</strong>.
                    No emite alertas oficiales ni sustituye a SENAMHI, INDECI, ENFEN ni a las
                    autoridades competentes. Ante una emergencia, siga los canales oficiales.
                </p>
            </div>
        </div>

        <div class="pt-6 flex flex-col md:flex-row items-center justify-between gap-3 text-[0.66rem] text-slate-500">
            <span>© <?= date('Y') ?> WILLAQ · Proyecto académico · IESTP "Argentina" — DSI</span>
            <span class="flex items-center gap-2">
                <span class="wq-dot wq-dot--verde"></span>
                Datos: <?= e(date('d/m/Y H:i')) ?> (America/Lima)
            </span>
        </div>
    </div>
</footer>

<!-- ===================== MODAL DE ACCESO ===================== -->
<div id="wq-login"
     class="hidden fixed inset-0 z-[100] no-print"
     role="dialog" aria-modal="true" aria-labelledby="wq-login-title">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"
         onclick="document.getElementById('wq-login').classList.add('hidden')"></div>

    <div class="relative mx-auto mt-[8vh] w-[92%] max-w-md wq-panel-dark p-7 shadow-2xl">
        <button type="button" aria-label="Cerrar"
                onclick="document.getElementById('wq-login').classList.add('hidden')"
                class="absolute top-3 right-4 text-white/40 hover:text-white text-xl">&times;</button>

        <div class="flex items-center gap-3 mb-1">
            <img src="assets/img/logo-willaq.svg" alt="" width="34" height="34" class="rounded-lg">
            <h2 id="wq-login-title" class="wq-title text-2xl">Acceso a WILLAQ</h2>
        </div>
        <p class="text-[0.75rem] text-slate-400 mb-6">
            Roles diferenciados: administrador, autoridad y ciudadano.
        </p>

        <?php if ($usuarioPie): ?>
            <div class="text-sm text-slate-300 mb-4">
                Sesión activa como <strong class="text-brand-gold"><?= e($usuarioPie['nombre']) ?></strong>
                (<?= e($usuarioPie['rol']) ?>).
            </div>
            <a href="<?= e(url('logout')) ?>" class="btn btn--primary w-full">Cerrar sesión</a>
        <?php else: ?>
            <form method="post" action="index.php" class="space-y-3">
                <input type="hidden" name="action" value="login">
                <div>
                    <label class="wq-label" for="wq-email">Correo</label>
                    <input class="wq-input" id="wq-email" type="email" name="email"
                           required autocomplete="username" placeholder="usuario@willaq.pe">
                </div>
                <div>
                    <label class="wq-label" for="wq-pass">Contraseña</label>
                    <input class="wq-input" id="wq-pass" type="password" name="password"
                           required autocomplete="current-password" placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn--primary w-full mt-2">Ingresar</button>
            </form>

            <div class="mt-5 pt-4 border-t border-white/10">
                <p class="text-[0.64rem] uppercase tracking-[0.16em] text-brand-gold/80 mb-2">
                    Cuentas de demostración
                </p>
                <div class="space-y-1 text-[0.7rem] text-slate-400 font-mono">
                    <div>admin@willaq.pe</div>
                    <div>defensa@willaq.pe</div>
                    <div>ciudadano@willaq.pe</div>
                    <div class="text-slate-500 pt-1">contraseña: willaq2026</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===================== LIBRERÍAS LOCALES ===================== -->
<script src="assets/vendor/chart.umd.min.js"></script>
<?php /* MapLibre se carga en el <head> cuando la vista lo necesita. */ ?>

<script>
/* Utilidades compartidas por todas las vistas */
window.WQ = {
    fmt(n, dec = 0) {
        if (n === null || n === undefined || isNaN(n)) return '—';
        return Number(n).toLocaleString('es-PE', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    },
    nivel(id) {
        return ({ verde: 'Bajo', amarillo: 'Medio', naranja: 'Alto', rojo: 'Muy alto' })[id] || id;
    },
    color(id) {
        return ({ verde: '#22c55e', amarillo: '#eab308', naranja: '#f97316', rojo: '#ef4444' })[id] || '#94a3b8';
    },
    async json(url) {
        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    }
};

/* Cerrar modal con Escape */
document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') {
        document.getElementById('wq-login')?.classList.add('hidden');
    }
});
</script>
</body>
</html>
