# Notifikasi WhatsApp tiap Transaksi — panduan integrasi n8n

Integrasi ini mengirim notifikasi **WhatsApp** ke nomor yang ditentukan setiap
kali sebuah transaksi penjualan berhasil dicatat. Aplikasi PHP **hanya**
menulis & mengirim payload JSON ke **webhook n8n**; bagaimana n8n meneruskan ke
WhatsApp sepenuhnya dikelola oleh workflow n8n (lihat di bawah).

---

## Alur singkat (lihat `docs/` atau `plan` utama untuk detail)

```
Transaksi selesai → Transaksi::prosesPembayaran()
  └─ notify() [PRE-commit]
       ├─ Struk (JSON struk)
       ├─ LaporanPenjualan (INSERT rekap_penjualan)
       └─ NotifikasiWhatsApp (INSERT notifikasi_queue = PENDING)  ← atomic
  └─ commit
  └─ aksiBayar(): NotifikasiAntrian::proses() → POST ke n8n webhook   ← POST-commit
```

Keputuhan:
- `wa_webhook_url` — Production Webhook URL dari trigger **Webhook (POST)** di
  workflow n8n. (diisi di halaman **Pengaturan → Notifikasi WhatsApp**)
- `wa_tujuan_nomor` — nomor WA tujuan, format internasional tanpa `+`
  (cth: `6282123456789`).

> **Fitur OFF** bila `wa_webhook_url` kosong → tidak ada baris queue, tidak ada
> HTTP, tidak ada error di UI kasir.

---

## Format payload yang dikirim ke n8n (JSON, `Content-Type: application/json`)

```json
{
  "no_transaksi": "25",
  "tanggal": "22-08-2026 14:30",
  "kasir": "Kasir Demo",
  "member": "Siti Rahma",
  "member_nomor": "MEM-339302",
  "tujuan": "6282123456789",
  "metode": "Tunai",
  "subtotal": 15000,
  "diskon": 0,
  "pajak": 1650,
  "total": 111000,
  "dibayar": 120000,
  "kembalian": 9000,
  "items": [
    { "nama": "Indomie Goreng", "qty": 2, "harga": 3500, "subtotal": 7000 }
  ]
}
```

- Semua angka tanpa pemisah ribuan (n8n yang format Rupiah).
- `member` / `member_nomor` kosong bila bukan transaksi member.
- `tujuan` adalah nomor WA yang dituju (dipakai node WA `to`).

---

## Workflow n8n (resep langkah-demi-langkah)

Buka n8n canvas, bangun:

1. **Webhook** (trigger)
   - HTTP Method: `POST`
   - Salin **Production Webhook URL** → tempel ke `wa_webhook_url` (Pengaturan).
   - (Development) biarkan "Test URL" aktif untuk uji di editor.

2. (opsional) **IF** — `length({{ $json.tujuan }}) > 0`
   - Jika kosong → hentikan (atau log "no recipient").

3. **WhatsApp** node — pilih connector:

   | Connector | Kapan pakai | Setup singkat |
   |-----------|-------------|---------------|
   | **WAHA / GOWA** (self-host) | Dev/lokal (Laragon) — paling gampang | Jalanin container `devlikebro/wa-ha` (atau `go-wa`) yang dengarkan `0.0.0.0:3333`, scan QR sekali. Node WAHA: `to = {{ $json.tujuan }}`, `text = ...` |
   | **Twilio WhatsApp** | Produksi / sandbox |Pakai sandbox Twilio WhatsApp; `to = {{ $json.tujuan }}`, `body = ...`. Perlu nomor sandbox. |
   | **Meta WhatsApp Business Cloud API** | Produksi skala | butuh `phone_number_id`, `access_token` (WABA), template disetujui. Paling rumit. |

   Saya rekomendasikan **WAHA** untuk dev lokal.

4. **Set / Function** (bangun teks pesan) — contoh:
   ```
   🧾 Transaksi #{{ $json.no_transaksi }} — Minimarket Plaza
   🕒 {{ $json.tanggal }}
   👤 Kasir: {{ $json.kasir }}
   👥 Member: {{ $json.member }}
   💰 Total: {{ $json.total }} ({{ $json.metode }})
   ✅ Dibayar: {{ $json.dibayar }} | Kembalian: {{ $json.kembalian }}
   ```
   (Lengkapi daftar item via *Item Lists / Summation* bila ingin.)

5. **WhatsApp** node (lengkapkan `to` + `text`) → Save & Activate workflow.

---

## Kolom tabel `notifikasi_queue` (referensi operasional)

| kolom | keterangan |
|-------|-----------|
| `transaksi_id` | rujukan ke `transaksi.id` (FK, ON DELETE CASCADE) |
| `webhook_url` | snapshot URL webhook n8n pada saat transaksi |
| `nomor_tujuan` | snapshot nomor WA tujuan |
| `payload` | JSON di atas |
| `status` | `pending` → `sent` / `failed` |
| `upaya` | hitungan upaya kirim (retry hingga 5×) |
| `error` | pesan error bila gagal |
| `dibuat_pada` / `dikirim_pada` | timestamp |

### Melihat antrean
```sql
SELECT id, transaksi_id, status, upaya, error, dibuat_pada, dikirim_pada
FROM notifikasi_queue ORDER BY id DESC;
```

---

## Worker & retry

- Setiap transaksi berhasil **langsung** mencoba flush pending ke n8n lewat
  `NotifikasiAntrian::proses()` yang dipanggil di `aksiBayar()` (best-effort,
  timeout 3s/paket, tidak pernah throw ke kasir).
- Bila n8n down / error HTTP → baris jadi `status='failed'`, `upaya` naik.
- Jalankan worker untuk retry (supaya tidak menumpuk):
  ```
  php database/notif-worker.php            # loop
  php database/notif-worker.php --once     # satu pass (Task Scheduler / cron)
  ```
  Baris dengan `upaya >= 5` dilewati (tidak retry tak terbatas).

---

## Verifikasi manual

1. `php database/seed.php` → login `kasir/kasir123`.
2. Isi `wa_webhook_url` (Production URL n8n) + `wa_tujuan_nomor` di Pengaturan.
3. Buka Kas → jual 1–2 barang → proses pembayaran.
4. `SELECT * FROM notifikasi_queue WHERE transaksi_id = (SELECT MAX(id) FROM transaksi);`
   → semula `pending` → sebelum lama jadi `sent`.
5. Cek log n8n → WhatsApp sampai ke nomor tujuan.
6. Kosongkan `wa_webhook_url` → ulangi jual → tidak ada baris queue, tidak error.

## Troubleshooting cepat

- **WA tidak sampai padahal status `sent`:** cek nomor `tujuan` formatnya (62…, tanpa `+`, tanpa spasi). Pastikan konek WhatsApp Business pada n8n terhubung & QR/sesi terakhir login.
- **Baris stuck `pending`/`failed`:** jalankan `php database/notif-worker.php --once`; cek kolom `error` untuk kode HTTP spesifik (mis. 401/404 → URL/token salah, 429 → rate limit WA).
- **Transaksi lambat:** flush pakai timeout 3s; bila n8n lokal, biasanya < 200ms. Kalau n8n external lambat, turunkan jumlah `batch` di `aksiBayar` (mis. `proses(5, 5, 2000)`) atau pindahkan flush ke worker-asinkron.
