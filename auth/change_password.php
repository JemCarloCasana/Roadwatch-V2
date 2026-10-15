<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

// Forced-rotation step for accounts whose must_change_password flag is set
// (provisioned/seeded passwords). Reached only via the pending id that
// login.php / admin_login.php hold — a fully authenticated user has no
// rotation due and is bounced back through the sign-in routing.
if (isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['pending_password_user_id'])) {
    header('Location: login.php');
    exit;
}

$pending_id = (int) $_SESSION['pending_password_user_id'];

/**
 * Fetch the pending rotation account. Only active accounts qualify;
 * anything else abandons the pending state back to the sign-in page.
 */
function pending_password_account($conn, int $pending_id): ?array {
    $stmt = mysqli_prepare($conn, 'SELECT id, full_name, role, is_active, must_change_password FROM users WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $pending_id);
    mysqli_stmt_execute($stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$account || (int) $account['is_active'] !== 1) {
        return null;
    }
    return $account;
}

/**
 * Complete sign-in for a rotated account. Citizens bounce through login.php
 * so its citizen_home() smart-routing stays the single routing decision;
 * staff roles go straight to their consoles.
 */
function complete_rotated_login(array $account): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $account['id'];
    $_SESSION['full_name'] = $account['full_name'];
    $_SESSION['role'] = $account['role'];
    unset($_SESSION['pending_password_user_id']);
    if (($account['role'] ?? '') === 'super_admin') {
        header('Location: ../superadmin/accounts.php');
    } elseif (($account['role'] ?? '') === 'admin') {
        header('Location: ../admin/reports.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

function abandon_rotated_login(string $message): void {
    unset($_SESSION['pending_password_user_id']);
    set_flash('error', $message);
    header('Location: login.php');
    exit;
}

$account = pending_password_account($conn, $pending_id);
if ($account === null) {
    abandon_rotated_login('This password-change session is no longer valid. Please log in again.');
}

if ((int) ($account['must_change_password'] ?? 0) !== 1) {
    // Flag already cleared (e.g. a second tab finished rotation) — sign in.
    set_flash('success', 'Password already updated. You are now signed in.');
    complete_rotated_login($account);
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = 'Security session expired. Please submit the form again.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $stmt = mysqli_prepare($conn, 'SELECT password_hash, is_active FROM users WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $pending_id);
        mysqli_stmt_execute($stmt);
        $fresh = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$fresh || (int) $fresh['is_active'] !== 1) {
            abandon_rotated_login('This account is currently inactive. Please contact administration.');
        } elseif (!password_verify($current_password, $fresh['password_hash'])) {
            $message = 'Your current password is incorrect.';
        } elseif (strlen($new_password) < 8) {
            $message = 'New password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $message = 'New passwords do not match.';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = mysqli_prepare($conn, 'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
            mysqli_stmt_bind_param($upd, 'si', $password_hash, $pending_id);
            $saved = mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            if ($saved) {
                $account['must_change_password'] = 0;
                set_flash('success', 'Password updated. You are now signed in.');
                complete_rotated_login($account);
            }
            $message = 'Failed to update your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - RoadWatch Dagupan</title>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="icon" type="image/png" href="../includes/RoadWatch.png">
</head>
<body>
    <main class="auth-page">
        <section class="auth-card-mockup">
            <a href="logout.php" class="back-link-mockup">
                &larr; Use a different account
            </a>

            <div class="brand-lockup" style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--line);">
                <img src="../includes/RoadWatch.png" alt="RoadWatch logo" class="brand-mark-img" width="36" height="36">
                <div>
                    <div class="brand-title">ROADWATCH DAGUPAN</div>
                    <div class="brand-subtitle">Community road hazard reporting for Dagupan City</div>
                </div>
            </div>

            <h1 style="font-size: 1.65rem; margin-bottom: 6px;">Set a New Password</h1>
            <p class="muted" style="font-size: 0.88rem; margin-bottom: 24px;">
                Hi, <?php echo htmlspecialchars($account['full_name']); ?> — your account is still using
                its provisioned password. Choose a new one to finish signing in.
            </p>

            <?php render_flash(); ?>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" data-loading="Updating password…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="current_password">Current Password <span style="color: var(--impassable-red);">*</span></label>
                    <input id="current_password" type="password" name="current_password" required autocomplete="current-password" placeholder="••••••••">
                </div>

                <div class="form-group">
                    <label for="new_password">New Password <span style="color: var(--impassable-red);">*</span></label>
                    <input id="new_password" type="password" name="new_password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
                    <span style="font-size: 0.78rem; color: var(--muted); margin-top: 2px;">At least 8 characters.</span>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password <span style="color: var(--impassable-red);">*</span></label>
                    <input id="confirm_password" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="Re-enter your new password">
                </div>

                <button type="submit" class="btn-navy" style="width: 100%; margin-top: 8px; padding: 11px;">
                    Update Password &amp; Continue &rarr;
                </button>
            </form>
        </section>
    </main>

    <script src="../includes/app.js"></script>
</body>
</html>
