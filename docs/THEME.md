# ExpenseFlow design system (theme, colours, UI)

Everything a designer or developer needs to make a new screen look like ExpenseFlow. The live design lives in **two** stylesheets only:

| File | Used by | Purpose |
| --- | --- | --- |
| `assets/app.css` | every page (landing, sign-in, signed-in app, public pages, admin) | the whole design system |
| `assets/admin.css` | admin pages only, loaded after `app.css` | tables, role tags, stat tiles |

> **Legacy files, not loaded anywhere:** `assets/theme.css`, `style.css`, `dashboard.css`, `responsive.css`, `landing.css`, `auth.css` are left over from the first design. Do not edit them expecting a change on screen. They can be deleted once you are sure (`grep -r "dashboard.css"` shows no PHP file links to them; `style.css` is only named inside a comment in `assets/fx.js`). The old v6/v7/v8 HTML mock-ups in `docs/design/` are visual references only.

## 1. Design idea in one paragraph

A calm, modern "glass" look: frosted translucent cards floating over a soft two-colour gradient with blurred colour blobs. The accent colour tells you **which side of the app you are in**: **teal = Personal** money, **blue = Shared** (groups, friends, balances). Money in is **green**, money out is **orange**. Text is deep navy. The font stack starts with Inter, then the system font. Icons are one consistent set of 24x24 outline SVGs.

## 2. Colour tokens

Defined at the top of `assets/app.css` (`:root` and `[data-mode=s]`). Always use the variable, never paste the hex.

### Base tokens (`:root`)

| Token | Value | Meaning / where it is used |
| --- | --- | --- |
| `--tx` | `#0B2545` | Primary text, headings (deep navy, also the PWA `theme_color`) |
| `--mu` | `#5b6b80` | Muted text: labels, captions, inactive nav, icons |
| `--inc` | `#0d9f6e` | **Income** / positive / "you are owed" (green) |
| `--exp` | `#ea580c` | **Expense** / negative / "you owe" / delete hover / notification dot (orange) |
| `--gb` | `rgba(255,255,255,.85)` | Glass border colour |
| `--sh` | `0 8px 32px rgba(11,37,69,.10)` | Card shadow |
| `--a1` | `#0f766e` | Accent start (teal), gradient start for buttons, active nav, logo text |
| `--a2` | `#1d4ed8` | Accent end (blue), gradient end |
| `--h2` | `#137A7F` | Hero card gradient end (brand teal) |
| `--b1` / `--b2` | `#d6f0ea` / `#e3edfa` | Page background gradient (mint to light blue) |
| `--bl` | `#5eead4` | Colour of the top-left blurred blob |

### Shared-mode override (`[data-mode=s]`, set on the `.fr` wrapper)

| Token | Personal (teal) | Shared (blue) |
| --- | --- | --- |
| `--a1` | `#0f766e` | `#1d4ed8` |
| `--a2` | `#1d4ed8` | `#4DA8DA` |
| `--h2` | `#137A7F` | `#1d4ed8` |
| `--b1` / `--b2` | `#d6f0ea` / `#e3edfa` | `#dbe7fb` / `#e2f1fa` |
| `--bl` | `#5eead4` | `#93c5fd` |

Switching mode only flips `data-mode="p"` or `"s"` on the page wrapper (`<div class="fr" data-mode="...">`, printed by `ef_page_start()`); every button, nav item and gradient re-colours itself automatically because they all read `--a1/--a2`.

### Fixed brand colours (not tokens)

| Colour | Hex | Use |
| --- | --- | --- |
| Navy | `#0B2545` | Hero card start, text, theme colour |
| Deep blue | `#134074` | Brand secondary (logo, mails) |
| Brand teal | `#137A7F` | Logo, hero end |
| Warm peach | `#fdf0dc` / `#fdba74` | Bottom-right of the gradient and its blob (keeps the page from looking cold) |
| Danger | `#ef4444` | Error messages, destructive confirmation |
| Page fallback | `#e8f1f3` | `<body>` background behind the gradient |
| Staff role tag | amber `#b45309` on `rgba(245,158,11,.18)` | `.tag.rs` in admin |
| Blue tag | `#1d4ed8` on `rgba(29,78,216,.12)` | `.tag.tb2` in admin |

Semantic tag classes: `.tg` green, `.tx` orange, `.tn` neutral grey. Text helpers: `.in` (income green), `.ex` (expense orange), `.mu` (muted).

## 3. Typography

