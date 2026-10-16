# Background GPS Monitoring (website)

E-PASSPORT is a **browser website**. Students use Chrome or Safari on their phones. There is no store app in this version.

## How it works

1. Officer scans the student QR code.
2. Student allows location and is marked present/late.
3. The student stays on the dashboard or opens **Background Monitoring** (`student/monitor.php`).
4. GPS is sent while:
   - Location Services stay ON
   - Wi-Fi or mobile data stay ON
   - The **browser tab stays open** (not swiped away)

Switching to Messenger, Facebook, Home, or locking the screen usually **pauses** GPS. That is a phone/browser limit, not a hosting issue.

## Student instructions

1. Open the site with **HTTPS** (required for GPS on phones).
2. After attendance is marked, keep **Background Monitoring** (or the dashboard) in front.
3. Keep Location and internet ON.
4. Do **not** swipe away the browser from recent apps until logout scan.
5. Optional on Android: Add to Home screen (still a website tab).

## Server-side backup

If GPS pings stop, the website still runs expire-events, unscanned-absent, and outside SMS while someone is using the site (or via optional Task Scheduler on `cron/check_outside_students.php`).

## Platform notes

| Platform | GPS while the tab is in front | After switching apps |
|----------|-------------------------------|----------------------|
| Android Chrome | Good | Usually pauses |
| Android Home screen shortcut | Slightly more stable tab | Usually pauses |
| iOS Safari | Works when the page is visible | Usually pauses |

## Files

- `student/monitor.php` — lightweight monitoring page
- `assets/js/background-monitor.js` — wake lock, tab leadership, notifications
- `assets/js/location-bridge.js` — browser Geolocation
- `assets/js/gps.js` — monitoring orchestration
