<?php
/**
 * Logika "pintar" untuk impor_excel.php: baca rekap Excel bergaya kartu stok
 * (satu sheet per jenis dokumen, baris harian, grup kolom Penerimaan/Pengeluaran)
 * dan temukan baris penerimaan yang belum tercatat di database.
 *
 * Tidak menebak posisi kolom secara tetap (kolom bisa beda tiap sheet) — selalu
 * mencari label header-nya dulu ("No", "Bulan", "Penerimaan", "Jumlah", dst),
 * baru membaca data di bawahnya. Jadi tetap jalan walau sheet lain tahun
 * berikutnya kolomnya sedikit bergeser, asal pola labelnya serupa.
 */

const IMPOR_BULAN = [
  'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
  'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
];

function impor_normalisasi(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/', '', $s);
  return $s;
}

/** Skor kemiripan 0..100 antara dua teks (dipakai untuk menebak sheet ini dokumen apa). */
function impor_skor_kemiripan(string $a, string $b): float {
  $na = impor_normalisasi($a);
  $nb = impor_normalisasi($b);
  if ($na === '' || $nb === '') return 0;
  if ($na === $nb) return 100;
  similar_text($na, $nb, $persen);
  // tambahan: skor berdasar kata-kata yang sama persis (robust ke urutan kata beda)
  $wa = array_filter(preg_split('/[^a-z0-9]+/', strtolower($a)));
  $wb = array_filter(preg_split('/[^a-z0-9]+/', strtolower($b)));
  $irisan = count(array_intersect($wa, $wb));
  $gabungan = count(array_unique(array_merge($wa, $wb))) ?: 1;
  $skorKata = ($irisan / $gabungan) * 100;
  return max($persen, $skorKata);
}

function sel(array $rows, int $r, int $c) {
  return $rows[$r][$c] ?? null;
}

/** Cari baris & kolom pertama dalam area tertentu yang isinya (dinormalisasi) cocok. */
function impor_cari_label(array $rows, int $rMin, int $rMaks, int $cMin, int $cMaks, string $labelDicari): ?array {
  for ($r = $rMin; $r <= $rMaks; $r++) {
    for ($c = $cMin; $c <= $cMaks; $c++) {
      $v = sel($rows, $r, $c);
      if ($v !== null && str_contains(impor_normalisasi((string)$v), $labelDicari)) return [$r, $c];
    }
  }
  return null;
}

/** Baris terakhir yang punya data di sheet ini. */
function impor_baris_maks(array $rows): int {
  return $rows ? max(array_keys($rows)) : 0;
}

/**
 * Baca satu sheet, temukan grup kolom "Penerimaan", lalu kembalikan semua baris
 * yang jumlah penerimaannya > 0 (artinya memang ada transaksi barang masuk).
 * Hasil: ['ok'=>bool, 'pesan'=>?string, 'baris'=>array, 'peringatan'=>array]
 */
