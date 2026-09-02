# Student Panel — Full Visual Redesign

Tracks all UI/UX design work for the app. Each dated file in this folder is
one redesign pass; this is the first.

## Context

Investigation on 2026-09-02 found that every hand-written Blade+Livewire view
in this LMS (S1–S24, 24 shipped feature slices) was built functionally-
complete but with **zero CSS styling** — plain semantic HTML. Only the
Filament admin/teach/super panels look designed, because Filament ships its
own UI. The trigger was the user testing the student portal and finding the
UI "completely broken" — turned out to be a missing `@vite` include (found
and fixed in 26/30 page-shells) plus the deeper issue: the actual page markup
has no classes at all.

A design system was built and proven the same day: a "study notebook, not a
SaaS dashboard" system in `resources/css/app.css` under `@layer components`,
prefixed `.lms-*` — neutral paper background, ink text, one hairline border
color, a single typeface (Instrument Sans, already configured), flat panels
with no shadows/gradients, and one structural device (a 3px left "spine"
border in the tenant's own `--color-primary`). Buttons are always
ink-on-white/white-on-ink — never the tenant's arbitrary, contrast-unverified
brand color as fill/text — because a tenant can set any hex via
`BrandingService` and an unlucky pale color would make its own button
unreadable.

This system was applied and verified end-to-end (real headless-browser login
flow, screenshots, no console errors) to `student/dashboard.blade.php` and
every component it embeds (login/2FA, semantic search, photo-doubt, WhatsApp
link, notices, notifications, profile, password change, 2FA setup, cookie
banner, impersonation bar). That work is **done** — this plan extends the
same system to the rest of the student-reachable surface, with a shared
Blade layout component to eliminate the copy-pasted 20-line page-shell
boilerplate (the exact duplication that caused the original `@vite` bug).

## Approach

### Phase 0 — Foundation (sequential, single session)

- `resources/views/components/lms-layout.blade.php` (new) — anonymous Blade
  layout component wrapping the `<!DOCTYPE html>`/`<head>`/`.lms-shell`
  `<body>`/`.lms-header` boilerplate every page-shell currently copy-pastes.
  Props: `title`, `wide` (toggles `.lms-main--wide`); default slot for body.
- `resources/css/app.css` — new primitives, each serving 2+ downstream
  consumers: `.lms-badge`/`.lms-badge--muted` (status pills), `.lms-bar`/
  `.lms-bar-fill` (progress/score bars), `.lms-thread-item` (self-nesting via
  descendant selector, for recursive forum replies and the syllabus tree),
  `.lms-chat-*` (messages/message/message--mine/input), `.lms-quiz-nav`/
  `.lms-quiz-nav-item`/`.lms-quiz-timer` (quiz runner), `.lms-calendar-grid`/
  `.lms-calendar-day`/`.lms-calendar-event` (planner).
- Piloted the new layout component on
  `resources/views/comms/help-desk-ticket-form.blade.php` (simplest shell,
  one embed) and its component
  `app/Domains/Comms/Views/help-desk-ticket-form.blade.php` — fully styled
  here, so Phase 6 starts with this file already done.
- `student/dashboard.blade.php` and its already-shipped embeds are left
  untouched — that stays the reference pattern.

### Phases 1–9 — parallel, one subagent per feature area

Each subagent gets the same design-system context (tokens, class list, the
never-use-tenant-color-as-button-fill rule) plus its own file list, and does
not touch `resources/css/app.css` (all new primitives already exist from
Phase 0) — only adopts `<x-lms-layout>` and applies classes.

1. **Batches** — `student/batches.blade.php`, `batch-catalog.blade.php`,
   `fee-checkout.blade.php`, `batch-feedback.blade.php` (+ form),
   `batch-session-check-in.blade.php`, `geofence-check-in.blade.php`,
   `batch-daily-practice.blade.php`, `daily-practice-board.blade.php`,
   `batch-catch-up.blade.php`, `catch-up-plan.blade.php`. Geofence state via
   `.lms-badge`.
2. **Content: Catalog & Syllabus** — `content/catalog.blade.php`,
   `course-catalog.blade.php`, `my-wishlist.blade.php` (+ view),
   `content/syllabus.blade.php`, `syllabus-tree.blade.php`,
   `syllabus-tracker.blade.php` (`.lms-thread-item`), `live-poll-board.blade.php`
   + `live-poll-response.blade.php` (rendered inline in the syllabus page,
   not an overlay — `.lms-panel` + `.lms-bar`), `weak-topics.blade.php`,
   `peer-comparison-widget.blade.php`, `course-reviews.blade.php`.
3. **Content: Lesson Watch, Print, Formula Sheet** — `content/watch.blade.php`
   (player full-bleed above `.lms-main lms-main--wide`, everything else
   stacked below it), `chapter-markers.blade.php`, `doubt-chat.blade.php`
   (`.lms-chat-*`), `lesson-notes.blade.php`, `lesson-revision-notes.blade.php`,
   `lesson-questions.blade.php`, `lesson-notes-print.blade.php` (print-only
   styling, no `.lms-shell`/spine — separate pipeline), `chapter-formula-sheet.blade.php`
   + `formula-sheet.blade.php`. **`lesson-player.blade.php` explicitly left
   untouched** — already has working, self-contained Tailwind video chrome.
4. **Assessments** — `assessments/quiz.blade.php`, `quiz-runner.blade.php`
   (`.lms-quiz-nav`/`.lms-quiz-timer` — highest-risk file, 212 lines, confirm
   timer/proctoring JS still fires), `test-series-results.blade.php` (+ view,
   `.lms-bar`), `course-pyq.blade.php` + `pyq-browser.blade.php`.
5. **Assignments** — `assignments/assignment.blade.php`,
   `assignment-submit.blade.php` (`.lms-badge` for status).
   `certificate-verify.blade.php` deliberately left as-is — has its own
   small standalone design already, low-traffic public page.
6. **Comms** — `comms/messages.blade.php` + `direct-messages.blade.php`
   (`.lms-chat-*`), `batch-forum.blade.php` + `general-forum.blade.php` +
   `forum-post.blade.php` (style the recursive `forum-post` once via
   `.lms-thread-item`, both forum routes inherit it), `survey-runner.blade.php`
   (+ view). Help-desk already done in Phase 0.
7. **Planner** — `planner/calendar.blade.php` + `calendar.blade.php` — the
   one genuinely new grid layout in this whole redesign.
8. **Gamification** — `gamification.blade.php` + `gamification-widget.blade.php`
   — pure composition of Phase 0's `.lms-bar`/`.lms-badge`, no new classes.
   Note: this route has no auth middleware today — out of scope to fix here,
   flagged as a possible separate issue.
9. **Growth/Affiliate** — `growth/affiliate-dashboard.blade.php` +
   `affiliate-dashboard.blade.php` — `.lms-panel`/`.lms-list-item`, readonly
   `.lms-input` + copy button for the referral link.

**Out of scope, confirmed**: all `*-pdf.blade.php` dompdf exports (id-card,
rank-card, hall-ticket, omr-template, gradebook, certificate) — separate
rendering pipeline with their own inline print styles.

### Build & verify

After Blade/CSS edits, `npm run build` (no Vite dev server running locally —
edits don't take effect until rebuilt). One build after Phase 0, one more
after all Phase 1–9 subagents land. Verify each phase with the
`.claude/skills/gstack/browse` headless browser against at least one
representative route per feature area, logged in as
`student@physics.test` / `password` on `physics.lms.test:8000` — screenshot
plus a console-error check, not just "the code looks right."

## Critical files

- `resources/css/app.css` — design tokens + `.lms-*` system (extended only
  in Phase 0)
- `resources/views/components/lms-layout.blade.php` — shared layout
- `app/Domains/Comms/Views/forum-post.blade.php` — recursive component,
  styled once
- `resources/views/livewire/quiz-runner.blade.php` — highest-risk file
- `app/Domains/Planner/Views/calendar.blade.php` — only new grid layout
- `resources/views/livewire/lesson-player.blade.php` — left untouched

## Verification

1. `npm run build` succeeds with no errors after Phase 0 and again after all
   parallel phases land.
2. `browse` each phase's representative route(s) while logged in as
   `student@physics.test`, screenshot, and confirm: no console errors, the
   `.lms-` classes render (panels/spine/buttons visible, not raw browser
   defaults), and the page's actual function still works (e.g. quiz
   submission, forum reply posting, calendar navigation) — not just visual
   inspection.
3. Spot-check `student/dashboard.blade.php` still renders correctly after
   Phase 0's `app.css` additions (regression check on already-shipped work).
