# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

"AbsenKita Javag" — an employee attendance (absensi) and payroll-slip (slip gaji) management system for "Java Abadi Gemilang", built as plain procedural PHP + MySQLi with no framework, no build step, and no dependency manager (no composer.json, no vendor/, no package.json). Every page is a standalone `.php` file served directly by Apache. UI language and most identifiers/strings are Indonesian.

There are four roles: `admin`, `owner`, `supervisor`, `staff`. Pages are prefixed by role (`admin_*.php`, `owner_*.php`, `supervisor_*.php`, `staff_*.php`); files without a role prefix (e.g. `absen.php`, `jam_kerja.php`, `data_karyawan.php`, `kelola_pengajuan_izin.php`) are shared/admin-oriented pages. `supervisor` is a narrow approval-only role scoped to one branch via `users.id_cabang`.

## Running the app

This is a classic LAMP-style app with no CLI build/test/lint tooling in the repo. Run it with a local PHP/MySQL stack (e.g. XAMPP/Laragon/`php -S`) pointed at this directory as the document root, with a MySQL database named `db_absensi.kry` (see `config.php`). There are no automated tests, linters, or build commands — verify changes by exercising the relevant page in a browser against a real database.

`check_db.php` dumps `SHOW TABLES` for the connected database (run directly via browser or CLI, not part of any tooling pipeline).

### Database migrations

Schema changes go through a small tracked migration system (`migrate.php` + `migrations/` + `migration_helpers.php`) — see **`MIGRATIONS.md`** for the full guide (how to write one, how to run one safely, production checklist). Quick summary:
- Each `migrations/NNN_description.php` file returns `['name' => ..., 'up' => function($conn, MigrationLog $log) { ... }]`; write it idempotent (check `kolomAda()`/`tabelAda()`/`enumMengandung()` from `migration_helpers.php` before `ALTER`/`CREATE`), matching the existing files.
- `migrate.php` applies pending migrations **in filename order** and records each one in `schema_migrations` so it never re-runs — no more guessing "did I already run this on this database?" It stops at the first failure without recording it (earlier successes in the same run stay recorded), since MySQL DDL auto-commits and can't be transactionally rolled back.
- Web access is `requireAdmin()`-gated and always shows a status/preview before doing anything — nothing changes on a GET request. CLI: `php migrate.php status` to preview, `php migrate.php migrate --yes` to apply.
- The older `update_db_*.php` one-off scripts this replaced are gone; their content now lives in `migrations/001`–`006`.

## Architecture

**Bootstrap (`config.php`)**: every page starts with `require 'config.php'` (or `require_once`). It starts output buffering, configures session cookies (stricter flags — `Secure`, `SameSite=Strict` — when detected as non-localhost HTTPS, looser `SameSite=Lax` on localhost/LAN), opens the mysqli `$conn`, sets `Asia/Jakarta` timezone, and defines the core auth/utility helpers used everywhere:
- `isLoggedIn()`, `isAdmin()`, `isOwner()`, `isStaff()`, `isSupervisor()`, `isApprover()` — session role checks (`isApprover()` = admin, owner, or supervisor).
- `requireLogin()`, `requireAdmin()`, `requireAdminOrOwner()`, `requireStaff()`, `requireSupervisor()`, `requireApprover()`, `dashboardUntukRole($role)` — guards that redirect on failure, plus the single source of truth for each role's landing page (though most `*_header.php` files re-check role directly against `$_SESSION['role']` rather than calling these).
- `sanitizeInput()` — trim/stripslashes/htmlspecialchars for POST/GET input.
- `generateCSRFToken()` / `verifyCSRFToken($token)` — session-based CSRF, `verifyCSRFToken` dies on mismatch.
- `regenerateSession()` — called on successful login.
- `redirect($url)`.

