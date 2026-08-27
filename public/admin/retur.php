<?php

declare(strict_types=1);

/**
 * Halaman admin: retur barang ke supplier.
 *
 * Fitur:
 * - Form input retur barang (pilih produk -> cari supplier asal dari pembelian -> qty -> alasan)
 * - Proses retur via ReturBarang::prosesRetur()
 * - Tabel riwayat retur barang
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Database\Database;
use App\Models\Produk;
use App\Models\ReturBarang;
use App\Models\Supplier;

SessionGuard::requireLogin();
SessionGuard::requireRole('admin');

$nama      = $_SESSION['nama'] ?? 'Admin';
$pageTitle = 'Retur Barang';
$aktif     = 'retur';

// ---- Routing aksi POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SessionGuard::validateCsrfToken($_POST['csrf_token'] ?? null)
        || (function () { header('Location: retur.php'); exit; })();

    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'proses_retur') {
        $produkId    = (int) ($_POST['produk_id'] ?? 0);
        $supplierId  = (int) ($_POST['supplier_id'] ?? 0);
        $pembelianId = (int) ($_POST['pembelian_id'] ?? 0);
        $qty         = (int) ($_POST['qty'] ?? 0);
        $alasan      = trim((string) ($_POST['alasan'] ?? ''));

        $produk = Produk::cari($produkId);

        if ($produk === null) {
            SessionGuard::setFlash('error', 'Produk tidak ditemukan.');
        } else {
            $retur = new ReturBarang([
                'produk_id'    => $produkId,
                'supplier_id'  => $supplierId,
                'pembelian_id' => $pembelianId,
                'qty'          => $qty,
                'alasan'       => $alasan,
            ]);
            $retur->setProduk($produk);

            try {
                $retur->prosesRetur();
                SessionGuard::setFlash('success', sprintf(
                    'Retur %d unit "%s" ke "%s" berhasil dicatat.',
                    $qty,
                    $produk->getNama(),
                    $retur->getSupplier()->getNama()
                ));
            } catch (\Throwable $e) {
                SessionGuard::setFlash('error', $e->getMessage());
            }
        }
    }

    header('Location: retur.php');
    exit;
}

$produkSemua = Produk::semua();

// Ambil riwayat retur
$pdo = Database::connect();
$stmt = $pdo->query(
    'SELECT r.id, r.tanggal, r.qty, r.alasan, p.nama AS produk_nama, s.nama AS supplier_nama
       FROM retur_barang r
       JOIN produk p ON p.id = r.produk_id
       JOIN supplier s ON s.id = r.supplier_id
   ORDER BY r.tanggal DESC
      LIMIT 100'
);
$riwayatRetur = $stmt ? $stmt->fetchAll() : [];

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">Retur Barang</h1>
        <p class="text-muted small mb-0">Pengembalian barang rusak atau kadaluarsa ke supplier</p>
    </div>
</div>

<div class="row g-4">
    <!-- Form Input Retur -->
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h5 class="card-title h6 mb-0"><i class="bi bi-arrow-counterclockwise me-1"></i>Catat Retur Baru</h5>
            </div>
            <div class="card-body">
                <?php if (empty($produkSemua)): ?>
                    <div class="text-muted small">Belum ada produk aktif.</div>
                <?php else: ?>
                    <form method="post" id="form-retur">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="aksi" value="proses_retur">
                        <input type="hidden" name="pembelian_id" id="pembelian-id" value="0">

                        <div class="mb-3">
                            <label for="produk-retur" class="form-label">Pilih Produk <span class="text-danger">*</span></label>
                            <select id="produk-retur" name="produk_id" class="form-select" required>
                                <option value="">-- Pilih Produk --</option>
                                <?php foreach ($produkSemua as $p): ?>
                                    <option value="<?= (int) $p->getId() ?>" data-stok="<?= $p->getStok() ?>">
                                        <?= htmlspecialchars($p->getNama()) ?> (Stok: <?= $p->getStok() ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="supplier-retur" class="form-label">Supplier Asal <span class="text-danger">*</span></label>
                            <select id="supplier-retur" name="supplier_id" class="form-select" required disabled>
                                <option value="">Pilih produk terlebih dahulu...</option>
                            </select>
                            <div class="form-text small" id="info-pembelian"></div>
                        </div>

                        <div class="mb-3">
                            <label for="qty-retur" class="form-label">Jumlah Retur (Qty) <span class="text-danger">*</span></label>
                            <input type="number" min="1" id="qty-retur" name="qty" class="form-control" value="1" required>
                        </div>

                        <div class="mb-3">
                            <label for="alasan-retur" class="form-label">Alasan Retur <span class="text-danger">*</span></label>
                            <input type="text" id="alasan-retur" name="alasan" class="form-control"
                                   placeholder="Contoh: Kemasan rusak, kadaluarsa" required>
                        </div>

                        <button type="submit" class="btn btn-warning w-100" id="btn-submit-retur" disabled>
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Proses Retur
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Riwayat Retur -->
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="card-title h6 mb-0"><i class="bi bi-clock-history me-1"></i>Riwayat Retur</h5>
                <span class="badge bg-secondary"><?= count($riwayatRetur) ?> data</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>Produk</th>
                                <th>Supplier</th>
                                <th class="text-center">Qty</th>
                                <th>Alasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($riwayatRetur)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Belum ada riwayat retur.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($riwayatRetur as $r): ?>
                                    <tr>
                                        <td class="small text-muted"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($r['tanggal']))) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($r['produk_nama']) ?></td>
                                        <td><?= htmlspecialchars($r['supplier_nama']) ?></td>
                                        <td class="text-center font-num"><span class="badge bg-danger"><?= (int) $r['qty'] ?></span></td>
                                        <td class="small text-muted"><?= htmlspecialchars($r['alasan'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const produkSel   = document.getElementById('produk-retur');
    const supplierSel = document.getElementById('supplier-retur');
    const infoPem     = document.getElementById('info-pembelian');
    const pembelianId = document.getElementById('pembelian-id');
    const btnSubmit   = document.getElementById('btn-submit-retur');

    if (!produkSel) return;

    produkSel.addEventListener('change', function () {
        const pId = this.value;
        if (!pId) {
            supplierSel.innerHTML = '<option value="">Pilih produk terlebih dahulu...</option>';
            supplierSel.disabled = true;
            infoPem.textContent = '';
            pembelianId.value = '0';
            btnSubmit.disabled = true;
            return;
        }

        supplierSel.innerHTML = '<option value="">Memuat data supplier asal...</option>';
        supplierSel.disabled = true;
        infoPem.textContent = '';
        btnSubmit.disabled = true;

        fetch('<?= $baseUrl ?>/api.php?aksi=retur.supplier_asal&produk_id=' + encodeURIComponent(pId))
            .then(res => res.json())
            .then(res => {
                if (!res.sukses || !res.supplier) {
                    supplierSel.innerHTML = '<option value="">(Tidak ditemukan riwayat pembelian)</option>';
                    supplierSel.disabled = true;
                    infoPem.textContent = res.pesan || 'Produk belum pernah memiliki riwayat pembelian dari supplier.';
                    infoPem.className = 'form-text text-danger small';
                    pembelianId.value = '0';
                    btnSubmit.disabled = true;
                    return;
                }

                supplierSel.innerHTML = `<option value="${res.supplier.id}" selected>${res.supplier.nama}</option>`;
                supplierSel.disabled = false;
                pembelianId.value = res.pembelian_id || '0';
                infoPem.textContent = `Supplier asal dari pembelian #${res.pembelian_id} (${res.supplier.nama})`;
                infoPem.className = 'form-text text-success small';
                btnSubmit.disabled = false;
            })
            .catch(err => {
                supplierSel.innerHTML = '<option value="">Gagal memuat supplier</option>';
                supplierSel.disabled = true;
                btnSubmit.disabled = true;
            });
    });
});
</script>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
