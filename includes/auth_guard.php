<?php
if (session_status() === PHP_SESSION_NONE) {
    // Hardened session configuration
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

/**
 * Generate or retrieve CSRF token.
 */
function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF token input field.
 */
function csrf_input(): void {
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verify submitted CSRF token.
 */
function verify_csrf_token(?string $token = null): bool {
    $submitted = $token ?? ($_POST['csrf_token'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return !empty($sessionToken) && !empty($submitted) && hash_equals($sessionToken, $submitted);
}

/**
 * Set a one-time flash message.
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash_notification'] = [
        'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
        'message' => $message
    ];
}

/**
 * Retrieve and clear the flash message.
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash_notification'])) {
        $flash = $_SESSION['flash_notification'];
        unset($_SESSION['flash_notification']);
        return $flash;
    }
    return null;
}

/**
 * Render flash message as an accessible notice banner.
 */
function render_flash(): void {
    $flash = get_flash();
    if ($flash) {
        $class = 'notice';
        if ($flash['type'] === 'error') {
            $class .= ' error';
        } elseif ($flash['type'] === 'success') {
            $class .= ' success';
        } elseif ($flash['type'] === 'warning') {
            $class .= ' warning';
        }
        // Non-error notices auto-dismiss via shared app.js; errors persist until navigated away.
        $dismiss = $flash['type'] === 'error' ? '' : ' data-flash-dismiss';
        echo '<div class="' . $class . '" role="alert" aria-live="polite" data-flash' . $dismiss . '>' . htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

/**
 * Require active authentication.
 */
function require_login(string $redirectPath = '../auth/login.php'): array {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . $redirectPath);
        exit;
    }
    return [
        'id' => (int) $_SESSION['user_id'],
        'role' => $_SESSION['role'] ?? 'user',
        'full_name' => $_SESSION['full_name'] ?? 'User'
    ];
}

/**
 * Require specific user role(s).
 */function require_role(array $allowedRoles, string $redirectPath = '../auth/login.php'): array {
    $user = require_login($redirectPath);
    if (!in_array($user['role'], $allowedRoles, true)) {
        // Super admin can access admin routes
        if (in_array('admin', $allowedRoles, true) && $user['role'] === 'super_admin') {
            return $user;
        }
        // Redirect appropriately if unauthorized
        if ($user['role'] === 'user') {
            header('Location: ../user/my_reports.php');
        } elseif ($user['role'] === 'admin') {
            header('Location: ../admin/reports.php');
        } elseif ($user['role'] === 'super_admin') {
            header('Location: ../superadmin/accounts.php');
        } else {
            header('Location: ' . $redirectPath);
        }
        exit;
    }
    return $user;
}

/**
 * Require guest (unauthenticated) access.
 * Redirects already-logged-in users to their role home, mirroring the
 * role-home mapping used by auth/login.php:
 * super_admin -> superadmin console, admin -> admin console,
 * citizen (role user) -> report journal. Guests fall through.
 */
function require_guest(): void {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    $role = $_SESSION['role'] ?? 'user';
    // Callers live in auth/, so console paths are ../-prefixed; tolerate a
    // future root-level caller by dropping the prefix outside auth/.
    $in_auth = basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'auth';
    $prefix = $in_auth ? '../' : '';
    if ($role === 'super_admin') {
        header('Location: ' . $prefix . 'superadmin/accounts.php');
    } elseif ($role === 'admin') {
        header('Location: ' . $prefix . 'admin/reports.php');
    } else {
        header('Location: ' . $prefix . 'user/my_reports.php');
    }
    exit;
}

/* ==========================================================================
   Shared domain helpers (single source of truth; do not duplicate per-page)
   ========================================================================== */

// Dagupan CDRRMO emergency hotline — display text and tel: link target.
define('ROADWATCH_CDRRMO_DISPLAY', '(075) 515-2275');
define('ROADWATCH_CDRRMO_TEL', 'tel:0755152275');

/**
 * Expire stale pending reports (48-hour sunset, Grilled Decision 4).
 */
function apply_pending_report_sunset($conn): void {
    mysqli_query($conn, "UPDATE reports SET status = 'expired' WHERE status = 'pending' AND submitted_at < NOW() - INTERVAL 48 HOUR");
}

/**
 * Leaflet 1.9.4 CDN tags for map pages (public feed + report intake).
 */
function roadwatch_leaflet_head(): string {
    return '
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
';
}

/**
 * Normalize a passability value to [stripe class, pill modifier, label].
 * Accepts storage values (passable/partially_passable/not_passable) and the
 * legacy 'impassable' alias; unknown/NULL values fall back to passable.
 *
 * @return array{0:string, 1:string, 2:string}
 */
function passability_display($raw): array {
    switch ($raw) {
        case 'not_passable':
        case 'impassable':
            return ['impassable', 'pill-impassable', 'Impassable'];
        case 'partially_passable':
        case 'partial':
            return ['partially_passable', 'pill-partial', 'Partially passable'];
        default:
            return ['passable', 'pill-passable', 'Passable'];
    }
}

// Default list page size for paginated queues and feeds.
define('ROADWATCH_PER_PAGE', 50);

/**
 * Canonical account-recovery question list (single source of truth).
 * New citizen signups, superadmin-provisioned accounts, and the NULL-cohort
 * setup flow must all validate against this list with strict in_array().
 * Existing accounts holding a non-listed question are grandfathered:
 * do NOT rewrite their stored hashes.
 *
 * @return string[]
 */
function recovery_questions(): array {
    return [
        'What was the name of your first pet?',
        'What city were you born in?',
        'What was the name of your elementary school?',
        "What is your mother's maiden name?",
        'What street did you grow up on?',
        'What is your favorite food?',
    ];
}

/**
 * Render a windowed pager (prev + 1 … c-1 c c+1 … N + next) preserving $params.
 * Returns '' when everything fits on one page.
 */
function render_pager(int $total_items, int $current_page, array $params, int $per_page = ROADWATCH_PER_PAGE): string {
    $total_pages = max(1, (int) ceil($total_items / $per_page));
    if ($total_pages <= 1) {
        return '';
    }
    $current_page = min(max(1, $current_page), $total_pages);

    $link = function (int $p, string $label, string $rel = '', bool $is_current = false) use ($params): string {
        if ($is_current) {
            return '<span class="pager-current" aria-current="page">' . $label . '</span>';
        }
        $qs = htmlspecialchars(http_build_query(array_merge($params, ['page' => $p])), ENT_QUOTES, 'UTF-8');
        $rel_attr = $rel !== '' ? ' rel="' . $rel . '"' : '';
        return '<a class="pager-link" href="?' . $qs . '"' . $rel_attr . '>' . $label . '</a>';
    };

    $html = '<nav class="pager" aria-label="Pagination">';
    if ($current_page > 1) {
        $html .= $link($current_page - 1, '&larr; Prev', 'prev');
    }
    $pages = [1, $current_page - 1, $current_page, $current_page + 1, $total_pages];
    $pages = array_unique(array_filter($pages, function ($p) use ($total_pages): bool {
        return $p >= 1 && $p <= $total_pages;
    }));
    sort($pages);
    $prev = 0;
    foreach ($pages as $p) {
        if ($p - $prev > 1) {
            $html .= '<span class="pager-ellipsis" aria-hidden="true">…</span>';
        }
        $html .= $link($p, (string) $p, '', $p === $current_page);
        $prev = $p;
    }
    if ($current_page < $total_pages) {
        $html .= $link($current_page + 1, 'Next &rarr;', 'next');
    }
    return $html . '</nav>';
}
