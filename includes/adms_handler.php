<?php
/*
 * ============================================================================
 *  API ADMS / PUSH SDK (ZKTeco) — penanganan bersama
 * ----------------------------------------------------------------------------
 *  Mesin absensi yang menghubungi server (bukan sebaliknya). Berkas ini dipakai
 *  oleh DUA pintu masuk, keduanya berlaku sama:
 *
 *    1) /iclock/...        jalur baku ZKTeco (dipakai firmware yang otomatis
 *                          menambahkan /iclock/cdata sendiri)
 *    2) /adms/index.php    jalur eksplisit (dipakai firmware yang alamat
 *                          servernya diisi lengkap sampai nama berkas)
 *
 *  Aksi ditentukan dari jalur (cdata / getrequest / devicecmd / ping). Bila
 *  mesin memanggil /adms/index.php tanpa jalur tambahan, aksinya disimpulkan
 *  dari metode & parameter — lihat admsDeteksiAksi().
 *
 *  Aksi yang dikenali:
 *    GET  ...?SN=..&options=all    -> handshake, server membalas konfigurasi
 *    POST ...?SN=..&table=ATTLOG   -> kiriman data absensi
 *    POST ...?SN=..&table=OPERLOG  -> log operasi / data user & template biometrik
 *    POST ...?SN=..&table=BIODATA  -> template biometrik (jari/wajah/palm)
 *    GET  ...getrequest?SN=..      -> mesin meminta perintah dari server
 *    POST ...devicecmd?SN=..       -> mesin melaporkan hasil perintah
 *    POST ...querydata?SN=..       -> jawaban DATA QUERY (firmware Push 3.x)
 *    GET  ...ping                  -> cek hidup
 *
 *  Balasan WAJIB text/plain. Untuk ATTLOG mesin menghapus data lokalnya setelah
 *  menerima "OK", jadi jangan balas OK bila data gagal disimpan.
 * ============================================================================
 */
define('TANPA_SESSION', true);           // mesin polling tiap beberapa detik
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

$sn     = trim($_GET['SN'] ?? $_GET['sn'] ?? '');
$ip     = $_SERVER['REMOTE_ADDR'] ?? null;
$metode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$jalur  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

/**
 * Tentukan aksi dari jalur URL; bila tidak ada petunjuk di jalur, simpulkan
 * dari metode & parameter (kasus mesin yang menembak /adms/index.php langsung).
 */
function admsDeteksiAksi(string $jalur, string $metode, array $get): string {
    // Nama aksi bisa muncul di mana saja pada jalur, dengan atau tanpa ekstensi:
    //   /iclock/cdata          /iclock/cdata.aspx
    //   /adms/index.php/iclock/cdata      /adms/cdata
    if (preg_match('#(cdata|getrequest|devicecmd|ping|fdata|querydata)#i', $jalur, $m)) {
        return strtolower($m[1]);
    }
    // Tanpa petunjuk jalur: tebak dari bentuk permintaan.
    if ($metode === 'POST') {
        // Kiriman data selalu menyertakan nama tabel; jawaban DATA QUERY memakai
        // "tablename"; selain itu laporan perintah.
        if (isset($get['table']))     return 'cdata';
        if (isset($get['tablename'])) return 'querydata';
        return 'devicecmd';
    }
    // GET dengan options=all adalah handshake; GET polos adalah ambil perintah.
    return isset($get['options']) ? 'cdata' : 'getrequest';
}

$aksi = admsDeteksiAksi($jalur, $metode, $_GET);

/** Mesin terdaftar (dicocokkan lewat serial number). */
function cariMesin(PDO $pdo, string $sn): ?array {
    if ($sn === '') return null;
    $st = $pdo->prepare('SELECT * FROM mesin_absensi WHERE serial_number = ?');
    $st->execute([$sn]);
    return $st->fetch() ?: null;
}

function catatLog(PDO $pdo, array $d): void {
    $pdo->prepare('INSERT INTO adms_log (sn, endpoint, tabel, jumlah, disimpan, gagal, sn_dikenal, ip)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$d['sn'] ?: null, $d['endpoint'], $d['tabel'] ?? null, $d['jumlah'] ?? 0,
                   $d['disimpan'] ?? 0, $d['gagal'] ?? 0, $d['sn_dikenal'] ?? 1, $d['ip']]);
}

$mesin = cariMesin($pdo, $sn);
if ($mesin) {
    $pdo->prepare('UPDATE mesin_absensi SET last_online = NOW() WHERE id = ?')->execute([$mesin['id']]);
}
// SN asing: default tetap diterima & ditandai, supaya data absensi tidak hilang
// hanya karena serial number belum didaftarkan (lihat ADMS_SN_KETAT di config).
if (!$mesin && ADMS_SN_KETAT) {
    catatLog($pdo, ['sn'=>$sn, 'endpoint'=>$aksi, 'ip'=>$ip, 'sn_dikenal'=>0]);
    http_response_code(401);
    echo "Unauthorized device\n";
    exit;
}

