<?php
require_once __DIR__ . '/includes/site.php';

$vehicles = vehicle_types();
$services = service_categories();

public_header('Vehicles and home services', true);
?>
<section class="slider" id="hero" aria-roledescription="carousel" aria-label="Rent a vehicle or book a service">
  <div class="slide hero-vehicle on" role="group" aria-roledescription="slide" aria-label="1 of 2">
    <div class="hero-card">
      <h1>Rent a vehicle.</h1>
      <p>Choose a vehicle that is available in your area, with or without a driver.</p>
    </div>
  </div>
  <div class="slide hero-service" role="group" aria-roledescription="slide" aria-label="2 of 2">
    <div class="hero-card">
      <h2>Book a Service.</h2>
      <p>Find and connect with reliable local professionals for your everyday needs, from home repairs and cleaning to personal care and transportation.</p>
    </div>
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

<section class="section" id="vehicles">
  <div class="wrap">
    <h2>Vehicles for rent</h2>
    <p class="intro">Pick the vehicle that fits the trip, then decide whether you want to drive it yourself.</p>
    <ul class="rows">
      <?php foreach ($vehicles as [$label, $desc]): ?>
        <li>
          <strong><?= e($label) ?></strong>
          <span><?= e($desc) ?></span>
          <span class="tags"><span class="tag">Drive yourself</span><span class="tag">With a driver</span></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">A driver comes from the vehicle owner, or your area manager assigns one.</p>
  </div>
</section>

<section class="section" id="services">
  <div class="wrap">
    <h2>Services at your door</h2>
    <p class="intro">Request a service from providers who work in your area.</p>
    <ul class="svc">
      <?php foreach ($services as [$label, $desc]): ?>
        <li><strong><?= e($label) ?></strong><span><?= e($desc) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

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
