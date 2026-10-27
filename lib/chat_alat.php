<?php
/**
 * "Alat" yang boleh dipakai AI chat untuk membaca data aplikasi.
 *
 * Prinsip keamanan:
 *  - AI TIDAK menulis SQL. Ia hanya memilih alat + mengisi parameter (tanggal, kata kunci, dll).
 *  - Semua parameter divalidasi dan dikirim sebagai parameter query (bukan disambung ke teks SQL).
 *  - Semua alat hanya MEMBACA data. Tidak ada alat untuk mengubah/menghapus apa pun.
 *  - Hasil berupa tabel; tabel lengkap dikirim langsung ke layar pengguna (dan bisa diunduh),
 *    AI hanya menerima ringkasan + contoh baris, sehingga angka tidak "dihitung ulang" oleh AI.
 */

const CHAT_MAKS_BARIS_TABEL = 500;    // batas baris per tabel yang disimpan/diekspor
const CHAT_BARIS_UNTUK_AI   = 30;     // contoh baris yang dilihat AI per tabel

if (!function_exists('chat_str')) {
function chat_str($v, int $maks): string { return mb_substr(trim((string)$v), 0, $maks); }
}
if (!function_exists('chat_like')) {
function chat_like(string $s): string { return '%' . addcslashes($s, '%_\\') . '%'; }
}
if (!function_exists('chat_tgl')) {
function chat_tgl($v, string $nama): string {
  $v = trim((string)$v);
  if ($v === '') return '';
  if (!valid_date($v)) throw new InvalidArgumentException("Parameter \"$nama\" harus berformat YYYY-MM-DD (contoh 2026-10-31), bukan \"" . mb_substr($v, 0, 20) . '".');
  return $v;
}
}
if (!function_exists('chat_pilih')) {
function chat_pilih($v, array $boleh, string $bawaan): string { $v = is_string($v) ? strtolower(trim($v)) : ''; return in_array($v, $boleh, true) ? $v : $bawaan; }
}

