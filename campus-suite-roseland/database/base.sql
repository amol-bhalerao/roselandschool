-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: campus_suite
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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
-- Table structure for table `carousel_slides`
--

DROP TABLE IF EXISTS `carousel_slides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `carousel_slides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `title` varchar(500) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `image_url` varchar(1024) NOT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `link_post_id` int(10) unsigned DEFAULT NULL,
  `link_url` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_carousel_sort` (`sort_order`),
  KEY `fk_carousel_link_post` (`link_post_id`),
  CONSTRAINT `fk_carousel_link_post` FOREIGN KEY (`link_post_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `carousel_slides`
--

LOCK TABLES `carousel_slides` WRITE;
/*!40000 ALTER TABLE `carousel_slides` DISABLE KEYS */;
INSERT INTO `carousel_slides` VALUES (1,0,'Admissions open for BA and B.Sc.','Explore Arts and Science programs at Late Baburao Patil Arts and Science College, Hingoli.','https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=1600&q=80&auto=format&fit=crop','Admission notices, eligibility details and important dates are published on the college website.',NULL,'/p/admissions'),(2,1,'Departments and subjects','Science subjects: Botany, Microbiology, Zoology, Chemistry, Physics and Mathematics. Arts subjects: English, Hindi, Marathi, Political Science, Sociology, Geography and Economics.','https://images.unsplash.com/photo-1562774053-701939374585?w=1600&q=80&auto=format&fit=crop','Course and subject information can be edited from the admin website content tools.',NULL,'/p/about');
/*!40000 ALTER TABLE `carousel_slides` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,NULL,'Academics','academics','2026-05-20 13:19:33'),(2,NULL,'Admissions','admissions','2026-05-20 13:19:33'),(3,NULL,'Campus Life','campus-life','2026-05-20 13:19:33'),(4,NULL,'Announcements','announcements','2026-05-20 13:19:33');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_academic_years`
--

DROP TABLE IF EXISTS `erp_academic_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_academic_years` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_year_inst_name` (`institution_id`,`name`),
  CONSTRAINT `fk_erp_year_institution` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_academic_years`
--

LOCK TABLES `erp_academic_years` WRITE;
/*!40000 ALTER TABLE `erp_academic_years` DISABLE KEYS */;
INSERT INTO `erp_academic_years` VALUES (1,1,'2026-27','2026-04-01','2027-03-31',1);
/*!40000 ALTER TABLE `erp_academic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_admission_applications`
--

DROP TABLE IF EXISTS `erp_admission_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_admission_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `target_class_id` int(10) unsigned NOT NULL,
  `target_section_id` int(10) unsigned DEFAULT NULL,
  `applicant_name` varchar(180) NOT NULL,
  `guardian_name` varchar(180) NOT NULL,
  `phone` varchar(80) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `aadhar_no` varchar(20) DEFAULT NULL,
  `follow_up_at` datetime DEFAULT NULL,
  `follow_up_note` varchar(255) DEFAULT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `stage` enum('enquiry','application','screening','offer','fee_paid','enrolled','rejected','waitlisted') NOT NULL DEFAULT 'enquiry',
  `score` decimal(5,2) DEFAULT NULL,
  `source` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_admission_stage` (`stage`),
  KEY `fk_erp_app_inst` (`institution_id`),
  KEY `fk_erp_app_year` (`academic_year_id`),
  KEY `fk_erp_app_class` (`target_class_id`),
  KEY `idx_erp_admission_aadhar` (`aadhar_no`),
  KEY `fk_erp_app_section` (`target_section_id`),
  CONSTRAINT `fk_erp_app_class` FOREIGN KEY (`target_class_id`) REFERENCES `erp_classes` (`id`),
  CONSTRAINT `fk_erp_app_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_app_section` FOREIGN KEY (`target_section_id`) REFERENCES `erp_sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_app_year` FOREIGN KEY (`academic_year_id`) REFERENCES `erp_academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_admission_applications`
--

LOCK TABLES `erp_admission_applications` WRITE;
/*!40000 ALTER TABLE `erp_admission_applications` DISABLE KEYS */;
INSERT INTO `erp_admission_applications` VALUES (1,1,1,7,7,'Mira Rohan Kulkarnee','Rohan','+91 94444 44444','rohan.kulkarni@example.com','3453253245',NULL,NULL,'{\"Last Name \\/ Surname\":\"Kulkarnee\",\"First Name\":\"Mira\",\"Middle \\/ Father Name\":\"Rohan\",\"Mother Name\":\"Lata\",\"Mobile No\":\"+91 94444 44444\",\"Aadhar No\":\"3453253245\",\"UDISE No\":\"3453452345\",\"Student Saral ID\":\"546456456\",\"Admission Class\":\"BA Year 1\",\"Class Section Id\":\"7\",\"Application Sr. No\":\"APP-2026-0001\",\"Residential Address\":\"asdf sdf sdffh fdghg sfdgh dfgsd fgfasd fgasd63456435 b\",\"Date of Birth\":\"2000-11-18\",\"Minority Religion\":\"Buddhist\",\"Category\":\"SC\",\"Divyang\":\"No\",\"SSC Seat No\":\"f345343\",\"SSC Month\":\"August\",\"SSC Year\":\"2000\",\"SSC Board\\/College\":\"aurangabad\",\"HSC \\/ XIth Seat No\":\"h5324534\",\"HSC \\/ XIth Month\":\"March\",\"HSC \\/ XIth Year\":\"2000\",\"HSC \\/ XIth Board\\/College\":\"pune\",\"SSC Maharashtra Board\":\"Yes\",\"Eligibility Certificate Issued\":\"No\",\"Eligibility Certificate No\":\"\",\"Account No\":\"523543245\",\"IFSC Code\":\"sdgf23453245\",\"Account Holder\":\"Own\",\"residential_address\":\"asdf sdf sdffh fdghg sfdgh dfgsd fgfasd fgasd63456435 b\",\"date_of_birth\":\"2000-11-18\",\"ssc_details\":\"f345343 \\/ August \\/ 2000 \\/ aurangabad\",\"xith_details\":\"h5324534 \\/ March \\/ 2000 \\/ pune\",\"eligibility_certificate\":\"No\",\"admission_status\":\"Ready for fee confirmation\",\"Subject:__add_subject__\":\"selected\",\"New Subject Name\":\"math\",\"Subject:BA-ECO\":\"selected\",\"Subject:BA-ENG\":\"selected\",\"Subject:BA-HIN\":\"selected\",\"Subject:BA-MAR\":\"selected\",\"Subject:BA-POL\":\"selected\"}','enrolled',86.50,'Website','2026-05-20 13:19:34'),(2,1,1,2,NULL,'Kabir Jain','Swati Jain','+91 95555 55555','swati.jain@example.com',NULL,NULL,NULL,NULL,'offer',91.00,'Referral','2026-05-20 13:19:34'),(3,1,1,3,NULL,'Sara Sheikh','Imran Sheikh','+91 96666 66666','imran.sheikh@example.com',NULL,NULL,NULL,NULL,'fee_paid',78.25,'Walk-in','2026-05-20 13:19:34'),(5,1,1,3,NULL,'aarya amol bhalerao','amol bhalerao','70205667733','amubhalerao@gmail.com',NULL,NULL,NULL,NULL,'enrolled',0.00,'Walk-in','2026-05-20 18:11:07'),(7,1,1,7,NULL,'Workflow Smoke','Parent Smoke','+91 90000 00123','smoke@example.com','999900001111',NULL,NULL,'[]','enquiry',0.00,'Walk-in','2026-05-21 16:49:13'),(8,1,1,8,8,'Aayu Amol Bhalerao','Amol','8830350465',NULL,'343434333333',NULL,NULL,'{\"Last Name \\/ Surname\":\"Bhalerao\",\"First Name\":\"Aayu\",\"Middle \\/ Father Name\":\"Amol\",\"Mother Name\":\"Jayashree\",\"Mobile No\":\"8830350465\",\"Aadhar No\":\"343434333333\",\"UDISE No\":\"33333333333\",\"Student Saral ID\":\"343434343\",\"Admission Class\":\"BSc Year 1\",\"Class Section Id\":\"\",\"Application Sr. No\":\"APP-2026-0008\",\"Residential Address\":\"mukundwadi , aurngabd\",\"Date of Birth\":\"2025-05-08\",\"Minority Religion\":\"Buddhist\",\"Category\":\"SC\",\"Divyang\":\"No\",\"SSC Seat No\":\"j43432\",\"SSC Month\":\"February\",\"SSC Year\":\"2022\",\"SSC Board\\/College\":\"aurangabad\",\"HSC \\/ XIth Seat No\":\"k43243\",\"HSC \\/ XIth Month\":\"March\",\"HSC \\/ XIth Year\":\"2023\",\"HSC \\/ XIth Board\\/College\":\"pune\",\"SSC Maharashtra Board\":\"Yes\",\"Eligibility Certificate Issued\":\"No\",\"Eligibility Certificate No\":\"\",\"Account No\":\"5225555555\",\"IFSC Code\":\"sbin0102122\",\"Account Holder\":\"Own\",\"subjects\":[],\"residential_address\":\"mukundwadi , aurngabd\",\"date_of_birth\":\"2025-05-08\",\"ssc_details\":\"j43432 \\/ February \\/ 2022 \\/ aurangabad\",\"xith_details\":\"k43243 \\/ March \\/ 2023 \\/ pune\",\"eligibility_certificate\":\"No\",\"admission_status\":\"Ready for fee confirmation\"}','enrolled',0.00,'Walk-in','2026-05-21 16:52:33'),(9,1,1,8,7,'Minimal Enquiry Test','Not captured at enquiry','+91 90000 00456',NULL,NULL,'2026-05-22 10:30:00','Asked for BSc subject group information','[]','enrolled',0.00,'Phone','2026-05-21 17:22:51'),(10,1,1,7,7,'Test Clerk Admission','Clerk','+91 90000 12345',NULL,'123412341234','2026-05-22 10:30:00','Smoke test pending admission','{\"HSC \\/ XIth Month\":\"February\",\"Middle \\/ Father Name\":\"Clerk\",\"Class Section Id\":\"7\",\"First Name\":\"Test\",\"Last Name \\/ Surname\":\"Admission\",\"HSC \\/ XIth Year\":\"2026\",\"SSC Seat No\":\"SSC123\",\"HSC \\/ XIth Board\\/College\":\"SRTMUN Jr College\",\"SSC Month\":\"March\",\"SSC Board\\/College\":\"MSBSHSE\",\"Aadhar No\":\"123412341234\",\"Admission Class\":\"BA Year 1\",\"HSC \\/ XIth Seat No\":\"HSC456\",\"Mobile No\":\"+91 90000 12345\",\"SSC Year\":\"2024\",\"admission_status\":\"Pending accountant confirmation\",\"document_verification\":{\"documents\":{\"Leaving \\/ transfer certificate\":false,\"Aadhar copy\":true,\"Original marksheet\":true},\"result\":\"Some documents pending\",\"note\":\"TC pending\",\"verified_at\":\"2026-05-21 20:18:38\"}}','screening',0.00,'Walk-in','2026-05-21 18:18:38'),(11,1,1,7,NULL,'Tamanaa bhatiya','Not captured at enquiry','878788855855588',NULL,NULL,'2026-05-21 18:23:00','first followup','[]','enquiry',0.00,'Walk-in','2026-05-21 18:30:41'),(12,1,1,7,7,'Subject Array Test','Array','+91 90000 77777',NULL,'123412341234','2026-05-23 10:30:00','Subject array smoke','{\"UDISE No\":\"12345678901\",\"Middle \\/ Father Name\":\"Array\",\"Class Section Id\":\"7\",\"First Name\":\"Subject\",\"Last Name \\/ Surname\":\"Test\",\"HSC \\/ XIth Year\":\"2026\",\"IFSC Code\":\"SBIN0123456\",\"Aadhar No\":\"123412341234\",\"Admission Class\":\"BA Year 1\",\"Student Saral ID\":\"SARAL123\",\"Mobile No\":\"+91 90000 77777\",\"SSC Year\":\"2024\",\"subjects\":[{\"code\":\"BA-ENG\",\"status\":\"selected\"},{\"code\":\"BA-MAR\",\"status\":\"selected\"}],\"admission_status\":\"Pending accountant confirmation\"}','application',0.00,'Walk-in','2026-05-21 19:14:13'),(13,1,1,7,7,'Paid Convert Test','Convert','+91 90000 88888',NULL,'123412341234','2026-05-23 12:30:00','Paid conversion smoke','{\"UDISE No\":\"12345678901\",\"Middle \\/ Father Name\":\"Convert\",\"Class Section Id\":\"7\",\"First Name\":\"Paid\",\"Last Name \\/ Surname\":\"Test\",\"HSC \\/ XIth Year\":\"2026\",\"IFSC Code\":\"SBIN0123456\",\"Aadhar No\":\"123412341234\",\"Admission Class\":\"BA Year 1\",\"Student Saral ID\":\"SARAL888\",\"Mobile No\":\"+91 90000 88888\",\"SSC Year\":\"2024\",\"subjects\":[{\"code\":\"BA-ENG\",\"status\":\"selected\"},{\"code\":\"BA-MAR\",\"status\":\"selected\"}],\"admission_status\":\"Ready for fee confirmation\"}','enrolled',0.00,'Walk-in','2026-05-22 18:40:08'),(14,1,1,4,4,'jayshree amol bhalerao','amol','8898986589',NULL,'565856585655','2026-05-23 03:43:00','will come with parante','{\"Last Name \\/ Surname\":\"bhalerao\",\"First Name\":\"jayshree\",\"Middle \\/ Father Name\":\"amol\",\"Mother Name\":\"latabai\",\"Mobile No\":\"8898986589\",\"Aadhar No\":\"565856585655\",\"UDISE No\":\"45684585888\",\"Student Saral ID\":\"545458556\",\"Admission Class\":\"BCA Year 1\",\"Class Section Id\":\"4\",\"Application Sr. No\":\"APP-2026-0014\",\"Residential Address\":\"at post sawana tq, sengaon, dist. hingoli pin 431007\",\"Date of Birth\":\"1991-06-15\",\"Minority Religion\":\"Sikh\",\"Category\":\"NT(D)\",\"Divyang\":\"No\",\"SSC Seat No\":\"k345345\",\"SSC Month\":\"March\",\"SSC Year\":\"2022\",\"SSC Board\\/College\":\"pune\",\"HSC \\/ XIth Seat No\":\"k56343\",\"HSC \\/ XIth Month\":\"February\",\"HSC \\/ XIth Year\":\"2023\",\"HSC \\/ XIth Board\\/College\":\"pune\",\"SSC Maharashtra Board\":\"Yes\",\"Eligibility Certificate Issued\":\"No\",\"Eligibility Certificate No\":\"\",\"Account No\":\"45685465\",\"IFSC Code\":\"sbin0018765\",\"Account Holder\":\"Own\",\"subjects\":[],\"residential_address\":\"at post sawana tq, sengaon, dist. hingoli pin 431007\",\"date_of_birth\":\"1991-06-15\",\"ssc_details\":\"k345345 \\/ March \\/ 2022 \\/ pune\",\"xith_details\":\"k56343 \\/ February \\/ 2023 \\/ pune\",\"eligibility_certificate\":\"No\",\"admission_status\":\"Pending accountant confirmation\",\"document_verification\":{\"documents\":{\"Leaving \\/ transfer certificate\":true,\"Original marksheet\":true,\"Birth certificate\":true,\"Caste \\/ category certificate\":false,\"Aadhar copy\":true,\"Photograph\":true},\"result\":\"Some documents pending\",\"note\":\"caste certificate is pending\",\"verified_at\":\"2026-05-23 06:34:31\"}}','screening',0.00,'Walk-in','2026-05-23 03:46:09');
/*!40000 ALTER TABLE `erp_admission_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_attendance_records`
--

DROP TABLE IF EXISTS `erp_attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_attendance_records` (
  `session_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `status` enum('present','absent','late','excused') NOT NULL DEFAULT 'present',
  `note` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`session_id`,`student_id`),
  KEY `fk_erp_att_record_student` (`student_id`),
  CONSTRAINT `fk_erp_att_record_session` FOREIGN KEY (`session_id`) REFERENCES `erp_attendance_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_att_record_student` FOREIGN KEY (`student_id`) REFERENCES `erp_students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_attendance_records`
--

LOCK TABLES `erp_attendance_records` WRITE;
/*!40000 ALTER TABLE `erp_attendance_records` DISABLE KEYS */;
INSERT INTO `erp_attendance_records` VALUES (1,1,'present',NULL),(2,2,'late','Arrived after assembly');
/*!40000 ALTER TABLE `erp_attendance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_attendance_sessions`
--

DROP TABLE IF EXISTS `erp_attendance_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_attendance_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `section_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned DEFAULT NULL,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `period_no` int(10) unsigned DEFAULT NULL,
  `status` enum('open','submitted','locked') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_att_session` (`section_id`,`attendance_date`,`period_no`),
  KEY `fk_erp_att_subject` (`subject_id`),
  KEY `fk_erp_att_staff` (`staff_id`),
  CONSTRAINT `fk_erp_att_section` FOREIGN KEY (`section_id`) REFERENCES `erp_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_att_staff` FOREIGN KEY (`staff_id`) REFERENCES `erp_staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_att_subject` FOREIGN KEY (`subject_id`) REFERENCES `erp_subjects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_attendance_sessions`
--

LOCK TABLES `erp_attendance_sessions` WRITE;
/*!40000 ALTER TABLE `erp_attendance_sessions` DISABLE KEYS */;
INSERT INTO `erp_attendance_sessions` VALUES (1,1,1,2,'2026-05-20',1,'submitted'),(2,2,3,2,'2026-05-20',2,'submitted');
/*!40000 ALTER TABLE `erp_attendance_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_audit_logs`
--

DROP TABLE IF EXISTS `erp_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `module` varchar(80) NOT NULL,
  `action` varchar(120) NOT NULL,
  `entity_type` varchar(120) DEFAULT NULL,
  `entity_id` varchar(80) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_audit_module` (`module`,`created_at`),
  KEY `fk_erp_audit_user` (`user_id`),
  CONSTRAINT `fk_erp_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=174 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_audit_logs`
--

LOCK TABLES `erp_audit_logs` WRITE;
/*!40000 ALTER TABLE `erp_audit_logs` DISABLE KEYS */;
INSERT INTO `erp_audit_logs` VALUES (1,1,'database','seeded','campus_suite','initial','{\"source\":\"campus-suite-seed.sql\"}','2026-05-20 13:19:34'),(2,1,'auth','admin_user_created','users','1','{\"email\":\"admin@example.com\"}','2026-05-20 13:19:34'),(6,1,'users','invited','users','3','{\"persona\":\"Accountant\",\"role\":\"Accountant\"}','2026-05-20 14:51:39'),(8,1,'Smoke','draft_saved','erp_record','REC-20260520-194032','{\"name\":\"Smoke record\",\"code\":\"SMOKE-194032\",\"status\":\"Draft\",\"payload\":{\"module\":\"Smoke\",\"name\":\"Smoke record\",\"status\":\"Draft\"}}','2026-05-20 17:40:32'),(9,1,'reports','generated','erp_report','RPT-20260520-194032','{\"module\":\"Finance\",\"range\":\"This month\",\"groupBy\":\"Class\"}','2026-05-20 17:40:32'),(10,1,'Marks entry sheet report','record_saved','erp_record','REC-20260520-195851','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175851\",\"status\":\"Active\",\"payload\":{\"module\":\"Marks entry sheet report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175851\",\"status\":\"Active\"}}','2026-05-20 17:58:51'),(11,1,'Marks entry sheet report','record_saved','erp_record','REC-20260520-195855','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175855\",\"status\":\"Active\",\"payload\":{\"module\":\"Marks entry sheet report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175855\",\"status\":\"Active\"}}','2026-05-20 17:58:55'),(12,1,'Marks entry sheet report','record_saved','erp_record','REC-20260520-195902','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175902\",\"status\":\"Active\",\"payload\":{\"module\":\"Marks entry sheet report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175902\",\"status\":\"Active\"}}','2026-05-20 17:59:02'),(13,1,'Student profile form report','record_saved','erp_record','REC-20260520-195935','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175934\",\"status\":\"Active\",\"payload\":{\"module\":\"Student profile form report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175934\",\"status\":\"Active\"}}','2026-05-20 17:59:35'),(14,1,'Student profile form report','record_saved','erp_record','REC-20260520-195938','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175938\",\"status\":\"Active\",\"payload\":{\"module\":\"Student profile form report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-175938\",\"status\":\"Active\"}}','2026-05-20 17:59:38'),(15,1,'admissions','created','erp_admission_applications','5','{\"applicant\":\"aarya amol bhalerao\"}','2026-05-20 18:11:07'),(16,1,'admissions','advanced','erp_admission_applications','5','{\"from\":\"enquiry\",\"to\":\"application\"}','2026-05-20 18:11:25'),(17,1,'admissions','advanced','erp_admission_applications','5','{\"from\":\"application\",\"to\":\"screening\"}','2026-05-20 18:11:41'),(18,1,'admissions','advanced','erp_admission_applications','5','{\"from\":\"screening\",\"to\":\"offer\"}','2026-05-20 18:15:08'),(19,1,'admissions','advanced','erp_admission_applications','5','{\"from\":\"offer\",\"to\":\"fee_paid\"}','2026-05-20 18:15:30'),(20,1,'admissions','advanced','erp_admission_applications','5','{\"from\":\"fee_paid\",\"to\":\"enrolled\"}','2026-05-20 18:15:34'),(21,1,'admissions','advanced','erp_admission_applications','5','{\"from\":\"enrolled\",\"to\":\"enrolled\"}','2026-05-20 18:15:40'),(22,1,'Source-wise lead report','record_saved','erp_record','REC-20260520-201622','{\"name\":\"Export\",\"code\":\"EXPORT-181622\",\"status\":\"Active\",\"payload\":{\"module\":\"Source-wise lead report\",\"name\":\"Export\",\"code\":\"EXPORT-181622\",\"status\":\"Active\"}}','2026-05-20 18:16:22'),(23,1,'Counsellor conversion report','record_saved','erp_record','REC-20260520-201832','{\"name\":\"Export\",\"code\":\"EXPORT-181832\",\"status\":\"Active\",\"payload\":{\"module\":\"Counsellor conversion report\",\"name\":\"Export\",\"code\":\"EXPORT-181832\",\"status\":\"Active\"}}','2026-05-20 18:18:32'),(24,1,'Counsellor conversion report','record_saved','erp_record','REC-20260520-201833','{\"name\":\"Export\",\"code\":\"EXPORT-181833\",\"status\":\"Active\",\"payload\":{\"module\":\"Counsellor conversion report\",\"name\":\"Export\",\"code\":\"EXPORT-181833\",\"status\":\"Active\"}}','2026-05-20 18:18:33'),(25,1,'Counsellor conversion report','record_saved','erp_record','REC-20260520-201835','{\"name\":\"Export\",\"code\":\"EXPORT-181834\",\"status\":\"Active\",\"payload\":{\"module\":\"Counsellor conversion report\",\"name\":\"Export\",\"code\":\"EXPORT-181834\",\"status\":\"Active\"}}','2026-05-20 18:18:35'),(26,1,'Attendance register','draft_saved','erp_record','REC-20260520-203920','{\"name\":\"Attendance register entry\",\"code\":\"ATTENDANCE-R-183916\",\"status\":\"Draft\",\"payload\":{\"module\":\"Attendance register\",\"name\":\"Attendance register entry\",\"code\":\"ATTENDANCE-R-183916\",\"status\":\"Draft\",\"classId\":1,\"sectionId\":1,\"subjectId\":1,\"staffId\":1,\"responsibleStaff\":\"Asha Mehta\",\"effectiveFrom\":\"2026-05-20\",\"notes\":\"\"}}','2026-05-20 18:39:20'),(27,1,'Attendance register','record_saved','erp_record','REC-20260520-203924','{\"name\":\"Attendance register entry\",\"code\":\"ATTENDANCE-R-183916\",\"status\":\"Active\",\"payload\":{\"module\":\"Attendance register\",\"name\":\"Attendance register entry\",\"code\":\"ATTENDANCE-R-183916\",\"status\":\"Active\",\"classId\":1,\"sectionId\":1,\"subjectId\":1,\"staffId\":1,\"responsibleStaff\":\"Asha Mehta\",\"effectiveFrom\":\"2026-05-20\",\"notes\":\"\"}}','2026-05-20 18:39:24'),(28,1,'Attendance register report','record_saved','erp_record','REC-20260520-203931','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-183930\",\"status\":\"Active\",\"payload\":{\"module\":\"Attendance register report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-183930\",\"status\":\"Active\"}}','2026-05-20 18:39:31'),(29,1,'Attendance register report','record_saved','erp_record','REC-20260520-203932','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-183932\",\"status\":\"Active\",\"payload\":{\"module\":\"Attendance register report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-183932\",\"status\":\"Active\"}}','2026-05-20 18:39:32'),(30,1,'reports','generated','erp_report','RPT-20260520-205118','{\"module\":\"IT Admin\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 18:51:18'),(31,1,'reports','generated','erp_report','RPT-20260520-205122','{\"module\":\"Registrar\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 18:51:22'),(32,1,'reports','generated','erp_report','RPT-20260520-205125','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 18:51:25'),(33,1,'reports','generated','erp_report','RPT-20260520-205132','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 18:51:32'),(34,1,'reports','generated','erp_report','RPT-20260520-205137','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 18:51:37'),(35,1,'reports','generated','erp_report','RPT-20260520-205140','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"User role\"}','2026-05-20 18:51:40'),(36,1,'User administration','record_saved','erp_record','REC-20260520-205243','{\"name\":\"Invite clerk\",\"code\":\"INVITE-CLERK-185242\",\"status\":\"Active\",\"payload\":{\"module\":\"User administration\",\"name\":\"Invite clerk\",\"code\":\"INVITE-CLERK-185242\",\"status\":\"Active\"}}','2026-05-20 18:52:43'),(37,1,'User administration','record_saved','erp_record','REC-20260520-205257','{\"name\":\"Reset access\",\"code\":\"RESET-ACCESS-185257\",\"status\":\"Active\",\"payload\":{\"module\":\"User administration\",\"name\":\"Reset access\",\"code\":\"RESET-ACCESS-185257\",\"status\":\"Active\"}}','2026-05-20 18:52:57'),(38,1,'User administration','record_saved','erp_record','REC-20260520-205303','{\"name\":\"Invite clerk\",\"code\":\"INVITE-CLERK-185303\",\"status\":\"Active\",\"payload\":{\"module\":\"User administration\",\"name\":\"Invite clerk\",\"code\":\"INVITE-CLERK-185303\",\"status\":\"Active\"}}','2026-05-20 18:53:03'),(39,1,'User administration','record_saved','erp_record','REC-20260520-205319','{\"name\":\"Review MFA\",\"code\":\"REVIEW-MFA-185319\",\"status\":\"Active\",\"payload\":{\"module\":\"User administration\",\"name\":\"Review MFA\",\"code\":\"REVIEW-MFA-185319\",\"status\":\"Active\"}}','2026-05-20 18:53:19'),(40,1,'Clerical desk','record_saved','erp_record','REC-20260520-205353','{\"name\":\"Verify document\",\"code\":\"VERIFY-DOCUM-185352\",\"status\":\"Active\",\"payload\":{\"module\":\"Clerical desk\",\"name\":\"Verify document\",\"code\":\"VERIFY-DOCUM-185352\",\"status\":\"Active\"}}','2026-05-20 18:53:53'),(41,1,'admissions','created','erp_admission_applications','6','{\"applicant\":\"Smoke Admission\"}','2026-05-20 19:31:21'),(42,1,'admissions','converted','erp_admission_applications','6','{\"student_id\":4,\"section_id\":1}','2026-05-20 19:31:21'),(43,1,'Student profile form report','record_saved','erp_record','REC-20260520-214242','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-194242\",\"status\":\"Active\",\"payload\":{\"module\":\"Student profile form report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-194242\",\"status\":\"Active\"}}','2026-05-20 19:42:42'),(44,5,'Admission enquiry','record_saved','erp_record','REC-20260520-220342','{\"name\":\"Save enquiry\",\"code\":\"SAVE-ENQUIRY-200341\",\"status\":\"Active\",\"payload\":{\"module\":\"Admission enquiry\",\"name\":\"Save enquiry\",\"code\":\"SAVE-ENQUIRY-200341\",\"status\":\"Active\"}}','2026-05-20 20:03:42'),(45,5,'Admission enquiry','record_saved','erp_record','REC-20260520-220354','{\"name\":\"Save enquiry\",\"code\":\"SAVE-ENQUIRY-200354\",\"status\":\"Active\",\"payload\":{\"module\":\"Admission enquiry\",\"name\":\"Save enquiry\",\"code\":\"SAVE-ENQUIRY-200354\",\"status\":\"Active\"}}','2026-05-20 20:03:54'),(46,5,'reports','generated','erp_report','RPT-20260520-220601','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 20:06:01'),(47,5,'reports','generated','erp_report','RPT-20260520-220603','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 20:06:03'),(48,5,'reports','generated','erp_report','RPT-20260520-220605','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-20 20:06:05'),(49,5,'Fee collection','record_saved','erp_record','REC-20260521-175819','{\"name\":\"Smoke test student payment\",\"code\":\"SMOKE-212819\",\"status\":\"Paid\",\"payload\":{\"name\":\"Smoke test student payment\",\"code\":\"SMOKE-212819\",\"amount\":\"100\",\"studentName\":\"Aarav Rao\",\"paidOn\":\"2026-05-21T21.28\",\"module\":\"Fee collection\",\"status\":\"Paid\"}}','2026-05-21 15:58:19'),(50,1,'admissions','created','erp_admission_applications','7','{\"applicant\":\"Workflow Smoke\"}','2026-05-21 16:49:13'),(51,5,'admissions','created','erp_admission_applications','8','{\"applicant\":\"Aayu bhalerao\"}','2026-05-21 16:52:33'),(52,5,'Admission follow-up','record_saved','erp_record','REC-20260521-185852','{\"name\":\"Aayu bhalerao follow-up\",\"code\":\"FOLLOW-UP-165852\",\"status\":\"Interested\",\"payload\":{\"module\":\"Admission follow-up\",\"name\":\"Aayu bhalerao follow-up\",\"code\":\"FOLLOW-UP-165852\",\"status\":\"Interested\",\"applicantId\":8,\"outcome\":\"Interested\",\"nextDate\":\"2026-05-22T16:56\",\"contactMode\":\"Phone call\",\"counsellor\":\"Admission counsellor\",\"note\":\"test follow up\"}}','2026-05-21 16:58:52'),(53,5,'admissions','advanced','erp_admission_applications','8','{\"from\":\"enquiry\",\"to\":\"application\"}','2026-05-21 17:19:11'),(54,5,'admissions','advanced','erp_admission_applications','8','{\"from\":\"application\",\"to\":\"screening\"}','2026-05-21 17:19:22'),(55,5,'admissions','advanced','erp_admission_applications','8','{\"from\":\"screening\",\"to\":\"offer\"}','2026-05-21 17:19:35'),(56,1,'admissions','created','erp_admission_applications','9','{\"applicant\":\"Minimal Enquiry Test\"}','2026-05-21 17:22:51'),(57,5,'Detailed admission form','record_saved','erp_record','REC-20260521-192347','{\"name\":\"Kulkarni Mira Rohan\",\"code\":\"ADMISSION-172346\",\"status\":\"Pending accountant confirmation\",\"payload\":{\"module\":\"Detailed admission form\",\"name\":\"Kulkarni Mira Rohan\",\"code\":\"ADMISSION-172346\",\"status\":\"Pending accountant confirmation\",\"details\":{\"Candidate Name\":\"Mira Kulkarni\",\"Middle \\/ Father Name\":\"Rohan\",\"Mobile No\":\"+91 94444 44444\",\"Aadhar No\":\"64523453245234\",\"Admission Class\":\"Grade 10\",\"Application Sr. No\":\"APP-2026-1042\",\"Residential Address\":\"at post sawana tq. sengaon, dist. hingoli maharashtra 431703\",\"Date of Birth\":\"1989-06-11\",\"Minority Religion\":\"Buddhist\",\"Category\":\"SC\",\"Divyang\":\"No\",\"SSC Maharashtra Board\":\"Yes\",\"Eligibility Certificate Issued\":\"No\",\"Fee Reimbursement\":\"Not applicable\",\"Account Holder\":\"Own\",\"UDISE No\":\"12412333\",\"Student Saral ID\":\"53245324534\",\"Last Name \\/ Surname\":\"Kulkarni\",\"First Name\":\"Mira\",\"Mother Name\":\"Jijamata\",\"SSC Seat No\":\"k45343\",\"SSC Month\":\"March\",\"SSC Year\":\"2025\",\"SSC Board\\/College\":\"aurangabad\",\"HSC \\/ XIth Seat No\":\"j342432\",\"HSC \\/ XIth Month\":\"February\",\"HSC \\/ XIth Year\":\"2026\",\"HSC \\/ XIth Board\\/College\":\"pune\",\"Account No\":\"4523452334343\",\"IFSC Code\":\"sbin3453245\"}}}','2026-05-21 17:23:47'),(58,5,'Section subject assignment','record_saved','erp_record','REC-20260521-192420','{\"name\":\"Save section subjects\",\"code\":\"SAVE-SECTION-172420\",\"status\":\"Active\",\"payload\":{\"module\":\"Section subject assignment\",\"name\":\"Save section subjects\",\"code\":\"SAVE-SECTION-172420\",\"status\":\"Active\"}}','2026-05-21 17:24:20'),(59,5,'Detailed admission form','record_saved','erp_record','REC-20260521-192956','{\"name\":\"Kulkarni Meera Rohan\",\"code\":\"ADMISSION-172955\",\"status\":\"Pending accountant confirmation\",\"payload\":{\"module\":\"Detailed admission form\",\"name\":\"Kulkarni Meera Rohan\",\"code\":\"ADMISSION-172955\",\"status\":\"Pending accountant confirmation\",\"details\":{\"Candidate Name\":\"Mira Kulkarni\",\"Middle \\/ Father Name\":\"Rohan\",\"Mobile No\":\"+91 94444 44444\",\"Aadhar No\":\"45634562345234\",\"Admission Class\":\"Grade 10\",\"Application Sr. No\":\"APP-2026-0001\",\"Residential Address\":\"at post sawana t. sengoan dist higoli\",\"Date of Birth\":\"2000-11-12\",\"Minority Religion\":\"Buddhist\",\"Category\":\"SC\",\"Divyang\":\"No\",\"SSC Maharashtra Board\":\"Yes\",\"Eligibility Certificate Issued\":\"No\",\"Fee Reimbursement\":\"Not applicable\",\"Account Holder\":\"Own\",\"UDISE No\":\"234344\",\"Student Saral ID\":\"34523453245\",\"Last Name \\/ Surname\":\"Kulkarni\",\"First Name\":\"Meera\",\"Mother Name\":\"Lata\",\"SSC Seat No\":\"k345324\",\"SSC Month\":\"march\",\"SSC Year\":\"2022\",\"SSC Board\\/College\":\"aurangabd\",\"HSC \\/ XIth Seat No\":\"k453\",\"HSC \\/ XIth Month\":\"feb\",\"HSC \\/ XIth Year\":\"2000\",\"HSC \\/ XIth Board\\/College\":\"pune\",\"Account No\":\"52352345\",\"IFSC Code\":\"dsfsd234623\"}}}','2026-05-21 17:29:56'),(60,5,'Detailed admission form','record_saved','erp_record','REC-20260521-193101','{\"name\":\"Kulkarni Meera Rohan\",\"code\":\"ADMISSION-173058\",\"status\":\"Pending accountant confirmation\",\"payload\":{\"module\":\"Detailed admission form\",\"name\":\"Kulkarni Meera Rohan\",\"code\":\"ADMISSION-173058\",\"status\":\"Pending accountant confirmation\",\"details\":{\"Candidate Name\":\"Mira Kulkarni\",\"Middle \\/ Father Name\":\"Rohan\",\"Mobile No\":\"+91 94444 44444\",\"Aadhar No\":\"45634562345234\",\"Admission Class\":\"Grade 10\",\"Application Sr. No\":\"APP-2026-0001\",\"Residential Address\":\"at post sawana t. sengoan dist higoli\",\"Date of Birth\":\"2000-11-12\",\"Minority Religion\":\"Buddhist\",\"Category\":\"SC\",\"Divyang\":\"No\",\"SSC Maharashtra Board\":\"Yes\",\"Eligibility Certificate Issued\":\"No\",\"Fee Reimbursement\":\"Not applicable\",\"Account Holder\":\"Own\",\"UDISE No\":\"234344\",\"Student Saral ID\":\"34523453245\",\"Last Name \\/ Surname\":\"Kulkarni\",\"First Name\":\"Meera\",\"Mother Name\":\"Lata\",\"SSC Seat No\":\"k345324\",\"SSC Month\":\"march\",\"SSC Year\":\"2022\",\"SSC Board\\/College\":\"aurangabd\",\"HSC \\/ XIth Seat No\":\"k453\",\"HSC \\/ XIth Month\":\"feb\",\"HSC \\/ XIth Year\":\"2000\",\"HSC \\/ XIth Board\\/College\":\"pune\",\"Account No\":\"52352345\",\"IFSC Code\":\"dsfsd234623\"}}}','2026-05-21 17:31:01'),(61,1,'admissions','converted','erp_admission_applications','9','{\"student_id\":5,\"section_id\":7}','2026-05-21 17:34:27'),(62,1,'users','invited','users','6','{\"persona\":\"Clerk\",\"role\":\"Clerk\"}','2026-05-21 17:48:39'),(63,5,'Detailed admission form','record_saved','erp_record','REC-20260521-200815','{\"name\":\"Kulkarnee Mira Tukaram\",\"code\":\"ADMISSION-180815\",\"status\":\"Pending accountant confirmation\",\"payload\":{\"module\":\"Detailed admission form\",\"name\":\"Kulkarnee Mira Tukaram\",\"code\":\"ADMISSION-180815\",\"status\":\"Pending accountant confirmation\",\"details\":{\"Candidate Name\":\"Mira Kulkarni\",\"Middle \\/ Father Name\":\"Tukaram\",\"Mobile No\":\"+91 94444 44444\",\"Aadhar No\":\"34562345234\",\"Admission Class\":\"Grade 10\",\"Application Sr. No\":\"APP-2026-0001\",\"Residential Address\":\"at post sawana tq. sengaon dist. hingoli 431703\",\"Date of Birth\":\"2023-12-11\",\"Minority Religion\":\"Buddhist\",\"Category\":\"SC\",\"Divyang\":\"No\",\"SSC Maharashtra Board\":\"Yes\",\"Eligibility Certificate Issued\":\"No\",\"Fee Reimbursement\":\"Not applicable\",\"Account Holder\":\"Own\",\"UDISE No\":\"2345324\",\"Student Saral ID\":\"32454645645\",\"Last Name \\/ Surname\":\"Kulkarnee\",\"First Name\":\"Mira\",\"Mother Name\":\"Lata\",\"Subject:__add_subject__\":\"selected\",\"SSC Seat No\":\"ds345345\",\"SSC Year\":\"2024\",\"SSC Board\\/College\":\"zp school\",\"HSC \\/ XIth Seat No\":\"k4534\",\"HSC \\/ XIth Year\":\"2024\",\"HSC \\/ XIth Board\\/College\":\"tukaram college of arts\",\"SSC Month\":\"April\",\"HSC \\/ XIth Month\":\"June\",\"Account No\":\"325324534\",\"IFSC Code\":\"sdg345343\"}}}','2026-05-21 18:08:15'),(64,5,'admissions','advanced','erp_admission_applications','1','{\"from\":\"screening\",\"to\":\"offer\"}','2026-05-21 18:11:08'),(65,5,'admissions','advanced','erp_admission_applications','1','{\"from\":\"offer\",\"to\":\"fee_paid\"}','2026-05-21 18:12:50'),(66,5,'admissions','created','erp_admission_applications','10','{\"applicant\":\"Test Clerk Admission\"}','2026-05-21 18:18:38'),(67,5,'admissions','details_saved','erp_admission_applications','10','{\"status\":\"Pending accountant confirmation\",\"section_id\":7}','2026-05-21 18:18:38'),(68,5,'admissions','advanced','erp_admission_applications','10','{\"from\":\"application\",\"to\":\"screening\",\"documents\":{\"Leaving \\/ transfer certificate\":false,\"Aadhar copy\":true,\"Original marksheet\":true}}','2026-05-21 18:18:38'),(69,1,'users','invited','users','8','{\"persona\":\"Clerk\",\"role\":\"Clerk\"}','2026-05-21 18:19:40'),(70,5,'admissions','details_saved','erp_admission_applications','1','{\"status\":\"Pending accountant confirmation\",\"section_id\":1}','2026-05-21 18:24:21'),(71,5,'admissions','details_saved','erp_admission_applications','1','{\"status\":\"Pending accountant confirmation\",\"section_id\":1}','2026-05-21 18:27:55'),(72,5,'admissions','created','erp_admission_applications','11','{\"applicant\":\"Tamanaa bhatiya\"}','2026-05-21 18:30:41'),(73,5,'Admission follow-up','record_saved','erp_record','REC-20260521-204400','{\"name\":\"Tamanaa bhatiya follow-up\",\"code\":\"FOLLOW-UP-184400\",\"status\":\"Interested\",\"payload\":{\"module\":\"Admission follow-up\",\"name\":\"Tamanaa bhatiya follow-up\",\"code\":\"FOLLOW-UP-184400\",\"status\":\"Interested\",\"applicantId\":11,\"outcome\":\"Interested\",\"nextDate\":\"2026-05-21T18:43\",\"contactMode\":\"Phone call\",\"counsellor\":\"Campus Clerk\",\"remark\":\"first followup\",\"note\":\"second folloup\"}}','2026-05-21 18:44:00'),(74,5,'Admission follow-up','record_saved','erp_record','REC-20260521-204420','{\"name\":\"Tamanaa bhatiya follow-up\",\"code\":\"FOLLOW-UP-184420\",\"status\":\"Interested\",\"payload\":{\"module\":\"Admission follow-up\",\"name\":\"Tamanaa bhatiya follow-up\",\"code\":\"FOLLOW-UP-184420\",\"status\":\"Interested\",\"applicantId\":11,\"outcome\":\"Interested\",\"nextDate\":\"2026-05-21T18:44\",\"contactMode\":\"Phone call\",\"counsellor\":\"Campus Clerk\",\"remark\":\"second folloup\",\"note\":\"sfdgasdfasd\"}}','2026-05-21 18:44:20'),(75,1,'users','invited','users','9','{\"persona\":\"Accountant\",\"role\":\"Accountant\"}','2026-05-21 18:46:55'),(76,9,'admissions','details_saved','erp_admission_applications','1','{\"status\":\"Ready for fee confirmation\",\"section_id\":7}','2026-05-21 18:48:28'),(77,9,'admissions','converted','erp_admission_applications','1','{\"student_id\":6,\"section_id\":7}','2026-05-21 18:50:20'),(78,9,'Original documents','record_saved','erp_record','REC-20260521-205624','{\"name\":\"Save original document register\",\"code\":\"SAVE-ORIGINA-185624\",\"status\":\"Active\",\"payload\":{\"module\":\"Original documents\",\"name\":\"Save original document register\",\"code\":\"SAVE-ORIGINA-185624\",\"status\":\"Active\"}}','2026-05-21 18:56:24'),(79,9,'Fee collection','record_saved','erp_record','REC-20260521-210059','{\"name\":\"Aarav Rao payment\",\"code\":\"RECEIPT-190059\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Aarav Rao payment\",\"code\":\"RECEIPT-190059\",\"status\":\"Paid\",\"studentId\":\"1\",\"studentName\":\"Aarav Rao\",\"invoiceNo\":\"INV-2026-001\",\"feeHead\":\"Grade 10 Term 1 Tuition\",\"amount\":\"400\",\"method\":\"Cash\",\"reference\":\"asdf\",\"paidOn\":\"2026-05-21T18:56\"}}','2026-05-21 19:00:59'),(80,9,'reports','generated','erp_report','RPT-20260521-210326','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-21 19:03:26'),(81,9,'reports','generated','erp_report','RPT-20260521-210328','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-21 19:03:28'),(82,9,'reports','generated','erp_report','RPT-20260521-210338','{\"module\":\"Finance\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-21 19:03:38'),(83,9,'reports','generated','erp_report','RPT-20260521-210345','{\"module\":\"Finance\",\"range\":\"This month\",\"groupBy\":\"Class\"}','2026-05-21 19:03:45'),(84,9,'reports','generated','erp_report','RPT-20260521-210355','{\"module\":\"Admissions\",\"range\":\"This month\",\"groupBy\":\"Class\"}','2026-05-21 19:03:55'),(85,5,'admissions','created','erp_admission_applications','12','{\"applicant\":\"Subject Array Test\"}','2026-05-21 19:14:13'),(86,5,'admissions','details_saved','erp_admission_applications','12','{\"status\":\"Pending accountant confirmation\",\"section_id\":7}','2026-05-21 19:14:13'),(87,9,'Fee collection','record_saved','erp_record','REC-20260521-211843','{\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191843\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191843\",\"status\":\"Paid\",\"studentId\":\"6\",\"studentName\":\"Mira Kulkarnee\",\"balance\":0,\"feeHead\":\"Admission fee\",\"amount\":\"10000\",\"method\":\"Cash\",\"reference\":\"\",\"paidOn\":\"2026-05-21T19:17\"}}','2026-05-21 19:18:43'),(88,9,'Fee collection','record_saved','erp_record','REC-20260521-211856','{\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191856\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191856\",\"status\":\"Paid\",\"studentId\":\"6\",\"studentName\":\"Mira Kulkarnee\",\"balance\":0,\"feeHead\":\"Admission fee\",\"amount\":\"10000\",\"method\":\"Cash\",\"reference\":\"\",\"paidOn\":\"2026-05-21T19:17\"}}','2026-05-21 19:18:56'),(89,9,'Fee collection','record_saved','erp_record','REC-20260521-211857','{\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191857\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191857\",\"status\":\"Paid\",\"studentId\":\"6\",\"studentName\":\"Mira Kulkarnee\",\"balance\":0,\"feeHead\":\"Admission fee\",\"amount\":\"10000\",\"method\":\"Cash\",\"reference\":\"\",\"paidOn\":\"2026-05-21T19:17\"}}','2026-05-21 19:18:57'),(90,9,'Fee collection','record_saved','erp_record','REC-20260521-211858','{\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191857\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191857\",\"status\":\"Paid\",\"studentId\":\"6\",\"studentName\":\"Mira Kulkarnee\",\"balance\":0,\"feeHead\":\"Admission fee\",\"amount\":\"10000\",\"method\":\"Cash\",\"reference\":\"\",\"paidOn\":\"2026-05-21T19:17\"}}','2026-05-21 19:18:58'),(91,9,'Fee collection','record_saved','erp_record','REC-20260521-211858','{\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191857\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191857\",\"status\":\"Paid\",\"studentId\":\"6\",\"studentName\":\"Mira Kulkarnee\",\"balance\":0,\"feeHead\":\"Admission fee\",\"amount\":\"10000\",\"method\":\"Cash\",\"reference\":\"\",\"paidOn\":\"2026-05-21T19:17\"}}','2026-05-21 19:18:58'),(92,9,'Fee collection','record_saved','erp_record','REC-20260521-211858','{\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191858\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191858\",\"status\":\"Paid\",\"studentId\":\"6\",\"studentName\":\"Mira Kulkarnee\",\"balance\":0,\"feeHead\":\"Admission fee\",\"amount\":\"10000\",\"method\":\"Cash\",\"reference\":\"\",\"paidOn\":\"2026-05-21T19:17\"}}','2026-05-21 19:18:58'),(93,9,'Fee collection','record_saved','erp_record','REC-20260521-211858','{\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191858\",\"status\":\"Paid\",\"payload\":{\"module\":\"Fee collection\",\"name\":\"Mira Kulkarnee payment\",\"code\":\"RECEIPT-191858\",\"status\":\"Paid\",\"studentId\":\"6\",\"studentName\":\"Mira Kulkarnee\",\"balance\":0,\"feeHead\":\"Admission fee\",\"amount\":\"10000\",\"method\":\"Cash\",\"reference\":\"\",\"paidOn\":\"2026-05-21T19:17\"}}','2026-05-21 19:18:58'),(94,1,'Classes master','record_saved','erp_record','REC-20260521-211926','{\"name\":\"Edit\",\"code\":\"EDIT-191926\",\"status\":\"Active\",\"payload\":{\"module\":\"Classes master\",\"name\":\"Edit\",\"code\":\"EDIT-191926\",\"status\":\"Active\"}}','2026-05-21 19:19:26'),(95,1,'Classes master','record_saved','erp_record','REC-20260521-211929','{\"name\":\"Edit\",\"code\":\"EDIT-191929\",\"status\":\"Active\",\"payload\":{\"module\":\"Classes master\",\"name\":\"Edit\",\"code\":\"EDIT-191929\",\"status\":\"Active\"}}','2026-05-21 19:19:29'),(96,1,'Classes master','record_saved','erp_record','REC-20260521-211929','{\"name\":\"Edit\",\"code\":\"EDIT-191929\",\"status\":\"Active\",\"payload\":{\"module\":\"Classes master\",\"name\":\"Edit\",\"code\":\"EDIT-191929\",\"status\":\"Active\"}}','2026-05-21 19:19:29'),(97,1,'Classes master','record_saved','erp_record','REC-20260521-211931','{\"name\":\"Edit\",\"code\":\"EDIT-191930\",\"status\":\"Active\",\"payload\":{\"module\":\"Classes master\",\"name\":\"Edit\",\"code\":\"EDIT-191930\",\"status\":\"Active\"}}','2026-05-21 19:19:31'),(98,1,'finance','payment_collected','erp_fee_payments','RCT-2026-0003','{\"student_id\":2,\"fee_head\":\"Admission fee\",\"amount\":101,\"status\":\"partial\"}','2026-05-22 18:37:30'),(99,1,'admissions','created','erp_admission_applications','13','{\"applicant\":\"Paid Convert Test\"}','2026-05-22 18:40:08'),(100,1,'admissions','details_saved','erp_admission_applications','13','{\"status\":\"Ready for fee confirmation\",\"section_id\":7}','2026-05-22 18:40:08'),(101,1,'admissions','converted','erp_admission_applications','13','{\"student_id\":7,\"section_id\":7,\"payment_amount\":501}','2026-05-22 18:40:09'),(102,1,'finance','payment_collected','erp_fee_payments','RCT-2026-0005','{\"student_id\":2,\"fee_head\":\"Admission fee\",\"amount\":11,\"status\":\"partial\"}','2026-05-22 18:48:58'),(103,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0006','{\"student_id\":1,\"fee_head\":\"Grade 10 Term 1 Tuition\",\"amount\":4444,\"status\":\"paid\"}','2026-05-22 18:54:35'),(104,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0007','{\"student_id\":1,\"fee_head\":\"Admission fee\",\"amount\":222,\"status\":\"paid\"}','2026-05-22 18:54:50'),(105,1,'finance','payment_collected','erp_fee_payments','RCT-2026-0008','{\"student_id\":2,\"fee_head\":\"Grade 11 Science Term 1 Tuition\",\"amount\":17,\"status\":\"partial\"}','2026-05-22 19:06:20'),(106,1,'finance','balance_added','erp_fee_invoices','INV-2026-0007','{\"student_id\":2,\"fee_head\":\"Transfer certificate\",\"amount\":35}','2026-05-22 19:22:23'),(107,1,'finance','payment_collected','erp_fee_payments','RCT-2026-0009','{\"student_id\":2,\"fee_head\":\"Bonafide certificate\",\"amount\":25,\"status\":\"partial\"}','2026-05-22 19:22:28'),(108,1,'finance','balance_added','erp_fee_invoices','INV-2026-0008','{\"student_id\":2,\"fee_head\":\"No-dues certificate\",\"amount\":41}','2026-05-22 19:25:56'),(109,1,'finance','payment_collected','erp_fee_payments','RCT-2026-0010','{\"student_id\":2,\"fee_head\":\"Bonafide certificate\",\"amount\":10,\"status\":\"paid\"}','2026-05-22 19:25:56'),(110,1,'finance','fee_head_saved','erp_fee_plans','Administrative test fee','{\"class_id\":7,\"amount\":1,\"due_on\":\"2026-05-23\"}','2026-05-22 19:41:33'),(111,1,'finance','payment_collected','erp_fee_payments','RCT-2026-0011','{\"student_id\":6,\"fee_head\":\"Administrative test fee\",\"amount\":1,\"status\":\"paid\"}','2026-05-22 19:41:33'),(112,9,'finance','balance_added','erp_fee_invoices','INV-2026-0010','{\"student_id\":6,\"fee_head\":\"Admission fee\",\"amount\":50000}','2026-05-22 19:46:29'),(113,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0012','{\"student_id\":6,\"fee_head\":\"Tuition fee\",\"amount\":150000,\"status\":\"paid\"}','2026-05-22 19:46:54'),(114,9,'finance','balance_added','erp_fee_invoices','INV-2026-0012','{\"student_id\":6,\"fee_head\":\"Tuition fee\",\"amount\":150000}','2026-05-22 19:47:08'),(115,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0013','{\"student_id\":6,\"fee_head\":\"Tuition fee\",\"amount\":1000,\"status\":\"partial\"}','2026-05-22 19:47:35'),(116,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0014','{\"student_id\":6,\"fee_head\":\"Tuition fee\",\"amount\":200,\"status\":\"partial\"}','2026-05-22 19:50:51'),(117,9,'finance','fee_head_saved','erp_fee_plans','Gathering fees','{\"class_id\":7,\"amount\":500,\"due_on\":\"2026-05-23\"}','2026-05-22 19:59:15'),(118,9,'finance','balance_added','erp_fee_invoices','INV-2026-0013','{\"student_id\":6,\"fee_head\":\"Gathering fees\",\"amount\":500}','2026-05-22 19:59:23'),(119,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0015','{\"student_id\":6,\"fee_head\":\"Gathering fees\",\"amount\":300,\"status\":\"partial\"}','2026-05-22 19:59:31'),(120,1,'finance','class_fee_assigned','erp_fee_plans','9','{\"class_id\":7,\"fee_head\":\"Class default smoke fee\",\"amount\":2,\"students\":2}','2026-05-23 02:56:21'),(121,9,'finance','class_fee_assigned','erp_fee_plans','10','{\"class_id\":8,\"fee_head\":\"Tour\",\"amount\":2000,\"students\":1}','2026-05-23 03:12:43'),(122,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0016','{\"student_id\":5,\"fee_head\":\"Tour\",\"amount\":100,\"status\":\"partial\"}','2026-05-23 03:17:09'),(123,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0017','{\"student_id\":5,\"fee_head\":\"Tour\",\"amount\":100,\"status\":\"partial\"}','2026-05-23 03:17:10'),(124,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0018','{\"student_id\":5,\"fee_head\":\"Tour\",\"amount\":100,\"status\":\"partial\"}','2026-05-23 03:17:10'),(125,9,'finance','payment_collected','erp_fee_payments','RCT-2026-0019','{\"student_id\":5,\"fee_head\":\"Tour\",\"amount\":100,\"status\":\"partial\"}','2026-05-23 03:17:11'),(126,9,'reports','generated','erp_report','RPT-20260523-052149','{\"module\":\"Admissions\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-23 03:21:49'),(127,9,'reports','generated','erp_report','RPT-20260523-052154','{\"module\":\"Academics\",\"range\":\"This academic year\",\"groupBy\":\"Class\"}','2026-05-23 03:21:54'),(128,9,'admissions','details_saved','erp_admission_applications','8','{\"status\":\"Ready for fee confirmation\",\"section_id\":8}','2026-05-23 03:24:56'),(129,9,'admissions','converted','erp_admission_applications','8','{\"student_id\":8,\"section_id\":8,\"payment_amount\":200}','2026-05-23 03:25:13'),(130,1,'users','invited','users','10','{\"persona\":\"Teacher\",\"role\":\"Teacher\"}','2026-05-23 03:42:35'),(131,10,'admissions','created','erp_admission_applications','14','{\"applicant\":\"jaya bhalerao\"}','2026-05-23 03:46:09'),(132,10,'Admission follow-up','record_saved','erp_record','REC-20260523-054653','{\"name\":\"jaya bhalerao follow-up\",\"code\":\"FOLLOW-UP-034653\",\"status\":\"Interested\",\"payload\":{\"module\":\"Admission follow-up\",\"name\":\"jaya bhalerao follow-up\",\"code\":\"FOLLOW-UP-034653\",\"status\":\"Interested\",\"applicantId\":14,\"outcome\":\"Interested\",\"nextDate\":\"2026-05-23T03:46\",\"contactMode\":\"Campus visit\",\"counsellor\":\"Mr teacher one\",\"remark\":\"meet with parente\",\"note\":\"will arrange the money\"}}','2026-05-23 03:46:53'),(133,10,'admissions','details_saved','erp_admission_applications','14','{\"status\":\"Pending accountant confirmation\",\"section_id\":4}','2026-05-23 04:32:37'),(134,10,'admissions','advanced','erp_admission_applications','14','{\"from\":\"application\",\"to\":\"screening\",\"documents\":{\"Leaving \\/ transfer certificate\":true,\"Original marksheet\":true,\"Birth certificate\":true,\"Caste \\/ category certificate\":false,\"Aadhar copy\":true,\"Photograph\":true}}','2026-05-23 04:34:31'),(135,10,'Teacher class setup','record_saved','erp_record','REC-20260523-063628','{\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-043628\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-043628\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"semester\":\"Semester 1\",\"section\":\"B\",\"capacity\":\"50\"}}','2026-05-23 04:36:28'),(136,10,'Teacher exam component','record_saved','erp_record','REC-20260523-070502','{\"name\":\"BA-ENG Practical\",\"code\":\"EXAM-COMP-050502\",\"status\":\"Active\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"BA-ENG Practical\",\"code\":\"EXAM-COMP-050502\",\"status\":\"Active\",\"subjectCode\":\"BA-ENG\",\"semester\":\"1\",\"component\":\"Practical\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}}','2026-05-23 05:05:02'),(137,10,'Teacher exam component','record_saved','erp_record','REC-20260523-070508','{\"name\":\"BA-ENG Theory\",\"code\":\"EXAM-COMP-050508\",\"status\":\"Active\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"BA-ENG Theory\",\"code\":\"EXAM-COMP-050508\",\"status\":\"Active\",\"subjectCode\":\"BA-ENG\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}}','2026-05-23 05:05:08'),(138,10,'Timetable planner report','record_saved','erp_record','REC-20260523-070818','{\"name\":\"Open report\",\"code\":\"OPEN-REPORT-050817\",\"status\":\"Active\",\"payload\":{\"module\":\"Timetable planner report\",\"name\":\"Open report\",\"code\":\"OPEN-REPORT-050817\",\"status\":\"Active\"}}','2026-05-23 05:08:18'),(139,10,'Teacher class setup','record_saved','erp_record','REC-20260523-072457','{\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-052456\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-052456\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"semester\":\"Semester 1\",\"section\":\"B\",\"capacity\":\"60\"}}','2026-05-23 05:24:57'),(140,10,'Teacher subject map','record_saved','erp_record','REC-20260523-072555','{\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher subject map\",\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"BA-ENG\",\"categories\":[\"Compulsory\"],\"mandatory\":\"Yes\"}}','2026-05-23 05:25:55'),(141,1,'Teacher class setup','record_saved','erp_record','REC-20260523-072734','{\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-052734\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-052734\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"semester\":\"Semester 1\",\"section\":\"B\",\"capacity\":\"50\"}}','2026-05-23 05:27:34'),(142,1,'Teacher class setup','record_saved','erp_record','REC-20260523-072742','{\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-052742\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-052742\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"semester\":\"Semester 1\",\"section\":\"B\",\"capacity\":\"50\"}}','2026-05-23 05:27:42'),(143,1,'Teacher class setup','record_saved','erp_record','REC-20260523-073132','{\"name\":\"Semester 1 \\/ Z\",\"code\":\"CLASS-SETUP-SMOKE\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ Z\",\"code\":\"CLASS-SETUP-SMOKE\",\"status\":\"Pending admin sync\",\"classId\":\"1\",\"semester\":\"Semester 1\",\"section\":\"Z\",\"capacity\":\"40\"}}','2026-05-23 05:31:32'),(144,1,'Teacher subject map','record_saved','erp_record','REC-20260523-073814','{\"name\":\"Mathematics class mapping\",\"code\":\"MAT10\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher subject map\",\"name\":\"Mathematics class mapping\",\"code\":\"MAT10\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"MAT10\",\"categories\":[\"Compulsory\"],\"mandatory\":\"Yes\"}}','2026-05-23 05:38:14'),(145,1,'Teacher subject map','record_saved','erp_record','REC-20260523-073849','{\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher subject map\",\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"BA-ENG\",\"categories\":[\"Compulsory\"],\"mandatory\":\"Yes\"}}','2026-05-23 05:38:49'),(146,1,'Teacher subject map','record_saved','erp_record','REC-20260523-074023','{\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher subject map\",\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"BA-ENG\",\"categories\":[\"Compulsory\"],\"mandatory\":\"Yes\"}}','2026-05-23 05:40:23'),(147,1,'Teacher exam component','record_saved','erp_record','REC-20260523-074139','{\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-054138\",\"status\":\"Active\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-054138\",\"status\":\"Active\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}}','2026-05-23 05:41:39'),(148,1,'Teacher exam component','record_saved','erp_record','REC-20260523-074157','{\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-054157\",\"status\":\"Active\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-054157\",\"status\":\"Active\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"30\"}}','2026-05-23 05:41:57'),(149,1,'Teacher exam component','record_saved','erp_record','REC-20260523-074210','{\"name\":\"BA-ENG Theory\",\"code\":\"EXAM-COMP-054209\",\"status\":\"Active\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"BA-ENG Theory\",\"code\":\"EXAM-COMP-054209\",\"status\":\"Active\",\"subjectCode\":\"BA-ENG\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"30\"}}','2026-05-23 05:42:10'),(150,1,'Teacher exam component','record_saved','erp_record','REC-20260523-074223','{\"name\":\"BA-ENG Practical\",\"code\":\"EXAM-COMP-054223\",\"status\":\"Active\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"BA-ENG Practical\",\"code\":\"EXAM-COMP-054223\",\"status\":\"Active\",\"subjectCode\":\"BA-ENG\",\"semester\":\"1\",\"component\":\"Practical\",\"maxMarks\":\"40\",\"passingMarks\":\"20\"}}','2026-05-23 05:42:23'),(151,1,'Teacher class setup','record_saved','erp_record','REC-20260523-082157','{\"name\":\"Semester 1 \\/ A\",\"code\":\"CLASS-SETUP-062157\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ A\",\"code\":\"CLASS-SETUP-062157\",\"status\":\"Approved\",\"classId\":\"1\",\"semester\":\"Semester 1\",\"section\":\"A\",\"capacity\":\"60\"}}','2026-05-23 06:21:57'),(152,1,'Teacher subject master','record_saved','erp_record','REC-20260523-082217','{\"name\":\"Quality Assurance English\",\"code\":\"QA-ENG-101\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher subject master\",\"name\":\"Quality Assurance English\",\"code\":\"QA-ENG-101\",\"status\":\"Approved\",\"category\":\"Compulsory\"}}','2026-05-23 06:22:17'),(153,1,'Teacher subject map','record_saved','erp_record','REC-20260523-082234','{\"name\":\"Mathematics class mapping\",\"code\":\"MAT10\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher subject map\",\"name\":\"Mathematics class mapping\",\"code\":\"MAT10\",\"status\":\"Approved\",\"classId\":\"1\",\"sectionId\":\"1\",\"semester\":\"1\",\"subjectCode\":\"MAT10\",\"category\":\"Compulsory\",\"mandatory\":\"Yes\",\"categories\":[\"Compulsory\"]}}','2026-05-23 06:22:34'),(154,1,'Teacher exam component','record_saved','erp_record','REC-20260523-082244','{\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-062244\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-062244\",\"status\":\"Approved\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}}','2026-05-23 06:22:44'),(155,1,'Teacher exam component','record_saved','erp_record','REC-20260523-082246','{\"name\":\"MAT10 Practical\",\"code\":\"EXAM-COMP-062246\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Practical\",\"code\":\"EXAM-COMP-062246\",\"status\":\"Approved\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Practical\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}}','2026-05-23 06:22:46'),(156,1,'Teacher subject attendance','record_saved','erp_record','REC-20260523-082328','{\"name\":\"BA English attendance sample\",\"code\":\"ATT-UI-SMOKE\",\"status\":\"Submitted\",\"payload\":{\"attendance\":{\"7\":\"Absent\",\"6\":\"Present\"},\"subjectCode\":\"BA-ENG\",\"attendanceDate\":\"2026-05-23\",\"name\":\"BA English attendance sample\",\"module\":\"Teacher subject attendance\",\"sectionId\":\"7\",\"code\":\"ATT-UI-SMOKE\",\"status\":\"Submitted\",\"classId\":\"8\"}}','2026-05-23 06:23:28'),(157,1,'Teacher marks entry','record_saved','erp_record','REC-20260523-082348','{\"name\":\"BA English Semester 1 Theory\",\"code\":\"MARKS-UI-THEORY-2\",\"status\":\"Submitted\",\"payload\":{\"maxMarks\":\"80\",\"subjectCode\":\"BA-ENG\",\"marks\":{\"7\":\"65\",\"6\":\"72\"},\"semester\":\"1\",\"name\":\"BA English Semester 1 Theory\",\"module\":\"Teacher marks entry\",\"sectionId\":\"7\",\"code\":\"MARKS-UI-THEORY-2\",\"status\":\"Submitted\",\"component\":\"Theory\",\"classId\":\"8\"}}','2026-05-23 06:23:48'),(158,1,'Teacher marks entry','record_saved','erp_record','REC-20260523-082400','{\"name\":\"BA English Semester 1 Practical\",\"code\":\"MARKS-UI-PRACT-115400\",\"status\":\"Submitted\",\"payload\":{\"maxMarks\":\"50\",\"subjectCode\":\"BA-ENG\",\"marks\":{\"7\":\"40\",\"6\":\"44\"},\"semester\":\"1\",\"name\":\"BA English Semester 1 Practical\",\"module\":\"Teacher marks entry\",\"sectionId\":\"7\",\"code\":\"MARKS-UI-PRACT-115400\",\"status\":\"Submitted\",\"component\":\"Practical\",\"classId\":\"8\"}}','2026-05-23 06:24:00'),(159,1,'Teacher subject attendance','record_saved','erp_record','REC-20260523-082401','{\"name\":\"BA English attendance sample\",\"code\":\"ATT-UI-115400\",\"status\":\"Submitted\",\"payload\":{\"attendance\":{\"7\":\"Absent\",\"6\":\"Present\"},\"subjectCode\":\"BA-ENG\",\"attendanceDate\":\"2026-05-23\",\"name\":\"BA English attendance sample\",\"module\":\"Teacher subject attendance\",\"sectionId\":\"7\",\"code\":\"ATT-UI-115400\",\"status\":\"Submitted\",\"classId\":\"8\"}}','2026-05-23 06:24:01'),(160,1,'Teacher class setup','record_saved','erp_record','REC-20260523-082411','{\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-062411\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-062411\",\"status\":\"Approved\",\"classId\":\"8\",\"semester\":\"Semester 1\",\"section\":\"B\",\"capacity\":\"30\"}}','2026-05-23 06:24:11'),(161,1,'records','reviewed','erp_saved_record','REC-20260523-082411','{\"status\":\"Approved\",\"note\":null}','2026-05-23 06:24:34'),(162,1,'Teacher subject master','record_saved','erp_record','REC-20260523-082712','{\"name\":\"sanskrit\",\"code\":\"SANK001\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher subject master\",\"name\":\"sanskrit\",\"code\":\"SANK001\",\"status\":\"Approved\",\"category\":\"Second language\"}}','2026-05-23 06:27:12'),(163,1,'Teacher subject map','record_saved','erp_record','REC-20260523-082942','{\"name\":\"Sociology class mapping\",\"code\":\"BA-SOC\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher subject map\",\"name\":\"Sociology class mapping\",\"code\":\"BA-SOC\",\"status\":\"Approved\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"BA-SOC\",\"category\":\"Optional\",\"mandatory\":\"No\",\"categories\":[\"Optional\"]}}','2026-05-23 06:29:42'),(164,1,'masters','subject_saved','erp_subject','31','{\"code\":\"ADM-DIRECT-120315\",\"name\":\"Admin Direct Subject 120315\",\"type\":\"core\"}','2026-05-23 06:33:15'),(165,1,'masters','section_saved','erp_section','14','{\"classId\":8,\"name\":\"B\",\"capacity\":75}','2026-05-23 06:34:22'),(166,1,'masters','section_subject_saved','erp_section_subject','14:15','{\"sectionId\":14,\"subjectCode\":\"BA-ENG\",\"semester\":1,\"mandatory\":1}','2026-05-23 06:34:22'),(167,1,'masters','subject_saved','erp_subject','32','{\"code\":\"SANK001\",\"name\":\"sanskrit\",\"type\":\"elective\"}','2026-05-23 06:40:01'),(168,1,'masters','section_subject_saved','erp_section_subject','8:32','{\"sectionId\":8,\"subjectCode\":\"SANK001\",\"semester\":2,\"mandatory\":0}','2026-05-23 06:41:18'),(169,1,'Teacher exam component','record_saved','erp_record','REC-20260523-084246','{\"name\":\"SANK001 Theory\",\"code\":\"EXAM-COMP-064246\",\"status\":\"Approved\",\"payload\":{\"module\":\"Teacher exam component\",\"name\":\"SANK001 Theory\",\"code\":\"EXAM-COMP-064246\",\"status\":\"Approved\",\"subjectCode\":\"SANK001\",\"semester\":\"2\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}}','2026-05-23 06:42:46'),(170,1,'Teacher subject attendance','record_saved','erp_record','REC-20260523-084314','{\"name\":\"SANK001 attendance\",\"code\":\"ATT-064314\",\"status\":\"Submitted\",\"payload\":{\"module\":\"Teacher subject attendance\",\"name\":\"SANK001 attendance\",\"code\":\"ATT-064314\",\"status\":\"Submitted\",\"classId\":\"8\",\"sectionId\":\"8\",\"subjectCode\":\"SANK001\",\"attendanceDate\":\"2026-05-23\",\"attendance\":{\"8\":\"Present\"}}}','2026-05-23 06:43:14'),(171,10,'Section subject assignment','record_saved','erp_record','REC-20260523-084438','{\"name\":\"Save section subjects\",\"code\":\"SAVE-SECTION-064438\",\"status\":\"Active\",\"payload\":{\"module\":\"Section subject assignment\",\"name\":\"Save section subjects\",\"code\":\"SAVE-SECTION-064438\",\"status\":\"Active\"}}','2026-05-23 06:44:38'),(172,10,'Teacher class setup','record_saved','erp_record','REC-20260523-090603','{\"name\":\"Semester 2 \\/ A\",\"code\":\"CLASS-SETUP-070602\",\"status\":\"Pending admin sync\",\"payload\":{\"module\":\"Teacher class setup\",\"name\":\"Semester 2 \\/ A\",\"code\":\"CLASS-SETUP-070602\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"semester\":\"Semester 2\",\"section\":\"A\",\"capacity\":\"60\"}}','2026-05-23 07:06:03'),(173,NULL,'admissions','public_enquiry_created','erp_admission_applications','15','{\"applicant\":\"Website Test Enquiry\",\"source\":\"Website\"}','2026-05-23 10:31:54');
/*!40000 ALTER TABLE `erp_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_classes`
--

DROP TABLE IF EXISTS `erp_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_classes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `level_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_classes_inst_name` (`institution_id`,`name`),
  CONSTRAINT `fk_erp_classes_institution` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_classes`
--

LOCK TABLES `erp_classes` WRITE;
/*!40000 ALTER TABLE `erp_classes` DISABLE KEYS */;
INSERT INTO `erp_classes` VALUES (1,1,'Grade 10',10),(2,1,'Grade 11 Science',11),(3,1,'B.Com Year 1',13),(4,1,'BCA Year 1',14),(5,1,'BCA Year 2',15),(6,1,'BCA Year 3',16),(7,1,'BA Year 1',14),(8,1,'BSc Year 1',14),(9,1,'B.Ed Year 1',14),(10,1,'BA Year 2',15),(11,1,'BA Year 3',16),(12,1,'BSc Year 2',15),(13,1,'BSc Year 3',16);
/*!40000 ALTER TABLE `erp_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_exam_marks`
--

DROP TABLE IF EXISTS `erp_exam_marks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_exam_marks` (
  `schedule_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `marks_obtained` decimal(6,2) DEFAULT NULL,
  `grade` varchar(8) DEFAULT NULL,
  `remarks` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`schedule_id`,`student_id`),
  KEY `fk_erp_marks_student` (`student_id`),
  CONSTRAINT `fk_erp_marks_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `erp_exam_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_marks_student` FOREIGN KEY (`student_id`) REFERENCES `erp_students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_exam_marks`
--

LOCK TABLES `erp_exam_marks` WRITE;
/*!40000 ALTER TABLE `erp_exam_marks` DISABLE KEYS */;
INSERT INTO `erp_exam_marks` VALUES (1,1,72.00,'A','Strong performance'),(2,2,NULL,NULL,'Pending entry');
/*!40000 ALTER TABLE `erp_exam_marks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_exam_schedules`
--

DROP TABLE IF EXISTS `erp_exam_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_exam_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` int(10) unsigned NOT NULL,
  `class_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `exam_date` date NOT NULL,
  `starts_at` time NOT NULL,
  `ends_at` time NOT NULL,
  `max_marks` decimal(6,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_erp_exam_schedule_exam` (`exam_id`),
  KEY `fk_erp_exam_schedule_class` (`class_id`),
  KEY `fk_erp_exam_schedule_subject` (`subject_id`),
  CONSTRAINT `fk_erp_exam_schedule_class` FOREIGN KEY (`class_id`) REFERENCES `erp_classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_exam_schedule_exam` FOREIGN KEY (`exam_id`) REFERENCES `erp_exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_exam_schedule_subject` FOREIGN KEY (`subject_id`) REFERENCES `erp_subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_exam_schedules`
--

LOCK TABLES `erp_exam_schedules` WRITE;
/*!40000 ALTER TABLE `erp_exam_schedules` DISABLE KEYS */;
INSERT INTO `erp_exam_schedules` VALUES (1,1,1,1,'2026-08-10','09:00:00','12:00:00',80.00),(2,1,2,3,'2026-08-12','09:00:00','12:00:00',70.00);
/*!40000 ALTER TABLE `erp_exam_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_exams`
--

DROP TABLE IF EXISTS `erp_exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_exams` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(10) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `exam_type` enum('unit_test','mid_term','final','board','practical') NOT NULL DEFAULT 'unit_test',
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `status` enum('planned','marks_entry','published','archived') NOT NULL DEFAULT 'planned',
  PRIMARY KEY (`id`),
  KEY `fk_erp_exam_year` (`academic_year_id`),
  CONSTRAINT `fk_erp_exam_year` FOREIGN KEY (`academic_year_id`) REFERENCES `erp_academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_exams`
--

LOCK TABLES `erp_exams` WRITE;
/*!40000 ALTER TABLE `erp_exams` DISABLE KEYS */;
INSERT INTO `erp_exams` VALUES (1,1,'Term 1 Examination','mid_term','2026-08-10','2026-08-24','planned');
/*!40000 ALTER TABLE `erp_exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_fee_invoices`
--

DROP TABLE IF EXISTS `erp_fee_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_fee_invoices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `fee_plan_id` int(10) unsigned NOT NULL,
  `invoice_no` varchar(80) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','due','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'due',
  `due_on` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_invoice_no` (`invoice_no`),
  KEY `idx_erp_invoice_status` (`status`,`due_on`),
  KEY `fk_erp_invoice_student` (`student_id`),
  KEY `fk_erp_invoice_plan` (`fee_plan_id`),
  CONSTRAINT `fk_erp_invoice_plan` FOREIGN KEY (`fee_plan_id`) REFERENCES `erp_fee_plans` (`id`),
  CONSTRAINT `fk_erp_invoice_student` FOREIGN KEY (`student_id`) REFERENCES `erp_students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_fee_invoices`
--

LOCK TABLES `erp_fee_invoices` WRITE;
/*!40000 ALTER TABLE `erp_fee_invoices` DISABLE KEYS */;
INSERT INTO `erp_fee_invoices` VALUES (1,1,1,'INV-2026-0001',45000.00,0.00,45000.00,'paid','2026-06-15','2026-05-20 13:19:34'),(2,2,2,'INV-2026-0002',62000.00,5000.00,30129.00,'partial','2026-06-15','2026-05-20 13:19:34'),(3,3,3,'INV-2026-0003',3500.00,0.00,0.00,'due','2026-06-05','2026-05-20 13:19:34'),(5,7,1,'INV-2026-0004',501.00,0.00,501.00,'paid','2026-05-23','2026-05-22 18:40:08'),(6,1,1,'INV-2026-0005',4444.00,0.00,4444.00,'paid','2026-05-23','2026-05-22 18:54:35'),(7,1,1,'INV-2026-0006',222.00,0.00,222.00,'paid','2026-05-23','2026-05-22 18:54:50'),(8,2,2,'INV-2026-0007',35.00,0.00,35.00,'paid','2026-05-23','2026-05-22 19:22:23'),(9,2,2,'INV-2026-0008',41.00,0.00,0.00,'due','2026-05-23','2026-05-22 19:25:56'),(10,6,5,'INV-2026-0009',1.00,0.00,1.00,'paid','2026-05-23','2026-05-22 19:41:33'),(11,6,6,'INV-2026-0010',50000.00,0.00,0.00,'due','2026-05-23','2026-05-22 19:46:29'),(12,6,7,'INV-2026-0011',150000.00,0.00,150000.00,'paid','2026-05-23','2026-05-22 19:46:54'),(13,6,7,'INV-2026-0012',150000.00,0.00,1200.00,'partial','2026-05-23','2026-05-22 19:47:08'),(14,6,8,'INV-2026-0013',500.00,0.00,300.00,'partial','2026-05-23','2026-05-22 19:59:23'),(17,5,10,'INV-2026-0014',2000.00,0.00,400.00,'partial','2026-05-23','2026-05-23 03:12:43'),(18,8,10,'INV-2026-0015',2000.00,0.00,200.00,'partial','2026-05-23','2026-05-23 03:25:13');
/*!40000 ALTER TABLE `erp_fee_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_fee_payments`
--

DROP TABLE IF EXISTS `erp_fee_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_fee_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `receipt_no` varchar(80) NOT NULL,
  `fee_head` varchar(160) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` enum('cash','card','upi','bank_transfer','cheque','online') NOT NULL DEFAULT 'upi',
  `paid_at` datetime NOT NULL,
  `reference_no` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_receipt_no` (`receipt_no`),
  KEY `fk_erp_payment_invoice` (`invoice_id`),
  CONSTRAINT `fk_erp_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `erp_fee_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_fee_payments`
--

LOCK TABLES `erp_fee_payments` WRITE;
/*!40000 ALTER TABLE `erp_fee_payments` DISABLE KEYS */;
INSERT INTO `erp_fee_payments` VALUES (1,1,'RCT-2026-0001',NULL,45000.00,'upi','2026-05-10 10:15:00','UPI-CSIC-0001'),(2,2,'RCT-2026-0002',NULL,30000.00,'online','2026-05-11 12:30:00','PG-CSIC-0002'),(3,2,'RCT-2026-0003',NULL,101.00,'upi','2026-05-23 11:00:00','SMOKE-000730'),(4,5,'RCT-2026-0004',NULL,501.00,'upi','2026-05-23 00:10:09','ADM-001008'),(5,2,'RCT-2026-0005',NULL,11.00,'upi','2026-05-23 12:00:00','RECHECK-001857'),(6,6,'RCT-2026-0006',NULL,4444.00,'cash','2026-05-22 18:53:00','asdf'),(7,7,'RCT-2026-0007',NULL,222.00,'cash','2026-05-22 18:53:00','asdf asdf'),(8,2,'RCT-2026-0008',NULL,17.00,'upi','2026-05-23 13:00:00','ROW-003620'),(9,8,'RCT-2026-0009',NULL,25.00,'upi','2026-05-23 14:00:00','PAY-005224'),(10,8,'RCT-2026-0010','Bonafide certificate',10.00,'upi','2026-05-23 15:00:00','PURPOSE-005554'),(11,10,'RCT-2026-0011','Administrative test fee',1.00,'cash','2026-05-22 21:41:33','CODX-SMOKE'),(12,12,'RCT-2026-0012','Tuition fee',150000.00,'cash','2026-05-22 21:46:54',NULL),(13,13,'RCT-2026-0013','Tuition fee',1000.00,'cheque','2026-05-22 21:47:35','45845'),(14,13,'RCT-2026-0014','Tuition fee',200.00,'cash','2026-05-22 21:50:51',NULL),(15,14,'RCT-2026-0015','Gathering fees',300.00,'cash','2026-05-22 21:59:31',NULL),(16,17,'RCT-2026-0016','Tour',100.00,'cash','2026-05-23 05:17:09',NULL),(17,17,'RCT-2026-0017','Tour',100.00,'cash','2026-05-23 05:17:10',NULL),(18,17,'RCT-2026-0018','Tour',100.00,'cash','2026-05-23 05:17:10',NULL),(19,17,'RCT-2026-0019','Tour',100.00,'cash','2026-05-23 05:17:11',NULL),(20,18,'RCT-2026-0020',NULL,200.00,'cash','2026-05-23 08:55:13',NULL);
/*!40000 ALTER TABLE `erp_fee_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_fee_plans`
--

DROP TABLE IF EXISTS `erp_fee_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_fee_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `class_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `due_on` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_fee_plan` (`institution_id`,`academic_year_id`,`name`,`class_id`),
  KEY `fk_erp_fee_plan_year` (`academic_year_id`),
  KEY `fk_erp_fee_plan_class` (`class_id`),
  CONSTRAINT `fk_erp_fee_plan_class` FOREIGN KEY (`class_id`) REFERENCES `erp_classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_fee_plan_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_fee_plan_year` FOREIGN KEY (`academic_year_id`) REFERENCES `erp_academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_fee_plans`
--

LOCK TABLES `erp_fee_plans` WRITE;
/*!40000 ALTER TABLE `erp_fee_plans` DISABLE KEYS */;
INSERT INTO `erp_fee_plans` VALUES (1,1,1,1,'Grade 10 Term 1 Tuition',45000.00,'2026-06-15'),(2,1,1,2,'Grade 11 Science Term 1 Tuition',62000.00,'2026-06-15'),(3,1,1,NULL,'Transport Monthly',3500.00,'2026-06-05'),(4,1,1,NULL,'Hostel Monthly',12000.00,'2026-06-05'),(5,1,1,7,'Administrative test fee',1.00,'2026-05-23'),(6,1,1,7,'Admission fee',50000.00,'2026-05-23'),(7,1,1,7,'Tuition fee',150000.00,'2026-05-23'),(8,1,1,7,'Gathering fees',500.00,'2026-05-23'),(10,1,1,8,'Tour',2000.00,'2026-05-23');
/*!40000 ALTER TABLE `erp_fee_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_guardians`
--

DROP TABLE IF EXISTS `erp_guardians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_guardians` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `name` varchar(180) NOT NULL,
  `relation` varchar(80) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(80) NOT NULL,
  `address` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_erp_guardian_inst` (`institution_id`),
  CONSTRAINT `fk_erp_guardian_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_guardians`
--

LOCK TABLES `erp_guardians` WRITE;
/*!40000 ALTER TABLE `erp_guardians` DISABLE KEYS */;
INSERT INTO `erp_guardians` VALUES (1,1,'Priya Rao','Mother','priya.rao@example.com','+91 91111 11111','Kothrud, Pune'),(2,1,'Sanjay Patel','Father','sanjay.patel@example.com','+91 92222 22222','Baner, Pune'),(3,1,'Meena Das','Mother','meena.das@example.com','+91 93333 33333','Aundh, Pune'),(5,1,'Not captured at enquiry','Guardian',NULL,'+91 90000 00456',''),(6,1,'Rohan','Guardian','rohan.kulkarni@example.com','+91 94444 44444','asdf sdf sdffh fdghg sfdgh dfgsd fgfasd fgasd63456435 b'),(7,1,'Convert','Guardian',NULL,'+91 90000 88888',''),(8,1,'Amol','Guardian',NULL,'8830350465','mukundwadi , aurngabd');
/*!40000 ALTER TABLE `erp_guardians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_hostel_allocations`
--

DROP TABLE IF EXISTS `erp_hostel_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_hostel_allocations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `room_id` int(10) unsigned NOT NULL,
  `bed_label` varchar(40) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_erp_ha_student` (`student_id`),
  KEY `fk_erp_ha_room` (`room_id`),
  CONSTRAINT `fk_erp_ha_room` FOREIGN KEY (`room_id`) REFERENCES `erp_hostel_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_ha_student` FOREIGN KEY (`student_id`) REFERENCES `erp_students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_hostel_allocations`
--

LOCK TABLES `erp_hostel_allocations` WRITE;
/*!40000 ALTER TABLE `erp_hostel_allocations` DISABLE KEYS */;
INSERT INTO `erp_hostel_allocations` VALUES (1,3,1,'B2','2026-04-10',NULL);
/*!40000 ALTER TABLE `erp_hostel_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_hostel_rooms`
--

DROP TABLE IF EXISTS `erp_hostel_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_hostel_rooms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hostel_id` int(10) unsigned NOT NULL,
  `room_no` varchar(40) NOT NULL,
  `bed_count` int(10) unsigned NOT NULL,
  `monthly_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_hostel_room` (`hostel_id`,`room_no`),
  CONSTRAINT `fk_erp_room_hostel` FOREIGN KEY (`hostel_id`) REFERENCES `erp_hostels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_hostel_rooms`
--

LOCK TABLES `erp_hostel_rooms` WRITE;
/*!40000 ALTER TABLE `erp_hostel_rooms` DISABLE KEYS */;
INSERT INTO `erp_hostel_rooms` VALUES (1,1,'N-101',4,12000.00);
/*!40000 ALTER TABLE `erp_hostel_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_hostels`
--

DROP TABLE IF EXISTS `erp_hostels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_hostels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `hostel_type` enum('boys','girls','staff','mixed') NOT NULL,
  `warden_staff_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_erp_hostel_inst` (`institution_id`),
  KEY `fk_erp_hostel_warden` (`warden_staff_id`),
  CONSTRAINT `fk_erp_hostel_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_hostel_warden` FOREIGN KEY (`warden_staff_id`) REFERENCES `erp_staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_hostels`
--

LOCK TABLES `erp_hostels` WRITE;
/*!40000 ALTER TABLE `erp_hostels` DISABLE KEYS */;
INSERT INTO `erp_hostels` VALUES (1,1,'North Residential Block','boys',1);
/*!40000 ALTER TABLE `erp_hostels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_institutions`
--

DROP TABLE IF EXISTS `erp_institutions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_institutions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `code` varchar(40) NOT NULL,
  `type` enum('school','college','university','group') NOT NULL DEFAULT 'school',
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_institutions_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_institutions`
--

LOCK TABLES `erp_institutions` WRITE;
/*!40000 ALTER TABLE `erp_institutions` DISABLE KEYS */;
INSERT INTO `erp_institutions` VALUES (1,'Late Baburao Patil Arts and Science College','LBPASC','college','office@lbpcollege.edu.in','+91 2456 220000','Hingoli, Maharashtra','2026-05-20 13:19:33');
/*!40000 ALTER TABLE `erp_institutions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_library_books`
--

DROP TABLE IF EXISTS `erp_library_books`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_library_books` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `accession_no` varchar(80) NOT NULL,
  `title` varchar(300) NOT NULL,
  `author` varchar(200) DEFAULT NULL,
  `isbn` varchar(40) DEFAULT NULL,
  `category` varchar(120) DEFAULT NULL,
  `status` enum('available','issued','reserved','lost','maintenance') NOT NULL DEFAULT 'available',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_book_accession` (`institution_id`,`accession_no`),
  CONSTRAINT `fk_erp_book_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_library_books`
--

LOCK TABLES `erp_library_books` WRITE;
/*!40000 ALTER TABLE `erp_library_books` DISABLE KEYS */;
INSERT INTO `erp_library_books` VALUES (1,1,'LIB-0001','Concepts of Physics','H. C. Verma','9788177091878','Science','issued'),(2,1,'LIB-0002','Accountancy Fundamentals','D. K. Goel','9780000000002','Commerce','available');
/*!40000 ALTER TABLE `erp_library_books` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_library_loans`
--

DROP TABLE IF EXISTS `erp_library_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_library_loans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `book_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned DEFAULT NULL,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `issued_on` date NOT NULL,
  `due_on` date NOT NULL,
  `returned_on` date DEFAULT NULL,
  `fine_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_erp_loan_book` (`book_id`),
  KEY `fk_erp_loan_student` (`student_id`),
  KEY `fk_erp_loan_staff` (`staff_id`),
  CONSTRAINT `fk_erp_loan_book` FOREIGN KEY (`book_id`) REFERENCES `erp_library_books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_loan_staff` FOREIGN KEY (`staff_id`) REFERENCES `erp_staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_loan_student` FOREIGN KEY (`student_id`) REFERENCES `erp_students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_library_loans`
--

LOCK TABLES `erp_library_loans` WRITE;
/*!40000 ALTER TABLE `erp_library_loans` DISABLE KEYS */;
INSERT INTO `erp_library_loans` VALUES (1,1,2,NULL,'2026-05-12','2026-05-26',NULL,0.00);
/*!40000 ALTER TABLE `erp_library_loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_messages`
--

DROP TABLE IF EXISTS `erp_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `audience` enum('all','students','parents','staff','class','custom') NOT NULL DEFAULT 'all',
  `channel` enum('in_app','email','sms','whatsapp','notice') NOT NULL DEFAULT 'notice',
  `subject` varchar(240) NOT NULL,
  `body` text NOT NULL,
  `status` enum('draft','scheduled','sent','failed') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_erp_msg_inst` (`institution_id`),
  KEY `fk_erp_msg_user` (`created_by_user_id`),
  CONSTRAINT `fk_erp_msg_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_msg_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_messages`
--

LOCK TABLES `erp_messages` WRITE;
/*!40000 ALTER TABLE `erp_messages` DISABLE KEYS */;
INSERT INTO `erp_messages` VALUES (1,1,'parents','whatsapp','Attendance alert workflow enabled','Parents will receive absence and late arrival alerts after attendance submission.','sent',NULL,'2026-05-19 18:49:34',1),(2,1,'staff','notice','Term 1 planning meeting','Academic coordinators should review timetable and exam schedules before publication.','scheduled','2026-05-22 18:49:34',NULL,1);
/*!40000 ALTER TABLE `erp_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_saved_records`
--

DROP TABLE IF EXISTS `erp_saved_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_saved_records` (
  `id` varchar(80) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `module` varchar(120) NOT NULL,
  `name` varchar(190) NOT NULL,
  `code` varchar(120) NOT NULL,
  `status` varchar(80) NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `reviewed_by_user_id` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_saved_records_status` (`status`,`created_at`),
  KEY `idx_erp_saved_records_module` (`module`,`created_at`),
  KEY `fk_erp_saved_record_user` (`user_id`),
  KEY `fk_erp_saved_record_reviewer` (`reviewed_by_user_id`),
  CONSTRAINT `fk_erp_saved_record_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_saved_record_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_saved_records`
--

LOCK TABLES `erp_saved_records` WRITE;
/*!40000 ALTER TABLE `erp_saved_records` DISABLE KEYS */;
INSERT INTO `erp_saved_records` VALUES ('REC-20260523-073132',1,'Teacher class setup','Semester 1 / Z','CLASS-SETUP-SMOKE','Pending admin sync','{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ Z\",\"code\":\"CLASS-SETUP-SMOKE\",\"status\":\"Pending admin sync\",\"classId\":\"1\",\"semester\":\"Semester 1\",\"section\":\"Z\",\"capacity\":\"40\"}',NULL,NULL,NULL,'2026-05-23 05:31:32','2026-05-23 05:31:32'),('REC-20260523-073814',1,'Teacher subject map','Mathematics class mapping','MAT10','Pending admin sync','{\"module\":\"Teacher subject map\",\"name\":\"Mathematics class mapping\",\"code\":\"MAT10\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"MAT10\",\"categories\":[\"Compulsory\"],\"mandatory\":\"Yes\"}',NULL,NULL,NULL,'2026-05-23 05:38:14','2026-05-23 05:38:14'),('REC-20260523-073849',1,'Teacher subject map','English class mapping','BA-ENG','Pending admin sync','{\"module\":\"Teacher subject map\",\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"BA-ENG\",\"categories\":[\"Compulsory\"],\"mandatory\":\"Yes\"}',NULL,NULL,NULL,'2026-05-23 05:38:49','2026-05-23 05:38:49'),('REC-20260523-074023',1,'Teacher subject map','English class mapping','BA-ENG','Pending admin sync','{\"module\":\"Teacher subject map\",\"name\":\"English class mapping\",\"code\":\"BA-ENG\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"BA-ENG\",\"categories\":[\"Compulsory\"],\"mandatory\":\"Yes\"}',NULL,NULL,NULL,'2026-05-23 05:40:23','2026-05-23 05:40:23'),('REC-20260523-074139',1,'Teacher exam component','MAT10 Theory','EXAM-COMP-054138','Active','{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-054138\",\"status\":\"Active\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}',NULL,NULL,NULL,'2026-05-23 05:41:39','2026-05-23 05:41:39'),('REC-20260523-074157',1,'Teacher exam component','MAT10 Theory','EXAM-COMP-054157','Active','{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-054157\",\"status\":\"Active\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"30\"}',NULL,NULL,NULL,'2026-05-23 05:41:57','2026-05-23 05:41:57'),('REC-20260523-074210',1,'Teacher exam component','BA-ENG Theory','EXAM-COMP-054209','Active','{\"module\":\"Teacher exam component\",\"name\":\"BA-ENG Theory\",\"code\":\"EXAM-COMP-054209\",\"status\":\"Active\",\"subjectCode\":\"BA-ENG\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"30\"}',NULL,NULL,NULL,'2026-05-23 05:42:10','2026-05-23 05:42:10'),('REC-20260523-074223',1,'Teacher exam component','BA-ENG Practical','EXAM-COMP-054223','Active','{\"module\":\"Teacher exam component\",\"name\":\"BA-ENG Practical\",\"code\":\"EXAM-COMP-054223\",\"status\":\"Active\",\"subjectCode\":\"BA-ENG\",\"semester\":\"1\",\"component\":\"Practical\",\"maxMarks\":\"40\",\"passingMarks\":\"20\"}',NULL,NULL,NULL,'2026-05-23 05:42:23','2026-05-23 05:42:23'),('REC-20260523-082157',1,'Teacher class setup','Semester 1 / A','CLASS-SETUP-062157','Approved','{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ A\",\"code\":\"CLASS-SETUP-062157\",\"status\":\"Approved\",\"classId\":\"1\",\"semester\":\"Semester 1\",\"section\":\"A\",\"capacity\":\"60\"}',NULL,NULL,NULL,'2026-05-23 06:21:57','2026-05-23 06:21:57'),('REC-20260523-082217',1,'Teacher subject master','Quality Assurance English','QA-ENG-101','Approved','{\"module\":\"Teacher subject master\",\"name\":\"Quality Assurance English\",\"code\":\"QA-ENG-101\",\"status\":\"Approved\",\"category\":\"Compulsory\"}',NULL,NULL,NULL,'2026-05-23 06:22:17','2026-05-23 06:22:17'),('REC-20260523-082234',1,'Teacher subject map','Mathematics class mapping','MAT10','Approved','{\"module\":\"Teacher subject map\",\"name\":\"Mathematics class mapping\",\"code\":\"MAT10\",\"status\":\"Approved\",\"classId\":\"1\",\"sectionId\":\"1\",\"semester\":\"1\",\"subjectCode\":\"MAT10\",\"category\":\"Compulsory\",\"mandatory\":\"Yes\",\"categories\":[\"Compulsory\"]}',NULL,NULL,NULL,'2026-05-23 06:22:34','2026-05-23 06:22:34'),('REC-20260523-082244',1,'Teacher exam component','MAT10 Theory','EXAM-COMP-062244','Approved','{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Theory\",\"code\":\"EXAM-COMP-062244\",\"status\":\"Approved\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}',NULL,NULL,NULL,'2026-05-23 06:22:44','2026-05-23 06:22:44'),('REC-20260523-082246',1,'Teacher exam component','MAT10 Practical','EXAM-COMP-062246','Approved','{\"module\":\"Teacher exam component\",\"name\":\"MAT10 Practical\",\"code\":\"EXAM-COMP-062246\",\"status\":\"Approved\",\"subjectCode\":\"MAT10\",\"semester\":\"1\",\"component\":\"Practical\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}',NULL,NULL,NULL,'2026-05-23 06:22:46','2026-05-23 06:22:46'),('REC-20260523-082328',1,'Teacher subject attendance','BA English attendance sample','ATT-UI-SMOKE','Submitted','{\"attendance\":{\"7\":\"Absent\",\"6\":\"Present\"},\"subjectCode\":\"BA-ENG\",\"attendanceDate\":\"2026-05-23\",\"name\":\"BA English attendance sample\",\"module\":\"Teacher subject attendance\",\"sectionId\":\"7\",\"code\":\"ATT-UI-SMOKE\",\"status\":\"Submitted\",\"classId\":\"8\"}',NULL,NULL,NULL,'2026-05-23 06:23:28','2026-05-23 06:23:28'),('REC-20260523-082348',1,'Teacher marks entry','BA English Semester 1 Theory','MARKS-UI-THEORY-2','Submitted','{\"maxMarks\":\"80\",\"subjectCode\":\"BA-ENG\",\"marks\":{\"7\":\"65\",\"6\":\"72\"},\"semester\":\"1\",\"name\":\"BA English Semester 1 Theory\",\"module\":\"Teacher marks entry\",\"sectionId\":\"7\",\"code\":\"MARKS-UI-THEORY-2\",\"status\":\"Submitted\",\"component\":\"Theory\",\"classId\":\"8\"}',NULL,NULL,NULL,'2026-05-23 06:23:48','2026-05-23 06:23:48'),('REC-20260523-082400',1,'Teacher marks entry','BA English Semester 1 Practical','MARKS-UI-PRACT-115400','Submitted','{\"maxMarks\":\"50\",\"subjectCode\":\"BA-ENG\",\"marks\":{\"7\":\"40\",\"6\":\"44\"},\"semester\":\"1\",\"name\":\"BA English Semester 1 Practical\",\"module\":\"Teacher marks entry\",\"sectionId\":\"7\",\"code\":\"MARKS-UI-PRACT-115400\",\"status\":\"Submitted\",\"component\":\"Practical\",\"classId\":\"8\"}',NULL,NULL,NULL,'2026-05-23 06:24:00','2026-05-23 06:24:00'),('REC-20260523-082401',1,'Teacher subject attendance','BA English attendance sample','ATT-UI-115400','Submitted','{\"attendance\":{\"7\":\"Absent\",\"6\":\"Present\"},\"subjectCode\":\"BA-ENG\",\"attendanceDate\":\"2026-05-23\",\"name\":\"BA English attendance sample\",\"module\":\"Teacher subject attendance\",\"sectionId\":\"7\",\"code\":\"ATT-UI-115400\",\"status\":\"Submitted\",\"classId\":\"8\"}',NULL,NULL,NULL,'2026-05-23 06:24:01','2026-05-23 06:24:01'),('REC-20260523-082411',1,'Teacher class setup','Semester 1 / B','CLASS-SETUP-062411','Approved','{\"module\":\"Teacher class setup\",\"name\":\"Semester 1 \\/ B\",\"code\":\"CLASS-SETUP-062411\",\"status\":\"Approved\",\"classId\":\"8\",\"semester\":\"Semester 1\",\"section\":\"B\",\"capacity\":\"30\"}',1,'2026-05-23 11:54:34',NULL,'2026-05-23 06:24:11','2026-05-23 06:24:34'),('REC-20260523-082712',1,'Teacher subject master','sanskrit','SANK001','Approved','{\"module\":\"Teacher subject master\",\"name\":\"sanskrit\",\"code\":\"SANK001\",\"status\":\"Approved\",\"category\":\"Second language\"}',NULL,NULL,NULL,'2026-05-23 06:27:12','2026-05-23 06:27:12'),('REC-20260523-082942',1,'Teacher subject map','Sociology class mapping','BA-SOC','Approved','{\"module\":\"Teacher subject map\",\"name\":\"Sociology class mapping\",\"code\":\"BA-SOC\",\"status\":\"Approved\",\"classId\":\"8\",\"sectionId\":\"8\",\"semester\":\"1\",\"subjectCode\":\"BA-SOC\",\"category\":\"Optional\",\"mandatory\":\"No\",\"categories\":[\"Optional\"]}',NULL,NULL,NULL,'2026-05-23 06:29:42','2026-05-23 06:29:42'),('REC-20260523-084246',1,'Teacher exam component','SANK001 Theory','EXAM-COMP-064246','Approved','{\"module\":\"Teacher exam component\",\"name\":\"SANK001 Theory\",\"code\":\"EXAM-COMP-064246\",\"status\":\"Approved\",\"subjectCode\":\"SANK001\",\"semester\":\"2\",\"component\":\"Theory\",\"maxMarks\":\"80\",\"passingMarks\":\"28\"}',NULL,NULL,NULL,'2026-05-23 06:42:46','2026-05-23 06:42:46'),('REC-20260523-084314',1,'Teacher subject attendance','SANK001 attendance','ATT-064314','Submitted','{\"module\":\"Teacher subject attendance\",\"name\":\"SANK001 attendance\",\"code\":\"ATT-064314\",\"status\":\"Submitted\",\"classId\":\"8\",\"sectionId\":\"8\",\"subjectCode\":\"SANK001\",\"attendanceDate\":\"2026-05-23\",\"attendance\":{\"8\":\"Present\"}}',NULL,NULL,NULL,'2026-05-23 06:43:14','2026-05-23 06:43:14'),('REC-20260523-084438',10,'Section subject assignment','Save section subjects','SAVE-SECTION-064438','Active','{\"module\":\"Section subject assignment\",\"name\":\"Save section subjects\",\"code\":\"SAVE-SECTION-064438\",\"status\":\"Active\"}',NULL,NULL,NULL,'2026-05-23 06:44:38','2026-05-23 06:44:38'),('REC-20260523-090603',10,'Teacher class setup','Semester 2 / A','CLASS-SETUP-070602','Pending admin sync','{\"module\":\"Teacher class setup\",\"name\":\"Semester 2 \\/ A\",\"code\":\"CLASS-SETUP-070602\",\"status\":\"Pending admin sync\",\"classId\":\"8\",\"semester\":\"Semester 2\",\"section\":\"A\",\"capacity\":\"60\"}',NULL,NULL,NULL,'2026-05-23 07:06:03','2026-05-23 07:06:03');
/*!40000 ALTER TABLE `erp_saved_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_section_subjects`
--

DROP TABLE IF EXISTS `erp_section_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_section_subjects` (
  `section_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `semester_no` tinyint(3) unsigned DEFAULT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`section_id`,`subject_id`),
  KEY `fk_erp_section_subject_subject` (`subject_id`),
  CONSTRAINT `fk_erp_section_subject_section` FOREIGN KEY (`section_id`) REFERENCES `erp_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_section_subject_subject` FOREIGN KEY (`subject_id`) REFERENCES `erp_subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_section_subjects`
--

LOCK TABLES `erp_section_subjects` WRITE;
/*!40000 ALTER TABLE `erp_section_subjects` DISABLE KEYS */;
INSERT INTO `erp_section_subjects` VALUES (4,6,1,1),(4,7,1,1),(4,8,2,1),(5,9,3,1),(5,10,3,1),(5,11,4,1),(5,12,4,1),(6,13,5,1),(6,14,6,1),(7,15,1,1),(7,16,1,1),(7,17,1,1),(7,18,1,0),(7,19,1,0),(7,20,1,0),(7,21,1,0),(8,22,1,1),(8,26,1,1),(8,27,1,1),(8,28,1,1),(8,29,1,1),(8,30,1,1),(8,32,2,0),(9,23,1,1),(9,24,1,1),(9,25,2,1),(10,15,3,1),(10,16,3,1),(10,17,3,1),(10,18,3,0),(10,19,3,0),(10,20,3,0),(10,21,3,0),(11,15,5,1),(11,16,5,1),(11,17,5,1),(11,18,5,0),(11,19,5,0),(11,20,5,0),(11,21,5,0),(12,22,3,1),(12,26,3,1),(12,27,3,1),(12,28,3,1),(12,29,3,1),(12,30,3,1),(13,22,5,1),(13,26,5,1),(13,27,5,1),(13,28,5,1),(13,29,5,1),(13,30,5,1),(14,15,1,1);
/*!40000 ALTER TABLE `erp_section_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_sections`
--

DROP TABLE IF EXISTS `erp_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_sections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `name` varchar(40) NOT NULL,
  `capacity` int(10) unsigned NOT NULL DEFAULT 40,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_sections_class_name` (`class_id`,`name`),
  CONSTRAINT `fk_erp_sections_class` FOREIGN KEY (`class_id`) REFERENCES `erp_classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_sections`
--

LOCK TABLES `erp_sections` WRITE;
/*!40000 ALTER TABLE `erp_sections` DISABLE KEYS */;
INSERT INTO `erp_sections` VALUES (1,1,'A',45),(2,2,'A',50),(3,3,'Commerce A',60),(4,4,'BCA A',60),(5,5,'BCA A',60),(6,6,'BCA A',60),(7,7,'Arts A',80),(8,8,'Science A',80),(9,9,'Education A',50),(10,10,'Arts A',80),(11,11,'Arts A',80),(12,12,'Science A',80),(13,13,'Science A',80),(14,8,'B',75);
/*!40000 ALTER TABLE `erp_sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_staff`
--

DROP TABLE IF EXISTS `erp_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_staff` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `employee_no` varchar(60) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `role` varchar(80) NOT NULL,
  `department` varchar(120) DEFAULT NULL,
  `status` enum('active','on_leave','inactive') NOT NULL DEFAULT 'active',
  `joined_on` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_staff_emp_no` (`institution_id`,`employee_no`),
  CONSTRAINT `fk_erp_staff_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_staff`
--

LOCK TABLES `erp_staff` WRITE;
/*!40000 ALTER TABLE `erp_staff` DISABLE KEYS */;
INSERT INTO `erp_staff` VALUES (1,1,'EMP-001','Asha','Mehta','asha.mehta@campussuite.edu','+91 90000 00001','Principal','Administration','active','2019-06-01'),(2,1,'EMP-014','Rahul','Sharma','rahul.sharma@campussuite.edu','+91 90000 00014','Teacher','Science','active','2021-07-12'),(3,1,'EMP-021','Nisha','Iyer','nisha.iyer@campussuite.edu','+91 90000 00021','Accountant','Finance','active','2020-05-15'),(4,1,'EMP-045','Iqbal','Khan','iqbal.khan@campussuite.edu','+91 90000 00045','Driver','Transport','active','2022-04-01');
/*!40000 ALTER TABLE `erp_staff` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_student_guardians`
--

DROP TABLE IF EXISTS `erp_student_guardians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_student_guardians` (
  `student_id` int(10) unsigned NOT NULL,
  `guardian_id` int(10) unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`student_id`,`guardian_id`),
  KEY `fk_erp_sg_guardian` (`guardian_id`),
  CONSTRAINT `fk_erp_sg_guardian` FOREIGN KEY (`guardian_id`) REFERENCES `erp_guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_sg_student` FOREIGN KEY (`student_id`) REFERENCES `erp_students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_student_guardians`
--

LOCK TABLES `erp_student_guardians` WRITE;
/*!40000 ALTER TABLE `erp_student_guardians` DISABLE KEYS */;
INSERT INTO `erp_student_guardians` VALUES (1,1,1),(2,2,1),(3,3,1),(5,5,1),(6,6,1),(7,7,1),(8,8,1);
/*!40000 ALTER TABLE `erp_student_guardians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_students`
--

DROP TABLE IF EXISTS `erp_students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_students` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `class_id` int(10) unsigned NOT NULL,
  `section_id` int(10) unsigned NOT NULL,
  `admission_no` varchar(60) NOT NULL,
  `roll_no` varchar(40) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `gender` enum('female','male','other') NOT NULL,
  `date_of_birth` date NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `status` enum('applicant','active','alumni','left','suspended') NOT NULL DEFAULT 'active',
  `admitted_on` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_student_admission` (`institution_id`,`admission_no`),
  KEY `idx_erp_student_class` (`class_id`,`section_id`),
  KEY `fk_erp_student_year` (`academic_year_id`),
  KEY `fk_erp_student_section` (`section_id`),
  CONSTRAINT `fk_erp_student_class` FOREIGN KEY (`class_id`) REFERENCES `erp_classes` (`id`),
  CONSTRAINT `fk_erp_student_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_student_section` FOREIGN KEY (`section_id`) REFERENCES `erp_sections` (`id`),
  CONSTRAINT `fk_erp_student_year` FOREIGN KEY (`academic_year_id`) REFERENCES `erp_academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_students`
--

LOCK TABLES `erp_students` WRITE;
/*!40000 ALTER TABLE `erp_students` DISABLE KEYS */;
INSERT INTO `erp_students` VALUES (1,1,1,1,1,'CS-2026-001','10A-01','Aarav','Rao','male','2011-08-18','aarav.rao@student.campussuite.edu',NULL,'active','2026-04-05','2026-05-20 13:19:33'),(2,1,1,2,2,'CS-2026-002','11A-04','Kiara','Patel','female','2010-01-22','kiara.patel@student.campussuite.edu',NULL,'active','2026-04-05','2026-05-20 13:19:33'),(3,1,1,3,3,'CS-2026-003','BCOM-08','Dev','Das','male','2008-10-02','dev.das@student.campussuite.edu',NULL,'active','2026-04-07','2026-05-20 13:19:33'),(5,1,1,8,7,'ADM-2026-0009',NULL,'Minimal','Test','other','2008-01-01',NULL,'+91 90000 00456','active','2026-05-21','2026-05-21 17:34:27'),(6,1,1,7,7,'ADM-2026-0001',NULL,'Mira','Kulkarnee','other','2000-11-18','rohan.kulkarni@example.com','+91 94444 44444','active','2026-05-22','2026-05-21 18:50:20'),(7,1,1,7,7,'ADM-2026-0013',NULL,'Paid','Test','other','2008-01-01',NULL,'+91 90000 88888','active','2026-05-23','2026-05-22 18:40:08'),(8,1,1,8,8,'ADM-2026-0008',NULL,'Aayu','Bhalerao','other','2025-05-08',NULL,'8830350465','active','2026-05-23','2026-05-23 03:25:13');
/*!40000 ALTER TABLE `erp_students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_subject_group_subjects`
--

DROP TABLE IF EXISTS `erp_subject_group_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_subject_group_subjects` (
  `group_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`group_id`,`subject_id`),
  KEY `fk_erp_sgs_subject` (`subject_id`),
  CONSTRAINT `fk_erp_sgs_group` FOREIGN KEY (`group_id`) REFERENCES `erp_subject_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_sgs_subject` FOREIGN KEY (`subject_id`) REFERENCES `erp_subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_subject_group_subjects`
--

LOCK TABLES `erp_subject_group_subjects` WRITE;
/*!40000 ALTER TABLE `erp_subject_group_subjects` DISABLE KEYS */;
INSERT INTO `erp_subject_group_subjects` VALUES (1,15),(1,16),(1,17),(2,18),(2,19),(2,20),(2,21),(3,22),(3,26),(3,27),(4,28),(4,29),(4,30);
/*!40000 ALTER TABLE `erp_subject_group_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_subject_groups`
--

DROP TABLE IF EXISTS `erp_subject_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_subject_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `course_name` varchar(120) NOT NULL,
  `group_name` varchar(160) NOT NULL,
  `description` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_subject_group` (`institution_id`,`course_name`,`group_name`),
  CONSTRAINT `fk_erp_subject_group_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_subject_groups`
--

LOCK TABLES `erp_subject_groups` WRITE;
/*!40000 ALTER TABLE `erp_subject_groups` DISABLE KEYS */;
INSERT INTO `erp_subject_groups` VALUES (1,1,'BA','Languages','SRTMUN humanities language subjects offered by the college'),(2,1,'BA','Arts / Social Sciences','SRTMUN humanities optional and social science subjects'),(3,1,'B.Sc.','Life Sciences','Botany, Microbiology and Zoology subject group'),(4,1,'B.Sc.','Physical Sciences','Chemistry, Physics and Mathematics subject group');
/*!40000 ALTER TABLE `erp_subject_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_subjects`
--

DROP TABLE IF EXISTS `erp_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_subjects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(160) NOT NULL,
  `subject_type` enum('core','elective','activity') NOT NULL DEFAULT 'core',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_subject_code` (`institution_id`,`code`),
  CONSTRAINT `fk_erp_subject_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_subjects`
--

LOCK TABLES `erp_subjects` WRITE;
/*!40000 ALTER TABLE `erp_subjects` DISABLE KEYS */;
INSERT INTO `erp_subjects` VALUES (1,1,'MAT10','Mathematics','core'),(2,1,'SCI10','Science','core'),(3,1,'PHY11','Physics','core'),(4,1,'ACC101','Financial Accounting','core'),(5,1,'LIB','Library Period','activity'),(6,1,'BCA101','Fundamentals of Computer Science and IT','core'),(7,1,'BCA102','Programming in C','core'),(8,1,'BCA103','Web Technologies','core'),(9,1,'BCA201','Data Structure','core'),(10,1,'BCA202','Database Management System','core'),(11,1,'BCA301','Programming in Java','core'),(12,1,'BCA302','Computer Network','core'),(13,1,'BCA501','Windows Programming','elective'),(14,1,'BCA602','Fundamentals of Image Processing','elective'),(15,1,'BA-ENG','English','core'),(16,1,'BA-HIN','Hindi','core'),(17,1,'BA-MAR','Marathi','core'),(18,1,'BA-POL','Political Science','elective'),(19,1,'BA-SOC','Sociology','elective'),(20,1,'BA-GEO','Geography','elective'),(21,1,'BA-ECO','Economics','elective'),(22,1,'BSC-BOT','Botany','core'),(23,1,'BED101','Childhood and Growing Up','core'),(24,1,'BED102','Contemporary India and Education','core'),(25,1,'BED103','Learning and Teaching','core'),(26,1,'BSC-MIC','Microbiology','core'),(27,1,'BSC-ZOO','Zoology','core'),(28,1,'BSC-CHE','Chemistry','core'),(29,1,'BSC-PHY','Physics','core'),(30,1,'BSC-MAT','Mathematics','core'),(31,1,'ADM-DIRECT-120315','Admin Direct Subject 120315','core'),(32,1,'SANK001','sanskrit','elective');
/*!40000 ALTER TABLE `erp_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_timetable_periods`
--

DROP TABLE IF EXISTS `erp_timetable_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_timetable_periods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `section_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `staff_id` int(10) unsigned NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `starts_at` time NOT NULL,
  `ends_at` time NOT NULL,
  `room` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_timetable_section_day` (`section_id`,`day_of_week`),
  KEY `fk_erp_tt_subject` (`subject_id`),
  KEY `fk_erp_tt_staff` (`staff_id`),
  CONSTRAINT `fk_erp_tt_section` FOREIGN KEY (`section_id`) REFERENCES `erp_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_tt_staff` FOREIGN KEY (`staff_id`) REFERENCES `erp_staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_tt_subject` FOREIGN KEY (`subject_id`) REFERENCES `erp_subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_timetable_periods`
--

LOCK TABLES `erp_timetable_periods` WRITE;
/*!40000 ALTER TABLE `erp_timetable_periods` DISABLE KEYS */;
INSERT INTO `erp_timetable_periods` VALUES (1,1,1,2,1,'09:00:00','09:45:00','Room 10A'),(2,1,2,2,1,'09:50:00','10:35:00','Science Lab'),(3,2,3,2,2,'10:45:00','11:30:00','Physics Lab');
/*!40000 ALTER TABLE `erp_timetable_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_transport_assignments`
--

DROP TABLE IF EXISTS `erp_transport_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_transport_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `route_id` int(10) unsigned NOT NULL,
  `stop_id` int(10) unsigned NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_erp_ta_student` (`student_id`),
  KEY `fk_erp_ta_route` (`route_id`),
  KEY `fk_erp_ta_stop` (`stop_id`),
  CONSTRAINT `fk_erp_ta_route` FOREIGN KEY (`route_id`) REFERENCES `erp_transport_routes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_ta_stop` FOREIGN KEY (`stop_id`) REFERENCES `erp_transport_stops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_ta_student` FOREIGN KEY (`student_id`) REFERENCES `erp_students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_transport_assignments`
--

LOCK TABLES `erp_transport_assignments` WRITE;
/*!40000 ALTER TABLE `erp_transport_assignments` DISABLE KEYS */;
INSERT INTO `erp_transport_assignments` VALUES (1,3,1,2,'2026-04-10',NULL);
/*!40000 ALTER TABLE `erp_transport_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_transport_routes`
--

DROP TABLE IF EXISTS `erp_transport_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_transport_routes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `vehicle_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `route_code` varchar(40) NOT NULL,
  `monthly_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_route_code` (`institution_id`,`route_code`),
  KEY `fk_erp_route_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_erp_route_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_route_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `erp_vehicles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_transport_routes`
--

LOCK TABLES `erp_transport_routes` WRITE;
/*!40000 ALTER TABLE `erp_transport_routes` DISABLE KEYS */;
INSERT INTO `erp_transport_routes` VALUES (1,1,1,'Baner - Campus','R-01',3500.00);
/*!40000 ALTER TABLE `erp_transport_routes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_transport_stops`
--

DROP TABLE IF EXISTS `erp_transport_stops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_transport_stops` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `route_id` int(10) unsigned NOT NULL,
  `stop_order` int(11) NOT NULL DEFAULT 0,
  `name` varchar(180) NOT NULL,
  `pickup_time` time DEFAULT NULL,
  `drop_time` time DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_erp_stop_route` (`route_id`),
  CONSTRAINT `fk_erp_stop_route` FOREIGN KEY (`route_id`) REFERENCES `erp_transport_routes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_transport_stops`
--

LOCK TABLES `erp_transport_stops` WRITE;
/*!40000 ALTER TABLE `erp_transport_stops` DISABLE KEYS */;
INSERT INTO `erp_transport_stops` VALUES (1,1,1,'Baner High Street','07:20:00','15:45:00'),(2,1,2,'Aundh ITI Road','07:35:00','15:30:00');
/*!40000 ALTER TABLE `erp_transport_stops` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_vehicles`
--

DROP TABLE IF EXISTS `erp_vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `erp_vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `institution_id` int(10) unsigned NOT NULL,
  `registration_no` varchar(40) NOT NULL,
  `vehicle_type` varchar(80) NOT NULL,
  `capacity` int(10) unsigned NOT NULL,
  `driver_staff_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','maintenance','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_vehicle_reg` (`institution_id`,`registration_no`),
  KEY `fk_erp_vehicle_driver` (`driver_staff_id`),
  CONSTRAINT `fk_erp_vehicle_driver` FOREIGN KEY (`driver_staff_id`) REFERENCES `erp_staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_vehicle_inst` FOREIGN KEY (`institution_id`) REFERENCES `erp_institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_vehicles`
--

LOCK TABLES `erp_vehicles` WRITE;
/*!40000 ALTER TABLE `erp_vehicles` DISABLE KEYS */;
INSERT INTO `erp_vehicles` VALUES (1,1,'MH12CS1001','Bus',48,4,'active');
/*!40000 ALTER TABLE `erp_vehicles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(200) NOT NULL,
  `title` varchar(500) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content_html` mediumtext NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_events_slug` (`slug`),
  KEY `idx_events_pub` (`status`,`published_at`),
  KEY `idx_events_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `events`
--

LOCK TABLES `events` WRITE;
/*!40000 ALTER TABLE `events` DISABLE KEYS */;
INSERT INTO `events` VALUES (1,'orientation-day-2026','Orientation day 2026','Welcome sessions for new BA and BSc students and parents.','Orientation day introduces new students to college departments, subjects, timetable, library, office process and student support.',0,'published','2026-05-30 18:49:33','2026-05-20 13:19:33','2026-05-23 09:58:15'),(2,'term-one-exam-planning','Term one exam planning','Exam notice and planning for semester assessment.','The examination section will publish timetable, seating, practical and theory exam instructions for students.',1,'published','2026-06-10 18:49:33','2026-05-20 13:19:33','2026-05-23 09:58:15');
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gallery_items`
--

DROP TABLE IF EXISTS `gallery_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gallery_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `media_type` enum('image','video') NOT NULL,
  `url` varchar(1024) NOT NULL,
  `caption` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gallery_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gallery_items`
--

LOCK TABLES `gallery_items` WRITE;
/*!40000 ALTER TABLE `gallery_items` DISABLE KEYS */;
INSERT INTO `gallery_items` VALUES (1,0,'image','https://images.unsplash.com/photo-1580582932707-520aed937b7b?w=1200&q=80&auto=format&fit=crop','Smart classrooms','2026-05-20 13:19:33'),(2,1,'image','https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=1200&q=80&auto=format&fit=crop','Library and resources','2026-05-20 13:19:33'),(3,2,'image','https://images.unsplash.com/photo-1546519638-68e109498ffc?w=1200&q=80&auto=format&fit=crop','Sports and student life','2026-05-20 13:19:33');
/*!40000 ALTER TABLE `gallery_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `highlight_slots`
--

DROP TABLE IF EXISTS `highlight_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `highlight_slots` (
  `slot_key` varchar(16) NOT NULL,
  `post_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`slot_key`),
  KEY `fk_highlight_slot_post` (`post_id`),
  CONSTRAINT `fk_highlight_slot_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `highlight_slots`
--

LOCK TABLES `highlight_slots` WRITE;
/*!40000 ALTER TABLE `highlight_slots` DISABLE KEYS */;
INSERT INTO `highlight_slots` VALUES ('events',1),('news',2);
/*!40000 ALTER TABLE `highlight_slots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nav_items`
--

DROP TABLE IF EXISTS `nav_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nav_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `label` varchar(160) NOT NULL,
  `page_id` int(10) unsigned DEFAULT NULL,
  `post_id` int(10) unsigned DEFAULT NULL,
  `url` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nav_parent` (`parent_id`),
  KEY `idx_nav_page` (`page_id`),
  KEY `idx_nav_post` (`post_id`),
  KEY `idx_nav_sort` (`parent_id`,`sort_order`),
  CONSTRAINT `fk_nav_items_page` FOREIGN KEY (`page_id`) REFERENCES `site_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nav_items_parent` FOREIGN KEY (`parent_id`) REFERENCES `nav_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nav_items_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nav_items`
--

LOCK TABLES `nav_items` WRITE;
/*!40000 ALTER TABLE `nav_items` DISABLE KEYS */;
INSERT INTO `nav_items` VALUES (1,NULL,0,'About',1,NULL,NULL),(2,NULL,1,'Admissions',2,NULL,NULL),(3,NULL,5,'Gallery',3,NULL,NULL),(4,NULL,6,'News & events',5,NULL,NULL),(5,NULL,7,'ERP Login',NULL,NULL,'/erp'),(6,NULL,3,'IQAC',6,NULL,NULL),(7,6,0,'About IQAC',7,NULL,NULL),(8,6,1,'IQAC Composition',8,NULL,NULL),(9,6,2,'Minutes & ATR',9,NULL,NULL),(10,6,3,'AQAR Reports',10,NULL,NULL),(11,6,4,'Best Practices',11,NULL,NULL),(12,NULL,2,'Academics',12,NULL,NULL),(13,12,0,'Courses Offered',13,NULL,NULL),(14,12,1,'Science Faculty',14,NULL,NULL),(15,12,2,'Arts Faculty',15,NULL,NULL),(16,NULL,4,'Student Corner',16,NULL,NULL),(17,16,0,'Downloads',17,NULL,NULL),(18,16,1,'Scholarships',18,NULL,NULL),(19,16,2,'Examination',19,NULL,NULL);
/*!40000 ALTER TABLE `nav_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `post_categories`
--

DROP TABLE IF EXISTS `post_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `post_categories` (
  `post_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`post_id`,`category_id`),
  KEY `idx_pc_category` (`category_id`),
  CONSTRAINT `fk_pc_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `post_categories`
--

LOCK TABLES `post_categories` WRITE;
/*!40000 ALTER TABLE `post_categories` DISABLE KEYS */;
INSERT INTO `post_categories` VALUES (1,1),(1,3),(2,2),(2,4);
/*!40000 ALTER TABLE `post_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `posts`
--

