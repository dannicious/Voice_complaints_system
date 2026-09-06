-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 28, 2026 at 03:00 AM
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
-- Database: `voicedb`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` enum('admin','dean') NOT NULL,
  `action` varchar(255) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `role`, `action`, `target_type`, `target_id`, `created_at`) VALUES
(24, 2, 'admin', 'Deleted complaint category', 'complaint_categories', 44, '2026-07-13 12:41:24'),
(25, 2, 'admin', 'Deleted complaint category', 'complaint_categories', 45, '2026-07-13 12:41:28'),
(26, 2, 'admin', 'Deleted complaint category', 'complaint_categories', 46, '2026-07-13 12:41:31'),
(27, 2, 'admin', 'Deleted complaint category', 'complaint_categories', 47, '2026-07-13 12:41:34'),
(28, 2, 'admin', 'Added complaint category', 'complaint_categories', 0, '2026-07-13 12:45:33'),
(29, 2, 'admin', 'Added suggestion category', 'suggestion_categories', 0, '2026-07-13 12:45:50'),
(30, 2, 'admin', 'Added complaint category', 'complaint_categories', 0, '2026-07-13 12:46:53'),
(31, 2, 'admin', 'Added suggestion category', 'suggestion_categories', 0, '2026-07-13 12:47:15'),
(32, 2, 'admin', 'Updated complaint category', 'complaint_categories', 56, '2026-07-27 23:39:33'),
(33, 2, 'admin', 'Updated complaint category', 'complaint_categories', 55, '2026-07-27 23:39:45');

-- --------------------------------------------------------

--
-- Table structure for table `admin_profiles`
--

CREATE TABLE `admin_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_profiles`
--

INSERT INTO `admin_profiles` (`id`, `user_id`, `name`, `created_at`) VALUES
(1, 2, 'adminmain', '2026-03-28 10:37:32');

-- --------------------------------------------------------

--
-- Table structure for table `chatbot_conversations`
--

CREATE TABLE `chatbot_conversations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sender` enum('student','bot') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chatbot_conversations`
--

INSERT INTO `chatbot_conversations` (`id`, `user_id`, `sender`, `message`, `created_at`) VALUES
(26, NULL, 'bot', 'Chatbot is currently offline for maintenance. Please try again later.', '2026-03-28 09:32:18'),
(27, 6, 'student', 'hi', '2026-05-05 04:28:23'),
(28, 6, 'bot', 'Chatbot is currently offline for maintenance. Please try again later.', '2026-05-05 04:28:23'),
(29, 6, 'student', 'j', '2026-07-03 03:53:41'),
(30, 6, 'bot', 'Chatbot is currently offline for maintenance. Please try again later.', '2026-07-03 03:53:41'),
(31, 6, 'student', 'hi', '2026-07-06 13:26:56'),
(32, 6, 'bot', 'Chatbot is currently offline for maintenance. Please try again later.', '2026-07-06 13:26:56'),
(33, 6, 'student', 'hi', '2026-07-12 07:08:27'),
(34, 6, 'bot', 'Hello! How can I assist you today regarding complaints or suggestions?', '2026-07-12 07:08:27'),
(35, 6, 'student', 'how can i submit a suggestion?', '2026-07-12 07:08:38'),
(36, 6, 'bot', 'I\'m not sure about that. Please file a formal complaint.', '2026-07-12 07:08:38'),
(37, 6, 'student', 'how can i submit a suggestion?', '2026-07-12 07:12:46'),
(38, 6, 'bot', 'To submit a suggestion, open the Suggestion Form, write your idea clearly, and press Submit. Your feedback helps improve campus services.', '2026-07-12 07:12:46'),
(39, 6, 'student', 'what about complaints?', '2026-07-12 07:12:57'),
(40, 6, 'bot', 'I\'m not sure about that. Please file a formal complaint.', '2026-07-12 07:12:57'),
(41, 6, 'student', 'how to submit complaints', '2026-07-12 07:17:58'),
(42, 6, 'bot', 'To submit a complaint, go to the Complaint Form, fill in all required details, and click Submit. Make sure your information is complete for faster processing.', '2026-07-12 07:17:58'),
(43, 6, 'student', 'complaints', '2026-07-21 03:52:49'),
(44, 6, 'bot', 'I\'m not sure about that. Please file a formal complaint.', '2026-07-21 03:52:49');

-- --------------------------------------------------------

--
-- Table structure for table `chatbot_settings`
--

