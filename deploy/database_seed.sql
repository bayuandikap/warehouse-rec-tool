-- Simpul Warehouse Selection — Database Schema + Seed Data
-- Generated for InfinityFree deployment (MySQL 5.6+ / 8.0 compatible)
-- Import this file via phpMyAdmin after creating an empty database.
-- Do NOT run migrations separately — this file replaces php artisan migrate --seed.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- Schema
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `migrations` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch`     INT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                   VARCHAR(10)  NOT NULL COMMENT 'Unique product code, e.g. P001',
  `name`                   VARCHAR(255) NOT NULL,
  `category`               VARCHAR(100) NOT NULL,
  `requires_refrigeration` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`             TIMESTAMP    NULL DEFAULT NULL,
  `updated_at`             TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouses` (
  `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                      VARCHAR(10)  NOT NULL COMMENT 'Unique warehouse code, e.g. W01',
  `city`                      VARCHAR(100) NOT NULL,
  `distance_km`               DECIMAL(8,2) NOT NULL,
  `has_cold_storage`          TINYINT(1)   NOT NULL DEFAULT 0,
  `has_refrigerated_delivery` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`                TIMESTAMP    NULL DEFAULT NULL,
  `updated_at`                TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouses_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventories` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  `product_id`   BIGINT UNSIGNED NOT NULL,
  `quantity`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`   TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventories_warehouse_id_product_id_unique` (`warehouse_id`, `product_id`),
  KEY `inventories_product_id_index`   (`product_id`),
  KEY `inventories_warehouse_id_index` (`warehouse_id`),
  CONSTRAINT `inventories_product_id_foreign`
    FOREIGN KEY (`product_id`)   REFERENCES `products`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventories_warehouse_id_foreign`
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Migration bookkeeping (tells Laravel these migrations have already run)
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_000001_create_products_table',    1),
  ('2024_01_01_000002_create_warehouses_table',  1),
  ('2024_01_01_000003_create_inventories_table', 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- Products (5 rows)
-- requires_refrigeration: 1 = needs cold-chain, 0 = standard storage
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `products` (`id`, `code`, `name`, `category`, `requires_refrigeration`, `created_at`, `updated_at`) VALUES
  (1, 'P001', 'Surgical Gloves (Box of 100)',           'Medical Supplies', 0, NOW(), NOW()),
  (2, 'P002', 'Vaccine – Hepatitis B (10-dose vial)',   'Pharmaceutical',   1, NOW(), NOW()),
  (3, 'P003', 'Disposable Syringe (Box of 50)',         'Medical Supplies', 0, NOW(), NOW()),
  (4, 'P004', 'Vaccine – Tetanus Toxoid (10-dose vial)','Pharmaceutical',   1, NOW(), NOW()),
  (5, 'P005', 'Paracetamol Tablets (Box of 100)',       'Pharmaceutical',   0, NOW(), NOW());

-- ─────────────────────────────────────────────────────────────────────────────
-- Warehouses (8 rows)
-- has_cold_storage: facility can store refrigerated goods
-- has_refrigerated_delivery: has refrigerated vans for cold-chain delivery
-- A warehouse must have BOTH flags = 1 to supply refrigerated products.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `warehouses` (`id`, `code`, `city`, `distance_km`, `has_cold_storage`, `has_refrigerated_delivery`, `created_at`, `updated_at`) VALUES
  (1, 'W01', 'Surabaya',  5,   0, 0, NOW(), NOW()),
  (2, 'W02', 'Surabaya',  12,  1, 0, NOW(), NOW()),
  (3, 'W03', 'Sidoarjo',  25,  0, 0, NOW(), NOW()),
  (4, 'W04', 'Gresik',    30,  1, 1, NOW(), NOW()),
  (5, 'W05', 'Malang',    90,  0, 0, NOW(), NOW()),
  (6, 'W06', 'Malang',    95,  1, 0, NOW(), NOW()),
  (7, 'W07', 'Pasuruan',  45,  1, 1, NOW(), NOW()),
  (8, 'W08', 'Jember',    150, 1, 1, NOW(), NOW());

-- ─────────────────────────────────────────────────────────────────────────────
-- Inventory (40 rows: 8 warehouses × 5 products)
-- warehouse_id references warehouses.id, product_id references products.id
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `inventories` (`warehouse_id`, `product_id`, `quantity`, `created_at`, `updated_at`) VALUES
-- W01 (Surabaya, 5 km):    Gloves=200, HepB=0,  Syringes=500, Tetanus=0,  Paracetamol=300
  (1, 1, 200, NOW(), NOW()),
  (1, 2,   0, NOW(), NOW()),
  (1, 3, 500, NOW(), NOW()),
  (1, 4,   0, NOW(), NOW()),
  (1, 5, 300, NOW(), NOW()),
-- W02 (Surabaya, 12 km):   Gloves=50,  HepB=30, Syringes=0,   Tetanus=20, Paracetamol=0
  (2, 1,  50, NOW(), NOW()),
  (2, 2,  30, NOW(), NOW()),
  (2, 3,   0, NOW(), NOW()),
  (2, 4,  20, NOW(), NOW()),
  (2, 5,   0, NOW(), NOW()),
-- W03 (Sidoarjo, 25 km):   Gloves=300, HepB=0,  Syringes=200, Tetanus=0,  Paracetamol=0
  (3, 1, 300, NOW(), NOW()),
  (3, 2,   0, NOW(), NOW()),
  (3, 3, 200, NOW(), NOW()),
  (3, 4,   0, NOW(), NOW()),
  (3, 5,   0, NOW(), NOW()),
-- W04 (Gresik, 30 km):     Gloves=0,   HepB=40, Syringes=0,   Tetanus=25, Paracetamol=100
  (4, 1,   0, NOW(), NOW()),
  (4, 2,  40, NOW(), NOW()),
  (4, 3,   0, NOW(), NOW()),
  (4, 4,  25, NOW(), NOW()),
  (4, 5, 100, NOW(), NOW()),
-- W05 (Malang, 90 km):     Gloves=400, HepB=0,  Syringes=300, Tetanus=0,  Paracetamol=500
  (5, 1, 400, NOW(), NOW()),
  (5, 2,   0, NOW(), NOW()),
  (5, 3, 300, NOW(), NOW()),
  (5, 4,   0, NOW(), NOW()),
  (5, 5, 500, NOW(), NOW()),
-- W06 (Malang, 95 km):     Gloves=0,   HepB=20, Syringes=100, Tetanus=30, Paracetamol=0
  (6, 1,   0, NOW(), NOW()),
  (6, 2,  20, NOW(), NOW()),
  (6, 3, 100, NOW(), NOW()),
  (6, 4,  30, NOW(), NOW()),
  (6, 5,   0, NOW(), NOW()),
-- W07 (Pasuruan, 45 km):   Gloves=150, HepB=8,  Syringes=0,   Tetanus=10, Paracetamol=0
  (7, 1, 150, NOW(), NOW()),
  (7, 2,   8, NOW(), NOW()),
  (7, 3,   0, NOW(), NOW()),
  (7, 4,  10, NOW(), NOW()),
  (7, 5,   0, NOW(), NOW()),
-- W08 (Jember, 150 km):    Gloves=0,   HepB=60, Syringes=0,   Tetanus=50, Paracetamol=200
  (8, 1,   0, NOW(), NOW()),
  (8, 2,  60, NOW(), NOW()),
  (8, 3,   0, NOW(), NOW()),
  (8, 4,  50, NOW(), NOW()),
  (8, 5, 200, NOW(), NOW());

SET FOREIGN_KEY_CHECKS = 1;
