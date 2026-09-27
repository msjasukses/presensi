<?php
$pageTitle = 'Setting Jadwal Jam Absensi (Per Hari)';
require_once __DIR__ . '/config.php';
requireLogin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $up = $pdo->prepare('INSERT INTO jadwal_absensi (tipe, hari, jam_masuk, batas_terlambat, jam_pulang, libur)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE jam_masuk=VALUES(jam_masuk), batas_terlambat=VALUES(batas_terlambat),
                                jam_pulang=VALUES(jam_pulang), libur=VALUES(libur)');
    foreach (['siswa', 'guru'] as $tipe) {
        for ($h = 1; $h <= 7; $h++) {
            $row = $_POST['jadwal'][$tipe][$h] ?? [];
            $libur = !empty($row['libur']) ? 1 : 0;
            $jm = $libur ? null : (($row['jam_masuk'] ?? '') ?: null);
            $bt = $libur ? null : (($row['batas_terlambat'] ?? '') ?: null);
            $jp = $libur ? null : (($row['jam_pulang'] ?? '') ?: null);
            $up->execute([$tipe, $h, $jm, $bt, $jp, $libur]);
        }
    }
    $msg = 'Jadwal jam absensi per hari berhasil disimpan.';
}

// $jadwal[tipe][hari] => baris
$jadwal = [];
foreach ($pdo->query('SELECT * FROM jadwal_absensi') as $r) $jadwal[$r['tipe']][(int)$r['hari']] = $r;
$jam = fn($v) => $v ? substr($v, 0, 5) : '';

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<form method="post">
  <div class="row g-3">
    <?php foreach (['siswa' => 'Jadwal Absensi Siswa', 'guru' => 'Jadwal Absensi Guru'] as $tipe => $judul): ?>
    <div class="col-12 col-xl-6">
      <div class="card card-stat">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="fw-semibold"><?= e($judul) ?></span>
          <button type="button" class="btn btn-sm btn-outline-secondary btn-copy"><i class="bi bi-arrow-down-up me-1"></i>Samakan Senin ke semua</button>
        </div>
        <div class="card-body table-responsive">
          <table class="table table-sm align-middle mb-1">
            <thead>
              <tr>
                <th style="min-width:70px">Hari</th>
                <th>Jam Masuk</th>
                <th title="Lewat jam ini dihitung terlambat">Batas Terlambat</th>
                <th>Jam Pulang</th>
                <th class="text-center">Libur</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($HARI_ID as $i => $namaHari): $h = $i + 1; $j = $jadwal[$tipe][$h] ?? []; $isLibur = !empty($j['libur']); ?>
              <tr class="<?= $isLibur ? 'table-secondary' : '' ?>">
                <td class="fw-semibold"><?= e($namaHari) ?></td>
                <td><input type="time" class="form-control form-control-sm jam" data-role="masuk" name="jadwal[<?= $tipe ?>][<?= $h ?>][jam_masuk]" value="<?= e($jam($j['jam_masuk'] ?? '')) ?>" <?= $isLibur ? 'disabled' : 'required' ?>></td>
                <td><input type="time" class="form-control form-control-sm jam" data-role="terlambat" name="jadwal[<?= $tipe ?>][<?= $h ?>][batas_terlambat]" value="<?= e($jam($j['batas_terlambat'] ?? '')) ?>" <?= $isLibur ? 'disabled' : 'required' ?>></td>
                <td><input type="time" class="form-control form-control-sm jam" data-role="pulang" name="jadwal[<?= $tipe ?>][<?= $h ?>][jam_pulang]" value="<?= e($jam($j['jam_pulang'] ?? '')) ?>" <?= $isLibur ? 'disabled' : 'required' ?>></td>
                <td class="text-center">
                  <input type="checkbox" class="form-check-input chk-libur" name="jadwal[<?= $tipe ?>][<?= $h ?>][libur]" value="1" <?= $isLibur ? 'checked' : '' ?>>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="text-muted small">Centang <b>Libur</b> untuk hari tanpa jam sekolah — hari itu tampil <b>Libur</b> di laporan (bukan Alpha).</div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-primary mt-3"><i class="bi bi-save me-1"></i>Simpan Jadwal</button>
</form>

<script>
// Nonaktifkan input jam saat hari ditandai Libur
function syncRow(chk) {
  const tr = chk.closest('tr');
  tr.querySelectorAll('.jam').forEach(i => { i.disabled = chk.checked; if (chk.checked) i.removeAttribute('required'); else i.setAttribute('required', ''); });
  tr.classList.toggle('table-secondary', chk.checked);
}
document.querySelectorAll('.chk-libur').forEach(chk => {
  syncRow(chk);
  chk.addEventListener('change', () => syncRow(chk));
});
// Salin jam hari Senin ke semua hari yang bukan Libur
document.querySelectorAll('.btn-copy').forEach(btn => {
  btn.addEventListener('click', () => {
    const rows = [...btn.closest('.card').querySelectorAll('tbody tr')];
    const src = {};
    rows[0].querySelectorAll('.jam').forEach(i => src[i.dataset.role] = i.value);
    rows.slice(1).forEach(tr => {
      if (tr.querySelector('.chk-libur').checked) return;
      tr.querySelectorAll('.jam').forEach(i => i.value = src[i.dataset.role]);
    });
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
