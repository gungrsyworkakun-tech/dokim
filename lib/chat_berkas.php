<?php
/**
 * Berkas data (xlsx/csv) yang dilampirkan pengguna ke chat AI: dibaca, dibersihkan, disimpan sebagai tabel kecil,
 * lalu dianalisis lewat alat baca-saja (berkas_profil, berkas_data, berkas_agregat).
 * Berkas aslinya TIDAK disimpan di server — hanya isi tabelnya (maks. 5.000 baris x 40 kolom).
 */

const BERKAS_MAKS_BARIS = 5000;
const BERKAS_MAKS_KOLOM = 40;
const BERKAS_MAKS_BYTE  = 3145728;   // 3 MB

/* ---------- membaca berkas ---------- */

/** Ubah satu sel mentah jadi angka / tanggal (Y-m-d) / teks. */
function berkas_nilai($v) {
  if ($v === null) return null;
  if (is_bool($v)) return $v ? 'Ya' : 'Tidak';
  if (is_int($v) || is_float($v)) return $v;
  $s = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', (string)$v) ?? '');
  if ($s === '') return null;
  $s = mb_substr($s, 0, 200);
  if (preg_match('/^-?\d+$/', $s)) {
    $angka = ltrim($s, '-');
    if (strlen($angka) > 1 && $angka[0] === '0') return $s;       // "00123": nomor/kode, bukan angka
    if (strlen($angka) <= 15) return (int)$s;
    return $s;
  }
  if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) return (float)str_replace(['.', ','], ['', '.'], $s) + 0;   // 1.234,56
  if (preg_match('/^-?\d+,\d+$/', $s)) return (float)str_replace(',', '.', $s);
  if (preg_match('/^-?\d+\.\d+$/', $s)) return (float)$s;
  if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
  return $s;
}

/** @return array{0:string[],1:array[]} [kolom, baris] */
function berkas_baca(string $path, string $namaAsli): array {
  $ext = strtolower(pathinfo($namaAsli, PATHINFO_EXTENSION));
  $mentah = [];   // daftar baris, tiap baris array nilai berurutan
  if ($ext === 'xlsx') {
    require_once __DIR__ . '/xlsx_reader.php';
    $x = new XlsxReader($path);
    $terbaik = null; $jml = -1;
    foreach ($x->sheetNames() as $sn) {
      $rows = $x->readSheet($sn);
      if (count($rows) > $jml) { $jml = count($rows); $terbaik = $rows; }
    }
    if (!$terbaik) throw new RuntimeException('Berkas Excel tidak berisi data.');
    ksort($terbaik);
    foreach ($terbaik as $row) {
      if (!$row) continue;
      $maks = max(array_keys($row));
      $r = [];
      for ($c = 1; $c <= min($maks, 200); $c++) $r[] = $row[$c] ?? null;
      $mentah[] = $r;
      if (count($mentah) > BERKAS_MAKS_BARIS + 1) break;
    }
  } elseif ($ext === 'csv' || $ext === 'txt') {
    $isi = (string)file_get_contents($path);
    if (strncmp($isi, "\xEF\xBB\xBF", 3) === 0) $isi = substr($isi, 3);
    if (!mb_check_encoding($isi, 'UTF-8')) $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
    $baris1 = strtok($isi, "\n") ?: '';
    $pemisah = ';'; $terbanyak = -1;
    foreach ([';', ',', "\t", '|'] as $p) { $n = substr_count($baris1, $p); if ($n > $terbanyak) { $terbanyak = $n; $pemisah = $p; } }
    $f = fopen('php://temp', 'w+'); fwrite($f, $isi); rewind($f);
    while (($r = fgetcsv($f, 0, $pemisah, '"', '')) !== false) {
      if ($r === [null]) continue;
      $mentah[] = array_slice($r, 0, 200);
      if (count($mentah) > BERKAS_MAKS_BARIS + 1) break;
    }
    fclose($f);
  } else {
    throw new InvalidArgumentException('Jenis berkas tidak didukung. Pakai .xlsx atau .csv.');
  }

  // buang baris kosong di atas, lalu baris pertama = judul kolom
  while ($mentah && !array_filter($mentah[0], fn($v) => $v !== null && trim((string)$v) !== '')) array_shift($mentah);
  if (count($mentah) < 2) throw new InvalidArgumentException('Berkas ini tidak punya baris data (minimal harus ada judul kolom dan satu baris isi).');
  $kepala = array_shift($mentah);
  $lebar = min(BERKAS_MAKS_KOLOM, max(count($kepala), 1));
  $kolom = []; $dipakai = [];
  for ($i = 0; $i < $lebar; $i++) {
    $k = trim((string)($kepala[$i] ?? ''));
    $k = $k === '' ? 'Kolom ' . ($i + 1) : mb_substr($k, 0, 60);
    $asli = $k; $n = 2;
    while (isset($dipakai[mb_strtolower($k)])) $k = $asli . ' ' . $n++;
    $dipakai[mb_strtolower($k)] = true;
    $kolom[] = $k;
  }
  $baris = [];
  foreach ($mentah as $r) {
    $o = []; $ada = false;
    for ($i = 0; $i < $lebar; $i++) { $v = berkas_nilai($r[$i] ?? null); if ($v !== null) $ada = true; $o[] = $v; }
    if ($ada) $baris[] = $o;
    if (count($baris) >= BERKAS_MAKS_BARIS) break;
  }
  if (!$baris) throw new InvalidArgumentException('Semua baris di berkas ini kosong.');

  // kolom bertanggal dari Excel tersimpan sebagai nomor seri (mis. 46000) → ubah ke tanggal
  foreach ($kolom as $i => $k) {
    if (!preg_match('/tanggal|tgl|date|waktu/i', $k)) continue;
    $nilai = array_filter(array_column($baris, $i), fn($v) => $v !== null);
    if ($nilai && !array_filter($nilai, fn($v) => !is_int($v) || $v < 25000 || $v > 80000)) {
      foreach ($baris as &$b) if (is_int($b[$i])) $b[$i] = gmdate('Y-m-d', ($b[$i] - 25569) * 86400);
      unset($b);
    }
  }
  return [$kolom, $baris];
}

