<?php

function kiwiClassCertificateColumns(PDO $pdo): array
{
    $columns = [];

    foreach ($pdo->query('DESCRIBE classes') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    return [
        'certificate_template_image' => isset($columns['certificate_template_image']),
        'certificate_name_x' => isset($columns['certificate_name_x']),
        'certificate_name_y' => isset($columns['certificate_name_y']),
        'certificate_font_size' => isset($columns['certificate_font_size']),
        'certificate_font_color' => isset($columns['certificate_font_color']),
    ];
}

function kiwiClassCertificateReady(array $columns): bool
{
    foreach (['certificate_template_image', 'certificate_name_x', 'certificate_name_y', 'certificate_font_size', 'certificate_font_color'] as $field) {
        if (empty($columns[$field])) {
            return false;
        }
    }

    return true;
}

function kiwiCertificateFontPath(): string
{
    $candidates = [
        __DIR__ . '/../assets/fonts/GreatVibes-Regular.ttf',
        '/System/Library/Fonts/Supplemental/SignPainter.ttc',
        '/System/Library/Fonts/Supplemental/Brush Script.ttf',
        __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function kiwiCertificateHexColor(string $hex): array
{
    $hex = ltrim(trim($hex), '#');

    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '1f1a17';
    }

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function kiwiCertificateImageFromPath(string $path)
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (in_array($extension, ['jpg', 'jpeg'], true)) {
        return imagecreatefromjpeg($path);
    }

    if ($extension === 'png') {
        return imagecreatefrompng($path);
    }

    if ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
        return imagecreatefromwebp($path);
    }

    return false;
}

function kiwiCertificateLearnerName(array $learner): string
{
    $name = trim((string) ($learner['first_name'] ?? '') . ' ' . (string) ($learner['middle_name'] ?? '') . ' ' . (string) ($learner['last_name'] ?? ''));
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;

    if ($name === '') {
        return 'Learner';
    }

    $lowerName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

    return preg_replace_callback('/\p{L}[\p{L}\p{Mn}\'-]*/u', static function (array $matches): string {
        $word = $matches[0];

        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
        }

        return strtoupper(substr($word, 0, 1)) . substr($word, 1);
    }, $lowerName) ?? $lowerName;
}

function kiwiRenderCertificateImage(string $templatePath, string $learnerName, float $nameX, float $nameY, int $fontSize, string $fontColor)
{
    if (!extension_loaded('gd') || !is_file($templatePath)) {
        return false;
    }

    $image = kiwiCertificateImageFromPath($templatePath);

    if (!$image) {
        return false;
    }

    imagealphablending($image, true);
    imagesavealpha($image, true);

    $width = imagesx($image);
    $height = imagesy($image);
    [$red, $green, $blue] = kiwiCertificateHexColor($fontColor);
    $color = imagecolorallocate($image, $red, $green, $blue);
    $font = kiwiCertificateFontPath();
    $fontSize = max(12, min(220, $fontSize));
    $targetWidth = $width * 0.94;
    $centerX = ($width * max(0, min(100, $nameX))) / 100;
    $centerY = ($height * max(0, min(100, $nameY))) / 100;

    if ($font !== '') {
        while ($fontSize > 12) {
            $box = imagettfbbox($fontSize, 0, $font, $learnerName);
            $textWidth = max($box[0], $box[2]) - min($box[0], $box[2]);

            if ($textWidth <= $targetWidth) {
                break;
            }

            $fontSize -= 2;
        }

        $box = imagettfbbox($fontSize, 0, $font, $learnerName);
        $x = (int) round($centerX - (($box[0] + $box[2]) / 2));
        $y = (int) round($centerY - (($box[1] + $box[7]) / 2));
        imagettftext($image, $fontSize, 0, $x, $y, $color, $font, $learnerName);

        return $image;
    }

    $fallbackFont = 5;
    $textWidth = imagefontwidth($fallbackFont) * strlen($learnerName);
    $textHeight = imagefontheight($fallbackFont);
    imagestring($image, $fallbackFont, (int) round($centerX - ($textWidth / 2)), (int) round($centerY - ($textHeight / 2)), $learnerName, $color);

    return $image;
}

function kiwiOutputCertificatePng($image): string
{
    ob_start();
    imagepng($image);
    $contents = (string) ob_get_clean();
    imagedestroy($image);

    return $contents;
}
