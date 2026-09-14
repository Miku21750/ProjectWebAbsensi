<?php
/**
 * ==========================================
 * BACKFILL RIWAYAT ABSENSI (karyawan/user yang SUDAH ada)
 * ==========================================
 * Berbeda dari seed_dummy_data.php: skrip ini TIDAK PERNAH membuat cabang,
 * jam kerja, karyawan, atau akun baru - hanya mengisi riwayat absensi (serta
 * approval pendamping untuk contoh lembur hari kerja) bagi karyawan status
 * 'aktif' yang SUDAH ada di database. Dibuat untuk kasus
 * "data karyawan/cabang produksi sudah benar dan lengkap, cuma histori
 * absensinya kosong/berlubang" - supaya dashboard & laporan ada isinya
 * tanpa menyentuh data karyawan/akun sama sekali.
 *
 * Logika generate absensinya sama persis dengan seed_dummy_data.php (satu
 * sumber di seed_helpers.php, bukan disalin) - idempotent, melewati tanggal
 * yang sudah punya baris absensi apa pun sumbernya, dan TIDAK PERNAH
 * menyentuh hari ini (lihat docblock backfillRiwayatAbsensi() di
 * seed_helpers.php untuk alasannya - berkaitan dengan cara proses_absen.php
 * menentukan mode masuk/pulang).
 *
 * Baris yang dibuat selalu ditandai is_manual_entry=1 + manual_entry_by =
 * 'seed_backfill_absensi.php', termasuk baris "Hadir" - supaya bisa
 * dibedakan dari absensi kiosk sungguhan (badge "Manual" muncul di halaman
 * Histori Absensi) dan diaudit/dikecualikan manual di kemudian hari kalau
 * perlu, mis. dari perhitungan slip gaji untuk bulan yang datanya sengaja
 * di-backfill ini. Variasinya mencakup kolom baru: menit keterlambatan,
 * pulang cepat disetujui, konversi izin setengah hari, lembur mingguan, dan
 * lembur hari kerja beserta pengajuan_lembur Disetujui yang diwajibkan oleh
 * alur perhitungan lembur.
 *
 * KEAMANAN: sama seperti migrate.php/seed_dummy_data.php - wajib login
 * Admin di web, confirm eksplisit (POST + CSRF) sebelum menulis apa pun,
 * dan pengakuan tambahan kalau database yang terdeteksi bukan localhost.
 *
 * Bisa dibatasi ke karyawan tertentu saja (parameter opsional) - kalau
 * kosong, semua karyawan status 'aktif' diproses seperti biasa.
 *
 * Jalankan: http://localhost/seed_backfill_absensi.php (login Admin dulu)
 * atau CLI : php seed_backfill_absensi.php                              (pratinjau)
 *            php seed_backfill_absensi.php --confirm [--force-remote] [--hari=90] [--karyawan=ID1,ID2,...]
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
$idKaryawanFilter = null; // null = semua karyawan aktif
if ($isCli) {
    $confirmed = in_array('--confirm', $argv, true);
    $forceRemote = in_array('--force-remote', $argv, true);
    $hariKeBelakang = 90;
    foreach ($argv as $a) {
        if (preg_match('/^--hari=(\d+)$/', $a, $m)) {
            $hariKeBelakang = max(1, min(365, (int)$m[1]));
        }
        if (preg_match('/^--karyawan=(.+)$/', $a, $m)) {
            $idKaryawanFilter = explode(',', $m[1]);
        }
    }
} else {
    $confirmed = ($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['jalankan_backfill']);
    $forceRemote = $confirmed && !empty($_POST['ack_remote']);
    $hariKeBelakang = $confirmed ? max(1, min(365, (int)($_POST['hari'] ?? 90))) : max(1, min(365, (int)($_GET['hari'] ?? 90)));
    $rawKaryawan = $confirmed ? ($_POST['karyawan'] ?? '') : ($_GET['karyawan'] ?? '');
    $rawKaryawan = trim($rawKaryawan);
    if ($rawKaryawan !== '') {
        $idKaryawanFilter = preg_split('/[\s,]+/', $rawKaryawan, -1, PREG_SPLIT_NO_EMPTY);
    }
}

// Kalau host bukan localhost dan belum ada pengakuan eksplisit, turunkan ke
// mode pratinjau meski user "mengonfirmasi" - sama seperti seed_dummy_data.php.
$butuhAckRemote = $confirmed && !$info['is_local'] && !$forceRemote;
if ($butuhAckRemote) {
    $confirmed = false;
}

if (!$isCli && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jalankan_backfill'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');
}

$log = new MigrationLog();
$hasil = backfillRiwayatAbsensi($conn, $confirmed, $log, 'seed_backfill_absensi.php', $hariKeBelakang, $idKaryawanFilter);
$ada_error = $log->hasError();

// ==========================================================
// CLI output
// ==========================================================
if ($isCli) {
    echo "DB: {$info['host']} / {$info['database']}" . ($info['is_local'] ? " (lokal)" : " *** BUKAN LOKAL ***") . "\n";
    echo "Rentang: {$hariKeBelakang} hari ke belakang (tidak termasuk hari ini)\n";
    echo $idKaryawanFilter !== null
        ? "Karyawan: " . implode(', ', $idKaryawanFilter) . "\n\n"
        : "Karyawan: semua yang berstatus aktif\n\n";

    if ($butuhAckRemote) {
        echo "Database ini BUKAN localhost. Tambahkan --force-remote di samping --confirm untuk benar-benar menulis data di sini.\n\n";
    }

    foreach ($log->entries as $baris) {
        echo "[" . strtoupper($baris['status']) . "] " . strip_tags($baris['pesan']) . "\n";
    }

    if (!$confirmed) {
        echo "\nDRY-RUN selesai. Jalankan dengan --confirm (+ --force-remote bila bukan lokal, --hari=N untuk ubah rentang) untuk benar-benar menulis data.\n";
    } else {
        echo "\nSelesai. Baris absensi baru: {$hasil['absensi']}" . ($hasil['absensi_gagal'] > 0 ? ", gagal: {$hasil['absensi_gagal']}" : "") . ".\n";
        echo "Pulang cepat disetujui: {$hasil['pulang_cepat']}; konversi izin setengah hari: {$hasil['izin_setengah_hari']}; lembur hari kerja disetujui: {$hasil['lembur_disetujui']}.\n";
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
    <title>Backfill Riwayat Absensi - AbsenKita Javag</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f1f5f9; padding: 40px 20px; color: #0f172a; }
        .box { max-width: 780px; margin: 0 auto 20px; background: #fff; border-radius: 14px; padding: 28px 32px; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
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
        .footer { margin-top: 22px; font-size: 13px; color: #64748b; }
        a { color: #c026d3; font-weight: 600; }
        button { padding: 10px 22px; background: #c026d3; color: #fff; border: none; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; }
        button:hover { background: #a21caf; }
        label.ack { display: block; font-size: 13px; margin: 10px 0; color: #991b1b; }
        input[type=number] { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; width: 100px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?php echo $ada_error ? '⚠️ Backfill selesai dengan error' : ($confirmed ? '✅ Backfill absensi selesai' : '👀 Pratinjau (dry-run)'); ?></h1>
        <p class="sub">
            Hanya mengisi riwayat absensi dan approval lembur pendamping untuk karyawan yang SUDAH ada
            &mdash; tidak pernah membuat cabang, jam kerja, karyawan, atau akun baru. Aman dijalankan berulang kali; tanggal yang
            sudah punya data dilewati, tidak pernah menimpa. Hari ini tidak pernah disentuh.
            Data baru mencakup keterlambatan bertingkat, pulang cepat, izin setengah hari,
            dan lembur yang konsisten dengan tabel pengajuan lembur.
        </p>

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
            Data berhasil ditulis: <b><?php echo $hasil['absensi']; ?></b> baris absensi baru
            <?php if ($hasil['absensi_gagal'] > 0): ?> (<b><?php echo $hasil['absensi_gagal']; ?></b> gagal) <?php endif; ?>.
            Pulang cepat: <b><?php echo $hasil['pulang_cepat']; ?></b>,
            izin setengah hari: <b><?php echo $hasil['izin_setengah_hari']; ?></b>,
            lembur hari kerja disetujui: <b><?php echo $hasil['lembur_disetujui']; ?></b>.
        </div>
        <?php endif; ?>

        <ul>
            <?php foreach ($log->entries as $baris): ?>
                <li class="<?php echo $baris['status']; ?>"><?php echo $baris['pesan']; ?></li>
            <?php endforeach; ?>
        </ul>

        <form method="<?php echo $confirmed ? 'GET' : 'POST'; ?>" style="margin-top:20px;"
              <?php echo !$confirmed ? 'onsubmit="return confirm(\'Jalankan backfill absensi (' . $hariKeBelakang . ' hari) pada database ' . htmlspecialchars($info['host'] . '/' . $info['database'], ENT_QUOTES) . '?\');"' : ''; ?>>
            <?php if (!$confirmed): ?>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="jalankan_backfill" value="1">
            <?php endif; ?>
            <label style="font-size:13px;display:block;margin-bottom:10px;">
                Rentang hari ke belakang:
                <input type="number" name="hari" min="1" max="365" value="<?php echo $hariKeBelakang; ?>">
            </label>
            <label style="font-size:13px;display:block;margin-bottom:10px;">
                ID Karyawan tertentu (opsional - kosongkan untuk semua karyawan aktif):<br>
                <input type="text" name="karyawan" placeholder="mis. 20260812001, 20260812002"
                       value="<?php echo htmlspecialchars($idKaryawanFilter !== null ? implode(', ', $idKaryawanFilter) : ''); ?>"
                       style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;box-sizing:border-box;">
            </label>
            <?php if (!$info['is_local'] && !$confirmed): ?>
            <label class="ack">
                <input type="checkbox" name="ack_remote" value="1" required>
                Saya paham database ini BUKAN localhost dan tetap ingin menjalankan backfill di sini.
            </label>
            <?php endif; ?>
            <button type="submit"><?php echo $confirmed ? 'Pratinjau Ulang' : 'Jalankan Backfill'; ?></button>
        </form>

        <div class="footer">
            Wajib login Admin untuk membuka halaman ini.
            <br><br>
            <a href="login.php">&larr; Kembali ke halaman login</a>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
