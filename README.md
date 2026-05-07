# Folio Take-Home

A small document-sharing app. You'll be extending it with features that customers have been asking for.

---

## What Was Built (candidate additions)

All three requested features are implemented on the `feature/folio-features` branch.

### Feature 1 — Scheduled Publishing
- Staff can set a publish date and time on any document at creation
- Staff can update the schedule later using the inline editor in the admin table
- Recipients who hit a share link before the publish time see a "Not yet available" page (HTTP 403)
- All scheduling actions are logged to `audit_log`
- Timezone selector included — input is converted to UTC on save, compared in UTC at view time

### Feature 2 — Human-Readable Document IDs (Slugs)
- Every document gets a short readable ID on creation: `{title-slug}-{4-char-random}` e.g. `offer-letter-a1b2`
- Slug is stored alongside the existing share token — it does **not** replace the token
- Token remains the access control boundary (unguessable); slug is for staff reference and communication
- Collision handling built in: retries with a new random suffix if slug already exists
- Slug shown in admin table; included in audit log on document creation

### Feature 3 — Search by Title
- Search box on admin page filters the document list by title prefix
- Uses `LIKE 'term%'` — fast, no dependencies, right fit for an internal tool where staff know their own document titles
- GET-based so the filtered URL is bookmarkable
- Filtered results still show "Create share" links per document

### Extra Improvements
- **Timezone bug fix** — browser `datetime-local` was being interpreted as Chicago time regardless of the user's actual timezone. Fixed by adding a timezone selector and storing `publish_tz` per document.
- **Admin table layout** — widened container and cleaned up table styles to prevent columns from overflowing
- **Delete document** — staff can delete a document from the admin table; related shares are removed in a transaction; action is audit logged

### Migration System
Schema changes go through numbered SQL files in `migrations/`. `seed.php` runs them in order after `schema.sql` on every startup. `schema.sql` was never edited.

Migrations added:
- `migrations/001_add_publish_at.sql`
- `migrations/002_add_slug.sql`
- `migrations/003_add_publish_tz.sql`

### Tests
Extended `tests/test.php` with 10 new tests covering all three features. Full suite: **11 passed, 0 failed**.

---

## Setup

Requires Docker (with Compose). That's it — PHP, SQLite, and everything else ship inside the container.

```
docker compose up
```

Open http://localhost:8000. The first run builds the image (~30 seconds); subsequent runs start instantly.

Each `docker compose up` re-seeds `db.sqlite` from scratch, so you always start with a known state. Stop with `Ctrl+C`.

To run the tests:

```
docker compose exec app php tests/test.php
```

You edit files on your host machine in your normal editor — the container has them mounted, so changes show up immediately on browser refresh.

## Background

Folio is a small tool that lets staff create documents and share them with recipients via one-time links. This repo contains a staff admin page, document creation, share-link generation, and a recipient view. The schema (`schema.sql`) and helpers (`lib/bootstrap.php`) are meant to feel representative of a real internal tool.

Take some time to read the code before you start building.

## Agent setup

How you configure this repo for AI-assisted work is part of the exercise. That can include context files, permissions, hooks, custom commands, conventions to follow, orchestration (subagents, parallel tasks, custom skills or commands) — whatever fits how you work.

We're not prescribing specifics. Commit what you'd commit on a real project. If you decide setup isn't worth it for a three-hour exercise, say so in your video and explain why.

## Your Task

Customers have asked for three things. Pick an order, scope as you see fit, and build as much as you can in the time you have.

### 1. Scheduled publishing

Staff should be able to prepare a document in advance and have it become visible to recipients at a specific date and time. Before that time, someone hitting the share link should see a "not yet available" message instead of the document.

### 2. Human-readable document IDs

Today documents are identified by auto-increment integers (`#1`, `#2`) and share links use opaque hex tokens. Customers want each document to have a **short, readable ID** — something a person could say out loud, type into a URL, or paste into an email. Examples of the shape (not prescriptive): `welcome-2026`, `onboarding-packet-3k`, `FOLIO-7QX4`.

The exact format, length, and URL structure are your call. Think about collisions, guessability, and how this interacts with the existing share-token mechanism.

### 3. Share by name

Staff should be able to find a document to share by searching for it by title, not just by scrolling a list. Decide what "search" means here — exact match, prefix, fuzzy, something else — and justify your choice.

## What we're intentionally not specifying

- Whether readable IDs **replace** the existing share-token mechanism or **complement** it (there are real tradeoffs either way — privacy, guessability, link permanence)
- The URL structure for viewing a document
- How you structure and run schema migrations (see below)
- How the three features interact with each other

Make these calls yourself and explain your reasoning in your video. We care about your judgment as much as your code.

## Requirements

- **Schema changes go through a migration file (or files) you add to the repo**, not by editing `schema.sql` directly. There is no migration system yet — you decide how to organize one. Explain your approach in your video.
- At least one test covers each feature you build (see `tests/test.php` for the existing pattern).
- Document creation, scheduling changes, and share actions should be logged to `audit_log` (pattern is in `lib/bootstrap.php`).
- The `docker compose up` flow should still work from a fresh clone for anyone reviewing your branch.

## Deliverables

1. A branch with your changes and a commit log that tells the story of your work
2. A short video (~5 min) walking us through your approach, covering:
   - What you built and what you scoped out
   - The design decisions you made and the alternatives you rejected
   - Anything in the existing code you noticed worth flagging
   - What you'd do with more time
   - **Your AI workflow**: what you leaned on AI for, what you did yourself, a moment you pushed back on a suggestion, and anything you noticed about where AI helped or hurt
3. *(Optional)* Share chat transcripts or links if it's easy — a thoughtful minute in the video is worth more than an unedited log.

## Time

Budget ~3 hours. You probably won't finish all three features — **that's expected**. Prioritize, ship what you can finish well, and explain what you skipped and why. Partial + thoughtful beats rushed + complete.

## What we're looking for

- How you handle ambiguity (the spec is intentionally fuzzy)
- How you gather context before writing code
- How you set up and work with AI tools — including when you push back on their suggestions
- How you verify your own work
- How you communicate tradeoffs and anything surprising you found

Finished-but-sloppy loses to unfinished-but-thoughtful.
