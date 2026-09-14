# AbsenKita Javag — Sistem Absensi & Slip Gaji

An employee attendance + payroll-slip system built for **Java Abadi Gemilang**. It is a plain PHP + MySQL web app (no framework, no build step) with three user roles — **Admin**, **Owner**, and **Staff** — that together cover clocking in/out with face recognition and GPS, attendance history, monthly statistics, a performance leaderboard, and a full payroll ("slip gaji") creation → approval → distribution workflow with WhatsApp notifications.

This document explains **how the whole system fits together** and then gives a **file-by-file reference** so you can find your way around the codebase quickly.

---

## 1. Tech stack

- **Backend**: plain procedural PHP (mysqli), one `.php` file per page/action — no router, no framework, no Composer packages.
- **Database**: MySQL/MariaDB, database name `db_absensi.kry` (see `config.php`).
- **Frontend**: server-rendered HTML + Tailwind CSS (via CDN) + vanilla JS, sprinkled with SweetAlert2 (dialogs), Chart.js (dashboard charts), DataTables (sortable tables), and `davidshimjs-qrcodejs` (QR codes).
- **Face recognition**: 100% client-side, via `face-api.js` (loaded from a CDN) — the browser computes a numeric "face descriptor" and confidence score and sends only those numbers to the server; there is no server-side ML.
- **"PDF" exports**: there is no PDF library. "Print" pages are plain HTML styled for the browser's print dialog, and "Excel" exports are actually CSV files with a UTF-8 BOM.
- **WhatsApp notifications**: via the third-party **Fonnte** API (`https://api.fonnte.com/send`), using a per-installation `wa_token` stored on a `users` row.
- **No test suite or linter.** Schema changes go through a small tracked migration system (`migrate.php` + `migrations/`) — see `MIGRATIONS.md`.

---

## 2. The four roles

| Role | Who | Can do |
|---|---|---|
| **Admin** | HR / operations staff | Everything day-to-day: manage branches, positions, shifts, employees, user accounts, edit/add attendance records, build and submit payroll slips for Owner approval. |
| **Owner** | Business owner(s) | Read-mostly oversight (dashboards, statistics, employee/branch drill-downs) plus the final approval step for payroll slips (needs a digital signature *and* company stamp uploaded first). Can also create/delete Admin accounts and delete their own account. |
| **Supervisor** | Branch leads | Scoped to a single branch (`users.id_cabang`): review, approve, and reject leave / permission requests (`pengajuan_izin`) from that branch's employees, and monitor their annual leave quota usage. No access to master data, payroll, or user management. |
| **Staff** | Regular employees | Clock in/out, submit leave / permission requests, view their own attendance history and stats, view/approve their own payroll slips, manage their own profile (biodata, photo, face registration, password). |

Every "area" of the app has role-prefixed files: `admin_*.php`, `owner_*.php`, `supervisor_*.php`, `staff_*.php`. Files without a prefix (`data_karyawan.php`, `jam_kerja.php`, `histori_absensi.php`, `absen.php`, …) are either admin-only management pages or role-agnostic endpoints.

Each role has its own header/footer shell (`admin_header.php`/`admin_footer.php`, `owner_header.php`/`owner_footer.php`, `supervisor_header.php`/`supervisor_footer.php`, `staff_header.php`/`staff_footer.php`) that is `include`d at the top/bottom of every page in that area. Each header **independently** re-checks `$_SESSION['role']` and bounces to `login.php` if it doesn't match — there's no central router enforcing this, so it's a per-page contract.

---

## 3. First run / bootstrapping accounts

There's no seed script — the very first account is created through the UI:

1. On a fresh database (zero rows with `role='admin'` in `users`), `login.php` shows a **"Buat Master Admin"** button linking to `buat_akun.php`. That page only works while `admin_count < 1`; it creates the first Admin and then locks itself.
2. From then on, more Admins are normally created *by an existing Admin* from inside the app (`setting_users.php` → `master_process.php`), or via **`daftar_admin.php`**, a self-service registration page gated by a **rotating time-based secret code**: `strtoupper(substr(md5(date('Y-m-d H') . 'DINIA_ADMIN_SECRET_2026'), 0, 6))`, valid for the current or previous hour. Whoever knows the salt (presumably the developer, out-of-band) can compute the 6-character code and register as Admin without needing an existing Admin's help.
3. **`buat_akun_owner.php`** works the same way for Owner accounts, with its own salt (`DINIA_OWNER_SECRET_2026`). Admins can also create Owner accounts directly from `setting_users.php`.
4. Staff accounts are normally created by an Admin (`setting_users.php` → `master_process.php`, `tambah_staff`), but an employee can also self-register their own login via **`proses_buat_akun_mandiri.php`** if their `id_karyawan` exists in `karyawan` but has no `users` row yet (username defaults to their employee ID, or a custom one).

