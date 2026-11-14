<?php
// Provider applications: a provider signs up (register.php?as=provider) and the account waits, switched off, until the
// manager of their city or an admin approves it. Shared by admin/applications.php and manager/applications.php.
//
// Storage: provider_profiles.application_status is 'pending', 'approved' or 'rejected' (the providers an admin creates
// are simply 'approved'). While an application is pending or rejected, users.is_active stays 0, so every existing
// "active provider" query ignores the applicant without any change. Approving switches the account on.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/areas.php';

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
