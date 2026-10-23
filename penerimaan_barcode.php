<?php require 'config.php'; login_required(); $title = 'Barcode penerimaan';

$id = (int)($_GET['id'] ?? 0);
$s = $pdo->prepare('SELECT p.*, d.kode, d.nama, d.satuan FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id WHERE p.id=?');
$s->execute([$id]); $r = $s->fetch();
if (!$r) { flash('Data penerimaan tidak ditemukan.', 'err'); back('penerimaan.php'); }

const FOTO_MAKS   = 5;              // maks jumlah foto per batch
const FOTO_MB_MAX = 5;              // maks ukuran per foto (MB)
const FOTO_TIPE   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$dirUpload = __DIR__ . '/uploads/penerimaan/' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['act'] ?? 'simpan';

  if ($act === 'hapus_foto') {
    super_required();
    $fs = $pdo->prepare('SELECT id, file FROM penerimaan_foto WHERE id=? AND penerimaan_id=?');
    $fs->execute([(int)($_POST['foto_id'] ?? 0), $id]); $foto = $fs->fetch();
    if ($foto) {
      @unlink($dirUpload . '/' . $foto['file']);
      $pdo->prepare('DELETE FROM penerimaan_foto WHERE id=?')->execute([$foto['id']]);
      flash('Foto dihapus.');
    }
    back("penerimaan_barcode.php?id=$id");
  }

  $catatan = trim((string)($_POST['catatan'] ?? ''));
  $err = [];
  if (mb_strlen($catatan) > 1000) $err[] = 'Catatan maks. 1.000 karakter.';

  $adaFotoBaru = !empty($_FILES['foto']['name'][0] ?? '');
  $fs = $pdo->prepare('SELECT COUNT(*) FROM penerimaan_foto WHERE penerimaan_id=?'); $fs->execute([$id]);
  $fotoSudah = (int)$fs->fetchColumn();

  $simpanFoto = [];
  if ($adaFotoBaru) {
    $n = count($_FILES['foto']['name']);
    if ($fotoSudah + $n > FOTO_MAKS) $err[] = 'Maksimal ' . FOTO_MAKS . ' foto per batch (sudah ada ' . $fotoSudah . ').';
    else {
      for ($i = 0; $i < $n; $i++) {
        if (($_FILES['foto']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($_FILES['foto']['error'][$i] !== UPLOAD_ERR_OK) { $err[] = 'Gagal mengunggah salah satu foto.'; continue; }
        $tmp = $_FILES['foto']['tmp_name'][$i];
        if ($_FILES['foto']['size'][$i] > FOTO_MB_MAX * 1024 * 1024) { $err[] = 'Ukuran foto maks. ' . FOTO_MB_MAX . ' MB.'; continue; }
        $mime = @mime_content_type($tmp);
        if (!isset(FOTO_TIPE[$mime])) { $err[] = 'Foto harus berformat JPG, PNG, atau WEBP.'; continue; }
        $simpanFoto[] = ['tmp' => $tmp, 'ext' => FOTO_TIPE[$mime]];
      }
    }
  }

  if (!$err) {
    $pdo->prepare('UPDATE penerimaan SET catatan=? WHERE id=?')->execute([$catatan ?: null, $id]);
    if ($simpanFoto) {
      if (!is_dir($dirUpload)) mkdir($dirUpload, 0755, true);
      $ins = $pdo->prepare('INSERT INTO penerimaan_foto(penerimaan_id, file) VALUES(?,?)');
      foreach ($simpanFoto as $f) {
        $nama = bin2hex(random_bytes(8)) . '.' . $f['ext'];
        if (move_uploaded_file($f['tmp'], $dirUpload . '/' . $nama)) $ins->execute([$id, $nama]);
      }
    }
    if ($act === 'buat_barcode' && !$r['barcode_token']) {
      $token = bin2hex(random_bytes(16));
      $pdo->prepare('UPDATE penerimaan SET barcode_token=?, barcode_dibuat_at=NOW() WHERE id=?')->execute([$token, $id]);
      flash('Barcode dibuat. Cetak dan tempelkan di kemasan batch ini.');
    } else {
      flash('Detail batch disimpan.');
    }
    back("penerimaan_barcode.php?id=$id");
  }
  flash(implode(' ', $err), 'err');
  back("penerimaan_barcode.php?id=$id");
}

$fotos = $pdo->prepare('SELECT * FROM penerimaan_foto WHERE penerimaan_id=? ORDER BY id'); $fotos->execute([$id]); $fotos = $fotos->fetchAll();
$qrUrl = $r['barcode_token'] ? app_url('cek_barcode.php?token=' . $r['barcode_token']) : '';
include 'partials/header.php'; ?>

<section class="panel noprint">
  <div class="panel-h"><div><h2>Barcode penerimaan — <?= e($r['no_ba']) ?></h2>
    <p><?= e($r['nama']) ?> (<?= e($r['kode']) ?>) · diterima <?= tgl($r['tanggal']) ?> · <?= num($r['jumlah']) ?> <?= strtolower($r['satuan']) ?> · seri <span class="mono"><?= e($r['seri_awal']) ?> – <?= e($r['seri_akhir']) ?></span></p></div>
    <a class="btn sm ghost" href="penerimaan.php">Kembali ke penerimaan</a>
  </div>

  <form method="post" enctype="multipart/form-data" class="panel-b grid-form">
    <?= csrf_field() ?>
    <div class="full"><label for="b-cat">Catatan / keterangan batch ini</label><textarea id="b-cat" name="catatan" rows="2" maxlength="1000" placeholder="Opsional, mis. kondisi kemasan saat tiba"><?= e($r['catatan'] ?? '') ?></textarea></div>
    <div class="full"><label for="b-foto">Foto barang/kemasan (maks. <?= FOTO_MAKS ?> foto, @ maks. <?= FOTO_MB_MAX ?> MB, JPG/PNG/WEBP)</label>
      <input id="b-foto" type="file" name="foto[]" accept="image/jpeg,image/png,image/webp" multiple></div>
    <?php if ($fotos): ?>
    <div class="full"><div class="foto-grid">
      <?php foreach ($fotos as $f): ?>
        <div class="foto-item">
          <a href="uploads/penerimaan/<?= $id ?>/<?= e($f['file']) ?>" target="_blank" rel="noopener"><img src="uploads/penerimaan/<?= $id ?>/<?= e($f['file']) ?>" alt="Foto batch <?= e($r['no_ba']) ?>" loading="lazy"></a>
          <?php if (is_super()): ?><form method="post" class="inline" data-confirm="Hapus foto ini?"><?= csrf_field() ?><input type="hidden" name="act" value="hapus_foto"><input type="hidden" name="foto_id" value="<?= $f['id'] ?>"><button class="btn sm danger foto-del" title="Hapus foto">×</button></form><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>
    <div class="full actions">
      <button type="submit" name="act" value="simpan" class="btn ghost">Simpan catatan &amp; foto</button>
      <?php if (!$r['barcode_token']): ?><button type="submit" name="act" value="buat_barcode" class="btn">Buat barcode</button>
      <?php else: ?><span class="muted">Barcode sudah dibuat <?= tgl($r['barcode_dibuat_at']) ?>.</span><?php endif; ?>
    </div>
  </form>
</section>

<?php if ($r['barcode_token']): ?>
<section class="panel">
  <div class="panel-h noprint"><div><h2>Label barcode</h2><p>Tempel label ini di kemasan/box batch. Siapapun boleh scan untuk melihat detail batch ini tanpa harus masuk (login); untuk mencatat pengeluaran dari batch ini, login dulu lewat menu <a href="scan.php">Scan barcode</a>.</p></div>
    <button type="button" class="btn sm" onclick="print()">Cetak label</button>
  </div>
  <div class="panel-b label-wrap" id="label">
    <div class="label-box">
      <canvas id="qr" data-url="<?= e($qrUrl) ?>" width="160" height="160"></canvas>
      <div class="label-text">
        <b><?= e($r['nama']) ?></b>
        <span class="mono"><?= e($r['kode']) ?></span>
        <span>No. BA <?= e($r['no_ba']) ?></span>
        <span><?= num($r['jumlah']) ?> <?= strtolower($r['satuan']) ?> · diterima <?= tgl($r['tanggal']) ?></span>
        <span class="mono">Seri <?= e($r['seri_awal']) ?> – <?= e($r['seri_akhir']) ?></span>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($r['barcode_token']): ?>
<script src="assets/qrcode.min.js?v=<?= APP_VER ?>"></script>
<script>
(() => {
  const canvas = document.getElementById('qr');
  if (!canvas) return;
  if (typeof QRCode === 'undefined') { canvas.replaceWith(Object.assign(document.createElement('p'), { className: 'bad-text', textContent: 'Pustaka QR gagal dimuat. Muat ulang halaman ini.' })); return; }
  QRCode.toCanvas(canvas, canvas.dataset.url, { width: 160, margin: 1 }, err => { if (err) canvas.replaceWith(Object.assign(document.createElement('p'), { className: 'bad-text', textContent: 'QR gagal dibuat: ' + err.message })); });
})();
</script>
<?php endif; ?>

<?php include 'partials/footer.php'; ?>