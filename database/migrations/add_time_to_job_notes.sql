-- Add time tracking and billable flag to job_notes
ALTER TABLE `job_notes`
    ADD COLUMN `time_minutes` INT UNSIGNED DEFAULT 0 AFTER `note`,
    ADD COLUMN `is_billable` BOOLEAN DEFAULT FALSE AFTER `time_minutes`;
