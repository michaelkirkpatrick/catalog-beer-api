-- -------------------------------------------------------------
-- TablePlus 6.8.0(654)
--
-- https://tableplus.com/
--
-- Database: catalogbeer
-- Generation Time: 2026-02-11 21:08:03.1320
-- -------------------------------------------------------------


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


CREATE TABLE `US_addresses` (
  `locationID` varchar(36) NOT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `sub_code` varchar(5) NOT NULL,
  `zip5` int NOT NULL,
  `zip4` int DEFAULT NULL,
  `telephone` bigint DEFAULT NULL,
  PRIMARY KEY (`locationID`),
  KEY `fk_sub_code` (`sub_code`),
  CONSTRAINT `fk_locationID` FOREIGN KEY (`locationID`) REFERENCES `location` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_code` FOREIGN KEY (`sub_code`) REFERENCES `subdivisions` (`sub_code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `api_keys` (
  `id` varchar(36) NOT NULL,
  `userID` varchar(36) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_userID` (`userID`) USING BTREE,
  CONSTRAINT `api_keys_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `api_logging` (
  `id` varchar(36) NOT NULL,
  `apiKey` varchar(36) DEFAULT NULL,
  `timestamp` int DEFAULT NULL,
  `ipAddress` varchar(45) DEFAULT NULL,
  `method` varchar(6) DEFAULT NULL,
  `uri` varchar(255) DEFAULT NULL,
  `body` text,
  `response` text,
  `responseCode` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `api_usage` (
  `id` varchar(36) NOT NULL,
  `apiKey` varchar(36) NOT NULL,
  `year` smallint NOT NULL,
  `month` tinyint NOT NULL,
  `count` int NOT NULL,
  `lastUpdated` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `beer` (
  `id` varchar(36) NOT NULL,
  `brewerID` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `style` varchar(255) NOT NULL,
  `description` text,
  `abv` decimal(4,1) NOT NULL,
  `ibu` int DEFAULT NULL,
  `cbVerified` bit(1) NOT NULL DEFAULT b'0',
  `brewerVerified` bit(1) NOT NULL DEFAULT b'0',
  `lastModified` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_brewerID` (`brewerID`) USING BTREE,
  CONSTRAINT `beer_ibfk_1` FOREIGN KEY (`brewerID`) REFERENCES `brewer` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `brewer` (
  `id` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `shortDescription` varchar(160) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `domainName` varchar(255) DEFAULT NULL,
  `cbVerified` bit(1) NOT NULL DEFAULT b'0',
  `brewerVerified` bit(1) NOT NULL DEFAULT b'0',
  `facebookURL` varchar(255) DEFAULT NULL,
  `twitterURL` varchar(255) DEFAULT NULL,
  `instagramURL` varchar(255) DEFAULT NULL,
  `lastModified` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_url` (`url`) USING BTREE,
  UNIQUE KEY `unique_domain` (`domainName`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `error_log` (
  `id` varchar(36) NOT NULL,
  `errorNumber` varchar(255) DEFAULT NULL,
  `errorMessage` text,
  `badData` blob,
  `userID` varchar(36) DEFAULT NULL,
  `URI` varchar(255) DEFAULT NULL,
  `ipAddress` varchar(45) DEFAULT NULL,
  `timestamp` int DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `resolved` bit(1) DEFAULT b'0',
  PRIMARY KEY (`id`),
  KEY `fk_userID` (`userID`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `location` (
  `id` varchar(36) NOT NULL,
  `brewerID` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `countryCode` varchar(2) NOT NULL,
  `latitude` float(9,7) DEFAULT NULL,
  `longitude` float(10,7) DEFAULT NULL,
  `cbVerified` bit(1) NOT NULL DEFAULT b'0',
  `brewerVerified` bit(1) NOT NULL DEFAULT b'0',
  `lastModified` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_brewerID` (`brewerID`),
  CONSTRAINT `fk_brewerID` FOREIGN KEY (`brewerID`) REFERENCES `brewer` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `privileges` (
  `id` varchar(36) NOT NULL,
  `userID` varchar(36) NOT NULL,
  `brewerID` varchar(36) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_userID` (`userID`) USING BTREE,
  KEY `fk_brewerID` (`brewerID`) USING BTREE,
  CONSTRAINT `privileges_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `privileges_ibfk_2` FOREIGN KEY (`brewerID`) REFERENCES `brewer` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `subdivisions` (
  `sub_code` varchar(5) NOT NULL,
  `sub_name` varchar(255) NOT NULL,
  PRIMARY KEY (`sub_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `email` varchar(255) NOT NULL,
  `passwordHash` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `emailVerified` bit(1) NOT NULL DEFAULT b'0',
  `emailAuth` varchar(36) DEFAULT NULL,
  `emailAuthSent` int DEFAULT NULL,
  `passwordResetSent` int DEFAULT NULL,
  `passwordResetKey` varchar(36) DEFAULT NULL,
  `admin` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_email` (`email`) USING BTREE,
  UNIQUE KEY `unique_passwordResetKey` (`passwordResetKey`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;



/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;