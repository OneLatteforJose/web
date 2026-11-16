<?php
// Provider applications: a provider signs up (register.php?as=provider) and the account waits, switched off, until the
// manager of their city or an admin approves it. Shared by admin/applications.php and manager/applications.php.
//
// Storage: provider_profiles.application_status is 'pending', 'approved' or 'rejected' (the providers an admin creates
// are simply 'approved'). While an application is pending or rejected, users.is_active stays 0, so every existing
// "active provider" query ignores the applicant without any change. Approving switches the account on.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/areas.php';
require_once __DIR__ . '/site.php';   // service_categories(), used when listing proposed services

// Pending applications, optionally only for one city ("Province|City" key). Never breaks a page: 0 if the table is not upgraded yet.
function pending_applications(PDO $pdo, ?string $cityKey = null): int {
    try {
        if (parse_city_key($cityKey)) {
            [$cond, $cp] = city_filter($cityKey, 'a');
            $st = $pdo->prepare("SELECT COUNT(*) FROM provider_profiles pp JOIN users u ON u.id = pp.user_id JOIN areas a ON a.id = u.area_id
                                 WHERE pp.application_status = 'pending'$cond");
            $st->execute($cp);
            return (int)$st->fetchColumn();
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM provider_profiles WHERE application_status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// One page of applications. $o: status (pending|approved|rejected|all), city (scope key), q (search), page_key.
// "approved" and "all" only include applications somebody actually reviewed, not providers an admin created directly.
function applications_page(PDO $pdo, array $o): array {
    $status = $o['status'] ?? 'pending';
    [$cond, $params] = city_filter($o['city'] ?? '', 'a');
    $where = " WHERE u.role = 'provider'$cond";
    if ($status === 'pending' || $status === 'rejected') {
        $where .= " AND pp.application_status = '$status'";
    } elseif ($status === 'approved') {
        $where .= " AND pp.application_status = 'approved' AND pp.reviewed_at IS NOT NULL";
    } else {
        $where .= " AND (pp.application_status <> 'approved' OR pp.reviewed_at IS NOT NULL)";
    }
    $q = trim($o['q'] ?? '');
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR pp.business_name LIKE ?)";
        array_push($params, $like, $like, $like);
    }
    $base = "FROM provider_profiles pp JOIN users u ON u.id = pp.user_id JOIN areas a ON a.id = u.area_id LEFT JOIN users rv ON rv.id = pp.reviewed_by";
    $cnt = $pdo->prepare("SELECT COUNT(*) $base$where");
    $cnt->execute($params);
    $pg = paginate((int)$cnt->fetchColumn(), $o['per'] ?? 20);
    $order = $status === 'pending' ? 'u.id ASC' : 'COALESCE(pp.reviewed_at, u.created_at) DESC, u.id DESC';   // oldest first while waiting
    $st = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email, u.phone, u.street, u.created_at, pp.business_name, pp.about, pp.application_status AS status,
                pp.review_note, pp.reviewed_at, rv.full_name AS reviewer, a.province, a.city_municipality AS city, a.barangay
         $base$where ORDER BY $order LIMIT {$pg['per']} OFFSET {$pg['offset']}"
    );
    $st->execute($params);
    return [$st->fetchAll(), $pg];
}

// Approve or reject one pending application. $cityKey limits a manager to their own city (null = admin, any city).
// Returns '' on success, otherwise the message to show. Safe if two reviewers click at the same moment: the row is locked
// and only a still-pending application can be decided, so the second click is told it was already reviewed.
function review_application(PDO $pdo, int $userId, string $decision, int $reviewerId, string $note, ?string $cityKey): string {
    $note = trim($note);
    $len = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($decision !== 'approve' && $decision !== 'reject') {
        return 'Unknown action.';
    }
    if ($decision === 'reject' && $len < 3) {
        return 'Give a short reason for rejecting, so the applicant knows why.';
    }
    if ($len > 255) {
        return 'The note is too long (up to 255 characters).';
    }
    [$cond, $cp] = city_filter($cityKey, 'a');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT u.full_name, pp.application_status FROM users u
             JOIN provider_profiles pp ON pp.user_id = u.id JOIN areas a ON a.id = u.area_id
             WHERE u.id = ? AND u.role = 'provider'$cond FOR UPDATE"
        );
        $st->execute(array_merge([$userId], $cp));
        $row = $st->fetch();
        if (!$row || $row['application_status'] !== 'pending') {
            $pdo->rollBack();
            return 'That application was not found, or someone has already reviewed it.';
        }
        if ($decision === 'approve') {
            $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$userId]);
        }
        $pdo->prepare("UPDATE provider_profiles SET application_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE user_id = ?")
            ->execute([$decision === 'approve' ? 'approved' : 'rejected', $reviewerId, $note !== '' ? $note : null, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Could not save the decision. Please try again.';
    }
    log_action($decision === 'approve' ? 'application.approve' : 'application.reject', 'user', $userId,
               ($decision === 'approve' ? 'Approved' : 'Rejected') . ' provider application: ' . $row['full_name'] . ($note !== '' ? ' (' . $note . ')' : ''));
    return '';
}

