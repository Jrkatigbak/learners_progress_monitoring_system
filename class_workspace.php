<?php

require_once __DIR__ . '/includes/auth_guard.php';

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

function classCourseId(PDO $pdo, array $class): int
{
    $courseCode = 'CLASS-' . (int) $class['id'];
    $courseStatement = $pdo->prepare(
        'INSERT INTO courses (course_code, course_name, description, status)
         VALUES (:course_code, :course_name, :description, :status)
         ON DUPLICATE KEY UPDATE
            course_name = VALUES(course_name),
            description = VALUES(description),
            status = VALUES(status)'
    );
    $courseStatement->execute([
        'course_code' => $courseCode,
        'course_name' => $class['class_name'],
        'description' => $class['description'] ?? null,
        'status' => $class['status'],
    ]);

    $lookupStatement = $pdo->prepare('SELECT id FROM courses WHERE course_code = :course_code LIMIT 1');
    $lookupStatement->execute(['course_code' => $courseCode]);

    return (int) $lookupStatement->fetchColumn();
}

$classId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$tool = $_GET['tool'] ?? $_POST['tool'] ?? 'dashboard';
$allowedTools = ['dashboard', 'learners', 'materials', 'quizzes', 'assignments', 'tasks', 'grades'];
$tool = in_array($tool, $allowedTools, true) ? $tool : 'dashboard';
$errors = [];
$success = $_GET['success'] ?? '';
$isAdmin = $auth->isAdmin();
$isTeacher = $auth->isTeacher();
$teacherProfile = null;
$materialUploadDirectory = __DIR__ . '/uploads/materials';
$materialUploadPathPrefix = 'uploads/materials/';
$assignmentUploadDirectory = __DIR__ . '/uploads/assignments';
$assignmentUploadPathPrefix = 'uploads/assignments/';

