<?php login_required();
$menu = [
  'index.php'       => 'Ringkasan',
  'stok.php'        => 'Stok dokumen',
  'penerimaan.php'  => 'Penerimaan',
  'pengeluaran.php' => 'Pengeluaran',
  'kartu-stok.php'  => 'Kartu stok harian',
  'opname.php'      => 'Opname fisik',
  'scan.php'        => 'Scan barcode',
];
if (is_super()) { $menu['impor_excel.php'] = 'Impor Excel'; $menu['pengaturan.php'] = 'Pengguna'; }
$cur = basename($_SERVER['PHP_SELF']); if ($cur === 'stok_detail.php') $cur = 'stok.php';

/* ikon kecil per halaman, ditaruh di sebelah judul pada bar atas */
$pageIcons = [
  'index.php'       => '<rect x="3" y="11" width="3.4" height="6" rx=".8"/><rect x="8.3" y="7" width="3.4" height="10" rx=".8"/><rect x="13.6" y="3" width="3.4" height="14" rx=".8"/>',
  'stok.php'         => '<path d="M3 6.5 10 3l7 3.5v7L10 17 3 13.5z" fill="none"/><path d="M3 6.5 10 10l7-3.5M10 10v7" fill="none"/>',
  'penerimaan.php'   => '<rect x="3" y="4" width="14" height="13" rx="1.4" fill="none"/><path d="M10 7.5v5.5M7.3 10.5 10 13.2l2.7-2.7"/>',
  'pengeluaran.php'  => '<rect x="3" y="4" width="14" height="13" rx="1.4" fill="none"/><path d="M10 13v-5.5M7.3 9.5 10 6.8l2.7 2.7"/>',
  'opname.php'       => '<rect x="4.5" y="3.5" width="11" height="14" rx="1.4" fill="none"/><path d="M7.5 3 h5 a0.1 0.1 0 0 1 0 2 h-5 a0.1 0.1 0 0 1 0 -2" fill="none"/><path d="M7 11.2l1.8 1.8L13.3 8.6"/>',
  'kartu-stok.php'   => '<rect x="3" y="4" width="14" height="12" rx="1.4" fill="none"/><path d="M3 8h14M6.5 4v12M6.5 11h10.5" fill="none"/>',
  'scan.php'         => '<path d="M3 6V3.5h3.5M17 6V3.5h-3.5M3 14v2.5h3.5M17 14v2.5h-3.5" fill="none"/><path d="M6 6.5v7M8.4 6.5v7M10.6 6.5v7M12 6.5v7M14 6.5v7" fill="none" stroke-width="1.6"/>',
  'pengaturan.php'   => '<circle cx="7.3" cy="8" r="2.6" fill="none"/><path d="M2.6 16c.6-2.6 2.4-4 4.7-4s4.1 1.4 4.7 4" fill="none"/><circle cx="14.2" cy="7.2" r="2" fill="none"/><path d="M12.4 10.3c1.9.1 3.4 1.4 3.9 3.7" fill="none"/>',
  'akun.php'         => '<circle cx="10" cy="7" r="3.2" fill="none"/><path d="M4 17c.9-3.4 3-5.1 6-5.1s5.1 1.7 6 5.1" fill="none"/>',
  'impor_excel.php'  => '<path d="M10 3v9M6.5 8.5 10 12l3.5-3.5" fill="none"/><path d="M4 14.5v1.3A1.2 1.2 0 0 0 5.2 17h9.6A1.2 1.2 0 0 0 16 15.8v-1.3" fill="none"/>',
];
$pageIcon = $pageIcons[$cur] ?? '<rect x="4" y="2.5" width="12" height="15" rx="1.3" fill="none"/><path d="M7 7h6M7 10.3h6M7 13.6h3.5"/>';

/* angka kecil di menu: stok kritis & penerimaan menunggu verifikasi */
$navCnt = [];
$n = (int)$pdo->query('SELECT COUNT(*) FROM dokumen WHERE stok <= ' . (int)STOK_KRITIS)->fetchColumn();
if ($n) $navCnt['stok.php'] = [$n, 'bad', "$n dokumen stok kritis/habis"];
if (is_super()) {
  $n = (int)$pdo->query("SELECT COUNT(*) FROM penerimaan WHERE status='Pending'")->fetchColumn();
  if ($n) $navCnt['penerimaan.php'] = [$n, 'warn', "$n penerimaan menunggu verifikasi"];
}