It also pulls in `izin_functions.php` (leave-request domain logic) and `kalender_functions.php` (calendar/workweek logic, both described below), plus `security_functions.php`, which adds:
- `validatePassword()`, `checkRateLimit($action, $limit_seconds)` (session-based throttling, used e.g. for the attendance endpoint), `safe_output()`, `validateIDKaryawan()` (11-digit numeric employee ID format `YYYYMMDDXXX`), `logActivity($conn, $action, $description, $user_id)` (writes to `activity_logs`).
- `calculateDistance()` / `validateLokasiAbsen()` — Haversine-based geofencing: validates a staff member's submitted GPS coords against their branch (`cabang`) coordinates + `radius_meter`, used to gate "Hadir" (present) check-ins to on-site locations. Backward-compatible: if a branch has no lat/long configured, location validation is bypassed.

**Page pattern**: each role dashboard/list page (`admin_*.php`, `owner_*.php`, `staff_*.php`) is a self-contained file combining PHP data-fetch, inline HTML, and often inline `<script>`. Shared chrome is via `admin_header.php`/`admin_footer.php`, `owner_header.php`/`owner_footer.php`, `staff_header.php`/`staff_footer.php` — each `*_header.php` independently re-verifies the session role and redirects to `login.php` if it doesn't match, builds the avatar path (gendered default avatar via `jenis_kelamin`, or uploaded `foto_profil`), and (for admin/owner) queries pending-approval counts (e.g. "Pending Dinas" requests) for header notifications. `alert_messages.php` is included where flash messages are shown; it reads `$_SESSION['success_message']`/`$_SESSION['error_message']`, strips all but a small safe tag whitelist, and unsets them (so these session keys are single-shot, PRG-pattern flash messages set right before a `header("Location: ...")` redirect).

**Form/action processing is centralized, not per-page**: `master_process.php` is the single handler for essentially all admin CRUD (jam kerja/work-hour rules, jabatan/positions, cabang/branches, karyawan/employees including soft-delete via `nonaktifkan_karyawan` vs hard `hapus_karyawan`, face-recognition permission toggles, user/account management including owner creation). It dispatches purely on which `$_POST`/`$_GET` key is present (e.g. `isset($_POST['tambah_jam_kerja'])`), always uses prepared statements, and supports both classic redirect-with-flash-message flow and an `is_ajax` flag that instead returns JSON (`json_encode(['status' => ..., 'message' => ...])`) for use by frontend JS/SweetAlert-style calls. When adding new admin actions, follow this same dispatch-by-POST-key convention inside `master_process.php` rather than creating a new processor file, unless the action is domain-specific enough to warrant its own `proses_*.php` (see e.g. `proses_absen.php`, `proses_biodata.php`, `proses_acc_gaji.php`, `proses_persetujuan_dinas.php`, `proses_ganti_password.php`, `proses_upload_ttd.php`, `proses_buat_akun_mandiri.php`).

**Attendance flow (`proses_absen.php`)**: JSON API hit by the staff-facing check-in UI. Key behaviors to preserve when touching this file:
- Only the `Hadir` (present) keterangan requires face verification + GPS geofencing; `OFF`/`Sakit`/`Cuti`/`Alpha`/dinas-luar flows skip those checks.
- Face verification requires a pre-registered `face_descriptor` on the `users` row and a submitted `face_confidence` ≥ `$MIN_FACE_CONFIDENCE` (62.0).
- "Dinas Luar" (off-site duty) check-ins are recorded with keterangan `Pending Dinas` and require admin approval (`proses_persetujuan_dinas.php`) before being finalized as `Dinas Luar`.
- Same-day check-in/check-out is one row per employee per date in `absensi`; the handler first checks whether today's row already exists (`jam_masuk` set) to decide masuk vs pulang branch, uses a duplicate-submission guard (10s window) and a `FOR UPDATE` row lock inside a transaction on insert to avoid double check-ins.
- Late arrival (`status_masuk = 'Terlambat'`) is computed by comparing check-in time against `jam_kerja` rules for the employee's `id_cabang`, picking the closest shift rule when multiple exist. Grace periods and tiered deduction rates can be configured per branch on `admin_detail_cabang.php`; branch overrides are stored as `c{id}_keterlambatan_*` keys in `system_settings`, with the legacy global keys/defaults as fallback.
- Checking out later than the matched shift's `jam_pulang` is treated as overtime and requires an uploaded photo + reason (`alasan_pulang`/`foto_pulang`) before the checkout is accepted.
- All responses go through `outputJSON()` which clears the output buffer first (defensive against stray warnings corrupting the JSON) and always `exit`s.

