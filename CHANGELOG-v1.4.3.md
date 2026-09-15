# 4QT Part database — Changelog v1.4.3

**Overlay version:** `v1.4.2` → `v1.4.3`  
**Upstream Part-DB:** `2.17.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.4.3`  
**Also tagged:** `ghcr.io/aglelectronics/part-db-server:mechanical-preview`, `sha-3ed21ce`  
**Digest:** `sha256:5ac9ab6d60974d38c526c405762698c470187353ba97be809117115e6ef7edc3`  
**Date:** 2026-09-15

## Change

Add a **Landefeld** info provider from the Atlas 9 Compact English catalog (pneumatics, hydraulics, industrial supplies). About 11,400 type codes. Search by Landefeld number (`IQSG 146 G`) or a logical description (`straight 6mm`). The part name is the Landefeld number; the description is the readable form (`Straight push-in fitting, G 1/4", 6 mm`). No live API.

Enable with `PROVIDER_LANDEFELD_ENABLED=1` (on in the local preview Compose file).
