/**
 * Extended Abilities Admin Settings Scripts
 *
 * @package ExtendedAbilities
 * @since   1.0.0
 */

(function ($) {
	'use strict';

	/**
	 * Initialize settings page functionality.
	 */
	function init() {
		handleToggleAll();
		handleSubgroupToggleAll();
		handleIndividualToggles();
		handleCollapseToggle();
		handleCopyText();
		handleOAuthModal();
	}

	/**
	 * Handle "Toggle All" functionality for ability groups.
	 */
	function handleToggleAll() {
		$('.toggle-all-abilities').on('change', function () {
			const $toggleAll = $(this);
			const group = $toggleAll.data('group');
			const isChecked = $toggleAll.prop('checked');

			// Toggle all checkboxes in this group (including subgroups)
			$('.ability-checkbox[data-group="' + group + '"]').prop('checked', isChecked);

			// Update all subgroup toggle states
			$('.toggle-subgroup-abilities').each(function () {
				updateSubgroupToggleState($(this).data('subgroup'));
			});
		});
	}

	/**
	 * Handle "Toggle All" functionality for ability subgroups.
	 */
	function handleSubgroupToggleAll() {
		$('.toggle-subgroup-abilities').on('change', function () {
			const $toggleAll = $(this);
			const subgroup = $toggleAll.data('subgroup');
			const isChecked = $toggleAll.prop('checked');

			// Toggle all checkboxes in this subgroup
			$('.' + subgroup).prop('checked', isChecked);

			// Update parent group toggle state
			const $firstCheckbox = $('.' + subgroup).first();
			if ($firstCheckbox.length > 0) {
				const group = $firstCheckbox.data('group');
				updateToggleAllState(group);
			}
		});
	}

	/**
	 * Handle individual ability toggles to update "Toggle All" state.
	 */
	function handleIndividualToggles() {
		$('.ability-checkbox').on('change', function () {
			const $checkbox = $(this);
			const group = $checkbox.data('group');

			// Update parent group toggle state
			updateToggleAllState(group);

			// If this is part of a subgroup, update subgroup toggle state
			if ($checkbox.hasClass('ability-checkbox-subgroup')) {
				const subgroupClass = getSubgroupClass($checkbox);
				if (subgroupClass) {
					updateSubgroupToggleState(subgroupClass);
				}
			}
		});

		// Initialize toggle all states on page load
		const groups = [];
		$('.toggle-all-abilities').each(function () {
			const group = $(this).data('group');
			if (groups.indexOf(group) === -1) {
				groups.push(group);
				updateToggleAllState(group);
			}
		});

		// Initialize subgroup toggle states
		$('.toggle-subgroup-abilities').each(function () {
			const subgroup = $(this).data('subgroup');
			updateSubgroupToggleState(subgroup);
		});
	}

	/**
	 * Get the subgroup class from a checkbox element.
	 *
	 * @param {jQuery} $checkbox The checkbox element.
	 * @return {string|null} The subgroup class or null.
	 */
	function getSubgroupClass($checkbox) {
		const classes = $checkbox.attr('class').split(/\s+/);
		for (let i = 0; i < classes.length; i++) {
			if (classes[i].startsWith('subgroup-')) {
				return classes[i];
			}
		}
		return null;
	}

	/**
	 * Update the "Toggle All" checkbox state based on individual checkboxes.
	 *
	 * @param {string} group The group identifier.
	 */
	function updateToggleAllState(group) {
		const $groupCheckboxes = $('.ability-checkbox[data-group="' + group + '"]');
		const $toggleAll = $('.toggle-all-abilities[data-group="' + group + '"]');

		if ($groupCheckboxes.length === 0) {
			return;
		}

		const totalCheckboxes = $groupCheckboxes.length;
		const checkedCheckboxes = $groupCheckboxes.filter(':checked').length;

		// Set toggle all to checked if all are checked, unchecked otherwise
		$toggleAll.prop('checked', totalCheckboxes === checkedCheckboxes);
	}

	/**
	 * Update the subgroup "Toggle All" checkbox state.
	 *
	 * @param {string} subgroup The subgroup class.
	 */
	function updateSubgroupToggleState(subgroup) {
		const $subgroupCheckboxes = $('.' + subgroup);
		const $toggleAll = $('.toggle-subgroup-abilities[data-subgroup="' + subgroup + '"]');

		if ($subgroupCheckboxes.length === 0) {
			return;
		}

		const totalCheckboxes = $subgroupCheckboxes.length;
		const checkedCheckboxes = $subgroupCheckboxes.filter(':checked').length;

		// Set toggle all to checked if all are checked, unchecked otherwise
		$toggleAll.prop('checked', totalCheckboxes === checkedCheckboxes);
	}

	/**
	 * Handle collapse/expand functionality for groups and subgroups.
	 */
	function handleCollapseToggle() {
		// Handle main group collapse
		$('.ability-group-collapse-toggle').on('click', function () {
			const $button = $(this);
			const targetId = $button.attr('aria-controls');
			const $target = $('#' + targetId);
			const isExpanded = $button.attr('aria-expanded') === 'true';

			// Toggle state
			$button.attr('aria-expanded', !isExpanded);
			$target.toggleClass('collapsed');
		});

		// Handle subgroup collapse
		$('.ability-subgroup-collapse-toggle').on('click', function () {
			const $button = $(this);
			const targetId = $button.attr('aria-controls');
			const $target = $('#' + targetId);
			const isExpanded = $button.attr('aria-expanded') === 'true';

			// Toggle state
			$button.attr('aria-expanded', !isExpanded);
			$target.toggleClass('collapsed');
		});
	}

	/**
	 * Handle copy-to-clipboard functionality.
	 */
	function handleCopyText() {
		$(document).on('click', '.ea-copy-text', function () {
			const $el = $(this);
			const text = $el.text().trim();

			navigator.clipboard.writeText(text).then(function () {
				$el.addClass('copied');
				$el.attr('data-copied', extendedAbilitiesAdmin.i18n.copied);

				setTimeout(function () {
					$el.removeClass('copied');
					$el.removeAttr('data-copied');
				}, 2000);
			}).catch(function () {
				// Fallback for older browsers
				const textarea = document.createElement('textarea');
				textarea.value = text;
				document.body.appendChild(textarea);
				textarea.select();
				document.execCommand('copy');
				document.body.removeChild(textarea);

				$el.addClass('copied');
				$el.attr('data-copied', extendedAbilitiesAdmin.i18n.copied);

				setTimeout(function () {
					$el.removeClass('copied');
					$el.removeAttr('data-copied');
				}, 2000);
			});
		});
	}

	/**
	 * Handle OAuth client modal.
	 */
	function handleOAuthModal() {
		const $modal = $('#ea-oauth-client-modal');
		const $form = $('#ea-client-form');
		const $created = $('#ea-client-created');
		const $spinner = $modal.find('.spinner');

		// Open modal
		$(document).on('click', '#ea-add-client', function (e) {
			e.preventDefault();
			openModal();
		});

		// Close modal
		$(document).on('click', '.ea-modal-close, #ea-close-modal-btn', function () {
			closeModal();
		});

		// Close on backdrop click
		$modal.on('click', function (e) {
			if ($(e.target).is('.ea-modal')) {
				closeModal();
			}
		});

		// Close on ESC key
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && $modal.is(':visible')) {
				closeModal();
			}
		});

		// Create client
		$(document).on('click', '#ea-create-client-btn', function () {
			const userId = $('#ea-client-user').val();
			const name = $('#ea-client-name').val().trim();

			if (!userId) {
				$('#ea-client-user').focus();
				return;
			}

			if (!name) {
				$('#ea-client-name').focus();
				return;
			}

			$spinner.addClass('is-active');

			$.ajax({
				url: extendedAbilitiesAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'ea_create_oauth_client',
					nonce: extendedAbilitiesAdmin.nonce,
					user_id: userId,
					name: name
				},
				success: function (response) {
					$spinner.removeClass('is-active');

					if (response.success) {
						$('#ea-new-client-id').text(response.data.client_id);
						$('#ea-new-client-secret').text(response.data.client_secret);
						$form.hide();
						$created.show();
					} else {
						alert(response.data.message || extendedAbilitiesAdmin.i18n.createError);
					}
				},
				error: function () {
					$spinner.removeClass('is-active');
					alert(extendedAbilitiesAdmin.i18n.createError);
				}
			});
		});

		function openModal() {
			// Reset form
			$form.show();
			$created.hide();
			$('#ea-client-user').val('');
			$('#ea-client-name').val('');
			$modal.show();
			$('#ea-client-user').focus();
		}

		function closeModal() {
			$modal.hide();

			// If client was created, reload the page to show it
			if ($created.is(':visible')) {
				window.location.reload();
			}
		}
	}

	// Initialize on document ready
	$(document).ready(function () {
		init();
	});

})(jQuery);
