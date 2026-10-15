<?php
$pathDepth = 0;
require_once 'includes/auth_guard.php';
require_once 'includes/db.php';

// Automated 48-Hour Sunset Transition (Grilled Decision 4)
apply_pending_report_sunset($conn);

// Filter parameters: normalized BEFORE building $where so the query,
// pager, chips, and switcher all consume the same safe values.
$barangay_id = (int) ($_GET['barangay_id'] ?? 0);
$filter_passability = $_GET['passability'] ?? 'all';
if (!in_array($filter_passability, ['all', 'impassable', 'partially_passable', 'passable'], true)) {
    $filter_passability = 'all';
}
$view_mode = $_GET['view'] ?? 'feed'; // 'feed' or 'map' (Grilled Decision 1)
if (!in_array($view_mode, ['feed', 'map'], true)) {
    $view_mode = 'feed';
}
// Unknown barangay ids fall back to All (0) before SQL: otherwise the
// query filters on a nonexistent id (empty feed) while the dropdown
// cosmetically shows All and the pager keeps propagating the bad id.
if ($barangay_id <= 0) {
    $barangay_id = 0;
} else {
    $known_ids_result = mysqli_query($conn, 'SELECT id FROM barangays');
    if ($known_ids_result) {
        $known_ids = [];
        while ($known_row = mysqli_fetch_assoc($known_ids_result)) {
            $known_ids[] = (int) $known_row['id'];
        }
        mysqli_free_result($known_ids_result);
        if (!in_array($barangay_id, $known_ids, true)) {
            $barangay_id = 0;
        }
    }
}

$where = ["reports.status = 'verified'"];
$params = [];
$types = "";

if ($barangay_id > 0) {
    $where[] = "reports.barangay_id = ?";
    $params = [$barangay_id];
    $types = "i";
}

if ($filter_passability === 'impassable') {
    $where[] = "reports.passability = 'not_passable'";
} elseif ($filter_passability === 'partially_passable') {
    $where[] = "reports.passability = 'partially_passable'";
} elseif ($filter_passability === 'passable') {
    $where[] = "reports.passability = 'passable'";
}

$where_clause = implode(" AND ", $where);

// Paginated feed + full pin set: COUNT, then matching IDs (newest first).
// The feed shows one page of reports; the map plots every match.
$page = max(1, (int) ($_GET['page'] ?? 1));

