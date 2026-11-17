<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();
$adminId = $_SESSION['user']['id'];
$err = '';

// Small helper: does this query return a row?
$found = function (string $sql, array $params) use ($pdo): bool {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        // Admin can activate/deactivate managers and providers, and block/unblock customers.
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT u.role, u.is_active, u.area_id, COALESCE(pp.application_status, 'approved') AS app_status
                             FROM users u LEFT JOIN provider_profiles pp ON pp.user_id = u.id
                             WHERE u.id = ? AND u.role IN ('manager','provider','customer') FOR UPDATE");
        $st->execute([$id]);
        $u = $st->fetch();
        if ($u && $u['app_status'] !== 'approved') {
            $pdo->rollBack();
            flash('This provider has not been approved. Decide the application on the Applications page.');
        } elseif ($u) {
            $activating = !$u['is_active'];
            if ($activating && $u['role'] === 'manager' && $u['area_id'] && manager_conflict($pdo, (int)$u['area_id'], $id)) {
                $pdo->rollBack();
                flash('That city already has an active manager. Deactivate them first.');
            } else {
                $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $pdo->commit();
                log_action('account.status', 'user', $id, ($activating ? 'Activated ' : 'Deactivated ') . $u['role'] . ' account');
                flash('Account status updated.');
            }
        } else {
            $pdo->rollBack();
        }
        header('Location: accounts.php?role=' . urlencode($_POST['filter'] ?? 'all'));
        exit;
    }

    if ($action === 'add') {
        $role   = $_POST['role'] ?? '';
        $name   = trim($_POST['full_name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = trim($_POST['phone'] ?? '') ?: null;
        $pass   = $_POST['password'] ?? '';
        $area   = (int)($_POST['area_id'] ?? 0);   // the barangay; its city is what a manager covers
        $street = trim($_POST['street'] ?? '') ?: null;
        $biz    = trim($_POST['business_name'] ?? '') ?: null;
        $rec    = (int)($_POST['recommended_by'] ?? 0) ?: null;

        if (!in_array($role, ['manager', 'provider'], true)) {
            $err = 'Choose manager or provider.';
        } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Enter a name and a valid email.';
        } elseif ($phone !== null && !preg_match('/^[0-9]{11}$/', $phone)) {
            $err = 'Enter an 11-digit phone number, for example 09171234567, or leave it empty.';
        } elseif (strlen($pass) < 8) {
            $err = 'Temporary password must be at least 8 characters.';
        } elseif (!$found("SELECT 1 FROM areas WHERE id = ?", [$area])) {
            $err = 'Select a city and barangay.';
        } else {
            if ($rec && !$found("SELECT 1 FROM users WHERE id = ? AND role = 'manager'", [$rec])) {
                $rec = null;
            }
            try {
                $pdo->beginTransaction();
                if ($role === 'manager' && manager_conflict($pdo, $area)) {
                    $pdo->rollBack();
                    $err = 'That city already has an active manager.';
                } else {
                    $pdo->prepare(
                        "INSERT INTO users (role, full_name, email, phone, password_hash, area_id, street, created_by)
                         VALUES (?,?,?,?,?,?,?,?)"
                    )->execute([$role, $name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $area, $street, $adminId]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($role === 'provider') {
                        $pdo->prepare("INSERT INTO provider_profiles (user_id, business_name, recommended_by) VALUES (?,?,?)")
                            ->execute([$newId, $biz, $rec]);
                    }
                    $pdo->commit();
                    log_action('account.create', 'user', $newId, "Created $role account: $name");
                    flash(ucfirst($role) . ' account created.');
                    header('Location: accounts.php?role=' . $role);
                    exit;
                }
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = $ex->getCode() === '23000' ? 'That email is already registered.' : 'Could not save the account.';
            }
        }
    }
}

$filter = $_GET['role'] ?? 'all';
$where = " WHERE u.role <> 'admin'";
$params = [];
// Tabs: a role, or "off" = the switched-off accounts of a role (deactivated manager / provider, blocked customer).
// A provider whose application is still pending or rejected is not "deactivated"; those are on the Applications page.
$off = ['off_manager' => 'manager', 'off_provider' => 'provider', 'off_customer' => 'customer'];
if (in_array($filter, ['manager', 'provider', 'customer'], true)) {
    $where .= " AND u.role = ?";
    $params[] = $filter;
} elseif (isset($off[$filter])) {
    $where .= " AND u.role = ? AND u.is_active = 0 AND COALESCE(pp.application_status, 'approved') = 'approved'";
    $params[] = $off[$filter];
} else {
    $filter = 'all';
}
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';   // so a typed % or _ is searched literally
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = $like;
    $params[] = $like;
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN provider_profiles pp ON pp.user_id = u.id AND u.role = 'provider'" . $where);
$cnt->execute($params);
$pg = paginate((int)$cnt->fetchColumn(), 50);
$st = $pdo->prepare(
    "SELECT u.id, u.role, u.full_name, u.email, u.phone, u.street, u.is_active, a.city_municipality AS city, a.barangay,
            CASE WHEN pp.application_status = 'pending' THEN 'Pending approval' WHEN pp.application_status = 'rejected' THEN 'Rejected'
                 WHEN u.is_active THEN 'Active' WHEN u.role = 'customer' THEN 'Blocked' ELSE 'Deactivated' END AS status_text, COALESCE(pp.application_status, 'approved') AS app_status
     FROM users u LEFT JOIN areas a ON a.id = u.area_id LEFT JOIN provider_profiles pp ON pp.user_id = u.id AND u.role = 'provider'" . $where . " ORDER BY u.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($params);
$accounts = $st->fetchAll();

$managers  = $pdo->query("SELECT id, full_name FROM users WHERE role = 'manager' AND is_active = 1 ORDER BY full_name")->fetchAll();

page_header('Accounts', 'accounts.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
if (!db_has_areas($pdo)) echo '<p class="err">There are no areas yet. Run database/import_psgc.php, or add a city on the Areas page, before creating accounts.</p>';
?>
<?php
// The add form stays hidden until "Add account" is clicked, but opens by itself
// after a failed submit or when arriving from another page (?add=manager).
$showAdd = $err !== '' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') || isset($_GET['add']);
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin:1.7rem 0 .7rem">
  <h2 style="margin:0">All accounts</h2>
  <button type="button" id="toggle-add" aria-expanded="<?= $showAdd ? 'true' : 'false' ?>" aria-controls="add-section"><?= $showAdd ? 'Close' : '+ Add account' ?></button>
</div>

<div id="add-section" <?= $showAdd ? '' : 'hidden' ?>>
<h2 id="add" style="margin-top:.3rem">Add manager or provider</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label>Role
    <select name="role" required>
      <option value="manager" <?= ($_POST['role'] ?? ($_GET['add'] ?? '')) === 'manager' ? 'selected' : '' ?>>Manager (covers the whole city of the barangay)</option>
      <option value="provider" <?= ($_POST['role'] ?? '') === 'provider' ? 'selected' : '' ?>>Provider</option>
    </select>
  </label>
  <label>Full name <input name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>"></label>
  <label>Email <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
  <label>Phone <input type="tel" name="phone" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="11 digits, for example 09171234567" placeholder="09XXXXXXXXX" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)" value="<?= e($_POST['phone'] ?? '') ?>"></label>
  <?php area_picker(['api' => '../api/areas.php', 'js' => '../assets/areas.js', 'city' => (string)($_POST['city'] ?? ($_GET['city'] ?? '')),
                     'area_id' => (int)($_POST['area_id'] ?? 0), 'required' => true]); ?>
  <label>Street / address <input name="street" value="<?= e($_POST['street'] ?? '') ?>"></label>
  <label>Business name (providers) <input name="business_name" value="<?= e($_POST['business_name'] ?? '') ?>"></label>
  <label>Recommended by (providers)
    <select name="recommended_by">
      <option value="">None</option>
      <?php foreach ($managers as $m): ?>
        <option value="<?= (int)$m['id'] ?>" <?= (int)($_POST['recommended_by'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Temporary password <input type="password" name="password" minlength="8" required></label>
  <label>&nbsp;<button>Create account</button></label>
</form>
</div>
<script>
(function () {
  var btn = document.getElementById('toggle-add'), box = document.getElementById('add-section');
  btn.addEventListener('click', function () {
    var open = box.hidden;               // hidden now => opening
    box.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.textContent = open ? 'Close' : '+ Add account';
    if (open) { var f = box.querySelector('[name=full_name]'); if (f) f.focus(); }
  });
})();
</script>

<div class="tabs">
  <?php foreach (['all' => 'All', 'manager' => 'Managers', 'provider' => 'Providers', 'customer' => 'Customers',
                  'off_manager' => 'Deactivated managers', 'off_provider' => 'Deactivated providers', 'off_customer' => 'Blocked customers'] as $k => $label): ?>
    <a href="?role=<?= $k ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="box" style="grid-template-columns:1fr auto">
  <input type="hidden" name="role" value="<?= e($filter) ?>">
  <input name="q" placeholder="Search by name or email" value="<?= e($q) ?>" aria-label="Search accounts">
  <button>Search</button>
</form>
<?php if (!$accounts): ?>
  <p class="muted">Nothing to show yet.</p>
<?php else: ?>
<div class="scroll"><table>
  <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Phone</th><th>City / Municipality</th><th>Barangay</th><th>Street</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($accounts as $u): ?>
    <tr>
      <td><a href="account.php?id=<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></a></td>
      <td><?= e(ucfirst($u['role'])) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= e($u['phone']) ?></td>
      <td><?= e($u['city']) ?></td>
      <td><?= e($u['barangay']) ?></td>
      <td><?= e($u['street']) ?></td>
      <td><span class="badge badge-<?= e(preg_replace('/[^a-z]/', '', strtolower($u['status_text']))) ?>"><?= e($u['status_text']) ?></span></td>
      <td>
        <?php if ($u['app_status'] !== 'approved'): ?>
          <a href="applications.php?status=<?= e($u['app_status']) ?>">Review</a>
        <?php elseif (in_array($u['role'], ['manager', 'provider', 'customer'], true)): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Change this account\'s status?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <button class="alt"><?= $u['role'] === 'customer' ? ($u['is_active'] ? 'Block' : 'Unblock') : ($u['is_active'] ? 'Deactivate' : 'Activate') ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif;
render_pager($pg);
page_footer();
