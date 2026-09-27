# Aplikasi Absensi Sekolah

Aplikasi absensi guru & siswa berbasis PHP 8 + MySQL + Bootstrap 5 + Chart.js.

## Konsep 2 Koneksi Database

Aplikasi memakai **dua koneksi database** (didefinisikan di `config.php`):

1. **`$pdo` → `absensi_sekolah`** — menyimpan data **absensi & konfigurasi** (absensi_siswa, absensi_guru, jadwal, mesin, shift, hari libur, users).
2. **`$dc` → `datacenter_v2`** — sumber data **master** yang dibaca langsung/live (tanpa disalin):
   - siswa → `siswa`
   - guru → `guru`
   - jabatan → kolom teks `guru.jabatan`
   - kelas → `rombongan_belajar` (tahun ajaran aktif)

Seluruh tabel di database absensi merujuk data datacenter lewat **nomor induk**, bukan id:

- `absensi_siswa.nis` → `datacenter_v2.siswa.nis` (fallback NISN)
- `absensi_guru.nip` → `datacenter_v2.guru.nip`
- `jadwal_shift_guru.nip` → `datacenter_v2.guru.nip`

Jadi data siswa/guru/jabatan/kelas selalu mengikuti datacenter tanpa perlu sinkronisasi.

## Struktur Tabel Absensi (log event)

`absensi_siswa` dan `absensi_guru` memakai **struktur yang sama** — satu baris = satu kejadian:

| Kolom | Keterangan |
|---|---|
| `id` | primary key |
| `nis` / `nip` | nomor induk siswa (fallback NISN) atau guru, merujuk datacenter |
| `tanggal` | tanggal kejadian |
| `jam` | jam scan (diisi untuk kode 0 & 1, boleh NULL untuk kode 2–6) |
| `status` | kode status (lihat tabel di bawah) |
| `keterangan` | catatan bebas |

### Kode status

| Kode | Arti | Kolom `jam` |
|---|---|---|
| 0 | masuk | jam scan masuk |
| 1 | pulang | jam scan pulang |
| 2 | sakit | NULL |
| 3 | ijin | NULL |
| 4 | alpha (tidak hadir) | NULL |
| 5 | dinas luar | NULL |
| 6 | cuti | NULL |

Satu orang pada satu tanggal bisa punya beberapa baris, misalnya masuk (kode 0, jam 06:45) dan pulang (kode 1, jam 14:05). Ketidakhadiran cukup satu baris (kode 2–6).

**Kode 5 (dinas luar) dan 6 (cuti)** berlaku untuk guru; dropdown koreksi siswa hanya menawarkan kode 2–4. Struktur tabelnya tetap sama sehingga kode ini bisa dipakai untuk siswa bila nanti diperlukan — cukup ubah `kodeKetidakhadiran()` di `config.php`.

Status **hadir** dan **terlambat** tidak disimpan di database — keduanya dihitung otomatis dari jam masuk dibandingkan `batas_terlambat` pada Setting Jadwal hari yang bersangkutan. Hari yang tidak ada catatannya dihitung **Tidak Hadir** bila hari sekolah/kerja, atau **Libur** bila jatuh pada hari libur/akhir pekan.

Pada dashboard, guru berstatus **dinas luar dihitung hadir** (tetap bertugas), sedangkan **cuti tidak**.

Migrasi dari struktur lama ada di `migrasi_absensi_siswa.sql`, `migrasi_absensi_guru.sql`, dan `migrasi_jadwal_shift_guru.sql`.

## Endpoint ADMS (Push SDK ZKTeco)

Mesin absensi **menghubungi server** lewat HTTP (bukan server yang menarik data ke port 4370), sehingga mesin di lokasi mana pun bisa mengirim data tanpa VPN atau IP publik di sisi sekolah.

Endpoint ada di folder `iclock/` (satu front controller `iclock/index.php` + `.htaccess`):

| Endpoint | Fungsi |
|---|---|
| `GET /iclock/cdata?SN=…&options=all` | handshake, server balas konfigurasi pengiriman |
| `POST /iclock/cdata?SN=…&table=ATTLOG` | mesin kirim data absensi → dicatat ke tabel absensi |
| `POST /iclock/cdata?SN=…&table=OPERLOG` | log operasi (diterima, belum diproses) |
| `GET /iclock/getrequest?SN=…` | mesin ambil perintah dari antrean `adms_perintah` |
| `POST /iclock/devicecmd?SN=…` | mesin lapor hasil perintah |
| `GET /iclock/ping` | cek hidup |

### Setelan di mesin