DROP TABLE IF EXISTS `posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(500) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content_html` mediumtext NOT NULL,
  `cover_image_url` varchar(1024) DEFAULT NULL,
  `pdf_url` varchar(1024) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_posts_slug` (`slug`),
  KEY `idx_posts_status_published` (`status`,`published_at`),
  KEY `fk_posts_user` (`user_id`),
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `posts`
--

LOCK TABLES `posts` WRITE;
/*!40000 ALTER TABLE `posts` DISABLE KEYS */;
INSERT INTO `posts` VALUES (1,1,'Welcome to Late Baburao Patil Arts and Science College','welcome-late-baburao-patil-college','Admissions, courses, notices, events and campus updates for students and parents of the college.','Late Baburao Patil Arts and Science College, Hingoli welcomes students to BA and BSc programs with Science and Arts subject choices, academic support and an active campus notice system.','https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=1600&q=80&auto=format&fit=crop',NULL,'published','2026-05-17 18:49:33','2026-05-20 13:19:33','2026-05-23 09:56:35'),(2,1,'Admissions open for BA and B.Sc. 2026-27','admissions-open-2026-27','Apply for BA and B.Sc. programs and follow the admission notices from the college office.','The college admission office publishes BA and B.Sc. application updates, eligibility notes and important dates on this website.','https://images.unsplash.com/photo-1523580846011-d3a5bc25702b?w=1600&q=80&auto=format&fit=crop',NULL,'published','2026-05-19 18:49:33','2026-05-20 13:19:33','2026-05-23 09:58:58');
/*!40000 ALTER TABLE `posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_chrome`
--

DROP TABLE IF EXISTS `site_chrome`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_chrome` (
  `id` tinyint(3) unsigned NOT NULL,
  `header_json` longtext NOT NULL,
  `footer_json` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_chrome`
--

LOCK TABLES `site_chrome` WRITE;
/*!40000 ALTER TABLE `site_chrome` DISABLE KEYS */;
INSERT INTO `site_chrome` VALUES (1,'{\"minHeightPx\":118,\"maxHeightPx\":null,\"leftLogos\":[{\"url\":\"https:\\/\\/cache.careers360.mobi\\/media\\/colleges\\/social-media\\/logo\\/Logo_of_Late_Baburao_Patil_Arts_and_Science_College_Hingoli_Logo.png\",\"alt\":\"LATE BABURAO PATIL ARTS AND SCIENCE COLLEGE\",\"maxHeightPx\":56}],\"rightLogos\":[{\"url\":\"https:\\/\\/srtmun-uims.org\\/assets\\/logo.png\",\"alt\":\"SRTMUN\",\"maxHeightPx\":56}],\"center\":{\"mode\":\"text\",\"imageUrl\":null,\"imageMaxHeightPx\":112,\"lines\":[{\"text\":\"Shri Sharda Bhavan Education Society\",\"fontSizePx\":13,\"fontWeight\":\"700\",\"fontStyle\":\"italic\",\"fontFamily\":\"serif\",\"color\":\"#075985\"},{\"text\":\"LATE BABURAO PATIL ARTS AND SCIENCE COLLEGE\",\"fontSizePx\":42,\"fontWeight\":\"900\",\"fontStyle\":\"normal\",\"fontFamily\":\"serif\",\"color\":\"#0f172a\"},{\"text\":\"Hingoli, Maharashtra | Phone: +91 2456 220000 | Email: office@lbpcollege.edu.in\",\"fontSizePx\":15,\"fontWeight\":\"600\",\"fontStyle\":\"normal\",\"fontFamily\":\"sans\",\"color\":\"#475569\"}]}}','{\"mode\":\"text\",\"imageUrl\":null,\"imageMaxHeightPx\":56,\"lines\":[{\"text\":\"Late Baburao Patil Arts and Science College, Hingoli\",\"fontSizePx\":22,\"fontWeight\":\"800\",\"fontStyle\":\"normal\",\"fontFamily\":\"serif\",\"color\":\"#ffffff\"},{\"text\":\"BA, B.Sc. and Arts & Science programs | Admissions, notices, events and campus updates\",\"fontSizePx\":14,\"fontWeight\":\"400\",\"fontStyle\":\"normal\",\"fontFamily\":\"sans\",\"color\":\"#cbd5e1\"},{\"text\":\"Phone: +91 2456 220000 | Email: office@lbpcollege.edu.in\",\"fontSizePx\":14,\"fontWeight\":\"600\",\"fontStyle\":\"normal\",\"fontFamily\":\"sans\",\"color\":\"#bae6fd\"}]}','2026-05-24 09:17:14');
/*!40000 ALTER TABLE `site_chrome` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_home`
--

DROP TABLE IF EXISTS `site_home`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_home` (
  `id` tinyint(3) unsigned NOT NULL,
  `content_json` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_home`
--

LOCK TABLES `site_home` WRITE;
/*!40000 ALTER TABLE `site_home` DISABLE KEYS */;
INSERT INTO `site_home` VALUES (1,'{\"hero\":{\"title\":\"Late Baburao Patil Arts and Science College, Hingoli\",\"subtitle\":\"BA and B.Sc. programs with Arts, Science, student notices, admissions, events and campus updates in one public website.\",\"tagline\":\"Hingoli, Maharashtra | Arts and Science College\",\"image_url\":\"https:\\/\\/i.makeagif.com\\/media\\/10-11-2023\\/FARBcP.gif\",\"primary_cta_label\":\"Admissions\",\"primary_cta_href\":\"\\/p\\/admissions\",\"secondary_cta_label\":\"News & events\",\"secondary_cta_href\":\"\\/p\\/news-events\",\"stats\":[{\"label\":\"Programs\",\"value\":\"BA \\/ B.Sc.\"},{\"label\":\"Science subjects\",\"value\":\"6\"},{\"label\":\"Arts subjects\",\"value\":\"7\"},{\"label\":\"Location\",\"value\":\"Hingoli\"}]},\"sections\":[{\"id\":\"intro-mission\",\"heading\":\"About the college\",\"subheading\":\"A focused Arts and Science institution for Hingoli students\",\"body_html\":\"<p>Late Baburao Patil Arts and Science College provides undergraduate education in Arts and Science streams with academic guidance, student support, notices, admissions information and campus activities published through this website.<\\/p>\",\"variant\":\"default\"},{\"id\":\"programs-spotlight\",\"heading\":\"Courses offered\",\"subheading\":\"BA and B.Sc. with major subject choices\",\"body_html\":\"<p><strong>Science:<\\/strong> Botany, Microbiology, Zoology, Chemistry, Physics and Mathematics.<\\/p><p><strong>Arts:<\\/strong> English, Hindi, Marathi, Political Science, Sociology, Geography and Economics.<\\/p>\",\"variant\":\"muted\"},{\"id\":\"campus-life\",\"heading\":\"Student support and campus life\",\"subheading\":\"Notices, activities and information for students and parents\",\"body_html\":\"<p>Students can follow admission notices, academic updates, events, gallery posts and important announcements. The admin team can update all website content from Web Studio without code changes.<\\/p>\",\"variant\":\"accent\"},{\"id\":\"admin-managed-campus-resources\",\"heading\":\"Campus resources for students\",\"subheading\":\"Managed from the admin home page editor\",\"body_html\":\"<p>The college website can now highlight student services, IQAC updates, admissions, scholarships, examination notices and department resources from the admin panel.<\\/p><ul><li>Important links and navbar menus are dynamic.<\\/li><li>Home sections can be reordered or edited from CMS.<\\/li><li>Posts, pages, gallery, events and carousel content remain admin-managed.<\\/li><\\/ul>\",\"variant\":\"accent\"}],\"show_latest_posts\":true,\"latest_posts_heading\":\"Latest from the college\",\"latest_posts_intro\":\"Announcements, notices and campus stories managed by the college admin team.\"}','2026-05-24 09:13:08');
/*!40000 ALTER TABLE `site_home` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_page_topics`
--

DROP TABLE IF EXISTS `site_page_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_page_topics` (
  `page_slug` varchar(200) NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`page_slug`,`category_id`),
  KEY `idx_site_page_topics_page` (`page_slug`,`sort_order`),
  KEY `idx_site_page_topics_category` (`category_id`),
  CONSTRAINT `fk_site_page_topics_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_page_topics`
--

LOCK TABLES `site_page_topics` WRITE;
/*!40000 ALTER TABLE `site_page_topics` DISABLE KEYS */;
INSERT INTO `site_page_topics` VALUES ('about',1,10),('about',3,20),('admissions',2,10),('admissions',4,20),('gallery',3,10),('home',4,10),('home',2,20),('home',1,30),('home',3,40),('news-events',4,10),('news-events',1,20);
/*!40000 ALTER TABLE `site_page_topics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_pages`
--

DROP TABLE IF EXISTS `site_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(200) NOT NULL,
  `title` varchar(300) NOT NULL,
  `content_html` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_pages_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_pages`
--

LOCK TABLES `site_pages` WRITE;
/*!40000 ALTER TABLE `site_pages` DISABLE KEYS */;
INSERT INTO `site_pages` VALUES (1,'about','About Late Baburao Patil Arts and Science College','Late Baburao Patil Arts and Science College, Hingoli offers undergraduate Arts and Science education with subject choices across BA and BSc programs. The website publishes admissions, notices, gallery, news and events for students, parents and visitors.','2026-05-20 13:19:33','2026-05-23 09:56:54'),(2,'admissions','Admissions','Admissions are open for BA and BSc programs. Students should follow notices, eligibility instructions and office announcements published here. Science subjects: Botany, Microbiology, Zoology, Chemistry, Physics and Mathematics. Arts subjects: English, Hindi, Marathi, Political Science, Sociology, Geography and Economics.','2026-05-20 13:19:33','2026-05-23 09:56:54'),(3,'gallery','Gallery','<div class=\"prose-blog\"><p>Explore classrooms, labs, library, events and campus life.</p></div>','2026-05-20 13:19:33','2026-05-20 13:19:33'),(4,'contact','Contact','Late Baburao Patil Arts and Science College, Hingoli, Maharashtra. Email office@lbpcollege.edu.in or call +91 2456 220000.','2026-05-20 13:19:33','2026-05-23 09:58:15'),(5,'news-events','News & events','<div class=\"prose-blog\"><p>Latest notices, events and academic announcements.</p></div>','2026-05-20 13:19:33','2026-05-20 13:19:33'),(6,'iqac','IQAC','<div class=\"prose-blog\"><p>The Internal Quality Assurance Cell (IQAC) supports quality initiatives, academic planning, institutional review and continuous improvement at the college.</p><p>Use this section for IQAC notices, quality policies, meeting records, AQAR/SSR documents and accreditation-related information.</p></div>','2026-05-24 08:06:46','2026-05-24 08:06:46'),(7,'iqac-about','About IQAC','<div class=\"prose-blog\"><p>The Internal Quality Assurance Cell plans and monitors quality enhancement activities across teaching, learning, evaluation, administration and student support.</p><ul><li>Prepare quality benchmarks.</li><li>Coordinate academic and administrative audits.</li><li>Promote documentation and best practices.</li><li>Support NAAC and institutional accreditation work.</li></ul></div>','2026-05-24 08:06:46','2026-05-24 08:06:46'),(8,'iqac-composition','IQAC Composition','<div class=\"prose-blog\"><p>Publish the IQAC committee structure, coordinator details, member list and responsibilities here.</p><table><thead><tr><th>Role</th><th>Name</th><th>Responsibility</th></tr></thead><tbody><tr><td>Chairperson</td><td>Principal</td><td>Institutional quality leadership</td></tr><tr><td>Coordinator</td><td>IQAC Coordinator</td><td>Meetings, records and reports</td></tr><tr><td>Members</td><td>Faculty and stakeholders</td><td>Quality initiatives and review</td></tr></tbody></table></div>','2026-05-24 08:06:46','2026-05-24 08:06:46'),(9,'iqac-minutes','IQAC Minutes','<div class=\"prose-blog\"><p>Upload and publish IQAC meeting notices, agendas, minutes of meetings and action taken reports. Admin can attach PDF files through posts or page content links.</p></div>','2026-05-24 08:06:46','2026-05-24 08:06:46'),(10,'iqac-aqar','AQAR Reports','<div class=\"prose-blog\"><p>Publish Annual Quality Assurance Report (AQAR), SSR, institutional quality reports and supporting documents for public reference.</p></div>','2026-05-24 08:06:46','2026-05-24 08:06:46'),(11,'iqac-best-practices','Best Practices','<div class=\"prose-blog\"><p>Document institutional best practices, quality initiatives, student support activities, extension work and measurable outcomes.</p></div>','2026-05-24 08:06:46','2026-05-24 08:06:46'),(12,'academics','Academics','<div class=\"prose-blog\"><p>Academic information for BA and B.Sc. programs, departments, subject groups, academic calendar and learning support.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48'),(13,'courses-offered','Courses Offered','<div class=\"prose-blog\"><p><strong>BA:</strong> English, Hindi, Marathi, Political Science, Sociology, Geography and Economics.</p><p><strong>B.Sc.:</strong> Botany, Microbiology, Zoology, Chemistry, Physics and Mathematics.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48'),(14,'science-faculty','Science Faculty','<div class=\"prose-blog\"><p>Science departments include Botany, Microbiology, Zoology, Chemistry, Physics and Mathematics. Publish department profiles, laboratories, faculty details and activities here.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48'),(15,'arts-faculty','Arts Faculty','<div class=\"prose-blog\"><p>Arts departments include English, Hindi, Marathi, Political Science, Sociology, Geography and Economics. Publish department profiles, faculty details and activities here.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48'),(16,'student-corner','Student Corner','<div class=\"prose-blog\"><p>Student notices, downloads, scholarships, examination support and useful links can be managed from this section.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48'),(17,'downloads','Downloads','<div class=\"prose-blog\"><p>Publish student forms, notices, prospectus PDFs, admission documents, certificates and circulars here.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48'),(18,'scholarships','Scholarships','<div class=\"prose-blog\"><p>Publish scholarship schemes, eligibility, application guidance, important dates and required documents.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48'),(19,'examination','Examination','<div class=\"prose-blog\"><p>Publish examination notices, timetables, hall ticket instructions, result updates and university examination links.</p></div>','2026-05-24 08:14:48','2026-05-24 08:14:48');
/*!40000 ALTER TABLE `site_pages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `display_name` varchar(160) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'admin',
  `invite_token_hash` varchar(255) DEFAULT NULL,
  `invite_expires_at` datetime DEFAULT NULL,
  `invite_accepted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin@example.com','Campus Administrator','$2y$10$pPpAto9o5KwI7aq/FZZ39OCT9xRMDa/1aI.7xT/LnCyDV.bVpdTv6','admin',NULL,NULL,NULL,'2026-05-20 13:19:33'),(3,'abamolbhalerao@gmail.com','Amol Bhalerao','$2y$10$NuG9iZUX9sXJLZPN66kBT.TqZNDA33vXI4TKHeAvFiMBi043HUNMO','invite_pending',NULL,NULL,NULL,'2026-05-20 14:51:39'),(5,'clerk@example.com','Campus Clerk','$2y$10$pPpAto9o5KwI7aq/FZZ39OCT9xRMDa/1aI.7xT/LnCyDV.bVpdTv6','clerk',NULL,NULL,'2026-05-21 01:00:50','2026-05-20 19:30:50'),(6,'smoke.user.231839@example.com','Smoke User','$2y$10$iV4baowMHQnHy0qVlC0sb.X.Z.eOhUTLRl503QNBPXKNMW.aMmLJS','clerk','0f43cb5659e63d74f7ea7ec0a88340a5512cf3819e44128aeb458c3e1070f881','2026-05-28 23:18:39','2026-05-21 19:48:39','2026-05-21 17:48:39'),(8,'smoke.proxy.234940@example.com','Proxy Smoke','$2y$10$fDmDLc3/8w3frTNRGY4inOA2ev.b5yFFwcstp7aPkDJJA44DtIIPS','clerk','547e2a3720bda0d7e82f737f7ea4a64a7053d783ae0a1668e1964183660f7e35','2026-05-28 23:49:40','2026-05-21 20:19:40','2026-05-21 18:19:40'),(9,'accountant@gmail.com','aarya accountant','$2y$10$J/0Uxi1SWywwm210NPWFKOPmu0GGbbx3FKGlM367y4YXZpBZh7cPy','accountant','80df51fff816ca0c626df9e28bd87c10b04097a1796f09d99a8e1f37469307a2','2026-05-29 00:16:55','2026-05-21 20:46:55','2026-05-21 18:46:55'),(10,'teacher1@gmail.com','Mr teacher one','$2y$10$sHiNWV/Cv0c109/2491WdORveDZ/DvMzfqucbgdIcBQIocGVTwkPW','teacher','b61b363b4b63ca2f4d798966604dd58391bd1768f8ac7b9588692a1caeefa1a7','2026-05-30 09:12:35','2026-05-23 05:42:35','2026-05-23 03:42:35');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'campus_suite'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-24 16:04:23