/* ---------- penyimpanan ---------- */

function berkas_siapkan_db(PDO $pdo): bool {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_berkas (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      percakapan_id BIGINT UNSIGNED NULL,
      nama VARCHAR(150) NOT NULL,
      kolom MEDIUMTEXT NOT NULL,
      baris LONGTEXT NOT NULL,
      jml_baris INT NOT NULL DEFAULT 0,
      dibuat DATETIME NOT NULL,
      KEY idx_user (user_id, id),
      KEY idx_perc (percakapan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return true;
  } catch (Throwable $e) {
    error_log('SIMDOKIM chat: gagal membuat tabel chat_berkas: ' . $e->getMessage());
    return false;
  }
}

/** @return array{id:int,nama:string,kolom:string[],baris:array[]}|null */
function berkas_muat(PDO $pdo, int $id, int $uid): ?array {
  try {
    $s = $pdo->prepare('SELECT id, nama, kolom, baris, percakapan_id FROM chat_berkas WHERE id=? AND user_id=?');
    $s->execute([$id, $uid]);
    $r = $s->fetch();
  } catch (Throwable $e) { return null; }
  if (!$r) return null;
  return ['id' => (int)$r['id'], 'nama' => $r['nama'], 'kolom' => json_decode($r['kolom'], true) ?: [], 'baris' => json_decode($r['baris'], true) ?: [], 'percakapan_id' => $r['percakapan_id'] === null ? null : (int)$r['percakapan_id']];
}

function berkas_terbaru_percakapan(PDO $pdo, int $percakapanId, int $uid): ?array {
  try {
    $s = $pdo->prepare('SELECT id FROM chat_berkas WHERE percakapan_id=? AND user_id=? ORDER BY id DESC LIMIT 1');
    $s->execute([$percakapanId, $uid]);
    $id = $s->fetchColumn();
  } catch (Throwable $e) { return null; }
  return $id ? berkas_muat($pdo, (int)$id, $uid) : null;
}

/* ---------- bantu analisis ---------- */

function berkas_jenis(array $nilai): string {
  $nilai = array_values(array_filter($nilai, fn($v) => $v !== null));
  if (!$nilai) return 'kosong';
  $n = count($nilai);
  $angka = count(array_filter($nilai, fn($v) => is_int($v) || is_float($v)));
  $tgl = count(array_filter($nilai, fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)));
  if ($angka / $n >= 0.9) return 'angka';
  if ($tgl / $n >= 0.9) return 'tanggal';
  if ($angka > 0 || $tgl > 0) return 'campuran';
  return 'teks';
}

