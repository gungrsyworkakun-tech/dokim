<?php
// Service worker untuk fitur Pindai & Cek barcode SIM-DOKIM (halaman publik saja).
// Ditulis sebagai .php (bukan .js statis) supaya nomor versi cache ikut APP_VER
// dari config.php secara otomatis — jadi tiap kali ada update, cache lama otomatis dibuang.
require 'config.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('Service-Worker-Allowed: /');
$v = APP_VER;
?>
const CACHE = 'dokim-scan-v<?= $v ?>';
const CORE_ASSETS = [
  'scan.php',
  'assets/style.css?v=<?= $v ?>',
  'assets/html5-qrcode.min.js?v=<?= $v ?>',
  'manifest.webmanifest',
  'assets/icon-192.png',
  'assets/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE)
      .then(cache => cache.addAll(CORE_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(names => Promise.all(names.filter(n => n !== CACHE).map(n => caches.delete(n))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Halaman (navigasi): coba jaringan dulu supaya data selalu segar;
  // kalau gagal (offline/putus), tampilkan versi scan.php yang tersimpan.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('scan.php'))
    );
    return;
  }

  // Aset statis (css/js/ikon): pakai cache dulu supaya cepat, perbarui di latar belakang.
  if (/\.(?:css|js|png)(\?.*)?$/.test(url.pathname + url.search)) {
    event.respondWith(
      caches.match(req).then(cached => {
        const fetchPromise = fetch(req).then(res => {
          if (res && res.ok) caches.open(CACHE).then(cache => cache.put(req, res.clone()));
          return res;
        }).catch(() => cached);
        return cached || fetchPromise;
      })
    );
  }
});
