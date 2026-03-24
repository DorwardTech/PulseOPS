-- ================================================================
-- PulseOPS Seed Data
-- Version 3.0.0 - Single Tenant (NT Amusements)
-- ================================================================

-- Default settings for NT Amusements
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `description`) VALUES
('company_name', 'NT Amusements', 'string', 'general', 'Company name'),
('company_email', '', 'string', 'general', 'Company email address'),
('company_phone', '', 'string', 'general', 'Company phone number'),
('company_address', '', 'string', 'general', 'Company address'),
('company_logo', '', 'string', 'general', 'Company logo path'),
('timezone', 'Australia/Darwin', 'string', 'general', 'Application timezone'),
('currency_symbol', '$', 'string', 'general', 'Currency symbol'),
('currency_code', 'AUD', 'string', 'general', 'Currency code'),
('default_commission_rate', '30.00', 'number', 'commission', 'Default commission rate percentage'),
('default_processing_fee', '0.30', 'number', 'commission', 'Default per-transaction processing fee'),
('labour_hourly_rate', '80.00', 'number', 'commission', 'Default labour hourly rate'),
('labour_increment_minutes', '15', 'number', 'commission', 'Labour time rounding increment in minutes'),
('nayax_enabled', 'false', 'boolean', 'nayax', 'Enable Nayax integration'),
('nayax_api_token', '', 'string', 'nayax', 'Nayax API authentication token'),
('nayax_operator_id', '', 'string', 'nayax', 'Nayax operator ID'),
('nayax_environment', 'production', 'string', 'nayax', 'Nayax API environment (qa/production)'),
('nayax_api_url', 'https://lynx.nayax.com', 'string', 'nayax', 'Nayax API base URL'),
('nayax_cash_counting_enabled', 'false', 'boolean', 'nayax', 'Enable Nayax cash counting globally'),
('cash_primary_method', 'manual', 'string', 'revenue', 'Primary cash handling method'),
('revenue_aggregation_hours', '4', 'number', 'revenue', 'Nayax revenue aggregation interval in hours'),
('smtp_host', '', 'string', 'email', 'SMTP server hostname'),
('smtp_port', '587', 'number', 'email', 'SMTP server port'),
('smtp_username', '', 'string', 'email', 'SMTP username'),
('smtp_password', '', 'string', 'email', 'SMTP password'),
('smtp_from_email', '', 'string', 'email', 'SMTP from email address'),
('smtp_from_name', 'PulseOPS', 'string', 'email', 'SMTP from name');

-- Default admin user (password: changeme)
-- Using bcrypt for maximum compatibility; app upgrades to argon2id on next login
INSERT INTO `users` (`role_id`, `email`, `password`, `full_name`, `is_active`) VALUES
(1, 'admin@ntamusements.com.au', '$2y$12$HP6U8b9YZmvqXMUlvesRROpBAo4lt6.FSw6LrXVuuBhFKqc9V.9xG', 'System Admin', TRUE);

-- Default job statuses
INSERT INTO `job_statuses` (`name`, `slug`, `color`, `is_default`, `is_completed`, `sort_order`) VALUES
('Open', 'open', '#0d6efd', TRUE, FALSE, 1),
('In Progress', 'in_progress', '#ffc107', FALSE, FALSE, 2),
('Waiting Parts', 'waiting_parts', '#fd7e14', FALSE, FALSE, 3),
('Completed', 'completed', '#198754', FALSE, TRUE, 4),
('Cancelled', 'cancelled', '#dc3545', FALSE, FALSE, 5);

-- Default machine types
INSERT INTO `machine_types` (`name`, `description`, `icon`, `is_active`) VALUES
('Crane Machine', 'Claw crane/UFO catcher machines', 'crane', TRUE),
('Arcade Game', 'General arcade game machines', 'arcade', TRUE),
('Massage Chair', 'Coin-operated massage chairs', 'chair', TRUE),
('Ride', 'Coin-operated kiddie rides', 'ride', TRUE),
('Vending', 'Vending machines', 'vending', TRUE),
('Other', 'Other machine types', 'other', TRUE);
