<?php require 'config.php'; login_required(); $title = 'Kartu stok harian';

$dokumen = $pdo->query('SELECT id,kode,kode_resmi,nama,satuan FROM dokumen ORDER BY nama')->fetchAll();
if (!$dokumen) { flash('Belum ada dokumen terdaftar.', 'err'); back('index.php'); }

$tahunTersedia = $pdo->query('SELECT DISTINCT YEAR(tanggal) y FROM kartu_stok_harian ORDER BY y')->fetchAll(PDO::FETCH_COLUMN);
if (!$tahunTersedia) $tahunTersedia = [(int)date('Y')];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'simpan_ledger') {
  super_required();
  csrf_check();
  $retId  = (int)($_POST['id'] ?? 0);
  $retBln = preg_match('/^\d{4}-(0[1-9]|1[0-2]|ALL)$/', $_POST['bln'] ?? '') ? $_POST['bln'] : (end($tahunTersedia) . '-ALL');
  $upd = $pdo->prepare('UPDATE kartu_stok_harian SET terbit=?, terbit_kanim=?, terbit_ulp=?, rusak=?, catpro=? WHERE id=? AND dokumen_id=?');
  $n = 0;
  foreach (($_POST['kanim'] ?? []) as $rowId => $vKanim) {
    $rowId   = (int)$rowId;
    $vKanim  = max(0, (int)$vKanim);
    $vUlp    = max(0, (int)($_POST['ulp'][$rowId]    ?? 0));
    $vRusak  = max(0, (int)($_POST['rusak'][$rowId]  ?? 0));
    $vCatpro = max(0, (int)($_POST['catpro'][$rowId] ?? 0));
    $upd->execute([$vKanim + $vUlp, $vKanim, $vUlp, $vRusak, $vCatpro, $rowId, $retId]);
    $n++;
  }
  flash("Perubahan Terbit (Kanim/ULP), Rusak, dan Catpro disimpan ($n baris).");
  back("kartu-stok.php?id=$retId&bln=$retBln");
}

$id = (int)($_GET['id'] ?? $dokumen[0]['id']);
$cek = array_filter($dokumen, fn($d) => (int)$d['id'] === $id);
if (!$cek) { $id = (int)$dokumen[0]['id']; }
$s = $pdo->prepare('SELECT * FROM dokumen WHERE id=?'); $s->execute([$id]); $d = $s->fetch();

$tahunDefault = (string)end($tahunTersedia);
if (preg_match('/^(\d{4})-(0[1-9]|1[0-2]|ALL)$/', $_GET['bln'] ?? '', $m)) {
  $tahun = $m[1]; $bulanSel = $m[2];
} else {
  $tahun = $tahunDefault; $bulanSel = 'ALL';
}
if (!in_array((int)$tahun, $tahunTersedia)) $tahun = $tahunDefault;
$bln = "$tahun-$bulanSel";
$semuaBulan = $bulanSel === 'ALL';

if ($semuaBulan) {
  $awalRentang  = "$tahun-01-01";
  $akhirRentang = "$tahun-12-31";
} else {
  $awalRentang  = "$tahun-$bulanSel-01";
  $akhirRentang = date('Y-m-t', strtotime($awalRentang));
}
$prevYm = date('Y-m', strtotime("$tahun-$bulanSel-01 -1 month"));
$nextYm = date('Y-m', strtotime("$tahun-$bulanSel-01 +1 month"));

$q = $pdo->prepare('SELECT * FROM kartu_stok_harian WHERE dokumen_id=? AND tanggal BETWEEN ? AND ? ORDER BY tanggal');
$q->execute([$id, $awalRentang, $akhirRentang]);
$rows = $q->fetchAll();

/* No. seri dari tabel pengeluaran/penerimaan pada tanggal yang sama */
$seriKel = []; $seriTer = [];
$sq = $pdo->prepare('SELECT tanggal, seri_awal, seri_akhir FROM pengeluaran WHERE dokumen_id=? AND tanggal BETWEEN ? AND ? AND seri_awal IS NOT NULL ORDER BY id');
$sq->execute([$id, $awalRentang, $akhirRentang]);
foreach ($sq as $x) $seriKel[$x['tanggal']][] = $x['seri_awal'] . ($x['seri_akhir'] && $x['seri_akhir'] !== $x['seri_awal'] ? ' – ' . $x['seri_akhir'] : '');
$sq = $pdo->prepare('SELECT tanggal, seri_awal, seri_akhir FROM penerimaan WHERE dokumen_id=? AND tanggal BETWEEN ? AND ? AND seri_awal IS NOT NULL ORDER BY id');
$sq->execute([$id, $awalRentang, $akhirRentang]);
foreach ($sq as $x) $seriTer[$x['tanggal']][] = $x['seri_awal'] . ($x['seri_akhir'] && $x['seri_akhir'] !== $x['seri_awal'] ? ' – ' . $x['seri_akhir'] : '');

