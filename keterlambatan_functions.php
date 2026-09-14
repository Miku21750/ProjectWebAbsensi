<?php
/**
 * ==========================================
 * Helper Potongan Keterlambatan Bertingkat
 * ==========================================
 * Di-include otomatis lewat config.php.
 *
 * Kebijakan (system_settings, lihat migrations/007_keterlambatan_bertingkat.php):
 * - Dispensasi (grace) N menit setelah jam_masuk_akhir shift - dalam masa
 *   ini status masih 'Tepat Waktu', tidak ada potongan.
 * - Begitu lewat dispensasi: potongan flat (tier 1) untuk durasi tertentu.
 * - Setelah tier 1: potongan tambahan setiap interval menit tertentu (tier 2).
 * - Dibekukan (tidak naik lagi) setelah keterlambatan mencapai batas jam
 *   tertentu - dihitung seolah keterlambatannya persis di batas itu.
 *
 * Pengaturan bisa dioverride per cabang lewat key system_settings dengan
 * format "c{id}_keterlambatan_*". Key global lama tetap dipakai sebagai
 * fallback agar instalasi lama dan cabang yang belum diatur tetap berjalan.
 *
 * absensi.menit_terlambat menyimpan FAKTA MENTAH (berapa menit telat hari
 * itu), bukan nominal potongannya - supaya kalau tarif berubah di kemudian
 * hari, riwayat lama tetap bisa dihitung ulang dengan tarif yang berlaku
 * SAAT slip gaji dibuat (persis seperti rate_keterlambatan per-karyawan
 * yang sudah ada, bukan tarif yang dibekukan di tanggal absen).
 */

function getDefaultPengaturanKeterlambatan() {
    return [
        'grace_menit' => 10,
        'tier1_durasi_menit' => 10,
        'tier1_rate' => 15000,
        'tier2_interval_menit' => 5,
        'tier2_rate' => 10000,
        'maks_jam' => 3,
    ];
}

function getKunciPengaturanKeterlambatan() {
    return [
        'grace_menit' => 'keterlambatan_grace_menit',
        'tier1_durasi_menit' => 'keterlambatan_tier1_durasi_menit',
        'tier1_rate' => 'keterlambatan_tier1_rate',
        'tier2_interval_menit' => 'keterlambatan_tier2_interval_menit',
        'tier2_rate' => 'keterlambatan_tier2_rate',
        'maks_jam' => 'keterlambatan_maks_jam',
    ];
}

function getKunciPengaturanKeterlambatanCabang($idCabang, $kunciGlobal) {
    // system_settings.setting_key dibatasi 50 karakter pada schema lama.
    return 'c' . (int)$idCabang . '_' . $kunciGlobal;
}

function getPengaturanKeterlambatan($conn, $idCabang = null) {
    $defaults = getDefaultPengaturanKeterlambatan();
    $keys = getKunciPengaturanKeterlambatan();
    $hasil = [];

    foreach ($keys as $nama => $keyGlobal) {
        $nilaiGlobal = getPengaturan($conn, $keyGlobal, (string)$defaults[$nama]);
        $nilai = $nilaiGlobal;

        if ($idCabang !== null && (int)$idCabang > 0) {
            $keyCabang = getKunciPengaturanKeterlambatanCabang($idCabang, $keyGlobal);
            $nilai = getPengaturan($conn, $keyCabang, $nilaiGlobal);
        }

        $hasil[$nama] = in_array($nama, ['tier1_rate', 'tier2_rate', 'maks_jam'], true)
            ? (float)$nilai
            : (int)$nilai;
    }

    return $hasil;
}

/**
 * Apakah sejumlah menit keterlambatan ini sudah melewati masa dispensasi
 * (sehingga harus berstatus 'Terlambat')?
 */
function apakahTerlambat($menitTerlambat, array $pengaturan = null, $conn = null) {
    $p = $pengaturan ?? getPengaturanKeterlambatan($conn);
    return $menitTerlambat > $p['grace_menit'];
}

