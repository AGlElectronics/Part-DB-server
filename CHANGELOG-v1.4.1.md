# 4QT Part database — Changelog v1.4.1

**Overlay version:** `v1.4.0` → `v1.4.1`  
**Upstream Part-DB:** `2.17.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.4.1`  
**Also tagged:** `ghcr.io/aglelectronics/part-db-server:mechanical-preview`  
**Date:** 2026-09-14

## Fix

Creating a LAPP cable could not save the new `Meter` part unit. The part form already allows creating a category, footprint, or manufacturer when you have permission, but the measuring-unit field did not. Admins (and anyone with `@measurement_units.create`) can now create `Meter` from the create-from-provider form.