Masuk ke menu **Comm → Cloud Server Setting / ADMS**, isi alamat server dan port (mis. domain `absensi.sekolah.sch.id` port `80`), aktifkan *Enable Domain Name* bila memakai domain. **Aplikasi harus berada di root domain** — sebagian firmware tidak menerima path awalan seperti `/absensi/iclock/…`.

### Cara data mesin masuk ke laporan

1. PIN pada mesin dipetakan ke orang: tabel `mesin_pin` dulu (pemetaan manual), lalu NIS/NISN siswa, lalu NIP guru.
2. **Status yang dikirim mesin tidak dipakai** — urutan waktu yang menentukan. Selama jam masuk tanggal itu belum terisi, scan dicatat sebagai **jam masuk**, apa pun statusnya. Setelah jam masuk terisi, scan berikutnya dicatat sebagai **jam pulang** (bila berkali-kali, scan paling akhir yang dipakai).
3. Pengaman: scan yang terlambat dikirim tetapi jamnya lebih awal dari jam masuk tercatat menjadi jam masuk baru, dan kiriman ulang scan yang sama persis diabaikan. Punch state asli tetap tersimpan mentah di `adms_scan` untuk audit.
4. Semua kiriman disimpan mentah di `adms_scan` sebagai jejak audit, jadi bisa diproses ulang bila pemetaan PIN diperbaiki.

Pantau lewat menu **Setting Absensi → Monitor ADMS**: status online mesin, data scan per tanggal, PIN yang tidak dikenal (dengan tombol *Proses Ulang*), dan riwayat komunikasi mesin.

### Upload data guru/siswa ke mesin

Menu **Setting Absensi → Mesin Absensi & Upload** mengirim data memakai konsep ADMS: aplikasi **tidak** menghubungi mesin. Setiap orang diubah menjadi perintah `DATA UPDATE USERINFO` di tabel `adms_perintah`, lalu mesin mengambilnya sendiri lewat `/iclock/getrequest` dan melapor hasilnya lewat `/iclock/devicecmd`.

Alurnya: **Antre** (menunggu diambil mesin) → **Terkirim** (sudah diambil) → **Selesai** (mesin melapor berhasil). Statusnya terlihat di panel *Antrean Perintah ADMS*, lengkap dengan tombol membatalkan perintah yang belum diambil.

PIN dialokasikan otomatis dan disimpan di `mesin_pin` supaya scan yang kembali bisa dipetakan:

- Nomor induk dipakai apa adanya bila muat (maksimal 9 digit angka). NIS `000181` menjadi PIN `181` — nol di depan dibuang karena mesin biasanya membuangnya juga.
- NIP 18 digit tidak muat, jadi diberi PIN dari blok **900001** ke atas.
- PIN yang sudah dialokasikan untuk seseorang tidak pernah berubah, jadi upload ulang aman.

Mesin tujuan **wajib punya serial number** — itulah identitas mesin di ADMS.

### Cek koneksi mesin

Tombol cek pada daftar mesin mengirim perintah `CHECK` lewat antrean ADMS, lalu mesin mengambil dan melaporkannya. Kolom **Koneksi** menandai mesin yang menghubungi server dalam 5 menit terakhir (`last_online` diperbarui otomatis setiap mesin memanggil endpoint `/iclock/…`).

Cara ini menggantikan pengecekan soket TCP ke port 4370: pada ADMS mesin yang menghubungi server, sehingga mesin di balik NAT tidak bisa dijangkau dari sisi server walaupun kondisinya sehat.

Karena itu **alamat IP dan port mesin tidak lagi disimpan** — mesin dikenali sepenuhnya lewat serial number. Kolom `ip` dan `port` dihapus lewat `migrasi_hapus_ip_mesin.sql`.

### Hapus user dari mesin

Panel **Hapus User dari Mesin** di halaman Setting Mesin mengantrekan perintah `DATA DELETE` lewat ADMS. User dipilih dari daftar user mesin (hasil *Tarik Data*), per rombel / tingkat / jabatan, atau dengan mengetik PIN. Yang dihapus bisa user beserta seluruh biometrik & kartunya, atau biometriknya saja (semua / sidik jari / wajah) supaya bisa didaftarkan ulang. PIN orang dicari lewat `mesin_pin` tanpa mengalokasikan PIN baru. Perintah upload yang masih antre untuk PIN tersebut dibuang. Riwayat absensi di aplikasi dan pemetaan `mesin_pin` tidak ikut terhapus.

### Absensi kartu RFID

Menu **Absensi Kartu RFID** (butuh `migrasi_kartu_rfid.sql`), memakai reader RFID USB mode keyboard (mengetik nomor kartu lalu Enter):

