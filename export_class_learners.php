<?php

require_once __DIR__ . '/includes/admin_guard.php';

kiwiRequirePermission($pdo, 'class_learners.view');

$classId = (int) ($_GET['class_id'] ?? 0);
$classStatement = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$classStatement->execute(['id' => $classId]);
$class = $classStatement->fetch() ?: null;

if (!$class) {
    header('Location: classes.php');
    exit;
}

$courseStatement = $pdo->prepare('SELECT id FROM courses WHERE course_code = :course_code AND deleted_at IS NULL LIMIT 1');
$courseStatement->execute(['course_code' => 'CLASS-' . $classId]);
$courseId = (int) $courseStatement->fetchColumn();

$learnerStatement = $pdo->prepare(
    'SELECT DISTINCT learners.learner_number,
            learners.first_name,
            learners.middle_name,
            learners.last_name,
            learners.email,
            learners.phone,
            learners.status,
            course_enrollments.enrollment_status,
            course_enrollments.enrolled_at
     FROM learners
     LEFT JOIN course_enrollments
       ON course_enrollments.learner_id = learners.id
      AND course_enrollments.deleted_at IS NULL
      AND course_enrollments.course_id = :course_id
     WHERE learners.deleted_at IS NULL
       AND (
        learners.class_id = :class_id
        OR (
          course_enrollments.course_id = :enrolled_course_id
          AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
        )
       )
     ORDER BY learners.first_name, learners.last_name'
);
$learnerStatement->execute([
    'class_id' => $classId,
    'course_id' => $courseId,
    'enrolled_course_id' => $courseId,
]);
$learners = $learnerStatement->fetchAll();

$pendingStatement = $pdo->prepare(
    'SELECT first_name,
            middle_name,
            last_name,
            email,
            contact_number,
            status,
            requested_at
     FROM class_enrollment_requests
     WHERE class_id = :class_id
       AND status = "Pending"
       AND deleted_at IS NULL
     ORDER BY requested_at ASC, id ASC'
);
$pendingStatement->execute(['class_id' => $classId]);
$pendingRequests = $pendingStatement->fetchAll();

function exportCell(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$filenameSlug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $class['class_name']), '-'));
$filename = ($filenameSlug !== '' ? $filenameSlug : 'class') . '-learners.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF";
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #999; padding: 6px; text-align: left; }
    th { background: #f4f4f4; font-weight: bold; }
  </style>
</head>
<body>
  <h2><?php echo exportCell((string) $class['class_name']); ?> - Learner List</h2>
  <h3>Enrolled Learners</h3>
  <table>
    <thead>
      <tr>
        <th>Learner Number</th>
        <th>First Name</th>
        <th>Middle Name</th>
        <th>Last Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Learner Status</th>
        <th>Enrollment Status</th>
        <th>Enrolled Date</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($learners as $learner): ?>
        <tr>
          <td><?php echo exportCell($learner['learner_number'] ?? ''); ?></td>
          <td><?php echo exportCell($learner['first_name'] ?? ''); ?></td>
          <td><?php echo exportCell($learner['middle_name'] ?? ''); ?></td>
          <td><?php echo exportCell($learner['last_name'] ?? ''); ?></td>
          <td><?php echo exportCell($learner['email'] ?? ''); ?></td>
          <td><?php echo exportCell($learner['phone'] ?? ''); ?></td>
          <td><?php echo exportCell($learner['status'] ?? ''); ?></td>
          <td><?php echo exportCell($learner['enrollment_status'] ?? 'Direct Class'); ?></td>
          <td><?php echo exportCell(!empty($learner['enrolled_at']) ? date('M d, Y', strtotime((string) $learner['enrolled_at'])) : ''); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <br>
  <h3>Pending Enrollment Requests</h3>
  <table>
    <thead>
      <tr>
        <th>First Name</th>
        <th>Middle Name</th>
        <th>Last Name</th>
        <th>Email</th>
        <th>Contact Number</th>
        <th>Status</th>
        <th>Registered Date</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pendingRequests as $request): ?>
        <tr>
          <td><?php echo exportCell($request['first_name'] ?? ''); ?></td>
          <td><?php echo exportCell($request['middle_name'] ?? ''); ?></td>
          <td><?php echo exportCell($request['last_name'] ?? ''); ?></td>
          <td><?php echo exportCell($request['email'] ?? ''); ?></td>
          <td><?php echo exportCell($request['contact_number'] ?? ''); ?></td>
          <td><?php echo exportCell($request['status'] ?? 'Pending'); ?></td>
          <td><?php echo exportCell(!empty($request['requested_at']) ? date('M d, Y g:i A', strtotime((string) $request['requested_at'])) : ''); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
