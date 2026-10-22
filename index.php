<?php require 'config.php'; login_required(); $title = 'Ringkasan';

/* ---------- Stok & status per dokumen ---------- */
$dok  = $pdo->query('SELECT * FROM dokumen ORDER BY nama')->fetchAll();
$rata = pemakaian($pdo);
$tot  = ['stok' => 0, 'nilai' => 0];
$perlu = [];
foreach ($dok as $d) {
  $tot['stok']  += $d['stok'];
  $tot['nilai'] += $d['stok'] * $d['harga'];
  $st = status_stok($d['stok'], $rata[$d['id']] ?? 0);
  if ($st[1] !== 'aman') $perlu[] = $d + ['st' => $st];
}
$urut = ['habis' => 0, 'kritis' => 1, 'rendah' => 2];
usort($perlu, fn($a, $b) => [$urut[$a['st'][1]], $a['stok']] <=> [$urut[$b['st'][1]], $b['stok']]);

/* ---------- Status stok seluruh dokumen (untuk grafik) ---------- */
$statusTally = ['aman' => 0, 'rendah' => 0, 'kritis' => 0, 'habis' => 0];
foreach ($dok as $d) {
  $st = status_stok($d['stok'], $rata[$d['id']] ?? 0);
  $statusTally[$st[1]]++;
}

