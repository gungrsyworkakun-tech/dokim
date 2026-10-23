<?php require 'config.php'; $title = 'Cek barcode';
// Halaman ini SENGAJA tidak pakai login_required() — siapapun yang scan barcode
// di kemasan boleh melihat detail batch ini. Mencatat pengeluaran/penerimaan
// tetap wajib login (link di bawah akan diarahkan ke halaman login dulu).

$token = trim((string)($_GET['token'] ?? ''));
$seri  = trim((string)($_GET['seri'] ?? ''));
$r = null;

if (preg_match('/^[a-f0-9]{8,64}$/i', $token)) {
  $s = $pdo->prepare('SELECT p.*, d.nama, d.kode, d.satuan FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id WHERE p.barcode_token=?');
  $s->execute([$token]); $r = $s->fetch();
} elseif ($seri !== '' && mb_strlen($seri) <= 30) {
  // Cari di antara batch yang sudah punya label barcode (yang belum dibuat labelnya tidak bisa dibuka lewat scan/seri)
  $kandidat = $pdo->query("SELECT p.*, d.nama, d.kode, d.satuan FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id WHERE p.barcode_token IS NOT NULL ORDER BY p.tanggal DESC, p.id DESC")->fetchAll();
  foreach ($kandidat as $k) {
    if (seri_dalam_rentang($seri, $k['seri_awal'], $k['seri_akhir'])) { $r = $k; break; }
  }
}

header('X-Robots-Tag: noindex, nofollow');
include 'partials/header_publik.php'; ?>

<?php if (!$r): ?>
  <div class="result-icon bad" aria-hidden="true">
    <svg width="24" height="24" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l8 8M14 6l-8 8"/></svg>
  </div>
  <h1 class="result-title">Tidak ditemukan</h1>
  <p class="result-sub"><?= $seri !== '' ? 'Nomor seri ' . e($seri) . ' tidak ditemukan di batch manapun yang sudah punya label barcode.' : 'Kode ini tidak dikenali atau belum pernah dibuat barcode-nya.' ?> Pastikan memindai label asli dari kemasan, atau periksa lagi nomor serinya.</p>
  <div class="pub-cta" style="border-top:0;padding-top:18px"><a class="pub-btn ghost" href="scan.php">Coba cari lagi</a></div>
<?php else: $pend = $r['status'] === 'Pending'; ?>
  <div class="result-icon ok" aria-hidden="true">
    <svg width="24" height="24" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5l4 4 8-9"/></svg>
  </div>
  <h1 class="result-title"><?= e($r['nama']) ?></h1>
  <p class="result-sub"><span class="mono"><?= e($r['kode']) ?></span> · No. BA <span class="mono"><?= e($r['no_ba']) ?></span></p>

  <div class="stat-row">
    <div class="stat"><span class="stat-num"><?= num($r['jumlah']) ?></span><span class="stat-label"><?= strtolower($r['satuan']) ?></span></div>
    <div class="stat"><span class="stat-num sm"><?= tgl($r['tanggal']) ?></span><span class="stat-label">diterima</span></div>
  </div>

  <dl class="meta-list">
    <div><dt>Rentang seri</dt><dd><?= e($r['seri_awal']) ?> – <?= e($r['seri_akhir']) ?></dd></div>
    <div><dt>Status</dt><dd><span class="st <?= $pend ? 'st-pending' : 'st-ok' ?>"><?= $pend ? 'Menunggu verifikasi' : 'Terverifikasi' ?></span></dd></div>
  </dl>

  <?php if ($seri !== ''): ?><p class="pub-note">Ditemukan lewat nomor seri <span class="mono"><?= e($seri) ?></span>.</p><?php endif; ?>
  <?php if (!empty($r['catatan'])): ?><p class="pub-note"><b>Catatan:</b> <?= e($r['catatan']) ?></p><?php endif; ?>

  <div class="pub-cta">
    <p>Mau catat barang dari batch ini keluar?</p>
    <a class="pub-btn" href="pengeluaran.php?dari=<?= e($r['barcode_token']) ?>">Masuk &amp; catat pengeluaran</a>
  </div>
<?php endif; ?>

<?php include 'partials/footer_publik.php'; ?>