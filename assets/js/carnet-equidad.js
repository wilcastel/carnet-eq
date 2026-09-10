/**
 * Carnet Equidad public form.
 *
 * Submits the cédula to the plugin REST route and renders the states:
 * loading, not_found, error, found (radio list of policy options).
 *
 * Config is injected via wp_localize_script as `carnetEquidadConfig`:
 *   { restUrl, nonce, i18n: { ... } }
 */
(function () {
	'use strict';

	var config = window.carnetEquidadConfig || {};
	var i18n = config.i18n || {};

	function t(key, fallback) {
		return typeof i18n[key] === 'string' && i18n[key].length ? i18n[key] : fallback;
	}

	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function setStatus(statusEl, message, state) {
		if (!statusEl) {
			return;
		}
		statusEl.textContent = message || '';
		statusEl.hidden = !message;
		statusEl.setAttribute('data-state', state || '');
	}

	function clearResults(resultsEl) {
		if (resultsEl) {
			resultsEl.innerHTML = '';
			resultsEl.hidden = true;
		}
	}

	function formatValidity(from, to) {
		if (from && to) {
			return from + ' – ' + to;
		}
		return from || to || '—';
	}

	function renderOptions(resultsEl, data) {
		clearResults(resultsEl);
		if (!resultsEl) {
			return;
		}

		var options = Array.isArray(data.opciones) ? data.opciones : [];
		var wrapper = document.createElement('div');
		wrapper.className = 'carnet-equidad__options';

		if (data.asegurado) {
			var name = document.createElement('p');
			name.className = 'carnet-equidad__asegurado';
			name.textContent = data.asegurado;
			wrapper.appendChild(name);
		}

		var prompt = document.createElement('p');
		prompt.className = 'carnet-equidad__prompt';
		prompt.textContent = t('selectPrompt', 'Selecciona la póliza que deseas consultar:');
		wrapper.appendChild(prompt);

		var list = document.createElement('div');
		list.className = 'carnet-equidad__option-list';
		list.setAttribute('role', 'radiogroup');

		options.forEach(function (option, index) {
			var id = 'carnet-equidad-option-' + index;
			var row = document.createElement('label');
			row.className = 'carnet-equidad__option';
			row.setAttribute('for', id);

			var input = document.createElement('input');
			input.type = 'radio';
			input.name = 'carnet-equidad-option';
			input.id = id;
			input.value = String(option.id);
			if (index === 0) {
				input.checked = true;
			}

			var text = document.createElement('span');
			text.className = 'carnet-equidad__option-text';
			text.textContent =
				t('policy', 'Póliza') + ': ' + (option.poliza || '—') +
				'  ·  ' + t('order', 'Orden') + ': ' + (option.orden || '—') +
				'  ·  ' + t('validity', 'Vigencia') + ': ' +
				formatValidity(option.vigencia_desde, option.vigencia_hasta);

			row.appendChild(input);
			row.appendChild(text);
			list.appendChild(row);
		});

		wrapper.appendChild(list);

		var continueBtn = document.createElement('button');
		continueBtn.type = 'button';
		continueBtn.className = 'carnet-equidad__continue';
		continueBtn.textContent = t('continue', 'Continuar');
		wrapper.appendChild(continueBtn);

		var summary = document.createElement('div');
		summary.className = 'carnet-equidad__summary';
		summary.hidden = true;
		wrapper.appendChild(summary);

		continueBtn.addEventListener('click', function () {
			var checked = list.querySelector('input[name="carnet-equidad-option"]:checked');
			if (!checked) {
				return;
			}
			var selected = options[Number(checked.value)] || null;
			if (!selected) {
				return;
			}

			// TODO: next increment -> call /wp-json/carnet/v1/pdf with
			// { cedula, seleccion: selected.id } and stream the PDF download.
			console.log('[carnet-equidad] selected option', selected);

			summary.hidden = false;
			summary.innerHTML = '';
			var title = document.createElement('strong');
			title.textContent = t('chosen', 'Opción seleccionada');
			summary.appendChild(title);

			var dl = document.createElement('dl');
			[
				[t('policy', 'Póliza'), selected.poliza],
				[t('order', 'Orden'), selected.orden],
				[t('certificate', 'Certificado'), selected.certificado],
				[t('branch', 'Sucursal'), selected.sucursal],
				[t('validity', 'Vigencia'), formatValidity(selected.vigencia_desde, selected.vigencia_hasta)]
			].forEach(function (pair) {
				var dt = document.createElement('dt');
				dt.textContent = pair[0];
				var dd = document.createElement('dd');
				dd.textContent = pair[1] || '—';
				dl.appendChild(dt);
				dl.appendChild(dd);
			});
			summary.appendChild(dl);
		});

		resultsEl.appendChild(wrapper);
		resultsEl.hidden = false;
	}

	function handleResponse(root, httpStatus, data) {
		var statusEl = root.querySelector('[data-carnet-status]');
		var resultsEl = root.querySelector('[data-carnet-results]');
		data = data || {};

		if (httpStatus === 200 && data.status === 'found') {
			setStatus(statusEl, '', '');
			renderOptions(resultsEl, data);
			return;
		}

		clearResults(resultsEl);

		if (httpStatus === 200 && data.status === 'not_found') {
			setStatus(statusEl, t('notFound', 'No se encontró información para el documento ingresado.'), 'not-found');
			return;
		}

		if (httpStatus === 400) {
			setStatus(statusEl, t('invalid', 'Ingresa un número de documento válido (6 a 11 dígitos).'), 'invalid');
			return;
		}

		if (httpStatus === 429) {
			setStatus(statusEl, t('rateLimited', 'Has realizado demasiadas consultas. Espera unos minutos e inténtalo de nuevo.'), 'rate-limited');
			return;
		}

		setStatus(statusEl, t('error', 'No fue posible completar la consulta en este momento. Intenta nuevamente más tarde.'), 'error');
	}

	function bindForm(root) {
		var form = root.querySelector('[data-carnet-form]');
		var input = root.querySelector('[data-carnet-cedula]');
		var submit = root.querySelector('[data-carnet-submit]');
		var statusEl = root.querySelector('[data-carnet-status]');
		var resultsEl = root.querySelector('[data-carnet-results]');

		if (!form || !input) {
			return;
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var cedula = (input.value || '').replace(/\D+/g, '');
			if (cedula.length < 6 || cedula.length > 11) {
				clearResults(resultsEl);
				setStatus(statusEl, t('invalid', 'Ingresa un número de documento válido (6 a 11 dígitos).'), 'invalid');
				return;
			}

			clearResults(resultsEl);
			setStatus(statusEl, t('loading', 'Consultando…'), 'loading');
			if (submit) {
				submit.disabled = true;
			}

			fetch(config.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || ''
				},
				body: JSON.stringify({ cedula: cedula })
			})
				.then(function (response) {
					return response
						.json()
						.catch(function () {
							return {};
						})
						.then(function (data) {
							handleResponse(root, response.status, data);
						});
				})
				.catch(function () {
					clearResults(resultsEl);
					setStatus(statusEl, t('error', 'No fue posible completar la consulta en este momento. Intenta nuevamente más tarde.'), 'error');
				})
				.finally(function () {
					if (submit) {
						submit.disabled = false;
					}
				});
		});
	}

	onReady(function () {
		var roots = document.querySelectorAll('[data-carnet-equidad]');
		Array.prototype.forEach.call(roots, bindForm);
	});
})();
