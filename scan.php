<?php require 'config.php'; login_required(); $title = 'Scan barcode'; include 'partials/header.php'; ?>

<section class="panel">
  <div class="panel-h"><div><h2>Scan barcode penerimaan</h2>
    <p>Arahkan kamera ke label barcode yang ditempel di kemasan. Saat terbaca, formulir pengeluaran untuk dokumen tersebut akan langsung terbuka.</p></div></div>
  <div class="panel-b scan-wrap">
    <div id="reader"></div>
    <p id="scan-status" class="muted">Menyiapkan kamera…</p>
    <p class="side-note">Tidak bisa pakai kamera? Masukkan kode barcode secara manual:</p>
    <form id="scan-manual" class="toolbar" autocomplete="off">
      <div class="grow"><label for="scan-kode" class="sr-only">Kode barcode</label><input id="scan-kode" class="mono" placeholder="Tempel/ketik kode dari URL barcode"></div>
      <div class="tb-act"><button type="submit" class="btn sm">Buka</button></div>
    </form>
  </div>
</section>

<script src="https://unpkg.com/html5-qrcode@2.3.8"></script>
<script>
(() => {
  const status = document.getElementById('scan-status');
  const goto = raw => {
    let token = (raw || '').trim();
    const m = token.match(/[?&]dari=([a-f0-9]{8,64})/i);
    if (m) token = m[1];
    if (!/^[a-f0-9]{8,64}$/i.test(token)) { status.textContent = 'Kode tidak dikenali. Pastikan ini barcode penerimaan SIM-DOKIM.'; status.classList.add('bad-text'); return; }
    location.href = 'pengeluaran.php?dari=' + encodeURIComponent(token);
  };

  document.getElementById('scan-manual').addEventListener('submit', e => {
    e.preventDefault();
    goto(document.getElementById('scan-kode').value);
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

<?php include 'partials/footer.php'; ?>
