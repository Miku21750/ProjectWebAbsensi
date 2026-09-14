<?php
require 'config.php';

// Cek login dan admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

// Aktifkan error reporting untuk debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --- PROSES JAM KERJA ---
if (isset($_POST['simpan_pengaturan_keterlambatan'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');

    $id_cabang = filter_input(INPUT_POST, 'id_cabang', FILTER_VALIDATE_INT);
    $grace = filter_input(INPUT_POST, 'grace_menit', FILTER_VALIDATE_INT);
    $tier1_durasi = filter_input(INPUT_POST, 'tier1_durasi_menit', FILTER_VALIDATE_INT);
    $tier2_interval = filter_input(INPUT_POST, 'tier2_interval_menit', FILTER_VALIDATE_INT);
    $tier1_rate = filter_input(INPUT_POST, 'tier1_rate', FILTER_VALIDATE_FLOAT);
    $tier2_rate = filter_input(INPUT_POST, 'tier2_rate', FILTER_VALIDATE_FLOAT);
    $maks_jam = filter_input(INPUT_POST, 'maks_jam', FILTER_VALIDATE_FLOAT);

    $valid = $id_cabang && $grace !== false && $grace >= 0 && $grace <= 1440
        && $tier1_durasi !== false && $tier1_durasi >= 1 && $tier1_durasi <= 1440
        && $tier2_interval !== false && $tier2_interval >= 1 && $tier2_interval <= 1440
        && $tier1_rate !== false && $tier1_rate >= 0
        && $tier2_rate !== false && $tier2_rate >= 0
        && $maks_jam !== false && $maks_jam > 0 && $maks_jam <= 24
        && ($maks_jam * 60) > $grace;

    $stmt_cabang = $conn->prepare("SELECT id FROM cabang WHERE id = ?");
    $stmt_cabang->bind_param('i', $id_cabang);
    $stmt_cabang->execute();
    $cabang_ada = (bool)$stmt_cabang->get_result()->fetch_assoc();
    $stmt_cabang->close();

    if (!$valid || !$cabang_ada) {
        $_SESSION['error_message'] = 'Pengaturan keterlambatan tidak valid. Periksa kembali seluruh nilai yang diisi.';
        header('Location: ' . ($id_cabang ? 'admin_detail_cabang.php?id=' . (int)$id_cabang : 'data_cabang.php'));
        exit();
    }

    $nilai = [
        'grace_menit' => (string)$grace,
        'tier1_durasi_menit' => (string)$tier1_durasi,
        'tier1_rate' => (string)$tier1_rate,
        'tier2_interval_menit' => (string)$tier2_interval,
        'tier2_rate' => (string)$tier2_rate,
        'maks_jam' => (string)$maks_jam,
    ];
    $deskripsi = [
        'grace_menit' => 'Dispensasi keterlambatan cabang (menit)',
        'tier1_durasi_menit' => 'Durasi tier pertama keterlambatan cabang (menit)',
        'tier1_rate' => 'Potongan flat tier pertama keterlambatan cabang (Rp)',
        'tier2_interval_menit' => 'Interval tier kedua keterlambatan cabang (menit)',
        'tier2_rate' => 'Potongan per interval tier kedua keterlambatan cabang (Rp)',
        'maks_jam' => 'Batas maksimal keterlambatan yang dihitung untuk cabang (jam)',
    ];
    $keys = getKunciPengaturanKeterlambatan();

    try {
        $conn->begin_transaction();
        foreach ($nilai as $nama => $value) {
            $key = getKunciPengaturanKeterlambatanCabang($id_cabang, $keys[$nama]);
            if (!setPengaturan($conn, $key, $value, $deskripsi[$nama])) {
                throw new RuntimeException('Gagal menyimpan ' . $nama);
            }
        }
        $conn->commit();
        $_SESSION['success_message'] = 'Pengaturan keterlambatan cabang berhasil disimpan!';
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['error_message'] = 'Gagal menyimpan pengaturan keterlambatan: ' . $e->getMessage();
    }

    header('Location: admin_detail_cabang.php?id=' . (int)$id_cabang);
    exit();
}

if (isset($_POST['tambah_jam_kerja'])) {
    $id_cabang = intval($_POST['id_cabang']);
    $nama_shift = $conn->real_escape_string($_POST['nama_shift']);
    $jam_masuk_akhir = $conn->real_escape_string($_POST['jam_masuk_akhir']);
    $jam_pulang = $conn->real_escape_string($_POST['jam_pulang']);

    $stmt = $conn->prepare("INSERT INTO jam_kerja (id_cabang, nama_shift, jam_masuk_akhir, jam_pulang) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $id_cabang, $nama_shift, $jam_masuk_akhir, $jam_pulang);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Aturan jam kerja berhasil ditambahkan!";
    } else {
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: jam_kerja.php");
    exit();
}

if (isset($_POST['edit_jam_kerja'])) {
    $id = intval($_POST['id_jam_kerja']);
    $id_cabang = intval($_POST['id_cabang']);
    $nama_shift = $conn->real_escape_string($_POST['nama_shift']);
    $jam_masuk_akhir = $conn->real_escape_string($_POST['jam_masuk_akhir']);
    $jam_pulang = $conn->real_escape_string($_POST['jam_pulang']);

    $stmt = $conn->prepare("UPDATE jam_kerja SET id_cabang=?, nama_shift=?, jam_masuk_akhir=?, jam_pulang=? WHERE id=?");
    $stmt->bind_param("isssi", $id_cabang, $nama_shift, $jam_masuk_akhir, $jam_pulang, $id);
    if ($stmt->execute()) {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Aturan jam kerja berhasil diperbarui!']);
            exit();
        }
        $_SESSION['success_message'] = "Aturan jam kerja berhasil diperbarui!";
    } else {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
            exit();
        }
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: jam_kerja.php");
    exit();
}

if (isset($_GET['hapus_jam_kerja'])) {
    $id = intval($_GET['hapus_jam_kerja']);
    $stmt = $conn->prepare("DELETE FROM jam_kerja WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Aturan jam kerja berhasil dihapus!']);
            exit();
        }
        $_SESSION['success_message'] = "Aturan jam kerja berhasil dihapus!";
    } else {
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
            exit();
        }
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: jam_kerja.php");
    exit();
}


// --- PROSES JABATAN ---
if (isset($_POST['tambah_jabatan'])) {
    $nama_jabatan = $conn->real_escape_string($_POST['nama_jabatan']);
    
    // Hilangkan titik dari input rupiah
    $tunjangan_str = $_POST['tunjangan_jabatan'] ?? '0';
    $tunjangan_str = str_replace('.', '', $tunjangan_str);
    $tunjangan_jabatan = floatval($tunjangan_str);
    
    // Kelayakan lembur Sabtu (tidak semua jabatan punya lembur)
    $overtime_sabtu = isset($_POST['overtime_sabtu']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO jabatan (nama_jabatan, tunjangan_jabatan, overtime_sabtu) VALUES (?, ?, ?)");
    $stmt->bind_param("sdi", $nama_jabatan, $tunjangan_jabatan, $overtime_sabtu);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Jabatan berhasil ditambahkan!";
    } else {
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: data_jabatan.php");
    exit();
}

if (isset($_POST['edit_jabatan'])) {
    $id = intval($_POST['id_jabatan']);
    $nama_jabatan = $conn->real_escape_string($_POST['nama_jabatan']);
    
    // Hilangkan titik dari input rupiah
    $tunjangan_str = $_POST['tunjangan_jabatan'] ?? '0';
    $tunjangan_str = str_replace('.', '', $tunjangan_str);
    $tunjangan_jabatan = floatval($tunjangan_str);
    
    $overtime_sabtu = isset($_POST['overtime_sabtu']) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE jabatan SET nama_jabatan = ?, tunjangan_jabatan = ?, overtime_sabtu = ? WHERE id = ?");
    $stmt->bind_param("sdii", $nama_jabatan, $tunjangan_jabatan, $overtime_sabtu, $id);
    if ($stmt->execute()) {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Jabatan berhasil diperbarui!']);
            exit();
        }
        $_SESSION['success_message'] = "Jabatan berhasil diperbarui!";
    } else {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
            exit();
        }
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: data_jabatan.php");
    exit();
}

if (isset($_GET['hapus_jabatan'])) {
    $id = intval($_GET['hapus_jabatan']);
    try {
        $stmt = $conn->prepare("DELETE FROM jabatan WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Jabatan berhasil dihapus!']);
            exit();
        }
        $_SESSION['success_message'] = "Jabatan berhasil dihapus!";
    } catch (mysqli_sql_exception $e) {
        $msg = "Terjadi kesalahan pada database.";
        if ($e->getCode() == 1451) {
            $msg = "Gagal menghapus! Jabatan ini masih digunakan oleh karyawan.";
        }
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => $msg]);
            exit();
        }
        $_SESSION['error_message'] = $msg;
    }
    header("Location: data_jabatan.php");
    exit();
}

