-- ============================================================================
--  DATABASE ABSENSI SEKOLAH  (Koneksi 1 dari konsep 2 koneksi database)
-- ----------------------------------------------------------------------------
--  Database ini HANYA menyimpan data absensi & konfigurasi aplikasi.
--
--  Data master siswa, guru, jabatan, dan kelas TIDAK disimpan di sini.
--  Semuanya dibaca langsung (live) dari database "datacenter_v2" melalui
--  koneksi kedua ($dc) yang didefinisikan di config.php:
--     - siswa   -> datacenter_v2.siswa
--     - guru    -> datacenter_v2.guru
--     - jabatan -> datacenter_v2.guru.jabatan (kolom teks)
--     - kelas   -> datacenter_v2.rombongan_belajar (tahun ajaran aktif)
--
--  Tabel absensi merujuk data milik datacenter:
--     absensi_siswa.nis          -> datacenter_v2.siswa.nis (fallback nisn)
--     absensi_guru.nip           -> datacenter_v2.guru.nip
--     jadwal_shift_guru.nip      -> datacenter_v2.guru.nip
--     jadwal_shift_kelas.rombel_id -> datacenter_v2.rombongan_belajar.id
--  (Tidak ada FOREIGN KEY lintas-database karena master berada di DB lain.)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS absensi_sekolah CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE absensi_sekolah;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  nama VARCHAR(100) NOT NULL
);

-- Jadwal jam absensi PER HARI (per tipe siswa/guru per hari 1=Senin..7=Minggu).
-- libur=1 menandai hari tanpa jam sekolah (mis. akhir pekan) -> di laporan jadi "Libur".
CREATE TABLE jadwal_absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipe ENUM('siswa','guru') NOT NULL,
  hari TINYINT NOT NULL COMMENT '1=Senin .. 7=Minggu',
  jam_masuk TIME DEFAULT NULL,
  batas_terlambat TIME DEFAULT NULL,
  jam_pulang TIME DEFAULT NULL,
  libur TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=tidak ada jam sekolah (libur)',
  UNIQUE KEY uk_tipe_hari (tipe, hari)
);

-- Mesin dikenali lewat serial_number (identitasnya di ADMS). Alamat IP tidak
-- disimpan karena pada ADMS mesin yang menghubungi server, bukan sebaliknya.
CREATE TABLE mesin_absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  serial_number VARCHAR(50) DEFAULT NULL,
  tipe VARCHAR(50) DEFAULT 'Fingerprint',
  lokasi VARCHAR(100) DEFAULT NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  last_online DATETIME DEFAULT NULL COMMENT 'waktu terakhir mesin menghubungi server',
  tarik_data DATETIME DEFAULT NULL COMMENT 'diminta kirim ulang seluruh data user & biometrik'
);

CREATE TABLE upload_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mesin_id INT NOT NULL,
  jumlah_guru INT NOT NULL DEFAULT 0,
  jumlah_siswa INT NOT NULL DEFAULT 0,
  keterangan VARCHAR(200) DEFAULT NULL,
  waktu DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mesin_id) REFERENCES mesin_absensi(id)
);

-- Kartu RFID (reader USB di halaman Layar Tap Kartu) -> siswa/guru.
-- nomor_induk merujuk datacenter_v2 (NIS siswa / NIP guru), tanpa FK lintas-database.
CREATE TABLE kartu_rfid (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(32) NOT NULL COMMENT 'nomor kartu hasil normalisasi kartuNormal()',
  tipe ENUM('siswa','guru') NOT NULL,
  nomor_induk VARCHAR(30) NOT NULL COMMENT 'NIS (siswa) / NIP (guru)',
  dibuat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_uid (uid),
  UNIQUE KEY uk_orang (tipe, nomor_induk)
);

CREATE TABLE hari_libur (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tanggal DATE NOT NULL,
  tanggal_selesai DATE DEFAULT NULL,
  keterangan VARCHAR(200) NOT NULL,
  jenis ENUM('sekolah','nasional') NOT NULL DEFAULT 'sekolah'
);

-- Shift MENIMPA jadwal_absensi pada hari yang diberi shift; batas_terlambat
-- pada shift itulah yang dipakai laporan untuk menentukan status terlambat.
CREATE TABLE shift (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(50) NOT NULL,
  jam_masuk TIME NOT NULL,
  batas_terlambat TIME NOT NULL,
  jam_pulang TIME NOT NULL
);

-- Shift SISWA ditetapkan per KELAS (rombel), bukan per siswa.
-- rombel_id sudah spesifik per tahun ajaran, jadi tiap TA punya penetapannya sendiri.
CREATE TABLE jadwal_shift_kelas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rombel_id INT NOT NULL COMMENT 'ref datacenter_v2.rombongan_belajar.id',
  hari TINYINT NOT NULL COMMENT '1=Senin .. 7=Minggu',
  shift_id INT NOT NULL,
  UNIQUE KEY uk_rombel_hari (rombel_id, hari),
  FOREIGN KEY (shift_id) REFERENCES shift(id)
);

