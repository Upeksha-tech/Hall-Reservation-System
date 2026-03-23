-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 10, 2026 at 10:38 AM
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
-- Database: `hall_management-system`
--

-- --------------------------------------------------------

--
-- Table structure for table `building`
--

CREATE TABLE `building` (
  `building_id` int(2) UNSIGNED NOT NULL,
  `building_name` varchar(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `building`
--

INSERT INTO `building` (`building_id`, `building_name`) VALUES
(1, 'A11');

-- --------------------------------------------------------

--
-- Table structure for table `fixed_reservations`
--

CREATE TABLE `fixed_reservations` (
  `id` int(11) NOT NULL,
  `day_of_week` tinyint(1) NOT NULL COMMENT '1=Monday, 7=Sunday',
  `building_id` int(2) UNSIGNED NOT NULL,
  `hall_id` int(2) UNSIGNED NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `purpose` varchar(255) NOT NULL,
  `from_date` date NOT NULL,
  `to_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hall`
--

CREATE TABLE `hall` (
  `hall_id` int(2) UNSIGNED NOT NULL,
  `hall_name` varchar(15) NOT NULL,
  `building_id` int(2) UNSIGNED NOT NULL,
  `hall_capacity` int(3) UNSIGNED NOT NULL,
  `hall_type` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hall`
--

INSERT INTO `hall` (`hall_id`, `hall_name`, `building_id`, `hall_capacity`, `hall_type`) VALUES
(1, '201', 1, 30, 'Lecture Hall');

-- --------------------------------------------------------

--
-- Table structure for table `payment`
--

CREATE TABLE `payment` (
  `hall_id` int(10) UNSIGNED NOT NULL,
  `refundable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `non_refundable_amount` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment`
--

INSERT INTO `payment` (`hall_id`, `refundable_amount`, `non_refundable_amount`) VALUES
(1, 10000.00, 3000.00);

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `student_no` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) NOT NULL,
  `department` varchar(255) NOT NULL,
  `hod_email` varchar(255) NOT NULL,
  `purpose` text NOT NULL,
  `building_id` int(10) UNSIGNED NOT NULL,
  `hall_id` int(10) UNSIGNED NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `payment_slip_path` varchar(255) DEFAULT NULL,
  `status` enum('pending_hod','pending_payment','pending_dean','pending_admin','approved','rejected','cancelled') NOT NULL DEFAULT 'pending_hod',
  `approval_token` varchar(64) NOT NULL,
  `rejected_reason` text DEFAULT NULL,
  `hod_approved_at` datetime DEFAULT NULL,
  `dean_approved_at` datetime DEFAULT NULL,
  `admin_approved_at` datetime DEFAULT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `user_id` int(7) UNSIGNED NOT NULL,
  `user_name` varchar(25) NOT NULL,
  `password_hash` varchar(500) NOT NULL,
  `email` varchar(50) NOT NULL,
  `user_role` text NOT NULL DEFAULT 'user',
  `expiry_date` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`user_id`, `user_name`, `password_hash`, `email`, `user_role`, `expiry_date`) VALUES
(22, 'Vishwa', '$2y$10$iOKXLZIMpURHDjRjJqsKI.R5Df9W0cB5NbDzOCzQK1VhRHWVoAPku', 'upekshamataraarachchi@gmail.com', 'user', 2027),
(23, 'Dean', '', 'dean56318@gmail.com', 'dean', NULL),
(25, 'admin', '$2y$10$qw3Lioe/AaowxSrLQFfjmeEHBFqkgaKjToOY7hz7HrwWIqIlVtWOW', 'admin0474@gmail.com', 'admin', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `building`
--
ALTER TABLE `building`
  ADD PRIMARY KEY (`building_id`),
  ADD UNIQUE KEY `UNIQUE` (`building_name`);

--
-- Indexes for table `fixed_reservations`
--
ALTER TABLE `fixed_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_fr_building` (`building_id`),
  ADD KEY `fk_fr_hall` (`hall_id`);

--
-- Indexes for table `hall`
--
ALTER TABLE `hall`
  ADD PRIMARY KEY (`hall_id`),
  ADD UNIQUE KEY `unique_hall_name` (`hall_name`),
  ADD KEY `building_id` (`building_id`);

--
-- Indexes for table `payment`
--
ALTER TABLE `payment`
  ADD PRIMARY KEY (`hall_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_res_user` (`user_id`),
  ADD KEY `fk_res_building` (`building_id`),
  ADD KEY `fk_res_hall` (`hall_id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `unique_user_name` (`user_name`),
  ADD UNIQUE KEY `unique_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `building`
--
ALTER TABLE `building`
  MODIFY `building_id` int(2) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fixed_reservations`
--
ALTER TABLE `fixed_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hall`
--
ALTER TABLE `hall`
  MODIFY `hall_id` int(2) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `user_id` int(7) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `fixed_reservations`
--
ALTER TABLE `fixed_reservations`
  ADD CONSTRAINT `fk_fr_building` FOREIGN KEY (`building_id`) REFERENCES `building` (`building_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fr_hall` FOREIGN KEY (`hall_id`) REFERENCES `hall` (`hall_id`) ON DELETE CASCADE;

--
-- Constraints for table `hall`
--
ALTER TABLE `hall`
  ADD CONSTRAINT `hall_ibfk_1` FOREIGN KEY (`building_id`) REFERENCES `building` (`building_id`);

--
-- Constraints for table `payment`
--
ALTER TABLE `payment`
  ADD CONSTRAINT `fk_payment_hall` FOREIGN KEY (`hall_id`) REFERENCES `hall` (`hall_id`) ON DELETE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_res_building` FOREIGN KEY (`building_id`) REFERENCES `building` (`building_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_res_hall` FOREIGN KEY (`hall_id`) REFERENCES `hall` (`hall_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_res_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