- Font family: `Inter, system-ui, -apple-system, "Segoe UI", sans-serif`. **Inter is not downloaded by any live page** (only the unused legacy `theme.css` imports it from Google Fonts), so in practice users see Inter only if it is installed on their device; otherwise the system font (Segoe UI on Windows, San Francisco on Apple, Roboto on Android) is used. This is deliberate: no third-party font request, faster pages, better privacy. To make Inter appear everywhere, self-host the font files in `assets/fonts/` and add `@font-face` to `app.css` (do not hot-link Google Fonts).
- Weights in use: 500 (meta), 600 (nav, labels), 700 (buttons, titles), 800 (big numbers, logo wordmark).
- Scale (rem): small caption `.68-.76`, body `.85-.92`, card title `.95`, page title `1.1`, big number `1.7`, hero number `2.1`, landing hero `~2.15` on phone up to larger on desktop.
- Section labels (`.sl`) are 0.68rem, bold, UPPERCASE with `.1em` letter spacing.
- Inputs on phones use `16px` so iOS does not zoom.

## 4. Shape, spacing and effects

| Thing | Value |
| --- | --- |
| Card radius | 20px (`.g`) |
| Buttons / inputs | 12px radius (`.btn.lg` 14px, `.btn.sm` 10px) |
| Pills / tags | full round (`99px`) |
| Nav item | 13px radius |
| Page padding | 16px; max app width 1480px |
| Gap between cards | 14px; sidebar width 230px |
| Glass card (`.g`) | `rgba(255,255,255,.55)` + `backdrop-filter: blur(18px) saturate(160%)` + 1px `--gb` border + `--sh` shadow |
| Background | `.fr`: 135deg gradient `--b1` to `--b2` 55% to `#fdf0dc`, plus two fixed blurred blobs (`:before` 420px `--bl`, `:after` 360px orange at 45% opacity) |
| Entrance animation | `.view` fades and slides up 8px in .35s |
| Focus ring | `0 0 0 3px rgba(15,118,110,.15)` with border `--a1` |

Phones drop only the live blur (it makes scrolling slow); colours and animations stay. All animation is switched off under `prefers-reduced-motion`. Printing hides sidebar/top bar/bottom bar and turns cards plain white.

## 5. Layout of a signed-in page

```
.fr (gradient + blobs, data-mode)
 └─ .ap (flex, 16px padding)
     ├─ .sb  sidebar: logo, Personal/Shared menu from modules, Profile, Admin (staff only)
     └─ .mn  main column
         ├─ .tb   top bar: page title (.tt), mode switch (.seg), bell (.ic + .dt), user (.me)
         └─ .view page content: .G3 / .G2 / .GE / .G4 grids of .g.card
 └─ .bn  bottom bar on phones (the sidebar is hidden), .fabf floating add button
```

The shell is produced by **`ef_page_start()` / `ef_page_end()`** in `includes/layout.php` (admin uses `modules/admin/layout.php`, sign-in uses `includes/auth-layout.php`, public pages use `includes/public-layout.php`). Never hand-write the shell in a page.

### Breakpoints

| Width | What changes |
| --- | --- |
| `max-width: 900px` | 3-column grid becomes 2; 2-column becomes 1; friends header stacks |
| `max-width: 768px` | sidebar replaced by the bottom bar; sign-in brand panel hidden (form only); landing nav collapses; inputs 16px |
| `min-width: 769px` | floating add button hidden; top bar sticks while scrolling |
| `max-width: 700px` | notification pop-up spans the screen width |

## 6. Component catalogue (class names)

