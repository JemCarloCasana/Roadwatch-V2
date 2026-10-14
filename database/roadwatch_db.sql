-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 24, 2026 at 02:24 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `roadwatch_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `latitude` DECIMAL(10, 7) NULL,
  `longitude` DECIMAL(10, 7) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

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
(31, 'Tebeng', 16.0320226, 120.3588773);

-- --------------------------------------------------------

--
-- Table structure for table `hazard_types`
--

CREATE TABLE `hazard_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hazard_types`
--

INSERT INTO `hazard_types` (`id`, `name`) VALUES
(7, 'Accident'),
(5, 'Construction'),
(2, 'Flooding'),
(6, 'Landslide'),
(8, 'Other'),
(1, 'Pothole'),
(4, 'Road Damage'),
(3, 'Road Obstruction');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `barangay_id` int(11) NOT NULL,
  `hazard_type_id` int(11) NOT NULL,
  `road_name` varchar(150) NOT NULL,
  `landmark` varchar(255) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `description` text NOT NULL,
  `status` enum('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
  `passability` enum('passable','partially_passable','not_passable') DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reports`
-- (Intentionally empty: demo reports removed; citizens file live reports.)
--

-- --------------------------------------------------------

--
-- Table structure for table `report_photos`
--

CREATE TABLE `report_photos` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `report_photos`
-- (Intentionally empty: demo photos removed with their reports.)
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `security_question` varchar(255) DEFAULT NULL,
  `security_answer` varchar(255) DEFAULT NULL,
  `role` enum('user','admin','super_admin') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `security_question`, `security_answer`, `role`, `is_active`, `created_by`, `created_at`) VALUES
(1, 'RoadWatch Super Admin', 'superadmin@roadwatch.local', '$2y$10$kPbUg9vR.PipfmPEvFLIrO.lyal03R/FqMUSXkl3a9lYuvYGgrWgW', 'What is the system name?', '$2y$10$czcPHV9JBTldn3rEF9Eznex7PMUVbsot0Kdva28zaBfcZwwOgNpC.', 'super_admin', 1, NULL, '2026-09-23 23:58:52'),
(2, 'Test User', 'user@roadwatch.local', '$2y$10$9RsOn4I3obnNNevEb4.CX.qJFzX9zAYcDclTidZmPiXZTQDSritPy', 'What is the system name?', '$2y$10$f63/J74JuzkHr1sjSQmaGu175HfiFPZqgjWEeMaq7mSQSComusyJW', 'user', 1, 1, '2026-09-24 00:07:25'),
(3, 'Test Admin', 'admin@roadwatch.local', '$2y$10$ubJ.zG3.uqEV70VsBTCoeuXcDk7LZXAP.V2w83MJnOAttpmCPTeDW', 'What is the system name?', '$2y$10$vBno8qStWY0DW0NNfJF8Cub25SMH0G.KlBINsHgQC6rzNjIr2bKFO', 'admin', 1, 1, '2026-09-24 00:13:50'),
(4, 'RoadWatch Test Account', 'testaccount@roadwatch.local', '$2y$10$xJaMPag8Kgqxcbd5IMnGBu3lyUtTjRC6GdfXSj3d8hkYJhd311EJO', 'What is your favorite color?', '$2y$10$Ihc9CyArMeExYF/1xzUQwePNa6bAbNrWUAVVo8NBHBb.Hyx16KUxe', 'user', 1, 1, '2026-09-24 14:49:44'),
(8, 'RoadWatch User Two', 'user2@roadwatch.local', '$2y$10$8I77A9VDmZWZSt7xOYxWAeMhQ7PFGp4eYURWR1vrG1SUg5kuFGMYW', 'What is your favorite color?', '$2y$10$M0r/gaYr65DUz/YG0ZZS2.E1akojf1X4nj92DSn56pYcBaN0WuXk2', 'user', 1, 1, '2026-09-24 16:12:25'),
(9, 'Security Test User', 'securitytest@roadwatch.local', '$2y$10$BmKuuSmmFr3ZZz0SZ/L6u.2mYOHCPtvOGkC7oH8Oc7o.X3YHTOHZy', 'What is your favorite food?', '$2y$10$BDSJS0pOmzCpUTIhTkdGUOfdqwCdfvXoVdVn1pdT0O0zu8RzjUF0y', 'user', 1, 1, '2026-09-24 17:13:50');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `hazard_types`
--
ALTER TABLE `hazard_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `barangay_id` (`barangay_id`),
  ADD KEY `hazard_type_id` (`hazard_type_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `report_photos`
--
ALTER TABLE `report_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `report_id` (`report_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `created_by` (`created_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `hazard_types`
--
ALTER TABLE `hazard_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `report_photos`
--
ALTER TABLE `report_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`),
  ADD CONSTRAINT `reports_ibfk_3` FOREIGN KEY (`hazard_type_id`) REFERENCES `hazard_types` (`id`),
  ADD CONSTRAINT `reports_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `report_photos`
--
ALTER TABLE `report_photos`
  ADD CONSTRAINT `report_photos_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
