/* The map screen: search for a destination, see up to three routes, compare them, save one.
 *
 * views/map/index.php renders the panel, the sheet and the <template>s this fills. The map
 * itself is optional: when WebGL or the basemap is missing, search, routing and saving still
 * work in the sheet, and the map area says why it is empty.
 *
 * The trip (destination, starting point) is mirrored into the URL, so the vehicle chip can
 * send the user to switch vehicles and land back on the same trip, re-routed.
 */
import { createMap, decodePolyline, drawElevation, fitTo, MapLayout, mapUnavailableReason, RouteLayers, UNAVAILABLE_MESSAGES } from 'waypoint/map-core';

const screen = document.querySelector('[data-map-screen]');

if (screen) {
	start(screen);
}

function start(screen) {
	const $ = (selector, root = document) => root.querySelector(selector);
	const csrfToken = $('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
	const vehicle = JSON.parse(screen.dataset.vehicle || 'null');
	const categories = JSON.parse(screen.dataset.categories || '{}');
	const initial = JSON.parse(screen.dataset.initial || '{}');

	const el = {
		search: $('[data-search]'),
		searchLabel: $('[data-search-label]'),
		results: $('[data-results]'),
		resultsList: $('[data-results-list]'),
		resultsStatus: $('[data-results-status]'),
		originMode: $('[data-origin-mode]'),
		originCancel: $('[data-origin-cancel]'),
		originChip: $('[data-origin-chip]'),
		originLabel: $('[data-origin-label]'),
		vehicleChip: $('[data-vehicle-chip]'),
		locate: $('[data-locate]'),
		title: $('[data-sheet-title]'),
		subtitle: $('[data-sheet-subtitle]'),
		message: $('[data-sheet-message]'),
		clearance: $('[data-clearance]'),
		clearanceText: $('[data-clearance-text]'),
		options: $('[data-route-options]'),
		detail: $('[data-route-detail]'),
		whyText: $('[data-why-text]'),
		whyWarnings: $('[data-why-warnings]'),
		elevationChart: $('[data-elevation-chart]'),
		elevationRange: $('[data-elevation-range]'),
		elevationSummary: $('[data-elevation-summary]'),
		save: $('[data-save]'),
		saveLabel: $('[data-save-label]'),
		travelMode: $('[data-travel-mode]'),
		customToggle: $('[data-custom-toggle]'),
		customModes: $('#custom-modes'),
		fallback: $('[data-map-fallback]'),
	};

	const templates = {
		place: $('#place-result-template'),
		card: $('#route-card-template'),
		message: $('#sheet-message-template'),
		loading: $('#loading-template'),
	};

	const ROUTE_ICONS = { fastest: '⚡', vehicle_friendly: vehicle?.emoji ?? '🚗', scenic: '🏞️', flat: '〰️', alternate: '🔀' };
	const CLASSIFICATIONS = {
		OK: ['OK', 'badge-good'],
		SUBOPTIMAL: ['Suboptimal', 'badge-warn'],
		RESTRICTED: ['Restricted', 'badge-bad'],
	};

	const trip = {
		destination: null, // {lat, lng, label}
		origin: null, // {lat, lng, label}, or null for the user's current location
		position: null, // {lat, lng} from the Geolocation API, the last time it answered
		plan: null, // the /api/routes response on screen
		plannedFrom: null, // the starting point that plan was made from, with its label
		selected: 0,
		saved: new Map(), // "label|polyline" => saved route id
		personality: screen.dataset.personality,
		searchMode: 'destination',
	};

	let map = null;
	let routeLayers = null;

	/* --- Requests ------------------------------------------------------------ */

	/**
	 * JSON to and from Waypoint. Never throws: a dropped connection or a timeout comes back as
	 * status 0 with an error worded for the user. An aborted request comes back as null.
	 */
	async function api(url, { method = 'GET', body = null, timeout = 10000, signal = null } = {}) {
		const timer = new AbortController();
		const timeoutId = setTimeout(() => timer.abort(), timeout);
		const signals = signal ? AbortSignal.any([signal, timer.signal]) : timer.signal;

		try {
			const response = await fetch(url, {
				method,
				headers: {
					Accept: 'application/json',
					...(body ? { 'Content-Type': 'application/json' } : {}),
					...(method === 'GET' ? {} : { 'X-CSRF-Token': csrfToken }),
				},
				body: body ? JSON.stringify(body) : null,
				credentials: 'same-origin',
				signal: signals,
			});
			const data = await response.json().catch(() => ({}));

			return { ok: response.ok, status: response.status, data };
		} catch (error) {
			if (signal?.aborted) {
				return null;
			}

			const timedOut = timer.signal.aborted;

			return {
				ok: false,
				status: 0,
				data: {
					error: timedOut ? 'Waypoint is taking too long to answer. Try again in a moment.' : 'Waypoint can\'t be reached. Check your connection, then try again.',
					reason: timedOut ? 'timeout' : 'offline',
				},
			};
		} finally {
			clearTimeout(timeoutId);
		}
	}

	/* --- Sheet messages ------------------------------------------------------ */

	/** One message at the top of the sheet: tone is bad, warn or info. Actions are buttons or links. */
	function showMessage({ tone = 'bad', title, text = '', actions = [] }) {
		const node = templates.message.content.firstElementChild.cloneNode(true);
		node.classList.add(`alert-${tone}`);
		$('[data-message-title]', node).textContent = title;
		$('[data-message-text]', node).textContent = text;
		$('[data-message-text]', node).hidden = text === '';

		const actionRow = $('[data-message-actions]', node);
		for (const action of actions) {
			const control = document.createElement(action.href ? 'a' : 'button');
			control.className = 'btn btn-sm';
			control.textContent = action.label;

			if (action.href) {
				control.href = action.href;
			} else {
				control.type = 'button';
				control.addEventListener('click', action.onClick);
			}

			actionRow.append(control);
		}
		actionRow.hidden = actions.length === 0;

		el.message.replaceChildren(node);
		layout.reveal();
	}

	function clearMessage() {
		el.message.replaceChildren();
	}

	function showLoading(text) {
		const node = templates.loading.content.firstElementChild.cloneNode(true);
		$('[data-loading-text]', node).textContent = text;
		el.options.replaceChildren(node);
		el.detail.hidden = true;
	}

	/* --- Layout ------------------------------------------------------------- */

	const layout = new MapLayout(screen);

	/* --- The map ------------------------------------------------------------- */

	function showMapFallback(reason) {
		const [title, text] = UNAVAILABLE_MESSAGES[reason] ?? UNAVAILABLE_MESSAGES['tiles-failed'];
		$('[data-map-fallback-title]').textContent = title;
		$('[data-map-fallback-text]').textContent = `${text} Search and routes still work.`;
		el.fallback.hidden = false;
	}

	const unavailable = mapUnavailableReason(screen.dataset.tilesUrl);

	if (unavailable) {
		showMapFallback(unavailable);
	} else {
		try {
			map = createMap({ container: $('[data-map]'), tilesUrl: screen.dataset.tilesUrl, onFailure: showMapFallback });
			routeLayers = new RouteLayers(map, { onPick: (index) => selectRoute(index, { fromMap: true }) });
		} catch (error) {
			map = null;
			routeLayers = null;
			showMapFallback('no-webgl');
		}
	}

	function fitTrip() {
		const bounds = routeLayers?.bounds();

		if (map && bounds) {
			requestAnimationFrame(() => fitTo(map, bounds, layout.padding()));
		}
	}

	/* --- Where the user is --------------------------------------------------- */

	const LOCATION_PROBLEMS = {
		insecure: ['Location needs a secure connection', 'Browsers only share your location with https sites. Choose a starting point instead.'],
		unsupported: ['This browser can\'t share your location', 'Choose a starting point instead.'],
		denied: ['Location access is off for Waypoint', 'Allow location for this site in your browser\'s settings, or choose a starting point.'],
		unavailable: ['Your location isn\'t available right now', 'Your device couldn\'t work out where it is. Try again, or choose a starting point.'],
		timeout: ['Finding your location took too long', 'Try again, or choose a starting point.'],
	};

	/** Resolves with {lat, lng}, or rejects with one of LOCATION_PROBLEMS' keys. */
	function locate() {
		return new Promise((resolve, reject) => {
			if (!window.isSecureContext) {
				reject('insecure');
				return;
			}

			if (!('geolocation' in navigator)) {
				reject('unsupported');
				return;
			}

			navigator.geolocation.getCurrentPosition(
				(position) => {
					trip.position = { lat: position.coords.latitude, lng: position.coords.longitude };
					routeLayers?.setPosition(trip.position);
					resolve(trip.position);
				},
				(error) => reject({ 1: 'denied', 2: 'unavailable', 3: 'timeout' }[error.code] ?? 'unavailable'),
				{ enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
			);
		});
	}

	function showLocationProblem(reason, retry) {
		const [title, text] = LOCATION_PROBLEMS[reason] ?? LOCATION_PROBLEMS.unavailable;
		const actions = [{ label: 'Choose a starting point', onClick: enterOriginMode }];

		if (retry && reason !== 'insecure' && reason !== 'unsupported') {
			actions.push({ label: 'Try again', onClick: retry });
		}

		showMessage({ tone: 'warn', title, text, actions });
	}

	el.locate.addEventListener('click', async () => {
		el.locate.setAttribute('aria-busy', 'true');

		try {
			const position = await locate();
			clearMessage();
			map?.easeTo({ center: [position.lng, position.lat], zoom: Math.max(map.getZoom(), 13), padding: layout.padding() });

			if (trip.destination && !trip.origin && !trip.plan) {
				planRoute();
			}
		} catch (reason) {
			showLocationProblem(reason, () => el.locate.click());
		} finally {
			el.locate.removeAttribute('aria-busy');
		}
	});

	// Already allowed: find the user quietly, so the first search is biased toward them.
	navigator.permissions?.query({ name: 'geolocation' }).then((status) => {
		if (status.state === 'granted') {
			locate().catch(() => {});
		}
	}).catch(() => {});

	/* --- Search -------------------------------------------------------------- */

	let searchTimer = null;
	let searchController = null;
	let results = [];
	let activeResult = -1;
	let lastQuery = '';

	const CURRENT_LOCATION = { current: true, label: 'Your location', secondary_label: 'Where you are now', category: 'current' };

	function setExpanded(open) {
		el.results.hidden = !open;
		el.search.setAttribute('aria-expanded', open ? 'true' : 'false');

		if (!open) {
			setActiveResult(-1);
		}
	}

	function setActiveResult(index) {
		activeResult = index;
		el.resultsList.querySelectorAll('[role="option"]').forEach((option, optionIndex) => {
			option.setAttribute('aria-selected', optionIndex === index ? 'true' : 'false');
		});

		if (index >= 0) {
			const option = el.resultsList.children[index];
			el.search.setAttribute('aria-activedescendant', option.id);
			option.scrollIntoView({ block: 'nearest' });
		} else {
			el.search.removeAttribute('aria-activedescendant');
		}
	}

	function renderResults(places, status = '') {
		results = trip.searchMode === 'origin' ? [CURRENT_LOCATION, ...places] : places;
		el.resultsStatus.textContent = status;

		el.resultsList.replaceChildren(...results.map((place, index) => {
			const row = templates.place.content.firstElementChild.cloneNode(true);
			const category = place.category === 'current' ? { label: 'Your location', emoji: '📍' } : (categories[place.category] ?? categories.address);
			row.id = `place-option-${index}`;
			$('[data-place-icon]', row).textContent = category?.emoji ?? '📍';
			$('[data-place-label]', row).textContent = place.label;
			$('[data-place-secondary]', row).textContent = place.secondary_label ?? '';
			$('[data-place-category]', row).textContent = category ? `, ${category.label}` : '';
			// Keeps focus in the search box, so its blur doesn't close the list under the tap.
			row.addEventListener('mousedown', (event) => event.preventDefault());
			row.addEventListener('click', () => choose(index));

			return row;
		}));

		setActiveResult(-1);
		setExpanded(results.length > 0 || status !== '');
	}

	async function search(query) {
		searchController?.abort();
		searchController = new AbortController();
		lastQuery = query;

		// Where the user is, else the middle of the map once it shows about a city. Zoomed out to
		// a whole state, the middle says nothing, and leaning on it ranks parks near it above
		// the town that was typed.
		const center = map && map.getZoom() >= 10 ? map.getCenter() : null;
		const bias = trip.position ?? (center ? { lat: center.lat, lng: center.lng } : null);
		const params = new URLSearchParams({ q: query });

		if (bias) {
			params.set('lat', bias.lat.toFixed(4));
			params.set('lng', bias.lng.toFixed(4));
		}

		el.resultsStatus.textContent = 'Searching…';
		setExpanded(true);

		const response = await api(`/api/places/search?${params}`, { signal: searchController.signal, timeout: 8000 });

		if (response === null || query !== lastQuery) {
			return;
		}

		if (!response.ok) {
			renderResults([], searchProblem(response));
			return;
		}

		const places = response.data.results ?? [];
		renderResults(places, places.length === 0 ? `No places match “${query}”. Try a town, an address or a business name.` : '');
	}

	function searchProblem(response) {
		if (response.status === 401) {
			return 'You\'ve been signed out. Reload the page to sign in again.';
		}

		return response.data.error ?? 'Place search isn\'t working right now. Try again in a moment.';
	}

	el.search.addEventListener('input', () => {
		clearTimeout(searchTimer);
		const query = el.search.value.trim();

		if (query.length < 2) {
			searchController?.abort();
			lastQuery = '';
			renderResults([], query.length === 1 ? 'Keep typing…' : '');
			return;
		}

		searchTimer = setTimeout(() => search(query), 300);
	});

	el.search.addEventListener('keydown', (event) => {
		const count = results.length;

		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			if (count === 0) {
				return;
			}

			event.preventDefault();
			setExpanded(true);
			setActiveResult(event.key === 'ArrowDown' ? (activeResult + 1) % count : (activeResult - 1 + count) % count);
		} else if (event.key === 'Enter') {
			event.preventDefault();

			if (activeResult >= 0) {
				choose(activeResult);
			} else if (count > 0 && el.search.value.trim() === lastQuery) {
				choose(trip.searchMode === 'origin' && count > 1 ? 1 : 0);
			} else if (el.search.value.trim().length >= 2) {
				clearTimeout(searchTimer);
				search(el.search.value.trim());
			}
		} else if (event.key === 'Escape') {
			if (!el.results.hidden) {
				setExpanded(false);
			} else if (trip.searchMode === 'origin') {
				exitOriginMode();
			}
		}
	});

	el.search.addEventListener('focus', () => {
		if (results.length > 0 || trip.searchMode === 'origin') {
			setExpanded(true);
		}
	});

	// Late enough that a tap on a result, which some touch browsers deliver after the blur, still lands.
	el.search.addEventListener('blur', () => setTimeout(() => {
		if (document.activeElement !== el.search) {
			setExpanded(false);
		}
	}, 200));

	function choose(index) {
		const place = results[index];

		if (!place) {
			return;
		}

		setExpanded(false);

		if (trip.searchMode === 'origin') {
			trip.origin = place.current ? null : { lat: place.lat, lng: place.lng, label: place.label };
			exitOriginMode();
		} else {
			trip.destination = { lat: place.lat, lng: place.lng, label: place.label };
			el.search.value = place.label;
			el.search.blur();
		}

		syncTrip();

		if (trip.destination) {
			planRoute();
		}
	}

	/* --- Choosing a starting point --------------------------------------------- */

	function enterOriginMode() {
		trip.searchMode = 'origin';
		el.originMode.hidden = false;
		el.searchLabel.textContent = 'Search for a starting point';
		el.search.placeholder = 'Starting point';
		el.search.value = '';
		lastQuery = '';
		renderResults([]);
		el.search.focus();
	}

	function exitOriginMode() {
		trip.searchMode = 'destination';
		el.originMode.hidden = true;
		el.searchLabel.textContent = 'Search for a destination';
		el.search.placeholder = 'Where to?';
		el.search.value = trip.destination?.label ?? '';
		lastQuery = '';
		results = [];
		el.resultsList.replaceChildren();
		el.resultsStatus.textContent = '';
		setExpanded(false);
	}

	el.originChip.addEventListener('click', enterOriginMode);
	el.originCancel.addEventListener('click', () => {
		exitOriginMode();
		el.search.focus();
	});

	/* --- The trip in the URL ---------------------------------------------------- */

	function syncTrip() {
		const params = new URLSearchParams();
		const point = (place) => `${place.lat.toFixed(6)},${place.lng.toFixed(6)}`;

		if (trip.destination) {
			params.set('to', point(trip.destination));
			params.set('to_label', trip.destination.label);
		}

		if (trip.origin) {
			params.set('from', point(trip.origin));
			params.set('from_label', trip.origin.label);
		}

		const query = params.toString();
		const url = '/map' + (query === '' ? '' : `?${query}`);
		history.replaceState(null, '', url);

		if (vehicle) {
			el.vehicleChip.href = `/vehicles?return_to=${encodeURIComponent(url)}`;
		}

		el.originLabel.textContent = trip.origin?.label ?? 'Your location';
		routeLayers?.setEnds(trip.origin ?? trip.position, trip.destination);
	}

	/* --- Planning ------------------------------------------------------------ */

	let planController = null;

	function clearPlan() {
		trip.plan = null;
		el.options.replaceChildren();
		el.detail.hidden = true;
		el.clearance.hidden = true;
		routeLayers?.setRoutes([]);
	}

	async function planRoute() {
		if (!trip.destination) {
			return;
		}

		planController?.abort();
		const controller = new AbortController();
		planController = controller;
		clearMessage();
		clearPlan();
		el.title.textContent = `Routes to ${trip.destination.label}`;
		layout.reveal();

		let origin = trip.origin;

		if (!origin) {
			showLoading('Finding your location…');
			el.subtitle.textContent = 'From your location';

			try {
				const position = await locate();
				origin = { lat: position.lat, lng: position.lng, label: 'Your location', current: true };
			} catch (reason) {
				if (planController === controller) {
					el.options.replaceChildren();
					showLocationProblem(reason, planRoute);
				}

				return;
			}
		}

		if (planController !== controller) {
			return;
		}

		routeLayers?.setEnds(origin, trip.destination);
		el.subtitle.textContent = (origin.current ? 'From your location' : `From ${origin.label}`) + (vehicle ? ` · ${vehicle.name}` : '');
		showLoading(vehicle ? `Finding routes for ${vehicle.name}…` : 'Finding routes…');

		const response = await api('/api/routes', {
			method: 'POST',
			body: { origin: { lat: origin.lat, lng: origin.lng }, destination: { lat: trip.destination.lat, lng: trip.destination.lng } },
			timeout: 90000,
			signal: controller.signal,
		});

		if (response === null || planController !== controller) {
			return;
		}

		el.options.replaceChildren();

		if (!response.ok) {
			showPlanProblem(response);
			return;
		}

		trip.plannedFrom = origin;
		renderPlan(response.data);
	}

	function showPlanProblem(response) {
		const { status, data } = response;
		const retry = { label: 'Try again', onClick: planRoute };

		if (status === 422 && data.reason === 'no_route') {
			showMessage({
				tone: 'warn',
				title: 'No route found for this vehicle',
				text: `${data.error} Routes can be ruled out by your vehicle's size, so check ${vehicle?.name ?? 'your vehicle'}'s dimensions are right.`,
				actions: vehicle ? [{ label: 'Check vehicle dimensions', href: vehicle.edit_url }] : [],
			});
		} else if (status === 422 && !vehicle) {
			showMessage({ tone: 'info', title: 'Add a vehicle first', text: data.error, actions: [{ label: 'Add a vehicle', href: '/vehicles/create' }] });
		} else if (status === 422) {
			showMessage({ tone: 'warn', title: 'This route can\'t be planned', text: Object.values(data.errors ?? {}).join(' ') || data.error });
		} else if (status === 503 && data.reason === 'not_configured') {
			showMessage({ title: 'Routing isn\'t set up', text: data.error });
		} else if (status === 503) {
			showMessage({ title: 'Routing is unavailable', text: data.error, actions: [retry] });
		} else if (status === 401) {
			showMessage({ title: 'You\'ve been signed out', text: 'Sign in again to plan routes.', actions: [{ label: 'Sign in', href: '/login' }] });
		} else if (status === 419) {
			showMessage({ title: 'This page has expired', text: 'Reload it to carry on.', actions: [{ label: 'Reload', onClick: () => location.reload() }] });
		} else if (status === 429) {
			showMessage({ tone: 'warn', title: 'Too many routes at once', text: data.error, actions: [retry] });
		} else if (status === 0) {
			showMessage({ title: data.reason === 'timeout' ? 'Routing is taking too long' : 'Waypoint can\'t be reached', text: data.error, actions: [retry] });
		} else {
			showMessage({ title: 'Something went wrong planning this route', text: 'Try again in a moment.', actions: [retry] });
		}
	}

	function renderPlan(plan) {
		trip.plan = plan;
		trip.saved.clear();

		const notice = plan.notices?.[0] ?? null;
		el.clearance.hidden = notice === null;
		el.clearanceText.textContent = notice ?? '';

		const lines = plan.options.map((option) => {
			try {
				return decodePolyline(option.polyline, option.polyline_precision ?? 6);
			} catch (error) {
				return [];
			}
		});

		el.options.replaceChildren(...plan.options.map((option, index) => routeCard(option, index)));
		const recommended = Math.max(0, plan.options.findIndex((option) => option.recommended));
		routeLayers?.setRoutes(lines, recommended);
		selectRoute(recommended);
		fitTrip();
	}

	function routeCard(option, index) {
		const card = templates.card.content.firstElementChild.cloneNode(true);
		const [classification, tone] = CLASSIFICATIONS[option.classification] ?? [option.classification, ''];
		const display = option.display;
		const isFastest = option.labels.includes('fastest');

		$('[data-route-icon]', card).textContent = ROUTE_ICONS[option.label] ?? '🔀';
		$('[data-route-title]', card).textContent = option.title;
		$('[data-route-recommended]', card).hidden = !option.recommended;

		const badge = $('[data-route-classification]', card);
		badge.textContent = classification;
		if (tone) {
			badge.classList.add(tone);
		}

		$('[data-route-duration]', card).textContent = display.duration;
		$('[data-route-delta]', card).textContent = isFastest ? '' : (display.vs_fastest ? `${display.vs_fastest} vs fastest` : 'About the same as fastest');

		const facts = [display.distance];
		if (display.max_grade !== null) {
			facts.push(`max grade ${display.max_grade}`, `${display.total_climb} climb`);
		} else {
			facts.push('grades not measured');
		}
		$('[data-route-facts]', card).replaceChildren(...facts.map((fact) => Object.assign(document.createElement('span'), { textContent: fact })));

		card.addEventListener('click', () => selectRoute(index));

		return card;
	}

	function selectRoute(index, { fromMap = false } = {}) {
		const option = trip.plan?.options[index];

		if (!option) {
			return;
		}

		trip.selected = index;
		routeLayers?.select(index);

		[...el.options.children].forEach((card, cardIndex) => card.setAttribute('aria-pressed', cardIndex === index ? 'true' : 'false'));

		if (fromMap) {
			el.options.children[index]?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}

		el.detail.hidden = false;
		el.whyText.textContent = option.explanation;
		el.whyWarnings.replaceChildren(...option.warnings.map((warning) => Object.assign(document.createElement('li'), { textContent: warning })));
		el.whyWarnings.hidden = option.warnings.length === 0;

		const elevation = option.elevation ?? {};
		const drawn = elevation.available && drawElevation(el.elevationChart, elevation.profile);
		el.elevationChart.toggleAttribute('hidden', !drawn);
		el.elevationRange.textContent = elevation.available ? `${option.display.lowest} – ${option.display.highest}` : '';
		el.elevationSummary.textContent = elevation.available
			? `Climbs ${option.display.total_climb} in all. Highest point ${option.display.highest}, lowest ${option.display.lowest}.`
			: 'Elevation isn\'t available for this route, so its grades aren\'t known.';

		renderSaveState();
	}

	/* --- Saving -------------------------------------------------------------- */

	function savedKey(option) {
		return `${option.label}|${option.polyline}`;
	}

	function renderSaveState() {
		const option = trip.plan?.options[trip.selected];
		const saved = option && trip.saved.has(savedKey(option));
		el.save.disabled = !option || saved;
		el.saveLabel.textContent = saved ? 'Saved' : 'Save route';
	}

	/** A name for where the user was, for the saved route: the nearest address, else the coordinates. */
	async function currentLocationLabel(origin) {
		const params = new URLSearchParams({ lat: origin.lat.toFixed(5), lng: origin.lng.toFixed(5) });
		const response = await api(`/api/places/reverse?${params}`, { timeout: 5000 });

		return response?.ok && response.data.result?.label ? `near ${response.data.result.label}` : `${origin.lat.toFixed(5)}, ${origin.lng.toFixed(5)}`;
	}

	el.save.addEventListener('click', async () => {
		const plan = trip.plan;
		const option = plan?.options[trip.selected];
		const origin = trip.plannedFrom;

		if (!option || !origin) {
			return;
		}

		el.save.disabled = true;
		el.saveLabel.textContent = 'Saving…';

		const response = await api('/api/saved-routes', {
			method: 'POST',
			timeout: 90000,
			body: {
				origin: { lat: origin.lat, lng: origin.lng, label: origin.current ? await currentLocationLabel(origin) : origin.label },
				destination: trip.destination,
				vehicle_id: plan.vehicle.id,
				label: option.label,
				polyline: option.polyline,
			},
		});

		if (response.ok) {
			trip.saved.set(savedKey(option), response.data.id);
			renderSaveState();
			window.Deck?.toast({
				kind: 'good',
				title: 'Route saved',
				text: response.data.name,
				actions: [{ label: 'View', onClick: () => location.assign(response.data.url) }],
			});
			return;
		}

		renderSaveState();
		showMessage({
			tone: response.status === 409 ? 'warn' : 'bad',
			title: 'The route wasn\'t saved',
			text: response.data.error ?? 'Try again in a moment.',
			actions: response.status === 409 ? [{ label: 'Plan it again', onClick: planRoute }] : [],
		});
	});

	/* --- Travel mode ------------------------------------------------------------ */

	function renderPersonality(personality) {
		el.travelMode.querySelectorAll('button[name="personality"]').forEach((button) => {
			button.setAttribute('aria-pressed', button.value === personality ? 'true' : 'false');
		});

		const isCustom = el.customModes.querySelector(`button[value="${CSS.escape(personality)}"]`) !== null;
		el.customToggle.setAttribute('aria-pressed', isCustom ? 'true' : 'false');
	}

	el.customToggle.addEventListener('click', () => {
		const open = el.customModes.hidden;
		el.customModes.hidden = !open;
		el.customToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
	});

	el.travelMode.addEventListener('submit', async (event) => {
		event.preventDefault();
		const personality = event.submitter?.value;
		const previous = trip.personality;

		if (!personality || personality === previous) {
			return;
		}

		trip.personality = personality;
		renderPersonality(personality);

		const response = await api('/preferences/travel-mode', { method: 'POST', body: { personality } });

		if (!response.ok) {
			trip.personality = previous;
			renderPersonality(previous);
			window.Deck?.toast({ kind: 'bad', title: 'Travel mode not saved', text: response.data.error ?? 'Try again in a moment.' });
			return;
		}

		if (trip.destination) {
			planRoute();
		}
	});

	/* --- Arriving with a trip --------------------------------------------------- */

	const toPlace = (place) => place && { lat: Number(place.lat), lng: Number(place.lng), label: place.label };
	trip.origin = toPlace(initial.from);
	trip.destination = toPlace(initial.to);
	syncTrip();

	if (trip.destination) {
		el.search.value = trip.destination.label;
		planRoute();
	} else if (initial.q) {
		el.search.value = initial.q;
		el.search.focus();
		search(initial.q);
	}
}
