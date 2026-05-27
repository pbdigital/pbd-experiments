(function ($) {
	'use strict';

	function nextIndex($table) {
		return $table.find('tbody tr').length;
	}

	function syncVariantTypeFields($row) {
		var type = $row.find('.pbd-exp-variant-type').val();
		$row.find('.pbd-exp-template-path').toggle(type === 'template');
		$row.find('.pbd-exp-redirect-url').toggle(type === 'redirect');
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
		$rows.each(function () {
			var w = Math.max(0, parseInt($(this).find('.pbd-exp-weight').val(), 10) || 0);
			var pct = total > 0 ? Math.round((w / total) * 100) : 0;
			$(this).find('.pbd-exp-variant-share').text(pct + '%');
		});
	}

	$(function () {
		var $variants = $('#pbd-exp-variants');
		var $metrics = $('#pbd-exp-metrics');

		$variants.on('change', '.pbd-exp-variant-type', function () {
			syncVariantTypeFields($(this).closest('tr'));
		});

		$variants.on('input change', '.pbd-exp-weight', recomputeShares);

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
				}
			});
		}

		// Auto-fill experiment_key from name on the new-experiment screen.
		// Only when the key field is editable and the user hasn't typed in it manually.
		var $keyField = $('#experiment_key');
		var $nameField = $('#name');
		if ($keyField.length && $nameField.length && !$keyField.prop('readonly')) {
			var keyTouched = $keyField.val() !== '';
			$keyField.on('input', function () { keyTouched = true; });
			$nameField.on('input', function () {
				if (!keyTouched) {
					$keyField.val(slugify($nameField.val()));
				}
			});
		}

		$('#pbd-exp-add-variant').on('click', function () {
			var i = nextIndex($variants);
			var html = '<tr class="pbd-exp-variant-row" data-existing-id="0">' +
				'<td class="pbd-exp-drag">&#x2630;</td>' +
				'<td><input type="hidden" name="variants[' + i + '][id]" value="0">' +
					'<input type="text" name="variants[' + i + '][variant_key]" placeholder="variant_key"></td>' +
				'<td><input type="text" name="variants[' + i + '][label]" placeholder="Label"></td>' +
				'<td><input type="number" name="variants[' + i + '][weight]" min="0" value="50" class="pbd-exp-weight">' +
					'<span class="pbd-exp-variant-share">0%</span></td>' +
				'<td><select name="variants[' + i + '][variant_type]" class="pbd-exp-variant-type">' +
					'<option value="template">Template</option>' +
					'<option value="redirect">Redirect</option>' +
				'</select></td>' +
				'<td>' +
					'<input type="text" name="variants[' + i + '][template_path]" placeholder="page-variant.php" class="pbd-exp-template-path" style="width:100%;">' +
					'<input type="text" name="variants[' + i + '][redirect_url]" placeholder="/variant-url/" class="pbd-exp-redirect-url" style="width:100%;display:none;">' +
				'</td>' +
				'<td><button type="button" class="pbd-exp-remove-row" aria-label="Remove variant">&times;</button></td>' +
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
				'<td><input type="checkbox" name="metrics[' + i + '][active]" value="1" checked></td>' +
				'<td><button type="button" class="pbd-exp-remove-row" aria-label="Remove metric">&times;</button></td>' +
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
	});
})(jQuery);
