<?php
/**
 * Backend chat AI (widget pojok kanan bawah). Selalu membalas JSON.
 *
 * Cara kerja "agen": AI tidak lagi hanya diberi cuplikan data. Ia boleh memanggil beberapa "alat" baca-saja
 * (lib/chat_alat.php: stok, transaksi, rekap, kartu stok) beberapa kali, lalu menyimpulkan.
 * Tabel hasil alat dikirim langsung ke layar (rapi + tombol unduh Excel/CSV) — AI hanya melihat ringkasannya,
 * jadi hemat token dan angkanya tidak bisa "salah salin".
 * Percakapan disimpan di database per pengguna, sehingga tetap ada saat pindah tab/halaman/perangkat.
 *
 * Selain itu: AI bisa membuat grafik (alat "grafik", diambil dari tabel hasil alat) dan memeriksa berkas .xlsx/.csv
 * yang dilampirkan pengguna (lib/chat_berkas.php, diunggah lewat chat_berkas.php).
 * Aksi (field "aksi" di JSON): kirim (bawaan), riwayat, muat, hapus.
 */
ini_set('display_errors', '0');
ob_start();
require 'config.php';
require __DIR__ . '/lib/chat_alat.php';
require __DIR__ . '/lib/chat_berkas.php';
set_time_limit(130);

