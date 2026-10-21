-- YAP 4.5.2 database schema, translated from YAP's Laravel migrations
-- (database/migrations/2024_03_24_035821_create_*_table.php and
-- 2024_04_04_031341_create_config_table_status_column.php), MySQL 8 / MariaDB 10.3+.
--
-- Every table YAP creates is here, including the ones the reporting service must never read
-- (users, sessions, alerts, cache, metrics, event_status, flags, migrations): the fake backend
-- fills those with canary values so a test can prove they are never selected.

SET NAMES utf8mb4;

CREATE TABLE `alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NULL DEFAULT NULL,
  `alert_id` int(11) NOT NULL,
  `payload` longtext DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_records_conference_participants` (
  `parent_callsid` varchar(100) DEFAULT NULL,
  `callsid` varchar(100) DEFAULT NULL,
  `guid` varchar(36) DEFAULT NULL,
  `service_body_id` int(11) DEFAULT NULL,
  KEY `idx_rcp_parent_parent_callsid` (`parent_callsid`),
  KEY `idx_rcp_parent_callsid` (`callsid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `key` longtext DEFAULT NULL,
  `value` longtext DEFAULT NULL,
  `expiry` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conference_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `conferencesid` varchar(100) NOT NULL,
  `callsid` varchar(100) NOT NULL,
  `friendlyname` varchar(100) NOT NULL,
  `role` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conference_participants_conferencesid` (`conferencesid`),
  KEY `idx_conference_participants_callsid` (`callsid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_body_id` int(10) unsigned NOT NULL,
  `data` mediumtext NOT NULL,
  `data_type` varchar(45) NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_body_id_data_type_parent_id_unique` (`service_body_id`, `data_type`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_status` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `callsid` varchar(255) DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  `event_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_unique` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `flag_name` varchar(50) NOT NULL,
  `flag_setting` int(11) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `flag_name_unique` (`flag_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data` text NOT NULL,
  `service_body_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_metrics_unique` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `records_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `callsid` varchar(255) NOT NULL,
  `event_time` timestamp NULL DEFAULT NULL,
  `event_id` int(11) NOT NULL,
  `service_body_id` int(11) DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `type` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_records_events_callsid` (`callsid`),
  KEY `idx_records_events_service_body_id` (`service_body_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `callsid` varchar(255) NOT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `from_number` varchar(255) NOT NULL,
  `to_number` varchar(255) NOT NULL,
  `payload` longtext DEFAULT NULL,
  `duration` int(11) NOT NULL,
  `type` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_records_callsid` (`callsid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `callsid` varchar(255) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pin` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `username` varchar(45) NOT NULL,
  `password` varchar(255) NOT NULL,
  `permissions` int(11) NOT NULL DEFAULT 0,
  `is_admin` int(11) DEFAULT NULL,
  `created_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `service_bodies` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
