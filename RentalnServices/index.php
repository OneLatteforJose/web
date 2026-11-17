<?php
require_once __DIR__ . '/includes/site.php';
require_once __DIR__ . '/includes/areas.php';

public_header('Vehicles and home services', true);
?>
<section class="slider" id="hero" aria-roledescription="carousel" aria-label="Rent a vehicle or book a service">
  <div class="slide hero-vehicle on" role="group" aria-roledescription="slide" aria-label="1 of 2">
    <a class="hero-card" href="#vehicles">
      <h1>Rent a vehicle.</h1>
      <p>Choose a vehicle that is available in your area, with or without a driver.</p>
      <span class="hero-go">See areas and offered vehicles &rarr;</span>
    </a>
  </div>
  <div class="slide hero-service" role="group" aria-roledescription="slide" aria-label="2 of 2">
    <a class="hero-card" href="#services">
      <h2>Book a Service.</h2>
      <p>Find and connect with reliable local professionals for your everyday needs, from home repairs and cleaning to personal care and transportation.</p>
      <span class="hero-go">See areas and offered services &rarr;</span>
    </a>
  </div>
  <button type="button" class="arrow prev" aria-label="Previous slide">&#8249;</button>
  <button type="button" class="arrow next" aria-label="Next slide">&#8250;</button>
  <div class="dots" role="group" aria-label="Choose a slide"><button type="button" class="on" aria-label="Slide 1"></button><button type="button" aria-label="Slide 2"></button></div>
</section>
<script>
(function () {
  var root = document.getElementById('hero');
  var slides = root.querySelectorAll('.slide'), dots = root.querySelectorAll('.dots button'), i = 0, timer = null;
  var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  root.classList.add('js');
  function show(n) {
    i = (n + slides.length) % slides.length;
    slides.forEach(function (s, k) { s.classList.toggle('on', k === i); });
    dots.forEach(function (d, k) { d.classList.toggle('on', k === i); d.setAttribute('aria-current', k === i ? 'true' : 'false'); });
  }
  function play() { if (!still && !timer) timer = setInterval(function () { show(i + 1); }, 6000); }
  function stop() { clearInterval(timer); timer = null; }
  root.querySelector('.prev').addEventListener('click', function () { show(i - 1); });
  root.querySelector('.next').addEventListener('click', function () { show(i + 1); });
  dots.forEach(function (d, k) { d.addEventListener('click', function () { show(k); }); });
  root.addEventListener('mouseenter', stop); root.addEventListener('mouseleave', play);
  root.addEventListener('focusin', stop); root.addEventListener('focusout', play);
  var x0 = null;
  root.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; stop(); }, {passive: true});
  root.addEventListener('touchend', function (e) {
    if (x0 !== null) { var dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) show(i + (dx < 0 ? 1 : -1)); }
    x0 = null; play();
  });
  show(0); play();
})();
</script>

<?php
// Provinces that have at least one city/municipality with a manager, providers, and something to offer.
// Compact master-detail browser so the page stays the same height however many provinces there are:
// province chips on the left (scrollable, searchable), the chosen province's municipalities on the right (scrollable).
$covered = covered_cities(db());
$areaBrowser = function (string $for) use ($covered): void {
    $page = $for === 'vehicles' ? 'vehicles.php' : 'services.php';
    $word = $for === 'vehicles' ? 'vehicle' : 'service';
    $by = [];
    foreach ($covered as $c) {
        if ((int)$c[$for] > 0) $by[$c['province']][] = $c;
    }
    if (!$by) { echo '<p class="note">No area is open yet. Please check back soon.</p>'; return; }
    ksort($by);
    ?>
<div class="ab" data-ab>
  <div class="ab-side">
    <input type="search" class="ab-find" placeholder="Search province or municipality" aria-label="Search province or municipality" data-ab-find>
    <ul class="ab-prov" role="listbox" aria-label="Provinces" data-ab-prov>
      <?php foreach ($by as $prov => $cities): ?>
        <li><button type="button" role="option" data-prov="<?= e($prov) ?>" data-munis="<?= e(implode('|', array_column($cities, 'city'))) ?>">
          <span><?= e($prov) ?></span><span class="ab-n"><?= count($cities) ?></span></button></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="ab-main" data-ab-main aria-live="polite">
    <p class="ab-hint" data-ab-hint>Choose a province to see its municipalities.</p>
    <?php foreach ($by as $prov => $cities): ?>
      <div class="ab-panel" data-panel="<?= e($prov) ?>" hidden>
        <h3><?= e($prov) ?></h3>
        <ul class="ab-muni">
          <?php foreach ($cities as $c): $n = (int)$c[$for]; ?>
            <li data-muni="<?= e($c['city']) ?>"><a href="<?= $page ?>?<?= e(http_build_query(['city' => city_key($c['province'], $c['city'])])) ?>">
              <strong><?= e($c['city']) ?></strong>
              <span class="meta"><?= $n ?> <?= $word ?><?= $n === 1 ? '' : 's' ?> &middot; <?= (int)$c['providers'] ?> provider<?= (int)$c['providers'] === 1 ? '' : 's' ?></span>
              <span class="meta">Manager: <?= e($c['managers']) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
};
?>
<section class="section" id="vehicles">
  <div class="wrap">
    <h2>Available Areas with verified vehicles</h2>
    <p class="intro">Choose a province, then a municipality, to see the vehicles it offers.</p>
    <?php $areaBrowser('vehicles'); ?>
    <p class="note">A driver comes from the vehicle owner, or your area manager assigns one.</p>
  </div>
