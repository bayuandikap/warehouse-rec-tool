# Simpul Warehouse Selection

A Laravel + MySQL application that recommends which warehouse or combination
of warehouses should fulfill a medical-supply order, factoring in stock
availability, cold-chain requirements, and delivery distance.

**Live demo:** _(add URL after deploying)_

---

## Table of Contents

1. [Approach and Reasoning](#approach-and-reasoning)
2. [Assumptions](#assumptions)
3. [AI Assistance ΓÇö What Was Used, What Was Corrected](#ai-assistance)
4. [What I'd Do Next](#what-id-do-next)
5. [Local Setup](#local-setup)
6. [Running Tests](#running-tests)
7. [API Reference](#api-reference)
8. [Deployment ΓÇö InfinityFree](#deployment--infinityfree)
9. [Project Structure](#project-structure)

---

## Approach and Reasoning

### The core problem

The challenge asks: given an order for medical products, which warehouse (or
warehouses) should fill it? The answer depends on three things at once ΓÇö
whether a warehouse has enough stock, whether it can handle the product's
storage requirements, and how far away it is. Getting any one of those wrong
produces a meaningless recommendation.

My first instinct was to keep this honest: define a concrete objective,
implement it straightforwardly, and be explicit about what the algorithm can
and cannot do. That matters more here than implementing something clever.

### Defining the objective before writing any code

The brief says "consider delivery distance" but doesn't say how. I chose
to minimise the **quantity-weighted distance score** ΓÇö `╬ú(allocated_qty ├ù
distance_km)` ΓÇö as the primary objective, subject to hard constraints on
stock availability and storage eligibility. This is a precise, auditable
metric. I deliberately avoided a weighted scoring formula that mixed distance
and stock in a single number, because those are easy to tune to produce
whatever output looks good rather than what is correct.

### Why greedy rather than exhaustive search

With 8 warehouses and 5 products the search space is small enough that
exhaustive enumeration would work fine. I chose a **greedy nearest-first**
approach instead because it directly implements the stated objective: for each
product, drain stock from the closest eligible warehouse, then spill over to
the next closest when that runs out. For the defined objective function this
greedy strategy is optimal ΓÇö there is no scenario where choosing a farther
warehouse for a product first could reduce the total score.

I did consider exhaustive search and decided against it because it would
imply a global optimisation property I cannot guarantee once you add more
warehouses or more complex constraints. The greedy approach is honest about
what it does.

### Simulation, not reservation

The selection result is advisory. Calling the API does not decrement any
inventory. I made this choice deliberately: a recommendation endpoint should
be safe to call many times without side effects. Stock reservation belongs in
a separate "confirm order" step with proper transaction locking. Conflating
the two would have made the API dangerous to use in any concurrent setting.

### Architecture choices

I used a plain Laravel monolith with Blade ΓÇö exactly what the brief asked for.
The structure follows standard Laravel conventions:

- **`WarehouseSelectionService`** ΓÇö owns the algorithm. No business logic
  sits in the controller.
- **DTOs** ΓÇö `OrderItemDTO`, `WarehouseAllocationDTO`, `SelectionResultDTO`,
  etc. give the algorithm a typed, immutable interface. This makes it easy
  to test the service in isolation without HTTP scaffolding.
- **`WarehouseSelectionRequest`** ΓÇö handles all validation including
  unknown product codes (checked against the database) and duplicate lines.
- **`SelectionResultResource`** ΓÇö keeps the JSON shape decoupled from the
  internal DTOs.
- Three database queries total per request (products, warehouses,
  inventories). No queries inside loops.

I did not add a repository layer, a dedicated domain layer, event sourcing,
or any other pattern that would add indirection without adding value at this
scale. The rule I applied: add an abstraction when it makes the code clearer
or easier to test, not to demonstrate that I know the pattern exists.

### Testing approach

I wrote tests against the actual challenge dataset as a fixed fixture, not
mocked data. That means the tests verify the real business rules:

- The nearest warehouse actually wins for a non-refrigerated product.
- W02 (cold storage, standard vehicles) is explicitly excluded from
  supplying vaccines ΓÇö this is tested directly.
- Shortages add up correctly across warehouses.
- Allocations never exceed either the requested quantity or the available
  stock.
- Results are deterministic for identical inputs.

Tests use in-memory SQLite, which runs without a running MySQL server and
keeps CI simple. The schema and seed data have been verified separately
against the production MariaDB/MySQL database.

---

## Assumptions

The brief provided warehouse refrigeration data but left several decisions
unspecified. I documented each gap and made a defensible choice rather than
silently picking one.

| # | Gap in the brief | Decision made |
|---|---|---|
| A1 | Which products require refrigeration? The brief lists categories but not requirements. | Vaccines (P002 Hepatitis B, P004 Tetanus Toxoid) require cold-chain handling based on standard pharmaceutical practice. P001, P003, P005 do not. Stored as `products.requires_refrigeration`. |
| A2 | Does "cold storage" at a warehouse mean it can also *deliver* cold? | No. W02 and W06 have cold storage facilities but only standard delivery vehicles. I model two separate flags: `has_cold_storage` (facility) and `has_refrigerated_delivery` (vehicles). A warehouse needs **both** to supply a refrigerated product. W04, W07, W08 have both and are eligible for vaccines. |
| A3 | Can a single order be split across multiple warehouses? | Yes. The brief says "combination of warehouses" so split fulfillment is explicitly supported and preferred over leaving stock unallocated. |
| A4 | Does calling the API reserve stock? | No ΓÇö this is a simulation. No inventory is modified. The brief asks for a recommendation tool; actual reservation would need a separate confirmed-order step with transaction locking. |
| A5 | What does "distance" mean in the scoring? | `╬ú(allocated_quantity ├ù warehouse_distance_km)`. This is a routing-cost *proxy*, not real travel distance or shipping cost. I labelled it explicitly in the API response to avoid misleading anyone. |
| A6 | What happens if two warehouses have equal scores? | The warehouses are sorted by distance at load time. The first warehouse encountered wins ties deterministically. |
| A7 | What if an order contains duplicate product lines? | Lines are normalised by summing their quantities before processing. A validation warning is also returned so the caller knows their request was modified. |
| A8 | Is there a maximum order quantity? | 9,999 units per line ΓÇö a reasonable application-level cap to prevent obviously erroneous inputs. Not stated in the brief; documented here. |

The one assumption I'd most want to verify with a product owner before going
to production is **A2** ΓÇö the refrigerated delivery requirement. If W02 and
W06 are actually allowed to deliver vaccines (perhaps picked up by a
refrigerated courier rather than their own vehicles), the eligibility table
changes significantly.

---

## AI Assistance

### What was used

This project was built using **Kiro (powered by Claude)** as an AI coding
assistant with direct workspace access. The AI generated the initial versions
of most files: migrations, seeders, models, DTOs, the service class,
controller, Form Request, API Resource, Blade views, and the test suite.

### What I reviewed and corrected

AI-generated code is fast but it is not automatically correct. Here is an
honest account of where I pushed back or fixed things:

**Dead code in `deriveStatus`.**
The initial `WarehouseSelectionService` computed a variable `$anyAllocated`
that was then never used ΓÇö the actual logic was duplicated in an inner loop
below it. The variable was removed. This is the kind of mistake that happens
when code is written quickly without a second pass.

**The refrigerated delivery split.**
The first version of the warehouse data treated `has_cold_storage` as the
only condition for vaccine eligibility. I identified that W02 and W06 have
cold storage but only standard vehicles and flagged this as a problem ΓÇö
storing a vaccine correctly and delivering it cold are two different
requirements. I debated this with the AI and the schema was updated to carry
both flags separately, with `canHandleRefrigerated()` on the Warehouse model
enforcing the combined check.

**The distance-score definition.**
Early drafts described the score as "delivery distance" and "shipping cost"
interchangeably. I corrected this to label it explicitly as a routing-cost
*proxy* ΓÇö a `╬ú(qty ├ù km)` figure ΓÇö because calling it a real cost would be
technically dishonest. The API response includes a `distance_score_note` field
that explains what the number actually is.

**MariaDB collation error.**
The generated `.env` used `DB_CONNECTION=mysql` and MySQL 8.0's default
collation `utf8mb4_0900_ai_ci`. The local environment runs MariaDB 10.4 (via
XAMPP), which does not support that collation. Fixed by switching to
`DB_CONNECTION=mariadb` and `DB_COLLATION=utf8mb4_unicode_ci`.

**Test assertion type mismatch.**
One test asserted `assertSame(500.0, $score)` where `$score` was decoded from
JSON. PHP's JSON decoder returns `500` (integer) when a value has no decimal
part, so `assertSame` failed on strict type comparison. Changed to
`assertEquals(500)` which is the semantically correct assertion for a numeric
comparison.

**SQL dump encoding.**
The first automated database dump produced by `mysqldump` on Windows used
Windows-1252 encoding, which turned the em-dashes in product names (e.g.
"Vaccine ΓÇô Hepatitis B") into garbled sequences like `╬ô├ç├┤`. The final
`deploy/database_seed.sql` was written by hand as clean UTF-8 to avoid this.

### What I would do differently with AI assistance

The AI is very good at scaffolding structure and very fast at writing
boilerplate. Where it falls short is in understanding *intent* ΓÇö it implements
what you describe literally but does not always catch when two parts of the
system are in tension. The refrigerated delivery issue is a good example: the
AI dutifully implemented cold-storage checking because that was described, but
missed the delivery-vehicle distinction because that required reading between
the lines of the warehouse data.

My general approach was: let the AI generate a first draft, then read every
file as if reviewing a colleague's PR. The test suite was particularly useful
here ΓÇö writing tests forced me to think about edge cases that the initial
implementation did not handle cleanly.

---

## What I'd Do Next

If I had more time, in order of priority:

**1. Stock reservation with proper locking**

The current system is advisory. A real deployment needs a "confirm order"
endpoint that runs inside a database transaction with `SELECT ΓÇª FOR UPDATE`
on the relevant inventory rows. Without this, two concurrent users requesting
the same stock could both receive a "complete" recommendation and then both
try to fulfil it, resulting in overselling. This is the most important missing
piece for production use.

**2. A meaningful test against the InfinityFree deployment**

I have not yet deployed to InfinityFree and verified the live URL. The
deployment package is prepared (SQL import file, `.htaccess` workaround,
production `.env` template), but "prepared" is not the same as "working".
The deployment needs to be executed and the end-to-end workflow tested on the
actual public URL before the submission is complete.

**3. Minimising warehouse count as a secondary objective**

The current algorithm minimises distance score but treats the number of
warehouses used as irrelevant. In practice, splitting an order across five
warehouses to save a small amount of travel distance is probably worse than
using two slightly farther warehouses. I'd add a secondary objective: among
allocations with equal or near-equal distance scores, prefer fewer warehouses.
This would require tracking the count during allocation and adjusting the
greedy order accordingly.

**4. Historical delivery data**

The challenge includes an optional customer delivery history CSV. I have not
tackled this. The right approach would be: inspect the data first (column
names, date range, missing values, duplicates), define a baseline selection
method without it, then ask whether the historical data actually correlates
with faster or more reliable delivery from specific warehouses. A small
dataset with selection bias ΓÇö the historical orders probably went to the same
warehouses repeatedly ΓÇö can easily produce misleading correlations. I would
not bolt on any model until that analysis was done.

**5. Order persistence**

The schema has no `orders` table because selection is advisory. If this were
a real operational system, you'd want to record each recommendation made ΓÇö
which warehouses were selected, what the stock levels were at the time, what
the customer ordered ΓÇö both for auditing and for feeding back into future
selection decisions.

**6. Inventory management UI**

Currently the only way to update warehouse stock is to re-run the seeders or
write directly to the database. A simple admin interface for adjusting
quantities would make the application genuinely usable for demonstration
purposes.

---

## Local Setup

### Requirements

- PHP 8.2+
- Composer 2
- MySQL 8.0+ or MariaDB 10.4+

### Steps

```bash
# 1. Clone
git clone <repo-url>
cd simpul

# 2. Install dependencies
composer install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Edit .env ΓÇö set DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#    MySQL 8.0+:   DB_CONNECTION=mysql
#    MariaDB 10.x: DB_CONNECTION=mariadb
#                  DB_COLLATION=utf8mb4_unicode_ci

# 5. Create the database
mysql -u root -p -e "CREATE DATABASE simpul_warehouse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. Run migrations and seed
php artisan migrate:fresh --seed

# 7. Serve
php artisan serve
```

Visit `http://localhost:8000`.

---

## Running Tests

Tests use an in-memory SQLite database ΓÇö no running MySQL server required.

```bash
php artisan test
```

**Verified results:**

```
PASS  Tests\Unit\WarehouseSelectionServiceTest
  Γ£ô single product fulfilled by nearest warehouse
  Γ£ô single product nearest warehouse insufficient stock
  Γ£ô order fulfilled from multiple warehouses
  Γ£ô refrigerated product uses cold warehouse
  Γ£ô refrigerated product not supplied by non cold warehouse
  Γ£ô sufficient total stock but no valid cold allocation
  Γ£ô insufficient combined eligible stock
  Γ£ô empty items array returns unfulfillable
  Γ£ô duplicate lines normalized by controller
  Γ£ô empty warehouse inventory
  Γ£ô order with all five products
  Γ£ô deterministic results for equivalent requests
  Γ£ô allocations never exceed requested quantity
  Γ£ô allocations never exceed initial stock
  Γ£ô explanations reference actual warehouse codes
  Γ£ô explanation mentions refrigeration for cold product
  Γ£ô distance score is sum of qty times distance

PASS  Tests\Feature\WarehouseSelectionApiTest
  Γ£ô valid single product returns 200 complete
  Γ£ô multi product order returns 200
  Γ£ô all five products returns complete
  Γ£ô partial order returns 200 partial status
  Γ£ô unfulfillable order returns 200 unfulfillable status
  Γ£ô unknown product id returns 422
  Γ£ô missing items returns 422
  Γ£ô empty items array returns 422
  Γ£ô zero quantity returns 422
  Γ£ô negative quantity returns 422
  Γ£ô fractional quantity returns 422
  Γ£ô quantity exceeding max returns 422
  Γ£ô duplicate product lines flagged
  Γ£ô missing product id returns 422
  Γ£ô missing quantity returns 422
  Γ£ô refrigerated product not from non cold warehouse
  Γ£ô distance score calculation is correct
  Γ£ô allocated quantity does not exceed requested
  Γ£ô shortage plus allocated equals requested
  Γ£ô explanations are present on complete result
  Γ£ô index page returns 200

Tests: 42 passed (151 assertions)
```

---

## API Reference

### `POST /api/warehouse-selection`

Accepts a JSON body. Returns a recommendation ΓÇö never modifies inventory.

**Request**

```json
{
  "items": [
    { "product_id": "P001", "quantity": 100 },
    { "product_id": "P002", "quantity": 20 },
    { "product_id": "P003", "quantity": 200 }
  ]
}
```

`product_id` is the product code (P001ΓÇôP005). Quantities must be integers
between 1 and 9,999. Duplicate product lines return a 422.

**Response ΓÇö complete order**

```json
{
  "data": {
    "status": "complete",
    "summary": "The order can be fulfilled completely from the selected warehouse(s).",
    "allocations": [
      {
        "warehouse_code": "W01",
        "warehouse_city": "Surabaya",
        "distance_km": 5,
        "items": [
          {
            "product_code": "P001",
            "product_name": "Surgical Gloves (Box of 100)",
            "requested_quantity": 100,
            "allocated_quantity": 100
          },
          {
            "product_code": "P003",
            "product_name": "Disposable Syringe (Box of 50)",
            "requested_quantity": 200,
            "allocated_quantity": 200
          }
        ],
        "warehouse_distance_score": 1500
      },
      {
        "warehouse_code": "W04",
        "warehouse_city": "Gresik",
        "distance_km": 30,
        "items": [
          {
            "product_code": "P002",
            "product_name": "Vaccine \u2013 Hepatitis B (10-dose vial)",
            "requested_quantity": 20,
            "allocated_quantity": 20
          }
        ],
        "warehouse_distance_score": 600
      }
    ],
    "unfulfilled_items": [],
    "total_allocated_lines": 3,
    "total_distance_score": 2100,
    "distance_score_note": "Sum of (allocated_quantity \u00d7 warehouse_distance_km). A routing-cost proxy, not an actual shipping price.",
    "explanations": [
      "W01 (5 km) will supply 100 unit(s) of Surgical Gloves (Box of 100): has sufficient stock (200 available) and meets storage requirements.",
      "W04 (30 km) will supply 20 unit(s) of Vaccine \u2013 Hepatitis B (10-dose vial): has sufficient stock (40 available) and meets storage requirements."
    ]
  }
}
```

**Response ΓÇö partial order**

```json
{
  "data": {
    "status": "partial",
    "summary": "The order can only be partially fulfilled. 1 product line(s) have insufficient stock: Vaccine \u2013 Hepatitis B (10-dose vial).",
    "allocations": [ "..." ],
    "unfulfilled_items": [
      {
        "product_code": "P002",
        "product_name": "Vaccine \u2013 Hepatitis B (10-dose vial)",
        "requested_quantity": 200,
        "allocated_quantity": 108,
        "shortage_quantity": 92,
        "reason": "Combined eligible refrigerated-warehouse stock is less than requested quantity."
      }
    ],
    "total_distance_score": 10560
  }
}
```

**HTTP status codes**

| Code | Meaning |
|---|---|
| `200` | Valid request ΓÇö status is `complete`, `partial`, or `unfulfillable` |
| `422` | Validation error (unknown product, bad quantity, empty items, etc.) |
| `500` | Unexpected server error |

---

## Deployment ΓÇö InfinityFree

InfinityFree runs PHP 8.3 and MySQL, which are fully compatible with Laravel 11.
It has several shared-hosting constraints that require specific workarounds.

| Constraint | Workaround |
|---|---|
| No SSH / no CLI | Schema + seed data provided as `deploy/database_seed.sql` for phpMyAdmin |
| Web root is always `htdocs/` | `htdocs.htaccess` routes all traffic through `public/` |
| No symlinks | Not needed ΓÇö this app has no file uploads |
| Database host is not `localhost` | `.env.infinityfree` template has the correct structure |
| 1 MB per-file upload limit | `autoload_classmap.php` is 560 KB ΓÇö safely under the limit |

### Steps

1. Create a hosting account and a MySQL database in the InfinityFree control panel.
2. Note your database **host** (e.g. `sql123.infinityfree.com`), name, username, password.
3. Import `deploy/database_seed.sql` via phpMyAdmin.
4. Copy `.env.infinityfree` to `.env.production`, fill in the DB credentials and `APP_KEY`.
   - Generate the key locally: `php artisan key:generate --show`
   - Set `APP_DEBUG=false`
5. Run `php artisan optimize:clear` locally to remove cached files.
6. Upload all project files into `htdocs/` using the File Manager.
   Zip `vendor/` separately (it is large); upload and extract both archives.
7. Upload your filled `.env.production` into `htdocs/`, rename it `.env`.
8. Upload `htdocs.htaccess` into `htdocs/`, rename it `.htaccess`.
9. Visit your subdomain to confirm the UI loads.

---

## Project Structure

```
app/
  DTOs/                               # Immutable value objects
  Http/
    Controllers/WarehouseSelectionController.php
    Requests/WarehouseSelectionRequest.php
    Resources/SelectionResultResource.php
  Models/                             # Product, Warehouse, Inventory
  Services/WarehouseSelectionService.php   # Core algorithm

database/
  migrations/                         # Schema definitions
  seeders/                            # Challenge dataset

resources/views/
  layouts/app.blade.php
  warehouse-selection/index.blade.php

routes/
  web.php                             # GET /
  api.php                             # POST /api/warehouse-selection

tests/
  Unit/WarehouseSelectionServiceTest.php   # 17 service tests
  Feature/WarehouseSelectionApiTest.php    # 21 HTTP tests
  Support/SeedsChallengeData.php           # Shared fixture

deploy/
  database_seed.sql                   # Portable SQL import for InfinityFree
```