-- nip merujuk datacenter_v2.guru.nip (tanpa FK lintas-database)
CREATE TABLE jadwal_shift_guru (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nip VARCHAR(30) NOT NULL COMMENT 'ref datacenter_v2.guru.nip',
  hari TINYINT NOT NULL COMMENT '1=Senin .. 7=Minggu',
  shift_id INT NOT NULL,
  UNIQUE KEY uk_nip_hari (nip, hari),
  FOREIGN KEY (shift_id) REFERENCES shift(id)
);

-- ----------------------------------------------------------------------------
--  Kedua tabel absensi berbentuk LOG EVENT: satu baris = satu kejadian.
--    status: 0=masuk, 1=pulang, 2=sakit, 3=ijin, 4=alpha, 5=dinas luar, 6=cuti
--    kode 0/1 mengisi kolom jam (jam scan); kode 2-6 boleh tanpa jam.
--    kode 5 & 6 dipakai untuk guru; koreksi siswa hanya menawarkan kode 2-4.
--    "hadir"/"terlambat" tidak disimpan -> dihitung dari jam masuk terhadap
--    batas_terlambat pada jadwal_absensi hari ybs.
--  Kunci orang memakai nomor induk milik datacenter (tanpa FK lintas-database).
-- ----------------------------------------------------------------------------
CREATE TABLE absensi_siswa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nis VARCHAR(30) NOT NULL COMMENT 'ref datacenter_v2.siswa.nis (fallback nisn)',
  tanggal DATE NOT NULL,
  jam TIME DEFAULT NULL,
  status TINYINT NOT NULL COMMENT '0=masuk,1=pulang,2=sakit,3=ijin,4=alpha,5=dinas luar,6=cuti',
  keterangan VARCHAR(200) DEFAULT NULL,
  UNIQUE KEY uk_nis_tgl_status (nis, tanggal, status),
  KEY idx_tanggal (tanggal)
);

CREATE TABLE absensi_guru (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nip VARCHAR(30) NOT NULL COMMENT 'ref datacenter_v2.guru.nip',
  tanggal DATE NOT NULL,
  jam TIME DEFAULT NULL,
  status TINYINT NOT NULL COMMENT '0=masuk,1=pulang,2=sakit,3=ijin,4=alpha,5=dinas luar,6=cuti',
  keterangan VARCHAR(200) DEFAULT NULL,
  UNIQUE KEY uk_nip_tgl_status (nip, tanggal, status),
  KEY idx_tanggal (tanggal)
);

-- ============ SEED DATA (konfigurasi aplikasi saja) ============
-- Tidak ada seed siswa/guru/jabatan/kelas — data master berasal dari datacenter.

-- password: admin123
INSERT INTO users (username, password, nama) VALUES
('admin', '$2y$10$D0GgYQqrXCuXMOB9K2M5..jJifJdBUWmZoQk1nR6TW0v3Cw6y3lVu', 'Administrator');

-- Senin-Jumat (1-5) = hari sekolah; Sabtu & Minggu (6-7) = libur (bisa diubah di Setting Jadwal)
INSERT INTO jadwal_absensi (tipe, hari, jam_masuk, batas_terlambat, jam_pulang, libur) VALUES
('siswa',1,'06:30:00','07:00:00','14:00:00',0),
('siswa',2,'06:30:00','07:00:00','14:00:00',0),
('siswa',3,'06:30:00','07:00:00','14:00:00',0),
('siswa',4,'06:30:00','07:00:00','14:00:00',0),
('siswa',5,'06:30:00','07:00:00','14:00:00',0),
('siswa',6,NULL,NULL,NULL,1),
('siswa',7,NULL,NULL,NULL,1),
('guru',1,'06:30:00','07:00:00','15:00:00',0),
('guru',2,'06:30:00','07:00:00','15:00:00',0),
('guru',3,'06:30:00','07:00:00','15:00:00',0),
('guru',4,'06:30:00','07:00:00','15:00:00',0),
('guru',5,'06:30:00','07:00:00','15:00:00',0),
('guru',6,NULL,NULL,NULL,1),
('guru',7,NULL,NULL,NULL,1);

INSERT INTO mesin_absensi (nama, serial_number, tipe, lokasi, aktif) VALUES
('Mesin Gerbang Utama','ZK-A1B2C3D4','Fingerprint','Gerbang Utama',1),
('Mesin Ruang Guru','ZK-E5F6G7H8','Fingerprint + Kartu','Ruang Guru',1);

INSERT INTO hari_libur (tanggal, tanggal_selesai, keterangan, jenis) VALUES
('2026-08-17', NULL, 'Hari Kemerdekaan RI', 'nasional'),
('2026-12-25', NULL, 'Hari Raya Natal', 'nasional'),
('2026-06-22', '2026-07-12', 'Libur Kenaikan Kelas', 'sekolah');

INSERT INTO shift (nama, jam_masuk, batas_terlambat, jam_pulang) VALUES
('Pagi','06:30:00','07:00:00','14:00:00'),
('Siang','12:00:00','12:30:00','17:00:00'),
('Full','06:30:00','07:00:00','16:00:00');
