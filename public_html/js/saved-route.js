/* A saved route's page: the route as it was saved, drawn the same way the map screen drew it,
 * with its elevation profile. Everything else on the page is server-rendered. */
import { createMap, decodePolyline, drawElevation, fitTo, MapLayout, mapUnavailableReason, RouteLayers, UNAVAILABLE_MESSAGES } from 'waypoint/map-core';

const screen = document.querySelector('[data-saved-route]');

if (screen) {
	const route = JSON.parse(screen.dataset.route || '{}');
	const layout = new MapLayout(screen);
	const chart = screen.querySelector('[data-elevation-chart]');

	if (chart) {
		drawElevation(chart, route.profile);
	}

	const showFallback = (reason) => {
		const [title, text] = UNAVAILABLE_MESSAGES[reason] ?? UNAVAILABLE_MESSAGES['tiles-failed'];
		screen.querySelector('[data-map-fallback-title]').textContent = title;
		screen.querySelector('[data-map-fallback-text]').textContent = `${text} The route's details are below.`;
		screen.querySelector('[data-map-fallback]').hidden = false;
	};

	const unavailable = mapUnavailableReason(screen.dataset.tilesUrl);

	if (unavailable) {
		showFallback(unavailable);
	} else {
		try {
			const map = createMap({ container: screen.querySelector('[data-map]'), tilesUrl: screen.dataset.tilesUrl, onFailure: showFallback });
			const layers = new RouteLayers(map);
			let line = [];

			try {
				line = decodePolyline(route.polyline ?? '', route.precision ?? 6);
			} catch (error) {
				line = [];
			}

			layers.setRoutes(line.length > 1 ? [line] : [], 0);
			layers.setEnds(route.origin, route.destination);

			const bounds = layers.bounds();

			if (bounds) {
				requestAnimationFrame(() => fitTo(map, bounds, layout.padding()));
			}
		} catch (error) {
			showFallback('no-webgl');
		}
	}
}
