<?php
/**
 * Menjaga kolom dokumen.seri_aktif (stok aktif) dan dokumen.seri_simpan (stok tersimpan)
 * tetap sesuai transaksi:
 *   - Penerimaan baru  -> rentang serinya masuk ke STOK TERSIMPAN (kalau stok aktif kosong, langsung jadi stok aktif).
 *   - Pengeluaran      -> rentang serinya dikurangi dari STOK AKTIF. Stok tersimpan hanya dipakai kalau petugas memilih
 *                         opsi "pakai stok tersimpan" (otomatis berkurang, dan kalau stok aktif kosong blok itu jadi stok aktif).
 * Format kolom: "X8664751 - X8668500" atau beberapa blok dipisah koma: "E9006251 - E9007750, E8720135 - E8720250".
 * Kalau hasilnya lebih dari 60 karakter (batas kolom) atau seri tidak bisa dibaca, kolom dibiarkan apa adanya (return false)
 * dan petugas bisa memperbaikinya manual di halaman detail dokumen.
 */

/** "A - B, C - D" -> [['p'=>prefix,'a'=>int,'z'=>int,'w'=>lebar angka], ...] ; '-' / kosong -> [] ; null kalau ada yang tidak terbaca */
function seri_stok_baca(?string $teks): ?array {
  $teks = trim((string)$teks);
  if ($teks === '' || $teks === '-') return [];
  $hasil = [];
  foreach (explode(',', $teks) as $bagian) {
    $bagian = trim($bagian);
    if ($bagian === '') continue;
    $dua = preg_split('/\s+[-–]\s+/u', $bagian);
    if (count($dua) !== 2) return null;
    $a = seri_pecah(trim($dua[0])); $z = seri_pecah(trim($dua[1]));
    if (!$a || !$z || strcasecmp(trim($a['prefix']), trim($z['prefix'])) !== 0) return null;
    $hasil[] = ['p' => trim($a['prefix']), 'a' => (int)$a['num'], 'z' => (int)$z['num'], 'w' => max(strlen($a['num']), strlen($z['num']))];
  }
  return $hasil;
}

function seri_stok_tulis(array $blok): string {
  if (!$blok) return '-';
  return implode(', ', array_map(fn($b) => $b['p'] . str_pad((string)$b['a'], $b['w'], '0', STR_PAD_LEFT) . ' - ' . $b['p'] . str_pad((string)$b['z'], $b['w'], '0', STR_PAD_LEFT), $blok));
}

/** Kurangi interval [a,z] (awalan $p) dari daftar blok. */
function seri_stok_kurangi_blok(array $blok, string $p, int $a, int $z): array {
  $baru = [];
  foreach ($blok as $b) {
    if (strcasecmp($b['p'], $p) !== 0 || $z < $b['a'] || $a > $b['z']) { $baru[] = $b; continue; }
    if ($a > $b['a']) $baru[] = ['p' => $b['p'], 'a' => $b['a'], 'z' => $a - 1, 'w' => $b['w']];
    if ($z < $b['z']) $baru[] = ['p' => $b['p'], 'a' => $z + 1, 'z' => $b['z'], 'w' => $b['w']];
  }
  return $baru;
}

function seri_stok_ambil(PDO $pdo, int $dokId): ?array {
  $s = $pdo->prepare('SELECT seri_aktif, seri_simpan FROM dokumen WHERE id=? FOR UPDATE'); $s->execute([$dokId]);
  $d = $s->fetch();
  if (!$d) return null;
  $ak = seri_stok_baca($d['seri_aktif']); $sm = seri_stok_baca($d['seri_simpan']);
  return ($ak === null || $sm === null) ? null : [$ak, $sm];
}

function seri_stok_simpan(PDO $pdo, int $dokId, array $ak, array $sm): bool {
  $ta = seri_stok_tulis($ak); $ts = seri_stok_tulis($sm);
  if (mb_strlen($ta) > 60 || mb_strlen($ts) > 60) return false;
  $pdo->prepare('UPDATE dokumen SET seri_aktif=?, seri_simpan=? WHERE id=?')->execute([$ta, $ts, $dokId]);
  return true;
}

