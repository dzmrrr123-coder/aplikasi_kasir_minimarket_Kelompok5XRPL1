<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class LabelPrinter
{
    /**
     * Generate teks label harga untuk satu produk (format ESC/POS).
     * Label berisi: nama produk, barcode, harga, satuan.
     *
     * @return string Teks label siap cetak
     */
    public static function generateLabel(Produk $produk, int $jumlah = 1): string
    {
        $lines = [];

        // Border atas
        $lines[] = str_repeat('-', 32);

        // Nama produk (max 32 chars, wrap jika panjang)
        $nama = $produk->getNama();
        if (mb_strlen($nama) > 32) {
            $lines[] = mb_substr($nama, 0, 32);
            $lines[] = mb_substr($nama, 32, 32);
        } else {
            $lines[] = $nama;
        }

        // Kategori
        $kategoriNama = $produk->getKategori()->getNama();
        if ($kategoriNama !== '') {
            $lines[] = '(' . $kategoriNama . ')';
        }

        // Harga
        $harga = $produk->getSatuan() === 'gram'
            ? $produk->getHargaPerGram()
            : $produk->getHarga();

        $hargaText = 'Rp ' . number_format($harga, 0, ',', '.');

        if ($produk->getSatuan() === 'gram') {
            $hargaText .= '/gr';
        }

        // Center-aligned harga
        $hargaLine = str_repeat(' ', max(0, (int) ((32 - mb_strlen($hargaText)) / 2))) . $hargaText;
        $lines[] = $hargaLine;

        // Barcode (jika ada)
        if ($produk->getBarcode() !== '') {
            $lines[] = '[' . $produk->getBarcode() . ']';
        }

        // Satuan
        $lines[] = 'Satuan: ' . ($produk->getSatuan() === 'gram' ? 'gram' : 'pcs');

        // Stok info
        $lines[] = 'Stok: ' . $produk->getStok();

        // Border bawah
        $lines[] = str_repeat('-', 32);

        // Jumlah label
        if ($jumlah > 1) {
            $lines[] = 'x' . $jumlah;
        }

        return implode("\n", $lines);
    }

    /**
     * Generate batch labels untuk beberapa produk.
     *
     * @param list<array{produk_id: int, jumlah: int}> $items
     * @return list<array{produk_nama: string, label: string, jumlah: int}>
     */
    public static function generateBatchLabels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            $produkId = (int) $item['produk_id'];
            $jumlah = max(1, (int) $item['jumlah']);

            $produk = Produk::cari($produkId);
            if ($produk === null) {
                continue;
            }

            $label = self::generateLabel($produk, $jumlah);

            // Duplikat label sesuai jumlah
            for ($i = 0; $i < $jumlah; $i++) {
                $labels[] = [
                    'produk_nama' => $produk->getNama(),
                    'label'       => self::generateLabel($produk),
                    'jumlah'      => 1,
                ];
            }
        }

        return $labels;
    }

    /**
     * Generate ESC/POS commands untuk cetak label.
     * Menggunakan Font B (kecil) untuk label yang kompak.
     *
     * @param list<array{label: string}> $labels
     * @return string Raw ESC/POS bytes sebagai string
     */
    public static function generateEscPosCommands(array $labels): string
    {
        $cmds = [];

        // Initialize printer
        $cmds[] = "\x1b\x40"; // ESC @

        foreach ($labels as $i => $item) {
            $label = $item['label'];
            $baris = explode("\n", $label);

            // Font B (kecil) untuk label
            $cmds[] = "\x1b\x4d\x01"; // ESC M 1 (Font B)

            foreach ($baris as $line) {
                $cmds[] = $line;
                $cmds[] = "\n";
            }

            // Feed & cut antar label (kecuali label terakhir)
            if ($i < count($labels) - 1) {
                $cmds[] = "\x1b\x64\x02"; // ESC d 2 (feed 2 baris)
                $cmds[] = "\x1d\x56\x42\x00"; // GS V B 0 (partial cut)
            }
        }

        // Final: feed & cut
        $cmds[] = "\x1b\x64\x04"; // ESC d 4
        $cmds[] = "\x1d\x56\x42\x00"; // GS V B 0 (full cut)

        return implode('', $cmds);
    }

    /**
     * Generate HTML label untuk preview di browser / cetak via browser print.
     *
     * @param list<array{label: string, produk_nama: string}> $labels
     * @return string HTML
     */
    public static function generateHtmlLabels(array $labels): string
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>Cetak Label Produk</title>';
        $html .= '<style>';
        $html .= 'body { font-family: monospace; font-size: 11px; margin: 0; padding: 10px; }';
        $html .= '.label { border: 1px dashed #999; padding: 8px; margin-bottom: 10px; width: 280px; page-break-inside: avoid; }';
        $html .= '.label-name { font-weight: bold; font-size: 13px; margin-bottom: 2px; }';
        $html .= '.label-category { color: #666; font-size: 10px; }';
        $html .= '.label-price { font-size: 18px; font-weight: bold; text-align: center; margin: 6px 0; color: #0d9488; }';
        $html .= '.label-barcode { text-align: center; font-size: 10px; color: #333; }';
        $html .= '.label-meta { font-size: 9px; color: #888; margin-top: 2px; }';
        $html .= '@media print { .label { border: 1px solid #000; } }';
        $html .= '</style></head><body>';

        foreach ($labels as $item) {
            $label = $item['label'];
            $lines = explode("\n", $label);

            $html .= '<div class="label">';
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || preg_match('/^-+$/', $trimmed)) {
                    continue; // skip empty & border lines
                }

                if (preg_match('/^Rp\\s/i', $trimmed)) {
                    $html .= '<div class="label-price">' . htmlspecialchars($trimmed) . '</div>';
                } elseif (preg_match('/^\\[.*\\]$/', $trimmed)) {
                    $html .= '<div class="label-barcode">' . htmlspecialchars($trimmed) . '</div>';
                } elseif (preg_match('/^Satuan:/i', $trimmed) || preg_match('/^Stok:/i', $trimmed)) {
                    $html .= '<div class="label-meta">' . htmlspecialchars($trimmed) . '</div>';
                } elseif (preg_match('/^\\(.*\\)$/', $trimmed)) {
                    $html .= '<div class="label-category">' . htmlspecialchars($trimmed) . '</div>';
                } else {
                    $html .= '<div class="label-name">' . htmlspecialchars($trimmed) . '</div>';
                }
            }
            $html .= '</div>';
        }

        $html .= '</body></html>';

        return $html;
    }
}