// Reads the approve/reject form posted by an applications page and applies it. Returns [ok, message].
function handle_application_post(PDO $pdo, int $reviewerId, ?string $cityKey): array {
    csrf_check();
    $decision = $_POST['action'] ?? '';
    $err = review_application($pdo, (int)($_POST['id'] ?? 0), $decision, $reviewerId, (string)($_POST['note'] ?? ''), $cityKey);
    if ($err !== '') {
        return [false, $err];
    }
    return [true, $decision === 'approve' ? 'Application approved. The provider account is now active.' : 'Application rejected.'];
}

// One panel per applicant, with the approve / reject forms for the ones still waiting.
function render_applications(array $rows, bool $showCity): void {
    if (!$rows) {
        echo '<p class="muted">Nothing to show.</p>';
        return;
    }
    $dt = fn($d) => $d ? date('M j, Y g:i A', strtotime($d)) : '-';
    foreach ($rows as $r) {
        $place = implode(', ', array_filter([$r['street'], $r['barangay'], $showCity ? $r['city'] . ', ' . $r['province'] : '']));
        echo '<section class="panel"><div class="cityhead"><h3>' . e($r['full_name']) . ($r['business_name'] ? ' <span class="muted" style="font-weight:400">- ' . e($r['business_name']) . '</span>' : '') . '</h3>'
           . '<span class="badge badge-' . e($r['status']) . '">' . e(ucfirst($r['status'])) . '</span></div>';
        echo '<dl class="kv" style="border:0;padding:0;margin:.4rem 0 1rem">'
           . '<dt>Applied</dt><dd>' . e($dt($r['created_at'])) . '</dd>'
           . '<dt>Email</dt><dd>' . e($r['email']) . '</dd>'
           . '<dt>Phone</dt><dd>' . e($r['phone'] ?: '-') . '</dd>'
           . '<dt>Address</dt><dd>' . e($place) . '</dd>'
           . '<dt>Will offer</dt><dd>' . nl2br(e($r['about'] ?: '-')) . '</dd>';
        if ($r['status'] !== 'pending') {
            echo '<dt>' . ($r['status'] === 'approved' ? 'Approved' : 'Rejected') . '</dt><dd>' . e($r['reviewer'] ?: 'someone who has since been removed') . ', ' . e($dt($r['reviewed_at']))
               . ($r['review_note'] ? '<br><span class="muted">' . e($r['review_note']) . '</span>' : '') . '</dd>';
        }
        echo '</dl>';
        if ($r['status'] === 'pending') {
            echo '<div class="actions">'
               . '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="action" value="approve">'
               . '<label>Note (optional) <input name="note" maxlength="255"></label><button>Approve</button></form>'
               . '<form method="post" onsubmit="return confirm(\'Reject this application?\')">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="action" value="reject">'
               . '<label>Reason (shown to the applicant) <input name="note" maxlength="255" required></label><button class="danger">Reject</button></form>'
               . '</div>';
        }
        echo '</section>';
    }
}

// ============================================================================================================
// Service submissions: a provider proposes a new service (provider/services.php). It lives in service_submissions
// and becomes a real row in `services` only when the manager of the city or an admin approves it, so a waiting or
// rejected proposal can never show up for customers: there is nothing in `services` to filter out.
// ============================================================================================================

