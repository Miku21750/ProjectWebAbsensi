<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'supervisor') {
    header("Location: login.php");
    exit();
}
$stmt_header_profile = $conn->prepare("SELECT u.id_karyawan, u.jenis_kelamin, u.foto_profil, u.face_descriptor,
                                              k.jenis_kelamin AS jenis_kelamin_karyawan, k.foto AS foto_karyawan
                                       FROM users u
                                       LEFT JOIN karyawan k ON k.id_karyawan = u.id_karyawan
                                       WHERE u.id = ?");
$stmt_header_profile->bind_param('i', $_SESSION['user_id']);
$stmt_header_profile->execute();
$header_profile = $stmt_header_profile->get_result()->fetch_assoc() ?: [];
$stmt_header_profile->close();
$has_face_registered = !empty($header_profile['face_descriptor']);
$admin_jk = !empty($header_profile['id_karyawan'])
    ? ($header_profile['jenis_kelamin_karyawan'] ?? $header_profile['jenis_kelamin'] ?? 'L')
    : ($header_profile['jenis_kelamin'] ?? $_SESSION['jenis_kelamin'] ?? 'L');
if (!empty($header_profile['id_karyawan']) && !empty($header_profile['foto_karyawan'])) {
    $avatar_src = 'assets/images/foto_karyawan/' . $header_profile['foto_karyawan'];
} elseif (!empty($header_profile['foto_profil'])) {
    $avatar_src = 'assets/uploads/' . $header_profile['foto_profil'];
} else {
    $avatar_src = ($admin_jk == 'P') ? 'assets/images/avatar_p.png?v=2' : 'assets/images/avatar_l.png?v=2';
}
$avatar_bg = ($admin_jk == 'P') ? 'bg-pink-100' : 'bg-fuchsia-100';

// --- Cakupan cabang supervisor ---
$cabang_supervisor = getCabangReviewer($conn, $_SESSION['user_id'], 'supervisor');
$nama_cabang_supervisor = null;
if ($cabang_supervisor > 0) {
    $stmt_cbg = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id = ?");
    $stmt_cbg->bind_param("i", $cabang_supervisor);
    $stmt_cbg->execute();
    $row_cbg = $stmt_cbg->get_result()->fetch_assoc();
    $stmt_cbg->close();
    $nama_cabang_supervisor = $row_cbg['nama_cabang'] ?? null;
}

// --- Notifikasi Supervisor: seluruhnya dibatasi ke cabang yang disupervisi ---
$notif_pengajuan = [];
$stmt_notif = $conn->prepare("SELECT p.id, p.jenis, p.tanggal_mulai, p.tanggal_selesai, p.keperluan,
                                     p.jumlah_hari_kerja, k.nama_karyawan
                              FROM pengajuan_izin p
                              JOIN karyawan k ON p.id_karyawan = k.id_karyawan
                              WHERE p.status = 'Pending' AND k.id_cabang = ?
                              ORDER BY p.created_at ASC
                              LIMIT 20");
$stmt_notif->bind_param("i", $cabang_supervisor);
$stmt_notif->execute();
$res_notif = $stmt_notif->get_result();
while ($row = $res_notif->fetch_assoc()) {
    $notif_pengajuan[] = $row;
}
$stmt_notif->close();

$notif_pengajuan_lembur = [];
$stmt_notif_lembur = $conn->prepare("SELECT p.id, p.tanggal_mulai, p.tanggal_selesai, p.keperluan,
                                            k.nama_karyawan
                                     FROM pengajuan_lembur p
                                     JOIN karyawan k ON p.id_karyawan = k.id_karyawan
                                     WHERE p.status = 'Pending' AND k.id_cabang = ?
                                     ORDER BY p.created_at ASC
                                     LIMIT 20");
$stmt_notif_lembur->bind_param("i", $cabang_supervisor);
$stmt_notif_lembur->execute();
$res_notif_lembur = $stmt_notif_lembur->get_result();
while ($row = $res_notif_lembur->fetch_assoc()) {
    $notif_pengajuan_lembur[] = $row;
}
$stmt_notif_lembur->close();

$notif_dinas = [];
$stmt_dinas = $conn->prepare("SELECT a.id, a.tanggal, a.alasan, k.nama_karyawan
                              FROM absensi a
                              JOIN karyawan k ON a.id_karyawan = k.id_karyawan
                              WHERE a.keterangan = 'Pending Dinas' AND k.id_cabang = ?
                              ORDER BY a.tanggal DESC
                              LIMIT 20");
$stmt_dinas->bind_param("i", $cabang_supervisor);
$stmt_dinas->execute();
$res_dinas = $stmt_dinas->get_result();
while ($row = $res_dinas->fetch_assoc()) {
    $notif_dinas[] = $row;
}
$stmt_dinas->close();

$notif_pulang_cepat = [];
$stmt_pc = $conn->prepare("SELECT a.id, a.tanggal, a.alasan_pulang_cepat, k.nama_karyawan
                           FROM absensi a
                           JOIN karyawan k ON a.id_karyawan = k.id_karyawan
                           WHERE a.izin_pulang_cepat = 'Pending' AND a.tanggal = CURDATE() AND k.id_cabang = ?
                           ORDER BY a.tanggal DESC
                           LIMIT 20");
$stmt_pc->bind_param("i", $cabang_supervisor);
$stmt_pc->execute();
$res_pc = $stmt_pc->get_result();
while ($row = $res_pc->fetch_assoc()) {
    $notif_pulang_cepat[] = $row;
}
$stmt_pc->close();

$notif_izin_info = [];
$stmt_izin_info = $conn->prepare("SELECT a.id, a.tanggal, a.keterangan, a.alasan, k.nama_karyawan
                                  FROM absensi a
                                  JOIN karyawan k ON a.id_karyawan = k.id_karyawan
                                  WHERE a.keterangan IN ('Sakit', 'Cuti', 'Izin')
                                    AND a.tanggal >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
                                    AND k.id_cabang = ?
                                  ORDER BY a.tanggal DESC
                                  LIMIT 20");
$stmt_izin_info->bind_param("i", $cabang_supervisor);
$stmt_izin_info->execute();
$res_izin_info = $stmt_izin_info->get_result();
while ($row = $res_izin_info->fetch_assoc()) {
    $notif_izin_info[] = $row;
}
$stmt_izin_info->close();

$pending_lembur_count = hitungPendingLembur($conn, $cabang_supervisor);
$actionable_notif_count = count($notif_pengajuan) + count($notif_dinas) + count($notif_pulang_cepat) + $pending_lembur_count;
$total_notif = $actionable_notif_count + count($notif_izin_info);
// ------------------------------
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="view-transition" content="same-origin">
    <title>Dashboard Supervisor - Absensi Javag</title>
    <link rel="icon" type="image/png" href="/assets/images/logo.png">

    <!-- Tailwind CSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Konfigurasi Tema Tailwind -->
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#fdf4ff',
                            100: '#fae8ff',
                            500: '#d946ef',
                            600: '#c026d3',
                            900: '#701a75',
                        }
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Font Awesome untuk Icon -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Phosphor Icons (Modern UI) -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        /* Scrollbar kustom untuk sidebar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dark ::-webkit-scrollbar-thumb { background: #475569; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        /* Prevent FOUC transitions */
        .preload * { transition: none !important; }
        
        /* Prevent DataTables FOUC (Flash of Unstyled Content) and jumping */
        body.preload table:not(.dataTable) tbody tr:nth-child(n+6) { display: none !important; }
    </style>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.remove('dark');
        }
        window.addEventListener('load', () => {
            setTimeout(() => document.body.classList.remove('preload'), 100);
        });
    </script>
    <style>
        /* Sidebar Collapse Animation for Desktop */
        #sidebar {
            transition: width 0.3s ease-in-out, transform 0.3s ease-in-out;
            overflow-x: hidden;
        }
        #sidebar span.text-sm, 
        #sidebar .logo-text-container,
        #sidebar p.tracking-widest {
            transition: opacity 0.2s ease-in-out, width 0.3s ease-in-out;
            white-space: nowrap;
        }
        
        @media (min-width: 768px) {
            body.sidebar-collapsed #sidebar {
                width: 5.5rem !important;
            }
            body.sidebar-collapsed #sidebar .logo-text-container {
                opacity: 0;
                width: 0;
                visibility: hidden;
                margin: 0;
            }
            body.sidebar-collapsed #sidebar span.text-sm {
                opacity: 0;
                width: 0;
                visibility: hidden;
                margin: 0;
            }
            body.sidebar-collapsed #sidebar p.tracking-widest {
                opacity: 0;
                height: 0;
                margin: 0;
                padding: 0;
                visibility: hidden;
            }
            body.sidebar-collapsed #sidebar .fa-chevron-down {
                display: none;
            }
            body.sidebar-collapsed #sidebar a.group, 
            body.sidebar-collapsed #sidebar button.group {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }
            body.sidebar-collapsed #sidebar .pulse-badge,
            body.sidebar-collapsed #sidebar span.text-\[10px\] {
                display: none !important;
            }
            body.sidebar-collapsed #sidebar [x-show="open"] {
                display: none !important;
            }
            body.sidebar-collapsed #sidebar .h-20 {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }
        }
    </style>
