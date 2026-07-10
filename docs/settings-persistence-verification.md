# Settings Persistence Verification

Project V26 does not currently include a PHP test framework. The lightweight check below validates the pure configuration and fallback rules without a database, user session, CSRF token, or real user data.

```bash
docker compose exec -T app php scripts/verify-settings-fallbacks.php
```

It verifies:

- valid theme modes, accent colors, and Eye Comfort Mode levels;
- legacy theme and accent mappings;
- invalid theme, accent, and eye comfort values falling back to `dark`, `purple`, and `balanced`;
- Eye Comfort Mode forcing the warm `amber` accent;
- supported Turkish and English locales;
- valid DM privacy values, while preview-only `approved_only` and invalid values fall back to `everyone`.

## Manual Persistence Check

Use a local development account only. Save each item in Settings, reload the page, and confirm the selected value remains active:

1. A normal theme and accent color.
2. Eye Comfort Mode at Light, Balanced, and Intense levels. Its warm palette must remain locked.
3. Turkish and English language selections.
4. DM privacy values supported by the current backend: Everyone, My Connections when enabled, and Off.

`Approved Only` remains a preview option until the message request backend exists; it must not submit as a saved privacy value.
