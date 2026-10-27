<?php
/**
 * Unduh tabel hasil chat AI sebagai Excel (.xlsx) atau CSV.
 * GET chat_ekspor.php?pesan=<id pesan>&tabel=<nomor tabel>&format=xlsx|csv
 * Hanya pemilik percakapan yang bisa mengunduh. Hanya membaca data tersimpan, jadi aman dipanggil lewat tautan biasa.
 */
require 'config.php';
require __DIR__ . '/lib/xlsx_writer.php';

function gagal(string $teks, int $kode = 404): never {
  http_response_code($kode);
  header('Content-Type: text/plain; charset=utf-8');
  exit($teks);
}

if (empty($_SESSION['u'])) gagal('Sesi sudah habis, masuk lagi.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') gagal('Metode tidak didukung.', 405);

$pesanId = (int)($_GET['pesan'] ?? 0);
$idx     = (int)($_GET['tabel'] ?? 0);
$format  = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
$uid     = (int)$_SESSION['u']['id'];
session_write_close();

try {
  $s = $pdo->prepare('SELECT m.tabel FROM chat_pesan m JOIN chat_percakapan p ON p.id=m.percakapan_id WHERE m.id=? AND p.user_id=? AND m.peran=\'assistant\'');
  $s->execute([$pesanId, $uid]);
  $json = $s->fetchColumn();
} catch (Throwable $e) {
  gagal('Data percakapan tidak tersedia.');
}
$tabel = $json ? (json_decode($json, true) ?: []) : [];
if (!isset($tabel[$idx]) || !is_array($tabel[$idx]) || !isset($tabel[$idx]['kolom'])) gagal('Tabel tidak ditemukan.');
$t = $tabel[$idx];
$kolom = array_map('strval', $t['kolom'] ?? []);
$baris = is_array($t['baris'] ?? null) ? $t['baris'] : [];

$dasar = preg_replace('/[^A-Za-z0-9]+/', '_', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($t['judul'] ?? 'data'))) ?: 'data';
$nama = 'SIM-DOKIM_' . trim(substr($dasar, 0, 50), '_') . '_' . date('Ymd');

while (ob_get_level() > 0) ob_end_clean();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($format === 'csv') {
  $isi = csv_buat($kolom, $baris);
  header('Content-Type: text/csv; charset=utf-8');
} else {
  $isi = xlsx_buat((string)($t['judul'] ?? 'Data'), $kolom, $baris);
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
header('Content-Disposition: attachment; filename="' . $nama . '.' . $format . '"');
header('Content-Length: ' . strlen($isi));
echo $isi;