/** Penerimaan baru: rentang seri masuk ke stok tersimpan. */
function seri_stok_masuk(PDO $pdo, int $dokId, string $awal, string $akhir): bool {
  $a = seri_pecah(trim($awal)); $z = seri_pecah(trim($akhir));
  if (!$a || !$z || strcasecmp(trim($a['prefix']), trim($z['prefix'])) !== 0 || (int)$a['num'] > (int)$z['num']) return false;
  $st = seri_stok_ambil($pdo, $dokId); if ($st === null) return false;
  [$ak, $sm] = $st;
  $blok = ['p' => trim($a['prefix']), 'a' => (int)$a['num'], 'z' => (int)$z['num'], 'w' => max(strlen($a['num']), strlen($z['num']))];
  if ($ak) $sm[] = $blok; else $ak[] = $blok;
  return seri_stok_simpan($pdo, $dokId, $ak, $sm);
}

/** Berapa keping dari rentang [a,z] (awalan $p) yang ada di dalam daftar blok. */
function seri_stok_irisan(array $blok, string $p, int $a, int $z): int {
  $n = 0;
  foreach ($blok as $b) {
    if (strcasecmp($b['p'], $p) !== 0) continue;
    $lo = max($a, $b['a']); $hi = min($z, $b['z']);
    if ($hi >= $lo) $n += $hi - $lo + 1;
  }
  return $n;
}

/**
 * Rentang seri ini ada di mana? 'aktif' | 'simpan' (stok tersimpan/gudang) | 'luar' (tidak tercatat di keduanya) | 'salah' (tidak terbaca).
 * Dikembalikan juga ringkasan stok supaya form bisa memberi tahu petugas.
 */
function seri_stok_posisi(PDO $pdo, int $dokId, string $awal, string $akhir): array {
  $a = seri_pecah(trim($awal)); $z = seri_pecah(trim($akhir));
  if (!$a || !$z || strcasecmp(trim($a['prefix']), trim($z['prefix'])) !== 0 || (int)$a['num'] > (int)$z['num']) return ['posisi' => 'salah'];
  $st = seri_stok_ambil($pdo, $dokId); if ($st === null) return ['posisi' => 'salah'];
  [$ak, $sm] = $st;
  $p = trim($a['prefix']); $na = (int)$a['num']; $nz = (int)$z['num']; $n = $nz - $na + 1;
  $pos = seri_stok_irisan($ak, $p, $na, $nz) === $n ? 'aktif' : (seri_stok_irisan($sm, $p, $na, $nz) === $n ? 'simpan' : 'luar');
  return ['posisi' => $pos, 'aktif_kosong' => !$ak, 'simpan_ada' => (bool)$sm, 'simpan_teks' => seri_stok_tulis($sm), 'simpan_jumlah' => seri_stok_jumlah($sm)];
}

/**
 * Pengeluaran: rentang seri dikurangi dari stok.
 *  - Ada di stok aktif -> dikurangi dari stok aktif.
 *  - Ada di stok tersimpan -> hanya jalan kalau $pakaiSimpan = true (opsi dari petugas). Kalau stok aktif sedang kosong,
 *    blok tersimpan itu dijadikan stok aktif dulu, lalu dikurangi.
 *  - Tidak tercatat di keduanya -> tidak ada yang diubah.
 * Hasil: ['ok'=>bool, 'posisi'=>..., 'aktif_habis'=>bool, 'simpan_habis'=>bool, 'simpan_teks'=>string, 'simpan_jumlah'=>int]
 */
function seri_stok_keluar(PDO $pdo, int $dokId, string $awal, string $akhir, bool $pakaiSimpan = false): array {
  $info = seri_stok_posisi($pdo, $dokId, $awal, $akhir);
  $gagal = $info + ['ok' => false, 'aktif_habis' => false, 'simpan_habis' => false];
  if (!in_array($info['posisi'], ['aktif', 'simpan'], true)) return $gagal;
  if ($info['posisi'] === 'simpan' && !$pakaiSimpan) return $gagal;

  [$ak, $sm] = seri_stok_ambil($pdo, $dokId);
  $a = seri_pecah(trim($awal)); $p = trim($a['prefix']); $na = (int)$a['num']; $nz = (int)seri_pecah(trim($akhir))['num'];
  $simpanAda = (bool)$sm;
  if ($info['posisi'] === 'aktif') {
    $ak = seri_stok_kurangi_blok($ak, $p, $na, $nz);
  } elseif (!$ak) {                                        // stok aktif kosong -> blok tersimpan yang dipakai naik jadi stok aktif
    foreach ($sm as $i => $b) {
      if (strcasecmp($b['p'], $p) === 0 && $na >= $b['a'] && $nz <= $b['z']) { $ak = [$b]; unset($sm[$i]); $sm = array_values($sm); break; }
    }
    $ak = seri_stok_kurangi_blok($ak, $p, $na, $nz);
  } else {                                                 // aktif masih ada, tapi petugas memilih memakai stok tersimpan
    $sm = seri_stok_kurangi_blok($sm, $p, $na, $nz);
  }
  $ok = seri_stok_simpan($pdo, $dokId, $ak, $sm);
  return ['ok' => $ok, 'posisi' => $info['posisi'], 'aktif_habis' => $ok && !$ak, 'simpan_habis' => $ok && $simpanAda && !$sm,
          'simpan_teks' => seri_stok_tulis($sm), 'simpan_jumlah' => seri_stok_jumlah($sm)];
}

