-- =====================================================================
--  GSMaRT Construction System - database schema (one company)
--  The accounting tables come first; the construction tables (cs_*) follow.
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
