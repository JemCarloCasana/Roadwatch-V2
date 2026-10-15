<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['admin', 'super_admin'], '../auth/admin_login.php');

$page = max(1, (int) ($_GET['page'] ?? 1));

// Audit filter: verified / rejected / expired ('all' keeps every decided
// report, including sunset-expired ones the queue no longer shows).
// $status_where is interpolated, not bound, because mysqli has no placeholder
// for a dynamic IN-list; it stays safe because $status_filter is strictly
// whitelisted below (in_array with strict=true) before any interpolation.
$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all', 'verified', 'rejected', 'expired'], true)) {
    $status_filter = 'all';
}
$status_where = $status_filter === 'all'
    ? "reports.status IN ('verified', 'rejected', 'expired')"
    : "reports.status = '" . $status_filter . "'";

$count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reports WHERE {$status_where}");
$total_history = (int) (mysqli_fetch_assoc($count_result)['total'] ?? 0);

$total_pages = max(1, (int) ceil($total_history / ROADWATCH_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * ROADWATCH_PER_PAGE;

$id_result = mysqli_query($conn, "SELECT reports.id FROM reports WHERE {$status_where} ORDER BY COALESCE(reports.verified_at, reports.submitted_at) DESC LIMIT " . ROADWATCH_PER_PAGE . " OFFSET {$offset}");
$page_ids = [];
while ($id_row = mysqli_fetch_assoc($id_result)) {
    $page_ids[] = (int) $id_row['id'];
}

$historyReports = [];
if (!empty($page_ids)) {
    $id_list = implode(',', $page_ids);
    $result = mysqli_query($conn, "SELECT reports.id, users.full_name, barangays.name AS barangay_name, hazard_types.name AS hazard_name,
                reports.road_name, reports.landmark, reports.latitude, reports.longitude,
                reports.status, reports.passability, reports.rejection_reason,
                reports.submitted_at, reports.verified_at, report_photos.filename
         FROM reports
         JOIN users ON reports.user_id = users.id
         JOIN barangays ON reports.barangay_id = barangays.id
         JOIN hazard_types ON reports.hazard_type_id = hazard_types.id
         LEFT JOIN report_photos ON reports.id = report_photos.report_id
         WHERE reports.id IN ({$id_list})");
    $rows_by_id = [];
    while ($row = mysqli_fetch_assoc($result)) {
        if (!isset($rows_by_id[$row['id']])) {
            $rows_by_id[$row['id']] = $row;
        }
    }
    foreach ($page_ids as $pid) {
        if (isset($rows_by_id[$pid])) {
            $historyReports[] = $rows_by_id[$pid];
        }
    }
}

$pageTitle = 'Report Audit History - RoadWatch Operations';
$activeNav = 'history';

require_once '../includes/header.php';
?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h1 class="panel-title">Hazard Audit History</h1>
            <p class="panel-desc">Complete historical log of verified, rejected, and expired road conditions in Dagupan City.</p>
        </div>
        <a class="btn-secondary btn-sm" href="reports.php">&larr; Back to Pending Queue</a>
    </div>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 16px; flex-wrap: wrap;">
        <label for="status" class="muted" style="font-size: 0.82rem; font-weight: 600;">Show:</label>
        <select id="status" name="status" onchange="this.form.submit()" style="max-width: 220px;">
            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All decided (verified + rejected + expired)</option>
            <option value="verified" <?php echo $status_filter === 'verified' ? 'selected' : ''; ?>>Verified only</option>
            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected only</option>
            <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired only</option>
        </select>
    </form>

    <?php if ($total_history === 0): ?>
        <div class="panel empty-state">
            <h3 style="margin-bottom: 6px;">No reviewed reports yet</h3>
            <p class="feed-empty-text" style="margin-bottom: 18px;">Verified, rejected, and expired reports will appear here once admins work through the queue.</p>
            <a class="btn-secondary" href="reports.php">Back to Pending Queue</a>
        </div>
    <?php else: ?>

        <!-- Desktop Table View -->
        <div class="table-wrap desktop-only" tabindex="0" role="region" aria-label="Reviewed reports table (scrollable)">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Reporter &amp; Location</th>
                        <th>Hazard Type</th>
                        <th>Status</th>
                        <th>Passability</th>
                        <th>Photo</th>
                        <th>Reviewed At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historyReports as $r): ?>
                        <tr class="clickable-row" data-href="review.php?id=<?php echo $r['id']; ?>&from=history" tabindex="0" title="View details for report #<?php echo $r['id']; ?>">
                            <td><strong><a href="review.php?id=<?php echo $r['id']; ?>&from=history">#<?php echo $r['id']; ?></a></strong></td>
                            <td>
                                <div><strong><a href="review.php?id=<?php echo $r['id']; ?>&from=history"><?php echo htmlspecialchars($r['road_name']); ?></a></strong></div>
                                <div class="muted" style="font-size: 0.8rem;"><?php echo htmlspecialchars($r['barangay_name']); ?></div>
                                <div class="mt-[2px] text-[0.76rem] text-mist">By: <?php echo htmlspecialchars($r['full_name']); ?></div>
                                <?php if (!empty($r['latitude']) && !empty($r['longitude'])): ?>
                                    <a href="https://www.google.com/maps?q=<?php echo $r['latitude']; ?>,<?php echo $r['longitude']; ?>" target="_blank" rel="noopener noreferrer" style="font-size: 0.76rem;">
                                        Map
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge">
                                    <?php echo htmlspecialchars($r['hazard_name']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo htmlspecialchars($r['status']); ?>">
                                    <?php echo htmlspecialchars($r['status']); ?>
                                </span>
                                <?php if ($r['status'] === 'rejected' && !empty($r['rejection_reason'])): ?>
                                    <div style="font-size: 0.78rem; color: var(--danger); margin-top: 4px; max-width: 180px;">
                                        Reason: <?php echo htmlspecialchars($r['rejection_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['passability'])): ?>
                                    <span class="badge <?php echo htmlspecialchars($r['passability']); ?>">
                                        <?php echo str_replace('_', ' ', htmlspecialchars($r['passability'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['filename'])): ?>
                                    <a href="review.php?id=<?php echo $r['id']; ?>&from=history">
                                        <img src="../uploads/<?php echo htmlspecialchars($r['filename']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--line);" alt="Hazard photo for report #<?php echo $r['id']; ?>">
                                    </a>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 0.8rem;">No photo</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.82rem; color: var(--muted); white-space: nowrap;">
                                <?php if (!empty($r['verified_at'])): ?>
                                    <?php echo date('M d, Y', strtotime($r['verified_at'])); ?><br>
                                    <?php echo date('h:i A', strtotime($r['verified_at'])); ?>
                                <?php else: ?>
                                    <?php echo date('M d, Y', strtotime($r['submitted_at'])); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile History Cards -->
        <div class="incident-grid">
            <?php foreach ($historyReports as $r): ?>
                <a class="incident-card incident-link" href="review.php?id=<?php echo $r['id']; ?>&from=history" aria-label="View details for report #<?php echo $r['id']; ?>, <?php echo htmlspecialchars($r['road_name']); ?>">
                    <div class="incident-card-header">
                        <div>
                            <span class="badge <?php echo htmlspecialchars($r['status']); ?>">
                                <?php echo htmlspecialchars($r['status']); ?>
                            </span>
                            <?php if (!empty($r['passability'])): ?>
                                <span class="badge <?php echo htmlspecialchars($r['passability']); ?>" style="margin-left: 4px;">
                                    <?php echo str_replace('_', ' ', htmlspecialchars($r['passability'])); ?>
                                </span>
                            <?php endif; ?>
                            <div class="incident-card-title" style="margin-top: 6px;">
                                <?php echo htmlspecialchars($r['hazard_name']); ?> - <?php echo htmlspecialchars($r['road_name']); ?>
                            </div>
                        </div>
                        <span class="muted" style="font-size: 0.82rem; font-weight: 700;">#<?php echo $r['id']; ?></span>
                    </div>

                    <?php if (!empty($r['filename'])): ?>
                        <img class="incident-thumb" src="../uploads/<?php echo htmlspecialchars($r['filename']); ?>" alt="Hazard photo for report #<?php echo $r['id']; ?>">
                    <?php endif; ?>

                    <div class="incident-meta">
                        <div><strong>Barangay:</strong> <?php echo htmlspecialchars($r['barangay_name']); ?></div>
                        <div><strong>Reporter:</strong> <?php echo htmlspecialchars($r['full_name']); ?></div>
                        <?php if ($r['status'] === 'rejected' && !empty($r['rejection_reason'])): ?>
                            <div class="notice error" style="margin: 4px 0; padding: 6px 10px; font-size: 0.82rem;">
                                <strong>Reason:</strong> <?php echo htmlspecialchars($r['rejection_reason']); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <strong>Reviewed:</strong> 
                            <?php echo date('M d, Y h:i A', strtotime($r['verified_at'] ?? $r['submitted_at'])); ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php echo render_pager($total_history, $page, $status_filter === 'all' ? [] : ['status' => $status_filter]); ?>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>