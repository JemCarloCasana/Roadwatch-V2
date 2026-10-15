<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['super_admin'], '../auth/admin_login.php');

$search = trim($_GET['search'] ?? '');
$where = "role IN ('admin', 'super_admin')";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (full_name LIKE ? OR email LIKE ?)";
    $like = "%{$search}%";
    $params = [$like, $like];
    $types = "ss";
}

$stmt = mysqli_prepare($conn, "SELECT id, full_name, email, role, is_active, created_at FROM users WHERE {$where} ORDER BY created_at DESC");
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$admins = [];
$total_count = 0;
$active_count = 0;
$inactive_count = 0;

while ($row = mysqli_fetch_assoc($res)) {
    $admins[] = $row;
    $total_count++;
    if ((int)$row['is_active'] === 1) {
        $active_count++;
    } else {
        $inactive_count++;
    }
}
mysqli_stmt_close($stmt);

$pageTitle = 'Admin Accounts - RoadWatch Operations';
$activeNav = 'existing_accounts';

require_once '../includes/header.php';
?>

<div class="semi-narrow">
    <!-- Top Header from Main-2.png -->
    <div class="panel-header" style="margin-bottom: 24px;">
        <div>
            <h1 class="panel-title" style="font-size: 2.1rem;">Admin Accounts</h1>
            <p class="panel-desc">
                Manage administrators who can review and verify RoadWatch reports.
            </p>
            <div class="muted" style="font-size: 0.82rem; font-weight: 500; margin-top: 6px;">
                <?php echo $total_count; ?> administrators, <?php echo $active_count; ?> active, <?php echo $inactive_count; ?> inactive
            </div>
        </div>

        <a class="btn-navy" href="accounts.php" style="padding: 10px 18px; white-space: nowrap;">
            Create admin
        </a>
    </div>

    <!-- Search Bar from Main-2.png -->
    <div style="margin-bottom: 18px;">
        <form method="GET" action="existing_accounts.php" class="admin-search-bar">
            <div style="flex: 1; position: relative;">
                <label class="visually-hidden" for="admin-search">Search administrators by name or email</label>
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted-light);">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="admin-search" name="search" placeholder="Search admins..." value="<?php echo htmlspecialchars($search); ?>" style="padding-left: 40px;">
            </div>
            <button type="submit" class="btn-navy btn-sm" style="white-space: nowrap;">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn-secondary btn-sm" href="existing_accounts.php" style="white-space: nowrap;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Accounts Table from Main-2.png -->
    <div class="panel table-wrap" style="padding: 0; overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 560px;">
            <thead>
                <tr style="background: var(--line-subtle); border-bottom: 1px solid var(--line); font-size: 0.78rem; font-weight: 700; color: var(--ink-secondary);">
                    <th style="padding: 14px 20px;">Admin</th>
                    <th style="padding: 14px 20px;">Status</th>
                    <th style="padding: 14px 20px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($admins)): ?>
                    <tr>
                        <td colspan="3" style="padding: 36px 20px; text-align: center;" class="muted">No administrators match your search.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($admins as $adm): ?>
                        <tr style="border-bottom: 1px solid var(--line-subtle);">
                            <td style="padding: 16px 20px;">
                                <div style="font-weight: 700; color: var(--brand-navy); font-size: 0.95rem;">
                                    <?php echo htmlspecialchars($adm['full_name']); ?>
                                </div>
                                <div class="muted" style="font-size: 0.82rem;">
                                    <?php echo htmlspecialchars($adm['email']); ?>
                                </div>
                            </td>

                            <td style="padding: 16px 20px;">
                                <?php if ((int)$adm['is_active'] === 1): ?>
                                    <span class="badge passable">
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge">
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td style="padding: 16px 20px; text-align: right;">
                                <?php if ((int)$adm['id'] !== (int)$currentUser['id']): ?>
                                    <form method="POST" action="toggle_account.php" style="display: inline;" data-loading="Updating…" onsubmit="return confirm('Are you sure you want to <?php echo (int)$adm['is_active'] === 1 ? 'deactivate' : 'activate'; ?> this account?');">
                                        <input type="hidden" name="id" value="<?php echo $adm['id']; ?>">
                                        <?php csrf_input(); ?>
                                        <button type="submit" class="btn-secondary btn-sm" style="font-size: 0.82rem; padding: 5px 12px; white-space: nowrap;">
                                            <?php echo (int)$adm['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 0.8rem;">(Current User)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>