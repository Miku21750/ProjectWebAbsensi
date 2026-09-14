<?php
/**
 * ==========================================
 * SEED: Data Dummy / Test Data
 * ==========================================
 * Skrip idempotent (aman dijalankan berulang kali) untuk mengisi database
 * dengan data yang bervariasi agar tiap halaman (dashboard, histori absensi,
 * kalender, slip gaji) ada isinya saat development/demo/onboarding dev baru,
 * memakai pola migration_helpers.php yang sama dengan migrate.php: cek dulu
 * apakah data sudah ada, baru INSERT.
 *
 * APA YANG DIBUAT:
 *   1. 2 cabang tambahan (Kasablanka, Pilar) - alamat & koordinat asli
 *      (bukan data pribadi, aman disertakan) - dilewati jika nama sudah ada.
 *   2. Jam kerja default untuk cabang yang belum punya aturan shift.
 *   3. 8 karyawan contoh (nama PLACEHOLDER, lihat "Nama asli saat redeploy
 *      production" di bawah) - dilewati per baris jika id_karyawan sudah ada.
 *   4. Akun login staff untuk karyawan yang belum punya user - password
 *      DIACAK per akun, ditampilkan sekali di akhir, WAJIB diganti.
 *   5. Riwayat absensi ~90 hari ke belakang untuk SEMUA karyawan status
 *      'aktif' (termasuk yang sudah ada sebelumnya di DB), dengan status
 *      bervariasi: Hadir (termasuk Terlambat), Sakit, Izin, Cuti, Alpha,
 *      pulang cepat disetujui, konversi izin setengah hari, lembur Sabtu
 *      untuk jabatan yang eligible, serta lembur hari kerja beserta approval
 *      pendampingnya. Tanggal yang SUDAH
 *      punya baris absensi (mis. hasil approval pengajuan_izin) dilewati,
 *      jadi tidak pernah menduplikasi/menimpa data asli.
 *
 * KEAMANAN:
 * - Web: wajib login Admin (requireAdmin()). CLI: tidak digate (akses
 *   server dianggap sudah tepercaya, sama seperti migrate.php).
 * - Tidak menulis apa pun kecuali dikonfirmasi lewat form POST (web,
 *   ber-CSRF) atau --confirm (CLI). Tanpa itu, hanya pratinjau (dry-run).
 * - Kalau database yang terdeteksi BUKAN localhost, wajib centang
 *   pengakuan tambahan (web) atau --force-remote (CLI) - supaya menjalankan
 *   ini di production/staging asli selalu sengaja, bukan keklik/lupa.
 * - Password akun staff yang dibuat diacak per akun (bukan satu password
 *   tetap yang sama untuk semua), ditampilkan sekali di layar hasil.
 *
 * NAMA ASLI SAAT REDEPLOY PRODUCTION:
 * $karyawan_seed di bawah sengaja memakai nama PLACEHOLDER, bukan nama asli
 * karyawan - skrip ini dibaca/dijalankan siapa saja di tim, jadi nama asli
 * orang tidak seharusnya nangkring permanen di riwayat git. Untuk redeploy
 * production dengan nama asli:
 *   1. Buat file baru `seed_dummy_data.local.php` di folder yang sama
 *      dengan file ini (sudah di-gitignore - TIDAK akan pernah ter-commit).
 *   2. Isi file itu dengan array PERSIS berstruktur sama seperti
 *      $karyawan_seed di bawah, contoh:
 *          <?php
 *          return [
 *              ['id_karyawan' => '20260812001', 'nama' => 'Nama Asli', 'jk' => 'P', 'cabang' => 'Cabang Kasablanka'],
 *              // ...
 *          ];
 *   3. Jalankan skrip ini seperti biasa - kalau file itu ada, isinya
 *      dipakai menggantikan daftar placeholder di bawah, otomatis, tanpa
 *      mengubah file ini sama sekali.
 *
 * Jalankan: http://localhost/seed_dummy_data.php (login Admin dulu)
 * atau CLI : php seed_dummy_data.php status
 *            php seed_dummy_data.php seed --confirm [--force-remote]
 */

