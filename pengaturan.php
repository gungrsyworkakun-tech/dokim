<?php require 'config.php'; super_required(); $title = 'Pengguna';   // khusus superadmin
$me = (int)$_SESSION['u']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a  = $_POST['act'] ?? '';

  if ($a === 'ai_tes') {   // tes koneksi AI pakai isian yang sedang diketik (belum disimpan juga boleh), balas JSON langsung
    ini_set('display_errors', '0');
    $balas = function (bool $ok, string $pesan) {   // buang keluaran nyasar (warning dsb.) supaya JSON tetap bersih
      while (ob_get_level() > 0) ob_end_clean();
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => $ok, 'pesan' => $pesan], JSON_UNESCAPED_UNICODE); exit;
    };
    try {
      $key = trim((string)($_POST['openrouter_api_key'] ?? '')) ?: app_setting('openrouter_api_key', OPENROUTER_API_KEY);
      $model = trim((string)($_POST['openrouter_model'] ?? '')) ?: app_setting('openrouter_model', OPENROUTER_MODEL);
      $baseUrl = rtrim(trim((string)($_POST['openrouter_base_url'] ?? '')) ?: app_setting('openrouter_base_url', OPENROUTER_BASE_URL), '/');
      if ($key === '') $balas(false, 'API key belum diisi.');
      if (!preg_match('#^https://#i', $baseUrl)) $balas(false, 'Base URL tidak valid, harus diawali https://');
      if (!function_exists('curl_init')) $balas(false, 'Ekstensi PHP "curl" belum terpasang di server ini, jadi chat AI tidak bisa menghubungi internet. Pasang php-curl dulu.');

      $ch = curl_init($baseUrl . '/chat/completions');
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => 'ping']], 'max_tokens' => 20], JSON_UNESCAPED_UNICODE),
      ]);
      $raw = curl_exec($ch);
      $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $errCurl = curl_error($ch);
      curl_close($ch);

      if ($raw === false) $balas(false, "Server ini tidak bisa menghubungi layanan AI ($errCurl). Kemungkinan server tidak punya akses internet keluar (cek DNS/firewall Docker), atau Base URL salah.");
      $resp = json_decode($raw, true);
      if ($kode === 200 && isset($resp['choices'][0]['message'])) $balas(true, "Berhasil terhubung ke layanan AI (model: $model).");
      $err = $resp['error'] ?? null;
      $pesanError = is_array($err) ? ($err['message'] ?? json_encode($err)) : ($err ?: "balasan tidak dikenali: " . mb_substr(strip_tags((string)$raw), 0, 150));
      $balas(false, "Gagal (kode $kode): $pesanError");
    } catch (Throwable $e) {
      $balas(false, 'Terjadi error di server: ' . $e->getMessage());
    }
  }

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
  elseif ($a === 'tujuan_add') {
    $nama = trim((string)($_POST['nama'] ?? ''));
    if ($nama === '' || mb_strlen($nama) > 60) flash('Nama tujuan wajib diisi (maks. 60 karakter).', 'err');
    else {
      try { $pdo->prepare('INSERT INTO tujuan_pengeluaran(nama) VALUES(?)')->execute([$nama]); flash("Tujuan \"$nama\" ditambahkan."); }
      catch (PDOException $x) { flash("Tujuan \"$nama\" sudah ada.", 'err'); }
    }
  }
  elseif ($a === 'ai_setel') {
    $key = trim((string)($_POST['openrouter_api_key'] ?? ''));
    $model = trim((string)($_POST['openrouter_model'] ?? ''));
    $baseUrl = trim((string)($_POST['openrouter_base_url'] ?? ''));
    $sudahAda = app_setting('openrouter_api_key', OPENROUTER_API_KEY) !== '';
    if ($key === '' && !$sudahAda) flash('API key wajib diisi untuk mengaktifkan chat AI.', 'err');
    elseif (mb_strlen($key) > 200 || mb_strlen($model) > 100 || mb_strlen($baseUrl) > 200) flash('Isian terlalu panjang.', 'err');
    elseif ($baseUrl !== '' && !preg_match('#^https://#i', $baseUrl)) flash('Base URL harus diawali https://', 'err');
    else {
      try {
        if ($key !== '') app_setting_simpan('openrouter_api_key', $key);   // kosong = key lama tetap dipakai
        app_setting_simpan('openrouter_model', $model !== '' ? $model : OPENROUTER_MODEL);
        app_setting_simpan('openrouter_base_url', $baseUrl !== '' ? rtrim($baseUrl, '/') : OPENROUTER_BASE_URL);
        flash('Pengaturan chat AI disimpan. Muat ulang halaman mana pun untuk melihat tombol chat-nya di pojok kanan bawah.');
      } catch (Throwable $e) { flash('Gagal menyimpan: tabel pengaturan belum ada. Jalankan migrasi_setelan.sql lewat phpMyAdmin dulu.', 'err'); }
    }
  }
  elseif ($a === 'ai_hapus') {
    try { app_setting_simpan('openrouter_api_key', ''); flash('Chat AI dinonaktifkan.'); }
    catch (Throwable $e) { flash('Gagal menyimpan: tabel pengaturan belum ada. Jalankan migrasi_setelan.sql lewat phpMyAdmin dulu.', 'err'); }
  }
  elseif ($a === 'tujuan_del') {
    $s = $pdo->prepare('SELECT nama FROM tujuan_pengeluaran WHERE id=?'); $s->execute([$id]); $nama = $s->fetchColumn();
    if (!$nama) flash('Tujuan tidak ditemukan.', 'err');
    else {
      $s = $pdo->prepare('SELECT COUNT(*) FROM pengeluaran WHERE tujuan=?'); $s->execute([$nama]);
      if ((int)$s->fetchColumn() > 0) flash("Tujuan \"$nama\" sudah dipakai di data pengeluaran sehingga tidak bisa dihapus.", 'err');
      else { $pdo->prepare('DELETE FROM tujuan_pengeluaran WHERE id=?')->execute([$id]); flash("Tujuan \"$nama\" dihapus."); }
    }
  }
  back('pengaturan.php');
}

