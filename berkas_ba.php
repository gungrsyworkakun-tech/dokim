<?php require 'config.php'; login_required(); $title = 'Berkas BA/BAST';

const BA_TIPE_VALID = [
  'penerimaan'  => ['label' => 'Penerimaan', 'balik' => 'penerimaan.php'],
  'pengeluaran' => ['label' => 'Pengeluaran', 'balik' => 'pengeluaran.php'],
];

$tipe = $_GET['tipe'] ?? '';
if (!isset(BA_TIPE_VALID[$tipe])) { flash('Jenis transaksi tidak dikenali.', 'err'); back('index.php'); }
$tabel = $tipe; // nama tabel sama dengan nama tipe
$info  = BA_TIPE_VALID[$tipe];
$id    = (int)($_GET['id'] ?? 0);

$s = $pdo->prepare("SELECT p.*, d.kode, d.nama, d.satuan FROM $tabel p JOIN dokumen d ON d.id=p.dokumen_id WHERE p.id=?");
$s->execute([$id]); $r = $s->fetch();
if (!$r) { flash('Data tidak ditemukan.', 'err'); back($info['balik']); }

const BA_MB_MAX = 10;                 // maks ukuran berkas (MB)
const BA_TIPE_MIME = [
  'application/pdf' => 'pdf',
  'image/jpeg'       => 'jpg',
  'image/png'        => 'png',
  'image/webp'       => 'webp',
];
$dirUpload = __DIR__ . "/uploads/berita_acara/$tipe";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['act'] ?? 'unggah';

  if ($act === 'hapus') {
    super_required();
    if ($r['file_ba']) @unlink($dirUpload . '/' . $r['file_ba']);
    $pdo->prepare("UPDATE $tabel SET file_ba=NULL WHERE id=?")->execute([$id]);
    flash('Berkas BA dihapus.');
    back("berkas_ba.php?tipe=$tipe&id=$id");
  }

  // act === 'unggah'
  if (empty($_FILES['berkas']['name'])) { flash('Pilih berkas terlebih dahulu.', 'err'); back("berkas_ba.php?tipe=$tipe&id=$id"); }
  if ($_FILES['berkas']['error'] !== UPLOAD_ERR_OK) { flash('Gagal mengunggah berkas.', 'err'); back("berkas_ba.php?tipe=$tipe&id=$id"); }
  if ($_FILES['berkas']['size'] > BA_MB_MAX * 1024 * 1024) { flash('Ukuran berkas maks. ' . BA_MB_MAX . ' MB.', 'err'); back("berkas_ba.php?tipe=$tipe&id=$id"); }

  $mime = @mime_content_type($_FILES['berkas']['tmp_name']);
  if (!isset(BA_TIPE_MIME[$mime])) { flash('Berkas harus berformat PDF, JPG, PNG, atau WEBP.', 'err'); back("berkas_ba.php?tipe=$tipe&id=$id"); }

  if (!is_dir($dirUpload)) mkdir($dirUpload, 0755, true);
  $lama = $r['file_ba'];
  $nama = bin2hex(random_bytes(8)) . '.' . BA_TIPE_MIME[$mime];
  if (move_uploaded_file($_FILES['berkas']['tmp_name'], "$dirUpload/$nama")) {
    $pdo->prepare("UPDATE $tabel SET file_ba=? WHERE id=?")->execute([$nama, $id]);
    if ($lama) @unlink("$dirUpload/$lama");
    flash($lama ? 'Berkas BA diganti.' : 'Berkas BA berhasil diunggah.');
  } else {
    flash('Gagal menyimpan berkas di server.', 'err');
  }
  back("berkas_ba.php?tipe=$tipe&id=$id");
}

include 'partials/header.php'; ?>

<section class="panel">
  <div class="panel-h"><div><h2>Berkas BA/BAST — <?= e($r['no_ba']) ?></h2>
    <p><?= e($r['nama']) ?> (<?= e($r['kode']) ?>) · <?= tgl($r['tanggal']) ?> · <?= num($r['jumlah']) ?> <?= strtolower($r['satuan'] ?? '') ?> · <?= e($info['label']) ?></p></div>
    <a class="btn sm ghost" href="<?= e($info['balik']) ?>">Kembali</a>
  </div>
  <div class="panel-b">
    <?php if ($r['file_ba']): ?>
      <p>
        <a class="btn sm" href="uploads/berita_acara/<?= e($tipe) ?>/<?= e($r['file_ba']) ?>" target="_blank" rel="noopener">Buka berkas</a>
        <?php if (is_super()): ?>
          <form method="post" class="inline" data-confirm="Hapus berkas ini?" style="display:inline-block;margin-left:8px">
            <?= csrf_field() ?><input type="hidden" name="act" value="hapus">
            <button type="submit" class="btn sm danger">Hapus berkas</button>
          </form>
        <?php endif; ?>
      </p>
      <p class="muted">Unggah berkas baru di bawah untuk menggantikan berkas ini.</p>
    <?php else: ?>
      <p class="muted">Belum ada berkas untuk No. BA ini.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="grid-form" style="margin-top:16px">
      <?= csrf_field() ?><input type="hidden" name="act" value="unggah">
      <div class="full">
        <label for="berkas">Pilih berkas (PDF, JPG, PNG, atau WEBP, maks. <?= BA_MB_MAX ?> MB)</label>
        <input id="berkas" type="file" name="berkas" accept="application/pdf,image/jpeg,image/png,image/webp" required>
      </div>
      <div class="full"><button type="submit" class="btn"><?= $r['file_ba'] ? 'Ganti berkas' : 'Unggah berkas' ?></button></div>
    </form>
  </div>
</section>

<?php include 'partials/footer.php'; ?>
