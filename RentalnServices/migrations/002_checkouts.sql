-- Run once in phpMyAdmin (database booking_and_services). Fresh installs of booking_and_services.sql already include it.
-- A checkout is a booking the customer is paying for. The real booking and its payment are created only after
-- PayMongo confirms the money, so unpaid attempts never reach the manager.
CREATE TABLE IF NOT EXISTS `checkouts`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `vehicle_id` int NOT NULL,
  `area_id` int NOT NULL,
  `pickup_street` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `with_driver` tinyint(1) NOT NULL DEFAULT 0,
  `total_price` decimal(10, 2) NOT NULL,
  `provider_session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('open','paid','cancelled','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'open',
  `booking_id` int NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_session`(`provider_session_id` ASC) USING BTREE,
  INDEX `idx_vehicle_dates`(`vehicle_id` ASC, `start_at` ASC, `end_at` ASC) USING BTREE,
  INDEX `user_id`(`user_id` ASC) USING BTREE,
  INDEX `area_id`(`area_id` ASC) USING BTREE,
  INDEX `booking_id`(`booking_id` ASC) USING BTREE,
  CONSTRAINT `checkouts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `checkouts_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `checkouts_ibfk_3` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `checkouts_ibfk_4` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
