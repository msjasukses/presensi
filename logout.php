<?php
// Muat config agar session dibuka dengan save_path yang sama seperti saat login,
// sehingga session yang benar-benar aktif yang dihapus.
require_once __DIR__ . '/config.php';

$_SESSION = [];

// Hapus juga cookie session di browser.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// Hapus juga penanda "Ingat saya" agar sesi berikutnya kembali normal.
if (isset($_COOKIE['absensi_ingat'])) {
    $q = session_get_cookie_params();
    setcookie('absensi_ingat', '', time() - 42000, $q['path'], $q['domain'], $q['secure'], $q['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
