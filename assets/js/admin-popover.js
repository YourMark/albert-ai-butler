/**
 * Info Popover
 *
 * Click-to-toggle popover for info trigger buttons.
 *
 * @package Albert
 * @since   1.0.0
 */
(function() {
	var sel = '.albert-info-trigger[aria-expanded="true"]';

	function closeAll() {
		document.querySelectorAll(sel).forEach(function(t) {
			t.setAttribute('aria-expanded', 'false');
			var p = t.nextElementSibling;
			if (p) p.hidden = true;
		});
	}

	function position(trigger, popover) {
		var r = trigger.getBoundingClientRect();
		popover.style.top = (r.bottom + 6) + 'px';
		popover.style.left = Math.max(8, r.left - 260) + 'px';
	}

	document.addEventListener('click', function(e) {
		var trigger = e.target.closest('.albert-info-trigger');
		if (trigger) {
			var popover = trigger.nextElementSibling;
			if (!popover || !popover.classList.contains('albert-info-popover')) return;
			var isOpen = trigger.getAttribute('aria-expanded') === 'true';
			closeAll();
			if (!isOpen) {
				trigger.setAttribute('aria-expanded', 'true');
				popover.hidden = false;
				position(trigger, popover);
			}
			return;
		}
		if (!e.target.closest('.albert-info-popover')) {
			closeAll();
		}
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			var open = document.querySelector(sel);
			closeAll();
			if (open) open.focus();
		}
	});
})();
