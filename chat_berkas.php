<?php
/**
 * Unggah berkas data (.xlsx / .csv) untuk dianalisis chat AI. Selalu membalas JSON.
 * Berkas aslinya tidak disimpan; hanya isi tabelnya (maks. 5.000 baris x 40 kolom) yang disimpan di database.
 */
ini_set('display_errors', '0');
ini_set('memory_limit', '256M');
ob_start();
require 'config.php';
require __DIR__ . '/lib/chat_alat.php';
require __DIR__ . '/lib/chat_berkas.php';
set_time_limit(60);

function jawab(array $data, int $kode = 200): never {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($kode);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Berkas ini terlalu besar/rumit untuk dibaca di server (' . basename($e['file']) . ':' . $e['line'] . ').']);
  }
});

if (empty($_SESSION['u'])) jawab(['error' => 'Sesi sudah habis, muat ulang halaman dan masuk lagi.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jawab(['error' => 'Metode tidak didukung.'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) jawab(['error' => 'Token tidak valid, muat ulang halaman.'], 419);
if (app_setting('openrouter_api_key', OPENROUTER_API_KEY) === '') jawab(['error' => 'Fitur chat AI belum diaktifkan.']);

$uid = (int)$_SESSION['u']['id'];
session_write_close();

$f = $_FILES['berkas'] ?? null;
if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) {
  $kode = (int)($f['error'] ?? 0);
  jawab(['error' => in_array($kode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Berkas terlalu besar (maks. 3 MB).' : 'Berkas tidak terkirim, coba lagi.']);
}
if ($f['size'] > BERKAS_MAKS_BYTE) jawab(['error' => 'Berkas terlalu besar (maks. 3 MB). Kecilkan atau ambil sebagian barisnya.']);
$nama = mb_substr(preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', basename((string)$f['name'])), 0, 120);
if (!preg_match('/\.(xlsx|csv|txt)$/i', $nama)) jawab(['error' => 'Jenis berkas tidak didukung. Pakai .xlsx atau .csv.']);

try {
  [$kolom, $baris] = berkas_baca($f['tmp_name'], $nama);
} catch (InvalidArgumentException $e) {
  jawab(['error' => $e->getMessage()]);
} catch (Throwable $e) {
  error_log('SIMDOKIM chat berkas: ' . $e->getMessage());
  jawab(['error' => 'Berkas tidak bisa dibaca. Pastikan file .xlsx/.csv yang valid (bukan .xls lama atau file rusak).']);
}

if (!berkas_siapkan_db($pdo)) jawab(['error' => 'Database tidak mengizinkan membuat tabel penyimpanan berkas.']);
try {
  // bersihkan lampiran yatim (diunggah tapi tidak pernah dipakai) yang lebih dari sehari
  $pdo->exec("DELETE FROM chat_berkas WHERE percakapan_id IS NULL AND dibuat < NOW() - INTERVAL 1 DAY");
  $pdo->prepare('INSERT INTO chat_berkas (user_id, nama, kolom, baris, jml_baris, dibuat) VALUES (?,?,?,?,?,?)')
      ->execute([$uid, $nama, json_encode($kolom, JSON_UNESCAPED_UNICODE), json_encode($baris, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), count($baris), date('Y-m-d H:i:s')]);
  $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
  error_log('SIMDOKIM chat berkas simpan: ' . $e->getMessage());
  jawab(['error' => 'Berkas gagal disimpan (mungkin terlalu besar untuk database). Coba kecilkan.']);
}
jawab(['id' => $id, 'nama' => $nama, 'jumlah_baris' => count($baris), 'jumlah_kolom' => count($kolom), 'kolom' => $kolom, 'terpotong' => count($baris) >= BERKAS_MAKS_BARIS]);
