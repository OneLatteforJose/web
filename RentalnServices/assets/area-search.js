// Search box for cities and municipalities. Typing 2+ letters lists matches from api/areas.php?level=search;
// each result is a plain link (data-target + the city key), so it also works with Tab and Enter.
// Arrow keys move between the box and the results, Escape closes the list.
(function () {
  'use strict';
  function init(root) {
    var api = root.getAttribute('data-api');
    var target = root.getAttribute('data-target');
    var input = root.querySelector('input');
    var list = root.querySelector('ul');
    var status = root.querySelector('[data-status]');
    var timer = null, seq = 0;

    function close() { list.hidden = true; list.innerHTML = ''; }
    function say(text) { if (status) status.textContent = text; }

    function render(items) {
      list.innerHTML = '';
      if (!items.length) {
        var none = document.createElement('li');
        none.className = 'none';
        none.textContent = 'No city or municipality matches that.';
        list.appendChild(none);
        say('No matches');
      } else {
        items.forEach(function (it) {
          var li = document.createElement('li');
          var a = document.createElement('a');
          a.href = target + encodeURIComponent(it.key);
          a.textContent = it.name;
          var small = document.createElement('small');
          small.textContent = it.province;
          a.appendChild(small);
          li.appendChild(a);
          list.appendChild(li);
        });
        say(items.length + (items.length === 1 ? ' match' : ' matches') + ' found');
      }
      list.hidden = false;
    }

    function search() {
      var q = input.value.trim();
      if (q.length < 2) { close(); say(''); return; }
      var n = ++seq;
      fetch(api + '?' + new URLSearchParams({ level: 'search', q: q }), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('bad response'); return r.json(); })
        .then(function (items) { if (n === seq) render(items); })
        .catch(function () { if (n === seq) { close(); say('Search is not available right now.'); } });
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 200); });
    input.addEventListener('focus', function () { if (list.children.length) list.hidden = false; });
    root.addEventListener('keydown', function (e) {
      var links = Array.prototype.slice.call(list.querySelectorAll('a'));
      var i = links.indexOf(document.activeElement);
      if (e.key === 'ArrowDown' && links.length) { e.preventDefault(); (links[i + 1] || links[0]).focus(); }
      else if (e.key === 'ArrowUp' && i >= 0) { e.preventDefault(); (i === 0 ? input : links[i - 1]).focus(); }
      else if (e.key === 'Escape') { close(); input.focus(); }
    });
    document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });
  }
  function start() { Array.prototype.forEach.call(document.querySelectorAll('.area-search'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
