<?php

$config = [
    // SMTP sender for Kiwi business email notifications.
    'host' => getenv('KIWI_SMTP_HOST') ?: 'smtp.hostinger.com',
    'port' => (int) (getenv('KIWI_SMTP_PORT') ?: 465),
    'encryption' => getenv('KIWI_SMTP_ENCRYPTION') ?: 'ssl',
    'username' => getenv('KIWI_SMTP_USERNAME') ?: 'info@kiwidigitaltech.com',
    'password' => getenv('KIWI_SMTP_PASSWORD') ?: '0>eXF~@$?lF',
    'from_email' => getenv('KIWI_MAIL_FROM_EMAIL') ?: 'info@kiwidigitaltech.com',
    'from_name' => getenv('KIWI_MAIL_FROM_NAME') ?: 'Kiwi Digital Tech',
];

$localConfigPath = __DIR__ . '/Mailer.local.php';

if (is_file($localConfigPath)) {
    // Mailer.local.php is ignored by Git so SMTP secrets stay out of the repository.
    $localConfig = require $localConfigPath;

    if (is_array($localConfig)) {
        $config = array_replace($config, array_filter($localConfig, static fn ($value): bool => $value !== null && $value !== ''));
    }
}

return $config;
