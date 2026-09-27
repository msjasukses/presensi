<?php
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$type   = $_GET['type'] ?? '';     // guru | jabatan | siswa | kelas
$format = $_GET['format'] ?? '';   // excel | pdf
$dari   = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');
$periode = date('d-m-Y', strtotime($dari)) . ' s/d ' . date('d-m-Y', strtotime($sampai));

$title = ''; $subtitle = ''; $head = []; $body = []; $filename = 'laporan';

if ($type === 'guru' || $type === 'siswa') {
    // Laporan detail harian per orang. Master dari datacenter ($dc), absensi dari $pdo.
    if ($type === 'guru') {
        $id = (int)($_GET['guru_id'] ?? 0);
        $p = dcGuru($dc, $id);
        if (!$p) die('Guru tidak ditemukan.');
        $title = 'Laporan Absensi Guru';
        $subtitle = $p['nama'] . ' (NIP: ' . $p['nip'] . ' — ' . $p['jabatan'] . ') | Periode: ' . $periode;
        // Absensi guru dikunci per NIP (tabel absensi_guru berbentuk log event)
        $tipe = 'guru';
        $rec = recAbsensi($pdo, 'guru', [$p['nip']], $dari, $sampai)[$p['nip']] ?? [];
        $filename = 'absensi_guru_' . preg_replace('/[^a-z0-9]+/i', '_', $p['nama']);
    } else {
        $id = (int)($_GET['siswa_id'] ?? 0);
        $taX = tahunAjaranTerpilih($dc);
        $p = $taX ? dcSiswa($dc, (int)$taX['id'], $id) : null;
        if (!$p) die('Siswa tidak ditemukan.');
        $title = 'Laporan Absensi Siswa';
        $subtitle = $p['nama'] . ' (NIS: ' . $p['nis'] . ' — Kelas ' . ($p['kelas'] ?? '-') . ') | Periode: ' . $periode;
        // Absensi siswa dikunci per NIS (tabel absensi_siswa berbentuk log event)
        $tipe = 'siswa';
        $rec = recAbsensi($pdo, 'siswa', [$p['nis']], $dari, $sampai)[$p['nis']] ?? [];
        $filename = 'absensi_siswa_' . preg_replace('/[^a-z0-9]+/i', '_', $p['nama']);
    }
    // Laporan per tanggal: setiap hari dalam rentang, status dari setting jadwal + catatan absensi
    $shiftLap = $tipe === 'guru'
        ? shiftPerHari($pdo, 'guru', $p['nip'])
        : (!empty($p['kelas_id']) ? shiftPerHari($pdo, 'siswa', (int)$p['kelas_id']) : []);
    ['rows' => $lap, 'rekap' => $rekap] = laporanHarian($pdo, $tipe, $rec, $dari, $sampai, $shiftLap);
    $head = ['No', 'Tanggal', 'Hari', 'Jam Masuk', 'Jam Pulang', 'Status', 'Keterangan'];
    $no = 1;
    foreach ($lap as $r) {
        $body[] = [
            $no++,
            date('d-m-Y', strtotime($r['tanggal'])),
            $HARI_ID[date('N', strtotime($r['tanggal'])) - 1],
            $r['jam_masuk'] ? substr($r['jam_masuk'], 0, 5) : '-',
            $r['jam_pulang'] ? substr($r['jam_pulang'], 0, 5) : '-',
            $STATUS_DETAIL[$r['status']],
            $r['keterangan'] ?? '',
        ];
    }
    $footerText = 'Rekap: Hadir ' . $rekap['hadir'] . ' | Terlambat ' . $rekap['terlambat'] . ' | Izin ' . $rekap['izin']
        . ' | Sakit ' . $rekap['sakit']
        . ($tipe === 'guru' ? ' | Dinas Luar ' . $rekap['dinas'] . ' | Cuti ' . $rekap['cuti'] : '')
        . ' | Alpha ' . $rekap['alpha'] . ' | Libur ' . $rekap['libur'];

} elseif ($type === 'jabatan' || $type === 'kelas') {
    // Laporan rekap per grup. Anggota grup dari datacenter ($dc), rekap absensi dari $pdo.
    $members = []; // tiap item: ['noind'=>, 'nama'=>, 'c'=>[status=>n]]
    if ($type === 'jabatan') {
        $grup = trim($_GET['jabatan'] ?? '');
        if ($grup === '') die('Jabatan tidak ditemukan.');
        $title = 'Laporan Rekap Absensi Guru Per Jabatan';
        $subtitle = 'Jabatan: ' . $grup . ' | Periode: ' . $periode;
        $guru = dcGuruList($dc, $grup);
        $nipList = array_column($guru, 'nip');
        $rekap = rekapPeriode($pdo, 'guru', $nipList, recAbsensi($pdo, 'guru', $nipList, $dari, $sampai), $dari, $sampai, shiftPerHariBanyak($pdo, 'guru', $nipList));
        foreach ($guru as $g) $members[] = ['noind' => $g['nip'], 'nama' => $g['nama'], 'c' => $rekap[$g['nip']]];
        $head = ['No', 'NIP', 'Nama Guru', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Dinas Luar', 'Cuti', 'Alpha', 'Total Hari'];
        $filename = 'rekap_absensi_jabatan_' . preg_replace('/[^a-z0-9]+/i', '_', $grup);
    } else {
        $id = (int)($_GET['kelas_id'] ?? 0);
        $taX = tahunAjaranTerpilih($dc);
        $kelas = $taX ? dcKelas($dc, (int)$taX['id'], $id) : null;
        if (!$kelas) die('Kelas tidak ditemukan.');
        $grup = $kelas['nama'];
        $title = 'Laporan Rekap Absensi Siswa Per Kelas';
        $subtitle = 'Kelas: ' . $grup . ' | Periode: ' . $periode;
        $siswa = dcSiswaList($dc, (int)$taX['id'], $id);
        $nisList = array_column($siswa, 'nis');
        $shKelas = shiftPerHari($pdo, 'siswa', $id);
        $rekap = rekapPeriode($pdo, 'siswa', $nisList, recAbsensi($pdo, 'siswa', $nisList, $dari, $sampai), $dari, $sampai, $shKelas ? array_fill_keys($nisList, $shKelas) : []);
        foreach ($siswa as $s) $members[] = ['noind' => $s['nis'], 'nama' => $s['nama'], 'c' => $rekap[$s['nis']]];
        $head = ['No', 'NIS', 'Nama Siswa', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpha', 'Total Hari'];
        $filename = 'rekap_absensi_kelas_' . preg_replace('/[^a-z0-9]+/i', '_', $grup);
    }
    $no = 1;
    foreach ($members as $m) {
        $c = $m['c'];
        $row = [$no++, $m['noind'], $m['nama'], (int)$c['hadir'], (int)$c['terlambat'],
                (int)$c['izin'], (int)$c['sakit']];
        // Dinas luar & cuti hanya berlaku untuk guru
        if ($type === 'jabatan') { $row[] = (int)$c['dinas']; $row[] = (int)$c['cuti']; }
        $row[] = (int)$c['alpha'];
        // Total hari = hari sekolah/kerja dalam periode (hari libur tidak dihitung)
        $row[] = (int)array_sum($c) - (int)$c['libur'];
        $body[] = $row;
    }
    $footerText = '';
} elseif ($type === 'harian') {
    // Laporan absensi siswa untuk SATU tanggal, cakupan per rombel atau per tingkat.
    $taX = tahunAjaranTerpilih($dc);
    if (!$taX) die('Tidak ada tahun ajaran di datacenter.');
    $tanggal = $_GET['tanggal'] ?? date('Y-m-d');
    $cakupan = ($_GET['cakupan'] ?? 'rombel') === 'tingkat' ? 'tingkat' : 'rombel';
    $kelasId = (int)($_GET['kelas_id'] ?? 0);
    $tingkat = (int)($_GET['tingkat'] ?? 0);

    if ($cakupan === 'tingkat') {
        $siswa = dcSiswaList($dc, (int)$taX['id'], 0, $tingkat);
        $grup  = 'Tingkat ' . $tingkat;
        $filename = 'laporan_harian_tingkat_' . $tingkat . '_' . $tanggal;
    } else {
        $kelas = dcKelas($dc, (int)$taX['id'], $kelasId);
        if (!$kelas) die('Kelas tidak ditemukan.');
        $siswa = dcSiswaList($dc, (int)$taX['id'], $kelasId);
        $grup  = 'Kelas ' . $kelas['nama'];
        $filename = 'laporan_harian_' . preg_replace('/[^a-z0-9]+/i', '_', $kelas['nama']) . '_' . $tanggal;
    }

    $hariNama = $HARI_ID[date('N', strtotime($tanggal)) - 1];
    $title    = 'Laporan Harian Absensi Siswa';
    $subtitle = $grup . ' | ' . $hariNama . ', ' . date('d-m-Y', strtotime($tanggal));

    $nisList = array_column($siswa, 'nis');
    $rec     = recAbsensi($pdo, 'siswa', $nisList, $tanggal, $tanggal);
    $shiftK  = shiftPerHariBanyak($pdo, 'siswa', array_column($siswa, 'kelas_id'));
    $kalCache = [];
    $rekapH = rekapKosong();

    $head = ['No', 'NIS', 'Nama Siswa', 'Kelas', 'Shift', 'Jam Masuk', 'Jam Pulang', 'Status', 'Keterangan'];
    $no = 1;
    foreach ($siswa as $s) {
        $rid = (int)$s['kelas_id'];
        if (!isset($kalCache[$rid])) {
            $kalCache[$rid] = kalenderPeriode($pdo, 'siswa', $tanggal, $tanggal, $shiftK[$rid] ?? null);
        }
        $info = $kalCache[$rid][$tanggal] ?? ['libur' => false, 'batas' => '07:00:00', 'ket' => null, 'shift' => null];
        $a = $rec[$s['nis']][$tanggal] ?? null;
        ['status' => $st, 'keterangan' => $ket] = statusTanggal($info, $a);
        $rekapH[$st]++;
        $body[] = [
            $no++, $s['nis'], $s['nama'], $s['kelas'], $info['shift'] ?? '-',
            !empty($a['jam_masuk'])  ? substr($a['jam_masuk'], 0, 5)  : '-',
            !empty($a['jam_pulang']) ? substr($a['jam_pulang'], 0, 5) : '-',
            $STATUS_DETAIL[$st], $ket ?? '',
        ];
    }
    $footerText = 'Rekap: Hadir ' . $rekapH['hadir'] . ' | Terlambat ' . $rekapH['terlambat']
        . ' | Izin ' . $rekapH['izin'] . ' | Sakit ' . $rekapH['sakit']
        . ' | Alpha ' . $rekapH['alpha'] . ' | Libur ' . $rekapH['libur']
        . ' | Total ' . count($siswa) . ' siswa';
} else {
    die('Parameter type tidak valid.');
}

// ==== Bangun HTML tabel (dipakai Excel & PDF) ====
$html = '<h3 style="margin-bottom:2px">' . e($title) . '</h3>'
      . '<p style="margin-top:0">' . e($subtitle) . '</p>'
      . '<table border="1" cellspacing="0" cellpadding="5" style="border-collapse:collapse;width:100%;font-size:12px">'
      . '<thead><tr style="background:#e2e8f0;font-weight:bold"><th>' . implode('</th><th>', array_map('e', $head)) . '</th></tr></thead><tbody>';
if (!$body) {
    $html .= '<tr><td colspan="' . count($head) . '">Tidak ada data pada periode ini.</td></tr>';
}
foreach ($body as $row) {
    $html .= '<tr><td>' . implode('</td><td>', array_map('e', $row)) . '</td></tr>';
}
$html .= '</tbody></table>';
if ($footerText) $html .= '<p style="font-size:12px"><b>' . e($footerText) . '</b></p>';
$html .= '<p style="font-size:11px;color:#555">Dicetak: ' . date('d-m-Y H:i') . '</p>';

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
    exit;
}

if ($format === 'pdf') {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml('<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;}</style></head><body>' . $html . '</body></html>');
    $dompdf->setPaper('A4', count($head) > 7 ? 'landscape' : 'portrait');
    $dompdf->render();
    $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
    exit;
}

die('Parameter format tidak valid (excel|pdf).');
