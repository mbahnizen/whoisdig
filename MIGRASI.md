# Migrasi WHOISDIG dari Cloud Run

**Dibuat:** 24 Agustus 2026
**Alasan:** Billing GCP mati — seluruh akun billing Nizen berstatus tertutup (trial habis),
sehingga service Cloud Run masih ada dan `Ready=True` tapi ditolak melayani (**503**).

Kabar baiknya: **tidak ada kode yang perlu diubah.** `Dockerfile` dan `docker-entrypoint.sh`
sudah membaca `$PORT` saat runtime, jadi image ini jalan di host Docker mana pun.

---

## Syarat Runtime (cek ini sebelum memilih host)

| Kebutuhan | Dipakai untuk | Kalau tidak tersedia |
| :--- | :--- | :--- |
| **PHP 8.2** | Seluruh aplikasi | Tidak jalan |
| Ekstensi **intl** | `idn_to_ascii` — domain non-ASCII (IDN) | Domain IDN gagal diproses |
| Ekstensi **curl** | RDAP, GeoIP, Public Suffix List | Fitur utama mati |
| Ekstensi **mbstring** | Parsing respons WHOIS | Parsing kacau |
| **Outbound TCP port 43** | `fsockopen()` — WHOIS mentah (`WhoisClient.php:60`, `IanaResolver.php:79`) | ⚠️ Lihat catatan di bawah |
| **`dns_get_record()`** | Fitur DNS Dig (`DigChecker.php:76`) | Tab DNS Dig mati total |
| **Apache `mod_rewrite` + `.htaccess`** | Routing & proteksi `storage/` | Routing rusak, `storage/` bisa terekspos |
| **Document root = `public/`** | Struktur aplikasi | Kode sumber terekspos ke publik |
| **`storage/` bisa ditulis** | Cache SWR, log, upload | Cache & bulk upload gagal |

### ⚠️ Kenapa shared hosting berisiko untuk aplikasi ini

Rencana awal menyebut "WHOISDIG (PHP) bisa ke shared hosting biasa". Setelah kodenya diperiksa,
itu **tidak seaman kelihatannya**. WHOISDIG membuka **koneksi TCP mentah ke port 43**:

```php
// src/Clients/WhoisClient.php:60
$fp = @fsockopen($server, 43, $errno, $errstr, $timeout);
```

Banyak shared hosting memblokir outbound selain port 80/443, dan sebagian menonaktifkan
`fsockopen` serta `dns_get_record` lewat `disable_functions`. Kalau itu terjadi:

- **WHOIS mentah mati** → aplikasi jatuh ke **RDAP** (HTTPS). Sebagian besar TLD modern tetap
  terlayani, jadi aplikasi **tidak mati total** — tapi TLD lama yang hanya punya WHOIS akan gagal.
- **DNS Dig mati total** — `dns_get_record` tidak punya fallback.

Artinya: shared hosting mengubah WHOISDIG jadi versi pincang, justru menyembunyikan bagian yang
paling menarik secara teknis (WHOIS hybrid + circuit breaker). Untuk portofolio, itu merugikan.

**Uji dulu sebelum memilih shared hosting.** Unggah berkas ini, buka di browser:

```php
<?php
// cek-kompatibilitas.php — hapus setelah selesai dipakai
$fp = @fsockopen('whois.iana.org', 43, $e, $s, 5);
echo 'port 43       : ' . ($fp ? "OK\n" : "DIBLOKIR ($s)\n"); if ($fp) fclose($fp);
echo 'dns_get_record: ' . (function_exists('dns_get_record') ? "OK\n" : "DINONAKTIFKAN\n");
echo 'idn_to_ascii  : ' . (function_exists('idn_to_ascii') ? "OK\n" : "TIDAK ADA (ekstensi intl)\n");
echo 'curl          : ' . (function_exists('curl_init') ? "OK\n" : "TIDAK ADA\n");
echo 'PHP           : ' . PHP_VERSION . "\n";
```

