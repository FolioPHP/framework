# FolioPHP: Agent Instructions

## Architecture
- **Engine (`App/`, `bootstrap.php`):** Target PHP 8.5 and keep the engine site-agnostic.
- **Zero runtime dependencies:** Do not rely on third-party PHP packages, `vendor/`, or a Composer autoloader at runtime.

## Code Quality & Safety
- **Keep it minimal:** Prefer the smallest readable change—avoid boilerplate, speculative helpers, and clever one-liners. Follow existing patterns and extend existing classes before adding new files or abstractions.
- **Preserve contracts:** Read `README.md` for the public API reference. Do not change public template helpers, config semantics, or file formats without approval, and update `README.md` when public behavior changes.
- **Fail fast:** Fail fast on missing or invalid required data (optional template values and empty directory scans are valid).

## Verification
- Lint modified PHP with `php -l` and run a focused check on affected behavior (cover default, localized, nested, and missing routes for request-wide changes).
- After completing a task, ask before committing and pushing.