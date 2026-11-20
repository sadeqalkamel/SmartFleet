import * as maplibregl from 'maplibre-gl';

export function loadRouteOverlay(map) {

    function validPoint(latitude, longitude) {
        const lat = Number(latitude);
        const lng = Number(longitude);

        if (
            !Number.isFinite(lat) ||
            !Number.isFinite(lng) ||
            lat < -90 || lat > 90 ||
            lng < -180 || lng > 180
        ) {
            return null;
        }

        return [lng, lat];
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async function getRoadRoute(path) {
        const coordinates = path
            .map(p => `${p[0]},${p[1]}`)
            .join(';');

        const url =
            'https://router.project-osrm.org/route/v1/driving/' +
            coordinates +
            '?overview=full&geometries=geojson&steps=false';

        const response = await fetch(url);

        if (!response.ok) {
            throw new Error(`OSRM HTTP ${response.status}`);
        }

        const data = await response.json();

        if (
            data.code !== 'Ok' ||
            !data.routes?.[0]?.geometry?.coordinates
        ) {
            throw new Error('OSRM route unavailable');
        }

        return data.routes[0];
    }

    async function drawRoutes() {
        try {
            const response = await fetch('/admin/map-routes', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });

            if (!response.ok) {
                throw new Error('Could not load routes');
            }

            const routes = await response.json();

            if (!Array.isArray(routes)) {
                throw new Error('Invalid routes response');
            }

            const lines = [];
            const points = [];

            // Limit calls to the public demonstration server.
            // Only the first 5 routes are drawn in this test.
            for (const route of routes.slice(0, 5)) {

                const start = validPoint(
                    route.start?.latitude,
                    route.start?.longitude
                );

                if (!start) continue;

                const path = [start];

                points.push({
                    type: 'Feature',
                    properties: {
                        type: 'start',
                        label: 'S',
                        description:
                            'نقطة انطلاق المسار ' +
                            route.route_number
                    },
                    geometry: {
                        type: 'Point',
                        coordinates: start
                    }
                });

                const stops = [...(route.stops || [])]
                    .sort(
                        (a, b) =>
                            Number(a.sequence) -
                            Number(b.sequence)
                    );

                for (const stop of stops) {

                    const position = validPoint(
                        stop.latitude,
                        stop.longitude
                    );

                    if (!position) continue;

                    path.push(position);

                    points.push({
                        type: 'Feature',
                        properties: {
                            type: 'stop',
                            label: String(stop.sequence),
                            description:
                                'المسار: ' + route.route_number +
                                '\nالطلب: ' + stop.order_number +
                                '\nالترتيب: ' + stop.sequence
                        },
                        geometry: {
                            type: 'Point',
                            coordinates: position
                        }
                    });
                }

                if (path.length < 2) continue;

                let geometry = {
                    type: 'LineString',
                    coordinates: path
                };

                let roadDistance = null;
                let roadDuration = null;
                let roadSuccess = false;

                try {
                    const road = await getRoadRoute(path);

                    geometry = road.geometry;
                    roadDistance = road.distance;
                    roadDuration = road.duration;
                    roadSuccess = true;

                    console.log(
                        'REAL ROAD ROUTE:',
                        route.route_number,
                        'Distance:',
                        roadDistance,
                        'Duration:',
                        roadDuration
                    );

                } catch (error) {
                    console.warn(
                        'OSRM unavailable; showing straight-line fallback:',
                        route.route_number,
                        error
                    );
                }

                lines.push({
                    type: 'Feature',
                    properties: {
                        route_number: route.route_number,
                        is_real_road: roadSuccess ? 1 : 0,
                        distance_meters: roadDistance,
                        duration_seconds: roadDuration
                    },
                    geometry
                });

                // Respect demo server rate limit.
                await sleep(1100);
            }

            map.addSource('sf-route-lines', {
                type: 'geojson',
                data: {
                    type: 'FeatureCollection',
                    features: lines
                }
            });

            // Real road routes: solid blue lines
            map.addLayer({
                id: 'sf-route-lines-layer',
                type: 'line',
                source: 'sf-route-lines',
                filter: ['==', ['get', 'is_real_road'], 1],
                layout: {
                    'line-join': 'round',
                    'line-cap': 'round'
                },
                paint: {
                    'line-color': '#2563eb',
                    'line-width': 5,
                    'line-opacity': 0.9
                }
            });

            // Fallback routes: dashed purple lines
            map.addLayer({
                id: 'sf-route-fallback-layer',
                type: 'line',
                source: 'sf-route-lines',
                filter: ['==', ['get', 'is_real_road'], 0],
                paint: {
                    'line-color': '#7c3aed',
                    'line-width': 4,
                    'line-opacity': 0.8,
                    'line-dasharray': [2, 2]
                }
            });

            map.addSource('sf-route-points', {
                type: 'geojson',
                data: {
                    type: 'FeatureCollection',
                    features: points
                }
            });

            map.addLayer({
                id: 'sf-route-point-circles',
                type: 'circle',
                source: 'sf-route-points',
                paint: {
                    'circle-radius': 15,
                    'circle-color': [
                        'match',
                        ['get', 'type'],
                        'start', '#16a34a',
                        '#ea580c'
                    ],
                    'circle-stroke-color': '#ffffff',
                    'circle-stroke-width': 2
                }
            });

            map.addLayer({
                id: 'sf-route-point-labels',
                type: 'symbol',
                source: 'sf-route-points',
                layout: {
                    'text-field': ['get', 'label'],
                    'text-size': 13,
                    'text-allow-overlap': true,
                    'text-ignore-placement': true
                },
                paint: {
                    'text-color': '#ffffff'
                }
            });

            map.on('click', 'sf-route-point-circles', e => {
                const feature = e.features?.[0];

                if (!feature) return;

                new maplibregl.Popup({ offset: 20 })
                    .setLngLat(e.lngLat)
                    .setText(feature.properties.description)
                    .addTo(map);
            });

            map.on('mouseenter', 'sf-route-point-circles', () => {
                map.getCanvas().style.cursor = 'pointer';
            });

            map.on('mouseleave', 'sf-route-point-circles', () => {
                map.getCanvas().style.cursor = '';
            });

            console.log(
                'SMARTFLEET ROUTES LOADED:',
                lines.length,
                'POINTS:',
                points.length
            );

        } catch (error) {
            console.error('ROUTE MAP ERROR:', error);
        }
    }

    if (map.isStyleLoaded()) {
        drawRoutes();
    } else {
        map.once('load', drawRoutes);
    }
}