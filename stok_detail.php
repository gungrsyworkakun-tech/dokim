<?php require 'config.php'; require_once __DIR__ . '/lib/seri_stok.php'; login_required();
$id = (int)($_GET['id'] ?? 0);
$s = $pdo->prepare('SELECT * FROM dokumen WHERE id=?'); $s->execute([$id]); $d = $s->fetch();
if (!$d) { flash('Dokumen tidak ditemukan.', 'err'); back('stok.php'); }

/* ---------- Ubah data dokumen (superadmin) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  super_required(); csrf_check();
  $v = [
    'nama'        => trim((string)($_POST['nama'] ?? '')),
    'harga'       => (int)($_POST['harga'] ?? -1),
    'rusak'       => (int)($_POST['rusak'] ?? -1),
    'terbit'      => (int)($_POST['terbit'] ?? -1),
    'seri_aktif'  => trim((string)($_POST['seri_aktif'] ?? '')) ?: '-',
    'seri_simpan' => trim((string)($_POST['seri_simpan'] ?? '')) ?: '-',
  ];
  if ($v['nama'] === '' || mb_strlen($v['nama']) > 100 || $v['harga'] < 0 || $v['rusak'] < 0 || $v['terbit'] < 0
      || mb_strlen($v['seri_aktif']) > 60 || mb_strlen($v['seri_simpan']) > 60) {
    flash('Data tidak valid: nama wajib diisi, angka tidak boleh negatif, rentang seri maks. 60 karakter.', 'err');
  } elseif (($v['seri_aktif'] !== $d['seri_aktif'] || $v['seri_simpan'] !== $d['seri_simpan'])
            && ($cekSeri = seri_ubah_cek($pdo, $id, $v['seri_aktif'], $v['seri_simpan']))) {
    flash('Nomor seri tidak disimpan: ' . implode(' ', $cekSeri), 'err');
  } else {
    $pdo->prepare('UPDATE dokumen SET nama=?, harga=?, rusak=?, terbit=?, seri_aktif=?, seri_simpan=? WHERE id=?')->execute([...array_values($v), $id]);
    flash('Data dokumen diperbarui.');
  }
  back("stok_detail.php?id=$id");
}

$title = $d['nama'];
$blokAktif = seri_stok_baca($d['seri_aktif']) ?: [];  $blokSimpan = seri_stok_baca($d['seri_simpan']) ?: [];
$jmlAktif = seri_stok_jumlah($blokAktif);             $jmlSimpan = seri_stok_jumlah($blokSimpan);
$riwayat = seri_blok_riwayat($pdo, $id);
$seriCocok = ($blokAktif || $blokSimpan) ? ($jmlAktif + $jmlSimpan === (int)$d['stok']) : null;   // null = seri belum diisi
$rata  = pemakaian($pdo)[$id] ?? 0;
$st    = status_stok($d['stok'], $rata);

/* ---------- Buku harian per bulan ---------- */
$kini   = date('Y-m');
$bln    = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['bln'] ?? '') && $_GET['bln'] <= $kini ? $_GET['bln'] : $kini;
$awalBln  = "$bln-01";
$akhirBln = date('Y-m-t', strtotime($awalBln));
$prev = date('Y-m', strtotime("$awalBln -1 month"));
$next = date('Y-m', strtotime("$awalBln +1 month"));

$sumSejak = function ($tb) use ($pdo, $id, $awalBln) {
  $s = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) FROM $tb WHERE dokumen_id=? AND tanggal>=?"); $s->execute([$id, $awalBln]);
  return (int)$s->fetchColumn();
};
$awal = $d['stok'] - $sumSejak('penerimaan') + $sumSejak('pengeluaran');   // mundur dari stok sekarang

$harian = function ($tb, $kol) use ($pdo, $id, $awalBln, $akhirBln) {
  $s = $pdo->prepare("SELECT tanggal, SUM(jumlah) j, GROUP_CONCAT(DISTINCT $kol ORDER BY $kol SEPARATOR ', ') x FROM $tb WHERE dokumen_id=? AND tanggal BETWEEN ? AND ? GROUP BY tanggal");
  $s->execute([$id, $awalBln, $akhirBln]);
  return $s->fetchAll(PDO::FETCH_UNIQUE);
};
$in  = $harian('penerimaan', 'no_ba');
$out = $harian('pengeluaran', 'tujuan');
$totIn  = array_sum(array_column($in, 'j'));
$totOut = array_sum(array_column($out, 'j'));
$jmlHari = $bln === $kini ? (int)date('j') : (int)date('t', strtotime($awalBln));

include 'partials/header.php'; ?>

<p class="crumb noprint"><a href="stok.php">Stok dokumen</a><span>/</span><?= e($d['nama']) ?></p>

