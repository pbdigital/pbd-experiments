# PBD Experiments admin: improvement wish list

A running list of UX, intuitiveness, and functionality ideas across every admin screen. Organised by screen, then by tier: **Quick wins** (small, mostly UI), **Bigger lifts** (real feature work), **Stretch** (ambitious).

Nothing here is committed work. This is a menu to pull from.

---

## Cross-cutting (applies to every screen)

### Quick wins
- Persistent breadcrumbs or back-link affordance on every sub-screen (we have "All experiments" on edit/dashboard, but archive could use a sibling link).
- Tooltips on the status pills explaining what each state actually does for visitors.
- Keyboard shortcut: `n` to start a new experiment from the list, `/` to focus search on archive.
- Inline doc links: a "Help" link in the top right of each screen that opens a side panel with the relevant section of the README.
- "Copy link to this dashboard" button (especially useful for sharing in Slack).

### Bigger lifts
- **Activity log per experiment.** Who started it, who paused it, who concluded it, and when. Right now the only timestamp we keep is `started_at` and `concluded_at`.
- **Role-based permissions.** Today everything is gated on `manage_options`. A "viewer" role that can see dashboards but not change status would let non-admins watch tests without risk.
- **Notification triggers.** Optional Slack webhook or email when an experiment goes SRM, when a metric hasn't fired in N days, or when a long-running test crosses a milestone (e.g. 1000 visitors per arm).
- **Audit trail of config changes.** Variants added, weights changed, metrics flipped. Important for forensics on weird results.

### Stretch
- A "tag" system so related experiments group together (e.g. `homepage`, `pricing`, `Q3-onboarding`). Filter and aggregate by tag.
- Lightweight onboarding checklist when the plugin is first activated: "Set your timezone, create your first experiment, install the JS snippet on a test page."

---

## List screen (Experiments)

### Quick wins
- **Whole row is clickable** to the dashboard, not just the buttons.
- **Show "last event N min ago"** per experiment so it's obvious at a glance whether tracking is live.
- **Show the primary metric headline** (rate + lift vs control) right in the row, so you don't have to open the dashboard to know who's winning.
- **Quick-action row controls.** Pause an active test, resume a paused one, or duplicate as new without leaving the list.
- **Sort columns.** Visitors descending is the most useful default for active tests; updated_at desc is good for drafts.
- **Inline search-as-you-type.** A search box above the filter tabs that filters the table client-side.
- Show a small target-path icon when more than one experiment shares the same path (potential conflict).

### Bigger lifts
- **"Duplicate as new"** row action. Clone variants + metrics into a fresh draft so you can iterate.
- **Bulk actions.** Pause selected, conclude selected, delete drafts.
- **Conflict warning banner.** If two active experiments target the same `target_path`, surface that loudly. Today the dispatcher picks the first match, which is invisible.
- **Tracking health column.** Green dot if events recorded in the last hour, amber if last event > 24h, red if no events since `started_at`. Saves the trip into Recent Events to diagnose.
- **"Recent events" filter.** Limit to a specific experiment, or to off-target events (a frequent debugging case).

### Stretch
- Drag to set explicit priority among experiments that share a target path, instead of relying on insertion order.
- Drafts have their own subview/section so live experiments and ideas-in-progress don't compete for attention in the same table.

---

## Edit screen (Add / Edit experiment)

