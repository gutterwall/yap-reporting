/* Admin helpers: Region → Area auto-selection and grant pickers. No dependencies. */
(function () {
	'use strict';
	document.addEventListener('change', function (e) {
		var t = e.target;
		if (t.classList && t.classList.contains('yapr-sb')) {
			var include = document.getElementById('yapr-include-children');
			if (include && include.checked) {
				var li = t.closest('li');
				if (li) {
					Array.prototype.forEach.call(li.querySelectorAll('ul input.yapr-sb'), function (c) { c.checked = t.checked; });
				}
			}
		}
		if (t.classList && t.classList.contains('yapr-grant-mode')) { sync(t); }
	});
	function sync(sel) {
		var box = sel.nextElementSibling;
		if (box && box.classList.contains('yapr-grant-areas')) { box.hidden = sel.value !== 'some'; }
	}
	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('.yapr-grant-mode'), sync);
	});
})();
