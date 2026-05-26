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

	$(function () {
		var $variants = $('#pbd-exp-variants');
		var $metrics = $('#pbd-exp-metrics');

		// Variant type toggles destination input
		$variants.on('change', '.pbd-exp-variant-type', function () {
			syncVariantTypeFields($(this).closest('tr'));
		});

		// Sortable variants
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

		// Add variant row
		$('#pbd-exp-add-variant').on('click', function () {
			var i = nextIndex($variants);
			var html = '<tr class="pbd-exp-variant-row" data-existing-id="0">' +
				'<td class="pbd-exp-drag">&#x2630;</td>' +
				'<td><input type="hidden" name="variants[' + i + '][id]" value="0">' +
					'<input type="text" name="variants[' + i + '][variant_key]" placeholder="variant_key"></td>' +
				'<td><input type="text" name="variants[' + i + '][label]" placeholder="Label"></td>' +
				'<td><input type="number" name="variants[' + i + '][weight]" min="0" value="50"></td>' +
				'<td><select name="variants[' + i + '][variant_type]" class="pbd-exp-variant-type">' +
					'<option value="template">Template</option>' +
					'<option value="redirect">Redirect</option>' +
				'</select></td>' +
				'<td>' +
					'<input type="text" name="variants[' + i + '][template_path]" placeholder="page-variant.php" class="pbd-exp-template-path" style="width:100%;">' +
					'<input type="text" name="variants[' + i + '][redirect_url]" placeholder="/variant-url/" class="pbd-exp-redirect-url" style="width:100%;display:none;">' +
				'</td>' +
				'<td><button type="button" class="button-link pbd-exp-remove-row">&times;</button></td>' +
				'</tr>';
			$variants.find('tbody').append(html);
		});

		// Add metric row
		$('#pbd-exp-add-metric').on('click', function () {
			var i = nextIndex($metrics);
			var html = '<tr class="pbd-exp-metric-row">' +
				'<td><input type="hidden" name="metrics[' + i + '][id]" value="0">' +
					'<input type="text" name="metrics[' + i + '][metric_key]" placeholder="metric_key"></td>' +
				'<td><input type="text" name="metrics[' + i + '][name]" placeholder="Display Name"></td>' +
				'<td><input type="text" name="metrics[' + i + '][event_name]" placeholder="event_name"></td>' +
				'<td><input type="checkbox" name="metrics[' + i + '][active]" value="1" checked></td>' +
				'<td><button type="button" class="button-link pbd-exp-remove-row">&times;</button></td>' +
				'</tr>';
			$metrics.find('tbody').append(html);
		});

		// Remove row (variant or metric)
		$(document).on('click', '.pbd-exp-remove-row', function () {
			$(this).closest('tr').remove();
		});
	});
})(jQuery);