// Submissions waiting for a decision, optionally only for one city. Never breaks a page: 0 if the table is not created yet.
function pending_service_submissions(PDO $pdo, ?string $cityKey = null): int {
    try {
        if (parse_city_key($cityKey)) {
            [$cond, $cp] = city_filter($cityKey, 'a');
            $st = $pdo->prepare("SELECT COUNT(*) FROM service_submissions ss JOIN areas a ON a.id = ss.area_id WHERE ss.status = 'pending'$cond");
            $st->execute($cp);
            return (int)$st->fetchColumn();
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM service_submissions WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// One page of submissions. $o: status (pending|approved|rejected|all), city (scope key), q (search title / provider / business).
function service_submissions_page(PDO $pdo, array $o): array {
    $status = $o['status'] ?? 'pending';
    [$cond, $params] = city_filter($o['city'] ?? '', 'a');
    $where = " WHERE 1 = 1$cond";
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $where .= " AND ss.status = '$status'";
    }
    $q = trim($o['q'] ?? '');
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where .= " AND (ss.title LIKE ? OR u.full_name LIKE ? OR pp.business_name LIKE ?)";
        array_push($params, $like, $like, $like);
    }
    $base = "FROM service_submissions ss JOIN users u ON u.id = ss.provider_id JOIN areas a ON a.id = ss.area_id
             LEFT JOIN provider_profiles pp ON pp.user_id = u.id LEFT JOIN users rv ON rv.id = ss.reviewed_by";
    $cnt = $pdo->prepare("SELECT COUNT(*) $base$where");
    $cnt->execute($params);
    $pg = paginate((int)$cnt->fetchColumn(), $o['per'] ?? 20);
    $order = $status === 'pending' ? 'ss.id ASC' : 'COALESCE(ss.reviewed_at, ss.created_at) DESC, ss.id DESC';   // oldest first while waiting
    $st = $pdo->prepare(
        "SELECT ss.id, ss.category, ss.title, ss.description, ss.rate, ss.status, ss.review_note, ss.reviewed_at, ss.service_id, ss.created_at,
                u.full_name AS provider, pp.business_name, rv.full_name AS reviewer, a.province, a.city_municipality AS city, a.barangay
         $base$where ORDER BY $order LIMIT {$pg['per']} OFFSET {$pg['offset']}"
    );
    $st->execute($params);
    return [$st->fetchAll(), $pg];
}

// Approve (creates the live service) or reject one pending submission. $cityKey limits a manager to their own city
// (null = admin, any city). Returns '' on success, otherwise the message to show. The submission row is locked and only a
// still-pending one can be decided, so two reviewers clicking together cannot create the service twice.
function review_service_submission(PDO $pdo, int $id, string $decision, int $reviewerId, string $note, ?string $cityKey): string {
    $note = trim($note);
    $len = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($decision !== 'approve' && $decision !== 'reject') {
        return 'Unknown action.';
    }
    if ($decision === 'reject' && $len < 3) {
        return 'Give a short reason for rejecting, so the provider knows what to change.';
    }
    if ($len > 255) {
        return 'The note is too long (up to 255 characters).';
    }
    [$cond, $cp] = city_filter($cityKey, 'a');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT ss.*, u.is_active, COALESCE(pp.application_status, 'approved') AS app_status
             FROM service_submissions ss JOIN users u ON u.id = ss.provider_id JOIN areas a ON a.id = ss.area_id
             LEFT JOIN provider_profiles pp ON pp.user_id = u.id
             WHERE ss.id = ?$cond FOR UPDATE"
        );
        $st->execute(array_merge([$id], $cp));
        $row = $st->fetch();
        if (!$row || $row['status'] !== 'pending') {
            $pdo->rollBack();
            return 'That submission was not found, or someone has already decided it.';
        }
        $serviceId = null;
        if ($decision === 'approve') {
            if (!$row['is_active'] || $row['app_status'] !== 'approved') {
                $pdo->rollBack();
                return 'This provider\'s account is not active, so the service cannot be added. Reject it, or activate the provider first.';
            }
            $pdo->prepare("INSERT INTO services (provider_id, area_id, category, title, description, rate, status, added_by) VALUES (?, ?, ?, ?, ?, ?, 'available', ?)")
                ->execute([$row['provider_id'], $row['area_id'], $row['category'], $row['title'], $row['description'], $row['rate'], $reviewerId]);
            $serviceId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE service_submissions SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?, service_id = ? WHERE id = ?")
            ->execute([$decision === 'approve' ? 'approved' : 'rejected', $reviewerId, $note !== '' ? $note : null, $serviceId, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Could not save the decision. Please try again.';
    }
    log_action($decision === 'approve' ? 'service_submission.approve' : 'service_submission.reject', 'service', $serviceId, 
               ($decision === 'approve' ? 'Approved' : 'Rejected') . ' service submission: ' . $row['title'] . ($note !== '' ? ' (' . $note . ')' : ''));
    return '';
}

// Reads the approve/reject form posted by an applications page (services tab) and applies it. Returns [ok, message].
function handle_service_submission_post(PDO $pdo, int $reviewerId, ?string $cityKey): array {
    csrf_check();
    $decision = $_POST['action'] ?? '';
    $err = review_service_submission($pdo, (int)($_POST['id'] ?? 0), $decision, $reviewerId, (string)($_POST['note'] ?? ''), $cityKey);
    if ($err !== '') {
        return [false, $err];
    }
    return [true, $decision === 'approve' ? 'Service approved and added. It is now available to customers.' : 'Service submission rejected.'];
}

// One panel per submission, with the approve / reject forms for the ones still waiting.
function render_service_submissions(array $rows, bool $showCity): void {
    if (!$rows) {
        echo '<p class="muted">Nothing to show.</p>';
        return;
    }
    $cats = service_categories();
    $dt = fn($d) => $d ? date('M j, Y g:i A', strtotime($d)) : '-';
    foreach ($rows as $r) {
        $place = $r['barangay'] . ($showCity ? ', ' . $r['city'] . ', ' . $r['province'] : '');
        echo '<section class="panel"><div class="cityhead"><h3>' . e($r['title']) . ' <span class="muted" style="font-weight:400">- ' . e($cats[$r['category']][0] ?? $r['category']) . '</span></h3>'
           . '<span class="badge badge-' . e($r['status']) . '">' . e(ucfirst($r['status'])) . '</span></div>';
        echo '<dl class="kv" style="border:0;padding:0;margin:.4rem 0 1rem">'
           . '<dt>Provider</dt><dd>' . e($r['provider']) . ($r['business_name'] ? ' (' . e($r['business_name']) . ')' : '') . '</dd>'
           . '<dt>Rate</dt><dd>' . e(peso($r['rate'])) . '</dd>'
           . '<dt>Offered in</dt><dd>' . e($place) . '</dd>'
           . '<dt>Details</dt><dd>' . ($r['description'] ? nl2br(e($r['description'])) : '-') . '</dd>'
           . '<dt>Submitted</dt><dd>' . e($dt($r['created_at'])) . '</dd>';
        if ($r['status'] !== 'pending') {
            echo '<dt>' . ($r['status'] === 'approved' ? 'Approved' : 'Rejected') . '</dt><dd>' . e($r['reviewer'] ?: 'someone who has since been removed') . ', ' . e($dt($r['reviewed_at']))
               . ($r['review_note'] ? '<br><span class="muted">' . e($r['review_note']) . '</span>' : '') . '</dd>';
        }
        echo '</dl>';
        if ($r['status'] === 'pending') {
            echo '<div class="actions">'
               . '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="action" value="approve">'
               . '<label>Note (optional) <input name="note" maxlength="255"></label><button>Approve and add</button></form>'
               . '<form method="post" onsubmit="return confirm(\'Reject this service?\')">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="action" value="reject">'
               . '<label>Reason (shown to the provider) <input name="note" maxlength="255" required></label><button class="danger">Reject</button></form>'
               . '</div>';
        }
        echo '</section>';
    }
}

// The two inboxes ("Provider sign-ups" and "Service listings") as tabs with count badges. $current is 'providers' or 'services'.
function render_inbox_tabs(string $current, int $providers, int $services, array $keep = []): void {
    $badge = fn(int $n) => $n > 0 ? '<span class="count">' . $n . '</span>' : '';
    echo '<div class="tabs">';
    foreach (['providers' => ['Provider sign-ups', $providers], 'services' => ['Service listings', $services]] as $k => [$label, $n]) {
        echo '<a href="?' . e(http_build_query(array_filter($keep + ['type' => $k]))) . '" class="' . ($current === $k ? 'on' : '') . '">' . e($label) . $badge($n) . '</a>';
    }
    echo '</div>';
}
