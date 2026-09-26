<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$page = basename($_SERVER['PHP_SELF']);

// Link tunggal teratas
$dashboard = ['index.php', 'bi-speedometer2', 'Dashboard'];

// Grup sub-menu dropdown (collapse): [label, ikon grup, [ [file, ikon, label], ... ]]
$groups = [
    ['Setting Absensi', 'bi-gear', [
        ['setting_jadwal.php', 'bi-clock', 'Jadwal Jam Absensi'],
        ['setting_mesin.php', 'bi-cpu', 'Mesin Absensi & Upload'],
        ['adms_monitor.php', 'bi-broadcast', 'Monitor ADMS'],
        ['setting_libur.php', 'bi-calendar-x', 'Hari Libur'],
        ['setting_shift.php', 'bi-arrow-repeat', 'Jadwal Shift Siswa & Guru'],
    ]],
    ['Absensi Kartu RFID', 'bi-credit-card-2-front', [
        ['absensi_kartu.php', 'bi-display', 'Layar Tap Kartu'],
        ['kartu_rfid.php', 'bi-credit-card', 'Daftar Kartu RFID'],
    ]],
    ['Info Absensi Guru', 'bi-person-badge', [
        ['info_guru.php', 'bi-person-badge', 'Per Guru'],
        ['info_jabatan.php', 'bi-diagram-3', 'Per Jabatan'],
    ]],
    ['Info Absensi Siswa', 'bi-people', [
        ['info_siswa.php', 'bi-person', 'Per Siswa'],
        ['info_kelas.php', 'bi-people', 'Per Kelas'],
        ['laporan_harian_siswa.php', 'bi-calendar-check', 'Laporan Harian'],
    ]],
    ['Koreksi Absensi', 'bi-pencil-square', [
        ['koreksi_siswa.php', 'bi-pencil-square', 'Koreksi Siswa'],
        ['koreksi_guru.php', 'bi-pencil', 'Koreksi Guru'],
    ]],
];

// Menu bagian bawah (kelompok "Akun")
$akunItems = [
    ['data_user.php', 'bi-person-gear', 'Setting Profil'],
];

