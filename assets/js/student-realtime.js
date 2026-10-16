/**

 * WebSocket client for instant location requests after officer scan.

 * Falls back to dashboard polling if the server is offline.

 */

const StudentRealtime = (function () {

    'use strict';



    let ws = null;

    let reconnectDelay = 2000;

    let reconnectTimer = null;

    let pingTimer = null;

    let currentWsUrl = '';

    let currentToken = '';



    function setConnectionStatus(state, detail) {

        window.dispatchEvent(

            new CustomEvent('epassport:realtime-status', {

                detail: { state: state, message: detail || '' },

            })

        );

    }



    function dispatchLocationRequest(detail) {

        window.dispatchEvent(

            new CustomEvent('epassport:location-request', {

                detail: detail || {},

            })

        );

    }



    function dispatchLogoutReminder(detail) {

        window.dispatchEvent(

            new CustomEvent('epassport:logout-reminder', {

                detail: detail || {},

            })

        );

    }



    function stopPing() {

        if (pingTimer) {

            clearInterval(pingTimer);

            pingTimer = null;

        }

    }



    function startPing() {

        stopPing();

        pingTimer = setInterval(function () {

            if (ws && ws.readyState === WebSocket.OPEN) {

                ws.send(JSON.stringify({ type: 'ping' }));

            }

        }, 25000);

    }



    function connectWebSocket(wsUrl, token) {

        if (!wsUrl || !token) {

            return;

        }



        currentWsUrl = wsUrl;

        currentToken = token;



        const url =

            wsUrl +

            (wsUrl.indexOf('?') >= 0 ? '&' : '?') +

            'token=' +

            encodeURIComponent(token);



        if (ws) {

            ws.onclose = null;

            ws.close();

            ws = null;

        }



        setConnectionStatus('connecting', 'Connecting to realtime server…');



        try {

            ws = new WebSocket(url);

        } catch (e) {

            console.warn('WebSocket create failed:', e);

            setConnectionStatus('offline', 'Realtime offline — using polling fallback.');

            scheduleReconnect();

            return;

        }



        ws.onopen = function () {

            reconnectDelay = 2000;

            startPing();

            setConnectionStatus('online', 'Connected — location requests arrive instantly.');

            console.log('E-PASSPORT WebSocket connected:', wsUrl);

        };



        ws.onmessage = function (event) {

            try {

                const msg = JSON.parse(event.data);



                if (msg.type === 'location_request' && msg.attendance_id) {

                    console.log('WebSocket location_request:', msg.attendance_id);

                    dispatchLocationRequest(msg);

                }



                if (msg.type === 'logout_reminder' && msg.attendance_id) {

                    console.log('WebSocket logout_reminder:', msg.attendance_id);

                    dispatchLogoutReminder(msg);

                }

            } catch (e) {

                console.warn('WebSocket message parse error:', e);

            }

        };



        ws.onclose = function () {

            stopPing();

            setConnectionStatus('offline', 'Realtime reconnecting… (polling still active)');

            scheduleReconnect();

        };



        ws.onerror = function () {

            if (ws) {

                ws.close();

            }

        };

    }



    function scheduleReconnect() {

        if (reconnectTimer) {

            clearTimeout(reconnectTimer);

        }



        reconnectTimer = setTimeout(function () {

            if (currentWsUrl && currentToken) {

                connectWebSocket(currentWsUrl, currentToken);

            } else {

                connect();

            }

            reconnectDelay = Math.min(reconnectDelay * 1.5, 15000);

        }, reconnectDelay);

    }



    async function connect() {

        try {

            const response = await fetch('../ajax/register_presence.php', {

                method: 'POST',

                credentials: 'same-origin',

                headers: {

                    'Content-Type': 'application/x-www-form-urlencoded',

                },

                body: 'ping=1',

            });



            const data = await response.json();



            if (!data.success || !data.token) {

                setConnectionStatus(

                    'offline',

                    data.message || 'Could not register for realtime updates.'

                );

                return;

            }



            if (!data.ws_url) {

                setConnectionStatus(

                    'online',

                    'Secure mode — scan notifications via polling every 2 seconds.'

                );

                return;

            }



            connectWebSocket(data.ws_url, data.token);

        } catch (err) {

            console.warn('Presence registration failed (polling fallback):', err);

            setConnectionStatus('offline', 'Realtime unavailable — polling every 2 seconds.');

        }

    }



    function disconnect() {

        if (reconnectTimer) {

            clearTimeout(reconnectTimer);

            reconnectTimer = null;

        }



        stopPing();



        if (ws) {

            ws.close();

            ws = null;

        }



        setConnectionStatus('offline', 'Disconnected');

    }



    return {

        connect: connect,

        disconnect: disconnect,

    };

})();