function berkas_kolom_idx(array $kolom, $nama, string $label = 'kolom'): int {
  $nama = trim((string)$nama);
  if ($nama === '') throw new InvalidArgumentException("Parameter $label wajib diisi. Kolom yang ada: " . implode(', ', $kolom));
  foreach ($kolom as $i => $k) if (mb_strtolower($k) === mb_strtolower($nama)) return $i;
  if (ctype_digit($nama) && (int)$nama >= 1 && (int)$nama <= count($kolom)) return (int)$nama - 1;
  foreach ($kolom as $i => $k) if (mb_stripos($k, $nama) !== false) return $i;
  throw new InvalidArgumentException("Kolom \"$nama\" tidak ada di berkas. Kolom yang ada: " . implode(', ', $kolom));
}

function berkas_bandingkan($a, $b): int {
  if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return $a <=> $b;
  return strnatcasecmp((string)$a, (string)$b);
}

function berkas_saring(array $kolom, array $baris, $saring): array {
  if (!is_array($saring)) return $baris;
  $syarat = [];
  foreach (array_slice($saring, 0, 5) as $s) {
    if (!is_array($s)) continue;
    $op = (string)($s['op'] ?? '=');
    if (!in_array($op, ['=', '!=', '>', '>=', '<', '<=', 'mengandung', 'kosong', 'terisi'], true)) throw new InvalidArgumentException('Operator saringan tidak dikenal: ' . $op . '. Pakai = != > >= < <= mengandung kosong terisi.');
    $syarat[] = [berkas_kolom_idx($kolom, $s['kolom'] ?? '', 'saring.kolom'), $op, $s['nilai'] ?? ''];
  }
  if (!$syarat) return $baris;
  return array_values(array_filter($baris, function ($r) use ($syarat) {
    foreach ($syarat as [$i, $op, $nilai]) {
      $v = $r[$i] ?? null;
      if ($op === 'kosong') { if ($v !== null) return false; continue; }
      if ($op === 'terisi') { if ($v === null) return false; continue; }
      if ($v === null) return false;
      $n = berkas_nilai(is_string($nilai) ? $nilai : (string)$nilai);
      $ok = match ($op) {
        'mengandung' => mb_stripos((string)$v, (string)$nilai) !== false,
        '=' => is_numeric($v) && is_numeric($n) ? $v == $n : mb_strtolower((string)$v) === mb_strtolower(trim((string)$nilai)),
        '!=' => is_numeric($v) && is_numeric($n) ? $v != $n : mb_strtolower((string)$v) !== mb_strtolower(trim((string)$nilai)),
        '>' => berkas_bandingkan($v, $n) > 0, '>=' => berkas_bandingkan($v, $n) >= 0,
        '<' => berkas_bandingkan($v, $n) < 0, '<=' => berkas_bandingkan($v, $n) <= 0,
      };
      if (!$ok) return false;
    }
    return true;
  }));
}

/* ---------- definisi alat ---------- */

