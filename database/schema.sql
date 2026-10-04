CREATE DATABASE IF NOT EXISTS company_card
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE company_card;

-- ============================================================
-- USERS
-- ============================================================

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('ADMIN', 'STORE_OPERATOR', 'EMPLOYEE') NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================================================
-- EMPLOYEES
-- ============================================================

CREATE TABLE employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    employee_code VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_employees_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- CARDS
-- ============================================================

CREATE TABLE cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    card_reference VARCHAR(50) NOT NULL UNIQUE,
    credential_hash CHAR(64) NOT NULL UNIQUE,
    status ENUM('ACTIVE', 'SUSPENDED', 'REVOKED', 'EXPIRED')
        NOT NULL DEFAULT 'ACTIVE',
    issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_cards_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- WALLETS
-- ============================================================

CREATE TABLE wallets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL UNIQUE,

    -- Cached balance.
    -- It may only be changed by authorized financial operations.
    balance DECIMAL(15, 2) NOT NULL DEFAULT 0.00,

    currency CHAR(3) NOT NULL DEFAULT 'BDT',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_wallet_balance
        CHECK (balance >= 0),

    CONSTRAINT fk_wallets_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- STORES
-- ============================================================

CREATE TABLE stores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================================================
-- PRODUCTS
-- ============================================================

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    description VARCHAR(500) NULL,
    price DECIMAL(15, 2) NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_product_price
        CHECK (price >= 0),

    CONSTRAINT fk_products_store
        FOREIGN KEY (store_id)
        REFERENCES stores(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_products_store (store_id),
    INDEX idx_products_active (is_active)
) ENGINE=InnoDB;


-- ============================================================
-- TRANSACTIONS
-- ============================================================

CREATE TABLE transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    transaction_reference VARCHAR(50) NOT NULL UNIQUE,

    wallet_id BIGINT UNSIGNED NOT NULL,

    type ENUM(
        'TOP_UP',
        'PURCHASE',
        'REFUND',
        'ADJUSTMENT'
    ) NOT NULL,

    status ENUM(
        'PENDING',
        'COMPLETED',
        'FAILED',
        'REVERSED'
    ) NOT NULL DEFAULT 'PENDING',

    amount DECIMAL(15, 2) NOT NULL,

    created_by BIGINT UNSIGNED NULL,

    idempotency_key VARCHAR(100) NULL UNIQUE,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,

    CONSTRAINT chk_transaction_amount
        CHECK (amount > 0),

    CONSTRAINT fk_transactions_wallet
        FOREIGN KEY (wallet_id)
        REFERENCES wallets(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_transactions_creator
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_transactions_wallet (wallet_id),
    INDEX idx_transactions_type (type),
    INDEX idx_transactions_status (status),
    INDEX idx_transactions_created_at (created_at)
) ENGINE=InnoDB;


-- ============================================================
-- LEDGER ENTRIES
-- ============================================================

CREATE TABLE ledger_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    transaction_id BIGINT UNSIGNED NOT NULL,
    wallet_id BIGINT UNSIGNED NOT NULL,

    entry_type ENUM('CREDIT', 'DEBIT') NOT NULL,

    amount DECIMAL(15, 2) NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_ledger_amount
        CHECK (amount > 0),

    CONSTRAINT fk_ledger_transaction
        FOREIGN KEY (transaction_id)
        REFERENCES transactions(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_ledger_wallet
        FOREIGN KEY (wallet_id)
        REFERENCES wallets(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_ledger_wallet (wallet_id),
    INDEX idx_ledger_transaction (transaction_id),
    INDEX idx_ledger_created_at (created_at)
) ENGINE=InnoDB;


-- ============================================================
-- PURCHASES
-- ============================================================

CREATE TABLE purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
    store_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    processed_by BIGINT UNSIGNED NOT NULL,

    total_amount DECIMAL(15, 2) NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_purchase_amount
        CHECK (total_amount > 0),

    CONSTRAINT fk_purchases_transaction
        FOREIGN KEY (transaction_id)
        REFERENCES transactions(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_purchases_store
        FOREIGN KEY (store_id)
        REFERENCES stores(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_purchases_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_purchases_operator
        FOREIGN KEY (processed_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_purchases_employee (employee_id),
    INDEX idx_purchases_store (store_id),
    INDEX idx_purchases_created_at (created_at)
) ENGINE=InnoDB;


-- ============================================================
-- PURCHASE ITEMS
-- ============================================================

CREATE TABLE purchase_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    purchase_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,

    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(15, 2) NOT NULL,
    subtotal DECIMAL(15, 2) NOT NULL,

    CONSTRAINT chk_purchase_item_quantity
        CHECK (quantity > 0),

    CONSTRAINT chk_purchase_item_price
        CHECK (unit_price >= 0),

    CONSTRAINT chk_purchase_item_subtotal
        CHECK (subtotal >= 0),

    CONSTRAINT fk_purchase_items_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES purchases(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_purchase_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_purchase_items_purchase (purchase_id),
    INDEX idx_purchase_items_product (product_id)
) ENGINE=InnoDB;


-- ============================================================
-- AUDIT LOGS
-- ============================================================

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,

    ip_address VARCHAR(45) NULL,

    metadata JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_audit_logs_user (user_id),
    INDEX idx_audit_logs_entity (entity_type, entity_id),
    INDEX idx_audit_logs_created_at (created_at)
) ENGINE=InnoDB;