/** Definisi alat dalam format "function calling" yang dipahami API kompatibel OpenAI. */
function chat_alat_definisi(): array {
  $tgl = ['type' => 'string', 'description' => 'Format YYYY-MM-DD'];
  return [
    ['type' => 'function', 'function' => [
      'name' => 'stok_dokumen',
      'description' => 'Daftar stok semua jenis dokumen beserta status (Habis/Kritis/Rendah/Aman), perkiraan berapa hari lagi habis (dari rata-rata pengeluaran 90 hari terakhir), rusak, terbit, harga, dan nilai persediaan. Pakai untuk pertanyaan stok, stok kritis, prediksi habis, nilai persediaan.',
      'parameters' => ['type' => 'object', 'properties' => [
        'status' => ['type' => 'string', 'enum' => ['semua', 'habis', 'kritis', 'rendah', 'aman'], 'description' => 'Saring menurut status. Bawaan: semua'],
        'cari' => ['type' => 'string', 'description' => 'Kata kunci nama/kode dokumen (opsional)'],
        'urut' => ['type' => 'string', 'enum' => ['nama', 'stok_terkecil', 'stok_terbesar', 'paling_cepat_habis', 'nilai_terbesar']],
      ]],
    ]],
    ['type' => 'function', 'function' => [
      'name' => 'transaksi',
      'description' => 'Daftar transaksi penerimaan dan/atau pengeluaran (tanggal, dokumen, jumlah, No. BA, nomor seri, tujuan/status, petugas) dengan saringan. Pakai untuk "siapa/kapan/berapa" tentang transaksi tertentu, daftar barang masuk/keluar, atau cari No. BA.',
      'parameters' => ['type' => 'object', 'properties' => [
        'jenis' => ['type' => 'string', 'enum' => ['semua', 'penerimaan', 'pengeluaran']],
        'dari' => $tgl, 'sampai' => $tgl,
        'dokumen' => ['type' => 'string', 'description' => 'Kata kunci nama/kode dokumen'],
        'tujuan' => ['type' => 'string', 'description' => 'Kata kunci tujuan pengeluaran (hanya untuk pengeluaran)'],
        'status' => ['type' => 'string', 'enum' => ['Pending', 'Terverifikasi'], 'description' => 'Status verifikasi (hanya untuk penerimaan)'],
        'no_ba' => ['type' => 'string', 'description' => 'Sebagian nomor Berita Acara'],
        'urut' => ['type' => 'string', 'enum' => ['terbaru', 'terlama', 'jumlah_terbesar']],
        'batas' => ['type' => 'integer', 'description' => 'Maks. baris (1-500, bawaan 100)'],
      ]],
    ]],
    ['type' => 'function', 'function' => [
      'name' => 'rekap',
      'description' => 'Rekap/total penerimaan dan pengeluaran yang dikelompokkan per bulan, per dokumen, per tujuan pengeluaran, atau per petugas. Pakai untuk tren, perbandingan, "paling banyak", total per periode, analisis pemakaian.',
      'parameters' => ['type' => 'object', 'required' => ['kelompok'], 'properties' => [
        'kelompok' => ['type' => 'string', 'enum' => ['bulan', 'dokumen', 'tujuan', 'petugas']],
        'jenis' => ['type' => 'string', 'enum' => ['keduanya', 'penerimaan', 'pengeluaran'], 'description' => 'Bawaan: keduanya (kelompok tujuan selalu pengeluaran)'],
        'dari' => $tgl, 'sampai' => $tgl,
        'dokumen' => ['type' => 'string', 'description' => 'Batasi ke dokumen tertentu (kata kunci nama/kode)'],
        'sumber' => ['type' => 'string', 'enum' => ['otomatis', 'kartu', 'transaksi'], 'description' => '"kartu" = kartu stok harian (riwayat lengkap setahun, juga memuat terbit & rusak); "transaksi" = transaksi yang dicatat lewat aplikasi (punya No. BA & petugas). Bawaan otomatis: pakai kartu kalau ada datanya di periode itu.'],
      ]],
    ]],
    ['type' => 'function', 'function' => [
      'name' => 'kartu_stok',
      'description' => 'Kartu stok harian SATU dokumen: saldo stok per tanggal beserta penerimaan, pengeluaran, tujuan, No. BA, terbit, rusak. Pakai untuk riwayat harian/pergerakan stok satu dokumen di rentang tertentu.',
      'parameters' => ['type' => 'object', 'required' => ['dokumen'], 'properties' => [
        'dokumen' => ['type' => 'string', 'description' => 'Kode atau nama dokumen'],
        'dari' => $tgl, 'sampai' => $tgl,
        'hanya_mutasi' => ['type' => 'boolean', 'description' => 'true = hanya hari yang ada penerimaan/pengeluaran (bawaan true)'],
      ]],
    ]],
  ];
}

/** Jalankan satu alat. Mengembalikan ['tabel'=>..., 'ringkasan'=>..., 'catatan'=>...] atau melempar InvalidArgumentException (pesan boleh dibaca AI). */
function chat_alat_jalankan(PDO $pdo, string $nama, array $a): array {
  return match ($nama) {
    'stok_dokumen' => chat_alat_stok($pdo, $a),
    'transaksi'    => chat_alat_transaksi($pdo, $a),
    'rekap'        => chat_alat_rekap($pdo, $a),
    'kartu_stok'   => chat_alat_kartu($pdo, $a),
    default        => throw new InvalidArgumentException("Alat \"$nama\" tidak dikenal."),
  };
}

