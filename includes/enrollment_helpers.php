<?php

function kiwiAppBaseUrl(): string
{
    // Build public links from the current host so localhost and the live domain both generate the right URL.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $basePath = $basePath === '.' ? '' : $basePath;

    return $scheme . '://' . $host . ($basePath ? $basePath : '');
}

function kiwiLoginUrl(): string
{
    return kiwiAppBaseUrl() . '/login.php';
}

function kiwiEnrollmentUrl(string $token): string
{
    return kiwiAppBaseUrl() . '/enroll.php?token=' . rawurlencode($token);
}

function kiwiGenerateUniqueToken(PDO $pdo, string $table, string $column): string
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :token");

    do {
        $token = bin2hex(random_bytes(16));
        $statement->execute(['token' => $token]);
    } while ((int) $statement->fetchColumn() > 0);

    return $token;
}

function kiwiGenerateLearnerNumber(PDO $pdo): string
{
    $prefix = 'LRN-' . date('Y') . '-';
    $statement = $pdo->prepare('SELECT COUNT(*) FROM learners WHERE learner_number = :learner_number');

    for ($counter = 1; $counter < 10000; $counter++) {
        $candidate = $prefix . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
        $statement->execute(['learner_number' => $candidate]);

        if ((int) $statement->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return $prefix . bin2hex(random_bytes(3));
}

function kiwiEnrollmentName(array $request): string
{
    return trim((string) $request['first_name'] . ' ' . (string) ($request['middle_name'] ?? '') . ' ' . (string) $request['last_name']);
}

function kiwiSendEnrollmentReceivedEmail(SmtpMailer $mailer, string $email, string $name, string $className): bool
{
    $subject = 'Class registration received';
    $textBody = "Hello {$name},\n\n"
        . "Your registration for {$className} has been received.\n\n"
        . "Please wait for the administrator to approve your enrollment.\n\n"
        . "Kiwi Digital Tech";
    $htmlBody = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Your registration for <strong>' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '</strong> has been received.</p>'
        . '<p>Please wait for the administrator to approve your enrollment.</p>'
        . '<p>Kiwi Digital Tech</p>';

    return $mailer->send($email, $name, $subject, $htmlBody, $textBody);
}

function kiwiSendEnrollmentApprovedEmail(SmtpMailer $mailer, string $email, string $name, string $className, string $password): bool
{
    $loginUrl = kiwiLoginUrl();
    $subject = 'Class enrollment approved';
    $textBody = "Hello {$name},\n\n"
        . "Your enrollment for {$className} has been approved.\n\n"
        . "Login link: {$loginUrl}\n"
        . "Email: {$email}\n"
        . "Password: {$password}\n\n"
        . "Please keep these credentials secure.\n\n"
        . "Kiwi Digital Tech";
    $htmlBody = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Your enrollment for <strong>' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '</strong> has been approved.</p>'
        . '<p><strong>Login link:</strong> <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a><br>'
        . '<strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Password:</strong> ' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>Please keep these credentials secure.</p>'
        . '<p>Kiwi Digital Tech</p>';

    return $mailer->send($email, $name, $subject, $htmlBody, $textBody);
}

function kiwiSendEnrollmentDisapprovedEmail(SmtpMailer $mailer, string $email, string $name, string $className): bool
{
    $subject = 'Class enrollment update';
    $textBody = "Hello {$name},\n\n"
        . "Your registration for {$className} was reviewed but was not approved at this time.\n\n"
        . "Kiwi Digital Tech";
    $htmlBody = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Your registration for <strong>' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '</strong> was reviewed but was not approved at this time.</p>'
        . '<p>Kiwi Digital Tech</p>';

    return $mailer->send($email, $name, $subject, $htmlBody, $textBody);
}
