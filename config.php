<?php
if (defined('SIMDOKIM_LOADED')) return;
define('SIMDOKIM_LOADED', true);
/**
 * SIM-DOKIM - Konfigurasi & fungsi bantu
 * Di-include oleh semua halaman: require 'config.php';
 */

/* ============================================================
 * 1. KONFIGURASI
 * ============================================================ */
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'sim_dokim');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: 'dede');
define('SESSION_IDLE', 1800);                 // sesi habis setelah 30 menit tidak aktif
define('APP_TZ', 'Asia/Jakarta');
define('APP_KOTA', getenv('APP_KOTA') ?: 'Jakarta');   // dipakai di tanda tangan laporan
define('APP_VER', '3.7');
define('STOK_KRITIS', 100);                   // stok <= ini dianggap kritis
define('STOK_RENDAH', 200);                   // stok <= ini dianggap rendah
define('HARI_RATA', 90);                      // jendela hitung rata-rata pemakaian harian

/* ---------- Kop surat & penandatangan berita acara opname (ganti sesuai kantor Anda) ---------- */
define('APP_KOP1', 'KEMENTERIAN IMIGRASI DAN PEMASYARAKATAN REPUBLIK INDONESIA');
define('APP_KOP2', 'DIREKTORAT JENDERAL IMIGRASI');
define('APP_KOP3', 'KANTOR WILAYAH BALI');
define('APP_KOP4', 'KANTOR IMIGRASI KELAS I KHUSUS TPI NGURAH RAI');
define('APP_KOP_ALAMAT', 'Jalan Perum Taman Jimbaran, No. 1, Jimbaran, Kuta Selatan, Badung, Bali');
define('APP_KOP_EMAIL', 'kanim_ngurahrai@imigrasi.go.id');
define('APP_NOMOR_BA', 'WIM.20.IMI.1-PB.04.03-');

define('APP_KABAG_NAMA', 'Rachmad Akbar');
define('APP_KABAG_NIP', '198605132006041001');
define('APP_KABAG_JABATAN', 'Kepala Bagian Tata Usaha');

define('APP_PETUGAS1_NAMA', 'Ida Bagus Darma Yoga Manuaba');
define('APP_PETUGAS1_NIP', '200601312025061001');
define('APP_PETUGAS1_JABATAN', 'Pemeriksa Keimigrasian Pemula (Pengelola Aplikasi Dokim)');

define('APP_PETUGAS2_NAMA', 'Mochammad Akbar Sirajuddin Aqil');
define('APP_PETUGAS2_NIP', '200312102025061001');
define('APP_PETUGAS2_JABATAN', 'Pemeriksa Keimigrasian Pemula (Pengelola Aplikasi Persediaan)');

date_default_timezone_set(APP_TZ);

/* ============================================================
 * 2. SESI & HEADER KEAMANAN
 * ============================================================ */
if (session_status() !== PHP_SESSION_ACTIVE) {
  $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

  ini_set('session.use_strict_mode', 1);
  ini_set('session.use_only_cookies', 1);
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
  ]);
  session_name('SIMDOKIMSESS');
  session_start();
}

if (!headers_sent()) {
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
  header('Cache-Control: no-store');
}

/* ============================================================
 * 3. KONEKSI DATABASE
 * ============================================================ */
if (!isset($pdo)) {
  try {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER, DB_PASS,
      [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,   // gagal cepat (5 detik) kalau DB tidak merespons, bukan nunggu sampai 30 detik
      ]
    );
  } catch (PDOException $ex) {
    error_log('DB error: ' . $ex->getMessage());   // detail hanya di log server
    http_response_code(500);
    die('Tidak dapat terhubung ke database.');
  }
}

/* ============================================================
 * 4. FORMAT TAMPILAN
 * ============================================================ */