function chat_alat_stok(PDO $pdo, array $a): array {
  $status = chat_pilih($a['status'] ?? '', ['semua', 'habis', 'kritis', 'rendah', 'aman'], 'semua');
  $urut = chat_pilih($a['urut'] ?? '', ['nama', 'stok_terkecil', 'stok_terbesar', 'paling_cepat_habis', 'nilai_terbesar'], 'nama');
  $cari = chat_str($a['cari'] ?? '', 50);

  $sql = 'SELECT id,kode,nama,satuan,stok,rusak,terbit,harga FROM dokumen'; $p = [];
  if ($cari !== '') { $sql .= ' WHERE nama LIKE ? OR kode LIKE ?'; $p = [chat_like($cari), chat_like($cari)]; }
  $s = $pdo->prepare($sql); $s->execute($p);
  $rata = pemakaian($pdo);

  $baris = []; $hit = ['Habis' => 0, 'Kritis' => 0, 'Rendah' => 0, 'Aman' => 0]; $totStok = 0; $totNilai = 0;
  foreach ($s->fetchAll() as $d) {
    $st = status_stok($d['stok'], $rata[$d['id']] ?? 0);
    if ($status !== 'semua' && $st[1] !== $status) continue;
    $nilai = (int)$d['stok'] * (int)$d['harga'];
    $baris[] = [$d['kode'], $d['nama'], strtolower((string)$d['satuan']), (int)$d['stok'], $st[0], $st[2], (int)$d['rusak'], (int)$d['terbit'], (int)$d['harga'], $nilai];
    $hit[$st[0]]++; $totStok += (int)$d['stok']; $totNilai += $nilai;
  }
  usort($baris, function ($x, $y) use ($urut) {
    return match ($urut) {
      'stok_terkecil' => $x[3] <=> $y[3],
      'stok_terbesar' => $y[3] <=> $x[3],
      'nilai_terbesar' => $y[9] <=> $x[9],
      'paling_cepat_habis' => ($x[5] ?? PHP_INT_MAX) <=> ($y[5] ?? PHP_INT_MAX),
      default => strcasecmp($x[1], $y[1]),
    };
  });
  return [
    'tabel' => ['judul' => 'Stok dokumen' . ($status !== 'semua' ? " (status $status)" : '') . ($cari !== '' ? " — \"$cari\"" : ''),
      'kolom' => ['Kode', 'Dokumen', 'Satuan', 'Stok', 'Status', 'Perkiraan habis (hari)', 'Rusak', 'Terbit', 'Harga (Rp)', 'Nilai (Rp)'],
      'baris' => $baris],
    'ringkasan' => ['jumlah_jenis' => count($baris), 'total_stok' => $totStok, 'total_nilai_rp' => $totNilai, 'per_status' => $hit],
    'catatan' => 'Perkiraan habis dihitung dari rata-rata pengeluaran ' . HARI_RATA . ' hari terakhir; kosong (null) = belum ada pengeluaran di periode itu.',
  ];
}

