<?php
$pageTitle = 'Info Absensi Per Jabatan (Periode Tanggal)';
require_once __DIR__ . '/includes/header.php';

// Jabatan = kolom teks guru.jabatan di datacenter -> diidentifikasi dengan NAMA.
// Daftar guru dari datacenter ($dc); rekap absensi dari database absensi ($pdo).
$jabatanList = dcJabatanList($dc);
$jabatan = trim($_GET['jabatan'] ?? '');
// Periode bawaan mengikuti tahun ajaran terpilih
[$dariBawaan, $sampaiBawaan] = periodeBawaan(tahunAjaranTerpilih($dc));
$dari = $_GET['dari'] ?? $dariBawaan;
$sampai = $_GET['sampai'] ?? $sampaiBawaan;
$rows = [];
if ($jabatan !== '') {
    // Rekap memakai aturan yang sama dengan Info Per Guru agar angkanya konsisten.
    // Absensi guru dikunci per NIP; rekap memakai aturan yang sama dengan Info Per Guru.
    $guru = dcGuruList($dc, $jabatan);
    $nipList = array_column($guru, 'nip');
    $rec = recAbsensi($pdo, 'guru', $nipList, $dari, $sampai);
    $rekap = rekapPeriode($pdo, 'guru', $nipList, $rec, $dari, $sampai, shiftPerHariBanyak($pdo, 'guru', $nipList));
    foreach ($guru as $g) {
        $c = $rekap[$g['nip']];
        $rows[] = [
            'nip' => $g['nip'], 'nama' => $g['nama'],
            'hadir' => $c['hadir'], 'terlambat' => $c['terlambat'],
            'izin' => $c['izin'], 'sakit' => $c['sakit'],
            'dinas' => $c['dinas'], 'cuti' => $c['cuti'], 'alpha' => $c['alpha'],
            // Total hari kerja (hari libur tidak dihitung)
            'total' => array_sum($c) - $c['libur'],
        ];
    }
}
?>
<form class="card card-stat mb-4"><div class="card-body row g-2 align-items-end">
  <div class="col-md-4">
    <label class="form-label">Jabatan</label>
    <select class="form-select" name="jabatan" required>
      <option value="">— Pilih Jabatan —</option>
      <?php foreach ($jabatanList as $namaJab): ?>
        <option value="<?= e($namaJab) ?>" <?= $namaJab===$jabatan?'selected':'' ?>><?= e($namaJab) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3"><label class="form-label">Dari Tanggal</label><input type="date" class="form-control" name="dari" value="<?= e($dari) ?>"></div>
  <div class="col-md-3"><label class="form-label">Sampai Tanggal</label><input type="date" class="form-control" name="sampai" value="<?= e($sampai) ?>"></div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Tampilkan</button></div>
</div></form>

<?php if ($jabatan !== ''): $qs = http_build_query(['type'=>'jabatan','jabatan'=>$jabatan,'dari'=>$dari,'sampai'=>$sampai]); ?>
<div class="mb-3">
  <a class="btn btn-success btn-sm" href="export.php?<?= $qs ?>&format=excel"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
  <a class="btn btn-danger btn-sm" href="export.php?<?= $qs ?>&format=pdf"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</a>
</div>
<div class="card card-stat"><div class="card-body table-responsive">
  <table class="table table-hover table-sm align-middle">
    <thead><tr><th>NIP</th><th>Nama Guru</th><th>Hadir</th><th>Terlambat</th><th>Izin</th><th>Sakit</th><th>Dinas Luar</th><th>Cuti</th><th>Tidak Hadir</th><th>Total Hari</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-muted">Tidak ada guru pada jabatan ini.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['nip']) ?></td><td><?= e($r['nama']) ?></td>
        <td><span class="badge bg-success"><?= (int)$r['hadir'] ?></span></td>
        <td><span class="badge bg-warning text-dark"><?= (int)$r['terlambat'] ?></span></td>
        <td><span class="badge bg-info"><?= (int)$r['izin'] ?></span></td>
        <td><span class="badge bg-primary"><?= (int)$r['sakit'] ?></span></td>
        <td><span class="badge bg-dark"><?= (int)$r['dinas'] ?></span></td>
        <td><span class="badge bg-secondary"><?= (int)$r['cuti'] ?></span></td>
        <td><span class="badge bg-danger"><?= (int)$r['alpha'] ?></span></td>
        <td><?= (int)$r['total'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
