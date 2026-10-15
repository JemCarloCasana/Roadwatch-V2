<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['admin', 'super_admin'], '../auth/admin_login.php');
$report_id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($report_id <= 0) {
    header('Location: reports.php');
    exit;
}

// Fetch all pending report IDs in chronological order for queue navigation
$queue_res = mysqli_query($conn, "SELECT id FROM reports WHERE status = 'pending' ORDER BY submitted_at ASC");
$queue_ids = [];
while ($q = mysqli_fetch_assoc($queue_res)) {
    $queue_ids[] = (int) $q['id'];
}

$total_pending = count($queue_ids);
$current_index = array_search($report_id, $queue_ids, true);
$queue_position = ($current_index !== false) ? ($current_index + 1) : 1;
$prev_id = ($current_index !== false && $current_index > 0) ? $queue_ids[$current_index - 1] : null;
$next_id = ($current_index !== false && $current_index < $total_pending - 1) ? $queue_ids[$current_index + 1] : null;

// Handle Form Submissions (Verification or Rejection)
$reject_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        set_flash('error', 'Security token expired. Please try again.');
        header("Location: review.php?id={$report_id}");
        exit;
    }

    $action = $_POST['action'] ?? 'verify';
    $admin_id = $currentUser['id'];

    if ($action === 'reopen') {
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE reports
             SET status = 'pending', passability = NULL, reviewed_by = NULL,
                 verified_at = NULL, rejection_reason = NULL
             WHERE id = ? AND status IN ('verified', 'rejected')"
        );
        mysqli_stmt_bind_param($stmt, 'i', $report_id);
        mysqli_stmt_execute($stmt);
        $reopened = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if ($reopened) {
            set_flash('success', "Report #{$report_id} reopened to pending for re-review.");
        } else {
            set_flash('error', "Report #{$report_id} could not be reopened.");
        }
        header("Location: review.php?id={$report_id}");
        exit;
    }

    if ($action === 'verify') {
        $passability = $_POST['passability'] ?? 'passable';
        if (!in_array($passability, ['passable', 'partially_passable', 'not_passable'], true)) {
            $passability = 'passable';
        }

        $stmt = mysqli_prepare(
            $conn, 
            "UPDATE reports 
             SET status = 'verified', passability = ?, reviewed_by = ?, verified_at = NOW(), rejection_reason = NULL 
             WHERE id = ? AND status = 'pending'"
        );
        mysqli_stmt_bind_param($stmt, 'sii', $passability, $admin_id, $report_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        set_flash('success', "Report #{$report_id} verified as " . str_replace('_', ' ', $passability) . ".");

    } elseif ($action === 'reject') {
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        if ($rejection_reason === '') {
            // Parity with reject.php: empty reasons re-render inline instead of defaulting.
            $reject_error = 'Please provide a reason for rejecting this report.';
        } else {
            $stmt = mysqli_prepare(
                $conn, 
                "UPDATE reports 
                 SET status = 'rejected', passability = NULL, rejection_reason = ?, reviewed_by = ?, verified_at = NULL 
                 WHERE id = ? AND status = 'pending'"
            );
            mysqli_stmt_bind_param($stmt, 'sii', $rejection_reason, $admin_id, $report_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            set_flash('warning', "Report #{$report_id} has been rejected.");
        }
    }

    // Auto-Advance Queue (Grilled Decision 3) — skipped when rejection needs a reason.
    if ($reject_error === '' && ($next_pending_res = mysqli_query($conn, "SELECT id FROM reports WHERE status = 'pending' AND id != {$report_id} ORDER BY submitted_at ASC LIMIT 1"))) {
        if ($next_pending_row = mysqli_fetch_assoc($next_pending_res)) {
            header("Location: review.php?id=" . $next_pending_row['id']);
            exit;
        } else {
            set_flash('success', 'All pending reports in the queue have been reviewed!');
            header("Location: reports.php");
            exit;
        }
    }
}

// Fetch report details
$stmt = mysqli_prepare(
    $conn, 
    "SELECT r.id, r.road_name, r.landmark, r.description, r.submitted_at, r.status, r.passability,
            r.verified_at, r.rejection_reason, b.name AS barangay_name, h.name AS hazard_name,
            u.full_name AS reporter_name, rev.full_name AS reviewer_name, rp.filename
     FROM reports r
     JOIN barangays b ON r.barangay_id = b.id
     JOIN hazard_types h ON r.hazard_type_id = h.id
     JOIN users u ON r.user_id = u.id
     LEFT JOIN users rev ON r.reviewed_by = rev.id
     LEFT JOIN report_photos rp ON r.id = rp.report_id
     WHERE r.id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $report_id);
mysqli_stmt_execute($stmt);
$report = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$report) {
    set_flash('error', "Report #{$report_id} was not found.");
    header('Location: reports.php');
    exit;
}

