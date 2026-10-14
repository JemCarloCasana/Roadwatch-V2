<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

// If already logged in as admin/super_admin, redirect
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'user';
    if ($role === 'super_admin') {
        header('Location: ../superadmin/accounts.php');
        exit;
    } elseif ($role === 'admin') {
        header('Location: ../admin/reports.php');
        exit;
    }
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = 'Security session expired. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $sql = "SELECT id, full_name, password_hash, role, is_active
                FROM users 
                WHERE email = ? AND role IN ('admin', 'super_admin') 
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($user && password_verify($password, $user['password_hash'])) {
            if ((int) $user['is_active'] === 1) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                
                header('Location: ' . ($user['role'] === 'super_admin' ? '../superadmin/accounts.php' : '../admin/reports.php'));
                exit;
            }
            $message = 'This administrative account is inactive. Contact the Super Administrator.';
        } else {
            $message = 'Invalid administrator credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - RoadWatch Dagupan</title>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="icon" type="image/png" href="../includes/roadwatch.png">
</head>
<body>
    <main class="auth-page">
        <section class="auth-card-mockup">
            <a href="../index.php" class="back-link-mockup">
                &larr; Back to Road Conditions
            </a>

            <div class="auth-banner-box">
                <div class="brand-lockup">
                    <span class="brand-mark">RW</span>
                    <div>
                        <div class="brand-title">ROADWATCH DAGUPAN</div>
                        <div class="brand-subtitle">Community road hazard reporting for Dagupan City</div>
                    </div>
                </div>
            </div>

            <h1 style="font-size: 1.7rem; margin-bottom: 6px;">Admin Login</h1>
            <p class="muted" style="font-size: 0.88rem; margin-bottom: 24px; line-height: 1.5;">
                Sign in to review road hazard reports, assign passability, and manage report decisions.
            </p>

            <?php render_flash(); ?>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" data-loading="Signing in…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="email">Email Address <span style="color: var(--impassable-red);">*</span></label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="position: absolute; left: 12px; color: var(--muted);">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                        <input id="email" type="email" name="email" required autocomplete="email" placeholder="admin@example.com" style="padding-left: 38px !important;" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password <span style="color: var(--impassable-red);">*</span></label>
                    <div class="input-with-eye">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="position: absolute; left: 12px; color: var(--muted); z-index: 2;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••••••" style="padding-left: 38px !important;">
                        <button type="button" class="eye-toggle-btn" onclick="togglePassword('password', this)" title="Show / hide password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-navy" style="width: 100%; margin-top: 8px; padding: 11px;">
                    Log In &rarr;
                </button>
            </form>

            <div style="margin-top: 20px; font-size: 0.82rem; text-align: center; color: var(--muted);">
                Need help signing in? <a href="../index.php" style="color: var(--brand-navy); font-weight: 600;">Contact the Super Admin.</a>
            </div>

            <!-- Road Conditions Callout Box -->
            <div class="auth-info-callout" style="margin-top: 24px;">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="flex-shrink: 0; color: var(--brand-navy); margin-top: 1px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
                <div>
                    <strong style="color: var(--brand-navy); display: block; margin-bottom: 2px;">Looking for road conditions?</strong>
                    <a href="../index.php" style="font-weight: 700; font-size: 0.82rem; color: var(--brand-navy);">View Road Conditions &rarr;</a>
                </div>
            </div>
        </section>
    </main>

    <script src="../includes/app.js"></script>
</body>
</html>