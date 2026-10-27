<?php
/**
 * Cek apakah rentang nomor seri (awal–akhir) cocok dengan jumlah yang diisi.
 * Contoh: awal 8098251, akhir 8099250 → 1.000 lembar. Kalau jumlah diisi 1.000 → cocok.
 * Nomor yang berawalan huruf juga bisa dicek (mis. SKA0019301 – SKA0019360 = 60), awalan harus sama.
 * Mengembalikan null kalau cocok ATAU tidak bisa diperiksa (nomor tanpa angka di ujung, mis. "-");
 * selain itu mengembalikan kalimat penjelasan yang siap ditampilkan ke pengguna.
 */
if (!function_exists('seri_cek')) {
function seri_cek(string $awal, string $akhir, int $jumlah): ?string {
  $a = seri_pecah($awal); $z = seri_pecah($akhir);
  if (!$a || !$z) return null;                                        // bukan nomor berangka → tidak bisa dicek
  if (strcasecmp(trim($a['prefix']), trim($z['prefix'])) !== 0) return 'Awalan nomor seri awal ("' . trim($a['prefix']) . '") dan akhir ("' . trim($z['prefix']) . '") harus sama.';
  $an = ltrim($a['num'], '0'); $zn = ltrim($z['num'], '0');
  if (strlen($an) > 17 || strlen($zn) > 17) return null;              // terlalu panjang untuk dihitung dengan aman
  $an = (int)$an; $zn = (int)$zn;
  if ($zn < $an) return 'Nomor seri akhir (' . $akhir . ') lebih kecil dari nomor seri awal (' . $awal . ').';
  $isi = $zn - $an + 1;
  if ($isi === $jumlah) return null;
  $harusnya = $an + $jumlah - 1;
  $akhirBenar = trim($a['prefix']) . str_pad((string)$harusnya, strlen($a['num']), '0', STR_PAD_LEFT);
  return 'Rentang seri tidak sesuai jumlah: ' . $awal . ' sampai ' . $akhir . ' berisi ' . number_format($isi, 0, ',', '.') . ', sedangkan jumlah yang diisi ' . number_format($jumlah, 0, ',', '.') . '. Kalau jumlahnya benar, seri akhir seharusnya ' . $akhirBenar . '.';
}
}
