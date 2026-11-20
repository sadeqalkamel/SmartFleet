import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { loadRouteOverlay } from './route-overlay';

import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

maplibregl.setWorkerUrl(workerUrl);

document.addEventListener('DOMContentLoaded', () => {

    const container = document.getElementById('fleet-map');

    if (!container) return;

    const map = new maplibregl.Map({
        container: 'fleet-map',
        style: 'https://tiles.openfreemap.org/styles/liberty',
        center: [44.1930, 15.3710],
        zoom: 12
    });

    map.addControl(
        new maplibregl.NavigationControl(),
        'top-left'
    );
    loadRouteOverlay(map);

    const drivers = new Map();

    const FRESH_LOCATION_MS = 2 * 60 * 1000;

    function getStatus(recordedAt) {
        const timestamp = Date.parse(recordedAt);

        if (!Number.isFinite(timestamp)) {
            return 'وقت غير معروف';
        }

        const age = Date.now() - timestamp;

        return age >= 0 && age <= FRESH_LOCATION_MS
            ? 'موقع حديث'
            : 'موقع قديم';
    }

    function formatTime(recordedAt) {
        const date = new Date(recordedAt);

        if (Number.isNaN(date.getTime())) {
            return 'غير معروف';
        }

        return date.toLocaleString('ar', {
            dateStyle: 'short',
            timeStyle: 'medium'
        });
    }

    function createMarkerElement(name) {

        const element = document.createElement('div');

        element.style.cssText = `
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            min-width: 90px;
        `;

        const icon = document.createElement('div');

        icon.textContent = '🚚';

        icon.style.cssText = `
            width: 44px;
            height: 44px;
            background: #0284c7;
            border: 3px solid white;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 23px;
            box-shadow: 0 3px 12px #0005;
        `;

        const label = document.createElement('div');

        label.textContent = name;

        label.style.cssText = `
            background: #0f172a;
            color: white;
            font-size: 12px;
            font-weight: bold;
            padding: 5px 9px;
            border-radius: 7px;
            margin-top: 4px;
            border: 1px solid #334155;
            white-space: nowrap;
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
        `;

        const status = document.createElement('div');

        status.style.cssText = `
            width: 12px;
            height: 12px;
            border: 2px solid white;
            border-radius: 50%;
            margin-top: 4px;
            background: #64748b;
        `;

        element.append(icon, label, status);

        return { element, label, status };
    }

    function createPopupContent(driver) {

        const wrapper = document.createElement('div');

        wrapper.style.cssText = `
            font-family: Tahoma, Arial, sans-serif;
            min-width: 180px;
            color: #0f172a;
            line-height: 1.8;
        `;

        const title = document.createElement('strong');
        title.textContent = driver.name;

        const info = document.createElement('div');

        info.textContent =
            'الحالة: ' + getStatus(driver.recordedAt) +
            '\nآخر تحديث: ' + formatTime(driver.recordedAt) +
            '\nالموقع: ' + driver.lat + ', ' + driver.lng;

        info.style.whiteSpace = 'pre-line';

        wrapper.append(title, info);

        return wrapper;
    }

    function refreshDriverDisplay(driver) {

        const status = getStatus(driver.recordedAt);

        driver.label.textContent = driver.name;

        driver.status.style.background =
            status === 'موقع حديث'
                ? '#22c55e'
                : '#64748b';

        driver.popup.setDOMContent(
            createPopupContent(driver)
        );
    }

    function updateDriverLocation(data, isLive = false) {

        const id = Number(data.driver_id);
        const lat = Number(data.latitude);
        const lng = Number(data.longitude);

        if (
            !Number.isInteger(id) || id <= 0 ||
            !Number.isFinite(lat) ||
            !Number.isFinite(lng) ||
            lat < -90 || lat > 90 ||
            lng < -180 || lng > 180
        ) {
            return;
        }

        const name = data.driver_name ||
            'Driver #' + id;

        const recordedAt = data.recorded_at ||
            new Date().toISOString();

        const timestamp = Date.parse(recordedAt);

        const previous = drivers.get(id);

        if (previous) {

            const previousTime =
                Date.parse(previous.recordedAt);

            if (
                Number.isFinite(timestamp) &&
                Number.isFinite(previousTime) &&
                timestamp < previousTime
            ) {
                return;
            }

            previous.marker.setLngLat([lng, lat]);

            previous.name = name;
            previous.lat = lat;
            previous.lng = lng;
            previous.recordedAt = recordedAt;

            refreshDriverDisplay(previous);

        } else {

            const ui = createMarkerElement(name);

            const popup = new maplibregl.Popup({
                offset: 25
            });

            const marker = new maplibregl.Marker({
                element: ui.element,
                anchor: 'bottom'
            })
                .setLngLat([lng, lat])
                .setPopup(popup)
                .addTo(map);

            const driver = {
                marker,
                popup,
                label: ui.label,
                status: ui.status,
                name,
                lat,
                lng,
                recordedAt
            };

            drivers.set(id, driver);

            refreshDriverDisplay(driver);
        }

        const counter = document.getElementById(
            'tracked-drivers-count'
        );

        if (counter) {
            counter.textContent = drivers.size;
        }

        if (isLive) {
            map.flyTo({
                center: [lng, lat],
                zoom: 13,
                speed: 1.2,
                essential: true
            });

            console.log('MAP MOVING TO:', lng, lat);
        }
    }

    // Receive live updates from dashboard
    window.addEventListener(
        'smartfleet:location-updated',
        event => {
            console.log('MAP GPS RECEIVED:', event.detail);

            updateDriverLocation(event.detail, true);
        }
    );

    // Load saved locations
    fetch('/admin/driver-locations', {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json'
        }
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to load locations');
            }

            return response.json();
        })
        .then(locations => {

            locations.forEach(data => {
                updateDriverLocation(data);
            });

            if (locations.length > 0) {
                const first = locations[0];

                map.flyTo({
                    center: [
                        Number(first.longitude),
                        Number(first.latitude)
                    ],
                    zoom: 12
                });
            }

            console.log(
                'Saved driver locations loaded:',
                locations.length
            );
        })
        .catch(error => console.error(error));

    // Refresh location freshness indicators
    setInterval(() => {
        drivers.forEach(refreshDriverDisplay);
    }, 30000);

});