/* data untuk pencarian cepat & bantuan isi formulir (assets/app.js) */
$simData = [
  'idle' => SESSION_IDLE,
  'csrf' => csrf(),
  'dok'  => $pdo->query("SELECT d.id, d.kode, d.nama, d.stok, d.satuan,
      (SELECT p.seri_akhir FROM penerimaan p  WHERE p.dokumen_id=d.id ORDER BY p.tanggal DESC, p.id DESC LIMIT 1) last_in,
      (SELECT k.seri_akhir FROM pengeluaran k WHERE k.dokumen_id=d.id ORDER BY k.tanggal DESC, k.id DESC LIMIT 1) last_out
    FROM dokumen d ORDER BY d.nama")->fetchAll(),
];
?><!DOCTYPE html>
<html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · SIM-DOKIM</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= APP_VER ?>">
<script src="assets/app.js?v=<?= APP_VER ?>" defer></script>
</head>
<body>
<aside class="side" id="side">
  <a class="brand" href="index.php">
    <span class="seal" aria-hidden="true">SD</span>
    <span><b>SIM-DOKIM</b><small>Stok dokumen keimigrasian</small></span>
  </a>
  <nav aria-label="Menu utama">
    <?php foreach ($menu as $f => $l): $c = $navCnt[$f] ?? null; ?>
      <a href="<?= $f ?>" data-label="<?= e($l) ?>" class="<?= $cur === $f ? 'on' : '' ?>"<?= $cur === $f ? ' aria-current="page"' : '' ?>>
        <span><?= e($l) ?></span><?php if ($c): ?><em class="cnt cnt-<?= $c[1] ?>" title="<?= e($c[2]) ?>"><?= $c[0] ?></em><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">Direktorat Jenderal Imigrasi</div>
</aside>
<div class="scrim" data-toggle-side></div>

<div class="main">
<header class="top">
  <button type="button" class="icon-btn menu-btn" data-toggle-side aria-label="Buka menu">
    <svg viewBox="0 0 20 20" width="18" height="18"><path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
  </button>
  <div class="page-id">
    <span class="page-ico" aria-hidden="true"><svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><?= $pageIcon ?></svg></span>
    <h1><?= e($title) ?></h1>
  </div>
  <button type="button" class="search-btn" data-palette aria-label="Cari dokumen atau menu">
    <svg viewBox="0 0 20 20" width="15" height="15"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M13 13l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
    <span>Cari dokumen atau menu</span><kbd>Ctrl K</kbd>
  </button>
  <div class="sesi" id="sesi" title="Sesi berakhir otomatis bila tidak ada aktivitas">
    <svg viewBox="0 0 20 20" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="7.2"/><path d="M10 6v4.3l3 2" stroke-linecap="round"/></svg>
    Sesi <b>--:--</b> <button type="button" class="link" hidden>Perpanjang</button>
  </div>
  <a class="who" href="akun.php" title="Akun saya">
    <span class="who-avatar"><?= strtoupper(mb_substr($_SESSION['u']['nama'], 0, 1)) ?></span>
    <span class="who-info"><b><?= e($_SESSION['u']['nama']) ?></b><small><?= is_super() ? 'Superadmin' : 'Admin' ?></small></span>
    <svg class="who-chev" viewBox="0 0 20 20" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 5.5 12 10l-4.5 4.5"/></svg>
  </a>
  <a href="logout.php" class="icon-btn logout-btn" title="Keluar" aria-label="Keluar">
    <svg viewBox="0 0 20 20" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 17H4.8A1.8 1.8 0 0 1 3 15.2V4.8A1.8 1.8 0 0 1 4.8 3H8"/><path d="M13 14l4-4-4-4"/><path d="M17 10H8"/></svg>
  </a>
</header>

<main class="content">
<?php if (!empty($_SESSION['flash'])): [$fm, $ft] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="flash <?= $ft === 'err' ? 'err' : 'ok' ?>" role="<?= $ft === 'err' ? 'alert' : 'status' ?>">
    <span><?= e($fm) ?></span><button type="button" aria-label="Tutup pesan">&times;</button>
  </div>
<?php endif; ?>