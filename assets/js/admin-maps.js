/**
 * Shared helpers for admin geofence / live-tracking maps.
 */
window.EpassportMaps = {
    MAX_ZOOM: 22,
    _booted: {},

    /**
     * Register a Google Maps callback that waits for DOM + API, then runs once.
     * Usage: EpassportMaps.registerBoot('initGeofenceMap', bootGeofenceMap);
     */
    registerBoot(callbackName, initFn) {
        window[callbackName] = () => {
            if (this._booted[callbackName]) {
                return;
            }

            const run = () => {
                if (typeof google === 'undefined' || !google.maps) {
                    console.error('Google Maps API failed to load.');
                    return;
                }

                initFn();
                this._booted[callbackName] = true;
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run, { once: true });
            } else {
                run();
            }
        };
    },

    createOptions(defaults) {
        const options = {
            ...defaults,
            maxZoom: Math.max(defaults.maxZoom || 0, this.MAX_ZOOM),
            draggable: true,
            scrollwheel: true,
            gestureHandling: 'greedy',
            mapTypeControlOptions: {
                mapTypeIds: ['roadmap', 'hybrid', 'satellite', 'terrain'],
            },
        };

        delete options.restriction;

        return options;
    },

    centerOn(map, lat, lng, zoom) {
        if (!map) return;

        map.setCenter({ lat, lng });
        if (typeof zoom === 'number') {
            map.setZoom(zoom);
        }

        this.applyMaxZoomAt(map, new google.maps.LatLng(lat, lng));
    },

    /**
     * First paint: frame USTP Panaon and Punta Meriam so those labels are on screen.
     */
    focusOpeningArea(map, campus) {
        if (!map || !campus) return;

        const points = Array.isArray(campus.focus_points) ? campus.focus_points : [];
        const valid = [];

        points.forEach((point) => {
            const lat = Number(point && point.lat);
            const lng = Number(point && point.lng);
            if (Number.isFinite(lat) && Number.isFinite(lng) && !(lat === 0 && lng === 0)) {
                valid.push({ lat, lng });
            }
        });

        if (valid.length >= 2) {
            const bounds = new google.maps.LatLngBounds();
            valid.forEach((point) => bounds.extend(point));
            map.fitBounds(bounds, { top: 80, right: 80, bottom: 80, left: 80 });

            google.maps.event.addListenerOnce(map, 'idle', () => {
                const zoom = map.getZoom();
                const maxOpening = Number(campus.max_opening_zoom) || 18;
                const minOpening = Number(campus.min_zoom) || 16;
                if (typeof zoom === 'number' && zoom > maxOpening) {
                    map.setZoom(maxOpening);
                } else if (typeof zoom === 'number' && zoom < minOpening) {
                    map.setZoom(minOpening);
                }
            });
            return;
        }

        this.centerOn(
            map,
            Number(campus.latitude),
            Number(campus.longitude),
            Number(campus.default_zoom) || 17
        );
    },

    normalizeLatLng(position) {
        if (!position) return null;

        if (typeof position.lat === 'function') {
            return { lat: position.lat(), lng: position.lng() };
        }

        return { lat: Number(position.lat), lng: Number(position.lng) };
    },

    createGeofencePin(label) {
        const pin = document.createElement('div');
        pin.textContent = label;
        pin.style.cssText = [
            'background:#1f2f88',
            'color:#fff',
            'border:2px solid #fff',
            'border-radius:50%',
            'width:30px',
            'height:30px',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'font-weight:700',
            'font-size:14px',
            'box-shadow:0 2px 6px rgba(0,0,0,0.35)',
        ].join(';');
        return pin;
    },

    createGeofenceMarker(map, position, label, title) {
        const latLng = this.normalizeLatLng(position);
        if (!latLng || !map) return null;

        const useAdvanced = Boolean(
            map.get('mapId')
            && google.maps.marker
            && google.maps.marker.AdvancedMarkerElement
        );

        if (useAdvanced) {
            return new google.maps.marker.AdvancedMarkerElement({
                map,
                position: latLng,
                title: title || '',
                content: this.createGeofencePin(label),
            });
        }

        return new google.maps.Marker({
            map,
            position: latLng,
            title: title || '',
            label: {
                text: label,
                color: '#ffffff',
                fontWeight: '700',
            },
        });
    },

    removeMapOverlay(overlay) {
        if (!overlay) return;

        if (typeof overlay.setMap === 'function') {
            overlay.setMap(null);
            return;
        }

        overlay.map = null;
    },

    applyMaxZoomAt(map, latLng, done) {
        if (!map || !latLng || typeof google.maps.MaxZoomService !== 'function') {
            if (typeof done === 'function') done(null);
            return;
        }

        const service = new google.maps.MaxZoomService();
        service.getMaxZoomAtLatLng(latLng, (response) => {
            if (response.status === google.maps.MaxZoomStatus.OK) {
                map.setOptions({ maxZoom: response.zoom });
            }
            if (typeof done === 'function') done(response);
        });
    },

    zoomForRadius(lat, radiusMeters) {
        const latRad = (lat * Math.PI) / 180;
        const visibleMeters = Math.max(radiusMeters * 3, 30);
        const mapPixels = 420;
        const metersPerPixel = visibleMeters / mapPixels;
        const zoom = Math.log2((156543.03392 * Math.cos(latRad)) / metersPerPixel);

        return Math.max(10, Math.min(this.MAX_ZOOM, Math.ceil(zoom)));
    },

    fitToGeofence(map, lat, lng, radiusMeters, padding = 40) {
        if (!map) return;

        const center = new google.maps.LatLng(lat, lng);
        const bounds = new google.maps.Circle({
            center: { lat, lng },
            radius: Math.max(radiusMeters, 10),
        }).getBounds();

        const finish = (response) => {
            const locationMax = response && response.status === google.maps.MaxZoomStatus.OK
                ? response.zoom
                : this.MAX_ZOOM;
            const targetZoom = this.zoomForRadius(lat, radiusMeters);
            const desiredZoom = Math.min(locationMax, targetZoom);

            if (map.getZoom() < desiredZoom) {
                map.setZoom(desiredZoom);
            }
        };

        if (!bounds) {
            map.setCenter(center);
            this.applyMaxZoomAt(map, center, finish);
            return;
        }

        map.fitBounds(bounds, padding);
        google.maps.event.addListenerOnce(map, 'idle', () => {
            this.applyMaxZoomAt(map, center, finish);
        });
    },

    fitToBounds(map, bounds, padding = 48) {
        if (!map || !bounds) return;

        map.fitBounds(bounds, padding);
        google.maps.event.addListenerOnce(map, 'idle', () => {
            const center = map.getCenter();
            if (center) {
                this.applyMaxZoomAt(map, center);
            }
        });
    },
};
