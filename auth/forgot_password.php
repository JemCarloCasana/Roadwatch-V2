<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = 'Session expired. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $stmt = mysqli_prepare($conn, 'SELECT id, email, security_question, is_active FROM users WHERE email = ?');
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$user) {
            unset($_SESSION['reset_verified'], $_SESSION['reset_verified_for']);
            $message = 'No active account found with that email address.';
        } elseif (!(int)$user['is_active']) {
            unset($_SESSION['reset_verified'], $_SESSION['reset_verified_for']);
            $message = 'This account is currently inactive. Please contact administration.';
        } elseif (empty($user['security_question'])) {
            unset($_SESSION['reset_verified'], $_SESSION['reset_verified_for']);
            $message = 'No recovery question is set for this account. Please contact an administrator.';
        } else {
            unset($_SESSION['reset_verified'], $_SESSION['reset_verified_for']);
            $_SESSION['reset_user_id'] = (int) $user['id'];
            $_SESSION['reset_question'] = $user['security_question'];
            header('Location: security_question.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - RoadWatch</title>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="icon" type="image/png" href="../includes/RoadWatch.png">
</head>
<body>
    <main class="auth-page">
        <section class="auth-card">
            <a href="login.php" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.88rem; margin-bottom: 24px; color: var(--muted);">
                &larr; Back to Sign In
            </a>

            <h1>Forgot Password</h1>
            <p class="muted">Enter your registered email address to verify your account recovery question.</p>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" data-loading="Verifying…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="email">Account Email Address</label>
                    <input id="email" type="email" name="email" required autocomplete="email" placeholder="name@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <button type="submit" class="btn-navy" style="width: 100%; margin-top: 8px;">
                    Continue to Security Check &rarr;
                </button>
            </form>
        </section>
    </main>
</body>
</html>