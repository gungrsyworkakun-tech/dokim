<?php require_once 'config.php';
if (!empty($_SESSION['u'])) back(safe_next((string)($_GET['next'] ?? '')) ?: 'index.php');

/* ---------- Keamanan: header + CSP (nonce) ---------- */
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; style-src 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'nonce-$nonce'; img-src 'self' data:; connect-src 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" . (is_https() ? '; upgrade-insecure-requests' : ''));

/* ---------- Rate limit (anti brute-force) ---------- */
const MAX_USER = 5;     // gagal per IP+username
const MAX_IP   = 15;    // gagal per IP
const MAX_USER_SEMUA_IP = 30;   // gagal per username dari IP mana pun (menahan serangan tebak-password yang gonta-ganti IP)
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
$ip = client_ip();   // di balik proxy/ngrok tetap IP pengunjung asli, bukan IP proxy (kalau tidak, satu orang salah password bisa mengunci semua orang)

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
  if ($user !== '') {
    $g = $pdo->prepare("SELECT COUNT(*) c, TIMESTAMPDIFF(SECOND, MIN(waktu), NOW()) age FROM login_attempts WHERE username = ? AND waktu > NOW() - INTERVAL " . WINDOW . " SECOND");
    $g->execute([$user]);
    $gr = $g->fetch();
    if ((int)($gr['c'] ?? 0) >= MAX_USER_SEMUA_IP) $wait = max($wait, WINDOW - (int)$gr['age']);
  }
  return [$wait, $left];
}
}

