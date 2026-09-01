<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Database backup utility — bisa dipanggil dari admin panel atau cron.
 *
 * Backup disimpan ke database/backups/ dengan format:
 *   kasir_minimarket_YYYYMMDD_HHMMSS.sql.gz
 */
class Backup
{
    private string $backupDir;
    private int $retentionDays;

    public function __construct(?string $backupDir = null, int $retentionDays = 30)
    {
        $this->backupDir = $backupDir ?? dirname(__DIR__, 2) . '/database/backups';
        $this->retentionDays = $retentionDays;

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Jalankan backup database.
     *
     * @return array{success: bool, file?: string, size?: string, error?: string}
     */
    public function run(): array
    {
        $pdo = Database::connect();
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $date = date('Y-m-d_H-i-s');
        $backupFile = $this->backupDir . "/kasir_minimarket_{$date}.sql";

        try {
            $this->exportDatabase($dbName, $backupFile);
            $gzFile = $this->compress($backupFile);
            $this->cleanup();

            $size = filesize($gzFile);
            $sizeStr = $this->formatSize($size);

            return [
                'success' => true,
                'file'    => basename($gzFile),
                'size'    => $sizeStr,
            ];
        } catch (\Throwable $e) {
            // Cleanup partial file
            if (is_file($backupFile)) {
                @unlink($backupFile);
            }
            $gzFile = $backupFile . '.gz';
            if (is_file($gzFile)) {
                @unlink($gzFile);
            }

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Daftar file backup yang tersedia.
     *
     * @return list<array{file: string, size: string, date: string}>
     */
    public function list(): array
    {
        $files = glob($this->backupDir . '/kasir_minimarket_*.sql.gz');
        $result = [];

        if ($files === false) {
            return [];
        }

        // Sort descending (newest first)
        rsort($files);

        foreach ($files as $file) {
            $basename = basename($file);
            $size = filesize($file);
            $mtime = filemtime($file);

            $result[] = [
                'file' => $basename,
                'size' => $this->formatSize($size),
                'date' => date('d M Y H:i', $mtime),
            ];
        }

        return $result;
    }

    /**
     * Download file backup.
     */
    public function download(string $filename): void
    {
        // Security: only allow kasir_minimarket_*.sql.gz
        if (!preg_match('/^kasir_minimarket_\d{4}\d{2}\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$/', $filename)) {
            http_response_code(400);
            echo 'Invalid filename';
            exit;
        }

        $filePath = $this->backupDir . '/' . $filename;

        if (!is_file($filePath)) {
            http_response_code(404);
            echo 'Backup not found';
            exit;
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache');
        readfile($filePath);
        exit;
    }

    /**
     * Hapus file backup.
     */
    public function delete(string $filename): bool
    {
        if (!preg_match('/^kasir_minimarket_\d{4}\d{2}\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$/', $filename)) {
            return false;
        }

        $filePath = $this->backupDir . '/' . $filename;

        if (is_file($filePath)) {
            return unlink($filePath);
        }

        return false;
    }

    /**
     * Export database using mysqldump or PDO.
     */
    private function exportDatabase(string $dbName, string $outputFile): void
    {
        // Try mysqldump first (faster, more complete)
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s --single-transaction --routines --triggers --events %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            $pass !== '' ? '--password=' . escapeshellarg($pass) : '',
            escapeshellarg($dbName)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode === 0 && !empty($output)) {
            file_put_contents($outputFile, implode("\n", $output));
            return;
        }

        // Fallback: PDO dump (simpler, no mysqldump needed)
        $this->pdoExport($dbName, $outputFile);
    }

    /**
     * Fallback export using PDO — tulis CREATE TABLE + INSERT.
     */
    private function pdoExport(string $dbName, string $outputFile): void
    {
        $pdo = Database::connect();
        $tables = [];

        $rows = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $table) {
            $tables[] = $table;
        }

        $handle = fopen($outputFile, 'w');
        fwrite($handle, "-- Kasir Minimarket Database Backup\n");
        fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: {$dbName}\n\n");

        foreach ($tables as $table) {
            // CREATE TABLE
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_NUM);
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, $createRow[1] . ";\n\n");

            // INSERT rows
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $columns) . '`';

                foreach ($rows as $row) {
                    $values = array_map(function ($v) use ($pdo) {
                        return $v === null ? 'NULL' : $pdo->quote((string) $v);
                    }, $row);

                    fwrite($handle, "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $values) . ");\n");
                }
                fwrite($handle, "\n");
            }
        }

        fclose($handle);
    }

    /**
     * Kompres file SQL ke .gz.
     */
    private function compress(string $sqlFile): string
    {
        $gzFile = $sqlFile . '.gz';

        $source = fopen($sqlFile, 'rb');
        $dest = gzopen($gzFile, 'wb9');

        if ($source === false || $dest === false) {
            throw new \RuntimeException('Gagal membuat file kompresi');
        }

        while (!feof($source)) {
            $chunk = fread($source, 8192);
            gzwrite($dest, $chunk);
        }

        fclose($source);
        gzclose($dest);
        unlink($sqlFile);

        return $gzFile;
    }

    /**
     * Hapus backup yang sudah melewati retention period.
     */
    private function cleanup(): void
    {
        $files = glob($this->backupDir . '/kasir_minimarket_*.sql.gz');
        if ($files === false) {
            return;
        }

        $cutoff = time() - ($this->retentionDays * 86400);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1) . ' ' . $units[$i];
    }
}
