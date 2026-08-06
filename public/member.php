<?php

declare(strict_types=1);

/**
 * Halaman admin: CRUD member (pelanggan).
 *
 * Member: nama, telepon (unik), poin. Dipakai di layar POS untuk
 * mencatat transaksi member & mengumpulkan poin (1 poin / Rp 1.000).
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Member;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login sebagai admin.
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

function redirectSelf(string $pesan): never
{
    $_SESSION['pesan'] = $pesan;
    header('Location: member.php');
    exit;
}

/** Simpan atau perbarui member. */
function aksiSimpanMember(array $data): void
{
    $editId = (int) ($_SESSION['edit_member_id'] ?? 0);
    unset($_SESSION['edit_member_id']);

    $nama = trim((string) ($data['nama'] ?? ''));
    $telepon = trim((string) ($data['telepon'] ?? ''));
    $poin = (int) ($data['poin'] ?? 0);
    $password = (string) ($data['password'] ?? '');

    if ($nama === '') {
        $_SESSION['edit_member_id'] = $editId;
        redirectSelf('Nama member tidak boleh kosong.');
    }

    if ($poin < 0) {
        $_SESSION['edit_member_id'] = $editId;
        redirectSelf('Poin tidak boleh negatif.');
    }

    // Password wajib saat tambah member baru (untuk akun login member).
    if ($editId <= 0 && strlen($password) < 6) {
        $_SESSION['edit_member_id'] = $editId;
        redirectSelf('Password minimal 6 karakter (dipakai member untuk login).');
    }

    try {
        if ($editId > 0) {
            $member = Member::cari($editId);

            if ($member === null) {
                redirectSelf('Member tidak ditemukan.');
            }

            $member->setNama($nama);
            $member->setTelepon($telepon);
            $member->setPoin($poin);
            $member->perbarui();

            // Reset password member bila diisi (opsional saat edit).
            if ($password !== '') {
                if (strlen($password) < 6) {
                    $_SESSION['edit_member_id'] = $editId;
                    redirectSelf('Password minimal 6 karakter.');
                }
                $member->setPassword($password);
            }

            redirectSelf('Member diperbarui.');
        }

        $member = new Member([
            'nama'    => $nama,
            'telepon' => $telepon,
            'poin'    => $poin,
            'password' => $password,
        ]);
        $member->simpan();

        redirectSelf(sprintf('Member ditambahkan (nomor member: %s).', $member->getNomorMember()));
    } catch (\Throwable $e) {
        $_SESSION['edit_member_id'] = $editId;
        redirectSelf(pesanErrorRamah($e));
    }
}

/** Hapus member. */
function aksiHapusMember(int $id): void
{
    $member = Member::cari($id);

    if ($member === null) {
        redirectSelf('Member tidak ditemukan.');
    }

    $member->hapus();
    redirectSelf('Member dihapus.');
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

        case 'simpan_member':
            aksiSimpanMember($_POST);
            break;

        case 'edit_member':
            $_SESSION['edit_member_id'] = (int) ($_POST['member_id'] ?? 0);
            redirectSelf('');
            break;

        case 'hapus_member':
            aksiHapusMember((int) ($_POST['member_id'] ?? 0));
            break;
    }
}