function impor_ekstrak_penerimaan(array $rows): array {
  $peringatan = [];

  // 1) baris header anchor: kolom B='No', C='Bulan', D='Tgl'
  $rNo = null;
  for ($r = 1; $r <= 12; $r++) {
    if (impor_normalisasi((string)sel($rows, $r, 2)) === 'no'
        && impor_normalisasi((string)sel($rows, $r, 3)) === 'bulan'
        && impor_normalisasi((string)sel($rows, $r, 4)) === 'tgl') { $rNo = $r; break; }
  }
  if ($rNo === null) return ['ok' => false, 'pesan' => 'Baris header "No / Bulan / Tgl" tidak ditemukan di 12 baris pertama.', 'baris' => [], 'peringatan' => []];

  // 2) cari kolom awal grup "Pengeluaran" sebagai batas kanan grup Penerimaan
  $cMaks = 60;
  $posPengeluaran = impor_cari_label($rows, $rNo, $rNo, 6, $cMaks, 'pengeluaran');
  if (!$posPengeluaran) return ['ok' => false, 'pesan' => 'Kolom grup "Pengeluaran" tidak ditemukan — tidak bisa menentukan batas grup Penerimaan.', 'baris' => [], 'peringatan' => []];
  $colPengeluaran = $posPengeluaran[1];

  // 3) grup Penerimaan = label tidak-kosong terakhir di baris $rNo sebelum kolom Pengeluaran
  //    (dicari posisinya, bukan cocok persis teks "Penerimaan" — beberapa sheet salah ketik labelnya)
  $colPenerimaan = null;
  for ($c = $colPengeluaran - 1; $c >= 6; $c--) {
    if (sel($rows, $rNo, $c) !== null) { $colPenerimaan = $c; break; }
  }
  if ($colPenerimaan === null) return ['ok' => false, 'pesan' => 'Kolom grup Penerimaan tidak ditemukan (area sebelum grup Pengeluaran kosong).', 'baris' => [], 'peringatan' => []];

  // 4) di dalam grup Penerimaan, cari sub-label: Jumlah, No BA, No.Seri (bisa di baris mana saja, sampai 4 baris di bawah header)
  $pJumlah = impor_cari_label($rows, $rNo + 1, $rNo + 4, $colPenerimaan, $colPengeluaran - 1, 'jumlah');
  $pNoBa   = impor_cari_label($rows, $rNo + 1, $rNo + 4, $colPenerimaan, $colPengeluaran - 1, 'noba');
  $pNoSeri = impor_cari_label($rows, $rNo + 1, $rNo + 4, $colPenerimaan, $colPengeluaran - 1, 'noseri');
  if (!$pJumlah || !$pNoBa) return ['ok' => false, 'pesan' => 'Sub-kolom "Jumlah" dan/atau "No BA" tidak ditemukan di grup Penerimaan.', 'baris' => [], 'peringatan' => []];
  $colJumlah = $pJumlah[1]; $colNoBa = $pNoBa[1];
  $colNoSeriAwal = $colNoSeriAkhir = null;
  if ($pNoSeri) {
    $awal  = impor_cari_label($rows, $pNoSeri[0], $pNoSeri[0] + 2, $pNoSeri[1], $colPengeluaran - 1, 'awal');
    $akhir = impor_cari_label($rows, $pNoSeri[0], $pNoSeri[0] + 2, $pNoSeri[1], $colPengeluaran - 1, 'akhir');
    if ($awal) $colNoSeriAwal = $awal[1];
    if ($akhir) $colNoSeriAkhir = $akhir[1];
  }
  if (!$colNoSeriAwal || !$colNoSeriAkhir) $peringatan[] = 'Kolom rentang nomor seri (Awal/Akhir) tidak ditemukan — nomor seri akan dikosongkan di hasil impor.';

  // 5) baris pertama data = baris pertama setelah header yang kolom "Tgl"-nya berisi angka
  $dataMulai = null;
  $rMaks = impor_baris_maks($rows);
  for ($r = $rNo + 1; $r <= min($rNo + 8, $rMaks); $r++) {
    if (is_numeric(sel($rows, $r, 4))) { $dataMulai = $r; break; }
  }
  if ($dataMulai === null) return ['ok' => false, 'pesan' => 'Baris data pertama (kolom Tgl berisi angka) tidak ditemukan.', 'baris' => [], 'peringatan' => []];

  // 6) jalan di semua baris data, ambil yang Jumlah Penerimaan > 0
  $hasil = [];
  $bulanAktif = null;
  for ($r = $dataMulai; $r <= $rMaks; $r++) {
    $c2 = sel($rows, $r, 2);
    if ($c2 !== null && impor_normalisasi((string)$c2) === 'total') { $bulanAktif = null; continue; }

    $bulanTeks = sel($rows, $r, 3);
    if ($bulanTeks !== null) {
      $key = impor_normalisasi((string)$bulanTeks);
      if (isset(IMPOR_BULAN[$key])) $bulanAktif = IMPOR_BULAN[$key];
    }

    $jumlah = sel($rows, $r, $colJumlah);
    if ($jumlah === null || (float)$jumlah <= 0) continue;
    if (!is_numeric(sel($rows, $r, 4))) continue;   // baris tanpa tanggal = baris rekap/total di bawah tabel, bukan transaksi

    if ($bulanAktif === null) { $peringatan[] = "Baris $r: ada Jumlah penerimaan (" . (int)$jumlah . ") tapi bulannya tidak diketahui — dilewati."; continue; }

    $noBa = trim((string)sel($rows, $r, $colNoBa));
    if ($noBa === '') { $peringatan[] = "Baris $r: Jumlah penerimaan ada tapi No. BA kosong — dilewati."; continue; }

    $seriAwal = $colNoSeriAwal ? trim((string)(sel($rows, $r, $colNoSeriAwal) ?? '')) : '';
    $seriAkhir = $colNoSeriAkhir ? trim((string)(sel($rows, $r, $colNoSeriAkhir) ?? '')) : '';

    $hasil[] = [
      'baris_excel' => $r,
      'bulan' => $bulanAktif,
      'tgl' => (int)sel($rows, $r, 4),
      'jumlah' => (int)$jumlah,
      'no_ba' => $noBa,
      'seri_awal' => $seriAwal !== '' ? $seriAwal : null,
      'seri_akhir' => $seriAkhir !== '' ? $seriAkhir : null,
    ];
  }

  return ['ok' => true, 'pesan' => null, 'baris' => $hasil, 'peringatan' => $peringatan];
}


