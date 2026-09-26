-- ============================================================================
--  SHIFT SISWA + shift yang benar-benar dipakai perhitungan absensi
-- ----------------------------------------------------------------------------
--  1. Tabel shift ditambah kolom batas_terlambat, supaya tiap shift punya
--     toleransi keterlambatannya sendiri (sebelumnya hanya jam masuk & pulang,
--     sehingga status "terlambat" tidak bisa dihitung dari shift).
--  2. Tabel jadwal_shift_kelas: penetapan shift untuk SISWA dilakukan per
--     KELAS (rombongan belajar) per hari — bukan per siswa — karena di sekolah
--     shift pagi/siang memang berlaku serombel.
--     rombel_id sudah spesifik per tahun ajaran, jadi tiap tahun ajaran punya
--     penetapan shiftnya sendiri.
-- ============================================================================
USE absensi_sekolah;

ALTER TABLE shift
  ADD COLUMN batas_terlambat TIME NOT NULL DEFAULT '00:00:00' AFTER jam_masuk;

-- Nilai awal: 30 menit setelah jam masuk (bisa diubah di halaman setting)
UPDATE shift SET batas_terlambat = ADDTIME(jam_masuk, '00:30:00')
WHERE batas_terlambat = '00:00:00';

CREATE TABLE IF NOT EXISTS jadwal_shift_kelas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rombel_id INT NOT NULL COMMENT 'ref datacenter_v2.rombongan_belajar.id (sudah per tahun ajaran)',
  hari TINYINT NOT NULL COMMENT '1=Senin .. 7=Minggu',
  shift_id INT NOT NULL,
  UNIQUE KEY uk_rombel_hari (rombel_id, hari),
  FOREIGN KEY (shift_id) REFERENCES shift(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
