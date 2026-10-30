# ExpenseFlow documentation

Everything needed to understand, run, change, test, deploy and operate the project. Start with **ONBOARDING**.

| Document | Read it when you want to know... |
| --- | --- |
| [`ONBOARDING.md`](ONBOARDING.md) | what the project is, how to run it, the 5 key ideas, recipes for common changes, glossary |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | folder map, request life-cycle, modules, self-healing schema, exports, conventions |
| [`THEME.md`](THEME.md) | the design system: colours, fonts, spacing, components, icons, logos, breakpoints, UI rules |
| [`MODULES.md`](MODULES.md) | every feature and admin page, what it does and which permission guards it |
| [`DATABASE.md`](DATABASE.md) | all 28 tables, money rules, relationships, backups, handy SQL |
| [`SECURITY.md`](SECURITY.md) | protections in place, secret handling, the checklist for new code, known limits |
| [`TESTING.md`](TESTING.md) | local setup, the 20 test files, visual checks, manual release checklist |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | website deploy, GitHub secrets, app releases, rollback, other hosts |
| [`OPERATIONS.md`](OPERATIONS.md) | production runbook: InfinityFree quirks, Safe Browsing, routine admin tasks, moving to your own domain, monthly checks |
| [`APPS.md`](APPS.md) | Android, Windows and Store shells |
| [`MICROSOFT_STORE.md`](MICROSOFT_STORE.md) | step-by-step Store publishing and certification fixes |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | workflow, definition of done, code style |
| [`ROADMAP.md`](ROADMAP.md) | history of every Part and what is planned |
| `design/` | the original HTML mock-ups (v6-v8). Visual references only; the live design is `assets/app.css` |

Docs live in `docs/` and are never served by the website (`.md` files are blocked by `.htaccess` and `router.php`). Keep them updated in the same Pull Request as the code.
