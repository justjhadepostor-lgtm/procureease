-- ═══════════════════════════════════════════════════════════════
--  ProcureEase — full database schema
--  Automated PPMP Consolidation System
--  Aurora State College of Technology · RA 12009 (NGPA)
--
--  Import this into an empty database, then open the system once
--  as admin so any later migrations can run.
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ─── Core tables ───────────────────────────────────────────────

CREATE TABLE departments (
  code VARCHAR(20) PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  dept_group VARCHAR(50) NOT NULL,
  display_order INT DEFAULT 100
) ENGINE=InnoDB;

INSERT INTO departments (code, name, dept_group, display_order) VALUES
  ('PRES',    'Office of the President',                                    'Executive Offices', 10),
  ('OVPAA',   'Office of the VP for Academic Affairs',                      'Executive Offices', 20),
  ('OVPAPF',  'Office of the VP for Administration, Planning & Finance',    'Executive Offices', 30),
  ('BOARDSEC','Board Secretary',                                            'Executive Offices', 40),
  ('IAU',     'Internal Audit Unit',                                        'Executive Offices', 50),
  ('SOE',     'School of Engineering',                                      'Schools & Colleges', 110),
  ('SoIT',    'School of Information Technology',                           'Schools & Colleges', 120),
  ('SOAS',    'School of Arts and Sciences',                                'Schools & Colleges', 130),
  ('SOED',    'School of Education',                                        'Schools & Colleges', 140),
  ('SABM',    'School of Agri-Business Management',                         'Schools & Colleges', 150),
  ('SFES',    'School of Fisheries & Environmental Sciences',               'Schools & Colleges', 160),
  ('SFOS',    'School of Fisheries & Ocean Sciences',                       'Schools & Colleges', 170),
  ('GRAD',    'Graduate School',                                            'Schools & Colleges', 180),
  ('COL',     'College of Law',                                             'Schools & Colleges', 190),
  ('SAS',     'School of Agriculture',                                      'Schools & Colleges', 200),
  ('GSU',     'General Services Unit',                                      'Support Units',      210),
  ('PU',      'Procurement Unit',                                           'Support Units',      220),
  ('BAC',     'Bids and Awards Committee',                                  'Support Units',      230),
  ('HRMU',    'Human Resource Management Unit',                             'Support Units',      240),
  ('CASH',    'Cash Unit',                                                  'Support Units',      250),
  ('BUDGET',  'Budget Unit',                                                'Support Units',      260),
  ('ACCTG',   'Accounting Unit',                                            'Support Units',      270),
  ('ICTU',    'Information and Communication Technology Unit',              'Support Units',      280),
  ('INFO',    'Information Unit',                                           'Support Units',      290),
  ('PMU',     'Project Management Unit',                                    'Support Units',      300),
  ('SPMU',    'Supply & Property Management Unit',                          'Support Units',      310),
  ('PLAN',    'Planning and Development Unit',                              'Support Units',      320),
  ('QAO',     'Quality Assurance Office',                                   'Support Units',      330),
  ('LIB',     'Office of Library Services',                                 'Support Units',      340),
  ('HSU',     'Health Services Unit',                                       'Support Units',      350),
  ('LEGAL',   'Legal Unit',                                                 'Support Units',      360),
  ('LAB',     'Laboratory Section',                                         'Support Units',      370),
  ('REG',     'Registrar Office',                                           'Support Units',      380),
  ('RECORDS', 'Records Unit',                                               'Support Units',      390),
  ('CAO',     'CAO',                                                        'Support Units',      400),
  ('ERDO',    'Extension and Rural Development Office',                     'Special Programs',   410),
  ('RDO',     'Research and Development Office',                            'Special Programs',   420),
  ('IGP',     'Income Generating Projects',                                 'Special Programs',   430),
  ('DRRMO',   'DRRMO',                                                      'Special Programs',   440),
  ('GAD',     'Gender and Development',                                     'Special Programs',   450),
  ('NSTP',    'NSTP Office',                                                'Special Programs',   460),
  ('SWK',     'Sentro ng Wika at Kultura',                                  'Special Programs',   470),
  ('SCA',     'Socio-Cultural Affairs',                                     'Special Programs',   480),
  ('OSS',     'Office of Student Services',                                 'Special Programs',   490),
  ('IEAS',    'International, External & Alumni Services',                  'Special Programs',   500);