/** Ambil nilai nomor seri dari satu sel + sel di sebelah kanannya: huruf awalan ("X", "E", "V5B") ada di salah satu, angkanya di yang lain. */
function impor_seri_sel(array $rows, int $r, int $c, bool $ambilAkhir): ?string {
  $awalan = ''; $angka = null;
  foreach ([$c, $c + 1] as $cc) {
    $v = sel($rows, $r, $cc);
    if ($v === null || $v === '') continue;
    if (is_numeric($v) && (float)$v > 0) { $angka = (string)(int)$v; continue; }
    $t = trim((string)$v);
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $t)) { $awalan = $t; continue; }
    // beberapa angka dalam satu sel (dipisah baris baru): awal = yang pertama, akhir = yang terakhir
    $bagian = preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY);
    $pilih = $ambilAkhir ? end($bagian) : ($bagian[0] ?? '');
    if ($pilih !== '' && $pilih !== false && ctype_digit($pilih) && (int)$pilih > 0) $angka = $pilih;
  }
  return $angka === null ? null : $awalan . $angka;
}

/**
 * Seperti impor_ekstrak_penerimaan(), tapi untuk grup kolom "Pengeluaran":
 * ambil semua baris yang Jumlah pengeluarannya > 0 beserta tujuan, No. BA, dan rentang nomor seri.
 * Hasil: ['ok'=>bool, 'pesan'=>?string, 'baris'=>array, 'peringatan'=>array]
 */
