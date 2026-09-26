<?php
require_once __DIR__ . '/config.php';
if (!empty($_SESSION['user'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
        // Ganti id sesi setelah login untuk mencegah pembajakan sesi (session fixation)
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $user['id'], 'nama' => $user['nama']];

        // "Ingat saya": ditandai lewat cookie terpisah yang dibaca config.php saat
        // sesi dibuka, sekaligus memperpanjang umur cookie sesi yang sedang berjalan.
        $ingat  = !empty($_POST['ingat']);
        $params = session_get_cookie_params();
        $opsi   = [
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ($ingat) {
            $kedaluwarsa = time() + 60 * 60 * 24 * 30;   // 30 hari
            setcookie('absensi_ingat', '1', ['expires' => $kedaluwarsa] + $opsi);
            setcookie(session_name(), session_id(), ['expires' => $kedaluwarsa] + $opsi);
        } else {
            setcookie('absensi_ingat', '', ['expires' => time() - 3600] + $opsi);
        }

        header('Location: index.php');
        exit;
    }
    $error = 'Username atau password salah.';
}

// Nama sekolah dibaca dari datacenter supaya mengikuti identitas sekolah
$namaSekolah = 'Sekolah';
try {
    $n = $dc->query('SELECT nama_sekolah FROM sekolah LIMIT 1')->fetchColumn();
    if ($n) $namaSekolah = $n;
} catch (Throwable $e) { /* biarkan nilai bawaan bila tabel belum tersedia */ }
$inisial = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $namaSekolah) ?: 'S', 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#062275">
<title>Login - Absensi Sekolah</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
@import url('https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800');
:root { --navy:#062275; --brand:#14b8a6; --brand-600:#0d9488; }
* { box-sizing:border-box; }
body {
  margin:0; min-height:100vh;
  font-family:'Plus Jakarta Sans', system-ui, -apple-system, "Segoe UI", sans-serif;
  color:#1e293b; background:#f8fafc;
}
.auth-wrap { display:flex; min-height:100vh; }

/* ---------- Panel kiri: identitas & sambutan ---------- */
.auth-left {
  flex:0 0 58%; position:relative; overflow:hidden;
  display:flex; flex-direction:column; justify-content:space-between;
  padding:2.5rem 3.5rem; color:#fff;
  background:
    linear-gradient(rgba(6,34,117,.86), rgba(8,45,140,.86)),
    url('assets/img/gambar.jpg') center/cover no-repeat;
  background-color:var(--navy);
}
/* Cahaya lembut supaya gradasi tidak terasa datar */
.auth-left::after {
  content:''; position:absolute; inset:0; pointer-events:none;
  background:
    radial-gradient(at 85% 15%, rgba(20,184,166,.20) 0, transparent 45%),
    radial-gradient(at 10% 90%, rgba(56,189,248,.16) 0, transparent 50%);
}
.auth-left > * { position:relative; z-index:1; }
.brand-badge {
  width:52px; height:52px; border-radius:14px;
  display:flex; align-items:center; justify-content:center;
  background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.28);
  color:#fff; font-weight:800; font-size:1.35rem;
}
.auth-left h1 {
  font-size:clamp(2rem, 3.4vw, 3.1rem); font-weight:800;
  line-height:1.15; letter-spacing:-.02em; margin:0 0 1rem; max-width:16ch;
}
.auth-left p.lead-sub { font-size:1.05rem; color:rgba(255,255,255,.82); max-width:48ch; margin:0; }
.auth-left .kredit { font-size:.82rem; color:rgba(255,255,255,.65); }

