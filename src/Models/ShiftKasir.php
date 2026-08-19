<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Shift kasir: buka kas (modal awal) -> transaksi sepanjang shift ->
 * tutup kas (rekonsiliasi kas fisik vs sistem).
 */
class ShiftKasir implements DataReporter
{
    private string $id = '';
    private int $kasirId = 0;
    private string $kasirNama = '';
    private string $dibukaPada = '';
    private float $modalAwal = 0.0;
    private ?string $ditutupPada = null;
    private float $totalSistem = 0.0;
    private ?float $totalKasFisik = null;
    private ?float $selisih = null;
    private string $catatan = '';
    private string $status = 'buka';

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['kasir_id'])) {
            $this->kasirId = (int) $data['kasir_id'];
        }
        if (isset($data['kasir_nama'])) {
            $this->kasirNama = (string) $data['kasir_nama'];
        }
        if (isset($data['dibuka_pada'])) {
            $this->dibukaPada = (string) $data['dibuka_pada'];
        }
        if (isset($data['modal_awal'])) {
            $this->modalAwal = (float) $data['modal_awal'];
        }
        if (isset($data['ditutup_pada'])) {
            $this->ditutupPada = $data['ditutup_pada'] !== null ? (string) $data['ditutup_pada'] : null;
        }
        if (isset($data['total_sistem'])) {
            $this->totalSistem = (float) $data['total_sistem'];
        }
        if (isset($data['total_kas_fisik'])) {
            $this->totalKasFisik = $data['total_kas_fisik'] !== null ? (float) $data['total_kas_fisik'] : null;
        }
        if (isset($data['selisih'])) {
            $this->selisih = $data['selisih'] !== null ? (float) $data['selisih'] : null;
        }
        if (isset($data['catatan'])) {
            $this->catatan = (string) $data['catatan'];
        }
        if (isset($data['status'])) {
            $this->status = (string) $data['status'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getKasirId(): int
    {
        return $this->kasirId;
    }

    public function getKasirNama(): string
    {
        return $this->kasirNama;
    }

    public function getDibukaPada(): string
    {
        return $this->dibukaPada;
    }

    public function getModalAwal(): float
    {
        return $this->modalAwal;
    }

    public function getDitutupPada(): ?string
    {
        return $this->ditutupPada;
    }

    public function getTotalSistem(): float
    {
        return $this->totalSistem;
    }

    public function getTotalKasFisik(): ?float
    {
        return $this->totalKasFisik;
    }

    public function getSelisih(): ?float
    {
        return $this->selisih;
    }

    public function getCatatan(): string
    {
        return $this->catatan;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Total penjualan (rekap) yang terjadi selama shift berlangsung:
     * dari waktu dibuka sampai sekarang (atau sampai ditutup).
     */
    public function totalPenjualanShift(): float
    {
        $sampai = $this->ditutupPada ?? date('Y-m-d H:i:s');

        $stmt = Database::connect()->prepare(
            'SELECT COALESCE(SUM(total), 0) FROM rekap_penjualan
             WHERE tanggal >= :mulai AND tanggal <= :sampai
               AND kasir_id = :kasir'
        );
        $stmt->execute([
            ':mulai'  => $this->dibukaPada,
            ':sampai' => $sampai,
            ':kasir'  => $this->kasirId,
        ]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Total penjualan TUNAI selama shift (dipakai rekonsiliasi kas fisik).
     * Uang non-tunai (QRIS/EDC) masuk rekening bank, bukan laci.
     */
    public function totalPenjualanTunai(): float
    {
        $sampai = $this->ditutupPada ?? date('Y-m-d H:i:s');

        $stmt = Database::connect()->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM rekap_penjualan
             WHERE tanggal >= :mulai AND tanggal <= :sampai
               AND kasir_id = :kasir AND metode = 'Tunai'"
        );
        $stmt->execute([
            ':mulai'  => $this->dibukaPada,
            ':sampai' => $sampai,
            ':kasir'  => $this->kasirId,
        ]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Buka kas baru untuk kasir.
     *
     * @throws \RuntimeException bila masih ada shift terbuka
     */
    public static function buka(int $kasirId, float $modalAwal): int
    {
        if ($modalAwal < 0) {
            throw new \RuntimeException('Modal awal tidak boleh negatif.');
        }

        if (self::shiftAktif($kasirId) !== null) {
            throw new \RuntimeException('Masih ada shift yang terbuka. Tutup kas dulu.');
        }

        $stmt = Database::connect()->prepare(
            'INSERT INTO shift_kasir (kasir_id, modal_awal, status)
             VALUES (:kasir, :modal, :status)'
        );
        $stmt->execute([
            ':kasir'  => $kasirId,
            ':modal'  => round($modalAwal, 2),
            ':status' => 'buka',
        ]);

        return (int) Database::connect()->lastInsertId();
    }

    /**
     * Riwayat transaksi kasir selama shift ini — dipakai saat tutup kas
     * supaya kasir bisa mencocokkan uang di laci dengan transaksi yang
     * tercatat (membantu menemukan selisih).
     *
     * @return array<int, array{tanggal: string, total: float, metode: string}>
     */
    public function riwayatTransaksi(): array
    {
        $sampai = $this->ditutupPada ?? date('Y-m-d H:i:s');

        $stmt = Database::connect()->prepare(
            'SELECT r.tanggal, r.total, r.metode
             FROM rekap_penjualan r
             WHERE r.tanggal >= :mulai AND r.tanggal <= :sampai
               AND r.kasir_id = :kasir
             ORDER BY r.tanggal ASC'
        );
        $stmt->execute([
            ':mulai'  => $this->dibukaPada,
            ':sampai' => $sampai,
            ':kasir'  => $this->kasirId,
        ]);

        return array_map(static function (array $row): array {
            return [
                'tanggal' => $row['tanggal'],
                'total'   => (float) $row['total'],
                'metode'  => (string) $row['metode'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Tutup kas: rekonsiliasi kas fisik vs total sistem.
     *
     * @throws \RuntimeException bila shift tidak ditemukan / sudah tutup
     */
    public function tutup(float $kasFisik, string $catatan = ''): void
    {
        if ($this->id === '') {
            throw new \RuntimeException('Shift tidak ditemukan.');
        }
        if ($this->status !== 'buka') {
            throw new \RuntimeException('Shift sudah ditutup.');
        }
        if ($kasFisik < 0) {
            throw new \RuntimeException('Kas fisik tidak boleh negatif.');
        }

        $totalTunai = $this->totalPenjualanTunai();
        $totalNonTunai = round($this->totalPenjualanShift() - $totalTunai, 2);

        // Uang yang seharusnya di laci = modal awal + penjualan TUNAI saja
        // (non-tunai masuk rekening bank, bukan laci kasir).
        $harusnya = round($this->modalAwal + $totalTunai, 2);
        $selisih = round($kasFisik - $harusnya, 2);

        $stmt = Database::connect()->prepare(
            'UPDATE shift_kasir
             SET ditutup_pada = :ditutup, total_sistem = :total_sistem,
                 total_kas_fisik = :kas_fisik, selisih = :selisih,
                 catatan = :catatan, status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            ':ditutup'      => date('Y-m-d H:i:s'),
            ':total_sistem' => $harusnya,
            ':kas_fisik'    => round($kasFisik, 2),
            ':selisih'      => $selisih,
            ':catatan'      => $catatan !== '' ? $catatan : null,
            ':status'       => 'tutup',
            ':id'           => (int) $this->id,
        ]);

        $this->status = 'tutup';
        $this->ditutupPada = date('Y-m-d H:i:s');
        $this->totalSistem = $harusnya;
        $this->totalKasFisik = round($kasFisik, 2);
        $this->selisih = $selisih;
        $this->catatan = $catatan;
    }

    /** Shift yang sedang terbuka milik kasir tertentu (null bila tidak ada). */
    public static function shiftAktif(int $kasirId): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT s.id, s.kasir_id, u.nama AS kasir_nama, s.dibuka_pada, s.modal_awal,
                    s.ditutup_pada, s.total_sistem, s.total_kas_fisik, s.selisih, s.catatan, s.status
             FROM shift_kasir s
             JOIN users u ON u.id = s.kasir_id
             WHERE s.kasir_id = :kasir AND s.status = :status
             ORDER BY s.id DESC
             LIMIT 1'
        );
        $stmt->execute([':kasir' => $kasirId, ':status' => 'buka']);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT s.id, s.kasir_id, u.nama AS kasir_nama, s.dibuka_pada, s.modal_awal,
                    s.ditutup_pada, s.total_sistem, s.total_kas_fisik, s.selisih, s.catatan, s.status
             FROM shift_kasir s
             JOIN users u ON u.id = s.kasir_id
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    // ------------------------------------------------------------
    // DataReporter — untuk DataTables
    // ------------------------------------------------------------

    /**
     * Data tabel riwayat shift dengan pencarian & pagination.
     *
     * @param array<string, mixed> $params search/start/length
     */
    public function getDataTabel(array $params = []): array
    {
        $cari = trim((string) ($params['search'] ?? ''));
        $start = max(0, (int) ($params['start'] ?? 0));
        $length = max(1, (int) ($params['length'] ?? 10));

        $where = '';
        $bind = [];

        if ($cari !== '') {
            $where = 'WHERE u.nama LIKE :cari_nama OR s.status LIKE :cari_status';
            $bind[':cari_nama'] = '%' . $cari . '%';
            $bind[':cari_status'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM shift_kasir')->fetchColumn();

        $stmtFiltered = $pdo->prepare(
            'SELECT COUNT(*) FROM shift_kasir s JOIN users u ON u.id = s.kasir_id ' . $where
        );
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT s.id, s.kasir_id, u.nama AS kasir_nama, s.dibuka_pada, s.modal_awal,
                    s.ditutup_pada, s.total_sistem, s.total_kas_fisik, s.selisih, s.catatan, s.status
             FROM shift_kasir s
             JOIN users u ON u.id = s.kasir_id ' . $where . '
             ORDER BY s.id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($bind as $kunci => $nilai) {
            $stmt->bindValue($kunci, $nilai);
        }
        $stmt->bindValue(':limit', $length, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $r): array {
            return [
                'id'              => (int) $r['id'],
                'kasir_id'        => (int) $r['kasir_id'],
                'kasir_nama'      => $r['kasir_nama'],
                'dibuka_pada'     => $r['dibuka_pada'],
                'modal_awal'      => (float) $r['modal_awal'],
                'ditutup_pada'    => $r['ditutup_pada'] ?? null,
                'total_sistem'    => (float) $r['total_sistem'],
                'total_kas_fisik' => $r['total_kas_fisik'] !== null ? (float) $r['total_kas_fisik'] : null,
                'selisih'         => $r['selisih'] !== null ? (float) $r['selisih'] : null,
                'catatan'         => $r['catatan'] ?? '',
                'status'          => $r['status'],
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }

    /** @param array<string, mixed> $params */
    public function getAgregasiGrafik(array $params = []): array
    {
        return ['labels' => [], 'series' => ['label' => '', 'data' => []]];
    }
}
