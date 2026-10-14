<?php
require_once __DIR__ . '/db.php';
function ensure_department_change_requests(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS department_change_requests (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      current_dept VARCHAR(30) NOT NULL,
      requested_dept VARCHAR(30) NOT NULL,
      note VARCHAR(1000) NULL,
      status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
      requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      reviewed_by VARCHAR(150) NULL,
      reviewed_at DATETIME NULL,
      INDEX idx_dcr_status (status), INDEX idx_dcr_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
