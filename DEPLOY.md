# Deployment & cutover handoff

This is the operator's guide for getting `pbd-experiments` v1.0.0 from this dev environment to a real client site. Two real-world deploys are queued: Carla's site (PBD-7 "Done when") and the Bookkeepers cutover (PBD-11).

## Locked decisions (recorded here for the PR description)

| Decision | Choice | Reason |
| --- | --- | --- |
| Plugin slug | `pbd-experiments` (renamed from `pbd-experiment-tracker`) | Per spec recommendation. Clean menu label. |
| Table prefix | `pbd_experiments_` (full names: `wp_pbd_experiments_experiments`, `_variants`, `_assignments`, `_events`, `_metrics`, `_snapshots`) | Clean cut from v0.1's `wp_pbd_exp_*` tables on the Bookkeepers install. No collision risk; migration handled in PBD-11. |
| Redirect cookie ordering | Visitor cookie is `setcookie()`'d BEFORE `wp_safe_redirect()`. Both headers ship in the same response. | Verified in local Docker: redirect response carries `Set-Cookie:` and `Location:` together. The 302 lands on the variant URL with the visitor already cookied. |
| Direct landing on variant URL | Treat as unassigned. No assignment created, no `experiment_viewed` event, no stats pollution. | Per locked decision. The plugin's `target_path` match means only the entry URL triggers assignment. Direct visits to redirect destinations are invisible to the system. |
| Status workflow | `draft` → `active`/`paused`; `active` → `paused`/`concluded`; `paused` → `active`/`concluded`; `concluded` is terminal. To re-run a concluded test, clone it as a new experiment. | Cleanest model. Frozen snapshot stays trustworthy. UI enforces transitions. |

## Pre-deploy sanity check (any target site)

1. Confirm PHP 7.4+ (the plugin uses null-coalescing assignment and arrow functions sparingly; PHP 7.4 is the floor).
2. Confirm WordPress 5.9+ (we use `wp_readonly()`, introduced in 5.9; older WP will fatal on the edit screen).
3. If the site runs a page cache (WP Engine, Cloudways, WP Rocket, LiteSpeed): no changes needed. Active experiment pages auto-flag `DONOTCACHEPAGE`, emit `Cache-Control: no-store`, and emit `Surrogate-Control: no-store` for upstream CDNs.
4. If GTM / GA4 / Clarity are wired: also no changes needed. The dataLayer push and Clarity custom tags fire automatically.

## Deploy to Carla (PBD-7 "Done when")

1. Build the zip locally:
   ```
   cd wp-content/plugins
   zip -r pbd-experiments-1.0.0.zip pbd-experiments -x '*.DS_Store'
   ```