// Check other reports on the same road (Corridor Duplicate Context from Main-7.png)
$corridor_stmt = mysqli_prepare(
    $conn,
    "SELECT r.id, r.status, r.submitted_at, h.name AS hazard_name
     FROM reports r
     JOIN hazard_types h ON r.hazard_type_id = h.id
     WHERE r.road_name = ? AND r.id != ?
     ORDER BY r.submitted_at DESC LIMIT 3"
);
mysqli_stmt_bind_param($corridor_stmt, "si", $report['road_name'], $report_id);
mysqli_stmt_execute($corridor_stmt);
$corridor_res = mysqli_stmt_get_result($corridor_stmt);
$other_reports = [];
while ($cor = mysqli_fetch_assoc($corridor_res)) {
    $other_reports[] = $cor;
}
mysqli_stmt_close($corridor_stmt);

$pageTitle = "Review Report #RPT-{$report_id} - RoadWatch Console";
$from_history = ($_GET['from'] ?? '') === 'history';
$back_url = $from_history ? 'history.php' : 'reports.php';
$back_label = $from_history ? 'Back to Audit History' : 'Back to Reports';
$activeNav = $from_history ? 'history' : 'reports';
$is_decided = $report['status'] !== 'pending';

require_once '../includes/header.php';
?>