- **Daftar Kartu RFID** — pasangkan kartu ke siswa/guru: per orang, atau per kelas secara berurutan (tempel kartu → otomatis lanjut ke siswa berikutnya). Kartu yang ditempel di Layar Tap tapi belum terdaftar ikut tampil agar mudah dipasangkan. Satu orang satu kartu; nomor desimal dibuang nol depannya.
- **Layar Tap Kartu** — halaman layar penuh untuk komputer di gerbang. Setiap tap menampilkan nama, kelas, status (masuk / terlambat / pulang / sudah absen) dan mengucapkan namanya dengan suara Bahasa Indonesia (Web Speech API; gunakan Chrome/Edge). Aturan masuk/pulang sama dengan mesin (`admsTulisAbsensi`); tap ulang dalam `KARTU_JEDA_MENIT` (10 menit) tidak ditulis. Tiap tap juga dicatat di `adms_scan` dengan `sn = 'KARTU'`. Opsional: ikut menampilkan & mengumumkan absen dari mesin sidik jari/wajah.

### Salin user + sidik jari / wajah / palm antar mesin

Panel **Salin User + Sidik Jari / Wajah / Palm Antar Mesin** di halaman Setting Mesin (butuh `migrasi_salin_user_mesin.sql`):

1. **Tarik Data** pada mesin sumber — server menandai `tarik_data` dan mengantrekan `CHECK`. Pada handshake berikutnya server membalas `OpStamp=0` / `BIODATAStamp=0`, sehingga mesin mengirim ulang seluruh data user & template (`USER`, `FP`, `FACE`, `BIODATA`, `USERPIC`, `BIOPHOTO`; firmware Push 3.x lewat `querydata`). Semuanya disimpan di `adms_data_user`. Stamp absensi tidak direset, jadi ATTLOG lama tidak dikirim ulang.
2. **Salin** — tiap rekaman dijadikan perintah `DATA UPDATE USERINFO / FINGERTMP / FACE / BIODATA / …` di antrean mesin tujuan. User didaftarkan lebih dulu sebelum templatenya. PIN tetap sama, jadi pemetaan `mesin_pin` berlaku di kedua mesin.

Template hanya bisa dipakai bila algoritma kedua mesin sama (sidik jari ZKFinger v10 ≠ v12; wajah & palm umumnya hanya cocok antar seri yang sama). Satu balasan `getrequest` dibatasi ±32 KB agar buffer mesin tidak meluap, sehingga penyalinan ratusan user berlangsung bertahap.

### Catatan penting

- **NIP 18 digit tidak muat** di PIN mesin ZKTeco (umumnya maksimal 9–14 digit). Untuk guru, daftarkan PIN pendek di tabel `mesin_pin` yang menunjuk ke NIP. NIS siswa yang pendek bisa dipakai langsung sebagai PIN.
- Mesin menghapus data lokalnya setelah menerima balasan `OK`. Karena itu, data dari SN yang belum terdaftar **tetap disimpan** (ditandai di Monitor ADMS) agar tidak hilang. Untuk menolak mesin asing, ubah `ADMS_SN_KETAT` menjadi `true` di `config.php`.
- Protokol ADMS berjalan di HTTP polos tanpa autentikasi selain serial number — jangan ekspos ke internet tanpa pembatasan IP di firewall/reverse proxy.
- Bila pada satu tanggal sudah ada catatan manual sakit/ijin/dinas luar/cuti, status itu tetap dipakai di laporan meskipun ada scan masuk. Scan-nya tetap terlihat di Monitor ADMS untuk dikoreksi admin.

Tabel pendukung dibuat lewat `adms.sql`.

## Memindahkan ke Server Lain (Virtualmin, cPanel, VPS)

Aplikasi dirancang agar pindah server tidak perlu menyunting kode. Tidak ada jalur folder, alamat IP, maupun nama domain yang ditulis di dalam kode PHP.

**Langkah pindah:**

1. Salin seluruh folder aplikasi (`vendor/` sudah ikut, jadi tidak perlu menjalankan `composer install`).
2. Import `database.sql` dan `adms.sql` ke database di server baru. Pastikan database datacenter juga tersedia.
3. Salin `config.local.example.php` menjadi **`config.local.php`**, isi kredensial database server tersebut.
4. Buka menu **Setting Absensi → Mesin Absensi & Upload**. Di bagian atas ada kotak **Alamat Server untuk Mesin Absensi** yang terisi otomatis sesuai server yang sedang dibuka.
5. Salin alamat itu ke menu **Comm → Cloud Server Setting / ADMS** pada mesin.