// --- PROSES CABANG ---
// Tambah Cabang
if (isset($_POST['tambah_cabang'])) {
    $nama_cabang = $conn->real_escape_string($_POST['nama_cabang']);
    $alamat_cabang = $conn->real_escape_string($_POST['alamat_cabang']);
    
    // TAMBAHAN BARU - Ambil koordinat & radius
    $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : NULL;
    $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : NULL;
    $radius_meter = !empty($_POST['radius_meter']) ? intval($_POST['radius_meter']) : 100;
    
    // Query INSERT dengan prepared statement
    $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang, alamat_cabang, latitude, longitude, radius_meter) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssddi", $nama_cabang, $alamat_cabang, $latitude, $longitude, $radius_meter);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Cabang berhasil ditambahkan dengan koordinat lokasi!";
    } else {
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: data_cabang.php");
    exit();
}

if (isset($_POST['edit_cabang'])) {
    $id = intval($_POST['id_cabang']);
    $nama_cabang = $conn->real_escape_string($_POST['nama_cabang']);
    $alamat_cabang = $conn->real_escape_string($_POST['alamat_cabang']);
    
    // TAMBAHAN BARU - Ambil koordinat & radius
    $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : NULL;
    $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : NULL;
    $radius_meter = !empty($_POST['radius_meter']) ? intval($_POST['radius_meter']) : 100;
    
    // Query UPDATE dengan prepared statement
    $stmt = $conn->prepare("UPDATE cabang SET nama_cabang = ?, alamat_cabang = ?, latitude = ?, longitude = ?, radius_meter = ? WHERE id = ?");
    $stmt->bind_param("ssddii", $nama_cabang, $alamat_cabang, $latitude, $longitude, $radius_meter, $id);
    
    if ($stmt->execute()) {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Cabang berhasil diperbarui!']);
            exit();
        }
        $_SESSION['success_message'] = "Cabang berhasil diperbarui!";
    } else {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
            exit();
        }
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: data_cabang.php");
    exit();
}

if (isset($_GET['hapus_cabang'])) {
    $id = intval($_GET['hapus_cabang']);
    try {
        $stmt = $conn->prepare("DELETE FROM cabang WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Cabang berhasil dihapus!']);
            exit();
        }
        $_SESSION['success_message'] = "Cabang berhasil dihapus!";
    } catch (mysqli_sql_exception $e) {
        $msg = "Terjadi kesalahan pada database.";
        if ($e->getCode() == 1451) {
            $msg = "Gagal menghapus! Cabang ini masih memiliki karyawan.";
        }
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => $msg]);
            exit();
        }
        $_SESSION['error_message'] = $msg;
    }
    header("Location: data_cabang.php");
    exit();
}

// --- PROSES KARYAWAN (DENGAN CASCADE DELETE) ---
if (isset($_POST['tambah_karyawan'])) {
    $id_karyawan = $conn->real_escape_string($_POST['id_karyawan']);
    $nama_karyawan = $conn->real_escape_string($_POST['nama_karyawan']);
    $id_jabatan = intval($_POST['id_jabatan']);
    $id_cabang = intval($_POST['id_cabang']);
    $jenis_kelamin = $conn->real_escape_string($_POST['jenis_kelamin'] ?? 'L');
    $stmt = $conn->prepare("INSERT INTO karyawan (id_karyawan, nama_karyawan, id_jabatan, id_cabang, jenis_kelamin) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiis", $id_karyawan, $nama_karyawan, $id_jabatan, $id_cabang, $jenis_kelamin);
    if ($stmt->execute()) {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Karyawan berhasil ditambahkan!']);
            exit();
        }
        $_SESSION['success_message'] = "Karyawan berhasil ditambahkan!";
    } else {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
            exit();
        }
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: data_karyawan.php");
    exit();
}

