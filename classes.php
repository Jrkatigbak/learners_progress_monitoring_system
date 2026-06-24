<?php

require_once __DIR__ . '/includes/auth_guard.php';

// Reusable escaping helper for table and form output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = $_GET['success'] ?? '';
$editingClass = null;

if (isset($_GET['edit'])) {
    $editStatement = $pdo->prepare('SELECT * FROM classes WHERE id = :id LIMIT 1');
    $editStatement->execute(['id' => (int) $_GET['edit']]);
    $editingClass = $editStatement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        // Delete stays as a POST action so a simple page visit cannot remove data.
        $deleteStatement = $pdo->prepare('DELETE FROM classes WHERE id = :id');
        $deleteStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);

        header('Location: classes.php?success=deleted');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $className = trim($_POST['class_name'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $adviser = trim($_POST['adviser'] ?? '');
    $schoolYear = trim($_POST['school_year'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $description = trim($_POST['description'] ?? '');

    if ($className === '') {
        $errors[] = 'Class name is required.';
    }

    if ($section === '') {
        $errors[] = 'Section is required.';
    }

    if ($adviser === '') {
        $errors[] = 'Adviser is required.';
    }

    if ($schoolYear === '') {
        $errors[] = 'School year is required.';
    }

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $errors[] = 'Choose a valid status.';
    }

    if (!$errors) {
        if ($id > 0) {
            // Update the selected class while preserving its original creation date.
            $statement = $pdo->prepare(
                'UPDATE classes
                 SET class_name = :class_name,
                     section = :section,
                     adviser = :adviser,
                     school_year = :school_year,
                     status = :status,
                     description = :description
                 WHERE id = :id'
            );
            $statement->execute([
                'class_name' => $className,
                'section' => $section,
                'adviser' => $adviser,
                'school_year' => $schoolYear,
                'status' => $status,
                'description' => $description !== '' ? $description : null,
                'id' => $id,
            ]);

            header('Location: classes.php?success=updated');
            exit;
        }

        // Create a new class record from the Add Class form.
        $statement = $pdo->prepare(
            'INSERT INTO classes (class_name, section, adviser, school_year, status, description)
             VALUES (:class_name, :section, :adviser, :school_year, :status, :description)'
        );
        $statement->execute([
            'class_name' => $className,
            'section' => $section,
            'adviser' => $adviser,
            'school_year' => $schoolYear,
            'status' => $status,
            'description' => $description !== '' ? $description : null,
        ]);

        header('Location: classes.php?success=created');
        exit;
    }

    $editingClass = [
        'id' => $id,
        'class_name' => $className,
        'section' => $section,
        'adviser' => $adviser,
        'school_year' => $schoolYear,
        'status' => $status,
        'description' => $description,
    ];
}

$classesStatement = $pdo->query('SELECT * FROM classes ORDER BY created_at DESC, id DESC');
$classRows = $classesStatement->fetchAll();
$formClass = $editingClass ?: [
    'id' => 0,
    'class_name' => '',
    'section' => '',
    'adviser' => '',
    'school_year' => '2026-2027',
    'status' => 'Active',
    'description' => '',
];
$successMessages = [
    'created' => 'Class added successfully.',
    'updated' => 'Class updated successfully.',
    'deleted' => 'Class deleted successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Classes</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css" rel="stylesheet">
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <aside class="sidebar">
      <a class="sidebar-brand" href="dashboard.php">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <span>
          <strong>Kiwi Digital</strong>
          <!-- Keep the system name visible in the module navigation. -->
          <small>Learners Progress Monitoring System</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
        <a class="active" href="classes.php"><i class="fa-solid fa-chalkboard-user"></i> Classes</a>
        <a href="learners.php"><i class="fa-solid fa-users"></i> Learners</a>
        <a href="enrollments.php"><i class="fa-solid fa-book-open-reader"></i> Enrollments</a>
        <a href="#"><i class="fa-solid fa-chart-simple"></i> Reports</a>
        <a href="#"><i class="fa-solid fa-gear"></i> Settings</a>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Logged in as</p>
        <strong><?php echo e($currentUser['name']); ?></strong>
      </div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <!-- Repeat the system name for clear module context. -->
          <p class="eyebrow mb-1">Learners Progress Monitoring System</p>
          <h1 class="h3 mb-0">Classes</h1>
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
        <div class="hero-panel">
          <div>
            <span class="section-kicker">Class Management</span>
            <h2>Classes</h2>
            <p>Create, update, and monitor class sections used for learner progress tracking.</p>
          </div>
          <a href="#classForm" class="btn btn-outline-light"><i class="fa-solid fa-plus me-2"></i>Add Class</a>
        </div>

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

        <div class="row g-4">
          <div class="col-xl-4">
            <div class="panel-card h-100" id="classForm">
              <span class="section-kicker"><?php echo ((int) $formClass['id'] > 0) ? 'Edit Class' : 'Add Class'; ?></span>
              <h2 class="h5 mb-4"><?php echo ((int) $formClass['id'] > 0) ? 'Update class details' : 'Create class record'; ?></h2>
              <form method="post" class="module-form">
                <input type="hidden" name="id" value="<?php echo (int) $formClass['id']; ?>">
                <div class="mb-3">
                  <label class="form-label" for="class_name">Class name</label>
                  <input type="text" class="form-control" id="class_name" name="class_name" value="<?php echo e($formClass['class_name']); ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="section">Section</label>
                  <input type="text" class="form-control" id="section" name="section" value="<?php echo e($formClass['section']); ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="adviser">Adviser</label>
                  <input type="text" class="form-control" id="adviser" name="adviser" value="<?php echo e($formClass['adviser']); ?>" required>
                </div>
                <div class="row g-3">
                  <div class="col-sm-7">
                    <label class="form-label" for="school_year">School year</label>
                    <input type="text" class="form-control" id="school_year" name="school_year" value="<?php echo e($formClass['school_year']); ?>" required>
                  </div>
                  <div class="col-sm-5">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                      <option value="Active" <?php echo $formClass['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                      <option value="Inactive" <?php echo $formClass['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                  </div>
                </div>
                <div class="my-3">
                  <label class="form-label" for="description">Description</label>
                  <textarea class="form-control" id="description" name="description" rows="4"><?php echo e($formClass['description'] ?? ''); ?></textarea>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-2"></i><?php echo ((int) $formClass['id'] > 0) ? 'Update Class' : 'Add Class'; ?>
                  </button>
                  <?php if ((int) $formClass['id'] > 0): ?>
                    <a class="btn btn-outline-secondary" href="classes.php">Cancel</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>

          <div class="col-xl-8">
            <div class="panel-card">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <span class="section-kicker">Records</span>
                  <h2 class="h5 mb-0">Class list</h2>
                </div>
                <a href="#classForm" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-2"></i>Add Class</a>
              </div>
              <div class="table-responsive">
                <table class="table align-middle module-table">
                  <thead>
                    <tr>
                      <th>Class</th>
                      <th>Adviser</th>
                      <th>School Year</th>
                      <th>Status</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$classRows): ?>
                      <tr>
                        <td colspan="5" class="text-center text-secondary py-5">No classes added yet.</td>
                      </tr>
                    <?php endif; ?>
                    <?php foreach ($classRows as $classRow): ?>
                      <tr>
                        <td>
                          <strong><?php echo e($classRow['class_name']); ?></strong><br>
                          <span class="text-secondary small"><?php echo e($classRow['section']); ?></span>
                          <?php if (!empty($classRow['description'])): ?>
                            <div class="text-secondary small mt-1"><?php echo e($classRow['description']); ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?php echo e($classRow['adviser']); ?></td>
                        <td><?php echo e($classRow['school_year']); ?></td>
                        <td>
                          <span class="badge <?php echo $classRow['status'] === 'Active' ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                            <?php echo e($classRow['status']); ?>
                          </span>
                        </td>
                        <td class="text-end">
                          <div class="table-actions">
                            <a class="btn btn-sm btn-outline-primary" href="classes.php?edit=<?php echo (int) $classRow['id']; ?>#classForm">
                              <i class="fa-solid fa-pen-to-square"></i>
                              <span class="visually-hidden">Edit</span>
                            </a>
                            <form method="post" onsubmit="return confirm('Delete this class?');">
                              <input type="hidden" name="action" value="delete">
                              <input type="hidden" name="id" value="<?php echo (int) $classRow['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fa-solid fa-trash"></i>
                                <span class="visually-hidden">Delete</span>
                              </button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js"></script>
</body>
</html>