> ⚠️ Note for anyone hardening this app: the admin/owner secret salts are hardcoded string literals in `daftar_admin.php` / `buat_akun_owner.php`. Anyone with source access can compute a valid registration code.

---

## 4. Everyday system flow

### 4.1 Login (`login.php` → `config.php`)
Every request starts by `require`-ing `config.php`, which starts the session (with stricter cookie flags in production HTTPS), opens the mysqli connection, and defines the shared helper functions (`isLoggedIn()`, `requireAdmin()`, CSRF helpers, `sanitizeInput()`, etc. — see `security_functions.php` too).

`login.php` looks the submitted "ID / Username" up first by `username`, then falls back to matching `id_karyawan` (preferring a `staff` account if an employee has more than one login). It enforces 5-attempt/15-minute lockout via session counters, regenerates the session ID on success, and redirects to the right dashboard by role. If the login is tied to an employee whose `karyawan.status` isn't `aktif`, login is refused even with a correct password (the account still exists but the person has been offboarded).

### 4.2 Daily attendance (clock in / clock out)
This is the core feature and it has **two front doors**:

- **`absen.php?id=<id_karyawan>`** — a link/QR-code-driven check-in kiosk page, *not* gated by login. It's meant to be opened via a QR code (generated per-employee in `ambil_qrcode.php`) or a link (e.g. sent by the WhatsApp reminder cron), and shows only that one employee's status/actions for today.
- The **Staff dashboard** area also exposes check-in through the same underlying flow.

Both submit to **`proses_absen.php`**, a JSON API that is the single source of truth for the check-in/check-out business rules:

1. **Only `keterangan = 'Hadir'` (present) requires face + GPS verification.** OFF / Sakit / Cuti / Alpha / Dinas Luar requests skip both.
2. **Face verification** requires the employee to have already enrolled a `face_descriptor` (see §4.3) and the browser-computed `face_confidence` for this attempt must be ≥ **62%**.
3. **GPS geofencing**: the browser's `lat,long` is compared (Haversine formula, `security_functions.php::validateLokasiAbsen()`) against the employee's branch (`cabang.latitude/longitude/radius_meter`). If the branch has no coordinates configured, geofencing is skipped for backward compatibility.
4. **"Dinas Luar" (off-site duty)** check-ins bypass GPS but are recorded as `keterangan = 'Pending Dinas'` and must be approved by an Admin (`proses_persetujuan_dinas.php`) before becoming `Dinas Luar`. Pending requests older than 4 hours are auto-deleted the next time `absen.php` loads for anyone (a cheap, no-cron cleanup).
5. One `absensi` row per employee per calendar date: the first submission of the day is "masuk" (clock-in, decides `status_masuk` = Tepat Waktu/Terlambat by comparing against the branch's `jam_kerja` shift rules), the second is "pulang" (clock-out). A `FOR UPDATE` row lock + a 10-second duplicate-submission guard inside a DB transaction prevent double-clocking.
6. **Overtime**: if clock-out happens after the matched shift's `jam_pulang`, the employee must supply a reason + photo proof before the checkout is accepted; the payroll calculator later turns this into paid overtime hours.
7. Admins can retroactively fix a day's record (`update_absensi.php`), add manual OFF/Sakit/Cuti entries for a single employee (`tambah_absensi_manual.php`), or bulk-insert a shared holiday ("Libur Bersama") across a branch or the whole company for a date range (`tambah_libur_bersama.php`, reversible via `hapus_libur_bersama.php`). Employees can edit their own submitted reason/photo within a **2-hour window** (`update_alasan_karyawan.php`).

### 4.2b Leave & off-site permission requests (`pengajuan_izin`)

Staff submit a **request covering a date range** (e.g. 2–5 Aug) from `staff_pengajuan_izin.php`; everything is handled by `proses_pengajuan_izin.php` (dispatch-by-POST-key: `ajukan_izin` / `batal_izin` / `review_izin`).

- **Types:** `Cuti`, `Sakit`, `Izin` consume the annual quota (`karyawan.jatah_cuti`, default 12/year); `Dinas Luar` does not.
- **Effective days:** only configured work days count (Mon–Fri by default); Saturdays, Sundays, registered holidays, and dates that already have an `absensi` row are skipped — they neither consume quota nor generate rows. Recomputed at approval time, since the calendar can change between submission and review.
- **Quota holds:** pending requests reserve quota (`tersedia = jatah - terpakai - tertahan`) so the same days can't be requested twice.
- **Review:** Supervisor (own branch) or Admin (all branches) approves/rejects via `kelola_pengajuan_izin.php`; Owner sees the same page read-only. Rejection requires a reason.
- **On approval**, `absensi` rows are materialised for each effective day with the matching `keterangan` and `id_pengajuan` set, so every existing report, export, and payroll query keeps working unchanged. These rows carry `is_manual_entry = 0` so `hapus_libur_bersama.php` can't wipe them.
- **Approved `Dinas Luar`** is *not* materialised — instead `proses_absen.php` looks it up on clock-in (`getIzinDinasDisetujui()`), waives the GPS geofence for that day, and records `Dinas Luar` directly rather than the same-day `Pending Dinas` flow.
- **Cancellation:** staff may cancel while `Pending`, or while `Disetujui` if the range hasn't started yet; materialised rows are removed only for days with no `jam_masuk`, so real attendance is never destroyed.

Domain logic lives in `izin_functions.php` (auto-included by `config.php`).

### 4.2c Workweek, holidays & the calendar

The company workweek is stored in `system_settings`, not hardcoded: `hari_kerja` (default `1,2,3,4,5` = **Mon–Fri**) and `hari_overtime` (default `6` = **Saturday**), in ISO weekday numbers (1 = Monday). Admins edit both, plus the holiday list, at `data_hari_libur.php`.

- `hari_libur` holds the holidays that drive every calendar: name, `jenis` (Nasional / Cuti Bersama / Perusahaan), and an optional `id_cabang` when a holiday applies to one branch only. Holidays never consume leave quota.
- Every "is this a work day?" decision goes through `kalender_functions.php` (`isHariKerja()`, `isHariOvertime()`, `getHariLibur()`) — don't test `date('w')` directly in new code.
- `bangunKalenderBulan()` + `kalender_widget.php` render a Monday-first month grid with an agenda list, in two modes: **personal** (staff: own attendance + own leave, Pending included) and **global** (supervisor scoped to their branch, admin/owner company-wide: all *approved* leave ranges, so a whole team's absences are readable at a glance). Navigation is `?bulan=&tahun=`.

**Saturday is an overtime day, not a work day.** That distinction is enforced in several places, and matters because Saturday hours vary by assignment (a Product Manager might work 10:00–15:00 one week and not at all the next):

| Behaviour | Work day (Mon–Fri) | Overtime day (Sat) |
|---|---|---|
| Consumes leave quota | Yes | No |
| `Terlambat` / late deduction | Yes, vs shift `jam_masuk_akhir` | **Never** — no fixed start time |
| Overtime measured by | Clock-out past shift `jam_pulang` | **Actual hours worked** (`hitungJamKerja()`) |
| Overtime photo + reason form | Required past `jam_pulang` | Not required — the whole day is overtime |
| Who is eligible | — | Only positions with `jabatan.overtime_sabtu = 1` |

Saturday hours are surfaced in `slip_gaji_form.php` as a **suggestion with an explicit "Tambahkan" button**, never applied automatically. No stored payroll formula changed, so existing slips do not recalculate on their own.

### 4.3 Face registration
Staff enroll their face once via **`register_face.php`** (camera UI) → **`process_face_register.php`** (saves the descriptor to `users.face_descriptor`, logs to `face_recognition_logs`). The client-side engine (`assets/js/face-recognition.js`) also implements **liveness checks** (blink detection via Eye Aspect Ratio, mouth-open detection via Mouth Aspect Ratio) to make simple photo-spoofing harder, and a face-quality check (size/centering) before capture. Matching at check-in time uses Euclidean distance between descriptors, converted to a 0–100% confidence score (63% threshold client-side / 62% server-side).

If an employee needs to re-register (lost access, changed appearance, etc.), an Admin must explicitly grant permission or wipe the stored face data first, via `toggle_face_reset_permission.php` (called from `master_process.php`'s face-permission actions too) — staff can't reset their own face data unilaterally.

### 4.4 Reminders (cron)
**`cron_reminder_absensi.php`** is meant to run on a schedule (documented for 18:15 WIB) outside the web request cycle. It finds every employee with no `absensi` row for today and sends them a WhatsApp nudge (via Fonnte) with a direct link to `absen.php?id=...`.

### 4.5 Payroll ("Slip Gaji") — a three-stage approval workflow
This is the second major subsystem, entirely admin-initiated:

1. **Admin builds a slip**: `slip_gaji.php` (pick an employee/month) → `slip_gaji_form.php` (the actual calculator UI — pulls the month's `absensi` rows and auto-computes attendance day counts, half-days, lateness count, overtime hours, and Sunday-worked days; lets the admin set/override per-item rates: `gaji_pokok`, `tunjangan_cs`, transport, overtime, Sunday incentive, lateness deduction, plus free-form extra income/deduction line items) → **`slip_gaji_process.php`** does the actual math and INSERT/UPDATE into `slip_gaji` (+ child tables `slip_gaji_penghasilan` / `slip_gaji_potongan` for the extra line items). Editing an existing slip is locked 5 days after creation. `ajax_save_rate.php` lets an admin persist a rate (e.g. transport rate) back onto the `karyawan` row as that employee's new default.
2. **Admin approves** their own draft (`proses_acc_gaji.php`, action `acc_admin`) — blocked until the admin has uploaded a digital signature (`users.ttd_path`, via `proses_upload_ttd.php`).
3. **Owner approves** (`owner_approval_gaji.php` UI → `proses_acc_gaji.php`, action `acc_owner`) — blocked until the Owner has uploaded *both* a signature and a company stamp (`stempel_path`). On success, a WhatsApp message is sent to the employee via Fonnte announcing the net pay and a login link.
4. **Employee acknowledges** (`staff_laporan_gaji.php` → `proses_acc_gaji.php`, action `acc_karyawan`) — the employee's own sign-off, not gated on having a signature themselves.

Printable/exportable views sit on top of the same `slip_gaji` data: `laporan_gaji_print.php` (single/filtered print), `laporan_slip_batch.php` (bulk print of many employees' slips at once, styled like real payslip stationery), and `export_slip_gaji.php`.

### 4.6 Reporting & statistics
`laporan.php` is a hub page (Admin/Owner) linking out to the various print reports (attendance recap, employee biodata, payroll). `statistik_absensi.php` / `owner_statistik_absensi.php` / `staff_statistik.php` show aggregated attendance stats (their own print variant: `staff_statistik_print.php`). `klasemen_performance.php` computes a **monthly leaderboard** ranking employees by (days present, then total clocked hours) — the same query powers the "Best Performance" widget shown on the Admin and Owner dashboards; `staff_ranking_history.php` lets a staff member see their own placement over past months.

### 4.7 Master data management (Admin)
Branches (`data_cabang.php`, incl. GPS coordinates + geofence radius), positions/`jabatan` (`data_jabatan.php`, carries a default allowance), shift rules (`jam_kerja.php`, per-branch clock-in cutoff / clock-out time — a branch can have multiple shifts, and the system picks whichever shift's cutoff is closest to the employee's actual clock-in when deciding lateness/overtime), and employees (`data_karyawan.php`, incl. soft-delete/"nonaktifkan" vs hard delete). All of these submit their add/edit/delete actions to the single shared **`master_process.php`** dispatcher (see §5).

---

## 5. `master_process.php` — the shared form processor

Rather than each management page having its own save/delete script, almost all Admin CRUD (`jam_kerja`, `jabatan`, `cabang`, `karyawan`, face-permission toggles, staff/owner account creation, user password edit/delete) posts to this one file. It dispatches purely by checking which POST/GET key is present (e.g. `if (isset($_POST['tambah_jam_kerja']))`), always uses prepared statements, and supports two response modes: classic redirect + session flash message (`$_SESSION['success_message']`/`error_message`, rendered by `alert_messages.php`), or — if the request includes `is_ajax=1` — a JSON response consumed by the SweetAlert2-driven fetch helpers in `assets/js/main.js` (`handleDeleteAction`, `handleFormAjaxGlobal`). When adding a new Admin action, the established convention is to extend `master_process.php` rather than create a new file, unless the action is domain-specific enough to deserve its own `proses_*.php` (attendance, biodata, payroll approval, etc. already do this).

---

## 6. Security notes (what's already in place)

- All DB access with user input goes through mysqli **prepared statements**.
- Every state-changing form includes and verifies a session-bound **CSRF token** (`generateCSRFToken()` / `verifyCSRFToken()`).
- `.htaccess` blocks direct web access to `config.php`, itself, and sensitive extensions (`.sql .log .ini .conf .bak` …), and sets basic security headers.
- Session cookies are `HttpOnly` always, and `Secure` + `SameSite=Strict` when the app detects it's running on non-localhost HTTPS.
- Login has rate limiting + lockout, and session ID regeneration on successful auth.
- Deleting/deactivating an employee removes their `users` login row (so they can't sign in) but **never deletes their historical `absensi`/`slip_gaji` rows** — this is intentional, to preserve records for reporting/closing the books.
- Face re-registration requires explicit Admin permission — staff cannot reset their own biometric enrollment.

---

## 7. Key database tables (inferred from queries — no schema file exists)

| Table | Purpose |
|---|---|
| `users` | Login accounts. `role` = admin/owner/supervisor/staff, `is_active` controls account access, and `id_karyawan` is nullable but unique (one employee per account). Also stores `face_descriptor`/`face_registered_at`/`face_reset_allowed`, `foto_profil`, `ttd_path` (signature), `stempel_path` (owner's company stamp), and `wa_token` (Fonnte API token). |
| `karyawan` | Employee master data. Public-facing `id_karyawan` is an 11-digit code (`YYYYMMDDXXX`). `id_jabatan`, `id_cabang` FKs, `status` (aktif/nonaktif), `tanggal_resign`, `jatah_cuti` (annual leave quota, default 12), payroll default rates (`rate_transport`, `rate_overtime`, `rate_insentif_minggu`, `gaji_pokok`, `rate_keterlambatan`), `no_whatsapp`. |
| `cabang` | Branches. `latitude`/`longitude`/`radius_meter` power the check-in geofence. |
| `jabatan` | Positions, each with a default `tunjangan_jabatan` allowance and `overtime_sabtu` (1 = this position can be assigned Saturday overtime). |
| `jam_kerja` | Per-branch shift rules: `nama_shift`, `jam_masuk_akhir` (latest on-time clock-in), `jam_pulang` (scheduled clock-out). A branch can have several. |
| `hari_libur` | Holiday master driving every calendar. `tanggal`, `nama`, `jenis` (Nasional/Cuti Bersama/Perusahaan), nullable `id_cabang` (NULL = all branches), `perlu_verifikasi` (1 = seeded lunar date, still needs SKB confirmation). Unique on (`tanggal`, `id_cabang`). |
| `system_settings` | Key/value config. `hari_kerja` and `hari_overtime` hold the workweek as ISO weekday lists (1 = Monday). |
| `absensi` | One row per employee per date. `jam_masuk`/`jam_pulang`, `lokasi_masuk`/`lokasi_pulang` (GPS), `keterangan` (Hadir/OFF/Sakit/Cuti/Izin/Alpha/Pending Dinas/Dinas Luar), `id_pengajuan` (set when the row was generated by an approved leave request), `status_masuk` (Tepat Waktu/Terlambat), `face_verified`/`face_confidence`, `alasan`/`foto_bukti` (reason/proof photo), `alasan_pulang`/`foto_pulang` (overtime proof), `is_manual_entry`/`manual_entry_by`. |
| `pengajuan_izin` | One row per leave / permission **request** (a date range, not a day). `jenis` (Cuti/Sakit/Izin/Dinas Luar), `tanggal_mulai`/`tanggal_selesai`, `jumlah_hari` (calendar days) vs `jumlah_hari_kerja` (effective days that consume quota), `keperluan`, `lampiran`, `status` (Pending/Disetujui/Ditolak/Dibatalkan), `potong_kuota`, `id_cabang` (snapshot for supervisor scoping), `reviewed_by`/`reviewed_at`/`catatan_reviewer`. |
| `slip_gaji` | One row per employee per payroll period (`bulan`/`tahun`). All computed pay components, plus 3-stage approval flags: `status_admin_acc`/`admin_id`/`admin_acc_at`, `status_owner_acc`/`owner_id`/`owner_acc_at`, `status_karyawan_acc`/`karyawan_acc_at`. |
| `slip_gaji_penghasilan` / `slip_gaji_potongan` | Free-form extra income / deduction line items attached to a slip. |
| `activity_logs` | Audit trail written by `logActivity()`. |
| `login_logs` | Login attempt history. |
| `face_recognition_logs` | Face verification attempts (success/fail, confidence). |
| `face_admin_logs` | Admin actions on employees' face data (allow/lock/delete reset). |

---

## 8. File reference

### Bootstrap & shared includes
| File | Purpose |
|---|---|
| `config.php` | Session/DB bootstrap, role-check helpers, CSRF helpers. Included by (almost) everything. |
| `security_functions.php` | Password validation, rate limiting, output sanitization, `validateIDKaryawan()`, `logActivity()`, Haversine distance + `validateLokasiAbsen()` geofencing. |
| `alert_messages.php` | Renders and clears one-shot `$_SESSION['success_message']`/`error_message`. |
| `admin_header.php` / `admin_footer.php` | Admin chrome: role guard, avatar, nav, pending-approval notification counts, opens/closes the page shell. |
| `owner_header.php` / `owner_footer.php` | Same, for Owner. |
| `staff_header.php` / `staff_footer.php` | Same, for Staff. |
| `assets/js/main.js` | Sidebar/theme toggle, QR generation, and shared SweetAlert2-driven AJAX helpers (`handleDeleteAction`, `handleFormAjaxGlobal`, `confirmLogout`, `confirmRedirect`) used across almost every page. |
| `assets/js/face-recognition.js` | `FaceRecognitionSystem` class: camera control, face-api.js model loading, descriptor capture/comparison, liveness (blink/mouth) checks, face-quality checks. |
| `index.php` | Public marketing/welcome landing page, links to `login.php`. |
| `login.php` | Login form + auth logic (rate limiting, role-based redirect). |
| `logout.php` | Destroys the session, redirects to login. |

### Attendance (clock in/out & history)
| File | Purpose |
|---|---|
| `absen.php` | QR/link-driven check-in kiosk for a single employee (`?id=<id_karyawan>`), not login-gated. Shows today's status and the check-in/out UI. Also auto-purges stale "Pending Dinas" requests (>4h). |
| `proses_absen.php` | JSON API: the actual check-in/check-out logic (face + GPS validation, lateness/overtime detection, duplicate/row-lock guards). See §4.2. |
| `ambil_qrcode.php` | Generates/prints per-employee ID card + QR code (the QR points at `absen.php?id=...`). |
| `histori_absensi.php` | Admin: daily attendance log per branch, with inline edit/manual-entry/bulk-holiday actions. |
| `owner_histori_absensi.php` / `owner_rekap_absensi.php` | Owner's read-oriented mirrors of the daily attendance log. |
| `tambah_absensi_manual.php` | Admin: insert a single OFF/Sakit/Cuti record for one employee/date. |
| `tambah_libur_bersama.php` | Admin: bulk-insert OFF/Cuti across a date range for a branch or all branches ("Libur Bersama" / shared holiday). |
| `hapus_libur_bersama.php` | Admin: bulk-delete those manually-entered OFF/Cuti records for a date range. |
| `update_absensi.php` | Admin: correct an existing attendance row's time/keterangan/status. |
| `update_alasan_karyawan.php` | JSON API: employee edits their own submitted reason/photo, only within 2 hours of the original submission. |
| `proses_persetujuan_dinas.php` | Admin: approve/reject a "Pending Dinas" (off-site duty) request, finalizing it as `Dinas Luar` or rejecting it. |
| `ajax_get_overtime_details.php` | JSON API: lists an employee's overtime dates for a given month (used by the payroll form). |
| `cron_reminder_absensi.php` | Cron script: WhatsApp-reminds every employee who hasn't clocked in today (via Fonnte). |

### Leave & permission requests
| File | Purpose |
|---|---|
| `staff_pengajuan_izin.php` | Staff-facing: quota widget, submit form (type, date range, reason, optional attachment) with a live client-side day estimate, and their own request history with cancel action. |
| `kelola_pengajuan_izin.php` | Review queue shared by Supervisor / Admin / Owner. Picks the matching header/footer by role, scopes rows to the supervisor's branch, shows each requester's quota context, and approve/reject controls (Owner is read-only). |
| `proses_pengajuan_izin.php` | Single handler for submit / cancel / review. Validates dates, overlap, quota, and attachment; approval runs in a transaction with `FOR UPDATE` on the request row and materialises `absensi` rows. |
| `izin_functions.php` | Domain helpers: effective-day counting, quota summary, overlap check, materialise/roll back attendance rows, approved-Dinas-Luar lookup, badge/format helpers. |
| `supervisor_dashboard.php` | Supervisor home: pending-review count, who's out today, and per-employee quota usage for their branch. |
| `supervisor_header.php` / `supervisor_footer.php` | Supervisor chrome; the header re-checks the role, resolves the supervised branch, and lists pending requests in the notification bell. |

### Calendar, holidays & workweek
| File | Purpose |
|---|---|
| `kalender_functions.php` | Workweek settings accessor (`getHariKerja`/`getHariOvertime`), holiday lookup, month-grid builder (`bangunKalenderBulan`), work-duration and Saturday-overtime calculation. |
| `kalender_widget.php` | Reusable month-calendar partial (personal or global mode) with colour legend and agenda list. Included by all four dashboards. |
| `data_hari_libur.php` | Admin: holiday CRUD (single date or range, per-branch or global) plus the work-day / overtime-day checkbox matrix. Flags seeded lunar dates as needing SKB verification. |

### Statistics, ranking & reports
| File | Purpose |
|---|---|
| `statistik_absensi.php` / `owner_statistik_absensi.php` | Admin/Owner attendance statistics dashboards (by branch/employee/period). |
| `staff_statistik.php` / `staff_statistik_print.php` | Staff's own attendance statistics, and a printable version. |
| `klasemen_performance.php` | Monthly performance leaderboard (ranked by days present, then hours worked). |
| `staff_ranking_history.php` | Staff's personal ranking history across past months. |
| `laporan.php` | Admin/Owner "report center" hub linking to the print reports below. |
| `laporan_absensi_print.php` | Printable attendance recap. |
| `laporan_biodata_print.php` | Printable employee biodata sheet. |
| `laporan_karyawan_print.php` | Printable employee roster. |
| `export_absensi.php` | CSV export of attendance recap. |
| `export_statistik.php` | CSV export of statistics. |
| `export_karyawan_cabang.php` | CSV export of a branch's employee list. |
| `export_karyawan_pdf.php` | Print-styled ("PDF" via browser print) employee data view. |
| `export_staff_absensi.php` | Staff's own attendance history, print-styled, generated client-side from data already on `staff_dashboard.php`. |

### Master data (branches, positions, shifts, employees)
| File | Purpose |
|---|---|
| `data_cabang.php` / `owner_cabang.php` | Branch CRUD (Admin) / read-only view (Owner) — name, address, GPS coords, geofence radius. |
| `owner_detail_cabang.php` | Owner: drill-down into one branch's employees/summary. |
| `data_jabatan.php` | Position CRUD — name + default allowance. |
| `jam_kerja.php` | Shift-rule CRUD per branch (clock-in cutoff, scheduled clock-out). |
| `admin_detail_cabang.php` | Admin branch detail and per-branch lateness grace period/tiered deduction settings. |
| `data_karyawan.php` | Employee CRUD (add/edit/soft-deactivate/hard-delete), including the "arsip" (archived/inactive) view. |
| `owner_data_karyawan.php` | Owner's read-only employee list/browser. |
| `lihat_karyawan_cabang.php` | Admin: employee roster scoped to one branch. |
| `admin_detail_karyawan.php` / `owner_detail_karyawan.php` | Single-employee detail/profile view for Admin / Owner. |

### Payroll ("Slip Gaji")
| File | Purpose |
|---|---|
| `slip_gaji.php` | Admin: pick an employee + period to create/view a payroll slip. |
| `slip_gaji_form.php` | Admin: the payroll calculator/editor — pulls attendance data, auto-computes pay components, allows manual overrides and free-form line items. |
| `slip_gaji_process.php` | Backend: validates, computes totals, and INSERT/UPDATEs `slip_gaji` (+ line-item child tables). Enforces a 5-day edit lock after creation. |
| `ajax_save_rate.php` | JSON API: persist a payroll rate override back onto the employee's record as their new default. |
| `proses_acc_gaji.php` | JSON API: the 3-stage approval action (`acc_admin` / `acc_owner` / `acc_karyawan`), including the owner-approval WhatsApp notification. |
| `owner_approval_gaji.php` | Owner: queue of slips awaiting Owner approval. |
| `staff_laporan_gaji.php` | Staff: view own payroll slips and give final acknowledgement (ACC). |
| `slip_gaji_karyawan.php` | Admin: employee picker for viewing/printing a specific employee's slips. |
| `laporan_gaji_print.php` | Printable single/filtered payroll report. |
| `laporan_slip_batch.php` | Printable batch payslip run (many employees at once, payslip-styled). |
| `export_slip_gaji.php` | Print-styled single payslip view. |

### Accounts, profile & permissions
| File | Purpose |
|---|---|
| `setting_users.php` | Admin: user account management hub (list/create/edit/delete staff, admin, owner accounts). |
| `buat_akun.php` | One-time bootstrap: create the very first Master Admin (self-disables once an admin exists). |
| `daftar_admin.php` | Self-service additional-Admin registration, gated by a rotating hourly secret code. |
| `buat_akun_owner.php` | Self-service Owner registration, gated by its own rotating hourly secret code. |
| `owner_hapus_akun.php` | Owner: delete their own account (and log out). |
| `proses_buat_akun_mandiri.php` | JSON API: employee self-service login creation for their own `id_karyawan` (if no account exists yet). |
| `proses_ganti_password.php` | Staff: change own password. |
| `admin_pengaturan.php` / `owner_pengaturan.php` / `staff_pengaturan.php` | Per-role account settings pages (photo, signature/stamp upload entry points, biodata for staff, WhatsApp token entry for notifications). |
| `proses_biodata.php` | Staff: update their own biodata fields on `karyawan`. |
| `proses_upload_ttd.php` | Upload a digital signature (`ttd_path`) and, for Owner, a company stamp (`stempel_path`) — required before payroll approval actions unlock. |
| `toggle_face_reset_permission.php` | Admin: allow/lock a staff member's ability to re-register their face, or wipe their stored face data outright. |
| `register_face.php` | Staff-facing camera UI to enroll a face descriptor. |
| `process_face_register.php` | Backend: saves the captured face descriptor to `users`, logs the enrollment. |

### Dashboards
| File | Purpose |
|---|---|
| `admin_dashboard.php` | Admin home: today's headline stats, employees-per-branch chart, recent activity feed, monthly Best Performance widget. |
| `owner_dashboard.php` | Owner home: today's stats broken out by keterangan (Hadir/OFF/Sakit/Cuti/Alpha), a 7-day attendance trend chart (Chart.js), Best Performance widget. |
| `staff_dashboard.php` | Staff home: their own attendance history table with date filtering, per-row status computation (on-time/late, normal/overtime/half-day), and a client-side "export to PDF" (print) action. |

### One-off / utility scripts
| File | Purpose |
|---|---|
| `check_db.php` | Dumps `SHOW TABLES` — quick DB sanity check, not part of any tooling pipeline. |
| `migrate.php` | Runs pending schema migrations from `migrations/`, tracked in a `schema_migrations` table so each runs exactly once, in order. `requireAdmin()`-gated on the web; `php migrate.php status` / `migrate --yes` via CLI. See `MIGRATIONS.md`. |

---

## 9. Things to keep in mind when extending this codebase

- There's no framework and no autoloader — new pages are plain files that `require 'config.php'` and (for authenticated areas) `include` the matching role header/footer.
- Follow the existing **dispatch-by-POST-key** convention in `master_process.php` for new generic Admin CRUD actions; only branch out to a new `proses_*.php` file when the logic is domain-specific enough to warrant it (attendance, payroll, face data, biodata already do this).
- Anything that changes state must carry and verify a CSRF token, and must use prepared statements for any user-supplied value.
- If you touch the attendance flow, re-read §4.2 carefully — the face/GPS rules are conditional on `keterangan`, and there are transaction + row-lock guards specifically to prevent duplicate clock-ins that are easy to accidentally break.
- Deleting employees/users should never cascade into `absensi` or `slip_gaji` — historical records are kept by design even after an employee is removed.
