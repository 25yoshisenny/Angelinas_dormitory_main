/*M!999999\- enable the sandbox mode */
-- MariaDB dump 10.19  Distrib 10.11.16-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: angelinas_dormitory
-- ------------------------------------------------------
-- Server version	10.11.16-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_two_factor`
--

DROP TABLE IF EXISTS `admin_two_factor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_two_factor` (
  `user_id` int(10) unsigned NOT NULL,
  `secret_ciphertext` varbinary(256) NOT NULL,
  `enabled_at` datetime NOT NULL,
  `last_otp_counter` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `admin_two_factor_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_two_factor`
--

LOCK TABLES `admin_two_factor` WRITE;
/*!40000 ALTER TABLE `admin_two_factor` DISABLE KEYS */;
INSERT INTO `admin_two_factor` VALUES
(1,0x6A45596562637863592B2B4752616B4B6B4577476B6B7632456941724F6B643261597765574D7530767153724159414F5A393555746A4F4A6F6130664368726C6F67386732543033574349474F69695A,'2026-11-22 19:23:29',59847884,'2026-11-22 19:23:29');
/*!40000 ALTER TABLE `admin_two_factor` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_two_factor_attempts`
--

DROP TABLE IF EXISTS `admin_two_factor_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_two_factor_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `identity` char(64) NOT NULL,
  `attempted_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_admin_2fa_user_time` (`user_id`,`attempted_at`),
  KEY `ix_admin_2fa_identity_time` (`identity`,`attempted_at`),
  CONSTRAINT `admin_two_factor_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_two_factor_attempts`
--

LOCK TABLES `admin_two_factor_attempts` WRITE;
/*!40000 ALTER TABLE `admin_two_factor_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_two_factor_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_two_factor_recovery`
--

DROP TABLE IF EXISTS `admin_two_factor_recovery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_two_factor_recovery` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_recovery` (`user_id`,`code_hash`),
  KEY `ix_admin_recovery_unused` (`user_id`,`used_at`),
  CONSTRAINT `admin_two_factor_recovery_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_two_factor_recovery`
--

