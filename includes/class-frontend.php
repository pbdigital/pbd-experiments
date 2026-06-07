<?php
defined( 'ABSPATH' ) || exit;

/**
 * Inline front-end script: tracking API, Clarity custom-tag pass-through,
 * GTM dataLayer push, and the admin preview badge for logged-in admins.
 */
final class PBD_Exp_Frontend {

	/** @var PBD_Exp_Dispatcher */
	private $dispatcher;

	public function __construct( PBD_Exp_Dispatcher $dispatcher ) {
		$this->dispatcher = $dispatcher;
	}

	public function hook() {
		add_action( 'wp_head', array( $this, 'print_script' ), 1 );
		add_action( 'wp_footer', array( $this, 'maybe_print_admin_badge' ), 100 );
	}

	public function print_script() {
		if ( is_admin() ) {
			return;
		}

		$context = $this->current_context();
		$is_preview = is_user_logged_in() && current_user_can( 'manage_options' );

		$config = array(
			'restUrl'  => esc_url_raw( rest_url( 'pbd-experiments/v1/events' ) ),
			'context'  => $context,
			'preview'  => $is_preview,
			'triggers' => $context ? $this->form_triggers( (int) $context['experiment_id'] ) : array(),
		);
		?>
		<script>
		(function(w,d){
			var config = <?php echo wp_json_encode( $config ); ?>;
			var sentOnce = {};

			function toSnake(name) {
				return String(name || '').replace(/^pbdMeta/, '').replace(/[A-Z]/g, function(ch, i) {
					return (i ? '_' : '') + ch.toLowerCase();
				}).replace(/^_/, '');
			}

			function metadataFromForm(form) {
				var out = {};
				if (!form || !form.dataset) return out;
				for (var key in form.dataset) {
					if (key.indexOf('pbdMeta') === 0) out[toSnake(key)] = form.dataset[key];
				}
				return out;
			}

			function send(payload) {
				var body = JSON.stringify(payload);
				if (w.navigator && typeof w.navigator.sendBeacon === 'function') {
					try {
						var blob = new Blob([body], { type: 'application/json' });
						if (w.navigator.sendBeacon(config.restUrl, blob)) return;
					} catch (e) {}
				}
				if (typeof w.fetch === 'function') {
					w.fetch(config.restUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						credentials: 'same-origin',
						keepalive: true,
						body: body
					}).catch(function(){});
				}
			}

			function track(eventName, options) {
				options = options || {};
				var context = config.context || {};
				var experiment = options.experiment || context.experiment_key;
				if (!experiment || !eventName) return;

				var once = options.once !== false;
				var onceKey = experiment + '|' + eventName;
				if (once && sentOnce[onceKey]) return;
				if (once) sentOnce[onceKey] = true;

				var variant = options.variant || context.variant_key || '';
				var metadata = options.metadata || {};

				// Admin preview: no dataLayer/server-side event, prevents pollution of GA4/Clarity and the events table.
				if (config.preview) return;

				w.dataLayer = w.dataLayer || [];
				w.dataLayer.push(Object.assign({
					event: eventName,
					experiment_id: experiment,
					experiment_variant: variant
				}, metadata));

				send({
					experiment: experiment,
					event: eventName,
					once: once,
					variant: variant,
					metadata: metadata
				});
			}

			function pushClarityTags() {
				var context = config.context;
				if (!context || !context.experiment_key) return;
				if (config.preview) return;

				// Microsoft Clarity custom tags. clarity() may not exist yet
				// (snippet loads async); retry until it does, give up after 10s.
				var tries = 0;
				var maxTries = 40;
				var iv = setInterval(function() {
					tries++;
					if (typeof w.clarity === 'function') {
						try {
							w.clarity('set', 'experiment', context.experiment_key);
							w.clarity('set', 'variant', context.variant_key || 'unassigned');
						} catch (e) {}
						clearInterval(iv);
					} else if (tries >= maxTries) {
						clearInterval(iv);
					}
				}, 250);
			}

			function pushDataLayerExposure() {
				var context = config.context;
				if (!context || !context.experiment_key) return;
				if (config.preview) return;
				w.dataLayer = w.dataLayer || [];
				w.dataLayer.push({
					event: 'experiment_viewed',
					experiment_id: context.experiment_key,
					experiment_variant: context.variant_key || 'unassigned'
				});
			}

			function bindForms() {
				var forms = d.querySelectorAll('form[data-pbd-exp][data-pbd-event]');
				for (var i = 0; i < forms.length; i++) {
					forms[i].addEventListener('submit', function(e) {
						var form = e.currentTarget;
						if (form.checkValidity && !form.checkValidity()) return;
						track(form.getAttribute('data-pbd-event'), {
							experiment: form.getAttribute('data-pbd-exp'),
							once: true,
							metadata: metadataFromForm(form)
						});
					});
				}
			}

			// Selector matching for delegated events. An invalid selector never
			// matches (returns false) rather than throwing.
			function matchesSelector(el, sel) {
				if (!el || el.nodeType !== 1) return false;
				var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
				if (!fn) return false;
				try { return fn.call(el, sel); } catch (e) { return false; }
			}
			function closestMatch(el, sel) {
				for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
					if (matchesSelector(n, sel)) return n;
				}
				return null;
			}

			// Form-platform adapters. Each binds via delegation on the document so
			// forms injected later (modals, AJAX) are still covered, then calls
			// fire(formEl) when the targeted form converts.
			var formAdapters = {
				// Standard / Infusionsoft / Keap: native submit. Capture phase so it
				// still runs when the page navigates away on a full-page POST.
				native: function(trigger, fire) {
					d.addEventListener('submit', function(e) {
						var form = closestMatch(e.target, trigger.selector);
						if (!form) return;
						if (form.checkValidity && !form.checkValidity()) return;
						fire(form);
					}, true);
				},
				// Contact Form 7: native CustomEvent dispatched on successful send.
				cf7: function(trigger, fire) {
					d.addEventListener('wpcf7mailsent', function(e) {
						var form = closestMatch(e.target, trigger.selector);
						if (!form && e.target && e.target.querySelector) {
							try { form = e.target.querySelector(trigger.selector); } catch (err) {}
						}
						if (form) fire(form);
					});
				}
			};

			function bindTriggers() {
				var triggers = config.triggers || [];
				var ctx = config.context || {};
				for (var i = 0; i < triggers.length; i++) {
					(function(trigger) {
						if (!trigger || !trigger.selector || !trigger.event) return;
						var adapter = formAdapters[trigger.form_type] || formAdapters.native;
						adapter(trigger, function(formEl) {
							track(trigger.event, {
								experiment: ctx.experiment_key,
								once: true,
								variant: ctx.variant_key,
								metadata: formEl ? metadataFromForm(formEl) : {}
							});
						});
					})(triggers[i]);
				}
			}

			w.PBDExperiments = w.PBDExperiments || {};
			w.PBDExperiments.config = config;
			w.PBDExperiments.context = config.context;
			w.PBDExperiments.track = track;

			pushDataLayerExposure();
			pushClarityTags();

			function bindAll() { bindForms(); bindTriggers(); }
			if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', bindAll);
			else bindAll();
		})(window, document);
		</script>
		<?php
	}

	public function maybe_print_admin_badge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$context = $this->current_context();
		if ( ! $context || empty( $context['experiment_key'] ) ) {
			return;
		}

		$label = sprintf(
			/* translators: 1: experiment key, 2: variant label, 3: variant key */
			'Experiment: %1$s | Variant: %2$s (%3$s) | preview (no events recorded)',
			$context['experiment_key'],
			$context['variant_label'],
			$context['variant_key']
		);
		?>
		<div id="pbd-exp-admin-badge" style="position:fixed;bottom:16px;right:16px;z-index:99999;background:#1d2327;color:#fff;font:13px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;padding:8px 12px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.25);max-width:320px;">
			<strong style="display:block;font-size:11px;color:#a7aaad;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">PBD Experiment</strong>
			<?php echo esc_html( $label ); ?>
		</div>
		<?php
	}

	/**
	 * Active form-trigger metrics for the current experiment, shaped for the
	 * front-end auto-binder. Only metrics with trigger_type=form and a non-empty
	 * selector are emitted; everything else (page/js) fires through its own recipe.
	 */
	private function form_triggers( $experiment_id ) {
		$triggers = array();
		foreach ( PBD_Exp_Repo::get_metrics( $experiment_id, true ) as $m ) {
			if ( 'form' !== ( $m['trigger_type'] ?? '' ) ) {
				continue;
			}
			$selector = trim( (string) ( $m['selector'] ?? '' ) );
			if ( '' === $selector ) {
				continue;
			}
			$triggers[] = array(
				'event'     => $m['event_name'],
				'selector'  => $selector,
				'form_type' => ! empty( $m['form_type'] ) ? $m['form_type'] : 'native',
			);
		}
		return $triggers;
	}

	private function current_context() {
		$experiment = $this->dispatcher->current_experiment();
		$assignment = $this->dispatcher->current_assignment();

		if ( ! $experiment || ! $assignment ) {
			return null;
		}

		return array(
			'experiment_key' => $experiment['experiment_key'],
			'experiment_id'  => (int) $experiment['id'],
			'variant_key'    => $assignment['variant_key'],
			'variant_label'  => $assignment['label'],
			'variant_id'     => (int) $assignment['variant_id'],
		);
	}
}
