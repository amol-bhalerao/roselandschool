<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

function content_keys(): array
{
    return [
        'brand_tagline',
        'hero_eyebrow',
        'hero_title',
        'hero_text',
        'hero_primary_button',
        'hero_call_button',
        'admission_badge_title',
        'admission_badge_text',
        'stat_1_title',
        'stat_1_text',
        'stat_2_title',
        'stat_2_text',
        'stat_3_title',
        'stat_3_text',
        'about_eyebrow',
        'about_title',
        'about_text',
        'programs_eyebrow',
        'programs_title',
        'preprimary_title',
        'preprimary_text',
        'primary_title',
        'primary_text',
        'secondary_title',
        'secondary_text',
        'junior_title',
        'junior_text',
        'gallery_eyebrow',
        'gallery_title',
        'gallery_1_caption',
        'gallery_2_caption',
        'gallery_3_caption',
        'gallery_4_caption',
        'admission_eyebrow',
        'admission_title',
        'contact_eyebrow',
        'contact_title',
        'school_address',
        'school_email',
        'school_phone',
    ];
}

function read_content(): array
{
    $rows = db()->query('SELECT content_key, content_value FROM cms_content')->fetchAll();
    $content = [];

    foreach ($rows as $row) {
        $content[$row['content_key']] = $row['content_value'];
    }

    return $content;
}

if ($method === 'GET') {
    json_response(['content' => read_content()]);
}

if ($method === 'PUT') {
    require_admin();

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['content']) || !is_array($payload['content'])) {
        json_response(['message' => 'Invalid content data.'], 400);
    }

    $allowed = array_flip(content_keys());
    $statement = db()->prepare(
        'INSERT INTO cms_content (content_key, content_value)
         VALUES (:content_key, :content_value)
         ON DUPLICATE KEY UPDATE content_value = VALUES(content_value)'
    );

    foreach ($payload['content'] as $key => $value) {
        if (!isset($allowed[$key])) {
            continue;
        }

        $statement->execute([
            'content_key' => $key,
            'content_value' => trim((string) $value),
        ]);
    }

    json_response(['message' => 'Website content updated successfully.', 'content' => read_content()]);
}

json_response(['message' => 'Method not allowed.'], 405);