// Pemilih tahun ajaran — seluruh data yang bergantung tahun ajaran mengikuti pilihan ini.
$taAktifHdr  = tahunAjaranTerpilih($dc);
$taDaftarHdr = tahunAjaranList($dc);
// Kembali ke halaman yang sama beserta filternya setelah tahun ajaran diganti
$kembaliHdr  = $page . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$namaUser    = $_SESSION['user']['nama'] ?? 'Pengguna';
$inisialUser = strtoupper(mb_substr($namaUser, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1b2536">
<title><?= e($pageTitle ?? 'Absensi Sekolah') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<style>
@import url('https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800');
:root {
  --sb-w:258px;
  --sb-bg:#1b2536;            /* sidebar slate gelap */
  --sb-bg-2:#16202f;
  --brand:#2563eb;            /* aksen biru */
  --brand-600:#1d4ed8;
  --brand-050:#eff6ff;
  --line:#e2e8f0;
  --bs-primary:#2563eb; --bs-primary-rgb:37,99,235;
  --bs-link-color:#2563eb; --bs-link-color-rgb:37,99,235; --bs-link-hover-color:#1d4ed8;
}
body {
  font-family:'Plus Jakarta Sans', system-ui, -apple-system, "Segoe UI", sans-serif;
  color:#1e293b;
  background:#f1f5f9;
}
a { text-decoration:none; }

/* ---- Tombol & aksen ---- */
.btn-primary {
  --bs-btn-color:#fff; --bs-btn-hover-color:#fff; --bs-btn-active-color:#fff;
  --bs-btn-bg:#2563eb; --bs-btn-border-color:#2563eb;
  --bs-btn-hover-bg:#1d4ed8; --bs-btn-hover-border-color:#1d4ed8;
  --bs-btn-active-bg:#1e40af; --bs-btn-active-border-color:#1e40af;
  box-shadow:0 1px 2px rgba(15,23,42,.08);
}
.btn-outline-primary {
  --bs-btn-color:#2563eb; --bs-btn-border-color:#bfdbfe;
  --bs-btn-hover-bg:#2563eb; --bs-btn-hover-border-color:#2563eb;
  --bs-btn-active-bg:#1d4ed8;
}
.form-control:focus, .form-select:focus {
  border-color:#93c5fd; box-shadow:0 0 0 .2rem rgba(37,99,235,.15);
}
.form-check-input:checked { background-color:var(--brand); border-color:var(--brand); }

/* ---- Sidebar ---- */
.sidebar { background:var(--sb-bg); --bs-offcanvas-width:var(--sb-w); border:0; }
.sidebar .offcanvas-header { border-bottom:1px solid rgba(255,255,255,.08); padding:1.15rem 1.15rem; }
.sidebar .brand-link { color:#fff; font-weight:700; font-size:1.05rem; display:flex; align-items:center; gap:.55rem; }
.sidebar .brand-link i { font-size:1.2rem; }
.sidebar .offcanvas-body { padding:.5rem 0 1.5rem; overflow-y:auto; }
.sidebar a.nav-link {
  color:rgba(255,255,255,.72); padding:.62rem 1.15rem; font-size:.93rem;
  display:flex; align-items:center; border-left:3px solid transparent; transition:background .15s, color .15s;
}
.sidebar a.nav-link i { width:1.5rem; font-size:1rem; }
.sidebar a.nav-link:hover { color:#fff; background:rgba(255,255,255,.06); }
.sidebar a.nav-link.active {
  color:#fff; font-weight:600; background:rgba(255,255,255,.10); border-left-color:var(--brand);
}
.sidebar .group {
  color:rgba(255,255,255,.38); font-size:.7rem; font-weight:600; text-transform:uppercase;
  letter-spacing:.08em; padding:1.1rem 1.15rem .35rem;
}

/* Sub-menu (collapse) */
.sidebar .nav-group-toggle { cursor:pointer; }
.sidebar .nav-group-toggle .chev { margin-left:auto; font-size:.72rem; opacity:.7; transition:transform .2s ease; }
.sidebar .nav-group-toggle[aria-expanded="true"] .chev { transform:rotate(180deg); }
.sidebar .nav-group-toggle[aria-expanded="true"] { color:#fff; background:rgba(255,255,255,.05); }
.sidebar .submenu { background:var(--sb-bg-2); }
.sidebar .submenu a.nav-link { padding-left:2.85rem; font-size:.88rem; }
.sidebar .submenu a.nav-link i { width:1.25rem; font-size:.88rem; }

/* ---- Top bar ---- */
.topbar {
  background:#fff; border-bottom:1px solid var(--line);
  padding:.55rem .85rem; position:sticky; top:0; z-index:1020;
  display:flex; align-items:center; gap:.5rem; min-height:60px;
}
.topbar .btn-burger { border:0; background:transparent; color:#334155; padding:.25rem .5rem; line-height:1; }
.topbar .brand-mobile { color:#0f172a; font-weight:700; font-size:1rem; white-space:nowrap; }

/* Pil pemilih tahun ajaran */
.ta-pill {
  display:flex; align-items:center; gap:.45rem;
  border:1px solid var(--line); border-radius:.6rem; padding:.3rem .6rem; background:#fff;
}
.ta-pill i { color:#64748b; font-size:.95rem; }
.ta-pill select {
  border:0; outline:0; background:transparent; font-size:.88rem; font-weight:600;
  color:#0f172a; padding:0 .1rem; max-width:190px;
}
.ta-pill select:focus { box-shadow:none; }

/* Chip pengguna */
.user-chip {
  display:flex; align-items:center; gap:.5rem; background:#fff;
  border:1px solid var(--line); border-radius:.6rem; padding:.28rem .6rem; color:#0f172a;
}
.user-chip:hover { background:#f8fafc; }
.user-chip .avatar {
  width:30px; height:30px; border-radius:50%; background:var(--brand-050); color:var(--brand);
  display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem;
}
.user-chip .nama { font-size:.88rem; font-weight:600; }
.user-chip .tag {
  font-size:.62rem; font-weight:700; letter-spacing:.04em; background:#e2e8f0; color:#475569;
  padding:.12rem .4rem; border-radius:.3rem;
}
.user-chip::after { margin-left:.15rem; color:#94a3b8; }

/* ---- Konten ---- */
.main { padding:1.15rem; }
.main > h4.page-title { margin-bottom:1.15rem; font-size:1.5rem; font-weight:700; color:#0f172a; }

.card { border:1px solid var(--line); border-radius:.75rem; box-shadow:0 1px 2px rgba(15,23,42,.04); }
.card-header { background:#fff; border-bottom:1px solid var(--line); padding:.85rem 1.1rem; }
.card-stat { transition:box-shadow .2s, border-color .2s, transform .2s; }
.card-stat:hover { box-shadow:0 6px 20px -10px rgba(15,23,42,.18); border-color:#cbd5e1; }
.card-stat .icon { font-size:1.7rem; }
/* Kartu yang berfungsi sebagai tautan (dashboard) */
.card-link { display:block; color:inherit; }
.card-link:hover { color:inherit; border-color:#93c5fd; box-shadow:0 8px 24px -12px rgba(37,99,235,.35); transform:translateY(-2px); }
.card-link .lihat { font-size:.75rem; font-weight:600; color:var(--brand); opacity:.75; transition:opacity .15s; }
.card-link:hover .lihat { opacity:1; }
.table > :not(caption) > * > * { padding:.6rem .7rem; }
.table thead th { color:#475569; font-weight:600; font-size:.85rem; background:#f8fafc; }

/* ---- Select2 menyerupai form-select Bootstrap ---- */
.select2-container--bootstrap-5 .select2-selection {
  min-height: calc(1.5em + .75rem + 2px);
  padding: .375rem .75rem;
  border: 1px solid var(--bs-border-color, #dee2e6);
  border-radius: var(--bs-border-radius, .5rem);
  background-color:#fff; display:flex; align-items:center; font-size:1rem; line-height:1.5;
}
.select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { padding:0; line-height:1.5; color:#1e293b; }
.select2-container--bootstrap-5 .select2-selection--single .select2-selection__placeholder { color:#94a3b8; }
.select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow { height:100%; top:0; right:.5rem; }
.select2-container--bootstrap-5.select2-container--focus .select2-selection,
.select2-container--bootstrap-5.select2-container--open .select2-selection {
  border-color:#93c5fd; box-shadow:0 0 0 .2rem rgba(37,99,235,.15); outline:0;
}
.select2-container--bootstrap-5 .select2-results__option--highlighted { background-color:var(--brand); }

/* ---- Desktop: sidebar tetap, konten & topbar digeser ---- */
@media (min-width:992px){
  .sidebar { position:fixed; top:0; left:0; height:100vh; width:var(--sb-w);
             transform:none !important; visibility:visible !important; z-index:1000;
             display:flex; flex-direction:column; }
  .sidebar .offcanvas-header .btn-close { display:none; }
  .topbar { margin-left:var(--sb-w); padding:.55rem 1.6rem; }
  .main { margin-left:var(--sb-w); padding:1.5rem 1.6rem; }
}
/* Layar sangat kecil */
@media (max-width:575.98px){
  .main { padding:.85rem; }
  .table { font-size:.85rem; }
  .btn { --bs-btn-padding-x:.6rem; }
  .topbar { padding:.5rem .6rem; gap:.35rem; }
  /* Lebar dipatok (bukan sekadar maks) supaya teks panjang dipotong rapi, tidak meluber */
  .ta-pill { padding:.28rem .45rem; }
  .ta-pill select { width:104px; max-width:104px; font-size:.78rem; }
  .user-chip { padding:.24rem .45rem; }
}
</style>
</head>
<body>

<!-- Sidebar / menu -->
<div class="sidebar offcanvas offcanvas-start" tabindex="-1" id="sidebar" aria-labelledby="sidebarBrand">
  <div class="offcanvas-header">
    <a class="brand-link" id="sidebarBrand" href="index.php"><i class="bi bi-fingerprint"></i>Absensi Sekolah</a>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
  </div>
  <div class="offcanvas-body">
    <ul class="nav flex-column" id="sidebarNav">
      <!-- Link tunggal -->
      <li><a class="nav-link <?= in_array($page, [$dashboard[0], 'detail_status.php'], true) ? 'active' : '' ?>" href="<?= $dashboard[0] ?>"><i class="bi <?= $dashboard[1] ?>"></i><?= e($dashboard[2]) ?></a></li>

      <!-- Grup sub-menu berupa dropdown -->
      <?php foreach ($groups as $gi => [$glabel, $gicon, $items]): ?>
        <?php $activeGroup = in_array($page, array_column($items, 0), true); $cid = 'grp' . $gi; ?>
        <li>
          <a class="nav-link nav-group-toggle <?= $activeGroup ? '' : 'collapsed' ?>" data-bs-toggle="collapse" href="#<?= $cid ?>" role="button" aria-expanded="<?= $activeGroup ? 'true' : 'false' ?>" aria-controls="<?= $cid ?>">
            <i class="bi <?= $gicon ?>"></i><span><?= e($glabel) ?></span><i class="bi bi-chevron-down chev"></i>
          </a>
          <div class="collapse <?= $activeGroup ? 'show' : '' ?>" id="<?= $cid ?>" data-bs-parent="#sidebarNav">
            <ul class="nav flex-column submenu">
              <?php foreach ($items as [$file, $icon, $label]): ?>
                <li><a class="nav-link <?= $page === $file ? 'active' : '' ?>" href="<?= $file ?>"><i class="bi <?= $icon ?>"></i><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </li>
      <?php endforeach; ?>

      <li class="group">Akun</li>
      <?php foreach ($akunItems as [$file, $icon, $label]): ?>
        <li><a class="nav-link <?= $page === $file ? 'active' : '' ?>" href="<?= $file ?>"><i class="bi <?= $icon ?>"></i><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<!-- Top bar: pemilih tahun ajaran & akun -->
<nav class="topbar">
  <button class="btn-burger d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Buka menu">
    <i class="bi bi-list fs-3"></i>
  </button>
  <a class="brand-mobile d-lg-none" href="index.php"><i class="bi bi-fingerprint me-1"></i>Absensi</a>

  <div class="ms-auto d-flex align-items-center gap-2">
    <?php if ($taDaftarHdr): ?>
    <form method="post" action="set_ta.php" class="mb-0">
      <input type="hidden" name="kembali" value="<?= e($kembaliHdr) ?>">
      <div class="ta-pill">
        <i class="bi bi-calendar-event"></i>
        <select name="ta_id" class="no-select2" onchange="this.form.submit()" aria-label="Tahun Ajaran">
          <?php foreach ($taDaftarHdr as $taOpt): ?>
            <option value="<?= $taOpt['id'] ?>" <?= (int)$taOpt['id'] === (int)($taAktifHdr['id'] ?? 0) ? 'selected' : '' ?>>
              T.A. <?= e($taOpt['nama_tahun_ajaran']) ?><?= (int)$taOpt['is_aktif'] === 1 ? ' (aktif)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <?php endif; ?>

    <div class="dropdown">
      <button class="user-chip dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="avatar"><?= e($inisialUser) ?></span>
        <span class="nama d-none d-sm-inline"><?= e($namaUser) ?></span>
        <span class="tag d-none d-md-inline">ADMIN</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        <li><a class="dropdown-item" href="data_user.php"><i class="bi bi-person-gear me-2"></i>Setting Profil</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="main">
<h4 class="page-title"><?= e($pageTitle ?? '') ?></h4>
<?php if ($taAktifHdr && (int)$taAktifHdr['is_aktif'] !== 1): ?>
  <div class="alert alert-warning py-2 small">
    <i class="bi bi-clock-history me-1"></i>Anda sedang melihat <b>tahun ajaran lampau <?= e($taAktifHdr['nama_tahun_ajaran']) ?></b>
    (<?= e(date('d-m-Y', strtotime($taAktifHdr['tanggal_mulai']))) ?> s/d <?= e(date('d-m-Y', strtotime($taAktifHdr['tanggal_selesai']))) ?>).
    Roster siswa, kelas, dan laporan mengikuti tahun ajaran tersebut.
  </div>
<?php endif; ?>