$count_sql = "SELECT COUNT(*) AS total FROM reports WHERE {$where_clause}";
$count_stmt = mysqli_prepare($conn, $count_sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$total_reports = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'] ?? 0);
mysqli_stmt_close($count_stmt);

$total_pages = max(1, (int) ceil($total_reports / ROADWATCH_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * ROADWATCH_PER_PAGE;

$id_sql = "SELECT reports.id FROM reports WHERE {$where_clause} ORDER BY COALESCE(reports.verified_at, reports.submitted_at) DESC";
$id_stmt = mysqli_prepare($conn, $id_sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($id_stmt, $types, ...$params);
}
mysqli_stmt_execute($id_stmt);
$id_result = mysqli_stmt_get_result($id_stmt);
$all_ids = [];
while ($id_row = mysqli_fetch_assoc($id_result)) {
    $all_ids[] = (int) $id_row['id'];
}
mysqli_stmt_close($id_stmt);

$page_ids = array_slice($all_ids, $offset, ROADWATCH_PER_PAGE);
$detail_ids = $view_mode === 'map' ? $all_ids : $page_ids;

$verified_reports = [];
if (!empty($detail_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($detail_ids), '?'));
    $in_types = str_repeat('i', count($detail_ids));
    $sql = "SELECT reports.id, barangays.name AS barangay_name, hazard_types.name AS hazard_name,
                   reports.road_name, reports.landmark, reports.latitude, reports.longitude,
                   reports.description, reports.status, reports.passability, reports.verified_at, reports.submitted_at,
                   report_photos.filename
            FROM reports
            JOIN barangays ON reports.barangay_id = barangays.id
            JOIN hazard_types ON reports.hazard_type_id = hazard_types.id
            LEFT JOIN report_photos ON reports.id = report_photos.report_id
            WHERE reports.id IN ({$in_placeholders})
            ORDER BY COALESCE(reports.verified_at, reports.submitted_at) DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $in_types, ...$detail_ids);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows_by_id = [];
    while ($row = mysqli_fetch_assoc($result)) {
        if (!isset($rows_by_id[$row['id']])) {
            $rows_by_id[$row['id']] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    foreach ($detail_ids as $pid) {
        if (isset($rows_by_id[$pid])) {
            $verified_reports[] = $rows_by_id[$pid];
        }
    }
}

$pager_params = ['passability' => $filter_passability, 'view' => $view_mode, 'barangay_id' => $barangay_id];

$barangays_result = mysqli_query($conn, 'SELECT id, name FROM barangays ORDER BY name ASC');
$barangay_options = [];
if ($barangays_result) {
    while ($b = mysqli_fetch_assoc($barangays_result)) {
        $barangay_options[] = $b;
    }
    mysqli_free_result($barangays_result);
}
// $barangay_id was already validated against known ids before SQL (see top);
// it is therefore normalized here too, so the dropdown below matches the query.
if ($barangay_id > 0) {
    $known_option_ids = array_map('intval', array_column($barangay_options, 'id'));
    if (!in_array($barangay_id, $known_option_ids, true)) {
        $barangay_id = 0;
    }
}

// Barangay map focus (null until migration adds latitude/longitude columns)
$barangay_focus = null;
if ($barangay_id > 0) {
    $focus_stmt = mysqli_prepare($conn, 'SELECT latitude, longitude FROM barangays WHERE id = ?');
    if ($focus_stmt) {
        mysqli_stmt_bind_param($focus_stmt, 'i', $barangay_id);
        mysqli_stmt_execute($focus_stmt);
        $focus_result = mysqli_stmt_get_result($focus_stmt);
        if ($focus_result && ($focus_row = mysqli_fetch_assoc($focus_result))) {
            if ($focus_row['latitude'] !== null && $focus_row['longitude'] !== null) {
                $barangay_focus = ['lat' => (float) $focus_row['latitude'], 'lng' => (float) $focus_row['longitude']];
            }
        }
        mysqli_stmt_close($focus_stmt);
    }
}

$pageTitle = 'Road Conditions - RoadWatch Dagupan';
$activeNav = 'home';

$extraHead = roadwatch_leaflet_head();

require_once 'includes/header.php';
?>

<div class="hero-panel">
        <h1 class="hero-title">Road Conditions</h1>
        <p class="hero-sub">
            Recently reported road hazards around Dagupan City, checked by RoadWatch admins.
        </p>

        <!-- Barangay Filter -->
        <form method="GET" action="index.php" class="barangay-filter">
            <input type="hidden" name="passability" value="<?php echo htmlspecialchars($filter_passability); ?>">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
            <div class="barangay-field">
                <label for="barangay_id">Barangay</label>
                <select id="barangay_id" name="barangay_id" onchange="this.form.submit()">
                    <option value="0" <?php echo $barangay_id === 0 ? 'selected' : ''; ?>>All barangays</option>
                    <?php foreach ($barangay_options as $b): ?>
                        <option value="<?php echo (int) $b['id']; ?>" <?php echo $barangay_id === (int) $b['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-navy">Apply</button></noscript>
            </div>
        </form>

        <!-- Filter Chips Row & Map Toggle (Grilled Decision 1) from Main.png -->
        <div class="hero-controls">
            <div class="filter-chip-row" role="group" aria-label="Filter by passability">
                <a href="?passability=all&view=<?php echo $view_mode; ?>&barangay_id=<?php echo $barangay_id; ?>" title="Show all verified reports" class="filter-chip <?php echo $filter_passability === 'all' ? 'active' : ''; ?>" aria-pressed="<?php echo $filter_passability === 'all' ? 'true' : 'false'; ?>">
                    All
                </a>
                <a href="?passability=impassable&view=<?php echo $view_mode; ?>&barangay_id=<?php echo $barangay_id; ?>" title="The road currently cannot be used normally." class="filter-chip <?php echo $filter_passability === 'impassable' ? 'active' : ''; ?>" aria-pressed="<?php echo $filter_passability === 'impassable' ? 'true' : 'false'; ?>">
                    Impassable
                </a>
                <a href="?passability=partially_passable&view=<?php echo $view_mode; ?>&barangay_id=<?php echo $barangay_id; ?>" title="Some vehicles can pass, but movement may be limited." class="filter-chip <?php echo $filter_passability === 'partially_passable' ? 'active' : ''; ?>" aria-pressed="<?php echo $filter_passability === 'partially_passable' ? 'true' : 'false'; ?>">
                    Partially passable
                </a>
                <a href="?passability=passable&view=<?php echo $view_mode; ?>&barangay_id=<?php echo $barangay_id; ?>" title="Road can be used normally without obstruction." class="filter-chip <?php echo $filter_passability === 'passable' ? 'active' : ''; ?>" aria-pressed="<?php echo $filter_passability === 'passable' ? 'true' : 'false'; ?>">
                    Passable
                </a>
            </div>

            <!-- Hybrid View Switcher (Feed View / Map View) -->
            <div class="view-switcher" role="group" aria-label="Change view">
                <a href="?view=feed&passability=<?php echo $filter_passability; ?>&barangay_id=<?php echo $barangay_id; ?>" class="status-tab-item <?php echo $view_mode === 'feed' ? 'active' : ''; ?>" <?php echo $view_mode === 'feed' ? 'aria-current="page"' : ''; ?>>
                    Feed
                </a>
                <a href="?view=map&passability=<?php echo $filter_passability; ?>&barangay_id=<?php echo $barangay_id; ?>" class="status-tab-item <?php echo $view_mode === 'map' ? 'active' : ''; ?>" <?php echo $view_mode === 'map' ? 'aria-current="page"' : ''; ?>>
                    Map
                </a>
            </div>
        </div>
    </div>

    <!-- Toggleable View Mode: MAP VIEW -->
    <?php if ($view_mode === 'map'): ?>
        <section class="panel" style="padding: 16px; margin-bottom: 24px;">
            <div id="live-map-full" style="height: 520px; border-radius: var(--radius-sm); border: 1px solid var(--line); z-index: 5;"></div>
            <div class="rw-legend" aria-label="Map pin legend">
                <span class="rw-legend-item"><span class="rw-legend-swatch is-impassable" aria-hidden="true"></span>Impassable</span>
                <span class="rw-legend-item"><span class="rw-legend-swatch is-partial" aria-hidden="true"></span>Partially passable</span>
                <span class="rw-legend-item"><span class="rw-legend-swatch is-passable" aria-hidden="true"></span>Passable</span>
            </div>
        </section>
    <?php endif; ?>

    <!-- Latest Verified Reports Feed Header from Main.png -->
    <div class="feed-header">
        <h2 class="feed-title">Latest verified reports</h2>
        <span class="feed-count"><?php echo $total_reports; ?> active</span>
    </div>

    <!-- Feed Cards List from Main.png -->
    <?php if ($total_reports === 0): ?>
        <div class="panel feed-empty">
            <h3 style="margin-bottom: 6px;">No road hazard alerts</h3>
            <p class="feed-empty-text">No active hazard restrictions in Dagupan City match this filter right now.</p>
        </div>
    <?php else: ?>
        <div class="feed-list">
            <?php foreach ($verified_reports as $r):
                [$pass_class, $pass_pill, $pass_label] = passability_display($r['passability'] ?? null);
            ?>
                <article class="feed-item-card <?php echo $pass_class; ?>">
                    <div class="feed-main">
                        <!-- Top status & age -->
                        <div class="feed-top">
                            <span class="pill <?php echo $pass_pill; ?>">
                                <?php echo $pass_label; ?>
                            </span>
                            <span class="feed-time">
                                <?php
                                $ts = strtotime($r['verified_at'] ?? $r['submitted_at']);
                                $day = date('Y-m-d', $ts);
                                if ($day === date('Y-m-d')) {
                                    $when = 'Today ' . date('h:i A', $ts);
                                } elseif ($day === date('Y-m-d', strtotime('-1 day'))) {
                                    $when = 'Yesterday ' . date('h:i A', $ts);
                                } else {
                                    $when = date('M j, Y h:i A', $ts);
                                }
                                echo 'Confirmed ' . htmlspecialchars($when);
                                ?>
                            </span>
                        </div>

                        <!-- Road Name & Barangay -->
                        <h3 class="feed-road">
                            <?php echo htmlspecialchars($r['road_name']); ?>
                        </h3>
                        <div class="feed-barangay">
                            Brgy. <?php echo htmlspecialchars($r['barangay_name']); ?>
                        </div>

                        <!-- Hazard Pill & Landmark -->
                        <div class="feed-hazard">
                            <span><?php echo htmlspecialchars($r['hazard_name']); ?></span>
                            <span aria-hidden="true"> - </span>
                            <span class="muted"><?php echo htmlspecialchars($r['landmark']); ?></span>
                        </div>

                        <!-- Description -->
                        <p class="feed-desc">
                            <?php echo htmlspecialchars($r['description']); ?>
                        </p>

                        <!-- Verification line -->
                        <div class="feed-foot">
                            <span>
                                <?php if (!empty($r['latitude']) && !empty($r['longitude'])): ?>
                                    <a href="https://www.google.com/maps?q=<?php echo $r['latitude']; ?>,<?php echo $r['longitude']; ?>" target="_blank" rel="noopener noreferrer" class="feed-gps">
                                        View GPS coordinates
                                    </a>
                                <?php else: ?>
                                    Corridor status monitored
                                <?php endif; ?>
                            </span>
                            <span class="feed-verified">
                                Verified by City Operations
                            </span>
                        </div>
                    </div>

                    <!-- Right Thumbnail if photo exists -->
                    <?php if (!empty($r['filename'])): ?>
                        <div class="feed-photo">
                            <img src="uploads/<?php echo htmlspecialchars($r['filename']); ?>" alt="Road hazard photo at <?php echo htmlspecialchars($r['road_name']); ?>" loading="lazy">
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($view_mode !== 'map') echo render_pager($total_reports, $page, $pager_params); ?>
    <?php endif; ?>

    <!-- Emergency notice: kept prominent above the about strip (Task 2 treatment preserved). -->
    <div class="mb-4 flex items-start gap-3 rounded-md border border-[#fecaca] border-l-4 border-l-alert bg-[#fef2f2] px-4 py-3">
        <svg class="shrink-0 text-alert" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
        </svg>
        <div class="emergency-text">
            <strong class="text-xs font-bold uppercase tracking-wider text-[#991b1b]">Emergency notice:</strong> For life-threatening emergencies or urgent rescue, contact Dagupan CDRRMO directly at <a href="<?php echo ROADWATCH_CDRRMO_TEL; ?>" class="inline-flex min-h-[44px] items-center font-bold underline underline-offset-2"><?php echo ROADWATCH_CDRRMO_DISPLAY; ?></a>.
        </div>
    </div>

    <!-- About strip: one line + CTA. -->
    <section class="panel" aria-label="About these reports">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="muted text-[0.88rem] mb-0">
                Resident reports, checked by admins before they appear here. Conditions change fast in rain and high tide.
            </p>
            <?php
            // Role-aware CTA: guests -> citizen sign-in, citizens -> report
            // intake, admins/super_admins -> their own consoles (the citizen
            // submit form would bounce them via its role guard).
            $cta_href = 'auth/login.php';
            if (isset($_SESSION['user_id'])) {
                $cta_role = $_SESSION['role'] ?? 'user';
                if ($cta_role === 'super_admin') {
                    $cta_href = 'superadmin/accounts.php';
                } elseif ($cta_role === 'admin') {
                    $cta_href = 'admin/reports.php';
                } else {
                    $cta_href = 'user/submit_report.php';
                }
            }
            ?>
            <a href="<?php echo htmlspecialchars($cta_href); ?>" class="btn-navy btn-sm">
                Report a road hazard
            </a>
        </div>
    </section>
</div>

<?php if ($view_mode === 'map'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const map = L.map('live-map-full').setView([16.0433, 120.3333], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    const pins = <?php echo json_encode($verified_reports, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const barangayFocus = <?php echo json_encode($barangay_focus); ?>;
    const hasBarangay = <?php echo $barangay_id > 0 ? 'true' : 'false'; ?>;
    const bounds = L.latLngBounds();
    let located = 0;

    // Shaped pins: teardrop shell + white glyph + status color.
    // rwPin(passability, hazardId) reserves hazardId for future per-hazard
    // glyphs; currently all pins use the generic alert glyph.
    const RW_PIN_GLYPH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>';

    function rwPinClass(passability) {
        if (passability === 'not_passable' || passability === 'impassable') return 'rw-pin-impassable';
        if (passability === 'partially_passable' || passability === 'partial') return 'rw-pin-partial';
        return 'rw-pin-passable';
    }

    function rwPin(passability, hazardId) {
        const cls = rwPinClass(passability);
        return L.divIcon({
            className: 'rw-div',
            html: `<div class="rw-pin ${cls}"><div class="rw-pin-body">${RW_PIN_GLYPH}</div></div>`,
            iconSize: [30, 42],
            iconAnchor: [15, 40],
            popupAnchor: [0, -36]
        });
    }

    pins.forEach(pin => {
        if (!pin.latitude || !pin.longitude) return;

        // Full literal class names: the Tailwind scanner reads source text,
        // so dynamically concatenated fragments would never generate CSS.
        let tag = 'bg-[#ecfdf5] text-[#065f46]';
        if (pin.passability === 'partially_passable') {
            tag = 'bg-[#fffbeb] text-[#92400e]';
        }
        if (pin.passability === 'not_passable') {
            tag = 'bg-[#fef2f2] text-[#991b1b]';
        }

        const customIcon = rwPin(pin.passability, pin.hazard_type_id || pin.hazard_name);

        const popupContent = `
            <div class="text-[13px] leading-[1.4]">
                <strong class="text-[14px] text-navy">${pin.hazard_name}</strong><br>
                <span class="text-mist">${pin.road_name}, ${pin.barangay_name}</span><br>
                <div class="mt-1.5">
                    <span class="inline-block rounded-full ${tag} px-2 py-[2px] text-xs font-semibold">
                        ${(pin.passability || 'verified').replace(/_/g, ' ')}
                    </span>
                </div>
                ${pin.filename ? `<img src="uploads/${pin.filename}" alt="" class="mt-2 h-[90px] w-full rounded-md object-cover">` : ''}
                <div class="mt-1.5"><a href="https://www.google.com/maps?q=${pin.latitude},${pin.longitude}" target="_blank" rel="noopener noreferrer">View GPS coordinates</a></div>
            </div>
        `;

        L.marker([parseFloat(pin.latitude), parseFloat(pin.longitude)], { icon: customIcon })
            .addTo(map)
            .bindPopup(popupContent);
        bounds.extend([parseFloat(pin.latitude), parseFloat(pin.longitude)]);
        located++;
    });
    if (hasBarangay && located > 0) {
        map.fitBounds(bounds.pad(0.2), { maxZoom: 16 });
    } else if (hasBarangay && barangayFocus) {
        map.setView([barangayFocus.lat, barangayFocus.lng], 15);
    }
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>