function chat_alat_transaksi(PDO $pdo, array $a): array {
  $jenis = chat_pilih($a['jenis'] ?? '', ['semua', 'penerimaan', 'pengeluaran'], 'semua');
  $dari = chat_tgl($a['dari'] ?? '', 'dari'); $sampai = chat_tgl($a['sampai'] ?? '', 'sampai');
  $dok = chat_str($a['dokumen'] ?? '', 50); $tujuan = chat_str($a['tujuan'] ?? '', 50); $noBa = chat_str($a['no_ba'] ?? '', 60);
  $status = chat_pilih(strtolower((string)($a['status'] ?? '')), ['pending', 'terverifikasi'], '');
  $urut = chat_pilih($a['urut'] ?? '', ['terbaru', 'terlama', 'jumlah_terbesar'], 'terbaru');
  $batas = max(1, min(CHAT_MAKS_BARIS_TABEL, (int)($a['batas'] ?? 100)));

  $kaki = function (string $al, array &$p, array $extra = []) use ($dari, $sampai, $dok, $noBa): string {
    $w = [];
    if ($dari !== '')   { $w[] = "$al.tanggal >= ?"; $p[] = $dari; }
    if ($sampai !== '') { $w[] = "$al.tanggal <= ?"; $p[] = $sampai; }
    if ($dok !== '')    { $w[] = '(d.nama LIKE ? OR d.kode LIKE ?)'; $p[] = chat_like($dok); $p[] = chat_like($dok); }
    if ($noBa !== '')   { $w[] = "$al.no_ba LIKE ?"; $p[] = chat_like($noBa); }
    foreach ($extra as [$kond, $nilai]) { $w[] = $kond; $p[] = $nilai; }
    return $w ? ' WHERE ' . implode(' AND ', $w) : '';
  };

  $legs = []; $p = [];
  if (in_array($jenis, ['semua', 'penerimaan'], true) && $tujuan === '') {
    $extra = $status !== '' ? [['p.status = ?', $status === 'pending' ? 'Pending' : 'Terverifikasi']] : [];
    $legs[] = "SELECT p.tanggal, 'Penerimaan' AS jenis, d.kode, d.nama AS dokumen, p.jumlah, p.no_ba, p.seri_awal, p.seri_akhir, p.status AS keterangan, COALESCE(u.nama,'-') AS petugas, p.id AS rid FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id LEFT JOIN users u ON u.id=p.user_id" . $kaki('p', $p, $extra);
  }
  if (in_array($jenis, ['semua', 'pengeluaran'], true) && $status === '') {
    $extra = $tujuan !== '' ? [['k.tujuan LIKE ?', chat_like($tujuan)]] : [];
    $legs[] = "SELECT k.tanggal, 'Pengeluaran' AS jenis, d.kode, d.nama AS dokumen, k.jumlah, k.no_ba, k.seri_awal, k.seri_akhir, k.tujuan AS keterangan, COALESCE(u.nama,'-') AS petugas, k.id AS rid FROM pengeluaran k JOIN dokumen d ON d.id=k.dokumen_id LEFT JOIN users u ON u.id=k.user_id" . $kaki('k', $p, $extra);
  }
  if (!$legs) throw new InvalidArgumentException('Kombinasi saringan tidak cocok (mis. status hanya berlaku untuk penerimaan, tujuan hanya untuk pengeluaran).');
  $union = implode(' UNION ALL ', $legs);

  $s = $pdo->prepare("SELECT jenis, COUNT(*) n, COALESCE(SUM(jumlah),0) total FROM ($union) t GROUP BY jenis"); $s->execute($p);
  $agg = ['Penerimaan' => [0, 0], 'Pengeluaran' => [0, 0]];
  foreach ($s->fetchAll() as $r) $agg[$r['jenis']] = [(int)$r['n'], (int)$r['total']];
  $totalBaris = $agg['Penerimaan'][0] + $agg['Pengeluaran'][0];

  $order = match ($urut) { 'terlama' => 'tanggal ASC, rid ASC', 'jumlah_terbesar' => 'jumlah DESC, tanggal DESC', default => 'tanggal DESC, rid DESC' };
  $s = $pdo->prepare("SELECT * FROM ($union) t ORDER BY $order LIMIT " . (int)$batas); $s->execute($p);
  $baris = array_map(fn($r) => [$r['tanggal'], $r['jenis'], $r['kode'], $r['dokumen'], (int)$r['jumlah'], $r['no_ba'], $r['seri_awal'] ?? '', $r['seri_akhir'] ?? '', $r['keterangan'], $r['petugas']], $s->fetchAll());

  return [
    'tabel' => ['judul' => 'Transaksi' . ($jenis !== 'semua' ? " $jenis" : '') . ($dari || $sampai ? ' (' . ($dari ?: '…') . ' s/d ' . ($sampai ?: '…') . ')' : ''),
      'kolom' => ['Tanggal', 'Jenis', 'Kode', 'Dokumen', 'Jumlah', 'No. BA', 'Seri awal', 'Seri akhir', 'Tujuan / Status', 'Petugas'],
      'baris' => $baris, 'total_baris' => $totalBaris],
    'ringkasan' => ['total_transaksi_cocok' => $totalBaris, 'penerimaan' => ['transaksi' => $agg['Penerimaan'][0], 'total_jumlah' => $agg['Penerimaan'][1]],
      'pengeluaran' => ['transaksi' => $agg['Pengeluaran'][0], 'total_jumlah' => $agg['Pengeluaran'][1]], 'baris_ditampilkan' => count($baris)],
    'catatan' => $totalBaris > count($baris) ? 'Ada ' . $totalBaris . ' transaksi cocok; hanya ' . count($baris) . ' baris pertama (sesuai urutan) yang ditampilkan. Total di "ringkasan" mencakup SEMUA yang cocok.' : '',
  ];
}