</section>

<section class="section" id="services">
  <div class="wrap">
    <h2>Available Areas with verified services</h2>
    <p class="intro">Choose a province, then a municipality, to see the services it offers.</p>
    <?php $areaBrowser('services'); ?>
  </div>
</section>

<script>
// Province -> municipality browser (one per section). Works on whatever number of provinces there are.
document.querySelectorAll('[data-ab]').forEach(function (root) {
  var btns = [].slice.call(root.querySelectorAll('[data-prov]')),
      panels = [].slice.call(root.querySelectorAll('[data-panel]')),
      hint = root.querySelector('[data-ab-hint]'), find = root.querySelector('[data-ab-find]');
  function pick(name) {
    btns.forEach(function (b) { var on = b.getAttribute('data-prov') === name; b.classList.toggle('on', on); b.setAttribute('aria-selected', on); });
    panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
    hint.hidden = !!name;
  }
  btns.forEach(function (b) { b.addEventListener('click', function () { pick(b.getAttribute('data-prov')); }); });
  // Search narrows the province list and the municipality cards at the same time.
  find.addEventListener('input', function () {
    var q = find.value.trim().toLowerCase(), firstShown = null;
    btns.forEach(function (b) {
      var prov = b.getAttribute('data-prov').toLowerCase(), munis = b.getAttribute('data-munis').toLowerCase();
      var show = !q || prov.indexOf(q) > -1 || munis.indexOf(q) > -1;
      b.parentNode.hidden = !show;
      if (show && !firstShown) firstShown = b.getAttribute('data-prov');
    });
    panels.forEach(function (p) {
      var provMatch = p.getAttribute('data-panel').toLowerCase().indexOf(q) > -1;
      p.querySelectorAll('[data-muni]').forEach(function (li) { li.hidden = !!q && !provMatch && li.getAttribute('data-muni').toLowerCase().indexOf(q) === -1; });
    });
    if (q && firstShown) pick(firstShown);
  });
  if (btns.length === 1) pick(btns[0].getAttribute('data-prov'));   // only one province: show it straight away
});
</script>

<section class="section" id="how">
  <div class="wrap">
    <h2>How it works</h2>
    <p class="intro">From request to review in four steps.</p>
    <ol class="steps">
      <li><strong>Create an account</strong><span>Sign up, then choose to book a vehicle or request a service.</span></li>
      <li><strong>Pick a time and pay</strong><span>We check that the vehicle or provider is available. You pay online.</span></li>
      <li><strong>Your manager approves</strong><span>The manager for your area reviews and approves your booking or request.</span></li>
      <li><strong>Enjoy, then review</strong><span>Once it is done, rate the vehicle or service.</span></li>
    </ol>
  </div>
</section>

<section class="section" id="area">
  <div class="wrap">
    <h2>Built around your area</h2>
    <div class="triple">
      <div><h3>Local managers</h3><p>Every booking and request is reviewed by a manager who covers your area.</p></div>
      <div><h3>Recommended providers</h3><p>Providers are recommended by area managers and activated by the admin.</p></div>
      <div><h3>Honest ratings</h3><p>Only people who actually used a vehicle or service can review it.</p></div>
    </div>
  </div>
</section>

<section class="section" id="partners">
  <div class="wrap">
    <div class="callout">
      <h2>Own a vehicle or offer a service?</h2>
      <p>Apply to join as a provider. The manager of your city or an admin checks every application. Once it is approved, you can list your vehicles and services and start receiving bookings.</p>
      <p><a class="btn btn-road" href="register.php?as=provider">Apply as a provider</a></p>
    </div>
  </div>
</section>
<?php public_footer();
