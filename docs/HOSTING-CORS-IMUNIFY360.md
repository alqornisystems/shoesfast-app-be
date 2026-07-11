# Perbaikan Server: CORS di frontend & Webhook WA tidak masuk

**Untuk admin hosting / server (cPanel · WHM · Imunify360).**
Domain API: `systems.shoesfast.id` (server `101.50.1.118`).

## Gejala
1. Frontend (`app-v2.shoesfast.id`, Vercel) gagal memanggil API — browser lapor **CORS error** (termasuk saat login).
2. **Webhook WhatsApp (Wablas) tidak diproses** → pesan customer tidak jadi order.

## Akar masalah (bukan aplikasi)
Requests ke `systems.shoesfast.id` dicegat oleh **Imunify360 bot-protection / openresty (WAF)** *sebelum* sampai ke Laravel, lalu dibalas **tanpa header CORS**:

- **Preflight `OPTIONS /api/*` → `HTTP 415`** dari `Server: openresty` (bukan 204 dari Laravel). Tanpa `Access-Control-Allow-Origin` → browser menyebutnya **CORS error**.
- **`GET/POST /api/*` dari non-browser** (mis. server Wablas) → halaman **"Access denied by Imunify360 bot-protection"** (HTTP 200, halaman blokir). Wablas mengira terkirim, padahal Laravel tak pernah menerimanya.

Bukti cepat (dari luar server):
```bash
curl -i -X OPTIONS https://systems.shoesfast.id/api/auth/login \
  -H "Origin: https://app-v2.shoesfast.id" \
  -H "Access-Control-Request-Method: POST"
# -> HTTP/1.1 415 Unsupported Media Type  |  Server: openresty   (SALAH; seharusnya 204 dari Laravel)
```

## Yang perlu dilakukan di server
1. **Izinkan method `OPTIONS` (preflight) lewat** ke aplikasi — jangan ditolak `415` oleh openresty / mod_security. (Preflight tidak bawa body; pastikan rule WAF tidak menolaknya karena "unsupported media type".)
2. **Kecualikan `systems.shoesfast.id` (atau path `/api/*`) dari Imunify360 bot-protection** (WebShield / Proactive Defense / mod_security untuk domain ini).
   - WHM → Imunify360 → **Settings/Proactive Defense/WebShield** → tambahkan domain/URL ke **Exclusion/Whitelist**.
3. **Whitelist IP server Wablas** agar webhook masuk (atau kecualikan khusus path `/api/webhook`).
   - Cara dapat IP-nya: WHM → Imunify360 → **Incidents/Events**, filter URL `/api/webhook` → lihat IP yang diblokir, lalu whitelist. (Jangan pakai IP Cloudflare.)

## Verifikasi setelah beres
```bash
# Preflight harus 204 + ada Access-Control-Allow-Origin:
curl -i -X OPTIONS https://systems.shoesfast.id/api/auth/login \
  -H "Origin: https://app-v2.shoesfast.id" -H "Access-Control-Request-Method: POST"

# GET tidak boleh mengembalikan halaman "Access denied by Imunify360":
curl -s https://systems.shoesfast.id/api/auth/me -H "Accept: application/json"
# -> {"message":"Unauthenticated."}  (bukan halaman blokir)
```

## Catatan
- Sisi aplikasi (Laravel) **sudah benar**: config CORS memakai origin eksplisit dari `FRONTEND_URL` dan preflight lokal mengembalikan `204` dengan header CORS lengkap. Selama proxy/WAF masih mencegat `OPTIONS`, perbaikan aplikasi tidak akan terlihat — **perbaikan wajib di layer server ini.**
- Pastikan `FRONTEND_URL` di `.env` produksi memuat origin frontend, mis:
  `FRONTEND_URL=https://app-v2.shoesfast.id,https://shoesfast-admin.vercel.app`
