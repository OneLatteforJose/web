-- Adds the "maintenance" vehicle status (shown to customers as "Under maintenance").
-- Run once on the booking_and_services database (phpMyAdmin / Navicat). Safe to run again.
ALTER TABLE `vehicles`
  MODIFY `status` enum('available','booked','unavailable','maintenance') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'available';
