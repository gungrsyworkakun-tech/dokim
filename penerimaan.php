<?php require 'config.php';
require_once __DIR__ . '/lib/seri_cek.php'; require_once __DIR__ . '/lib/seri_stok.php'; login_required(); $title = 'Penerimaan';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['act'] ?? 'add';
  $ret = ret_url('penerimaan.php');

  /* ---------- Verifikasi (satu atau banyak) ---------- */
  if ($act === 'verify') {
    super_required();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['id'] ?? []))));
    if (!$ids) { flash('Centang minimal satu penerimaan yang akan diverifikasi.', 'err'); back($ret); }
    $s = $pdo->prepare("UPDATE penerimaan SET status='Terverifikasi' WHERE status='Pending' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $s->execute($ids);
    flash($s->rowCount() . ' penerimaan diverifikasi.');
    back($ret);
  }

  /* ---------- Buat barcode untuk semua penerimaan yang belum punya ---------- */
  if ($act === 'buat_barcode_massal') {
    $rows = $pdo->query('SELECT id FROM penerimaan WHERE barcode_token IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare('UPDATE penerimaan SET barcode_token=?, barcode_dibuat_at=NOW() WHERE id=?');
    foreach ($rows as $pid) $upd->execute([bin2hex(random_bytes(16)), $pid]);
    flash(count($rows) ? count($rows) . ' barcode baru dibuat. Buka tiap baris untuk cetak labelnya.' : 'Semua penerimaan sudah punya barcode.');
    back($ret);
  }

  /* ---------- Hapus (koreksi stok, tidak boleh membuat stok minus) ---------- */
  if ($act === 'del') {
    super_required();
    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT p.id, p.jumlah, p.dokumen_id, p.no_ba, p.seri_awal, p.seri_akhir, d.stok FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id WHERE p.id=? FOR UPDATE');
    $s->execute([(int)($_POST['id'] ?? 0)]);
    $r = $s->fetch();
    if (!$r) { $pdo->rollBack(); flash('Data penerimaan tidak ditemukan.', 'err'); }
    elseif ($r['stok'] < $r['jumlah']) {
      $pdo->rollBack();
      flash('Tidak dapat dihapus: stok saat ini ' . num($r['stok']) . ' lebih kecil dari jumlah penerimaan ' . num($r['jumlah']) . ', artinya sebagian sudah dikeluarkan. Hapus atau koreksi pengeluarannya dulu.', 'err');
    } else {
      $pdo->prepare('UPDATE dokumen SET stok=stok-? WHERE id=?')->execute([$r['jumlah'], $r['dokumen_id']]);
      $pdo->prepare('DELETE FROM penerimaan WHERE id=?')->execute([$r['id']]);
      $seriCabut = seri_stok_cabut($pdo, (int)$r['dokumen_id'], $r['seri_awal'], $r['seri_akhir']);   // nomor seri ikut dicabut dari stok
      $pdo->commit();
      flash('Penerimaan ' . $r['no_ba'] . ' dihapus dan stok dikurangi ' . num($r['jumlah']) . '.' . ($seriCabut ? ' Nomor seri ikut dikeluarkan dari stok.' : ' Nomor seri stok tidak berubah otomatis, cek di halaman detail dokumen.'));
    }
    back($ret);
  }

  /* ---------- Tambah ---------- */
  $f = [
    'dokumen_id' => (int)($_POST['dokumen_id'] ?? 0),
    'jumlah'     => (int)($_POST['jumlah'] ?? 0),
    'tanggal'    => (string)($_POST['tanggal'] ?? ''),
    'no_ba'      => trim((string)($_POST['no_ba'] ?? '')),
    'seri_awal'  => trim((string)($_POST['seri_awal'] ?? '')),
    'seri_akhir' => trim((string)($_POST['seri_akhir'] ?? '')),
    'catatan'    => trim((string)($_POST['catatan'] ?? '')),
  ];
  $err = [];
  if ($f['jumlah'] < 1 || $f['jumlah'] > 10000000) $err[] = 'Jumlah harus antara 1 dan 10.000.000.';
  if (!valid_date($f['tanggal']) || $f['tanggal'] > date('Y-m-d')) $err[] = 'Tanggal tidak valid atau masih di masa depan.';
  if ($f['no_ba'] === '' || mb_strlen($f['no_ba']) > 60) $err[] = 'No. BA wajib diisi (maks. 60 karakter).';
  if ($f['seri_awal'] === '' || $f['seri_akhir'] === '' || mb_strlen($f['seri_awal']) > 30 || mb_strlen($f['seri_akhir']) > 30) $err[] = 'Nomor seri awal dan akhir wajib diisi (maks. 30 karakter).';
  elseif ($f['jumlah'] >= 1 && ($e = seri_cek($f['seri_awal'], $f['seri_akhir'], $f['jumlah']))) $err[] = $e;
  if (mb_strlen($f['catatan']) > 1000) $err[] = 'Catatan maks. 1.000 karakter.';

  if (!$err) {
    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT id FROM dokumen WHERE id=? FOR UPDATE'); $s->execute([$f['dokumen_id']]);
    if (!$s->fetch()) $err[] = 'Pilih jenis dokumen.';
    else {
      $s = $pdo->prepare('SELECT 1 FROM penerimaan WHERE no_ba=? AND dokumen_id=?'); $s->execute([$f['no_ba'], $f['dokumen_id']]);
      if ($s->fetch()) $err[] = 'No. BA ' . $f['no_ba'] . ' sudah pernah dicatat untuk dokumen ini.';
    }
    if ($err) $pdo->rollBack();
    else {
      $pdo->prepare('INSERT INTO penerimaan(tanggal,dokumen_id,jumlah,no_ba,seri_awal,seri_akhir,catatan,user_id,status) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$f['tanggal'], $f['dokumen_id'], $f['jumlah'], $f['no_ba'], $f['seri_awal'], $f['seri_akhir'], $f['catatan'] ?: null, $_SESSION['u']['id'], is_super() ? 'Terverifikasi' : 'Pending']);
      $pdo->prepare('UPDATE dokumen SET stok=stok+? WHERE id=?')->execute([$f['jumlah'], $f['dokumen_id']]);
      $seriOk = seri_stok_masuk($pdo, (int)$f['dokumen_id'], $f['seri_awal'], $f['seri_akhir']);   // masuk ke stok tersimpan
      $pdo->commit();
      flash('Penerimaan ' . num($f['jumlah']) . ' keping tersimpan' . (is_super() ? '.' : ' dan menunggu verifikasi superadmin.') . ($seriOk ? ' Nomor seri masuk ke stok tersimpan.' : ' Nomor seri stok tidak berubah otomatis (cek di halaman detail dokumen).'));
      back('penerimaan.php');
    }
  }
  $_SESSION['old'] = $f;
  flash(implode(' ', $err), 'err');
  back('penerimaan.php#form');
}

/* ---------- Data halaman ---------- */
$old = $_SESSION['old'] ?? []; unset($_SESSION['old']);
$pilih = (int)($old['dokumen_id'] ?? ($_GET['baru'] ?? 0));

$belumBarcode = (int)$pdo->query('SELECT COUNT(*) FROM penerimaan WHERE barcode_token IS NULL')->fetchColumn();

[$w, $p, $fl] = trx_filter();
$fl['status'] = in_array($_GET['status'] ?? '', ['Pending', 'Terverifikasi'], true) ? $_GET['status'] : '';
if ($fl['status'] !== '') { $w .= ' AND p.status=?'; $p[] = $fl['status']; }
$from = "FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id LEFT JOIN users u ON u.id=p.user_id WHERE $w";

if (isset($_GET['export'])) {
  $s = $pdo->prepare("SELECT p.tanggal, d.kode, d.nama, p.jumlah, d.satuan, p.no_ba, p.seri_awal, p.seri_akhir, u.nama, p.status, p.catatan $from ORDER BY p.tanggal, p.id");
  $s->execute($p);
  csv_out('penerimaan-' . date('Ymd') . '.csv', ['Tanggal', 'Kode', 'Jenis Dokumen', 'Jumlah', 'Satuan', 'No. BA', 'Seri Awal', 'Seri Akhir', 'Petugas', 'Status', 'Catatan'], $s->fetchAll(PDO::FETCH_NUM));
}
$s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(p.jumlah),0) j $from"); $s->execute($p); $sum = $s->fetch();
$s = $pdo->prepare("SELECT p.*, d.nama, d.kode, u.nama petugas $from ORDER BY p.tanggal DESC, p.id DESC LIMIT 200"); $s->execute($p); $rows = $s->fetchAll();

