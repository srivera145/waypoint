/* Waypoint's map building blocks, shared by the map screen (map.js) and a saved route's page
 * (saved-route.js): the self-hosted basemap, the route lines, polyline decoding and the
 * elevation profile. Pages import it as 'waypoint/map-core' through the import map in
 * views/partials/map-assets.php.
 *
 * Nothing here talks to a third-party service. Tiles come from the .pmtiles archive the page
 * names, and fonts and sprites from public_html/vendor.
 */
import * as maplibregl from 'maplibre-gl';
import { layers, namedFlavor } from 'protomaps-basemaps';

const ASSETS = '/vendor/protomaps-basemaps-assets@028c18f';
const BASEMAP = 'protomaps';
const ATTRIBUTION = '<a href="https://protomaps.com">Protomaps</a> © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>';

/* Route lines, widest last: [zoom, width] pairs for a line-width interpolation. */
const WIDTH = {
	selected: [5, 5, 10, 7, 15, 10],
	selectedCasing: [5, 8, 10, 11, 15, 15],
	alternate: [5, 3, 10, 4, 15, 6],
	alternateCasing: [5, 5, 10, 7, 15, 9],
};

/**
 * Decodes an encoded polyline into [lng, lat] pairs, GeoJSON's order. Precision 6 is what
 * Valhalla writes and what /api/routes sends. Arithmetic rather than bit operators, so a
 * value past 2^31 cannot wrap.
 */
export function decodePolyline(encoded, precision = 6) {
	const factor = 10 ** precision;
	const points = [];
	let index = 0;
	let lat = 0;
	let lng = 0;

	const next = () => {
		let result = 0;
		let scale = 1;
		let byte;

		do {
			if (index >= encoded.length) {
				throw new Error('The route line was cut short.');
			}

			byte = encoded.charCodeAt(index++) - 63;
			result += (byte & 0x1f) * scale;
			scale *= 32;
		} while (byte >= 0x20);

		return result % 2 === 1 ? -(result + 1) / 2 : result / 2;
	};

	while (index < encoded.length) {
		lat += next();
		lng += next();
		points.push([lng / factor, lat / factor]);
	}

	return points;
}

