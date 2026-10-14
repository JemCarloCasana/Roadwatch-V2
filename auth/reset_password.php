<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

if (!isset($_SESSION['reset_user_id'], $_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true) {
    header('Location: forgot_password.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = 'Session expired. Please try again.';
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (strlen($new_password) < 8) {
            $message = 'Password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $message = 'Passwords do not match.';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = (int) $_SESSION['reset_user_id'];
            
            $stmt = mysqli_prepare($conn, 'UPDATE users SET password_hash = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'si', $password_hash, $user_id);

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                unset($_SESSION['reset_user_id'], $_SESSION['reset_question'], $_SESSION['reset_verified']);
                set_flash('success', 'Your password has been successfully reset! You may now sign in.');
                header('Location: login.php');
                exit;
            } else {
                $message = 'Failed to update password. Please try again.';
                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - RoadWatch</title>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="icon" type="image/png" href="../includes/roadwatch.png">
</head>
<body>
    <main class="auth-page">
        <section class="auth-card">
            <h1>Set New Password</h1>
            <p class="muted">Your identity has been verified. Enter your new password below.</p>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" data-loading="Updating password…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="new_password">New Password (Min 8 chars)</label>
                    <input id="new_password" type="password" name="new_password" minlength="8" required autocomplete="new-password" autofocus placeholder="••••••••">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="••••••••">
                </div>

                <button type="submit" class="btn-navy" style="width: 100%; margin-top: 8px;">
                    Update Password &amp; Sign In
                </button>
            </form>
        </section>
    </main>
</body>
</html>