# GPS on student phones (HTTPS required)

Mobile browsers **block GPS on plain HTTP**. You must use **HTTPS** on the same Wi‑Fi.

## 1. Generate SSL for your LAN IP (once on the server PC)

In **PowerShell as Administrator**:

```powershell
cd C:\xampp\htdocs\E-PASSPORT\setup
.\generate-lan-ssl.ps1 -LanIp 192.168.1.103
```

Use your real IPv4 from `ipconfig`. Then **restart Apache** in XAMPP.

## 2. Open the app on your phone

```
https://192.168.1.103/E-PASSPORT/student/secure_access.php
```

Or go to the dashboard — HTTP will send you to the help page first.

## 3. Pass the Chrome privacy screen (required)

You will see **“Your connection is not private”** / `NET::ERR_CERT_AUTHORITY_INVALID`.

This is **normal** for a local self-signed certificate.

1. Tap **Advanced**
2. Tap **Proceed to 192.168.1.103 (unsafe)**
3. Log in to the student portal
4. When scanned, tap **Allow** and allow **Location** in Chrome site settings

## Allow browser location (Chrome Android)

1. Tap the **lock icon** (or ⓘ) in the address bar
2. **Permissions** → **Location** → **Allow**
3. Turn on **Location** in phone quick settings
4. In the app dialog, tap **Retry GPS**

## Find your PC IP

On the server PC, run `ipconfig` and use the **IPv4 Address** (e.g. `192.168.1.10`).

## Firewall

Allow inbound **TCP 443** (HTTPS) on Windows Firewall so phones on the same Wi‑Fi can connect.