/* ---------- Panel kanan: formulir ---------- */
.auth-right { flex:1; display:flex; align-items:center; justify-content:center; padding:2.5rem 1.5rem; }
.auth-form { width:100%; max-width:420px; }
.auth-form h2 { font-size:2rem; font-weight:800; letter-spacing:-.02em; margin:0 0 .35rem; color:#0f172a; }
.auth-form .sub { color:#64748b; font-size:.95rem; margin:0 0 2rem; }
.auth-form label.form-label { font-weight:600; font-size:.9rem; color:#334155; margin-bottom:.4rem; }
.auth-form .form-control {
  border:1px solid #cbd5e1; border-radius:.65rem; padding:.7rem .9rem; font-size:.98rem;
}
.auth-form .form-control:focus { border-color:var(--brand); box-shadow:0 0 0 .22rem rgba(20,184,166,.18); }
.pwd-wrap { position:relative; }
.pwd-wrap .form-control { padding-right:2.9rem; }
.pwd-toggle {
  position:absolute; right:.35rem; top:50%; transform:translateY(-50%);
  border:0; background:transparent; color:#94a3b8; padding:.45rem .55rem;
  line-height:1; border-radius:.5rem; cursor:pointer;
}
.pwd-toggle:hover { color:var(--brand-600); background:#f1f5f9; }
.form-check-input:checked { background-color:var(--brand); border-color:var(--brand); }
.form-check-label { font-size:.92rem; color:#475569; }
.btn-login {
  width:100%; border:0; border-radius:.7rem; padding:.8rem 1rem;
  font-weight:700; font-size:1rem; color:#fff;
  background:linear-gradient(135deg,#14b8a6 0%,#059669 100%);
  box-shadow:0 8px 24px -10px rgba(13,148,136,.6); transition:filter .15s, transform .15s;
}
.btn-login:hover { filter:brightness(1.05); }
.btn-login:active { transform:translateY(1px); }
.auth-form .bantuan { text-align:center; font-size:.85rem; color:#94a3b8; margin:1.5rem 0 0; }

/* ---------- Layar kecil: panel kiri jadi kepala ringkas ---------- */
@media (max-width: 991.98px) {
  .auth-wrap { flex-direction:column; }
  .auth-left { flex:none; padding:1.75rem 1.5rem 2rem; gap:1.25rem; }
  .auth-left h1 { font-size:1.6rem; max-width:none; }
  .auth-left p.lead-sub { font-size:.95rem; }
  .auth-left .kredit { display:none; }
  .auth-right { padding:2rem 1.25rem 3rem; align-items:flex-start; }
}
</style>
</head>
<body>
<div class="auth-wrap">

  <section class="auth-left">
    <div class="brand-badge"><?= e($inisial) ?></div>

    <div>
      <h1>Selamat datang di Sistem Absensi Sekolah.</h1>
      <p class="lead-sub">Kelola absensi siswa dan guru, jadwal, serta mesin absensi dalam satu tempat.</p>
    </div>

    <div class="kredit">&copy; <?= date('Y') ?> <?= e($namaSekolah) ?></div>
  </section>

  <section class="auth-right">
    <div class="auth-form">
      <h2>Login Absensi</h2>
      <p class="sub">Khusus untuk Admin sekolah.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2 small" role="alert">
          <i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input type="text" class="form-control" id="username" name="username"
                 placeholder="Masukkan username Anda" autocomplete="username" required autofocus>
        </div>

        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <div class="pwd-wrap">
            <input type="password" class="form-control" id="password" name="password"
                   placeholder="Masukkan password Anda" autocomplete="current-password" required>
            <button class="pwd-toggle" type="button" id="togglePwd"
                    aria-label="Tampilkan password" title="Tampilkan password">
              <i class="bi bi-eye" id="ikonPwd"></i>
            </button>
          </div>
        </div>

        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" value="1" name="ingat" id="ingat">
          <label class="form-check-label" for="ingat">Ingat saya di perangkat ini</label>
        </div>

        <button class="btn-login" type="submit">Login &rarr;</button>
      </form>

      <p class="bantuan">Lupa password? Hubungi administrator sistem.</p>
    </div>
  </section>

</div>

<script>
// Tampilkan / sembunyikan password
document.getElementById('togglePwd').addEventListener('click', function () {
  const input = document.getElementById('password');
  const ikon  = document.getElementById('ikonPwd');
  const lihat = input.type === 'password';
  input.type = lihat ? 'text' : 'password';
  ikon.className = lihat ? 'bi bi-eye-slash' : 'bi bi-eye';
  this.setAttribute('aria-label', lihat ? 'Sembunyikan password' : 'Tampilkan password');
  input.focus();
});
</script>
</body>
</html>
