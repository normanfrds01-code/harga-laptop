<?php
require __DIR__ . '/lib.php';

$model = load_model();
$opt   = options();
$in    = clean_input($_GET);
$kurs  = $in['kurs'];
$res   = predict($in);
$tabs  = ['prediksi' => 'Prediksi', 'bandingkan' => 'Bandingkan', 'grafik' => 'Grafik', 'model' => 'Model', 'data' => 'Data dan merek', 'riwayat' => 'Riwayat'];
$tab   = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'prediksi';

$data = load_dataset();

// --- Rata-rata per merek (tab Data) ---
$stat = [];
foreach ($data as $r) {
    $k = $r['Company'];
    $stat[$k]['n']   = ($stat[$k]['n'] ?? 0) + 1;
    $stat[$k]['sum'] = ($stat[$k]['sum'] ?? 0) + (float)$r['Price_euros'];
}
$brands = [];
foreach ($stat as $k => $s) if ($s['n'] >= 10) $brands[$k] = ['n' => $s['n'], 'avg' => $s['sum'] / $s['n'] * $kurs];
uasort($brands, fn($a, $b) => $b['avg'] <=> $a['avg']);
$maxAvg    = $brands ? max(array_column($brands, 'avg')) : 1;
$allBrands = array_keys($stat); sort($allBrands);
$filter  = $_GET['merek'] ?? '';
$rows    = ($filter !== '' && isset($stat[$filter])) ? array_values(array_filter($data, fn($r) => $r['Company'] === $filter)) : $data;
$perPage = 15;
$pages   = max(1, (int)ceil(count($rows) / $perPage));
$page    = max(1, min($pages, (int)($_GET['hal'] ?? 1)));
$slice   = array_slice($rows, ($page - 1) * $perPage, $perPage);
$avgSel  = $rows ? array_sum(array_map(fn($r) => (float)$r['Price_euros'], $rows)) / count($rows) * $kurs : 0;

function dataLink(string $merek, int $hal): string { return '?' . http_build_query(['tab' => 'data', 'merek' => $merek, 'hal' => $hal]); }
function label(string $k): string { return str_replace(['Company_Group_', 'Cpu_Family_', 'TypeName_'], ['Merek ', 'CPU ', 'Tipe '], $k); }

$coefs = array_filter($model['coef'], fn($k) => $k !== 'Ram_GB' && $k !== 'SSD_GB', ARRAY_FILTER_USE_KEY);
uasort($coefs, fn($a, $b) => abs($b) <=> abs($a));
$coefs   = array_slice($coefs, 0, 10, true);
$maxCoef = max(array_map('abs', $coefs));

