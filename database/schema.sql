-- Main application database for the Learners Progress Monitoring System.
CREATE DATABASE IF NOT EXISTS kiwi_learners_progress_monitoring_system_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE kiwi_learners_progress_monitoring_system_db;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores class sections that can be monitored from the Classes module.
CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_name VARCHAR(160) NOT NULL,
  section VARCHAR(100) NOT NULL,
  adviser VARCHAR(160) NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_class_section_year (class_name, section, school_year),
  INDEX idx_classes_status (status),
  INDEX idx_classes_school_year (school_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores learner profiles with optional photos and progress tracking.
CREATE TABLE IF NOT EXISTS learners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NULL,
  learner_number VARCHAR(50) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Active', 'On Hold', 'Completed') NOT NULL DEFAULT 'Active',
  profile_photo VARCHAR(255) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_learner_number (learner_number),
  INDEX idx_learners_class_id (class_id),
  INDEX idx_learners_status (status),
  CONSTRAINT fk_learners_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores courses that learners can be enrolled into by the admin.
CREATE TABLE IF NOT EXISTS courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(50) NOT NULL,
  course_name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_course_code (course_code),
  INDEX idx_courses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connects learners to courses and keeps their enrollment status.
CREATE TABLE IF NOT EXISTS course_enrollments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  learner_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  enrollment_status ENUM('Enrolled', 'In Progress', 'Completed', 'Dropped') NOT NULL DEFAULT 'Enrolled',
  enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_learner_course (learner_id, course_id),
  INDEX idx_enrollments_status (enrollment_status),
  CONSTRAINT fk_enrollments_learner
    FOREIGN KEY (learner_id) REFERENCES learners(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_enrollments_course
    FOREIGN KEY (course_id) REFERENCES courses(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (name, email, password_hash, role)
VALUES (
  'Learners Administrator',
  'admin@learnersprogress.local',
  '$2y$10$DhFM9Arkv4x.9l34tF70HuWyWzfyC8AX0sfSCVn3qNt9Br7tf7vc6',
  'admin'
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  password_hash = VALUES(password_hash),
  role = VALUES(role);

INSERT INTO classes (class_name, section, adviser, school_year, status, description)
VALUES
  ('Work Immersion', 'Grade 12 - ICT A', 'Maria Santos', '2026-2027', 'Active', 'Learners assigned for weekly progress monitoring.'),
  ('Practicum Program', 'BSIT 4A', 'Juan Dela Cruz', '2026-2027', 'Active', 'College learners under supervised practicum tracking.')
ON DUPLICATE KEY UPDATE
  adviser = VALUES(adviser),
  status = VALUES(status),
  description = VALUES(description);

INSERT INTO learners (class_id, learner_number, first_name, last_name, email, phone, progress_percent, status, notes)
SELECT id, 'LRN-2026-001', 'Andrea', 'Reyes', 'andrea.reyes@example.local', '0917 000 1001', 72, 'Active', 'Completing weekly progress reports.'
FROM classes
WHERE class_name = 'Work Immersion' AND section = 'Grade 12 - ICT A'
LIMIT 1
ON DUPLICATE KEY UPDATE
  class_id = VALUES(class_id),
  progress_percent = VALUES(progress_percent),
  status = VALUES(status);

INSERT INTO learners (class_id, learner_number, first_name, last_name, email, phone, progress_percent, status, notes)
SELECT id, 'LRN-2026-002', 'Miguel', 'Santos', 'miguel.santos@example.local', '0917 000 1002', 45, 'Active', 'Needs adviser follow-up this week.'
FROM classes
WHERE class_name = 'Practicum Program' AND section = 'BSIT 4A'
LIMIT 1
ON DUPLICATE KEY UPDATE
  class_id = VALUES(class_id),
  progress_percent = VALUES(progress_percent),
  status = VALUES(status);

INSERT INTO learners (class_id, learner_number, first_name, last_name, email, phone, progress_percent, status, notes)
SELECT id, 'LRN-2026-003', 'Bianca', 'Cruz', 'bianca.cruz@example.local', '0917 000 1003', 100, 'Completed', 'Finished required learner progress milestones.'
FROM classes
WHERE class_name = 'Work Immersion' AND section = 'Grade 12 - ICT A'
LIMIT 1
ON DUPLICATE KEY UPDATE
  class_id = VALUES(class_id),
  progress_percent = VALUES(progress_percent),
  status = VALUES(status);

INSERT INTO courses (course_code, course_name, description, status)
VALUES
  ('DIGI-101', 'Digital Literacy', 'Core digital skills course for learner onboarding.', 'Active'),
  ('WEB-201', 'Web Development Basics', 'Introductory HTML, CSS, and web publishing course.', 'Active'),
  ('PROG-301', 'Progress Portfolio', 'Learner output tracking and portfolio completion course.', 'Active')
ON DUPLICATE KEY UPDATE
  course_name = VALUES(course_name),
  description = VALUES(description),
  status = VALUES(status);
