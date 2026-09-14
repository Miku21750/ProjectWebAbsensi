<?php
/**
 * ==========================================
 * HELPER SEED / BACKFILL ABSENSI
 * ==========================================
 * Dipakai bersama oleh seed_dummy_data.php (seed penuh: cabang, jam kerja,
 * karyawan, akun, DAN riwayat absensi) dan seed_backfill_absensi.php (HANYA
 * riwayat absensi, untuk karyawan/user yang SUDAH ada di database - dipakai
 * di database yang datanya sudah nyata/production, supaya dashboard &
 * laporan tidak kosong tanpa membuat karyawan/akun palsu).
 */

function ambilSettingSeed($conn, $key, $default) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['setting_value'] : $default;
}

// Aritmatika detik-sejak-tengah-malam murni (tanpa strtotime/date yang
// timezone-aware) - mencampur strtotime(...UTC) dengan date() lokal
// (Asia/Jakarta, UTC+7) akan menggeser jam 7 jam dan membungkus ke tengah malam.
function jamKeDetik($jam) {
    list($h, $m, $s) = array_map('intval', explode(':', $jam));
    return $h * 3600 + $m * 60 + $s;
}
function detikKeJam($detik) {
    $detik = (($detik % 86400) + 86400) % 86400;
    return sprintf('%02d:%02d:%02d', intdiv($detik, 3600), intdiv($detik % 3600, 60), $detik % 60);
}

function lokasiAcak($koordinat, $jakarta_fallback) {
    $base = $koordinat ?? $jakarta_fallback;
    // jitter kecil (~0-150m) supaya tidak semua titik identik tapi tetap dalam radius wajar
    $jitter_lat = (mt_rand(-150, 150) / 1000000) * 10; // ~0-0.0015 derajat
    $jitter_lng = (mt_rand(-150, 150) / 1000000) * 10;
    return round($base[0] + $jitter_lat, 8) . ',' . round($base[1] + $jitter_lng, 8);
}

/**
 * Backfill riwayat absensi untuk SEMUA karyawan status 'aktif' yang SUDAH
 * ada di database (tidak pernah membuat karyawan/user baru - itu urusan
 * seed_dummy_data.php, bukan fungsi ini). Melewati tanggal yang sudah punya
 * baris absensi apa pun sumbernya (kiosk asli, approval izin, atau backfill
 * sebelumnya), jadi tidak pernah menimpa/menduplikasi data asli.
 *
 * Dua aturan keamanan yang tidak bisa dimatikan lewat parameter:
 * - TIDAK PERNAH menyentuh hari ini. proses_absen.php menentukan mode
 *   masuk/pulang dari "apakah baris hari ini sudah ada", bukan dari
 *   jam_masuk-nya - backfill hari ini bisa membuat karyawan yang belum
 *   sempat absen dianggap sistem sudah "pulang", memblokir absen asli
 *   mereka. Rentang berhenti di kemarin.
 * - Baris yang dibuat SELALU ditandai is_manual_entry=1 + manual_entry_by
 *   (termasuk baris "Hadir", yang sebelumnya tidak ditandai sama sekali)
 *   supaya bisa dibedakan dari absensi kiosk sungguhan dan diaudit/
 *   dikecualikan secara manual di kemudian hari kalau perlu (mis. dari
 *   penghitungan slip gaji) - fungsi ini sendiri TIDAK mengecualikannya
 *   secara otomatis dari perhitungan mana pun.
 *
 * Selain baris absensi, fungsi ini membuat pengajuan_lembur Disetujui untuk
 * contoh lembur di hari kerja. Itu diperlukan karena lembur hari kerja baru
 * sah/dihitung oleh lembur_functions.php bila ada approval yang mencakup
 * tanggal absensinya. Metadata lain hanya diisi saat relevan: izin pulang
 * cepat untuk kepulangan dini, serta kolom konversi untuk kasus lupa absen
 * masuk yang sudah dijadikan izin setengah hari.
 *
 * @return array{absensi:int, absensi_gagal:int, pulang_cepat:int, izin_setengah_hari:int, lembur_disetujui:int}
 */
