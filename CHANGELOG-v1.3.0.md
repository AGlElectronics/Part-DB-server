# 4QT Part database — Changelog v1.3.0

**Overlay version:** `v1.2.0` → `v1.3.0`  
**Upstream Part-DB:** `2.16.0` → official **`2.17.0`** (includes `2.16.1`)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.3.0`  
**Also tagged:** `ghcr.io/aglelectronics/part-db-server:mechanical-preview`  
**Date:** 2026-09-09  
**Local preview:** http://localhost:8080 (Docker Desktop, Compose file `compose.preview.yaml`)

---

## Why this is 1.3.0

`1.2.0` was the overlay on Part-DB 2.16.0. This release takes a full upstream **minor** (2.16 → 2.17) and ships the create-from-provider UX in the image that people actually run. That is a minor of our overlay, not a homepage patch.

| Number | What it is |
|---|---|
| **v1.3.0** | Our overlay / deployment version. Homepage: `Version v1.3.0`. Image tag: `mechanical-v1.3.0` |
| **2.17.0** | Official upstream Part-DB application version (`VERSION` file) |

Sequence:

| Overlay | What it was |
|---|---|
| `mechanical-v1.1.0` | First packaged mechanical-library image |
| `mechanical-v1.1.1` | Homepage branding |
| `mechanical-v1.2.0` | Upstream 2.16.0 + overlay WIP |
| **`mechanical-v1.3.0`** | **This release.** Upstream 2.17.0 + create-from-provider in the running image |

---

## One-page summary

1. Merged official **Part-DB 2.17.0** (and 2.16.1) into the mechanical-library branch. Kept every 4QT customization: homepage, mechanical providers, TraceParts, preview Compose.
2. **Create from provider** is a top-level navbar item next to **New part**, and a button next to **Create part** on parts lists. This is now in the published image, not only in uncommitted source.
3. Rebuilt and published **`mechanical-v1.3.0`** to GHCR. Rolling tag `mechanical-preview` points at the same image.
4. Pointed `compose.preview.yaml` at that tag.

---

## What we changed (4QT overlay)

### Create from provider

- Navbar link **Create from provider** / **Aus Anbieter erstellen** → `/tools/info_providers/search`.
- Removed that action from the New part dropdown so it is not listed twice.
- Parts lists get **Create parts from info provider** next to **Create part**.
- Permission: `@info_providers.create_parts`. Log in to see it.

Files: `templates/_navbar.html.twig`, `templates/parts/lists/_action_bar.html.twig`, `translations/messages.en.xlf`, `translations/messages.de.xlf`.

### Homepage / preview (carried forward)

- Title: **4QT Part database**. Subtitle unchanged. Version line: **Version v1.3.0**.
- License / GitHub / forum card still removed.
- Preview Compose still uses PostgreSQL 16 and `ghcr.io/aglelectronics/part-db-server:mechanical-v1.3.0`.
- Categories stay user-owned. No taxonomy installer.

### Kept beside upstream

- Standard mechanical parts (BOLTS) provider.
- TraceParts settings/provider, registered next to upstream **TrustedParts**.

---

## What upstream 2.17.0 brought in

From official Part-DB 2.16.1 and 2.17.0, including:

- TrustedParts.com (ECIA) info provider
- Stateless MCP 2026-07-28 plus an advanced part search MCP tool
- Twig/HTML code editor for labels in twig mode
- DigiKey create-from-scan fix
- `INITIAL_ADMIN_PW` env var actually applied
- Add/withdraw dialog fix (2.16.1)
- Dependency and translation updates

Full upstream notes: https://github.com/Part-DB/Part-DB-server/releases/tag/v2.17.0

---

## How to run

```bash
docker compose -f compose.preview.yaml pull
docker compose -f compose.preview.yaml up -d
```

```text
Image:    ghcr.io/aglelectronics/part-db-server:mechanical-v1.3.0
Also:     ghcr.io/aglelectronics/part-db-server:mechanical-preview
URL:      http://localhost:8080
```

After login as `admin`, the navbar is **Scanner | New part | Create from provider | search**. The homepage shows **Version v1.3.0**.
