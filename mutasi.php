<?php require 'config.php'; login_required(); $title = 'Mutasi seluruh tahun';

$tahunTersedia = $pdo->query('SELECT DISTINCT YEAR(tanggal) y FROM kartu_stok_harian ORDER BY y')->fetchAll(PDO::FETCH_COLUMN);
if (!$tahunTersedia) $tahunTersedia = [(int)date('Y')];
$tahun = (int)($_GET['tahun'] ?? end($tahunTersedia));
if (!in_array($tahun, array_map('intval', $tahunTersedia), true)) $tahun = (int)end($tahunTersedia);

/* Rentang: seluruh tahun, atau sampai tanggal tertentu (mis. posisi hari ini) */
$dari   = "$tahun-01-01";
$maks   = "$tahun-12-31";
$sampai = valid_date($_GET['sampai'] ?? '') && substr($_GET['sampai'], 0, 4) == $tahun ? $_GET['sampai'] : $maks;

$dok = $pdo->query('SELECT id,kode,kode_resmi,nama,satuan,harga FROM dokumen ORDER BY kode_resmi IS NULL, kode_resmi, nama')->fetchAll();
$q = $pdo->prepare('SELECT
    COALESCE(SUM(penerimaan_jumlah),0) pen, COALESCE(SUM(pengeluaran_jumlah),0) kel,
    COALESCE(SUM(terbit_kanim),0) kanim, COALESCE(SUM(terbit_ulp),0) ulp, COALESCE(SUM(rusak),0) rusak, COALESCE(SUM(catpro),0) catpro, COUNT(*) n
  FROM kartu_stok_harian WHERE dokumen_id=? AND tanggal BETWEEN ? AND ?');
$qa = $pdo->prepare('SELECT stok, penerimaan_jumlah, pengeluaran_jumlah FROM kartu_stok_harian WHERE dokumen_id=? AND tanggal>=? ORDER BY tanggal ASC LIMIT 1');
$qz = $pdo->prepare('SELECT stok FROM kartu_stok_harian WHERE dokumen_id=? AND tanggal<=? ORDER BY tanggal DESC LIMIT 1');

$baris = []; $tot = ['awal' => 0, 'pen' => 0, 'kel' => 0, 'kanim' => 0, 'ulp' => 0, 'rusak' => 0, 'catpro' => 0, 'akhir' => 0, 'nilai' => 0];
foreach ($dok as $d) {
  $q->execute([$d['id'], $dari, $sampai]); $m = $q->fetch();
  if (!(int)$m['n']) continue;                       // jenis tanpa riwayat kartu stok tidak ikut direkap
  $qa->execute([$d['id'], $dari]); $a = $qa->fetch();
  $awal = (int)$a['stok'] - (int)$a['penerimaan_jumlah'] + (int)$a['pengeluaran_jumlah'];   // stok sebelum transaksi hari pertama
  $qz->execute([$d['id'], $sampai]); $akhir = (int)$qz->fetchColumn();
  $selisih = $awal + (int)$m['pen'] - (int)$m['kel'] - $akhir;                              // harus 0
  $b = ['d' => $d, 'awal' => $awal, 'pen' => (int)$m['pen'], 'kel' => (int)$m['kel'], 'kanim' => (int)$m['kanim'], 'ulp' => (int)$m['ulp'], 'rusak' => (int)$m['rusak'], 'catpro' => (int)$m['catpro'], 'akhir' => $akhir, 'sel' => $selisih, 'nilai' => $akhir * (int)$d['harga']];
  $baris[] = $b;
  foreach (['awal', 'pen', 'kel', 'kanim', 'ulp', 'rusak', 'catpro', 'akhir', 'nilai'] as $k) $tot[$k] += $b[$k];
}

if (($_GET['act'] ?? '') === 'unduh_csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="mutasi-' . $tahun . '-sd-' . $sampai . '.csv"');
  $out = fopen('php://output', 'w'); fputs($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Kode', 'Dokumen', 'Satuan', 'Stok awal', 'Penerimaan', 'Pengeluaran', 'Terbit Kanim', 'Terbit ULP', 'Rusak', 'Stok akhir', 'Harga', 'Nilai akhir'], ';');
  foreach ($baris as $b) fputcsv($out, [$b['d']['kode'], $b['d']['nama'], $b['d']['satuan'], $b['awal'], $b['pen'], $b['kel'], $b['kanim'], $b['ulp'], $b['rusak'], $b['catpro'], $b['akhir'], $b['d']['harga'], $b['nilai']], ';');
  fputcsv($out, ['', 'TOTAL', '', $tot['awal'], $tot['pen'], $tot['kel'], $tot['kanim'], $tot['ulp'], $tot['rusak'], $tot['catpro'], $tot['akhir'], '', $tot['nilai']], ';');
  fclose($out); exit;
}

/* Rincian per bulan (semua dokumen) */
$bulanan = $pdo->prepare('SELECT MONTH(tanggal) m, SUM(penerimaan_jumlah) pen, SUM(pengeluaran_jumlah) kel, SUM(terbit_kanim) kanim, SUM(terbit_ulp) ulp, SUM(rusak) rusak, SUM(catpro) catpro
  FROM kartu_stok_harian WHERE tanggal BETWEEN ? AND ? GROUP BY m ORDER BY m');
$bulanan->execute([$dari, $sampai]); $perBulan = $bulanan->fetchAll();

include 'partials/header.php'; ?>

<section class="panel">
  <div class="panel-h">
    <div><h2>Mutasi seluruh tahun <?= $tahun ?></h2>
      <p>Stok awal + penerimaan − pengeluaran = stok akhir, untuk semua jenis dokumen. Tanggal akhir bisa diganti untuk melihat posisi sampai tanggal tertentu.</p></div>
    <div class="actions noprint">
      <form method="get" class="inline">
        <label class="check" for="mt-th">Tahun</label>
        <select id="mt-th" name="tahun" onchange="this.form.submit()">
          <?php foreach ($tahunTersedia as $ty): ?><option value="<?= (int)$ty ?>" <?= (int)$ty === $tahun ? 'selected' : '' ?>><?= (int)$ty ?></option><?php endforeach; ?>
        </select>
        <label class="check" for="mt-sd">Sampai</label>
        <input id="mt-sd" type="date" name="sampai" value="<?= e($sampai) ?>" min="<?= $dari ?>" max="<?= $maks ?>" onchange="this.form.submit()">
      </form>
      <a class="btn sm ghost" href="?tahun=<?= $tahun ?>&amp;sampai=<?= e($sampai) ?>&amp;act=unduh_csv">Unduh CSV</a>
      <button type="button" class="btn sm ghost" onclick="print()">Cetak</button>
      <a class="btn sm ghost" href="opname.php?tgl=<?= e(min($sampai, date('Y-m-d'))) ?>">Opname tanggal ini</a>
    </div>
  </div>
  <div class="strip inset">
    <div><small>Penerimaan</small><b class="in">+<?= num($tot['pen']) ?></b></div>
    <div><small>Pengeluaran</small><b class="out">−<?= num($tot['kel']) ?></b></div>
    <div><small>Terbit — Kanim</small><b><?= num($tot['kanim']) ?></b></div>
    <div><small>Terbit — ULP</small><b><?= num($tot['ulp']) ?></b></div>
    <div><small>Rusak</small><b><?= num($tot['rusak']) ?></b></div>
    <div><small>Catpro</small><b><?= num($tot['catpro']) ?></b></div>
    <div><small>Nilai stok akhir</small><b><?= rp_singkat($tot['nilai']) ?></b></div>
  </div>
  <div class="tbl-wrap"><table class="ledger">
    <thead><tr><th>Kode</th><th>Dokumen</th><th class="num">Stok awal</th><th class="num">Terima</th><th class="num">Keluar</th><th class="num">Terbit Kanim</th><th class="num">Terbit ULP</th><th class="num">Rusak</th><th class="num">Catpro</th><th class="num">Stok akhir</th><th class="num">Nilai akhir</th><th class="noprint"></th></tr></thead>
    <tbody>
    <?php if (!$baris): ?><tr class="empty-row"><td colspan="11"><div class="empty"><b>Belum ada data kartu stok</b>Tidak ada riwayat harian untuk tahun ini.</div></td></tr><?php endif; ?>
    <?php foreach ($baris as $b): $d = $b['d']; ?>
      <tr>
        <td class="mono nowrap"><?= e($d['kode']) ?></td>
        <td class="doc"><a href="kartu-stok.php?id=<?= (int)$d['id'] ?>&amp;bln=<?= $tahun ?>-ALL"><?= e($d['nama']) ?></a><div class="sub"><?= strtolower(e($d['satuan'])) ?></div></td>
        <td class="num"><?= num($b['awal']) ?></td>
        <td class="num <?= $b['pen'] ? 'in' : '' ?>"><?= $b['pen'] ? '+' . num($b['pen']) : '–' ?></td>
        <td class="num <?= $b['kel'] ? 'out' : '' ?>"><?= $b['kel'] ? '−' . num($b['kel']) : '–' ?></td>
        <td class="num"><?= $b['kanim'] ? num($b['kanim']) : '–' ?></td>
        <td class="num"><?= $b['ulp'] ? num($b['ulp']) : '–' ?></td>
        <td class="num"><?= $b['rusak'] ? num($b['rusak']) : '–' ?></td>
        <td class="num"><?= $b['catpro'] ? num($b['catpro']) : '–' ?></td>
        <td class="num"><b><?= num($b['akhir']) ?></b></td>
        <td class="num"><?= rp($b['nilai']) ?></td>
        <td class="noprint"><?php if ($b['sel'] !== 0): ?><span class="st st-kritis" title="Stok awal + terima − keluar tidak sama dengan stok akhir (selisih <?= num($b['sel']) ?>). Cek kartu stoknya.">tidak balance</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($baris): ?><tfoot><tr><td colspan="2">TOTAL</td><td class="num"><?= num($tot['awal']) ?></td><td class="num"><?= num($tot['pen']) ?></td><td class="num"><?= num($tot['kel']) ?></td><td class="num"><?= num($tot['kanim']) ?></td><td class="num"><?= num($tot['ulp']) ?></td><td class="num"><?= num($tot['rusak']) ?></td><td class="num"><?= num($tot['catpro']) ?></td><td class="num"><?= num($tot['akhir']) ?></td><td class="num"><?= rp($tot['nilai']) ?></td><td class="noprint"></td></tr></tfoot><?php endif; ?>
  </table></div>
</section>

<section class="panel">
  <div class="panel-h"><div><h2>Per bulan — semua dokumen</h2><p>Jumlah lembar/buku yang diterima, keluar, terbit, rusak, dan catpro tiap bulan.</p></div></div>
  <div class="tbl-wrap"><table class="ledger">
    <thead><tr><th>Bulan</th><th class="num">Terima</th><th class="num">Keluar</th><th class="num">Terbit Kanim</th><th class="num">Terbit ULP</th><th class="num">Rusak</th><th class="num">Catpro</th></tr></thead>
    <tbody>
    <?php foreach ($perBulan as $p): ?>
      <tr><td><?= e(NAMA_BULAN[(int)$p['m'] - 1]) ?></td>
        <td class="num"><?= $p['pen'] ? num($p['pen']) : '–' ?></td><td class="num"><?= $p['kel'] ? num($p['kel']) : '–' ?></td>
        <td class="num"><?= $p['kanim'] ? num($p['kanim']) : '–' ?></td><td class="num"><?= $p['ulp'] ? num($p['ulp']) : '–' ?></td><td class="num"><?= $p['rusak'] ? num($p['rusak']) : '–' ?></td><td class="num"><?= $p['catpro'] ? num($p['catpro']) : '–' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php include 'partials/footer.php'; ?>