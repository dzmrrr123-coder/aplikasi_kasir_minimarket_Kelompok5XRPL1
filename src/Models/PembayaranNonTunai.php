<?php
// src/Models/PembayaranNonTunai.php
// Pembayaran non-tunai (kartu/QRIS/dsb). Di scope ini prosesnya disimulasikan
// selalu berhasil — tidak ada integrasi payment gateway nyata.

class PembayaranNonTunai extends Pembayaran
{
    // Simulasi: pembayaran non-tunai selalu berhasil.
    public function proses(): bool
    {
        return true;
    }
}