</head>
<body class="preload bg-slate-100 text-slate-800 dark:bg-slate-900 dark:text-slate-200 h-screen flex overflow-hidden transition-colors duration-200">

    <!-- SIDEBAR (LabFlow Style - Selalu Gelap) -->
    <aside id="sidebar" class="w-64 bg-[#0b172a] border-r border-[#1e293b] flex flex-col transition-all duration-300 fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 z-50 h-screen shadow-2xl md:shadow-[4px_0_24px_rgba(0,0,0,0.15)] dark:md:shadow-[4px_0_24px_rgba(0,0,0,0.4)]">
        
        <!-- Top Left Logo -->
        <div class="h-20 flex items-center gap-3 px-5 border-b border-[#1e293b] shrink-0 bg-slate-950">
            <div class="bg-white/10 p-1.5 rounded-xl shadow-lg border border-white/10">
                <img src="/assets/images/logo.png" alt="Javag Logo" class="h-8 w-auto" onerror="this.style.display='none'">
            </div>
            <div class="flex flex-col justify-center space-y-0.5 logo-text-container">
                <h2 class="text-2xl font-bold tracking-tight text-white leading-none">
                    Absen<span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-cyan-400">Kita</span>
                </h2>
                <span class="text-xs text-slate-300 font-medium tracking-wide leading-none ml-0.5 pt-0.5">
                    Java Abadi Gemilang
                </span>
            </div>

        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-6 space-y-2 no-scrollbar">
            <p class="px-6 text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 mt-2">Utama</p>

            <a href="supervisor_dashboard.php" class="flex items-center gap-3 px-4 py-3 mx-4 <?php echo basename($_SERVER['PHP_SELF']) == 'supervisor_dashboard.php' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <i class="ph-duotone ph-squares-four text-xl w-6 flex items-center justify-center <?php echo basename($_SERVER['PHP_SELF']) == 'supervisor_dashboard.php' ? '' : 'opacity-70 group-hover:opacity-100 transition-opacity'; ?>"></i>
                <span class="font-medium text-sm">Dashboard</span>
            </a>

            <a href="supervisor_kalender.php" class="flex items-center gap-3 px-4 py-3 mx-4 <?php echo basename($_SERVER['PHP_SELF']) == 'supervisor_kalender.php' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <i class="ph-duotone ph-calendar-dots text-xl w-6 flex items-center justify-center <?php echo basename($_SERVER['PHP_SELF']) == 'supervisor_kalender.php' ? '' : 'opacity-70 group-hover:opacity-100 transition-opacity'; ?>"></i>
                <span class="font-medium text-sm">Kalender</span>
            </a>

            <a href="supervisor_data_karyawan.php" class="flex items-center gap-3 px-4 py-3 mx-4 <?php echo in_array(basename($_SERVER['PHP_SELF']), ['supervisor_data_karyawan.php', 'supervisor_detail_karyawan.php'], true) ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <i class="ph-duotone ph-users-three text-xl w-6 flex items-center justify-center"></i>
                <span class="font-medium text-sm">Data Karyawan</span>
            </a>

            <?php if (!empty($header_profile['id_karyawan'])): ?>
            <a href="register_face.php" class="flex items-center justify-between px-4 py-3 mx-4 <?php echo basename($_SERVER['PHP_SELF']) == 'register_face.php' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <div class="flex items-center gap-3">
                    <i class="ph-duotone ph-shield-check text-xl w-6 flex items-center justify-center <?php echo basename($_SERVER['PHP_SELF']) == 'register_face.php' ? '' : 'opacity-70 group-hover:opacity-100 transition-opacity'; ?>"></i>
                    <span class="font-medium text-sm">Registrasi Wajah</span>
                </div>
                <?php if ($has_face_registered): ?>
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold bg-emerald-500/20 text-emerald-400 px-2.5 py-0.5 rounded-full border border-emerald-500/30 uppercase">
                        <i class="fa-solid fa-check"></i> Aktif
                    </span>
                <?php endif; ?>
            </a>
            <a href="staff_pengajuan_izin.php" class="flex items-center gap-3 px-4 py-3 mx-4 <?php echo basename($_SERVER['PHP_SELF']) == 'staff_pengajuan_izin.php' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <i class="ph-duotone ph-calendar-plus text-xl w-6 flex items-center justify-center"></i>
                <span class="font-medium text-sm">Pengajuan Saya</span>
            </a>
            <a href="staff_pengajuan_lembur.php" class="flex items-center gap-3 px-4 py-3 mx-4 <?php echo basename($_SERVER['PHP_SELF']) == 'staff_pengajuan_lembur.php' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <i class="ph-duotone ph-business-time text-xl w-6 flex items-center justify-center"></i>
                <span class="font-medium text-sm">Lembur Saya</span>
            </a>
            <?php endif; ?>

            <a href="histori_absensi.php" class="flex items-center gap-3 px-4 py-3 mx-4 <?= basename($_SERVER['PHP_SELF']) === 'histori_absensi.php' ? 'bg-purple-600 text-white' : 'text-white hover:bg-[#1e293b]' ?> rounded-xl"><i class="ph-duotone ph-calendar-check text-xl"></i><span class="font-medium text-sm">Rekap Absensi</span></a>
            <p class="px-6 text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 mt-8">Persetujuan</p>

            <a href="kelola_pengajuan_izin.php" class="flex items-center justify-between px-4 py-3 mx-4 <?php echo basename($_SERVER['PHP_SELF']) == 'kelola_pengajuan_izin.php' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <div class="flex items-center gap-3">
                    <i class="ph-duotone ph-clipboard-text text-xl w-6 flex items-center justify-center <?php echo basename($_SERVER['PHP_SELF']) == 'kelola_pengajuan_izin.php' ? '' : 'opacity-70 group-hover:opacity-100 transition-opacity'; ?>"></i>
                    <span class="font-medium text-sm">Pengajuan Tim</span>
                </div>
                <?php if ($actionable_notif_count > 0): ?>
                    <span class="pulse-badge inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-[10px] font-bold bg-rose-500/20 text-rose-400 rounded-full border border-rose-500/30 shadow-sm shadow-rose-500/20">
                        <?php echo $actionable_notif_count; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="kelola_pengajuan_lembur.php" class="flex items-center gap-3 px-4 py-3 mx-4 <?php echo basename($_SERVER['PHP_SELF']) == 'kelola_pengajuan_lembur.php' ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' : 'text-white hover:bg-[#1e293b]'; ?> rounded-xl transition-all duration-300 group">
                <i class="ph-duotone ph-business-time text-xl w-6 flex items-center justify-center <?php echo basename($_SERVER['PHP_SELF']) == 'kelola_pengajuan_lembur.php' ? '' : 'opacity-70 group-hover:opacity-100 transition-opacity'; ?>"></i>
                <span class="font-medium text-sm">Kelola Lembur Tim</span>
            </a>

            <p class="px-6 text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 mt-8">Akun</p>

            <a href="logout.php" onclick="confirmLogout(event, this.href);" class="flex items-center gap-3 px-4 py-3 mx-4 text-rose-400 hover:bg-rose-500/10 hover:text-rose-300 rounded-xl transition-all duration-300 group">
                <i class="ph-duotone ph-sign-out text-xl w-6 flex items-center justify-center opacity-70 group-hover:opacity-100 transition-opacity"></i>
                <span class="font-medium text-sm">Logout</span>
            </a>
        </nav>
        
    </aside>

    <!-- Overlay untuk Sidebar di Mobile -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden md:hidden opacity-0 transition-opacity duration-300"></div>

    <!-- MAIN CONTENT -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden relative w-full bg-slate-50 dark:bg-slate-900">
        
        <!-- HEADER -->
        <header class="h-20 bg-white/90 dark:bg-slate-800/90 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-4 sm:px-6 z-30 transition-colors duration-200 shrink-0">
            <!-- Left: Mobile Menu Toggle -->
            <div class="flex items-center gap-3">
                <button id="openSidebar" class="text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400 transition-colors p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700/50">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
            </div>

            <!-- Right: Dark Mode Toggle & Profile Dropdown -->
            <div class="flex items-center gap-2 sm:gap-4">
                
                <!-- Dark Mode Toggle (Pill Switch) -->
                <button @click="
                        darkMode = !darkMode;
                        if (darkMode) {
                            document.documentElement.classList.add('dark');
                            localStorage.setItem('theme', 'dark');
                        } else {
                            document.documentElement.classList.remove('dark');
                            localStorage.setItem('theme', 'light');
                        }
                    " 
                    x-data="{ darkMode: document.documentElement.classList.contains('dark') }"
                    class="relative w-14 h-7 sm:w-16 sm:h-8 rounded-full bg-slate-200 dark:bg-slate-700 shadow-inner flex items-center p-1 transition-colors duration-300 border border-slate-300 dark:border-slate-600 hover:ring-2 hover:ring-brand-500/50 outline-none group shrink-0">
                    <!-- Background Track Icons -->
                    <div class="absolute inset-0 flex items-center justify-between px-2 pointer-events-none">
                        <i class="fa-solid fa-sun text-slate-400 text-[10px] transition-opacity" :class="darkMode ? 'opacity-50' : 'opacity-100'"></i>
                        <i class="fa-solid fa-moon text-slate-400 dark:text-slate-500 text-[10px] transition-opacity" :class="darkMode ? 'opacity-100' : 'opacity-50'"></i>
                    </div>
                    <!-- Knob -->
                    <div class="w-5 h-5 sm:w-6 sm:h-6 rounded-full bg-white dark:bg-slate-800 shadow flex items-center justify-center transform transition-transform duration-300 relative z-10 border border-slate-100 dark:border-slate-600 group-hover:shadow-md" :class="darkMode ? 'translate-x-7 sm:translate-x-8' : 'translate-x-0'">
                        <i class="fa-solid fa-sun text-amber-500 text-[10px] sm:text-[11px]" x-show="!darkMode"></i>
                        <i class="fa-solid fa-moon text-fuchsia-400 text-[10px] sm:text-[11px]" x-show="darkMode" style="display: none;"></i>
                    </div>
                </button>

                
                <!-- Notification Bell -->
                <div x-data="{ 
                    openNotif: false, 
                    actionableCount: <?php echo $actionable_notif_count; ?>,
                    hasInfo: <?php echo count($notif_izin_info) > 0 ? 'true' : 'false'; ?>,
                    get showBadge() {
                        if (this.actionableCount > 0) return true;
                        return this.hasInfo && !sessionStorage.getItem('notif_opened');
                    }
                }" class="relative">
                    <button @click="openNotif = !openNotif; if(openNotif) { sessionStorage.setItem('notif_opened', 'true'); }" @click.away="openNotif = false" class="relative w-10 h-10 flex items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700/50 transition-colors border border-transparent hover:border-slate-200 dark:hover:border-slate-600">
                        <i class="fa-regular fa-bell text-lg"></i>
                        <span x-show="showBadge" style="display: none;" class="absolute top-1.5 right-1.5 flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-rose-500"></span>
                        </span>
                    </button>
                    
                    <!-- Notification Dropdown -->
                    <div x-show="openNotif" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 translate-y-2"
                         class="absolute right-0 mt-2 w-80 sm:w-96 bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-50 overflow-hidden" 
                         style="display: none;">
                        
                        <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/50 flex justify-between items-center bg-white dark:bg-slate-800 sticky top-0 z-10">
                            <p class="text-sm font-bold text-slate-800 dark:text-white">Menunggu Persetujuan</p>
                            <span class="text-xs font-semibold bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 px-2.5 py-0.5 rounded-full"><?php echo $total_notif; ?> Baru</span>
                        </div>
                        
                        <div class="max-h-[60vh] overflow-y-auto no-scrollbar relative">
                            <?php if ($total_notif == 0): ?>
                            <div class="py-8 text-center px-4">
                                <div class="w-12 h-12 rounded-full bg-slate-50 dark:bg-slate-800/50 flex items-center justify-center mx-auto mb-3 border border-slate-100 dark:border-slate-700">
                                    <i class="fa-solid fa-check text-slate-300 dark:text-slate-500 text-xl"></i>
                                </div>
                                <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Semua pengajuan sudah beres!</p>
                                <p class="text-xs text-slate-400 mt-1">Tidak ada pengajuan yang menunggu review.</p>
                            </div>
                            <?php else: ?>
                                <?php if (count($notif_pengajuan) > 0): ?>
                                <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/50 border-y border-slate-100 dark:border-slate-700/50 sticky top-0 z-10 backdrop-blur-sm">
                                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Menunggu Review Anda</p>
                                </div>
                                <?php foreach ($notif_pengajuan as $np): ?>
                                <a href="kelola_pengajuan_izin.php?status=Pending" class="block p-4 border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <p class="text-sm font-bold text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($np['nama_karyawan']); ?></p>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-bold shrink-0 <?php echo badgeJenisIzin($np['jenis']); ?>"><?php echo htmlspecialchars($np['jenis']); ?></span>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 line-clamp-2"><?php echo htmlspecialchars($np['keperluan']); ?></p>
                                    <p class="text-[10px] font-medium text-slate-400 mt-2">
                                        <i class="fa-regular fa-calendar mr-1"></i>
                                        <?php echo formatRentangTanggal($np['tanggal_mulai'], $np['tanggal_selesai']); ?>
                                        &middot; <?php echo (int)$np['jumlah_hari_kerja']; ?> hari kerja
                                    </p>
                                </a>
                                <?php endforeach; endif; ?>

                                <?php if ($pending_lembur_count > 0): ?>
                                <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/50 border-y border-slate-100 dark:border-slate-700/50 sticky top-0 z-10 backdrop-blur-sm">
                                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Pengajuan Lembur Menunggu Review</p>
                                </div>
                                <?php foreach ($notif_pengajuan_lembur as $nl): ?>
                                <a href="kelola_pengajuan_lembur.php?status=Pending" class="block p-4 border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <p class="text-sm font-bold text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($nl['nama_karyawan']); ?></p>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-bold shrink-0 bg-purple-100 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-800/50">Lembur</span>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 line-clamp-2"><?php echo htmlspecialchars($nl['keperluan']); ?></p>
                                    <p class="text-[10px] font-medium text-slate-400 mt-2">
                                        <i class="fa-regular fa-calendar mr-1"></i>
                                        <?php echo formatRentangTanggal($nl['tanggal_mulai'], $nl['tanggal_selesai']); ?>
                                    </p>
                                </a>
                                <?php endforeach; endif; ?>

                                <!-- Loop Pending Dinas: preview saja, aksi ACC/Tolak dilakukan di Kelola Pengajuan Izin -->
                                <?php if (count($notif_dinas) > 0): ?>
                                <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/50 border-y border-slate-100 dark:border-slate-700/50 sticky top-0 z-10 backdrop-blur-sm">
                                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Pending Dinas Luar</p>
                                </div>
                                <?php foreach ($notif_dinas as $nd): ?>
                                <a href="kelola_pengajuan_izin.php" class="block p-4 border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <p class="text-sm font-bold text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($nd['nama_karyawan']); ?></p>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-bold shrink-0 bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400 border-sky-200 dark:border-sky-800/50">Dinas Luar</span>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 line-clamp-2"><?php echo htmlspecialchars($nd['alasan'] ?? '-'); ?></p>
                                    <p class="text-[10px] font-medium text-slate-400 mt-2"><i class="fa-regular fa-calendar mr-1"></i><?php echo date('d M Y', strtotime($nd['tanggal'])); ?></p>
                                </a>
                                <?php endforeach; endif; ?>

                                <!-- Loop Pending Pulang Cepat: preview saja, aksi ACC/Tolak dilakukan di Kelola Pengajuan Izin -->
                                <?php if (count($notif_pulang_cepat) > 0): ?>
                                <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/50 border-y border-slate-100 dark:border-slate-700/50 sticky top-0 z-10 backdrop-blur-sm">
                                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Pending Izin Pulang Cepat</p>
                                </div>
                                <?php foreach ($notif_pulang_cepat as $npc): ?>
                                <a href="kelola_pengajuan_izin.php" class="block p-4 border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <p class="text-sm font-bold text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($npc['nama_karyawan']); ?></p>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-bold shrink-0 bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400 border-amber-200 dark:border-amber-800/50">Pulang Cepat</span>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 line-clamp-2"><?php echo htmlspecialchars($npc['alasan_pulang_cepat'] ?? '-'); ?></p>
                                    <p class="text-[10px] font-medium text-slate-400 mt-2"><i class="fa-regular fa-calendar mr-1"></i><?php echo date('d M Y', strtotime($npc['tanggal'])); ?></p>
                                </a>
                                <?php endforeach; endif; ?>

                                <?php if (count($notif_izin_info) > 0): ?>
                                <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/50 border-y border-slate-100 dark:border-slate-700/50 sticky top-0 z-10 backdrop-blur-sm">
                                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Sakit, Cuti &amp; Izin (2 Hari Terakhir)</p>
                                </div>
                                <?php foreach ($notif_izin_info as $ni): ?>
                                <div class="p-4 border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <p class="text-sm font-bold text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($ni['nama_karyawan']); ?></p>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full border font-bold shrink-0 bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400 border-indigo-200 dark:border-indigo-800/50"><?php echo htmlspecialchars($ni['keterangan']); ?></span>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 line-clamp-2"><?php echo htmlspecialchars($ni['alasan'] ?? '-'); ?></p>
                                    <p class="text-[10px] font-medium text-slate-400 mt-1"><i class="fa-regular fa-calendar mr-1"></i><?php echo date('d M Y', strtotime($ni['tanggal'])); ?></p>
                                </div>
                                <?php endforeach; endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Divider -->
                <div class="hidden sm:block w-px h-8 bg-slate-200 dark:bg-slate-700 mx-1"></div>

                <!-- Profile Dropdown -->
                <div x-data="{ openProfile: false }" class="relative">
                    <button @click="openProfile = !openProfile" @click.away="openProfile = false" class="flex items-center gap-3 p-1 pr-3 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700/50 transition-colors border border-transparent hover:border-slate-200 dark:hover:border-slate-600">
                        <img src="<?php echo $avatar_src; ?>" alt="Profile" class="w-9 h-9 rounded-full border border-slate-200 dark:border-slate-600 object-cover">
                        <div class="hidden sm:flex items-center gap-2">
                            <span class="text-sm font-semibold text-slate-800 dark:text-white"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                        </div>
                    </button>
                    
                    <!-- Dropdown Menu -->
                    <div x-show="openProfile" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 translate-y-2"
                         class="absolute right-0 mt-2 w-64 bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-50 overflow-hidden" 
                         style="display: none;">
                        
                        <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/50">
                            <p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username']); ?></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Supervisor<?php echo $nama_cabang_supervisor ? ' &middot; ' . htmlspecialchars($nama_cabang_supervisor) : ''; ?>
                            </p>
                        </div>

                        <div class="py-1">
                            <button onclick="openModalHeader('modal-ganti-password-header')" class="w-full text-left px-5 py-2.5 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50 flex items-center gap-3 transition-colors">
                                <i class="fa-solid fa-key w-4 text-center text-slate-400"></i> Ganti Password
                            </button>
                            <a href="supervisor_pengaturan.php" class="block px-5 py-2.5 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50 flex items-center gap-3 transition-colors">
                                <i class="fa-solid fa-user-gear w-4 text-center text-slate-400"></i> Pengaturan Akun
                            </a>
                            <button onclick="confirmDeleteAkunSupervisorHeader()" class="w-full text-left px-5 py-2.5 text-sm text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20 flex items-center gap-3 transition-colors">
                                <i class="fa-solid fa-user-xmark w-4 text-center text-rose-400"></i> Hapus Akun
                            </button>
                        </div>
                        
                        <div class="border-t border-slate-100 dark:border-slate-700/50 py-1">
                            <a href="logout.php" onclick="confirmLogout(event, this.href);" class="block px-5 py-2.5 text-sm text-rose-600 dark:text-rose-400 font-medium hover:bg-rose-50 dark:hover:bg-rose-500/10 flex items-center gap-3 transition-colors">
                                <i class="fa-solid fa-right-from-bracket w-4 text-center text-rose-500 dark:text-rose-400"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Alpine.js untuk fitur interaktif (submenu dll) -->
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

        <!-- Modal Ganti Password Header -->
        <div id="modal-ganti-password-header" class="fixed inset-0 z-[60] hidden">
            <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeModalHeader('modal-ganti-password-header')"></div>
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div class="relative bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-md w-full border border-slate-200 dark:border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
                        <h3 class="text-lg font-bold text-slate-800 dark:text-white">Ganti Password Supervisor</h3>
                        <button onclick="closeModalHeader('modal-ganti-password-header')" class="text-slate-400 hover:text-slate-500 dark:hover:text-slate-300">
                            <i class="fa-solid fa-xmark text-xl"></i>
                        </button>
                    </div>
                    <form id="form-ganti-password-header" action="proses_ganti_password.php" method="POST" onsubmit="return kirimGantiPassword(event)">
                        <div class="px-6 py-5 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Username</label>
                                <input type="text" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>" readonly class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-500 dark:text-slate-400 text-sm focus:outline-none cursor-not-allowed">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Password Baru <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <input type="password" id="pwd_admin_baru" name="password_baru" required minlength="6" class="w-full px-4 py-2.5 pr-10 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 transition-colors">
                                    <button type="button" onclick="togglePasswordVisibility('pwd_admin_baru', 'icon_pwd_baru')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                        <i id="icon_pwd_baru" class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                                <p class="text-xs text-slate-500 mt-1">Minimal 6 karakter.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Konfirmasi Password <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <input type="password" id="pwd_admin_confirm" name="konfirmasi_password" required minlength="6" class="w-full px-4 py-2.5 pr-10 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 transition-colors">
                                    <button type="button" onclick="togglePasswordVisibility('pwd_admin_confirm', 'icon_pwd_confirm')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                        <i id="icon_pwd_confirm" class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                                <p id="pwd_admin_error" class="text-xs text-rose-500 mt-1 hidden">Konfirmasi password tidak cocok!</p>
                            </div>
                        </div>
                        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-3">
                            <button type="button" onclick="closeModalHeader('modal-ganti-password-header')" class="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-sm font-medium">Batal</button>
                            <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl shadow-sm shadow-purple-500/30 transition-colors text-sm font-medium">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function togglePasswordVisibility(inputId, iconId) {
            var input = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        function confirmDeleteAkunSupervisorHeader() {
            const submitDelete = function () {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'hapus_akun_sendiri.php';
                const csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = 'csrf_token';
                csrf.value = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
                form.appendChild(csrf);
                document.body.appendChild(form);
                form.submit();
            };
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Hapus Akun Supervisor?',
                    text: 'Akun login Supervisor Anda akan dihapus dan Anda akan logout. Data karyawan tidak ikut dihapus.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#94a3b8',
                    confirmButtonText: 'Ya, Hapus Akun',
                    cancelButtonText: 'Batal'
                }).then(function (result) {
                    if (result.isConfirmed) submitDelete();
                });
            } else if (confirm('Hapus akun Supervisor Anda dan logout?')) {
                submitDelete();
            }
        }
        function kirimGantiPassword(event) {
            event.preventDefault();
            var p1 = document.getElementById('pwd_admin_baru').value;
            var p2 = document.getElementById('pwd_admin_confirm').value;
            if (p1 !== p2) {
                document.getElementById('pwd_admin_error').classList.remove('hidden');
                return false;
            }
            document.getElementById('pwd_admin_error').classList.add('hidden');

            var form = document.getElementById('form-ganti-password-header');
            fetch('proses_ganti_password.php', { method: 'POST', body: new FormData(form) })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    closeModalHeader('modal-ganti-password-header');
                    if (typeof Swal === 'undefined') { alert(data.message); return; }
                    Swal.fire({
                        icon: data.success ? 'success' : 'error',
                        title: data.success ? 'Berhasil' : 'Gagal',
                        text: data.message,
                        confirmButtonColor: '#c026d3'
                    }).then(function () { if (data.success) window.location.reload(); });
                })
                .catch(function () {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Gagal', text: 'Tidak dapat menghubungi server.', confirmButtonColor: '#c026d3' });
                    }
                });
            return false;
        }
        function openModalHeader(id) {
            document.getElementById(id).classList.remove('hidden');
        }
        function closeModalHeader(id) {
            document.getElementById(id).classList.add('hidden');
        }

        // Logic to open/close sidebar
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const openBtn = document.getElementById('openSidebar');
            const closeBtn = document.getElementById('closeSidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function toggleSidebar() {
                if (window.innerWidth < 768) {
                    // Mobile
                    sidebar.classList.toggle('-translate-x-full');
                    if (overlay.classList.contains('hidden')) {
                        overlay.classList.remove('hidden');
                        setTimeout(() => overlay.classList.remove('opacity-0'), 10);
                    } else {
                        overlay.classList.add('opacity-0');
                        setTimeout(() => overlay.classList.add('hidden'), 300);
                    }
                } else {
                    // Desktop
                    document.body.classList.toggle('sidebar-collapsed');
                }
            }

            if(openBtn) openBtn.addEventListener('click', toggleSidebar);
            if(closeBtn) closeBtn.addEventListener('click', toggleSidebar);
            if(overlay) overlay.addEventListener('click', toggleSidebar);

            // AJAX form handler for Header password change
            const formGantiPassword = document.getElementById('form-ganti-password-header');
            if (formGantiPassword) {
                formGantiPassword.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Memproses...',
                            text: 'Menyimpan password baru...',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                    }
                    
                    const formData = new FormData(this);
                    formData.append('is_ajax', '1');
                    
                    fetch(this.action, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (typeof Swal !== 'undefined') {
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
                        } else {
                            if (data.status === 'success') {
                                alert(data.message);
                                window.location.reload();
                            } else {
                                alert(data.message);
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
                            alert('Gagal terhubung ke server.');
                        }
                    });
                });
            }
        });
        </script>

        <!-- SCROLLABLE CONTENT AREA -->
        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 relative">
