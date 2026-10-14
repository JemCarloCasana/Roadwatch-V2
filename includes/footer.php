</main>
<footer style="margin-top: 60px; padding: 24px 0; border-top: 1px solid var(--line); text-align: center; color: var(--muted); font-size: 0.85rem;">
    <div style="width: min(1200px, calc(100% - 32px)); margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>&copy; <?php echo date('Y'); ?> RoadWatch Dagupan. Community-powered civic road safety.</div>
        <div>City Engineering &amp; Disaster Risk Reduction Operations</div>
    </div>
</footer>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
<script src="<?php echo $basePath; ?>includes/app.js"></script>
<script>
(function() {
    var header = document.querySelector('.site-header');
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.getElementById('site-nav');
    if (!header || !toggle || !nav) return;
    // Enable collapsed-nav behavior only when JS runs; no-JS keeps the full nav visible.
    header.classList.add('nav-collapsible');
    function setOpen(open) {
        header.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
    toggle.addEventListener('click', function() {
        setOpen(!header.classList.contains('nav-open'));
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && header.classList.contains('nav-open')) {
            setOpen(false);
            toggle.focus();
        }
    });
})();
</script>
</body>
</html>
