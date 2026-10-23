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
    status.textContent = 'Pustaka pemindai gagal dimuat (perlu koneksi internet di perangkat ini). Gunakan kolom kode manual di bawah.';
    return;
  }
  const qr = new Html5Qrcode('reader');
  let done = false;
  Html5Qrcode.getCameras().then(cams => {
    if (!cams || !cams.length) { status.textContent = 'Tidak ada kamera terdeteksi. Gunakan kolom kode manual di bawah.'; return; }
    const camId = cams.find(c => /back|belakang|rear/i.test(c.label))?.id || cams[cams.length - 1].id;
    qr.start(camId, { fps: 10, qrbox: 240 }, text => {
      if (done) return; done = true;
      status.textContent = 'Barcode terbaca, membuka…';
      qr.stop().catch(() => {}).finally(() => goto(text));
    }, () => {}).then(() => { status.textContent = 'Arahkan kamera ke barcode.'; })
      .catch(() => { status.textContent = 'Tidak bisa mengakses kamera (izin ditolak?). Gunakan kolom kode manual di bawah.'; });
  }).catch(() => { status.textContent = 'Tidak bisa mengakses kamera (izin ditolak?). Gunakan kolom kode manual di bawah.'; });
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