function impor_ekstrak_pengeluaran(array $rows): array {
  $peringatan = [];
  $gagal = fn(string $p) => ['ok' => false, 'pesan' => $p, 'baris' => [], 'peringatan' => []];

  $rNo = null;
  for ($r = 1; $r <= 12; $r++) {
    if (impor_normalisasi((string)sel($rows, $r, 2)) === 'no'
        && impor_normalisasi((string)sel($rows, $r, 3)) === 'bulan'
        && impor_normalisasi((string)sel($rows, $r, 4)) === 'tgl') { $rNo = $r; break; }
  }
  if ($rNo === null) return $gagal('Baris header "No / Bulan / Tgl" tidak ditemukan di 12 baris pertama.');

  $pos = impor_cari_label($rows, $rNo, $rNo, 6, 60, 'pengeluaran');
  if (!$pos) return $gagal('Kolom grup "Pengeluaran" tidak ditemukan.');
  $cAwalGrup = $pos[1];

  // grup Pengeluaran berakhir sebelum label berikutnya di baris header (mis. "Terbit" / "Rusak")
  $cAkhirGrup = $cAwalGrup + 12;
  for ($c = $cAwalGrup + 1; $c <= $cAwalGrup + 12; $c++) {
    if (sel($rows, $rNo, $c) !== null) { $cAkhirGrup = $c - 1; break; }
  }

  $pJumlah = impor_cari_label($rows, $rNo + 1, $rNo + 4, $cAwalGrup, $cAkhirGrup, 'jumlah');
  $pNoBa   = impor_cari_label($rows, $rNo + 1, $rNo + 4, $cAwalGrup, $cAkhirGrup, 'noba');
  $pTujuan = impor_cari_label($rows, $rNo + 1, $rNo + 4, $cAwalGrup, $cAkhirGrup, 'tujuan');
  $pSeri   = impor_cari_label($rows, $rNo + 1, $rNo + 4, $cAwalGrup, $cAkhirGrup, 'noseri');
  if (!$pJumlah) return $gagal('Sub-kolom "Jumlah" tidak ditemukan di grup Pengeluaran.');
  $cJumlah = $pJumlah[1];
  $cNoBa = $pNoBa ? $pNoBa[1] : null;
  $cTujuan = $pTujuan ? $pTujuan[1] : null;
  $cAwalSeri = $cAkhirSeri = null;
  if ($pSeri) {
    $a = impor_cari_label($rows, $pSeri[0], $pSeri[0] + 2, $pSeri[1], $cAkhirGrup, 'awal');
    $z = impor_cari_label($rows, $pSeri[0], $pSeri[0] + 2, $pSeri[1], $cAkhirGrup, 'akhir');
    if ($a) $cAwalSeri = $a[1];
    if ($z) $cAkhirSeri = $z[1];
  }
  if (!$cNoBa) $peringatan[] = 'Pengeluaran: kolom No. BA tidak ditemukan — No. BA akan diisi otomatis (ARSIP-...).';
  if (!$cAwalSeri || !$cAkhirSeri) $peringatan[] = 'Pengeluaran: kolom rentang nomor seri tidak ditemukan — nomor seri dikosongkan.';

  $rMaks = impor_baris_maks($rows);
  $dataMulai = null;
  for ($r = $rNo + 1; $r <= min($rNo + 8, $rMaks); $r++) {
    if (is_numeric(sel($rows, $r, 4))) { $dataMulai = $r; break; }
  }
  if ($dataMulai === null) return $gagal('Baris data pertama (kolom Tgl berisi angka) tidak ditemukan.');

  $hasil = []; $bulanAktif = null;
  for ($r = $dataMulai; $r <= $rMaks; $r++) {
    $c2 = sel($rows, $r, 2);
    if ($c2 !== null && impor_normalisasi((string)$c2) === 'total') { $bulanAktif = null; continue; }
    $bulanTeks = sel($rows, $r, 3);
    if ($bulanTeks !== null) { $key = impor_normalisasi((string)$bulanTeks); if (isset(IMPOR_BULAN[$key])) $bulanAktif = IMPOR_BULAN[$key]; }

    $jumlah = sel($rows, $r, $cJumlah);
    if ($jumlah === null || !is_numeric($jumlah) || (float)$jumlah <= 0) continue;
    if (!is_numeric(sel($rows, $r, 4))) continue;   // baris tanpa tanggal = baris rekap/total di bawah tabel, bukan transaksi
    if ($bulanAktif === null) { $peringatan[] = "Pengeluaran baris $r: bulan tidak diketahui — dilewati."; continue; }

    $noBa = $cNoBa ? trim((string)(sel($rows, $r, $cNoBa) ?? '')) : '';
    if ($noBa === '-') $noBa = '';
    $noBa = trim(preg_replace('/[ \t]+(?=\R|$)/', '', $noBa));
    $tujuan = $cTujuan ? trim((string)(sel($rows, $r, $cTujuan) ?? '')) : '';
    $awal  = $cAwalSeri  ? impor_seri_sel($rows, $r, $cAwalSeri, false) : null;
    $akhir = $cAkhirSeri ? impor_seri_sel($rows, $r, $cAkhirSeri, true) : null;

    $hasil[] = [
      'baris_excel' => $r, 'bulan' => $bulanAktif, 'tgl' => (int)sel($rows, $r, 4), 'jumlah' => (int)$jumlah,
      'no_ba' => $noBa, 'tujuan' => $tujuan, 'seri_awal' => $awal, 'seri_akhir' => $akhir,
    ];
  }
  return ['ok' => true, 'pesan' => null, 'baris' => $hasil, 'peringatan' => $peringatan];
}