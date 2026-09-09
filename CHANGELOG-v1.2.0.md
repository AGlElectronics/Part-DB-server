# 4QT Part database — Changelog v1.2.0

**Overlay version:** `v1.1.1` → `v1.2.0`  
**Upstream Part-DB:** `2.15.0` (partial 2.16 development line) → official **`2.16.0`**  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.2.0`  
**Also tagged:** `ghcr.io/aglelectronics/part-db-server:mechanical-preview`  
**Digest:** `sha256:9d654c8dd2f9e0a8f212f347e4525f0bc0350165a146f52427ea22e940b99212`  
**Image size:** 787 MB  
**Date:** 2026-08-31  
**Local preview:** http://localhost:8080 (Docker Desktop, Compose file `compose.preview.yaml`)

This is the long version. If someone claims “you just bumped a Docker tag”, send them this file and wait.

---

## 0. How our versioning works (read this first)

There are **two version numbers**. They are not the same thing.

| Number | What it is | Where you see it |
|---|---|---|
| **v1.2.0** | Our overlay / deployment version (4QT fork on top of Part-DB) | Homepage small text: `Version v1.2.0`. Image tag: `mechanical-v1.2.0` |
| **2.16.0** | Official upstream Part-DB application version | `VERSION` file inside the image, Part-DB’s own version command |

We deliberately do **not** tag our images as bare `v2.16.0`, because that would be confused with Jan Böhmer’s upstream release. Our tags are `mechanical-vX.Y.Z`.

Sequence so far:

| Overlay | What it was |
|---|---|
| `mechanical-v1.1.0` | First packaged mechanical-library image |
| `mechanical-v1.1.1` | Homepage branding (4QT title, subtitle, license card removed) |
| **`mechanical-v1.2.0`** | **This release.** Upstream 2.16.0 merge + navbar “Create from provider” + preview stack on Postgres |

Why **1.2.0** and not **1.1.2**: 1.1.1 was a homepage patch. 1.2.0 includes a full upstream **minor** (2.15 → 2.16), new MCP write tools, a database migration, a first-class navbar action, and a Compose/Postgres change. That is a minor of *our* overlay, not a typo fix.

---

## 1. One-page summary (for people who scroll)

In this release we:

1. Merged official **Part-DB 2.16.0** into our mechanical-library branch and kept every 4QT customization.
2. Promoted **Create from provider** to a top-level navbar item next to **New part**, because that is the primary create path here (BOLTS / TraceParts / distributors), not a buried dropdown entry.
3. Rebuilt and published **`mechanical-v1.2.0`** to GHCR.
4. Pointed local Docker Desktop Compose at that image and brought the stack up on **PostgreSQL 16** with **automatic migrations**.
5. Applied upstream migration `Version20260827164156` (log `access_method` / `request_id` / `transaction_id`).

The homepage now says **4QT Part database** / **Version v1.2.0**. After login, the navbar is: **Scanner | New part | Create from provider | search**.

---

## 2. What *we* changed (4QT overlay) — this is not upstream

These are our files / behaviours. They do not exist in stock Part-DB 2.16.0.

### 2.1 Navbar: “Create from provider”

**Problem:** Creating a part from an info provider (the thing we actually do all day) lived inside the **New part** caret dropdown. You had to notice the tiny triangle, open the menu, and pick “Create parts from info provider”. That is the wrong primary action for this instance.

**Change:**

- New top-level navbar link, same `nav-link` style as **Scanner** / **New part**.
- Label: **Create from provider** (EN) / **Aus Anbieter erstellen** (DE).
- Target route: `info_providers_search` → `/tools/info_providers/search`.
- Permission: `@info_providers.create_parts` (this permission also implies `@parts.create`).
- Anonymous users do **not** see it. Log in as `admin`. That is not a bug.
- The same action was **removed** from the New part dropdown so it is not listed twice.
- The New part caret still offers: create-from-URL (if enabled), create-from-scan (if scanner permission), CSV import.
- The caret is hidden when those extra items would be empty, so you do not get a blank dropdown.
- Parts lists (category, location, footprint, etc.) also get a **Create parts from info provider** button next to **Create part**.

**Files:**

- `templates/_navbar.html.twig`
- `translations/messages.en.xlf` (`navbar.create_from_provider`)
- `translations/messages.de.xlf` (`navbar.create_from_provider`)

### 2.2 Homepage branding (carried from 1.1.1, version string updated)

- Title: **4QT Part database** (hardcoded, not the generic Part-DB title helper).
- Subtitle: **For small electrical and mechanical parts**.
- Version line: **Version v1.2.0**.
- Stock Part-DB license / GitHub / forum card on the homepage is removed. The software is still AGPL-3.0; we just stopped putting the upstream marketing card on our instance homepage.

**File:** `templates/homepage.html.twig`

### 2.3 Mechanical parts library (already in 1.1.x, still in this image)

This is the reason the fork exists. Still present in 1.2.0:

- **Standard mechanical parts** provider (`standard_parts`): offline, no API key. Searches a pinned BOLTS metadata catalog (`resources/mechanical/bolts/catalog.json`). Example query: `DIN 912 M6 x 20`. Imports standard, equivalents, hardware type, head/drive, thread, diameter, coarse pitch, length. Does **not** invent material, finish, property class, or hardness.
- **TraceParts** provider: exact part-number lookup against TraceParts, gated on API key + tenant UID + explicit “catalog syndication is approved” setting. Their terms restrict storing catalog data without written consent; the setting exists on purpose.
- Normalizer / fastener registry so DIN / ISO / EN / ASME designations land as **parameters**, not duplicate category trees.
- Categories are **not** auto-created. You make `Mechanical → Fasteners → …` yourself. That is intentional.

**Code (non-exhaustive):**

- `src/Services/InfoProviderSystem/Providers/StandardPartsProvider.php`
- `src/Services/InfoProviderSystem/Providers/TracePartsProvider.php`
- `src/Services/MechanicalParts/*` (catalog, normalizer, fastener registry, definition DTO)
- `src/Settings/InfoProviderSystem/TracePartsSettings.php`
- `src/Settings/InfoProviderSystem/InfoProviderSettings.php` (embeds TraceParts)
- `resources/mechanical/bolts/*` (catalog + attribution + LGPL)
- tests under `tests/Services/InfoProviderSystem/Providers/` and `tests/Services/MechanicalParts/`

In 1.2.0 we also **stopped shipping** the old `partdb:mechanical-library:install` command and `resources/mechanical/taxonomy.json`. Categories stay user-owned. The preview Compose file no longer runs an install hook on container start.

### 2.4 Local / preview deployment

`compose.preview.yaml` now:

- Does **not** build from source on your laptop (Composer + Yarn on Windows against this tree is a bad time).
- Pulls **`ghcr.io/aglelectronics/part-db-server:mechanical-v1.2.0`** (`pull_policy: always`).
- Runs **PostgreSQL 16 Alpine** as a sibling container, not SQLite in the uploads volume.
- `DB_AUTOMIGRATE=true` so the 2.16.0 log-table migration applied on first start of this image.
- Isolated volumes: `partdb_mechanical_preview_data`, `partdb_mechanical_preview_postgres`.
- Port **8080** (override with `PARTDB_PORT`).
- `CHECK_FOR_UPDATES=0`, `ALLOW_ATTACHMENT_DOWNLOADS=0` for the preview.

Bring it up:

```bash
docker compose -f compose.preview.yaml up -d
```

Open http://localhost:8080 and log in as `admin`.

CI: Docker image builds for our fork stay **master-only** (not every feature branch). Mechanical preview image workflow remains **manual** (`workflow_dispatch`).

---

## 3. What upstream 2.16.0 actually contains

This is the official Part-DB 2.16.0 release (tag `v2.16.0`, published 2026-08-28). We merged it. It is not “a few dependency bumps”.

Official notes: https://github.com/Part-DB/Part-DB-server/releases/tag/v2.16.0  
Compare: https://github.com/Part-DB/Part-DB-server/compare/v2.15.0...v2.16.0  
(~61 commits, ~176 files in the full 2.15.0→2.16.0 range. Our branch already had the first part of that line; 1.2.0 brings in the rest through the version bump.)

### 3.1 MCP / AI tools (this is the big functional chunk)

Before 2.16.0, MCP was largely read-oriented. 2.16.0 adds **write** tools, behind a second kill switch.

- New setting / env: **`MCP_EDITING_ENABLED`** (default **off**). Even if `MCP_ENABLED=1`, write tools fail closed until an admin enables “Enable part-editing MCP tools”.
- Create / update / delete **parts**.
- Create / update / delete **categories, footprints, manufacturers, suppliers, storage locations**.
- Stock tools: add stock, withdraw stock, stocktake on lots.
- Attachment content + preview image tools (some of this landed just before the tag; it is in the tree we ship).
- MCP protocol version in config bumped to **0.2.0**.
- `mcp/sdk` **0.7.1** — patches **CVE-2026-53965** (SSE buffer DoS). Yes, that is a real CVE. Updating was not optional cosmetics.

### 3.2 Audit log (this is why the database migrated)

Every change log entry can now record:

- **`access_method`** — Web UI vs REST API vs MCP vs CLI, with icons in the log table.
- **`request_id`** — UUID so every log row from one request can be grouped. Filter exists. You can open “all events with this request ID”.
- **`transaction_id`**.

Migration: `migrations/Version20260827164156.php`

- Adds those three columns on `log` (Postgres: `SMALLINT` + two `UUID`s, plus indexes).
- Backfills old CLI rows that used the `!!!CLI ` username prefix.

This **already ran** on the local Docker Desktop preview (`DB_AUTOMIGRATE=true`). Production must run the same migration (or automigrate) after switching the image.

### 3.3 Projects / BOM

- **Performance mode:** projects with **> 100 BOM lines** can no longer be edited as one giant form on the project admin page (PHP `max_input_vars` / huge POST bodies). Edit lines from the BOM table instead.
- Direct **BOM entry edit** page/form when the project is small enough.
- BOM entry permissions checked on the **entry**, not only the parent project.
- Merging parts **keeps** project BOM relations (they used to be able to go missing).
- Stock move dialog: create a **new lot at a new location** while moving (`#1500`).

Docker PHP setting: **`max_input_vars=8000`**. Server info page shows the current value. Requirements checker warns if it is too low.

### 3.4 Other 2.16.0 product changes

- Component image generator for resistors / capacitors / inductors (and bulk generate), plus related styling fixes on part info.
- Faster KiCad category part listing (full part details in the listing so KiCad does not round-trip every row).
- Alternative names may contain commas.
- Attachment merging fix.
- Merge-part confirmation dialog fix (titles escaped properly).
- Label generation via REST API requires **read** permission on parts.
- SQLite foreign-key enforcement status shown in server info; recommended via `DATABASE_SQLITE_ENFORCE_FOREIGN_KEYS` (our preview uses **Postgres**, so this is N/A there).
- VS Code devcontainer support.
- `brick/math` → 0.19.
- KiCad symbol/footprint lists, translations, Unifont, docs, general dependency refresh.
- WebAuthn logging bugfix from an upstream library issue.

### 3.5 Merge conflict we actually had to resolve

Only one: `translations/messages.en.xlf`.

Upstream added BOM performance-mode strings at the end of the file. We had TraceParts setting strings in the same place. **Both kept.** If someone diffs translations and sees both blocks, that is correct, not a merge accident.

---

## 4. Database / upgrade checklist (do not skip)

After switching any running instance from `mechanical-v1.1.1` (or older) to `mechanical-v1.2.0`:

1. Pull / retag the new image.
2. Run migrations as the webserver user, **or** keep `DB_AUTOMIGRATE=true` (preview already did this).

```text
php bin/console doctrine:migrations:migrate
```

Docker equivalent:

```text
docker exec --user=www-data <container> php bin/console doctrine:migrations:migrate
```

3. Target version of that migration: **`DoctrineMigrations\Version20260827164156`**.
4. If you use MCP write tools, they stay **off** until `MCP_EDITING_ENABLED=1` (or the matching system setting). Read MCP still uses `MCP_ENABLED`.
5. Large project BOMs: edit via the BOM table, not the project admin form.
6. SQLite-only shops: consider `DATABASE_SQLITE_ENFORCE_FOREIGN_KEYS=1` **after** checking existing data. Postgres preview: ignore.

Known nuisance on the local preview: automigration tries `pg_dump` for a backup first. The Part-DB image ships **pg_dump 15**, Compose runs **Postgres 16**, so you get a version-mismatch warning. The migration **still ran**. The zip backup in uploads may be incomplete. That is a tool-version mismatch, not a failed schema update.

---

## 5. How to run what we shipped

### Local Docker Desktop (already deployed 2026-08-31)

```text
Image:    ghcr.io/aglelectronics/part-db-server:mechanical-v1.2.0
Compose:  compose.preview.yaml
URL:      http://localhost:8080
User:     admin  (existing preview database; same password as before)
DB:       PostgreSQL 16 in container partdb-mechanical-preview-postgres
```

Commands:

```bash
docker compose -f compose.preview.yaml pull
docker compose -f compose.preview.yaml up -d
docker compose -f compose.preview.yaml logs -f partdb
```

### GHCR

```text
ghcr.io/aglelectronics/part-db-server:mechanical-v1.2.0
ghcr.io/aglelectronics/part-db-server:mechanical-preview   # same digest, rolling tag
```

Private registry; needs a GitHub identity that can read `AGlElectronics/part-db-server` packages.

---

## 6. What you should see when it is working

**Logged out**

- Brand: Mechanical Parts Preview (navbar) / 4QT Part database (hero).
- Version v1.2.0 under the subtitle.
- Scanner may show depending on permissions; **New part** and **Create from provider** do not, until you log in.

**Logged in as admin**

- Navbar: **Scanner**, **New part** (optional caret), **Create from provider**, then search.
- **Create from provider** opens the info-provider search (BOLTS / TraceParts / DigiKey / etc. depending on what is enabled).
- Tools sidebar still has the same provider entries. The navbar is the shortcut, not a second feature.

**Info providers**

- Standard mechanical parts: always on, offline.
- TraceParts: only if configured **and** “catalog syndication is approved” is checked.

---

## 7. File-level map of *our* delta (not the whole 2.16.0 tree)

Custom / overlay-touched paths in this packaged image:

```text
templates/_navbar.html.twig
templates/homepage.html.twig
translations/messages.en.xlf
translations/messages.de.xlf
compose.preview.yaml
docs/usage/mechanical_parts_library.md
src/Services/MechanicalParts/
src/Services/InfoProviderSystem/Providers/StandardPartsProvider.php
src/Services/InfoProviderSystem/Providers/TracePartsProvider.php
src/Settings/InfoProviderSystem/TracePartsSettings.php
src/Settings/InfoProviderSystem/InfoProviderSettings.php
resources/mechanical/bolts/
.github/workflows/mechanical_preview_image.yml
.github/workflows/docker_build.yml          # master-only, not every branch
.github/workflows/docker_frankenphp.yml     # same
```

Removed vs earlier mechanical WIP:

```text
resources/mechanical/taxonomy.json
src/Command/InstallMechanicalLibraryCommand.php
```

---

## 8. What this release is *not*

- Not a rewrite of Part-DB.
- Not “we forked the whole application from scratch this week”.
- Not an untested laptop Docker build. The image was built on the amd64 builder, pushed to GHCR, pulled onto Docker Desktop, started, and the homepage was verified at http://localhost:8080 showing **Version v1.2.0**.
- Not enabling MCP write tools by default.
- Not auto-creating mechanical category trees.
- Not storing TraceParts catalogs unless an admin has explicitly approved syndication.

---

## 9. If someone still says it is a small change

Then they can explain, in writing, why any of the following is trivial:

- Merging 2.16.0 through a live custom branch (mechanical providers + translations) without dropping either side.
- A schema migration on `log` that production **must** apply.
- A CVE bump on the MCP SDK.
- Write MCP tools that can create and delete inventory objects (correctly gated).
- Changing the default create UX to match how this warehouse actually files parts.
- Publishing a versioned GHCR image and running it locally on Postgres with automigrate.

If they only looked at the homepage subtitle: the subtitle is 12 characters of CSS. The rest of this file is the actual release.
