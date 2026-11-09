<?php
/* Arsip transaksi tahun lama: tabel terpisah, TIDAK mengubah stok / nomor seri saat ini. */
function arsip_siapkan(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS arsip_transaksi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tahun SMALLINT NOT NULL,
    doc_key VARCHAR(120) NOT NULL,
    dokumen_id INT NULL,
    kode_arsip VARCHAR(30) NULL,
    nama_arsip VARCHAR(100) NULL,
    jenis ENUM('penerimaan','pengeluaran') NOT NULL,
    tanggal DATE NOT NULL,
    jumlah INT NOT NULL,
    no_ba VARCHAR(80) NULL,
    tujuan VARCHAR(80) NULL,
    seri_awal VARCHAR(40) NULL,
    seri_akhir VARCHAR(40) NULL,
    sumber VARCHAR(100) NULL,
    dibuat DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_th (tahun, doc_key),
    KEY idx_tgl (tanggal)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function arsip_kunci_dok(?int $dokumenId, string $kode): string {
  return $dokumenId ? 'D' . $dokumenId : 'N:' . strtoupper(trim($kode));
}
