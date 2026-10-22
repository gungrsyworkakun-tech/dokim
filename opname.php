<?php require 'config.php'; login_required(); $title = 'Opname fisik';
$rows = $pdo->query('SELECT * FROM dokumen ORDER BY kode_resmi IS NULL, kode_resmi, kode')->fetchAll();
$nilai = 0; $rusak = 0;
foreach ($rows as $r) { $nilai += $r['stok'] * $r['harga']; $rusak += $r['rusak']; }
$tgl = valid_date($_GET['tgl'] ?? '') ? $_GET['tgl'] : date('Y-m-d');
$bln_lampiran = substr($tgl, 0, 7);
include 'partials/header.php'; ?>

<section class="panel noprint">
  <div class="panel-h">
    <div><h2>Lembar kerja opname</h2>
      <p>Ketik hasil hitung gudang di kolom <b>Stok fisik</b>; selisih terhadap sistem dihitung langsung. Tekan Enter untuk pindah ke baris berikutnya. Isian tersimpan sementara di browser ini, jadi tidak hilang bila halaman tertutup.</p></div>
    <div class="actions">
      <form method="get" class="inline"><label class="check" for="op-tgl">Tanggal</label><input id="op-tgl" type="date" name="tgl" value="<?= e($tgl) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"></form>
      <button type="button" class="btn sm ghost" id="op-isi" title="Isi baris yang masih kosong dengan angka stok sistem">Isi yang kosong sesuai sistem</button>
      <button type="button" class="btn sm ghost" id="op-reset">Kosongkan</button>
      <button type="button" class="btn sm" onclick="print()">Cetak berita acara</button>
    </div>
  </div>
</section>

<!-- ======== HALAMAN 1 (cetak saja): Berita Acara ======== -->
<section class="print-only ba-page">
  <div class="ba-kop">
    <div class="ba-kop-text">
      <b><?= e(APP_KOP1) ?></b>
      <b><?= e(APP_KOP2) ?></b>
      <b><?= e(APP_KOP3) ?></b>
      <b class="ba-kop-unit"><?= e(APP_KOP4) ?></b>
      <span><?= e(APP_KOP_ALAMAT) ?></span>
      <span>Pos-el: <?= e(APP_KOP_EMAIL) ?></span>
    </div>
  </div>
  <hr class="ba-rule">

  <h2 class="ba-title">BERITA ACARA OPNAME FISIK PERSEDIAAN DOKUMEN KEIMIGRASIAN</h2>
  <p class="ba-nomor"><?= e(APP_NOMOR_BA) ?></p>

  <p class="ba-par">Pada hari ini <?= tgl_narasi($tgl) ?>, kami yang bertanda tangan di bawah ini :</p>

  <div class="ba-pihak">
    <p>Nama&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <?= e(APP_PETUGAS1_NAMA) ?><br>
       NIP&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <?= e(APP_PETUGAS1_NIP) ?><br>
       Jabatan&nbsp;&nbsp;&nbsp;: <?= e(APP_PETUGAS1_JABATAN) ?></p>
    <p>Nama&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <?= e(APP_PETUGAS2_NAMA) ?><br>
       NIP&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <?= e(APP_PETUGAS2_NIP) ?><br>
       Jabatan&nbsp;&nbsp;&nbsp;: <?= e(APP_PETUGAS2_JABATAN) ?></p>
  </div>

  <p class="ba-par">telah melaksanakan opname fisik persediaan Dokumen Keimigrasian dengan hasil sebagaimana terlampir.</p>
  <p class="ba-par">Demikian berita acara ini dibuat dengan sebenarnya untuk dapat dipergunakan sebagaimana mestinya.</p>

  <table class="ba-sign">
    <tr><td>Pelaksana Opname Fisik<br>Persediaan Dokim,</td><td>Pelaksana Opname Fisik<br>Persediaan Dokim,</td></tr>
    <tr><td class="ba-sign-gap"></td><td class="ba-sign-gap"></td></tr>
    <tr><td><b><?= e(APP_PETUGAS1_NAMA) ?></b><br>NIP <?= e(APP_PETUGAS1_NIP) ?></td>
        <td><b><?= e(APP_PETUGAS2_NAMA) ?></b><br>NIP <?= e(APP_PETUGAS2_NIP) ?></td></tr>
  </table>
  <table class="ba-sign ba-sign-single">
    <tr><td>Mengetahui,<br><?= e(APP_KABAG_JABATAN) ?></td></tr>
    <tr><td class="ba-sign-gap"></td></tr>
    <tr><td><b><?= e(APP_KABAG_NAMA) ?></b><br>NIP <?= e(APP_KABAG_NIP) ?></td></tr>
  </table>
