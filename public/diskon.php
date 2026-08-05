<?php

declare(strict_types=1);

/**
 * Halaman kelola diskon (admin only).
 *
 * - Daftar semua kode diskon (kode, jenis, nilai).
 * - Tambah diskon baru: kode unik, jenis (persen/nominal), nilai > 0.
 * - Edit diskon, hapus diskon.
 * - Kode diskon dipakai kasir di halaman transaksi (Diskon::cariBerdasarkanKode).
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Diskon;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    $nama403 = $_SESSION['nama'] ?? 'Pengguna';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Akses Ditolak - Kasir Minimarket</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
$pesanTipe = $_SESSION['pesan_tipe'] ?? 'info';
unset($_SESSION['pesan_tipe']);

// Batal edit.
if (isset($_GET['batal_edit'])) {
    unset($_SESSION['edit_diskon_id']);
    header('Location: diskon.php');
    exit;
}

function redirectSelf(string $pesan, string $tipe = 'info'): never
{
    $_SESSION['pesan'] = $pesan;
    $_SESSION['pesan_tipe'] = $tipe;
    header('Location: diskon.php');
    exit;
}

function redirectSelfDenganEdit(string $pesan, int $editId): never
{
    $_SESSION['pesan'] = $pesan;
    $_SESSION['pesan_tipe'] = 'danger';
    $_SESSION['edit_diskon_id'] = $editId;
    header('Location: diskon.php');
    exit;
}

/** Simpan atau perbarui diskon. */
function aksiSimpanDiskon(array $data): void
{
    $editId = (int) ($_SESSION['edit_diskon_id'] ?? 0);
    unset($_SESSION['edit_diskon_id']);

    $kode = strtoupper(trim((string) ($data['kode'] ?? '')));
    $jenis = (string) ($data['jenis'] ?? 'persen');
    $nilai = (float) ($data['nilai'] ?? 0);

    if ($kode === '') {
        redirectSelfDenganEdit('Kode diskon tidak boleh kosong.', $editId);
    }

    if (!in_array($jenis, ['persen', 'nominal'], true)) {
        redirectSelfDenganEdit('Jenis diskon tidak valid.', $editId);
    }

    if ($nilai <= 0) {
        redirectSelfDenganEdit('Nilai diskon harus lebih dari 0.', $editId);
    }

    if ($jenis === 'persen' && $nilai > 100) {
        redirectSelfDenganEdit('Diskon persen maksimal 100%.', $editId);
    }

    try {
        if ($editId > 0) {
            $diskon = Diskon::cari($editId);

            if ($diskon === null) {
                redirectSelf('Diskon tidak ditemukan.', 'danger');
            }

            $diskon->setKode($kode);
            $diskon->setJenis($jenis);
            $diskon->setNilai($nilai);
            $diskon->perbarui();

            redirectSelf('Diskon diperbarui.', 'success');
        }

        $diskon = new Diskon(['kode' => $kode, 'jenis' => $jenis, 'nilai' => $nilai]);
        $diskon->simpan();

        redirectSelf('Diskon ditambahkan.', 'success');
    } catch (\Throwable $e) {
        // Duplikat kode (UNIQUE) atau error lain.
        redirectSelfDenganEdit('Gagal menyimpan diskon: ' . $e->getMessage(), $editId);
    }
}

/** Hapus diskon. */
function aksiHapusDiskon(int $id): void
{
    $diskon = Diskon::cari($id);

    if ($diskon === null) {
        redirectSelf('Diskon tidak ditemukan.', 'danger');
    }

    try {
        $diskon->hapus();
        redirectSelf('Diskon dihapus.', 'success');
    } catch (\Throwable $e) {
        redirectSelf('Diskon tidak bisa dihapus.', 'danger');
    }
}

// ---- Routing aksi (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    switch ($aksi) {
        case 'logout':
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit;

        case 'simpan_diskon':
            aksiSimpanDiskon($_POST);
            break;

        case 'edit_diskon':
            $_SESSION['edit_diskon_id'] = (int) ($_POST['diskon_id'] ?? 0);
            redirectSelf('');
            break;

        case 'hapus_diskon':
            aksiHapusDiskon((int) ($_POST['diskon_id'] ?? 0));
            break;
    }
}