if (isset($_POST['edit_karyawan'])) {
    $id = intval($_POST['id_karyawan_pk']);
    $nama_karyawan = $conn->real_escape_string($_POST['nama_karyawan']);
    $id_jabatan = intval($_POST['id_jabatan']);
    $id_cabang = intval($_POST['id_cabang']);
    $jenis_kelamin = $conn->real_escape_string($_POST['jenis_kelamin'] ?? 'L');
    $stmt = $conn->prepare("UPDATE karyawan SET nama_karyawan = ?, id_jabatan = ?, id_cabang = ?, jenis_kelamin = ? WHERE id = ?");
    $stmt->bind_param("siisi", $nama_karyawan, $id_jabatan, $id_cabang, $jenis_kelamin, $id);
    if ($stmt->execute()) {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Data karyawan berhasil diperbarui!']);
            exit();
        }
        $_SESSION['success_message'] = "Data karyawan berhasil diperbarui!";
    } else {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
            exit();
        }
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    header("Location: data_karyawan.php");
    exit();
}

if (isset($_GET['nonaktifkan_karyawan'])) {
    $id = intval($_GET['nonaktifkan_karyawan']);
    
    // Ambil id_karyawan
    $stmt_karyawan = $conn->prepare("SELECT id_karyawan, nama_karyawan FROM karyawan WHERE id = ?");
    $stmt_karyawan->bind_param("i", $id);
    $stmt_karyawan->execute();
    $result_karyawan = $stmt_karyawan->get_result();
    
    if ($result_karyawan->num_rows > 0) {
        $row = $result_karyawan->fetch_assoc();
        $id_karyawan_string = $row['id_karyawan'];
        $nama_karyawan = $row['nama_karyawan'];
        
        // Hapus user terkait agar tidak bisa login lagi
        $stmt_user = $conn->prepare("DELETE FROM users WHERE id_karyawan = ?");
        $stmt_user->bind_param("s", $id_karyawan_string);
        $stmt_user->execute();
        $stmt_user->close();
        
        // Ubah status menjadi nonaktif dan set tanggal resign
        $stmt_update = $conn->prepare("UPDATE karyawan SET status = 'nonaktif', tanggal_resign = CURDATE() WHERE id = ?");
        $stmt_update->bind_param("i", $id);
        if ($stmt_update->execute()) {
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'success', 'message' => htmlspecialchars($nama_karyawan) . " berhasil diarsipkan/dinonaktifkan."]);
                exit();
            }
            $_SESSION['success_message'] = htmlspecialchars($nama_karyawan) . " berhasil diarsipkan/dinonaktifkan.";
        } else {
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'error', 'message' => "Gagal menonaktifkan karyawan: " . $stmt_update->error]);
                exit();
            }
            $_SESSION['error_message'] = "Gagal menonaktifkan karyawan: " . $stmt_update->error;
        }
        $stmt_update->close();
    } else {
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => "Karyawan tidak ditemukan."]);
            exit();
        }
        $_SESSION['error_message'] = "Karyawan tidak ditemukan.";
    }
    
    $stmt_karyawan->close();
    if (!isset($_GET['is_ajax'])) {
        header("Location: data_karyawan.php");
        exit();
    }
}

if (isset($_GET['aktifkan_karyawan'])) {
    $id = intval($_GET['aktifkan_karyawan']);
    $stmt_update = $conn->prepare("UPDATE karyawan SET status = 'aktif', tanggal_resign = NULL WHERE id = ?");
    $stmt_update->bind_param("i", $id);
    if ($stmt_update->execute()) {
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'success', 'message' => "Karyawan berhasil diaktifkan kembali. Silakan buat akun baru di Setting User."]);
            exit();
        }
        $_SESSION['success_message'] = "Karyawan berhasil diaktifkan kembali. Silakan buat akun baru di Setting User.";
    } else {
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => "Gagal mengaktifkan karyawan: " . $stmt_update->error]);
            exit();
        }
        $_SESSION['error_message'] = "Gagal mengaktifkan karyawan: " . $stmt_update->error;
    }
    $stmt_update->close();
    if (!isset($_GET['is_ajax'])) {
        header("Location: data_karyawan.php?view=arsip");
        exit();
    }
}

if (isset($_GET['hapus_karyawan'])) {
    $id = intval($_GET['hapus_karyawan']);
    
    // Ambil detail karyawan dulu sebelum dihapus
    $stmt_karyawan = $conn->prepare("SELECT id_karyawan, nama_karyawan FROM karyawan WHERE id = ?");
    $stmt_karyawan->bind_param("i", $id);
    $stmt_karyawan->execute();
    $result_karyawan = $stmt_karyawan->get_result();
    
    if ($result_karyawan->num_rows > 0) {
        $row = $result_karyawan->fetch_assoc();
        $id_karyawan_string = $row['id_karyawan'];
        $nama_karyawan = $row['nama_karyawan'];
        
        // Histori Absensi dan Slip Gaji TIDAK dihapus agar tetap ada untuk keperluan Laporan / Tutup Buku
        // (Sesuai permintaan user, data riwayat tidak ikut terhapus meski karyawan dihapus)

        // Hapus user terkait secara eksplisit
        $stmt_user = $conn->prepare("DELETE FROM users WHERE id_karyawan = ?");
        $stmt_user->bind_param("s", $id_karyawan_string);
        $stmt_user->execute();
        $stmt_user->close();
        
        // Hapus karyawan
        $stmt_delete = $conn->prepare("DELETE FROM karyawan WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        
        if ($stmt_delete->execute()) {
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'success', 'message' => htmlspecialchars($nama_karyawan) . " beserta akun dan data terkait berhasil dihapus."]);
                exit();
            }
            $_SESSION['success_message'] = htmlspecialchars($nama_karyawan) . " beserta akun dan data terkait berhasil dihapus.";
        } else {
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'error', 'message' => "Gagal menghapus karyawan: " . $stmt_delete->error]);
                exit();
            }
            $_SESSION['error_message'] = "Gagal menghapus karyawan: " . $stmt_delete->error;
        }
        $stmt_delete->close();
        
    } else {
        if (isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => "Karyawan tidak ditemukan."]);
            exit();
        }
        $_SESSION['error_message'] = "Karyawan tidak ditemukan.";
    }
    
    $stmt_karyawan->close();
    if (!isset($_GET['is_ajax'])) {
        header("Location: data_karyawan.php");
        exit();
    }
}