<section class="panel">
  <div class="panel-h">
    <div><h2><?= e($d['nama']) ?> <span class="tag"><?= e($d['kode']) ?></span></h2>
      <p><?= badge_stok($st) ?>&ensp;Perkiraan habis: <?= hari_txt($st[2]) ?></p></div>
    <div class="actions noprint">
      <a class="btn sm" href="penerimaan.php?baru=<?= $id ?>#form">Catat penerimaan</a>
      <a class="btn sm ghost" href="pengeluaran.php?baru=<?= $id ?>#form">Catat pengeluaran</a>
      <a class="btn sm ghost" href="kartu-stok.php?id=<?= $id ?>">Kartu stok harian resmi</a>
    </div>
  </div>
  <dl class="info-grid">
    <div><dt>Stok sistem</dt><dd class="big"><?= num($d['stok']) ?> <small><?= strtolower($d['satuan']) ?></small></dd></div>
    <div><dt>Harga satuan</dt><dd><?= rp($d['harga']) ?></dd></div>
    <div><dt>Nilai persediaan</dt><dd><?= rp($d['stok'] * $d['harga']) ?></dd></div>
    <div><dt>Rata-rata keluar per hari</dt><dd><?= $rata ? number_format($rata, 1, ',', '.') : '—' ?> <small><?= HARI_RATA ?> hari terakhir</small></dd></div>
    <div><dt>Stok aktif <small>(dikeluarkan lebih dulu)</small></dt><dd class="mono">
      <?php foreach ($blokAktif as $bk): ?><div><?= e(str_replace(' - ', ' – ', seri_stok_tulis([$bk]))) ?> <small>· <?= num($bk['z'] - $bk['a'] + 1) ?> <?= strtolower(e($d['satuan'])) ?></small></div><?php endforeach; ?>
      <?php if (!$blokAktif): ?><span class="muted">Kosong</span><?php endif; ?></dd></div>
    <div><dt>Stok tersimpan <small>(dipakai setelah aktif habis)</small></dt><dd class="mono">
      <?php foreach ($blokSimpan as $bk): ?><div><?= e(str_replace(' - ', ' – ', seri_stok_tulis([$bk]))) ?> <small>· <?= num($bk['z'] - $bk['a'] + 1) ?> <?= strtolower(e($d['satuan'])) ?></small></div><?php endforeach; ?>
      <?php if (!$blokSimpan): ?><span class="muted">Kosong</span><small class="muted"> — belum ada blok cadangan. Catat penerimaan baru (dengan nomor seri) untuk mengisinya.</small><?php endif; ?></dd></div>
    <div><dt>Terbit</dt><dd><?= num($d['terbit']) ?></dd></div>
    <div><dt>Rusak</dt><dd><?= num($d['rusak']) ?></dd></div>
  </dl>
  <?php if ($seriCocok === false): ?><div class="warn-box" style="margin:0;border-radius:0;border-left:0;border-right:0"><b>Nomor seri belum cocok dengan stok.</b> Jumlah seri <?= num($jmlAktif + $jmlSimpan) ?> ≠ stok sistem <?= num($d['stok']) ?> (selisih <?= num(abs($jmlAktif + $jmlSimpan - (int)$d['stok'])) ?>). Perbaiki lewat "Ubah data dokumen" di bawah.</div><?php endif; ?>
  <?php if (is_super()): ?>
  <details class="edit noprint">
    <summary>Ubah data dokumen</summary>
    <form method="post" class="grid-form panel-b">
      <?= csrf_field() ?>
      <div class="span2"><label for="e-nama">Nama dokumen</label><input id="e-nama" name="nama" maxlength="100" value="<?= e($d['nama']) ?>" required></div>
      <div><label for="e-harga">Harga satuan (Rp)</label><input id="e-harga" type="number" min="0" name="harga" value="<?= (int)$d['harga'] ?>" required></div>
      <div><label for="e-sa">Seri aktif</label><input id="e-sa" name="seri_aktif" maxlength="60" class="mono" value="<?= e($d['seri_aktif']) ?>"></div>
      <div><label for="e-ss">Seri tersimpan</label><input id="e-ss" name="seri_simpan" maxlength="60" class="mono" value="<?= e($d['seri_simpan']) ?>"></div>
      <div><label for="e-tb">Terbit</label><input id="e-tb" type="number" min="0" name="terbit" value="<?= (int)$d['terbit'] ?>"></div>
      <div><label for="e-rs">Rusak</label><input id="e-rs" type="number" min="0" name="rusak" value="<?= (int)$d['rusak'] ?>"></div>
      <div class="full"><button type="submit" class="btn">Simpan perubahan</button>
        <p class="hint">Stok tidak diubah di sini. Stok hanya berubah lewat penerimaan dan pengeluaran agar riwayatnya tetap tercatat.</p></div>
    </form>
  </details>
  <?php endif; ?>
</section>

