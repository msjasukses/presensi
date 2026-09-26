<?php
$pageTitle = 'Daftar Kartu RFID';
require_once __DIR__ . '/config.php';
requireLogin();

/**
 * Pasangkan kartu ke seseorang. Satu orang satu kartu: kartu lama orang itu
 * diganti. Kartu yang sudah dipakai orang lain ditolak (hapus dulu di sana).
 */
function kartuSimpan(PDO $pdo, PDO $dc, string $tipe, string $induk, string $uidMentah): array {
    $uid = kartuNormal($uidMentah);
    if (!in_array($tipe, ['siswa', 'guru'], true) || $induk === '') return ['ok' => false, 'pesan' => 'Pemilik kartu belum dipilih.'];
    if ($uid === '') return ['ok' => false, 'pesan' => 'Nomor kartu kosong — tempelkan kartu ke reader.'];

    $st = $pdo->prepare('SELECT tipe, nomor_induk FROM kartu_rfid WHERE uid=?');
    $st->execute([$uid]);
    $lain = $st->fetch();
    if ($lain && !($lain['tipe'] === $tipe && $lain['nomor_induk'] === $induk)) {
        $o = kartuInfoOrang($dc, $lain['tipe'], $lain['nomor_induk']);
        return ['ok' => false, 'pesan' => "Kartu $uid sudah dipakai " . ($o['nama'] ?? $lain['nomor_induk'])
                                         . '. Hapus dulu kartu itu di daftar bila memang mau dipindahkan.'];
    }
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM kartu_rfid WHERE tipe=? AND nomor_induk=?')->execute([$tipe, $induk]);
    $pdo->prepare('INSERT INTO kartu_rfid (uid, tipe, nomor_induk) VALUES (?,?,?)')->execute([$uid, $tipe, $induk]);
    $pdo->commit();
    $o = kartuInfoOrang($dc, $tipe, $induk);
    return ['ok' => true, 'uid' => $uid, 'pesan' => "Kartu $uid terdaftar untuk " . ($o['nama'] ?? $induk) . '.'];
}

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'simpan') {
            $h = kartuSimpan($pdo, $dc, $_POST['tipe'] ?? '', trim($_POST['induk'] ?? ''), (string)($_POST['uid'] ?? ''));
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($h);
                exit;
            }
            $h['ok'] ? $msg = $h['pesan'] : $err = $h['pesan'];
        } elseif ($act === 'hapus') {
            $pdo->prepare('DELETE FROM kartu_rfid WHERE id=?')->execute([(int)$_POST['id']]);
            $msg = 'Kartu dihapus.';
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'pesan' => 'Gagal menyimpan: ' . $ex->getMessage()]);
            exit;
        }
        $err = 'Gagal: ' . $ex->getMessage();
    }
}

$ta         = tahunAjaranTerpilih($dc);
$siswaList  = $ta ? dcSiswaList($dc, (int)$ta['id']) : [];
$guruList   = dcGuruList($dc);
$rombelList = $ta ? dcKelasList($dc, (int)$ta['id']) : [];
$kelasId    = (int)($_GET['kelas'] ?? 0);

// Kartu terdaftar, dilengkapi nama dari datacenter
$kartu = $pdo->query('SELECT * FROM kartu_rfid ORDER BY dibuat DESC')->fetchAll();
$petaSiswa = []; foreach ($siswaList as $s) $petaSiswa[$s['nis']] = $s;
$petaGuru  = []; foreach ($guruList as $g) $petaGuru[$g['nip']] = $g;
$kartuOrang = [];
foreach ($kartu as &$k) {
    $o = $k['tipe'] === 'guru' ? ($petaGuru[$k['nomor_induk']] ?? null) : ($petaSiswa[$k['nomor_induk']] ?? null);
    if (!$o) {
        $info = kartuInfoOrang($dc, $k['tipe'], $k['nomor_induk']);
        $o = $info ? ['nama' => $info['nama'], 'kelas' => $info['kelas'], 'jabatan' => $info['kelas']] : null;
    }
    $k['nama']  = $o['nama'] ?? '(tidak ditemukan di datacenter)';
    $k['kelas'] = $k['tipe'] === 'guru' ? ($o['jabatan'] ?? '') : ($o['kelas'] ?? '');
    $kartuOrang[$k['tipe'] . ':' . $k['nomor_induk']] = $k['uid'];
}
unset($k);

