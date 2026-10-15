</main>
<footer class="mt-[60px] border-t border-line py-6 text-center text-[0.85rem] text-mist">
    <div class="mx-auto flex w-[calc(100%-32px)] max-w-[1200px] flex-wrap items-center justify-between gap-3">
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
