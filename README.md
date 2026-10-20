# Simpul Warehouse Selection

A Laravel + MariaDB/MySQL application that recommends which warehouse or
combination of warehouses should fulfill a medical-supply order, considering
stock availability, cold-chain storage requirements, and delivery distance.

---

## Live Demo

> Deployment URL: _(add after deploying to production)_

---

## Problem Summary

A medical distribution platform receives orders containing one or more products
and quantities. The system must recommend the best warehouse(s) to fulfill each
order by evaluating:

- Available stock at every warehouse.
- Cold-chain (refrigeration) requirements for pharmaceutical products.
- Delivery distance from the warehouse to the customer hub.
- Whether a partial or full fulfillment is possible.

The five products and eight warehouses used in this application come from the
Simpul Tech Backend Skill Challenge dataset.

---

## Key Assumptions and Business Rules

| # | Assumption |
|---|---|
| A1 | **Refrigeration**: Vaccines (P002 Hepatitis B, P004 Tetanus Toxoid) require cold-chain handling. Surgical Gloves (P001), Syringes (P003), and Paracetamol (P005) do not. Modelled as `products.requires_refrigeration`. |
| A2 | **Cold-chain eligibility**: A warehouse can supply a refrigerated product only if it has **both** `has_cold_storage = true` (facility) **and** `has_refrigerated_delivery = true` (vehicles). W02 and W06 have cold storage but only standard vehicles — they cannot supply refrigerated products. W04, W07, and W08 have refrigerated vans and are fully eligible. |
| A3 | **Split orders**: An order may be fulfilled from multiple warehouses when no single warehouse can cover the full quantity. |
| A4 | **Simulation only**: The selection result is advisory. No inventory is decremented. The same inventory unit is never counted twice within one order via an in-memory virtual inventory map. A separate "confirm order" workflow could later reserve stock. |
| A5 | **Distance score**: `Σ(allocated_quantity × warehouse_distance_km)`. A routing-cost proxy — not a real shipping price or map distance. |
| A6 | **Selection policy**: Prefer the nearest eligible warehouse first. Spill over to the next nearest only when the nearest cannot cover the full quantity. This minimises the distance score while respecting all constraints. |
| A7 | **Duplicate lines**: Duplicate product lines in a single request are merged by summing their quantities before processing. |
| A8 | **Maximum quantity**: 9,999 units per order line (application-level cap). |

---

## Algorithm

The selection algorithm lives in `app/Services/WarehouseSelectionService.php`.

### Objective

> Minimise Σ(allocated_quantity × warehouse_distance_km) subject to:
> 1. Each product is allocated only from warehouses with sufficient stock.
> 2. Products requiring refrigeration are allocated only from warehouses that
>    have both cold storage AND refrigerated delivery capability.
> 3. The same inventory unit is never allocated twice in one order.

### Strategy

For each product in the order:

1. Build a list of eligible warehouses sorted by distance (nearest first).
2. Drain stock greedily from the nearest eligible warehouse.
3. Spill over to the next nearest warehouse when stock runs out.
4. Record any remaining shortage after all eligible warehouses are exhausted.

This greedy nearest-first approach is optimal for the stated objective with
8 warehouses and 5 products. The algorithm is O(P × W) — trivially fast.

### Fulfillment status

| Status | Meaning |
|---|---|
| `complete` | Every requested item is fully allocated. |
| `partial` | At least one item has a shortage; some items may be fully allocated. |
| `unfulfillable` | No items could be allocated at all. |

---

## Technical Stack

| Component | Version | Reason |
|---|---|---|
| Backend | Laravel 11 + PHP 8.2 | Current stable LTS line |
| Database | MariaDB 10.4 / MySQL 8.0 | Relational model; FK constraints enforce integrity |
| Frontend | Laravel Blade + vanilla JS | No SPA overhead; simple stack as specified |
| Tests | PHPUnit 10 (Laravel default) | Ships with the framework |
| Architecture | Laravel monolith | Proportionate to the problem |

**Note on MariaDB vs MySQL**: The `DB_CONNECTION=mariadb` driver is used locally
(XAMPP ships MariaDB 10.4). On a production MySQL 8.0 host, change this to
`DB_CONNECTION=mysql` in `.env`. The application schema and queries are
compatible with both.