// Kartu yang ditempel di Layar Tap tapi belum terdaftar
$takDikenal = $pdo->query("SELECT pin uid, MAX(waktu) terakhir, COUNT(*) kali FROM adms_scan
                           WHERE sn='KARTU' AND tipe IS NULL
                             AND pin NOT IN (SELECT uid FROM kartu_rfid)
                           GROUP BY pin ORDER BY terakhir DESC LIMIT 10")->fetchAll();

$siswaKelas = $kelasId ? array_values(array_filter($siswaList, fn($s) => (int)$s['kelas_id'] === $kelasId)) : [];

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="alert alert-info small">
  <i class="bi bi-info-circle me-1"></i>Pakai <b>reader RFID USB</b> (mode keyboard). Klik kolom <b>Nomor Kartu</b>, lalu tempelkan kartu —
  nomornya terisi otomatis. Setelah kartu terdaftar, buka <a href="absensi_kartu.php" class="alert-link">Layar Tap Kartu</a>
  di komputer dekat gerbang untuk absensi.
</div>

<div class="row g-4 mb-4">
  <!-- ============ Daftarkan per orang ============ -->
  <div class="col-lg-7">
    <div class="card card-stat h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-person-plus me-1"></i>Daftarkan Kartu per Orang</div>
      <div class="card-body">
        <form method="post" class="row g-3" id="formOrang">
          <input type="hidden" name="act" value="simpan">
          <input type="hidden" name="induk" id="f_induk">
          <div class="col-md-4">
            <label class="form-label">Tipe</label>
            <select class="form-select" name="tipe" id="f_tipe" onchange="gantiTipe()">
              <option value="siswa">Siswa</option>
              <option value="guru">Guru</option>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label">Pemilik Kartu</label>
            <div class="tipe-wrap" data-tipe="siswa">
              <select class="form-select" id="sel_siswa">
                <option value="">— pilih siswa —</option>
                <?php foreach ($siswaList as $s): $ada = isset($kartuOrang['siswa:' . $s['nis']]); ?>
                  <option value="<?= e($s['nis']) ?>"><?= e($s['nama']) ?> — <?= e($s['kelas']) ?> (<?= e($s['nis']) ?>)<?= $ada ? ' ✓ sudah punya kartu' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="tipe-wrap" data-tipe="guru">
              <select class="form-select" id="sel_guru">
                <option value="">— pilih guru —</option>
                <?php foreach ($guruList as $g): $ada = isset($kartuOrang['guru:' . $g['nip']]); ?>
                  <option value="<?= e($g['nip']) ?>"><?= e($g['nama']) ?> — <?= e($g['jabatan']) ?><?= $ada ? ' ✓ sudah punya kartu' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="col-md-8">
            <label class="form-label">Nomor Kartu</label>
            <input class="form-control form-control-lg font-monospace" name="uid" id="f_uid" placeholder="Klik di sini lalu tempelkan kartu" autocomplete="off" required>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <button class="btn btn-primary btn-lg w-100"><i class="bi bi-save me-1"></i>Simpan</button>
          </div>
          <div class="col-12 form-text mt-1">Bila orang tersebut sudah punya kartu, kartu lamanya diganti dengan kartu baru ini.</div>
        </form>
      </div>
    </div>
  </div>

  <!-- ============ Kartu tak dikenal ============ -->
  <div class="col-lg-5">
    <div class="card card-stat h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-question-circle me-1"></i>Kartu Belum Terdaftar (ditempel di Layar Tap)</div>
      <div class="card-body">
        <?php if (!$takDikenal): ?>
          <div class="text-muted small">Belum ada. Kartu yang ditempel di Layar Tap tapi belum terdaftar akan muncul di sini, jadi bisa langsung dipasangkan ke pemiliknya.</div>
        <?php else: ?>
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>No. Kartu</th><th>Terakhir</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($takDikenal as $t): ?>
            <tr>
              <td class="font-monospace"><?= e($t['uid']) ?> <span class="text-muted small">×<?= (int)$t['kali'] ?></span></td>
              <td class="small"><?= e(date('d-m H:i', strtotime($t['terakhir']))) ?></td>
              <td><button type="button" class="btn btn-sm btn-outline-primary" onclick="pakaiUid('<?= e($t['uid']) ?>')">Pakai</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ============ Daftarkan per kelas (berurutan) ============ -->
<div class="card card-stat mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i>Daftarkan Kartu per Kelas (Berurutan)</div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3">
      <div class="col-md-5">
        <select class="form-select" name="kelas" onchange="this.form.submit()">
          <option value="">— pilih kelas —</option>
          <?php foreach ($rombelList as $rb): ?>
            <option value="<?= $rb['id'] ?>" <?= $kelasId === (int)$rb['id'] ? 'selected' : '' ?>><?= e($rb['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-7 small text-muted d-flex align-items-center">
        Pilih kelas, lalu bagikan kartu ke siswa sesuai urutan. Siswa yang disorot adalah giliran berikutnya —
        tempelkan kartunya dan sistem otomatis pindah ke siswa berikutnya. Klik nama siswa untuk memilih giliran secara manual.
      </div>
    </form>

    <?php if ($kelasId): ?>
    <div class="row g-3 align-items-center mb-3">
      <div class="col-md-6">
        <div class="small text-muted">Giliran:</div>
        <div class="fs-4 fw-bold" id="giliranNama">—</div>
      </div>
      <div class="col-md-4">
        <input class="form-control form-control-lg font-monospace" id="uidKelas" placeholder="Tempelkan kartu…" autocomplete="off">
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-outline-secondary w-100" onclick="lewati()">Lewati</button>
      </div>
      <div class="col-12"><div class="small" id="statusKelas"></div></div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle" id="tabelKelas">
        <thead><tr><th style="width:50px">#</th><th>Nama</th><th>NIS</th><th>No. Kartu</th></tr></thead>
        <tbody>
        <?php foreach ($siswaKelas as $i => $s): $uid = $kartuOrang['siswa:' . $s['nis']] ?? ''; ?>
          <tr data-nis="<?= e($s['nis']) ?>" data-nama="<?= e($s['nama']) ?>" style="cursor:pointer">
            <td><?= $i + 1 ?></td>
            <td><?= e($s['nama']) ?></td>
            <td><?= e($s['nis']) ?></td>
            <td class="font-monospace kolom-uid"><?= $uid ? '<span class="badge bg-success">' . e($uid) . '</span>' : '<span class="text-muted">belum</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$siswaKelas): ?><tr><td colspan="4" class="text-muted">Tidak ada siswa di kelas ini.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ Kartu terdaftar ============ -->
<div class="card card-stat">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="fw-semibold">Kartu Terdaftar (<?= count($kartu) ?>)</span>
    <input class="form-control form-control-sm" style="max-width:260px" id="cari" placeholder="Cari nama / kelas / nomor kartu">
  </div>
  <div class="card-body table-responsive">
    <table class="table table-sm table-hover align-middle" id="tabelKartu">
      <thead><tr><th>Nama</th><th>Tipe</th><th>Kelas / Jabatan</th><th>NIS / NIP</th><th>No. Kartu</th><th>Didaftarkan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($kartu as $k): ?>
        <tr>
          <td><?= e($k['nama']) ?></td>
          <td><span class="badge bg-<?= $k['tipe'] === 'guru' ? 'primary' : 'info text-dark' ?>"><?= e(ucfirst($k['tipe'])) ?></span></td>
          <td><?= e($k['kelas']) ?></td>
          <td><?= e($k['nomor_induk']) ?></td>
          <td class="font-monospace"><?= e($k['uid']) ?></td>
          <td class="small"><?= e(date('d-m-Y H:i', strtotime($k['dibuat']))) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Hapus kartu milik <?= e(addslashes($k['nama'])) ?>?')">
              <input type="hidden" name="act" value="hapus"><input type="hidden" name="id" value="<?= $k['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$kartu): ?><tr><td colspan="7" class="text-muted">Belum ada kartu terdaftar.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// ---- Per orang ----
function gantiTipe() {
  const t = document.getElementById('f_tipe').value;
  document.querySelectorAll('.tipe-wrap').forEach(w => w.style.display = w.dataset.tipe === t ? '' : 'none');
}
gantiTipe();
document.getElementById('formOrang').addEventListener('submit', e => {
  const t = document.getElementById('f_tipe').value;
  const induk = document.getElementById(t === 'guru' ? 'sel_guru' : 'sel_siswa').value;
  if (!induk) { e.preventDefault(); alert('Pilih pemilik kartu dulu.'); return; }
  document.getElementById('f_induk').value = induk;
});
// Reader mengetik lalu menekan Enter: jangan langsung kirim bila pemilik belum dipilih
document.getElementById('f_uid').addEventListener('keydown', e => {
  if (e.key !== 'Enter') return;
  const t = document.getElementById('f_tipe').value;
  if (!document.getElementById(t === 'guru' ? 'sel_guru' : 'sel_siswa').value) { e.preventDefault(); alert('Pilih pemilik kartu dulu.'); }
});
function pakaiUid(uid) {
  const f = document.getElementById('f_uid');
  f.value = uid;
  f.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ---- Pencarian daftar kartu ----
document.getElementById('cari').addEventListener('input', e => {
  const q = e.target.value.toLowerCase();
  document.querySelectorAll('#tabelKartu tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});

// ---- Per kelas berurutan ----
(() => {
  const tabel = document.getElementById('tabelKelas');
  if (!tabel) return;
  const baris = [...tabel.querySelectorAll('tbody tr[data-nis]')];
  const input = document.getElementById('uidKelas');
  const status = document.getElementById('statusKelas');
  let aktif = null;

  function pilih(tr) {
    baris.forEach(b => b.classList.remove('table-warning'));
    aktif = tr;
    document.getElementById('giliranNama').textContent = tr ? tr.dataset.nama : 'Semua siswa sudah punya kartu 🎉';
    if (tr) { tr.classList.add('table-warning'); tr.scrollIntoView({ block: 'nearest' }); }
    input.focus();
  }
  const belumPunya = b => b.querySelector('.kolom-uid .badge') === null;
  function berikutnya(dari) {
    const mulai = dari ? baris.indexOf(dari) + 1 : 0;
    return baris.slice(mulai).find(belumPunya) || baris.slice(0, mulai).find(belumPunya) || null;
  }
  window.lewati = () => {
    if (!aktif) return;
    const i = baris.indexOf(aktif);
    pilih(baris.slice(i + 1).find(belumPunya) || berikutnya(null));
  };
  baris.forEach(b => b.addEventListener('click', () => pilih(b)));

  input.addEventListener('keydown', async e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const uid = input.value.trim();
    input.value = '';
    if (!uid || !aktif) return;
    const tr = aktif;
    const res = await fetch('kartu_rfid.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ act: 'simpan', ajax: '1', tipe: 'siswa', induk: tr.dataset.nis, uid })
    }).then(r => r.json()).catch(() => ({ ok: false, pesan: 'Tidak dapat menghubungi server.' }));
    status.className = 'small ' + (res.ok ? 'text-success' : 'text-danger');
    status.textContent = res.pesan;
    if (res.ok) {
      const sel = tr.querySelector('.kolom-uid');
      sel.innerHTML = '';
      const b = document.createElement('span');
      b.className = 'badge bg-success'; b.textContent = res.uid;
      sel.appendChild(b);
      pilih(berikutnya(tr));
    }
  });
  pilih(berikutnya(null));
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
