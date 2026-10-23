<?php
/**
 * Pembaca file .xlsx minimal, TANPA ekstensi "zip" PHP (ext-zip sering tidak
 * aktif di server shared-hosting/Docker minimal). File .xlsx itu sebenarnya
 * cuma arsip ZIP — jadi di sini kita baca sendiri struktur ZIP-nya pakai
 * fungsi bawaan PHP (gzinflate(), yang hampir selalu aktif karena bagian
 * dari zlib) tanpa perlu class ZipArchive sama sekali.
 * Hanya mendukung BACA, bukan menulis ulang file .xlsx.
 */
class MiniZip {
  private array $entries = [];   // nama file dalam arsip => ['offset'=>int,'compSize'=>int,'uncompSize'=>int,'method'=>int]
  private string $data;

  public function __construct(string $filePath) {
    $this->data = file_get_contents($filePath);
    if ($this->data === false || $this->data === '') {
      throw new RuntimeException('Berkas tidak bisa dibaca atau kosong.');
    }
    $this->muatEntriesDariCentralDirectory();
  }

  public function getFromName(string $name) {
    if (!isset($this->entries[$name])) return false;
    $e = $this->entries[$name];

    // baca local file header di posisi $e['offset'] untuk tahu di mana data sebenarnya mulai
    // (panjang nama file & extra field di local header bisa beda dari yang di central directory)
    $lfh = substr($this->data, $e['offset'], 30);
    if (substr($lfh, 0, 4) !== "\x50\x4b\x03\x04") return false;    // signature local file header tidak cocok
    $namaLen  = unpack('v', substr($lfh, 26, 2))[1];
    $extraLen = unpack('v', substr($lfh, 28, 2))[1];
    $dataStart = $e['offset'] + 30 + $namaLen + $extraLen;
    $raw = substr($this->data, $dataStart, $e['compSize']);

    if ($e['method'] === 0) return $raw;                            // disimpan tanpa kompresi
    if ($e['method'] === 8) {
      $hasil = @gzinflate($raw);
      return $hasil === false ? false : $hasil;
    }
    return false;                                                    // metode kompresi lain tidak didukung (jarang dipakai Excel)
  }

  private function muatEntriesDariCentralDirectory(): void {
    // Cari "End Of Central Directory" (EOCD), signature PK\05\06, dicari dari belakang
    // (boleh ada komentar arsip di ujung file, makanya tidak langsung ambil 22 byte terakhir).
    $eocdPos = strrpos($this->data, "\x50\x4b\x05\x06");
    if ($eocdPos === false) throw new RuntimeException('Bukan arsip ZIP yang valid (EOCD tidak ditemukan) — pastikan ini file .xlsx asli, bukan .xls lama.');

    $eocd = substr($this->data, $eocdPos, 22);
    $jumlahEntri = unpack('v', substr($eocd, 10, 2))[1];
    $cdOffset    = unpack('V', substr($eocd, 16, 4))[1];

    $pos = $cdOffset;
    for ($i = 0; $i < $jumlahEntri; $i++) {
      if (substr($this->data, $pos, 4) !== "\x50\x4b\x01\x02") break;   // signature central directory header
      $hdr = substr($this->data, $pos, 46);
      $method     = unpack('v', substr($hdr, 10, 2))[1];
      $compSize   = unpack('V', substr($hdr, 20, 4))[1];
      $uncompSize = unpack('V', substr($hdr, 24, 4))[1];
      $namaLen    = unpack('v', substr($hdr, 28, 2))[1];
      $extraLen   = unpack('v', substr($hdr, 30, 2))[1];
      $komenLen   = unpack('v', substr($hdr, 32, 2))[1];
      $lokalOffset = unpack('V', substr($hdr, 42, 4))[1];
      $nama = substr($this->data, $pos + 46, $namaLen);

      $this->entries[$nama] = ['offset' => $lokalOffset, 'compSize' => $compSize, 'uncompSize' => $uncompSize, 'method' => $method];
      $pos += 46 + $namaLen + $extraLen + $komenLen;
    }
    if (!$this->entries) throw new RuntimeException('Arsip ZIP terbaca tapi tidak ada isinya — berkas kemungkinan rusak.');
  }
}

class XlsxReader {
  private MiniZip $zip;
  private array $sharedStrings = [];
  /** @var array<string,string> nama sheet => path internal xl/worksheets/sheetN.xml */
  private array $sheetPaths = [];

