-- ============================================================================
--  ABSENSI KARTU RFID
-- ----------------------------------------------------------------------------
--  kartu_rfid: pemetaan nomor kartu (UID yang diketik reader RFID USB) ke
--  siswa/guru. Satu orang satu kartu; satu kartu hanya milik satu orang.
--  Setiap tap dicatat juga di adms_scan dengan sn = 'KARTU' (jejak audit,
--  termasuk kartu yang belum terdaftar supaya mudah didaftarkan).
-- ============================================================================
USE absensi_sekolah;

CREATE TABLE IF NOT EXISTS kartu_rfid (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(32) NOT NULL COMMENT 'nomor kartu hasil normalisasi kartuNormal()',
  tipe ENUM('siswa','guru') NOT NULL,
  nomor_induk VARCHAR(30) NOT NULL COMMENT 'NIS (siswa) / NIP (guru)',
  dibuat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_uid (uid),
  UNIQUE KEY uk_orang (tipe, nomor_induk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
