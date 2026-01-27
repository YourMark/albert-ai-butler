/**
 * AI Bridge Dashboard Scripts
 *
 * @package AIBridge
 * @since 1.0.0
 */

(function() {
	'use strict';

	/**
	 * Initialize when DOM is ready.
	 */
	document.addEventListener('DOMContentLoaded', function() {
		initCopyButtons();
	});

	/**
	 * Initialize copy to clipboard buttons.
	 */
	function initCopyButtons() {
		const copyButtons = document.querySelectorAll('.albert-copy-btn');

		copyButtons.forEach(function(button) {
			button.addEventListener('click', function(e) {
				e.preventDefault();

				const targetSelector = button.getAttribute('data-clipboard-target');
				const targetElement = document.querySelector(targetSelector);

				if (!targetElement) {
					return;
				}

				// Copy to clipboard
				copyToClipboard(targetElement.value)
					.then(function() {
						// Show success feedback
						showCopyFeedback(button, true);
					})
					.catch(function(err) {
						console.error('Failed to copy:', err);
						showCopyFeedback(button, false);
					});
			});
		});
	}

	/**
	 * Copy text to clipboard using modern API with fallback.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise} Promise that resolves when copy is successful.
	 */
	function copyToClipboard(text) {
		// Try modern clipboard API first
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}

		// Fallback for older browsers
		return new Promise(function(resolve, reject) {
			const textArea = document.createElement('textarea');
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.left = '-9999px';
			textArea.style.top = '-9999px';
			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();

			try {
				const successful = document.execCommand('copy');
				document.body.removeChild(textArea);

				if (successful) {
					resolve();
				} else {
					reject(new Error('Copy command failed'));
				}
			} catch (err) {
				document.body.removeChild(textArea);
				reject(err);
			}
		});
	}

	/**
	 * Show visual feedback when copy succeeds or fails.
	 *
	 * @param {HTMLElement} button The copy button element.
	 * @param {boolean} success Whether copy was successful.
	 */
	function showCopyFeedback(button, success) {
		const originalText = button.textContent;
		const feedbackText = success ? 'Copied!' : 'Failed!';
		const feedbackClass = success ? 'albert-copy-success' : 'albert-copy-error';

		// Update button text and add class
		button.textContent = feedbackText;
		button.classList.add(feedbackClass);
		button.disabled = true;

		// Revert after 2 seconds
		setTimeout(function() {
			button.textContent = originalText;
			button.classList.remove(feedbackClass);
			button.disabled = false;
		}, 2000);
	}

})();
