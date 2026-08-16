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
| **Shipped** | 10 (S1–S10) |
| **Designed, not built** | 1 (S11) |
| **Proposed, not yet scoped** | 15 (S12–S26) |

**Phase completion:**

- **P1 — Institute Zero** (~102 features, the sellable core): **complete**, S1–S9.
- **P2 — Depth & extras** (~75 features): **11 shipped** (S10), 5 more designed (S11, not built).
- **P3 — Scale & enterprise** (~55 features): not started — by design, this comes after paying customers exist.

---

## Shipped + designed — S1 to S11

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
| S11 | Plan Tiers + Billing — seat/storage/bandwidth caps, Razorpay subscriptions, grace period, proration | 17 blocks planned | Aug 16 | 🟡 Designed, build pending |

---

## Full roadmap — S12 onward (proposed)

**Not locked.** This is a first-pass grouping of every remaining backlog
item into slice-sized chunks, so the whole plan is visible in one place.
None of these have a real design/plan doc yet — that happens when each
slice's turn comes, the same way S10 and S11 were shaped only once they
were picked, not decided years in advance.

| Slice | Scope | Items | Draws from |
|---|---|---|---|
| S12 | Security & Resilience Hardening — device/session limits, login history, audit log, GDPR/DPDP export, circuit breakers on vendors, explicit cache invalidation | 11 | B, V1-V5 |
| S13 | Discovery & Content Engagement — categories, prerequisites, course bundles, catalog search, lesson Q&A, notes/bookmarks, direct messaging, ratings | 8 | C7-C10, N7-N10 |
| S14 | Video & Live-Class Depth — concurrent-play limits, multi-DRM, auto-subtitles, doc print-block, live recording → AI ingestion, Zoom OAuth, live polls | 12 | D9-D13, E4, G5-G10 |
| S15 | Batch & Institute Operations — discussion forums, timetable conflict detection, bulk CSV import, per-instructor permissions, admissions CRM, ID cards | 11 | F6-F10, S2-S7 |
| S16 | Assessment & Attendance Depth — extended question types, proctoring, All-India rank test series, OMR scanning, low-attendance alerts, geofencing | 9 | I6-I11, H10-H13 |
| S17 | AI Layer, Round 2 — course outline generator, semantic search, weak-topic detection, revision notes, photo-based doubt solving | 7 | L9-L15 |
| S18 | Analytics & Parent Engagement — engagement ratio, rewatch heatmap, drop-off tracking, inactivity nudges, monthly report card, parent-teacher messaging | 8 | M5-M8, M14-M15, O5-O6 |
| S19 | Communication Platform — real-time WebSockets, study planner + calendar, general forum, feedback surveys, help desk, gamification | 8 | N11-N18 |
| S20 | Growth & Billing Extensions — affiliate codes, instructor payouts, refunds, subscription plans, multi-currency, revenue analytics (MRR/ARR) | 12 | R1-R6, P6/P8-P10, Q6-Q7 |
| S21 | Platform Scale & Migration — read replicas, horizontal scaling, per-tenant feature flags, org SSO, data-import tooling from an existing LMS | 10 | V6-V15 |
| S22 | AI-Native Content Layer — auto lecture notes, auto chapters/timestamps, Daily Practice Problems, ask-at-timestamp, auto formula sheets | 8 | U1-U8 |
| S23 | Coaching-Market Differentiators — confusion-signal alerting, syllabus tracker, PYQ bank, scholarship tests, rank cards, backlog/catch-up mode | 10 | U9-U18 |
| S24 | WhatsApp-Native AI & Ops — WhatsApp doubt bot, voice-note doubts, photo doubt over WhatsApp, auto-scheduler, fee analytics, peer comparison | 7 | U19-U25 |
| S25 | Mobile App: Core Shell — Capacitor shell, screen-record block, per-institute branding, offline downloads, camera/voice doubts, push | 6 | W1-W6 |
| S26 | Mobile App: Build & Store — white-label build pipeline, store submission runbook, forced updates, background audio, biometric lock, iOS build | 6 | W7-W12 |

---

Full feature-level detail and phase tags: [`docs/FEATURES.md`](FEATURES.md).
Per-slice design + block-by-block build logs: [`docs/superpowers/specs/`](superpowers/specs/).
