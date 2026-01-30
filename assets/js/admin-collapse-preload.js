/**
 * Collapse Preload
 *
 * Pre-applies collapsed state before paint to prevent flash of expanded content.
 * Reads the same localStorage key used by CollapseModule.
 *
 * @package Albert
 * @since   1.0.0
 */
(function() {
	try {
		var collapsed = JSON.parse(localStorage.getItem('albert_collapsed_categories') || '[]');
		if (!collapsed.length) return;
		var css = '';
		for (var i = 0; i < collapsed.length; i++) {
			var id = collapsed[i].replace(/[^a-zA-Z0-9_-]/g, '');
			css += '#' + id + ' .ability-group-items{display:none}';
			css += '#' + id + '{align-self:start;border-color:var(--albert-border-light);box-shadow:none}';
			css += '#' + id + ' .ability-group-header{border-bottom:none}';
			css += '#' + id + ' .ability-group-collapse-toggle[aria-expanded="true"] .dashicons{transform:rotate(-90deg)}';
		}
		var s = document.createElement('style');
		s.id = 'albert-collapse-preload';
		s.textContent = css;
		document.head.appendChild(s);
	} catch (e) {}
})();