</section>

<!-- ======== HALAMAN 2 (cetak saja): Lampiran ======== -->
<section class="print-only ba-page ba-lampiran">
  <h2 class="ba-title ba-title-sm">BERITA ACARA OPNAME FISIK PADA<br><?= e(APP_KOP4) ?></h2>
  <p class="ba-nomor">PERIODE : <?= mb_strtoupper(bulan_txt($bln_lampiran)) ?></p>
  <p class="ba-nomor"><?= e(APP_NOMOR_BA) ?></p>

  <table class="ba-tbl">
    <thead><tr><th>NO</th><th>KODE</th><th>NAMA BARANG</th><th>JUMLAH</th><th>SATUAN</th><th>HARGA PENDAPATAN TERAKHIR</th><th>TOTAL NILAI PERSEDIAAN</th><th>KONDISI BARANG</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td class="num"><?= $i + 1 ?></td>
        <td class="mono"><?= $r['kode_resmi'] ? (int)$r['kode_resmi'] : e($r['kode']) ?></td>
        <td><?= e($r['nama']) ?></td>
        <td class="num"><?= num($r['stok']) ?></td>
        <td><?= e(mb_strtoupper($r['satuan'])) ?></td>
        <td class="num"><?= num($r['harga']) ?></td>
        <td class="num"><?= num($r['stok'] * $r['harga']) ?></td>
        <td>BAIK</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="6">JUMLAH</td><td class="num"><?= num($nilai) ?></td><td></td></tr></tfoot>
  </table>

  <table class="ba-sign">
    <tr><td>OPERATOR PERSEDIAAN</td><td>PELAKSANA OPNAME FISIK PERSEDIAAN</td></tr>
    <tr><td class="ba-sign-gap"></td><td class="ba-sign-gap"></td></tr>
    <tr><td><b><?= e(APP_PETUGAS2_NAMA) ?></b><br>NIP <?= e(APP_PETUGAS2_NIP) ?></td>
        <td><b><?= e(APP_PETUGAS1_NAMA) ?></b><br>NIP <?= e(APP_PETUGAS1_NIP) ?></td></tr>
  </table>
  <table class="ba-sign ba-sign-single">
    <tr><td><?= e(APP_KABAG_JABATAN) ?></td></tr>
    <tr><td class="ba-sign-gap"></td></tr>
    <tr><td><b><?= e(APP_KABAG_NAMA) ?></b><br>NIP <?= e(APP_KABAG_NIP) ?></td></tr>
  </table>
</section>

<div class="strip noprint">
  <div><small>Item terdata</small><b><?= count($rows) ?></b><em>nilai buku <?= rp_singkat($nilai) ?></em></div>
  <div><small>Sudah dihitung</small><b><span id="op-cek">0</span><span class="of"> / <?= count($rows) ?></span></b></div>
  <div><small>Item berselisih</small><b id="op-beda">0</b></div>
  <div><small>Nilai selisih</small><b id="op-nilai">Rp 0</b><em><?= num($rusak) ?> keping rusak tercatat</em></div>
</div>

<section class="panel noprint" id="opname" data-tgl="<?= e($tgl) ?>">
  <div class="tbl-wrap"><table>
    <thead><tr><th>Kode</th><th>Dokumen</th><th class="num">Stok sistem</th><th class="num">Stok fisik</th><th class="num">Selisih</th><th class="num">Rusak</th><th class="num">Harga</th><th class="num">Nilai buku</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono nowrap"><?= e($r['kode']) ?></td>
        <td class="doc"><?= e($r['nama']) ?><div class="sub"><?= strtolower($r['satuan']) ?></div></td>
        <td class="num"><?= num($r['stok']) ?></td>
        <td class="num"><input class="op-in" type="number" min="0" inputmode="numeric" name="fisik[<?= $r['id'] ?>]" data-sys="<?= (int)$r['stok'] ?>" data-harga="<?= (int)$r['harga'] ?>" aria-label="Stok fisik <?= e($r['nama']) ?>"></td>
        <td class="num muted" data-sel>—</td>
        <td class="num"><?= num($r['rusak']) ?></td>
        <td class="num"><?= rp($r['harga']) ?></td>
        <td class="num"><?= rp($r['stok'] * $r['harga']) ?></td>
        <td><input class="op-ket" name="ket[<?= $r['id'] ?>]" maxlength="120" aria-label="Keterangan <?= e($r['nama']) ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="7">Total nilai buku persediaan</td><td class="num"><?= rp($nilai) ?></td><td></td></tr></tfoot>
  </table></div>
</section>

<?php include 'partials/footer.php'; ?>