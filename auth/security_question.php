<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

if (!isset($_SESSION['reset_user_id'], $_SESSION['reset_question'])) {
    header('Location: forgot_password.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = 'Security session expired. Please try again.';
    } else {
        $answer = trim($_POST['security_answer'] ?? '');
        $user_id = (int) $_SESSION['reset_user_id'];
        
        $stmt = mysqli_prepare($conn, 'SELECT security_answer FROM users WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        $stored_answer = $user['security_answer'] ?? '';
        if ($user && $stored_answer !== '' && password_verify($answer, $stored_answer)) {
            $_SESSION['reset_verified'] = true;
            header('Location: reset_password.php');
            exit;
        }
        $message = 'Incorrect recovery answer. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Verification - RoadWatch</title>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="icon" type="image/png" href="../includes/roadwatch.png">
</head>
<body>
    <main class="auth-page">
        <section class="auth-card">
            <a href="forgot_password.php" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.88rem; margin-bottom: 24px; color: var(--muted);">
                &larr; Back
            </a>

            <h1>Security Question</h1>
            <p class="muted">Please answer your preset account recovery question:</p>

            <div style="padding: 14px 18px; background: var(--line-subtle); border-radius: var(--radius-md); border: 1px solid var(--line); margin-bottom: 18px; font-weight: 600; color: var(--ink);">
                "<?php echo htmlspecialchars($_SESSION['reset_question'], ENT_QUOTES, 'UTF-8'); ?>"
            </div>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" data-loading="Checking answer…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="security_answer">Your Answer</label>
                    <input id="security_answer" type="text" name="security_answer" required autocomplete="off" autofocus placeholder="Type your answer...">
                </div>

                <button type="submit" class="btn-navy" style="width: 100%; margin-top: 8px;">
                    Verify Answer &rarr;
                </button>
            </form>
        </section>
    </main>
</body>
</html>