<?php require 'config.php';
require_once __DIR__ . '/lib/seri_cek.php'; login_required(); $title = 'Pengeluaran';

// Daftar tujuan dikelola di halaman Pengaturan. Kalau tabelnya belum ada
// (migrasi_tujuan_pengeluaran.sql belum dijalankan), pakai daftar bawaan.
try { $TUJUAN = $pdo->query('SELECT nama FROM tujuan_pengeluaran ORDER BY nama')->fetchAll(PDO::FETCH_COLUMN); }
catch (Throwable $e) { $TUJUAN = ['TPI', 'Doklan', 'Kantor Imigrasi', 'ULP']; }
if (!$TUJUAN) $TUJUAN = ['TPI', 'Doklan', 'Kantor Imigrasi', 'ULP'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['act'] ?? 'add';
  $ret = ret_url('pengeluaran.php');

  /* ---------- Hapus (stok dikembalikan) ---------- */
  if ($act === 'del') {
    super_required();
    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT id, jumlah, dokumen_id, no_ba FROM pengeluaran WHERE id=? FOR UPDATE'); $s->execute([(int)($_POST['id'] ?? 0)]);
    if ($r = $s->fetch()) {
      $pdo->prepare('UPDATE dokumen SET stok=stok+? WHERE id=?')->execute([$r['jumlah'], $r['dokumen_id']]);
      $pdo->prepare('DELETE FROM pengeluaran WHERE id=?')->execute([$r['id']]);
      $pdo->commit();
      flash('Pengeluaran ' . $r['no_ba'] . ' dihapus dan ' . num($r['jumlah']) . ' keping dikembalikan ke stok.');
    } else { $pdo->rollBack(); flash('Data pengeluaran tidak ditemukan.', 'err'); }
    back($ret);
  }

  /* ---------- Tambah ---------- */
  $f = [
    'dokumen_id' => (int)($_POST['dokumen_id'] ?? 0),
    'jumlah'     => (int)($_POST['jumlah'] ?? 0),
    'tanggal'    => (string)($_POST['tanggal'] ?? ''),
    'tujuan'     => (string)($_POST['tujuan'] ?? ''),
    'no_ba'      => trim((string)($_POST['no_ba'] ?? '')),
    'seri_awal'  => trim((string)($_POST['seri_awal'] ?? '')),
    'seri_akhir' => trim((string)($_POST['seri_akhir'] ?? '')),
    'dari_token' => trim((string)($_POST['dari_token'] ?? '')),
  ];
  $err = [];
  if ($f['jumlah'] < 1) $err[] = 'Jumlah minimal 1.';
  if (!valid_date($f['tanggal']) || $f['tanggal'] > date('Y-m-d')) $err[] = 'Tanggal tidak valid atau masih di masa depan.';
  if (!in_array($f['tujuan'], $TUJUAN, true)) $err[] = 'Pilih tujuan pengeluaran.';
  if ($f['no_ba'] === '' || mb_strlen($f['no_ba']) > 60) $err[] = 'No. BA wajib diisi (maks. 60 karakter).';
  if ($f['seri_awal'] === '' || $f['seri_akhir'] === '' || mb_strlen($f['seri_awal']) > 30 || mb_strlen($f['seri_akhir']) > 30) $err[] = 'Nomor seri awal dan akhir wajib diisi (maks. 30 karakter).';
  elseif ($f['jumlah'] >= 1 && ($e = seri_cek($f['seri_awal'], $f['seri_akhir'], $f['jumlah']))) $err[] = $e;

  $dariPenerimaanId = null;
  if (($_POST['dari_token'] ?? '') !== '') {
    $s = $pdo->prepare('SELECT id FROM penerimaan WHERE barcode_token=?'); $s->execute([(string)$_POST['dari_token']]);
    $dariPenerimaanId = $s->fetchColumn() ?: null;
  }

  if (!$err) {
    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT stok, nama FROM dokumen WHERE id=? FOR UPDATE'); $s->execute([$f['dokumen_id']]); $dk = $s->fetch();
    if (!$dk) $err[] = 'Pilih jenis dokumen.';
    elseif ($f['jumlah'] > $dk['stok']) $err[] = 'Stok ' . $dk['nama'] . ' hanya ' . num($dk['stok']) . ', tidak cukup untuk mengeluarkan ' . num($f['jumlah']) . '.';
    else {
      $s = $pdo->prepare('SELECT 1 FROM pengeluaran WHERE no_ba=? AND dokumen_id=?'); $s->execute([$f['no_ba'], $f['dokumen_id']]);
      if ($s->fetch()) $err[] = 'No. BA ' . $f['no_ba'] . ' sudah pernah dicatat untuk dokumen ini.';
    }
    if ($err) $pdo->rollBack();
    else {
      $pdo->prepare('INSERT INTO pengeluaran(tanggal,dokumen_id,dari_penerimaan_id,jumlah,tujuan,seri_awal,seri_akhir,no_ba,user_id) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$f['tanggal'], $f['dokumen_id'], $dariPenerimaanId, $f['jumlah'], $f['tujuan'], $f['seri_awal'], $f['seri_akhir'], $f['no_ba'], $_SESSION['u']['id']]);
      $pdo->prepare('UPDATE dokumen SET stok=stok-? WHERE id=?')->execute([$f['jumlah'], $f['dokumen_id']]);
      $pdo->commit();
      $sisa = $dk['stok'] - $f['jumlah'];
      flash('Pengeluaran ' . num($f['jumlah']) . ' keping ke ' . $f['tujuan'] . ' tersimpan. Sisa stok ' . $dk['nama'] . ': ' . num($sisa) . '.' . ($sisa <= STOK_KRITIS ? ' Stok sudah kritis, segera ajukan pengadaan.' : ''));
      back('pengeluaran.php');
    }
  }
  $_SESSION['old'] = $f;
  flash(implode(' ', $err), 'err');
  back('pengeluaran.php#form');
}

/* ---------- Data halaman ---------- */
$old = $_SESSION['old'] ?? []; unset($_SESSION['old']);

/* Dari scan barcode: cari batch penerimaan lewat token, isi otomatis jenis dokumen & seri awal */
$batch = null; $dariToken = (string)($old['dari_token'] ?? $_GET['dari'] ?? '');
if ($dariToken !== '') {
  $s = $pdo->prepare('SELECT p.*, d.nama dnama, d.kode FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id WHERE p.barcode_token=?');
  $s->execute([$dariToken]); $batch = $s->fetch();
  if (!$batch) flash('Kode barcode tidak dikenali atau sudah tidak berlaku.', 'err');
}
$pilih = (int)($old['dokumen_id'] ?? ($batch['dokumen_id'] ?? ($_GET['baru'] ?? 0)));

[$w, $p, $fl] = trx_filter();
$fl['tujuan'] = in_array($_GET['tujuan'] ?? '', $TUJUAN, true) ? $_GET['tujuan'] : '';
if ($fl['tujuan'] !== '') { $w .= ' AND p.tujuan=?'; $p[] = $fl['tujuan']; }
$from = "FROM pengeluaran p JOIN dokumen d ON d.id=p.dokumen_id LEFT JOIN users u ON u.id=p.user_id WHERE $w";

if (isset($_GET['export'])) {
  $s = $pdo->prepare("SELECT p.tanggal, d.kode, d.nama, p.jumlah, d.satuan, p.tujuan, p.no_ba, p.seri_awal, p.seri_akhir, u.nama $from ORDER BY p.tanggal, p.id");
  $s->execute($p);
  csv_out('pengeluaran-' . date('Ymd') . '.csv', ['Tanggal', 'Kode', 'Jenis Dokumen', 'Jumlah', 'Satuan', 'Tujuan', 'No. BA', 'Seri Awal', 'Seri Akhir', 'Petugas'], $s->fetchAll(PDO::FETCH_NUM));
}
$s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(p.jumlah),0) j $from"); $s->execute($p); $sum = $s->fetch();
$s = $pdo->prepare("SELECT p.*, d.nama, d.kode, u.nama petugas $from ORDER BY p.tanggal DESC, p.id DESC LIMIT 200"); $s->execute($p); $rows = $s->fetchAll();

