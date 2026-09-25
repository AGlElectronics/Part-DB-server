# 4QT Part database — Changelog v1.5.4

**Overlay version:** `v1.5.3` → `v1.5.4`  
**Upstream Part-DB:** `2.18.0` (unchanged)  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.5.4`  
**Digest:** pending  
**Date:** 2026-09-25

## Change

Purchase orders can be created and edited without starting from the parts table.

- The orders list can start a new order, with an optional name.
- An order can be renamed, and parts can be added or removed. Deleting an order does not change stock.
- A part page has **Add this part to an order**, which adds it to an existing order or starts a new one.
- A part already on the order is not added again. New lines still use the refill quantity: minimum stock plus 10%, rounded up to the next 5, minus what is already in stock.