LOCK TABLES `admin_two_factor_recovery` WRITE;
/*!40000 ALTER TABLE `admin_two_factor_recovery` DISABLE KEYS */;
INSERT INTO `admin_two_factor_recovery` VALUES
(1,1,'cb862e66f007a1d69168ea63e6c89b316a292ecdd8230aa7d00fa8ff7113c96f',NULL,'2026-11-22 19:23:29'),
(2,1,'1e372136c27c4e37ebc215094712d042f6a858c0e0d9a09977f211cf80fec16b',NULL,'2026-11-22 19:23:29'),
(3,1,'94a4bd55477b1ca5c9b7ec9201e439d52e7df42997c6dce532de27b51c47b848',NULL,'2026-11-22 19:23:29'),
(4,1,'18efb596a501c675123d39d6a635d1a97f48f8c8de5c358ad2ad8769e1a064af',NULL,'2026-11-22 19:23:29'),
(5,1,'894797e2501b11e7e9c8930f0dad823a0d95336153d73c5b4746f8b894f5c333',NULL,'2026-11-22 19:23:29'),
(6,1,'97d570cd9c1bd7adf7bfaccaf01dd7d183897017b9f0499f7cd34bfd69b59be4',NULL,'2026-11-22 19:23:29'),
(7,1,'8e871fb6b42d0bf0d201c7a6700bde141331bfe4d8074439d9b52d4a8b559c11',NULL,'2026-11-22 19:23:29'),
(8,1,'55e4f561b1a377a3ad144aef8914a9f0ab99185d37cdaf2809bbf3015ea57a9f',NULL,'2026-11-22 19:23:29'),
(9,1,'609295c84019de05c904ab4c3505eb8a347279b1fb79f327a00a6fca955f8640',NULL,'2026-11-22 19:23:29'),
(10,1,'d2eaae652e9f4ce5b3bc6959e156850985ad02bfa35966b4c77278f2b784e716',NULL,'2026-11-22 19:23:29');
/*!40000 ALTER TABLE `admin_two_factor_recovery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `expires_on` date DEFAULT NULL,
  `active` tinyint(4) DEFAULT 1,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `record_type` varchar(80) DEFAULT NULL,
  `record_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES
(1,NULL,'System initialized',NULL,NULL,'Initial rooms and clearly labeled demonstration tenant created.','2026-10-07 13:39:35'),
(2,1,'Logged in','',NULL,'','2026-10-07 13:46:27'),
(3,1,'Changed own password','',NULL,'','2026-10-07 13:47:44'),
(4,1,'Prepared overdue reminders','',NULL,'','2026-10-07 13:48:48'),
(5,1,'Logged out','',NULL,'','2026-10-07 13:49:01'),
(6,2,'Logged in','',NULL,'','2026-10-07 13:49:18'),
(7,2,'Changed own password','',NULL,'','2026-10-07 13:49:42'),
(8,2,'Logged out','',NULL,'','2026-10-07 13:49:51'),
(12,1,'Logged in','',NULL,'','2026-10-07 14:20:36'),
(13,1,'Logged out','',NULL,'','2026-10-07 14:23:16'),
(14,2,'Logged in','',NULL,'','2026-10-07 14:23:26'),
(15,2,'Logged out','',NULL,'','2026-10-07 14:23:43'),
(23,1,'Logged in','',NULL,'','2026-10-07 14:38:35'),
(24,1,'Logged out','',NULL,'','2026-10-07 14:38:53'),
(27,1,'Logged in','',NULL,'','2026-10-07 14:39:34'),
(30,1,'Logged out','',NULL,'','2026-10-07 14:40:42'),
(33,1,'Logged in','',NULL,'','2026-10-07 15:04:00'),
(37,1,'Logged out','',NULL,'','2026-10-07 15:05:19'),
(41,2,'Logged in','',NULL,'','2026-10-07 15:19:58'),
(44,2,'Logged out','',NULL,'','2026-10-07 15:21:18'),
(45,1,'Logged in','',NULL,'','2026-10-07 15:21:28'),
(46,1,'Logged out','',NULL,'','2026-10-07 15:39:20'),
(50,1,'Logged in','',NULL,'','2026-10-07 15:40:57'),
(51,1,'Updated tenant payment instructions','',NULL,'','2026-10-07 15:42:29'),
(52,1,'Updated dormitory settings','',NULL,'','2026-10-07 15:42:56'),
(54,1,'Logged out','',NULL,'','2026-10-07 16:04:49'),
(60,1,'Logged in','',NULL,'','2026-10-07 16:07:41'),
(62,1,'Logged out','',NULL,'','2026-10-07 16:09:31'),
(65,1,'Logged in','',NULL,'','2026-10-07 16:10:13'),
(67,1,'Logged out','',NULL,'','2026-10-07 16:11:48'),
(71,1,'Logged in','',NULL,'','2026-10-07 16:13:13'),
(74,1,'Logged in','',NULL,'','2026-11-22 17:27:04'),
(81,1,'Logged out','',NULL,'','2026-11-22 17:45:48'),
(85,1,'Logged in','',NULL,'','2026-11-22 18:13:08'),
(86,1,'Logged in with two-factor authentication','',NULL,'','2026-11-22 19:23:29'),
(87,1,'Enabled admin two-factor authentication','',NULL,'','2026-11-22 19:23:29'),
(88,1,'Logged out','',NULL,'','2026-11-22 19:24:30'),
(89,1,'Logged in with two-factor authentication','',NULL,'','2026-11-22 19:24:57'),
(90,1,'Logged in with two-factor authentication','',NULL,'','2026-11-23 07:32:59'),
(91,1,'Logged out','',NULL,'','2026-11-23 07:33:12'),
(92,1,'Logged in with two-factor authentication','',NULL,'','2026-11-23 07:34:09'),
(93,1,'Logged out','',NULL,'','2026-11-23 07:35:04'),
(94,2,'Logged in','',NULL,'','2026-11-23 07:35:38'),
(95,2,'Logged out','',NULL,'','2026-11-23 07:36:15'),
(96,1,'Logged in with two-factor authentication','',NULL,'','2026-11-23 07:38:20'),
(97,1,'Logged in with two-factor authentication','',NULL,'','2026-11-23 08:09:12'),
(98,1,'Logged out','',NULL,'','2026-11-23 08:10:03'),
(99,1,'Logged in with two-factor authentication','',NULL,'','2026-11-23 09:10:30'),
(100,2,'Logged in','',NULL,'','2026-11-23 12:21:07'),
(101,2,'Logged out','',NULL,'','2026-11-23 12:21:12'),
(102,1,'Logged in with two-factor authentication','',NULL,'','2026-11-23 12:22:11'),
(104,1,'Updated account email','users',1,'','2026-11-23 15:05:38'),
(105,1,'Updated account email','users',2,'','2026-11-23 15:06:03');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bed_spaces`
--

DROP TABLE IF EXISTS `bed_spaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bed_spaces` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_id` int(10) unsigned NOT NULL,
  `label` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_id` (`room_id`,`label`),
  CONSTRAINT `bed_spaces_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bed_spaces`
--

LOCK TABLES `bed_spaces` WRITE;
/*!40000 ALTER TABLE `bed_spaces` DISABLE KEYS */;
/*!40000 ALTER TABLE `bed_spaces` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing`
--

DROP TABLE IF EXISTS `billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `assignment_id` int(10) unsigned NOT NULL,
  `period` char(7) NOT NULL,
  `cutoff` date NOT NULL,
  `due_date` date NOT NULL,
  `rent` bigint(20) NOT NULL CHECK (`rent` >= 0),
  `electricity` bigint(20) NOT NULL CHECK (`electricity` >= 0),
  `water` bigint(20) NOT NULL CHECK (`water` >= 0),
  `previous_balance` bigint(20) NOT NULL DEFAULT 0 CHECK (`previous_balance` >= 0),
  `total_due` bigint(20) NOT NULL CHECK (`total_due` >= 0),
  `carried_to` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_id` (`tenant_id`,`period`),
  KEY `due_date` (`due_date`),
  KEY `assignment_id` (`assignment_id`),
  KEY `carried_to` (`carried_to`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `billing_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `billing_ibfk_2` FOREIGN KEY (`assignment_id`) REFERENCES `tenant_assignments` (`id`),
  CONSTRAINT `billing_ibfk_3` FOREIGN KEY (`carried_to`) REFERENCES `billing` (`id`),
  CONSTRAINT `billing_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing`
--

LOCK TABLES `billing` WRITE;
/*!40000 ALTER TABLE `billing` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing_adjustments`
--

DROP TABLE IF EXISTS `billing_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_adjustments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `billing_id` int(10) unsigned NOT NULL,
  `type` enum('discount','adjustment') NOT NULL,
  `amount` bigint(20) NOT NULL,
  `reason` text NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `billing_id` (`billing_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `billing_adjustments_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  CONSTRAINT `billing_adjustments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_adjustments`
--

LOCK TABLES `billing_adjustments` WRITE;
/*!40000 ALTER TABLE `billing_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deposits`
--

DROP TABLE IF EXISTS `deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `deposits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `amount` bigint(20) NOT NULL CHECK (`amount` > 0),
  `received_date` date NOT NULL,
  `refund_amount` bigint(20) DEFAULT NULL CHECK (`refund_amount` >= 0 and `refund_amount` <= `amount`),
  `deduction_reason` text DEFAULT NULL,
  `refunded_date` date DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `deposits_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `deposits_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deposits`
--

LOCK TABLES `deposits` WRITE;
/*!40000 ALTER TABLE `deposits` DISABLE KEYS */;
/*!40000 ALTER TABLE `deposits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category` enum('Utilities','Maintenance','Supplies','Salaries','Other') NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` bigint(20) NOT NULL CHECK (`amount` > 0),
  `expense_date` date NOT NULL,
  `reference` varchar(150) DEFAULT NULL,
  `recorded_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `vendor` varchar(200) NOT NULL DEFAULT '',
  `payment_method` enum('Cash','GCash','Bank transfer') NOT NULL DEFAULT 'Cash',
  `idempotency_key` varchar(100) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(10) unsigned DEFAULT NULL,
  `void_reason` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `recorded_by` (`recorded_by`),
  KEY `fk_expense_voider` (`voided_by`),
  CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_expense_voider` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `identity` char(64) NOT NULL,
  `attempted_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `identity` (`identity`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_requests`
--

DROP TABLE IF EXISTS `maintenance_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `room_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('normal','urgent') DEFAULT 'normal',
  `status` enum('open','in_progress','resolved') DEFAULT 'open',
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `room_id` (`room_id`),
  CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `maintenance_requests_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_requests`
--

LOCK TABLES `maintenance_requests` WRITE;
/*!40000 ALTER TABLE `maintenance_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `billing_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(30) NOT NULL,
  `email` varchar(254) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('queued','sent','failed') DEFAULT 'queued',
  `error` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dedup_key` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dedup_key` (`dedup_key`),
  KEY `tenant_id` (`tenant_id`),
  KEY `billing_id` (`billing_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=638 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `occupancy_scans`
--

DROP TABLE IF EXISTS `occupancy_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `occupancy_scans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_id` int(10) unsigned NOT NULL,
  `detected_count` int(11) NOT NULL CHECK (`detected_count` >= 0),
  `registered_count` int(11) NOT NULL,
  `capacity` int(11) NOT NULL,
  `source` enum('manual','camera') NOT NULL,
  `notes` text DEFAULT NULL,
  `scanned_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `room_id` (`room_id`),
  KEY `scanned_by` (`scanned_by`),
  CONSTRAINT `occupancy_scans_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `occupancy_scans_ibfk_2` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `occupancy_scans`
--

LOCK TABLES `occupancy_scans` WRITE;
/*!40000 ALTER TABLE `occupancy_scans` DISABLE KEYS */;
/*!40000 ALTER TABLE `occupancy_scans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_submissions`
--

DROP TABLE IF EXISTS `payment_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `billing_id` int(10) unsigned NOT NULL,
  `amount` bigint(20) NOT NULL CHECK (`amount` > 0),
  `payer` varchar(200) NOT NULL,
  `payment_date` date NOT NULL,
  `reference` varchar(150) NOT NULL,
  `proof_file` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `active_reference` varchar(150) GENERATED ALWAYS AS (case when `status` in ('pending','approved') then `reference` else NULL end) STORED,
  `submission_key` varchar(100) NOT NULL,
  `payment_id` int(10) unsigned DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `submission_key` (`submission_key`),
  UNIQUE KEY `active_reference` (`active_reference`),
  UNIQUE KEY `payment_id` (`payment_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `billing_id` (`billing_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `payment_submissions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `payment_submissions_ibfk_2` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  CONSTRAINT `payment_submissions_ibfk_3` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`),
  CONSTRAINT `payment_submissions_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_submissions`
--

LOCK TABLES `payment_submissions` WRITE;
/*!40000 ALTER TABLE `payment_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `billing_id` int(10) unsigned NOT NULL,
  `tenant_id` int(10) unsigned NOT NULL,
  `amount` bigint(20) NOT NULL CHECK (`amount` > 0),
  `method` enum('GCash','Personal Payment') NOT NULL,
  `payer` varchar(200) NOT NULL,
  `payment_date` date NOT NULL,
  `reference` varchar(150) DEFAULT NULL,
  `received_by` int(10) unsigned NOT NULL,
  `idempotency_key` varchar(100) NOT NULL,
  `balance_after` bigint(20) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(10) unsigned DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `gcash_reference` varchar(150) GENERATED ALWAYS AS (case when `method` = 'GCash' then `reference` else NULL end) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  UNIQUE KEY `gcash_reference` (`gcash_reference`),
  KEY `payment_date` (`payment_date`),
  KEY `billing_id` (`billing_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `received_by` (`received_by`),
  KEY `voided_by` (`voided_by`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`),
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`),
  CONSTRAINT `payments_ibfk_4` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rooms`
--

DROP TABLE IF EXISTS `rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rooms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(50) NOT NULL,
  `capacity` int(11) NOT NULL CHECK (`capacity` between 1 and 30),
  `rent` bigint(20) NOT NULL CHECK (`rent` >= 0),
  `status` enum('active','maintenance') DEFAULT 'active',
  `fixed_electricity` bigint(20) DEFAULT NULL CHECK (`fixed_electricity` >= 0),
  `fixed_water` bigint(20) DEFAULT NULL CHECK (`fixed_water` >= 0),
  PRIMARY KEY (`id`),
  UNIQUE KEY `number` (`number`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rooms`
--

LOCK TABLES `rooms` WRITE;
/*!40000 ALTER TABLE `rooms` DISABLE KEYS */;
/*!40000 ALTER TABLE `rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `key` varchar(80) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES
('business_address','Brgy. Bucana, Nasugbu, Batangas'),
('business_contact','09635145864'),
('business_name','Angelina’s Dormitory'),
('cutoff_day','15'),
('due_day','28'),
('gcash_name','Angelina'),
('gcash_number','09635145864'),
('office_hours','8:00-5:00'),
('payment_instructions',''),
('seeded','1');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenant_assignments`
--

DROP TABLE IF EXISTS `tenant_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `bed_id` int(10) unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `active_bed` int(10) unsigned GENERATED ALWAYS AS (case when `end_date` is null then `bed_id` else NULL end) STORED,
  `active_tenant` int(10) unsigned GENERATED ALWAYS AS (case when `end_date` is null then `tenant_id` else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `active_bed` (`active_bed`),
  UNIQUE KEY `active_tenant` (`active_tenant`),
  KEY `tenant_id` (`tenant_id`),
  KEY `bed_id` (`bed_id`),
  CONSTRAINT `tenant_assignments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `tenant_assignments_ibfk_2` FOREIGN KEY (`bed_id`) REFERENCES `bed_spaces` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenant_assignments`
--

LOCK TABLES `tenant_assignments` WRITE;
/*!40000 ALTER TABLE `tenant_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `tenant_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenant_inquiries`
--

DROP TABLE IF EXISTS `tenant_inquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_inquiries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `category` enum('Billing','Payment','Room assignment','Deposit','Other') NOT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `reply` text DEFAULT NULL,
  `replied_by` int(10) unsigned DEFAULT NULL,
  `replied_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `replied_by` (`replied_by`),
  CONSTRAINT `tenant_inquiries_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `tenant_inquiries_ibfk_2` FOREIGN KEY (`replied_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenant_inquiries`
--

LOCK TABLES `tenant_inquiries` WRITE;
/*!40000 ALTER TABLE `tenant_inquiries` DISABLE KEYS */;
/*!40000 ALTER TABLE `tenant_inquiries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenants`
--

DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `email` varchar(254) NOT NULL,
  `contact` varchar(80) NOT NULL,
  `status` enum('active','moved_out') NOT NULL DEFAULT 'active',
  `move_in` date NOT NULL,
  `move_out` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenants`
--

LOCK TABLES `tenants` WRITE;
/*!40000 ALTER TABLE `tenants` DISABLE KEYS */;
/*!40000 ALTER TABLE `tenants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff','tenant') NOT NULL,
  `name` varchar(200) NOT NULL,
  `tenant_id` int(10) unsigned DEFAULT NULL,
  `active` tinyint(4) DEFAULT 1,
  `must_change` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `tenant_id` (`tenant_id`),
  UNIQUE KEY `uq_users_email` (`email`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'admin','angelinarivera@gmail.com','$2y$12$duCHWxG7edRNOkje1UdCse5At.HdChfJ7kgo3Kc7ImovAFgzXDrSi','admin','Angelina • Administrator',NULL,1,0),
(2,'staff','nadineenriquez@gmail.com','$2y$12$QwoWqND8DOWsCTCPHbwB7.p89ARl1.rdYvoykQmCa6TEj5HBj8eLK','staff','Payment Staff',NULL,1,0);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `utility_charges`
--

DROP TABLE IF EXISTS `utility_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `utility_charges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `billing_id` int(10) unsigned NOT NULL,
  `type` enum('water','electricity') NOT NULL,
  `amount` bigint(20) NOT NULL CHECK (`amount` >= 0),
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_id` (`billing_id`,`type`),
  CONSTRAINT `utility_charges_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `utility_charges`
--

LOCK TABLES `utility_charges` WRITE;
/*!40000 ALTER TABLE `utility_charges` DISABLE KEYS */;
/*!40000 ALTER TABLE `utility_charges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'angelinas_dormitory'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-23 23:27:12
