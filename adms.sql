-- ============================================================================
--  TABEL PENDUKUNG ADMS (Push SDK ZKTeco)
-- ----------------------------------------------------------------------------
--  Mesin absensi menghubungi server lewat HTTP (/iclock/...), bukan sebaliknya.
--    adms_scan     : data mentah ATTLOG apa adanya dari mesin (jejak audit)
--    adms_log      : catatan setiap request mesin (untuk pemantauan/diagnosa)
--    adms_perintah : antrean perintah yang diambil mesin lewat /iclock/getrequest
--    mesin_pin     : pemetaan PIN mesin -> NIS/NIP, dipakai bila PIN berbeda
--                    dari nomor induk (mis. NIP 18 digit tidak muat di mesin)
--    adms_data_user: data user & template biometrik kiriman mesin, dipakai
--                    untuk menyalin user beserta sidik jari/wajah/palm ke mesin lain
-- ============================================================================
USE absensi_sekolah;

CREATE TABLE IF NOT EXISTS adms_scan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sn VARCHAR(50) DEFAULT NULL COMMENT 'serial number mesin pengirim',
  pin VARCHAR(30) NOT NULL COMMENT 'PIN/user id di mesin',
  waktu DATETIME NOT NULL,
  status_mesin TINYINT NOT NULL COMMENT 'punch state asli mesin: 0=in,1=out,2=break-out,3=break-in,4=OT-in,5=OT-out',
  verify TINYINT DEFAULT NULL COMMENT 'cara verifikasi: 1=sidik jari, 3=kartu, 4=password, dst',
  tipe ENUM('siswa','guru') DEFAULT NULL COMMENT 'hasil pemetaan PIN; NULL = tidak dikenal',
  nomor_induk VARCHAR(30) DEFAULT NULL COMMENT 'NIS/NIP hasil pemetaan PIN',
  diproses TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = sudah ditulis ke tabel absensi',
  diterima_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_scan (pin, waktu, status_mesin),
  KEY idx_waktu (waktu),
  KEY idx_induk (tipe, nomor_induk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adms_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  waktu DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sn VARCHAR(50) DEFAULT NULL,
  endpoint VARCHAR(30) NOT NULL,
  tabel VARCHAR(30) DEFAULT NULL COMMENT 'ATTLOG / OPERLOG / dll',
  jumlah INT NOT NULL DEFAULT 0 COMMENT 'baris diterima',
  disimpan INT NOT NULL DEFAULT 0 COMMENT 'baris baru tersimpan',
  gagal INT NOT NULL DEFAULT 0 COMMENT 'baris tidak terpetakan',
  sn_dikenal TINYINT(1) NOT NULL DEFAULT 1,
  ip VARCHAR(45) DEFAULT NULL,
  KEY idx_waktu (waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adms_perintah (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sn VARCHAR(50) NOT NULL,
  perintah MEDIUMTEXT NOT NULL COMMENT 'isi perintah tanpa awalan C:<id>:',
  status ENUM('antre','terkirim','selesai') NOT NULL DEFAULT 'antre',
  hasil VARCHAR(100) DEFAULT NULL COMMENT 'Return= dari mesin',
  dibuat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dikirim DATETIME DEFAULT NULL,
  KEY idx_sn_status (sn, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mesin_pin (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pin VARCHAR(30) NOT NULL,
  tipe ENUM('siswa','guru') NOT NULL,
  nomor_induk VARCHAR(30) NOT NULL COMMENT 'NIS (siswa) / NIP (guru)',
  UNIQUE KEY uk_pin (pin),
  KEY idx_induk (tipe, nomor_induk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adms_data_user (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sn VARCHAR(50) NOT NULL COMMENT 'serial number mesin asal data',
  pin VARCHAR(30) NOT NULL,
  jenis VARCHAR(10) NOT NULL COMMENT 'USER / FP / FACE / BIODATA / USERPIC / BIOPHOTO',
  kunci VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'pembeda dalam satu jenis: nomor jari, Type-No-Index biodata, dst',
  tipe_bio TINYINT DEFAULT NULL COMMENT 'Type BIODATA: 1=jari, 2=wajah, 7=vena jari, 8=palm, 9=wajah visible light',
  data MEDIUMTEXT NOT NULL COMMENT 'field rekaman (JSON), nama field baku Push SDK',
  diperbarui DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rekaman (sn, pin, jenis, kunci),
  KEY idx_sn_jenis (sn, jenis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
