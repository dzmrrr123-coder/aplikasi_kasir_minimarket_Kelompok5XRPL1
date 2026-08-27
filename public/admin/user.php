<?php

declare(strict_types=1);

/**
 * Halaman admin: kelola akun kasir.
 *
 * Fitur:
 * - List semua kasir (aktif & nonaktif) dengan stat cards
 * - Tambah kasir baru (modal)
 * - Edit nama & username (modal)
 * - Reset password (modal)
 * - Aktifkan / nonaktifkan (soft-delete)
 * - Hapus kasir tanpa transaksi
 * - Live search by nama / username
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Models\User;

SessionGuard::requireLogin();
SessionGuard::requireRole('admin');

$nama      = $_SESSION['nama'] ?? 'Admin';
$pageTitle = 'Kelola User / Kasir';
$aktif     = 'user';

// ---- Routing aksi POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SessionGuard::validateCsrfToken($_POST['csrf_token'] ?? null)
        || (function () { header('Location: user.php'); exit; })();

    $aksi = (string) ($_POST['aksi'] ?? '');

    try {
        switch ($aksi) {
            case 'tambah':
                User::simpanKasir([
                    'nama'     => trim((string) ($_POST['nama'] ?? '')),
                    'username' => trim((string) ($_POST['username'] ?? '')),
                    'password' => (string) ($_POST['password'] ?? ''),
                ]);
                SessionGuard::setFlash('success', 'Kasir berhasil ditambahkan.');
                break;

            case 'edit':
                $id = (int) ($_POST['id'] ?? 0);
                User::perbaruiKasir($id, [
                    'nama'     => trim((string) ($_POST['nama'] ?? '')),
                    'username' => trim((string) ($_POST['username'] ?? '')),
                    'password' => (string) ($_POST['password'] ?? ''),
                ]);
                SessionGuard::setFlash('success', 'Data kasir berhasil diperbarui.');
                break;

            case 'aktifkan':
                User::setStatusAktifKasir((int) ($_POST['id'] ?? 0), true);
                SessionGuard::setFlash('success', 'Kasir berhasil diaktifkan.');
                break;

            case 'nonaktifkan':
                User::setStatusAktifKasir((int) ($_POST['id'] ?? 0), false);
                SessionGuard::setFlash('success', 'Kasir berhasil dinonaktifkan.');
                break;

            case 'hapus':
                User::hapusKasir((int) ($_POST['id'] ?? 0));
                SessionGuard::setFlash('success', 'Kasir berhasil dihapus.');
                break;
        }
    } catch (Throwable $e) {
        SessionGuard::setFlash('error', $e->getMessage());
    }

    header('Location: user.php');
    exit;
}

$daftarKasir  = User::daftarKasir();
$totalKasir   = count($daftarKasir);
$kasirAktif   = count(array_filter($daftarKasir, fn($k) => $k->isActive()));
$kasirInaktif = $totalKasir - $kasirAktif;

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="bi bi-people me-2 text-success"></i>Kelola User / Kasir</h1>
        <p class="page-subtitle">Manajemen akun dan hak akses kasir</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-tambah" id="btn-tambah-kasir">
        <i class="bi bi-person-plus me-1"></i>Tambah Kasir
    </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-emerald">
                <i class="bi bi-people"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalKasir ?></div>
                <div class="stat-mini-label">Total Kasir</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-teal">
                <i class="bi bi-person-check"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $kasirAktif ?></div>
                <div class="stat-mini-label">Kasir Aktif</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-rose">
                <i class="bi bi-person-dash"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $kasirInaktif ?></div>
                <div class="stat-mini-label">Nonaktif</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Search -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <div class="position-relative" style="min-width:240px;flex:1;max-width:360px">
        <i class="bi bi-search position-absolute" style="left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.85rem"></i>
        <input type="text" id="searchKasir" class="form-control" style="padding-left:2.2rem"
               placeholder="Cari nama atau username...">
    </div>
    <div class="btn-group" id="filterStatus" role="group" aria-label="Filter status">
        <button type="button" class="btn btn-sm btn-outline-secondary active" data-filter="semua">Semua</button>
        <button type="button" class="btn btn-sm btn-outline-success" data-filter="aktif">Aktif</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-filter="nonaktif">Nonaktif</button>
    </div>
    <span class="text-muted small ms-auto" id="countInfo"><?= $totalKasir ?> kasir</span>
</div>

<!-- Tabel Kasir -->
<div class="admin-table-wrap animate-fade-in">
    <?php if (empty($daftarKasir)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-people" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-3">Belum ada kasir terdaftar.</p>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-tambah">
                <i class="bi bi-person-plus me-1"></i>Tambah Kasir Pertama
            </button>
        </div>
    <?php else: ?>
        <table class="admin-table" id="tabel-kasir">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Nama Kasir</th>
                    <th>Username</th>
                    <th style="width:120px" class="text-center">Status</th>
                    <th style="width:200px" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daftarKasir as $i => $kasir): ?>
                <tr data-status="<?= $kasir->isActive() ? 'aktif' : 'nonaktif' ?>">
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php
                                $namaKasir  = $kasir->getNama();
                                $namaK      = explode(' ', trim($namaKasir));
                                $inisialK   = mb_strtoupper(mb_substr($namaK[0], 0, 1));
                                if (isset($namaK[1])) $inisialK .= mb_strtoupper(mb_substr($namaK[1], 0, 1));
                                $bgColors   = ['icon-teal','icon-indigo','icon-amber','icon-sky','icon-purple','icon-emerald','icon-rose'];
                                $colorClass = $bgColors[$kasir->getId() % count($bgColors)];
                            ?>
                            <span class="sidebar-icon <?= $colorClass ?>" style="width:2rem;height:2rem;font-size:0.7rem;font-weight:800;border-radius:50%;flex-shrink:0">
                                <?= htmlspecialchars($inisialK) ?>
                            </span>
                            <span class="fw-semibold <?= !$kasir->isActive() ? 'text-muted' : '' ?>">
                                <?= htmlspecialchars($kasir->getNama()) ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <code class="text-muted" style="font-size:0.8rem;background:var(--surface-2);padding:.2rem .5rem;border-radius:4px;border:1px solid var(--border-soft)">
                            <?= htmlspecialchars($kasir->getUsername()) ?>
                        </code>
                    </td>
                    <td class="text-center">
                        <?php if ($kasir->isActive()): ?>
                            <span class="status-badge status-badge-active">
                                <i class="bi bi-circle-fill" style="font-size:.45rem"></i>
                                Aktif
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-badge-inactive">
                                <i class="bi bi-circle" style="font-size:.45rem"></i>
                                Nonaktif
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <!-- Tombol Edit -->
                        <button class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#modal-edit"
                                data-id="<?= (int) $kasir->getId() ?>"
                                data-nama="<?= htmlspecialchars($kasir->getNama()) ?>"
                                data-username="<?= htmlspecialchars($kasir->getUsername()) ?>"
                                title="Edit data kasir">
                            <i class="bi bi-pencil"></i>
                        </button>

                        <!-- Aktif / Nonaktif -->
                        <?php if ($kasir->isActive()): ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="aksi" value="nonaktifkan">
                            <input type="hidden" name="id" value="<?= (int) $kasir->getId() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Nonaktifkan kasir">
                                <i class="bi bi-person-dash"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="aksi" value="aktifkan">
                            <input type="hidden" name="id" value="<?= (int) $kasir->getId() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success" title="Aktifkan kasir">
                                <i class="bi bi-person-check"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- Hapus -->
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Hapus kasir ini? Hanya bisa dihapus bila belum punya transaksi.')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="aksi" value="hapus">
                            <input type="hidden" name="id" value="<?= (int) $kasir->getId() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus kasir">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Modal Tambah Kasir -->
<div class="modal fade" id="modal-tambah" tabindex="-1" aria-labelledby="modal-tambah-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tambah-label">
                        <span class="sidebar-icon icon-emerald me-2" style="width:1.75rem;height:1.75rem;font-size:0.85rem;display:inline-flex">
                            <i class="bi bi-person-plus"></i>
                        </span>
                        Tambah Kasir Baru
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" required autofocus placeholder="contoh: Andi Setiawan">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required autocomplete="off" placeholder="contoh: andi.kasir">
                        <div class="form-text">Digunakan untuk login. Tidak boleh mengandung spasi.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control" required minlength="6"
                                   autocomplete="new-password" id="pw-tambah">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw-tambah',this)" title="Tampilkan password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimal 6 karakter.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i>Tambah Kasir
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Kasir -->
<div class="modal fade" id="modal-edit" tabindex="-1" aria-labelledby="modal-edit-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-edit-label">
                        <span class="sidebar-icon icon-indigo me-2" style="width:1.75rem;height:1.75rem;font-size:0.85rem;display:inline-flex">
                            <i class="bi bi-pencil"></i>
                        </span>
                        Edit Data Kasir
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" id="edit-nama" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" id="edit-username" required autocomplete="off">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Password Baru</label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control" minlength="6"
                                   autocomplete="new-password" id="pw-edit">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw-edit',this)" title="Tampilkan password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Kosongkan bila tidak ingin mengubah password.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Modal edit: isi data
document.getElementById('modal-edit').addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    document.getElementById('edit-id').value       = b.dataset.id;
    document.getElementById('edit-nama').value     = b.dataset.nama;
    document.getElementById('edit-username').value = b.dataset.username;
});

// Toggle show/hide password
function togglePw(id, btn) {
    var inp = document.getElementById(id);
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        inp.type = 'password';
        btn.innerHTML = '<i class="bi bi-eye"></i>';
    }
}

// Live search kasir
document.getElementById('searchKasir').addEventListener('input', filterTable);

// Filter by status
document.getElementById('filterStatus').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-filter]');
    if (!btn) return;
    document.querySelectorAll('#filterStatus button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterTable();
});

function filterTable() {
    var q      = (document.getElementById('searchKasir').value || '').toLowerCase().trim();
    var filter = (document.querySelector('#filterStatus .active') || {}).dataset?.filter || 'semua';
    var rows   = document.querySelectorAll('#tabel-kasir tbody tr');
    var count  = 0;
    rows.forEach(function (row) {
        var nama     = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() ?? '';
        var username = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() ?? '';
        var status   = row.dataset.status ?? '';
        var matchQ   = !q || nama.includes(q) || username.includes(q);
        var matchF   = filter === 'semua' || status === filter;
        var show     = matchQ && matchF;
        row.style.display = show ? '' : 'none';
        if (show) count++;
    });
    var el = document.getElementById('countInfo');
    if (el) el.textContent = count + ' kasir';
}
</script>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