### Quick wins
- **Dirty-form warning** on navigate away (the existing "Cancel" button is a soft trap if you've made changes).
- **Live preview links per variant** while editing: "View on site as Control" / "View as Variant" using `?pbd_exp_variant=` so you can sanity-check the template swap before saving.
- **Template path picker.** Dropdown of `*.php` files in the active theme + child theme, instead of typing a filename and praying. Free-text fallback for advanced cases.
- **Validate basics on save:** at least 2 variants, weights not all zero, variant keys unique, redirect URLs not empty when type is `redirect`. Right now invalid rows are silently dropped or refused.
- **Visible warning** when `experiment_key` clashes with an existing one (currently relies on DB unique constraint failing).
- **Hypothesis field** separate from Notes. "I expect Variant B to lift opt-in by 10%" framed as its own input forces clearer thinking and reads back well on the archive.
- **Snippet helper auto-pulls the first metric's event name** instead of always saying `opt_in`. Less editing for the user.
- **Inline link to "Test mode"** so admins can see a variant via cookie without committing real assignment data.

### Bigger lifts
- **Variant cards instead of a table.** Each variant gets a small card with the destination input, a "Preview" link, optional screenshot thumb, and the weight + share. Reads better when destinations are long URLs or paths.
- **Pre-flight checklist** before Start. A small card that turns from amber to green as you tick: at least 2 variants present, weights sum > 0, at least one active metric, target path resolves to a real WP URL, redirect URLs respond 200. Then Start is enabled.
- **Path validator.** When you type a `target_path`, AJAX a quick check: does WP resolve it, what template would render? Catches typos and 404 targets at config time.
- **Conflict detection on save.** Refuse (or warn loudly) if another active experiment uses the same target path.
- **Per-variant screenshot upload** for visual identification on the dashboard and archive.
- **Allow `target_path` patterns.** Wildcard suffix (`/blog/*`), or a list of explicit paths, so a single experiment can cover a section.

### Stretch
- **Visual diff of variant templates** (read the template file off disk, show the differences from the control template).
- Markdown or rich-text notes editor.
- Inline traffic estimator: "At your current visitor volume, this experiment will reach 1000 visitors per arm in ~6 days."

---

## Dashboard (per-experiment results)

### Quick wins
- **Date window presets:** Last 7 days, Last 14 days, Last 30 days, Since start. Saves picking dates manually.
- **Donut of observed traffic split** alongside the configured weights, so SRM is visible before the warning fires.
- **Tracking health indicator** at the top: "Last event 3 minutes ago" or "No events since started_at, check your tracking."
- **Per-metric tabs.** When there are 3+ metrics, the wide table gets cramped. Tabs (or radio-style toggles) collapse to one metric at a time, with "All metrics" view as an option.
- **CSV export** of the current report.
- **Auto-refresh** toggle (every 30s) for actively running tests.
- **Tooltip on the lift cells** explaining the calculation in plain English.
- Hide the Actions bar at the bottom if user role can't transition.

### Bigger lifts
- **Time-series chart.** Visitors and conversions per day, per variant. The biggest single UX upgrade for this screen.
- **Statistical confidence indicator.** Even a simple frequentist z-test or Bayesian probability-of-being-best, surfaced as "Variant B is ahead, but with the current sample size we're 70% confident." Stops people calling winners too early.
- **Sample-size calculator card.** "You need ~2,400 more visitors per arm to detect a 5% lift at 95% confidence." Tied to current visitor velocity to estimate "about 6 more days."
- **Per-variant conversion funnel** when multiple metrics are configured. See where Variant B picks up the win, not just that it wins.
- **Segment slicing.** Logged-in vs anonymous, new vs returning visitor (cookie-age based), top referrers, device type if we capture it. Reveals when a win is real vs an artefact.
- **Annotations on the chart.** When config changes mid-flight (weights adjusted, metric activated), mark the time on the chart so you can see whether results shifted.

### Stretch
- **Pairwise variant comparison** when more than 2 arms. Today everything's compared to the first row (control). Sometimes you want B vs C.
- **Anonymous share link** that renders a read-only public dashboard for sharing with clients.
- **Weekly summary email** of running experiments, opt-in per experiment.

---

## Archive (Past Experiments)

### Quick wins
- **Sort dropdown:** Most recent, longest run, highest lift, biggest sample.
- **Filter by outcome:** Has winner, no winner declared, abandoned (no data recorded). Helps with "what have we actually learned?" reviews.
- **Re-run as new** button on every card. Clones config to a fresh draft.
- **Print-friendly view** of a single archived experiment (clean layout, no admin chrome) for embedding in client reports.
- **"Pin to top"** or star on important experiments so the lessons surface above noise as the archive grows.

### Bigger lifts
- **Lessons-learned field** separate from Notes. Filled in *after* concluding. Tag-able so you can search "what did we learn about pricing pages."
- **Compare two archived experiments** side by side. Same target path, before and after, what changed.
- **Roll-up by tag.** All experiments tagged `pricing`: aggregate winning rate, total visitors tested, common patterns.
- **Restore to active** with a confirm warning. Useful for tests we concluded too early.
- **Export everything** as a JSON archive for portability or backup.

### Stretch
- A "best-of" section that surfaces the highest-lift winners across the whole archive, partly as a morale boost, partly as an idea library.
- Auto-generated narrative summary per archived experiment: "Ran 14 days, 12k visitors, Variant B won opt-in by 18% at 95% confidence" generated from the snapshot.

---

## Tracking and developer ergonomics (not screens, but admin-adjacent)

### Quick wins
- **Admin frontend toolbar widget.** When an admin browses the site, show which experiment + variant they're assigned to and a "Force variant" dropdown that overrides cookie for the session.
- **In-page debug panel** behind a `?pbd_exp_debug=1` query string, showing dispatched experiment, assigned variant, metrics fired, REST endpoint responses.

### Bigger lifts
- **Force-variant link generator** per variant in the edit screen. One-click copyable URL like `https://site.com/free-classes/?pbd_exp_variant=variant&pbd_exp_preview=key` that bypasses normal assignment for QA.
- **Built-in event tester.** A button on the Edit screen that fires a test event against the experiment + a chosen variant. Confirms the REST endpoint works without instrumenting the page first.

### Stretch
- **Integration helpers.** First-class hooks for GA4, Google Tag Manager, Microsoft Clarity, Meta Pixel: when an assignment happens, also push an event into the configured analytics tool with the experiment + variant.

---

## Triage suggestion

If the goal is "I'd happily hand this to Joe or Carlo tomorrow," the next sweep would focus on the **edit screen pre-flight checklist**, **dashboard time-series chart + tracking-health indicator**, and **list-screen primary-metric column + tracking-health column**. Those three together remove the biggest sources of "wait, is this thing actually working?" and "who's winning?" anxiety.

The cross-cutting activity log and the per-experiment hypothesis field are the next layer down. Cheap to add, high signal for future-Paul (and future-Carlo) revisiting old tests.
