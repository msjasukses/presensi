<?php
$pageTitle = 'Laporan Harian Siswa';
require_once __DIR__ . '/includes/header.php';

/*
 * Laporan absensi siswa untuk SATU tanggal, dengan cakupan per rombel atau
 * per tingkat kelas. Status tiap siswa dihitung dengan aturan yang sama seperti
 * laporan periode: shift kelas menimpa jadwal umum, hari libur diperhitungkan.
 */
$ta          = tahunAjaranTerpilih($dc);
$kelasList   = $ta ? dcKelasList($dc, (int)$ta['id']) : [];
$tingkatList = $ta ? dcTingkatList($dc, (int)$ta['id']) : [];

$tanggal = $_GET['tanggal'] ?? tanggalAcuan($ta);
$cakupan = ($_GET['cakupan'] ?? 'rombel') === 'tingkat' ? 'tingkat' : 'rombel';
$kelasId = (int)($_GET['kelas_id'] ?? 0);
$tingkat = (int)($_GET['tingkat'] ?? 0);

// Nilai bawaan pilihan agar laporan langsung terisi saat halaman dibuka
if (!$kelasId && $kelasList)   $kelasId = (int)$kelasList[0]['id'];
if (!$tingkat && $tingkatList) $tingkat = (int)$tingkatList[0];

$rows = []; $rekap = rekapKosong(); $judulCakupan = '';
if ($ta) {
    $siswa = $cakupan === 'tingkat'
        ? dcSiswaList($dc, (int)$ta['id'], 0, $tingkat)
        : dcSiswaList($dc, (int)$ta['id'], $kelasId);

    $judulCakupan = $cakupan === 'tingkat'
        ? 'Tingkat ' . $tingkat
        : 'Kelas ' . (current(array_filter($kelasList, fn($k) => (int)$k['id'] === $kelasId))['nama'] ?? '-');

    if ($siswa) {
        $nisList = array_column($siswa, 'nis');
        $rec     = recAbsensi($pdo, 'siswa', $nisList, $tanggal, $tanggal);
        // Tiap rombel bisa punya shift sendiri -> kalender dihitung per rombel
        $shiftKelas = shiftPerHariBanyak($pdo, 'siswa', array_column($siswa, 'kelas_id'));
        $kalCache = [];

        foreach ($siswa as $s) {
            $rid = (int)$s['kelas_id'];
            if (!isset($kalCache[$rid])) {
                $kalCache[$rid] = kalenderPeriode($pdo, 'siswa', $tanggal, $tanggal, $shiftKelas[$rid] ?? null);
            }
            $info = $kalCache[$rid][$tanggal] ?? ['libur' => false, 'batas' => '07:00:00', 'ket' => null, 'shift' => null];
            $a    = $rec[$s['nis']][$tanggal] ?? null;
            ['status' => $status, 'keterangan' => $ket] = statusTanggal($info, $a);

            $rekap[$status]++;
            $rows[] = [
                'nis'        => $s['nis'],
                'nama'       => $s['nama'],
                'kelas'      => $s['kelas'],
                'jam_masuk'  => $a['jam_masuk'] ?? null,
                'jam_pulang' => $a['jam_pulang'] ?? null,
                'status'     => $status,
                'shift'      => $info['shift'] ?? null,
                'keterangan' => $ket,
            ];
        }
    }
}

$hariNama = $HARI_ID[date('N', strtotime($tanggal)) - 1];
$qs = http_build_query(['type' => 'harian', 'tanggal' => $tanggal, 'cakupan' => $cakupan,
                        'kelas_id' => $kelasId, 'tingkat' => $tingkat]);
