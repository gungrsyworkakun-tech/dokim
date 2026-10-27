<?php
/**
 * Penulis berkas Excel (.xlsx) dan CSV tanpa ekstensi "zip" PHP.
 * Berkas .xlsx adalah arsip zip berisi beberapa file XML; di sini arsip zip-nya dibuat sendiri
 * (tanpa kompresi, cukup untuk tabel kecil-menengah) sehingga jalan di hosting yang tidak punya ZipArchive.
 */

/** Zip sederhana: tiap berkas disimpan apa adanya (metode "stored"). */
final class MiniZipWriter {
  private array $berkas = [];

  public function tambah(string $nama, string $isi): void { $this->berkas[] = [$nama, $isi]; }

  public function bytes(): string {
    $waktu = (int)date('G') << 11 | (int)date('i') << 5 | (int)((int)date('s') / 2);
    $tanggal = ((int)date('Y') - 1980) << 9 | (int)date('n') << 5 | (int)date('j');
    $lokal = ''; $pusat = ''; $n = 0;
    foreach ($this->berkas as [$nama, $isi]) {
      $crc = crc32($isi) & 0xFFFFFFFF;
      $ukuran = strlen($isi);
      $offset = strlen($lokal);
      $lokal .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $waktu, $tanggal, $crc, $ukuran, $ukuran, strlen($nama), 0) . $nama . $isi;
      $pusat .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $waktu, $tanggal, $crc, $ukuran, $ukuran, strlen($nama), 0, 0, 0, 0, 0, $offset) . $nama;
      $n++;
    }
    $akhir = pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($pusat), strlen($lokal), 0);
    return $lokal . $pusat . $akhir;
  }
}

if (!function_exists('xlsx_kolom')) {
/** 1 => A, 27 => AA */
function xlsx_kolom(int $n): string {
  $s = '';
  while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
  return $s;
}
}

if (!function_exists('xlsx_xml')) {
function xlsx_xml($v): string {
  $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$v) ?? '';
  return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
}

if (!function_exists('xlsx_buat')) {
/**
 * @param string $namaSheet  nama lembar kerja
 * @param array  $kolom      judul kolom, mis. ['Tanggal','Dokumen','Jumlah']
 * @param array  $baris      daftar baris; tiap baris array nilai (int/float jadi angka, 'YYYY-MM-DD' jadi tanggal, lainnya teks)
 */
function xlsx_buat(string $namaSheet, array $kolom, array $baris): string {
  $namaSheet = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $namaSheet)) ?: 'Data';
  $namaSheet = mb_substr($namaSheet, 0, 31);
  $jmlKolom = max(1, count($kolom));

  // lebar kolom mengikuti isi (maks. 60 karakter)
  $lebar = [];
  foreach ($kolom as $i => $k) $lebar[$i] = mb_strlen((string)$k) + 3;
  foreach ($baris as $r) foreach (array_values($r) as $i => $v) {
    $p = is_float($v) ? 12 : mb_strlen((string)$v) + 2;
    if ($p > ($lebar[$i] ?? 0)) $lebar[$i] = $p;
  }
  $cols = '';
  for ($i = 0; $i < $jmlKolom; $i++) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(8, $lebar[$i] ?? 10)) . '" customWidth="1"/>';

  $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<cols>' . $cols . '</cols><sheetData><row r="1" ht="22" customHeight="1">';
  foreach (array_values($kolom) as $i => $k) {
    $xml .= '<c r="' . xlsx_kolom($i + 1) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml($k) . '</t></is></c>';
  }
  $xml .= '</row>';

  $r = 1;
  foreach ($baris as $row) {
    $r++;
    $xml .= '<row r="' . $r . '">';
    foreach (array_values($row) as $i => $v) {
      $ref = xlsx_kolom($i + 1) . $r;
      if ($v === null || $v === '') continue;                       // sel kosong
      if (is_int($v)) { $xml .= '<c r="' . $ref . '" s="2"><v>' . $v . '</v></c>'; }
      elseif (is_float($v)) { $xml .= '<c r="' . $ref . '" s="3"><v>' . rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') . '</v></c>'; }
      elseif (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $serial = (int)floor((gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
        $xml .= '<c r="' . $ref . '" s="4"><v>' . $serial . '</v></c>';
      }
      else { $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml($v) . '</t></is></c>'; }   // teks tidak pernah dianggap rumus
    }
    $xml .= '</row>';
  }
  $xml .= '</sheetData><autoFilter ref="A1:' . xlsx_kolom($jmlKolom) . max(1, $r) . '"/></worksheet>';

  $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="5">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left"/></xf>'
    . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

  $z = new MiniZipWriter();
  $z->tambah('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
  $z->tambah('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $z->tambah('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsx_xml($namaSheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
  $z->tambah('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
  $z->tambah('xl/styles.xml', $styles);
  $z->tambah('xl/worksheets/sheet1.xml', $xml);
  return $z->bytes();
}
}

if (!function_exists('csv_buat')) {
/** CSV pemisah titik koma + BOM UTF-8 (langsung rapi di Excel berbahasa Indonesia). Teks berawalan = + - @ diberi tanda kutip agar tidak dibaca sebagai rumus. */
function csv_buat(array $kolom, array $baris): string {
  $f = fopen('php://temp', 'w+');
  fwrite($f, "\xEF\xBB\xBF");
  $aman = function ($v) {
    if (is_string($v) && $v !== '' && strpos("=+-@\t\r", $v[0]) !== false && !is_numeric($v)) return "'" . $v;
    return $v;
  };
  fputcsv($f, array_map($aman, array_values($kolom)), ';');
  foreach ($baris as $r) fputcsv($f, array_map($aman, array_values($r)), ';');
  rewind($f);
  $out = stream_get_contents($f);
  fclose($f);
  return $out;
}
}