$dok    = $pdo->query('SELECT id, kode, nama, stok FROM dokumen ORDER BY nama')->fetchAll();
$lastBa = $pdo->query('SELECT no_ba FROM pengeluaran ORDER BY id DESC LIMIT 1')->fetchColumn();
$adaFilter = $fl['q'] !== '' || $fl['dok'] || $fl['dari'] || $fl['sampai'] || $fl['tujuan'];
$qs = cur_qs();
$exportUrl = '?' . http_build_query(array_diff_key($_GET, ['baru' => 1]) + ['export' => 1]);
$kolom = is_super() ? 8 : 7;
include 'partials/header.php'; ?>

<?php if ($batch): ?>
<div class="notice"><span>Dari scan barcode: batch <b><?= e($batch['dnama']) ?></b> (<?= e($batch['kode']) ?>), No. BA <?= e($batch['no_ba']) ?>, diterima <?= tgl($batch['tanggal']) ?>. Jenis dokumen dan seri awal sudah diisi dari batch ini — periksa lagi sebelum menyimpan.</span></div>
<?php endif; ?>

<section class="panel" id="form">
  <div class="panel-h"><div><h2>Catat pengeluaran</h2>
    <p>Stok tersedia diperiksa otomatis; tombol simpan terkunci bila jumlah melebihi stok. Seri awal melanjutkan seri pengeluaran terakhir dokumen yang dipilih.</p></div></div>
  <form method="post" data-trx="out" class="form-wrap" autocomplete="off">
    <div class="form-main grid-form">
      <?= csrf_field() ?><input type="hidden" name="act" value="add"><input type="hidden" name="dari_token" value="<?= e($dariToken) ?>">
      <div class="span2"><label for="f-dok">Jenis dokumen <span class="req">*</span></label>
        <select id="f-dok" name="dokumen_id" required><option value="">Pilih jenis dokumen…</option>
          <?php foreach ($dok as $d): ?><option value="<?= $d['id'] ?>"<?= $pilih === (int)$d['id'] ? ' selected' : '' ?><?= $d['stok'] <= 0 ? ' disabled' : '' ?>><?= e($d['nama']) ?> (stok <?= num($d['stok']) ?>)</option><?php endforeach; ?>
        </select><div class="hint">Dokumen dengan stok habis tidak bisa dipilih.</div></div>
      <div><label for="f-tgl">Tanggal keluar <span class="req">*</span></label><input id="f-tgl" type="date" name="tanggal" max="<?= date('Y-m-d') ?>" value="<?= e($old['tanggal'] ?? date('Y-m-d')) ?>" required></div>
      <div><label for="f-jml">Jumlah <span class="req">*</span></label><input id="f-jml" type="number" name="jumlah" min="1" inputmode="numeric" value="<?= e(($old['jumlah'] ?? 0) ?: '') ?>" required></div>
      <div><label for="f-sa">No. seri awal <span class="req">*</span></label><input id="f-sa" name="seri_awal" maxlength="30" class="mono" value="<?= e($old['seri_awal'] ?? ($batch['seri_awal'] ?? '')) ?>" required><div class="hint" data-o="last"></div></div>
      <div><label for="f-sz">No. seri akhir <span class="req">*</span></label><input id="f-sz" name="seri_akhir" maxlength="30" class="mono" value="<?= e($old['seri_akhir'] ?? '') ?>" required></div>
      <div><label for="f-tj">Tujuan <span class="req">*</span></label><select id="f-tj" name="tujuan" required>
        <?php foreach ($TUJUAN as $t): ?><option<?= ($old['tujuan'] ?? '') === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
      <div class="span2"><label for="f-ba">No. berita acara <span class="req">*</span></label><input id="f-ba" name="no_ba" maxlength="60" class="mono" value="<?= e($old['no_ba'] ?? '') ?>" placeholder="BA/OUT/107/IM/I/2026" required>
        <?php if ($lastBa): ?><div class="hint">BA pengeluaran terakhir: <span class="mono"><?= e($lastBa) ?></span></div><?php endif; ?></div>
      <div class="full"><button type="submit" class="btn">Simpan pengeluaran</button></div>
    </div>
    <aside class="form-side">
      <h3>Periksa sebelum menyimpan</h3>
      <dl class="calc">
        <div><dt>Dokumen</dt><dd data-o="nama">—</dd></div>
        <div><dt>Stok saat ini</dt><dd data-o="stok">—</dd></div>
        <div><dt>Sisa setelah keluar</dt><dd class="big" data-o="after">—</dd></div>
        <div><dt>Rentang seri</dt><dd data-o="seri">—</dd></div>
      </dl>
      <p class="warn-box" data-o="warn" hidden></p>
      <p class="side-note">Pengeluaran yang salah hanya bisa dihapus superadmin; stoknya akan dikembalikan.</p>
    </aside>
  </form>