/* Rekap per bulan untuk tahun terpilih + peta stok per tanggal (untuk opname cepat) */
$qt = $pdo->prepare('SELECT * FROM kartu_stok_harian WHERE dokumen_id=? AND YEAR(tanggal)=? ORDER BY tanggal');
$qt->execute([$id, $tahun]); $barisTahun = $qt->fetchAll();
$perBulan = []; $petaStok = []; $rusakKum = 0;
foreach ($barisTahun as $r) {
  $m = (int)substr($r['tanggal'], 5, 2);
  if (!isset($perBulan[$m])) $perBulan[$m] = ['pen' => 0, 'kel' => 0, 'kanim' => 0, 'ulp' => 0, 'rusak' => 0, 'catpro' => 0, 'akhir' => 0];
  $perBulan[$m]['pen'] += (int)$r['penerimaan_jumlah']; $perBulan[$m]['kel'] += (int)$r['pengeluaran_jumlah'];
  $perBulan[$m]['kanim'] += (int)$r['terbit_kanim']; $perBulan[$m]['ulp'] += (int)$r['terbit_ulp']; $perBulan[$m]['rusak'] += (int)$r['rusak']; $perBulan[$m]['catpro'] += (int)$r['catpro'];
  $perBulan[$m]['akhir'] = (int)$r['stok'];
  $rusakKum += (int)$r['rusak'];
  $petaStok[$r['tanggal']] = [(int)$r['stok'], $rusakKum];
}

if (($_GET['act'] ?? '') === 'unduh_csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="kartu-stok-' . $d['kode'] . '-' . $bln . '.csv"');
  $out = fopen('php://output', 'w');
  fputs($out, "\xEF\xBB\xBF"); // BOM biar angka/teks tampil benar di Excel
  fputcsv($out, ['Tanggal','Stok','Penerimaan','No. BA Terima','Pengeluaran','Tujuan','No. BA Keluar','Terbit Kanim','Terbit ULP','Rusak','Catpro','Nilai','No. Seri Keluar','No. Seri Terima']);
  foreach ($rows as $r) {
    fputcsv($out, [$r['tanggal'], $r['stok'], $r['penerimaan_jumlah'], $r['penerimaan_no_ba'], $r['pengeluaran_jumlah'], $r['pengeluaran_tujuan'], $r['pengeluaran_no_ba'], $r['terbit_kanim'], $r['terbit_ulp'], $r['rusak'], $r['catpro'], $r['total_harga'], implode(' | ', $seriKel[$r['tanggal']] ?? []), implode(' | ', $seriTer[$r['tanggal']] ?? [])]);
  }
  fclose($out);
  exit;
}

$totPen    = array_sum(array_column($rows, 'penerimaan_jumlah'));
$totKel    = array_sum(array_column($rows, 'pengeluaran_jumlah'));
$totKanim  = array_sum(array_column($rows, 'terbit_kanim'));
$totUlp    = array_sum(array_column($rows, 'terbit_ulp'));
$totRusak  = array_sum(array_column($rows, 'rusak'));
$totCatpro = array_sum(array_column($rows, 'catpro'));
$stokAkhir = $rows ? (int)end($rows)['stok'] : (int)$d['stok'];
$editMode  = is_super() && ($_GET['edit'] ?? '') === '1' && !$semuaBulan;

include 'partials/header.php'; ?>

<p class="crumb noprint"><a href="stok_detail.php?id=<?= $id ?>"><?= e($d['nama']) ?></a><span>/</span>Kartu stok harian</p>

