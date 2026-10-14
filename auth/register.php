<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$message = '';
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$security_question = trim($_POST['security_question'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = 'Security session expired. Please submit the form again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $security_answer = trim($_POST['security_answer'] ?? '');

        if ($full_name === '' || $email === '') {
            $message = 'Please complete all required fields.';
        } elseif ($security_question === '' || $security_answer === '') {
            $message = 'Please set a recovery question and answer so you can reset your password later.';
        } elseif (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm_password) {
            $message = 'Passwords do not match.';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $security_answer_hash = password_hash($security_answer, PASSWORD_DEFAULT);
            $role = 'user';

            $sql = "INSERT INTO users (full_name, email, password_hash, security_question, security_answer, role) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'ssssss', $full_name, $email, $password_hash, $security_question, $security_answer_hash, $role);

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                set_flash('success', 'Account created successfully! You can now log in.');
                header('Location: login.php');
                exit;
            } elseif (mysqli_errno($conn) === 1062) {
                $message = 'This email address is already registered. Please log in or use another email.';
            } else {
                $message = 'Unable to create your account. Please try again.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create an Account - RoadWatch Dagupan</title>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="icon" type="image/png" href="../includes/roadwatch.png">
</head>
<body>
    <main class="auth-page">
        <section class="auth-card-mockup">
            <a href="../index.php" class="back-link-mockup">
                &larr; Back to Road Conditions
            </a>

            <div class="brand-lockup" style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--line);">
                <span class="brand-mark">RW</span>
                <div>
                    <div class="brand-title">ROADWATCH DAGUPAN</div>
                    <div class="brand-subtitle">Community road hazard reporting for Dagupan City</div>
                </div>
            </div>

            <h1 style="font-size: 1.65rem; margin-bottom: 6px;">Create an Account</h1>
            <p class="muted" style="font-size: 0.88rem; margin-bottom: 24px;">
                Create an account to report road hazards and track the reports you submit.
            </p>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" data-loading="Creating account…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="full_name">Full Name <span style="color: var(--impassable-red);">*</span></label>
                    <input id="full_name" type="text" name="full_name" required autocomplete="name" placeholder="e.g. Maria Santos" value="<?php echo htmlspecialchars($full_name); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email Address <span style="color: var(--impassable-red);">*</span></label>
                    <input id="email" type="email" name="email" required autocomplete="email" placeholder="maria@example.com" value="<?php echo htmlspecialchars($email); ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password <span style="color: var(--impassable-red);">*</span></label>
                    <div class="input-with-eye">
                        <input id="password" type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
                        <button type="button" class="eye-toggle-btn" onclick="togglePassword('password', this)" title="Show / hide password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                    <span style="font-size: 0.78rem; color: var(--muted); margin-top: 2px;">At least 8 characters.</span>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span style="color: var(--impassable-red);">*</span></label>
                    <div class="input-with-eye">
                        <input id="confirm_password" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="Re-enter your password">
                        <button type="button" class="eye-toggle-btn" onclick="togglePassword('confirm_password', this)" title="Show / hide password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="security_question">Recovery Question <span style="color: var(--impassable-red);">*</span></label>
                    <input id="security_question" type="text" name="security_question" required autocomplete="off" placeholder="e.g. Name of the street you grew up on?" value="<?php echo htmlspecialchars($security_question); ?>">
                </div>

                <div class="form-group">
                    <label for="security_answer">Recovery Answer <span style="color: var(--impassable-red);">*</span></label>
                    <div class="input-with-eye">
                        <input id="security_answer" type="password" name="security_answer" required autocomplete="off" placeholder="Your private answer">
                        <button type="button" class="eye-toggle-btn" onclick="togglePassword('security_answer', this)" title="Show / hide answer" aria-label="Toggle answer visibility">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                    <span style="font-size: 0.78rem; color: var(--muted); margin-top: 2px;">Used only if you forget your password.</span>
                </div>

                <button type="submit" class="btn-navy" style="width: 100%; margin-top: 8px; padding: 11px;">
                    Create Account
                </button>
            </form>

            <div style="margin-top: 26px; padding-top: 20px; border-top: 1px solid var(--line); text-align: center;">
                <div style="font-size: 0.88rem; color: var(--ink-secondary);">
                    Already have an account? <a href="login.php" style="color: var(--brand-navy); font-weight: 700;">Log in</a>
                </div>
                <div style="font-size: 0.8rem; color: var(--muted); margin-top: 6px;">
                    An account is only required when submitting or tracking reports.
                </div>
            </div>
        </section>
    </main>

    <script src="../includes/app.js"></script>
</body>
</html>