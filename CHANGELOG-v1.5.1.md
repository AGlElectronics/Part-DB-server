# 4QT Part database — Changelog v1.5.1

**Overlay version:** `v1.5.0` → `v1.5.1`  
**Upstream Part-DB:** `2.17.0` → `2.18.0`  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.5.1`  
**Digest:** `sha256:74080f97d31195197cb9f8996c35aa56b0d7b95ec699ee1fa8a06f381a7072cb`  
**Date:** 2026-09-22

## Change

Upstream Part-DB 2.18.0, plus a 12 mm storage-location label.

- Part labels stay **P700 18mm** and **P700 24mm**, 30 mm long, QR above the part name.
- **P700 12mm** is for storage locations. The QR and the location name sit side by side. The bridge cuts the tape to the length of the name.
- Upstream 2.18.0 adds info-provider stock levels, rate limits, batch refresh, custom part-state colors, and KiCad export field groups.

After deploy, run migrations and install the label profiles once:

```bash
docker exec --user=www-data partdb php bin/console doctrine:migrations:migrate --no-interaction
docker exec --user=www-data partdb php bin/console partdb:labels:install-p700 --no-interaction
```

The bridge exe is not in the image. PCs that already have `PartDbBpacBridge.exe` need the build that sizes 12 mm labels. Editor Lite must be off.
