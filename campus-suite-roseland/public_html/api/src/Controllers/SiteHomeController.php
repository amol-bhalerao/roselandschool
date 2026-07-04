<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SiteHomeController
{
    private const ROW_ID = 1;

    public function __construct(private Database $db)
    {
    }

    public function getPublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->loadOrDefaults();
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->loadOrDefaults();
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json($response, ['error' => 'Invalid JSON body'], 422);
        }
        $merged = $this->normalizePayload($body);
        $json = json_encode($merged, JSON_THROW_ON_ERROR);

        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'INSERT INTO site_home (id, content_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE content_json = VALUES(content_json)'
        );
        $st->execute([self::ROW_ID, $json]);

        $payload = $this->loadOrDefaults();
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @return array<string, mixed> */
    private function loadOrDefaults(): array
    {
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT content_json FROM site_home WHERE id = ?');
        $st->execute([self::ROW_ID]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->defaultContent();
        }
        $raw = $row['content_json'] ?? null;
        if ($raw === null || $raw === '') {
            return $this->defaultContent();
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return $this->defaultContent();
            }
            return $this->normalizePayload($decoded);
        } catch (\JsonException) {
            return $this->defaultContent();
        }
    }

    /** @param array<string, mixed> $in */
    private function normalizePayload(array $in): array
    {
        $d = $this->defaultContent();

        if (isset($in['hero']) && is_array($in['hero'])) {
            $h = $in['hero'];
            $d['hero']['title'] = trim((string) ($h['title'] ?? $d['hero']['title']));
            $d['hero']['subtitle'] = trim((string) ($h['subtitle'] ?? $d['hero']['subtitle']));
            $d['hero']['tagline'] = trim((string) ($h['tagline'] ?? $d['hero']['tagline']));
            $d['hero']['image_url'] = trim((string) ($h['image_url'] ?? $d['hero']['image_url']));
            $d['hero']['primary_cta_label'] = trim((string) ($h['primary_cta_label'] ?? $d['hero']['primary_cta_label']));
            $d['hero']['primary_cta_href'] = trim((string) ($h['primary_cta_href'] ?? $d['hero']['primary_cta_href']));
            $d['hero']['secondary_cta_label'] = trim((string) ($h['secondary_cta_label'] ?? $d['hero']['secondary_cta_label']));
            $d['hero']['secondary_cta_href'] = trim((string) ($h['secondary_cta_href'] ?? $d['hero']['secondary_cta_href']));
            if (isset($h['stats']) && is_array($h['stats'])) {
                $stats = [];
                foreach ($h['stats'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $stats[] = [
                        'label' => trim((string) ($row['label'] ?? '')),
                        'value' => trim((string) ($row['value'] ?? '')),
                    ];
                }
                $d['hero']['stats'] = $stats;
            }
        }

        if (isset($in['sections']) && is_array($in['sections'])) {
            $sections = [];
            foreach ($in['sections'] as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $id = trim((string) ($s['id'] ?? ''));
                if ($id === '') {
                    $id = bin2hex(random_bytes(6));
                }
                $var = (string) ($s['variant'] ?? 'default');
                if (!in_array($var, ['default', 'muted', 'accent'], true)) {
                    $var = 'default';
                }
                $sections[] = [
                    'id' => $id,
                    'heading' => trim((string) ($s['heading'] ?? '')),
                    'subheading' => trim((string) ($s['subheading'] ?? '')),
                    'body_html' => (string) ($s['body_html'] ?? ''),
                    'variant' => $var,
                ];
            }
            $d['sections'] = $sections;
        }

        if (array_key_exists('show_latest_posts', $in)) {
            $d['show_latest_posts'] = (bool) $in['show_latest_posts'];
        }
        if (isset($in['latest_posts_heading'])) {
            $d['latest_posts_heading'] = trim((string) $in['latest_posts_heading']);
        }
        if (isset($in['latest_posts_intro'])) {
            $d['latest_posts_intro'] = trim((string) $in['latest_posts_intro']);
        }

        return $d;
    }

    /** @return array<string, mixed> */
    private function defaultContent(): array
    {
        return [
            'hero' => [
                'title' => 'Late Baburao Patil Arts and Science College, Hingoli',
                'subtitle' => 'BA and B.Sc. programs with Arts, Science, student notices, admissions, events and campus updates in one public website.',
                'tagline' => 'Hingoli, Maharashtra | Arts and Science College',
                'image_url' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=1600&q=80&auto=format&fit=crop',
                'primary_cta_label' => 'Admissions',
                'primary_cta_href' => '/p/admissions',
                'secondary_cta_label' => 'News & events',
                'secondary_cta_href' => '/p/news-events',
                'stats' => [
                    ['label' => 'Programs', 'value' => 'BA / B.Sc.'],
                    ['label' => 'Science subjects', 'value' => '6'],
                    ['label' => 'Arts subjects', 'value' => '7'],
                    ['label' => 'Location', 'value' => 'Hingoli'],
                ],
            ],
            'sections' => [
                [
                    'id' => 'intro-mission',
                    'heading' => 'About the college',
                    'subheading' => 'A focused Arts and Science institution for Hingoli students',
                    'body_html' => '<p>Late Baburao Patil Arts and Science College provides undergraduate education in Arts and Science streams with academic guidance, student support, notices, admissions information and campus activities published through this website.</p>',
                    'variant' => 'default',
                ],
                [
                    'id' => 'programs-spotlight',
                    'heading' => 'Courses offered',
                    'subheading' => 'BA and B.Sc. with major subject choices',
                    'body_html' => '<p><strong>Science:</strong> Botany, Microbiology, Zoology, Chemistry, Physics and Mathematics.</p><p><strong>Arts:</strong> English, Hindi, Marathi, Political Science, Sociology, Geography and Economics.</p>',
                    'variant' => 'muted',
                ],
                [
                    'id' => 'campus-life',
                    'heading' => 'Student support and campus life',
                    'subheading' => 'Notices, activities and information for students and parents',
                    'body_html' => '<p>Students can follow admission notices, academic updates, events, gallery posts and important announcements. The admin team can update all website content from Web Studio without code changes.</p>',
                    'variant' => 'accent',
                ],
                [
                    'id' => 'admin-managed-campus-resources',
                    'heading' => 'Campus resources for students',
                    'subheading' => 'Managed from the admin home page editor',
                    'body_html' => '<p>The college website can highlight student services, IQAC updates, admissions, scholarships, examination notices and department resources from the admin panel.</p><ul><li>Important links and navbar menus are dynamic.</li><li>Home sections can be reordered or edited from CMS.</li><li>Posts, pages, gallery, events and carousel content remain admin-managed.</li></ul>',
                    'variant' => 'accent',
                ],
            ],
            'show_latest_posts' => true,
            'latest_posts_heading' => 'Latest from the college',
            'latest_posts_intro' => 'Announcements, notices and campus stories managed by the college admin team.',
        ];
    }

    private function json(ResponseInterface $response, array $payload, int $status = 400): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