---

## Database Schema

```
products        code (unique), name, category, requires_refrigeration
warehouses      code (unique), city, distance_km, has_cold_storage, has_refrigerated_delivery
inventories     warehouse_id (FK), product_id (FK), quantity
                UNIQUE KEY (warehouse_id, product_id)
                INDEX on warehouse_id, INDEX on product_id
```

No `orders` table — selection is advisory and non-destructive.

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

# 4. Edit .env — set DB_DATABASE, DB_USERNAME, DB_PASSWORD
#    • MySQL 8.0+:  DB_CONNECTION=mysql
#    • MariaDB 10.x: DB_CONNECTION=mariadb + DB_COLLATION=utf8mb4_unicode_ci

# 5. Create the database
# mysql -u root -p -e "CREATE DATABASE simpul_warehouse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. Run migrations and seed
php artisan migrate:fresh --seed

# 7. Start the development server
php artisan serve
```

Visit `http://localhost:8000`.

---

## Running Tests

Tests run against an in-memory SQLite database — no separate test DB required.

```bash
php artisan test
```

**Verified test results (38 tests, 135 assertions, 0 failures):**

```
PASS  Tests\Unit\WarehouseSelectionServiceTest
  ✓ single product fulfilled by nearest warehouse
  ✓ single product nearest warehouse insufficient stock
  ✓ order fulfilled from multiple warehouses
  ✓ refrigerated product uses cold warehouse
  ✓ refrigerated product not supplied by non cold warehouse
  ✓ sufficient total stock but no valid cold allocation
  ✓ insufficient combined eligible stock
  ✓ empty items array returns unfulfillable
  ✓ duplicate lines normalized by controller
  ✓ empty warehouse inventory
  ✓ order with all five products
  ✓ deterministic results for equivalent requests
  ✓ allocations never exceed requested quantity
  ✓ allocations never exceed initial stock
  ✓ explanations reference actual warehouse codes
  ✓ explanation mentions refrigeration for cold product
  ✓ distance score is sum of qty times distance

PASS  Tests\Feature\WarehouseSelectionApiTest
  ✓ valid single product returns 200 complete
  ✓ multi product order returns 200
  ✓ all five products returns complete
  ✓ partial order returns 200 partial status
  ✓ unfulfillable order returns 200 unfulfillable status
  ✓ unknown product id returns 422
  ✓ missing items returns 422
  ✓ empty items array returns 422
  ✓ zero quantity returns 422
  ✓ negative quantity returns 422
  ✓ fractional quantity returns 422
  ✓ quantity exceeding max returns 422
  ✓ duplicate product lines flagged
  ✓ missing product id returns 422
  ✓ missing quantity returns 422
  ✓ refrigerated product not from non cold warehouse
  ✓ distance score calculation is correct
  ✓ allocated quantity does not exceed requested
  ✓ shortage plus allocated equals requested
  ✓ explanations are present on complete result
  ✓ index page returns 200

Tests: 38 passed (135 assertions)
```

---

## API Reference

### `POST /api/warehouse-selection`

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

`product_id` must be a valid product code (P001–P005). Quantities must be
integers between 1 and 9,999.