// --- Data untuk grafik (tab Grafik) ---
$chart = null;
if ($tab === 'grafik') {
    $pts = json_decode((string)file_get_contents(__DIR__ . '/testpred.json'), true) ?: [];
    $chart = [
        'kurs'   => $kurs,
        'pts'    => $pts,
        'prices' => array_map(fn($r) => [(float)$r['Price_euros'], $r['Company']], $data),
        'brands' => $allBrands,
    ];
}
$A = ['ram' => 8,  'ssd' => 256, 'cpu' => 'Core i5', 'brand' => 'Dell',  'type' => 'Notebook'];
$B = ['ram' => 16, 'ssd' => 512, 'cpu' => 'Core i7', 'brand' => 'Apple', 'type' => 'Ultrabook'];
$fields = ['ram' => 'RAM (GB)', 'ssd' => 'SSD (GB)', 'cpu' => 'Prosesor', 'brand' => 'Merek', 'type' => 'Tipe'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Semesta Harga Laptop</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<canvas id="stars" aria-hidden="true"></canvas>
<div class="wrap">
<header>
<h1>✦ Semesta Harga Laptop</h1>
<p class="sub">Prediksi harga dengan regresi linear berganda · <?= count($data) ?> laptop · Tugas Data Mining</p>
</header>

<div class="card kurs">
<label for="kurs">Kurs: Rp per 1 euro. Dataset berharga euro, lalu dikonversi ke rupiah (ubah sesuai kurs hari ini).</label>
<input type="number" id="kurs" value="<?= (int)$kurs ?>" min="1" style="max-width:200px">
</div>

<nav class="tabs">
<?php foreach ($tabs as $k => $v): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= h($v) ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'prediksi'): ?>
<form id="f" method="get" class="grid">
<input type="hidden" name="tab" value="prediksi">
<div class="card">
<h2>Pilih spesifikasi</h2>
<?php foreach ($fields as $name => $lab): ?>
<label for="<?= $name ?>"><?= h($lab) ?></label><?= sel($name, $name, $opt[$name], $in[$name]) ?>
<?php endforeach; ?>
</div>
<div>
<div class="out">
<div class="sm">Perkiraan harga (dihitung oleh PHP)</div>
<div class="big" id="idr">Rp <?= rp($res['idr']) ?></div>
<div class="sm" id="rng">Kisaran wajar: Rp <?= rp($res['low']) ?> sampai Rp <?= rp($res['high']) ?></div>
<div class="orbit"><i id="dot" style="left:<?= (int)$res['persen'] ?>%"></i></div>
<div class="sm" id="pct">Lebih mahal dari <b><?= (int)$res['persen'] ?>%</b> laptop di dataset.</div>
<div class="sm" id="warn"><?= $res['clamped'] ? 'Spesifikasi ini di bawah rentang data, jadi hasil dibatasi ke harga termurah di dataset.' : '' ?></div>
<hr style="border:0;border-top:1px solid var(--line);width:100%">
<div class="sm" id="why">Rincian (Rp):<br>Dasar <?= rp($res['base']) ?><?php foreach ($res['parts'] as $p): ?><br><?= h($p['label']) ?>: <?= ($p['value'] >= 0 ? '+' : '') . rp($p['value']) ?><?php endforeach; ?></div>
</div>
<p style="margin-top:12px"><button type="button" id="save">Simpan ke riwayat</button> <a class="btn ghost" href="?tab=riwayat">Lihat riwayat</a> <a class="btn ghost" href="?tab=bandingkan">Bandingkan</a></p>
<p class="note" id="saved"></p>
<p class="note">Perkiraan dari model, bukan harga pasar. Rata-rata kesalahan model sekitar Rp <?= rp($res['mae']) ?> pada data uji. Angka dibulatkan ke ribuan.</p>
</div>
</form>

<?php elseif ($tab === 'bandingkan'): ?>
<div class="vs" id="cmp">
<?php foreach (['a' => ['Laptop A', $A, 'tagA'], 'b' => ['Laptop B', $B, 'tagB']] as $s => [$title, $def, $cls]): ?>
<div class="card side">
<h3 class="<?= $cls ?>"><?= $title ?></h3>
<?php foreach ($fields as $name => $lab): ?>
<label for="<?= $s . '_' . $name ?>"><?= h($lab) ?></label><?= sel($s . '_' . $name, $s . '_' . $name, $opt[$name], $def[$name]) ?>
<?php endforeach; ?>
<div class="big" id="<?= $s ?>_price" style="margin-top:16px">-</div>
<div class="sm" id="<?= $s ?>_pct"></div>
</div>
<?php if ($s === 'a'): ?>
<div class="mid"><button type="button" id="swap" class="ghost" title="Tukar A dan B">⇄ Tukar</button></div>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="card">
<p class="verdict" id="verdict">-</p>
<div class="scroll"><table id="ctable"></table></div>
<p class="note">Selisih dihitung dari koefisien model pada tiap faktor. Nilai positif berarti laptop B lebih mahal pada faktor itu.</p>
</div>

<?php elseif ($tab === 'grafik'): ?>
<div class="card">
<h2>Harga aktual vs prediksi (data uji)</h2>
<div class="chartbox" id="scatterbox"></div>
<div class="legend" id="legend"></div>
<p class="note">Setiap titik adalah satu laptop dari 261 data uji. Titik yang dekat garis putus-putus berarti prediksinya akurat. Klik tipe laptop untuk menyembunyikan atau menampilkannya, arahkan kursor ke titik untuk melihat detail.</p>
</div>
<div class="card">
<h2>Sebaran harga laptop</h2>
<div class="ctl">
<div><label for="hbrand">Merek</label><select id="hbrand"><option value="">Semua merek</option></select></div>
<div><label for="hbins">Jumlah kelompok harga: <b id="hbinsv">20</b></label><input type="range" id="hbins" min="5" max="40" value="20"></div>
</div>
<div class="chartbox" id="histbox"></div>
<p class="sm" id="hstat"></p>
</div>

