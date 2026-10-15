-- 002_add_coordinates: repairs legacy DBs created before lat/lng columns existed.
-- Safe to re-run: duplicate-column error 1060 is ignored by the migrator.
-- Uses plain ADD COLUMN (no IF NOT EXISTS) for MySQL + MariaDB compatibility.

ALTER TABLE `barangays` ADD COLUMN `latitude` DECIMAL(10,7) NULL;
ALTER TABLE `barangays` ADD COLUMN `longitude` DECIMAL(10,7) NULL;
ALTER TABLE `reports` ADD COLUMN `latitude` DECIMAL(10,8) NULL DEFAULT NULL AFTER `landmark`;
ALTER TABLE `reports` ADD COLUMN `longitude` DECIMAL(11,8) NULL DEFAULT NULL AFTER `latitude`;
