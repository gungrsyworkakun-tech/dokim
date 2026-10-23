<?php
// Layout minimal untuk halaman publik (tanpa login) seperti cek_barcode.php & scan.php.
// Tidak memuat data sesi/menu apapun — aman diakses siapa saja yang punya link/kode barcode.
?><!DOCTYPE html>
<html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#1e3a5f">
<title><?= e($title) ?> · SIM-DOKIM</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= APP_VER ?>">
<!-- PWA: halaman Pindai & Cek barcode bisa di-"install" ke layar utama HP -->
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="icon" href="assets/icon-192.png">
</head>
<body class="pub">
<div class="pub-shell">
  <div class="pub-card">
    <a class="pub-brand" href="index.php">
      <span class="pub-seal" aria-hidden="true">SD</span>
      <span><b>SIM-DOKIM</b><small>Stok dokumen keimigrasian</small></span>
    </a>
    <?php if (!empty($_SESSION['flash'])): [$fm, $ft] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="flash <?= $ft === 'err' ? 'err' : 'ok' ?>" role="<?= $ft === 'err' ? 'alert' : 'status' ?>">
        <span><?= e($fm) ?></span><button type="button" aria-label="Tutup pesan">&times;</button>
      </div>
    <?php endif; ?>
    <main class="pub-main">