# E-PASSPORT — website GPS & online hosting

This system is a **website**. Students use Chrome/Safari on phones; officers and admins use any browser. There is no app-store client in this version.

## What powers real-time location?

| Feature | Technology | Required? |
|---------|------------|-----------|
| **Live GPS coordinates** | Browser `watchPosition` → `ajax/update_location.php` every ~2–5 s | **Yes** — core tracking |
| **Admin live map** | Polls `ajax/get_live_locations.php` every 2 s | **Yes** |
| **Instant scan popup** | Node WebSocket (`realtime/server.js`) | Optional but recommended |

---

## Step 1 — HTTPS (mandatory on phones)

Mobile browsers **block GPS on HTTP** (except `localhost`).

- **LAN testing:** follow `setup/HTTPS-GPS.md` (self-signed cert + `https://YOUR-LAN-IP/E-PASSPORT/`)
- **Online hosting:** use a real certificate (Let’s Encrypt, Cloudflare, hosting panel)

Students must **Allow Location** in browser settings.

---

## Step 2 — Start the WebSocket server (instant scan alerts)

GPS tracking works without this, but scan → location popup is faster with WebSocket.

PHP will **try to start** `realtime/server.js` by itself when someone opens the website (`autostart_websocket` in `config/realtime.php`), if Node.js is installed and `realtime/node_modules` exists.

First-time setup still needs:

```bat
cd C:\xampp\htdocs\E-PASSPORT\realtime
npm install
```

Install Node.js from https://nodejs.org if `node` is not on the PATH. Optional: set environment variable `EPASSPORT_NODE` to the full path of `node.exe`.

You can still start it by hand: `realtime/start.bat` or `npm start`.

Health check: `http://127.0.0.1:8081/health`

---

## Step 3 — Enable WebSocket on HTTPS (wss)

Browsers block `ws://` on `https://` pages. Proxy WebSocket through Apache/Nginx on port **443**.

### Apache (XAMPP)

1. Enable in `httpd.conf`:
   ```
   LoadModule proxy_module modules/mod_proxy.so
   LoadModule proxy_wsc_module modules/mod_proxy_wsc.so
   ```
2. Include `setup/apache-ws-proxy.conf` (edit path if needed).
3. In `config/realtime.php`:
   ```php
   'ws_path' => 'epassport-ws',
   ```
4. Restart Apache.

Students will connect to:  
`wss://your-host/E-PASSPORT/epassport-ws?token=...`

### Nginx (typical VPS)

```nginx
location /E-PASSPORT/epassport-ws {
    proxy_pass http://127.0.0.1:8081/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
}
```

Set `'ws_path' => 'epassport-ws'` in `config/realtime.php`.

---

## Step 4 — Configuration for online hosting

Edit before going live:

| File | Setting |
|------|---------|
| `.env` | Copy from `.env.example`. DB user/password, `EPASSPORT_SMS_API_TOKEN`, `EPASSPORT_GOOGLE_MAPS_API_KEY`. Never commit `.env`. |
| `config/app.php` | `'base_path' => '/E-PASSPORT'` or `''` if at domain root |
| `config/realtime.php` | `ws_path`, `ws_public_host` if needed |
| Google Cloud | Restrict the Maps key by HTTPS domain (after you have a domain) |

Apache already denies `/config`, `/database`, `/setup`, `/cron`, `/realtime`, `/includes`, `/vendor`, and `.env`. For Nginx, include `setup/nginx-deny.conf`.

Schema upgrades are **not** applied on page load. Run once per deploy:

```bash
php database/setup_schema.php
```

Fresh empty server: `mysql -u root < database/e_passport.sql` then create users in the admin UI.

`composer install` is optional (PDF reports already have a PHP fallback).

---

## Step 5 — Student workflow (mobile web)

1. Open **`https://yoursite/.../student/dashboard.php`** on phone (bookmark it).
2. Officer scans QR → student gets location prompt (WebSocket or 2 s polling).
3. Student taps **Allow Location** → marked present/late.
4. **Live tracking starts automatically** — GPS updates every few seconds as they walk.
5. Keep the **browser tab open** (or use `student/monitor.php` on a dedicated phone).

---

## Limitations (web-only, all platforms)

- **Tab must stay open** — the phone may pause GPS when the browser is force-closed, the screen is locked, or another app is in front.
- **Not true background GPS** — campus events work if students keep the page open.
- **Accuracy** depends on phone GPS/Wi‑Fi; geofence uses an accuracy buffer (see `config/realtime.php`).

---

## Online hosting — problems you may hit

| Issue | Cause | Fix |
|-------|--------|-----|
| GPS never works | Site still on HTTP | Force HTTPS |
| Scan prompt delayed ~2 s | WebSocket not running or no wss proxy | Start Node + configure `ws_path` |
| WebSocket never connects | `ws_path` empty on HTTPS | Set `ws_path` + reverse proxy |
| Port 8081 blocked publicly | Firewall | Only proxy 443; keep 8081 on localhost |
| Wrong redirects | Hardcoded `/E-PASSPORT` | Set `base_path` in `config/app.php` |
| DB errors | Migrations not run | Run `database/setup_*.php` |
| SMS not sent | IProg token / firewall | Set `EPASSPORT_SMS_API_TOKEN` in `.env`, allow outbound HTTPS |
| Maps blank | Invalid/restricted Google API key | Enable Maps JS API, billing, domain restriction |
| Shared hosting | No Node.js, no cron | Need VPS/cloud with Node + MySQL + cron |
| Secrets exposed | `config/` web-accessible | Block with `.htaccess` or move outside web root |
| Self-signed cert online | Browsers block | Use Let’s Encrypt |

**Shared cPanel-style hosting without Node.js is not a good fit** — you need a VPS or cloud VM where you can run `realtime/server.js` and schedule cron jobs.

---

## Quick checklist

- [ ] HTTPS with valid certificate
- [ ] Copy `.env` from `.env.example` (DB, SMS token, Maps key)
- [ ] `config/app.php` → `base_path` correct
- [ ] MySQL `e_passport` + `php database/setup_schema.php` once
- [ ] Node realtime running (`npm start` or PM2)
- [ ] `ws_path` + Apache/Nginx proxy (for HTTPS WebSocket)
- [ ] Firewall: 443 open; 8081 **not** public
- [ ] Student tested on real phone over HTTPS
