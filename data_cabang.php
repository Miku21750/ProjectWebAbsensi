<?php
require 'config.php';
require 'admin_header.php';

// Ambil semua data cabang dari database
$sql = "SELECT * FROM cabang ORDER BY nama_cabang ASC";
$result = $conn->query($sql);
?>

<!-- Top Action Bar -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
    <div>
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white">Data Cabang</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Kelola data lokasi dan alamat cabang perusahaan.</p>
    </div>
    
    <div class="flex items-center gap-3 w-full sm:w-auto">
        <button onclick="openModal('modal-tambah')" class="flex items-center justify-center gap-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl transition-colors font-medium text-sm shadow-sm shadow-brand-500/30 w-full sm:w-auto whitespace-nowrap">
            <i class="fa-solid fa-plus"></i> Tambah Baru
        </button>
    </div>
</div>

<?php include 'alert_messages.php'; ?>

<!-- Table Card Container -->
<div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden flex flex-col mb-8">
    
    <!-- Table Toolbar (Search & Entries) -->
    <div class="p-5 border-b border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row justify-between gap-4">
        <!-- Search Area -->
        <div class="relative w-full sm:w-96">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fa-solid fa-magnifying-glass text-slate-400"></i>
            </div>
            <input type="text" id="searchInput" onkeyup="filterTable()" class="block w-full pl-10 pr-3 py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl leading-5 bg-slate-50 dark:bg-slate-900/50 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 sm:text-sm transition-colors" placeholder="Cari nama atau alamat cabang...">
        </div>

        <!-- Pilihan Jumlah Tampilan -->
        <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 self-start sm:self-center">
            <span>Tampilkan</span>
            <select id="entriesSelect" onchange="changeEntries()" class="border border-slate-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-800 px-3 py-2 outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                <option value="5" selected>5</option>
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="30">30</option>
                <option value="all">Semua</option>
            </select>
            <span>data</span>
        </div>
    </div>

    <!-- Table Wrapper -->
    <div class="overflow-x-auto w-full">
        <table class="w-full text-left border-collapse" id="cabangTable">
            <thead class="sticky top-0 z-10">
                <tr class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider border-b border-slate-200 dark:border-slate-700 shadow-sm">
                    <th class="px-6 py-4 font-semibold w-24 text-center">No</th>
                    <th class="px-6 py-4 font-semibold">Nama Cabang</th>
                    <th class="px-6 py-4 font-semibold">Alamat</th>
                    <th class="px-6 py-4 font-semibold text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                <?php if ($result->num_rows > 0): ?>
                    <?php $no = 1; while($row = $result->fetch_assoc()): ?>
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/50 transition-colors group">
                            <td class="px-6 py-4 whitespace-nowrap text-center font-medium text-slate-500 dark:text-slate-400">
                                <?php echo $no++; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <p class="font-semibold text-slate-800 dark:text-white text-sm search-target"><?php echo htmlspecialchars($row['nama_cabang']); ?></p>
                                <?php if(!empty($row['latitude']) && !empty($row['longitude'])): ?>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                        <i class="fa-solid fa-location-dot text-brand-500 mr-1"></i> Radius: <?php echo $row['radius_meter'] ?? 100; ?>m
                                    </p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300 search-target">
                                <?php echo nl2br(htmlspecialchars($row['alamat_cabang'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-2 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
                                    <a href="admin_detail_cabang.php?id=<?php echo $row['id']; ?>" class="p-2 text-amber-600 hover:bg-amber-50 rounded-lg dark:text-amber-400 dark:hover:bg-amber-900/30 transition-colors" title="Detail dan Aturan Keterlambatan">
                                        <i class="fa-solid fa-sliders"></i>
                                    </a>
                                    <?php if(!empty($row['latitude']) && !empty($row['longitude'])): ?>
                                        <a href="https://www.google.com/maps?q=<?php echo $row['latitude']; ?>,<?php echo $row['longitude']; ?>" target="_blank" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg dark:text-emerald-400 dark:hover:bg-emerald-900/30 transition-colors" title="Lihat di Google Maps">
                                            <i class="fa-solid fa-map-marked-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="lihat_karyawan_cabang.php?id=<?php echo $row['id']; ?>" class="p-2 text-fuchsia-600 hover:bg-fuchsia-50 rounded-lg dark:text-fuchsia-400 dark:hover:bg-fuchsia-900/30 transition-colors" title="Lihat Karyawan">
                                        <i class="fa-solid fa-users"></i>
                                    </a>
                                    <button onclick="openEditCabangModal('<?php echo $row['id']; ?>', '<?php echo htmlspecialchars(addslashes($row['nama_cabang'])); ?>', '<?php echo htmlspecialchars(addslashes(str_replace(["\r", "\n"], ["", "\\n"], $row['alamat_cabang']))); ?>', '<?php echo $row['latitude'] ?? ''; ?>', '<?php echo $row['longitude'] ?? ''; ?>', '<?php echo $row['radius_meter'] ?? 100; ?>')" class="p-2 text-brand-600 hover:bg-brand-50 rounded-lg dark:text-brand-400 dark:hover:bg-brand-900/30 transition-colors" title="Edit Data">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <a href="master_process.php?hapus_cabang=<?php echo $row['id']; ?>" onclick="event.preventDefault(); handleDeleteAction(this.href, 'Hapus Cabang?', 'Apakah Anda yakin ingin menghapus cabang <?php echo addslashes($row['nama_cabang']); ?>?');" class="p-2 text-red-600 hover:bg-red-50 rounded-lg dark:text-red-400 dark:hover:bg-red-900/30 transition-colors" title="Hapus">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">
                            <i class="fa-solid fa-folder-open text-4xl mb-3 opacity-50"></i>
                            <p>Tidak ada data cabang.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <div class="p-5 border-t border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row items-center justify-between gap-4 bg-slate-50/50 dark:bg-slate-900/20">
        <span id="tableInfo" class="text-sm text-slate-500 dark:text-slate-400">Menampilkan 0 hingga 0 dari 0 data</span>
        <div id="paginationControls" class="flex gap-1">
            <!-- Buttons injected by JS -->
        </div>
    </div>
</div>

<!-- Modal Tambah Cabang -->
<div id="modal-tambah" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeModal('modal-tambah')"></div>
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-lg w-full border border-slate-200 dark:border-slate-700">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
                <h3 class="text-lg font-bold text-slate-800 dark:text-white">Tambah Cabang Baru</h3>
                <button onclick="closeModal('modal-tambah')" class="text-slate-400 hover:text-slate-500 dark:hover:text-slate-300">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <form action="master_process.php" method="POST">
                <div class="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Nama Cabang <span class="text-red-500">*</span></label>
                        <input type="text" name="nama_cabang" placeholder="Contoh: Cabang Slawi" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alamat Lengkap <span class="text-red-500">*</span></label>
                        <textarea name="alamat_cabang" rows="3" placeholder="Masukkan alamat lengkap cabang..." required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Latitude</label>
                            <input type="text" name="latitude" placeholder="-6.905977" step="any" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Longitude</label>
                            <input type="text" name="longitude" placeholder="109.634132" step="any" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Radius Area (meter)</label>
                        <input type="number" name="radius_meter" value="100" min="10" max="1000" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                        <p class="text-xs text-slate-500 mt-1"><i class="fa-solid fa-circle-info mr-1"></i> Karyawan hanya bisa absen dalam radius ini dari kantor</p>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modal-tambah')" class="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-sm font-medium">Batal</button>
                    <button type="submit" name="tambah_cabang" class="px-6 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl shadow-sm shadow-brand-500/30 transition-colors text-sm font-medium">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Cabang -->
<div id="modal-edit" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeModal('modal-edit')"></div>
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-lg w-full border border-slate-200 dark:border-slate-700">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
                <h3 class="text-lg font-bold text-slate-800 dark:text-white">Edit Data Cabang</h3>
                <button onclick="closeModal('modal-edit')" class="text-slate-400 hover:text-slate-500 dark:hover:text-slate-300">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <form action="master_process.php" method="POST" id="form-edit">
                <input type="hidden" id="edit-id-cabang" name="id_cabang">
                <div class="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Nama Cabang <span class="text-red-500">*</span></label>
                        <input type="text" id="edit-nama-cabang" name="nama_cabang" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Alamat Lengkap <span class="text-red-500">*</span></label>
                        <textarea id="edit-alamat-cabang" name="alamat_cabang" rows="3" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Latitude</label>
                            <input type="text" id="edit-latitude" name="latitude" step="any" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Longitude</label>
                            <input type="text" id="edit-longitude" name="longitude" step="any" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Radius Area (meter)</label>
                        <input type="number" id="edit-radius-meter" name="radius_meter" min="10" max="1000" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-xl text-slate-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition-colors">
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modal-edit')" class="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-sm font-medium">Batal</button>
                    <button type="submit" name="edit_cabang" class="px-6 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl shadow-sm shadow-brand-500/30 transition-colors text-sm font-medium">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditCabangModal(id, nama, alamat, latitude, longitude, radius) {
    document.getElementById('form-edit').reset();
    document.getElementById('edit-id-cabang').value = id;
    document.getElementById('edit-nama-cabang').value = nama;
    // Handle newlines back correctly if needed, though PHP's \n string replacement should handle it
    document.getElementById('edit-alamat-cabang').value = alamat.replace(/\\n/g, '\n');
    document.getElementById('edit-latitude').value = latitude || '';
    document.getElementById('edit-longitude').value = longitude || '';
    document.getElementById('edit-radius-meter').value = radius || 100;
    
    document.getElementById('modal-edit').classList.remove('hidden');
}

let currentPage = 1;
let entriesPerPage = 5;

document.addEventListener('DOMContentLoaded', () => {
    filterTable();
});

function changeEntries() {
    const val = document.getElementById('entriesSelect').value;
    entriesPerPage = val === 'all' ? Number.MAX_SAFE_INTEGER : parseInt(val);
    currentPage = 1;
    filterTable();
}

function goToPage(page) {
    currentPage = page;
    filterTable();
}

function filterTable() {
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    const table = document.getElementById('cabangTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    let filteredRows = [];

    // Filter baris
    rows.forEach(row => {
        // Skip baris "Tidak ada data"
        if (row.querySelector('td[colspan]')) return;

        let matchSearch = false;
        const targets = row.querySelectorAll('.search-target');
        
        if (targets.length > 0) {
            targets.forEach(target => {
                if (target.textContent.toLowerCase().includes(searchInput)) {
                    matchSearch = true;
                }
            });
        } else {
            // Fallback jika tidak ada class search-target
            matchSearch = row.textContent.toLowerCase().includes(searchInput);
        }

        if (matchSearch) {
            filteredRows.push(row);
        }
        row.style.display = 'none'; // Sembunyikan semua dulu
    });

    const totalEntries = filteredRows.length;
    const totalPages = Math.ceil(totalEntries / entriesPerPage);
    
    // Pastikan current page valid
    if (currentPage > totalPages && totalPages > 0) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    const startIndex = (currentPage - 1) * entriesPerPage;
    const endIndex = Math.min(startIndex + entriesPerPage, totalEntries);

    // Update Nomor Urut dan Tampilkan baris sesuai halaman
    let displayIndex = startIndex + 1;
    for (let i = startIndex; i < endIndex; i++) {
        filteredRows[i].style.display = '';
        // Update kolom pertama (No) jika bukan th
        const firstTd = filteredRows[i].querySelector('td:first-child');
        if (firstTd && !firstTd.hasAttribute('colspan')) {
            firstTd.innerHTML = displayIndex++;
        }
    }

    // Update Info
    const infoSpan = document.getElementById('tableInfo');
    if (totalEntries === 0) {
        infoSpan.textContent = 'Menampilkan 0 hingga 0 dari 0 data';
    } else {
        infoSpan.textContent = `Menampilkan ${startIndex + 1} hingga ${endIndex} dari ${totalEntries} data`;
    }

    // Update Pagination Buttons
    const paginationControls = document.getElementById('paginationControls');
    paginationControls.innerHTML = '';

    if (totalPages > 1) {
        // Prev button
        const prevBtn = document.createElement('button');
        prevBtn.className = `px-3 py-1 border border-slate-200 dark:border-slate-700 rounded-md bg-white dark:bg-slate-800 text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors ${currentPage === 1 ? 'opacity-50 cursor-not-allowed' : ''}`;
        prevBtn.textContent = 'Previous';
        prevBtn.disabled = currentPage === 1;
        prevBtn.onclick = () => goToPage(currentPage - 1);
        paginationControls.appendChild(prevBtn);

        // Page buttons (simplified: show all if few, or just surrounding pages if many)
        for (let i = 1; i <= totalPages; i++) {
            // Batasi tombol yang tampil agar tidak kepanjangan
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

        // Next button
        const nextBtn = document.createElement('button');
        nextBtn.className = `px-3 py-1 border border-slate-200 dark:border-slate-700 rounded-md bg-white dark:bg-slate-800 text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors ${currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : ''}`;
        nextBtn.textContent = 'Next';
        nextBtn.disabled = currentPage === totalPages;
        nextBtn.onclick = () => goToPage(currentPage + 1);
        paginationControls.appendChild(nextBtn);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const editForm = document.getElementById('form-edit');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = this;
            const formData = new FormData(form);
            formData.append('is_ajax', '1');
            formData.append('edit_cabang', '1'); 
            
            const confirmAndSubmit = () => {
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
                
                fetch(form.action, {
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
                            closeModal('modal-edit');
                            fetch(window.location.href)
                                .then(res => res.text())
                                .then(html => {
                                    const parser = new DOMParser();
                                    const doc = parser.parseFromString(html, 'text/html');
                                    document.querySelector('#cabangTable tbody').innerHTML = doc.querySelector('#cabangTable tbody').innerHTML;
                                    filterTable(); // Re-apply pagination state
                                });
                        });
                    } else {
                        alert(data.message);
                        closeModal('modal-edit');
                        fetch(window.location.href)
                                .then(res => res.text())
                                .then(html => {
                                    const parser = new DOMParser();
                                    const doc = parser.parseFromString(html, 'text/html');
                                    document.querySelector('#cabangTable tbody').innerHTML = doc.querySelector('#cabangTable tbody').innerHTML;
                                    filterTable(); // Re-apply pagination state
                                });
                    }
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
            };

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Konfirmasi Edit',
                    text: 'Apakah Anda yakin ingin menyimpan perubahan ini?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#c026d3',
                    cancelButtonColor: '#ef4444',
                    confirmButtonText: '<i class="fa-solid fa-check mr-2"></i>Ya, Simpan',
                    cancelButtonText: '<i class="fa-solid fa-xmark mr-2"></i>Batal',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        confirmAndSubmit();
                    }
                });
            } else {
                if (confirm('Apakah Anda yakin ingin menyimpan perubahan ini?')) {
                    confirmAndSubmit();
                }
            }
        });
    }
});
</script>
<?php include 'admin_footer.php'; ?>