$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM penerimaan WHERE user_id=u.id) + (SELECT COUNT(*) FROM pengeluaran WHERE user_id=u.id) trx
  FROM users u ORDER BY u.role DESC, u.nama")->fetchAll();

try {
  $tujuanList = $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM pengeluaran WHERE tujuan=t.nama) trx FROM tujuan_pengeluaran t ORDER BY t.nama")->fetchAll();
  $adaTabelTujuan = true;
} catch (Throwable $e) { $tujuanList = []; $adaTabelTujuan = false; }
$bawaan = 0;
foreach ($users as &$u) {   // tandai akun yang masih memakai password contoh dari database.sql
  $u['bawaan'] = password_verify('Super123!', $u['password']) || password_verify('Admin123!', $u['password']);
  if ($u['bawaan']) $bawaan++;
} unset($u);
$aiKeyNilai = app_setting('openrouter_api_key', OPENROUTER_API_KEY);
$aiModelNilai = app_setting('openrouter_model', OPENROUTER_MODEL);
$aiBaseUrlNilai = app_setting('openrouter_base_url', OPENROUTER_BASE_URL);
$aiAktif = $aiKeyNilai !== '';
$aiKeyMasked = $aiAktif ? substr($aiKeyNilai, 0, 7) . str_repeat('•', 10) . substr($aiKeyNilai, -4) : '';

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

