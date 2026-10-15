-- Seed: reference data required by filters and submit_report.
-- Idempotent via ON DUPLICATE KEY UPDATE (seed is authority): re-runs backfill
-- coordinates on legacy rows instead of silently skipping them like INSERT IGNORE did.
INSERT INTO `barangays` (`id`, `name`, `latitude`, `longitude`) VALUES
(1, 'Bacayao Norte', 16.0321184, 120.3436590),
(2, 'Bacayao Sur', 16.0224982, 120.3460497),
(3, 'Barangay I (T. Bugallon)', 16.0440, 120.3364),
(4, 'Barangay II (Nueva)', 16.0434494, 120.3366372),
(5, 'Barangay IV (Zamora)', 16.0372683, 120.3373478),
(6, 'Bolosan', 16.0470283, 120.3653888),
(7, 'Bonuan Binloc', 16.1019298, 120.3797034),
(8, 'Bonuan Boquig', 16.0774984, 120.3580251),
(9, 'Bonuan Gueset', 16.0753807, 120.3431786),
(10, 'Calmay', 16.0452879, 120.3255690),
(11, 'Carael', 16.0318140, 120.3138628),
(12, 'Caranglaan', 16.0306481, 120.3499153),
(13, 'Herrero', 16.0439297, 120.3422628),
(14, 'Lasip Chico', 16.0216379, 120.3402722),
(15, 'Lasip Grande', 16.0286206, 120.3432008),
(16, 'Lomboy', 16.0548499, 120.3248795),
(17, 'Lucao', 16.0204862, 120.3226274),
(18, 'Malued', 16.0292908, 120.3351235),
(19, 'Mamalingling', 16.0534043, 120.3615684),
(20, 'Mangin', 16.0388765, 120.3673354),
(21, 'Mayombo', 16.0418406, 120.3463080),
(22, 'Pantal', 16.0461741, 120.3387457),
(23, 'Poblacion Oeste', 16.0432792, 120.3295612),
(24, 'Pogo Chico', 16.0386432, 120.3375088),
(25, 'Pogo Grande', 16.0325032, 120.3365188),
(26, 'Pugaro Suit', 16.0660026, 120.3237005),
(27, 'Salapingao', 16.0589914, 120.3217036),
(28, 'Salisay', 16.0423396, 120.3714122),
(29, 'Tambac', 16.0460360, 120.3563194),
(30, 'Tapuac', 16.0362403, 120.3333255),
(31, 'Tebeng', 16.0320226, 120.3588773)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `latitude`=VALUES(`latitude`), `longitude`=VALUES(`longitude`);

INSERT INTO `hazard_types` (`id`, `name`) VALUES
(1, 'Pothole'),
(2, 'Flooding'),
(3, 'Road Obstruction'),
(4, 'Road Damage'),
(5, 'Construction'),
(6, 'Landslide'),
(7, 'Accident'),
(8, 'Other')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);