CREATE TABLE `chatbot_settings` (
  `id` int(11) NOT NULL,
  `bot_name` varchar(100) DEFAULT 'VOICE Support Bot',
  `welcome_message` text DEFAULT NULL,
  `fallback_message` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chatbot_settings`
--

INSERT INTO `chatbot_settings` (`id`, `bot_name`, `welcome_message`, `fallback_message`, `is_active`, `updated_at`) VALUES
(1, 'VOICE Support', 'Hi! I\'m the VOICE Assistant. How can I help you today?', 'I\'m not sure about that. Please file a formal complaint.', 1, '2026-07-12 07:08:15');

-- --------------------------------------------------------

--
-- Table structure for table `chatbot_triggers`
--

CREATE TABLE `chatbot_triggers` (
  `id` int(11) NOT NULL,
  `keywords` text NOT NULL,
  `response` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chatbot_triggers`
--

INSERT INTO `chatbot_triggers` (`id`, `keywords`, `response`, `is_active`) VALUES
(9, 'what is this system,voice assistant,complaint system', 'This is a Voice Assistant for the Web-Based Information System of BISU Balilihan Campus. It helps students submit complaints and suggestions quickly and easily.', 1),
(10, 'how to complain,submit complaint,file complaint', 'To submit a complaint, go to the Complaint Form, fill in all required details, and click Submit. Make sure your information is complete for faster processing.', 1),
(11, 'how to suggest,submit suggestion,give suggestion', 'To submit a suggestion, open the Suggestion Form, write your idea clearly, and press Submit. Your feedback helps improve campus services.', 1),
(12, 'where to complain,office complaint,who handles complaints', 'Complaints are handled by the assigned office or personnel based on the issue. Please select the correct office when submitting your complaint.', 1),
(13, 'hello,hi,good day', 'Hello! How can I assist you today regarding complaints or suggestions?', 1);

-- --------------------------------------------------------

--
-- Table structure for table `faq_categories`
--

CREATE TABLE `faq_categories` (
  `id` int(10) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faq_questions`
--

CREATE TABLE `faq_questions` (
  `id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `colleges`
--

CREATE TABLE `colleges` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `colleges`
--

INSERT INTO `colleges` (`id`, `code`, `name`, `status`, `created_at`) VALUES
(1, 'CCIS', 'College of Computing and Information Sciences', 'active', '2026-03-28 08:50:35'),
(2, 'CTAS', 'College of Technology and Allied Sciences', 'active', '2026-03-28 10:23:29'),
(3, 'CCJ', 'College of Criminal Justice', 'active', '2026-05-04 15:29:43');

-- --------------------------------------------------------

--
-- Table structure for table `complaints`
--

CREATE TABLE `complaints` (
  `id` int(11) NOT NULL,
  `ticket_no` varchar(20) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `complainant_name` varchar(100) NOT NULL,
  `complainant_address` text NOT NULL,
  `complainant_sex` enum('male','female','other') DEFAULT NULL,
  `complainant_age` int(3) DEFAULT NULL,
  `complainant_civil_status` varchar(50) DEFAULT NULL,
  `complainant_contact_details` varchar(255) DEFAULT NULL,
  `person_complained_of` varchar(100) NOT NULL,
  `date_of_incident` date NOT NULL,
  `time_of_incident` time DEFAULT NULL,
  `place_of_incident` varchar(255) NOT NULL,
  `act_complained_of` text NOT NULL,
  `narrative_report` longtext NOT NULL,
  `college_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `proof_of_complaint` text DEFAULT NULL,
  `attachments` varchar(255) DEFAULT NULL,
  `desired_outcome` text DEFAULT NULL,
  `terms_agreement_accepted` tinyint(1) DEFAULT 1,
  `signature` varchar(255) DEFAULT NULL,
  `status` enum('new','pending','under_review','resolved','closed') DEFAULT 'new',
  `is_anonymous` tinyint(1) DEFAULT 0,
  `approval_status` varchar(50) DEFAULT 'pending',
  `admin_id` int(11) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `admin_reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `school_year` varchar(9) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_escalated` tinyint(1) DEFAULT 0,
  `visibility_status` varchar(50) DEFAULT 'private' COMMENT 'private, public'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `complaints`
--

INSERT INTO `complaints` (`id`, `ticket_no`, `student_id`, `complainant_name`, `complainant_address`, `complainant_sex`, `complainant_age`, `complainant_civil_status`, `complainant_contact_details`, `person_complained_of`, `date_of_incident`, `time_of_incident`, `place_of_incident`, `act_complained_of`, `narrative_report`, `college_id`, `category_id`, `proof_of_complaint`, `attachments`, `desired_outcome`, `terms_agreement_accepted`, `signature`, `status`, `is_anonymous`, `approval_status`, `admin_id`, `admin_notes`, `admin_reviewed_at`, `created_at`, `updated_at`, `is_escalated`, `visibility_status`) VALUES
(18, 'VOX-C-2026-4518', 2, 'Benjamen Daraman', 'sambog, corella, bohol', 'male', 26, 'single', '09765432157', 'School Guard', '2026-07-13', '08:50:00', 'School Gate', 'harass', 'nasuko ang guard kay blablabla then he act like bleblebledkjb', NULL, 55, NULL, 'assets/uploads/complaints/complaints_1783947012_2a20ffe6.jpg', 'unta ma fixed ni ug dina ni mausab', 1, NULL, 'under_review', 0, 'approved', NULL, NULL, NULL, '2026-07-13 12:50:12', '2026-07-13 12:51:55', 0, 'private'),
(19, 'VOX-C-2026-4052', 2, 'Benjamen Daraman', 'sambog, corella, bohol', 'male', 26, 'single', '09765432157', 'hakdog', '2026-07-13', '08:55:00', 'sa lugar nga wala ka', 'basta', 'huhuhuhu', 1, 56, NULL, 'assets/uploads/complaints/complaints_1783947371_1c0cea95.png', 'unta ma fixed', 1, NULL, 'under_review', 0, 'approved', NULL, NULL, NULL, '2026-07-13 12:56:11', '2026-07-13 12:59:53', 0, 'private'),
(20, 'VOX-C-2026-3160', 2, 'Benjamen Daraman', 'sambog', 'male', 26, 'single', '09765432157', 'hakdog', '2026-07-14', '00:41:00', 'School Gate', 'rter', 'fgrtht', NULL, 55, NULL, 'assets/uploads/complaints/complaints_1784004139_362540dd.png', 'rtet', 1, NULL, 'resolved', 0, 'approved', NULL, NULL, NULL, '2026-07-14 04:42:19', '2026-07-14 04:43:36', 0, 'private'),
(21, 'VOX-C-2026-8905', 2, 'Benjamen Daraman', 'sambog', 'female', 59, 'single', '09765432157', 'School Guard', '2026-07-21', '04:07:00', 'School Gate', 'hgg', 'hjg', NULL, 55, NULL, NULL, 'hfh', 1, NULL, 'new', 0, 'approved', NULL, NULL, NULL, '2026-07-21 08:07:55', '2026-07-21 08:07:55', 0, 'private'),
(22, 'VOX-C-2026-2539', 2, 'Benjamen Daraman', 'Not provided', 'male', NULL, 'Not provided', '09123456789', 'Aldrin Cabrera, Andrea Rivera, Andrea Orbeta', '2026-07-01', '19:28:00', 'library', 'for bullying me', '', 1, NULL, NULL, NULL, 'i want to stop them for bullying me', 1, NULL, 'new', 0, 'approved', NULL, NULL, NULL, '2026-07-28 00:08:53', '2026-07-28 00:08:53', 0, 'private'),
(23, 'VOX-C-2026-1020', 2, 'Benjamen Daraman', 'Not provided', 'male', NULL, 'Not provided', '09123456789', 'Camille Isip, Dexter Abad', '2026-07-01', '19:28:00', 'library', 'for bullying me', '', 1, NULL, NULL, NULL, 'i want to stop them for bullying me', 1, NULL, 'new', 0, 'approved', NULL, NULL, NULL, '2026-07-28 00:15:19', '2026-07-28 00:15:19', 0, 'private'),
(24, 'VOX-C-2026-3391', 2, 'Benjamen Daraman', 'Not provided', 'male', NULL, 'Not provided', '09123456789', 'Maria Fajardo', '2026-07-01', '19:28:00', 'library', 'for bullying me', '', NULL, NULL, NULL, NULL, 'i want to stop them for bullying me', 1, NULL, 'new', 0, 'approved', NULL, NULL, NULL, '2026-07-28 00:55:17', '2026-07-28 00:55:17', 0, 'private'),
(25, 'VOX-C-2026-2442', 2, 'Benjamen Daraman', 'Not provided', 'male', NULL, 'Not provided', '09123456789', 'Aldrin Cabrera', '2026-07-01', '19:28:00', 'library', 'for bullying me', '', NULL, NULL, NULL, NULL, 'i want to stop them for bullying me', 1, NULL, 'new', 0, 'approved', NULL, NULL, NULL, '2026-07-28 00:55:27', '2026-07-28 00:55:27', 0, 'private'),
(26, 'VOX-C-2026-6710', 2, 'Benjamen Daraman', 'Not provided', 'male', NULL, 'Not provided', '09123456789', 'Dexter Abad', '2026-07-01', '19:28:00', 'library', 'for bullying me', '', NULL, NULL, NULL, NULL, 'i want to stop them for bullying me', 1, NULL, 'new', 0, 'approved', NULL, NULL, NULL, '2026-07-28 00:55:42', '2026-07-28 00:55:42', 0, 'private');

-- --------------------------------------------------------

--
-- Table structure for table `complaint_categories`
--

CREATE TABLE `complaint_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `route` enum('general','college') DEFAULT NULL,
  `category_type` enum('general','college') DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `complaint_categories`
--

INSERT INTO `complaint_categories` (`id`, `name`, `route`, `category_type`, `is_active`) VALUES
(55, 'admin', 'general', 'general', 1),
(56, 'dean', 'college', 'college', 1);

-- --------------------------------------------------------

--
-- Table structure for table `complaint_reactions`
--

CREATE TABLE `complaint_reactions` (
  `id` int(11) NOT NULL,
  `complaint_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `reaction_type` varchar(20) NOT NULL COMMENT 'agree, disagree',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `complaint_student_links`
--

CREATE TABLE `complaint_student_links` (
  `complaint_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `complaint_student_links`
--

INSERT INTO `complaint_student_links` (`complaint_id`, `student_id`, `created_at`) VALUES
(23, 9, '2026-07-28 00:15:19'),
(23, 23, '2026-07-28 00:15:19'),
(23, 36, '2026-07-28 00:15:19'),
(23, 43, '2026-07-28 00:15:19'),
(23, 48, '2026-07-28 00:15:19'),
(24, 9, '2026-07-28 00:55:17'),
(24, 23, '2026-07-28 00:55:17'),
(24, 36, '2026-07-28 00:55:17'),
(24, 43, '2026-07-28 00:55:17'),
(24, 48, '2026-07-28 00:55:17'),
(24, 52, '2026-07-28 00:55:17'),
(25, 9, '2026-07-28 00:55:27'),
(25, 36, '2026-07-28 00:55:27'),
(25, 48, '2026-07-28 00:55:27'),
(25, 52, '2026-07-28 00:55:27'),
(26, 9, '2026-07-28 00:55:42'),
(26, 36, '2026-07-28 00:55:42'),
(26, 48, '2026-07-28 00:55:42'),
(26, 52, '2026-07-28 00:55:42');

-- --------------------------------------------------------

--
-- Table structure for table `dean_profiles`
--

CREATE TABLE `dean_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `college_id` int(11) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `office` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `profile_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dean_profiles`
--

INSERT INTO `dean_profiles` (`id`, `user_id`, `first_name`, `last_name`, `college_id`, `phone`, `office`, `status`, `profile_photo`, `created_at`) VALUES
(6, 11, 'Dan', 'Montes', 1, NULL, NULL, 'active', 'assets/images/profiles/dean_11_1777953464_e7701331.png', '2026-05-05 03:31:27'),
(7, 12, 'Justine', 'Jaum', 2, NULL, NULL, 'active', NULL, '2026-05-05 03:31:58'),
(8, 13, 'Grace', 'Erediano', 3, NULL, NULL, 'active', NULL, '2026-05-05 03:33:17');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `message` varchar(255) NOT NULL,
  `ticket_type` enum('complaint','suggestion') DEFAULT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `message`, `ticket_type`, `ticket_id`, `is_read`, `created_at`) VALUES
(82, 2, 'new_complaint', 'New general complaint submitted: VOX-C-2026-4518', 'complaint', 18, 1, '2026-07-13 12:50:12'),
(83, 11, 'new_complaint', 'New college complaint submitted: VOX-C-2026-4052', 'complaint', 19, 1, '2026-07-13 12:56:11'),
(84, 2, 'new_complaint', 'New general complaint submitted: VOX-C-2026-3160', 'complaint', 20, 1, '2026-07-14 04:42:19'),
(85, 2, 'new_complaint', 'New general complaint submitted: VOX-C-2026-8905', 'complaint', 21, 1, '2026-07-21 08:07:55');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `id` int(11) NOT NULL,
  `college_id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`id`, `college_id`, `code`, `name`, `status`, `created_at`) VALUES
(1, 1, 'BSIT', 'Bachelor of Science in Information Technology', 'active', '2026-03-28 08:51:11'),
(2, 1, 'BSCS', 'Bachelor of Science in Computer Science', 'active', '2026-03-28 08:51:38'),
(3, 2, 'BSELEXT', 'Bachelor of Science in Electronics Technology', 'active', '2026-03-28 10:24:24'),
(5, 3, 'BSCRIM', 'Bachelor of Science in Criminal Justice', 'active', '2026-05-04 15:32:06'),
(6, 2, 'BINDTECH-FPST', 'Bachelor of Science in Industrial Technology Major in Food Preparation Services Technology', 'active', '2026-05-05 03:42:05'),
(8, 2, 'BSELECT', 'Bachelor of Science in Electrical Technology', 'active', '2026-05-05 03:48:06');

-- --------------------------------------------------------

--
-- Table structure for table `student_profiles`
--

CREATE TABLE `student_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `college_id` int(11) DEFAULT NULL,
  `year_level` tinyint(4) DEFAULT NULL,
  `section` varchar(10) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `id_document` varchar(255) DEFAULT NULL,
  `status` enum('pending','active','inactive') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `program_id` int(11) DEFAULT NULL,
  `complaint_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_profiles`
--

INSERT INTO `student_profiles` (`id`, `user_id`, `student_number`, `first_name`, `last_name`, `middle_name`, `gender`, `contact_number`, `college_id`, `year_level`, `section`, `school_year`, `id_document`, `status`, `created_at`, `program_id`, `complaint_count`) VALUES
(2, 6, '125347', 'Benjamen', 'Daraman', NULL, 'male', '09123456789', 1, 3, 'A', '2025-2026', NULL, 'active', '2026-03-29 05:47:26', 1, 0),
(4, 14, '642387', 'Justine Jean', 'Jaum', NULL, 'female', '09154327653', 3, 4, 'B', '2025-2026', NULL, 'pending', '2026-05-05 05:21:36', 5, 0),
(5, 15, '700001', 'John', 'Herrera', NULL, 'male', '09117550026', 1, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(6, 16, '700002', 'Kurt', 'Cabrera', NULL, 'male', '09287142271', 1, 4, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 1, 0),
(7, 17, '700003', 'Grace', 'Ocampo', NULL, 'female', '09759358685', 1, 3, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(8, 18, '700004', 'Mark', 'Cordero', NULL, 'male', '09883481367', 1, 3, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 1, 0),
(9, 19, '700005', 'Dexter', 'Abad', NULL, 'male', '09429623708', 1, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 1, 4),
(10, 20, '700006', 'Angelica', 'Pascual', NULL, 'female', '09836394225', 1, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 1, 0),
(11, 21, '700007', 'Faith', 'De Leon', NULL, 'female', '09914781165', 1, 3, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(12, 22, '700008', 'Angelica', 'Uy', NULL, 'female', '09807652614', 1, 3, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(13, 23, '700009', 'Maria', 'Guevarra', NULL, 'female', '09982661757', 1, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(14, 24, '700010', 'Dexter', 'Delos Santos', NULL, 'male', '09988308904', 1, 1, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(15, 25, '700011', 'Kurt', 'Jimenez', NULL, 'male', '09452842572', 1, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 1, 0),
(16, 26, '700012', 'Angelica', 'Cordero', NULL, 'female', '09420055070', 1, 4, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(17, 27, '700013', 'Mark', 'Quijano', NULL, 'male', '09262195478', 1, 1, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(18, 28, '700014', 'Paul', 'Aquino', NULL, 'male', '09480660781', 1, 3, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(19, 29, '700015', 'Daniel', 'Quiambao', NULL, 'male', '09148088065', 1, 2, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(20, 30, '700016', 'James', 'Umali', NULL, 'male', '09424039274', 1, 2, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 1, 0),
(21, 31, '700017', 'Erika', 'Cordero', NULL, 'female', '09295570558', 1, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(22, 32, '700018', 'Mark', 'Nolasco', NULL, 'male', '09562182564', 1, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 1, 0),
(23, 33, '700019', 'Andrea', 'Rivera', NULL, 'female', '09362370499', 1, 2, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 2),
(24, 34, '700020', 'Kurt', 'Rivera', NULL, 'male', '09883187696', 1, 2, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 2, 0),
(25, 35, '700021', 'Kurt', 'Rosario', NULL, 'male', '09287122368', 2, 1, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 0),
(26, 36, '700022', 'Andrea', 'Sison', NULL, 'female', '09420762754', 2, 1, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(27, 37, '700023', 'Paul', 'Gonzales', NULL, 'male', '09035403990', 2, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 0),
(28, 38, '700024', 'Bianca', 'Dizon', NULL, 'female', '09784619898', 2, 1, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(29, 39, '700025', 'Maria', 'Castro', NULL, 'female', '09888271882', 2, 3, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 0),
(30, 40, '700026', 'Kristine', 'Rosario', NULL, 'female', '09632073568', 2, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(31, 41, '700027', 'Maria', 'Flores', NULL, 'female', '09921473653', 2, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 6, 0),
(32, 42, '700028', 'Ryan', 'Cruz', NULL, 'male', '09586971499', 2, 4, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 0),
(33, 43, '700029', 'Grace', 'Guevarra', NULL, 'female', '09442149696', 2, 3, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 6, 0),
(34, 44, '700030', 'Angelica', 'Robles', NULL, 'female', '09276346579', 2, 3, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(35, 45, '700031', 'James', 'Aguilar', NULL, 'male', '09876210248', 2, 3, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 0),
(36, 46, '700032', 'Aldrin', 'Cabrera', NULL, 'male', '09642607714', 2, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 4),
(37, 47, '700033', 'Reymark', 'Ignacio', NULL, 'male', '09486959867', 2, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(38, 48, '700034', 'Erika', 'Gutierrez', NULL, 'female', '09226756202', 2, 4, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(39, 49, '700035', 'Jean', 'Delos Santos', NULL, 'female', '09131243408', 2, 1, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 0),
(40, 50, '700036', 'Faith', 'Espino', NULL, 'female', '09037894756', 2, 4, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(41, 51, '700037', 'Jerome', 'Domingo', NULL, 'male', '09036414160', 2, 4, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 6, 0),
(42, 52, '700038', 'Faith', 'Serrano', NULL, 'female', '09614032373', 2, 1, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 8, 0),
(43, 53, '700039', 'Andrea', 'Orbeta', NULL, 'female', '09709209002', 2, 4, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 2),
(44, 54, '700040', 'Dexter', 'Mendoza', NULL, 'male', '09652671671', 2, 2, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 3, 0),
(45, 55, '700041', 'Kevin', 'Aquino', NULL, 'male', '09693706617', 3, 3, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(46, 56, '700042', 'Ivan', 'Lacson', NULL, 'male', '09435388196', 3, 3, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(47, 57, '700043', 'Jasmine', 'De Leon', NULL, 'female', '09854472880', 3, 3, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(48, 58, '700044', 'Camille', 'Isip', NULL, 'female', '09081273872', 3, 4, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 4),
(49, 59, '700045', 'Reymark', 'Garcia', NULL, 'male', '09452474024', 3, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(50, 60, '700046', 'Kevin', 'Cordero', NULL, 'male', '09547372211', 3, 4, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(51, 61, '700047', 'Kurt', 'Isip', NULL, 'male', '09725759823', 3, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(52, 62, '700048', 'Maria', 'Fajardo', NULL, 'female', '09956184854', 3, 3, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 3),
(53, 63, '700049', 'Jean', 'Castro', NULL, 'female', '09104561890', 3, 3, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(54, 64, '700050', 'Paul', 'Robles', NULL, 'male', '09392545090', 3, 2, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(55, 65, '700051', 'Daniel', 'Navarro', NULL, 'male', '09100452277', 3, 2, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(56, 66, '700052', 'Kate', 'Dizon', NULL, 'female', '09581121210', 3, 3, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(57, 67, '700053', 'Joy', 'Herrera', NULL, 'female', '09434254587', 3, 4, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(58, 68, '700054', 'Maria', 'Ramos', NULL, 'female', '09857154294', 3, 1, 'C', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(59, 69, '700055', 'Kate', 'Lopez', NULL, 'female', '09311446470', 3, 4, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(60, 70, '700056', 'Angelo', 'Navarro', NULL, 'male', '09880840564', 3, 1, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(61, 71, '700057', 'Angel', 'Rivera', NULL, 'female', '09461962155', 3, 2, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(62, 72, '700058', 'Christian', 'Orbeta', NULL, 'male', '09619274444', 3, 4, 'A', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(63, 73, '700059', 'Rafael', 'Garcia', NULL, 'male', '09783720785', 3, 1, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0),
(64, 74, '700060', 'Jasmine', 'Gutierrez', NULL, 'female', '09554316527', 3, 3, 'B', '2025-2026', NULL, 'active', '2026-07-27 11:08:51', 5, 0);

-- --------------------------------------------------------

--
-- Table structure for table `suggestions`
--

CREATE TABLE `suggestions` (
  `id` int(11) NOT NULL,
  `ticket_no` varchar(20) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `college_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `date_of_suggestion` date DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `expected_outcome` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `status` enum('new','under_review','approved','implemented','declined','rejected','reviewed') DEFAULT 'new',
  `is_forwarded` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approval_status` varchar(50) DEFAULT 'pending' COMMENT 'pending, approved, rejected',
  `admin_id` int(11) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `admin_reviewed_at` timestamp NULL DEFAULT NULL,
  `visibility_status` varchar(50) DEFAULT 'private' COMMENT 'private, public'
  ,`school_year` varchar(9) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suggestion_categories`
--

CREATE TABLE `suggestion_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `route` enum('general','college') DEFAULT NULL,
  `category_type` enum('general','college') DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suggestion_categories`
--

INSERT INTO `suggestion_categories` (`id`, `name`, `route`, `category_type`, `is_active`) VALUES
(24, 'school event', 'general', 'general', 1),
(25, 'classroom officers', 'college', 'college', 1);

-- --------------------------------------------------------

--
-- Table structure for table `ticket_feedback`
--

CREATE TABLE `ticket_feedback` (
  `id` int(11) NOT NULL,
  `ticket_type` enum('complaint','suggestion') NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `satisfaction` enum('satisfied','neutral','not_satisfied') NOT NULL,
  `rating_value` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_feedback`
--

INSERT INTO `ticket_feedback` (`id`, `ticket_type`, `ticket_id`, `student_id`, `satisfaction`, `rating_value`, `comment`, `created_at`, `updated_at`) VALUES
(37, 'complaint', 18, 2, 'neutral', NULL, 'paspasi', '2026-07-13 12:52:54', '2026-07-13 12:52:54'),
(38, 'complaint', 19, 2, 'satisfied', NULL, 'thnak youuu', '2026-07-13 12:58:03', '2026-07-13 12:58:03'),
(39, 'complaint', 20, 2, 'neutral', NULL, 'wa,geh', '2026-07-14 04:42:55', '2026-07-14 04:42:55');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_feedback_history`
--

CREATE TABLE `ticket_feedback_history` (
  `id` int(11) NOT NULL,
  `ticket_type` enum('complaint','suggestion') NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `satisfaction` enum('satisfied','neutral','not_satisfied') NOT NULL,
  `comment` text DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_feedback_replies`
--

CREATE TABLE `ticket_feedback_replies` (
  `id` int(11) NOT NULL,
  `ticket_type` enum('complaint','suggestion') NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `feedback_history_id` int(11) DEFAULT NULL,
  `replier_id` int(11) NOT NULL,
  `replier_role` varchar(32) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_feedback_replies`
--

INSERT INTO `ticket_feedback_replies` (`id`, `ticket_type`, `ticket_id`, `student_id`, `feedback_history_id`, `replier_id`, `replier_role`, `message`, `created_at`) VALUES
(44, 'complaint', 18, 2, 37, 1, 'admin', 'okay', '2026-07-13 12:53:11'),
(45, 'complaint', 18, 2, 37, 1, 'admin', 'okay', '2026-07-13 12:53:56'),
(46, 'complaint', 18, 2, 37, 2, 'student', 'hu', '2026-07-13 12:54:12'),
(47, 'complaint', 19, 2, 38, 6, 'dean', 'thanks', '2026-07-13 13:00:01'),
(48, 'complaint', 19, 2, 38, 2, 'student', 'wellcome', '2026-07-13 13:00:11'),
(49, 'complaint', 20, 2, 39, 1, 'admin', 'rearh', '2026-07-14 04:43:09'),
(50, 'complaint', 20, 2, 39, 2, 'student', 'tege', '2026-07-14 04:43:17'),
(51, 'complaint', 20, 2, 39, 1, 'admin', 'grggfejgs', '2026-07-14 04:43:35');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_replies`
--

CREATE TABLE `ticket_replies` (
  `id` int(11) NOT NULL,
  `ticket_type` enum('complaint','suggestion') NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_role` enum('student','dean','admin') NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_replies`
--

INSERT INTO `ticket_replies` (`id`, `ticket_type`, `ticket_id`, `sender_id`, `sender_role`, `message`, `is_read`, `created_at`) VALUES
(59, 'complaint', 18, 1, 'admin', 'okay gi review pa namo ni nga issue', 0, '2026-07-13 12:51:55'),
(60, 'complaint', 19, 6, 'dean', 'okay we blelelele then this isuure kay na resolved na', 0, '2026-07-13 12:57:44'),
(61, 'complaint', 20, 1, 'admin', 'uyfgefhekfhewkfhjk', 0, '2026-07-14 04:42:34');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','dean','student') NOT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token_hash` varchar(64) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL,
  `reset_code` varchar(255) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `profile_pic`, `is_active`, `created_at`, `reset_token_hash`, `reset_token_expires_at`, `reset_code`, `reset_expiry`) VALUES
(2, 'adminmain', 'adminmain@voice.local', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'admin', 'assets/images/profiles/admin_2_1774694375.png', 1, '2026-03-28 09:41:54', NULL, NULL, NULL, NULL),
(6, 'benjeee', 'dan_mea_grace.montes@bisu.edu.ph', '$2y$10$mapSn7VHU5i6XMgOc6c43OXyNF2Z5Zz9jI/sUyZoW98/1Ldu6y3cq', 'student', 'assets/images/profiles/student_6_1777950422_67bc5652.jpg', 1, '2026-03-29 05:47:26', '4790aff221d00cc99ef24a200f63697e460bd5255af6af1e01c45369c3d8ace1', '2026-03-29 08:54:40', NULL, NULL),
(11, 'danmontes', 'dan@bisu.edu.ph', '$2y$10$F4aT.2XvkoGxSvrcycQT/OUoF70xkgTEMhATI2THxVP2cijkibX56', 'dean', NULL, 1, '2026-05-05 03:31:27', NULL, NULL, NULL, NULL),
(12, 'justinejaum', 'justine@bisu.edu.ph', '123', 'dean', NULL, 1, '2026-05-05 03:31:58', NULL, NULL, NULL, NULL),
(13, 'graceerediano', 'grace@bisu.edu.ph', '123', 'dean', NULL, 1, '2026-05-05 03:33:17', NULL, NULL, NULL, NULL),
(14, 'jeanjaum', 'justinejean@bisu.edu.ph', '$2y$10$aqsbIN9U/B78Nuii8r4SyOej9/Uju8RekrHgM0HVgAs/qWBjBW2X6', 'student', NULL, 1, '2026-05-05 05:21:36', NULL, NULL, NULL, NULL),
(15, 'johnherrera', 'johnherrera@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(16, 'kurtcabrera', 'kurtcabrera@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(17, 'graceocampo', 'graceocampo@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(18, 'markcordero', 'markcordero@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(19, 'dexterabad', 'dexterabad@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(20, 'angelicapascual', 'angelicapascual@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(21, 'faithde', 'faithde@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(22, 'angelicauy', 'angelicauy@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(23, 'mariaguevarra', 'mariaguevarra@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(24, 'dexterdelos', 'dexterdelos@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(25, 'kurtjimenez', 'kurtjimenez@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(26, 'angelicacordero', 'angelicacordero@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(27, 'markquijano', 'markquijano@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(28, 'paulaquino', 'paulaquino@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(29, 'danielquiambao', 'danielquiambao@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(30, 'jamesumali', 'jamesumali@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(31, 'erikacordero', 'erikacordero@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(32, 'marknolasco', 'marknolasco@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(33, 'andrearivera', 'andrearivera@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(34, 'kurtrivera', 'kurtrivera@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(35, 'kurtrosario', 'kurtrosario@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(36, 'andreasison', 'andreasison@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(37, 'paulgonzales', 'paulgonzales@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(38, 'biancadizon', 'biancadizon@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(39, 'mariacastro', 'mariacastro@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(40, 'kristinerosario', 'kristinerosario@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(41, 'mariaflores', 'mariaflores@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(42, 'ryancruz', 'ryancruz@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(43, 'graceguevarra', 'graceguevarra@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(44, 'angelicarobles', 'angelicarobles@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(45, 'jamesaguilar', 'jamesaguilar@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(46, 'aldrincabrera', 'aldrincabrera@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(47, 'reymarkignacio', 'reymarkignacio@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(48, 'erikagutierrez', 'erikagutierrez@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(49, 'jeandelos', 'jeandelos@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(50, 'faithespino', 'faithespino@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(51, 'jeromedomingo', 'jeromedomingo@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(52, 'faithserrano', 'faithserrano@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(53, 'andreaorbeta', 'andreaorbeta@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(54, 'dextermendoza', 'dextermendoza@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(55, 'kevinaquino', 'kevinaquino@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(56, 'ivanlacson', 'ivanlacson@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(57, 'jasminede', 'jasminede@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(58, 'camilleisip', 'camilleisip@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(59, 'reymarkgarcia', 'reymarkgarcia@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(60, 'kevincordero', 'kevincordero@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(61, 'kurtisip', 'kurtisip@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(62, 'mariafajardo', 'mariafajardo@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(63, 'jeancastro', 'jeancastro@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(64, 'paulrobles', 'paulrobles@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(65, 'danielnavarro', 'danielnavarro@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(66, 'katedizon', 'katedizon@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(67, 'joyherrera', 'joyherrera@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(68, 'mariaramos', 'mariaramos@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(69, 'katelopez', 'katelopez@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(70, 'angelonavarro', 'angelonavarro@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(71, 'angelrivera', 'angelrivera@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(72, 'christianorbeta', 'christianorbeta@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(73, 'rafaelgarcia', 'rafaelgarcia@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL),
(74, 'jasminegutierrez', 'jasminegutierrez@bisu.edu.ph', '$2y$10$L38u6H8q/R14Lch1rgHOw.OAXYiAfydY.UJcsJRBC6lsG64MqK/x2', 'student', NULL, 1, '2026-07-27 11:08:51', NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `admin_profiles`
--
ALTER TABLE `admin_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `chatbot_conversations`
--
ALTER TABLE `chatbot_conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `chatbot_settings`
--
ALTER TABLE `chatbot_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chatbot_triggers`
--
ALTER TABLE `chatbot_triggers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `colleges`
--
ALTER TABLE `colleges`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `complaints`
--
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_no` (`ticket_no`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `college_id` (`college_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `fk_complaints_admin` (`admin_id`),
  ADD KEY `idx_approval_status` (`approval_status`),
  ADD KEY `idx_visibility_status` (`visibility_status`),
  ADD KEY `idx_admin_reviewed_at` (`admin_reviewed_at`),
  ADD KEY `idx_complaints_student_school_year` (`student_id`,`school_year`);

--
-- Indexes for table `complaint_categories`
--
ALTER TABLE `complaint_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `complaint_reactions`
--
ALTER TABLE `complaint_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reaction` (`complaint_id`,`student_id`),
  ADD KEY `idx_complaint_id` (`complaint_id`),
  ADD KEY `idx_student_id` (`student_id`);

--
-- Indexes for table `complaint_student_links`
--
ALTER TABLE `complaint_student_links`
  ADD PRIMARY KEY (`complaint_id`,`student_id`),
  ADD KEY `idx_student_id` (`student_id`);

--
-- Indexes for table `dean_profiles`
--
ALTER TABLE `dean_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `college_id` (`college_id`);

--
-- Indexes for table `faq_categories`
--
ALTER TABLE `faq_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_faq_category_name` (`name`),
  ADD KEY `idx_faq_category_active_order` (`is_active`,`display_order`,`name`);

--
-- Indexes for table `faq_questions`
--
ALTER TABLE `faq_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_faq_question_category_active_order` (`category_id`,`is_active`,`display_order`,`question`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `college_id` (`college_id`);

--
-- Indexes for table `student_profiles`
--
ALTER TABLE `student_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD KEY `college_id` (`college_id`),
  ADD KEY `fk_student_program` (`program_id`);

--
-- Indexes for table `suggestions`
--
ALTER TABLE `suggestions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_no` (`ticket_no`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `college_id` (`college_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_approval_status` (`approval_status`),
  ADD KEY `idx_visibility_status` (`visibility_status`),
  ADD KEY `idx_admin_reviewed_at` (`admin_reviewed_at`),
  ADD KEY `fk_suggestions_admin` (`admin_id`),
  ADD KEY `idx_suggestions_student_school_year` (`student_id`,`school_year`);

--
-- Indexes for table `suggestion_categories`
--
ALTER TABLE `suggestion_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_ticket_feedback` (`ticket_type`,`ticket_id`,`student_id`),
  ADD KEY `idx_ticket_type` (`ticket_type`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_student_id` (`student_id`);

--
-- Indexes for table `ticket_feedback_history`
--
ALTER TABLE `ticket_feedback_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_type` (`ticket_type`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_archived_at` (`archived_at`);

--
-- Indexes for table `ticket_feedback_replies`
--
ALTER TABLE `ticket_feedback_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket` (`ticket_type`,`ticket_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_feedback_history` (`feedback_history_id`);

--
-- Indexes for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `admin_profiles`
--
ALTER TABLE `admin_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `chatbot_conversations`
--
ALTER TABLE `chatbot_conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `chatbot_settings`
--
ALTER TABLE `chatbot_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `chatbot_triggers`
--
ALTER TABLE `chatbot_triggers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `colleges`
--
ALTER TABLE `colleges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `complaint_categories`
--
ALTER TABLE `complaint_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `complaint_reactions`
--
ALTER TABLE `complaint_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `dean_profiles`
--
ALTER TABLE `dean_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `faq_categories`
--
ALTER TABLE `faq_categories`
  MODIFY `id` int(10) unsigned NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faq_questions`
--
ALTER TABLE `faq_questions`
  MODIFY `id` int(10) unsigned NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `student_profiles`
--
ALTER TABLE `student_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `suggestions`
--
ALTER TABLE `suggestions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `suggestion_categories`
--
ALTER TABLE `suggestion_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `ticket_feedback_history`
--
ALTER TABLE `ticket_feedback_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ticket_feedback_replies`
--
ALTER TABLE `ticket_feedback_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `admin_profiles`
--
ALTER TABLE `admin_profiles`
  ADD CONSTRAINT `admin_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chatbot_conversations`
--
ALTER TABLE `chatbot_conversations`
  ADD CONSTRAINT `chatbot_conversations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `complaints`
--
ALTER TABLE `complaints`
  ADD CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_profiles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `complaints_ibfk_2` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `complaints_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `complaint_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_complaints_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `complaint_reactions`
--
ALTER TABLE `complaint_reactions`
  ADD CONSTRAINT `complaint_reactions_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `complaint_reactions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `student_profiles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dean_profiles`
--
ALTER TABLE `dean_profiles`
  ADD CONSTRAINT `dean_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dean_profiles_ibfk_2` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `faq_questions`
--
ALTER TABLE `faq_questions`
  ADD CONSTRAINT `fk_faq_question_category` FOREIGN KEY (`category_id`) REFERENCES `faq_categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_profiles`
--
ALTER TABLE `student_profiles`
  ADD CONSTRAINT `fk_student_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `student_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_profiles_ibfk_2` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `suggestions`
--
ALTER TABLE `suggestions`
  ADD CONSTRAINT `fk_suggestions_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `suggestions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_profiles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `suggestions_ibfk_2` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `suggestions_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `suggestion_categories` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
