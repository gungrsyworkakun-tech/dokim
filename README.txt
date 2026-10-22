SIM-DOKIM 3.0 (PHP native + MySQL)

PEMASANGAN
1. Taruh folder ini di htdocs (XAMPP) / www (Laragon).
2. Import database.sql lewat phpMyAdmin. Tidak ada perubahan struktur tabel dari versi 2.4,
   jadi database lama bisa langsung dipakai.
3. Sesuaikan koneksi DB di config.php. Untuk XAMPP biasanya:
     DB_HOST = 'localhost'   DB_USER = 'root'   DB_PASS = ''
4. Buka http://localhost/sim-dokim/login.php
Akun contoh: superadmin / Super123!   |   admin / Admin123!
Halaman Pengguna dan Akun saya akan mengingatkan selama password contoh ini masih dipakai.

STRUKTUR
  config.php            koneksi, sesi, fungsi bantu
  partials/header.php   menu, bar atas, pencarian cepat
  partials/footer.php
  assets/style.css      tampilan
  assets/app.js         fitur bantu di browser
  akun.php              ganti password sendiri (baru)
  ping.php              dipakai tombol "Perpanjang" sesi (baru)

FITUR BANTU BARU
- Pencarian cepat: tekan Ctrl+K (atau /) di halaman mana pun untuk melompat ke dokumen atau menu.
- Ringkasan: daftar "Perlu perhatian" dengan perkiraan berapa hari lagi stok habis,
  dihitung dari rata-rata pengeluaran 90 hari terakhir. Tombol langsung "Catat penerimaan".
- Formulir penerimaan/pengeluaran:
    * nomor seri awal otomatis melanjutkan seri terakhir dokumen yang dipilih,
    * nomor seri akhir dihitung dari seri awal + jumlah (A00001 + 10.000 = A10000),
    * panel samping menampilkan stok sekarang dan stok setelah transaksi,
    * tombol simpan terkunci bila pengeluaran melebihi stok,
    * No. BA ganda untuk dokumen yang sama ditolak,
    * isian tidak hilang bila ada kesalahan.
- Verifikasi penerimaan oleh superadmin (satu per satu atau centang banyak sekaligus).
- Saring riwayat per dokumen, status/tujuan, dan rentang tanggal, lalu unduh CSV sesuai saringan.
  CSV memakai pemisah titik koma agar langsung rapi di Excel berbahasa Indonesia.
- Tabel bisa diurutkan dengan klik judul kolom; tabel stok bisa disaring per status.
- Buku harian dokumen: navigasi bulan, ringkasan saldo, opsi tampilkan hari bermutasi saja, cetak.
- Opname: kolom stok fisik + selisih otomatis, nilai selisih rupiah, draf tersimpan di browser,
  cetak berita acara dengan tanda tangan.
- Sesi: hitung mundur di bar atas dan tombol "Perpanjang" 5 menit sebelum habis.
- Pengguna: ubah role, cegah menghapus superadmin terakhir, password minimal 8 karakter.

PERBAIKAN
- Username baru disimpan huruf kecil (sebelumnya user berhuruf kapital tidak bisa login).
- Menghapus penerimaan yang sebagian sudah dikeluarkan ditolak agar stok tidak minus.
- Pengeluaran dicek ulang di dalam transaksi database (aman bila dua petugas menyimpan bersamaan).
- Validasi tanggal, jumlah, jenis dokumen, tujuan, dan panjang teks di sisi server.
- Kode dokumen ganda tidak lagi memunculkan error 500.
- Bulan default buku harian = bulan berjalan; nama bulan dalam bahasa Indonesia.
- Nilai persediaan ditulis "M" untuk miliar (sebelumnya angka juta diberi label M).

PENGATURAN TAMBAHAN (config.php)
  APP_KOTA     kota di tanda tangan laporan opname (bawaan: Jakarta)
  STOK_KRITIS  batas stok kritis (bawaan: 100)
  STOK_RENDAH  batas stok rendah (bawaan: 200)
  HARI_RATA    jendela hari untuk menghitung rata-rata pemakaian (bawaan: 90)

Catatan: font (IBM Plex) dan grafik (Chart.js) dimuat dari internet. Tanpa internet,
aplikasi tetap jalan dengan font sistem dan grafik digantikan pesan singkat.