require 'config.php';
require 'migration_helpers.php';
require 'seed_helpers.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    requireAdmin();
}

$info = infoKoneksiDb($conn);

$argv = $_SERVER['argv'] ?? [];
if ($isCli) {
    $confirmed = in_array('--confirm', $argv, true);
    $forceRemote = in_array('--force-remote', $argv, true);
} else {
    $confirmed = ($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['jalankan_seed']);
    $forceRemote = $confirmed && !empty($_POST['ack_remote']);
}

// Kalau host bukan localhost dan belum ada pengakuan eksplisit, jangan
// tulis apa pun - turunkan ke mode pratinjau meski user "mengonfirmasi".
$butuhAckRemote = $confirmed && !$info['is_local'] && !$forceRemote;
if ($butuhAckRemote) {
    $confirmed = false;
}

if (!$isCli && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jalankan_seed'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');
}

$log = new MigrationLog();
$counts = [
    'cabang' => 0,
    'jam_kerja' => 0,
    'karyawan' => 0,
    'users' => 0,
    'absensi' => 0,
    'absensi_gagal' => 0,
];

// ==========================================================
// 0. Helper
// ==========================================================
function cabangIdByNama($conn, $nama) {
    $stmt = $conn->prepare("SELECT id FROM cabang WHERE nama_cabang = ?");
    $stmt->bind_param("s", $nama);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function jabatanIdByNama($conn, $nama) {
    $stmt = $conn->prepare("SELECT id FROM jabatan WHERE nama_jabatan = ?");
    $stmt->bind_param("s", $nama);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function karyawanAda($conn, $id_karyawan) {
    $stmt = $conn->prepare("SELECT id FROM karyawan WHERE id_karyawan = ?");
    $stmt->bind_param("s", $id_karyawan);
    $stmt->execute();
    $ada = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ada;
}

function userAdaUntukKaryawan($conn, $id_karyawan) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id_karyawan = ?");
    $stmt->bind_param("s", $id_karyawan);
    $stmt->execute();
    $ada = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ada;
}

/** Password acak yang gampang dibaca/diketik ulang - bukan satu string tetap untuk semua akun. */
function passwordAcak() {
    $karakter = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $hasil = '';
    for ($i = 0; $i < 10; $i++) {
        $hasil .= $karakter[random_int(0, strlen($karakter) - 1)];
    }
    return $hasil . '!';
}

// ==========================================================
// 1. Cabang tambahan (alamat & koordinat asli - lokasi bisnis, bukan data pribadi)
// ==========================================================
$cabang_seed = [
    [
        'nama' => 'Cabang Kasablanka',
        'alamat' => 'Prudential Center Kota Kasablanka Lantai 5 Unit C-E, Jl. Raya Casablanca No.Kav. 88 16, RT.16/RW.5, Menteng Dalam, Kec. Tebet, Kota Jakarta Selatan, Daerah Khusus Ibukota Jakarta 12870',
        'lat' => -6.22372018,
        'lng' => 106.84277248,
        'radius' => 500,
    ],
    [
        'nama' => 'Cabang Pilar',
        'alamat' => 'Jl. Pilar 3 No.16, RT.2/RW.3, Kedoya Sel., Kec. Kb. Jeruk, Kota Jakarta Barat, Daerah Khusus Ibukota Jakarta 11520',
        'lat' => -6.18173209,
        'lng' => 106.76013823,
        'radius' => 500,
    ],
];

$cabang_id = []; // nama => id (baik yang sudah ada atau baru dibuat)
foreach ($cabang_seed as $c) {
    $id = cabangIdByNama($conn, $c['nama']);
    if ($id !== null) {
        $cabang_id[$c['nama']] = $id;
        $log->skip("Cabang <b>{$c['nama']}</b> sudah ada (id {$id}), dilewati.");
        continue;
    }
    if (!$confirmed) {
        $log->warn("[Dry-run] Akan membuat cabang <b>{$c['nama']}</b>.");
        continue;
    }
    $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang, alamat_cabang, latitude, longitude, radius_meter) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssddi", $c['nama'], $c['alamat'], $c['lat'], $c['lng'], $c['radius']);
    if ($stmt->execute()) {
        $cabang_id[$c['nama']] = $stmt->insert_id;
        $counts['cabang']++;
        $log->ok("Cabang <b>{$c['nama']}</b> berhasil dibuat (id {$stmt->insert_id}).");
    } else {
        $log->error("Gagal membuat cabang {$c['nama']}: " . $conn->error);
    }
    $stmt->close();
}