$err = ''; $wait = 0;
$next = safe_next((string)($_POST['next'] ?? $_GET['next'] ?? ''));
if (isset($_GET['exp'])) $err = 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Nilai selain teks biasa (mis. username[]=x) dianggap kosong, supaya tidak memicu perilaku aneh di PHP.
  $user = strtolower(substr(trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : ''), 0, 40));
  $pass = substr(is_string($_POST['password'] ?? null) ? $_POST['password'] : '', 0, 200);
  $userValid = (bool)preg_match('/^[a-z0-9._-]{1,40}$/', $user);   // format username sama dengan aturan saat pengguna dibuat
  $userLog = $userValid ? $user : '?';
  [$wait] = lock_wait($pdo, $ip, $user);
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $err = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
  } elseif ($wait > 0) {
    $err = '';   // ditampilkan sebagai kotak terkunci
  } else {
    $u = false;
    if ($userValid) { $s = $pdo->prepare('SELECT * FROM users WHERE username=?'); $s->execute([$user]); $u = $s->fetch(); }
    if ($u) $ok = password_verify($pass, $u['password']);
    else { password_hash($pass, PASSWORD_DEFAULT); $ok = false; }   // user tidak ada: tetap habiskan waktu hash yang sama, supaya tidak bisa menebak username dari kecepatan respons
    if ($ok) {
      if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
      $pdo->prepare('DELETE FROM login_attempts WHERE ip=? AND username=?')->execute([$ip, $user]);
      session_regenerate_id(true);
      $_SESSION = ['u' => ['id'=>(int)$u['id'], 'nama'=>$u['nama'], 'username'=>$u['username'], 'role'=>$u['role']],
                   'ua' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 'last' => time()];
      back($next ?: 'index.php');
    }
    $pdo->prepare('INSERT INTO login_attempts(ip,username) VALUES(?,?)')->execute([$ip, $user]);
    error_log("SIMDOKIM login gagal user=$userLog ip=$ip");   // jejak untuk admin server (atau fail2ban); password tidak pernah dicatat
    usleep(random_int(250000, 500000));
    [$wait, $left] = lock_wait($pdo, $ip, $user);
    if ($wait > 0) error_log("SIMDOKIM login dikunci user=$userLog ip=$ip");
    $err = $wait > 0 ? '' : "Username atau password salah. Sisa percobaan: $left.";
  }
}
unset($_SESSION['csrf']); $csrf = csrf();   // token baru setiap tampil
?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Masuk · SIM-DOKIM</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style nonce="<?= $nonce ?>">
:root{--ink:#161a21;--ink-2:#3d4452;--ink-3:#667085;--line:#d5dae1;--navy:#14304f;--navy-2:#2a5490;--gold:#c4922e;--bg:#f3f5f8}
*{box-sizing:border-box;margin:0}
html{background:#0d2036}
body{font:14px/1.6 "IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);min-height:100vh;position:relative;display:grid;grid-template-columns:minmax(0,1fr) 440px;grid-template-rows:auto 1fr auto;column-gap:56px;padding:0 clamp(24px,6vw,96px);isolation:isolate}
/* foto gedung kantor sebagai latar penuh, ditutup lapisan biru tua supaya tulisan tetap terbaca */
body::before{content:"";position:fixed;inset:0;z-index:-2;background:#0d2036 url("assets/login-kanim.jpg") 28% center/cover no-repeat}
body::after{content:"";position:fixed;inset:0;z-index:-1;background:
  linear-gradient(180deg,rgba(9,24,43,.78) 0,rgba(9,24,43,.15) 22%,rgba(9,24,43,.15) 45%,rgba(9,24,43,.88) 100%),
  linear-gradient(90deg,rgba(9,24,43,.55) 0,rgba(9,24,43,.1) 55%,rgba(9,24,43,.55) 100%)}
.brand{grid-column:1/-1;display:flex;align-items:center;gap:14px;padding-top:36px}
.seal{width:42px;height:42px;border-radius:10px;background:rgba(255,255,255,.14);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);color:#fff;display:grid;place-items:center;font:600 14px "IBM Plex Mono",monospace;border:1px solid rgba(255,255,255,.28);box-shadow:inset 0 -2px 0 var(--gold)}
.brand b{display:block;font-size:16px;letter-spacing:.3px;color:#fff;font-weight:600}
.brand small{display:block;color:rgba(255,255,255,.72);font-size:12.5px}
.lead{grid-column:1;align-self:end;padding-bottom:44px;max-width:560px;color:#fff}
.lead h2{font-size:clamp(26px,3.2vw,40px);line-height:1.18;font-weight:600;letter-spacing:-.015em;text-shadow:0 2px 24px rgba(0,0,0,.35)}
.lead p{margin-top:16px;color:rgba(255,255,255,.82);font-size:15px;line-height:1.7;max-width:46ch}
.lead::before{content:"";display:block;width:44px;height:3px;background:var(--gold);margin-bottom:22px;border-radius:2px}
.form-side{grid-column:2;grid-row:2/4;align-self:center;padding:24px 0}
form{width:100%;background:#fff;border-radius:16px;padding:36px 34px 30px;box-shadow:0 24px 60px rgba(4,14,28,.45),0 2px 6px rgba(4,14,28,.2);border-top:3px solid var(--gold)}
h1{font-size:24px;font-weight:600;letter-spacing:-.01em;color:var(--ink)}
.sub{color:var(--ink-3);margin:6px 0 26px;font-size:14px}
.fld{margin-bottom:16px}
label{display:block;font-size:13px;font-weight:500;color:var(--ink-2);margin-bottom:6px}
.wrap{position:relative}
input{width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:10px;background:#fff;font:inherit;font-size:15px;color:var(--ink);transition:border-color .15s,box-shadow .15s}
input:focus{outline:none;border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(42,84,144,.14)}
#p{padding-right:92px}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;padding:7px 12px;font:inherit;font-size:13px;color:var(--navy-2);cursor:pointer;border-radius:6px;transition:background .15s}
.eye:hover{background:#eef3fb}
.cw{font-size:13px;color:#b06d10;margin-top:6px}.cw[hidden]{display:none}
.go{width:100%;margin-top:8px;padding:12px;border:0;border-radius:10px;background:var(--navy);color:#fff;font:inherit;font-weight:500;font-size:15px;cursor:pointer;transition:background .18s,box-shadow .18s}
.go:hover{background:var(--navy-2);box-shadow:0 6px 16px rgba(20,48,79,.28)}
.go:disabled{opacity:.55;cursor:not-allowed;box-shadow:none}
.go:focus-visible,.eye:focus-visible,.scan-alt:focus-visible{outline:2px solid var(--navy-2);outline-offset:2px}
.err,.lock{padding:12px 14px;border-radius:10px;font-size:13.5px;margin-bottom:18px;border:1px solid}
.err{background:#fde8e7;border-color:#f9d0ce;color:#8a2017}
.lock{background:#eef3fb;border-color:#c6d2e4;color:var(--navy)}.lock b{font-family:"IBM Plex Mono",monospace}
.note{margin-top:22px;padding-top:16px;border-top:1px solid #e6e9ee;font-size:12.5px;color:var(--ink-3);line-height:1.6}
.scan-alt{display:flex;align-items:center;gap:10px;margin-top:14px;padding:11px 14px;border-radius:10px;background:var(--bg);border:1px solid #e2e5ea;text-decoration:none;color:var(--ink-2);transition:background .15s,border-color .15s}
.scan-alt:hover{background:#eaf0f8;border-color:#c6d2e4}
.scan-alt svg{flex-shrink:0;color:var(--navy-2)}
.scan-alt span{display:block;font-size:13.5px;font-weight:500;color:var(--ink)}
.scan-alt small{display:block;font-size:12px;color:var(--ink-3);margin-top:1px}
.org{grid-column:1/-1;grid-row:3;padding:0 0 26px;font-size:12px;color:rgba(255,255,255,.6)}
@media(max-width:980px){
  body{grid-template-columns:minmax(0,1fr);grid-template-rows:auto 1fr auto;column-gap:0;padding:0 20px}
  body::before{background-position:30% center}
  body::after{background:linear-gradient(180deg,rgba(9,24,43,.7) 0,rgba(9,24,43,.55) 60%,rgba(9,24,43,.85) 100%)}
  .brand{padding-top:24px}
  .lead{display:none}
  .form-side{grid-column:1;grid-row:2;align-self:start;padding:28px 0 20px;max-width:440px;width:100%;margin:0 auto}
  form{padding:30px 24px 24px}
  .org{grid-row:3;text-align:center}
}
.guide-btn{display:inline-flex;align-items:center;gap:7px;margin:-14px 0 22px;padding:5px 10px 5px 8px;border:1px solid #d6dfec;background:#f5f8fc;color:var(--navy-2);border-radius:999px;font:inherit;font-size:12.5px;font-weight:500;cursor:pointer;transition:background .15s,border-color .15s}
.guide-btn:hover{background:#eaf0f8;border-color:#b9c9de}
.guide-btn:focus-visible{outline:2px solid var(--navy-2);outline-offset:2px}
/* panduan langkah demi langkah */
#tour-hl{position:fixed;z-index:50;border-radius:12px;box-shadow:0 0 0 9999px rgba(7,18,34,.62),0 0 0 2px #fff;pointer-events:none;transition:top .28s ease,left .28s ease,width .28s ease,height .28s ease}
#tour-pop{position:fixed;z-index:51;width:320px;max-width:calc(100vw - 24px);background:#fff;color:var(--ink);border-radius:12px;padding:16px 18px 14px;box-shadow:0 16px 40px rgba(4,14,28,.4);transition:top .28s ease,left .28s ease}
#tour-pop::before{content:"";position:absolute;width:14px;height:14px;background:#fff;transform:rotate(45deg);left:var(--ax,28px);top:-7px;border-radius:3px}
#tour-pop.atas::before{top:auto;bottom:-7px}
#tour-pop.kiri::before{left:auto;right:-7px;top:var(--ay,24px)}
#tour-pop h3{font-size:15px;font-weight:600;margin-bottom:4px}
#tour-pop p{font-size:13.5px;color:var(--ink-2);line-height:1.55}
#tour-pop .ft{display:flex;align-items:center;gap:8px;margin-top:14px}
#tour-pop .stp{margin-right:auto;white-space:nowrap;font-size:12px;color:var(--ink-3)}
#tour-pop button{font:inherit;font-size:13px;font-weight:500;border-radius:8px;padding:7px 14px;cursor:pointer;border:1px solid var(--line);background:#fff;color:var(--ink-2)}
#tour-pop button.pri{background:var(--navy);border-color:var(--navy);color:#fff}
#tour-pop button.pri:hover{background:var(--navy-2)}
#tour-pop button:focus-visible{outline:2px solid var(--navy-2);outline-offset:2px}
#tour-pop .x{position:absolute;right:8px;top:6px;border:0;background:none;font-size:20px;line-height:1;color:var(--ink-3);padding:2px 8px}
[hidden]{display:none!important}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style></head>
<body>
<header class="brand"><span class="seal">SD</span><span><b>SIM-DOKIM</b><small>Sistem Informasi Manajemen Dokumen Keimigrasian</small></span></header>
<section class="lead">
  <h2>Buku stok blanko dokumen keimigrasian</h2>
  <p>Penerimaan, pengeluaran, nomor seri, dan opname fisik paspor, stiker izin tinggal, VOA, dan dokumen lainnya tercatat di satu tempat.</p>
</section>

<main class="form-side">
<form method="post" id="card" autocomplete="on" novalidate>
  <h1>Masuk</h1>
  <p class="sub">Gunakan akun yang diberikan oleh superadmin kantor.</p>
  <button type="button" class="guide-btn" id="gb"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="7.5"/><path d="M7.9 7.8a2.2 2.2 0 1 1 3.1 2c-.7.4-1 .8-1 1.5"/><path d="M10 14h.01"/></svg>Panduan masuk</button>

  <?php if ($wait > 0): ?><div class="lock" id="lk" role="alert" data-wait="<?= (int)$wait ?>">Terlalu banyak percobaan gagal. Coba lagi dalam <b id="cd">--:--</b>.</div>
  <?php elseif ($err): ?><div class="err" role="alert"><?= e($err) ?></div><?php endif; ?>

  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
  <div class="fld" id="fu"><label for="u">Username</label>
    <input id="u" name="username" maxlength="40" value="<?= e(is_string($_POST['username'] ?? null) ? substr($_POST['username'], 0, 40) : '') ?>" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false"></div>
  <div class="fld" id="fp"><label for="p">Password</label><div class="wrap">
    <input id="p" type="password" name="password" maxlength="200" required autocomplete="current-password">
    <button type="button" class="eye" id="t" aria-controls="p">Tampilkan</button></div>
    <div class="cw" id="cw" hidden>Caps Lock sedang aktif.</div></div>
  <button class="go" id="b">Masuk</button>
  <p class="note" id="nt">Sesi berakhir otomatis setelah 30 menit tanpa aktivitas. Lupa password? Minta superadmin meresetnya di menu Pengguna.</p>
  <a class="scan-alt" id="sc" href="scan.php">
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 6V3.5h3.5M17 6V3.5h-3.5M3 14v2.5h3.5M17 14v2.5h-3.5"/><path d="M6 6.5v7M8.4 6.5v7M10.6 6.5v7M12 6.5v7M14 6.5v7" stroke-width="1.4"/></svg>
    <span>Cuma mau cek barcode dokumen?<small>Tidak perlu masuk — pindai atau cari nomor seri di sini</small></span>
  </a>
</form>
</main>
<div class="org">Seksi TIK Kantor Imigrasi Kelas I Khusus TPI Ngurah Rai</div>
<script nonce="<?= $nonce ?>">
const $=i=>document.getElementById(i),t=$('t'),p=$('p'),b=$('b'),f=$('card'),cw=$('cw'),lk=$('lk');
t.onclick=()=>{const h=p.type==='password';p.type=h?'text':'password';t.textContent=h?'Sembunyikan':'Tampilkan'};
['keyup','keydown'].forEach(ev=>p.addEventListener(ev,e=>{cw.hidden=!(e.getModifierState&&e.getModifierState('CapsLock'))}));
f.addEventListener('submit',e=>{if(!f.u.value.trim()||!p.value){e.preventDefault();(f.u.value.trim()?p:f.u).focus();return}b.disabled=true;b.textContent='Memeriksa…'});
if(lk){let w=+lk.dataset.wait;b.disabled=true;const s=$('cd'),tick=()=>{s.textContent=Math.floor(w/60)+':'+String(w%60).padStart(2,'0');if(w--<=0)location.href=location.pathname+location.search};tick();setInterval(tick,1000)}

/* Panduan masuk: sorotan + kotak penjelasan dengan panah ke bagian yang dimaksud */
(()=>{
  const langkah=[
    {el:'fu',j:'Isi username',t:'Ketik username yang diberikan superadmin. Semua huruf kecil, tanpa spasi.'},
    {el:'fp',j:'Isi password',t:'Ketik password. Klik "Tampilkan" kalau mau memeriksa ketikanmu. Pastikan Caps Lock mati.'},
    {el:'b',j:'Klik Masuk',t:'Klik Masuk atau tekan Enter. Kalau salah 5 kali, akun dikunci 15 menit demi keamanan.'},
    {el:'nt',j:'Lupa password?',t:'Minta superadmin mengatur ulang di menu Pengguna. Sesi berakhir otomatis setelah 30 menit tanpa aktivitas.'},
    {el:'sc',j:'Hanya cek barcode?',t:'Tidak perlu masuk. Pindai barcode di kemasan atau cari nomor seri lewat tombol ini.'}
  ];
  const gb=$('gb'); if(!gb) return;
  let i=-1,hl,pop,lastFocus;
  const tutup=()=>{if(hl){hl.remove();pop.remove();hl=pop=null}document.removeEventListener('keydown',tombol);window.removeEventListener('resize',taruh);window.removeEventListener('scroll',taruh,true);i=-1;try{localStorage.setItem('dokim_panduan','1')}catch(e){}if(lastFocus)lastFocus.focus()};
  const tombol=e=>{if(e.key==='Escape')tutup();else if(e.key==='ArrowRight'||e.key==='Enter'&&document.activeElement.id==='tn'){}};
  function taruh(){
    if(!pop)return; const L=langkah[i], el=$(L.el), r=el.getBoundingClientRect(), pad=6;
    hl.style.cssText='top:'+(r.top-pad)+'px;left:'+(r.left-pad)+'px;width:'+(r.width+pad*2)+'px;height:'+(r.height+pad*2)+'px';
    const pw=pop.offsetWidth, ph=pop.offsetHeight, vw=innerWidth, vh=innerHeight;
    if(r.left-pad-16-pw>=12){   // layar lebar: kotak penjelasan di sebelah kiri, panah menunjuk ke kanan
      const top=Math.min(Math.max(12,r.top+r.height/2-30),vh-ph-12);
      pop.classList.remove('atas');pop.classList.add('kiri');
      pop.style.top=top+'px';pop.style.left=(r.left-pad-16-pw)+'px';
      pop.style.setProperty('--ay',Math.min(Math.max(14,r.top+r.height/2-top-7),ph-30)+'px');
      return;
    }
    pop.classList.remove('kiri');
    let atas=r.bottom+pad+14+ph>vh-8 && r.top-pad-14-ph>8;
    let top=atas?r.top-pad-14-ph:r.bottom+pad+14;
    let left=Math.min(Math.max(12,r.left+r.width/2-pw/2),vw-pw-12);
    pop.classList.toggle('atas',atas);
    pop.style.top=top+'px'; pop.style.left=left+'px';
    pop.style.setProperty('--ax',Math.min(Math.max(16,r.left+r.width/2-left-7),pw-30)+'px');
  }
  function tampil(n){
    i=n; const L=langkah[i], el=$(L.el);
    el.scrollIntoView({block:'center',behavior:'instant'});
    pop.innerHTML='<button type="button" class="x" id="tx" aria-label="Tutup panduan">×</button><h3>'+L.j+'</h3><p>'+L.t+'</p><div class="ft"><span class="stp">Langkah '+(i+1)+' dari '+langkah.length+'</span>'+(i>0?'<button type="button" id="tb">Kembali</button>':'')+'<button type="button" class="pri" id="tn">'+(i<langkah.length-1?'Berikutnya':'Selesai')+'</button></div>';
    $('tx').onclick=tutup; if($('tb'))$('tb').onclick=()=>tampil(i-1);
    $('tn').onclick=()=>i<langkah.length-1?tampil(i+1):tutup(); $('tn').focus();
    taruh();
  }
  gb.onclick=()=>{
    if(hl)return; lastFocus=gb;
    hl=document.createElement('div');hl.id='tour-hl';
    pop=document.createElement('div');pop.id='tour-pop';pop.setAttribute('role','dialog');pop.setAttribute('aria-live','polite');pop.setAttribute('aria-label','Panduan masuk');
    document.body.append(hl,pop);
    document.addEventListener('keydown',tombol);window.addEventListener('resize',taruh);window.addEventListener('scroll',taruh,true);
    tampil(0);
  };
  try{if(!localStorage.getItem('dokim_panduan'))gb.style.boxShadow='0 0 0 3px rgba(42,84,144,.18)'}catch(e){}
})();
</script>
</body></html>