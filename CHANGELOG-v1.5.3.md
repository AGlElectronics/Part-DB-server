# 4QT Part database — Changelog v1.5.3

**Overlay version:** `v1.5.2` → `v1.5.3`  
**Upstream Part-DB:** `2.18.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.5.3`  
**Digest:** `sha256:d576aeb11e15630f2b7dea75a5152812c54b9088af93d1a3b18f3ef1326879cd`  
**Date:** 2026-09-23

## Change

Supplier refill orders.

- On the parts table, select parts and choose **Make order**.
- The order uses minimum stock plus 10%, rounded up to the next 5, minus what is already in stock. Six in stock with a minimum of 10 orders 9.
- Quantities can be changed before saving. Past orders stay in **Orders**.
- Download a DigiKey or Mouser basket file from the saved quantities. Lines without that supplier's part number are left out.

After deploy, run the migration once if the container does not migrate on startup:

```bash
docker compose exec --user=www-data partdb php bin/console doctrine:migrations:migrate --no-interaction
```