switch ($aksi) {

    // ---- Handshake / kiriman data ------------------------------------------
    case 'cdata':
        if ($metode === 'GET') {
            // Diminta tarik data user (fitur salin antar mesin): OpStamp=0 membuat
            // mesin mengirim ulang SELURUH data user & template lewat OPERLOG/BIODATA.
            // Stamp absensi tetap "sekarang" supaya ATTLOG lama tidak dikirim ulang.
            $tarik = $mesin && !empty($mesin['tarik_data']);
            catatLog($pdo, ['sn'=>$sn, 'endpoint'=>$tarik ? 'cdata(tarik user)' : 'cdata(handshake)',
                            'ip'=>$ip, 'sn_dikenal'=>$mesin?1:0]);
            $stamp = time();
            if ($tarik) {
                $pdo->prepare('UPDATE mesin_absensi SET tarik_data = NULL WHERE id = ?')->execute([$mesin['id']]);
                echo "GET OPTION FROM: $sn\r\n"
                   . "Stamp=$stamp\r\nATTLOGStamp=$stamp\r\nATTPHOTOStamp=$stamp\r\n"
                   . "OpStamp=0\r\nOPERLOGStamp=0\r\nBIODATAStamp=0\r\n"
                   . "ErrorDelay=30\r\n"
                   . "Delay=10\r\n"
                   . "TransTimes=00:00;12:00\r\n"
                   . "TransInterval=1\r\n"
                   // Bentuk teks: ikut kirim user, sidik jari, wajah & foto
                   . "TransFlag=TransData AttLog\tOpLog\tAttPhoto\tEnrollUser\tChgUser\tEnrollFP\tChgFP\tFACE\tUserPic\tBioPhoto\r\n"
                   . "TimeZone=7\r\n"
                   . "Realtime=1\r\n"
                   . "Encrypt=0\r\n";
                exit;
            }
            echo "GET OPTION FROM: $sn\r\n"
               . "Stamp=$stamp\r\n"
               . "OpStamp=$stamp\r\n"
               . "ErrorDelay=30\r\n"        // jeda coba lagi saat gagal (detik)
               . "Delay=10\r\n"             // jeda polling getrequest (detik)
               . "TransTimes=00:00;12:00\r\n"
               . "TransInterval=1\r\n"      // kirim tiap 1 menit bila ada data
               . "TransFlag=1111000000\r\n" // AttLog, OpLog, AttPhoto, EnrollUser
               . "TimeZone=7\r\n"           // WIB
               . "Realtime=1\r\n"           // kirim begitu ada scan
               . "Encrypt=0\r\n";
            exit;
        }

        $tabel = strtoupper(trim($_GET['table'] ?? ''));
        $isi   = file_get_contents('php://input') ?: '';
        $baris = preg_split('/\r\n|\n|\r/', trim($isi), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tabel !== 'ATTLOG') {
            // OPERLOG / BIODATA / USERINFO / FINGERTMP dsb: data user & template
            // biometrik disimpan untuk fitur salin antar mesin; baris lain (log
            // operasi) diabaikan. ATTPHOTO berisi biner, tidak diurai.
            $disimpan = $tabel === 'ATTPHOTO' ? 0 : admsSimpanDataUser($pdo, $sn, $baris, $tabel);
            catatLog($pdo, ['sn'=>$sn, 'endpoint'=>'cdata', 'tabel'=>$tabel ?: 'LAIN',
                            'jumlah'=>count($baris), 'disimpan'=>$disimpan, 'ip'=>$ip, 'sn_dikenal'=>$mesin?1:0]);
            echo "OK\r\n";
            exit;
        }

        // ATTLOG: PIN \t waktu \t status \t verify \t workcode ...
        $simpanScan = $pdo->prepare(
            'INSERT INTO adms_scan (sn, pin, waktu, status_mesin, verify, tipe, nomor_induk, diproses)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE tipe=VALUES(tipe), nomor_induk=VALUES(nomor_induk), diproses=VALUES(diproses)');

        $disimpan = 0; $gagal = 0; $petaCache = [];
        foreach ($baris as $b) {
            $f = preg_split('/\t+/', trim($b));
            if (count($f) < 2) { $gagal++; continue; }

            $pin    = trim($f[0]);
            $waktu  = trim($f[1]);
            $status = isset($f[2]) && $f[2] !== '' ? (int)$f[2] : 0;
            $verify = isset($f[3]) && $f[3] !== '' ? (int)$f[3] : null;

            $ts = strtotime($waktu);
            if ($pin === '' || !$ts) { $gagal++; continue; }
            $waktu = date('Y-m-d H:i:s', $ts);

            // Satu baris bermasalah tidak boleh menggagalkan seluruh kiriman: bila
            // gagal, mesin tidak menerima "OK" dan akan mengulang tanpa henti.
            try {
                if (!array_key_exists($pin, $petaCache)) {
                    $petaCache[$pin] = admsPetakanPin($pdo, $dc, $pin);
                }
                $orang = $petaCache[$pin];

                $ditulis = false;
                if ($orang) {
                    $ditulis = admsTulisAbsensi($pdo, $orang['tipe'], $orang['nomor_induk'], $waktu, $status);
                } else {
                    $gagal++;   // PIN tidak dikenal: data mentah tetap disimpan
                }

                $simpanScan->execute([$sn ?: null, $pin, $waktu, $status, $verify,
                                      $orang['tipe'] ?? null, $orang['nomor_induk'] ?? null, $ditulis ? 1 : 0]);
                $disimpan++;
            } catch (Throwable $ex) {
                $gagal++;
                error_log('ADMS ATTLOG gagal (PIN ' . $pin . ' ' . $waktu . '): ' . $ex->getMessage());
            }
        }

        catatLog($pdo, ['sn'=>$sn, 'endpoint'=>'cdata', 'tabel'=>'ATTLOG', 'jumlah'=>count($baris),
                        'disimpan'=>$disimpan, 'gagal'=>$gagal, 'ip'=>$ip, 'sn_dikenal'=>$mesin?1:0]);
        echo "OK: $disimpan\r\n";
        exit;

    // ---- Mesin meminta perintah dari server ---------------------------------
    case 'getrequest':
        $st = $pdo->prepare("SELECT id, perintah FROM adms_perintah
                             WHERE sn = ? AND status = 'antre' ORDER BY id LIMIT 10");
        $st->execute([$sn]);
        $antre = $st->fetchAll();
        if (!$antre) { echo "OK\r\n"; exit; }

        // Perintah template biometrik bisa puluhan KB; batasi ukuran satu balasan
        // supaya buffer mesin tidak meluap. Minimal satu perintah selalu dikirim.
        $batasByte = 32768;
        $tandai = $pdo->prepare("UPDATE adms_perintah SET status='terkirim', dikirim=NOW() WHERE id=?");
        $keluar = ''; $dikirim = 0;
        foreach ($antre as $c) {
            $baris = 'C:' . $c['id'] . ':' . $c['perintah'] . "\r\n";
            if ($dikirim > 0 && strlen($keluar) + strlen($baris) > $batasByte) break;
            $keluar .= $baris;
            $tandai->execute([$c['id']]);
            $dikirim++;
        }
        catatLog($pdo, ['sn'=>$sn, 'endpoint'=>'getrequest', 'jumlah'=>$dikirim,
                        'ip'=>$ip, 'sn_dikenal'=>$mesin?1:0]);
        echo $keluar;
        exit;

    // ---- Mesin melaporkan hasil perintah ------------------------------------
    case 'devicecmd':
        // Satu kiriman bisa berisi hasil beberapa perintah, satu baris per perintah:
        //   ID=12&Return=0&CMD=DATA\nID=13&Return=0&CMD=DATA
        $isi = file_get_contents('php://input') ?: '';
        $selesai = $pdo->prepare("UPDATE adms_perintah SET status='selesai', hasil=? WHERE id=?");
        $jml = 0;
        foreach (preg_split('/\r\n|\n|\r/', trim($isi), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $b) {
            parse_str(trim($b), $data);
            if (empty($data['ID'])) continue;
            $selesai->execute([substr((string)($data['Return'] ?? ''), 0, 100), (int)$data['ID']]);
            $jml++;
        }
        catatLog($pdo, ['sn'=>$sn, 'endpoint'=>'devicecmd', 'jumlah'=>$jml, 'ip'=>$ip, 'sn_dikenal'=>$mesin?1:0]);
        echo "OK\r\n";
        exit;

    // ---- Jawaban DATA QUERY (firmware Push 3.x) -----------------------------
    // POST querydata?SN=..&type=tabledata&tablename=user|biodata|templatev10
    case 'querydata':
        $tabel = strtoupper(trim($_GET['tablename'] ?? $_GET['table'] ?? ''));
        $isi   = file_get_contents('php://input') ?: '';
        $baris = preg_split('/\r\n|\n|\r/', trim($isi), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $disimpan = admsSimpanDataUser($pdo, $sn, $baris, $tabel);
        catatLog($pdo, ['sn'=>$sn, 'endpoint'=>'querydata', 'tabel'=>$tabel ?: null, 'jumlah'=>count($baris),
                        'disimpan'=>$disimpan, 'ip'=>$ip, 'sn_dikenal'=>$mesin?1:0]);
        echo strtolower($tabel ?: 'data') . '=' . count($baris) . "\r\n";
        exit;

    case 'ping':
        echo "OK\r\n";
        exit;

    default:
        // Aksi tak dikenal tetap dicatat supaya terlihat di Monitor ADMS —
        // sangat membantu bila firmware mesin memakai nama jalur yang berbeda.
        catatLog($pdo, ['sn'=>$sn, 'endpoint'=>substr('? ' . $jalur, 0, 30), 'ip'=>$ip, 'sn_dikenal'=>$mesin?1:0]);
        http_response_code(404);
        echo "Not found\r\n";
        exit;
}
