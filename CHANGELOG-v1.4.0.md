# 4QT Part database — Changelog v1.4.0

**Overlay version:** `v1.3.0` → `v1.4.0`  
**Upstream Part-DB:** `2.17.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.4.0`  
**Also tagged:** `ghcr.io/aglelectronics/part-db-server:mechanical-preview`  
**Date:** 2026-09-14

## Why this is 1.4.0

`master` is now the currently deployed mechanical-preview line (upstream 2.17.0 + mechanical library). This overlay adds the LAPP catalog info providers on that baseline.

| Overlay | What it is |
|---|---|
| `mechanical-v1.3.0` | Upstream 2.17.0 + create-from-provider navbar |
| **`mechanical-v1.4.0`** | **This release.** Same baseline plus LAPP industrial, halogen-free, and automotive catalogs |

## Changes

1. Promoted `master` to the mechanical-preview / v1.3.0 image line so the default branch matches what is deployed.
2. Merged the LAPP providers:
   - **LAPP Automotive** — ÖLFLEX HEAT 125 single cores
   - **LAPP Halogen-free** — H05Z-K 90°C, H07Z-K 90°C, H07Z1-K Type 2
   - **LAPP** — industrial catalog (empty, ready for later families)
3. Compact size searches such as `1mm2 white` match the catalog.
4. Preview Compose enables the three LAPP providers and pins `mechanical-v1.4.0`.