function chat_alat_rekap(PDO $pdo, array $a): array {
  $kel = chat_pilih($a['kelompok'] ?? '', ['bulan', 'dokumen', 'tujuan', 'petugas'], '');
  if ($kel === '') throw new InvalidArgumentException('Parameter "kelompok" wajib: bulan, dokumen, tujuan, atau petugas.');
  $jenis = chat_pilih($a['jenis'] ?? '', ['keduanya', 'penerimaan', 'pengeluaran'], 'keduanya');
  if ($kel === 'tujuan') $jenis = 'pengeluaran';
  $dari = chat_tgl($a['dari'] ?? '', 'dari'); $sampai = chat_tgl($a['sampai'] ?? '', 'sampai'); $dok = chat_str($a['dokumen'] ?? '', 50);

  $sumber = chat_pilih($a['sumber'] ?? '', ['otomatis', 'kartu', 'transaksi'], 'otomatis');
  if ($kel === 'petugas') $sumber = 'transaksi';   // kartu stok harian tidak mencatat petugas
  if ($sumber !== 'transaksi') {
    $hasil = chat_alat_rekap_kartu($pdo, $kel, $dari, $sampai, $dok, $sumber === 'kartu');
    if ($hasil !== null) return $hasil;
  }

  $kunci = match ($kel) {
    'bulan' => ["DATE_FORMAT(%s.tanggal,'%%Y-%%m')", 'Bulan'],
    'dokumen' => ['d.kode', 'Dokumen'],
    'tujuan' => ['%s.tujuan', 'Tujuan'],
    'petugas' => ["COALESCE(u.nama,'(tanpa petugas)')", 'Petugas'],
  };
  $ambil = function (string $tabel, string $al) use ($pdo, $kunci, $kel, $dari, $sampai, $dok): array {
    $p = []; $w = [];
    if ($dari !== '')   { $w[] = "$al.tanggal >= ?"; $p[] = $dari; }
    if ($sampai !== '') { $w[] = "$al.tanggal <= ?"; $p[] = $sampai; }
    if ($dok !== '')    { $w[] = '(d.nama LIKE ? OR d.kode LIKE ?)'; $p[] = chat_like($dok); $p[] = chat_like($dok); }
    $k = str_contains($kunci[0], '%s') ? sprintf($kunci[0], $al) : $kunci[0];
    $nama = $kel === 'dokumen' ? ', MAX(d.nama) AS nama' : '';
    $sql = "SELECT $k AS grp, SUM($al.jumlah) AS total, COUNT(*) AS n$nama FROM $tabel $al JOIN dokumen d ON d.id=$al.dokumen_id LEFT JOIN users u ON u.id=$al.user_id"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' GROUP BY grp';
    $s = $pdo->prepare($sql); $s->execute($p);
    $o = [];
    foreach ($s->fetchAll() as $r) $o[(string)$r['grp']] = ['total' => (int)$r['total'], 'n' => (int)$r['n'], 'nama' => $r['nama'] ?? ''];
    return $o;
  };
  $masuk = $jenis !== 'pengeluaran' ? $ambil('penerimaan', 'p') : [];
  $keluar = $jenis !== 'penerimaan' ? $ambil('pengeluaran', 'k') : [];
  $semua = array_unique(array_merge(array_keys($masuk), array_keys($keluar)));

  $baris = [];
  foreach ($semua as $k) {
    $m = $masuk[$k]['total'] ?? 0; $o = $keluar[$k]['total'] ?? 0;
    $nm = $masuk[$k]['nama'] ?? ($keluar[$k]['nama'] ?? '');
    $baris[] = ['k' => (string)$k, 'nama' => $nm, 'm' => $m, 'o' => $o, 'nm' => $masuk[$k]['n'] ?? 0, 'no' => $keluar[$k]['n'] ?? 0];
  }
  if ($kel === 'bulan') usort($baris, fn($x, $y) => strcmp($x['k'], $y['k']));
  else usort($baris, fn($x, $y) => ($y['m'] + $y['o']) <=> ($x['m'] + $x['o']));
  $baris = array_slice($baris, 0, CHAT_MAKS_BARIS_TABEL);

  $tm = array_sum(array_column($baris, 'm')); $to = array_sum(array_column($baris, 'o'));
  if ($kel === 'tujuan') {
    $kolom = ['Tujuan', 'Jumlah dikeluarkan', 'Jumlah transaksi'];
    $out = array_map(fn($r) => [$r['k'], $r['o'], $r['no']], $baris);
    $out[] = ['TOTAL', $to, array_sum(array_column($baris, 'no'))];
  } else {
    $kolom = [$kunci[1]];
    if ($kel === 'dokumen') $kolom = ['Kode', 'Dokumen'];
    if ($jenis !== 'pengeluaran') $kolom[] = 'Penerimaan';
    if ($jenis !== 'penerimaan') $kolom[] = 'Pengeluaran';
    if ($jenis === 'keduanya') $kolom[] = 'Selisih (masuk - keluar)';
    $out = [];
    foreach ($baris as $r) {
      $x = $kel === 'dokumen' ? [$r['k'], $r['nama']] : [$r['k']];
      if ($jenis !== 'pengeluaran') $x[] = $r['m'];
      if ($jenis !== 'penerimaan') $x[] = $r['o'];
      if ($jenis === 'keduanya') $x[] = $r['m'] - $r['o'];
      $out[] = $x;
    }
    $t = $kel === 'dokumen' ? ['TOTAL', ''] : ['TOTAL'];
    if ($jenis !== 'pengeluaran') $t[] = $tm;
    if ($jenis !== 'penerimaan') $t[] = $to;
    if ($jenis === 'keduanya') $t[] = $tm - $to;
    $out[] = $t;
  }
  return [
    'tabel' => ['judul' => 'Rekap ' . ($jenis === 'keduanya' ? 'penerimaan & pengeluaran' : $jenis) . " per $kel" . ($dari || $sampai ? ' (' . ($dari ?: '…') . ' s/d ' . ($sampai ?: '…') . ')' : '') . ($dok !== '' ? " — \"$dok\"" : ''),
      'kolom' => $kolom, 'baris' => $out],
    'ringkasan' => ['jumlah_kelompok' => count($baris), 'total_penerimaan' => $tm, 'total_pengeluaran' => $to, 'selisih' => $tm - $to],
    'catatan' => 'Baris terakhir tabel adalah TOTAL.',
  ];
}

