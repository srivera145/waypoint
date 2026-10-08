/* The vehicle form: picking a type shows the dimensions that apply to it and fills in its
 * usual size; ticking "Towing a trailer" shows the trailer's.
 *
 * The server draws the same state for whatever type it was given, and reads only the
 * fields that apply, so with scripts off the form still posts and validates. It just does
 * not change shape until it comes back. Fields that do not apply are disabled as well as
 * hidden, so they neither post nor trip the browser's own validation.
 *
 * Each type's labels, fields and defaults (already in the user's units) arrive as JSON in
 * data-vehicle-types, from VehicleService::typesForForm().
 */
(() => {
	const form = document.querySelector('[data-vehicle-form]');

	if (!form) {
		return;
	}

	// namedItem(), never form.elements.length: the collection's own length property shadows
	// the input called "length", and assigning .value to a number fails without a sound.
	const control = (name) => form.elements.namedItem(name);
	const types = JSON.parse(form.dataset.vehicleTypes || '{}');
	const typeSelect = control('type');
	const trailerToggle = control('has_trailer');
	const nameInput = control('name');
	const trailerFields = ['trailer_length', 'trailer_weight'];
	let currentType = typeSelect.value;

	const show = (element, shown) => {
		element.hidden = !shown;
		element.querySelectorAll('input, select').forEach((control) => {
			control.disabled = !shown;
		});
	};

	const redraw = () => {
		const type = types[typeSelect.value];

		if (!type) {
			return;
		}

		const trailerOn = type.trailer === 'required' || (type.trailer === 'optional' && trailerToggle.checked);
		const applies = new Set(trailerOn ? type.fields.concat(trailerFields) : type.fields);

		form.querySelectorAll('[data-field]').forEach((field) => show(field, applies.has(field.dataset.field)));
		form.querySelector('[data-size-section]').hidden = type.fields.length === 0;
		form.querySelector('[data-no-dimensions]').hidden = type.fields.length !== 0;
		form.querySelector('[data-trailer-section]').hidden = type.trailer === 'none';
		form.querySelector('[data-trailer-fields]').hidden = !trailerOn;
		show(form.querySelector('[data-trailer-toggle]'), type.trailer === 'optional');
	};

	const fill = (names, defaults) => {
		names.forEach((name) => {
			if (control(name) && defaults[name] !== undefined) {
				control(name).value = defaults[name];
			}
		});
	};

	typeSelect.addEventListener('change', () => {
		const previous = types[currentType];
		const type = types[typeSelect.value];
		currentType = typeSelect.value;

		if (!type) {
			return;
		}

		// A name still reading as the old type's label ("RV") was ours, so it follows the type.
		if (previous && nameInput.value.trim() === previous.label) {
			nameInput.value = type.label;
		}

		trailerToggle.checked = type.trailer === 'required';
		fill(Object.keys(type.defaults), type.defaults);
		redraw();
	});

	trailerToggle.addEventListener('change', () => {
		const type = types[typeSelect.value];

		if (trailerToggle.checked && type) {
			fill(trailerFields.filter((name) => control(name).value.trim() === ''), type.defaults);
		}

		redraw();
	});
})();
