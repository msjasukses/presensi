<?php
/*
 * Menyimpan pilihan tahun ajaran ke sesi, lalu kembali ke halaman asal.
 * Dipakai oleh pemilih tahun ajaran di header.
 */
require_once __DIR__ . '/config.php';
requireLogin();

$id = (int)($_POST['ta_id'] ?? 0);
// Hanya terima id yang benar-benar ada di datacenter
foreach (tahunAjaranList($dc) as $ta) {
    if ((int)$ta['id'] === $id) { $_SESSION['ta_id'] = $id; break; }
}

// Batasi tujuan ke halaman di aplikasi ini saja (cegah pengalihan ke situs luar)
$kembali = $_POST['kembali'] ?? 'index.php';
if (!preg_match('#^[A-Za-z0-9_\-]+\.php(\?[^\s"\'<>]*)?$#', $kembali)) {
    $kembali = 'index.php';
}
header('Location: ' . $kembali);
exit;
