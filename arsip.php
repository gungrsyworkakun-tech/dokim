<?php require 'config.php'; login_required(); $title = 'Arsip tahun lama';
require_once 'lib/arsip_tahun.php'; arsip_siapkan($pdo);
$tahunList = $pdo->query('SELECT DISTINCT tahun FROM arsip_transaksi ORDER BY tahun DESC')->fetchAll(PDO::FETCH_COLUMN);
$tahun = (int)($_GET['tahun'] ?? ($tahunList[0] ?? 0));
$ringkas = []; $baris = [];
if ($tahun) {
  $s = $pdo->prepare("SELECT a.doc_key, COALESCE(d.nama, a.nama_arsip) AS nm, COALESCE(d.kode, a.kode_arsip) AS kd,
      SUM(CASE WHEN a.jenis='penerimaan' THEN a.jumlah ELSE 0 END) masuk,
      SUM(CASE WHEN a.jenis='pengeluaran' THEN a.jumlah ELSE 0 END) keluar, COUNT(*) n
    FROM arsip_transaksi a LEFT JOIN dokumen d ON d.id=a.dokumen_id WHERE a.tahun=? GROUP BY a.doc_key, COALESCE(d.nama, a.nama_arsip), COALESCE(d.kode, a.kode_arsip) ORDER BY nm");
  $s->execute([$tahun]); $ringkas = $s->fetchAll();
  $doc = $_GET['dok'] ?? '';
  $q = "SELECT a.*, COALESCE(d.nama, a.nama_arsip) nama FROM arsip_transaksi a LEFT JOIN dokumen d ON d.id=a.dokumen_id WHERE a.tahun=?" . ($doc !== '' ? ' AND a.doc_key=?' : '') . ' ORDER BY a.tanggal, a.id';
  $s = $pdo->prepare($q); $s->execute($doc !== '' ? [$tahun, $doc] : [$tahun]); $baris = $s->fetchAll();
}
include 'partials/header.php'; ?>
<section class="panel">
  <div class="panel-h"><div><h2>Arsip tahun lama</h2>
    <p>Riwayat penerimaan dan pengeluaran tahun-tahun sebelumnya. Hanya untuk dibaca — <b>tidak mempengaruhi stok sekarang</b>.</p></div>
    <?php if (is_super()): ?><a class="btn sm" href="impor_excel.php">Impor arsip dari Excel</a><?php endif; ?></div>
  <div class="panel-b">
  <?php if (!$tahunList): ?><div class="empty"><b>Belum ada arsip</b>Impor Excel dengan "Tahun data" tahun lalu untuk mengisi arsip.</div>
  <?php else: ?>
    <form method="get" style="margin-bottom:12px"><label for="th">Tahun</label>
      <select id="th" name="tahun" onchange="this.form.submit()"><?php foreach ($tahunList as $t): ?><option value="<?= (int)$t ?>"<?= (int)$t === $tahun ? ' selected' : '' ?>><?= (int)$t ?></option><?php endforeach; ?></select></form>
    <div class="tbl-wrap"><table><thead><tr><th>Dokumen</th><th>Kode</th><th class="num">Total masuk</th><th class="num">Total keluar</th><th class="num">Transaksi</th></tr></thead><tbody>
    <?php foreach ($ringkas as $r): ?><tr><td><a href="?tahun=<?= $tahun ?>&amp;dok=<?= urlencode($r['doc_key']) ?>"><?= e($r['nm']) ?></a></td><td><?= e($r['kd']) ?></td><td class="num in">+<?= num($r['masuk']) ?></td><td class="num out">−<?= num($r['keluar']) ?></td><td class="num"><?= num($r['n']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <h3 style="margin:18px 0 8px">Rincian transaksi <?= $tahun ?><?= ($doc ?? '') !== '' ? ' (satu dokumen) — <a href="?tahun=' . $tahun . '">tampilkan semua</a>' : '' ?></h3>
    <div class="tbl-wrap"><table><thead><tr><th>Tanggal</th><th>Jenis</th><th>Dokumen</th><th class="num">Jumlah</th><th>Tujuan</th><th>No. BA</th><th>Rentang seri</th></tr></thead><tbody>
    <?php foreach ($baris as $b): ?><tr><td class="nowrap"><?= tgl($b['tanggal']) ?></td><td><?= $b['jenis'] === 'pengeluaran' ? 'Keluar' : 'Terima' ?></td><td><?= e($b['nama']) ?></td><td class="num"><?= num($b['jumlah']) ?></td><td class="small"><?= e($b['tujuan'] ?: '—') ?></td><td class="mono"><?= e($b['no_ba'] ?: '—') ?></td><td class="mono nowrap"><?= $b['seri_awal'] ? e($b['seri_awal']) . ' – ' . e($b['seri_akhir']) : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  </div>
</section>
<?php include 'partials/footer.php'; ?>