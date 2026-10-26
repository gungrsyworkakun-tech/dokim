<?php
/**
 * Backend untuk widget chat AI (pojok kanan bawah di semua halaman setelah login).
 * Dipanggil lewat fetch() dari assets/chat_ai.js, selalu balas JSON.
 * Konteks data aplikasi (stok, penerimaan/pengeluaran terbaru) diambil ulang dari
 * database tiap kali ada pertanyaan, supaya jawabannya selalu berdasar data terkini
 * — bukan ditebak-tebak oleh AI-nya sendiri.
 */
ini_set('display_errors', '0');   // warning/notice PHP jangan sampai ikut tercetak dan merusak JSON
ob_start();
require 'config.php';
set_time_limit(60);

function jawab(array $data, int $kode = 200): never {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($kode);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// Kalau ada error fatal PHP, tetap balas JSON berisi penyebabnya (bukan halaman kosong/HTML).
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['error' => 'Error di server: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  }
});

if (empty($_SESSION['u'])) jawab(['error' => 'Sesi sudah habis, muat ulang halaman dan masuk lagi.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jawab(['error' => 'Metode tidak didukung.'], 405);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !hash_equals($_SESSION['csrf'] ?? '', (string)($body['csrf'] ?? ''))) jawab(['error' => 'Token tidak valid, muat ulang halaman.'], 419);

$apiKey  = app_setting('openrouter_api_key', OPENROUTER_API_KEY);
$model   = app_setting('openrouter_model', OPENROUTER_MODEL);
$baseUrl = rtrim(app_setting('openrouter_base_url', OPENROUTER_BASE_URL), '/');
if ($apiKey === '') jawab(['error' => 'Fitur chat AI belum diaktifkan. Minta superadmin mengisi API key di halaman Pengaturan.']);

$pesan = trim((string)($body['pesan'] ?? ''));
if ($pesan === '') jawab(['error' => 'Pertanyaan kosong.']);
if (mb_strlen($pesan) > 600) jawab(['error' => 'Pertanyaannya kepanjangan, coba dipersingkat (maks. 600 karakter).']);

// Batas pemakaian per sesi login, jaga-jaga supaya tidak kebablasan boros kalau ada yang nyoba-nyoba terus-menerus.
const CHAT_MAKS_PER_SESI = 80;
$_SESSION['chat_hitung'] = ($_SESSION['chat_hitung'] ?? 0) + 1;
if ($_SESSION['chat_hitung'] > CHAT_MAKS_PER_SESI) jawab(['error' => 'Sudah mencapai batas tanya-jawab untuk sesi login ini. Muat ulang/masuk lagi untuk lanjut.']);

// Riwayat percakapan dikirim dari browser (disimpan sementara di memori halaman, bukan di server).
$riwayat = is_array($body['riwayat'] ?? null) ? $body['riwayat'] : [];
$riwayat = array_slice($riwayat, -10);   // batasi biar tidak makin lama makin mahal

/* ============================================================
 * Ambil cuplikan data terkini dari database sebagai konteks AI
 * ============================================================ */
function konteks_aman(callable $f, string $fallback): string {
  try { return $f(); } catch (Throwable $e) { return $fallback; }
}

function konteks_data(PDO $pdo): string {
  $baris = ["Tanggal hari ini: " . date('Y-m-d (l)'), ''];

  $baris[] = konteks_aman(function () use ($pdo) {
    $dok = $pdo->query('SELECT kode,nama,satuan,stok,rusak,terbit FROM dokumen ORDER BY nama')->fetchAll();
    $o = ["=== Stok semua jenis dokumen ===", "kode | nama | satuan | stok | rusak | terbit | status"];
    foreach ($dok as $d) {
      $status = $d['stok'] <= STOK_KRITIS ? 'KRITIS' : ($d['stok'] <= STOK_RENDAH ? 'rendah' : 'aman');
      $o[] = "{$d['kode']} | {$d['nama']} | {$d['satuan']} | {$d['stok']} | {$d['rusak']} | {$d['terbit']} | $status";
    }
    return implode("\n", $o);
  }, '(data stok tidak bisa dibaca)');

  $baris[] = konteks_aman(function () use ($pdo) {
    $bulanIni = date('Y-m');
    $s = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) FROM penerimaan WHERE DATE_FORMAT(tanggal,'%Y-%m')=?"); $s->execute([$bulanIni]);
    $masuk = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) FROM pengeluaran WHERE DATE_FORMAT(tanggal,'%Y-%m')=?"); $s->execute([$bulanIni]);
    $keluar = (int)$s->fetchColumn();
    return "\n=== Ringkasan bulan berjalan ($bulanIni) ===\nTotal diterima: $masuk keping. Total dikeluarkan: $keluar keping.";
  }, '');

  $baris[] = konteks_aman(function () use ($pdo) {
    $o = ['', '=== 15 penerimaan terakhir ===', 'tanggal | dokumen | jumlah | no.BA | status | petugas'];
    $rows = $pdo->query("SELECT p.tanggal,d.nama,p.jumlah,p.no_ba,p.status,u.nama petugas FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id LEFT JOIN users u ON u.id=p.user_id ORDER BY p.tanggal DESC,p.id DESC LIMIT 15")->fetchAll();
    foreach ($rows as $r) $o[] = "{$r['tanggal']} | {$r['nama']} | {$r['jumlah']} | {$r['no_ba']} | {$r['status']} | " . ($r['petugas'] ?? '-');
    return implode("\n", $o);
  }, '');

  $baris[] = konteks_aman(function () use ($pdo) {
    $o = ['', '=== 15 pengeluaran terakhir ===', 'tanggal | dokumen | jumlah | tujuan | no.BA | petugas'];
    $rows = $pdo->query("SELECT k.tanggal,d.nama,k.jumlah,k.tujuan,k.no_ba,u.nama petugas FROM pengeluaran k JOIN dokumen d ON d.id=k.dokumen_id LEFT JOIN users u ON u.id=k.user_id ORDER BY k.tanggal DESC,k.id DESC LIMIT 15")->fetchAll();
    foreach ($rows as $r) $o[] = "{$r['tanggal']} | {$r['nama']} | {$r['jumlah']} | {$r['tujuan']} | {$r['no_ba']} | " . ($r['petugas'] ?? '-');
    return implode("\n", $o);
  }, '');

  $baris[] = konteks_aman(function () use ($pdo) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM penerimaan WHERE status='Pending'")->fetchColumn();
    return "\nPenerimaan menunggu verifikasi: $n.";
  }, '');

  return implode("\n", $baris);
}

