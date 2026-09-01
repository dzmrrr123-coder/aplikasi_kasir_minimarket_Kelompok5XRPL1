<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use DateTime;

class PromoEngine
{
    /**
     * Menerapkan promo aktif ke dalam keranjang.
     * Promo yang didukung saat ini: Buy X Get Y (diskon sekian persen).
     *
     * @param array $keranjang State keranjang saat ini
     * @return array Keranjang dengan tambahan `potongan_promo` pada item
     */
    public static function terapkanPromo(array $keranjang): array
    {
        $pdo = Database::connect();
        
        // Ambil semua promo aktif yang masih berlaku
        $stmt = $pdo->query(
            "SELECT * FROM promo 
             WHERE is_active = 1 
               AND (mulai IS NULL OR mulai <= NOW()) 
               AND (selesai IS NULL OR selesai >= NOW())"
        );
        $promos = $stmt->fetchAll();

        if (!$promos) {
            return $keranjang;
        }

        // Reset potongan promo di awal
        foreach ($keranjang as &$item) {
            $item['potongan_promo'] = 0.0;
        }
        unset($item);

        foreach ($promos as $promo) {
            if ($promo['tipe'] === 'buy_x_get_y') {
                $syaratId = (string) $promo['syarat_produk_id'];
                $rewardId = (string) $promo['reward_produk_id'];
                $syaratQty = (int) $promo['syarat_qty'];
                $rewardQty = (int) $promo['reward_qty'];
                $diskonPersen = (float) $promo['reward_diskon_persen'];

                // Cek apakah item syarat ada di keranjang dan memenuhi syarat qty
                if (isset($keranjang[$syaratId]) && $keranjang[$syaratId]['qty'] >= $syaratQty) {
                    // Cek apakah item reward ada di keranjang
                    if (isset($keranjang[$rewardId])) {
                        $qtySyaratTersedia = $keranjang[$syaratId]['qty'];
                        $qtyRewardTersedia = $keranjang[$rewardId]['qty'];

                        // Jika syarat dan reward adalah produk yang sama
                        if ($syaratId === $rewardId) {
                            // Hitung berapa kali kelipatan promo bisa diterapkan
                            // Misal beli 2 gratis 1. Butuh 3 barang (2 bayar, 1 gratis).
                            $kelompok = $syaratQty + $rewardQty;
                            $kaliPromo = (int) floor($qtySyaratTersedia / $kelompok);
                            
                            if ($kaliPromo > 0) {
                                $qtyDapatDiskon = $kaliPromo * $rewardQty;
                                $hargaSatuan = $keranjang[$rewardId]['harga']; // Sudah harga efektif
                                $nilaiDiskon = round(($hargaSatuan * $qtyDapatDiskon) * ($diskonPersen / 100), 2);
                                
                                $keranjang[$rewardId]['potongan_promo'] += $nilaiDiskon;
                            }
                        } else {
                            // Produk syarat dan reward berbeda
                            $kaliPromo = (int) floor($qtySyaratTersedia / $syaratQty);
                            
                            if ($kaliPromo > 0) {
                                $qtyDapatDiskon = min($kaliPromo * $rewardQty, $qtyRewardTersedia);
                                $hargaSatuan = $keranjang[$rewardId]['harga'];
                                $nilaiDiskon = round(($hargaSatuan * $qtyDapatDiskon) * ($diskonPersen / 100), 2);
                                
                                $keranjang[$rewardId]['potongan_promo'] += $nilaiDiskon;
                            }
                        }
                    }
                }
            }
        }

        // Kalkulasi ulang subtotal per item
        foreach ($keranjang as &$item) {
            $potonganManual = $item['potongan'] ?? 0.0;
            $potonganPromo = $item['potongan_promo'] ?? 0.0;
            $hargaTotalItem = round($item['harga'] * $item['qty'], 2);
            
            $sub = $hargaTotalItem - $potonganManual - $potonganPromo;
            $item['subtotal'] = max(0.0, $sub);
        }
        unset($item);

        return $keranjang;
    }
}
