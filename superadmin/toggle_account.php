<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['super_admin'], '../auth/admin_login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: existing_accounts.php');
    exit;
}

if (!verify_csrf_token()) {
    set_flash('error', 'Security token expired or invalid.');
    header('Location: existing_accounts.php');
    exit;
}

$account_id = (int) ($_POST['id'] ?? 0);

if ($account_id <= 0) {
    set_flash('error', 'Invalid account ID.');
    header('Location: existing_accounts.php');
    exit;
}

// Prevent Super Admin from deactivating their own account
if ($account_id === $currentUser['id']) {
    set_flash('error', 'You cannot deactivate your own super administrator account.');
    header('Location: existing_accounts.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id, full_name, is_active FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $account_id);
mysqli_stmt_execute($stmt);
$targetUser = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$targetUser) {
    set_flash('error', 'Account not found.');
    header('Location: existing_accounts.php');
    exit;
}

$newStatus = (int) $targetUser['is_active'] === 1 ? 0 : 1;
$statusWord = $newStatus === 1 ? 'activated' : 'deactivated';

$updateStmt = mysqli_prepare($conn, "UPDATE users SET is_active = ? WHERE id = ?");
mysqli_stmt_bind_param($updateStmt, "ii", $newStatus, $account_id);
if (mysqli_stmt_execute($updateStmt)) {
    set_flash('success', "Account for " . htmlspecialchars($targetUser['full_name'], ENT_QUOTES, 'UTF-8') . " was successfully {$statusWord}.");
} else {
    set_flash('error', "Failed to update account status.");
}
mysqli_stmt_close($updateStmt);

header('Location: existing_accounts.php');
exit;