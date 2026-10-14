// RoadWatch shared UI helpers — single source of truth.
// Do not duplicate these per-page; load this file instead.

/**
 * Toggle password field visibility (show/hide eye button).
 * @param {string} inputId - ID of the password input.
 * @param {HTMLElement} btn - The toggle button (for pressed state + color).
 */
function togglePassword(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    if (btn) {
        btn.style.color = show ? 'var(--brand-navy)' : 'var(--muted)';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    }
}

// Navigating indicator: filter chips, tabs, pager links and auto-submit
// selects trigger full reloads — show a thin progress bar immediately.
function showNavProgress() {
    var bar = document.querySelector('.nav-progress');
    if (!bar) {
        bar = document.createElement('div');
        bar.className = 'nav-progress';
        bar.setAttribute('aria-hidden', 'true');
        document.body.prepend(bar);
    }
    window.requestAnimationFrame(function () {
        document.body.setAttribute('data-navigating', '');
    });
}
document.querySelectorAll('.filter-chip, .status-tab-item, .pager-link').forEach(function (el) {
    el.addEventListener('click', showNavProgress);
});
document.querySelectorAll('select[onchange]').forEach(function (el) {
    el.addEventListener('change', showNavProgress);
});

// Clickable card/row navigation (progressive enhancement for data-href containers).
document.querySelectorAll('.clickable-card, tr.clickable-row').forEach(function (el) {
    el.addEventListener('click', function (e) {
        if (e.target.closest('a, button')) return;
        var href = el.getAttribute('data-href');
        if (href) window.location = href;
    });
    el.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('a, button')) {
            e.preventDefault();
            var href = el.getAttribute('data-href');
            if (href) window.location = href;
        }
    });
});

// Auto-dismiss non-error flash notices after 5s (toasts must not steal focus).
window.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-flash-dismiss]').forEach(function (notice) {
        window.setTimeout(function () {
            notice.style.transition = 'opacity 0.3s ease';
            notice.style.opacity = '0';
            window.setTimeout(function () { notice.remove(); }, 350);
        }, 5000);
    });
});

// Submit loading: forms with data-loading disable their submit button and
// show a spinner. Native validation runs first, so this only fires on real submits.
document.querySelectorAll('form[data-loading]').forEach(function (form) {
    form.addEventListener('submit', function () {
        var btn = form.querySelector('[type="submit"]');
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        if (!btn.dataset.origLabel) btn.dataset.origLabel = btn.innerHTML;
        var label = form.getAttribute('data-loading') || 'Submitting…';
        btn.innerHTML = '<span class="spinner" aria-hidden="true"></span> ' + label;
    });
});
