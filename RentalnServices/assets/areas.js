// Province -> city -> barangay pickers. Each picker is a <div class="area-picker"> written by area_picker()
// in includes/areas.php. Options are fetched on demand from api/areas.php, so no page ever carries all
// 42,000 barangays. City options carry the value "Province|City"; barangay options carry the areas.id.
(function () {
  'use strict';

  function option(text, value, selected) {
    var o = document.createElement('option');
    o.textContent = text;
    o.value = value;
    if (selected) o.selected = true;
    return o;
  }

  function fill(select, placeholder, items, want) {
    select.innerHTML = '';
    select.appendChild(option(placeholder, '', false));
    items.forEach(function (it) { select.appendChild(option(it.name, it.value, String(it.value) === String(want))); });
    select.disabled = items.length === 0;
  }

  function init(root) {
    var api = root.getAttribute('data-api');
    var prov = root.querySelector('[data-level=province]');
    var city = root.querySelector('[data-level=city]');
    var brgy = root.querySelector('[data-level=barangay]');
    var want = {
      province: root.getAttribute('data-province') || '',
      city: root.getAttribute('data-city') || '',
      area: root.getAttribute('data-area') || ''
    };
    var text = {
      province: root.getAttribute('data-province-label') || 'Select province',
      city: root.getAttribute('data-city-label') || 'Select city',
      barangay: root.getAttribute('data-barangay-label') || 'Select barangay'
    };
    var seq = { province: 0, city: 0, barangay: 0 };   // ignore answers that arrive after a newer request

    function get(params, level) {
      var n = ++seq[level];
      return fetch(api + '?' + new URLSearchParams(params), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('bad response'); return r.json(); })
        .then(function (data) { return n === seq[level] ? data : null; });
    }
    function failed(select, placeholder) {
      select.innerHTML = '';
      select.appendChild(option('Could not load. Reload the page.', '', false));
      select.disabled = true;
    }

    function loadBarangays() {
      if (!brgy) return;
      var key = city.value, i = key.indexOf('|');
      if (!key || i < 0) { fill(brgy, text.barangay, [], ''); return; }
      get({ level: 'barangay', province: key.slice(0, i), city: key.slice(i + 1) }, 'barangay').then(function (data) {
        if (!data) return;
        fill(brgy, text.barangay, data.map(function (b) { return { name: b.name, value: b.id }; }), want.area);
        want.area = '';
      }).catch(function () { failed(brgy); });
    }

    function loadCities() {
      if (!prov.value) {
        fill(city, text.city, [], '');
        if (brgy) fill(brgy, text.barangay, [], '');
        return;
      }
      get({ level: 'city', province: prov.value }, 'city').then(function (data) {
        if (!data) return;
        fill(city, text.city, data.map(function (c) { return { name: c.name, value: c.key }; }), want.city);
        want.city = '';
        loadBarangays();
      }).catch(function () { failed(city); });
    }

    function loadProvinces() {
      get({ level: 'province' }, 'province').then(function (data) {
        if (!data) return;
        fill(prov, text.province, data.map(function (p) { return { name: p, value: p }; }), want.province);
        want.province = '';
        loadCities();
      }).catch(function () { failed(prov); });
    }

    prov.addEventListener('change', function () { want.city = ''; want.area = ''; loadCities(); });
    city.addEventListener('change', function () { want.area = ''; loadBarangays(); });
    loadProvinces();
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('.area-picker'), init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
