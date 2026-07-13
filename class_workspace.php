<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/classes/SmtpMailer.php';
require_once __DIR__ . '/includes/enrollment_helpers.php';

$mailerConfig = require __DIR__ . '/config/Mailer.php';

// Class workspace keeps learners, tasks, grades, materials, quizzes, and assignments inside one selected class.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function materialTypeFromFile(string $mimeType, string $extension): string
{
    // Keep material cards readable by grouping uploaded files into familiar classroom types.
    if (strpos($mimeType, 'image/') === 0) {
        return 'image';
    }

    if (strpos($mimeType, 'video/') === 0) {
        return 'video';
    }

    if ($mimeType === 'application/pdf' || strtolower($extension) === 'pdf') {
        return 'pdf';
    }

    return 'file';
}

function materialIcon(string $materialType): string
{
    $icons = [
        'image' => 'fa-regular fa-image',
        'video' => 'fa-solid fa-play',
        'pdf' => 'fa-regular fa-file-pdf',
        'youtube' => 'fa-brands fa-youtube',
        'link' => 'fa-solid fa-link',
    ];

    return $icons[$materialType] ?? 'fa-regular fa-file-lines';
}

function deleteMaterialFile(string $filePath): void
{
    if ($filePath === '' || strpos($filePath, 'uploads/materials/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $filePath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

function deleteAssignmentFile(string $filePath): void
{
    if ($filePath === '' || strpos($filePath, 'uploads/assignments/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $filePath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

function deleteTopicBanner(string $bannerPath): void
{
    if ($bannerPath === '' || strpos($bannerPath, 'uploads/topics/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $bannerPath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

function generateLearnerPassword(): string
{
    // Reset passwords stay temporary-looking and hard to guess before the learner changes them.
    return 'Kiwi-' . bin2hex(random_bytes(3)) . '-' . random_int(100, 999);
}

function classWorkspaceLoginUrl(): string
{
    // Build the login URL from the current request host so localhost and the live domain both send the right link.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . ($basePath ? $basePath : '') . '/login.php';
}

function sendClassLearnerCredentialEmail(SmtpMailer $mailer, string $email, string $name, string $className, string $password, string $loginUrl): bool
{
    $subject = kiwiLmsClassEmailSubject($className, 'Login Credentials Reset');
    $textBody = "Hello {$name},\n\n"
        . "Your Learners Progress Monitoring System login credentials were reset.\n\n"
        . "Login link: {$loginUrl}\n"
        . "Email: {$email}\n"
        . "Password: {$password}\n\n"
        . "Please keep these credentials secure.\n\n"
        . "Kiwi Digital Tech";
    $htmlBody = '<p>Hello ' . e($name) . ',</p>'
        . '<p>Your <strong>Learners Progress Monitoring System</strong> login credentials were reset.</p>'
        . '<p><strong>Login link:</strong> <a href="' . e($loginUrl) . '">' . e($loginUrl) . '</a><br>'
        . '<strong>Email:</strong> ' . e($email) . '<br>'
        . '<strong>Password:</strong> ' . e($password) . '</p>'
        . '<p>Please keep these credentials secure.</p>'
        . '<p>Kiwi Digital Tech</p>';

    return $mailer->send($email, $name, $subject, $htmlBody, $textBody);
}

function classWorkspaceUrl(int $classId): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . ($basePath ? $basePath : '') . '/class_workspace.php?class_id=' . $classId;
}

function sendClassTeacherAssignedEmail(SmtpMailer $mailer, string $email, string $name, string $className, string $workspaceUrl): bool
{
    $subject = kiwiLmsClassEmailSubject($className, 'Teacher Class Assignment');
    $textBody = "Hello {$name},\n\n"
        . "You have been assigned as a teacher for {$className}.\n\n"
        . "Open class workspace: {$workspaceUrl}\n"
        . "Login link: " . classWorkspaceLoginUrl() . "\n\n"
        . "Kiwi Digital Tech";
    $htmlBody = '<p>Hello ' . e($name) . ',</p>'
        . '<p>You have been assigned as a teacher for <strong>' . e($className) . '</strong>.</p>'
        . '<p><strong>Open class workspace:</strong> <a href="' . e($workspaceUrl) . '">' . e($workspaceUrl) . '</a><br>'
        . '<strong>Login link:</strong> <a href="' . e(classWorkspaceLoginUrl()) . '">' . e(classWorkspaceLoginUrl()) . '</a></p>'
        . '<p>Kiwi Digital Tech</p>';

    return $mailer->send($email, $name, $subject, $htmlBody, $textBody);
}

function approveClassEnrollmentRequest(PDO $pdo, array $request, int $courseId, int $adminUserId, string $password): int
{
    $fullName = kiwiEnrollmentName($request);
    $existingLearnerStatement = $pdo->prepare('SELECT id, class_id FROM learners WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $existingLearnerStatement->execute(['email' => $request['email']]);
    $existingLearner = $existingLearnerStatement->fetch() ?: null;
    $learnerId = (int) ($existingLearner['id'] ?? 0);

    if ($learnerId > 0) {
        $otherClassStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM course_enrollments
             INNER JOIN courses ON courses.id = course_enrollments.course_id
             WHERE course_enrollments.learner_id = :learner_id
               AND course_enrollments.course_id <> :course_id
               AND course_enrollments.deleted_at IS NULL
               AND courses.deleted_at IS NULL'
        );
        $otherClassStatement->execute([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
        ]);

        // Learners are exclusive to one class, so block approvals for emails already linked elsewhere.
        $legacyClassId = (int) ($existingLearner['class_id'] ?? 0);
        if ((int) $otherClassStatement->fetchColumn() > 0 || ($legacyClassId > 0 && $legacyClassId !== (int) $request['class_id'])) {
            throw new RuntimeException('Learner already belongs to another class.');
        }

        // Keep the learner master record current when the same email is approved for this class.
        $learnerUpdate = $pdo->prepare(
            'UPDATE learners
             SET first_name = :first_name,
                 middle_name = :middle_name,
                 last_name = :last_name,
                 phone = :phone,
                 status = "Active"
             WHERE id = :id'
        );
        $learnerUpdate->execute([
            'first_name' => $request['first_name'],
            'middle_name' => $request['middle_name'] !== '' ? $request['middle_name'] : null,
            'last_name' => $request['last_name'],
            'phone' => $request['contact_number'] !== '' ? $request['contact_number'] : null,
            'id' => $learnerId,
        ]);
    } else {
        $learnerInsert = $pdo->prepare(
            'INSERT INTO learners
                (learner_number, first_name, middle_name, last_name, email, phone, status)
             VALUES
                (:learner_number, :first_name, :middle_name, :last_name, :email, :phone, "Active")'
        );
        $learnerInsert->execute([
            'learner_number' => kiwiGenerateLearnerNumber($pdo),
            'first_name' => $request['first_name'],
            'middle_name' => $request['middle_name'] !== '' ? $request['middle_name'] : null,
            'last_name' => $request['last_name'],
            'email' => $request['email'],
            'phone' => $request['contact_number'] !== '' ? $request['contact_number'] : null,
        ]);
        $learnerId = (int) $pdo->lastInsertId();
    }

    $userStatement = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role)
         VALUES (:name, :email, :password_hash, "learner")
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            password_hash = VALUES(password_hash),
            deleted_at = NULL'
    );
    $userStatement->execute([
        'name' => $fullName,
        'email' => $request['email'],
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $enrollmentLookup = $pdo->prepare('SELECT id FROM course_enrollments WHERE learner_id = :learner_id AND course_id = :course_id AND deleted_at IS NULL LIMIT 1');
    $enrollmentLookup->execute([
        'learner_id' => $learnerId,
        'course_id' => $courseId,
    ]);
    $existingEnrollmentId = (int) $enrollmentLookup->fetchColumn();

    if ($existingEnrollmentId > 0) {
        $enrollmentUpdate = $pdo->prepare('UPDATE course_enrollments SET enrollment_status = "Enrolled" WHERE id = :id');
        $enrollmentUpdate->execute(['id' => $existingEnrollmentId]);
    } else {
        $enrollmentInsert = $pdo->prepare(
            'INSERT INTO course_enrollments (learner_id, course_id, enrollment_status)
             VALUES (:learner_id, :course_id, "Enrolled")'
        );
        $enrollmentInsert->execute([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
        ]);
    }

    $requestUpdate = $pdo->prepare(
        'UPDATE class_enrollment_requests
         SET status = "Approved",
             learner_id = :learner_id,
             reviewed_at = NOW(),
             reviewed_by_user_id = :reviewed_by_user_id
         WHERE id = :id'
    );
    $requestUpdate->execute([
        'learner_id' => $learnerId,
        'reviewed_by_user_id' => $adminUserId,
        'id' => (int) $request['id'],
    ]);

    return $learnerId;
}

function classCourseId(PDO $pdo, array $class): int
{
    $courseCode = 'CLASS-' . (int) $class['id'];
    $courseStatement = $pdo->prepare(
        'INSERT INTO courses (course_code, course_name, description, status)
         VALUES (:course_code, :course_name, :description, :status)
         ON DUPLICATE KEY UPDATE
            course_name = VALUES(course_name),
            description = VALUES(description),
            status = VALUES(status),
            deleted_at = NULL'
    );
    $courseStatement->execute([
        'course_code' => $courseCode,
        'course_name' => $class['class_name'],
        'description' => $class['description'] ?? null,
        'status' => $class['status'],
    ]);

    $lookupStatement = $pdo->prepare('SELECT id FROM courses WHERE course_code = :course_code AND deleted_at IS NULL LIMIT 1');
    $lookupStatement->execute(['course_code' => $courseCode]);

    return (int) $lookupStatement->fetchColumn();
}

function classTopicExists(PDO $pdo, int $classId, int $topicId): bool
{
    if ($topicId <= 0) {
        return true;
    }

    // Topics are scoped to a class, so forms cannot attach records to another class topic.
    $topicStatement = $pdo->prepare('SELECT id FROM class_material_folders WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
    $topicStatement->execute([
        'id' => $topicId,
        'class_id' => $classId,
    ]);

    return (bool) $topicStatement->fetch();
}

function classTaskGradeSettingColumns(PDO $pdo): array
{
    $columns = [];

    foreach ($pdo->query('DESCRIBE class_tasks') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    return [
        'max_score' => isset($columns['max_score']),
        'passing_score' => isset($columns['passing_score']),
    ];
}

function learnerGradeColumns(PDO $pdo): array
{
    $columns = [];

    foreach ($pdo->query('DESCRIBE learner_grades') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    return $columns;
}

function normalizeGradeItemSettings(array $source, array &$errors): array
{
    $maxScoreRaw = trim((string) ($source['max_score'] ?? '100'));
    $passingScoreRaw = trim((string) ($source['passing_score'] ?? ''));
    $maxScore = $maxScoreRaw !== '' ? (float) $maxScoreRaw : 100.0;
    $passingScore = $passingScoreRaw !== '' ? (float) $passingScoreRaw : null;

    if ($maxScore < 1) {
        $errors[] = 'Max grade must be at least 1.';
    }

    if ($passingScore !== null && $passingScore < 1) {
        $errors[] = 'Passing grade must be at least 1.';
    }

    if ($passingScore !== null && $passingScore > $maxScore) {
        $errors[] = 'Passing grade cannot be higher than the max grade.';
    }

    return [
        'max_score' => $maxScore,
        'passing_score' => $passingScore,
    ];
}

function normalizeTopicSortOrder(PDO $pdo, int $classId): void
{
    // Keep topic ordering compact per class so the up/down controls swap the nearest neighbor.
    $topicRowsStatement = $pdo->prepare('SELECT id FROM class_material_folders WHERE class_id = :class_id AND deleted_at IS NULL ORDER BY sort_order ASC, created_at DESC, id DESC');
    $topicRowsStatement->execute(['class_id' => $classId]);
    $orderStatement = $pdo->prepare('UPDATE class_material_folders SET sort_order = :sort_order WHERE id = :id AND class_id = :class_id');

    foreach ($topicRowsStatement->fetchAll() as $index => $topicRow) {
        $orderStatement->execute([
            'sort_order' => $index + 1,
            'id' => (int) $topicRow['id'],
            'class_id' => $classId,
        ]);
    }
}

function prepareQuizQuestions(array $questions, array &$errors): array
{
    $preparedQuestions = [];

    foreach ($questions as $question) {
        $questionText = trim((string) ($question['text'] ?? ''));
        $choices = [
            trim((string) ($question['choices'][0] ?? '')),
            trim((string) ($question['choices'][1] ?? '')),
            trim((string) ($question['choices'][2] ?? '')),
            trim((string) ($question['choices'][3] ?? '')),
        ];
        $correctIndex = (int) ($question['correct'] ?? -1);

        if ($questionText === '' && implode('', $choices) === '') {
            continue;
        }

        if ($questionText === '' || in_array('', $choices, true) || $correctIndex < 0 || $correctIndex > 3) {
            $errors[] = 'Complete every quiz question, four choices, and the correct answer.';
            break;
        }

        $preparedQuestions[] = [
            'text' => $questionText,
            'choices' => $choices,
            'correct' => $correctIndex,
        ];
    }

    if (!$preparedQuestions && !$errors) {
        $errors[] = 'Add at least one quiz question.';
    }

    return $preparedQuestions;
}

function replaceQuizQuestions(PDO $pdo, int $quizId, array $preparedQuestions): void
{
    // Replacing the answer key requires fresh question and choice ids for reliable scoring.
    $choiceDelete = $pdo->prepare(
        'UPDATE quiz_choices
         INNER JOIN quiz_questions ON quiz_questions.id = quiz_choices.question_id
         SET quiz_choices.deleted_at = NOW()
         WHERE quiz_questions.quiz_id = :quiz_id
           AND quiz_choices.deleted_at IS NULL'
    );
    $choiceDelete->execute(['quiz_id' => $quizId]);

    $questionDelete = $pdo->prepare('UPDATE quiz_questions SET deleted_at = NOW() WHERE quiz_id = :quiz_id AND deleted_at IS NULL');
    $questionDelete->execute(['quiz_id' => $quizId]);

    $questionStatement = $pdo->prepare(
        'INSERT INTO quiz_questions (quiz_id, question_text, position)
         VALUES (:quiz_id, :question_text, :position)'
    );
    $choiceStatement = $pdo->prepare(
        'INSERT INTO quiz_choices (question_id, choice_text, is_correct, position)
         VALUES (:question_id, :choice_text, :is_correct, :position)'
    );

    foreach ($preparedQuestions as $questionIndex => $question) {
        $questionStatement->execute([
            'quiz_id' => $quizId,
            'question_text' => $question['text'],
            'position' => $questionIndex + 1,
        ]);
        $questionId = (int) $pdo->lastInsertId();

        foreach ($question['choices'] as $choiceIndex => $choiceText) {
            $choiceStatement->execute([
                'question_id' => $questionId,
                'choice_text' => $choiceText,
                'is_correct' => $choiceIndex === (int) $question['correct'] ? 1 : 0,
                'position' => $choiceIndex + 1,
            ]);
        }
    }
}

function classWorkspaceCan(PDO $pdo, bool $isAdmin, string $permission): bool
{
    if ($isAdmin) {
        return true;
    }

    $user = $_SESSION['user'] ?? null;

    // Existing teacher accounts keep access until the Teacher role gets an explicit permission set.
    if (($user['role'] ?? '') === 'teacher' && kiwiUserPermissions($pdo, $user) === []) {
        return true;
    }

    return kiwiCan($pdo, $permission, $user);
}

function classWorkspaceCanAny(PDO $pdo, bool $isAdmin, array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (classWorkspaceCan($pdo, $isAdmin, $permission)) {
            return true;
        }
    }

    return false;
}

function classWorkspaceToolPermission(string $tool): string
{
    $toolPermissions = [
        'learners' => 'class_learners.view',
        'teachers' => 'class_teachers.view',
        'materials' => 'class_materials.view',
        'quizzes' => 'class_quizzes.view',
        'assignments' => 'class_assignments.view',
        'grades' => 'class_grades.view',
    ];

    return $toolPermissions[$tool] ?? '';
}

function classWorkspaceActionPermissions(string $action): array
{
    $rules = [
        'approve_enrollment_request' => ['any' => ['class_learners.edit']],
        'disapprove_enrollment_request' => ['any' => ['class_learners.edit']],
        'reset_learner_credentials' => ['any' => ['class_learners.edit']],
        'remove_class_learner' => ['any' => ['class_learners.delete']],
        'add_class_learners' => ['any' => ['class_learners.add']],
        'add_class_teachers' => ['any' => ['class_teachers.add']],
        'remove_class_teacher' => ['any' => ['class_teachers.delete']],
        'save_material' => ['any' => ['class_materials.add']],
        'update_material' => ['any' => ['class_materials.edit']],
        'move_material_topic' => ['any' => ['class_materials.edit']],
        'delete_material' => ['any' => ['class_materials.delete']],
        'save_quiz' => ['any' => ['class_quizzes.add']],
        'update_quiz' => ['any' => ['class_quizzes.edit']],
        'delete_quiz' => ['any' => ['class_quizzes.delete']],
        'save_assignment' => ['any' => ['class_assignments.add']],
        'delete_assignment' => ['any' => ['class_assignments.delete']],
        'save_task' => ['any' => ['class_grades.add']],
        'update_task' => ['any' => ['class_grades.edit']],
        'delete_task' => ['any' => ['class_grades.delete']],
        'save_grades' => ['any' => ['class_grades.edit']],
        'ajax_save_grade_other_remarks' => ['any' => ['class_grades.edit']],
        'ajax_save_grade_score' => ['any' => ['class_grades.edit']],
        'save_material_folder' => ['any' => ['class_materials.add', 'class_quizzes.add', 'class_assignments.add', 'class_grades.add']],
        'update_material_folder' => ['any' => ['class_materials.edit', 'class_quizzes.edit', 'class_assignments.edit', 'class_grades.edit']],
        'move_material_folder' => ['any' => ['class_materials.edit', 'class_quizzes.edit', 'class_assignments.edit', 'class_grades.edit']],
        'delete_material_folder' => ['any' => ['class_materials.delete', 'class_quizzes.delete', 'class_assignments.delete', 'class_grades.delete']],
    ];

    return $rules[$action] ?? [];
}

$classId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$tool = $_GET['tool'] ?? $_POST['tool'] ?? 'dashboard';
$allowedTools = ['dashboard', 'learners', 'teachers', 'materials', 'quizzes', 'assignments', 'tasks', 'grades'];
$tool = in_array($tool, $allowedTools, true) ? $tool : 'dashboard';
$errors = [];
$success = $_GET['success'] ?? '';
$selectedTopicId = (int) ($_GET['topic_id'] ?? $_POST['topic_id'] ?? 0);
$isAdmin = $auth->isAdminSideUser();
$isSystemAdmin = (($currentUser['role'] ?? '') === 'admin');
$isTeacher = $auth->isTeacher();
$teacherProfile = null;
$materialUploadDirectory = __DIR__ . '/uploads/materials';
$materialUploadPathPrefix = 'uploads/materials/';
$assignmentUploadDirectory = __DIR__ . '/uploads/assignments';
$assignmentUploadPathPrefix = 'uploads/assignments/';
$topicUploadDirectory = __DIR__ . '/uploads/topics';
$topicUploadPathPrefix = 'uploads/topics/';
$taskGradeSettingColumns = classTaskGradeSettingColumns($pdo);
$learnerGradeColumns = learnerGradeColumns($pdo);

if (!$isAdmin && !$isTeacher) {
    header('Location: ' . $auth->redirectPath());
    exit;
}

if ($isAdmin) {
    kiwiRequirePermission($pdo, 'classes.manage');
}

if (!is_dir($materialUploadDirectory)) {
    // Material uploads are kept outside the class image folder so they can be managed separately.
    mkdir($materialUploadDirectory, 0777, true);
}

if (is_dir($materialUploadDirectory)) {
    chmod($materialUploadDirectory, 0777);
}

if (!is_dir($assignmentUploadDirectory)) {
    // Assignment files are kept separate from learning materials and learner submissions.
    mkdir($assignmentUploadDirectory, 0777, true);
}

if (is_dir($assignmentUploadDirectory)) {
    chmod($assignmentUploadDirectory, 0777);
}

if (!is_dir($topicUploadDirectory)) {
    // Topic wallpapers live in their own folder so deleting a topic banner never touches class banners.
    mkdir($topicUploadDirectory, 0777, true);
}

if (is_dir($topicUploadDirectory)) {
    @chmod($topicUploadDirectory, 0777);
}

$classStatement = $pdo->prepare(
    'SELECT classes.*
     FROM classes
     WHERE classes.id = :id
       AND classes.deleted_at IS NULL
     LIMIT 1'
);
$classStatement->execute(['id' => $classId]);
$class = $classStatement->fetch();

if (!$class) {
    header('Location: classes.php');
    exit;
}

$assignedTeacherStatement = $pdo->prepare(
    'SELECT teachers.*
     FROM class_teachers
     INNER JOIN teachers ON teachers.id = class_teachers.teacher_id
     WHERE class_teachers.class_id = :class_id
       AND class_teachers.deleted_at IS NULL
       AND teachers.deleted_at IS NULL
     ORDER BY teachers.full_name'
);
$assignedTeacherStatement->execute(['class_id' => $classId]);
$assignedTeachers = $assignedTeacherStatement->fetchAll();
$assignedTeacherIds = array_map(static fn ($teacher): int => (int) $teacher['id'], $assignedTeachers);

if (!$assignedTeachers && !empty($class['teacher_id'])) {
    // Older class records may still only use classes.teacher_id, so keep them visible until reassigned.
    $legacyTeacherStatement = $pdo->prepare('SELECT * FROM teachers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $legacyTeacherStatement->execute(['id' => (int) $class['teacher_id']]);
    $legacyTeacher = $legacyTeacherStatement->fetch() ?: null;

    if ($legacyTeacher) {
        $assignedTeachers[] = $legacyTeacher;
        $assignedTeacherIds[] = (int) $legacyTeacher['id'];
    }
}

$class['display_teacher'] = $assignedTeachers
    ? implode(', ', array_map(static fn ($teacher): string => (string) $teacher['full_name'], $assignedTeachers))
    : (string) ($class['teacher'] ?? '');
$primaryTeacher = $assignedTeachers[0] ?? null;

if ($isTeacher) {
    $teacherStatement = $pdo->prepare('SELECT * FROM teachers WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $teacherStatement->execute(['email' => $currentUser['email']]);
    $teacherProfile = $teacherStatement->fetch() ?: null;

    if (!$teacherProfile || !in_array((int) $teacherProfile['id'], $assignedTeacherIds, true)) {
        // Teachers can manage only classes they are assigned to from the class workspace.
        header('Location: teacher_dashboard.php');
        exit;
    }
}

$activeTeacherId = $isTeacher && $teacherProfile ? (int) $teacherProfile['id'] : (int) ($primaryTeacher['id'] ?? 0);

$courseId = classCourseId($pdo, $class);
$classToolAccess = [
    'dashboard' => true,
    'learners' => classWorkspaceCan($pdo, $isSystemAdmin, 'class_learners.view'),
    'teachers' => classWorkspaceCan($pdo, $isSystemAdmin, 'class_teachers.view'),
    'materials' => classWorkspaceCan($pdo, $isSystemAdmin, 'class_materials.view'),
    'quizzes' => classWorkspaceCan($pdo, $isSystemAdmin, 'class_quizzes.view'),
    'assignments' => classWorkspaceCan($pdo, $isSystemAdmin, 'class_assignments.view'),
    'tasks' => classWorkspaceCanAny($pdo, $isSystemAdmin, ['class_materials.view', 'class_quizzes.view', 'class_assignments.view', 'class_grades.view']),
    'grades' => classWorkspaceCan($pdo, $isSystemAdmin, 'class_grades.view'),
];

if (empty($classToolAccess[$tool])) {
    $fallbackTool = array_key_first(array_filter($classToolAccess));
    if ($fallbackTool) {
        header('Location: class_workspace.php?class_id=' . $classId . '&tool=' . $fallbackTool);
        exit;
    }

    header('Location: ' . ($isTeacher ? 'teacher_dashboard.php' : 'classes.php'));
    exit;
}

$canManageLearnersAdd = classWorkspaceCan($pdo, $isSystemAdmin, 'class_learners.add');
$canManageLearnersEdit = classWorkspaceCan($pdo, $isSystemAdmin, 'class_learners.edit');
$canManageLearnersDelete = classWorkspaceCan($pdo, $isSystemAdmin, 'class_learners.delete');
$canManageTeachersAdd = classWorkspaceCan($pdo, $isSystemAdmin, 'class_teachers.add');
$canManageTeachersDelete = classWorkspaceCan($pdo, $isSystemAdmin, 'class_teachers.delete');
$canManageMaterialsAdd = classWorkspaceCan($pdo, $isSystemAdmin, 'class_materials.add');
$canManageMaterialsEdit = classWorkspaceCan($pdo, $isSystemAdmin, 'class_materials.edit');
$canManageMaterialsDelete = classWorkspaceCan($pdo, $isSystemAdmin, 'class_materials.delete');
$canManageQuizzesAdd = classWorkspaceCan($pdo, $isSystemAdmin, 'class_quizzes.add');
$canManageQuizzesEdit = classWorkspaceCan($pdo, $isSystemAdmin, 'class_quizzes.edit');
$canManageQuizzesDelete = classWorkspaceCan($pdo, $isSystemAdmin, 'class_quizzes.delete');
$canManageAssignmentsAdd = classWorkspaceCan($pdo, $isSystemAdmin, 'class_assignments.add');
$canManageAssignmentsDelete = classWorkspaceCan($pdo, $isSystemAdmin, 'class_assignments.delete');
$canManageGradesAdd = classWorkspaceCan($pdo, $isSystemAdmin, 'class_grades.add');
$canManageGradesEdit = classWorkspaceCan($pdo, $isSystemAdmin, 'class_grades.edit');
$canManageGradesDelete = classWorkspaceCan($pdo, $isSystemAdmin, 'class_grades.delete');
$canManageTopicsAdd = classWorkspaceCanAny($pdo, $isSystemAdmin, ['class_materials.add', 'class_quizzes.add', 'class_assignments.add', 'class_grades.add']);
$canManageTopicsEdit = classWorkspaceCanAny($pdo, $isSystemAdmin, ['class_materials.edit', 'class_quizzes.edit', 'class_assignments.edit', 'class_grades.edit']);
$canManageTopicsDelete = classWorkspaceCanAny($pdo, $isSystemAdmin, ['class_materials.delete', 'class_quizzes.delete', 'class_assignments.delete', 'class_grades.delete']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $actionRule = classWorkspaceActionPermissions($action);

    if (!empty($actionRule['any']) && !classWorkspaceCanAny($pdo, $isSystemAdmin, $actionRule['any'])) {
        if (strpos($action, 'ajax_') === 0 || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['ok' => false, 'message' => 'You do not have permission to do this action.']);
            exit;
        }

        $errors[] = 'You do not have permission to do this action.';
        $action = '__blocked';
    }

    if (in_array($action, ['ajax_save_grade_other_remarks', 'ajax_save_grade_score'], true)) {
        header('Content-Type: application/json');

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $learnerId = (int) ($_POST['learner_id'] ?? 0);
        $scoreValue = trim((string) ($_POST['score'] ?? ''));
        $resultRemark = trim((string) ($_POST['result_remark'] ?? ''));
        $otherRemark = trim((string) ($_POST['other_remarks'] ?? ''));

        $taskStatement = $pdo->prepare('SELECT * FROM class_tasks WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $taskStatement->execute([
            'id' => $taskId,
            'class_id' => $classId,
        ]);
        $task = $taskStatement->fetch() ?: null;

        if (!$task || $learnerId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Select a valid learner grade row.']);
            exit;
        }

        $learnerStatement = $pdo->prepare(
            'SELECT learners.id
             FROM learners
             LEFT JOIN course_enrollments ON course_enrollments.learner_id = learners.id
                AND course_enrollments.deleted_at IS NULL
             WHERE learners.id = :learner_id
               AND learners.deleted_at IS NULL
               AND (
                 learners.class_id = :class_id
                 OR (
                   course_enrollments.course_id = :course_id
                   AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
                 )
               )
             LIMIT 1'
        );
        $learnerStatement->execute([
            'learner_id' => $learnerId,
            'class_id' => $classId,
            'course_id' => $courseId,
        ]);

        if (!$learnerStatement->fetch()) {
            echo json_encode(['ok' => false, 'message' => 'Learner is not enrolled in this class.']);
            exit;
        }

        $existingStatement = $pdo->prepare('SELECT id FROM learner_grades WHERE task_id = :task_id AND learner_id = :learner_id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([
            'task_id' => $taskId,
            'learner_id' => $learnerId,
            'class_id' => $classId,
        ]);
        $existingGradeId = (int) $existingStatement->fetchColumn();
        $taskMaxScore = max(1.0, (float) ($task['max_score'] ?? 100));
        $score = $scoreValue !== '' ? (float) $scoreValue : null;

        if ($score !== null && ($score < 1 || $score > $taskMaxScore)) {
            echo json_encode(['ok' => false, 'message' => 'Grade is outside the allowed range.']);
            exit;
        }

        if ($existingGradeId > 0) {
            $setSql = 'remarks = :remarks';
            $updateParams = [
                'remarks' => $resultRemark !== '' ? $resultRemark : null,
                'id' => $existingGradeId,
            ];

            if ($score !== null) {
                $setSql .= ', score = :score, max_score = :max_score, graded_at = :graded_at, grade_title = :grade_title, created_by_user_id = :created_by_user_id';
                $updateParams['score'] = $score;
                $updateParams['max_score'] = $taskMaxScore;
                $updateParams['graded_at'] = date('Y-m-d');
                $updateParams['grade_title'] = $task['task_title'];
                $updateParams['created_by_user_id'] = (int) $currentUser['id'];
            }

            if (!empty($learnerGradeColumns['other_remarks'])) {
                $setSql .= ', other_remarks = :other_remarks';
                $updateParams['other_remarks'] = $otherRemark !== '' ? $otherRemark : null;
            }

            $updateRemark = $pdo->prepare('UPDATE learner_grades SET ' . $setSql . ' WHERE id = :id');
            $updateRemark->execute($updateParams);

            echo json_encode(['ok' => true, 'grade_id' => $existingGradeId, 'message' => 'Saved']);
            exit;
        }

        if ($scoreValue === '') {
            echo json_encode(['ok' => false, 'message' => 'Enter a grade first before autosaving remarks.']);
            exit;
        }

        $insertColumns = 'task_id, learner_id, class_id, teacher_id, grade_title, score, max_score, remarks, graded_at, created_by_user_id';
        $insertValues = ':task_id, :learner_id, :class_id, :teacher_id, :grade_title, :score, :max_score, :remarks, :graded_at, :created_by_user_id';
        $insertParams = [
            'task_id' => $taskId,
            'learner_id' => $learnerId,
            'class_id' => $classId,
            'teacher_id' => $activeTeacherId > 0 ? $activeTeacherId : null,
            'grade_title' => $task['task_title'],
            'score' => $score,
            'max_score' => $taskMaxScore,
            'remarks' => $resultRemark !== '' ? $resultRemark : null,
            'graded_at' => date('Y-m-d'),
            'created_by_user_id' => (int) $currentUser['id'],
        ];

        if (!empty($learnerGradeColumns['other_remarks'])) {
            $insertColumns = 'task_id, learner_id, class_id, teacher_id, grade_title, score, max_score, remarks, other_remarks, graded_at, created_by_user_id';
            $insertValues = ':task_id, :learner_id, :class_id, :teacher_id, :grade_title, :score, :max_score, :remarks, :other_remarks, :graded_at, :created_by_user_id';
            $insertParams['other_remarks'] = $otherRemark !== '' ? $otherRemark : null;
        }
        $insertRemark = $pdo->prepare(
            'INSERT INTO learner_grades
                (' . $insertColumns . ')
             VALUES
                (' . $insertValues . ')'
        );
        $insertRemark->execute($insertParams);

        echo json_encode(['ok' => true, 'grade_id' => (int) $pdo->lastInsertId(), 'message' => 'Saved']);
        exit;
    }

    if ($action === 'approve_enrollment_request') {
        $requestId = (int) ($_POST['request_id'] ?? 0);

        $requestStatement = $pdo->prepare(
            'SELECT *
             FROM class_enrollment_requests
             WHERE id = :id AND class_id = :class_id AND status = "Pending" AND deleted_at IS NULL
             LIMIT 1'
        );
        $requestStatement->execute([
            'id' => $requestId,
            'class_id' => $classId,
        ]);
        $enrollmentRequest = $requestStatement->fetch() ?: null;

        if (!$enrollmentRequest) {
            $errors[] = 'Select a pending enrollment request.';
        }

        if (!$errors) {
            $learnerPassword = generateLearnerPassword();

            try {
                $pdo->beginTransaction();
                approveClassEnrollmentRequest($pdo, $enrollmentRequest, $courseId, (int) $currentUser['id'], $learnerPassword);
                $pdo->commit();

                $mailer = new SmtpMailer($mailerConfig);
                $emailSent = kiwiSendEnrollmentApprovedEmail(
                    $mailer,
                    (string) $enrollmentRequest['email'],
                    kiwiEnrollmentName($enrollmentRequest),
                    (string) $class['class_name'],
                    $learnerPassword
                );
                $emailStatus = $emailSent ? 'sent' : 'failed';
                $mailError = $emailStatus === 'failed' ? '&mail_error=' . urlencode($mailer->getLastError()) : '';

                header('Location: class_workspace.php?class_id=' . $classId . '&tool=learners&success=enrollment_approved&credential_email=' . $emailStatus . $mailError);
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Enrollment request could not be approved.';
            }
        }

        $tool = 'learners';
    }

    if ($action === 'disapprove_enrollment_request') {
        $requestId = (int) ($_POST['request_id'] ?? 0);

        $requestStatement = $pdo->prepare(
            'SELECT *
             FROM class_enrollment_requests
             WHERE id = :id AND class_id = :class_id AND status = "Pending" AND deleted_at IS NULL
             LIMIT 1'
        );
        $requestStatement->execute([
            'id' => $requestId,
            'class_id' => $classId,
        ]);
        $enrollmentRequest = $requestStatement->fetch() ?: null;

        if (!$enrollmentRequest) {
            $errors[] = 'Select a pending enrollment request.';
        }

        if (!$errors) {
            $updateStatement = $pdo->prepare(
                'UPDATE class_enrollment_requests
                 SET status = "Disapproved",
                     reviewed_at = NOW(),
                     reviewed_by_user_id = :reviewed_by_user_id
                 WHERE id = :id AND class_id = :class_id'
            );
            $updateStatement->execute([
                'reviewed_by_user_id' => (int) $currentUser['id'],
                'id' => $requestId,
                'class_id' => $classId,
            ]);

            $mailer = new SmtpMailer($mailerConfig);
            $emailSent = kiwiSendEnrollmentDisapprovedEmail(
                $mailer,
                (string) $enrollmentRequest['email'],
                kiwiEnrollmentName($enrollmentRequest),
                (string) $class['class_name']
            );
            $emailStatus = $emailSent ? 'sent' : 'failed';
            $mailError = $emailStatus === 'failed' ? '&mail_error=' . urlencode($mailer->getLastError()) : '';

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=learners&success=enrollment_disapproved&notification_email=' . $emailStatus . $mailError);
            exit;
        }

        $tool = 'learners';
    }

    if ($action === 'reset_learner_credentials') {
        $learnerId = (int) ($_POST['learner_id'] ?? 0);

        $learnerStatement = $pdo->prepare(
            'SELECT DISTINCT learners.*
             FROM learners
             LEFT JOIN course_enrollments
                ON course_enrollments.learner_id = learners.id
               AND course_enrollments.course_id = :course_id
               AND course_enrollments.deleted_at IS NULL
             WHERE learners.id = :learner_id
               AND learners.deleted_at IS NULL
               AND (
                    learners.class_id = :class_id
                    OR course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
               )
             LIMIT 1'
        );
        $learnerStatement->execute([
            'learner_id' => $learnerId,
            'class_id' => $classId,
            'course_id' => $courseId,
        ]);
        $learnerCredential = $learnerStatement->fetch() ?: null;

        if (!$learnerCredential) {
            $errors[] = 'Select a valid learner from this class.';
        }

        $learnerEmail = trim((string) ($learnerCredential['email'] ?? ''));
        $learnerName = $learnerCredential ? trim((string) $learnerCredential['first_name'] . ' ' . (string) $learnerCredential['last_name']) : '';

        if ($learnerEmail === '' || !filter_var($learnerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Learner needs a valid email before credentials can be resent.';
        }

        if (!$errors) {
            $learnerPassword = generateLearnerPassword();
            $userStatement = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role)
                 VALUES (:name, :email, :password_hash, :role)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    password_hash = VALUES(password_hash),
            deleted_at = NULL'
            );
            $userStatement->execute([
                'name' => $learnerName,
                'email' => $learnerEmail,
                'password_hash' => password_hash($learnerPassword, PASSWORD_DEFAULT),
                'role' => 'learner',
            ]);

            $mailer = new SmtpMailer($mailerConfig);
            $emailSent = sendClassLearnerCredentialEmail($mailer, $learnerEmail, $learnerName, (string) $class['class_name'], $learnerPassword, classWorkspaceLoginUrl());
            $emailStatus = $emailSent ? 'sent' : 'failed';
            $mailError = $emailStatus === 'failed' ? '&mail_error=' . urlencode($mailer->getLastError()) : '';

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=learners&success=learner_credentials_reset&credential_email=' . $emailStatus . $mailError);
            exit;
        }

        $tool = 'learners';
    }

    if ($action === 'remove_class_learner') {
        $learnerId = (int) ($_POST['learner_id'] ?? 0);

        $learnerStatement = $pdo->prepare(
            'SELECT DISTINCT learners.id
             FROM learners
             LEFT JOIN course_enrollments
                ON course_enrollments.learner_id = learners.id
               AND course_enrollments.course_id = :course_id
               AND course_enrollments.deleted_at IS NULL
             WHERE learners.id = :learner_id
               AND learners.deleted_at IS NULL
               AND (
                    learners.class_id = :class_id
                    OR course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
               )
             LIMIT 1'
        );
        $learnerStatement->execute([
            'learner_id' => $learnerId,
            'class_id' => $classId,
            'course_id' => $courseId,
        ]);

        if (!$learnerStatement->fetch()) {
            $errors[] = 'Select a valid learner from this class.';
        }

        if (!$errors) {
            // Soft remove the learner from this class while preserving the learner profile and history.
            $removeEnrollment = $pdo->prepare(
                'UPDATE course_enrollments
                 SET deleted_at = NOW(), enrollment_status = "Dropped"
                 WHERE learner_id = :learner_id
                   AND course_id = :course_id
                   AND deleted_at IS NULL'
            );
            $removeEnrollment->execute([
                'learner_id' => $learnerId,
                'course_id' => $courseId,
            ]);

            $clearLegacyClass = $pdo->prepare(
                'UPDATE learners
                 SET class_id = NULL
                 WHERE id = :learner_id
                   AND class_id = :class_id
                   AND deleted_at IS NULL'
            );
            $clearLegacyClass->execute([
                'learner_id' => $learnerId,
                'class_id' => $classId,
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=learners&success=learner_removed');
            exit;
        }

        $tool = 'learners';
    }

    if ($action === 'add_class_learners') {
        $selectedLearnerIds = array_map('intval', $_POST['learner_ids'] ?? []);
        $selectedLearnerIds = array_values(array_unique(array_filter($selectedLearnerIds)));

        if (!$selectedLearnerIds) {
            $errors[] = 'Select at least one learner.';
        }

        if (!$errors) {
            // Add learners only when they are not connected to any other class-backed course.
            $eligibleStatement = $pdo->prepare(
                'SELECT learners.id
                 FROM learners
                 LEFT JOIN course_enrollments
                    ON course_enrollments.learner_id = learners.id
                   AND course_enrollments.deleted_at IS NULL
                 WHERE learners.id = :learner_id
                   AND learners.deleted_at IS NULL
                   AND learners.class_id IS NULL
                   AND course_enrollments.id IS NULL
                 LIMIT 1'
            );
            $enrollStatement = $pdo->prepare(
                'INSERT INTO course_enrollments (learner_id, course_id, enrollment_status)
                 VALUES (:learner_id, :course_id, "Enrolled")'
            );
            $addedCount = 0;

            foreach ($selectedLearnerIds as $learnerId) {
                $eligibleStatement->execute([
                    'learner_id' => $learnerId,
                ]);

                if (!$eligibleStatement->fetch()) {
                    continue;
                }

                $enrollStatement->execute([
                    'learner_id' => $learnerId,
                    'course_id' => $courseId,
                ]);
                $addedCount++;
            }

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=learners&success=learners_added&added=' . $addedCount);
            exit;
        }

        $tool = 'learners';
    }

    if ($action === 'add_class_teachers') {
        $selectedTeacherIds = array_map('intval', $_POST['teacher_ids'] ?? []);
        $selectedTeacherIds = array_values(array_unique(array_filter($selectedTeacherIds)));

        if (!$selectedTeacherIds) {
            $errors[] = 'Select at least one teacher.';
        }

        if (!$errors) {
            // A teacher should be hidden only when already connected to this class.
            $eligibleStatement = $pdo->prepare(
                'SELECT teachers.id, teachers.full_name, teachers.email
                 FROM teachers
                 LEFT JOIN class_teachers
                    ON class_teachers.teacher_id = teachers.id
                   AND class_teachers.class_id = :class_id
                   AND class_teachers.deleted_at IS NULL
                 LEFT JOIN classes AS legacy_class
                    ON legacy_class.teacher_id = teachers.id
                   AND legacy_class.id = :legacy_class_id
                   AND legacy_class.deleted_at IS NULL
                 WHERE teachers.id = :teacher_id
                   AND teachers.status = "Active"
                   AND teachers.deleted_at IS NULL
                   AND class_teachers.id IS NULL
                   AND legacy_class.id IS NULL
                 LIMIT 1'
            );
            $assignStatement = $pdo->prepare(
                'INSERT INTO class_teachers (class_id, teacher_id)
                 VALUES (:class_id, :teacher_id)
                 ON DUPLICATE KEY UPDATE deleted_at = NULL'
            );
            $addedCount = 0;
            $teachersToNotify = [];

            foreach ($selectedTeacherIds as $teacherId) {
                $eligibleStatement->execute([
                    'class_id' => $classId,
                    'legacy_class_id' => $classId,
                    'teacher_id' => $teacherId,
                ]);

                $eligibleTeacher = $eligibleStatement->fetch() ?: null;

                if (!$eligibleTeacher) {
                    continue;
                }

                $assignStatement->execute([
                    'class_id' => $classId,
                    'teacher_id' => $teacherId,
                ]);
                $addedCount++;
                $teachersToNotify[] = $eligibleTeacher;
            }

            $teacherEmailStatus = 'skipped';
            $teacherMailError = '';

            if ($teachersToNotify) {
                // Notify newly assigned teachers after the assignment is saved so the email reflects real access.
                $mailer = new SmtpMailer($mailerConfig);
                $workspaceUrl = classWorkspaceUrl($classId);
                $emailFailures = [];
                $sentCount = 0;

                foreach ($teachersToNotify as $teacherToNotify) {
                    $teacherEmail = trim((string) ($teacherToNotify['email'] ?? ''));
                    $teacherName = trim((string) ($teacherToNotify['full_name'] ?? ''));

                    if ($teacherEmail === '' || !filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) {
                        $emailFailures[] = $teacherName !== '' ? $teacherName : 'Teacher';
                        continue;
                    }

                    if (sendClassTeacherAssignedEmail($mailer, $teacherEmail, $teacherName, (string) $class['class_name'], $workspaceUrl)) {
                        $sentCount++;
                    } else {
                        $emailFailures[] = $teacherName !== '' ? $teacherName : $teacherEmail;
                    }
                }

                $teacherEmailStatus = $emailFailures ? 'failed' : ($sentCount > 0 ? 'sent' : 'skipped');
                $teacherMailError = $emailFailures ? 'Could not notify: ' . implode(', ', $emailFailures) . '. ' . $mailer->getLastError() : '';
            }

            $teacherMailQuery = '&teacher_email=' . $teacherEmailStatus . ($teacherMailError !== '' ? '&mail_error=' . urlencode($teacherMailError) : '');
            header('Location: class_workspace.php?class_id=' . $classId . '&tool=teachers&success=teachers_added&added=' . $addedCount . $teacherMailQuery);
            exit;
        }

        $tool = 'teachers';
    }

    if ($action === 'remove_class_teacher') {
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);

        if ($teacherId <= 0) {
            $errors[] = 'Select a valid teacher.';
        }

        if (!$errors) {
            // Removing an assignment keeps the teacher masterlist and login account intact.
            $removeStatement = $pdo->prepare('UPDATE class_teachers SET deleted_at = NOW() WHERE class_id = :class_id AND teacher_id = :teacher_id AND deleted_at IS NULL');
            $removeStatement->execute([
                'class_id' => $classId,
                'teacher_id' => $teacherId,
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=teachers&success=teacher_removed');
            exit;
        }

        $tool = 'teachers';
    }

    if ($action === 'save_material') {
        $title = trim($_POST['material_title'] ?? '');
        $description = trim($_POST['material_description'] ?? '');
        $folderId = (int) ($_POST['material_folder_id'] ?? 0);
        $postedMaterialUrls = $_POST['material_urls'] ?? [];
        $postedMaterialUrls = is_array($postedMaterialUrls) ? $postedMaterialUrls : [$postedMaterialUrls];
        $materialUrls = array_values(array_filter(array_map('trim', $postedMaterialUrls), static fn ($url): bool => $url !== ''));
        $uploadedMaterials = [];
        $maxMaterialFileSize = 200 * 1024 * 1024 * 1024;

        if ($title === '') {
            $errors[] = 'Material title is required.';
        }

        if (!classTopicExists($pdo, $classId, $folderId)) {
            $errors[] = 'Select a valid topic.';
        }

        $fileErrors = $_FILES['material_files']['error'] ?? [];
        $fileErrors = is_array($fileErrors) ? $fileErrors : [$fileErrors];
        $hasUpload = array_filter($fileErrors, static fn ($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE) !== [];
        $hasLink = count($materialUrls) > 0;

        if (!$hasLink && !$hasUpload) {
            $errors[] = 'Upload at least one file or paste a learning material link.';
        }

        // Validate every submitted external material link because the UI can add multiple link rows.
        foreach ($materialUrls as $materialUrl) {
            if (!filter_var($materialUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Enter valid YouTube or website links.';
                break;
            }
        }

        if ($hasUpload && !$errors) {
            if (!is_writable($materialUploadDirectory)) {
                $errors[] = 'Learning material upload folder is not writable.';
            } else {
                $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'sh'];
                $fileNames = $_FILES['material_files']['name'] ?? [];
                $fileTmpNames = $_FILES['material_files']['tmp_name'] ?? [];
                $fileSizes = $_FILES['material_files']['size'] ?? [];
                $fileNames = is_array($fileNames) ? $fileNames : [$fileNames];
                $fileTmpNames = is_array($fileTmpNames) ? $fileTmpNames : [$fileTmpNames];
                $fileSizes = is_array($fileSizes) ? $fileSizes : [$fileSizes];

                foreach ($fileErrors as $fileIndex => $fileError) {
                    if ((int) $fileError === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    if ((int) $fileError !== UPLOAD_ERR_OK) {
                        $errors[] = 'One learning material file could not be uploaded.';
                        break;
                    }

                    $originalFilename = basename((string) ($fileNames[$fileIndex] ?? 'material'));
                    $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                    $fileSize = (int) ($fileSizes[$fileIndex] ?? 0);

                    if (in_array($extension, $blockedExtensions, true)) {
                        $errors[] = 'This file type is not allowed for learning materials.';
                        break;
                    }

                    if ($fileSize > $maxMaterialFileSize) {
                        $errors[] = 'Each learning material file must be 200GB or smaller.';
                        break;
                    }

                    $tmpName = (string) ($fileTmpNames[$fileIndex] ?? '');
                    $mimeType = $tmpName !== '' ? (mime_content_type($tmpName) ?: 'application/octet-stream') : 'application/octet-stream';
                    $materialType = materialTypeFromFile($mimeType, $extension);
                    $safeExtension = $extension !== '' ? $extension : 'bin';
                    $filename = 'material-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $safeExtension;
                    $targetPath = $materialUploadDirectory . '/' . $filename;

                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $uploadedMaterials[] = [
                            'file_path' => $materialUploadPathPrefix . $filename,
                            'original_filename' => $originalFilename,
                            'mime_type' => $mimeType,
                            'material_type' => $materialType,
                        ];
                    } else {
                        $errors[] = 'Learning material file could not be saved.';
                        break;
                    }
                }
            }
        }

        if (!$errors) {
            // Store each uploaded file and each external link as its own class material.
            $materialStatement = $pdo->prepare(
                'INSERT INTO class_learning_materials
                    (class_id, folder_id, title, description, material_type, file_path, original_filename, mime_type, external_url, uploaded_by_user_id)
                 VALUES
                    (:class_id, :folder_id, :title, :description, :material_type, :file_path, :original_filename, :mime_type, :external_url, :uploaded_by_user_id)'
            );
            $totalMaterialItems = count($uploadedMaterials) + count($materialUrls);
            foreach ($uploadedMaterials as $index => $uploadedMaterial) {
                $materialTitle = $totalMaterialItems === 1
                    ? $title
                    : $title . ' - ' . $uploadedMaterial['original_filename'];
                $materialStatement->execute([
                    'class_id' => $classId,
                    'folder_id' => $folderId > 0 ? $folderId : null,
                    'title' => $materialTitle,
                    'description' => $description !== '' ? $description : null,
                    'material_type' => $uploadedMaterial['material_type'],
                    'file_path' => $uploadedMaterial['file_path'],
                    'original_filename' => $uploadedMaterial['original_filename'],
                    'mime_type' => $uploadedMaterial['mime_type'],
                    'external_url' => null,
                    'uploaded_by_user_id' => (int) $currentUser['id'],
                ]);
            }

            foreach ($materialUrls as $index => $materialUrl) {
                $materialType = preg_match('/(youtube\.com|youtu\.be)/i', $materialUrl) ? 'youtube' : 'link';
                $urlHost = parse_url($materialUrl, PHP_URL_HOST);
                $urlHost = is_string($urlHost) && $urlHost !== '' ? preg_replace('/^www\./i', '', $urlHost) : 'Link ' . ($index + 1);
                $materialTitle = $totalMaterialItems === 1 ? $title : $title . ' - ' . $urlHost;
                $materialStatement->execute([
                    'class_id' => $classId,
                    'folder_id' => $folderId > 0 ? $folderId : null,
                    'title' => $materialTitle,
                    'description' => $description !== '' ? $description : null,
                    'material_type' => $materialType,
                    'file_path' => null,
                    'original_filename' => null,
                    'mime_type' => null,
                    'external_url' => $materialUrl,
                    'uploaded_by_user_id' => (int) $currentUser['id'],
                ]);
            }

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=materials&success=material_created');
            exit;
        }

        $tool = 'materials';
    }

    if ($action === 'save_material_folder') {
        $folderName = trim($_POST['folder_name'] ?? '');
        $folderDescription = trim($_POST['folder_description'] ?? '');
        $returnTool = $_POST['return_tool'] ?? 'materials';
        $returnTool = in_array($returnTool, ['materials', 'quizzes', 'assignments', 'tasks'], true) ? $returnTool : 'materials';
        $bannerImage = null;

        if ($folderName === '') {
            $errors[] = 'Topic name is required.';
        }

        if (isset($_FILES['topic_banner_image']) && $_FILES['topic_banner_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['topic_banner_image']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Topic wallpaper could not be uploaded.';
            } elseif (!is_writable($topicUploadDirectory)) {
                $errors[] = 'Topic wallpaper upload folder is not writable.';
            } else {
                $allowedTopicImageTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                $mimeType = mime_content_type($_FILES['topic_banner_image']['tmp_name']);

                if (!isset($allowedTopicImageTypes[$mimeType])) {
                    $errors[] = 'Topic wallpaper must be JPG, PNG, or WEBP.';
                } elseif ($_FILES['topic_banner_image']['size'] > 4 * 1024 * 1024) {
                    $errors[] = 'Topic wallpaper must be 4MB or smaller.';
                } else {
                    // Store topic wallpapers with generated names to avoid overwriting another topic image.
                    $filename = 'topic-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTopicImageTypes[$mimeType];
                    $targetPath = $topicUploadDirectory . '/' . $filename;

                    if (move_uploaded_file($_FILES['topic_banner_image']['tmp_name'], $targetPath)) {
                        $bannerImage = $topicUploadPathPrefix . $filename;
                    } else {
                        $errors[] = 'Topic wallpaper could not be saved.';
                    }
                }
            }
        }

        if (!$errors) {
            $nextSortOrderStatement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM class_material_folders WHERE class_id = :class_id AND deleted_at IS NULL');
            $nextSortOrderStatement->execute(['class_id' => $classId]);
            $nextSortOrder = (int) $nextSortOrderStatement->fetchColumn();

            // Topics are scoped per class so the same topic names can exist in different classes.
            $folderStatement = $pdo->prepare(
                'INSERT INTO class_material_folders (class_id, name, description, banner_image, sort_order, created_by_user_id)
                 VALUES (:class_id, :name, :description, :banner_image, :sort_order, :created_by_user_id)'
            );
            $folderStatement->execute([
                'class_id' => $classId,
                'name' => $folderName,
                'description' => $folderDescription !== '' ? $folderDescription : null,
                'banner_image' => $bannerImage,
                'sort_order' => $nextSortOrder,
                'created_by_user_id' => (int) $currentUser['id'],
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=' . $returnTool . '&success=topic_created');
            exit;
        }

        $tool = $returnTool;
    }

    if ($action === 'update_material_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $folderName = trim($_POST['folder_name'] ?? '');
        $folderDescription = trim($_POST['folder_description'] ?? '');
        $returnTool = $_POST['return_tool'] ?? 'materials';
        $returnTool = in_array($returnTool, ['materials', 'quizzes', 'assignments', 'tasks'], true) ? $returnTool : 'materials';
        $existingBanner = '';
        $bannerImage = null;

        if (!classTopicExists($pdo, $classId, $folderId)) {
            $errors[] = 'Select a valid topic.';
        } else {
            $existingBannerStatement = $pdo->prepare('SELECT banner_image FROM class_material_folders WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
            $existingBannerStatement->execute([
                'id' => $folderId,
                'class_id' => $classId,
            ]);
            $existingBanner = (string) ($existingBannerStatement->fetchColumn() ?: '');
            $bannerImage = $existingBanner !== '' ? $existingBanner : null;
        }

        if ($folderName === '') {
            $errors[] = 'Topic name is required.';
        }

        if (isset($_FILES['topic_banner_image']) && $_FILES['topic_banner_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['topic_banner_image']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Topic wallpaper could not be uploaded.';
            } elseif (!is_writable($topicUploadDirectory)) {
                $errors[] = 'Topic wallpaper upload folder is not writable.';
            } else {
                $allowedTopicImageTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                $mimeType = mime_content_type($_FILES['topic_banner_image']['tmp_name']);

                if (!isset($allowedTopicImageTypes[$mimeType])) {
                    $errors[] = 'Topic wallpaper must be JPG, PNG, or WEBP.';
                } elseif ($_FILES['topic_banner_image']['size'] > 4 * 1024 * 1024) {
                    $errors[] = 'Topic wallpaper must be 4MB or smaller.';
                } else {
                    // Replacing a topic wallpaper removes the previous saved file after the new file lands.
                    $filename = 'topic-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTopicImageTypes[$mimeType];
                    $targetPath = $topicUploadDirectory . '/' . $filename;

                    if (move_uploaded_file($_FILES['topic_banner_image']['tmp_name'], $targetPath)) {
                        $bannerImage = $topicUploadPathPrefix . $filename;
                    } else {
                        $errors[] = 'Topic wallpaper could not be saved.';
                    }
                }
            }
        }

        if (!$errors) {
            // Rename only topics that belong to the current class workspace.
            $folderStatement = $pdo->prepare(
                'UPDATE class_material_folders
                 SET name = :name, description = :description, banner_image = :banner_image
                 WHERE id = :id AND class_id = :class_id'
            );
            $folderStatement->execute([
                'name' => $folderName,
                'description' => $folderDescription !== '' ? $folderDescription : null,
                'banner_image' => $bannerImage,
                'id' => $folderId,
                'class_id' => $classId,
            ]);

            $topicRoute = $returnTool === 'materials' ? '&topic_id=' . $folderId : '';
            header('Location: class_workspace.php?class_id=' . $classId . '&tool=' . $returnTool . $topicRoute . '&success=topic_updated');
            exit;
        }

        $tool = $returnTool;
    }

    if ($action === 'delete_material_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $returnTool = $_POST['return_tool'] ?? 'tasks';
        $returnTool = in_array($returnTool, ['materials', 'quizzes', 'assignments', 'tasks'], true) ? $returnTool : 'tasks';
        $bannerToDelete = '';

        if (!classTopicExists($pdo, $classId, $folderId) || $folderId <= 0) {
            $errors[] = 'Select a valid topic.';
        }

        if (!$errors) {
            // Deleting a topic only unassigns content so materials, quizzes, assignments, and grades stay intact.
            try {
                $pdo->beginTransaction();
                $bannerStatement = $pdo->prepare('SELECT banner_image FROM class_material_folders WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
                $bannerStatement->execute([
                    'id' => $folderId,
                    'class_id' => $classId,
                ]);
                $bannerToDelete = (string) ($bannerStatement->fetchColumn() ?: '');
                $unassignStatements = [
                    'UPDATE class_learning_materials SET folder_id = NULL WHERE folder_id = :folder_id AND class_id = :class_id AND deleted_at IS NULL',
                    'UPDATE class_quizzes SET folder_id = NULL WHERE folder_id = :folder_id AND class_id = :class_id AND deleted_at IS NULL',
                    'UPDATE class_assignments SET folder_id = NULL WHERE folder_id = :folder_id AND class_id = :class_id AND deleted_at IS NULL',
                    'UPDATE class_tasks SET folder_id = NULL WHERE folder_id = :folder_id AND class_id = :class_id AND deleted_at IS NULL',
                ];

                foreach ($unassignStatements as $unassignSql) {
                    $unassignStatement = $pdo->prepare($unassignSql);
                    $unassignStatement->execute([
                        'folder_id' => $folderId,
                        'class_id' => $classId,
                    ]);
                }

                $deleteFolder = $pdo->prepare('UPDATE class_material_folders SET deleted_at = NOW() WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL');
                $deleteFolder->execute([
                    'id' => $folderId,
                    'class_id' => $classId,
                ]);
                $pdo->commit();
                normalizeTopicSortOrder($pdo, $classId);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Topic could not be deleted.';
            }

            if (!$errors) {
                header('Location: class_workspace.php?class_id=' . $classId . '&tool=' . $returnTool . '&success=topic_deleted');
                exit;
            }
        }

        $tool = $returnTool;
    }

    if ($action === 'move_material_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';

        normalizeTopicSortOrder($pdo, $classId);

        $folderStatement = $pdo->prepare('SELECT id, sort_order FROM class_material_folders WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $folderStatement->execute([
            'id' => $folderId,
            'class_id' => $classId,
        ]);
        $folderToMove = $folderStatement->fetch() ?: null;

        if ($folderToMove && in_array($direction, ['up', 'down'], true)) {
            // Swap with the nearest topic in the requested direction for a simple manual order.
            $neighborSql = $direction === 'up'
                ? 'SELECT id, sort_order FROM class_material_folders WHERE class_id = :class_id AND deleted_at IS NULL AND sort_order < :sort_order ORDER BY sort_order DESC, id DESC LIMIT 1'
                : 'SELECT id, sort_order FROM class_material_folders WHERE class_id = :class_id AND deleted_at IS NULL AND sort_order > :sort_order ORDER BY sort_order ASC, id ASC LIMIT 1';
            $neighborStatement = $pdo->prepare($neighborSql);
            $neighborStatement->execute([
                'class_id' => $classId,
                'sort_order' => (int) $folderToMove['sort_order'],
            ]);
            $neighbor = $neighborStatement->fetch() ?: null;

            if ($neighbor) {
                $swapStatement = $pdo->prepare(
                    'UPDATE class_material_folders
                     SET sort_order = CASE
                        WHEN id = :folder_id THEN :neighbor_order
                        WHEN id = :neighbor_id THEN :folder_order
                        ELSE sort_order
                     END
                     WHERE class_id = :class_id AND id IN (:folder_id_filter, :neighbor_id_filter)'
                );
                $swapStatement->execute([
                    'folder_id' => (int) $folderToMove['id'],
                    'neighbor_order' => (int) $neighbor['sort_order'],
                    'neighbor_id' => (int) $neighbor['id'],
                    'folder_order' => (int) $folderToMove['sort_order'],
                    'class_id' => $classId,
                    'folder_id_filter' => (int) $folderToMove['id'],
                    'neighbor_id_filter' => (int) $neighbor['id'],
                ]);
            }
        }

        header('Location: class_workspace.php?class_id=' . $classId . '&tool=tasks&success=topic_reordered');
        exit;
    }

    if ($action === 'move_material_topic') {
        $materialId = (int) ($_POST['material_id'] ?? 0);
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

        if ($materialId <= 0) {
            $errors[] = 'Select a valid learning material.';
        }

        if (!classTopicExists($pdo, $classId, $folderId)) {
            $errors[] = 'Select a valid topic.';
        }

        if (!$errors) {
            // Drag-and-drop only moves materials inside the currently open class workspace.
            $moveStatement = $pdo->prepare(
                'UPDATE class_learning_materials
                 SET folder_id = :folder_id
                 WHERE id = :id AND class_id = :class_id'
            );
            $moveStatement->execute([
                'folder_id' => $folderId > 0 ? $folderId : null,
                'id' => $materialId,
                'class_id' => $classId,
            ]);

            if ($isAjaxRequest) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => $moveStatement->rowCount() > 0,
                    'message' => $moveStatement->rowCount() > 0 ? 'Material moved to topic.' : 'Material was already in that topic.',
                ]);
                exit;
            }

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=materials&success=material_created');
            exit;
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json', true, 422);
            echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
            exit;
        }

        $tool = 'materials';
    }

    if ($action === 'update_material') {
        $materialId = (int) ($_POST['material_id'] ?? 0);
        $title = trim($_POST['material_title'] ?? '');
        $description = trim($_POST['material_description'] ?? '');
        $folderId = (int) ($_POST['material_folder_id'] ?? 0);
        $externalUrl = trim($_POST['material_external_url'] ?? '');
        $editableMaterial = null;

        if ($materialId <= 0) {
            $errors[] = 'Select a valid learning material.';
        } else {
        $editableMaterialStatement = $pdo->prepare('SELECT * FROM class_learning_materials WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
            $editableMaterialStatement->execute([
                'id' => $materialId,
                'class_id' => $classId,
            ]);
            $editableMaterial = $editableMaterialStatement->fetch() ?: null;

            if (!$editableMaterial) {
                $errors[] = 'Select a valid learning material.';
            }
        }

        if ($title === '') {
            $errors[] = 'Material title is required.';
        }

        if (!classTopicExists($pdo, $classId, $folderId)) {
            $errors[] = 'Select a valid topic.';
        }

        if ($editableMaterial && !empty($editableMaterial['external_url']) && $externalUrl === '') {
            $errors[] = 'Material link is required for link resources.';
        }

        if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Enter a valid material link.';
        }

        if (!$errors) {
            // Keep edits scoped to the open class; file uploads keep their existing stored file path.
            $updateStatement = $pdo->prepare(
                'UPDATE class_learning_materials
                 SET folder_id = :folder_id,
                     title = :title,
                     description = :description,
                     external_url = CASE
                        WHEN external_url IS NULL THEN external_url
                        ELSE :external_url
                     END,
                     material_type = CASE
                        WHEN external_url IS NULL THEN material_type
                        WHEN :external_url_for_type REGEXP "(youtube\\.com|youtu\\.be)" THEN "youtube"
                        ELSE "link"
                     END
                 WHERE id = :id AND class_id = :class_id'
            );
            $updateStatement->execute([
                'folder_id' => $folderId > 0 ? $folderId : null,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'external_url' => $externalUrl !== '' ? $externalUrl : null,
                'external_url_for_type' => $externalUrl,
                'id' => $materialId,
                'class_id' => $classId,
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=materials' . ($folderId > 0 ? '&topic_id=' . $folderId : '') . '&success=material_updated');
            exit;
        }

        $tool = 'materials';
    }

    if ($action === 'delete_material') {
        $materialId = (int) ($_POST['material_id'] ?? 0);
        $materialStatement = $pdo->prepare('SELECT file_path FROM class_learning_materials WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $materialStatement->execute([
            'id' => $materialId,
            'class_id' => $classId,
        ]);
        $material = $materialStatement->fetch() ?: null;

        if ($material) {
            // Soft delete only materials that belong to the open class workspace.
            $deleteStatement = $pdo->prepare('UPDATE class_learning_materials SET deleted_at = NOW() WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL');
            $deleteStatement->execute([
                'id' => $materialId,
                'class_id' => $classId,
            ]);
        }

        header('Location: class_workspace.php?class_id=' . $classId . '&tool=materials&success=material_deleted');
        exit;
    }

    if ($action === 'save_quiz') {
        $quizTitle = trim($_POST['quiz_title'] ?? '');
        $quizDescription = trim($_POST['quiz_description'] ?? '');
        $quizTopicId = (int) ($_POST['quiz_topic_id'] ?? 0);
        $timerMinutes = (int) ($_POST['timer_minutes'] ?? 10);
        $questions = $_POST['questions'] ?? [];

        if ($quizTitle === '') {
            $errors[] = 'Quiz title is required.';
        }

        if ($timerMinutes < 1 || $timerMinutes > 240) {
            $errors[] = 'Quiz timer must be from 1 to 240 minutes.';
        }

        if (!classTopicExists($pdo, $classId, $quizTopicId)) {
            $errors[] = 'Select a valid topic.';
        }

        $preparedQuestions = prepareQuizQuestions(is_array($questions) ? $questions : [], $errors);

        if (!$errors) {
            $pdo->beginTransaction();

            // A quiz owns its questions and choices so scoring can be computed later from the saved answer key.
            $quizStatement = $pdo->prepare(
                'INSERT INTO class_quizzes (class_id, folder_id, title, description, timer_minutes, status, created_by_user_id)
                 VALUES (:class_id, :folder_id, :title, :description, :timer_minutes, :status, :created_by_user_id)'
            );
            $quizStatement->execute([
                'class_id' => $classId,
                'folder_id' => $quizTopicId > 0 ? $quizTopicId : null,
                'title' => $quizTitle,
                'description' => $quizDescription !== '' ? $quizDescription : null,
                'timer_minutes' => $timerMinutes,
                'status' => 'Active',
                'created_by_user_id' => (int) $currentUser['id'],
            ]);
            $quizId = (int) $pdo->lastInsertId();

            replaceQuizQuestions($pdo, $quizId, $preparedQuestions);

            $pdo->commit();

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=quizzes&success=quiz_created');
            exit;
        }

        $tool = 'quizzes';
    }

    if ($action === 'update_quiz') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $quizTitle = trim($_POST['quiz_title'] ?? '');
        $quizDescription = trim($_POST['quiz_description'] ?? '');
        $quizTopicId = (int) ($_POST['quiz_topic_id'] ?? 0);
        $timerMinutes = (int) ($_POST['timer_minutes'] ?? 10);
        $quizStatus = trim($_POST['quiz_status'] ?? 'Active');
        $allowedQuizStatuses = ['Active', 'Inactive'];
        $questions = $_POST['questions'] ?? [];

        if ($quizTitle === '') {
            $errors[] = 'Quiz title is required.';
        }

        if ($timerMinutes < 1 || $timerMinutes > 240) {
            $errors[] = 'Quiz timer must be from 1 to 240 minutes.';
        }

        if (!in_array($quizStatus, $allowedQuizStatuses, true)) {
            $errors[] = 'Select a valid quiz status.';
        }

        $quizOwnerStatement = $pdo->prepare('SELECT id FROM class_quizzes WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $quizOwnerStatement->execute([
            'id' => $quizId,
            'class_id' => $classId,
        ]);

        if (!$quizOwnerStatement->fetch()) {
            $errors[] = 'Select a valid quiz.';
        }

        if (!classTopicExists($pdo, $classId, $quizTopicId)) {
            $errors[] = 'Select a valid topic.';
        }

        $preparedQuestions = prepareQuizQuestions(is_array($questions) ? $questions : [], $errors);

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Replacing questions clears old attempts because saved answers point to old choice ids.
                $answerDelete = $pdo->prepare(
                    'UPDATE quiz_attempt_answers
                     INNER JOIN quiz_attempts ON quiz_attempts.id = quiz_attempt_answers.attempt_id
                     SET quiz_attempt_answers.deleted_at = NOW()
                     WHERE quiz_attempts.quiz_id = :quiz_id
                       AND quiz_attempt_answers.deleted_at IS NULL'
                );
                $answerDelete->execute(['quiz_id' => $quizId]);

                $attemptDelete = $pdo->prepare('UPDATE quiz_attempts SET deleted_at = NOW() WHERE quiz_id = :quiz_id AND deleted_at IS NULL');
                $attemptDelete->execute(['quiz_id' => $quizId]);

                $quizUpdate = $pdo->prepare(
                    'UPDATE class_quizzes
                     SET folder_id = :folder_id,
                         title = :title,
                         description = :description,
                         timer_minutes = :timer_minutes,
                         status = :status
                     WHERE id = :id AND class_id = :class_id'
                );
                $quizUpdate->execute([
                    'folder_id' => $quizTopicId > 0 ? $quizTopicId : null,
                    'title' => $quizTitle,
                    'description' => $quizDescription !== '' ? $quizDescription : null,
                    'timer_minutes' => $timerMinutes,
                    'status' => $quizStatus,
                    'id' => $quizId,
                    'class_id' => $classId,
                ]);
                replaceQuizQuestions($pdo, $quizId, $preparedQuestions);
                $pdo->commit();

                header('Location: class_workspace.php?class_id=' . $classId . '&tool=quizzes&success=quiz_updated');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Quiz could not be updated.';
            }
        }

        $tool = 'quizzes';
    }

    if ($action === 'delete_quiz') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $quizStatement = $pdo->prepare('SELECT id FROM class_quizzes WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $quizStatement->execute([
            'id' => $quizId,
            'class_id' => $classId,
        ]);

        if ($quizStatement->fetch()) {
            // Remove dependent rows manually so older local databases without foreign keys stay tidy.
            $answerDelete = $pdo->prepare(
                'UPDATE quiz_attempt_answers
                 INNER JOIN quiz_attempts ON quiz_attempts.id = quiz_attempt_answers.attempt_id
                 SET quiz_attempt_answers.deleted_at = NOW()
                 WHERE quiz_attempts.quiz_id = :quiz_id
                   AND quiz_attempt_answers.deleted_at IS NULL'
            );
            $answerDelete->execute(['quiz_id' => $quizId]);

            $attemptDelete = $pdo->prepare('UPDATE quiz_attempts SET deleted_at = NOW() WHERE quiz_id = :quiz_id AND deleted_at IS NULL');
            $attemptDelete->execute(['quiz_id' => $quizId]);

            $choiceDelete = $pdo->prepare(
                'UPDATE quiz_choices
                 INNER JOIN quiz_questions ON quiz_questions.id = quiz_choices.question_id
                 SET quiz_choices.deleted_at = NOW()
                 WHERE quiz_questions.quiz_id = :quiz_id
                   AND quiz_choices.deleted_at IS NULL'
            );
            $choiceDelete->execute(['quiz_id' => $quizId]);

            $questionDelete = $pdo->prepare('UPDATE quiz_questions SET deleted_at = NOW() WHERE quiz_id = :quiz_id AND deleted_at IS NULL');
            $questionDelete->execute(['quiz_id' => $quizId]);

            $quizDelete = $pdo->prepare('UPDATE class_quizzes SET deleted_at = NOW(), status = "Inactive" WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL');
            $quizDelete->execute([
                'id' => $quizId,
                'class_id' => $classId,
            ]);
        }

        header('Location: class_workspace.php?class_id=' . $classId . '&tool=quizzes&success=quiz_deleted');
        exit;
    }

    if ($action === 'save_assignment') {
        $assignmentTitle = trim($_POST['assignment_title'] ?? '');
        $instructions = trim($_POST['assignment_instructions'] ?? '');
        $dueDate = trim($_POST['due_date'] ?? '');
        $assignmentTopicId = (int) ($_POST['assignment_topic_id'] ?? 0);
        $attachmentPath = null;
        $originalFilename = null;

        if ($assignmentTitle === '') {
            $errors[] = 'Assignment title is required.';
        }

        if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $errors[] = 'Choose a valid due date.';
        }

        if (!classTopicExists($pdo, $classId, $assignmentTopicId)) {
            $errors[] = 'Select a valid topic.';
        }

        $hasAssignmentUpload = isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasAssignmentUpload && !$errors) {
            if ($_FILES['assignment_file']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Assignment attachment could not be uploaded.';
            } elseif (!is_writable($assignmentUploadDirectory)) {
                $errors[] = 'Assignment upload folder is not writable.';
            } else {
                $originalFilename = basename((string) $_FILES['assignment_file']['name']);
                $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'sh'];

                if (in_array($extension, $blockedExtensions, true)) {
                    $errors[] = 'This file type is not allowed for assignments.';
                } else {
                    $safeExtension = $extension !== '' ? $extension : 'bin';
                    $filename = 'assignment-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $safeExtension;
                    $targetPath = $assignmentUploadDirectory . '/' . $filename;

                    if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $targetPath)) {
                        $attachmentPath = $assignmentUploadPathPrefix . $filename;
                    } else {
                        $errors[] = 'Assignment attachment could not be saved.';
                    }
                }
            }
        }

        if (!$errors) {
            // Assignments are class-scoped requirements that learners can submit from their portal.
            $assignmentStatement = $pdo->prepare(
                'INSERT INTO class_assignments
                    (class_id, folder_id, title, instructions, due_date, attachment_path, original_filename, status, created_by_user_id)
                 VALUES
                    (:class_id, :folder_id, :title, :instructions, :due_date, :attachment_path, :original_filename, :status, :created_by_user_id)'
            );
            $assignmentStatement->execute([
                'class_id' => $classId,
                'folder_id' => $assignmentTopicId > 0 ? $assignmentTopicId : null,
                'title' => $assignmentTitle,
                'instructions' => $instructions !== '' ? $instructions : null,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'attachment_path' => $attachmentPath,
                'original_filename' => $originalFilename,
                'status' => 'Active',
                'created_by_user_id' => (int) $currentUser['id'],
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=assignments&success=assignment_created');
            exit;
        }

        $tool = 'assignments';
    }

    if ($action === 'delete_assignment') {
        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $assignmentStatement = $pdo->prepare('SELECT attachment_path FROM class_assignments WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $assignmentStatement->execute([
            'id' => $assignmentId,
            'class_id' => $classId,
        ]);
        $assignment = $assignmentStatement->fetch() ?: null;

        if ($assignment) {
            // Soft delete submissions first so learner history remains recoverable.
            $submissionDelete = $pdo->prepare('UPDATE assignment_submissions SET deleted_at = NOW() WHERE assignment_id = :assignment_id AND deleted_at IS NULL');
            $submissionDelete->execute(['assignment_id' => $assignmentId]);

            $assignmentDelete = $pdo->prepare('UPDATE class_assignments SET deleted_at = NOW(), status = "Inactive" WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL');
            $assignmentDelete->execute([
                'id' => $assignmentId,
                'class_id' => $classId,
            ]);
        }

        header('Location: class_workspace.php?class_id=' . $classId . '&tool=assignments&success=assignment_deleted');
        exit;
    }

    if ($action === 'save_task') {
        $title = trim($_POST['task_title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $taskDate = trim($_POST['task_date'] ?? date('Y-m-d'));
        $taskTopicId = (int) ($_POST['task_topic_id'] ?? 0);
        $returnTool = $_POST['return_tool'] ?? 'tasks';
        $returnTool = in_array($returnTool, ['tasks', 'grades'], true) ? $returnTool : 'tasks';
        $gradeSettings = normalizeGradeItemSettings($_POST, $errors);

        if ($title === '') {
            $errors[] = 'Topic title is required.';
        }

        if ($taskDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) {
            $errors[] = 'Choose a valid topic date.';
        }

        if ($taskTopicId <= 0 || !classTopicExists($pdo, $classId, $taskTopicId)) {
            $errors[] = 'Select a valid class topic.';
        }

        if (!$errors) {
            // Grade items can be grouped under the same reusable class topics as resources.
            $taskColumns = 'class_id, folder_id, teacher_id, task_title, description, task_date, created_by_user_id';
            $taskValues = ':class_id, :folder_id, :teacher_id, :task_title, :description, :task_date, :created_by_user_id';
            $taskParams = [
                'class_id' => $classId,
                'folder_id' => $taskTopicId > 0 ? $taskTopicId : null,
                'teacher_id' => $activeTeacherId > 0 ? $activeTeacherId : null,
                'task_title' => $title,
                'description' => $description !== '' ? $description : null,
                'task_date' => $taskDate,
                'created_by_user_id' => (int) $currentUser['id'],
            ];

            if ($taskGradeSettingColumns['max_score']) {
                $taskColumns .= ', max_score';
                $taskValues .= ', :max_score';
                $taskParams['max_score'] = $gradeSettings['max_score'];
            }

            if ($taskGradeSettingColumns['passing_score']) {
                $taskColumns .= ', passing_score';
                $taskValues .= ', :passing_score';
                $taskParams['passing_score'] = $gradeSettings['passing_score'];
            }

            $taskStatement = $pdo->prepare(
                'INSERT INTO class_tasks (' . $taskColumns . ')
                 VALUES (' . $taskValues . ')'
            );
            $taskStatement->execute($taskParams);
            $newTaskId = (int) $pdo->lastInsertId();

            if ($returnTool === 'grades') {
                header('Location: class_workspace.php?class_id=' . $classId . '&tool=grades&topic_id=' . $taskTopicId . '&task_id=' . $newTaskId . '&success=task_created');
                exit;
            }

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=tasks&success=task_created');
            exit;
        }

        $tool = $returnTool;
    }

    if ($action === 'update_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $title = trim($_POST['task_title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $taskDate = trim($_POST['task_date'] ?? date('Y-m-d'));
        $taskTopicId = (int) ($_POST['task_topic_id'] ?? 0);
        $gradeSettings = normalizeGradeItemSettings($_POST, $errors);

        if ($taskId <= 0) {
            $errors[] = 'Select a valid grade item.';
        }

        if ($title === '') {
            $errors[] = 'Grade title is required.';
        }

        if ($taskDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) {
            $errors[] = 'Choose a valid grade date.';
        }

        if ($taskTopicId <= 0 || !classTopicExists($pdo, $classId, $taskTopicId)) {
            $errors[] = 'Select a valid class topic.';
        }

        if (!$errors) {
            $existingTaskStatement = $pdo->prepare('SELECT id FROM class_tasks WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
            $existingTaskStatement->execute([
                'id' => $taskId,
                'class_id' => $classId,
            ]);

            if (!$existingTaskStatement->fetch()) {
                $errors[] = 'Select a valid grade item for this class.';
            }
        }

        if (!$errors) {
            // Editing a grade item keeps learner scores while syncing their saved grade title.
            $taskSetSql = 'folder_id = :folder_id,
                     task_title = :task_title,
                     description = :description,
                     task_date = :task_date';
            $taskParams = [
                'folder_id' => $taskTopicId,
                'task_title' => $title,
                'description' => $description !== '' ? $description : null,
                'task_date' => $taskDate,
                'id' => $taskId,
                'class_id' => $classId,
            ];

            if ($taskGradeSettingColumns['max_score']) {
                $taskSetSql .= ', max_score = :max_score';
                $taskParams['max_score'] = $gradeSettings['max_score'];
            }

            if ($taskGradeSettingColumns['passing_score']) {
                $taskSetSql .= ', passing_score = :passing_score';
                $taskParams['passing_score'] = $gradeSettings['passing_score'];
            }

            $taskStatement = $pdo->prepare(
                'UPDATE class_tasks
                 SET ' . $taskSetSql . '
                 WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL'
            );
            $taskStatement->execute($taskParams);

            $gradeTitleStatement = $pdo->prepare(
                'UPDATE learner_grades
                 SET grade_title = :grade_title,
                     max_score = :max_score
                 WHERE task_id = :task_id AND class_id = :class_id AND deleted_at IS NULL'
            );
            $gradeTitleStatement->execute([
                'grade_title' => $title,
                'max_score' => $gradeSettings['max_score'],
                'task_id' => $taskId,
                'class_id' => $classId,
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=grades&topic_id=' . $taskTopicId . '&task_id=' . $taskId . '&success=task_updated');
            exit;
        }

        $tool = 'grades';
    }

    if ($action === 'delete_task') {
        // Delete a class topic and its grades from this workspace only.
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $deleteGrades = $pdo->prepare('UPDATE learner_grades SET deleted_at = NOW() WHERE task_id = :task_id AND class_id = :class_id AND deleted_at IS NULL');
        $deleteGrades->execute([
            'task_id' => $taskId,
            'class_id' => $classId,
        ]);

        $deleteTask = $pdo->prepare('UPDATE class_tasks SET deleted_at = NOW() WHERE id = :task_id AND class_id = :class_id AND deleted_at IS NULL');
        $deleteTask->execute([
            'task_id' => $taskId,
            'class_id' => $classId,
        ]);

        header('Location: class_workspace.php?class_id=' . $classId . '&tool=tasks&success=task_deleted');
        exit;
    }

    if ($action === 'save_grades') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $taskStatement = $pdo->prepare('SELECT * FROM class_tasks WHERE id = :id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
        $taskStatement->execute([
            'id' => $taskId,
            'class_id' => $classId,
        ]);
        $task = $taskStatement->fetch();

        if (!$task) {
            $errors[] = 'Choose a valid topic.';
        }

        $taskMaxScore = max(1.0, (float) ($task['max_score'] ?? 100));
        $scores = $_POST['scores'] ?? [];
        $remarks = $_POST['remarks'] ?? [];
        $otherRemarks = $_POST['other_remarks'] ?? [];

        if (!$errors) {
            foreach ($scores as $learnerId => $scoreValue) {
                $scoreValue = trim((string) $scoreValue);

                if ($scoreValue === '') {
                    continue;
                }

                $score = (float) $scoreValue;
                $learnerId = (int) $learnerId;

                if ($score < 1 || $score > $taskMaxScore) {
                    $errors[] = 'Grades must be from 1 to ' . number_format($taskMaxScore, 2) . '.';
                    break;
                }

                $learnerStatement = $pdo->prepare(
                    'SELECT learners.id
                     FROM learners
                     LEFT JOIN course_enrollments ON course_enrollments.learner_id = learners.id
                        AND course_enrollments.deleted_at IS NULL
                     WHERE learners.id = :learner_id
                       AND learners.deleted_at IS NULL
                       AND (
                         learners.class_id = :class_id
                         OR (
                           course_enrollments.course_id = :course_id
                           AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
                         )
                       )
                     LIMIT 1'
                );
                $learnerStatement->execute([
                    'learner_id' => $learnerId,
                    'class_id' => $classId,
                    'course_id' => $courseId,
                ]);

                if (!$learnerStatement->fetch()) {
                    $errors[] = 'One selected learner is not enrolled in this class.';
                    break;
                }

                $existingStatement = $pdo->prepare('SELECT id FROM learner_grades WHERE task_id = :task_id AND learner_id = :learner_id AND deleted_at IS NULL LIMIT 1');
                $existingStatement->execute([
                    'task_id' => $taskId,
                    'learner_id' => $learnerId,
                ]);
                $existingGradeId = (int) $existingStatement->fetchColumn();
                $remark = trim((string) ($remarks[$learnerId] ?? ''));
                $otherRemark = trim((string) ($otherRemarks[$learnerId] ?? ''));

                if ($existingGradeId > 0) {
                    $otherRemarkSetSql = empty($learnerGradeColumns['other_remarks']) ? '' : ', other_remarks = :other_remarks';
                    $gradeStatement = $pdo->prepare(
                        'UPDATE learner_grades
                         SET score = :score,
                             max_score = :max_score,
                             remarks = :remarks,
                             ' . ltrim($otherRemarkSetSql, ', ') . ($otherRemarkSetSql === '' ? '' : ',') . '
                             graded_at = :graded_at,
                             grade_title = :grade_title,
                             created_by_user_id = :created_by_user_id
                         WHERE id = :id'
                    );
                    $gradeParams = [
                        'score' => $score,
                        'max_score' => $taskMaxScore,
                        'remarks' => $remark !== '' ? $remark : null,
                        'graded_at' => date('Y-m-d'),
                        'grade_title' => $task['task_title'],
                        'created_by_user_id' => (int) $currentUser['id'],
                        'id' => $existingGradeId,
                    ];
                    if (!empty($learnerGradeColumns['other_remarks'])) {
                        $gradeParams['other_remarks'] = $otherRemark !== '' ? $otherRemark : null;
                    }
                    $gradeStatement->execute($gradeParams);
                } else {
                    $insertColumns = 'task_id, learner_id, class_id, teacher_id, grade_title, score, max_score, remarks, graded_at, created_by_user_id';
                    $insertValues = ':task_id, :learner_id, :class_id, :teacher_id, :grade_title, :score, :max_score, :remarks, :graded_at, :created_by_user_id';
                    if (!empty($learnerGradeColumns['other_remarks'])) {
                        $insertColumns = 'task_id, learner_id, class_id, teacher_id, grade_title, score, max_score, remarks, other_remarks, graded_at, created_by_user_id';
                        $insertValues = ':task_id, :learner_id, :class_id, :teacher_id, :grade_title, :score, :max_score, :remarks, :other_remarks, :graded_at, :created_by_user_id';
                    }
                    $gradeStatement = $pdo->prepare(
                        'INSERT INTO learner_grades
                            (' . $insertColumns . ')
                         VALUES
                            (' . $insertValues . ')'
                    );
                    $gradeParams = [
                        'task_id' => $taskId,
                        'learner_id' => $learnerId,
                        'class_id' => $classId,
                        'teacher_id' => $activeTeacherId > 0 ? $activeTeacherId : null,
                        'grade_title' => $task['task_title'],
                        'score' => $score,
                        'max_score' => $taskMaxScore,
                        'remarks' => $remark !== '' ? $remark : null,
                        'graded_at' => date('Y-m-d'),
                        'created_by_user_id' => (int) $currentUser['id'],
                    ];
                    if (!empty($learnerGradeColumns['other_remarks'])) {
                        $gradeParams['other_remarks'] = $otherRemark !== '' ? $otherRemark : null;
                    }
                    $gradeStatement->execute($gradeParams);
                }
            }
        }

        if (!$errors) {
            $redirectTopicId = (int) ($task['folder_id'] ?? 0);
            header('Location: class_workspace.php?class_id=' . $classId . '&tool=grades&task_id=' . $taskId . ($redirectTopicId > 0 ? '&topic_id=' . $redirectTopicId : '') . '&success=grades_saved');
            exit;
        }

        $tool = 'grades';
    }
}

$learnerStatement = $pdo->prepare(
    'SELECT DISTINCT learners.*
     FROM learners
     LEFT JOIN course_enrollments ON course_enrollments.learner_id = learners.id
        AND course_enrollments.deleted_at IS NULL
     WHERE learners.deleted_at IS NULL
       AND (
        learners.class_id = :class_id
        OR (
          course_enrollments.course_id = :course_id
          AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
        )
       )
     ORDER BY learners.first_name, learners.last_name'
);
$learnerStatement->execute([
    'class_id' => $classId,
    'course_id' => $courseId,
]);
$learners = $learnerStatement->fetchAll();

// Available learners exclude anyone already connected to any class.
$availableLearnerStatement = $pdo->prepare(
    'SELECT learners.*
     FROM learners
     LEFT JOIN course_enrollments
       ON course_enrollments.learner_id = learners.id
       AND course_enrollments.deleted_at IS NULL
     WHERE learners.class_id IS NULL
       AND learners.deleted_at IS NULL
       AND course_enrollments.id IS NULL
     ORDER BY learners.first_name, learners.last_name'
);
$availableLearnerStatement->execute();
$availableLearners = $availableLearnerStatement->fetchAll();

$availableTeacherStatement = $pdo->prepare(
    'SELECT teachers.*
     FROM teachers
     LEFT JOIN class_teachers
        ON class_teachers.teacher_id = teachers.id
       AND class_teachers.class_id = :class_id
       AND class_teachers.deleted_at IS NULL
     LEFT JOIN classes AS legacy_class
        ON legacy_class.teacher_id = teachers.id
       AND legacy_class.id = :legacy_class_id
       AND legacy_class.deleted_at IS NULL
     WHERE teachers.status = "Active"
       AND teachers.deleted_at IS NULL
       AND class_teachers.id IS NULL
       AND legacy_class.id IS NULL
     ORDER BY teachers.full_name'
);
$availableTeacherStatement->execute([
    'class_id' => $classId,
    'legacy_class_id' => $classId,
]);
$availableTeachers = $availableTeacherStatement->fetchAll();

$pendingEnrollmentStatement = $pdo->prepare(
    'SELECT *
     FROM class_enrollment_requests
     WHERE class_id = :class_id AND status = "Pending" AND deleted_at IS NULL
     ORDER BY requested_at ASC, id ASC'
);
$pendingEnrollmentStatement->execute(['class_id' => $classId]);
$pendingEnrollmentRequests = $pendingEnrollmentStatement->fetchAll();

$tasksStatement = $pdo->prepare(
    'SELECT class_tasks.*,
            class_material_folders.name AS topic_name,
            COUNT(learner_grades.id) AS grade_count,
            COALESCE(AVG(learner_grades.score), 0) AS average_score
     FROM class_tasks
     LEFT JOIN class_material_folders ON class_material_folders.id = class_tasks.folder_id AND class_material_folders.deleted_at IS NULL
     LEFT JOIN learner_grades ON learner_grades.task_id = class_tasks.id AND learner_grades.deleted_at IS NULL
     WHERE class_tasks.class_id = :class_id
       AND class_tasks.deleted_at IS NULL
     GROUP BY class_tasks.id
     ORDER BY class_tasks.task_date DESC, class_tasks.id DESC'
);
$tasksStatement->execute(['class_id' => $classId]);
$tasks = $tasksStatement->fetchAll();

normalizeTopicSortOrder($pdo, $classId);

$materialFoldersStatement = $pdo->prepare(
    'SELECT class_material_folders.*,
            (
                SELECT COUNT(*)
                FROM class_learning_materials
                WHERE class_learning_materials.folder_id = class_material_folders.id
                  AND class_learning_materials.deleted_at IS NULL
            ) AS material_count,
            (
                SELECT COUNT(*)
                FROM class_quizzes
                WHERE class_quizzes.folder_id = class_material_folders.id
                  AND class_quizzes.deleted_at IS NULL
            ) AS quiz_count,
            (
                SELECT COUNT(*)
                FROM class_assignments
                WHERE class_assignments.folder_id = class_material_folders.id
                  AND class_assignments.deleted_at IS NULL
            ) AS assignment_count,
            (
                SELECT COUNT(*)
                FROM class_tasks
                WHERE class_tasks.folder_id = class_material_folders.id
                  AND class_tasks.deleted_at IS NULL
            ) AS grade_item_count
     FROM class_material_folders
     WHERE class_material_folders.class_id = :class_id
       AND class_material_folders.deleted_at IS NULL
     ORDER BY class_material_folders.sort_order ASC, class_material_folders.created_at DESC, class_material_folders.id DESC'
);
$materialFoldersStatement->execute(['class_id' => $classId]);
$materialFolders = $materialFoldersStatement->fetchAll();
$selectedTopic = null;
foreach ($materialFolders as $folder) {
    if ((int) $folder['id'] === $selectedTopicId) {
        $selectedTopic = $folder;
        break;
    }
}

if (!$selectedTopic) {
    $selectedTopicId = 0;
}

$materialsSql = 'SELECT class_learning_materials.*, users.name AS uploader_name, class_material_folders.name AS folder_name
     FROM class_learning_materials
     LEFT JOIN users ON users.id = class_learning_materials.uploaded_by_user_id AND users.deleted_at IS NULL
     LEFT JOIN class_material_folders ON class_material_folders.id = class_learning_materials.folder_id AND class_material_folders.deleted_at IS NULL
     WHERE class_learning_materials.class_id = :class_id
       AND class_learning_materials.deleted_at IS NULL';
$materialsParams = ['class_id' => $classId];

if ($selectedTopicId > 0) {
    $materialsSql .= ' AND class_learning_materials.folder_id = :topic_id';
    $materialsParams['topic_id'] = $selectedTopicId;
} else {
    // The main resources view only shows materials that are not already organized into a topic.
    $materialsSql .= ' AND class_learning_materials.folder_id IS NULL';
}

$materialsSql .= ' ORDER BY class_learning_materials.created_at DESC, class_learning_materials.id DESC';
$materialsStatement = $pdo->prepare($materialsSql);
$materialsStatement->execute($materialsParams);
$materials = $materialsStatement->fetchAll();

$quizzesSql = 'SELECT class_quizzes.*,
            class_material_folders.name AS topic_name,
            COUNT(DISTINCT quiz_questions.id) AS question_count,
            COUNT(DISTINCT quiz_attempts.id) AS attempt_count,
            COALESCE(AVG(quiz_attempts.score), 0) AS average_score
     FROM class_quizzes
     LEFT JOIN class_material_folders ON class_material_folders.id = class_quizzes.folder_id AND class_material_folders.deleted_at IS NULL
     LEFT JOIN quiz_questions ON quiz_questions.quiz_id = class_quizzes.id AND quiz_questions.deleted_at IS NULL
     LEFT JOIN quiz_attempts ON quiz_attempts.quiz_id = class_quizzes.id AND quiz_attempts.deleted_at IS NULL
     WHERE class_quizzes.class_id = :class_id
       AND class_quizzes.deleted_at IS NULL';
$quizParameters = ['class_id' => $classId];

if ($tool === 'quizzes' && $selectedTopicId > 0) {
    // Topic clicks on the quiz page show only quizzes organized inside that topic.
    $quizzesSql .= ' AND class_quizzes.folder_id = :topic_id';
    $quizParameters['topic_id'] = $selectedTopicId;
}

$quizzesSql .= '
     GROUP BY class_quizzes.id
     ORDER BY class_quizzes.created_at DESC, class_quizzes.id DESC';
$quizzesStatement = $pdo->prepare($quizzesSql);
$quizzesStatement->execute($quizParameters);
$quizzes = $quizzesStatement->fetchAll();

$quizQuestionMap = [];
$quizIds = array_map(static fn ($quiz): int => (int) $quiz['id'], $quizzes);

if ($quizIds) {
    $quizQuestionPlaceholders = implode(',', array_fill(0, count($quizIds), '?'));
    $quizQuestionStatement = $pdo->prepare(
        'SELECT quiz_questions.id AS question_id,
                quiz_questions.quiz_id,
                quiz_questions.question_text,
                quiz_questions.position AS question_position,
                quiz_choices.choice_text,
                quiz_choices.is_correct,
                quiz_choices.position AS choice_position
         FROM quiz_questions
         LEFT JOIN quiz_choices ON quiz_choices.question_id = quiz_questions.id AND quiz_choices.deleted_at IS NULL
         WHERE quiz_questions.quiz_id IN (' . $quizQuestionPlaceholders . ')
           AND quiz_questions.deleted_at IS NULL
         ORDER BY quiz_questions.quiz_id, quiz_questions.position, quiz_choices.position'
    );
    $quizQuestionStatement->execute($quizIds);

    foreach ($quizQuestionStatement->fetchAll() as $row) {
        $quizId = (int) $row['quiz_id'];
        $questionId = (int) $row['question_id'];

        if (!isset($quizQuestionMap[$quizId])) {
            $quizQuestionMap[$quizId] = [];
        }

        if (!isset($quizQuestionMap[$quizId][$questionId])) {
            $quizQuestionMap[$quizId][$questionId] = [
                'text' => (string) $row['question_text'],
                'choices' => ['', '', '', ''],
                'correct' => 0,
            ];
        }

        $choiceIndex = max(0, min(3, (int) $row['choice_position'] - 1));
        $quizQuestionMap[$quizId][$questionId]['choices'][$choiceIndex] = (string) ($row['choice_text'] ?? '');

        if ((int) $row['is_correct'] === 1) {
            $quizQuestionMap[$quizId][$questionId]['correct'] = $choiceIndex;
        }
    }

    foreach ($quizQuestionMap as $quizId => $questionRows) {
        // Flatten by quiz id so the front-end can loop over a simple JSON array.
        $quizQuestionMap[$quizId] = array_values($questionRows);
    }
}

$assignmentsSql = 'SELECT class_assignments.*,
            class_material_folders.name AS topic_name,
            COUNT(assignment_submissions.id) AS submission_count
     FROM class_assignments
     LEFT JOIN class_material_folders ON class_material_folders.id = class_assignments.folder_id AND class_material_folders.deleted_at IS NULL
     LEFT JOIN assignment_submissions ON assignment_submissions.assignment_id = class_assignments.id AND assignment_submissions.deleted_at IS NULL
     WHERE class_assignments.class_id = :class_id
       AND class_assignments.deleted_at IS NULL';
$assignmentParameters = ['class_id' => $classId];

if ($tool === 'assignments' && $selectedTopicId > 0) {
    // Topic clicks on the assignments page show only assignments organized inside that topic.
    $assignmentsSql .= ' AND class_assignments.folder_id = :topic_id';
    $assignmentParameters['topic_id'] = $selectedTopicId;
}

$assignmentsSql .= '
     GROUP BY class_assignments.id
     ORDER BY class_assignments.created_at DESC, class_assignments.id DESC';
$assignmentsStatement = $pdo->prepare($assignmentsSql);
$assignmentsStatement->execute($assignmentParameters);
$assignments = $assignmentsStatement->fetchAll();

$selectedGradeTopicId = (int) ($_GET['topic_id'] ?? $_POST['topic_id'] ?? ($materialFolders[0]['id'] ?? 0));
$selectedGradeTopic = null;
foreach ($materialFolders as $folder) {
    if ((int) $folder['id'] === $selectedGradeTopicId) {
        $selectedGradeTopic = $folder;
        break;
    }
}

if (!$selectedGradeTopic) {
    $selectedGradeTopicId = 0;
}

// The grades screen first chooses a saved topic, then shows grade items under that topic.
$gradeTasks = array_values(array_filter($tasks, static function (array $taskRow) use ($selectedGradeTopicId): bool {
    return $selectedGradeTopicId > 0 && (int) ($taskRow['folder_id'] ?? 0) === $selectedGradeTopicId;
}));

$selectedTaskId = (int) ($_GET['task_id'] ?? $_POST['task_id'] ?? ($gradeTasks[0]['id'] ?? 0));
$selectedTask = null;
foreach ($gradeTasks as $taskRow) {
    if ((int) $taskRow['id'] === $selectedTaskId) {
        $selectedTask = $taskRow;
        break;
    }
}

$gradeRows = [];
if ($selectedTask) {
    $gradeStatement = $pdo->prepare('SELECT * FROM learner_grades WHERE task_id = :task_id AND class_id = :class_id AND deleted_at IS NULL');
    $gradeStatement->execute([
        'task_id' => (int) $selectedTask['id'],
        'class_id' => $classId,
    ]);
    foreach ($gradeStatement->fetchAll() as $gradeRow) {
        $gradeRows[(int) $gradeRow['learner_id']] = $gradeRow;
    }
}

$classAverageStatement = $pdo->prepare('SELECT COALESCE(AVG(score), 0) FROM learner_grades WHERE class_id = :class_id AND deleted_at IS NULL');
$classAverageStatement->execute(['class_id' => $classId]);
$classAverage = (float) $classAverageStatement->fetchColumn();
$gradeCountStatement = $pdo->prepare('SELECT COUNT(*) FROM learner_grades WHERE class_id = :class_id AND deleted_at IS NULL');
$gradeCountStatement->execute(['class_id' => $classId]);
$gradeCount = (int) $gradeCountStatement->fetchColumn();
$teacherName = (string) ($primaryTeacher['full_name'] ?? ($class['display_teacher'] ?? ''));
$teacherInitials = $teacherName !== '' ? strtoupper(substr($teacherName, 0, 1)) : 'T';

$successMessages = [
    'enrollment_approved' => 'Enrollment request approved successfully.',
    'enrollment_disapproved' => 'Enrollment request disapproved successfully.',
    'learners_added' => 'Learners added successfully.',
    'learner_credentials_reset' => 'Learner credentials were reset.',
    'learner_removed' => 'Learner deleted from this class.',
    'teachers_added' => 'Teachers added successfully.',
    'teacher_removed' => 'Teacher removed from this class.',
    'topic_created' => 'Topic added successfully.',
    'topic_updated' => 'Topic updated successfully.',
    'topic_deleted' => 'Topic deleted successfully.',
    'topic_reordered' => 'Topic order updated successfully.',
    'folder_created' => 'Topic added successfully.',
    'material_created' => 'Learning material added successfully.',
    'material_updated' => 'Learning material updated successfully.',
    'material_deleted' => 'Learning material deleted successfully.',
    'quiz_created' => 'Quiz added successfully.',
    'quiz_updated' => 'Quiz updated successfully.',
    'quiz_deleted' => 'Quiz deleted successfully.',
    'assignment_created' => 'Assignment added successfully.',
    'assignment_deleted' => 'Assignment deleted successfully.',
    'task_created' => 'Grade added successfully.',
    'task_updated' => 'Grade item updated successfully.',
    'task_deleted' => 'Grade deleted successfully.',
    'grades_saved' => 'Grades saved successfully.',
];
$credentialEmailStatus = $_GET['credential_email'] ?? '';
$notificationEmailStatus = $_GET['notification_email'] ?? '';
$teacherEmailStatus = $_GET['teacher_email'] ?? '';
$mailError = trim((string) ($_GET['mail_error'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e(kiwiSystemBrandName()); ?> | <?php echo e($class['class_name']); ?></title>
  <link rel="icon" type="image/png" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260713-learner-sidebar-teacher-email" rel="stylesheet">
  <?php echo kiwiSystemThemeStyle(); ?>
</head>
<body class="dashboard-page class-workspace-page class-workspace-<?php echo e($tool); ?>">
  <div class="app-layout">
    <aside class="sidebar">
      <a class="sidebar-brand" href="<?php echo $isTeacher ? 'teacher_dashboard.php' : 'classes.php'; ?>">
        <img src="<?php echo e(kiwiSystemLogo()); ?>" alt="<?php echo e(kiwiSystemBrandName()); ?>" class="brand-logo">
        <span>
          <strong><?php echo e($class['class_name']); ?></strong>
          <small>Class Workspace</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <?php if (!empty($classToolAccess['dashboard'])): ?>
          <a class="<?php echo $tool === 'dashboard' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=dashboard"><i class="fa-solid fa-gauge-high"></i> Class Dashboard</a>
        <?php endif; ?>
        <?php if (!empty($classToolAccess['tasks'])): ?>
          <a class="<?php echo $tool === 'tasks' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=tasks"><i class="fa-solid fa-list-check"></i> Topics</a>
        <?php endif; ?>
        <?php if (!empty($classToolAccess['learners'])): ?>
          <a class="<?php echo $tool === 'learners' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=learners"><i class="fa-solid fa-users"></i> Learners</a>
        <?php endif; ?>
        <?php if (!empty($classToolAccess['teachers'])): ?>
          <a class="<?php echo $tool === 'teachers' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=teachers"><i class="fa-solid fa-user-tie"></i> Teachers</a>
        <?php endif; ?>
        <?php if (!empty($classToolAccess['materials'])): ?>
          <a class="<?php echo $tool === 'materials' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=materials"><i class="fa-solid fa-folder-open"></i> Materials</a>
        <?php endif; ?>
        <?php if (!empty($classToolAccess['quizzes'])): ?>
          <a class="<?php echo $tool === 'quizzes' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=quizzes"><i class="fa-solid fa-circle-question"></i> Quizzes</a>
        <?php endif; ?>
        <?php if (!empty($classToolAccess['assignments'])): ?>
          <a class="<?php echo $tool === 'assignments' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=assignments"><i class="fa-solid fa-file-pen"></i> Assignments</a>
        <?php endif; ?>
        <?php if (!empty($classToolAccess['grades'])): ?>
          <a class="<?php echo $tool === 'grades' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=grades"><i class="fa-solid fa-star"></i> Grades</a>
        <?php endif; ?>
        <a href="<?php echo $isTeacher ? 'teacher_dashboard.php' : 'classes.php'; ?>"><i class="fa-solid fa-arrow-left"></i> Back to Classes</a>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Assigned Teachers</p>
        <strong><?php echo e($assignedTeachers ? count($assignedTeachers) . ' teacher' . (count($assignedTeachers) === 1 ? '' : 's') : 'None assigned'); ?></strong>
      </div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Class Workspace</p>
          <h1 class="h3 mb-0"><?php echo e($class['class_name']); ?></h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo e(strtoupper(substr($currentUser['name'], 0, 1))); ?></span>
            <span class="d-none d-sm-inline"><?php echo e($currentUser['name']); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e($currentUser['email']); ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <?php if (isset($successMessages[$success])): ?>
          <div class="alert alert-success" role="alert"><?php echo e($successMessages[$success]); ?></div>
        <?php endif; ?>

        <?php if (in_array($credentialEmailStatus, ['sent', 'failed'], true)): ?>
          <div class="alert <?php echo $credentialEmailStatus === 'sent' ? 'alert-info' : 'alert-warning'; ?>" role="alert">
            <?php echo $credentialEmailStatus === 'sent'
                ? 'The new learner credentials were emailed successfully.'
                : 'The learner password was reset, but the credential email could not be sent.'; ?>
          </div>
        <?php endif; ?>

        <?php if (in_array($notificationEmailStatus, ['sent', 'failed'], true)): ?>
          <div class="alert <?php echo $notificationEmailStatus === 'sent' ? 'alert-info' : 'alert-warning'; ?>" role="alert">
            <?php echo $notificationEmailStatus === 'sent'
                ? 'The enrollment notification email was sent successfully.'
                : 'The enrollment request was updated, but the notification email could not be sent.'; ?>
          </div>
        <?php endif; ?>

        <?php if (in_array($teacherEmailStatus, ['sent', 'failed'], true)): ?>
          <div class="alert <?php echo $teacherEmailStatus === 'sent' ? 'alert-info' : 'alert-warning'; ?>" role="alert">
            <?php echo $teacherEmailStatus === 'sent'
                ? 'The teacher assignment notification email was sent successfully.'
                : 'The teacher was assigned, but the notification email could not be sent.' . ($mailError !== '' ? ' ' . e($mailError) : ''); ?>
          </div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'dashboard'): ?>
          <div class="row g-0 mb-4 class-wallpaper-row">
            <div class="col-12">
              <div class="class-dashboard-banner">
                <?php if (!empty($class['banner_image'])): ?>
                  <button type="button" class="class-image-view-button learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($class['banner_image']); ?>" data-name="<?php echo e($class['class_name']); ?> wallpaper" aria-label="View <?php echo e($class['class_name']); ?> wallpaper">
                    <img src="<?php echo e($class['banner_image']); ?>" alt="<?php echo e($class['class_name']); ?> wallpaper">
                  </button>
                <?php else: ?>
                  <div class="class-dashboard-banner-placeholder">
                    <i class="fa-solid fa-chalkboard-user"></i>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="row g-4">
            <div class="col-md-6">
              <div class="panel-card class-teacher-profile-card h-100">
                <span class="section-kicker">Teachers</span>
                <div class="class-teacher-profile">
                  <div class="class-teacher-photo">
                    <?php if (!empty($primaryTeacher['profile_photo'])): ?>
                      <button type="button" class="learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($primaryTeacher['profile_photo']); ?>" data-name="<?php echo e($teacherName); ?>" aria-label="View <?php echo e($teacherName); ?> profile picture">
                        <img src="<?php echo e($primaryTeacher['profile_photo']); ?>" alt="<?php echo e($teacherName); ?> profile picture">
                      </button>
                    <?php else: ?>
                      <span><?php echo e($teacherInitials); ?></span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <h2 class="h5 mb-1"><?php echo e($teacherName ?: 'No teacher assigned'); ?></h2>
                    <?php if ($assignedTeachers): ?>
                      <p class="text-secondary mb-1"><?php echo count($assignedTeachers); ?> assigned teacher<?php echo count($assignedTeachers) === 1 ? '' : 's'; ?></p>
                      <?php if (count($assignedTeachers) > 1): ?>
                        <p class="mb-0"><?php echo e(implode(', ', array_slice(array_map(static fn ($teacher): string => (string) $teacher['full_name'], $assignedTeachers), 0, 3))); ?></p>
                      <?php elseif (!empty($primaryTeacher['specialization'])): ?>
                        <p class="mb-0"><?php echo e($primaryTeacher['specialization']); ?></p>
                      <?php endif; ?>
                    <?php else: ?>
                      <p class="text-secondary mb-0">Add teachers from the Teachers tab.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="row g-4">
                <div class="col-md-6">
                  <div class="metric-card">
                    <span class="metric-icon bg-success-subtle text-success"><i class="fa-solid fa-users"></i></span>
                    <p>Enrolled Learners</p>
                    <h3><?php echo count($learners); ?></h3>
                    <small class="text-secondary">Visible in this class</small>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="metric-card">
                    <span class="metric-icon bg-primary-subtle text-primary"><i class="fa-solid fa-list-check"></i></span>
                    <p>Topics</p>
                    <h3><?php echo count($tasks); ?></h3>
                    <small class="text-secondary">Created for class</small>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="metric-card">
                    <span class="metric-icon bg-warning-subtle text-warning"><i class="fa-solid fa-star"></i></span>
                    <p>Grades</p>
                    <h3><?php echo $gradeCount; ?></h3>
                    <small class="text-secondary">Saved topic scores</small>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="metric-card">
                    <span class="metric-icon bg-info-subtle text-info"><i class="fa-solid fa-chart-line"></i></span>
                    <p>Average</p>
                    <h3><?php echo number_format($classAverage, 1); ?></h3>
                    <small class="text-secondary">Across all grades</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'learners'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Learners</span>
                <h2 class="h5 mb-0">Enrolled learners</h2>
              </div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-primary"><?php echo count($learners); ?> total</span>
                <?php if ($canManageLearnersEdit): ?>
                  <span class="badge text-bg-warning"><?php echo count($pendingEnrollmentRequests); ?> pending</span>
                <?php endif; ?>
                <?php if ($canManageLearnersAdd): ?>
                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addClassLearnersModal">
                    <i class="fa-solid fa-user-plus me-2"></i>Add Learners
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($canManageLearnersAdd && !empty($class['enrollment_token'])): ?>
              <?php $enrollmentLink = kiwiEnrollmentUrl((string) $class['enrollment_token']); ?>
              <div class="enrollment-link-copy-bar mb-4">
                <div>
                  <span class="section-kicker">Enrollment Link</span>
                  <a href="<?php echo e($enrollmentLink); ?>" target="_blank" rel="noopener"><?php echo e($enrollmentLink); ?></a>
                </div>
                <button type="button" class="btn btn-sm btn-primary copy-enrollment-link" data-link="<?php echo e($enrollmentLink); ?>">
                  <i class="fa-solid fa-copy me-2"></i>Copy Link
                </button>
              </div>
            <?php endif; ?>

            <?php if ($canManageLearnersEdit && $pendingEnrollmentRequests): ?>
              <div class="pending-enrollment-list mb-4">
                <?php foreach ($pendingEnrollmentRequests as $request): ?>
                  <article class="pending-enrollment-card">
                    <div>
                      <span class="section-kicker">Enrollment Link Request</span>
                      <h3><?php echo e(kiwiEnrollmentName($request)); ?></h3>
                      <p>
                        <i class="fa-regular fa-envelope"></i><?php echo e($request['email']); ?>
                        <?php if (!empty($request['contact_number'])): ?>
                          <span><i class="fa-solid fa-phone"></i><?php echo e($request['contact_number']); ?></span>
                        <?php endif; ?>
                      </p>
                      <small>Registered <?php echo e(date('M d, Y g:i A', strtotime((string) $request['requested_at']))); ?></small>
                    </div>
                    <div class="pending-enrollment-actions">
                      <form method="post">
                        <input type="hidden" name="action" value="approve_enrollment_request">
                        <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                        <input type="hidden" name="tool" value="learners">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-success">
                          <i class="fa-solid fa-check me-2"></i>Approve
                        </button>
                      </form>
                      <form method="post" onsubmit="return confirm('Disapprove this registration?');">
                        <input type="hidden" name="action" value="disapprove_enrollment_request">
                        <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                        <input type="hidden" name="tool" value="learners">
                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                          <i class="fa-solid fa-xmark me-2"></i>Disapprove
                        </button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!$learners): ?>
              <div class="empty-state">
                <i class="fa-solid fa-users"></i>
                <p>No learners are enrolled in this class yet.</p>
              </div>
            <?php else: ?>
              <div class="learner-grid class-workspace-learner-grid">
                <?php foreach ($learners as $learner): ?>
                  <?php
                    $fullName = trim($learner['first_name'] . ' ' . ($learner['middle_name'] ?? '') . ' ' . $learner['last_name']);
                    $initials = strtoupper(substr($learner['first_name'], 0, 1) . substr($learner['last_name'], 0, 1));
                  ?>
                  <article class="learner-card">
                    <div class="learner-card-body">
                      <span class="learner-status-pill <?php echo $learner['status'] === 'Completed' ? 'is-completed' : ($learner['status'] === 'On Hold' ? 'is-hold' : 'is-active'); ?>"><?php echo e($learner['status']); ?></span>
                      <?php if ($canManageLearnersEdit): ?>
                        <form method="post" class="learner-card-reset-form credential-reset-form" data-confirm-message="Reset password and email new login credentials to this learner?">
                          <input type="hidden" name="action" value="reset_learner_credentials">
                          <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                          <input type="hidden" name="tool" value="learners">
                          <input type="hidden" name="learner_id" value="<?php echo (int) $learner['id']; ?>">
                          <button type="submit" class="learner-icon-button" aria-label="Reset and resend login credentials" title="Reset and resend credentials">
                            <i class="fa-solid fa-key"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                      <div class="learner-card-media">
                        <?php if (!empty($learner['profile_photo'])): ?>
                          <button type="button" class="learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($learner['profile_photo']); ?>" data-name="<?php echo e($fullName); ?>" aria-label="View <?php echo e($fullName); ?> profile picture">
                            <img src="<?php echo e($learner['profile_photo']); ?>" alt="<?php echo e($fullName); ?>">
                          </button>
                        <?php else: ?>
                          <div class="learner-photo-placeholder"><?php echo e($initials); ?></div>
                        <?php endif; ?>
                      </div>
                      <h3><?php echo e($fullName); ?></h3>
                      <p class="learner-number"><?php echo e($learner['learner_number']); ?></p>
                    </div>
                    <?php if ($canManageLearnersEdit || $canManageLearnersDelete): ?>
                      <footer class="learner-card-footer">
                        <div class="learner-icon-actions">
                          <?php if ($canManageLearnersEdit): ?>
                            <a class="learner-icon-button" href="learners.php?edit=<?php echo (int) $learner['id']; ?>" aria-label="Edit learner">
                              <i class="fa-solid fa-pen"></i>
                            </a>
                          <?php endif; ?>
                          <?php if ($canManageLearnersDelete): ?>
                            <form method="post" onsubmit="return confirm('Delete this learner from this class?');">
                              <input type="hidden" name="action" value="remove_class_learner">
                              <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                              <input type="hidden" name="tool" value="learners">
                              <input type="hidden" name="learner_id" value="<?php echo (int) $learner['id']; ?>">
                              <button type="submit" class="learner-icon-button is-danger" aria-label="Delete learner from class">
                                <i class="fa-solid fa-trash"></i>
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </footer>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'teachers'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Teachers</span>
                <h2 class="h5 mb-0">Assigned teachers</h2>
              </div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-primary"><?php echo count($assignedTeachers); ?> total</span>
                <?php if ($canManageTeachersAdd): ?>
                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addClassTeachersModal">
                    <i class="fa-solid fa-user-plus me-2"></i>Add Teachers
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!$assignedTeachers): ?>
              <div class="empty-state">
                <i class="fa-solid fa-user-tie"></i>
                <p>No teachers are assigned to this class yet.</p>
              </div>
            <?php else: ?>
              <div class="teacher-card-grid">
                <?php foreach ($assignedTeachers as $teacher): ?>
                  <?php $teacherInitial = strtoupper(substr((string) $teacher['full_name'], 0, 1)); ?>
                  <article class="teacher-card">
                    <div class="teacher-card-body">
                      <div class="teacher-card-media">
                        <?php if (!empty($teacher['profile_photo'])): ?>
                          <button type="button" class="learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($teacher['profile_photo']); ?>" data-name="<?php echo e($teacher['full_name']); ?>" aria-label="View <?php echo e($teacher['full_name']); ?> profile picture">
                            <img src="<?php echo e($teacher['profile_photo']); ?>" alt="<?php echo e($teacher['full_name']); ?>">
                          </button>
                        <?php else: ?>
                          <span><?php echo e($teacherInitial); ?></span>
                        <?php endif; ?>
                      </div>
                      <h3><?php echo e($teacher['full_name']); ?></h3>
                      <p class="learner-number"><?php echo e($teacher['teacher_code']); ?></p>
                      <div class="teacher-card-meta">
                        <?php if (!empty($teacher['specialization'])): ?>
                          <span><i class="fa-solid fa-certificate"></i><?php echo e($teacher['specialization']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($teacher['email'])): ?>
                          <span><i class="fa-regular fa-envelope"></i><?php echo e($teacher['email']); ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php if ($canManageTeachersDelete): ?>
                      <footer class="teacher-card-footer">
                        <form method="post" onsubmit="return confirm('Remove this teacher from this class?');">
                          <input type="hidden" name="action" value="remove_class_teacher">
                          <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                          <input type="hidden" name="tool" value="teachers">
                          <input type="hidden" name="teacher_id" value="<?php echo (int) $teacher['id']; ?>">
                          <button type="submit" class="learner-icon-button is-danger" aria-label="Remove teacher from class">
                            <i class="fa-solid fa-user-minus"></i>
                          </button>
                        </form>
                      </footer>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'materials'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
	              <div>
	                <span class="section-kicker">Learning Materials</span>
	                <h2 class="h5 mb-0"><?php echo $selectedTopic ? e($selectedTopic['name']) . ' resources' : 'Unassigned resources'; ?></h2>
              </div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($canManageMaterialsAdd): ?>
                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#materialModal">
                    <i class="fa-solid fa-plus me-2"></i>Add Material
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!$materialFolders && !$materials): ?>
              <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <p>No topics or learning materials have been added yet.</p>
              </div>
            <?php endif; ?>

	            <?php if ($materialFolders): ?>
	              <div class="material-folder-grid mb-4">
	                <?php foreach ($materialFolders as $folder): ?>
	                  <?php if ($selectedTopicId > 0 && $selectedTopicId !== (int) $folder['id']) { continue; } ?>
	                  <?php $topicItemCount = (int) $folder['material_count'] + (int) $folder['quiz_count'] + (int) $folder['assignment_count']; ?>
	                  <article class="material-folder-card material-topic-dropzone <?php echo $selectedTopicId === (int) $folder['id'] ? 'is-active' : ''; ?>" data-topic-id="<?php echo (int) $folder['id']; ?>">
	                    <a class="material-folder-link" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=materials&topic_id=<?php echo (int) $folder['id']; ?>">
                      <div class="material-folder-icon">
                        <i class="fa-solid fa-folder"></i>
                      </div>
                      <div>
                        <h3><?php echo e($folder['name']); ?></h3>
                        <?php if (!empty($folder['description'])): ?>
                          <p><?php echo e($folder['description']); ?></p>
                        <?php endif; ?>
                        <span><?php echo $topicItemCount; ?> items</span>
                      </div>
                    </a>
	                    <?php if ($selectedTopicId === (int) $folder['id']): ?>
	                      <a class="btn btn-sm btn-outline-secondary topic-back-button" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=materials">
	                        <i class="fa-solid fa-arrow-left me-1"></i>Back
	                      </a>
	                    <?php endif; ?>
	                  </article>
	                <?php endforeach; ?>
	              </div>
	            <?php endif; ?>

            <?php if ($materials): ?>
              <div class="material-grid">
                <?php foreach ($materials as $material): ?>
                  <?php
                    $materialType = (string) $material['material_type'];
                    $materialPath = (string) ($material['file_path'] ?? '');
                    $materialUrl = (string) ($material['external_url'] ?? '');
                    $openUrl = $materialPath !== '' ? $materialPath : $materialUrl;
                  ?>
                  <article class="material-card material-draggable-card" draggable="<?php echo $canManageMaterialsEdit ? 'true' : 'false'; ?>" data-material-id="<?php echo (int) $material['id']; ?>">
                    <div class="material-preview">
                      <?php if ($materialType === 'image' && $materialPath !== ''): ?>
                        <button type="button" class="class-image-view-button learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($materialPath); ?>" data-name="<?php echo e($material['title']); ?>" aria-label="View <?php echo e($material['title']); ?>">
                          <img src="<?php echo e($materialPath); ?>" alt="<?php echo e($material['title']); ?>">
                        </button>
                      <?php elseif ($materialType === 'video' && $materialPath !== ''): ?>
                        <video controls preload="metadata">
                          <source src="<?php echo e($materialPath); ?>" type="<?php echo e((string) ($material['mime_type'] ?? 'video/mp4')); ?>">
                        </video>
                      <?php else: ?>
                        <div class="material-icon">
                          <i class="<?php echo e(materialIcon($materialType)); ?>"></i>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="material-card-body">
                      <div class="d-flex align-items-start justify-content-between gap-2">
	                        <div>
	                          <span class="material-type"><?php echo e(strtoupper($materialType)); ?></span>
	                          <h3><?php echo e($material['title']); ?></h3>
	                        </div>
	                        <div class="d-flex align-items-center gap-2">
                            <?php if ($canManageMaterialsEdit): ?>
	                            <button type="button" class="learner-icon-button edit-material-button" data-bs-toggle="modal" data-bs-target="#editMaterialModal" data-material-id="<?php echo (int) $material['id']; ?>" data-title="<?php echo e($material['title']); ?>" data-description="<?php echo e((string) ($material['description'] ?? '')); ?>" data-topic-id="<?php echo (int) ($material['folder_id'] ?? 0); ?>" data-external-url="<?php echo e((string) ($material['external_url'] ?? '')); ?>" aria-label="Edit material">
	                              <i class="fa-solid fa-pen"></i>
	                            </button>
                            <?php endif; ?>
                            <?php if ($canManageMaterialsDelete): ?>
	                            <form method="post" onsubmit="return confirm('Delete this learning material?');">
	                              <input type="hidden" name="action" value="delete_material">
	                              <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
	                              <input type="hidden" name="tool" value="materials">
	                              <input type="hidden" name="material_id" value="<?php echo (int) $material['id']; ?>">
	                              <button type="submit" class="learner-icon-button is-danger" aria-label="Delete material">
	                                <i class="fa-solid fa-trash"></i>
	                              </button>
	                            </form>
                            <?php endif; ?>
	                        </div>
	                      </div>
                      <?php if (!empty($material['description'])): ?>
                        <p><?php echo e($material['description']); ?></p>
                      <?php endif; ?>
	                      <div class="material-meta">
                        <?php if (!empty($material['folder_name'])): ?>
                          <span><i class="fa-solid fa-folder"></i><?php echo e($material['folder_name']); ?></span>
                        <?php endif; ?>
                        <span><i class="fa-regular fa-calendar"></i><?php echo e(date('M d, Y', strtotime($material['created_at']))); ?></span>
                        <?php if (!empty($material['uploader_name'])): ?>
                          <span><i class="fa-regular fa-user"></i><?php echo e($material['uploader_name']); ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if ($openUrl !== ''): ?>
                        <a class="btn btn-sm btn-outline-primary mt-3" href="<?php echo e($openUrl); ?>" target="_blank" rel="noopener">
                          <i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Open
                        </a>
                      <?php endif; ?>
                    </div>
	                  </article>
	                <?php endforeach; ?>
	              </div>
            <?php elseif ($selectedTopic): ?>
              <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <p>No learning materials are inside this topic yet.</p>
              </div>
            <?php elseif ($materialFolders): ?>
              <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <p>No unassigned learning materials. Click a topic to view organized resources.</p>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'quizzes'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
	              <div>
	                <span class="section-kicker">Quizzes</span>
	                <h2 class="h5 mb-0"><?php echo $selectedTopic ? e($selectedTopic['name']) . ' quizzes' : 'Multiple choice quizzes'; ?></h2>
	              </div>
	              <div class="d-flex flex-wrap align-items-center gap-2">
                  <?php if ($canManageQuizzesAdd): ?>
	                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#quizModal">
	                    <i class="fa-solid fa-plus me-2"></i>Add Quiz
	                  </button>
                  <?php endif; ?>
              </div>
            </div>

            <?php if ($materialFolders): ?>
              <div class="material-folder-grid mb-4">
                <?php foreach ($materialFolders as $folder): ?>
                  <?php if ($selectedTopicId > 0 && $selectedTopicId !== (int) $folder['id']) { continue; } ?>
                  <article class="material-folder-card <?php echo $selectedTopicId === (int) $folder['id'] ? 'is-active' : ''; ?>">
                    <a class="material-folder-link" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=quizzes&topic_id=<?php echo (int) $folder['id']; ?>">
                      <div class="material-folder-icon">
                        <i class="fa-solid fa-folder"></i>
                      </div>
                      <div>
                        <h3><?php echo e($folder['name']); ?></h3>
                        <?php if (!empty($folder['description'])): ?>
                          <p><?php echo e($folder['description']); ?></p>
                        <?php endif; ?>
                        <span><?php echo (int) $folder['quiz_count']; ?> quizzes</span>
                      </div>
                    </a>
                    <?php if ($selectedTopicId === (int) $folder['id']): ?>
                      <a class="btn btn-sm btn-outline-secondary topic-back-button" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=quizzes">
                        <i class="fa-solid fa-arrow-left me-1"></i>Back
                      </a>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!$quizzes): ?>
              <div class="empty-state">
                <i class="fa-solid fa-circle-question"></i>
                <p><?php echo $selectedTopic ? 'No quizzes are inside this topic yet.' : 'No quizzes have been created for this class yet.'; ?></p>
              </div>
            <?php else: ?>
              <div class="quiz-card-grid">
                <?php foreach ($quizzes as $quiz): ?>
                  <?php $quizQuestionsJson = json_encode($quizQuestionMap[(int) $quiz['id']] ?? [], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG); ?>
                  <article class="quiz-card">
                    <div class="quiz-card-icon">
                      <i class="fa-solid fa-circle-question"></i>
                    </div>
                    <div class="quiz-card-body">
                      <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                          <span class="material-type"><?php echo (int) $quiz['timer_minutes']; ?> MINUTES</span>
                          <h3><?php echo e($quiz['title']); ?></h3>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                          <?php if ($canManageQuizzesEdit): ?>
                            <button type="button" class="learner-icon-button edit-quiz-button" data-bs-toggle="modal" data-bs-target="#editQuizModal" data-quiz-id="<?php echo (int) $quiz['id']; ?>" data-title="<?php echo e($quiz['title']); ?>" data-description="<?php echo e((string) ($quiz['description'] ?? '')); ?>" data-topic-id="<?php echo (int) ($quiz['folder_id'] ?? 0); ?>" data-timer-minutes="<?php echo (int) $quiz['timer_minutes']; ?>" data-status="<?php echo e((string) ($quiz['status'] ?? 'Active')); ?>" data-questions="<?php echo e((string) $quizQuestionsJson); ?>" aria-label="Edit quiz">
                              <i class="fa-solid fa-pen"></i>
                            </button>
                          <?php endif; ?>
                          <?php if ($canManageQuizzesDelete): ?>
                            <form method="post" onsubmit="return confirm('Delete this quiz and all attempts?');">
                              <input type="hidden" name="action" value="delete_quiz">
                              <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                              <input type="hidden" name="tool" value="quizzes">
                              <input type="hidden" name="quiz_id" value="<?php echo (int) $quiz['id']; ?>">
                              <button type="submit" class="learner-icon-button is-danger" aria-label="Delete quiz">
                                <i class="fa-solid fa-trash"></i>
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </div>
	                      <?php if (!empty($quiz['description'])): ?>
	                        <p><?php echo e($quiz['description']); ?></p>
	                      <?php endif; ?>
	                      <div class="quiz-stats">
	                        <?php if (!empty($quiz['topic_name'])): ?>
	                          <span><i class="fa-solid fa-folder me-1"></i><?php echo e($quiz['topic_name']); ?></span>
	                        <?php endif; ?>
	                        <span><strong><?php echo (int) $quiz['question_count']; ?></strong> questions</span>
	                        <span><strong><?php echo (int) $quiz['attempt_count']; ?></strong> attempts</span>
	                        <span><strong><?php echo number_format((float) $quiz['average_score'], 1); ?></strong> avg</span>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'assignments'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
	              <div>
	                <span class="section-kicker">Assignments</span>
	                <h2 class="h5 mb-0"><?php echo $selectedTopic ? e($selectedTopic['name']) . ' assignments' : 'Learner work requirements'; ?></h2>
	              </div>
	              <div class="d-flex flex-wrap align-items-center gap-2">
                  <?php if ($canManageAssignmentsAdd): ?>
	                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignmentModal">
	                    <i class="fa-solid fa-plus me-2"></i>Add Assignment
	                  </button>
                  <?php endif; ?>
              </div>
            </div>

            <?php if ($materialFolders): ?>
              <div class="material-folder-grid mb-4">
                <?php foreach ($materialFolders as $folder): ?>
                  <?php if ($selectedTopicId > 0 && $selectedTopicId !== (int) $folder['id']) { continue; } ?>
                  <article class="material-folder-card <?php echo $selectedTopicId === (int) $folder['id'] ? 'is-active' : ''; ?>">
                    <a class="material-folder-link" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=assignments&topic_id=<?php echo (int) $folder['id']; ?>">
                      <div class="material-folder-icon">
                        <i class="fa-solid fa-folder"></i>
                      </div>
                      <div>
                        <h3><?php echo e($folder['name']); ?></h3>
                        <?php if (!empty($folder['description'])): ?>
                          <p><?php echo e($folder['description']); ?></p>
                        <?php endif; ?>
                        <span><?php echo (int) $folder['assignment_count']; ?> assignments</span>
                      </div>
                    </a>
                    <?php if ($selectedTopicId === (int) $folder['id']): ?>
                      <a class="btn btn-sm btn-outline-secondary topic-back-button" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=assignments">
                        <i class="fa-solid fa-arrow-left me-1"></i>Back
                      </a>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!$assignments): ?>
              <div class="empty-state">
                <i class="fa-solid fa-file-pen"></i>
                <p><?php echo $selectedTopic ? 'No assignments are inside this topic yet.' : 'No assignments have been created for this class yet.'; ?></p>
              </div>
            <?php else: ?>
              <div class="quiz-card-grid">
                <?php foreach ($assignments as $assignment): ?>
                  <article class="quiz-card assignment-card">
                    <div class="quiz-card-icon">
                      <i class="fa-solid fa-file-pen"></i>
                    </div>
                    <div class="quiz-card-body">
                      <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                          <span class="material-type">
                            <?php echo !empty($assignment['due_date']) ? 'DUE ' . e(date('M d, Y', strtotime($assignment['due_date']))) : 'NO DUE DATE'; ?>
                          </span>
                          <h3><?php echo e($assignment['title']); ?></h3>
                        </div>
                        <?php if ($canManageAssignmentsDelete): ?>
                          <form method="post" onsubmit="return confirm('Delete this assignment and its submissions?');">
                            <input type="hidden" name="action" value="delete_assignment">
                            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                            <input type="hidden" name="tool" value="assignments">
                            <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment['id']; ?>">
                            <button type="submit" class="learner-icon-button is-danger" aria-label="Delete assignment">
                              <i class="fa-solid fa-trash"></i>
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
	                      <?php if (!empty($assignment['instructions'])): ?>
	                        <p><?php echo e($assignment['instructions']); ?></p>
	                      <?php endif; ?>
	                      <div class="quiz-stats">
	                        <?php if (!empty($assignment['topic_name'])): ?>
	                          <span><i class="fa-solid fa-folder me-1"></i><?php echo e($assignment['topic_name']); ?></span>
	                        <?php endif; ?>
	                        <span><strong><?php echo (int) $assignment['submission_count']; ?></strong> submissions</span>
                        <?php if (!empty($assignment['attachment_path'])): ?>
                          <span><i class="fa-solid fa-paperclip me-1"></i> Attachment</span>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($assignment['attachment_path'])): ?>
                        <a class="btn btn-sm btn-outline-primary mt-3" href="<?php echo e($assignment['attachment_path']); ?>" target="_blank" rel="noopener">
                          <i class="fa-solid fa-download me-2"></i>Open Attachment
                        </a>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'tasks'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Topics</span>
                <h2 class="h5 mb-0">Class topic library</h2>
              </div>
              <?php if ($canManageTopicsAdd): ?>
                <button type="button" class="btn btn-sm btn-outline-primary add-topic-button" data-bs-toggle="modal" data-bs-target="#materialFolderModal" data-return-tool="tasks">
                  <i class="fa-solid fa-plus me-2"></i>Add Topic
                </button>
              <?php endif; ?>
            </div>
            <?php if (!$materialFolders): ?>
              <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <p>No class topics yet.</p>
              </div>
            <?php else: ?>
              <div class="input-group mb-4">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" class="form-control" id="topicSearchInput" placeholder="Search topics">
              </div>
              <div class="class-card-grid topic-card-grid mb-5">
                <?php foreach ($materialFolders as $topicIndex => $folder): ?>
                  <?php $topicSearchText = strtolower(trim($folder['name'] . ' ' . ($folder['description'] ?? ''))); ?>
                  <article class="class-card topic-library-card topic-search-card" data-topic-search="<?php echo e($topicSearchText); ?>">
                    <a class="class-card-open-link topic-library-link" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=materials&topic_id=<?php echo (int) $folder['id']; ?>" aria-label="Open <?php echo e($folder['name']); ?> topic">
                      <div class="class-wallpaper topic-card-banner">
                        <?php if (!empty($folder['banner_image'])): ?>
                          <img src="<?php echo e($folder['banner_image']); ?>" alt="<?php echo e($folder['name']); ?> wallpaper">
                        <?php else: ?>
                          <div class="class-wallpaper-placeholder topic-wallpaper-placeholder">
                            <i class="fa-solid fa-folder-open"></i>
                          </div>
                        <?php endif; ?>
                      </div>
                      <div class="class-card-body topic-card-body">
                        <h3><?php echo e($folder['name']); ?></h3>
                        <p class="class-teacher"><i class="fa-solid fa-folder"></i>Learning topic</p>
                        <?php if (!empty($folder['description'])): ?>
                          <p class="class-description"><?php echo e($folder['description']); ?></p>
                        <?php endif; ?>
                      </div>
                    </a>
                    <footer class="class-card-footer topic-card-actions">
                      <?php if ($canManageTopicsEdit): ?>
                        <div class="class-order-actions" aria-label="Reorder <?php echo e($folder['name']); ?> topic">
                          <form method="post">
                            <input type="hidden" name="action" value="move_material_folder">
                            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                            <input type="hidden" name="folder_id" value="<?php echo (int) $folder['id']; ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="learner-icon-button topic-order-button" <?php echo $topicIndex === 0 ? 'disabled' : ''; ?> aria-label="Move topic up">
                              <i class="fa-solid fa-arrow-up"></i>
                            </button>
                          </form>
                          <form method="post">
                            <input type="hidden" name="action" value="move_material_folder">
                            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                            <input type="hidden" name="folder_id" value="<?php echo (int) $folder['id']; ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="learner-icon-button topic-order-button" <?php echo $topicIndex === count($materialFolders) - 1 ? 'disabled' : ''; ?> aria-label="Move topic down">
                              <i class="fa-solid fa-arrow-down"></i>
                            </button>
                          </form>
                        </div>
                      <?php endif; ?>
                      <a class="btn btn-sm btn-primary" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=materials&topic_id=<?php echo (int) $folder['id']; ?>">
                        <i class="fa-solid fa-folder-open me-2"></i>Open Topic
                      </a>
                      <?php if ($canManageTopicsEdit): ?>
                        <button type="button" class="learner-icon-button edit-topic-button" data-bs-toggle="modal" data-bs-target="#editTopicModal" data-topic-id="<?php echo (int) $folder['id']; ?>" data-name="<?php echo e($folder['name']); ?>" data-description="<?php echo e((string) ($folder['description'] ?? '')); ?>" data-banner-image="<?php echo e((string) ($folder['banner_image'] ?? '')); ?>" data-return-tool="tasks" aria-label="Edit topic">
                          <i class="fa-solid fa-pen"></i>
                        </button>
                      <?php endif; ?>
                      <?php if ($canManageTopicsDelete): ?>
                        <form method="post" onsubmit="return confirm('Delete this topic? Linked records will be unassigned only.');">
                          <input type="hidden" name="action" value="delete_material_folder">
                          <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                          <input type="hidden" name="tool" value="tasks">
                          <input type="hidden" name="return_tool" value="tasks">
                          <input type="hidden" name="folder_id" value="<?php echo (int) $folder['id']; ?>">
                          <button type="submit" class="learner-icon-button is-danger" aria-label="Delete topic">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </footer>
                  </article>
                <?php endforeach; ?>
              </div>
              <div class="empty-state d-none" id="topicSearchNoResults">
                <i class="fa-solid fa-magnifying-glass"></i>
                <p>No topics match your search.</p>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'grades'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Grades</span>
                <h2 class="h5 mb-0">Manage topic grades</h2>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <?php if ($materialFolders): ?>
                  <?php if ($canManageGradesAdd): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal">
                      <i class="fa-solid fa-plus me-2"></i>Add Grade Item
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
                <a href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=tasks" class="btn btn-sm btn-outline-primary">Manage Topics</a>
              </div>
            </div>

            <?php if (!$materialFolders): ?>
              <div class="empty-state">
                <i class="fa-solid fa-list-check"></i>
                <p>Add a saved topic first before encoding grades.</p>
              </div>
            <?php else: ?>
              <form method="get" class="module-form mb-4">
                <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                <input type="hidden" name="tool" value="grades">
                <div class="row g-3 align-items-end">
                  <div class="col-md-5">
                    <label class="form-label" for="grade_topic_id_filter">Topic</label>
                    <select class="form-select" id="grade_topic_id_filter" name="topic_id" onchange="this.form.submit()">
                      <?php foreach ($materialFolders as $folder): ?>
                        <option value="<?php echo (int) $folder['id']; ?>" <?php echo (int) $folder['id'] === $selectedGradeTopicId ? 'selected' : ''; ?>>
                          <?php echo e($folder['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="task_id_filter">Grade item</label>
                    <select class="form-select" id="task_id_filter" name="task_id" <?php echo !$gradeTasks ? 'disabled' : ''; ?>>
                      <?php if (!$gradeTasks): ?>
                        <option value="">No grade items in this topic</option>
                      <?php endif; ?>
                      <?php foreach ($gradeTasks as $taskRow): ?>
                        <option value="<?php echo (int) $taskRow['id']; ?>" data-topic-id="<?php echo (int) ($taskRow['folder_id'] ?? 0); ?>" data-title="<?php echo e((string) $taskRow['task_title']); ?>" data-description="<?php echo e((string) ($taskRow['description'] ?? '')); ?>" data-task-date="<?php echo e((string) $taskRow['task_date']); ?>" data-max-score="<?php echo e((string) ($taskRow['max_score'] ?? 100)); ?>" data-passing-score="<?php echo e((string) ($taskRow['passing_score'] ?? '')); ?>" <?php echo $selectedTask && (int) $selectedTask['id'] === (int) $taskRow['id'] ? 'selected' : ''; ?>>
                          <?php echo e($taskRow['task_title']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-1">
                    <label class="form-label d-none d-md-block">&nbsp;</label>
                    <button type="button" class="learner-icon-button w-100 edit-task-button" data-bs-toggle="modal" data-bs-target="#editTaskModal" <?php echo (!$selectedTask || !$canManageGradesEdit) ? 'disabled' : ''; ?> aria-label="Edit selected grade item">
                      <i class="fa-solid fa-pen"></i>
                    </button>
                  </div>
                  <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100" <?php echo !$gradeTasks ? 'disabled' : ''; ?>>Open</button>
                  </div>
                </div>
              </form>

              <?php if (!$gradeTasks): ?>
                <div class="empty-state">
                  <i class="fa-solid fa-star-half-stroke"></i>
                  <p>No grade items are saved under this topic yet. Click Add Grade Item to start encoding grades for this topic.</p>
                </div>
              <?php else: ?>
              <?php
                $selectedTaskMaxScore = max(1.0, (float) ($selectedTask['max_score'] ?? 100));
                $selectedTaskPassingScore = isset($selectedTask['passing_score']) && $selectedTask['passing_score'] !== null ? (float) $selectedTask['passing_score'] : null;
              ?>
              <form method="post" class="module-form">
                <input type="hidden" name="action" value="save_grades">
                <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                <input type="hidden" name="tool" value="grades">
                <input type="hidden" name="topic_id" value="<?php echo $selectedGradeTopicId; ?>">
                <input type="hidden" name="task_id" value="<?php echo $selectedTask ? (int) $selectedTask['id'] : 0; ?>">
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Learner</th>
                        <th>Grade</th>
                        <th>Status</th>
                        <th>Other remarks</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($selectedTask): ?>
                        <tr>
                          <td colspan="4" class="text-secondary small">
                            Grade range is 1-<?php echo e(number_format($selectedTaskMaxScore, 2)); ?><?php echo $selectedTaskPassingScore !== null ? ' · Passing grade: ' . e(number_format($selectedTaskPassingScore, 2)) : ''; ?>
                          </td>
                        </tr>
                      <?php endif; ?>
                      <?php if (!$learners): ?>
                        <tr><td colspan="4" class="text-center text-secondary py-5">No enrolled learners to grade.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($learners as $learner): ?>
                        <?php
                          $grade = $gradeRows[(int) $learner['id']] ?? null;
                          $gradeStatus = trim((string) ($grade['remarks'] ?? ''));
                          $statusClass = strcasecmp($gradeStatus, 'Pass') === 0 ? 'text-bg-success' : (strcasecmp($gradeStatus, 'Failed') === 0 ? 'text-bg-danger' : 'text-bg-secondary');
                        ?>
                        <tr>
                          <td>
                            <strong><?php echo e(trim($learner['first_name'] . ' ' . $learner['last_name'])); ?></strong><br>
                            <span class="text-secondary small"><?php echo e($learner['learner_number']); ?></span>
                          </td>
                          <td style="max-width: 160px;">
                            <input type="number" class="form-control grade-score-input" name="scores[<?php echo (int) $learner['id']; ?>]" min="1" max="<?php echo e((string) $selectedTaskMaxScore); ?>" step="0.01" value="<?php echo $grade ? e((string) $grade['score']) : ''; ?>" placeholder="1-<?php echo e(number_format($selectedTaskMaxScore, 0)); ?>" data-passing-score="<?php echo $selectedTaskPassingScore !== null ? e((string) $selectedTaskPassingScore) : ''; ?>" <?php echo !$canManageGradesEdit ? 'disabled' : ''; ?>>
                            <input type="hidden" class="grade-result-input" name="remarks[<?php echo (int) $learner['id']; ?>]" value="<?php echo e($gradeStatus); ?>">
                          </td>
                          <td>
                            <span class="badge <?php echo e($statusClass); ?> grade-status-badge"><?php echo $gradeStatus !== '' ? e($gradeStatus) : 'No result'; ?></span>
                          </td>
                          <td>
                            <input type="text" class="form-control grade-other-remarks-input" name="other_remarks[<?php echo (int) $learner['id']; ?>]" value="<?php echo $grade ? e((string) ($grade['other_remarks'] ?? '')) : ''; ?>" placeholder="Optional" data-grade-id="<?php echo $grade ? (int) $grade['id'] : 0; ?>" data-task-id="<?php echo $selectedTask ? (int) $selectedTask['id'] : 0; ?>" data-learner-id="<?php echo (int) $learner['id']; ?>" <?php echo !$canManageGradesEdit ? 'disabled' : ''; ?>>
                            <div class="form-text grade-autosave-status" aria-live="polite"></div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <button type="submit" class="btn btn-primary" <?php echo (!$learners || !$selectedTask || !$canManageGradesEdit) ? 'disabled' : ''; ?>>
                  <i class="fa-solid fa-floppy-disk me-2"></i>Save Grades
                </button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <div class="modal fade" id="addClassLearnersModal" tabindex="-1" aria-labelledby="addClassLearnersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable class-learner-picker-modal">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Add Learners</span>
            <h2 class="modal-title h5" id="addClassLearnersModalLabel">Select learners for <?php echo e($class['class_name']); ?></h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="add_class_learners">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="learners">
            <div class="row g-3 mb-4">
              <div class="col-12">
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                  <input type="search" class="form-control" id="classLearnerSearch" placeholder="Search learner name, number, email, or phone">
                </div>
              </div>
            </div>

            <?php if (!$availableLearners): ?>
              <div class="empty-state">
                <i class="fa-solid fa-circle-check"></i>
                <p>All learners are already added to this class.</p>
              </div>
            <?php else: ?>
              <div class="class-learner-picker-grid" id="classLearnerPickerGrid">
                <?php foreach ($availableLearners as $availableLearner): ?>
                  <?php
                    $availableName = trim($availableLearner['first_name'] . ' ' . $availableLearner['last_name']);
                    $availableInitials = strtoupper(substr($availableLearner['first_name'], 0, 1) . substr($availableLearner['last_name'], 0, 1));
                    $availableSearch = strtolower(trim($availableName . ' ' . $availableLearner['learner_number'] . ' ' . ($availableLearner['email'] ?? '') . ' ' . ($availableLearner['phone'] ?? '')));
                  ?>
                  <label class="class-learner-picker-card" data-search="<?php echo e($availableSearch); ?>">
                    <input type="checkbox" name="learner_ids[]" value="<?php echo (int) $availableLearner['id']; ?>">
                    <span class="class-learner-picker-photo">
                      <?php if (!empty($availableLearner['profile_photo'])): ?>
                        <img src="<?php echo e($availableLearner['profile_photo']); ?>" alt="<?php echo e($availableName); ?>">
                      <?php else: ?>
                        <span><?php echo e($availableInitials); ?></span>
                      <?php endif; ?>
                    </span>
                    <span class="class-learner-picker-info">
                      <strong><?php echo e($availableName); ?></strong>
                      <small><?php echo e($availableLearner['learner_number']); ?></small>
                      <?php if (!empty($availableLearner['email'])): ?>
                        <small><?php echo e($availableLearner['email']); ?></small>
                      <?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="empty-state d-none" id="classLearnerNoResults">
                <i class="fa-solid fa-magnifying-glass"></i>
                <p>No available learners match your filter.</p>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <span class="text-secondary small me-auto" id="classLearnerSelectedCount">0 selected</span>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" <?php echo !$availableLearners ? 'disabled' : ''; ?>>
              <i class="fa-solid fa-user-plus me-2"></i>Add Selected
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="addClassTeachersModal" tabindex="-1" aria-labelledby="addClassTeachersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable class-learner-picker-modal">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Add Teachers</span>
            <h2 class="modal-title h5" id="addClassTeachersModalLabel">Select teachers for <?php echo e($class['class_name']); ?></h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="add_class_teachers">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="teachers">
            <div class="row g-3 mb-4">
              <div class="col-12">
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                  <input type="search" class="form-control" id="classTeacherSearch" placeholder="Search teacher name, code, email, or specialization">
                </div>
              </div>
            </div>

            <?php if (!$availableTeachers): ?>
              <div class="empty-state">
                <i class="fa-solid fa-circle-check"></i>
                <p>All active teachers are already assigned to this class.</p>
              </div>
            <?php else: ?>
              <div class="class-learner-picker-grid" id="classTeacherPickerGrid">
                <?php foreach ($availableTeachers as $availableTeacher): ?>
                  <?php
                    $teacherSearch = strtolower(trim($availableTeacher['full_name'] . ' ' . $availableTeacher['teacher_code'] . ' ' . ($availableTeacher['email'] ?? '') . ' ' . ($availableTeacher['specialization'] ?? '')));
                    $teacherInitial = strtoupper(substr((string) $availableTeacher['full_name'], 0, 1));
                  ?>
                  <label class="class-learner-picker-card class-teacher-picker-card" data-search="<?php echo e($teacherSearch); ?>">
                    <input type="checkbox" name="teacher_ids[]" value="<?php echo (int) $availableTeacher['id']; ?>">
                    <span class="class-learner-picker-photo">
                      <?php if (!empty($availableTeacher['profile_photo'])): ?>
                        <img src="<?php echo e($availableTeacher['profile_photo']); ?>" alt="<?php echo e($availableTeacher['full_name']); ?>">
                      <?php else: ?>
                        <span><?php echo e($teacherInitial); ?></span>
                      <?php endif; ?>
                    </span>
                    <span class="class-learner-picker-info">
                      <strong><?php echo e($availableTeacher['full_name']); ?></strong>
                      <small><?php echo e($availableTeacher['teacher_code']); ?></small>
                      <?php if (!empty($availableTeacher['email'])): ?>
                        <small><?php echo e($availableTeacher['email']); ?></small>
                      <?php endif; ?>
                      <?php if (!empty($availableTeacher['specialization'])): ?>
                        <small><?php echo e($availableTeacher['specialization']); ?></small>
                      <?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="empty-state d-none" id="classTeacherNoResults">
                <i class="fa-solid fa-magnifying-glass"></i>
                <p>No available teachers match your filter.</p>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <span class="text-secondary small me-auto" id="classTeacherSelectedCount">0 selected</span>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" <?php echo !$availableTeachers ? 'disabled' : ''; ?>>
              <i class="fa-solid fa-user-plus me-2"></i>Add Selected
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="learnerPhotoModal" tabindex="-1" aria-labelledby="learnerPhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content image-preview-modal">
        <div class="modal-header">
          <h2 class="modal-title h5" id="learnerPhotoModalLabel">Profile picture</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <img src="" alt="" id="learnerPhotoPreview">
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="materialFolderModal" tabindex="-1" aria-labelledby="materialFolderModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-md modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Topic</span>
            <h2 class="modal-title h5" id="materialFolderModalLabel">Add topic</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="save_material_folder">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="materials">
            <input type="hidden" id="topic_return_tool" name="return_tool" value="<?php echo e($tool); ?>">
            <div class="mb-3">
              <label class="form-label" for="folder_name">Topic name</label>
              <input type="text" class="form-control" id="folder_name" name="folder_name" required>
            </div>
            <div>
              <label class="form-label" for="folder_description">Description</label>
              <textarea class="form-control" id="folder_description" name="folder_description" rows="3" placeholder="Optional topic notes"></textarea>
            </div>
            <div class="mt-3">
              <label class="form-label" for="topic_banner_image">Topic wallpaper</label>
              <input type="file" class="form-control" id="topic_banner_image" name="topic_banner_image" accept="image/png,image/jpeg,image/webp">
              <div class="form-text">Recommended size: 1600 x 600 px. JPG, PNG, or WEBP up to 4MB.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-folder-plus me-2"></i>Save Topic
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editTopicModal" tabindex="-1" aria-labelledby="editTopicModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-md modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Topic</span>
            <h2 class="modal-title h5" id="editTopicModalLabel">Edit topic</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_material_folder">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="materials">
            <input type="hidden" id="edit_topic_id" name="folder_id">
            <input type="hidden" id="edit_topic_return_tool" name="return_tool" value="<?php echo e($tool); ?>">
            <input type="hidden" id="edit_topic_existing_banner" name="existing_banner">
            <div class="mb-3">
              <label class="form-label" for="edit_topic_name">Topic name</label>
              <input type="text" class="form-control" id="edit_topic_name" name="folder_name" required>
            </div>
            <div>
              <label class="form-label" for="edit_topic_description">Description</label>
              <textarea class="form-control" id="edit_topic_description" name="folder_description" rows="3" placeholder="Optional topic notes"></textarea>
            </div>
            <div class="mt-3">
              <label class="form-label" for="edit_topic_banner_image">Replace topic wallpaper</label>
              <input type="file" class="form-control" id="edit_topic_banner_image" name="topic_banner_image" accept="image/png,image/jpeg,image/webp">
              <div class="form-text">Leave blank to keep the current wallpaper. Recommended size: 1600 x 600 px.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Topic
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Grade</span>
            <h2 class="modal-title h5" id="taskModalLabel">Add grade</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="tasks">
            <input type="hidden" name="return_tool" value="<?php echo $tool === 'grades' ? 'grades' : 'tasks'; ?>">
            <div class="mb-3">
              <label class="form-label" for="task_topic_id">Class topic</label>
              <select class="form-select" id="task_topic_id" name="task_topic_id" required>
                <?php if (!$materialFolders): ?>
                  <option value="">Add a topic first</option>
                <?php endif; ?>
                <?php foreach ($materialFolders as $folder): ?>
                  <option value="<?php echo (int) $folder['id']; ?>" <?php echo $tool === 'grades' && (int) $folder['id'] === $selectedGradeTopicId ? 'selected' : ''; ?>><?php echo e($folder['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label" for="task_title">Grade title</label>
              <input type="text" class="form-control" id="task_title" name="task_title" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="task_date">Grade date</label>
              <input type="date" class="form-control" id="task_date" name="task_date" value="<?php echo e(date('Y-m-d')); ?>" required>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label" for="task_max_score">Max grade</label>
                <input type="number" class="form-control grade-max-score-input" id="task_max_score" name="max_score" min="1" step="0.01" value="100" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="task_passing_score">Passing grade</label>
                <input type="number" class="form-control grade-passing-score-input" id="task_passing_score" name="passing_score" min="1" step="0.01" placeholder="Optional">
              </div>
            </div>
            <div class="alert alert-warning py-2 d-none grade-setting-warning" role="alert">
              Passing grade cannot be higher than the max grade.
            </div>
            <div>
              <label class="form-label" for="description">Description</label>
              <textarea class="form-control" id="description" name="description" rows="3"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" <?php echo !$materialFolders ? 'disabled' : ''; ?>>Save Grade</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editTaskModal" tabindex="-1" aria-labelledby="editTaskModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Grade</span>
            <h2 class="modal-title h5" id="editTaskModalLabel">Edit grade item</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_task">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="grades">
            <input type="hidden" id="edit_task_id" name="task_id" value="<?php echo $selectedTask ? (int) $selectedTask['id'] : 0; ?>">
            <div class="mb-3">
              <label class="form-label" for="edit_task_topic_id">Class topic</label>
              <select class="form-select" id="edit_task_topic_id" name="task_topic_id" required>
                <?php foreach ($materialFolders as $folder): ?>
                  <option value="<?php echo (int) $folder['id']; ?>" <?php echo $selectedTask && (int) ($selectedTask['folder_id'] ?? 0) === (int) $folder['id'] ? 'selected' : ''; ?>><?php echo e($folder['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label" for="edit_task_title">Grade title</label>
              <input type="text" class="form-control" id="edit_task_title" name="task_title" value="<?php echo $selectedTask ? e((string) $selectedTask['task_title']) : ''; ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="edit_task_date">Grade date</label>
              <input type="date" class="form-control" id="edit_task_date" name="task_date" value="<?php echo $selectedTask ? e((string) $selectedTask['task_date']) : e(date('Y-m-d')); ?>" required>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label" for="edit_task_max_score">Max grade</label>
                <input type="number" class="form-control grade-max-score-input" id="edit_task_max_score" name="max_score" min="1" step="0.01" value="<?php echo $selectedTask ? e((string) ($selectedTask['max_score'] ?? 100)) : '100'; ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="edit_task_passing_score">Passing grade</label>
                <input type="number" class="form-control grade-passing-score-input" id="edit_task_passing_score" name="passing_score" min="1" step="0.01" value="<?php echo $selectedTask && isset($selectedTask['passing_score']) && $selectedTask['passing_score'] !== null ? e((string) $selectedTask['passing_score']) : ''; ?>" placeholder="Optional">
              </div>
            </div>
            <div class="alert alert-warning py-2 d-none grade-setting-warning" role="alert">
              Passing grade cannot be higher than the max grade.
            </div>
            <div>
              <label class="form-label" for="edit_task_description">Description</label>
              <textarea class="form-control" id="edit_task_description" name="description" rows="3"><?php echo $selectedTask ? e((string) ($selectedTask['description'] ?? '')) : ''; ?></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" <?php echo !$selectedTask ? 'disabled' : ''; ?>>
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Grade Item
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editMaterialModal" tabindex="-1" aria-labelledby="editMaterialModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Learning Material</span>
            <h2 class="modal-title h5" id="editMaterialModalLabel">Edit material</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_material">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="materials">
            <input type="hidden" id="edit_material_id" name="material_id">
            <div class="mb-3">
              <label class="form-label" for="edit_material_title">Title</label>
              <input type="text" class="form-control" id="edit_material_title" name="material_title" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="edit_material_description">Description</label>
              <textarea class="form-control" id="edit_material_description" name="material_description" rows="3"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label" for="edit_material_folder_id">Topic</label>
              <select class="form-select" id="edit_material_folder_id" name="material_folder_id">
                <option value="0">No topic</option>
                <?php foreach ($materialFolders as $folder): ?>
                  <option value="<?php echo (int) $folder['id']; ?>"><?php echo e($folder['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-0">
              <label class="form-label" for="edit_material_external_url">External link</label>
              <input type="url" class="form-control" id="edit_material_external_url" name="material_external_url" placeholder="https://">
              <div class="form-text">Only link resources use this field. Uploaded files keep their saved file.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Learning Material</span>
            <h2 class="modal-title h5" id="materialModalLabel">Add material</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" enctype="multipart/form-data" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="save_material">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="materials">
            <div class="mb-3">
              <label class="form-label" for="material_title">Title</label>
              <input type="text" class="form-control" id="material_title" name="material_title" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="material_description">Description</label>
              <textarea class="form-control" id="material_description" name="material_description" rows="3" placeholder="Optional notes for learners"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label" for="material_folder_id">Topic</label>
              <select class="form-select" id="material_folder_id" name="material_folder_id">
                <option value="0">No topic</option>
                <?php foreach ($materialFolders as $folder): ?>
                  <option value="<?php echo (int) $folder['id']; ?>"><?php echo e($folder['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Select a topic first if you want to organize this material.</div>
            </div>
            <div class="mb-4">
              <span class="form-label d-block">Attachment</span>
              <input type="file" class="visually-hidden" id="material_files" name="material_files[]" multiple>
              <label class="material-upload-zone" id="materialDropZone" for="material_files" role="button" tabindex="0">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <strong>Drag &amp; drop files here</strong>
                <span>or click to browse (multiple files allowed)</span>
              </label>
              <div class="material-selected-panel d-none" id="materialFilePanel">
                <div class="material-selected-header">
                  <strong id="materialFileCount">0 files selected</strong>
                  <span>Pending upload</span>
                </div>
                <div class="material-file-list" id="materialFileList"></div>
              </div>
              <div class="form-text">App limit: 200GB per file. Your PHP and Apache settings must also allow very large uploads.</div>
            </div>
            <div class="material-link-section">
              <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                <label class="form-label mb-0" for="material_url_0">External Links</label>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addMaterialLink">
                  <i class="fa-solid fa-plus me-1"></i>Add Link
                </button>
              </div>
              <div class="material-link-list" id="materialLinkList">
                <div class="material-link-row">
                  <input type="url" class="form-control" id="material_url_0" name="material_urls[]" placeholder="https://youtube.com/... or https://drive.google.com/...">
                  <button type="button" class="btn btn-outline-secondary material-link-remove" aria-label="Remove link">
                    <i class="fa-solid fa-xmark"></i>
                  </button>
                </div>
              </div>
              <div class="form-text">Links are saved separately from uploaded files. Add as many YouTube, Drive, or website links as needed.</div>
            </div>
            <div class="alert alert-info mt-3 mb-0">
              Add files, links, or both. Every file and every link will become its own class material.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Material
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="quizModal" tabindex="-1" aria-labelledby="quizModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Quiz</span>
            <h2 class="modal-title h5" id="quizModalLabel">Add multiple choice quiz</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="save_quiz">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="quizzes">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label" for="quiz_title">Quiz title</label>
                <input type="text" class="form-control" id="quiz_title" name="quiz_title" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="timer_minutes">Timer minutes</label>
                <input type="number" class="form-control" id="timer_minutes" name="timer_minutes" min="1" max="240" value="10" required>
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label" for="quiz_topic_id">Topic</label>
              <select class="form-select" id="quiz_topic_id" name="quiz_topic_id">
                <option value="0">No topic</option>
                <?php foreach ($materialFolders as $folder): ?>
                  <option value="<?php echo (int) $folder['id']; ?>"><?php echo e($folder['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Select a topic first if you want to organize this quiz.</div>
            </div>
            <div class="mt-3">
              <label class="form-label" for="quiz_description">Description</label>
              <textarea class="form-control" id="quiz_description" name="quiz_description" rows="2" placeholder="Optional instructions for learners"></textarea>
            </div>
            <div class="d-flex align-items-center justify-content-between gap-3 mt-4 mb-3">
              <h3 class="h6 mb-0">Questions</h3>
              <button type="button" class="btn btn-sm btn-outline-primary" id="addQuizQuestion">
                <i class="fa-solid fa-plus me-2"></i>Add Question
              </button>
            </div>
            <div id="quizQuestions">
              <div class="quiz-question-builder" data-question-index="0">
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <strong>Question 1</strong>
                  <button type="button" class="btn btn-sm btn-outline-danger remove-quiz-question" disabled>Remove</button>
                </div>
                <label class="form-label">Question text</label>
                <textarea class="form-control mb-3" name="questions[0][text]" rows="2" required></textarea>
                <div class="row g-3">
                  <?php for ($choiceIndex = 0; $choiceIndex < 4; $choiceIndex++): ?>
                    <div class="col-md-6">
                      <label class="form-label">Choice <?php echo $choiceIndex + 1; ?></label>
                      <div class="input-group">
                        <span class="input-group-text">
                          <input class="form-check-input mt-0" type="radio" name="questions[0][correct]" value="<?php echo $choiceIndex; ?>" <?php echo $choiceIndex === 0 ? 'checked' : ''; ?> aria-label="Correct answer">
                        </span>
                        <input type="text" class="form-control" name="questions[0][choices][<?php echo $choiceIndex; ?>]" required>
                      </div>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Quiz
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editQuizModal" tabindex="-1" aria-labelledby="editQuizModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Quiz</span>
            <h2 class="modal-title h5" id="editQuizModalLabel">Edit quiz</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_quiz">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="quizzes">
            <input type="hidden" id="edit_quiz_id" name="quiz_id">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label" for="edit_quiz_title">Quiz title</label>
                <input type="text" class="form-control" id="edit_quiz_title" name="quiz_title" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="edit_timer_minutes">Timer minutes</label>
                <input type="number" class="form-control" id="edit_timer_minutes" name="timer_minutes" min="1" max="240" required>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-8">
                <label class="form-label" for="edit_quiz_topic_id">Topic</label>
                <select class="form-select" id="edit_quiz_topic_id" name="quiz_topic_id">
                  <option value="0">No topic</option>
                  <?php foreach ($materialFolders as $folder): ?>
                    <option value="<?php echo (int) $folder['id']; ?>"><?php echo e($folder['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="edit_quiz_status">Status</label>
                <select class="form-select" id="edit_quiz_status" name="quiz_status">
                  <option value="Active">Active</option>
                  <option value="Inactive">Inactive</option>
                </select>
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label" for="edit_quiz_description">Description</label>
              <textarea class="form-control" id="edit_quiz_description" name="quiz_description" rows="3" placeholder="Optional instructions for learners"></textarea>
            </div>
            <div class="alert alert-warning mt-3 mb-0">
              Saving question changes refreshes the answer key and clears existing learner attempts for this quiz.
            </div>
            <div class="d-flex align-items-center justify-content-between gap-3 mt-4 mb-3">
              <h3 class="h6 mb-0">Questions</h3>
              <button type="button" class="btn btn-sm btn-outline-primary" id="addEditQuizQuestion">
                <i class="fa-solid fa-plus me-2"></i>Add Question
              </button>
            </div>
            <div id="editQuizQuestions">
              <div class="quiz-question-builder" data-question-index="0">
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <strong>Question 1</strong>
                  <button type="button" class="btn btn-sm btn-outline-danger remove-quiz-question" disabled>Remove</button>
                </div>
                <label class="form-label">Question text</label>
                <textarea class="form-control mb-3" name="questions[0][text]" rows="2" required></textarea>
                <div class="row g-3">
                  <?php for ($choiceIndex = 0; $choiceIndex < 4; $choiceIndex++): ?>
                    <div class="col-md-6">
                      <label class="form-label">Choice <?php echo $choiceIndex + 1; ?></label>
                      <div class="input-group">
                        <span class="input-group-text">
                          <input class="form-check-input mt-0" type="radio" name="questions[0][correct]" value="<?php echo $choiceIndex; ?>" <?php echo $choiceIndex === 0 ? 'checked' : ''; ?> aria-label="Correct answer">
                        </span>
                        <input type="text" class="form-control" name="questions[0][choices][<?php echo $choiceIndex; ?>]" required>
                      </div>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="assignmentModal" tabindex="-1" aria-labelledby="assignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Assignment</span>
            <h2 class="modal-title h5" id="assignmentModalLabel">Add assignment</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" enctype="multipart/form-data" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="save_assignment">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="assignments">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label" for="assignment_title">Assignment title</label>
                <input type="text" class="form-control" id="assignment_title" name="assignment_title" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="due_date">Due date</label>
                <input type="date" class="form-control" id="due_date" name="due_date">
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label" for="assignment_topic_id">Topic</label>
              <select class="form-select" id="assignment_topic_id" name="assignment_topic_id">
                <option value="0">No topic</option>
                <?php foreach ($materialFolders as $folder): ?>
                  <option value="<?php echo (int) $folder['id']; ?>"><?php echo e($folder['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Select a topic first if you want to organize this assignment.</div>
            </div>
            <div class="mt-3">
              <label class="form-label" for="assignment_instructions">Instructions</label>
              <textarea class="form-control" id="assignment_instructions" name="assignment_instructions" rows="5" placeholder="Tell learners what they need to do."></textarea>
            </div>
            <div class="mt-3">
              <label class="form-label" for="assignment_file">Attachment</label>
              <input type="file" class="form-control" id="assignment_file" name="assignment_file">
              <div class="form-text">Optional file learners can download before submitting their work.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Assignment
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <?php if (in_array($credentialEmailStatus, ['sent', 'failed'], true)): ?>
    <?php $credentialResetText = $credentialEmailStatus === 'sent'
        ? 'The learner password was reset and the new login credentials were sent by email.'
        : 'The learner password was reset, but the credential email could not be sent.' . ($mailError !== '' ? ' ' . $mailError : ''); ?>
    <script>
      window.credentialResetNotice = {
        icon: <?php echo json_encode($credentialEmailStatus === 'sent' ? 'success' : 'error'); ?>,
        title: <?php echo json_encode($credentialEmailStatus === 'sent' ? 'Credentials sent' : 'Email not sent'); ?>,
        text: <?php echo json_encode($credentialResetText); ?>
      };
    </script>
  <?php endif; ?>
  <script>
    (function () {
      function refreshQuizQuestionLabels(container) {
        if (!container) {
          return;
        }

        container.querySelectorAll('.quiz-question-builder').forEach(function (block, index) {
          block.querySelector('strong').textContent = 'Question ' + (index + 1);
          var removeButton = block.querySelector('.remove-quiz-question');
          removeButton.disabled = container.querySelectorAll('.quiz-question-builder').length === 1;
        });
      }

      function buildQuizQuestionBlock(template, index, question) {
        var clone = template.cloneNode(true);
        var choices = question && Array.isArray(question.choices) ? question.choices : ['', '', '', ''];
        var correct = question ? Number(question.correct || 0) : 0;

        clone.dataset.questionIndex = String(index);
        clone.querySelectorAll('textarea, input').forEach(function (field) {
          field.name = field.name.replace(/questions\[\d+\]/, 'questions[' + index + ']');

          if (field.matches('textarea')) {
            field.value = question && question.text ? question.text : '';
            return;
          }

          if (field.type === 'radio') {
            field.checked = Number(field.value) === correct;
            return;
          }

          var choiceMatch = field.name.match(/choices\]\[(\d+)\]/);
          field.value = choiceMatch ? (choices[Number(choiceMatch[1])] || '') : '';
        });

        return clone;
      }

      function addQuizQuestion(container) {
        if (!container) {
          return;
        }

        var template = container.querySelector('.quiz-question-builder');
        var nextIndex = container.querySelectorAll('.quiz-question-builder').length;

        container.appendChild(buildQuizQuestionBlock(template, nextIndex, null));
        refreshQuizQuestionLabels(container);
      }

      function bindQuizQuestionBuilder(containerId, buttonId) {
        var container = document.getElementById(containerId);
        var addButton = document.getElementById(buttonId);

        if (!container || !addButton) {
          return;
        }

        addButton.addEventListener('click', function () {
          addQuizQuestion(container);
        });

        container.addEventListener('click', function (event) {
          if (event.target.classList.contains('remove-quiz-question')) {
            event.target.closest('.quiz-question-builder').remove();
            refreshQuizQuestionLabels(container);
          }
        });

        refreshQuizQuestionLabels(container);
      }

      window.renderEditQuizQuestions = function (questions) {
        var container = document.getElementById('editQuizQuestions');

        if (!container) {
          return;
        }

        var template = container.querySelector('.quiz-question-builder').cloneNode(true);
        var rows = Array.isArray(questions) && questions.length ? questions : [{ text: '', choices: ['', '', '', ''], correct: 0 }];

        container.innerHTML = '';
        rows.forEach(function (question, index) {
          container.appendChild(buildQuizQuestionBlock(template, index, question));
        });
        refreshQuizQuestionLabels(container);
      };

      bindQuizQuestionBuilder('quizQuestions', 'addQuizQuestion');
      bindQuizQuestionBuilder('editQuizQuestions', 'addEditQuizQuestion');
    })();
  </script>
  <script>
    (function () {
      var editQuizModal = document.getElementById('editQuizModal');

      if (editQuizModal) {
        editQuizModal.addEventListener('shown.bs.modal', function () {
          var modalBody = editQuizModal.querySelector('.modal-body');

          if (modalBody) {
            modalBody.scrollTop = 0;
          }
        });
      }
    })();
  </script>
  <script src="js/app.js?v=20260713-learner-sidebar-teacher-email"></script>
</body>
</html>
