<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$database = new Database();
$pdo = $database->connect();
$auth = new Auth($pdo);

// Keep older local databases compatible with the learner approval workflow.
try {
    // Users can share an email across learner and teacher roles.
    $emailOnlyIndex = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND INDEX_NAME = 'email'"
    )->fetchColumn();

    if ((int) $emailOnlyIndex > 0) {
        $pdo->exec('ALTER TABLE users DROP INDEX email');
    }

    $emailRoleIndex = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND INDEX_NAME = 'uniq_user_email_role'"
    )->fetchColumn();

    if ((int) $emailRoleIndex === 0) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uniq_user_email_role (email, role)');
    }

    // Teacher masterlist is created here so older local databases can use the new Classes dropdown.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teachers (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $teacherPhotoColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'teachers'
           AND COLUMN_NAME = 'profile_photo'
         LIMIT 1"
    );

    if (!$teacherPhotoColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE teachers ADD profile_photo VARCHAR(255) NULL AFTER specialization');
    }

    $teacherIdColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'classes'
           AND COLUMN_NAME = 'teacher_id'
         LIMIT 1"
    );

    if (!$teacherIdColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE classes ADD teacher_id INT UNSIGNED NULL AFTER id');
        $pdo->exec('ALTER TABLE classes ADD INDEX idx_classes_teacher_id (teacher_id)');
    }

    $classSortColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'classes'
           AND COLUMN_NAME = 'sort_order'
         LIMIT 1"
    );

    if (!$classSortColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE classes ADD sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER teacher_id');
        $pdo->exec('ALTER TABLE classes ADD INDEX idx_classes_sort_order (sort_order)');

        // Preserve the old visible order as the initial manual order.
        $classRows = $pdo->query('SELECT id FROM classes ORDER BY created_at DESC, id DESC')->fetchAll();
        $classOrderStatement = $pdo->prepare('UPDATE classes SET sort_order = :sort_order WHERE id = :id');
        foreach ($classRows as $index => $classRow) {
            $classOrderStatement->execute([
                'sort_order' => $index + 1,
                'id' => (int) $classRow['id'],
            ]);
        }
    }

    $existingTeachers = $pdo->query("SELECT DISTINCT teacher FROM classes WHERE teacher <> ''")->fetchAll();
    $teacherExistsStatement = $pdo->prepare('SELECT id FROM teachers WHERE full_name = :full_name LIMIT 1');
    $teacherSeedStatement = $pdo->prepare(
        "INSERT INTO teachers (teacher_code, full_name, status)
         VALUES (:teacher_code, :full_name, 'Active')
         ON DUPLICATE KEY UPDATE teacher_code = teacher_code"
    );

    foreach ($existingTeachers as $teacherRow) {
        $teacherName = trim((string) ($teacherRow['teacher'] ?? ''));

        if ($teacherName === '') {
            continue;
        }

        $teacherExistsStatement->execute(['full_name' => $teacherName]);

        if ($teacherExistsStatement->fetchColumn()) {
            continue;
        }

        $teacherSeedStatement->execute([
            'teacher_code' => 'TCH-' . strtoupper(substr(hash('crc32b', $teacherName), 0, 6)),
            'full_name' => $teacherName,
        ]);
    }

    $pdo->exec(
        "UPDATE classes
         INNER JOIN teachers ON teachers.full_name = classes.teacher
         SET classes.teacher_id = teachers.id
         WHERE classes.teacher_id IS NULL"
    );

    // Multiple teacher assignments are managed inside the class workspace.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_teachers (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          class_id INT UNSIGNED NOT NULL,
          teacher_id INT UNSIGNED NOT NULL,
          assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_class_teacher (class_id, teacher_id),
          INDEX idx_class_teachers_class_id (class_id),
          INDEX idx_class_teachers_teacher_id (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Seed old single-teacher classes into the new assignment table once.
    $pdo->exec(
        "INSERT IGNORE INTO class_teachers (class_id, teacher_id)
         SELECT id, teacher_id
         FROM classes
         WHERE teacher_id IS NOT NULL"
    );

    // Teacher portal access uses teacher email accounts with a local default password.
    $teacherUsers = $pdo->query("SELECT full_name, email FROM teachers WHERE email IS NOT NULL AND email <> ''")->fetchAll();
    $teacherUserStatement = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role)
         VALUES (:name, :email, :password_hash, 'teacher')
         ON DUPLICATE KEY UPDATE
            name = VALUES(name)"
    );
    $teacherPasswordHash = password_hash('Teacher@12345', PASSWORD_DEFAULT);

    foreach ($teacherUsers as $teacherUser) {
        $teacherUserStatement->execute([
            'name' => $teacherUser['full_name'],
            'email' => $teacherUser['email'],
            'password_hash' => $teacherPasswordHash,
        ]);
    }

    // Grades can be created by either admins or assigned teachers.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_tasks (
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
          INDEX idx_class_tasks_teacher_id (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS learner_grades (
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
          INDEX idx_grades_teacher_id (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $gradeTaskColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'learner_grades'
           AND COLUMN_NAME = 'task_id'
         LIMIT 1"
    );

    if (!$gradeTaskColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE learner_grades ADD task_id INT UNSIGNED NULL AFTER id');
        $pdo->exec('ALTER TABLE learner_grades ADD INDEX idx_grades_task_id (task_id)');
    }

    $classTaskFolderColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'class_tasks'
           AND COLUMN_NAME = 'folder_id'
         LIMIT 1"
    );

    if (!$classTaskFolderColumn->fetchColumn()) {
        // Grade items can optionally belong to the same class topics used by materials, quizzes, and assignments.
        $pdo->exec('ALTER TABLE class_tasks ADD folder_id INT UNSIGNED NULL AFTER class_id');
        $pdo->exec('ALTER TABLE class_tasks ADD INDEX idx_class_tasks_folder_id (folder_id)');
    }

    // Learning materials can be uploaded per class by admins or the assigned teacher.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_learning_materials (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          class_id INT UNSIGNED NOT NULL,
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
          INDEX idx_materials_type (material_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Topics organize materials, quizzes, and assignments without changing existing uploaded files.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_material_folders (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          class_id INT UNSIGNED NOT NULL,
          name VARCHAR(140) NOT NULL,
          description VARCHAR(255) NULL,
          banner_image VARCHAR(255) NULL,
          sort_order INT UNSIGNED NOT NULL DEFAULT 0,
          created_by_user_id INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_material_folders_class_id (class_id),
          INDEX idx_material_folders_sort_order (class_id, sort_order),
          INDEX idx_material_folders_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $topicBannerColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'class_material_folders'
           AND COLUMN_NAME = 'banner_image'
         LIMIT 1"
    );

    if (!$topicBannerColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE class_material_folders ADD banner_image VARCHAR(255) NULL AFTER description');
    }

    $topicSortColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'class_material_folders'
           AND COLUMN_NAME = 'sort_order'
         LIMIT 1"
    );

    if (!$topicSortColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE class_material_folders ADD sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER banner_image');
        $pdo->exec('ALTER TABLE class_material_folders ADD INDEX idx_material_folders_sort_order (class_id, sort_order)');

        // Preserve the old visible topic order as the initial manual order per class.
        $topicRows = $pdo->query('SELECT id, class_id FROM class_material_folders ORDER BY class_id ASC, created_at DESC, id DESC')->fetchAll();
        $topicOrderByClass = [];
        $topicOrderStatement = $pdo->prepare('UPDATE class_material_folders SET sort_order = :sort_order WHERE id = :id');
        foreach ($topicRows as $topicRow) {
            $topicClassId = (int) $topicRow['class_id'];
            $topicOrderByClass[$topicClassId] = ($topicOrderByClass[$topicClassId] ?? 0) + 1;
            $topicOrderStatement->execute([
                'sort_order' => $topicOrderByClass[$topicClassId],
                'id' => (int) $topicRow['id'],
            ]);
        }
    }

    $topicSortIndex = $pdo->query(
        "SELECT INDEX_NAME
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'class_material_folders'
           AND INDEX_NAME = 'idx_material_folders_sort_order'
         LIMIT 1"
    );

    if (!$topicSortIndex->fetchColumn()) {
        $pdo->exec('ALTER TABLE class_material_folders ADD INDEX idx_material_folders_sort_order (class_id, sort_order)');
    }

    $materialFolderColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'class_learning_materials'
           AND COLUMN_NAME = 'folder_id'
         LIMIT 1"
    );

    if (!$materialFolderColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE class_learning_materials ADD folder_id INT UNSIGNED NULL AFTER class_id');
        $pdo->exec('ALTER TABLE class_learning_materials ADD INDEX idx_materials_folder_id (folder_id)');
    }

    // Timed multiple-choice quizzes compute learner scores automatically.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_quizzes (
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
          INDEX idx_quizzes_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $quizFolderColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'class_quizzes'
           AND COLUMN_NAME = 'folder_id'
         LIMIT 1"
    );

    if (!$quizFolderColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE class_quizzes ADD folder_id INT UNSIGNED NULL AFTER class_id');
        $pdo->exec('ALTER TABLE class_quizzes ADD INDEX idx_quizzes_folder_id (folder_id)');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quiz_questions (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          quiz_id INT UNSIGNED NOT NULL,
          question_text TEXT NOT NULL,
          position INT UNSIGNED NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_quiz_questions_quiz_id (quiz_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quiz_choices (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          question_id INT UNSIGNED NOT NULL,
          choice_text TEXT NOT NULL,
          is_correct TINYINT(1) NOT NULL DEFAULT 0,
          position INT UNSIGNED NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_quiz_choices_question_id (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quiz_attempts (
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
          INDEX idx_quiz_attempts_learner_id (learner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          attempt_id INT UNSIGNED NOT NULL,
          question_id INT UNSIGNED NOT NULL,
          choice_id INT UNSIGNED NULL,
          is_correct TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_attempt_answers_attempt_id (attempt_id),
          INDEX idx_attempt_answers_question_id (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Class assignments let teachers/admins collect learner work submissions.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_assignments (
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
          INDEX idx_assignments_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $assignmentFolderColumn = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'class_assignments'
           AND COLUMN_NAME = 'folder_id'
         LIMIT 1"
    );

    if (!$assignmentFolderColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE class_assignments ADD folder_id INT UNSIGNED NULL AFTER class_id');
        $pdo->exec('ALTER TABLE class_assignments ADD INDEX idx_assignments_folder_id (folder_id)');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assignment_submissions (
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
          INDEX idx_submissions_learner_id (learner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $statusColumn = $pdo->query(
        "SELECT COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'course_enrollments'
           AND COLUMN_NAME = 'enrollment_status'
         LIMIT 1"
    );
    $currentType = (string) ($statusColumn->fetchColumn() ?: '');

    if ($currentType !== '' && strpos($currentType, 'Pending') === false) {
        $pdo->exec(
            "ALTER TABLE course_enrollments
             MODIFY enrollment_status ENUM('Pending', 'Enrolled', 'In Progress', 'Completed', 'Dropped', 'Disapproved')
             NOT NULL DEFAULT 'Pending'"
        );
    }
} catch (PDOException $exception) {
    // Fresh imports already match the schema; pages can continue if the column is already current.
}