Hanya langkah 3 yang perlu diketik manual; sisanya menyesuaikan sendiri.

**Kenapa portabel:**

- Kredensial database dibaca berurutan dari `config.local.php` → variabel lingkungan → nilai bawaan. Berkas `config.local.php` diabaikan git sehingga setelan tiap server tidak saling menimpa saat kode diperbarui.
- Alamat ADMS dideteksi dari permintaan yang sedang berjalan, sehingga otomatis benar baik saat aplikasi berada di akar domain (`https://absensi.sekolah.sch.id/adms/index.php`), di dalam subfolder (`http://server/absensi/adms/index.php`), maupun diakses lewat IP. Status HTTPS juga terbaca sendiri.
- Endpoint utama `/adms/index.php` adalah berkas nyata, jadi **berfungsi di Apache maupun nginx tanpa aturan rewrite apa pun**. Inilah alamat yang sebaiknya dipakai saat berpindah-pindah server.

**Konfigurasi web server (opsional).** Jalur `/adms/index.php` tidak memerlukan apa-apa. Jalur alternatif `/iclock/...` butuh pengalihan: di Apache sudah disertakan `iclock/.htaccess`, sedangkan di nginx tambahkan blok berikut sebelum `location /`:

```nginx
location /iclock { try_files $uri /iclock/index.php$is_args$args; }
location /adms   { try_files $uri /adms/index.php$is_args$args; }
```

**Catatan HTTPS.** Sebagian firmware mesin hanya mau berbicara HTTPS dan tidak punya opsi untuk mematikannya. Di hosting seperti Virtualmin hal ini justru menguntungkan karena sertifikat Let's Encrypt tersedia otomatis — cukup isikan alamat `https://...` ke mesin.

## Cara Menjalankan

1. Pastikan Laragon (Apache/Nginx + MySQL) berjalan.
2. Database `absensi_sekolah` sudah dibuat (atau import ulang: `mysql -uroot < database.sql`).
3. Pastikan database **`datacenter_v2`** tersedia di server MySQL yang sama dan punya **tahun ajaran aktif** (`tahun_ajaran.is_aktif=1`). Sesuaikan koneksi di `config.php` bila nama database berbeda.
4. Buka `http://absensi.test` atau `http://localhost/absensi`.
5. Login: **admin / admin123**

## Modul

1. **Dashboard** — total siswa/guru, hadir, tidak hadir, terlambat + grafik 7 hari (hadir, terlambat, izin, sakit, tidak hadir).
2. **Setting Absensi**
   - Jadwal jam absensi guru & siswa (jam masuk, batas terlambat, jam pulang)
   - Mesin absensi (CRUD) & upload data guru/siswa ke mesin (dengan riwayat upload)
   - Hari libur sekolah & nasional (mendukung rentang tanggal)
   - Jadwal shift guru (CRUD shift + penjadwalan per guru per hari)
3. **Info Absensi Guru** — per guru & per jabatan, filter periode tanggal.
4. **Info Absensi Siswa** — per siswa & per kelas, filter periode tanggal.
5. **Export Laporan** — tombol Export Excel (.xls) dan Export PDF (dompdf) tersedia di keempat halaman info absensi (per guru, per jabatan, per siswa, per kelas) mengikuti filter periode yang dipilih. Logika export ada di `export.php`.
6. **Koreksi Absensi** — koreksi/input manual per tanggal untuk siswa (filter kelas) dan guru. Pilih *Hadir* lalu isi jam masuk/pulang (tersimpan sebagai kode 0 & 1), atau pilih kode ketidakhadiran (siswa: Sakit/Ijin/Alpha; guru: ditambah Dinas Luar/Cuti) yang tersimpan sebagai satu baris kode 2–6.

## Catatan

- Tombol "Upload Data" ke mesin saat ini bersifat simulasi (mencatat log upload). Untuk koneksi nyata ke mesin ZKTeco/fingerprint, integrasikan library seperti `php-zklib` pada `setting_mesin.php` (action `upload`).
- Data master siswa/guru/jabatan/kelas **tidak** di-seed di sini — semuanya dibaca langsung dari `datacenter_v2`. `database.sql` hanya berisi tabel absensi & konfigurasi.
- Roster siswa yang tampil = siswa aktif yang **sudah ditempatkan di kelas (rombel) pada tahun ajaran aktif** di datacenter. Siswa yang belum punya rombel di TA aktif akan otomatis muncul setelah ditempatkan di datacenter.
- Konfigurasi kedua koneksi database ada di `config.php`.
