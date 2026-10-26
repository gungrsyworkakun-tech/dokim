<?php require 'config.php'; $title = 'Scan barcode';
// Halaman ini SENGAJA publik (tanpa login_required()) — siapapun boleh scan untuk
// melihat detail batch. Mencatat pengeluaran tetap wajib login (diarahkan oleh
// cek_barcode.php). Kalau pengguna sudah login, tetap tampil dengan menu
// lengkap seperti biasa.
$sudahLogin = !empty($_SESSION['u']);
include $sudahLogin ? 'partials/header.php' : 'partials/header_publik.php'; ?>

<?php if ($sudahLogin): ?>
<section class="panel">
  <div class="panel-h"><div><h2>Scan barcode penerimaan</h2>
    <p>Arahkan kamera ke label barcode yang ditempel di kemasan, atau masukkan nomor seri yang tertera di dokumennya langsung. Detail batch akan langsung terbuka — tidak perlu masuk (login) untuk melihatnya.</p></div></div>
  <div class="panel-b">
<?php else: ?>
  <div class="pub-head">
    <h1>Pindai label dokumen</h1>
    <p>Arahkan kamera ke barcode di kemasan. Detail batch terbuka langsung, tanpa perlu masuk (login).</p>
  </div>
<?php endif; ?>

    <div class="scanner">
      <div class="scanner-frame">
        <div id="reader"></div>
        <div class="scanner-corners" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <div class="scanner-sweep" aria-hidden="true"></div>
      </div>
      <p id="scan-status" class="scan-status">Menyiapkan kamera…</p>
    </div>

    <div id="izin-kamera" class="izin-overlay" hidden>
      <div class="izin-modal" role="dialog" aria-modal="true" aria-labelledby="izin-judul" aria-describedby="izin-isi">
        <div class="izin-ikon" aria-hidden="true">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>
        </div>
        <h3 id="izin-judul"></h3>
        <p id="izin-isi"></p>
        <div class="izin-aksi">
          <button type="button" id="izin-tombol" class="utama" hidden></button>
          <button type="button" id="izin-tutup" class="sekunder">Tutup</button>
        </div>
      </div>
    </div>
    <style>
      .izin-overlay{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(10,22,40,.62)}
      .izin-overlay[hidden]{display:none}
      .izin-modal{width:100%;max-width:380px;background:#fff;color:#1b2430;border-radius:18px;padding:24px 22px 20px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.35)}
      .izin-ikon{width:52px;height:52px;margin:0 auto 12px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#e8eef6;color:#1e3a5f}
      .izin-modal h3{margin:0 0 8px;font-size:1.1rem}
      .izin-modal p{margin:0;font-size:.93rem;line-height:1.55;color:#4b5563}
      .izin-aksi{display:flex;flex-direction:column;gap:8px;margin-top:18px}
      .izin-aksi button{padding:12px 16px;border-radius:11px;font:inherit;font-weight:600;cursor:pointer;border:0}
      .izin-aksi button[hidden]{display:none}
      .izin-aksi .utama{background:#1e3a5f;color:#fff}
      .izin-aksi .sekunder{background:transparent;color:#4b5563}
    </style>

    <div class="pub-divider"><span>atau cari manual</span></div>

    <form id="scan-manual" class="search-pill" autocomplete="off">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M13 13l4 4" stroke-linecap="round"/></svg>
      <label for="scan-kode" class="sr-only">Kode barcode atau nomor seri</label>
      <input id="scan-kode" placeholder="Nomor seri atau kode barcode" autocapitalize="characters" autocomplete="off">
      <button type="submit">Cari</button>
    </form>

<?php if (!$sudahLogin): ?>
    <div id="install-hint" class="install-hint">
      <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2.5" width="10" height="15" rx="2"/><path d="M8.5 15h3"/></svg>
      <p id="install-hint-text">Pasang halaman ini sebagai aplikasi di layar utama HP, biar lebih cepat dibuka.</p>
      <button type="button" id="install-btn">Pasang</button>
    </div>
<?php endif; ?>

<?php if ($sudahLogin): ?>
  </div>
</section>
<?php endif; ?>

<script src="assets/html5-qrcode.min.js?v=<?= APP_VER ?>"></script>
<script>
(() => {
  const status = document.getElementById('scan-status');
  // Hasil scan kamera selalu berupa URL/kode barcode asli, bukan nomor seri.
  const goto = raw => {
    let token = (raw || '').trim();
    const m = token.match(/[?&](?:dari|token)=([a-f0-9]{8,64})/i);
    if (m) token = m[1];
    if (!/^[a-f0-9]{8,64}$/i.test(token)) { status.textContent = 'Kode tidak dikenali. Pastikan ini barcode penerimaan SIM-DOKIM.'; status.classList.add('bad-text'); return; }
    location.href = 'cek_barcode.php?token=' + encodeURIComponent(token);
  };

  // Input manual bisa berupa kode barcode (dari URL label) ATAU nomor seri yang tertera di dokumen.
  const bukaManual = raw => {
    let v = (raw || '').trim();
    if (!v) return;
    const m = v.match(/[?&](?:dari|token)=([a-f0-9]{8,64})/i);
    if (m) { location.href = 'cek_barcode.php?token=' + encodeURIComponent(m[1]); return; }
    if (/^[a-f0-9]{8,64}$/i.test(v)) { location.href = 'cek_barcode.php?token=' + encodeURIComponent(v); return; }
    if (/^.{1,30}$/.test(v)) { location.href = 'cek_barcode.php?seri=' + encodeURIComponent(v); return; }
    status.textContent = 'Kode atau nomor seri tidak dikenali.'; status.classList.add('bad-text');
  };

  document.getElementById('scan-manual').addEventListener('submit', e => {
    e.preventDefault();
    bukaManual(document.getElementById('scan-kode').value);
  });

  if (typeof Html5Qrcode === 'undefined') {
    status.textContent = 'Pustaka pemindai gagal dimuat. Muat ulang halaman, atau gunakan kolom kode manual di bawah.';
    return;
  }

  const kartu = document.getElementById('izin-kamera');
  const kartuJudul = document.getElementById('izin-judul');
  const kartuIsi = document.getElementById('izin-isi');
  const kartuTombol = document.getElementById('izin-tombol');
  const kartuTutup = document.getElementById('izin-tutup');
  let aksiTombol = null;
  // Pop up: judul, isi, label tombol utama (null = tanpa tombol utama), dan aksi tombol itu.
  const popup = (judul, isi, labelTombol, aksi) => {
    kartuJudul.textContent = judul; kartuIsi.textContent = isi;
    aksiTombol = aksi || null;
    kartuTombol.hidden = !labelTombol;
    if (labelTombol) kartuTombol.textContent = labelTombol;
    kartu.hidden = false;
    (labelTombol ? kartuTombol : kartuTutup).focus();
  };
  const tutupPopup = () => { kartu.hidden = true; };
  kartuTombol.addEventListener('click', () => { if (aksiTombol) aksiTombol(); });
  kartuTutup.addEventListener('click', tutupPopup);
  kartu.addEventListener('click', e => { if (e.target === kartu) tutupPopup(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !kartu.hidden) tutupPopup(); });

  const qr = new Html5Qrcode('reader');
  let done = false, jalan = false, sedangMulai = false;

  const onScan = text => {
    if (done) return; done = true;
    status.textContent = 'Barcode terbaca, membuka…';
    qr.stop().catch(() => {}).finally(() => goto(text));
  };

  const jelaskanGagal = err => {
    const t = String((err && err.name) || '') + ' ' + String((err && err.message) || err || '');
    status.classList.add('bad-text');
    if (/NotAllowed|Permission|denied/i.test(t)) {
      status.textContent = 'Izin kamera belum diberikan.';
      popup('Izin kamera ditolak',
        'Buka pengaturan situs: ketuk ikon gembok (atau ikon pengaturan) di sebelah alamat web, pilih Izin lalu Kamera lalu Izinkan. Di iPhone: Pengaturan > Safari > Kamera > Izinkan. Setelah itu tekan Coba lagi.',
        'Coba lagi', mulaiKamera);
    } else if (/NotFound|DevicesNotFound|no camera|Requested device not found/i.test(t)) {
      status.textContent = 'Tidak ada kamera terdeteksi.';
      popup('Kamera tidak ditemukan', 'Perangkat ini tidak punya kamera yang bisa dipakai. Gunakan kolom kode manual di bawah.', null);
    } else if (/NotReadable|TrackStart|in use|Could not start/i.test(t)) {
      status.textContent = 'Kamera sedang dipakai.';
      popup('Kamera sedang dipakai aplikasi lain', 'Tutup aplikasi atau tab lain yang memakai kamera, lalu tekan Coba lagi.', 'Coba lagi', mulaiKamera);
    } else {
      status.textContent = 'Kamera belum bisa dibuka.';
      popup('Kamera belum bisa dibuka', 'Coba lagi. Kalau tetap gagal, gunakan kolom kode manual di bawah. (' + t.trim().slice(0, 120) + ')', 'Coba lagi', mulaiKamera);
    }
  };

  async function mulaiKamera() {
    if (jalan || sedangMulai) return;
    tutupPopup();
    status.classList.remove('bad-text');
    sedangMulai = true;
    status.textContent = 'Menunggu izin kamera… pilih "Izinkan" di kotak dari browser.';
    try {
      await qr.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, onScan, () => {});
      jalan = true;
      status.textContent = 'Arahkan kamera ke barcode.';
    } catch (err) {
      jelaskanGagal(err);
    } finally {
      sedangMulai = false;
    }
  }

  window.addEventListener('pagehide', () => { if (jalan) qr.stop().catch(() => {}); });

  // Kalau izin kamera diubah ke "Izinkan" lewat pengaturan browser saat pop up masih terbuka, kamera langsung dicoba lagi.
  try {
    navigator.permissions.query({ name: 'camera' }).then(izin => {
      izin.onchange = () => { if (izin.state === 'granted' && !jalan) mulaiKamera(); };
    }).catch(() => {});
  } catch (e) {}

  // Langkah pertama begitu halaman dibuka: pop up yang meminta izin kamera.
  // Browser hanya mau membuka kamera lewat HTTPS (atau localhost). Tanpa itu, kotak izin tidak akan pernah muncul.
  if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    status.textContent = 'Kamera tidak bisa dibuka di alamat ini.';
    status.classList.add('bad-text');
    popup('Kamera butuh alamat HTTPS',
      'Browser hanya mengizinkan akses kamera kalau halaman dibuka lewat alamat yang diawali https:// (bukan http://). Minta admin memasang HTTPS, atau gunakan kolom kode manual di bawah.', null);
  } else {
    status.textContent = 'Menunggu izin kamera…';
    popup('Izinkan akses kamera',
      'Aplikasi butuh kamera untuk memindai barcode di kemasan. Tekan Izinkan kamera, lalu pilih "Izinkan" (Allow) di kotak dari browser.',
      'Izinkan kamera', mulaiKamera);
  }
})();
</script>

<?php if (!$sudahLogin): ?>
<script>
(() => {
  const hint = document.getElementById('install-hint');
  const btn = document.getElementById('install-btn');
  const text = document.getElementById('install-hint-text');
  if (!hint) return;

  const sudahTerpasang = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  if (sudahTerpasang) return;

  let deferred = null;
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferred = e;
    hint.classList.add('show');
  });
  btn.addEventListener('click', async () => {
    if (!deferred) return;
    deferred.prompt();
    await deferred.userChoice;
    deferred = null;
    hint.classList.remove('show');
  });
  window.addEventListener('appinstalled', () => hint.classList.remove('show'));

  // iOS Safari tidak punya beforeinstallprompt — tampilkan petunjuk manual saja.
  const iOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const safari = /safari/i.test(navigator.userAgent) && !/crios|fxios|chrome/i.test(navigator.userAgent);
  if (iOS && safari) {
    text.textContent = 'Pasang di layar utama: ketuk tombol Bagikan di Safari, lalu pilih "Tambah ke Layar Utama".';
    btn.remove();
    hint.classList.add('show');
  }
})();
</script>
<?php endif; ?>

<?php include $sudahLogin ? 'partials/footer.php' : 'partials/footer_publik.php'; ?>