  public function __construct(string $filePath) {
    $this->zip = new MiniZip($filePath);
    $this->muatSharedStrings();
    $this->muatDaftarSheet();
  }

  /** @return string[] nama semua sheet, sesuai urutan di file aslinya */
  public function sheetNames(): array { return array_keys($this->sheetPaths); }

  /**
   * Baca satu sheet penuh jadi array 2D (1-indexed): $rows[$baris][$kolom] = nilai.
   * Baris/kolom kosong tidak dijamin ada key-nya (pakai ?? saat mengakses).
   */
  public function readSheet(string $sheetName): array {
    if (!isset($this->sheetPaths[$sheetName])) {
      throw new InvalidArgumentException("Sheet \"$sheetName\" tidak ditemukan di file ini.");
    }
    $xmlRaw = $this->zip->getFromName($this->sheetPaths[$sheetName]);
    if ($xmlRaw === false) throw new RuntimeException("Gagal membaca isi sheet \"$sheetName\".");

    $xml = simplexml_load_string($xmlRaw);
    if ($xml === false) throw new RuntimeException("Isi sheet \"$sheetName\" tidak valid (XML rusak).");

    $rows = [];
    foreach ($xml->sheetData->row as $rowEl) {
      $r = (int)$rowEl['r'];
      foreach ($rowEl->c as $cEl) {
        $ref = (string)$cEl['r'];
        $col = self::kolomKeAngka($ref);
        $type = (string)$cEl['t'];
        $value = null;

        if ($type === 'inlineStr') {
          $value = isset($cEl->is->t) ? (string)$cEl->is->t : $this->gabungRichText($cEl->is);
        } elseif (isset($cEl->v)) {
          $raw = (string)$cEl->v;
          if ($type === 's') {                      // shared string index
            $idx = (int)$raw;
            $value = $this->sharedStrings[$idx] ?? '';
          } elseif ($type === 'b') {                 // boolean
            $value = $raw === '1';
          } elseif ($type === 'str') {                // hasil formula berupa teks
            $value = $raw;
          } else {                                    // angka biasa
            $value = is_numeric($raw) ? ($raw + 0) : $raw;
          }
        }
        if ($value !== null && $value !== '') $rows[$r][$col] = $value;
      }
    }
    return $rows;
  }

  /** "A1".."Z1".."AA1" dst -> indeks kolom 1-based */
  private static function kolomKeAngka(string $ref): int {
    preg_match('/^([A-Z]+)/', $ref, $m);
    $huruf = $m[1] ?? 'A';
    $n = 0;
    foreach (str_split($huruf) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n;
  }

  private function muatSharedStrings(): void {
    $raw = $this->zip->getFromName('xl/sharedStrings.xml');
    if ($raw === false) return;                        // wajar kalau file tidak pakai shared strings
    $xml = simplexml_load_string($raw);
    if ($xml === false) return;
    foreach ($xml->si as $si) {
      $this->sharedStrings[] = isset($si->t) ? (string)$si->t : $this->gabungRichText($si);
    }
  }

  /** Gabungkan teks dari <r><t>...</t></r> (rich text berformat campuran) jadi satu string polos. */
  private function gabungRichText($node): string {
    $teks = '';
    if (isset($node->r)) foreach ($node->r as $run) $teks .= (string)($run->t ?? '');
    return $teks;
  }

  private function muatDaftarSheet(): void {
    $wbXml = simplexml_load_string($this->zip->getFromName('xl/workbook.xml'));
    $relsRaw = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
    $rels = $relsRaw !== false ? simplexml_load_string($relsRaw) : null;

    $targetById = [];
    if ($rels) {
      foreach ($rels->Relationship as $rel) {
        $targetById[(string)$rel['Id']] = (string)$rel['Target'];
      }
    }
    $ns = $wbXml->getNamespaces(true);
    $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    foreach ($wbXml->sheets->sheet as $sheetEl) {
      $nama = (string)$sheetEl['name'];
      $attrs = $sheetEl->attributes($rNs);
      $rid = (string)($attrs['id'] ?? '');
      $target = $targetById[$rid] ?? null;
      if ($target === null) continue;
      $target = ltrim($target, '/');
      if (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
      $this->sheetPaths[$nama] = $target;
    }
  }
}