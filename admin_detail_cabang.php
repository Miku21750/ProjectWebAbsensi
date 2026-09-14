<?php
require 'config.php';
require 'admin_header.php';

$id_cabang = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_cabang) {
    $_SESSION['error_message'] = 'Cabang tidak valid.';
    header('Location: data_cabang.php');
    exit();
}

$stmt = $conn->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM karyawan k WHERE k.id_cabang = c.id AND k.status = 'aktif') AS total_karyawan,
            (SELECT COUNT(*) FROM jam_kerja jk WHERE jk.id_cabang = c.id) AS total_shift
     FROM cabang c
     WHERE c.id = ?"
);
$stmt->bind_param('i', $id_cabang);
$stmt->execute();
$cabang = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cabang) {
    $_SESSION['error_message'] = 'Cabang tidak ditemukan.';
    header('Location: data_cabang.php');
    exit();
}

$pengaturan = getPengaturanKeterlambatan($conn, $id_cabang);
$stmt_shift = $conn->prepare("SELECT nama_shift, jam_masuk_akhir, jam_pulang FROM jam_kerja WHERE id_cabang = ? ORDER BY jam_masuk_akhir");
$stmt_shift->bind_param('i', $id_cabang);
$stmt_shift->execute();
$shifts = $stmt_shift->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_shift->close();
?>

<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
    <div>
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white">Detail Cabang</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Informasi dan aturan keterlambatan khusus cabang ini.</p>
    </div>
    <a href="data_cabang.php" class="flex items-center justify-center gap-2 px-4 py-2.5 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl transition-colors font-medium text-sm w-full sm:w-auto">
        <i class="fa-solid fa-arrow-left"></i> Kembali
    </a>
</div>

<?php include 'alert_messages.php'; ?>

<div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 mb-8">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div class="flex items-start gap-4">
            <div class="w-14 h-14 rounded-2xl bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl border border-brand-100 dark:border-brand-800/30 shrink-0">
                <i class="fa-solid fa-building"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($cabang['nama_cabang']); ?></h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    <i class="fa-solid fa-location-dot text-rose-500 mr-1"></i>
                    <?php echo nl2br(htmlspecialchars($cabang['alamat_cabang'])); ?>
                </p>
            </div>
        </div>
        <div class="flex gap-3">
            <div class="px-4 py-3 rounded-xl bg-slate-50 dark:bg-slate-900/50 border border-slate-100 dark:border-slate-700 text-center min-w-28">
                <p class="text-lg font-bold text-slate-800 dark:text-white"><?php echo (int)$cabang['total_karyawan']; ?></p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Karyawan Aktif</p>
            </div>
            <div class="px-4 py-3 rounded-xl bg-slate-50 dark:bg-slate-900/50 border border-slate-100 dark:border-slate-700 text-center min-w-28">
                <p class="text-lg font-bold text-slate-800 dark:text-white"><?php echo (int)$cabang['total_shift']; ?></p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Aturan Shift</p>
            </div>
        </div>
    </div>

    <?php if (!empty($shifts)): ?>
        <div class="mt-5 pt-5 border-t border-slate-200 dark:border-slate-700 flex flex-wrap gap-2">
            <?php foreach ($shifts as $shift): ?>
                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300">
                    <strong><?php echo htmlspecialchars($shift['nama_shift']); ?></strong>
                    <?php echo date('H:i', strtotime($shift['jam_masuk_akhir'])); ?>–<?php echo date('H:i', strtotime($shift['jam_pulang'])); ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-8">
    <div class="p-5 border-b border-slate-200 dark:border-slate-700">
        <h3 class="font-bold text-slate-800 dark:text-white"><i class="fa-solid fa-stopwatch text-amber-500 mr-2"></i>Aturan Potongan Keterlambatan</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Nilai awal mengikuti kebijakan default: dispensasi 10 menit, Rp15.000 untuk 10 menit pertama, lalu Rp10.000 setiap 5 menit, maksimal 3 jam.</p>
    </div>

    <form action="master_process.php" method="POST" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
        <input type="hidden" name="id_cabang" value="<?php echo $id_cabang; ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Masa Dispensasi</label>
                <input type="number" name="grace_menit" value="<?php echo $pengaturan['grace_menit']; ?>" min="0" max="1440" step="1" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <p class="text-xs text-slate-400 mt-1">Menit setelah batas masuk sebelum dianggap terlambat.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Durasi Tarif Pertama (menit)</label>
                <input type="number" name="tier1_durasi_menit" value="<?php echo $pengaturan['tier1_durasi_menit']; ?>" min="1" max="1440" step="1" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Potongan Tarif Pertama (Rp)</label>
                <input type="number" name="tier1_rate" value="<?php echo $pengaturan['tier1_rate']; ?>" min="0" step="1" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Interval Tarif Berikutnya (menit)</label>
                <input type="number" name="tier2_interval_menit" value="<?php echo $pengaturan['tier2_interval_menit']; ?>" min="1" max="1440" step="1" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Potongan per Interval (Rp)</label>
                <input type="number" name="tier2_rate" value="<?php echo $pengaturan['tier2_rate']; ?>" min="0" step="1" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Batas Maksimal (jam)</label>
                <input type="number" name="maks_jam" value="<?php echo $pengaturan['maks_jam']; ?>" min="0.1" max="24" step="0.1" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>

        <div class="mt-5 pt-5 border-t border-slate-200 dark:border-slate-700 flex justify-end">
            <button type="submit" name="simpan_pengaturan_keterlambatan" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl shadow-sm shadow-brand-500/30 transition-colors text-sm font-medium">
                <i class="fa-solid fa-floppy-disk mr-2"></i>Simpan Pengaturan
            </button>
        </div>
    </form>
</div>

<?php include 'admin_footer.php'; ?>