?>
<form class="card card-stat mb-4"><div class="card-body row g-2 align-items-end">
  <div class="col-md-3">
    <label class="form-label">Tanggal</label>
    <input type="date" class="form-control" name="tanggal" value="<?= e($tanggal) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Cakupan</label>
    <select class="form-select" name="cakupan" id="cakupan" onchange="gantiCakupan()">
      <option value="rombel"  <?= $cakupan === 'rombel'  ? 'selected' : '' ?>>Per Rombel (Kelas)</option>
      <option value="tingkat" <?= $cakupan === 'tingkat' ? 'selected' : '' ?>>Per Tingkat Kelas</option>
    </select>
  </div>
  <div class="col-md-4">
    <label class="form-label">Pilihan</label>
    <div class="pilih-wrap" data-cakupan="rombel">
      <select class="form-select" name="kelas_id">
        <?php foreach ($kelasList as $k): ?>
          <option value="<?= $k['id'] ?>" <?= (int)$k['id'] === $kelasId ? 'selected' : '' ?>>
            <?= e($k['nama']) ?> — Tingkat <?= e($k['tingkat']) ?>
          </option>
        <?php endforeach; ?>
        <?php if (!$kelasList): ?><option value="0">(tidak ada kelas)</option><?php endif; ?>
      </select>
    </div>
    <div class="pilih-wrap" data-cakupan="tingkat">
      <select class="form-select" name="tingkat">
        <?php foreach ($tingkatList as $t): ?>
          <option value="<?= $t ?>" <?= (int)$t === $tingkat ? 'selected' : '' ?>>Tingkat <?= e($t) ?></option>
        <?php endforeach; ?>
        <?php if (!$tingkatList): ?><option value="0">(tidak ada tingkat)</option><?php endif; ?>
      </select>
    </div>
  </div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Tampilkan</button></div>
</div></form>

<?php if ($ta): ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div class="text-muted small">
    <i class="bi bi-calendar-event me-1"></i>
    <b><?= e($hariNama) ?>, <?= e(date('d-m-Y', strtotime($tanggal))) ?></b>
    &middot; <?= e($judulCakupan) ?> &middot; <?= count($rows) ?> siswa
  </div>
  <div>
    <a class="btn btn-success btn-sm" href="export.php?<?= $qs ?>&format=excel"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
    <a class="btn btn-danger btn-sm" href="export.php?<?= $qs ?>&format=pdf"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</a>
  </div>
</div>

<!-- Ringkasan status -->
<div class="row g-2 mb-3 row-cols-2 row-cols-sm-3 row-cols-md-6">
  <?php foreach ($STATUS_DETAIL as $k => $label): if (in_array($k, ['dinas','cuti'], true)) continue; /* khusus guru */ ?>
  <div class="col">
    <div class="card card-stat text-center">
      <div class="card-body py-2">
        <div class="small text-muted"><?= e($label) ?></div>
        <div class="fs-4 fw-bold text-<?= explode(' ', $STATUS_WARNA[$k])[0] ?>"><?= $rekap[$k] ?? 0 ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card card-stat"><div class="card-body table-responsive">
  <table class="table table-hover table-sm align-middle">
    <thead><tr>
      <th style="width:50px">No</th><th>NIS</th><th>Nama Siswa</th><th>Kelas</th>
      <th>Shift</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Status</th><th>Keterangan</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="text-muted">Tidak ada siswa pada cakupan ini.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($r['nis']) ?></td>
        <td><?= e($r['nama']) ?></td>
        <td><?= e($r['kelas']) ?></td>
        <td><?= $r['shift'] ? '<span class="badge bg-light text-dark border">' . e($r['shift']) . '</span>' : '<span class="text-muted">-</span>' ?></td>
        <td><?= e($r['jam_masuk'] ? substr($r['jam_masuk'], 0, 5) : '-') ?></td>
        <td><?= e($r['jam_pulang'] ? substr($r['jam_pulang'], 0, 5) : '-') ?></td>
        <td><span class="badge bg-<?= $STATUS_WARNA[$r['status']] ?>"><?= e($STATUS_DETAIL[$r['status']]) ?></span></td>
        <td><?= e($r['keterangan'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php else: ?>
  <div class="alert alert-warning">Tidak ada tahun ajaran di datacenter, sehingga roster siswa belum bisa ditampilkan.</div>
<?php endif; ?>

<script>
// Tampilkan hanya pilihan yang sesuai cakupan (rombel / tingkat)
function gantiCakupan() {
  const c = document.getElementById('cakupan').value;
  document.querySelectorAll('.pilih-wrap').forEach(w => {
    w.style.display = (w.dataset.cakupan === c) ? '' : 'none';
  });
}
gantiCakupan();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