/* ---------- Nilai persediaan per dokumen (top 6 + Lainnya) ---------- */
$nilaiPerDok = array_map(fn($d) => ['nama' => $d['nama'], 'nilai' => (int)$d['stok'] * (int)$d['harga']], $dok);
usort($nilaiPerDok, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
$chartNilai = array_slice($nilaiPerDok, 0, 6);
$sisaNilai  = array_sum(array_column(array_slice($nilaiPerDok, 6), 'nilai'));
if ($sisaNilai > 0) $chartNilai[] = ['nama' => 'Lainnya', 'nilai' => $sisaNilai];

/* ---------- Filter periode (dari - sampai), berlaku untuk mutasi, tren, tujuan, terbit/rusak & aktivitas ---------- */
$base = new DateTime(date('Y-m-01'));
$adaBaru = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM penerimaan WHERE tanggal >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01'))
                              + (SELECT COUNT(*) FROM pengeluaran WHERE tanggal >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01'))")->fetchColumn();
if (!$adaBaru) {
  $mx = $pdo->query("SELECT GREATEST(COALESCE((SELECT MAX(tanggal) FROM penerimaan),'1970-01-01'), COALESCE((SELECT MAX(tanggal) FROM pengeluaran),'1970-01-01'))")->fetchColumn();
  if ($mx > '1970-01-01') $base = new DateTime(substr($mx, 0, 7) . '-01');
}
$defaultDari   = (clone $base)->modify('-5 month')->format('Y-m-d');
$defaultSampai = (clone $base)->modify('+1 month')->modify('-1 day')->format('Y-m-d');

$rentang = $pdo->query("SELECT LEAST(COALESCE((SELECT MIN(tanggal) FROM penerimaan),'2026-01-01'), COALESCE((SELECT MIN(tanggal) FROM pengeluaran),'2026-01-01')) mn,
                                GREATEST(COALESCE((SELECT MAX(tanggal) FROM penerimaan),CURDATE()), COALESCE((SELECT MAX(tanggal) FROM pengeluaran),CURDATE())) mx")->fetch();

$valid = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v);
$dari   = $valid($_GET['dari'] ?? '')   ? $_GET['dari']   : $defaultDari;
$sampai = $valid($_GET['sampai'] ?? '') ? $_GET['sampai'] : $defaultSampai;
if ($dari > $sampai) { [$dari, $sampai] = [$sampai, $dari]; }
$filterAktif = ($dari !== $defaultDari) || ($sampai !== $defaultSampai);

/* ---------- Mutasi dalam periode terpilih ---------- */
$mut = [];
foreach (['penerimaan', 'pengeluaran'] as $tb) {
  $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(jumlah),0) j FROM $tb WHERE tanggal BETWEEN ? AND ?");
  $s->execute([$dari, $sampai]); $mut[$tb] = $s->fetch();
}
$pending = (int)$pdo->query("SELECT COUNT(*) FROM penerimaan WHERE status='Pending'")->fetchColumn();

/* ---------- Tren per bulan dalam periode terpilih ---------- */
$bulan = [];
$kursor = new DateTime(substr($dari, 0, 7) . '-01');
$akhirKursor = new DateTime(substr($sampai, 0, 7) . '-01');
while ($kursor <= $akhirKursor) { $bulan[] = $kursor->format('Y-m'); $kursor->modify('+1 month'); }
$tren = [];
foreach (['penerimaan', 'pengeluaran'] as $tb) {
  $s = $pdo->prepare("SELECT DATE_FORMAT(tanggal,'%Y-%m') m, SUM(jumlah) FROM $tb WHERE tanggal BETWEEN ? AND ? GROUP BY m");
  $s->execute([$dari, $sampai]);
  $tren[$tb] = $s->fetchAll(PDO::FETCH_KEY_PAIR);
}

/* ---------- Tujuan pengeluaran dalam periode terpilih (top 6 + Lainnya) ---------- */
$s = $pdo->prepare("SELECT UPPER(TRIM(tujuan)) t, SUM(jumlah) j FROM pengeluaran WHERE tanggal BETWEEN ? AND ? AND tujuan IS NOT NULL AND TRIM(tujuan) <> '' GROUP BY t ORDER BY j DESC");
$s->execute([$dari, $sampai]);
$tujuanRows = $s->fetchAll();
$chartTujuan = array_slice($tujuanRows, 0, 6);
$sisaTujuan  = array_sum(array_column(array_slice($tujuanRows, 6), 'j'));
if ($sisaTujuan > 0) $chartTujuan[] = ['t' => 'Lainnya', 'j' => $sisaTujuan];

/* ---------- Terbit & rusak seluruh dokumen dalam periode terpilih, per bulan (dari kartu stok harian, bila ada) ---------- */
$adaKartu = false; $trenTerbit = [];
try {
  $pdo->query('SELECT 1 FROM kartu_stok_harian LIMIT 1');
  $adaKartu = true;
  $s = $pdo->prepare("SELECT DATE_FORMAT(tanggal,'%Y-%m') m, SUM(terbit) t, SUM(rusak) r FROM kartu_stok_harian WHERE tanggal BETWEEN ? AND ? GROUP BY m ORDER BY m");
  $s->execute([$dari, $sampai]);
  $trenTerbit = $s->fetchAll();
} catch (Throwable $e) { $adaKartu = false; }

/* ---------- Aktivitas dalam periode terpilih ---------- */
$s = $pdo->prepare("SELECT * FROM (
    SELECT p.id, p.tanggal, d.id did, d.nama, 'in' a, p.jumlah, p.no_ba, u.nama petugas FROM penerimaan p JOIN dokumen d ON d.id=p.dokumen_id LEFT JOIN users u ON u.id=p.user_id WHERE p.tanggal BETWEEN ? AND ?
    UNION ALL
    SELECT p.id, p.tanggal, d.id, d.nama, 'out', p.jumlah, p.no_ba, u.nama FROM pengeluaran p JOIN dokumen d ON d.id=p.dokumen_id LEFT JOIN users u ON u.id=p.user_id WHERE p.tanggal BETWEEN ? AND ?
  ) x ORDER BY tanggal DESC, id DESC LIMIT 8");
$s->execute([$dari, $sampai, $dari, $sampai]);
$akt = $s->fetchAll();

$jam = (int)date('G');
$salam = $jam < 11 ? 'Selamat pagi' : ($jam < 15 ? 'Selamat siang' : ($jam < 18 ? 'Selamat sore' : 'Selamat malam'));
include 'partials/header.php'; ?>

<div class="intro">
  <div>
    <h2><?= $salam ?>, <?= e(strtok($_SESSION['u']['nama'], ' ')) ?></h2>
    <p class="muted"><?= hari_ini() ?>, data per pukul <?= date('H:i') ?> WIB</p>
  </div>
  <div class="actions">
    <a class="btn" href="penerimaan.php#form">Catat penerimaan</a>
    <a class="btn ghost" href="pengeluaran.php#form">Catat pengeluaran</a>
  </div>
</div>

<?php if (is_super() && $pending): ?>
<div class="notice"><span><b><?= $pending ?> penerimaan</b> dari admin menunggu verifikasi Anda.</span><a class="btn sm ghost" href="penerimaan.php?status=Pending">Tinjau sekarang</a></div>
<?php endif; ?>

<section class="panel noprint">
  <div class="panel-h">
    <div><h2>Periode</h2><p>Mengatur rentang tanggal untuk ringkasan mutasi, grafik tren, tujuan pengeluaran, terbit/rusak, dan aktivitas di bawah. Nilai persediaan &amp; status stok selalu menunjukkan kondisi saat ini.</p></div>
    <div class="actions">
      <form method="get" class="inline">
        <label class="check" for="f-dari">Dari</label>
        <input type="date" id="f-dari" name="dari" value="<?= e($dari) ?>" min="<?= e(min($rentang['mn'], $dari)) ?>" max="<?= e($sampai) ?>">
        <label class="check" for="f-sampai">Sampai</label>
        <input type="date" id="f-sampai" name="sampai" value="<?= e($sampai) ?>" min="<?= e($dari) ?>" max="<?= e(max($rentang['mx'], $sampai)) ?>">
        <button type="submit" class="btn sm">Terapkan</button>
        <?php if ($filterAktif): ?><a class="btn sm ghost" href="index.php">Reset</a><?php endif; ?>
      </form>
      <a class="btn sm ghost" href="?dari=<?= e($rentang['mn']) ?>&amp;sampai=<?= e($rentang['mx']) ?>">Semua data</a>
    </div>
  </div>
</section>

<div class="strip">
  <div><small>Jenis dokumen</small><b><?= count($dok) ?></b><em><?= count($perlu) ? count($perlu) . ' perlu perhatian' : 'semua aman' ?></em></div>
  <div><small>Total stok gudang</small><b><?= num($tot['stok']) ?></b><em>buku &amp; lembar</em></div>
  <div><small>Nilai persediaan</small><b title="<?= rp($tot['nilai']) ?>"><?= rp_singkat($tot['nilai']) ?></b><em>stok × harga satuan</em></div>
  <div><small>Mutasi <?= tgl($dari) ?> – <?= tgl($sampai) ?></small>
    <b class="pair"><span class="in">+<?= num($mut['penerimaan']['j']) ?></span> <span class="out">−<?= num($mut['pengeluaran']['j']) ?></span></b>
    <em><?= $mut['penerimaan']['n'] ?> penerimaan, <?= $mut['pengeluaran']['n'] ?> pengeluaran</em></div>
</div>

<div class="cols">
  <section class="panel">
    <div class="panel-h">
      <div><h2>Perlu perhatian</h2>
        <p>Stok habis, di bawah <?= STOK_RENDAH ?>, atau diperkirakan habis dalam 45 hari menurut rata-rata pengeluaran <?= HARI_RATA ?> hari terakhir.</p></div>
      <a href="stok.php" class="btn sm ghost">Semua stok</a>
    </div>
    <?php if (!$perlu): ?>
      <div class="empty"><b>Semua stok dalam kondisi aman</b>Tidak ada dokumen yang mendekati habis.</div>
    <?php else: ?>
    <div class="tbl-wrap"><table>
      <thead><tr><th>Dokumen</th><th class="num">Stok</th><th>Status</th><th>Perkiraan habis</th><th></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($perlu, 0, 8) as $p): ?>
        <tr>
          <td><a href="stok_detail.php?id=<?= $p['id'] ?>"><?= e($p['nama']) ?></a><div class="sub mono"><?= e($p['kode']) ?></div></td>
          <td class="num"><?= num($p['stok']) ?></td>
          <td><?= badge_stok($p['st']) ?></td>
          <td><?= hari_txt($p['st'][2]) ?></td>
          <td class="r"><a class="btn sm ghost" href="penerimaan.php?baru=<?= $p['id'] ?>#form">Catat penerimaan</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($perlu) > 8): ?><div class="panel-f"><a href="stok.php">Lihat <?= count($perlu) - 8 ?> dokumen lain yang perlu perhatian</a></div><?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-h"><div><h2>Penerimaan dan pengeluaran</h2><p><?= bulan_txt($bulan[0]) ?> sampai <?= bulan_txt(end($bulan)) ?>, jumlah keping per bulan</p></div></div>
    <div class="panel-b"><div class="chart-box"><canvas id="tren" aria-label="Grafik penerimaan dan pengeluaran per bulan"></canvas></div></div>
  </section>
