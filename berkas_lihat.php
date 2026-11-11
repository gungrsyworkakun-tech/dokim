<?php require 'config.php'; login_required();
/**
 * Menyajikan berkas unggahan (BA/BAST dan foto batch) hanya untuk pengguna yang sudah masuk.
 *   berkas_lihat.php?jenis=ba&tipe=penerimaan|pengeluaran&id=<id transaksi>
 *   berkas_lihat.php?jenis=foto&id=<id penerimaan_foto>
 * Folder uploads/ ditutup untuk akses langsung (uploads/.htaccess).
 */
const LIHAT_MIME = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

function lihat_gagal(): never { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Berkas tidak ditemukan.'); }

$jenis = (string)($_GET['jenis'] ?? '');
$id    = (int)($_GET['id'] ?? 0);
$path  = null;

if ($jenis === 'ba') {
  $tipe = (string)($_GET['tipe'] ?? '');
  if (!in_array($tipe, ['penerimaan', 'pengeluaran'], true)) lihat_gagal();
  $s = $pdo->prepare("SELECT file_ba FROM $tipe WHERE id=?"); $s->execute([$id]);
  $f = (string)$s->fetchColumn();
  if ($f !== '') $path = __DIR__ . "/uploads/berita_acara/$tipe/$f";
} elseif ($jenis === 'foto') {
  $s = $pdo->prepare('SELECT penerimaan_id, file FROM penerimaan_foto WHERE id=?'); $s->execute([$id]);
  if ($r = $s->fetch()) $path = __DIR__ . '/uploads/penerimaan/' . (int)$r['penerimaan_id'] . '/' . $r['file'];
}

// nama file di database dibuat server (hex + ekstensi); tolak apa pun yang bentuknya lain
if (!$path || !preg_match('/^[a-f0-9]{16}\.(pdf|jpg|png|webp)$/', basename($path)) || !is_file($path)) lihat_gagal();

session_write_close();
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . LIHAT_MIME[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=300');
readfile($path);
