<?php

declare(strict_types=1);

/**
 * Halaman kelola akun kasir (admin only).
 *
 * - Daftar semua akun kasir (nama, username, aksi edit/reset password/hapus).
 * - Tambah kasir baru: validasi nama non-kosong, username unik, password >= 6.
 * - Edit nama/username (password opsional diganti bila diisi).
 * - Reset password kasir.
 * - Hapus kasir ditolak bila masih punya transaksi (FK RESTRICT),
 *   dengan pesan error yang jelas (bukan error SQL mentah).
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\User;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login; hanya admin yang boleh membuka halaman ini.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    $nama = $_SESSION['nama'] ?? 'Pengguna';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Akses Ditolak - Kasir Minimarket</title>
        <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
        <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
        <link href="assets/theme.css" rel="stylesheet">
    </head>
    <body class="d-flex align-items-center" style="min-height: 100vh;">
    <div class="container">
        <div class="card pos-card mx-auto" style="max-width: 480px;">
            <div class="card-body text-center p-4">
                <span class="badge text-bg-danger mb-3"><i class="bi bi-shield-exclamation me-1"></i>403</span>
                <h1 class="h4 mb-3">Akses Ditolak</h1>
                <p class="mb-4">Anda tidak memiliki akses ke halaman ini.</p>
                <a href="transaksi.php" class="btn btn-primary"><i class="bi bi-cash-register me-1"></i>Kembali ke Kasir</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$nama = $_SESSION['nama'] ?? 'Admin';

$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);

// Batal edit (via tautan).
if (isset($_GET['batal_edit'])) {
    unset($_SESSION['edit_kasir_id']);
    header('Location: user.php');
    exit;
}

function redirectSelf(string $pesan): never
{
    $_SESSION['pesan'] = $pesan;
    header('Location: user.php');
    exit;
}

function redirectSelfDenganEdit(string $pesan, int $editKasirId): never
{
    $_SESSION['pesan'] = $pesan;
    $_SESSION['edit_kasir_id'] = $editKasirId;
    header('Location: user.php');
    exit;
}

/** Tambah kasir baru. */
function aksiSimpanKasir(array $data): void
{
    $editId = (int) ($_SESSION['edit_kasir_id'] ?? 0);
    unset($_SESSION['edit_kasir_id']);

    try {
        if ($editId > 0) {
            User::perbaruiKasir($editId, $data);
            redirectSelf('Akun kasir diperbarui.');
        }

        User::simpanKasir($data);
        redirectSelf('Akun kasir ditambahkan.');
    } catch (\Throwable $e) {
        redirectSelfDenganEdit(pesanErrorRamah($e), $editId);
    }
}

/** Reset password kasir. */
function aksiResetPassword(int $id, string $password): void
{
    try {
        User::resetPasswordKasir($id, $password);
        redirectSelf('Password kasir berhasil direset.');
    } catch (\Throwable $e) {
        redirectSelf(pesanErrorRamah($e));
    }
}

/** Hapus kasir; ditolak bila masih punya transaksi. */
function aksiHapusKasir(int $id): void
{
    try {
        User::hapusKasir($id);
        redirectSelf('Akun kasir dihapus.');
    } catch (\Throwable $e) {
        redirectSelf(pesanErrorRamah($e));
    }
}

// ---- Routing aksi (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $aksi = $_POST['aksi'] ?? '';

    switch ($aksi) {
        case 'logout':
            logoutKaryawan();
            header('Location: login.php');
            exit;

        case 'simpan_kasir':
            aksiSimpanKasir($_POST);
            break;

        case 'edit_kasir':
            $_SESSION['edit_kasir_id'] = (int) ($_POST['kasir_id'] ?? 0);
            redirectSelf('');
            break;

        case 'reset_password':
            aksiResetPassword(
                (int) ($_POST['kasir_id'] ?? 0),
                (string) ($_POST['password_baru'] ?? '')
            );
            break;

        case 'hapus_kasir':
            aksiHapusKasir((int) ($_POST['kasir_id'] ?? 0));
            break;

        case 'toggle_aktif_kasir':
            try {
                $aktif = (string) ($_POST['aktif'] ?? '1') === '1';
                User::setStatusAktifKasir(
                    (int) ($_POST['kasir_id'] ?? 0),
                    $aktif
                );
                redirectSelf('Status akun kasir diperbarui.');
            } catch (\Throwable $e) {
                redirectSelf(pesanErrorRamah($e));
            }
            break;
    }
}

// ---- Data untuk tampilan ----
$kasirSemua = User::daftarKasir();