**Leave / permission requests (`pengajuan_izin`)**: a request is one row covering a **date range**, never one row per day. `staff_pengajuan_izin.php` submits, `kelola_pengajuan_izin.php` reviews (role-aware chrome; supervisors are scoped to their branch, Owner is read-only), and `proses_pengajuan_izin.php` is the single handler (dispatch-by-POST-key: `ajukan_izin` / `batal_izin` / `review_izin`). Rules encoded in `izin_functions.php`:
- `Cuti`, `Sakit`, and `Izin` consume the annual quota (`karyawan.jatah_cuti`, default 12); `Dinas Luar` does not (`izinPotongKuota()`).
- Effective days (`hitungHariIzin()`) count only configured work days (Mon–Fri by default), and exclude registered holidays and any date that already has an `absensi` row — those days neither consume quota nor get a generated row. Always recomputed at approval time, not trusted from submission.
- Pending requests hold quota, so `tersedia = jatah - terpakai - tertahan` is what a new request is checked against.
- **On approval**, `materialisasiIzin()` inserts one `absensi` row per effective day with `id_pengajuan` set and `is_manual_entry = 0` (so `hapus_libur_bersama.php`, which deletes manual-entry OFF/Cuti rows, can't destroy approved leave). This is why no existing report/export/payroll query needed changing — they all still just read `absensi`.
- `Dinas Luar` is the exception: it is **not** materialised. Instead `proses_absen.php` calls `getIzinDinasDisetujui()` at check-in, and when an approved request covers today it bypasses geofencing and records `Dinas Luar` directly instead of the same-day `Pending Dinas` approval flow. Face verification is still required.
- Cancelling only removes materialised rows that have no `jam_masuk`, so real attendance is never deleted.

**Workweek policy & calendar (`kalender_functions.php`)**: the company workweek is **configuration, not hardcode** — `system_settings.hari_kerja` (default `1,2,3,4,5` = Mon–Fri) and `hari_overtime` (default `6` = Saturday), using ISO weekday numbers where 1 = Monday. Admins edit both from `data_hari_libur.php`. Anything asking "is this a work day?" must go through `getHariKerja()` / `isHariKerja()` / `isHariOvertime()` rather than testing `date('w')` directly.
- `hari_libur` is the holiday master (date, name, `jenis` = Nasional/Cuti Bersama/Perusahaan, optional `id_cabang` for one-branch holidays, `perlu_verifikasi` marking seeded lunar dates that still need checking against the official SKB). `getHariLibur()` returns a date-keyed map; branch-specific rows override global ones.
- `bangunKalenderBulan()` builds a Monday-first month grid plus an agenda list. Two modes: personal (`id_karyawan` — own attendance and own leave, including Pending) and global (`global => true` — all *approved* leave ranges in scope, optionally filtered by `id_cabang`). `kalender_widget.php` renders it and is included by the staff, supervisor, admin, and owner dashboards.
- **Saturday is an overtime day, not a work day.** Consequences encoded across the app: it doesn't consume leave quota (`hitungHariIzin()`), `proses_absen.php` does **not** compute `Terlambat` on it (otherwise a 10:00 start would trigger the `rate_keterlambatan` deduction) and does not demand the overtime photo/reason form, and hours are measured from **actual duration** via `hitungJamKerja()` rather than by comparing against a shift's `jam_pulang` — Saturday shifts have no fixed hours. Eligibility is per position (`jabatan.overtime_sabtu`), because some roles have no overtime at all.
- Saturday hours reach payroll as a **suggestion only**: `getLemburHariSabtu()` feeds a panel in `slip_gaji_form.php` with an explicit "Tambahkan" button. No stored payroll formula was changed, so existing slips never recalculate silently.

**Face recognition**: client-side only, via `assets/js/face-recognition.js` using `face-api.js` loaded from a CDN (`@vladmandic/face-api`) — no server-side ML. The browser computes a face descriptor/confidence and posts it to `proses_absen.php` (check-in) or `process_face_register.php`/`register_face.php` (registration) for the server to store/compare.

**Exports/reporting**: no PDF library is vendored. "Excel" exports (`export_*.php`) are actually CSV with a UTF-8 BOM and `;`-delimited `fputcsv`. "PDF" reports (`laporan_*_print.php`, `slip_gaji_form.php`, etc.) are plain HTML pages styled for browser print-to-PDF rather than server-generated PDFs.

**Security conventions already in place** (follow these for any new code rather than introducing new patterns):
- All DB writes/reads with user input use mysqli prepared statements (`$conn->prepare` + `bind_param`); a few older reads combine `real_escape_string` with prepared statements defensively.
- All state-changing POST forms include and verify a `csrf_token` from `generateCSRFToken()`/`verifyCSRFToken()`.
- `.htaccess` denies direct web access to `config.php`, itself, and files with sensitive extensions (`.sql`, `.log`, `.ini`, `.conf`, `.bak`, etc.), and sets basic security headers.
- Login (`login.php`) implements session-based rate limiting (5 attempts / 15 min lockout), regenerates the session ID on success, and looks up by `username` first, falling back to `id_karyawan`. User management now enforces a one-to-one employee/account link via `uniq_users_id_karyawan`; `users.is_active` disables login and invalidates an existing session on its next request.
- Employee soft-delete (`nonaktifkan_karyawan` in `master_process.php`) deletes the linked `users` login row (so the account can't log in) but keeps the `karyawan` row with `status = 'nonaktif'`; attendance/payroll history is never deleted when an employee record is removed, by explicit design.

## Key tables referenced in code (no schema file exists — inferred from queries)

`users` (login accounts; `id_karyawan` FK, `id_cabang` for supervisors, `role` enum admin/owner/supervisor/staff, `face_descriptor`, `face_registered_at`, `face_reset_allowed`, `foto_profil`, `ttd_path`), `karyawan` (employee master data; `id_karyawan` is the public-facing 11-digit ID, `id_jabatan`, `id_cabang`, `status` aktif/nonaktif, `tanggal_resign`, `jatah_cuti`), `cabang` (branches; `latitude`/`longitude`/`radius_meter` for geofencing), `jabatan` (positions; `tunjangan_jabatan` allowance, `overtime_sabtu` Saturday-overtime eligibility), `jam_kerja` (per-branch shift rules; `jam_masuk_akhir`, `jam_pulang`), `hari_libur` (holiday master driving the calendar; `tanggal`, `nama`, `jenis`, nullable `id_cabang`, `perlu_verifikasi`), `system_settings` (key/value; `hari_kerja` & `hari_overtime` define the workweek), `absensi` (one row per employee per date; `jam_masuk`/`jam_pulang`, `lokasi_masuk`/`lokasi_pulang`, `keterangan`, `status_masuk`, `face_verified`/`face_confidence`, `id_pengajuan`, overtime fields), `pengajuan_izin` (leave/permission requests; `jenis`, `tanggal_mulai`/`tanggal_selesai`, `jumlah_hari` vs `jumlah_hari_kerja`, `status`, `potong_kuota`, `reviewed_by`), `activity_logs`, `login_logs`, `face_recognition_logs`.