**Response — complete order**

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
            "product_name": "Vaccine – Hepatitis B (10-dose vial)",
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
    "distance_score_note": "Sum of (allocated_quantity × warehouse_distance_km). A routing-cost proxy, not an actual shipping price.",
    "explanations": [
      "W01 (5 km) will supply 100 unit(s) of Surgical Gloves...",
      "W04 (30 km) will supply 20 unit(s) of Vaccine – Hepatitis B..."
    ]
  }
}
```

**Response — partial order** (example: 200 Hepatitis B vaccines, only 108 available across eligible warehouses)

```json
{
  "data": {
    "status": "partial",
    "summary": "The order can only be partially fulfilled. 1 product line(s) have insufficient stock: Vaccine – Hepatitis B (10-dose vial).",
    "allocations": [ ... ],
    "unfulfilled_items": [
      {
        "product_code": "P002",
        "product_name": "Vaccine – Hepatitis B (10-dose vial)",
        "requested_quantity": 200,
        "allocated_quantity": 108,
        "shortage_quantity": 92,
        "reason": "Combined eligible refrigerated-warehouse stock is less than requested quantity."
      }
    ],
    ...
  }
}
```

**HTTP status codes**

| Code | When |
|---|---|
| `200` | Valid request — complete, partial, or unfulfillable decision |
| `422` | Invalid input (unknown product, bad quantity, empty items, duplicates) |
| `500` | Unexpected server error |

---

## Deployment

### InfinityFree (free shared hosting)

InfinityFree supports PHP 8.3 and MySQL, which is fully compatible with this
application. There are several constraints that require specific workarounds.

**Constraints and how this project handles them:**

| Constraint | Impact | Workaround applied |
|---|---|---|
| No SSH / no CLI | Can't run `php artisan` | Schema + seed data provided as a SQL file for phpMyAdmin import |
| Web root is always `htdocs/` | Laravel's `public/` can't be web root | `htdocs.htaccess` redirects all traffic through `public/` |
| `open_basedir` restriction | PHP can't read outside `htdocs/` | Everything uploaded into `htdocs/`; `htdocs.htaccess` handles routing |
| No symlinks | `php artisan storage:link` fails | Not needed — this app has no file uploads |
| 1 MB file upload limit | Large files silently rejected | Autoloader is 560 KB — safely under the limit |
| No background workers | Queues, schedulers don't work | Already using `QUEUE_CONNECTION=sync` |
| Database host is NOT `localhost` | Different host per account | Documented in `.env.infinityfree` template |

---

#### Step-by-step InfinityFree deployment

**1. Create your InfinityFree account and hosting**
- Sign up at [infinityfree.com](https://infinityfree.com)
- Create a new hosting account — you'll get a subdomain like `yourapp.infinityfreeapp.com`

**2. Create the MySQL database**
- In your control panel → **MySQL Databases**
- Create a database and note the **host**, **database name**, **username**, **password**
- The host will be something like `sql123.infinityfree.com` — it is **not** `localhost`

**3. Import the database schema + seed data**
- In the control panel → **phpMyAdmin**
- Select your new database
- Click **Import** → upload `deploy/database_seed.sql`
- This creates all tables and inserts the 5 products, 8 warehouses, and 40 inventory rows

**4. Prepare your `.env` file locally**
```
# Copy the template
cp .env.infinityfree .env.production

# Edit .env.production with your actual values:
APP_KEY=          # Run: php artisan key:generate --show
APP_URL=          # Your InfinityFree subdomain, e.g. https://yourapp.infinityfreeapp.com
DB_HOST=          # From control panel (e.g. sql123.infinityfree.com)
DB_DATABASE=      # From control panel
DB_USERNAME=      # From control panel
DB_PASSWORD=      # Your database password
APP_DEBUG=false   # MUST be false in production
```

**5. Generate your APP_KEY**
```bash
php artisan key:generate --show
# Copy the output (base64:...) into .env.production
```

**6. Clear local caches before uploading**
```bash
php artisan optimize:clear
```

**7. Package the files**

Zip the files in two batches (the File Manager has upload limits):
```bash
# On Windows — use 7-Zip or Windows Explorer right-click → Send to → Compressed
# Zip 1: everything except vendor/  (name it: project.zip)
# Zip 2: the vendor/ folder alone   (name it: vendor.zip)
```

**8. Upload via File Manager**
- In the control panel → **File Manager** → open `htdocs/`
- Upload and extract `project.zip` into `htdocs/`
- Upload and extract `vendor.zip` into `htdocs/` (merges with existing `vendor/` folder)
- Upload your `.env.production` file, rename it to `.env` in the file manager
- Upload `htdocs.htaccess` from your project root, rename it to `.htaccess` in `htdocs/`

**Your final `htdocs/` structure should be:**
```
htdocs/
├── .htaccess          ← renamed from htdocs.htaccess
├── .env               ← renamed from .env.production
├── app/
├── bootstrap/
├── config/
├── public/
│   ├── index.php
│   └── .htaccess      ← Laravel's built-in, leave untouched
├── resources/
├── routes/
├── storage/
├── vendor/
└── ...
```

**9. Test your deployment**
- Visit your subdomain — you should see the warehouse selection UI
- Try submitting an order with P001 × 50
- Check `/api/warehouse-selection` responds to a POST request

**10. Security hardening**
- Confirm `APP_DEBUG=false` in `.env`
- Confirm `.env` is not publicly accessible (the `htdocs.htaccess` routes it to `public/.env` which doesn't exist → 404)

---

### Other providers (Railway, Render, Fly.io)

```bash
# Standard Laravel deployment on a proper server
APP_ENV=production
APP_DEBUG=false
APP_KEY=<php artisan key:generate --show>
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=<host>
DB_PORT=3306
DB_DATABASE=simpul_warehouse
DB_USERNAME=<user>
DB_PASSWORD=<password>

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync

