<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

// Fully authenticated users have nothing to set up; login.php smart-routes them.
if (isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['pending_recovery_user_id'])) {
    header('Location: login.php');
    exit;
}

$pending_id = (int) $_SESSION['pending_recovery_user_id'];

/**
 * Fetch the pending NULL-cohort account. Only active citizen accounts qualify;
 * anything else abandons the pending state back to the sign-in page.
 */
function pending_recovery_account($conn, int $pending_id): ?array {
    $stmt = mysqli_prepare($conn, 'SELECT id, full_name, role, is_active, security_question FROM users WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $pending_id);
    mysqli_stmt_execute($stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$account || (int) $account['is_active'] !== 1 || ($account['role'] ?? '') !== 'user') {
        return null;
    }
    return $account;
}

/**
 * Complete sign-in for a repaired account, then bounce through login.php so
 * its citizen_home() smart-routing stays the single routing decision.
 */
function complete_pending_login(array $account): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $account['id'];
    $_SESSION['full_name'] = $account['full_name'];
    $_SESSION['role'] = $account['role'];
    unset($_SESSION['pending_recovery_user_id']);
    header('Location: login.php');
    exit;
}

function abandon_pending_login(string $message): void {
    unset($_SESSION['pending_recovery_user_id']);
    set_flash('error', $message);
    header('Location: login.php');
    exit;
}

$account = pending_recovery_account($conn, $pending_id);
if ($account === null) {
    abandon_pending_login('This sign-in session is no longer valid. Please log in again.');
}

if (!empty($account['security_question'])) {
    // Already repaired (e.g. a second tab finished setup) — just sign in.
    set_flash('success', 'Recovery details already on file. You are now signed in.');
    complete_pending_login($account);
}

$message = '';
$security_question = trim($_POST['security_question'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = 'Security session expired. Please submit the form again.';
    } else {
        $security_answer = trim($_POST['security_answer'] ?? '');

        if (!in_array($security_question, recovery_questions(), true)) {
            $message = 'Please select a valid recovery question.';
        } elseif ($security_answer === '') {
            $message = 'Please provide an answer to your recovery question.';
        } else {
            $security_answer_hash = password_hash($security_answer, PASSWORD_DEFAULT);
            // Guarded write: only fills a still-empty slot, never overwrites.
            $upd = mysqli_prepare(
                $conn,
                "UPDATE users SET security_question = ?, security_answer = ? WHERE id = ? AND (security_question IS NULL OR security_question = '')"
            );
            mysqli_stmt_bind_param($upd, 'ssi', $security_question, $security_answer_hash, $pending_id);
            $saved = mysqli_stmt_execute($upd) && mysqli_stmt_affected_rows($upd) === 1;
            mysqli_stmt_close($upd);

            if ($saved) {
                $account['security_question'] = $security_question;
                set_flash('success', 'Recovery question saved. You are now signed in.');
                complete_pending_login($account);
            }

            $fresh = pending_recovery_account($conn, $pending_id);
            if ($fresh !== null && !empty($fresh['security_question'])) {
                set_flash('success', 'Recovery details already on file. You are now signed in.');
                complete_pending_login($fresh);
            }
            $message = 'Unable to save your recovery details. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Recovery Question - RoadWatch Dagupan</title>
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

            <h1 style="font-size: 1.65rem; margin-bottom: 6px;">Set Your Recovery Question</h1>
            <p class="muted" style="font-size: 0.88rem; margin-bottom: 24px;">
                Hi, <?php echo htmlspecialchars($account['full_name']); ?> — your account is missing recovery
                details. Choose a question and answer once to finish signing in.
            </p>

            <?php render_flash(); ?>

            <?php if ($message): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" data-loading="Saving…">
                <?php csrf_input(); ?>

                <div class="form-group">
                    <label for="security_question">Recovery Question <span style="color: var(--impassable-red);">*</span></label>
                    <select id="security_question" name="security_question" required>
                        <option value="" disabled <?php echo $security_question === '' ? 'selected' : ''; ?>>Select a recovery question</option>
                        <?php foreach (recovery_questions() as $q): ?>
                            <option value="<?php echo htmlspecialchars($q); ?>" <?php echo $security_question === $q ? 'selected' : ''; ?>><?php echo htmlspecialchars($q); ?></option>
                        <?php endforeach; ?>
                    </select>
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
                    Save and Continue &rarr;
                </button>
            </form>
        </section>
    </main>

    <script src="../includes/app.js"></script>
</body>
</html>
