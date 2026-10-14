<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['admin', 'super_admin'], '../auth/admin_login.php');

$report_id = (int) ($_POST["id"] ?? $_GET["id"] ?? 0);
if ($report_id <= 0) {
    set_flash('error', 'Report ID is missing or invalid.');
    header('Location: reports.php');
    exit;
}

// Fetch report details for context
$stmt = mysqli_prepare($conn, "SELECT r.id, r.road_name, b.name AS barangay_name, h.name AS hazard_name 
                               FROM reports r 
                               JOIN barangays b ON r.barangay_id = b.id 
                               JOIN hazard_types h ON r.hazard_type_id = h.id 
                               WHERE r.id = ? AND r.status = 'pending'");
mysqli_stmt_bind_param($stmt, "i", $report_id);
mysqli_stmt_execute($stmt);
$report = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$report) {
    set_flash('error', "Report #{$report_id} not found or already reviewed.");
    header('Location: reports.php');
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        $error = "Invalid or expired security token. Please try again.";
    } else {
        $rejection_reason = trim($_POST["rejection_reason"] ?? '');
        if ($rejection_reason === '') {
            $error = "Please provide a reason for rejecting this report.";
        } else {
            $admin_id = $currentUser['id'];
            $status = "rejected";

            $sql = "UPDATE reports
                    SET status = ?,
                        passability = NULL,
                        rejection_reason = ?,
                        reviewed_by = ?,
                        verified_at = NULL
                    WHERE id = ? AND status = 'pending'";

            $updateStmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param(
                $updateStmt,
                "ssii",
                $status,
                $rejection_reason,
                $admin_id,
                $report_id
            );

            if (mysqli_stmt_execute($updateStmt) && mysqli_stmt_affected_rows($updateStmt) > 0) {
                mysqli_stmt_close($updateStmt);
                set_flash('warning', "Report #{$report_id} was rejected.");
                header('Location: reports.php');
                exit;
            } else {
                $error = "Report could not be rejected or was already updated.";
            }
            mysqli_stmt_close($updateStmt);
        }
    }
}
$pageTitle = "Reject Report #{$report_id} - RoadWatch";
$activeNav = 'reports';

require_once '../includes/header.php';
?>

<div class="narrow">
    <section class="panel">
        <a class="back-link-mockup" href="reports.php">&larr; Back to pending reports</a>
        <h1 class="panel-title">Reject road report #<?php echo $report_id; ?></h1>
        <p class="panel-desc">
            Hazard: <strong><?php echo htmlspecialchars($report['hazard_name']); ?></strong> on
            <strong><?php echo htmlspecialchars($report['road_name']); ?></strong>
            (<?php echo htmlspecialchars($report['barangay_name']); ?>)
        </p>

        <?php if ($error): ?>
            <div class="notice error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" data-loading="Rejecting report…">
            <input type="hidden" name="id" value="<?php echo $report_id; ?>">
            <?php csrf_input(); ?>

            <div class="form-group">
                <label for="rejection_reason">Reason for Rejection (Visible to Citizen):</label>
                <textarea
                    id="rejection_reason"
                    name="rejection_reason"
                    rows="4"
                    placeholder="e.g. Duplicate report, photo does not show road hazard, or road already repaired."
                    required><?php echo htmlspecialchars($_POST['rejection_reason'] ?? ''); ?></textarea>
            </div>

            <div class="actions" style="margin-top: 16px;">
                <button class="btn-danger" type="submit">Confirm Rejection</button>
                <a class="btn-secondary" href="reports.php">Cancel</a>
            </div>
        </form>
    </section>
</div>

<?php require_once '../includes/footer.php'; ?>