</div>

<section class="panel">
  <div class="panel-h"><div><h2>Gambaran menyeluruh</h2><p>Nilai persediaan &amp; status stok (kondisi saat ini); tujuan pengeluaran mengikuti periode di atas.</p></div></div>
  <div class="panel-b">
    <div class="cols3">
      <div class="chart-card">
        <h3>Nilai persediaan per dokumen</h3>
        <div class="chart-box sm"><canvas id="chNilai" aria-label="Grafik komposisi nilai persediaan per jenis dokumen"></canvas></div>
      </div>
      <div class="chart-card">
        <h3>Status stok (<?= count($dok) ?> jenis dokumen)</h3>
        <div class="chart-box sm"><canvas id="chStatus" aria-label="Grafik jumlah dokumen per status stok"></canvas></div>
      </div>
      <div class="chart-card">
        <h3>Tujuan pengeluaran (<?= tgl($dari) ?> – <?= tgl($sampai) ?>)</h3>
        <div class="chart-box sm"><canvas id="chTujuan" aria-label="Grafik jumlah keping keluar per tujuan"></canvas></div>
      </div>
    </div>
  </div>
</section>

<?php if ($adaKartu && $trenTerbit): ?>
<section class="panel">
  <div class="panel-h"><div><h2>Terbit &amp; rusak — seluruh dokumen</h2><p>Total dokumen terbit dan rusak per bulan, <?= tgl($dari) ?> – <?= tgl($sampai) ?> (semua jenis dokumen digabung).</p></div></div>
  <div class="panel-b"><div class="chart-box"><canvas id="chTerbitRusak" aria-label="Grafik terbit dan rusak per bulan, seluruh dokumen"></canvas></div></div>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel-h"><div><h2>Aktivitas terakhir</h2><p>8 transaksi terbaru dalam periode <?= tgl($dari) ?> – <?= tgl($sampai) ?></p></div>
    <div class="actions"><a class="btn sm ghost" href="penerimaan.php">Riwayat penerimaan</a><a class="btn sm ghost" href="pengeluaran.php">Riwayat pengeluaran</a></div></div>
  <?php if (!$akt): ?>
    <div class="empty"><b>Belum ada transaksi</b>Mulai dengan mencatat penerimaan dokumen pertama.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Tanggal</th><th>Dokumen</th><th>Jenis</th><th class="num">Jumlah</th><th>No. BA</th><th>Petugas</th></tr></thead>
    <tbody>
    <?php foreach ($akt as $a): ?>
      <tr>
        <td class="nowrap"><?= tgl($a['tanggal']) ?></td>
        <td><a href="stok_detail.php?id=<?= $a['did'] ?>&amp;bln=<?= substr($a['tanggal'], 0, 7) ?>"><?= e($a['nama']) ?></a></td>
        <td><?= $a['a'] === 'in' ? '<span class="tag">Masuk</span>' : '<span class="tag out">Keluar</span>' ?></td>
        <td class="num <?= $a['a'] === 'in' ? 'in' : 'out' ?>"><?= ($a['a'] === 'in' ? '+' : '−') . num($a['jumlah']) ?></td>
        <td class="mono"><?= e($a['no_ba']) ?></td>
        <td><?= e($a['petugas'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(() => {
  const box = document.getElementById('tren');
  if (!window.Chart) { box.parentNode.insertAdjacentHTML('beforeend', '<p class="chart-note">Grafik tidak dapat dimuat. Periksa koneksi internet komputer ini.</p>'); return; }
  Chart.defaults.font.family = '"IBM Plex Sans", system-ui, sans-serif';
  Chart.defaults.color = '#6b7280';
  const id = v => v.toLocaleString('id-ID');
  new Chart(box, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_map('bulan_pendek', $bulan)) ?>,
      datasets: [
        { label: 'Penerimaan',  data: <?= json_encode(array_map(fn($m) => (int)($tren['penerimaan'][$m] ?? 0), $bulan)) ?>,  backgroundColor: '#1e3a5f', borderRadius: 4, maxBarThickness: 24 },
        { label: 'Pengeluaran', data: <?= json_encode(array_map(fn($m) => (int)($tren['pengeluaran'][$m] ?? 0), $bulan)) ?>, backgroundColor: '#c4922e', borderRadius: 4, maxBarThickness: 24 }
      ]
    },
    options: {
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, padding: 16 } },
        tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + id(c.parsed.y) } }
      },
      scales: { x: { grid: { display: false } }, y: { grid: { color: '#edf0f3' }, border: { display: false }, ticks: { callback: id } } }
    }
  });

  const palet = ['#1e3a5f', '#c4922e', '#6b8f71', '#a65d57', '#4a7a96', '#8a6d9c', '#9ca3af'];

  function doughnut(canvasId, labels, data, satuanLabel) {
    const box = document.getElementById(canvasId);
    if (!box) return;
    if (!data.length || !data.some(v => v > 0)) {
      box.parentNode.insertAdjacentHTML('beforeend', '<p class="chart-note">Belum ada data.</p>');
      return;
    }
    new Chart(box, {
      type: 'doughnut',
      data: { labels, datasets: [{ data, backgroundColor: palet, borderWidth: 0 }] },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, padding: 10, font: { size: 11.5 } } },
          tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + id(c.parsed) + (satuanLabel ? ' ' + satuanLabel : '') } }
        }
      }
    });
  }

  doughnut('chNilai', <?= json_encode(array_column($chartNilai, 'nama')) ?>, <?= json_encode(array_map(fn($v) => (int)$v, array_column($chartNilai, 'nilai'))) ?>, 'rupiah');
  doughnut('chStatus', ['Aman', 'Rendah', 'Kritis', 'Habis'], <?= json_encode(array_values($statusTally)) ?>, 'jenis dokumen');
  doughnut('chTujuan', <?= json_encode(array_column($chartTujuan, 't')) ?>, <?= json_encode(array_map(fn($v) => (int)$v, array_column($chartTujuan, 'j'))) ?>, 'keping');

  <?php if ($adaKartu && $trenTerbit): ?>
  const boxTR = document.getElementById('chTerbitRusak');
  if (boxTR) {
    new Chart(boxTR, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_map(fn($r) => bulan_pendek($r['m']), $trenTerbit)) ?>,
        datasets: [
          { label: 'Terbit', data: <?= json_encode(array_map(fn($r) => (int)$r['t'], $trenTerbit)) ?>, backgroundColor: '#1e3a5f', borderRadius: 4, maxBarThickness: 22 },
          { label: 'Rusak',  data: <?= json_encode(array_map(fn($r) => (int)$r['r'], $trenTerbit)) ?>, backgroundColor: '#a65d57', borderRadius: 4, maxBarThickness: 22 }
        ]
      },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, padding: 16 } },
          tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + id(c.parsed.y) } }
        },
        scales: { x: { grid: { display: false } }, y: { grid: { color: '#edf0f3' }, border: { display: false }, ticks: { callback: id } } }
      }
    });
  }
  <?php endif; ?>
})();
</script>
<?php include 'partials/footer.php'; ?>