<?php require_once 'config.php';
if (!empty($_SESSION['u'])) back('index.php');

/* ---------- Keamanan: header + CSP (nonce) ---------- */
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; style-src 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'nonce-$nonce'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

/* ---------- Rate limit (anti brute-force) ---------- */
const MAX_USER = 5;     // gagal per IP+username
const MAX_IP   = 15;    // gagal per IP
const WINDOW   = 900;   // 15 menit
if (!function_exists('login_attempts_table_ready')) {
  function login_attempts_table_ready(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts(id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, username VARCHAR(40) NOT NULL, waktu DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(ip,waktu), INDEX(waktu))");
    // bersihkan baris lama hanya sesekali (±1 dari 50 request), bukan tiap request, biar login tidak lemot
    if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM login_attempts WHERE waktu < NOW() - INTERVAL 1 DAY");
    $ready = true;
  }
}
login_attempts_table_ready($pdo);
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!function_exists('lock_wait')) {
function lock_wait(PDO $pdo, string $ip, string $user): array {   // [detik tunggu, sisa percobaan] — 1 query gabungan (sebelumnya 2 query terpisah)
  $s = $pdo->prepare(
    "SELECT COUNT(*) cnt_ip, TIMESTAMPDIFF(SECOND, MIN(waktu), NOW()) age_ip,
            SUM(username = ?) cnt_user, TIMESTAMPDIFF(SECOND, MIN(CASE WHEN username = ? THEN waktu END), NOW()) age_user
     FROM login_attempts WHERE ip = ? AND waktu > NOW() - INTERVAL " . WINDOW . " SECOND"
  );
  $s->execute([$user, $user, $ip]);
  $r = $s->fetch();
  $cntUser = (int)($r['cnt_user'] ?? 0);
  $left = max(0, MAX_USER - $cntUser);
  $wait = 0;
  if ($cntUser >= MAX_USER) $wait = max($wait, WINDOW - (int)$r['age_user']);
  if ((int)$r['cnt_ip'] >= MAX_IP) $wait = max($wait, WINDOW - (int)$r['age_ip']);
  return [$wait, $left];
}
}

