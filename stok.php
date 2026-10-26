<?php require 'config.php'; login_required(); $title = 'Stok dokumen';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  super_required(); csrf_check();
  $act = $_POST['act'] ?? '';
  if ($act === 'add') {
    $kode  = strtoupper(trim((string)($_POST['kode'] ?? '')));
    $nama  = trim((string)($_POST['nama'] ?? ''));
    $sat   = strtoupper(trim(preg_replace('/\s+/', ' ', (string)($_POST['satuan'] ?? ''))));   // satuan bebas diketik admin
    $harga = (int)($_POST['harga'] ?? -1);
    if ($kode === '' || $nama === '' || strlen($kode) > 20 || mb_strlen($nama) > 100 || $harga < 0) {
      flash('Kode, nama, dan harga wajib diisi. Kode maks. 20 karakter, harga tidak boleh negatif.', 'err');
    } elseif (!preg_match('#^[A-Z0-9][A-Z0-9 ./-]{0,29}$#', $sat)) {
      flash('Satuan wajib diisi (maks. 30 karakter), hanya huruf, angka, spasi, titik, garis miring, atau minus. Contoh: LEMBAR, BUKU, ROLL.', 'err');
    } else {
      try {
        $pdo->prepare('INSERT INTO dokumen(kode,nama,satuan,harga) VALUES(?,?,?,?)')->execute([$kode, $nama, $sat, $harga]);
        flash("Jenis dokumen $kode ditambahkan. Stok awal 0, catat penerimaan untuk mengisinya.");
      } catch (PDOException $x) {
        if ((int)($x->errorInfo[1] ?? 0) === 1062) flash("Kode $kode sudah dipakai dokumen lain.", 'err');
        else flash('Gagal menyimpan. Kalau satuannya baru, jalankan dulu migrasi_satuan_bebas.sql lewat phpMyAdmin.', 'err');
      }
    }
  }
  if ($act === 'del') {
    try { $pdo->prepare('DELETE FROM dokumen WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]); flash('Jenis dokumen dihapus.'); }
    catch (PDOException $x) { flash('Tidak dapat dihapus karena dokumen ini sudah punya transaksi.', 'err'); }
  }
  if ($act === 'reset_stok') {
    $id = (int)($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT kode FROM dokumen WHERE id=?'); $s->execute([$id]); $kode = $s->fetchColumn();
    if ($kode) {
      $pdo->prepare('UPDATE dokumen SET stok=0 WHERE id=?')->execute([$id]);
      flash("Stok $kode direset ke 0. Catat penerimaan untuk memasukkan data stok yang baru.");
    } else {
      flash('Dokumen tidak ditemukan.', 'err');
    }
  }
  back('stok.php');
}

$rows = $pdo->query('SELECT d.*, (SELECT COUNT(*) FROM penerimaan WHERE dokumen_id=d.id) + (SELECT COUNT(*) FROM pengeluaran WHERE dokumen_id=d.id) trx FROM dokumen d ORDER BY d.nama')->fetchAll();
$rata = pemakaian($pdo);
$hit  = ['aman' => 0, 'rendah' => 0, 'kritis' => 0, 'habis' => 0];
$nilai = 0;
foreach ($rows as &$r) {
  $r['st'] = status_stok($r['stok'], $rata[$r['id']] ?? 0);
  $hit[$r['st'][1]]++;
  $nilai += $r['stok'] * $r['harga'];
} unset($r);

if (isset($_GET['export'])) {
  super_required();
  csv_out('stok-dokumen-' . date('Ymd') . '.csv',
    ['Kode', 'Jenis Dokumen', 'Satuan', 'Stok', 'Status', 'Perkiraan Habis (hari)', 'Seri Aktif', 'Seri Tersimpan', 'Terbit', 'Rusak', 'Harga', 'Total Nilai'],
    array_map(fn($r) => [$r['kode'], $r['nama'], $r['satuan'], $r['stok'], $r['st'][0], $r['st'][2] ?? '', $r['seri_aktif'], $r['seri_simpan'], $r['terbit'], $r['rusak'], $r['harga'], $r['stok'] * $r['harga']], $rows));
}
include 'partials/header.php'; ?>

<?php if (is_super()): ?>
<details class="drawer">
  <summary>Tambah jenis dokumen</summary>
  <form method="post" class="grid-form panel-b">
    <?= csrf_field() ?><input type="hidden" name="act" value="add">
    <div><label for="a-kode">Kode <span class="req">*</span></label><input id="a-kode" name="kode" maxlength="20" class="mono" placeholder="mis. EP-48" required autocapitalize="characters"></div>
    <div class="span2"><label for="a-nama">Nama dokumen <span class="req">*</span></label><input id="a-nama" name="nama" maxlength="100" placeholder="mis. E-Paspor 48 Halaman" required></div>
    <div><label for="a-sat">Satuan <span class="req">*</span></label><input id="a-sat" name="satuan" list="a-sat-list" maxlength="30" value="LEMBAR" placeholder="mis. LEMBAR, BUKU, ROLL" autocomplete="off" autocapitalize="characters" required>
      <datalist id="a-sat-list"><option value="LEMBAR"><option value="BUKU"><option value="KEPING"><option value="BUAH"><option value="ROLL"><option value="PAK"><option value="BOX"><option value="SET"></datalist>
      <div class="hint">Pilih dari daftar atau ketik sendiri kalau satuannya belum ada.</div></div>
    <div><label for="a-harga">Harga satuan (Rp) <span class="req">*</span></label><input id="a-harga" type="number" min="0" name="harga" inputmode="numeric" required></div>
    <div class="full"><button type="submit" class="btn">Simpan jenis dokumen</button></div>
  </form>
</details>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <div><h2>Stok gudang dan persediaan</h2>
      <p><span data-count="#tbl-stok"><?= count($rows) ?></span> dari <?= count($rows) ?> jenis ditampilkan. Nilai persediaan total <?= rp($nilai) ?>. Klik judul kolom untuk mengurutkan.</p></div>
    <?php if (is_super()): ?><a class="btn sm ghost" href="?export=1">Unduh CSV (Excel)</a><?php endif; ?>
  </div>
  <div class="toolbar">
    <div class="grow"><label for="cari">Saring</label><input id="cari" type="search" data-filter="#tbl-stok" placeholder="Nama, kode, atau nomor seri"></div>
    <div><label>Status</label>
      <div class="chips" data-chips="#tbl-stok">
        <button type="button" class="chip on" data-v="" aria-pressed="true">Semua<em><?= count($rows) ?></em></button>
        <?php foreach (['habis' => 'Habis', 'kritis' => 'Kritis', 'rendah' => 'Rendah', 'aman' => 'Aman'] as $k => $l): if (!$hit[$k]) continue; ?>
          <button type="button" class="chip" data-v="<?= $k ?>" aria-pressed="false"><?= $l ?><em><?= $hit[$k] ?></em></button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="tbl-wrap"><table id="tbl-stok">
    <thead><tr>
      <th data-sort>Kode</th><th data-sort>Dokumen</th><th data-sort="num" class="num">Stok</th><th data-sort>Status</th>
      <th data-sort="num">Perkiraan habis</th><th>Seri aktif / tersimpan</th>
      <th data-sort="num" class="num">Terbit</th><th data-sort="num" class="num">Rusak</th><th data-sort="num" class="num">Harga</th><th data-sort="num" class="num">Nilai</th>
      <?php if (is_super()): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-status="<?= $r['st'][1] ?>">
        <td class="mono nowrap"><?= e($r['kode']) ?></td>
        <td class="doc"><a href="stok_detail.php?id=<?= $r['id'] ?>"><?= e($r['nama']) ?></a><div class="sub"><?= strtolower($r['satuan']) ?></div></td>
        <td class="num"><?= num($r['stok']) ?></td>
        <td data-v="<?= ['habis' => 0, 'kritis' => 1, 'rendah' => 2, 'aman' => 3][$r['st'][1]] ?>"><?= badge_stok($r['st']) ?></td>
        <td class="nowrap" data-v="<?= $r['st'][2] ?? 999999 ?>"><?= hari_txt($r['st'][2]) ?></td>
        <td class="mono nowrap"><?= e($r['seri_aktif']) ?><div class="sub"><?= e($r['seri_simpan']) ?></div></td>
        <td class="num"><?= num($r['terbit']) ?></td>
        <td class="num"><?= num($r['rusak']) ?></td>
        <td class="num"><?= rp($r['harga']) ?></td>
        <td class="num"><?= rp($r['stok'] * $r['harga']) ?></td>
        <?php if (is_super()): ?><td class="r nowrap">
          <form method="post" class="inline" data-confirm="Reset stok <?= e($r['kode']) ?> ke 0? Angka stok saat ini (<?= num($r['stok']) ?> <?= strtolower($r['satuan']) ?>) akan hilang dan tidak bisa dikembalikan. Catat penerimaan sesudahnya untuk memasukkan data stok yang baru.">
            <?= csrf_field() ?><input type="hidden" name="act" value="reset_stok"><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn sm ghost" title="Reset stok dokumen ini ke 0">Reset stok</button>
          </form>
          <?php if ($r['trx']): ?><span class="muted" title="Sudah punya <?= $r['trx'] ?> transaksi">—</span>
          <?php else: ?><form method="post" class="inline" data-confirm="Hapus jenis dokumen <?= e($r['kode']) ?>?"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn sm danger">Hapus</button></form><?php endif; ?>
        </td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr class="empty-row"><td colspan="11"><div class="empty"><b>Belum ada jenis dokumen</b><?= is_super() ? 'Tambahkan lewat tombol "Tambah jenis dokumen" di atas.' : 'Minta superadmin menambahkan jenis dokumen.' ?></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php include 'partials/footer.php'; ?>