<?php

declare(strict_types=1);

/**
 * Area member (pelanggan): lihat nomor member, saldo poin, riwayat
 * belanja singkat, dan tukar poin dengan badge/hadiah dari katalog.
 *
 * Sesi member terpisah dari sesi karyawan (member_id).
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;
use App\Models\Member;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login member.
if (!isset($_SESSION['member_id'])) {
    header('Location: member_login.php');
    exit;
}

$member = Member::cari((int) $_SESSION['member_id']);

// Member sudah dihapus -> hancurkan sesi member.
if ($member === null) {
    unset($_SESSION['member_id'], $_SESSION['member_nama'], $_SESSION['member_nomor']);
    header('Location: member_login.php');
    exit;
}

$pesan = $_SESSION['pesan_member'] ?? '';
unset($_SESSION['pesan_member']);

$error = $_SESSION['error_member'] ?? '';
unset($_SESSION['error_member']);

// ---- Aksi: tukar poin ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'tukar_poin') {
    require_csrf();
    $hadiahId = (int) ($_POST['hadiah_id'] ?? 0);

    try {
        Member::tukarPoin((int) $member->getId(), $hadiahId);
        $_SESSION['pesan_member'] = 'Poin berhasil ditukar.';
    } catch (\Throwable $e) {
        $_SESSION['error_member'] = pesanErrorRamah($e);
    }

    header('Location: member_area.php');
    exit;
}

// ---- Aksi: logout ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'logout') {
    require_csrf();
    unset($_SESSION['member_id'], $_SESSION['member_nama'], $_SESSION['member_nomor']);
    header('Location: member_login.php');
    exit;
}

// Riwayat belanja member (transaksi terakhir, join nama produk).
$riwayat = [];
if ($member->getId() !== '') {
    $stmt = Database::connect()->prepare(
        'SELECT t.id, t.tanggal, t.total,
                COALESCE(GROUP_CONCAT(DISTINCT p.nama SEPARATOR ", "), "—") AS produk
         FROM transaksi t
         LEFT JOIN item_transaksi it ON it.transaksi_id = t.id
         LEFT JOIN produk p ON p.id = it.produk_id
         WHERE t.member_id = :member_id
         GROUP BY t.id
         ORDER BY t.tanggal DESC
         LIMIT 10'
    );
    $stmt->execute([':member_id' => (int) $member->getId()]);
    $riwayat = $stmt->fetchAll();
}

$katalog = Member::katalogHadiah();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Area Member - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .member-hero {
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-darker) 100%);
            color: #fff;
            border-radius: 1rem;
            padding: 1.5rem;
        }
        .poin-badge {
            font-size: 2rem;
            font-weight: 700;
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 820px;">

    <div class="member-hero mb-4">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <span class="badge text-bg-light mb-2"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($member->getNomorMember()) ?></span>
                <h1 class="h4 mb-1"><?= htmlspecialchars($member->getNama()) ?></h1>
                <span class="small opacity-75"><?= htmlspecialchars($member->getTelepon()) ?></span>
            </div>
            <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="logout">
                <button type="submit" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right me-1"></i>Keluar</button>
            </form>
        </div>
        <div class="mt-3 d-flex align-items-center gap-2">
            <i class="bi bi-stars fs-3"></i>
            <span>
                <span class="poin-badge"><?= number_format($member->getPoin(), 0, ',', '.') ?></span>
                <span class="opacity-75">poin</span>
            </span>
        </div>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Katalog penukaran poin -->
        <div class="col-lg-7">
            <div class="card pos-card h-100">
                <div class="card-header bg-white"><strong><i class="bi bi-gift me-1"></i>Tukar Badge / Hadiah</strong></div>
                <div class="card-body">
                    <?php if ($katalog === []): ?>
                        <div class="text-muted small">Belum ada hadiah yang bisa ditukar. Cek lagi nanti.</div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($katalog as $hadiah): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($hadiah['nama']) ?></div>
                                        <?php if (!empty($hadiah['deskripsi'])): ?>
                                            <div class="small text-muted"><?= htmlspecialchars($hadiah['deskripsi']) ?></div>
                                        <?php endif; ?>
                                        <span class="badge text-bg-warning mt-1"><?= (int) $hadiah['poin'] ?> poin</span>
                                    </div>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="aksi" value="tukar_poin">
                                        <input type="hidden" name="hadiah_id" value="<?= (int) $hadiah['id'] ?>">
                                        <button
                                            type="submit"
                                            class="btn btn-sm <?= $member->getPoin() >= (int) $hadiah['poin'] ? 'btn-primary' : 'btn-outline-secondary' ?>"
                                            <?= $member->getPoin() < (int) $hadiah['poin'] ? 'disabled' : '' ?>
                                        >
                                            <?= $member->getPoin() >= (int) $hadiah['poin'] ? 'Tukar' : 'Poin kurang' ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Riwayat belanja -->
        <div class="col-lg-5">
            <div class="card pos-card h-100">
                <div class="card-header bg-white"><strong><i class="bi bi-receipt me-1"></i>Riwayat Belanja</strong></div>
                <div class="card-body p-0">
                    <?php if ($riwayat === []): ?>
                        <div class="p-4 text-muted small">Belum ada transaksi member.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($riwayat as $r): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <span class="small"><?= date('d M Y H:i', strtotime($r['tanggal'])) ?></span>
                                        <span class="fw-semibold font-num">Rp <?= number_format((float) $r['total'], 0, ',', '.') ?></span>
                                    </div>
                                    <div class="small text-muted text-truncate"><?= htmlspecialchars($r['produk']) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-4 text-center">
        <a href="login.php" class="text-decoration-none">Login karyawan</a>
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/theme.js"></script>
</body>
</html>
