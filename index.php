<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

// Tanggal acuan mengikuti tahun ajaran terpilih: hari ini bila TA sedang berjalan,
// atau hari terakhir TA bila yang dipilih tahun ajaran lampau.
$taDash = tahunAjaranTerpilih($dc);
$today  = tanggalAcuan($taDash);
$ta     = $taDash;

// Status seluruh roster selama 7 hari terakhir (sekali hitung, dipakai kartu & grafik).
// Memakai rosterAbsensi() yang sama dengan halaman Detail Kehadiran, sehingga angka
// pada kartu selalu sama dengan jumlah nama yang muncul saat kartu diklik.
$mulai7      = date('Y-m-d', strtotime($today . ' -6 day'));
$rosterSiswa = rosterAbsensi($pdo, $dc, 'siswa', $ta, $mulai7, $today);
$rosterGuru  = rosterAbsensi($pdo, $dc, 'guru',  $ta, $mulai7, $today);

$totalSiswa = count($rosterSiswa['orang']);
$totalGuru  = count($rosterGuru['orang']);
$cs = hitungRoster($rosterSiswa, $today);
$cg = hitungRoster($rosterGuru,  $today);

$jumlahKelompok = fn(array $c, string $tipe, string $kelompok): int
    => array_sum(array_intersect_key($c, array_flip(statusKelompok($tipe, $kelompok))));

$siswaHadir      = $jumlahKelompok($cs, 'siswa', 'masuk');
$siswaTidakHadir = $jumlahKelompok($cs, 'siswa', 'tidak_hadir');
// Guru dinas luar tetap bertugas -> dihitung hadir; cuti tidak.
$guruHadir       = $jumlahKelompok($cg, 'guru', 'masuk');
$guruTidakHadir  = $jumlahKelompok($cg, 'guru', 'tidak_hadir');

// Grafik 7 hari terakhir (gabungan siswa + guru per status)
$labels = []; $tanggalGrafik = [];
$series = ['hadir'=>[],'terlambat'=>[],'izin'=>[],'sakit'=>[],'dinas'=>[],'cuti'=>[],'alpha'=>[]];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("$today -$i day"));
    $labels[] = date('d/m', strtotime($d));
    $tanggalGrafik[] = $d;
    $a = hitungRoster($rosterSiswa, $d);
    $b = hitungRoster($rosterGuru,  $d);
    foreach ($series as $k => $_) $series[$k][] = $a[$k] + $b[$k];
}

$linkDetail = fn(string $tipe, string $status) => 'detail_status.php?' . http_build_query(
    ['tipe' => $tipe, 'status' => $status, 'tanggal' => $today]);
?>
<?php if (!$ta): ?>
  <div class="alert alert-warning">Tidak ada <b>tahun ajaran aktif</b> di datacenter (<code><?= e(DC_NAME) ?></code>). Data siswa & kelas tidak dapat ditampilkan sampai ada tahun ajaran yang diaktifkan.</div>
<?php else: ?>
  <div class="text-muted small mb-3">
    <i class="bi bi-database me-1"></i>Tahun Ajaran <b><?= e($ta['nama_tahun_ajaran']) ?></b>
    <?= (int)$ta['is_aktif'] === 1 ? '<span class="badge bg-success ms-1">aktif</span>' : '<span class="badge bg-secondary ms-1">lampau</span>' ?>
    &middot; Tanggal acuan <b><?= e(date('d-m-Y', strtotime($today))) ?></b>
    &middot; <span class="text-primary">klik kartu untuk melihat daftar nama</span>
  </div>