if (!$isAdmin && !$isTeacher) {
    header('Location: ' . $auth->redirectPath());
    exit;
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

$classStatement = $pdo->prepare(
    'SELECT classes.*,
            COALESCE(teachers.full_name, classes.teacher) AS display_teacher,
            teachers.profile_photo AS teacher_photo,
            teachers.teacher_code AS teacher_code,
            teachers.specialization AS teacher_specialization
     FROM classes
     LEFT JOIN teachers ON teachers.id = classes.teacher_id
     WHERE classes.id = :id
     LIMIT 1'
);
$classStatement->execute(['id' => $classId]);
$class = $classStatement->fetch();

if (!$class) {
    header('Location: classes.php');
    exit;
}

if ($isTeacher) {
    $teacherStatement = $pdo->prepare('SELECT * FROM teachers WHERE email = :email LIMIT 1');
    $teacherStatement->execute(['email' => $currentUser['email']]);
    $teacherProfile = $teacherStatement->fetch() ?: null;

    if (!$teacherProfile || (int) ($class['teacher_id'] ?? 0) !== (int) $teacherProfile['id']) {
        // Teachers can manage only the class connected to their teacher profile.
        header('Location: teacher_dashboard.php');
        exit;
    }
}

$courseId = classCourseId($pdo, $class);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_material') {
        $title = trim($_POST['material_title'] ?? '');
        $description = trim($_POST['material_description'] ?? '');
        $materialUrl = trim($_POST['material_url'] ?? '');
        $filePath = null;
        $originalFilename = null;
        $mimeType = null;
        $materialType = 'file';

        if ($title === '') {
            $errors[] = 'Material title is required.';
        }

        $hasUpload = isset($_FILES['material_file']) && $_FILES['material_file']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($materialUrl === '' && !$hasUpload) {
            $errors[] = 'Upload a file or paste a learning material link.';
        }

        if ($materialUrl !== '' && !filter_var($materialUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Enter a valid YouTube or website link.';
        }

        if ($hasUpload && !$errors) {
            if ($_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Learning material file could not be uploaded.';
            } elseif (!is_writable($materialUploadDirectory)) {
                $errors[] = 'Learning material upload folder is not writable.';
            } else {
                $originalFilename = basename((string) $_FILES['material_file']['name']);
                $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'sh'];

                if (in_array($extension, $blockedExtensions, true)) {
                    $errors[] = 'This file type is not allowed for learning materials.';
                } else {
                    $mimeType = mime_content_type($_FILES['material_file']['tmp_name']) ?: 'application/octet-stream';
                    $materialType = materialTypeFromFile($mimeType, $extension);
                    $safeExtension = $extension !== '' ? $extension : 'bin';
                    $filename = 'material-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $safeExtension;
                    $targetPath = $materialUploadDirectory . '/' . $filename;

                    if (move_uploaded_file($_FILES['material_file']['tmp_name'], $targetPath)) {
                        $filePath = $materialUploadPathPrefix . $filename;
                    } else {
                        $errors[] = 'Learning material file could not be saved.';
                    }
                }
            }
        } elseif ($materialUrl !== '') {
            $materialType = preg_match('/(youtube\.com|youtu\.be)/i', $materialUrl) ? 'youtube' : 'link';
        }

        if (!$errors) {
            // Store either the uploaded file reference or the external learning material URL for this class.
            $materialStatement = $pdo->prepare(
                'INSERT INTO class_learning_materials
                    (class_id, title, description, material_type, file_path, original_filename, mime_type, external_url, uploaded_by_user_id)
                 VALUES
                    (:class_id, :title, :description, :material_type, :file_path, :original_filename, :mime_type, :external_url, :uploaded_by_user_id)'
            );
            $materialStatement->execute([
                'class_id' => $classId,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'material_type' => $materialType,
                'file_path' => $filePath,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'external_url' => $materialUrl !== '' ? $materialUrl : null,
                'uploaded_by_user_id' => (int) $currentUser['id'],
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=materials&success=material_created');
            exit;
        }

        $tool = 'materials';
    }

    if ($action === 'delete_material') {
        $materialId = (int) ($_POST['material_id'] ?? 0);
        $materialStatement = $pdo->prepare('SELECT file_path FROM class_learning_materials WHERE id = :id AND class_id = :class_id LIMIT 1');
        $materialStatement->execute([
            'id' => $materialId,
            'class_id' => $classId,
        ]);
        $material = $materialStatement->fetch() ?: null;

        if ($material) {
            deleteMaterialFile((string) ($material['file_path'] ?? ''));
            // Delete only materials that belong to the open class workspace.
            $deleteStatement = $pdo->prepare('DELETE FROM class_learning_materials WHERE id = :id AND class_id = :class_id');
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
        $timerMinutes = (int) ($_POST['timer_minutes'] ?? 10);
        $questions = $_POST['questions'] ?? [];

        if ($quizTitle === '') {
            $errors[] = 'Quiz title is required.';
        }

        if ($timerMinutes < 1 || $timerMinutes > 240) {
            $errors[] = 'Quiz timer must be from 1 to 240 minutes.';
        }

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

        if (!$preparedQuestions) {
            $errors[] = 'Add at least one quiz question.';
        }

        if (!$errors) {
            $pdo->beginTransaction();

            // A quiz owns its questions and choices so scoring can be computed later from the saved answer key.
            $quizStatement = $pdo->prepare(
                'INSERT INTO class_quizzes (class_id, title, description, timer_minutes, status, created_by_user_id)
                 VALUES (:class_id, :title, :description, :timer_minutes, :status, :created_by_user_id)'
            );
            $quizStatement->execute([
                'class_id' => $classId,
                'title' => $quizTitle,
                'description' => $quizDescription !== '' ? $quizDescription : null,
                'timer_minutes' => $timerMinutes,
                'status' => 'Active',
                'created_by_user_id' => (int) $currentUser['id'],
            ]);
            $quizId = (int) $pdo->lastInsertId();

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

            $pdo->commit();

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=quizzes&success=quiz_created');
            exit;
        }

        $tool = 'quizzes';
    }

    if ($action === 'delete_quiz') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $quizStatement = $pdo->prepare('SELECT id FROM class_quizzes WHERE id = :id AND class_id = :class_id LIMIT 1');
        $quizStatement->execute([
            'id' => $quizId,
            'class_id' => $classId,
        ]);

        if ($quizStatement->fetch()) {
            // Remove dependent rows manually so older local databases without foreign keys stay tidy.
            $answerDelete = $pdo->prepare(
                'DELETE quiz_attempt_answers
                 FROM quiz_attempt_answers
                 INNER JOIN quiz_attempts ON quiz_attempts.id = quiz_attempt_answers.attempt_id
                 WHERE quiz_attempts.quiz_id = :quiz_id'
            );
            $answerDelete->execute(['quiz_id' => $quizId]);

            $attemptDelete = $pdo->prepare('DELETE FROM quiz_attempts WHERE quiz_id = :quiz_id');
            $attemptDelete->execute(['quiz_id' => $quizId]);

            $choiceDelete = $pdo->prepare(
                'DELETE quiz_choices
                 FROM quiz_choices
                 INNER JOIN quiz_questions ON quiz_questions.id = quiz_choices.question_id
                 WHERE quiz_questions.quiz_id = :quiz_id'
            );
            $choiceDelete->execute(['quiz_id' => $quizId]);

            $questionDelete = $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id = :quiz_id');
            $questionDelete->execute(['quiz_id' => $quizId]);

            $quizDelete = $pdo->prepare('DELETE FROM class_quizzes WHERE id = :id AND class_id = :class_id');
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
        $attachmentPath = null;
        $originalFilename = null;

        if ($assignmentTitle === '') {
            $errors[] = 'Assignment title is required.';
        }

        if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $errors[] = 'Choose a valid due date.';
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
                    (class_id, title, instructions, due_date, attachment_path, original_filename, status, created_by_user_id)
                 VALUES
                    (:class_id, :title, :instructions, :due_date, :attachment_path, :original_filename, :status, :created_by_user_id)'
            );
            $assignmentStatement->execute([
                'class_id' => $classId,
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
        $assignmentStatement = $pdo->prepare('SELECT attachment_path FROM class_assignments WHERE id = :id AND class_id = :class_id LIMIT 1');
        $assignmentStatement->execute([
            'id' => $assignmentId,
            'class_id' => $classId,
        ]);
        $assignment = $assignmentStatement->fetch() ?: null;

        if ($assignment) {
            deleteAssignmentFile((string) ($assignment['attachment_path'] ?? ''));
            // Delete submissions first so older local databases without foreign keys stay tidy.
            $submissionDelete = $pdo->prepare('DELETE FROM assignment_submissions WHERE assignment_id = :assignment_id');
            $submissionDelete->execute(['assignment_id' => $assignmentId]);

            $assignmentDelete = $pdo->prepare('DELETE FROM class_assignments WHERE id = :id AND class_id = :class_id');
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

        if ($title === '') {
            $errors[] = 'Task title is required.';
        }

        if ($taskDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) {
            $errors[] = 'Choose a valid task date.';
        }

        if (!$errors) {
            // Tasks are tied to this class so grade entry can stay class-specific.
            $taskStatement = $pdo->prepare(
                'INSERT INTO class_tasks (class_id, teacher_id, task_title, description, task_date, created_by_user_id)
                 VALUES (:class_id, :teacher_id, :task_title, :description, :task_date, :created_by_user_id)'
            );
            $taskStatement->execute([
                'class_id' => $classId,
                'teacher_id' => !empty($class['teacher_id']) ? (int) $class['teacher_id'] : null,
                'task_title' => $title,
                'description' => $description !== '' ? $description : null,
                'task_date' => $taskDate,
                'created_by_user_id' => (int) $currentUser['id'],
            ]);

            header('Location: class_workspace.php?class_id=' . $classId . '&tool=tasks&success=task_created');
            exit;
        }

        $tool = 'tasks';
    }

    if ($action === 'delete_task') {
        // Delete a class task and its task grades from this workspace only.
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $deleteGrades = $pdo->prepare('DELETE FROM learner_grades WHERE task_id = :task_id AND class_id = :class_id');
        $deleteGrades->execute([
            'task_id' => $taskId,
            'class_id' => $classId,
        ]);

        $deleteTask = $pdo->prepare('DELETE FROM class_tasks WHERE id = :task_id AND class_id = :class_id');
        $deleteTask->execute([
            'task_id' => $taskId,
            'class_id' => $classId,
        ]);

        header('Location: class_workspace.php?class_id=' . $classId . '&tool=tasks&success=task_deleted');
        exit;
    }

    if ($action === 'save_grades') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $taskStatement = $pdo->prepare('SELECT * FROM class_tasks WHERE id = :id AND class_id = :class_id LIMIT 1');
        $taskStatement->execute([
            'id' => $taskId,
            'class_id' => $classId,
        ]);
        $task = $taskStatement->fetch();

        if (!$task) {
            $errors[] = 'Choose a valid task.';
        }

        $scores = $_POST['scores'] ?? [];
        $remarks = $_POST['remarks'] ?? [];

        if (!$errors) {
            foreach ($scores as $learnerId => $scoreValue) {
                $scoreValue = trim((string) $scoreValue);

                if ($scoreValue === '') {
                    continue;
                }

                $score = (float) $scoreValue;
                $learnerId = (int) $learnerId;

                if ($score < 1 || $score > 100) {
                    $errors[] = 'Grades must be from 1 to 100.';
                    break;
                }

                $learnerStatement = $pdo->prepare(
                    'SELECT learners.id
                     FROM learners
                     LEFT JOIN course_enrollments ON course_enrollments.learner_id = learners.id
                     WHERE learners.id = :learner_id
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

                $existingStatement = $pdo->prepare('SELECT id FROM learner_grades WHERE task_id = :task_id AND learner_id = :learner_id LIMIT 1');
                $existingStatement->execute([
                    'task_id' => $taskId,
                    'learner_id' => $learnerId,
                ]);
                $existingGradeId = (int) $existingStatement->fetchColumn();
                $remark = trim((string) ($remarks[$learnerId] ?? ''));

                if ($existingGradeId > 0) {
                    $gradeStatement = $pdo->prepare(
                        'UPDATE learner_grades
                         SET score = :score,
                             max_score = 100,
                             remarks = :remarks,
                             graded_at = :graded_at,
                             grade_title = :grade_title,
                             created_by_user_id = :created_by_user_id
                         WHERE id = :id'
                    );
                    $gradeStatement->execute([
                        'score' => $score,
                        'remarks' => $remark !== '' ? $remark : null,
                        'graded_at' => date('Y-m-d'),
                        'grade_title' => $task['task_title'],
                        'created_by_user_id' => (int) $currentUser['id'],
                        'id' => $existingGradeId,
                    ]);
                } else {
                    $gradeStatement = $pdo->prepare(
                        'INSERT INTO learner_grades
                            (task_id, learner_id, class_id, teacher_id, grade_title, score, max_score, remarks, graded_at, created_by_user_id)
                         VALUES
                            (:task_id, :learner_id, :class_id, :teacher_id, :grade_title, :score, 100, :remarks, :graded_at, :created_by_user_id)'
                    );
                    $gradeStatement->execute([
                        'task_id' => $taskId,
                        'learner_id' => $learnerId,
                        'class_id' => $classId,
                        'teacher_id' => !empty($class['teacher_id']) ? (int) $class['teacher_id'] : null,
                        'grade_title' => $task['task_title'],
                        'score' => $score,
                        'remarks' => $remark !== '' ? $remark : null,
                        'graded_at' => date('Y-m-d'),
                        'created_by_user_id' => (int) $currentUser['id'],
                    ]);
                }
            }
        }

        if (!$errors) {
            header('Location: class_workspace.php?class_id=' . $classId . '&tool=grades&task_id=' . $taskId . '&success=grades_saved');
            exit;
        }

        $tool = 'grades';
    }
}

$learnerStatement = $pdo->prepare(
    'SELECT DISTINCT learners.*
     FROM learners
     LEFT JOIN course_enrollments ON course_enrollments.learner_id = learners.id
     WHERE learners.class_id = :class_id
        OR (
          course_enrollments.course_id = :course_id
          AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
        )
     ORDER BY learners.first_name, learners.last_name'
);
$learnerStatement->execute([
    'class_id' => $classId,
    'course_id' => $courseId,
]);
$learners = $learnerStatement->fetchAll();

$tasksStatement = $pdo->prepare(
    'SELECT class_tasks.*,
            COUNT(learner_grades.id) AS grade_count,
            COALESCE(AVG(learner_grades.score), 0) AS average_score
     FROM class_tasks
     LEFT JOIN learner_grades ON learner_grades.task_id = class_tasks.id
     WHERE class_tasks.class_id = :class_id
     GROUP BY class_tasks.id
     ORDER BY class_tasks.task_date DESC, class_tasks.id DESC'
);
$tasksStatement->execute(['class_id' => $classId]);
$tasks = $tasksStatement->fetchAll();

$materialsStatement = $pdo->prepare(
    'SELECT class_learning_materials.*, users.name AS uploader_name
     FROM class_learning_materials
     LEFT JOIN users ON users.id = class_learning_materials.uploaded_by_user_id
     WHERE class_learning_materials.class_id = :class_id
     ORDER BY class_learning_materials.created_at DESC, class_learning_materials.id DESC'
);
$materialsStatement->execute(['class_id' => $classId]);
$materials = $materialsStatement->fetchAll();

$quizzesStatement = $pdo->prepare(
    'SELECT class_quizzes.*,
            COUNT(DISTINCT quiz_questions.id) AS question_count,
            COUNT(DISTINCT quiz_attempts.id) AS attempt_count,
            COALESCE(AVG(quiz_attempts.score), 0) AS average_score
     FROM class_quizzes
     LEFT JOIN quiz_questions ON quiz_questions.quiz_id = class_quizzes.id
     LEFT JOIN quiz_attempts ON quiz_attempts.quiz_id = class_quizzes.id
     WHERE class_quizzes.class_id = :class_id
     GROUP BY class_quizzes.id
     ORDER BY class_quizzes.created_at DESC, class_quizzes.id DESC'
);
$quizzesStatement->execute(['class_id' => $classId]);
$quizzes = $quizzesStatement->fetchAll();

$assignmentsStatement = $pdo->prepare(
    'SELECT class_assignments.*,
            COUNT(assignment_submissions.id) AS submission_count
     FROM class_assignments
     LEFT JOIN assignment_submissions ON assignment_submissions.assignment_id = class_assignments.id
     WHERE class_assignments.class_id = :class_id
     GROUP BY class_assignments.id
     ORDER BY class_assignments.created_at DESC, class_assignments.id DESC'
);
$assignmentsStatement->execute(['class_id' => $classId]);
$assignments = $assignmentsStatement->fetchAll();

$selectedTaskId = (int) ($_GET['task_id'] ?? $_POST['task_id'] ?? ($tasks[0]['id'] ?? 0));
$selectedTask = null;
foreach ($tasks as $taskRow) {
    if ((int) $taskRow['id'] === $selectedTaskId) {
        $selectedTask = $taskRow;
        break;
    }
}

$gradeRows = [];
if ($selectedTask) {
    $gradeStatement = $pdo->prepare('SELECT * FROM learner_grades WHERE task_id = :task_id AND class_id = :class_id');
    $gradeStatement->execute([
        'task_id' => (int) $selectedTask['id'],
        'class_id' => $classId,
    ]);
    foreach ($gradeStatement->fetchAll() as $gradeRow) {
        $gradeRows[(int) $gradeRow['learner_id']] = $gradeRow;
    }
}

$classAverageStatement = $pdo->prepare('SELECT COALESCE(AVG(score), 0) FROM learner_grades WHERE class_id = :class_id');
$classAverageStatement->execute(['class_id' => $classId]);
$classAverage = (float) $classAverageStatement->fetchColumn();
$gradeCountStatement = $pdo->prepare('SELECT COUNT(*) FROM learner_grades WHERE class_id = :class_id');
$gradeCountStatement->execute(['class_id' => $classId]);
$gradeCount = (int) $gradeCountStatement->fetchColumn();
$teacherName = (string) ($class['display_teacher'] ?? '');
$teacherInitials = $teacherName !== '' ? strtoupper(substr($teacherName, 0, 1)) : 'T';

$successMessages = [
    'material_created' => 'Learning material added successfully.',
    'material_deleted' => 'Learning material deleted successfully.',
    'quiz_created' => 'Quiz added successfully.',
    'quiz_deleted' => 'Quiz deleted successfully.',
    'assignment_created' => 'Assignment added successfully.',
    'assignment_deleted' => 'Assignment deleted successfully.',
    'task_created' => 'Task added successfully.',
    'task_deleted' => 'Task deleted successfully.',
    'grades_saved' => 'Grades saved successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | <?php echo e($class['class_name']); ?></title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css" rel="stylesheet">
</head>
<body class="dashboard-page class-workspace-page class-workspace-<?php echo e($tool); ?>">
  <div class="app-layout">
    <aside class="sidebar">
      <a class="sidebar-brand" href="<?php echo $isTeacher ? 'teacher_dashboard.php' : 'classes.php'; ?>">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <span>
          <strong><?php echo e($class['class_name']); ?></strong>
          <small>Class Workspace</small>
        </span>
      </a>
      <div class="class-sidebar-banner">
        <?php if (!empty($class['banner_image'])): ?>
          <button type="button" class="class-image-view-button learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($class['banner_image']); ?>" data-name="<?php echo e($class['class_name']); ?> wallpaper" aria-label="View <?php echo e($class['class_name']); ?> wallpaper">
            <img src="<?php echo e($class['banner_image']); ?>" alt="<?php echo e($class['class_name']); ?> wallpaper">
          </button>
        <?php else: ?>
          <div class="class-sidebar-banner-placeholder">
            <i class="fa-solid fa-image"></i>
          </div>
        <?php endif; ?>
      </div>
      <nav class="sidebar-nav">
        <a class="<?php echo $tool === 'dashboard' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=dashboard"><i class="fa-solid fa-gauge-high"></i> Class Dashboard</a>
        <a class="<?php echo $tool === 'learners' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=learners"><i class="fa-solid fa-users"></i> Learners</a>
        <a class="<?php echo $tool === 'materials' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=materials"><i class="fa-solid fa-folder-open"></i> Materials</a>
        <a class="<?php echo $tool === 'quizzes' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=quizzes"><i class="fa-solid fa-circle-question"></i> Quizzes</a>
        <a class="<?php echo $tool === 'assignments' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=assignments"><i class="fa-solid fa-file-pen"></i> Assignments</a>
        <a class="<?php echo $tool === 'tasks' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=tasks"><i class="fa-solid fa-list-check"></i> Tasks</a>
        <a class="<?php echo $tool === 'grades' ? 'active' : ''; ?>" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=grades"><i class="fa-solid fa-star"></i> Grades</a>
        <a href="<?php echo $isTeacher ? 'teacher_dashboard.php' : 'classes.php'; ?>"><i class="fa-solid fa-arrow-left"></i> Back to Classes</a>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Teacher</p>
        <strong><?php echo e($class['display_teacher'] ?? ''); ?></strong>
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
                <span class="section-kicker">Teacher</span>
                <div class="class-teacher-profile">
                  <div class="class-teacher-photo">
                    <?php if (!empty($class['teacher_photo'])): ?>
                      <button type="button" class="learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($class['teacher_photo']); ?>" data-name="<?php echo e($teacherName); ?>" aria-label="View <?php echo e($teacherName); ?> profile picture">
                        <img src="<?php echo e($class['teacher_photo']); ?>" alt="<?php echo e($teacherName); ?> profile picture">
                      </button>
                    <?php else: ?>
                      <span><?php echo e($teacherInitials); ?></span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <h2 class="h5 mb-1"><?php echo e($teacherName ?: 'No teacher assigned'); ?></h2>
                    <?php if (!empty($class['teacher_code'])): ?>
                      <p class="text-secondary mb-1"><?php echo e($class['teacher_code']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($class['teacher_specialization'])): ?>
                      <p class="mb-0"><?php echo e($class['teacher_specialization']); ?></p>
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
                    <p>Tasks</p>
                    <h3><?php echo count($tasks); ?></h3>
                    <small class="text-secondary">Created for class</small>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="metric-card">
                    <span class="metric-icon bg-warning-subtle text-warning"><i class="fa-solid fa-star"></i></span>
                    <p>Grades</p>
                    <h3><?php echo $gradeCount; ?></h3>
                    <small class="text-secondary">Saved task scores</small>
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
              <span class="badge text-bg-primary"><?php echo count($learners); ?> total</span>
            </div>

            <?php if (!$learners): ?>
              <div class="empty-state">
                <i class="fa-solid fa-users"></i>
                <p>No learners are enrolled in this class yet.</p>
              </div>
            <?php else: ?>
              <div class="learner-grid class-workspace-learner-grid">
                <?php foreach ($learners as $learner): ?>
                  <?php
                    $fullName = trim($learner['first_name'] . ' ' . $learner['last_name']);
                    $initials = strtoupper(substr($learner['first_name'], 0, 1) . substr($learner['last_name'], 0, 1));
                  ?>
                  <article class="learner-card">
                    <div class="learner-card-body">
                      <span class="learner-status-pill <?php echo $learner['status'] === 'Completed' ? 'is-completed' : ($learner['status'] === 'On Hold' ? 'is-hold' : 'is-active'); ?>"><?php echo e($learner['status']); ?></span>
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
                <h2 class="h5 mb-0">Class resources</h2>
              </div>
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#materialModal">
                <i class="fa-solid fa-plus me-2"></i>Add Material
              </button>
            </div>

            <?php if (!$materials): ?>
              <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <p>No learning materials have been uploaded yet.</p>
              </div>
            <?php else: ?>
              <div class="material-grid">
                <?php foreach ($materials as $material): ?>
                  <?php
                    $materialType = (string) $material['material_type'];
                    $materialPath = (string) ($material['file_path'] ?? '');
                    $materialUrl = (string) ($material['external_url'] ?? '');
                    $openUrl = $materialPath !== '' ? $materialPath : $materialUrl;
                  ?>
                  <article class="material-card">
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
                        <form method="post" onsubmit="return confirm('Delete this learning material?');">
                          <input type="hidden" name="action" value="delete_material">
                          <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                          <input type="hidden" name="tool" value="materials">
                          <input type="hidden" name="material_id" value="<?php echo (int) $material['id']; ?>">
                          <button type="submit" class="learner-icon-button is-danger" aria-label="Delete material">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      </div>
                      <?php if (!empty($material['description'])): ?>
                        <p><?php echo e($material['description']); ?></p>
                      <?php endif; ?>
                      <div class="material-meta">
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
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'quizzes'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Quizzes</span>
                <h2 class="h5 mb-0">Multiple choice quizzes</h2>
              </div>
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#quizModal">
                <i class="fa-solid fa-plus me-2"></i>Add Quiz
              </button>
            </div>

            <?php if (!$quizzes): ?>
              <div class="empty-state">
                <i class="fa-solid fa-circle-question"></i>
                <p>No quizzes have been created for this class yet.</p>
              </div>
            <?php else: ?>
              <div class="quiz-card-grid">
                <?php foreach ($quizzes as $quiz): ?>
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
                        <form method="post" onsubmit="return confirm('Delete this quiz and all attempts?');">
                          <input type="hidden" name="action" value="delete_quiz">
                          <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                          <input type="hidden" name="tool" value="quizzes">
                          <input type="hidden" name="quiz_id" value="<?php echo (int) $quiz['id']; ?>">
                          <button type="submit" class="learner-icon-button is-danger" aria-label="Delete quiz">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      </div>
                      <?php if (!empty($quiz['description'])): ?>
                        <p><?php echo e($quiz['description']); ?></p>
                      <?php endif; ?>
                      <div class="quiz-stats">
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
                <h2 class="h5 mb-0">Learner work requirements</h2>
              </div>
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignmentModal">
                <i class="fa-solid fa-plus me-2"></i>Add Assignment
              </button>
            </div>

            <?php if (!$assignments): ?>
              <div class="empty-state">
                <i class="fa-solid fa-file-pen"></i>
                <p>No assignments have been created for this class yet.</p>
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
                        <form method="post" onsubmit="return confirm('Delete this assignment and its submissions?');">
                          <input type="hidden" name="action" value="delete_assignment">
                          <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                          <input type="hidden" name="tool" value="assignments">
                          <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment['id']; ?>">
                          <button type="submit" class="learner-icon-button is-danger" aria-label="Delete assignment">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      </div>
                      <?php if (!empty($assignment['instructions'])): ?>
                        <p><?php echo e($assignment['instructions']); ?></p>
                      <?php endif; ?>
                      <div class="quiz-stats">
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
                <span class="section-kicker">Tasks</span>
                <h2 class="h5 mb-0">Manage class tasks</h2>
              </div>
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal">
                <i class="fa-solid fa-plus me-2"></i>Add Task
              </button>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>Task</th>
                    <th>Date</th>
                    <th>Grades</th>
                    <th>Average</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$tasks): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-5">No tasks yet.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($tasks as $taskRow): ?>
                    <tr>
                      <td>
                        <strong><?php echo e($taskRow['task_title']); ?></strong>
                        <?php if (!empty($taskRow['description'])): ?>
                          <br><span class="text-secondary small"><?php echo e($taskRow['description']); ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?php echo e(date('M d, Y', strtotime($taskRow['task_date']))); ?></td>
                      <td><?php echo (int) $taskRow['grade_count']; ?></td>
                      <td><?php echo number_format((float) $taskRow['average_score'], 2); ?></td>
                      <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=grades&task_id=<?php echo (int) $taskRow['id']; ?>">Manage Grades</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this task and its grades?');">
                          <input type="hidden" name="action" value="delete_task">
                          <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                          <input type="hidden" name="tool" value="tasks">
                          <input type="hidden" name="task_id" value="<?php echo (int) $taskRow['id']; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($tool === 'grades'): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Grades</span>
                <h2 class="h5 mb-0">Manage task grades</h2>
              </div>
              <a href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=tasks" class="btn btn-sm btn-outline-primary">Manage Tasks</a>
            </div>

            <?php if (!$tasks): ?>
              <div class="empty-state">
                <i class="fa-solid fa-list-check"></i>
                <p>Add a task first before encoding grades.</p>
              </div>
            <?php else: ?>
              <form method="get" class="module-form mb-4">
                <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                <input type="hidden" name="tool" value="grades">
                <label class="form-label" for="task_id_filter">Task</label>
                <div class="input-group">
                  <select class="form-select" id="task_id_filter" name="task_id">
                    <?php foreach ($tasks as $taskRow): ?>
                      <option value="<?php echo (int) $taskRow['id']; ?>" <?php echo $selectedTask && (int) $selectedTask['id'] === (int) $taskRow['id'] ? 'selected' : ''; ?>>
                        <?php echo e($taskRow['task_title']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-primary">Open</button>
                </div>
              </form>

              <form method="post" class="module-form">
                <input type="hidden" name="action" value="save_grades">
                <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                <input type="hidden" name="tool" value="grades">
                <input type="hidden" name="task_id" value="<?php echo $selectedTask ? (int) $selectedTask['id'] : 0; ?>">
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Learner</th>
                        <th>Grade</th>
                        <th>Remarks</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (!$learners): ?>
                        <tr><td colspan="3" class="text-center text-secondary py-5">No enrolled learners to grade.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($learners as $learner): ?>
                        <?php $grade = $gradeRows[(int) $learner['id']] ?? null; ?>
                        <tr>
                          <td>
                            <strong><?php echo e(trim($learner['first_name'] . ' ' . $learner['last_name'])); ?></strong><br>
                            <span class="text-secondary small"><?php echo e($learner['learner_number']); ?></span>
                          </td>
                          <td style="max-width: 160px;">
                            <input type="number" class="form-control" name="scores[<?php echo (int) $learner['id']; ?>]" min="1" max="100" step="0.01" value="<?php echo $grade ? e((string) $grade['score']) : ''; ?>" placeholder="1-100">
                          </td>
                          <td>
                            <input type="text" class="form-control" name="remarks[<?php echo (int) $learner['id']; ?>]" value="<?php echo $grade ? e((string) ($grade['remarks'] ?? '')) : ''; ?>" placeholder="Optional">
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <button type="submit" class="btn btn-primary" <?php echo (!$learners || !$selectedTask) ? 'disabled' : ''; ?>>
                  <i class="fa-solid fa-floppy-disk me-2"></i>Save Grades
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
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

  <div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker">Task</span>
            <h2 class="modal-title h5" id="taskModalLabel">Add task</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
            <input type="hidden" name="tool" value="tasks">
            <div class="mb-3">
              <label class="form-label" for="task_title">Task title</label>
              <input type="text" class="form-control" id="task_title" name="task_title" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="task_date">Task date</label>
              <input type="date" class="form-control" id="task_date" name="task_date" value="<?php echo e(date('Y-m-d')); ?>" required>
            </div>
            <div>
              <label class="form-label" for="description">Description</label>
              <textarea class="form-control" id="description" name="description" rows="3"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Task</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel" aria-hidden="true">
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
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="material_file">Upload file</label>
                <input type="file" class="form-control" id="material_file" name="material_file">
                <div class="form-text">Use this for images, videos, PDFs, or other class files.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="material_url">YouTube or website link</label>
                <input type="url" class="form-control" id="material_url" name="material_url" placeholder="https://">
                <div class="form-text">Use this for YouTube, Google Drive, or any online resource.</div>
              </div>
            </div>
            <div class="alert alert-info mt-3 mb-0">
              Add either a file or a link. If both are provided, the uploaded file will be used.
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

  <div class="modal fade" id="quizModal" tabindex="-1" aria-labelledby="quizModalLabel" aria-hidden="true">
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
  <script>
    (function () {
      var quizQuestions = document.getElementById('quizQuestions');
      var addButton = document.getElementById('addQuizQuestion');
      var nextQuestionIndex = 1;

      function refreshQuizQuestionLabels() {
        quizQuestions.querySelectorAll('.quiz-question-builder').forEach(function (block, index) {
          block.querySelector('strong').textContent = 'Question ' + (index + 1);
          var removeButton = block.querySelector('.remove-quiz-question');
          removeButton.disabled = quizQuestions.querySelectorAll('.quiz-question-builder').length === 1;
        });
      }

      if (quizQuestions && addButton) {
        addButton.addEventListener('click', function () {
          var nextIndex = nextQuestionIndex++;
          var clone = quizQuestions.querySelector('.quiz-question-builder').cloneNode(true);
          clone.dataset.questionIndex = String(nextIndex);
          clone.querySelectorAll('textarea, input').forEach(function (field) {
            field.name = field.name.replace(/questions\\[\\d+\\]/, 'questions[' + nextIndex + ']');
            if (field.type === 'radio') {
              field.checked = field.value === '0';
            } else {
              field.value = '';
            }
          });
          quizQuestions.appendChild(clone);
          refreshQuizQuestionLabels();
        });

        quizQuestions.addEventListener('click', function (event) {
          if (event.target.classList.contains('remove-quiz-question')) {
            event.target.closest('.quiz-question-builder').remove();
            refreshQuizQuestionLabels();
          }
        });
      }
    })();
  </script>
  <script src="js/app.js"></script>
</body>
</html>