| Component | Classes | Notes |
| --- | --- | --- |
| Glass card | `.g .card` | `.hero` makes it the navy-to-teal highlight card |
| Stat | `.k` label, `.v` value | `.hero .v` is larger |
| Button | `.btn` (+ `.i2` green, `.e2` orange, `.o` outline, `.lg`, `.sm`, `.w` full width) | icon inside as `svg` 15px |
| Tag / badge | `.tag` + `.tg/.tx/.tn`; `.badge` on dark cards | |
| Section title | `.t` (flex, with right-hand link) | |
| List row | `.row` + `small` sub-line + `<b>` amount | alternating border-top |
| Category icon | `.cat` white rounded tile with a 17px icon | icon name from `modules/categories` |
| Avatar | `.av`; stacked `.stk .av` | initials from `ef_initials()` |
| Tabs / segmented | `.tabs a.on`, `.seg a.on` | active = white chip / accent gradient |
| Pills | `.pills .pl` | quick facts |
| Insight tile | `.ig .ins` | |
| Search bar | `.sbar` (input `type=search`, clear `.sx`) | friends page |
| Admin table | `table.tbl` in `.tbl-wrap` | `assets/admin.css` |
| Notification pop-up | `.np`, `.npop` | `assets/notif-pop.js` |
| Sign-in trust block | `.atrust`, `.aboutln` | explains who runs the site (Safe Browsing trust) |
| Terms tick box | `.agree` | register form |
| Sign-in footer links | `.aft` (`.aft.q` quiet variant) | **Do not reuse `.ft2` on sign-in pages**: `.ft2` is the landing footer and brings its own padding, border and left alignment |
| Google button | `.gbtn` + `.gmark`, note `.gnote`, divider `.gor` | login/register; white button, 12px radius; the mark is `assets/icons/google-g.svg` when that file exists (put Google's official "G" from their sign-in branding guidelines there), otherwise a plain neutral letter |
| Inline SVG icon | `svg.i` (18px, 2px stroke, round caps) | |

Grids: `.G3` (1.5fr 1fr 1fr), `.G2` (1.6fr 1fr), `.GE` (equal halves), `.G4` (auto-fit 220px), admin `.G4a` (auto-fit 190px).

## 7. Icons

- One set: **24x24 outline SVG paths** in `includes/icons.php`, printed with `ef_icon('name')` (optionally a colour). Stroke width 2, round caps and joins, `fill: none`, colour `currentColor` so icons inherit text colour.
- To add an icon: add a `'name' => '<path d="..."/>'` entry in `ef_icon_paths()`, then call `ef_icon('name')`. Keep the same stroke style; do not mix in Font Awesome on new pages.
- Category icons map: Food `food`, Transport `car`, Shopping `bag`, Entertainment `tag`, Bills `zap`, Healthcare `heart`, Education `book`, Other `more` (`modules/categories/lib.php`).

## 8. Logo, favicon and app icons

| Asset | Path | Notes |
| --- | --- | --- |
| Source logo | `ExpenseFlow Logo.png` (repo root) | master, transparent background |
| Favicons | `assets/icons/favicon-32/48/192/512.png`, `favicon.ico`, `favicon.png` | **Transparent** background. Use the include `includes/favicons.php` on every page layout, never hand-write `<link rel="icon">` |
| PWA icons | `assets/icons/icon-192.png`, `icon-512.png`, `maskable-192/512.png`, `apple-touch-icon.png` | referenced by `manifest.json` |
| Social preview | `assets/og-image.png` | link previews |
| Desktop icon | `apps/desktop/build/icon.ico` | exe + installer |
| Store tiles | `apps/desktop/build/appx/*.png` | Microsoft Store package |

Logo tile is the leaf/flow mark in rounded square (38px in sidebar, 38px in sign-in brand panel). The wordmark "ExpenseFlow" is Inter 800 with the accent gradient as text fill (`.wm`).

## 9. Page types and their look

| Page type | Shell | Look |
| --- | --- | --- |
| Landing (`index.php`) | own markup + `app.css` landing section | sticky blurred nav, hero with floating cards and drawn sparkline, features, FAQ, resources cards, professional footer; scroll-reveal animation (needs JS, content is visible without it) |
| Sign-in / register / reset (`auth/*`) | `ef_auth_start/end` | split screen: dark navy-teal brand panel (headline, check list, "made by" line) + frosted form card with trust footer. On phones only the form |
| Signed-in app | `ef_page_start/end` | sidebar + top bar (see section 5) |
| Admin | `modules/admin/layout.php` | same shell, staff menu grouped by section, tables |
| Public info (About, Privacy, Terms, Contact, Guides) | `ef_public_start/end` | readable article width, same nav/footer as landing |
| Emails | `ef_mail_html()` in `config/mail.php` | navy header, big code box, teal/blue accents |
| Exports (PDF/Excel) | `includes/export.php` | branded header with logo and name, coloured summary tiles, banded rows; see `docs/ARCHITECTURE.md` section "Exports" |

## 10. Rules when you build or change UI

1. Use tokens (`var(--a1)`), classes and `ef_icon()`; do not add one-off hex colours or inline styles for things that exist.
2. Green means money in, orange means money out. Do not swap them, never use red for a normal expense (red = errors only).
3. A page in Personal uses `mode => 'p'`, a Shared page `mode => 's'`. Profile, notifications and help are "neutral" (`neutral => true`) and keep the last mode.
4. Test at 390px wide and 1280px wide. Nothing may scroll sideways on a phone (wide tables go in `.tbl-wrap`).
5. Touch targets at least 38px; inputs 16px on phones.
6. Escape every printed value with `e()`.
7. After editing a CSS/JS file nothing else is needed: `ef_asset()` appends `?v=<file time>` so browsers fetch the new copy after each deploy. Bump `CACHE_NAME` in `sw.js` only when you change the offline shell files it pre-caches.
8. Keep text short and friendly; the app is used in English and by Urdu speakers, so avoid idioms.