# After deployment:
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
```

---

## Project Structure

```
app/
  DTOs/                               # Immutable value objects for the domain
    OrderItemDTO.php
    AllocationLineDTO.php
    WarehouseAllocationDTO.php
    UnfulfilledItemDTO.php
    SelectionResultDTO.php
  Http/
    Controllers/
      WarehouseSelectionController.php  # Thin: validates → service → resource
    Requests/
      WarehouseSelectionRequest.php     # Validation + product-code check
    Resources/
      SelectionResultResource.php       # JSON response shape
  Models/
    Product.php
    Warehouse.php
    Inventory.php
  Services/
    WarehouseSelectionService.php       # Core allocation algorithm
  Providers/
    AppServiceProvider.php

database/
  migrations/
    2024_01_01_000001_create_products_table.php
    2024_01_01_000002_create_warehouses_table.php
    2024_01_01_000003_create_inventories_table.php
  seeders/
    DatabaseSeeder.php
    ProductSeeder.php
    WarehouseSeeder.php
    InventorySeeder.php

resources/views/
  layouts/app.blade.php               # Base layout with inline CSS
  warehouse-selection/index.blade.php # Main UI page

routes/
  web.php                             # GET /
  api.php                             # POST /api/warehouse-selection

tests/
  Unit/WarehouseSelectionServiceTest.php   # 17 service-layer tests
  Feature/WarehouseSelectionApiTest.php    # 21 HTTP integration tests
  Support/SeedsChallengeData.php           # Shared fixture trait
```

---

## AI Assistance Disclosure

This project was built with significant AI assistance (Kiro / Claude) in these areas:

- Scaffolding the Laravel project structure.
- Writing migrations, seeders, models, DTOs, service class, controller, Form Request, API Resource, and Blade views.
- Writing the test suite.
- Generating documentation.

All AI-generated code was reviewed for correctness and alignment with requirements. The algorithm design, assumption documentation, architectural decisions, and business-rule validations were confirmed manually and via the test suite.

Specific corrections made during review:
- Dead code (`$anyAllocated` variable) removed from `deriveStatus`.
- MariaDB collation adjusted for local compatibility.
- Test assertions corrected for PHP/JSON type coercion (`assertSame(500.0)` → `assertEquals(500)`).

---

## Technical Trade-offs

| Decision | Trade-off |
|---|---|
| Advisory simulation (no stock reservation) | Simpler, safe to call repeatedly; but cannot prevent overselling if used in production without a confirm step |
| Greedy nearest-first allocation | Optimal for stated objective with this dataset; not globally optimal for arbitrary future constraints |
| SQLite for tests | Faster test runs; tested separately that the schema and queries work on MariaDB/MySQL |
| No order persistence | Keeps the schema simple; a `orders`/`order_items` table would be added for a real reservation workflow |

---

## Future Improvements

1. **Order confirmation endpoint** — `POST /api/orders/confirm` with `SELECT … FOR UPDATE` locking to actually reserve stock.
2. **Inventory management** — UI for warehouse managers to update stock levels.
3. **Real road distances** — Replace fixed `distance_km` with geocoded actual distances via a mapping API.
4. **Historical delivery data** — Analyse whether past delivery patterns improve warehouse selection.
5. **API authentication** — Token-based auth if the API is exposed to external systems.
6. **Minimise warehouse count** — Add a secondary objective to prefer fewer warehouses when scores are equal.
