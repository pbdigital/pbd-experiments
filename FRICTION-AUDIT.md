# Friction audit, non-technical user

## Persona

A site owner who has set up Mailchimp once or twice and knows what "the page on my website" means. They have never written PHP, do not know what a "template file" is, have never copy-pasted a JS snippet into a theme, and have never heard of `dataLayer`, `clarity`, or `snake_case`. They understand the core idea: "show two versions of a page, see which converts better."

## How I walked through it

- Landed on the **Add Experiment** screen. Got as far as Name and Target URL fine (the URL field with the host prefix and page dropdown is the best part of the screen). Stalled hard at the **Variants** table's "TEMPLATE FILE" column: nothing tells me what files exist, the placeholder `page-variant.php` is invented, and there's no way to discover what my theme actually offers. A real first-time user would either guess and silently break their test or close the tab.
- Toggled a variant to **Redirect**. The column header above it still reads "TEMPLATE FILE" even though the input is now a URL. Confusing.
- Scrolled to **Metrics**. The default row is pre-filled with `opt_in`, `Opt-in`, `opt_in`. I have three text fields, no idea which is which, and no idea where the "event" comes from. Helper text references "snake_case" and tells me "Event name is what the page fires" — but doesn't say how to fire it, where to fire it from, or what happens if I leave it. The `?` popover does explain it, but the snippets it shows assume I can edit theme files or attach attributes to a form, which is exactly the gap.
- Viewed the **Dashboard** for a draft. Clean but the headline stat says "OPT-IN (PRIMARY)" with `0.00% headline rate` before anything is running. Reads as "broken" not "not started yet." The empty state below is good ("No data yet"); the headline numbers above it undercut it.
- Viewed the **Past Experiments** archive. Good page. Only nit: a concluded test with no winner shows a "Declare winner" call to action, but the winning-variant picker is buried at the bottom of the Edit screen, behind a config-locked wall of greyed-out fields. The user has to scroll past everything they can't edit to find the one thing they can.

## Recommendations

### Priority 1, actual blockers

- **Control should *be* the Target URL by default, not a variant the user has to configure.**
  - **Where:** Edit screen, Variants table. `includes/admin/class-edit.php` `render_variants_table()` and the default-variant seed at lines 44-47.
  - **The friction:** Paul's own reaction during walk-through: "Why would I pick Type for the control? Isn't the control the page I set as the Target URL?" Exactly right. The current screen asks the user to fill in a template file or redirect URL for the control row, even though the answer is implicit: control means "the page that's already there." The user either types the same target page in, copies the variant's value, or guesses, and any wrong answer still saves cleanly.
  - **Proposal:** Treat the first row as the Control, lock it visually, and render it as "Original page at `/free-classes`" (read-only, sourced from the Target URL) with no Type / Template / Redirect inputs at all. The dispatcher already handles "no template swap, no redirect" cleanly because it falls back to the original page; codify that in the data by storing the control variant with `variant_type = 'template'` and an empty template path (which is what happens today). Editable fields on the control row stay limited to Label and Weight. Adding a variant adds a Variant B/C/D row that *does* show the Type + destination controls, because those rows are the ones that diverge from the original.
    - **Bonus:** the section header copy becomes "What's different in each variant?" rather than "List Control first," which is also clearer.
    - **Edge case:** if the user wants the control to use a non-default template too (rare), expose an "Override the original page" link on the control row that reveals the same inputs. Keep the happy path clean.
  - **Effort:** M. Touches the render function, default-variant seed, the JS "Add variant" template, and a tiny tweak to `save_variants()` to keep the first row's `variant_type='template'` and empty paths when the user hasn't overridden.
  - **Why this is the highest-leverage change in the audit:** it removes an entire decision the user shouldn't have to make, and makes the "what does this plugin actually do" mental model click in one screenful: target page is the control, variants are what changes.

