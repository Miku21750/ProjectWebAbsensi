<?php
require 'config.php';
require_once 'attendance_capture.php';
require_once 'attendance_face.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

function outputJSON($data) {
    if (ob_get_length()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        outputJSON(['success' => false, 'message' => 'Gunakan POST.']);
    }
    // Sama seperti verify_attendance_face.php - dicek manual (bukan lewat
    // verifyCSRFToken() global) karena endpoint ini harus selalu balas JSON,
    // sedangkan verifyCSRFToken() mati dengan teks polos.
    $csrf = $_POST['csrf_token'] ?? null;
    if (!is_string($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        outputJSON(['success' => false, 'message' => 'Sesi tidak valid. Muat ulang halaman absensi.']);
    }
    foreach (['id_karyawan', 'lokasi', 'keterangan', 'alasan', 'alasan_pulang'] as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) {
            outputJSON(['success' => false, 'message' => 'Format permintaan tidak valid.']);
        }
    }
    $capture_ready = attendanceCaptureReady($conn);
    $capture_bytes = null;
    $rate_check = checkRateLimit('absen', 5);
    if (!$rate_check['allowed']) {
        outputJSON([
            'success' => false,
            'message' => 'Tunggu ' . $rate_check['remaining'] . ' detik untuk absen lagi.'
        ]);
    }

    $id_karyawan = isset($_POST['id_karyawan']) ? sanitizeInput($_POST['id_karyawan']) : '';
    $lokasi = isset($_POST['lokasi']) ? sanitizeInput($_POST['lokasi']) : 'Lokasi tidak terdeteksi';
    $keterangan_param = isset($_POST['keterangan']) ? sanitizeInput($_POST['keterangan']) : '';
    $is_dinas_luar = isset($_POST['is_dinas_luar']) && $_POST['is_dinas_luar'] === 'true';
    
    // Never trust a confidence score or verified flag from the browser.
    $face_confidence = null;
    $face_verified = 0;
    
    $MIN_FACE_CONFIDENCE = ATTENDANCE_FACE_MIN_CONFIDENCE;
    
    $tanggal = date('Y-m-d');
    $waktu = date('H:i:s');

    $response = ['success' => false, 'message' => 'Terjadi kesalahan.'];

    if (empty($id_karyawan)) {
        outputJSON(['success' => false, 'message' => 'ID Karyawan tidak boleh kosong.']);
    }

    if (!validateIDKaryawan($id_karyawan)) {
        outputJSON(['success' => false, 'message' => 'Format ID Karyawan tidak valid.']);
    }
    if (!in_array($keterangan_param, ['Hadir', 'pulang', 'OFF', 'Sakit', 'Cuti', 'Alpha'], true)) {
        outputJSON(['success' => false, 'message' => 'Jenis absensi tidak valid.']);
    }

    // Get karyawan data
    $stmt = $conn->prepare("SELECT k.id, k.id_cabang, u.face_descriptor FROM karyawan k LEFT JOIN users u ON k.id_karyawan = u.id_karyawan WHERE k.id_karyawan = ? AND k.status = 'aktif' AND (u.id IS NULL OR u.is_active = 1)");
    $stmt->bind_param("s", $id_karyawan);
    $stmt->execute();
    $result_karyawan = $stmt->get_result();
    
    if ($result_karyawan->num_rows == 0) {
        $stmt->close();
        outputJSON(['success' => false, 'message' => 'ID Karyawan tidak ditemukan.']);
    }

    $karyawan_data = $result_karyawan->fetch_assoc();
    $id_cabang = $karyawan_data['id_cabang'];
    $has_registered_face = !empty($karyawan_data['face_descriptor']);
    $stmt->close();

    // Pengajuan Dinas Luar yang sudah disetujui supervisor/admin untuk hari ini.
    // Bila ada, karyawan tidak perlu lagi mengajukan dinas dadakan: validasi
    // lokasi dilewati dan absensi langsung tercatat sebagai 'Dinas Luar'.
    $izin_dinas_hari_ini = getIzinDinasDisetujui($conn, $id_karyawan, $tanggal);
    if ($izin_dinas_hari_ini) {
        $is_dinas_luar = true;
    }

    // ========== PERBAIKAN: VALIDASI HANYA UNTUK "HADIR" ==========
    // Validasi GPS dan Face HANYA untuk keterangan "Hadir"
    if ($keterangan_param === 'Hadir') {
        
        // 0. CEK REGISTRASI WAJAH (WAJIB untuk Hadir)
        if (!$has_registered_face) {
            outputJSON([
                'success' => false,
                'message' => '❌ Registrasi Wajah Diperlukan!<br><br>Untuk absensi <strong>HADIR</strong>, Anda wajib melakukan registrasi wajah terlebih dahulu melalui menu profile/akun Anda.',
                'type' => 'face_registration_required'
            ]);
        }
        
        // 1. VALIDASI LOKASI GPS (wajib untuk Hadir, kecuali request Dinas Luar)
        $validasi_lokasi = validateLokasiAbsen($lokasi, $id_karyawan, $conn);
        if (!$is_dinas_luar && !$validasi_lokasi['valid'] && !isset($validasi_lokasi['bypass'])) {
            outputJSON([
                'success' => false,
                'message' => $validasi_lokasi['message'],
                'jarak' => $validasi_lokasi['jarak'],
                'radius' => $validasi_lokasi['radius'],
                'type' => 'location_error'
            ]);
        }
        
    }

    // Cek duplikasi
    $time_threshold = date('Y-m-d H:i:s', strtotime('-10 seconds'));
    $stmt_duplicate = $conn->prepare("SELECT id FROM absensi WHERE id_karyawan = ? AND tanggal = ? AND created_at > ?");
    $stmt_duplicate->bind_param("sss", $id_karyawan, $tanggal, $time_threshold);
    $stmt_duplicate->execute();
    if ($stmt_duplicate->get_result()->num_rows > 0) {
        $stmt_duplicate->close();
        outputJSON(['success' => false, 'message' => 'Absensi sedang diproses. Mohon tunggu.']);
    }
    $stmt_duplicate->close();

    // Cek status absensi hari ini
    $stmt_check = $conn->prepare("SELECT id, jam_masuk, jam_pulang, keterangan FROM absensi WHERE id_karyawan = ? AND tanggal = ?");
    $stmt_check->bind_param("ss", $id_karyawan, $tanggal);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $attendance_row = $result_check->fetch_assoc();
    $row_ada = (bool)$attendance_row;
    $data_absen = $attendance_row;

    // Kiosk membiarkan Masuk dan Pulang dipilih bebas (bukan otomatis dari
    // state) - klik "Absen Pulang" mengirim aksi=pulang secara eksplisit,
    // supaya bisa dibedakan dari submit Masuk walau belum ada baris sama
    // sekali hari ini (skenario lupa absen masuk - lihat cabang !$row_ada
    // di bawah, yang tetap mewajibkan verifikasi wajah lewat token yang
    // sama seperti Pulang normal).
    $aksi_pulang = isset($_POST['aksi']) && $_POST['aksi'] === 'pulang';
    $is_absen_pulang = $row_ada || $aksi_pulang;

    // Derive the operation from the database (+ aksi=pulang eksplisit untuk
    // kasus lupa absen masuk), bukan cuma dari status/jenis yang diposting.
    // 'Pending Dinas' sengaja tidak diikutkan: verify_attendance_face.php
    // tidak pernah menerbitkan token untuk status ini (masih menunggu ACC
    // admin), jadi cabang ini dibiarkan gagal di pengecekan $is_pending_dinas
    // di bawah yang pesannya jauh lebih jelas, bukan dipaksa lewat sini dulu.
    $needs_face = $is_absen_pulang
        ? ($row_ada ? in_array($attendance_row['keterangan'], ['Hadir', 'Dinas Luar'], true) : true)
        : ($keterangan_param === 'Hadir' || $is_dinas_luar || $izin_dinas_hari_ini);
    if ($needs_face) {
        $context = attendanceFaceContext($id_karyawan, $row_ada ? $attendance_row : null, $karyawan_data['face_descriptor'], $tanggal, $is_absen_pulang ? 'pulang' : 'masuk');
        $verified = attendanceFaceConsumeToken($_SESSION, 'attendance_face_receipts', $_POST['verification_token'] ?? null, $context);
        $capture_bytes = readAttendanceCapture($_FILES['foto_capture'] ?? null);
        if (!hash_equals($verified['photo_hash'], hash('sha256', $capture_bytes))) {
            throw new RuntimeException('Foto berbeda dari verifikasi. Silakan verifikasi ulang.');
        }
        $face_confidence = $verified['confidence'];
        $face_verified = 1;
    }

    // ============== SAKIT / CUTI: rute lewat pengajuan_izin ==============
    // Dulu keterangan ini langsung diinsert ke absensi (auto-approved, tanpa
    // potong kuota). Sekarang dibuat sebagai pengajuan Pending yang tunduk
    // pada kuota tahunan (izinPotongKuota) dan butuh persetujuan atasan,
    // sama seperti Dinas Luar dadakan - supaya kedua sistem tetap konsisten
    // dan bisa dipantau lewat satu layar (kelola_pengajuan_izin.php).
    if (!$is_dinas_luar && !$izin_dinas_hari_ini && in_array($keterangan_param, ['Sakit', 'Cuti'], true)) {
        $stmt_check->close();

        if ($is_absen_pulang) {
            outputJSON(['success' => false, 'message' => 'Anda sudah tercatat absen hari ini.']);
        }

        $alasan_izin = isset($_POST['alasan']) ? sanitizeInput($_POST['alasan']) : '';
        if (strlen($alasan_izin) < 5) {
            outputJSON(['success' => false, 'message' => 'Alasan wajib diisi minimal 5 karakter.']);
        }

        // Hanya deteksi kehadiran foto di sini (belum divalidasi/dipindah), supaya
        // Sakit + bukti bisa dibebaskan dari kuota SEBELUM cek kuota di bawah.
        $ada_bukti_izin = isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0 && $_FILES['foto_bukti']['size'] > 0;

        $bentrok = cekTumpangTindihIzin($conn, $id_karyawan, $tanggal, $tanggal);
        if ($bentrok) {
            outputJSON(['success' => false, 'message' => "Anda sudah punya pengajuan {$bentrok['jenis']} ({$bentrok['status']}) yang mencakup hari ini."]);
        }

        $rincian = hitungHariIzin($conn, $id_karyawan, $tanggal, $tanggal);
        if ($rincian['hari_efektif'] < 1) {
            outputJSON(['success' => false, 'message' => 'Hari ini bukan hari kerja efektif, sehingga tidak perlu mengajukan izin.']);
        }

        $potong_kuota = izinPotongKuota($keterangan_param, $ada_bukti_izin) ? 1 : 0;
        if ($potong_kuota) {
            $kuota = getRingkasanKuotaIzin($conn, $id_karyawan, (int)date('Y'));
            if ($rincian['hari_efektif'] > $kuota['tersedia']) {
                outputJSON(['success' => false, 'message' => "Kuota izin/cuti tahun ini sudah habis (sisa {$kuota['tersedia']} dari {$kuota['jatah']} hari). Lampirkan surat dokter agar Sakit tidak memotong kuota."]);
            }
        }

        // Validasi tegas (bukan dilewati diam-diam): kalau ada file tapi tidak
        // valid, tolak seluruh pengajuan - supaya potong_kuota=0 di atas tidak
        // pernah tersimpan tanpa bukti yang benar-benar berhasil diunggah.
        $foto_izin_name = null;
        if ($ada_bukti_izin) {
            $ext = strtolower(pathinfo($_FILES['foto_bukti']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                outputJSON(['success' => false, 'message' => 'Format foto bukti tidak valid. Gunakan JPG atau PNG.']);
            }
            if ($_FILES['foto_bukti']['size'] > 6 * 1024 * 1024) {
                outputJSON(['success' => false, 'message' => 'Ukuran foto bukti maksimal 6MB.']);
            }
            $upload_dir = __DIR__ . '/assets/uploads/izin/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $foto_izin_name = $id_karyawan . '_izin_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($_FILES['foto_bukti']['tmp_name'], $upload_dir . $foto_izin_name)) {
                outputJSON(['success' => false, 'message' => 'Gagal mengunggah foto bukti. Silakan coba lagi.']);
            }
        }

        $stmt_izin_insert = $conn->prepare("INSERT INTO pengajuan_izin
            (id_karyawan, jenis, tanggal_mulai, tanggal_selesai, jumlah_hari, jumlah_hari_kerja,
             keperluan, lampiran, status, potong_kuota, id_cabang)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)");
        $stmt_izin_insert->bind_param(
            "ssssiissii",
            $id_karyawan, $keterangan_param, $tanggal, $tanggal,
            $rincian['hari_kalender'], $rincian['hari_efektif'],
            $alasan_izin, $foto_izin_name, $potong_kuota, $id_cabang
        );

        if ($stmt_izin_insert->execute()) {
            $stmt_izin_insert->close();
            logActivity($conn, 'ajukan_izin', "Ajukan $keterangan_param dadakan via kiosk absen", $id_karyawan);
            $ket_kuota = (!$potong_kuota && $keterangan_param === 'Sakit')
                ? ' Karena dilampiri bukti, permohonan ini tidak memotong kuota tahunan Anda.'
                : '';
            outputJSON([
                'success' => true,
                'title' => 'Permintaan Terkirim',
                'message' => "Permohonan $keterangan_param Anda telah dikirim dan menunggu persetujuan atasan.$ket_kuota"
            ]);
        } else {
            $stmt_izin_insert->close();
            outputJSON(['success' => false, 'message' => 'Gagal mengirim permohonan: ' . $conn->error]);
        }
    }
    // =======================================================================

    // OFF & Alpha tidak lagi bisa diajukan sendiri lewat kiosk - keduanya
    // butuh keputusan Admin (OFF lewat hari libur/entri manual, Alpha lewat
    // entri manual histori_absensi.php), bukan tombol self-service tanpa
    // alasan/persetujuan. Guard sisi server, bukan sekadar sembunyikan tombol.
    // Cuti khusus (Menikah dkk) juga harus lewat pengajuan_izin resmi
    // (staff_pengajuan_izin.php) supaya direview, bukan diinsert langsung
    // dari kiosk yang sessionless/tanpa autentikasi kuat.
    if (!$is_absen_pulang && !$is_dinas_luar && !$izin_dinas_hari_ini && in_array($keterangan_param, array_merge(['OFF', 'Alpha'], IZIN_JENIS_KHUSUS), true)) {
        $stmt_check->close();
        outputJSON(['success' => false, 'message' => 'Opsi ini sudah tidak tersedia untuk pengajuan mandiri. Gunakan menu Pengajuan Izin, atau hubungi Admin.']);
    }
    // =======================================================================

    if ($is_absen_pulang) {
        // ============== PROSES ABSEN PULANG ==============

        if (!$row_ada) {
            // Pulang dipilih sebagai AKSI PERTAMA hari ini - tidak ada baris
            // sama sekali, artinya karyawan lupa (atau sengaja belum) absen
            // masuk. Baris baru dibuat dengan jam_masuk KOSONG - sengaja
            // dibiarkan tampak sebagai anomali (bukan ditebak/ditutupi jadi
            // "Tepat Waktu"), supaya SPV/Admin bisa menanyakan langsung ke
            // karyawan saat meninjau data sebelum membuat slip gaji, alih-
            // alih sistem diam-diam mengarang jam masuk. Tidak ada
            // perhitungan lembur/pulang-cepat di sini karena tidak ada
            // jam_masuk sebagai pembanding shift - itu murni urusan admin
            // memperbaiki manual lewat histori_absensi.php kalau perlu.
            // Alasan wajib diisi di sini juga (bukan cuma dianjurkan) - ini titik
            // paling wajar untuk menagihnya karena karyawan sendiri yang baru
            // sadar lupa absen masuk, sebelum Admin/SPV sempat menanyakannya
            // lewat histori_absensi.php.
            // Verifikasi wajah (token + foto) sudah dikonsumsi di blok
            // $needs_face di atas - $face_verified/$face_confidence di sini
            // berasal dari situ (server), bukan dari input klien.
            $alasan_tidak_masuk = isset($_POST['alasan']) ? sanitizeInput($_POST['alasan']) : '';
            if (strlen($alasan_tidak_masuk) < 5) {
                $stmt_check->close();
                outputJSON([
                    'success' => false,
                    'message' => 'Mohon isi alasan kenapa belum absen masuk hari ini (minimal 5 karakter).',
                    'type' => 'alasan_required'
                ]);
            }

            $validasi_lokasi = validateLokasiAbsen($lokasi, $id_karyawan, $conn);
            if (!$validasi_lokasi['valid'] && !isset($validasi_lokasi['bypass'])) {
                $stmt_check->close();
                outputJSON([
                    'success' => false,
                    'message' => $validasi_lokasi['message'],
                    'jarak' => $validasi_lokasi['jarak'],
                    'radius' => $validasi_lokasi['radius'],
                    'type' => 'location_error'
                ]);
            }

            $conn->begin_transaction();
            try {
                $stmt_final_check = $conn->prepare("SELECT id FROM absensi WHERE id_karyawan = ? AND tanggal = ? FOR UPDATE");
                $stmt_final_check->bind_param("ss", $id_karyawan, $tanggal);
                $stmt_final_check->execute();
                if ($stmt_final_check->get_result()->num_rows > 0) {
                    $conn->rollback();
                    $stmt_final_check->close();
                    $stmt_check->close();
                    outputJSON(['success' => false, 'message' => 'Absensi sudah tercatat.']);
                }
                $stmt_final_check->close();

                // status_masuk SENGAJA tidak diisi (NULL) - tabel ini defaultnya
                // 'Tepat Waktu' kalau kolomnya dilewati, yang justru menyesatkan
                // (seolah ada absen masuk yang tepat waktu). Ditulis eksplisit
                // NULL di sini supaya anomalinya benar-benar tampak kosong.
                // alasan/waktu_alasan dipakai di sini untuk "kenapa lupa absen
                // masuk" - baris jenis ini tidak pernah lewat alur alasan
                // check-in yang lain (Dinas Luar dkk.), jadi tidak bentrok makna.
                $waktu_alasan_tidak_masuk = date('Y-m-d H:i:s');
                $stmt_insert_pulang_saja = $conn->prepare(
                    "INSERT INTO absensi (id_karyawan, tanggal, jam_pulang, lokasi_pulang, keterangan, status_masuk, face_verified, face_confidence, input_method, alasan, waktu_alasan)
                     VALUES (?, ?, ?, ?, 'Hadir', NULL, 1, ?, 'qr_scan', ?, ?)"
                );
                $stmt_insert_pulang_saja->bind_param("ssssdss", $id_karyawan, $tanggal, $waktu, $lokasi, $face_confidence, $alasan_tidak_masuk, $waktu_alasan_tidak_masuk);

                if ($stmt_insert_pulang_saja->execute()) {
                    if ($capture_ready) saveAttendanceCapture($conn, $conn->insert_id, 'pulang', $capture_bytes);
                    logActivity($conn, 'absen_pulang_tanpa_masuk', "Absen pulang jam $waktu tanpa absen masuk sebelumnya - perlu ditinjau", $id_karyawan);
                    if (!$conn->commit()) throw new RuntimeException('Gagal menyimpan absensi pulang.');
                    $stmt_insert_pulang_saja->close();
                    $stmt_check->close();
                    outputJSON([
                        'success' => true,
                        'title' => 'Absen Pulang Tercatat',
                        'message' => "Absen pulang jam $waktu berhasil dicatat.<br><br>Karena tidak ada absen masuk hari ini, data ini akan ditandai untuk ditinjau Admin/Supervisor - Anda mungkin akan dihubungi untuk konfirmasi."
                    ]);
                } else {
                    $conn->rollback();
                    $stmt_insert_pulang_saja->close();
                    $stmt_check->close();
                    outputJSON(['success' => false, 'message' => 'Gagal merekam absensi pulang.']);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $stmt_check->close();
                outputJSON(['success' => false, 'message' => 'Gagal merekam absensi pulang: ' . $e->getMessage()]);
            }
        }

        if ($data_absen['jam_pulang'] != NULL && $data_absen['jam_pulang'] != '00:00:00') {
            $stmt_check->close();
            outputJSON(['success' => false, 'message' => 'Anda sudah absen pulang hari ini.']);
        }

        // VALIDASI LOKASI untuk absen pulang (hanya jika keterangan aslinya adalah Hadir)
        $stmt_get_ket = $conn->prepare("SELECT keterangan, izin_pulang_cepat FROM absensi WHERE id = ?");
        $stmt_get_ket->bind_param("i", $data_absen['id']);
        $stmt_get_ket->execute();
        $ket_data = $stmt_get_ket->get_result()->fetch_assoc();
        $stmt_get_ket->close();
        
        $is_hadir_masuk = ($ket_data['keterangan'] === 'Hadir');
        $is_pending_dinas = ($ket_data['keterangan'] === 'Pending Dinas');
        $is_dinas_luar_status = ($ket_data['keterangan'] === 'Dinas Luar');
        
        if ($is_pending_dinas) {
            $stmt_check->close();
            outputJSON(['success' => false, 'message' => 'Absensi masuk Anda masih menunggu persetujuan Dinas Luar. Harap tunggu Admin menyetujui, atau periksa kembali statusnya.']);
        }
        
        if ($is_hadir_masuk) {
            // Validasi GPS untuk pulang
            $validasi_lokasi = validateLokasiAbsen($lokasi, $id_karyawan, $conn);
            if (!$validasi_lokasi['valid'] && !isset($validasi_lokasi['bypass'])) {
                $stmt_check->close();
                outputJSON([
                    'success' => false,
                    'message' => $validasi_lokasi['message'],
                    'jarak' => $validasi_lokasi['jarak'],
                    'radius' => $validasi_lokasi['radius'],
                    'type' => 'location_error'
                ]);
            }
        }

        if ($is_hadir_masuk || $is_dinas_luar_status) {
            // Validasi Face untuk pulang (WAJIB jika masuknya Hadir atau Dinas Luar)
            if ($face_confidence === null || $face_confidence < $MIN_FACE_CONFIDENCE) {
                $stmt_check->close();
                outputJSON([
                    'success' => false,
                    'message' => '🔒 Verifikasi wajah wajib dilakukan untuk absen pulang. Confidence score minimal ' . $MIN_FACE_CONFIDENCE . '% diperlukan.',
                    'type' => 'face_required'
                ]);
            }
        }

        // ============== CEK PULANG CEPAT =================
        // Overtime lewat jam pulang shift TIDAK LAGI otomatis terdeteksi/
        // diblokir di sini - sejak fitur izin lembur (pengajuan_lembur), jam
        // lemburnya dihitung nanti di slip gaji dari selisih jam_pulang aktual
        // vs jam pulang shift, HANYA untuk hari yang punya izin lembur
        // Disetujui (lihat hitungJamLemburDisetujui() di lembur_functions.php).
        // Karyawan yang pulang telat tanpa izin lembur tetap absen normal -
        // tidak dianggap lembur berbayar, tapi juga tidak diblokir/dipaksa
        // isi alasan+foto di sini.
        if ($is_hadir_masuk && !isHariOvertime($conn, $tanggal)) {
            $stmt_jam_pulang = $conn->prepare("
                SELECT jk.jam_pulang
                FROM jam_kerja jk
                WHERE jk.id_cabang = ?
                ORDER BY ABS(TIMESTAMPDIFF(MINUTE, ?, jk.jam_masuk_akhir)) ASC
                LIMIT 1
            ");
            $stmt_jam_pulang->bind_param("is", $id_cabang, $data_absen['jam_masuk']);
            $stmt_jam_pulang->execute();
            $result_jam_pulang = $stmt_jam_pulang->get_result();
            if ($result_jam_pulang->num_rows > 0) {
                $target_jam_pulang = $result_jam_pulang->fetch_assoc()['jam_pulang'];
                if ($waktu < $target_jam_pulang && $ket_data['izin_pulang_cepat'] !== 'Disetujui') {
                    // Pulang lebih awal dari jam pulang shift wajib punya izin
                    // pulang cepat yang sudah Disetujui Admin/Supervisor.
                    $stmt_jam_pulang->close();
                    $stmt_check->close();
                    $pesan_pulang_cepat = $ket_data['izin_pulang_cepat'] === 'Pending'
                        ? 'Pengajuan izin pulang cepat Anda masih menunggu persetujuan. Silakan tunggu, atau absen pulang pada jam pulang normal.'
                        : 'Anda mencoba pulang sebelum jam pulang. Ajukan izin pulang cepat terlebih dahulu lewat menu "Izin Pulang Cepat" pada halaman ini.';
                    outputJSON([
                        'success' => false,
                        'message' => $pesan_pulang_cepat,
                        'type' => 'pulang_cepat_required'
                    ]);
                }
            }
            $stmt_jam_pulang->close();
        }

        $alasan_pulang = isset($_POST['alasan_pulang']) ? sanitizeInput($_POST['alasan_pulang']) : null;
        $foto_pulang_name = null;
        // ==============================================

        // $capture_bytes sudah dibaca+divalidasi di blok $needs_face di atas
        // kalau memang butuh wajah - jangan baca file upload yang sama dua kali.
        if ($capture_ready && $capture_bytes === null && ($is_hadir_masuk || $is_dinas_luar_status || isset($_FILES['foto_capture']))) {
            $capture_bytes = readAttendanceCapture($_FILES['foto_capture'] ?? null);
        }
        $conn->begin_transaction();
        // Update dengan data face jika ada
        // $face_verified and $face_confidence come only from the consumed server receipt.
        
        if ($face_verified) {
            $stmt_update = $conn->prepare("UPDATE absensi SET jam_pulang = ?, lokasi_pulang = ?, face_verified = 1, face_confidence = ?, alasan_pulang = ?, foto_pulang = ? WHERE id = ? AND (jam_pulang IS NULL OR jam_pulang = '00:00:00')");
            $stmt_update->bind_param("ssdssi", $waktu, $lokasi, $face_confidence, $alasan_pulang, $foto_pulang_name, $data_absen['id']);
        } else {
            $stmt_update = $conn->prepare("UPDATE absensi SET jam_pulang = ?, lokasi_pulang = ?, alasan_pulang = ?, foto_pulang = ? WHERE id = ? AND (jam_pulang IS NULL OR jam_pulang = '00:00:00')");
            $stmt_update->bind_param("ssssi", $waktu, $lokasi, $alasan_pulang, $foto_pulang_name, $data_absen['id']);
        }
        
        if ($stmt_update->execute() && $stmt_update->affected_rows > 0) {
            if ($capture_ready) saveAttendanceCapture($conn, $data_absen['id'], 'pulang', $capture_bytes);
            $log_message = "Absen pulang jam $waktu" . ($face_verified ? " (Face Verified: {$face_confidence}%)" : "");
            logActivity($conn, 'absen_pulang', $log_message, $id_karyawan);
            
            if ($face_verified) {
                $stmt_face_log = $conn->prepare(
                    "INSERT INTO face_recognition_logs (id_karyawan, attempt_type, status, confidence_score, ip_address) 
                     VALUES (?, 'verification', 'success', ?, ?)"
                );
                $ip = $_SERVER['REMOTE_ADDR'];
                $stmt_face_log->bind_param("sds", $id_karyawan, $face_confidence, $ip);
                $stmt_face_log->execute();
                $stmt_face_log->close();
            }
            
            if (!$conn->commit()) throw new RuntimeException('Gagal menyimpan absensi pulang.');
            $stmt_update->close();
            $stmt_check->close();
            
            outputJSON([
                'success' => true, 
                'message' => "Absensi pulang berhasil direkam pada jam $waktu." . ($face_verified ? " <br><small style='color: #28a745;'>🛡️ Wajah terverifikasi ({$face_confidence}%)</small>" : ""), 
                'title' => "Selamat Beristirahat!"
            ]);
        } else {
            $conn->rollback();
            $stmt_update->close();
            $stmt_check->close();
            outputJSON(['success' => false, 'message' => 'Gagal merekam absensi pulang.']);
        }

    } else {
        // ============== PROSES ABSEN MASUK ==============
        // Dinas luar yang sudah disetujui di muka langsung final; dinas dadakan
        // tetap masuk antrean 'Pending Dinas' untuk di-ACC admin di hari-H.
        if ($izin_dinas_hari_ini) {
            $keterangan = 'Dinas Luar';
        } else {
            $keterangan = $is_dinas_luar ? 'Pending Dinas' : $keterangan_param;
        }
        $status_masuk = 'Tepat Waktu';
        $menit_terlambat = null;

        // Keterlambatan hanya berlaku pada HARI KERJA normal. Pada hari lembur
        // (mis. Sabtu) jam masuk memang tidak tetap - bisa 10:00 tergantung
        // penugasan - sehingga membandingkannya dengan jam_masuk_akhir shift
        // akan salah menandai 'Terlambat' dan memotong gaji lewat
        // rate_keterlambatan. Hari libur nasional diperlakukan sama.
        $hari_kerja_normal = isHariKerja($conn, $tanggal)
            && empty(getHariLibur($conn, $tanggal, $tanggal, $id_cabang));

        // Cek status keterlambatan HANYA untuk 'Hadir' (Pending Dinas kita anggap Tepat Waktu sementara, atau bisa juga terlambat jika mau)
        if ($keterangan == 'Hadir' && $hari_kerja_normal) {
            $stmt_jam = $conn->prepare("SELECT jam_masuk_akhir FROM jam_kerja WHERE id_cabang = ?");
            $stmt_jam->bind_param("i", $id_cabang);
            $stmt_jam->execute();
            $rules = $stmt_jam->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_jam->close();

            if (!empty($rules)) {
                $target_rule = null;
                if (count($rules) == 1) {
                    $target_rule = $rules[0];
                } else {
                    $min_diff = PHP_INT_MAX;
                    foreach ($rules as $rule) {
                        $diff = abs(strtotime($waktu) - strtotime($rule['jam_masuk_akhir']));
                        if ($diff < $min_diff) {
                            $min_diff = $diff;
                            $target_rule = $rule;
                        }
                    }
                }
                if ($target_rule) {
                    // Menit mentah lewat jam_masuk_akhir shift - fakta historis,
                    // independen dari tarif dispensasi/potongan (yang bisa
                    // berubah kapan saja lewat system_settings). Status
                    // 'Terlambat' baru berlaku setelah lewat masa dispensasi
                    // (default 10 menit), bukan begitu lewat jam_masuk_akhir
                    // persis - lihat keterlambatan_functions.php.
                    $selisih_menit = (int)round((strtotime($waktu) - strtotime($target_rule['jam_masuk_akhir'])) / 60);
                    if ($selisih_menit > 0) {
                        $menit_terlambat = $selisih_menit;
                        if (apakahTerlambat($menit_terlambat, getPengaturanKeterlambatan($conn, $id_cabang))) {
                            $status_masuk = 'Terlambat';
                        }
                    }
                }
            }
        }

        // Face verified status
        // $face_verified and $face_confidence come only from the consumed server receipt.

        // $capture_bytes sudah dibaca+divalidasi di blok $needs_face di atas
        // kalau memang butuh wajah - jangan baca file upload yang sama dua kali.
        if ($capture_ready && $capture_bytes === null && ($keterangan_param === 'Hadir' || isset($_FILES['foto_capture']))) {
            $capture_bytes = readAttendanceCapture($_FILES['foto_capture'] ?? null);
        }
        // Insert absensi masuk
        $conn->begin_transaction();
        try {
            $stmt_final_check = $conn->prepare("SELECT id FROM absensi WHERE id_karyawan = ? AND tanggal = ? FOR UPDATE");
            $stmt_final_check->bind_param("ss", $id_karyawan, $tanggal);
            $stmt_final_check->execute();
            
            if ($stmt_final_check->get_result()->num_rows > 0) {
                $conn->rollback();
                $stmt_final_check->close();
                $stmt_check->close();
                outputJSON(['success' => false, 'message' => 'Absensi sudah tercatat.']);
            }
            $stmt_final_check->close();

            // Handle Alasan dan Foto Bukti
            $alasan = isset($_POST['alasan']) ? sanitizeInput($_POST['alasan']) : null;
            
            $foto_bukti_name = null;
            if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
                $upload_dir = __DIR__ . '/assets/uploads/absensi/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $ext = strtolower(pathinfo($_FILES['foto_bukti']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png'];
                if (in_array($ext, $allowed_ext) && $_FILES['foto_bukti']['size'] <= 6 * 1024 * 1024) {
                    $foto_bukti_name = $id_karyawan . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['foto_bukti']['tmp_name'], $upload_dir . $foto_bukti_name);
                }
            }
            
            $waktu_alasan = ($alasan !== null) ? date('Y-m-d H:i:s') : null;

            // Insert data
            if ($face_verified) {
                $stmt_insert = $conn->prepare(
                    "INSERT INTO absensi (id_karyawan, tanggal, jam_masuk, lokasi_masuk, keterangan, status_masuk, menit_terlambat, face_verified, face_confidence, alasan, foto_bukti, waktu_alasan)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
                );
                $stmt_insert->bind_param("ssssssidsss", $id_karyawan, $tanggal, $waktu, $lokasi, $keterangan, $status_masuk, $menit_terlambat, $face_confidence, $alasan, $foto_bukti_name, $waktu_alasan);
            } else {
                $stmt_insert = $conn->prepare(
                    "INSERT INTO absensi (id_karyawan, tanggal, jam_masuk, lokasi_masuk, keterangan, status_masuk, menit_terlambat, alasan, foto_bukti, waktu_alasan)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt_insert->bind_param("ssssssisss", $id_karyawan, $tanggal, $waktu, $lokasi, $keterangan, $status_masuk, $menit_terlambat, $alasan, $foto_bukti_name, $waktu_alasan);
            }
            
            if ($stmt_insert->execute()) {
                if ($capture_ready) saveAttendanceCapture($conn, $conn->insert_id, 'masuk', $capture_bytes);
                $log_message = "Absen $keterangan ($status_masuk)" . ($face_verified ? " - Face Verified: {$face_confidence}%" : "");
                logActivity($conn, 'absen_masuk', $log_message, $id_karyawan);

                if ($face_verified) {
                    $stmt_face_log = $conn->prepare(
                        "INSERT INTO face_recognition_logs (id_karyawan, attempt_type, status, confidence_score, ip_address) 
                         VALUES (?, 'verification', 'success', ?, ?)"
                    );
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $stmt_face_log->bind_param("sds", $id_karyawan, $face_confidence, $ip);
                    $stmt_face_log->execute();
                    $stmt_face_log->close();
                }

                if (!$conn->commit()) throw new RuntimeException('Gagal menyimpan absensi masuk.');
                $stmt_insert->close();
                $stmt_check->close();

                // Pesan sukses dinamis
                $pesan_sukses = "Absensi masuk Anda ($keterangan) berhasil direkam pada jam $waktu.";
                $judul_sukses = "Terima Kasih!";

                switch ($keterangan) {
                    // Sakit & Cuti tidak lagi muncul di sini - keduanya dibelokkan
                    // ke pengajuan_izin (Pending) lebih awal di atas.
                    case 'OFF':
                        $judul_sukses = "Selamat Libur!";
                        $pesan_sukses = "Absensi OFF telah dicatat. Selamat menikmati hari libur.";
                        break;
                    case 'Alpha':
                        $judul_sukses = "Konfirmasi Absen";
                        $pesan_sukses = "Status ALPHA telah dicatat. Silakan konfirmasi dengan atasan jika ada kendala.";
                        break;
                    case 'Pending Dinas':
                        $judul_sukses = "Permintaan Dinas Terkirim";
                        $pesan_sukses = "Permohonan Dinas Luar Anda telah dikirim dan menunggu persetujuan Admin.";
                        if ($face_verified) {
                            $pesan_sukses .= "<br><small style='color: #28a745;'>🛡️ Wajah terverifikasi ({$face_confidence}%)</small>";
                        }
                        break;
                    case 'Hadir':
                        $judul_sukses = "Selamat Bekerja!";
                        $pesan_sukses = "Terima kasih, absensi masuk berhasil direkam pada jam $waktu.";
                        if ($face_verified) {
                            $pesan_sukses .= "<br><small style='color: #28a745;'>🛡️ Wajah terverifikasi ({$face_confidence}%)</small>";
                        }
                        break;
                }

                outputJSON([
                    'success' => true,
                    'message' => $pesan_sukses,
                    'title' => $judul_sukses,
                    'face_verified' => $face_verified
                ]);
            } else {
                $conn->rollback();
                $stmt_insert->close();
                $stmt_check->close();
                outputJSON(['success' => false, 'message' => 'Gagal merekam absensi masuk: ' . $conn->error]);
            }

        } catch (Exception $e) {
            $conn->rollback();
            $stmt_check->close();
            error_log("Error absensi: " . $e->getMessage());
            outputJSON(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
        }
    }

    $stmt_check->close();

} catch (RuntimeException $e) {
    $conn->rollback();
    outputJSON(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    $conn->rollback();
    error_log("Fatal error in proses_absen.php: " . $e->getMessage());
    outputJSON([
        'success' => false, 
        'message' => 'Terjadi kesalahan fatal pada server. Silakan coba lagi atau hubungi administrator.',
        'error_detail' => $e->getMessage()
    ]);
}
?>
