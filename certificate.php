<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/certificates.php';

$classId = (int) ($_GET['class_id'] ?? 0);
$learnerId = (int) ($_GET['learner_id'] ?? 0);
$downloadAll = ($_GET['all'] ?? '') === '1';
$forceDownload = ($_GET['download'] ?? '') === '1';

function certificateClassCourseId(PDO $pdo, int $classId): int
{
    $statement = $pdo->prepare('SELECT id FROM courses WHERE course_code = :course_code AND deleted_at IS NULL LIMIT 1');
    $statement->execute(['course_code' => 'CLASS-' . $classId]);

    return (int) $statement->fetchColumn();
}

function certificateLearnerRows(PDO $pdo, int $classId, int $courseId, int $learnerId = 0): array
{
    $sql = 'SELECT DISTINCT learners.id,
                   learners.learner_number,
                   learners.first_name,
                   learners.middle_name,
                   learners.last_name
            FROM learners
            LEFT JOIN course_enrollments
              ON course_enrollments.learner_id = learners.id
             AND course_enrollments.deleted_at IS NULL
            WHERE learners.deleted_at IS NULL
              AND (
                learners.class_id = :class_id
                OR (
                  course_enrollments.course_id = :course_id
                  AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
                )
              )';
    $params = [
        'class_id' => $classId,
        'course_id' => $courseId,
    ];

    if ($learnerId > 0) {
        $sql .= ' AND learners.id = :learner_id';
        $params['learner_id'] = $learnerId;
    }

    $sql .= ' ORDER BY learners.first_name, learners.last_name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function certificateLearnerName(array $learner): string
{
    $name = trim((string) $learner['first_name'] . ' ' . (string) ($learner['middle_name'] ?? '') . ' ' . (string) $learner['last_name']);
    $name = strtolower(preg_replace('/\s+/', ' ', $name) ?? $name);

    return preg_replace_callback('/\b([a-z])([a-z]*)\b/', static function (array $matches): string {
        return strtoupper($matches[1]) . $matches[2];
    }, $name) ?? $name;
}

function certificateFileName(string $name): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));

    return ($slug !== '' ? $slug : 'learner') . '-certificate.png';
}

$classColumns = kiwiClassCertificateColumns($pdo);

if (!kiwiClassCertificateReady($classColumns)) {
    http_response_code(500);
    echo 'Certificate database fields are not installed yet.';
    exit;
}

$classStatement = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$classStatement->execute(['id' => $classId]);
$class = $classStatement->fetch() ?: null;

if (!$class || empty($class['certificate_template_image'])) {
    http_response_code(404);
    echo 'Certificate template not found.';
    exit;
}

$isAdminSideUser = $auth->isAdminSideUser();
$isSystemAdmin = (($currentUser['role'] ?? '') === 'admin');
$courseId = certificateClassCourseId($pdo, $classId);

if ($isAdminSideUser) {
    if (!$isSystemAdmin && !kiwiCan($pdo, 'class_certificates.view')) {
        http_response_code(403);
        echo 'You do not have permission to view certificates.';
        exit;
    }
} elseif ($auth->isTeacher()) {
    $teacherStatement = $pdo->prepare('SELECT id FROM teachers WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $teacherStatement->execute(['email' => $currentUser['email']]);
    $teacherId = (int) $teacherStatement->fetchColumn();
    $assignedStatement = $pdo->prepare(
        'SELECT classes.id
         FROM classes
         LEFT JOIN class_teachers ON class_teachers.class_id = classes.id AND class_teachers.deleted_at IS NULL
         WHERE classes.id = :class_id
           AND classes.deleted_at IS NULL
           AND (classes.teacher_id = :teacher_id OR class_teachers.teacher_id = :assigned_teacher_id)
         LIMIT 1'
    );
    $assignedStatement->execute([
        'class_id' => $classId,
        'teacher_id' => $teacherId,
        'assigned_teacher_id' => $teacherId,
    ]);

    if (!$teacherId || !$assignedStatement->fetch()) {
        http_response_code(403);
        echo 'You can only view certificates for assigned classes.';
        exit;
    }
} elseif ($auth->isLearner()) {
    $learnerStatement = $pdo->prepare(
        'SELECT learners.id
         FROM learners
         LEFT JOIN course_enrollments
           ON course_enrollments.learner_id = learners.id
          AND course_enrollments.deleted_at IS NULL
         WHERE learners.email = :email
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
        'email' => $currentUser['email'],
        'class_id' => $classId,
        'course_id' => $courseId,
    ]);
    $loggedLearnerId = (int) $learnerStatement->fetchColumn();

    if ($loggedLearnerId <= 0) {
        http_response_code(403);
        echo 'You can only view certificates for enrolled classes.';
        exit;
    }

    $downloadAll = false;
    $learnerId = $loggedLearnerId;
} else {
    http_response_code(403);
    echo 'You do not have permission to view certificates.';
    exit;
}

$templatePath = __DIR__ . '/' . ltrim((string) $class['certificate_template_image'], '/');
$learners = certificateLearnerRows($pdo, $classId, $courseId, $downloadAll ? 0 : $learnerId);

if (!$learners) {
    http_response_code(404);
    echo 'No learner certificate is available.';
    exit;
}

if ($downloadAll) {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZIP support is not available on this server.';
        exit;
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'kiwi-certificates-');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);

    foreach ($learners as $learner) {
        $name = certificateLearnerName($learner);
        $image = kiwiRenderCertificateImage(
            $templatePath,
            $name,
            (float) $class['certificate_name_x'],
            (float) $class['certificate_name_y'],
            (int) $class['certificate_font_size'],
            (string) $class['certificate_font_color']
        );

        if ($image) {
            $zip->addFromString(certificateFileName($name), kiwiOutputCertificatePng($image));
        }
    }

    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9-]+/', '-', (string) $class['class_name']) . '-certificates.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

$learner = $learners[0];
$learnerName = certificateLearnerName($learner);
$image = kiwiRenderCertificateImage(
    $templatePath,
    $learnerName,
    (float) $class['certificate_name_x'],
    (float) $class['certificate_name_y'],
    (int) $class['certificate_font_size'],
    (string) $class['certificate_font_color']
);

if (!$image) {
    http_response_code(500);
    echo 'Certificate could not be generated.';
    exit;
}

$png = kiwiOutputCertificatePng($image);
header('Content-Type: image/png');
header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . certificateFileName($learnerName) . '"');
header('Content-Length: ' . strlen($png));
echo $png;
