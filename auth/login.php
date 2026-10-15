<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

// If already logged in, redirect based on role
// Citizens smart-route: no reports yet -> Road Conditions home,
// otherwise -> their report journal.
function citizen_home($conn, int $user_id): string {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM reports WHERE user_id = ?');
    if (!$stmt) {
        return '../user/my_reports.php';
    }
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return ((int) ($total ?? 0) === 0) ? '../index.php' : '../user/my_reports.php';
}

if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'user';
    if ($role === 'super_admin') {
        header('Location: ../superadmin/accounts.php');
    } elseif ($role === 'admin') {
        header('Location: ../admin/reports.php');
    } else {
        header('Location: ' . citizen_home($conn, (int) $_SESSION['user_id']));
    }
    exit;
}

$message = "";
$show_admin_hint = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        $message = "Session expired. Please try logging in again.";
    } else {
        $email = trim($_POST["email"] ?? '');
        $password = $_POST["password"] ?? '';

        $sql = "SELECT id, full_name, email, password_hash, role, is_active, security_question, must_change_password
                FROM users
                WHERE email = ? AND role = 'user'
                LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($user && password_verify($password, $user["password_hash"])) {
            if ((int) $user["is_active"] === 1) {
                if ((int) ($user["must_change_password"] ?? 0) === 1) {
                    // Provisioned/seeded password still in use: hold a pending
                    // id only — grant NO session — until rotation completes.
                    session_regenerate_id(true);
                    unset($_SESSION["user_id"], $_SESSION["role"], $_SESSION["full_name"]);
                    $_SESSION["pending_password_user_id"] = (int) $user["id"];
                    unset($_SESSION["pending_recovery_user_id"]);
                    set_flash('warning', 'For your security, please set a new password before continuing.');
                    header("Location: change_password.php");
                    exit;
                }
                if (empty($user["security_question"])) {
                    // NULL cohort (created while Q&A fields were removed):
                    // hold a pending id only — grant NO session — until Q&A setup completes.
                    session_regenerate_id(true);
                    unset($_SESSION["user_id"], $_SESSION["role"], $_SESSION["full_name"]);
                    $_SESSION["pending_recovery_user_id"] = (int) $user["id"];
                    unset($_SESSION["pending_password_user_id"]);
                    set_flash('info', 'Please set a recovery question and answer to finish signing in.');
                    header("Location: setup_recovery.php");
                    exit;
                }
                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"] = $user["role"];
                unset($_SESSION["pending_password_user_id"], $_SESSION["pending_recovery_user_id"]);

                if ($user["role"] === "user") {
                    header("Location: " . citizen_home($conn, (int) $user["id"]));
                    exit;
                }
                // NOTE: the citizen query above constrains role = 'user', so
                // admin/super_admin branches here would be dead code by design.
            } else {
                $message = "Your account is currently inactive. Please contact the administrator.";
            }
        } else {
            $message = "Invalid email address or password.";
            // Citizen sign-in only accepts role='user' accounts; admins landing
            // here (e.g. via bookmark) get a pointer to their own portal.
            $show_admin_hint = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Sign In - RoadWatch Dagupan</title>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="icon" type="image/png" href="../includes/RoadWatch.png">
</head>
<body>
    <main class="auth-page">
        <section class="auth-card-mockup">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <a href="../index.php" class="back-link-mockup" style="margin-bottom: 0;">
                    &larr; Back to Road Conditions
                </a>
            </div>

            <!-- Brand Lockup Box -->
            <div class="auth-banner-box">
                <div class="brand-lockup">
                    <img src="../includes/RoadWatch.png" alt="RoadWatch logo" class="brand-mark-img" width="36" height="36">
                    <div>
                        <div class="brand-title">ROADWATCH DAGUPAN</div>
                        <div class="brand-subtitle">Community road hazard reporting for Dagupan City</div>
                    </div>
                </div>
            </div>

            <h1 style="font-size: 1.65rem; margin-bottom: 6px;">Welcome Back</h1>
            <p class="muted" style="font-size: 0.88rem; margin-bottom: 22px;">
                Log in to track your reports and submit new road hazard information.
            </p>

            <?php render_flash(); ?>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
                <?php if ($show_admin_hint): ?>
                    <div class="muted" style="font-size: 0.82rem; margin-top: 8px;">
                        Are you an administrator? <a href="admin_login.php" style="font-weight: 700;">Sign in via the Admin Portal</a>.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" data-loading="Signing in…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="email">Email Address <span style="color: var(--impassable-red);">*</span></label>
                    <input id="email" type="email" name="email" required autocomplete="email" placeholder="name@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password <span style="color: var(--impassable-red);">*</span></label>
                    <div class="input-with-eye">
                        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="eye-toggle-btn" onclick="togglePassword('password', this)" title="Show / hide password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Remember-me is deliberately NOT offered: there is no
                     persistent-login token store, so a checkbox would imply a
                     "keep me signed in" promise the server never honors.
                     Sessions end with the browser session by design. -->
                <div style="display: flex; justify-content: flex-end; align-items: center; margin: 2px 0 6px;">
                    <a href="forgot_password.php" style="font-size: 0.82rem; font-weight: 600;">Forgot password?</a>
                </div>

                <button type="submit" class="btn-navy" style="width: 100%; padding: 11px;">
                    Log In &rarr;
                </button>
            </form>

            <div style="display: flex; align-items: center; gap: 12px; margin: 24px 0 16px; color: var(--muted); font-size: 0.82rem; font-weight: 600;">
                <span style="flex: 1; height: 1px; background: var(--line);"></span>
                <span>New to RoadWatch?</span>
                <span style="flex: 1; height: 1px; background: var(--line);"></span>
            </div>

            <a href="register.php" class="btn-secondary" style="width: 100%; text-align: center; display: flex; justify-content: center; gap: 6px;">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>
                Create an Account
            </a>

            <!-- Info Callout -->
            <div class="auth-info-callout">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="flex-shrink: 0; color: var(--brand-navy); margin-top: 1px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <strong style="color: var(--brand-navy); display: block; margin-bottom: 2px;">Just checking road conditions?</strong>
                    <span>You don't need an account to view verified public alerts and street status.</span>
                    <div style="margin-top: 6px;">
                        <a href="../index.php" style="font-weight: 700; font-size: 0.8rem;">Browse Road Conditions &rarr;</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script src="../includes/app.js"></script>
</body>
</html>