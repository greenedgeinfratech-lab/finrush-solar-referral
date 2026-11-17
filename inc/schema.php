<?php
// Database schema for the Finrush Solar Referral CRM (MySQL / MariaDB).
return [
"CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  mobile VARCHAR(10) NOT NULL UNIQUE,
  email VARCHAR(120) NULL,
  org VARCHAR(40) NOT NULL DEFAULT 'Finrush',
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  password_hash VARCHAR(255) NOT NULL,
  must_change TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS partners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  mobile VARCHAR(10) NOT NULL UNIQUE,
  email VARCHAR(120) NULL,
  city VARCHAR(60) NULL,
  district VARCHAR(80) NULL,
  occupation VARCHAR(40) NULL,
  message TEXT NULL,
  status ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
  password_hash VARCHAR(255) NULL,
  must_change TINYINT(1) NOT NULL DEFAULT 0,
  kyc_status ENUM('pending','verified') NOT NULL DEFAULT 'pending',
  pan VARCHAR(10) NULL,
  bank_name VARCHAR(80) NULL,
  bank_last4 VARCHAR(4) NULL,
  ifsc VARCHAR(11) NULL,
  team_notes TEXT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  last_login_at DATETIME NULL,
  consent_at DATETIME NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'web',
  created_at DATETIME NOT NULL,
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NOT NULL UNIQUE,
  partner_id INT UNSIGNED NULL,
  customer_name VARCHAR(80) NOT NULL,
  customer_phone VARCHAR(10) NOT NULL,
  customer_city VARCHAR(80) NULL,
  project_type ENUM('residential','commercial','industrial','unknown') NOT NULL DEFAULT 'unknown',
  size_info VARCHAR(60) NULL,
  pm_surya_ghar VARCHAR(10) NULL,
  notes TEXT NULL,
  stage VARCHAR(30) NOT NULL DEFAULT 'new',
  assigned_to INT UNSIGNED NULL,
  project_value DECIMAL(14,2) NULL,
  commission_rate DECIMAL(5,2) NULL,
  commission_amount DECIMAL(12,2) NULL,
  payment_received_on DATE NULL,
  commission_status ENUM('none','pending','paid','reversed') NOT NULL DEFAULT 'none',
  commission_tds DECIMAL(12,2) NULL,
  commission_net DECIMAL(12,2) NULL,
  commission_paid_on DATE NULL,
  commission_ref VARCHAR(80) NULL,
  lost_reason VARCHAR(200) NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'partner',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (customer_phone), INDEX (partner_id), INDEX (stage), INDEX (payment_received_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS lead_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NOT NULL,
  actor_type ENUM('partner','team','system') NOT NULL,
  actor_id INT UNSIGNED NULL,
  kind ENUM('note','stage','update') NOT NULL,
  body TEXT NULL,
  from_stage VARCHAR(30) NULL,
  to_stage VARCHAR(30) NULL,
  partner_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  INDEX (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS bonuses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_id INT UNSIGNED NOT NULL,
  month CHAR(7) NOT NULL,
  sales DECIMAL(14,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  tds DECIMAL(12,2) NOT NULL DEFAULT 0,
  net DECIMAL(12,2) NOT NULL,
  paid_on DATE NOT NULL,
  ref VARCHAR(80) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY partner_month (partner_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(20) NOT NULL,
  ident VARCHAR(80) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (scope, ident, created_at), INDEX (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
