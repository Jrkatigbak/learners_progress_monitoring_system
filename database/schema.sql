-- Main application database for the Learners Progress Monitoring System.
CREATE DATABASE IF NOT EXISTS kiwi_learners_progress_monitoring_system_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE kiwi_learners_progress_monitoring_system_db;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_email_role (email, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores teacher records that can be assigned to class records.
CREATE TABLE IF NOT EXISTS teachers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_code VARCHAR(50) NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  specialization VARCHAR(160) NULL,
  profile_photo VARCHAR(255) NULL,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_teacher_code (teacher_code),
  INDEX idx_teachers_status (status),
  INDEX idx_teachers_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores classes that can be monitored from the Classes module.
CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT UNSIGNED NULL,
  class_name VARCHAR(160) NOT NULL,
  teacher VARCHAR(160) NOT NULL,
  banner_image VARCHAR(255) NULL,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_class_name (class_name),
  INDEX idx_classes_teacher_id (teacher_id),
  INDEX idx_classes_status (status)
  ,
  CONSTRAINT fk_classes_teacher
    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
    ON DELETE SET NULL
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
  banner_image VARCHAR(255) NULL,
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
  enrollment_status ENUM('Pending', 'Enrolled', 'In Progress', 'Completed', 'Dropped', 'Disapproved') NOT NULL DEFAULT 'Pending',
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

-- Stores class tasks that can receive learner grades.
CREATE TABLE IF NOT EXISTS class_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  folder_id INT UNSIGNED NULL,
  teacher_id INT UNSIGNED NULL,
  task_title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  task_date DATE NOT NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_class_tasks_class_id (class_id),
  INDEX idx_class_tasks_folder_id (folder_id),
  INDEX idx_class_tasks_teacher_id (teacher_id),
  CONSTRAINT fk_class_tasks_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_class_tasks_teacher
    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_class_tasks_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores grades given to learners by admins or assigned teachers.
CREATE TABLE IF NOT EXISTS learner_grades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NULL,
  learner_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NULL,
  grade_title VARCHAR(160) NOT NULL,
  score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  max_score DECIMAL(6,2) NOT NULL DEFAULT 100.00,
  remarks TEXT NULL,
  graded_at DATE NOT NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_grades_task_id (task_id),
  INDEX idx_grades_learner_id (learner_id),
  INDEX idx_grades_class_id (class_id),
  INDEX idx_grades_teacher_id (teacher_id),
  CONSTRAINT fk_grades_task
    FOREIGN KEY (task_id) REFERENCES class_tasks(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_grades_learner
    FOREIGN KEY (learner_id) REFERENCES learners(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_grades_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_grades_teacher
    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_grades_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores learning materials shared in each class workspace.
CREATE TABLE IF NOT EXISTS class_learning_materials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  folder_id INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  material_type VARCHAR(40) NOT NULL DEFAULT 'file',
  file_path VARCHAR(255) NULL,
  original_filename VARCHAR(255) NULL,
  mime_type VARCHAR(120) NULL,
  external_url VARCHAR(500) NULL,
  uploaded_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_materials_class_id (class_id),
  INDEX idx_materials_folder_id (folder_id),
  INDEX idx_materials_type (material_type),
  CONSTRAINT fk_materials_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_materials_uploaded_by
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groups class learning materials, quizzes, and assignments into topics.
CREATE TABLE IF NOT EXISTS class_material_folders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  description VARCHAR(255) NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_material_folders_class_id (class_id),
  INDEX idx_material_folders_name (name),
  CONSTRAINT fk_material_folders_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_material_folders_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores timed multiple-choice quizzes for each class.
CREATE TABLE IF NOT EXISTS class_quizzes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  folder_id INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  timer_minutes INT UNSIGNED NOT NULL DEFAULT 10,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_quizzes_class_id (class_id),
  INDEX idx_quizzes_folder_id (folder_id),
  INDEX idx_quizzes_status (status),
  CONSTRAINT fk_quizzes_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_quizzes_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id INT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_quiz_questions_quiz_id (quiz_id),
  CONSTRAINT fk_quiz_questions_quiz
    FOREIGN KEY (quiz_id) REFERENCES class_quizzes(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_choices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  choice_text TEXT NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  position INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_quiz_choices_question_id (question_id),
  CONSTRAINT fk_quiz_choices_question
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id INT UNSIGNED NOT NULL,
  learner_id INT UNSIGNED NOT NULL,
  score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  total_questions INT UNSIGNED NOT NULL DEFAULT 0,
  correct_answers INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  submitted_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_quiz_attempt_learner (quiz_id, learner_id),
  INDEX idx_quiz_attempts_quiz_id (quiz_id),
  INDEX idx_quiz_attempts_learner_id (learner_id),
  CONSTRAINT fk_quiz_attempts_quiz
    FOREIGN KEY (quiz_id) REFERENCES class_quizzes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_quiz_attempts_learner
    FOREIGN KEY (learner_id) REFERENCES learners(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  choice_id INT UNSIGNED NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt_answers_attempt_id (attempt_id),
  INDEX idx_attempt_answers_question_id (question_id),
  CONSTRAINT fk_attempt_answers_attempt
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_attempt_answers_question
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_attempt_answers_choice
    FOREIGN KEY (choice_id) REFERENCES quiz_choices(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores class assignments and learner submissions.
CREATE TABLE IF NOT EXISTS class_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  folder_id INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  instructions TEXT NULL,
  due_date DATE NULL,
  attachment_path VARCHAR(255) NULL,
  original_filename VARCHAR(255) NULL,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_assignments_class_id (class_id),
  INDEX idx_assignments_folder_id (folder_id),
  INDEX idx_assignments_status (status),
  CONSTRAINT fk_assignments_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_assignments_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assignment_id INT UNSIGNED NOT NULL,
  learner_id INT UNSIGNED NOT NULL,
  submission_text TEXT NULL,
  file_path VARCHAR(255) NULL,
  original_filename VARCHAR(255) NULL,
  submitted_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assignment_learner (assignment_id, learner_id),
  INDEX idx_submissions_assignment_id (assignment_id),
  INDEX idx_submissions_learner_id (learner_id),
  CONSTRAINT fk_submissions_assignment
    FOREIGN KEY (assignment_id) REFERENCES class_assignments(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_submissions_learner
    FOREIGN KEY (learner_id) REFERENCES learners(id)
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

INSERT INTO teachers (teacher_code, full_name, email, phone, specialization, status)
VALUES
  ('TCH-001', 'Maria Santos', 'maria.santos@example.local', '0917 000 2001', 'Work Immersion', 'Active'),
  ('TCH-002', 'Juan Dela Cruz', 'juan.delacruz@example.local', '0917 000 2002', 'Practicum Program', 'Active')
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  email = VALUES(email),
  phone = VALUES(phone),
  specialization = VALUES(specialization),
  status = VALUES(status);

INSERT INTO classes (class_name, teacher, status, description)
VALUES
  ('Work Immersion', 'Maria Santos', 'Active', 'Learners assigned for weekly progress monitoring.'),
  ('Practicum Program', 'Juan Dela Cruz', 'Active', 'College learners under supervised practicum tracking.')
ON DUPLICATE KEY UPDATE
  teacher = VALUES(teacher),
  status = VALUES(status),
  description = VALUES(description);

UPDATE classes
INNER JOIN teachers ON teachers.full_name = classes.teacher
SET classes.teacher_id = teachers.id
WHERE classes.teacher_id IS NULL;

INSERT INTO learners (class_id, learner_number, first_name, last_name, email, phone, progress_percent, status, notes)
SELECT id, 'LRN-2026-001', 'Andrea', 'Reyes', 'andrea.reyes@example.local', '0917 000 1001', 72, 'Active', 'Completing weekly progress reports.'
FROM classes
WHERE class_name = 'Work Immersion'
LIMIT 1
ON DUPLICATE KEY UPDATE
  class_id = VALUES(class_id),
  progress_percent = VALUES(progress_percent),
  status = VALUES(status);

INSERT INTO learners (class_id, learner_number, first_name, last_name, email, phone, progress_percent, status, notes)
SELECT id, 'LRN-2026-002', 'Miguel', 'Santos', 'miguel.santos@example.local', '0917 000 1002', 45, 'Active', 'Needs teacher follow-up this week.'
FROM classes
WHERE class_name = 'Practicum Program'
LIMIT 1
ON DUPLICATE KEY UPDATE
  class_id = VALUES(class_id),
  progress_percent = VALUES(progress_percent),
  status = VALUES(status);

INSERT INTO learners (class_id, learner_number, first_name, last_name, email, phone, progress_percent, status, notes)
SELECT id, 'LRN-2026-003', 'Bianca', 'Cruz', 'bianca.cruz@example.local', '0917 000 1003', 100, 'Completed', 'Finished required learner progress milestones.'
FROM classes
WHERE class_name = 'Work Immersion'
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
