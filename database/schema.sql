-- ================================================================
-- PulseOPS Database Schema
-- Version 3.0.0 - Single Tenant (NT Amusements)
-- Consolidated schema for fresh installations
-- ================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ================================================================
-- CORE TABLES
-- ================================================================

CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL,
    `slug` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_system` BOOLEAN DEFAULT FALSE,
    `permissions` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id` INT UNSIGNED NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `login_attempts` INT UNSIGNED DEFAULT 0,
    `locked_until` TIMESTAMP NULL DEFAULT NULL,
    `remember_token` VARCHAR(100) DEFAULT NULL,
    `preferences` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_email` (`email`),
    INDEX `idx_role` (`role_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- CUSTOMER MANAGEMENT
-- ================================================================

CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `business_name` VARCHAR(255) DEFAULT NULL,
    `contact_name` VARCHAR(100) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `mobile` VARCHAR(50) DEFAULT NULL,
    `address_line1` VARCHAR(255) DEFAULT NULL,
    `address_line2` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `state` VARCHAR(50) DEFAULT NULL,
    `postcode` VARCHAR(20) DEFAULT NULL,
    `country` VARCHAR(50) DEFAULT 'Australia',
    `abn` VARCHAR(20) DEFAULT NULL,
    -- Commission settings
    `commission_rate` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Commission percentage (e.g., 30.00)',
    `processing_fee` DECIMAL(10,2) DEFAULT NULL COMMENT 'Per-txn fee, NULL=use system default',
    `carry_forward` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Carried from previous period (negative = they owe)',
    -- Payment details
    `payment_terms` VARCHAR(50) DEFAULT 'Monthly',
    `payment_method` VARCHAR(50) DEFAULT 'Bank Transfer',
    `bank_name` VARCHAR(100) DEFAULT NULL,
    `bank_account_name` VARCHAR(100) DEFAULT NULL,
    `bank_bsb` VARCHAR(20) DEFAULT NULL,
    `bank_account_number` VARCHAR(50) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commission rate/processing fee change history
