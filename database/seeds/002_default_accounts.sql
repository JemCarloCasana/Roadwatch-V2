-- Seed: default accounts for fresh clones. INSERT IGNORE so re-runs never
-- overwrite credentials (passwords must not reset on every migrate).
-- must_change_password=1 forces rotation after first login (see Task 5 warning).
-- Passwords (change after first login):
--   superadmin@roadwatch.local / SuperAdmin123!
--   admin@roadwatch.local      / Admin12345
--   user@roadwatch.local       / User12345
-- Security answer for all three: RoadWatch
INSERT IGNORE INTO `users` (`id`, `full_name`, `email`, `password_hash`, `security_question`, `security_answer`, `role`, `is_active`, `created_by`, `must_change_password`) VALUES
(1, 'RoadWatch Super Admin', 'superadmin@roadwatch.local', '$2y$10$wMinhrmA7RQJdBc56Afn4uFoHxnqnhIQR8TBKGb8sxWqqETECvXCO', 'What is the system name?', '$2y$10$u.75hXVrwXbXO9h/NTBKv.pqsMcMZzJ313kK1/jsijSAgvOIM724C', 'super_admin', 1, NULL, 1),
(2, 'RoadWatch Admin', 'admin@roadwatch.local', '$2y$10$w8.gD0KUtw9OPbgopTLypOWnCu0aMNUldm5aQFoIIQvCEV7h96aHC', 'What is the system name?', '$2y$10$u.75hXVrwXbXO9h/NTBKv.pqsMcMZzJ313kK1/jsijSAgvOIM724C', 'admin', 1, 1, 1),
(3, 'RoadWatch Test User', 'user@roadwatch.local', '$2y$10$amm7fMSWIw5h8z8iqfJTo.57WIHJKJjo7hBs1SHTItf4xG0tdekdm', 'What is the system name?', '$2y$10$u.75hXVrwXbXO9h/NTBKv.pqsMcMZzJ313kK1/jsijSAgvOIM724C', 'user', 1, 1, 1);
