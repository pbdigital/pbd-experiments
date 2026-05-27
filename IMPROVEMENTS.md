> Status: all 16 items implemented in the Add/Edit Experiment screen (see `includes/admin/class-edit.php`, `assets/admin.css`, `assets/admin.js`).

# UI/UX recommendations — `Add Experiment` screen

Working through this top-to-bottom from the perspective of a first-time user trying to launch their first test.

## Priority 1 — the things hurting comprehension right now

1. **Reframe the page as a setup flow, not a settings form.** The WP `form-table` (label-left, input-right) is dense and feels like a 2012 Settings page. For a creation flow, stack inputs vertically inside each card with the label directly above the field. Form-table works great for site-wide settings, badly for a multi-section editor.

2. **Give variants a visual split bar.** Right now Weight is a number input with a tiny "50%" beside it. Add a single horizontal bar above (or below) the variants table that shows the current split visually: `[ Control 50% ████ | Variant 50% ████ ]`, updating live as weights change. It makes the abstraction concrete and catches errors like a total of 150% immediately.

3. **The Template/Redirect column is confusing.** Two inputs overlap in the same cell with one display-hidden via JS. Replace it with: when `Type = Redirect`, the destination field's placeholder, helper text, and validation pattern change. Or, better, make it a segmented control inside the row that visibly swaps the field's label ("Template file" vs "Redirect URL").

4. **Make Target path feel like a URL, not free text.** Prefix the field with the read-only site origin so users see what they're actually targeting:
   `[ http://localhost:8090 ] [ /free-classes ]`. Saves typos and removes the cognitive jump. 

   Even better. Provide the option of a searchable dropdown of existing WP pages that user can select from.

5. **Sticky save bar.** A long form ends at a tiny "Save and continue" button at the bottom-left. Pin a slim bar to the bottom of the viewport with the primary CTA on the right and a quiet "Cancel" on the left. Show validation summary inline when the user attempts to save with errors.

## Priority 2 — clarity and confidence

6. **Inline the metric snippet helper where it's relevant.** "How do I fire a metric event?" is currently a `<details>` at the foot of the Metrics card. Move the copy snippets to a popover that opens from a small `(?)` icon on the Event name column header, or render them per-row when a row is focused. The current placement makes the user scroll past metrics, then scroll back.

7. **Show the derivation of the `Key` live.** Right now the helper says "Auto-fills from the name." Show it inline: as the user types into Name, render a small badge under it ("Key: `free_classes_homepage`") with an "Edit" link that reveals the editable key field. Hides the technical detail until needed.

8. **Cookie days needs human translation.** Stick a quiet `≈ 3 months` next to the number that updates as the user types. Same trick for `1 = each pageview`, `30 = a month`. Number-to-meaning helpers are cheap and high-value.

9. **"Include logged-in users" needs a warning, not a reassurance.** The current description says "Default off. Internal browsing shouldn't pollute results." Reframe as a yellow info note when the box is checked: "Heads-up: your own admin sessions will now be counted in results."

10. **Notes deserves a hypothesis nudge.** Replace the placeholder with the actual format you want people to use: `Hypothesis: changing X will increase Y because…`. Optional but a great forcing function for good experimentation hygiene.

## Priority 3 — polish

11. **Two competing H1/H2 stacks at the top.** "Add Experiment" (h1) immediately followed by a help banner immediately followed by "Basics" (h2). Drop the "Basics" heading, the card itself is the section and the hint is enough.

12. **Drag handle is invisible to most users.** The `≡` glyph with `title="Drag to reorder"` only shows on hover. Use a proper dotted-grip icon (Dashicons has one) and add a subtle hover state on the whole row so it's obvious rows are sortable. Also consider whether reordering variants is something users actually do often, if not, drop it.

13. **Remove row buttons are bare `×` glyphs.** On hover they should turn red, and only the X cell should activate them (not the whole cell). Add an `aria-label` per row that includes the variant label so screen readers say "Remove Control" not just "Remove variant".

14. **Variants and Metrics tables are using `widefat` which gives them a slightly off-feel inside a card.** Either drop `widefat` and style them to match the card's padding, or pull the table out of the padded card body so it goes edge to edge cleanly.

15. **Status bar appears only after save**, fine, but the empty state should set the expectation. Add a faint placeholder under the title for new experiments: "Status appears here after you save."

16. **The bottom of the Metrics card has three blocks of "description" text and a `<details>` snippet help.** That's a lot of small grey text. Consolidate: one short sentence in the card header, and the snippets behind the inline `(?)` icon from point 6.

## A quick rough sketch of the proposed layout

```
┌─────────────────────────────────────────────────────────────┐
│ Add Experiment                       ← All experiments      │
│ Name it, pick where it runs, define variants, save.         │
├─────────────────────────────────────────────────────────────┤
│ ┃ Basics                                                    │
│ ┃   Name *                                                  │
│ ┃   [ Free classes homepage test                       ]    │
│ ┃   Key: free_classes_homepage  (edit)                      │
│ ┃                                                           │
│ ┃   Target URL *                                            │
│ ┃   [ localhost:8090 ][ /free-classes                  ]    │
│ ┃                                                           │
│ ┃   Cookie [ 90 ] days  ≈ 3 months                          │
│ ┃   ☐ Include logged-in users                               │
│ ┃   Notes                                                   │
│ ┃   [ Hypothesis: changing X will increase Y because… ]     │
├─────────────────────────────────────────────────────────────┤
│ ┃ Variants                                                  │
│ ┃   ████████████████ Control 50%  |  ████████ Variant 50%   │
│ ┃   ─── rows ───                                            │
│ ┃   + Add variant                                           │
├─────────────────────────────────────────────────────────────┤
│ ┃ Metrics                          (?) How to fire events   │
│ ┃   ─── rows ───                                            │
│ ┃   + Add metric                                            │
└─────────────────────────────────────────────────────────────┘
┌──────────────────────────────────────  Cancel | Save ───────┐
```

## Suggested implementation order

If picking these off one by one, the biggest perceptible lift per hour of work:

1. Split bar above variants (#2)
2. URL-prefix input for Target path (#4)
3. Sticky save bar (#5)
4. Inline key derivation badge (#7)
5. Cookie days human translation (#8)

Everything else is incremental polish on top.
