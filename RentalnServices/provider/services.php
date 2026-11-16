<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$err = '';

$cats = service_categories();
$barangays = provider_barangays($pdo, $p);
$maxPending = 10;
$form = ['category' => '', 'title' => '', 'description' => '', 'rate' => '', 'area_id' => (int)$p['area_id']];
$editId = (int)($_GET['edit'] ?? 0);

// A rejected proposal can be corrected and sent again: load it into the form (only the provider's own, only if rejected).
if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare("SELECT id, category, title, description, rate, area_id FROM service_submissions WHERE id = ? AND provider_id = ? AND status = 'rejected'");
    $st->execute([$editId, $me]);
    if ($row = $st->fetch()) {
        $form = ['category' => $row['category'], 'title' => $row['title'], 'description' => (string)$row['description'], 'rate' => rtrim(rtrim($row['rate'], '0'), '.'), 'area_id' => (int)$row['area_id']];
    } else {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'status';

    if ($action === 'propose') {
        // A new service is only proposed here. It is added to the catalog when the manager of the city or an admin approves it.
        $editId = (int)($_POST['edit'] ?? 0);
        $form = [
            'category'    => $_POST['category'] ?? '',
            'title'       => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'rate'        => trim($_POST['rate'] ?? ''),
            'area_id'     => (int)($_POST['area_id'] ?? 0),
        ];
        $len = fn(string $x) => function_exists('mb_strlen') ? mb_strlen($x) : strlen($x);
        if (!isset($cats[$form['category']])) {
            $err = 'Choose a category.';
        } elseif ($len($form['title']) < 3 || $len($form['title']) > 150) {
            $err = 'Give the service a title (3 to 150 characters), for example "Aircon cleaning".';
        } elseif (!is_numeric($form['rate']) || (float)$form['rate'] <= 0 || (float)$form['rate'] > 99999999) {
            $err = 'Enter your rate in pesos, above zero.';
        } elseif ($len($form['description']) > 1000) {
            $err = 'Keep the details under 1,000 characters.';
        } elseif (!in_array($form['area_id'], array_column($barangays, 'id'), true)) {
            $err = 'Choose a barangay in ' . $p['city'] . '.';
        } else {
            try {
                $dupe = $pdo->prepare(
                    "SELECT (SELECT COUNT(*) FROM services WHERE provider_id = :p1 AND category = :c1 AND title = :t1)
                          + (SELECT COUNT(*) FROM service_submissions WHERE provider_id = :p2 AND category = :c2 AND title = :t2 AND status = 'pending' AND id <> :e)"
                );
                $dupe->execute([':p1' => $me, ':c1' => $form['category'], ':t1' => $form['title'], ':p2' => $me, ':c2' => $form['category'], ':t2' => $form['title'], ':e' => $editId]);
                if ((int)$dupe->fetchColumn() > 0) {
                    $err = 'You already offer, or have already proposed, a service with that category and title.';
                } elseif ((int)prov_value($pdo, "SELECT COUNT(*) FROM service_submissions WHERE provider_id = ? AND status = 'pending' AND id <> ?", [$me, $editId]) >= $maxPending) {
                    $err = 'You already have ' . $maxPending . ' services waiting for approval. Wait for a decision before proposing more.';
                } else {
                    if ($editId) {
                        $st = $pdo->prepare("UPDATE service_submissions SET area_id = ?, category = ?, title = ?, description = ?, rate = ?, status = 'pending', review_note = NULL, reviewed_by = NULL, reviewed_at = NULL
                                             WHERE id = ? AND provider_id = ? AND status = 'rejected'");
                        $st->execute([$form['area_id'], $form['category'], $form['title'], $form['description'] !== '' ? $form['description'] : null, $form['rate'], $editId, $me]);
                        if (!$st->rowCount()) { throw new RuntimeException('not-editable'); }
                        $id = $editId;
                    } else {
                        $pdo->prepare("INSERT INTO service_submissions (provider_id, area_id, category, title, description, rate) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$me, $form['area_id'], $form['category'], $form['title'], $form['description'] !== '' ? $form['description'] : null, $form['rate']]);
                        $id = (int)$pdo->lastInsertId();
                    }
                    log_action('service_submission.create', 'service', null, 'Provider proposed a service: ' . $form['title'] . ' (submission #' . $id . ')');
                    flash('Sent for approval. The manager of ' . ($p['city'] ?: 'your city') . ' or an admin will review "' . $form['title'] . '"; it is added once approved.');
                    header('Location: services.php');
                    exit;
                }
            } catch (RuntimeException $ex) {
                $err = 'That submission can no longer be edited.';
            } catch (PDOException $ex) {
                $err = $ex->getCode() === '42S02'
                    ? 'Proposing services is not set up yet. Ask the administrator to run database\migrate_service_submissions.php.'
                    : 'Could not save the proposal. Please try again.';
            }
        }
    } elseif ($action === 'withdraw') {
        // Take back a waiting proposal, or clear a rejected one from the list. Approved ones are real services and stay.
        $st = $pdo->prepare("DELETE FROM service_submissions WHERE id = ? AND provider_id = ? AND status IN ('pending','rejected')");
        $st->execute([(int)($_POST['id'] ?? 0), $me]);
        flash($st->rowCount() ? 'Removed.' : 'That submission was not found, or it has already been decided.');
        header('Location: services.php');
        exit;
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $to = $_POST['status'] ?? '';
        $st = $pdo->prepare("SELECT id, title FROM services WHERE id = ? AND provider_id = ?");
        $st->execute([$id, $me]);
        $s = $st->fetch();
        if (!$s) {
            $err = 'That service was not found among yours.';
        } elseif (!in_array($to, ['available', 'unavailable'], true)) {
            $err = 'Choose available or unavailable.';
        } elseif ($to === 'unavailable' && (prov_value($pdo, "SELECT 1 FROM service_requests WHERE service_id = ? AND status IN ('pending','approved') AND scheduled_at > NOW() LIMIT 1", [$id])
                || prov_value($pdo, "SELECT 1 FROM bookings WHERE driver_service_id = ? AND status IN ('pending','approved') AND end_at > NOW() LIMIT 1", [$id]))) {
            $err = $s['title'] . ' still has pending or upcoming approved jobs, so it cannot be marked unavailable yet. Ask the manager if a job has to change.';
        } else {
            $pdo->prepare("UPDATE services SET status = ? WHERE id = ? AND provider_id = ?")->execute([$to, $id, $me]);
            log_action('service.status', 'service', $id, 'Provider set ' . $s['title'] . ' to ' . $to);
            flash($s['title'] . ' is now ' . $to . '.');
            header('Location: services.php');
            exit;
        }
    }
}

$st = $pdo->prepare(
    "SELECT s.id, s.category, s.title, s.rate, s.status, a.barangay,
            (SELECT COUNT(*) FROM service_requests r WHERE r.service_id = s.id AND r.status IN ('pending','approved') AND r.scheduled_at > NOW())
          + (SELECT COUNT(*) FROM bookings b WHERE b.driver_service_id = s.id AND b.status IN ('pending','approved') AND b.end_at > NOW()) AS open_jobs,
            (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS rating,
            (SELECT COUNT(*) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS reviews
     FROM services s LEFT JOIN areas a ON a.id = s.area_id WHERE s.provider_id = ? ORDER BY s.title"
);
$st->execute([$me]);
$rows = $st->fetchAll();

$subs = [];
try {
    $q = $pdo->prepare("SELECT ss.id, ss.category, ss.title, ss.rate, ss.status, ss.review_note, ss.reviewed_at, ss.created_at, a.barangay
                        FROM service_submissions ss JOIN areas a ON a.id = ss.area_id
                        WHERE ss.provider_id = ? AND ss.status IN ('pending','rejected') ORDER BY ss.id DESC LIMIT 50");
    $q->execute([$me]);
    $subs = $q->fetchAll();
} catch (Throwable $ex) { /* the submissions table is created by the upgrade script */ }

provider_header('Services', 'services.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
echo '<p class="muted">The services you offer. Switch one to unavailable when you cannot take new requests. To offer something new, propose it below: the manager of ' . e($p['city'] ?: 'your city') . ' or an admin checks it first, and it is added to the catalog only once approved.</p>';
?>
<details class="panel" <?= ($err && ($_POST['action'] ?? '') === 'propose') || $editId || (!$rows && !$subs) ? 'open' : '' ?>>
  <summary><b><?= $editId ? 'Edit and send again' : 'Propose a new service' ?></b></summary>
  <form method="post" class="box" style="margin:1rem 0 0">
    <?= csrf_field() ?><input type="hidden" name="action" value="propose"><input type="hidden" name="edit" value="<?= (int)$editId ?>">
    <label>Category
      <select name="category" required>
        <option value="">Choose what you offer</option>
        <?php foreach ($cats as $k => [$label, $about]): ?>
          <option value="<?= e($k) ?>" <?= $form['category'] === $k ? 'selected' : '' ?>><?= e($label . ' - ' . $about) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Title <input name="title" required minlength="3" maxlength="150" placeholder="For example: Aircon cleaning" value="<?= e($form['title']) ?>"></label>
    <label>Your rate (PHP) <input name="rate" type="number" min="1" step="0.01" required value="<?= e($form['rate']) ?>"></label>
    <label>Barangay where you offer it
      <select name="area_id" required>
        <?php foreach ($barangays as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $form['area_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['barangay']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="grid-column:1/-1">Details (optional) <textarea name="description" rows="3" maxlength="1000" placeholder="What is included, how long it takes, anything customers should know"><?= e($form['description']) ?></textarea></label>
    <label>&nbsp;<button><?= $editId ? 'Send again for approval' : 'Send for approval' ?></button></label>
  </form>
</details>

<?php if ($subs): ?>
<h2>Waiting for a decision or not approved</h2>
<div class="scroll"><table>
  <thead><tr><th>Service</th><th>Category</th><th>Barangay</th><th>Rate</th><th>Status</th><th>Note from the reviewer</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($subs as $x): ?>
    <tr>
      <td><?= e($x['title']) ?></td>
      <td><?= e($cats[$x['category']][0] ?? $x['category']) ?></td>
      <td><?= e($x['barangay']) ?></td>
      <td><?= e(peso($x['rate'])) ?></td>
      <td><span class="badge badge-<?= e($x['status']) ?>"><?= $x['status'] === 'pending' ? 'Waiting for approval' : 'Not approved' ?></span></td>
      <td style="white-space:normal;max-width:22rem"><?= $x['status'] === 'rejected' ? e($x['review_note'] ?: '-') : '<span class="muted">Not reviewed yet</span>' ?></td>
      <td style="white-space:nowrap">
        <?php if ($x['status'] === 'rejected'): ?><a href="services.php?edit=<?= (int)$x['id'] ?>">Edit and send again</a><?php endif; ?>
        <form method="post" style="display:inline;margin:0 0 0 .6rem" onsubmit="return confirm('<?= $x['status'] === 'pending' ? 'Take this back?' : 'Remove it from the list?' ?>')">
          <?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
          <button class="alt"><?= $x['status'] === 'pending' ? 'Withdraw' : 'Dismiss' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<h2>Your services</h2>
<?php
if (!$rows) {
    echo '<p class="muted">None yet. Services appear here once they are approved.</p>';
} else { ?>
<div class="scroll"><table>
  <thead><tr><th>Service</th><th>Category</th><th>Barangay</th><th>Rate</th><th>Rating</th><th>Open jobs</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $on = $r['status'] !== 'unavailable'; ?>
    <tr>
      <td><?= e($r['title']) ?></td>
      <td><?= e($cats[$r['category']][0] ?? ucfirst(str_replace('_', ' ', $r['category']))) ?></td>
      <td><?= e($r['barangay'] ?: '-') ?></td>
      <td><?= e(peso($r['rate'])) ?></td>
      <td><?= $r['reviews'] ? e($r['rating'] . ' (' . $r['reviews'] . ')') : '-' ?></td>
      <td><?= (int)$r['open_jobs'] ?></td>
      <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
      <td>
        <form method="post" style="margin:0" <?= $on ? 'onsubmit="return confirm(\'Mark this service unavailable? Customers will not be able to request it.\')"' : '' ?>>
          <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $on ? 'unavailable' : 'available' ?>">
          <button class="alt"><?= $on ? 'Mark unavailable' : 'Mark available' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php }
page_footer();