function backfillRiwayatAbsensi($conn, $confirmed, MigrationLog $log, string $sumberLabel, int $hariKeBelakang = 90, ?array $idKaryawanFilter = null) {
    $hasil = [
        'absensi' => 0,
        'absensi_gagal' => 0,
        'pulang_cepat' => 0,
        'izin_setengah_hari' => 0,
        'lembur_disetujui' => 0,
    ];

    $kolom_wajib = [
        'menit_terlambat', 'izin_pulang_cepat', 'alasan_pulang_cepat',
        'dikonversi_izin_setengah_hari', 'dikonversi_oleh', 'dikonversi_at',
    ];
    $kolom_hilang = array_values(array_filter($kolom_wajib, function ($kolom) use ($conn) {
        return !kolomAda($conn, 'absensi', $kolom);
    }));
    if (!empty($kolom_hilang) || !tabelAda($conn, 'pengajuan_lembur')) {
        $detail = !empty($kolom_hilang)
            ? ' Kolom absensi yang belum ada: <code>' . implode('</code>, <code>', $kolom_hilang) . '</code>.'
            : '';
        if (!tabelAda($conn, 'pengajuan_lembur')) {
            $detail .= ' Tabel <code>pengajuan_lembur</code> belum ada.';
        }
        $log->error('Schema belum lengkap untuk backfill versi terbaru.' . $detail . ' Jalankan <code>migrate.php</code> terlebih dahulu.');
        return $hasil;
    }

    $hari_kerja = array_map('intval', explode(',', ambilSettingSeed($conn, 'hari_kerja', '1,2,3,4,5')));
    $hari_overtime = array_map('intval', explode(',', ambilSettingSeed($conn, 'hari_overtime', '6')));

    // Berhenti di KEMARIN, bukan hari ini - lihat catatan keamanan di docblock.
    $tanggal_akhir = date('Y-m-d', strtotime('-1 day'));
    $tanggal_awal = date('Y-m-d', strtotime("-{$hariKeBelakang} days"));

    $hari_libur_map = [];
    $stmt_libur = $conn->prepare("SELECT tanggal FROM hari_libur WHERE tanggal BETWEEN ? AND ?");
    $stmt_libur->bind_param("ss", $tanggal_awal, $tanggal_akhir);
    $stmt_libur->execute();
    $res_libur = $stmt_libur->get_result();
    while ($row = $res_libur->fetch_assoc()) {
        $hari_libur_map[$row['tanggal']] = true;
    }
    $stmt_libur->close();

    // Filter opsional ke id_karyawan tertentu - kalau diisi, hanya karyawan
    // itu yang diproses (tetap harus status 'aktif'). ID yang tidak
    // ditemukan/tidak aktif dilaporkan, bukan dilewati diam-diam.
    $idKaryawanFilter = $idKaryawanFilter !== null
        ? array_values(array_unique(array_filter(array_map('trim', $idKaryawanFilter))))
        : null;

    $roster = [];
    $sql_roster = "SELECT k.id_karyawan, k.id_cabang, k.id_jabatan, COALESCE(j.overtime_sabtu, 0) AS overtime_sabtu
                   FROM karyawan k
                   LEFT JOIN jabatan j ON j.id = k.id_jabatan
                   WHERE k.status = 'aktif'";
    if ($idKaryawanFilter !== null && !empty($idKaryawanFilter)) {
        $placeholders = implode(',', array_fill(0, count($idKaryawanFilter), '?'));
        $sql_roster .= " AND k.id_karyawan IN ($placeholders)";
        $stmt_roster = $conn->prepare($sql_roster);
        $stmt_roster->bind_param(str_repeat('s', count($idKaryawanFilter)), ...$idKaryawanFilter);
        $stmt_roster->execute();
        $res_roster = $stmt_roster->get_result();
        while ($row = $res_roster->fetch_assoc()) {
            $roster[] = $row;
        }
        $stmt_roster->close();

        $ditemukan = array_column($roster, 'id_karyawan');
        $tidakDitemukan = array_diff($idKaryawanFilter, $ditemukan);
        if (!empty($tidakDitemukan)) {
            $log->warn("ID karyawan berikut dilewati (tidak ditemukan atau statusnya bukan 'aktif'): "
                . implode(', ', array_map('htmlspecialchars', $tidakDitemukan)));
        }
    } else {
        $res_roster = $conn->query($sql_roster);
        while ($row = $res_roster->fetch_assoc()) {
            $roster[] = $row;
        }
    }

    if (empty($roster)) {
        $log->error("Tidak ada karyawan aktif yang cocok untuk di-backfill - tidak ada yang dilakukan.");
        return $hasil;
    }

    $jam_kerja_cabang = [];
    $res_jk = $conn->query("SELECT id_cabang, jam_masuk_akhir, jam_pulang FROM jam_kerja");
    while ($row = $res_jk->fetch_assoc()) {
        if (!isset($jam_kerja_cabang[$row['id_cabang']])) {
            $jam_kerja_cabang[$row['id_cabang']] = ['masuk_akhir' => $row['jam_masuk_akhir'], 'pulang' => $row['jam_pulang']];
        }
    }

    $koordinat_cabang = [];
    $res_koor = $conn->query("SELECT id, latitude, longitude FROM cabang");
    while ($row = $res_koor->fetch_assoc()) {
        $koordinat_cabang[$row['id']] = ($row['latitude'] !== null && $row['longitude'] !== null)
            ? [(float)$row['latitude'], (float)$row['longitude']]
            : null;
    }
    $JAKARTA_FALLBACK = [-6.200000, 106.816666];

    // Kebijakan keterlambatan bertingkat SAAT INI (system_settings, lihat
    // keterlambatan_functions.php) - dipakai supaya menit_terlambat & status
    // yang di-generate di sini konsisten dengan proses_absen.php yang asli
    // dan ikut berubah kalau adminnya mengubah grace/tier di detail cabang,
    // bukan rentang menit yang di-hardcode lepas dari pengaturan.
    $pengaturan_telat_cabang = [];

    if (!$confirmed) {
        $hariKerjaCount = 0;
        $d = strtotime($tanggal_awal);
        $akhir_ts = strtotime($tanggal_akhir);
        while ($d <= $akhir_ts) {
            $wd = (int)date('N', $d);
            if (in_array($wd, $hari_kerja, true) && !isset($hari_libur_map[date('Y-m-d', $d)])) $hariKerjaCount++;
            $d = strtotime('+1 day', $d);
        }
        $log->warn("[Dry-run] Akan menghasilkan riwayat absensi ~{$hariKerjaCount} hari kerja x " . count($roster)
            . " karyawan aktif (tanggal {$tanggal_awal} s.d. {$tanggal_akhir}, tidak termasuk hari ini), "
            . "melewati tanggal yang sudah punya data. Variasi mencakup menit keterlambatan, izin pulang cepat "
            . "yang disetujui, konversi izin setengah hari, lembur mingguan, dan lembur hari kerja yang disetujui.");
        return $hasil;
    }

    // Ambil semua tanggal yang SUDAH ada di rentang ini, per karyawan, sekali saja (hindari query per hari)
    $existing = [];
    $stmt_exist = $conn->prepare("SELECT id_karyawan, tanggal FROM absensi WHERE tanggal BETWEEN ? AND ?");
    $stmt_exist->bind_param("ss", $tanggal_awal, $tanggal_akhir);
    $stmt_exist->execute();
    $res_exist = $stmt_exist->get_result();
    while ($row = $res_exist->fetch_assoc()) {
        $existing[$row['id_karyawan'] . '|' . $row['tanggal']] = true;
    }
    $stmt_exist->close();

    $stmt_hadir = $conn->prepare(
        "INSERT INTO absensi
            (id_karyawan, tanggal, jam_masuk, jam_pulang, lokasi_masuk, lokasi_pulang,
             keterangan, status_masuk, menit_terlambat, face_verified, face_confidence, input_method,
             is_manual_entry, manual_entry_by, alasan_pulang, izin_pulang_cepat, alasan_pulang_cepat)
         VALUES (?, ?, ?, ?, ?, ?, 'Hadir', ?, ?, 1, ?, 'qr_scan', 1, ?, ?, ?, ?)"
    );
    $stmt_khusus = $conn->prepare(
        "INSERT INTO absensi (id_karyawan, tanggal, keterangan, is_manual_entry, manual_entry_by)
         VALUES (?, ?, ?, 1, ?)"
    );
    $stmt_setengah_hari = $conn->prepare(
        "INSERT INTO absensi
            (id_karyawan, tanggal, jam_pulang, lokasi_pulang, keterangan, alasan, waktu_alasan,
             status_masuk, face_verified, face_confidence, input_method, is_manual_entry, manual_entry_by,
             dikonversi_izin_setengah_hari, dikonversi_oleh, dikonversi_at)
         VALUES (?, ?, ?, ?, 'Hadir', ?, ?, NULL, 1, ?, 'qr_scan', 1, ?, 1, ?, ?)"
    );
    $stmt_lembur = $conn->prepare(
        "INSERT INTO pengajuan_lembur
            (id_karyawan, tanggal_mulai, tanggal_selesai, keperluan, status, id_cabang,
             reviewed_by, reviewed_at, catatan_reviewer, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'Disetujui', ?, ?, ?, ?, ?, ?)"
    );
    $stmt_cek_lembur = $conn->prepare(
        "SELECT status FROM pengajuan_lembur
         WHERE id_karyawan = ? AND ? BETWEEN tanggal_mulai AND tanggal_selesai
           AND status IN ('Pending', 'Disetujui')
         ORDER BY FIELD(status, 'Disetujui', 'Pending') LIMIT 1"
    );

    // Reviewer boleh NULL bila database memang tidak punya akun approver.
    // Bila ada, pakai akun approver pertama agar histori approval dummy tetap
    // menyerupai data dari alur aplikasi yang sebenarnya.
    $reviewer_id = null;
    $res_reviewer = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'owner') AND is_active = 1 ORDER BY FIELD(role, 'admin', 'owner'), id LIMIT 1");
    if ($res_reviewer && ($row_reviewer = $res_reviewer->fetch_assoc())) {
        $reviewer_id = (int)$row_reviewer['id'];
    }

    $conn->begin_transaction();
    try {
        foreach ($roster as $r) {
            $id_karyawan = $r['id_karyawan'];
            $id_cabang_karyawan = (int)$r['id_cabang'];
            if (!isset($pengaturan_telat_cabang[$id_cabang_karyawan])) {
                $pengaturan_telat_cabang[$id_cabang_karyawan] = getPengaturanKeterlambatan($conn, $id_cabang_karyawan);
            }
            $pengaturan_telat = $pengaturan_telat_cabang[$id_cabang_karyawan];
            $shift = $jam_kerja_cabang[$r['id_cabang']] ?? ['masuk_akhir' => '08:00:00', 'pulang' => '17:00:00'];
            $koor = $koordinat_cabang[$r['id_cabang']] ?? null;

            $d = strtotime($tanggal_awal);
            $akhir_ts = strtotime($tanggal_akhir);
            while ($d <= $akhir_ts) {
                $tanggal = date('Y-m-d', $d);
                $key = $id_karyawan . '|' . $tanggal;
                $d = strtotime('+1 day', $d);

                if (isset($existing[$key])) continue; // jangan duplikasi data yang sudah ada
                if (isset($hari_libur_map[$tanggal])) continue; // hari libur: tidak ada baris

                $wd = (int)date('N', strtotime($tanggal));
                $roll = mt_rand(1, 100);

                if (in_array($wd, $hari_kerja, true)) {
                    // Hari kerja normal: distribusi status
                    if ($roll <= 75) {
                        // Hadir (sebagian Terlambat)
                        $masuk_akhir_detik = jamKeDetik($shift['masuk_akhir']);
                        $pulang_detik = jamKeDetik($shift['pulang']);

                        // menit_terlambat & status_masuk dibuat dengan alur yang SAMA
                        // dengan proses_absen.php: menit_terlambat = selisih mentah
                        // lewat jam_masuk_akhir shift (NULL kalau tidak lewat sama
                        // sekali), status baru 'Terlambat' setelah lewat masa
                        // dispensasi (grace) yang sedang aktif di system_settings.
                        $telat = mt_rand(1, 100) <= 18;
                        if ($telat) {
                            // Disebar dari 1 menit lewat batas sampai sedikit di atas
                            // batas pembekuan (maks_jam) kebijakan aktif, supaya tier1
                            // & tier2 potongan sama-sama terwakili di data dummy -
                            // bukan rentang tetap 1-60 menit yang lepas dari pengaturan.
                            $batas_atas_menit = max(60, (int)round($pengaturan_telat['maks_jam'] * 60) + 15);
                            $menit_terlambat = mt_rand(1, $batas_atas_menit);
                            $jam_masuk = detikKeJam($masuk_akhir_detik + ($menit_terlambat * 60));
                        } else {
                            // Sebagian besar datang lebih awal (menit_terlambat NULL,
                            // seperti hari yang tidak pernah lewat jam_masuk_akhir);
                            // sisanya datang sedikit lewat tapi masih dalam masa
                            // dispensasi - menit_terlambat tetap tercatat sebagai fakta
                            // mentah walau statusnya tetap Tepat Waktu.
                            if ($pengaturan_telat['grace_menit'] > 0 && mt_rand(1, 100) <= 30) {
                                $menit_terlambat = mt_rand(1, $pengaturan_telat['grace_menit']);
                                $jam_masuk = detikKeJam($masuk_akhir_detik + ($menit_terlambat * 60));
                            } else {
                                $menit_terlambat = null;
                                $jam_masuk = detikKeJam($masuk_akhir_detik - mt_rand(0, 1800)); // sampai 30 menit lebih awal
                            }
                        }
                        $status_masuk = apakahTerlambat($menit_terlambat ?? 0, $pengaturan_telat) ? 'Terlambat' : 'Tepat Waktu';
                        $izin_pulang_cepat = null;
                        $alasan_pulang_cepat = null;
                        $alasan_pulang = null;
                        $buat_lembur_disetujui = false;
                        $lembur_disetujui = false;

                        $variasi_pulang = mt_rand(1, 100);
                        if ($variasi_pulang <= 8) {
                            // Pulang setelah 3-5 jam kerja, selalu sebelum akhir
                            // shift. Status Disetujui + alasan harus berpasangan;
                            // jangan membuat status Pending historis yang tidak
                            // mungkin lagi ditindaklanjuti reviewer.
                            $pulang_cepat_detik = min(
                                $pulang_detik - 1800,
                                jamKeDetik($jam_masuk) + mt_rand(3 * 3600, 5 * 3600)
                            );
                            $jam_pulang = detikKeJam($pulang_cepat_detik);
                            $izin_pulang_cepat = 'Disetujui';
                            $alasan_pulang_cepat = 'Keperluan keluarga (data backfill)';
                            $alasan_pulang = $alasan_pulang_cepat;
                        } elseif ($variasi_pulang <= 20) {
                            // Lembur hari kerja wajib punya approval pendamping.
                            // Hormati pengajuan nyata yang mungkin sudah meliputi
                            // tanggal kosong ini: pakai approval Disetujui yang
                            // ada, atau jangan membuat lembur bila masih Pending.
                            $stmt_cek_lembur->bind_param("ss", $id_karyawan, $tanggal);
                            if (!$stmt_cek_lembur->execute()) {
                                throw new RuntimeException('Gagal memeriksa pengajuan lembur yang sudah ada: ' . $stmt_cek_lembur->error);
                            }
                            $res_cek_lembur = $stmt_cek_lembur->get_result();
                            $lembur_ada = $res_cek_lembur->fetch_assoc();
                            $res_cek_lembur->free();
                            if (!$lembur_ada || $lembur_ada['status'] === 'Disetujui') {
                                $jam_pulang = detikKeJam($pulang_detik + mt_rand(60, 240) * 60);
                                $lembur_disetujui = true;
                                $buat_lembur_disetujui = !$lembur_ada;
                            } else {
                                $jam_pulang = detikKeJam($pulang_detik + mt_rand(-600, 2400));
                            }
                        } else {
                            $jam_pulang = detikKeJam($pulang_detik + mt_rand(-600, 2400)); // -10 menit s.d. +40 menit
                        }

                        $lokasi_masuk = lokasiAcak($koor, $JAKARTA_FALLBACK);
                        $lokasi_pulang = lokasiAcak($koor, $JAKARTA_FALLBACK);
                        $confidence = round(mt_rand(6500, 9800) / 100, 2);

                        $stmt_hadir->bind_param(
                            "sssssssidssss",
                            $id_karyawan, $tanggal, $jam_masuk, $jam_pulang,
                            $lokasi_masuk, $lokasi_pulang, $status_masuk, $menit_terlambat, $confidence, $sumberLabel,
                            $alasan_pulang, $izin_pulang_cepat, $alasan_pulang_cepat
                        );
                        $ok = $stmt_hadir->execute();
                        if ($ok && $izin_pulang_cepat === 'Disetujui') {
                            $hasil['pulang_cepat']++;
                        }
                        if ($ok && $lembur_disetujui) {
                            if ($buat_lembur_disetujui) {
                                $keperluan_lembur = 'Penyelesaian pekerjaan operasional (data backfill)';
                                $catatan_reviewer = 'Disetujui otomatis oleh seed backfill untuk data demo.';
                                $diajukan_at = date('Y-m-d 16:00:00', strtotime($tanggal . ' -1 day'));
                                $reviewed_at = $tanggal . ' 07:00:00';
                                $stmt_lembur->bind_param(
                                    "ssssiissss",
                                    $id_karyawan, $tanggal, $tanggal, $keperluan_lembur,
                                    $id_cabang_karyawan, $reviewer_id, $reviewed_at, $catatan_reviewer,
                                    $diajukan_at, $reviewed_at
                                );
                                if (!$stmt_lembur->execute()) {
                                    throw new RuntimeException('Gagal membuat approval lembur pendamping: ' . $stmt_lembur->error);
                                }
                            }
                            $hasil['lembur_disetujui']++;
                        }
                    } else if ($roll <= 78) {
                        // Karyawan hanya absen pulang, lalu admin mengonversinya
                        // menjadi izin setengah hari (migrasi 009). jam_masuk dan
                        // status_masuk memang NULL supaya fakta lupa check-in tidak
                        // disamarkan menjadi Tepat Waktu.
                        $jam_pulang = detikKeJam(jamKeDetik($shift['pulang']) + mt_rand(-600, 600));
                        $lokasi_pulang = lokasiAcak($koor, $JAKARTA_FALLBACK);
                        $alasan_lupa_masuk = 'Lupa melakukan absen masuk (data backfill)';
                        $waktu_alasan = $tanggal . ' ' . $jam_pulang;
                        $confidence = round(mt_rand(6500, 9800) / 100, 2);
                        $dikonversi_at = $tanggal . ' 18:00:00';
                        $stmt_setengah_hari->bind_param(
                            "ssssssdsis",
                            $id_karyawan, $tanggal, $jam_pulang, $lokasi_pulang,
                            $alasan_lupa_masuk, $waktu_alasan, $confidence, $sumberLabel,
                            $reviewer_id, $dikonversi_at
                        );
                        $ok = $stmt_setengah_hari->execute();
                        if ($ok) {
                            $hasil['izin_setengah_hari']++;
                        }
                    } else if ($roll <= 84) {
                        $ket = 'Sakit';
                        $stmt_khusus->bind_param("ssss", $id_karyawan, $tanggal, $ket, $sumberLabel);
                        $ok = $stmt_khusus->execute();
                    } else if ($roll <= 89) {
                        $ket = 'Izin';
                        $stmt_khusus->bind_param("ssss", $id_karyawan, $tanggal, $ket, $sumberLabel);
                        $ok = $stmt_khusus->execute();
                    } else if ($roll <= 93) {
                        $ket = 'Cuti';
                        $stmt_khusus->bind_param("ssss", $id_karyawan, $tanggal, $ket, $sumberLabel);
                        $ok = $stmt_khusus->execute();
                    } else if ($roll <= 97) {
                        $ket = 'Alpha';
                        $stmt_khusus->bind_param("ssss", $id_karyawan, $tanggal, $ket, $sumberLabel);
                        $ok = $stmt_khusus->execute();
                    } else {
                        // sisa 3%: hari kerja tanpa baris sama sekali (belum sempat absen input apa pun)
                        continue;
                    }
                    if ($ok) { $hasil['absensi']++; } else { $hasil['absensi_gagal']++; }
                } else if (in_array($wd, $hari_overtime, true)) {
                    // Sabtu/hari overtime: hanya untuk jabatan yang eligible, dan tidak selalu masuk
                    if ((int)$r['overtime_sabtu'] === 1 && mt_rand(1, 100) <= 50) {
                        $masuk_detik = jamKeDetik('08:00:00') + mt_rand(0, 3600);
                        $durasi_detik = mt_rand(2 * 3600, 6 * 3600); // 2-6 jam lembur, durasi aktual
                        $jam_masuk = detikKeJam($masuk_detik);
                        $jam_pulang = detikKeJam($masuk_detik + $durasi_detik);
                        $status_masuk = 'Tepat Waktu'; // Sabtu tidak dihitung Terlambat
                        $menit_terlambat = null; // idem - hari overtime tidak punya jam masuk baku untuk dibandingkan
                        $alasan_pulang = null;
                        $izin_pulang_cepat = null;
                        $alasan_pulang_cepat = null;

                        $lokasi_masuk = lokasiAcak($koor, $JAKARTA_FALLBACK);
                        $lokasi_pulang = lokasiAcak($koor, $JAKARTA_FALLBACK);
                        $confidence = round(mt_rand(6500, 9800) / 100, 2);

                        $stmt_hadir->bind_param(
                            "sssssssidssss",
                            $id_karyawan, $tanggal, $jam_masuk, $jam_pulang,
                            $lokasi_masuk, $lokasi_pulang, $status_masuk, $menit_terlambat, $confidence, $sumberLabel,
                            $alasan_pulang, $izin_pulang_cepat, $alasan_pulang_cepat
                        );
                        if ($stmt_hadir->execute()) { $hasil['absensi']++; } else { $hasil['absensi_gagal']++; }
                    }
                }
                // Hari selain hari kerja & hari overtime (mis. Minggu): tidak ada baris.
            }
        }
        $conn->commit();
        $log->ok("Riwayat absensi berhasil dibuat: <b>{$hasil['absensi']}</b> baris baru untuk " . count($roster)
            . " karyawan aktif (tanggal {$tanggal_awal} s.d. {$tanggal_akhir}, tidak termasuk hari ini). "
            . "Rincian fitur baru: <b>{$hasil['pulang_cepat']}</b> pulang cepat disetujui, "
            . "<b>{$hasil['izin_setengah_hari']}</b> konversi izin setengah hari, dan "
            . "<b>{$hasil['lembur_disetujui']}</b> lembur hari kerja disetujui.");
        if ($hasil['absensi_gagal'] > 0) {
            $log->error("{$hasil['absensi_gagal']} baris absensi GAGAL diinsert (lihat error mysqli): " . $conn->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $hasil = array_fill_keys(array_keys($hasil), 0);
        $log->error("Gagal generate absensi, rollback: " . $e->getMessage());
    }
    $stmt_hadir->close();
    $stmt_khusus->close();
    $stmt_setengah_hari->close();
    $stmt_lembur->close();
    $stmt_cek_lembur->close();

    return $hasil;
}
