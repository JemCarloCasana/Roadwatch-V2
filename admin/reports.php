<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['admin', 'super_admin'], '../auth/admin_login.php');

// Automated 48-Hour Sunset Transition (Grilled Decision 4)
apply_pending_report_sunset($conn);

// Search & Filter parameters
$search = trim($_GET['search'] ?? '');
$barangay_id = (int) ($_GET['barangay_id'] ?? 0);
$hazard_type_id = (int) ($_GET['hazard_type_id'] ?? 0);

$where = ["reports.status = 'pending'"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(reports.road_name LIKE ? OR reports.landmark LIKE ? OR reports.description LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}
if ($barangay_id > 0) {
    $where[] = "reports.barangay_id = ?";
    $params[] = $barangay_id;
    $types .= "i";
}
if ($hazard_type_id > 0) {
    $where[] = "reports.hazard_type_id = ?";
    $params[] = $hazard_type_id;
    $types .= "i";
}

$where_clause = implode(" AND ", $where);

// Oldest report waiting calculation
$oldest_res = mysqli_query($conn, "SELECT MIN(submitted_at) AS oldest_time FROM reports WHERE status = 'pending'");
$oldest_row = mysqli_fetch_assoc($oldest_res);
$oldest_time = $oldest_row['oldest_time'] ?? null;
$oldest_text = "No pending reports";
if ($oldest_time) {
    $diff_hours = (time() - strtotime($oldest_time)) / 3600;
    if ($diff_hours >= 24) {
        $days = floor($diff_hours / 24);
        $oldest_text = "Oldest report waiting: {$days} day" . ($days > 1 ? "s" : "");
    } else {
        $hrs = max(1, floor($diff_hours));
        $oldest_text = "Oldest report waiting: {$hrs} hour" . ($hrs > 1 ? "s" : "");
    }
}

// Paginated queue: COUNT + page of IDs (no joins needed — all filters hit
// reports.*), then one detail query. PHP-side dedupe keeps one card per
// report even when several photos exist.
$page = max(1, (int) ($_GET['page'] ?? 1));

$count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM reports WHERE {$where_clause}");
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$total_reports = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'] ?? 0);
mysqli_stmt_close($count_stmt);

$total_pages = max(1, (int) ceil($total_reports / ROADWATCH_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * ROADWATCH_PER_PAGE;

$id_stmt = mysqli_prepare($conn, "SELECT reports.id FROM reports WHERE {$where_clause} ORDER BY reports.submitted_at ASC LIMIT ? OFFSET ?");
$id_types = $types . 'ii';
$id_params = array_merge($params, [ROADWATCH_PER_PAGE, $offset]);
mysqli_stmt_bind_param($id_stmt, $id_types, ...$id_params);
mysqli_stmt_execute($id_stmt);
$id_result = mysqli_stmt_get_result($id_stmt);
$page_ids = [];
while ($id_row = mysqli_fetch_assoc($id_result)) {
    $page_ids[] = (int) $id_row['id'];
}
mysqli_stmt_close($id_stmt);

$pendingReports = [];
if (!empty($page_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($page_ids), '?'));
    $in_types = str_repeat('i', count($page_ids));
    $detail_sql = "SELECT reports.id, users.full_name, barangays.name AS barangay_name, hazard_types.name AS hazard_name,
                          reports.road_name, reports.landmark, reports.description, reports.submitted_at, report_photos.filename
                   FROM reports
                   JOIN users ON reports.user_id = users.id
                   JOIN barangays ON reports.barangay_id = barangays.id
                   JOIN hazard_types ON reports.hazard_type_id = hazard_types.id
                   LEFT JOIN report_photos ON reports.id = report_photos.report_id
                   WHERE reports.id IN ({$in_placeholders})";
    $detail_stmt = mysqli_prepare($conn, $detail_sql);
    mysqli_stmt_bind_param($detail_stmt, $in_types, ...$page_ids);
    mysqli_stmt_execute($detail_stmt);
    $detail_result = mysqli_stmt_get_result($detail_stmt);
    $rows_by_id = [];
    while ($row = mysqli_fetch_assoc($detail_result)) {
        if (!isset($rows_by_id[$row['id']])) {
            $rows_by_id[$row['id']] = $row;
        }
    }
    mysqli_stmt_close($detail_stmt);
    foreach ($page_ids as $pid) {
        if (isset($rows_by_id[$pid])) {
            $pendingReports[] = $rows_by_id[$pid];
        }
    }
}

$pager_params = [];
if ($search !== '') {
    $pager_params['search'] = $search;
}
if ($barangay_id > 0) {
    $pager_params['barangay_id'] = $barangay_id;
}
if ($hazard_type_id > 0) {
    $pager_params['hazard_type_id'] = $hazard_type_id;
}

$barangays_list = mysqli_query($conn, "SELECT id, name FROM barangays ORDER BY name ASC");
$hazard_types_list = mysqli_query($conn, "SELECT id, name FROM hazard_types ORDER BY name ASC");

$pageTitle = 'Reports to Review - RoadWatch Operations';
$activeNav = 'reports';

require_once '../includes/header.php';
?>

<div>
    <!-- Top Header from Main-6.png -->
    <div class="panel-header" style="margin-bottom: 20px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                <h1 class="panel-title" style="font-size: 1.85rem; margin: 0;">Reports to Review</h1>
                <span class="status-tab-item active" style="padding: 3px 10px; font-size: 0.8rem; background: var(--brand-navy-light); color: var(--brand-navy); border-color: var(--brand-navy-border);">
                    <?php echo $total_reports; ?> Pending
                </span>
            </div>
            <p class="panel-desc">
                Review submitted road hazard reports and decide whether they should appear on RoadWatch.
            </p>
        </div>
        <div class="flex items-center gap-1.5 whitespace-nowrap rounded-md border border-line bg-paper px-3 py-1.5 text-[0.82rem] text-mist">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span><?php echo htmlspecialchars($oldest_text); ?></span>
        </div>
    </div>

    <!-- Search & Filter Bar from Main-6.png -->
    <div class="panel" style="padding: 14px 18px; margin-bottom: 20px;">
        <form method="GET" action="reports.php" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 2; min-width: 240px; position: relative;">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted-light);">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" name="search" placeholder="Search road, barangay, or hazard..." value="<?php echo htmlspecialchars($search); ?>" style="padding-left: 36px !important;">
            </div>

            <div style="flex: 1; min-width: 170px;">
                <select name="barangay_id" onchange="this.form.submit()">
                    <option value="">Barangay: All</option>
                    <?php while ($b = mysqli_fetch_assoc($barangays_list)): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $barangay_id === (int)$b['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 170px;">
                <select name="hazard_type_id" onchange="this.form.submit()">
                    <option value="">Hazard Type: All</option>
                    <?php while ($h = mysqli_fetch_assoc($hazard_types_list)): ?>
                        <option value="<?php echo $h['id']; ?>" <?php echo $hazard_type_id === (int)$h['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($h['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button type="submit" class="btn-navy" style="padding: 8px 14px; font-size: 0.85rem;">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Refresh
            </button>
        </form>
    </div>

    <!-- Review Queue List from Main-6.png -->
    <?php if ($total_reports === 0): ?>
        <div class="panel" style="text-align: center; padding: 60px 20px;">
            <div style="width: 52px; height: 52px; border-radius: 50%; background: var(--passable-soft); color: var(--passable-green); display: grid; place-items: center; margin: 0 auto 14px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h3 style="margin-bottom: 6px;">Queue is Clean</h3>
            <p class="muted" style="font-size: 0.9rem; margin-bottom: 18px;">No pending reports require municipal review right now.</p>
            <a class="btn-secondary" href="history.php">Browse audit history</a>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 14px;">
            <?php foreach ($pendingReports as $r): 
                $age_hours = (time() - strtotime($r['submitted_at'])) / 3600;
                $is_urgent = $age_hours >= 24;
                $is_expiring = $age_hours >= 40;
            ?>
                <div class="panel clickable-card" data-href="review.php?id=<?php echo $r['id']; ?>" tabindex="0" title="Review report #<?php echo $r['id']; ?>" style="padding: 16px 20px; margin-bottom: 0; display: flex; align-items: center; justify-content: space-between; gap: 20px; transition: box-shadow 0.15s ease;">
                    <!-- Left: Photo thumbnail -->
                    <div style="flex-shrink: 0;">
                        <?php if (!empty($r['filename'])): ?>
                            <img src="../uploads/<?php echo htmlspecialchars($r['filename']); ?>" alt="Evidence" style="width: 130px; height: 95px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--line); display: block;">
                        <?php else: ?>
                            <div style="width: 130px; height: 95px; background: var(--line-subtle); border: 1px solid var(--line); border-radius: var(--radius-sm); display: grid; place-items: center; color: var(--muted-light); font-size: 0.75rem; font-weight: 600;">
                                NO PHOTO
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Center: Details from Mockup -->
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <span style="font-size: 0.78rem; font-weight: 700; color: var(--brand-navy); background: var(--line-subtle); padding: 2px 7px; border-radius: var(--radius-sm);">
                                <?php echo htmlspecialchars($r['hazard_name']); ?>
                            </span>

                            <?php if ($is_expiring): ?>
                                <span style="font-size: 0.74rem; font-weight: 700; color: var(--impassable-red); display: flex; align-items: center; gap: 3px;">
                                    Waiting 2 days - Expires soon
                                </span>
                            <?php elseif ($is_urgent): ?>
                                <span style="font-size: 0.74rem; font-weight: 700; color: var(--partial-amber); display: flex; align-items: center; gap: 3px;">
                                    Waiting 1 day
                                </span>
                            <?php else: ?>
                                <span class="muted" style="font-size: 0.76rem;">
                                    <?php
                                    $sub_ts = strtotime($r['submitted_at']);
                                    $sub_day = date('Y-m-d', $sub_ts);
                                    if ($sub_day === date('Y-m-d')) {
                                        $sub_when = 'Today ' . date('h:i A', $sub_ts);
                                    } elseif ($sub_day === date('Y-m-d', strtotime('-1 day'))) {
                                        $sub_when = 'Yesterday ' . date('h:i A', $sub_ts);
                                    } else {
                                        $sub_when = date('M j, Y h:i A', $sub_ts);
                                    }
                                    echo 'Submitted ' . htmlspecialchars($sub_when);
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="font-size: 1.05rem; font-weight: 700; color: var(--brand-navy); margin-bottom: 2px;">
                            <?php echo htmlspecialchars($r['road_name']); ?> - Brgy. <?php echo htmlspecialchars($r['barangay_name']); ?>
                        </div>

                        <div class="muted" style="font-size: 0.82rem; margin-bottom: 6px;">
                            Near <?php echo htmlspecialchars($r['landmark']); ?>
                        </div>

                        <div style="font-size: 0.85rem; color: var(--ink-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 550px;">
                            &ldquo;<?php echo htmlspecialchars($r['description']); ?>&rdquo;
                        </div>
                    </div>

                    <!-- Right: Review Action Button -->
                    <div style="flex-shrink: 0;">
                        <a href="review.php?id=<?php echo $r['id']; ?>" class="btn-navy" style="padding: 10px 18px; font-size: 0.88rem; white-space: nowrap;">
                            Review &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php echo render_pager($total_reports, $page, $pager_params); ?>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>