<div>
    <!-- Top Bar from Main-7.png -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
        <div>
            <a href="<?php echo $back_url; ?>" class="back-link-mockup" style="margin-bottom: 4px;">
                &larr; <?php echo $back_label; ?>
            </a>
            <h1 style="font-size: 1.85rem; margin: 0;">Review Report #RPT-<?php echo $report['id']; ?></h1>
            <div class="muted" style="font-size: 0.85rem; margin-top: 3px;">
                Submitted <?php echo date('M d, Y h:i A', strtotime($report['submitted_at'])); ?> &bull; 
                <?php if ($is_decided): ?>
                    <strong style="text-transform: capitalize;"><?php echo htmlspecialchars($report['status']); ?></strong> report
                <?php else: ?>
                    <strong><?php echo $queue_position; ?> of <?php echo max(1, $total_pending); ?></strong> Pending Reports
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$is_decided): ?>
        <div style="display: flex; gap: 8px;">
            <?php if ($prev_id): ?>
                <a href="review.php?id=<?php echo $prev_id; ?>" class="btn-secondary btn-sm">&larr; Previous</a>
            <?php else: ?>
                <span class="btn-secondary btn-sm" style="opacity: 0.5; pointer-events: none;">&larr; Previous</span>
            <?php endif; ?>

            <?php if ($next_id): ?>
                <a href="review.php?id=<?php echo $next_id; ?>" class="btn-secondary btn-sm">Next &rarr;</a>
            <?php else: ?>
                <span class="btn-secondary btn-sm" style="opacity: 0.5; pointer-events: none;">Next &rarr;</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Split-Screen Grid from Main-7.png -->
    <div class="review-console-grid">
        <!-- Left Column: Report Evidence -->
        <div class="evidence-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <h3 style="font-size: 1.15rem; margin: 0;">Report Evidence</h3>
                <span class="muted" style="font-size: 0.82rem; font-weight: 700;">#RPT-<?php echo $report['id']; ?></span>
            </div>

            <?php if (!empty($report['filename'])): ?>
                <img class="evidence-photo-large" src="../uploads/<?php echo htmlspecialchars($report['filename']); ?>" alt="Evidence Photo">
            <?php else: ?>
                <div style="height: 240px; background: var(--line-subtle); border: 1px dashed var(--line); border-radius: var(--radius-sm); display: grid; place-items: center; color: var(--muted-light); margin-bottom: 20px;">
                    <div>No photo attached to this report</div>
                </div>
            <?php endif; ?>

            <table class="evidence-meta-table">
                <tr>
                    <td>Hazard Type</td>
                    <td>
                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: var(--impassable-red);"></span>
                            <?php echo htmlspecialchars($report['hazard_name']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td>Location</td>
                    <td><?php echo htmlspecialchars($report['road_name']); ?> &bull; Brgy. <?php echo htmlspecialchars($report['barangay_name']); ?></td>
                </tr>
                <tr>
                    <td>Landmark</td>
                    <td><?php echo htmlspecialchars($report['landmark']); ?></td>
                </tr>
                <tr>
                    <td>Description</td>
                    <td>
                        <div style="background: var(--line-subtle); padding: 10px 14px; border-radius: var(--radius-sm); font-weight: 400; color: var(--ink);">
                            &ldquo;<?php echo nl2br(htmlspecialchars($report['description'])); ?>&rdquo;
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Submitter Details</td>
                    <td>Submitted by <?php echo htmlspecialchars($report['reporter_name']); ?></td>
                </tr>
            </table>

            <!-- Corridor Context Callout from Main-7.png -->
            <div style="background: var(--brand-navy-light); border: 1px solid var(--brand-navy-border); border-radius: var(--radius-sm); padding: 14px 16px; margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div style="font-size: 0.82rem; font-weight: 700; color: var(--brand-navy); display: flex; align-items: center; gap: 6px;">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        Other reports on this road: <?php echo count($other_reports); ?>
                    </div>
                </div>

                <?php if (empty($other_reports)): ?>
                    <span class="muted" style="font-size: 0.78rem;">No concurrent reports on this road.</span>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 6px; font-size: 0.8rem;">
                        <?php foreach ($other_reports as $or): ?>
                            <div style="display: flex; justify-content: space-between;">
                                <span><?php echo htmlspecialchars($or['hazard_name']); ?> &bull; <strong style="text-transform: capitalize;"><?php echo htmlspecialchars($or['status']); ?></strong></span>
                                <span class="muted"><?php echo date('M d, h:i A', strtotime($or['submitted_at'])); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Admin Decision Panel -->
        <div>
            <div class="decision-card-panel">
                <h3 style="font-size: 1.15rem; margin-bottom: 4px;">Admin Decision</h3>
                <?php if ($is_decided): ?>
                    <p class="muted" style="font-size: 0.88rem; margin-bottom: 14px;">This report has already been reviewed. No further action needed.</p>
                    <div style="display: flex; flex-direction: column; gap: 10px; font-size: 0.9rem;">
                        <div>
                            <strong style="display: block; font-size: 0.78rem; color: var(--muted);">Outcome</strong>
                            <span style="text-transform: capitalize; font-weight: 700; color: var(--brand-navy);"><?php echo htmlspecialchars($report['status']); ?></span><?php if (!empty($report['passability'])): ?> as <?php echo str_replace('_', ' ', htmlspecialchars($report['passability'])); ?><?php endif; ?>
                        </div>
                        <?php if (!empty($report['reviewer_name'])): ?>
                            <div>
                                <strong style="display: block; font-size: 0.78rem; color: var(--muted);">Reviewed by</strong>
                                <?php echo htmlspecialchars($report['reviewer_name']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($report['verified_at'])): ?>
                            <div>
                                <strong style="display: block; font-size: 0.78rem; color: var(--muted);">Reviewed at</strong>
                                <?php echo date('M d, Y h:i A', strtotime($report['verified_at'])); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($report['status'] === 'rejected' && !empty($report['rejection_reason'])): ?>
                            <div style="background: var(--line-subtle); padding: 10px 14px; border-radius: var(--radius-sm);">
                                <strong style="display: block; font-size: 0.78rem; color: var(--muted);">Rejection reason</strong>
                                <?php echo htmlspecialchars($report['rejection_reason']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo $back_url; ?>" class="btn-secondary btn-sm" style="width: 100%; margin-top: 16px; justify-content: center;">&larr; <?php echo $back_label; ?></a>
                    <form method="POST" style="margin-top: 10px;" data-loading="Reopening…" onsubmit="return confirm('Reopen report #<?php echo $report['id']; ?> back to pending for re-review?');">
                        <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                        <input type="hidden" name="action" value="reopen">
                        <?php csrf_input(); ?>
                        <button type="submit" class="btn-secondary btn-sm" style="width: 100%; justify-content: center;">Reopen to pending</button>
                    </form>
                <?php else: ?>
                <p class="muted" style="font-size: 0.88rem; margin-bottom: 18px;">How passable is the road?</p>

                <?php if (!empty($reject_error)): ?>
                    <div class="notice error" role="alert"><?php echo htmlspecialchars($reject_error); ?></div>
                <?php endif; ?>

                <form method="POST" id="decision-form" data-loading="Verifying report…">
                    <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                    <input type="hidden" name="action" id="decision-action" value="verify">
                    <input type="hidden" name="passability" id="selected-passability" value="not_passable">
                    <?php csrf_input(); ?>

                    <!-- Option 1: Passable -->
                    <div class="passability-radio-option" id="opt-passable" onclick="setPassability('passable', this)">
                        <input type="radio" name="_dummy" style="margin-top: 3px; cursor: pointer;">
                        <div>
                            <strong style="color: var(--passable-green); display: block; font-size: 0.92rem;">Passable</strong>
                            <span class="muted" style="font-size: 0.8rem;">Vehicles can use the road normally.</span>
                        </div>
                    </div>

                    <!-- Option 2: Partially Passable -->
                    <div class="passability-radio-option" id="opt-partial" onclick="setPassability('partially_passable', this)">
                        <input type="radio" name="_dummy" style="margin-top: 3px; cursor: pointer;">
                        <div>
                            <strong style="color: var(--partial-amber); display: block; font-size: 0.92rem;">Partially Passable</strong>
                            <span class="muted" style="font-size: 0.8rem;">Some access remains, but movement is limited.</span>
                        </div>
                    </div>

                    <!-- Option 3: Impassable (Default selected as in Main-7.png) -->
                    <div class="passability-radio-option selected-impassable" id="opt-impassable" onclick="setPassability('not_passable', this)">
                        <input type="radio" name="_dummy" checked style="margin-top: 3px; cursor: pointer;">
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong style="color: var(--impassable-red); font-size: 0.92rem;">Impassable</strong>
                                <span class="rounded-[3px] bg-alert px-1.5 py-[1px] text-[0.75rem] font-extrabold text-white">SELECTED</span>
                            </div>
                            <span class="muted text-[0.8rem]">The road currently cannot be used normally.</span>
                        </div>
                    </div>

                    <button type="submit" class="btn-navy" style="width: 100%; margin-top: 14px; padding: 12px; justify-content: center;">
                        Verify Report &rarr;
                    </button>
                    <div class="muted" style="font-size: 0.75rem; text-align: center; margin-top: 8px;">
                        Verifying publishes this condition to the public map and moves to the next pending report.
                    </div>
                </form>

                <!-- Rejection Toggle from Main-7.png -->
                <div style="border-top: 1px solid var(--line); margin-top: 24px; padding-top: 16px; text-align: center;">
                    <div id="reject-trigger"<?php echo !empty($reject_error) ? ' style="display: none;"' : ''; ?>>
                        <span class="muted" style="font-size: 0.82rem;">Cannot verify this report?</span>
                        <a href="javascript:void(0)" onclick="toggleRejectionBox()" style="color: var(--impassable-red); font-weight: 700; font-size: 0.82rem; margin-left: 4px;">
                            Reject Report &rarr;
                        </a>
                    </div>

                    <div id="rejection-box" style="display: <?php echo !empty($reject_error) ? 'block' : 'none'; ?>; text-align: left; margin-top: 12px; background: var(--line-subtle); padding: 14px; border-radius: var(--radius-sm);">
                        <label for="rejection_reason" style="font-size: 0.8rem; font-weight: 700; color: var(--impassable-red); display: block; margin-bottom: 6px;">Reason for Rejection:</label>
                        <textarea id="rejection_reason" rows="3" placeholder="e.g. Duplicate report, photo does not show hazard, or road is already clear..." style="font-size: 0.85rem; width: 100%; margin-bottom: 8px;"></textarea>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn-danger btn-sm" onclick="submitRejection()">Confirm Rejection</button>
                            <button type="button" class="btn-secondary btn-sm" onclick="toggleRejectionBox()">Cancel</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bottom Queue Status Bar from Main-7.png -->
    <?php if (!$is_decided): ?>
    <div style="margin-top: 28px; padding-top: 16px; border-top: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
        <span class="muted"><?php echo $total_pending; ?> reports remaining in queue</span>
        <div style="display: flex; align-items: center; gap: 14px;">
            <?php if ($next_id): ?>
                <a href="review.php?id=<?php echo $next_id; ?>" class="muted" style="text-decoration: underline;">Skip for now</a>
                <a href="review.php?id=<?php echo $next_id; ?>" class="btn-navy btn-sm">Next Report &rarr;</a>
            <?php else: ?>
                <a href="reports.php" class="btn-navy btn-sm">Finish Review &rarr;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function setPassability(val, el) {
    document.getElementById('selected-passability').value = val;
    document.querySelectorAll('.passability-radio-option').forEach(opt => {
        opt.className = 'passability-radio-option';
        opt.setAttribute('aria-checked', 'false');
        const radio = opt.querySelector('input[type="radio"]');
        if (radio) radio.checked = false;
        const badge = opt.querySelector('span[style*="SELECTED"]');
        if (badge) badge.remove();
    });

    const radio = el.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
    el.setAttribute('aria-checked', 'true');

    if (val === 'not_passable') {
        el.classList.add('selected-impassable');
    } else if (val === 'partially_passable') {
        el.classList.add('selected-partial');
    } else {
        el.classList.add('selected-passable');
    }
}

function toggleRejectionBox() {
    const box = document.getElementById('rejection-box');
    const trigger = document.getElementById('reject-trigger');
    if (box.style.display === 'none') {
        box.style.display = 'block';
        trigger.style.display = 'none';
    } else {
        box.style.display = 'none';
        trigger.style.display = 'block';
    }
}

function submitRejection() {
    const reason = document.getElementById('rejection_reason').value.trim();
    const box = document.getElementById('rejection-box');
    let err = document.getElementById('rejection-inline-error');
    if (!reason) {
        if (!err) {
            err = document.createElement('div');
            err.id = 'rejection-inline-error';
            err.className = 'notice error';
            err.setAttribute('role', 'alert');
            box.prepend(err);
        }
        err.textContent = 'Please provide a reason for rejecting this report.';
        document.getElementById('rejection_reason').focus();
        return;
    }
    if (err) err.remove();
    const form = document.getElementById('decision-form');
    document.getElementById('decision-action').value = 'reject';
    
    const hiddenReason = document.createElement('input');
    hiddenReason.type = 'hidden';
    hiddenReason.name = 'rejection_reason';
    hiddenReason.value = reason;
    form.appendChild(hiddenReason);
    
    form.submit();
}

document.querySelectorAll('.passability-radio-option').forEach(function(opt) {
    opt.setAttribute('role', 'radio');
    opt.setAttribute('tabindex', '0');
    opt.setAttribute('aria-checked', opt.classList.contains('selected-impassable') ? 'true' : 'false');
    opt.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            opt.click();
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
