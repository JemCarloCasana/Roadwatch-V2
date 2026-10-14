<?php
require_once '../includes/auth_guard.php';
require_once '../includes/db.php';

$currentUser = require_role(['user'], '../auth/login.php');

$error = '';
$field_errors = [];
$barangay_id = (int) ($_POST['barangay_id'] ?? 0);
$hazard_type_id = (int) ($_POST['hazard_type_id'] ?? 0);
$road_name = trim($_POST['road_name'] ?? '');
$landmark = trim($_POST['landmark'] ?? '');
$location_details = trim($_POST['location_details'] ?? '');
$latitude = !empty($_POST['latitude']) ? (float) $_POST['latitude'] : null;
$longitude = !empty($_POST['longitude']) ? (float) $_POST['longitude'] : null;
$description = trim($_POST['description'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Security session expired. Please submit the report again.';
    } else {
        $field_errors = [];
        if (empty($road_name)) {
            $field_errors['road_name'] = 'Enter the road name or nearby landmark.';
        }
        if ($barangay_id <= 0) {
            $field_errors['barangay_id'] = 'Select the barangay where the hazard is.';
        }
        if ($hazard_type_id <= 0) {
            $field_errors['hazard'] = 'Pick the situation that best describes the road issue.';
        }
        if (empty($description)) {
            $field_errors['description'] = 'Describe what vehicles can or cannot pass.';
        }
        if (!empty($field_errors)) {
            $error = 'Some fields need attention before this report can be submitted.';
        } else {
            $photo_valid = true;
            $temporary_file = '';
            $extension = '';

            // Photo is now optional (as specified in Main-3.png)
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $temporary_file = $_FILES['photo']['tmp_name'];
                $extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $photo_valid = getimagesize($temporary_file) !== false &&
                               in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) &&
                               $_FILES['photo']['size'] <= 5 * 1024 * 1024;
                if (!$photo_valid) {
                    $field_errors['photo'] = 'Photo must be a valid JPG, PNG, or WebP image under 5 MB.';
                    $error = 'Some fields need attention before this report can be submitted.';
                }
            }

            if ($photo_valid && empty($error)) {
            $user_id = $currentUser['id'];
            $full_landmark = $landmark;
            if (!empty($location_details)) {
                $full_landmark = $full_landmark ? "{$full_landmark} ({$location_details})" : $location_details;
            }

            $stmt = mysqli_prepare(
                $conn, 
                "INSERT INTO reports (user_id, barangay_id, hazard_type_id, road_name, landmark, latitude, longitude, description, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            mysqli_stmt_bind_param(
                $stmt, 
                'iiissdds', 
                $user_id, 
                $barangay_id, 
                $hazard_type_id, 
                $road_name, 
                $full_landmark, 
                $latitude, 
                $longitude, 
                $description
            );

            if (mysqli_stmt_execute($stmt)) {
                $report_id = mysqli_insert_id($conn);
                
                // If photo was uploaded, save it
                if (!empty($temporary_file) && !empty($extension)) {
                    $new_filename = uniqid('road_', true) . '.' . $extension;
                    $target_dir = __DIR__ . '/../uploads/';
                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0755, true);
                    }
                    if (move_uploaded_file($temporary_file, $target_dir . $new_filename)) {
                        $photo_stmt = mysqli_prepare($conn, 'INSERT INTO report_photos (report_id, filename) VALUES (?, ?)');
                        mysqli_stmt_bind_param($photo_stmt, 'is', $report_id, $new_filename);
                        mysqli_stmt_execute($photo_stmt);
                        mysqli_stmt_close($photo_stmt);
                    }
                }

                set_flash('success', "Report #{$report_id} submitted for review! An Admin will inspect and verify passability.");
                header('Location: my_reports.php');
                exit;
            } else {
                $error = 'Failed to submit report. Please try again.';
            }
            mysqli_stmt_close($stmt);
            }
        }
    }
}

$barangays = mysqli_query($conn, 'SELECT id, name FROM barangays ORDER BY name ASC');

$pageTitle = 'Report a Road Hazard - RoadWatch Dagupan';
$activeNav = 'submit_report';