$dok    = $pdo->query('SELECT id, kode, nama FROM dokumen ORDER BY nama')->fetchAll();
$lastBa = $pdo->query('SELECT no_ba FROM penerimaan ORDER BY id DESC LIMIT 1')->fetchColumn();
$adaFilter = $fl['q'] !== '' || $fl['dok'] || $fl['dari'] || $fl['sampai'] || $fl['status'];
$qs = cur_qs();
$exportUrl = '?' . http_build_query(array_diff_key($_GET, ['baru' => 1]) + ['export' => 1]);
$kolom = is_super() ? 10 : 8;
include 'partials/header.php'; ?>

<section class="panel" id="form">
  <div class="panel-h"><div><h2>Catat penerimaan</h2>
    <p>Kolom bertanda <span class="req">*</span> wajib diisi. Nomor seri awal melanjutkan seri terakhir dan seri akhir dihitung dari jumlah; keduanya tetap bisa diubah.</p></div></div>
  <form method="post" data-trx="in" class="form-wrap" autocomplete="off">
    <div class="form-main grid-form">
      <?= csrf_field() ?><input type="hidden" name="act" value="add">
      <div class="span2"><label for="f-dok">Jenis dokumen <span class="req">*</span></label>
        <select id="f-dok" name="dokumen_id" required><option value="">Pilih jenis dokumen…</option>
          <?php foreach ($dok as $d): ?><option value="<?= $d['id'] ?>"<?= $pilih === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['nama']) ?> (<?= e($d['kode']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div><label for="f-tgl">Tanggal diterima <span class="req">*</span></label><input id="f-tgl" type="date" name="tanggal" max="<?= date('Y-m-d') ?>" value="<?= e($old['tanggal'] ?? date('Y-m-d')) ?>" required></div>
      <div><label for="f-jml">Jumlah <span class="req">*</span></label><input id="f-jml" type="number" name="jumlah" min="1" max="10000000" inputmode="numeric" value="<?= e(($old['jumlah'] ?? 0) ?: '') ?>" placeholder="mis. 10000" required></div>
      <div><label for="f-sa">No. seri awal <span class="req">*</span></label><input id="f-sa" name="seri_awal" maxlength="30" class="mono" value="<?= e($old['seri_awal'] ?? '') ?>" required><div class="hint" data-o="last"></div></div>
      <div><label for="f-sz">No. seri akhir <span class="req">*</span></label><input id="f-sz" name="seri_akhir" maxlength="30" class="mono" value="<?= e($old['seri_akhir'] ?? '') ?>" required></div>
      <div class="span2"><label for="f-ba">No. berita acara <span class="req">*</span></label><input id="f-ba" name="no_ba" maxlength="60" class="mono" value="<?= e($old['no_ba'] ?? '') ?>" placeholder="BA/103/IM/I/2026" required>
        <?php if ($lastBa): ?><div class="hint">BA penerimaan terakhir: <span class="mono"><?= e($lastBa) ?></span></div><?php endif; ?></div>
      <div class="full"><label for="f-cat">Catatan</label><textarea id="f-cat" name="catatan" rows="2" maxlength="1000" placeholder="Opsional, mis. kondisi kemasan atau nama pengirim"><?= e($old['catatan'] ?? '') ?></textarea></div>
      <div class="full"><button type="submit" class="btn">Simpan penerimaan</button></div>
    </div>
    <aside class="form-side">
      <h3>Periksa sebelum menyimpan</h3>
      <dl class="calc">
        <div><dt>Dokumen</dt><dd data-o="nama">—</dd></div>
        <div><dt>Stok saat ini</dt><dd data-o="stok">—</dd></div>
        <div><dt>Stok setelah dicatat</dt><dd class="big" data-o="after">—</dd></div>
        <div><dt>Rentang seri</dt><dd data-o="seri">—</dd></div>
      </dl>
      <p class="warn-box" data-o="warn" hidden></p>
      <p class="side-note"><?= is_super() ? 'Penerimaan oleh superadmin langsung berstatus terverifikasi.' : 'Stok langsung bertambah. Status penerimaan menunggu verifikasi superadmin.' ?></p>
    </aside>
  </form>
</section>

<section class="panel">
  <div class="panel-h">
    <div><h2>Riwayat penerimaan</h2>
      <p><?= num($sum['n']) ?> transaksi, total <?= num($sum['j']) ?> keping<?= $adaFilter ? ' sesuai saringan' : '' ?><?= $sum['n'] > 200 ? '. Menampilkan 200 terbaru; unduh CSV untuk data lengkap' : '' ?>.</p></div>
    <div class="actions">
      <?php if (is_super() && $rows): ?><button type="submit" class="btn sm" form="fverify">Verifikasi yang dicentang</button><?php endif; ?>
      <?php if ($belumBarcode): ?><form method="post" class="inline" data-confirm="Buat barcode untuk <?= $belumBarcode ?> penerimaan yang belum punya?"><?= csrf_field() ?><input type="hidden" name="act" value="buat_barcode_massal"><input type="hidden" name="ret" value="<?= e($qs ?? '') ?>"><button class="btn sm ghost">Buat barcode untuk semua (<?= $belumBarcode ?>)</button></form><?php endif; ?>
      <a class="btn sm ghost" href="<?= e($exportUrl) ?>">Unduh CSV</a>
    </div>
  </div>
  <form class="toolbar" method="get">
    <div class="grow"><label for="q">Cari</label><input id="q" type="search" name="q" value="<?= e($fl['q']) ?>" placeholder="No. BA atau nomor seri"></div>
    <div><label for="fd">Dokumen</label><select id="fd" name="dok"><option value="">Semua</option>
      <?php foreach ($dok as $d): ?><option value="<?= $d['id'] ?>"<?= $fl['dok'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['nama']) ?></option><?php endforeach; ?></select></div>
    <div><label for="fs">Status</label><select id="fs" name="status"><option value="">Semua</option>
      <option value="Pending"<?= $fl['status'] === 'Pending' ? ' selected' : '' ?>>Menunggu verifikasi</option>
      <option value="Terverifikasi"<?= $fl['status'] === 'Terverifikasi' ? ' selected' : '' ?>>Terverifikasi</option></select></div>
    <div><label for="f1">Dari</label><input id="f1" type="date" name="dari" value="<?= e($fl['dari']) ?>"></div>
    <div><label for="f2">Sampai</label><input id="f2" type="date" name="sampai" value="<?= e($fl['sampai']) ?>"></div>
    <div class="tb-act"><button class="btn sm">Terapkan</button><?php if ($adaFilter): ?><a class="btn sm ghost" href="penerimaan.php">Hapus saringan</a><?php endif; ?></div>
  </form>

  <?php if (is_super()): ?><form id="fverify" method="post" data-confirm="Verifikasi semua penerimaan yang dicentang?"><?= csrf_field() ?><input type="hidden" name="act" value="verify"><input type="hidden" name="ret" value="<?= e($qs) ?>"></form><?php endif; ?>

  <div class="tbl-wrap"><table id="tbl">
    <thead><tr>
      <?php if (is_super()): ?><th class="chk"><input type="checkbox" data-checkall="#tbl" aria-label="Centang semua yang menunggu"></th><?php endif; ?>
      <th data-sort>Tanggal</th><th data-sort>Dokumen</th><th data-sort="num" class="num">Jumlah</th><th data-sort>No. BA</th><th>Rentang seri</th><th data-sort>Petugas</th><th data-sort>Status</th><th>Barcode</th>
      <?php if (is_super()): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pend = $r['status'] === 'Pending'; ?>
      <tr>
        <?php if (is_super()): ?><td class="chk"><?php if ($pend): ?><input type="checkbox" name="id[]" value="<?= $r['id'] ?>" form="fverify" aria-label="Centang <?= e($r['no_ba']) ?>"><?php endif; ?></td><?php endif; ?>
        <td class="nowrap" data-v="<?= e($r['tanggal']) ?>"><?= tgl($r['tanggal']) ?></td>
        <td><a href="stok_detail.php?id=<?= $r['dokumen_id'] ?>&amp;bln=<?= substr($r['tanggal'], 0, 7) ?>"><?= e($r['nama']) ?></a>
          <?php if ($r['catatan']): ?><div class="sub"><?= e($r['catatan']) ?></div><?php endif; ?></td>
        <td class="num in">+<?= num($r['jumlah']) ?></td>
        <td class="mono nowrap">
          <?php if (!empty($r['file_ba'])): ?>
            <a href="uploads/berita_acara/penerimaan/<?= e($r['file_ba']) ?>" target="_blank" rel="noopener" title="Buka berkas BA"><?= e($r['no_ba']) ?></a>
          <?php else: ?>
            <?= e($r['no_ba']) ?> <a class="ba-upload" href="berkas_ba.php?tipe=penerimaan&id=<?= $r['id'] ?>" title="Unggah berkas BA">unggah</a>
          <?php endif; ?>
        </td>
        <td class="mono nowrap"><?= e($r['seri_awal']) ?> – <?= e($r['seri_akhir']) ?><?php if ($cekSeri = seri_cek((string)$r['seri_awal'], (string)$r['seri_akhir'], (int)$r['jumlah'])): ?> <span class="st st-kritis" title="<?= e($cekSeri) ?>">seri tidak sesuai</span><?php endif; ?></td>
        <td><?= e($r['petugas'] ?? '-') ?></td>
        <td><span class="st <?= $pend ? 'st-pending' : 'st-ok' ?>"><?= $pend ? 'Menunggu verifikasi' : 'Terverifikasi' ?></span></td>
        <td class="nowrap"><a class="btn sm ghost" href="penerimaan_barcode.php?id=<?= $r['id'] ?>"><?= $r['barcode_token'] ? 'Lihat' : 'Buat' ?></a></td>
        <?php if (is_super()): ?><td><div class="row-act">
          <?php if ($pend): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="verify"><input type="hidden" name="id[]" value="<?= $r['id'] ?>"><input type="hidden" name="ret" value="<?= e($qs) ?>"><button class="btn sm ghost">Verifikasi</button></form><?php endif; ?>
          <form method="post" class="inline" data-confirm="Hapus penerimaan <?= e($r['no_ba']) ?>? Stok <?= e($r['nama']) ?> akan dikurangi <?= num($r['jumlah']) ?>."><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="ret" value="<?= e($qs) ?>"><button class="btn sm danger">Hapus</button></form>
        </div></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr class="empty-row"><td colspan="<?= $kolom ?>"><div class="empty">
      <?php if ($adaFilter): ?><b>Tidak ada penerimaan yang cocok</b>Ubah atau hapus saringan untuk melihat data lain.
      <?php else: ?><b>Belum ada penerimaan</b>Catat penerimaan pertama lewat formulir di atas.<?php endif; ?>
    </div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php include 'partials/footer.php'; ?>
