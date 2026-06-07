(function ($) {
	'use strict';

	function nextIndex($table) {
		return $table.find('tbody tr.pbd-exp-variant-row, tbody tr.pbd-exp-metric-row').length;
	}

	function syncVariantTypeFields($row) {
		var type = $row.find('.pbd-exp-variant-type:checked').val() || $row.find('.pbd-exp-variant-type').val() || 'template';
		var $tmpl = $row.find('.pbd-exp-template-picker');
		var $redirect = $row.find('.pbd-exp-redirect-url');
		if (type === 'redirect') {
			$tmpl.attr('hidden', 'hidden');
			$redirect.removeAttr('hidden');
		} else {
			$redirect.attr('hidden', 'hidden');
			$tmpl.removeAttr('hidden');
		}
	}

	function syncTemplatePicker($picker) {
		var $select = $picker.find('.pbd-exp-template-select');
		var $custom = $picker.find('.pbd-exp-template-path-custom');
		var $hidden = $picker.find('.pbd-exp-template-path');
		var val = $select.val();
		if (val === '__custom__') {
			$custom.removeAttr('hidden');
			$hidden.val($custom.val() || '');
		} else {
			$custom.attr('hidden', 'hidden');
			$hidden.val(val || '');
		}
	}

	function slugify(value) {
		return String(value || '')
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '')
			.slice(0, 64);
	}

	function recomputeShares() {
		var $rows = $('#pbd-exp-variants .pbd-exp-variant-row');
		var total = 0;
		$rows.each(function () {
			total += Math.max(0, parseInt($(this).find('.pbd-exp-weight').val(), 10) || 0);
		});
		var $bar = $('#pbd-exp-split-bar');
		$bar.empty();
		$rows.each(function () {
			var w = Math.max(0, parseInt($(this).find('.pbd-exp-weight').val(), 10) || 0);
			var pct = total > 0 ? Math.round((w / total) * 100) : 0;
			$(this).find('.pbd-exp-variant-share').text(pct + '%');
			var label = $(this).find('.pbd-exp-variant-label').val() || 'Variant';
			var $seg = $('<div class="pbd-exp-split-bar__seg"></div>')
				.css('flex', w + ' 1 0')
				.append($('<span class="pbd-exp-split-bar__label"></span>').text(label).append(' ').append($('<span class="pct"></span>').text(pct + '%')));
			$bar.append($seg);
		});

		var $warn = $('#pbd-exp-split-warning');
		if (total === 0) {
			$warn.find('[data-total]').text('0');
			$warn.removeAttr('hidden').text('Total weight is 0; visitors won\'t be assigned.');
		} else {
			$warn.attr('hidden', 'hidden');
		}
	}

	function humanizeDays(days) {
		days = parseInt(days, 10);
		if (!days || days < 1) return '';
		if (days === 1) return '≈ each pageview';
		if (days < 7) return '≈ ' + days + ' days';
		if (days < 14) return '≈ 1 week';
		if (days < 28) return '≈ ' + Math.round(days / 7) + ' weeks';
		if (days < 60) return '≈ 1 month';
		if (days < 350) return '≈ ' + Math.round(days / 30) + ' months';
		return '≈ ' + Math.round(days / 365) + ' year' + (Math.round(days / 365) > 1 ? 's' : '');
	}

	function updateCookieHelper() {
		var v = $('#cookie_days').val();
		$('#pbd-exp-cookie-helper').text(humanizeDays(v));
	}

	function updateKeyBadge() {
		var $name = $('#name');
		var $key = $('#experiment_key');
		var $badge = $('#pbd-exp-key-badge');
		var $preview = $('#pbd-exp-key-preview');
		var $field = $('#pbd-exp-key-field');
		if (!$badge.length) return;
		if ($field.is(':visible')) return;
		var val = $key.val() || slugify($name.val());
		if (val) {
			$preview.text(val);
			$badge.removeAttr('hidden');
		} else {
			$badge.attr('hidden', 'hidden');
		}
	}

	function updateControlTargetDisplay() {
		var path = $('#target_path').val() || '/';
		$('.pbd-exp-variant-row--control .pbd-exp-control-target').text(path);
	}

	function refreshMetricSnippets($row) {
		var event = slugify($row.find('.pbd-exp-metric-event').val() || $row.find('.pbd-exp-metric-key').val() || $row.find('.pbd-exp-metric-name').val()) || 'event_name';
		// Prefer the live input value; fall back to the table's initial data attribute, then to a sane placeholder.
		var liveKey = ($('#experiment_key').val() || '').trim();
		var nameSlug = slugify($('#name').val());
		var exp = liveKey || nameSlug || $('#pbd-exp-metrics').data('experiment-key') || 'your_experiment_id';
		var $snippetRow = $row.next('.pbd-exp-metric-snippet-row');
		$snippetRow.find('.pbd-exp-snippet-event').text(event);
		$snippetRow.find('.pbd-exp-snippet-exp').text(exp);

		var trigger = $row.find('.pbd-exp-metric-trigger').val() || 'page';
		$snippetRow.find('.pbd-exp-metric-snippet').each(function () {
			if ($(this).data('snippet-for') === trigger) {
				$(this).removeAttr('hidden');
			} else {
				$(this).attr('hidden', 'hidden');
			}
		});

		// Selector + form-platform fields only apply to form triggers.
		var $formConfig = $row.find('.pbd-exp-metric-form-config');
		if (trigger === 'form') {
			$formConfig.removeAttr('hidden');
		} else {
			$formConfig.attr('hidden', 'hidden');
		}
	}

	function gatherValidationErrors() {
		var errors = [];
		if (!$('#name').val().trim()) errors.push('Name is required.');
		var target = ($('#target_path').val() || '').trim();
		if (!target) errors.push('Target URL path is required (use / for the homepage).');
		else if (target[0] !== '/') errors.push('Target URL must start with /.');

		var variantCount = $('#pbd-exp-variants .pbd-exp-variant-row').length;
		if (variantCount < 2) errors.push('At least two variants are needed.');

		var totalWeight = 0;
		$('#pbd-exp-variants .pbd-exp-weight').each(function () {
			totalWeight += Math.max(0, parseInt($(this).val(), 10) || 0);
		});
		if (totalWeight === 0) errors.push('Variant weights add up to 0; assign at least one variant some weight.');

		return errors;
	}

	$(function () {
		var $variants = $('#pbd-exp-variants');
		var $metrics = $('#pbd-exp-metrics');

		// ---------------- Variants ----------------

		$variants.on('change', '.pbd-exp-variant-type', function () {
			syncVariantTypeFields($(this).closest('tr'));
		});

		$variants.on('input change', '.pbd-exp-weight, .pbd-exp-variant-label', recomputeShares);

		// Template-picker
		$variants.on('change', '.pbd-exp-template-select', function () {
			syncTemplatePicker($(this).closest('.pbd-exp-template-picker'));
		});
		$variants.on('input', '.pbd-exp-template-path-custom', function () {
			var $picker = $(this).closest('.pbd-exp-template-picker');
			$picker.find('.pbd-exp-template-path').val($(this).val());
		});

		// Control override toggle
		$variants.on('click', '.pbd-exp-control-override-toggle', function () {
			var $cell = $(this).closest('.pbd-exp-control-dest');
			var action = $(this).data('action');
			if (action === 'reveal') {
				$cell.addClass('is-overridden');
				$cell.find('.pbd-exp-control-dest__default').attr('hidden', 'hidden');
				$cell.find('.pbd-exp-control-dest__override').removeAttr('hidden');
				$cell.find('.pbd-exp-control-override-flag').val('1');
			} else {
				$cell.removeClass('is-overridden');
				$cell.find('.pbd-exp-control-dest__default').removeAttr('hidden');
				$cell.find('.pbd-exp-control-dest__override').attr('hidden', 'hidden');
				$cell.find('.pbd-exp-control-override-flag').val('0');
			}
		});

		// Toggle showing technical IDs (variants/metrics)
		$('#pbd-exp-toggle-variant-keys').on('click', function () {
			var $table = $('#pbd-exp-variants');
			var showing = $table.toggleClass('pbd-exp-hide-keys').hasClass('pbd-exp-hide-keys') === false;
			$(this).attr('aria-pressed', showing ? 'true' : 'false').text(showing ? 'Hide technical IDs' : 'Show technical IDs');
		});
		$('#pbd-exp-toggle-metric-keys').on('click', function () {
			var $table = $('#pbd-exp-metrics');
			var showing = $table.toggleClass('pbd-exp-hide-keys').hasClass('pbd-exp-hide-keys') === false;
			$(this).attr('aria-pressed', showing ? 'true' : 'false').text(showing ? 'Hide technical IDs' : 'Show technical IDs');
		});

		// Auto-fill variant_key from label
		$variants.on('input', '.pbd-exp-variant-label', function () {
			var $row = $(this).closest('tr');
			var $key = $row.find('.pbd-exp-variant-key');
			if (!$key.data('touched')) {
				$key.val(slugify($(this).val()));
			}
		});
		$variants.on('input', '.pbd-exp-variant-key', function () {
			$(this).data('touched', true);
		});

		if ($.fn.sortable) {
			$variants.find('tbody').sortable({
				handle: '.pbd-exp-drag',
				items: '> tr.pbd-exp-variant-row:not(.pbd-exp-variant-row--control)',
				placeholder: 'pbd-exp-sortable-placeholder',
				helper: function (e, tr) {
					var $orig = tr.children();
					var $helper = tr.clone();
					$helper.children().each(function (i) {
						$(this).width($orig.eq(i).width());
					});
					return $helper;
				},
				update: recomputeShares
			});
		}

		// Experiment key badge auto-fill
		var $keyField = $('#experiment_key');
		var $nameField = $('#name');
		if ($keyField.length && $nameField.length && !$keyField.prop('readonly')) {
			var keyTouched = $keyField.val() !== '';
			$keyField.on('input', function () { keyTouched = true; updateKeyBadge(); });
			$nameField.on('input', function () {
				if (!keyTouched) {
					$keyField.val(slugify($nameField.val()));
				}
				updateKeyBadge();
				// Refresh metric snippets in case the experiment key changed
				$('#pbd-exp-metrics .pbd-exp-metric-row').each(function () {
					refreshMetricSnippets($(this));
				});
			});
			updateKeyBadge();
		}

		$('#pbd-exp-key-edit').on('click', function () {
			$('#pbd-exp-key-field').removeAttr('hidden');
			$('#pbd-exp-key-badge').attr('hidden', 'hidden');
			$('#experiment_key').trigger('focus');
		});

		// Target path -> update control row's display
		$('#target_path').on('input change', updateControlTargetDisplay);

		// Cookie days helper
		$('#cookie_days').on('input change', updateCookieHelper);
		updateCookieHelper();

		// Logged-in users warning
		$('#include_logged_in').on('change', function () {
			var $note = $('#pbd-exp-loggedin-warning');
			if ($(this).is(':checked')) $note.removeAttr('hidden');
			else $note.attr('hidden', 'hidden');
		});

		// Add variant
		$('#pbd-exp-add-variant').on('click', function () {
			var i = nextIndex($variants);
			var templateOpts = $('#pbd-exp-template-options').html() || '<option value="">Default template</option>';
			var defaultLabel = 'Variant ' + String.fromCharCode(64 + i + 1); // B, C, D...
			var html = '<tr class="pbd-exp-variant-row" data-existing-id="0" data-is-control="0">' +
				'<td class="pbd-exp-drag" title="Drag to reorder" aria-label="Drag to reorder"><span class="pbd-exp-drag__grip" aria-hidden="true"></span></td>' +
				'<td class="pbd-exp-keys-col"><input type="hidden" name="variants[' + i + '][id]" value="0">' +
					'<input type="text" name="variants[' + i + '][variant_key]" placeholder="variant_' + String.fromCharCode(97 + i) + '" class="pbd-exp-variant-key"></td>' +
				'<td><input type="text" name="variants[' + i + '][label]" placeholder="' + defaultLabel + '" class="pbd-exp-variant-label"></td>' +
				'<td><input type="number" name="variants[' + i + '][weight]" min="0" value="50" class="pbd-exp-weight">' +
					'<span class="pbd-exp-variant-share">0%</span></td>' +
				'<td><div class="pbd-exp-segmented pbd-exp-variant-type-seg" role="radiogroup" aria-label="Destination type">' +
					'<label><input type="radio" name="variants[' + i + '][variant_type]" value="template" class="pbd-exp-variant-type" checked><span>Template</span></label>' +
					'<label><input type="radio" name="variants[' + i + '][variant_type]" value="redirect" class="pbd-exp-variant-type"><span>Redirect</span></label>' +
				'</div></td>' +
				'<td class="pbd-exp-dest-cell">' +
					'<span class="pbd-exp-template-picker">' +
						'<select class="pbd-exp-template-select" data-row-index="' + i + '">' + templateOpts + '</select>' +
						'<input type="text" class="pbd-exp-template-path-custom" placeholder="path/to/template.php" hidden>' +
						'<input type="hidden" name="variants[' + i + '][template_path]" class="pbd-exp-template-path" value="">' +
					'</span>' +
					'<input type="text" name="variants[' + i + '][redirect_url]" placeholder="/variant-url/" class="pbd-exp-redirect-url" hidden>' +
				'</td>' +
				'<td class="col-remove"><button type="button" class="pbd-exp-remove-row" aria-label="Remove variant"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>' +
				'</tr>';
			$variants.find('tbody').append(html);
			recomputeShares();
		});

		// ---------------- Metrics ----------------

		// Auto-fill metric_key and event_name from name
		$metrics.on('input', '.pbd-exp-metric-name', function () {
			var $row = $(this).closest('tr');
			var $key = $row.find('.pbd-exp-metric-key');
			var $event = $row.find('.pbd-exp-metric-event');
			var slug = slugify($(this).val());
			if (!$key.data('touched')) $key.val(slug);
			if (!$event.data('touched')) $event.val(slug);
			refreshMetricSnippets($row);
		});
		$metrics.on('input', '.pbd-exp-metric-key', function () {
			$(this).data('touched', true);
			var $row = $(this).closest('tr');
			var $event = $row.find('.pbd-exp-metric-event');
			if (!$event.data('touched')) $event.val(slugify($(this).val()));
			refreshMetricSnippets($row);
		});
		$metrics.on('input', '.pbd-exp-metric-event', function () {
			$(this).data('touched', true);
			refreshMetricSnippets($(this).closest('tr'));
		});

		// Per-metric trigger dropdown
		$metrics.on('change', '.pbd-exp-metric-trigger', function () {
			refreshMetricSnippets($(this).closest('tr'));
		});

		// Add metric
		$('#pbd-exp-add-metric').on('click', function () {
			var i = nextIndex($metrics);
			var expKey = $metrics.data('experiment-key') || $('#experiment_key').val() || slugify($('#name').val()) || 'your_experiment_id';
			var event = 'event_name';
			var html = '<tr class="pbd-exp-metric-row">' +
				'<td class="pbd-exp-keys-col"><input type="hidden" name="metrics[' + i + '][id]" value="0">' +
					'<input type="text" name="metrics[' + i + '][metric_key]" placeholder="opt_in" class="pbd-exp-metric-key"></td>' +
				'<td><input type="text" name="metrics[' + i + '][name]" placeholder="Display Name" class="pbd-exp-metric-name"></td>' +
				'<td><select name="metrics[' + i + '][trigger_type]" class="pbd-exp-metric-trigger">' +
					'<option value="page">Visiting a specific page</option>' +
					'<option value="form">Submitting a form</option>' +
					'<option value="js">Custom JavaScript</option>' +
				'</select>' +
				'<span class="pbd-exp-metric-form-config" hidden>' +
					'<input type="text" name="metrics[' + i + '][selector]" placeholder="#inf_form, .infusion-form, form.optin" class="pbd-exp-metric-selector">' +
					'<select name="metrics[' + i + '][form_type]" class="pbd-exp-metric-formtype">' +
						'<option value="native">Standard form (incl. Infusionsoft / Keap)</option>' +
						'<option value="cf7">Contact Form 7</option>' +
					'</select>' +
				'</span></td>' +
				'<td class="pbd-exp-keys-col"><input type="text" name="metrics[' + i + '][event_name]" placeholder="event_name" class="pbd-exp-metric-event"></td>' +
				'<td class="col-active"><input type="checkbox" name="metrics[' + i + '][active]" value="1" checked></td>' +
				'<td class="col-remove"><button type="button" class="pbd-exp-remove-row" aria-label="Remove metric"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>' +
				'</tr>' +
				'<tr class="pbd-exp-metric-snippet-row"><td colspan="6">' +
					'<div class="pbd-exp-metric-snippet" data-snippet-for="page">' +
						'<p class="pbd-exp-metric-snippet__lede"><strong>Paste this shortcode into the page that confirms the conversion</strong> (e.g. the thank-you page someone lands on after submitting).</p>' +
						'<p class="pbd-exp-metric-snippet__how">In WordPress: edit the page → <em>+</em> → add a <strong>Shortcode</strong> block → paste → Save.</p>' +
						'<div class="pbd-exp-snippet"><code class="pbd-exp-snippet-code">[pbd_experiment_event event=&quot;<span class="pbd-exp-snippet-event">' + event + '</span>&quot; experiment=&quot;<span class="pbd-exp-snippet-exp">' + expKey + '</span>&quot; once=&quot;1&quot;]</code><button type="button" class="copy-btn">Copy</button></div>' +
					'</div>' +
					'<div class="pbd-exp-metric-snippet" data-snippet-for="form" hidden>' +
						'<p class="pbd-exp-metric-snippet__lede"><strong>Enter the CSS selector for the form above</strong> (e.g. <code>#inf_form</code>, <code>.infusion-form</code>, <code>form.optin</code>). On a page with several forms, this is how you target the right one. The conversion is recorded the moment that form is submitted, on the test page itself, so it works even when the form posts off-site (Infusionsoft / Keap) and redirects back.</p>' +
						'<p class="pbd-exp-metric-snippet__how">Right-click the form in your browser → Inspect → read its <code>id</code> or <code>class</code>. Prefer an <code>#id</code>: it is the most specific.</p>' +
						'<p class="pbd-exp-metric-snippet__how"><em>Advanced (optional):</em> instead of a selector you can hand-add attributes to the form\'s HTML — <code>&lt;form data-pbd-exp=&quot;<span class="pbd-exp-snippet-exp">' + expKey + '</span>&quot; data-pbd-event=&quot;<span class="pbd-exp-snippet-event">' + event + '</span>&quot;&gt;</code></p>' +
					'</div>' +
					'<div class="pbd-exp-metric-snippet" data-snippet-for="js" hidden>' +
						'<p class="pbd-exp-metric-snippet__lede"><strong>Call this from JavaScript</strong> when the conversion happens (button click, ajax response, custom flow).</p>' +
						'<div class="pbd-exp-snippet"><code class="pbd-exp-snippet-code">PBDExperiments.track(\'<span class="pbd-exp-snippet-event">' + event + '</span>\', { experiment: \'<span class="pbd-exp-snippet-exp">' + expKey + '</span>\', once: true });</code><button type="button" class="copy-btn">Copy</button></div>' +
					'</div>' +
				'</td></tr>';
			$metrics.find('tbody').append(html);
		});

		// Remove row
		$(document).on('click', '.pbd-exp-remove-row', function () {
			var $row = $(this).closest('tr');
			var inVariants = $row.closest('#pbd-exp-variants').length > 0;
			// For metric rows, also remove the adjacent snippet row.
			var $next = $row.next('.pbd-exp-metric-snippet-row');
			$row.remove();
			if ($next.length) $next.remove();
			if (inVariants) recomputeShares();
		});

		// Copy snippets
		$(document).on('click', '.pbd-exp-snippet .copy-btn', function () {
			var $btn = $(this);
			var text = $btn.closest('.pbd-exp-snippet').find('code').text();
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function () {
					var original = $btn.text();
					$btn.text('Copied!');
					setTimeout(function () { $btn.text(original); }, 1200);
				});
			}
		});

		// Concluded delete: require typing experiment key
		$(document).on('submit', '.pbd-exp-delete-form[data-confirm-key]', function (e) {
			var expected = $(this).data('confirm-key');
			var prompt = $(this).data('confirm-prompt') || ('Type "' + expected + '" to confirm permanent deletion:');
			var answer = window.prompt(prompt, '');
			if (answer === null) {
				e.preventDefault();
				return false;
			}
			if (answer.trim() !== String(expected)) {
				e.preventDefault();
				window.alert('That did not match the experiment ID. Nothing was deleted.');
				return false;
			}
		});

		// Validation
		$('#pbd-exp-form').on('submit', function (e) {
			var errors = gatherValidationErrors();
			var $summary = $('#pbd-exp-validation');
			if (errors.length) {
				e.preventDefault();
				var $list = $summary.find('ul').empty();
				errors.forEach(function (msg) { $list.append($('<li></li>').text(msg)); });
				$summary.removeAttr('hidden').attr('tabindex', '-1').trigger('focus');
				$('html, body').animate({ scrollTop: $summary.offset().top - 80 }, 200);
			} else {
				$summary.attr('hidden', 'hidden');
			}
		});

		// Initial render
		recomputeShares();
		updateControlTargetDisplay();
		$variants.find('.pbd-exp-template-picker').each(function () { syncTemplatePicker($(this)); });
		$metrics.find('.pbd-exp-metric-row').each(function () { refreshMetricSnippets($(this)); });
	});
})(jQuery);
