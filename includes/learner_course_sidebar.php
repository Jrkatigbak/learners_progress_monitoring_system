<?php

require_once __DIR__ . '/evaluations.php';
require_once __DIR__ . '/certificates.php';

function kiwiSidebarEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function kiwiLearnerCourseContext(PDO $pdo, int $learnerId, int $courseId = 0, int $classId = 0): ?array
{
    if ($learnerId <= 0) {
        return null;
    }

    $where = $courseId > 0 ? 'courses.id = :course_id' : 'classes.id = :class_id';
    $params = [
        'learner_id_profile' => $learnerId,
        'learner_id_enrollment' => $learnerId,
    ];

    if ($courseId > 0) {
        $params['course_id'] = $courseId;
    } elseif ($classId > 0) {
        $params['class_id'] = $classId;
    } else {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT courses.id AS course_id,
                courses.course_name,
                classes.id AS class_id,
                classes.class_name,
                classes.certificate_template_image,
                classes.certificate_name_x,
                classes.certificate_name_y,
                classes.certificate_font_size,
                classes.certificate_font_color
         FROM courses
         INNER JOIN classes
           ON courses.course_code = CONCAT('CLASS-', classes.id)
          AND classes.deleted_at IS NULL
         INNER JOIN learners
           ON learners.id = :learner_id_profile
          AND learners.deleted_at IS NULL
         LEFT JOIN course_enrollments
           ON course_enrollments.course_id = courses.id
          AND course_enrollments.learner_id = :learner_id_enrollment
          AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
          AND course_enrollments.deleted_at IS NULL
         WHERE {$where}
           AND courses.status = 'Active'
           AND courses.deleted_at IS NULL
           AND (
             learners.class_id = classes.id
             OR course_enrollments.id IS NOT NULL
           )
         LIMIT 1"
    );
    $statement->execute($params);
    $context = $statement->fetch();

    if (!$context) {
        return null;
    }

    $resolvedCourseId = (int) $context['course_id'];
    $resolvedClassId = (int) $context['class_id'];

    $countQuery = static function (string $sql, array $params = []) use ($pdo): int {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    };

    $evaluationReady = kiwiClassEvaluationColumnsReady(kiwiClassEvaluationColumns($pdo)) && kiwiClassEvaluationsTableReady($pdo);
    $certificateReady = kiwiClassCertificateReady(kiwiClassCertificateColumns($pdo)) && !empty($context['certificate_template_image']);

    return [
        'course_id' => $resolvedCourseId,
        'course_name' => (string) $context['course_name'],
        'class_id' => $resolvedClassId,
        'class_name' => (string) $context['class_name'],
        'topics_count' => $countQuery(
            'SELECT COUNT(*) FROM class_material_folders WHERE class_id = :class_id AND deleted_at IS NULL',
            ['class_id' => $resolvedClassId]
        ),
        'materials_count' => $countQuery(
            'SELECT COUNT(*) FROM class_materials WHERE class_id = :class_id AND deleted_at IS NULL',
            ['class_id' => $resolvedClassId]
        ),
        'quizzes_count' => $countQuery(
            'SELECT COUNT(*) FROM class_quizzes WHERE class_id = :class_id AND status = "Active" AND deleted_at IS NULL',
            ['class_id' => $resolvedClassId]
        ),
        'assignments_count' => $countQuery(
            'SELECT COUNT(*) FROM class_assignments WHERE class_id = :class_id AND status = "Active" AND deleted_at IS NULL',
            ['class_id' => $resolvedClassId]
        ),
        'grades_count' => $countQuery(
            'SELECT COUNT(*) FROM learner_grades WHERE class_id = :class_id AND learner_id = :learner_id AND deleted_at IS NULL',
            ['class_id' => $resolvedClassId, 'learner_id' => $learnerId]
        ),
        'evaluation_count' => $evaluationReady ? $countQuery(
            'SELECT COUNT(*) FROM class_evaluations WHERE class_id = :class_id AND learner_id = :learner_id AND deleted_at IS NULL',
            ['class_id' => $resolvedClassId, 'learner_id' => $learnerId]
        ) : 0,
        'classmates_count' => max(0, $countQuery(
            'SELECT COUNT(DISTINCT course_enrollments.learner_id)
             FROM course_enrollments
             INNER JOIN learners ON learners.id = course_enrollments.learner_id AND learners.deleted_at IS NULL
             WHERE course_enrollments.course_id = :course_id
               AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
               AND course_enrollments.deleted_at IS NULL',
            ['course_id' => $resolvedCourseId]
        ) - 1),
        'certificate_count' => $certificateReady ? 1 : 0,
        'evaluation_ready' => $evaluationReady,
        'certificate_ready' => $certificateReady,
    ];
}

