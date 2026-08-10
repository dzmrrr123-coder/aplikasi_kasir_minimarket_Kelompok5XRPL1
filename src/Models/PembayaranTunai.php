<?php
// src/Models/PembayaranTunai.php
// Pembayaran tunai. Di scope ini prosesnya disimulasikan selalu berhasil
// (validasi nominal riil, mis. uang kembalian, di luar scope).

class PembayaranTunai extends Pembayaran
{
    // Simulasi: pembayaran tunai selalu berhasil.
    public function proses(): bool
    {
        return true;
    }
}