function berkas_alat_definisi(): array {
  $saring = ['type' => 'array', 'description' => 'Saringan baris (maks. 5), semua harus terpenuhi', 'items' => ['type' => 'object', 'properties' => [
    'kolom' => ['type' => 'string'], 'op' => ['type' => 'string', 'enum' => ['=', '!=', '>', '>=', '<', '<=', 'mengandung', 'kosong', 'terisi']], 'nilai' => ['type' => 'string'],
  ], 'required' => ['kolom', 'op']]];
  return [
    ['type' => 'function', 'function' => [
      'name' => 'berkas_profil',
      'description' => 'Periksa berkas yang dilampirkan pengguna: untuk tiap kolom tampilkan tipe (angka/tanggal/teks/campuran), jumlah terisi & kosong, jumlah nilai unik, min/maks/jumlah/rata-rata, nilai terbanyak, plus catatan kualitas (baris duplikat, kolom banyak kosong, format campur, nilai ekstrem). Panggil ini PERTAMA saat pengguna minta mengecek/memahami berkas.',
      'parameters' => ['type' => 'object', 'properties' => new stdClass()],
    ]],
    ['type' => 'function', 'function' => [
      'name' => 'berkas_data',
      'description' => 'Tampilkan baris-baris dari berkas lampiran, dengan saringan, pengurutan, dan pilihan kolom.',
      'parameters' => ['type' => 'object', 'properties' => [
        'saring' => $saring,
        'urut_kolom' => ['type' => 'string'], 'arah' => ['type' => 'string', 'enum' => ['naik', 'turun']],
        'kolom_tampil' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Kolom yang ditampilkan (bawaan semua)'],
        'batas' => ['type' => 'integer', 'description' => 'Maks. baris (1-500, bawaan 100)'],
      ]],
    ]],
    ['type' => 'function', 'function' => [
      'name' => 'berkas_agregat',
      'description' => 'Hitung ringkasan berkas lampiran per kelompok (seperti pivot): jumlah/rata-rata/hitung/min/maks/unik dari satu kolom, dikelompokkan menurut satu kolom (untuk kolom tanggal bisa per bulan/tahun). Tanpa kelompok_kolom = satu angka total.',
      'parameters' => ['type' => 'object', 'properties' => [
        'kelompok_kolom' => ['type' => 'string'],
        'waktu' => ['type' => 'string', 'enum' => ['bulan', 'tahun'], 'description' => 'Hanya bila kelompok_kolom bertanggal'],
        'nilai_kolom' => ['type' => 'string', 'description' => 'Kolom angka yang dihitung (tidak perlu untuk fungsi hitung)'],
        'fungsi' => ['type' => 'string', 'enum' => ['jumlah', 'rata', 'hitung', 'min', 'maks', 'unik']],
        'urut' => ['type' => 'string', 'enum' => ['kelompok', 'nilai_terbesar', 'nilai_terkecil']],
        'batas' => ['type' => 'integer', 'description' => 'Maks. kelompok (1-200, bawaan 50)'],
        'saring' => $saring,
      ]],
    ]],
  ];
}

function berkas_alat_jalankan(array $b, string $nama, array $a): array {
  return match ($nama) {
    'berkas_profil' => berkas_alat_profil($b),
    'berkas_data' => berkas_alat_data($b, $a),
    'berkas_agregat' => berkas_alat_agregat($b, $a),
    default => throw new InvalidArgumentException("Alat \"$nama\" tidak dikenal."),
  };
}

function berkas_fmt($v) { return is_float($v) ? round($v, 2) : $v; }

