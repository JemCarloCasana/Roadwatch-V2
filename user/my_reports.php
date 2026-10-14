<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['user'], '../auth/login.php');
$user_id = $currentUser['id'];

// Automated 48-Hour Sunset Transition (Grilled Decision 4)
apply_pending_report_sunset($conn);

// Get active tab filter
$tab = $_GET['tab'] ?? 'all';
$allowed_tabs = ['all', 'pending', 'verified', 'rejected', 'expired'];
if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'all';
}

// Fetch all user reports for count badges and metrics
$page = max(1, (int) ($_GET['page'] ?? 1));

$count_stmt = mysqli_prepare($conn, "SELECT reports.status, COUNT(*) AS total FROM reports WHERE reports.user_id = ? GROUP BY reports.status");
mysqli_stmt_bind_param($count_stmt, 'i', $user_id);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$counts = ['all' => 0, 'pending' => 0, 'verified' => 0, 'rejected' => 0, 'expired' => 0];
while ($c = mysqli_fetch_assoc($count_result)) {
    $counts['all'] += (int) $c['total'];
    if (isset($counts[$c['status']])) {
        $counts[$c['status']] = (int) $c['total'];
    }
}
mysqli_stmt_close($count_stmt);

$tab_where = $tab === 'all' ? '' : "AND reports.status = '{$tab}'";
$total_tab = $tab === 'all' ? $counts['all'] : ($counts[$tab] ?? 0);
$total_pages = max(1, (int) ceil($total_tab / ROADWATCH_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * ROADWATCH_PER_PAGE;

$id_result = mysqli_query($conn, "SELECT reports.id FROM reports WHERE reports.user_id = {$user_id} {$tab_where} ORDER BY reports.submitted_at DESC LIMIT " . ROADWATCH_PER_PAGE . " OFFSET {$offset}");
$page_ids = [];
while ($id_row = mysqli_fetch_assoc($id_result)) {
    $page_ids[] = (int) $id_row['id'];
}

$reports = [];
if (!empty($page_ids)) {
    $id_list = implode(',', $page_ids);
    $detail_result = mysqli_query($conn, "SELECT reports.id, barangays.name AS barangay_name, hazard_types.name AS hazard_name,
                            reports.road_name, reports.landmark, reports.latitude, reports.longitude,
                            reports.description, reports.status, reports.passability, reports.rejection_reason,
                            reports.submitted_at, reports.verified_at, report_photos.filename
                     FROM reports
                     JOIN barangays ON reports.barangay_id = barangays.id
                     JOIN hazard_types ON reports.hazard_type_id = hazard_types.id
                     LEFT JOIN report_photos ON reports.id = report_photos.report_id
                     WHERE reports.id IN ({$id_list})");
    $rows_by_id = [];
    while ($row = mysqli_fetch_assoc($detail_result)) {
        if (!isset($rows_by_id[$row['id']])) {
            $rows_by_id[$row['id']] = $row;
        }
    }
    foreach ($page_ids as $pid) {
        if (isset($rows_by_id[$pid])) {
            $reports[] = $rows_by_id[$pid];
        }
    }
}

$pageTitle = 'My Reports - RoadWatch Citizen Journal';
$activeNav = 'my_reports';

require_once '../includes/header.php';
?>

<div>
    <!-- Top Header & Breadcrumb from Mockup -->
    <div class="panel-header" style="margin-bottom: 24px;">
        <div>
            <h1 class="panel-title" style="font-size: 2.2rem;">My Reports</h1>
            <p class="panel-desc" style="max-width: 650px;">
                Track the road hazard reports you submitted and see their review status.
            </p>
        </div>
        <a class="btn-navy" href="submit_report.php" style="padding: 11px 20px; white-space: nowrap;">
            New report
        </a>
    </div>

    <!-- 2 Metric Cards from Mockup (real counts only) -->
    <div class="telemetry-row telemetry-row-2">
        <div class="telemetry-box">
            <div class="telemetry-top">
                <span class="telemetry-title">Active Submissions</span>
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--muted-light);">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </div>
            <div class="telemetry-num"><?php echo $counts['all']; ?></div>
            <div class="telemetry-meta">Total across all sectors</div>
        </div>

        <div class="telemetry-box highlight">
            <div class="telemetry-top">
                <span class="telemetry-title">Verified &amp; Live</span>
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--passable-green);">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <div class="telemetry-num" style="color: var(--passable-green);"><?php echo $counts['verified']; ?></div>
            <div class="telemetry-meta">Assisting commute routes</div>
        </div>
    </div>

    <!-- Main Workspace 2-Column Layout (Cards + Sidebar) -->
    <div class="workspace-grid">
        <!-- Left Column: Filter Tabs & Journal List -->
        <div>
            <!-- Status Filter Tabs from Mockup -->
            <div class="status-tab-bar" role="group" aria-label="Filter reports by status">
                <a href="?tab=all" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>" <?php echo $tab === 'all' ? 'aria-current="page"' : ''; ?>>All (<?php echo $counts['all']; ?>)</a>
                <a href="?tab=pending" class="status-tab-item <?php echo $tab === 'pending' ? 'active' : ''; ?>" <?php echo $tab === 'pending' ? 'aria-current="page"' : ''; ?>>Pending (<?php echo $counts['pending']; ?>)</a>
                <a href="?tab=verified" class="status-tab-item <?php echo $tab === 'verified' ? 'active' : ''; ?>" <?php echo $tab === 'verified' ? 'aria-current="page"' : ''; ?>>Verified (<?php echo $counts['verified']; ?>)</a>
                <a href="?tab=rejected" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>" <?php echo $tab === 'rejected' ? 'aria-current="page"' : ''; ?>>Rejected (<?php echo $counts['rejected']; ?>)</a>
                <a href="?tab=expired" class="status-tab-item <?php echo $tab === 'expired' ? 'active' : ''; ?>" <?php echo $tab === 'expired' ? 'aria-current="page"' : ''; ?>>Expired (<?php echo $counts['expired']; ?>)</a>
            </div>

            <?php if (empty($reports)): ?>
                <div class="panel" style="text-align: center; padding: 48px 20px;">
                    <h3 style="margin-bottom: 6px;">No reports in this tab</h3>
                    <p class="muted" style="margin-bottom: 18px;">You currently have no hazard reports matching this status filter.</p>
                    <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                        <a class="btn-secondary" href="../index.php">Browse road conditions</a>
                        <a class="btn-navy" href="submit_report.php">Report a hazard</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($reports as $r): ?>
                    <?php if ($r['status'] === 'pending'): ?>
                        <!-- PENDING CARD from Main Workspace.png -->
                        <div class="journal-entry-card pending">
                            <div class="journal-header-row">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="pill pill-info">
                                        Pending review
                                    </span>
                                </div>
                                <span class="muted" style="font-size: 0.78rem;">Submitted <?php echo date('M d, h:i A', strtotime($r['submitted_at'])); ?></span>
                            </div>

                            <div class="journal-title"><?php echo htmlspecialchars($r['hazard_name']); ?> - <?php echo htmlspecialchars($r['road_name']); ?>, Brgy. <?php echo htmlspecialchars($r['barangay_name']); ?></div>

                            <div class="journal-callout-box pending">
                                <strong style="color: var(--brand-blue); display: block; margin-bottom: 2px;">Your report is waiting for an Admin to review it.</strong>
                                <span>&ldquo;<?php echo htmlspecialchars($r['description']); ?>&rdquo;</span>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: var(--muted); margin-top: 10px;">
                                <span>Once reviewed, it will appear on the public Road Conditions page.</span>
                                <span style="font-weight: 600; color: var(--ink);">Report #<?php echo $r['id']; ?> in review queue</span>
                            </div>
                        </div>

                    <?php elseif ($r['status'] === 'verified'): ?>
                        <!-- VERIFIED CARD from Main Workspace.png -->
                        <div class="journal-entry-card verified">
                            <div class="journal-header-row">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span class="pill pill-passable">
                                        Verified
                                    </span>
                                    <?php [, $pass_pill, $pass_label] = passability_display($r['passability'] ?? null); ?>
                                    <span class="pill <?php echo $pass_pill; ?>">
                                        <?php echo $pass_label; ?>
                                    </span>
                                </div>
                                <span class="muted" style="font-size: 0.78rem;">Confirmed <?php echo date('M d, h:i A', strtotime($r['verified_at'] ?? $r['submitted_at'])); ?></span>
                            </div>

                            <div class="journal-title"><?php echo htmlspecialchars($r['hazard_name']); ?> - <?php echo htmlspecialchars($r['road_name']); ?>, Brgy. <?php echo htmlspecialchars($r['barangay_name']); ?></div>

                            <div class="journal-callout-box verified">
                                <strong style="color: var(--passable-green); display: block; margin-bottom: 2px;">Your report is now helping people checking this road.</strong>
                                <span><?php echo htmlspecialchars($r['description']); ?></span>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: var(--muted); margin-top: 10px;">
                                <span>Verified by City Operations</span>
                                <a href="../index.php" style="color: var(--brand-navy); font-weight: 700;">View public report</a>
                            </div>
                        </div>

                    <?php elseif ($r['status'] === 'rejected'): ?>
                        <!-- REJECTED CARD from Main Workspace.png -->
                        <div class="journal-entry-card rejected">
                            <div class="journal-header-row">
                                <span class="pill pill-impassable">
                                    Rejected
                                </span>
                                <span class="muted" style="font-size: 0.78rem;">Reviewed <?php echo date('M d, Y', strtotime($r['submitted_at'])); ?></span>
                            </div>

                            <div class="journal-title"><?php echo htmlspecialchars($r['hazard_name']); ?> - <?php echo htmlspecialchars($r['road_name']); ?>, Brgy. <?php echo htmlspecialchars($r['barangay_name']); ?></div>

                            <div class="journal-callout-box rejected">
                                <strong style="color: var(--impassable-red); display: block; margin-bottom: 2px;">Reason: The submitted information could not be verified.</strong>
                                <span>Admin note: <?php echo htmlspecialchars($r['rejection_reason'] ?: 'Insufficient evidence or road was found clear upon inspection.'); ?></span>
                            </div>

                            <div style="font-size: 0.78rem; color: var(--muted); margin-top: 10px;">
                                <span>Cleared reports keep drivers from taking unneeded detours.</span>
                            </div>
                        </div>

                    <?php elseif ($r['status'] === 'expired'): ?>
                        <!-- EXPIRED CARD from Main Workspace.png -->
                        <div class="journal-entry-card expired">
                            <div class="journal-header-row">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="pill pill-neutral">
                                        Expired
                                    </span>
                                </div>
                                <span class="muted" style="font-size: 0.78rem;">Submitted <?php echo date('M d, Y', strtotime($r['submitted_at'])); ?></span>
                            </div>

                            <div class="journal-title"><?php echo htmlspecialchars($r['hazard_name']); ?> - <?php echo htmlspecialchars($r['road_name']); ?> (<?php echo htmlspecialchars($r['barangay_name']); ?>)</div>

                            <div class="journal-callout-box" style="background: var(--line-subtle); border-left: 3px solid var(--muted);">
                                <strong style="color: var(--ink); display: block; margin-bottom: 2px;">No Admin reviewed this report within 48 hours, so it expired.</strong>
                                <span>Reports sunset automatically to keep navigation info fresh.</span>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: var(--muted); margin-top: 10px;">
                                <span>Is this hazard still blocking the road?</span>
                                <a href="submit_report.php" style="color: var(--brand-navy); font-weight: 700;">Report again</a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php echo render_pager($total_tab, $page, ['tab' => $tab]); ?>
        </div>

        <!-- Right Column: Sidebar from Mockup -->
        <div>
            <!-- Card 1: How Reports Are Checked -->
            <div class="panel" style="padding: 22px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--brand-navy);">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <h3 style="font-size: 1.05rem; margin: 0;">How Reports Are Checked</h3>
                </div>
                <p class="muted" style="font-size: 0.82rem; line-height: 1.5; margin-bottom: 16px;">
                    Most reports are checked within 15 to 30 minutes during normal operating hours. Reports older than 48 hours are automatically archived to keep road information fresh.
                </p>

                <div style="display: flex; flex-direction: column; gap: 14px; font-size: 0.84rem;">
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <span style="width: 22px; height: 22px; border-radius: 4px; background: var(--brand-navy); color: #fff; font-size: 0.72rem; font-weight: 700; display: grid; place-items: center; flex-shrink: 0;">1</span>
                        <div>
                            <strong style="color: var(--brand-navy); display: block;">Citizen Intake</strong>
                            <span class="muted" style="font-size: 0.78rem;">Your report and optional photo arrive at the admin portal.</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <span style="width: 22px; height: 22px; border-radius: 4px; background: var(--brand-navy); color: #fff; font-size: 0.72rem; font-weight: 700; display: grid; place-items: center; flex-shrink: 0;">2</span>
                        <div>
                            <strong style="color: var(--brand-navy); display: block;">Cross-Referencing</strong>
                            <span class="muted" style="font-size: 0.78rem;">Tide tables, nearby CCTV feeds, and CDRRMO patrol reports verify water depth.</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <span style="width: 22px; height: 22px; border-radius: 4px; background: var(--brand-navy); color: #fff; font-size: 0.72rem; font-weight: 700; display: grid; place-items: center; flex-shrink: 0;">3</span>
                        <div>
                            <strong style="color: var(--brand-navy); display: block;">Public Broadcasting</strong>
                            <span class="muted" style="font-size: 0.78rem;">Live markers populate the public corridor map with vehicle passability tags.</span>
                        </div>
                    </div>
                </div>

                <div style="border-top: 1px solid var(--line); margin-top: 18px; padding-top: 12px; font-size: 0.78rem; color: var(--ink-secondary);">
                    Reports stay visible while the hazard lasts. Expired ones can be reported again.
                </div>
            </div>

            <!-- Card 2: Community Safety Note -->
            <div class="panel" style="padding: 18px; margin-bottom: 20px;">
                <h4 style="font-size: 1rem; margin-bottom: 4px;">Community-Driven Safety</h4>
                <p class="muted" style="font-size: 0.8rem; line-height: 1.5; margin: 0;">
                    Every submission prevents stranded vehicles and gives public safety officers accurate locations for rapid relief.
                </p>
            </div>

            <!-- Card 3: CDRRMO Emergency Hotline -->
            <div class="panel" style="padding: 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #fff;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 38px; height: 38px; border-radius: 6px; background: var(--line-subtle); display: grid; place-items: center; color: var(--brand-navy);">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 0.85rem; font-weight: 700; color: var(--brand-navy);">Urgent life danger?</div>
                        <div style="font-size: 0.8rem; color: var(--ink-secondary);">Direct CDRRMO Hotline</div>
                    </div>
                </div>
                <a href="<?php echo ROADWATCH_CDRRMO_TEL; ?>" class="btn-navy btn-sm" style="font-size: 0.88rem; padding: 7px 12px; white-space: nowrap;">
                    <?php echo ROADWATCH_CDRRMO_DISPLAY; ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>