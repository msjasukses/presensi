<?php
$pageTitle = 'Setting Mesin Absensi & Upload Data';
require_once __DIR__ . '/config.php';
requireLogin();

/**
 * Daftar orang menurut pilihan "Jenis Data" (dipakai upload & hapus user).
 * @return array{0: array<array{tipe:string, induk:string, nama:string}>, 1: string} [orang, keterangan]
 */
function orangPerScope(PDO $dc, ?array $ta, string $scope, array $in): array {
    $orang = [];
    if ($scope === 'tingkat') {
        if (!$ta) throw new RuntimeException('Tidak ada tahun ajaran di datacenter.');
        $tingkat = (int)($in['tingkat'] ?? 0);
        if (!$tingkat) throw new RuntimeException('Tingkat kelas belum dipilih.');
        $q = $dc->prepare("SELECT COALESCE(NULLIF(s.nis,''), s.nisn) induk, s.nama_siswa nama
            FROM siswa s
            JOIN siswa_rombel sr ON sr.siswa_id=s.id AND sr.tahun_ajaran_id=?
            JOIN rombongan_belajar rb ON rb.id=sr.rombongan_belajar_id
            WHERE rb.tingkat=? AND s.is_aktif=1 AND s.status_siswa='Aktif'
            ORDER BY s.nama_siswa");
        $q->execute([$ta['id'], $tingkat]);
        foreach ($q as $r) $orang[] = ['tipe'=>'siswa'] + $r;
        return [$orang, "Siswa Per Tingkat Kelas: Tingkat $tingkat"];
    }
    if ($scope === 'rombel') {
        if (!$ta) throw new RuntimeException('Tidak ada tahun ajaran di datacenter.');
        $rid = (int)($in['rombel_id'] ?? 0);
        $rb = $rid ? dcKelas($dc, (int)$ta['id'], $rid) : null;
        if (!$rb) throw new RuntimeException('Rombel belum dipilih / tidak valid.');
        foreach (dcSiswaList($dc, (int)$ta['id'], $rid) as $s) {
            $orang[] = ['tipe'=>'siswa', 'induk'=>$s['nis'], 'nama'=>$s['nama']];
        }
        return [$orang, "Siswa Per Rombel: " . $rb['nama']];
    }
    if ($scope === 'jabatan') {
        $jab = trim($in['jabatan'] ?? '');
        if ($jab === '') throw new RuntimeException('Jabatan belum dipilih.');
        foreach (dcGuruList($dc, $jab) as $g) {
            $orang[] = ['tipe'=>'guru', 'induk'=>$g['nip'], 'nama'=>$g['nama']];
        }
        return [$orang, "Guru Per Jabatan: $jab"];
    }
    throw new RuntimeException('Jenis data tidak valid.');
}

$msg = ''; $err = '';
$ta = tahunAjaranTerpilih($dc);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        $stmt = $pdo->prepare('INSERT INTO mesin_absensi (nama, serial_number, tipe, lokasi, aktif) VALUES (?,?,?,?,?)');
        $stmt->execute([$_POST['nama'], ($_POST['serial_number'] ?? '') ?: null, $_POST['tipe'], $_POST['lokasi'], isset($_POST['aktif']) ? 1 : 0]);
        $msg = 'Mesin absensi berhasil ditambahkan.';
    } elseif ($act === 'edit') {
        $stmt = $pdo->prepare('UPDATE mesin_absensi SET nama=?, serial_number=?, tipe=?, lokasi=?, aktif=? WHERE id=?');
        $stmt->execute([$_POST['nama'], ($_POST['serial_number'] ?? '') ?: null, $_POST['tipe'], $_POST['lokasi'], isset($_POST['aktif']) ? 1 : 0, (int)$_POST['id']]);
        $msg = 'Mesin absensi berhasil diperbarui.';
    } elseif ($act === 'delete') {
        $pdo->prepare('DELETE FROM upload_log WHERE mesin_id=?')->execute([(int)$_POST['id']]);
        $pdo->prepare('DELETE FROM mesin_absensi WHERE id=?')->execute([(int)$_POST['id']]);
        $msg = 'Mesin absensi dihapus.';
    } elseif ($act === 'cek') {
        // Cek lewat ADMS: kirim perintah CHECK ke antrean. Mesin mengambilnya saat
        // menghubungi server, lalu melapor hasilnya lewat /iclock/devicecmd.
        // Cara ini menggantikan soket TCP ke port 4370 yang tidak jalan bila mesin
        // berada di balik NAT — pada ADMS mesin yang menghubungi server, bukan sebaliknya.
        $id = (int)$_POST['id'];
        $m = $pdo->prepare('SELECT * FROM mesin_absensi WHERE id=?');
        $m->execute([$id]);
        $m = $m->fetch();
        if (!$m) {
            $err = 'Mesin tidak ditemukan.';
        } elseif (empty($m['serial_number'])) {
            $err = "Serial number mesin \"{$m['nama']}\" belum diisi. ADMS mengenali mesin lewat serial number — isi dulu lewat tombol Edit.";
        } else {
            $sn = $m['serial_number'];
            // Sisakan satu CHECK yang menunggu, supaya klik berulang tidak menumpuk antrean.
            $pdo->prepare("DELETE FROM adms_perintah WHERE sn=? AND status='antre' AND perintah='CHECK'")->execute([$sn]);
            admsAntre($pdo, $sn, 'CHECK');

            $selisih = $m['last_online'] ? time() - strtotime($m['last_online']) : null;
            if ($selisih !== null && $selisih < 300) {
                $msg = "Perintah CHECK dikirim ke antrean mesin \"{$m['nama']}\" (SN {$sn}). "
                     . 'Mesin terakhir menghubungi server ' . ($selisih < 60 ? 'kurang dari 1 menit' : floor($selisih / 60) . ' menit') . ' yang lalu, '
                     . 'jadi perintah akan segera diambil. Pantau statusnya di panel Antrean Perintah ADMS.';
            } elseif ($selisih !== null) {
                $err = "Perintah CHECK sudah diantrekan, tapi mesin \"{$m['nama']}\" belum menghubungi server sejak "
                     . date('d-m-Y H:i:s', strtotime($m['last_online'])) . ' (' . floor($selisih / 60) . ' menit lalu). '
                     . 'Kemungkinan mesin mati atau setelan Cloud Server / ADMS-nya belum benar.';
            } else {
                $err = "Perintah CHECK sudah diantrekan, tapi mesin \"{$m['nama']}\" belum pernah menghubungi server sama sekali. "
                     . 'Periksa menu Comm → Cloud Server Setting / ADMS di mesin: alamat server, port, dan serial number harus cocok.';
            }
        }
    } elseif ($act === 'batal_antre') {
        // Buang perintah yang belum sempat diambil mesin.
        $sn = trim($_POST['sn'] ?? '');
        $n = $pdo->prepare("DELETE FROM adms_perintah WHERE sn=? AND status='antre'");
        $n->execute([$sn]);
        $msg = $n->rowCount() . ' perintah yang masih antre dibatalkan.';

    } elseif ($act === 'tarik') {
        // Langkah 1 salin antar mesin: minta mesin sumber mengirim ulang seluruh
        // data user + template. Penanda tarik_data dibaca saat handshake (OpStamp=0);
        // CHECK memaksa mesin melakukan handshake itu. DATA QUERY untuk firmware
        // Push 3.x yang menjawab lewat /iclock/querydata (firmware lama menolaknya, aman).
        $m = $pdo->prepare('SELECT * FROM mesin_absensi WHERE id=?');
        $m->execute([(int)$_POST['id']]);
        $m = $m->fetch();
        if (!$m) {
            $err = 'Mesin tidak ditemukan.';
        } elseif (empty($m['serial_number'])) {
            $err = "Serial number mesin \"{$m['nama']}\" belum diisi.";
        } else {
            $sn = $m['serial_number'];
            $pdo->prepare('UPDATE mesin_absensi SET tarik_data = NOW() WHERE id=?')->execute([$m['id']]);
            $pdo->prepare("DELETE FROM adms_perintah WHERE sn=? AND status='antre'
                           AND (perintah='CHECK' OR perintah LIKE 'DATA QUERY tablename=%')")->execute([$sn]);
            admsAntre($pdo, $sn, 'CHECK');
            foreach (['user', 'templatev10', 'biodata'] as $t) {
                admsAntre($pdo, $sn, "DATA QUERY tablename=$t,fielddesc=*,filter=*");
            }
            $msg = "Permintaan tarik data dikirim ke mesin \"{$m['nama']}\" (SN {$sn}). Mesin akan mengirim ulang seluruh "
                 . 'data user beserta sidik jari/wajah/palm dalam beberapa menit. Pantau jumlahnya di tabel "Data User Tersimpan per Mesin" '
                 . '(muat ulang halaman), lalu lanjutkan ke langkah 2.';
        }

    } elseif ($act === 'salin') {
        // Langkah 2: antrekan DATA UPDATE ke mesin tujuan dari data mesin sumber.
        $ambil = $pdo->prepare('SELECT * FROM mesin_absensi WHERE id=?');
        $ambil->execute([(int)($_POST['sumber_id'] ?? 0)]);  $sumber = $ambil->fetch();
        $ambil->execute([(int)($_POST['tujuan_id'] ?? 0)]);  $tujuan = $ambil->fetch();
        $pilih = array_intersect((array)($_POST['bio'] ?? []), ['jari', 'wajah', 'palm', 'foto', 'lain']);
        if (!$sumber || !$tujuan) {
            $err = 'Mesin sumber / tujuan tidak ditemukan.';
        } elseif ($sumber['id'] == $tujuan['id']) {
            $err = 'Mesin sumber dan tujuan tidak boleh sama.';
        } elseif (!$tujuan['aktif'] || empty($tujuan['serial_number'])) {
            $err = "Mesin tujuan \"{$tujuan['nama']}\" harus aktif dan serial number-nya terisi.";
        } else {
            try {
                $snAsal = (string)$sumber['serial_number'];
                $snTuju = $tujuan['serial_number'];
                $st = $pdo->prepare("SELECT pin, jenis, tipe_bio, data FROM adms_data_user WHERE sn=?
                                     ORDER BY FIELD(jenis,'USER','FP','FACE','BIODATA','USERPIC','BIOPHOTO'), pin, kunci");
                $st->execute([$snAsal]);
                $jumlah = ['user' => 0, 'jari' => 0, 'wajah' => 0, 'palm' => 0, 'foto' => 0, 'lain' => 0];
                $adaUser = [];
                $pdo->beginTransaction();
                foreach ($st as $r) {
                    $gol = admsGolonganBio($r['jenis'], $r['tipe_bio'] === null ? null : (int)$r['tipe_bio']);
                    if ($gol !== 'user' && !in_array($gol, $pilih, true)) continue;
                    // Template hanya diterima mesin bila user-nya sudah ada: daftarkan
                    // dulu user yang datanya tidak ikut terkirim dari mesin sumber.
                    if ($gol !== 'user' && !isset($adaUser[$r['pin']])) {
                        $orang = admsPetakanPin($pdo, $dc, $r['pin']);
                        admsAntre($pdo, $snTuju, admsPerintahUser($r['pin'], $orang['nama'] ?? ''));
                        $adaUser[$r['pin']] = true;
                        $jumlah['user']++;
                    }
                    $cmd = admsPerintahDariData($r['jenis'], json_decode($r['data'], true) ?: []);
                    if (!$cmd) continue;
                    admsAntre($pdo, $snTuju, $cmd);
                    if ($gol === 'user') $adaUser[$r['pin']] = true;
                    $jumlah[$gol]++;
                }
                if (!$jumlah['user']) {
                    $pdo->rollBack();
                    $err = "Belum ada data user dari mesin \"{$sumber['nama']}\" yang tersimpan. Jalankan langkah 1 (Tarik Data) dulu "
                         . 'dan tunggu sampai jumlahnya muncul di tabel.';
                } else {
                    $label = ['user' => 'user', 'jari' => 'sidik jari', 'wajah' => 'wajah', 'palm' => 'palm', 'foto' => 'foto', 'lain' => 'biometrik lain'];
                    $rinci = [];
                    foreach ($jumlah as $k => $v) if ($v) $rinci[] = "$v {$label[$k]}";
                    $ket = "Salin dari {$sumber['nama']}: " . implode(', ', $rinci);
                    $pdo->prepare('INSERT INTO upload_log (mesin_id, jumlah_guru, jumlah_siswa, keterangan) VALUES (?,?,?,?)')
                        ->execute([$tujuan['id'], 0, 0, mb_substr($ket, 0, 200)]);
                    $pdo->commit();
                    $msg = "Masuk antrean mesin \"{$tujuan['nama']}\" (SN {$snTuju}): " . implode(', ', $rinci) . '. '
                         . 'Mesin tujuan mengambilnya bertahap tiap kali menghubungi server; pantau di panel Antrean Perintah ADMS.';
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = 'Salin gagal: ' . $ex->getMessage();
            }
        }

    } elseif ($act === 'hapus_user') {
        // Hapus user dari mesin lewat ADMS: tiap PIN dijadikan perintah DATA DELETE
        // di antrean; mesin mengambilnya sendiri. Data absensi di aplikasi TIDAK
        // ikut terhapus, begitu pula pemetaan mesin_pin (PIN tetap dipakai mesin lain).
        $ambil = $pdo->prepare('SELECT * FROM mesin_absensi WHERE id=?');
        $ambil->execute([(int)($_POST['mesin_id'] ?? 0)]);
        $mesin = $ambil->fetch();
        $scope = $_POST['scope'] ?? '';
        $yang  = in_array($_POST['yang'] ?? '', ['user', 'biometrik', 'jari', 'wajah'], true) ? $_POST['yang'] : 'user';
        if (!$mesin) {
            $err = 'Mesin tidak ditemukan.';
        } elseif (empty($mesin['serial_number'])) {
            $err = "Serial number mesin \"{$mesin['nama']}\" belum diisi.";
        } else {
            try {
                $sn = $mesin['serial_number'];
                $target = [];   // pin => nama
                if ($scope === 'pilih') {
                    foreach ((array)($_POST['pins'] ?? []) as $p) {
                        $p = trim((string)$p);
                        if ($p !== '') $target[$p] = $p;
                    }
                    // Nama diambil dari data user kiriman mesin (hasil Tarik Data)
                    if ($target) {
                        $in = implode(',', array_fill(0, count($target), '?'));
                        $st = $pdo->prepare("SELECT pin, data FROM adms_data_user WHERE sn=? AND jenis='USER' AND pin IN ($in)");
                        $st->execute([$sn, ...array_keys($target)]);
                        foreach ($st as $r) $target[$r['pin']] = (json_decode($r['data'], true)['Name'] ?? '') ?: $r['pin'];
                    }
                    $ket = count($target) . ' user terpilih';
                } elseif ($scope === 'pin') {
                    foreach (preg_split('/[\s,;]+/', (string)($_POST['pin_manual'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $p) {
                        if (!preg_match('/^[0-9A-Za-z]{1,24}$/', $p)) throw new RuntimeException("PIN \"$p\" tidak valid.");
                        $target[$p] = $p;
                    }
                    $ket = 'PIN: ' . implode(', ', array_slice(array_keys($target), 0, 10)) . (count($target) > 10 ? ', …' : '');
                } else {
                    [$orang, $ket] = orangPerScope($dc, $ta, $scope, $_POST);
                    foreach ($orang as $o) {
                        if (($o['induk'] ?? '') === '') continue;
                        $target[admsCariPin($pdo, $o['tipe'], $o['induk'])] = $o['nama'];
                    }
                }

                if (!$target) {
                    $err = 'Tidak ada user yang dipilih untuk dihapus.';
                } else {
                    $labelYang = ['user' => 'user beserta sidik jari/wajah/kartu', 'biometrik' => 'semua biometrik (user tetap ada)',
                                  'jari' => 'sidik jari saja', 'wajah' => 'wajah saja'][$yang];
                    // Salinan data user di server (adms_data_user) ikut disesuaikan
                    $hapusData = match ($yang) {
                        'user'      => "DELETE FROM adms_data_user WHERE sn=? AND pin=?",
                        'biometrik' => "DELETE FROM adms_data_user WHERE sn=? AND pin=? AND jenis<>'USER'",
                        'jari'      => "DELETE FROM adms_data_user WHERE sn=? AND pin=? AND (jenis='FP' OR (jenis='BIODATA' AND tipe_bio=1))",
                        'wajah'     => "DELETE FROM adms_data_user WHERE sn=? AND pin=? AND (jenis='FACE' OR (jenis='BIODATA' AND tipe_bio IN (2,9)))",
                    };
                    $hapusData = $pdo->prepare($hapusData);
                    $pdo->beginTransaction();
                    // Buang perintah upload/salin yang masih antre untuk PIN ini, supaya
                    // user tidak didaftarkan lagi sesudah dihapus.
                    $buangAntre = $pdo->prepare("DELETE FROM adms_perintah WHERE sn=? AND status='antre'
                                                 AND perintah LIKE 'DATA UPDATE %' AND (perintah LIKE ? OR perintah LIKE ?)");
                    foreach ($target as $pin => $nama) {
                        $pin = (string)$pin;
                        if ($yang === 'user') {
                            $buangAntre->execute([$sn, "% PIN=$pin\t%", "% Pin=$pin\t%"]);
                            admsAntre($pdo, $sn, admsPerintahHapusUser($pin));
                        } else {
                            foreach (admsPerintahHapusBiometrik($pin, $yang === 'biometrik' ? 'semua' : $yang) as $c) admsAntre($pdo, $sn, $c);
                        }
                        $hapusData->execute([$sn, $pin]);
                    }
                    $pdo->prepare('INSERT INTO upload_log (mesin_id, jumlah_guru, jumlah_siswa, keterangan) VALUES (?,?,?,?)')
                        ->execute([$mesin['id'], 0, 0, mb_substr("HAPUS $labelYang — $ket (" . count($target) . ' user)', 0, 200)]);
                    $pdo->commit();

                    $contoh = [];
                    foreach (array_slice($target, 0, 3, true) as $pin => $nama) $contoh[] = "$nama (PIN $pin)";
                    $msg = count($target) . " perintah hapus ($labelYang) masuk antrean mesin \"{$mesin['nama']}\" (SN $sn): "
                         . implode(', ', $contoh) . (count($target) > 3 ? ', …' : '') . '. '
                         . 'Data absensi di aplikasi tidak ikut terhapus. Pantau hasilnya di panel Antrean Perintah ADMS.';
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = 'Hapus gagal: ' . $ex->getMessage();
            }
        }

    } elseif ($act === 'upload') {
        // Upload lewat ADMS: data TIDAK dikirim langsung ke mesin. Setiap orang
        // dijadikan perintah "DATA UPDATE USERINFO" di tabel adms_perintah, lalu
        // mesin mengambilnya sendiri saat memanggil /iclock/getrequest.
        // PIN dialokasikan & dicatat di mesin_pin supaya scan balik bisa dipetakan.
        $mesinId = (int)($_POST['mesin_id'] ?? 0);
        $mesin = $pdo->prepare('SELECT * FROM mesin_absensi WHERE id=?');
        $mesin->execute([$mesinId]);
        $mesin = $mesin->fetch();
        $scope = $_POST['scope'] ?? '';
        if (!$mesin) {
            $err = 'Mesin tujuan tidak ditemukan.';
        } elseif (!$mesin['aktif']) {
            $err = 'Mesin tidak aktif — aktifkan dulu sebelum upload.';
        } elseif (empty($mesin['serial_number'])) {
            $err = "Serial number mesin \"{$mesin['nama']}\" belum diisi. ADMS mengenali mesin lewat serial number — isi dulu lewat tombol Edit.";
        } else {
            try {
                [$orang, $ket] = orangPerScope($dc, $ta, $scope, $_POST);

                if (!$orang) {
                    $err = "Tidak ada data untuk diupload ($ket).";
                } else {
                    $sn = $mesin['serial_number'];
                    $jg = 0; $js = 0; $contohPin = [];
                    $pdo->beginTransaction();
                    foreach ($orang as $o) {
                        if (($o['induk'] ?? '') === '') continue;   // tanpa nomor induk tidak bisa dipetakan
                        $pin = admsAlokasiPin($pdo, $o['tipe'], $o['induk']);
                        admsAntre($pdo, $sn, admsPerintahUser($pin, $o['nama']));
                        if ($o['tipe'] === 'guru') $jg++; else $js++;
                        if (count($contohPin) < 3) $contohPin[] = $o['nama'] . ' → PIN ' . $pin;
                    }
                    $pdo->prepare('INSERT INTO upload_log (mesin_id, jumlah_guru, jumlah_siswa, keterangan) VALUES (?,?,?,?)')
                        ->execute([$mesinId, $jg, $js, $ket]);
                    $pdo->commit();

                    $jml = $jg + $js;
                    $rincian = $scope === 'jabatan' ? "$jg guru" : "$js siswa";
                    $onlineBaru = $mesin['last_online'] && (time() - strtotime($mesin['last_online']) < 300);
                    $msg = "$jml perintah pendaftaran user ($rincian — $ket) masuk antrean untuk mesin \"{$mesin['nama']}\" (SN {$sn}). "
                         . "Mesin akan mengambilnya sendiri saat menghubungi server. Contoh: " . implode(', ', $contohPin) . '.'
                         . ($onlineBaru ? '' : ' Catatan: mesin ini belum menghubungi server dalam 5 menit terakhir, jadi antrean baru terkirim setelah mesin online.');
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = 'Upload gagal: ' . $ex->getMessage();
            }
        }
    }
}

$mesinList   = $pdo->query('SELECT * FROM mesin_absensi ORDER BY id')->fetchAll();
$activeMesin = array_values(array_filter($mesinList, fn($m) => $m['aktif']));
$logs        = $pdo->query('SELECT l.*, m.nama FROM upload_log l JOIN mesin_absensi m ON m.id=l.mesin_id ORDER BY l.waktu DESC LIMIT 10')->fetchAll();
// Ringkasan antrean perintah ADMS per serial number
$antrean = [];
foreach ($pdo->query("SELECT sn, status, COUNT(*) c, MAX(dikirim) terakhir
                      FROM adms_perintah GROUP BY sn, status") as $r) {
    $antrean[$r['sn']][$r['status']] = ['c' => (int)$r['c'], 'terakhir' => $r['terakhir']];
}
// Ringkasan data user & biometrik yang sudah dikirim tiap mesin (bahan salin antar mesin)
$dataUser = [];
foreach ($pdo->query("SELECT sn, jenis, tipe_bio, COUNT(*) c, MAX(diperbarui) terakhir
                      FROM adms_data_user GROUP BY sn, jenis, tipe_bio") as $r) {
    $gol = admsGolonganBio($r['jenis'], $r['tipe_bio'] === null ? null : (int)$r['tipe_bio']);
    $dataUser[$r['sn']][$gol] = ($dataUser[$r['sn']][$gol] ?? 0) + (int)$r['c'];
    $dataUser[$r['sn']]['terakhir'] = max($dataUser[$r['sn']]['terakhir'] ?? '', $r['terakhir']);
}
// Daftar user per mesin (hasil Tarik Data) untuk pilihan hapus user
$userMesin = [];
foreach ($pdo->query("SELECT sn, pin, JSON_UNQUOTE(JSON_EXTRACT(data, '$.Name')) nama
                      FROM adms_data_user WHERE jenis='USER' ORDER BY sn, CAST(pin AS UNSIGNED), pin") as $r) {
    $userMesin[$r['sn']][$r['pin']] = ['nama' => $r['nama'] ?? '', 'jari' => 0, 'wajah' => 0, 'palm' => 0];
}
foreach ($pdo->query("SELECT sn, pin, jenis, tipe_bio, COUNT(*) c FROM adms_data_user
                      WHERE jenis<>'USER' GROUP BY sn, pin, jenis, tipe_bio") as $r) {
    if (!isset($userMesin[$r['sn']][$r['pin']])) continue;
    $gol = admsGolonganBio($r['jenis'], $r['tipe_bio'] === null ? null : (int)$r['tipe_bio']);
    if (isset($userMesin[$r['sn']][$r['pin']][$gol])) $userMesin[$r['sn']][$r['pin']][$gol] += (int)$r['c'];
}
$tingkatList = $ta ? dcTingkatList($dc, (int)$ta['id']) : [];
$rombelList  = $ta ? dcKelasList($dc, (int)$ta['id']) : [];
$jabatanList = dcJabatanList($dc);

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<!-- ============ Alamat untuk Mesin (terdeteksi otomatis) ============ -->
<?php
$urlAdms = urlAdms();
// Alamat lewat IP server berguna bila mesin tidak bisa menerjemahkan nama domain
$ipServer = $_SERVER['SERVER_ADDR'] ?? '';
$urlAdmsIp = '';
if ($ipServer && !filter_var($_SERVER['HTTP_HOST'] ?? '', FILTER_VALIDATE_IP)) {
    $urlAdmsIp = preg_replace('#://[^/]+#', '://' . $ipServer, $urlAdms);
}
?>
<div class="card card-stat mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-link-45deg me-1"></i>Alamat Server untuk Mesin Absensi</div>
  <div class="card-body">
    <p class="text-muted small mb-2">
      Isikan alamat ini ke menu <b>Comm &rarr; Cloud Server Setting / ADMS</b> di mesin.
      Alamat terdeteksi otomatis dari server yang sedang Anda buka, jadi setelah aplikasi
      dipindah ke server lain cukup buka halaman ini lagi dan salin alamat yang baru.
    </p>
    <div class="input-group mb-2">
      <span class="input-group-text">Alamat</span>
      <input class="form-control font-monospace" id="url_adms" value="<?= e($urlAdms) ?>" readonly>
      <button class="btn btn-outline-secondary" type="button" onclick="salinUrl('url_adms', this)">Salin</button>
    </div>
    <?php if ($urlAdmsIp): ?>
    <div class="input-group mb-2">
      <span class="input-group-text">Lewat IP</span>
      <input class="form-control font-monospace" id="url_adms_ip" value="<?= e($urlAdmsIp) ?>" readonly>
      <button class="btn btn-outline-secondary" type="button" onclick="salinUrl('url_adms_ip', this)">Salin</button>
    </div>
    <div class="text-muted small mb-2">Pakai alamat IP bila mesin tidak dapat menerjemahkan nama domain.</div>
    <?php endif; ?>
    <div class="small">
      <span class="badge bg-<?= str_starts_with($urlAdms, 'https') ? 'success' : 'secondary' ?>">
        <?= str_starts_with($urlAdms, 'https') ? 'HTTPS aktif' : 'HTTP biasa' ?>
      </span>
      <span class="text-muted ms-2">
        Port: <?= e($_SERVER['SERVER_PORT'] ?? '80') ?> &middot;
        Server: <?= e(strtok($_SERVER['SERVER_SOFTWARE'] ?? '-', ' ')) ?> &middot;
        Zona waktu: <?= e(date_default_timezone_get()) ?>
      </span>
    </div>
    <div class="text-muted small mt-2">
      Sebagian firmware hanya menerima alamat tanpa skema — dalam hal itu isi bagian setelah
      <code>://</code> saja, dan pastikan port diisi terpisah.
    </div>
  </div>
</div>

<!-- ============ Daftar Mesin ============ -->
<div class="card card-stat mb-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold">Daftar Mesin Absensi</span>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalMesin" onclick="resetForm()"><i class="bi bi-plus-lg me-1"></i>Tambah Mesin</button>
  </div>
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Nama</th><th>Serial Number</th><th>Tipe</th><th>Lokasi</th><th>Aktif</th><th>Kontak Terakhir</th><th>Koneksi</th><th style="width:150px">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($mesinList as $m):
        // Pada ADMS, mesin dianggap tersambung bila menghubungi server dalam 5 menit terakhir.
        $online = $m['last_online'] && (time() - strtotime($m['last_online']) < 300); ?>
        <tr>
          <td><?= e($m['nama']) ?></td>
          <td><?= $m['serial_number'] ? '<code>' . e($m['serial_number']) . '</code>' : '<span class="text-danger small">belum diisi</span>' ?></td>
          <td><?= e($m['tipe']) ?></td>
          <td><?= e($m['lokasi']) ?></td>
          <td><?= $m['aktif'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
          <td><?= $m['last_online'] ? e(date('d-m-Y H:i:s', strtotime($m['last_online']))) : '<span class="text-muted">Belum pernah</span>' ?></td>
          <td><?= $online ? '<span class="badge bg-success">Tersambung</span>' : '<span class="badge bg-secondary">Tidak ada kabar</span>' ?></td>
          <td>
            <form method="post" class="d-inline">
              <input type="hidden" name="act" value="cek"><input type="hidden" name="id" value="<?= $m['id'] ?>">
              <button class="btn btn-sm btn-outline-primary" title="Kirim perintah CHECK lewat ADMS"><i class="bi bi-broadcast"></i></button>
            </form>
            <button class="btn btn-sm btn-warning" onclick='editMesin(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline" onsubmit="return confirm('Hapus mesin ini?')">
              <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $m['id'] ?>">
              <button class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mesinList): ?><tr><td colspan="8" class="text-muted">Belum ada mesin absensi.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="text-muted small">
      <i class="bi bi-broadcast me-1"></i>Tombol cek mengirim perintah <code>CHECK</code> lewat antrean ADMS — mesin mengambilnya
      saat menghubungi server, lalu melapor hasilnya. Kolom <b>Koneksi</b> menandai mesin yang menghubungi server dalam 5 menit terakhir.
      Mesin dikenali lewat <b>serial number</b>; alamat IP mesin tidak diperlukan karena pada ADMS mesin yang menghubungi server.
    </div>
  </div>
</div>

<!-- ============ Upload Data ke Mesin (terpisah) ============ -->
<div class="card card-stat mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i>Upload Data ke Mesin</div>
  <div class="card-body">
    <?php if (!$activeMesin): ?>
      <div class="text-muted">Belum ada mesin <b>aktif</b>. Tambahkan atau aktifkan mesin terlebih dahulu.</div>
    <?php else: ?>
    <form method="post" class="row g-3" onsubmit="return confirm('Masukkan data terpilih ke antrean perintah mesin ini?')">
      <input type="hidden" name="act" value="upload">
      <div class="col-md-4">
        <label class="form-label">Mesin Tujuan</label>
        <select class="form-select" name="mesin_id" required>
          <?php foreach ($activeMesin as $m): ?>
            <option value="<?= $m['id'] ?>"><?= e($m['nama']) ?><?= $m['serial_number'] ? ' — SN ' . e($m['serial_number']) : ' — SN belum diisi' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Jenis Data</label>
        <select class="form-select" id="up_scope" name="scope" onchange="toggleScope()">
          <option value="tingkat">Siswa — Per Tingkat Kelas</option>
          <option value="rombel">Siswa — Per Rombel</option>
          <option value="jabatan">Guru — Per Jabatan</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Pilihan</label>
        <div class="scope-wrap" data-scope="tingkat">
          <select class="form-select" id="sel_tingkat" name="tingkat">
            <?php foreach ($tingkatList as $t): ?><option value="<?= $t ?>">Tingkat <?= $t ?></option><?php endforeach; ?>
            <?php if (!$tingkatList): ?><option value="">(tidak ada kelas)</option><?php endif; ?>
          </select>
        </div>
        <div class="scope-wrap" data-scope="rombel">
          <select class="form-select" id="sel_rombel" name="rombel_id">
            <?php foreach ($rombelList as $rb): ?><option value="<?= $rb['id'] ?>"><?= e($rb['nama']) ?> — Tingkat <?= e($rb['tingkat']) ?></option><?php endforeach; ?>
            <?php if (!$rombelList): ?><option value="">(tidak ada rombel)</option><?php endif; ?>
          </select>
        </div>
        <div class="scope-wrap" data-scope="jabatan">
          <select class="form-select" id="sel_jabatan" name="jabatan">
            <?php foreach ($jabatanList as $jb): ?><option value="<?= e($jb) ?>"><?= e($jb) ?></option><?php endforeach; ?>
            <?php if (!$jabatanList): ?><option value="">(tidak ada jabatan)</option><?php endif; ?>
          </select>
        </div>
      </div>
      <div class="col-12 d-flex align-items-center flex-wrap gap-2">
        <button class="btn btn-success"><i class="bi bi-upload me-1"></i>Kirim ke Antrean Mesin</button>
        <span class="text-muted small">
          Sumber data: datacenter (TA <?= e($ta['nama_tahun_ajaran'] ?? '-') ?>).
          Data dikirim lewat <b>ADMS</b>: tiap orang menjadi perintah <code>DATA UPDATE USERINFO</code> di antrean,
          lalu mesin mengambilnya sendiri. PIN memakai nomor induk bila muat (maks 9 digit angka);
          NIP yang terlalu panjang diberi PIN dari blok 900001 dan dicatat di <code>mesin_pin</code>.
        </span>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- ============ Hapus User dari Mesin ============ -->
<div class="card card-stat mb-4 border-danger-subtle">
  <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-person-x me-1"></i>Hapus User dari Mesin</div>
  <div class="card-body">
    <?php $mesinBerSn = array_values(array_filter($mesinList, fn($m) => $m['serial_number'])); ?>
    <?php if (!$mesinBerSn): ?>
      <div class="text-muted">Belum ada mesin dengan serial number.</div>
    <?php else: ?>
    <form method="post" id="formHapus" class="row g-3">
      <input type="hidden" name="act" value="hapus_user">
      <div class="col-md-4">
        <label class="form-label">Mesin</label>
        <select class="form-select" name="mesin_id" id="hp_mesin" onchange="hpTampil()">
          <?php foreach ($mesinBerSn as $m): ?>
            <option value="<?= $m['id'] ?>" data-sn="<?= e($m['serial_number']) ?>"><?= e($m['nama']) ?> — SN <?= e($m['serial_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Pilih User</label>
        <select class="form-select" name="scope" id="hp_scope" onchange="hpTampil()">
          <option value="pilih">Pilih dari daftar user di mesin</option>
          <option value="rombel">Siswa — Per Rombel</option>
          <option value="tingkat">Siswa — Per Tingkat Kelas</option>
          <option value="jabatan">Guru — Per Jabatan</option>
          <option value="pin">Ketik PIN manual</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Yang Dihapus</label>
        <select class="form-select" name="yang" id="hp_yang">
          <option value="user">User beserta sidik jari, wajah &amp; kartu</option>
          <option value="biometrik">Semua biometrik saja (user tetap ada)</option>
          <option value="jari">Sidik jari saja</option>
          <option value="wajah">Wajah saja</option>
        </select>
      </div>

      <div class="col-12 hp-wrap" data-scope="rombel">
        <select class="form-select" name="rombel_id">
          <?php foreach ($rombelList as $rb): ?><option value="<?= $rb['id'] ?>"><?= e($rb['nama']) ?> — Tingkat <?= e($rb['tingkat']) ?></option><?php endforeach; ?>
          <?php if (!$rombelList): ?><option value="">(tidak ada rombel)</option><?php endif; ?>
        </select>
      </div>
      <div class="col-12 hp-wrap" data-scope="tingkat">
        <select class="form-select" name="tingkat">
          <?php foreach ($tingkatList as $t): ?><option value="<?= $t ?>">Tingkat <?= $t ?></option><?php endforeach; ?>
          <?php if (!$tingkatList): ?><option value="">(tidak ada kelas)</option><?php endif; ?>
        </select>
      </div>
      <div class="col-12 hp-wrap" data-scope="jabatan">
        <select class="form-select" name="jabatan">
          <?php foreach ($jabatanList as $jb): ?><option value="<?= e($jb) ?>"><?= e($jb) ?></option><?php endforeach; ?>
          <?php if (!$jabatanList): ?><option value="">(tidak ada jabatan)</option><?php endif; ?>
        </select>
      </div>
      <div class="col-12 hp-wrap" data-scope="pin">
        <textarea class="form-control font-monospace" name="pin_manual" rows="2" placeholder="Contoh: 1001, 1002 1003"></textarea>
        <div class="form-text">Pisahkan beberapa PIN dengan koma, spasi, atau baris baru.</div>
      </div>
      <div class="col-12 hp-wrap" data-scope="pilih">
        <?php foreach ($mesinBerSn as $m): $daftar = $userMesin[$m['serial_number']] ?? []; ?>
        <div class="hp-daftar" data-sn="<?= e($m['serial_number']) ?>">
          <?php if (!$daftar): ?>
            <div class="alert alert-light border small mb-0">
              Daftar user mesin ini belum ada di server. Klik <b>Tarik Data</b> pada mesin ini di panel
              <i>Salin User</i> di bawah, tunggu beberapa menit, lalu muat ulang halaman. Atau pakai pilihan lain (per rombel / ketik PIN).
            </div>
          <?php else: ?>
          <div class="d-flex gap-2 mb-2 flex-wrap">
            <input class="form-control form-control-sm hp-cari" style="max-width:260px" placeholder="Cari nama / PIN">
            <button type="button" class="btn btn-sm btn-outline-secondary hp-semua">Centang semua yang tampil</button>
            <button type="button" class="btn btn-sm btn-outline-secondary hp-kosong">Kosongkan</button>
            <span class="small text-muted align-self-center"><span class="hp-jml">0</span> dipilih dari <?= count($daftar) ?> user</span>
          </div>
          <div class="table-responsive border rounded" style="max-height:320px; overflow:auto">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light" style="position:sticky; top:0"><tr><th style="width:36px"></th><th>PIN</th><th>Nama di Mesin</th>
                <th class="text-end">Jari</th><th class="text-end">Wajah</th><th class="text-end">Palm</th></tr></thead>
              <tbody>
              <?php foreach ($daftar as $pin => $u): ?>
                <tr>
                  <td><input class="form-check-input" type="checkbox" name="pins[]" value="<?= e($pin) ?>"></td>
                  <td class="font-monospace"><?= e($pin) ?></td>
                  <td><?= e($u['nama']) ?></td>
                  <td class="text-end"><?= $u['jari'] ?: '' ?></td>
                  <td class="text-end"><?= $u['wajah'] ?: '' ?></td>
                  <td class="text-end"><?= $u['palm'] ?: '' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="col-12 d-flex align-items-center flex-wrap gap-2">
        <button class="btn btn-danger"><i class="bi bi-trash me-1"></i>Hapus dari Mesin</button>
        <span class="text-muted small">
          Dikirim lewat antrean ADMS (perintah <code>DATA DELETE</code>). Yang dihapus hanya data di <b>mesin</b> —
          riwayat absensi di aplikasi tetap aman. Penghapusan di mesin tidak bisa dibatalkan; user perlu didaftarkan ulang
          (atau disalin lagi dari mesin lain) bila terhapus tidak sengaja.
        </span>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- ============ Salin User & Biometrik Antar Mesin ============ -->
<div class="card card-stat mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-files me-1"></i>Salin User + Sidik Jari / Wajah / Palm Antar Mesin</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      <b>Langkah 1</b> — klik <b>Tarik Data</b> pada mesin sumber: mesin diminta mengirim ulang seluruh data user beserta
      template biometriknya ke server. Tunggu beberapa menit sampai jumlahnya muncul di tabel (muat ulang halaman).
      <b>Langkah 2</b> — pilih mesin sumber &amp; tujuan lalu klik <b>Salin</b>; data dikirim ke mesin tujuan lewat antrean ADMS.
    </p>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Mesin</th><th class="text-end">User</th><th class="text-end">Sidik Jari</th><th class="text-end">Wajah</th>
        <th class="text-end">Palm</th><th class="text-end">Foto</th><th>Data Terakhir Diterima</th><th style="width:150px"></th></tr></thead>
      <tbody>
      <?php foreach ($mesinList as $m): $d = $m['serial_number'] ? ($dataUser[$m['serial_number']] ?? []) : []; ?>
        <tr>
          <td><?= e($m['nama']) ?><?= $m['serial_number'] ? ' <code class="small">' . e($m['serial_number']) . '</code>' : '' ?></td>
          <?php foreach (['user', 'jari', 'wajah', 'palm', 'foto'] as $g): ?>
            <td class="text-end"><?= (int)($d[$g] ?? 0) ?></td>
          <?php endforeach; ?>
          <td>
            <?= e($d['terakhir'] ?? '—') ?>
            <?php if (!empty($m['tarik_data'])): ?><span class="badge bg-warning text-dark ms-1">menunggu mesin</span><?php endif; ?>
          </td>
          <td>
            <?php if ($m['serial_number']): ?>
            <form method="post" onsubmit="return confirm('Minta mesin ini mengirim ulang seluruh data user & biometrik?')">
              <input type="hidden" name="act" value="tarik"><input type="hidden" name="id" value="<?= $m['id'] ?>">
              <button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-cloud-download me-1"></i>Tarik Data</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mesinList): ?><tr><td colspan="8" class="text-muted">Belum ada mesin absensi.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>

    <?php if (count($mesinList) >= 2): ?>
    <form method="post" class="row g-3 align-items-end" onsubmit="return confirm('Salin data user & biometrik terpilih ke mesin tujuan?')">
      <input type="hidden" name="act" value="salin">
      <div class="col-md-3">
        <label class="form-label">Mesin Sumber</label>
        <select class="form-select" name="sumber_id" required>
          <?php foreach ($mesinList as $m): if (!$m['serial_number']) continue; ?>
            <option value="<?= $m['id'] ?>"><?= e($m['nama']) ?> (<?= (int)($dataUser[$m['serial_number']]['user'] ?? 0) ?> user)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Mesin Tujuan</label>
        <select class="form-select" name="tujuan_id" required>
          <?php foreach ($activeMesin as $i => $m): if (!$m['serial_number']) continue; ?>
            <option value="<?= $m['id'] ?>" <?= $i === 1 ? 'selected' : '' ?>><?= e($m['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label d-block">Ikut Disalin</label>
        <?php foreach (['jari' => 'Sidik jari', 'wajah' => 'Wajah', 'palm' => 'Palm', 'foto' => 'Foto user'] as $k => $lbl): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="bio[]" value="<?= $k ?>" id="bio_<?= $k ?>" <?= $k !== 'foto' ? 'checked' : '' ?>>
            <label class="form-check-label" for="bio_<?= $k ?>"><?= $lbl ?></label>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="col-md-2">
        <button class="btn btn-success w-100"><i class="bi bi-files me-1"></i>Salin</button>
      </div>
    </form>
    <?php endif; ?>
    <div class="text-muted small mt-3">
      Data user (PIN, nama, kartu, password, hak akses) selalu ikut. Template hanya bisa dipakai bila <b>algoritma kedua mesin sama</b>
      (mis. sidik jari ZKFinger v10 ≠ v12; wajah &amp; palm umumnya hanya cocok antar seri yang sama). Foto user berukuran besar,
      jadi hanya centang bila diperlukan — pada mesin wajah <i>visible light</i> foto dapat dipakai mesin untuk membentuk ulang template wajah.
    </div>
  </div>
</div>

<!-- ============ Antrean Perintah ADMS ============ -->
<div class="card card-stat mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check me-1"></i>Antrean Perintah ADMS</div>
  <div class="card-body table-responsive">
    <table class="table table-sm align-middle mb-2">
      <thead><tr><th>Mesin</th><th>Serial Number</th><th class="text-end">Antre</th><th class="text-end">Terkirim</th><th class="text-end">Selesai</th><th>Pengiriman Terakhir</th><th style="width:130px"></th></tr></thead>
      <tbody>
      <?php foreach ($mesinList as $m): $sn = $m['serial_number']; $a = $sn ? ($antrean[$sn] ?? []) : []; ?>
        <tr>
          <td><?= e($m['nama']) ?></td>
          <td><?= $sn ? '<code>' . e($sn) . '</code>' : '<span class="text-danger small">belum diisi</span>' ?></td>
          <td class="text-end"><span class="badge bg-<?= !empty($a['antre']) ? 'warning text-dark' : 'light text-dark border' ?>"><?= (int)($a['antre']['c'] ?? 0) ?></span></td>
          <td class="text-end"><span class="badge bg-<?= !empty($a['terkirim']) ? 'info' : 'light text-dark border' ?>"><?= (int)($a['terkirim']['c'] ?? 0) ?></span></td>
          <td class="text-end"><span class="badge bg-<?= !empty($a['selesai']) ? 'success' : 'light text-dark border' ?>"><?= (int)($a['selesai']['c'] ?? 0) ?></span></td>
          <td><?= e($a['terkirim']['terakhir'] ?? $a['selesai']['terakhir'] ?? '—') ?></td>
          <td>
            <?php if (!empty($a['antre'])): ?>
            <form method="post" onsubmit="return confirm('Batalkan semua perintah yang masih antre untuk mesin ini?')">
              <input type="hidden" name="act" value="batal_antre"><input type="hidden" name="sn" value="<?= e($sn) ?>">
              <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-lg me-1"></i>Batalkan</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mesinList): ?><tr><td colspan="7" class="text-muted">Belum ada mesin absensi.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="text-muted small">
      <b>Antre</b> = menunggu diambil mesin &middot; <b>Terkirim</b> = sudah diambil mesin, menunggu laporan hasil &middot;
      <b>Selesai</b> = mesin melaporkan perintah sudah dijalankan. Pantau lalu lintasnya di <a href="adms_monitor.php">Monitor ADMS</a>.
    </div>
  </div>
</div>

<!-- ============ Riwayat Upload ============ -->
<div class="card card-stat">
  <div class="card-header bg-white fw-semibold">Riwayat Pengiriman Data ke Mesin</div>
  <div class="card-body table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Waktu</th><th>Mesin</th><th>Keterangan</th><th class="text-end">Guru</th><th class="text-end">Siswa</th></tr></thead>
      <tbody>
      <?php if (!$logs): ?><tr><td colspan="5" class="text-muted">Belum ada riwayat upload.</td></tr><?php endif; ?>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td><?= e($l['waktu']) ?></td>
          <td><?= e($l['nama']) ?></td>
          <td><?= e($l['keterangan'] ?? '-') ?></td>
          <td class="text-end"><?= e($l['jumlah_guru']) ?></td>
          <td class="text-end"><?= e($l['jumlah_siswa']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal tambah/edit mesin -->
<div class="modal fade" id="modalMesin"><div class="modal-dialog"><div class="modal-content">
  <form method="post">
    <div class="modal-header"><h5 class="modal-title" id="modalTitle">Tambah Mesin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="act" id="f_act" value="add">
      <input type="hidden" name="id" id="f_id">
      <div class="mb-3"><label class="form-label">Nama Mesin</label><input class="form-control" name="nama" id="f_nama" required></div>
      <div class="mb-3">
        <label class="form-label">Serial Number</label>
        <input class="form-control" name="serial_number" id="f_serial" placeholder="mis. ZK-A1B2C3D4" required>
        <div class="form-text">Identitas mesin di ADMS — harus sama persis dengan SN yang tertera di mesin.</div>
      </div>
      <div class="mb-3"><label class="form-label">Tipe</label><input class="form-control" name="tipe" id="f_tipe" value="Fingerprint"></div>
      <div class="mb-3"><label class="form-label">Lokasi</label><input class="form-control" name="lokasi" id="f_lokasi"></div>
      <div class="form-check"><input type="checkbox" class="form-check-input" name="aktif" id="f_aktif" checked><label class="form-check-label" for="f_aktif">Aktif</label></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
  </form>
</div></div></div>

<script>
function salinUrl(id, tombol) {
  const inp = document.getElementById(id);
  inp.select();
  navigator.clipboard.writeText(inp.value).then(() => {
    const semula = tombol.textContent;
    tombol.textContent = 'Tersalin';
    setTimeout(() => tombol.textContent = semula, 1500);
  });
}
function resetForm() {
  document.getElementById('modalTitle').textContent = 'Tambah Mesin';
  document.getElementById('f_act').value = 'add';
  ['f_id','f_nama','f_serial','f_lokasi'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('f_tipe').value = 'Fingerprint';
  document.getElementById('f_aktif').checked = true;
}
function editMesin(m) {
  document.getElementById('modalTitle').textContent = 'Edit Mesin';
  document.getElementById('f_act').value = 'edit';
  document.getElementById('f_id').value = m.id;
  document.getElementById('f_nama').value = m.nama;
  document.getElementById('f_serial').value = m.serial_number || '';
  document.getElementById('f_tipe').value = m.tipe;
  document.getElementById('f_lokasi').value = m.lokasi || '';
  document.getElementById('f_aktif').checked = m.aktif == 1;
  new bootstrap.Modal(document.getElementById('modalMesin')).show();
}
// Tampilkan hanya pilihan yang sesuai jenis data (sembunyikan wrapper agar tampilan Select2 ikut
// tersembunyi). Server hanya membaca field sesuai "scope", jadi field lain diabaikan.
function toggleScope() {
  const scope = document.getElementById('up_scope').value;
  document.querySelectorAll('.scope-wrap').forEach(w => {
    w.style.display = (w.dataset.scope === scope) ? '' : 'none';
  });
}
if (document.getElementById('up_scope')) toggleScope();

// ---- Hapus user dari mesin ----
function hpTampil() {
  const form = document.getElementById('formHapus');
  if (!form) return;
  const scope = document.getElementById('hp_scope').value;
  const mesin = document.getElementById('hp_mesin');
  const sn = mesin.options[mesin.selectedIndex].dataset.sn;
  form.querySelectorAll('.hp-wrap').forEach(w => w.style.display = w.dataset.scope === scope ? '' : 'none');
  // Hanya daftar milik mesin terpilih yang ikut terkirim
  form.querySelectorAll('.hp-daftar').forEach(d => {
    const aktif = d.dataset.sn === sn;
    d.style.display = aktif ? '' : 'none';
    d.querySelectorAll('input[name="pins[]"]').forEach(c => c.disabled = !aktif);
  });
}
(() => {
  const form = document.getElementById('formHapus');
  if (!form) return;
  form.querySelectorAll('.hp-daftar').forEach(d => {
    const hitung = () => { const j = d.querySelector('.hp-jml'); if (j) j.textContent = d.querySelectorAll('input[name="pins[]"]:checked').length; };
    const cari = d.querySelector('.hp-cari');
    if (!cari) return;
    cari.addEventListener('input', () => {
      const q = cari.value.toLowerCase();
      d.querySelectorAll('tbody tr').forEach(tr => tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none');
    });
    d.querySelector('.hp-semua').onclick = () => {
      d.querySelectorAll('tbody tr').forEach(tr => { if (tr.style.display !== 'none') tr.querySelector('input').checked = true; });
      hitung();
    };
    d.querySelector('.hp-kosong').onclick = () => { d.querySelectorAll('input[name="pins[]"]').forEach(c => c.checked = false); hitung(); };
    d.addEventListener('change', hitung);
  });
  form.addEventListener('submit', e => {
    const scope = document.getElementById('hp_scope').value;
    const mesin = document.getElementById('hp_mesin');
    const yang = document.getElementById('hp_yang');
    let jumlah = '';
    if (scope === 'pilih') {
      const n = form.querySelectorAll('input[name="pins[]"]:checked:not(:disabled)').length;
      if (!n) { e.preventDefault(); alert('Centang dulu user yang akan dihapus.'); return; }
      jumlah = n + ' user';
    }
    const teks = `Hapus ${yang.options[yang.selectedIndex].text.toLowerCase()}${jumlah ? ' untuk ' + jumlah : ''} dari mesin "${mesin.options[mesin.selectedIndex].text}"?\n\nPenghapusan di mesin tidak bisa dibatalkan.`;
    if (!confirm(teks)) e.preventDefault();
  });
  hpTampil();
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
