-- Mini Project 3: Inventory Management System
-- Jalankan: mysql -u root -p < database/schema.sql   (MySQL 8.0.16+ / MariaDB 10.2+)

CREATE DATABASE IF NOT EXISTS inventory_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_db;

DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS product_suppliers;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS user_profiles;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------
-- users (+ one-to-one ke user_profiles)
-- ---------------------------------------------------------------
CREATE TABLE users (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ONE-TO-ONE: FK + UNIQUE
CREATE TABLE user_profiles (
    id        INT PRIMARY KEY AUTO_INCREMENT,
    user_id   INT NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    phone     VARCHAR(30) NULL,
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- categories -> products  (ONE-TO-MANY)
-- ---------------------------------------------------------------
CREATE TABLE categories (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE products (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    category_id   INT NOT NULL,
    sku           VARCHAR(50)  NOT NULL UNIQUE,            -- Business rule: SKU unik
    name          VARCHAR(150) NOT NULL,
    price         DECIMAL(12,2) NOT NULL,
    stock         INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 5,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_price_nonneg CHECK (price >= 0),        -- harga tidak negatif
    CONSTRAINT chk_stock_nonneg CHECK (stock >= 0),        -- stok tidak negatif
    CONSTRAINT chk_minstock_nonneg CHECK (minimum_stock >= 0),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- suppliers <-> products  (MANY-TO-MANY via junction table)
-- ---------------------------------------------------------------
CREATE TABLE suppliers (
    id      INT PRIMARY KEY AUTO_INCREMENT,
    name    VARCHAR(150) NOT NULL,
    phone   VARCHAR(30)  NULL,
    address VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE product_suppliers (
    product_id     INT NOT NULL,
    supplier_id    INT NOT NULL,
    supplier_price DECIMAL(12,2) NULL,
    PRIMARY KEY (product_id, supplier_id),                 -- composite PK: pasangan tidak duplikat
    CONSTRAINT chk_supplier_price CHECK (supplier_price IS NULL OR supplier_price >= 0),
    CONSTRAINT fk_ps_product  FOREIGN KEY (product_id)  REFERENCES products(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ps_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- stock_movements (histori barang masuk / keluar)
-- RESTRICT: produk / user yang punya histori tidak bisa dihapus sembarangan
-- ---------------------------------------------------------------
CREATE TABLE stock_movements (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    user_id    INT NOT NULL,
    type       ENUM('IN','OUT') NOT NULL,
    quantity   INT NOT NULL,
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_qty_positive CHECK (quantity > 0),
    CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_sm_product (product_id),
    INDEX idx_sm_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- DATA CONTOH
-- Login: admin / admin123   |   staff / staff123
-- ---------------------------------------------------------------
INSERT INTO users (username, password_hash, role) VALUES
('admin', '$2y$10$9hMh8ffWfP/sSQyf81ernuuzAs1wv9UcJstdFbfnKUffpfPQw5Z7a', 'admin'),
('staff', '$2y$10$4nNkE/8R9TmeC.SX3ZVULev6ouiIQ6rgsslyDppbgcysAHdgQkhxC', 'staff');

INSERT INTO user_profiles (user_id, full_name, phone) VALUES
(1, 'Administrator Toko', '081200000001'),
(2, 'Staff Gudang', '081200000002');

INSERT INTO categories (name, description) VALUES
('Elektronik', 'Perangkat elektronik dan aksesoris'),
('Alat Tulis', 'Perlengkapan kantor dan sekolah'),
('Minuman', 'Minuman kemasan');

INSERT INTO suppliers (name, phone, address) VALUES
('PT Maju Jaya', '021-555-0101', 'Jakarta'),
('CV Sumber Rezeki', '061-555-0202', 'Medan'),
('UD Berkah', '0645-555-0303', 'Lhokseumawe');

INSERT INTO products (category_id, sku, name, price, stock, minimum_stock) VALUES
(1, 'ELK-001', 'Keyboard Mechanical', 350000, 0, 5),
(1, 'ELK-002', 'Mouse Wireless', 120000, 0, 5),
(2, 'ATK-001', 'Pulpen Hitam (box)', 45000, 0, 10),
(2, 'ATK-002', 'Buku Tulis A5', 8000, 0, 20),
(3, 'MNM-001', 'Air Mineral 600ml', 3500, 0, 24);

INSERT INTO product_suppliers (product_id, supplier_id, supplier_price) VALUES
(1, 1, 300000), (1, 2, 310000), (2, 1, 100000),
(3, 2, 38000), (4, 3, 6000), (5, 3, 2800);

-- Stok awal dicatat sebagai histori barang masuk, sehingga
-- stok akhir = stok awal + total masuk - total keluar selalu bisa diaudit.
INSERT INTO stock_movements (product_id, user_id, type, quantity, note) VALUES
(1, 1, 'IN', 10, 'Stok awal'),
(2, 1, 'IN', 3,  'Stok awal'),
(3, 1, 'IN', 50, 'Stok awal'),
(4, 1, 'IN', 100,'Stok awal'),
(5, 1, 'IN', 120,'Stok awal'),
(5, 2, 'OUT', 30,'Penjualan'),
(1, 2, 'OUT', 2, 'Penjualan');

UPDATE products SET stock = 8   WHERE id = 1;
UPDATE products SET stock = 3   WHERE id = 2;
UPDATE products SET stock = 50  WHERE id = 3;
UPDATE products SET stock = 100 WHERE id = 4;
UPDATE products SET stock = 90  WHERE id = 5;
