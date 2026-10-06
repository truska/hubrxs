-- Developer task tracking: shared notes, threaded comments and private images.
CREATE TABLE IF NOT EXISTS hub_dev_task (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task_name VARCHAR(255) NOT NULL,
  task_note TEXT NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 3,
  status ENUM('open','in_progress','completed','closed','future','on_hold') NOT NULL DEFAULT 'open',
  created_by INT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NOT NULL,
  next_action_by INT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dev_task_status_priority (status, priority, modified),
  KEY idx_dev_task_assignee (next_action_by),
  KEY idx_dev_task_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hub_dev_task_comment (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NOT NULL,
  author_name VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  image_filename VARCHAR(80) NULL,
  is_activity TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dev_task_comment_thread (task_id, created, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
