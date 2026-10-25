-- =====================================================================
--  GSMaRT Construction System - master (platform) database
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+
--
--  One master database serves every company. It holds the companies,
--  the database each company uses, their Company Codes, which company
--  every username belongs to, the Super Admin accounts and the
--  platform's own audit log. Company records (transactions, lists,
--  users and their roles) stay in each company's own database.
--
--  The system creates this database and runs this file itself when the
--  first company is registered (System Administration > Multi-Company
--  Setup). Every statement can be run again safely.
-- =====================================================================

SET NAMES utf8mb4;

-- Super Admin accounts: they manage the platform and every company, and belong to no company
CREATE TABLE IF NOT EXISTS super_admins (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_super_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The companies. A company is usable only while its status is 'active'.
CREATE TABLE IF NOT EXISTS companies (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(150) NOT NULL,
  address             VARCHAR(255) NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(60)  NULL,
  email               VARCHAR(120) NULL,
  tin                 VARCHAR(40)  NULL,
  status              ENUM('provisioning','active','failed','inactive') NOT NULL DEFAULT 'provisioning',
  owner_user_id       INT UNSIGNED NULL,               -- the Company Owner: a user id inside the company database
  require_approval    TINYINT(1)   NOT NULL DEFAULT 1, -- employees who join with the Company Code wait for approval
  join_role_id        INT UNSIGNED NULL,               -- role suggested for (or, without approval, given to) employees who join
  template_company_id INT UNSIGNED NULL,               -- the company whose set-up was copied
  notes               VARCHAR(255) NULL,
  last_error          TEXT         NULL,               -- why the last set-up attempt failed
  created_by          INT UNSIGNED NULL,               -- super_admins.id
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at        DATETIME     NULL,
  deactivated_at      DATETIME     NULL,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_companies_name (name),
  KEY ix_companies_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company -> database. The database name is made by the system and never typed by a user.
CREATE TABLE IF NOT EXISTS company_databases (
  company_id          INT UNSIGNED NOT NULL PRIMARY KEY,
  db_name             VARCHAR(64)  NOT NULL,
  is_primary          TINYINT(1)   NOT NULL DEFAULT 0, -- the original database (Company 1): never created or dropped by the platform
  created_by_platform TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = the platform created it (and may drop it if its set-up fails)
  table_count         INT          NULL,
  triggers_ok         TINYINT(1)   NOT NULL DEFAULT 0, -- audit trail protection triggers are in place
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at         DATETIME     NULL,
  UNIQUE KEY uq_company_databases_name (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company Codes: an employee types one to say which company they are joining. It is never a password.
-- Only one code of a company is 'active'; a regenerated code retires the old one for good.
CREATE TABLE IF NOT EXISTS company_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id  INT UNSIGNED NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  status      ENUM('active','disabled','retired') NOT NULL DEFAULT 'active',
  expires_at  DATETIME     NULL,
  created_by  VARCHAR(80)  NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at  DATETIME     NULL,
  UNIQUE KEY uq_company_codes_code (code),
  KEY ix_company_codes_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which company every username belongs to (the log-in page reads it). Usernames are unique across companies.
-- The account itself (password, roles, lock-out) stays in the company database.
CREATE TABLE IF NOT EXISTS user_company_memberships (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id      INT UNSIGNED NOT NULL,
  company_user_id INT UNSIGNED NOT NULL,
  username        VARCHAR(50)  NOT NULL,
  full_name       VARCHAR(120) NULL,
  email           VARCHAR(120) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',  -- active, inactive, locked, pending, rejected
  created_via     VARCHAR(12)  NOT NULL DEFAULT 'admin',   -- admin, registration, provisioning, setup (never 'link': see user_company_links)
  joined_at       DATETIME     NULL,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_memberships_username (username),
  UNIQUE KEY uq_memberships_company_user (company_id, company_user_id),
  KEY ix_memberships_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access to another company: a user of one company (home) who may also work in another company with the same
-- log-in, switching between them from the top bar. The other company holds a linked account (users.created_via =
-- 'link') with its own roles; it never logs in by itself. Made by that company's administrator (User Accounts).
CREATE TABLE IF NOT EXISTS user_company_links (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  home_company_id INT UNSIGNED NOT NULL,
  home_user_id    INT UNSIGNED NOT NULL,
  username        VARCHAR(50)  NOT NULL,
  company_id      INT UNSIGNED NOT NULL,              -- the company the user may switch to
  company_user_id INT UNSIGNED NOT NULL,              -- the linked account in that company's database
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',  -- active, removed
  granted_by      VARCHAR(80)  NULL,
  granted_at      DATETIME     NULL,
  removed_by      VARCHAR(80)  NULL,
  removed_at      DATETIME     NULL,
  removed_reason  VARCHAR(255) NULL,
  last_switch_at  DATETIME     NULL,
  UNIQUE KEY uq_links_home_company (home_company_id, home_user_id, company_id),
  UNIQUE KEY uq_links_company_user (company_id, company_user_id),
  KEY ix_links_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every step of setting up a company database, for the Super Admin to follow and to recover from
CREATE TABLE IF NOT EXISTS company_provisioning_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  attempt    INT          NOT NULL DEFAULT 1,
  step       VARCHAR(60)  NOT NULL,
  ok         TINYINT(1)   NOT NULL DEFAULT 1,
  message    TEXT         NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_provisioning_company (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The platform's own audit log: companies created, changed, switched on and off, owners, codes,
-- Super Admin log-ins and every time a Super Admin opened a company
CREATE TABLE IF NOT EXISTS platform_audit (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_type  VARCHAR(12)  NOT NULL,              -- super_admin, owner, user, guest, system
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(80)  NULL,
  company_id  INT UNSIGNED NULL,
  action      VARCHAR(40)  NOT NULL,
  details     TEXT         NULL,
  ip_address  VARCHAR(45)  NULL,
  KEY ix_platform_audit_company (company_id, id),
  KEY ix_platform_audit_action (action, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform-wide settings (database name prefix for new companies, default approval rule, ...)
CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key   VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attempts on the join page, so Company Codes cannot be guessed by trying many of them
CREATE TABLE IF NOT EXISTS registration_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_address  VARCHAR(45)  NOT NULL,
  ok          TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_registration_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