CREATE TABLE IF NOT EXISTS `commission_rate_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` INT UNSIGNED NOT NULL,
    `field_changed` ENUM('commission_rate', 'processing_fee') NOT NULL,
    `old_value` DECIMAL(10,2) DEFAULT NULL,
    `new_value` DECIMAL(10,2) NOT NULL,
    `effective_from` DATE NOT NULL,
    `reason` TEXT DEFAULT NULL,
    `changed_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_customer_date` (`customer_id`, `effective_from`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_portal_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` INT UNSIGNED NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `is_primary` BOOLEAN DEFAULT FALSE,
    `is_active` BOOLEAN DEFAULT TRUE,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `remember_token` VARCHAR(100) DEFAULT NULL,
    `password_reset_token` VARCHAR(100) DEFAULT NULL,
    `password_reset_expires` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_email` (`email`),
    INDEX `idx_customer` (`customer_id`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_portal_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portal_user_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user` (`portal_user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_date` (`created_at`),
    FOREIGN KEY (`portal_user_id`) REFERENCES `customer_portal_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- MACHINE MANAGEMENT
-- ================================================================

CREATE TABLE IF NOT EXISTS `machine_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `icon` VARCHAR(50) DEFAULT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `machines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` INT UNSIGNED DEFAULT NULL,
    `machine_type_id` INT UNSIGNED DEFAULT NULL,
    `machine_code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `location_details` TEXT DEFAULT NULL,
    `nayax_device_id` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('active', 'maintenance', 'inactive', 'in_storage') DEFAULT 'active',
    `commission_rate` DECIMAL(5,2) DEFAULT NULL COMMENT 'Override customer rate if set',
    `installation_date` DATE DEFAULT NULL,
    `last_service_date` DATE DEFAULT NULL,
    `next_service_date` DATE DEFAULT NULL,
    `serial_number` VARCHAR(100) DEFAULT NULL,
    `manufacturer` VARCHAR(100) DEFAULT NULL,
    `model` VARCHAR(100) DEFAULT NULL,
    `purchase_date` DATE DEFAULT NULL,
    `purchase_price` DECIMAL(10,2) DEFAULT NULL,
    `warranty_expiry` DATE DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `nayax_cash_counting` BOOLEAN DEFAULT FALSE,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_machine_code` (`machine_code`),
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_type` (`machine_type_id`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`machine_type_id`) REFERENCES `machine_types`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `machine_photos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) DEFAULT NULL,
    `original_name` VARCHAR(255) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `is_primary` BOOLEAN DEFAULT FALSE,
    `description` TEXT DEFAULT NULL,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_machine` (`machine_id`),
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collection_schedules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `schedule_type` ENUM('weekly', 'fortnightly', 'monthly', 'custom') NOT NULL DEFAULT 'monthly',
    `day_of_week` TINYINT UNSIGNED DEFAULT NULL,
    `day_of_month` TINYINT UNSIGNED DEFAULT NULL,
    `next_collection_date` DATE DEFAULT NULL,
    `last_collection_date` DATE DEFAULT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_machine` (`machine_id`),
    INDEX `idx_next_date` (`next_collection_date`),
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- JOB/MAINTENANCE MANAGEMENT
-- ================================================================

CREATE TABLE IF NOT EXISTS `job_statuses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL,
    `slug` VARCHAR(50) NOT NULL,
    `color` VARCHAR(7) DEFAULT '#6c757d',
    `is_default` BOOLEAN DEFAULT FALSE,
    `is_completed` BOOLEAN DEFAULT FALSE,
    `sort_order` INT UNSIGNED DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maintenance_jobs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `job_number` VARCHAR(20) DEFAULT NULL,
    `status_id` INT UNSIGNED DEFAULT NULL,
    `job_type` ENUM('routine', 'repair', 'installation', 'removal', 'inspection', 'emergency', 'stock_fill') NOT NULL DEFAULT 'repair',
    `priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `reported_by` VARCHAR(100) DEFAULT NULL,
    `reported_by_customer` INT UNSIGNED DEFAULT NULL,
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `scheduled_date` DATE DEFAULT NULL,
    `scheduled_time` TIME DEFAULT NULL,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `estimated_duration` INT UNSIGNED DEFAULT NULL COMMENT 'Minutes',
    `actual_duration` INT UNSIGNED DEFAULT NULL COMMENT 'Minutes',
    -- Labour tracking
    `labour_minutes` INT UNSIGNED DEFAULT 0 COMMENT 'Total labour time in minutes',
    `labour_rate` DECIMAL(10,2) DEFAULT NULL COMMENT 'Hourly rate used (snapshot)',
    `labour_cost` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Calculated: labour_minutes / 60 * labour_rate',
    -- Parts tracking
    `parts_cost` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total parts cost (sum of job_parts)',
    `total_cost` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'labour_cost + parts_cost',
    -- Visibility
    `is_customer_visible` BOOLEAN DEFAULT TRUE,
    `resolution` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_machine` (`machine_id`),
    INDEX `idx_status` (`status_id`),
    INDEX `idx_assigned` (`assigned_to`),
    INDEX `idx_scheduled` (`scheduled_date`),
    INDEX `idx_completed` (`completed_at`),
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`status_id`) REFERENCES `job_statuses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`reported_by_customer`) REFERENCES `customer_portal_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_photos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) DEFAULT NULL,
    `original_name` VARCHAR(255) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `photo_type` ENUM('before', 'during', 'after', 'damage', 'parts', 'other') DEFAULT 'other',
    `description` TEXT DEFAULT NULL,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    FOREIGN KEY (`job_id`) REFERENCES `maintenance_jobs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `portal_user_id` INT UNSIGNED DEFAULT NULL,
    `note` TEXT NOT NULL,
    `time_minutes` INT UNSIGNED DEFAULT 0,
    `is_billable` BOOLEAN DEFAULT FALSE,
    `is_internal` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    FOREIGN KEY (`job_id`) REFERENCES `maintenance_jobs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`portal_user_id`) REFERENCES `customer_portal_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parts used on jobs
CREATE TABLE IF NOT EXISTS `job_parts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `part_name` VARCHAR(255) NOT NULL,
    `part_number` VARCHAR(100) DEFAULT NULL,
    `quantity` INT UNSIGNED DEFAULT 1,
    `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_cost` DECIMAL(10,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    FOREIGN KEY (`job_id`) REFERENCES `maintenance_jobs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- MAINTENANCE SCHEDULE (Separate from Jobs)
-- ================================================================

CREATE TABLE IF NOT EXISTS `maintenance_schedules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `maintenance_type` ENUM('preventive', 'cleaning', 'inspection', 'calibration', 'other') DEFAULT 'preventive',
    `frequency` ENUM('weekly', 'fortnightly', 'monthly', 'quarterly', 'yearly', 'custom') DEFAULT 'monthly',
    `last_performed` DATE DEFAULT NULL,
    `next_due` DATE DEFAULT NULL,
    -- Labour tracking
    `labour_minutes` INT UNSIGNED DEFAULT 0,
    `labour_rate` DECIMAL(10,2) DEFAULT NULL,
    `labour_cost` DECIMAL(10,2) DEFAULT 0.00,
    `parts_cost` DECIMAL(10,2) DEFAULT 0.00,
    `total_cost` DECIMAL(10,2) DEFAULT 0.00,
    `notes` TEXT DEFAULT NULL,
    `performed_by` INT UNSIGNED DEFAULT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_machine` (`machine_id`),
    INDEX `idx_next_due` (`next_due`),
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parts used on maintenance
CREATE TABLE IF NOT EXISTS `maintenance_parts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `maintenance_id` INT UNSIGNED NOT NULL,
    `part_name` VARCHAR(255) NOT NULL,
    `part_number` VARCHAR(100) DEFAULT NULL,
    `quantity` INT UNSIGNED DEFAULT 1,
    `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_cost` DECIMAL(10,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_maintenance` (`maintenance_id`),
    FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_schedules`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- REVENUE & COLLECTIONS
-- ================================================================

CREATE TABLE IF NOT EXISTS `revenue` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `collection_date` DATE NOT NULL,

    -- Aggregation period (for Nayax records)
    `period_start` DATETIME DEFAULT NULL,
    `period_end` DATETIME DEFAULT NULL,

    -- RAW AMOUNTS (store only actual values)
    `cash_amount` DECIMAL(10,2) DEFAULT 0.00,
    `card_amount` DECIMAL(10,2) DEFAULT 0.00,
    `prepaid_amount` DECIMAL(10,2) DEFAULT 0.00,

    -- TRANSACTION COUNTS (for fee calculation)
    `card_transactions` INT UNSIGNED DEFAULT 0,
    `prepaid_transactions` INT UNSIGNED DEFAULT 0,

    -- CASH SOURCE
    `cash_source` ENUM('manual', 'nayax') DEFAULT 'manual',

    -- SOURCE TRACKING
    `source` ENUM('manual', 'nayax', 'import') DEFAULT 'manual',
    `nayax_transaction_ids` JSON DEFAULT NULL COMMENT 'Array of transaction IDs if aggregated',

    -- WORKFLOW
    `status` ENUM('draft', 'approved', 'rejected') DEFAULT 'approved',
    `collected_by` INT UNSIGNED DEFAULT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,

    -- TIMESTAMPS
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_machine_date` (`machine_id`, `collection_date`),
    INDEX `idx_date` (`collection_date`),
    INDEX `idx_source` (`source`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`collected_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- COMMISSION PAYMENTS
-- ================================================================

CREATE TABLE IF NOT EXISTS `commission_payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` INT UNSIGNED NOT NULL,

    -- Period
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `period_label` VARCHAR(100) DEFAULT NULL COMMENT 'e.g., January 2026',

    -- RAW TOTALS (from revenue records)
    `total_cash` DECIMAL(10,2) DEFAULT 0.00,
    `total_card` DECIMAL(10,2) DEFAULT 0.00,
    `total_prepaid` DECIMAL(10,2) DEFAULT 0.00,
    `total_card_transactions` INT UNSIGNED DEFAULT 0,

    -- RATES (snapshot at generation time)
    `commission_rate` DECIMAL(5,2) NOT NULL,
    `processing_fee_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.30,
    `labour_hourly_rate` DECIMAL(10,2) DEFAULT 0.00,

    -- DEDUCTIONS (from jobs & maintenance in period)
    `total_parts_cost` DECIMAL(10,2) DEFAULT 0.00,
    `total_labour_minutes` INT UNSIGNED DEFAULT 0,
    `total_labour_cost` DECIMAL(10,2) DEFAULT 0.00,

    -- CARRY FORWARD
    `carry_forward_in` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'From previous period (negative = they owe)',
    `carry_forward_out` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'To next period (if this period negative)',

    -- CALCULATED VALUES (stored for audit/historical)
    `gross_revenue` DECIMAL(10,2) NOT NULL COMMENT 'cash + card (excluding prepaid)',
    `processing_fees` DECIMAL(10,2) NOT NULL COMMENT 'card_transactions x fee_rate',
    `net_revenue` DECIMAL(10,2) NOT NULL COMMENT 'gross - transaction_fees - parts_deduction - labour_deduction',
    `parts_deduction` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'parts_cost x commission_rate',
    `labour_deduction` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'labour_cost x commission_rate',
    `adjustments_total` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Sum of line items',
    `commission_calculated` DECIMAL(10,2) NOT NULL COMMENT 'net_revenue x commission_rate + adjustments + carry_forward',
    `commission_amount` DECIMAL(10,2) NOT NULL COMMENT 'Final payable (min $0)',

    -- WORKFLOW
    `status` ENUM('draft', 'approved', 'paid', 'void') DEFAULT 'draft',
    `generated_by` INT UNSIGNED DEFAULT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `paid_at` DATETIME DEFAULT NULL,
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `payment_reference` VARCHAR(255) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,

    -- TIMESTAMPS
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_customer_period` (`customer_id`, `period_start`, `period_end`),
    INDEX `idx_status` (`status`),
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_period` (`period_start`, `period_end`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual adjustments / line items on commission
CREATE TABLE IF NOT EXISTS `commission_line_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `commission_id` INT UNSIGNED NOT NULL,

    -- Item Details
    `type` ENUM('adjustment', 'bonus', 'deduction', 'credit', 'refund', 'other') NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL COMMENT 'Positive=add to commission, Negative=deduct',

    -- Reference (optional link to job/maintenance)
    `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'job, maintenance, invoice',
    `reference_id` INT UNSIGNED DEFAULT NULL,

    -- REQUEST WORKFLOW
    `requested_by_type` ENUM('admin', 'customer') DEFAULT 'admin',
    `requested_by` INT UNSIGNED DEFAULT NULL,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `request_notes` TEXT DEFAULT NULL,

    -- APPROVAL
    `status` ENUM('pending', 'approved', 'declined') DEFAULT 'approved',
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `review_notes` TEXT DEFAULT NULL,

    -- TIMESTAMPS
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_commission` (`commission_id`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`commission_id`) REFERENCES `commission_payments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- NAYAX INTEGRATION
-- ================================================================

CREATE TABLE IF NOT EXISTS `nayax_devices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` VARCHAR(100) NOT NULL,
    `device_name` VARCHAR(255) DEFAULT NULL,
    `device_serial` VARCHAR(100) DEFAULT NULL,
    `device_status` VARCHAR(50) DEFAULT 'unknown',
    `nayax_device_id` VARCHAR(100) DEFAULT NULL,
    `device_model` VARCHAR(100) DEFAULT NULL,
    `firmware_version` VARCHAR(100) DEFAULT NULL,
    `smart_sticker_id` VARCHAR(100) DEFAULT NULL,
    `vpos_id` VARCHAR(100) DEFAULT NULL,
    `cash_box_id` VARCHAR(100) DEFAULT NULL,
    `latitude` DECIMAL(10,7) DEFAULT NULL,
    `longitude` DECIMAL(10,7) DEFAULT NULL,
    `last_communication` DATETIME DEFAULT NULL,
    `last_sync_at` DATETIME DEFAULT NULL,
    `machine_id` INT UNSIGNED DEFAULT NULL,
    `last_transaction` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_device` (`device_id`),
    INDEX `idx_machine` (`machine_id`),
    INDEX `idx_last_sync` (`last_sync_at`),
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nayax_transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_id` VARCHAR(100) NOT NULL,
    `device_id` VARCHAR(100) NOT NULL,
    `transaction_date` DATETIME NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_type` VARCHAR(50) DEFAULT 'card',
    `status` VARCHAR(50) DEFAULT 'completed',
    `raw_data` JSON DEFAULT NULL,
    `is_aggregated` BOOLEAN DEFAULT FALSE COMMENT 'Has been aggregated into revenue',
    `aggregated_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_txn` (`transaction_id`),
    INDEX `idx_device` (`device_id`),
    INDEX `idx_date` (`transaction_date`),
    INDEX `idx_aggregated` (`is_aggregated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nayax_imports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_type` VARCHAR(50) DEFAULT 'manual',
    `transactions_imported` INT UNSIGNED DEFAULT 0,
    `transactions_skipped` INT UNSIGNED DEFAULT 0,
    `transactions_error` INT UNSIGNED DEFAULT 0,
    `import_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `date_from` DATE NOT NULL,
    `date_to` DATE NOT NULL,
    `records_imported` INT UNSIGNED DEFAULT 0,
    `records_skipped` INT UNSIGNED DEFAULT 0,
    `records_failed` INT UNSIGNED DEFAULT 0,
    `status` ENUM('success', 'partial', 'failed') DEFAULT 'success',
    `error_message` TEXT DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `imported_by` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_date` (`import_date`),
    FOREIGN KEY (`imported_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nayax_api_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `method` VARCHAR(10) NOT NULL,
    `endpoint` VARCHAR(255) NOT NULL,
    `request_data` JSON DEFAULT NULL,
    `response_data` JSON DEFAULT NULL,
    `status_code` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import Jobs Queue (for long-running background imports)
CREATE TABLE IF NOT EXISTS `import_jobs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `job_type` VARCHAR(50) NOT NULL DEFAULT 'nayax_transactions',
    `status` ENUM('queued', 'processing', 'completed', 'failed') DEFAULT 'queued',
    `parameters` JSON DEFAULT NULL,
    `progress` INT UNSIGNED DEFAULT 0,
    `total` INT UNSIGNED DEFAULT 0,
    `progress_message` VARCHAR(255) DEFAULT NULL,
    `result` JSON DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- NOTES & ACTIVITY
-- ================================================================

CREATE TABLE IF NOT EXISTS `notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` ENUM('machine', 'customer', 'job', 'revenue', 'collection', 'commission') NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `note` TEXT NOT NULL,
    `priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    `is_pinned` BOOLEAN DEFAULT FALSE,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `portal_user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) DEFAULT NULL,
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `old_values` JSON DEFAULT NULL,
    `new_values` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- SETTINGS
-- ================================================================

CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` ENUM('string','number','boolean','json') DEFAULT 'string',
    `category` VARCHAR(50) DEFAULT 'general',
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- DEFAULT DATA
-- ================================================================

INSERT INTO `roles` (`name`, `slug`, `description`, `is_system`, `permissions`) VALUES
('Admin', 'admin', 'Full system access', TRUE, '["*"]'),
('Manager', 'manager', 'Manager access', TRUE, '["machines.*","customers.*","jobs.*","revenue.*","commissions.*","analytics.*","nayax.*"]'),
('Technician', 'technician', 'Technician access', TRUE, '["machines.view","jobs.*"]'),
('Collector', 'collector', 'Collector access', TRUE, '["machines.view","revenue.*"]'),
('Viewer', 'viewer', 'Read-only access', TRUE, '["*.view"]');

-- ================================================================
-- END OF SCHEMA
-- ================================================================