<?php if ($riwayat || $blokAktif || $blokSimpan): ?>
<section class="panel">
  <div class="panel-h"><div><h2>Riwayat blok nomor seri</h2>
    <p>Stok awal tahun dan tiap penerimaan, beserta berapa yang sudah keluar. Pemakaian dihitung dari rentang seri di data pengeluaran.</p></div></div>
  <?php if (!$blokAktif && $blokSimpan): ?><p class="warn-box" style="margin:0 16px 12px">Stok aktif sudah habis. Stok tersimpan masih ada (<?= num($jmlSimpan) ?> <?= strtolower(e($d['satuan'])) ?>); pilih "Pakai stok tersimpan" saat mencatat pengeluaran.</p><?php endif; ?>
  <?php if (!$blokAktif && !$blokSimpan && $riwayat): ?><p class="warn-box" style="margin:0 16px 12px">Stok aktif dan stok tersimpan sudah habis (menurut nomor seri).</p><?php endif; ?>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Asal</th><th>Tanggal</th><th>Nomor seri</th><th class="num">Jumlah</th><th class="num">Terpakai</th><th class="num">Sisa</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($riwayat as $b): ?>
      <tr>
        <td><?= e($b['asal']) ?></td>
        <td class="nowrap"><?= tgl($b['tanggal']) ?></td>
        <td class="mono nowrap"><?= e($b['awal']) ?> – <?= e($b['akhir']) ?></td>
        <td class="num"><?= num($b['jumlah']) ?></td>
        <td class="num"><?= $b['terpakai'] ? '−' . num($b['terpakai']) : '–' ?></td>
        <td class="num"><b><?= num($b['sisa']) ?></b></td>
        <td><span class="st <?= $b['status'] === 'Habis' ? 'st-kritis' : ($b['status'] === 'Utuh' ? 'st-ok' : 'st-pending') ?>"><?= e($b['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$riwayat): ?><tr class="empty-row"><td colspan="7"><div class="empty"><b>Belum ada riwayat blok seri</b>Jalankan tambah_seri_blok.sql atau catat penerimaan dengan nomor seri.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <div><h2>Buku harian <?= bulan_txt($bln) ?></h2><p>Saldo awal dihitung mundur dari stok sistem saat ini dikurangi seluruh mutasi sejak awal bulan.</p></div>
    <div class="actions noprint">
      <a class="btn sm ghost" href="?id=<?= $id ?>&amp;bln=<?= $prev ?>" aria-label="Bulan sebelumnya">‹ <?= bulan_pendek($prev) ?></a>
      <form method="get" class="inline"><input type="hidden" name="id" value="<?= $id ?>"><input type="month" name="bln" value="<?= e($bln) ?>" max="<?= $kini ?>" onchange="this.form.submit()" aria-label="Pilih bulan"></form>
      <?php if ($bln < $kini): ?><a class="btn sm ghost" href="?id=<?= $id ?>&amp;bln=<?= $next ?>" aria-label="Bulan berikutnya"><?= bulan_pendek($next) ?> ›</a><?php endif; ?>
      <button type="button" class="btn sm ghost" onclick="print()">Cetak</button>
    </div>
  </div>
  <div class="strip inset">
    <div><small>Saldo awal</small><b><?= num($awal) ?></b></div>
    <div><small>Masuk</small><b class="in">+<?= num($totIn) ?></b><em><?= count($in) ?> hari</em></div>
    <div><small>Keluar</small><b class="out">−<?= num($totOut) ?></b><em><?= count($out) ?> hari</em></div>
    <div><small>Saldo akhir</small><b><?= num($awal + $totIn - $totOut) ?></b></div>
  </div>
  <div class="toolbar noprint"><label class="check"><input type="checkbox" data-toggle-class="#ledger|only-mut"<?= ($in || $out) ? ' checked' : '' ?>> Tampilkan hanya hari yang ada mutasi</label></div>
  <div class="tbl-wrap"><table id="ledger" class="ledger">
    <thead><tr><th>Tanggal</th><th class="num">Saldo awal</th><th class="num">Masuk</th><th>No. BA</th><th class="num">Keluar</th><th>Tujuan</th><th class="num">Saldo akhir</th></tr></thead>
    <tbody>
    <?php $saldo = $awal;
    for ($i = 1; $i <= $jmlHari; $i++):
      $k = sprintf('%s-%02d', $bln, $i); $a = $saldo;
      $mi = (int)($in[$k]['j'] ?? 0); $mo = (int)($out[$k]['j'] ?? 0); $saldo = $a + $mi - $mo;
      $w = (int)date('w', strtotime($k)); ?>
      <tr class="<?= ($mi || $mo) ? '' : 'idle' ?><?= $w === 0 || $w === 6 ? ' wkend' : '' ?>">
        <td class="day"><?= $i ?><small><?= substr(NAMA_HARI[$w], 0, 3) ?></small></td>
        <td class="num"><?= num($a) ?></td>
        <td class="num <?= $mi ? 'in' : '' ?>"><?= $mi ? '+' . num($mi) : '–' ?></td>
        <td class="mono"><?= e($in[$k]['x'] ?? '') ?></td>
        <td class="num <?= $mo ? 'out' : '' ?>"><?= $mo ? '−' . num($mo) : '–' ?></td>
        <td><?= e($out[$k]['x'] ?? '') ?></td>
        <td class="num"><b><?= num($saldo) ?></b></td>
      </tr>
    <?php endfor; ?>
    <?php if (!$in && !$out): ?><tr class="empty-row"><td colspan="7"><div class="empty"><b>Tidak ada mutasi di <?= bulan_txt($bln) ?></b>Pilih bulan lain atau matikan saringan di atas untuk melihat saldo harian.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php include 'partials/footer.php'; ?>