<section class="panel noprint">
  <div class="panel-h">
    <div><h2>Kartu stok harian — <?= e($d['nama']) ?> <span class="tag mono"><?= $d['kode_resmi'] ? 'KODE ' . (int)$d['kode_resmi'] : e($d['kode']) ?></span></h2>
      <p>Riwayat harian resmi, diimpor dari database opname fisik induk. Setiap baris menunjukkan sisa stok, penerimaan, pengeluaran, terbit (Kanim/ULP), dan rusak pada tanggal tersebut.</p></div>
    <div class="actions">
      <form method="get" class="inline"><label class="check" for="ks-dok">Dokumen</label>
        <select id="ks-dok" name="id" onchange="this.form.submit()">
          <?php foreach ($dokumen as $dd): ?>
            <option value="<?= $dd['id'] ?>" <?= (int)$dd['id'] === $id ? 'selected' : '' ?>><?= e($dd['nama']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="bln" value="<?= e($bln) ?>">
      </form>
    </div>
  </div>
</section>

<section class="panel">
  <div class="panel-h">
    <div><h2>Mutasi <?= $semuaBulan ? "Seluruh tahun $tahun" : bulan_txt($bln) ?></h2><p>Data sebagaimana tercatat di berkas opname fisik bulanan.</p></div>
    <div class="actions noprint">
      <form method="get" class="inline">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label class="check" for="ks-bulan">Bulan</label>
        <select id="ks-bulan" name="bulan_sel" onchange="kartuStokPilihPeriode(this.form)">
          <option value="ALL" <?= $semuaBulan ? 'selected' : '' ?>>Semua bulan</option>
          <?php foreach (NAMA_BULAN as $i => $nm): $mm = sprintf('%02d', $i + 1); ?>
            <option value="<?= $mm ?>" <?= (!$semuaBulan && $bulanSel === $mm) ? 'selected' : '' ?>><?= $nm ?></option>
          <?php endforeach; ?>
        </select>
        <label class="check" for="ks-tahun">Tahun</label>
        <select id="ks-tahun" name="tahun_sel" onchange="kartuStokPilihPeriode(this.form)">
          <?php foreach ($tahunTersedia as $ty): ?>
            <option value="<?= $ty ?>" <?= (string)$ty === $tahun ? 'selected' : '' ?>><?= $ty ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" id="ks-bln-hidden" name="bln" value="<?= e($bln) ?>">
      </form>
      <?php if (!$semuaBulan): ?>
        <a class="btn sm ghost" href="?id=<?= $id ?>&amp;bln=<?= $prevYm ?>" aria-label="Bulan sebelumnya">‹</a>
        <a class="btn sm ghost" href="?id=<?= $id ?>&amp;bln=<?= $nextYm ?>" aria-label="Bulan berikutnya">›</a>
      <?php endif; ?>
      <a class="btn sm ghost" href="?id=<?= $id ?>&amp;bln=<?= e($bln) ?>&amp;act=unduh_csv">Unduh CSV</a>
      <button type="button" class="btn sm ghost" onclick="print()">Cetak</button>
      <?php if (is_super() && !$semuaBulan): ?>
        <?php if ($editMode): ?>
          <a class="btn sm ghost" href="?id=<?= $id ?>&amp;bln=<?= $bln ?>">Selesai edit</a>
        <?php else: ?>
          <a class="btn sm" href="?id=<?= $id ?>&amp;bln=<?= $bln ?>&amp;edit=1">Edit Terbit/Rusak/Catpro</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="strip inset">
    <div><small>Penerimaan</small><b class="in">+<?= num($totPen) ?></b><em><?= strtolower($d['satuan']) ?></em></div>
    <div><small>Pengeluaran</small><b class="out">−<?= num($totKel) ?></b><em><?= strtolower($d['satuan']) ?></em></div>
    <div><small>Terbit — Kanim</small><b><?= num($totKanim) ?></b></div>
    <div><small>Terbit — ULP</small><b><?= num($totUlp) ?></b></div>
    <div><small>Rusak</small><b><?= num($totRusak) ?></b></div>
    <div><small>Catpro</small><b><?= num($totCatpro) ?></b></div>
    <div><small>Stok akhir</small><b><?= num($stokAkhir) ?></b></div>
  </div>
  <?php if ($semuaBulan): ?><p class="muted noprint">Menampilkan seluruh tahun <?= $tahun ?> (<?= count($rows) ?> baris) — cocok untuk dicetak atau diunduh sebagai satu laporan utuh. Mode edit tidak tersedia di tampilan "Semua bulan"; pilih bulan tertentu dulu untuk mengoreksi angka.</p><?php endif; ?>
  <?php if ($editMode): ?><p class="muted noprint">Mode edit aktif — ubah angka Terbit Kanim, Terbit ULP, Rusak, dan/atau Catpro pada baris yang perlu dikoreksi, lalu klik <b>Simpan perubahan</b> di bawah tabel. Baris lain yang tidak diubah tetap aman.</p><?php endif; ?>
  <div class="toolbar noprint">
    <label class="check"><input type="checkbox" id="ks-hanya"> Hanya hari yang ada transaksi atau terbit</label>
    <a class="btn sm ghost" href="mutasi.php?tahun=<?= (int)$tahun ?>">Mutasi semua dokumen</a>
  </div>
  <form method="post" <?= $editMode ? '' : 'class="noedit"' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="simpan_ledger">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="bln" value="<?= e($bln) ?>">
    <div class="tbl-wrap"><table class="ledger">
      <thead><tr><th>Tanggal</th><th class="num">Stok</th><th class="num">Terima</th><th>No. BA terima</th><th class="num">Keluar</th><th>Tujuan</th><th>No. BA keluar</th><th>No. seri</th><th class="num">Terbit Kanim</th><th class="num">Terbit ULP</th><th class="num">Rusak</th><th class="num">Catpro</th><th class="num">Nilai</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="empty-row"><td colspan="13"><div class="empty"><b>Belum ada data kartu stok</b>Tidak ditemukan riwayat harian untuk dokumen dan periode ini.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $w = (int)date('w', strtotime($r['tanggal']));
        $mut = $r['penerimaan_jumlah'] || $r['pengeluaran_jumlah']; $aktif = $mut || $r['terbit_kanim'] || $r['terbit_ulp'] || $r['rusak']; ?>
        <tr data-aktif="<?= $aktif ? 1 : 0 ?>" class="<?= $mut ? '' : 'idle' ?><?= $w === 0 || $w === 6 ? ' wkend' : '' ?>">
          <td class="day"><?= $semuaBulan ? date('j M', strtotime($r['tanggal'])) : (int)date('j', strtotime($r['tanggal'])) ?><?php if (!$semuaBulan): ?><small><?= substr(NAMA_HARI[$w], 0, 3) ?></small><?php endif; ?></td>
          <td class="num"><?= num($r['stok']) ?></td>
          <td class="num <?= $r['penerimaan_jumlah'] ? 'in' : '' ?>"><?= $r['penerimaan_jumlah'] ? '+' . num($r['penerimaan_jumlah']) : '–' ?></td>
          <td class="mono small"><?= e($r['penerimaan_no_ba'] ?? '') ?></td>
          <td class="num <?= $r['pengeluaran_jumlah'] ? 'out' : '' ?>"><?= $r['pengeluaran_jumlah'] ? '−' . num($r['pengeluaran_jumlah']) : '–' ?></td>
          <td class="small"><?= e($r['pengeluaran_tujuan'] ?? '') ?></td>
          <td class="mono small"><?= e($r['pengeluaran_no_ba'] ?? '') ?></td>
          <td class="mono small"><?php foreach (($seriKel[$r['tanggal']] ?? []) as $sk): ?><div>keluar: <?= e($sk) ?></div><?php endforeach; foreach (($seriTer[$r['tanggal']] ?? []) as $st): ?><div>terima: <?= e($st) ?></div><?php endforeach; ?></td>
          <?php if ($editMode): ?>
            <td class="num"><input type="number" min="0" step="1" class="ledger-input" name="kanim[<?= $r['id'] ?>]" value="<?= (int)$r['terbit_kanim'] ?>"></td>
            <td class="num"><input type="number" min="0" step="1" class="ledger-input" name="ulp[<?= $r['id'] ?>]" value="<?= (int)$r['terbit_ulp'] ?>"></td>
            <td class="num"><input type="number" min="0" step="1" class="ledger-input" name="rusak[<?= $r['id'] ?>]" value="<?= (int)$r['rusak'] ?>"></td>
            <td class="num"><input type="number" min="0" step="1" class="ledger-input" name="catpro[<?= $r['id'] ?>]" value="<?= (int)$r['catpro'] ?>"></td>
          <?php else: ?>
            <td class="num"><?= $r['terbit_kanim'] ? num($r['terbit_kanim']) : '–' ?></td>
            <td class="num"><?= $r['terbit_ulp'] ? num($r['terbit_ulp']) : '–' ?></td>
            <td class="num"><?= $r['rusak'] ? num($r['rusak']) : '–' ?></td>
            <td class="num"><?= $r['catpro'] ? num($r['catpro']) : '–' ?></td>
          <?php endif; ?>
          <td class="num"><?= $r['total_harga'] ? rp($r['total_harga']) : '–' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($editMode && $rows): ?>
      <div class="actions noprint" style="margin-top:12px"><button type="submit" class="btn">Simpan perubahan</button></div>
    <?php endif; ?>
  </form>
</section>

<section class="panel noprint">
  <div class="panel-h"><div><h2>Rekap per bulan — <?= e($d['nama']) ?> <?= (int)$tahun ?></h2><p>Terima, keluar, terbit Kanim/ULP, rusak, dan stok akhir tiap bulan. Klik nama bulan untuk melihat rinciannya.</p></div></div>
  <div class="tbl-wrap"><table class="ledger">
    <thead><tr><th>Bulan</th><th class="num">Terima</th><th class="num">Keluar</th><th class="num">Terbit Kanim</th><th class="num">Terbit ULP</th><th class="num">Rusak</th><th class="num">Catpro</th><th class="num">Stok akhir</th></tr></thead>
    <tbody>
    <?php foreach ($perBulan as $mi => $pb): ?>
      <tr><td><a href="?id=<?= $id ?>&amp;bln=<?= (int)$tahun ?>-<?= sprintf('%02d', $mi) ?>"><?= e(NAMA_BULAN[$mi - 1]) ?></a></td>
        <td class="num"><?= $pb['pen'] ? '+' . num($pb['pen']) : '–' ?></td><td class="num"><?= $pb['kel'] ? '−' . num($pb['kel']) : '–' ?></td>
        <td class="num"><?= $pb['kanim'] ? num($pb['kanim']) : '–' ?></td><td class="num"><?= $pb['ulp'] ? num($pb['ulp']) : '–' ?></td>
        <td class="num"><?= $pb['rusak'] ? num($pb['rusak']) : '–' ?></td><td class="num"><?= $pb['catpro'] ? num($pb['catpro']) : '–' ?></td><td class="num"><b><?= num($pb['akhir']) ?></b></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel noprint">
  <div class="panel-h"><div><h2>Opname cepat</h2><p>Pilih tanggal, ketik jumlah hasil hitung fisik, selisihnya langsung terlihat. Untuk semua dokumen sekaligus dan berita acara, pakai halaman <a href="opname.php">Opname fisik</a>.</p></div></div>
  <div class="panel-b"><div class="grid-form">
    <div><label for="oc-tgl">Tanggal</label><input id="oc-tgl" type="date" min="<?= (int)$tahun ?>-01-01" max="<?= (int)$tahun ?>-12-31" value="<?= e(min(date('Y-m-d'), (int)$tahun . '-12-31')) ?>"></div>
    <div><label>Stok sistem</label><div class="mono" id="oc-sis" style="padding:8px 0;font-size:1.2em">–</div></div>
    <div><label for="oc-fis">Stok fisik</label><input id="oc-fis" type="number" min="0" inputmode="numeric"></div>
    <div><label>Selisih</label><div class="mono" id="oc-sel" style="padding:8px 0;font-size:1.2em">–</div></div>
    <div><label>Rusak (sampai tanggal itu)</label><div class="mono" id="oc-rus" style="padding:8px 0">–</div></div>
    <div><label>&nbsp;</label><a class="btn sm" id="oc-lnk" href="opname.php">Buka lembar opname</a></div>
  </div></div>
</section>

<script>
const KS_STOK = <?= json_encode($petaStok) ?>;
(function () {
  const t = document.getElementById('oc-tgl'), f = document.getElementById('oc-fis');
  function hitung() {
    let d = t.value, v = KS_STOK[d];
    if (!v && d) { const ks = Object.keys(KS_STOK).filter(k => k <= d).sort(); if (ks.length) v = KS_STOK[ks[ks.length - 1]]; }
    document.getElementById('oc-sis').textContent = v ? v[0].toLocaleString('id-ID') : '–';
    document.getElementById('oc-rus').textContent = v ? v[1].toLocaleString('id-ID') : '–';
    const sel = document.getElementById('oc-sel');
    if (v && f.value !== '') { const s = (+f.value) - v[0]; sel.textContent = (s > 0 ? '+' : '') + s.toLocaleString('id-ID'); sel.style.color = s === 0 ? '' : '#b42318'; }
    else { sel.textContent = '–'; sel.style.color = ''; }
    document.getElementById('oc-lnk').href = 'opname.php?tgl=' + encodeURIComponent(d);
  }
  t.addEventListener('input', hitung); f.addEventListener('input', hitung); hitung();
  const h = document.getElementById('ks-hanya');
  h.addEventListener('change', function () {
    document.querySelectorAll('table.ledger tbody tr[data-aktif]').forEach(tr => { tr.hidden = h.checked && tr.dataset.aktif === '0'; });
  });
})();
function kartuStokPilihPeriode(form) {
  const b = document.getElementById('ks-bulan').value;
  const t = document.getElementById('ks-tahun').value;
  document.getElementById('ks-bln-hidden').value = t + '-' + b;
  form.submit();
}
</script>

<?php include 'partials/footer.php'; ?>