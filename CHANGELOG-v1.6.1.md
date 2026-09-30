# 4QT Part database — Changelog v1.6.1

**Overlay version:** `v1.6.0` → `v1.6.1`  
**Upstream Part-DB:** `2.19.1`  
**Image:** `ghcr.io/aglelectronics/part-db-server:mechanical-v1.6.1`  
**Digest:** `sha256:b8f0ca0f20faa77da5188169499b76644bc6053c86c70909c167c3c525b2889a`  
**Date:** 2026-09-29

## Change

Purchase orders can record when they were placed, and received parts can be checked in to stock.

- An order has one **Ordered on** date. Empty means not ordered. The orders list shows that date or **Not ordered**.
- Check in is available whether or not that date is set. Each part can be received in more than one delivery.
- The order shows ordered, already received, and still open. Status is **Open**, **Partial**, or **Received**.
- Check-in asks for a quantity, then a review, then confirmation. Stock changes only on confirm, with one comment on the part history.
- If a part has one usable lot, that lot is used. If it has several, the check-in asks which lot. If it has none, the check-in asks for a storage location and creates a lot there.
- If any selected line cannot be added, the whole check-in is cancelled and stock stays unchanged.
