<?php

require_once __DIR__ . '/includes/auth_guard.php';

if (!$auth->isAdmin() && !$auth->isTeacher()) {
    header('Location: ' . $auth->redirectPath());
    exit;
}

if ($auth->isAdminSideUser() && !$auth->isTeacher()) {
    kiwiRequirePermission($pdo, 'grades.manage');
}

// Reusable escaping helper for grade workflows.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = $_GET['success'] ?? '';
$isTeacher = $auth->isTeacher();
$teacher = null;
$showTaskModal = false;
$showGradeModal = false;

if ($isTeacher) {
    $teacherStatement = $pdo->prepare('SELECT * FROM teachers WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $teacherStatement->execute(['email' => $currentUser['email']]);
    $teacher = $teacherStatement->fetch() ?: null;

    if (!$teacher) {
        $errors[] = 'Your login is not linked to a teacher profile yet.';
    }
}

function canManageClass(PDO $pdo, bool $isTeacher, ?array $teacher, int $classId): bool
{
    if ($classId <= 0) {
        return false;
    }

    if (!$isTeacher) {
        return true;
    }

    if (!$teacher) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT classes.id
         FROM classes
         LEFT JOIN class_teachers ON class_teachers.class_id = classes.id AND class_teachers.deleted_at IS NULL
         WHERE classes.id = :class_id
           AND classes.deleted_at IS NULL
           AND (classes.teacher_id = :teacher_id OR class_teachers.teacher_id = :assigned_teacher_id)
         LIMIT 1'
    );
    $statement->execute([
        'class_id' => $classId,
        'teacher_id' => (int) $teacher['id'],
        'assigned_teacher_id' => (int) $teacher['id'],
    ]);

    return (bool) $statement->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $classId = (int) ($_POST['class_id'] ?? 0);

    if (!canManageClass($pdo, $isTeacher, $teacher, $classId)) {
        $errors[] = $isTeacher ? 'You can only manage grades for your assigned classes.' : 'Choose a valid class.';
    }

    if ($action === 'save_task') {
        $showTaskModal = true;
        $taskTitle = trim($_POST['task_title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $taskDate = trim($_POST['task_date'] ?? date('Y-m-d'));
        $teacherId = $isTeacher && $teacher ? (int) $teacher['id'] : (int) ($_POST['teacher_id'] ?? 0);

        if ($taskTitle === '') {
            $errors[] = 'Task title is required.';
        }

        if ($taskDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) {
            $errors[] = 'Choose a valid task date.';
        }

        if (!$errors) {
            // Tasks are created per class and become selectable when adding learner grades.
            $statement = $pdo->prepare(
                'INSERT INTO class_tasks (class_id, teacher_id, task_title, description, task_date, created_by_user_id)
                 VALUES (:class_id, :teacher_id, :task_title, :description, :task_date, :created_by_user_id)'
            );
            $statement->execute([
                'class_id' => $classId,
                'teacher_id' => $teacherId > 0 ? $teacherId : null,
                'task_title' => $taskTitle,
                'description' => $description !== '' ? $description : null,
                'task_date' => $taskDate,
                'created_by_user_id' => (int) $currentUser['id'],
            ]);

            header('Location: grades.php?class_id=' . $classId . '&success=task_created');
            exit;
        }
    }

    if ($action === 'save_grade') {
        $showGradeModal = true;
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $learnerId = (int) ($_POST['learner_id'] ?? 0);
        $score = (float) ($_POST['score'] ?? 0);
        $gradedAt = trim($_POST['graded_at'] ?? date('Y-m-d'));
        $remarks = trim($_POST['remarks'] ?? '');

        if ($taskId <= 0) {
            $errors[] = 'Choose a task.';
        }

        if ($learnerId <= 0) {
            $errors[] = 'Choose a learner.';
        }

        if ($score < 1 || $score > 100) {
            $errors[] = 'Grade must be from 1 to 100.';
        }

        if ($gradedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gradedAt)) {
            $errors[] = 'Choose a valid grade date.';
        }

        if (!$errors) {
            $taskStatement = $pdo->prepare('SELECT id, task_title FROM class_tasks WHERE id = :task_id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
            $taskStatement->execute([
                'task_id' => $taskId,
                'class_id' => $classId,
            ]);
            $task = $taskStatement->fetch();

            if (!$task) {
                $errors[] = 'Choose a valid task for this class.';
            }
        }

        if (!$errors) {
            $learnerStatement = $pdo->prepare('SELECT id FROM learners WHERE id = :learner_id AND class_id = :class_id AND deleted_at IS NULL LIMIT 1');
            $learnerStatement->execute([
                'learner_id' => $learnerId,
                'class_id' => $classId,
            ]);

            if (!$learnerStatement->fetch()) {
                $errors[] = 'This learner does not belong to the selected class.';
            }
        }

        if (!$errors) {
            $teacherId = $isTeacher && $teacher ? (int) $teacher['id'] : null;
            // One task can receive one current score per learner; re-saving updates that score.
            $statement = $pdo->prepare(
                'SELECT id FROM learner_grades WHERE task_id = :task_id AND learner_id = :learner_id AND deleted_at IS NULL LIMIT 1'
            );
            $statement->execute([
                'task_id' => $taskId,
                'learner_id' => $learnerId,
            ]);
            $existingGradeId = (int) $statement->fetchColumn();

            if ($existingGradeId > 0) {
                $gradeStatement = $pdo->prepare(
                    'UPDATE learner_grades
                     SET class_id = :class_id,
                         teacher_id = :teacher_id,
                         grade_title = :grade_title,
                         score = :score,
                         max_score = 100,
                         remarks = :remarks,
                         graded_at = :graded_at,
                         created_by_user_id = :created_by_user_id
                     WHERE id = :id'
                );
                $gradeStatement->execute([
                    'class_id' => $classId,
                    'teacher_id' => $teacherId,
                    'grade_title' => $task['task_title'],
                    'score' => $score,
                    'remarks' => $remarks !== '' ? $remarks : null,
                    'graded_at' => $gradedAt,
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
                    'teacher_id' => $teacherId,
                    'grade_title' => $task['task_title'],
                    'score' => $score,
                    'remarks' => $remarks !== '' ? $remarks : null,
                    'graded_at' => $gradedAt,
                    'created_by_user_id' => (int) $currentUser['id'],
                ]);
            }

            header('Location: grades.php?class_id=' . $classId . '&success=grade_saved');
            exit;
        }
    }
}

if ($isTeacher && $teacher) {
    $classesStatement = $pdo->prepare(
        'SELECT classes.id, classes.class_name, classes.teacher
         FROM classes
         LEFT JOIN class_teachers ON class_teachers.class_id = classes.id AND class_teachers.deleted_at IS NULL
         WHERE classes.deleted_at IS NULL
           AND (classes.teacher_id = :teacher_id
            OR class_teachers.teacher_id = :assigned_teacher_id
           )
         GROUP BY classes.id
         ORDER BY class_name'
    );
    $classesStatement->execute([
        'teacher_id' => (int) $teacher['id'],
        'assigned_teacher_id' => (int) $teacher['id'],
    ]);
    $classes = $classesStatement->fetchAll();
} else {
    $classes = $pdo->query('SELECT id, class_name, teacher FROM classes WHERE deleted_at IS NULL ORDER BY class_name')->fetchAll();
}

$defaultClassId = (int) ($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
$selectedClass = null;

foreach ($classes as $class) {
    if ((int) $class['id'] === $defaultClassId) {
        $selectedClass = $class;
        break;
    }
}

if (!$selectedClass && $classes) {
    $selectedClass = $classes[0];
    $defaultClassId = (int) $selectedClass['id'];
}

$tasks = [];
$learners = [];
$grades = [];
$averages = [];
$teachers = $pdo->query("SELECT id, full_name FROM teachers WHERE status = 'Active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll();

if ($selectedClass) {
    $tasksStatement = $pdo->prepare(
        'SELECT class_tasks.*, teachers.full_name AS teacher_name
         FROM class_tasks
         LEFT JOIN teachers ON teachers.id = class_tasks.teacher_id AND teachers.deleted_at IS NULL
         WHERE class_tasks.class_id = :class_id
           AND class_tasks.deleted_at IS NULL
         ORDER BY class_tasks.task_date DESC, class_tasks.id DESC'
    );
    $tasksStatement->execute(['class_id' => $defaultClassId]);
    $tasks = $tasksStatement->fetchAll();

    $learnersStatement = $pdo->prepare(
        'SELECT id, class_id, learner_number, first_name, last_name
         FROM learners
         WHERE class_id = :class_id
           AND deleted_at IS NULL
         ORDER BY first_name, last_name'
    );
    $learnersStatement->execute(['class_id' => $defaultClassId]);
    $learners = $learnersStatement->fetchAll();

    $gradesStatement = $pdo->prepare(
        'SELECT learner_grades.*,
                learners.learner_number,
                learners.first_name,
                learners.last_name,
                class_tasks.task_title,
                teachers.full_name AS teacher_name
         FROM learner_grades
         INNER JOIN learners ON learners.id = learner_grades.learner_id AND learners.deleted_at IS NULL
         LEFT JOIN class_tasks ON class_tasks.id = learner_grades.task_id AND class_tasks.deleted_at IS NULL
         LEFT JOIN teachers ON teachers.id = learner_grades.teacher_id AND teachers.deleted_at IS NULL
         WHERE learner_grades.class_id = :class_id
           AND learner_grades.deleted_at IS NULL
         ORDER BY learner_grades.graded_at DESC, learner_grades.id DESC'
    );
    $gradesStatement->execute(['class_id' => $defaultClassId]);
    $grades = $gradesStatement->fetchAll();

    $averageStatement = $pdo->prepare(
        'SELECT learners.id,
                learners.learner_number,
                learners.first_name,
                learners.last_name,
                COUNT(learner_grades.id) AS task_count,
                COALESCE(AVG(learner_grades.score), 0) AS average_score
         FROM learners
         LEFT JOIN learner_grades
           ON learner_grades.learner_id = learners.id
          AND learner_grades.class_id = learners.class_id
          AND learner_grades.deleted_at IS NULL
         WHERE learners.class_id = :class_id
           AND learners.deleted_at IS NULL
         GROUP BY learners.id, learners.learner_number, learners.first_name, learners.last_name
         ORDER BY learners.first_name, learners.last_name'
    );
    $averageStatement->execute(['class_id' => $defaultClassId]);
    $averages = $averageStatement->fetchAll();
}

$successMessages = [
    'task_created' => 'Task added successfully.',
    'grade_saved' => 'Grade saved successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e(kiwiSystemBrandName()); ?> | Grades</title>
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
<body class="dashboard-page">
  <div class="app-layout">
    <?php if ($isTeacher): ?>
      <aside class="sidebar">
        <a class="sidebar-brand" href="teacher_dashboard.php">
          <img src="<?php echo e(kiwiSystemLogo()); ?>" alt="<?php echo e(kiwiSystemBrandName()); ?>" class="brand-logo">
          <span>
            <strong><?php echo e(kiwiSystemBrandName()); ?></strong>
            <small><?php echo e(kiwiSystemName()); ?></small>
          </span>
        </a>
        <nav class="sidebar-nav">
          <a href="teacher_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        </nav>
        <div class="sidebar-footer">
          <p class="mb-1">Logged in as</p>
          <strong><?php echo e($currentUser['name']); ?></strong>
        </div>
      </aside>
    <?php else: ?>
      <?php $activeSidebarItem = 'grades'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <?php endif; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1"><?php echo $isTeacher ? 'Teacher Portal' : 'Learners Progress Monitoring System'; ?></p>
          <h1 class="h3 mb-0">Grades</h1>
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

        <div class="panel-card mb-4">
          <span class="section-kicker">Class</span>
          <h2 class="h5 mb-4">Select class</h2>
          <form method="get" class="module-form">
            <div class="row g-3 align-items-end">
              <div class="col-lg-9">
                <label class="form-label" for="class_id">Class</label>
                <select class="form-select" id="class_id" name="class_id" required>
                  <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>" <?php echo $defaultClassId === (int) $class['id'] ? 'selected' : ''; ?>>
                      <?php echo e($class['class_name'] . (!empty($class['teacher']) ? ' - ' . $class['teacher'] : '')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-lg-3 d-grid">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-folder-open me-2"></i>Open Class</button>
              </div>
            </div>
          </form>
        </div>

        <?php if ($selectedClass): ?>
          <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
              <div class="metric-card">
                <span class="metric-icon bg-primary-subtle text-primary"><i class="fa-solid fa-list-check"></i></span>
                <p>Tasks</p>
                <h3><?php echo count($tasks); ?></h3>
                <small class="text-secondary">For selected class</small>
              </div>
            </div>
            <div class="col-md-6 col-xl-3">
              <div class="metric-card">
                <span class="metric-icon bg-success-subtle text-success"><i class="fa-solid fa-users"></i></span>
                <p>Learners</p>
                <h3><?php echo count($learners); ?></h3>
                <small class="text-secondary">Can receive grades</small>
              </div>
            </div>
            <div class="col-md-6 col-xl-3">
              <div class="metric-card">
                <span class="metric-icon bg-warning-subtle text-warning"><i class="fa-solid fa-star-half-stroke"></i></span>
                <p>Grades</p>
                <h3><?php echo count($grades); ?></h3>
                <small class="text-secondary">Saved task scores</small>
              </div>
            </div>
            <div class="col-md-6 col-xl-3">
              <div class="metric-card">
                <span class="metric-icon bg-info-subtle text-info"><i class="fa-solid fa-chart-line"></i></span>
                <p>Class Average</p>
                <h3><?php echo $averages ? number_format(array_sum(array_column($averages, 'average_score')) / count($averages), 1) : '0.0'; ?></h3>
                <small class="text-secondary">Across learner averages</small>
              </div>
            </div>
          </div>

          <div class="panel-card mb-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Tasks</span>
                <h2 class="h5 mb-0">Tasks for <?php echo e($selectedClass['class_name']); ?></h2>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal"><i class="fa-solid fa-plus me-2"></i>Add Task</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#gradeModal" <?php echo !$tasks || !$learners ? 'disabled' : ''; ?>><i class="fa-solid fa-star me-2"></i>Add Grade</button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>Task</th>
                    <th>Date</th>
                    <th>Teacher</th>
                    <th>Description</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$tasks): ?>
                    <tr><td colspan="4" class="text-center text-secondary py-5">No tasks yet. Add a task before adding grades.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($tasks as $task): ?>
                    <tr>
                      <td><strong><?php echo e($task['task_title']); ?></strong></td>
                      <td><?php echo e(date('M d, Y', strtotime($task['task_date']))); ?></td>
                      <td><?php echo e($task['teacher_name'] ?? ''); ?></td>
                      <td><?php echo e($task['description'] ?? ''); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="row g-4">
            <div class="col-xl-5">
              <div class="panel-card h-100">
                <span class="section-kicker">Average</span>
                <h2 class="h5 mb-4">Learner averages</h2>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Learner</th>
                        <th>Tasks</th>
                        <th>Average</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (!$averages): ?>
                        <tr><td colspan="3" class="text-center text-secondary py-5">No learners in this class.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($averages as $average): ?>
                        <tr>
                          <td>
                            <strong><?php echo e($average['first_name'] . ' ' . $average['last_name']); ?></strong><br>
                            <span class="text-secondary small"><?php echo e($average['learner_number']); ?></span>
                          </td>
                          <td><?php echo (int) $average['task_count']; ?></td>
                          <td><strong><?php echo e(number_format((float) $average['average_score'], 2)); ?></strong></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <div class="col-xl-7">
              <div class="panel-card">
                <span class="section-kicker">Records</span>
                <h2 class="h5 mb-4">Task grades</h2>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Learner</th>
                        <th>Task</th>
                        <th>Grade</th>
                        <th>Date</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (!$grades): ?>
                        <tr><td colspan="4" class="text-center text-secondary py-5">No grades recorded yet.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($grades as $grade): ?>
                        <tr>
                          <td>
                            <strong><?php echo e($grade['first_name'] . ' ' . $grade['last_name']); ?></strong><br>
                            <span class="text-secondary small"><?php echo e($grade['learner_number']); ?></span>
                          </td>
                          <td>
                            <strong><?php echo e($grade['task_title'] ?: $grade['grade_title']); ?></strong>
                            <?php if (!empty($grade['remarks'])): ?>
                              <br><span class="text-secondary small"><?php echo e($grade['remarks']); ?></span>
                            <?php endif; ?>
                          </td>
                          <td><strong><?php echo e(number_format((float) $grade['score'], 2)); ?></strong> / 100</td>
                          <td><?php echo e(date('M d, Y', strtotime($grade['graded_at']))); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <i class="fa-solid fa-circle-exclamation"></i>
            <p>No class is available for grades yet.</p>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <?php if ($selectedClass): ?>
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
              <input type="hidden" name="class_id" value="<?php echo (int) $selectedClass['id']; ?>">
              <?php if (!$isTeacher): ?>
                <div class="mb-3">
                  <label class="form-label" for="teacher_id">Teacher</label>
                  <select class="form-select" id="teacher_id" name="teacher_id">
                    <option value="">Optional</option>
                    <?php foreach ($teachers as $teacherOption): ?>
                      <option value="<?php echo (int) $teacherOption['id']; ?>"><?php echo e($teacherOption['full_name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
              <div class="mb-3">
                <label class="form-label" for="task_title">Task title</label>
                <input type="text" class="form-control" id="task_title" name="task_title" placeholder="Quiz 1, Activity 2, Project" required>
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
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-2"></i>Save Task</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="gradeModal" tabindex="-1" aria-labelledby="gradeModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <span class="section-kicker">Grade</span>
              <h2 class="modal-title h5" id="gradeModalLabel">Add task grade</h2>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="post" class="module-form">
            <div class="modal-body">
              <input type="hidden" name="action" value="save_grade">
              <input type="hidden" name="class_id" value="<?php echo (int) $selectedClass['id']; ?>">
              <div class="mb-3">
                <label class="form-label" for="task_id">Task</label>
                <select class="form-select" id="task_id" name="task_id" required>
                  <option value="">Choose task</option>
                  <?php foreach ($tasks as $task): ?>
                    <option value="<?php echo (int) $task['id']; ?>"><?php echo e($task['task_title']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label" for="learner_id">Learner</label>
                <select class="form-select" id="learner_id" name="learner_id" required>
                  <option value="">Choose learner</option>
                  <?php foreach ($learners as $learner): ?>
                    <option value="<?php echo (int) $learner['id']; ?>"><?php echo e($learner['first_name'] . ' ' . $learner['last_name'] . ' - ' . $learner['learner_number']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label" for="score">Grade</label>
                <input type="number" min="1" max="100" step="0.01" class="form-control" id="score" name="score" placeholder="1 to 100" required>
              </div>
              <div class="mb-3">
                <label class="form-label" for="graded_at">Date</label>
                <input type="date" class="form-control" id="graded_at" name="graded_at" value="<?php echo e(date('Y-m-d')); ?>" required>
              </div>
              <div>
                <label class="form-label" for="remarks">Remarks</label>
                <textarea class="form-control" id="remarks" name="remarks" rows="3"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-2"></i>Save Grade</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($showTaskModal && $errors): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('taskModal')).show();
      });
    </script>
  <?php endif; ?>
  <?php if ($showGradeModal && $errors): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('gradeModal')).show();
      });
    </script>
  <?php endif; ?>
  <script src="js/app.js?v=20260713-learner-sidebar-teacher-email"></script>
</body>
</html>