Kalau baris pertama dan kedua **OK**, shared hosting aman dipakai. Kalau tidak, pilih host Docker.

---

## Pilihan Host

Urutan ini disusun dari yang paling menjaga keutuhan fitur. **Ketentuan free tier sering berubah —
konfirmasi langsung ke penyedia sebelum memutuskan.**

### 1. Host Docker (direkomendasikan — fitur utuh)

Jalankan `Dockerfile` yang sudah ada apa adanya. Port 43 dan `dns_get_record` tersedia penuh.
`render.yaml` di repo ini sudah disiapkan untuk **Render**:

```bash
git add render.yaml MIGRASI.md && git commit -m "Add Render blueprint" && git push
```

Lalu di Render Dashboard → **New → Blueprint** → pilih repo `whoisdig`.

Alternatif serupa: **Koyeb**, **Fly.io** (Fly umumnya minta kartu meski ada kuota gratis).

> **Catatan free tier Render:** service gratis di-*spin down* setelah tidak ada trafik, sehingga
> permintaan pertama bisa lambat (cold start puluhan detik). Untuk demo portofolio ini masih wajar,
> tapi kalau URL akan ditaruh di CV, pertimbangkan cron ping ringan agar tetap hangat.

### 2. VPS sendiri (kontrol penuh)

`docker compose up -d` memakai `docker-compose.yml` yang sudah ada. Paling stabil, tapi ada biaya
dan perlu dirawat — bertentangan dengan tujuan "jangan ada lagi yang perlu dirawat".

### 3. Shared hosting cPanel (hanya kalau lolos uji di atas)

1. Unggah isi repo ke luar `public_html`, mis. `~/whoisdig`.
2. Arahkan document root subdomain ke `~/whoisdig/public`.
3. Pastikan `storage/` bisa ditulis: `chmod -R 755 storage`.
4. Pastikan `.htaccess` aktif (`AllowOverride All`).
5. Aktifkan ekstensi `intl`, `curl`, `mbstring` lewat PHP Selector.

---

## Setelah Deploy — Verifikasi

```bash
# Ganti URL dengan alamat baru
BASE="https://whoisdig-xxxx.onrender.com"
curl -s -o /dev/null -w "root      : %{http_code}\n" -L --max-time 60 "$BASE/"
```

Lalu **uji manual di browser**, karena status 200 saja tidak membuktikan fitur jalan:

1. Lookup domain **`google.com`** → harus menampilkan data WHOIS/RDAP lengkap.
2. Lookup TLD lama yang WHOIS-only (mis. **`.mil`** atau domain `.id`) → membuktikan port 43 hidup.
3. Buka tab **DNS Dig** untuk `google.com` → membuktikan `dns_get_record` hidup.
4. Coba **bulk** 2–3 domain → membuktikan `storage/` bisa ditulis.

## Setelah Terbukti 200

- [ ] Perbarui `homepage` repo GitHub ke URL baru.
- [ ] Perbarui tabel **Ringkasan Status Link** di `CV-Nizen-Iskandar/data-lengkap-nizen/Portofolio Github Nizen.md`
      (ubah 🔴 DOWN → 🟢 200 dan tanggal ceknya).
- [ ] Perbarui badge/tautan "Live Demo" di `README.md` — sekarang masih menunjuk `whoisdig.web.app`.
- [ ] Baru setelah itu, link demo boleh dicantumkan lagi di CV.

## Membersihkan Sisa di Google Cloud

Setelah aplikasi hidup di host baru:

- Cabut `sa-key.json` (service account key) kalau sudah tidak dipakai — key-nya sudah di-`.gitignore`
  dan tidak pernah masuk riwayat git, tapi tetap sebaiknya di-*revoke*.
- Firebase Hosting `whoisdig.web.app` bisa diarahkan ulang (redirect) ke URL baru, atau dilepas.
