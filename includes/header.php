<?php
require_once __DIR__ . '/auth_guard.php';

$pageTitle = $pageTitle ?? 'RoadWatch Dagupan';
$activeNav = $activeNav ?? '';
$currentUser = isset($_SESSION['user_id']) ? [
    'id' => (int) $_SESSION['user_id'],
    'role' => $_SESSION['role'] ?? 'user',
    'full_name' => $_SESSION['full_name'] ?? 'User'
] : null;

// Determine relative path to includes root
$basePath = isset($pathDepth) ? str_repeat('../', $pathDepth) : '../';
if (!isset($pathDepth) && basename(dirname($_SERVER['PHP_SELF'])) === 'roadwatch-sandbox') {
    $basePath = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo $basePath; ?>includes/style.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>includes/tailwind.css">
    <link rel="icon" type="image/png" href="<?php echo $basePath; ?>includes/RoadWatch.png">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-header">
    <div class="site-header-inner">
        <a href="<?php echo $basePath; ?>index.php" class="brand-lockup">
            <img src="<?php echo $basePath; ?>includes/RoadWatch.png" alt="RoadWatch logo" class="brand-mark-img" width="36" height="36">
            <div>
                <div class="brand-title">RoadWatch</div>
                <div class="brand-subtitle">Dagupan City Hazard Operations</div>
            </div>
        </a>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Open menu">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>

        <?php if ($currentUser): ?>
        <nav class="global-nav" id="site-nav" aria-label="Main Navigation">
            <?php if ($currentUser['role'] === 'user'): ?>
                <a class="nav-item <?php echo $activeNav === 'home' ? 'active' : ''; ?>" href="<?php echo $basePath; ?>index.php" <?php echo $activeNav === 'home' ? 'aria-current="page"' : ''; ?>>Home</a>
                <a class="nav-item <?php echo $activeNav === 'my_reports' ? 'active' : ''; ?>" href="<?php echo $basePath; ?>user/my_reports.php" <?php echo $activeNav === 'my_reports' ? 'aria-current="page"' : ''; ?>>My Reports</a>
                <a class="nav-item <?php echo $activeNav === 'submit_report' ? 'active' : ''; ?>" href="<?php echo $basePath; ?>user/submit_report.php" <?php echo $activeNav === 'submit_report' ? 'aria-current="page"' : ''; ?>>Submit Hazard</a>
            <?php elseif (in_array($currentUser['role'], ['admin', 'super_admin'], true)): ?>
                <a class="nav-item <?php echo $activeNav === 'reports' ? 'active' : ''; ?>" href="<?php echo $basePath; ?>admin/reports.php" <?php echo $activeNav === 'reports' ? 'aria-current="page"' : ''; ?>>Pending Queue</a>
                <a class="nav-item <?php echo $activeNav === 'history' ? 'active' : ''; ?>" href="<?php echo $basePath; ?>admin/history.php" <?php echo $activeNav === 'history' ? 'aria-current="page"' : ''; ?>>Audit History</a>
                <a class="nav-item" href="<?php echo $basePath; ?>index.php" target="_blank" rel="noopener" title="Preview the public Road Conditions page">View site<span class="visually-hidden"> (opens in new tab)</span></a>
                <?php if ($currentUser['role'] === 'super_admin'): ?>
                    <a class="nav-item <?php echo $activeNav === 'accounts' ? 'active' : ''; ?>" href="<?php echo $basePath; ?>superadmin/accounts.php" <?php echo $activeNav === 'accounts' ? 'aria-current="page"' : ''; ?>>Add Account</a>
                    <a class="nav-item <?php echo $activeNav === 'existing_accounts' ? 'active' : ''; ?>" href="<?php echo $basePath; ?>superadmin/existing_accounts.php" <?php echo $activeNav === 'existing_accounts' ? 'aria-current="page"' : ''; ?>>Manage Accounts</a>
                <?php endif; ?>
            <?php endif; ?>

            <div class="user-badge">
                <span class="user-name"><?php echo htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="role-pill <?php echo htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo str_replace('_', ' ', htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8')); ?>
                </span>
            </div>

            <a class="nav-item nav-danger" href="<?php echo $basePath; ?>auth/logout.php" title="Sign out of your session">Log out</a>
        </nav>
        <?php else: ?>
        <nav class="global-nav" id="site-nav" aria-label="Main Navigation">
            <a class="nav-item" href="<?php echo $basePath; ?>auth/login.php">Citizen Login</a>
            <a class="nav-item" href="<?php echo $basePath; ?>auth/admin_login.php">Admin Portal</a>
            <a class="btn-navy btn-sm" href="<?php echo $basePath; ?>auth/register.php">Register</a>
        </nav>
        <?php endif; ?>
    </div>
</header>
<main class="page-shell" id="main-content">
<?php render_flash(); ?>
