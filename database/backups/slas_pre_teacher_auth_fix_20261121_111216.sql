-- MySQL dump 10.13  Distrib 8.4.7, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: student_learning_analytics
-- ------------------------------------------------------
-- Server version	8.4.7

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `academic_years`
--

DROP TABLE IF EXISTS `academic_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_years_name_unique` (`name`),
  KEY `academic_years_status_index` (`status`),
  KEY `academic_years_start_date_end_date_index` (`start_date`,`end_date`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_years`
--

LOCK TABLES `academic_years` WRITE;
/*!40000 ALTER TABLE `academic_years` DISABLE KEYS */;
INSERT INTO `academic_years` VALUES (1,'2025-2026','2025-09-01','2026-06-30','completed','2026-10-05 11:13:04','2026-10-05 11:13:04'),(2,'2026-2027','2026-09-01','2027-06-30','active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(3,'2023-2024','2023-09-01','2024-06-30','completed','2026-11-02 06:06:46','2026-11-02 06:06:46'),(4,'2024-2025','2024-09-01','2025-06-30','completed','2026-11-02 06:06:46','2026-11-02 06:06:46'),(5,'2027-2028','2027-09-01','2028-06-30','inactive','2026-11-02 06:06:46','2026-11-02 06:06:46');
/*!40000 ALTER TABLE `academic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `admin_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admins_user_id_unique` (`user_id`),
  UNIQUE KEY `admins_admin_code_unique` (`admin_code`),
  KEY `admins_status_index` (`status`),
  CONSTRAINT `admins_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (1,1,'ADM001','SLAS','Administrator',NULL,'Administration','System Administrator','active','2026-10-05 11:13:05','2026-10-05 11:13:05');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignment_attempts`
--

DROP TABLE IF EXISTS `assignment_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assignment_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `attempt_number` smallint unsigned NOT NULL,
  `started_at` timestamp NOT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `file_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `answers` json DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `graded_by` bigint unsigned DEFAULT NULL,
  `graded_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assignment_attempt_student_try_unique` (`assignment_id`,`student_id`,`attempt_number`),
  KEY `assignment_attempt_student_lookup_index` (`student_id`),
  KEY `assignment_attempts_graded_by_foreign` (`graded_by`),
  CONSTRAINT `assignment_attempts_graded_by_foreign` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_attempts`
--

LOCK TABLES `assignment_attempts` WRITE;
/*!40000 ALTER TABLE `assignment_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignment_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignment_questions`
--

DROP TABLE IF EXISTS `assignment_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assignment_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint unsigned NOT NULL,
  `prompt` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `correct_answer` tinyint(1) NOT NULL,
  `position` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `assignment_questions_assignment_id_foreign` (`assignment_id`),
  CONSTRAINT `assignment_questions_assignment_id_foreign` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_questions`
--

LOCK TABLES `assignment_questions` WRITE;
/*!40000 ALTER TABLE `assignment_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignment_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignment_submissions`
--

DROP TABLE IF EXISTS `assignment_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assignment_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `submissions_assignment_student_unique` (`assignment_id`,`student_id`),
  KEY `submissions_student_status_index` (`student_id`,`status`),
  CONSTRAINT `assignment_submissions_assignment_id_foreign` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignment_submissions_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=630 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_submissions`
--

LOCK TABLES `assignment_submissions` WRITE;
/*!40000 ALTER TABLE `assignment_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignment_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignments`
--

DROP TABLE IF EXISTS `assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `course_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `total_score` decimal(6,2) NOT NULL DEFAULT '100.00',
  `due_date` datetime DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `assignment_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'file_upload',
  `allow_retakes` tinyint(1) NOT NULL DEFAULT '0',
  `max_attempts` smallint unsigned NOT NULL DEFAULT '1',
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `assignments_course_id_foreign` (`course_id`),
  KEY `assignments_created_by_foreign` (`created_by`),
  KEY `assignments_course_offering_id_foreign` (`course_offering_id`),
  CONSTRAINT `assignments_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `assignments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignments`
--

LOCK TABLES `assignments` WRITE;
/*!40000 ALTER TABLE `assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `course_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `student_id` bigint unsigned DEFAULT NULL,
  `marked_by` bigint unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `topic` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_course_id_foreign` (`course_id`),
  KEY `attendance_teacher_id_foreign` (`teacher_id`),
  KEY `attendance_semester_id_foreign` (`semester_id`),
  KEY `attendance_student_id_foreign` (`student_id`),
  KEY `attendance_marked_by_foreign` (`marked_by`),
  KEY `attendance_attendance_date_index` (`attendance_date`),
  KEY `attendance_course_offering_id_foreign` (`course_offering_id`),
  CONSTRAINT `attendance_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `attendance_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_marked_by_foreign` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=162 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_details`
--

DROP TABLE IF EXISTS `attendance_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attendance_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_details_attendance_student_unique` (`attendance_id`,`student_id`),
  KEY `attendance_details_student_status_index` (`student_id`,`status`),
  CONSTRAINT `attendance_details_attendance_id_foreign` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_details_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1535 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_details`
--

LOCK TABLES `attendance_details` WRITE;
/*!40000 ALTER TABLE `attendance_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendances`
--

DROP TABLE IF EXISTS `attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `attendance_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marked_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendances_course_id_student_id_attendance_date_unique` (`course_id`,`student_id`,`attendance_date`),
  KEY `attendances_student_id_foreign` (`student_id`),
  KEY `attendances_marked_by_foreign` (`marked_by`),
  CONSTRAINT `attendances_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendances_marked_by_foreign` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendances`
--

LOCK TABLES `attendances` WRITE;
/*!40000 ALTER TABLE `attendances` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `buildings`
--

DROP TABLE IF EXISTS `buildings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buildings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buildings_name_unique` (`name`),
  UNIQUE KEY `buildings_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `buildings`
--

LOCK TABLES `buildings` WRITE;
/*!40000 ALTER TABLE `buildings` DISABLE KEYS */;
INSERT INTO `buildings` VALUES (1,'BLDG-A','Building A','OMG','active','2026-11-20 14:53:17','2026-11-20 14:53:17');
/*!40000 ALTER TABLE `buildings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('laravel-cache-shang123@gmail.com|127.0.0.1','i:1;',1795234122),('laravel-cache-shang123@gmail.com|127.0.0.1:timer','i:1795234122;',1795234122),('laravel-cache-slas26t009|192.168.1.117','i:5;',1794799150),('laravel-cache-slas26t009|192.168.1.117:timer','i:1794799150;',1794799150),('laravel-cache-slas26t010|192.168.1.3','i:4;',1794841589),('laravel-cache-slas26t010|192.168.1.3:timer','i:1794841589;',1794841589),('laravel-cache-slas26t101|127.0.0.1','i:1;',1794913374),('laravel-cache-slas26t101|127.0.0.1:timer','i:1794913374;',1794913374),('laravel-cache-slas26t101|192.168.1.19','i:1;',1794913160),('laravel-cache-slas26t101|192.168.1.19:timer','i:1794913160;',1794913160),('laravel-cache-slas26t103|192.168.1.19','i:1;',1794913143),('laravel-cache-slas26t103|192.168.1.19:timer','i:1794913143;',1794913143),('laravel-cache-slas26t104|192.168.1.19','i:2;',1794913105),('laravel-cache-slas26t104|192.168.1.19:timer','i:1794913105;',1794913105),('laravel-cache-slas6t009|127.0.0.1','i:3;',1795202792),('laravel-cache-slas6t009|127.0.0.1:timer','i:1795202792;',1795202792),('laravel-cache-tch001|127.0.0.1','i:2;',1795204791),('laravel-cache-tch001|127.0.0.1:timer','i:1795204791;',1795204791),('laravel-cache-tch0sang|127.0.0.1','i:1;',1795234121),('laravel-cache-tch0sang|127.0.0.1:timer','i:1795234121;',1795234121);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_schedules`
--

DROP TABLE IF EXISTS `class_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `academic_year_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `class_id` bigint unsigned NOT NULL,
  `building_id` bigint unsigned DEFAULT NULL,
  `floor_id` bigint unsigned DEFAULT NULL,
  `course_id` bigint unsigned NOT NULL,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `teacher_id` bigint unsigned DEFAULT NULL,
  `day_of_week` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `class_schedules_academic_year_id_foreign` (`academic_year_id`),
  KEY `class_schedules_semester_id_foreign` (`semester_id`),
  KEY `class_schedules_department_id_foreign` (`department_id`),
  KEY `class_schedules_building_id_foreign` (`building_id`),
  KEY `class_schedules_floor_id_foreign` (`floor_id`),
  KEY `class_schedules_course_id_foreign` (`course_id`),
  KEY `class_schedules_course_offering_id_foreign` (`course_offering_id`),
  KEY `idx_sched_class_slot` (`class_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_sched_teacher_slot` (`teacher_id`,`day_of_week`,`start_time`,`end_time`),
  CONSTRAINT `class_schedules_academic_year_id_foreign` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_building_id_foreign` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_schedules_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_schedules_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_floor_id_foreign` FOREIGN KEY (`floor_id`) REFERENCES `floors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_schedules`
--

LOCK TABLES `class_schedules` WRITE;
/*!40000 ALTER TABLE `class_schedules` DISABLE KEYS */;
INSERT INTO `class_schedules` VALUES (1,2,3,1,28,NULL,NULL,15,87,19,'Monday','18:00:00','21:00:00','active',NULL,'2026-11-20 20:50:00','2026-11-20 20:50:00');
/*!40000 ALTER TABLE `class_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_teacher`
--

DROP TABLE IF EXISTS `class_teacher`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_teacher` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `class_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_teacher_unique` (`class_id`,`teacher_id`,`course_id`),
  KEY `class_teacher_course_id_foreign` (`course_id`),
  KEY `class_teacher_teacher_id_index` (`teacher_id`),
  CONSTRAINT `class_teacher_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_teacher_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_teacher_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_teacher`
--

LOCK TABLES `class_teacher` WRITE;
/*!40000 ALTER TABLE `class_teacher` DISABLE KEYS */;
INSERT INTO `class_teacher` VALUES (12,28,19,15,'2026-11-20 20:50:00','2026-11-20 20:50:00');
/*!40000 ALTER TABLE `class_teacher` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned DEFAULT NULL,
  `academic_year_id` bigint unsigned DEFAULT NULL,
  `building_id` bigint unsigned DEFAULT NULL,
  `floor_id` bigint unsigned DEFAULT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `teacher_id` bigint unsigned DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year_level` tinyint unsigned DEFAULT NULL,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `room` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacity` int unsigned NOT NULL DEFAULT '40',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classes_code_unique` (`code`),
  KEY `classes_course_id_foreign` (`course_id`),
  KEY `classes_teacher_id_foreign` (`teacher_id`),
  KEY `classes_department_id_foreign` (`department_id`),
  KEY `classes_academic_year_id_foreign` (`academic_year_id`),
  KEY `classes_building_id_foreign` (`building_id`),
  KEY `classes_floor_id_foreign` (`floor_id`),
  CONSTRAINT `classes_academic_year_id_foreign` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classes_building_id_foreign` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classes_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classes_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classes_floor_id_foreign` FOREIGN KEY (`floor_id`) REFERENCES `floors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classes_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT INTO `classes` VALUES (1,1,2,NULL,NULL,NULL,NULL,'Computer Science Year 1 (2026-2027)','CS-Y1-2026-2027',1,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(2,1,2,NULL,NULL,NULL,NULL,'Computer Science Year 2 (2026-2027)','CS-Y2-2026-2027',2,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(3,1,2,NULL,NULL,NULL,NULL,'Computer Science Year 3 (2026-2027)','CS-Y3-2026-2027',3,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(4,2,2,NULL,NULL,NULL,NULL,'Information Technology Year 1 (2026-2027)','IT-Y1-2026-2027',1,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(5,2,2,NULL,NULL,NULL,NULL,'Information Technology Year 2 (2026-2027)','IT-Y2-2026-2027',2,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(6,2,2,NULL,NULL,NULL,NULL,'Information Technology Year 3 (2026-2027)','IT-Y3-2026-2027',3,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(7,3,2,NULL,NULL,NULL,NULL,'Business Administration Year 1 (2026-2027)','BA-Y1-2026-2027',1,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(8,3,2,NULL,NULL,NULL,NULL,'Business Administration Year 2 (2026-2027)','BA-Y2-2026-2027',2,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(9,3,2,NULL,NULL,NULL,NULL,'Business Administration Year 3 (2026-2027)','BA-Y3-2026-2027',3,NULL,NULL,NULL,NULL,40,'active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(10,3,5,NULL,NULL,NULL,NULL,'Business Administration Year 1 (2027-2028)','BA-Y1-2027-2028',1,NULL,NULL,NULL,NULL,40,'active','2026-11-04 20:22:20','2026-11-04 20:22:20'),(11,2,1,NULL,NULL,NULL,NULL,'Information Technology Year 2 (2025-2026)','IT-Y2-2025-2026',2,NULL,NULL,NULL,NULL,40,'active','2026-11-06 00:56:30','2026-11-06 00:56:30'),(12,2,1,NULL,NULL,NULL,NULL,'Information Technology Year 3 (2025-2026)','IT-Y3-2025-2026',3,NULL,NULL,NULL,NULL,40,'active','2026-11-06 00:56:30','2026-11-06 00:56:30'),(13,3,1,NULL,NULL,NULL,NULL,'Business Administration Year 1 (2025-2026)','BA-Y1-2025-2026',1,NULL,NULL,NULL,NULL,40,'active','2026-11-06 00:56:30','2026-11-06 00:56:30'),(14,2,1,NULL,NULL,NULL,NULL,'Information Technology Year 1 (2025-2026)','IT-Y1-2025-2026',1,NULL,NULL,NULL,NULL,40,'active','2026-11-15 19:20:44','2026-11-15 19:20:44'),(15,3,1,NULL,NULL,NULL,NULL,'Business Administration Year 2 (2025-2026)','BA-Y2-2025-2026',2,NULL,NULL,NULL,NULL,40,'active','2026-11-15 19:20:44','2026-11-15 19:20:44'),(16,3,1,NULL,NULL,NULL,NULL,'Business Administration Year 3 (2025-2026)','BA-Y3-2025-2026',3,NULL,NULL,NULL,NULL,40,'active','2026-11-15 19:20:44','2026-11-15 19:20:44'),(17,1,1,NULL,NULL,NULL,NULL,'Computer Science Year 2 (2025-2026)','CS-Y2-2025-2026',2,NULL,NULL,NULL,NULL,40,'active','2026-11-17 01:46:32','2026-11-17 01:46:32'),(28,1,2,NULL,NULL,NULL,NULL,'Class A','CLASS-A',1,NULL,NULL,NULL,NULL,40,'active','2026-11-20 12:58:38','2026-11-20 12:58:38'),(29,1,2,NULL,NULL,NULL,NULL,'Class B','CLASS-B',1,NULL,NULL,NULL,NULL,40,'active','2026-11-20 12:58:38','2026-11-20 12:58:38'),(30,1,2,NULL,NULL,NULL,NULL,'Class C','CLASS-C',1,NULL,NULL,NULL,NULL,40,'active','2026-11-20 12:58:38','2026-11-20 12:58:38'),(31,1,2,NULL,NULL,NULL,NULL,'Class D','CLASS-D',1,NULL,NULL,NULL,NULL,40,'active','2026-11-20 12:58:38','2026-11-20 12:58:38'),(32,1,2,NULL,NULL,NULL,NULL,'Class E','CLASS-E',1,NULL,NULL,NULL,NULL,40,'active','2026-11-20 12:58:38','2026-11-20 12:58:38');
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_enrollments`
--

DROP TABLE IF EXISTS `course_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `enrollment_date` date DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enrolled',
  `final_score` decimal(5,2) DEFAULT NULL,
  `grade` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `enrollments_student_course_semester_unique` (`student_id`,`course_id`,`semester_id`),
  KEY `course_enrollments_semester_id_foreign` (`semester_id`),
  KEY `enrollments_student_semester_index` (`student_id`,`semester_id`),
  KEY `enrollments_course_semester_index` (`course_id`,`semester_id`),
  KEY `course_enrollments_status_index` (`status`),
  KEY `course_enrollments_course_offering_id_foreign` (`course_offering_id`),
  CONSTRAINT `course_enrollments_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `course_enrollments_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_enrollments_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `course_enrollments_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1145 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_enrollments`
--

LOCK TABLES `course_enrollments` WRITE;
/*!40000 ALTER TABLE `course_enrollments` DISABLE KEYS */;
INSERT INTO `course_enrollments` VALUES (1142,87,39,15,3,'2026-11-21','enrolled',NULL,NULL,'2026-11-20 19:12:46','2026-11-20 19:12:46'),(1143,88,39,16,3,'2026-11-21','enrolled',NULL,NULL,'2026-11-20 19:12:46','2026-11-20 19:12:46'),(1144,89,39,17,3,'2026-11-21','enrolled',NULL,NULL,'2026-11-20 19:12:46','2026-11-20 19:12:46');
/*!40000 ALTER TABLE `course_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_offering_teachers`
--

DROP TABLE IF EXISTS `course_offering_teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_offering_teachers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `assigned_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offering_teachers_teacher_status_idx` (`teacher_id`,`status`),
  KEY `offering_teachers_offering_status_idx` (`course_offering_id`,`status`),
  CONSTRAINT `course_offering_teachers_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_offering_teachers_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_offering_teachers`
--

LOCK TABLES `course_offering_teachers` WRITE;
/*!40000 ALTER TABLE `course_offering_teachers` DISABLE KEYS */;
INSERT INTO `course_offering_teachers` VALUES (90,87,19,'primary','active','2026-11-20 20:50:00',NULL,'2026-11-20 20:50:00','2026-11-20 20:50:00');
/*!40000 ALTER TABLE `course_offering_teachers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_offerings`
--

DROP TABLE IF EXISTS `course_offerings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_offerings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `academic_year_id` bigint unsigned DEFAULT NULL,
  `program_id` bigint unsigned DEFAULT NULL,
  `study_year` tinyint unsigned NOT NULL DEFAULT '1',
  `section_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `course_offerings_semester_id_foreign` (`semester_id`),
  KEY `course_offerings_academic_year_id_foreign` (`academic_year_id`),
  KEY `course_offerings_program_id_foreign` (`program_id`),
  KEY `offerings_course_semester_index` (`course_id`,`semester_id`),
  KEY `offerings_class_status_index` (`class_id`,`status`),
  KEY `course_offerings_status_index` (`status`),
  CONSTRAINT `course_offerings_academic_year_id_foreign` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_offerings_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_offerings_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `course_offerings_program_id_foreign` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_offerings_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_offerings`
--

LOCK TABLES `course_offerings` WRITE;
/*!40000 ALTER TABLE `course_offerings` DISABLE KEYS */;
INSERT INTO `course_offerings` VALUES (1,1,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(2,1,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(3,1,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(4,1,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(5,1,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(6,1,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(7,1,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(8,1,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(9,1,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(10,1,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(11,2,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:41','2026-11-11 03:25:41',NULL),(12,2,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(13,2,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(14,2,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(15,2,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(16,2,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(17,2,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(18,2,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(19,2,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(20,2,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(21,3,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(22,3,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(23,3,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(24,3,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(25,3,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(26,3,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(27,3,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(28,3,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(29,3,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(30,3,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(31,4,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(32,4,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(33,4,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(34,4,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(35,4,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(36,4,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(37,4,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(38,4,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(39,4,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:42','2026-11-11 03:25:42',NULL),(40,4,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(41,5,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(42,5,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(43,5,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(44,5,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(45,5,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(46,5,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(47,5,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(48,5,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(49,5,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(50,5,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(51,6,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(52,6,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(53,6,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(54,6,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(55,6,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(56,6,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(57,6,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(58,6,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(59,6,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(60,6,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(61,7,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(62,7,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(63,7,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(64,7,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(65,7,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(66,7,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:43','2026-11-11 03:25:43',NULL),(67,7,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(68,7,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(69,7,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(70,7,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(71,8,2,1,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(72,8,2,2,1,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(73,8,2,3,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(74,8,2,4,2,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(75,8,2,5,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(76,8,2,6,3,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(77,8,2,7,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(78,8,2,8,4,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(79,8,2,9,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(80,8,2,10,5,1,2,'Computer Science Year 2 (2026-2027)','active','2026-11-11 03:25:44','2026-11-11 03:25:44',NULL),(87,15,28,3,2,1,1,'Class A','active','2026-11-20 12:58:38','2026-11-20 12:58:38',NULL),(88,16,28,3,2,1,1,'Class A','active','2026-11-20 12:58:38','2026-11-20 12:58:38',NULL),(89,17,28,3,2,1,1,'Class A','active','2026-11-20 12:58:38','2026-11-20 12:58:38',NULL);
/*!40000 ALTER TABLE `course_offerings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_student`
--

DROP TABLE IF EXISTS `course_student`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_student` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `academic_year` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `semester` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enrolled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_student_course_id_student_id_unique` (`course_id`,`student_id`),
  KEY `course_student_student_id_status_index` (`student_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_student`
--

LOCK TABLES `course_student` WRITE;
/*!40000 ALTER TABLE `course_student` DISABLE KEYS */;
/*!40000 ALTER TABLE `course_student` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned DEFAULT NULL,
  `teacher_id` bigint unsigned DEFAULT NULL,
  `academic_year` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `semester` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `day_of_week` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `room` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `course_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `course_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `credits` smallint unsigned DEFAULT NULL,
  `credit` decimal(3,1) NOT NULL DEFAULT '3.0',
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courses`
--

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (1,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Programming Fundamentals','CS101','Programming Fundamentals','CS101',NULL,'Introduction to programming logic, control flow and basic problem solving.',NULL,3.0,'active','2026-10-05 11:13:05','2026-11-18 08:08:12',NULL),(2,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Data Structures and Algorithms','CS201','Data Structures and Algorithms','CS201',NULL,'Lists, stacks, queues, trees, graphs and algorithm analysis.',NULL,4.0,'active','2026-10-05 11:13:05','2026-10-05 11:13:05',NULL),(3,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Database Systems','CS301','Database Systems','CS301',NULL,'Relational modelling, SQL, normalization and transactions.',NULL,3.0,'active','2026-10-05 11:13:05','2026-10-05 11:13:05',NULL),(4,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Software Engineering','CS302','Software Engineering','CS302',NULL,'Software lifecycle, agile practices, testing and version control.',NULL,3.0,'active','2026-10-05 11:13:05','2026-10-05 11:13:05',NULL),(5,2,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Networking Fundamentals','IT101','Networking Fundamentals','IT101',NULL,'OSI model, IP addressing, routing and network services.',NULL,3.0,'active','2026-10-05 11:13:05','2026-10-05 11:13:05',NULL),(6,2,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Web Development','IT201','Web Development','IT201',NULL,'HTML, CSS, JavaScript and building REST-backed web applications.',NULL,4.0,'active','2026-10-05 11:13:05','2026-10-05 11:13:05',NULL),(7,2,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Information Security','IT301','Information Security','IT301',NULL,'Threats, cryptography, authentication and secure system design.',NULL,3.0,'active','2026-10-05 11:13:05','2026-10-05 11:13:05',NULL),(8,2,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Mobile Application Development','IT302','Mobile Application Development','IT302',NULL,'Building Android apps that consume REST APIs.',NULL,3.0,'active','2026-10-05 11:13:05','2026-10-05 11:13:05',NULL),(15,4,19,NULL,NULL,NULL,NULL,NULL,NULL,'Data Science','DS101','Data Science','DS101',NULL,'Data analysis, statistics, predictive modeling, and machine learning foundations.',NULL,3.0,'active','2026-11-20 12:58:38','2026-11-20 19:11:18',NULL),(16,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Android App','CS305','Android App','CS305',NULL,'Android architecture, Material UI, Retrofit REST APIs, and mobile data persistence.',NULL,3.0,'active','2026-11-20 12:58:38','2026-11-20 12:58:38',NULL),(17,2,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Network Administration','IT205','Network Administration','IT205',NULL,'Routing, switching, Linux/Windows server administration, DNS, DHCP, and network security.',NULL,3.0,'active','2026-11-20 12:58:38','2026-11-20 12:58:38',NULL);
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_name_unique` (`name`),
  UNIQUE KEY `departments_code_unique` (`code`),
  KEY `departments_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'Computer Science','CS','Software, algorithms, data and computing theory.','active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(2,'Information Technology','IT','Networks, web, mobile and security technologies.','active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(3,'Business Administration','BA','Management, marketing and entrepreneurship.','active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(4,'Data Science','DS',NULL,'active','2026-11-17 04:41:17','2026-11-17 04:41:17');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_results`
--

DROP TABLE IF EXISTS `exam_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `grade` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_results_exam_student_unique` (`exam_id`,`student_id`),
  KEY `exam_results_student_id_foreign` (`student_id`),
  CONSTRAINT `exam_results_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_results_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=625 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_results`
--

LOCK TABLES `exam_results` WRITE;
/*!40000 ALTER TABLE `exam_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exams`
--

DROP TABLE IF EXISTS `exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `course_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `exam_date` date NOT NULL,
  `total_score` decimal(6,2) NOT NULL DEFAULT '100.00',
  `duration_minutes` smallint unsigned DEFAULT NULL,
  `venue` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exams_teacher_id_foreign` (`teacher_id`),
  KEY `exams_semester_id_foreign` (`semester_id`),
  KEY `exams_exam_date_index` (`exam_date`),
  KEY `exams_course_semester_type_index` (`course_id`,`semester_id`,`exam_type`),
  KEY `exams_status_index` (`status`),
  KEY `exams_course_offering_id_foreign` (`course_offering_id`),
  CONSTRAINT `exams_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `exams_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exams_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `exams_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exams`
--

LOCK TABLES `exams` WRITE;
/*!40000 ALTER TABLE `exams` DISABLE KEYS */;
/*!40000 ALTER TABLE `exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `faculty_reviews`
--

DROP TABLE IF EXISTS `faculty_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `faculty_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `reviewer_id` bigint unsigned DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `comments` text COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `faculty_reviews_semester_id_foreign` (`semester_id`),
  KEY `faculty_reviews_reviewer_id_foreign` (`reviewer_id`),
  KEY `faculty_reviews_teacher_id_status_index` (`teacher_id`,`status`),
  CONSTRAINT `faculty_reviews_reviewer_id_foreign` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faculty_reviews_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faculty_reviews_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `faculty_reviews`
--

LOCK TABLES `faculty_reviews` WRITE;
/*!40000 ALTER TABLE `faculty_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `faculty_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `floors`
--

DROP TABLE IF EXISTS `floors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `floors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `building_id` bigint unsigned NOT NULL,
  `floor_number` int NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `floors_building_number_unique` (`building_id`,`floor_number`),
  CONSTRAINT `floors_building_id_foreign` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `floors`
--

LOCK TABLES `floors` WRITE;
/*!40000 ALTER TABLE `floors` DISABLE KEYS */;
INSERT INTO `floors` VALUES (1,1,1,'Floor 1','active','2026-11-20 14:53:17','2026-11-20 14:53:17'),(2,1,2,'Floor 2','active','2026-11-20 14:53:17','2026-11-20 14:53:17'),(3,1,3,'Floor 3','active','2026-11-20 14:53:17','2026-11-20 14:53:17'),(4,1,4,'Floor 4','active','2026-11-20 14:53:17','2026-11-20 14:53:17');
/*!40000 ALTER TABLE `floors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gpa_records`
--

DROP TABLE IF EXISTS `gpa_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gpa_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `gpa` decimal(3,2) NOT NULL DEFAULT '0.00',
  `total_credits` decimal(5,1) NOT NULL DEFAULT '0.0',
  `earned_credits` decimal(5,1) NOT NULL DEFAULT '0.0',
  `academic_standing` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gpa_records_student_semester_unique` (`student_id`,`semester_id`),
  KEY `gpa_records_semester_id_foreign` (`semester_id`),
  KEY `gpa_records_academic_standing_index` (`academic_standing`),
  CONSTRAINT `gpa_records_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `gpa_records_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gpa_records`
--

LOCK TABLES `gpa_records` WRITE;
/*!40000 ALTER TABLE `gpa_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `gpa_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grades`
--

DROP TABLE IF EXISTS `grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grades` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `assignment_score` decimal(5,2) DEFAULT NULL,
  `quiz_score` decimal(5,2) DEFAULT NULL,
  `attendance_score` decimal(5,2) DEFAULT NULL,
  `midterm_score` decimal(5,2) DEFAULT NULL,
  `final_exam_score` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(5,2) DEFAULT NULL,
  `letter_grade` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grade_point` decimal(3,2) DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grades_student_course_semester_unique` (`student_id`,`course_id`,`semester_id`),
  KEY `grades_semester_id_foreign` (`semester_id`),
  KEY `grades_student_semester_index` (`student_id`,`semester_id`),
  KEY `grades_course_semester_index` (`course_id`,`semester_id`),
  KEY `grades_course_offering_id_foreign` (`course_offering_id`),
  CONSTRAINT `grades_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `grades_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grades_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `grades_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=401 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grades`
--

LOCK TABLES `grades` WRITE;
/*!40000 ALTER TABLE `grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `incidents`
--

DROP TABLE IF EXISTS `incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incidents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `incident_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Academic Risk',
  `priority` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Medium Priority',
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Open',
  `student_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `reporter_id` bigint unsigned DEFAULT NULL,
  `assigned_to` bigint unsigned DEFAULT NULL,
  `resolution_notes` text COLLATE utf8mb4_unicode_ci,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incidents_incident_code_unique` (`incident_code`),
  KEY `incidents_student_id_foreign` (`student_id`),
  KEY `incidents_department_id_foreign` (`department_id`),
  KEY `incidents_reporter_id_foreign` (`reporter_id`),
  KEY `incidents_assigned_to_foreign` (`assigned_to`),
  KEY `incidents_status_priority_index` (`status`,`priority`),
  KEY `incidents_category_index` (`category`),
  CONSTRAINT `incidents_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incidents_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incidents_reporter_id_foreign` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incidents_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `incidents`
--

LOCK TABLES `incidents` WRITE;
/*!40000 ALTER TABLE `incidents` DISABLE KEYS */;
/*!40000 ALTER TABLE `incidents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `learning_analytics`
--

DROP TABLE IF EXISTS `learning_analytics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_analytics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `period_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'semester',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `attendance_rate` decimal(5,2) DEFAULT NULL,
  `assignment_average` decimal(5,2) DEFAULT NULL,
  `quiz_average` decimal(5,2) DEFAULT NULL,
  `exam_average` decimal(5,2) DEFAULT NULL,
  `overall_performance` decimal(5,2) DEFAULT NULL,
  `performance_change` decimal(6,2) DEFAULT NULL,
  `learning_trend` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `strengths` json DEFAULT NULL,
  `weaknesses` json DEFAULT NULL,
  `analytics_summary` text COLLATE utf8mb4_unicode_ci,
  `calculated_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `analytics_student_period_unique` (`student_id`,`period_type`,`period_start`),
  KEY `learning_analytics_semester_id_foreign` (`semester_id`),
  KEY `analytics_student_semester_index` (`student_id`,`semester_id`),
  KEY `analytics_period_index` (`period_type`,`period_start`),
  KEY `learning_analytics_learning_trend_index` (`learning_trend`),
  CONSTRAINT `learning_analytics_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `learning_analytics_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `learning_analytics`
--

LOCK TABLES `learning_analytics` WRITE;
/*!40000 ALTER TABLE `learning_analytics` DISABLE KEYS */;
/*!40000 ALTER TABLE `learning_analytics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_07_23_182506_create_students_table',1),(5,'2026_07_23_183254_create_personal_access_tokens_table',1),(6,'2026_09_04_154708_create_teachers_table',1),(7,'2026_09_04_154717_create_admins_table',1),(8,'2026_09_16_000001_add_profile_picture_to_users_table',1),(9,'2026_09_19_000000_create_sessions_table',1),(10,'2026_09_28_000001_create_courses_table',1),(11,'2026_09_28_000002_create_assignments_table',1),(12,'2026_09_28_000003_add_academic_fields_to_courses_table',1),(13,'2026_09_28_000004_create_course_student_table',1),(14,'2026_10_02_000001_add_class_schedule_to_courses_table',1),(15,'2026_10_02_000002_create_classes_table',1),(16,'2026_10_02_000003_create_attendances_table',1),(17,'2026_10_02_000004_add_assignment_availability_window',1),(18,'2026_10_02_000005_add_assignment_type_and_retakes',1),(19,'2026_10_02_000006_create_assignment_questions_table',1),(20,'2026_10_02_000007_create_assignment_attempts_table',1),(21,'2026_10_02_000008_add_assignment_attempt_student_index',1),(22,'2026_10_03_000001_add_grading_audit_fields_to_assignment_attempts',1),(23,'2026_10_05_100001_add_status_and_verification_to_users_table',1),(24,'2026_10_05_100002_create_academic_years_table',1),(25,'2026_10_05_100003_create_departments_table',1),(26,'2026_10_05_100004_create_semesters_table',1),(27,'2026_10_05_100005_create_classes_table',1),(28,'2026_10_05_100006_alter_students_table_for_slas_structure',1),(29,'2026_10_05_100007_alter_teachers_table_for_slas_structure',1),(30,'2026_10_05_100008_alter_admins_table_for_slas_structure',1),(31,'2026_10_05_100009_create_courses_table',1),(32,'2026_10_05_100010_create_course_enrollments_table',1),(33,'2026_10_05_100011_create_attendance_table',1),(34,'2026_10_05_100012_create_attendance_details_table',1),(35,'2026_10_05_100013_create_assignments_table',1),(36,'2026_10_05_100014_create_assignment_submissions_table',1),(37,'2026_10_05_100015_create_quizzes_table',1),(38,'2026_10_05_100016_create_quiz_questions_table',1),(39,'2026_10_05_100017_create_quiz_attempts_table',1),(40,'2026_10_05_100018_create_quiz_answers_table',1),(41,'2026_10_05_100019_create_exams_table',1),(42,'2026_10_05_100020_create_exam_results_table',1),(43,'2026_10_05_100021_create_grades_table',1),(44,'2026_10_05_100022_create_gpa_records_table',1),(45,'2026_10_05_100023_create_performance_records_table',1),(46,'2026_10_05_100024_create_learning_analytics_table',1),(47,'2026_10_05_100025_create_predictions_table',1),(48,'2026_10_05_100026_create_risk_assessments_table',1),(49,'2026_10_05_100027_create_recommendations_table',1),(50,'2026_10_05_100028_create_notifications_table',1),(51,'2026_10_05_100029_create_reports_table',1),(52,'2026_10_10_000001_add_deleted_at_to_courses_table',2),(53,'2026_11_05_000001_create_class_teacher_table',3),(54,'2026_11_05_000002_create_faculty_reviews_table',3),(55,'2026_11_05_100001_create_programs_and_extend_students',3),(56,'2026_11_05_100002_create_student_family_and_emergency_tables',3),(57,'2026_11_05_100003_create_student_imports_tables',3),(58,'2026_11_11_100001_create_course_offerings_and_assignments_tables',4),(59,'2026_11_16_000001_create_student_notes_table',5),(60,'2026_11_17_000001_enhance_teacher_profile_fields',5),(61,'2026_11_18_000001_create_incidents_table',6),(62,'2026_11_21_000001_add_course_offering_to_exams_table',7),(63,'2026_11_21_000002_create_buildings_floors_and_class_schedules_tables',8);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_read_index` (`user_id`,`is_read`),
  KEY `notifications_user_created_index` (`user_id`,`created_at`),
  KEY `notifications_reference_index` (`reference_type`,`reference_id`),
  KEY `notifications_type_index` (`type`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `office_locations`
--

DROP TABLE IF EXISTS `office_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `office_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `building` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `room_number` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `office_locations_display_name_unique` (`display_name`),
  KEY `office_locations_created_by_foreign` (`created_by`),
  KEY `office_locations_building_room_index` (`building`,`room_number`),
  CONSTRAINT `office_locations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `office_locations`
--

LOCK TABLES `office_locations` WRITE;
/*!40000 ALTER TABLE `office_locations` DISABLE KEYS */;
INSERT INTO `office_locations` VALUES (1,'Building A','Room 101','Building A, Room 101',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(2,'Building A','Room 102','Building A, Room 102',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(3,'Building A','Room 103','Building A, Room 103',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(4,'Building A','Room 201','Building A, Room 201',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(5,'Building A','Room 202','Building A, Room 202',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(6,'Building A','Room 203','Building A, Room 203',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(7,'Building B','Room 101','Building B, Room 101',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(8,'Building B','Room 102','Building B, Room 102',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(9,'Building B','Room 201','Building B, Room 201',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(10,'Building B','Room 202','Building B, Room 202',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(11,'Building C','Room 101','Building C, Room 101',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(12,'Building C','Room 102','Building C, Room 102',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(13,'Faculty Center','Office 101','Faculty Center, Office 101',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(14,'Faculty Center','Office 102','Faculty Center, Office 102',NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37');
/*!40000 ALTER TABLE `office_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_records`
--

DROP TABLE IF EXISTS `performance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `period_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'semester',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `attendance_percentage` decimal(5,2) DEFAULT NULL,
  `assignment_percentage` decimal(5,2) DEFAULT NULL,
  `quiz_percentage` decimal(5,2) DEFAULT NULL,
  `exam_percentage` decimal(5,2) DEFAULT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `performance_level` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `performance_student_course_period_unique` (`student_id`,`course_id`,`period_type`,`period_start`),
  KEY `performance_records_semester_id_foreign` (`semester_id`),
  KEY `performance_student_semester_index` (`student_id`,`semester_id`),
  KEY `performance_course_semester_index` (`course_id`,`semester_id`),
  KEY `performance_period_index` (`period_type`,`period_start`),
  CONSTRAINT `performance_records_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_records_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_records_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=401 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_records`
--

LOCK TABLES `performance_records` WRITE;
/*!40000 ALTER TABLE `performance_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `performance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=275 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (4,'App\\Models\\User',1,'api-token','2578151fab85bf03f83d2d8150f770b08d10345e4c2227324dad6065897eecbd','[\"*\"]','2026-10-07 08:04:57',NULL,'2026-10-07 08:04:57','2026-10-07 08:04:57'),(12,'App\\Models\\User',1,'api-token','294baaea5c4344ea924cf0405552c9e10fe2dbe250bea2512e82c156864966d2','[\"*\"]',NULL,NULL,'2026-10-21 22:47:43','2026-10-21 22:47:43'),(17,'App\\Models\\User',1,'api-token','9a9332a9eb384038b3dbc26d2b7e71e02fb0d325fe93ea7ab5c55a4135860764','[\"*\"]',NULL,NULL,'2026-10-22 12:25:30','2026-10-22 12:25:30'),(19,'App\\Models\\User',1,'api-token','d3662230355b918020d2d9141cc07333831604e94f22763957830fb92eae24da','[\"*\"]',NULL,NULL,'2026-10-23 18:58:23','2026-10-23 18:58:23'),(22,'App\\Models\\User',1,'api-token','86c955665bfd04399f7e1511bdab9ecf620e20c8764e68b2576083e0a1e14dad','[\"*\"]',NULL,NULL,'2026-10-25 01:15:09','2026-10-25 01:15:09'),(27,'App\\Models\\User',1,'api-token','d71cc139a9ecaa65aaa6d0c9ab89a1adb0c183fa638ab6090be8753377f93c5a','[\"*\"]','2026-10-26 08:45:29',NULL,'2026-10-26 07:25:42','2026-10-26 08:45:29'),(37,'App\\Models\\User',1,'api-token','3e65db9f749b9ae749d5b0d27cafd1d04044d11ce85c19ca0151f9397fec87a5','[\"*\"]','2026-10-30 02:26:17',NULL,'2026-10-30 02:09:44','2026-10-30 02:26:17'),(38,'App\\Models\\User',1,'api-token','7204c065358f1a066310f5b67c9317e727ee0edbcc92309603aecc9e29b0afb4','[\"*\"]','2026-10-30 02:31:18',NULL,'2026-10-30 02:26:29','2026-10-30 02:31:18'),(40,'App\\Models\\User',1,'api-token','ac787e3f4799c860e8859157fbcae57283140a2480a36c83edc7269e1f2a1dc6','[\"*\"]','2026-10-30 03:07:47',NULL,'2026-10-30 02:36:49','2026-10-30 03:07:47'),(42,'App\\Models\\User',1,'api-token','cfd66c8d5b88cd8208380fb02e0d461048d6e3b8d8151f681c80066a61f7f7ba','[\"*\"]','2026-11-01 06:24:20',NULL,'2026-10-30 11:02:22','2026-11-01 06:24:20'),(45,'App\\Models\\User',1,'api-token','e5c1b9420ab0adedc17071b4b64264d6fdad89c1e997218263adb25537189bdf','[\"*\"]','2026-11-01 07:23:21',NULL,'2026-11-01 07:23:21','2026-11-01 07:23:21'),(52,'App\\Models\\User',1,'api-token','62f0a2e7acf4abb165b016bc8be78c84d400685abf9af9d922e625af8c46667f','[\"*\"]','2026-11-01 22:36:37',NULL,'2026-11-01 22:29:05','2026-11-01 22:36:37'),(54,'App\\Models\\User',1,'api-token','0ea6bedd8ae7059dfc2e3f79aeb71fe69b036923d3947166c63ff6185f7b4e1a','[\"*\"]','2026-11-02 01:06:19',NULL,'2026-11-01 23:22:08','2026-11-02 01:06:19'),(56,'App\\Models\\User',1,'api-token','2347f0b2ab79d37d03edd2f66ffe6935f1f50da5c9c18ce9c1045fd00f790df4','[\"*\"]','2026-11-02 05:54:01',NULL,'2026-11-02 04:22:34','2026-11-02 05:54:01'),(58,'App\\Models\\User',1,'api-token','c5606bdc70a48154920e52f31ecb1bbe92026bebc18f6fe1ff1612a27c299ba7','[\"*\"]','2026-11-02 05:04:38',NULL,'2026-11-02 04:53:27','2026-11-02 05:04:38'),(60,'App\\Models\\User',1,'api-token','364f3928d44facaa34935e507f4ec2ceae4eb6d761ff2f40e3da94b9355973e7','[\"*\"]','2026-11-05 08:40:17',NULL,'2026-11-02 05:58:57','2026-11-05 08:40:17'),(62,'App\\Models\\User',1,'api-token','bc39998387e16f765855593bc5e7974730cd97d53c513277b4b0cc1d782edc0b','[\"*\"]','2026-11-05 10:38:40',NULL,'2026-11-05 10:38:39','2026-11-05 10:38:40'),(64,'App\\Models\\User',1,'api-token','a1b89cb8ab3019c8067d940bcac09828fb49808b15b8f39a5b81fa8df58b173b','[\"*\"]','2026-11-06 02:45:29',NULL,'2026-11-05 18:01:21','2026-11-06 02:45:29'),(92,'App\\Models\\User',1,'api-token','12c3458146c2670af6af12acf53c2b44d41ff958f4736b7a0018bb70775bd904','[\"*\"]',NULL,NULL,'2026-11-06 02:54:40','2026-11-06 02:54:40'),(93,'App\\Models\\User',1,'api-token','47de6e2d144cb6cce33de6e4c319674befc2cf9333155ff4003fd83d40171efd','[\"*\"]',NULL,NULL,'2026-11-06 02:54:42','2026-11-06 02:54:42'),(96,'App\\Models\\User',1,'api-token','af42630cd889c3dfbe0732e911439e01b39a0445a5d2896a669682d458f1ae7d','[\"*\"]','2026-11-07 06:40:47',NULL,'2026-11-07 06:40:47','2026-11-07 06:40:47'),(98,'App\\Models\\User',1,'api-token','8bc4b5747dde16c741b357ded1aaec437282154b6fc65f8e3295f7da5e65a94f','[\"*\"]','2026-11-08 09:05:34',NULL,'2026-11-07 06:41:02','2026-11-08 09:05:34'),(102,'App\\Models\\User',1,'api-token','e71c4a5fe3851bd4582f87d04be198c0c3e4178d9f583f53690d2f6a2cea8e11','[\"*\"]','2026-11-09 08:01:26',NULL,'2026-11-09 08:01:23','2026-11-09 08:01:26'),(108,'App\\Models\\User',1,'api-token','ef2f278067f210f709b96ce204bb5e7253830589606454fb52f15763de6ee325','[\"*\"]','2026-11-15 19:23:14',NULL,'2026-11-14 05:41:40','2026-11-15 19:23:14'),(109,'App\\Models\\User',1,'api-token','efd05a92a954a4957963658679824930b0b78ebe2beb7ffe115deafcfd16c8b1','[\"*\"]','2026-11-15 19:24:30',NULL,'2026-11-15 19:23:46','2026-11-15 19:24:30'),(110,'App\\Models\\User',1,'api-token','72f0a7cd708d94fa185c80a7be1d57dc6be387ead11645e73c25fde0832d2cd5','[\"*\"]','2026-11-15 20:13:47',NULL,'2026-11-15 19:34:12','2026-11-15 20:13:47'),(111,'App\\Models\\User',34,'api-token','d0899569a96ccc3906f63ff4b423348f802ad2aa235e5aa6f922df772429058d','[\"*\"]',NULL,NULL,'2026-11-15 19:43:50','2026-11-15 19:43:50'),(112,'App\\Models\\User',1,'api-token','866f264161c42f6604fcc851be762977e1b437af194ceb9e16f3d9b5a7e70409','[\"*\"]','2026-11-16 08:01:07',NULL,'2026-11-15 20:19:34','2026-11-16 08:01:07'),(113,'App\\Models\\User',1,'api-token','9f36144bbf71c9ea38a3e2af3cfa2f5ca9e4a0c7e6755cf8563e6ced4092978b','[\"*\"]','2026-11-16 08:07:37',NULL,'2026-11-16 08:01:45','2026-11-16 08:07:37'),(116,'App\\Models\\User',1,'api-token','8b3243a2ca3caef9181ee67fd67a869fc5bf9932137223bd447b708b0d4b0c77','[\"*\"]','2026-11-17 02:22:50',NULL,'2026-11-17 00:05:00','2026-11-17 02:22:50'),(130,'App\\Models\\User',1,'api-token','2d76238715e68c7d16fef1b425cc8ed9ac7a8645ff6f246f06c81ec453657928','[\"*\"]','2026-11-17 03:01:54',NULL,'2026-11-17 02:43:58','2026-11-17 03:01:54'),(139,'App\\Models\\User',1,'api-token','f500036e6d0fa24a5833f82b0c4d1efacd10d77bdbc36b9e40a8f9f898ee0ae9','[\"*\"]','2026-11-17 03:49:26',NULL,'2026-11-17 03:03:50','2026-11-17 03:49:26'),(140,'App\\Models\\User',1,'api-token','4168837b5ef566174daafcc5727f1832968aee92f6c882c651aeb3cce642153f','[\"*\"]','2026-11-17 04:04:27',NULL,'2026-11-17 04:04:24','2026-11-17 04:04:27'),(142,'App\\Models\\User',1,'api-token','ed882d204551c50372b647821f494add5e4670f22429be20de0f85fde82f3748','[\"*\"]','2026-11-17 05:26:37',NULL,'2026-11-17 04:09:06','2026-11-17 05:26:37'),(154,'App\\Models\\User',1,'api-token','c16bda1102477917d804d077ebb0a1c57cd6968e7bc7ab66794c40b7c9c675a8','[\"*\"]','2026-11-18 01:25:01',NULL,'2026-11-17 05:27:48','2026-11-18 01:25:01'),(157,'App\\Models\\User',1,'api-token','4b9cec7aa39d211faa0dbcc728957a5cf6fed4e3036db0d64f488b9f93d4c451','[\"*\"]','2026-11-18 02:27:12',NULL,'2026-11-18 02:25:31','2026-11-18 02:27:12'),(159,'App\\Models\\User',1,'api-token','23acddc10f3052361a7fa6ee2ef673a61b7fea46853e4f0a448327ab67e1e42c','[\"*\"]','2026-11-18 04:07:44',NULL,'2026-11-18 04:02:41','2026-11-18 04:07:44'),(160,'App\\Models\\User',1,'api-token','f3935184ffe13cc752f6da2a6a77d2dddd824e28292e23ea4413fd11c96ec5ac','[\"*\"]','2026-11-18 04:11:00',NULL,'2026-11-18 04:10:57','2026-11-18 04:11:00'),(161,'App\\Models\\User',1,'api-token','e3330a7353de3bf75bc60469e0ea68f8026b632b1813b234395b2d0d6a9b9a10','[\"*\"]','2026-11-18 04:18:26',NULL,'2026-11-18 04:17:32','2026-11-18 04:18:26'),(162,'App\\Models\\User',1,'api-token','36ea97ffda6ce37fa7139a86f6b21987011d960f6227126cdd773ef17664dab8','[\"*\"]','2026-11-18 04:33:27',NULL,'2026-11-18 04:19:40','2026-11-18 04:33:27'),(163,'App\\Models\\User',1,'api-token','f856ae18a48478c5119560b24f2fad62632e1f7467c3a21abc61416f7270a926','[\"*\"]','2026-11-18 04:37:28',NULL,'2026-11-18 04:34:32','2026-11-18 04:37:28'),(164,'App\\Models\\User',1,'api-token','f2b7f43a261c376f0a2deeb9743b2131a54ab85de31b254888e691e939cf49cf','[\"*\"]','2026-11-18 06:19:56',NULL,'2026-11-18 04:37:54','2026-11-18 06:19:56'),(165,'App\\Models\\User',1,'api-token','123190dd3ee8b6fc4280aaaa66ea775ae1958bab11f027116e212c981f1df694','[\"*\"]','2026-11-18 06:29:04',NULL,'2026-11-18 06:20:36','2026-11-18 06:29:04'),(168,'App\\Models\\User',1,'api-token','140050b3576fa5c9c447427a67dd20ea6cf40a0958aeb8be4288942cd6121c1a','[\"*\"]','2026-11-18 06:50:04',NULL,'2026-11-18 06:50:00','2026-11-18 06:50:04'),(169,'App\\Models\\User',1,'api-token','e88fd60c2cb806b465ea3e858dcf3c319473c786dc89870373683e4c8135ab9a','[\"*\"]','2026-11-18 06:55:47',NULL,'2026-11-18 06:55:45','2026-11-18 06:55:47'),(170,'App\\Models\\User',1,'api-token','674a829fa694026244cbfaf29628b6ac384333d351b2502e4e85b5a4561813fb','[\"*\"]','2026-11-18 07:02:12',NULL,'2026-11-18 06:56:45','2026-11-18 07:02:12'),(174,'App\\Models\\User',1,'api-token','0fec1d301d30affe4ec4357f20791a1fa2c2d07f307df1e2657bedb28f62299c','[\"*\"]','2026-11-18 07:58:56',NULL,'2026-11-18 07:57:32','2026-11-18 07:58:56'),(176,'App\\Models\\User',1,'api-token','b972430c44ead008455969a5ff4ca57d9a6d970a3ead24762290abaa0aea79d6','[\"*\"]','2026-11-18 08:03:41',NULL,'2026-11-18 08:02:14','2026-11-18 08:03:41'),(177,'App\\Models\\User',1,'api-token','9a4f3607240a27cb15e6297a8912dc42bb4a0165820cea6e9537d1f45367941a','[\"*\"]','2026-11-18 08:10:20',NULL,'2026-11-18 08:04:41','2026-11-18 08:10:20'),(258,'App\\Models\\User',1,'api-token','103b0bae6e6cb0a0efe6b5378422e5e5c497034772c1bc62a1d0a923ddc84bf3','[\"*\"]','2026-11-20 19:13:04',NULL,'2026-11-20 12:55:02','2026-11-20 19:13:04'),(270,'App\\Models\\User',1,'api-token','7f1cd9039b48e6ddadb2e39594d84d5a559b796e6fd73ee6dd35edee9fa2a01d','[\"*\"]','2026-11-20 19:15:31',NULL,'2026-11-20 19:14:44','2026-11-20 19:15:31'),(271,'App\\Models\\User',1,'api-token','6826e27927de7eec2610affe3d439015eed0af36de086ef0cd6df8dfb84de662','[\"*\"]','2026-11-20 20:52:23',NULL,'2026-11-20 19:16:24','2026-11-20 20:52:23'),(273,'App\\Models\\User',67,'api-token','1fce155fb0577b5dbf36287d59c68bf90a74d250c407d7e24319f9a45a2a831a','[\"*\"]',NULL,NULL,'2026-11-20 21:03:40','2026-11-20 21:03:40'),(274,'App\\Models\\User',67,'api-token','673c5da1b7f0b91f8f85c306080dcebc8f5261555b4d735cf500a51f2f22cfa5','[\"*\"]',NULL,NULL,'2026-11-20 21:03:42','2026-11-20 21:03:42');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `predictions`
--

DROP TABLE IF EXISTS `predictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `predictions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `prediction_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `predicted_score` decimal(6,2) NOT NULL,
  `confidence_score` decimal(5,4) DEFAULT NULL,
  `prediction_date` date NOT NULL,
  `model_version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `predictions_course_id_foreign` (`course_id`),
  KEY `predictions_semester_id_foreign` (`semester_id`),
  KEY `predictions_student_semester_index` (`student_id`,`semester_id`),
  KEY `predictions_student_course_index` (`student_id`,`course_id`),
  KEY `predictions_prediction_date_index` (`prediction_date`),
  KEY `predictions_prediction_type_index` (`prediction_type`),
  CONSTRAINT `predictions_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `predictions_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `predictions_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `predictions`
--

LOCK TABLES `predictions` WRITE;
/*!40000 ALTER TABLE `predictions` DISABLE KEYS */;
/*!40000 ALTER TABLE `predictions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `programs`
--

DROP TABLE IF EXISTS `programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `programs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_years` tinyint unsigned NOT NULL DEFAULT '4',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `programs_code_unique` (`code`),
  KEY `programs_department_id_foreign` (`department_id`),
  KEY `programs_status_index` (`status`),
  CONSTRAINT `programs_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `programs`
--

LOCK TABLES `programs` WRITE;
/*!40000 ALTER TABLE `programs` DISABLE KEYS */;
INSERT INTO `programs` VALUES (1,1,'BSCS','Bachelor of Science in Computer Science',4,'active','2026-11-04 20:20:27','2026-11-04 20:20:27'),(2,1,'BSE','Bachelor of Software Engineering',4,'active','2026-11-04 20:20:27','2026-11-04 20:20:27'),(3,2,'BSIT','Bachelor of Science in Information Technology',4,'active','2026-11-04 20:20:27','2026-11-04 20:20:27'),(4,3,'BBA','Bachelor of Business Administration',4,'active','2026-11-04 20:20:27','2026-11-04 20:20:27'),(5,1,'CS','Computer Science',4,'active','2026-11-06 00:30:22','2026-11-06 00:30:22'),(6,1,'SE','Software Engineering',4,'active','2026-11-06 00:30:22','2026-11-06 00:30:22'),(7,2,'IT','Information Technology',4,'active','2026-11-06 00:30:22','2026-11-06 00:30:22'),(8,3,'BA','Business Administration',4,'active','2026-11-06 00:30:22','2026-11-06 00:30:22');
/*!40000 ALTER TABLE `programs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_answers`
--

DROP TABLE IF EXISTS `quiz_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quiz_answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quiz_attempt_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `answer` text COLLATE utf8mb4_unicode_ci,
  `is_correct` tinyint(1) DEFAULT NULL,
  `marks_obtained` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quiz_answers_attempt_question_unique` (`quiz_attempt_id`,`question_id`),
  KEY `quiz_answers_question_id_foreign` (`question_id`),
  CONSTRAINT `quiz_answers_question_id_foreign` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_answers_quiz_attempt_id_foreign` FOREIGN KEY (`quiz_attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=439 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_answers`
--

LOCK TABLES `quiz_answers` WRITE;
/*!40000 ALTER TABLE `quiz_answers` DISABLE KEYS */;
/*!40000 ALTER TABLE `quiz_answers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_attempts`
--

DROP TABLE IF EXISTS `quiz_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quiz_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quiz_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `attempt_number` tinyint unsigned NOT NULL DEFAULT '1',
  `started_at` datetime NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quiz_attempts_quiz_student_attempt_unique` (`quiz_id`,`student_id`,`attempt_number`),
  KEY `quiz_attempts_student_status_index` (`student_id`,`status`),
  CONSTRAINT `quiz_attempts_quiz_id_foreign` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_attempts_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=634 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_attempts`
--

LOCK TABLES `quiz_attempts` WRITE;
/*!40000 ALTER TABLE `quiz_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `quiz_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_questions`
--

DROP TABLE IF EXISTS `quiz_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quiz_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quiz_id` bigint unsigned NOT NULL,
  `question_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `question_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'multiple_choice',
  `marks` decimal(5,2) NOT NULL DEFAULT '1.00',
  `question_order` smallint unsigned NOT NULL DEFAULT '1',
  `option_a` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `option_b` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `option_c` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `option_d` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correct_answer` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quiz_questions_quiz_order_unique` (`quiz_id`,`question_order`),
  CONSTRAINT `quiz_questions_quiz_id_foreign` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_questions`
--

LOCK TABLES `quiz_questions` WRITE;
/*!40000 ALTER TABLE `quiz_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `quiz_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quizzes`
--

DROP TABLE IF EXISTS `quizzes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quizzes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `course_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `total_score` decimal(6,2) NOT NULL DEFAULT '100.00',
  `duration_minutes` smallint unsigned DEFAULT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quizzes_teacher_id_foreign` (`teacher_id`),
  KEY `quizzes_semester_id_foreign` (`semester_id`),
  KEY `quizzes_course_semester_index` (`course_id`,`semester_id`),
  KEY `quizzes_start_at_end_at_index` (`start_at`,`end_at`),
  KEY `quizzes_status_index` (`status`),
  KEY `quizzes_course_offering_id_foreign` (`course_offering_id`),
  CONSTRAINT `quizzes_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `quizzes_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quizzes_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `quizzes_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quizzes`
--

LOCK TABLES `quizzes` WRITE;
/*!40000 ALTER TABLE `quizzes` DISABLE KEYS */;
/*!40000 ALTER TABLE `quizzes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recommendations`
--

DROP TABLE IF EXISTS `recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recommendations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `generated_by` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recommendations_course_id_foreign` (`course_id`),
  KEY `recommendations_semester_id_foreign` (`semester_id`),
  KEY `recommendations_student_status_index` (`student_id`,`status`),
  KEY `recommendations_priority_index` (`priority`),
  KEY `recommendations_type_index` (`type`),
  CONSTRAINT `recommendations_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recommendations_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recommendations_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recommendations`
--

LOCK TABLES `recommendations` WRITE;
/*!40000 ALTER TABLE `recommendations` DISABLE KEYS */;
/*!40000 ALTER TABLE `recommendations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reports`
--

DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned DEFAULT NULL,
  `teacher_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `report_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generated_by` bigint unsigned DEFAULT NULL,
  `generated_at` datetime DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reports_semester_id_foreign` (`semester_id`),
  KEY `reports_generated_by_foreign` (`generated_by`),
  KEY `reports_report_type_index` (`report_type`),
  KEY `reports_student_type_index` (`student_id`,`report_type`),
  KEY `reports_teacher_type_index` (`teacher_id`,`report_type`),
  KEY `reports_status_index` (`status`),
  CONSTRAINT `reports_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reports_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reports_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reports_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reports`
--

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
INSERT INTO `reports` VALUES (1,NULL,NULL,NULL,'student_performance','Semester Academic Performance Summary','Overall academic performance report across all departments','reports/semester_summary_2026.pdf',1,'2026-11-02 13:35:36','generated','2026-10-05 11:13:14','2026-11-02 06:35:36');
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `risk_assessments`
--

DROP TABLE IF EXISTS `risk_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `risk_assessments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_offering_id` bigint unsigned DEFAULT NULL,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned NOT NULL,
  `risk_level` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `risk_score` decimal(5,2) NOT NULL,
  `risk_factors` json DEFAULT NULL,
  `assessment_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `risk_assessments_course_id_foreign` (`course_id`),
  KEY `risk_assessments_risk_level_index` (`risk_level`),
  KEY `risk_student_semester_index` (`student_id`,`semester_id`),
  KEY `risk_semester_level_index` (`semester_id`,`risk_level`),
  KEY `risk_assessments_assessment_date_index` (`assessment_date`),
  KEY `risk_assessments_course_offering_id_foreign` (`course_offering_id`),
  CONSTRAINT `risk_assessments_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `risk_assessments_course_offering_id_foreign` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `risk_assessments_semester_id_foreign` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `risk_assessments_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `risk_assessments`
--

LOCK TABLES `risk_assessments` WRITE;
/*!40000 ALTER TABLE `risk_assessments` DISABLE KEYS */;
/*!40000 ALTER TABLE `risk_assessments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `semesters`
--

DROP TABLE IF EXISTS `semesters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `semesters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `academic_year_id` bigint unsigned NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `semester_number` tinyint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upcoming',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `semesters_year_number_unique` (`academic_year_id`,`semester_number`),
  KEY `semesters_status_index` (`status`),
  KEY `semesters_start_date_end_date_index` (`start_date`,`end_date`),
  CONSTRAINT `semesters_academic_year_id_foreign` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `semesters`
--

LOCK TABLES `semesters` WRITE;
/*!40000 ALTER TABLE `semesters` DISABLE KEYS */;
INSERT INTO `semesters` VALUES (1,1,'Semester 1',1,'2025-09-01','2026-01-31','completed','2026-10-05 11:13:04','2026-10-05 11:13:04'),(2,1,'Semester 2',2,'2026-02-01','2026-06-30','completed','2026-10-05 11:13:04','2026-10-05 11:13:04'),(3,2,'Semester 1',1,'2026-09-01','2027-01-31','active','2026-10-05 11:13:04','2026-10-05 11:13:04'),(4,2,'Semester 2',2,'2027-02-01','2027-06-30','upcoming','2026-10-05 11:13:04','2026-10-05 11:13:04'),(5,3,'Semester 1',1,'2023-09-01','2024-01-31','completed','2026-11-02 06:06:46','2026-11-02 06:06:46'),(6,3,'Semester 2',2,'2024-02-01','2024-06-30','completed','2026-11-02 06:06:46','2026-11-02 06:06:46'),(7,4,'Semester 1',1,'2024-09-01','2025-01-31','completed','2026-11-02 06:06:46','2026-11-02 06:06:46'),(8,4,'Semester 2',2,'2025-02-01','2025-06-30','completed','2026-11-02 06:06:46','2026-11-02 06:06:46'),(9,5,'Semester 1',1,'2027-09-01','2028-01-31','upcoming','2026-11-02 06:06:46','2026-11-02 06:06:46'),(10,5,'Semester 2',2,'2028-02-01','2028-06-30','upcoming','2026-11-02 06:06:46','2026-11-02 06:06:46');
/*!40000 ALTER TABLE `semesters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `specializations`
--

DROP TABLE IF EXISTS `specializations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `specializations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `specializations_name_unique` (`name`),
  KEY `specializations_department_id_foreign` (`department_id`),
  KEY `specializations_created_by_foreign` (`created_by`),
  CONSTRAINT `specializations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `specializations_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `specializations`
--

LOCK TABLES `specializations` WRITE;
/*!40000 ALTER TABLE `specializations` DISABLE KEYS */;
INSERT INTO `specializations` VALUES (1,'Algorithms',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(2,'Artificial Intelligence',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(3,'Business Analytics',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(4,'Cloud Computing',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(5,'Cybersecurity',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(6,'Data Science',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(7,'Data Structures',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(8,'Database Systems',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(9,'Databases',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(10,'Machine Learning',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(11,'Mobile Development',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(12,'Networking',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(13,'Operating Systems',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(14,'Software Engineering',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37'),(15,'Web Development',NULL,NULL,'2026-11-17 04:30:37','2026-11-17 04:30:37');
/*!40000 ALTER TABLE `specializations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_emergency_contacts`
--

DROP TABLE IF EXISTS `student_emergency_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_emergency_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `relationship` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_emergency_contacts_student_id_index` (`student_id`),
  CONSTRAINT `student_emergency_contacts_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_emergency_contacts`
--

LOCK TABLES `student_emergency_contacts` WRITE;
/*!40000 ALTER TABLE `student_emergency_contacts` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_emergency_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_family_information`
--

DROP TABLE IF EXISTS `student_family_information`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_family_information` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `father_khmer_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `father_english_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `father_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_khmer_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_english_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_family_information_student_id_unique` (`student_id`),
  CONSTRAINT `student_family_information_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_family_information`
--

LOCK TABLES `student_family_information` WRITE;
/*!40000 ALTER TABLE `student_family_information` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_family_information` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_import_rows`
--

DROP TABLE IF EXISTS `student_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_import_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_import_id` bigint unsigned NOT NULL,
  `row_number` int unsigned NOT NULL,
  `student_identifier` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'valid',
  `data` json DEFAULT NULL,
  `errors` json DEFAULT NULL,
  `temp_password_encrypted` text COLLATE utf8mb4_unicode_ci,
  `student_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_import_rows_unique` (`student_import_id`,`row_number`),
  KEY `student_import_rows_student_id_foreign` (`student_id`),
  KEY `student_import_rows_student_import_id_status_index` (`student_import_id`,`status`),
  KEY `student_import_rows_student_identifier_index` (`student_identifier`),
  CONSTRAINT `student_import_rows_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_import_rows_student_import_id_foreign` FOREIGN KEY (`student_import_id`) REFERENCES `student_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_import_rows`
--

LOCK TABLES `student_import_rows` WRITE;
/*!40000 ALTER TABLE `student_import_rows` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_import_rows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_imports`
--

DROP TABLE IF EXISTS `student_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_rows` int unsigned NOT NULL DEFAULT '0',
  `valid_rows` int unsigned NOT NULL DEFAULT '0',
  `invalid_rows` int unsigned NOT NULL DEFAULT '0',
  `duplicate_rows` int unsigned NOT NULL DEFAULT '0',
  `processed_rows` int unsigned NOT NULL DEFAULT '0',
  `success_rows` int unsigned NOT NULL DEFAULT '0',
  `failed_rows` int unsigned NOT NULL DEFAULT '0',
  `skipped_rows` int unsigned NOT NULL DEFAULT '0',
  `cancel_requested` tinyint(1) NOT NULL DEFAULT '0',
  `failure_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_imports_status_created_at_index` (`status`,`created_at`),
  KEY `student_imports_user_id_index` (`user_id`),
  CONSTRAINT `student_imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_imports`
--

LOCK TABLES `student_imports` WRITE;
/*!40000 ALTER TABLE `student_imports` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_imports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_notes`
--

DROP TABLE IF EXISTS `student_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `author_id` bigint unsigned NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_notes_author_id_foreign` (`author_id`),
  KEY `student_notes_student_id_updated_at_index` (`student_id`,`updated_at`),
  CONSTRAINT `student_notes_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_notes_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_notes`
--

LOCK TABLES `student_notes` WRITE;
/*!40000 ALTER TABLE `student_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `class_id` bigint unsigned DEFAULT NULL,
  `student_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `khmer_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `english_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_birth` date NOT NULL,
  `place_of_birth` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` enum('Male','Female') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nationality` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Cambodian',
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `major` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_level` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `advisor` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enrollment_date` date DEFAULT NULL,
  `status` enum('enrolled','graduated','suspended','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enrolled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `program_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_user_id_unique` (`user_id`),
  UNIQUE KEY `students_student_code_unique` (`student_code`),
  KEY `students_department_id_foreign` (`department_id`),
  KEY `students_class_id_foreign` (`class_id`),
  KEY `students_status_index` (`status`),
  KEY `students_program_id_foreign` (`program_id`),
  CONSTRAINT `students_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `students_program_id_foreign` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (39,66,1,28,'STD0THAI','ទ្រី សុថៃ','Try Sothai','Sothai','Try','2003-03-20','Katie','Male','Cambodian',NULL,'SiemReap','Bachelor of Science in Computer Science','1',NULL,'2026-11-21','enrolled','2026-11-20 19:05:03','2026-11-20 19:12:46',NULL,1);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_specialization`
--

DROP TABLE IF EXISTS `teacher_specialization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_specialization` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` bigint unsigned NOT NULL,
  `specialization_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_specialization_unique` (`teacher_id`,`specialization_id`),
  KEY `teacher_specialization_specialization_id_foreign` (`specialization_id`),
  CONSTRAINT `teacher_specialization_specialization_id_foreign` FOREIGN KEY (`specialization_id`) REFERENCES `specializations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_specialization_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_specialization`
--

LOCK TABLES `teacher_specialization` WRITE;
/*!40000 ALTER TABLE `teacher_specialization` DISABLE KEYS */;
INSERT INTO `teacher_specialization` VALUES (13,19,6,'2026-11-20 19:09:30','2026-11-20 19:09:30');
/*!40000 ALTER TABLE `teacher_specialization` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teachers`
--

DROP TABLE IF EXISTS `teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teachers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `teacher_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('Male','Female') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `office_location_id` bigint unsigned DEFAULT NULL,
  `position` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Full-time',
  `specialization` text COLLATE utf8mb4_unicode_ci,
  `biography` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teachers_user_id_unique` (`user_id`),
  UNIQUE KEY `teachers_teacher_code_unique` (`teacher_code`),
  KEY `teachers_department_id_foreign` (`department_id`),
  KEY `teachers_status_index` (`status`),
  KEY `teachers_office_location_id_foreign` (`office_location_id`),
  CONSTRAINT `teachers_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `teachers_office_location_id_foreign` FOREIGN KEY (`office_location_id`) REFERENCES `office_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `teachers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teachers`
--

LOCK TABLES `teachers` WRITE;
/*!40000 ALTER TABLE `teachers` DISABLE KEYS */;
INSERT INTO `teachers` VALUES (19,67,1,'TCH0SHANG','Tat','Bunsang','2000-09-20','Male','1234567890',1,'Associate Professor','Full-time','Data Science','This teacher of khmer','active','2026-11-20 19:09:30','2026-11-20 19:09:30',NULL);
/*!40000 ALTER TABLE `teachers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `profile_picture` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('student','teacher','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_status_index` (`role`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin SLAS','admin@slas.com',NULL,'$2y$12$EsCuZRZtedMpaFdF2kk/xOrkaemavMGXowi/2GazNoKxvEk2HcCba',NULL,'admin','active',NULL,'2026-10-05 11:13:05','2026-10-05 11:13:05'),(16,'System Admin','admin@slas.local',NULL,'$2y$12$X8Qy04rmmcLNwzG2mIR07OlR/6D8schqGl5cd2IL4ZTolRO9frM7u',NULL,'admin','active',NULL,NULL,NULL),(66,'Try Sothai',NULL,NULL,'$2y$12$SBDOz2aqrtxJYRBSQIRUDuAaAg9h0yqhW8XkRJX.RrHUVbhsYSYi6',NULL,'student','active',NULL,'2026-11-20 19:05:02','2026-11-20 19:05:02'),(67,'Tat Bunsang','shang123@gmail.com',NULL,'$2y$12$a8aq3Mhebnl8ONPOjFbRvuFGZAZOBMZb.Cu3YMvuxrOhvEjljpaWi',NULL,'teacher','active',NULL,'2026-11-20 19:09:30','2026-11-20 19:09:30');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-21 11:12:18
