# 4QT Part database — Changelog v1.5.2

**Overlay version:** `v1.5.1` → `v1.5.2`  
**Upstream Part-DB:** `2.18.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.5.2`  
**Digest:** `sha256:97c5802b1f1102e5d7297c070d30a3e95f1460c660eb03f13845b70fdfb6a6b7`  
**Date:** 2026-09-23

## Change

Part label **P700 12mm description**.

- 12 mm tape, 50 mm long.
- Prints the part description only. No QR.
- Text that does not fit is cut off.

The 18 mm and 24 mm part labels, and the 12 mm storage-location label, are unchanged.

After deploy, install the profiles once:

```bash
docker compose exec --user=www-data partdb php bin/console partdb:labels:install-p700 --no-interaction
```

PCs that print need the bridge build that understands layout `text`.
