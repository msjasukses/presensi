-- ============================================================================
--  SALIN USER + BIOMETRIK (sidik jari, wajah, palm) ANTAR MESIN
-- ----------------------------------------------------------------------------
--  1. adms_data_user : salinan data user & template biometrik yang dikirim
--     mesin lewat ADMS (OPERLOG / BIODATA / querydata). Satu baris = satu
--     rekaman: data user, satu template jari, satu template wajah, dst.
--     Isinya dipakai untuk menyusun perintah DATA UPDATE ke mesin lain.
--  2. adms_perintah.perintah diperbesar: satu template berukuran 1–30 KB,
--     tidak muat di VARCHAR(500).
--  3. mesin_absensi.tarik_data: penanda "minta mesin mengirim ulang seluruh
--     data user". Dibaca & dikosongkan saat handshake berikutnya.
-- ============================================================================
USE absensi_sekolah;

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

ALTER TABLE adms_perintah
  MODIFY perintah MEDIUMTEXT NOT NULL COMMENT 'isi perintah tanpa awalan C:<id>:';

ALTER TABLE mesin_absensi
  ADD COLUMN tarik_data DATETIME DEFAULT NULL COMMENT 'diminta kirim ulang seluruh data user & biometrik';