- **The shortcode is invisible until the user opens the popover, and even then they don't know what a shortcode is or where to put it.**
  - **Where:** Metrics card, snippet popover. `includes/admin/class-edit.php` `render_metric_snippet_popover()` lines 336-362. Also the actual shortcode handler at `includes/class-shortcode.php`.
  - **The friction:** Paul's reaction: "I thought you can add a shortcode to a thank-you page to track the conversion. I've got no idea how you would do that or even what the shortcode is." Three layers of friction stacked:
    1. The snippet only shows after clicking a `?` icon they have to notice exists.
    2. The popover lists three options (JS, form attributes, shortcode) with no guidance on which to pick.
    3. Even after picking shortcode, the instruction is "Drop on a thank-you page or success view." A non-technical user doesn't know how to "drop a shortcode on a page" without being told to edit the page in WP and paste the code into a Shortcode block.
  - **Proposal:** Two-part fix.
    1. **Show the shortcode for the most likely use case inline next to the metric row**, no popover required. Once a metric has a name, render a small `[pbd_experiment_event event="opt_in" experiment="free_classes_homepage"] Copy` line under the row with a one-line instruction: "Paste this into the success page in WordPress (Edit page, add a Shortcode block, paste, save)."
    2. **Replace the three-options-take-your-pick popover with one recommended path per metric type.** Add an explicit "What triggers this metric?" dropdown to each metric row with three options:
       - "Visiting a specific page" (default) → shows the shortcode block plus a Page picker for which page to put it on, and ideally a "Add the shortcode for me" button that programmatically inserts the shortcode block into that page using the REST API. Falls back to copy-paste if auto-insert isn't possible.
       - "Submitting a form" → shows the `data-pbd-exp` / `data-pbd-event` attributes with a one-line explanation.
       - "Custom JavaScript" → shows the `PBDExperiments.track()` snippet for power users.
       This routes each user to one path instead of asking them to evaluate three.
  - **Effort:** M for inline shortcode + copy rewrite. L for the auto-insert-into-page action (REST call against the page's content blocks). Ship the M now, file the L as a follow-up.
  - **Why this is a blocker:** without a way to fire the event, the metric records nothing, the dashboard reports `0.00%`, and the user has no way to tell whether the plugin is broken or they did the setup wrong.

- **Replace the "Template file" text input with a dropdown of the theme's registered page templates.**
  - **Where:** Edit screen, Variants table, "Template file" column. `includes/admin/class-edit.php` `render_variants_table()` lines 389 and 423-425; matching client-side `Add variant` HTML in `assets/admin.js` lines 198-200.
  - **The friction:** A non-technical user does not know what `.php` files exist in their theme. The placeholder `page-variant.php` is invented and won't resolve. The dispatcher silently falls back to the default template when a path doesn't resolve (`includes/class-frontend.php` `resolve_template_path()` returns `''` and the dispatcher returns the original `$template`), so a wrong filename produces a "test that looks like it's running" with both arms showing identical pages. Brutal failure mode.
  - **Proposal:** Make this a dropdown sourced from `wp_get_theme()->get_page_templates( null, 'page' )`, the same source WordPress uses for the Pages screen's Template dropdown. Default selection: the currently-active template of the page the Target URL points to (look it up by path, same way the target picker datalist already does, then call `get_page_template_slug( $page_id )`). Include "Default template" as an explicit option, and keep a final "Other file..." item that reveals the existing text input as the escape hatch for power users who want to point at an arbitrary file under the theme. Apply to the existing variant row, the `+ Add variant` JS template, and to a small REST/AJAX endpoint that returns the template list for the picked target page (so changing the Target URL refreshes the available templates).
    - **Option B, recommended as a future v2 not v1:** add a third variant `type` called "Page" that lets the user pick another WordPress Page from a dropdown, and the dispatcher serves that page's template and content instead of swapping a template file. This matches the "I want to A/B two pages I already built in the editor" mental model better than either Template or Redirect, but it requires a data-model addition (`target_post_id` column) and a `template_include` change. Worth doing later; not required now.
    - **Option C:** simply show the current target page's existing template inline as read-only text under the Template column, so the user at least knows what filename to mimic. Cheapest, but still leaves them on their own.
  - **Recommended primary:** Option A (dropdown of registered templates). Option B is the bigger UX win but a bigger build. Ship A now, file B as a "Page variant mode" follow-up.
  - **Effort:** M for Option A (new control, AJAX endpoint, small JS change). L for Option B.

- **Change the "Template file" column header label based on the selected Type.**
  - **Where:** Edit screen, Variants table header, `includes/admin/class-edit.php` line 389 (`<th class="col-dest"><span class="js-dest-label">Template file</span></th>`).
  - **The friction:** Toggling a row to Redirect changes the input below but the column header above it still says "Template file." User has to trust the placeholder text to know they're typing a URL not a filename. With mixed rows (one Template, one Redirect) the header is wrong for one of them.
  - **Proposal:** Either rename the column to "Destination" permanently and rely on the per-row placeholders / inline labels, or move the input label inline with each row's input rather than relying on the column header at all. Easiest: rename to "Destination."
  - **Effort:** S.

- **Stop showing snake_case identifiers (the `Key` columns) as required, separate, user-facing fields on first run.**
  - **Where:** Edit screen, Variants table "KEY" column and Metrics table "KEY" column. `includes/admin/class-edit.php` lines 402-405 (variants) and 462-465 (metrics).
  - **The friction:** A non-technical user is asked to fill in three near-identical fields for each metric (`Key`, `Name`, `Event name`) and two for each variant (`Key`, `Label`). They don't know what a "key" is, and the only difference between Key and Event Name on metrics is "one is for your records, one is what the page fires," which doesn't mean anything to them. The current "auto-fill from name" behavior the JS does on the experiment Key is the right pattern; extend it.
  - **Proposal:** Default the variant `Key` and the metric `Key`/`Event name` to auto-slugified versions of `Label`/`Name`, hidden by default behind an "Advanced" or `edit` link the same way the experiment-level Key is collapsed into a small badge today. The fields still exist for power users; they just don't take up screen real estate on the happy path.
  - **Effort:** M (UI rework on the two tables plus a small JS auto-fill helper, mirroring `updateKeyBadge` from `assets/admin.js`).

### Priority 2, comprehension and confidence

- **Rewrite the Metrics section so the first-time user understands they need to wire something into a page.**
  - **Where:** Edit screen, Metrics card. `includes/admin/class-edit.php` lines 184-194 and the popover at 336-362.
  - **The friction:** The Metrics card looks like "fill in some fields, you're done." Nothing on the surface says "this only works if a page on your site actually fires this event when someone converts." The `?` icon is easy to miss. The current header copy is "One event name per metric. First active metric drives the headline rate," which assumes the reader already knows what "fire an event" means.
  - **Proposal:** Reframe the section header to plain language: "What counts as a conversion?" Sub-line: "Each metric is something the page does when a visitor converts (submits a form, clicks a button, lands on a thank-you page). You'll need to drop a small snippet on the page that fires it; we'll show you how." Auto-open the snippet popover the first time the user adds a metric on a new experiment, or move the snippets out of a popover and into a collapsed "Show me how to fire this" section below the table that's open by default until the experiment has recorded its first event.
  - **Effort:** M.

- **Don't show the "OPT-IN (PRIMARY)" headline stat at all when the experiment hasn't started.**
  - **Where:** Dashboard, summary stats. `includes/admin/class-dashboard.php` `render_summary_stats()` lines 128-187.
  - **The friction:** A draft experiment's dashboard shows `Visitors: 0`, `Opt-in (primary): 0` with `0.00% headline rate`, `Running for: --`. Reads as "the test ran and got nothing." The "No data yet, this experiment is still a draft" panel below already says it correctly; the stat cards above contradict it.
  - **Proposal:** When `status === 'draft'`, replace the four stat cards with a single full-width "Not started yet, finish setup and click Start" panel that links back to the Edit screen's Start button. Or grey the cards out and replace numbers with em-dashes plus a "starts when you click Start" hint. Same treatment when the experiment is active but `total_visitors === 0`.
  - **Effort:** S.

- **Surface the "declare winner" picker at the top of a concluded experiment, not at the bottom.**
  - **Where:** Edit screen for `status === 'concluded'`. `includes/admin/class-edit.php` lines 196-211.
  - **The friction:** A concluded experiment loads the full Edit form with every field disabled. The one thing the user came to do (declare a winner) is below all of that. The archive's "Declare winner" link drops them at the top of a wall of locked fields.
  - **Proposal:** When concluded, either render a dedicated "Concluded experiment" screen (winner picker, snapshot summary, link to clone) instead of the full Edit form, or hoist the winner picker into the status bar at the top and collapse the rest of the form into a "Configuration (locked)" accordion. Either fixes the scroll-past-everything-you-can't-edit problem.
  - **Effort:** M.

- **Add a "Clone as new experiment" action on concluded experiments.**
  - **Where:** Concluded Edit screen and Past Experiments cards. `includes/admin/class-edit.php`, `includes/admin/class-archive.php`.
  - **The friction:** README and inline copy both tell the user "to re-run, clone the experiment as a new one." Nothing in the UI clones. The user has to retype every variant, metric, target URL, and notes field from scratch.
  - **Proposal:** Add a "Clone" button that duplicates the experiment record (new key suffix `-2`, copy variants and metrics, status `draft`, no started_at/concluded_at, no winning_variant_id) and lands the user on the new Edit screen.
  - **Effort:** M.

- **Make the "delete" button on the status bar genuinely scary on a live experiment, or hide it.**
  - **Where:** Edit status bar, `includes/admin/class-edit.php` `render_status_bar()` lines 293-300.
  - **The friction:** Currently Delete only shows for `draft` and `concluded`, which is right. But on `concluded` it deletes the experiment **and the snapshot and the historical events**. The confirmation says "Permanently delete this experiment and all its assignments and events? This cannot be undone." which is OK, but for an experiment that's in Past Experiments and being kept as a reference, accidental deletion loses the only record of what happened.
  - **Proposal:** On concluded experiments, replace Delete with "Archive permanently" or "Remove from history" copy that makes the destructive scope explicit. Optionally require typing the experiment key to confirm, the way GitHub does for repo deletion. Or split into two actions: "Archive" (hides from active and past lists but keeps the row) and "Delete forever."
  - **Effort:** S for copy/confirmation change. M for archive split.

### Priority 3, polish, defaults, copy

- **Drop the word "Key" everywhere it isn't load-bearing for the user.** "Lowercase identifier used in event tracking, dataLayer, and Clarity tags" is jargon. For an internal PB Digital tool that's fine, for a non-technical owner reuse: "ID used by the tracking code. Auto-fills from the name. You usually won't need to touch this." Files: `includes/admin/class-edit.php` lines 122-126, 393, 450, 483.
- **Soften the Notes placeholder.** "Hypothesis: changing X will increase Y because..." is good for a strategist; for a non-tech user it implies they have to write a hypothesis or skip the section. Try: "Optional. Why are you running this test? What do you hope happens? Future-you will thank you." Same file, line 168.
- **Change "Cookie days" to "Remember a visitor's variant for".** The hint already humanizes it ("≈ 3 months"); leading with "Cookie" forces the user to think about cookies. Same file, line 148.
- **The "+ Add metric" button is below the table.** When the table only has one row and no scroll, this is fine. Once a user has 3-4 metrics it's far from where the eye goes. Low cost: move the button into the table footer row.
- **Recent events table on the list page leaks raw `event_name`, `variant_key`, and full URL.** Useful for confirming tracking, intimidating for a first-time user. Consider collapsing it under a "Show recent activity" disclosure that's closed by default, and showing only the time-since-last-event by default ("Last event: 12 minutes ago, opt_in on Free Classes Redesign"). `includes/admin/class-admin.php` lines 292-327.
- **"Save and continue" actually means "Save and stay on this page."** The button text on a new experiment promises continuation that doesn't happen, you land on the same Edit screen. Either rename to "Save draft" (which is what it does), or actually advance to a next-step page (Add metric, or Dashboard).

## Things to leave alone

- The **Target URL** input with the host prefix and the page-name datalist is genuinely good. Don't change it.
- The **traffic split bar** above the variants table with live percentages is the right way to make weight allocation feel concrete. Keep.
- The **status transition model** (`draft` → `active` → `paused` → `concluded`) is well-scoped and the inline hint under each status is exactly the right level of explanation. Keep.
- **SRM warning copy** on the dashboard is technical but accurate, and the user only sees it if it triggers. Don't water it down.
- **Admin badge in the bottom-right of any active experiment page** is great. Don't touch.
- **Cookie days humanizer** ("≈ 3 months", "≈ each pageview") is exactly the right pattern; please apply it to more numeric inputs over time.

## Open questions for Paul

- Is "Page variant mode" (point a variant at another existing WordPress Page) in or out of v1 vision? It would close the biggest non-technical gap by far, but it adds a data column and changes the dispatcher. Decide before scoping the template-dropdown ticket so you don't ship the dropdown then immediately add a parallel mode.
- Who is this plugin really for: PB Digital's internal team operating client sites, or the client's own staff? The README frames it as agency-internal; the inline tone and helper text frame it as anyone-can-use. Pick one. If internal-only, half the Priority 2 items go away; if client-facing, several Priority 3 items become Priority 1.
- On the Metrics section: would you accept opinionated "metric templates" (a dropdown like Form submit, Button click, Thank-you page visit, Custom event) that generate the right snippet automatically with the chosen event name? That would replace most of the snake_case friction with a 3-click setup. Bigger build but it's the single biggest comprehension lever in the audit.