function kiwiLearnerCourseSidebarModules(array $context, int $learnerId): array
{
    $courseId = (int) $context['course_id'];
    $classId = (int) $context['class_id'];

    return [
        ['key' => 'topics', 'label' => 'Topics', 'icon' => 'fa-list-check', 'count' => (int) $context['topics_count'], 'url' => 'learner_course.php?course_id=' . $courseId . '#topics'],
        ['key' => 'materials', 'label' => 'Materials', 'icon' => 'fa-folder-open', 'count' => (int) $context['materials_count'], 'url' => 'learner_course.php?course_id=' . $courseId . '#materials'],
        ['key' => 'quizzes', 'label' => 'Quizzes', 'icon' => 'fa-circle-question', 'count' => (int) $context['quizzes_count'], 'url' => 'learner_quizzes.php?course_id=' . $courseId],
        ['key' => 'assignments', 'label' => 'Assignments', 'icon' => 'fa-file-pen', 'count' => (int) $context['assignments_count'], 'url' => 'learner_assignments.php?course_id=' . $courseId],
        ['key' => 'grades', 'label' => 'Grades', 'icon' => 'fa-star', 'count' => (int) $context['grades_count'], 'url' => 'learner_grades.php?course_id=' . $courseId],
        ['key' => 'evaluation', 'label' => 'Evaluation', 'icon' => 'fa-clipboard-check', 'count' => (int) $context['evaluation_count'], 'url' => !empty($context['evaluation_ready']) ? 'learner_evaluation.php?course_id=' . $courseId : 'learner_course.php?course_id=' . $courseId . '#evaluation'],
        ['key' => 'classmates', 'label' => 'Classmates', 'icon' => 'fa-users', 'count' => (int) $context['classmates_count'], 'url' => 'learner_course.php?course_id=' . $courseId . '#classmates'],
        ['key' => 'certificates', 'label' => 'Certificates', 'icon' => 'fa-award', 'count' => (int) $context['certificate_count'], 'url' => !empty($context['certificate_ready']) ? 'certificate.php?class_id=' . $classId . '&learner_id=' . $learnerId : 'learner_course.php?course_id=' . $courseId . '#certificates'],
    ];
}

function kiwiRenderLearnerCourseSidebar(?array $context, string $learnerName, string $activeModule = '', int $learnerId = 0): void
{
    $dashboardUrl = $context ? 'learner_course.php?course_id=' . (int) $context['course_id'] : 'learner_dashboard.php';
    ?>
      <nav class="sidebar-nav">
        <a class="<?php echo $activeModule === 'dashboard' ? 'active' : ''; ?>" href="<?php echo kiwiSidebarEscape($dashboardUrl); ?>"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <?php if ($context): ?>
          <?php foreach (kiwiLearnerCourseSidebarModules($context, $learnerId) as $module): ?>
            <a class="<?php echo $activeModule === $module['key'] ? 'active' : ''; ?>" href="<?php echo kiwiSidebarEscape((string) $module['url']); ?>">
              <i class="fa-solid <?php echo kiwiSidebarEscape((string) $module['icon']); ?>"></i>
              <?php echo kiwiSidebarEscape((string) $module['label']); ?>
              <span class="sidebar-nav-count"><?php echo (int) $module['count']; ?></span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Logged in as</p>
        <strong><?php echo kiwiSidebarEscape($learnerName); ?></strong>
      </div>
    <?php
}
