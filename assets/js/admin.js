(function () {
	'use strict';

	var domainMode = document.querySelector('#htbd-routing-mode input[value="domain"]');
	var bindingsRow = document.getElementById('htbd-domain-bindings-row');
	var autoDetectSourceLanguage = document.getElementById('htbd-auto-detect-source-language');
	var sourceLanguageRow = document.getElementById('htbd-source-language-row');

	if (domainMode && bindingsRow) {
		domainMode.addEventListener('change', function () {
			bindingsRow.style.display = domainMode.checked ? '' : 'none';
		});
	}

	if (autoDetectSourceLanguage && sourceLanguageRow) {
		autoDetectSourceLanguage.addEventListener('change', function () {
			sourceLanguageRow.style.display = autoDetectSourceLanguage.checked ? 'none' : '';
		});
	}

	document.querySelectorAll('form[data-htbd-confirm]').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!window.confirm(form.getAttribute('data-htbd-confirm'))) {
				event.preventDefault();
			}
		});
	});

	var container = document.getElementById('htbd-translation-progress');
	if (!container || typeof htbdAdmin === 'undefined') {
		return;
	}

	function appendText(parent, text) {
		parent.appendChild(document.createTextNode(text));
	}

	function format(template, values) {
		return template.replace(/%([1-9]\d*)\$s/g, function (match, position) {
			return values[Number(position) - 1];
		});
	}

	function renderProgress(progress) {
		var summary = document.createElement('p');
		var percent = document.createElement('strong');
		percent.textContent = progress.percent + '%';
		summary.appendChild(percent);
		appendText(summary, ' ' + format(htbdAdmin.i18n.contentItems, [progress.completed, progress.total]));

		var progressBar = document.createElement('div');
		progressBar.style.cssText = 'max-width:480px;height:12px;background:#dcdcde';
		var progressValue = document.createElement('div');
		progressValue.style.cssText = 'height:12px;background:#2271b1';
		progressValue.style.width = progress.percent + '%';
		progressBar.appendChild(progressValue);

		var description = document.createElement('p');
		description.className = 'description';
		description.textContent = format(htbdAdmin.i18n.latestTask, [progress.task_label, progress.posts_completed, progress.posts_total, progress.terms_completed, progress.terms_total]);

		var children = [summary, progressBar, description];
		if (progress.failed_items.length) {
			var failedHeading = document.createElement('h3');
			failedHeading.textContent = htbdAdmin.i18n.failedItems;
			children.push(failedHeading);

			var failedList = document.createElement('ul');
			progress.failed_items.forEach(function (item) {
				var failedItem = document.createElement('li');
				appendText(failedItem, item.name + ' (' + item.language + ') ');

				var retryLink = document.createElement('a');
				retryLink.className = 'button button-small';
				retryLink.href = item.retry_url;
				retryLink.textContent = htbdAdmin.i18n.retranslate;
				failedItem.appendChild(retryLink);
				failedList.appendChild(failedItem);
			});
			children.push(failedList);
		}

		container.replaceChildren.apply(container, children);
	}

	function renderProgressError() {
		var message = document.createElement('p');
		message.textContent = htbdAdmin.i18n.progressError;
		container.replaceChildren(message);
	}

	var POLL_INTERVAL = 5000;
	var MAX_POLL_INTERVAL = 60000;

	var pollTimer = null;
	var pollDelay = POLL_INTERVAL;
	var pollingFinished = false;

	function schedulePoll(delay) {
		if (pollingFinished || document.hidden) {
			return;
		}

		window.clearTimeout(pollTimer);
		pollTimer = window.setTimeout(updateProgress, delay);
	}

	function isFinished(progress) {
		var failedItems = progress.failed_items || [];

		return progress.percent >= 100 && !failedItems.length;
	}

	function updateProgress() {
		var url = htbdAdmin.progressUrl + '?action=htbd_translation_progress&_ajax_nonce=' + encodeURIComponent(htbdAdmin.progressNonce);

		window.fetch(url, { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('progress_request_failed');
				}
				return response.json();
			})
			.then(function (response) {
				if (!response.success) {
					throw new Error('progress_request_failed');
				}

				renderProgress(response.data);
				pollDelay = POLL_INTERVAL;

				if (isFinished(response.data)) {
					pollingFinished = true;

					return;
				}

				schedulePoll(pollDelay);
			})
			.catch(function () {
				renderProgressError();
				pollDelay = Math.min(pollDelay * 2, MAX_POLL_INTERVAL);
				schedulePoll(pollDelay);
			});
	}

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			window.clearTimeout(pollTimer);

			return;
		}

		if (!pollingFinished) {
			updateProgress();
		}
	});

	updateProgress();
}());
