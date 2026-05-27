(function ($) {
	'use strict';

	function nextIndex($table) {
		return $table.find('tbody tr').length;
	}

	function syncVariantTypeFields($row) {
		var type = $row.find('.pbd-exp-variant-type:checked').val() || $row.find('.pbd-exp-variant-type').val();
		var $template = $row.find('.pbd-exp-template-path');
		var $redirect = $row.find('.pbd-exp-redirect-url');
		if (type === 'redirect') {
			$template.attr('hidden', 'hidden');
			$redirect.removeAttr('hidden');
		} else {
			$redirect.attr('hidden', 'hidden');
			$template.removeAttr('hidden');
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

		// Total-weight warning. We don't enforce 100, just flag when it's clearly off.
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
		if ($field.is(':visible')) return; // user is editing key directly
		var val = $key.val() || slugify($name.val());
		if (val) {
			$preview.text(val);
			$badge.removeAttr('hidden');
		} else {
			$badge.attr('hidden', 'hidden');
		}
	}

	function gatherValidationErrors() {
		var errors = [];
		if (!$('#name').val().trim()) errors.push('Name is required.');
		var key = ($('#experiment_key').val() || '').trim();
		if (!key && !$('#name').val().trim()) errors.push('Key is required.');
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

		$variants.on('change', '.pbd-exp-variant-type', function () {
			syncVariantTypeFields($(this).closest('tr'));
		});

		$variants.on('input change', '.pbd-exp-weight, .pbd-exp-variant-label', recomputeShares);

		if ($.fn.sortable) {
			$variants.find('tbody').sortable({
				handle: '.pbd-exp-drag',
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

		// Auto-fill experiment_key from name on the new-experiment screen.
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
			});
			updateKeyBadge();
		}

		// Reveal the key editor when the user clicks "edit" on the badge.
		$('#pbd-exp-key-edit').on('click', function () {
			$('#pbd-exp-key-field').removeAttr('hidden');
			$('#pbd-exp-key-badge').attr('hidden', 'hidden');
			$('#experiment_key').trigger('focus');
		});

		// Cookie days human translation.
		$('#cookie_days').on('input change', updateCookieHelper);
		updateCookieHelper();

		// Logged-in users warning.
		$('#include_logged_in').on('change', function () {
			var $note = $('#pbd-exp-loggedin-warning');
			if ($(this).is(':checked')) $note.removeAttr('hidden');
			else $note.attr('hidden', 'hidden');
		});

		// Metric snippet help popover toggle.
		$('#pbd-exp-metric-help-btn').on('click', function () {
			var $pop = $('#pbd-exp-metric-help');
			var open = !$pop.is(':visible');
			if (open) $pop.removeAttr('hidden');
			else $pop.attr('hidden', 'hidden');
			$(this).attr('aria-expanded', open ? 'true' : 'false');
		});
		$('#pbd-exp-metric-help .pbd-exp-popover__close').on('click', function () {
			$('#pbd-exp-metric-help').attr('hidden', 'hidden');
			$('#pbd-exp-metric-help-btn').attr('aria-expanded', 'false');
		});

		$('#pbd-exp-add-variant').on('click', function () {
			var i = nextIndex($variants);
			var html = '<tr class="pbd-exp-variant-row" data-existing-id="0">' +
				'<td class="pbd-exp-drag" title="Drag to reorder" aria-label="Drag to reorder"><span class="pbd-exp-drag__grip" aria-hidden="true"></span></td>' +
				'<td><input type="hidden" name="variants[' + i + '][id]" value="0">' +
					'<input type="text" name="variants[' + i + '][variant_key]" placeholder="variant_key"></td>' +
				'<td><input type="text" name="variants[' + i + '][label]" placeholder="Label" class="pbd-exp-variant-label"></td>' +
				'<td><input type="number" name="variants[' + i + '][weight]" min="0" value="50" class="pbd-exp-weight">' +
					'<span class="pbd-exp-variant-share">0%</span></td>' +
				'<td><div class="pbd-exp-segmented pbd-exp-variant-type-seg" role="radiogroup" aria-label="Destination type">' +
					'<label><input type="radio" name="variants[' + i + '][variant_type]" value="template" class="pbd-exp-variant-type" checked><span>Template</span></label>' +
					'<label><input type="radio" name="variants[' + i + '][variant_type]" value="redirect" class="pbd-exp-variant-type"><span>Redirect</span></label>' +
				'</div></td>' +
				'<td class="pbd-exp-dest-cell">' +
					'<input type="text" name="variants[' + i + '][template_path]" placeholder="page-variant.php" class="pbd-exp-template-path">' +
					'<input type="text" name="variants[' + i + '][redirect_url]" placeholder="/variant-url/" class="pbd-exp-redirect-url" hidden>' +
				'</td>' +
				'<td class="col-remove"><button type="button" class="pbd-exp-remove-row" aria-label="Remove variant"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>' +
				'</tr>';
			$variants.find('tbody').append(html);
			recomputeShares();
		});

		$('#pbd-exp-add-metric').on('click', function () {
			var i = nextIndex($metrics);
			var html = '<tr class="pbd-exp-metric-row">' +
				'<td><input type="hidden" name="metrics[' + i + '][id]" value="0">' +
					'<input type="text" name="metrics[' + i + '][metric_key]" placeholder="metric_key"></td>' +
				'<td><input type="text" name="metrics[' + i + '][name]" placeholder="Display Name"></td>' +
				'<td><input type="text" name="metrics[' + i + '][event_name]" placeholder="event_name"></td>' +
				'<td class="col-active"><input type="checkbox" name="metrics[' + i + '][active]" value="1" checked></td>' +
				'<td class="col-remove"><button type="button" class="pbd-exp-remove-row" aria-label="Remove metric"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>' +
				'</tr>';
			$metrics.find('tbody').append(html);
		});

		// Mirror metric_key into event_name (until either has been edited manually)
		$metrics.on('input', 'input[name$="[metric_key]"]', function () {
			var $row = $(this).closest('tr');
			var $event = $row.find('input[name$="[event_name]"]');
			if (!$event.data('touched') && !$event.val()) {
				$event.val(slugify($(this).val()));
			}
		});
		$metrics.on('input', 'input[name$="[event_name]"]', function () {
			$(this).data('touched', true);
		});

		$(document).on('click', '.pbd-exp-remove-row', function () {
			var $row = $(this).closest('tr');
			var inVariants = $row.closest('#pbd-exp-variants').length > 0;
			$row.remove();
			if (inVariants) {
				recomputeShares();
			}
		});

		// Copy-to-clipboard for snippet helpers
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

		// Inline validation summary on submit attempt.
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
	});
})(jQuery);
