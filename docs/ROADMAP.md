# Full Build Roadmap — S1 to S26

One page, plain language: the whole plan, from what's shipped to everything
still sitting in `docs/FEATURES.md`'s backlog, grouped into slices so it's
readable without hunting through the spec. Live status always lives in
[`HANDOVER.md`](../HANDOVER.md); this file is the big picture around it.

There's also a visual version of this same register (progress bars, a
stamped register table) — ask for the link if you don't have it, or
regenerate it from this file.

## Where things stand

| | |
|---|---|
| **Slices in the full plan** | 26 |
| **Shipped** | 21 (S1–S21) |
| **Proposed, not yet scoped** | 5 (S22–S26) |

**Phase completion:**

- **P1 — Institute Zero** (~102 features, the sellable core): **complete**, S1–S9.
- **P2 — Depth & extras** (~75 features): **70 shipped** (S10, S11, S12, S13, S14, S15, S17, S18, plus S19's own N11/N12/N15/N16), plus S16's own P2 items (I6/I10/H10) and S20's own P6/P8 (refunds, fee-based access restriction) — S16/S20 both mixed P2/P3 grab-bags, not phase-boundary slices.
- **P3 — Scale & enterprise** (~55 features): 9 shipped early — S19's own N14 (general forum) and N18 (gamification), plus S20's own P9/P10/Q6/R1/R2/R3/R6 (student subscriptions, multi-currency/tax, revenue analytics, affiliate program, instructor payouts, course-interest capture), all explicitly named in each slice's own roadmap headline so pulled forward same as S16's own P3 items — otherwise not started, by design, until paying customers exist.

---

## Shipped — S1 to S21

| Slice | Scope | Size | Built | Status |
|---|---|---|---|---|
| S1 | Foundation — multi-tenancy, auth, roles, branding, cross-tenant isolation | 12 blocks | Aug 9–10 | ✅ Done |
| S2 | Content + Video — course → section → lesson, HLS player, Bunny CDN, bandwidth metering | 8 blocks | Aug 10 | ✅ Done |
| S3 | AI Spine — lecture ingestion pipeline, doubt chat grounded per-lecture | 8 blocks | Aug 10 | ✅ Done |
| S4 | Batches + Enrolment — seats, waitlist, approval flow, attendance | 9 blocks | Aug 10–11 | ✅ Done |
| S5 | Live Classes — topic-anchored live sessions, Zoom SDK integration | 9 blocks | Aug 10–11 | ✅ Done |
| S6 | Assessments — quiz builder, timed attempts, auto-grading | 9 blocks | Aug 11 | ✅ Done |
| S7 | Fees — Razorpay payments, coupons, instalments, GST invoices | 8 blocks | Aug 11 | ✅ Done |
| S8 | Comms — WhatsApp notifications, parent portal, parent-student linking | 9 blocks | Aug 12 | ✅ Done |
| S9 | Hardening & Backlog — 2FA, Google OAuth, AI/bandwidth spend caps, audit-trail fixes | 15 blocks | Aug 12–13 | ✅ Done |
| S10 | Assignments, Gradebook, Certificates — submissions, rubric grading, weighted gradebook, auto-issued certificates | 16 blocks | Aug 16 | ✅ Done |
| S11 | Plan Tiers + Billing — seat/storage/bandwidth caps, Razorpay subscriptions, grace period, proration | 17 blocks | Aug 17 | ✅ Done |
| S12 | Security & Resilience Hardening — device/session limits, login history, audit log, GDPR/DPDP, circuit breakers, cache invalidation, soft-delete gaps | 15 blocks | Aug 17–18 | ✅ Done |
| S13 | Discovery & Content Engagement — categories/tags/catalog filtering, prerequisites + sequential unlocking, course bundles (Programs), catalog search/wishlist/recommendations, lesson Q&A, video notes/bookmarks, direct messaging, ratings/reviews | 18 blocks | Aug 19 | ✅ Done |
| S14 | Video & Live-Class Depth — concurrent-playback limiting, auto-subtitles, print/save protection, live recording → AI ingestion, real per-institute Zoom OAuth, Google Meet, live polls. (D10-D12 multi-DRM/watermark/offline-DRM cut — P3, needs paying customers) | 19 blocks | Aug 19–22 | ✅ Done |
| S15 | Batch & Institute Operations — instructor invitations/assignment + per-instructor permissions (closes a real never-built gap), timetable templates + teacher conflict detection, discussion forums, batch feedback, bulk student CSV import, bulk enrolment/invitations, admissions CRM, ID cards, enrolment/completion reports. (§S.S7 job board cut — P3, not in the roadmap's own headline) | 22 blocks | Aug 22–24 | ✅ Done |
| S16 | Assessment & Attendance Depth — extended question types + partial credit, proctoring + violation auto-submit, test series with rank/percentile, OMR scanning, tenant-configurable low-attendance alerts, geofenced check-in, biometric/RFID device webhook, substitute-teacher workflow. (I7 numerical questions already shipped in S6 — real scope was 9 of the roadmap row's 10 named items) | 18 blocks | Aug 24–25 | ✅ Done |
| S17 | AI Layer, Round 2 — course outline generator, revision notes, semantic search, weak-topic detection, photo-based doubt solving. Real scope is 5 items, not the row's headline "7": L10 (auto-subtitles) already shipped in S14, L15 (AI content-generation assist) is P3 and cut for now — same "not named in the headline, not in scope" boundary S16 drew the other way | 14 blocks | Aug 25–26 | ✅ Done |
| S18 | Analytics & Parent Engagement — engagement ratio + speed profile, rewatch heatmap, drop-off tracking, missed-class-replay + weak-topic-practice nudges, monthly parent report card on WhatsApp. Real scope is 5 items, not the row's headline "8": M15's 3-day-inactivity nudge already shipped in S9, M14/O5 are the same report-card feature counted twice, O6 (parent-teacher thread) is P3 and largely already covered by the existing generic `DirectMessage` — cut for now | 13 blocks | Aug 26 | ✅ Done |
| S19 | Communication Platform — real-time WebSockets (Reverb), general forum, feedback surveys, help desk, study planner + calendar (incl. signed `.ics` export), gamification. Real scope is 6 items, matching the row's own headline exactly | 17 blocks | Aug 26–27 | ✅ Done |
| S20 | Growth & Billing Extensions — refunds, fee-based access restriction, student subscriptions, multi-currency + regional tax, platform revenue analytics (MRR/ARR), affiliate program, instructor payouts, course-interest capture. Real scope is 9 items, not the row's own headline "12": Q7 already shipped (S8/S9's revenue-register export), R4/R5 cut (too broad / a separate product surface) — same verify-before-building boundary every prior slice drew | 15 blocks | Aug 27–28 | ✅ Done |
| S21 | Platform Scale & Migration — stateless-readiness audit, per-tenant feature flags, institute API keys + outbound webhooks, bearer-token auth on `/api/v1/`, Google Workspace SSO, Card EMI, data-import tooling (courses/enrolments/payment history). Real scope is 6 items, not the row's own headline "5 sentences" (V12 splits into two tracked deliverables): read replicas (V8) and server hardening (V7) are cut — infra/ops or "no concrete deliverable yet," not application code; SAML (half of V13) is cut — no library installed, no buyer signal | 16 blocks | Aug 28–29 | ✅ Done |

Full design/plan docs: `docs/superpowers/specs/2026-08-26-s19-communication-platform-design.md`,
`...-plan.md`; `docs/superpowers/specs/2026-08-27-s20-growth-billing-extensions-design.md`,
`...-plan.md`; `docs/superpowers/specs/2026-08-28-s21-platform-scale-migration-design.md`,
`...-plan.md`.

---

## Full roadmap — S22 onward (proposed)

**Not locked.** This is a first-pass grouping of every remaining backlog
item into slice-sized chunks, so the whole plan is visible in one place.
None of these have a real design/plan doc yet — that happens when each
slice's turn comes, the same way S10-S21 were each shaped only once they
were picked, not decided years in advance.

| Slice | Scope | Items | Draws from |
|---|---|---|---|
| S22 | AI-Native Content Layer — auto lecture notes, auto chapters/timestamps, Daily Practice Problems, ask-at-timestamp, auto formula sheets | 8 | U1-U8 |
| S23 | Coaching-Market Differentiators — confusion-signal alerting, syllabus tracker, PYQ bank, scholarship tests, rank cards, backlog/catch-up mode | 10 | U9-U18 |
| S24 | WhatsApp-Native AI & Ops — WhatsApp doubt bot, voice-note doubts, photo doubt over WhatsApp, auto-scheduler, fee analytics, peer comparison | 7 | U19-U25 |
| S25 | Mobile App: Core Shell — Capacitor shell, screen-record block, per-institute branding, offline downloads, camera/voice doubts, push | 6 | W1-W6 |
| S26 | Mobile App: Build & Store — white-label build pipeline, store submission runbook, forced updates, background audio, biometric lock, iOS build | 6 | W7-W12 |

---

Full feature-level detail and phase tags: [`docs/FEATURES.md`](FEATURES.md).
Per-slice design + block-by-block build logs: [`docs/superpowers/specs/`](superpowers/specs/).
