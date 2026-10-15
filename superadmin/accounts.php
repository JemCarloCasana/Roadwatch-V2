<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['super_admin'], '../auth/admin_login.php');

$error = '';
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role = $_POST['role'] ?? '';
$security_question = trim($_POST['security_question'] ?? '');
$security_answer = trim($_POST['security_answer'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Security session expired. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        if (empty($full_name) || empty($email) || empty($security_question) || empty($security_answer)) {
            $error = 'Please fill out all fields.';
        } elseif (!in_array($security_question, recovery_questions(), true)) {
            $error = 'Please select a valid recovery question.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!in_array($role, ['user', 'admin'], true)) {
            $error = 'Please select a valid account role.';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $security_answer_hash = password_hash($security_answer, PASSWORD_DEFAULT);
            $created_by = (int) $currentUser['id'];

            $stmt = mysqli_prepare(
                $conn, 
                "INSERT INTO users (full_name, email, password_hash, security_question, security_answer, role, created_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'ssssssi', $full_name, $email, $password_hash, $security_question, $security_answer_hash, $role, $created_by);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                set_flash('success', "Account for " . htmlspecialchars($full_name) . " ({$role}) created successfully.");
                header('Location: existing_accounts.php');
                exit;
            } elseif (mysqli_errno($conn) === 1062) {
                $error = 'This email address is already registered in the system.';
            } else {
                $error = 'Failed to create user account. Please check database logs.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

$pageTitle = 'Add Account - Super Admin';
$activeNav = 'accounts';

require_once '../includes/header.php';
?>

<div class="narrow">
    <section class="panel">
        <div class="panel-header">
            <div>
                <h1 class="panel-title">Add System Account</h1>
                <p class="panel-desc">Provision citizen credentials or assign departmental administrator roles.</p>
            </div>
            <a class="btn-secondary btn-sm" href="existing_accounts.php">Manage Accounts &rarr;</a>
        </div>

        <?php if ($error): ?>
            <div class="notice error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" data-loading="Creating account…">
            <?php csrf_input(); ?>

            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input id="full_name" type="text" name="full_name" required autocomplete="name" value="<?php echo htmlspecialchars($full_name); ?>">
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input id="email" type="email" name="email" required autocomplete="email" value="<?php echo htmlspecialchars($email); ?>">
            </div>

            <div class="form-group">
                <label for="role">System Role *</label>
                <select id="role" name="role" required>
                    <option value="">Select Account Role</option>
                    <option value="user" <?php echo $role === 'user' ? 'selected' : ''; ?>>Citizen User (Reports only)</option>
                    <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>City Administrator (Verification queue)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="password">Initial Password (Min 8 characters) *</label>
                <input id="password" type="password" name="password" minlength="8" required autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="security_question">Security Recovery Question *</label>
                <select id="security_question" name="security_question" required>
                    <option value="" disabled <?php echo $security_question === '' ? 'selected' : ''; ?>>Select a recovery question</option>
                    <?php foreach (recovery_questions() as $q): ?>
                        <option value="<?php echo htmlspecialchars($q); ?>" <?php echo $security_question === $q ? 'selected' : ''; ?>><?php echo htmlspecialchars($q); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="security_answer">Security Recovery Answer *</label>
                <input id="security_answer" type="text" name="security_answer" required autocomplete="off" value="<?php echo htmlspecialchars($security_answer); ?>">
            </div>

            <div class="actions" style="margin-top: 10px;">
                <button type="submit" class="btn-navy" style="width: 100%;">Create Account</button>
            </div>
        </form>
    </section>
</div>

<?php require_once '../includes/footer.php'; ?>