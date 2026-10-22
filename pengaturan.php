<?php require 'config.php'; super_required(); $title = 'Pengguna';   // khusus superadmin
$me = (int)$_SESSION['u']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a  = $_POST['act'] ?? '';
  $id = (int)($_POST['id'] ?? 0);
  $superLain = function ($id) use ($pdo) {   // jumlah superadmin selain $id
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='superadmin' AND id<>?"); $s->execute([$id]); return (int)$s->fetchColumn();
  };
  $roleOf = function ($id) use ($pdo) { $s = $pdo->prepare('SELECT role FROM users WHERE id=?'); $s->execute([$id]); return $s->fetchColumn(); };

  if ($a === 'add') {
    $nama = trim((string)($_POST['nama'] ?? ''));
    $user = strtolower(trim((string)($_POST['username'] ?? '')));   // login selalu memakai huruf kecil
    $pass = (string)($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? '') === 'superadmin' ? 'superadmin' : 'admin';
    if ($nama === '' || mb_strlen($nama) > 80) flash('Nama wajib diisi (maks. 80 karakter).', 'err');
    elseif (!preg_match('/^[a-z0-9._-]{3,40}$/', $user)) flash('Username 3–40 karakter, hanya huruf kecil, angka, titik, minus, atau garis bawah.', 'err');
    elseif (strlen($pass) < 8 || strlen($pass) > 200) flash('Password minimal 8 karakter.', 'err');
    else {
      try { $pdo->prepare('INSERT INTO users(nama,username,password,role) VALUES(?,?,?,?)')->execute([$nama, $user, password_hash($pass, PASSWORD_DEFAULT), $role]); flash("Pengguna $user ditambahkan."); }
      catch (PDOException $x) { flash("Username $user sudah dipakai.", 'err'); }
    }
  }
  elseif ($a === 'pass') {
    $pass = (string)($_POST['password'] ?? '');
    if (strlen($pass) < 8 || strlen($pass) > 200) flash('Password baru minimal 8 karakter.', 'err');
    else { $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]); flash('Password direset. Sampaikan password baru ke pengguna secara langsung.'); }
  }
  elseif ($a === 'role') {
    $role = $roleOf($id);
    if ($id === $me) flash('Anda tidak dapat mengubah role akun sendiri.', 'err');
    elseif (!$role) flash('Pengguna tidak ditemukan.', 'err');
    elseif ($role === 'superadmin' && !$superLain($id)) flash('Harus ada minimal satu superadmin.', 'err');
    else {
      $baru = $role === 'superadmin' ? 'admin' : 'superadmin';
      $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$baru, $id]);
      flash('Role diubah menjadi ' . ($baru === 'superadmin' ? 'Superadmin' : 'Admin') . '. Berlaku saat pengguna masuk berikutnya.');
    }
  }
  elseif ($a === 'del') {
    $role = $roleOf($id);
    if ($id === $me) flash('Anda tidak dapat menghapus akun sendiri.', 'err');
    elseif ($role === 'superadmin' && !$superLain($id)) flash('Harus ada minimal satu superadmin.', 'err');
    else {
      try { $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]); flash('Pengguna dihapus.'); }
      catch (PDOException $x) { flash('Pengguna sudah punya transaksi sehingga tidak bisa dihapus. Reset passwordnya bila akun tidak dipakai lagi.', 'err'); }
    }
  }
  back('pengaturan.php');
}

$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM penerimaan WHERE user_id=u.id) + (SELECT COUNT(*) FROM pengeluaran WHERE user_id=u.id) trx
  FROM users u ORDER BY u.role DESC, u.nama")->fetchAll();
$bawaan = 0;
foreach ($users as &$u) {   // tandai akun yang masih memakai password contoh dari database.sql
  $u['bawaan'] = password_verify('Super123!', $u['password']) || password_verify('Admin123!', $u['password']);
  if ($u['bawaan']) $bawaan++;
} unset($u);
include 'partials/header.php'; ?>

<?php if ($bawaan): ?>
<div class="notice"><span><b><?= $bawaan ?> akun</b> masih memakai password contoh dari file instalasi. Reset passwordnya di tabel di bawah.</span></div>
<?php endif; ?>

<details class="drawer"<?= count($users) < 2 ? ' open' : '' ?>>
  <summary>Tambah pengguna</summary>
  <form method="post" class="grid-form panel-b" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="act" value="add">
    <div><label for="u-nama">Nama lengkap <span class="req">*</span></label><input id="u-nama" name="nama" maxlength="80" required></div>
    <div><label for="u-user">Username <span class="req">*</span></label><input id="u-user" name="username" maxlength="40" pattern="[a-zA-Z0-9._\-]{3,40}" autocapitalize="none" spellcheck="false" required>
      <div class="hint">Disimpan dalam huruf kecil.</div></div>
    <div><label for="u-role">Role</label><select id="u-role" name="role"><option value="admin">Admin: catat transaksi</option><option value="superadmin">Superadmin: akses penuh</option></select></div>
    <div><label for="u-pass">Password awal <span class="req">*</span></label><input id="u-pass" class="pw" type="password" name="password" minlength="8" maxlength="200" autocomplete="new-password" required>
      <div class="hint">Minimal 8 karakter. <label class="check"><input type="checkbox" data-showpw> tampilkan</label></div></div>
    <div class="full"><button type="submit" class="btn">Tambah pengguna</button></div>
  </form>
</details>

<section class="panel">
  <div class="panel-h"><div><h2>Daftar pengguna</h2>
    <p>Admin bisa mencatat penerimaan dan pengeluaran. Superadmin juga bisa memverifikasi, menghapus transaksi, mengelola jenis dokumen, dan mengelola pengguna.</p></div></div>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Pengguna</th><th>Role</th><th class="num">Transaksi</th><th>Terdaftar</th><th>Reset password</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $self = (int)$u['id'] === $me; ?>
      <tr>
        <td><b><?= e($u['nama']) ?></b><?= $self ? ' <span class="muted">(Anda)</span>' : '' ?><div class="sub mono"><?= e($u['username']) ?></div>
          <?php if ($u['bawaan']): ?><span class="tag warn">password contoh</span><?php endif; ?></td>
        <td><span class="tag<?= $u['role'] === 'admin' ? ' out' : '' ?>"><?= $u['role'] === 'superadmin' ? 'Superadmin' : 'Admin' ?></span></td>
        <td class="num"><?= num($u['trx']) ?></td>
        <td class="nowrap"><?= tgl($u['created_at']) ?></td>
        <td><form method="post" class="pw-form"><?= csrf_field() ?><input type="hidden" name="act" value="pass"><input type="hidden" name="id" value="<?= $u['id'] ?>">
          <input type="password" name="password" minlength="8" maxlength="200" placeholder="Password baru" autocomplete="new-password" required aria-label="Password baru untuk <?= e($u['username']) ?>"><button class="btn sm ghost">Reset</button></form></td>
        <td><div class="row-act">
          <?php if (!$self): ?>
            <form method="post" class="inline" data-confirm="Ubah <?= e($u['nama']) ?> menjadi <?= $u['role'] === 'superadmin' ? 'Admin' : 'Superadmin' ?>?"><?= csrf_field() ?><input type="hidden" name="act" value="role"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn sm ghost">Jadikan <?= $u['role'] === 'superadmin' ? 'admin' : 'superadmin' ?></button></form>
            <?php if (!$u['trx']): ?><form method="post" class="inline" data-confirm="Hapus pengguna <?= e($u['nama']) ?>?"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn sm danger">Hapus</button></form><?php endif; ?>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php include 'partials/footer.php'; ?>
