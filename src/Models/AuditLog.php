<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Audit log: mencatat perubahan data penting (harga produk, void item,
 * reset password, dll.) untuk akuntabilitas.
 */
class AuditLog implements DataReporter
{
    private string $id = '';
    private int $userId = 0;
    private string $userNama = '';
    private string $aksi = '';
    private string $tabel = '';
    private int $recordId = 0;
    private string $detail = '';
    private string $dicatatPada = '';

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['user_id'])) {
            $this->userId = (int) $data['user_id'];
        }
        if (isset($data['user_nama'])) {
            $this->userNama = (string) $data['user_nama'];
        }
        if (isset($data['aksi'])) {
            $this->aksi = (string) $data['aksi'];
        }
        if (isset($data['tabel'])) {
            $this->tabel = (string) $data['tabel'];
        }
        if (isset($data['record_id'])) {
            $this->recordId = (int) $data['record_id'];
        }
        if (isset($data['detail'])) {
            $this->detail = (string) $data['detail'];
        }
        if (isset($data['dicatat_pada'])) {
            $this->dicatatPada = (string) $data['dicatat_pada'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUserNama(): string
    {
        return $this->userNama;
    }

    public function getAksi(): string
    {
        return $this->aksi;
    }

    public function getTabel(): string
    {
        return $this->tabel;
    }

    public function getRecordId(): int
    {
        return $this->recordId;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getDicatatPada(): string
    {
        return $this->dicatatPada;
    }

    /**
     * Simpan satu entri audit.
     *
     * @param array<string, mixed> $detail data tambahan (diubah jadi JSON)
     */
    public static function catat(
        string $aksi,
        string $tabel,
        int $recordId = 0,
        array $detail = []
    ): void {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $stmt = Database::connect()->prepare(
            'INSERT INTO audit_log (user_id, aksi, tabel, record_id, detail)
             VALUES (:user_id, :aksi, :tabel, :record_id, :detail)'
        );
        $stmt->execute([
            ':user_id'    => $userId > 0 ? $userId : null,
            ':aksi'       => $aksi,
            ':tabel'      => $tabel,
            ':record_id'  => $recordId > 0 ? $recordId : null,
            ':detail'     => $detail !== [] ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ------------------------------------------------------------
    // DataReporter — untuk DataTables
    // ------------------------------------------------------------

    /**
     * Data tabel riwayat audit dengan pencarian & pagination.
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
            $where = 'WHERE a.aksi LIKE :cari OR a.tabel LIKE :cari OR u.nama LIKE :cari';
            $bind[':cari'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        $stmtFiltered = $pdo->prepare(
            'SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ' . $where
        );
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT a.id, a.user_id, u.nama AS user_nama, a.aksi, a.tabel, a.record_id,
                    a.detail, a.dicatat_pada
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id ' . $where . '
             ORDER BY a.id DESC
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
                'id'           => (int) $r['id'],
                'user_id'      => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                'user_nama'    => $r['user_nama'] ?? 'Sistem',
                'aksi'         => $r['aksi'],
                'tabel'        => $r['tabel'],
                'record_id'    => $r['record_id'] !== null ? (int) $r['record_id'] : null,
                'detail'       => $r['detail'] ?? '',
                'dicatat_pada' => $r['dicatat_pada'],
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
