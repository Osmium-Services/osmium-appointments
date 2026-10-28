ALTER TABLE `{PREFIX}appointment_resources`
  ADD COLUMN `break_start` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `enabled`,
  ADD COLUMN `break_end` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `break_start`;
