-- 003_reconcile_schema: canonical integrity (fresh clones == legacy/manual imports).
-- Order: (A) FK reconciliation, (B) coordinate backfill, (C) columns/indexes.
-- Idempotent: DROP FOREIGN KEY on missing constraint raises 1091 (ignored by migrator);
-- ADD CONSTRAINT re-run raises 1022/1061 (ignored). One statement per `;` + newline for the splitter.

-- (A) FK reconciliation: all deletes RESTRICT to preserve reports + provenance.
-- Exception: report_photos cascades when its parent report is deleted (avoids orphan files).
ALTER TABLE `reports` DROP FOREIGN KEY `reports_ibfk_1`;
ALTER TABLE `reports` DROP FOREIGN KEY `reports_ibfk_2`;
ALTER TABLE `reports` DROP FOREIGN KEY `reports_ibfk_3`;
ALTER TABLE `reports` DROP FOREIGN KEY `reports_ibfk_4`;
ALTER TABLE `users` DROP FOREIGN KEY `users_ibfk_1`;
ALTER TABLE `report_photos` DROP FOREIGN KEY `report_photos_ibfk_1`;

ALTER TABLE `reports` ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `reports` ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `reports` ADD CONSTRAINT `reports_ibfk_3` FOREIGN KEY (`hazard_type_id`) REFERENCES `hazard_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `reports` ADD CONSTRAINT `reports_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `users` ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `report_photos` ADD CONSTRAINT `report_photos_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- (B) Coordinate backfill: lives in database/seeds/001_reference_data.sql
-- (ON DUPLICATE KEY UPDATE). Not duplicated here by design; normal migrate
-- always runs seeds after migrations. --no-seed installs must run seeds once.

-- (C) Audit columns + photo metadata + query indexes.
ALTER TABLE `reports` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `reports` ADD COLUMN `reviewed_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `report_photos` ADD COLUMN `mime_type` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `report_photos` ADD COLUMN `file_size` INT NULL DEFAULT NULL;
ALTER TABLE `report_photos` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `report_photos` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0;

CREATE INDEX `idx_reports_status_submitted` ON `reports` (`status`, `submitted_at`);
CREATE INDEX `idx_reports_user_submitted` ON `reports` (`user_id`, `submitted_at`);
CREATE INDEX `idx_reports_barangay_status` ON `reports` (`barangay_id`, `status`);
CREATE INDEX `idx_reports_hazard_status` ON `reports` (`hazard_type_id`, `status`);
