<?php
$pageTitle = 'Setting Jadwal Shift (Siswa & Guru)';
require_once __DIR__ . '/config.php';
requireLogin();

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'shift_add') {
        $pdo->prepare('INSERT INTO shift (nama, jam_masuk, batas_terlambat, jam_pulang) VALUES (?,?,?,?)')
            ->execute([$_POST['nama'], $_POST['jam_masuk'], $_POST['batas_terlambat'], $_POST['jam_pulang']]);
        $msg = 'Shift berhasil ditambahkan.';

    } elseif ($act === 'shift_edit') {
        $pdo->prepare('UPDATE shift SET nama=?, jam_masuk=?, batas_terlambat=?, jam_pulang=? WHERE id=?')
            ->execute([$_POST['nama'], $_POST['jam_masuk'], $_POST['batas_terlambat'], $_POST['jam_pulang'], (int)$_POST['id']]);
        $msg = 'Shift berhasil diperbarui.';

    } elseif ($act === 'shift_delete') {
        try {
            $pdo->prepare('DELETE FROM shift WHERE id=?')->execute([(int)$_POST['id']]);
            $msg = 'Shift dihapus.';
        } catch (PDOException $e) {
            $err = 'Shift tidak bisa dihapus karena masih dipakai pada jadwal kelas atau guru.';
        }

    } elseif ($act === 'jadwal_guru') {
        // Jadwal shift guru dikunci per NIP, seragam dengan absensi_guru.
        $nip = trim($_POST['nip'] ?? '');
        $del = $pdo->prepare('DELETE FROM jadwal_shift_guru WHERE nip=? AND hari=?');
        $ins = $pdo->prepare('INSERT INTO jadwal_shift_guru (nip, hari, shift_id) VALUES (?,?,?)');
        for ($h = 1; $h <= 7; $h++) {
            $del->execute([$nip, $h]);
            $shiftId = (int)($_POST['hari'][$h] ?? 0);
            if ($shiftId) $ins->execute([$nip, $h, $shiftId]);
        }
        $msg = 'Jadwal shift guru berhasil disimpan.';

    } elseif ($act === 'jadwal_kelas') {
        // Shift siswa ditetapkan PER KELAS (rombel), berlaku untuk semua siswa
        // di rombel tersebut. rombel_id sudah spesifik per tahun ajaran.
        $rombelId = (int)($_POST['rombel_id'] ?? 0);
        $del = $pdo->prepare('DELETE FROM jadwal_shift_kelas WHERE rombel_id=? AND hari=?');
        $ins = $pdo->prepare('INSERT INTO jadwal_shift_kelas (rombel_id, hari, shift_id) VALUES (?,?,?)');
        for ($h = 1; $h <= 7; $h++) {
            $del->execute([$rombelId, $h]);
            $shiftId = (int)($_POST['hari'][$h] ?? 0);
            if ($shiftId) $ins->execute([$rombelId, $h, $shiftId]);
        }
        $msg = 'Jadwal shift kelas berhasil disimpan.';

    } elseif ($act === 'jadwal_kelas_semua') {
        // Terapkan pola shift satu kelas ke SELURUH kelas pada tahun ajaran ini
        $ta = tahunAjaranTerpilih($dc);
        $kelas = $ta ? dcKelasList($dc, (int)$ta['id']) : [];
        $del = $pdo->prepare('DELETE FROM jadwal_shift_kelas WHERE rombel_id=? AND hari=?');
        $ins = $pdo->prepare('INSERT INTO jadwal_shift_kelas (rombel_id, hari, shift_id) VALUES (?,?,?)');
        foreach ($kelas as $k) {
            for ($h = 1; $h <= 7; $h++) {
                $del->execute([$k['id'], $h]);
                $shiftId = (int)($_POST['hari'][$h] ?? 0);
                if ($shiftId) $ins->execute([$k['id'], $h, $shiftId]);
            }
        }
        $msg = 'Jadwal shift diterapkan ke ' . count($kelas) . ' kelas.';
    }
}

$shiftList = $pdo->query('SELECT * FROM shift ORDER BY jam_masuk, nama')->fetchAll();

