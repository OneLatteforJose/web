<?php
// Temporary helper: shows why "Online payment is not set up yet" appears. Open it in the browser, then DELETE this file.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Local use only.'); }
require_once __DIR__ . '/includes/xendit.php';

$rows = [];
function row(array &$rows, string $label, bool $ok, string $detail): void { $rows[] = [$label, $ok, $detail]; }

$cfg = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
row($rows, 'config.local.php exists in the project folder', is_file($cfg),
    is_file($cfg) ? $cfg : 'Not found at: ' . $cfg . (is_file($cfg . '.txt') ? '  (but config.local.php.txt exists: Windows is hiding the real extension, rename it)' : ''));

$has = defined('XENDIT_SECRET_KEY');
row($rows, 'XENDIT_SECRET_KEY is defined', $has, $has ? 'yes' : 'No. Check the spelling in config.local.php: define(\'XENDIT_SECRET_KEY\', \'xnd_development_...\');');
$key = $has ? (string)XENDIT_SECRET_KEY : '';
if ($has) {
    row($rows, 'Key is not empty', $key !== '', $key !== '' ? 'length ' . strlen($key) : 'It is an empty string');
    $shape = str_starts_with($key, 'xnd_development_') || str_starts_with($key, 'xnd_production_');
    row($rows, 'Key looks like a Xendit secret key', $shape && !str_contains($key, 'xxxx'),
        'starts with "' . substr($key, 0, 16) . '"' . (str_starts_with($key, 'xnd_public') ? ' (this is a PUBLIC key, you need the SECRET key)' : '') . (str_contains($key, 'xxxx') ? ' (still the placeholder)' : '') . (trim($key) !== $key ? ' (has spaces around it)' : ''));
}
row($rows, 'PHP curl extension is on', extension_loaded('curl'), extension_loaded('curl') ? 'yes' : 'Remove the ; before extension=curl in php.ini, then restart Apache');

if ($has && $key !== '' && extension_loaded('curl')) {
    [$code, $res] = xendit_request('GET', '/v2/invoices?limit=1');
    $msg = $code === 200 ? 'Xendit accepted the key' : ($code === 0 ? 'Could not reach api.xendit.co (internet, firewall or SSL certificate problem; see the Apache error.log)' : 'HTTP ' . $code . ': ' . ($res['message'] ?? json_encode($res)));
    row($rows, 'Xendit accepts the key', $code === 200, $msg);
}
?><!doctype html><meta charset="utf-8"><title>Xendit check</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem}li{margin:.5rem 0}.ok{color:#1a7a43}.no{color:#b3261e}code{background:#eee;padding:0 .3rem}</style>
<h1>Xendit check</h1>
<ul>
<?php foreach ($rows as [$label, $ok, $detail]): ?>
  <li class="<?= $ok ? 'ok' : 'no' ?>"><strong><?= $ok ? 'OK' : 'FIX' ?></strong>: <?= htmlspecialchars($label) ?><br><small><?= htmlspecialchars($detail) ?></small></li>
<?php endforeach; ?>
</ul>
<p>All OK? Delete <code>xendit_check.php</code> and reload the Book page (Ctrl+F5).</p>
