-- Run once in phpMyAdmin (database booking_and_services). Fresh installs of booking_and_services.sql already include it.
-- A service checkout is a service request the customer is paying for online. The real request and its payment are
-- created only after Xendit confirms the money, so unpaid attempts never reach the manager.
CREATE TABLE IF NOT EXISTS `service_checkouts`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `service_id` int NOT NULL,
  `area_id` int NOT NULL,
  `service_street` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `scheduled_at` datetime NOT NULL,
  `total_price` decimal(10, 2) NOT NULL,
  `provider_session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('open','paid','cancelled','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'open',
  `service_request_id` int NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_service_session`(`provider_session_id` ASC) USING BTREE,
  INDEX `user_id`(`user_id` ASC) USING BTREE,
  INDEX `service_id`(`service_id` ASC) USING BTREE,
  INDEX `area_id`(`area_id` ASC) USING BTREE,
  INDEX `service_request_id`(`service_request_id` ASC) USING BTREE,
  CONSTRAINT `service_checkouts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `service_checkouts_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `service_checkouts_ibfk_3` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `service_checkouts_ibfk_4` FOREIGN KEY (`service_request_id`) REFERENCES `service_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