$err = ''; $wait = 0;
if (isset($_GET['exp'])) $err = 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = strtolower(substr(trim((string)($_POST['username'] ?? '')), 0, 40));
  $pass = substr((string)($_POST['password'] ?? ''), 0, 200);
  [$wait] = lock_wait($pdo, $ip, $user);
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $err = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
  } elseif ($wait > 0) {
    $err = '';   // ditampilkan sebagai kotak terkunci
  } else {
    $s = $pdo->prepare('SELECT * FROM users WHERE username=?'); $s->execute([$user]); $u = $s->fetch();
    $dummy = '$2y$10$iEG2ivPxYN6khz5rmrrm8OhRqkJPe5te7N/Gc.fu6VsIqrTRElZci';   // samakan waktu proses bila user tidak ada
    $ok = password_verify($pass, $u['password'] ?? $dummy) && $u;
    if ($ok) {
      if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
      $pdo->prepare('DELETE FROM login_attempts WHERE ip=? AND username=?')->execute([$ip, $user]);
      session_regenerate_id(true);
      $_SESSION = ['u' => ['id'=>(int)$u['id'], 'nama'=>$u['nama'], 'username'=>$u['username'], 'role'=>$u['role']],
                   'ua' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 'last' => time()];
      back('index.php');
    }
    $pdo->prepare('INSERT INTO login_attempts(ip,username) VALUES(?,?)')->execute([$ip, $user]);
    usleep(random_int(250000, 500000));
    [$wait, $left] = lock_wait($pdo, $ip, $user);
    $err = $wait > 0 ? '' : "Username atau password salah. Sisa percobaan: $left.";
  }
}
unset($_SESSION['csrf']); $csrf = csrf();   // token baru setiap tampil
?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Masuk · SIM-DOKIM</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style nonce="<?= $nonce ?>">
:root{--bg:#f0f2f5;--ink:#1a1d23;--ink-2:#3d4452;--ink-3:#6b7280;--line:#d1d5db;--navy:#1e3a5f;--navy-2:#2a5490;--gold:#c4922e}
*{box-sizing:border-box;margin:0}
body{font:14px/1.6 "IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg);min-height:100vh;display:grid;grid-template-columns:minmax(0,5fr) minmax(0,6fr)}
.info{position:relative;overflow:hidden;background:linear-gradient(160deg,#152d4d 0%,var(--navy) 40%,#1a3f6e 100%);color:#dfe5ee;padding:48px 56px 40px;display:flex;flex-direction:column;justify-content:space-between;gap:48px}
.info::before{content:"";position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cdefs%3E%3Cpattern id='g' patternUnits='userSpaceOnUse' width='60' height='60'%3E%3Cpath d='M0 60L60 0' stroke='%23ffffff' stroke-width='.3' opacity='.04'/%3E%3C/pattern%3E%3C/defs%3E%3Crect fill='url(%23g)' width='100%25' height='100%25'/%3E%3C/svg%3E");pointer-events:none}
.info>*{position:relative}
.brand{display:flex;align-items:center;gap:13px}
.seal{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.15);backdrop-filter:blur(8px);color:#fff;display:grid;place-items:center;font:600 14px "IBM Plex Mono",monospace;border:1px solid rgba(255,255,255,.2);box-shadow:inset 0 -2px 0 var(--gold)}
.brand b{display:block;font-size:16px;letter-spacing:.4px;color:#fff}
.brand small{display:block;color:rgba(255,255,255,.55);font-size:12px}
.lead h2{font-size:32px;line-height:1.2;font-weight:600;color:#fff;max-width:16ch;letter-spacing:-.01em}
.lead p{margin-top:18px;color:#b8c3d5;max-width:42ch;font-size:15px;line-height:1.7}
.org{font-size:12px;color:rgba(255,255,255,.35)}
.form-side{display:grid;place-items:center;padding:48px 32px;background:#fff}
form{width:100%;max-width:380px}
h1{font-size:26px;font-weight:600;letter-spacing:-.01em}
.sub{color:var(--ink-3);margin:6px 0 32px;font-size:14.5px}
.fld{margin-bottom:18px}
label{display:block;font-size:13px;font-weight:500;color:var(--ink-2);margin-bottom:6px}
.wrap{position:relative}
input{width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:10px;background:#fff;font:inherit;font-size:15px;color:var(--ink);transition:border-color .15s,box-shadow .15s}
input:focus{outline:none;border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(42,84,144,.12)}
#p{padding-right:90px}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;padding:7px 12px;font:inherit;font-size:13px;color:var(--navy-2);cursor:pointer;border-radius:6px;transition:background .15s}
.eye:hover{background:#eef3fb}
.cw{font-size:13px;color:#c07d18;margin-top:6px}.cw[hidden]{display:none}
.go{width:100%;margin-top:10px;padding:12px;border:0;border-radius:10px;background:var(--navy);color:#fff;font:inherit;font-weight:500;font-size:15px;cursor:pointer;transition:all .18s;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.go:hover{background:var(--navy-2);box-shadow:0 4px 12px rgba(30,58,95,.25);transform:translateY(-1px)}.go:active{transform:translateY(0)}
.go:disabled{opacity:.5;cursor:not-allowed;transform:none}
.go:focus-visible,.eye:focus-visible{outline:2px solid var(--navy-2);outline-offset:2px}
.err,.lock{padding:12px 14px;border-radius:10px;font-size:13.5px;margin-bottom:20px;border:1px solid}
.err{background:#fde8e7;border-color:#f9d0ce;color:#8a2017}
.lock{background:#eef3fb;border-color:#c6d2e4;color:var(--navy)}.lock b{font-family:"IBM Plex Mono",monospace}
.note{margin-top:28px;padding-top:18px;border-top:1px solid #e2e5ea;font-size:13px;color:var(--ink-3);line-height:1.6}
@media(max-width:820px){
  body{grid-template-columns:1fr;grid-template-rows:auto 1fr}
  .info{padding:24px 28px;gap:0}
  .info::before,.lead,.org{display:none}
  .form-side{place-items:start center;padding:36px 24px}
}
</style></head>
<body>
<section class="info">
  <div class="brand"><span class="seal">SD</span><span><b>SIM-DOKIM</b><small>Sistem Informasi Manajemen Dokumen Keimigrasian</small></span></div>
  <div class="lead">
    <h2>Buku stok blanko dokumen keimigrasian</h2>
    <p>Penerimaan, pengeluaran, nomor seri, dan opname fisik paspor, stiker izin tinggal, VOA, dan dokumen lainnya tercatat di satu tempat.</p>
  </div>
  <div class="org">Direktorat Jenderal Imigrasi</div>
</section>

<main class="form-side">
<form method="post" id="card" autocomplete="on" novalidate>
  <h1>Masuk</h1>
  <p class="sub">Gunakan akun yang diberikan oleh superadmin kantor Anda.</p>

  <?php if ($wait > 0): ?><div class="lock" id="lk" role="alert" data-wait="<?= (int)$wait ?>">Terlalu banyak percobaan gagal. Coba lagi dalam <b id="cd">--:--</b>.</div>
  <?php elseif ($err): ?><div class="err" role="alert"><?= e($err) ?></div><?php endif; ?>

  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <div class="fld"><label for="u">Username</label>
    <input id="u" name="username" maxlength="40" value="<?= e($_POST['username'] ?? '') ?>" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false"></div>
  <div class="fld"><label for="p">Password</label><div class="wrap">
    <input id="p" type="password" name="password" maxlength="200" required autocomplete="current-password">
    <button type="button" class="eye" id="t" aria-controls="p">Tampilkan</button></div>
    <div class="cw" id="cw" hidden>Caps Lock sedang aktif.</div></div>
  <button class="go" id="b">Masuk</button>
  <p class="note">Sesi berakhir otomatis setelah 30 menit tanpa aktivitas. Lupa password? Minta superadmin meresetnya di menu Pengguna.</p>
</form>
</main>
<script nonce="<?= $nonce ?>">
const $=i=>document.getElementById(i),t=$('t'),p=$('p'),b=$('b'),f=$('card'),cw=$('cw'),lk=$('lk');
t.onclick=()=>{const h=p.type==='password';p.type=h?'text':'password';t.textContent=h?'Sembunyikan':'Tampilkan'};
['keyup','keydown'].forEach(ev=>p.addEventListener(ev,e=>{cw.hidden=!(e.getModifierState&&e.getModifierState('CapsLock'))}));
f.addEventListener('submit',e=>{if(!f.u.value.trim()||!p.value){e.preventDefault();(f.u.value.trim()?p:f.u).focus();return}b.disabled=true;b.textContent='Memeriksa…'});
if(lk){let w=+lk.dataset.wait;b.disabled=true;const s=$('cd'),tick=()=>{s.textContent=Math.floor(w/60)+':'+String(w%60).padStart(2,'0');if(w--<=0)location.href='login.php'};tick();setInterval(tick,1000)}
</script>
</body></html>
