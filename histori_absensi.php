<?php
require 'config.php';
require_once 'attendance_capture.php';
$cabang_reviewer = requireAttendancePhotoReviewer($conn);
$is_admin = isAdmin();
$capture_ready = attendanceCaptureReady($conn);

// Ambil daftar cabang untuk dropdown
$cabang_list = [];
$res_cabang = $conn->query("SELECT id, nama_cabang FROM cabang"
    . ($cabang_reviewer !== null ? ' WHERE id = ' . (int)$cabang_reviewer : '')
    . " ORDER BY nama_cabang ASC");
if ($res_cabang) {
    while($row = $res_cabang->fetch_assoc()) {
        $cabang_list[] = $row;
    }
}

// Default ke cabang pertama jika tidak ada yang dipilih
if (!isset($_GET['cabang']) || !is_numeric($_GET['cabang'])) {
    if (!empty($cabang_list)) {
        $id_cabang = intval($cabang_list[0]['id']);
    } else {
        $id_cabang = 0;
    }
} else {
    $id_cabang = intval($_GET['cabang']);
}

// Supervisor cannot override their branch through a query parameter.
if ($cabang_reviewer !== null) $id_cabang = (int)$cabang_reviewer;

// Ambil nama cabang
$nama_cabang = 'Cabang Tidak Ditemukan';
foreach ($cabang_list as $c) {
    if ($c['id'] == $id_cabang) {
        $nama_cabang = $c['nama_cabang'];
        break;
    }
}

// Filter tanggal
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) 
    ? sanitizeInput($_GET['start_date']) 
    : date('Y-m-01');
    
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) 
    ? sanitizeInput($_GET['end_date']) 
    : date('Y-m-t');

// Search filter
$search_name = isset($_GET['search_name']) ? html_entity_decode(sanitizeInput($_GET['search_name']), ENT_QUOTES, 'UTF-8') : '';

$absensi_list = null;
$shifts_data = [];
$dataForExport = [];

if ($id_cabang > 0) {
    // CRITICAL FIX: Ambil semua shift data untuk cabang ini
    $sql_shifts = "SELECT nama_shift, jam_masuk_akhir, jam_pulang FROM jam_kerja WHERE id_cabang = ? ORDER BY jam_pulang ASC";
    $stmt_shifts = $conn->prepare($sql_shifts);
    $stmt_shifts->bind_param("i", $id_cabang);
    $stmt_shifts->execute();
    $shifts_data = $stmt_shifts->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_shifts->close();

    // Auto-Reject (Delete) Pending Dinas yang sudah lebih dari 4 jam
    if ($is_admin) $conn->query("DELETE FROM absensi WHERE keterangan = 'Pending Dinas' AND TIMESTAMPDIFF(HOUR, waktu_alasan, NOW()) >= 4");

    // Query diperbarui dengan is_manual_entry dan manual_entry_by
    $capture_columns = $capture_ready
        ? "EXISTS(SELECT 1 FROM absensi_capture p WHERE p.id_absensi = a.id AND p.jenis = 'masuk') AS capture_masuk,
           EXISTS(SELECT 1 FROM absensi_capture p WHERE p.id_absensi = a.id AND p.jenis = 'pulang') AS capture_pulang,"
        : '0 AS capture_masuk, 0 AS capture_pulang,';
    $sql = "SELECT a.*, $capture_columns
            k.nama_karyawan, 
            k.id as karyawan_id,
            k.id_cabang,
            (SELECT MAX(jam_pulang) FROM jam_kerja WHERE id_cabang = k.id_cabang) AS jam_pulang_standar,
            TIMESTAMPDIFF(MINUTE, a.jam_masuk, a.jam_pulang) AS durasi_menit,
            a.is_manual_entry,
            a.manual_entry_by
            FROM absensi a 
            JOIN karyawan k ON a.id_karyawan = k.id_karyawan
            WHERE k.id_cabang = ? 
            AND a.tanggal BETWEEN ? AND ?";

    if (!empty($search_name)) {
        $sql .= " AND k.nama_karyawan LIKE ?";
    }

    $sql .= " ORDER BY a.tanggal DESC, a.jam_masuk DESC";

    $stmt = $conn->prepare($sql);

    if (!empty($search_name)) {
        $search_param = '%' . $search_name . '%';
        $stmt->bind_param("isss", $id_cabang, $start_date, $end_date, $search_param);
    } else {
        $stmt->bind_param("iss", $id_cabang, $start_date, $end_date);
    }

    $stmt->execute();
    $absensi_list = $stmt->get_result();
}

// Function untuk detect shift berdasarkan jam masuk karyawan
function detectCorrectShift($jam_masuk_karyawan, $shifts_data) {
    if (empty($jam_masuk_karyawan) || empty($shifts_data)) {
        return null;
    }
    $jam_masuk_ts = strtotime($jam_masuk_karyawan);
    $best_match = null;
    $min_diff = PHP_INT_MAX;
    foreach ($shifts_data as $shift) {
        $shift_masuk_ts = strtotime($shift['jam_masuk_akhir']);
        $diff = abs($jam_masuk_ts - $shift_masuk_ts);
        if ($diff < $min_diff) {
            $min_diff = $diff;
            $best_match = $shift;
        }
    }
    return $best_match;
}

$csrf_token = generateCSRFToken();

require $is_admin ? 'admin_header.php' : 'supervisor_header.php';
?>