// --- PROSES FACE PERMISSION ---
if (isset($_GET['action']) && isset($_GET['id_karyawan'])) {
    $action = $_GET['action'];
    $id_karyawan = $conn->real_escape_string($_GET['id_karyawan']);
    $sql = "";
    $msg = "";
    
    if ($action == 'allow_reset') {
        $sql = "UPDATE users SET face_reset_allowed = 1 WHERE id_karyawan = ?";
        $msg = "Izin reset wajah diberikan.";
    } elseif ($action == 'lock_face') {
        $sql = "UPDATE users SET face_reset_allowed = 0 WHERE id_karyawan = ?";
        $msg = "Izin reset wajah dicabut.";
    } elseif ($action == 'delete_face') {
        $sql = "UPDATE users SET face_descriptor = NULL, face_registered_at = NULL, face_reset_allowed = 0 WHERE id_karyawan = ?";
        $msg = "Data wajah berhasil dihapus. Karyawan harus mendaftar ulang.";
    }
    
    if ($sql !== "") {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $id_karyawan);
        if ($stmt->execute()) {
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'success', 'message' => $msg]);
                exit();
            }
            $_SESSION['success_message'] = $msg;
        } else {
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'error', 'message' => "Gagal memproses aksi wajah: " . $stmt->error]);
                exit();
            }
            $_SESSION['error_message'] = "Gagal memproses aksi wajah: " . $stmt->error;
        }
        $stmt->close();
    }
    
    if (!isset($_GET['is_ajax'])) {
        header("Location: setting_users.php");
        exit();
    }
}

// --- PROSES USER MANAGEMENT ---
if (isset($_POST['tambah_staff'])) {
    $id_karyawan = $conn->real_escape_string($_POST['id_karyawan_selected']);
    $password = $_POST['password'];
    
    // Username otomatis sama dengan ID Karyawan
    $username = $id_karyawan;
    $role = 'staff';
    
    if (empty($id_karyawan) || empty($password)) {
        $_SESSION['error_message'] = "Semua field harus diisi.";
    } else if (strlen($password) < 8) {
        $_SESSION['error_message'] = "Password minimal 8 karakter.";
    } else if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $_SESSION['error_message'] = "Password harus kombinasi huruf dan angka.";
    } else {
        // Ambil nama karyawan dari tabel karyawan
        $stmt_get_nama = $conn->prepare("SELECT nama_karyawan FROM karyawan WHERE id_karyawan = ?");
        $stmt_get_nama->bind_param("s", $id_karyawan);
        $stmt_get_nama->execute();
        $res_nama = $stmt_get_nama->get_result();
        
        if ($res_nama->num_rows == 0) {
            $_SESSION['error_message'] = "Karyawan tidak ditemukan.";
        } else {
            $nama_karyawan = $res_nama->fetch_assoc()['nama_karyawan'];
            
            // Cek apakah username sudah ada
            $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? OR id_karyawan = ?");
            $stmt_check->bind_param("ss", $username, $id_karyawan);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();
            
            if ($res_check->num_rows > 0) {
                $_SESSION['error_message'] = "Karyawan ini sudah memiliki akun.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt_insert = $conn->prepare("INSERT INTO users (nama, username, password, role, id_karyawan) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->bind_param("sssss", $nama_karyawan, $username, $hashed_password, $role, $id_karyawan);
                
                if ($stmt_insert->execute()) {
                    $_SESSION['success_message'] = "Akun staff untuk '$nama_karyawan' (Username: $username) berhasil dibuat!";
                } else {
                    $_SESSION['error_message'] = "Gagal membuat akun: " . $stmt_insert->error;
                }
            }
        }
    }
    
    if (isset($_POST['is_ajax'])) {
        if (isset($_SESSION['error_message'])) {
            $msg = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
            echo json_encode(['status' => 'error', 'message' => $msg]);
            exit();
        } else if (isset($_SESSION['success_message'])) {
            $msg = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
            echo json_encode(['status' => 'success', 'message' => $msg]);
            exit();
        }
    }
    
    header("Location: setting_users.php");
    exit();
}

    // Handler Tambah Owner
if (isset($_POST['tambah_owner'])) {
    $nama = sanitizeInput($_POST['nama_owner']);
    $username = sanitizeInput($_POST['username_owner']);
    $password = $_POST['password_owner'];
    
    // Validasi
    if (strlen($username) < 4 || strlen($password) < 8) {
        if (isset($_POST['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Username min 4 karakter, password min 8 karakter.']);
            exit();
        }
        $_SESSION['error_message'] = "Username min 4 karakter, password min 8 karakter.";
        header("Location: setting_users.php");
        exit();
    }
    
    // Cek username sudah ada atau belum
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt_check->bind_param("s", $username);
    $stmt_check->execute();
    
    if ($stmt_check->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "Username sudah digunakan.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $role = 'owner';
        
        $sql = "INSERT INTO users (nama, username, password, role) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $nama, $username, $hashed_password, $role);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Akun Owner berhasil dibuat.";
            logActivity($conn, 'create_user', "Buat akun owner: $username", null);
        } else {
            $_SESSION['error_message'] = "Gagal membuat akun owner.";
        }
    }
    
    if (isset($_POST['is_ajax'])) {
        if (isset($_SESSION['error_message'])) {
            $msg = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
            echo json_encode(['status' => 'error', 'message' => $msg]);
            exit();
        } else if (isset($_SESSION['success_message'])) {
            $msg = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
            echo json_encode(['status' => 'success', 'message' => $msg]);
            exit();
        }
    }
    
    header("Location: setting_users.php");
    exit();
}

