<?php

function kiwiDefaultSystemSettings(): array
{
    return [
        'brand_name' => 'Kiwi Digital',
        'system_name' => 'Learners Progress Monitoring System',
        'logo_path' => 'images/kiwi-logo.png',
        'colors' => [
            'primary' => '#f58220',
            'primary_dark' => '#d96500',
            'success' => '#4c8f17',
            'success_dark' => '#2f6f11',
            'warning' => '#f7c84b',
            'danger' => '#dc3545',
            'text' => '#252019',
            'muted_text' => '#7d756c',
            'soft_background' => '#fffaf2',
            'border' => '#f0e4d2',
        ],
    ];
}

function kiwiSystemSettingsPath(): string
{
    return __DIR__ . '/../config/system_settings.json';
}

function kiwiSystemSettings(): array
{
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    $defaults = kiwiDefaultSystemSettings();
    $path = kiwiSystemSettingsPath();
    $saved = [];

    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        $saved = is_array($decoded) ? $decoded : [];
    }

    $settings = array_replace_recursive($defaults, $saved);

    return $settings;
}

function kiwiSaveSystemSettings(array $settings): void
{
    $path = kiwiSystemSettingsPath();
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function kiwiSystemBrandName(): string
{
    return (string) kiwiSystemSettings()['brand_name'];
}

function kiwiSystemName(): string
{
    return (string) kiwiSystemSettings()['system_name'];
}

function kiwiSystemLogo(): string
{
    return (string) kiwiSystemSettings()['logo_path'];
}

function kiwiSanitizeHexColor(string $color, string $fallback): string
{
    $color = trim($color);

    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
}

function kiwiSystemThemeStyle(): string
{
    $settings = kiwiSystemSettings();
    $colors = $settings['colors'];

    // Settings are emitted as CSS variables so the existing design system updates globally.
    return '<style id="systemSettingsTheme">'
        . ':root{'
        . '--brand:' . htmlspecialchars($colors['primary'], ENT_QUOTES, 'UTF-8') . ';'
        . '--brand-dark:' . htmlspecialchars($colors['primary_dark'], ENT_QUOTES, 'UTF-8') . ';'
        . '--green:' . htmlspecialchars($colors['success'], ENT_QUOTES, 'UTF-8') . ';'
        . '--green-dark:' . htmlspecialchars($colors['success_dark'], ENT_QUOTES, 'UTF-8') . ';'
        . '--yellow:' . htmlspecialchars($colors['warning'], ENT_QUOTES, 'UTF-8') . ';'
        . '--danger:' . htmlspecialchars($colors['danger'], ENT_QUOTES, 'UTF-8') . ';'
        . '--ink:' . htmlspecialchars($colors['text'], ENT_QUOTES, 'UTF-8') . ';'
        . '--muted:' . htmlspecialchars($colors['muted_text'], ENT_QUOTES, 'UTF-8') . ';'
        . '--soft:' . htmlspecialchars($colors['soft_background'], ENT_QUOTES, 'UTF-8') . ';'
        . '--surface-alt:' . htmlspecialchars($colors['soft_background'], ENT_QUOTES, 'UTF-8') . ';'
        . '--line:' . htmlspecialchars($colors['border'], ENT_QUOTES, 'UTF-8') . ';'
        . '}'
        . '</style>';
}
