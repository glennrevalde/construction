-- =====================================================================
--  GSMaRT Construction System - clean database (set-up lists and user accounts, no sample data)
--  Generated 2026-10-23 20:35:40 +08:00
--  Company database `gsmart_construction`: 139 tables, 2954 rows (0 projects, 0 accounting transactions)
--  Platform database `gsmart_construction_master`: Super Admin, companies and Company Codes
--
--  Import:  phpMyAdmin > Import > choose this file > Go   (no database needs to be selected)
--      or:  mysql -u root -p < gsmart_construction_clean.sql
--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.
--  Import as root (or a user with CREATE, DROP, INSERT, REFERENCES and TRIGGER privileges).
--  WARNING: importing drops and recreates every table in the databases below.
--
--  Logins: see README.md (superadmin / Super@12345, admin / Admin@12345, other users Demo@12345)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';

CREATE DATABASE IF NOT EXISTS `gsmart_construction` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gsmart_construction`;

-- ---------------------------------------------------------------------
--  1. COMPANY DATABASE: TABLES
-- ---------------------------------------------------------------------
-- =====================================================================
--  GSMaRT Construction System - database schema (one company)
--  The accounting tables come first; the construction tables (cs_*) follow.
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================


-- ---------------------------------------------------------------------
--  SYSTEM ADMINISTRATION AND SECURITY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  setting_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
  setting_value TEXT         NULL,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  employee_no          VARCHAR(30)  NULL,
  pos_default_site_id  INT UNSIGNED NULL,                 -- the site this user's POS Register opens on
  pos_default_class_id INT UNSIGNED NULL,                 -- the class every new sale of theirs starts with
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  approval_status      VARCHAR(10)  NOT NULL DEFAULT 'approved',  -- approved / pending / rejected (joined with the Company Code)
  created_via          VARCHAR(12)  NOT NULL DEFAULT 'admin',     -- admin / registration / provisioning / setup / platform
  approved_by          INT UNSIGNED NULL,                         -- who approved or rejected the registration
  approved_at          DATETIME     NULL,
  rejected_reason      VARCHAR(255) NULL,
  is_platform          TINYINT(1)   NOT NULL DEFAULT 0,           -- the account a Super Admin works through inside the company
  portal_customer_id   INT UNSIGNED NULL,                         -- client portal: the client this user represents
  portal_vendor_id     INT UNSIGNED NULL,                         -- subcontractor portal: the subcontractor this user represents
  all_projects         TINYINT(1)   NOT NULL DEFAULT 0,           -- sees every project, not only the ones they are a member of
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_approval (approval_status),
  KEY ix_users_pos_site (pos_default_site_id),
  KEY ix_users_pos_class (pos_default_class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  description VARCHAR(255) NULL,
  is_admin    TINYINT(1)   NOT NULL DEFAULT 0,   -- full access to everything
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,   -- standard role shipped with the system
  all_sites   TINYINT(1)   NOT NULL DEFAULT 1,   -- 0 = limited to role_sites
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_roles;
CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS modules;
CREATE TABLE modules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  icon       VARCHAR(40)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_modules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pages;
CREATE TABLE pages (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_key     VARCHAR(60)  NOT NULL,
  module_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  page_type    ENUM('center','transaction','list','report','setup','admin') NOT NULL DEFAULT 'transaction',
  menu_group   VARCHAR(40)  NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  show_in_menu TINYINT(1)   NOT NULL DEFAULT 1,
  description  VARCHAR(255) NULL,
  UNIQUE KEY uq_pages_key (page_key),
  CONSTRAINT fk_pages_module FOREIGN KEY (module_id) REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page access + action permissions per role (View = allowed)
DROP TABLE IF EXISTS role_page_access;
CREATE TABLE role_page_access (
  role_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, page_id),
  CONSTRAINT fk_rpa_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rpa_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-level overrides always take precedence over roles
DROP TABLE IF EXISTS user_page_overrides;
CREATE TABLE user_page_overrides (
  user_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, page_id),
  CONSTRAINT fk_upo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sensitive permissions set separately from the grid
--  view_costs, view_bank_balances, credit_limit_override, coa_changes,
--  closed_period_changes (0/1); max_discount_pct, max_price_override_pct,
--  max_amount (number, blank = unlimited)
DROP TABLE IF EXISTS role_special_permissions;
CREATE TABLE role_special_permissions (
  role_id    INT UNSIGNED NOT NULL,
  perm_key   VARCHAR(40)  NOT NULL,
  perm_value VARCHAR(40)  NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rsp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent, read-only audit trail (see triggers at the end of the file)
DROP TABLE IF EXISTS audit_trail;
CREATE TABLE audit_trail (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(50)  NULL,
  user_roles  VARCHAR(255) NULL,
  ip_address  VARCHAR(45)  NULL,
  workstation VARCHAR(255) NULL,
  site_id     INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  module      VARCHAR(20)  NOT NULL,
  page_key    VARCHAR(60)  NULL,
  action      VARCHAR(30)  NOT NULL,
  record_type VARCHAR(40)  NULL,
  record_id   BIGINT       NULL,
  record_ref  VARCHAR(160) NULL,
  amount      DECIMAL(15,2) NULL,
  changes     LONGTEXT     NULL,   -- JSON [{field, old, new}]
  snapshot    LONGTEXT     NULL,   -- JSON copy of the record
  reason      VARCHAR(255) NULL,
  approved_by VARCHAR(50)  NULL,
  KEY ix_audit_date (created_at),
  KEY ix_audit_user (user_id, created_at),
  KEY ix_audit_record (record_type, record_id),
  KEY ix_audit_action (action),
  KEY ix_audit_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS memorized_reports;
CREATE TABLE memorized_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  group_name  VARCHAR(60)  NULL,
  report_key  VARCHAR(60)  NOT NULL,
  query_string TEXT        NULL,
  is_shared   TINYINT(1)   NOT NULL DEFAULT 0,
  schedule    VARCHAR(10)  NULL,          -- daily, weekly, monthly (tools/send_scheduled_reports.php)
  email_to    VARCHAR(255) NULL,
  last_sent_at DATETIME    NULL,
  created_by  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mr_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sequences;
CREATE TABLE sequences (
  seq_key VARCHAR(30) NOT NULL PRIMARY KEY,
  prefix  VARCHAR(10) NOT NULL DEFAULT '',
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  pad     TINYINT      NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CHART OF ACCOUNTS AND LISTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS accounts;
CREATE TABLE accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  account_type    ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                       'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                       'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  category        ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
  normal_balance  ENUM('Debit','Credit') NOT NULL,
  parent_id       INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  bank_account_no VARCHAR(40)  NULL,
  with_sl         TINYINT(1)   NOT NULL DEFAULT 0,    -- keeps a subsidiary ledger: every entry needs a name
  cs_cost_category VARCHAR(20) NULL,                  -- construction cost category of an expense account (material, labor, equipment, subcontract, other, overhead)
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_system       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_accounts_code (code),
  KEY ix_acc_with_sl (with_sl),
  CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module default accounts (ar, ap, undeposited_funds, output_tax, ...)
DROP TABLE IF EXISTS default_accounts;
CREATE TABLE default_accounts (
  setting_key VARCHAR(40)  NOT NULL PRIMARY KEY,
  label       VARCHAR(120) NOT NULL,
  module      VARCHAR(20)  NOT NULL,
  account_id  INT UNSIGNED NULL,
  CONSTRAINT fk_da_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS terms;
CREATE TABLE terms (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(40)  NOT NULL,
  net_days      INT          NOT NULL DEFAULT 0,
  discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_days INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS tax_codes;
CREATE TABLE tax_codes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(80)  NOT NULL,
  rate                DECIMAL(6,3) NOT NULL DEFAULT 0,
  sales_account_id    INT UNSIGNED NULL,   -- Output Tax
  purchase_account_id INT UNSIGNED NULL,   -- Input Tax
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tax_code (code),
  CONSTRAINT fk_tax_sales FOREIGN KEY (sales_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tax_purch FOREIGN KEY (purchase_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(40)  NOT NULL,
  method_type         ENUM('cash','check','card','ewallet','bank_transfer','voucher','other') NOT NULL DEFAULT 'other',
  clearing_account_id INT UNSIGNED NULL,   -- POS clearing account for card / e-wallet / voucher / bank
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  show_in_pos         TINYINT(1)   NOT NULL DEFAULT 1,   -- offered as a payment option at the POS register
  CONSTRAINT fk_pm_account FOREIGN KEY (clearing_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_levels;
CREATE TABLE price_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(40)  NOT NULL,
  adjust_pct DECIMAL(6,2) NOT NULL DEFAULT 0,  -- e.g. -10 = 10% below standard price
  is_active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sales_reps;
CREATE TABLE sales_reps (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  initials  VARCHAR(10)  NULL,
  user_id   INT UNSIGNED NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS customer_types;
CREATE TABLE customer_types (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS billing_groups;
CREATE TABLE billing_groups (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class List: your own way of splitting the books (branches, departments, projects ...).
-- Every transaction can carry a class; the ledger keeps it, so financial reports can filter by it.
DROP TABLE IF EXISTS classes;
CREATE TABLE classes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_classes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employees and other names (payees and subsidiary ledgers that are neither customers nor vendors)
DROP TABLE IF EXISTS other_names;
CREATE TABLE other_names (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name_type   VARCHAR(10)  NOT NULL DEFAULT 'other',  -- employee, other
  name        VARCHAR(120) NOT NULL,
  employee_no VARCHAR(30)  NULL,
  position    VARCHAR(80)  NULL,
  phone       VARCHAR(40)  NULL,
  address     VARCHAR(255) NULL,
  tin         VARCHAR(30)  NULL,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_other_names_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CUSTOMERS AND VENDORS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id           INT UNSIGNED NULL,          -- job / project under a customer
  name                VARCHAR(120) NOT NULL,
  company_name        VARCHAR(120) NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(40)  NULL,
  mobile              VARCHAR(40)  NULL,
  email               VARCHAR(120) NULL,
  billing_address     VARCHAR(255) NULL,
  shipping_address    VARCHAR(255) NULL,
  tin                 VARCHAR(30)  NULL,
  wht_ids             VARCHAR(100) NULL,          -- withholding taxes it usually withholds from its payments
  terms_id            INT UNSIGNED NULL,
  credit_limit        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- 0 = no limit
  price_level_id      INT UNSIGNED NULL,
  tax_code_id         INT UNSIGNED NULL,
  payment_method_id   INT UNSIGNED NULL,
  customer_type_id    INT UNSIGNED NULL,
  sales_rep_id        INT UNSIGNED NULL,
  billing_group_id    INT UNSIGNED NULL,
  allow_pos_charge    TINYINT(1)   NOT NULL DEFAULT 0,  -- approved account customer at the POS
  client_class        VARCHAR(20)  NULL,          -- construction client: government, private, corporate, institutional, other
  notes               TEXT         NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_customers_name (name),
  CONSTRAINT fk_cust_parent FOREIGN KEY (parent_id) REFERENCES customers(id),
  CONSTRAINT fk_cust_terms  FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_cust_pl     FOREIGN KEY (price_level_id) REFERENCES price_levels(id),
  CONSTRAINT fk_cust_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_cust_pm     FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_cust_type   FOREIGN KEY (customer_type_id) REFERENCES customer_types(id),
  CONSTRAINT fk_cust_rep    FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_cust_bg     FOREIGN KEY (billing_group_id) REFERENCES billing_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS vendors;
CREATE TABLE vendors (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                       VARCHAR(120) NOT NULL,
  company_name               VARCHAR(120) NULL,
  contact_person             VARCHAR(120) NULL,
  phone                      VARCHAR(40)  NULL,
  fax                        VARCHAR(40)  NULL,
  email                      VARCHAR(120) NULL,
  address                    VARCHAR(255) NULL,
  tin                        VARCHAR(30)  NULL,
  terms_id                   INT UNSIGNED NULL,
  credit_limit               DECIMAL(15,2) NOT NULL DEFAULT 0,
  default_expense_account_id INT UNSIGNED NULL,
  tax_code_id                INT UNSIGNED NULL,
  account_no                 VARCHAR(40)  NULL,   -- our account number with the vendor
  wht_ids                    VARCHAR(100) NULL,   -- withholding taxes usually withheld from it (withholding_taxes ids, "1,3")
  vendor_kind                VARCHAR(20)  NOT NULL DEFAULT 'supplier',  -- supplier, subcontractor, both, service
  service_categories         VARCHAR(255) NULL,   -- trades / services a subcontractor or supplier provides
  accreditation_no           VARCHAR(60)  NULL,   -- PCAB licence or other accreditation
  accreditation_expiry       DATE         NULL,
  qualifications             TEXT         NULL,
  rating                     TINYINT      NULL,   -- 1 to 5
  notes                      TEXT         NULL,
  is_active                  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_vendors_name (name),
  CONSTRAINT fk_vend_terms FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_vend_exp   FOREIGN KEY (default_expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_vend_tax   FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS entity_notes;
CREATE TABLE entity_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('customer','vendor','item') NOT NULL,
  entity_id   INT UNSIGNED NOT NULL,
  note        TEXT         NOT NULL,
  is_todo     TINYINT(1)   NOT NULL DEFAULT 0,
  due_date    DATE         NULL,
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notes_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  INVENTORY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS sites;
CREATE TABLE sites (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(20)  NOT NULL,
  name      VARCHAR(80)  NOT NULL,
  site_type ENUM('warehouse','store','stockroom','van','damaged','project','other') NOT NULL DEFAULT 'warehouse',
  address   VARCHAR(255) NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sites_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_categories;
CREATE TABLE item_categories (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Units of measure: a set holds the base unit (stock and costs are kept in it)
-- plus the larger or smaller units a document may be entered in
DROP TABLE IF EXISTS uom_sets;
CREATE TABLE uom_sets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL,
  uom_kind   VARCHAR(10)  NOT NULL DEFAULT 'count',  -- count, weight, volume, length, time, other
  base_unit  VARCHAR(20)  NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_uom_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS uom_units;
CREATE TABLE uom_units (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_id              INT UNSIGNED NOT NULL,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(60)  NOT NULL,
  factor              DECIMAL(15,6) NOT NULL DEFAULT 1,  -- base units in one of this unit
  is_base             TINYINT(1)   NOT NULL DEFAULT 0,
  is_sales_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_purchase_default TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order          INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_uom_units (set_id, code),
  CONSTRAINT fk_uom_units_set FOREIGN KEY (set_id) REFERENCES uom_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS items;
CREATE TABLE items (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_type            ENUM('service','inventory','non_inventory','assembly','other_charge','subtotal','group','discount','payment') NOT NULL,
  code                 VARCHAR(40)  NOT NULL,
  barcode              VARCHAR(60)  NULL,
  name                 VARCHAR(120) NOT NULL,
  description          VARCHAR(255) NULL,
  purchase_description VARCHAR(255) NULL,
  category_id          INT UNSIGNED NULL,
  unit                 VARCHAR(20)  NULL,
  uom_set_id           INT UNSIGNED NULL,                  -- U/M set (blank = single unit)
  sales_uom_id         INT UNSIGNED NULL,                  -- unit offered first on sales forms
  purchase_uom_id      INT UNSIGNED NULL,                  -- unit offered first on purchase forms
  cost                 DECIMAL(15,4) NOT NULL DEFAULT 0,  -- standard purchase cost
  avg_cost             DECIMAL(15,4) NOT NULL DEFAULT 0,  -- weighted average cost
  sales_price          DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_pct         DECIMAL(6,2)  NOT NULL DEFAULT 0,  -- discount items: percent (0 = use sales_price as amount)
  income_account_id    INT UNSIGNED NULL,
  cogs_account_id      INT UNSIGNED NULL,
  asset_account_id     INT UNSIGNED NULL,
  expense_account_id   INT UNSIGNED NULL,
  tax_code_id          INT UNSIGNED NULL,
  group_variance_account_id INT UNSIGNED NULL,             -- group items: account of the group price less the consolidated price
  preferred_vendor_id  INT UNSIGNED NULL,
  reorder_point        DECIMAL(15,4) NOT NULL DEFAULT 0,
  max_stock            DECIMAL(15,4) NOT NULL DEFAULT 0,
  build_point          DECIMAL(15,4) NOT NULL DEFAULT 0,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_items_code (code),
  KEY ix_items_barcode (barcode),
  CONSTRAINT fk_items_cat    FOREIGN KEY (category_id) REFERENCES item_categories(id),
  CONSTRAINT fk_items_inc    FOREIGN KEY (income_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_cogs   FOREIGN KEY (cogs_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_asset  FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_exp    FOREIGN KEY (expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_items_gvar   FOREIGN KEY (group_variance_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_vendor FOREIGN KEY (preferred_vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_items_uom    FOREIGN KEY (uom_set_id) REFERENCES uom_sets(id),
  CONSTRAINT fk_items_suom   FOREIGN KEY (sales_uom_id) REFERENCES uom_units(id),
  CONSTRAINT fk_items_puom   FOREIGN KEY (purchase_uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bill of materials for assemblies, and members of group items
DROP TABLE IF EXISTS item_components;
CREATE TABLE item_components (
  parent_item_id    INT UNSIGNED NOT NULL,
  component_item_id INT UNSIGNED NOT NULL,
  qty               DECIMAL(15,4) NOT NULL DEFAULT 1,
  PRIMARY KEY (parent_item_id, component_item_id),
  CONSTRAINT fk_ic_parent FOREIGN KEY (parent_item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_comp   FOREIGN KEY (component_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Processing formulas (multi-output bills of materials) for disassembly,
-- de-kitting / break-bulk and co-product / by-product processing
DROP TABLE IF EXISTS process_formulas;
CREATE TABLE process_formulas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  process_type VARCHAR(20)  NOT NULL DEFAULT 'co_product',  -- disassembly, dekit, co_product
  alloc_method VARCHAR(20)  NOT NULL DEFAULT 'sales_value', -- sales_value, standard_cost, quantity, weight, percent, average_cost, manual
  site_id      INT UNSIGNED NULL,                           -- usual processing site
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,                           -- set by the application (DB::update)
  UNIQUE KEY uq_pf_code (code),
  CONSTRAINT fk_pf_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formula lines, quantities per batch. input = consumed, output = produced, cost = labor / overhead absorbed
DROP TABLE IF EXISTS process_formula_lines;
CREATE TABLE process_formula_lines (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  formula_id  INT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  line_type   VARCHAR(10)  NOT NULL,                  -- input, output, cost
  item_id     INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,                      -- cost lines: account credited
  description VARCHAR(255) NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  output_role VARCHAR(12)  NULL,                      -- co_product, by_product, scrap
  alloc_basis DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price / weight / percent per unit, by-product value per unit, manual amount per batch
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,       -- cost lines: amount per batch
  KEY ix_pfl_formula (formula_id),
  KEY ix_pfl_item (item_id),
  CONSTRAINT fk_pfl_formula FOREIGN KEY (formula_id) REFERENCES process_formulas(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_pfl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_level_items;
CREATE TABLE price_level_items (
  price_level_id INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  custom_price   DECIMAL(15,4) NOT NULL,
  PRIMARY KEY (price_level_id, item_id),
  CONSTRAINT fk_pli_level FOREIGN KEY (price_level_id) REFERENCES price_levels(id) ON DELETE CASCADE,
  CONSTRAINT fk_pli_item  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_site_stock;
CREATE TABLE item_site_stock (
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (item_id, site_id),
  CONSTRAINT fk_iss_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_iss_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  TRANSACTIONS (all modules)
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS transactions;
CREATE TABLE transactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_type           VARCHAR(30)  NOT NULL,
  -- estimate, sales_order, invoice, sales_receipt, statement_charge, finance_charge,
  -- payment, credit_memo, refund, purchase_order, bill, vendor_credit, item_receipt,
  -- bill_payment, check, deposit, transfer, journal, inventory_adjustment,
  -- inventory_transfer, build_assembly, pos_sale, pos_return, depreciation,
  -- asset_disposal, bank_charge, bank_interest, item_processing, cash_voucher, vendor_advance
  txn_no             VARCHAR(30)  NULL,
  txn_date           DATE         NOT NULL,
  due_date           DATE         NULL,
  ship_date          DATE         NULL,
  ref_no             VARCHAR(60)  NULL,
  customer_id        INT UNSIGNED NULL,
  vendor_id          INT UNSIGNED NULL,
  payee_name         VARCHAR(120) NULL,
  account_id         INT UNSIGNED NULL,     -- AR / AP / bank / deposit-to / from account
  account2_id        INT UNSIGNED NULL,     -- transfer-to / cash-back / adjustment account
  site_id            INT UNSIGNED NULL,
  site2_id           INT UNSIGNED NULL,     -- transfer-to site
  terms_id           INT UNSIGNED NULL,
  sales_rep_id       INT UNSIGNED NULL,
  payment_method_id  INT UNSIGNED NULL,
  billing_address    VARCHAR(255) NULL,
  shipping_address   VARCHAR(255) NULL,
  memo               VARCHAR(255) NULL,
  customer_message   VARCHAR(255) NULL,
  subtotal           DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cash_back_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total              DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20)  NOT NULL DEFAULT 'open',
  -- open, closed, paid, partial, pending, accepted, suspended, completed, void
  is_pending         TINYINT(1)   NOT NULL DEFAULT 0,
  is_voided          TINYINT(1)   NOT NULL DEFAULT 0,
  void_reason        VARCHAR(255) NULL,
  voided_by          INT UNSIGNED NULL,
  voided_at          DATETIME     NULL,
  to_be_printed      TINYINT(1)   NOT NULL DEFAULT 0,
  is_printed         TINYINT(1)   NOT NULL DEFAULT 0,
  restock            TINYINT(1)   NOT NULL DEFAULT 1,     -- credit memos / POS returns
  credit_disposition VARCHAR(10)  NULL,                   -- keep, apply, refund
  progress_pct       DECIMAL(6,2) NULL,                   -- progress invoicing
  source_txn_id      BIGINT UNSIGNED NULL,                -- converted from / refund of
  terminal_id        INT UNSIGNED NULL,
  pos_shift_id       INT UNSIGNED NULL,
  receipt_no         VARCHAR(30)  NULL,
  amount_tendered    DECIMAL(15,2) NOT NULL DEFAULT 0,
  change_given       DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_by        INT UNSIGNED NULL,
  batch_ref          VARCHAR(40)  NULL,
  process_type       VARCHAR(20)  NULL,                   -- item processing: disassembly, dekit, co_product
  alloc_method       VARCHAR(20)  NULL,                   -- item processing: cost allocation method
  process_formula_id INT UNSIGNED NULL,                   -- item processing: formula used
  process_batches    DECIMAL(15,4) NULL,                  -- item processing: formula batches run
  class_id           INT UNSIGNED NULL,                   -- class of the whole transaction (lines may override it)
  other_name_id      INT UNSIGNED NULL,                   -- payee from Employees & Other Names
  received_via       VARCHAR(12)  NULL,                   -- receipt = Receive Items, bill = Receive Items and Enter Bills
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_txn_type_date (txn_type, txn_date),
  KEY ix_txn_customer (customer_id, txn_date),
  KEY ix_txn_vendor (vendor_id, txn_date),
  KEY ix_txn_status (status),
  KEY ix_txn_source (source_txn_id),
  KEY ix_txn_shift (pos_shift_id),
  KEY ix_txn_formula (process_formula_id),
  KEY ix_txn_class (class_id),
  KEY ix_txn_other_name (other_name_id),
  CONSTRAINT fk_txn_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_txn_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_txn_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_txn_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_txn_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_formula  FOREIGN KEY (process_formula_id) REFERENCES process_formulas(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_account2 FOREIGN KEY (account2_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_site     FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_site2    FOREIGN KEY (site2_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_terms    FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_txn_rep      FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_txn_pm       FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS transaction_lines;
CREATE TABLE transaction_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id          BIGINT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  line_kind       VARCHAR(10)  NOT NULL DEFAULT 'item',   -- item, expense, journal, deposit, component, input, output, cost
  item_id         INT UNSIGNED NULL,
  account_id      INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit            VARCHAR(20)  NULL,
  uom_id          INT UNSIGNED NULL,                      -- unit the line was entered in
  uom_factor      DECIMAL(15,6) NOT NULL DEFAULT 1,       -- base units in one entered unit
  uom_qty         DECIMAL(15,4) NOT NULL DEFAULT 0,       -- quantity as entered
  uom_rate        DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price as entered
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_kind   VARCHAR(3)    NOT NULL DEFAULT 'pct',   -- pct = percent of the line, amt = amount off
  discount_pct    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,       -- journal: + debit / - credit
  tax_code_id     INT UNSIGNED NULL,
  tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  site_id         INT UNSIGNED NULL,
  customer_id     INT UNSIGNED NULL,                      -- customer:job for billable costs / journal
  vendor_id       INT UNSIGNED NULL,
  is_billable     TINYINT(1)   NOT NULL DEFAULT 0,
  billed_txn_id   BIGINT UNSIGNED NULL,                   -- invoice that billed this cost
  ref_txn_id      BIGINT UNSIGNED NULL,                   -- deposit: payment / receipt being deposited
  pos_shift_id    INT UNSIGNED NULL,                      -- deposit: the POS shift whose cash the line deposits
  source_line_id  BIGINT UNSIGNED NULL,                   -- estimate / SO / PO line
  qty_fulfilled   DECIMAL(15,4) NOT NULL DEFAULT 0,       -- SO invoiced / PO received
  amount_fulfilled DECIMAL(15,2) NOT NULL DEFAULT 0,      -- estimate progress-invoiced
  is_closed       TINYINT(1)   NOT NULL DEFAULT 0,
  cost_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,       -- COGS captured at posting
  value_change    DECIMAL(15,2) NOT NULL DEFAULT 0,       -- inventory value adjustment
  output_role     VARCHAR(12)  NULL,                      -- item processing output: co_product, by_product, scrap
  alloc_basis     DECIMAL(15,4) NOT NULL DEFAULT 0,       -- item processing: price / weight / percent / value entered
  alloc_pct       DECIMAL(9,4)  NOT NULL DEFAULT 0,       -- item processing: share of the total cost
  planned_qty     DECIMAL(15,4) NULL,                     -- item processing: quantity expected by the formula
  class_id        INT UNSIGNED NULL,                      -- class of this line (blank = the transaction's class)
  other_name_id   INT UNSIGNED NULL,                      -- subsidiary ledger: employee / other name
  group_item_id   INT UNSIGNED NULL,                      -- item group sold: the group this line belongs to
  group_ref       INT UNSIGNED NULL,                      -- which group of the document (several of the same group can be on it)
  group_qty       DECIMAL(15,4) NULL,                     -- groups sold on the line's group
  group_rate      DECIMAL(15,4) NULL,                     -- price of one group
  group_role      VARCHAR(10)  NULL,                      -- member = an item of the group, variance = group price less consolidated price
  cost_code_id    INT UNSIGNED NULL,                      -- construction cost code of the line (copied to the ledger)
  KEY ix_tl_txn (txn_id),
  KEY ix_tl_group (group_item_id),
  KEY ix_tl_item (item_id),
  KEY ix_tl_ref (ref_txn_id),
  KEY ix_tl_shift (pos_shift_id),
  KEY ix_tl_source (source_line_id),
  KEY ix_tl_class (class_id),
  KEY ix_tl_other_name (other_name_id),
  KEY ix_tl_cost_code (cost_code_id),
  CONSTRAINT fk_tl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_tl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_tl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_tl_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tl_tax     FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_tl_site    FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_tl_cust    FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_tl_uom     FOREIGN KEY (uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments / credits applied to invoices and bills
DROP TABLE IF EXISTS payment_applications;
CREATE TABLE payment_applications (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,   -- payment, credit memo, bill payment, vendor credit
  target_txn_id  BIGINT UNSIGNED NOT NULL,   -- invoice, statement/finance charge, bill
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding    DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pa_payment (payment_txn_id),
  KEY ix_pa_target (target_txn_id),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withholding Tax Table (BIR Reports): the taxes a payment can have withheld
DROP TABLE IF EXISTS withholding_taxes;
CREATE TABLE withholding_taxes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(20)  NOT NULL,
  atc                   VARCHAR(10)  NULL,                   -- BIR alphanumeric tax code (WC158, WC160, WV010 ...)
  name                  VARCHAR(100) NOT NULL,
  tax_kind              ENUM('income','vat') NOT NULL DEFAULT 'income',   -- expanded withholding of income tax, or VAT / business tax withheld
  rate                  DECIMAL(9,4) NOT NULL DEFAULT 0,     -- percent
  base_type             ENUM('net_of_vat','gross') NOT NULL DEFAULT 'net_of_vat',
  applies_to            ENUM('purchases','sales','both') NOT NULL DEFAULT 'both',
  payable_account_id    INT UNSIGNED NULL,                   -- withheld from suppliers (blank = module default)
  receivable_account_id INT UNSIGNED NULL,                   -- withheld by customers (blank = module default)
  description           VARCHAR(255) NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_wht_code (code),
  CONSTRAINT fk_wht_payable FOREIGN KEY (payable_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_wht_receivable FOREIGN KEY (receivable_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taxes withheld by a payment (bill payment or customer payment), by document paid and type: BIR Form 2307 and the BIR Reports
DROP TABLE IF EXISTS payment_withholdings;
CREATE TABLE payment_withholdings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,
  target_txn_id  BIGINT UNSIGNED NULL,        -- the bill / invoice it was withheld on
  wht_id         INT UNSIGNED NOT NULL,
  tax_kind       ENUM('income','vat') NOT NULL DEFAULT 'income',
  atc            VARCHAR(10)  NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,
  base           DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pw_payment (payment_txn_id),
  KEY ix_pw_target (target_txn_id),
  KEY ix_pw_wht (wht_id),
  CONSTRAINT fk_pw_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pw_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pw_wht     FOREIGN KEY (wht_id) REFERENCES withholding_taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item group sales (System Administration > Track Item Group Sales transactions): one row per group sold on a document
DROP TABLE IF EXISTS item_group_sales;
CREATE TABLE item_group_sales (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id         BIGINT UNSIGNED NOT NULL,
  group_ref      INT UNSIGNED NOT NULL DEFAULT 0,
  group_item_id  INT UNSIGNED NOT NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  txn_date       DATE         NOT NULL,
  customer_id    INT UNSIGNED NULL,
  site_id        INT UNSIGNED NULL,
  terminal_id    INT UNSIGNED NULL,
  class_id       INT UNSIGNED NULL,
  sales_rep_id   INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,   -- groups sold
  group_price    DECIMAL(15,4) NOT NULL DEFAULT 0,   -- price of one group
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,   -- what the groups sold for (before tax)
  consolidated   DECIMAL(15,2) NOT NULL DEFAULT 0,   -- the items of the groups at their own prices
  variance       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- amount less consolidated (to the price variance account)
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_igs_txn (txn_id),
  KEY ix_igs_group (group_item_id, txn_date),
  KEY ix_igs_date (txn_type, txn_date),
  CONSTRAINT fk_igs_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_igs_group FOREIGN KEY (group_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General ledger (generated automatically by the posting engine)
DROP TABLE IF EXISTS journal_lines;
CREATE TABLE journal_lines (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_date          DATE         NOT NULL,
  account_id        INT UNSIGNED NOT NULL,
  debit             DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit            DECIMAL(15,2) NOT NULL DEFAULT 0,
  customer_id       INT UNSIGNED NULL,
  vendor_id         INT UNSIGNED NULL,
  item_id           INT UNSIGNED NULL,
  site_id           INT UNSIGNED NULL,
  class_id          INT UNSIGNED NULL,
  other_name_id     INT UNSIGNED NULL,
  cost_code_id      INT UNSIGNED NULL,                 -- construction cost code (with class_id = the project)
  memo              VARCHAR(255) NULL,
  cleared           TINYINT(1)   NOT NULL DEFAULT 0,
  reconciliation_id INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jl_account_date (account_id, txn_date),
  KEY ix_jl_txn (txn_id),
  KEY ix_jl_customer (customer_id),
  KEY ix_jl_vendor (vendor_id),
  KEY ix_jl_class (class_id, txn_date),
  KEY ix_jl_other_name (other_name_id),
  KEY ix_jl_cost_code (cost_code_id),
  CONSTRAINT fk_jl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_jl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_jl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perpetual inventory movements (generated automatically)
DROP TABLE IF EXISTS inventory_movements;
CREATE TABLE inventory_movements (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id      BIGINT UNSIGNED NOT NULL,
  txn_line_id BIGINT UNSIGNED NULL,
  txn_date    DATE         NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_cost  DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- total on hand (all sites) after the movement
  avg_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- average cost after the movement
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_im_item_date (item_id, txn_date),
  KEY ix_im_txn (txn_id),
  CONSTRAINT fk_im_txn  FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_im_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_im_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  VENDORS - RECEIPT MANAGEMENT
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS receipt_documents;
CREATE TABLE receipt_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type     VARCHAR(80)  NULL,
  source        ENUM('upload','email','mobile') NOT NULL DEFAULT 'upload',
  vendor_id     INT UNSIGNED NULL,
  receipt_date  DATE         NULL,
  amount        DECIMAL(15,2) NULL,
  account_id    INT UNSIGNED NULL,
  memo          VARCHAR(255) NULL,
  status        ENUM('for_review','matched','created','rejected') NOT NULL DEFAULT 'for_review',
  linked_txn_id BIGINT UNSIGNED NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME     NULL,
  CONSTRAINT fk_rd_vendor  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_rd_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_rd_txn     FOREIGN KEY (linked_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FIXED ASSETS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS asset_categories;
CREATE TABLE asset_categories (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(80)  NOT NULL,
  asset_account_id       INT UNSIGNED NOT NULL,
  accum_dep_account_id   INT UNSIGNED NOT NULL,
  dep_expense_account_id INT UNSIGNED NOT NULL,
  default_method         ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL DEFAULT 'straight_line',
  default_life_years     DECIMAL(5,2) NOT NULL DEFAULT 5,
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_ac_asset FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_accum FOREIGN KEY (accum_dep_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_exp   FOREIGN KEY (dep_expense_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_locations;
CREATE TABLE asset_locations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_asset_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fixed_assets;
CREATE TABLE fixed_assets (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_no            VARCHAR(30)  NOT NULL,
  description         VARCHAR(160) NOT NULL,
  category_id         INT UNSIGNED NOT NULL,
  location            VARCHAR(120) NULL,
  location_id         INT UNSIGNED NULL,
  site_id             INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,      -- default class (depreciation entry when no class is chosen)
  serial_no           VARCHAR(60)  NULL,
  vendor_id           INT UNSIGNED NULL,
  acquisition_date    DATE         NOT NULL,
  cost                DECIMAL(15,2) NOT NULL,
  salvage_value       DECIMAL(15,2) NOT NULL DEFAULT 0,
  useful_life_years   DECIMAL(5,2) NOT NULL,
  method              ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL,
  db_multiplier       DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  total_units         DECIMAL(15,2) NULL,      -- units of production
  unit_label          VARCHAR(30)  NULL,
  -- change in estimate: new basis applied from basis_date forward
  basis_date          DATE         NULL,
  basis_nbv           DECIMAL(15,2) NULL,
  basis_life_months   INT          NULL,
  -- existing assets: depreciation recorded before the system was used
  opening_accum_dep   DECIMAL(15,2) NOT NULL DEFAULT 0,
  opening_through     DATE         NULL,
  acquisition_txn_id  BIGINT UNSIGNED NULL,
  status              ENUM('active','fully_depreciated','disposed') NOT NULL DEFAULT 'active',
  disposal_date       DATE         NULL,
  disposal_proceeds   DECIMAL(15,2) NULL,
  disposal_txn_id     BIGINT UNSIGNED NULL,
  notes               TEXT         NULL,
  current_meter       DECIMAL(15,2) NULL,     -- odometer / running hours
  meter_unit          VARCHAR(20)  NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_fa_no (asset_no),
  KEY ix_fa_location (location_id),
  KEY ix_fa_class (class_id),
  CONSTRAINT fk_fa_location FOREIGN KEY (location_id) REFERENCES asset_locations(id),
  CONSTRAINT fk_fa_class  FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_fa_cat    FOREIGN KEY (category_id) REFERENCES asset_categories(id),
  CONSTRAINT fk_fa_site   FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_fa_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_fa_acq    FOREIGN KEY (acquisition_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_fa_disp   FOREIGN KEY (disposal_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_depreciation;
CREATE TABLE asset_depreciation (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units_used   DECIMAL(15,2) NULL,
  amount       DECIMAL(15,2) NOT NULL,
  txn_id       BIGINT UNSIGNED NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ad_period (asset_id, period_year, period_month),
  CONSTRAINT fk_ad_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ad_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_meter_readings;
CREATE TABLE asset_meter_readings (
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units        DECIMAL(15,2) NOT NULL,
  entered_by   INT UNSIGNED NULL,
  entered_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_id, period_year, period_month),
  CONSTRAINT fk_amr_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixed asset maintenance and preventive maintenance
DROP TABLE IF EXISTS asset_pm_schedules;
CREATE TABLE asset_pm_schedules (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id         INT UNSIGNED NOT NULL,
  task_name        VARCHAR(160) NOT NULL,
  task_type        VARCHAR(30)  NOT NULL DEFAULT 'other',  -- registration, change_oil, cleaning, inspection, insurance, other
  interval_value   INT          NULL,                      -- repeat every N ... (NULL = one time only)
  interval_unit    VARCHAR(10)  NULL,                      -- days, weeks, months, years
  meter_interval   DECIMAL(15,2) NULL,                     -- or every N km / hours
  next_due_date    DATE         NULL,
  next_due_meter   DECIMAL(15,2) NULL,
  remind_days      INT          NOT NULL DEFAULT 14,       -- start reminding this many days before the due date
  last_done_date   DATE         NULL,
  last_done_meter  DECIMAL(15,2) NULL,
  assigned_to      VARCHAR(120) NULL,
  notes            VARCHAR(255) NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  KEY ix_pm_asset (asset_id),
  KEY ix_pm_due (is_active, next_due_date),
  CONSTRAINT fk_pm_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance;
CREATE TABLE asset_maintenance (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maint_no            VARCHAR(30)  NOT NULL,
  asset_id            INT UNSIGNED NOT NULL,
  maint_date          DATE         NOT NULL,
  maint_type          VARCHAR(20)  NOT NULL DEFAULT 'repair',     -- repair, preventive, inspection, upgrade
  status              VARCHAR(20)  NOT NULL DEFAULT 'completed',  -- in_progress, completed, void
  problem             VARCHAR(255) NULL,                          -- what was reported / why
  description         TEXT         NULL,                          -- maintenance description: the work done
  vendor_id           INT UNSIGNED NULL,                          -- supplier of the parts / service
  performed_by        VARCHAR(120) NULL,                          -- mechanic, technician or shop
  ref_no              VARCHAR(60)  NULL,                          -- supplier invoice / job order no.
  meter_reading       DECIMAL(15,2) NULL,
  downtime_days       DECIMAL(7,2) NULL,
  parts_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  labor_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total           DECIMAL(15,2) NOT NULL DEFAULT 0,
  total               DECIMAL(15,2) NOT NULL DEFAULT 0,
  with_vat            TINYINT(1)   NOT NULL DEFAULT 0,
  payment_mode        VARCHAR(20)  NOT NULL DEFAULT 'none',       -- none, bill, check, cash_voucher
  pay_account_id      INT UNSIGNED NULL,                          -- bank (check) or cash account (cash voucher)
  parts_account_id    INT UNSIGNED NULL,
  labor_account_id    INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,
  txn_id              BIGINT UNSIGNED NULL,                       -- the bill, check or cash voucher created for it
  schedule_id         INT UNSIGNED NULL,                          -- preventive maintenance task completed by this record
  next_service_date   DATE         NULL,
  notes               TEXT         NULL,
  void_reason         VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_maint_no (maint_no),
  KEY ix_maint_asset (asset_id, maint_date),
  KEY ix_maint_vendor (vendor_id),
  KEY ix_maint_txn (txn_id),
  KEY ix_maint_class (class_id),
  KEY ix_maint_schedule (schedule_id),
  CONSTRAINT fk_maint_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id),
  CONSTRAINT fk_maint_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_maint_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_maint_txn      FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_maint_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_parts;
CREATE TABLE asset_maintenance_parts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  part_no         VARCHAR(60)  NULL,
  description     VARCHAR(200) NOT NULL,
  serial_no       VARCHAR(60)  NULL,
  model_no        VARCHAR(60)  NULL,
  size_spec       VARCHAR(60)  NULL,
  brand           VARCHAR(60)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 1,
  unit            VARCHAR(20)  NULL,
  unit_cost       DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  warranty_until  DATE         NULL,
  KEY ix_mparts_maint (maintenance_id),
  KEY ix_mparts_partno (part_no),
  CONSTRAINT fk_mparts_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_labor;
CREATE TABLE asset_maintenance_labor (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  description     VARCHAR(200) NOT NULL,
  worker          VARCHAR(120) NULL,
  charge_type     VARCHAR(10)  NOT NULL DEFAULT 'hourly',   -- hourly (man-hours x rate) or bulk (one charge)
  hours           DECIMAL(9,2) NOT NULL DEFAULT 0,
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_mlabor_maint (maintenance_id),
  CONSTRAINT fk_mlabor_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_photos;
CREATE TABLE asset_maintenance_photos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  file_name       VARCHAR(120) NOT NULL,      -- name inside storage/maintenance/
  original_name   VARCHAR(190) NULL,
  mime_type       VARCHAR(60)  NULL,
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  caption         VARCHAR(190) NULL,
  uploaded_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mphotos_maint (maintenance_id),
  CONSTRAINT fk_mphotos_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_pm_log;
CREATE TABLE asset_pm_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_id     INT UNSIGNED NOT NULL,
  asset_id        INT UNSIGNED NOT NULL,
  task_name       VARCHAR(160) NOT NULL,
  due_date        DATE         NULL,
  done_date       DATE         NOT NULL,
  done_meter      DECIMAL(15,2) NULL,
  maintenance_id  INT UNSIGNED NULL,
  remarks         VARCHAR(255) NULL,
  done_by         INT UNSIGNED NULL,          -- the user who acknowledged that the task is complete
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pmlog_schedule (schedule_id),
  KEY ix_pmlog_asset (asset_id, done_date),
  CONSTRAINT fk_pmlog_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_pmlog_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  POINT OF SALE
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS pos_terminals;
CREATE TABLE pos_terminals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  site_id         INT UNSIGNED NOT NULL,
  cash_account_id INT UNSIGNED NOT NULL,
  receipt_prefix  VARCHAR(10)  NOT NULL DEFAULT 'OR',
  next_receipt_no INT UNSIGNED NOT NULL DEFAULT 1,
  next_z_no       INT UNSIGNED NOT NULL DEFAULT 1,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  class_id        INT UNSIGNED NULL,                      -- default class of sales made at this terminal
  UNIQUE KEY uq_term_code (code),
  CONSTRAINT fk_term_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_term_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_term_cash FOREIGN KEY (cash_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_shifts;
CREATE TABLE pos_shifts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  terminal_id    INT UNSIGNED NOT NULL,
  cashier_id     INT UNSIGNED NOT NULL,
  business_date  DATE         NOT NULL,
  opened_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_float  DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(15,2) NULL,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  deposit_txn_id BIGINT UNSIGNED NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by      INT UNSIGNED NULL,
  corrected_by   INT UNSIGNED NULL,          -- who last corrected the closing entries (float, drops, count)
  corrected_at   DATETIME     NULL,
  KEY ix_shift_term (terminal_id, status),
  CONSTRAINT fk_shift_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_shift_user FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_tenders;
CREATE TABLE pos_tenders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,     -- net amount applied to the sale (after change)
  reference         VARCHAR(60)  NULL,
  KEY ix_pt_txn (txn_id),
  CONSTRAINT fk_pt_txn FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_pm  FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_cash_drops;
CREATE TABLE pos_cash_drops (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id    INT UNSIGNED NOT NULL,
  amount      DECIMAL(15,2) NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cd_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_readings;
CREATE TABLE pos_readings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reading_type   ENUM('X','Z') NOT NULL,
  terminal_id    INT UNSIGNED NOT NULL,
  shift_id       INT UNSIGNED NOT NULL,
  z_no           INT UNSIGNED NULL,
  business_date  DATE         NOT NULL,
  first_receipt  VARCHAR(30)  NULL,
  last_receipt   VARCHAR(30)  NULL,
  sales_count    INT          NOT NULL DEFAULT 0,
  gross_sales    DECIMAL(15,2) NOT NULL DEFAULT 0,
  discounts      DECIMAL(15,2) NOT NULL DEFAULT 0,
  returns        DECIMAL(15,2) NOT NULL DEFAULT 0,
  voids          DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_sales      DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax            DECIMAL(15,2) NOT NULL DEFAULT 0,
  tenders_json   TEXT         NULL,
  cash_drops     DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_cash  DECIMAL(15,2) NOT NULL DEFAULT 0,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  created_by     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_term  FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_pr_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time approval codes a supervisor generates for a cashier or clerk (used once, then expired)
DROP TABLE IF EXISTS approval_codes;
CREATE TABLE approval_codes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12)  NOT NULL,
  purpose       VARCHAR(160) NULL,
  for_user_id   INT UNSIGNED NULL,          -- the user it is meant for (NULL: any user)
  page_key      VARCHAR(60)  NULL,          -- the page it may approve on (NULL: any page the issuer may approve on)
  max_amount    DECIMAL(15,2) NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',   -- open / used / expired / cancelled
  issued_by     INT UNSIGNED NOT NULL,
  issued_at     DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_by       INT UNSIGNED NULL,
  used_at       DATETIME     NULL,
  used_on       VARCHAR(160) NULL,
  used_txn_id   BIGINT UNSIGNED NULL,
  cancelled_by  INT UNSIGNED NULL,
  cancelled_at  DATETIME     NULL,
  UNIQUE KEY uq_approval_codes_code (code),
  KEY ix_approval_codes_status (status, expires_at),
  KEY ix_approval_codes_issued (issued_by, issued_at),
  CONSTRAINT fk_apc_issuer FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BANKING
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS bank_reconciliations;
CREATE TABLE bank_reconciliations (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id         INT UNSIGNED NOT NULL,
  statement_date     DATE         NOT NULL,
  beginning_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
  ending_balance     DECIMAL(15,2) NOT NULL DEFAULT 0,
  cleared_balance    DECIMAL(15,2) NOT NULL DEFAULT 0,
  difference         DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_charge_txn_id BIGINT UNSIGNED NULL,
  interest_txn_id    BIGINT UNSIGNED NULL,
  service_charge     DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_date       DATE         NULL,
  service_account_id INT UNSIGNED NULL,
  interest_earned    DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_date      DATE         NULL,
  interest_account_id INT UNSIGNED NULL,
  class_id           INT UNSIGNED NULL,
  status             ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_by       INT UNSIGNED NULL,
  completed_at       DATETIME     NULL,
  KEY ix_br_account (account_id, statement_date),
  CONSTRAINT fk_br_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of the items cleared in each reconciliation (used by the reports)
DROP TABLE IF EXISTS reconciliation_items;
CREATE TABLE reconciliation_items (
  reconciliation_id INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,   -- + deposit / - payment
  cleared           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (reconciliation_id, txn_id),
  CONSTRAINT fk_ri_rec FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleared transactions changed after reconciliation
DROP TABLE IF EXISTS reconciliation_discrepancies;
CREATE TABLE reconciliation_discrepancies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reconciliation_id INT UNSIGNED NULL,
  account_id        INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_ref           VARCHAR(80)  NULL,
  change_type       VARCHAR(20)  NOT NULL,   -- amount changed, voided, deleted, uncleared
  cleared_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  new_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  changed_by        INT UNSIGNED NULL,
  changed_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rd_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SECURITY - site and terminal assignments
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS role_sites;
CREATE TABLE role_sites (
  role_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, site_id),
  CONSTRAINT fk_rs_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_sites;
CREATE TABLE user_sites (
  user_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, site_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_terminals;
CREATE TABLE user_terminals (
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, terminal_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ut_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Memorized transactions, budgets and forecasts, report layouts,
--  CSV imports and database backups
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS memorized_txns;
CREATE TABLE memorized_txns (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  group_name     VARCHAR(60)  NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  entity_label   VARCHAR(160) NULL,               -- customer / supplier / payee, for the list
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  payload        LONGTEXT     NOT NULL,           -- the header and the lines of the saved transaction
  how            VARCHAR(20)  NOT NULL DEFAULT 'remind',   -- remind, auto, none (list only)
  frequency      VARCHAR(10)  NOT NULL DEFAULT 'monthly',  -- daily, weekly, months, quarterly, yearly
  every_n        INT          NOT NULL DEFAULT 1,
  next_date      DATE         NULL,
  days_in_advance INT         NOT NULL DEFAULT 5,
  remaining      INT          NULL,               -- how many are left to enter (NULL = no limit)
  end_date       DATE         NULL,
  last_entered   DATE         NULL,
  entered_count  INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  KEY ix_mt_due (is_active, next_date),
  KEY ix_mt_type (txn_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budgets;
CREATE TABLE budgets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'pl',   -- pl = income statement, bs = balance sheet, forecast = sales forecast
  fiscal_year   SMALLINT     NOT NULL,
  start_month   TINYINT      NOT NULL DEFAULT 1,      -- the month the fiscal year starts in
  class_id      INT UNSIGNED NULL,
  customer_id   INT UNSIGNED NULL,                    -- forecast for one customer (NULL = all customers)
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_budget (kind, fiscal_year, name),
  KEY ix_budget_class (class_id),
  KEY ix_budget_customer (customer_id),
  CONSTRAINT fk_budget_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_budget_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budget_lines;
CREATE TABLE budget_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id    INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  customer_id  INT UNSIGNED NULL,                 -- forecasts: the customer the amounts belong to
  period_month TINYINT      NOT NULL,             -- 1..12, counted from the start of the fiscal year
  amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_budget_line (budget_id, account_id, customer_id, period_month),
  KEY ix_bl_account (account_id),
  CONSTRAINT fk_bl_budget   FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_bl_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS report_layouts;
CREATE TABLE report_layouts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_key   VARCHAR(60)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  title        VARCHAR(160) NULL,                 -- a heading of your own for the print-out
  columns      TEXT         NULL,                 -- key:Heading, one per line, in the order they print; a leading - means hidden
  options      TEXT         NULL,                 -- name=value, one per line: negatives, zeros, decimals, density and the rest
  is_shared    TINYINT(1)   NOT NULL DEFAULT 0,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,
  KEY ix_rl_report (report_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS import_batches;
CREATE TABLE import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_type   VARCHAR(40)  NOT NULL,
  file_name     VARCHAR(190) NULL,
  rows_total    INT          NOT NULL DEFAULT 0,
  rows_ok       INT          NOT NULL DEFAULT 0,
  rows_failed   INT          NOT NULL DEFAULT 0,
  created_ids   LONGTEXT     NULL,                -- what the import created, so it can be traced
  messages      LONGTEXT     NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ib_type (import_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS backup_log;
CREATE TABLE backup_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(10)  NOT NULL,             -- backup, restore
  file_name    VARCHAR(190) NOT NULL,
  file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT          NOT NULL DEFAULT 0,
  rows_count   INT          NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bl_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  CONSTRUCTION MANAGEMENT (cs_* tables)
--  A project is tied to one accounting class (cs_projects.class_id), so
--  every accounting transaction carrying that class belongs to the
--  project; cost codes ride on transaction_lines / journal_lines.
-- =====================================================================

-- ---------------------------------------------------------------------
--  SET-UP LISTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_cost_codes;
CREATE TABLE cs_cost_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30)  NOT NULL,
  name        VARCHAR(150) NOT NULL,
  category    VARCHAR(20)  NOT NULL DEFAULT 'other',   -- the usual cost type: material, labor, equipment, subcontract, other, overhead
  parent_id   INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,                        -- GL cost account suggested when this code is charged
  unit        VARCHAR(20)  NULL,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_cost_codes_code (code),
  KEY ix_cs_cc_parent (parent_id),
  KEY ix_cs_cc_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_trades;
CREATE TABLE cs_trades (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80)  NOT NULL,
  description  VARCHAR(255) NULL,
  daily_rate   DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cs_trades_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_checklist_templates;
CREATE TABLE cs_checklist_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind        VARCHAR(20)  NOT NULL,                   -- milestone, closeout, inspection, tender
  name        VARCHAR(120) NOT NULL,
  items       TEXT         NOT NULL,                   -- one item per line
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_cs_ct_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  APPROVALS AND NOTIFICATIONS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_approval_routes;
CREATE TABLE cs_approval_routes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_type      VARCHAR(40)  NOT NULL,                 -- contract, budget, material_request, timesheet, billing, ...
  name          VARCHAR(120) NOT NULL,
  min_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  max_amount    DECIMAL(15,2) NULL,                    -- NULL = no upper limit
  project_id    INT UNSIGNED NULL,                     -- NULL = every project
  risk_level    VARCHAR(10)  NULL,                     -- NULL = any; low, medium, high
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_ar_type (doc_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_approval_steps;
CREATE TABLE cs_approval_steps (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id    INT UNSIGNED NOT NULL,
  step_no     INT          NOT NULL,
  label       VARCHAR(80)  NOT NULL,
  role_id     INT UNSIGNED NULL,                       -- anyone holding this role may act
  user_id     INT UNSIGNED NULL,                       -- or this one user
  project_role VARCHAR(20) NULL,                       -- or the project's own: pm, engineer
  due_hours   INT          NOT NULL DEFAULT 48,
  KEY ix_cs_as_route (route_id, step_no),
  CONSTRAINT fk_cs_as_route FOREIGN KEY (route_id) REFERENCES cs_approval_routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_approval_requests;
CREATE TABLE cs_approval_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_type      VARCHAR(40)  NOT NULL,
  doc_id        INT UNSIGNED NOT NULL,
  doc_ref       VARCHAR(120) NULL,
  project_id    INT UNSIGNED NULL,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  route_id      INT UNSIGNED NULL,
  current_step  INT          NOT NULL DEFAULT 1,
  total_steps   INT          NOT NULL DEFAULT 1,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending, approved, rejected, returned, cancelled
  requested_by  INT UNSIGNED NULL,
  requested_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  step_since    DATETIME     NULL,
  due_at        DATETIME     NULL,
  decided_at    DATETIME     NULL,
  delegated_to  INT UNSIGNED NULL,
  escalated     TINYINT(1)   NOT NULL DEFAULT 0,
  note          VARCHAR(255) NULL,
  KEY ix_cs_aq_doc (doc_type, doc_id),
  KEY ix_cs_aq_status (status, current_step),
  KEY ix_cs_aq_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_approval_actions;
CREATE TABLE cs_approval_actions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id   INT UNSIGNED NOT NULL,
  step_no      INT          NOT NULL DEFAULT 0,
  action       VARCHAR(12)  NOT NULL,                  -- submit, approve, reject, return, delegate, escalate, cancel, resubmit
  user_id      INT UNSIGNED NULL,
  user_name    VARCHAR(120) NULL,
  step_label   VARCHAR(80)  NULL,
  amount       DECIMAL(15,2) NULL,
  comment      TEXT         NULL,
  delegated_to INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_aa_req (request_id),
  CONSTRAINT fk_cs_aa_req FOREIGN KEY (request_id) REFERENCES cs_approval_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_notifications;
CREATE TABLE cs_notifications (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  project_id  INT UNSIGNED NULL,
  kind        VARCHAR(40)  NOT NULL,
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(500) NULL,
  link        VARCHAR(255) NULL,
  is_read     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_nt_user (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_attachments;
CREATE TABLE cs_attachments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type  VARCHAR(30)  NOT NULL,                  -- daily_report, ncr, milestone_item, safety, contract, billing, change_order, punch, defect ...
  entity_id    INT UNSIGNED NOT NULL,
  project_id   INT UNSIGNED NULL,
  file_path    VARCHAR(255) NOT NULL,
  file_name    VARCHAR(190) NOT NULL,
  mime         VARCHAR(80)  NULL,
  file_size    INT UNSIGNED NOT NULL DEFAULT 0,
  caption      VARCHAR(255) NULL,
  uploaded_by  INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_att_entity (entity_type, entity_id),
  KEY ix_cs_att_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BUSINESS DEVELOPMENT, CONTRACTS AND PROJECTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_opportunities;
CREATE TABLE cs_opportunities (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  opp_no              VARCHAR(30)  NOT NULL,
  name                VARCHAR(200) NOT NULL,
  customer_id         INT UNSIGNED NULL,
  prospect_name       VARCHAR(150) NULL,
  source              VARCHAR(60)  NULL,               -- PhilGEPS, referral, invitation to bid, walk-in
  location            VARCHAR(255) NULL,
  est_value           DECIMAL(15,2) NOT NULL DEFAULT 0,
  est_cost            DECIMAL(15,2) NOT NULL DEFAULT 0,
  probability_pct     DECIMAL(6,2) NOT NULL DEFAULT 50,
  bid_due_date        DATE         NULL,
  status              VARCHAR(20)  NOT NULL DEFAULT 'lead',    -- lead, qualifying, bidding, submitted, won, lost, no_bid
  bid_decision        VARCHAR(10)  NOT NULL DEFAULT 'pending', -- pending, bid, no_bid
  decision_notes      TEXT         NULL,
  bid_security_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  bid_security_ref    VARCHAR(60)  NULL,
  bid_security_expiry DATE         NULL,
  checklist           TEXT         NULL,               -- tender document checklist: "[x] item" per line
  owner_user_id       INT UNSIGNED NULL,
  project_id          INT UNSIGNED NULL,               -- set when won and turned into a project
  notes               TEXT         NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_cs_opp_no (opp_no),
  KEY ix_cs_opp_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_contracts;
CREATE TABLE cs_contracts (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contract_no              VARCHAR(30)  NOT NULL,
  client_ref_no            VARCHAR(60)  NULL,
  customer_id              INT UNSIGNED NOT NULL,
  title                    VARCHAR(200) NOT NULL,
  scope                    TEXT         NULL,
  description              TEXT         NULL,
  participating_orgs       VARCHAR(255) NULL,          -- consultants, joint venture partners, PMC
  contract_type            VARCHAR(20)  NOT NULL DEFAULT 'lump_sum', -- lump_sum, unit_price, cost_plus, tm, hybrid
  original_amount          DECIMAL(15,2) NOT NULL DEFAULT 0,  -- net of VAT
  approved_variations      DECIMAL(15,2) NOT NULL DEFAULT 0,  -- client-approved change orders (kept up to date)
  tax_code_id              INT UNSIGNED NULL,          -- VAT on billings
  wht_ids                  VARCHAR(100) NULL,          -- taxes the client withholds from payments
  signing_date             DATE         NULL,
  effective_date           DATE         NULL,
  ntp_date                 DATE         NULL,          -- Notice to Proceed
  commencement_date        DATE         NULL,
  original_completion_date DATE         NULL,
  revised_completion_date  DATE         NULL,
  terms_id                 INT UNSIGNED NULL,
  payment_terms            TEXT         NULL,
  advance_pct              DECIMAL(7,4) NOT NULL DEFAULT 0,   -- mobilization / advance payment
  advance_amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
  recoupment_pct           DECIMAL(7,4) NOT NULL DEFAULT 0,   -- advance recouped from each progress billing
  retention_pct            DECIMAL(7,4) NOT NULL DEFAULT 0,
  retention_cap_pct        DECIMAL(7,4) NULL,                 -- stop retaining at this % of the contract
  retention_release_terms  TEXT         NULL,
  ld_per_day               DECIMAL(15,2) NOT NULL DEFAULT 0,  -- liquidated damages
  ld_cap_pct               DECIMAL(7,4) NULL,
  penalty_terms            TEXT         NULL,
  warranty_months          INT          NOT NULL DEFAULT 12,
  dlp_months               INT          NOT NULL DEFAULT 12,  -- defect liability period
  status                   VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft, for_approval, active, suspended, completed, closed, terminated, rejected
  approved_by              INT UNSIGNED NULL,
  approved_at              DATETIME     NULL,
  notes                    TEXT         NULL,
  created_by               INT UNSIGNED NULL,
  created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NULL,
  UNIQUE KEY uq_cs_contract_no (contract_no),
  KEY ix_cs_contract_customer (customer_id),
  KEY ix_cs_contract_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_contract_bonds;
CREATE TABLE cs_contract_bonds (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contract_id  INT UNSIGNED NOT NULL,
  kind         VARCHAR(30)  NOT NULL,                  -- performance_bond, surety_bond, warranty_bond, insurance, permit, bid_security, other
  reference_no VARCHAR(60)  NULL,
  issuer       VARCHAR(150) NULL,
  amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  issue_date   DATE         NULL,
  expiry_date  DATE         NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'active', -- active, released, expired, claimed
  notes        VARCHAR(255) NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_cb_contract (contract_id),
  KEY ix_cs_cb_expiry (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_projects;
CREATE TABLE cs_projects (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(30)  NOT NULL,
  name               VARCHAR(200) NOT NULL,
  description        TEXT         NULL,
  location           VARCHAR(255) NULL,
  contract_id        INT UNSIGNED NULL,
  customer_id        INT UNSIGNED NULL,
  class_id           INT UNSIGNED NOT NULL,             -- the accounting class of the project
  site_id            INT UNSIGNED NULL,                 -- project stockyard (inventory site)
  pm_user_id         INT UNSIGNED NULL,
  engineer_user_id   INT UNSIGNED NULL,
  qs_user_id         INT UNSIGNED NULL,
  division           VARCHAR(60)  NULL,
  status             VARCHAR(24)  NOT NULL DEFAULT 'planning', -- planning, mobilization, active, on_hold, substantially_completed, completed, under_warranty, closed
  contract_value     DECIMAL(15,2) NOT NULL DEFAULT 0,  -- this project's share of the contract (net of VAT)
  start_date         DATE         NULL,
  target_end_date    DATE         NULL,
  revised_end_date   DATE         NULL,
  actual_start_date  DATE         NULL,
  actual_end_date    DATE         NULL,
  completion_pct     DECIMAL(6,2) NOT NULL DEFAULT 0,   -- physical progress (from the schedule or set by hand)
  progress_source    VARCHAR(10)  NOT NULL DEFAULT 'schedule', -- schedule, manual
  floor_area         DECIMAL(15,2) NOT NULL DEFAULT 0,  -- used by floor-area cost allocation
  warranty_end_date  DATE         NULL,
  closure_override   VARCHAR(255) NULL,                 -- documented exception when closed with open items
  closed_by          INT UNSIGNED NULL,
  closed_at          DATETIME     NULL,
  notes              TEXT         NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_cs_projects_code (code),
  UNIQUE KEY uq_cs_projects_class (class_id),
  KEY ix_cs_projects_contract (contract_id),
  KEY ix_cs_projects_status (status),
  KEY ix_cs_projects_pm (pm_user_id),
  CONSTRAINT fk_cs_projects_class FOREIGN KEY (class_id) REFERENCES classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_project_members;
CREATE TABLE cs_project_members (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  role_label  VARCHAR(60)  NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_pm (project_id, user_id),
  KEY ix_cs_pm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_project_logs;
CREATE TABLE cs_project_logs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NOT NULL,
  kind         VARCHAR(20)  NOT NULL,                  -- risk, issue, decision, instruction, correspondence, meeting, diary, delay
  ref_no       VARCHAR(30)  NULL,
  log_date     DATE         NOT NULL,
  title        VARCHAR(200) NOT NULL,
  details      TEXT         NULL,
  party        VARCHAR(150) NULL,                      -- from / to / attendees / owner
  probability  TINYINT      NULL,                      -- risks: 1 to 5
  impact       TINYINT      NULL,                      -- risks: 1 to 5
  cost_impact  DECIMAL(15,2) NOT NULL DEFAULT 0,
  days_impact  INT          NOT NULL DEFAULT 0,
  response     TEXT         NULL,
  due_date     DATE         NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'open',   -- open, monitoring, closed
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_pl_project (project_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  DOCUMENTS, SPECIFICATIONS, RFIs AND SUBMITTALS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_documents;
CREATE TABLE cs_documents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NULL,
  contract_id  INT UNSIGNED NULL,
  category     VARCHAR(30)  NOT NULL,                  -- specification, drawing, boq, method_statement, permit, contract, photo, as_built, manual, report, other
  discipline   VARCHAR(20)  NULL,                      -- architectural, structural, civil, mechanical, plumbing, electrical, general
  doc_no       VARCHAR(60)  NOT NULL,
  title        VARCHAR(200) NOT NULL,
  current_rev  VARCHAR(10)  NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'active', -- active, void
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_doc_project (project_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_document_revisions;
CREATE TABLE cs_document_revisions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  document_id  INT UNSIGNED NOT NULL,
  rev_no       VARCHAR(10)  NOT NULL,
  file_path    VARCHAR(255) NULL,
  file_name    VARCHAR(190) NULL,
  mime         VARCHAR(80)  NULL,
  file_size    INT UNSIGNED NOT NULL DEFAULT 0,
  notes        VARCHAR(255) NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'for_approval', -- for_approval, approved, superseded, rejected
  approved_by  INT UNSIGNED NULL,
  approved_at  DATETIME     NULL,
  uploaded_by  INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_dr_doc (document_id),
  CONSTRAINT fk_cs_dr_doc FOREIGN KEY (document_id) REFERENCES cs_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_rfis;
CREATE TABLE cs_rfis (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id     INT UNSIGNED NOT NULL,
  kind           VARCHAR(20)  NOT NULL DEFAULT 'rfi',  -- rfi, submittal, shop_drawing, material_submittal, transmittal, technical_approval
  ref_no         VARCHAR(30)  NOT NULL,
  subject        VARCHAR(200) NOT NULL,
  description    TEXT         NULL,
  from_party     VARCHAR(150) NULL,
  to_party       VARCHAR(150) NULL,
  date_sent      DATE         NULL,
  date_due       DATE         NULL,
  response       TEXT         NULL,
  response_date  DATE         NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'open', -- open, answered, approved, approved_as_noted, revise_resubmit, rejected, closed
  document_id    INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_rfi_project (project_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  ESTIMATES, BOQ / SCHEDULE OF VALUES AND BUDGETS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_estimates;
CREATE TABLE cs_estimates (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  estimate_no      VARCHAR(30)  NOT NULL,
  project_id       INT UNSIGNED NULL,
  opportunity_id   INT UNSIGNED NULL,
  title            VARCHAR(200) NOT NULL,
  version_no       INT          NOT NULL DEFAULT 1,
  status           VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, rejected, superseded
  markup_pct       DECIMAL(7,4) NOT NULL DEFAULT 0,
  contingency_pct  DECIMAL(7,4) NOT NULL DEFAULT 0,
  total_cost       DECIMAL(15,2) NOT NULL DEFAULT 0,
  contingency_amt  DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_price      DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes            TEXT         NULL,
  created_by       INT UNSIGNED NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME     NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  UNIQUE KEY uq_cs_est_no (estimate_no),
  KEY ix_cs_est_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_estimate_lines;
CREATE TABLE cs_estimate_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  estimate_id   INT UNSIGNED NOT NULL,
  line_no       INT          NOT NULL DEFAULT 0,
  category      VARCHAR(20)  NOT NULL,                 -- materials, labor, equipment, subcontract, hauling, site_prep, utilities, permits, temporary, site_admin, overhead, corporate_overhead, contingency
  cost_code_id  INT UNSIGNED NULL,
  item_id       INT UNSIGNED NULL,
  boq_ref       VARCHAR(30)  NULL,
  description   VARCHAR(255) NOT NULL,
  qty           DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit          VARCHAR(20)  NULL,
  unit_cost     DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_cs_el_est (estimate_id),
  CONSTRAINT fk_cs_el_est FOREIGN KEY (estimate_id) REFERENCES cs_estimates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_boq_items;
CREATE TABLE cs_boq_items (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  contract_id     INT UNSIGNED NULL,
  item_no         VARCHAR(30)  NOT NULL,
  description     VARCHAR(255) NOT NULL,
  unit            VARCHAR(20)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_rate       DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,     -- scheduled value (contract price of the line)
  cost_code_id    INT UNSIGNED NULL,
  activity_id     INT UNSIGNED NULL,
  milestone_id    INT UNSIGNED NULL,
  is_variation    TINYINT(1)   NOT NULL DEFAULT 0,
  change_order_id INT UNSIGNED NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  KEY ix_cs_boq_project (project_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_budgets;
CREATE TABLE cs_budgets (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id         INT UNSIGNED NOT NULL,
  version_no         INT          NOT NULL DEFAULT 1,
  kind               VARCHAR(10)  NOT NULL DEFAULT 'original',  -- original, revision
  name               VARCHAR(150) NOT NULL,
  status             VARCHAR(12)  NOT NULL DEFAULT 'draft',     -- draft, for_approval, approved, rejected, superseded
  is_baseline        TINYINT(1)   NOT NULL DEFAULT 0,
  effective_date     DATE         NULL,
  total_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  reason             TEXT         NULL,
  source_estimate_id INT UNSIGNED NULL,
  change_order_id    INT UNSIGNED NULL,
  created_by         INT UNSIGNED NULL,
  approved_by        INT UNSIGNED NULL,
  approved_at        DATETIME     NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_bud_project (project_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_budget_lines;
CREATE TABLE cs_budget_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id     INT UNSIGNED NOT NULL,
  cost_code_id  INT UNSIGNED NOT NULL,
  category      VARCHAR(20)  NOT NULL DEFAULT 'material',  -- cost type: material, labor, equipment, subcontract, other, overhead
  description   VARCHAR(255) NULL,
  qty           DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit          VARCHAR(20)  NULL,
  unit_cost     DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_cs_bl_budget (budget_id),
  CONSTRAINT fk_cs_bl_budget FOREIGN KEY (budget_id) REFERENCES cs_budgets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SCHEDULE, MILESTONES AND DELIVERABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_activities;
CREATE TABLE cs_activities (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  parent_id       INT UNSIGNED NULL,
  wbs_code        VARCHAR(30)  NOT NULL,
  name            VARCHAR(200) NOT NULL,
  cost_code_id    INT UNSIGNED NULL,
  planned_start   DATE         NULL,
  planned_finish  DATE         NULL,
  baseline_start  DATE         NULL,
  baseline_finish DATE         NULL,
  actual_start    DATE         NULL,
  actual_finish   DATE         NULL,
  weight_pct      DECIMAL(9,4) NOT NULL DEFAULT 0,     -- share of the project's physical progress
  planned_qty     DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit            VARCHAR(20)  NULL,
  qty_done        DECIMAL(15,4) NOT NULL DEFAULT 0,
  progress_pct    DECIMAL(6,2) NOT NULL DEFAULT 0,
  budget_hours    DECIMAL(15,2) NOT NULL DEFAULT 0,    -- planned man-hours (productivity)
  is_milestone    TINYINT(1)   NOT NULL DEFAULT 0,
  status          VARCHAR(12)  NOT NULL DEFAULT 'not_started', -- not_started, in_progress, completed, on_hold
  total_float     INT          NULL,                   -- working out of the critical path (days)
  is_critical     TINYINT(1)   NOT NULL DEFAULT 0,
  resource_notes  VARCHAR(255) NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  KEY ix_cs_act_project (project_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_activity_deps;
CREATE TABLE cs_activity_deps (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  activity_id     INT UNSIGNED NOT NULL,
  predecessor_id  INT UNSIGNED NOT NULL,
  dep_type        VARCHAR(2)   NOT NULL DEFAULT 'FS',  -- FS, SS, FF, SF
  lag_days        INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_cs_dep (activity_id, predecessor_id),
  CONSTRAINT fk_cs_dep_act FOREIGN KEY (activity_id) REFERENCES cs_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_dep_pred FOREIGN KEY (predecessor_id) REFERENCES cs_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_eot_requests;
CREATE TABLE cs_eot_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  ref_no          VARCHAR(30)  NOT NULL,
  reason          TEXT         NOT NULL,
  cause           VARCHAR(30)  NULL,                    -- weather, client_delay, variation, force_majeure, permits, other
  days_requested  INT          NOT NULL DEFAULT 0,
  days_granted    INT          NOT NULL DEFAULT 0,
  submitted_date  DATE         NULL,
  decision_date   DATE         NULL,
  status          VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft, submitted, granted, partially_granted, rejected
  change_order_id INT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_eot_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_milestones;
CREATE TABLE cs_milestones (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id            INT UNSIGNED NOT NULL,
  code                  VARCHAR(30)  NOT NULL,
  name                  VARCHAR(200) NOT NULL,
  scope                 TEXT         NULL,
  scheduled_date        DATE         NULL,
  deliverables          TEXT         NULL,
  budget_value          DECIMAL(15,2) NOT NULL DEFAULT 0,
  contract_value        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- amount billable when certified
  measurement_method    VARCHAR(20)  NOT NULL DEFAULT 'physical_pct', -- physical_pct, quantity, completion, cost_to_cost, client_certified
  required_inspections  TEXT         NULL,
  responsible_user_id   INT UNSIGNED NULL,
  reviewer_user_id      INT UNSIGNED NULL,
  client_cert_required  TINYINT(1)   NOT NULL DEFAULT 1,
  completion_pct        DECIMAL(6,2) NOT NULL DEFAULT 0,   -- approved completion
  client_certified_date DATE         NULL,
  client_cert_ref       VARCHAR(60)  NULL,
  accepted_date         DATE         NULL,
  status                VARCHAR(14)  NOT NULL DEFAULT 'pending', -- pending, in_progress, submitted, under_review, accepted, rejected, completed
  billing_eligible      TINYINT(1)   NOT NULL DEFAULT 0,   -- set by the system, never by hand
  activity_id           INT UNSIGNED NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  notes                 TEXT         NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_ms_project (project_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_milestone_items;
CREATE TABLE cs_milestone_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  milestone_id  INT UNSIGNED NOT NULL,
  line_no       INT          NOT NULL DEFAULT 0,
  description   VARCHAR(255) NOT NULL,
  doc_type      VARCHAR(40)  NULL,                     -- inspection_report, accomplishment_report, progress_photos, test_results, engineer_certification, signed_acceptance, as_built, delivery_ack, punch_clearance
  is_required   TINYINT(1)   NOT NULL DEFAULT 1,
  status        VARCHAR(14)  NOT NULL DEFAULT 'pending', -- pending, in_progress, submitted, under_review, accepted, rejected, completed
  remarks       VARCHAR(255) NULL,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME     NULL,
  KEY ix_cs_mi_ms (milestone_id),
  CONSTRAINT fk_cs_mi_ms FOREIGN KEY (milestone_id) REFERENCES cs_milestones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CHANGE ORDERS AND CLAIMS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_change_orders;
CREATE TABLE cs_change_orders (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  co_no                 VARCHAR(30)  NOT NULL,
  project_id            INT UNSIGNED NOT NULL,
  contract_id           INT UNSIGNED NULL,
  co_type               VARCHAR(30)  NOT NULL,          -- client_request, additional_work, scope_reduction, design_change, material_change, quantity_adjustment, price_revision, time_extension, contractor_claim, delay_cost, dispute
  title                 VARCHAR(200) NOT NULL,
  reason                TEXT         NULL,
  description           TEXT         NULL,
  requested_by_party    VARCHAR(20)  NOT NULL DEFAULT 'client', -- client, contractor, consultant
  price_impact          DECIMAL(15,2) NOT NULL DEFAULT 0,  -- change in the contract value
  cost_impact           DECIMAL(15,2) NOT NULL DEFAULT 0,  -- change in the estimated cost
  labor_impact          VARCHAR(255) NULL,
  equipment_impact      VARCHAR(255) NULL,
  schedule_impact_days  INT          NOT NULL DEFAULT 0,
  billing_implications  TEXT         NULL,
  status                VARCHAR(20)  NOT NULL DEFAULT 'proposed', -- proposed, for_approval, internally_approved, client_approved, rejected, disputed, withdrawn
  internal_approved_by  INT UNSIGNED NULL,
  internal_approved_at  DATETIME     NULL,
  client_approved_date  DATE         NULL,
  client_ref            VARCHAR(60)  NULL,
  settlement_amount     DECIMAL(15,2) NULL,
  budget_id             INT UNSIGNED NULL,            -- budget revision created from it
  created_by            INT UNSIGNED NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_cs_co_no (co_no),
  KEY ix_cs_co_project (project_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_co_lines;
CREATE TABLE cs_co_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  co_id         INT UNSIGNED NOT NULL,
  line_no       INT          NOT NULL DEFAULT 0,
  description   VARCHAR(255) NOT NULL,
  unit          VARCHAR(20)  NULL,
  qty           DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_rate     DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,       -- price
  cost_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,       -- estimated cost
  cost_code_id  INT UNSIGNED NULL,
  KEY ix_cs_col_co (co_id),
  CONSTRAINT fk_cs_col_co FOREIGN KEY (co_id) REFERENCES cs_change_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  PROCUREMENT AND MATERIALS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_material_requests;
CREATE TABLE cs_material_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mr_no         VARCHAR(30)  NOT NULL,
  project_id    INT UNSIGNED NOT NULL,
  activity_id   INT UNSIGNED NULL,
  request_date  DATE         NOT NULL,
  needed_date   DATE         NULL,
  deliver_to    VARCHAR(12)  NOT NULL DEFAULT 'site',  -- site, warehouse
  purpose       VARCHAR(255) NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, rejected, partially_ordered, ordered, issued, closed, cancelled
  requested_by  INT UNSIGNED NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_mr_no (mr_no),
  KEY ix_cs_mr_project (project_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_mr_lines;
CREATE TABLE cs_mr_lines (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mr_id          INT UNSIGNED NOT NULL,
  line_no        INT          NOT NULL DEFAULT 0,
  item_id        INT UNSIGNED NULL,
  description    VARCHAR(255) NOT NULL,
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit           VARCHAR(20)  NULL,
  est_unit_cost  DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_approved   DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_ordered    DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_issued     DECIMAL(15,4) NOT NULL DEFAULT 0,
  cost_code_id   INT UNSIGNED NULL,
  KEY ix_cs_mrl_mr (mr_id),
  CONSTRAINT fk_cs_mrl_mr FOREIGN KEY (mr_id) REFERENCES cs_material_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_canvass;
CREATE TABLE cs_canvass (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  canvass_no        VARCHAR(30)  NOT NULL,
  mr_id             INT UNSIGNED NOT NULL,
  project_id        INT UNSIGNED NOT NULL,
  canvass_date      DATE         NOT NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'open', -- open, awarded, cancelled
  awarded_vendor_id INT UNSIGNED NULL,
  po_txn_id         BIGINT UNSIGNED NULL,
  award_reason      VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_canvass_no (canvass_no),
  KEY ix_cs_canvass_mr (mr_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_canvass_quotes;
CREATE TABLE cs_canvass_quotes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  canvass_id     INT UNSIGNED NOT NULL,
  vendor_id      INT UNSIGNED NOT NULL,
  quote_ref      VARCHAR(60)  NULL,
  quote_date     DATE         NULL,
  delivery_days  INT          NULL,
  payment_terms  VARCHAR(60)  NULL,
  valid_until    DATE         NULL,
  remarks        VARCHAR(255) NULL,
  is_awarded     TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_cs_cq_canvass (canvass_id),
  CONSTRAINT fk_cs_cq_canvass FOREIGN KEY (canvass_id) REFERENCES cs_canvass(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_canvass_prices;
CREATE TABLE cs_canvass_prices (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  quote_id    INT UNSIGNED NOT NULL,
  mr_line_id  INT UNSIGNED NOT NULL,
  unit_price  DECIMAL(15,4) NOT NULL DEFAULT 0,
  available   TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cs_cp (quote_id, mr_line_id),
  CONSTRAINT fk_cs_cp_quote FOREIGN KEY (quote_id) REFERENCES cs_canvass_quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_receiving_checks;
CREATE TABLE cs_receiving_checks (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id         BIGINT UNSIGNED NOT NULL,             -- the item receipt or bill that brought the delivery in
  txn_line_id    BIGINT UNSIGNED NULL,
  project_id     INT UNSIGNED NULL,
  item_id        INT UNSIGNED NULL,
  check_date     DATE         NOT NULL,
  qty_delivered  DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_accepted   DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_rejected   DECIMAL(15,4) NOT NULL DEFAULT 0,
  reject_reason  VARCHAR(255) NULL,
  inspected_by   INT UNSIGNED NULL,
  debit_memo_txn_id BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_rc_txn (txn_id),
  KEY ix_cs_rc_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_material_docs;
CREATE TABLE cs_material_docs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_no         VARCHAR(30)  NOT NULL,
  doc_type       VARCHAR(12)  NOT NULL,                -- issue, return, transfer, consume
  project_id     INT UNSIGNED NOT NULL,
  to_project_id  INT UNSIGNED NULL,                    -- transfers
  mr_id          INT UNSIGNED NULL,
  daily_report_id INT UNSIGNED NULL,
  site_id        INT UNSIGNED NULL,                    -- stock taken from (issue, consume) / returned to (return)
  to_site_id     INT UNSIGNED NULL,
  doc_date       DATE         NOT NULL,
  status         VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, posted, cancelled
  cost_policy    VARCHAR(12)  NULL,                    -- on_issue, on_consume (when it was posted)
  total_cost     DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id         BIGINT UNSIGNED NULL,                 -- the accounting entry it posted
  txn2_id        BIGINT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  requested_by   INT UNSIGNED NULL,
  approved_by    INT UNSIGNED NULL,
  posted_by      INT UNSIGNED NULL,
  posted_at      DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_md_no (doc_no),
  KEY ix_cs_md_project (project_id, doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_material_doc_lines;
CREATE TABLE cs_material_doc_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_id        INT UNSIGNED NOT NULL,
  line_no       INT          NOT NULL DEFAULT 0,
  item_id       INT UNSIGNED NOT NULL,
  description   VARCHAR(255) NULL,
  qty           DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_wasted    DECIMAL(15,4) NOT NULL DEFAULT 0,       -- consumption: part of qty that was waste / scrap
  unit          VARCHAR(20)  NULL,
  unit_cost     DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  cost_code_id  INT UNSIGNED NULL,
  activity_id   INT UNSIGNED NULL,
  mr_line_id    INT UNSIGNED NULL,
  KEY ix_cs_mdl_doc (doc_id),
  KEY ix_cs_mdl_item (item_id),
  CONSTRAINT fk_cs_mdl_doc FOREIGN KEY (doc_id) REFERENCES cs_material_docs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  WORKFORCE, ATTENDANCE AND LABOR COST
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_workers;
CREATE TABLE cs_workers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  worker_no           VARCHAR(30)  NOT NULL,
  full_name           VARCHAR(120) NOT NULL,
  other_name_id       INT UNSIGNED NULL,               -- the employee name in accounting (subsidiary ledger)
  classification      VARCHAR(20)  NOT NULL DEFAULT 'direct', -- direct, indirect, labor_only, outsourced, staff
  trade_id            INT UNSIGNED NULL,
  skills              VARCHAR(255) NULL,
  certifications      VARCHAR(255) NULL,
  rate_basis          VARCHAR(8)   NOT NULL DEFAULT 'daily', -- daily, hourly
  daily_rate          DECIMAL(15,2) NOT NULL DEFAULT 0,
  hourly_rate         DECIMAL(15,4) NOT NULL DEFAULT 0,
  ot_multiplier       DECIMAL(6,3) NOT NULL DEFAULT 1.250,
  holiday_multiplier  DECIMAL(6,3) NOT NULL DEFAULT 2.000,
  burden_pct          DECIMAL(7,4) NOT NULL DEFAULT 0,  -- labor burden (SSS, PhilHealth, Pag-IBIG, 13th month)
  allowance_daily     DECIMAL(15,2) NOT NULL DEFAULT 0,
  assigned_project_id INT UNSIGNED NULL,
  supervisor_id       INT UNSIGNED NULL,               -- another worker (foreman)
  vendor_id           INT UNSIGNED NULL,               -- agency or labor-only subcontractor
  shift_start         TIME         NULL,
  shift_end           TIME         NULL,
  break_minutes       INT          NOT NULL DEFAULT 60,
  phone               VARCHAR(40)  NULL,
  address             VARCHAR(255) NULL,
  date_hired          DATE         NULL,
  safety_docs         VARCHAR(255) NULL,
  status              VARCHAR(12)  NOT NULL DEFAULT 'active', -- active, inactive, terminated
  notes               VARCHAR(255) NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_worker_no (worker_no),
  KEY ix_cs_worker_project (assigned_project_id),
  KEY ix_cs_worker_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_timesheets;
CREATE TABLE cs_timesheets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts_no         VARCHAR(30)  NOT NULL,
  project_id    INT UNSIGNED NOT NULL,
  work_date     DATE         NOT NULL,
  shift         VARCHAR(12)  NOT NULL DEFAULT 'day',   -- day, night
  status        VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, rejected, posted
  total_hours   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cost    DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id        BIGINT UNSIGNED NULL,                  -- labor cost posting (journal)
  payroll_run_id INT UNSIGNED NULL,
  prepared_by   INT UNSIGNED NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_ts_no (ts_no),
  KEY ix_cs_ts_project (project_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_timesheet_lines;
CREATE TABLE cs_timesheet_lines (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  timesheet_id      INT UNSIGNED NOT NULL,
  worker_id         INT UNSIGNED NOT NULL,
  work_date         DATE         NOT NULL,
  activity_id       INT UNSIGNED NULL,
  cost_code_id      INT UNSIGNED NULL,
  time_in           TIME         NULL,
  time_out          TIME         NULL,
  break_minutes     INT          NOT NULL DEFAULT 0,
  regular_hours     DECIMAL(6,2) NOT NULL DEFAULT 0,
  ot_hours          DECIMAL(6,2) NOT NULL DEFAULT 0,
  late_minutes      INT          NOT NULL DEFAULT 0,
  undertime_minutes INT          NOT NULL DEFAULT 0,
  is_absent         TINYINT(1)   NOT NULL DEFAULT 0,
  is_holiday        TINYINT(1)   NOT NULL DEFAULT 0,
  hourly_rate       DECIMAL(15,4) NOT NULL DEFAULT 0,
  labor_cost        DECIMAL(15,2) NOT NULL DEFAULT 0,
  burden_cost       DECIMAL(15,2) NOT NULL DEFAULT 0,
  allowance         DECIMAL(15,2) NOT NULL DEFAULT 0,
  remarks           VARCHAR(255) NULL,
  correction_note   VARCHAR(255) NULL,
  KEY ix_cs_tsl_ts (timesheet_id),
  KEY ix_cs_tsl_worker (worker_id, work_date),
  CONSTRAINT fk_cs_tsl_ts FOREIGN KEY (timesheet_id) REFERENCES cs_timesheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_payroll_runs;
CREATE TABLE cs_payroll_runs (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_no           VARCHAR(30)  NOT NULL,
  period_from      DATE         NOT NULL,
  period_to        DATE         NOT NULL,
  pay_date         DATE         NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, posted, rejected, void
  total_gross      DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_burden     DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_net        DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id           BIGINT UNSIGNED NULL,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  posted_by        INT UNSIGNED NULL,
  posted_at        DATETIME     NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_pr_no (run_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_payroll_lines;
CREATE TABLE cs_payroll_lines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id          INT UNSIGNED NOT NULL,
  worker_id       INT UNSIGNED NOT NULL,
  days_worked     DECIMAL(6,2) NOT NULL DEFAULT 0,
  regular_hours   DECIMAL(9,2) NOT NULL DEFAULT 0,
  ot_hours        DECIMAL(9,2) NOT NULL DEFAULT 0,
  gross_pay       DECIMAL(15,2) NOT NULL DEFAULT 0,
  allowances      DECIMAL(15,2) NOT NULL DEFAULT 0,
  burden          DECIMAL(15,2) NOT NULL DEFAULT 0,
  advance_deduction DECIMAL(15,2) NOT NULL DEFAULT 0,
  other_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_pay         DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_cs_prl_run (run_id),
  CONSTRAINT fk_cs_prl_run FOREIGN KEY (run_id) REFERENCES cs_payroll_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_cash_advances;
CREATE TABLE cs_cash_advances (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ca_no           VARCHAR(30)  NOT NULL,
  worker_id       INT UNSIGNED NOT NULL,
  project_id      INT UNSIGNED NULL,
  advance_date    DATE         NOT NULL,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  purpose         VARCHAR(255) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'for_approval', -- for_approval, approved, released, deducted, rejected
  cash_account_id INT UNSIGNED NULL,
  txn_id          BIGINT UNSIGNED NULL,                -- the cash voucher that released it
  deducted        DECIMAL(15,2) NOT NULL DEFAULT 0,
  payroll_run_id  INT UNSIGNED NULL,
  approved_by     INT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_ca_no (ca_no),
  KEY ix_cs_ca_worker (worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  EQUIPMENT AND VEHICLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_equipment;
CREATE TABLE cs_equipment (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(30)  NOT NULL,
  name                VARCHAR(150) NOT NULL,
  equipment_type      VARCHAR(40)  NOT NULL,           -- excavator, crane, bulldozer, backhoe, mixer, generator, welder, truck, vehicle, other
  make_model          VARCHAR(120) NULL,
  plate_serial        VARCHAR(60)  NULL,
  ownership           VARCHAR(10)  NOT NULL DEFAULT 'owned', -- owned, rented, leased
  fixed_asset_id      INT UNSIGNED NULL,
  vendor_id           INT UNSIGNED NULL,               -- rental supplier
  costing_method      VARCHAR(12)  NOT NULL DEFAULT 'standard', -- standard (hourly charge-out rate), rental (supplier bills carry the cost), actual (rate plus fuel)
  hourly_rate         DECIMAL(15,2) NOT NULL DEFAULT 0, -- charge-out rate per operating hour
  idle_rate           DECIMAL(15,2) NOT NULL DEFAULT 0, -- charged per idle hour while deployed
  rental_basis        VARCHAR(8)   NULL,               -- hourly, daily, monthly
  rental_rate         DECIMAL(15,2) NOT NULL DEFAULT 0,
  acquisition_cost    DECIMAL(15,2) NOT NULL DEFAULT 0,
  meter_unit          VARCHAR(10)  NOT NULL DEFAULT 'hours',
  current_meter       DECIMAL(15,2) NOT NULL DEFAULT 0,
  fuel_type           VARCHAR(20)  NULL,
  operator_worker_id  INT UNSIGNED NULL,
  current_project_id  INT UNSIGNED NULL,
  insurance_expiry    DATE         NULL,
  inspection_due      DATE         NULL,
  registration_expiry DATE         NULL,
  next_service_meter  DECIMAL(15,2) NULL,
  status              VARCHAR(14)  NOT NULL DEFAULT 'available', -- available, deployed, under_repair, idle, off_hired, disposed
  notes               VARCHAR(255) NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_equip_code (code),
  KEY ix_cs_equip_project (current_project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_equipment_dispatch;
CREATE TABLE cs_equipment_dispatch (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispatch_no        VARCHAR(30)  NOT NULL,
  equipment_id       INT UNSIGNED NOT NULL,
  project_id         INT UNSIGNED NOT NULL,
  date_from          DATE         NOT NULL,
  date_to            DATE         NOT NULL,
  operator_worker_id INT UNSIGNED NULL,
  purpose            VARCHAR(255) NULL,
  status             VARCHAR(12)  NOT NULL DEFAULT 'scheduled', -- scheduled, dispatched, returned, cancelled
  meter_out          DECIMAL(15,2) NULL,
  meter_in           DECIMAL(15,2) NULL,
  returned_date      DATE         NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_disp_no (dispatch_no),
  KEY ix_cs_disp_equip (equipment_id, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_equipment_logs;
CREATE TABLE cs_equipment_logs (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  log_no             VARCHAR(30)  NOT NULL,
  equipment_id       INT UNSIGNED NOT NULL,
  project_id         INT UNSIGNED NOT NULL,
  activity_id        INT UNSIGNED NULL,
  cost_code_id       INT UNSIGNED NULL,
  log_date           DATE         NOT NULL,
  operating_hours    DECIMAL(9,2) NOT NULL DEFAULT 0,
  idle_hours         DECIMAL(9,2) NOT NULL DEFAULT 0,
  breakdown_hours    DECIMAL(9,2) NOT NULL DEFAULT 0,
  meter_start        DECIMAL(15,2) NULL,
  meter_end          DECIMAL(15,2) NULL,
  fuel_liters        DECIMAL(9,2) NOT NULL DEFAULT 0,
  fuel_cost          DECIMAL(15,2) NOT NULL DEFAULT 0,
  operator_worker_id INT UNSIGNED NULL,
  hourly_rate        DECIMAL(15,2) NOT NULL DEFAULT 0,
  charge_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, posted, rejected
  txn_id             BIGINT UNSIGNED NULL,
  approved_by        INT UNSIGNED NULL,
  remarks            VARCHAR(255) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_elog_no (log_no),
  KEY ix_cs_elog_equip (equipment_id, log_date),
  KEY ix_cs_elog_project (project_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  DAILY SITE REPORTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_daily_reports;
CREATE TABLE cs_daily_reports (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_no           VARCHAR(30)  NOT NULL,
  project_id          INT UNSIGNED NOT NULL,
  report_date         DATE         NOT NULL,
  weather_am          VARCHAR(30)  NULL,
  weather_pm          VARCHAR(30)  NULL,
  site_conditions     VARCHAR(255) NULL,
  workers_count       INT          NOT NULL DEFAULT 0,
  man_hours           DECIMAL(9,2) NOT NULL DEFAULT 0,
  equipment_used      VARCHAR(255) NULL,
  materials_used      TEXT         NULL,
  delays              TEXT         NULL,
  delay_hours         DECIMAL(6,2) NOT NULL DEFAULT 0,
  safety_observations TEXT         NULL,
  inspections         TEXT         NULL,
  instructions        TEXT         NULL,
  issues              TEXT         NULL,
  remarks             TEXT         NULL,
  status              VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, returned
  submitted_by        INT UNSIGNED NULL,
  submitted_at        DATETIME     NULL,
  approved_by         INT UNSIGNED NULL,
  approved_at         DATETIME     NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_dsr_no (report_no),
  UNIQUE KEY uq_cs_dsr_day (project_id, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_daily_report_lines;
CREATE TABLE cs_daily_report_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id     INT UNSIGNED NOT NULL,
  activity_id   INT UNSIGNED NULL,
  cost_code_id  INT UNSIGNED NULL,
  description   VARCHAR(255) NOT NULL,
  qty_done      DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit          VARCHAR(20)  NULL,
  workers       INT          NOT NULL DEFAULT 0,
  man_hours     DECIMAL(9,2) NOT NULL DEFAULT 0,
  remarks       VARCHAR(255) NULL,
  KEY ix_cs_drl_report (report_id),
  CONSTRAINT fk_cs_drl_report FOREIGN KEY (report_id) REFERENCES cs_daily_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SUBCONTRACTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_subcontracts;
CREATE TABLE cs_subcontracts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sc_no            VARCHAR(30)  NOT NULL,
  project_id       INT UNSIGNED NOT NULL,
  vendor_id        INT UNSIGNED NOT NULL,
  work_package     VARCHAR(200) NOT NULL,
  scope            TEXT         NULL,
  cost_code_id     INT UNSIGNED NULL,
  contract_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_variations DECIMAL(15,2) NOT NULL DEFAULT 0,
  retention_pct    DECIMAL(7,4) NOT NULL DEFAULT 0,
  advance_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  recoupment_pct   DECIMAL(7,4) NOT NULL DEFAULT 0,
  tax_code_id      INT UNSIGNED NULL,
  start_date       DATE         NULL,
  end_date         DATE         NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, active, completed, closed, terminated, rejected
  terms            TEXT         NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME     NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_sc_no (sc_no),
  KEY ix_cs_sc_project (project_id),
  KEY ix_cs_sc_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_subcontract_items;
CREATE TABLE cs_subcontract_items (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subcontract_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  description     VARCHAR(255) NOT NULL,
  unit            VARCHAR(20)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_rate       DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  cost_code_id    INT UNSIGNED NULL,
  activity_id     INT UNSIGNED NULL,
  KEY ix_cs_sci_sc (subcontract_id),
  CONSTRAINT fk_cs_sci_sc FOREIGN KEY (subcontract_id) REFERENCES cs_subcontracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_sub_billings;
CREATE TABLE cs_sub_billings (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sb_no             VARCHAR(30)  NOT NULL,
  subcontract_id    INT UNSIGNED NOT NULL,
  project_id        INT UNSIGNED NOT NULL,
  vendor_id         INT UNSIGNED NOT NULL,
  kind              VARCHAR(20)  NOT NULL DEFAULT 'progress', -- progress, final, retention_release
  invoice_no        VARCHAR(60)  NOT NULL,
  billing_date      DATE         NOT NULL,
  period_from       DATE         NULL,
  period_to         DATE         NULL,
  gross_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,  -- accomplishment this billing
  prev_cumulative   DECIMAL(15,2) NOT NULL DEFAULT 0,
  cumulative_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  retention_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  advance_recoup    DECIMAL(15,2) NOT NULL DEFAULT 0,
  back_charges      DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalties         DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  status            VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, posted, rejected, cancelled
  bill_txn_id       BIGINT UNSIGNED NULL,
  submitted_via     VARCHAR(10)  NOT NULL DEFAULT 'office', -- office, portal
  certified_by      INT UNSIGNED NULL,
  approved_by       INT UNSIGNED NULL,
  notes             VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_sb_no (sb_no),
  UNIQUE KEY uq_cs_sb_invoice (vendor_id, invoice_no),
  KEY ix_cs_sb_sc (subcontract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_sub_billing_lines;
CREATE TABLE cs_sub_billing_lines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sb_id           INT UNSIGNED NOT NULL,
  sc_item_id      INT UNSIGNED NOT NULL,
  prev_qty        DECIMAL(15,4) NOT NULL DEFAULT 0,
  this_qty        DECIMAL(15,4) NOT NULL DEFAULT 0,
  this_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_cs_sbl_sb (sb_id),
  CONSTRAINT fk_cs_sbl_sb FOREIGN KEY (sb_id) REFERENCES cs_sub_billings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  PROGRESS BILLING AND REVENUE RECOGNITION
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_billings;
CREATE TABLE cs_billings (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  billing_no              VARCHAR(30)  NOT NULL,
  project_id              INT UNSIGNED NOT NULL,
  contract_id             INT UNSIGNED NULL,
  customer_id             INT UNSIGNED NOT NULL,
  billing_type            VARCHAR(20)  NOT NULL DEFAULT 'progress', -- progress, milestone, quantity, tm, recurring, final, retention_release, change_order, advance
  billing_date            DATE         NOT NULL,
  period_from             DATE         NULL,
  period_to               DATE         NULL,
  original_contract       DECIMAL(15,2) NOT NULL DEFAULT 0,
  revised_contract        DECIMAL(15,2) NOT NULL DEFAULT 0,
  prev_gross              DECIMAL(15,2) NOT NULL DEFAULT 0,  -- previously certified accomplishment
  this_gross              DECIMAL(15,2) NOT NULL DEFAULT 0,  -- verified accomplishment this period
  cumulative_gross        DECIMAL(15,2) NOT NULL DEFAULT 0,
  materials_stored        DECIMAL(15,2) NOT NULL DEFAULT 0,
  retention_pct           DECIMAL(7,4) NOT NULL DEFAULT 0,
  retention_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  advance_recoupment      DECIMAL(15,2) NOT NULL DEFAULT 0,
  other_deductions        DECIMAL(15,2) NOT NULL DEFAULT 0,
  deductions_note         VARCHAR(255) NULL,
  vat_amount              DECIMAL(15,2) NOT NULL DEFAULT 0,
  wht_expected            DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_applied          DECIMAL(15,2) NOT NULL DEFAULT 0,  -- total for certification (what is applied for)
  certified_amount        DECIMAL(15,2) NULL,                -- what the client certified (gross of VAT, net of retention)
  certified_date          DATE         NULL,
  certificate_ref         VARCHAR(60)  NULL,
  invoice_txn_id          BIGINT UNSIGNED NULL,
  status                  VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft, for_approval, approved, submitted, certified, invoiced, returned, rejected, cancelled
  overbill_override       VARCHAR(255) NULL,                 -- the authorized contractual adjustment that allowed going over a line
  notes                   TEXT         NULL,
  created_by              INT UNSIGNED NULL,
  created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NULL,
  UNIQUE KEY uq_cs_bill_no (billing_no),
  KEY ix_cs_bill_project (project_id, status),
  KEY ix_cs_bill_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_billing_lines;
CREATE TABLE cs_billing_lines (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  billing_id       INT UNSIGNED NOT NULL,
  boq_item_id      INT UNSIGNED NULL,
  milestone_id     INT UNSIGNED NULL,
  description      VARCHAR(255) NOT NULL,
  unit             VARCHAR(20)  NULL,
  scheduled_value  DECIMAL(15,2) NOT NULL DEFAULT 0,
  contract_qty     DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_rate        DECIMAL(15,4) NOT NULL DEFAULT 0,
  prev_qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  this_qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  prev_pct         DECIMAL(9,4) NOT NULL DEFAULT 0,
  this_pct         DECIMAL(9,4) NOT NULL DEFAULT 0,
  prev_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  this_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_cs_bil_billing (billing_id),
  KEY ix_cs_bil_boq (boq_item_id),
  CONSTRAINT fk_cs_bil_billing FOREIGN KEY (billing_id) REFERENCES cs_billings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_revenue_recognitions;
CREATE TABLE cs_revenue_recognitions (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rr_no             VARCHAR(30)  NOT NULL,
  project_id        INT UNSIGNED NOT NULL,
  period_end        DATE         NOT NULL,
  method            VARCHAR(14)  NOT NULL,             -- poc_cost, poc_physical, completed
  contract_value    DECIMAL(15,2) NOT NULL DEFAULT 0,
  est_total_cost    DECIMAL(15,2) NOT NULL DEFAULT 0,
  cost_to_date      DECIMAL(15,2) NOT NULL DEFAULT 0,
  pct_complete      DECIMAL(9,4) NOT NULL DEFAULT 0,
  earned_to_date    DECIMAL(15,2) NOT NULL DEFAULT 0,
  prev_recognized   DECIMAL(15,2) NOT NULL DEFAULT 0,
  this_period       DECIMAL(15,2) NOT NULL DEFAULT 0,
  loss_provision    DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id            BIGINT UNSIGNED NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'draft',  -- draft, for_approval, posted, rejected, reversed
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_rr_no (rr_no),
  KEY ix_cs_rr_project (project_id, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SHARED COST ALLOCATION
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_alloc_rules;
CREATE TABLE cs_alloc_rules (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  method       VARCHAR(20)  NOT NULL,                  -- equal, fixed_pct, man_hours, equipment_hours, material_cost, direct_cost, contract_value, revenue, floor_area, custom
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_alloc_rule_shares;
CREATE TABLE cs_alloc_rule_shares (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rule_id     INT UNSIGNED NOT NULL,
  project_id  INT UNSIGNED NOT NULL,
  share_value DECIMAL(15,4) NOT NULL DEFAULT 0,        -- percentage (fixed_pct) or units (custom)
  UNIQUE KEY uq_cs_ars (rule_id, project_id),
  CONSTRAINT fk_cs_ars_rule FOREIGN KEY (rule_id) REFERENCES cs_alloc_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_allocations;
CREATE TABLE cs_allocations (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alloc_no          VARCHAR(30)  NOT NULL,
  alloc_date        DATE         NOT NULL,
  period_from       DATE         NULL,                 -- the period the basis is measured over
  period_to         DATE         NULL,
  source_txn_id     BIGINT UNSIGNED NULL,              -- the original expense (bill, check, cash voucher, journal)
  source_account_id INT UNSIGNED NOT NULL,             -- the expense account being allocated
  source_class_id   INT UNSIGNED NULL,                 -- the class it sits under now (blank = no class)
  description       VARCHAR(255) NOT NULL,
  amount            DECIMAL(15,2) NOT NULL DEFAULT 0,
  method            VARCHAR(20)  NOT NULL,
  rule_id           INT UNSIGNED NULL,
  cost_code_id      INT UNSIGNED NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'draft', -- draft, for_approval, posted, reversed, rejected
  txn_id            BIGINT UNSIGNED NULL,              -- the reclassification journal
  replaces_id       INT UNSIGNED NULL,                 -- a reallocation: the allocation it corrects
  reversal_reason   VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  approved_by       INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_alloc_no (alloc_no),
  KEY ix_cs_alloc_src (source_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_allocation_lines;
CREATE TABLE cs_allocation_lines (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  allocation_id  INT UNSIGNED NOT NULL,
  project_id     INT UNSIGNED NOT NULL,
  basis_value    DECIMAL(18,4) NOT NULL DEFAULT 0,
  pct            DECIMAL(9,4) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_cs_all_alloc (allocation_id),
  KEY ix_cs_all_project (project_id),
  CONSTRAINT fk_cs_all_alloc FOREIGN KEY (allocation_id) REFERENCES cs_allocations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  QUALITY, SAFETY, CLOSE-OUT AND WARRANTY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS cs_inspections;
CREATE TABLE cs_inspections (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  insp_no         VARCHAR(30)  NOT NULL,
  project_id      INT UNSIGNED NOT NULL,
  activity_id     INT UNSIGNED NULL,
  kind            VARCHAR(20)  NOT NULL,               -- itp, checklist, material_test, equipment_safety, pre_pour, final
  title           VARCHAR(200) NOT NULL,
  inspection_date DATE         NOT NULL,
  location        VARCHAR(150) NULL,
  inspector       VARCHAR(120) NULL,
  checklist       TEXT         NULL,                   -- "item|pass" per line
  findings        TEXT         NULL,
  result          VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending, passed, failed, conditional
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_insp_no (insp_no),
  KEY ix_cs_insp_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_ncrs;
CREATE TABLE cs_ncrs (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ncr_no            VARCHAR(30)  NOT NULL,
  project_id        INT UNSIGNED NOT NULL,
  activity_id       INT UNSIGNED NULL,
  cost_code_id      INT UNSIGNED NULL,
  inspection_id     INT UNSIGNED NULL,
  title             VARCHAR(200) NOT NULL,
  description       TEXT         NULL,
  raised_date       DATE         NOT NULL,
  responsible_party VARCHAR(150) NULL,
  root_cause        TEXT         NULL,
  corrective_action TEXT         NULL,
  preventive_action TEXT         NULL,
  rework_cost       DECIMAL(15,2) NOT NULL DEFAULT 0,
  due_date          DATE         NULL,
  closed_date       DATE         NULL,
  status            VARCHAR(14)  NOT NULL DEFAULT 'open', -- open, in_progress, verified, closed
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_ncr_no (ncr_no),
  KEY ix_cs_ncr_project (project_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_safety_records;
CREATE TABLE cs_safety_records (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref_no            VARCHAR(30)  NOT NULL,
  project_id        INT UNSIGNED NOT NULL,
  kind              VARCHAR(20)  NOT NULL,             -- incident, near_miss, toolbox, training, ppe, permit, hazard, equipment_check
  record_date       DATE         NOT NULL,
  title             VARCHAR(200) NOT NULL,
  description       TEXT         NULL,
  persons           VARCHAR(255) NULL,
  severity          VARCHAR(10)  NULL,                 -- low, medium, high, critical
  attendees         INT          NOT NULL DEFAULT 0,
  lost_time_hours   DECIMAL(9,2) NOT NULL DEFAULT 0,
  corrective_action TEXT         NULL,
  valid_until       DATE         NULL,                 -- permits and trainings
  status            VARCHAR(10)  NOT NULL DEFAULT 'open', -- open, closed
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cs_sr_no (ref_no),
  KEY ix_cs_sr_project (project_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_closeout_items;
CREATE TABLE cs_closeout_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id     INT UNSIGNED NOT NULL,
  line_no        INT          NOT NULL DEFAULT 0,
  item           VARCHAR(200) NOT NULL,
  status         VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending, in_progress, completed, waived
  completed_date DATE         NULL,
  remarks        VARCHAR(255) NULL,
  updated_by     INT UNSIGNED NULL,
  KEY ix_cs_co_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cs_punch_items;
CREATE TABLE cs_punch_items (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id        INT UNSIGNED NOT NULL,
  ref_no            VARCHAR(30)  NOT NULL,
  kind              VARCHAR(10)  NOT NULL DEFAULT 'punch', -- punch, defect
  location          VARCHAR(150) NULL,
  description       VARCHAR(255) NOT NULL,
  responsible_party VARCHAR(150) NULL,
  reported_date     DATE         NOT NULL,
  due_date          DATE         NULL,
  severity          VARCHAR(10)  NULL,
  warranty_covered  TINYINT(1)   NOT NULL DEFAULT 0,
  cost              DECIMAL(15,2) NOT NULL DEFAULT 0,
  status            VARCHAR(12)  NOT NULL DEFAULT 'open', -- open, in_progress, rectified, verified, closed
  closed_date       DATE         NULL,
  remarks           VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_punch_project (project_id, kind, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. COMPANY DATABASE: DATA
-- ---------------------------------------------------------------------

-- settings (60 rows)
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('adopt_group_price','1','2026-10-23 20:35:32'),
('allow_negative_stock','0','2026-10-23 20:35:32'),
('audit_retention_years','10','2026-10-23 20:35:32'),
('bir_signatory_name','','2026-10-23 20:35:32'),
('bir_signatory_tin','','2026-10-23 20:35:32'),
('bir_signatory_title','','2026-10-23 20:35:32'),
('bir_zip_code','','2026-10-23 20:35:32'),
('block_zero_cost_processing','1','2026-10-23 20:35:32'),
('block_zero_cost_sales','1','2026-10-23 20:35:32'),
('bt_printer_autoprint','0','2026-10-23 20:35:32'),
('bt_printer_cut','0','2026-10-23 20:35:32'),
('bt_printer_enabled','0','2026-10-23 20:35:32'),
('bt_printer_feed','4','2026-10-23 20:35:32'),
('bt_printer_name','','2026-10-23 20:35:32'),
('bt_printer_width','32','2026-10-23 20:35:32'),
('closing_date','','2026-10-23 20:35:32'),
('company_address','','2026-10-23 20:35:34'),
('company_email','','2026-10-23 20:35:34'),
('company_logo','','2026-10-23 20:35:32'),
('company_name','Your Construction Company, Inc.','2026-10-23 20:35:34'),
('company_phone','','2026-10-23 20:35:34'),
('company_tin','','2026-10-23 20:35:34'),
('costing_method','weighted_average','2026-10-23 20:35:32'),
('cs_approval_overdue_hours','48','2026-10-23 20:35:32'),
('cs_billing_vat_base','gross_less_recoupment','2026-10-23 20:35:32'),
('cs_budget_control','warn','2026-10-23 20:35:32'),
('cs_material_policy','on_issue','2026-10-23 20:35:32'),
('cs_max_daily_hours','16','2026-10-23 20:35:32'),
('cs_overbilling','block','2026-10-23 20:35:32'),
('cs_project_access','members','2026-10-23 20:35:32'),
('cs_project_code_prefix','PRJ-','2026-10-23 20:35:32'),
('cs_regular_hours','8','2026-10-23 20:35:32'),
('cs_retention_default','10','2026-10-23 20:35:32'),
('cs_revenue_method','poc_cost','2026-10-23 20:35:32'),
('cs_self_approval','0','2026-10-23 20:35:32'),
('finance_charge_grace_days','0','2026-10-23 20:35:32'),
('finance_charge_min','50','2026-10-23 20:35:32'),
('finance_charge_on_charges','0','2026-10-23 20:35:32'),
('finance_charge_rate','0','2026-10-23 20:35:32'),
('fiscal_year_start_month','1','2026-10-23 20:35:32'),
('item_search_by_page','','2026-10-23 20:35:32'),
('item_search_format','code_name','2026-10-23 20:35:32'),
('large_discount_pct','10','2026-10-23 20:35:32'),
('logo_targets','','2026-10-23 20:35:32'),
('max_failed_logins','5','2026-10-23 20:35:32'),
('pos_posting_mode','per_sale','2026-10-23 20:35:32'),
('pos_receipt_footer','Thank you!\nThis serves as your official receipt.','2026-10-23 20:35:32'),
('pos_receipt_show_customer','1','2026-10-23 20:35:32'),
('pos_receipt_title','OFFICIAL RECEIPT','2026-10-23 20:35:32'),
('pos_receipt_title_charge','CHARGE INVOICE','2026-10-23 20:35:32'),
('pos_receipt_title_payment','ACKNOWLEDGEMENT RECEIPT','2026-10-23 20:35:32'),
('pos_receipt_title_return','RETURN / REFUND','2026-10-23 20:35:32'),
('pw_expiry_days','90','2026-10-23 20:35:32'),
('pw_min_length','8','2026-10-23 20:35:32'),
('pw_require_number','1','2026-10-23 20:35:32'),
('pw_require_symbol','1','2026-10-23 20:35:32'),
('pw_require_upper','1','2026-10-23 20:35:32'),
('session_timeout_minutes','30','2026-10-23 20:35:32'),
('track_group_sales','1','2026-10-23 20:35:32'),
('withholding_agent','1','2026-10-23 20:35:32');

-- users (26 rows)
INSERT INTO `users` (`id`,`username`,`password_hash`,`full_name`,`email`,`employee_no`,`pos_default_site_id`,`pos_default_class_id`,`status`,`approval_status`,`created_via`,`approved_by`,`approved_at`,`rejected_reason`,`is_platform`,`portal_customer_id`,`portal_vendor_id`,`all_projects`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'subcon','$2y$10$GM1KEynlI7tU84NU8CHM3.Kt4q4yq1KLXvr0RjAxbDLgoupDv5nmO','Allan Torres','subcon@samplebuilders.test','EMP-001',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,0,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(2,'client','$2y$10$KbXJaxLfTYKcfaS4KJe4.OoxL.MDEfbuHBhzdper5KQ8V8QCES.xe','Atty. Ramon Velasco','client@samplebuilders.test','EMP-002',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,0,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(3,'timekeeper','$2y$10$Tx40RORe05aSKbRma9BdOeiNK4Rz.jYWnkw7RNxvJTiYdmlUKUd0q','Pedro Castillo','timekeeper@samplebuilders.test','EMP-003',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(4,'foreman','$2y$10$meiZBWqJliHM4f6rqOwmaucXl.MVa1OAelor9WVU4gGmPZwBuaEFC','Nestor Ramos','foreman@samplebuilders.test','EMP-004',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,0,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(5,'warehouse','$2y$10$ZcwxwaionYPmkb5n9yDtqe1oTbhOc4591SmyBpI1EADsRySPNS23y','Dan Villanueva','warehouse@samplebuilders.test','EMP-005',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(6,'cashier','$2y$10$LcphZCt/3Peik4AieB3RPOauz1w386qRP9HL8BYVQQ2mqlPGxq0CK','Ella Ramos','cashier@samplebuilders.test','EMP-006',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(7,'safety','$2y$10$k1sxcRu62q9GMYdpiKJ/5uDVwZZ3KmzStNIeMhwt.g1rA1AHKpZ7y','Jose Mercado','safety@samplebuilders.test','EMP-007',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(8,'qa','$2y$10$YSLWMIH3Uv.Xekv8cyEkrOhyueo6ssJ9f0wHG.d2wUaU/qgWPx0ti','Engr. Paolo Garcia','qa@samplebuilders.test','EMP-008',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(9,'billing','$2y$10$DNKMso7U.Vqu0bKfvDJFAOUCsIl7lE9m3VE074gAEUKIXXGHzHb6K','Maria Santos','billing@samplebuilders.test','EMP-009',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(10,'acctstaff','$2y$10$vJ833YjvxPXxGeZN03IMneuX5hoY4eI6.IV4QQOSugYCi9GYXfOMi','Ben Garcia','acctstaff@samplebuilders.test','EMP-010',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(11,'procurement','$2y$10$cTJZ34gJI3eZua6d5slJhuXitFEIWO4CoOMUU9EqXwjm9SO0p2N22','Carla Lim','procurement@samplebuilders.test','EMP-011',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:33',0,NULL,NULL,NULL,'2026-10-23 20:35:33',NULL),
(12,'hr','$2y$10$g8umpuwsKzki9woIXR5AAuxAtgrR5ES53lD9MMvwngorK2jxtodU.','Angela Torres','hr@samplebuilders.test','EMP-012',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(13,'equipmgr','$2y$10$yJU0UgQiEC8niRH4o6VEtuIwzpH4KBb9sRoj6jbyG9CK1UpORHehq','Rogelio Navarro','equipmgr@samplebuilders.test','EMP-013',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(14,'siteeng','$2y$10$pNibxvVrhICGVd3ZNE879.QzSGALwCroFUOiW0jQPAMWR7U.2h9le','Engr. Mark Dizon','siteeng@samplebuilders.test','EMP-014',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,0,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(15,'qs','$2y$10$hdCnV9LcLc4OnKyyua7UQ.QGLUiNE6TAqrvIwQysfoL896fz2uVuS','Joy Fernandez','qs@samplebuilders.test','EMP-015',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(16,'auditor','$2y$10$aAgZU9fY5sjVjw/VDsS8huQfiqbxG7M6vOMMzU4Qi2leFs0lRrFwu','Grace Uy','auditor@samplebuilders.test','EMP-016',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(17,'purchasing','$2y$10$GaYdMCOU0yYteZhqQp.Tg.yIa5VEbg243u7rYyt1VClCd/BUM46yi','Victor Chua','purchasing@samplebuilders.test','EMP-017',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(18,'controller','$2y$10$/waiCEAoi5KKjrHLgLd.deY/xPCt1fC5luuepzYFSPJson.B.GEGm','Ana Cruz, CPA','controller@samplebuilders.test','EMP-018',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(19,'pm','$2y$10$i4unlevDMvntWA0lsp/5x.aurRUB0amXuXl0/O6j0GBow1MmvIfte','Engr. Carlo Reyes','pm@samplebuilders.test','EMP-019',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,0,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(20,'pm2','$2y$10$r64bY4nijrl3fpmYYmt4KutjBTK1nitZUJbotwZgnU6vX1BsU0CYS','Engr. Liza Bautista','pm2@samplebuilders.test','EMP-020',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,0,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(21,'finmgr','$2y$10$FW6cQdJ7zDw/s2xCbilGL.b1fr2FoKPvqzTk5YbcCOwugpsRO/YIS','Michelle Tan','finmgr@samplebuilders.test','EMP-021',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(22,'projdir','$2y$10$NQxQEgJLg2XLI/DlJXE14O5kGSAsNR0c5PpF0lwyu4ILfv5fNZtRa','Engr. Ramon Aquino','projdir@samplebuilders.test','EMP-022',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(23,'opsmgr','$2y$10$J5ZlK1V2HLrPP8YNSUOogeYWR1fgoiAXksoqk3tnRnOPf7TE6X3Qm','Teresa Mendoza','opsmgr@samplebuilders.test','EMP-023',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(24,'gm','$2y$10$et5fdPzcu/A4UQGtW4yTKuEXOoO5d8iS7xDRpUHEV4T8YzmTCvzIG','Ricardo Villanueva','gm@samplebuilders.test','EMP-024',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(25,'owner','$2y$10$SKYPf45wR2ICVGUMdH2ZWu5jt0/Gr61nMsqTiWPq0mbh6Jef0aHPm','Eduardo Santos','owner@samplebuilders.test','EMP-025',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL),
(26,'admin','$2y$10$eMsBL7QezQngRvhfQ2WWrOV7V20ZyY.txIj68tGwKE2dRLdXi8eJu','System Administrator','admin@samplebuilders.test','EMP-026',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,NULL,NULL,1,0,'2026-10-23 20:35:34',0,NULL,NULL,NULL,'2026-10-23 20:35:34',NULL);

-- roles (25 rows)
INSERT INTO `roles` (`id`,`name`,`description`,`is_admin`,`is_system`,`all_sites`,`created_at`,`updated_at`) VALUES
(1,'Company Administrator','Everything in every module, including user accounts, roles, page access, construction and company settings, and the closing date.',1,1,1,'2026-10-23 20:35:33',NULL),
(2,'Business Owner / President','Sees every project, report and transaction and gives the final executive approvals. (The Company Owner account has full authority in any case.)',0,1,1,'2026-10-23 20:35:33',NULL),
(3,'General Manager','Runs the company day to day: every project, contract and report, and the authorized-approver step of contracts, billings, change orders and large purchases.',0,1,1,'2026-10-23 20:35:33',NULL),
(4,'Operations Manager','All field operations across projects: site reports, workforce, equipment, procurement and subcontracts, with approval rights on them.',0,1,1,'2026-10-23 20:35:33',NULL),
(5,'Project Director','Directs a portfolio of projects: contracts, budgets, schedules and change orders, and the director-level approvals.',0,1,1,'2026-10-23 20:35:33',NULL),
(6,'Project Manager','Runs the projects assigned to them: budgets, schedule, materials, workforce, equipment, subcontracts and progress billings, and approves their project\'s documents.',0,1,1,'2026-10-23 20:35:33',NULL),
(7,'Site Engineer','Daily site reports and accomplishments, inspections, RFIs, material requests and issues, timesheets and equipment logs of their projects; prepares the progress billing.',0,1,1,'2026-10-23 20:35:33',NULL),
(8,'Quantity Surveyor','Estimates, BOQ and schedule of values, budgets, change orders, and the measurement of progress and subcontractor billings.',0,1,1,'2026-10-23 20:35:33',NULL),
(9,'Site Supervisor / Foreman','Crew attendance, daily site reports, material requests and equipment logs on site.',0,1,1,'2026-10-23 20:35:33',NULL),
(10,'Human Resources Officer','Worker records, trades and rates, cash advances, and the man-hour and labor cost reports.',0,1,1,'2026-10-23 20:35:33',NULL),
(11,'Timekeeper','Records daily time in and time out of the workers.',0,1,1,'2026-10-23 20:35:33',NULL),
(12,'Equipment Manager','The equipment register, dispatch and booking, usage logs and charges, preventive maintenance and repairs.',0,1,1,'2026-10-23 20:35:33',NULL),
(13,'Warehouse Officer','Receiving and inspection of deliveries, stock, issues, returns and transfers of materials to and between projects.',0,1,1,'2026-10-23 20:35:33',NULL),
(14,'Procurement Officer','Canvass and supplier quotations, purchase orders, supplier and subcontractor records.',0,1,1,'2026-10-23 20:35:33',NULL),
(15,'Purchasing Manager','Approves material requests and purchases, awards canvasses, and manages suppliers and subcontract agreements.',0,1,1,'2026-10-23 20:35:33',NULL),
(16,'Accounting Staff','Day-to-day bookkeeping: bills, checks, receipts, deposits, journal entries, cost code assignment and payroll postings.',0,1,1,'2026-10-23 20:35:33',NULL),
(17,'Chief Accountant / Controller','The books: chart of accounts, period closing, revenue recognition, cost allocation, payroll posting, BIR reports and every financial and project report.',0,1,1,'2026-10-23 20:35:33',NULL),
(18,'Finance Manager','Cash, collections, payments and project finances: approves billings, payments, budgets and allocations, and watches receivables, payables and cash flow.',0,1,1,'2026-10-23 20:35:33',NULL),
(19,'Billing Officer','Progress billing applications, invoices and statements of account, retention and billing reports.',0,1,1,'2026-10-23 20:35:33',NULL),
(20,'Collection Officer / Cashier','Receives client payments and issues official receipts, makes deposits, and works the yard sales counter.',0,1,1,'2026-10-23 20:35:33',NULL),
(21,'Safety Officer','Safety records: incidents, near misses, toolbox meetings, training, PPE, permits to work and hazard assessments; equipment safety inspections.',0,1,1,'2026-10-23 20:35:33',NULL),
(22,'Quality Inspector','Inspection and test plans, quality checklists, material tests, nonconformance reports and corrective actions.',0,1,1,'2026-10-23 20:35:33',NULL),
(23,'Internal Auditor','View-only access to every record, report and the audit trail. Cannot create, change or approve anything.',0,1,1,'2026-10-23 20:35:33',NULL),
(24,'External Client / Client Representative','Portal: the progress, milestones, billings, statements and documents of the client\'s own projects.',0,1,1,'2026-10-23 20:35:33',NULL),
(25,'Subcontractor Portal User','Portal: the subcontractor\'s own agreements, progress claims, retention and payments.',0,1,1,'2026-10-23 20:35:33',NULL);

-- user_roles (26 rows)
INSERT INTO `user_roles` (`user_id`,`role_id`) VALUES
(1,25),
(2,24),
(3,11),
(4,9),
(5,13),
(6,20),
(7,21),
(8,22),
(9,19),
(10,16),
(11,14),
(12,10),
(13,12),
(14,7),
(15,8),
(16,23),
(17,15),
(18,17),
(19,6),
(20,6),
(21,18),
(22,5),
(23,4),
(24,3),
(25,2),
(26,1);

-- modules (23 rows)
INSERT INTO `modules` (`id`,`code`,`name`,`icon`,`sort_order`) VALUES
(1,'home','Home','house',1),
(2,'coa','Financial Set-up & Reports','journal-bookmark',2),
(3,'fa','Fixed Assets','building',3),
(4,'cust','Customers','people',4),
(5,'vend','Vendors','truck',5),
(6,'inv','Inventory','box-seam',6),
(7,'pos','Point of Sale','cart',7),
(8,'bank','Banking','bank',8),
(9,'bir','BIR Reports','file-earmark-ruled',9),
(10,'admin','System Administration','shield-lock',10),
(11,'company','Company','buildings',11),
(12,'proj','Projects','buildings',20),
(13,'plan','Planning & Estimating','rulers',21),
(14,'site','Site Operations','clipboard2-check',22),
(15,'labor','Workforce','people',23),
(16,'equip','Equipment','truck-front',24),
(17,'proc','Procurement & Materials','cart-check',25),
(18,'subk','Subcontracts','person-gear',26),
(19,'pbill','Billing & Revenue','receipt-cutoff',27),
(20,'cost','Cost Control','pie-chart',28),
(21,'appr','Approvals','check2-square',29),
(22,'creports','Project Reports','graph-up-arrow',30),
(23,'portal','Portals','door-open',31);

-- pages (288 rows)
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(1,'home.dashboard',1,'Home Dashboard','center','Home',10,1,NULL),
(2,'home.reports',1,'Report Center','center','Home',20,1,NULL),
(3,'home.memorized',1,'Memorized Reports','list','Home',30,1,NULL),
(4,'home.report_layouts',1,'Saved Report Layouts','list','Home',40,1,NULL),
(5,'home.search',1,'Search','center','Home',50,0,NULL),
(6,'home.find',1,'Find','center','Home',60,1,NULL),
(7,'rpt.find',1,'Find Results','report','Reports',70,0,NULL),
(8,'coa.accounts',2,'Chart of Accounts','list','Lists',80,1,NULL),
(9,'coa.classes',2,'Class List','list','Lists',90,1,NULL),
(10,'coa.names',2,'Employees & Other Names','list','Lists',100,1,NULL),
(11,'coa.memorized_txns',2,'Memorized Transactions','list','Lists',110,1,NULL),
(12,'coa.budgets',2,'Set Up Budgets','setup','Setup',120,1,NULL),
(13,'coa.journal',2,'Make Journal Entries','transaction','Transactions',130,1,NULL),
(14,'coa.default_accounts',2,'Module Default Accounts','setup','Setup',140,1,NULL),
(15,'coa.lists',2,'Terms, Tax Codes & Other Lists','setup','Setup',150,1,NULL),
(16,'rpt.trial_balance',2,'Trial Balance','report','Reports',160,1,NULL),
(17,'rpt.balance_sheet',2,'Balance Sheet','report','Reports',170,1,NULL),
(18,'rpt.balance_sheet_class',2,'Balance Sheet by Class','report','Reports',180,1,NULL),
(19,'rpt.trial_balance_range',2,'Trial Balance by Date Range','report','Reports',190,1,NULL),
(20,'rpt.trial_balance_totals',2,'Trial Balance of Totals','report','Reports',200,1,NULL),
(21,'rpt.balance_sheet_range',2,'Balance Sheet by Date Range','report','Reports',210,1,NULL),
(22,'rpt.budget_income',2,'Income Statement vs Budget','report','Reports',220,1,NULL),
(23,'rpt.budget_balance',2,'Balance Sheet vs Budget','report','Reports',230,1,NULL),
(24,'rpt.income_statement',2,'Income Statement','report','Reports',240,1,NULL),
(25,'rpt.income_statement_class',2,'Income Statement by Class','report','Reports',250,1,NULL),
(26,'rpt.cmp_is_month',2,'Comparative Income Statement by Month','report','Reports',260,1,NULL),
(27,'rpt.cmp_is_quarter',2,'Comparative Income Statement by Quarter','report','Reports',270,1,NULL),
(28,'rpt.cmp_is_year',2,'Comparative Income Statement by Year','report','Reports',280,1,NULL),
(29,'rpt.cmp_is_custom',2,'Comparative Income Statement by Custom Range','report','Reports',290,1,NULL),
(30,'rpt.cmp_bs_month',2,'Comparative Balance Sheet by Month','report','Reports',300,1,NULL),
(31,'rpt.cmp_bs_quarter',2,'Comparative Balance Sheet by Quarter','report','Reports',310,1,NULL),
(32,'rpt.cmp_bs_year',2,'Comparative Balance Sheet by Year','report','Reports',320,1,NULL),
(33,'rpt.cmp_bs_custom',2,'Comparative Balance Sheet by Custom Date','report','Reports',330,1,NULL),
(34,'rpt.cash_flow',2,'Statement of Cash Flows','report','Reports',340,1,NULL),
(35,'rpt.general_ledger',2,'General Ledger','report','Reports',350,1,NULL),
(36,'rpt.subsidiary_ledger',2,'Subsidiary Ledger','report','Reports',360,1,NULL),
(37,'rpt.schedule_of_accounts',2,'Schedule of Accounts','report','Reports',370,1,NULL),
(38,'rpt.journal',2,'Journal','report','Reports',380,1,NULL),
(39,'fa.register',3,'Fixed Asset Register','center','Transactions',390,1,NULL),
(40,'fa.depreciation',3,'Run Depreciation','transaction','Transactions',400,1,NULL),
(41,'fa.disposal',3,'Dispose of Assets','transaction','Transactions',410,1,NULL),
(42,'fa.maintenance',3,'Asset Maintenance','transaction','Transactions',420,1,NULL),
(43,'fa.pm',3,'Preventive Maintenance','transaction','Transactions',430,1,NULL),
(44,'fa.categories',3,'Asset Categories','setup','Setup',440,1,NULL),
(45,'fa.locations',3,'Asset Locations','setup','Setup',450,1,NULL),
(46,'rpt.fa_register',3,'Fixed Asset Register Report','report','Reports',460,1,NULL),
(47,'rpt.fa_schedule',3,'Depreciation Schedule by Asset','report','Reports',470,1,NULL),
(48,'rpt.fa_by_category',3,'Depreciation by Category','report','Reports',480,1,NULL),
(49,'rpt.fa_additions',3,'Additions and Disposals','report','Reports',490,1,NULL),
(50,'rpt.fa_maint_history',3,'Maintenance History','report','Reports',500,1,NULL),
(51,'rpt.fa_maint_cost',3,'Maintenance Cost by Asset','report','Reports',510,1,NULL),
(52,'rpt.fa_maint_parts',3,'Spare Parts Used','report','Reports',520,1,NULL),
(53,'rpt.fa_maint_labor',3,'Maintenance Labor','report','Reports',530,1,NULL),
(54,'rpt.fa_pm_upcoming',3,'Upcoming Preventive Maintenance','report','Reports',540,1,NULL),
(55,'rpt.fa_pm_log',3,'Completed Preventive Maintenance','report','Reports',550,1,NULL),
(56,'cust.center',4,'Customer Center','center','Transactions',560,1,NULL),
(57,'cust.estimates',4,'Create Estimates','transaction','Transactions',570,1,NULL),
(58,'cust.sales_orders',4,'Create Sales Orders','transaction','Transactions',580,1,NULL),
(59,'cust.invoices',4,'Create Invoices','transaction','Transactions',590,1,NULL),
(60,'cust.batch_invoices',4,'Create Batch Invoices','transaction','Transactions',600,1,NULL),
(61,'cust.sales_receipts',4,'Enter Sales Receipts','transaction','Transactions',610,1,NULL),
(62,'cust.statement_charges',4,'Enter Statement Charges','transaction','Transactions',620,1,NULL),
(63,'cust.statements',4,'Create Statements','transaction','Transactions',630,1,NULL),
(64,'cust.finance_charges',4,'Assess Finance Charges','transaction','Transactions',640,1,NULL),
(65,'cust.forecast',4,'Set Up Forecast','setup','Transactions',650,1,NULL),
(66,'cust.payments',4,'Receive Payments','transaction','Transactions',660,1,NULL),
(67,'cust.credit_memos',4,'Create Credit Memos/Refunds','transaction','Transactions',670,1,NULL),
(68,'rpt.ar_aging_summary',4,'A/R Aging Summary','report','Customer and Receivables Reports',680,1,NULL),
(69,'rpt.ar_aging_detail',4,'A/R Aging Detail','report','Customer and Receivables Reports',690,1,NULL),
(70,'rpt.customer_balance_summary',4,'Customer Balance Summary','report','Customer and Receivables Reports',700,1,NULL),
(71,'rpt.customer_balance_detail',4,'Customer Balance Detail','report','Customer and Receivables Reports',710,1,NULL),
(72,'rpt.open_invoices',4,'Open Invoices','report','Customer and Receivables Reports',720,1,NULL),
(73,'rpt.collections',4,'Collections Report','report','Customer and Receivables Reports',730,1,NULL),
(74,'rpt.avg_days_summary',4,'Average Days to Pay Summary','report','Customer and Receivables Reports',740,1,NULL),
(75,'rpt.avg_days_detail',4,'Average Days to Pay','report','Customer and Receivables Reports',750,1,NULL),
(76,'rpt.ar_graph',4,'Accounts Receivable Graph','report','Customer and Receivables Reports',760,1,NULL),
(77,'rpt.unbilled_costs',4,'Unbilled Cost by Job','report','Customer and Receivables Reports',770,1,NULL),
(78,'rpt.txn_list_customer',4,'Transaction List by Customer','report','Customer and Receivables Reports',780,1,NULL),
(79,'rpt.item_price_list',4,'Item Price List','report','Customer and Receivables Reports',790,1,NULL),
(80,'rpt.sales_customer_summary',4,'Sales by Customer Summary','report','Sales Reports',800,1,NULL),
(81,'rpt.forecast_vs_actual',4,'Forecast vs Actual by Customer','report','Sales Reports',810,1,NULL),
(82,'rpt.sales_customer_detail',4,'Sales by Customer Detail','report','Sales Reports',820,1,NULL),
(83,'rpt.income_customer_summary',4,'Income by Customer Summary','report','Sales Reports',830,1,NULL),
(84,'rpt.income_customer_detail',4,'Income by Customer Detail','report','Sales Reports',840,1,NULL),
(85,'rpt.sales_item_summary',4,'Sales by Item Summary','report','Sales Reports',850,1,NULL),
(86,'rpt.sales_item_detail',4,'Sales by Item Detail','report','Sales Reports',860,1,NULL),
(87,'rpt.sales_item_group',4,'Sales by Item Group','report','Sales Reports',870,1,NULL),
(88,'rpt.sales_rep_summary',4,'Sales by Rep Summary','report','Sales Reports',880,1,NULL),
(89,'rpt.sales_rep_detail',4,'Sales by Rep Detail','report','Sales Reports',890,1,NULL),
(90,'rpt.inventory_sales_site',4,'Inventory Sales by Site','report','Sales Reports',900,1,NULL),
(91,'rpt.sales_inv_movement',4,'Sales Report Through Inventory Movement','report','Sales Reports',910,1,NULL),
(92,'rpt.pending_sales',4,'Pending Sales','report','Sales Reports',920,1,NULL),
(93,'rpt.open_so_customer',4,'Open Sales Orders by Customer','report','Sales Reports',930,1,NULL),
(94,'rpt.open_so_item',4,'Open Sales Orders by Item','report','Sales Reports',940,1,NULL),
(95,'vend.center',5,'Vendor Center','center','Transactions',950,1,NULL),
(96,'vend.bill_tracker',5,'Bill Tracker','center','Transactions',960,1,NULL),
(97,'vend.bills',5,'Enter Bills','transaction','Transactions',970,1,NULL),
(98,'vend.debit_memos',5,'Supplier Debit Memos','transaction','Transactions',980,1,NULL),
(99,'vend.pay_bills',5,'Pay Bills','transaction','Transactions',990,1,NULL),
(100,'vend.receipts',5,'Receipt Management','transaction','Transactions',1000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(101,'vend.purchase_orders',5,'Create Purchase Orders','transaction','Transactions',1010,1,NULL),
(102,'vend.receive_bill',5,'Receive Items and Enter Bills','transaction','Transactions',1020,1,NULL),
(103,'vend.receive_items',5,'Receive Items','transaction','Transactions',1030,1,NULL),
(104,'vend.bill_received',5,'Enter Bill for Received Items','transaction','Transactions',1040,1,NULL),
(105,'vend.advances',5,'Advances to Suppliers','transaction','Transactions',1050,1,NULL),
(106,'rpt.ap_aging_summary',5,'A/P Aging Summary','report','Vendors and Payables Reports',1060,1,NULL),
(107,'rpt.ap_aging_detail',5,'A/P Aging Detail','report','Vendors and Payables Reports',1070,1,NULL),
(108,'rpt.vendor_balance_summary',5,'Vendor Balance Summary','report','Vendors and Payables Reports',1080,1,NULL),
(109,'rpt.vendor_balance_detail',5,'Vendor Balance Detail','report','Vendors and Payables Reports',1090,1,NULL),
(110,'rpt.unpaid_bills',5,'Unpaid Bills Detail','report','Vendors and Payables Reports',1100,1,NULL),
(111,'rpt.txn_list_vendor',5,'Transaction List by Vendor','report','Vendors and Payables Reports',1110,1,NULL),
(112,'rpt.vendor_contacts',5,'Vendor Contact List','report','Vendors and Payables Reports',1120,1,NULL),
(113,'rpt.expenses_vendor_summary',5,'Expenses by Vendor Summary','report','Vendors and Payables Reports',1130,1,NULL),
(114,'rpt.expenses_vendor_detail',5,'Expenses by Vendor Detail','report','Vendors and Payables Reports',1140,1,NULL),
(115,'rpt.vendor_advances',5,'Advances to Suppliers','report','Vendors and Payables Reports',1150,1,NULL),
(116,'inv.center',6,'Inventory Center','center','Transactions',1160,1,NULL),
(117,'inv.items',6,'Item List','list','Lists',1170,1,NULL),
(118,'inv.categories',6,'Category List','list','Lists',1180,1,NULL),
(119,'inv.sites',6,'Inventory Site List','list','Lists',1190,1,NULL),
(120,'inv.uom',6,'U/M Set List','list','Lists',1200,1,NULL),
(121,'inv.formulas',6,'Processing Formulas (Multi-Output BOM)','list','Lists',1210,1,NULL),
(122,'inv.transfers',6,'Transfer Inventory','transaction','Transactions',1220,1,NULL),
(123,'inv.adjust',6,'Adjust Quantity/Value on Hand','transaction','Transactions',1230,1,NULL),
(124,'inv.build',6,'Build Assemblies','transaction','Transactions',1240,1,NULL),
(125,'inv.process',6,'Disassemble / Process Items','transaction','Transactions',1250,1,NULL),
(126,'inv.physical_count',6,'Physical Inventory Count','transaction','Transactions',1260,1,NULL),
(127,'inv.change_prices',6,'Change Item Prices','transaction','Transactions',1270,1,NULL),
(128,'inv.set_prices',6,'Set Item Prices and Levels','transaction','Transactions',1280,1,NULL),
(129,'rpt.qoh_by_site',6,'Quantity on Hand by Site','report','Inventory Reports',1290,1,NULL),
(130,'rpt.inv_valuation_summary',6,'Inventory Valuation Summary','report','Inventory Reports',1300,1,NULL),
(131,'rpt.inv_valuation_site',6,'Inventory Valuation Summary by Site','report','Inventory Reports',1310,1,NULL),
(132,'rpt.inv_valuation_detail',6,'Inventory Valuation Detail','report','Inventory Reports',1320,1,NULL),
(133,'rpt.avg_cost_changes',6,'Average Cost Changes','report','Inventory Reports',1330,1,NULL),
(134,'rpt.stock_status_item',6,'Inventory Stock Status by Item','report','Inventory Reports',1340,1,NULL),
(135,'rpt.stock_status_vendor',6,'Inventory Stock Status by Vendor','report','Inventory Reports',1350,1,NULL),
(136,'rpt.stock_status_site',6,'Inventory Stock Status by Site','report','Inventory Reports',1360,1,NULL),
(137,'rpt.physical_worksheet',6,'Physical Inventory Worksheet','report','Inventory Reports',1370,1,NULL),
(138,'rpt.pending_builds',6,'Pending Builds','report','Inventory Reports',1380,1,NULL),
(139,'rpt.pending_builds_site',6,'Pending Builds by Site','report','Inventory Reports',1390,1,NULL),
(140,'rpt.processing_register',6,'Processing & Disassembly Register','report','Inventory Reports',1400,1,NULL),
(141,'rpt.coproduct_costs',6,'Co-product & By-product Cost Allocation','report','Inventory Reports',1410,1,NULL),
(142,'rpt.process_yield',6,'Processing Yield Analysis','report','Inventory Reports',1420,1,NULL),
(143,'rpt.negative_inventory',6,'Negative Inventory Listing','report','Inventory Reports',1430,1,NULL),
(144,'rpt.inv_no_movement',6,'No. of Days Inventory Has No Movement','report','Inventory Reports',1440,1,NULL),
(145,'rpt.inv_custom',6,'Customized Inventory Report','report','Inventory Reports',1450,1,NULL),
(146,'rpt.inv_movement_summary',6,'Inventory Movement Summary Report','report','Inventory Reports',1460,1,NULL),
(147,'rpt.inv_movement_detail',6,'Inventory Movement Detailed Report','report','Inventory Reports',1470,1,NULL),
(148,'rpt.inv_movement_txns',6,'Inventory Movement Transactions','report','Inventory Reports',1480,0,NULL),
(149,'pos.register',7,'POS Register','transaction','Transactions',1490,1,NULL),
(150,'pos.shifts',7,'Shifts, Cash Drops & Readings','transaction','Transactions',1500,1,NULL),
(151,'pos.receipts',7,'POS Receipts, Returns & Voids','transaction','Transactions',1510,1,NULL),
(152,'pos.approval_codes',7,'Approval Codes','transaction','Transactions',1520,1,NULL),
(153,'pos.terminals',7,'POS Terminals','setup','Setup',1530,1,NULL),
(154,'rpt.pos_sales_txn',7,'POS Sales by Transaction No. (detail)','report','Reports',1540,1,NULL),
(155,'rpt.pos_sales_item',7,'POS Sales by Item','report','Reports',1550,1,NULL),
(156,'rpt.pos_sales_item_group',7,'POS Sales by Item Group','report','Reports',1560,1,NULL),
(157,'rpt.pos_sales_category',7,'POS Sales by Category','report','Reports',1570,1,NULL),
(158,'rpt.pos_sales_cashier',7,'POS Sales by Cashier & Payment Method','report','Reports',1580,1,NULL),
(159,'rpt.pos_sales_payment',7,'POS Sales by Payment Method','report','Reports',1590,0,NULL),
(160,'rpt.pos_sales_hour',7,'POS Sales by Hour','report','Reports',1600,1,NULL),
(161,'rpt.pos_daily_summary',7,'POS Daily Sales Summary','report','Reports',1610,1,NULL),
(162,'rpt.pos_readings',7,'X- and Z-Reading Summary','report','Reports',1620,1,NULL),
(163,'rpt.pos_discount_log',7,'POS Discount Log','report','Reports',1630,1,NULL),
(164,'rpt.pos_void_log',7,'POS Void and Refund Log','report','Reports',1640,1,NULL),
(165,'rpt.pos_ending_inventory',7,'Ending Inventory - POS Cashier','report','Reports',1650,1,NULL),
(166,'bank.write_checks',8,'Write Checks','transaction','Transactions',1660,1,NULL),
(167,'bank.cash_voucher',8,'Cash Voucher','transaction','Transactions',1670,1,NULL),
(168,'bank.register',8,'Use Register','center','Transactions',1680,1,NULL),
(169,'bank.deposits',8,'Make Deposits','transaction','Transactions',1690,1,NULL),
(170,'bank.transfers',8,'Transfer Funds','transaction','Transactions',1700,1,NULL),
(171,'bank.reconcile',8,'Reconcile','transaction','Transactions',1710,1,NULL),
(172,'rpt.recon_summary',8,'Reconciliation Summary','report','Reports',1720,1,NULL),
(173,'rpt.recon_detail',8,'Reconciliation Detail','report','Reports',1730,1,NULL),
(174,'rpt.recon_discrepancy',8,'Reconciliation Discrepancy','report','Reports',1740,1,NULL),
(175,'rpt.check_register',8,'Check Register','report','Reports',1750,1,NULL),
(176,'bir.form_2307',9,'Print BIR Form 2307','transaction','Transactions',1760,1,NULL),
(177,'bir.wht_table',9,'Withholding Tax Table','setup','Setup',1770,1,NULL),
(178,'rpt.bir_wht_summary',9,'Summary of Taxes Withheld from Vendors/Suppliers','report','BIR Reports',1780,1,NULL),
(179,'rpt.bir_ewt_register',9,'Expanded Withholding Tax Register (1% / 2%)','report','BIR Reports',1790,1,NULL),
(180,'rpt.bir_remittance',9,'Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT)','report','BIR Reports',1800,1,NULL),
(181,'rpt.bir_vat_withholding',9,'VAT Withholding Register (1600-VT)','report','BIR Reports',1810,1,NULL),
(182,'rpt.bir_sales_register',9,'Output VAT / Sales Register','report','BIR Reports',1820,1,NULL),
(183,'rpt.bir_purchase_register',9,'Input VAT / Purchases Register','report','BIR Reports',1830,1,NULL),
(184,'rpt.bir_cvat_withheld',9,'Creditable VAT Withheld Schedule (2550Q Schedule 3)','report','BIR Reports',1840,1,NULL),
(185,'rpt.bir_2550q',9,'Quarterly VAT Return Worksheet (2550Q)','report','BIR Reports',1850,1,NULL),
(186,'admin.users',10,'User Accounts','admin','Setup',1860,1,NULL),
(187,'admin.roles',10,'User Role Configuration','admin','Setup',1870,1,NULL),
(188,'admin.page_access',10,'Page Access Configuration','admin','Setup',1880,1,NULL),
(189,'admin.effective_access',10,'Effective Access (View as User)','admin','Setup',1890,1,NULL),
(190,'admin.settings',10,'Company, Security & Closing Date','admin','Setup',1900,1,NULL),
(191,'admin.utilities',10,'Utilities: Verify & Rebuild Data','admin','Setup',1910,1,NULL),
(192,'admin.import',10,'Import from CSV','admin','Utilities',1920,1,NULL),
(193,'admin.backup',10,'Backup & Restore','admin','Utilities',1930,1,NULL),
(194,'admin.multi_company',10,'Multi-Company Setup','admin','Setup',1940,1,NULL),
(195,'admin.audit_trail',10,'Audit Trail','report','Audit Reports',1950,1,NULL),
(196,'admin.txn_history',10,'Transaction History','report','Audit Reports',1960,1,NULL),
(197,'rpt.voided_deleted_summary',10,'Voided and Deleted Transactions Summary','report','Audit Reports',1970,1,NULL),
(198,'rpt.voided_deleted_detail',10,'Voided and Deleted Transactions Detail','report','Audit Reports',1980,1,NULL),
(199,'rpt.user_activity',10,'User Activity','report','Audit Reports',1990,1,NULL),
(200,'rpt.login_history',10,'Login History','report','Audit Reports',2000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(201,'rpt.user_access',10,'User Access and Permission Changes','report','Audit Reports',2010,1,NULL),
(202,'company.profile',11,'Company Profile & Code','admin','Company',2020,1,NULL),
(203,'company.users',11,'Employees & Users','admin','Company',2030,1,NULL),
(204,'company.join_requests',11,'Join Requests','admin','Company',2040,1,NULL),
(205,'admin.cs_settings',10,'Construction Settings','admin','Setup',2050,1,NULL),
(206,'home.exec_dashboard',1,'Executive Dashboard','center','Dashboards',2060,1,NULL),
(207,'home.pm_dashboard',1,'Project Manager Dashboard','center','Dashboards',2070,1,NULL),
(208,'home.fin_dashboard',1,'Finance Dashboard','center','Dashboards',2080,1,NULL),
(209,'home.alerts',1,'Alerts & Notifications','center','Home',2090,1,NULL),
(210,'proj.center',12,'Project Center','center','Projects',2100,1,NULL),
(211,'proj.view',12,'Project Workspace','center','Projects',2110,0,NULL),
(212,'proj.projects',12,'Project Set-up','list','Projects',2120,0,NULL),
(213,'proj.clients',12,'Clients','list','Projects',2130,1,NULL),
(214,'proj.contracts',12,'Contracts','list','Projects',2140,1,NULL),
(215,'proj.change_orders',12,'Change Orders & Claims','transaction','Projects',2150,1,NULL),
(216,'proj.documents',12,'Documents & Drawings','list','Project Records',2160,1,NULL),
(217,'proj.rfis',12,'RFIs, Submittals & Transmittals','list','Project Records',2170,1,NULL),
(218,'proj.logs',12,'Risks, Issues & Meetings','list','Project Records',2180,1,NULL),
(219,'proj.closeout',12,'Close-out, Punch List & Warranty','transaction','Close-out',2190,1,NULL),
(220,'plan.opportunities',13,'Leads & Tenders','list','Pre-construction',2200,1,NULL),
(221,'plan.estimates',13,'Estimates','transaction','Pre-construction',2210,1,NULL),
(222,'plan.boq',13,'BOQ & Schedule of Values','list','Planning',2220,1,NULL),
(223,'plan.budgets',13,'Project Budgets','transaction','Planning',2230,1,NULL),
(224,'plan.schedule',13,'Schedule & Gantt Chart','center','Planning',2240,1,NULL),
(225,'plan.milestones',13,'Milestones & Deliverables','transaction','Planning',2250,1,NULL),
(226,'plan.eot',13,'Extension of Time','transaction','Planning',2260,1,NULL),
(227,'plan.cost_codes',13,'Cost Codes','setup','Setup',2270,1,NULL),
(228,'site.daily',14,'Daily Site Reports','transaction','Site',2280,1,NULL),
(229,'site.inspections',14,'Quality Inspections','transaction','Quality & Safety',2290,1,NULL),
(230,'site.ncr',14,'Nonconformance Reports','transaction','Quality & Safety',2300,1,NULL),
(231,'site.safety',14,'Safety Records','transaction','Quality & Safety',2310,1,NULL),
(232,'labor.workers',15,'Workers','list','Workforce',2320,1,NULL),
(233,'labor.timesheets',15,'Attendance & Timesheets','transaction','Workforce',2330,1,NULL),
(234,'labor.payroll',15,'Payroll Runs','transaction','Workforce',2340,1,NULL),
(235,'labor.advances',15,'Cash Advances','transaction','Workforce',2350,1,NULL),
(236,'labor.trades',15,'Trades & Rates','setup','Setup',2360,1,NULL),
(237,'equip.register',16,'Equipment Register','list','Equipment',2370,1,NULL),
(238,'equip.dispatch',16,'Dispatch & Booking','transaction','Equipment',2380,1,NULL),
(239,'equip.logs',16,'Usage Logs & Charges','transaction','Equipment',2390,1,NULL),
(240,'proc.requests',17,'Material Requests','transaction','Procurement',2400,1,NULL),
(241,'proc.canvass',17,'Canvass & Quotations','transaction','Procurement',2410,1,NULL),
(242,'proc.receiving',17,'Delivery Inspection','transaction','Procurement',2420,1,NULL),
(243,'proc.materials',17,'Issues, Returns & Transfers','transaction','Materials',2430,1,NULL),
(244,'proc.stock',17,'Project Materials Ledger','center','Materials',2440,1,NULL),
(245,'subk.register',18,'Subcontractors','list','Subcontracts',2450,1,NULL),
(246,'subk.contracts',18,'Subcontract Agreements','transaction','Subcontracts',2460,1,NULL),
(247,'subk.billings',18,'Subcontractor Billings','transaction','Subcontracts',2470,1,NULL),
(248,'pbill.billings',19,'Progress Billings','transaction','Billing',2480,1,NULL),
(249,'pbill.revenue',19,'Revenue Recognition','transaction','Revenue',2490,1,NULL),
(250,'pbill.collections',19,'Collections by Project','center','Collections',2500,1,NULL),
(251,'pbill.retention',19,'Retention Monitor','center','Collections',2510,1,NULL),
(252,'cost.allocations',20,'Shared Cost Allocation','transaction','Cost Control',2520,1,NULL),
(253,'cost.assign',20,'Cost Code Assignment','transaction','Cost Control',2530,1,NULL),
(254,'cost.rules',20,'Allocation Rules','setup','Setup',2540,1,NULL),
(255,'appr.inbox',21,'My Approvals','center','Approvals',2550,1,NULL),
(256,'appr.history',21,'Approval History','list','Approvals',2560,1,NULL),
(257,'appr.routes',21,'Approval Routes','setup','Setup',2570,1,NULL),
(258,'portal.client',23,'Client Portal','center','Portal',2580,1,NULL),
(259,'portal.sub',23,'Subcontractor Portal','center','Portal',2590,1,NULL),
(260,'rpt.cs_portfolio',22,'Project Portfolio Summary','report','Project Financials',2600,1,NULL),
(261,'rpt.cs_income',22,'Project Income Statement','report','Project Financials',2610,1,NULL),
(262,'rpt.cs_cost_breakdown',22,'Project Cost Breakdown','report','Project Financials',2620,1,NULL),
(263,'rpt.cs_budget_actual',22,'Budget versus Actual Cost','report','Project Financials',2630,1,NULL),
(264,'rpt.cs_commitments',22,'Committed Costs and Open Purchase Orders','report','Project Financials',2640,1,NULL),
(265,'rpt.cs_eac',22,'Estimate at Completion','report','Project Financials',2650,1,NULL),
(266,'rpt.cs_etc',22,'Estimated Cost to Complete','report','Project Financials',2660,1,NULL),
(267,'rpt.cs_cash_flow',22,'Project Cash Flow Statement','report','Project Financials',2670,1,NULL),
(268,'rpt.cs_forecast',22,'Project Profitability Forecast','report','Project Financials',2680,1,NULL),
(269,'rpt.cs_wip',22,'Work-in-Progress / Contract Position','report','Project Financials',2690,1,NULL),
(270,'rpt.cs_earned_billed',22,'Earned Revenue versus Billing','report','Project Financials',2700,1,NULL),
(271,'rpt.cs_client_profit',22,'Client Profitability','report','Project Financials',2710,1,NULL),
(272,'rpt.cs_billing',22,'Project Billing Statement','report','Billing and Collections',2720,1,NULL),
(273,'rpt.cs_collections',22,'Project Collections Statement','report','Billing and Collections',2730,1,NULL),
(274,'rpt.cs_ar_aging',22,'Accounts Receivable Aging by Project','report','Billing and Collections',2740,1,NULL),
(275,'rpt.cs_retention',22,'Retention Receivables','report','Billing and Collections',2750,1,NULL),
(276,'rpt.cs_ap',22,'Project Accounts Payable','report','Costs and Resources',2760,1,NULL),
(277,'rpt.cs_materials',22,'Material Consumption and Wastage','report','Costs and Resources',2770,1,NULL),
(278,'rpt.cs_labor',22,'Worker Man-hours and Labor Cost','report','Costs and Resources',2780,1,NULL),
(279,'rpt.cs_productivity',22,'Labor Productivity','report','Costs and Resources',2790,1,NULL),
(280,'rpt.cs_equipment',22,'Equipment Utilization and Cost','report','Costs and Resources',2800,1,NULL),
(281,'rpt.cs_subcon',22,'Subcontractor Billing and Payments','report','Costs and Resources',2810,1,NULL),
(282,'rpt.cs_allocations',22,'Shared Cost Allocation','report','Costs and Resources',2820,1,NULL),
(283,'rpt.cs_supplier_spend',22,'Supplier Spending Analysis','report','Costs and Resources',2830,1,NULL),
(284,'rpt.cs_change_orders',22,'Approved Change Orders','report','Contracts and Progress',2840,1,NULL),
(285,'rpt.cs_contract_summary',22,'Contract Value and Variation Summary','report','Contracts and Progress',2850,1,NULL),
(286,'rpt.cs_evm',22,'Earned Value Analysis','report','Contracts and Progress',2860,1,NULL),
(287,'rpt.cs_deliverables',22,'Project Completion and Deliverables','report','Contracts and Progress',2870,1,NULL),
(288,'rpt.cs_qhse',22,'Quality and Safety Summary','report','Contracts and Progress',2880,1,NULL);

-- role_page_access (1997 rows)
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,1,1,0,0,0,1,1),
(2,2,1,0,0,0,1,1),
(2,3,1,0,0,0,1,1),
(2,4,1,0,0,0,1,1),
(2,5,1,0,0,0,1,1),
(2,6,1,0,0,0,1,1),
(2,7,1,0,0,0,1,1),
(2,8,1,0,0,0,1,1),
(2,9,1,0,0,0,1,1),
(2,10,1,0,0,0,1,1),
(2,11,1,0,0,0,1,1),
(2,12,1,0,0,0,1,1),
(2,13,1,0,0,0,1,1),
(2,14,1,0,0,0,1,1),
(2,15,1,0,0,0,1,1),
(2,16,1,0,0,0,1,1),
(2,17,1,0,0,0,1,1),
(2,18,1,0,0,0,1,1),
(2,19,1,0,0,0,1,1),
(2,20,1,0,0,0,1,1),
(2,21,1,0,0,0,1,1),
(2,22,1,0,0,0,1,1),
(2,23,1,0,0,0,1,1),
(2,24,1,0,0,0,1,1),
(2,25,1,0,0,0,1,1),
(2,26,1,0,0,0,1,1),
(2,27,1,0,0,0,1,1),
(2,28,1,0,0,0,1,1),
(2,29,1,0,0,0,1,1),
(2,30,1,0,0,0,1,1),
(2,31,1,0,0,0,1,1),
(2,32,1,0,0,0,1,1),
(2,33,1,0,0,0,1,1),
(2,34,1,0,0,0,1,1),
(2,35,1,0,0,0,1,1),
(2,36,1,0,0,0,1,1),
(2,37,1,0,0,0,1,1),
(2,38,1,0,0,0,1,1),
(2,39,1,0,0,0,1,1),
(2,40,1,0,0,0,1,1),
(2,41,1,0,0,0,1,1),
(2,42,1,0,0,0,1,1),
(2,43,1,0,0,0,1,1),
(2,44,1,0,0,0,1,1),
(2,45,1,0,0,0,1,1),
(2,46,1,0,0,0,1,1),
(2,47,1,0,0,0,1,1),
(2,48,1,0,0,0,1,1),
(2,49,1,0,0,0,1,1),
(2,50,1,0,0,0,1,1),
(2,51,1,0,0,0,1,1),
(2,52,1,0,0,0,1,1),
(2,53,1,0,0,0,1,1),
(2,54,1,0,0,0,1,1),
(2,55,1,0,0,0,1,1),
(2,56,1,0,0,0,1,1),
(2,57,1,0,0,0,1,1),
(2,58,1,0,0,0,1,1),
(2,59,1,0,0,0,1,1),
(2,60,1,0,0,0,1,1),
(2,61,1,0,0,0,1,1),
(2,62,1,0,0,0,1,1),
(2,63,1,0,0,0,1,1),
(2,64,1,0,0,0,1,1),
(2,65,1,0,0,0,1,1),
(2,66,1,0,0,0,1,1),
(2,67,1,0,0,0,1,1),
(2,68,1,0,0,0,1,1),
(2,69,1,0,0,0,1,1),
(2,70,1,0,0,0,1,1),
(2,71,1,0,0,0,1,1),
(2,72,1,0,0,0,1,1),
(2,73,1,0,0,0,1,1),
(2,74,1,0,0,0,1,1),
(2,75,1,0,0,0,1,1),
(2,76,1,0,0,0,1,1),
(2,77,1,0,0,0,1,1),
(2,78,1,0,0,0,1,1),
(2,79,1,0,0,0,1,1),
(2,80,1,0,0,0,1,1),
(2,81,1,0,0,0,1,1),
(2,82,1,0,0,0,1,1),
(2,83,1,0,0,0,1,1),
(2,84,1,0,0,0,1,1),
(2,85,1,0,0,0,1,1),
(2,86,1,0,0,0,1,1),
(2,87,1,0,0,0,1,1),
(2,88,1,0,0,0,1,1),
(2,89,1,0,0,0,1,1),
(2,90,1,0,0,0,1,1),
(2,91,1,0,0,0,1,1),
(2,92,1,0,0,0,1,1),
(2,93,1,0,0,0,1,1),
(2,94,1,0,0,0,1,1),
(2,95,1,0,0,0,1,1),
(2,96,1,0,0,0,1,1),
(2,97,1,0,0,0,1,1),
(2,98,1,0,0,0,1,1),
(2,99,1,0,0,0,1,1),
(2,100,1,0,0,0,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,101,1,0,0,0,1,1),
(2,102,1,0,0,0,1,1),
(2,103,1,0,0,0,1,1),
(2,104,1,0,0,0,1,1),
(2,105,1,0,0,0,1,1),
(2,106,1,0,0,0,1,1),
(2,107,1,0,0,0,1,1),
(2,108,1,0,0,0,1,1),
(2,109,1,0,0,0,1,1),
(2,110,1,0,0,0,1,1),
(2,111,1,0,0,0,1,1),
(2,112,1,0,0,0,1,1),
(2,113,1,0,0,0,1,1),
(2,114,1,0,0,0,1,1),
(2,115,1,0,0,0,1,1),
(2,116,1,0,0,0,1,1),
(2,117,1,0,0,0,1,1),
(2,118,1,0,0,0,1,1),
(2,119,1,0,0,0,1,1),
(2,120,1,0,0,0,1,1),
(2,121,1,0,0,0,1,1),
(2,122,1,0,0,0,1,1),
(2,123,1,0,0,0,1,1),
(2,124,1,0,0,0,1,1),
(2,125,1,0,0,0,1,1),
(2,126,1,0,0,0,1,1),
(2,127,1,0,0,0,1,1),
(2,128,1,0,0,0,1,1),
(2,129,1,0,0,0,1,1),
(2,130,1,0,0,0,1,1),
(2,131,1,0,0,0,1,1),
(2,132,1,0,0,0,1,1),
(2,133,1,0,0,0,1,1),
(2,134,1,0,0,0,1,1),
(2,135,1,0,0,0,1,1),
(2,136,1,0,0,0,1,1),
(2,137,1,0,0,0,1,1),
(2,138,1,0,0,0,1,1),
(2,139,1,0,0,0,1,1),
(2,140,1,0,0,0,1,1),
(2,141,1,0,0,0,1,1),
(2,142,1,0,0,0,1,1),
(2,143,1,0,0,0,1,1),
(2,144,1,0,0,0,1,1),
(2,145,1,0,0,0,1,1),
(2,146,1,0,0,0,1,1),
(2,147,1,0,0,0,1,1),
(2,148,1,0,0,0,1,1),
(2,149,1,0,0,0,1,1),
(2,150,1,0,0,0,1,1),
(2,151,1,0,0,0,1,1),
(2,152,1,0,0,0,1,1),
(2,153,1,0,0,0,1,1),
(2,154,1,0,0,0,1,1),
(2,155,1,0,0,0,1,1),
(2,156,1,0,0,0,1,1),
(2,157,1,0,0,0,1,1),
(2,158,1,0,0,0,1,1),
(2,159,1,0,0,0,1,1),
(2,160,1,0,0,0,1,1),
(2,161,1,0,0,0,1,1),
(2,162,1,0,0,0,1,1),
(2,163,1,0,0,0,1,1),
(2,164,1,0,0,0,1,1),
(2,165,1,0,0,0,1,1),
(2,166,1,0,0,0,1,1),
(2,167,1,0,0,0,1,1),
(2,168,1,0,0,0,1,1),
(2,169,1,0,0,0,1,1),
(2,170,1,0,0,0,1,1),
(2,171,1,0,0,0,1,1),
(2,172,1,0,0,0,1,1),
(2,173,1,0,0,0,1,1),
(2,174,1,0,0,0,1,1),
(2,175,1,0,0,0,1,1),
(2,176,1,0,0,0,1,1),
(2,177,1,0,0,0,1,1),
(2,178,1,0,0,0,1,1),
(2,179,1,0,0,0,1,1),
(2,180,1,0,0,0,1,1),
(2,181,1,0,0,0,1,1),
(2,182,1,0,0,0,1,1),
(2,183,1,0,0,0,1,1),
(2,184,1,0,0,0,1,1),
(2,185,1,0,0,0,1,1),
(2,195,1,0,0,0,1,1),
(2,196,1,0,0,0,1,1),
(2,197,1,0,0,0,1,1),
(2,198,1,0,0,0,1,1),
(2,199,1,0,0,0,1,1),
(2,200,1,0,0,0,1,1),
(2,201,1,0,0,0,1,1),
(2,206,1,0,0,0,1,1),
(2,207,1,0,0,0,1,1),
(2,208,1,0,0,0,1,1),
(2,209,1,0,0,0,1,1),
(2,210,1,0,0,0,1,1),
(2,211,1,0,0,0,1,1),
(2,212,1,0,0,0,1,1),
(2,213,1,0,0,0,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,214,1,0,0,0,1,1),
(2,215,1,0,0,0,1,1),
(2,216,1,0,0,0,1,1),
(2,217,1,0,0,0,1,1),
(2,218,1,0,0,0,1,1),
(2,219,1,0,0,0,1,1),
(2,220,1,0,0,0,1,1),
(2,221,1,0,0,0,1,1),
(2,222,1,0,0,0,1,1),
(2,223,1,0,0,0,1,1),
(2,224,1,0,0,0,1,1),
(2,225,1,0,0,0,1,1),
(2,226,1,0,0,0,1,1),
(2,227,1,0,0,0,1,1),
(2,228,1,0,0,0,1,1),
(2,229,1,0,0,0,1,1),
(2,230,1,0,0,0,1,1),
(2,231,1,0,0,0,1,1),
(2,232,1,0,0,0,1,1),
(2,233,1,0,0,0,1,1),
(2,234,1,0,0,0,1,1),
(2,235,1,0,0,0,1,1),
(2,236,1,0,0,0,1,1),
(2,237,1,0,0,0,1,1),
(2,238,1,0,0,0,1,1),
(2,239,1,0,0,0,1,1),
(2,240,1,0,0,0,1,1),
(2,241,1,0,0,0,1,1),
(2,242,1,0,0,0,1,1),
(2,243,1,0,0,0,1,1),
(2,244,1,0,0,0,1,1),
(2,245,1,0,0,0,1,1),
(2,246,1,0,0,0,1,1),
(2,247,1,0,0,0,1,1),
(2,248,1,0,0,0,1,1),
(2,249,1,0,0,0,1,1),
(2,250,1,0,0,0,1,1),
(2,251,1,0,0,0,1,1),
(2,252,1,0,0,0,1,1),
(2,253,1,0,0,0,1,1),
(2,254,1,0,0,0,1,1),
(2,255,1,0,0,0,1,1),
(2,256,1,0,0,0,1,1),
(2,257,1,0,0,0,1,1),
(2,260,1,0,0,0,1,1),
(2,261,1,0,0,0,1,1),
(2,262,1,0,0,0,1,1),
(2,263,1,0,0,0,1,1),
(2,264,1,0,0,0,1,1),
(2,265,1,0,0,0,1,1),
(2,266,1,0,0,0,1,1),
(2,267,1,0,0,0,1,1),
(2,268,1,0,0,0,1,1),
(2,269,1,0,0,0,1,1),
(2,270,1,0,0,0,1,1),
(2,271,1,0,0,0,1,1),
(2,272,1,0,0,0,1,1),
(2,273,1,0,0,0,1,1),
(2,274,1,0,0,0,1,1),
(2,275,1,0,0,0,1,1),
(2,276,1,0,0,0,1,1),
(2,277,1,0,0,0,1,1),
(2,278,1,0,0,0,1,1),
(2,279,1,0,0,0,1,1),
(2,280,1,0,0,0,1,1),
(2,281,1,0,0,0,1,1),
(2,282,1,0,0,0,1,1),
(2,283,1,0,0,0,1,1),
(2,284,1,0,0,0,1,1),
(2,285,1,0,0,0,1,1),
(2,286,1,0,0,0,1,1),
(2,287,1,0,0,0,1,1),
(2,288,1,0,0,0,1,1),
(3,1,1,0,0,0,1,1),
(3,2,1,0,0,0,1,1),
(3,3,1,0,0,0,1,1),
(3,4,1,0,0,0,1,1),
(3,5,1,0,0,0,1,1),
(3,6,1,0,0,0,1,1),
(3,7,1,0,0,0,1,1),
(3,8,1,0,0,0,1,1),
(3,9,1,0,0,0,1,1),
(3,10,1,0,0,0,1,1),
(3,11,1,0,0,0,1,1),
(3,12,1,0,0,0,1,1),
(3,13,1,0,0,0,1,1),
(3,14,1,0,0,0,1,1),
(3,15,1,0,0,0,1,1),
(3,16,1,0,0,0,1,1),
(3,17,1,0,0,0,1,1),
(3,18,1,0,0,0,1,1),
(3,19,1,0,0,0,1,1),
(3,20,1,0,0,0,1,1),
(3,21,1,0,0,0,1,1),
(3,22,1,0,0,0,1,1),
(3,23,1,0,0,0,1,1),
(3,24,1,0,0,0,1,1),
(3,25,1,0,0,0,1,1),
(3,26,1,0,0,0,1,1),
(3,27,1,0,0,0,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(3,28,1,0,0,0,1,1),
(3,29,1,0,0,0,1,1),
(3,30,1,0,0,0,1,1),
(3,31,1,0,0,0,1,1),
(3,32,1,0,0,0,1,1),
(3,33,1,0,0,0,1,1),
(3,34,1,0,0,0,1,1),
(3,35,1,0,0,0,1,1),
(3,36,1,0,0,0,1,1),
(3,37,1,0,0,0,1,1),
(3,38,1,0,0,0,1,1),
(3,39,1,0,0,0,1,1),
(3,40,1,0,0,0,1,1),
(3,41,1,0,0,0,1,1),
(3,42,1,0,0,0,1,1),
(3,43,1,0,0,0,1,1),
(3,44,1,0,0,0,1,1),
(3,45,1,0,0,0,1,1),
(3,46,1,0,0,0,1,1),
(3,47,1,0,0,0,1,1),
(3,48,1,0,0,0,1,1),
(3,49,1,0,0,0,1,1),
(3,50,1,0,0,0,1,1),
(3,51,1,0,0,0,1,1),
(3,52,1,0,0,0,1,1),
(3,53,1,0,0,0,1,1),
(3,54,1,0,0,0,1,1),
(3,55,1,0,0,0,1,1),
(3,56,1,0,0,0,1,1),
(3,57,1,0,0,0,1,1),
(3,58,1,0,0,0,1,1),
(3,59,1,0,0,0,1,1),
(3,60,1,0,0,0,1,1),
(3,61,1,0,0,0,1,1),
(3,62,1,0,0,0,1,1),
(3,63,1,0,0,0,1,1),
(3,64,1,0,0,0,1,1),
(3,65,1,0,0,0,1,1),
(3,66,1,0,0,0,1,1),
(3,67,1,0,0,0,1,1),
(3,68,1,0,0,0,1,1),
(3,69,1,0,0,0,1,1),
(3,70,1,0,0,0,1,1),
(3,71,1,0,0,0,1,1),
(3,72,1,0,0,0,1,1),
(3,73,1,0,0,0,1,1),
(3,74,1,0,0,0,1,1),
(3,75,1,0,0,0,1,1),
(3,76,1,0,0,0,1,1),
(3,77,1,0,0,0,1,1),
(3,78,1,0,0,0,1,1),
(3,79,1,0,0,0,1,1),
(3,80,1,0,0,0,1,1),
(3,81,1,0,0,0,1,1),
(3,82,1,0,0,0,1,1),
(3,83,1,0,0,0,1,1),
(3,84,1,0,0,0,1,1),
(3,85,1,0,0,0,1,1),
(3,86,1,0,0,0,1,1),
(3,87,1,0,0,0,1,1),
(3,88,1,0,0,0,1,1),
(3,89,1,0,0,0,1,1),
(3,90,1,0,0,0,1,1),
(3,91,1,0,0,0,1,1),
(3,92,1,0,0,0,1,1),
(3,93,1,0,0,0,1,1),
(3,94,1,0,0,0,1,1),
(3,95,1,0,0,0,1,1),
(3,96,1,0,0,0,1,1),
(3,97,1,0,0,0,1,1),
(3,98,1,0,0,0,1,1),
(3,99,1,0,0,0,1,1),
(3,100,1,0,0,0,1,1),
(3,101,1,0,0,0,1,1),
(3,102,1,0,0,0,1,1),
(3,103,1,0,0,0,1,1),
(3,104,1,0,0,0,1,1),
(3,105,1,0,0,0,1,1),
(3,106,1,0,0,0,1,1),
(3,107,1,0,0,0,1,1),
(3,108,1,0,0,0,1,1),
(3,109,1,0,0,0,1,1),
(3,110,1,0,0,0,1,1),
(3,111,1,0,0,0,1,1),
(3,112,1,0,0,0,1,1),
(3,113,1,0,0,0,1,1),
(3,114,1,0,0,0,1,1),
(3,115,1,0,0,0,1,1),
(3,116,1,0,0,0,1,1),
(3,117,1,0,0,0,1,1),
(3,118,1,0,0,0,1,1),
(3,119,1,0,0,0,1,1),
(3,120,1,0,0,0,1,1),
(3,121,1,0,0,0,1,1),
(3,122,1,0,0,0,1,1),
(3,123,1,0,0,0,1,1),
(3,124,1,0,0,0,1,1),
(3,125,1,0,0,0,1,1),
(3,126,1,0,0,0,1,1),
(3,127,1,0,0,0,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(3,128,1,0,0,0,1,1),
(3,129,1,0,0,0,1,1),
(3,130,1,0,0,0,1,1),
(3,131,1,0,0,0,1,1),
(3,132,1,0,0,0,1,1),
(3,133,1,0,0,0,1,1),
(3,134,1,0,0,0,1,1),
(3,135,1,0,0,0,1,1),
(3,136,1,0,0,0,1,1),
(3,137,1,0,0,0,1,1),
(3,138,1,0,0,0,1,1),
(3,139,1,0,0,0,1,1),
(3,140,1,0,0,0,1,1),
(3,141,1,0,0,0,1,1),
(3,142,1,0,0,0,1,1),
(3,143,1,0,0,0,1,1),
(3,144,1,0,0,0,1,1),
(3,145,1,0,0,0,1,1),
(3,146,1,0,0,0,1,1),
(3,147,1,0,0,0,1,1),
(3,148,1,0,0,0,1,1),
(3,149,1,0,0,0,1,1),
(3,150,1,0,0,0,1,1),
(3,151,1,0,0,0,1,1),
(3,152,1,0,0,0,1,1),
(3,153,1,0,0,0,1,1),
(3,154,1,0,0,0,1,1),
(3,155,1,0,0,0,1,1),
(3,156,1,0,0,0,1,1),
(3,157,1,0,0,0,1,1),
(3,158,1,0,0,0,1,1),
(3,159,1,0,0,0,1,1),
(3,160,1,0,0,0,1,1),
(3,161,1,0,0,0,1,1),
(3,162,1,0,0,0,1,1),
(3,163,1,0,0,0,1,1),
(3,164,1,0,0,0,1,1),
(3,165,1,0,0,0,1,1),
(3,166,1,0,0,0,1,1),
(3,167,1,0,0,0,1,1),
(3,168,1,0,0,0,1,1),
(3,169,1,0,0,0,1,1),
(3,170,1,0,0,0,1,1),
(3,171,1,0,0,0,1,1),
(3,172,1,0,0,0,1,1),
(3,173,1,0,0,0,1,1),
(3,174,1,0,0,0,1,1),
(3,175,1,0,0,0,1,1),
(3,176,1,0,0,0,1,1),
(3,177,1,0,0,0,1,1),
(3,178,1,0,0,0,1,1),
(3,179,1,0,0,0,1,1),
(3,180,1,0,0,0,1,1),
(3,181,1,0,0,0,1,1),
(3,182,1,0,0,0,1,1),
(3,183,1,0,0,0,1,1),
(3,184,1,0,0,0,1,1),
(3,185,1,0,0,0,1,1),
(3,195,1,0,0,0,1,1),
(3,196,1,0,0,0,1,1),
(3,197,1,0,0,0,1,1),
(3,198,1,0,0,0,1,1),
(3,199,1,0,0,0,1,1),
(3,200,1,0,0,0,1,1),
(3,201,1,0,0,0,1,1),
(3,206,1,0,0,0,1,1),
(3,207,1,0,0,0,1,1),
(3,208,1,0,0,0,1,1),
(3,209,1,0,0,0,1,1),
(3,210,1,1,1,0,1,1),
(3,211,1,1,1,0,1,1),
(3,212,1,1,1,0,1,1),
(3,213,1,1,1,0,1,1),
(3,214,1,1,1,0,1,1),
(3,215,1,1,1,0,1,1),
(3,216,1,1,1,0,1,1),
(3,217,1,1,1,0,1,1),
(3,218,1,1,1,0,1,1),
(3,219,1,1,1,0,1,1),
(3,220,1,1,1,0,1,1),
(3,221,1,1,1,0,1,1),
(3,222,1,1,1,0,1,1),
(3,223,1,1,1,0,1,1),
(3,224,1,1,1,0,1,1),
(3,225,1,1,1,0,1,1),
(3,226,1,1,1,0,1,1),
(3,227,1,1,1,0,1,1),
(3,228,1,0,0,0,1,1),
(3,229,1,0,0,0,1,1),
(3,230,1,0,0,0,1,1),
(3,231,1,0,0,0,1,1),
(3,232,1,0,0,0,1,1),
(3,233,1,0,0,0,1,1),
(3,234,1,0,0,0,1,1),
(3,235,1,0,0,0,1,1),
(3,236,1,0,0,0,1,1),
(3,237,1,0,0,0,1,1),
(3,238,1,0,0,0,1,1),
(3,239,1,0,0,0,1,1),
(3,240,1,0,0,0,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(3,241,1,0,0,0,1,1),
(3,242,1,0,0,0,1,1),
(3,243,1,0,0,0,1,1),
(3,244,1,0,0,0,1,1),
(3,245,1,0,0,0,1,1),
(3,246,1,0,0,0,1,1),
(3,247,1,0,0,0,1,1),
(3,248,1,0,0,0,1,1),
(3,249,1,0,0,0,1,1),
(3,250,1,0,0,0,1,1),
(3,251,1,0,0,0,1,1),
(3,252,1,0,0,0,1,1),
(3,253,1,0,0,0,1,1),
(3,254,1,0,0,0,1,1),
(3,255,1,0,0,0,1,1),
(3,256,1,0,0,0,1,1),
(3,257,1,0,0,0,1,1),
(3,260,1,0,0,0,1,1),
(3,261,1,0,0,0,1,1),
(3,262,1,0,0,0,1,1),
(3,263,1,0,0,0,1,1),
(3,264,1,0,0,0,1,1),
(3,265,1,0,0,0,1,1),
(3,266,1,0,0,0,1,1),
(3,267,1,0,0,0,1,1),
(3,268,1,0,0,0,1,1),
(3,269,1,0,0,0,1,1),
(3,270,1,0,0,0,1,1),
(3,271,1,0,0,0,1,1),
(3,272,1,0,0,0,1,1),
(3,273,1,0,0,0,1,1),
(3,274,1,0,0,0,1,1),
(3,275,1,0,0,0,1,1),
(3,276,1,0,0,0,1,1),
(3,277,1,0,0,0,1,1),
(3,278,1,0,0,0,1,1),
(3,279,1,0,0,0,1,1),
(3,280,1,0,0,0,1,1),
(3,281,1,0,0,0,1,1),
(3,282,1,0,0,0,1,1),
(3,283,1,0,0,0,1,1),
(3,284,1,0,0,0,1,1),
(3,285,1,0,0,0,1,1),
(3,286,1,0,0,0,1,1),
(3,287,1,0,0,0,1,1),
(3,288,1,0,0,0,1,1),
(4,1,1,0,0,0,1,0),
(4,2,1,0,0,0,1,0),
(4,3,1,0,0,0,1,0),
(4,5,1,0,0,0,1,0),
(4,6,1,0,0,0,1,0),
(4,42,1,0,0,0,1,0),
(4,43,1,0,0,0,1,0),
(4,101,1,0,0,0,1,0),
(4,116,1,0,0,0,1,0),
(4,206,1,0,0,0,1,0),
(4,207,1,0,0,0,1,0),
(4,209,1,0,0,0,1,0),
(4,210,1,1,1,0,1,1),
(4,211,1,1,1,0,1,1),
(4,212,1,1,1,0,1,1),
(4,213,1,1,1,0,1,1),
(4,214,1,1,1,0,1,1),
(4,215,1,1,1,0,1,1),
(4,216,1,1,1,0,1,1),
(4,217,1,1,1,0,1,1),
(4,218,1,1,1,0,1,1),
(4,219,1,1,1,0,1,1),
(4,220,1,1,1,0,1,1),
(4,221,1,1,1,0,1,1),
(4,222,1,1,1,0,1,1),
(4,223,1,1,1,0,1,1),
(4,224,1,1,1,0,1,1),
(4,225,1,1,1,0,1,1),
(4,226,1,1,1,0,1,1),
(4,227,1,1,1,0,1,1),
(4,228,1,1,1,0,1,1),
(4,229,1,1,1,0,1,1),
(4,230,1,1,1,0,1,1),
(4,231,1,1,1,0,1,1),
(4,232,1,1,1,0,1,1),
(4,233,1,1,1,0,1,1),
(4,234,1,1,1,0,1,1),
(4,235,1,1,1,0,1,1),
(4,236,1,1,1,0,1,1),
(4,237,1,1,1,0,1,1),
(4,238,1,1,1,0,1,1),
(4,239,1,1,1,0,1,1),
(4,240,1,1,1,0,1,1),
(4,241,1,1,1,0,1,1),
(4,242,1,1,1,0,1,1),
(4,243,1,1,1,0,1,1),
(4,244,1,1,1,0,1,1),
(4,245,1,1,1,0,1,1),
(4,246,1,1,1,0,1,1),
(4,247,1,1,1,0,1,1),
(4,248,1,0,0,0,1,0),
(4,250,1,0,0,0,1,0),
(4,251,1,0,0,0,1,0),
(4,252,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(4,253,1,0,0,0,1,0),
(4,254,1,0,0,0,1,0),
(4,255,1,0,0,0,1,0),
(4,256,1,0,0,0,1,0),
(4,260,1,0,0,0,1,0),
(4,261,1,0,0,0,1,0),
(4,262,1,0,0,0,1,0),
(4,263,1,0,0,0,1,0),
(4,264,1,0,0,0,1,0),
(4,265,1,0,0,0,1,0),
(4,266,1,0,0,0,1,0),
(4,267,1,0,0,0,1,0),
(4,268,1,0,0,0,1,0),
(4,269,1,0,0,0,1,0),
(4,270,1,0,0,0,1,0),
(4,271,1,0,0,0,1,0),
(4,272,1,0,0,0,1,0),
(4,273,1,0,0,0,1,0),
(4,274,1,0,0,0,1,0),
(4,275,1,0,0,0,1,0),
(4,276,1,0,0,0,1,0),
(4,277,1,0,0,0,1,0),
(4,278,1,0,0,0,1,0),
(4,279,1,0,0,0,1,0),
(4,280,1,0,0,0,1,0),
(4,281,1,0,0,0,1,0),
(4,282,1,0,0,0,1,0),
(4,283,1,0,0,0,1,0),
(4,284,1,0,0,0,1,0),
(4,285,1,0,0,0,1,0),
(4,286,1,0,0,0,1,0),
(4,287,1,0,0,0,1,0),
(4,288,1,0,0,0,1,0),
(5,1,1,0,0,0,1,0),
(5,2,1,0,0,0,1,0),
(5,3,1,0,0,0,1,0),
(5,5,1,0,0,0,1,0),
(5,6,1,0,0,0,1,0),
(5,206,1,0,0,0,1,0),
(5,207,1,0,0,0,1,0),
(5,209,1,0,0,0,1,0),
(5,210,1,1,1,1,1,1),
(5,211,1,1,1,1,1,1),
(5,212,1,1,1,1,1,1),
(5,213,1,1,1,1,1,1),
(5,214,1,1,1,1,1,1),
(5,215,1,1,1,1,1,1),
(5,216,1,1,1,1,1,1),
(5,217,1,1,1,1,1,1),
(5,218,1,1,1,1,1,1),
(5,219,1,1,1,1,1,1),
(5,220,1,1,1,1,1,1),
(5,221,1,1,1,1,1,1),
(5,222,1,1,1,1,1,1),
(5,223,1,1,1,1,1,1),
(5,224,1,1,1,1,1,1),
(5,225,1,1,1,1,1,1),
(5,226,1,1,1,1,1,1),
(5,227,1,1,1,1,1,1),
(5,228,1,0,0,0,1,1),
(5,229,1,0,0,0,1,1),
(5,230,1,0,0,0,1,1),
(5,231,1,0,0,0,1,1),
(5,232,1,0,0,0,1,1),
(5,233,1,0,0,0,1,1),
(5,234,1,0,0,0,1,1),
(5,235,1,0,0,0,1,1),
(5,236,1,0,0,0,1,1),
(5,237,1,0,0,0,1,1),
(5,238,1,0,0,0,1,1),
(5,239,1,0,0,0,1,1),
(5,240,1,0,0,0,1,1),
(5,241,1,0,0,0,1,1),
(5,242,1,0,0,0,1,1),
(5,243,1,0,0,0,1,1),
(5,244,1,0,0,0,1,1),
(5,245,1,1,1,0,1,1),
(5,246,1,1,1,0,1,1),
(5,247,1,1,1,0,1,1),
(5,248,1,0,0,0,1,1),
(5,249,1,0,0,0,1,1),
(5,250,1,0,0,0,1,1),
(5,251,1,0,0,0,1,1),
(5,252,1,0,0,0,1,0),
(5,253,1,0,0,0,1,0),
(5,254,1,0,0,0,1,0),
(5,255,1,0,0,0,1,0),
(5,256,1,0,0,0,1,0),
(5,260,1,0,0,0,1,0),
(5,261,1,0,0,0,1,0),
(5,262,1,0,0,0,1,0),
(5,263,1,0,0,0,1,0),
(5,264,1,0,0,0,1,0),
(5,265,1,0,0,0,1,0),
(5,266,1,0,0,0,1,0),
(5,267,1,0,0,0,1,0),
(5,268,1,0,0,0,1,0),
(5,269,1,0,0,0,1,0),
(5,270,1,0,0,0,1,0),
(5,271,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(5,272,1,0,0,0,1,0),
(5,273,1,0,0,0,1,0),
(5,274,1,0,0,0,1,0),
(5,275,1,0,0,0,1,0),
(5,276,1,0,0,0,1,0),
(5,277,1,0,0,0,1,0),
(5,278,1,0,0,0,1,0),
(5,279,1,0,0,0,1,0),
(5,280,1,0,0,0,1,0),
(5,281,1,0,0,0,1,0),
(5,282,1,0,0,0,1,0),
(5,283,1,0,0,0,1,0),
(5,284,1,0,0,0,1,0),
(5,285,1,0,0,0,1,0),
(5,286,1,0,0,0,1,0),
(5,287,1,0,0,0,1,0),
(5,288,1,0,0,0,1,0),
(6,1,1,0,0,0,1,0),
(6,2,1,0,0,0,1,0),
(6,3,1,0,0,0,1,0),
(6,5,1,0,0,0,1,0),
(6,6,1,0,0,0,1,0),
(6,101,1,0,0,0,1,0),
(6,207,1,0,0,0,1,0),
(6,209,1,0,0,0,1,0),
(6,210,1,0,0,0,1,0),
(6,211,1,0,0,0,1,0),
(6,212,1,0,1,0,1,0),
(6,213,1,0,0,0,1,0),
(6,214,1,0,0,0,1,0),
(6,215,1,1,1,0,1,1),
(6,216,1,1,1,0,1,1),
(6,217,1,1,1,0,1,1),
(6,218,1,1,1,0,1,0),
(6,219,1,1,1,0,1,1),
(6,220,1,1,1,0,1,1),
(6,221,1,1,1,0,1,1),
(6,222,1,1,1,0,1,1),
(6,223,1,1,1,0,1,1),
(6,224,1,1,1,0,1,1),
(6,225,1,1,1,0,1,1),
(6,226,1,1,1,0,1,1),
(6,227,1,1,1,0,1,1),
(6,228,1,1,1,0,1,1),
(6,229,1,1,1,0,1,1),
(6,230,1,1,1,0,1,1),
(6,231,1,1,1,0,1,1),
(6,232,1,0,0,0,1,0),
(6,233,1,1,1,0,1,1),
(6,235,1,1,1,0,1,1),
(6,237,1,0,0,0,1,0),
(6,238,1,1,1,0,1,1),
(6,239,1,1,1,0,1,1),
(6,240,1,1,1,0,1,1),
(6,241,1,0,0,0,1,0),
(6,242,1,1,1,0,1,0),
(6,243,1,1,1,0,1,1),
(6,244,1,0,0,0,1,0),
(6,245,1,0,0,0,1,0),
(6,246,1,1,1,0,1,0),
(6,247,1,1,1,0,1,1),
(6,248,1,1,1,0,1,1),
(6,250,1,0,0,0,1,0),
(6,251,1,0,0,0,1,0),
(6,253,1,0,0,0,1,0),
(6,255,1,0,0,0,1,0),
(6,256,1,0,0,0,1,0),
(6,260,1,0,0,0,1,0),
(6,261,1,0,0,0,1,0),
(6,262,1,0,0,0,1,0),
(6,263,1,0,0,0,1,0),
(6,264,1,0,0,0,1,0),
(6,265,1,0,0,0,1,0),
(6,266,1,0,0,0,1,0),
(6,267,1,0,0,0,1,0),
(6,268,1,0,0,0,1,0),
(6,269,1,0,0,0,1,0),
(6,270,1,0,0,0,1,0),
(6,271,1,0,0,0,1,0),
(6,272,1,0,0,0,1,0),
(6,273,1,0,0,0,1,0),
(6,274,1,0,0,0,1,0),
(6,275,1,0,0,0,1,0),
(6,276,1,0,0,0,1,0),
(6,277,1,0,0,0,1,0),
(6,278,1,0,0,0,1,0),
(6,279,1,0,0,0,1,0),
(6,280,1,0,0,0,1,0),
(6,281,1,0,0,0,1,0),
(6,282,1,0,0,0,1,0),
(6,283,1,0,0,0,1,0),
(6,284,1,0,0,0,1,0),
(6,285,1,0,0,0,1,0),
(6,286,1,0,0,0,1,0),
(6,287,1,0,0,0,1,0),
(6,288,1,0,0,0,1,0),
(7,1,1,0,0,0,1,0),
(7,2,1,0,0,0,1,0),
(7,3,1,0,0,0,1,0),
(7,5,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(7,6,1,0,0,0,1,0),
(7,207,1,0,0,0,1,0),
(7,209,1,0,0,0,1,0),
(7,210,1,0,0,0,1,0),
(7,211,1,0,0,0,1,0),
(7,216,1,0,0,0,1,0),
(7,217,1,0,0,0,1,0),
(7,218,1,0,0,0,1,0),
(7,219,1,0,1,0,1,0),
(7,222,1,0,0,0,1,0),
(7,224,1,0,0,0,1,0),
(7,225,1,0,0,0,1,0),
(7,228,1,1,1,0,1,0),
(7,229,1,1,1,0,1,0),
(7,230,1,1,1,0,1,0),
(7,231,1,1,1,0,1,0),
(7,232,1,0,0,0,1,0),
(7,233,1,1,1,0,1,0),
(7,238,1,0,0,0,1,0),
(7,239,1,1,1,0,1,0),
(7,240,1,1,1,0,1,0),
(7,242,1,1,1,0,1,0),
(7,243,1,1,1,0,1,0),
(7,244,1,0,0,0,1,0),
(7,248,1,1,1,0,1,1),
(7,255,1,0,0,0,1,0),
(7,256,1,0,0,0,1,0),
(7,277,1,0,0,0,1,0),
(7,278,1,0,0,0,1,0),
(7,279,1,0,0,0,1,0),
(7,286,1,0,0,0,1,0),
(7,287,1,0,0,0,1,0),
(8,1,1,0,0,0,1,0),
(8,2,1,0,0,0,1,0),
(8,3,1,0,0,0,1,0),
(8,5,1,0,0,0,1,0),
(8,6,1,0,0,0,1,0),
(8,209,1,0,0,0,1,0),
(8,210,1,0,0,0,1,0),
(8,211,1,0,0,0,1,0),
(8,214,1,0,0,0,1,0),
(8,215,1,1,1,0,1,1),
(8,220,1,1,1,0,1,0),
(8,221,1,1,1,1,1,1),
(8,222,1,1,1,1,1,0),
(8,223,1,1,1,0,1,0),
(8,224,1,0,0,0,1,0),
(8,225,1,0,1,0,1,0),
(8,227,1,1,1,0,1,0),
(8,246,1,1,1,0,1,0),
(8,247,1,1,1,0,1,1),
(8,248,1,1,1,0,1,1),
(8,251,1,0,0,0,1,0),
(8,255,1,0,0,0,1,0),
(8,256,1,0,0,0,1,0),
(8,260,1,0,0,0,1,0),
(8,261,1,0,0,0,1,0),
(8,262,1,0,0,0,1,0),
(8,263,1,0,0,0,1,0),
(8,264,1,0,0,0,1,0),
(8,265,1,0,0,0,1,0),
(8,266,1,0,0,0,1,0),
(8,267,1,0,0,0,1,0),
(8,268,1,0,0,0,1,0),
(8,269,1,0,0,0,1,0),
(8,270,1,0,0,0,1,0),
(8,271,1,0,0,0,1,0),
(8,272,1,0,0,0,1,0),
(8,273,1,0,0,0,1,0),
(8,274,1,0,0,0,1,0),
(8,275,1,0,0,0,1,0),
(8,276,1,0,0,0,1,0),
(8,277,1,0,0,0,1,0),
(8,278,1,0,0,0,1,0),
(8,279,1,0,0,0,1,0),
(8,280,1,0,0,0,1,0),
(8,281,1,0,0,0,1,0),
(8,282,1,0,0,0,1,0),
(8,283,1,0,0,0,1,0),
(8,284,1,0,0,0,1,0),
(8,285,1,0,0,0,1,0),
(8,286,1,0,0,0,1,0),
(8,287,1,0,0,0,1,0),
(8,288,1,0,0,0,1,0),
(9,1,1,0,0,0,1,0),
(9,2,1,0,0,0,1,0),
(9,3,1,0,0,0,1,0),
(9,5,1,0,0,0,1,0),
(9,6,1,0,0,0,1,0),
(9,209,1,0,0,0,1,0),
(9,210,1,0,0,0,1,0),
(9,211,1,0,0,0,1,0),
(9,216,1,0,0,0,1,0),
(9,224,1,0,0,0,1,0),
(9,228,1,1,1,0,1,0),
(9,231,1,1,1,0,1,0),
(9,232,1,0,0,0,1,0),
(9,233,1,1,1,0,1,0),
(9,239,1,1,1,0,1,0),
(9,240,1,1,1,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,243,1,1,0,0,1,0),
(9,255,1,0,0,0,1,0),
(9,256,1,0,0,0,1,0),
(10,1,1,0,0,0,1,0),
(10,2,1,0,0,0,1,0),
(10,3,1,0,0,0,1,0),
(10,5,1,0,0,0,1,0),
(10,6,1,0,0,0,1,0),
(10,10,1,1,1,0,1,0),
(10,209,1,0,0,0,1,0),
(10,231,1,0,0,0,1,0),
(10,232,1,1,1,1,1,0),
(10,233,1,0,0,0,1,0),
(10,234,1,0,0,0,1,0),
(10,235,1,1,1,0,1,0),
(10,236,1,1,1,1,1,0),
(10,255,1,0,0,0,1,0),
(10,256,1,0,0,0,1,0),
(10,278,1,0,0,0,1,0),
(10,279,1,0,0,0,1,0),
(11,1,1,0,0,0,1,0),
(11,209,1,0,0,0,1,0),
(11,210,1,0,0,0,1,0),
(11,232,1,0,0,0,1,0),
(11,233,1,1,1,0,1,0),
(11,278,1,0,0,0,1,0),
(12,1,1,0,0,0,1,0),
(12,2,1,0,0,0,1,0),
(12,3,1,0,0,0,1,0),
(12,5,1,0,0,0,1,0),
(12,6,1,0,0,0,1,0),
(12,39,1,0,0,0,1,0),
(12,42,1,1,1,0,1,0),
(12,43,1,1,1,0,1,0),
(12,46,1,0,0,0,1,0),
(12,47,1,0,0,0,1,0),
(12,48,1,0,0,0,1,0),
(12,49,1,0,0,0,1,0),
(12,50,1,0,0,0,1,0),
(12,51,1,0,0,0,1,0),
(12,52,1,0,0,0,1,0),
(12,53,1,0,0,0,1,0),
(12,54,1,0,0,0,1,0),
(12,55,1,0,0,0,1,0),
(12,209,1,0,0,0,1,0),
(12,210,1,0,0,0,1,0),
(12,211,1,0,0,0,1,0),
(12,237,1,1,1,1,1,1),
(12,238,1,1,1,1,1,1),
(12,239,1,1,1,1,1,1),
(12,255,1,0,0,0,1,0),
(12,256,1,0,0,0,1,0),
(12,280,1,0,0,0,1,0),
(13,1,1,0,0,0,1,0),
(13,2,1,0,0,0,1,0),
(13,3,1,0,0,0,1,0),
(13,5,1,0,0,0,1,0),
(13,6,1,0,0,0,1,0),
(13,101,1,0,0,0,1,0),
(13,103,1,1,1,0,1,0),
(13,116,1,0,0,0,1,0),
(13,117,1,1,1,0,1,0),
(13,118,1,1,1,0,1,0),
(13,119,1,1,1,0,1,0),
(13,120,1,0,0,0,1,0),
(13,122,1,1,1,0,1,0),
(13,123,1,1,1,0,1,0),
(13,126,1,0,0,0,1,0),
(13,129,1,0,0,0,1,0),
(13,130,1,0,0,0,1,0),
(13,131,1,0,0,0,1,0),
(13,132,1,0,0,0,1,0),
(13,133,1,0,0,0,1,0),
(13,134,1,0,0,0,1,0),
(13,135,1,0,0,0,1,0),
(13,136,1,0,0,0,1,0),
(13,137,1,0,0,0,1,0),
(13,138,1,0,0,0,1,0),
(13,139,1,0,0,0,1,0),
(13,140,1,0,0,0,1,0),
(13,141,1,0,0,0,1,0),
(13,142,1,0,0,0,1,0),
(13,143,1,0,0,0,1,0),
(13,144,1,0,0,0,1,0),
(13,145,1,0,0,0,1,0),
(13,146,1,0,0,0,1,0),
(13,147,1,0,0,0,1,0),
(13,148,1,0,0,0,1,0),
(13,209,1,0,0,0,1,0),
(13,210,1,0,0,0,1,0),
(13,240,1,0,0,0,1,0),
(13,242,1,1,1,0,1,0),
(13,243,1,1,1,0,1,0),
(13,244,1,0,0,0,1,0),
(13,255,1,0,0,0,1,0),
(13,256,1,0,0,0,1,0),
(13,277,1,0,0,0,1,0),
(14,1,1,0,0,0,1,0),
(14,2,1,0,0,0,1,0),
(14,3,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(14,5,1,0,0,0,1,0),
(14,6,1,0,0,0,1,0),
(14,95,1,1,1,0,1,0),
(14,101,1,1,1,0,1,0),
(14,106,1,0,0,0,1,0),
(14,107,1,0,0,0,1,0),
(14,108,1,0,0,0,1,0),
(14,109,1,0,0,0,1,0),
(14,110,1,0,0,0,1,0),
(14,111,1,0,0,0,1,0),
(14,112,1,0,0,0,1,0),
(14,113,1,0,0,0,1,0),
(14,114,1,0,0,0,1,0),
(14,115,1,0,0,0,1,0),
(14,116,1,0,0,0,1,0),
(14,117,1,1,1,0,1,0),
(14,209,1,0,0,0,1,0),
(14,210,1,0,0,0,1,0),
(14,240,1,0,0,0,1,0),
(14,241,1,1,1,0,1,0),
(14,242,1,0,0,0,1,0),
(14,244,1,0,0,0,1,0),
(14,245,1,1,1,0,1,0),
(14,255,1,0,0,0,1,0),
(14,256,1,0,0,0,1,0),
(14,264,1,0,0,0,1,0),
(14,283,1,0,0,0,1,0),
(15,1,1,0,0,0,1,0),
(15,2,1,0,0,0,1,0),
(15,3,1,0,0,0,1,0),
(15,5,1,0,0,0,1,0),
(15,6,1,0,0,0,1,0),
(15,95,1,1,1,0,1,0),
(15,101,1,1,1,1,1,1),
(15,106,1,0,0,0,1,0),
(15,107,1,0,0,0,1,0),
(15,108,1,0,0,0,1,0),
(15,109,1,0,0,0,1,0),
(15,110,1,0,0,0,1,0),
(15,111,1,0,0,0,1,0),
(15,112,1,0,0,0,1,0),
(15,113,1,0,0,0,1,0),
(15,114,1,0,0,0,1,0),
(15,115,1,0,0,0,1,0),
(15,116,1,0,0,0,1,0),
(15,117,1,1,1,0,1,0),
(15,209,1,0,0,0,1,0),
(15,210,1,0,0,0,1,0),
(15,211,1,0,0,0,1,0),
(15,240,1,1,1,0,1,1),
(15,241,1,1,1,0,1,1),
(15,242,1,1,1,0,1,1),
(15,243,1,1,1,0,1,1),
(15,244,1,1,1,0,1,1),
(15,245,1,1,1,0,1,1),
(15,246,1,1,1,0,1,1),
(15,247,1,1,1,0,1,1),
(15,255,1,0,0,0,1,0),
(15,256,1,0,0,0,1,0),
(15,264,1,0,0,0,1,0),
(15,277,1,0,0,0,1,0),
(15,281,1,0,0,0,1,0),
(15,283,1,0,0,0,1,0),
(16,1,1,0,0,0,1,0),
(16,2,1,0,0,0,1,0),
(16,3,1,0,0,0,1,0),
(16,5,1,0,0,0,1,0),
(16,6,1,0,0,0,1,0),
(16,8,1,0,0,0,1,0),
(16,9,1,0,0,0,1,0),
(16,10,1,1,1,0,1,0),
(16,13,1,1,1,0,1,0),
(16,16,1,0,0,0,1,0),
(16,17,1,0,0,0,1,0),
(16,18,1,0,0,0,1,0),
(16,19,1,0,0,0,1,0),
(16,20,1,0,0,0,1,0),
(16,21,1,0,0,0,1,0),
(16,22,1,0,0,0,1,0),
(16,23,1,0,0,0,1,0),
(16,24,1,0,0,0,1,0),
(16,25,1,0,0,0,1,0),
(16,26,1,0,0,0,1,0),
(16,27,1,0,0,0,1,0),
(16,28,1,0,0,0,1,0),
(16,29,1,0,0,0,1,0),
(16,30,1,0,0,0,1,0),
(16,31,1,0,0,0,1,0),
(16,32,1,0,0,0,1,0),
(16,33,1,0,0,0,1,0),
(16,34,1,0,0,0,1,0),
(16,35,1,0,0,0,1,0),
(16,36,1,0,0,0,1,0),
(16,37,1,0,0,0,1,0),
(16,38,1,0,0,0,1,0),
(16,46,1,0,0,0,1,0),
(16,47,1,0,0,0,1,0),
(16,48,1,0,0,0,1,0),
(16,49,1,0,0,0,1,0),
(16,50,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(16,51,1,0,0,0,1,0),
(16,52,1,0,0,0,1,0),
(16,53,1,0,0,0,1,0),
(16,54,1,0,0,0,1,0),
(16,55,1,0,0,0,1,0),
(16,56,1,1,1,0,1,0),
(16,57,1,1,1,0,1,0),
(16,58,1,1,1,0,1,0),
(16,59,1,1,1,0,1,0),
(16,60,1,1,1,0,1,0),
(16,61,1,1,1,0,1,0),
(16,62,1,1,1,0,1,0),
(16,63,1,1,1,0,1,0),
(16,64,1,1,1,0,1,0),
(16,65,1,1,1,0,1,0),
(16,66,1,1,1,0,1,0),
(16,67,1,1,1,0,1,0),
(16,68,1,0,0,0,1,0),
(16,69,1,0,0,0,1,0),
(16,70,1,0,0,0,1,0),
(16,71,1,0,0,0,1,0),
(16,72,1,0,0,0,1,0),
(16,73,1,0,0,0,1,0),
(16,74,1,0,0,0,1,0),
(16,75,1,0,0,0,1,0),
(16,76,1,0,0,0,1,0),
(16,77,1,0,0,0,1,0),
(16,78,1,0,0,0,1,0),
(16,79,1,0,0,0,1,0),
(16,80,1,0,0,0,1,0),
(16,81,1,0,0,0,1,0),
(16,82,1,0,0,0,1,0),
(16,83,1,0,0,0,1,0),
(16,84,1,0,0,0,1,0),
(16,85,1,0,0,0,1,0),
(16,86,1,0,0,0,1,0),
(16,87,1,0,0,0,1,0),
(16,88,1,0,0,0,1,0),
(16,89,1,0,0,0,1,0),
(16,90,1,0,0,0,1,0),
(16,91,1,0,0,0,1,0),
(16,92,1,0,0,0,1,0),
(16,93,1,0,0,0,1,0),
(16,94,1,0,0,0,1,0),
(16,95,1,1,1,0,1,0),
(16,96,1,1,1,0,1,0),
(16,97,1,1,1,0,1,0),
(16,98,1,1,1,0,1,0),
(16,99,1,1,1,0,1,0),
(16,100,1,1,1,0,1,0),
(16,101,1,1,1,0,1,0),
(16,102,1,1,1,0,1,0),
(16,103,1,1,1,0,1,0),
(16,104,1,1,1,0,1,0),
(16,105,1,1,1,0,1,0),
(16,106,1,0,0,0,1,0),
(16,107,1,0,0,0,1,0),
(16,108,1,0,0,0,1,0),
(16,109,1,0,0,0,1,0),
(16,110,1,0,0,0,1,0),
(16,111,1,0,0,0,1,0),
(16,112,1,0,0,0,1,0),
(16,113,1,0,0,0,1,0),
(16,114,1,0,0,0,1,0),
(16,115,1,0,0,0,1,0),
(16,116,1,0,0,0,1,0),
(16,129,1,0,0,0,1,0),
(16,130,1,0,0,0,1,0),
(16,131,1,0,0,0,1,0),
(16,132,1,0,0,0,1,0),
(16,133,1,0,0,0,1,0),
(16,134,1,0,0,0,1,0),
(16,135,1,0,0,0,1,0),
(16,136,1,0,0,0,1,0),
(16,137,1,0,0,0,1,0),
(16,138,1,0,0,0,1,0),
(16,139,1,0,0,0,1,0),
(16,140,1,0,0,0,1,0),
(16,141,1,0,0,0,1,0),
(16,142,1,0,0,0,1,0),
(16,143,1,0,0,0,1,0),
(16,144,1,0,0,0,1,0),
(16,145,1,0,0,0,1,0),
(16,146,1,0,0,0,1,0),
(16,147,1,0,0,0,1,0),
(16,148,1,0,0,0,1,0),
(16,166,1,1,1,0,1,0),
(16,167,1,1,1,0,1,0),
(16,168,1,1,1,0,1,0),
(16,169,1,1,1,0,1,0),
(16,170,1,1,1,0,1,0),
(16,171,1,1,1,0,1,0),
(16,172,1,0,0,0,1,0),
(16,173,1,0,0,0,1,0),
(16,174,1,0,0,0,1,0),
(16,175,1,0,0,0,1,0),
(16,176,1,0,0,0,1,0),
(16,178,1,0,0,0,1,0),
(16,179,1,0,0,0,1,0),
(16,180,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(16,181,1,0,0,0,1,0),
(16,182,1,0,0,0,1,0),
(16,183,1,0,0,0,1,0),
(16,184,1,0,0,0,1,0),
(16,185,1,0,0,0,1,0),
(16,209,1,0,0,0,1,0),
(16,210,1,0,0,0,1,0),
(16,234,1,1,1,0,1,0),
(16,235,1,1,1,0,1,0),
(16,247,1,0,0,0,1,0),
(16,250,1,0,0,0,1,0),
(16,251,1,0,0,0,1,0),
(16,253,1,1,1,0,1,0),
(16,255,1,0,0,0,1,0),
(16,256,1,0,0,0,1,0),
(17,1,1,0,0,0,1,0),
(17,2,1,0,0,0,1,0),
(17,3,1,0,0,0,1,0),
(17,5,1,0,0,0,1,0),
(17,6,1,0,0,0,1,0),
(17,8,1,1,1,1,1,1),
(17,9,1,1,1,1,1,1),
(17,10,1,1,1,1,1,1),
(17,11,1,1,1,1,1,1),
(17,12,1,1,1,1,1,1),
(17,13,1,1,1,1,1,1),
(17,14,1,1,1,1,1,1),
(17,15,1,1,1,1,1,1),
(17,16,1,1,1,1,1,1),
(17,17,1,1,1,1,1,1),
(17,18,1,1,1,1,1,1),
(17,19,1,1,1,1,1,1),
(17,20,1,1,1,1,1,1),
(17,21,1,1,1,1,1,1),
(17,22,1,1,1,1,1,1),
(17,23,1,1,1,1,1,1),
(17,24,1,1,1,1,1,1),
(17,25,1,1,1,1,1,1),
(17,26,1,1,1,1,1,1),
(17,27,1,1,1,1,1,1),
(17,28,1,1,1,1,1,1),
(17,29,1,1,1,1,1,1),
(17,30,1,1,1,1,1,1),
(17,31,1,1,1,1,1,1),
(17,32,1,1,1,1,1,1),
(17,33,1,1,1,1,1,1),
(17,34,1,1,1,1,1,1),
(17,35,1,1,1,1,1,1),
(17,36,1,1,1,1,1,1),
(17,37,1,1,1,1,1,1),
(17,38,1,1,1,1,1,1),
(17,39,1,1,1,1,1,1),
(17,40,1,1,1,1,1,1),
(17,41,1,1,1,1,1,1),
(17,42,1,1,1,1,1,1),
(17,43,1,1,1,1,1,1),
(17,44,1,1,1,1,1,1),
(17,45,1,1,1,1,1,1),
(17,46,1,1,1,1,1,1),
(17,47,1,1,1,1,1,1),
(17,48,1,1,1,1,1,1),
(17,49,1,1,1,1,1,1),
(17,50,1,1,1,1,1,1),
(17,51,1,1,1,1,1,1),
(17,52,1,1,1,1,1,1),
(17,53,1,1,1,1,1,1),
(17,54,1,1,1,1,1,1),
(17,55,1,1,1,1,1,1),
(17,56,1,1,1,1,1,1),
(17,57,1,1,1,1,1,1),
(17,58,1,1,1,1,1,1),
(17,59,1,1,1,1,1,1),
(17,60,1,1,1,1,1,1),
(17,61,1,1,1,1,1,1),
(17,62,1,1,1,1,1,1),
(17,63,1,1,1,1,1,1),
(17,64,1,1,1,1,1,1),
(17,65,1,1,1,1,1,1),
(17,66,1,1,1,1,1,1),
(17,67,1,1,1,1,1,1),
(17,68,1,1,1,1,1,1),
(17,69,1,1,1,1,1,1),
(17,70,1,1,1,1,1,1),
(17,71,1,1,1,1,1,1),
(17,72,1,1,1,1,1,1),
(17,73,1,1,1,1,1,1),
(17,74,1,1,1,1,1,1),
(17,75,1,1,1,1,1,1),
(17,76,1,1,1,1,1,1),
(17,77,1,1,1,1,1,1),
(17,78,1,1,1,1,1,1),
(17,79,1,1,1,1,1,1),
(17,80,1,1,1,1,1,1),
(17,81,1,1,1,1,1,1),
(17,82,1,1,1,1,1,1),
(17,83,1,1,1,1,1,1),
(17,84,1,1,1,1,1,1),
(17,85,1,1,1,1,1,1),
(17,86,1,1,1,1,1,1),
(17,87,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(17,88,1,1,1,1,1,1),
(17,89,1,1,1,1,1,1),
(17,90,1,1,1,1,1,1),
(17,91,1,1,1,1,1,1),
(17,92,1,1,1,1,1,1),
(17,93,1,1,1,1,1,1),
(17,94,1,1,1,1,1,1),
(17,95,1,1,1,1,1,1),
(17,96,1,1,1,1,1,1),
(17,97,1,1,1,1,1,1),
(17,98,1,1,1,1,1,1),
(17,99,1,1,1,1,1,1),
(17,100,1,1,1,1,1,1),
(17,101,1,1,1,1,1,1),
(17,102,1,1,1,1,1,1),
(17,103,1,1,1,1,1,1),
(17,104,1,1,1,1,1,1),
(17,105,1,1,1,1,1,1),
(17,106,1,1,1,1,1,1),
(17,107,1,1,1,1,1,1),
(17,108,1,1,1,1,1,1),
(17,109,1,1,1,1,1,1),
(17,110,1,1,1,1,1,1),
(17,111,1,1,1,1,1,1),
(17,112,1,1,1,1,1,1),
(17,113,1,1,1,1,1,1),
(17,114,1,1,1,1,1,1),
(17,115,1,1,1,1,1,1),
(17,116,1,0,0,0,1,0),
(17,117,1,0,0,0,1,0),
(17,129,1,0,0,0,1,0),
(17,130,1,0,0,0,1,0),
(17,131,1,0,0,0,1,0),
(17,132,1,0,0,0,1,0),
(17,133,1,0,0,0,1,0),
(17,134,1,0,0,0,1,0),
(17,135,1,0,0,0,1,0),
(17,136,1,0,0,0,1,0),
(17,137,1,0,0,0,1,0),
(17,138,1,0,0,0,1,0),
(17,139,1,0,0,0,1,0),
(17,140,1,0,0,0,1,0),
(17,141,1,0,0,0,1,0),
(17,142,1,0,0,0,1,0),
(17,143,1,0,0,0,1,0),
(17,144,1,0,0,0,1,0),
(17,145,1,0,0,0,1,0),
(17,146,1,0,0,0,1,0),
(17,147,1,0,0,0,1,0),
(17,148,1,0,0,0,1,0),
(17,166,1,1,1,1,1,1),
(17,167,1,1,1,1,1,1),
(17,168,1,1,1,1,1,1),
(17,169,1,1,1,1,1,1),
(17,170,1,1,1,1,1,1),
(17,171,1,1,1,1,1,1),
(17,172,1,1,1,1,1,1),
(17,173,1,1,1,1,1,1),
(17,174,1,1,1,1,1,1),
(17,175,1,1,1,1,1,1),
(17,176,1,1,1,1,1,1),
(17,177,1,1,1,1,1,1),
(17,178,1,1,1,1,1,1),
(17,179,1,1,1,1,1,1),
(17,180,1,1,1,1,1,1),
(17,181,1,1,1,1,1,1),
(17,182,1,1,1,1,1,1),
(17,183,1,1,1,1,1,1),
(17,184,1,1,1,1,1,1),
(17,185,1,1,1,1,1,1),
(17,191,1,0,0,0,1,0),
(17,195,1,0,0,0,1,0),
(17,196,1,0,0,0,1,0),
(17,208,1,0,0,0,1,0),
(17,209,1,0,0,0,1,0),
(17,210,1,0,0,0,1,0),
(17,211,1,0,0,0,1,0),
(17,214,1,0,0,0,1,0),
(17,223,1,0,0,0,1,0),
(17,234,1,1,1,1,1,1),
(17,235,1,0,0,0,1,1),
(17,247,1,0,0,0,1,1),
(17,248,1,1,1,0,1,1),
(17,249,1,1,1,0,1,1),
(17,250,1,1,1,0,1,1),
(17,251,1,1,1,0,1,1),
(17,252,1,1,1,1,1,1),
(17,253,1,1,1,1,1,1),
(17,254,1,1,1,1,1,1),
(17,255,1,0,0,0,1,0),
(17,256,1,0,0,0,1,0),
(17,260,1,0,0,0,1,0),
(17,261,1,0,0,0,1,0),
(17,262,1,0,0,0,1,0),
(17,263,1,0,0,0,1,0),
(17,264,1,0,0,0,1,0),
(17,265,1,0,0,0,1,0),
(17,266,1,0,0,0,1,0),
(17,267,1,0,0,0,1,0),
(17,268,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(17,269,1,0,0,0,1,0),
(17,270,1,0,0,0,1,0),
(17,271,1,0,0,0,1,0),
(17,272,1,0,0,0,1,0),
(17,273,1,0,0,0,1,0),
(17,274,1,0,0,0,1,0),
(17,275,1,0,0,0,1,0),
(17,276,1,0,0,0,1,0),
(17,277,1,0,0,0,1,0),
(17,278,1,0,0,0,1,0),
(17,279,1,0,0,0,1,0),
(17,280,1,0,0,0,1,0),
(17,281,1,0,0,0,1,0),
(17,282,1,0,0,0,1,0),
(17,283,1,0,0,0,1,0),
(17,284,1,0,0,0,1,0),
(17,285,1,0,0,0,1,0),
(17,286,1,0,0,0,1,0),
(17,287,1,0,0,0,1,0),
(17,288,1,0,0,0,1,0),
(18,1,1,0,0,0,1,0),
(18,2,1,0,0,0,1,0),
(18,3,1,0,0,0,1,0),
(18,5,1,0,0,0,1,0),
(18,6,1,0,0,0,1,0),
(18,8,1,0,0,0,1,1),
(18,9,1,0,0,0,1,1),
(18,10,1,0,0,0,1,1),
(18,11,1,0,0,0,1,1),
(18,12,1,0,0,0,1,1),
(18,13,1,0,0,0,1,1),
(18,14,1,0,0,0,1,1),
(18,15,1,0,0,0,1,1),
(18,16,1,0,0,0,1,1),
(18,17,1,0,0,0,1,1),
(18,18,1,0,0,0,1,1),
(18,19,1,0,0,0,1,1),
(18,20,1,0,0,0,1,1),
(18,21,1,0,0,0,1,1),
(18,22,1,0,0,0,1,1),
(18,23,1,0,0,0,1,1),
(18,24,1,0,0,0,1,1),
(18,25,1,0,0,0,1,1),
(18,26,1,0,0,0,1,1),
(18,27,1,0,0,0,1,1),
(18,28,1,0,0,0,1,1),
(18,29,1,0,0,0,1,1),
(18,30,1,0,0,0,1,1),
(18,31,1,0,0,0,1,1),
(18,32,1,0,0,0,1,1),
(18,33,1,0,0,0,1,1),
(18,34,1,0,0,0,1,1),
(18,35,1,0,0,0,1,1),
(18,36,1,0,0,0,1,1),
(18,37,1,0,0,0,1,1),
(18,38,1,0,0,0,1,1),
(18,46,1,0,0,0,1,0),
(18,47,1,0,0,0,1,0),
(18,48,1,0,0,0,1,0),
(18,49,1,0,0,0,1,0),
(18,50,1,0,0,0,1,0),
(18,51,1,0,0,0,1,0),
(18,52,1,0,0,0,1,0),
(18,53,1,0,0,0,1,0),
(18,54,1,0,0,0,1,0),
(18,55,1,0,0,0,1,0),
(18,56,1,1,1,0,1,1),
(18,57,1,1,1,0,1,1),
(18,58,1,1,1,0,1,1),
(18,59,1,1,1,0,1,1),
(18,60,1,1,1,0,1,1),
(18,61,1,1,1,0,1,1),
(18,62,1,1,1,0,1,1),
(18,63,1,1,1,0,1,1),
(18,64,1,1,1,0,1,1),
(18,65,1,1,1,0,1,1),
(18,66,1,1,1,0,1,1),
(18,67,1,1,1,0,1,1),
(18,68,1,1,1,0,1,1),
(18,69,1,1,1,0,1,1),
(18,70,1,1,1,0,1,1),
(18,71,1,1,1,0,1,1),
(18,72,1,1,1,0,1,1),
(18,73,1,1,1,0,1,1),
(18,74,1,1,1,0,1,1),
(18,75,1,1,1,0,1,1),
(18,76,1,1,1,0,1,1),
(18,77,1,1,1,0,1,1),
(18,78,1,1,1,0,1,1),
(18,79,1,1,1,0,1,1),
(18,80,1,1,1,0,1,1),
(18,81,1,1,1,0,1,1),
(18,82,1,1,1,0,1,1),
(18,83,1,1,1,0,1,1),
(18,84,1,1,1,0,1,1),
(18,85,1,1,1,0,1,1),
(18,86,1,1,1,0,1,1),
(18,87,1,1,1,0,1,1),
(18,88,1,1,1,0,1,1),
(18,89,1,1,1,0,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(18,90,1,1,1,0,1,1),
(18,91,1,1,1,0,1,1),
(18,92,1,1,1,0,1,1),
(18,93,1,1,1,0,1,1),
(18,94,1,1,1,0,1,1),
(18,95,1,1,1,0,1,1),
(18,96,1,1,1,0,1,1),
(18,97,1,1,1,0,1,1),
(18,98,1,1,1,0,1,1),
(18,99,1,1,1,0,1,1),
(18,100,1,1,1,0,1,1),
(18,101,1,1,1,0,1,1),
(18,102,1,1,1,0,1,1),
(18,103,1,1,1,0,1,1),
(18,104,1,1,1,0,1,1),
(18,105,1,1,1,0,1,1),
(18,106,1,1,1,0,1,1),
(18,107,1,1,1,0,1,1),
(18,108,1,1,1,0,1,1),
(18,109,1,1,1,0,1,1),
(18,110,1,1,1,0,1,1),
(18,111,1,1,1,0,1,1),
(18,112,1,1,1,0,1,1),
(18,113,1,1,1,0,1,1),
(18,114,1,1,1,0,1,1),
(18,115,1,1,1,0,1,1),
(18,129,1,0,0,0,1,0),
(18,130,1,0,0,0,1,0),
(18,131,1,0,0,0,1,0),
(18,132,1,0,0,0,1,0),
(18,133,1,0,0,0,1,0),
(18,134,1,0,0,0,1,0),
(18,135,1,0,0,0,1,0),
(18,136,1,0,0,0,1,0),
(18,137,1,0,0,0,1,0),
(18,138,1,0,0,0,1,0),
(18,139,1,0,0,0,1,0),
(18,140,1,0,0,0,1,0),
(18,141,1,0,0,0,1,0),
(18,142,1,0,0,0,1,0),
(18,143,1,0,0,0,1,0),
(18,144,1,0,0,0,1,0),
(18,145,1,0,0,0,1,0),
(18,146,1,0,0,0,1,0),
(18,147,1,0,0,0,1,0),
(18,148,1,0,0,0,1,0),
(18,166,1,1,1,1,1,1),
(18,167,1,1,1,1,1,1),
(18,168,1,1,1,1,1,1),
(18,169,1,1,1,1,1,1),
(18,170,1,1,1,1,1,1),
(18,171,1,1,1,1,1,1),
(18,172,1,1,1,1,1,1),
(18,173,1,1,1,1,1,1),
(18,174,1,1,1,1,1,1),
(18,175,1,1,1,1,1,1),
(18,176,1,0,0,0,1,0),
(18,177,1,0,0,0,1,0),
(18,178,1,0,0,0,1,0),
(18,179,1,0,0,0,1,0),
(18,180,1,0,0,0,1,0),
(18,181,1,0,0,0,1,0),
(18,182,1,0,0,0,1,0),
(18,183,1,0,0,0,1,0),
(18,184,1,0,0,0,1,0),
(18,185,1,0,0,0,1,0),
(18,206,1,0,0,0,1,0),
(18,208,1,0,0,0,1,0),
(18,209,1,0,0,0,1,0),
(18,210,1,0,0,0,1,0),
(18,211,1,0,0,0,1,0),
(18,214,1,0,0,0,1,0),
(18,215,1,0,0,0,1,1),
(18,219,1,0,0,0,1,1),
(18,223,1,0,0,0,1,1),
(18,234,1,0,0,0,1,1),
(18,235,1,0,0,0,1,1),
(18,240,1,0,0,0,1,1),
(18,241,1,0,0,0,1,1),
(18,245,1,0,0,0,1,1),
(18,246,1,0,0,0,1,1),
(18,247,1,0,0,0,1,1),
(18,248,1,1,1,0,1,1),
(18,249,1,1,1,0,1,1),
(18,250,1,1,1,0,1,1),
(18,251,1,1,1,0,1,1),
(18,252,1,0,0,0,1,1),
(18,253,1,0,0,0,1,1),
(18,254,1,0,0,0,1,1),
(18,255,1,0,0,0,1,0),
(18,256,1,0,0,0,1,0),
(18,260,1,0,0,0,1,0),
(18,261,1,0,0,0,1,0),
(18,262,1,0,0,0,1,0),
(18,263,1,0,0,0,1,0),
(18,264,1,0,0,0,1,0),
(18,265,1,0,0,0,1,0),
(18,266,1,0,0,0,1,0),
(18,267,1,0,0,0,1,0),
(18,268,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(18,269,1,0,0,0,1,0),
(18,270,1,0,0,0,1,0),
(18,271,1,0,0,0,1,0),
(18,272,1,0,0,0,1,0),
(18,273,1,0,0,0,1,0),
(18,274,1,0,0,0,1,0),
(18,275,1,0,0,0,1,0),
(18,276,1,0,0,0,1,0),
(18,277,1,0,0,0,1,0),
(18,278,1,0,0,0,1,0),
(18,279,1,0,0,0,1,0),
(18,280,1,0,0,0,1,0),
(18,281,1,0,0,0,1,0),
(18,282,1,0,0,0,1,0),
(18,283,1,0,0,0,1,0),
(18,284,1,0,0,0,1,0),
(18,285,1,0,0,0,1,0),
(18,286,1,0,0,0,1,0),
(18,287,1,0,0,0,1,0),
(18,288,1,0,0,0,1,0),
(19,1,1,0,0,0,1,0),
(19,2,1,0,0,0,1,0),
(19,3,1,0,0,0,1,0),
(19,5,1,0,0,0,1,0),
(19,6,1,0,0,0,1,0),
(19,56,1,0,0,0,1,0),
(19,59,1,1,1,0,1,0),
(19,63,1,1,0,0,1,0),
(19,67,1,1,1,0,1,0),
(19,68,1,0,0,0,1,0),
(19,69,1,0,0,0,1,0),
(19,70,1,0,0,0,1,0),
(19,71,1,0,0,0,1,0),
(19,72,1,0,0,0,1,0),
(19,73,1,0,0,0,1,0),
(19,74,1,0,0,0,1,0),
(19,75,1,0,0,0,1,0),
(19,76,1,0,0,0,1,0),
(19,77,1,0,0,0,1,0),
(19,78,1,0,0,0,1,0),
(19,79,1,0,0,0,1,0),
(19,80,1,0,0,0,1,0),
(19,81,1,0,0,0,1,0),
(19,82,1,0,0,0,1,0),
(19,83,1,0,0,0,1,0),
(19,84,1,0,0,0,1,0),
(19,85,1,0,0,0,1,0),
(19,86,1,0,0,0,1,0),
(19,87,1,0,0,0,1,0),
(19,88,1,0,0,0,1,0),
(19,89,1,0,0,0,1,0),
(19,90,1,0,0,0,1,0),
(19,91,1,0,0,0,1,0),
(19,92,1,0,0,0,1,0),
(19,93,1,0,0,0,1,0),
(19,94,1,0,0,0,1,0),
(19,209,1,0,0,0,1,0),
(19,210,1,0,0,0,1,0),
(19,211,1,0,0,0,1,0),
(19,222,1,0,0,0,1,0),
(19,225,1,0,0,0,1,0),
(19,248,1,1,1,0,1,0),
(19,250,1,0,0,0,1,0),
(19,251,1,0,0,0,1,0),
(19,255,1,0,0,0,1,0),
(19,256,1,0,0,0,1,0),
(19,270,1,0,0,0,1,0),
(19,272,1,0,0,0,1,0),
(19,273,1,0,0,0,1,0),
(19,274,1,0,0,0,1,0),
(19,275,1,0,0,0,1,0),
(20,1,1,0,0,0,1,0),
(20,2,1,0,0,0,1,0),
(20,3,1,0,0,0,1,0),
(20,5,1,0,0,0,1,0),
(20,6,1,0,0,0,1,0),
(20,56,1,0,0,0,1,0),
(20,66,1,1,1,0,1,0),
(20,68,1,0,0,0,1,0),
(20,69,1,0,0,0,1,0),
(20,72,1,0,0,0,1,0),
(20,73,1,0,0,0,1,0),
(20,149,1,1,0,0,1,0),
(20,150,1,1,0,0,1,0),
(20,151,1,1,0,0,1,0),
(20,169,1,1,1,0,1,0),
(20,209,1,0,0,0,1,0),
(20,250,1,0,0,0,1,0),
(20,255,1,0,0,0,1,0),
(20,256,1,0,0,0,1,0),
(20,273,1,0,0,0,1,0),
(20,274,1,0,0,0,1,0),
(21,1,1,0,0,0,1,0),
(21,2,1,0,0,0,1,0),
(21,3,1,0,0,0,1,0),
(21,5,1,0,0,0,1,0),
(21,6,1,0,0,0,1,0),
(21,209,1,0,0,0,1,0),
(21,210,1,0,0,0,1,0),
(21,211,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(21,228,1,0,0,0,1,0),
(21,229,1,1,1,0,1,0),
(21,231,1,1,1,1,1,1),
(21,237,1,0,0,0,1,0),
(21,255,1,0,0,0,1,0),
(21,256,1,0,0,0,1,0),
(21,288,1,0,0,0,1,0),
(22,1,1,0,0,0,1,0),
(22,2,1,0,0,0,1,0),
(22,3,1,0,0,0,1,0),
(22,5,1,0,0,0,1,0),
(22,6,1,0,0,0,1,0),
(22,209,1,0,0,0,1,0),
(22,210,1,0,0,0,1,0),
(22,211,1,0,0,0,1,0),
(22,216,1,0,0,0,1,0),
(22,219,1,0,1,0,1,0),
(22,225,1,0,1,0,1,0),
(22,228,1,0,0,0,1,0),
(22,229,1,1,1,1,1,1),
(22,230,1,1,1,1,1,1),
(22,255,1,0,0,0,1,0),
(22,256,1,0,0,0,1,0),
(22,288,1,0,0,0,1,0),
(23,1,1,0,0,0,1,0),
(23,2,1,0,0,0,1,0),
(23,3,1,0,0,0,1,0),
(23,4,1,0,0,0,1,0),
(23,5,1,0,0,0,1,0),
(23,6,1,0,0,0,1,0),
(23,7,1,0,0,0,1,0),
(23,8,1,0,0,0,1,0),
(23,9,1,0,0,0,1,0),
(23,10,1,0,0,0,1,0),
(23,11,1,0,0,0,1,0),
(23,12,1,0,0,0,1,0),
(23,13,1,0,0,0,1,0),
(23,14,1,0,0,0,1,0),
(23,15,1,0,0,0,1,0),
(23,16,1,0,0,0,1,0),
(23,17,1,0,0,0,1,0),
(23,18,1,0,0,0,1,0),
(23,19,1,0,0,0,1,0),
(23,20,1,0,0,0,1,0),
(23,21,1,0,0,0,1,0),
(23,22,1,0,0,0,1,0),
(23,23,1,0,0,0,1,0),
(23,24,1,0,0,0,1,0),
(23,25,1,0,0,0,1,0),
(23,26,1,0,0,0,1,0),
(23,27,1,0,0,0,1,0),
(23,28,1,0,0,0,1,0),
(23,29,1,0,0,0,1,0),
(23,30,1,0,0,0,1,0),
(23,31,1,0,0,0,1,0),
(23,32,1,0,0,0,1,0),
(23,33,1,0,0,0,1,0),
(23,34,1,0,0,0,1,0),
(23,35,1,0,0,0,1,0),
(23,36,1,0,0,0,1,0),
(23,37,1,0,0,0,1,0),
(23,38,1,0,0,0,1,0),
(23,39,1,0,0,0,1,0),
(23,40,1,0,0,0,1,0),
(23,41,1,0,0,0,1,0),
(23,42,1,0,0,0,1,0),
(23,43,1,0,0,0,1,0),
(23,44,1,0,0,0,1,0),
(23,45,1,0,0,0,1,0),
(23,46,1,0,0,0,1,0),
(23,47,1,0,0,0,1,0),
(23,48,1,0,0,0,1,0),
(23,49,1,0,0,0,1,0),
(23,50,1,0,0,0,1,0),
(23,51,1,0,0,0,1,0),
(23,52,1,0,0,0,1,0),
(23,53,1,0,0,0,1,0),
(23,54,1,0,0,0,1,0),
(23,55,1,0,0,0,1,0),
(23,56,1,0,0,0,1,0),
(23,57,1,0,0,0,1,0),
(23,58,1,0,0,0,1,0),
(23,59,1,0,0,0,1,0),
(23,60,1,0,0,0,1,0),
(23,61,1,0,0,0,1,0),
(23,62,1,0,0,0,1,0),
(23,63,1,0,0,0,1,0),
(23,64,1,0,0,0,1,0),
(23,65,1,0,0,0,1,0),
(23,66,1,0,0,0,1,0),
(23,67,1,0,0,0,1,0),
(23,68,1,0,0,0,1,0),
(23,69,1,0,0,0,1,0),
(23,70,1,0,0,0,1,0),
(23,71,1,0,0,0,1,0),
(23,72,1,0,0,0,1,0),
(23,73,1,0,0,0,1,0),
(23,74,1,0,0,0,1,0),
(23,75,1,0,0,0,1,0),
(23,76,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(23,77,1,0,0,0,1,0),
(23,78,1,0,0,0,1,0),
(23,79,1,0,0,0,1,0),
(23,80,1,0,0,0,1,0),
(23,81,1,0,0,0,1,0),
(23,82,1,0,0,0,1,0),
(23,83,1,0,0,0,1,0),
(23,84,1,0,0,0,1,0),
(23,85,1,0,0,0,1,0),
(23,86,1,0,0,0,1,0),
(23,87,1,0,0,0,1,0),
(23,88,1,0,0,0,1,0),
(23,89,1,0,0,0,1,0),
(23,90,1,0,0,0,1,0),
(23,91,1,0,0,0,1,0),
(23,92,1,0,0,0,1,0),
(23,93,1,0,0,0,1,0),
(23,94,1,0,0,0,1,0),
(23,95,1,0,0,0,1,0),
(23,96,1,0,0,0,1,0),
(23,97,1,0,0,0,1,0),
(23,98,1,0,0,0,1,0),
(23,99,1,0,0,0,1,0),
(23,100,1,0,0,0,1,0),
(23,101,1,0,0,0,1,0),
(23,102,1,0,0,0,1,0),
(23,103,1,0,0,0,1,0),
(23,104,1,0,0,0,1,0),
(23,105,1,0,0,0,1,0),
(23,106,1,0,0,0,1,0),
(23,107,1,0,0,0,1,0),
(23,108,1,0,0,0,1,0),
(23,109,1,0,0,0,1,0),
(23,110,1,0,0,0,1,0),
(23,111,1,0,0,0,1,0),
(23,112,1,0,0,0,1,0),
(23,113,1,0,0,0,1,0),
(23,114,1,0,0,0,1,0),
(23,115,1,0,0,0,1,0),
(23,116,1,0,0,0,1,0),
(23,117,1,0,0,0,1,0),
(23,118,1,0,0,0,1,0),
(23,119,1,0,0,0,1,0),
(23,120,1,0,0,0,1,0),
(23,121,1,0,0,0,1,0),
(23,122,1,0,0,0,1,0),
(23,123,1,0,0,0,1,0),
(23,124,1,0,0,0,1,0),
(23,125,1,0,0,0,1,0),
(23,126,1,0,0,0,1,0),
(23,127,1,0,0,0,1,0),
(23,128,1,0,0,0,1,0),
(23,129,1,0,0,0,1,0),
(23,130,1,0,0,0,1,0),
(23,131,1,0,0,0,1,0),
(23,132,1,0,0,0,1,0),
(23,133,1,0,0,0,1,0),
(23,134,1,0,0,0,1,0),
(23,135,1,0,0,0,1,0),
(23,136,1,0,0,0,1,0),
(23,137,1,0,0,0,1,0),
(23,138,1,0,0,0,1,0),
(23,139,1,0,0,0,1,0),
(23,140,1,0,0,0,1,0),
(23,141,1,0,0,0,1,0),
(23,142,1,0,0,0,1,0),
(23,143,1,0,0,0,1,0),
(23,144,1,0,0,0,1,0),
(23,145,1,0,0,0,1,0),
(23,146,1,0,0,0,1,0),
(23,147,1,0,0,0,1,0),
(23,148,1,0,0,0,1,0),
(23,151,1,0,0,0,1,0),
(23,152,1,0,0,0,1,0),
(23,153,1,0,0,0,1,0),
(23,154,1,0,0,0,1,0),
(23,155,1,0,0,0,1,0),
(23,156,1,0,0,0,1,0),
(23,157,1,0,0,0,1,0),
(23,158,1,0,0,0,1,0),
(23,159,1,0,0,0,1,0),
(23,160,1,0,0,0,1,0),
(23,161,1,0,0,0,1,0),
(23,162,1,0,0,0,1,0),
(23,163,1,0,0,0,1,0),
(23,164,1,0,0,0,1,0),
(23,165,1,0,0,0,1,0),
(23,166,1,0,0,0,1,0),
(23,167,1,0,0,0,1,0),
(23,168,1,0,0,0,1,0),
(23,169,1,0,0,0,1,0),
(23,170,1,0,0,0,1,0),
(23,171,1,0,0,0,1,0),
(23,172,1,0,0,0,1,0),
(23,173,1,0,0,0,1,0),
(23,174,1,0,0,0,1,0),
(23,175,1,0,0,0,1,0),
(23,176,1,0,0,0,1,0),
(23,177,1,0,0,0,1,0),
(23,178,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(23,179,1,0,0,0,1,0),
(23,180,1,0,0,0,1,0),
(23,181,1,0,0,0,1,0),
(23,182,1,0,0,0,1,0),
(23,183,1,0,0,0,1,0),
(23,184,1,0,0,0,1,0),
(23,185,1,0,0,0,1,0),
(23,195,1,0,0,0,1,0),
(23,196,1,0,0,0,1,0),
(23,197,1,0,0,0,1,0),
(23,198,1,0,0,0,1,0),
(23,199,1,0,0,0,1,0),
(23,200,1,0,0,0,1,0),
(23,201,1,0,0,0,1,0),
(23,206,1,0,0,0,1,0),
(23,207,1,0,0,0,1,0),
(23,208,1,0,0,0,1,0),
(23,209,1,0,0,0,1,0),
(23,210,1,0,0,0,1,0),
(23,211,1,0,0,0,1,0),
(23,212,1,0,0,0,1,0),
(23,213,1,0,0,0,1,0),
(23,214,1,0,0,0,1,0),
(23,215,1,0,0,0,1,0),
(23,216,1,0,0,0,1,0),
(23,217,1,0,0,0,1,0),
(23,218,1,0,0,0,1,0),
(23,219,1,0,0,0,1,0),
(23,220,1,0,0,0,1,0),
(23,221,1,0,0,0,1,0),
(23,222,1,0,0,0,1,0),
(23,223,1,0,0,0,1,0),
(23,224,1,0,0,0,1,0),
(23,225,1,0,0,0,1,0),
(23,226,1,0,0,0,1,0),
(23,227,1,0,0,0,1,0),
(23,228,1,0,0,0,1,0),
(23,229,1,0,0,0,1,0),
(23,230,1,0,0,0,1,0),
(23,231,1,0,0,0,1,0),
(23,232,1,0,0,0,1,0),
(23,233,1,0,0,0,1,0),
(23,234,1,0,0,0,1,0),
(23,235,1,0,0,0,1,0),
(23,236,1,0,0,0,1,0),
(23,237,1,0,0,0,1,0),
(23,238,1,0,0,0,1,0),
(23,239,1,0,0,0,1,0),
(23,240,1,0,0,0,1,0),
(23,241,1,0,0,0,1,0),
(23,242,1,0,0,0,1,0),
(23,243,1,0,0,0,1,0),
(23,244,1,0,0,0,1,0),
(23,245,1,0,0,0,1,0),
(23,246,1,0,0,0,1,0),
(23,247,1,0,0,0,1,0),
(23,248,1,0,0,0,1,0),
(23,249,1,0,0,0,1,0),
(23,250,1,0,0,0,1,0),
(23,251,1,0,0,0,1,0),
(23,252,1,0,0,0,1,0),
(23,253,1,0,0,0,1,0),
(23,254,1,0,0,0,1,0),
(23,255,1,0,0,0,1,0),
(23,256,1,0,0,0,1,0),
(23,257,1,0,0,0,1,0),
(23,260,1,0,0,0,1,0),
(23,261,1,0,0,0,1,0),
(23,262,1,0,0,0,1,0),
(23,263,1,0,0,0,1,0),
(23,264,1,0,0,0,1,0),
(23,265,1,0,0,0,1,0),
(23,266,1,0,0,0,1,0),
(23,267,1,0,0,0,1,0),
(23,268,1,0,0,0,1,0),
(23,269,1,0,0,0,1,0),
(23,270,1,0,0,0,1,0),
(23,271,1,0,0,0,1,0),
(23,272,1,0,0,0,1,0),
(23,273,1,0,0,0,1,0),
(23,274,1,0,0,0,1,0),
(23,275,1,0,0,0,1,0),
(23,276,1,0,0,0,1,0),
(23,277,1,0,0,0,1,0),
(23,278,1,0,0,0,1,0),
(23,279,1,0,0,0,1,0),
(23,280,1,0,0,0,1,0),
(23,281,1,0,0,0,1,0),
(23,282,1,0,0,0,1,0),
(23,283,1,0,0,0,1,0),
(23,284,1,0,0,0,1,0),
(23,285,1,0,0,0,1,0),
(23,286,1,0,0,0,1,0),
(23,287,1,0,0,0,1,0),
(23,288,1,0,0,0,1,0),
(24,258,1,0,0,0,1,0),
(25,259,1,1,0,0,1,0);

-- role_special_permissions (61 rows)
INSERT INTO `role_special_permissions` (`role_id`,`perm_key`,`perm_value`) VALUES
(2,'design_reports','1'),
(2,'max_amount',''),
(2,'view_bank_balances','1'),
(2,'view_costs','1'),
(3,'design_reports','1'),
(3,'max_amount',''),
(3,'view_bank_balances','1'),
(3,'view_costs','1'),
(4,'max_amount','1000000'),
(4,'view_costs','1'),
(5,'max_amount','2000000'),
(5,'view_costs','1'),
(6,'max_amount','500000'),
(6,'view_costs','1'),
(7,'max_amount','50000'),
(7,'view_costs','0'),
(8,'max_amount','100000'),
(8,'view_costs','1'),
(9,'max_amount','0'),
(9,'view_costs','0'),
(10,'max_amount','0'),
(10,'view_costs','1'),
(11,'max_amount','0'),
(11,'view_costs','0'),
(12,'max_amount','100000'),
(12,'view_costs','1'),
(13,'max_amount','20000'),
(13,'view_costs','0'),
(14,'max_amount','200000'),
(14,'view_costs','1'),
(15,'max_amount','1000000'),
(15,'view_costs','1'),
(16,'max_amount','100000'),
(16,'view_bank_balances','0'),
(16,'view_costs','1'),
(17,'closed_period_changes','1'),
(17,'coa_changes','1'),
(17,'design_reports','1'),
(17,'max_amount',''),
(17,'view_bank_balances','1'),
(17,'view_costs','1'),
(18,'design_reports','1'),
(18,'max_amount','5000000'),
(18,'view_bank_balances','1'),
(18,'view_costs','1'),
(19,'max_amount','0'),
(19,'view_costs','0'),
(20,'max_amount','0'),
(20,'max_discount_pct','5'),
(20,'max_price_override_pct','0'),
(20,'view_costs','0'),
(21,'max_amount','0'),
(21,'view_costs','0'),
(22,'max_amount','0'),
(22,'view_costs','0'),
(23,'view_bank_balances','1'),
(23,'view_costs','1'),
(24,'max_amount','0'),
(24,'view_costs','0'),
(25,'max_amount','0'),
(25,'view_costs','0');

-- audit_trail (1 rows)
INSERT INTO `audit_trail` (`id`,`created_at`,`user_id`,`username`,`user_roles`,`ip_address`,`workstation`,`site_id`,`terminal_id`,`module`,`page_key`,`action`,`record_type`,`record_id`,`record_ref`,`amount`,`changes`,`snapshot`,`reason`,`approved_by`) VALUES
(1,'2026-10-23 20:35:35',NULL,NULL,NULL,'cli','CLI',NULL,NULL,'admin','admin.multi_company','multi_company_setup','company',1,'Your Construction Company, Inc.',NULL,NULL,NULL,'Registered as Company 1 of the platform. Company Owner: owner. Super Admin: superadmin.',NULL);

-- sequences (55 rows)
INSERT INTO `sequences` (`seq_key`,`prefix`,`next_no`,`pad`) VALUES
('adjustment','ADJ-',1,5),
('advance_apply','AAP-',1,5),
('asset','FA-',1,5),
('bill','BILL-',1,5),
('build','BLD-',1,5),
('cash_voucher','CAV-',1,5),
('check','',1001,4),
('credit_memo','CM-',1,5),
('cs_advance','CA-',1,4),
('cs_allocation','ALC-',1,4),
('cs_billing','PB-',1,4),
('cs_canvass','CNV-',1,4),
('cs_change_order','VO-',1,4),
('cs_contract','CON-',1,4),
('cs_daily','DSR-',1,5),
('cs_dispatch','DSP-',1,4),
('cs_doc','DOC-',1,4),
('cs_eot','EOT-',1,3),
('cs_equip_log','EQL-',1,5),
('cs_equipment','EQ-',1,3),
('cs_estimate','CE-',1,4),
('cs_inspection','INSP-',1,4),
('cs_log','LOG-',1,4),
('cs_matdoc','MIS-',1,5),
('cs_mr','MR-',1,5),
('cs_ncr','NCR-',1,4),
('cs_opportunity','OPP-',1,4),
('cs_payroll','PAY-',1,4),
('cs_project','PRJ-',1,4),
('cs_punch','PL-',1,4),
('cs_revenue','RR-',1,4),
('cs_rfi','RFI-',1,4),
('cs_safety','SAF-',1,4),
('cs_sub_billing','SPB-',1,4),
('cs_subcontract','SUB-',1,4),
('cs_timesheet','TS-',1,5),
('cs_worker','W-',1,4),
('debit_memo','DM-',1,5),
('deposit','DEP-',1,5),
('estimate','EST-',1,5),
('finance_charge','FC-',1,5),
('inv_transfer','IT-',1,5),
('invoice','INV-',1,5),
('item_receipt','RR-',1,5),
('journal','JE-',1,5),
('maintenance','MNT-',1,5),
('payment','OR-',1,6),
('pos','POS-',1,6),
('processing','PRC-',1,5),
('purchase_order','PO-',1,5),
('sales_order','SO-',1,5),
('sales_receipt','SR-',1,5),
('statement_charge','SC-',1,5),
('transfer','TRF-',1,5),
('vendor_advance','ADV-',1,5);

-- accounts (116 rows)
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`cs_cost_category`,`is_active`,`is_system`,`created_at`,`updated_at`) VALUES
(1,'1000','Cash','Bank','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(2,'1001','Cash on Hand - Yard Counter','Bank','Asset','Debit',1,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(3,'1005','Petty Cash Fund - Head Office','Bank','Asset','Debit',1,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(4,'1006','Site Revolving Fund','Bank','Asset','Debit',1,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(5,'1010','Cash in Bank - BDO Current','Bank','Asset','Debit',1,NULL,'0012-3456-7890',0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(6,'1020','Cash in Bank - BPI Savings','Bank','Asset','Debit',1,NULL,'1234-5678-90',0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(7,'1030','Cash in Bank - Landbank (Government Collections)','Bank','Asset','Debit',1,NULL,'1832-0045-12',0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(8,'1050','Undeposited Funds','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(9,'1060','Card Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(10,'1065','E-wallet Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(11,'1070','P.O. Card Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(12,'1100','Accounts Receivable - Contracts','Accounts Receivable','Asset','Debit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(13,'1110','Retention Receivable','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(14,'1120','Contract Assets - Revenue Earned Not Billed','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(15,'1150','Allowance for Doubtful Accounts','Other Current Asset','Asset','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(16,'1170','Advances to Employees','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(17,'1200','Construction Materials Inventory','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(18,'1300','Input Tax','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(19,'1310','Creditable Withholding Tax','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(20,'1315','Creditable VAT Withheld','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(21,'1400','Prepaid Expenses','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(22,'1405','Prepaid Insurance and Bond Premiums','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(23,'1410','Advances to Suppliers and Subcontractors','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(24,'1500','Land','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(25,'1510','Office Building','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(26,'1515','Accumulated Depreciation - Building','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(27,'1520','Construction Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(28,'1525','Accumulated Depreciation - Construction Equipment','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(29,'1530','Transportation Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(30,'1535','Accumulated Depreciation - Transportation','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(31,'1540','Furniture and Fixtures','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(32,'1545','Accumulated Depreciation - Furniture','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(33,'1550','Computer Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(34,'1555','Accumulated Depreciation - Computer','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(35,'1560','Tools and Scaffolding','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(36,'1565','Accumulated Depreciation - Tools and Scaffolding','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(37,'1600','Security and Rental Deposits','Other Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(38,'1610','Bid and Performance Security Deposits','Other Asset','Asset','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(39,'2000','Accounts Payable','Accounts Payable','Liability','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(40,'2050','Company Credit Card','Credit Card','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(41,'2100','Output Tax','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:32',NULL),
(42,'2110','Withholding Tax Payable - Expanded','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(43,'2112','Withholding Tax Payable - Compensation','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(44,'2115','VAT Withheld Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(45,'2120','Gift Vouchers Outstanding','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(46,'2130','Customer Deposits','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(47,'2150','SSS, PhilHealth and Pag-IBIG Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:32',NULL),
(48,'2160','Accrued Payroll','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(49,'2165','Salaries and Wages Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(50,'2200','Accrued Expenses','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(51,'2400','Progress Billings on Contracts','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:33',NULL),
(52,'2420','Advances from Clients (Mobilization)','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:33',NULL),
(53,'2430','Retention Payable - Subcontractors','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:33',NULL),
(54,'2440','Provision for Losses on Contracts','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:33',NULL),
(55,'2500','Loans Payable - Equipment Financing','Long Term Liability','Liability','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(56,'3000','Capital Stock','Equity','Equity','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(57,'3100','Dividends Declared','Equity','Equity','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(58,'3200','Retained Earnings','Equity','Equity','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:33',NULL),
(59,'3900','Opening Balance Equity','Equity','Equity','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:33',NULL),
(60,'4000','Contract Revenue','Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,1,'2026-10-23 20:35:33',NULL),
(61,'4010','Service Income','Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(62,'4020','Sales - Construction Materials','Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(63,'4030','Equipment Rental Income','Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(64,'4050','Sales Returns and Allowances','Income','Income','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(65,'4060','Sales Discounts','Income','Income','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(66,'4070','Item Group Price Variance','Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(67,'4110','Reimbursable Expense Income','Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(68,'4100','Finance Charge Income','Other Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(69,'4200','Interest Income','Other Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(70,'4300','Gain (Loss) on Disposal of Assets','Other Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(71,'4400','Other Income','Other Income','Income','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(72,'5000','Cost of Materials Sold','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(73,'5050','Purchase Discounts','Cost of Goods Sold','Expense','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(74,'5100','Inventory Adjustments and Shrinkage','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(75,'5200','Direct Labor Absorbed','Cost of Goods Sold','Expense','Credit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(76,'5300','Cost of Construction','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(77,'5310','Direct Materials','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'material',1,0,'2026-10-23 20:35:33',NULL),
(78,'5320','Direct Labor','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'labor',1,0,'2026-10-23 20:35:33',NULL),
(79,'5325','Labor Burden and Benefits','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'labor',1,0,'2026-10-23 20:35:33',NULL),
(80,'5330','Equipment Costs Charged','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'equipment',1,0,'2026-10-23 20:35:33',NULL),
(81,'5335','Equipment Rental - Projects','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'equipment',1,0,'2026-10-23 20:35:33',NULL),
(82,'5340','Subcontract Costs','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'subcontract',1,0,'2026-10-23 20:35:33',NULL),
(83,'5350','Hauling and Delivery - Projects','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(84,'5355','Site Preparation and Temporary Facilities','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(85,'5360','Permits, Licenses and Fees - Projects','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(86,'5365','Site Utilities','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(87,'5370','Site Supervision and Indirect Labor','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'labor',1,0,'2026-10-23 20:35:33',NULL),
(88,'5375','Insurance and Bonds - Projects','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(89,'5380','Testing and Inspection','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(90,'5385','Rework and Warranty Costs','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(91,'5390','Other Direct Project Costs','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(92,'5395','Provision for Contract Losses','Cost of Goods Sold','Expense','Debit',76,NULL,NULL,0,'other',1,0,'2026-10-23 20:35:33',NULL),
(93,'6000','Salaries and Wages - Office','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(94,'6005','Employee Benefits and Contributions','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(95,'6010','Rent - Office and Warehouse','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(96,'6020','Utilities Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(97,'6030','Repairs and Maintenance','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(98,'6040','Office Supplies Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(99,'6050','Fuel and Oil - Service Vehicles','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(100,'6060','Meals and Representation','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL);
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`cs_cost_category`,`is_active`,`is_system`,`created_at`,`updated_at`) VALUES
(101,'6070','Professional Fees','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(102,'6080','Bank and Merchant Charges','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(103,'6090','Depreciation Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(104,'6100','Freight and Delivery','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(105,'6110','Purchases - Non-inventory','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(106,'6120','Insurance Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(107,'6130','Taxes and Licenses','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(108,'6140','Equipment Fuel and Lubricants','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(109,'6145','Equipment Repairs and Parts','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(110,'6155','Equipment Cost Recovery (charged to projects)','Expense','Expense','Credit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(111,'6160','Travel and Transportation','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(112,'6170','Communication Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(113,'6180','Shared Warehouse and Yard Costs','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(114,'6200','Cash Over and Short','Expense','Expense','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL),
(115,'6900','Miscellaneous Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,'overhead',1,0,'2026-10-23 20:35:33',NULL),
(116,'7000','Interest Expense','Other Expense','Expense','Debit',NULL,NULL,NULL,0,NULL,1,0,'2026-10-23 20:35:33',NULL);

-- default_accounts (52 rows)
INSERT INTO `default_accounts` (`setting_key`,`label`,`module`,`account_id`) VALUES
('ap','Accounts Payable','vend',39),
('ar','Accounts Receivable','cust',12),
('card_clearing','Card Clearing','pos',9),
('cash_on_hand','Cash on Hand (Register)','pos',2),
('cash_over_short','Cash Over and Short','pos',114),
('cogs','Cost of Goods Sold','inv',72),
('cs_accrued_payroll','Accrued Payroll (approved timesheets)','proj',48),
('cs_client_advances','Advances from Clients (mobilization)','proj',52),
('cs_contract_asset','Contract Assets - Revenue Earned Not Billed','proj',14),
('cs_contract_revenue','Contract Revenue (earned)','proj',60),
('cs_employee_advances','Advances to Employees (cash advances)','proj',16),
('cs_equipment_cost','Equipment Cost Charged to Projects','proj',80),
('cs_equipment_recovery','Equipment Cost Recovery (contra)','proj',110),
('cs_indirect_labor','Indirect Labor / Site Supervision','proj',87),
('cs_labor_burden','Labor Burden and Benefits','proj',79),
('cs_labor_cost','Direct Labor Cost','proj',78),
('cs_loss_liability','Provision for Losses on Contracts (liability)','proj',54),
('cs_loss_provision','Provision for Contract Losses','proj',92),
('cs_materials_cost','Direct Materials Cost','proj',77),
('cs_other_direct','Other Direct Project Costs','proj',91),
('cs_progress_billings','Progress Billings on Contracts (contract liability)','proj',51),
('cs_retention_payable','Retention Payable (subcontractors)','proj',53),
('cs_retention_receivable','Retention Receivable','proj',13),
('cs_statutory_payable','SSS, PhilHealth and Pag-IBIG Payable','proj',47),
('cs_subcontract_cost','Subcontract Cost','proj',82),
('cs_wages_payable','Salaries and Wages Payable (net pay)','proj',49),
('cvat_withheld','Creditable VAT Withheld','cust',20),
('cwt','Creditable Withholding Tax','cust',19),
('default_expense','Default Purchases / Expense','vend',105),
('ewallet_clearing','E-wallet Clearing','pos',10),
('finance_charge_income','Finance Charge Income','cust',68),
('gain_loss_disposal','Gain / Loss on Disposal of Assets','fa',70),
('group_price_variance','Item Group Price Variance','inv',66),
('input_tax','Input Tax','vend',18),
('interest_income','Interest Income','bank',69),
('inventory_adjustment','Inventory Adjustment (Shrinkage)','inv',74),
('inventory_asset','Inventory Asset','inv',17),
('merchant_fees','Bank / Merchant Charges','bank',102),
('opening_equity','Opening Balance Equity','coa',59),
('output_tax','Output Tax','cust',41),
('processing_absorbed','Processing Labor & Overhead Absorbed','inv',75),
('processing_variance','Processing / Disassembly Variance','inv',74),
('purchase_discounts','Purchase Discounts','vend',73),
('reimbursable_income','Reimbursable Expense Income','cust',67),
('retained_earnings','Retained Earnings','coa',58),
('sales_discounts','Sales Discounts','cust',65),
('sales_income','Default Sales Income','cust',62),
('sales_returns','Sales Returns and Allowances','cust',64),
('undeposited_funds','Undeposited Funds','cust',8),
('vat_withheld_payable','VAT Withheld Payable','vend',44),
('vendor_advances','Advances to Suppliers','vend',23),
('wht_payable','Withholding Tax Payable (Expanded)','vend',42);

-- terms (6 rows)
INSERT INTO `terms` (`id`,`name`,`net_days`,`discount_pct`,`discount_days`,`is_active`) VALUES
(1,'Due on receipt',0,'0.00',0,1),
(2,'Net 15',15,'0.00',0,1),
(3,'Net 30',30,'0.00',0,1),
(4,'2% 10 Net 30',30,'2.00',10,1),
(5,'Net 45',45,'0.00',0,1),
(6,'Net 60',60,'0.00',0,1);

-- tax_codes (4 rows)
INSERT INTO `tax_codes` (`id`,`code`,`name`,`rate`,`sales_account_id`,`purchase_account_id`,`is_active`) VALUES
(1,'VAT12','VAT 12%','12.000',41,18,1),
(2,'ZR','Zero-rated','0.000',41,18,1),
(3,'EX','VAT-exempt','0.000',41,18,1),
(4,'NT','Non-taxable','0.000',41,18,1);

-- payment_methods (9 rows)
INSERT INTO `payment_methods` (`id`,`name`,`method_type`,`clearing_account_id`,`is_active`,`show_in_pos`) VALUES
(1,'Cash','cash',NULL,1,1),
(2,'Check','check',NULL,1,0),
(3,'Credit/Debit Card','card',9,1,1),
(4,'GCash','ewallet',10,1,1),
(5,'Maya','ewallet',10,1,1),
(6,'Bank Transfer','bank_transfer',NULL,1,0),
(7,'Gift Voucher','voucher',45,1,0),
(8,'P.O. Card','card',11,1,0),
(9,'Bank','bank_transfer',5,1,1);

-- price_levels (10 rows)
INSERT INTO `price_levels` (`id`,`name`,`adjust_pct`,`is_active`) VALUES
(1,'Contractor Price','-5.00',1),
(2,'Developer Price','-8.00',1),
(3,'Walk-in Plus','5.00',1),
(4,'Price Level 4','0.00',1),
(5,'Price Level 5','0.00',1),
(6,'Price Level 6','0.00',1),
(7,'Price Level 7','0.00',1),
(8,'Price Level 8','0.00',1),
(9,'Price Level 9','0.00',1),
(10,'Price Level 10','0.00',1);

-- customer_types (5 rows)
INSERT INTO `customer_types` (`id`,`name`) VALUES
(1,'Government'),
(2,'Private Developer'),
(3,'Corporate'),
(4,'Institutional'),
(5,'Individual');

-- billing_groups (1 rows)
INSERT INTO `billing_groups` (`id`,`name`) VALUES
(1,'Equipment Rental Clients');

-- sites (1 rows)
INSERT INTO `sites` (`id`,`code`,`name`,`site_type`,`address`,`is_active`) VALUES
(1,'WH','Main Warehouse (Taguig Yard)','warehouse','Lot 4, C-5 Road, Taguig City',1);

-- item_categories (12 rows)
INSERT INTO `item_categories` (`id`,`name`) VALUES
(1,'Cement and Aggregates'),
(2,'Steel and Rebar'),
(3,'Lumber and Formworks'),
(4,'Masonry'),
(5,'Electrical'),
(6,'Plumbing'),
(7,'Paints and Finishes'),
(8,'Roofing'),
(9,'Fuel and Lubricants'),
(10,'Safety and PPE'),
(11,'Services'),
(12,'Charges');

-- uom_sets (9 rows)
INSERT INTO `uom_sets` (`id`,`name`,`uom_kind`,`base_unit`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Piece','count','pc',1,'2026-10-23 20:35:33',NULL),
(2,'Bag (cement)','count','bag',1,'2026-10-23 20:35:33',NULL),
(3,'Cubic meter','volume','cu.m',1,'2026-10-23 20:35:33',NULL),
(4,'Length (m)','length','m',1,'2026-10-23 20:35:33',NULL),
(5,'Steel bar (6 m)','count','len',1,'2026-10-23 20:35:33',NULL),
(6,'Weight (kg)','weight','kg',1,'2026-10-23 20:35:33',NULL),
(7,'Volume (L)','volume','L',1,'2026-10-23 20:35:33',NULL),
(8,'Sheet','count','sht',1,'2026-10-23 20:35:33',NULL),
(9,'Time (hour)','time','hr',1,'2026-10-23 20:35:33',NULL);

-- uom_units (20 rows)
INSERT INTO `uom_units` (`id`,`set_id`,`code`,`name`,`factor`,`is_base`,`is_sales_default`,`is_purchase_default`,`sort_order`) VALUES
(1,1,'pc','Piece','1.000000',1,1,0,0),
(2,1,'bdl','Bundle of 10','10.000000',0,0,0,1),
(3,1,'box','Box of 100','100.000000',0,0,1,2),
(4,2,'bag','Bag (40 kg)','1.000000',1,1,1,0),
(5,2,'pallet','Pallet of 40 bags','40.000000',0,0,0,1),
(6,3,'cu.m','Cubic meter','1.000000',1,1,1,0),
(7,3,'truck','Truckload of 8 cu.m','8.000000',0,0,0,1),
(8,4,'m','Meter','1.000000',1,1,1,0),
(9,4,'lm','Linear meter','1.000000',0,0,0,1),
(10,4,'roll','Roll of 150 m','150.000000',0,0,0,2),
(11,5,'len','Length (6 m)','1.000000',1,1,1,0),
(12,5,'bdl10','Bundle of 10 lengths','10.000000',0,0,0,1),
(13,6,'kg','Kilogram','1.000000',1,1,1,0),
(14,6,'ton','Metric ton','1000.000000',0,0,0,1),
(15,7,'L','Liter','1.000000',1,1,1,0),
(16,7,'gal','Gallon (4 L)','4.000000',0,0,0,1),
(17,7,'drum','Drum of 200 L','200.000000',0,0,0,2),
(18,8,'sht','Sheet','1.000000',1,1,1,0),
(19,9,'hr','Hour','1.000000',1,1,1,0),
(20,9,'day','Day (8 hours)','8.000000',0,0,0,1);

-- withholding_taxes (5 rows)
INSERT INTO `withholding_taxes` (`id`,`code`,`atc`,`name`,`tax_kind`,`rate`,`base_type`,`applies_to`,`payable_account_id`,`receivable_account_id`,`description`,`sort_order`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'WT-GOODS','WC158','WT on Goods','income','1.0000','net_of_vat','both',NULL,NULL,'Goods bought from regular suppliers by top withholding agents (RR 11-2018)',10,1,'2026-10-23 20:35:33',NULL),
(2,'WT-SERVICES','WC160','WT on Services','income','2.0000','net_of_vat','both',NULL,NULL,'Services bought from regular suppliers by top withholding agents (RR 11-2018)',20,1,'2026-10-23 20:35:33',NULL),
(3,'WT-CONTRACTOR','WC120','WT on Contractors','income','2.0000','net_of_vat','both',NULL,NULL,'Payments to general engineering, building and specialty contractors',25,1,'2026-10-23 20:35:33',NULL),
(4,'WT-VAT','WV010','WT on VAT (Government)','vat','5.0000','net_of_vat','both',NULL,NULL,'Creditable VAT withheld by government agencies on VATable purchases (5% of the VAT-exclusive payment)',30,1,'2026-10-23 20:35:33',NULL),
(5,'WT-RENT','WC100','WT on Rentals','income','5.0000','net_of_vat','both',NULL,NULL,'Rental of real and personal property, including construction equipment',40,1,'2026-10-23 20:35:33',NULL);

-- asset_categories (8 rows)
INSERT INTO `asset_categories` (`id`,`name`,`asset_account_id`,`accum_dep_account_id`,`dep_expense_account_id`,`default_method`,`default_life_years`,`is_active`) VALUES
(1,'Office Building',25,26,103,'straight_line','25.00',1),
(2,'Heavy Construction Equipment',27,28,103,'straight_line','8.00',1),
(3,'Light Construction Equipment',27,28,103,'straight_line','5.00',1),
(4,'Construction Equipment (usage-based)',27,28,103,'units_of_production','8.00',1),
(5,'Transportation Equipment',29,30,103,'declining_balance','5.00',1),
(6,'Furniture and Fixtures',31,32,103,'straight_line','10.00',1),
(7,'Computer Equipment',33,34,103,'declining_balance','3.00',1),
(8,'Tools and Scaffolding',35,36,103,'straight_line','3.00',1);

-- pos_terminals (1 rows)
INSERT INTO `pos_terminals` (`id`,`code`,`name`,`site_id`,`cash_account_id`,`receipt_prefix`,`next_receipt_no`,`next_z_no`,`is_active`,`class_id`) VALUES
(1,'POS-01','Yard Sales Counter',1,2,'YD-',1,1,1,NULL);

-- user_terminals (1 rows)
INSERT INTO `user_terminals` (`user_id`,`terminal_id`) VALUES
(6,1);

-- cs_cost_codes (37 rows)
INSERT INTO `cs_cost_codes` (`id`,`code`,`name`,`category`,`parent_id`,`account_id`,`unit`,`description`,`is_active`,`sort_order`,`created_at`) VALUES
(1,'01','General Requirements','other',NULL,NULL,NULL,NULL,1,10,'2026-10-23 20:35:34'),
(2,'01-100','Mobilization and Demobilization','other',1,91,NULL,NULL,1,20,'2026-10-23 20:35:34'),
(3,'01-200','Temporary Facilities','other',1,84,NULL,NULL,1,30,'2026-10-23 20:35:34'),
(4,'01-300','Site Supervision and Administration','labor',1,87,NULL,NULL,1,40,'2026-10-23 20:35:34'),
(5,'01-400','Permits and Clearances','other',1,85,NULL,NULL,1,50,'2026-10-23 20:35:34'),
(6,'01-500','Insurance and Bonds','other',1,88,NULL,NULL,1,60,'2026-10-23 20:35:34'),
(7,'01-600','Safety and Health Program','other',1,91,NULL,NULL,1,70,'2026-10-23 20:35:34'),
(8,'01-700','Testing and Quality Control','other',1,89,NULL,NULL,1,80,'2026-10-23 20:35:34'),
(9,'01-800','Shared Overhead (allocated)','overhead',1,NULL,NULL,NULL,1,90,'2026-10-23 20:35:34'),
(10,'02','Site Works','equipment',NULL,NULL,NULL,NULL,1,100,'2026-10-23 20:35:34'),
(11,'02-100','Clearing and Grubbing','equipment',10,80,NULL,NULL,1,110,'2026-10-23 20:35:34'),
(12,'02-200','Excavation and Earthworks','equipment',10,80,NULL,NULL,1,120,'2026-10-23 20:35:34'),
(13,'02-300','Backfill and Compaction','equipment',10,80,NULL,NULL,1,130,'2026-10-23 20:35:34'),
(14,'03','Concrete Works','material',NULL,NULL,NULL,NULL,1,140,'2026-10-23 20:35:34'),
(15,'03-100','Formworks and Scaffolding','material',14,77,NULL,NULL,1,150,'2026-10-23 20:35:34'),
(16,'03-200','Reinforcing Steel','material',14,77,NULL,NULL,1,160,'2026-10-23 20:35:34'),
(17,'03-300','Structural Concrete','material',14,77,NULL,NULL,1,170,'2026-10-23 20:35:34'),
(18,'04','Masonry Works','material',NULL,NULL,NULL,NULL,1,180,'2026-10-23 20:35:34'),
(19,'04-100','CHB Walls and Plastering','material',18,77,NULL,NULL,1,190,'2026-10-23 20:35:34'),
(20,'05','Metal and Roofing Works','material',NULL,NULL,NULL,NULL,1,200,'2026-10-23 20:35:34'),
(21,'05-100','Structural Steel','material',20,77,NULL,NULL,1,210,'2026-10-23 20:35:34'),
(22,'05-200','Roof Framing and Roofing','material',20,77,NULL,NULL,1,220,'2026-10-23 20:35:34'),
(23,'06','Architectural Finishes','material',NULL,NULL,NULL,NULL,1,230,'2026-10-23 20:35:34'),
(24,'06-100','Doors and Windows','material',23,77,NULL,NULL,1,240,'2026-10-23 20:35:34'),
(25,'06-200','Ceiling Works','material',23,77,NULL,NULL,1,250,'2026-10-23 20:35:34'),
(26,'06-300','Painting Works','subcontract',23,82,NULL,NULL,1,260,'2026-10-23 20:35:34'),
(27,'06-400','Tiles and Flooring','material',23,77,NULL,NULL,1,270,'2026-10-23 20:35:34'),
(28,'07','Plumbing and Sanitary Works','subcontract',NULL,NULL,NULL,NULL,1,280,'2026-10-23 20:35:34'),
(29,'07-100','Plumbing and Fixtures','subcontract',28,82,NULL,NULL,1,290,'2026-10-23 20:35:34'),
(30,'08','Electrical Works','subcontract',NULL,NULL,NULL,NULL,1,300,'2026-10-23 20:35:34'),
(31,'08-100','Electrical Roughing-in and Wiring','subcontract',30,82,NULL,NULL,1,310,'2026-10-23 20:35:34'),
(32,'09','Mechanical Works','subcontract',NULL,NULL,NULL,NULL,1,320,'2026-10-23 20:35:34'),
(33,'09-100','Air-conditioning and Ventilation','subcontract',32,82,NULL,NULL,1,330,'2026-10-23 20:35:34'),
(34,'10','Road and Drainage Works','material',NULL,NULL,NULL,NULL,1,340,'2026-10-23 20:35:34'),
(35,'10-100','Aggregate Base Course','material',34,77,NULL,NULL,1,350,'2026-10-23 20:35:34'),
(36,'10-200','Portland Cement Concrete Pavement','material',34,77,NULL,NULL,1,360,'2026-10-23 20:35:34'),
(37,'10-300','Drainage and Culverts','material',34,77,NULL,NULL,1,370,'2026-10-23 20:35:34');

-- cs_trades (14 rows)
INSERT INTO `cs_trades` (`id`,`name`,`description`,`daily_rate`,`is_active`) VALUES
(1,'Laborer / Helper','General labor and helpers','695.00',1),
(2,'Carpenter','Formworks, framing and finishing carpentry','850.00',1),
(3,'Mason','Concrete, CHB and plastering','850.00',1),
(4,'Steelman','Rebar cutting, bending and tying','880.00',1),
(5,'Welder','Structural and pipe welding','950.00',1),
(6,'Electrician','Electrical roughing-in and wiring','950.00',1),
(7,'Plumber','Plumbing and sanitary works','900.00',1),
(8,'Painter','Surface preparation and painting','820.00',1),
(9,'Tile Setter','Tiles and stone finishes','880.00',1),
(10,'Heavy Equipment Operator','Excavator, loader, roller and crane operators','1100.00',1),
(11,'Driver','Dump truck and service vehicle drivers','850.00',1),
(12,'Foreman','Crew leader on site','1200.00',1),
(13,'Surveyor','Line and grade, quantity measurement','1100.00',1),
(14,'Timekeeper','Attendance and time records','750.00',1);

-- cs_checklist_templates (5 rows)
INSERT INTO `cs_checklist_templates` (`id`,`kind`,`name`,`items`,`is_active`) VALUES
(1,'milestone','Milestone completion evidence','Accomplishment report signed by the site engineer\nProgress photographs\nInspection report\nTest results (where required)\nEngineer certification\nClient acceptance or certificate of completion',1),
(2,'closeout','Project close-out','Final accomplishment verified\nPunch list cleared\nCertificate of completion issued\nAs-built drawings turned over\nEquipment manuals and warranties turned over\nTesting and commissioning records\nClient acceptance signed\nOccupancy or completion permit\nFinal billing issued\nFinal collection received\nRetention release billed\nSubcontractors closed out (final billing, retention, clearances)\nWarranty obligations registered\nFinal project cost and profit reviewed',1),
(3,'inspection','Pre-pour concrete inspection','Formworks aligned and braced\nRebar size, spacing and cover as per plan\nEmbedded items and sleeves in place\nConcrete mix design approved\nSlump test before pouring\nCylinder samples taken',1),
(4,'inspection','Electrical roughing-in inspection','Conduit sizes as per plan\nBoxes and fittings secured\nGrounding provisions\nNo damaged conduits\nPull wires in place',1),
(5,'tender','Tender document checklist','Eligibility documents (PhilGEPS, PCAB licence)\nBid security\nTechnical proposal\nFinancial proposal and BOQ\nOmnibus sworn statement\nSchedule of work\nKey personnel and equipment list',1);

-- cs_approval_routes (22 rows)
INSERT INTO `cs_approval_routes` (`id`,`doc_type`,`name`,`min_amount`,`max_amount`,`project_id`,`risk_level`,`is_active`,`sort_order`,`created_at`) VALUES
(1,'contract','Contracts','0.00',NULL,NULL,NULL,1,10,'2026-10-23 20:35:34'),
(2,'estimate','Estimates','0.00',NULL,NULL,NULL,1,20,'2026-10-23 20:35:34'),
(3,'budget','Project budgets and revisions','0.00',NULL,NULL,NULL,1,30,'2026-10-23 20:35:34'),
(4,'change_order','Change orders (internal approval)','0.00','1000000.00',NULL,NULL,1,40,'2026-10-23 20:35:34'),
(5,'change_order','Change orders of P1M and above','1000000.00',NULL,NULL,NULL,1,50,'2026-10-23 20:35:34'),
(6,'material_request','Material requests below P100,000','0.00','100000.00',NULL,NULL,1,60,'2026-10-23 20:35:34'),
(7,'material_request','Material requests of P100,000 and above','100000.00',NULL,NULL,NULL,1,70,'2026-10-23 20:35:34'),
(8,'purchase','Canvass award and purchase order below P500,000','0.00','500000.00',NULL,NULL,1,80,'2026-10-23 20:35:34'),
(9,'purchase','Canvass award and purchase order of P500,000 and above','500000.00',NULL,NULL,NULL,1,90,'2026-10-23 20:35:34'),
(10,'material_transfer','Material transfers between projects','0.00',NULL,NULL,NULL,1,100,'2026-10-23 20:35:34'),
(11,'timesheet','Daily timesheets','0.00',NULL,NULL,NULL,1,110,'2026-10-23 20:35:34'),
(12,'equipment_log','Equipment usage charges','0.00',NULL,NULL,NULL,1,120,'2026-10-23 20:35:34'),
(13,'daily_report','Daily site reports','0.00',NULL,NULL,NULL,1,130,'2026-10-23 20:35:34'),
(14,'cash_advance','Cash advances','0.00',NULL,NULL,NULL,1,140,'2026-10-23 20:35:34'),
(15,'subcontract','Subcontract agreements','0.00',NULL,NULL,NULL,1,150,'2026-10-23 20:35:34'),
(16,'sub_billing','Subcontractor billings below P500,000','0.00','500000.00',NULL,NULL,1,160,'2026-10-23 20:35:34'),
(17,'sub_billing','Subcontractor billings of P500,000 and above','500000.00',NULL,NULL,NULL,1,170,'2026-10-23 20:35:34'),
(18,'billing','Progress billing to clients','0.00',NULL,NULL,NULL,1,180,'2026-10-23 20:35:34'),
(19,'allocation','Shared cost allocations and reallocations','0.00',NULL,NULL,NULL,1,190,'2026-10-23 20:35:34'),
(20,'revenue','Revenue recognition','0.00',NULL,NULL,NULL,1,200,'2026-10-23 20:35:34'),
(21,'payroll','Payroll runs','0.00',NULL,NULL,NULL,1,210,'2026-10-23 20:35:34'),
(22,'closeout','Financial closure with open items','0.00',NULL,NULL,NULL,1,220,'2026-10-23 20:35:34');

-- cs_approval_steps (46 rows)
INSERT INTO `cs_approval_steps` (`id`,`route_id`,`step_no`,`label`,`role_id`,`user_id`,`project_role`,`due_hours`) VALUES
(1,1,1,'Project Director review',5,NULL,NULL,48),
(2,1,2,'General Manager approval',3,NULL,NULL,48),
(3,2,1,'Project Director approval',5,NULL,NULL,48),
(4,3,1,'Project Manager',NULL,NULL,'pm',48),
(5,3,2,'Project Director',5,NULL,NULL,48),
(6,3,3,'Finance Manager',18,NULL,NULL,48),
(7,4,1,'Project Manager',NULL,NULL,'pm',48),
(8,4,2,'Project Director',5,NULL,NULL,48),
(9,5,1,'Project Manager',NULL,NULL,'pm',48),
(10,5,2,'Project Director',5,NULL,NULL,48),
(11,5,3,'General Manager',3,NULL,NULL,48),
(12,6,1,'Project Manager',NULL,NULL,'pm',48),
(13,7,1,'Project Manager',NULL,NULL,'pm',48),
(14,7,2,'Purchasing Manager',15,NULL,NULL,48),
(15,8,1,'Purchasing Manager',15,NULL,NULL,48),
(16,9,1,'Purchasing Manager',15,NULL,NULL,48),
(17,9,2,'Finance Manager',18,NULL,NULL,48),
(18,9,3,'General Manager',3,NULL,NULL,48),
(19,10,1,'Project Manager (giving project)',NULL,NULL,'pm',48),
(20,10,2,'Operations Manager',4,NULL,NULL,48),
(21,11,1,'Project Manager or site engineer',NULL,NULL,'pm',48),
(22,12,1,'Project Manager',NULL,NULL,'pm',48),
(23,13,1,'Project Manager',NULL,NULL,'pm',48),
(24,14,1,'Project Manager',NULL,NULL,'pm',48),
(25,14,2,'Finance Manager',18,NULL,NULL,48),
(26,15,1,'Project Director',5,NULL,NULL,48),
(27,15,2,'General Manager',3,NULL,NULL,48),
(28,16,1,'Quantity Surveyor',8,NULL,NULL,48),
(29,16,2,'Project Manager',NULL,NULL,'pm',48),
(30,16,3,'Finance Manager',18,NULL,NULL,48),
(31,17,1,'Quantity Surveyor',8,NULL,NULL,48),
(32,17,2,'Project Manager',NULL,NULL,'pm',48),
(33,17,3,'Finance Manager',18,NULL,NULL,48),
(34,17,4,'General Manager',3,NULL,NULL,48),
(35,18,1,'Project Manager',NULL,NULL,'pm',48),
(36,18,2,'Quantity Surveyor / billing engineer',8,NULL,NULL,48),
(37,18,3,'Accounting review',17,NULL,NULL,48),
(38,18,4,'Finance Manager',18,NULL,NULL,48),
(39,18,5,'Authorized approver',3,NULL,NULL,48),
(40,19,1,'Chief Accountant',17,NULL,NULL,48),
(41,19,2,'Finance Manager',18,NULL,NULL,48),
(42,20,1,'Chief Accountant',17,NULL,NULL,48),
(43,21,1,'Chief Accountant',17,NULL,NULL,48),
(44,21,2,'Finance Manager',18,NULL,NULL,48),
(45,22,1,'Finance Manager',18,NULL,NULL,48),
(46,22,2,'General Manager',3,NULL,NULL,48);

-- cs_alloc_rules (8 rows)
INSERT INTO `cs_alloc_rules` (`id`,`name`,`method`,`description`,`is_active`,`created_at`) VALUES
(1,'Equal share among active projects','equal','Splits a shared cost evenly among the projects chosen on the allocation',1,'2026-10-23 20:35:34'),
(2,'By man-hours worked','man_hours','Approved timesheet hours of each project in the period',1,'2026-10-23 20:35:34'),
(3,'By equipment hours','equipment_hours','Operating hours logged on each project in the period',1,'2026-10-23 20:35:34'),
(4,'By direct cost','direct_cost','Direct costs posted to each project in the period',1,'2026-10-23 20:35:34'),
(5,'By contract value','contract_value','Current contract value of each project',1,'2026-10-23 20:35:34'),
(6,'By revenue recognized','revenue','Revenue recognized for each project in the period',1,'2026-10-23 20:35:34'),
(7,'By floor area','floor_area','Floor area of each project',1,'2026-10-23 20:35:34'),
(8,'By material consumption','material_cost','Materials charged to each project in the period',1,'2026-10-23 20:35:34');

-- ---------------------------------------------------------------------
--  3. AUDIT TRAIL PROTECTION (created after the data is loaded)
-- ---------------------------------------------------------------------
--  Audit trail protection: entries can never be changed or deleted,
--  not even by administrators or directly in the database.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_audit_trail_no_update;
DROP TRIGGER IF EXISTS trg_audit_trail_no_delete;

DELIMITER $$
CREATE TRIGGER trg_audit_trail_no_update BEFORE UPDATE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are read-only and cannot be changed.';
END$$

CREATE TRIGGER trg_audit_trail_no_delete BEFORE DELETE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are permanent and cannot be deleted.';
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
--  4. PLATFORM (MASTER) DATABASE: companies, Company Codes, Super Admin
-- ---------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `gsmart_construction_master` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gsmart_construction_master`;

