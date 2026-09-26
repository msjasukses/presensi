<?php
$pageTitle = 'Koreksi Absensi Guru';
require_once __DIR__ . '/config.php';
requireLogin();

$msg = '';
// Tanggal bawaan mengikuti tahun ajaran terpilih (hari ini bila TA sedang berjalan)
$tanggal = $_GET['tanggal'] ?? $_POST['tanggal'] ?? tanggalAcuan(tahunAjaranTerpilih($dc));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    // absensi_guru = log event. Koreksi satu hari = tulis ulang seluruh baris
    // guru tsb pada tanggal itu: jam masuk -> kode 0, jam pulang -> kode 1,
    // ketidakhadiran -> kode 2/3/4/5/6 (satu baris, tanpa jam).
    simpanAbsensi(
        $pdo, 'guru', trim($_POST['nip'] ?? ''), $tanggal,
        ($_POST['kode'] ?? '') === '' ? null : (int)$_POST['kode'],
        // Input jam di-disable saat status ketidakhadiran, jadi bisa tidak terkirim.
        ($_POST['jam_masuk'] ?? '') ?: null,
        ($_POST['jam_pulang'] ?? '') ?: null,
        ($_POST['keterangan'] ?? '') !== '' ? $_POST['keterangan'] : null
    );
    $msg = 'Koreksi absensi guru berhasil disimpan.';
}

// Daftar guru dari datacenter ($dc); catatan absensi tanggal ini dari $pdo, digabung di PHP.
$guru = dcGuruList($dc);
$rec = recAbsensi($pdo, 'guru', array_column($guru, 'nip'), $tanggal, $tanggal);
$rows = [];
foreach ($guru as $g) {
    $a = $rec[$g['nip']][$tanggal] ?? null;
    $rows[] = [
        'nip' => $g['nip'], 'nama' => $g['nama'], 'jabatan' => $g['jabatan'],
        'jam_masuk' => $a['jam_masuk'] ?? null, 'jam_pulang' => $a['jam_pulang'] ?? null,
        // status catatan: null = belum ada catatan, selain itu hasil kode 2-6
        'status' => $a['status'] ?? null, 'ada' => $a !== null, 'keterangan' => $a['keterangan'] ?? null,
    ];
}

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<form class="card card-stat mb-4"><div class="card-body row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label">Tanggal</label><input type="date" class="form-control" name="tanggal" value="<?= e($tanggal) ?>"></div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Tampilkan</button></div>
</div></form>

<?php foreach ($rows as $r): ?>
<form method="post" id="fr<?= e($r['nip']) ?>">
  <input type="hidden" name="act" value="save">
  <input type="hidden" name="tanggal" value="<?= e($tanggal) ?>">
  <input type="hidden" name="nip" value="<?= e($r['nip']) ?>">
</form>
<?php endforeach; ?>

<div class="card card-stat"><div class="card-body table-responsive">
  <table class="table table-sm align-middle">
    <thead><tr>
      <th>NIP</th><th>Nama</th><th>Jabatan</th>
      <th>Jam Masuk <span class="text-muted fw-normal">(kode 0)</span></th>
      <th>Jam Pulang <span class="text-muted fw-normal">(kode 1)</span></th>
      <th>Ketidakhadiran</th><th>Keterangan</th><th style="width:90px"></th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-muted">Tidak ada guru aktif di datacenter.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $f = 'fr' . $r['nip']; $absen = $r['status'] !== null; ?>
      <tr class="<?= $r['ada'] ? '' : 'table-light' ?>">
        <td class="small"><?= e($r['nip']) ?></td>
        <td><?= e($r['nama']) ?></td>
        <td><?= e($r['jabatan']) ?></td>
        <td><input type="time" class="form-control form-control-sm" name="jam_masuk" form="<?= $f ?>" value="<?= e($r['jam_masuk'] ? substr($r['jam_masuk'],0,5) : '') ?>" <?= $absen ? 'disabled' : '' ?>></td>
        <td><input type="time" class="form-control form-control-sm" name="jam_pulang" form="<?= $f ?>" value="<?= e($r['jam_pulang'] ? substr($r['jam_pulang'],0,5) : '') ?>" <?= $absen ? 'disabled' : '' ?>></td>
        <td>
          <select class="form-select form-select-sm" name="kode" form="<?= $f ?>" onchange="toggleJam(this)">
            <option value="">— Hadir (isi jam) —</option>
            <?php foreach (kodeKetidakhadiran('guru') as $kode): ?>
              <option value="<?= $kode ?>" <?= $r['status'] === kodeKeStatus($kode) ? 'selected' : '' ?>>
                <?= $kode ?> — <?= e(kodeLabel($kode)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input class="form-control form-control-sm" name="keterangan" form="<?= $f ?>" value="<?= e($r['keterangan'] ?? '') ?>" placeholder="mis. dinas ke dinas pendidikan"></td>
        <td><button class="btn btn-sm btn-primary w-100" form="<?= $f ?>"><i class="bi bi-save"></i></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="text-muted small">
    Baris abu-abu = belum ada catatan absensi pada tanggal tersebut.
    Pilih <b>Hadir</b> lalu isi jam untuk menyimpan event masuk (kode 0) dan pulang (kode 1);
    pilih Sakit/Ijin/Alpha/Dinas Luar/Cuti untuk menyimpan satu baris ketidakhadiran (kode 2–6).
    Status <b>Terlambat</b> tidak disimpan — dihitung otomatis dari jam masuk terhadap batas terlambat.
  </div>
</div></div>

<script>
// Jam hanya relevan saat status Hadir; kunci input saat memilih kode ketidakhadiran.
function toggleJam(sel) {
  const tr = sel.closest('tr');
  const absen = sel.value !== '';
  tr.querySelectorAll('input[type=time]').forEach(i => { i.disabled = absen; if (absen) i.value = ''; });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