<!-- MAIN CONTENT -->
<div class="flex-1 overflow-y-auto p-6 lg:p-8 space-y-6">
    <?php include 'alert_messages.php'; ?>
    
    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white">Data Log Harian</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Pantau dan kelola riwayat kedatangan/kepulangan karyawan per hari.</p>
        </div>
        
        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto justify-start sm:justify-end">
            <!-- Tombol ke Halaman Statistik -->
            <?php if ($is_admin): ?>
            <a href="statistik_absensi.php?cabang=<?php echo $id_cabang; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="flex items-center gap-2 px-4 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors font-medium text-sm shadow-sm">
                <i class="fa-solid fa-chart-pie text-brand-500"></i> Lihat Statistik
            </a>
            <?php endif; ?>

            <button onclick="exportAbsensiExcel()" class="flex items-center gap-2 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl transition-colors font-medium text-sm shadow-sm shadow-emerald-500/30">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </button>


            <!-- Filter Opsi Cabang -->
            <form action="histori_absensi.php" method="GET" class="relative m-0" id="formCabang">
                <input type="hidden" name="start_date" value="<?php echo $start_date; ?>">
                <input type="hidden" name="end_date" value="<?php echo $end_date; ?>">
                <input type="hidden" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>">
                <select name="cabang" onchange="document.getElementById('formCabang').submit()" class="appearance-none pl-4 pr-10 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors font-medium text-sm shadow-sm outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer">
                    <?php if (empty($cabang_list)): ?>
                        <option value="">-- Tidak ada cabang --</option>
                    <?php else: ?>
                        <?php foreach($cabang_list as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $id_cabang == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['nama_cabang']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-slate-500">
                    <i class="fa-solid fa-chevron-down text-xs"></i>
                </div>
            </form>

            <?php if ($is_admin): ?><div class="flex items-center gap-2">
                <!-- Tombol Kelola Cuti Bersama -->
                <button onclick="openModal('modal-kelola-cuti-bersama')" class="flex items-center gap-2 px-4 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl transition-colors font-medium text-sm shadow-sm shadow-teal-500/30">
                    <i class="fa-solid fa-calendar-plus"></i> Kelola Cuti Bersama
                </button>
                
                <!-- Tombol Tambah Manual -->
                <button onclick="openModal('modal-tambah-manual')" class="flex items-center gap-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl transition-colors font-medium text-sm shadow-sm shadow-brand-500/30">
                    <i class="fa-solid fa-plus"></i> Tambah Absensi Manual
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Table Card Container -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm flex flex-col">
        
        <!-- Table Toolbar (Filter) -->
        <form action="histori_absensi.php" method="GET" class="p-5 border-b border-slate-200 dark:border-slate-700 flex flex-wrap items-end gap-4">
            <input type="hidden" name="cabang" value="<?php echo $id_cabang; ?>">
            <div class="w-full sm:w-auto flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 uppercase tracking-wider">Pencarian</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-magnifying-glass text-slate-400"></i>
                    </div>
                    <input type="text" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>" class="block w-full pl-10 pr-3 py-2 border border-slate-200 dark:border-slate-600 rounded-xl text-sm bg-slate-50 dark:bg-slate-900/50 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:text-white" placeholder="Cari nama karyawan...">
                </div>
            </div>

            <div class="w-full sm:w-auto flex-1 min-w-[150px]">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 uppercase tracking-wider">Tanggal Mulai</label>
                <div class="relative">
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" max="<?php echo date('Y-m-d'); ?>" class="block w-full px-3 py-2 border border-slate-200 dark:border-slate-600 rounded-xl text-sm bg-slate-50 dark:bg-slate-900/50 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:text-white">
                </div>
            </div>

            <div class="w-full sm:w-auto flex-1 min-w-[150px]">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 uppercase tracking-wider">Tanggal Akhir</label>
                <div class="relative">
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" max="<?php echo date('Y-m-d'); ?>" class="block w-full px-3 py-2 border border-slate-200 dark:border-slate-600 rounded-xl text-sm bg-slate-50 dark:bg-slate-900/50 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:text-white">
                </div>
            </div>

            <button type="submit" class="w-full sm:w-auto px-6 py-2 bg-slate-800 dark:bg-slate-700 text-white rounded-xl font-medium shadow-sm hover:bg-slate-700 dark:hover:bg-slate-600 transition-colors text-sm h-[38px] flex items-center justify-center gap-2">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if (!empty($search_name)): ?>
                <a href="histori_absensi.php?cabang=<?php echo $id_cabang; ?>&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>" class="w-full sm:w-auto px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-xl font-medium shadow-sm hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors text-sm h-[38px] flex items-center justify-center gap-2">
                    <i class="fa-solid fa-times"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Table View -->
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse min-w-[1000px]" id="dataTable">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-900/50 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider border-b border-slate-200 dark:border-slate-700">
                        <th class="px-5 py-4 font-semibold text-center w-12">NO</th>
                        <th class="px-5 py-4 font-semibold">TANGGAL</th>
                        <th class="px-5 py-4 font-semibold">NAMA KARYAWAN</th>
                        <th class="px-5 py-4 font-semibold text-center">JAM MASUK</th>
                        <th class="px-5 py-4 font-semibold text-center">JAM PULANG</th>
                        <th class="px-5 py-4 font-semibold">STATUS / KETERANGAN</th>
                        <th class="px-5 py-4 font-semibold text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700" id="tableBody">
                    <?php if ($absensi_list && $absensi_list->num_rows > 0): ?>
                        <?php 
                        $no = 1; 
                        while($row = $absensi_list->fetch_assoc()): 
                            $status_pulang = '-';
                            
                            // CRITICAL FIX: Detect shift berdasarkan jam masuk
                            $detected_shift = detectCorrectShift($row['jam_masuk'], $shifts_data);
                            $jam_pulang_standar = $detected_shift ? $detected_shift['jam_pulang'] : null;
                            
                            if ($row['jam_pulang'] && $row['jam_pulang'] != '00:00:00' && $row['jam_pulang'] != NULL) {
                                
                                // Hitung durasi dalam menit
                                $durasi_menit = $row['durasi_menit'];
                                
                                if ($durasi_menit !== null && $durasi_menit > 0) {
                                    if ($durasi_menit < 330) {
                                        $status_pulang = 'Setengah Hari';
                                    } elseif (!empty($jam_pulang_standar) && strtotime($row['jam_pulang']) > strtotime($jam_pulang_standar)) {
                                        $status_pulang = 'Over Time';
                                    } else {
                                        $status_pulang = 'Normal';
                                    }
                                }
                            } else if ($row['keterangan'] == 'Hadir' && strtotime($row['tanggal']) < strtotime('today')) {
                                $status_pulang = 'Belum Absen Pulang = Set. Hari';
                            }

                            // Simpan untuk export
                            $dataForExport[] = [
                                'no' => $no,
                                'nama' => htmlspecialchars($row['nama_karyawan']),
                                'tanggal' => date('d-m-Y', strtotime($row['tanggal'])),
                                'jam_masuk' => $row['jam_masuk'] ? date('H:i:s', strtotime($row['jam_masuk'])) : '-',
                                'jam_keluar' => ($row['jam_pulang'] && $row['jam_pulang'] != '00:00:00') ? date('H:i:s', strtotime($row['jam_pulang'])) : '-',
                                'status_masuk' => ($row['keterangan'] == 'Hadir' && empty($row['jam_masuk']))
                                    ? 'TIDAK ABSEN MASUK'
                                    : ($row['keterangan'] == 'Hadir' ? $row['status_masuk'] : '-'),
                                'status_pulang' => $status_pulang,
                                'keterangan' => htmlspecialchars($row['keterangan']),
                            ];

                            // Kumpulkan badge class
                            $tr_class = "hover:bg-slate-50/50 dark:hover:bg-slate-800/50 transition-colors";
                            if ($row['keterangan'] == 'Sakit') $tr_class .= " bg-rose-50/30 dark:bg-rose-900/5";
                            else if ($row['keterangan'] == 'Cuti') $tr_class .= " bg-purple-50/30 dark:bg-purple-900/5";
                            else if ($row['keterangan'] == 'Alpha') $tr_class .= " bg-red-50/30 dark:bg-red-900/5";
                            else if ($row['keterangan'] == 'OFF') $tr_class .= " bg-slate-50/30 dark:bg-slate-800/30";
                        ?>
                            <tr class="<?php echo $tr_class; ?>">
                                <td class="px-5 py-4 whitespace-nowrap text-sm text-center text-slate-500"><?php echo $no++; ?></td>
                                <td class="px-5 py-4 whitespace-nowrap text-sm text-slate-700 dark:text-slate-300 font-medium"><?php echo date('d-m-Y', strtotime($row['tanggal'])); ?></td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-slate-800 dark:text-white text-sm"><?php echo htmlspecialchars($row['nama_karyawan']); ?></span>
                                        <?php if (isset($row['is_manual_entry']) && $row['is_manual_entry'] == 1): ?>
                                            <span class="px-1.5 py-0.5 bg-slate-200 dark:bg-slate-700 text-[10px] rounded font-medium text-slate-600 dark:text-slate-300" title="Ditambahkan manual">Manual</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars($nama_cabang); ?></p>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-center">
                                    <?php if (!empty($row['jam_masuk'])): ?>
                                        <span class="inline-block px-3 py-1 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded font-mono text-sm border border-slate-200 dark:border-slate-600">
                                            <?php echo date('H:i:s', strtotime($row['jam_masuk'])); ?>
                                        </span>
                                    <?php elseif (!empty($row['jam_pulang'])): ?>
                                        <span class="inline-block px-3 py-1 bg-amber-50 text-amber-600 dark:bg-amber-900/20 dark:text-amber-400 rounded font-mono text-sm border border-amber-200 dark:border-amber-800/50 font-bold" title="Tidak ada absen masuk - langsung absen pulang">--:--:--</span>
                                    <?php else: ?>
                                        <span class="inline-block px-3 py-1 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded font-mono text-sm border border-slate-200 dark:border-slate-600">--:--:--</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-center">
                                    <?php if ($row['jam_pulang'] && $row['jam_pulang'] != '00:00:00' && $row['jam_pulang'] != NULL): ?>
                                        <span class="inline-block px-3 py-1 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded font-mono text-sm border border-slate-200 dark:border-slate-600">
                                            <?php echo date('H:i:s', strtotime($row['jam_pulang'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block px-3 py-1 bg-amber-50 text-amber-600 dark:bg-amber-900/20 dark:text-amber-400 rounded font-mono text-sm border border-amber-200 dark:border-amber-800/50 font-bold">--:--:--</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="flex flex-col gap-1.5 items-start">
                                        <?php if ($row['keterangan'] == 'Hadir'): ?>
                                            <?php if (empty($row['jam_masuk'])): ?>
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <?php if (!empty($row['dikonversi_izin_setengah_hari'])): ?>
                                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-200 dark:bg-teal-900/30 dark:text-teal-400 dark:border-teal-800/50" title="<?php echo !empty($row['dikonversi_at']) ? 'Dikonversi ' . date('d-m-Y H:i', strtotime($row['dikonversi_at'])) : ''; ?>">
                                                            <i class="fa-solid fa-calendar-check text-[10px]"></i> Izin Setengah Hari
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-800/50" title="Karyawan langsung absen pulang tanpa absen masuk - tanyakan alasannya">
                                                            <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Tidak Absen Masuk
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['alasan'])): ?>
                                                        <button type="button" onclick="openDetailAlasanModal(this)" data-alasan="<?php echo htmlspecialchars($row['alasan']); ?>" data-foto="" data-lokasi="<?php echo htmlspecialchars($row['lokasi_pulang'] ?? ''); ?>" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-fuchsia-50 text-fuchsia-700 border border-fuchsia-200 hover:bg-fuchsia-100 transition-colors dark:bg-fuchsia-900/30 dark:text-fuchsia-400 dark:border-fuchsia-800/50">
                                                            <i class="fa-solid fa-eye"></i> Lihat Alasan
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (empty($row['dikonversi_izin_setengah_hari'])): ?>
                                                        <button type="button" onclick="konversiSetengahHari(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['nama_karyawan'], ENT_QUOTES); ?>')" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-colors dark:bg-emerald-900/30 dark:text-emerald-400 dark:border-emerald-800/50">
                                                            <i class="fa-solid fa-calendar-check"></i> Konversi ke Izin ½ Hari
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($row['status_masuk'] == 'Tepat Waktu'): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400 dark:border-emerald-800/50">
                                                    <i class="fa-solid fa-circle-check text-[10px]"></i> Hadir Tepat Waktu
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-400 dark:border-red-800/50">
                                                    <i class="fa-solid fa-circle-exclamation text-[10px]"></i> Terlambat
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($row['jam_pulang'] && $row['jam_pulang'] != '00:00:00'): ?>
                                                <?php if ($status_pulang == 'Setengah Hari'): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-orange-50 text-orange-700 border border-orange-200 dark:bg-orange-900/30 dark:text-orange-400 dark:border-orange-800/50">
                                                        <i class="fa-solid fa-clock-rotate-left"></i> Setengah Hari
                                                    </span>
                                                <?php elseif ($status_pulang == 'Over Time'): ?>
                                                    <div class="flex items-center gap-1.5">
                                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-purple-50 text-purple-700 border border-purple-200 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-800/50">
                                                            <i class="fa-solid fa-business-time"></i> Over Time
                                                        </span>
                                                        <?php if (!empty($row['alasan_pulang'])): ?>
                                                            <button type="button" onclick="openDetailAlasanModal(this)" data-alasan="<?php echo htmlspecialchars($row['alasan_pulang'] ?? ''); ?>" data-foto="<?php echo htmlspecialchars($row['foto_pulang'] ?? ''); ?>" data-lokasi="<?php echo htmlspecialchars($row['lokasi_pulang'] ?? ''); ?>" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-fuchsia-50 text-fuchsia-700 border border-fuchsia-200 hover:bg-fuchsia-100 transition-colors dark:bg-fuchsia-900/30 dark:text-fuchsia-400 dark:border-fuchsia-800/50">
                                                                <i class="fa-solid fa-eye"></i> Detail
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-800/50">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo ($status_pulang == 'Belum Absen Pulang = Set. Hari') ? 'Belum Absen Pulang = Set. Hari' : 'Belum Absen Pulang'; ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php
                                                $keterangan_classes = [
                                                    'OFF' => 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-600',
                                                    'Sakit' => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-900/30 dark:text-rose-400 dark:border-rose-800/50',
                                                    'Cuti' => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-800/50',
                                                    'Alpha' => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-400 dark:border-red-800/50',
                                                    'Dinas Luar' => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-800/50',
                                                    'Menikah' => 'bg-pink-50 text-pink-700 border-pink-200 dark:bg-pink-900/30 dark:text-pink-400 dark:border-pink-800/50',
                                                    'Menikahkan Anak' => 'bg-pink-50 text-pink-700 border-pink-200 dark:bg-pink-900/30 dark:text-pink-400 dark:border-pink-800/50',
                                                    'Melahirkan' => 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-900/30 dark:text-teal-400 dark:border-teal-800/50',
                                                    'Duka Cita' => 'bg-slate-200 text-slate-700 border-slate-300 dark:bg-slate-700/50 dark:text-slate-300 dark:border-slate-600'
                                                ];

                                                $icons = [
                                                    'OFF' => 'fa-calendar-times',
                                                    'Sakit' => 'fa-file-medical',
                                                    'Cuti' => 'fa-calendar-check',
                                                    'Alpha' => 'fa-xmark',
                                                    'Dinas Luar' => 'fa-briefcase',
                                                    'Menikah' => 'fa-ring',
                                                    'Menikahkan Anak' => 'fa-ring',
                                                    'Melahirkan' => 'fa-baby',
                                                    'Duka Cita' => 'fa-heart-crack'
                                                ];
                                                
                                                $class = $keterangan_classes[$row['keterangan']] ?? $keterangan_classes['OFF'];
                                                $icon = $icons[$row['keterangan']] ?? 'fa-circle';
                                            ?>
                                            <?php if ($row['keterangan'] === 'Pending Dinas' && $is_admin): ?>
                                                <button type="button" onclick="openApprovalDinasModal(this)" data-id="<?php echo $row['id']; ?>" data-nama="<?php echo htmlspecialchars($row['nama_karyawan']); ?>" data-alasan="<?php echo htmlspecialchars($row['alasan'] ?? ''); ?>" data-foto="<?php echo htmlspecialchars($row['foto_bukti'] ?? ''); ?>" data-waktu="<?php echo date('H:i:s', strtotime($row['jam_masuk'])); ?>" data-lokasi="<?php echo htmlspecialchars($row['lokasi_masuk'] ?? ''); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium bg-amber-500 text-white shadow-sm hover:bg-amber-600 transition-colors">
                                                    <i class="fa-solid fa-clock"></i> Permintaan Persetujuan Dinas
                                                </button>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium border <?php echo $class; ?>">
                                                    <i class="fa-solid <?php echo $icon; ?> text-[10px]"></i> <?php echo htmlspecialchars($row['keterangan']); ?>
                                                </span>
                                                <?php if (in_array($row['keterangan'], ['Sakit', 'Cuti', 'Dinas Luar', 'Menikah', 'Menikahkan Anak', 'Melahirkan', 'Duka Cita'], true)): ?>
                                                    <div class="flex items-center gap-1.5 mt-1">
                                                        <?php if ($row['keterangan'] === 'Dinas Luar' && $status_pulang === 'Setengah Hari'): ?>
                                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-orange-50 text-orange-700 border border-orange-200 dark:bg-orange-900/30 dark:text-orange-400 dark:border-orange-800/50">
                                                                <i class="fa-solid fa-clock-rotate-left"></i> Setengah Hari
                                                            </span>
                                                        <?php elseif ($row['keterangan'] === 'Dinas Luar' && $status_pulang === 'Over Time'): ?>
                                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-purple-50 text-purple-700 border border-purple-200 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-800/50">
                                                                <i class="fa-solid fa-business-time"></i> Over Time
                                                            </span>
                                                            <?php if (!empty($row['alasan_pulang'])): ?>
                                                                <button type="button" onclick="openDetailAlasanModal(this)" data-alasan="<?php echo htmlspecialchars($row['alasan_pulang'] ?? ''); ?>" data-foto="<?php echo htmlspecialchars($row['foto_pulang'] ?? ''); ?>" data-lokasi="<?php echo htmlspecialchars($row['lokasi_pulang'] ?? ''); ?>" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-fuchsia-50 text-fuchsia-700 border border-fuchsia-200 hover:bg-fuchsia-100 transition-colors dark:bg-fuchsia-900/30 dark:text-fuchsia-400 dark:border-fuchsia-800/50">
                                                                    <i class="fa-solid fa-eye"></i> Detail Lembur
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <button type="button" onclick="openDetailAlasanModal(this)" data-alasan="<?php echo htmlspecialchars($row['alasan'] ?? ''); ?>" data-foto="<?php echo htmlspecialchars($row['foto_bukti'] ?? ''); ?>" data-lokasi="<?php echo htmlspecialchars($row['lokasi_masuk'] ?? ''); ?>" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium bg-fuchsia-50 text-fuchsia-700 border border-fuchsia-200 hover:bg-fuchsia-100 transition-colors dark:bg-fuchsia-900/30 dark:text-fuchsia-400 dark:border-fuchsia-800/50">
                                                            <i class="fa-solid fa-eye"></i> Detail
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-center">
                                    <?php if ($row['capture_masuk'] || $row['capture_pulang']): ?>
                                        <button type="button" onclick="openCaptureModal(this)" data-id="<?= (int)$row['id'] ?>" data-nama="<?= htmlspecialchars($row['nama_karyawan'], ENT_QUOTES, 'UTF-8') ?>" data-tanggal="<?= htmlspecialchars($row['tanggal'], ENT_QUOTES, 'UTF-8') ?>" data-masuk="<?= (int)$row['capture_masuk'] ?>" data-pulang="<?= (int)$row['capture_pulang'] ?>" data-jam-masuk="<?= htmlspecialchars($row['jam_masuk'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" data-jam-pulang="<?= htmlspecialchars($row['jam_pulang'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" data-lokasi-masuk="<?= htmlspecialchars($row['lokasi_masuk'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-lokasi-pulang="<?= htmlspecialchars($row['lokasi_pulang'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium bg-fuchsia-50 text-fuchsia-700 border border-fuchsia-200 hover:bg-fuchsia-100 dark:bg-fuchsia-900/30 dark:text-fuchsia-400 dark:border-fuchsia-800/50">
                                            <i class="fa-solid fa-camera"></i> Bukti Foto
                                        </button>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400"><?= $capture_ready ? 'Belum ada foto' : 'Foto belum aktif' ?></span>
                                    <?php endif; ?>
                                    <?php if ($is_admin): ?>
                                    <button type="button" onclick="openEditAbsensiModal(this)" data-absen='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES); ?>' class="p-2 text-fuchsia-600 hover:bg-fuchsia-50 rounded-lg dark:text-fuchsia-400 dark:hover:bg-fuchsia-900/30 transition-colors border border-transparent hover:border-fuchsia-200 dark:hover:border-fuchsia-800" title="Edit Data">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="fa-solid fa-folder-open text-4xl mb-3 opacity-20"></i>
                                    <p>Belum ada data absensi.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Controls -->
        <div class="px-5 py-4 border-t border-slate-200 dark:border-slate-700 flex flex-col md:flex-row justify-between items-center gap-4 bg-slate-50 dark:bg-slate-900/50">
            <div class="flex flex-col sm:flex-row items-center gap-4 w-full md:w-auto justify-between sm:justify-start">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-slate-500 dark:text-slate-400">Tampilkan</span>
                    <select id="rowsPerPage" onchange="updateRowsPerPage()" class="px-2.5 py-1.5 border border-slate-200 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 outline-none focus:border-brand-500 cursor-pointer shadow-sm">
                        <option value="5" selected>5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="text-sm text-slate-500 dark:text-slate-400">baris</span>
                </div>
                <div class="text-sm text-slate-500 dark:text-slate-400" id="tableInfo">
                    Menampilkan 0 hingga 0 dari 0 data
                </div>
            </div>
            <div class="flex items-center gap-2 overflow-x-auto w-full md:w-auto justify-center md:justify-end pb-1 md:pb-0" id="paginationControls">
                <!-- Buttons will be rendered here by JS -->
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: DETAIL ALASAN & FOTO                -->
<!-- ========================================== -->
<div id="modal-detail-alasan" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden flex flex-col">
        
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-900/50">
            <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="fa-solid fa-file-lines text-fuchsia-500"></i> Detail Keterangan
            </h3>
            <button type="button" onclick="closeModal('modal-detail-alasan')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        
        <div class="p-6">
            <div class="mb-4">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 uppercase tracking-wider">Alasan</label>
                <div id="detail-alasan-text" class="p-3 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-slate-700 dark:text-slate-300 min-h-[60px]">
                    <!-- Text alasan akan muncul di sini -->
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 uppercase tracking-wider">Foto Bukti</label>
                <div id="detail-alasan-foto-container" class="rounded-xl border border-slate-200 dark:border-slate-700 p-2 bg-slate-50 dark:bg-slate-900/50 text-center flex flex-col items-center">
                    <img id="detail-alasan-foto" src="" alt="Foto Bukti" class="max-w-full max-h-[300px] rounded-lg mx-auto hidden cursor-pointer mb-2" onclick="window.open(this.src, '_blank')">
                    <p id="detail-alasan-no-foto" class="text-sm text-slate-500 dark:text-slate-400 py-4 hidden w-full">
                        <i class="fa-solid fa-image-slash text-2xl mb-2 opacity-50 block"></i>
                        Tidak ada lampiran foto
                    </p>
                    <a id="detail-lokasi-btn" href="#" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-slate-50 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-medium rounded-lg transition-colors border border-slate-200 dark:border-slate-600 shadow-sm mt-2 hidden">
                        <i class="fa-solid fa-map-location-dot text-brand-500"></i> Lihat Lokasi Absen
                    </a>
                </div>
            </div>
            
            <div class="mt-6 flex justify-end">
                <button type="button" onclick="closeModal('modal-detail-alasan')" class="px-5 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors font-medium text-sm">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<?php if ($is_admin): ?>
<!-- MODAL: PERSETUJUAN DINAS LUAR              -->
<!-- ========================================== -->
<div id="modal-persetujuan-dinas" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden flex flex-col">
        
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-900/50">
            <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="fa-solid fa-briefcase text-brand-500"></i> Persetujuan Dinas Luar
            </h3>
            <button type="button" onclick="closeModal('modal-persetujuan-dinas')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto max-h-[70vh]">
            <form action="proses_persetujuan_dinas.php" method="POST" id="form-persetujuan-dinas">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="id_absensi" id="dinas-id-absensi">
                <input type="hidden" name="action" id="dinas-action">
                <input type="hidden" name="redirect_url" value="histori_absensi.php?cabang=<?php echo $id_cabang; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                
                <div class="mb-4">
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Permintaan dari: <strong id="dinas-nama-karyawan" class="text-slate-800 dark:text-white"></strong> pada <span id="dinas-waktu"></span></p>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 uppercase tracking-wider">Keterangan Dinas</label>
                    <div id="dinas-alasan-text-view" class="p-3 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-slate-700 dark:text-slate-300 min-h-[60px]">
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 uppercase tracking-wider">Foto Bukti</label>
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-2 bg-slate-50 dark:bg-slate-900/50 text-center flex flex-col items-center">
                        <img id="dinas-foto-view" src="" alt="Foto Bukti" class="max-w-full max-h-[250px] rounded-lg mx-auto cursor-pointer mb-2" onclick="window.open(this.src, '_blank')">
                        <a id="dinas-lokasi-btn" href="#" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-slate-50 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-medium rounded-lg transition-colors border border-slate-200 dark:border-slate-600 shadow-sm mt-2 hidden">
                            <i class="fa-solid fa-map-location-dot text-brand-500"></i> Lihat Lokasi Absen
                        </a>
                    </div>
                </div>
                
                <div class="flex items-center gap-3 w-full">
                    <button type="button" onclick="submitPersetujuanDinas('tolak')" class="flex-1 py-2.5 bg-rose-50 text-rose-600 hover:bg-rose-100 dark:bg-rose-900/30 dark:text-rose-400 dark:hover:bg-rose-900/50 rounded-xl font-medium transition-colors text-sm border border-rose-200 dark:border-rose-800/50 shadow-sm">
                        <i class="fa-solid fa-xmark mr-1"></i> Tolak
                    </button>
                    <button type="button" onclick="submitPersetujuanDinas('acc')" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl font-medium transition-colors text-sm shadow-sm">
                        <i class="fa-solid fa-check mr-1"></i> ACC (Setujui)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: TAMBAH ABSENSI MANUAL               -->
<!-- ========================================== -->
<div id="modal-tambah-manual" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden flex flex-col">
        
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-900/50">
            <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="fa-solid fa-plus-circle text-brand-500"></i> Tambah Absensi Manual
            </h3>
            <button type="button" onclick="closeModal('modal-tambah-manual')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        
        <div class="p-6">
            <div class="bg-fuchsia-50 dark:bg-fuchsia-900/20 text-fuchsia-700 dark:text-fuchsia-300 p-3 rounded-xl mb-5 text-sm flex items-start gap-3 border border-fuchsia-100 dark:border-fuchsia-800/30">
                <i class="fa-solid fa-info-circle mt-0.5"></i>
                <p>Fitur ini untuk menambahkan status OFF/Sakit/Cuti pada tanggal yang sudah lewat. Tidak bisa untuk status "Hadir" karena harus melalui scan QR + Pengenalan Wajah.</p>
            </div>
            
            <form action="tambah_absensi_manual.php" method="POST" id="form-tambah-manual" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="redirect_cabang" value="<?php echo $id_cabang; ?>">
                
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Pilih Karyawan <span class="text-red-500">*</span></label>
                    <select name="id_karyawan" id="manual-id-karyawan" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-brand-500 outline-none text-sm">
                        <option value="">-- Pilih Karyawan --</option>
                        <?php
                        if ($id_cabang > 0) {
                            $stmt_karyawan = $conn->prepare("SELECT id_karyawan, nama_karyawan FROM karyawan WHERE id_cabang = ? ORDER BY nama_karyawan ASC");
                            $stmt_karyawan->bind_param("i", $id_cabang);
                            $stmt_karyawan->execute();
                            $karyawan_list = $stmt_karyawan->get_result();
                            while ($karyawan = $karyawan_list->fetch_assoc()):
                            ?>
                                <option value="<?php echo htmlspecialchars($karyawan['id_karyawan']); ?>">
                                    <?php echo htmlspecialchars($karyawan['nama_karyawan']); ?> (<?php echo htmlspecialchars($karyawan['id_karyawan']); ?>)
                                </option>
                            <?php 
                            endwhile; 
                            $stmt_karyawan->close(); 
                        }
                        ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Tanggal <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal" id="manual-tanggal" max="<?php echo date('Y-m-d'); ?>" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-brand-500 outline-none text-sm">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Status Absensi <span class="text-red-500">*</span></label>
                    <select name="keterangan" id="manual-keterangan" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-brand-500 outline-none text-sm">
                        <option value="">-- Pilih Status --</option>
                        <option value="OFF">OFF (Libur)</option>
                        <option value="Sakit">Sakit</option>
                        <option value="Cuti">Cuti</option>
                        <option value="Dinas Luar">Dinas Luar</option>
                        <option value="Menikah">Menikah (Cuti Khusus)</option>
                        <option value="Menikahkan Anak">Menikahkan Anak (Cuti Khusus)</option>
                        <option value="Melahirkan">Cuti Melahirkan (Cuti Khusus)</option>
                        <option value="Duka Cita">Duka Cita (Cuti Khusus)</option>
                    </select>
                </div>

                <div class="pt-4 border-t border-slate-100 dark:border-slate-700 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modal-tambah-manual')" class="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium text-sm">Batal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl font-medium transition-colors text-sm shadow-sm"><i class="fa-solid fa-save mr-1"></i> Simpan Absensi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: KELOLA CUTI BERSAMA                 -->
<!-- ========================================== -->
<div id="modal-kelola-cuti-bersama" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden flex flex-col">
        
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-900/50">
            <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="fa-solid fa-calendar-day text-teal-500"></i> Kelola Cuti Bersama
            </h3>
            <button type="button" onclick="closeModal('modal-kelola-cuti-bersama')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        
        <div class="p-6">
            <!-- Filter Status di Modal -->
            <div class="bg-slate-100 dark:bg-slate-800/80 p-1.5 rounded-xl border border-slate-200 dark:border-slate-700 w-full mb-5 mx-auto">
                <div class="relative flex w-full">
                    <!-- Sliding Background Pill -->
                    <div id="kelolaCutiPill" class="absolute inset-y-0 left-0 w-1/2 bg-teal-600 rounded-lg shadow-md shadow-teal-500/30 border border-teal-600 transition-transform duration-300 ease-in-out" style="transform: translateX(0);"></div>
                    
                    <button type="button" id="btnTabTambahCuti" onclick="switchTabKelolaCuti('tambah')" class="relative z-10 flex-1 py-1.5 text-center text-sm text-white font-bold transition-colors duration-300">Tambah Cuti</button>
                    <button type="button" id="btnTabBatalCuti" onclick="switchTabKelolaCuti('batal')" class="relative z-10 flex-1 py-1.5 text-center text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300 font-semibold transition-colors duration-300">Batalkan Cuti</button>
                </div>
            </div>

            <!-- TAB: Tambah Cuti Bersama -->
            <div id="tabTambahCuti" class="block space-y-4">
                <div class="bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-300 p-3 rounded-xl text-sm flex items-start gap-3 border border-teal-100 dark:border-teal-800/30">
                    <i class="fa-solid fa-info-circle mt-0.5"></i>
                    <p>Fitur ini digunakan untuk memasukkan status OFF/Cuti kepada banyak karyawan sekaligus dalam rentang tanggal tertentu (Bulk Insert).</p>
                </div>
                
                <form action="tambah_libur_bersama.php" method="POST" id="form-cuti-bersama" class="space-y-4" onsubmit="return handleCutiBersama(event);">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="redirect_cabang" value="<?php echo $id_cabang; ?>">
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Target Cabang <span class="text-red-500">*</span></label>
                        <select name="id_cabang_target" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-teal-500 outline-none text-sm">
                            <option value="all">-- Semua Cabang --</option>
                            <?php
                            $stmt_cabang = $conn->prepare("SELECT id, nama_cabang FROM cabang ORDER BY nama_cabang ASC");
                            $stmt_cabang->execute();
                            $cabang_lists = $stmt_cabang->get_result();
                            while ($cb = $cabang_lists->fetch_assoc()):
                            ?>
                                <option value="<?php echo htmlspecialchars($cb['id']); ?>">
                                    <?php echo htmlspecialchars($cb['nama_cabang']); ?>
                                </option>
                            <?php 
                            endwhile; 
                            $stmt_cabang->close(); 
                            ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Dari Tanggal <span class="text-red-500">*</span></label>
                            <input type="date" name="tanggal_mulai" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-teal-500 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Sampai Tanggal <span class="text-red-500">*</span></label>
                            <input type="date" name="tanggal_selesai" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-teal-500 outline-none text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Status Absensi <span class="text-red-500">*</span></label>
                        <select name="keterangan" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-teal-500 outline-none text-sm">
                            <option value="OFF">OFF (Libur)</option>
                            <option value="Cuti">Cuti</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Keterangan / Catatan Bebas <span class="text-xs text-slate-400 font-normal">(Opsional)</span></label>
                        <input type="text" name="catatan_bebas" placeholder="Misal: Libur Idul Fitri" class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-teal-500 outline-none text-sm">
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-700 flex justify-end gap-3">
                        <button type="button" onclick="closeModal('modal-kelola-cuti-bersama')" class="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium text-sm">Tutup</button>
                        <button type="submit" class="px-6 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-xl font-medium transition-colors text-sm shadow-sm"><i class="fa-solid fa-save mr-1"></i> Simpan Bulk</button>
                    </div>
                </form>
            </div>

            <!-- TAB: Batalkan Cuti Bersama -->
            <div id="tabBatalCuti" class="hidden space-y-4">
                <div class="bg-rose-50 dark:bg-rose-900/20 text-rose-700 dark:text-rose-300 p-3 rounded-xl text-sm flex items-start gap-3 border border-rose-100 dark:border-rose-800/30">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <p>Fitur ini digunakan untuk <b>menghapus</b> data Cuti Bersama (Bulk Insert) pada rentang tanggal tertentu yang mungkin salah diinputkan.</p>
                </div>
                
                <form action="hapus_libur_bersama.php" method="POST" id="form-batal-cuti-bersama" class="space-y-4" onsubmit="return handleBatalCutiBersama(event);">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="redirect_cabang" value="<?php echo $id_cabang; ?>">
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Target Cabang yang Dihapus <span class="text-red-500">*</span></label>
                        <select name="id_cabang_target" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-rose-500 outline-none text-sm">
                            <option value="all">-- Semua Cabang --</option>
                            <?php
                            $stmt_cabang = $conn->prepare("SELECT id, nama_cabang FROM cabang ORDER BY nama_cabang ASC");
                            $stmt_cabang->execute();
                            $cabang_lists = $stmt_cabang->get_result();
                            while ($cb = $cabang_lists->fetch_assoc()):
                            ?>
                                <option value="<?php echo htmlspecialchars($cb['id']); ?>">
                                    <?php echo htmlspecialchars($cb['nama_cabang']); ?>
                                </option>
                            <?php 
                            endwhile; 
                            $stmt_cabang->close(); 
                            ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Dari Tanggal <span class="text-red-500">*</span></label>
                            <input type="date" name="tanggal_mulai" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-rose-500 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Sampai Tanggal <span class="text-red-500">*</span></label>
                            <input type="date" name="tanggal_selesai" required class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-rose-500 outline-none text-sm">
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-700 flex justify-end gap-3">
                        <button type="button" onclick="closeModal('modal-kelola-cuti-bersama')" class="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium text-sm">Tutup</button>
                        <button type="submit" class="px-6 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-medium transition-colors text-sm shadow-sm"><i class="fa-solid fa-trash mr-1"></i> Hapus Bulk Data</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>


<!-- ========================================== -->
<!-- MODAL: EDIT ABSENSI (Koreksi Jam)          -->
<!-- ========================================== -->
<div id="modal-edit-absensi" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden flex flex-col">
        
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-900/50">
            <h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-fuchsia-500"></i> Edit Data Absensi
            </h3>
            <button type="button" onclick="closeModal('modal-edit-absensi')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto max-h-[80vh]">
            <form action="update_absensi.php" method="POST" id="form-edit-absensi" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="id_absensi" id="edit-id-absensi">
                <input type="hidden" name="redirect_cabang" value="<?php echo $id_cabang; ?>">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Nama Karyawan</label>
                        <input type="text" id="edit-nama-karyawan" readonly class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-slate-100 dark:bg-slate-900 text-slate-500 dark:text-slate-400 focus:outline-none text-sm cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Tanggal</label>
                        <input type="date" id="edit-tanggal" readonly class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-slate-100 dark:bg-slate-900 text-slate-500 dark:text-slate-400 focus:outline-none text-sm cursor-not-allowed">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Jam Masuk</label>
                        <input type="time" name="jam_masuk" id="edit-jam-masuk" step="1" class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-fuchsia-500 outline-none text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Jam Pulang</label>
                        <input type="time" name="jam_keluar" id="edit-jam-keluar" step="1" class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-fuchsia-500 outline-none text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Keterangan / Alasan</label>
                    <select name="keterangan" id="edit-keterangan" onchange="toggleStatusFields()" class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-fuchsia-500 outline-none text-sm">
                        <option value="Hadir">Hadir</option>
                        <option value="OFF">OFF</option>
                        <option value="Sakit">Sakit</option>
                        <option value="Cuti">Cuti</option>
                        <option value="Alpha">Alpha</option>
                        <option value="Dinas Luar">Dinas Luar</option>
                        <option value="Menikah">Menikah (Cuti Khusus)</option>
                        <option value="Menikahkan Anak">Menikahkan Anak (Cuti Khusus)</option>
                        <option value="Melahirkan">Cuti Melahirkan (Cuti Khusus)</option>
                        <option value="Duka Cita">Duka Cita (Cuti Khusus)</option>
                    </select>
                </div>

                <div id="status-masuk-group">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Status Masuk</label>
                    <select name="status_masuk" id="edit-status-masuk" class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-fuchsia-500 outline-none text-sm">
                        <option value="Tepat Waktu">Tepat Waktu</option>
                        <option value="Terlambat">Terlambat</option>
                    </select>
                </div>

                <div id="status-pulang-group" style="display: none;">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Status Pulang</label>
                    <select name="status_pulang" id="edit-status-pulang" class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:ring-2 focus:ring-fuchsia-500 outline-none text-sm">
                        <option value="">- Otomatis Dihitung -</option>
                        <option value="Setengah Hari">Setengah Hari</option>
                        <option value="Normal">Normal</option>
                        <option value="Over Time">Over Time</option>
                    </select>
                </div>

                <div class="pt-4 border-t border-slate-100 dark:border-slate-700 flex justify-end gap-3 mt-4">
                    <button type="button" onclick="closeModal('modal-edit-absensi')" class="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium text-sm">Batal</button>
                    <button type="submit" class="px-6 py-2 bg-fuchsia-600 hover:bg-fuchsia-700 text-white rounded-xl font-medium transition-colors text-sm shadow-sm"><i class="fa-solid fa-save mr-1"></i> Update Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<div id="modal-capture-absensi" role="dialog" aria-modal="true" aria-labelledby="capture-title" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto border border-slate-200 dark:border-slate-700">
        <div class="p-5 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center">
            <div><h3 id="capture-title" class="text-lg font-bold text-slate-800 dark:text-white">Bukti Foto Absensi</h3><p id="capture-karyawan" class="text-sm text-slate-500 dark:text-slate-400"></p></div>
            <button type="button" onclick="closeModal('modal-capture-absensi')" aria-label="Tutup bukti foto" class="p-2 text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-5">
            <?php foreach (['masuk' => 'Masuk', 'pulang' => 'Pulang'] as $jenis => $label): ?>
                <section><h4 class="font-semibold text-slate-800 dark:text-white"><?= $label ?> <span id="capture-jam-<?= $jenis ?>" class="text-sm font-normal"></span></h4>
                    <a id="capture-link-<?= $jenis ?>" target="_blank" rel="noopener" class="hidden block mt-3" title="Buka foto ukuran penuh"><img id="capture-img-<?= $jenis ?>" alt="Bukti foto <?= strtolower($label) ?>" class="w-full rounded-xl object-contain max-h-80"></a>
                    <p id="capture-empty-<?= $jenis ?>" class="py-8 text-center text-sm text-slate-400">Belum ada foto</p>
                    <a id="capture-lokasi-<?= $jenis ?>" href="#" target="_blank" rel="noopener" class="hidden items-center gap-1.5 px-3 py-1.5 mt-3 bg-white hover:bg-slate-50 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-medium rounded-lg transition-colors border border-slate-200 dark:border-slate-600 shadow-sm">
                        <i class="fa-solid fa-map-location-dot text-brand-500"></i> Lihat Lokasi Absen
                    </a>
                </section>
            <?php endforeach; ?>
        </div>
        <div class="p-4 border-t border-slate-200 dark:border-slate-700 text-right"><button type="button" onclick="closeModal('modal-capture-absensi')" class="px-5 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-sm">Tutup</button></div>
    </div>
</div>

<!-- Hidden Form Export Excel -->
<form id="exportForm" method="POST" action="export_absensi.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="format" value="excel">
    <input type="hidden" name="cabang" value="<?php echo htmlspecialchars($nama_cabang); ?>">
    <input type="hidden" name="id_cabang" value="<?php echo (int)$id_cabang; ?>">
    <input type="hidden" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>">
    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
</form>

<!-- Hidden Form Konversi Izin Setengah Hari -->
<form id="formKonversiSetengahHari" method="POST" action="proses_pengajuan_izin.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="konversi_setengah_hari" value="1">
    <input type="hidden" name="id_absensi" id="inKonversiIdAbsensi" value="">
    <input type="hidden" name="id_cabang" value="<?php echo (int)$id_cabang; ?>">
    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
</form>

<script>
function openCaptureModal(button) {
    const data = button.dataset;
    document.getElementById('capture-karyawan').textContent = data.nama + ' · ' + data.tanggal;
    for (const jenis of ['masuk', 'pulang']) {
        const link = document.getElementById('capture-link-' + jenis);
        const img = document.getElementById('capture-img-' + jenis);
        const empty = document.getElementById('capture-empty-' + jenis);
        const lokasiButton = document.getElementById('capture-lokasi-' + jenis);
        const lokasi = data[jenis === 'masuk' ? 'lokasiMasuk' : 'lokasiPulang'];
        document.getElementById('capture-jam-' + jenis).textContent = data[jenis === 'masuk' ? 'jamMasuk' : 'jamPulang'] || '-';
        img.removeAttribute('src');
        link.removeAttribute('href');
        link.classList.add('hidden');
        empty.textContent = 'Belum ada foto';
        empty.classList.remove('hidden');
        lokasiButton.removeAttribute('href');
        lokasiButton.classList.add('hidden');
        lokasiButton.classList.remove('inline-flex');
        if (lokasi && lokasi !== 'Lokasi tidak terdeteksi') {
            lokasiButton.href = 'https://www.google.com/maps?q=' + encodeURIComponent(lokasi);
            lokasiButton.classList.remove('hidden');
            lokasiButton.classList.add('inline-flex');
        }
        if (data[jenis] === '1') {
            const url = 'foto_capture_absensi.php?id=' + encodeURIComponent(data.id) + '&jenis=' + jenis;
            img.onerror = () => {
                if (img.getAttribute('src') !== url) return;
                link.classList.add('hidden');
                empty.textContent = 'Foto tidak dapat dimuat. Tutup lalu coba lagi.';
                empty.classList.remove('hidden');
            };
            img.src = url;
            link.href = url;
            link.classList.remove('hidden');
            empty.classList.add('hidden');
        }
    }
    openModal('modal-capture-absensi');
}

function exportAbsensiExcel() {
    document.getElementById('exportForm').submit();
}

function konversiSetengahHari(idAbsensi, nama) {
    Swal.fire({
        title: 'Konversi ke Izin Setengah Hari?',
        html: `Baris "Tidak Absen Masuk" milik <b>${nama}</b> akan ditandai sebagai izin setengah hari.<br><br>Maksimal 3x per bulan per karyawan - sistem menolak otomatis kalau batas ini sudah tercapai.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Konversi',
        cancelButtonText: 'Batal',
        reverseButtons: true,
        customClass: { popup: 'rounded-3xl' }
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('inKonversiIdAbsensi').value = idAbsensi;
            document.getElementById('formKonversiSetengahHari').submit();
        }
    });
}

// DataTables JS Pagination
const tableBody = document.getElementById('tableBody');
let currentPage = 1;
let rowsPerPage = 5;
let trs = Array.from(tableBody.querySelectorAll('tr'));

function updateRowsPerPage() {
    rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
    initPagination();
}

function initPagination() {
    if (trs.length === 0 || (trs.length === 1 && trs[0].querySelector('td[colspan]'))) {
        document.getElementById('tableInfo').textContent = 'Menampilkan 0 hingga 0 dari 0 data';
        document.getElementById('paginationControls').innerHTML = '';
        return;
    }
    goToPage(1);
}

function goToPage(page) {
    const totalEntries = trs.length;
    const totalPages = Math.ceil(totalEntries / rowsPerPage);
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    currentPage = page;

    const startIndex = (currentPage - 1) * rowsPerPage;
    const endIndex = Math.min(startIndex + rowsPerPage, totalEntries);

    trs.forEach((tr, index) => {
        if (index >= startIndex && index < endIndex) {
            tr.style.display = '';
        } else {
            tr.style.display = 'none';
        }
    });

    const infoSpan = document.getElementById('tableInfo');
    infoSpan.textContent = `Menampilkan ${startIndex + 1} hingga ${endIndex} dari ${totalEntries} data`;

    const paginationControls = document.getElementById('paginationControls');
    paginationControls.innerHTML = '';

    if (totalPages > 1) {
        const prevBtn = document.createElement('button');
        prevBtn.className = `px-3 py-1 border border-slate-200 dark:border-slate-700 rounded-md bg-white dark:bg-slate-800 text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors ${currentPage === 1 ? 'opacity-50 cursor-not-allowed' : ''}`;
        prevBtn.textContent = 'Previous';
        prevBtn.disabled = currentPage === 1;
        prevBtn.onclick = () => goToPage(currentPage - 1);
        paginationControls.appendChild(prevBtn);

        for (let i = 1; i <= totalPages; i++) {
            if (totalPages > 7) {
                if (i !== 1 && i !== totalPages && (i < currentPage - 1 || i > currentPage + 1)) {
                    if (i === currentPage - 2 || i === currentPage + 2) {
                        const ellipsis = document.createElement('span');
                        ellipsis.className = 'px-2 py-1 text-slate-500';
                        ellipsis.innerHTML = '&hellip;';
                        paginationControls.appendChild(ellipsis);
                    }
                    continue;
                }
            }

            const pageBtn = document.createElement('button');
            if (i === currentPage) {
                pageBtn.className = 'px-3 py-1 border border-brand-500 bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-400 rounded-md font-medium';
            } else {
                pageBtn.className = 'px-3 py-1 border border-slate-200 dark:border-slate-700 rounded-md bg-white dark:bg-slate-800 text-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors';
            }
            pageBtn.textContent = i;
            pageBtn.onclick = () => goToPage(i);
            paginationControls.appendChild(pageBtn);
        }

        const nextBtn = document.createElement('button');
        nextBtn.className = `px-3 py-1 border border-slate-200 dark:border-slate-700 rounded-md bg-white dark:bg-slate-800 text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors ${currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : ''}`;
        nextBtn.textContent = 'Next';
        nextBtn.disabled = currentPage === totalPages;
        nextBtn.onclick = () => goToPage(currentPage + 1);
        paginationControls.appendChild(nextBtn);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initPagination();
});

// Modal Logic
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function openDetailAlasanModal(button) {
    const alasan = button.getAttribute('data-alasan');
    const foto = button.getAttribute('data-foto');
    const lokasi = button.getAttribute('data-lokasi');
    
    document.getElementById('detail-alasan-text').textContent = alasan || '- Tidak ada keterangan yang ditulis -';
    
    const fotoImg = document.getElementById('detail-alasan-foto');
    const noFoto = document.getElementById('detail-alasan-no-foto');
    const btnLokasi = document.getElementById('detail-lokasi-btn');
    
    if (foto) {
        fotoImg.src = 'assets/uploads/absensi/' + foto;
        fotoImg.classList.remove('hidden');
        noFoto.classList.add('hidden');
    } else {
        fotoImg.src = '';
        fotoImg.classList.add('hidden');
        noFoto.classList.remove('hidden');
    }
    
    if (lokasi && lokasi !== 'Lokasi tidak terdeteksi') {
        btnLokasi.href = `https://www.google.com/maps?q=${lokasi}`;
        btnLokasi.classList.remove('hidden');
    } else {
        btnLokasi.classList.add('hidden');
    }
    
    openModal('modal-detail-alasan');
}

function openApprovalDinasModal(button) {
    const id = button.getAttribute('data-id');
    const nama = button.getAttribute('data-nama');
    const alasan = button.getAttribute('data-alasan');
    const foto = button.getAttribute('data-foto');
    const waktu = button.getAttribute('data-waktu');
    const lokasi = button.getAttribute('data-lokasi');
    
    document.getElementById('dinas-id-absensi').value = id;
    document.getElementById('dinas-nama-karyawan').textContent = nama;
    document.getElementById('dinas-waktu').textContent = waktu;
    document.getElementById('dinas-alasan-text-view').textContent = alasan || '- Tidak ada keterangan yang ditulis -';
    
    const fotoImg = document.getElementById('dinas-foto-view');
    const btnLokasi = document.getElementById('dinas-lokasi-btn');
    
    if (foto) {
        fotoImg.src = 'assets/uploads/absensi/' + foto;
        fotoImg.classList.remove('hidden');
    } else {
        fotoImg.src = '';
        fotoImg.classList.add('hidden');
    }
    
    if (lokasi && lokasi !== 'Lokasi tidak terdeteksi') {
        btnLokasi.href = `https://www.google.com/maps?q=${lokasi}`;
        btnLokasi.classList.remove('hidden');
    } else {
        btnLokasi.classList.add('hidden');
    }
    
    openModal('modal-persetujuan-dinas');
}

function submitPersetujuanDinas(action) {
    let title = action === 'tolak' ? 'Tolak Permintaan?' : 'Setujui Permintaan?';
    let text = action === 'tolak' 
        ? 'Apakah Anda yakin ingin menolak permintaan dinas ini? Data absensi ini akan ditolak dan dihapus.' 
        : 'Apakah Anda yakin ingin menyetujui permintaan dinas ini?';
    let confirmBtnText = action === 'tolak' ? 'Ya, Tolak!' : 'Ya, Setujui!';
    let confirmBtnColor = action === 'tolak' ? '#ef4444' : '#a21caf'; // Red for reject, Blue for approve

    Swal.fire({
        title: title,
        text: text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: confirmBtnColor,
        cancelButtonColor: '#94a3b8',
        confirmButtonText: confirmBtnText,
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Memproses...',
                text: 'Mohon tunggu sebentar',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            document.getElementById('dinas-action').value = action;
            document.getElementById('form-persetujuan-dinas').submit();
        }
    });
}

function toggleStatusFields() {
    const ket = document.getElementById('edit-keterangan').value;
    const jamPulang = document.getElementById('edit-jam-keluar').value;
    const statusMasukGroup = document.getElementById('status-masuk-group');
    const statusPulangGroup = document.getElementById('status-pulang-group');
    
    if (ket === 'Hadir') {
        statusMasukGroup.style.display = 'block';
    } else {
        statusMasukGroup.style.display = 'none';
    }
    
    if (jamPulang && jamPulang !== '00:00:00' && jamPulang !== '') {
        statusPulangGroup.style.display = 'block';
    } else {
        statusPulangGroup.style.display = 'none';
    }
}

// Initial binding
const editJamKeluar = document.getElementById('edit-jam-keluar');
if(editJamKeluar) editJamKeluar.addEventListener('change', toggleStatusFields);

function openEditAbsensiModal(button) {
    const data = JSON.parse(button.getAttribute('data-absen'));
    
    document.getElementById('edit-id-absensi').value = data.id;
    document.getElementById('edit-nama-karyawan').value = data.nama_karyawan;
    document.getElementById('edit-tanggal').value = data.tanggal;
    document.getElementById('edit-jam-masuk').value = data.jam_masuk;
    document.getElementById('edit-jam-keluar').value = data.jam_pulang || '';
    document.getElementById('edit-keterangan').value = data.keterangan;
    document.getElementById('edit-status-masuk').value = data.status_masuk || 'Tepat Waktu';
    
    const statusPulangSelect = document.getElementById('edit-status-pulang');
    if (statusPulangSelect && data.status_pulang) {
        statusPulangSelect.value = data.status_pulang;
    }
    
    toggleStatusFields();
    openModal('modal-edit-absensi');
}

// Custom AJAX Submit for Tambah Manual to support dynamic SweetAlert text
document.addEventListener('DOMContentLoaded', () => {
    const tambahManualForm = document.getElementById('form-tambah-manual');
    if (tambahManualForm) {
        tambahManualForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const karyawan = document.getElementById('manual-id-karyawan');
            const tanggal = document.getElementById('manual-tanggal');
            const keterangan = document.getElementById('manual-keterangan');
            
            const karyawanText = karyawan.options[karyawan.selectedIndex].text;
            const tanggalFormatted = new Date(tanggal.value).toLocaleDateString('id-ID', {
                day: '2-digit', month: 'long', year: 'numeric'
            });
            
            const htmlMessage = `
                <div class="text-left text-sm space-y-2 mt-4">
                    <p><strong>Karyawan:</strong> ${karyawanText}</p>
                    <p><strong>Tanggal:</strong> ${tanggalFormatted}</p>
                    <p><strong>Status:</strong> <span class="bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded font-medium">${keterangan.value}</span></p>
                    <p class="mt-4 text-brand-600 dark:text-brand-400 font-medium">Apakah data sudah benar?</p>
                </div>
            `;
            
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Konfirmasi Absensi Manual',
                    html: htmlMessage,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#10b981',
                    cancelButtonColor: '#94a3b8',
                    confirmButtonText: 'Ya, Simpan!',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Menyimpan...',
                            text: 'Mohon tunggu sebentar',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        const formData = new FormData(this);
                        formData.append('is_ajax', '1');
                        
                        fetch(this.action, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: data.message,
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: data.message
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            Swal.fire({
                                icon: 'error',
                                title: 'Terjadi Kesalahan!',
                                text: 'Gagal terhubung ke server.'
                            });
                        });
                    }
                });
            } else {
                if (confirm(`Karyawan: ${karyawanText}\nTanggal: ${tanggalFormatted}\nStatus: ${keterangan.value}\n\nApakah data sudah benar?`)) {
                    this.submit();
                }
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const editForm = document.getElementById('form-edit-absensi');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('is_ajax', '1');
            
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Menyimpan...',
                    text: 'Mohon tunggu sebentar',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            }
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil!',
                            text: data.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            closeModal('modal-edit-absensi');
                        });
                    } else {
                        alert(data.message);
                        closeModal('modal-edit-absensi');
                    }
                    
                    // Update tampilan row secara dinamis
                    const idAbsensi = document.getElementById('edit-id-absensi').value;
                    const jamMasuk = document.getElementById('edit-jam-masuk').value;
                    const jamKeluar = document.getElementById('edit-jam-keluar').value;
                    const keterangan = document.getElementById('edit-keterangan').value;
                    
                    // Kita bisa biarkan tabel seperti apa adanya untuk mengurangi kompleksitas,
                    // atau cukup reload dengan parameter yang sama agar filter tidak hilang.
                    // Karena user minta "tanpa harus merubah halaman", kita update tombol editnya aja
                    // Tapi untuk mempermudah, karena table state tidak berubah, kita cukup tutup modalnya saja.
                    
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal!',
                            text: data.message
                        });
                    } else {
                        alert('Gagal: ' + data.message);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Terjadi Kesalahan!',
                        text: 'Gagal terhubung ke server.'
                    });
                } else {
                    alert('Terjadi kesalahan koneksi.');
                }
            });
        });
    }
});
function handleCutiBersama(e) {
    e.preventDefault();
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Apakah Anda Yakin?',
            html: 'Anda akan memasukkan data absensi secara massal (Bulk Insert) ke banyak karyawan untuk rentang tanggal yang dipilih.<br><br>Apakah Anda yakin data (Cabang, Tanggal, dan Status) yang dimasukkan sudah benar?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0d9488',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Oke, Simpan',
            cancelButtonText: 'Batal',
            customClass: {
                popup: 'rounded-3xl'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('form-cuti-bersama').submit();
            }
        });
    } else {
        if(confirm('⚠️ PERINGATAN: Anda akan memasukkan data absensi secara massal (Bulk Insert) ke banyak karyawan untuk rentang tanggal yang dipilih.\n\nApakah Anda yakin data (Cabang, Tanggal, dan Status) yang dimasukkan sudah benar?')) {
            document.getElementById('form-cuti-bersama').submit();
        }
    }
    return false;
}

