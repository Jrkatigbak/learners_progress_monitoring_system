<?php

function kiwiClassEvaluationColumns(PDO $pdo): array
{
    $columns = [];

    foreach ($pdo->query('DESCRIBE classes') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    return [
        'class_type' => isset($columns['class_type']),
        'seminar_title' => isset($columns['seminar_title']),
        'seminar_presenter' => isset($columns['seminar_presenter']),
        'seminar_date' => isset($columns['seminar_date']),
        'seminar_venue' => isset($columns['seminar_venue']),
    ];
}

function kiwiClassEvaluationColumnsReady(array $columns): bool
{
    foreach (['class_type', 'seminar_title', 'seminar_presenter', 'seminar_date', 'seminar_venue'] as $field) {
        if (empty($columns[$field])) {
            return false;
        }
    }

    return true;
}

function kiwiClassEvaluationsTableReady(PDO $pdo): bool
{
    $statement = $pdo->query("SHOW TABLES LIKE 'class_evaluations'");

    return (bool) $statement->fetchColumn();
}

function kiwiEvaluationRatingItems(): array
{
    return [
        'content' => [
            'title' => 'Content & Relevancy',
            'items' => [
                'content_objectives_clear' => 'The objectives were clear and well-defined.',
                'content_relevant' => 'The topic was highly relevant to my professional/personal needs.',
                'content_organized' => 'The material was organized and easy to follow.',
                'content_depth' => 'The depth and complexity of the content were appropriate.',
            ],
        ],
        'presenter' => [
            'title' => 'Presenter / Speaker',
            'items' => [
                'presenter_knowledge' => 'The presenter demonstrated extensive knowledge of the topic.',
                'presenter_style' => 'The presentation style was dynamic and engaging.',
                'presenter_questions' => 'Questions from the audience were handled effectively.',
                'presenter_pace' => 'The pace of the presentation was comfortable.',
            ],
        ],
        'logistics' => [
            'title' => 'Environment & Logistics',
            'items' => [
                'logistics_venue' => 'The venue/online platform was comfortable and accessible.',
                'logistics_technology' => 'The audio, video, and technology functioned smoothly.',
                'logistics_registration' => 'The registration process was seamless and efficient.',
                'logistics_materials' => 'Handouts and support materials were helpful.',
            ],
        ],
    ];
}

function kiwiEvaluationFormTitle(array $class): string
{
    return strcasecmp((string) ($class['class_type'] ?? 'Course'), 'Seminar') === 0
        ? 'Seminar Evaluation Form'
        : 'Course Evaluation Form';
}