function berkas_alat_profil(array $b): array {
  $kolom = $b['kolom']; $baris = $b['baris']; $n = count($baris);
  $out = []; $catatan = [];
  foreach ($kolom as $i => $k) {
    $nilai = array_column($baris, $i);
    $isi = array_values(array_filter($nilai, fn($v) => $v !== null));
    $jenis = berkas_jenis($nilai);
    $unik = count(array_unique(array_map(fn($v) => mb_strtolower((string)$v), $isi)));
    $min = $maks = $jml = $rata = null; $teratas = '';
    if ($jenis === 'angka') {
      $angka = array_values(array_filter($isi, fn($v) => is_int($v) || is_float($v)));
      $min = berkas_fmt(min($angka)); $maks = berkas_fmt(max($angka)); $jml = berkas_fmt(array_sum($angka)); $rata = round(array_sum($angka) / count($angka), 2);
      sort($angka); $c = count($angka);
      if ($c >= 8) {
        $q1 = $angka[(int)floor($c * .25)]; $q3 = $angka[(int)floor($c * .75)]; $iqr = $q3 - $q1;
        if ($iqr > 0) { $x = count(array_filter($angka, fn($v) => $v < $q1 - 3 * $iqr || $v > $q3 + 3 * $iqr)); if ($x) $catatan[] = "Kolom \"$k\": $x nilai sangat jauh dari kebanyakan (kemungkinan salah ketik/ekstrem)."; }
      }
      if (preg_match('/jumlah|stok|qty|total|harga|nilai/i', $k) && ($neg = count(array_filter($angka, fn($v) => $v < 0)))) $catatan[] = "Kolom \"$k\": $neg nilai negatif.";
    } elseif ($jenis === 'tanggal') {
      $t = array_values(array_filter($isi, 'is_string')); sort($t); $min = $t[0]; $maks = end($t);
    } elseif ($isi) {
      $hit = array_count_values(array_map('strval', $isi)); arsort($hit);
      $teratas = implode('; ', array_map(fn($v, $c) => "$v ($c)", array_slice(array_keys($hit), 0, 3), array_slice($hit, 0, 3)));
    }
    $kosong = $n - count($isi);
    if ($n && $kosong / $n > 0.3) $catatan[] = "Kolom \"$k\": " . round($kosong / $n * 100) . '% kosong.';
    if ($jenis === 'campuran') $catatan[] = "Kolom \"$k\": isi bercampur (angka/tanggal/teks) — format tidak seragam.";
    if ($jenis === 'teks' && $unik > 1 && $unik < count($isi)) {
      $persis = count(array_unique(array_map('strval', $isi)));
      if ($persis > $unik) $catatan[] = "Kolom \"$k\": ada penulisan sama yang beda huruf besar/kecil (" . ($persis - $unik) . ' varian).';
    }
    $out[] = [$k, $jenis, count($isi), $kosong, $unik, $min, $maks, $jml, $rata, $teratas];
  }
  $dup = $n - count(array_unique(array_map(fn($r) => json_encode($r), $baris)));
  if ($dup > 0) $catatan[] = "$dup baris persis sama dengan baris lain (duplikat).";
  return [
    'tabel' => ['judul' => 'Profil berkas "' . $b['nama'] . '"', 'kolom' => ['Kolom', 'Tipe', 'Terisi', 'Kosong', 'Nilai unik', 'Min', 'Maks', 'Jumlah', 'Rata-rata', 'Nilai terbanyak'], 'baris' => $out],
    'ringkasan' => ['nama_berkas' => $b['nama'], 'jumlah_baris' => $n, 'jumlah_kolom' => count($kolom), 'baris_duplikat' => $dup, 'catatan_kualitas' => $catatan ?: ['Tidak ditemukan masalah menonjol.']],
    'catatan' => 'Hanya data berkas (maks. ' . BERKAS_MAKS_BARIS . ' baris pertama). Nilai berawalan nol (mis. 00123) dibaca sebagai teks.',
  ];
}

function berkas_alat_data(array $b, array $a): array {
  $kolom = $b['kolom'];
  $baris = berkas_saring($kolom, $b['baris'], $a['saring'] ?? null);
  $total = count($baris);
  if (!empty($a['urut_kolom'])) {
    $i = berkas_kolom_idx($kolom, $a['urut_kolom'], 'urut_kolom');
    $desc = ($a['arah'] ?? 'naik') === 'turun';
    usort($baris, function ($x, $y) use ($i, $desc) {
      if ($x[$i] === null || $y[$i] === null) return ($x[$i] === null) <=> ($y[$i] === null);   // kosong selalu di bawah
      $c = berkas_bandingkan($x[$i], $y[$i]); return $desc ? -$c : $c;
    });
  }
  $batas = max(1, min(CHAT_MAKS_BARIS_TABEL, (int)($a['batas'] ?? 100)));
  $baris = array_slice($baris, 0, $batas);
  $idx = array_keys($kolom);
  if (!empty($a['kolom_tampil']) && is_array($a['kolom_tampil'])) $idx = array_values(array_unique(array_map(fn($k) => berkas_kolom_idx($kolom, $k, 'kolom_tampil'), array_slice($a['kolom_tampil'], 0, 20))));
  return [
    'tabel' => ['judul' => 'Data berkas "' . $b['nama'] . '"', 'kolom' => array_map(fn($i) => $kolom[$i], $idx), 'baris' => array_map(fn($r) => array_map(fn($i) => $r[$i], $idx), $baris), 'total_baris' => $total],
    'ringkasan' => ['baris_cocok' => $total, 'baris_ditampilkan' => count($baris)],
  ];
}

