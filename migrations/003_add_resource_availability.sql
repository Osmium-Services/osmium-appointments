ALTER TABLE `{PREFIX}appointment_resources`
  ADD COLUMN `weekly` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `break_end`,
  ADD COLUMN `time_off` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `weekly`;
