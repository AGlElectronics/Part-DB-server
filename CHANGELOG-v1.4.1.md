# 4QT Part database — Changelog v1.4.1

**Overlay version:** `v1.4.0` → `v1.4.1`  
**Upstream Part-DB:** `2.17.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.4.1`  
**Also tagged:** `ghcr.io/aglelectronics/part-db-server:mechanical-preview`, `sha-fc486e9`  
**Digest:** `sha256:bbdc54f6a8fb654ee38b8243dbac0fed10256a67d2e05b1a9952adce0af438f2`  
**Date:** 2026-09-14

## Fix

Creating a LAPP cable could not save the new `Meter` part unit. The part form already allows creating a category, footprint, or manufacturer when you have permission, but the measuring-unit field did not. Admins (and anyone with `@measurement_units.create`) can now create `Meter` from the create-from-provider form.
