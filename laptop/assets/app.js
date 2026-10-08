(function () {
'use strict';
var $ = function (i) { return document.getElementById(i); };
var fmt = function (n) { return Math.round(n).toLocaleString('id-ID'); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var jt = function (n) { var v = n / 1e6; return (v >= 10 ? Math.round(v) : Math.round(v * 10) / 10).toString().replace('.', ',') + ' jt'; };
var APP = window.APP || {};

/* ---------- Kurs (disimpan di cookie, dipakai semua tab) ---------- */
function kursValue() { return Math.max(1, parseInt($('kurs').value, 10) || 19000); }
function saveKurs() { document.cookie = 'kurs=' + kursValue() + '; path=/; max-age=31536000'; }

/* ---------- Latar bintang ---------- */
(function () {
  var c = $('stars'); if (!c) return;
  var x = c.getContext('2d'), W, H, S = [];
  var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function size() {
    W = c.width = innerWidth; H = c.height = innerHeight; S = [];
    var n = Math.min(220, Math.floor(W * H / 9000));
    for (var i = 0; i < n; i++) S.push({x: Math.random() * W, y: Math.random() * H, r: Math.random() * 1.3 + .2, p: Math.random() * 6.28, s: Math.random() * .02 + .006, d: Math.random() * .12 + .02});
  }
  function draw(t) {
    x.clearRect(0, 0, W, H);
    S.forEach(function (s) {
      x.globalAlpha = still ? .7 : .3 + .7 * Math.abs(Math.sin(s.p + t * s.s * .06));
      x.fillStyle = s.r > 1 ? '#bfe7ff' : '#ffffff';
      x.beginPath(); x.arc(s.x, s.y, s.r, 0, 6.283); x.fill();
      if (!still) { s.y += s.d * .25; if (s.y > H) { s.y = 0; s.x = Math.random() * W; } }
    });
    x.globalAlpha = 1;
    if (!still) requestAnimationFrame(draw);
  }
  size(); addEventListener('resize', size); requestAnimationFrame(draw);
})();

/* ---------- Kurs: ubah lalu muat ulang (atau hitung ulang di tab interaktif) ---------- */
var live = null;
$('kurs').addEventListener('input', function () { saveKurs(); if (live) live(); });
$('kurs').addEventListener('change', function () { saveKurs(); if (!live) location.reload(); });

/* ---------- Tab Prediksi ---------- */
var form = $('f');
if (form) {
  var show = function (d) {
    $('idr').textContent = 'Rp ' + fmt(d.idr);
    $('rng').textContent = 'Kisaran wajar: Rp ' + fmt(d.low) + ' sampai Rp ' + fmt(d.high);
    $('dot').style.left = d.persen + '%';
    $('pct').innerHTML = 'Lebih mahal dari <b>' + d.persen + '%</b> laptop di dataset.';
    $('warn').textContent = d.clamped ? 'Spesifikasi ini di bawah rentang data, jadi hasil dibatasi ke harga termurah di dataset.' : '';
    $('why').innerHTML = 'Rincian (Rp):<br>Dasar ' + fmt(d.base) + d.parts.map(function (p) { return '<br>' + esc(p.label) + ': ' + (p.value >= 0 ? '+' : '') + fmt(p.value); }).join('');
  };
  var qs = function () { var p = new URLSearchParams(new FormData(form)); p.set('kurs', kursValue()); return p.toString(); };
  live = function () {
    fetch('predict.php?action=predict&' + qs()).then(function (r) { return r.json(); }).then(show);
    history.replaceState(null, '', '?' + qs());
  };
  form.addEventListener('input', live);
  form.addEventListener('submit', function (e) { e.preventDefault(); live(); });
  $('save').addEventListener('click', function () {
    var fd = new FormData(form); fd.set('kurs', kursValue());
    fetch('predict.php?action=save', {method: 'POST', body: fd}).then(function (r) { return r.json(); }).then(function (d) {
      $('saved').textContent = d.ok ? 'Tersimpan ke riwayat (' + d.history.length + ' data).' : 'Gagal menyimpan.';
    });
  });
}

/* ---------- Tab Bandingkan ---------- */
if ($('cmp')) {
  var ids = ['ram', 'ssd', 'cpu', 'brand', 'type'], names = ['RAM', 'SSD', 'Prosesor', 'Merek', 'Tipe'];
  var params = function (s) { var p = new URLSearchParams(); ids.forEach(function (k) { p.set(k, $(s + '_' + k).value); }); p.set('kurs', kursValue()); return p.toString(); };
  var get = function (s) { return fetch('predict.php?action=predict&' + params(s)).then(function (r) { return r.json(); }); };
  live = function () {
    Promise.all([get('a'), get('b')]).then(function (r) {
      var a = r[0], b = r[1];
      $('a_price').textContent = 'Rp ' + fmt(a.idr); $('b_price').textContent = 'Rp ' + fmt(b.idr);
      $('a_pct').textContent = 'Lebih mahal dari ' + a.persen + '% laptop di dataset'; $('b_pct').textContent = 'Lebih mahal dari ' + b.persen + '% laptop di dataset';
      var diff = b.idr - a.idr, pc = a.idr ? diff / a.idr * 100 : 0;
      $('verdict').innerHTML = diff === 0 ? 'Harga keduanya sama.' : (diff > 0 ? '<span class="tagB">Laptop B</span>' : '<span class="tagA">Laptop A</span>') + ' lebih mahal <b>Rp ' + fmt(Math.abs(diff)) + '</b> (' + Math.abs(pc).toFixed(1).replace('.', ',') + '%)';
      var d = a.parts.map(function (p, i) { return b.parts[i].value - p.value; });
      var mx = Math.max.apply(null, d.map(Math.abs).concat([1]));
      $('ctable').innerHTML = '<tr><th>Faktor</th><th class="tagA">A</th><th class="tagB">B</th><th>Selisih (B - A)</th></tr>' +
        a.parts.map(function (p, i) {
          var v = d[i];
          return '<tr><td>' + names[i] + '</td><td>' + esc(p.label.replace(/^[^ ]+ /, '')) + ': ' + fmt(p.value) + '</td><td>' + esc(b.parts[i].label.replace(/^[^ ]+ /, '')) + ': ' + fmt(b.parts[i].value) + '</td><td class="' + (v >= 0 ? 'pos' : 'negt') + '">' + (v >= 0 ? '+' : '') + fmt(v) +
            '<div class="track" style="margin-top:4px;height:6px;background:rgba(255,255,255,.07);border-radius:4px"><div style="height:100%;width:' + (Math.abs(v) / mx * 100) + '%;border-radius:4px;background:' + (v >= 0 ? 'var(--cy)' : 'var(--pk)') + '"></div></div></td></tr>';
        }).join('') +
        (a.clamped || b.clamped ? '<tr><td colspan="4" class="sm">Salah satu spesifikasi di bawah rentang data, sehingga harganya dibatasi ke harga termurah di dataset.</td></tr>' : '');
    });
  };
  $('cmp').addEventListener('input', live);
  $('swap').addEventListener('click', function () {
    ids.forEach(function (k) { var t = $('a_' + k).value; $('a_' + k).value = $('b_' + k).value; $('b_' + k).value = t; });
    live();
  });
  live();
}

/* ---------- Tab Grafik ---------- */
var CH = APP.chart;
function niceStep(v) { var e = Math.pow(10, Math.floor(Math.log10(v))), m = v / e; return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * e; }
function showTip(box, tip, html, ev) {
  var r = box.getBoundingClientRect();
  tip.innerHTML = html; tip.style.display = 'block';
  var x = ev.clientX - r.left + 14, y = ev.clientY - r.top + 14;
  if (x + tip.offsetWidth > r.width) x = ev.clientX - r.left - tip.offsetWidth - 14;
  tip.style.left = Math.max(0, x) + 'px'; tip.style.top = Math.max(0, y) + 'px';
}
if (CH) {
  var K = CH.kurs, colors = {'2 in 1 Convertible': '#5ee1ff', 'Gaming': '#ff7ad9', 'Netbook': '#ffc46b', 'Notebook': '#a78bfa', 'Ultrabook': '#6ee7b7', 'Workstation': '#ff8a5c'};
  var off = {};

  /* Scatter aktual vs prediksi */
  var sbox = $('scatterbox'), stip = document.createElement('div'); stip.className = 'tip'; sbox.appendChild(stip);
  var drawScatter = function () {
    var W = 700, H = 440, L = 62, R = 16, T = 14, B = 46;
    var pts = CH.pts.filter(function (p) { return !off[p.t]; });
    var all = CH.pts, mxv = Math.max.apply(null, all.map(function (p) { return Math.max(p.a, p.p) * K; }));
    var st = niceStep(mxv / 5), mx = Math.ceil(mxv / st) * st;
    var X = function (v) { return L + v / mx * (W - L - R); }, Y = function (v) { return H - B - v / mx * (H - T - B); };
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Harga aktual melawan harga prediksi">';
    for (var v = 0; v <= mx + 1; v += st) {
      s += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" stroke="rgba(150,170,255,.15)"/>';
      s += '<text x="' + (L - 8) + '" y="' + (Y(v) + 4) + '" fill="#9aa3c7" font-size="11" text-anchor="end">' + jt(v) + '</text>';
      s += '<text x="' + X(v) + '" y="' + (H - B + 18) + '" fill="#9aa3c7" font-size="11" text-anchor="middle">' + jt(v) + '</text>';
    }
    s += '<text x="' + (L + (W - L - R) / 2) + '" y="' + (H - 6) + '" fill="#9aa3c7" font-size="12" text-anchor="middle">Harga aktual</text>';
    s += '<text transform="translate(14 ' + (T + (H - T - B) / 2) + ') rotate(-90)" fill="#9aa3c7" font-size="12" text-anchor="middle">Harga prediksi</text>';
    s += '<line x1="' + X(0) + '" y1="' + Y(0) + '" x2="' + X(mx) + '" y2="' + Y(mx) + '" stroke="#ffc46b" stroke-dasharray="6 5" stroke-width="1.5"/>';
    pts.forEach(function (p) {
      var i = CH.pts.indexOf(p);
      s += '<circle data-i="' + i + '" cx="' + X(p.a * K) + '" cy="' + Y(p.p * K) + '" r="4.5" fill="' + (colors[p.t] || '#fff') + '" fill-opacity=".72" stroke="#fff" stroke-opacity=".25"/>';
    });
    sbox.querySelector('svg') && sbox.removeChild(sbox.querySelector('svg'));
    sbox.insertAdjacentHTML('afterbegin', s + '</svg>');
  };
  sbox.addEventListener('pointermove', function (e) {
    var t = e.target;
    if (t.tagName === 'circle' && t.dataset.i !== undefined) {
      var p = CH.pts[+t.dataset.i], d = (p.p - p.a) * K;
      showTip(sbox, stip, '<b>' + esc(p.b) + '</b> · ' + esc(p.t) + '<br>Aktual: Rp ' + fmt(p.a * K) + '<br>Prediksi: Rp ' + fmt(p.p * K) + '<br>Selisih: ' + (d >= 0 ? '+' : '') + fmt(d), e);
    } else stip.style.display = 'none';
  });
  sbox.addEventListener('pointerleave', function () { stip.style.display = 'none'; });
  var types = Object.keys(colors), cnt = {};
  CH.pts.forEach(function (p) { cnt[p.t] = (cnt[p.t] || 0) + 1; });
  $('legend').innerHTML = types.filter(function (t) { return cnt[t]; }).map(function (t) {
    return '<button type="button" data-t="' + esc(t) + '"><span class="dot" style="background:' + colors[t] + '"></span>' + esc(t) + ' (' + cnt[t] + ')</button>';
  }).join('');
  $('legend').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var t = b.getAttribute('data-t'); off[t] = !off[t]; b.classList.toggle('off', !!off[t]); drawScatter();
  });
  drawScatter();

  /* Histogram sebaran harga */
  var hbox = $('histbox'), htip = document.createElement('div'); htip.className = 'tip'; hbox.appendChild(htip);
  var hb = $('hbrand');
  CH.brands.forEach(function (b) { hb.insertAdjacentHTML('beforeend', '<option>' + esc(b) + '</option>'); });
  var drawHist = function () {
    var n = +$('hbins').value, br = hb.value; $('hbinsv').textContent = n;
    var v = CH.prices.filter(function (p) { return !br || p[1] === br; }).map(function (p) { return p[0] * K; }).sort(function (a, b) { return a - b; });
    var W = 700, H = 360, L = 52, R = 12, T = 12, B = 40;
    if (!v.length) { hbox.querySelector('svg') && hbox.removeChild(hbox.querySelector('svg')); $('hstat').textContent = 'Tidak ada data.'; return; }
    var mx = v[v.length - 1], w = mx / n, bins = [];
    for (var i = 0; i < n; i++) bins.push(0);
    v.forEach(function (x) { bins[Math.min(n - 1, Math.floor(x / w))]++; });
    var top = Math.max.apply(null, bins), st = niceStep(top / 4), ym = Math.ceil(top / st) * st;
    var X = function (q) { return L + q / mx * (W - L - R); }, Y = function (q) { return H - B - q / ym * (H - T - B); };
    var mean = v.reduce(function (a, b) { return a + b; }, 0) / v.length, med = v[Math.floor(v.length / 2)];
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Sebaran harga laptop"><defs><linearGradient id="hg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5ee1ff"/><stop offset="1" stop-color="#a78bfa"/></linearGradient></defs>';
    for (var y = 0; y <= ym; y += st) {
      s += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + Y(y) + '" y2="' + Y(y) + '" stroke="rgba(150,170,255,.15)"/><text x="' + (L - 8) + '" y="' + (Y(y) + 4) + '" fill="#9aa3c7" font-size="11" text-anchor="end">' + y + '</text>';
    }
    for (var t = 0; t <= 5; t++) s += '<text x="' + X(mx * t / 5) + '" y="' + (H - B + 18) + '" fill="#9aa3c7" font-size="11" text-anchor="middle">' + jt(mx * t / 5) + '</text>';
    bins.forEach(function (c, i) {
      s += '<rect data-i="' + i + '" x="' + (X(i * w) + 1) + '" y="' + Y(c) + '" width="' + Math.max(1, X(w) - X(0) - 2) + '" height="' + (Y(0) - Y(c)) + '" rx="3" fill="url(#hg)" fill-opacity=".85"/>';
    });
    s += '<line x1="' + X(mean) + '" x2="' + X(mean) + '" y1="' + T + '" y2="' + (H - B) + '" stroke="#ffc46b" stroke-dasharray="5 4"/><text x="' + (X(mean) + 6) + '" y="' + (T + 12) + '" fill="#ffc46b" font-size="11">rata-rata</text>';
    s += '<text x="' + (L + (W - L - R) / 2) + '" y="' + (H - 4) + '" fill="#9aa3c7" font-size="12" text-anchor="middle">Harga (Rp)</text></svg>';
    hbox.querySelector('svg') && hbox.removeChild(hbox.querySelector('svg'));
    hbox.insertAdjacentHTML('afterbegin', s);
    hbox._bins = bins; hbox._w = w;
    $('hstat').innerHTML = '<b>' + v.length + '</b> laptop · rata-rata <b>Rp ' + fmt(mean) + '</b> · median <b>Rp ' + fmt(med) + '</b> · termurah Rp ' + fmt(v[0]) + ' · termahal Rp ' + fmt(mx);
  };
  hbox.addEventListener('pointermove', function (e) {
    var t = e.target;
    if (t.tagName === 'rect' && t.dataset.i !== undefined) {
      var i = +t.dataset.i;
      showTip(hbox, htip, 'Rp ' + jt(i * hbox._w) + ' sampai ' + jt((i + 1) * hbox._w) + '<br><b>' + hbox._bins[i] + '</b> laptop', e);
    } else htip.style.display = 'none';
  });
  hbox.addEventListener('pointerleave', function () { htip.style.display = 'none'; });
  hb.addEventListener('input', drawHist); $('hbins').addEventListener('input', drawHist);
  drawHist();
}

/* ---------- Tab Riwayat ---------- */
var hist = $('hist');
if (hist) {
  var renderHist = function (list) {
    hist.innerHTML = '<tr><th>Waktu</th><th>Spesifikasi</th><th>Harga (Rp)</th></tr>' + list.map(function (x) {
      return '<tr><td>' + esc(x.time) + '</td><td>' + esc(x.brand) + ' ' + esc(x.type) + ', ' + esc(x.cpu) + ', RAM ' + esc(x.ram) + ' GB, SSD ' + esc(x.ssd) + ' GB</td><td>Rp ' + fmt(x.idr) + '</td></tr>';
    }).join('');
    $('empty').style.display = list.length ? 'none' : 'block';
  };
  renderHist(APP.hist || []);
  $('clear').addEventListener('click', function () {
    if (!confirm('Hapus semua riwayat?')) return;
    fetch('predict.php?action=clear', {method: 'POST'}).then(function (r) { return r.json(); }).then(function () { renderHist([]); });
  });
}
})();
