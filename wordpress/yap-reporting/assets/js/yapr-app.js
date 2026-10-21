/*
 * YAP Reporting dashboards. No dependencies.
 * All data comes from the site's own /wp-json/yapr/v1 routes (cookie + nonce auth).
 * DOM is built with textContent/createElement only — API data is never inserted as HTML.
 */
(function () {
	'use strict';

	var BOOT = window.YAPR_BOOT || {};
	var T = BOOT.i18n || {};
	var SVGNS = 'http://www.w3.org/2000/svg';

	// ------------------------------------------------------------------ helpers

	function h(tag, attrs) {
		var el = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'text') { el.textContent = String(v); }
				else if (k === 'class') { el.className = v; }
				else if (k.indexOf('on') === 0) { el.addEventListener(k.slice(2), v); }
				else { el.setAttribute(k, v === true ? '' : String(v)); }
			});
		}
		for (var i = 2; i < arguments.length; i++) { append(el, arguments[i]); }
		return el;
	}
	function append(el, child) {
		if (child === null || child === undefined || child === false) { return; }
		if (Array.isArray(child)) { child.forEach(function (c) { append(el, c); }); return; }
		el.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
	}
	function s(tag, attrs) {
		var el = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, String(attrs[k])); });
		for (var i = 2; i < arguments.length; i++) {
			var c = arguments[i];
			if (c) { el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); }
		}
		return el;
	}
	function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }
	function num(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString(); }
	function pct(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%'; }
	function dur(sec) {
		if (sec === null || sec === undefined) { return '—'; }
		sec = Math.round(sec);
		var hrs = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), r = sec % 60;
		if (hrs) { return hrs + ' h ' + m + ' m'; }
		return m + ':' + (r < 10 ? '0' : '') + r;
	}
	function metricValue(m) { return m && m.available ? m.value : null; }
	function fmtMetric(m) {
		if (!m || !m.available) { return T.unavailable || 'Not available'; }
		if (m.unit === 'percent') { return pct(m.value); }
		if (m.unit === 'seconds') { return dur(m.value); }
		return num(m.value);
	}
	function levelText(level) {
		return { call: T.callLevel, attempt: T.attemptLevel, interaction: T.interactionLevel, message: T.messageLevel }[level] || '';
	}
	function sum() {
		var total = 0, any = false;
		for (var i = 0; i < arguments.length; i++) {
			var m = arguments[i];
			if (m && m.available && m.value !== null) { total += Number(m.value); any = true; }
		}
		return any ? { value: total, available: true, level: 'interaction' } : { value: null, available: false, reason: '' };
	}
	function qs(params) {
		var parts = [];
		Object.keys(params).forEach(function (k) {
			var v = params[k];
			if (v === null || v === undefined || v === '' || v === false) { return; }
			parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v === true ? 'true' : v));
		});
		return parts.length ? '?' + parts.join('&') : '';
	}
	// Sites without pretty permalinks have a REST base like /index.php?rest_route=/yapr/v1/: add to its query.
	function withQuery(base, params) {
		var q = qs(params || {});
		return q ? base + (base.indexOf('?') === -1 ? q : '&' + q.slice(1)) : base;
	}
	function api(path, params) {
		return fetch(withQuery(BOOT.rest + path, params), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': BOOT.nonce, 'Accept': 'application/json' }
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (body) {
				if (!res.ok) { throw new Error((body && body.message) || ('HTTP ' + res.status)); }
				return body;
			});
		});
	}
	var uid = 0;
	function nextId(prefix) { uid += 1; return 'yapr-' + prefix + '-' + uid; }

	// ------------------------------------------------------------------ charts (SVG, accessible)

	/**
	 * series: [{ name, values: [numbers|null], cls }]
	 * labels: category labels
	 */
	function chart(opts) {
		var W = 640, H = 240, padL = 44, padR = 12, padT = 12, padB = 34;
		var labels = opts.labels, series = opts.series, type = opts.type || BOOT.chartStyle || 'bars';
		var max = 0;
		series.forEach(function (se) { se.values.forEach(function (v) { if (v !== null && v > max) { max = v; } }); });
		if (opts.max) { max = opts.max; }
		if (max <= 0) { max = 1; }
		var nice = niceMax(max);
		var plotW = W - padL - padR, plotH = H - padT - padB;
		var n = Math.max(1, labels.length);
		var band = plotW / n;

		var svg = s('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'yapr-chart-svg', role: 'img', 'aria-label': opts.title, preserveAspectRatio: 'none' });
		// gridlines + y labels
		for (var g = 0; g <= 4; g++) {
			var gy = padT + plotH - (plotH * g / 4);
			svg.appendChild(s('line', { x1: padL, x2: W - padR, y1: gy, y2: gy, class: 'yapr-grid' }));
			svg.appendChild(s('text', { x: padL - 6, y: gy + 4, 'text-anchor': 'end', class: 'yapr-axis' }, formatAxis(nice * g / 4, opts.unit)));
		}
		// x labels (thinned)
		var step = Math.ceil(n / 10);
		labels.forEach(function (lab, i) {
			if (i % step !== 0 && i !== n - 1) { return; }
			svg.appendChild(s('text', { x: padL + band * i + band / 2, y: H - 12, 'text-anchor': 'middle', class: 'yapr-axis' }, String(lab)));
		});

		if (type === 'lines' && n > 1) {
			series.forEach(function (se, si) {
				var d = '';
				se.values.forEach(function (v, i) {
					if (v === null) { return; }
					var x = padL + band * i + band / 2, y = padT + plotH - (plotH * v / nice);
					d += (d === '' ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1) + ' ';
				});
				svg.appendChild(s('path', { d: d, class: 'yapr-line yapr-s' + si + (se.cls ? ' ' + se.cls : '') }));
			});
		} else {
			var groups = series.length;
			var inner = band * 0.72, bw = inner / groups;
			series.forEach(function (se, si) {
				se.values.forEach(function (v, i) {
					if (v === null) { return; }
					var bh = plotH * v / nice;
					var x = padL + band * i + (band - inner) / 2 + bw * si;
					var rect = s('rect', { x: x.toFixed(1), y: (padT + plotH - bh).toFixed(1), width: Math.max(1, bw - 1).toFixed(1), height: Math.max(0, bh).toFixed(1), class: 'yapr-bar yapr-s' + si + (se.cls ? ' ' + se.cls : '') });
					rect.appendChild(s('title', {}, labels[i] + ' — ' + se.name + ': ' + formatAxis(v, opts.unit)));
					svg.appendChild(rect);
				});
			});
		}
		return figure(opts.title, svg, labels, series, opts.unit, opts.note);
	}

	/** One-row heat strip (e.g. 24 hours). */
	function heatStrip(title, labels, values, note) {
		var W = 640, H = 70, cell = W / labels.length, max = Math.max.apply(null, values.concat([1]));
		var svg = s('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'yapr-chart-svg yapr-heat', role: 'img', 'aria-label': title, preserveAspectRatio: 'none' });
		values.forEach(function (v, i) {
			var level = v === 0 ? 0 : Math.min(5, Math.ceil(5 * v / max));
			var r = s('rect', { x: (cell * i + 1).toFixed(1), y: 4, width: (cell - 2).toFixed(1), height: 40, rx: 3, class: 'yapr-heat-' + level });
			r.appendChild(s('title', {}, labels[i] + ': ' + v));
			svg.appendChild(r);
			if (i % 3 === 0) { svg.appendChild(s('text', { x: (cell * i + cell / 2).toFixed(1), y: 62, 'text-anchor': 'middle', class: 'yapr-axis' }, String(labels[i]))); }
		});
		return figure(title, svg, labels, [{ name: T.calls || 'Calls', values: values }], null, note);
	}

	function figure(title, svg, labels, series, unit, note) {
		var tableId = nextId('tbl');
		var table = h('table', { class: 'yapr-table yapr-chart-table', id: tableId, hidden: true },
			h('caption', { class: 'screen-reader-text', text: title }),
			h('thead', null, h('tr', null, h('th', { scope: 'col', text: '' }), series.map(function (se) { return h('th', { scope: 'col', text: se.name }); }))),
			h('tbody', null, labels.map(function (lab, i) {
				return h('tr', null, h('th', { scope: 'row', text: String(lab) }), series.map(function (se) { return h('td', { text: se.values[i] === null ? '—' : formatAxis(se.values[i], unit) }); }));
			}))
		);
		var toggle = h('button', { type: 'button', class: 'yapr-link', 'aria-controls': tableId, 'aria-expanded': 'false', text: T.showTable || 'Show as table' });
		toggle.addEventListener('click', function () {
			var open = table.hidden;
			table.hidden = !open;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			toggle.textContent = open ? (T.hideTable || 'Hide table') : (T.showTable || 'Show as table');
		});
		var legend = series.length > 1 ? h('ul', { class: 'yapr-legend' }, series.map(function (se, si) { return h('li', { class: 'yapr-s' + si + (se.cls ? ' ' + se.cls : '') }, h('span', { class: 'yapr-swatch', 'aria-hidden': 'true' }), se.name); })) : null;
		return h('figure', { class: 'yapr-chart' },
			h('figcaption', null, h('span', { class: 'yapr-chart-title', text: title }), toggle),
			legend, svg, note ? h('p', { class: 'yapr-chart-note', text: note }) : null, table);
	}

	function niceMax(v) {
		var p = Math.pow(10, Math.floor(Math.log10(v)));
		var f = v / p;
		var n = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10;
		return n * p;
	}
	function formatAxis(v, unit) {
		if (unit === 'percent') { return Math.round(v) + '%'; }
		if (unit === 'seconds') { return dur(v); }
		return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 });
	}

	// ------------------------------------------------------------------ app shell

	function App(root) {
		this.root = root;
		this.mode = root.getAttribute('data-yapr-mode');
		try { this.cfg = JSON.parse(root.getAttribute('data-yapr-config') || '{}'); } catch (e) { this.cfg = {}; }
		var allowed = Object.keys(BOOT.areas || {});
		this.areaChoices = (this.cfg.areas || []).map(String).filter(function (id) { return allowed.indexOf(id) !== -1; });
		this.state = {
			areas: '',
			period: this.cfg.period || '30d',
			start_date: this.cfg.start_date || '',
			end_date: this.cfg.end_date || '',
			compare: !!this.cfg.comparison,
			view: this.cfg.view || 'overview',
			tab: this.cfg.tab || 'calls',
			granularity: 'day'
		};
		if (this.state.start_date && this.state.end_date) { this.state.period = 'custom'; }
		this.calls = { page: 1, sort: 'desc', filters: {} };
		this.render();
	}

	App.prototype.baseParams = function () {
		var everyArea = this.areaChoices.length === Object.keys(BOOT.areas || {}).length;
		// "All my Areas": let the server use the full grant, which can include region-wide activity
		// (meeting searches, JFT/SPAD, texts) that YAP does not tie to any Area.
		var p = { areas: this.state.areas || (everyArea ? '' : this.areaChoices.join(',')) };
		if (this.state.period === 'custom') { p.start_date = this.state.start_date; p.end_date = this.state.end_date; }
		else { p.period = this.state.period; }
		return p;
	};

	App.prototype.render = function () {
		var self = this;
		clear(this.root);
		this.root.classList.add('is-ready');
		this.status = h('p', { class: 'yapr-status', role: 'status', 'aria-live': 'polite' });
		this.body = h('div', { class: 'yapr-body' });
		append(this.root, [this.filters(), this.status, this.body]);
		if (this.mode === 'admin') { this.renderAdmin(); } else { this.renderReport(); }
		window.addEventListener('beforeprint', function () { self.root.classList.add('is-printing'); });
	};

	App.prototype.filters = function () {
		var self = this;
		var form = h('form', { class: 'yapr-filters' });

		if (this.areaChoices.length > 1) {
			var aid = nextId('area');
			var sel = h('select', { id: aid, onchange: function () { self.state.areas = sel.value; self.reload(); } },
				h('option', { value: '', text: T.allAreas || 'All my Areas' }),
				this.areaChoices.map(function (id) { return h('option', { value: id, text: BOOT.areas[id] }); }));
			append(form, h('div', { class: 'yapr-field' }, h('label', { for: aid, text: T.area }), sel));
		}

		var pid = nextId('period');
		var psel = h('select', { id: pid });
		Object.keys(BOOT.periods || {}).forEach(function (k) { append(psel, h('option', { value: k, text: BOOT.periods[k], selected: k === self.state.period })); });
		append(psel, h('option', { value: 'custom', text: T.custom, selected: self.state.period === 'custom' }));
		var custom = h('span', { class: 'yapr-custom', hidden: self.state.period !== 'custom' });
		var fromId = nextId('from'), toId = nextId('to');
		var from = h('input', { type: 'date', id: fromId, value: self.state.start_date });
		var to = h('input', { type: 'date', id: toId, value: self.state.end_date });
		append(custom, [h('label', { for: fromId, text: T.from }), from, h('label', { for: toId, text: T.to }), to, h('button', { type: 'submit', class: 'yapr-btn', text: T.apply })]);
		psel.addEventListener('change', function () {
			self.state.period = psel.value;
			custom.hidden = psel.value !== 'custom';
			if (psel.value !== 'custom') { self.reload(); } else { from.focus(); }
		});
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (psel.value === 'custom') {
				if (!from.value || !to.value) { from.focus(); return; }
				self.state.start_date = from.value;
				self.state.end_date = to.value;
			}
			self.reload();
		});
		append(form, h('div', { class: 'yapr-field' }, h('label', { for: pid, text: T.period }), psel, custom));

		if (this.mode !== 'admin') {
			var cid = nextId('cmp');
			var cmp = h('input', { type: 'checkbox', id: cid, checked: self.state.compare, onchange: function () { self.state.compare = cmp.checked; self.reload(); } });
			append(form, h('div', { class: 'yapr-field yapr-check' }, cmp, h('label', { for: cid, text: T.compare })));
		}

		this.exportSlot = h('div', { class: 'yapr-export' });
		append(form, this.exportSlot);
		return form;
	};

	App.prototype.exportLinks = function (types) {
		clear(this.exportSlot);
		if (!BOOT.can || !BOOT.can.export || this.cfg.export === false) { return; }
		var self = this;
		types.forEach(function (t) {
			var p = Object.assign({ action: 'yapr_export', type: t[0], _yapr_nonce: BOOT.exportNonce }, self.baseParams());
			append(self.exportSlot, h('a', { class: 'yapr-btn yapr-btn-quiet', href: withQuery(BOOT.exportUrl, p), text: t[1] }));
		});
		var printable = Object.assign({ action: 'yapr_export', type: types[0][0], format: 'html', _yapr_nonce: BOOT.exportNonce }, self.baseParams());
		append(self.exportSlot, h('a', { class: 'yapr-btn yapr-btn-quiet', href: withQuery(BOOT.exportUrl, printable), target: '_blank', rel: 'noopener', text: T.print }));
	};

	App.prototype.reload = function () {
		if (this.mode === 'admin') { this.calls.page = 1; this.renderAdmin(); } else { this.renderReport(); }
	};

	App.prototype.busy = function (on, msg) {
		this.root.setAttribute('aria-busy', on ? 'true' : 'false');
		this.status.textContent = on ? (T.loading || 'Loading…') : (msg || '');
	};

	App.prototype.fail = function (err) {
		this.busy(false);
		clear(this.body);
		append(this.body, h('div', { class: 'yapr-error', role: 'alert' }, h('p', { text: (T.errorPrefix || 'Error:') + ' ' + err.message })));
	};

	// ------------------------------------------------------------------ report mode

	App.prototype.renderReport = function () {
		var self = this, view = this.state.view, cfg = this.cfg;
		var want = function (v) { return view === 'all' || view === v; };
		var p = this.baseParams();
		var jobs = {
			summary: api('report', Object.assign({ view: 'summary', compare: self.state.compare }, p))
		};
		if (cfg.charts !== false) {
			if (want('overview') || want('calls') || want('trends') || want('voicemail') || want('meetings') || want('meditations')) {
				jobs.trends = api('report', Object.assign({ view: 'trends', granularity: self.state.granularity }, p));
			}
			if (want('calls') || want('overview')) { jobs.dist = api('report', Object.assign({ view: 'distributions' }, p)); }
		}
		if (want('sms') || want('overview')) { jobs.sms = api('report', Object.assign({ view: 'sms-summary' }, p)); }
		if (want('meetings')) { jobs.meetings = api('report', Object.assign({ view: 'meeting-lookups' }, p)); }
		if (want('meditations')) { jobs.meditations = api('report', Object.assign({ view: 'meditations' }, p)); }

		this.busy(true);
		this.exportLinks([['summary', T.export], ['trends', 'Trends CSV'], ['hours', 'Hours CSV'], ['meetings', 'Meetings CSV']]);
		var keys = Object.keys(jobs);
		Promise.all(keys.map(function (k) { return jobs[k]; })).then(function (results) {
			var d = {};
			keys.forEach(function (k, i) { d[k] = results[i]; });
			self.busy(false);
			self.drawReport(d, want);
		}).catch(function (e) { self.fail(e); });
	};

	App.prototype.drawReport = function (d, want) {
		var self = this, body = this.body, cfg = this.cfg;
		clear(body);
		var m = d.summary.data.metrics || {};
		var prev = d.summary.data.previous_period ? d.summary.data.previous_period.metrics : null;

		append(body, this.freshness(d.summary));
		var gap = m.no_volunteer_response_logged_calls;
		if (gap && gap.available && gap.value) {
			append(body, h('div', { class: 'yapr-alert', role: 'alert' },
				h('p', null, h('strong', { text: num(gap.value) + ' caller(s) were never connected to a volunteer. ' }),
					'YAP dialed volunteers but logged no response from any volunteer phone, and no volunteer joined the call. This usually means YAP\u2019s volunteer webhooks are failing, not that volunteers ignored the calls. Check the Twilio Console (Monitor \u2192 Errors) and YAP\u2019s error log, and place a test call.')));
		}

		// KPI cards
		var cards = [
			['total_calls', T.calls || 'Total calls', m.total_calls],
			['volunteer_routed_calls', 'Volunteer calls', m.volunteer_routed_calls],
			['answered_calls', T.answered || 'Calls answered', m.answered_calls],
			['answer_rate', 'Answer rate', m.answer_rate],
			['volunteer_calls_to_voicemail', T.voicemail || 'Voicemails', m.volunteer_calls_to_voicemail],
			['forwarded_to_helpline_calls', 'Forwarded', m.forwarded_to_helpline_calls],
			['no_volunteer_response_logged_calls', 'Caller never connected (no volunteer response logged)', m.no_volunteer_response_logged_calls],
			['routed_outcome_not_recorded_calls', 'Result not recorded by YAP', m.routed_outcome_not_recorded_calls],
			['meeting_lookup_calls', 'Meeting lookups (phone)', m.meeting_lookup_calls],
			['sms_meeting_lookups', 'Meeting lookups (text)', m.sms_meeting_lookups],
			['sms_volunteer_requests', 'Volunteer requests (text)', m.sms_volunteer_requests],
			['jft_calls', 'Just for Today (phone)', m.jft_calls],
			['jft_sms', 'Just for Today (text)', m.jft_sms],
			['spad_calls', 'SPAD (phone)', m.spad_calls],
			['spad_sms', 'SPAD (text)', m.spad_sms]
		];
		var grid = h('div', { class: 'yapr-kpis' });
		cards.forEach(function (c) {
			var metric = c[2];
			if ((c[0] === 'forwarded_to_helpline_calls' || c[0] === 'routed_outcome_not_recorded_calls' || c[0] === 'no_volunteer_response_logged_calls') && !(metric && metric.available && metric.value)) { return; }
			var p = prev ? prev[c[0]] : null;
			var delta = null;
			if (p && metric && metric.available && p.available && metric.value !== null && p.value !== null) {
				var diff = Number(metric.value) - Number(p.value);
				delta = h('span', { class: 'yapr-delta' }, (diff > 0 ? '+' : diff < 0 ? '−' : '±') + (metric.unit === 'percent' ? Math.abs(diff).toFixed(1) + ' pts' : Math.abs(diff).toLocaleString()), h('span', { class: 'screen-reader-text', text: ' ' + (T.previousPeriod || 'previous period') }));
			}
			append(grid, h('div', { class: 'yapr-kpi yapr-kpi-' + c[0] + (metric && metric.available ? '' : ' is-unavailable'), title: metric && !metric.available ? (metric.reason || '') : levelText(metric && metric.level) },
				h('span', { class: 'yapr-kpi-label', text: c[1] }),
				h('span', { class: 'yapr-kpi-value', text: fmtMetric(metric) }),
				c[0] === 'answer_rate' && metric && metric.available && metric.value !== null ? h('span', { class: 'yapr-meter', 'aria-hidden': 'true' }, h('span', { style: 'width:' + Math.max(0, Math.min(100, metric.value)) + '%' })) : null,
				delta));
		});
		append(body, h('section', { class: 'yapr-section', 'aria-label': T.overview || 'Overview' }, grid));
		if (this.state.areas || this.areaChoices.length < Object.keys(BOOT.areas || {}).length) {
			append(body, h('p', { class: 'yapr-chart-note', text: 'YAP does not tie meeting searches, Just for Today, SPAD or text messages to an Area, so they usually show 0 for a single Area. Choose \u201cAll my Areas\u201d to see them.' }));
		}

		var charts = h('div', { class: 'yapr-charts' });
		if (cfg.charts !== false && d.trends) {
			var series = d.trends.data.series || [];
			var labels = series.map(function (r) { return shortBucket(r.bucket); });
			var col = function (k) { return series.map(function (r) { return r[k] === undefined ? null : r[k]; }); };
			if (want('overview') || want('calls') || want('trends')) {
				append(charts, chart({ title: 'Daily call volume', labels: labels, series: [{ name: T.calls || 'Calls', values: col('total_calls') }, { name: 'Volunteer calls', values: col('volunteer_routed_calls') }, { name: T.answered || 'Answered', values: col('answered_calls'), cls: 'yapr-good' }] }));
				append(charts, chart({ title: 'Answer rate', labels: labels, type: 'lines', unit: 'percent', max: 100, series: [{ name: 'Answer rate', values: col('answer_rate'), cls: 'yapr-good' }] }));
			}
			if (want('voicemail') || want('trends')) {
				append(charts, chart({ title: 'Voicemail', labels: labels, series: [{ name: T.voicemail || 'Voicemail', values: col('voicemail_calls') }] }));
			}
			if (want('meetings') || want('trends')) {
				append(charts, chart({ title: 'Meeting lookups', labels: labels, series: [{ name: 'By phone', values: col('meeting_lookup_actions') }, { name: 'By text', values: col('sms_meeting_lookups') }] }));
			}
			if (want('meditations') || want('trends')) {
				append(charts, chart({ title: 'Just for Today and SPAD', labels: labels, series: [{ name: 'JFT', values: col('jft'), cls: 'yapr-good' }, { name: 'SPAD', values: col('spad'), cls: 'yapr-muted-series' }], note: 'Area-attributed requests only.' }));
			}
		}
		if (cfg.charts !== false && (want('overview') || want('calls'))) {
			var cats = [
				['Asked for a volunteer', metricValue(m.volunteer_requested_calls)],
				['Meeting lookup', metricValue(m.meeting_lookup_calls)],
				['Voicemail', metricValue(m.voicemail_calls)],
				['Ended at menu', metricValue(m.ended_before_selection_calls)]
			];
			append(charts, chart({ title: 'Calls by category', labels: cats.map(function (c) { return c[0]; }), series: [{ name: T.calls || 'Calls', values: cats.map(function (c) { return c[1]; }) }], note: 'A call can appear in more than one category.' }));
		}
		if (cfg.charts !== false && d.dist) {
			var dist = d.dist.data;
			append(charts, heatStrip('Calls by hour of day (' + (dist.timezone || BOOT.timezone) + ')', dist.by_hour.map(function (r) { return r.hour; }), dist.by_hour.map(function (r) { return r.calls; })));
			append(charts, chart({ title: 'Calls by weekday', labels: dist.by_weekday.map(function (r) { return r.name.slice(0, 3); }), series: [{ name: T.calls || 'Calls', values: dist.by_weekday.map(function (r) { return r.calls; }) }, { name: T.answered || 'Answered', values: dist.by_weekday.map(function (r) { return r.answered_calls; }), cls: 'yapr-good' }] }));
		}
		if (d.sms) {
			var bt = d.sms.data.by_event_type || {};
			var smsRows = [['Meeting lookups', bt.MEETING_SEARCH_SMS], ['Volunteer requests', bt.VOLUNTEER_SEARCH_SMS], ['Just for Today', bt.JFT_LOOKUP_SMS], ['Spiritual Principle a Day', bt.SPAD_LOOKUP_SMS]];
			append(body, h('section', { class: 'yapr-section' }, h('h3', { text: T.sms || 'Text messages' }),
				h('div', { class: 'yapr-scroll' }, h('table', { class: 'yapr-table' },
					h('thead', null, h('tr', null, h('th', { scope: 'col', text: 'Request' }), h('th', { scope: 'col', text: 'Texts' }))),
					h('tbody', null, smsRows.map(function (r) { return h('tr', null, h('th', { scope: 'row', text: r[0] }), h('td', { class: 'num', text: num(r[1] || 0) })); }))))));
		}
		if (cfg.charts !== false && d.sms) {
			var daily = d.sms.data.daily || [];
			append(charts, chart({ title: 'Text messages', labels: daily.map(function (r) { return r.date.slice(5); }), series: [{ name: T.sms || 'Texts', values: daily.map(function (r) { return r.sms_events; }) }] }));
		}
		if (prev && cfg.charts !== false) {
			var keys = ['total_calls', 'volunteer_routed_calls', 'answered_calls', 'meeting_lookup_calls', 'sms_meeting_lookups', 'sms_volunteer_requests', 'jft_sms', 'spad_sms'];
			var names = ['Calls', 'Volunteer', 'Answered', 'Mtg phone', 'Mtg text', 'Vol text', 'JFT text', 'SPAD text'];
			append(charts, chart({ title: 'Compared with the previous period', labels: names, series: [{ name: T.thisPeriod || 'This period', values: keys.map(function (k) { return metricValue(m[k]); }) }, { name: T.previousPeriod || 'Previous period', values: keys.map(function (k) { return metricValue(prev[k]); }), cls: 'yapr-muted-series' }] }));
		}
		if (charts.childNodes.length) { append(body, charts); }

		if (d.meetings) { append(body, this.meetingsTable(d.meetings.data)); }
		if (d.meditations) { append(body, this.meditationsTable(d.meditations.data)); }
		if (cfg.tables !== false) { append(body, this.metricsTable(m, prev)); }
		if (this.state.view === 'trends') { append(body, this.granularityPicker()); }
	};

	function shortBucket(b) {
		if (/^\d{4}-\d{2}-\d{2}$/.test(b)) { return b.slice(5); }
		if (b.charAt(0) === 'W') { return b.slice(6); }
		return b;
	}

	App.prototype.granularityPicker = function () {
		var self = this, id = nextId('gran');
		var sel = h('select', { id: id, onchange: function () { self.state.granularity = sel.value; self.renderReport(); } },
			['day', 'week', 'month', 'quarter', 'year'].map(function (g) { return h('option', { value: g, text: g, selected: g === self.state.granularity }); }));
		return h('p', { class: 'yapr-field' }, h('label', { for: id, text: 'Group by' }), sel);
	};

	App.prototype.freshness = function (res) {
		var range = (res.meta && res.meta.range) || {};
		var parts = [];
		if (range.start_date) { parts.push(range.start_date + ' – ' + range.end_date); }
		parts.push((T.tz || 'Times shown in') + ' ' + (range.timezone || BOOT.timezone));
		if (res.fetched_at) { parts.push((T.updated || 'Updated') + ' ' + new Date(res.fetched_at * 1000).toLocaleString()); }
		var names = res.areas ? Object.keys(res.areas).map(function (k) { return res.areas[k]; }) : [];
		return h('div', { class: 'yapr-fresh' },
			names.length ? h('p', { class: 'yapr-fresh-areas', text: names.join(', ') }) : null,
			h('p', { class: 'yapr-fresh-meta', text: parts.join('  |  ') }),
			res.stale ? h('p', { class: 'yapr-stale', role: 'alert', text: T.stale }) : null,
			res.meta && res.meta.answered_definition ? h('p', { class: 'yapr-fresh-meta', text: (T.answered || 'Answered') + ': ' + res.meta.answered_definition }) : null);
	};

	App.prototype.metricsTable = function (m, prev) {
		var rows = Object.keys(m).map(function (k) {
			var x = m[k];
			return h('tr', { class: x.available ? '' : 'is-unavailable' },
				h('th', { scope: 'row', text: k.replace(/_/g, ' ') }),
				h('td', { class: 'num', text: fmtMetric(x) }),
				prev ? h('td', { class: 'num', text: fmtMetric(prev[k]) }) : null,
				h('td', { class: 'yapr-muted', text: x.available ? levelText(x.level) : (x.reason || '') }));
		});
		return h('details', { class: 'yapr-section yapr-all-metrics' },
			h('summary', { text: 'All figures' }),
			h('div', { class: 'yapr-scroll' }, h('table', { class: 'yapr-table' },
				h('thead', null, h('tr', null, h('th', { scope: 'col', text: 'Figure' }), h('th', { scope: 'col', text: T.thisPeriod || 'This period' }), prev ? h('th', { scope: 'col', text: T.previousPeriod }) : null, h('th', { scope: 'col', text: 'What it counts' }))),
				h('tbody', null, rows))));
	};

	App.prototype.meetingsTable = function (d) {
		var rows = (d.by_service_body || []).map(function (r) {
			return h('tr', null, h('th', { scope: 'row', text: r.service_body_id === null ? 'Unassigned' : (BOOT.areas[String(r.service_body_id)] || ('#' + r.service_body_id)) }), h('td', { class: 'num', text: num(r.voice_actions) }), h('td', { class: 'num', text: num(r.sms_lookups) }));
		});
		return h('section', { class: 'yapr-section' }, h('h3', { text: T.meetings || 'Meeting lookups' }),
			h('div', { class: 'yapr-scroll' }, h('table', { class: 'yapr-table' }, h('thead', null, h('tr', null, h('th', { scope: 'col', text: T.area }), h('th', { scope: 'col', text: 'By phone' }), h('th', { scope: 'col', text: 'By text' }))), h('tbody', null, rows))),
			d.attribution_note ? h('p', { class: 'yapr-chart-note', text: d.attribution_note }) : null);
	};

	App.prototype.meditationsTable = function (d) {
		var row = function (label, blk) {
			if (!blk) { return null; }
			return h('tr', null, h('th', { scope: 'row', text: label }), ['jft_voice', 'jft_sms', 'spad_voice', 'spad_sms'].map(function (k) { return h('td', { class: 'num', text: fmtMetric(blk[k]) }); }));
		};
		return h('section', { class: 'yapr-section' }, h('h3', { text: T.meditations || 'Meditations' }),
			h('div', { class: 'yapr-scroll' }, h('table', { class: 'yapr-table' },
				h('thead', null, h('tr', null, h('th', { scope: 'col', text: '' }), ['JFT by phone', 'JFT by text', 'SPAD by phone', 'SPAD by text'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
				h('tbody', null, row('Selected Areas', d.area_attributed), row('Whole phone system', d.installation_wide)))),
			h('p', { class: 'yapr-chart-note', text: (d.attribution_note || '') + ' ' + (d.installation_note || '') }));
	};

	// ------------------------------------------------------------------ phoneline admin mode

	App.prototype.renderAdmin = function () {
		var self = this;
		clear(this.body);
		var tabs = [['calls', T.calls || 'Calls']];
		if (BOOT.can && BOOT.can.volunteers) { tabs.push(['volunteers', T.volunteers || 'Volunteers']); }
		if (tabs.length > 1) {
			var list = h('div', { class: 'yapr-tabs', role: 'tablist' });
			tabs.forEach(function (t) {
				append(list, h('button', { type: 'button', role: 'tab', class: 'yapr-tab', 'aria-selected': self.state.tab === t[0] ? 'true' : 'false', text: t[1], onclick: function () { self.state.tab = t[0]; self.renderAdmin(); } }));
			});
			append(this.body, list);
		}
		this.panel = h('div', { class: 'yapr-panel', role: tabs.length > 1 ? 'tabpanel' : null });
		append(this.body, this.panel);
		if (this.state.tab === 'volunteers' && BOOT.can.volunteers) { this.loadVolunteers(); } else { this.loadCalls(); }
	};

	App.prototype.loadCalls = function () {
		var self = this, f = this.calls.filters;
		this.exportLinks([['calls', T.export]]);
		var params = Object.assign({}, this.baseParams(), {
			page: this.calls.page, per_page: 25, sort: this.calls.sort,
			category: f.category, answered: f.answered, voicemail: f.voicemail,
			volunteer_id: f.volunteer_id, min_duration: f.min_duration, max_duration: f.max_duration
		});
		this.busy(true);
		var volunteers = BOOT.can.volunteers && !this.volunteerList ? api('volunteers', this.baseParams()).catch(function () { return null; }) : Promise.resolve(null);
		Promise.all([api('calls', params), volunteers]).then(function (r) {
			if (r[1] && r[1].data) { self.volunteerList = r[1].data.volunteers || []; }
			self.busy(false);
			self.drawCalls(r[0]);
		}).catch(function (e) { self.fail(e); });
	};

	App.prototype.callFilters = function () {
		var self = this, f = this.calls.filters;
		var mk = function (label, key, options) {
			var id = nextId(key);
			var sel = h('select', { id: id, onchange: function () { f[key] = sel.value === '' ? undefined : sel.value; self.calls.page = 1; self.loadCalls(); } },
				options.map(function (o) { return h('option', { value: o[0], text: o[1], selected: String(f[key] || '') === o[0] }); }));
			return h('div', { class: 'yapr-field' }, h('label', { for: id, text: label }), sel);
		};
		var any = T.any || 'Any';
		var row = h('div', { class: 'yapr-filters yapr-subfilters' },
			mk(T.category || 'Type', 'category', [['', any], ['volunteer', 'Volunteer'], ['meeting_lookup', 'Meeting lookup'], ['meditation', 'Meditation'], ['voicemail', 'Voicemail'], ['dialback', 'Dialback'], ['menu_only', 'Ended at menu']]),
			mk(T.answered || 'Answered', 'answered', [['', any], ['true', T.answered || 'Answered'], ['false', T.notAnswered || 'Not answered']]),
			mk(T.voicemail || 'Voicemail', 'voicemail', [['', any], ['true', 'Yes'], ['false', 'No']]));
		if (this.volunteerList && this.volunteerList.length) {
			append(row, mk(T.volunteer || 'Volunteer', 'volunteer_id', [['', any]].concat(this.volunteerList.map(function (v) { return [v.volunteer_id, v.name || v.volunteer_id]; }))));
		}
		['min_duration', 'max_duration'].forEach(function (k) {
			var id = nextId(k);
			var inp = h('input', { type: 'number', min: 0, max: 86400, id: id, value: f[k] || '', class: 'yapr-num' });
			inp.addEventListener('change', function () { f[k] = inp.value === '' ? undefined : inp.value; self.calls.page = 1; self.loadCalls(); });
			append(row, h('div', { class: 'yapr-field' }, h('label', { for: id, text: k === 'min_duration' ? T.minDur : T.maxDur }), inp));
		});
		return row;
	};

	App.prototype.drawCalls = function (res) {
		var self = this, panel = this.panel, showVol = BOOT.can.volunteers;
		clear(panel);
		append(panel, this.freshness({ meta: res.meta, fetched_at: res.fetched_at, areas: res.areas }));
		append(panel, this.callFilters());
		var calls = res.calls || [];
		if (!calls.length) { append(panel, h('p', { class: 'yapr-empty', text: T.empty })); return; }

		var sortBtn = h('button', { type: 'button', class: 'yapr-sort', 'aria-label': 'Sort by date', text: 'Date ' + (this.calls.sort === 'desc' ? '↓' : '↑'), onclick: function () { self.calls.sort = self.calls.sort === 'desc' ? 'asc' : 'desc'; self.calls.page = 1; self.loadCalls(); } });
		var head = h('tr', null, h('th', { scope: 'col', 'aria-sort': this.calls.sort === 'desc' ? 'descending' : 'ascending' }, sortBtn),
			['Time', T.area, T.category, 'Outcome', T.answered].map(function (t) { return h('th', { scope: 'col', text: t }); }),
			showVol ? h('th', { scope: 'col', text: T.volunteer }) : null,
			['Call length', 'Talk time', T.voicemail, T.meetings, ''].map(function (t) { return h('th', { scope: 'col', text: t }); }));
		var tbody = h('tbody');
		calls.forEach(function (c) {
			var detailRow = h('tr', { class: 'yapr-detail-row', hidden: true }, h('td', { colspan: showVol ? 12 : 11 }));
			var btn = h('button', { type: 'button', class: 'yapr-link', 'aria-expanded': 'false', text: T.details });
			btn.addEventListener('click', function () { self.toggleDetail(c.id, btn, detailRow); });
			append(tbody, h('tr', null,
				h('td', { text: c.date }), h('td', { text: c.time }), h('td', { text: c.area_name }),
				h('td', { text: label(c.category) }), h('td', { text: label(c.outcome) }),
				h('td', { text: c.answered ? (T.answered || 'Yes') : '—' }),
				showVol ? h('td', { text: c.answered_by ? (c.answered_by.name || c.answered_by.volunteer_id) : '—' }) : null,
				h('td', { class: 'num', text: dur(c.duration_seconds) }), h('td', { class: 'num', text: dur(c.conversation_seconds) }),
				h('td', { text: c.voicemail ? 'Yes' : '—' }), h('td', { class: 'num', text: num(c.meeting_lookups) }), h('td', null, btn)));
			append(tbody, detailRow);
		});
		append(panel, h('div', { class: 'yapr-scroll' }, h('table', { class: 'yapr-table yapr-calls' }, h('thead', null, head), tbody)));
		append(panel, this.pager(res.pagination || {}));
	};

	function label(v) { return String(v || '').replace(/_/g, ' '); }
	function volLabel(v) {
		if (v.name) { return v.name + (v.number_hint ? ' (' + v.number_hint + ')' : ''); }
		return v.number_hint ? 'Unnamed ' + v.number_hint : v.volunteer_id;
	}

	App.prototype.pager = function (p) {
		var self = this, total = p.total_pages || 1, page = p.page || 1;
		return h('nav', { class: 'yapr-pager', 'aria-label': 'Pages' },
			h('button', { type: 'button', class: 'yapr-btn yapr-btn-quiet', disabled: page <= 1, text: T.prev, onclick: function () { self.calls.page = page - 1; self.loadCalls(); } }),
			h('span', { text: (T.page || 'Page') + ' ' + page + ' ' + (T.of || 'of') + ' ' + total + '  (' + num(p.total) + ')' }),
			h('button', { type: 'button', class: 'yapr-btn yapr-btn-quiet', disabled: page >= total, text: T.next, onclick: function () { self.calls.page = page + 1; self.loadCalls(); } }));
	};

	App.prototype.toggleDetail = function (id, btn, row) {
		var cell = row.firstChild;
		if (!row.hidden) { row.hidden = true; btn.setAttribute('aria-expanded', 'false'); return; }
		row.hidden = false;
		btn.setAttribute('aria-expanded', 'true');
		clear(cell);
		append(cell, h('p', { role: 'status', text: T.loading }));
		api('calls/' + encodeURIComponent(id)).then(function (res) {
			var c = res.call;
			clear(cell);
			append(cell, h('ol', { class: 'yapr-timeline' }, (c.events || []).map(function (e) {
				return h('li', { class: 'yapr-leg-' + e.leg },
					h('span', { class: 'yapr-offset', text: '+' + dur(e.offset_seconds) }),
					h('span', { text: e.label }),
					e.volunteer ? h('span', { class: 'yapr-muted', text: ' — ' + (e.volunteer.name || e.volunteer.volunteer_id) }) : null);
			})));
			append(cell, h('p', { class: 'yapr-muted', text: 'Volunteers dialed: ' + c.volunteer_attempts + '  |  ' + label(c.answer_status) }));
		}).catch(function (e) { clear(cell); append(cell, h('p', { class: 'yapr-error', text: e.message })); });
	};

	App.prototype.loadVolunteers = function () {
		var self = this;
		this.volSort = this.volSort || 'answered_calls';
		this.exportLinks([['volunteers', T.export]]);
		this.busy(true);
		api('volunteers', Object.assign({ sort: this.volSort }, this.baseParams())).then(function (res) {
			self.busy(false);
			self.drawVolunteers(res);
		}).catch(function (e) { self.fail(e); });
	};

	App.prototype.drawVolunteers = function (res) {
		var self = this, panel = this.panel, d = res.data || {};
		clear(panel);
		append(panel, this.freshness({ meta: res.meta, fetched_at: res.fetched_at }));
		if (!d.available) { append(panel, h('p', { class: 'yapr-empty', text: d.reason || T.unavailable })); return; }
		var vols = d.volunteers || [];
		if (!vols.length) { append(panel, h('p', { class: 'yapr-empty', text: T.empty })); return; }

		var top = vols.slice(0, 15);
		append(panel, chart({ title: 'Volunteer activity', labels: top.map(function (v) { return (v.name || v.volunteer_id).split(' ')[0]; }), series: [{ name: 'Times dialed', values: top.map(function (v) { return v.routed_attempts; }) }, { name: T.answered || 'Answered', values: top.map(function (v) { return v.answered_calls; }), cls: 'yapr-good' }] }));

		if (d.names_status === 'permission_missing') {
			append(panel, h('div', { class: 'yapr-alert', role: 'note' },
				h('p', null, h('strong', { text: 'Volunteer names are hidden. ' }),
					'The API credential this site uses does not have the "Volunteer names" permission. On the YAP server open reporting-api/setup, choose "Change permissions" for this site\u2019s credential, tick "Volunteer names" and save. Then click Test connection on the Diagnostics page here.')));
		} else if (d.names_status === 'setting_off') {
			append(panel, h('div', { class: 'yapr-alert', role: 'note' },
				h('p', null, h('strong', { text: 'Volunteer names are switched off on this site. ' }), 'An administrator can turn them on under YAP Reporting \u2192 Settings \u2192 Volunteer names.')));
		}
		var unrecorded = vols.reduce(function (n, v) { return n + (v.attempts_outcome_not_recorded || 0); }, 0);
		if (unrecorded) {
			append(panel, h('div', { class: 'yapr-notice', role: 'note' }, h('p', { text: 'On ' + num(unrecorded) + ' volunteer dial(s) YAP logged nothing about what happened next (no pick-up, accept or decline). Those are shown as "Result not recorded" and left out of answer rates; when the volunteer\u2019s line joined the caller\u2019s conference the call still counts as answered.' })));
		}
		var flagged = vols.filter(function (v) { return v.possible_voicemail_pickup; });
		if (flagged.length) {
			append(panel, h('div', { class: 'yapr-alert', role: 'note' },
				h('p', null, h('strong', { text: 'Possible voicemail or call-forwarding pick-up: ' }),
					flagged.map(function (v) { return volLabel(v) + ' (' + num(v.picked_up) + ' pick-ups, ' + num(v.answered_calls) + ' accepted, ' + num(v.declined_without_key) + ' with no key pressed, ' + num(v.calls_lost_after_first_pickup) + ' calls lost after their phone picked up first)'; }).join('; ')),
				h('p', { text: 'Their phone answers YAP\u2019s call but nobody presses the key to accept, so the caller is not connected. Ask them to check voicemail and call-forwarding settings, or pause them in YAP.' })));
		}
		var cols = [['name', T.volunteer], ['routed_attempts', 'Times dialed'], ['picked_up', 'Picked up'], ['answered_calls', 'Accepted (answered)'], ['pickups_taken_by_other', 'Taken by another volunteer'], ['pickups_on_unanswered_calls', 'Picked up, nobody answered'], ['attempts_outcome_not_recorded', 'Result not recorded'], ['declined_without_key', 'No key pressed'], ['calls_lost_after_first_pickup', 'Calls lost after their pick-up'], ['not_answered_attempts', T.notAnswered], ['busy_attempts', 'Busy'], ['bad_number_attempts', 'Number failed'], ['answer_rate', 'Answer rate'], ['total_conversation_seconds', 'Talk time'], ['average_conversation_seconds', 'Average'], ['last_answered_at', 'Last answered']];
		var sortable = ['answered_calls', 'answer_rate', 'total_conversation_seconds', 'routed_attempts'];
		var head = h('tr', null, cols.map(function (c) {
			if (sortable.indexOf(c[0]) === -1) { return h('th', { scope: 'col', text: c[1] }); }
			return h('th', { scope: 'col', 'aria-sort': self.volSort === c[0] ? 'descending' : 'none' },
				h('button', { type: 'button', class: 'yapr-sort', text: c[1] + (self.volSort === c[0] ? ' ↓' : ''), onclick: function () { self.volSort = c[0]; self.loadVolunteers(); } }));
		}));
		var body = h('tbody', null, vols.map(function (v) {
			return h('tr', { class: v.possible_voicemail_pickup ? 'is-flagged' : null },
				h('th', { scope: 'row' }, volLabel(v), v.possible_voicemail_pickup ? h('span', { class: 'yapr-flag', text: ' Possible voicemail pick-up' }) : null),
				h('td', { class: 'num', text: num(v.routed_attempts) }), h('td', { class: 'num', text: num(v.picked_up) }), h('td', { class: 'num', text: num(v.answered_calls) }),
				h('td', { class: 'num', text: num(v.pickups_taken_by_other) }), h('td', { class: 'num', text: num(v.pickups_on_unanswered_calls) }), h('td', { class: 'num', text: num(v.attempts_outcome_not_recorded) }), h('td', { class: 'num', text: num(v.declined_without_key) }), h('td', { class: 'num', text: num(v.calls_lost_after_first_pickup) }),
				h('td', { class: 'num', text: num(v.not_answered_attempts) }), h('td', { class: 'num', text: num(v.busy_attempts) }),
				h('td', { class: 'num', text: num(v.bad_number_attempts) }), h('td', { class: 'num', text: pct(v.answer_rate) }),
				h('td', { class: 'num', text: dur(v.total_conversation_seconds) }), h('td', { class: 'num', text: dur(v.average_conversation_seconds) }),
				h('td', { text: v.last_answered_at ? new Date(v.last_answered_at).toLocaleString() : '—' }));
		}));
		append(panel, h('div', { class: 'yapr-scroll' }, h('table', { class: 'yapr-table' }, h('thead', null, head), body)));

		var notes = (d.limitations || []).slice();
		if (d.unattributed_attempts) { notes.push(d.unattributed_attempts + ' dial attempts could not be tied to a volunteer.'); }
		if (d.unattributed_answered_calls) { notes.push(d.unattributed_answered_calls + ' answered calls could not be tied to a volunteer.'); }
		if (d.answers_attributed_by_inference) { notes.push(d.answers_attributed_by_inference + ' answered calls were credited to the most recently dialed volunteer.'); }
		append(panel, h('details', { class: 'yapr-section' }, h('summary', { text: 'How to read these figures' }), h('ul', null, notes.map(function (n) { return h('li', { text: n }); }))));
	};

	// ------------------------------------------------------------------ boot

	function boot() {
		var nodes = document.querySelectorAll('.yapr-app[data-yapr-mode]');
		Array.prototype.forEach.call(nodes, function (n) { if (!n.yapr) { n.yapr = new App(n); } });
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
