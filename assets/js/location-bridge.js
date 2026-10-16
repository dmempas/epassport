/**
 * Location bridge — browser Geolocation only. HTTPS required on phones.
 */
const LocationBridge = (function () {
    'use strict';

    const OPTS_FAST = {
        enableHighAccuracy: false,
        timeout: 15000,
        maximumAge: 120000,
    };

    const OPTS_PRECISE = {
        enableHighAccuracy: true,
        timeout: 30000,
        maximumAge: 0,
    };

    const OPTS_MONITOR = {
        enableHighAccuracy: true,
        timeout: 25000,
        maximumAge: 0,
    };

    function isSecure() {
        return window.isSecureContext === true;
    }

    function getHttpsUrl() {
        try {
            const url = new URL(window.location.href);
            url.protocol = 'https:';
            if (url.port === '80' || url.port === '') {
                url.port = '';
            }
            return url.toString();
        } catch (e) {
            return window.location.href.replace(/^http:/i, 'https:');
        }
    }

    function isValidPosition(position) {
        if (!position || !position.coords) {
            return false;
        }

        const lat = Number(position.coords.latitude);
        const lng = Number(position.coords.longitude);

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return false;
        }

        if (Math.abs(lat) > 90 || Math.abs(lng) > 180) {
            return false;
        }

        return !(lat === 0 && lng === 0);
    }

    function normalizePosition(position) {
        if (!position || !position.coords) {
            return null;
        }

        return {
            coords: {
                latitude: Number(position.coords.latitude),
                longitude: Number(position.coords.longitude),
                accuracy: Number(position.coords.accuracy) || 0,
            },
        };
    }

    function errorLabel(error) {
        if (!error) {
            return 'Waiting for GPS…';
        }

        if (!isSecure()) {
            return 'GPS is blocked on HTTP. Switch to HTTPS using the button below.';
        }

        const labels = {
            1: 'Browser blocked location. Tap "Fix browser permission" below, then Retry.',
            2: 'Turn on Location Services on your device, then tap Retry.',
            3: 'GPS timed out. Still listening… tap Retry if needed.',
        };

        return labels[error.code] || (error.message || 'Waiting for GPS…');
    }

    function getCurrentPositionWithFallback(onSuccess, onError) {
        if (!navigator.geolocation) {
            onError({ code: 0, message: 'Geolocation not supported' });
            return;
        }

        if (!isSecure()) {
            onError({ code: 0, message: 'Not a secure context' });
            return;
        }

        const chain = [OPTS_PRECISE, OPTS_FAST];
        let index = 0;

        function next() {
            if (index >= chain.length) {
                onError({ code: 2, message: 'Could not get GPS fix' });
                return;
            }

            const opts = chain[index];
            index += 1;

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    const normalized = normalizePosition(position);
                    if (isValidPosition(normalized)) {
                        onSuccess(normalized);
                    } else {
                        next();
                    }
                },
                function (err) {
                    if (index >= chain.length) {
                        onError(err);
                    } else {
                        next();
                    }
                },
                opts
            );
        }

        next();
    }

    function queryPermissionState() {
        if (!navigator.permissions || !navigator.permissions.query) {
            return Promise.resolve('unknown');
        }

        return navigator.permissions
            .query({ name: 'geolocation' })
            .then(function (result) {
                return result.state;
            })
            .catch(function () {
                return 'unknown';
            });
    }

    function captureNow(onEnabled, onStatus, onError) {
        if (!isSecure()) {
            if (onStatus) {
                onStatus('GPS requires HTTPS on mobile. Use the secure link button.');
            }
            if (onError) {
                onError({ code: 0, message: 'Not a secure context' });
            }
            return;
        }

        if (onStatus) {
            onStatus('Requesting GPS…');
        }

        getCurrentPositionWithFallback(
            function (position) {
                if (onStatus) {
                    onStatus('GPS coordinates received.');
                }
                onEnabled(position);
            },
            function (err) {
                if (onStatus) {
                    onStatus(errorLabel(err));
                }
                if (onError) {
                    onError(err);
                }
            }
        );
    }

    function ensurePermission(onGranted, onBlocked) {
        if (!isSecure()) {
            onBlocked({ reason: 'insecure', message: 'Open this site with HTTPS first.' });
            return;
        }

        queryPermissionState().then(function (state) {
            if (state === 'denied') {
                onBlocked({ reason: 'denied', message: errorLabel({ code: 1 }) });
                return;
            }

            getCurrentPositionWithFallback(
                function (position) {
                    onGranted(position);
                },
                function (err) {
                    if (err && err.code === 1) {
                        onBlocked({ reason: 'denied', message: errorLabel(err) });
                    } else {
                        onGranted(null);
                    }
                }
            );
        });
    }

    function watchLocationEnabled(onEnabled, options) {
        const onStatus = options && options.onStatus;
        const pollMs = (options && options.pollMs) || 1000;
        let stopped = false;
        let watchId = null;
        let pollTimer = null;
        let permissionResult = null;

        function report(err) {
            if (onStatus) {
                onStatus(err ? errorLabel(err) : 'Listening for GPS…');
            }
        }

        function deliver(rawPosition) {
            if (stopped) {
                return;
            }

            const position = normalizePosition(rawPosition);
            if (!isValidPosition(position)) {
                return;
            }

            stopped = true;
            cleanup();
            onEnabled(position);
        }

        function clearWebWatch() {
            if (watchId !== null && navigator.geolocation) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
        }

        function startWebWatch() {
            if (stopped || !navigator.geolocation || !isSecure()) {
                return;
            }

            clearWebWatch();

            watchId = navigator.geolocation.watchPosition(
                deliver,
                function (err) {
                    report(err);
                    clearWebWatch();
                    if (!stopped) {
                        setTimeout(startWebWatch, 800);
                    }
                },
                OPTS_PRECISE
            );
        }

        function pollOnce() {
            if (stopped || !isSecure()) {
                return;
            }

            getCurrentPositionWithFallback(
                deliver,
                function (err) {
                    report(err);
                }
            );
        }

        function onResume() {
            if (!stopped) {
                startWebWatch();
                pollOnce();
            }
        }

        function cleanup() {
            clearWebWatch();

            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }

            document.removeEventListener('visibilitychange', onVisibilityChange);
            window.removeEventListener('focus', onResume);
            window.removeEventListener('pageshow', onResume);

            if (permissionResult) {
                permissionResult.onchange = null;
                permissionResult = null;
            }
        }

        function stop() {
            stopped = true;
            cleanup();
        }

        function onVisibilityChange() {
            if (!document.hidden) {
                onResume();
            }
        }

        if (!navigator.geolocation) {
            report({ code: 0, message: 'Geolocation not supported' });
            return stop;
        }

        if (!isSecure()) {
            report({ code: 0, message: 'HTTPS required' });
            return stop;
        }

        startWebWatch();
        pollOnce();
        pollTimer = setInterval(pollOnce, pollMs);

        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('focus', onResume);
        window.addEventListener('pageshow', onResume);

        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions
                .query({ name: 'geolocation' })
                .then(function (result) {
                    if (stopped) {
                        return;
                    }
                    permissionResult = result;
                    result.onchange = function () {
                        if (result.state === 'granted') {
                            onResume();
                        } else if (result.state === 'denied') {
                            report({ code: 1 });
                        }
                    };
                })
                .catch(function () {});
        }

        return stop;
    }

    function requestLocationPermission() {
        return ensurePermission(
            function () {
                return true;
            },
            function () {
                return false;
            }
        );
    }

    function getPermissionHelpHtml() {
        return (
            '<ol class="small mb-0 ps-3">' +
            '<li>Tap the <strong>lock icon</strong> (or ⓘ) in the browser address bar.</li>' +
            '<li>Open <strong>Permissions</strong> → <strong>Location</strong> → choose <strong>Allow</strong>.</li>' +
            '<li>Also turn on <strong>Location</strong> in your phone quick settings.</li>' +
            '<li>Return here and tap <strong>Retry GPS</strong>.</li>' +
            '</ol>'
        );
    }

    function watchContinuousPosition(onPosition, onError, options) {
        const pollMs = (options && options.pollMs) || 15000;
        const minIntervalMs = (options && options.minIntervalMs) || 10000;
        const hiddenPollMs = (options && options.hiddenPollMs) || pollMs;
        let stopped = false;
        let watchId = null;
        let pollTimer = null;
        let lastDeliveredAt = 0;

        function report(err) {
            if (onError) {
                onError(err);
            }
        }

        function deliver(rawPosition) {
            if (stopped) {
                return;
            }

            const position = normalizePosition(rawPosition);
            if (!isValidPosition(position)) {
                return;
            }

            const now = Date.now();
            if (now - lastDeliveredAt < minIntervalMs) {
                return;
            }

            lastDeliveredAt = now;
            onPosition(position);
        }

        function clearWebWatch() {
            if (watchId !== null && navigator.geolocation) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
        }

        function startWebWatch() {
            if (stopped || !navigator.geolocation || !isSecure()) {
                return;
            }

            clearWebWatch();

            watchId = navigator.geolocation.watchPosition(
                deliver,
                function (err) {
                    report(err);
                },
                OPTS_MONITOR
            );
        }

        function pollOnce() {
            if (stopped || !isSecure()) {
                return;
            }

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    deliver(position);
                },
                function (err) {
                    report(err);
                },
                OPTS_MONITOR
            );
        }

        function restartPollTimer() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }

            const interval = document.hidden ? hiddenPollMs : pollMs;
            pollTimer = setInterval(pollOnce, interval);
        }

        function onResume() {
            if (stopped) {
                return;
            }

            restartPollTimer();
            pollOnce();
            startWebWatch();
        }

        function onVisibilityChange() {
            restartPollTimer();

            if (!document.hidden) {
                onResume();
            } else {
                pollOnce();
            }
        }

        function stop() {
            stopped = true;
            clearWebWatch();

            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }

            document.removeEventListener('visibilitychange', onVisibilityChange);
            window.removeEventListener('focus', onResume);
            window.removeEventListener('pageshow', onResume);
        }

        if (!navigator.geolocation) {
            report({ code: 0, message: 'Geolocation not supported' });
            return stop;
        }

        if (!isSecure()) {
            report({ code: 0, message: 'HTTPS required' });
            return stop;
        }

        startWebWatch();
        pollOnce();
        restartPollTimer();
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('focus', onResume);
        window.addEventListener('pageshow', onResume);

        return stop;
    }

    return {
        isSecure: isSecure,
        getHttpsUrl: getHttpsUrl,
        isValidPosition: isValidPosition,
        captureNow: captureNow,
        ensurePermission: ensurePermission,
        watchLocationEnabled: watchLocationEnabled,
        watchContinuousPosition: watchContinuousPosition,
        requestLocationPermission: requestLocationPermission,
        getPermissionHelpHtml: getPermissionHelpHtml,
        queryPermissionState: queryPermissionState,
    };
})();
