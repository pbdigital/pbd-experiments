# PBD Experiments

Agency-grade WordPress split-testing. Internal PB Digital tool. Drop-in plugin: zip upload, activate, configure tests from wp-admin, watch the numbers.

## What it does

- Assigns a visitor to a variant on a target page, stickily, via a long-lived first-party cookie.
- Dispatches the variant either as a **template swap** (renders a different theme template) or as a **redirect** (302 to a different same-site URL).
- Records arbitrary events tied to the visitor's experiment context (`experiment_viewed`, `opt_in`, `purchase`, anything).
- Reports per-variant visitors, per-metric conversions, conversion rate, and lift vs control. Multi-metric. Date-windowed. Flags sample ratio mismatch.
- Pushes experiment context into the GTM dataLayer and Microsoft Clarity custom tags, so GA4 funnels, Clarity recordings, and any other downstream tooling can be sliced by variant with zero per-site setup.

## Quick start on a new client site

1. Upload the `pbd-experiments` folder to `wp-content/plugins/` (zip upload through wp-admin works too).
2. Activate the plugin. A new **Experiments** menu appears in wp-admin.
3. **Recommended first test: an AA test.** Create one experiment with two variants pointing at the same template (or both with no template override). After meaningful traffic, both arms should show roughly equal conversion rates. If they don't, your assignment or tracking is broken before you ship a real test.
4. Once AA passes, set up your first real test:
   - **Name**: human-readable, e.g. "Free Classes Redesign"
   - **Key**: lowercase identifier, e.g. `free-classes-redesign`
   - **Target path**: site-root-relative, e.g. `/free-classes`
   - **Variants**: control + one or more challengers. Pick template or redirect type per variant.
   - **Metrics**: one or more event names that the page (or thank-you page) will fire on conversion.
5. Flip status to **Active**. The plugin assigns visitors, dispatches variants, and records events.

## Firing events from a page

Three equivalent ways, pick whichever fits the surface you're instrumenting.

### 1. JavaScript API

```js
PBDExperiments.track('opt_in', {
  experiment: 'free-classes-redesign',   // optional; defaults to current page's experiment
  once: true,                            // default; deduplicates per visitor
  metadata: { source: 'hero_form' }
});
```

`PBDExperiments.context` is available in the browser console on any active experiment page. Use it for debugging.

### 2. Shortcode (for thank-you pages and off-site checkout returns)

```
[pbd_experiment_event experiment="free-classes-redesign" event="purchase"]
```

Renders nothing visible by default. The shortcode reads the visitor's existing assignment cookie, so an off-site checkout round-trip (Keap, Stripe, anything) still attributes the conversion to the right variant when the user lands on the on-site thank-you page.

For QA, add `debug="true"` to print a visible confirmation of what fired:

```
[pbd_experiment_event experiment="free-classes-redesign" event="purchase" debug="true"]
```

### 3. Form data attributes (auto-binding)

Add `data-pbd-exp` and `data-pbd-event` to any form. The plugin auto-binds a submit handler and fires the event on successful submission. Any `data-pbd-meta-*` attributes are passed through as event metadata.

```html
<form data-pbd-exp="free-classes-redesign"
      data-pbd-event="opt_in"
      data-pbd-meta-source="hero">
  ...
</form>
```

## Analytics integrations (no per-site config required)

### GTM dataLayer

On every active experiment page, the plugin pushes:

```js
{
  event: 'experiment_viewed',
  experiment_id: 'free-classes-redesign',
  experiment_variant: 'variant'
}
```

Every conversion event (`opt_in`, `purchase`, etc.) is also pushed with the same `experiment_id` / `experiment_variant` keys. Wire those into GA4 event parameters in GTM and the variant becomes a slice-by dimension everywhere GA4 reports.

### Microsoft Clarity custom tags

When Clarity is detected on the page, the plugin calls:

```js
clarity('set', 'experiment', 'free-classes-redesign');
clarity('set', 'variant', 'variant');
```

In the Clarity dashboard, every session, heatmap, click map, and funnel can then be filtered by variant. Single highest-leverage integration; zero extra setup beyond having Clarity installed.

## Operational rules baked in

- **Logged-in WordPress users excluded by default.** Toggle per-experiment with the "Include logged-in users" checkbox on the Edit screen.
- **Bot user-agents excluded.** Common bots filtered cheaply via UA regex. Customise via the `pbd_exp_bot_ua_regex` filter.
- **Admin preview badge.** When logged in as an admin, you see a fixed-position "Experiment X, Variant Y" badge in the bottom-right of any active experiment page.
- **Forced variant query param.** `?pbd_exp_variant=variant_key` forces an assignment for QA. `?pbd_exp_clear=1` clears the visitor's assignment.
- **No cache-page interference.** Active experiment pages auto-flag `DONOTCACHEPAGE`, send no-cache headers, and emit `Surrogate-Control: no-store` for upstream CDNs.
- **Sample ratio mismatch warning.** When per-arm visitors clear 500 and the observed split deviates from the configured split by more than 5 percentage points on any arm, the dashboard flags it. Caching bugs, bot inflation, and broken redirects all surface here before you trust the numbers.

## Status workflow

- **Draft**: configured but not assigning visitors. Can transition to Active or Paused.
- **Active**: assigning visitors and recording events. Can transition to Paused or Concluded.
- **Paused**: not assigning new visitors; existing assignments still resolve sticky and existing events still record. Can transition back to Active or to Concluded.
- **Concluded**: terminal. Snapshot frozen, moved to Past Experiments. To re-run, clone the experiment as a new one.

## Past Experiments archive

Every experiment that hits Concluded has its dashboard frozen into a snapshot and lands in **Experiments &rarr; Past Experiments**. Searchable by name or key. Useful answer to "what did we try last year?" without needing to dig into the database.

## Schema

Tables live under the `wp_pbd_experiments_*` prefix:

- `wp_pbd_experiments_experiments`
- `wp_pbd_experiments_variants`
- `wp_pbd_experiments_assignments`
- `wp_pbd_experiments_events`
- `wp_pbd_experiments_metrics`
- `wp_pbd_experiments_snapshots`

Self-contained per-install. No phone-home, no shared service, no central agency dashboard (per v1 scope).

## Known limitations (v1)

- **Cross-domain assignment isn't supported.** The visitor cookie is per-domain. A visitor bucketed on `example.com` who converts on `members.example.com` won't be attributed. Flag this on any test that crosses subdomains.
- **Direct landings on a variant URL** (someone bookmarks or shares the variant's destination URL, bypassing the entry URL) are treated as unassigned. They don't fire `experiment_viewed` or pollute stats.
- **No built-in significance math.** Raw numbers and lift only. Use Clarity recordings and GA4 funnels for qualitative judgement.
- **One experiment per target path.** If two active experiments target the same path, the first match wins. Mutex groups across overlapping pages are out of v1.

## Filters

- `pbd_exp_bot_ua_regex` — override the bot UA regex used for exclusion.
