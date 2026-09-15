# 4QT Part database — Changelog v1.4.4

**Overlay version:** `v1.4.3` → `v1.4.4`  
**Upstream Part-DB:** `2.17.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.4.4`  
**Also tagged:** `ghcr.io/aglelectronics/part-db-server:mechanical-preview`  
**Date:** 2026-09-15

## Change

Add a **HellermannTyton** info provider from three catalogs in one search:

- Heat shrink and insulation (Insulation 2025 UK)
- Cable protection / coverings (Automotive Cable Protection Systems 2024)
- Cable ties and fixings (Automotive Cable Ties and Fixings 2024)

About 2,266 article numbers. Search by HellermannTyton number (`111-01980`, `300-30120`), type (`T18R`, `HIS-PACK`, `HEGP03`), or a logical description (`zip tie black`). The part name is the article number; the description is the readable form (`Cable tie T18R, Black (BK), 2.5 x 100 mm`). No live API.

Enable with `PROVIDER_HELLERMANNTYTON_ENABLED=1` (on in the local preview Compose file).