function chat_alat_kartu(PDO $pdo, array $a): array {
  $kata = chat_str($a['dokumen'] ?? '', 60);
  if ($kata === '') throw new InvalidArgumentException('Parameter "dokumen" wajib diisi (kode atau nama dokumen).');
  $dari = chat_tgl($a['dari'] ?? '', 'dari'); $sampai = chat_tgl($a['sampai'] ?? '', 'sampai');
  $hanyaMutasi = !array_key_exists('hanya_mutasi', $a) || filter_var($a['hanya_mutasi'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
  if ($dari === '') $dari = date('Y-m-01', strtotime('-2 months'));   // bawaan: 3 bulan terakhir
  if ($sampai === '') $sampai = date('Y-m-d');

  $s = $pdo->prepare('SELECT id,kode,nama,satuan FROM dokumen WHERE kode = ? OR nama = ?'); $s->execute([$kata, $kata]);
  $cocok = $s->fetchAll();
  if (!$cocok) { $s = $pdo->prepare('SELECT id,kode,nama,satuan FROM dokumen WHERE nama LIKE ? OR kode LIKE ? LIMIT 10'); $s->execute([chat_like($kata), chat_like($kata)]); $cocok = $s->fetchAll(); }
  if (!$cocok) throw new InvalidArgumentException("Tidak ada dokumen yang cocok dengan \"$kata\".");
  if (count($cocok) > 1) throw new InvalidArgumentException('Nama "' . $kata . '" cocok ke beberapa dokumen: ' . implode('; ', array_map(fn($d) => $d['kode'] . ' (' . $d['nama'] . ')', $cocok)) . '. Tanyakan ke pengguna yang mana, atau pakai kodenya.');
  $d = $cocok[0];

  try {
    $sql = 'SELECT tanggal,stok,penerimaan_jumlah,penerimaan_no_ba,pengeluaran_jumlah,pengeluaran_tujuan,pengeluaran_no_ba,terbit,rusak FROM kartu_stok_harian WHERE dokumen_id=? AND tanggal BETWEEN ? AND ?'
         . ($hanyaMutasi ? ' AND (penerimaan_jumlah > 0 OR pengeluaran_jumlah > 0)' : '') . ' ORDER BY tanggal LIMIT ' . (CHAT_MAKS_BARIS_TABEL + 1);
    $s = $pdo->prepare($sql); $s->execute([$d['id'], $dari, $sampai]);
    $rows = $s->fetchAll();
    $s = $pdo->prepare('SELECT COALESCE(SUM(penerimaan_jumlah),0) m, COALESCE(SUM(pengeluaran_jumlah),0) k FROM kartu_stok_harian WHERE dokumen_id=? AND tanggal BETWEEN ? AND ?'); $s->execute([$d['id'], $dari, $sampai]);
    $tot = $s->fetch();
    $s = $pdo->prepare('SELECT tanggal, stok FROM kartu_stok_harian WHERE dokumen_id=? AND tanggal BETWEEN ? AND ? ORDER BY tanggal DESC LIMIT 1'); $s->execute([$d['id'], $dari, $sampai]);
    $akhir = $s->fetch();
  } catch (PDOException $e) {
    throw new InvalidArgumentException('Data kartu stok harian belum tersedia di database ini. Gunakan alat "transaksi" sebagai gantinya.');
  }
  $terpotong = count($rows) > CHAT_MAKS_BARIS_TABEL; $rows = array_slice($rows, 0, CHAT_MAKS_BARIS_TABEL);
  $baris = array_map(fn($r) => [$r['tanggal'], (int)$r['stok'], (int)$r['penerimaan_jumlah'], $r['penerimaan_no_ba'] ?? '', (int)$r['pengeluaran_jumlah'], $r['pengeluaran_tujuan'] ?? '', $r['pengeluaran_no_ba'] ?? '', (int)$r['terbit'], (int)$r['rusak']], $rows);
  return [
    'tabel' => ['judul' => "Kartu stok {$d['kode']} — {$d['nama']} ($dari s/d $sampai)",
      'kolom' => ['Tanggal', 'Stok (akhir hari)', 'Penerimaan', 'No. BA masuk', 'Pengeluaran', 'Tujuan', 'No. BA keluar', 'Terbit', 'Rusak'],
      'baris' => $baris],
    'ringkasan' => ['dokumen' => $d['kode'] . ' - ' . $d['nama'], 'satuan' => strtolower((string)$d['satuan']), 'periode' => "$dari s/d $sampai",
      'total_penerimaan' => (int)$tot['m'], 'total_pengeluaran' => (int)$tot['k'], 'stok_di_akhir_periode' => $akhir ? (int)$akhir['stok'] : null, 'tanggal_data_terakhir' => $akhir['tanggal'] ?? null],
    'catatan' => ($hanyaMutasi ? 'Hanya hari yang ada mutasi. ' : '') . ($terpotong ? 'Dipotong di ' . CHAT_MAKS_BARIS_TABEL . ' baris. ' : '') . 'Total di "ringkasan" mencakup seluruh periode.',
  ];
}


/** Rekap dari kartu stok harian. Mengembalikan null kalau (mode otomatis) tidak ada datanya di periode itu. */
function chat_alat_rekap_kartu(PDO $pdo, string $kel, string $dari, string $sampai, string $dok, bool $wajib): ?array {
  $w = []; $p = [];
  if ($dari !== '')   { $w[] = 'c.tanggal >= ?'; $p[] = $dari; }
  if ($sampai !== '') { $w[] = 'c.tanggal <= ?'; $p[] = $sampai; }
  if ($dok !== '')    { $w[] = '(d.nama LIKE ? OR d.kode LIKE ?)'; $p[] = chat_like($dok); $p[] = chat_like($dok); }
  if ($kel === 'tujuan') $w[] = 'c.pengeluaran_jumlah > 0';
  $kunci = match ($kel) {
    'bulan' => "DATE_FORMAT(c.tanggal,'%Y-%m')",
    'dokumen' => 'd.kode',
    default => "COALESCE(NULLIF(c.pengeluaran_tujuan,''),'(tanpa tujuan)')",
  };
  $nama = $kel === 'dokumen' ? ', MAX(d.nama) AS nama' : ", '' AS nama";
  try {
    $sql = "SELECT $kunci AS grp, SUM(c.penerimaan_jumlah) AS m, SUM(c.pengeluaran_jumlah) AS o, SUM(c.terbit) AS t, SUM(c.rusak) AS r, SUM(c.pengeluaran_jumlah > 0) AS n$nama FROM kartu_stok_harian c JOIN dokumen d ON d.id=c.dokumen_id"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' GROUP BY grp';
    $s = $pdo->prepare($sql); $s->execute($p);
    $rows = $s->fetchAll();
  } catch (PDOException $e) {
    if ($wajib) throw new InvalidArgumentException('Data kartu stok harian belum tersedia di database ini. Pakai sumber "transaksi".');
    return null;
  }
  if (!$rows || array_sum(array_map(fn($r) => (int)$r['m'] + (int)$r['o'] + (int)$r['t'], $rows)) === 0) {
    if ($wajib) return ['tabel' => ['judul' => 'Rekap kartu stok harian (tidak ada data)', 'kolom' => ['Keterangan'], 'baris' => []], 'ringkasan' => ['jumlah_kelompok' => 0], 'catatan' => 'Tidak ada mutasi di kartu stok harian pada periode/saringan itu.'];
    return null;
  }
  if ($kel === 'bulan') usort($rows, fn($x, $y) => strcmp($x['grp'], $y['grp']));
  else usort($rows, fn($x, $y) => ((int)$y['m'] + (int)$y['o']) <=> ((int)$x['m'] + (int)$x['o']));
  $rows = array_slice($rows, 0, CHAT_MAKS_BARIS_TABEL);

  $tm = $to = $tt = $tr = 0; $out = [];
  foreach ($rows as $r) {
    $m = (int)$r['m']; $o = (int)$r['o']; $t = (int)$r['t']; $rz = (int)$r['r'];
    $tm += $m; $to += $o; $tt += $t; $tr += $rz;
    if ($kel === 'tujuan') $out[] = [$r['grp'], $o, (int)$r['n']];
    else $out[] = array_merge($kel === 'dokumen' ? [$r['grp'], $r['nama']] : [$r['grp']], [$m, $o, $m - $o, $t, $rz]);
  }
  if ($kel === 'tujuan') { $kolom = ['Tujuan', 'Jumlah dikeluarkan', 'Jumlah hari ada pengeluaran']; $out[] = ['TOTAL', $to, array_sum(array_column($out, 2))]; }
  else {
    $kolom = array_merge($kel === 'dokumen' ? ['Kode', 'Dokumen'] : ['Bulan'], ['Penerimaan', 'Pengeluaran', 'Selisih (masuk - keluar)', 'Terbit', 'Rusak']);
    $out[] = array_merge($kel === 'dokumen' ? ['TOTAL', ''] : ['TOTAL'], [$tm, $to, $tm - $to, $tt, $tr]);
  }
  return [
    'tabel' => ['judul' => "Rekap kartu stok harian per $kel" . ($dari || $sampai ? ' (' . ($dari ?: '…') . ' s/d ' . ($sampai ?: '…') . ')' : '') . ($dok !== '' ? " — \"$dok\"" : ''), 'kolom' => $kolom, 'baris' => $out],
    'ringkasan' => ['sumber_dipakai' => 'kartu_stok_harian', 'jumlah_kelompok' => count($rows), 'total_penerimaan' => $tm, 'total_pengeluaran' => $to, 'total_terbit' => $tt, 'total_rusak' => $tr, 'selisih' => $tm - $to],
    'catatan' => 'Sumber: kartu stok harian (riwayat harian lengkap). Baris terakhir adalah TOTAL. Data transaksi yang dicatat lewat aplikasi (No. BA, petugas) ada di sumber "transaksi".',
  ];
}