2. In Carla's wp-admin: **Plugins → Add New → Upload Plugin**, pick the zip, install, activate.
3. Confirm **Experiments** menu appears in wp-admin with the chart icon.
4. **Run the recommended AA test first.** Create one experiment with two variants both pointing at the same page (no template override needed). Set status to Active. Let it accumulate ~50–100 visitors. Both arms should show roughly equal `experiment_viewed` counts. If not, assignment or tracking is broken before you ship a real test.
5. After AA passes, configure Carla's actual test against the new page designs. Either template-swap (drop variant templates into Carla's child theme) or redirect-swap (variants point at fully separate URLs on the site).
6. Flip the AA test to Concluded (it now lives in Past Experiments as proof the install works), and flip the real test to Active.
7. Verify Microsoft Clarity custom tags arrive: in the Clarity dashboard, go to Filters and confirm `experiment` and `variant` appear in the custom-tag list within a few minutes.
8. Verify GA4 receives `experiment_id` and `experiment_variant` event params via GTM (existing GTM setup on Carla's site needs the event variables wired once; the plugin pushes them).

PBD-7 is done when an assignment exists and at least one event has been recorded on Carla's site.

## Bookkeepers cutover (PBD-11)

The Bookkeepers free-classes test currently runs on the v0.1 `pbd-experiment-tracker` plugin against `wp_pbd_exp_*` tables. Cutover decision lives in the PBD-11 ticket; below is the operational sequence once the decision is locked.

### Option A: clean restart (recommended, simpler)

1. Deactivate v0.1 (`pbd-experiment-tracker`) on `bookkeepers.com`.
2. Install and activate v1 (`pbd-experiments`) using the zip from this repo.
3. In v1's admin, create the `free-classes-redesign` experiment with the same variants/metrics. New visitors get fresh assignments via the new cookie scheme; existing v0.1 cookies are ignored.
4. Optionally drop the old v0.1 tables once you're satisfied:
   ```sql
   DROP TABLE wp_pbd_exp_experiments, wp_pbd_exp_variants, wp_pbd_exp_assignments, wp_pbd_exp_events, wp_pbd_exp_metrics;
   DELETE FROM wp_options WHERE option_name = 'pbd_exp_tracker_version';
   ```
5. Verify the off-site Keap checkout still attributes the `purchase` event correctly to the variant via the existing thank-you page shortcode (which is already in the `[pbd_experiment_event experiment="free-classes-redesign" event="purchase"]` format and works identically in v1).

User-visible impact: existing in-test visitors lose their bucket. For a 50/50 split with no strong winner already declared, this is acceptable. Document this in the PR.

### Option B: data migration (preserves visitor buckets and event history)

If the live test has a meaningful sample we want to preserve, run a one-shot migration script on `bookkeepers.com`:

```sql
-- Copy experiments
INSERT INTO wp_pbd_experiments_experiments
  (experiment_key, name, status, target_path, cookie_days, include_logged_in, started_at, created_at, updated_at)
SELECT experiment_key, name, status, target_path, cookie_days, 0, created_at, created_at, updated_at
FROM wp_pbd_exp_experiments;

-- Copy variants (note: v1 adds variant_type, redirect_url, sort_order columns; defaults are correct for v0.1 data which was all template-swap)
INSERT INTO wp_pbd_experiments_variants
  (experiment_id, variant_key, label, weight, variant_type, template_path, redirect_url, sort_order, created_at, updated_at)
SELECT
  (SELECT new_e.id FROM wp_pbd_experiments_experiments new_e WHERE new_e.experiment_key = (SELECT old_e.experiment_key FROM wp_pbd_exp_experiments old_e WHERE old_e.id = v.experiment_id)),
  variant_key, label, weight, 'template', template_path, '', sort_order, created_at, updated_at
FROM wp_pbd_exp_variants v;

-- Repeat the same join pattern for assignments, events, metrics.
```

Validate counts match (`SELECT COUNT(*)` on each old vs new table) before deactivating v0.1. Keep v0.1 tables for a week as backup, then drop.

The existing v0.1 visitor cookie (`pbd_exp_vid`) carries through unchanged: v1 reads the same cookie name. So sticky buckets survive the cutover automatically.

## Rollback

If anything goes wrong:

1. Deactivate `pbd-experiments`.
2. Reactivate the previous version (or `pbd-experiment-tracker` v0.1 on Bookkeepers).
3. The new `wp_pbd_experiments_*` tables remain in the DB but are inert. Drop them if you want.

The visitor cookie is shared between v0.1 and v1, so visitors don't lose their identity even if you flip between plugins.

## Known limitations to flag with stakeholders

- One experiment per target path. If two active experiments target the same path, the first match wins (DB row order).
- No built-in significance math. Lean on Clarity recordings and GA4 funnels for qualitative judgement.
- Cross-domain assignment isn't supported. `bookkeepers.com` and `members.bookkeepers.com` are separate domains; the visitor cookie won't carry. Flag this on any test that crosses subdomains.
- The plugin's bot UA regex is intentionally cheap, not exhaustive. Override via the `pbd_exp_bot_ua_regex` filter if a client site has unusual bot traffic.
