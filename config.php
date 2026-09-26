<?php
// Pastikan session punya folder yang writable. Di sebagian hosting,
// session.save_path bawaan server tidak ada / tidak bisa ditulis sehingga
// login "berhasil" tapi session hilang. Cari folder pertama yang writable:
//   1) temp sistem (di luar web root, paling aman)
//   2) folder ./sessions di dalam aplikasi (fallback)
// Endpoint mesin absensi (ADMS) mendefinisikan TANPA_SESSION sebelum memuat file
// ini: mesin memanggil server tiap beberapa detik, jadi jangan bikin file session.
if (!defined('TANPA_SESSION')) {
    foreach ([sys_get_temp_dir() . '/presensi_sessions', __DIR__ . '/sessions'] as $sessionDir) {
        if (!is_dir($sessionDir)) { @mkdir($sessionDir, 0700, true); }
        if (is_dir($sessionDir) && is_writable($sessionDir)) {
            session_save_path($sessionDir);
            break;
        }
    }
    // "Ingat saya di perangkat ini": bila dipilih saat login, umur sesi & cookie
    // diperpanjang 30 hari. Tanpa itu sesi berakhir ketika browser ditutup.
    $ingatSaya = ($_COOKIE['absensi_ingat'] ?? '') === '1';
    $umurSesi  = $ingatSaya ? 60 * 60 * 24 * 30 : 0;
    if ($ingatSaya) {
        ini_set('session.gc_maxlifetime', (string)$umurSesi);
    }
    $cookieSesi = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $umurSesi,
        'path'     => $cookieSesi['path'],
        'domain'   => $cookieSesi['domain'],
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

/*
 * ============================================================================
 *  KONSEP 2 KONEKSI DATABASE
 * ----------------------------------------------------------------------------
 *  Koneksi 1 ($pdo) -> absensi_sekolah : data ABSENSI & konfigurasi aplikasi
 *                                        (absensi_siswa, absensi_guru, jadwal,
 *                                        mesin, shift, hari libur, users).
 *  Koneksi 2 ($dc)  -> datacenter_v2   : data MASTER (dibaca langsung / live):
 *                                        - siswa   = tabel siswa
 *                                        - guru    = tabel guru
 *                                        - jabatan = kolom guru.jabatan
 *                                        - kelas   = tabel rombongan_belajar
 *
 *  Data master TIDAK disalin/di-sync ke database absensi. Semua halaman
 *  membaca siswa/guru/jabatan/kelas langsung dari datacenter melalui $dc.
 *  Tabel absensi merujuk data datacenter tanpa FK lintas-database:
 *    absensi_siswa.nis           -> datacenter_v2.siswa.nis (fallback nisn)
 *    absensi_guru.nip            -> datacenter_v2.guru.nip
 *    jadwal_shift_guru.nip       -> datacenter_v2.guru.nip
 * ============================================================================
 */

/*
 * ---------------------------------------------------------------------------
 *  PENGATURAN PER SERVER (agar aplikasi bisa dipindah tanpa mengubah kode)
 * ---------------------------------------------------------------------------
 *  Urutan sumber nilai, yang pertama ditemukan dipakai:
 *    1. berkas config.local.php  (salin dari config.local.example.php)
 *    2. variabel lingkungan      (berguna di panel hosting seperti Virtualmin)
 *    3. nilai bawaan di bawah    (cocok untuk Laragon/XAMPP di komputer sendiri)
 *
 *  Saat pindah server, cukup buat satu berkas config.local.php — tidak ada
 *  berkas kode lain yang perlu disentuh. config.local.php diabaikan git,
 *  jadi setelan tiap server tidak saling menimpa.
 */
$konfLokal = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
if (!is_array($konfLokal)) $konfLokal = [];

function konf(string $kunci, string $bawaan): string {
    global $konfLokal;
    if (array_key_exists($kunci, $konfLokal)) return (string)$konfLokal[$kunci];
    $env = getenv($kunci);
    return $env !== false && $env !== '' ? $env : $bawaan;
}

// ---- Koneksi 1: Database Absensi ----
define('DB_HOST', konf('DB_HOST', '127.0.0.1'));
define('DB_PORT', konf('DB_PORT', '3306'));
define('DB_NAME', konf('DB_NAME', 'absensi_sekolah'));
define('DB_USER', konf('DB_USER', 'root'));
define('DB_PASS', konf('DB_PASS', ''));

// ---- Koneksi 2: Database Datacenter (sumber data master) ----
define('DC_HOST', konf('DC_HOST', '127.0.0.1'));
define('DC_PORT', konf('DC_PORT', '3306'));
define('DC_NAME', konf('DC_NAME', 'datacenter_v2'));
define('DC_USER', konf('DC_USER', 'root'));
define('DC_PASS', konf('DC_PASS', ''));

function connectPDO(string $host, string $port, string $name, string $user, string $pass, string $label): PDO {
    try {
        return new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die("Koneksi database $label gagal: " . $e->getMessage()
          . "\n\nPerbaiki di berkas config.local.php (salin dari config.local.example.php)"
          . " pada folder aplikasi, lalu muat ulang halaman ini.");
    }
}

$pdo = connectPDO(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, 'presensi (' . DB_NAME . ')');
$dc  = connectPDO(DC_HOST, DC_PORT, DC_NAME, DC_USER, DC_PASS, 'datacenter_v2 (' . DC_NAME . ')');

// ============================================================================
//  Helper data master (dari datacenter, via $dc)
// ============================================================================

/** Tahun ajaran aktif di datacenter. Return ['id'=>, 'nama_tahun_ajaran'=>] atau null. */
function tahunAjaranAktif(PDO $dc): ?array {
    return $dc->query("SELECT id, kode_tahun_ajaran, nama_tahun_ajaran, tanggal_mulai, tanggal_selesai, is_aktif
                       FROM tahun_ajaran WHERE is_aktif=1 LIMIT 1")->fetch() ?: null;
}

/** Semua tahun ajaran di datacenter, terbaru lebih dulu. */
function tahunAjaranList(PDO $dc): array {
    return $dc->query("SELECT id, kode_tahun_ajaran, nama_tahun_ajaran, tanggal_mulai, tanggal_selesai, is_aktif
                       FROM tahun_ajaran ORDER BY kode_tahun_ajaran DESC")->fetchAll();
}

/**
 * Tahun ajaran yang sedang DIPILIH pengguna (tersimpan di sesi, dipakai seluruh
 * halaman). Bila belum memilih atau pilihannya tidak valid lagi, jatuh ke tahun
 * ajaran aktif. Semua data yang bergantung tahun ajaran — roster siswa, kelas,
 * dan laporan — mengikuti nilai ini.
 */
function tahunAjaranTerpilih(PDO $dc): ?array {
    $daftar = tahunAjaranList($dc);
    if (!$daftar) return null;
    $pilihan = (int)($_SESSION['ta_id'] ?? 0);
    foreach ($daftar as $ta) if ((int)$ta['id'] === $pilihan) return $ta;
    foreach ($daftar as $ta) if ((int)$ta['is_aktif'] === 1) return $ta;
    return $daftar[0];
}

/**
 * Rentang tanggal bawaan untuk laporan, menyesuaikan tahun ajaran terpilih:
 *   - TA aktif  -> bulan berjalan (dibatasi agar tidak mundur sebelum TA dimulai)
 *   - TA lampau -> seluruh rentang tahun ajaran tersebut
 * @return array{0:string,1:string} [dari, sampai]
 */
function periodeBawaan(?array $ta): array {
    $dari   = date('Y-m-01');
    $sampai = date('Y-m-d');
    if (!$ta) return [$dari, $sampai];

    if (!(int)$ta['is_aktif']) {
        return [$ta['tanggal_mulai'] ?: $dari, $ta['tanggal_selesai'] ?: $sampai];
    }
    if (!empty($ta['tanggal_mulai']) && $dari < $ta['tanggal_mulai']) $dari = $ta['tanggal_mulai'];
    return [$dari, $sampai];
}

/** Tanggal acuan dashboard: hari ini bila masih dalam rentang TA, selain itu hari terakhir TA. */
function tanggalAcuan(?array $ta): string {
    $hariIni = date('Y-m-d');
    if (!$ta) return $hariIni;
    if (!empty($ta['tanggal_selesai']) && $hariIni > $ta['tanggal_selesai']) return $ta['tanggal_selesai'];
    if (!empty($ta['tanggal_mulai'])   && $hariIni < $ta['tanggal_mulai'])   return $ta['tanggal_mulai'];
    return $hariIni;
}

/** Daftar tingkat (kelas) unik pada tahun ajaran aktif, mis. [7,8,9]. */
function dcTingkatList(PDO $dc, int $taId): array {
    $st = $dc->prepare("SELECT DISTINCT tingkat FROM rombongan_belajar WHERE tahun_ajaran_id=? ORDER BY tingkat");
    $st->execute([$taId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Daftar kelas (rombongan belajar) pada tahun ajaran aktif. */
function dcKelasList(PDO $dc, int $taId): array {
    $st = $dc->prepare("SELECT id, nama_rombel AS nama, tingkat
                        FROM rombongan_belajar WHERE tahun_ajaran_id=?
                        ORDER BY tingkat, nama_rombel");
    $st->execute([$taId]);
    return $st->fetchAll();
}

/** Satu kelas by id, dibatasi tahun ajaran aktif. */
function dcKelas(PDO $dc, int $taId, int $id): ?array {
    $st = $dc->prepare("SELECT id, nama_rombel AS nama, tingkat
                        FROM rombongan_belajar WHERE id=? AND tahun_ajaran_id=?");
    $st->execute([$id, $taId]);
    return $st->fetch() ?: null;
}

/** Roster siswa aktif pada tahun ajaran aktif (opsional filter kelas/rombel). */
function dcSiswaList(PDO $dc, int $taId, int $kelasId = 0, int $tingkat = 0): array {
    $sql = "SELECT s.id, COALESCE(NULLIF(s.nis,''), s.nisn) AS nis, s.nama_siswa AS nama,
                   s.jenis_kelamin AS jk, rb.id AS kelas_id, rb.nama_rombel AS kelas
            FROM siswa s
            JOIN siswa_rombel sr ON sr.siswa_id = s.id AND sr.tahun_ajaran_id = ?
            JOIN rombongan_belajar rb ON rb.id = sr.rombongan_belajar_id
            WHERE s.is_aktif = 1 AND s.status_siswa = 'Aktif'";
    $args = [$taId];
    if ($kelasId) { $sql .= " AND rb.id = ?"; $args[] = $kelasId; }
    if ($tingkat) { $sql .= " AND rb.tingkat = ?"; $args[] = $tingkat; }
    $sql .= " ORDER BY rb.nama_rombel, s.nama_siswa";
    $st = $dc->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Satu siswa by id, beserta kelas pada tahun ajaran aktif (kelas bisa null bila belum ditempatkan). */
function dcSiswa(PDO $dc, int $taId, int $id): ?array {
    $st = $dc->prepare("SELECT s.id, COALESCE(NULLIF(s.nis,''), s.nisn) AS nis, s.nama_siswa AS nama,
                               s.jenis_kelamin AS jk, rb.id AS kelas_id, rb.nama_rombel AS kelas
                        FROM siswa s
                        LEFT JOIN siswa_rombel sr ON sr.siswa_id = s.id AND sr.tahun_ajaran_id = ?
                        LEFT JOIN rombongan_belajar rb ON rb.id = sr.rombongan_belajar_id
                        WHERE s.id = ?");
    $st->execute([$taId, $id]);
    return $st->fetch() ?: null;
}

/** Daftar guru aktif (opsional filter berdasarkan nama jabatan). */
function dcGuruList(PDO $dc, string $jabatan = ''): array {
    $sql = "SELECT id, nip, nama_ptk AS nama, jenis_kelamin AS jk,
                   COALESCE(NULLIF(TRIM(jabatan),''),'Guru') AS jabatan
            FROM guru WHERE is_aktif = 1";
    $args = [];
    if ($jabatan !== '') { $sql .= " AND COALESCE(NULLIF(TRIM(jabatan),''),'Guru') = ?"; $args[] = $jabatan; }
    $sql .= " ORDER BY nama_ptk";
    $st = $dc->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Satu guru by id. */
function dcGuru(PDO $dc, int $id): ?array {
    $st = $dc->prepare("SELECT id, nip, nama_ptk AS nama, jenis_kelamin AS jk,
                               COALESCE(NULLIF(TRIM(jabatan),''),'Guru') AS jabatan
                        FROM guru WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Daftar nama jabatan unik dari guru aktif (jabatan = kolom teks di datacenter). */
function dcJabatanList(PDO $dc): array {
    return $dc->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(jabatan),''),'Guru') AS nama
                       FROM guru WHERE is_aktif = 1 ORDER BY nama")->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================================================
//  KODE STATUS ABSENSI  (kolom status pada absensi_siswa & absensi_guru)
// ----------------------------------------------------------------------------
//  KEDUA tabel berbentuk LOG EVENT dengan struktur yang sama:
//    absensi_siswa : id, nis, tanggal, jam, status, keterangan
//    absensi_guru  : id, nip, tanggal, jam, status, keterangan
//
//      0 = masuk       -> kolom jam berisi jam scan masuk
//      1 = pulang      -> kolom jam berisi jam scan pulang
//      2 = sakit        3 = ijin        4 = alpha
//      5 = dinas luar   6 = cuti                    (kolom jam boleh NULL)
//
//  Satu orang pada satu tanggal bisa punya beberapa baris (mis. masuk + pulang).
//  Status laporan "hadir"/"terlambat" TIDAK disimpan — dihitung dari jam masuk
//  terhadap batas terlambat pada setting jadwal hari yang bersangkutan.
//  Kode 5 & 6 dipakai untuk guru; dropdown siswa hanya menawarkan kode 2-4.
// ============================================================================
const ABS_MASUK = 0, ABS_PULANG = 1, ABS_SAKIT = 2, ABS_IJIN = 3,
      ABS_ALPHA = 4, ABS_DINAS = 5, ABS_CUTI  = 6;

/** Kode ketidakhadiran yang bisa dipilih saat koreksi, per tipe. */
function kodeKetidakhadiran(string $tipe): array {
    return $tipe === 'guru'
        ? [ABS_SAKIT, ABS_IJIN, ABS_ALPHA, ABS_DINAS, ABS_CUTI]
        : [ABS_SAKIT, ABS_IJIN, ABS_ALPHA];
}

/** Label kode status untuk tampilan. */
function kodeLabel(int $kode): string {
    return [ABS_MASUK => 'Masuk', ABS_PULANG => 'Pulang', ABS_SAKIT => 'Sakit',
            ABS_IJIN  => 'Ijin',  ABS_ALPHA  => 'Alpha (Tidak Hadir)',
            ABS_DINAS => 'Dinas Luar', ABS_CUTI => 'Cuti'][$kode] ?? '-';
}

/** Kode ketidakhadiran (2-6) -> status laporan. Null untuk kode masuk/pulang. */
function kodeKeStatus(int $kode): ?string {
    return [ABS_SAKIT => 'sakit', ABS_IJIN  => 'izin',  ABS_ALPHA => 'alpha',
            ABS_DINAS => 'dinas', ABS_CUTI  => 'cuti'][$kode] ?? null;
}

/** Status laporan -> kode ketidakhadiran. Null bila bukan status ketidakhadiran. */
function statusKeKode(string $status): ?int {
    return ['sakit' => ABS_SAKIT, 'izin'  => ABS_IJIN,  'alpha' => ABS_ALPHA,
            'dinas' => ABS_DINAS, 'cuti'  => ABS_CUTI][$status] ?? null;
}

/** Rekap kosong (semua status laporan bernilai 0) — satu sumber kebenaran urutan status. */
function rekapKosong(): array {
    return ['hadir'=>0, 'terlambat'=>0, 'izin'=>0, 'sakit'=>0,
            'dinas'=>0, 'cuti'=>0, 'alpha'=>0, 'libur'=>0];
}

// ============================================================================
//  ADMS (Push SDK ZKTeco) — mesin mengirim data ke server lewat HTTP /iclock/...
// ----------------------------------------------------------------------------
//  Kolom "status" pada ATTLOG mesin adalah PUNCH STATE (0=check-in, 1=check-out,
//  2/3=break, 4/5=overtime, 255=tanpa status). Nilai ini TIDAK dipakai untuk
//  menentukan masuk/pulang — urutan waktu scan yang menentukan (lihat
//  admsTulisAbsensi). Punch state tetap disimpan mentah di adms_scan untuk audit.
// ============================================================================

/** Terima data dari SN yang belum terdaftar? true = tolak (data mesin asing hilang). */
const ADMS_SN_KETAT = false;

/**
 * Alamat dasar aplikasi, dideteksi dari permintaan yang sedang berjalan.
 * Dipakai untuk menampilkan alamat ADMS yang harus diisikan ke mesin, sehingga
 * saat aplikasi dipindah server tidak ada yang perlu diubah di kode — cukup
 * buka halaman Setting Mesin dan salin alamat yang tertera.
 */
function urlDasarAplikasi(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    // Folder aplikasi relatif terhadap akar situs ('' bila aplikasi di akar domain)
    $basis = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $basis = rtrim($basis === '/' || $basis === '.' ? '' : $basis, '/');
    return ($https ? 'https' : 'http') . '://' . $host . $basis;
}

/** Alamat yang diisikan ke menu Cloud Server / ADMS pada mesin absensi. */
function urlAdms(): string {
    return urlDasarAplikasi() . '/adms/index.php';
}


/** PIN mesin tanpa nol di depan (mesin kerap membuang nol awal: "000181" -> "181"). */
function admsPinNormal(string $pin): string {
    $s = ltrim(trim($pin), '0');
    return $s === '' ? '0' : $s;
}

/**
 * Tentukan PIN mesin untuk seseorang, sekaligus simpan pemetaannya di mesin_pin.
 * Urutan: pemetaan yang sudah ada -> nomor induk bila muat (maks 9 digit angka,
 * batas umum PIN ZKTeco) -> nomor urut dari blok 900001 (mis. NIP 18 digit).
 */
function admsAlokasiPin(PDO $pdo, string $tipe, string $nomorInduk): string {
    $st = $pdo->prepare('SELECT pin FROM mesin_pin WHERE tipe=? AND nomor_induk=?');
    $st->execute([$tipe, $nomorInduk]);
    $lama = $st->fetchColumn();
    if ($lama !== false && $lama !== null && $lama !== '') return (string)$lama;

    $pin = '';
    $kandidat = admsPinNormal($nomorInduk);
    $dipakai = $pdo->prepare('SELECT COUNT(*) FROM mesin_pin WHERE pin=?');

    // Nomor induk dipakai apa adanya bila muat di mesin & belum dipakai orang lain
    if (ctype_digit($kandidat) && strlen($kandidat) <= 9) {
        $dipakai->execute([$kandidat]);
        if (!$dipakai->fetchColumn()) $pin = $kandidat;
    }
    if ($pin === '') {
        // Blok cadangan untuk nomor induk yang tidak muat di mesin (mis. NIP 18 digit)
        $mulai = (int)$pdo->query('SELECT COALESCE(MAX(CAST(pin AS UNSIGNED)), 900000) FROM mesin_pin
                                   WHERE CAST(pin AS UNSIGNED) >= 900000')->fetchColumn();
        do {
            $mulai++;
            $dipakai->execute([(string)$mulai]);
        } while ($dipakai->fetchColumn());
        $pin = (string)$mulai;
    }

    $pdo->prepare('INSERT INTO mesin_pin (pin, tipe, nomor_induk) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE tipe=VALUES(tipe), nomor_induk=VALUES(nomor_induk)')
        ->execute([$pin, $tipe, $nomorInduk]);
    return $pin;
}

/**
 * Susun perintah pendaftaran user ke mesin (format Push SDK, antar-field TAB).
 * Pri: 0 = user biasa, 14 = admin mesin.
 */
function admsPerintahUser(string $pin, string $nama, int $pri = 0): string {
    // Nama dibersihkan dari TAB/baris baru & dipangkas — mesin umumnya maks 24 karakter.
    $nama = mb_substr(preg_replace('/\s+/', ' ', trim($nama)), 0, 24);
    return "DATA UPDATE USERINFO PIN=$pin\tName=$nama\tPri=$pri\tPasswd=\tCard=\tGrp=1\tTZ=0000000000000000";
}

/** Perintah hapus user dari mesin. */
function admsPerintahHapusUser(string $pin): string {
    return "DATA DELETE USERINFO PIN=$pin";
}

/** Masukkan perintah ke antrean; mesin mengambilnya lewat /iclock/getrequest. */
function admsAntre(PDO $pdo, string $sn, string $perintah): void {
    $pdo->prepare('INSERT INTO adms_perintah (sn, perintah) VALUES (?,?)')->execute([$sn, $perintah]);
}

/* ----------------------------------------------------------------------------
 *  DATA USER & BIOMETRIK KIRIMAN MESIN (untuk salin antar mesin)
 * ----------------------------------------------------------------------------
 *  Mesin mengirim data user & template lewat beberapa dialek Push SDK:
 *    lama   : "USER PIN=1\tName=..", "FP PIN=1\tFID=6\tTMP=..", "FACE PIN=.."
 *    2.4+   : "BIODATA Pin=1\tNo=6\tIndex=0\tType=1\t..\tTmp=.."  (jari/wajah/palm)
 *    3.x    : "user uid=1\tpin=1\tname=..", "templatev10 pin=1\tfingerid=6\ttemplate=.."
 *  Semuanya dibakukan ke nama field Push SDK klasik, disimpan per rekaman di
 *  adms_data_user, lalu dikirim ke mesin lain sebagai perintah DATA UPDATE.
 * ------------------------------------------------------------------------- */

/** Jenis rekaman baku => [nama tabel perintah DATA UPDATE, [field baku => alias yang diterima]]. */
function admsSkemaDataUser(): array {
    return [
        'USER'     => ['USERINFO',  ['PIN'=>['pin'], 'Name'=>['name'], 'Pri'=>['pri','privilege'],
                                     'Passwd'=>['passwd','password'], 'Card'=>['card','cardno'],
                                     'Grp'=>['grp','group'], 'TZ'=>['tz'], 'Verify'=>['verify']]],
        'FP'       => ['FINGERTMP', ['PIN'=>['pin'], 'FID'=>['fid','fingerid'], 'Size'=>['size'],
                                     'Valid'=>['valid'], 'TMP'=>['tmp','template']]],
        'FACE'     => ['FACE',      ['PIN'=>['pin'], 'FID'=>['fid','faceid'], 'Size'=>['size'],
                                     'Valid'=>['valid'], 'TMP'=>['tmp','template']]],
        'BIODATA'  => ['BIODATA',   ['Pin'=>['pin'], 'No'=>['no'], 'Index'=>['index'], 'Valid'=>['valid'],
                                     'Duress'=>['duress'], 'Type'=>['type'], 'MajorVer'=>['majorver'],
                                     'MinorVer'=>['minorver'], 'Format'=>['format'], 'Tmp'=>['tmp','template']]],
        'USERPIC'  => ['USERPIC',   ['PIN'=>['pin'], 'Size'=>['size'], 'Content'=>['content']]],
        'BIOPHOTO' => ['BIOPHOTO',  ['PIN'=>['pin'], 'Type'=>['type'], 'Size'=>['size'],
                                     'Content'=>['content'], 'Format'=>['format']]],
    ];
}

/** Nama rekaman dari mesin (semua dialek) => jenis baku. */
function admsJenisRekaman(string $nama): ?string {
    static $peta = ['USER'=>'USER', 'USERINFO'=>'USER',
                    'FP'=>'FP', 'FINGERTMP'=>'FP', 'TEMPLATEV10'=>'FP', 'TEMPLATE'=>'FP',
                    'FACE'=>'FACE', 'BIODATA'=>'BIODATA',
                    'USERPIC'=>'USERPIC', 'BIOPHOTO'=>'BIOPHOTO'];
    return $peta[strtoupper($nama)] ?? null;
}

/**
 * Urai satu baris kiriman mesin menjadi rekaman baku, atau null bila baris itu
 * bukan data user/biometrik (mis. "OPLOG ..."). $tabel dipakai bila baris
 * tidak diawali nama rekaman (sebagian firmware: table=FINGERTMP, isi "PIN=..").
 * @return array{jenis:string, pin:string, kunci:string, tipe_bio:?int, data:array}|null
 */
function admsUraiDataUser(string $baris, string $tabel = ''): ?array {
    $baris = trim($baris);
    if ($baris === '') return null;
    // Nama rekaman = kata pertama, asalkan kata itu bukan pasangan kunci=nilai.
    if (preg_match('/^([A-Za-z0-9_]+)\s+(.*)$/s', $baris, $m) && strpos($m[1], '=') === false) {
        $jenis = admsJenisRekaman($m[1]);
        $sisa  = $m[2];
    } else {
        $jenis = admsJenisRekaman($tabel);
        $sisa  = $baris;
    }
    if (!$jenis) return null;

    $f = [];
    foreach (explode("\t", $sisa) as $pasang) {
        $p = strpos($pasang, '=');
        if ($p === false) continue;
        $f[strtolower(trim(substr($pasang, 0, $p)))] = substr($pasang, $p + 1);
    }

    $data = [];
    foreach (admsSkemaDataUser()[$jenis][1] as $baku => $alias) {
        foreach ($alias as $a) {
            if (array_key_exists($a, $f)) { $data[$baku] = trim($f[$a]); break; }
        }
    }
    $pin = (string)($data['PIN'] ?? $data['Pin'] ?? '');
    if ($pin === '') return null;
    // Rekaman biometrik tanpa isi template tidak ada gunanya disalin.
    $isi = $data['TMP'] ?? $data['Tmp'] ?? $data['Content'] ?? null;
    if ($jenis !== 'USER' && ($isi === null || $isi === '')) return null;

    $kunci = match ($jenis) {
        'FP', 'FACE' => (string)($data['FID'] ?? '0'),
        'BIODATA'    => ($data['Type'] ?? '0') . '-' . ($data['No'] ?? '0') . '-' . ($data['Index'] ?? '0'),
        'BIOPHOTO'   => (string)($data['Type'] ?? '0'),
        default      => '',
    };
    return ['jenis' => $jenis, 'pin' => $pin, 'kunci' => $kunci,
            'tipe_bio' => $jenis === 'BIODATA' && isset($data['Type']) ? (int)$data['Type'] : null,
            'data' => $data];
}

/**
 * Simpan semua baris data user/biometrik dalam satu kiriman mesin.
 * @return int jumlah rekaman yang tersimpan
 */
function admsSimpanDataUser(PDO $pdo, string $sn, array $baris, string $tabel = ''): int {
    if ($sn === '') return 0;
    $st = $pdo->prepare('INSERT INTO adms_data_user (sn, pin, jenis, kunci, tipe_bio, data) VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE tipe_bio=VALUES(tipe_bio), data=VALUES(data), diperbarui=NOW()');
    $n = 0;
    foreach ($baris as $b) {
        $r = admsUraiDataUser($b, $tabel);
        if (!$r) continue;
        try {
            $st->execute([$sn, $r['pin'], $r['jenis'], $r['kunci'], $r['tipe_bio'],
                          json_encode($r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $n++;
        } catch (Throwable $ex) {
            error_log('ADMS data user gagal disimpan (PIN ' . $r['pin'] . ' ' . $r['jenis'] . '): ' . $ex->getMessage());
        }
    }
    return $n;
}

/** Susun perintah DATA UPDATE dari satu rekaman adms_data_user. */
function admsPerintahDariData(string $jenis, array $data): ?string {
    $skema = admsSkemaDataUser()[$jenis] ?? null;
    if (!$skema) return null;
    if ($jenis === 'USER') {
        // Field wajib diisi supaya mesin tidak menolak; nama dibersihkan dari TAB/baris baru.
        $data['Name'] = mb_substr(preg_replace('/\s+/', ' ', trim($data['Name'] ?? '')), 0, 24);
        $data += ['Pri' => '0', 'Passwd' => '', 'Card' => '', 'Grp' => '1', 'TZ' => '0000000000000000'];
    }
    // Size wajib pada perintah template; hitung dari isi bila mesin asal tidak mengirimnya.
    if (in_array($jenis, ['FP', 'FACE', 'USERPIC', 'BIOPHOTO'], true) && ($data['Size'] ?? '') === '') {
        $isi = $data['TMP'] ?? $data['Content'] ?? '';
        $data['Size'] = (string)strlen((string)base64_decode($isi, true));
    }
    $bagian = [];
    foreach (array_keys($skema[1]) as $k) {
        if (!array_key_exists($k, $data)) continue;
        $bagian[] = $k . '=' . str_replace(["\t", "\r", "\n"], ' ', (string)$data[$k]);
    }
    return 'DATA UPDATE ' . $skema[0] . ' ' . implode("\t", $bagian);
}

/** Golongan biometrik untuk ringkasan & pilihan salin: jari / wajah / palm / foto / lain. */
function admsGolonganBio(string $jenis, ?int $tipeBio): string {
    return match (true) {
        $jenis === 'USER'                           => 'user',
        $jenis === 'FP'                             => 'jari',
        $jenis === 'FACE'                           => 'wajah',
        $jenis === 'USERPIC' || $jenis === 'BIOPHOTO' => 'foto',
        $tipeBio === 1                              => 'jari',
        $tipeBio === 2 || $tipeBio === 9            => 'wajah',
        $tipeBio === 6 || $tipeBio === 8            => 'palm',
        default                                     => 'lain',
    };
}

/**
 * Petakan PIN mesin ke orang. Urutan: tabel mesin_pin (pemetaan manual),
 * lalu NIS/NISN siswa, lalu NIP guru.
 * @return array{tipe:string, nomor_induk:string, nama:string}|null
 */
function admsPetakanPin(PDO $pdo, PDO $dc, string $pin): ?array {
    $st = $pdo->prepare('SELECT tipe, nomor_induk FROM mesin_pin WHERE pin = ?');
    $st->execute([$pin]);
    if ($m = $st->fetch()) {
        $nama = $m['tipe'] === 'guru'
            ? $dc->prepare('SELECT nama_ptk FROM guru WHERE nip = ?')
            : $dc->prepare("SELECT nama_siswa FROM siswa WHERE COALESCE(NULLIF(nis,''), nisn) = ?");
        $nama->execute([$m['nomor_induk']]);
        return ['tipe' => $m['tipe'], 'nomor_induk' => $m['nomor_induk'], 'nama' => (string)$nama->fetchColumn()];
    }

    // Siswa: PIN dicocokkan ke NIS atau NISN, termasuk versi tanpa nol di depan
    // (mesin kerap membuang nol awal). Nomor induk mengikuti aturan dcSiswaList()
    // supaya cocok dengan isi absensi_siswa.nis.
    $norm = admsPinNormal($pin);
    $st = $dc->prepare("SELECT COALESCE(NULLIF(nis,''), nisn) AS induk, nama_siswa AS nama
                        FROM siswa
                        WHERE (nis = ? OR nisn = ?
                               OR TRIM(LEADING '0' FROM COALESCE(NULLIF(nis,''), nisn)) = ?)
                          AND is_aktif = 1 AND status_siswa = 'Aktif'
                        LIMIT 1");
    $st->execute([$pin, $pin, $norm]);
    if ($s = $st->fetch()) return ['tipe' => 'siswa', 'nomor_induk' => $s['induk'], 'nama' => $s['nama']];

    $st = $dc->prepare("SELECT nip AS induk, nama_ptk AS nama FROM guru
                        WHERE (nip = ? OR TRIM(LEADING '0' FROM nip) = ?) AND is_aktif = 1 LIMIT 1");
    $st->execute([$pin, $norm]);
    if ($g = $st->fetch()) return ['tipe' => 'guru', 'nomor_induk' => $g['induk'], 'nama' => $g['nama']];

    return null;
}

/**
 * Tulis satu scan mesin ke tabel absensi sebagai jam masuk (kode 0) atau jam
 * pulang (kode 1). Status/punch state yang dikirim mesin TIDAK dipakai — apa pun
 * nilainya, urutan waktu yang menentukan:
 *   - jam masuk tanggal itu belum terisi   -> scan dicatat sebagai JAM MASUK
 *   - jam masuk sudah terisi               -> scan dicatat sebagai JAM PULANG
 *                                             (bila berkali-kali, yang paling akhir dipakai)
 * Pengaman agar data tetap benar walau kiriman mesin tertunda atau diulang:
 *   - scan yang tiba belakangan tetapi jamnya LEBIH AWAL dari jam masuk tercatat
 *     menjadi jam masuk baru; jam masuk lama digeser menjadi kandidat jam pulang
 *   - scan yang sama persis dengan jam masuk (kiriman ulang mesin) diabaikan
 * Baris ketidakhadiran manual (kode 2-6) tidak disentuh.
 *
 * @param int $statusMesin Tidak dipakai lagi; tetap diterima agar pemanggil dan
 *                         data mentah (adms_scan) tidak perlu berubah.
 * @return bool true bila scan sudah tercatat di tabel absensi.
 */
function admsTulisAbsensi(PDO $pdo, string $tipe, string $orang, string $waktu, int $statusMesin = 0): bool {
    $tanggal = substr($waktu, 0, 10);
    $jam     = substr($waktu, 11, 8);
    [$tabel, $kol] = absTabel($tipe);

    // Kunci baris orang+tanggal selama keputusan dibuat, supaya dua mesin yang
    // mengirim scan orang yang sama bersamaan tidak menghasilkan dua "jam masuk".
    $transaksiSendiri = !$pdo->inTransaction();
    if ($transaksiSendiri) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT status, jam FROM $tabel
                             WHERE $kol = ? AND tanggal = ? AND status IN (?, ?) FOR UPDATE");
        $st->execute([$orang, $tanggal, ABS_MASUK, ABS_PULANG]);
        $tercatat = [];
        foreach ($st as $r) $tercatat[(int)$r['status']] = $r['jam'];

        $simpan = $pdo->prepare("INSERT INTO $tabel ($kol, tanggal, jam, status) VALUES (?,?,?,?)
                                 ON DUPLICATE KEY UPDATE jam = VALUES(jam)");
        $masuk  = $tercatat[ABS_MASUK] ?? null;
        $pulang = $tercatat[ABS_PULANG] ?? '';

        if ($masuk === null) {
            $simpan->execute([$orang, $tanggal, $jam, ABS_MASUK]);                // belum ada jam masuk
        } elseif ($jam === $masuk) {
            // kiriman ulang scan yang sama — tidak ada yang berubah
        } elseif ($jam < $masuk) {
            $simpan->execute([$orang, $tanggal, $jam, ABS_MASUK]);                // scan lebih awal jadi jam masuk
            $simpan->execute([$orang, $tanggal, max($pulang, $masuk), ABS_PULANG]);
        } else {
            $simpan->execute([$orang, $tanggal, max($pulang, $jam), ABS_PULANG]); // jam pulang = scan terakhir
        }

        if ($transaksiSendiri) $pdo->commit();
    } catch (Throwable $e) {
        if ($transaksiSendiri && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return true;
}

// ============================================================================
//  Helper catatan absensi (dari database absensi, via $pdo)
// ----------------------------------------------------------------------------
//  Kedua tabel diringkas ke bentuk seragam "catatan per hari":
//     [kunci orang => [tanggal => ['jam_masuk','jam_pulang','status','keterangan']]]
//  sehingga logika laporan/rekap di bawahnya sama untuk siswa maupun guru.
//  Kunci orang: NIS (siswa) atau NIP (guru) — keduanya nomor induk dari datacenter.
// ============================================================================

/** Nama tabel & kolom kunci absensi per tipe. */
function absTabel(string $tipe): array {
    return $tipe === 'guru' ? ['absensi_guru', 'nip'] : ['absensi_siswa', 'nis'];
}

/* ----------------------------------------------------------------------------
 *  ABSENSI KARTU RFID
 * ----------------------------------------------------------------------------
 *  Reader RFID USB bekerja seperti keyboard: mengetik nomor kartu lalu Enter.
 *  Halaman absensi_kartu.php mengirim nomor itu ke kartuTap(), yang menulis
 *  absensi lewat admsTulisAbsensi() — aturan masuk/pulang sama dengan mesin.
 * ------------------------------------------------------------------------- */

/** Tap kartu yang sama dalam rentang ini (menit) setelah tap terakhir diabaikan. */
const KARTU_JEDA_MENIT = 10;

/**
 * Bakukan nomor kartu: hanya huruf/angka, huruf besar. Nomor desimal dibuang
 * nol depannya (reader kerap mengetik "0012345678" untuk kartu "12345678").
 */
function kartuNormal(string $uid): string {
    $s = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $uid));
    if ($s !== '' && ctype_digit($s)) $s = ltrim($s, '0') ?: '0';
    return substr($s, 0, 32);
}

/**
 * Data tampilan seseorang dari datacenter: nama, jenis kelamin, kelas/jabatan.
 * @return array{tipe:string, nomor_induk:string, nama:string, jk:string, kelas:string, kelas_id:?int}|null
 */
function kartuInfoOrang(PDO $dc, string $tipe, string $induk): ?array {
    if ($tipe === 'guru') {
        $st = $dc->prepare("SELECT nama_ptk nama, jenis_kelamin jk, COALESCE(NULLIF(TRIM(jabatan),''),'Guru') kelas
                            FROM guru WHERE nip = ? LIMIT 1");
        $st->execute([$induk]);
    } else {
        // Kelas pada tahun ajaran aktif; bila belum ada, kelas terakhir siswa itu.
        $ta = tahunAjaranAktif($dc);
        $st = $dc->prepare("SELECT s.nama_siswa nama, s.jenis_kelamin jk, rb.nama_rombel kelas, rb.id kelas_id
                            FROM siswa s
                            LEFT JOIN siswa_rombel sr ON sr.siswa_id = s.id
                            LEFT JOIN rombongan_belajar rb ON rb.id = sr.rombongan_belajar_id
                            WHERE COALESCE(NULLIF(s.nis,''), s.nisn) = ?
                            ORDER BY sr.tahun_ajaran_id = ? DESC, sr.tahun_ajaran_id DESC LIMIT 1");
        $st->execute([$induk, $ta['id'] ?? 0]);
    }
    $r = $st->fetch();
    if (!$r) return null;
    return ['tipe' => $tipe, 'nomor_induk' => $induk, 'nama' => (string)$r['nama'], 'jk' => (string)$r['jk'],
            'kelas' => (string)($r['kelas'] ?? ''), 'kelas_id' => isset($r['kelas_id']) ? (int)$r['kelas_id'] : null];
}

/** Pemilik kartu, atau null bila kartu belum terdaftar. */
function kartuCariOrang(PDO $pdo, PDO $dc, string $uid): ?array {
    $st = $pdo->prepare('SELECT tipe, nomor_induk FROM kartu_rfid WHERE uid = ?');
    $st->execute([$uid]);
    $k = $st->fetch();
    if (!$k) return null;
    return kartuInfoOrang($dc, $k['tipe'], $k['nomor_induk'])
        ?? ['tipe' => $k['tipe'], 'nomor_induk' => $k['nomor_induk'], 'nama' => $k['nomor_induk'],
            'jk' => '', 'kelas' => '', 'kelas_id' => null];
}

/**
 * Proses satu tap kartu: tulis absensi masuk/pulang & catat jejaknya di adms_scan.
 * jenis: masuk | pulang | ulang (tap berulang dalam KARTU_JEDA_MENIT, tidak ditulis)
 */
function kartuTap(PDO $pdo, PDO $dc, string $uidMentah): array {
    $uid = kartuNormal($uidMentah);
    if ($uid === '') return ['ok' => false, 'kode' => 'kosong', 'pesan' => 'Nomor kartu kosong.'];

    $waktu = date('Y-m-d H:i:s');
    $tanggal = substr($waktu, 0, 10);
    $jam = substr($waktu, 11, 8);
    $jejak = $pdo->prepare('INSERT IGNORE INTO adms_scan (sn, pin, waktu, status_mesin, verify, tipe, nomor_induk, diproses)
                            VALUES (?,?,?,?,?,?,?,?)');

    $orang = kartuCariOrang($pdo, $dc, $uid);
    if (!$orang) {
        $jejak->execute(['KARTU', $uid, $waktu, 0, 4, null, null, 0]);
        return ['ok' => false, 'kode' => 'tak_dikenal', 'uid' => $uid, 'jam' => $jam,
                'pesan' => 'Kartu belum terdaftar.'];
    }

    [$tabel, $kol] = absTabel($orang['tipe']);
    $st = $pdo->prepare("SELECT status, jam FROM $tabel WHERE $kol = ? AND tanggal = ? AND status IN (?, ?)");
    $st->execute([$orang['nomor_induk'], $tanggal, ABS_MASUK, ABS_PULANG]);
    $tercatat = [];
    foreach ($st as $r) $tercatat[(int)$r['status']] = $r['jam'];
    $terakhir = $tercatat ? max($tercatat) : null;

    if ($terakhir !== null && strtotime("$tanggal $jam") - strtotime("$tanggal $terakhir") < KARTU_JEDA_MENIT * 60) {
        $jenis = 'ulang';
    } else {
        $jenis = isset($tercatat[ABS_MASUK]) ? 'pulang' : 'masuk';
        admsTulisAbsensi($pdo, $orang['tipe'], $orang['nomor_induk'], $waktu, $jenis === 'masuk' ? ABS_MASUK : ABS_PULANG);
        $jejak->execute(['KARTU', $uid, $waktu, $jenis === 'masuk' ? 0 : 1, 4, $orang['tipe'], $orang['nomor_induk'], 1]);
        if ($jenis === 'masuk') $tercatat[ABS_MASUK] = $jam;
    }

    // Terlambat dihitung seperti laporan: jam masuk dibanding batas jadwal/shift hari ini.
    $jamMasuk = $tercatat[ABS_MASUK] ?? null;
    $kunciShift = $orang['tipe'] === 'guru' ? $orang['nomor_induk'] : $orang['kelas_id'];
    $info = kalenderPeriode($pdo, $orang['tipe'], $tanggal, $tanggal,
                            $kunciShift ? shiftPerHari($pdo, $orang['tipe'], $kunciShift) : null)[$tanggal];
    $terlambat = $jamMasuk && !$info['libur'] && $jamMasuk > $info['batas'];

    return ['ok' => true, 'jenis' => $jenis, 'uid' => $uid, 'jam' => $jam, 'jam_masuk' => $jamMasuk,
            'terlambat' => $terlambat, 'tipe' => $orang['tipe'], 'nomor_induk' => $orang['nomor_induk'],
            'nama' => $orang['nama'], 'jk' => $orang['jk'], 'kelas' => $orang['kelas']];
}

/**
 * Baca log event absensi lalu ringkas jadi satu baris per (orang, tanggal).
 * Berlaku untuk siswa (kunci NIS) maupun guru (kunci NIP) karena strukturnya sama.
 *
 * @param array $keys Daftar NIS (siswa) atau NIP (guru).
 * @return array [kunci => [tanggal => ['jam_masuk','jam_pulang','status','keterangan']]]
 */
function recAbsensi(PDO $pdo, string $tipe, array $keys, string $dari, string $sampai): array {
    $out = [];
    if (!$keys) return $out;
    [$tabel, $kol] = absTabel($tipe);
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT $kol AS orang, tanggal, jam, status, keterangan FROM $tabel
                         WHERE $kol IN ($in) AND tanggal BETWEEN ? AND ?
                         ORDER BY tanggal, status, jam");
    $st->execute([...$keys, $dari, $sampai]);
    foreach ($st as $r) {
        $k = $r['orang']; $tgl = $r['tanggal'];
        if (!isset($out[$k][$tgl])) {
            $out[$k][$tgl] = ['jam_masuk'=>null, 'jam_pulang'=>null, 'status'=>null, 'keterangan'=>null];
        }
        $kode = (int)$r['status'];
        if ($kode === ABS_MASUK)      $out[$k][$tgl]['jam_masuk']  = $r['jam'];
        elseif ($kode === ABS_PULANG) $out[$k][$tgl]['jam_pulang'] = $r['jam'];
        else                          $out[$k][$tgl]['status']     = kodeKeStatus($kode);
        if (($r['keterangan'] ?? '') !== '') $out[$k][$tgl]['keterangan'] = $r['keterangan'];
    }
    return $out;
}

/**
 * Tulis ulang seluruh catatan satu orang pada satu tanggal (dipakai halaman koreksi).
 * Kode ketidakhadiran (2-6) disimpan sebagai satu baris tanpa jam; selain itu
 * jam masuk/pulang disimpan sebagai event kode 0 dan 1.
 */
function simpanAbsensi(PDO $pdo, string $tipe, string $orang, string $tanggal,
                       ?int $kode, ?string $jamMasuk, ?string $jamPulang, ?string $ket): void {
    [$tabel, $kol] = absTabel($tipe);
    $pdo->prepare("DELETE FROM $tabel WHERE $kol=? AND tanggal=?")->execute([$orang, $tanggal]);
    $ins = $pdo->prepare("INSERT INTO $tabel ($kol, tanggal, jam, status, keterangan) VALUES (?,?,?,?,?)");
    if ($kode !== null) {
        $ins->execute([$orang, $tanggal, null, $kode, $ket]);
        return;
    }
    if ($jamMasuk)  $ins->execute([$orang, $tanggal, $jamMasuk,  ABS_MASUK,  $ket]);
    if ($jamPulang) $ins->execute([$orang, $tanggal, $jamPulang, ABS_PULANG, null]);
}

/**
 * Info tiap tanggal dalam periode: hari sekolah atau libur, dan batas terlambatnya.
 * Menggabungkan setting jadwal PER HARI (jadwal_absensi) dengan hari libur khusus
 * (hari_libur, rentang tanggal di-expand).
 *
 * @return array<string,array{libur:bool, batas:string, ket:?string}>
 */
function kalenderPeriode(PDO $pdo, string $tipe, string $dari, string $sampai, ?array $shiftHari = null): array {
    $jadwalHari = [];
    $js = $pdo->prepare('SELECT hari, jam_masuk, batas_terlambat, libur FROM jadwal_absensi WHERE tipe=?');
    $js->execute([$tipe]);
    foreach ($js as $jr) $jadwalHari[(int)$jr['hari']] = $jr;

    $liburKhusus = [];
    $hl = $pdo->prepare("SELECT tanggal, tanggal_selesai, keterangan FROM hari_libur
                         WHERE tanggal <= ? AND COALESCE(tanggal_selesai, tanggal) >= ?");
    $hl->execute([$sampai, $dari]);
    foreach ($hl as $h) {
        $end = $h['tanggal_selesai'] ?: $h['tanggal'];
        for ($d = strtotime($h['tanggal']); $d <= strtotime($end); $d = strtotime('+1 day', $d)) {
            $liburKhusus[date('Y-m-d', $d)] = $h['keterangan'];
        }
    }

    $kal = [];
    for ($d = strtotime($dari); $d <= strtotime($sampai); $d = strtotime('+1 day', $d)) {
        $tgl = date('Y-m-d', $d);
        $hariKe = (int)date('N', $d);
        $jh = $jadwalHari[$hariKe] ?? null;
        // Shift MENIMPA jadwal umum pada hari yang punya shift. Bila hari itu
        // tidak punya shift, perhitungan jatuh ke jadwal_absensi seperti semula.
        $sh = $shiftHari[$hariKe] ?? null;
        if ($sh) {
            $kal[$tgl] = [
                'libur' => isset($liburKhusus[$tgl]),
                'batas' => $sh['batas_terlambat'] ?: $sh['jam_masuk'],
                'ket'   => $liburKhusus[$tgl] ?? null,
                'shift' => $sh['nama'] ?? null,
            ];
        } else {
            $kal[$tgl] = [
                // Hari non-sekolah: ditandai libur di jadwal, tak punya jam masuk terjadwal, atau libur khusus
                'libur' => !$jh || (int)$jh['libur'] === 1 || empty($jh['jam_masuk']) || isset($liburKhusus[$tgl]),
                'batas' => ($jh && $jh['batas_terlambat']) ? $jh['batas_terlambat'] : '07:00:00',
                'ket'   => $liburKhusus[$tgl] ?? null,
                'shift' => null,
            ];
        }
    }
    return $kal;
}

/**
 * Tentukan status satu tanggal dari catatan absensi + info kalender:
 *   - sakit/ijin/dinas luar/cuti tercatat   -> sesuai catatan (kode 2,3,5,6)
 *   - ada jam masuk                         -> 'hadir' jika <= batas terlambat, selain itu 'terlambat'
 *   - alpha tercatat (kode 4)               -> 'alpha'
 *   - hari libur (jadwal / libur khusus)    -> 'libur' (tidak dihitung tidak hadir)
 *   - hari sekolah tanpa catatan            -> 'alpha' (Tidak Hadir)
 */
function statusTanggal(array $info, ?array $r): array {
    $jamMasuk = $r['jam_masuk'] ?? null;
    $ket      = $r['keterangan'] ?? null;
    $tercatat = $r['status'] ?? null;   // sakit|izin|dinas|cuti|alpha|null

    if ($tercatat !== null && $tercatat !== 'alpha') {
        $status = $tercatat;
    } elseif ($jamMasuk) {
        $status = ($jamMasuk <= $info['batas']) ? 'hadir' : 'terlambat';
    } elseif ($tercatat === 'alpha') {
        $status = 'alpha';                      // alpha dicatat eksplisit (kode 4)
    } elseif ($info['libur']) {
        $status = 'libur';
        $ket = $ket ?: $info['ket'];            // nama hari libur khusus
    } else {
        $status = 'alpha';                      // hari sekolah tanpa catatan
    }
    return ['status' => $status, 'keterangan' => $ket];
}

/**
 * Laporan absensi harian satu orang untuk SETIAP tanggal dalam rentang.
 *
 * @param array $rec Catatan orang tsb: [tanggal => ['jam_masuk','jam_pulang','status','keterangan']]
 * @return array{rows: array<int,array>, rekap: array<string,int>}
 */
function laporanHarian(PDO $pdo, string $tipe, array $rec, string $dari, string $sampai, ?array $shiftHari = null): array {
    $rekap = rekapKosong();
    if (strtotime($dari) > strtotime($sampai)) return ['rows'=>[], 'rekap'=>$rekap];

    $rows = [];
    foreach (kalenderPeriode($pdo, $tipe, $dari, $sampai, $shiftHari) as $tgl => $info) {
        $r = $rec[$tgl] ?? null;
        ['status'=>$status, 'keterangan'=>$ket] = statusTanggal($info, $r);
        $rekap[$status]++;
        $rows[] = [
            'tanggal'   => $tgl,
            'jam_masuk' => $r['jam_masuk'] ?? null,
            'jam_pulang'=> $r['jam_pulang'] ?? null,
            'status'    => $status,
            'keterangan'=> $ket,
        ];
    }
    return ['rows'=>$rows, 'rekap'=>$rekap];
}

/**
 * Rekap periode untuk banyak orang sekaligus, memakai aturan yang sama dengan
 * laporanHarian() sehingga angka di halaman per kelas/jabatan konsisten dengan
 * halaman per siswa/guru.
 *
 * @param array $recAll [kunci orang => [tanggal => catatan]]
 * @return array [kunci orang => ['hadir'=>n,'terlambat'=>n,'izin'=>n,'sakit'=>n,'alpha'=>n,'libur'=>n]]
 */
/**
 * Shift per hari untuk banyak kelas / guru sekaligus.
 *   - tipe 'siswa' -> kunci = rombel_id (jadwal_shift_kelas)
 *   - tipe 'guru'  -> kunci = NIP       (jadwal_shift_guru)
 * @return array [kunci => [hari(1-7) => ['nama','jam_masuk','batas_terlambat','jam_pulang']]]
 */
function shiftPerHariBanyak(PDO $pdo, string $tipe, array $kunci): array {
    $out = [];
    $kunci = array_values(array_unique(array_filter($kunci, fn($k) => $k !== null && $k !== '')));
    if (!$kunci) return $out;

    [$tabel, $kol] = $tipe === 'guru'
        ? ['jadwal_shift_guru', 'nip']
        : ['jadwal_shift_kelas', 'rombel_id'];
    $in = implode(',', array_fill(0, count($kunci), '?'));
    $st = $pdo->prepare("SELECT j.$kol AS kunci, j.hari, s.nama, s.jam_masuk, s.batas_terlambat, s.jam_pulang
                         FROM $tabel j JOIN shift s ON s.id = j.shift_id
                         WHERE j.$kol IN ($in)");
    $st->execute($kunci);
    foreach ($st as $r) $out[$r['kunci']][(int)$r['hari']] = $r;
    return $out;
}

/** Shift per hari untuk satu kelas (rombel_id) atau satu guru (NIP). */
function shiftPerHari(PDO $pdo, string $tipe, string|int $kunci): array {
    return shiftPerHariBanyak($pdo, $tipe, [$kunci])[$kunci] ?? [];
}

function rekapPeriode(PDO $pdo, string $tipe, array $keys, array $recAll, string $dari, string $sampai,
                      array $shiftPerKunci = []): array {
    $adaRentang = strtotime($dari) <= strtotime($sampai);
    $out = [];
    $cacheKal = [];   // kalender dipakai ulang untuk orang/kelas dengan shift sama
    foreach ($keys as $key) {
        $sh   = $shiftPerKunci[$key] ?? null;
        $tanda = $sh ? md5(serialize($sh)) : '-';
        if (!isset($cacheKal[$tanda])) {
            $cacheKal[$tanda] = $adaRentang ? kalenderPeriode($pdo, $tipe, $dari, $sampai, $sh) : [];
        }
        $rekap = rekapKosong();
        foreach ($cacheKal[$tanda] as $tgl => $info) {
            $rekap[statusTanggal($info, $recAll[$key][$tgl] ?? null)['status']]++;
        }
        $out[$key] = $rekap;
    }
    return $out;
}

/**
 * Status absensi SELURUH roster untuk setiap tanggal dalam rentang:
 *   - siswa : siswa aktif pada tahun ajaran terpilih (grup = kelas, shift per kelas)
 *   - guru  : guru aktif (grup = jabatan, shift per guru)
 * Dipakai dashboard dan halaman detail kehadiran, sehingga angka pada kartu selalu
 * sama dengan daftar nama di halaman detail. Catatan absensi yang nomor induknya
 * tidak ada di roster datacenter tidak ikut dihitung.
 *
 * @return array{orang: array, status: array}
 *   orang  : [kunci => ['kunci','id','nama','grup','grup_id']]
 *   status : [kunci => [tanggal => ['status','keterangan','jam_masuk','jam_pulang','shift']]]
 */
function rosterAbsensi(PDO $pdo, PDO $dc, string $tipe, ?array $ta, string $dari, string $sampai): array {
    $orang = [];
    if ($tipe === 'guru') {
        foreach (dcGuruList($dc) as $g) {
            $orang[$g['nip']] = ['kunci' => $g['nip'], 'id' => (int)$g['id'], 'nama' => $g['nama'],
                                 'grup' => $g['jabatan'], 'grup_id' => $g['jabatan']];
        }
        $shift = shiftPerHariBanyak($pdo, 'guru', array_map('strval', array_keys($orang)));
    } else {
        if ($ta) {
            foreach (dcSiswaList($dc, (int)$ta['id']) as $s) {
                $orang[$s['nis']] = ['kunci' => $s['nis'], 'id' => (int)$s['id'], 'nama' => $s['nama'],
                                     'grup' => $s['kelas'], 'grup_id' => (int)$s['kelas_id']];
            }
        }
        $shift = shiftPerHariBanyak($pdo, 'siswa', array_column($orang, 'grup_id'));
    }

    $hasil = ['orang' => $orang, 'status' => []];
    if (!$orang || strtotime($dari) > strtotime($sampai)) return $hasil;

    $rec = recAbsensi($pdo, $tipe, array_map('strval', array_keys($orang)), $dari, $sampai);
    $kalCache = [];   // kalender dipakai ulang untuk orang/kelas dengan pola shift sama
    foreach ($orang as $k => $o) {
        $sh = $tipe === 'guru' ? ($shift[$k] ?? null) : ($shift[$o['grup_id']] ?? null);
        $tanda = $sh ? md5(serialize($sh)) : '-';
        $kalCache[$tanda] ??= kalenderPeriode($pdo, $tipe, $dari, $sampai, $sh);
        foreach ($kalCache[$tanda] as $tgl => $info) {
            $r = $rec[$k][$tgl] ?? null;
            ['status' => $st, 'keterangan' => $ket] = statusTanggal($info, $r);
            $hasil['status'][$k][$tgl] = [
                'status'     => $st,
                'keterangan' => $ket,
                'jam_masuk'  => $r['jam_masuk'] ?? null,
                'jam_pulang' => $r['jam_pulang'] ?? null,
                'shift'      => $info['shift'] ?? null,
            ];
        }
    }
    return $hasil;
}

/** Jumlah tiap status laporan pada satu tanggal dari hasil rosterAbsensi(). */
function hitungRoster(array $roster, string $tanggal): array {
    $c = rekapKosong();
    foreach ($roster['status'] as $perTanggal) {
        if (isset($perTanggal[$tanggal])) $c[$perTanggal[$tanggal]['status']]++;
    }
    return $c;
}

/**
 * Status laporan yang tercakup oleh satu pilihan filter kehadiran.
 *   semua       -> null (tanpa filter)
 *   masuk       -> yang datang: hadir + terlambat (guru: + dinas luar, karena tetap bertugas)
 *   tidak_hadir -> selain "masuk" (izin, sakit, alpha, libur; guru juga cuti)
 *   lainnya     -> satu status persis (hadir = tepat waktu, terlambat, izin, dst.)
 */
function statusKelompok(string $tipe, string $kelompok): ?array {
    $masuk = $tipe === 'guru' ? ['hadir', 'terlambat', 'dinas'] : ['hadir', 'terlambat'];
    return match (true) {
        $kelompok === 'masuk'       => $masuk,
        $kelompok === 'tidak_hadir' => array_values(array_diff(array_keys(rekapKosong()), $masuk)),
        array_key_exists($kelompok, rekapKosong()) => [$kelompok],
        default                     => null,
    };
}

// ============================================================================

function requireLogin(): void {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$HARI_ID = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$STATUS_LIST = ['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'izin' => 'Izin', 'sakit' => 'Sakit',
                'dinas' => 'Dinas Luar', 'cuti' => 'Cuti', 'alpha' => 'Tidak Hadir'];
// Laporan harian (termasuk "Libur"). Warna dipakai sebagai kelas badge Bootstrap.
$STATUS_DETAIL = $STATUS_LIST + ['libur' => 'Libur'];
$STATUS_WARNA  = ['hadir'=>'success', 'terlambat'=>'warning text-dark', 'izin'=>'info', 'sakit'=>'primary',
                  'dinas'=>'dark', 'cuti'=>'secondary', 'alpha'=>'danger', 'libur'=>'light text-dark border'];