/** The theme in effect: the one Deck's switch set, else the operating system's. */
export function currentTheme() {
	const theme = document.documentElement.getAttribute('data-theme');

	if (theme === 'light' || theme === 'dark') {
		return theme;
	}

	return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/** Calls back with the new theme whenever the effective theme changes, from either source. */
export function onThemeChange(callback) {
	let theme = currentTheme();
	const check = () => {
		const next = currentTheme();

		if (next !== theme) {
			theme = next;
			callback(next);
		}
	};

	new MutationObserver(check).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
	matchMedia('(prefers-color-scheme: dark)').addEventListener('change', check);
}

/**
 * A Deck colour token as rgba(), which every MapLibre version parses. Deck's tokens are
 * oklch() inside light-dark(), so the browser resolves one on a probe element for the
 * current theme and the result is read back off a one-pixel canvas.
 */
export function tokenColor(token) {
	const probe = document.createElement('span');
	probe.hidden = true;
	probe.style.color = `var(${token})`;
	document.body.append(probe);
	const resolved = getComputedStyle(probe).color;
	probe.remove();

	tokenColor.canvas ??= Object.assign(document.createElement('canvas'), { width: 1, height: 1 });
	const context = tokenColor.canvas.getContext('2d', { willReadFrequently: true });
	context.clearRect(0, 0, 1, 1);
	context.fillStyle = resolved;
	context.fillRect(0, 0, 1, 1);
	const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;

	return `rgba(${r}, ${g}, ${b}, ${(a / 255).toFixed(3)})`;
}

/**
 * Why no map can be drawn here, or null when one can: 'no-tiles' (no basemap installed),
 * 'no-library' (pmtiles.js did not load) or 'no-webgl' (MapLibre 6 needs WebGL 2).
 */
export function mapUnavailableReason(tilesUrl) {
	if (!tilesUrl) {
		return 'no-tiles';
	}

	if (!window.pmtiles) {
		return 'no-library';
	}

	return document.createElement('canvas').getContext('webgl2') ? null : 'no-webgl';
}

/** For each mapUnavailableReason(), and 'tiles-failed': a title and the cause. Pages add what still works. */
export const UNAVAILABLE_MESSAGES = {
	'no-tiles': ['No basemap is installed', 'Waypoint has no map tiles to draw yet (see docker/README.md).'],
	'no-library': ['The map didn\'t load', 'Reload the page to try again.'],
	'no-webgl': ['This browser can\'t draw the map', 'The map needs WebGL 2, which is off or unsupported here.'],
	'tiles-failed': ['The map tiles didn\'t load', 'Reload the page to try again.'],
};

let protocol = null;

function basemapStyle(tilesUrl, theme) {
	return {
		version: 8,
		glyphs: `${location.origin}${ASSETS}/fonts/{fontstack}/{range}.pbf`,
		sprite: `${location.origin}${ASSETS}/sprites/v4/${theme}`,
		sources: {
			[BASEMAP]: { type: 'vector', url: `pmtiles://${tilesUrl}`, attribution: ATTRIBUTION },
		},
		layers: layers(BASEMAP, namedFlavor(theme), { lang: 'en' }),
	};
}

/**
 * A MapLibre map on the self-hosted basemap, styled light or dark with the page and restyled
 * when that changes. Call mapUnavailableReason() first.
 *
 * Until something else moves the camera, it shows the whole area the tile archive covers.
 * onFailure(reason) is called once if the basemap itself cannot be read.
 */
export function createMap({ container, tilesUrl, onFailure }) {
	const url = new URL(tilesUrl, location.href).href;

	if (protocol === null) {
		protocol = new window.pmtiles.Protocol({ metadata: true });
		maplibregl.addProtocol('pmtiles', protocol.tile);
	}

	const archive = new window.pmtiles.PMTiles(url);
	protocol.add(archive);

	const map = new maplibregl.Map({
		container,
		style: basemapStyle(url, currentTheme()),
		center: [-98.6, 39.8],
		zoom: 3,
		attributionControl: false,
		dragRotate: false,
		pitchWithRotate: false,
		touchPitch: false,
	});
	map.touchZoomRotate.disableRotation();
	map.keyboard.disableRotation();
	// Bottom left: the locate button has the bottom right.
	map.addControl(new maplibregl.AttributionControl({ compact: true }), 'bottom-left');

	let moved = false;
	map.once('movestart', () => {
		moved = true;
	});

	archive.getHeader().then((header) => {
		if (!moved) {
			map.fitBounds([[header.minLon, header.minLat], [header.maxLon, header.maxLat]], { animate: false, padding: 20 });
		}
	}).catch(() => {
		onFailure?.('tiles-failed');
		onFailure = null;
	});

	map.on('error', (event) => {
		if (event.sourceId === BASEMAP && !map.loaded()) {
			onFailure?.('tiles-failed');
			onFailure = null;
		}
	});

	// A full reload rather than a diff: every paint colour changes, and RouteLayers puts its
	// own layers back on 'style.load'.
	onThemeChange((theme) => map.setStyle(basemapStyle(url, theme), { diff: false }));

	return map;
}

function lineWidth(stops) {
	return ['interpolate', ['linear'], ['zoom'], ...stops];
}

function boundsOf(coordinates) {
	const bounds = new maplibregl.LngLatBounds();

	for (const point of coordinates) {
		bounds.extend(point);
	}

	return bounds;
}

/** The distance in pixels from a screen point to the nearest segment of a line. */
function pixelDistance(map, coordinates, point) {
	let best = Infinity;
	let previous = null;

	for (const coordinate of coordinates) {
		const current = map.project(coordinate);

		if (previous !== null) {
			const dx = current.x - previous.x;
			const dy = current.y - previous.y;
			const lengthSquared = dx * dx + dy * dy;
			const t = lengthSquared === 0 ? 0 : Math.max(0, Math.min(1, ((point.x - previous.x) * dx + (point.y - previous.y) * dy) / lengthSquared));
			best = Math.min(best, Math.hypot(point.x - (previous.x + t * dx), point.y - (previous.y + t * dy)));
		}

		previous = current;
	}

	return best;
}

/**
 * The routes, their two ends and the user's position, drawn over the basemap. The selected
 * route is bold in the brand colour on top; the others are thinner and muted beneath it.
 * Tapping a line picks it: onPick(index) is called and the caller decides what selecting means.
 *
 * Survives a theme change: everything is put back, in the new theme's colours, on 'style.load'.
 */
export class RouteLayers {
	constructor(map, { onPick } = {}) {
		this.map = map;
		this.onPick = onPick;
		this.routes = [];
		this.selected = 0;
		this.ends = [];
		this.position = null;

		map.on('style.load', () => this.install());

		if (map.isStyleLoaded()) {
			this.install();
		}

		map.on('click', 'route-hit', (event) => this.pick(event));
		map.on('mouseenter', 'route-hit', () => {
			map.getCanvas().style.cursor = 'pointer';
		});
		map.on('mouseleave', 'route-hit', () => {
			map.getCanvas().style.cursor = '';
		});
	}

	/** @param {Array<Array<[number, number]>>} routes each route's [lng, lat] coordinates */
	setRoutes(routes, selected = 0) {
		this.routes = routes;
		this.selected = selected;
		this.refresh('routes', this.routeData());
	}

	select(index) {
		this.selected = index;
		this.refresh('routes', this.routeData());
	}

	/** @param {{lng: number, lat: number}|null} origin @param {{lng: number, lat: number}|null} destination */
	setEnds(origin, destination) {
		this.ends = [
			origin && { kind: 'origin', point: [origin.lng, origin.lat] },
			destination && { kind: 'destination', point: [destination.lng, destination.lat] },
		].filter(Boolean);
		this.refresh('route-ends', this.endsData());
	}

	setPosition(position) {
		this.position = position;
		this.refresh('position', this.positionData());
	}

	/** Bounds of every route, else of the two ends, else null. */
	bounds() {
		const coordinates = this.routes.length > 0 ? this.routes.flat() : this.ends.map((end) => end.point);

		return coordinates.length > 0 ? boundsOf(coordinates) : null;
	}

	refresh(sourceId, data) {
		this.map.getSource(sourceId)?.setData(data);
	}

	routeData() {
		return {
			type: 'FeatureCollection',
			features: this.routes.map((coordinates, index) => ({
				type: 'Feature',
				properties: { index, selected: index === this.selected },
				geometry: { type: 'LineString', coordinates },
			})),
		};
	}

	endsData() {
		return {
			type: 'FeatureCollection',
			features: this.ends.map((end) => ({ type: 'Feature', properties: { kind: end.kind }, geometry: { type: 'Point', coordinates: end.point } })),
		};
	}

	positionData() {
		return {
			type: 'FeatureCollection',
			features: this.position === null ? [] : [{ type: 'Feature', properties: {}, geometry: { type: 'Point', coordinates: [this.position.lng, this.position.lat] } }],
		};
	}

	install() {
		const map = this.map;
		const color = {
			route: tokenColor('--brand'),
			alternate: tokenColor('--ink-400'),
			casing: tokenColor('--surface'),
			text: tokenColor('--text'),
			good: tokenColor('--good-500'),
		};
		const selected = ['==', ['get', 'selected'], true];
		const alternate = ['==', ['get', 'selected'], false];
		const round = { 'line-join': 'round', 'line-cap': 'round' };

		map.addSource('routes', { type: 'geojson', data: this.routeData() });
		map.addSource('route-ends', { type: 'geojson', data: this.endsData() });
		map.addSource('position', { type: 'geojson', data: this.positionData() });

		map.addLayer({ id: 'route-alternate-casing', type: 'line', source: 'routes', filter: alternate, layout: round, paint: { 'line-color': color.casing, 'line-width': lineWidth(WIDTH.alternateCasing) } });
		map.addLayer({ id: 'route-alternate', type: 'line', source: 'routes', filter: alternate, layout: round, paint: { 'line-color': color.alternate, 'line-width': lineWidth(WIDTH.alternate) } });
		map.addLayer({ id: 'route-selected-casing', type: 'line', source: 'routes', filter: selected, layout: round, paint: { 'line-color': color.casing, 'line-width': lineWidth(WIDTH.selectedCasing) } });
		map.addLayer({ id: 'route-selected', type: 'line', source: 'routes', filter: selected, layout: round, paint: { 'line-color': color.route, 'line-width': lineWidth(WIDTH.selected) } });
		// Invisible and wide, so a fingertip anywhere near a line picks it.
		map.addLayer({ id: 'route-hit', type: 'line', source: 'routes', layout: round, paint: { 'line-color': '#000', 'line-opacity': 0, 'line-width': 28 } });

		map.addLayer({
			id: 'route-ends',
			type: 'circle',
			source: 'route-ends',
			paint: {
				'circle-radius': ['match', ['get', 'kind'], 'destination', 9, 7],
				'circle-color': ['match', ['get', 'kind'], 'destination', color.route, color.casing],
				'circle-stroke-color': ['match', ['get', 'kind'], 'destination', color.casing, color.text],
				'circle-stroke-width': 3,
			},
		});
		map.addLayer({ id: 'position-halo', type: 'circle', source: 'position', paint: { 'circle-radius': 16, 'circle-color': color.good, 'circle-opacity': 0.2 } });
		map.addLayer({ id: 'position', type: 'circle', source: 'position', paint: { 'circle-radius': 7, 'circle-color': color.good, 'circle-stroke-color': color.casing, 'circle-stroke-width': 3 } });
	}

	/**
	 * Where lines overlap, the one nearest the tap wins; on a stretch they share, the selected
	 * route stays selected rather than flipping to whichever was drawn on top.
	 */
	pick(event) {
		const indices = [...new Set((event.features ?? []).map((feature) => feature.properties.index))];

		if (indices.length === 0) {
			return;
		}

		let best = null;

		for (const index of indices) {
			const distance = pixelDistance(this.map, this.routes[index] ?? [], event.point);

			if (best === null || distance < best.distance - 2 || (Math.abs(distance - best.distance) <= 2 && index === this.selected)) {
				best = { index, distance };
			}
		}

		this.onPick?.(best.index);
	}
}

/**
 * Draws [distance_m, height_m] pairs into the elevation <svg> (viewBox 300 x 80): a line and
 * the area under it. The vertical scale spans at least 100 m, so a flat route looks flat
 * rather than being stretched until its bumps fill the chart. Returns false when there is
 * nothing to draw.
 */
export function drawElevation(svg, profile) {
	const area = svg.querySelector('[data-elevation-area]');
	const line = svg.querySelector('[data-elevation-line]');

	if (!Array.isArray(profile) || profile.length < 2) {
		area.setAttribute('d', '');
		line.setAttribute('d', '');
		return false;
	}

	const width = 300;
	const height = 80;
	const inset = 4;
	const first = profile[0][0];
	const run = profile[profile.length - 1][0] - first || 1;
	const heights = profile.map(([, metres]) => metres);
	const low = Math.min(...heights);
	const span = Math.max(Math.max(...heights) - low, 100);

	const points = profile.map(([distance, metres]) => [
		((distance - first) / run * width).toFixed(1),
		(inset + (1 - (metres - low) / span) * (height - inset * 2)).toFixed(1),
	]);
	const path = 'M' + points.map(([x, y]) => `${x},${y}`).join('L');

	line.setAttribute('d', path);
	area.setAttribute('d', `${path}L${width},${height}L0,${height}Z`);

	return true;
}

/**
 * The screen around the map: the search panel over its top and the sheet over its bottom on
 * a phone, a column beside it from 64rem (see .map-screen in keel.css). Keeps the CSS
 * variables that place the locate button and attribution current, runs the sheet's expand
 * button and drag, and says how much of the map the two leave uncovered.
 *
 * The sheet has three states: 'collapsed' (never past --sheet-collapsed, so the map always
 * shows), 'expanded', and 'peek' (just its header).
 */
export class MapLayout {
	constructor(screen) {
		this.screen = screen;
		this.top = screen.querySelector('[data-map-top]');
		this.sheet = screen.querySelector('[data-sheet]');
		this.handle = screen.querySelector('[data-sheet-handle]');
		this.toggle = screen.querySelector('[data-sheet-toggle]');
		this.body = this.sheet.querySelector('.map-sheet-body');
		this.wide = matchMedia('(min-width: 64rem)');

		const sync = () => this.sync();
		new ResizeObserver(sync).observe(this.top);
		new ResizeObserver(sync).observe(this.sheet);
		this.wide.addEventListener('change', sync);
		sync();

		this.toggle?.addEventListener('click', () => this.setSheet(this.state === 'expanded' ? 'collapsed' : 'expanded'));
		this.handle.addEventListener('pointerdown', (event) => this.drag(event));
	}

	get state() {
		return this.sheet.dataset.state;
	}

	setSheet(state) {
		this.sheet.dataset.state = state;
		this.toggle?.setAttribute('aria-expanded', state === 'expanded' ? 'true' : 'false');
		this.toggle?.setAttribute('aria-label', state === 'expanded' ? 'Show less of the route panel' : 'Show more of the route panel');
	}

	/** Out of peek, so whatever was just put in the sheet can be seen. */
	reveal() {
		if (this.state === 'peek') {
			this.setSheet('collapsed');
		}
	}

	/** The sheet's height in a state, for padding the map before the sheet has finished moving. */
	heightFor(state) {
		const handle = this.handle.offsetHeight;

		if (state === 'peek') {
			return handle;
		}

		if (state === 'expanded') {
			return innerHeight - this.top.getBoundingClientRect().bottom - 12;
		}

		return Math.min(handle + this.body.scrollHeight, innerHeight * 0.46);
	}

	sync() {
		this.screen.style.setProperty('--map-top-height', `${Math.round(this.top.getBoundingClientRect().bottom)}px`);
		this.screen.style.setProperty('--sheet-peek', `${this.handle.offsetHeight}px`);
		this.screen.style.setProperty('--sheet-height', this.wide.matches ? '0px' : `${Math.round(this.sheet.getBoundingClientRect().height)}px`);
	}

	/** Padding for fitting the map to something: clear of the panel and of the sheet in its current state. */
	padding() {
		if (this.wide.matches) {
			return { top: 48, bottom: 48, left: 48, right: 48 };
		}

		return {
			top: this.top.getBoundingClientRect().bottom + 16,
			bottom: this.heightFor(this.state) + 16,
			left: 24,
			// Clear of the locate button, which sits at the right just above the sheet.
			right: 72,
		};
	}

	/** Dragging the grip or header: the sheet follows the finger, then settles on the nearest state, or the next one on a flick. A tap toggles. */
	drag(event) {
		if (this.wide.matches || event.button !== 0 || event.target.closest('a, button, input')) {
			return;
		}

		const sheet = this.sheet;
		const startY = event.clientY;
		const startHeight = sheet.getBoundingClientRect().height;
		const heights = { peek: this.heightFor('peek'), collapsed: this.heightFor('collapsed'), expanded: this.heightFor('expanded') };
		const order = ['peek', 'collapsed', 'expanded'];
		let moved = false;
		let height = startHeight;
		let lastY = startY;
		let lastTime = event.timeStamp;
		let velocity = 0;

		this.handle.setPointerCapture(event.pointerId);

		const move = (moveEvent) => {
			const dy = moveEvent.clientY - startY;

			if (!moved && Math.abs(dy) < 6) {
				return;
			}

			moved = true;
			sheet.dataset.dragging = '';
			height = Math.min(heights.expanded, Math.max(heights.peek, startHeight - dy));
			sheet.style.blockSize = sheet.style.maxBlockSize = `${height}px`;
			velocity = (moveEvent.clientY - lastY) / Math.max(1, moveEvent.timeStamp - lastTime);
			lastY = moveEvent.clientY;
			lastTime = moveEvent.timeStamp;
		};

		const end = () => {
			this.handle.removeEventListener('pointermove', move);
			this.handle.removeEventListener('pointerup', end);
			this.handle.removeEventListener('pointercancel', end);

			if (!moved) {
				this.setSheet(this.state === 'expanded' ? 'collapsed' : 'expanded');
				return;
			}

			let state = order.reduce((best, candidate) => (Math.abs(heights[candidate] - height) < Math.abs(heights[best] - height) ? candidate : best), 'collapsed');

			if (Math.abs(velocity) > 0.5) {
				const flicked = order[Math.min(2, Math.max(0, order.indexOf(state) + (velocity < 0 ? 1 : -1)))];
				state = (velocity < 0) === (heights[flicked] > height) ? flicked : state;
			}

			delete sheet.dataset.dragging;
			sheet.style.blockSize = sheet.style.maxBlockSize = '';
			this.setSheet(state);
		};

		this.handle.addEventListener('pointermove', move);
		this.handle.addEventListener('pointerup', end);
		this.handle.addEventListener('pointercancel', end);
	}
}

/** Fits the map to bounds inside the padding, shrinking the padding if the map is too small for it. */
export function fitTo(map, bounds, padding) {
	const canvas = map.getCanvas();
	const fit = { ...padding };
	const spareHeight = canvas.clientHeight - fit.top - fit.bottom;
	const spareWidth = canvas.clientWidth - fit.left - fit.right;

	if (spareHeight < 80) {
		const scale = Math.max(0, canvas.clientHeight - 80) / (fit.top + fit.bottom);
		fit.top *= scale;
		fit.bottom *= scale;
	}

	if (spareWidth < 80) {
		fit.left = fit.right = Math.max(0, (canvas.clientWidth - 80) / 2);
	}

	map.fitBounds(bounds, { padding: fit, maxZoom: 15, duration: 600 });
}
