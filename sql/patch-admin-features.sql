-- ─────────────────────────────────────────────────────────────
--  ProcureEase — Admin Tools patch
--  Adds activity_logs, settings, and backups tables + seed data.
--  Safe to run on an existing database (uses IF NOT EXISTS and
--  INSERT IGNORE so nothing is lost or duplicated).
--
--  How to apply:
--    1. Open phpMyAdmin
--    2. Select the "procureease" database
--    3. Click the "SQL" tab
--    4. Paste this entire file, then click Go
-- ─────────────────────────────────────────────────────────────

-- ─── Activity log (audit trail) ──────────────────────────────
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50),
  full_name VARCHAR(100),
  role VARCHAR(20),
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40),
  entity_ref VARCHAR(100),
  details TEXT,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at),
  INDEX idx_username (username),
  INDEX idx_action (action)
) ENGINE=InnoDB;

INSERT IGNORE INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address, created_at) VALUES
  ('pu-99-001',    'Ramon Cruz',              'admin', 'Signed in',            'session', '',                 'Signed in from web browser.',                                       '127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 MINUTE)),
  ('pu-99-001',    'Ramon Cruz',              'admin', 'Requested revision',   'ppmp',    'HRMU · FY 2027',   'Requested revision on the HRMU FY 2027 PPMP.',                       '127.0.0.1', DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
  ('hrmu-07-001',  'Rico Bautista',           'user',  'Signed in',            'session', '',                 'Signed in from web browser.',                                       '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  ('ictu-01-001',  'Maria Reyes',             'user',  'Submitted PPMP',       'ppmp',    'ICTU · FY 2027',   'Submitted the FY 2027 PPMP with 4 procurement entries.',              '127.0.0.1', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
  ('ictu-01-001',  'Maria Reyes',             'user',  'Saved draft',          'ppmp',    'ICTU · FY 2027',   'Autosaved 3 changes to the draft PPMP.',                              '127.0.0.1', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
  ('soe-02-001',   'Engr. Juan dela Cruz',    'user',  'Submitted PPMP',       'ppmp',    'SOE · FY 2027',    'Submitted the FY 2027 PPMP with 4 procurement entries.',              '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  ('pu-99-001',    'Ramon Cruz',              'admin', 'Requested edit',       'ppmp',    'SOAS · FY 2027',   'Requested edit permission for the SOAS FY 2027 PPMP.',                '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  ('soas-04-001',  'Malou C. Angara',         'user',  'Granted edit access',  'edit_req','SOAS · FY 2027',   'Granted edit access to the Procurement Unit.',                        '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  ('gsu-06-001',   'Miguel Torres',           'user',  'Submitted PPMP',       'ppmp',    'GSU · FY 2027',    'Submitted the FY 2027 PPMP.',                                         '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
  ('lib-08-001',   'Elena Ramos',             'user',  'Signed in',            'session', '',                 'Signed in from web browser.',                                       '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
  ('pu-99-001',    'Ramon Cruz',              'admin', 'Generated report',     'report',  'APP',              'Generated FY 2027 Annual Procurement Plan (XLSX).',                   '127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
  ('reg-09-001',   'Ligaya Bautista',         'user',  'Submitted PPMP',       'ppmp',    'REG · FY 2027',    'Submitted the FY 2027 PPMP.',                                         '127.0.0.1', DATE_SUB(NOW(), INTERVAL 4 DAY)),
  ('soed-05-001',  'Dr. Cristina Villanueva', 'user',  'Signed in',            'session', '',                 'Signed in from web browser.',                                       '127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 DAY)),
  ('pu-99-001',    'Ramon Cruz',              'admin', 'Backed up database',   'backup',  'procureease-full', 'Full database backup generated (13 tables, 289 rows).',              '127.0.0.1', DATE_SUB(NOW(), INTERVAL 7 DAY));

-- ─── System settings (key/value) ─────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  key_name  VARCHAR(80) PRIMARY KEY,
  value     TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (key_name, value) VALUES
  ('active_fy',            '2027'),
  ('institution_name',     'Aurora State College of Technology'),
  ('institution_address',  'Zabali, Baler, Aurora, Philippines · 3200'),
  ('governing_law',        'RA 12009 (New Government Procurement Act)'),
  ('session_timeout_min',  '30'),
  ('auto_save_interval',   '800'),
  ('password_min_length',  '6'),
  ('notify_on_submission', '1'),
  ('notify_on_revision',   '1'),
  ('backup_schedule',      'weekly'),
  ('theme_page',           '#E5F0EF'),
  ('theme_primary',        '#293681'),
  ('theme_accent',         '#FF6A1C');

-- ─── Backup history ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS backups (
  id INT PRIMARY KEY AUTO_INCREMENT,
  filename VARCHAR(200) NOT NULL,
  size_bytes BIGINT,
  tables_count INT,
  rows_count INT,
  created_by VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  notes TEXT
) ENGINE=InnoDB;

INSERT IGNORE INTO backups (filename, size_bytes, tables_count, rows_count, created_by, created_at, notes) VALUES
  ('procureease-2026-07-14.sql', 45824,  13, 289, 'pu-99-001', DATE_SUB(NOW(), INTERVAL 7  DAY), 'Weekly automatic backup'),
  ('procureease-2026-07-07.sql', 41892,  13, 264, 'pu-99-001', DATE_SUB(NOW(), INTERVAL 14 DAY), 'Weekly automatic backup'),
  ('procureease-2026-06-30.sql', 38102,  12, 240, 'pu-99-001', DATE_SUB(NOW(), INTERVAL 21 DAY), 'Weekly automatic backup');