if (!defined('NAMA_BULAN')) define('NAMA_BULAN', ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']);
if (!defined('NAMA_HARI'))  define('NAMA_HARI',  ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu']);

if (!function_exists('e'))   { function e($s)   { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('num')) { function num($n) { return number_format((float)$n, 0, ',', '.'); } }
if (!function_exists('rp'))  { function rp($n)  { return 'Rp ' . num($n); } }

if (!function_exists('rp_singkat')) {
function rp_singkat($n) {                     // Rp 9,41 M (miliar), Rp 12,5 jt
  $n = (float)$n;
  if ($n >= 1e12) return 'Rp ' . number_format($n / 1e12, 2, ',', '.') . ' T';
  if ($n >= 1e9)  return 'Rp ' . number_format($n / 1e9, 2, ',', '.') . ' M';
  if ($n >= 1e6)  return 'Rp ' . number_format($n / 1e6, 1, ',', '.') . ' jt';
  return rp($n);
}
}

if (!function_exists('tgl')) {
function tgl($d) {                            // 15 Jan 2026
  $t = strtotime((string)$d); if (!$t) return '-';
  return date('j', $t) . ' ' . substr(NAMA_BULAN[date('n', $t) - 1], 0, 3) . ' ' . date('Y', $t);
}
}
if (!function_exists('tgl_panjang')) {
function tgl_panjang($d) {                    // 15 Januari 2026
  $t = strtotime((string)$d); if (!$t) return '-';
  return date('j', $t) . ' ' . NAMA_BULAN[date('n', $t) - 1] . ' ' . date('Y', $t);
}
}
if (!function_exists('hari_ini'))        { function hari_ini()        { return NAMA_HARI[date('w')] . ', ' . tgl_panjang(date('Y-m-d')); } }
if (!function_exists('bulan_txt'))       { function bulan_txt($ym)    { $t = strtotime("$ym-01"); return NAMA_BULAN[date('n', $t) - 1] . ' ' . date('Y', $t); } }
if (!function_exists('bulan_pendek'))    { function bulan_pendek($ym) { $t = strtotime("$ym-01"); return substr(NAMA_BULAN[date('n', $t) - 1], 0, 3) . ' ' . date('y', $t); } }

if (!function_exists('valid_date')) {
function valid_date($d) {
  $x = DateTime::createFromFormat('!Y-m-d', (string)$d);
  return $x && $x->format('Y-m-d') === $d;
}
}

if (!function_exists('_terbilang')) {
/** Konversi angka ke kata bahasa Indonesia (dipakai internal oleh terbilang()) */
function _terbilang(int $n): string {
  $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
  if ($n < 0) return 'minus ' . _terbilang(-$n);
  if ($n < 12) return $satuan[$n];
  if ($n < 20) return _terbilang($n - 10) . ' belas';
  if ($n < 100) return _terbilang(intdiv($n, 10)) . ' puluh ' . _terbilang($n % 10);
  if ($n < 200) return 'seratus ' . _terbilang($n - 100);
  if ($n < 1000) return _terbilang(intdiv($n, 100)) . ' ratus ' . _terbilang($n % 100);
  if ($n < 2000) return 'seribu ' . _terbilang($n - 1000);
  if ($n < 1000000) return _terbilang(intdiv($n, 1000)) . ' ribu ' . _terbilang($n % 1000);
  if ($n < 1000000000) return _terbilang(intdiv($n, 1000000)) . ' juta ' . _terbilang($n % 1000000);
  if ($n < 1000000000000) return _terbilang(intdiv($n, 1000000000)) . ' miliar ' . _terbilang($n % 1000000000);
  return _terbilang(intdiv($n, 1000000000000)) . ' triliun ' . _terbilang($n % 1000000000000);
}
}
if (!function_exists('terbilang')) {
function terbilang(int $n): string {
  return trim(preg_replace('/\s+/', ' ', _terbilang($n)));
}
}
if (!function_exists('tgl_narasi')) {
/** "Rabu tanggal tiga puluh bulan September tahun dua ribu dua puluh enam" */
function tgl_narasi($d): string {
  $t = strtotime((string)$d); if (!$t) return '-';
  $hari = NAMA_HARI[date('w', $t)];
  $tgl  = terbilang((int)date('j', $t));
  $bln  = NAMA_BULAN[date('n', $t) - 1];
  $thn  = terbilang((int)date('Y', $t));
  return "$hari tanggal $tgl bulan $bln tahun $thn";
}
}

/* ============================================================
 * 5. STATUS STOK & PERKIRAAN HABIS
 * ============================================================ */
if (!function_exists('pemakaian')) {
/** Rata-rata pengeluaran per hari tiap dokumen selama HARI_RATA hari terakhir: [dokumen_id => rata] */
function pemakaian(PDO $pdo): array {
  $rows = $pdo->query('SELECT dokumen_id, SUM(jumlah) FROM pengeluaran WHERE tanggal > CURDATE() - INTERVAL ' . (int)HARI_RATA . ' DAY GROUP BY dokumen_id')->fetchAll(PDO::FETCH_KEY_PAIR);
  return array_map(fn($j) => $j / HARI_RATA, $rows);
}
}

if (!function_exists('status_stok')) {
/** [label, kelas css, perkiraan hari habis | null] */
function status_stok($stok, $rata = 0): array {
  $stok = (int)$stok;
  if ($stok <= 0) return ['Habis', 'habis', 0];
  $hari = $rata > 0 ? max(1, (int)floor($stok / $rata)) : null;
  if ($stok <= STOK_KRITIS || ($hari !== null && $hari <= 14)) return ['Kritis', 'kritis', $hari];
  if ($stok <= STOK_RENDAH || ($hari !== null && $hari <= 45)) return ['Rendah', 'rendah', $hari];
  return ['Aman', 'aman', $hari];
}
}
if (!function_exists('badge_stok')) { function badge_stok(array $st) { return '<span class="st st-' . $st[1] . '">' . $st[0] . '</span>'; } }
if (!function_exists('hari_txt')) {
function hari_txt($h) {
  if ($h === null) return '<span class="muted" title="Belum ada pengeluaran dalam ' . HARI_RATA . ' hari terakhir">—</span>';
  if ($h <= 0) return 'Sudah habis';
  return '± ' . num($h) . ' hari';
}
}

/* ============================================================
 * 6. AUTENTIKASI & ROLE
 * ============================================================ */
if (!function_exists('is_super')) {
function is_super() {
  return ($_SESSION['u']['role'] ?? '') === 'superadmin';
}
}

if (!function_exists('login_required')) {
function login_required() {
  if (empty($_SESSION['u'])) { header('Location: login.php'); exit; }

  $ua = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
  $idle = time() - ($_SESSION['last'] ?? 0);
  if (($_SESSION['ua'] ?? '') !== $ua || $idle > SESSION_IDLE) {   // idle terlalu lama / browser berubah
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?exp=1');
    exit;
  }
  $_SESSION['last'] = time();
}
}

if (!function_exists('super_required')) {
function super_required() {
  login_required();
  if (!is_super()) {
    http_response_code(403);
    die('<p style="font:15px system-ui,sans-serif;padding:40px">Halaman atau aksi ini khusus Superadmin. <a href="index.php">Kembali ke ringkasan</a></p>');
  }
}
}

/* ============================================================
 * 7. CSRF
 * ============================================================ */
if (!function_exists('csrf')) {
function csrf() {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
}
if (!function_exists('csrf_field')) { function csrf_field() { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; } }

if (!function_exists('csrf_check')) {
function csrf_check() {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    http_response_code(419);
    die('Token tidak valid, silakan muat ulang halaman.');
  }
}
}

/* ============================================================
 * 8. FLASH MESSAGE & REDIRECT
 * ============================================================ */
if (!function_exists('flash')) { function flash($m, $t = 'ok') { $_SESSION['flash'] = [$m, $t]; } }
if (!function_exists('back'))  { function back($url)           { header("Location: $url"); exit; } }

/** URL absolut ke file di folder aplikasi ini, mis. app_url('pengeluaran.php?dari=abc') */
function app_url(string $path): string {
  $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  return ($https ? 'https://' : 'http://') . $host . $dir . '/' . ltrim($path, '/');
}

function cur_qs() {                           // query string halaman saat ini (untuk kembali setelah aksi)
  $q = $_SERVER['QUERY_STRING'] ?? '';
  return $q === '' ? '' : '?' . $q;
}
if (!function_exists('ret_url')) {
function ret_url($base) {                     // tujuan redirect yang aman
  $q = (string)($_POST['ret'] ?? '');
  return $base . (preg_match('/^\?[\w=&%.\-+]*$/', $q) ? $q : '');
}
}

/* ============================================================
 * 9. FILTER TRANSAKSI & EXPORT CSV
 * ============================================================ */
if (!function_exists('trx_filter')) {
/** Bangun WHERE dari ?q, ?dok, ?dari, ?sampai. Return [sql, params, nilai filter] */
function trx_filter(): array {
  $f = [
    'q'      => trim((string)($_GET['q'] ?? '')),
    'dok'    => (int)($_GET['dok'] ?? 0),
    'dari'   => (string)($_GET['dari'] ?? ''),
    'sampai' => (string)($_GET['sampai'] ?? ''),
  ];
  $w = ['1=1']; $p = [];
  if ($f['q'] !== '') { $w[] = '(p.no_ba LIKE ? OR p.seri_awal LIKE ? OR p.seri_akhir LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($p, $like, $like, $like); }
  if ($f['dok']) { $w[] = 'p.dokumen_id = ?'; $p[] = $f['dok']; }
  if (valid_date($f['dari']))   { $w[] = 'p.tanggal >= ?'; $p[] = $f['dari']; }   else $f['dari'] = '';
  if (valid_date($f['sampai'])) { $w[] = 'p.tanggal <= ?'; $p[] = $f['sampai']; } else $f['sampai'] = '';
  return [implode(' AND ', $w), $p, $f];
}
}

if (!function_exists('csv_out')) {
/** CSV dengan pemisah ; dan BOM UTF-8 agar langsung rapi dibuka di Excel versi Indonesia */
function csv_out(string $nama, array $head, array $rows) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $nama . '"');
  $o = fopen('php://output', 'w');
  fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, $head, ';');
  foreach ($rows as $r) fputcsv($o, array_values($r), ';');
  exit;
}
}