/** Gabungkan blok yang nomornya bersambung (awalan sama), urut naik. */
function seri_stok_rapikan(array $blok): array {
  usort($blok, fn($x, $y) => [strtolower($x['p']), $x['a']] <=> [strtolower($y['p']), $y['a']]);
  $hasil = [];
  foreach ($blok as $b) {
    $n = count($hasil);
    if ($n && strcasecmp($hasil[$n - 1]['p'], $b['p']) === 0 && $b['a'] <= $hasil[$n - 1]['z'] + 1) $hasil[$n - 1]['z'] = max($hasil[$n - 1]['z'], $b['z']);
    else $hasil[] = $b;
  }
  return $hasil;
}

/** Total keping dalam daftar blok. */
function seri_stok_jumlah(array $blok): int {
  return array_sum(array_map(fn($b) => $b['z'] - $b['a'] + 1, $blok));
}

/** Pengeluaran dihapus: rentang serinya kembali ke stok aktif. */
function seri_stok_kembali(PDO $pdo, int $dokId, ?string $awal, ?string $akhir): bool {
  $a = seri_pecah(trim((string)$awal)); $z = seri_pecah(trim((string)$akhir));
  if (!$a || !$z || strcasecmp(trim($a['prefix']), trim($z['prefix'])) !== 0 || (int)$a['num'] > (int)$z['num']) return false;
  $st = seri_stok_ambil($pdo, $dokId); if ($st === null) return false;
  [$ak, $sm] = $st;
  $ak[] = ['p' => trim($a['prefix']), 'a' => (int)$a['num'], 'z' => (int)$z['num'], 'w' => max(strlen($a['num']), strlen($z['num']))];
  return seri_stok_simpan($pdo, $dokId, seri_stok_rapikan($ak), $sm);
}

/** Penerimaan dihapus: rentang serinya dicabut dari stok aktif/tersimpan. */
function seri_stok_cabut(PDO $pdo, int $dokId, ?string $awal, ?string $akhir): bool {
  $a = seri_pecah(trim((string)$awal)); $z = seri_pecah(trim((string)$akhir));
  if (!$a || !$z || strcasecmp(trim($a['prefix']), trim($z['prefix'])) !== 0 || (int)$a['num'] > (int)$z['num']) return false;
  $st = seri_stok_ambil($pdo, $dokId); if ($st === null) return false;
  [$ak, $sm] = $st;
  $p = trim($a['prefix']); $na = (int)$a['num']; $nz = (int)$z['num'];
  $ak2 = seri_stok_kurangi_blok($ak, $p, $na, $nz); $sm2 = seri_stok_kurangi_blok($sm, $p, $na, $nz);
  if ($ak2 === $ak && $sm2 === $sm) return false;
  if (!$ak2 && $sm2) $ak2[] = array_shift($sm2);
  return seri_stok_simpan($pdo, $dokId, $ak2, $sm2);
}

/* ============================================================
 * Riwayat blok seri (stok awal tahun dari Excel + penerimaan), lengkap dengan pemakaiannya.
 * Pemakaian dihitung dari rentang seri di tabel pengeluaran, jadi tidak perlu dirawat manual.
 * ============================================================ */