// Handler Simpan Hari Libur (tambah / edit, mendukung rentang tanggal)
if (isset($_POST['simpan_hari_libur'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');

    $id_libur        = intval($_POST['id_libur'] ?? 0);
    $tanggal_mulai   = sanitizeInput($_POST['tanggal_mulai'] ?? '');
    $tanggal_selesai = sanitizeInput($_POST['tanggal_selesai'] ?? '');
    $nama            = sanitizeInput($_POST['nama'] ?? '');
    $jenis           = sanitizeInput($_POST['jenis'] ?? 'Nasional');
    $id_cabang       = ($_POST['id_cabang'] ?? '') === '' ? null : intval($_POST['id_cabang']);

    $cek = DateTime::createFromFormat('Y-m-d', $tanggal_mulai);
    if (!$cek || $cek->format('Y-m-d') !== $tanggal_mulai) {
        $_SESSION['error_message'] = "❌ Tanggal tidak valid.";
        header("Location: data_hari_libur.php");
        exit();
    }
    if ($nama === '') {
        $_SESSION['error_message'] = "❌ Keterangan hari libur wajib diisi.";
        header("Location: data_hari_libur.php");
        exit();
    }
    if (!in_array($jenis, ['Nasional', 'Cuti Bersama', 'Perusahaan'], true)) {
        $jenis = 'Nasional';
    }

    if ($id_libur > 0) {
        // Edit satu tanggal. perlu_verifikasi dinolkan: admin sudah memeriksanya.
        $stmt = $conn->prepare("UPDATE hari_libur
                                SET tanggal = ?, nama = ?, jenis = ?, id_cabang = ?, perlu_verifikasi = 0
                                WHERE id = ?");
        $stmt->bind_param("sssii", $tanggal_mulai, $nama, $jenis, $id_cabang, $id_libur);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "✅ Hari libur \"{$nama}\" berhasil diperbarui.";
            logActivity($conn, 'edit_hari_libur', "Mengubah hari libur #{$id_libur}: {$nama} ({$tanggal_mulai})", $_SESSION['user_id']);
        } else {
            $_SESSION['error_message'] = "❌ Gagal memperbarui hari libur. Pastikan tanggal tersebut belum terdaftar.";
        }
        $stmt->close();
    } else {
        // Tambah, boleh berupa rentang (mis. cuti bersama beberapa hari)
        if ($tanggal_selesai === '') {
            $tanggal_selesai = $tanggal_mulai;
        }
        $cek_akhir = DateTime::createFromFormat('Y-m-d', $tanggal_selesai);
        if (!$cek_akhir || $cek_akhir->format('Y-m-d') !== $tanggal_selesai || $tanggal_selesai < $tanggal_mulai) {
            $_SESSION['error_message'] = "❌ Tanggal selesai tidak valid.";
            header("Location: data_hari_libur.php");
            exit();
        }
        if ((strtotime($tanggal_selesai) - strtotime($tanggal_mulai)) / 86400 > 30) {
            $_SESSION['error_message'] = "❌ Rentang hari libur maksimal 31 hari sekali simpan.";
            header("Location: data_hari_libur.php");
            exit();
        }

        // INSERT IGNORE: tanggal yang sudah terdaftar dilewati, bukan error
        $stmt = $conn->prepare("INSERT IGNORE INTO hari_libur
                                (tanggal, nama, jenis, id_cabang, perlu_verifikasi, created_by)
                                VALUES (?, ?, ?, ?, 0, ?)");
        $tersimpan = 0;
        $dilewati  = 0;
        for ($ts = strtotime($tanggal_mulai); $ts <= strtotime($tanggal_selesai); $ts = strtotime('+1 day', $ts)) {
            $tgl = date('Y-m-d', $ts);
            $stmt->bind_param("sssii", $tgl, $nama, $jenis, $id_cabang, $_SESSION['user_id']);
            if ($stmt->execute() && $conn->affected_rows > 0) {
                $tersimpan++;
            } else {
                $dilewati++;
            }
        }
        $stmt->close();

        if ($tersimpan > 0) {
            $_SESSION['success_message'] = "✅ {$tersimpan} hari libur \"{$nama}\" berhasil ditambahkan."
                . ($dilewati > 0 ? " {$dilewati} tanggal dilewati karena sudah terdaftar." : "");
            logActivity($conn, 'tambah_hari_libur',
                "Menambah hari libur {$nama} ({$tanggal_mulai} s/d {$tanggal_selesai}), tersimpan {$tersimpan}",
                $_SESSION['user_id']);
        } else {
            $_SESSION['error_message'] = "❌ Semua tanggal pada rentang tersebut sudah terdaftar sebagai hari libur.";
        }
    }

    header("Location: data_hari_libur.php?tahun=" . date('Y', strtotime($tanggal_mulai)));
    exit();
}

// Handler Hapus Hari Libur
if (isset($_POST['hapus_hari_libur'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');

    $id_libur = intval($_POST['id_libur'] ?? 0);
    $nama     = sanitizeInput($_POST['nama_libur'] ?? '');

    if ($id_libur <= 0) {
        $_SESSION['error_message'] = "❌ Data hari libur tidak valid.";
    } else {
        $stmt = $conn->prepare("DELETE FROM hari_libur WHERE id = ?");
        $stmt->bind_param("i", $id_libur);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "✅ Hari libur \"{$nama}\" berhasil dihapus.";
            logActivity($conn, 'hapus_hari_libur', "Menghapus hari libur #{$id_libur}: {$nama}", $_SESSION['user_id']);
        } else {
            $_SESSION['error_message'] = "❌ Hari libur tidak ditemukan atau sudah dihapus.";
        }
        $stmt->close();
    }

    header("Location: data_hari_libur.php");
    exit();
}

// Handler Generate Hari Libur Otomatis (dari pratinjau kalender math di
// data_hari_libur.php / hari_libur_generator.php). Hanya baris yang
// dicentang admin di pratinjau yang benar-benar disimpan - checkbox yang
// tidak dicentang (mis. tanggal yang sudah terdaftar) tidak pernah masuk
// $_POST['pilih'], jadi indeksnya otomatis terlewati di bawah.
if (isset($_POST['generate_hari_libur'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');

    $tahun_redirect = intval($_POST['tahun'] ?? date('Y'));
    $pilih = $_POST['pilih'] ?? [];
    $tanggal_arr = $_POST['tanggal'] ?? [];
    $nama_arr = $_POST['nama'] ?? [];
    $jenis_arr = $_POST['jenis'] ?? [];
    $verifikasi_arr = $_POST['verifikasi'] ?? [];

    if (empty($pilih)) {
        $_SESSION['error_message'] = "❌ Tidak ada hari libur yang dipilih untuk disimpan.";
        header("Location: data_hari_libur.php?tahun={$tahun_redirect}");
        exit();
    }

    // PENTING: uniq_libur_tanggal_cabang (tanggal, id_cabang) TIDAK mencegah
    // duplikat di sini - MySQL menganggap NULL selalu berbeda dari NULL lain
    // di unique key, dan hari libur nasional selalu id_cabang NULL. Jadi
    // INSERT IGNORE saja tidak cukup; cek manual tanggal yang sudah ada dulu.
    $stmt_cek = $conn->prepare("SELECT tanggal FROM hari_libur WHERE id_cabang IS NULL AND tanggal = ?");
    $stmt_insert = $conn->prepare("INSERT INTO hari_libur (tanggal, nama, jenis, id_cabang, perlu_verifikasi, created_by)
                                   VALUES (?, ?, ?, NULL, ?, ?)");
    $tersimpan = 0;
    foreach (array_keys($pilih) as $idx) {
        $tanggal = sanitizeInput($tanggal_arr[$idx] ?? '');
        $nama = sanitizeInput($nama_arr[$idx] ?? '');
        $jenis = in_array($jenis_arr[$idx] ?? '', ['Nasional', 'Cuti Bersama', 'Perusahaan'], true)
            ? $jenis_arr[$idx] : 'Nasional';
        $perlu_verifikasi = !empty($verifikasi_arr[$idx]) ? 1 : 0;

        $cek = DateTime::createFromFormat('Y-m-d', $tanggal);
        if (!$cek || $cek->format('Y-m-d') !== $tanggal || $nama === '') {
            continue; // baris rusak/dimanipulasi - lewati diam-diam, bukan gagal total
        }

        $stmt_cek->bind_param("s", $tanggal);
        $stmt_cek->execute();
        if ($stmt_cek->get_result()->num_rows > 0) {
            continue; // sudah ada (mis. submit ganda) - lewati, bukan duplikat
        }

        $stmt_insert->bind_param("sssii", $tanggal, $nama, $jenis, $perlu_verifikasi, $_SESSION['user_id']);
        if ($stmt_insert->execute() && $conn->affected_rows > 0) {
            $tersimpan++;
        }
    }
    $stmt_cek->close();
    $stmt_insert->close();

    if ($tersimpan > 0) {
        $_SESSION['success_message'] = "✅ {$tersimpan} hari libur berhasil di-generate untuk tahun {$tahun_redirect}. "
            . "Cocokkan yang bertanda \"Perlu Verifikasi\" dengan SKB 3 Menteri resmi.";
        logActivity($conn, 'generate_hari_libur',
            "Generate {$tersimpan} hari libur otomatis untuk tahun {$tahun_redirect}", $_SESSION['user_id']);
    } else {
        $_SESSION['error_message'] = "❌ Tidak ada hari libur baru yang ditambahkan (mungkin semua tanggal terpilih sudah terdaftar).";
    }

    header("Location: data_hari_libur.php?tahun={$tahun_redirect}");
    exit();
}

// Handler Simpan Pengaturan Hari Kerja / Hari Lembur
if (isset($_POST['simpan_hari_kerja'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');

    $hari_kerja    = isset($_POST['hari_kerja']) && is_array($_POST['hari_kerja']) ? $_POST['hari_kerja'] : [];
    $hari_overtime = isset($_POST['hari_overtime']) && is_array($_POST['hari_overtime']) ? $_POST['hari_overtime'] : [];

    // Bersihkan ke angka 1-7 saja
    $bersih = function ($arr) {
        $out = [];
        foreach ($arr as $v) {
            $n = intval($v);
            if ($n >= 1 && $n <= 7 && !in_array($n, $out, true)) $out[] = $n;
        }
        sort($out);
        return $out;
    };
    $hari_kerja    = $bersih($hari_kerja);
    $hari_overtime = $bersih($hari_overtime);

    if (empty($hari_kerja)) {
        $_SESSION['error_message'] = "❌ Minimal satu hari kerja harus dipilih.";
        header("Location: data_hari_libur.php");
        exit();
    }

    // Satu hari tidak boleh menjadi hari kerja sekaligus hari lembur
    $tabrakan = array_intersect($hari_kerja, $hari_overtime);
    if (!empty($tabrakan)) {
        $nama_tabrakan = array_map(function ($n) { return KALENDER_NAMA_HARI[$n]; }, $tabrakan);
        $_SESSION['error_message'] = "❌ " . implode(', ', $nama_tabrakan)
            . " tidak boleh dicentang sebagai hari kerja dan hari lembur sekaligus.";
        header("Location: data_hari_libur.php");
        exit();
    }

    $ok1 = setPengaturan($conn, 'hari_kerja', implode(',', $hari_kerja),
        'Hari kerja normal perusahaan (1=Senin ... 7=Minggu)');
    $ok2 = setPengaturan($conn, 'hari_overtime', implode(',', $hari_overtime),
        'Hari yang dihitung sebagai hari lembur/overtime, bukan hari kerja normal');

    if ($ok1 && $ok2) {
        $_SESSION['success_message'] = "✅ Pengaturan hari kerja berhasil disimpan. "
            . "Perhitungan kuota cuti dan keterlambatan mengikuti pengaturan baru mulai sekarang.";
        logActivity($conn, 'ubah_hari_kerja',
            "Mengubah hari kerja menjadi [" . implode(',', $hari_kerja) . "], hari lembur [" . implode(',', $hari_overtime) . "]",
            $_SESSION['user_id']);
    } else {
        $_SESSION['error_message'] = "❌ Gagal menyimpan pengaturan hari kerja.";
    }

    header("Location: data_hari_libur.php");
    exit();
}

// Handler Tambah Supervisor
// Supervisor wajib tertaut ke karyawan aktif. Nama, gender, dan cabang
// selalu mengikuti master data karyawan agar cakupan akses tidak dapat dipilih bebas.
if (isset($_POST['tambah_supervisor'])) {
    $id_karyawan   = sanitizeInput($_POST['id_karyawan_supervisor'] ?? '');
    $username      = sanitizeInput($_POST['username_supervisor'] ?? '');
    $password      = $_POST['password_supervisor'] ?? '';

    $error_msg = null;
    $data_karyawan = null;

    if (empty($id_karyawan) || strlen($username) < 4 || preg_match('/\s/', $username) || strlen($password) < 8) {
        $error_msg = "Karyawan wajib dipilih, username min 4 karakter tanpa spasi, password min 8 karakter.";
    } else {
        $stmt_karyawan = $conn->prepare("SELECT k.nama_karyawan, k.jenis_kelamin, k.id_cabang
                                         FROM karyawan k
                                         LEFT JOIN users u ON u.id_karyawan = k.id_karyawan
                                         WHERE k.id_karyawan = ? AND k.status = 'aktif' AND u.id IS NULL");
        $stmt_karyawan->bind_param("s", $id_karyawan);
        $stmt_karyawan->execute();
        $data_karyawan = $stmt_karyawan->get_result()->fetch_assoc();
        if (!$data_karyawan || empty($data_karyawan['id_cabang'])) {
            $error_msg = "Karyawan tidak tersedia, sudah ditautkan ke akun lain, atau belum memiliki cabang.";
        }
        $stmt_karyawan->close();

        if (!$error_msg) {
            $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt_check->bind_param("s", $username);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $error_msg = "Username sudah digunakan.";
            }
            $stmt_check->close();
        }
    }

    if ($error_msg) {
        $_SESSION['error_message'] = $error_msg;
    } else {
        $nama = $data_karyawan['nama_karyawan'];
        $jenis_kelamin = in_array($data_karyawan['jenis_kelamin'], ['L', 'P'], true) ? $data_karyawan['jenis_kelamin'] : 'L';
        $id_cabang = (int)$data_karyawan['id_cabang'];
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $role = 'supervisor';

        $stmt = $conn->prepare("INSERT INTO users (nama, username, password, role, jenis_kelamin, id_karyawan, id_cabang)
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssi", $nama, $username, $hashed_password, $role, $jenis_kelamin, $id_karyawan, $id_cabang);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Akun Supervisor berhasil dibuat.";
            logActivity($conn, 'create_user', "Buat akun supervisor: $username ($id_karyawan, cabang #$id_cabang)", $id_karyawan);
        } else {
            $_SESSION['error_message'] = "Gagal membuat akun supervisor.";
        }
        $stmt->close();
    }

    if (isset($_POST['is_ajax'])) {
        if (isset($_SESSION['error_message'])) {
            $msg = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
            echo json_encode(['status' => 'error', 'message' => $msg]);
            exit();
        } else if (isset($_SESSION['success_message'])) {
            $msg = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
            echo json_encode(['status' => 'success', 'message' => $msg]);
            exit();
        }
    }

    header("Location: setting_users.php");
    exit();
}

// Edit User: username, role, tautan karyawan, dan password opsional.
if (isset($_POST['edit_user'])) {
    verifyCSRFToken($_POST['csrf_token'] ?? '');

    $id_user = intval($_POST['id_user'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $new_role = trim($_POST['role'] ?? '');
    $new_is_active_raw = (string)($_POST['is_active'] ?? '');
    $new_is_active = $new_is_active_raw === '1' ? 1 : 0;
    $new_id_karyawan = trim($_POST['id_karyawan'] ?? '');
    $password = $_POST['password'] ?? '';
    $current_user_id = $_SESSION['user_id'];

    $allowed_roles = ['admin', 'owner', 'supervisor', 'staff'];
    $error_msg = null;

    if ($id_user <= 0) {
        $error_msg = "User tidak valid.";
    } elseif (strlen($username) < 4 || strlen($username) > 30 || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
        $error_msg = "Username harus 4-30 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.";
    } elseif (!in_array($new_role, $allowed_roles, true)) {
        $error_msg = "Role user tidak valid.";
    } elseif (!in_array($new_is_active_raw, ['0', '1'], true)) {
        $error_msg = "Status akun tidak valid.";
    } elseif ($password !== '' && (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password))) {
        $error_msg = "Password baru minimal 8 karakter dan harus berisi huruf serta angka.";
    }

    if (!$error_msg) {
        try {
            $conn->begin_transaction();

            // Kunci user target agar pemeriksaan admin terakhir dan perubahan data atomik.
            $stmt_check = $conn->prepare("SELECT id, nama, username, role, is_active, jenis_kelamin, id_karyawan
                                          FROM users WHERE id = ? FOR UPDATE");
            $stmt_check->bind_param("i", $id_user);
            $stmt_check->execute();
            $user_data = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if (!$user_data) {
                throw new RuntimeException("User tidak ditemukan.");
            }

            if ($username !== $user_data['username']) {
                throw new RuntimeException("Username bersifat read-only dan tidak dapat diubah dari form ini.");
            }

            if ($user_data['role'] === 'admin' && $id_user !== (int)$current_user_id && $password !== '') {
                throw new RuntimeException("Anda tidak dapat mengubah password admin lain. Turunkan rolenya terlebih dahulu.");
            }

            $old_id_karyawan = $user_data['id_karyawan'] ?? '';
            if ($id_user === (int)$current_user_id
                && ($new_role !== $user_data['role']
                    || $new_id_karyawan !== $old_id_karyawan
                    || $new_is_active !== (int)$user_data['is_active'])) {
                throw new RuntimeException("Anda tidak dapat mengubah role, status, atau tautan karyawan akun sendiri.");
            }

            if ($user_data['role'] === 'admin' && (int)$user_data['is_active'] === 1
                && ($new_role !== 'admin' || $new_is_active !== 1)) {
                $result_admins = $conn->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE");
                if ($result_admins->num_rows <= 1) {
                    throw new RuntimeException("Admin aktif terakhir tidak dapat diturunkan rolenya atau dinonaktifkan.");
                }
            }

            $stmt_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $stmt_username->bind_param("si", $username, $id_user);
            $stmt_username->execute();
            if ($stmt_username->get_result()->num_rows > 0) {
                throw new RuntimeException("Username sudah digunakan oleh user lain.");
            }
            $stmt_username->close();

            $new_nama = $user_data['nama'];
            $new_jenis_kelamin = $user_data['jenis_kelamin'];
            $new_id_cabang = null;
            $id_karyawan_db = null;

            if ($new_id_karyawan !== '') {
                // Mengunci baris karyawan mencegah dua edit bersamaan menautkan
                // karyawan yang sama ke dua akun berbeda.
                $stmt_karyawan = $conn->prepare("SELECT nama_karyawan, jenis_kelamin, id_cabang
                                                 FROM karyawan
                                                 WHERE id_karyawan = ? AND status = 'aktif'
                                                 FOR UPDATE");
                $stmt_karyawan->bind_param("s", $new_id_karyawan);
                $stmt_karyawan->execute();
                $data_karyawan = $stmt_karyawan->get_result()->fetch_assoc();
                $stmt_karyawan->close();

                if (!$data_karyawan) {
                    throw new RuntimeException("Karyawan tidak ditemukan atau sudah nonaktif.");
                }

                $stmt_link = $conn->prepare("SELECT username FROM users WHERE id_karyawan = ? AND id <> ? LIMIT 1");
                $stmt_link->bind_param("si", $new_id_karyawan, $id_user);
                $stmt_link->execute();
                $linked_user = $stmt_link->get_result()->fetch_assoc();
                $stmt_link->close();
                if ($linked_user) {
                    throw new RuntimeException("Karyawan tersebut sudah ditautkan ke user '{$linked_user['username']}'.");
                }

                if ($new_role === 'supervisor' && empty($data_karyawan['id_cabang'])) {
                    throw new RuntimeException("Supervisor wajib ditautkan ke karyawan yang memiliki cabang.");
                }

                $id_karyawan_db = $new_id_karyawan;
                $new_nama = $data_karyawan['nama_karyawan'];
                $new_jenis_kelamin = in_array($data_karyawan['jenis_kelamin'], ['L', 'P'], true)
                    ? $data_karyawan['jenis_kelamin']
                    : 'L';
                $new_id_cabang = $new_role === 'supervisor' ? (int)$data_karyawan['id_cabang'] : null;
            } elseif ($new_role === 'staff' || $new_role === 'supervisor') {
                throw new RuntimeException("Role Staff dan Supervisor wajib ditautkan ke satu karyawan aktif.");
            }

            if ($password !== '') {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt_update = $conn->prepare("UPDATE users
                                              SET nama = ?, username = ?, role = ?, jenis_kelamin = ?,
                                                  is_active = ?, id_karyawan = ?, id_cabang = ?, password = ?
                                              WHERE id = ?");
                $stmt_update->bind_param("ssssisisi", $new_nama, $username, $new_role, $new_jenis_kelamin,
                    $new_is_active, $id_karyawan_db, $new_id_cabang, $hashed_password, $id_user);
            } else {
                $stmt_update = $conn->prepare("UPDATE users
                                              SET nama = ?, username = ?, role = ?, jenis_kelamin = ?,
                                                  is_active = ?, id_karyawan = ?, id_cabang = ?
                                              WHERE id = ?");
                $stmt_update->bind_param("ssssisii", $new_nama, $username, $new_role, $new_jenis_kelamin,
                    $new_is_active, $id_karyawan_db, $new_id_cabang, $id_user);
            }
            $stmt_update->execute();
            $stmt_update->close();

            $old_link_label = $old_id_karyawan !== '' ? $old_id_karyawan : 'NULL';
            $new_link_label = $new_id_karyawan !== '' ? $new_id_karyawan : 'NULL';
            logActivity($conn, 'edit_user',
                "Edit user #{$id_user} {$user_data['username']} -> {$username}; role {$user_data['role']} -> {$new_role}; status "
                . ((int)$user_data['is_active'] === 1 ? 'aktif' : 'nonaktif') . ' -> ' . ($new_is_active === 1 ? 'aktif' : 'nonaktif')
                . "; karyawan {$old_link_label} -> {$new_link_label}; password " . ($password !== '' ? 'diubah' : 'tetap'),
                $_SESSION['user_id']);

            $conn->commit();
            $_SESSION['success_message'] = "User '{$username}' berhasil diperbarui.";
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error_msg = "Gagal memperbarui user karena terjadi kesalahan database.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error_msg = $e->getMessage();
        }
    }

    if ($error_msg) {
        $_SESSION['error_message'] = $error_msg;
    }

    if (isset($_POST['is_ajax']) && isset($_SESSION['error_message'])) {
        $msg = $_SESSION['error_message'];
        unset($_SESSION['error_message']);
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit();
    }
    if (isset($_POST['is_ajax']) && isset($_SESSION['success_message'])) {
        $msg = $_SESSION['success_message'];
        unset($_SESSION['success_message']);
        echo json_encode(['status' => 'success', 'message' => $msg]);
        exit();
    }
    header("Location: setting_users.php");
    exit();
}

// Hapus User
if (isset($_GET['hapus_user'])) {
    $id_user = intval($_GET['hapus_user']);
    $current_user_id = $_SESSION['user_id'];
    
    $stmt_check = $conn->prepare("SELECT role, username FROM users WHERE id = ?");
    $stmt_check->bind_param("i", $id_user);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($res_check->num_rows == 0) {
        $_SESSION['error_message'] = "User tidak ditemukan.";
    } else {
        $user_to_delete = $res_check->fetch_assoc();
        
        // Cek apakah ini admin terakhir
        if ($user_to_delete['role'] == 'admin') {
            $sql_admin_count = "SELECT COUNT(*) as total_admin FROM users WHERE role = 'admin'";
            $res_admin_count = $conn->query($sql_admin_count);
            $admin_count = $res_admin_count->fetch_assoc()['total_admin'];
            
            if ($admin_count <= 1) {
                if (isset($_GET['is_ajax'])) {
                    echo json_encode(['status' => 'error', 'message' => "Tidak dapat menghapus admin terakhir."]);
                    exit();
                }
                $_SESSION['error_message'] = "Tidak dapat menghapus admin terakhir.";
                header("Location: setting_users.php");
                exit();
            }
            
            // Admin tidak bisa hapus admin lain (kecuali diri sendiri)
            if ($id_user != $current_user_id) {
                if (isset($_GET['is_ajax'])) {
                    echo json_encode(['status' => 'error', 'message' => "Anda tidak dapat menghapus akun admin lain."]);
                    exit();
                }
                $_SESSION['error_message'] = "Anda tidak dapat menghapus akun admin lain.";
                header("Location: setting_users.php");
                exit();
            }
        }
        
        // Proses hapus
        $stmt_delete = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt_delete->bind_param("i", $id_user);
        
        if ($stmt_delete->execute()) {
            // Jika menghapus akun sendiri, logout
            if ($id_user == $current_user_id) {
                session_destroy();
                if (isset($_GET['is_ajax'])) {
                    echo json_encode(['status' => 'success', 'redirect' => 'login.php']);
                    exit();
                }
                header("Location: login.php");
                exit();
            }
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'success', 'message' => "User '{$user_to_delete['username']}' berhasil dihapus!"]);
                exit();
            }
            $_SESSION['success_message'] = "User '{$user_to_delete['username']}' berhasil dihapus!";
        } else {
            if (isset($_GET['is_ajax'])) {
                echo json_encode(['status' => 'error', 'message' => "Gagal menghapus user."]);
                exit();
            }
            $_SESSION['error_message'] = "Gagal menghapus user.";
        }
    }
    
    if (isset($_GET['is_ajax'])) {
        if (isset($_SESSION['error_message'])) {
            $msg = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
            echo json_encode(['status' => 'error', 'message' => $msg]);
            exit();
        }
    }
    
    header("Location: setting_users.php");
    exit();
}
?>