// ---- Data siswa (per kelas) ----
$ta        = tahunAjaranTerpilih($dc);
$kelasList = $ta ? dcKelasList($dc, (int)$ta['id']) : [];
$jadwalKelas = [];
foreach ($pdo->query('SELECT * FROM jadwal_shift_kelas') as $r) {
    $jadwalKelas[(int)$r['rombel_id']][(int)$r['hari']] = (int)$r['shift_id'];
}
$selRombel = (int)($_GET['rombel_id'] ?? ($_POST['rombel_id'] ?? ($kelasList[0]['id'] ?? 0)));

// ---- Data guru (per NIP) ----
$guruList = dcGuruList($dc);
$jadwalGuru = [];
foreach ($pdo->query('SELECT * FROM jadwal_shift_guru') as $r) {
    $jadwalGuru[$r['nip']][(int)$r['hari']] = (int)$r['shift_id'];
}
$selNip = trim((string)($_GET['nip'] ?? ($_POST['nip'] ?? ($guruList[0]['nip'] ?? ''))));

$jam = fn($v) => $v ? substr($v, 0, 5) : '';

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle me-1"></i>
  Shift <b>menimpa</b> Setting Jadwal Jam Absensi pada hari yang diberi shift. Hari yang dibiarkan
  <i>Tidak ada shift</i> tetap memakai jadwal umum. Jam masuk pada shift menentukan
  <b>batas terlambat</b> yang dipakai laporan.
</div>

<!-- ============ Daftar Shift ============ -->
<div class="card card-stat mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-clock me-1"></i>Daftar Shift</div>
  <div class="card-body table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Nama Shift</th><th>Jam Masuk</th><th>Batas Terlambat</th><th>Jam Pulang</th><th style="width:150px"></th></tr></thead>
      <tbody>
      <?php foreach ($shiftList as $s): ?>
        <tr>
          <form method="post">
            <input type="hidden" name="act" value="shift_edit">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <td><input class="form-control form-control-sm" name="nama" value="<?= e($s['nama']) ?>" required></td>
            <td><input type="time" class="form-control form-control-sm" name="jam_masuk" value="<?= e($jam($s['jam_masuk'])) ?>" required></td>
            <td><input type="time" class="form-control form-control-sm" name="batas_terlambat" value="<?= e($jam($s['batas_terlambat'])) ?>" required></td>
            <td><input type="time" class="form-control form-control-sm" name="jam_pulang" value="<?= e($jam($s['jam_pulang'])) ?>" required></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-primary" title="Simpan perubahan"><i class="bi bi-save"></i></button>
          </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus shift <?= e($s['nama']) ?>?')">
                <input type="hidden" name="act" value="shift_delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Hapus shift"><i class="bi bi-trash"></i></button>
              </form>
            </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shiftList): ?><tr><td colspan="5" class="text-muted">Belum ada shift.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <form method="post" class="row g-2 align-items-end border-top pt-3">
      <input type="hidden" name="act" value="shift_add">
      <div class="col-md-3"><label class="form-label small mb-1">Nama shift baru</label><input class="form-control form-control-sm" name="nama" placeholder="mis. Siang" required></div>
      <div class="col-md-2"><label class="form-label small mb-1">Jam masuk</label><input type="time" class="form-control form-control-sm" name="jam_masuk" required></div>
      <div class="col-md-2"><label class="form-label small mb-1">Batas terlambat</label><input type="time" class="form-control form-control-sm" name="batas_terlambat" required></div>
      <div class="col-md-2"><label class="form-label small mb-1">Jam pulang</label><input type="time" class="form-control form-control-sm" name="jam_pulang" required></div>
      <div class="col-md-2"><button class="btn btn-sm btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Tambah</button></div>
    </form>
  </div>
</div>