$extraHead = roadwatch_leaflet_head();

require_once '../includes/header.php';
?>

<div class="semi-narrow">
    <div style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <a href="../index.php" class="back-link-mockup" style="margin-bottom: 0;">
                &larr; Back to Road Conditions
            </a>
        </div>
        <h1 style="font-size: 1.85rem; margin-bottom: 6px;">Report a Road Hazard</h1>
        <p class="muted" style="font-size: 0.92rem;">
            Saw flooding, an obstruction, or another road problem? Share what you currently see. Your report will be reviewed before it appears publicly.
        </p>
    </div>

    <?php if ($error): ?>
        <div class="notice error" role="alert" id="form-error-summary" tabindex="-1">
            <div>
                <strong><?php echo htmlspecialchars($error); ?></strong>
                <?php if (!empty($field_errors)): ?>
                    <ul style="margin: 6px 0 0 18px; padding: 0;">
                        <?php foreach ($field_errors as $fid => $msg): ?>
                            <li><a href="#field-<?php echo $fid; ?>"><?php echo htmlspecialchars($msg); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel" style="padding: clamp(24px, 4vw, 36px);">
        <form method="POST" enctype="multipart/form-data" id="intake-form" data-loading="Submitting report…">
            <?php csrf_input(); ?>

            <!-- Section: location -->
            <div class="intake-step">
                <div class="step-header-row">
                    <h2 class="step-title">Where is it?</h2>
                    <div class="step-icon-btn">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                </div>
                <p class="muted" style="font-size: 0.86rem; margin-bottom: 16px;">
                    Tell us the road and barangay in Dagupan City so others know where to look.
                </p>

                <div class="form-group" id="field-road_name" style="margin-bottom: 14px;">
                    <label for="road_name">Road name or landmark <span style="color: var(--impassable-red);">* Required</span></label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <span style="position: absolute; left: 12px; color: var(--muted-light); font-weight: 700;">A</span>
                        <input id="road_name" type="text" name="road_name" required placeholder="e.g. Perez Boulevard, near CSI Mall" value="<?php echo htmlspecialchars($road_name); ?>" style="padding-left: 32px !important;"<?php echo isset($field_errors['road_name']) ? ' aria-invalid="true" aria-describedby="err-road_name"' : ''; ?>>
                    </div>
                    <?php if (isset($field_errors['road_name'])): ?>
                        <p class="field-error" id="err-road_name" role="alert"><?php echo htmlspecialchars($field_errors['road_name']); ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group" id="field-barangay_id" style="margin-bottom: 14px;">
                    <label for="barangay_id">Barangay in Dagupan <span style="color: var(--impassable-red);">* Required</span></label>
                    <select id="barangay_id" name="barangay_id" required<?php echo isset($field_errors['barangay_id']) ? ' aria-invalid="true" aria-describedby="err-barangay_id"' : ''; ?>>
                        <option value="">Select barangay...</option>
                        <?php while ($b = mysqli_fetch_assoc($barangays)): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $barangay_id === (int)$b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <?php if (isset($field_errors['barangay_id'])): ?>
                        <p class="field-error" id="err-barangay_id" role="alert"><?php echo htmlspecialchars($field_errors['barangay_id']); ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="location_details">Specific location details <span class="muted" style="font-weight: 400;">(Optional)</span></label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="position: absolute; left: 12px; color: var(--muted-light);">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                        </svg>
                        <input id="location_details" type="text" name="location_details" placeholder="e.g. in front of Mercury Drug, eastbound lane" value="<?php echo htmlspecialchars($location_details); ?>" style="padding-left: 36px !important;">
                    </div>
                </div>

                <!-- GPS Map Pin (always visible so reports carry coordinates) -->
                <div style="margin-top: 10px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span style="font-size: 0.85rem; font-weight: 600; color: var(--ink-secondary);">
                            Pin the exact location on the map <span class="muted" style="font-weight: 400;">(Optional)</span>
                        </span>
                        <span id="coords-text" style="font-size: 0.78rem; color: var(--ink-secondary);">
                            <?php echo (!empty($latitude) && !empty($longitude)) ? "{$latitude}, {$longitude}" : "No pin selected"; ?>
                        </span>
                    </div>

                    <div id="map-wrapper" style="margin-top: 12px;">
                        <div id="map-picker" style="height: 220px; border-radius: var(--radius-sm); border: 1px solid var(--line);"></div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                            <span class="muted" style="font-size: 0.75rem;">Click anywhere or drag marker to set exact location.</span>
                            <button type="button" class="btn-secondary btn-sm" id="btn-locate-me" style="padding: 2px 8px; font-size: 0.75rem;">Use My Location</button>
                        </div>
                        <p class="field-error" id="map-error" role="alert" hidden></p>
                    </div>
                    <input type="hidden" id="latitude" name="latitude" value="<?php echo htmlspecialchars((string)($latitude ?? '')); ?>">
                    <input type="hidden" id="longitude" name="longitude" value="<?php echo htmlspecialchars((string)($longitude ?? '')); ?>">
                </div>
            </div>

            <!-- Section: hazard -->
            <div class="intake-step">
                <div class="step-header-row">
                    <h2 class="step-title">What happened?</h2>
                    <div class="step-icon-btn">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                </div>
                <p class="muted" style="font-size: 0.86rem; margin-bottom: 14px;">
                    Pick the situation that best describes the road issue.
                </p>

                <label style="display: block; margin-bottom: 8px;">Hazard Category <span style="color: var(--impassable-red);">* Required</span></label>
                <input type="hidden" id="hazard_type_id" name="hazard_type_id" value="<?php echo $hazard_type_id; ?>">

                <div class="category-selector-grid" id="field-hazard" role="radiogroup" aria-label="Hazard category"<?php echo isset($field_errors['hazard']) ? ' aria-describedby="err-hazard"' : ''; ?>>
                    <!-- 1. Flooding -->
                    <div class="category-tile <?php echo $hazard_type_id === 2 ? 'active' : ''; ?>" onclick="selectHazard(2, this)">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                        </svg>
                        <span>Flooding</span>
                    </div>

                    <!-- 2. Accident -->
                    <div class="category-tile <?php echo $hazard_type_id === 7 ? 'active' : ''; ?>" onclick="selectHazard(7, this)">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Accident</span>
                    </div>

                    <!-- 3. Fallen Tree -->
                    <div class="category-tile <?php echo $hazard_type_id === 3 ? 'active' : ''; ?>" onclick="selectHazard(3, this)">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                        </svg>
                        <span>Fallen Tree</span>
                    </div>

                    <!-- 4. Road Works -->
                    <div class="category-tile <?php echo $hazard_type_id === 5 ? 'active' : ''; ?>" onclick="selectHazard(5, this)">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                        <span>Road Works</span>
                    </div>

                    <!-- 5. Stalled Vehicle -->
                    <div class="category-tile <?php echo $hazard_type_id === 1 ? 'active' : ''; ?>" onclick="selectHazard(1, this)">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                        </svg>
                        <span>Stalled Vehicle</span>
                    </div>

                    <!-- 6. Other Hazard -->
                    <div class="category-tile <?php echo $hazard_type_id === 8 ? 'active' : ''; ?>" onclick="selectHazard(8, this)">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Other Hazard</span>
                    </div>
                </div>

                <p class="field-error" id="err-hazard" role="alert"<?php echo isset($field_errors['hazard']) ? '' : ' hidden'; ?>><?php echo isset($field_errors['hazard']) ? htmlspecialchars($field_errors['hazard']) : ''; ?></p>

                <div class="form-group" id="field-description">
                    <label for="description">Brief description <span style="color: var(--impassable-red);">* Required</span></label>
                    <textarea id="description" name="description" rows="3" maxlength="250" required placeholder="e.g. Floodwater is ankle-deep and rising quickly. Tricycles are turning around." oninput="updateCharCount(this)"<?php echo isset($field_errors['description']) ? ' aria-invalid="true" aria-describedby="err-description"' : ''; ?>><?php echo htmlspecialchars($description); ?></textarea>
                    <?php if (isset($field_errors['description'])): ?>
                        <p class="field-error" id="err-description" role="alert"><?php echo htmlspecialchars($field_errors['description']); ?></p>
                    <?php endif; ?>
                    <div class="char-counter">
                        <span>Keep it simple. Describe what vehicles can or cannot pass.</span>
                        <span id="char-count">0 / 250</span>
                    </div>
                </div>
            </div>

            <!-- Section: photo -->
            <div class="intake-step">
                <div class="step-header-row">
                    <h2 class="step-title">Add a photo</h2>
                    <div class="step-icon-btn">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                </div>
                <p class="muted" style="font-size: 0.86rem; margin-bottom: 14px;">
                    A photo can help the Admin understand the road condition.
                </p>

                <!-- Mockup Photo Upload Tile -->
                <div id="field-photo" style="background: var(--line-subtle); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 20px; display: flex; align-items: center; gap: 20px;">
                    <div id="photo-placeholder-box" style="width: 80px; height: 80px; border: 1px dashed #cbd5e1; border-radius: var(--radius-sm); display: grid; place-items: center; background: #fff; flex-shrink: 0; color: var(--muted-light); font-size: 0.75rem; text-align: center; font-weight: 700;">
                        <div>
                            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin: 0 auto 2px;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            NO FILE
                        </div>
                    </div>
                    <img id="photo-preview-box" src="" alt="Photo Preview" style="display: none; width: 80px; height: 80px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--line); flex-shrink: 0;">

                    <div style="flex: 1;">
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="previewSelectedPhoto(this)">
                        <button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('photo').click();" style="display: inline-flex; align-items: center; gap: 6px;">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Choose Photo
                        </button>
                        <div class="muted" style="font-size: 0.76rem; margin-top: 6px;">Optional: JPG, PNG, or WebP. Maximum 5 MB.</div>
                        <div style="display: flex; align-items: center; gap: 4px; font-size: 0.76rem; color: var(--ink-secondary); margin-top: 4px;">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--passable-green);">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                            Photos are not required, but they help Admins verify and share your report faster.
                        </div>
                    </div>
                </div>
                <?php if (isset($field_errors['photo'])): ?>
                    <p class="field-error" id="err-photo" role="alert"><?php echo htmlspecialchars($field_errors['photo']); ?></p>
                <?php endif; ?>

                <!-- Flood Guideline Alert Box from Mockup -->
                <div style="background: var(--brand-navy-light); border: 1px solid var(--brand-navy-border); border-radius: var(--radius-sm); padding: 14px 16px; margin-top: 16px;">
                    <div style="font-size: 0.82rem; color: var(--ink-secondary);">
                        <strong style="color: var(--brand-navy); display: block; margin-bottom: 2px;">Dagupan Flood Reporting Guideline</strong>
                        When water levels are associated with high tide in the Pantal River or Lingayen Gulf, note whether the water is stagnant or visibly draining.
                    </div>
                </div>
            </div>

            <!-- Submit CTA Bar -->
            <div style="padding-top: 14px;">
                <button type="submit" class="btn-navy" style="width: 100%; padding: 13px; font-size: 0.98rem; justify-content: center;">
                    Submit Report for Review &rarr;
                </button>
                <div style="display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.78rem; color: var(--muted); margin-top: 10px; text-align: center;">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    Your submission will be checked by a RoadWatch Admin before appearing on the public map and list.
                </div>
            </div>
        </form>
    </section>

    <!-- Bottom Verification & Emergency Banners from Mockup -->
    <div class="banner-grid">
        <div style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 16px; display: flex; gap: 12px; align-items: flex-start;">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--brand-navy); flex-shrink: 0; margin-top: 2px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            <div style="font-size: 0.8rem; color: var(--ink-secondary);">
                <strong style="color: var(--brand-navy); display: block; margin-bottom: 2px;">Verified Community Data</strong>
                Admin review filters false alarms before alerts go public.
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 16px; display: flex; gap: 12px; align-items: flex-start;">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--impassable-red); flex-shrink: 0; margin-top: 2px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            <div style="font-size: 0.8rem; color: var(--ink-secondary);">
                <strong style="color: var(--brand-navy); display: block; margin-bottom: 2px;">Life-Threatening Emergency?</strong>
                For critical rescue or urgent vehicle stranding, call Dagupan CDRRMO at <strong style="color: var(--brand-navy);"><?php echo ROADWATCH_CDRRMO_DISPLAY; ?></strong> immediately.
            </div>
        </div>
    </div>