// Kasir yang sedang diedit (kalau ada).
$editKasirId = (int) ($_SESSION['edit_kasir_id'] ?? 0);
$editKasir = $editKasirId > 0 ? User::cariKasir($editKasirId) : null;
$aktif = 'user';
$breadcrumb = ['Dashboard' => 'dashboard.php', 'Kelola Kasir' => ''];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Kasir - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Kelola Akun Kasir</h1>
        <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Form tambah/edit kasir -->
        <div class="col-lg-4">
            <div class="card pos-card mb-4">
                <div class="card-header bg-white">
                    <?= $editKasir !== null ? 'Edit Kasir' : 'Tambah Kasir' ?>
                </div>
                <div class="card-body">
                    <?php if ($editKasir !== null): ?>
                        <form method="post" class="mb-3">
                            <input type="hidden" name="aksi" value="simpan_kasir">
                            <div class="mb-3">
                                <label for="nama-kasir" class="form-label">Nama</label>
                                <input
                                    type="text"
                                    id="nama-kasir"
                                    name="nama"
                                    class="form-control"
                                    value="<?= htmlspecialchars($editKasir->getNama()) ?>"
                                    required
                                >
                            </div>
                            <div class="mb-3">
                                <label for="username-kasir" class="form-label">Username</label>
                                <input
                                    type="text"
                                    id="username-kasir"
                                    name="username"
                                    class="form-control"
                                    value="<?= htmlspecialchars($editKasir->getUsername()) ?>"
                                    required
                                >
                            </div>
                            <div class="mb-3">
                                <label for="password-kasir" class="form-label">
                                    Password baru <span class="text-muted">(kosongkan bila tidak diganti)</span>
                                </label>
                                <input
                                    type="password"
                                    id="password-kasir"
                                    name="password"
                                    class="form-control"
                                    placeholder="Minimal 6 karakter"
                                    autocomplete="new-password"
                                >
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                                <a href="?batal_edit=1" class="btn btn-outline-secondary">Batal</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="aksi" value="simpan_kasir">
                            <div class="mb-3">
                                <label for="nama-kasir" class="form-label">Nama</label>
                                <input
                                    type="text"
                                    id="nama-kasir"
                                    name="nama"
                                    class="form-control"
                                    placeholder="cth: Budi Santoso"
                                    required
                                >
                            </div>
                            <div class="mb-3">
                                <label for="username-kasir" class="form-label">Username</label>
                                <input
                                    type="text"
                                    id="username-kasir"
                                    name="username"
                                    class="form-control"
                                    placeholder="cth: budi"
                                    required
                                >
                            </div>
                            <div class="mb-3">
                                <label for="password-kasir" class="form-label">Password</label>
                                <input
                                    type="password"
                                    id="password-kasir"
                                    name="password"
                                    class="form-control"
                                    placeholder="Minimal 6 karakter"
                                    required
                                    autocomplete="new-password"
                                >
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-person-plus me-1"></i>Tambah Kasir
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Daftar kasir -->
        <div class="col-lg-8">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Daftar Akun Kasir</span>
                    <span class="text-muted small"><?= count($kasirSemua) ?> kasir</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($kasirSemua === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada akun kasir.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nama</th>
                                        <th>Username</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($kasirSemua as $k): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($k->getNama()) ?></td>
                                            <td><?= htmlspecialchars($k->getUsername()) ?></td>
                                            <td class="text-center">
                                                <?php if ($k->isActive()): ?>
                                                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary"><i class="bi bi-slash-circle me-1"></i>Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="d-inline-flex gap-1">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="aksi" value="edit_kasir">
                                                        <input type="hidden" name="kasir_id" value="<?= $k->getId() ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil me-1"></i>Edit</button>
                                                    </form>
                                                    <?php if ($k->isActive()): ?>
                                                        <form method="post" class="d-inline"
                                                              onsubmit="return confirm('Nonaktifkan akun ini? Kasir tidak bisa login.');">
                                                            <input type="hidden" name="aksi" value="toggle_aktif_kasir">
                                                            <input type="hidden" name="kasir_id" value="<?= $k->getId() ?>">
                                                            <input type="hidden" name="aktif" value="0">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Nonaktifkan"><i class="bi bi-pause-circle me-1"></i>Nonaktif</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="aksi" value="toggle_aktif_kasir">
                                                            <input type="hidden" name="kasir_id" value="<?= $k->getId() ?>">
                                                            <input type="hidden" name="aktif" value="1">
                                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Aktifkan"><i class="bi bi-play-circle me-1"></i>Aktifkan</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-warning"
                                                        title="Reset password"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modal-reset-<?= $k->getId() ?>"
                                                    >
                                                        <i class="bi bi-key me-1"></i>Reset
                                                    </button>
                                                    <form method="post" class="d-inline"
                                                          onsubmit="return confirm('Hapus akun kasir ini?');">
                                                        <input type="hidden" name="aksi" value="hapus_kasir">
                                                        <input type="hidden" name="kasir_id" value="<?= $k->getId() ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash me-1"></i>Hapus</button>
                                                    </form>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($kasirSemua !== []): ?>
    <?php foreach ($kasirSemua as $k): ?>
        <!-- Modal reset password (di luar tabel, HTML valid) -->
        <div class="modal fade" id="modal-reset-<?= $k->getId() ?>" tabindex="-1"
             aria-labelledby="modal-reset-label-<?= $k->getId() ?>" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="aksi" value="reset_password">
                        <input type="hidden" name="kasir_id" value="<?= $k->getId() ?>">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modal-reset-label-<?= $k->getId() ?>">
                                Reset Password — <?= htmlspecialchars($k->getNama()) ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                        </div>
                        <div class="modal-body">
                            <label for="password-baru-<?= $k->getId() ?>" class="form-label">Password baru</label>
                            <input
                                type="password"
                                id="password-baru-<?= $k->getId() ?>"
                                name="password_baru"
                                class="form-control"
                                placeholder="Minimal 6 karakter"
                                required
                                minlength="6"
                                autocomplete="new-password"
                            >
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>Reset</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/theme.js"></script>
<script src="assets/crud-reload.js"></script>
</body>
</html>