function jawab(array $data, int $kode = 200): never {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  http_response_code($kode);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

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

$uid  = (int)$_SESSION['u']['id'];
$aksi = is_string($body['aksi'] ?? null) ? $body['aksi'] : 'kirim';

const CHAT_MAKS_PER_SESI   = 80;    // jumlah pertanyaan per sesi login
const CHAT_MAKS_PUTARAN    = 5;     // berapa kali AI boleh memanggil alat untuk satu pertanyaan
const CHAT_MAKS_TABEL      = 6;     // tabel per jawaban
const CHAT_MAKS_GRAFIK     = 3;     // grafik per jawaban
const CHAT_RIWAYAT_PESAN   = 12;    // pesan terakhir yang dibawa sebagai ingatan AI
const CHAT_MAKS_PERCAKAPAN = 60;    // percakapan tersimpan per pengguna (yang terlama dihapus otomatis)
const CHAT_BATAS_WAKTU     = 100;   // detik total per pertanyaan

/* ============================================================
 * Tabel penyimpanan (dibuat otomatis sekali, seperti login_attempts)
 * ============================================================ */
function chat_siapkan_db(PDO $pdo): bool {
  if (!empty($_SESSION['chat_tbl_ok'])) return true;
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_percakapan (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      judul VARCHAR(120) NOT NULL DEFAULT '',
      dibuat DATETIME NOT NULL,
      diubah DATETIME NOT NULL,
      KEY idx_user_ubah (user_id, diubah)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_pesan (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      percakapan_id BIGINT UNSIGNED NOT NULL,
      peran VARCHAR(10) NOT NULL,
      isi MEDIUMTEXT NOT NULL,
      tabel MEDIUMTEXT NULL,
      dibuat DATETIME NOT NULL,
      KEY idx_perc (percakapan_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    berkas_siapkan_db($pdo);
    $_SESSION['chat_tbl_ok'] = 1;
    return true;
  } catch (Throwable $e) {
    error_log('SIMDOKIM chat: gagal membuat tabel penyimpanan: ' . $e->getMessage());
    return false;
  }
}

function chat_milik(PDO $pdo, int $id, int $uid): ?array {
  $s = $pdo->prepare('SELECT id, judul, diubah FROM chat_percakapan WHERE id=? AND user_id=?');
  $s->execute([$id, $uid]);
  return $s->fetch() ?: null;
}

/* ---------- aksi baca/hapus riwayat ---------- */
if ($aksi !== 'kirim') {
  if (!chat_siapkan_db($pdo)) jawab(['error' => 'Penyimpanan percakapan belum bisa dipakai (database tidak mengizinkan membuat tabel).']);
  $session_id = (int)($body['id'] ?? 0);
  if ($aksi === 'riwayat') {
    $s = $pdo->prepare('SELECT id, judul, diubah FROM chat_percakapan WHERE user_id=? ORDER BY diubah DESC, id DESC LIMIT ' . CHAT_MAKS_PERCAKAPAN);
    $s->execute([$uid]);
    jawab(['daftar' => $s->fetchAll()]);
  }
  if ($aksi === 'muat') {
    $p = chat_milik($pdo, $session_id, $uid);
    if (!$p) jawab(['error' => 'Percakapan tidak ditemukan.'], 404);
    $s = $pdo->prepare('SELECT id, peran, isi, tabel FROM chat_pesan WHERE percakapan_id=? ORDER BY id');
    $s->execute([$p['id']]);
    $pesan = [];
    foreach ($s->fetchAll() as $r) {
      $tabel = [];
      if ($r['tabel'] !== null && $r['tabel'] !== '') {
        $tabel = json_decode($r['tabel'], true) ?: [];
        foreach ($tabel as $i => &$t) { $t['idx'] = $i; $t['pesan_id'] = (int)$r['id']; }
        unset($t);
      }
      $pesan[] = ['peran' => $r['peran'], 'isi' => $r['isi'], 'tabel' => $tabel];
    }
    jawab(['id' => (int)$p['id'], 'judul' => $p['judul'], 'pesan' => $pesan]);
  }
  if ($aksi === 'hapus') {
    $p = chat_milik($pdo, $session_id, $uid);
    if (!$p) jawab(['error' => 'Percakapan tidak ditemukan.'], 404);
    $pdo->prepare('DELETE FROM chat_pesan WHERE percakapan_id=?')->execute([$p['id']]);
    try { $pdo->prepare('DELETE FROM chat_berkas WHERE percakapan_id=? AND user_id=?')->execute([$p['id'], $uid]); } catch (Throwable $e) {}
    $pdo->prepare('DELETE FROM chat_percakapan WHERE id=?')->execute([$p['id']]);
    jawab(['ok' => true]);
  }
  jawab(['error' => 'Aksi tidak dikenal.'], 400);
}

/* ============================================================
 * aksi: kirim
 * ============================================================ */
$apiKey  = app_setting('openrouter_api_key', OPENROUTER_API_KEY);
$model   = app_setting('openrouter_model', OPENROUTER_MODEL);
$baseUrl = rtrim(app_setting('openrouter_base_url', OPENROUTER_BASE_URL), '/');
if ($apiKey === '') jawab(['error' => 'Fitur chat AI belum diaktifkan. Minta superadmin mengisi API key di halaman Pengaturan.']);

$pesan = trim((string)($body['pesan'] ?? ''));
if ($pesan === '') jawab(['error' => 'Pertanyaan kosong.']);
if (mb_strlen($pesan) > 600) jawab(['error' => 'Pertanyaannya kepanjangan, coba dipersingkat (maks. 600 karakter).']);

$_SESSION['chat_hitung'] = ($_SESSION['chat_hitung'] ?? 0) + 1;
if ($_SESSION['chat_hitung'] > CHAT_MAKS_PER_SESI) jawab(['error' => 'Sudah mencapai batas tanya-jawab untuk sesi login ini. Muat ulang/masuk lagi untuk lanjut.']);

$simpan = chat_siapkan_db($pdo);

// Lepas kunci sesi supaya tab/halaman lain tetap lancar selagi AI berpikir (bisa puluhan detik).
session_write_close();

/* ---------- percakapan & ingatan ---------- */
$percakapanId = (int)($body['id'] ?? 0);
$riwayat = [];   // [['role','content']]
$tabelLama = []; // tabel dari jawaban sebelumnya di percakapan ini; boleh dijadikan acuan grafik tanpa mengambil ulang
if ($simpan) {
  if ($percakapanId > 0) {
    if (!chat_milik($pdo, $percakapanId, $uid)) $percakapanId = 0;
  }
  if ($percakapanId > 0) {
    $s = $pdo->prepare('SELECT peran, isi, tabel FROM chat_pesan WHERE percakapan_id=? ORDER BY id DESC LIMIT ' . CHAT_RIWAYAT_PESAN);
    $s->execute([$percakapanId]);
    foreach (array_reverse($s->fetchAll()) as $r) {
      $isi = mb_substr($r['isi'], 0, 1500);
      if ($r['peran'] === 'assistant' && $r['tabel']) {
        $tt = array_values(array_filter(json_decode($r['tabel'], true) ?: [], fn($t) => isset($t['kolom'])));
        if ($tt) $tabelLama = array_slice($tt, 0, 4);   // yang dipakai hanya dari jawaban terakhir yang punya tabel
        $isi .= "\n[Tabel yang sudah ditampilkan ke pengguna: " . implode('; ', array_filter(array_map(fn($t) => $t['judul'] ?? '', $tt))) . ']';
      }
      $riwayat[] = ['role' => $r['peran'] === 'assistant' ? 'assistant' : 'user', 'content' => $isi];
    }
  }
} else {
  // Tanpa penyimpanan server: pakai riwayat kiriman browser (seperti versi lama).
  foreach (array_slice(is_array($body['riwayat'] ?? null) ? $body['riwayat'] : [], -CHAT_RIWAYAT_PESAN) as $m) {
    $isi = trim((string)($m['content'] ?? ''));
    if ($isi !== '') $riwayat[] = ['role' => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'content' => mb_substr($isi, 0, 1500)];
  }
}

/* ---------- berkas lampiran (kalau ada) ---------- */
$berkas = null; $berkasBaru = false;
if ($simpan) {
  $bid = (int)($body['berkas'] ?? 0);
  if ($bid > 0) {
    $b = berkas_muat($pdo, $bid, $uid);
    if ($b && ($b['percakapan_id'] === null || $b['percakapan_id'] === $percakapanId)) { $berkas = $b; $berkasBaru = $b['percakapan_id'] === null; }
  }
  if (!$berkas && $percakapanId > 0) $berkas = berkas_terbaru_percakapan($pdo, $percakapanId, $uid);
}

/* ---------- prompt sistem ---------- */
function chat_konteks_stok(PDO $pdo): string {
  try {
    $rata = pemakaian($pdo);
    $o = ['kode | nama | satuan | stok | rusak | terbit | status'];
    foreach ($pdo->query('SELECT id,kode,nama,satuan,stok,rusak,terbit FROM dokumen ORDER BY nama')->fetchAll() as $d) {
      [$label] = status_stok((int)$d['stok'], $rata[$d['id']] ?? 0);
      $o[] = "{$d['kode']} | {$d['nama']} | {$d['satuan']} | {$d['stok']} | {$d['rusak']} | {$d['terbit']} | $label";
    }
    $n = (int)$pdo->query("SELECT COUNT(*) FROM penerimaan WHERE status='Pending'")->fetchColumn();
    $o[] = "Penerimaan menunggu verifikasi: $n.";
    return implode("\n", $o);
  } catch (Throwable $e) {
    return '(cuplikan stok tidak bisa dibaca; gunakan alat stok_dokumen)';
  }
}

function chat_prompt(PDO $pdo, bool $adaAlat, ?array $berkas = null): string {
  $p = "Kamu adalah asisten data dan analis untuk SIM-DOKIM (Sistem Informasi Manajemen Dokumen Keimigrasian) milik kantor imigrasi di Indonesia. "
     . "Tanggal hari ini: " . date('Y-m-d (l)') . ". Pengguna adalah staf/admin; panggil mereka \"kamu\" (bukan \"Anda\"), gaya santai tapi sopan, Bahasa Indonesia.\n\n"
     . "ATURAN KERAS: semua angka harus berasal dari data/alat. Jangan mengarang atau menghitung kira-kira. Kalau data tidak ada atau alat gagal, bilang terus terang.\n\n";
  if ($adaAlat) {
    $p .= "CARA KERJA:\n"
       . "- Kamu punya alat baca-saja: stok_dokumen, transaksi, rekap, kartu_stok. Panggil alat sebanyak yang perlu (boleh beberapa kali, boleh berurutan) lalu simpulkan. Untuk pertanyaan analisis (tren, perbandingan, tujuan terbanyak, prediksi habis), kumpulkan datanya dulu dengan alat, baru analisis.\n"
       . "- Tabel dari alat OTOMATIS tampil rapi di layar pengguna lengkap dengan tombol unduh Excel dan CSV. Kamu hanya melihat ringkasan + contoh baris. JANGAN menyalin ulang seluruh tabel ke jawaban; cukup rangkum temuan pentingnya (angka kunci, yang menonjol, saran).\n"
       . "- Kalau pengguna minta ekspor/unduh/Excel/CSV: jalankan alat yang sesuai agar tabelnya muncul, lalu katakan 'tabel di atas bisa diunduh lewat tombol Excel/CSV di bawahnya'. Kamu tidak perlu dan tidak bisa membuat file sendiri.\n"
       . "- Kalau pengguna minta data yang berbeda dari tabel sebelumnya (periode/saringan lain), panggil alat lagi dengan parameter baru.\n"
       . "- Sumber data: \"kartu stok harian\" = riwayat harian lengkap (juga memuat jumlah terbit dan rusak), dipakai alat kartu_stok dan rekap (sumber kartu). \"transaksi\" = penerimaan/pengeluaran yang dicatat lewat aplikasi (punya No. BA, petugas, status verifikasi). Angka keduanya bisa berbeda karena sumbernya beda; sebutkan sumber yang kamu pakai bila relevan.\n"
       . "- GRAFIK: kalau pengguna minta grafik/diagram/visual (atau tren/perbandingan jelas terbantu grafik), ambil datanya dulu dengan alat, lalu panggil alat grafik dengan nomor_tabel dari hasil alat itu. Jenis: garis = tren waktu; batang = perbandingan beberapa kategori; batang_mendatar = peringkat/kategori banyak; pie = komposisi (maks. 8 bagian). Angka grafik diambil sistem dari tabel (jangan kirim angka sendiri). Maks. 1-2 grafik per jawaban dan jangan buat grafik kalau tidak diminta kecuali sangat membantu. Setelah grafik tampil, jelaskan apa yang terlihat.\n"
       . "- Jangan sebut nama alat/parameter teknis ke pengguna.\n\n";
    if ($berkas) {
      $p .= "BERKAS LAMPIRAN PENGGUNA: \"{$berkas['nama']}\", " . count($berkas['baris']) . " baris, kolom: " . implode(' | ', $berkas['kolom']) . ".\n"
         . "- Periksa dengan alat berkas_profil / berkas_data / berkas_agregat. Kalau pengguna minta 'cek' berkas, mulai dari berkas_profil lalu laporkan: gambaran isi, kualitas data (kosong, duplikat, format campur, nilai ekstrem), temuan menarik, dan saran perapian.\n"
         . "- Berkas ini TERPISAH dari data stok aplikasi. Jangan mencampur kecuali pengguna minta membandingkan.\n\n";
    }
  } else {
    $p .= "Kamu tidak punya alat pencarian atau pembuat grafik; jawab hanya dari cuplikan stok di bawah. Kalau pertanyaannya butuh data transaksi/riwayat, katakan datanya tidak tersedia di cuplikan ini.\n\n";
  }
  $p .= "FORMAT JAWABAN: ringkas dan jelas. Boleh pakai poin-poin dan **tebal** untuk angka kunci. Tidak perlu tabel markdown (tabel sudah ditampilkan sistem). Akhiri analisis dengan satu saran tindakan bila memang ada.\n\n"
     . "=== STOK TERKINI (semua jenis dokumen) ===\n" . chat_konteks_stok($pdo);
  return $p;
}

/* ---------- panggilan ke penyedia AI ---------- */
function chat_panggil(string $baseUrl, string $apiKey, array $payload, int $timeout): array {
  $ch = curl_init($baseUrl . '/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => max(10, $timeout),
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json',
      'HTTP-Referer: ' . app_url('index.php'),
      'X-Title: SIM-DOKIM',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
  ]);
  $raw = curl_exec($ch);
  $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) return ['ok' => false, 'kode' => 0, 'pesan' => 'Tidak bisa menghubungi layanan AI (' . $err . '). Pastikan server ini bisa akses internet keluar.'];
  $resp = json_decode($raw, true);
  if ($kode !== 200 || !is_array($resp) || empty($resp['choices'][0]['message'])) {
    $e = is_array($resp) ? ($resp['error'] ?? null) : null;
    $pesan = is_array($e) ? ($e['message'] ?? json_encode($e)) : ($e ?: ($kode === 200
      ? 'balasan AI tidak dikenali'
      : "balasan tidak dikenali (kode $kode): " . mb_substr(strip_tags((string)$raw), 0, 150)));
    return ['ok' => false, 'kode' => $kode, 'pesan' => (string)$pesan];
  }
  return ['ok' => true, 'pesan' => $resp['choices'][0]['message'], 'selesai' => $resp['choices'][0]['finish_reason'] ?? ''];
}

/** Ubah hasil alat jadi teks ringkas untuk AI (tabel penuh TIDAK dikirim ke AI). */
function chat_ringkas_hasil(array $h, ?int $nomor = null): string {
  $t = $h['tabel'];
  $baris = array_slice($t['baris'], 0, CHAT_BARIS_UNTUK_AI);
  $o = [
    'nomor_tabel' => $nomor,   // dipakai alat grafik; null = tabel tidak ditampilkan (batas tercapai)
    'tabel_ditampilkan_ke_pengguna' => $t['judul'],
    'kolom' => $t['kolom'],
    'jumlah_baris_total' => count($t['baris']),
    'contoh_baris' => $baris,
    'ringkasan' => $h['ringkasan'] ?? new stdClass(),
  ];
  if (count($t['baris']) > CHAT_BARIS_UNTUK_AI) $o['catatan_baris'] = 'Hanya ' . CHAT_BARIS_UNTUK_AI . ' baris pertama yang kamu lihat; tabel lengkap ada di layar pengguna. Gunakan "ringkasan" untuk angka total.';
  if (!empty($h['catatan'])) $o['catatan'] = $h['catatan'];
  return mb_substr(json_encode($o, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 9000);
}

/** Definisi alat grafik. */
function chat_grafik_definisi(): array {
  return ['type' => 'function', 'function' => [
    'name' => 'grafik',
    'description' => 'Tampilkan GRAFIK dari tabel yang sudah dihasilkan alat lain pada pertanyaan ini. Sistem mengambil angka dari tabel itu sendiri.',
    'parameters' => ['type' => 'object', 'required' => ['nomor_tabel', 'jenis', 'kolom_label', 'kolom_nilai'], 'properties' => [
      'nomor_tabel' => ['type' => 'integer', 'description' => 'nomor_tabel dari hasil alat sebelumnya'],
      'jenis' => ['type' => 'string', 'enum' => ['batang', 'batang_mendatar', 'garis', 'pie']],
      'kolom_label' => ['type' => 'string', 'description' => 'Nama kolom untuk label sumbu/irisan (mis. Bulan, Dokumen)'],
      'kolom_nilai' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Satu atau beberapa (maks. 4) nama kolom angka. Pie hanya satu.'],
      'judul' => ['type' => 'string'],
      'urut' => ['type' => 'string', 'enum' => ['asli', 'terbesar', 'terkecil'], 'description' => 'Bawaan asli (urutan tabel). Pakai terbesar untuk peringkat.'],
      'batas' => ['type' => 'integer', 'description' => 'Maks. titik/batang (2-40, bawaan 24)'],
    ]],
  ]];
}

/** Bangun spesifikasi grafik dari tabel yang sudah ada. Melempar InvalidArgumentException bila tidak cocok. */
function chat_buat_grafik(array $tabel, array $a): array {
  $no = (int)($a['nomor_tabel'] ?? 0);
  if (!$tabel) throw new InvalidArgumentException('Belum ada tabel. Panggil alat data (mis. rekap) dulu, lalu panggil grafik.');
  if ($no < 1 || !isset($tabel[$no - 1])) $no = count($tabel);   // nomor ngawur/kosong → pakai tabel terbaru
  $t = $tabel[$no - 1];
  $kolom = $t['kolom'];
  $cari = function ($nama) use ($kolom, $no) {
    $nama = trim((string)$nama);
    foreach ($kolom as $i => $k) if (mb_strtolower($k) === mb_strtolower($nama)) return $i;
    foreach ($kolom as $i => $k) if ($nama !== '' && mb_stripos($k, $nama) !== false) return $i;
    throw new InvalidArgumentException("Kolom \"$nama\" tidak ada di tabel $no. Kolom: " . implode(', ', $kolom));
  };
  $jenis = chat_pilih($a['jenis'] ?? '', ['batang', 'batang_mendatar', 'garis', 'pie'], 'batang');
  $li = trim((string)($a['kolom_label'] ?? '')) === '' ? 0 : $cari($a['kolom_label']);
  $nilaiNama = $a['kolom_nilai'] ?? [];
  if (is_string($nilaiNama)) {                       // beberapa model mengirim '["A","B"]' atau 'A, B' sebagai teks
    $dec = json_decode($nilaiNama, true);
    $nilaiNama = is_array($dec) ? $dec : array_map('trim', explode(',', $nilaiNama));
  }
  if (!is_array($nilaiNama) || !array_filter($nilaiNama, fn($x) => trim((string)$x) !== '')) {   // tidak disebut → kolom angka pertama selain label
    $nilaiNama = [];
    foreach ($kolom as $i => $k) if ($i !== $li && array_filter(array_column($t['baris'], $i), 'is_numeric')) { $nilaiNama[] = $k; break; }
  }
  if (!is_array($nilaiNama) || !$nilaiNama) throw new InvalidArgumentException('kolom_nilai wajib diisi (nama kolom angka).');
  $vis = array_values(array_unique(array_map($cari, array_slice($nilaiNama, 0, 4))));
  if ($jenis === 'pie') $vis = array_slice($vis, 0, 1);

  $baris = array_values(array_filter($t['baris'], fn($r) => mb_strtoupper(trim((string)($r[$li] ?? ''))) !== 'TOTAL' && mb_strtoupper(trim((string)($r[0] ?? ''))) !== 'TOTAL'));
  if (!$baris) throw new InvalidArgumentException('Tabel ini tidak punya baris data untuk digrafikkan.');
  $urut = chat_pilih($a['urut'] ?? '', ['asli', 'terbesar', 'terkecil'], 'asli');
  if ($urut !== 'asli') {
    $v0 = $vis[0];
    usort($baris, fn($x, $y) => $urut === 'terbesar' ? ((float)($y[$v0] ?? 0) <=> (float)($x[$v0] ?? 0)) : ((float)($x[$v0] ?? 0) <=> (float)($y[$v0] ?? 0)));
  }
  $batas = max(2, min(40, (int)($a['batas'] ?? 24)));
  $baris = array_slice($baris, 0, $batas);
  $label = array_map(fn($r) => mb_substr((string)($r[$li] ?? ''), 0, 40), $baris);
  $seri = [];
  foreach ($vis as $vi) {
    $nilai = array_map(function ($r) use ($vi) { $v = $r[$vi] ?? 0; return is_numeric($v) ? $v + 0 : 0; }, $baris);
    if (!array_filter($nilai, fn($v) => $v != 0) && !array_filter(array_column($baris, $vi), 'is_numeric')) throw new InvalidArgumentException("Kolom \"{$kolom[$vi]}\" bukan kolom angka.");
    $seri[] = ['nama' => $kolom[$vi], 'nilai' => $nilai];
  }
  if ($jenis === 'pie' && array_filter($seri[0]['nilai'], fn($v) => $v < 0)) throw new InvalidArgumentException('Pie tidak bisa dipakai untuk nilai negatif; pakai batang.');
  $judul = trim((string)($a['judul'] ?? '')) ?: ($seri[0]['nama'] . ' per ' . $kolom[$li]);
  return ['grafik' => true, 'jenis' => $jenis, 'judul' => mb_substr($judul, 0, 120), 'label' => $label, 'seri' => $seri, 'sumber' => $t['judul'] ?? ''];
}

/** Cadangan: pengguna jelas minta grafik tapi AI tidak/gagal memanggil alat grafik → buat dari tabel terbaru. */
function chat_grafik_otomatis(array $acuan, string $pesan): ?array {
  for ($n = count($acuan); $n >= 1; $n--) {
    $t = $acuan[$n - 1];
    $baris = array_values(array_filter($t['baris'] ?? [], fn($r) => mb_strtoupper(trim((string)($r[0] ?? ''))) !== 'TOTAL'));
    if (count($baris) < 2 || empty($t['kolom'])) continue;
    $angka = [];
    foreach ($t['kolom'] as $i => $k) if ($i > 0 && count(array_filter(array_column($baris, $i), 'is_numeric')) >= count($baris) * 0.8) $angka[$i] = $k;
    if (!$angka) continue;
    $pilih = [];
    foreach ($angka as $i => $k) if (mb_stripos($pesan, $k) !== false) $pilih[] = $k;
    if (!$pilih) $pilih = array_slice(array_values(array_filter($angka, fn($k) => !preg_match('/selisih|saldo|rata/i', $k))) ?: array_values($angka), 0, 2);
    $label0 = array_column($baris, 0);
    $waktu = count(array_filter($label0, fn($l) => preg_match('/^\d{4}-\d{2}(-\d{2})?$/', (string)$l))) >= count($label0) * 0.8;
    $jenis = preg_match('/pie|lingkaran|komposisi|proporsi/i', $pesan) ? 'pie'
           : (preg_match('/mendatar|horizontal/i', $pesan) ? 'batang_mendatar'
           : (preg_match('/garis|tren|line/i', $pesan) || $waktu ? 'garis' : (count($baris) > 12 ? 'batang_mendatar' : 'batang')));
    if ($jenis === 'pie') $pilih = array_slice($pilih, 0, 1);
    try {
      return chat_buat_grafik($acuan, ['nomor_tabel' => $n, 'jenis' => $jenis, 'kolom_label' => $t['kolom'][0], 'kolom_nilai' => $pilih, 'urut' => $jenis === 'batang_mendatar' || $jenis === 'pie' ? 'terbesar' : 'asli']);
    } catch (InvalidArgumentException $e) { continue; }
  }
  return null;
}

/* ---------- loop agen ---------- */
$mulai = time();
$promptAlat = chat_prompt($pdo, true, $berkas);
if ($tabelLama) {
  $promptAlat .= "\n\n=== TABEL DARI JAWABAN SEBELUMNYA (bisa langsung dipakai alat grafik, tanpa mengambil data lagi) ===\n";
  foreach ($tabelLama as $i => $t) $promptAlat .= 'nomor_tabel ' . ($i + 1) . ': ' . ($t['judul'] ?? '') . ' — kolom: ' . implode(' | ', $t['kolom']) . "\n";
}
$messages = array_merge([['role' => 'system', 'content' => $promptAlat]], $riwayat, [['role' => 'user', 'content' => $pesan]]);
$alat = array_merge(chat_alat_definisi(), [chat_grafik_definisi()], $berkas ? berkas_alat_definisi() : []);
$grafikHasil = [];
$tabelLamaN = count($tabelLama);
$denganAlat = true;
$tabelHasil = [];
$balasan = '';
$dipotong = false;

for ($putaran = 0; $putaran <= CHAT_MAKS_PUTARAN; $putaran++) {
  $sisa = CHAT_BATAS_WAKTU - (time() - $mulai);
  if ($sisa < 8) { $dipotong = true; break; }
  $payload = ['model' => $model, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => 2500];
  if ($denganAlat && $putaran < CHAT_MAKS_PUTARAN) { $payload['tools'] = $alat; $payload['tool_choice'] = 'auto'; }

  $r = chat_panggil($baseUrl, $apiKey, $payload, min(45, $sisa));
  if (!$r['ok']) {
    // Model/penyedia tidak mendukung alat -> ulangi tanpa alat (mode cuplikan seperti versi lama).
    if ($putaran === 0 && $denganAlat && $r['kode'] >= 400 && $r['kode'] < 500 && preg_match('/tool|function|schema|parameter/i', $r['pesan'])) {
      $denganAlat = false;
      $messages[0]['content'] = chat_prompt($pdo, false, null);
      $putaran = -1;
      continue;
    }
    jawab(['error' => 'Layanan AI gagal merespons: ' . $r['pesan']]);
  }

  $m = $r['pesan'];
  $panggilan = (is_array($m['tool_calls'] ?? null) && $denganAlat) ? $m['tool_calls'] : [];
  if (!$panggilan) {
    $balasan = trim((string)($m['content'] ?? ''));
    if (($r['selesai'] ?? '') === 'length') $balasan .= "\n\n_(Jawaban terpotong karena terlalu panjang. Minta aku melanjutkan atau persempit pertanyaannya.)_";
    break;
  }

  // simpan permintaan AI apa adanya, lalu jawab tiap panggilan alat
  $messages[] = ['role' => 'assistant', 'content' => (string)($m['content'] ?? ''), 'tool_calls' => $panggilan];
  foreach ($panggilan as $pg) {
    $idPanggilan = (string)($pg['id'] ?? ('call_' . $putaran));
    $namaAlat = (string)($pg['function']['name'] ?? '');
    $argMentah = $pg['function']['arguments'] ?? '{}';
    $arg = is_array($argMentah) ? $argMentah : json_decode((string)$argMentah, true);   // sebagian penyedia mengirim objek, bukan teks JSON
    if (!is_array($arg)) $arg = [];
    try {
      if ($namaAlat === 'grafik') {
        if (count($grafikHasil) >= CHAT_MAKS_GRAFIK) throw new InvalidArgumentException('Batas grafik per jawaban tercapai.');
        $g = chat_buat_grafik(array_merge($tabelLama, $tabelHasil), $arg);
        $grafikHasil[] = $g;
        $isi = json_encode(['ok' => true, 'grafik_ditampilkan' => $g['judul'], 'jumlah_titik' => count($g['label'])], JSON_UNESCAPED_UNICODE);
      } else {
        $h = str_starts_with($namaAlat, 'berkas_')
          ? ($berkas ? berkas_alat_jalankan($berkas, $namaAlat, $arg) : throw new InvalidArgumentException('Tidak ada berkas lampiran di percakapan ini.'))
          : chat_alat_jalankan($pdo, $namaAlat, $arg);
        $nomor = null;
        if (count($tabelHasil) < CHAT_MAKS_TABEL && !empty($h['tabel']['baris'])) { $tabelHasil[] = $h['tabel']; $nomor = $tabelLamaN + count($tabelHasil); }
        $isi = chat_ringkas_hasil($h, $nomor);
      }
    } catch (InvalidArgumentException $e) {
      $isi = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
      error_log('SIMDOKIM chat alat ' . $namaAlat . ': ' . $e->getMessage());
      $isi = json_encode(['error' => 'Alat gagal dijalankan karena masalah di server.'], JSON_UNESCAPED_UNICODE);
    }
    $messages[] = ['role' => 'tool', 'tool_call_id' => $idPanggilan, 'content' => $isi];
  }
}

if (!$grafikHasil && $denganAlat && preg_match('/grafik|diagram|chart|visual|\bpie\b|plot|bagan/i', $pesan)) {
  $g = chat_grafik_otomatis(array_merge($tabelLama, $tabelHasil), $pesan);
  if ($g) $grafikHasil[] = $g;
}

if ($balasan === '') {
  if ($dipotong) $balasan = 'Pertanyaannya butuh waktu terlalu lama untuk dianalisis. Coba persempit (misalnya satu jenis dokumen atau satu periode).';
  elseif ($tabelHasil || $grafikHasil) $balasan = 'Datanya sudah dikumpulkan dan tampil di atas, tapi AI tidak memberi kesimpulan. Coba tanya lagi dengan lebih spesifik.';
  else jawab(['error' => 'AI membalas kosong (model "berpikir" bisa kehabisan jatah jawaban). Coba tanya lagi atau ganti Model di Pengaturan.']);
}

/* ---------- simpan percakapan ---------- */
$pesanIdBalasan = 0;
$judul = '';
if ($simpan) {
  try {
    $skrg = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    if ($percakapanId === 0) {
      $judul = mb_substr(preg_replace('/\s+/', ' ', $pesan), 0, 60);
      $pdo->prepare('INSERT INTO chat_percakapan (user_id, judul, dibuat, diubah) VALUES (?,?,?,?)')->execute([$uid, $judul, $skrg, $skrg]);
      $percakapanId = (int)$pdo->lastInsertId();
    } else {
      $pdo->prepare('UPDATE chat_percakapan SET diubah=? WHERE id=? AND user_id=?')->execute([$skrg, $percakapanId, $uid]);
    }
    $ins = $pdo->prepare('INSERT INTO chat_pesan (percakapan_id, peran, isi, tabel, dibuat) VALUES (?,?,?,?,?)');
    $ins->execute([$percakapanId, 'user', $berkasBaru ? "\u{1F4CE} {$berkas['nama']}\n" . $pesan : $pesan, null, $skrg]);
    if ($berkasBaru) $pdo->prepare('UPDATE chat_berkas SET percakapan_id=? WHERE id=? AND user_id=?')->execute([$percakapanId, $berkas['id'], $uid]);
    $semua = array_merge($tabelHasil, $grafikHasil);   // tabel dulu (nomornya dipakai unduhan), grafik menyusul
    $tj = $semua ? json_encode($semua, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
    if ($tj !== null && strlen($tj) > 3000000) $tj = null;   // jaga-jaga, jangan simpan tabel raksasa
    $ins->execute([$percakapanId, 'assistant', $balasan, $tj, $skrg]);
    $pesanIdBalasan = (int)$pdo->lastInsertId();
    $pdo->commit();

    // rapikan: simpan hanya CHAT_MAKS_PERCAKAPAN percakapan terbaru per pengguna
    $lama = $pdo->prepare('SELECT id FROM chat_percakapan WHERE user_id=? ORDER BY diubah DESC, id DESC LIMIT 1000 OFFSET ' . CHAT_MAKS_PERCAKAPAN);
    $lama->execute([$uid]);
    foreach ($lama->fetchAll(PDO::FETCH_COLUMN) as $idLama) {
      $pdo->prepare('DELETE FROM chat_pesan WHERE percakapan_id=?')->execute([$idLama]);
      $pdo->prepare('DELETE FROM chat_berkas WHERE percakapan_id=? AND user_id=?')->execute([$idLama, $uid]);
      $pdo->prepare('DELETE FROM chat_percakapan WHERE id=?')->execute([$idLama]);
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('SIMDOKIM chat: gagal menyimpan percakapan: ' . $e->getMessage());
    $simpan = false; $pesanIdBalasan = 0;
  }
}

$semua = array_merge($tabelHasil, $grafikHasil);
foreach ($semua as $i => &$t) { $t['idx'] = $i; $t['pesan_id'] = $pesanIdBalasan; }
unset($t);

jawab([
  'balasan' => $balasan,
  'tabel' => $semua,
  'id' => $simpan ? $percakapanId : 0,
  'judul' => $judul,
  'tersimpan' => $simpan,
  'mode' => $denganAlat ? 'agen' : 'cuplikan',
]);