<?php endif; ?>
<div class="row g-3">
  <?php
  // [label, nilai, ikon, warna, tipe, filter status di halaman detail]
  $cards = [
    ['Total Siswa',       $totalSiswa,      'bi-people',        'primary', 'siswa', 'semua'],
    ['Siswa Hadir',       $siswaHadir,      'bi-check-circle',  'success', 'siswa', 'masuk'],
    ['Siswa Tidak Hadir', $siswaTidakHadir, 'bi-x-circle',      'danger',  'siswa', 'tidak_hadir'],
    ['Siswa Terlambat',   $cs['terlambat'], 'bi-clock-history', 'warning', 'siswa', 'terlambat'],
    ['Total Guru',        $totalGuru,       'bi-person-badge',  'primary', 'guru',  'semua'],
    ['Guru Hadir',        $guruHadir,       'bi-check-circle',  'success', 'guru',  'masuk'],
    ['Guru Tidak Hadir',  $guruTidakHadir,  'bi-x-circle',      'danger',  'guru',  'tidak_hadir'],
    ['Guru Terlambat',    $cg['terlambat'], 'bi-clock-history', 'warning', 'guru',  'terlambat'],
  ];
  foreach ($cards as [$label, $val, $icon, $color, $tipeK, $statusK]): ?>
  <div class="col-6 col-md-3">
    <a class="card card-stat card-link h-100" href="<?= e($linkDetail($tipeK, $statusK)) ?>" title="Lihat daftar <?= e(strtolower($label)) ?>">
      <div class="card-body text-center py-4">
        <i class="bi <?= $icon ?> icon d-block mb-2 text-<?= $color ?>"></i>
        <div class="fs-2 fw-bold lh-1"><?= $val ?></div>
        <div class="text-muted small mt-1"><?= e($label) ?></div>
        <div class="lihat mt-2">Lihat detail <i class="bi bi-arrow-right"></i></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card card-stat mt-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h6 class="mb-0">Grafik Absensi 7 Hari Terakhir (Siswa + Guru) — sampai <?= e(date('d-m-Y', strtotime($today))) ?></h6>
      <span class="text-muted small"><i class="bi bi-hand-index me-1"></i>Klik batang grafik untuk melihat daftar nama</span>
    </div>
    <canvas id="chartAbsensi" height="90" style="cursor:pointer"></canvas>
  </div>
</div>

<script>
const tanggalGrafik = <?= json_encode($tanggalGrafik) ?>;
const kunciSeri = ['hadir', 'terlambat', 'izin', 'sakit', 'dinas', 'cuti', 'alpha'];
const grafik = new Chart(document.getElementById('chartAbsensi'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [
      { label: 'Hadir',       data: <?= json_encode($series['hadir']) ?>,     backgroundColor: '#22c55e' },
      { label: 'Terlambat',   data: <?= json_encode($series['terlambat']) ?>, backgroundColor: '#f59e0b' },
      { label: 'Izin',        data: <?= json_encode($series['izin']) ?>,      backgroundColor: '#3b82f6' },
      { label: 'Sakit',       data: <?= json_encode($series['sakit']) ?>,     backgroundColor: '#a855f7' },
      { label: 'Dinas Luar',  data: <?= json_encode($series['dinas']) ?>,     backgroundColor: '#0f172a' },
      { label: 'Cuti',        data: <?= json_encode($series['cuti']) ?>,      backgroundColor: '#64748b' },
      { label: 'Tidak Hadir', data: <?= json_encode($series['alpha']) ?>,     backgroundColor: '#ef4444' }
    ]
  },
  options: {
    responsive: true,
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});

// Klik batang -> daftar nama untuk status & tanggal itu.
// Dinas luar & cuti hanya berlaku bagi guru, status lain dibuka pada tab siswa.
document.getElementById('chartAbsensi').addEventListener('click', function (evt) {
  const titik = grafik.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
  if (!titik.length) return;
  const status = kunciSeri[titik[0].datasetIndex];
  const tipe = (status === 'dinas' || status === 'cuti') ? 'guru' : 'siswa';
  const q = new URLSearchParams({ tipe, status, tanggal: tanggalGrafik[titik[0].index] });
  location.href = 'detail_status.php?' + q.toString();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