</div>

<script>
function selectHazard(id, el) {
    document.querySelectorAll('.category-tile').forEach(t => {
        t.classList.remove('active');
        t.setAttribute('aria-checked', 'false');
    });
    el.classList.add('active');
    el.setAttribute('aria-checked', 'true');
    document.getElementById('hazard_type_id').value = id;
    var hazardErr = document.getElementById('err-hazard');
    if (hazardErr) {
        hazardErr.textContent = '';
        hazardErr.hidden = true;
    }
}

function updateCharCount(el) {
    document.getElementById('char-count').textContent = `${el.value.length} / 250`;
}

function previewSelectedPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('photo-preview-box').src = e.target.result;
            document.getElementById('photo-preview-box').style.display = 'block';
            document.getElementById('photo-placeholder-box').style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Leaflet Pin Picker Setup (map is always visible)
let mapInstance = null;
let markerInstance = null;

function initMap() {
    if (mapInstance) return;
    const defaultLat = <?php echo !empty($latitude) ? (float)$latitude : 16.0433; ?>;
    const defaultLng = <?php echo !empty($longitude) ? (float)$longitude : 120.3333; ?>;
    
    mapInstance = L.map('map-picker').setView([defaultLat, defaultLng], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(mapInstance);

    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const coordsText = document.getElementById('coords-text');

    function setPin(lat, lng) {
        latInput.value = lat.toFixed(6);
        lngInput.value = lng.toFixed(6);
        coordsText.textContent = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
        if (markerInstance) {
            markerInstance.setLatLng([lat, lng]);
        } else {
            markerInstance = L.marker([lat, lng], { draggable: true }).addTo(mapInstance);
            markerInstance.on('dragend', function(e) {
                const pos = e.target.getLatLng();
                setPin(pos.lat, pos.lng);
            });
        }
    }

    <?php if (!empty($latitude) && !empty($longitude)): ?>
        setPin(defaultLat, defaultLng);
    <?php endif; ?>

    mapInstance.on('click', function(e) {
        setPin(e.latlng.lat, e.latlng.lng);
    });

    document.getElementById('btn-locate-me').addEventListener('click', function() {
        var mapError = document.getElementById('map-error');
        function showMapError(msg) {
            mapError.textContent = msg;
            mapError.hidden = false;
        }
        if (!navigator.geolocation) {
            showMapError('Geolocation is not supported by this browser. Pin the location manually on the map.');
            return;
        }
        mapError.hidden = true;
        navigator.geolocation.getCurrentPosition(pos => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            mapInstance.setView([lat, lng], 16);
            setPin(lat, lng);
        }, err => {
            showMapError('Could not get your location (' + err.message + '). Pin the location manually on the map.');
        });
    });
}

// Client-side hazard check: the category tiles are not native required inputs,
// so validate before submit and point at the radiogroup (server re-checks too).
document.getElementById('intake-form').addEventListener('submit', function(e) {
    var hazardInput = document.getElementById('hazard_type_id');
    if (!hazardInput.value || parseInt(hazardInput.value, 10) <= 0) {
        e.preventDefault();
        var grid = document.getElementById('field-hazard');
        var err = document.getElementById('err-hazard');
        err.textContent = 'Pick the situation that best describes the road issue.';
        err.hidden = false;
        grid.setAttribute('aria-describedby', 'err-hazard');
        var firstTile = grid.querySelector('.category-tile');
        if (firstTile) firstTile.focus();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var summary = document.getElementById('form-error-summary');
    if (summary) summary.focus();
    updateCharCount(document.getElementById('description'));
    document.querySelectorAll('.category-tile').forEach(function(t) {
        t.setAttribute('role', 'radio');
        t.setAttribute('tabindex', '0');
        if (!t.classList.contains('active')) t.setAttribute('aria-checked', 'false');
        else t.setAttribute('aria-checked', 'true');
        t.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                t.click();
            }
        });
    });
    initMap();
});
</script>

<?php require_once '../includes/footer.php'; ?>