<div class="row g-3">

  <!-- ============ Shift SISWA (per kelas) ============ -->
  <div class="col-12 col-xl-6">
    <div class="card card-stat h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i>Jadwal Shift Siswa (per Kelas)</div>
      <div class="card-body">
        <?php if (!$kelasList): ?>
          <div class="text-muted">Belum ada kelas pada tahun ajaran ini.</div>
        <?php else: ?>
        <form method="get" class="mb-3">
          <label class="form-label">Pilih Kelas</label>
          <select class="form-select" name="rombel_id" onchange="this.form.submit()">
            <?php foreach ($kelasList as $k): ?>
              <option value="<?= $k['id'] ?>" <?= (int)$k['id'] === $selRombel ? 'selected' : '' ?>>
                <?= e($k['nama']) ?> — Tingkat <?= e($k['tingkat']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <form method="post" id="formKelas">
          <input type="hidden" name="act" value="jadwal_kelas" id="aksiKelas">
          <input type="hidden" name="rombel_id" value="<?= $selRombel ?>">
          <table class="table table-sm align-middle">
            <thead><tr><th style="width:90px">Hari</th><th>Shift</th></tr></thead>
            <tbody>
            <?php foreach ($HARI_ID as $i => $hari): $h = $i + 1; ?>
              <tr>
                <td class="fw-semibold"><?= e($hari) ?></td>
                <td>
                  <select class="form-select form-select-sm" name="hari[<?= $h ?>]">
                    <option value="0">— Tidak ada shift (pakai jadwal umum) —</option>
                    <?php foreach ($shiftList as $s): ?>
                      <option value="<?= $s['id'] ?>" <?= ($jadwalKelas[$selRombel][$h] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['nama']) ?> (<?= e($jam($s['jam_masuk'])) ?>–<?= e($jam($s['jam_pulang'])) ?>, telat &gt; <?= e($jam($s['batas_terlambat'])) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan untuk Kelas Ini</button>
            <button type="button" class="btn btn-outline-secondary" onclick="terapkanSemuaKelas()">
              <i class="bi bi-copy me-1"></i>Terapkan ke Semua Kelas
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ============ Shift GURU (per NIP) ============ -->
  <div class="col-12 col-xl-6">
    <div class="card card-stat h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-1"></i>Jadwal Shift Guru (per Guru)</div>
      <div class="card-body">
        <?php if (!$guruList): ?>
          <div class="text-muted">Belum ada guru aktif di datacenter.</div>
        <?php else: ?>
        <form method="get" class="mb-3">
          <label class="form-label">Pilih Guru</label>
          <select class="form-select" name="nip" onchange="this.form.submit()">
            <?php foreach ($guruList as $g): ?>
              <option value="<?= e($g['nip']) ?>" <?= $g['nip'] === $selNip ? 'selected' : '' ?>>
                <?= e($g['nama']) ?> — <?= e($g['jabatan']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($selNip !== ''): ?>
        <form method="post">
          <input type="hidden" name="act" value="jadwal_guru">
          <input type="hidden" name="nip" value="<?= e($selNip) ?>">
          <table class="table table-sm align-middle">
            <thead><tr><th style="width:90px">Hari</th><th>Shift</th></tr></thead>
            <tbody>
            <?php foreach ($HARI_ID as $i => $hari): $h = $i + 1; ?>
              <tr>
                <td class="fw-semibold"><?= e($hari) ?></td>
                <td>
                  <select class="form-select form-select-sm" name="hari[<?= $h ?>]">
                    <option value="0">— Tidak ada shift (pakai jadwal umum) —</option>
                    <?php foreach ($shiftList as $s): ?>
                      <option value="<?= $s['id'] ?>" <?= ($jadwalGuru[$selNip][$h] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['nama']) ?> (<?= e($jam($s['jam_masuk'])) ?>–<?= e($jam($s['jam_pulang'])) ?>, telat &gt; <?= e($jam($s['batas_terlambat'])) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Jadwal Guru</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
// Terapkan pola shift kelas yang sedang tampil ke seluruh kelas tahun ajaran ini
function terapkanSemuaKelas() {
  if (!confirm('Terapkan pola shift ini ke SEMUA kelas pada tahun ajaran yang sedang dipilih?\nJadwal shift kelas lain akan ditimpa.')) return;
  document.getElementById('aksiKelas').value = 'jadwal_kelas_semua';
  document.getElementById('formKelas').submit();
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
