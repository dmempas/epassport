/**
 * Keeps GPS monitoring alive when students switch apps or dim the screen.
 * Uses Wake Lock, visibility-aware polling, optional notifications, and tab leadership.
 */
const BackgroundMonitor = (function () {
    'use strict';

    const LEADER_KEY = 'epassport_monitor_leader';
    const LEADER_AT_KEY = 'epassport_monitor_leader_at';
    const TAB_ID_KEY = 'epassport_tab_id';
    const LEADER_STALE_MS = 12000;
    const HEARTBEAT_MS = 4000;

    let wakeLock = null;
    let heartbeatTimer = null;
    let tabId = null;
    let active = false;
    let options = {};

    function getTabId() {
        if (tabId) {
            return tabId;
        }

        tabId = sessionStorage.getItem(TAB_ID_KEY);

        if (!tabId) {
            tabId = 'tab_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
            sessionStorage.setItem(TAB_ID_KEY, tabId);
        }

        return tabId;
    }

    function claimLeadership() {
        localStorage.setItem(LEADER_KEY, getTabId());
        localStorage.setItem(LEADER_AT_KEY, String(Date.now()));
    }

    function isLeader() {
        const leader = localStorage.getItem(LEADER_KEY);
        const leaderAt = parseInt(localStorage.getItem(LEADER_AT_KEY) || '0', 10);

        if (!leader || Date.now() - leaderAt > LEADER_STALE_MS) {
            return true;
        }

        return leader === getTabId();
    }

    function shouldSendGps() {
        return active && isLeader();
    }

    async function requestWakeLock() {
        if (!('wakeLock' in navigator)) {
            return false;
        }

        try {
            if (wakeLock) {
                return true;
            }

            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', function () {
                wakeLock = null;
            });

            return true;
        } catch (e) {
            return false;
        }
    }

    async function releaseWakeLock() {
        if (wakeLock) {
            try {
                await wakeLock.release();
            } catch (e) {
                // Ignore release errors.
            }

            wakeLock = null;
        }
    }

    async function reacquireWakeLock() {
        if (active) {
            await requestWakeLock();
        }
    }

    async function showMonitoringNotification(eventName) {
        if (!('Notification' in window) || Notification.permission === 'denied') {
            return;
        }

        if (Notification.permission === 'default') {
            try {
                await Notification.requestPermission();
            } catch (e) {
                return;
            }
        }

        if (Notification.permission !== 'granted') {
            return;
        }

        try {
            if (typeof Notification !== 'undefined') {
                new Notification('E-PASSPORT location monitoring', {
                    body:
                        (eventName ? eventName + ' — ' : '') +
                        'Location is being tracked. Keep GPS and Wi-Fi/mobile data ON.',
                    tag: 'epassport-monitoring',
                    renotify: true,
                });
            }
        } catch (e) {
            // Some browsers block notifications without service worker.
        }
    }

    function onVisibilityChange() {
        if (document.hidden) {
            claimLeadership();
            showMonitoringNotification(options.eventName || '');
        } else {
            claimLeadership();
            reacquireWakeLock();

            if (typeof window.epassportForceMonitoringPing === 'function' && shouldSendGps()) {
                window.epassportForceMonitoringPing();
            }
        }
    }

    function startHeartbeat() {
        stopHeartbeat();
        claimLeadership();

        heartbeatTimer = setInterval(function () {
            if (!active) {
                return;
            }

            claimLeadership();
        }, HEARTBEAT_MS);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    function start(opts) {
        options = opts || {};
        active = true;
        claimLeadership();
        startHeartbeat();
        requestWakeLock();
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('focus', reacquireWakeLock);
        window.addEventListener('pageshow', reacquireWakeLock);
    }

    function stop() {
        active = false;
        stopHeartbeat();
        document.removeEventListener('visibilitychange', onVisibilityChange);
        window.removeEventListener('focus', reacquireWakeLock);
        window.removeEventListener('pageshow', reacquireWakeLock);
        releaseWakeLock();
    }

    function openMonitorPage() {
        window.location.href = 'monitor.php';
    }

    function isMonitorPage() {
        return /monitor\.php/i.test(window.location.pathname);
    }

    return {
        start: start,
        stop: stop,
        shouldSendGps: shouldSendGps,
        isLeader: isLeader,
        isMonitorPage: isMonitorPage,
        openMonitorPage: openMonitorPage,
        requestWakeLock: requestWakeLock,
        releaseWakeLock: releaseWakeLock,
    };
})();