</section>

<section class="panel">
  <div class="panel-h">
    <div><h2>Riwayat pengeluaran</h2>
      <p><?= num($sum['n']) ?> transaksi, total <?= num($sum['j']) ?> keping<?= $adaFilter ? ' sesuai saringan' : '' ?><?= $sum['n'] > 200 ? '. Menampilkan 200 terbaru; unduh CSV untuk data lengkap' : '' ?>.</p></div>
    <a class="btn sm ghost" href="<?= e($exportUrl) ?>">Unduh CSV</a>
  </div>
  <form class="toolbar" method="get">
    <div class="grow"><label for="q">Cari</label><input id="q" type="search" name="q" value="<?= e($fl['q']) ?>" placeholder="No. BA atau nomor seri"></div>
    <div><label for="fd">Dokumen</label><select id="fd" name="dok"><option value="">Semua</option>
      <?php foreach ($dok as $d): ?><option value="<?= $d['id'] ?>"<?= $fl['dok'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['nama']) ?></option><?php endforeach; ?></select></div>
    <div><label for="ft">Tujuan</label><select id="ft" name="tujuan"><option value="">Semua</option>
      <?php foreach ($TUJUAN as $t): ?><option<?= $fl['tujuan'] === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div><label for="f1">Dari</label><input id="f1" type="date" name="dari" value="<?= e($fl['dari']) ?>"></div>
    <div><label for="f2">Sampai</label><input id="f2" type="date" name="sampai" value="<?= e($fl['sampai']) ?>"></div>
    <div class="tb-act"><button class="btn sm">Terapkan</button><?php if ($adaFilter): ?><a class="btn sm ghost" href="pengeluaran.php">Hapus saringan</a><?php endif; ?></div>
  </form>
  <div class="tbl-wrap"><table id="tbl">
    <thead><tr>
      <th data-sort>Tanggal</th><th data-sort>Dokumen</th><th data-sort="num" class="num">Jumlah</th><th data-sort>Tujuan</th><th data-sort>No. BA</th><th>Rentang seri</th><th data-sort>Petugas</th>
      <?php if (is_super()): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap" data-v="<?= e($r['tanggal']) ?>"><?= tgl($r['tanggal']) ?></td>
        <td><a href="stok_detail.php?id=<?= $r['dokumen_id'] ?>&amp;bln=<?= substr($r['tanggal'], 0, 7) ?>"><?= e($r['nama']) ?></a></td>
        <td class="num out">−<?= num($r['jumlah']) ?></td>
        <td><?= e($r['tujuan']) ?></td>
        <td class="mono nowrap">
          <?php if (!empty($r['file_ba'])): ?>
            <a href="uploads/berita_acara/pengeluaran/<?= e($r['file_ba']) ?>" target="_blank" rel="noopener" title="Buka berkas BA"><?= e($r['no_ba']) ?></a>
          <?php else: ?>
            <?= e($r['no_ba']) ?> <a class="ba-upload" href="berkas_ba.php?tipe=pengeluaran&id=<?= $r['id'] ?>" title="Unggah berkas BA">unggah</a>
          <?php endif; ?>
        </td>
        <td class="mono nowrap"><?= e($r['seri_awal']) ?> – <?= e($r['seri_akhir']) ?><?php if ($cekSeri = seri_cek((string)$r['seri_awal'], (string)$r['seri_akhir'], (int)$r['jumlah'])): ?> <span class="st st-kritis" title="<?= e($cekSeri) ?>">seri tidak sesuai</span><?php endif; ?></td>
        <td><?= e($r['petugas'] ?? '-') ?></td>
        <?php if (is_super()): ?><td class="r"><form method="post" class="inline" data-confirm="Hapus pengeluaran <?= e($r['no_ba']) ?>? <?= num($r['jumlah']) ?> keping <?= e($r['nama']) ?> akan dikembalikan ke stok."><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="ret" value="<?= e($qs) ?>"><button class="btn sm danger">Hapus</button></form></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr class="empty-row"><td colspan="<?= $kolom ?>"><div class="empty">
      <?php if ($adaFilter): ?><b>Tidak ada pengeluaran yang cocok</b>Ubah atau hapus saringan untuk melihat data lain.
      <?php else: ?><b>Belum ada pengeluaran</b>Catat pengeluaran pertama lewat formulir di atas.<?php endif; ?>
    </div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php include 'partials/footer.php'; ?>