function handleBatalCutiBersama(e) {
    e.preventDefault();
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Batalkan Cuti Bersama?',
            html: 'Tindakan ini akan <b>MENGHAPUS secara permanen</b> semua data absen berstatus OFF/Cuti (hasil input manual) di rentang tanggal tersebut.<br><br>Lanjutkan penghapusan?',
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus Data',
            cancelButtonText: 'Batal',
            customClass: {
                popup: 'rounded-3xl'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('form-batal-cuti-bersama').submit();
            }
        });
    } else {
        if(confirm('🚨 PERHATIAN: Tindakan ini akan MENGHAPUS secara permanen semua data absen berstatus OFF/Cuti (hasil input manual) di rentang tanggal tersebut.\n\nLanjutkan penghapusan?')) {
            document.getElementById('form-batal-cuti-bersama').submit();
        }
    }
    return false;
}
function switchTabKelolaCuti(tab) {
    const pill = document.getElementById('kelolaCutiPill');
    const btnTambah = document.getElementById('btnTabTambahCuti');
    const btnBatal = document.getElementById('btnTabBatalCuti');
    const tabTambah = document.getElementById('tabTambahCuti');
    const tabBatal = document.getElementById('tabBatalCuti');

    if (tab === 'tambah') {
        pill.style.transform = 'translateX(0)';
        pill.className = 'absolute inset-y-0 left-0 w-1/2 bg-teal-600 rounded-lg shadow-md shadow-teal-500/30 border border-teal-600 transition-transform duration-300 ease-in-out';
        
        btnTambah.className = 'relative z-10 flex-1 py-1.5 text-center text-sm text-white font-bold transition-colors duration-300';
        btnBatal.className = 'relative z-10 flex-1 py-1.5 text-center text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300 font-semibold transition-colors duration-300';
        
        tabTambah.classList.remove('hidden');
        tabTambah.classList.add('block');
        tabBatal.classList.remove('block');
        tabBatal.classList.add('hidden');
    } else {
        pill.style.transform = 'translateX(100%)';
        pill.className = 'absolute inset-y-0 left-0 w-1/2 bg-rose-600 rounded-lg shadow-md shadow-rose-500/30 border border-rose-600 transition-transform duration-300 ease-in-out';
        
        btnBatal.className = 'relative z-10 flex-1 py-1.5 text-center text-sm text-white font-bold transition-colors duration-300';
        btnTambah.className = 'relative z-10 flex-1 py-1.5 text-center text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300 font-semibold transition-colors duration-300';
        
        tabBatal.classList.remove('hidden');
        tabBatal.classList.add('block');
        tabTambah.classList.remove('block');
        tabTambah.classList.add('hidden');
    }
}
</script>
<?php 
if (isset($stmt)) $stmt->close();
require $is_admin ? 'admin_footer.php' : 'supervisor_footer.php';
?>
