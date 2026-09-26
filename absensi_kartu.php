<?php
/*
 * Layar Tap Kartu RFID (mode kiosk)
 * ----------------------------------------------------------------------------
 * Dibuka di komputer/laptop dekat gerbang yang tersambung reader RFID USB.
 * Reader bekerja seperti keyboard: mengetik nomor kartu lalu Enter. Halaman
 * mengirim nomor itu ke server, lalu menampilkan nama pemilik kartu dan
 * mengucapkannya (Web Speech API, suara Bahasa Indonesia).
 *
 *   POST aksi=tap  uid=..      -> JSON hasil kartuTap()
 *   GET  aksi=feed sejak=ID    -> scan terbaru dari mesin sidik jari (opsional)
 *   GET  aksi=ping             -> menjaga sesi login tetap hidup
 */
require_once __DIR__ . '/config.php';

$aksi = $_REQUEST['aksi'] ?? '';
if ($aksi !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'kode' => 'sesi', 'pesan' => 'Sesi login habis.']);
        exit;
    }
    try {
        if ($aksi === 'tap' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            echo json_encode(kartuTap($pdo, $dc, (string)($_POST['uid'] ?? '')));
        } elseif ($aksi === 'feed') {
            // Scan dari mesin (bukan dari layar ini). Panggilan pertama (sejak=0)
            // hanya mengembalikan id terakhir supaya scan lama tidak diumumkan.
            $sejak = (int)($_GET['sejak'] ?? 0);
            if ($sejak <= 0) {
                echo json_encode(['sejak' => (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM adms_scan')->fetchColumn(), 'scan' => []]);
                exit;
            }
            $st = $pdo->prepare("SELECT id, waktu, tipe, nomor_induk FROM adms_scan
                                 WHERE id > ? AND sn <> 'KARTU' AND tipe IS NOT NULL
                                   AND waktu >= NOW() - INTERVAL 3 MINUTE
                                 ORDER BY id LIMIT 20");
            $st->execute([$sejak]);
            $scan = [];
            $maxId = $sejak;
            foreach ($st as $r) {
                $maxId = max($maxId, (int)$r['id']);
                $o = kartuInfoOrang($dc, $r['tipe'], $r['nomor_induk']);
                if (!$o) continue;
                [$tabel, $kol] = absTabel($r['tipe']);
                $m = $pdo->prepare("SELECT jam FROM $tabel WHERE $kol=? AND tanggal=? AND status=?");
                $m->execute([$r['nomor_induk'], substr($r['waktu'], 0, 10), ABS_MASUK]);
                $jamMasuk = $m->fetchColumn() ?: null;
                $jam = substr($r['waktu'], 11, 8);
                $scan[] = ['ok' => true, 'jenis' => $jamMasuk === $jam ? 'masuk' : 'pulang', 'jam' => $jam,
                           'jam_masuk' => $jamMasuk, 'terlambat' => false, 'mesin' => true] + $o;
            }
            // Lewati juga id yang tidak lolos filter supaya tidak dibaca ulang
            $maxAll = $pdo->prepare('SELECT COALESCE(MAX(id), ?) FROM adms_scan WHERE id > ?');
            $maxAll->execute([$maxId, $sejak]);
            echo json_encode(['sejak' => count($scan) < 20 ? max($maxId, (int)$maxAll->fetchColumn()) : $maxId, 'scan' => $scan]);
        } elseif ($aksi === 'ping') {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'pesan' => 'Aksi tidak dikenal.']);
        }
    } catch (Throwable $ex) {
        error_log('Tap kartu gagal: ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'kode' => 'galat', 'pesan' => 'Terjadi kesalahan server.']);
    }
    exit;
}

requireLogin();
$jumlahKartu = (int)$pdo->query('SELECT COUNT(*) FROM kartu_rfid')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Layar Tap Kartu - Absensi Sekolah</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --bg1:#0b1f3a; --bg2:#12355f; --masuk:#16a34a; --pulang:#2563eb; --telat:#f59e0b; --ulang:#64748b; --galat:#dc2626; }
html, body { height:100%; }
body { margin:0; color:#fff; background:radial-gradient(circle at 20% 10%, var(--bg2), var(--bg1) 70%); font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; overflow:hidden; }
.bar { display:flex; align-items:center; gap:.75rem; padding:1rem 1.5rem; }
.bar .judul { font-weight:700; font-size:1.25rem; }
.bar .jam { margin-left:auto; text-align:right; line-height:1.1; }
.bar .jam b { font-size:2rem; font-variant-numeric:tabular-nums; }
.bar .btn { --bs-btn-color:#cbd5e1; --bs-btn-border-color:rgba(255,255,255,.2); --bs-btn-hover-bg:rgba(255,255,255,.1); --bs-btn-hover-color:#fff; }
.utama { display:grid; grid-template-columns:1fr 340px; gap:1.5rem; padding:0 1.5rem 1.5rem; height:calc(100% - 84px); }
.panggung { position:relative; border-radius:1.5rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08);
  display:flex; align-items:center; justify-content:center; text-align:center; overflow:hidden; }
.idle i { font-size:7rem; color:#93c5fd; animation:denyut 2s ease-in-out infinite; display:inline-block; }
.idle h1 { font-size:2.6rem; font-weight:700; margin-top:1rem; }
.idle p { color:#cbd5e1; font-size:1.2rem; }
@keyframes denyut { 0%,100% { transform:scale(1); opacity:.85 } 50% { transform:scale(1.08); opacity:1 } }
.hasil { display:none; padding:2rem; width:100%; animation:muncul .35s ease-out; }
@keyframes muncul { from { transform:scale(.92); opacity:0 } to { transform:scale(1); opacity:1 } }
.avatar { width:170px; height:170px; border-radius:50%; margin:0 auto 1.25rem; display:flex; align-items:center; justify-content:center;
  font-size:4.5rem; font-weight:700; background:var(--warna); box-shadow:0 0 0 10px rgba(255,255,255,.08), 0 0 60px var(--warna); }
.hasil .nama { font-size:clamp(2.2rem, 5vw, 4.2rem); font-weight:800; line-height:1.1; }
.hasil .kelas { font-size:1.6rem; color:#cbd5e1; margin-top:.35rem; }
.hasil .lencana { display:inline-block; margin-top:1.25rem; padding:.55rem 1.6rem; border-radius:999px; font-size:1.6rem; font-weight:700; background:var(--warna); }
.hasil .waktu { margin-top:.9rem; font-size:1.3rem; color:#e2e8f0; }
.riwayat { border-radius:1.5rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); padding:1rem; overflow:hidden; display:flex; flex-direction:column; }
.riwayat h6 { color:#93c5fd; text-transform:uppercase; letter-spacing:.08em; font-size:.8rem; }
.riwayat ul { list-style:none; padding:0; margin:0; overflow:auto; }
.riwayat li { display:flex; align-items:center; gap:.65rem; padding:.55rem .25rem; border-bottom:1px solid rgba(255,255,255,.07); }
.riwayat .titik { width:38px; height:38px; flex:none; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; background:var(--warna); }
.riwayat .nm { font-weight:600; line-height:1.15; }
.riwayat small { color:#94a3b8; }
.statistik { display:flex; gap:.5rem; margin-bottom:.75rem; }
.statistik div { flex:1; background:rgba(255,255,255,.06); border-radius:.75rem; padding:.5rem; text-align:center; }
.statistik b { display:block; font-size:1.5rem; }
.statistik small { color:#94a3b8; }
#uid { position:absolute; left:-9999px; opacity:0; }
.mulai { position:fixed; inset:0; background:rgba(11,31,58,.94); display:flex; align-items:center; justify-content:center; z-index:10; text-align:center; cursor:pointer; }
.mulai i { font-size:5rem; color:#93c5fd; }
.peringatan { position:absolute; bottom:1rem; left:1rem; right:1rem; font-size:.9rem; }
@media (max-width: 900px) { .utama { grid-template-columns:1fr; } .riwayat { display:none; } }
</style>
</head>
<body>

<div class="bar">
  <i class="bi bi-credit-card-2-front fs-3 text-info"></i>
  <div class="judul">Absensi Kartu</div>
  <a class="btn btn-sm btn-outline-light ms-2" href="index.php" title="Kembali ke dashboard"><i class="bi bi-arrow-left"></i></a>
  <button class="btn btn-sm btn-outline-light" id="btnSuara" title="Suara nyala/mati"><i class="bi bi-volume-up"></i></button>
  <button class="btn btn-sm btn-outline-light" id="btnSetel" title="Pengaturan" data-bs-toggle="modal" data-bs-target="#modalSetel"><i class="bi bi-gear"></i></button>
  <button class="btn btn-sm btn-outline-light" id="btnLayar" title="Layar penuh"><i class="bi bi-arrows-fullscreen"></i></button>
  <div class="jam"><b id="jam">--:--:--</b><br><small id="tanggal"></small></div>
</div>

<div class="utama">
  <div class="panggung" id="panggung">
    <div class="idle" id="idle">
      <i class="bi bi-wifi"></i>
      <h1>Tempelkan Kartu Anda</h1>
      <p>Dekatkan kartu RFID ke reader untuk absen masuk atau pulang</p>
    </div>
    <div class="hasil" id="hasil">
      <div class="avatar" id="hAvatar"></div>
      <div class="nama" id="hNama"></div>
      <div class="kelas" id="hKelas"></div>
      <div class="lencana" id="hLencana"></div>
      <div class="waktu" id="hWaktu"></div>
    </div>
    <?php if (!$jumlahKartu): ?>
      <div class="peringatan alert alert-warning mb-0">Belum ada kartu terdaftar. Daftarkan kartu dulu di menu <a href="kartu_rfid.php">Daftar Kartu RFID</a>.</div>
    <?php endif; ?>
    <div class="peringatan alert alert-warning mb-0 d-none" id="tanpaSuaraId"></div>
  </div>
  <div class="riwayat">
    <div class="statistik">
      <div><b id="nMasuk">0</b><small>Masuk</small></div>
      <div><b id="nPulang">0</b><small>Pulang</small></div>
      <div><b id="nTelat">0</b><small>Terlambat</small></div>
    </div>
    <h6>Tap Terakhir</h6>
    <ul id="daftar"></ul>
  </div>
</div>

<input id="uid" autocomplete="off" inputmode="none" aria-label="Nomor kartu">

<div class="mulai" id="mulai">
  <div>
    <i class="bi bi-play-circle"></i>
    <h2 class="mt-3">Klik di mana saja untuk memulai</h2>
    <p class="text-secondary">Browser mewajibkan satu klik sebelum halaman boleh mengeluarkan suara.</p>
  </div>
</div>

<!-- Pengaturan -->
<div class="modal fade" id="modalSetel" tabindex="-1"><div class="modal-dialog"><div class="modal-content text-dark">
  <div class="modal-header"><h5 class="modal-title">Pengaturan Layar Tap</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-3">
      <label class="form-label">Suara</label>
      <select class="form-select" id="pilSuara"></select>
      <div class="form-text">Pilih suara Bahasa Indonesia (mis. "Google Bahasa Indonesia" di Chrome, atau "Microsoft Gadis/Andika" di Edge).</div>
    </div>
    <div class="mb-3">
      <label class="form-label">Kecepatan bicara: <span id="lblKec"></span></label>
      <input type="range" class="form-range" id="kecepatan" min="0.6" max="1.4" step="0.05">
    </div>
    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" id="ikutMesin">
      <label class="form-check-label" for="ikutMesin">Tampilkan &amp; umumkan juga absen dari mesin sidik jari/wajah</label>
    </div>
    <button class="btn btn-outline-primary" id="tesSuara"><i class="bi bi-megaphone me-1"></i>Tes Suara</button>
    <div class="mt-3">
      <label class="form-label small text-muted">Uji tanpa reader: ketik nomor kartu lalu Enter</label>
      <input class="form-control" id="uidManual" placeholder="Nomor kartu">
    </div>
  </div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const $ = id => document.getElementById(id);
  const simpan = (k, v) => { try { localStorage.setItem('tapKartu.' + k, v); } catch (e) {} };
  const baca = (k, d) => { try { return localStorage.getItem('tapKartu.' + k) ?? d; } catch (e) { return d; } };

  let suaraNyala = baca('suara', '1') === '1';
  let namaSuara  = baca('namaSuara', '');
  let kecepatan  = parseFloat(baca('kecepatan', '0.95'));
  let ikutMesin  = baca('ikutMesin', '0') === '1';
  const hitung = { masuk: new Set(), pulang: new Set(), telat: new Set() };

  // ---------- Jam ----------
  const fmtTgl = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  function detak() {
    const d = new Date();
    $('jam').textContent = d.toLocaleTimeString('id-ID', { hour12: false }).replace(/\./g, ':');
    $('tanggal').textContent = fmtTgl.format(d);
  }
  detak(); setInterval(detak, 1000);

  // ---------- Suara ----------
  const sintesis = window.speechSynthesis;
  function daftarSuara() {
    if (!sintesis) return [];
    return sintesis.getVoices();
  }
  function suaraTerpilih() {
    const semua = daftarSuara();
    return semua.find(v => v.name === namaSuara)
        || semua.find(v => /^id/i.test(v.lang) && /google/i.test(v.name))
        || semua.find(v => /^id/i.test(v.lang))
        || null;
  }
  function isiPilihanSuara() {
    const semua = daftarSuara();
    const pil = $('pilSuara');
    pil.innerHTML = '';
    const id = semua.filter(v => /^id/i.test(v.lang));
    const lain = semua.filter(v => !/^id/i.test(v.lang));
    const aktif = suaraTerpilih();
    [...id, ...lain].forEach(v => {
      const o = document.createElement('option');
      o.value = v.name; o.textContent = `${v.name} (${v.lang})`;
      if (aktif && v.name === aktif.name) o.selected = true;
      pil.appendChild(o);
    });
    const warn = $('tanpaSuaraId');
    if (sintesis && semua.length && !id.length) {
      warn.textContent = 'Suara Bahasa Indonesia tidak ditemukan di browser ini. Gunakan Google Chrome atau Microsoft Edge (butuh internet), atau pasang paket suara Bahasa Indonesia di Windows (Settings → Time & language → Speech).';
      warn.classList.remove('d-none');
    } else if (!sintesis) {
      warn.textContent = 'Browser ini tidak mendukung suara. Gunakan Google Chrome atau Microsoft Edge.';
      warn.classList.remove('d-none');
    } else {
      warn.classList.add('d-none');
    }
  }
  if (sintesis) { isiPilihanSuara(); sintesis.onvoiceschanged = isiPilihanSuara; }

  function ucap(teks) {
    if (!suaraNyala || !sintesis || !teks) return;
    const u = new SpeechSynthesisUtterance(teks);
    const v = suaraTerpilih();
    if (v) u.voice = v;
    u.lang = v ? v.lang : 'id-ID';
    u.rate = kecepatan;
    sintesis.speak(u);          // antre: setiap nama tetap diucapkan walau tap beruntun
  }

  // Bunyi pendek sebagai tanda kartu terbaca (sukses = nada naik, gagal = nada rendah)
  let audio;
  function bip(sukses) {
    if (!suaraNyala) return;
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      const o = audio.createOscillator(), g = audio.createGain();
      o.connect(g); g.connect(audio.destination);
      o.frequency.value = sukses ? 880 : 220;
      if (sukses) o.frequency.setValueAtTime(1320, audio.currentTime + 0.08);
      g.gain.setValueAtTime(0.15, audio.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + (sukses ? 0.2 : 0.4));
      o.start(); o.stop(audio.currentTime + (sukses ? 0.2 : 0.4));
    } catch (e) {}
  }

  // ---------- Kalimat ----------
  const huruf = s => (s || '').toLowerCase().replace(/(^|[\s'.-])(\p{L})/gu, (m, a, b) => a + b.toUpperCase());
  function salam() {
    const j = new Date().getHours();
    return j < 11 ? 'Selamat pagi' : j < 15 ? 'Selamat siang' : j < 18 ? 'Selamat sore' : 'Selamat malam';
  }
  function sapaan(r) {
    if (r.tipe !== 'guru') return '';
    return (r.jk || '').toUpperCase().startsWith('P') ? 'Ibu ' : 'Bapak ';
  }
  function kalimat(r) {
    if (!r.ok) {
      if (r.kode === 'tak_dikenal') return 'Maaf, kartu belum terdaftar.';
      if (r.kode === 'sesi') return 'Sesi login habis. Silakan login ulang.';
      return 'Maaf, terjadi kesalahan.';
    }
    const nama = sapaan(r) + huruf(r.nama);
    if (r.jenis === 'ulang')  return `${nama}, sudah absen.`;
    if (r.jenis === 'pulang') return `Terima kasih ${nama}, hati-hati di jalan.`;
    if (r.terlambat)          return `${salam()}, ${nama}. Anda tercatat terlambat.`;
    return r.tipe === 'guru' ? `${salam()}, ${nama}.` : `${salam()}, ${nama}. Selamat belajar.`;
  }

  // ---------- Tampilan ----------
  const warna = r => !r.ok ? 'var(--galat)' : r.jenis === 'ulang' ? 'var(--ulang)'
                   : r.jenis === 'pulang' ? 'var(--pulang)' : r.terlambat ? 'var(--telat)' : 'var(--masuk)';
  const lencana = r => !r.ok ? (r.kode === 'tak_dikenal' ? 'KARTU TIDAK TERDAFTAR' : 'GAGAL')
                     : r.jenis === 'ulang' ? 'SUDAH ABSEN' : r.jenis === 'pulang' ? 'PULANG'
                     : r.terlambat ? 'MASUK · TERLAMBAT' : 'MASUK';
  const inisial = n => huruf(n).split(/\s+/).filter(Boolean).slice(0, 2).map(s => s[0]).join('') || '?';
  const jam5 = j => (j || '').slice(0, 5);

  let timerIdle;
  function tampil(r) {
    const w = warna(r);
    const hasil = $('hasil');
    hasil.style.setProperty('--warna', w);
    $('hAvatar').innerHTML = r.ok ? inisial(r.nama) : '<i class="bi bi-x-lg"></i>';
    $('hNama').textContent = r.ok ? huruf(r.nama) : (r.pesan || 'Gagal');
    $('hKelas').textContent = r.ok ? [r.tipe === 'guru' ? 'Guru' : 'Siswa', r.kelas].filter(Boolean).join(' · ')
                                   : (r.uid ? 'No. kartu: ' + r.uid : '');
    $('hLencana').textContent = lencana(r);
    $('hWaktu').textContent = r.ok
      ? (r.jenis === 'pulang' ? `Pulang ${jam5(r.jam)} · masuk ${jam5(r.jam_masuk)}` : `Jam masuk ${jam5(r.jam_masuk || r.jam)}`) + (r.mesin ? ' · dari mesin' : '')
      : (r.jam ? 'Pukul ' + jam5(r.jam) : '');
    $('idle').style.display = 'none';
    hasil.style.display = 'none'; void hasil.offsetWidth;   // ulang animasi
    hasil.style.display = 'block';
    clearTimeout(timerIdle);
    timerIdle = setTimeout(() => { hasil.style.display = 'none'; $('idle').style.display = ''; }, 7000);

    if (r.ok) {
      const li = document.createElement('li');
      li.style.setProperty('--warna', w);
      li.innerHTML = `<div class="titik"></div><div class="flex-grow-1"><div class="nm"></div><small></small></div><small></small>`;
      li.querySelector('.titik').textContent = inisial(r.nama);
      li.querySelector('.nm').textContent = huruf(r.nama);
      li.querySelector('.flex-grow-1 small').textContent = lencana(r).toLowerCase() + (r.kelas ? ' · ' + r.kelas : '');
      li.lastElementChild.textContent = jam5(r.jam);
      const ul = $('daftar');
      ul.prepend(li);
      while (ul.children.length > 30) ul.lastElementChild.remove();
      const kunci = r.tipe + ':' + r.nomor_induk;
      if (r.jenis === 'masuk') hitung.masuk.add(kunci);
      if (r.jenis === 'pulang') hitung.pulang.add(kunci);
      if (r.terlambat && r.jenis === 'masuk') hitung.telat.add(kunci);
      $('nMasuk').textContent = hitung.masuk.size;
      $('nPulang').textContent = hitung.pulang.size;
      $('nTelat').textContent = hitung.telat.size;
    }
    bip(r.ok && r.jenis !== 'ulang');
    ucap(kalimat(r));
  }

  // ---------- Kirim tap ----------
  let sibuk = Promise.resolve();
  function kirim(uid) {
    uid = (uid || '').trim();
    if (!uid) return;
    // Diproses berurutan supaya tap beruntun tidak saling mendahului
    sibuk = sibuk.then(async () => {
      try {
        const res = await fetch('absensi_kartu.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ aksi: 'tap', uid })
        });
        tampil(await res.json());
      } catch (e) {
        tampil({ ok: false, kode: 'jaringan', pesan: 'Tidak dapat menghubungi server.' });
      }
    });
  }

  // Reader mengetik ke input tersembunyi yang selalu difokuskan
  const input = $('uid');
  const modalTerbuka = () => document.querySelector('.modal.show');
  function fokus() { if (!modalTerbuka()) input.focus({ preventScroll: true }); }
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); kirim(input.value); input.value = ''; }
  });
  input.addEventListener('blur', () => setTimeout(fokus, 50));
  document.addEventListener('click', () => setTimeout(fokus, 50));
  document.getElementById('modalSetel').addEventListener('hidden.bs.modal', fokus);
  $('uidManual').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); kirim(e.target.value); e.target.value = ''; }
  });

  // ---------- Tombol & pengaturan ----------
  function ikonSuara() { $('btnSuara').innerHTML = `<i class="bi bi-volume-${suaraNyala ? 'up' : 'mute'}"></i>`; }
  ikonSuara();
  $('btnSuara').onclick = () => { suaraNyala = !suaraNyala; simpan('suara', suaraNyala ? '1' : '0'); ikonSuara(); };
  $('btnLayar').onclick = () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen();
  $('pilSuara').onchange = e => { namaSuara = e.target.value; simpan('namaSuara', namaSuara); };
  $('kecepatan').value = kecepatan; $('lblKec').textContent = kecepatan.toFixed(2);
  $('kecepatan').oninput = e => { kecepatan = parseFloat(e.target.value); $('lblKec').textContent = kecepatan.toFixed(2); simpan('kecepatan', kecepatan); };
  $('ikutMesin').checked = ikutMesin;
  $('ikutMesin').onchange = e => { ikutMesin = e.target.checked; simpan('ikutMesin', ikutMesin ? '1' : '0'); feedSejak = 0; };
  $('tesSuara').onclick = () => { const s = suaraNyala; suaraNyala = true; ucap(`${salam()}, Budi Santoso. Selamat belajar.`); suaraNyala = s; };

  $('mulai').addEventListener('click', () => {
    $('mulai').remove();
    if (sintesis) sintesis.speak(new SpeechSynthesisUtterance(''));   // membuka izin suara
    bip(true);
    fokus();
  });

  // ---------- Absen dari mesin (opsional) ----------
  let feedSejak = 0;
  async function tarikFeed() {
    if (!ikutMesin) return;
    try {
      const res = await fetch('absensi_kartu.php?aksi=feed&sejak=' + feedSejak);
      if (!res.ok) return;
      const d = await res.json();
      const pertama = feedSejak === 0;
      feedSejak = d.sejak;
      if (!pertama) d.scan.forEach(tampil);
    } catch (e) {}
  }
  setInterval(tarikFeed, 3000);

  // Jaga sesi login tetap hidup selama layar dibiarkan menyala seharian
  setInterval(() => fetch('absensi_kartu.php?aksi=ping').then(r => {
    if (r.status === 401) tampil({ ok: false, kode: 'sesi', pesan: 'Sesi login habis — muat ulang halaman & login lagi.' });
  }).catch(() => {}), 5 * 60 * 1000);
})();
</script>
</body>
</html>
