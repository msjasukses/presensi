<?php
$pageTitle = 'Koreksi Absensi Siswa';
require_once __DIR__ . '/config.php';
requireLogin();

$msg = '';
// Tanggal bawaan mengikuti tahun ajaran terpilih (hari ini bila TA sedang berjalan)
$tanggal = $_GET['tanggal'] ?? $_POST['tanggal'] ?? tanggalAcuan(tahunAjaranTerpilih($dc));
$kelasId = (int)($_GET['kelas_id'] ?? $_POST['kelas_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    // absensi_siswa = log event. Koreksi satu hari = tulis ulang seluruh baris
    // siswa tsb pada tanggal itu: jam masuk -> kode 0, jam pulang -> kode 1,
    // ketidakhadiran -> kode 2/3/4 (satu baris, tanpa jam).
    simpanAbsensi(
        $pdo, 'siswa', trim($_POST['nis'] ?? ''), $tanggal,
        ($_POST['kode'] ?? '') === '' ? null : (int)$_POST['kode'],
        // Input jam di-disable saat status ketidakhadiran, jadi bisa tidak terkirim.
        ($_POST['jam_masuk'] ?? '') ?: null,
        ($_POST['jam_pulang'] ?? '') ?: null,
        ($_POST['keterangan'] ?? '') !== '' ? $_POST['keterangan'] : null
    );
    $msg = 'Koreksi absensi siswa berhasil disimpan.';
}

// Daftar siswa & kelas dari datacenter ($dc); catatan absensi tanggal ini dari $pdo, digabung di PHP.
$ta = tahunAjaranTerpilih($dc);
$kelasList = $ta ? dcKelasList($dc, (int)$ta['id']) : [];
$siswa = $ta ? dcSiswaList($dc, (int)$ta['id'], $kelasId) : [];
$rec = recAbsensi($pdo, 'siswa', array_column($siswa, 'nis'), $tanggal, $tanggal);
$rows = [];
foreach ($siswa as $s) {
    $a = $rec[$s['nis']][$tanggal] ?? null;
    $rows[] = [
        'nis' => $s['nis'], 'nama' => $s['nama'], 'kelas' => $s['kelas'],
        'jam_masuk' => $a['jam_masuk'] ?? null, 'jam_pulang' => $a['jam_pulang'] ?? null,
        // status catatan: null = belum ada catatan, 'sakit'/'izin'/'alpha' = kode 2/3/4
        'status' => $a['status'] ?? null, 'ada' => $a !== null, 'keterangan' => $a['keterangan'] ?? null,
    ];
}

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<form class="card card-stat mb-4"><div class="card-body row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label">Tanggal</label><input type="date" class="form-control" name="tanggal" value="<?= e($tanggal) ?>"></div>
  <div class="col-md-3">
    <label class="form-label">Kelas</label>
    <select class="form-select" name="kelas_id">
      <option value="0">— Semua Kelas —</option>
      <?php foreach ($kelasList as $k): ?><option value="<?= $k['id'] ?>" <?= $k['id']==$kelasId?'selected':'' ?>><?= e($k['nama']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Tampilkan</button></div>
</div></form>

<?php foreach ($rows as $r): ?>
<form method="post" id="fr<?= e($r['nis']) ?>">
  <input type="hidden" name="act" value="save">
  <input type="hidden" name="tanggal" value="<?= e($tanggal) ?>">
  <input type="hidden" name="kelas_id" value="<?= $kelasId ?>">
  <input type="hidden" name="nis" value="<?= e($r['nis']) ?>">
</form>
<?php endforeach; ?>

<div class="card card-stat"><div class="card-body table-responsive">
  <table class="table table-sm align-middle">
    <thead><tr>
      <th>NIS</th><th>Nama</th><th>Kelas</th>
      <th>Jam Masuk <span class="text-muted fw-normal">(kode 0)</span></th>
      <th>Jam Pulang <span class="text-muted fw-normal">(kode 1)</span></th>
      <th>Ketidakhadiran</th><th>Keterangan</th><th style="width:90px"></th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-muted">Tidak ada siswa pada kelas / tahun ajaran aktif.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $f = 'fr' . $r['nis']; $absen = $r['status'] !== null; ?>
      <tr class="<?= $r['ada'] ? '' : 'table-light' ?>">
        <td><?= e($r['nis']) ?></td>
        <td><?= e($r['nama']) ?></td>
        <td><?= e($r['kelas']) ?></td>
        <td><input type="time" class="form-control form-control-sm" name="jam_masuk" form="<?= $f ?>" value="<?= e($r['jam_masuk'] ? substr($r['jam_masuk'],0,5) : '') ?>" <?= $absen ? 'disabled' : '' ?>></td>
        <td><input type="time" class="form-control form-control-sm" name="jam_pulang" form="<?= $f ?>" value="<?= e($r['jam_pulang'] ? substr($r['jam_pulang'],0,5) : '') ?>" <?= $absen ? 'disabled' : '' ?>></td>
        <td>
          <select class="form-select form-select-sm" name="kode" form="<?= $f ?>" onchange="toggleJam(this)">
            <option value="">— Hadir (isi jam) —</option>
            <?php foreach (kodeKetidakhadiran('siswa') as $kode): ?>
              <option value="<?= $kode ?>" <?= $r['status'] === kodeKeStatus($kode) ? 'selected' : '' ?>>
                <?= $kode ?> — <?= e(kodeLabel($kode)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input class="form-control form-control-sm" name="keterangan" form="<?= $f ?>" value="<?= e($r['keterangan'] ?? '') ?>" placeholder="mis. surat izin"></td>
        <td><button class="btn btn-sm btn-primary w-100" form="<?= $f ?>"><i class="bi bi-save"></i></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="text-muted small">
    Baris abu-abu = belum ada catatan absensi pada tanggal tersebut.
    Pilih <b>Hadir</b> lalu isi jam untuk menyimpan event masuk (kode 0) dan pulang (kode 1);
    pilih Sakit/Ijin/Alpha untuk menyimpan satu baris ketidakhadiran (kode 2/3/4).
    Status <b>Terlambat</b> tidak disimpan — dihitung otomatis dari jam masuk terhadap batas terlambat.
  </div>
</div></div>

<script>
// Jam hanya relevan saat status Hadir; kunci input saat memilih sakit/ijin/alpha.
function toggleJam(sel) {
  const tr = sel.closest('tr');
  const absen = sel.value !== '';
  tr.querySelectorAll('input[type=time]').forEach(i => { i.disabled = absen; if (absen) i.value = ''; });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
