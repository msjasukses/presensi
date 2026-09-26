<?php
$pageTitle = 'Detail Kehadiran';
require_once __DIR__ . '/includes/header.php';

/*
 * Daftar nama per status kehadiran pada satu tanggal — tujuan klik dari kartu dan
 * grafik dashboard. Perhitungannya memakai rosterAbsensi() yang sama dengan
 * dashboard, sehingga jumlah baris di sini selalu sama dengan angka pada kartu.
 */
$ta   = tahunAjaranTerpilih($dc);
$tipe = ($_GET['tipe'] ?? 'siswa') === 'guru' ? 'guru' : 'siswa';

$tanggal = (string)($_GET['tanggal'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || !strtotime($tanggal)) {
    $tanggal = tanggalAcuan($ta);
}

// Pilihan filter status: kelompok (semua / masuk / tidak_hadir) atau status persis
$pilihan = ['semua' => 'Semua', 'masuk' => 'Hadir', 'hadir' => 'Tepat Waktu', 'terlambat' => 'Terlambat',
            'tidak_hadir' => 'Tidak Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit'];
if ($tipe === 'guru') $pilihan += ['dinas' => 'Dinas Luar', 'cuti' => 'Cuti'];
$pilihan += ['alpha' => 'Alpha (Tanpa Keterangan)', 'libur' => 'Libur'];

$status = (string)($_GET['status'] ?? 'semua');
if (!array_key_exists($status, $pilihan)) $status = 'semua';

$grup = trim((string)($_GET['grup'] ?? ''));   // siswa: id kelas, guru: nama jabatan

$roster = rosterAbsensi($pdo, $dc, $tipe, $ta, $tanggal, $tanggal);

// Daftar grup untuk filter, jumlah per status, dan baris sebelum difilter status
$daftarGrup = [];
$jumlah = rekapKosong();
$baris = [];
foreach ($roster['orang'] as $k => $o) {
    $daftarGrup[(string)$o['grup_id']] = $o['grup'];
    if ($grup !== '' && (string)$o['grup_id'] !== $grup) continue;
    $st = $roster['status'][$k][$tanggal] ?? null;
    if (!$st) continue;
    $jumlah[$st['status']]++;
    $baris[] = $o + $st;
}
asort($daftarGrup, SORT_NATURAL);

$jumlahPilihan = function (string $key) use ($tipe, $jumlah, $baris): int {
    $izin = statusKelompok($tipe, $key);
    return $izin === null ? count($baris) : array_sum(array_intersect_key($jumlah, array_flip($izin)));
};

$izinkan = statusKelompok($tipe, $status);
$rows = $izinkan === null ? $baris : array_values(array_filter($baris, fn($r) => in_array($r['status'], $izinkan, true)));
usort($rows, fn($a, $b) => [$a['grup'], $a['nama']] <=> [$b['grup'], $b['nama']]);

$url = fn(array $ubah) => 'detail_status.php?' . http_build_query(array_merge(
    ['tipe' => $tipe, 'tanggal' => $tanggal, 'status' => $status, 'grup' => $grup], $ubah));

$hariNama = $HARI_ID[date('N', strtotime($tanggal)) - 1];
$awalBulan = date('Y-m-01', strtotime($tanggal));
?>
<style>
.status-pill { border:1px solid var(--line); background:#fff; color:#334155; border-radius:2rem;
  padding:.3rem .75rem; font-size:.85rem; display:inline-flex; align-items:center; gap:.4rem; }
.status-pill:hover { border-color:#93c5fd; color:#1d4ed8; }
.status-pill.aktif { background:var(--brand); border-color:var(--brand); color:#fff; }
.status-pill .jml { background:rgba(15,23,42,.08); border-radius:1rem; padding:0 .45rem; font-size:.75rem; font-weight:700; }
.status-pill.aktif .jml { background:rgba(255,255,255,.25); }
</style>

<div class="mb-3">
  <a href="index.php" class="small"><i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard</a>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tipe === 'siswa' ? 'active' : '' ?>" href="<?= e('detail_status.php?' . http_build_query(['tipe' => 'siswa', 'tanggal' => $tanggal, 'status' => in_array($status, ['dinas', 'cuti'], true) ? 'semua' : $status])) ?>">
      <i class="bi bi-people me-1"></i>Siswa
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tipe === 'guru' ? 'active' : '' ?>" href="<?= e('detail_status.php?' . http_build_query(['tipe' => 'guru', 'tanggal' => $tanggal, 'status' => $status])) ?>">
      <i class="bi bi-person-badge me-1"></i>Guru
    </a>
  </li>
</ul>

<form class="card card-stat mb-3"><div class="card-body row g-2 align-items-end">
  <input type="hidden" name="tipe" value="<?= e($tipe) ?>">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="col-md-3">
    <label class="form-label">Tanggal</label>
    <input type="date" class="form-control" name="tanggal" value="<?= e($tanggal) ?>">
  </div>
  <div class="col-md-4">
    <label class="form-label"><?= $tipe === 'guru' ? 'Jabatan' : 'Kelas' ?></label>
    <select class="form-select" name="grup">
      <option value="">— Semua <?= $tipe === 'guru' ? 'Jabatan' : 'Kelas' ?> —</option>
      <?php foreach ($daftarGrup as $gid => $gnama): ?>
        <option value="<?= e($gid) ?>" <?= (string)$gid === $grup ? 'selected' : '' ?>><?= e($gnama) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Cari nama / nomor induk</label>
    <input type="search" class="form-control" id="cari" placeholder="Ketik untuk menyaring...">
  </div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Tampilkan</button></div>
</div></form>

<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($pilihan as $key => $label): ?>
    <a class="status-pill <?= $key === $status ? 'aktif' : '' ?>" href="<?= e($url(['status' => $key])) ?>">
      <?= e($label) ?> <span class="jml"><?= $jumlahPilihan($key) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card card-stat">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="fw-semibold">
      <i class="bi bi-list-check me-1"></i><?= $tipe === 'guru' ? 'Guru' : 'Siswa' ?> — <?= e($pilihan[$status]) ?>
    </span>
    <span class="badge bg-primary"><?= e($hariNama) ?>, <?= e(date('d-m-Y', strtotime($tanggal))) ?> &middot; <?= count($rows) ?> orang</span>
  </div>
  <div class="card-body table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" id="tabelDetail">
      <thead><tr>
        <th style="width:50px">No</th>
        <th><?= $tipe === 'guru' ? 'NIP' : 'NIS' ?></th>
        <th>Nama</th>
        <th><?= $tipe === 'guru' ? 'Jabatan' : 'Kelas' ?></th>
        <th>Shift</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Status</th><th>Keterangan</th>
        <th class="text-end" style="width:120px">Aksi</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data untuk status ini.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $i => $r):
        $linkInfo = $tipe === 'guru'
            ? 'info_guru.php?' . http_build_query(['guru_id' => $r['id'], 'dari' => $awalBulan, 'sampai' => $tanggal])
            : 'info_siswa.php?' . http_build_query(['siswa_id' => $r['id'], 'dari' => $awalBulan, 'sampai' => $tanggal]);
        $linkKoreksi = $tipe === 'guru'
            ? 'koreksi_guru.php?' . http_build_query(['tanggal' => $tanggal])
            : 'koreksi_siswa.php?' . http_build_query(['tanggal' => $tanggal, 'kelas_id' => $r['grup_id']]);
      ?>
        <tr data-cari="<?= e(mb_strtolower($r['nama'] . ' ' . $r['kunci'])) ?>">
          <td class="no"><?= $i + 1 ?></td>
          <td class="small"><?= e($r['kunci']) ?></td>
          <td><?= e($r['nama']) ?></td>
          <td><?= e($r['grup']) ?></td>
          <td><?= $r['shift'] ? '<span class="badge bg-light text-dark border">' . e($r['shift']) . '</span>' : '<span class="text-muted">-</span>' ?></td>
          <td><?= e($r['jam_masuk'] ? substr($r['jam_masuk'], 0, 5) : '-') ?></td>
          <td><?= e($r['jam_pulang'] ? substr($r['jam_pulang'], 0, 5) : '-') ?></td>
          <td><span class="badge bg-<?= $STATUS_WARNA[$r['status']] ?>"><?= e($STATUS_DETAIL[$r['status']]) ?></span></td>
          <td class="small"><?= e($r['keterangan'] ?? '') ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e($linkInfo) ?>" title="Riwayat absensi bulan ini"><i class="bi bi-clock-history"></i></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($linkKoreksi) ?>" title="Koreksi absensi tanggal ini"><i class="bi bi-pencil-square"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Saring baris tabel berdasarkan nama atau nomor induk tanpa memuat ulang halaman
document.getElementById('cari').addEventListener('input', function () {
  const q = this.value.trim().toLowerCase();
  let no = 0;
  document.querySelectorAll('#tabelDetail tbody tr[data-cari]').forEach(tr => {
    const cocok = !q || tr.dataset.cari.includes(q);
    tr.hidden = !cocok;
    if (cocok) tr.querySelector('.no').textContent = ++no;
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