$sistemPrompt = "Kamu adalah asisten data untuk aplikasi SIM-DOKIM (Sistem Informasi Manajemen Dokumen Keimigrasian) milik sebuah kantor imigrasi di Indonesia. "
  . "Jawab pertanyaan staf/admin tentang stok dan transaksi dokumen HANYA berdasarkan data yang diberikan di bawah ini — jangan mengarang angka. "
  . "Kalau datanya tidak cukup untuk menjawab (misalnya ditanya transaksi yang sudah sangat lama dan tidak ada di cuplikan ini), katakan terus terang bahwa datanya tidak tersedia di cuplikan ini, jangan menebak. "
  . "Jawab singkat, jelas, pakai Bahasa Indonesia santai tapi sopan (gunakan \"kamu\", bukan \"Anda\"). Boleh pakai poin-poin kalau memang membantu.\n\n"
  . "=== DATA TERKINI ===\n" . konteks_data($pdo);

$messages = [['role' => 'system', 'content' => $sistemPrompt]];
foreach ($riwayat as $m) {
  $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
  $isi = trim((string)($m['content'] ?? ''));
  if ($isi !== '') $messages[] = ['role' => $role, 'content' => mb_substr($isi, 0, 1000)];
}
$messages[] = ['role' => 'user', 'content' => $pesan];

/* ============================================================
 * Panggil OpenRouter
 * ============================================================ */
$ch = curl_init($baseUrl . '/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_CONNECTTIMEOUT => 8,
  CURLOPT_TIMEOUT => 45,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
    'HTTP-Referer: ' . app_url('index.php'),
    'X-Title: SIM-DOKIM',
  ],
  CURLOPT_POSTFIELDS => json_encode([
    'model' => $model,
    'messages' => $messages,
    'temperature' => 0.3,
    'max_tokens' => 1500,   // model "berpikir" (mis. gpt-oss) memakai sebagian jatah ini untuk menalar dulu
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
]);
$raw = curl_exec($ch);
$httpKode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($raw === false) jawab(['error' => 'Tidak bisa menghubungi layanan AI (' . $curlErr . '). Pastikan server ini bisa akses internet keluar.']);

$resp = json_decode($raw, true);
$isiBalasan = trim((string)($resp['choices'][0]['message']['content'] ?? ''));
if ($httpKode !== 200 || $isiBalasan === '') {
  $err = $resp['error'] ?? null;
  $pesanError = is_array($err) ? ($err['message'] ?? json_encode($err)) : ($err ?: ($httpKode === 200
    ? 'AI membalas kosong (model "berpikir" bisa kehabisan jatah jawaban). Coba tanya lagi atau ganti Model.'
    : "balasan tidak dikenali (kode $httpKode): " . mb_substr(strip_tags((string)$raw), 0, 150)));
  jawab(['error' => 'Layanan AI gagal merespons: ' . $pesanError]);
}

jawab(['balasan' => $isiBalasan]);