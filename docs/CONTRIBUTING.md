# Contributing to ExpenseFlow

Thank you for helping. The rules are short because the project is small; they exist so the live site (which deploys on every merge) stays healthy.

## Workflow

1. Open an issue or describe the change in the Pull Request.
2. `git fetch origin && git checkout -b feature/part-N-short-name origin/main` (use `fix/...` for bug fixes, `docs/...` for documentation).
3. Make the change in **small steps**; one PR = one idea.
4. Run the tests ([`TESTING.md`](TESTING.md)) and check phone + desktop screenshots for any UI change.
5. Update the docs that your change touches (see the table below) and add a line to [`ROADMAP.md`](ROADMAP.md).
6. Push, open a PR into `main`, describe what and why, how you tested.
7. After merge, GitHub Actions deploys the website automatically. If the app shells changed, run the release workflow with a new version.

Never push straight to `main`: a push deploys to production.

## Definition of done

- [ ] Follows the checklist in [`SECURITY.md`](SECURITY.md) section 3
- [ ] Uses design tokens, classes and `ef_icon()` ([`THEME.md`](THEME.md)); looks right at 390 px and 1280 px
- [ ] New tables/columns are in the module's `ensure_schema()` **and** `schema.sql` ([`DATABASE.md`](DATABASE.md))
- [ ] Tests added or updated, and the whole suite passes
- [ ] No secrets, SQL dumps or local config committed
- [ ] Docs updated, ROADMAP line added

## Which doc to update

| You changed | Update |
| --- | --- |
| a screen, colour, component | `THEME.md` |
| folder layout, request flow, helper function, convention | `ARCHITECTURE.md` |
| a table or column | `DATABASE.md`, `schema.sql` |
| a feature or admin page, a permission | `MODULES.md` |
| login, sessions, uploads, rate limits, headers | `SECURITY.md` |
| workflows, secrets, hosting, domain | `DEPLOYMENT.md`, `OPERATIONS.md` |
| Android/Windows/Store | `APPS.md`, `MICROSOFT_STORE.md` |
| how to run tests | `TESTING.md` |
| anything a newcomer must know first | `ONBOARDING.md`, root `README.md` |

## Code style

- PHP 8.1, `declare` types where cheap, early returns, small functions with a one-line "why" comment.
- Prefix functions by area (`shared_`, `friends_`, `admin_`, `personal_`, `ef_`).
- No framework, no new Composer dependency unless it clearly saves a lot of code (hosting is free and limited).
- CSS: extend `assets/app.css`; compact one-line rules are the house style there; do not edit the legacy CSS files.
- JavaScript: vanilla, in `assets/`, progressive enhancement (the page must work if the script fails).
- Text: short, friendly English; avoid idioms; amounts always via the currency helpers.
- Commits: imperative title (`Add friends by e-mail`), body explains why.

## Reporting bugs

Include the page URL, what you did, what you expected, what happened, device/browser (or app version), and a screenshot. For security problems do not open a public issue: use Help & support inside the app or the contact page and describe it privately.

## Licence and ownership

ExpenseFlow is developed and owned by Muhammad Anas. Contributions are accepted under the same terms unless agreed otherwise in writing.