function seri_blok_siapkan(PDO $pdo): void {
  static $sudah = false; if ($sudah) return; $sudah = true;
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS seri_blok (
      id INT AUTO_INCREMENT PRIMARY KEY, dokumen_id INT NOT NULL, jenis VARCHAR(12) NOT NULL DEFAULT 'tersimpan',
      seri_awal VARCHAR(30) NOT NULL, seri_akhir VARCHAR(30) NOT NULL, catatan VARCHAR(120) DEFAULT NULL,
      tanggal DATE NOT NULL DEFAULT '2026-01-01', KEY (dokumen_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) { /* abaikan: riwayat saja yang tidak tampil */ }
}

/** @return array<int,array{asal:string,tanggal:string,awal:string,akhir:string,jumlah:int,terpakai:int,sisa:int,status:string}> */
function seri_blok_riwayat(PDO $pdo, int $dokId): array {
  seri_blok_siapkan($pdo);
  $daftar = [];
  try {
    $s = $pdo->prepare('SELECT tanggal, seri_awal, seri_akhir, jenis, catatan FROM seri_blok WHERE dokumen_id=? ORDER BY id'); $s->execute([$dokId]);
    foreach ($s as $r) $daftar[] = [$r['tanggal'], $r['seri_awal'], $r['seri_akhir'], $r['catatan'] ?: ('Stok awal tahun (' . ($r['jenis'] === 'aktif' ? 'aktif' : 'tersimpan') . ')'), 0];
  } catch (Throwable $e) { /* tabel belum ada */ }
  $s = $pdo->prepare('SELECT tanggal, seri_awal, seri_akhir, no_ba FROM penerimaan WHERE dokumen_id=? AND seri_awal IS NOT NULL AND seri_awal<>"" ORDER BY tanggal, id'); $s->execute([$dokId]);
  foreach ($s as $r) $daftar[] = [$r['tanggal'], $r['seri_awal'], $r['seri_akhir'], 'Penerimaan ' . $r['no_ba'], 1];
  if (!$daftar) return [];

  $keluar = [];
  $s = $pdo->prepare('SELECT seri_awal, seri_akhir FROM pengeluaran WHERE dokumen_id=? AND seri_awal IS NOT NULL AND seri_awal<>""'); $s->execute([$dokId]);
  foreach ($s as $r) {
    $a = seri_pecah((string)$r['seri_awal']); $z = seri_pecah((string)$r['seri_akhir']);
    if ($a && $z && strcasecmp(trim($a['prefix']), trim($z['prefix'])) === 0) $keluar[] = ['p' => trim($a['prefix']), 'a' => (int)$a['num'], 'z' => (int)$z['num'], 'w' => 0];
  }
  $hasil = [];
  foreach ($daftar as [$tgl, $aw, $ak, $asal]) {
    $a = seri_pecah($aw); $z = seri_pecah($ak);
    if (!$a || !$z) continue;
    $p = trim($a['prefix']); $na = (int)$a['num']; $nz = (int)$z['num']; $jml = $nz - $na + 1;
    $pakai = min($jml, seri_stok_irisan($keluar, $p, $na, $nz));
    $hasil[] = ['asal' => $asal, 'tanggal' => $tgl, 'awal' => $aw, 'akhir' => $ak, 'jumlah' => $jml, 'terpakai' => $pakai, 'sisa' => $jml - $pakai,
                'status' => $pakai >= $jml ? 'Habis' : ($pakai > 0 ? 'Sebagian dipakai' : 'Utuh')];
  }
  return $hasil;
}

/* Cek apakah rentang seri penerimaan baru bertabrakan dengan penerimaan yang sudah tercatat (dokumen sama).
   Hasil: teks pesan kalau bertabrakan, null kalau aman. */
function seri_terima_tabrakan(PDO $pdo, int $dokId, string $awal, string $akhir): ?string {
  $a = seri_pecah($awal); $z = seri_pecah($akhir);
  if (!$a || !$z || strcasecmp(trim($a['prefix']), trim($z['prefix'])) !== 0) return null;
  $na = (int)$a['num']; $nz = (int)$z['num'];
  $q = $pdo->prepare('SELECT no_ba, tanggal, seri_awal, seri_akhir FROM penerimaan WHERE dokumen_id=? AND seri_awal IS NOT NULL AND seri_akhir IS NOT NULL');
  $q->execute([$dokId]);
  foreach ($q->fetchAll() as $r) {
    $p = seri_pecah((string)$r['seri_awal']); $k = seri_pecah((string)$r['seri_akhir']);
    if (!$p || !$k || strcasecmp(trim($p['prefix']), trim($a['prefix'])) !== 0) continue;
    if ((int)$p['num'] <= $nz && (int)$k['num'] >= $na)
      return 'Nomor seri ' . $awal . ' – ' . $akhir . ' bertabrakan dengan penerimaan No. BA ' . $r['no_ba'] . ' (' . $r['tanggal'] . ', seri ' . $r['seri_awal'] . ' – ' . $r['seri_akhir'] . ').';
  }
  return null;
}