/**
 * Hitung potongan (Rupiah) untuk sejumlah menit keterlambatan, mengikuti
 * kebijakan bertingkat. $menitTerlambat <= grace -> 0. Dibekukan begitu
 * mencapai maks_jam (tidak dihitung lebih jauh dari itu).
 */
function hitungPotonganKeterlambatan($menitTerlambat, array $pengaturan = null, $conn = null) {
    $p = $pengaturan ?? getPengaturanKeterlambatan($conn);

    if ($menitTerlambat <= $p['grace_menit']) {
        return 0.0;
    }

    // Bekukan di batas maksimal - keterlambatan yang lebih parah dari ini
    // dihitung seolah persis di batasnya, tidak terus bertambah.
    $batasMenit = $p['maks_jam'] * 60;
    $menitEfektif = min($menitTerlambat, $batasMenit);

    $menitSetelahGrace = $menitEfektif - $p['grace_menit'];

    if ($menitSetelahGrace <= $p['tier1_durasi_menit']) {
        return $p['tier1_rate'];
    }

    $menitSetelahTier1 = $menitSetelahGrace - $p['tier1_durasi_menit'];
    $jumlahInterval = (int)ceil($menitSetelahTier1 / $p['tier2_interval_menit']);

    return $p['tier1_rate'] + ($jumlahInterval * $p['tier2_rate']);
}

/**
 * Total potongan keterlambatan satu periode (bulan/tahun) untuk satu
 * karyawan, dijumlahkan PER HARI dari menit_terlambat mentah - bukan lagi
 * rate flat x jumlah hari seperti model lama.
 *
 * Baris absensi lama (sebelum migrasi 007) tidak punya menit_terlambat
 * (NULL) walau status_masuk sudah 'Terlambat' - untuk hari macam ini kita
 * tidak bisa menghitung tarif bertingkat (tidak ada fakta mentahnya lagi),
 * jadi jatuh ke $rateFallbackLegacy (rate flat per-karyawan lama) sebagai
 * perkiraan terbaik yang tersedia, dan ditandai 'legacy_flat' di rincian
 * supaya admin tahu hari mana yang dihitung dengan cara lama.
 */
function hitungTotalPotonganKeterlambatanPeriode($conn, $id_karyawan, $bulan, $tahun, $rateFallbackLegacy = 0) {
    $sql = "SELECT a.tanggal, a.menit_terlambat, k.id_cabang
            FROM absensi a
            JOIN karyawan k ON k.id_karyawan = a.id_karyawan
            WHERE a.id_karyawan = ? AND a.status_masuk = 'Terlambat'
              AND a.keterangan IN ('Hadir', 'Dinas Luar')
              AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?
            ORDER BY a.tanggal ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sii', $id_karyawan, $bulan, $tahun);
    $stmt->execute();
    $result = $stmt->get_result();

    $pengaturan = null;
    $total = 0.0;
    $rincian = [];
    $jumlah_legacy = 0;

    while ($row = $result->fetch_assoc()) {
        if ($pengaturan === null) {
            $pengaturan = getPengaturanKeterlambatan($conn, (int)$row['id_cabang']);
        }
        if ($row['menit_terlambat'] !== null) {
            $potongan = hitungPotonganKeterlambatan((int)$row['menit_terlambat'], $pengaturan);
            $sumber = 'tiered';
        } else {
            $potongan = (float)$rateFallbackLegacy;
            $sumber = 'legacy_flat';
            $jumlah_legacy++;
        }
        $total += $potongan;
        $rincian[] = [
            'tanggal' => $row['tanggal'],
            'menit_terlambat' => $row['menit_terlambat'] !== null ? (int)$row['menit_terlambat'] : null,
            'potongan' => $potongan,
            'sumber' => $sumber,
        ];
    }
    $stmt->close();

    return [
        'total' => $total,
        'jumlah_hari' => count($rincian),
        'jumlah_legacy' => $jumlah_legacy,
        'rincian' => $rincian,
    ];
}
