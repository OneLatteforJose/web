<?php
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
$me = require_user();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT s.*, p.full_name AS provider, pp.business_name, a.barangay, a.city_municipality AS city, a.province,
            (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS rating,
            (SELECT COUNT(*) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS reviews
     FROM services s JOIN users p ON p.id = s.provider_id AND p.is_active = 1 LEFT JOIN provider_profiles pp ON pp.user_id = p.id JOIN areas a ON a.id = s.area_id
     WHERE s.id = ?"
);
$st->execute([$id]);
$s = $st->fetch();
if (!$s) {
    flash('That service is no longer listed.');
    header('Location: services.php');
    exit;
}
$st = $pdo->prepare(
    "SELECT rv.rating, rv.comment, rv.created_at, u.full_name FROM reviews rv
     JOIN service_requests r ON r.id = rv.service_request_id JOIN users u ON u.id = rv.user_id
     WHERE r.service_id = ? ORDER BY rv.id DESC LIMIT 5"
);
$st->execute([$id]);
$reviews = $st->fetchAll();
$sameCity = $me['city'] && $me['province'] === $s['province'] && $me['city'] === $s['city'];

user_header($s['title'], 'services.php', ['back' => 'services.php']);
?>
<div class="cols">
<section class="panel main">
  <div style="display:flex;gap:.8rem;align-items:center;margin-bottom:.7rem">
    <span class="ic"><?= uicon(category_icon($s['category'])) ?></span>
    <div><strong><?= e(category_label($s['category'])) ?></strong><br><?= stars($s['rating'] !== null ? (float)$s['rating'] : null, (int)$s['reviews']) ?></div>
    <div style="margin-left:auto;text-align:right"><span class="price-big"><?= e(peso($s['rate'])) ?></span></div>
  </div>
  <?php if ($s['description']): ?><p style="margin:.2rem 0 .8rem"><?= nl2br(e($s['description'])) ?></p><?php endif; ?>
  <dl class="kv">
    <dt>Provider</dt><dd><?= e($s['business_name'] ?: $s['provider']) ?></dd>
    <dt>Area</dt><dd><?= e($s['barangay'] . ', ' . $s['city']) ?></dd>
    <dt>Availability</dt><dd><?= $s['status'] === 'available' ? 'Available' : 'Not available right now' ?></dd>
  </dl>
</section>

<div class="side">
<?php if (!$sameCity): ?><p class="alert">This service is only offered in <?= e($s['city']) ?>. Services can be requested in your own city.</p><?php endif; ?>
<?php if ($s['status'] !== 'available'): ?><button class="btn" disabled>Not available right now</button>
<?php elseif (!$sameCity): ?><button class="btn" disabled>Book now</button>
<?php elseif (!xendit_enabled()): ?><button class="btn" disabled>Book now</button><p class="hint">Online payment is not set up yet.</p>
<?php else: ?><a class="btn" href="book_service.php?id=<?= (int)$s['id'] ?>">Book now</a>
<p class="hint">Pay with GCash, Maya or a card. Refunded in full if the manager rejects it or you cancel while it is pending.</p><?php endif; ?>
</div>

<div class="more">
<div class="sec"><h2>Reviews</h2></div>
<?php if (!$reviews): ?><p class="empty">No reviews yet.</p><?php else: ?>
<div class="panel">
  <?php foreach ($reviews as $r): ?>
    <div class="reviewer"><strong><?= e(short_name($r['full_name'])) ?></strong><span class="rate"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.8 6.3.7-4.7 4.3 1.3 6.2L12 16.9 6.4 20l1.3-6.2L3 9.5l6.3-.7z"/></svg><?= (int)$r['rating'] ?></span>
      <span class="muted"> <?= e(substr($r['created_at'], 0, 10)) ?></span><?php if ($r['comment']): ?><br><?= e($r['comment']) ?><?php endif; ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<?php user_footer();
