# 4QT Part database — Changelog v1.5.0

**Overlay version:** `v1.4.4` → `v1.5.0`  
**Upstream Part-DB:** `2.17.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.5.0`  
**Date:** 2026-09-22

## Change

Print Brother PT-P700 labels from Part-DB.

- Profiles **P700 18mm** and **P700 24mm**, 30 mm long.
- QR on top linking to the part, then the part name. The description is not printed.
- **Print to P-touch** opens the local Windows bridge (`PartDbBpacBridge.exe`) so the tape length stays 30 mm.

After deploy, install the profiles once:

```bash
php bin/console partdb:labels:install-p700 --no-interaction
```

The bridge exe is not in the image. Copy `tools/bpac-bridge/dist/PartDbBpacBridge.exe` to each PC that prints, run it, and click Install. Editor Lite must be off.
