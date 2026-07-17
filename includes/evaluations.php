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
        'evaluation_enabled' => isset($columns['evaluation_enabled']),
        'evaluation_form_json' => isset($columns['evaluation_form_json']),
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

function kiwiEvaluationEnabled(array $class, array $columns): bool
{
    if (empty($columns['evaluation_enabled'])) {
        return true;
    }

    return (int) ($class['evaluation_enabled'] ?? 1) === 1;
}

function kiwiClassEvaluationsTableReady(PDO $pdo): bool
{
    $statement = $pdo->query("SHOW TABLES LIKE 'class_evaluations'");

    return (bool) $statement->fetchColumn();
}

function kiwiClassEvaluationResponseColumns(PDO $pdo): array
{
    if (!kiwiClassEvaluationsTableReady($pdo)) {
        return [];
    }

    $columns = [];

    foreach ($pdo->query('DESCRIBE class_evaluations') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    return [
        'rating_answers_json' => isset($columns['rating_answers_json']),
        'feedback_answers_json' => isset($columns['feedback_answers_json']),
    ];
}

function kiwiEvaluationRatingFieldNames(): array
{
    $fieldNames = [];

    foreach (kiwiEvaluationRatingItems() as $section) {
        foreach ($section['items'] as $fieldName => $_label) {
            $fieldNames[] = $fieldName;
        }
    }

    return $fieldNames;
}

function kiwiClassEvaluationRequiredTableColumns(): array
{
    return array_merge([
        'id',
        'class_id',
        'learner_id',
    ], kiwiEvaluationRatingFieldNames(), [
        'overall_rating',
        'recommend',
        'feedback_useful',
        'feedback_improvements',
        'feedback_topics',
        'attendee_name',
        'attendee_email',
        'created_at',
        'updated_at',
        'deleted_at',
    ]);
}

function kiwiClassEvaluationTableColumns(PDO $pdo): array
{
    if (!kiwiClassEvaluationsTableReady($pdo)) {
        return [];
    }

    $columns = [];

    foreach ($pdo->query('DESCRIBE class_evaluations') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    return $columns;
}

function kiwiClassEvaluationMissingTableColumns(array $columns): array
{
    $missing = [];

    foreach (kiwiClassEvaluationRequiredTableColumns() as $fieldName) {
        if (empty($columns[$fieldName])) {
            $missing[] = $fieldName;
        }
    }

    return $missing;
}

function kiwiClassEvaluationTableColumnsReady(array $columns): bool
{
    return kiwiClassEvaluationMissingTableColumns($columns) === [];
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

function kiwiDefaultEvaluationFormConfig(): array
{
    $sections = [];

    foreach (kiwiEvaluationRatingItems() as $section) {
        $questions = [];

        foreach ($section['items'] as $label) {
            $questions[] = (string) $label;
        }

        $sections[] = [
            'title' => (string) $section['title'],
            'questions' => $questions,
        ];
    }

    return [
        'title' => '',
        'intro' => 'Thank you for participating. Your feedback helps us improve future events. Please rate each item by checking the number that best matches your experience.',
        'rating_scale' => [
            5 => 'Strongly Agree (Excellent)',
            4 => 'Agree (Good)',
            3 => 'Neutral (Satisfactory)',
            2 => 'Disagree (Poor)',
            1 => 'Strongly Disagree (Very Poor)',
        ],
        'sections' => $sections,
        'overall_question' => 'Overall, how would you rate this {type}?',
        'overall_options' => ['Excellent', 'Good', 'Satisfactory', 'Unsatisfactory'],
        'recommend_question' => 'Would you recommend this {type} to colleagues?',
        'recommend_options' => ['Yes', 'No', 'Maybe'],
        'feedback_questions' => [
            'useful' => 'What did you find most useful or valuable?',
            'improvements' => 'What specific improvements could be made?',
            'topics' => 'What related topics would you like covered in the future?',
        ],
    ];
}

function kiwiNormalizeEvaluationFormConfig(?array $config = null): array
{
    $default = kiwiDefaultEvaluationFormConfig();
    $config = is_array($config) ? $config : [];

    $normalized = [
        'title' => trim((string) ($config['title'] ?? $default['title'])),
        'intro' => trim((string) ($config['intro'] ?? $default['intro'])),
        'rating_scale' => [],
        'sections' => [],
        'overall_question' => trim((string) ($config['overall_question'] ?? $default['overall_question'])),
        'overall_options' => [],
        'recommend_question' => trim((string) ($config['recommend_question'] ?? $default['recommend_question'])),
        'recommend_options' => [],
        'feedback_questions' => [],
    ];

    $ratingScale = is_array($config['rating_scale'] ?? null) ? $config['rating_scale'] : $default['rating_scale'];
    for ($score = 5; $score >= 1; $score--) {
        $label = trim((string) ($ratingScale[$score] ?? $ratingScale[(string) $score] ?? $default['rating_scale'][$score]));
        $normalized['rating_scale'][$score] = $label !== '' ? $label : $default['rating_scale'][$score];
    }

    $sections = is_array($config['sections'] ?? null) ? $config['sections'] : $default['sections'];
    foreach ($sections as $section) {
        $title = trim((string) ($section['title'] ?? ''));
        $questions = [];

        foreach ((array) ($section['questions'] ?? []) as $question) {
            $question = trim((string) $question);

            if ($question !== '') {
                $questions[] = $question;
            }
        }

        if ($title !== '' && $questions) {
            $normalized['sections'][] = [
                'title' => $title,
                'questions' => array_slice($questions, 0, 20),
            ];
        }
    }

    if (!$normalized['sections']) {
        $normalized['sections'] = $default['sections'];
    }

    foreach ((array) ($config['overall_options'] ?? $default['overall_options']) as $option) {
        $option = trim((string) $option);

        if ($option !== '') {
            $normalized['overall_options'][] = $option;
        }
    }

    if (!$normalized['overall_options']) {
        $normalized['overall_options'] = $default['overall_options'];
    }

    foreach ((array) ($config['recommend_options'] ?? $default['recommend_options']) as $option) {
        $option = trim((string) $option);

        if ($option !== '') {
            $normalized['recommend_options'][] = $option;
        }
    }

    if (!$normalized['recommend_options']) {
        $normalized['recommend_options'] = $default['recommend_options'];
    }

    $feedbackQuestions = is_array($config['feedback_questions'] ?? null) ? $config['feedback_questions'] : $default['feedback_questions'];
    foreach (['useful', 'improvements', 'topics'] as $key) {
        $label = trim((string) ($feedbackQuestions[$key] ?? $default['feedback_questions'][$key]));
        $normalized['feedback_questions'][$key] = $label !== '' ? $label : $default['feedback_questions'][$key];
    }

    return $normalized;
}

function kiwiEvaluationFormConfig(array $class): array
{
    $rawConfig = trim((string) ($class['evaluation_form_json'] ?? ''));
    $decoded = null;

    if ($rawConfig !== '') {
        $decoded = json_decode($rawConfig, true);
    }

    return kiwiNormalizeEvaluationFormConfig(is_array($decoded) ? $decoded : null);
}

function kiwiEvaluationFormConfigFromPost(array $post): array
{
    $sections = [];
    $sectionTitles = (array) ($post['evaluation_section_title'] ?? []);
    $sectionQuestions = (array) ($post['evaluation_section_questions'] ?? []);

    foreach ($sectionTitles as $index => $title) {
        $title = trim((string) $title);
        $lines = preg_split('/\R+/', (string) ($sectionQuestions[$index] ?? '')) ?: [];
        $questions = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                $questions[] = $line;
            }
        }

        if ($title !== '' && $questions) {
            $sections[] = [
                'title' => $title,
                'questions' => $questions,
            ];
        }
    }

    $splitLines = static function (string $value): array {
        $items = [];

        foreach (preg_split('/\R+/', $value) ?: [] as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items;
    };

    return kiwiNormalizeEvaluationFormConfig([
        'title' => trim((string) ($post['evaluation_form_title'] ?? '')),
        'intro' => trim((string) ($post['evaluation_intro'] ?? '')),
        'rating_scale' => [
            5 => trim((string) ($post['rating_scale_5'] ?? '')),
            4 => trim((string) ($post['rating_scale_4'] ?? '')),
            3 => trim((string) ($post['rating_scale_3'] ?? '')),
            2 => trim((string) ($post['rating_scale_2'] ?? '')),
            1 => trim((string) ($post['rating_scale_1'] ?? '')),
        ],
        'sections' => $sections,
        'overall_question' => trim((string) ($post['overall_question'] ?? '')),
        'overall_options' => $splitLines((string) ($post['overall_options'] ?? '')),
        'recommend_question' => trim((string) ($post['recommend_question'] ?? '')),
        'recommend_options' => $splitLines((string) ($post['recommend_options'] ?? '')),
        'feedback_questions' => [
            'useful' => trim((string) ($post['feedback_question_useful'] ?? '')),
            'improvements' => trim((string) ($post['feedback_question_improvements'] ?? '')),
            'topics' => trim((string) ($post['feedback_question_topics'] ?? '')),
        ],
    ]);
}

function kiwiEvaluationQuestionField(int $sectionIndex, int $questionIndex): string
{
    return 'rating_' . $sectionIndex . '_' . $questionIndex;
}

function kiwiEvaluationLegacyRatingValues(array $ratingAnswers): array
{
    $legacyFields = kiwiEvaluationRatingFieldNames();
    $values = [];
    $ratings = array_values($ratingAnswers);

    foreach ($legacyFields as $index => $fieldName) {
        $values[$fieldName] = (int) ($ratings[$index] ?? 1);
    }

    return $values;
}

function kiwiEvaluationResponseRatingRows(array $response, array $config): array
{
    $decodedAnswers = json_decode((string) ($response['rating_answers_json'] ?? ''), true);
    $hasDynamicAnswers = is_array($decodedAnswers);
    $legacyFields = kiwiEvaluationRatingFieldNames();
    $legacyIndex = 0;
    $rows = [];

    foreach ((array) ($config['sections'] ?? []) as $sectionIndex => $section) {
        $questionRows = [];

        foreach ((array) ($section['questions'] ?? []) as $questionIndex => $label) {
            $field = kiwiEvaluationQuestionField((int) $sectionIndex, (int) $questionIndex);
            $legacyField = $legacyFields[$legacyIndex] ?? '';
            $score = $hasDynamicAnswers
                ? ($decodedAnswers[$field] ?? null)
                : ($legacyField !== '' ? ($response[$legacyField] ?? null) : null);

            $questionRows[] = [
                'label' => (string) $label,
                'score' => $score !== null ? (int) $score : null,
            ];
            $legacyIndex++;
        }

        if ($questionRows) {
            $rows[] = [
                'title' => (string) ($section['title'] ?? ''),
                'questions' => $questionRows,
            ];
        }
    }

    return $rows;
}

function kiwiEvaluationResponseAverage(array $response, array $config): float
{
    $total = 0;
    $count = 0;

    foreach (kiwiEvaluationResponseRatingRows($response, $config) as $section) {
        foreach ($section['questions'] as $question) {
            if ($question['score'] !== null) {
                $total += (int) $question['score'];
                $count++;
            }
        }
    }

    return $count > 0 ? round($total / $count, 2) : 0.0;
}

function kiwiEvaluationResponseFeedback(array $response): array
{
    $decoded = json_decode((string) ($response['feedback_answers_json'] ?? ''), true);

    if (is_array($decoded)) {
        return [
            'useful' => (string) ($decoded['useful'] ?? ''),
            'improvements' => (string) ($decoded['improvements'] ?? ''),
            'topics' => (string) ($decoded['topics'] ?? ''),
        ];
    }

    return [
        'useful' => (string) ($response['feedback_useful'] ?? ''),
        'improvements' => (string) ($response['feedback_improvements'] ?? ''),
        'topics' => (string) ($response['feedback_topics'] ?? ''),
    ];
}

function kiwiEvaluationFormTitle(array $class): string
{
    return strcasecmp((string) ($class['class_type'] ?? 'Course'), 'Seminar') === 0
        ? 'Seminar Evaluation Form'
        : 'Course Evaluation Form';
}