// ---- Data untuk tampilan ----
$diskonSemua = Diskon::semua();
$editDiskonId = (int) ($_SESSION['edit_diskon_id'] ?? 0);
$editDiskon = $editDiskonId > 0 ? Diskon::cari($editDiskonId) : null;

function formatRupiah(float $jumlah): string
{
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Diskon - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="bi bi-shop"></i> Kasir Minimarket</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-diskon"
                aria-controls="nav-diskon" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-diskon">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin.php"><i class="bi bi-box-seam"></i> Admin</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="transaksi.php"><i class="bi bi-cash-register"></i> Kasir</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="laporan.php"><i class="bi bi-bar-chart-line"></i> Laporan</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="supplier.php"><i class="bi bi-truck"></i> Supplier</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="retur.php"><i class="bi bi-arrow-counterclockwise"></i> Retur</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="diskon.php"><i class="bi bi-tags"></i> Diskon</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="user.php"><i class="bi bi-people"></i> Kelola Kasir</a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="theme-toggle" id="toggle-theme" title="Ganti mode terang/gelap">
                    <i class="bi bi-circle-half"></i>
                </button>
                <span class="navbar-text text-white small me-2 d-none d-lg-inline">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nama) ?>
                </span>
                <form method="post" class="d-inline">
                    <input type="hidden" name="aksi" value="logout">
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Kelola Diskon</h1>
        <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($pesanTipe) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Form tambah/edit -->
        <div class="col-lg-4">
            <div class="card pos-card mb-4">
                <div class="card-header bg-white">
                    <?= $editDiskon !== null ? 'Edit Diskon' : 'Tambah Diskon' ?>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="aksi" value="simpan_diskon">
                        <div class="mb-3">
                            <label for="kode-diskon" class="form-label">Kode</label>
                            <input
                                type="text"
                                id="kode-diskon"
                                name="kode"
                                class="form-control text-uppercase"
                                placeholder="cth: DISC10"
                                value="<?= htmlspecialchars($editDiskon !== null ? $editDiskon->getKode() : '') ?>"
                                required
                            >
                            <div class="form-text">Kode dipakai kasir di halaman transaksi.</div>
                        </div>
                        <div class="mb-3">
                            <label for="jenis-diskon" class="form-label">Jenis</label>
                            <select id="jenis-diskon" name="jenis" class="form-select" required>
                                <option value="persen" <?= $editDiskon !== null && $editDiskon->getJenis() === 'persen' ? 'selected' : '' ?>>
                                    Persen (%)
                                </option>
                                <option value="nominal" <?= $editDiskon !== null && $editDiskon->getJenis() === 'nominal' ? 'selected' : '' ?>>
                                    Nominal (Rp)
                                </option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="nilai-diskon" class="form-label">Nilai</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                id="nilai-diskon"
                                name="nilai"
                                class="form-control"
                                placeholder="cth: 10 (persen) atau 2000 (Rp)"
                                value="<?= $editDiskon !== null ? $editDiskon->getNilai() : '' ?>"
                                required
                            >
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($editDiskon !== null): ?>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan</button>
                                <a href="?batal_edit=1" class="btn btn-outline-secondary">Batal</a>
                            <?php else: ?>
                                <button type="submit" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>Tambah</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Daftar diskon -->
        <div class="col-lg-8">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Daftar Diskon</span>
                    <span class="text-muted small"><?= count($diskonSemua) ?> diskon</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($diskonSemua === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada diskon.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Nilai</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($diskonSemua as $d): ?>
                                        <tr>
                                            <td><span class="badge text-bg-light border"><?= htmlspecialchars($d->getKode()) ?></span></td>
                                            <td><?= $d->getJenis() === 'persen' ? 'Persen' : 'Nominal' ?></td>
                                            <td class="text-end">
                                                <?= $d->getJenis() === 'persen' ? $d->getNilai() . '%' : formatRupiah($d->getNilai()) ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="d-inline-flex gap-1">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="aksi" value="edit_diskon">
                                                        <input type="hidden" name="diskon_id" value="<?= $d->getId() ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil me-1"></i>Edit</button>
                                                    </form>
                                                    <form method="post" class="d-inline"
                                                          onsubmit="return confirm('Hapus diskon ini?');">
                                                        <input type="hidden" name="aksi" value="hapus_diskon">
                                                        <input type="hidden" name="diskon_id" value="<?= $d->getId() ?>">
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/theme.js"></script>
</body>
</html>