function berkas_alat_agregat(array $b, array $a): array {
  $kolom = $b['kolom'];
  $baris = berkas_saring($kolom, $b['baris'], $a['saring'] ?? null);
  $fungsi = chat_pilih($a['fungsi'] ?? '', ['jumlah', 'rata', 'hitung', 'min', 'maks', 'unik'], 'hitung');
  $gi = !empty($a['kelompok_kolom']) ? berkas_kolom_idx($kolom, $a['kelompok_kolom'], 'kelompok_kolom') : null;
  $vi = null;
  if ($fungsi !== 'hitung') { $vi = berkas_kolom_idx($kolom, $a['nilai_kolom'] ?? '', 'nilai_kolom'); }
  $waktu = chat_pilih($a['waktu'] ?? '', ['bulan', 'tahun', ''], '');

  $grup = [];
  foreach ($baris as $r) {
    $kunci = '(semua)';
    if ($gi !== null) {
      $g = $r[$gi];
      $kunci = $g === null ? '(kosong)' : (string)$g;
      if ($waktu !== '' && is_string($g) && preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $g, $m)) $kunci = $waktu === 'bulan' ? "$m[1]-$m[2]" : $m[1];
    }
    $grup[$kunci][] = $vi === null ? 1 : $r[$vi];
  }
  $hasil = [];
  foreach ($grup as $k => $vals) {
    $angka = array_values(array_filter($vals, fn($v) => is_int($v) || is_float($v)));
    $isi = array_filter($vals, fn($v) => $v !== null);
    $hasil[$k] = match ($fungsi) {
      'hitung' => count($vals),
      'unik' => count(array_unique(array_map(fn($v) => mb_strtolower((string)$v), $isi))),
      'jumlah' => berkas_fmt(array_sum($angka)),
      'rata' => $angka ? round(array_sum($angka) / count($angka), 2) : null,
      'min' => $angka ? berkas_fmt(min($angka)) : null,
      'maks' => $angka ? berkas_fmt(max($angka)) : null,
    };
  }
  $urut = chat_pilih($a['urut'] ?? '', ['kelompok', 'nilai_terbesar', 'nilai_terkecil'], $waktu !== '' ? 'kelompok' : 'nilai_terbesar');
  if ($urut === 'kelompok') uksort($hasil, 'strnatcasecmp');
  elseif ($urut === 'nilai_terbesar') arsort($hasil); else asort($hasil);
  $jmlKelompok = count($hasil);
  $hasil = array_slice($hasil, 0, max(1, min(200, (int)($a['batas'] ?? 50))), true);
  $labelFungsi = ['hitung' => 'Jumlah baris', 'unik' => 'Nilai unik', 'jumlah' => 'Jumlah', 'rata' => 'Rata-rata', 'min' => 'Terkecil', 'maks' => 'Terbesar'][$fungsi];
  $namaNilai = $labelFungsi . ($vi !== null && mb_strtolower($kolom[$vi]) !== mb_strtolower($labelFungsi) ? ' ' . $kolom[$vi] : '');
  $out = [];
  foreach ($hasil as $k => $v) $out[] = [(string)$k, $v];
  if (in_array($fungsi, ['jumlah', 'hitung'], true) && $gi !== null && count($out) === $jmlKelompok) $out[] = ['TOTAL', array_sum(array_column($out, 1))];
  return [
    'tabel' => ['judul' => $namaNilai . ($gi !== null ? ' per ' . $kolom[$gi] . ($waktu ? " ($waktu)" : '') : '') . ' — berkas "' . $b['nama'] . '"', 'kolom' => [$gi !== null ? $kolom[$gi] : 'Keterangan', $namaNilai], 'baris' => $out],
    'ringkasan' => ['jumlah_kelompok' => $jmlKelompok, 'baris_dihitung' => count($baris)],
  ];
}
