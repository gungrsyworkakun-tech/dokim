<?php require 'config.php'; super_required(); $title = 'Impor dari Excel';
require 'lib/xlsx_reader.php';
require 'lib/impor_dokim.php';

const IMPOR_MB_MAX = 8;             // maks ukuran berkas .xlsx yang diterima
const IMPOR_SKOR_OTOMATIS = 55;     // di atas ini, sheet otomatis dipetakan ke dokumen tanpa perlu dipilih manual

$dok = $pdo->query('SELECT id, kode, nama FROM dokumen ORDER BY nama')->fetchAll();
$dokById = [];
foreach ($dok as $d) $dokById[(int)$d['id']] = $d;

$tahunSekarang = (int)date('Y');
$hasilSheet = null;      // null = belum ada analisis; array = hasil analisis utk ditampilkan
$xlsxB64 = null;
$tahunDipakai = $tahunSekarang;
$sheetInfo = [];          // nama sheet => ['dokumen_id'=>?int,'skor'=>float,'status'=>...]

/* ============================================================
 * Impor baris yang dicentang ke tabel penerimaan / pengeluaran
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'import') {
  csrf_check();
  $kandidat = json_decode((string)($_POST['kandidat_json'] ?? '[]'), true) ?: [];
  $pilih = array_map('strval', (array)($_POST['pilih'] ?? []));
  $pilihSet = array_flip($pilih);

  $berhasil = 0; $berhasilKeluar = 0; $dilewati = 0; $gagal = [];
  foreach ($kandidat as $k) {
    if (!isset($pilihSet[(string)($k['id'] ?? '')])) continue;

    $jenis     = ($k['jenis'] ?? 'penerimaan') === 'pengeluaran' ? 'pengeluaran' : 'penerimaan';
    $dokumenId = (int)($k['dokumen_id'] ?? 0);
    $jumlah    = (int)($k['jumlah'] ?? 0);
    $tanggal   = (string)($k['tanggal'] ?? '');
    $noBa      = trim((string)($k['no_ba'] ?? ''));
    $seriAwal  = $k['seri_awal'] ?? null;
    $seriAkhir = $k['seri_akhir'] ?? null;
    $sheet     = (string)($k['sheet'] ?? '');
    if ($jenis === 'pengeluaran') {
      $tujuan = trim((string)($k['tujuan'] ?? ''));
      if ($tujuan === '') $tujuan = '(tidak dicatat di sumber)';
      if ($noBa === '') $noBa = 'ARSIP-' . $dokumenId . '-' . $tanggal;     // Excel tidak mencatat No. BA: pakai penanda arsip
      $tujuan = mb_substr($tujuan, 0, 80);
    }

    if ($jumlah < 1 || $jumlah > 10000000 || !valid_date($tanggal) || $tanggal > date('Y-m-d') || $noBa === '' || mb_strlen($noBa) > 60) {
      $gagal[] = "Baris $jenis dari sheet \"$sheet\" (No. BA \"$noBa\"): data tidak valid, dilewati.";
      continue;
    }

    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT id, stok FROM dokumen WHERE id=? FOR UPDATE'); $s->execute([$dokumenId]);
    $dk = $s->fetch();
    if (!$dk) { $pdo->rollBack(); $gagal[] = "Baris dari sheet \"$sheet\": jenis dokumen tidak ditemukan."; continue; }

    if ($jenis === 'penerimaan') {
      $s = $pdo->prepare('SELECT 1 FROM penerimaan WHERE no_ba=? AND dokumen_id=?'); $s->execute([$noBa, $dokumenId]);
      if ($s->fetch()) { $pdo->rollBack(); $dilewati++; continue; }     // sudah ada (barangkali ditambahkan orang lain barusan)

      $pdo->prepare('INSERT INTO penerimaan(tanggal,dokumen_id,jumlah,no_ba,seri_awal,seri_akhir,catatan,user_id,status) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$tanggal, $dokumenId, $jumlah, $noBa, $seriAwal ?: null, $seriAkhir ?: null, "Impor dari Excel (sheet \"$sheet\")", $_SESSION['u']['id'], 'Terverifikasi']);
      $pdo->prepare('UPDATE dokumen SET stok=stok+? WHERE id=?')->execute([$jumlah, $dokumenId]);
      $pdo->commit();
      $berhasil++;
    } else {
      // pengeluaran dianggap sama kalau jenis dokumen, tanggal, dan jumlahnya sama
      $s = $pdo->prepare('SELECT 1 FROM pengeluaran WHERE dokumen_id=? AND tanggal=? AND jumlah=?'); $s->execute([$dokumenId, $tanggal, $jumlah]);
      if ($s->fetch()) { $pdo->rollBack(); $dilewati++; continue; }
      if ((int)$dk['stok'] < $jumlah) { $pdo->rollBack(); $gagal[] = "Pengeluaran $jumlah dari sheet \"$sheet\" ($tanggal): stok di sistem hanya " . (int)$dk['stok'] . ", dilewati."; continue; }

      $pdo->prepare('INSERT INTO pengeluaran(tanggal,dokumen_id,dari_penerimaan_id,jumlah,tujuan,seri_awal,seri_akhir,no_ba,user_id) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$tanggal, $dokumenId, null, $jumlah, $tujuan, $seriAwal ?: null, $seriAkhir ?: null, $noBa, $_SESSION['u']['id']]);
      $pdo->prepare('UPDATE dokumen SET stok=stok-? WHERE id=?')->execute([$jumlah, $dokumenId]);
      $pdo->commit();
      $berhasilKeluar++;
    }
  }

  $pesan = "$berhasil penerimaan dan $berhasilKeluar pengeluaran berhasil ditambahkan.";
  if ($dilewati)      $pesan .= " $dilewati dilewati (ternyata sudah ada di database).";
  if ($gagal)         $pesan .= ' ' . count($gagal) . ' baris gagal: ' . implode(' ', array_slice($gagal, 0, 5)) . (count($gagal) > 5 ? ' …' : '');
  flash($pesan, $gagal ? 'err' : 'ok');
  back('impor_excel.php');
}

/* ============================================================
 * Unggah berkas baru ATAU terapkan ulang pemetaan sheet->dokumen
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['act'] ?? '', ['unggah', 'petakan_ulang'], true)) {
  csrf_check();
  $tahunDipakai = (int)($_POST['tahun'] ?? $tahunSekarang);
  if ($tahunDipakai < 2015 || $tahunDipakai > $tahunSekarang + 1) $tahunDipakai = $tahunSekarang;

  $xlsxBytes = null;
  if (($_POST['act'] ?? '') === 'unggah') {
    if (empty($_FILES['berkas']['name'])) { flash('Pilih berkas .xlsx terlebih dahulu.', 'err'); back('impor_excel.php'); }
    if ($_FILES['berkas']['error'] !== UPLOAD_ERR_OK) { flash('Gagal mengunggah berkas (kemungkinan ukurannya melebihi batas server).', 'err'); back('impor_excel.php'); }
    if ($_FILES['berkas']['size'] > IMPOR_MB_MAX * 1024 * 1024) { flash('Ukuran berkas maks. ' . IMPOR_MB_MAX . ' MB.', 'err'); back('impor_excel.php'); }
    if (!str_ends_with(strtolower($_FILES['berkas']['name']), '.xlsx')) { flash('Berkas harus berformat .xlsx (Excel).', 'err'); back('impor_excel.php'); }
    $xlsxBytes = file_get_contents($_FILES['berkas']['tmp_name']);
  } else {
    $xlsxBytes = base64_decode((string)($_POST['xlsx_data'] ?? ''), true);
    if ($xlsxBytes === false || $xlsxBytes === '') { flash('Data berkas hilang, silakan unggah ulang.', 'err'); back('impor_excel.php'); }
  }

  $tmpPath = tempnam(sys_get_temp_dir(), 'dokim_xlsx_');
  file_put_contents($tmpPath, $xlsxBytes);
  $overridePeta = (array)($_POST['peta'] ?? []);   // nama sheet => '' (lewati) atau id dokumen, dari pemetaan manual

  try {
    $xr = new XlsxReader($tmpPath);
    $xlsxB64 = base64_encode($xlsxBytes);
    $hasilSheet = [];

    foreach ($xr->sheetNames() as $nama) {
      $rows = $xr->readSheet($nama);

      // tentukan dokumen_id: pemetaan manual kalau ada, kalau tidak coba tebak otomatis dari nama sheet
      $skorTerbaik = 0; $dokTerbaik = null;
      foreach ($dok as $d) {
        $skor = impor_skor_kemiripan($nama, $d['nama']);
        if ($skor > $skorTerbaik) { $skorTerbaik = $skor; $dokTerbaik = $d; }
      }

      if (array_key_exists($nama, $overridePeta)) {
        $idDipilih = (int)$overridePeta[$nama];
        $dokumenId = $idDipilih > 0 ? $idDipilih : null;
        $otomatis = false;
      } else {
        $dokumenId = ($skorTerbaik >= IMPOR_SKOR_OTOMATIS && $dokTerbaik) ? (int)$dokTerbaik['id'] : null;
        $otomatis = $dokumenId !== null;
      }

      $sheetInfo[$nama] = [
        'dokumen_id' => $dokumenId,
        'tebakan_id' => $dokTerbaik ? (int)$dokTerbaik['id'] : null,
        'skor' => round($skorTerbaik),
        'otomatis' => $otomatis,
      ];

      if ($dokumenId === null || !isset($dokById[$dokumenId])) {
        $hasilSheet[$nama] = ['ok' => null, 'pesan' => 'Belum dipetakan ke jenis dokumen.', 'baris' => [], 'peringatan' => []];
        continue;
      }
      $hTerima = impor_ekstrak_penerimaan($rows);
      $hKeluar = impor_ekstrak_pengeluaran($rows);
      foreach ($hTerima['baris'] as &$bb) $bb['jenis'] = 'penerimaan'; unset($bb);
      foreach ($hKeluar['baris'] as &$bb) $bb['jenis'] = 'pengeluaran'; unset($bb);
      $hasilSheet[$nama] = [
        'ok' => ($hTerima['ok'] || $hKeluar['ok']) ? true : false,
        'pesan' => $hTerima['ok'] ? $hKeluar['pesan'] : $hTerima['pesan'],
        'baris' => array_merge($hTerima['baris'], $hKeluar['baris']),
        'peringatan' => array_merge($hTerima['peringatan'], $hKeluar['peringatan']),
        'n_terima' => count($hTerima['baris']), 'n_keluar' => count($hKeluar['baris']),
      ];
    }
  } catch (Throwable $e) {
    flash('Gagal membaca berkas Excel: ' . $e->getMessage(), 'err');
    @unlink($tmpPath);
    back('impor_excel.php');
  }
  @unlink($tmpPath);

  // cek mana yang sudah ada di database (per dokumen_id + no_ba, dibandingkan tanpa peduli huruf besar/kecil & spasi)
  $existing = [];
  $dokIdsDipakai = array_values(array_unique(array_filter(array_column($sheetInfo, 'dokumen_id'))));
  if ($dokIdsDipakai) {
    $place = implode(',', array_fill(0, count($dokIdsDipakai), '?'));
    $s = $pdo->prepare("SELECT dokumen_id, no_ba FROM penerimaan WHERE dokumen_id IN ($place)");
    $s->execute($dokIdsDipakai);
    foreach ($s->fetchAll() as $row) $existing['penerimaan|' . $row['dokumen_id'] . '|' . impor_normalisasi($row['no_ba'])] = true;
    $s = $pdo->prepare("SELECT dokumen_id, tanggal, jumlah FROM pengeluaran WHERE dokumen_id IN ($place)");
    $s->execute($dokIdsDipakai);
    foreach ($s->fetchAll() as $row) $existing['pengeluaran|' . $row['dokumen_id'] . '|' . $row['tanggal'] . '|' . (int)$row['jumlah']] = true;
  }

  $idCounter = 0;
  $kandidatBaru = [];
  $totalBaru = 0; $totalSudahAda = 0;
  foreach ($hasilSheet as $nama => &$h) {
    if ($h['ok'] !== true) continue;
    $dokumenId = $sheetInfo[$nama]['dokumen_id'];
    foreach ($h['baris'] as &$b) {
      $b['tanggal'] = sprintf('%04d-%02d-%02d', $tahunDipakai, $b['bulan'], $b['tgl']);
      $key = $b['jenis'] === 'pengeluaran'
        ? 'pengeluaran|' . $dokumenId . '|' . $b['tanggal'] . '|' . (int)$b['jumlah']
        : 'penerimaan|' . $dokumenId . '|' . impor_normalisasi($b['no_ba']);
      $b['status'] = isset($existing[$key]) ? 'sudah_ada' : 'baru';
      $b['dokumen_id'] = $dokumenId;
      $b['dokumen_nama'] = $dokById[$dokumenId]['nama'];
      $b['sheet'] = $nama;
      $b['id'] = 'k' . (++$idCounter);
      if ($b['status'] === 'baru') { $kandidatBaru[] = $b; $totalBaru++; } else { $totalSudahAda++; }
    }
    unset($b);
  }
  unset($h);
}

include 'partials/header.php'; ?>

<section class="panel">
  <div class="panel-h"><div><h2>Impor penerimaan dan pengeluaran dari rekap Excel</h2>
    <p>Unggah file rekap Excel (format kartu stok per jenis dokumen, satu sheet per jenis). Sistem akan membaca sendiri baris mana yang punya transaksi penerimaan <b>dan pengeluaran</b> (lengkap dengan tujuan, No. BA, dan nomor seri), lalu membandingkannya dengan data yang sudah ada di database — supaya tidak tercatat dobel.</p></div></div>
  <div class="panel-b">
    <form method="post" enctype="multipart/form-data" class="grid-form" id="f-unggah">
      <?= csrf_field() ?><input type="hidden" name="act" value="unggah">
      <div><label for="f-berkas">Berkas Excel (.xlsx, maks. <?= IMPOR_MB_MAX ?> MB) <span class="req">*</span></label>
        <input id="f-berkas" type="file" name="berkas" accept=".xlsx" required></div>
      <div><label for="f-tahun">Tahun data ini <span class="req">*</span></label>
        <input id="f-tahun" type="number" name="tahun" min="2015" max="<?= $tahunSekarang + 1 ?>" value="<?= $tahunDipakai ?>" required>
        <div class="hint">Excel-nya cuma punya kolom Bulan &amp; Tanggal, tidak ada Tahun — jadi perlu diisi manual.</div></div>
      <div class="full">
        <button type="submit" class="btn" id="f-unggah-btn">Analisis berkas</button>
        <div class="upload-progress" id="upload-progress" hidden>
          <div class="upload-progress-bar"><div class="upload-progress-fill" id="upload-progress-fill"></div></div>
          <p class="upload-progress-text" id="upload-progress-text">Mengunggah… 0%</p>
        </div>
      </div>
    </form>
  </div>
</section>

<script>
(() => {
  const form = document.getElementById('f-unggah');
  if (!form || !window.XMLHttpRequest) return;   // kalau browser terlalu lama, biarkan submit biasa (tanpa progress bar)
  form.addEventListener('submit', e => {
    e.preventDefault();
    const btn = document.getElementById('f-unggah-btn');
    const wrap = document.getElementById('upload-progress');
    const fill = document.getElementById('upload-progress-fill');
    const text = document.getElementById('upload-progress-text');
    btn.disabled = true; wrap.hidden = false;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'impor_excel.php', true);
    xhr.upload.addEventListener('progress', ev => {
      if (!ev.lengthComputable) return;
      const pct = Math.round((ev.loaded / ev.total) * 100);
      fill.style.width = pct + '%';
      text.textContent = 'Mengunggah… ' + pct + '%';
    });
    xhr.upload.addEventListener('load', () => {
      fill.classList.add('indeterminate');
      text.textContent = 'Berkas terkirim, sedang membaca & menganalisis isinya…';
    });
    xhr.onload = () => {
      if (xhr.status === 200) {
        document.open(); document.write(xhr.responseText); document.close();
      } else {
        fill.classList.remove('indeterminate'); fill.style.width = '0%';
        text.textContent = 'Gagal (kode ' + xhr.status + '). Coba muat ulang halaman dan unggah lagi.';
        btn.disabled = false;
      }
    };
    xhr.onerror = () => {
      fill.classList.remove('indeterminate'); fill.style.width = '0%';
      text.textContent = 'Koneksi terputus saat mengunggah. Coba lagi.';
      btn.disabled = false;
    };
    xhr.send(new FormData(form));
  });
})();
</script>

<?php if ($hasilSheet !== null): ?>

<section class="panel">
  <div class="panel-h"><div><h2>Pemetaan sheet → jenis dokumen</h2>
    <p>Dicocokkan otomatis dari nama sheet. Kalau ada yang salah atau belum terpetakan (ditandai merah), pilih manual lalu klik "Terapkan &amp; analisis ulang".</p></div></div>
  <div class="panel-b">
    <form method="post" class="js-progres-sederhana" data-label-proses="Menganalisis ulang…">
      <?= csrf_field() ?><input type="hidden" name="act" value="petakan_ulang">
      <input type="hidden" name="tahun" value="<?= $tahunDipakai ?>">
      <input type="hidden" name="xlsx_data" value="<?= e($xlsxB64) ?>">
      <div class="tbl-wrap"><table>
        <thead><tr><th>Nama sheet</th><th>Dipetakan ke</th><th class="num">Kecocokan</th><th>Hasil baca</th></tr></thead>
        <tbody>
        <?php foreach ($sheetInfo as $nama => $info): $h = $hasilSheet[$nama]; ?>
          <tr>
            <td><?= e($nama) ?></td>
            <td><select name="peta[<?= e($nama) ?>]">
                <option value="">— Lewati sheet ini —</option>
                <?php foreach ($dok as $d): ?>
                  <option value="<?= $d['id'] ?>"<?= (int)$d['id'] === (int)$info['dokumen_id'] ? ' selected' : '' ?>><?= e($d['nama']) ?> (<?= e($d['kode']) ?>)</option>
                <?php endforeach; ?>
              </select></td>
            <td class="num"><?= $info['skor'] ?>%</td>
            <td>
              <?php if ($info['dokumen_id'] === null): ?><span class="st st-pending">Belum dipetakan</span>
              <?php elseif ($h['ok'] === false): ?><span class="st st-pending" title="<?= e($h['pesan']) ?>">Gagal dibaca</span>
              <?php else: ?><?= (int)($h['n_terima'] ?? 0) ?> penerimaan, <?= (int)($h['n_keluar'] ?? 0) ?> pengeluaran ditemukan<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="full" style="margin-top:12px"><button type="submit" class="btn sm ghost">Terapkan &amp; analisis ulang</button></p>
    </form>
  </div>
</section>

<?php
$adaMasalah = false;
foreach ($hasilSheet as $nama => $h) if ($h['ok'] === false || $h['peringatan']) $adaMasalah = true;
if ($adaMasalah): ?>
<section class="panel">
  <div class="panel-h"><div><h2>Catatan dari proses baca</h2></div></div>
  <div class="panel-b">
    <ul style="margin:0;padding-left:18px;font-size:13px;color:var(--ink-3);line-height:1.8">
      <?php foreach ($hasilSheet as $nama => $h): ?>
        <?php if ($h['ok'] === false): ?><li><b><?= e($nama) ?>:</b> <?= e($h['pesan']) ?></li><?php endif; ?>
        <?php foreach ($h['peringatan'] as $p): ?><li><b><?= e($nama) ?>:</b> <?= e($p) ?></li><?php endforeach; ?>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <div><h2>Hasil analisis</h2>
      <p><?= $totalBaru ?> transaksi (penerimaan &amp; pengeluaran) <b>belum tercatat</b> di database (siap ditambahkan), <?= $totalSudahAda ?> sudah ada sebelumnya (tidak ditampilkan lagi supaya tidak dobel).</p></div>
  </div>
  <div class="panel-b">
    <?php if (!$kandidatBaru): ?>
      <div class="empty"><b>Tidak ada yang perlu ditambahkan</b>Semua transaksi penerimaan dan pengeluaran di berkas ini sudah tercatat di database, atau belum ada sheet yang terpetakan dengan benar.</div>
    <?php else: ?>
      <form method="post" id="fimport" class="js-progres-sederhana" data-label-proses="Menyimpan ke database…">
        <?= csrf_field() ?><input type="hidden" name="act" value="import">
        <input type="hidden" name="kandidat_json" value='<?= e(json_encode($kandidatBaru)) ?>'>
        <div class="tbl-wrap"><table>
          <thead><tr>
            <th class="chk"><input type="checkbox" checked onclick="this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(c=>c.checked=this.checked)" aria-label="Centang semua"></th>
            <th>Tanggal</th><th>Jenis</th><th>Dokumen</th><th class="num">Jumlah</th><th>Tujuan</th><th>No. BA</th><th>Rentang seri</th><th>Sheet asal</th>
          </tr></thead>
          <tbody>
          <?php foreach ($kandidatBaru as $k): ?>
            <tr>
              <td class="chk"><input type="checkbox" name="pilih[]" value="<?= e($k['id']) ?>" checked></td>
              <td class="nowrap"><?= tgl($k['tanggal']) ?></td>
              <td><?= $k['jenis'] === 'pengeluaran' ? '<span class="st st-kritis">Keluar</span>' : '<span class="st st-aman">Terima</span>' ?></td>
              <td><?= e($k['dokumen_nama']) ?></td>
              <td class="num <?= $k['jenis'] === 'pengeluaran' ? 'out">−' : 'in">+' ?><?= num($k['jumlah']) ?></td>
              <td class="small"><?= $k['jenis'] === 'pengeluaran' ? e($k['tujuan'] ?: '—') : '<span class="muted">—</span>' ?></td>
              <td class="mono"><?= $k['no_ba'] !== '' ? e($k['no_ba']) : '<span class="muted">(kosong)</span>' ?></td>
              <td class="mono nowrap"><?= $k['seri_awal'] ? e($k['seri_awal']) . ' – ' . e($k['seri_akhir']) : '<span class="muted">—</span>' ?></td>
              <td class="muted"><?= e($k['sheet']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p style="margin-top:12px"><button type="submit" class="btn" data-confirm="Tambahkan <?= count($kandidatBaru) ?> transaksi yang dicentang ke database? Stok akan bertambah (penerimaan) atau berkurang (pengeluaran).">Tambahkan yang dicentang ke database</button></p>
      </form>
    <?php endif; ?>
  </div>
</section>

<script>
document.querySelectorAll('.js-progres-sederhana').forEach(f => {
  f.addEventListener('submit', () => {
    const btn = f.querySelector('button[type=submit]');
    if (!btn) return;
    btn.disabled = true;
    btn.dataset.labelAsli = btn.textContent;
    btn.textContent = f.dataset.labelProses || 'Memproses…';
  });
});
</script>

<?php endif; ?>

<?php include 'partials/footer.php'; ?>