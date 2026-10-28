ALTER TABLE `{PREFIX}appointment_types`
  ADD COLUMN `clean_minutes` smallint unsigned NOT NULL DEFAULT '0' AFTER `duration_minutes`;
