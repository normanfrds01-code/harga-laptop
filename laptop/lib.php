<?php
// Fungsi bersama: model, validasi input, prediksi, riwayat.
const HISTORY_FILE = __DIR__ . '/data/history.json';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function rp(float $n): string { return number_format($n, 0, ',', '.'); }

function load_model(): array {
    static $m = null;
    if ($m === null) {
        $m = json_decode(file_get_contents(__DIR__ . '/model.json'), true);
    }
    return $m;
}

function options(): array {
    return [
        'ram'   => [2, 4, 6, 8, 12, 16, 24, 32, 64],
        'ssd'   => [0, 128, 256, 512, 768, 1024],
        'cpu'   => ['AMD', 'Core i3', 'Core i5', 'Core i7', 'Core M', 'Intel Lainnya', 'Lainnya'],
        'brand' => ['Acer', 'Apple', 'Asus', 'Dell', 'HP', 'Lenovo', 'MSI', 'Toshiba', 'Lainnya'],
        'type'  => ['2 in 1 Convertible', 'Gaming', 'Netbook', 'Notebook', 'Ultrabook', 'Workstation'],
    ];
}

// Ambil input dari $_GET/$_POST dan paksa hanya nilai yang diizinkan (whitelist).
function clean_input(array $src): array {
    $o = options();
    $in = ['ram' => 8, 'ssd' => 256, 'cpu' => 'Core i5', 'brand' => 'Dell', 'type' => 'Notebook', 'kurs' => 19000];
    if (isset($src['ram']) && in_array((int)$src['ram'], $o['ram'], true)) $in['ram'] = (int)$src['ram'];
    if (isset($src['ssd']) && in_array((int)$src['ssd'], $o['ssd'], true)) $in['ssd'] = (int)$src['ssd'];
    if (isset($src['cpu']) && in_array((string)$src['cpu'], $o['cpu'], true)) $in['cpu'] = (string)$src['cpu'];
    if (isset($src['brand']) && in_array((string)$src['brand'], $o['brand'], true)) $in['brand'] = (string)$src['brand'];
    if (isset($src['type']) && in_array((string)$src['type'], $o['type'], true)) $in['type'] = (string)$src['type'];
    $kurs = $src['kurs'] ?? ($_COOKIE['kurs'] ?? $in['kurs']);
    $in['kurs'] = max(1, min(1000000, (int)$kurs));
    return $in;
}

// Harga = intercept + koefisien x nilai fitur (model regresi linear berganda).
function predict(array $in): array {
    $m = load_model();
    $c = $m['coef'];
    $parts = [
        ['RAM ' . $in['ram'] . ' GB',   $in['ram'] * $c['Ram_GB']],
        ['SSD ' . $in['ssd'] . ' GB',   $in['ssd'] * $c['SSD_GB']],
        ['Prosesor ' . $in['cpu'],      $c['Cpu_Family_' . $in['cpu']] ?? 0.0],
        ['Merek ' . $in['brand'],       $c['Company_Group_' . $in['brand']] ?? 0.0],
        ['Tipe ' . $in['type'],         $c['TypeName_' . $in['type']] ?? 0.0],
    ];
    $raw = $m['intercept'];
    foreach ($parts as $p) $raw += $p[1];
    $price = max($raw, $m['min_price']);
    // Dataset berharga euro; semua hasil dikonversi ke rupiah (dibulatkan ke ribuan).
    $k = $in['kurs'];
    $r = fn(float $e): float => round($e * $k / 1000) * 1000;
    return [
        'input'   => $in,
        'kurs'    => $k,
        'base'    => $r($m['intercept']),
        'parts'   => array_map(fn($p) => ['label' => $p[0], 'value' => $r($p[1])], $parts),
        'idr'     => $r($price),
        'low'     => $r(max($price - $m['mae'], $m['min_price'])),
        'high'    => $r($price + $m['mae']),
        'mae'     => $r($m['mae']),
        'persen'  => price_percentile($price),
        'clamped' => $raw < $m['min_price'],
    ];
}

function read_history(): array {
    if (!is_file(HISTORY_FILE)) return [];
    $d = json_decode((string)file_get_contents(HISTORY_FILE), true);
    return is_array($d) ? $d : [];
}

function write_history(array $list): void {
    if (!is_dir(dirname(HISTORY_FILE))) mkdir(dirname(HISTORY_FILE), 0777, true);
    file_put_contents(HISTORY_FILE, json_encode(array_values($list), JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Baca dataset bersih menjadi array asosiatif.
function load_dataset(): array {
    $rows = [];
    if (($f = fopen(__DIR__ . '/laptop_clean.csv', 'r')) === false) return $rows;
    $head = fgetcsv($f, 0, ',', '"', '');
    while (($r = fgetcsv($f, 0, ',', '"', '')) !== false) {
        if (count($r) === count($head)) $rows[] = array_combine($head, $r);
    }
    fclose($f);
    return $rows;
}

// Persentase laptop di dataset yang harganya lebih murah dari nilai ini (euro).
function price_percentile(float $euro): float {
    static $prices = null;
    if ($prices === null) {
        $prices = array_map(fn($r) => (float)$r['Price_euros'], load_dataset());
    }
    if (!$prices) return 0.0;
    $less = 0;
    foreach ($prices as $p) if ($p < $euro) $less++;
    return round($less / count($prices) * 100);
}

function sel(string $id, string $name, array $opts, $cur): string {
    $o = '';
    foreach ($opts as $v) {
        $s = ((string)$cur === (string)$v) ? ' selected' : '';
        $o .= '<option' . $s . '>' . h((string)$v) . '</option>';
    }
    return '<select id="' . h($id) . '" name="' . h($name) . '">' . $o . '</select>';
}