// ==========================================================
// 2. Jam kerja default untuk cabang yang belum punya shift
//    (termasuk cabang lama yang sudah ada sebelumnya, bukan hanya yang baru)
// ==========================================================
$res_cabang_all = $conn->query("SELECT id, nama_cabang FROM cabang");
while ($row = $res_cabang_all->fetch_assoc()) {
    $stmt = $conn->prepare("SELECT id FROM jam_kerja WHERE id_cabang = ? LIMIT 1");
    $stmt->bind_param("i", $row['id']);
    $stmt->execute();
    $punyaShift = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($punyaShift) {
        $log->skip("Cabang <b>{$row['nama_cabang']}</b> sudah punya jam kerja, dilewati.");
        continue;
    }
    if (!$confirmed) {
        $log->warn("[Dry-run] Akan membuat jam kerja default untuk <b>{$row['nama_cabang']}</b>.");
        continue;
    }
    $nama_shift = 'Shift Normal';
    $jam_masuk_akhir = '08:00:00';
    $jam_pulang = '17:00:00';
    $stmt = $conn->prepare("INSERT INTO jam_kerja (id_cabang, nama_shift, jam_masuk_akhir, jam_pulang) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $row['id'], $nama_shift, $jam_masuk_akhir, $jam_pulang);
    if ($stmt->execute()) {
        $counts['jam_kerja']++;
        $log->ok("Jam kerja default dibuat untuk <b>{$row['nama_cabang']}</b> (08:00-17:00).");
    } else {
        $log->error("Gagal membuat jam kerja untuk {$row['nama_cabang']}: " . $conn->error);
    }
    $stmt->close();
}

// ==========================================================
// 3. Jabatan pemetaan (reuse "Staff" yang sudah ada, buat jika belum ada)
// ==========================================================
$id_jabatan_staff = jabatanIdByNama($conn, 'Staff');
if ($id_jabatan_staff === null) {
    if ($confirmed) {
        $nama_j = 'Staff';
        $tunjangan = 0;
        $overtime = 0;
        $stmt = $conn->prepare("INSERT INTO jabatan (nama_jabatan, tunjangan_jabatan, overtime_sabtu) VALUES (?, ?, ?)");
        $stmt->bind_param("sdi", $nama_j, $tunjangan, $overtime);
        $stmt->execute();
        $id_jabatan_staff = $stmt->insert_id;
        $stmt->close();
        $log->ok("Jabatan <b>Staff</b> dibuat (id {$id_jabatan_staff}) untuk dipakai karyawan contoh.");
    } else {
        $log->warn("[Dry-run] Akan membuat jabatan <b>Staff</b> (belum ada) untuk karyawan contoh.");
        $id_jabatan_staff = 0; // placeholder, hanya dipakai untuk pratinjau
    }
} else {
    $log->skip("Jabatan <b>Staff</b> sudah ada (id {$id_jabatan_staff}), dipakai untuk karyawan contoh.");
}

// ==========================================================
// 4. Karyawan contoh - nama PLACEHOLDER (lihat docblock di atas soal
//    override seed_dummy_data.local.php untuk redeploy production)
// ==========================================================
$karyawan_seed = [
    ['id_karyawan' => '20260812001', 'nama' => 'Rina Kusuma', 'jk' => 'P', 'cabang' => 'Cabang Kasablanka'],
    ['id_karyawan' => '20260812002', 'nama' => 'Dewi Anggraini', 'jk' => 'P', 'cabang' => 'Cabang Kasablanka'],
    ['id_karyawan' => '20260812003', 'nama' => 'Andi Wijaya', 'jk' => 'L', 'cabang' => 'Cabang Pilar'],
    ['id_karyawan' => '20260812004', 'nama' => 'Fajar Hidayat', 'jk' => 'L', 'cabang' => 'Cabang Pilar'],
    ['id_karyawan' => '20260812005', 'nama' => 'Eko Prasetyo', 'jk' => 'L', 'cabang' => 'Cabang Kasablanka'],
    ['id_karyawan' => '20260812006', 'nama' => 'Maya Puspita', 'jk' => 'P', 'cabang' => 'Cabang Pilar'],
    ['id_karyawan' => '20260812007', 'nama' => 'Dian Saputra', 'jk' => 'L', 'cabang' => 'Cabang Kasablanka'],
    ['id_karyawan' => '20260815001', 'nama' => 'Sari Wulandari', 'jk' => 'P', 'cabang' => 'Cabang Kasablanka'],
];

$override_file = __DIR__ . '/seed_dummy_data.local.php';
if (file_exists($override_file)) {
    $override = include $override_file;
    if (is_array($override) && !empty($override)) {
        $karyawan_seed = $override;
        $log->warn("Memakai daftar karyawan dari <code>seed_dummy_data.local.php</code> (override lokal, tidak ter-commit) - "
            . count($karyawan_seed) . " baris.");
    }
}

// rate_* diberi angka realistis supaya slip gaji ada sesuatu untuk dihitung.
$rate_transport = 40000.00;
$rate_overtime = 7500.00;
$rate_insentif_minggu = 25000.00;
$rate_keterlambatan = 20000.00;
$gaji_pokok_default = 4200000.00;
$jatah_cuti_default = 12;

$akun_dibuat = []; // id_karyawan => password plaintext (untuk ditampilkan sekali)

foreach ($karyawan_seed as $k) {
    if (karyawanAda($conn, $k['id_karyawan'])) {
        $log->skip("Karyawan <b>{$k['id_karyawan']} - {$k['nama']}</b> sudah ada, dilewati.");
    } else if (!$confirmed) {
        $log->warn("[Dry-run] Akan membuat karyawan <b>{$k['id_karyawan']} - {$k['nama']}</b> ({$k['cabang']}).");
    } else {
        $id_cabang = $cabang_id[$k['cabang']] ?? null;
        if ($id_cabang === null) {
            $log->error("Lewati {$k['id_karyawan']}: cabang {$k['cabang']} tidak tersedia.");
            continue;
        }
        $stmt = $conn->prepare(
            "INSERT INTO karyawan
                (id_karyawan, nama_karyawan, jenis_kelamin, status, id_jabatan, id_cabang,
                 rate_transport, rate_overtime, rate_insentif_minggu, gaji_pokok, rate_keterlambatan, jatah_cuti)
             VALUES (?, ?, ?, 'aktif', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssiidddddi",
            $k['id_karyawan'], $k['nama'], $k['jk'], $id_jabatan_staff, $id_cabang,
            $rate_transport, $rate_overtime, $rate_insentif_minggu, $gaji_pokok_default,
            $rate_keterlambatan, $jatah_cuti_default
        );
        if ($stmt->execute()) {
            $counts['karyawan']++;
            $log->ok("Karyawan <b>{$k['id_karyawan']} - {$k['nama']}</b> berhasil dibuat ({$k['cabang']}).");
        } else {
            $log->error("Gagal membuat karyawan {$k['id_karyawan']}: " . $conn->error);
        }
        $stmt->close();
    }

    // Akun login staff (hanya jika karyawan sudah/baru ada dan belum punya user)
    if ($confirmed && karyawanAda($conn, $k['id_karyawan']) && !userAdaUntukKaryawan($conn, $k['id_karyawan'])) {
        $password = passwordAcak();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $username = $k['id_karyawan'];
        $role = 'staff';
        $is_active = 1;
        $stmt = $conn->prepare(
            "INSERT INTO users (nama, username, password, role, is_active, jenis_kelamin, id_karyawan)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssssiss", $k['nama'], $username, $hash, $role, $is_active, $k['jk'], $k['id_karyawan']);
        if ($stmt->execute()) {
            $counts['users']++;
            $akun_dibuat[$k['id_karyawan']] = $password;
            $log->ok("Akun login staff dibuat untuk <b>{$k['id_karyawan']}</b> (username sama dengan ID karyawan, password diacak).");
        } else {
            $log->error("Gagal membuat akun untuk {$k['id_karyawan']}: " . $conn->error);
        }
        $stmt->close();
    } else if (!$confirmed && !userAdaUntukKaryawan($conn, $k['id_karyawan'])) {
        $log->warn("[Dry-run] Akan membuat akun login staff untuk <b>{$k['id_karyawan']}</b> (password diacak).");
    }
}

// ==========================================================
// 5. Riwayat absensi ~90 hari untuk SEMUA karyawan aktif
//    (logika sepenuhnya di seed_helpers.php - dipakai bareng dengan
//    seed_backfill_absensi.php supaya tidak ada dua salinan yang bisa
//    diam-diam berbeda perilaku)
// ==========================================================
$hasil_absensi = backfillRiwayatAbsensi($conn, $confirmed, $log, 'seed_dummy_data.php', 90);
$counts['absensi'] = $hasil_absensi['absensi'];
$counts['absensi_gagal'] = $hasil_absensi['absensi_gagal'];

$ada_error = $log->hasError();

// ==========================================================
// CLI output
// ==========================================================
if ($isCli) {
    echo "DB: {$info['host']} / {$info['database']}" . ($info['is_local'] ? " (lokal)" : " *** BUKAN LOKAL ***") . "\n\n";

    if ($butuhAckRemote) {
        echo "Database ini BUKAN localhost. Tambahkan --force-remote di samping --confirm untuk benar-benar menulis data di sini.\n\n";
    }

    foreach ($log->entries as $baris) {
        echo "[" . strtoupper($baris['status']) . "] " . strip_tags($baris['pesan']) . "\n";
    }

    if (!$confirmed) {
        echo "\nDRY-RUN selesai. Jalankan dengan --confirm (+ --force-remote bila bukan lokal) untuk benar-benar menulis data.\n";
    } else {
        echo "\nSelesai. Cabang baru: {$counts['cabang']}, jam kerja baru: {$counts['jam_kerja']}, karyawan baru: {$counts['karyawan']}, akun baru: {$counts['users']}, baris absensi baru: {$counts['absensi']}.\n";
        if (!empty($akun_dibuat)) {
            echo "\nAkun staff baru (password diacak per akun, WAJIB diganti):\n";
            foreach ($akun_dibuat as $id => $pass) {
                echo "  username: {$id}  password: {$pass}\n";
            }
        }
    }
    $conn->close();
    exit($ada_error ? 1 : 0);
}

// ==========================================================
// Web output
// ==========================================================
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Seed Data Dummy - AbsenKita Javag</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f1f5f9; padding: 40px 20px; color: #0f172a; }
        .box { max-width: 820px; margin: 0 auto 20px; background: #fff; border-radius: 14px; padding: 28px 32px; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p.sub { color: #64748b; font-size: 14px; margin: 0 0 22px; }
        .db-banner { padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 18px; }
        .db-banner.local { background: #f0f9ff; color: #075985; border: 1px solid #bae6fd; }
        .db-banner.remote { background: #fef2f2; color: #991b1b; border: 2px solid #fca5a5; }
        .banner { padding: 12px 16px; border-radius: 9px; font-size: 14px; margin-bottom: 18px; }
        .banner.dry { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .banner.live { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        ul { list-style: none; padding: 0; margin: 0; }
        li { padding: 10px 14px; border-radius: 9px; margin-bottom: 8px; font-size: 14px; border: 1px solid transparent; }
        li.ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        li.skip { background: #f8fafc; border-color: #e2e8f0; color: #475569; }
        li.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        li.error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        code { background: rgba(15,23,42,.07); padding: 1px 5px; border-radius: 4px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #e2e8f0; }
        .footer { margin-top: 22px; font-size: 13px; color: #64748b; }
        a { color: #c026d3; font-weight: 600; }
        button { padding: 10px 22px; background: #c026d3; color: #fff; border: none; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; }
        button:hover { background: #a21caf; }
        label.ack { display: block; font-size: 13px; margin: 10px 0; color: #991b1b; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?php echo $ada_error ? '⚠️ Seed selesai dengan error' : ($confirmed ? '✅ Seed data dummy selesai' : '👀 Pratinjau (dry-run)'); ?></h1>
        <p class="sub">Aman dijalankan berulang kali &mdash; data yang sudah ada otomatis dilewati, tidak pernah menimpa/menduplikasi.</p>

        <div class="db-banner <?php echo $info['is_local'] ? 'local' : 'remote'; ?>">
            <?php if ($info['is_local']): ?>
                🖥️ Terhubung ke <code><?php echo htmlspecialchars($info['host']); ?></code> / <code><?php echo htmlspecialchars($info['database']); ?></code> (lingkungan lokal)
            <?php else: ?>
                ⚠️ Terhubung ke <code><?php echo htmlspecialchars($info['host']); ?></code> / <code><?php echo htmlspecialchars($info['database']); ?></code>
                — <b>ini BUKAN localhost.</b>
            <?php endif; ?>
        </div>

        <?php if (!$confirmed): ?>
        <div class="banner dry">
            Ini baru <b>pratinjau</b>, belum ada yang ditulis ke database.
            <?php echo $butuhAckRemote ? 'Database ini bukan lokal - centang pengakuan di bawah sebelum bisa menjalankan yang sesungguhnya.' : ''; ?>
        </div>
        <?php else: ?>
        <div class="banner live">
            Data berhasil ditulis: <b><?php echo $counts['cabang']; ?></b> cabang baru,
            <b><?php echo $counts['jam_kerja']; ?></b> jam kerja baru,
            <b><?php echo $counts['karyawan']; ?></b> karyawan baru,
            <b><?php echo $counts['users']; ?></b> akun baru,
            <b><?php echo $counts['absensi']; ?></b> baris absensi baru.
        </div>
        <?php endif; ?>

        <ul>
            <?php foreach ($log->entries as $baris): ?>
                <li class="<?php echo $baris['status']; ?>"><?php echo $baris['pesan']; ?></li>
            <?php endforeach; ?>
        </ul>

        <?php if (!empty($akun_dibuat)): ?>
        <h3 style="font-size:15px;margin-top:22px;">Akun staff baru (password diacak per akun, WAJIB diganti setelah login pertama)</h3>
        <table>
            <tr><th>Username</th><th>Password</th></tr>
            <?php foreach ($akun_dibuat as $id => $pass): ?>
            <tr><td><code><?php echo htmlspecialchars($id); ?></code></td><td><code><?php echo htmlspecialchars($pass); ?></code></td></tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <?php if (!$confirmed): ?>
        <form method="POST" style="margin-top:20px;" onsubmit="return confirm('Jalankan seed data dummy pada database ' + <?php echo json_encode($info['host'] . '/' . $info['database']); ?> + '?');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="jalankan_seed" value="1">
            <?php if (!$info['is_local']): ?>
            <label class="ack">
                <input type="checkbox" name="ack_remote" value="1" required>
                Saya paham database ini BUKAN localhost dan tetap ingin menjalankan seed di sini.
            </label>
            <?php endif; ?>
            <button type="submit">Jalankan Seed</button>
        </form>
        <?php endif; ?>

        <div class="footer">
            Wajib login Admin untuk membuka halaman ini. Setelah dipakai untuk provisioning awal, sebaiknya batasi
            akses file ini lebih lanjut (mis. IP allowlist di web server) di production.
            <br><br>
            <a href="login.php">&larr; Kembali ke halaman login</a>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
