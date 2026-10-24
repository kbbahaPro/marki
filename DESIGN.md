# Design system

The interface follows two ideas: **one accent colour on calm ground**, and **one shape taken from the product itself**.

## The motif: the time slot

A booking is a time slot, and a time slot is drawn as a pill. That one shape is used at very different sizes:

| Size | Where |
|------|-------|
| Text size | badges (`.pill-*`), time ranges in lists, the duration chip on event types |
| Control size | every button (`.btn-*`), bookable times (`.slot`), tabs (`.tabs`), the active nav item |
| Card size | the confirmation header, the brand mark |
| Page size | one giant cropped pill (`.motif`) on sign-in, public pages and the "Next up" card |

Do not introduce other decorative shapes (blobs, hexagons, gradients). Inputs and cards use a modest `rounded-xl` / `rounded-2xl` so the pill stays special.

## Colour

All colours are semantic tokens defined once in `resources/css/app.css` (light and dark values) and exposed as Tailwind utilities in `tailwind.config.js`. Views never use raw palette classes such as `text-gray-500`.

| Token | Use |
|-------|-----|
| `canvas`, `surface`, `surface-2`, `line` | page background, cards, subtle fills, borders |
| `ink`, `ink-2`, `ink-3` | primary, secondary and tertiary text (all ≥ 4.5:1 on their backgrounds) |
| `primary` (+ `hover`, `ink`, `soft`) | the single accent: actions, links, selected states |
| `success`, `warning`, `danger` (+ `soft`) | meaning only: confirmed, needs attention, error |

Dark mode follows the operating system (`prefers-color-scheme`) by switching the token values; no `dark:` classes are needed in views.

## Type

Plus Jakarta Sans (variable), bundled through `@fontsource-variable/plus-jakarta-sans`, so no request goes to a font CDN. Use `tabular-nums` wherever times are listed.

## Components

Shared classes live in `@layer components` in `resources/css/app.css`: `.card`, `.btn-primary`, `.btn-secondary`, `.btn-danger`, `.btn-ghost`, `.input`, `.label`, `.checkbox`, `.pill-*`, `.slot`, `.alert-*`, `.tabs`/`.tab`, `.page-title`, `.section-title`, `.muted`, `.link`.

Icons are inline Lucide SVGs via `<x-icon name="…" />` (`resources/views/components/icon.blade.php`); no emoji as icons.

## Accessibility checklist

- Visible focus ring on everything focusable (`:focus-visible` in `app.css`).
- Touch targets at least 44 px (`.btn`, `.slot`, calendar days).
- Motion is limited to 150 ms colour/opacity transitions and is disabled under `prefers-reduced-motion`.
- Status changes use `role="status"` / `role="alert"`; icon-only buttons carry `aria-label`.

## Previewing

With `APP_ENV=local`, `/_preview/page/login` and `/_preview/page/forgot-password` show signed-out screens while you are signed in, and `/_preview/mail/{guest|host|meeting-link|ics}` render the booking emails.