DROP TABLE IF EXISTS `registration_attempts`;
DROP TABLE IF EXISTS `platform_settings`;
DROP TABLE IF EXISTS `platform_audit`;
DROP TABLE IF EXISTS `company_provisioning_log`;
DROP TABLE IF EXISTS `user_company_links`;
DROP TABLE IF EXISTS `user_company_memberships`;
DROP TABLE IF EXISTS `company_codes`;
DROP TABLE IF EXISTS `company_databases`;
DROP TABLE IF EXISTS `companies`;
DROP TABLE IF EXISTS `super_admins`;

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

-- super_admins (1 rows)
INSERT INTO `super_admins` (`id`,`username`,`password_hash`,`full_name`,`email`,`status`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`) VALUES
(1,'superadmin','$2y$10$EzV/yyxLvmMZicR00gaBket3mmsIU/5tWWeN3pbKqmrk0028d4fiy','Platform Super Administrator','superadmin@samplebuilders.test','active',0,'2026-10-23 20:35:35',0,NULL,NULL,NULL,'2026-10-23 20:35:35');

-- companies (1 rows)
INSERT INTO `companies` (`id`,`name`,`address`,`contact_person`,`phone`,`email`,`tin`,`status`,`owner_user_id`,`require_approval`,`join_role_id`,`template_company_id`,`notes`,`last_error`,`created_by`,`created_at`,`activated_at`,`deactivated_at`,`updated_at`) VALUES
(1,'Your Construction Company, Inc.',NULL,'Eduardo Santos',NULL,NULL,NULL,'active',25,1,NULL,NULL,'The original database, registered as Company 1',NULL,NULL,'2026-10-23 20:35:34','2026-10-23 20:35:34',NULL,NULL);

-- company_databases (1 rows)
INSERT INTO `company_databases` (`company_id`,`db_name`,`is_primary`,`created_by_platform`,`table_count`,`triggers_ok`,`created_at`,`verified_at`) VALUES
(1,'gsmart_construction',1,0,139,1,'2026-10-23 20:35:34','2026-10-23 20:35:34');

-- company_codes (1 rows)
INSERT INTO `company_codes` (`id`,`company_id`,`code`,`status`,`expires_at`,`created_by`,`created_at`,`retired_at`) VALUES
(1,1,'YCC7ERFC','active',NULL,'setup:','2026-10-23 20:35:35',NULL);

-- user_company_memberships (26 rows)
INSERT INTO `user_company_memberships` (`id`,`company_id`,`company_user_id`,`username`,`full_name`,`email`,`status`,`created_via`,`joined_at`,`updated_at`) VALUES
(1,1,1,'subcon','Allan Torres','subcon@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(2,1,2,'client','Atty. Ramon Velasco','client@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(3,1,3,'timekeeper','Pedro Castillo','timekeeper@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(4,1,4,'foreman','Nestor Ramos','foreman@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(5,1,5,'warehouse','Dan Villanueva','warehouse@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(6,1,6,'cashier','Ella Ramos','cashier@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(7,1,7,'safety','Jose Mercado','safety@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(8,1,8,'qa','Engr. Paolo Garcia','qa@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(9,1,9,'billing','Maria Santos','billing@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(10,1,10,'acctstaff','Ben Garcia','acctstaff@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(11,1,11,'procurement','Carla Lim','procurement@samplebuilders.test','active','admin','2026-10-23 20:35:33','2026-10-23 20:35:35'),
(12,1,12,'hr','Angela Torres','hr@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(13,1,13,'equipmgr','Rogelio Navarro','equipmgr@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(14,1,14,'siteeng','Engr. Mark Dizon','siteeng@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(15,1,15,'qs','Joy Fernandez','qs@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(16,1,16,'auditor','Grace Uy','auditor@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(17,1,17,'purchasing','Victor Chua','purchasing@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(18,1,18,'controller','Ana Cruz, CPA','controller@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(19,1,19,'pm','Engr. Carlo Reyes','pm@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(20,1,20,'pm2','Engr. Liza Bautista','pm2@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(21,1,21,'finmgr','Michelle Tan','finmgr@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(22,1,22,'projdir','Engr. Ramon Aquino','projdir@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(23,1,23,'opsmgr','Teresa Mendoza','opsmgr@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(24,1,24,'gm','Ricardo Villanueva','gm@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(25,1,25,'owner','Eduardo Santos','owner@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35'),
(26,1,26,'admin','System Administrator','admin@samplebuilders.test','active','admin','2026-10-23 20:35:34','2026-10-23 20:35:35');

-- platform_audit (1 rows)
INSERT INTO `platform_audit` (`id`,`created_at`,`actor_type`,`actor_id`,`actor_name`,`company_id`,`action`,`details`,`ip_address`) VALUES
(1,'2026-10-23 20:35:35','user',0,NULL,1,'platform_setup','The existing database was registered as Company 1 (Your Construction Company, Inc.); owner owner; Super Admin superadmin created','cli');

-- platform_settings (1 rows)
INSERT INTO `platform_settings` (`setting_key`,`setting_value`) VALUES
('default_require_approval','1');

USE `gsmart_construction`;
SET FOREIGN_KEY_CHECKS = 1;
