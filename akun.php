<?php require 'config.php'; login_required(); $title = 'Akun saya';
$uid = (int)$_SESSION['u']['id'];

const FRASE_RESET = 'HAPUS SEMUA DATA';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['act'] ?? 'ganti_password';

  if ($act === 'reset_total') {
    super_required();
    if ((string)($_POST['konfirmasi'] ?? '') !== FRASE_RESET) {
      flash('Ketik persis "' . FRASE_RESET . '" di kolom konfirmasi untuk menghapus data.', 'err');
    } else {
      $adaKartu = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kartu_stok_harian'")->fetchColumn();
      $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
      if ($adaKartu) $pdo->exec('TRUNCATE TABLE kartu_stok_harian');
      $pdo->exec('TRUNCATE TABLE pengeluaran');
      $pdo->exec('TRUNCATE TABLE penerimaan');
      $pdo->exec('TRUNCATE TABLE dokumen');
      $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
      flash('Semua data jenis dokumen, stok, penerimaan, dan pengeluaran sudah dikosongkan. Akun pengguna tidak terpengaruh. Silakan tambahkan jenis dokumen dan data yang asli lewat menu Stok dokumen.');
    }
    back('akun.php');
  }

  $lama  = (string)($_POST['lama'] ?? '');
  $baru  = (string)($_POST['baru'] ?? '');
  $ulang = (string)($_POST['ulang'] ?? '');
  $s = $pdo->prepare('SELECT password FROM users WHERE id=?'); $s->execute([$uid]); $hash = $s->fetchColumn();
  if (!$hash || !password_verify($lama, $hash))      flash('Password saat ini salah.', 'err');
  elseif (strlen($baru) < 8 || strlen($baru) > 200)  flash('Password baru minimal 8 karakter.', 'err');
  elseif ($baru !== $ulang)                          flash('Ulangi password baru dengan isi yang sama.', 'err');
  elseif ($baru === $lama)                           flash('Password baru harus berbeda dari password saat ini.', 'err');
  else {
    $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($baru, PASSWORD_DEFAULT), $uid]);
    session_regenerate_id(true);
    flash('Password berhasil diganti. Gunakan password baru saat masuk berikutnya.');
  }
  back('akun.php');
}

$s = $pdo->prepare('SELECT nama, username, role, created_at, password FROM users WHERE id=?'); $s->execute([$uid]); $u = $s->fetch();
$bawaan = $u && (password_verify('Super123!', $u['password']) || password_verify('Admin123!', $u['password']));
$s = $pdo->prepare('SELECT (SELECT COUNT(*) FROM penerimaan WHERE user_id=?) a, (SELECT COUNT(*) FROM pengeluaran WHERE user_id=?) b'); $s->execute([$uid, $uid]); $trx = $s->fetch();
include 'partials/header.php'; ?>

<?php if ($bawaan): ?><div class="notice"><span>Akun Anda masih memakai password contoh dari file instalasi. Ganti sekarang lewat formulir di bawah.</span></div><?php endif; ?>

<div class="cols">
  <section class="panel">
    <div class="panel-h"><div><h2>Ganti password</h2><p>Gunakan minimal 8 karakter. Gabungan kata yang mudah diingat lebih aman daripada kata pendek yang rumit.</p></div></div>
    <form method="post" class="panel-b grid-form" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="act" value="ganti_password">
      <div class="full"><label for="pw0">Password saat ini</label><input id="pw0" class="pw" type="password" name="lama" autocomplete="current-password" required></div>
      <div class="span2"><label for="pw1">Password baru</label><input id="pw1" class="pw" type="password" name="baru" minlength="8" maxlength="200" autocomplete="new-password" required></div>
      <div class="span2"><label for="pw2">Ulangi password baru</label><input id="pw2" class="pw" type="password" name="ulang" minlength="8" maxlength="200" autocomplete="new-password" required></div>
      <div class="full actions"><button type="submit" class="btn">Simpan password baru</button><label class="check"><input type="checkbox" data-showpw> Tampilkan password</label></div>
    </form>
  </section>

  <section class="panel">
    <div class="panel-h"><h2>Profil</h2></div>
    <dl class="calc panel-b">
      <div><dt>Nama</dt><dd><?= e($u['nama']) ?></dd></div>
      <div><dt>Username</dt><dd><?= e($u['username']) ?></dd></div>
      <div><dt>Role</dt><dd><?= $u['role'] === 'superadmin' ? 'Superadmin' : 'Admin' ?></dd></div>
      <div><dt>Terdaftar</dt><dd><?= tgl($u['created_at']) ?></dd></div>
      <div><dt>Penerimaan dicatat</dt><dd><?= num($trx['a']) ?></dd></div>
      <div><dt>Pengeluaran dicatat</dt><dd><?= num($trx['b']) ?></dd></div>
    </dl>
    <p class="panel-f muted">Perubahan nama atau role dilakukan oleh superadmin di menu Pengguna.</p>
  </section>
</div>

<?php if (is_super()): ?>
<section class="panel danger-zone">
  <div class="panel-h"><div><h2>Zona berbahaya</h2><p>Mengosongkan seluruh data jenis dokumen, stok, penerimaan, dan pengeluaran, supaya Anda bisa mulai menambahkan data yang asli dari nol. Akun pengguna (login) tidak ikut terhapus. <b>Tindakan ini tidak bisa dibatalkan.</b></p></div></div>
  <form method="post" class="panel-b grid-form" data-confirm="Hapus SEMUA data jenis dokumen, stok, penerimaan, dan pengeluaran? Tindakan ini tidak bisa dibatalkan.">
    <?= csrf_field() ?><input type="hidden" name="act" value="reset_total">
    <div class="full"><label for="konf-reset">Ketik <code><?= e(FRASE_RESET) ?></code> untuk mengonfirmasi</label><input id="konf-reset" name="konfirmasi" autocomplete="off" placeholder="<?= e(FRASE_RESET) ?>"></div>
    <div class="full actions"><button type="submit" class="btn danger" data-match-btn="#konf-reset" data-match="<?= e(FRASE_RESET) ?>" disabled>Hapus semua data sekarang</button></div>
  </form>
</section>
<?php endif; ?>
<?php include 'partials/footer.php'; ?>