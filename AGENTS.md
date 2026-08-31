# Prototype Instructions

Run the local server yourself and open the preview in the in-app browser. Do not give the user server-start instructions when you can run it.

Before making substantial visual changes, use the Product Design plugin's `get-context` skill when the visual source is unclear or no longer matches the current goal. When the user gives durable prototype-specific design feedback, preferences, or decisions, record them in `AGENTS.md`.

Mobile movie details should use a compact, normal-scale header rather than an oversized single-column hero. Keep the primary watched action prominent near the title at the top, with a large touch target.

Mobile discovery previews should fill the visual viewport without right or bottom gutters. When a show's episode catalog is empty, show a single empty state instead of an optionless season picker or inactive season actions.

Keep mobile text inputs, selects, and textareas at a computed font size of at least 16px so iOS Safari does not retain focus zoom when a media detail opens. Preserve pinch zoom, and keep detail controls and episode copy intrinsically contained instead of masking overflow.

When implementing from a selected generated mock, treat that image as the source of truth for layout, component anatomy, density, spacing, color, typography, visible content, and hierarchy.