<?php elseif ($tab === 'model'): ?>
<div class="card"><h2>Evaluasi pada data uji (261 laptop)</h2>
<div class="scroll"><table><tr><th>Model</th><th>R²</th><th>MAE (Rp)</th><th>RMSE (Rp)</th></tr>
<?php foreach ($model['metrics'] as $m): ?><tr><td><?= h($m['model']) ?></td><td><?= number_format($m['r2'], 3, ',', '.') ?></td><td><?= rp($m['mae'] * $kurs) ?></td><td><?= rp($m['rmse'] * $kurs) ?></td></tr><?php endforeach; ?>
</table></div>
<p class="note">Akurasi hampir setara, jadi regresi linear dipilih sebagai model utama karena koefisiennya mudah ditafsirkan.</p></div>
<div class="card"><h2>Pengaruh tiap faktor terhadap harga (koefisien)</h2>
<?php foreach ($coefs as $k => $v): ?>
<div class="row"><span><?= h(label($k)) ?></span><div class="track"><div class="fill <?= $v < 0 ? 'neg' : '' ?>" style="width:<?= abs($v) / $maxCoef * 100 ?>%"></div></div><span><?= ($v >= 0 ? '+' : '') . rp($v * $kurs) ?></span></div>
<?php endforeach; ?>
<div class="row"><span>RAM</span><div></div><span>+<?= rp($model['coef']['Ram_GB'] * $kurs) ?> / GB</span></div>
<div class="row"><span>SSD</span><div></div><span>+<?= rp($model['coef']['SSD_GB'] * $kurs) ?> / GB</span></div>
<p class="note">Nilai dalam rupiah (dikonversi dari euro dengan kurs di atas), dengan fitur lain tetap. Pembanding: merek Acer, tipe 2 in 1 Convertible, CPU AMD. Intercept: Rp <?= rp($model['intercept'] * $kurs) ?>.</p></div>

<?php elseif ($tab === 'data'): ?>
<div class="card"><h2>Harga rata-rata per merek (Rp)</h2>
<?php foreach ($brands as $k => $b): ?>
<div class="row"><span><a href="<?= h(dataLink($k, 1)) ?>"><?= h($k) ?></a> (<?= $b['n'] ?>)</span><div class="track"><div class="fill" style="width:<?= $b['avg'] / $maxAvg * 100 ?>%"></div></div><span><?= rp($b['avg']) ?></span></div>
<?php endforeach; ?>
<p class="note">Hanya merek dengan minimal 10 data. Klik nama merek untuk melihat laptopnya. Rata-rata belum memperhitungkan perbedaan spesifikasi.</p></div>
<div class="card"><h2>Dataset (<?= count($rows) ?> baris<?= $filter !== '' ? ', merek ' . h($filter) . ', rata-rata Rp ' . rp($avgSel) : '' ?>)</h2>
<form method="get"><input type="hidden" name="tab" value="data">
<label for="merek">Filter merek</label>
<select id="merek" name="merek" onchange="this.form.submit()">
<option value="">Semua merek</option>
<?php foreach ($allBrands as $b): ?><option <?= $filter === $b ? 'selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?>
</select></form>
<div class="scroll" style="margin-top:12px"><table><tr><th>Merek</th><th>Tipe</th><th>CPU</th><th>RAM (GB)</th><th>SSD (GB)</th><th>Harga (Rp)</th></tr>
<?php foreach ($slice as $r): ?><tr><td><?= h($r['Company']) ?></td><td><?= h($r['TypeName']) ?></td><td><?= h($r['Cpu_Family']) ?></td><td><?= h($r['Ram_GB']) ?></td><td><?= h($r['SSD_GB']) ?></td><td><?= rp((float)$r['Price_euros'] * $kurs) ?></td></tr><?php endforeach; ?>
</table></div>
<div class="pager">
<?php if ($page > 1): ?><a class="btn ghost" href="<?= h(dataLink($filter, $page - 1)) ?>">Sebelumnya</a><?php endif; ?>
<span class="note" style="margin:0">Halaman <?= $page ?> dari <?= $pages ?></span>
<?php if ($page < $pages): ?><a class="btn ghost" href="<?= h(dataLink($filter, $page + 1)) ?>">Berikutnya</a><?php endif; ?>
</div></div>

<?php else: ?>
<div class="card"><h2>Riwayat prediksi</h2>
<div class="scroll"><table id="hist"></table></div>
<p class="note" id="empty">Belum ada riwayat. Simpan prediksi dari tab Prediksi.</p>
<p><button class="ghost" id="clear" type="button">Hapus semua riwayat</button></p>
<p class="note">Riwayat disimpan di file <code>data/history.json</code> pada server (maksimal 50 terakhir).</p></div>
<?php endif; ?>
</div>

<script>
window.APP = <?= json_encode(['hist' => read_history(), 'chart' => $chart], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>
