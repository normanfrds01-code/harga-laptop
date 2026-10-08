<?php
// API kecil (JSON): prediksi, simpan riwayat, lihat riwayat, hapus riwayat.
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'predict';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

switch ($action) {
    case 'predict':
        echo json_encode(predict(clean_input($_GET)), JSON_UNESCAPED_UNICODE);
        break;

    case 'save':
        if (!$isPost) { http_response_code(405); echo json_encode(['error' => 'Gunakan POST']); break; }
        $res = predict(clean_input($_POST));
        $list = read_history();
        array_unshift($list, [
            'time'  => date('d-m-Y H:i:s'),
            'ram'   => $res['input']['ram'],
            'ssd'   => $res['input']['ssd'],
            'cpu'   => $res['input']['cpu'],
            'brand' => $res['input']['brand'],
            'type'  => $res['input']['type'],
            'kurs'  => $res['kurs'],
            'idr'   => $res['idr'],
        ]);
        write_history(array_slice($list, 0, 50));
        echo json_encode(['ok' => true, 'history' => read_history()], JSON_UNESCAPED_UNICODE);
        break;

    case 'history':
        echo json_encode(read_history(), JSON_UNESCAPED_UNICODE);
        break;

    case 'clear':
        if (!$isPost) { http_response_code(405); echo json_encode(['error' => 'Gunakan POST']); break; }
        write_history([]);
        echo json_encode(['ok' => true, 'history' => []]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Aksi tidak dikenal']);
}