// ---- Data untuk tampilan ----
$editMemberId = (int) ($_SESSION['edit_member_id'] ?? 0);
$editMember = $editMemberId > 0 ? Member::cari($editMemberId) : null;
$aktif = 'member';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Member - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Kelola Member</h1>
        <span class="text-muted small">Pelanggan setia & poin (1 poin per Rp 1.000 belanja)</span>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Form tambah/edit -->
        <div class="col-lg-4">
            <div class="card pos-card">
                <div class="card-header bg-white">
                    <?= $editMember !== null ? 'Edit Member' : 'Tambah Member' ?>
                </div>
                <div class="card-body">
                    <?php if ($editMember !== null): ?>
                        <div class="alert alert-light border small py-2 mb-3">
                            <i class="bi bi-person-badge me-1"></i>
                            Nomor member: <strong><?= htmlspecialchars($editMember->getNomorMember()) ?></strong>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="aksi" value="simpan_member">
                        <div class="col-12">
                            <label for="nama-member" class="form-label">Nama</label>
                            <input
                                type="text"
                                id="nama-member"
                                name="nama"
                                class="form-control"
                                value="<?= $editMember !== null ? htmlspecialchars($editMember->getNama()) : '' ?>"
                                placeholder="cth: Budi Santoso"
                                required
                            >
                        </div>
                        <div class="col-12">
                            <label for="telepon-member" class="form-label">Telepon (untuk scan di kasir)</label>
                            <input
                                type="text"
                                id="telepon-member"
                                name="telepon"
                                class="form-control"
                                value="<?= $editMember !== null ? htmlspecialchars($editMember->getTelepon()) : '' ?>"
                                placeholder="cth: 081234567890"
                            >
                        </div>
                        <div class="col-12">
                            <label for="poin-member" class="form-label">Poin</label>
                            <input
                                type="number"
                                id="poin-member"
                                name="poin"
                                class="form-control"
                                value="<?= $editMember !== null ? $editMember->getPoin() : 0 ?>"
                                min="0"
                            >
                        </div>
                        <div class="col-12">
                            <label for="password-member" class="form-label">
                                <?= $editMember !== null ? 'Password baru (kosongkan jika tidak diganti)' : 'Password (untuk login member)' ?>
                            </label>
                            <input
                                type="password"
                                id="password-member"
                                name="password"
                                class="form-control"
                                placeholder="Minimal 6 karakter"
                                <?= $editMember === null ? 'required' : '' ?>
                            >
                            <div class="form-text">
                                Member login di <code>member_login.php</code> pakai nomor member/telepon + password ini.
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi <?= $editMember !== null ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-1"></i>
                                <?= $editMember !== null ? 'Simpan Perubahan' : 'Tambah Member' ?>
                            </button>
                            <?php if ($editMember !== null): ?>
                                <a href="member.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Batal</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Daftar member -->
        <div class="col-lg-8">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Daftar Member</span>
                    <a href="member_login.php" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                        <i class="bi bi-person-badge me-1"></i>Halaman Login Member
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabel-member">
                            <thead class="table-light">
                                <tr>
                                    <th>Nomor Member</th>
                                    <th>Nama</th>
                                    <th>Telepon</th>
                                    <th class="text-center">Poin</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/datatables/dataTables.min.js"></script>
<script src="assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script src="assets/theme.js"></script>
<script>
    // Tabel member via DataTables server-side.
    (function () {
        if (!window.jQuery || !window.DataTable) return;

        jQuery('#tabel-member').DataTable({
            serverSide: true,
            ajax: { url: 'api.php?aksi=member.tabel', data: function (d) { d.draw = d.draw || 0; } },
            pageLength: 10,
            lengthChange: false,
            order: [],
            columns: [
                { data: 'nomor_member', render: function (d) { return '<span class="font-num small">' + (d || '—') + '</span>'; } },
                { data: 'nama' },
                { data: 'telepon', render: function (d) { return d ? '<span class="font-num small">' + d + '</span>' : '<span class="text-muted">—</span>'; } },
                { data: 'poin', className: 'text-center font-num' },
                {
                    data: 'id',
                    className: 'text-center',
                    orderable: false,
                    render: function (d) {
                        return '<span class="d-inline-flex gap-1">' +
                            '<form method="post" class="d-inline">' +
                            '<input type="hidden" name="aksi" value="edit_member">' +
                            '<input type="hidden" name="member_id" value="' + d + '">' +
                            '<button type="submit" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil me-1"></i>Edit</button>' +
                            '</form>' +
                            '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus member ini?\');">' +
                            '<input type="hidden" name="aksi" value="hapus_member">' +
                            '<input type="hidden" name="member_id" value="' + d + '">' +
                            '<button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash me-1"></i>Hapus</button>' +
                            '</form></span>';
                    }
                }
            ],
            language: {
                url: 'assets/vendor/datatables/id.json'
            }
        });
    })();
</script>
</body>
</html>
