---
title: Mechanical parts library
layout: default
parent: Usage
---

# Mechanical parts library

Part-DB can store mechanical hardware with the same part, stock, supplier, and
information-provider model used for electronic components. Mechanical
properties are stored as parameters, so this feature does not require a
database migration.

## Create your category hierarchy

The mechanical library does not create categories automatically. Create the
categories that fit your own inventory in Part-DB's category editor. A common
starting point is:

```text
Mechanical
└── Fasteners
    └── Bolts & Screws
        └── Socket Head Cap Screws
```

DIN, ISO, EN, and ASME designations work best as parameters rather than
parallel category trees. This prevents one `DIN 912` / `ISO 4762` screw from
needing duplicate category entries.

## Run the preview with Docker Desktop

From the repository root, build and start an isolated local preview:

```bash
docker compose -f compose.preview.yaml up --build -d
docker compose -f compose.preview.yaml logs -f partdb
```

Open <http://localhost:8080>. On the first start, the migration output in the
container log prints the generated `admin` password.

The preview stores its database and uploads in the dedicated
`partdb_mechanical_preview_data` volume, so it does not modify another Part-DB
installation. Stop it without deleting data:

```bash
docker compose -f compose.preview.yaml down
```

To completely reset the preview and generate a new database:

```bash
docker compose -f compose.preview.yaml down --volumes
```

Set `PARTDB_PORT` before starting Compose if port 8080 is already occupied.
The GHCR preview workflow is manual-only; use it when a revision is ready to
share rather than for normal local iteration.

## Standard mechanical parts provider

The **Standard mechanical parts** provider is always available and works
offline. It contains a pinned, metadata-only subset of the
[BOLTS Open Library of Technical Specifications](https://boltsparts.github.io/).
Search for a standard and size such as:

```text
DIN 912 M6 x 20
```

The result imports the canonical standard, equivalent standards, hardware
type, head and drive styles, thread designation, nominal diameter, coarse
pitch, and length. It deliberately does not guess a material, finish, property
class, or hardness. It suggests a matching category path but does not create
that category; the user remains in control of the category hierarchy.

The source revision, license, and warranty notice are recorded in
`resources/mechanical/bolts/ATTRIBUTION.md`. Standard metadata is a convenient
index, not an engineering authority; verify critical dimensions against the
official standard.

## Mechanical parameter conventions

Supplier adapters should normalize data to these names in the
`Mechanical parameters` group:

- Hardware type
- Standard and equivalent standards
- Head style and drive
- Thread system and thread designation
- Nominal diameter, thread pitch, and length
- Material and finish
- Property class
- Hardness

Property class and hardness are separate. For example, `12.9` is a metric
fastener property class, while `39 HRC` is a hardness measurement.

## TraceParts

TraceParts is an optional exact-part-number provider. TraceParts does not offer
a general keyword endpoint in its documented API, so enter a manufacturer part
number and configure the matching catalog label.

Required environment variables:

```dotenv
PROVIDER_TRACEPARTS_API_KEY=
PROVIDER_TRACEPARTS_TENANT_UID=
PROVIDER_TRACEPARTS_CATALOG=
PROVIDER_TRACEPARTS_LANGUAGE=en
PROVIDER_TRACEPARTS_SYNDICATION_APPROVED=1
```

The standard TraceParts API terms restrict copying or storing catalog data
outside CAD/viewing use without prior written consent. Do not enable
`PROVIDER_TRACEPARTS_SYNDICATION_APPROVED` unless TraceParts has approved your
use. The provider does not request generated CAD files because that workflow
requires downloader identity information. It imports linked documentation and
lists available CAD formats without requesting a download.

## Add another mechanical supplier

A supplier such as Würth should be implemented as a normal
`InfoProviderInterface`, just like DigiKey:

1. Search the supplier API and return `SearchResultDTO` values.
2. Convert supplier attributes with `MechanicalPartNormalizer`.
3. Return the normalized parameters and category path in `PartDetailDTO`.
4. Add supplier SKU, product URL, and prices through `PurchaseInfoDTO`.
5. Keep supplier-specific authentication and transport logic inside that
   provider.

This separation lets BOLTS describe a neutral standardized item while Würth or
another distributor adds purchasable order numbers and live pricing.
