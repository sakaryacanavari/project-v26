/*
 Navicat MySQL Dump SQL

 Source Server         : proje1
 Source Server Type    : MySQL
 Source Server Version : 80403 (8.4.3)
 Source Host           : localhost:3306
 Source Schema         : proje

 Target Server Type    : MySQL
 Target Server Version : 80403 (8.4.3)
 File Encoding         : 65001

 Date: 01/05/2026 16:01:38
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for article_comment_reactions
-- ----------------------------
DROP TABLE IF EXISTS `article_comment_reactions`;
CREATE TABLE `article_comment_reactions`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `comment_id` int NOT NULL,
  `uid` int NOT NULL,
  `reaction` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_article_comment_reactions_comment_uid_reaction`(`comment_id` ASC, `uid` ASC, `reaction` ASC) USING BTREE,
  INDEX `idx_article_comment_reactions_comment_id`(`comment_id` ASC) USING BTREE,
  INDEX `idx_article_comment_reactions_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of article_comment_reactions
-- ----------------------------
INSERT INTO `article_comment_reactions` VALUES (1, 3, 22, 'sword', '2026-03-24 17:06:37', '2026-03-24 17:06:37');
INSERT INTO `article_comment_reactions` VALUES (2, 3, 22, 'brain', '2026-03-24 17:06:39', '2026-03-24 17:06:39');

-- ----------------------------
-- Table structure for article_comment_votes
-- ----------------------------
DROP TABLE IF EXISTS `article_comment_votes`;
CREATE TABLE `article_comment_votes`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` bigint UNSIGNED NOT NULL,
  `uid` bigint UNSIGNED NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_article_comment_votes`(`comment_id` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `ux_comment_votes_comment_uid`(`comment_id` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_article_comment_votes_comment_uid`(`comment_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_article_comment_votes_comment`(`comment_id` ASC) USING BTREE,
  INDEX `idx_article_comment_votes_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_comment_votes_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_article_comment_votes_comment_id`(`comment_id` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of article_comment_votes
-- ----------------------------
INSERT INTO `article_comment_votes` VALUES (1, 3, 22, NULL);

-- ----------------------------
-- Table structure for article_comments
-- ----------------------------
DROP TABLE IF EXISTS `article_comments`;
CREATE TABLE `article_comments`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` bigint UNSIGNED NOT NULL,
  `parent_id` int NOT NULL DEFAULT 0,
  `uid` bigint UNSIGNED NOT NULL,
  `mention_uid` int NULL DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `is_author_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `pin_currency` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `pin_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `votes` int UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_article_comments_article`(`article_id` ASC) USING BTREE,
  INDEX `idx_article_comments_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_comments_article_votes`(`article_id` ASC, `votes` ASC) USING BTREE,
  INDEX `idx_comments_article_created`(`article_id` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_comments_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_article_comments_article_id`(`article_id` ASC) USING BTREE,
  INDEX `idx_article_comments_parent_id`(`parent_id` ASC) USING BTREE,
  INDEX `idx_article_comments_mention_uid`(`mention_uid` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of article_comments
-- ----------------------------
INSERT INTO `article_comments` VALUES (1, 1, 0, 22, NULL, 'test', 0, 0, NULL, 0.00, 0, '2026-03-21 18:10:38', '2026-03-21 18:10:38');
INSERT INTO `article_comments` VALUES (2, 1, 1, 22, 22, '@admin5k yeni', 0, 0, NULL, 0.00, 0, '2026-03-21 18:12:29', '2026-03-21 18:12:29');
INSERT INTO `article_comments` VALUES (3, 3, 0, 22, NULL, 'perfect', 0, 1, NULL, 0.00, 1, '2026-03-22 15:45:49', '2026-03-22 15:45:59');

-- ----------------------------
-- Table structure for article_endorsements
-- ----------------------------
DROP TABLE IF EXISTS `article_endorsements`;
CREATE TABLE `article_endorsements`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `uid` int NOT NULL,
  `amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `currency` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_article_endorsements_article_id`(`article_id` ASC) USING BTREE,
  INDEX `idx_article_endorsements_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of article_endorsements
-- ----------------------------

-- ----------------------------
-- Table structure for article_fact_checks
-- ----------------------------
DROP TABLE IF EXISTS `article_fact_checks`;
CREATE TABLE `article_fact_checks`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `uid` int NOT NULL,
  `verdict` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_article_fact_checks_article_uid`(`article_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_article_fact_checks_article_id`(`article_id` ASC) USING BTREE,
  INDEX `idx_article_fact_checks_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of article_fact_checks
-- ----------------------------

-- ----------------------------
-- Table structure for article_votes
-- ----------------------------
DROP TABLE IF EXISTS `article_votes`;
CREATE TABLE `article_votes`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `article` int NOT NULL,
  `uid` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `article_uid`(`article` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `ux_article_votes_article_uid`(`article` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_article_votes_article_uid`(`article` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_article_votes_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_article_votes_article`(`article` ASC) USING BTREE,
  INDEX `idx_article_votes_uid_article`(`uid` ASC, `article` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of article_votes
-- ----------------------------
INSERT INTO `article_votes` VALUES (1, 1, 18, '2016-08-27 11:38:35', '2016-08-27 11:38:35');
INSERT INTO `article_votes` VALUES (2, 2, 18, '2016-09-06 17:16:50', '2016-09-06 17:16:50');
INSERT INTO `article_votes` VALUES (3, 3, 22, '2026-03-22 15:47:58', '2026-03-22 15:47:58');
INSERT INTO `article_votes` VALUES (4, 3, 42, '2026-03-22 21:22:02', '2026-03-22 21:22:02');

-- ----------------------------
-- Table structure for auth_rate_limits
-- ----------------------------
DROP TABLE IF EXISTS `auth_rate_limits`;
CREATE TABLE `auth_rate_limits`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `scope_key` varchar(191) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `attempts` int UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `last_attempt_at` datetime NOT NULL,
  `blocked_until` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_auth_rate_limits_action_scope`(`action` ASC, `scope_key` ASC) USING BTREE,
  INDEX `idx_auth_rate_limits_blocked_until`(`blocked_until` ASC) USING BTREE,
  INDEX `idx_auth_rate_limits_last_attempt`(`last_attempt_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 174 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of auth_rate_limits
-- ----------------------------
INSERT INTO `auth_rate_limits` VALUES (74, 'nick_check_ip', '::1', 4, '2026-04-24 22:05:43', '2026-04-24 22:06:53', NULL, '2026-04-24 22:05:43', '2026-04-24 22:06:53');
INSERT INTO `auth_rate_limits` VALUES (75, 'nick_check_fingerprint', '4d965dcc517f41c540c91fd8c9cc6ee263aa02d045220a8e3a9c00f5d5b6c2f3', 1, '2026-03-21 15:11:45', '2026-03-21 15:11:45', NULL, '2026-03-21 15:11:45', '2026-03-21 15:11:45');
INSERT INTO `auth_rate_limits` VALUES (76, 'signup_ip', '::1', 1, '2026-04-24 22:07:25', '2026-04-24 22:07:25', NULL, '2026-04-24 22:07:25', '2026-04-24 22:07:25');
INSERT INTO `auth_rate_limits` VALUES (77, 'signup_email', 'mixedcase_test@example.com', 1, '2026-03-21 15:12:01', '2026-03-21 15:12:01', NULL, '2026-03-21 15:12:01', '2026-03-21 15:12:01');
INSERT INTO `auth_rate_limits` VALUES (78, 'signup_fingerprint', '7aeefc19369711b1c8a13c96bcaa7f9520973284c839d9aedb3fcbee47f211c3', 1, '2026-03-21 15:12:01', '2026-03-21 15:12:01', NULL, '2026-03-21 15:12:01', '2026-03-21 15:12:01');
INSERT INTO `auth_rate_limits` VALUES (79, 'signup_actor', '::1|7aeefc19369711b1c8a13c96bcaa7f9520973284c839d9aedb3fcbee47f211c3', 1, '2026-03-21 15:12:01', '2026-03-21 15:12:01', NULL, '2026-03-21 15:12:01', '2026-03-21 15:12:01');
INSERT INTO `auth_rate_limits` VALUES (80, 'signup_email_actor', 'mixedcase_test@example.com|::1|7aeefc19369711b1c8a13c96bcaa7f9520973284c839d9aedb3fcbee47f211c3', 1, '2026-03-21 15:12:01', '2026-03-21 15:12:01', NULL, '2026-03-21 15:12:01', '2026-03-21 15:12:01');
INSERT INTO `auth_rate_limits` VALUES (81, 'nick_check_fingerprint', '4ce33791409ec0c7ba6141f0aadb00d5442cbd8daf34528ace93ef67657a8708', 2, '2026-03-25 00:09:45', '2026-03-25 00:09:45', NULL, '2026-03-25 00:09:45', '2026-03-25 00:09:45');
INSERT INTO `auth_rate_limits` VALUES (82, 'signup_email', 'buyertest1774117736@example.com', 1, '2026-03-21 15:28:55', '2026-03-21 15:28:55', NULL, '2026-03-21 15:28:55', '2026-03-21 15:28:55');
INSERT INTO `auth_rate_limits` VALUES (83, 'signup_fingerprint', '441edb24892724567b84543d651ad48196952a1bce4ba06ef5947524ece045f8', 1, '2026-03-21 15:28:55', '2026-03-21 15:28:55', NULL, '2026-03-21 15:28:55', '2026-03-21 15:28:55');
INSERT INTO `auth_rate_limits` VALUES (84, 'signup_actor', '::1|441edb24892724567b84543d651ad48196952a1bce4ba06ef5947524ece045f8', 1, '2026-03-21 15:28:56', '2026-03-21 15:28:56', NULL, '2026-03-21 15:28:56', '2026-03-21 15:28:56');
INSERT INTO `auth_rate_limits` VALUES (85, 'signup_email_actor', 'buyertest1774117736@example.com|::1|441edb24892724567b84543d651ad48196952a1bce4ba06ef5947524ece045f8', 1, '2026-03-21 15:28:56', '2026-03-21 15:28:56', NULL, '2026-03-21 15:28:56', '2026-03-21 15:28:56');
INSERT INTO `auth_rate_limits` VALUES (86, 'signup_email', 'buyerfix1774118711@example.com', 1, '2026-03-21 15:45:10', '2026-03-21 15:45:10', NULL, '2026-03-21 15:45:10', '2026-03-21 15:45:10');
INSERT INTO `auth_rate_limits` VALUES (87, 'signup_fingerprint', '6b9f373fd6312fc908f6b6cfd4f58eadfa9afd8cb12c723cb58ffffcac27ad54', 1, '2026-03-21 15:45:10', '2026-03-21 15:45:10', NULL, '2026-03-21 15:45:10', '2026-03-21 15:45:10');
INSERT INTO `auth_rate_limits` VALUES (88, 'signup_actor', '::1|6b9f373fd6312fc908f6b6cfd4f58eadfa9afd8cb12c723cb58ffffcac27ad54', 1, '2026-03-21 15:45:10', '2026-03-21 15:45:10', NULL, '2026-03-21 15:45:10', '2026-03-21 15:45:10');
INSERT INTO `auth_rate_limits` VALUES (89, 'signup_email_actor', 'buyerfix1774118711@example.com|::1|6b9f373fd6312fc908f6b6cfd4f58eadfa9afd8cb12c723cb58ffffcac27ad54', 1, '2026-03-21 15:45:10', '2026-03-21 15:45:10', NULL, '2026-03-21 15:45:10', '2026-03-21 15:45:10');
INSERT INTO `auth_rate_limits` VALUES (90, 'signup_email', 'buyerfixx1774118752@example.com', 1, '2026-03-21 15:45:51', '2026-03-21 15:45:51', NULL, '2026-03-21 15:45:51', '2026-03-21 15:45:51');
INSERT INTO `auth_rate_limits` VALUES (91, 'signup_fingerprint', '2205ac54af5d79fd34321ad263f10c89c78888246ce078efdb23b3c4e8a75b9b', 1, '2026-03-21 15:45:51', '2026-03-21 15:45:51', NULL, '2026-03-21 15:45:51', '2026-03-21 15:45:51');
INSERT INTO `auth_rate_limits` VALUES (92, 'signup_actor', '::1|2205ac54af5d79fd34321ad263f10c89c78888246ce078efdb23b3c4e8a75b9b', 1, '2026-03-21 15:45:51', '2026-03-21 15:45:51', NULL, '2026-03-21 15:45:51', '2026-03-21 15:45:51');
INSERT INTO `auth_rate_limits` VALUES (93, 'signup_email_actor', 'buyerfixx1774118752@example.com|::1|2205ac54af5d79fd34321ad263f10c89c78888246ce078efdb23b3c4e8a75b9b', 1, '2026-03-21 15:45:51', '2026-03-21 15:45:51', NULL, '2026-03-21 15:45:51', '2026-03-21 15:45:51');
INSERT INTO `auth_rate_limits` VALUES (94, 'signup_email', 'buyerhtml1774118778@example.com', 1, '2026-03-21 15:46:17', '2026-03-21 15:46:17', NULL, '2026-03-21 15:46:17', '2026-03-21 15:46:17');
INSERT INTO `auth_rate_limits` VALUES (95, 'signup_fingerprint', '3dbbcf3a01ad13cd4a8dd5ff399cbf062b3abe632c03f72080547935cacb193f', 1, '2026-03-21 15:46:17', '2026-03-21 15:46:17', NULL, '2026-03-21 15:46:17', '2026-03-21 15:46:17');
INSERT INTO `auth_rate_limits` VALUES (96, 'signup_actor', '::1|3dbbcf3a01ad13cd4a8dd5ff399cbf062b3abe632c03f72080547935cacb193f', 1, '2026-03-21 15:46:17', '2026-03-21 15:46:17', NULL, '2026-03-21 15:46:17', '2026-03-21 15:46:17');
INSERT INTO `auth_rate_limits` VALUES (97, 'signup_email_actor', 'buyerhtml1774118778@example.com|::1|3dbbcf3a01ad13cd4a8dd5ff399cbf062b3abe632c03f72080547935cacb193f', 1, '2026-03-21 15:46:17', '2026-03-21 15:46:17', NULL, '2026-03-21 15:46:17', '2026-03-21 15:46:17');
INSERT INTO `auth_rate_limits` VALUES (98, 'signup_email', 'buyerproof1774118794@example.com', 1, '2026-03-21 15:46:33', '2026-03-21 15:46:33', NULL, '2026-03-21 15:46:33', '2026-03-21 15:46:33');
INSERT INTO `auth_rate_limits` VALUES (99, 'signup_fingerprint', '5d8eedd02176dc4372e07402bba3a6bf1f9251d6e70d90221cc819f1c05a49ca', 1, '2026-03-21 15:46:33', '2026-03-21 15:46:33', NULL, '2026-03-21 15:46:33', '2026-03-21 15:46:33');
INSERT INTO `auth_rate_limits` VALUES (100, 'signup_actor', '::1|5d8eedd02176dc4372e07402bba3a6bf1f9251d6e70d90221cc819f1c05a49ca', 1, '2026-03-21 15:46:33', '2026-03-21 15:46:33', NULL, '2026-03-21 15:46:33', '2026-03-21 15:46:33');
INSERT INTO `auth_rate_limits` VALUES (101, 'signup_email_actor', 'buyerproof1774118794@example.com|::1|5d8eedd02176dc4372e07402bba3a6bf1f9251d6e70d90221cc819f1c05a49ca', 1, '2026-03-21 15:46:33', '2026-03-21 15:46:33', NULL, '2026-03-21 15:46:33', '2026-03-21 15:46:33');
INSERT INTO `auth_rate_limits` VALUES (102, 'signup_email', 'buyerfinal1774119113@example.com', 1, '2026-03-21 15:51:52', '2026-03-21 15:51:52', NULL, '2026-03-21 15:51:52', '2026-03-21 15:51:52');
INSERT INTO `auth_rate_limits` VALUES (103, 'signup_fingerprint', '78d0c6be880f098d4d42445e49591c9e82da6ab75559a6ad85b311d55decebad', 1, '2026-03-21 15:51:52', '2026-03-21 15:51:52', NULL, '2026-03-21 15:51:52', '2026-03-21 15:51:52');
INSERT INTO `auth_rate_limits` VALUES (104, 'signup_actor', '::1|78d0c6be880f098d4d42445e49591c9e82da6ab75559a6ad85b311d55decebad', 1, '2026-03-21 15:51:52', '2026-03-21 15:51:52', NULL, '2026-03-21 15:51:52', '2026-03-21 15:51:52');
INSERT INTO `auth_rate_limits` VALUES (105, 'signup_email_actor', 'buyerfinal1774119113@example.com|::1|78d0c6be880f098d4d42445e49591c9e82da6ab75559a6ad85b311d55decebad', 1, '2026-03-21 15:51:52', '2026-03-21 15:51:52', NULL, '2026-03-21 15:51:52', '2026-03-21 15:51:52');
INSERT INTO `auth_rate_limits` VALUES (106, 'signup_email', 'buyerfinal1774119129@example.com', 1, '2026-03-21 15:52:09', '2026-03-21 15:52:09', NULL, '2026-03-21 15:52:09', '2026-03-21 15:52:09');
INSERT INTO `auth_rate_limits` VALUES (107, 'signup_fingerprint', 'd7afb31b53e87f91fee2ba2ca888dd9c2da17d62ee623b52dd92696333cd668a', 1, '2026-03-21 15:52:09', '2026-03-21 15:52:09', NULL, '2026-03-21 15:52:09', '2026-03-21 15:52:09');
INSERT INTO `auth_rate_limits` VALUES (108, 'signup_actor', '::1|d7afb31b53e87f91fee2ba2ca888dd9c2da17d62ee623b52dd92696333cd668a', 1, '2026-03-21 15:52:09', '2026-03-21 15:52:09', NULL, '2026-03-21 15:52:09', '2026-03-21 15:52:09');
INSERT INTO `auth_rate_limits` VALUES (109, 'signup_email_actor', 'buyerfinal1774119129@example.com|::1|d7afb31b53e87f91fee2ba2ca888dd9c2da17d62ee623b52dd92696333cd668a', 1, '2026-03-21 15:52:09', '2026-03-21 15:52:09', NULL, '2026-03-21 15:52:09', '2026-03-21 15:52:09');
INSERT INTO `auth_rate_limits` VALUES (110, 'signup_email', 'mktest72683@example.test', 1, '2026-03-21 15:55:28', '2026-03-21 15:55:28', NULL, '2026-03-21 15:55:28', '2026-03-21 15:55:28');
INSERT INTO `auth_rate_limits` VALUES (111, 'signup_fingerprint', '591b292a17432fb597ae242e43c377c325a3e178050d34d36d5a3f3d0db43f7d', 4, '2026-03-21 15:55:28', '2026-03-21 16:18:14', NULL, '2026-03-21 15:55:28', '2026-03-21 16:18:14');
INSERT INTO `auth_rate_limits` VALUES (112, 'signup_actor', '::1|591b292a17432fb597ae242e43c377c325a3e178050d34d36d5a3f3d0db43f7d', 4, '2026-03-21 15:55:28', '2026-03-21 16:18:14', NULL, '2026-03-21 15:55:28', '2026-03-21 16:18:14');
INSERT INTO `auth_rate_limits` VALUES (113, 'signup_email_actor', 'mktest72683@example.test|::1|591b292a17432fb597ae242e43c377c325a3e178050d34d36d5a3f3d0db43f7d', 1, '2026-03-21 15:55:28', '2026-03-21 15:55:28', NULL, '2026-03-21 15:55:28', '2026-03-21 15:55:28');
INSERT INTO `auth_rate_limits` VALUES (114, 'signup_email', 'mkflash37742@example.test', 1, '2026-03-21 16:04:42', '2026-03-21 16:04:42', NULL, '2026-03-21 16:04:42', '2026-03-21 16:04:42');
INSERT INTO `auth_rate_limits` VALUES (115, 'signup_email_actor', 'mkflash37742@example.test|::1|591b292a17432fb597ae242e43c377c325a3e178050d34d36d5a3f3d0db43f7d', 1, '2026-03-21 16:04:42', '2026-03-21 16:04:42', NULL, '2026-03-21 16:04:42', '2026-03-21 16:04:42');
INSERT INTO `auth_rate_limits` VALUES (116, 'signup_email', 'wotest32764@example.test', 1, '2026-03-21 16:18:02', '2026-03-21 16:18:02', NULL, '2026-03-21 16:18:02', '2026-03-21 16:18:02');
INSERT INTO `auth_rate_limits` VALUES (117, 'signup_email_actor', 'wotest32764@example.test|::1|591b292a17432fb597ae242e43c377c325a3e178050d34d36d5a3f3d0db43f7d', 1, '2026-03-21 16:18:02', '2026-03-21 16:18:02', NULL, '2026-03-21 16:18:02', '2026-03-21 16:18:02');
INSERT INTO `auth_rate_limits` VALUES (118, 'signup_email', 'wotest40762@example.test', 1, '2026-03-21 16:18:14', '2026-03-21 16:18:14', NULL, '2026-03-21 16:18:14', '2026-03-21 16:18:14');
INSERT INTO `auth_rate_limits` VALUES (119, 'signup_email_actor', 'wotest40762@example.test|::1|591b292a17432fb597ae242e43c377c325a3e178050d34d36d5a3f3d0db43f7d', 1, '2026-03-21 16:18:14', '2026-03-21 16:18:14', NULL, '2026-03-21 16:18:14', '2026-03-21 16:18:14');
INSERT INTO `auth_rate_limits` VALUES (120, 'signup_email', 'botplayer@gmail.com', 1, '2026-03-21 17:52:41', '2026-03-21 17:52:41', NULL, '2026-03-21 17:52:41', '2026-03-21 17:52:41');
INSERT INTO `auth_rate_limits` VALUES (121, 'signup_fingerprint', '4ce33791409ec0c7ba6141f0aadb00d5442cbd8daf34528ace93ef67657a8708', 1, '2026-03-25 00:10:12', '2026-03-25 00:10:12', NULL, '2026-03-25 00:10:12', '2026-03-25 00:10:12');
INSERT INTO `auth_rate_limits` VALUES (122, 'signup_actor', '::1|4ce33791409ec0c7ba6141f0aadb00d5442cbd8daf34528ace93ef67657a8708', 1, '2026-03-25 00:10:12', '2026-03-25 00:10:12', NULL, '2026-03-25 00:10:12', '2026-03-25 00:10:12');
INSERT INTO `auth_rate_limits` VALUES (123, 'signup_email_actor', 'botplayer@gmail.com|::1|4ce33791409ec0c7ba6141f0aadb00d5442cbd8daf34528ace93ef67657a8708', 1, '2026-03-21 17:52:41', '2026-03-21 17:52:41', NULL, '2026-03-21 17:52:41', '2026-03-21 17:52:41');
INSERT INTO `auth_rate_limits` VALUES (124, 'newspaper_create', 'uid:22', 1, '2026-03-22 15:32:27', '2026-03-22 15:32:27', NULL, '2026-03-22 15:32:27', '2026-03-22 15:32:27');
INSERT INTO `auth_rate_limits` VALUES (125, 'company_offer_create', 'uid:22', 1, '2026-03-22 15:40:10', '2026-03-22 15:40:10', NULL, '2026-03-22 15:40:10', '2026-03-22 15:40:10');
INSERT INTO `auth_rate_limits` VALUES (126, 'newspaper_publish_article', 'uid:22', 1, '2026-03-22 15:42:32', '2026-03-22 15:42:32', NULL, '2026-03-22 15:42:32', '2026-03-22 15:42:32');
INSERT INTO `auth_rate_limits` VALUES (127, 'newspaper_comment', 'uid:22', 1, '2026-03-22 15:45:49', '2026-03-22 15:45:49', NULL, '2026-03-22 15:45:49', '2026-03-22 15:45:49');
INSERT INTO `auth_rate_limits` VALUES (128, 'job_apply', 'uid:42', 1, '2026-03-22 17:30:02', '2026-03-22 17:30:02', NULL, '2026-03-22 17:30:02', '2026-03-22 17:30:02');
INSERT INTO `auth_rate_limits` VALUES (129, 'job_work', 'uid:42', 1, '2026-03-22 17:30:04', '2026-03-22 17:30:04', NULL, '2026-03-22 17:30:04', '2026-03-22 17:30:04');
INSERT INTO `auth_rate_limits` VALUES (130, 'newspaper_create', 'uid:42', 1, '2026-03-22 17:30:17', '2026-03-22 17:30:17', NULL, '2026-03-22 17:30:17', '2026-03-22 17:30:17');
INSERT INTO `auth_rate_limits` VALUES (131, 'party_buy_ad', 'uid:22', 1, '2026-03-22 17:49:41', '2026-03-22 17:49:41', NULL, '2026-03-22 17:49:41', '2026-03-22 17:49:41');
INSERT INTO `auth_rate_limits` VALUES (132, 'shout.create.slow', 'uid:22', 1, '2026-03-23 00:33:10', '2026-03-23 00:33:10', NULL, '2026-03-22 22:27:11', '2026-03-23 00:33:10');
INSERT INTO `auth_rate_limits` VALUES (133, 'shout.create.burst', 'uid:22', 1, '2026-03-23 00:33:10', '2026-03-23 00:33:10', NULL, '2026-03-22 22:27:11', '2026-03-23 00:33:10');
INSERT INTO `auth_rate_limits` VALUES (134, 'shout.like', 'uid:22', 2, '2026-04-24 22:12:17', '2026-04-24 22:12:17', NULL, '2026-03-22 22:41:09', '2026-04-24 22:12:17');
INSERT INTO `auth_rate_limits` VALUES (135, 'shout.report', 'uid:22', 1, '2026-03-23 01:56:14', '2026-03-23 01:56:14', NULL, '2026-03-22 23:02:25', '2026-03-23 01:56:14');
INSERT INTO `auth_rate_limits` VALUES (136, 'shout.create.daily', 'uid:22', 5, '2026-03-22 23:02:44', '2026-03-23 00:33:10', NULL, '2026-03-22 23:02:44', '2026-03-23 00:33:10');
INSERT INTO `auth_rate_limits` VALUES (137, 'shout.create.slow', 'uid:42', 1, '2026-03-23 00:34:02', '2026-03-23 00:34:02', NULL, '2026-03-22 23:05:31', '2026-03-23 00:34:02');
INSERT INTO `auth_rate_limits` VALUES (138, 'shout.create.burst', 'uid:42', 1, '2026-03-23 00:34:02', '2026-03-23 00:34:02', NULL, '2026-03-22 23:05:31', '2026-03-23 00:34:02');
INSERT INTO `auth_rate_limits` VALUES (139, 'shout.create.daily', 'uid:42', 2, '2026-03-22 23:05:31', '2026-03-23 00:34:02', NULL, '2026-03-22 23:05:31', '2026-03-23 00:34:02');
INSERT INTO `auth_rate_limits` VALUES (140, 'shout.report', 'uid:42', 1, '2026-03-22 23:05:42', '2026-03-22 23:05:42', NULL, '2026-03-22 23:05:42', '2026-03-22 23:05:42');
INSERT INTO `auth_rate_limits` VALUES (141, 'shout.like', 'uid:42', 1, '2026-03-22 23:46:01', '2026-03-22 23:46:01', NULL, '2026-03-22 23:46:01', '2026-03-22 23:46:01');
INSERT INTO `auth_rate_limits` VALUES (142, 'shout.tip', 'uid:22', 1, '2026-03-23 01:59:04', '2026-03-23 01:59:04', NULL, '2026-03-23 00:33:22', '2026-03-23 01:59:04');
INSERT INTO `auth_rate_limits` VALUES (143, 'shout.poll.vote', 'uid:42', 1, '2026-03-23 00:34:06', '2026-03-23 00:34:06', NULL, '2026-03-23 00:34:06', '2026-03-23 00:34:06');
INSERT INTO `auth_rate_limits` VALUES (144, 'shout.poll.vote', 'uid:22', 1, '2026-03-23 03:07:51', '2026-03-23 03:07:51', NULL, '2026-03-23 00:34:13', '2026-03-23 03:07:51');
INSERT INTO `auth_rate_limits` VALUES (145, 'job_work', 'uid:22', 1, '2026-03-23 03:44:27', '2026-03-23 03:44:27', NULL, '2026-03-23 03:44:27', '2026-03-23 03:44:27');
INSERT INTO `auth_rate_limits` VALUES (146, 'signup_email', 'testhesapx@gmail.com', 1, '2026-03-25 00:10:12', '2026-03-25 00:10:12', NULL, '2026-03-25 00:10:12', '2026-03-25 00:10:12');
INSERT INTO `auth_rate_limits` VALUES (147, 'signup_email_actor', 'testhesapx@gmail.com|::1|4ce33791409ec0c7ba6141f0aadb00d5442cbd8daf34528ace93ef67657a8708', 1, '2026-03-25 00:10:12', '2026-03-25 00:10:12', NULL, '2026-03-25 00:10:12', '2026-03-25 00:10:12');
INSERT INTO `auth_rate_limits` VALUES (148, 'market_sell', 'uid:22', 3, '2026-03-25 20:28:59', '2026-03-25 20:29:06', NULL, '2026-03-25 20:28:59', '2026-03-25 20:29:06');
INSERT INTO `auth_rate_limits` VALUES (161, 'nick_check_fingerprint', '0e978f2132f8cea7d44d719b1ce5513dcdc9c750b0efa2fd47871eb68d8bfad3', 4, '2026-04-24 22:05:43', '2026-04-24 22:06:53', NULL, '2026-04-24 22:05:43', '2026-04-24 22:06:53');
INSERT INTO `auth_rate_limits` VALUES (164, 'signup_email', 'onlyraze@gmail.com', 1, '2026-04-24 22:07:25', '2026-04-24 22:07:25', NULL, '2026-04-24 22:07:25', '2026-04-24 22:07:25');
INSERT INTO `auth_rate_limits` VALUES (165, 'signup_fingerprint', '0e978f2132f8cea7d44d719b1ce5513dcdc9c750b0efa2fd47871eb68d8bfad3', 1, '2026-04-24 22:07:25', '2026-04-24 22:07:25', NULL, '2026-04-24 22:07:25', '2026-04-24 22:07:25');
INSERT INTO `auth_rate_limits` VALUES (166, 'signup_actor', '::1|0e978f2132f8cea7d44d719b1ce5513dcdc9c750b0efa2fd47871eb68d8bfad3', 1, '2026-04-24 22:07:25', '2026-04-24 22:07:25', NULL, '2026-04-24 22:07:25', '2026-04-24 22:07:25');
INSERT INTO `auth_rate_limits` VALUES (167, 'signup_email_actor', 'onlyraze@gmail.com|::1|0e978f2132f8cea7d44d719b1ce5513dcdc9c750b0efa2fd47871eb68d8bfad3', 1, '2026-04-24 22:07:25', '2026-04-24 22:07:25', NULL, '2026-04-24 22:07:25', '2026-04-24 22:07:25');
INSERT INTO `auth_rate_limits` VALUES (168, 'password_reset_ip', '::1', 4, '2026-04-24 22:20:05', '2026-04-24 22:21:22', NULL, '2026-04-24 22:20:05', '2026-04-24 22:21:22');
INSERT INTO `auth_rate_limits` VALUES (169, 'password_reset_email', 'admin5k@gmail.com', 4, '2026-04-24 22:20:05', '2026-04-24 22:21:22', '2026-04-24 23:21:22', '2026-04-24 22:20:05', '2026-04-24 22:21:22');
INSERT INTO `auth_rate_limits` VALUES (170, 'password_reset_fingerprint', 'eb2842230941f7bf5ec0c17df1a009274ae76ec97e5827e5135663869a365e2a', 1, '2026-04-24 22:20:05', '2026-04-24 22:20:05', NULL, '2026-04-24 22:20:05', '2026-04-24 22:20:05');
INSERT INTO `auth_rate_limits` VALUES (171, 'password_reset_fingerprint', '227325985c2a0c517f27e70ba94a4138f42bec51544a7e1e606eadf3d6d237a3', 1, '2026-04-24 22:21:05', '2026-04-24 22:21:05', NULL, '2026-04-24 22:21:05', '2026-04-24 22:21:05');
INSERT INTO `auth_rate_limits` VALUES (172, 'password_reset_fingerprint', '676e7510f47a6897086861d42d7a1b34a1e40c1f8e707aedc407b6eb93574cdd', 1, '2026-04-24 22:21:22', '2026-04-24 22:21:22', NULL, '2026-04-24 22:21:22', '2026-04-24 22:21:22');
INSERT INTO `auth_rate_limits` VALUES (173, 'password_reset_fingerprint', '41eb4b1a72ab36a73f78215f1923fa657ae623f257d05f08cdce584382010f81', 1, '2026-04-24 22:21:22', '2026-04-24 22:21:22', NULL, '2026-04-24 22:21:22', '2026-04-24 22:21:22');

-- ----------------------------
-- Table structure for black_market_items
-- ----------------------------
DROP TABLE IF EXISTS `black_market_items`;
CREATE TABLE `black_market_items`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` int UNSIGNED NOT NULL,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'diger',
  `item_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `price` decimal(18, 2) NOT NULL DEFAULT 0.00,
  `quantity` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_black_market_items_seller_id`(`seller_id` ASC) USING BTREE,
  INDEX `idx_black_market_items_quantity`(`quantity` ASC) USING BTREE,
  INDEX `idx_black_market_items_created_at`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of black_market_items
-- ----------------------------

-- ----------------------------
-- Table structure for box_logs
-- ----------------------------
DROP TABLE IF EXISTS `box_logs`;
CREATE TABLE `box_logs`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int UNSIGNED NOT NULL,
  `item_id` int NOT NULL,
  `item_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `rarity` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_box_logs_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_box_logs_created_at`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 16 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of box_logs
-- ----------------------------
INSERT INTO `box_logs` VALUES (1, 22, 41, 'Enerji +30', 'rarity-uncommon', '2026-03-21 13:36:11');
INSERT INTO `box_logs` VALUES (2, 22, 30, 'Can +10', 'rarity-common', '2026-03-21 16:52:01');
INSERT INTO `box_logs` VALUES (3, 22, 40, 'Enerji +10', 'rarity-common', '2026-03-23 17:07:04');
INSERT INTO `box_logs` VALUES (4, 22, 99, 'Bozuk Mühimmat', 'rarity-junk', '2026-03-23 17:07:36');
INSERT INTO `box_logs` VALUES (5, 22, 21, 'ESP +500', 'rarity-uncommon', '2026-03-23 17:07:46');
INSERT INTO `box_logs` VALUES (6, 22, 20, 'ESP +100', 'rarity-common', '2026-03-23 17:08:00');
INSERT INTO `box_logs` VALUES (7, 22, 10, '5 Gold', 'rarity-common', '2026-03-24 15:16:14');
INSERT INTO `box_logs` VALUES (8, 22, 1, 'Q3 Silah x10', 'rarity-common', '2026-03-25 00:29:08');
INSERT INTO `box_logs` VALUES (9, 22, 10, '5 Gold', 'rarity-common', '2026-03-25 00:34:36');
INSERT INTO `box_logs` VALUES (10, 22, 99, 'Bozuk Mühimmat', 'rarity-junk', '2026-03-25 20:28:07');
INSERT INTO `box_logs` VALUES (11, 22, 11, '50 Gold', 'rarity-rare', '2026-03-25 20:37:04');
INSERT INTO `box_logs` VALUES (12, 22, 21, 'ESP +500', 'rarity-uncommon', '2026-03-28 15:02:55');
INSERT INTO `box_logs` VALUES (13, 22, 10, '5 Gold', 'rarity-common', '2026-04-08 06:15:18');
INSERT INTO `box_logs` VALUES (14, 22, 12, '10 Gold', 'rarity-uncommon', '2026-04-11 02:54:32');
INSERT INTO `box_logs` VALUES (15, 22, 40, 'Enerji +10', 'rarity-common', '2026-04-24 22:18:38');

-- ----------------------------
-- Table structure for bug_events
-- ----------------------------
DROP TABLE IF EXISTS `bug_events`;
CREATE TABLE `bug_events`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` int UNSIGNED NOT NULL,
  `actor_user_id` int UNSIGNED NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_bug_events_report`(`report_id` ASC) USING BTREE,
  INDEX `idx_bug_events_report_id`(`report_id` ASC) USING BTREE,
  INDEX `idx_bug_events_actor_user_id`(`actor_user_id` ASC) USING BTREE,
  INDEX `idx_bug_events_type`(`type` ASC) USING BTREE,
  INDEX `idx_bug_events_created_at`(`created_at` ASC) USING BTREE,
  CONSTRAINT `bug_events_chk_1` CHECK (json_valid(`meta`))
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of bug_events
-- ----------------------------

-- ----------------------------
-- Table structure for bug_replies
-- ----------------------------
DROP TABLE IF EXISTS `bug_replies`;
CREATE TABLE `bug_replies`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` int UNSIGNED NOT NULL,
  `admin_id` int UNSIGNED NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_bug_replies_report`(`report_id` ASC) USING BTREE,
  INDEX `idx_bug_replies_report_id`(`report_id` ASC) USING BTREE,
  INDEX `idx_bug_replies_admin_id`(`admin_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of bug_replies
-- ----------------------------

-- ----------------------------
-- Table structure for bug_reports
-- ----------------------------
DROP TABLE IF EXISTS `bug_reports`;
CREATE TABLE `bug_reports`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `votes_count` int NOT NULL DEFAULT 0,
  `category` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `priority` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'major',
  `repro_rate` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'sometimes',
  `image_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `error_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_bug_reports_category`(`category` ASC) USING BTREE,
  INDEX `idx_bug_reports_user_id`(`user_id` ASC) USING BTREE,
  INDEX `idx_bug_reports_status`(`status` ASC) USING BTREE,
  INDEX `idx_bug_reports_votes_count`(`votes_count` ASC) USING BTREE,
  INDEX `idx_bug_reports_created_at`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of bug_reports
-- ----------------------------

-- ----------------------------
-- Table structure for bug_subscriptions
-- ----------------------------
DROP TABLE IF EXISTS `bug_subscriptions`;
CREATE TABLE `bug_subscriptions`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_bug_subscription`(`report_id` ASC, `user_id` ASC) USING BTREE,
  UNIQUE INDEX `uq_bug_subscriptions_report_user`(`report_id` ASC, `user_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of bug_subscriptions
-- ----------------------------

-- ----------------------------
-- Table structure for bug_votes
-- ----------------------------
DROP TABLE IF EXISTS `bug_votes`;
CREATE TABLE `bug_votes`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_bug_vote`(`report_id` ASC, `user_id` ASC) USING BTREE,
  UNIQUE INDEX `uq_bug_votes_report_user`(`report_id` ASC, `user_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of bug_votes
-- ----------------------------

-- ----------------------------
-- Table structure for candidate_votes
-- ----------------------------
DROP TABLE IF EXISTS `candidate_votes`;
CREATE TABLE `candidate_votes`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `candidate` int NOT NULL,
  `uid` int NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of candidate_votes
-- ----------------------------

-- ----------------------------
-- Table structure for chats
-- ----------------------------
DROP TABLE IF EXISTS `chats`;
CREATE TABLE `chats`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `message` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `sender` int NOT NULL,
  `channel_id` int NOT NULL,
  `channel_type` int NOT NULL,
  `likes` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of chats
-- ----------------------------

-- ----------------------------
-- Table structure for coalition_embargos
-- ----------------------------
DROP TABLE IF EXISTS `coalition_embargos`;
CREATE TABLE `coalition_embargos`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `coalition_id` int UNSIGNED NOT NULL,
  `target_party_id` int UNSIGNED NOT NULL,
  `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_coalition_embargos`(`coalition_id` ASC, `target_party_id` ASC) USING BTREE,
  UNIQUE INDEX `ux_embargos_coalition_target`(`coalition_id` ASC, `target_party_id` ASC) USING BTREE,
  UNIQUE INDEX `uq_coalition_embargos_pair`(`coalition_id` ASC, `target_party_id` ASC) USING BTREE,
  UNIQUE INDEX `uniq_coalition_embargos_coalition_target`(`coalition_id` ASC, `target_party_id` ASC) USING BTREE,
  INDEX `idx_embargos_target`(`target_party_id` ASC) USING BTREE,
  INDEX `idx_coalition_embargos_target_party_id`(`target_party_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of coalition_embargos
-- ----------------------------

-- ----------------------------
-- Table structure for coalition_invites
-- ----------------------------
DROP TABLE IF EXISTS `coalition_invites`;
CREATE TABLE `coalition_invites`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `coalition_id` int UNSIGNED NOT NULL,
  `inviter_party_id` int UNSIGNED NOT NULL,
  `target_party_id` int UNSIGNED NOT NULL,
  `status` enum('pending','accepted','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'pending',
  `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_coalition_id`(`coalition_id` ASC) USING BTREE,
  INDEX `idx_target_party_id`(`target_party_id` ASC) USING BTREE,
  INDEX `idx_invites_target_status`(`target_party_id` ASC, `status` ASC) USING BTREE,
  INDEX `idx_invites_coalition_status`(`coalition_id` ASC, `status` ASC) USING BTREE,
  INDEX `idx_invites_inviter`(`inviter_party_id` ASC) USING BTREE,
  INDEX `idx_coalition_invites_coalition_id`(`coalition_id` ASC) USING BTREE,
  INDEX `idx_coalition_invites_target_party_id`(`target_party_id` ASC) USING BTREE,
  INDEX `idx_coalition_invites_inviter_party_id`(`inviter_party_id` ASC) USING BTREE,
  INDEX `idx_coalition_invites_target_status`(`target_party_id` ASC, `status` ASC) USING BTREE,
  INDEX `idx_coalition_invites_coalition_status`(`coalition_id` ASC, `status` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of coalition_invites
-- ----------------------------

-- ----------------------------
-- Table structure for coalitions
-- ----------------------------
DROP TABLE IF EXISTS `coalitions`;
CREATE TABLE `coalitions`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `manifesto` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `founder_party_id` int UNSIGNED NOT NULL,
  `treasury` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_coalitions_founder_party_id`(`founder_party_id` ASC) USING BTREE,
  UNIQUE INDEX `uq_coalitions_name`(`name` ASC) USING BTREE,
  INDEX `idx_founder_party`(`founder_party_id` ASC) USING BTREE,
  INDEX `idx_coalitions_founder`(`founder_party_id` ASC) USING BTREE,
  INDEX `idx_coalitions_founder_party_id`(`founder_party_id` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of coalitions
-- ----------------------------
INSERT INTO `coalitions` VALUES (1, 'admin alliance', '', 'admin alliance\n-', 6, 0.00, '2026-03-21 16:36:32');

-- ----------------------------
-- Table structure for companies
-- ----------------------------
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `type` int NOT NULL,
  `quality` int NOT NULL,
  `last_work` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_companies_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_companies_uid_id`(`uid` ASC, `id` ASC) USING BTREE,
  INDEX `idx_companies_type`(`type` ASC) USING BTREE,
  INDEX `idx_companies_uid_type`(`uid` ASC, `type` ASC) USING BTREE,
  INDEX `idx_companies_quality`(`quality` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of companies
-- ----------------------------
INSERT INTO `companies` VALUES (1, 18, 1, 1, '2016-08-28 14:29:47', '2016-08-11 16:20:06', '2016-08-28 14:29:47');
INSERT INTO `companies` VALUES (2, 18, 4, 1, '2016-08-28 14:29:47', '2016-08-11 16:21:00', '2016-08-28 14:29:47');
INSERT INTO `companies` VALUES (3, 18, 1, 2, '2016-08-28 14:29:47', '2016-08-12 18:01:46', '2016-08-28 14:29:47');
INSERT INTO `companies` VALUES (4, 18, 3, 1, '2016-08-28 14:29:47', '2016-08-16 17:37:33', '2016-08-28 14:29:47');
INSERT INTO `companies` VALUES (5, 22, 1, 5, NULL, '2026-03-21 13:44:23', '2026-03-21 13:44:23');

-- ----------------------------
-- Table structure for congress_candidates
-- ----------------------------
DROP TABLE IF EXISTS `congress_candidates`;
CREATE TABLE `congress_candidates`  (
  `uid` int NOT NULL,
  `yes` int NOT NULL DEFAULT 0,
  `no` int NOT NULL DEFAULT 0,
  `country` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`uid`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of congress_candidates
-- ----------------------------

-- ----------------------------
-- Table structure for congress_election_candidates
-- ----------------------------
DROP TABLE IF EXISTS `congress_election_candidates`;
CREATE TABLE `congress_election_candidates`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `election_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `party_id` int UNSIGNED NOT NULL DEFAULT 0,
  `votes` int UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_congress_candidate_cycle_user`(`election_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_congress_candidate_party`(`party_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of congress_election_candidates
-- ----------------------------

-- ----------------------------
-- Table structure for congress_election_histories
-- ----------------------------
DROP TABLE IF EXISTS `congress_election_histories`;
CREATE TABLE `congress_election_histories`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_id` int UNSIGNED NOT NULL,
  `election_key` datetime NOT NULL,
  `seat_count` int UNSIGNED NOT NULL DEFAULT 10,
  `candidate_count` int UNSIGNED NOT NULL DEFAULT 0,
  `total_votes` int UNSIGNED NOT NULL DEFAULT 0,
  `summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `winners_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `finished_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_congress_history_cycle`(`country_id` ASC, `election_key` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of congress_election_histories
-- ----------------------------
INSERT INTO `congress_election_histories` VALUES (1, 1, '2026-03-01 00:00:00', 10, 0, 0, '[]', '[]', '2026-03-28 14:06:45', '2026-03-28 14:06:45', '2026-03-28 14:06:45');

-- ----------------------------
-- Table structure for congress_election_votes
-- ----------------------------
DROP TABLE IF EXISTS `congress_election_votes`;
CREATE TABLE `congress_election_votes`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `election_id` int UNSIGNED NOT NULL,
  `voter_uid` int UNSIGNED NOT NULL,
  `candidate_uid` int UNSIGNED NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_congress_vote_cycle_user`(`election_id` ASC, `voter_uid` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of congress_election_votes
-- ----------------------------

-- ----------------------------
-- Table structure for congress_elections
-- ----------------------------
DROP TABLE IF EXISTS `congress_elections`;
CREATE TABLE `congress_elections`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_id` int UNSIGNED NOT NULL,
  `election_key` datetime NOT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'candidacy',
  `seat_count` int UNSIGNED NOT NULL DEFAULT 10,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `ends_at` datetime NULL DEFAULT NULL,
  `finished_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_congress_election_cycle`(`country_id` ASC, `election_key` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of congress_elections
-- ----------------------------
INSERT INTO `congress_elections` VALUES (1, 1, '2026-03-01 00:00:00', 'finished', 10, '2026-03-25 03:35:50', NULL, '2026-03-28 14:06:45', '2026-03-28 14:06:45');
INSERT INTO `congress_elections` VALUES (2, 1, '2026-04-01 00:00:00', 'review', 10, '2026-04-24 22:17:55', NULL, '2026-04-26 23:59:59', NULL);

-- ----------------------------
-- Table structure for congress_members
-- ----------------------------
DROP TABLE IF EXISTS `congress_members`;
CREATE TABLE `congress_members`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `party` int NOT NULL,
  `uid` int NOT NULL,
  `country` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_congress_uid_country`(`uid` ASC, `country` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of congress_members
-- ----------------------------

-- ----------------------------
-- Table structure for countries
-- ----------------------------
DROP TABLE IF EXISTS `countries`;
CREATE TABLE `countries`  (
  `id` int NOT NULL,
  `name` varchar(110) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `currency` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `capital` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `president` int UNSIGNED NOT NULL DEFAULT 0,
  `color` varchar(16) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `speaker_uid` int UNSIGNED NULL DEFAULT NULL,
  `minimum_wage` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `ohal_until` datetime NULL DEFAULT NULL,
  `emergency_session_until` datetime NULL DEFAULT NULL,
  `borders_closed_until` datetime NULL DEFAULT NULL,
  `mobilization_until` datetime NULL DEFAULT NULL,
  INDEX `id`(`id` ASC) USING BTREE,
  INDEX `capital`(`capital` ASC) USING BTREE,
  INDEX `idx_countries_president`(`president` ASC) USING BTREE,
  INDEX `idx_countries_name`(`name` ASC) USING BTREE,
  INDEX `idx_countries_currency`(`currency` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of countries
-- ----------------------------
INSERT INTO `countries` VALUES (1, 'Spain', 'esp', 3, '2016-08-07 18:21:18', '2016-08-07 18:21:18', 22, NULL, NULL, 30.00, NULL, NULL, NULL, '2026-03-27 01:16:03');

-- ----------------------------
-- Table structure for country_alliances
-- ----------------------------
DROP TABLE IF EXISTS `country_alliances`;
CREATE TABLE `country_alliances`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_1` int UNSIGNED NOT NULL,
  `country_2` int UNSIGNED NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_country_alliances`(`country_1` ASC, `country_2` ASC) USING BTREE,
  INDEX `idx_alliances_expires`(`expires_at` ASC) USING BTREE,
  INDEX `idx_alliances_c1_expires`(`country_1` ASC, `expires_at` ASC) USING BTREE,
  INDEX `idx_alliances_c2_expires`(`country_2` ASC, `expires_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of country_alliances
-- ----------------------------

-- ----------------------------
-- Table structure for country_funds
-- ----------------------------
DROP TABLE IF EXISTS `country_funds`;
CREATE TABLE `country_funds`  (
  `country` int NOT NULL,
  `gold` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `esp` decimal(10, 2) NOT NULL DEFAULT 0.00,
  UNIQUE INDEX `country`(`country` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of country_funds
-- ----------------------------

-- ----------------------------
-- Table structure for country_media_blackouts
-- ----------------------------
DROP TABLE IF EXISTS `country_media_blackouts`;
CREATE TABLE `country_media_blackouts`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_id` int UNSIGNED NOT NULL,
  `activated_by` int UNSIGNED NOT NULL,
  `source_shout_id` int UNSIGNED NULL DEFAULT NULL,
  `cost_currency` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'CC',
  `cost_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `starts_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_country_media_blackouts_country`(`country_id` ASC, `expires_at` ASC) USING BTREE,
  INDEX `idx_country_media_blackouts_shout`(`source_shout_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of country_media_blackouts
-- ----------------------------

-- ----------------------------
-- Table structure for country_relations
-- ----------------------------
DROP TABLE IF EXISTS `country_relations`;
CREATE TABLE `country_relations`  (
  `id` int NOT NULL,
  `country` int NOT NULL,
  `target` int NOT NULL,
  `relation` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of country_relations
-- ----------------------------

-- ----------------------------
-- Table structure for item_offers
-- ----------------------------
DROP TABLE IF EXISTS `item_offers`;
CREATE TABLE `item_offers`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `item` int NOT NULL,
  `quality` int NOT NULL,
  `uid` int NOT NULL,
  `price` decimal(10, 2) NOT NULL,
  `quantity` int NOT NULL,
  `country` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_item_offers_country`(`country` ASC) USING BTREE,
  INDEX `idx_item_offers_item_quality`(`item` ASC, `quality` ASC) USING BTREE,
  INDEX `idx_item_offers_price`(`price` ASC) USING BTREE,
  INDEX `idx_item_offers_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_item_offers_item_country_quality`(`item` ASC, `country` ASC, `quality` ASC) USING BTREE,
  INDEX `idx_item_offers_uid_item_quality_price_country`(`uid` ASC, `item` ASC, `quality` ASC, `price` ASC, `country` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 17 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of item_offers
-- ----------------------------
INSERT INTO `item_offers` VALUES (13, 1, 0, 18, 0.30, 205, 1, '2016-08-14 08:34:06', '2026-03-21 16:07:21');
INSERT INTO `item_offers` VALUES (14, 4, 1, 18, 0.10, 992, 1, '2016-08-14 08:36:45', '2026-03-21 16:13:15');
INSERT INTO `item_offers` VALUES (15, 1, 5, 22, 1.00, 1, 1, '2026-03-21 14:08:20', '2026-03-21 14:08:20');
INSERT INTO `item_offers` VALUES (16, 4, 1, 22, 1.00, 3, 1, '2026-03-25 20:28:59', '2026-03-25 20:29:06');

-- ----------------------------
-- Table structure for items
-- ----------------------------
DROP TABLE IF EXISTS `items`;
CREATE TABLE `items`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` tinyint NOT NULL DEFAULT 0,
  `canBeSold` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_items_type`(`type` ASC) USING BTREE,
  INDEX `idx_items_canbesold`(`canBeSold` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of items
-- ----------------------------

-- ----------------------------
-- Table structure for law_proposals
-- ----------------------------
DROP TABLE IF EXISTS `law_proposals`;
CREATE TABLE `law_proposals`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `type` int NOT NULL,
  `country` int NOT NULL,
  `reason` varchar(110) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `target_country` int NOT NULL DEFAULT 0,
  `amount` float NOT NULL DEFAULT 0,
  `yes` int NOT NULL DEFAULT 0,
  `no` int NOT NULL DEFAULT 0,
  `expected_votes` int NOT NULL,
  `finished` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `member` int UNSIGNED NOT NULL DEFAULT 0,
  `currency` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'local',
  `is_vetoed` tinyint UNSIGNED NOT NULL DEFAULT 0,
  `is_secret` tinyint UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_law_proposals_country`(`country` ASC) USING BTREE,
  INDEX `idx_law_proposals_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_law_proposals_type`(`type` ASC) USING BTREE,
  INDEX `idx_law_proposals_finished`(`finished` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 12 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of law_proposals
-- ----------------------------
INSERT INTO `law_proposals` VALUES (1, 18, 3, 1, 'We must increase it to steal more money', 0, 5.56, 0, 0, 2, 1, '2016-08-21 16:00:45', '2026-03-04 12:11:46', 0, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (2, 18, 3, 1, 'We must increase it to steal more money', 0, 5.56, 0, 0, 2, 1, '2016-08-21 16:01:26', '2026-03-04 12:11:46', 0, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (3, 19, 11, 1, 'testtttttttttttttt', 0, 0, 1, 0, 1, 1, '2026-03-04 12:14:07', '2026-03-04 12:14:32', 19, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (4, 19, 17, 1, '[W:15 M:15 MW:15] testtttttttttttttttttttttt', 0, 0, 1, 0, 1, 1, '2026-03-04 12:15:12', '2026-03-04 12:15:48', 0, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (5, 22, 17, 1, '[W:30 M:30 MW:30] testtttt', 0, 0, 1, 0, 1, 1, '2026-03-21 16:18:36', '2026-03-21 16:22:31', 0, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (6, 22, 5, 1, 'testttttt', 0, 0, 1, 0, 1, 1, '2026-03-25 01:04:09', '2026-03-25 01:04:20', 19, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (7, 22, 5, 1, 'tewstttttttt', 0, 0, 1, 0, 1, 1, '2026-03-25 01:14:35', '2026-03-25 01:14:39', 19, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (8, 22, 21, 1, 'testt', 0, 0, 1, 0, 1, 1, '2026-03-25 01:15:58', '2026-03-25 01:16:03', 0, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (9, 22, 16, 1, 'testttttt', 0, 0, 1, 0, 1, 1, '2026-03-25 01:34:43', '2026-03-25 01:34:49', 19, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (10, 22, 5, 1, 'testtt', 0, 0, 1, 0, 1, 1, '2026-03-25 02:02:30', '2026-03-25 02:02:46', 19, 'local', 0, 0);
INSERT INTO `law_proposals` VALUES (11, 22, 14, 1, 'testt', 0, 0, 1, 0, 1, 1, '2026-03-25 02:03:05', '2026-03-25 02:03:10', 0, 'local', 0, 0);

-- ----------------------------
-- Table structure for law_votes
-- ----------------------------
DROP TABLE IF EXISTS `law_votes`;
CREATE TABLE `law_votes`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `law` int NOT NULL,
  `uid` int NOT NULL,
  `in_favor` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_law_uid`(`law` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `uq_law_votes_law_uid`(`law` ASC, `uid` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 10 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of law_votes
-- ----------------------------
INSERT INTO `law_votes` VALUES (1, 3, 19, 1, '2026-03-04 12:14:32', '2026-03-04 12:14:32');
INSERT INTO `law_votes` VALUES (2, 4, 19, 1, '2026-03-04 12:15:48', '2026-03-04 12:15:48');
INSERT INTO `law_votes` VALUES (3, 5, 22, 1, '2026-03-21 16:22:05', '2026-03-21 16:22:05');
INSERT INTO `law_votes` VALUES (4, 6, 22, 1, '2026-03-25 01:04:20', '2026-03-25 01:04:20');
INSERT INTO `law_votes` VALUES (5, 7, 22, 1, '2026-03-25 01:14:39', '2026-03-25 01:14:39');
INSERT INTO `law_votes` VALUES (6, 8, 22, 1, '2026-03-25 01:16:03', '2026-03-25 01:16:03');
INSERT INTO `law_votes` VALUES (7, 9, 22, 1, '2026-03-25 01:34:49', '2026-03-25 01:34:49');
INSERT INTO `law_votes` VALUES (8, 10, 22, 1, '2026-03-25 02:02:46', '2026-03-25 02:02:46');
INSERT INTO `law_votes` VALUES (9, 11, 22, 1, '2026-03-25 02:03:10', '2026-03-25 02:03:10');

-- ----------------------------
-- Table structure for law_whips
-- ----------------------------
DROP TABLE IF EXISTS `law_whips`;
CREATE TABLE `law_whips`  (
  `law_id` int NOT NULL,
  `party_id` int NOT NULL,
  `decision` enum('yes','no') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`law_id`, `party_id`) USING BTREE,
  UNIQUE INDEX `uq_law_whips`(`law_id` ASC, `party_id` ASC) USING BTREE,
  UNIQUE INDEX `uq_law_whips_law_party`(`law_id` ASC, `party_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of law_whips
-- ----------------------------

-- ----------------------------
-- Table structure for message_archives
-- ----------------------------
DROP TABLE IF EXISTS `message_archives`;
CREATE TABLE `message_archives`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` bigint UNSIGNED NOT NULL,
  `other_uid` bigint UNSIGNED NOT NULL,
  `archived_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_message_archives_uid_other`(`uid` ASC, `other_uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_message_archives_uid_other_uid`(`uid` ASC, `other_uid` ASC) USING BTREE,
  INDEX `idx_message_archives_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_message_archives_other`(`other_uid` ASC) USING BTREE,
  INDEX `idx_arch_uid_other`(`uid` ASC, `other_uid` ASC) USING BTREE,
  INDEX `idx_arch_other_uid`(`other_uid` ASC) USING BTREE,
  INDEX `idx_message_archives_other_uid`(`other_uid` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of message_archives
-- ----------------------------

-- ----------------------------
-- Table structure for messages
-- ----------------------------
DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_uid` bigint UNSIGNED NOT NULL,
  `to_uid` bigint UNSIGNED NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `read_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_messages_from_uid`(`from_uid` ASC) USING BTREE,
  INDEX `idx_messages_to_uid`(`to_uid` ASC) USING BTREE,
  INDEX `idx_messages_read_at`(`read_at` ASC) USING BTREE,
  INDEX `idx_messages_created_at`(`created_at` ASC) USING BTREE,
  INDEX `idx_messages_from_to_id`(`from_uid` ASC, `to_uid` ASC, `id` ASC) USING BTREE,
  INDEX `idx_messages_to_from_read`(`to_uid` ASC, `from_uid` ASC, `read_at` ASC) USING BTREE,
  INDEX `idx_messages_to_id`(`to_uid` ASC, `id` ASC) USING BTREE,
  INDEX `idx_messages_from_id`(`from_uid` ASC, `id` ASC) USING BTREE,
  INDEX `idx_messages_to_read`(`to_uid` ASC, `read_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of messages
-- ----------------------------

-- ----------------------------
-- Table structure for newspaper_articles
-- ----------------------------
DROP TABLE IF EXISTS `newspaper_articles`;
CREATE TABLE `newspaper_articles`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(110) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `text` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `category` int NOT NULL,
  `uid` int NOT NULL,
  `country` int NOT NULL,
  `views` int NOT NULL DEFAULT 0,
  `is_promoted` tinyint(1) NOT NULL DEFAULT 0,
  `votes` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_articles_uid_id`(`uid` ASC, `id` ASC) USING BTREE,
  INDEX `idx_articles_votes`(`votes` ASC) USING BTREE,
  INDEX `idx_articles_created_at`(`created_at` ASC) USING BTREE,
  INDEX `idx_articles_promoted_id`(`is_promoted` ASC, `id` ASC) USING BTREE,
  INDEX `idx_newspaper_articles_votes_created`(`votes` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_newspaper_articles_country_created`(`country` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_newspaper_articles_category_created`(`category` ASC, `created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of newspaper_articles
-- ----------------------------
INSERT INTO `newspaper_articles` VALUES (1, 'Debes morir', '[center][size=6]Porque lo digo [b]yo[/b][/size][/center]\n\n[center][size=6][b][img]http://cdn.arstechnica.net/wp-content/uploads/2016/02/5718897981_10faa45ac3_b-640x624.jpg[/img][/b][/size][/center]\n', 1, 18, 1, 39, 0, 1, '2016-08-27 11:09:23', '2026-03-24 15:40:47');
INSERT INTO `newspaper_articles` VALUES (2, 'Congress declares WAR', '[center][img]http://www.army-technology.com/projects/ariete/images/ariete5.jpg[/img][/center]\n[center][b][size=7]Congress declares WAR[/size][/b][/center]\n\n[justify][color=#000000][size=2][font=\"Open Sans\", Arial, sans-serif]orem ipsum dolor sit amet, consectetur adipiscing elit. Etiam eget augue eu ipsum molestie posuere. Ut ac ante ac odio aliquam pretium eget dictum arcu. Morbi malesuada consequat mattis. Suspendisse elementum volutpat justo, et malesuada dui posuere quis. Aliquam erat volutpat. Curabitur tristique lacinia elit, non sollicitudin orci pellentesque ut. Morbi lobortis erat quis eleifend ornare. Nunc eget fermentum neque. Interdum et malesuada fames ac ante ipsum primis in faucibus. In neque nisi, elementum scelerisque magna eget, mattis scelerisque urna. Ut congue ante at sapien dictum scelerisque. Duis sed ultricies lectus. Morbi ex nisl, facilisis vitae dolor in, facilisis tincidunt felis. Nulla in finibus dui. Integer fringilla quam vel aliquet faucibus. Ut ante eros, vehicula ut venenatis vel, gravida non quam.[/font][/size][/color][/justify]\n[justify][color=#000000][size=2][font=\"Open Sans\", Arial, sans-serif]Duis mollis accumsan risus, sit amet ornare lorem aliquet a. Fusce erat ante, elementum sit amet consequat in, imperdiet blandit risus. Maecenas hendrerit neque sit amet imperdiet tempor. Morbi sit amet pretium ante. Nulla facilisis eleifend diam a porta. Nulla condimentum quis ex vel mattis. Donec faucibus justo vitae scelerisque tempor. Duis est nisi, dignissim ut tempor id, suscipit ac lorem. In libero augue, euismod blandit nibh ut, molestie accumsan est. Donec molestie sed nibh non elementum. Aliquam pulvinar justo a tortor lobortis, id suscipit dui pulvinar. Phasellus vitae sapien sapien. Cras vulputate a tellus eget fermentum. Donec maximus massa dolor, mollis interdum neque efficitur quis. Pellentesque et ligula finibus, venenatis ligula et, rutrum nibh. Duis non maximus enim.[/font][/size][/color][/justify]\n[justify][color=#000000][size=2][font=\"Open Sans\", Arial, sans-serif]Suspendisse potenti. Pellentesque maximus sodales massa ut tempus. Curabitur at ultrices ante, vitae vestibulum nulla. Praesent ante orci, cursus non consectetur non, scelerisque euismod nisl. Donec sagittis molestie egestas. In mi nunc, auctor et aliquam vel, pharetra consequat lectus. Phasellus porttitor eget ligula id gravida. Etiam at libero efficitur, convallis eros vel, maximus lectus. Vivamus facilisis, ligula a dictum scelerisque, eros tellus pretium justo, pellentesque elementum nisi dolor non mauris. Interdum et malesuada fames ac ante ipsum primis in faucibus. Suspendisse tempus porta imperdiet.[/font][/size][/color][/justify]\n', 1, 18, 1, 4, 0, 1, '2016-09-06 17:16:15', '2026-03-28 14:06:36');
INSERT INTO `newspaper_articles` VALUES (3, 'welcome the game', 'welcome the game', 4, 22, 1, 40, 1, 2, '2026-03-22 15:42:32', '2026-03-28 14:06:34');

-- ----------------------------
-- Table structure for newspaper_subscribers
-- ----------------------------
DROP TABLE IF EXISTS `newspaper_subscribers`;
CREATE TABLE `newspaper_subscribers`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `newspaper_id` bigint UNSIGNED NOT NULL,
  `uid` bigint UNSIGNED NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_newspaper_subscribers`(`newspaper_id` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `ux_subscribers_newspaper_uid`(`newspaper_id` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_newspaper_subscribers_newspaper_uid`(`newspaper_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_newspaper_subscribers_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_subscribers_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_newspaper_subscribers_newspaper_id`(`newspaper_id` ASC) USING BTREE,
  INDEX `idx_newspaper_subscribers_uid_newspaper`(`uid` ASC, `newspaper_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of newspaper_subscribers
-- ----------------------------

-- ----------------------------
-- Table structure for newspapers
-- ----------------------------
DROP TABLE IF EXISTS `newspapers`;
CREATE TABLE `newspapers`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(110) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `country` int NOT NULL,
  `subscribers` int UNSIGNED NOT NULL DEFAULT 0,
  `uid` int NOT NULL,
  `description` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `ux_newspapers_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_newspapers_subscribers`(`subscribers` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of newspapers
-- ----------------------------
INSERT INTO `newspapers` VALUES (2, 'El Noticiero', 1, 0, 18, 'Para pasar el rato', '2016-08-27 09:44:08', '2016-08-27 09:44:08');
INSERT INTO `newspapers` VALUES (3, 'admin news', 1, 0, 22, 'admin news', '2026-03-22 15:32:27', '2026-03-22 15:32:27');

-- ----------------------------
-- Table structure for notifications
-- ----------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` bigint UNSIGNED NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_notifications_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_notifications_is_read`(`is_read` ASC) USING BTREE,
  INDEX `idx_notifications_created_at`(`created_at` ASC) USING BTREE,
  INDEX `idx_notifications_uid_id`(`uid` ASC, `id` ASC) USING BTREE,
  INDEX `idx_notifications_uid_isread`(`uid` ASC, `is_read` ASC) USING BTREE,
  INDEX `idx_notifications_uid_isread_id`(`uid` ASC, `is_read` ASC, `id` ASC) USING BTREE,
  INDEX `idx_uid_id`(`uid` ASC, `id` ASC) USING BTREE,
  INDEX `idx_uid_is_read`(`uid` ASC, `is_read` ASC) USING BTREE,
  INDEX `idx_notifications_uid_read_created`(`uid` ASC, `is_read` ASC, `created_at` ASC) USING BTREE,
  CONSTRAINT `notifications_chk_1` CHECK (json_valid(`meta`))
) ENGINE = InnoDB AUTO_INCREMENT = 25 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of notifications
-- ----------------------------
INSERT INTO `notifications` VALUES (1, 22, 'shout_like', 'Bir shoutun begenildi', 'Bir kullanici shoutunu begendi.', '/#shout-6', NULL, NULL, '{\"shout_id\":6,\"from_uid\":42}', 1, '2026-03-22 23:46:01');
INSERT INTO `notifications` VALUES (2, 42, 'shout_tip', 'Bir shoutun destek aldi', '1 Gold destek aldigin bir shout var.', '/#shout-5', NULL, NULL, '{\"shout_id\":5,\"from_uid\":22,\"gold_amount\":1}', 1, '2026-03-23 00:33:22');
INSERT INTO `notifications` VALUES (3, 22, 'shout_restriction', 'Shout paylasimin gecici olarak kisitlandi', 'Cok fazla kaldirilan shout davranisi nedeniyle 2 gun boyunca shout atamayacaksin.', '/', NULL, NULL, '{\"muted_until\":\"2026-03-25 01:46:47\"}', 1, '2026-03-23 01:46:47');
INSERT INTO `notifications` VALUES (4, 42, 'shout_tip', 'Bir shoutun destek aldi', '1 Gold destek aldigin bir shout var.', '/#shout-8', NULL, NULL, '{\"shout_id\":8,\"from_uid\":22,\"gold_amount\":1}', 1, '2026-03-23 01:59:04');
INSERT INTO `notifications` VALUES (5, 42, 'shout_restriction', 'Shout paylasimin gecici olarak kisitlandi', 'Admin seni 24 saat boyunca shouttan susturdu.', '/', NULL, NULL, '{\"muted_until\":\"2026-03-24 02:53:57\"}', 1, '2026-03-23 02:53:57');
INSERT INTO `notifications` VALUES (6, 42, 'shout_restriction_lifted', 'Shout kisitin kaldirildi', 'Admin shout kisitini kaldirdi. Tekrar shout atabilirsin.', '/', NULL, NULL, NULL, 1, '2026-03-23 02:55:03');
INSERT INTO `notifications` VALUES (7, 22, 'shout_restriction_lifted', 'Shout kisitin kaldirildi', 'Admin shout kisitini kaldirdi. Tekrar shout atabilirsin.', '/', NULL, NULL, NULL, 1, '2026-03-23 02:55:11');
INSERT INTO `notifications` VALUES (8, 42, 'shout_deleted', 'Bir shoutun kaldirildi', 'Admin bir shoutunu kaldirdi.', '/', NULL, NULL, '{\"shout_id\":8}', 1, '2026-03-23 02:56:32');
INSERT INTO `notifications` VALUES (9, 42, 'shout_mention', 'Bir shoutta senden bahsedildi', 'admin5k seni bir shoutta etiketledi.', '/#shout-21', NULL, NULL, '{\"shout_id\":21,\"thread_shout_id\":21,\"from_uid\":22}', 1, '2026-03-28 04:41:11');
INSERT INTO `notifications` VALUES (10, 22, 'shout_mention', 'Bir shoutta senden bahsedildi', 'botplayer seni bir shoutta etiketledi.', '/#shout-21', NULL, NULL, '{\"shout_id\":22,\"thread_shout_id\":21,\"from_uid\":42}', 1, '2026-03-28 04:50:02');
INSERT INTO `notifications` VALUES (11, 22, 'shout_mention', 'Bir shoutta senden bahsedildi', 'botplayer seni bir shoutta etiketledi.', '/#shout-23', NULL, NULL, '{\"shout_id\":23,\"thread_shout_id\":23,\"from_uid\":42}', 1, '2026-03-28 04:59:19');
INSERT INTO `notifications` VALUES (12, 19, 'election', 'Kongre secimi sonuclandi', '0 aday yeni meclise girdi. Sandalyeler parti listesi esasina gore dagitildi.', '/elections/archive/congress', NULL, NULL, '{\"scope\":\"congress\",\"country_id\":1}', 0, '2026-03-28 14:06:45');
INSERT INTO `notifications` VALUES (13, 29, 'election', 'Kongre secimi sonuclandi', '0 aday yeni meclise girdi. Sandalyeler parti listesi esasina gore dagitildi.', '/elections/archive/congress', NULL, NULL, '{\"scope\":\"congress\",\"country_id\":1}', 0, '2026-03-28 14:06:45');
INSERT INTO `notifications` VALUES (14, 42, 'election', 'Kongre secimi sonuclandi', '0 aday yeni meclise girdi. Sandalyeler parti listesi esasina gore dagitildi.', '/elections/archive/congress', NULL, NULL, '{\"scope\":\"congress\",\"country_id\":1}', 1, '2026-03-28 14:06:45');
INSERT INTO `notifications` VALUES (15, 43, 'election', 'Kongre secimi sonuclandi', '0 aday yeni meclise girdi. Sandalyeler parti listesi esasina gore dagitildi.', '/elections/archive/congress', NULL, NULL, '{\"scope\":\"congress\",\"country_id\":1}', 0, '2026-03-28 14:06:45');
INSERT INTO `notifications` VALUES (16, 18, 'election', 'Kongre secimi sonuclandi', '0 aday yeni meclise girdi. Sandalyeler parti listesi esasina gore dagitildi.', '/elections/archive/congress', NULL, NULL, '{\"scope\":\"congress\",\"country_id\":1}', 0, '2026-03-28 14:06:45');
INSERT INTO `notifications` VALUES (17, 22, 'election', 'Kongre secimi sonuclandi', '0 aday yeni meclise girdi. Sandalyeler parti listesi esasina gore dagitildi.', '/elections/archive/congress', NULL, NULL, '{\"scope\":\"congress\",\"country_id\":1}', 1, '2026-03-28 14:06:45');
INSERT INTO `notifications` VALUES (18, 42, 'shout_like', 'Bir shoutun begenildi', 'Bir kullanici shoutunu begendi.', '/#shout-23', NULL, NULL, '{\"shout_id\":23,\"from_uid\":22}', 1, '2026-03-30 15:48:28');
INSERT INTO `notifications` VALUES (19, 18, 'state_decree', 'Resmi duyuru yayinda', 'admin5k yeni bir resmi duyuru paylasti.', '/#shout-26', NULL, NULL, '{\"shout_id\":26,\"thread_shout_id\":26,\"from_uid\":22,\"country_id\":1}', 0, '2026-04-11 02:40:27');
INSERT INTO `notifications` VALUES (20, 19, 'state_decree', 'Resmi duyuru yayinda', 'admin5k yeni bir resmi duyuru paylasti.', '/#shout-26', NULL, NULL, '{\"shout_id\":26,\"thread_shout_id\":26,\"from_uid\":22,\"country_id\":1}', 0, '2026-04-11 02:40:27');
INSERT INTO `notifications` VALUES (21, 29, 'state_decree', 'Resmi duyuru yayinda', 'admin5k yeni bir resmi duyuru paylasti.', '/#shout-26', NULL, NULL, '{\"shout_id\":26,\"thread_shout_id\":26,\"from_uid\":22,\"country_id\":1}', 0, '2026-04-11 02:40:27');
INSERT INTO `notifications` VALUES (22, 42, 'state_decree', 'Resmi duyuru yayinda', 'admin5k yeni bir resmi duyuru paylasti.', '/#shout-26', NULL, NULL, '{\"shout_id\":26,\"thread_shout_id\":26,\"from_uid\":22,\"country_id\":1}', 1, '2026-04-11 02:40:27');
INSERT INTO `notifications` VALUES (23, 43, 'state_decree', 'Resmi duyuru yayinda', 'admin5k yeni bir resmi duyuru paylasti.', '/#shout-26', NULL, NULL, '{\"shout_id\":26,\"thread_shout_id\":26,\"from_uid\":22,\"country_id\":1}', 0, '2026-04-11 02:40:27');
INSERT INTO `notifications` VALUES (24, 42, 'shout_mention', 'Bir shoutta senden bahsedildi', 'admin5k seni bir shoutta etiketledi.', '/#shout-29', NULL, NULL, '{\"shout_id\":29,\"thread_shout_id\":29,\"from_uid\":22}', 1, '2026-04-11 09:19:24');

-- ----------------------------
-- Table structure for party_candidates
-- ----------------------------
DROP TABLE IF EXISTS `party_candidates`;
CREATE TABLE `party_candidates`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `election_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `ux_party_candidates_election_uid`(`election_id` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_party_candidates_election_uid`(`election_id` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_party_candidate_cycle_user`(`election_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_candidates_election`(`election_id` ASC) USING BTREE,
  INDEX `idx_party_candidates_election`(`election_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_candidates
-- ----------------------------

-- ----------------------------
-- Table structure for party_daily_actions
-- ----------------------------
DROP TABLE IF EXISTS `party_daily_actions`;
CREATE TABLE `party_daily_actions`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `action_type` varchar(40) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `meta` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_party_daily_actions_party_type_created`(`party_id` ASC, `action_type` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_party_daily_actions_uid_type_created`(`uid` ASC, `action_type` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_party_daily_actions_created`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_daily_actions
-- ----------------------------

-- ----------------------------
-- Table structure for party_election_histories
-- ----------------------------
DROP TABLE IF EXISTS `party_election_histories`;
CREATE TABLE `party_election_histories`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` int UNSIGNED NOT NULL,
  `election_key` datetime NOT NULL,
  `winner_uid` int UNSIGNED NOT NULL DEFAULT 0,
  `winner_votes` int UNSIGNED NOT NULL DEFAULT 0,
  `total_votes` int UNSIGNED NOT NULL DEFAULT 0,
  `candidate_count` int UNSIGNED NOT NULL DEFAULT 0,
  `finished_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_party_election_history_cycle`(`party_id` ASC, `election_key` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_election_histories
-- ----------------------------

-- ----------------------------
-- Table structure for party_elections
-- ----------------------------
DROP TABLE IF EXISTS `party_elections`;
CREATE TABLE `party_elections`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` int UNSIGNED NOT NULL,
  `election_key` datetime NULL DEFAULT NULL,
  `status` enum('running','finished') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'running',
  `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at` datetime NULL DEFAULT NULL,
  `finished_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_party_election_cycle`(`party_id` ASC, `election_key` ASC) USING BTREE,
  INDEX `idx_party_elections_party`(`party_id` ASC) USING BTREE,
  INDEX `idx_party_elections_party_status`(`party_id` ASC, `status` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_elections
-- ----------------------------

-- ----------------------------
-- Table structure for party_join_applications
-- ----------------------------
DROP TABLE IF EXISTS `party_join_applications`;
CREATE TABLE `party_join_applications`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `message` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `status` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` int UNSIGNED NULL DEFAULT NULL,
  `reviewed_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_party_join_applications_party_status`(`party_id` ASC, `status` ASC) USING BTREE,
  INDEX `idx_party_join_applications_uid_status`(`uid` ASC, `status` ASC) USING BTREE,
  INDEX `idx_party_join_applications_reviewed_by`(`reviewed_by` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_join_applications
-- ----------------------------

-- ----------------------------
-- Table structure for party_logs
-- ----------------------------
DROP TABLE IF EXISTS `party_logs`;
CREATE TABLE `party_logs`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_party_logs_party`(`party_id` ASC) USING BTREE,
  INDEX `idx_party_logs_party_created`(`party_id` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_party_logs_party_id`(`party_id` ASC) USING BTREE,
  INDEX `idx_party_logs_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 13 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_logs
-- ----------------------------
INSERT INTO `party_logs` VALUES (1, 5, 19, 'Bir Üye 50 Altın bağışladı.', '2026-03-04 14:13:46');
INSERT INTO `party_logs` VALUES (2, 5, 19, 'Sponsorlu Reklam yayına alındı.', '2026-03-04 14:13:50');
INSERT INTO `party_logs` VALUES (3, 6, 22, 'Bir Üye 50 Altın bağışladı.', '2026-03-21 19:17:27');
INSERT INTO `party_logs` VALUES (4, 6, 22, 'Sponsorlu Reklam yayına alındı.', '2026-03-21 19:17:29');
INSERT INTO `party_logs` VALUES (5, 6, 22, 'Karargah ayarlari guncellendi.', '2026-03-21 20:37:11');
INSERT INTO `party_logs` VALUES (6, 6, 42, 'Bir Uye katıldı.', '2026-03-21 20:52:53');
INSERT INTO `party_logs` VALUES (7, 6, 42, 'Bir Uye ayrıldı.', '2026-03-21 20:58:28');
INSERT INTO `party_logs` VALUES (8, 6, 42, 'Bir Uye katıldı.', '2026-03-21 20:59:12');
INSERT INTO `party_logs` VALUES (9, 6, 22, 'Bir Uye 10 Altın bağışladı.', '2026-03-21 21:14:21');
INSERT INTO `party_logs` VALUES (10, 6, 22, 'admin5k 100 Altın bağışladı.', '2026-03-21 21:40:36');
INSERT INTO `party_logs` VALUES (11, 6, 22, 'Sponsorlu reklam kampanyasi baslatildi: 1 Gun / 20 Altin.', '2026-03-22 20:49:41');
INSERT INTO `party_logs` VALUES (12, 6, 22, 'Karargah ayarlari guncellendi.', '2026-03-23 02:07:11');

-- ----------------------------
-- Table structure for party_members
-- ----------------------------
DROP TABLE IF EXISTS `party_members`;
CREATE TABLE `party_members`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `party` int NOT NULL,
  `uid` int NOT NULL,
  `level` int NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uid`(`uid` ASC) USING BTREE,
  UNIQUE INDEX `ux_party_members_party_uid`(`party` ASC, `uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_party_members_party_uid`(`party` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_party_members_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_party_members_party_level`(`party` ASC, `level` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 9 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_members
-- ----------------------------
INSERT INTO `party_members` VALUES (4, 4, 18, 3, '2016-08-16 15:52:26', '2016-08-16 15:52:26');
INSERT INTO `party_members` VALUES (5, 5, 19, 3, '2026-03-04 12:11:40', '2026-03-04 12:11:40');
INSERT INTO `party_members` VALUES (6, 6, 22, 3, '2026-03-21 16:17:17', '2026-03-21 16:17:17');
INSERT INTO `party_members` VALUES (8, 6, 42, 0, '2026-03-21 17:59:12', '2026-03-21 18:02:03');

-- ----------------------------
-- Table structure for party_votes
-- ----------------------------
DROP TABLE IF EXISTS `party_votes`;
CREATE TABLE `party_votes`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `election_id` int UNSIGNED NOT NULL,
  `voter_uid` int UNSIGNED NOT NULL,
  `candidate_uid` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_party_vote`(`election_id` ASC, `voter_uid` ASC) USING BTREE,
  UNIQUE INDEX `ux_party_votes_election_voter`(`election_id` ASC, `voter_uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_party_votes_election_voter`(`election_id` ASC, `voter_uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_party_vote_cycle_user`(`election_id` ASC, `voter_uid` ASC) USING BTREE,
  INDEX `idx_party_votes_election`(`election_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of party_votes
-- ----------------------------

-- ----------------------------
-- Table structure for political_parties
-- ----------------------------
DROP TABLE IF EXISTS `political_parties`;
CREATE TABLE `political_parties`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `description` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `announcement` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `ideology` varchar(120) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `economy_stance` varchar(120) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `cover_pic` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `logo_url` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `daily_fee` decimal(18, 2) NOT NULL DEFAULT 0.00,
  `country` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `coalition_id` int UNSIGNED NULL DEFAULT NULL,
  `treasury` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `ad_until` datetime NULL DEFAULT NULL,
  `last_ad_purchase_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uid`(`uid` ASC) USING BTREE,
  INDEX `idx_political_parties_coalition_id`(`coalition_id` ASC) USING BTREE,
  INDEX `idx_parties_country`(`country` ASC) USING BTREE,
  INDEX `idx_parties_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_parties_ad_until`(`ad_until` ASC) USING BTREE,
  INDEX `idx_parties_coalition`(`coalition_id` ASC) USING BTREE,
  INDEX `idx_political_parties_country`(`country` ASC) USING BTREE,
  INDEX `idx_political_parties_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of political_parties
-- ----------------------------
INSERT INTO `political_parties` VALUES (4, 18, 'Los traidores', 'Traicionar al país con todos los medios disponibles.', NULL, NULL, NULL, NULL, NULL, 0.00, 1, '2016-08-16 15:52:26', '2016-08-16 15:52:26', NULL, 0.00, NULL, NULL);
INSERT INTO `political_parties` VALUES (5, 19, 'Admin Party', 'Admin Party', NULL, NULL, NULL, NULL, NULL, 0.00, 1, '2026-03-04 12:11:40', '2026-03-04 12:13:50', NULL, 0.00, '2026-03-05 12:13:50', NULL);
INSERT INTO `political_parties` VALUES (6, 22, 'Admin Party new', 'Admin Party new', NULL, 'Merkez', 'Karma Ekonomi', '', 'https://i.hizliresim.com/lqvwjvj.jpg', 1.00, 1, '2026-03-21 16:17:17', '2026-03-22 23:07:11', 1, 90.00, '2026-03-23 17:49:41', '2026-03-22 17:49:41');

-- ----------------------------
-- Table structure for presidential_candidates
-- ----------------------------
DROP TABLE IF EXISTS `presidential_candidates`;
CREATE TABLE `presidential_candidates`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int UNSIGNED NOT NULL,
  `country` int UNSIGNED NOT NULL,
  `election_key` datetime NOT NULL,
  `votes` int UNSIGNED NOT NULL DEFAULT 0,
  `campaign_title` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `campaign_message` varchar(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_presidential_candidate_cycle_user`(`country` ASC, `election_key` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_presidential_candidates_country_key`(`country` ASC, `election_key` ASC) USING BTREE,
  INDEX `idx_presidential_candidates_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of presidential_candidates
-- ----------------------------

-- ----------------------------
-- Table structure for presidential_election_histories
-- ----------------------------
DROP TABLE IF EXISTS `presidential_election_histories`;
CREATE TABLE `presidential_election_histories`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `country` int UNSIGNED NOT NULL,
  `election_key` datetime NOT NULL,
  `winner_uid` int UNSIGNED NOT NULL DEFAULT 0,
  `winner_votes` int UNSIGNED NOT NULL DEFAULT 0,
  `total_votes` int UNSIGNED NOT NULL DEFAULT 0,
  `candidate_count` int UNSIGNED NOT NULL DEFAULT 0,
  `summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `finished_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_pres_history_cycle`(`country` ASC, `election_key` ASC) USING BTREE,
  INDEX `idx_pres_history_country_finished`(`country` ASC, `finished_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of presidential_election_histories
-- ----------------------------
INSERT INTO `presidential_election_histories` VALUES (1, 1, '2026-03-25 02:16:50', 22, 0, 0, 1, '[{\"uid\":22,\"nick\":\"admin5k\",\"votes\":0,\"campaign_title\":\"\"}]', '2026-03-25 03:21:36', '2026-03-25 03:21:36', '2026-03-25 03:21:36');

-- ----------------------------
-- Table structure for presidential_votes
-- ----------------------------
DROP TABLE IF EXISTS `presidential_votes`;
CREATE TABLE `presidential_votes`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `candidate_uid` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `country` int UNSIGNED NOT NULL,
  `election_key` datetime NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_presidential_votes_cycle_user`(`country` ASC, `election_key` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_presidential_votes_candidate`(`candidate_uid` ASC) USING BTREE,
  INDEX `idx_presidential_votes_country_key`(`country` ASC, `election_key` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of presidential_votes
-- ----------------------------

-- ----------------------------
-- Table structure for region_connections
-- ----------------------------
DROP TABLE IF EXISTS `region_connections`;
CREATE TABLE `region_connections`  (
  `region_a` int NOT NULL,
  `region_b` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  UNIQUE INDEX `region_a_region_b`(`region_a` ASC, `region_b` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of region_connections
-- ----------------------------

-- ----------------------------
-- Table structure for regions
-- ----------------------------
DROP TABLE IF EXISTS `regions`;
CREATE TABLE `regions`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(110) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `owner_country_id` int UNSIGNED NULL DEFAULT NULL,
  `country` int NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `country`(`country` ASC) USING BTREE,
  INDEX `idx_regions_owner`(`owner_country_id` ASC) USING BTREE,
  INDEX `idx_regions_country`(`country` ASC) USING BTREE,
  INDEX `idx_regions_name`(`name` ASC) USING BTREE,
  INDEX `idx_regions_owner_country_id`(`owner_country_id` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 15 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of regions
-- ----------------------------
INSERT INTO `regions` VALUES (3, 'Andalucia', NULL, 1);
INSERT INTO `regions` VALUES (4, 'Aragon', NULL, 1);
INSERT INTO `regions` VALUES (6, 'Balearic Islands', NULL, 1);
INSERT INTO `regions` VALUES (7, 'Cantabria', NULL, 1);
INSERT INTO `regions` VALUES (8, 'Castilla La Mancha', NULL, 1);
INSERT INTO `regions` VALUES (9, 'Catalonia', NULL, 1);
INSERT INTO `regions` VALUES (10, 'Extremadura', NULL, 1);
INSERT INTO `regions` VALUES (11, 'Madrid', NULL, 1);
INSERT INTO `regions` VALUES (12, 'Murcia', NULL, 1);
INSERT INTO `regions` VALUES (13, 'Navarra', NULL, 1);
INSERT INTO `regions` VALUES (14, 'Valencian Community', NULL, 1);

-- ----------------------------
-- Table structure for shout_likes
-- ----------------------------
DROP TABLE IF EXISTS `shout_likes`;
CREATE TABLE `shout_likes`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `shout_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_shout_likes_shout_uid`(`shout_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_shout_likes_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_shout_likes_uid_shout`(`uid` ASC, `shout_id` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 33 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of shout_likes
-- ----------------------------
INSERT INTO `shout_likes` VALUES (1, 1, 22, '2026-03-22 22:41:09', '2026-03-22 22:41:09');
INSERT INTO `shout_likes` VALUES (3, 3, 22, '2026-03-22 23:07:32', '2026-03-22 23:07:32');
INSERT INTO `shout_likes` VALUES (4, 6, 22, '2026-03-22 23:45:55', '2026-03-22 23:45:55');
INSERT INTO `shout_likes` VALUES (5, 6, 42, '2026-03-22 23:46:01', '2026-03-22 23:46:01');
INSERT INTO `shout_likes` VALUES (6, 7, 22, '2026-03-23 04:02:17', '2026-03-23 04:02:17');
INSERT INTO `shout_likes` VALUES (24, 18, 22, '2026-03-28 03:29:10', '2026-03-28 03:29:10');
INSERT INTO `shout_likes` VALUES (28, 20, 22, '2026-03-28 04:12:02', '2026-03-28 04:12:02');

-- ----------------------------
-- Table structure for shout_poll_votes
-- ----------------------------
DROP TABLE IF EXISTS `shout_poll_votes`;
CREATE TABLE `shout_poll_votes`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `shout_id` int UNSIGNED NOT NULL,
  `poll_option_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_shout_poll_votes_shout_uid`(`shout_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_shout_poll_votes_shout`(`shout_id` ASC) USING BTREE,
  INDEX `idx_shout_poll_votes_option`(`poll_option_id` ASC) USING BTREE,
  INDEX `idx_shout_poll_votes_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_shout_poll_votes_uid_shout`(`uid` ASC, `shout_id` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of shout_poll_votes
-- ----------------------------
INSERT INTO `shout_poll_votes` VALUES (1, 8, 2, 42, '2026-03-23 00:34:06', '2026-03-23 00:34:06');
INSERT INTO `shout_poll_votes` VALUES (2, 8, 2, 22, '2026-03-23 00:34:13', '2026-03-23 00:34:13');
INSERT INTO `shout_poll_votes` VALUES (3, 10, 1, 22, '2026-03-23 03:07:51', '2026-03-23 03:07:51');

-- ----------------------------
-- Table structure for shout_reports
-- ----------------------------
DROP TABLE IF EXISTS `shout_reports`;
CREATE TABLE `shout_reports`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `shout_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `reason` varchar(191) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_shout_reports_shout_uid`(`shout_id` ASC, `uid` ASC) USING BTREE,
  INDEX `idx_shout_reports_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_shout_reports_shout_created`(`shout_id` ASC, `created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of shout_reports
-- ----------------------------
INSERT INTO `shout_reports` VALUES (1, 1, 22, 'Uygunsuz icerik', '2026-03-22 23:02:25', '2026-03-22 23:02:25');
INSERT INTO `shout_reports` VALUES (2, 5, 42, 'Uygunsuz icerik', '2026-03-22 23:05:42', '2026-03-22 23:05:42');
INSERT INTO `shout_reports` VALUES (3, 8, 22, 'Uygunsuz icerik', '2026-03-23 01:56:14', '2026-03-23 01:56:14');

-- ----------------------------
-- Table structure for shout_tips
-- ----------------------------
DROP TABLE IF EXISTS `shout_tips`;
CREATE TABLE `shout_tips`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `shout_id` int UNSIGNED NOT NULL,
  `from_uid` int UNSIGNED NOT NULL,
  `to_uid` int UNSIGNED NOT NULL,
  `gold_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_shout_tips_shout`(`shout_id` ASC) USING BTREE,
  INDEX `idx_shout_tips_from_uid`(`from_uid` ASC) USING BTREE,
  INDEX `idx_shout_tips_to_uid`(`to_uid` ASC) USING BTREE,
  INDEX `idx_shout_tips_created_at`(`created_at` ASC) USING BTREE,
  INDEX `idx_shout_tips_shout_created`(`shout_id` ASC, `created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of shout_tips
-- ----------------------------
INSERT INTO `shout_tips` VALUES (1, 5, 22, 42, 1.00, '2026-03-23 00:33:22', '2026-03-23 00:33:22');
INSERT INTO `shout_tips` VALUES (2, 8, 22, 42, 1.00, '2026-03-23 01:59:04', '2026-03-23 01:59:04');

-- ----------------------------
-- Table structure for shout_user_restrictions
-- ----------------------------
DROP TABLE IF EXISTS `shout_user_restrictions`;
CREATE TABLE `shout_user_restrictions`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int UNSIGNED NOT NULL,
  `muted_until` datetime NOT NULL,
  `reason` varchar(191) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `created_by` int UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_shout_user_restrictions_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_shout_user_restrictions_muted_until`(`muted_until` ASC) USING BTREE,
  INDEX `idx_shout_user_restrictions_created_by`(`created_by` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of shout_user_restrictions
-- ----------------------------

-- ----------------------------
-- Table structure for shouts
-- ----------------------------
DROP TABLE IF EXISTS `shouts`;
CREATE TABLE `shouts`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED NULL DEFAULT NULL,
  `body` varchar(240) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `has_poll` tinyint(1) NOT NULL DEFAULT 0,
  `poll_question` varchar(160) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `poll_data` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `poll_total_votes` int UNSIGNED NOT NULL DEFAULT 0,
  `poll_duration_hours` int UNSIGNED NOT NULL DEFAULT 0,
  `poll_expires_at` datetime NULL DEFAULT NULL,
  `poll_cost_gold` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `likes_count` int UNSIGNED NOT NULL DEFAULT 0,
  `tips_gold_total` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `reports_count` int UNSIGNED NOT NULL DEFAULT 0,
  `is_state_decree` tinyint(1) NOT NULL DEFAULT 0,
  `decree_country_id` int UNSIGNED NULL DEFAULT NULL,
  `decree_expires_at` datetime NULL DEFAULT NULL,
  `decree_cost_currency` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `decree_cost_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `article_card_article_id` int UNSIGNED NULL DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NULL DEFAULT NULL,
  `edited_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_shouts_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_shouts_deleted_created`(`is_deleted` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_shouts_decree_country`(`decree_country_id` ASC, `decree_expires_at` ASC) USING BTREE,
  INDEX `idx_shouts_article_card`(`article_card_article_id` ASC) USING BTREE,
  INDEX `idx_shouts_parent_created`(`parent_id` ASC, `created_at` ASC) USING BTREE,
  INDEX `idx_shouts_feed_main`(`parent_id` ASC, `is_deleted` ASC, `id` ASC) USING BTREE,
  INDEX `idx_shouts_state_decree_active`(`is_state_decree` ASC, `decree_expires_at` ASC, `is_deleted` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 30 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of shouts
-- ----------------------------
INSERT INTO `shouts` VALUES (1, 22, NULL, 'test', 0, NULL, NULL, 0, 0, NULL, 0.00, 1, 0.00, 1, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-22 22:35:44', NULL, '2026-03-30 15:49:57');
INSERT INTO `shouts` VALUES (2, 22, NULL, 'test', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-22 23:02:44', NULL, '2026-03-22 23:02:44');
INSERT INTO `shouts` VALUES (3, 22, NULL, 'seymene tten', 0, NULL, NULL, 0, 0, NULL, 0.00, 1, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-22 23:04:27', NULL, '2026-03-22 23:07:32');
INSERT INTO `shouts` VALUES (4, 22, NULL, 'RAZE MAINIM', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-03-22 23:05:00', NULL, '2026-03-22 23:50:23');
INSERT INTO `shouts` VALUES (5, 42, NULL, 'admin best game', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 1.00, 1, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-22 23:05:31', NULL, '2026-03-23 00:33:22');
INSERT INTO `shouts` VALUES (6, 22, NULL, 'testttttttt', 0, NULL, NULL, 0, 0, NULL, 0.00, 2, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-22 23:43:26', NULL, '2026-03-22 23:46:01');
INSERT INTO `shouts` VALUES (7, 22, NULL, 'deneyelim bakalım', 0, NULL, NULL, 0, 0, NULL, 0.00, 1, 0.00, 0, 1, 1, '2026-03-24 00:33:10', 'CC', 250.00, NULL, 0, '2026-03-23 00:33:10', NULL, '2026-03-23 04:02:17');
INSERT INTO `shouts` VALUES (8, 42, NULL, 'savaş çıktımı', 1, 'savaş varmı', '[{\"id\": 1, \"option_key\": 1, \"option_text\": \"evet\", \"votes_count\": 0}, {\"id\": 2, \"option_key\": 2, \"option_text\": \"hayır\", \"votes_count\": 2}, {\"id\": 3, \"option_key\": 3, \"option_text\": \"kararsız\", \"votes_count\": 0}]', 2, 0, NULL, 0.00, 0, 1.00, 1, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-23 00:34:02', NULL, '2026-03-30 15:49:51');
INSERT INTO `shouts` VALUES (9, 22, NULL, 'yeniyyyyyyyyy', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-03-23 01:46:35', NULL, '2026-03-23 01:46:47');
INSERT INTO `shouts` VALUES (10, 22, NULL, 'RESMİ OYLAMA', 1, 'oyun nasıl', '[{\"id\":1,\"option_key\":1,\"option_text\":\"iyi\",\"votes_count\":1},{\"id\":2,\"option_key\":2,\"option_text\":\"kötü\",\"votes_count\":0}]', 1, 1, '2026-03-23 04:02:47', 1.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-23 03:02:47', NULL, '2026-03-23 03:07:51');
INSERT INTO `shouts` VALUES (11, 22, NULL, 'S-REPUBLIK', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-23 03:18:12', NULL, '2026-03-23 03:18:12');
INSERT INTO `shouts` VALUES (12, 22, NULL, 'testestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestestes', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-03-23 17:24:25', NULL, '2026-03-23 17:24:31');
INSERT INTO `shouts` VALUES (13, 22, NULL, 'güzel', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-23 17:29:25', NULL, '2026-03-28 03:23:47');
INSERT INTO `shouts` VALUES (14, 22, NULL, 'testtttttttttt', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-25 21:08:25', NULL, '2026-03-28 03:23:59');
INSERT INTO `shouts` VALUES (15, 22, NULL, 'yeniiiiiiiiiiiii', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-03-28 02:02:27', NULL, '2026-03-28 02:02:33');
INSERT INTO `shouts` VALUES (16, 22, 14, 'evet dogru diyor', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-03-28 02:45:52', NULL, '2026-03-28 02:50:28');
INSERT INTO `shouts` VALUES (17, 22, 14, 'testtttttttttttşşşşşşşşşşş', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-28 02:59:08', '2026-03-28 02:59:14', '2026-03-28 03:04:30');
INSERT INTO `shouts` VALUES (18, 22, NULL, 'İyi ekipman, oyunun en kaotik anında varlığını unutturur. SteelSeries, donanımı düşünmeyi bırakıp sadece o imkansız vuruşa odaklandığım tek alan.', 0, NULL, NULL, 0, 0, NULL, 0.00, 1, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-28 03:29:04', NULL, '2026-03-28 03:29:10');
INSERT INTO `shouts` VALUES (19, 22, NULL, 'evet yeni', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-03-28 03:40:39', NULL, '2026-03-28 03:41:16');
INSERT INTO `shouts` VALUES (20, 22, NULL, 'Sevgili vatandaşlar, birlik ve kararlılıkla ülkemizi daha güçlü yarınlara taşıyoruz. Adalet, refah, huzur için durmadan çalışıyoruz. Her birinizin katkısı kıymetlidir; birlikte başaracağız, birlikte yükseleceğiz birlikte ilerliyoruz.', 0, NULL, NULL, 0, 0, NULL, 0.00, 1, 0.00, 0, 1, 1, '2026-03-29 03:41:12', 'CC', 250.00, NULL, 0, '2026-03-28 03:41:12', NULL, '2026-03-28 04:12:02');
INSERT INTO `shouts` VALUES (21, 22, NULL, '@botplayer yat oglum', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-28 04:41:11', NULL, '2026-03-28 04:41:11');
INSERT INTO `shouts` VALUES (22, 42, 21, 'tamamdır başkanım @admin5k', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-28 04:49:53', '2026-03-28 04:50:02', '2026-03-28 04:50:02');
INSERT INTO `shouts` VALUES (23, 42, NULL, '@admin5k en büyük başkan', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-03-28 04:59:19', NULL, '2026-03-30 15:48:28');
INSERT INTO `shouts` VALUES (24, 22, NULL, 'testtrttttttttt', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-03-28 14:06:29', NULL, '2026-03-28 14:08:02');
INSERT INTO `shouts` VALUES (25, 22, NULL, 'Nasıl görünüyor?', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-04-08 06:07:20', NULL, '2026-04-08 06:07:20');
INSERT INTO `shouts` VALUES (26, 22, NULL, 'Are you ready?', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 1, 1, '2026-04-12 02:40:27', 'CC', 250.00, NULL, 0, '2026-04-11 02:40:27', NULL, '2026-04-24 22:12:17');
INSERT INTO `shouts` VALUES (27, 22, NULL, 'Ready or not… here we go.', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-04-11 02:42:08', NULL, '2026-04-11 09:18:49');
INSERT INTO `shouts` VALUES (28, 22, 27, 'testtttttttttttttttttt', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 0, '2026-04-11 09:18:57', NULL, '2026-04-11 09:18:57');
INSERT INTO `shouts` VALUES (29, 22, NULL, '@botplayer adam mısın?', 0, NULL, NULL, 0, 0, NULL, 0.00, 0, 0.00, 0, 0, NULL, NULL, NULL, 0.00, NULL, 1, '2026-04-11 09:19:24', NULL, '2026-04-11 09:24:22');

-- ----------------------------
-- Table structure for system_cron_status
-- ----------------------------
DROP TABLE IF EXISTS `system_cron_status`;
CREATE TABLE `system_cron_status`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(120) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `last_started_at` datetime NULL DEFAULT NULL,
  `last_success_at` datetime NULL DEFAULT NULL,
  `last_error_at` datetime NULL DEFAULT NULL,
  `last_error_message` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `last_meta_json` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_system_cron_status_name`(`name` ASC) USING BTREE,
  INDEX `idx_system_cron_status_last_success`(`last_success_at` ASC) USING BTREE,
  INDEX `idx_system_cron_status_last_error`(`last_error_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of system_cron_status
-- ----------------------------
INSERT INTO `system_cron_status` VALUES (1, 'deleteOldChats', '2026-03-22 18:12:56', '2026-03-22 18:12:56', NULL, NULL, '{\"deleted\":3,\"schedule\":\"daily\"}', NULL, '2026-03-22 18:12:56');
INSERT INTO `system_cron_status` VALUES (2, 'candidacyVotations', '2026-03-22 18:12:56', '2026-03-22 18:12:56', NULL, NULL, '{\"schedule\":\"daily\",\"finalized\":0,\"elected\":0}', NULL, '2026-03-22 18:12:56');
INSERT INTO `system_cron_status` VALUES (3, 'lawProposals', '2026-03-22 18:12:57', '2026-03-22 18:12:57', NULL, NULL, '{\"schedule\":\"daily\",\"finished\":0,\"applied\":0}', NULL, '2026-03-22 18:12:57');

-- ----------------------------
-- Table structure for taxes
-- ----------------------------
DROP TABLE IF EXISTS `taxes`;
CREATE TABLE `taxes`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` int NOT NULL,
  `amount` float NOT NULL,
  `country` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of taxes
-- ----------------------------

-- ----------------------------
-- Table structure for trade_embargos
-- ----------------------------
DROP TABLE IF EXISTS `trade_embargos`;
CREATE TABLE `trade_embargos`  (
  `country_id` int NOT NULL,
  `target_country_id` int NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`country_id`, `target_country_id`) USING BTREE,
  UNIQUE INDEX `uq_trade_embargo`(`country_id` ASC, `target_country_id` ASC) USING BTREE,
  UNIQUE INDEX `uq_trade_embargos_pair`(`country_id` ASC, `target_country_id` ASC) USING BTREE,
  INDEX `idx_trade_embargo_expires`(`expires_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of trade_embargos
-- ----------------------------

-- ----------------------------
-- Table structure for user_email_verifications
-- ----------------------------
DROP TABLE IF EXISTS `user_email_verifications`;
CREATE TABLE `user_email_verifications`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int UNSIGNED NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `requested_ip` varchar(45) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_user_email_verifications_token_hash`(`token_hash` ASC) USING BTREE,
  INDEX `idx_user_email_verifications_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_user_email_verifications_email`(`email` ASC) USING BTREE,
  INDEX `idx_user_email_verifications_expires_at`(`expires_at` ASC) USING BTREE,
  INDEX `idx_user_email_verifications_verified_at`(`verified_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_email_verifications
-- ----------------------------
INSERT INTO `user_email_verifications` VALUES (1, 25, 'vrf_1774114233@test.local', '815e341be48c47311889b0bb1753b990e2fe077bdcd9c262212522db6128077d', '127.0.0.1', '2026-03-22 15:30:34', '2026-03-21 15:30:34', '2026-03-21 15:30:34', '2026-03-21 15:30:34');
INSERT INTO `user_email_verifications` VALUES (2, 25, 'vrf_1774114233@test.local', '78979d8451a8e88dc932185e7fa54592d17d1e27ec2bbcc3cda867a9375b58b0', '127.0.0.1', '2026-03-22 15:30:34', '2026-03-21 15:30:34', '2026-03-21 15:30:34', '2026-03-21 15:30:34');
INSERT INTO `user_email_verifications` VALUES (3, 26, 'vrf2_1774114298@test.local', '8455fbafe52de15391027a76b310c5f77931da5a5dca9c123f0bd09e1882ca3c', '127.0.0.1', '2026-03-22 15:31:38', '2026-03-21 15:31:38', '2026-03-21 15:31:38', '2026-03-21 15:31:38');
INSERT INTO `user_email_verifications` VALUES (4, 26, 'vrf2_1774114298@test.local', 'd2fe41041ce459e94221572ea0f7f3ed852299eddc86f0b8f37f83e26597d4a5', '127.0.0.1', '2026-03-22 15:31:38', '2026-03-21 15:31:38', '2026-03-21 15:31:38', '2026-03-21 15:31:38');
INSERT INTO `user_email_verifications` VALUES (5, 27, 'testhesap@gmail.com', '3197442f0215c56f9253319e5f7d549e892b164b5d5bab2507dfe4b272ca0925', '::1', '2026-03-22 14:34:02', NULL, '2026-03-21 14:34:02', '2026-03-21 14:34:02');

-- ----------------------------
-- Table structure for user_gyms
-- ----------------------------
DROP TABLE IF EXISTS `user_gyms`;
CREATE TABLE `user_gyms`  (
  `uid` int NOT NULL,
  `q1` datetime NULL DEFAULT NULL,
  `q2` datetime NULL DEFAULT NULL,
  `q3` datetime NULL DEFAULT NULL,
  `q4` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  UNIQUE INDEX `uniq_user_gyms_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_gyms
-- ----------------------------
INSERT INTO `user_gyms` VALUES (18, '2016-08-28 00:00:00', '2016-08-18 00:00:00', NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (19, '2026-03-04 00:00:00', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (22, '2026-04-05 00:00:00', NULL, NULL, '2026-04-24 00:00:00', NULL, NULL);
INSERT INTO `user_gyms` VALUES (24, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (25, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (26, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (27, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (29, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (32, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (33, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (34, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (35, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (36, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (37, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (42, '2026-03-22 00:00:00', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (43, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `user_gyms` VALUES (44, NULL, NULL, NULL, NULL, NULL, NULL);

-- ----------------------------
-- Table structure for user_items
-- ----------------------------
DROP TABLE IF EXISTS `user_items`;
CREATE TABLE `user_items`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `item` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `quality` int NOT NULL DEFAULT 0,
  `quantity` float NOT NULL DEFAULT 0,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uid_item_quality`(`uid` ASC, `item` ASC, `quality` ASC) USING BTREE,
  UNIQUE INDEX `uniq_user_item_quality`(`uid` ASC, `item` ASC, `quality` ASC) USING BTREE,
  UNIQUE INDEX `ux_user_items_uid_item_quality`(`uid` ASC, `item` ASC, `quality` ASC) USING BTREE,
  UNIQUE INDEX `uq_user_items_uid_item_quality`(`uid` ASC, `item` ASC, `quality` ASC) USING BTREE,
  UNIQUE INDEX `uniq_user_items_uid_item_quality`(`uid` ASC, `item` ASC, `quality` ASC) USING BTREE,
  INDEX `uid`(`uid` ASC) USING BTREE,
  INDEX `idx_user_items_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_user_items_item`(`item` ASC) USING BTREE,
  INDEX `idx_user_items_item_quality`(`item` ASC, `quality` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 14 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_items
-- ----------------------------
INSERT INTO `user_items` VALUES (1, 18, '1', 0, 1023, NULL, NULL);
INSERT INTO `user_items` VALUES (2, 18, '4', 1, 479, NULL, NULL);
INSERT INTO `user_items` VALUES (3, 18, '1', 2, 70, NULL, NULL);
INSERT INTO `user_items` VALUES (4, 18, '1', 1, 30, NULL, NULL);
INSERT INTO `user_items` VALUES (5, 18, '3', 1, 30, NULL, NULL);
INSERT INTO `user_items` VALUES (6, 18, '3', 0, 30, NULL, NULL);
INSERT INTO `user_items` VALUES (7, 22, '1', 5, 749, NULL, NULL);
INSERT INTO `user_items` VALUES (8, 22, '4', 1, 5, NULL, NULL);
INSERT INTO `user_items` VALUES (12, 22, '1', 0, 1, NULL, NULL);
INSERT INTO `user_items` VALUES (13, 22, '2', 3, 10, NULL, NULL);

-- ----------------------------
-- Table structure for user_locations
-- ----------------------------
DROP TABLE IF EXISTS `user_locations`;
CREATE TABLE `user_locations`  (
  `uid` int UNSIGNED NOT NULL,
  `country_id` int UNSIGNED NOT NULL,
  `region_id` int UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`uid`) USING BTREE,
  INDEX `idx_user_locations_country`(`country_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_locations
-- ----------------------------

-- ----------------------------
-- Table structure for user_money
-- ----------------------------
DROP TABLE IF EXISTS `user_money`;
CREATE TABLE `user_money`  (
  `uid` int NOT NULL,
  `gold` decimal(11, 2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `esp` decimal(11, 2) NOT NULL DEFAULT 0.00,
  UNIQUE INDEX `uid`(`uid` ASC) USING BTREE,
  UNIQUE INDEX `uq_user_money_uid`(`uid` ASC) USING BTREE,
  UNIQUE INDEX `uniq_user_money_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_money
-- ----------------------------
INSERT INTO `user_money` VALUES (18, 60.00, NULL, NULL, 4801.10);
INSERT INTO `user_money` VALUES (19, 950.00, NULL, NULL, 100.00);
INSERT INTO `user_money` VALUES (22, 761.42, NULL, NULL, 6068.90);
INSERT INTO `user_money` VALUES (24, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (25, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (26, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (27, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (29, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (32, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (33, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (34, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (35, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (36, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (37, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (42, 2.00, NULL, NULL, 30.00);
INSERT INTO `user_money` VALUES (43, 0.00, NULL, NULL, 0.00);
INSERT INTO `user_money` VALUES (44, 0.00, NULL, NULL, 0.00);

-- ----------------------------
-- Table structure for user_password_resets
-- ----------------------------
DROP TABLE IF EXISTS `user_password_resets`;
CREATE TABLE `user_password_resets`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int UNSIGNED NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `requested_ip` varchar(45) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_user_password_resets_token_hash`(`token_hash` ASC) USING BTREE,
  INDEX `idx_user_password_resets_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_user_password_resets_email`(`email` ASC) USING BTREE,
  INDEX `idx_user_password_resets_expires_at`(`expires_at` ASC) USING BTREE,
  INDEX `idx_user_password_resets_used_at`(`used_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_password_resets
-- ----------------------------
INSERT INTO `user_password_resets` VALUES (1, 22, 'admin5k@gmail.com', 'd1092fb624c16b0d04348908c35a1887c2b3cf83900e5fd06de3150ee8105787', '::1', '2026-04-25 00:20:05', '2026-04-24 22:21:05', '2026-04-24 22:20:05', '2026-04-24 22:21:05');
INSERT INTO `user_password_resets` VALUES (2, 22, 'admin5k@gmail.com', '9685a1435717a3357feb1b0bda901e59225aa0d6157c4c429060fbd5df5fa9d2', '::1', '2026-04-25 00:21:05', '2026-04-24 22:21:22', '2026-04-24 22:21:05', '2026-04-24 22:21:22');
INSERT INTO `user_password_resets` VALUES (3, 22, 'admin5k@gmail.com', '8195186e8509d267e2be99a33cd75afb4606f7dbe57b41ff77edbd7230f571a9', '::1', '2026-04-25 00:21:22', '2026-04-24 22:21:22', '2026-04-24 22:21:22', '2026-04-24 22:21:22');
INSERT INTO `user_password_resets` VALUES (4, 22, 'admin5k@gmail.com', '0a93a00be3929c7a9210e704d8e3d5ee60ca2a3d99baabf72dfe7f42367e0ed3', '::1', '2026-04-25 00:21:22', '2026-04-24 22:21:22', '2026-04-24 22:21:22', '2026-04-24 22:21:22');

-- ----------------------------
-- Table structure for user_shout_limits
-- ----------------------------
DROP TABLE IF EXISTS `user_shout_limits`;
CREATE TABLE `user_shout_limits`  (
  `uid` int UNSIGNED NOT NULL,
  `minute_window_started_at` datetime NULL DEFAULT NULL,
  `minute_count` int UNSIGNED NOT NULL DEFAULT 0,
  `burst_window_started_at` datetime NULL DEFAULT NULL,
  `burst_count` int UNSIGNED NOT NULL DEFAULT 0,
  `day_window_started_at` datetime NULL DEFAULT NULL,
  `daily_count` int UNSIGNED NOT NULL DEFAULT 0,
  `last_shout_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`uid`) USING BTREE,
  INDEX `idx_user_shout_limits_last_shout`(`last_shout_at` ASC) USING BTREE,
  INDEX `idx_user_shout_limits_updated`(`updated_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb3 COLLATE = utf8mb3_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_shout_limits
-- ----------------------------
INSERT INTO `user_shout_limits` VALUES (22, '2026-04-11 09:18:57', 2, '2026-04-11 09:18:57', 2, '2026-04-11 00:00:00', 4, '2026-04-11 09:19:24', '2026-04-11 09:19:24');
INSERT INTO `user_shout_limits` VALUES (42, '2026-03-28 04:59:19', 1, '2026-03-28 04:59:19', 1, '2026-03-28 00:00:00', 2, '2026-03-28 04:59:19', '2026-03-28 04:59:19');

-- ----------------------------
-- Table structure for user_trainings
-- ----------------------------
DROP TABLE IF EXISTS `user_trainings`;
CREATE TABLE `user_trainings`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` bigint UNSIGNED NOT NULL,
  `quality` tinyint UNSIGNED NOT NULL,
  `strength_gained` int NOT NULL,
  `created_at` datetime NOT NULL,
  `training_day` date GENERATED ALWAYS AS (cast(`created_at` as date)) STORED NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_training_day`(`uid` ASC, `quality` ASC, `training_day` ASC) USING BTREE,
  INDEX `idx_user_trainings_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_user_trainings_quality`(`quality` ASC) USING BTREE,
  INDEX `idx_user_trainings_created_at`(`created_at` ASC) USING BTREE,
  INDEX `idx_user_trainings_uid_quality`(`uid` ASC, `quality` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 14 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_trainings
-- ----------------------------
INSERT INTO `user_trainings` VALUES (1, 19, 1, 5, '2026-03-04 11:58:08', DEFAULT);
INSERT INTO `user_trainings` VALUES (2, 22, 1, 5, '2026-03-21 18:16:43', DEFAULT);
INSERT INTO `user_trainings` VALUES (3, 22, 1, 5, '2026-03-22 15:32:10', DEFAULT);
INSERT INTO `user_trainings` VALUES (4, 42, 1, 5, '2026-03-22 15:35:58', DEFAULT);
INSERT INTO `user_trainings` VALUES (5, 22, 1, 5, '2026-03-23 00:09:11', DEFAULT);
INSERT INTO `user_trainings` VALUES (6, 22, 1, 5, '2026-03-24 00:01:14', DEFAULT);
INSERT INTO `user_trainings` VALUES (7, 22, 1, 5, '2026-03-25 01:00:39', DEFAULT);
INSERT INTO `user_trainings` VALUES (8, 22, 1, 5, '2026-03-28 01:49:36', DEFAULT);
INSERT INTO `user_trainings` VALUES (9, 22, 1, 5, '2026-03-30 15:44:59', DEFAULT);
INSERT INTO `user_trainings` VALUES (10, 22, 1, 5, '2026-04-01 01:23:20', DEFAULT);
INSERT INTO `user_trainings` VALUES (11, 22, 1, 5, '2026-04-05 21:28:21', DEFAULT);
INSERT INTO `user_trainings` VALUES (12, 22, 4, 20, '2026-04-11 08:30:42', DEFAULT);
INSERT INTO `user_trainings` VALUES (13, 22, 4, 20, '2026-04-24 22:20:59', DEFAULT);

-- ----------------------------
-- Table structure for user_weapons
-- ----------------------------
DROP TABLE IF EXISTS `user_weapons`;
CREATE TABLE `user_weapons`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int UNSIGNED NOT NULL,
  `quality` tinyint NOT NULL,
  `quantity` int NOT NULL DEFAULT 0,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_user_weapon`(`uid` ASC, `quality` ASC) USING BTREE,
  UNIQUE INDEX `ux_user_weapons_uid_quality`(`uid` ASC, `quality` ASC) USING BTREE,
  UNIQUE INDEX `uniq_user_weapons_uid_quality`(`uid` ASC, `quality` ASC) USING BTREE,
  INDEX `idx_user_weapons_uid`(`uid` ASC) USING BTREE,
  INDEX `idx_user_weapons_uid_qty`(`uid` ASC, `quantity` ASC) USING BTREE,
  INDEX `idx_user_weapons_quantity`(`quantity` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of user_weapons
-- ----------------------------

-- ----------------------------
-- Table structure for users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `country_id` int UNSIGNED NULL DEFAULT NULL,
  `nick` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `email` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `avatar` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `bio` varchar(280) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `theme` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'dark_cyan',
  `language` varchar(8) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'tr',
  `password` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `status` int NOT NULL DEFAULT 0,
  `email_verified_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `level` int NOT NULL DEFAULT 1,
  `xp` int NOT NULL DEFAULT 0,
  `strength` int NOT NULL DEFAULT 1,
  `region` int NOT NULL,
  `referrer` int NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `gold` decimal(11, 2) NOT NULL DEFAULT 0.00,
  `energy` int UNSIGNED NOT NULL DEFAULT 100,
  `health` int NOT NULL DEFAULT 0,
  `stamina` int NOT NULL DEFAULT 0,
  `pity_count` int NOT NULL DEFAULT 0,
  `scrap_total` int NOT NULL DEFAULT 0,
  `upgrade_fail_count` int NOT NULL DEFAULT 0,
  `economic_skill` int NOT NULL DEFAULT 1,
  `economic_xp` int NOT NULL DEFAULT 0,
  `media_reputation` int NOT NULL DEFAULT 0,
  `last_daily_work_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `email`(`email` ASC) USING BTREE,
  UNIQUE INDEX `nick`(`nick` ASC) USING BTREE,
  UNIQUE INDEX `ux_users_email`(`email` ASC) USING BTREE,
  UNIQUE INDEX `uniq_users_email`(`email` ASC) USING BTREE,
  UNIQUE INDEX `uniq_users_nick`(`nick` ASC) USING BTREE,
  INDEX `region`(`region` ASC) USING BTREE,
  INDEX `referrer`(`referrer` ASC) USING BTREE,
  INDEX `idx_users_status`(`status` ASC) USING BTREE,
  INDEX `idx_users_referrer`(`referrer` ASC) USING BTREE,
  INDEX `idx_users_email`(`email` ASC) USING BTREE,
  INDEX `idx_users_region`(`region` ASC) USING BTREE,
  INDEX `idx_users_country_id`(`country_id` ASC) USING BTREE,
  INDEX `idx_users_theme`(`theme` ASC) USING BTREE,
  INDEX `idx_users_last_daily_work_at`(`last_daily_work_at` ASC) USING BTREE,
  INDEX `idx_users_economic_skill`(`economic_skill` ASC) USING BTREE,
  INDEX `idx_users_nick`(`nick` ASC) USING BTREE,
  INDEX `idx_users_email_verified_at`(`email_verified_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 45 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of users
-- ----------------------------
INSERT INTO `users` VALUES (18, NULL, 'admin', 'admin@yopmail.com', NULL, NULL, 'dark_cyan', 'tr', 'd63be78cdd3bcfe1df97b5b3acbc752d', 1, NULL, '2016-08-07 18:34:41', 1, 0, 31, 3, NULL, '2016-08-28 14:14:29', 0, 0.00, 100, 0, 0, 0, 0, 0, 1, 0, 0, NULL);
INSERT INTO `users` VALUES (19, 1, 'sakaryacanavari', 'ararmetehan25@gmail.com', NULL, NULL, 'dark_cyan', 'tr', '000302db7d60e893a6eb78ef77e83de7', 1, NULL, '2026-03-04 11:28:17', 10, 10, 1005, 3, NULL, '2026-03-04 11:28:17', 0, 0.00, 90, 0, 0, 0, 0, 0, 1, 0, 0, NULL);
INSERT INTO `users` VALUES (22, NULL, 'admin5k', 'admin5k@gmail.com', 'https://i.hizliresim.com/9v1utot.png', '', 'dark_amber', 'tr', '$argon2id$v=19$m=65536,t=4,p=1$cDNFem5KTVF1R3VkRVQzTg$9quv1O6kyghxV86dZXiOnq6uNm1zAo9M1zbXJbvH7mQ', 1, NULL, '2026-03-21 13:32:46', 3, 3, 86, 3, NULL, '2026-04-24 22:21:22', 1, 0.00, 60, 10, 0, 4, 0, 0, 1, 2, 6, '2026-03-23 03:44:27');
INSERT INTO `users` VALUES (29, 1, 'testhesap', 'testhesap@gmail.com', NULL, NULL, 'dark_cyan', 'tr', '$argon2id$v=19$m=65536,t=4,p=1$NjFzRFdlcW14UUY0RklGSw$perJ63WPEdqI8Wxm+f3Mn2pAhDDuVRIxjkRz/N8LKSc', 1, '2026-03-21 14:38:04', '2026-03-21 14:38:04', 1, 0, 1, 3, NULL, '2026-03-21 14:38:04', 0, 0.00, 100, 0, 0, 0, 0, 0, 1, 0, 0, NULL);
INSERT INTO `users` VALUES (42, 1, 'botplayer', 'botplayer@gmail.com', 'https://i.hizliresim.com/dj7duax.png', NULL, 'dark_cyan', 'tr', '$argon2id$v=19$m=65536,t=4,p=1$TlFpMnUxcXd0Z0IxOU1UMQ$FnQqmrLkcbkUohto7R/b5sKsWbpJUYPQtCYTq6Nf+xA', 1, NULL, '2026-03-21 17:52:41', 5, 5, 100, 3, NULL, '2026-03-22 17:30:04', 0, 0.00, 90, 0, 0, 0, 0, 0, 1, 1, -6, '2026-03-22 17:30:04');
INSERT INTO `users` VALUES (43, 1, 'testhesapx', 'testhesapx@gmail.com', NULL, NULL, 'dark_cyan', 'tr', '$argon2id$v=19$m=65536,t=4,p=1$N1IwaTd5b1FBS0ZXS2cwdQ$L6GGHKulYCsRdBk7Khxwe4U/O8l+mzMErguePs30Tt8', 1, NULL, '2026-03-25 00:10:12', 1, 0, 1, 3, NULL, '2026-03-25 00:10:12', 0, 0.00, 100, 0, 0, 0, 0, 0, 1, 0, 0, NULL);
INSERT INTO `users` VALUES (44, 1, 'onlyraze', 'onlyraze@gmail.com', NULL, NULL, 'dark_cyan', 'tr', '$argon2id$v=19$m=65536,t=4,p=1$N0V3UjhtUlNMWndKa2V5Qw$MyCggefh+v1vhKcmCqfJjfvV8pSjji6HXQwlnl0qhYM', 1, NULL, '2026-04-24 22:07:25', 1, 0, 1, 3, NULL, '2026-04-24 22:07:25', 0, 0.00, 100, 0, 0, 0, 0, 0, 1, 0, 0, NULL);

-- ----------------------------
-- Table structure for war_damages
-- ----------------------------
DROP TABLE IF EXISTS `war_damages`;
CREATE TABLE `war_damages`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `war_id` int UNSIGNED NOT NULL,
  `uid` int UNSIGNED NOT NULL,
  `side` enum('attacker','defender') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `damage` bigint UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_war_user_side`(`war_id` ASC, `uid` ASC, `side` ASC) USING BTREE,
  UNIQUE INDEX `ux_war_damages_war_uid_side`(`war_id` ASC, `uid` ASC, `side` ASC) USING BTREE,
  UNIQUE INDEX `uniq_war_damages_war_uid_side`(`war_id` ASC, `uid` ASC, `side` ASC) USING BTREE,
  INDEX `idx_war_damages_war`(`war_id` ASC) USING BTREE,
  INDEX `idx_war_damages_war_side_damage`(`war_id` ASC, `side` ASC, `damage` ASC) USING BTREE,
  INDEX `idx_war_damages_uid`(`uid` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of war_damages
-- ----------------------------

-- ----------------------------
-- Table structure for wars
-- ----------------------------
DROP TABLE IF EXISTS `wars`;
CREATE TABLE `wars`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `attacker_id` int NOT NULL,
  `defender_id` int NOT NULL,
  `target_region_id` int NULL DEFAULT NULL,
  `attacker_damage` bigint NULL DEFAULT 0,
  `defender_damage` bigint NULL DEFAULT 0,
  `atk_rounds` int UNSIGNED NOT NULL DEFAULT 0,
  `def_rounds` int UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'active',
  `ends_at` datetime NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_wars_status_ends`(`status` ASC, `ends_at` ASC) USING BTREE,
  INDEX `idx_wars_target_region`(`target_region_id` ASC) USING BTREE,
  INDEX `idx_wars_attacker`(`attacker_id` ASC) USING BTREE,
  INDEX `idx_wars_defender`(`defender_id` ASC) USING BTREE,
  INDEX `idx_wars_status_ends_at`(`status` ASC, `ends_at` ASC) USING BTREE,
  INDEX `idx_wars_attacker_id`(`attacker_id` ASC) USING BTREE,
  INDEX `idx_wars_defender_id`(`defender_id` ASC) USING BTREE,
  INDEX `idx_wars_target_region_id`(`target_region_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of wars
-- ----------------------------

-- ----------------------------
-- Table structure for work_offers
-- ----------------------------
DROP TABLE IF EXISTS `work_offers`;
CREATE TABLE `work_offers`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `company` int NOT NULL,
  `salary` decimal(10, 2) NOT NULL DEFAULT 1000.00,
  `currency` varchar(8) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `country` int NOT NULL,
  `required_skill` int NOT NULL DEFAULT 0,
  `employer_uid` int UNSIGNED NULL DEFAULT NULL,
  `worker` int NULL DEFAULT NULL,
  `title` varchar(160) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `description` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `last_work` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `worker`(`worker` ASC) USING BTREE,
  INDEX `idx_work_offers_country_worker`(`country` ASC, `worker` ASC) USING BTREE,
  INDEX `idx_work_offers_country_id`(`country` ASC, `id` ASC) USING BTREE,
  INDEX `idx_work_offers_company`(`company` ASC) USING BTREE,
  INDEX `idx_work_offers_worker`(`worker` ASC) USING BTREE,
  INDEX `idx_work_offers_country`(`country` ASC) USING BTREE,
  INDEX `idx_work_offers_currency`(`currency` ASC) USING BTREE,
  INDEX `idx_work_offers_company_worker`(`company` ASC, `worker` ASC) USING BTREE,
  INDEX `idx_work_offers_required_skill`(`required_skill` ASC) USING BTREE,
  INDEX `idx_work_offers_last_work`(`last_work` ASC) USING BTREE,
  INDEX `idx_work_offers_salary`(`salary` ASC) USING BTREE,
  INDEX `idx_work_offers_created_at`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of work_offers
-- ----------------------------
INSERT INTO `work_offers` VALUES (1, 5, 15.00, 'ESP', 1, 1, NULL, 22, NULL, NULL, '2026-03-23 03:44:27', '2026-03-21 13:44:41', '2026-03-23 03:44:27');
INSERT INTO `work_offers` VALUES (2, 5, 30.00, 'ESP', 1, 1, NULL, 42, NULL, NULL, '2026-03-22 17:30:04', '2026-03-22 15:40:10', '2026-03-22 17:30:04');

SET FOREIGN_KEY_CHECKS = 1;