<section class="panel">
  <div class="panel-h"><div><h2>Tujuan pengeluaran</h2>
    <p>Pilihan "Tujuan" yang muncul di formulir Pengeluaran. Tambah sendiri sesuai kebutuhan kantor.</p></div></div>
  <?php if (!$adaTabelTujuan): ?>
    <div class="empty"><b>Fitur ini belum aktif</b>Jalankan <span class="mono">migrasi_tujuan_pengeluaran.sql</span> lewat phpMyAdmin dulu, lalu muat ulang halaman ini.</div>
  <?php else: ?>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="act" value="tujuan_add">
    <div class="grow"><label for="tj-nama">Tujuan baru</label><input id="tj-nama" name="nama" maxlength="60" placeholder="mis. ULP, cs, atau nama lain" required></div>
    <div class="tb-act"><button type="submit" class="btn sm">Tambah</button></div>
  </form>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Tujuan</th><th class="num">Dipakai di</th><th></th></tr></thead>
    <tbody>
    <?php if (!$tujuanList): ?>
      <tr class="empty-row"><td colspan="3"><div class="empty"><b>Belum ada tujuan</b>Tambahkan lewat formulir di atas.</div></td></tr>
    <?php endif; ?>
    <?php foreach ($tujuanList as $t): ?>
      <tr>
        <td><b><?= e($t['nama']) ?></b></td>
        <td class="num"><?= num($t['trx']) ?> transaksi</td>
        <td class="r"><?php if (!$t['trx']): ?><form method="post" class="inline" data-confirm="Hapus tujuan <?= e($t['nama']) ?>?"><?= csrf_field() ?><input type="hidden" name="act" value="tujuan_del"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn sm danger">Hapus</button></form><?php else: ?><span class="muted">sedang dipakai</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-h"><div><h2>Chat AI</h2>
    <p>Widget tanya-jawab di pojok kanan bawah (muncul di semua halaman setelah login). Bisa pakai <b>Groq</b> (ada tier gratis, API key dari <a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com</a>) atau <b>OpenRouter</b> (<a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai</a>, berbayar per pertanyaan). Pilih penyedianya, tempel API key, lalu klik Tes koneksi.</p></div>
    <span class="tag<?= $aiAktif ? '' : ' out' ?>"><?= $aiAktif ? 'Aktif' : 'Belum aktif' ?></span>
  </div>
  <div class="panel-b">
    <?php if ($aiAktif): ?><p class="hint" style="margin-bottom:14px">API key saat ini: <span class="mono"><?= e($aiKeyMasked) ?></span> &middot; Model: <span class="mono"><?= e($aiModelNilai) ?></span> &middot; Base URL: <span class="mono"><?= e($aiBaseUrlNilai) ?></span></p><?php endif; ?>
    <form method="post" class="grid-form" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="act" value="ai_setel">
      <div class="span2"><label for="ai-penyedia">Penyedia AI</label>
        <select id="ai-penyedia">
          <option value="groq">Groq (ada tier gratis)</option>
          <option value="openrouter">OpenRouter</option>
          <option value="lain">Lainnya (isi Base URL sendiri)</option>
        </select>
        <div class="hint">Memilih penyedia otomatis mengisi Base URL dan Model di bawah. API key harus sesuai penyedianya — key Groq tidak bisa dipakai di OpenRouter, dan sebaliknya.</div></div>
      <div class="span2"><label for="ai-key">API key <span class="req">*</span></label>
        <input id="ai-key" name="openrouter_api_key" maxlength="200" class="mono" placeholder="sk-or-v1-…" autocomplete="off">
        <div class="hint"><?= $aiAktif ? 'Kosongkan kalau tidak mau mengganti key yang sudah tersimpan.' : 'Belum diisi — chat AI masih tersembunyi dari semua pengguna.' ?></div></div>
      <div><label for="ai-baseurl">Base URL</label>
        <input id="ai-baseurl" name="openrouter_base_url" maxlength="200" class="mono" value="<?= e($aiBaseUrlNilai) ?>" placeholder="<?= e(OPENROUTER_BASE_URL) ?>">
        <div class="hint">Alamat API-nya. Biarkan bawaan kecuali mau ganti ke proxy/provider lain yang kompatibel (format sama seperti OpenAI).</div></div>
      <div><label for="ai-model">Model</label>
        <input id="ai-model" name="openrouter_model" maxlength="100" class="mono" value="<?= e($aiModelNilai) ?>" placeholder="<?= e(OPENROUTER_MODEL) ?>">
        <div class="hint">Daftar model: console.groq.com/docs/models (Groq) atau openrouter.ai/models. Kalau muncul "model tidak ada / tidak punya akses", ganti ke model lain dari daftar itu.</div></div>
      <div class="full" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <button type="submit" class="btn sm">Simpan</button>
        <button type="button" id="ai-tes-btn" class="btn sm ghost">Tes koneksi</button>
        <span id="ai-tes-hasil" style="font-size:.92em"></span>
      </div>
    </form>
    <?php if ($aiAktif): ?>
      <form method="post" class="inline" style="margin-top:10px" data-confirm="Nonaktifkan chat AI? Tombol chat akan hilang dari semua halaman sampai API key diisi lagi.">
        <?= csrf_field() ?><input type="hidden" name="act" value="ai_hapus">
        <button type="submit" class="btn sm danger">Nonaktifkan chat AI</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  var btn = document.getElementById('ai-tes-btn');
  if (!btn) return;
  var hasil = document.getElementById('ai-tes-hasil');
  var form = btn.closest('form');

  // Pilihan penyedia: cuma membantu mengisi Base URL & Model, tidak disimpan sendiri.
  var PRESET = {
    groq: { url: 'https://api.groq.com/openai/v1', model: 'openai/gpt-oss-20b' },
    openrouter: { url: 'https://openrouter.ai/api/v1', model: 'google/gemini-2.0-flash-001' }
  };
  var pilih = document.getElementById('ai-penyedia');
  var inUrl = document.getElementById('ai-baseurl'), inModel = document.getElementById('ai-model');
  var urlSekarang = inUrl.value.replace(/\/+$/, '');
  pilih.value = urlSekarang.indexOf('groq.com') > -1 ? 'groq' : urlSekarang.indexOf('openrouter.ai') > -1 ? 'openrouter' : 'lain';
  pilih.addEventListener('change', function () {
    var p = PRESET[pilih.value];
    if (!p) return;
    inUrl.value = p.url; inModel.value = p.model;
    hasil.textContent = 'Base URL dan Model sudah diisi otomatis. Tempel API key-nya lalu klik Tes koneksi.';
    hasil.style.color = '';
  });
  btn.addEventListener('click', function () {
    var labelAsli = btn.textContent;
    btn.disabled = true; btn.textContent = 'Menguji…';
    hasil.textContent = ''; hasil.style.color = '';
    var data = new URLSearchParams({
      act: 'ai_tes',
      csrf: form.querySelector('[name="csrf"]').value,
      openrouter_api_key: form.querySelector('#ai-key').value,
      openrouter_base_url: form.querySelector('#ai-baseurl').value,
      openrouter_model: form.querySelector('#ai-model').value
    });
    fetch(location.href, { method: 'POST', body: data })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); }
          catch (e) {
            var cuplikan = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
            return { ok: false, pesan: 'Server aplikasi membalas bukan hasil tes (kode ' + r.status + '): ' + (cuplikan || '(kosong)') };
          }
        });
      })
      .then(function (j) {
        hasil.textContent = j.pesan;
        hasil.style.color = j.ok ? '#1a7f37' : '#b42318';
      })
      .catch(function (e) {
        hasil.textContent = 'Permintaan tes terputus sebelum ada balasan (' + e.message + '). Biasanya karena server menunggu terlalu lama.';
        hasil.style.color = '#b42318';
      })
      .finally(function () { btn.disabled = false; btn.textContent = labelAsli; });
  });
})();
</script>

<?php include 'partials/footer.php'; ?>