-- ─── Users (one account per department + admin) ────────────────
CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100),
  position VARCHAR(100),
  email VARCHAR(100),
  contact VARCHAR(30),
  role ENUM('user', 'admin') DEFAULT 'user',
  dept_code VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (dept_code) REFERENCES departments(code)
) ENGINE=InnoDB;

-- Seed accounts. Default password for ALL demo users is: password123
-- The hash below is bcrypt('password123')
INSERT INTO users (username, password_hash, full_name, position, email, contact, role, dept_code) VALUES
  ('ictu-01-001',    '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Maria Reyes',       'Unit Head, ICTU',              'ictu@ascot.edu.com',        '+63 917 555 0142', 'user',  'ICTU'),
  ('soe-02-001',     '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Engr. Juan dela Cruz','Dean, SOE',                 'soe@ascot.edu.com',         '+63 917 555 0143', 'user',  'SOE'),
  ('soit-03-001',    '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Prof. Nelson Ramos','Program Chair, SoIT',          'soit@ascot.edu.com',        '+63 917 555 0144', 'user',  'SoIT'),
  ('soas-04-001',    '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Malou C. Angara',   'Dean, SOAS',                   'soas@ascot.edu.com',        '+63 917 555 0145', 'user',  'SOAS'),
  ('soed-05-001',    '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Dr. Cristina Villanueva','Dean, SOED',              'soed@ascot.edu.com',        '+63 917 555 0146', 'user',  'SOED'),
  ('gsu-06-001',     '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Miguel Torres',     'Unit Head, GSU',               'gsu@ascot.edu.com',         '+63 917 555 0147', 'user',  'GSU'),
  ('hrmu-07-001',    '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Rico Bautista',     'HR Head',                      'hrmu@ascot.edu.com',        '+63 917 555 0148', 'user',  'HRMU'),
  ('lib-08-001',     '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Elena Ramos',       'Chief Librarian',              'library@ascot.edu.com',     '+63 917 555 0149', 'user',  'LIB'),
  ('reg-09-001',     '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'Ligaya Bautista',   'Registrar',                    'registrar@ascot.edu.com',   '+63 917 555 0150', 'user',  'REG'),
  ('pu-99-001',      '$2y$10$rtb/fipkelSaS0.KdY4J5eLfM/Zao99nsPAGPeK56maT.N2qRBOzy', 'George Wilson A. Uy', 'Head, Procurement Unit',       'procurement@ascot.edu.com', '+63 917 555 0198', 'admin', 'PU');

-- ─── PPMPs (one per department per fiscal year) ────────────────
CREATE TABLE ppmps (
  id INT PRIMARY KEY AUTO_INCREMENT,
  dept_code VARCHAR(20) NOT NULL,
  fy INT NOT NULL,
  preparer VARCHAR(100),
  date_prepared VARCHAR(50),
  primary_fund VARCHAR(60) DEFAULT 'GAA',
  certification TEXT,
  sig_name VARCHAR(100),
  sig_position VARCHAR(100),
  status ENUM('draft','submitted') DEFAULT 'draft',
  submitted_at TIMESTAMP NULL,
  submitted_by VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_dept_fy (dept_code, fy),
  FOREIGN KEY (dept_code) REFERENCES departments(code)
) ENGINE=InnoDB;

-- Seed PPMPs for ICTU across 4 fiscal years
INSERT INTO ppmps (dept_code, fy, preparer, date_prepared, primary_fund, certification, sig_name, sig_position, status, submitted_at, submitted_by) VALUES
  ('ICTU', 2027, 'Maria Reyes, Unit Head', 'July 6, 2026', 'GAA',
   'I hereby certify that the above Project Procurement Management Plan is prepared in accordance with the approved Work and Financial Plan of the Information and Communication Technology Unit for FY 2027, in compliance with Republic Act No. 12009 (New Government Procurement Act) and its implementing rules and regulations.',
   'Maria Reyes', 'Unit Head, ICTU', 'submitted', '2026-07-06 14:31:00', 'Maria Reyes'),
  ('ICTU', 2026, 'Maria Reyes, Unit Head', 'January 14, 2026', 'GAA',
   'I hereby certify that the above Project Procurement Management Plan is prepared in accordance with the approved Work and Financial Plan of the Information and Communication Technology Unit for FY 2026, in compliance with Republic Act No. 12009 (New Government Procurement Act) and its implementing rules and regulations.',
   'Maria Reyes', 'Unit Head, ICTU', 'submitted', '2026-01-14 10:00:00', 'Maria Reyes'),
  ('ICTU', 2025, 'Maria Reyes, Unit Head', 'November 2, 2024', 'GAA',
   'I hereby certify that the above Project Procurement Management Plan is prepared in accordance with the approved Work and Financial Plan of the Information and Communication Technology Unit for FY 2025, in compliance with Republic Act No. 12009 (New Government Procurement Act) and its implementing rules and regulations.',
   'Maria Reyes', 'Unit Head, ICTU', 'submitted', '2024-11-02 15:20:00', 'Maria Reyes'),
  ('ICTU', 2024, 'Maria Reyes, Unit Head', 'October 18, 2023', 'GAA',
   'I hereby certify that the above Project Procurement Management Plan is prepared in accordance with the approved Work and Financial Plan of the Information and Communication Technology Unit for FY 2024, in compliance with Republic Act No. 12009 (New Government Procurement Act) and its implementing rules and regulations.',
   'Maria Reyes', 'Unit Head, ICTU', 'submitted', '2023-10-18 09:45:00', 'Maria Reyes'),
  ('OVPAPF', 2027, 'VP Rowena Santos', 'July 3, 2026', 'GAA', 'Certified prepared per NGPA guidelines.', 'Rowena Santos', 'VP for Administration', 'submitted', '2026-07-03 09:14:00', 'Rowena Santos'),
  ('SOE',    2027, 'Engr. Juan dela Cruz', 'July 4, 2026', 'GAA', 'Certified prepared per NGPA guidelines.', 'Juan dela Cruz', 'Dean, SOE', 'submitted', '2026-07-04 11:00:00', 'Juan dela Cruz'),
  ('GSU',    2027, 'Miguel Torres', 'June 30, 2026', 'GAA', 'Certified prepared per NGPA guidelines.', 'Miguel Torres', 'Unit Head, GSU', 'submitted', '2026-06-30 14:05:00', 'Miguel Torres'),
  ('LIB',    2027, 'Elena Ramos', 'June 20, 2026', 'GAA', 'Certified prepared per NGPA guidelines.', 'Elena Ramos', 'Chief Librarian', 'submitted', '2026-06-20 10:00:00', 'Elena Ramos'),
  ('REG',    2027, 'Ligaya Bautista', 'June 28, 2026', 'GAA', 'Certified prepared per NGPA guidelines.', 'Ligaya Bautista', 'Registrar', 'submitted', '2026-06-28 13:00:00', 'Ligaya Bautista'),
  ('SOAS',   2027, 'Malou C. Angara', 'July 2, 2026', 'GAA', 'Certified prepared per NGPA guidelines.', 'Malou C. Angara', 'Dean, SOAS', 'submitted', '2026-07-02 11:00:00', 'Malou C. Angara'),
  ('HRMU',   2027, 'Rico Bautista', 'June 26, 2026', 'GAA', 'Certified prepared per NGPA guidelines.', 'Rico Bautista', 'HR Head', 'submitted', '2026-06-26 16:00:00', 'Rico Bautista');

-- ─── PPMP entries (per procurement category) ────────────────────
CREATE TABLE ppmp_entries (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ppmp_id INT NOT NULL,
  category ENUM('training','consulting','nonps','appcse') NOT NULL,
  budget_category ENUM('travel','training','supply','equipment') NOT NULL DEFAULT 'training',
  description TEXT,
  proj_type VARCHAR(50) DEFAULT 'Goods',
  mode VARCHAR(60) DEFAULT 'Small Value Procurement',
  fund VARCHAR(60) DEFAULT 'GAA',
  activity_start VARCHAR(20) DEFAULT 'January',
  activity_end VARCHAR(20) DEFAULT 'December',
  delivery VARCHAR(20) DEFAULT 'February',
  amount DECIMAL(15,2) DEFAULT 0,
  remarks TEXT,
  FOREIGN KEY (ppmp_id) REFERENCES ppmps(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed entries for ICTU FY 2027 (ppmp_id=1)
INSERT INTO ppmp_entries (ppmp_id, category, description, proj_type, mode, fund, activity_start, activity_end, delivery, amount, remarks) VALUES
  (1, 'training',   '2027 IT staff capacity-building program',      'Consulting Services', 'Small Value Procurement',  'GAA', 'February', 'November', 'March',   45000.00,  'Cybersecurity, cloud administration, and network engineering trainings for ICTU personnel.'),
  (1, 'consulting', 'Systems audit and penetration testing services','Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'June',     'July',    150000.00, 'Third-party assessment of the student information system.'),
  (1, 'nonps',      'Specialized network equipment and cabling',      'Goods',              'Small Value Procurement',  'GAA', 'January',  'March',    'April',   320000.00, 'Managed switches, fiber patch cords, rack-mount UPS. See attached Non-PS Supplies list.'),
  (1, 'appcse',     'Common-use office supplies from PS-DBM',         'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 18500.00,  'Ballpens, bond paper, folders, envelopes, sign pens. See attached APP-CSE Form.'),
  -- ICTU FY 2026 (ppmp_id=2)
  (2, 'training',   'IT staff development trainings',                 'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'October',  'April',   40000.00, ''),
  (2, 'consulting', 'Software licensing and consultancy',             'Consulting Services', 'Small Value Procurement',  'GAA', 'January',  'April',    'May',     120000.00, ''),
  (2, 'nonps',      'Server upgrades and networking hardware',        'Goods',              'Small Value Procurement',  'GAA', 'January',  'March',    'April',   310000.00, ''),
  (2, 'appcse',     'Common-use office supplies',                     'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 15000.00, ''),
  -- ICTU FY 2025 (ppmp_id=3)
  (3, 'training',   'IT trainings and workshops',                     'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'October',  'April',   35000.00, ''),
  (3, 'nonps',      'Network and computing peripherals',              'Goods',              'Small Value Procurement',  'GAA', 'January',  'March',    'April',   365000.00, ''),
  (3, 'appcse',     'Common-use office supplies',                     'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 12000.00, ''),
  -- ICTU FY 2024 (ppmp_id=4)
  (4, 'training',   'Basic IT training program',                      'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'October',  'April',   30000.00, ''),
  (4, 'nonps',      'Computing hardware and peripherals',             'Goods',              'Small Value Procurement',  'GAA', 'January',  'March',    'April',   338000.00, ''),
  (4, 'appcse',     'Common-use office supplies',                     'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 10000.00, ''),
  -- Other departments (aggregate entries)
  (5, 'training',   'Executive leadership development',               'Consulting Services', 'Small Value Procurement',  'GAA', 'February', 'September','March',   250000.00, ''),
  (5, 'consulting', 'Financial systems review',                       'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'June',     'July',    850000.00, ''),
  (5, 'nonps',      'Office equipment and furniture',                 'Goods',              'Small Value Procurement',  'GAA', 'January',  'March',    'April',   650000.00, ''),
  (5, 'appcse',     'Office supplies from PS-DBM',                    'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 100000.00, ''),
  (6, 'training',   'Faculty development seminars',                   'Consulting Services', 'Small Value Procurement',  'GAA', 'February', 'November', 'March',   180000.00, ''),
  (6, 'consulting', 'Laboratory equipment consulting',                'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'June',     'July',    450000.00, ''),
  (6, 'nonps',      'Laboratory chemicals and equipment',             'Goods',              'Small Value Procurement',  'GAA', 'January',  'April',    'May',     840000.00, ''),
  (6, 'appcse',     'PS-DBM supplies',                                'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 150000.00, ''),
  (7, 'training',   'Staff training program',                         'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'October',  'April',   120000.00, ''),
  (7, 'nonps',      'Vehicle maintenance and supplies',               'Goods',              'Small Value Procurement',  'GAA', 'January',  'December', 'February',900000.00, ''),
  (7, 'appcse',     'PS-DBM office supplies',                         'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 340000.00, ''),
  (8, 'training',   'Library staff development',                      'Consulting Services', 'Small Value Procurement',  'GAA', 'April',    'September','May',     80000.00, ''),
  (8, 'nonps',      'Books and periodicals',                          'Goods',              'Small Value Procurement',  'GAA', 'January',  'December', 'March',   380000.00, ''),
  (8, 'appcse',     'PS-DBM office supplies',                         'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 80000.00, ''),
  (9, 'training',   'Registrar staff training',                       'Consulting Services', 'Small Value Procurement',  'GAA', 'February', 'August',   'March',   90000.00, ''),
  (9, 'nonps',      'Records management supplies',                    'Goods',              'Small Value Procurement',  'GAA', 'January',  'December', 'February',680000.00, ''),
  (9, 'appcse',     'PS-DBM office supplies',                         'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 180000.00, ''),
  (10,'training',   'Faculty capacity-building',                      'Consulting Services', 'Small Value Procurement',  'GAA', 'February', 'October',  'March',   150000.00, ''),
  (10,'consulting', 'Curriculum development consulting',              'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'August',   'September',280000.00, ''),
  (10,'nonps',      'Instructional materials and equipment',          'Goods',              'Small Value Procurement',  'GAA', 'January',  'May',      'June',    295000.00, ''),
  (10,'appcse',     'PS-DBM office supplies',                         'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 50000.00, ''),
  (11,'training',   'HR development program',                         'Consulting Services', 'Small Value Procurement',  'GAA', 'March',    'September','April',   100000.00, ''),
  (11,'nonps',      'HR office equipment',                            'Goods',              'Small Value Procurement',  'GAA', 'January',  'April',    'May',     250000.00, ''),
  (11,'appcse',     'PS-DBM office supplies',                         'Goods',              'Direct Purchase to PS-DBM', 'GAA','January',  'December', 'January', 70000.00, '');

-- ─── Edit permission requests (admin → user) ────────────────────
CREATE TABLE edit_requests (
  id INT PRIMARY KEY AUTO_INCREMENT,
  dept_code VARCHAR(20) NOT NULL,
  ppmp_id INT,
  fy INT NOT NULL,
  reason TEXT NOT NULL,
  requested_by VARCHAR(100),
  status ENUM('pending','granted','declined') DEFAULT 'pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL,
  FOREIGN KEY (dept_code) REFERENCES departments(code)
) ENGINE=InnoDB;

-- ─── Revision requests (admin → user) ───────────────────────────
CREATE TABLE revision_requests (
  id INT PRIMARY KEY AUTO_INCREMENT,
  dept_code VARCHAR(20) NOT NULL,
  ppmp_id INT,
  fy INT NOT NULL,
  note TEXT NOT NULL,
  requested_by VARCHAR(100),
  resolved BOOLEAN DEFAULT FALSE,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (dept_code) REFERENCES departments(code)
) ENGINE=InnoDB;

-- Seed one existing revision request for demo
INSERT INTO revision_requests (dept_code, ppmp_id, fy, note, requested_by) VALUES
  ('ICTU', 3, 2025, 'Line items in Q2 exceed the department''s allocated ceiling. Please revise categorization for training materials.', 'Ramon Cruz'),
  ('HRMU', 11, 2027, 'Please reclassify training expenses under GAA instead of Fund 164, and attach the DTR for July personnel.', 'Ramon Cruz');

-- ─── Activity log (audit trail) ──────────────────────────────────
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

-- Seed sample activity for the demo (spread over the last 7 days)
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

-- ─── System settings (key/value) ─────────────────────────────────
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

-- ─── Backup history ──────────────────────────────────────────────
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

-- ─── Feature tables ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS email_otps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    code VARCHAR(10) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    expires_at DATETIME NOT NULL,   -- v110: DATETIME, so changing the row never moves the expiry
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_lookup (email, code, purpose)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS archived_ppmps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ppmp_id INT NOT NULL,
    dept_code VARCHAR(20) NOT NULL,
    fy INT NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_by VARCHAR(100),
    reason VARCHAR(200),
    UNIQUE KEY unique_ppmp (ppmp_id),
    INDEX idx_dept (dept_code),
    INDEX idx_fy (fy)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    posted_by VARCHAR(100),
    audience VARCHAR(20) DEFAULT 'all',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active, created_at)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_prefs (
    user_id INT NOT NULL,
    key_name VARCHAR(50) NOT NULL,
    value VARCHAR(200),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, key_name)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type VARCHAR(30) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT,
    link VARCHAR(200),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_created (created_at)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    username VARCHAR(50),
    full_name VARCHAR(100),
    dept_code VARCHAR(20),
    category VARCHAR(30),
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open','in_progress','resolved') DEFAULT 'open',
    admin_reply TEXT,
    replied_by VARCHAR(100),
    replied_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_user (user_id)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dept_budgets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dept_code VARCHAR(20) NOT NULL,
    fy INT NOT NULL,
    alloted_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
    travel_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
    training_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
    supply_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
    equipment_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
    notes TEXT,
    set_by VARCHAR(100),
    set_role VARCHAR(20),
    set_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dept_fy (dept_code, fy),
    INDEX idx_fy (fy)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS submission_deadlines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    deadline_date DATE NOT NULL,
    note TEXT,
    set_by VARCHAR(100),
    reminder_interval_days INT NOT NULL DEFAULT 2,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fy (fy),
    INDEX idx_deadline (deadline_date)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS submission_reminders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    dept_code VARCHAR(20) NOT NULL,
    fy INT NOT NULL,
    last_reminded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reminder_count INT DEFAULT 1,
    UNIQUE KEY uniq_user_fy (user_id, fy),
    INDEX idx_dept (dept_code)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS account_deletion_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    username VARCHAR(50),
    full_name VARCHAR(100),
    dept_code VARCHAR(20),
    reason TEXT,
    status ENUM('pending','approved','denied') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at TIMESTAMP NULL,
    decided_by VARCHAR(100),
    UNIQUE KEY uniq_pending (user_id, status),
    INDEX idx_status (status)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS deleted_ppmps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ppmp_id INT NOT NULL,
    dept_code VARCHAR(20) NOT NULL,
    fy INT NOT NULL,
    snapshot LONGTEXT,
    entry_count INT DEFAULT 0,
    total_amount DECIMAL(14,2) DEFAULT 0,
    deleted_by VARCHAR(100),
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dept (dept_code),
    INDEX idx_fy (fy)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nonps_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dept_code VARCHAR(20) NOT NULL,
    fy INT NOT NULL,
    status ENUM('draft','submitted','revision','approved') DEFAULT 'draft',
    submitted_by VARCHAR(100),
    submitted_at TIMESTAMP NULL,
    reviewed_by VARCHAR(100),
    reviewed_at TIMESTAMP NULL,
    review_note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dept_fy (dept_code, fy),
    INDEX idx_status (status)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nonps_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    submission_id INT NOT NULL,
    item_no INT DEFAULT 0,
    particulars TEXT,
    unit VARCHAR(50),
    quantity INT DEFAULT 0,
    unit_cost DECIMAL(14,2) DEFAULT 0,
    total_cost DECIMAL(14,2) DEFAULT 0,
    source_category VARCHAR(20) NULL,
    INDEX idx_submission (submission_id)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nonps_admin_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    dept_code VARCHAR(20) NOT NULL,
    source_key VARCHAR(255) NOT NULL,
    source_kind ENUM('ppmp','manual') NOT NULL DEFAULT 'ppmp',
    source_category VARCHAR(20) NULL,
    particulars TEXT,
    unit VARCHAR(50),
    quantity INT DEFAULT 0,
    unit_cost DECIMAL(14,2) DEFAULT 0,
    total_cost DECIMAL(14,2) DEFAULT 0,
    display_order INT DEFAULT 0,
    deleted TINYINT(1) DEFAULT 0,
    updated_by VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fy_dept_source (fy, dept_code, source_key),
    INDEX idx_fy_dept (fy, dept_code)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fund_pools (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    fund_source VARCHAR(60) NOT NULL,
    total_amount DECIMAL(14,2) DEFAULT 0,
    notes TEXT,
    set_by VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fy_source (fy, fund_source)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dept_units (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dept_code VARCHAR(20) NOT NULL,
    name VARCHAR(150) NOT NULL,
    display_order INT DEFAULT 100,
    INDEX idx_dept (dept_code),
    UNIQUE KEY uniq_dept_unit (dept_code, name)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appcse_qty (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    item_code VARCHAR(60) NOT NULL,
    `jan` DECIMAL(12,2) DEFAULT 0, `feb` DECIMAL(12,2) DEFAULT 0, `mar` DECIMAL(12,2) DEFAULT 0,
    `apr` DECIMAL(12,2) DEFAULT 0, `may` DECIMAL(12,2) DEFAULT 0, `jun` DECIMAL(12,2) DEFAULT 0,
    `jul` DECIMAL(12,2) DEFAULT 0, `aug` DECIMAL(12,2) DEFAULT 0, `sep` DECIMAL(12,2) DEFAULT 0,
    `oct` DECIMAL(12,2) DEFAULT 0, `nov` DECIMAL(12,2) DEFAULT 0, `dec` DECIMAL(12,2) DEFAULT 0,
    unit_price DECIMAL(14,2) DEFAULT 0,
    updated_by VARCHAR(150) DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fy_code (fy, item_code)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appcse_header (
    fy INT PRIMARY KEY,
    department VARCHAR(150) DEFAULT '',
    region VARCHAR(120) DEFAULT '',
    address VARCHAR(255) DEFAULT '',
    agency_code VARCHAR(120) DEFAULT '',
    contact_person VARCHAR(150) DEFAULT '',
    position VARCHAR(150) DEFAULT '',
    org_type VARCHAR(150) DEFAULT '',
    email VARCHAR(150) DEFAULT '',
    telephone VARCHAR(120) DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appcse_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    `part` VARCHAR(5) NOT NULL DEFAULT 'I',
    `category` VARCHAR(150) NOT NULL DEFAULT '',
    code VARCHAR(60) DEFAULT '',
    description VARCHAR(255) DEFAULT '',
    unit VARCHAR(40) DEFAULT '',
    unit_price DECIMAL(14,2) DEFAULT 0,
    `jan` DECIMAL(12,2) DEFAULT 0, `feb` DECIMAL(12,2) DEFAULT 0, `mar` DECIMAL(12,2) DEFAULT 0,
    `apr` DECIMAL(12,2) DEFAULT 0, `may` DECIMAL(12,2) DEFAULT 0, `jun` DECIMAL(12,2) DEFAULT 0,
    `jul` DECIMAL(12,2) DEFAULT 0, `aug` DECIMAL(12,2) DEFAULT 0, `sep` DECIMAL(12,2) DEFAULT 0,
    `oct` DECIMAL(12,2) DEFAULT 0, `nov` DECIMAL(12,2) DEFAULT 0, `dec` DECIMAL(12,2) DEFAULT 0,
    sort_order INT DEFAULT 0,
    updated_by VARCHAR(150) DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fy (fy)
    ) ENGINE=InnoDB;

-- The APP-CSE list of categories and items, per fiscal year. Every
-- department's APP-CSE for the year starts from it; the Procurement Unit
-- edits it on Document Format > APP-CSE. (Also created on first use by
-- config/appcse-items.php.)
CREATE TABLE IF NOT EXISTS appcse_catalogue_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    `part` VARCHAR(5) NOT NULL DEFAULT 'I',
    `category` VARCHAR(150) NOT NULL DEFAULT '',
    code VARCHAR(60) DEFAULT '',
    description VARCHAR(255) DEFAULT '',
    unit VARCHAR(40) DEFAULT '',
    unit_price DECIMAL(14,2) DEFAULT 0,
    sort_order INT DEFAULT 0,
    updated_by VARCHAR(150) DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_appcse_cat_fy (fy)
    ) ENGINE=InnoDB;

-- The Procurement Unit's own changes to the consolidated APP-CSE, laid
-- over the approved departments' sum. A NULL field follows the sum.
CREATE TABLE IF NOT EXISTS appcse_consol_edits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    line_key VARCHAR(255) NOT NULL DEFAULT '',
    is_added TINYINT(1) NOT NULL DEFAULT 0,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    `part` VARCHAR(5) NOT NULL DEFAULT 'I',
    `category` VARCHAR(150) NOT NULL DEFAULT '',
    item_key VARCHAR(255) NOT NULL DEFAULT '',
    code VARCHAR(60) NULL,
    description VARCHAR(255) NULL,
    unit VARCHAR(40) NULL,
    unit_price DECIMAL(14,2) NULL,
    `jan` DECIMAL(12,2) NULL, `feb` DECIMAL(12,2) NULL, `mar` DECIMAL(12,2) NULL,
    `apr` DECIMAL(12,2) NULL, `may` DECIMAL(12,2) NULL, `jun` DECIMAL(12,2) NULL,
    `jul` DECIMAL(12,2) NULL, `aug` DECIMAL(12,2) NULL, `sep` DECIMAL(12,2) NULL,
    `oct` DECIMAL(12,2) NULL, `nov` DECIMAL(12,2) NULL, `dec` DECIMAL(12,2) NULL,
    sort_order INT DEFAULT 0,
    updated_by VARCHAR(150) DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_appcse_consol_fy (fy)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS annex_forms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    dept_code VARCHAR(30) NOT NULL,
    annex VARCHAR(2) NOT NULL DEFAULT 'B',
    project_name VARCHAR(255) DEFAULT '',
    general_description TEXT,
    nep_account_1 VARCHAR(200) DEFAULT '',
    allocated_budget_1 DECIMAL(16,2) DEFAULT 0,
    nep_account_2 VARCHAR(200) DEFAULT '',
    allocated_budget_2 DECIMAL(16,2) DEFAULT 0,
    inclusive_dates VARCHAR(255) DEFAULT '',
    total_estimated DECIMAL(16,2) DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    submitted_by VARCHAR(150) DEFAULT '',
    submitted_at DATETIME NULL,
    reviewed_by VARCHAR(150) DEFAULT '',
    reviewed_at DATETIME NULL,
    review_note TEXT,
    created_by VARCHAR(150) DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fy_dept (fy, dept_code, annex)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS annex_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    form_id INT NOT NULL,
    item_group VARCHAR(80) DEFAULT '',
    specification VARCHAR(255) DEFAULT '',
    quantity DECIMAL(14,2) DEFAULT 0,
    total_amount DECIMAL(16,2) DEFAULT 0,
    sort_order INT DEFAULT 0,
    INDEX idx_form (form_id)
    ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_scoping (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fy INT NOT NULL,
    dept_code VARCHAR(30) NOT NULL,
    title VARCHAR(255) DEFAULT '',
    content LONGTEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    submitted_by VARCHAR(150) DEFAULT '',
    submitted_at DATETIME NULL,
    reviewed_by VARCHAR(150) DEFAULT '',
    reviewed_at DATETIME NULL,
    review_note TEXT,
    created_by VARCHAR(150) DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fy_dept (fy, dept_code)
    ) ENGINE=InnoDB;
