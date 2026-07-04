<?php

declare(strict_types=1);

namespace BlogApi;

use BlogApi\Controllers\AuthController;
use BlogApi\Controllers\CarouselController;
use BlogApi\Controllers\CategoryController;
use BlogApi\Controllers\EventController;
use BlogApi\Controllers\ErpController;
use BlogApi\Controllers\GalleryController;
use BlogApi\Controllers\HighlightsController;
use BlogApi\Controllers\SiteChromeController;
use BlogApi\Controllers\SiteHomeController;
use BlogApi\Controllers\NavigationController;
use BlogApi\Controllers\PostController;
use BlogApi\Controllers\ProfileController;
use BlogApi\Controllers\SitePageController;
use BlogApi\Controllers\StatsController;
use BlogApi\Controllers\UploadController;
use BlogApi\Handlers\ApiJsonErrorHandler;
use BlogApi\Middleware\AuthMiddleware;
use BlogApi\Middleware\CorsMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class AppFactory
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();

        $app->options('/{routes:.+}', function ($request, $response) {
            return $response->withStatus(204);
        });

        $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container) {
            $group->get('/health', function ($request, $response) {
                $response->getBody()->write(json_encode(['status' => 'ok']));
                return $response->withHeader('Content-Type', 'application/json');
            });

            $group->get('/health/db', function ($request, $response) use ($container) {
                try {
                    $pdo = $container->get(\PDO::class);
                    $pdo->query('SELECT 1');
                    $response->getBody()->write(json_encode(['ok' => true, 'database' => 'connected']));
                } catch (\Throwable $e) {
                    $response->getBody()->write(json_encode([
                        'ok' => false,
                        'error' => $e->getMessage(),
                        'hint' => 'Start MySQL (e.g. docker compose up -d), match api/.env DB_* and run php api/scripts/seed-database.php',
                    ], JSON_THROW_ON_ERROR));
                    return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
                }
                return $response->withHeader('Content-Type', 'application/json');
            });

            $group->post('/auth/login', [AuthController::class, 'login']);
            $group->post('/auth/accept-invite', [AuthController::class, 'acceptInvite']);

            $group->get('/posts', [PostController::class, 'listPublic']);
            $group->get('/posts/{slug}', [PostController::class, 'getBySlugPublic']);
            $group->get('/categories', [CategoryController::class, 'listPublic']);
            $group->post('/enquiries', [ErpController::class, 'createPublicEnquiry']);
            $group->post('/admission-applications', [ErpController::class, 'createPublicAdmissionApplication']);
            $group->post('/public-admissions', [ErpController::class, 'createPublicAdmissionApplicationLite']);
            $group->get('/admission-masters', [ErpController::class, 'publicAdmissionMasters']);
            $group->get('/pincode/{pin:[0-9]{6}}', function ($request, $response, array $args) {
                $pin = (string) ($args['pin'] ?? '');
                $url = 'https://api.postalpincode.in/pincode/' . rawurlencode($pin);
                $raw = null;
                if (function_exists('curl_init')) {
                    $curl = curl_init($url);
                    curl_setopt_array($curl, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 8,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_USERAGENT => 'CampusSuite/1.0',
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                    ]);
                    $raw = curl_exec($curl);
                    curl_close($curl);
                }
                if (!is_string($raw) || $raw === '') {
                    $raw = @file_get_contents($url, false, stream_context_create([
                        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                        'http' => ['timeout' => 8, 'header' => "User-Agent: CampusSuite/1.0\r\n"],
                    ]));
                }
                $payload = is_string($raw) ? json_decode($raw, true) : null;
                $postOffices = is_array($payload[0]['PostOffice'] ?? null) ? $payload[0]['PostOffice'] : [];
                $places = [];
                foreach ($postOffices as $office) {
                    if (!is_array($office)) {
                        continue;
                    }
                    $name = trim((string) ($office['Name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $places[] = [
                        'name' => $name,
                        'district' => trim((string) ($office['District'] ?? '')),
                        'state' => trim((string) ($office['State'] ?? '')),
                        'taluka' => trim((string) ($office['Block'] ?? $office['Taluk'] ?? $office['Division'] ?? '')),
                    ];
                }
                $response->getBody()->write(json_encode([
                    'data' => $places,
                    'message' => $places === [] ? 'No address details found for this pincode' : null,
                ], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json');
            });
            $group->get('/navigation', [NavigationController::class, 'treePublic']);
            $group->get('/carousel', [CarouselController::class, 'listPublic']);
            $group->get('/gallery', [GalleryController::class, 'listPublic']);
            $group->get('/events', [EventController::class, 'listPublic']);
            $group->get('/events/{slug}', [EventController::class, 'getBySlugPublic']);
            $group->get('/chrome', [SiteChromeController::class, 'getPublic']);
            $group->get('/home', [SiteHomeController::class, 'getPublic']);
            $group->get('/highlights', [HighlightsController::class, 'listPublic']);
            $group->get('/pages/{slug}', [SitePageController::class, 'getBySlugPublic']);

            $group->group('/admin', function (RouteCollectorProxy $admin) {
                $admin->get('/stats', [StatsController::class, 'overview']);

                $admin->get('/erp/summary', [ErpController::class, 'summary']);
                $admin->get('/erp/admissions', [ErpController::class, 'admissions']);
                $admin->post('/erp/admissions', [ErpController::class, 'createAdmission']);
                $admin->put('/erp/admissions/{id:[0-9]+}', [ErpController::class, 'updateAdmission']);
                $admin->put('/erp/admissions/{id:[0-9]+}/advance', [ErpController::class, 'advanceAdmission']);
                $admin->put('/erp/admissions/{id:[0-9]+}/documents', [ErpController::class, 'saveAdmissionDocuments']);
                $admin->put('/erp/admissions/{id:[0-9]+}/convert', [ErpController::class, 'convertAdmission']);
                $admin->get('/erp/users', [ErpController::class, 'users']);
                $admin->post('/erp/users/invite', [ErpController::class, 'inviteUser']);
                $admin->get('/erp/reports', [ErpController::class, 'reports']);
                  $admin->post('/erp/reports/generate', [ErpController::class, 'generateReport']);
                  $admin->get('/erp/masters', [ErpController::class, 'masters']);
                  $admin->post('/erp/masters/classes', [ErpController::class, 'saveMasterClass']);
                  $admin->post('/erp/masters/courses', [ErpController::class, 'saveMasterCourse']);
                  $admin->post('/erp/masters/faculties', [ErpController::class, 'saveMasterFaculty']);
                  $admin->post('/erp/masters/sections', [ErpController::class, 'saveMasterSection']);
                  $admin->post('/erp/masters/subjects', [ErpController::class, 'saveMasterSubject']);
                  $admin->post('/erp/masters/section-subjects', [ErpController::class, 'saveMasterSectionSubject']);
                  $admin->post('/erp/masters/fees', [ErpController::class, 'saveMasterFee']);
                  $admin->post('/erp/masters/routes', [ErpController::class, 'saveMasterRoute']);
                  $admin->post('/erp/masters/hostels', [ErpController::class, 'saveMasterHostel']);
                  $admin->post('/erp/masters/documents', [ErpController::class, 'saveMasterDocument']);
                  $admin->get('/erp/records', [ErpController::class, 'savedRecords']);
                $admin->post('/erp/records', [ErpController::class, 'saveRecord']);
                $admin->put('/erp/records/{id}', [ErpController::class, 'reviewRecord']);
                $admin->get('/erp/finance', [ErpController::class, 'finance']);
                $admin->post('/erp/finance/payments', [ErpController::class, 'collectFeePayment']);
                $admin->post('/erp/finance/fee-heads', [ErpController::class, 'createFeeHead']);
                $admin->post('/erp/finance/class-fees', [ErpController::class, 'assignClassFees']);

                $adminOnly = function ($request, $handler) {
                    if ((string) $request->getAttribute('user_role') !== 'admin') {
                        $response = new \Slim\Psr7\Response(403);
                        $response->getBody()->write(json_encode(['error' => 'Admin role required'], JSON_THROW_ON_ERROR));
                        return $response->withHeader('Content-Type', 'application/json');
                    }
                    return $handler->handle($request);
                };

                $admin->group('', function (RouteCollectorProxy $cms) {
                $cms->get('/posts', [PostController::class, 'listAdmin']);
                $cms->post('/posts', [PostController::class, 'create']);
                $cms->get('/posts/id/{id:[0-9]+}', [PostController::class, 'getById']);
                $cms->put('/posts/{id:[0-9]+}', [PostController::class, 'update']);
                $cms->delete('/posts/{id:[0-9]+}', [PostController::class, 'delete']);

                $cms->get('/categories', [CategoryController::class, 'listAdmin']);
                $cms->post('/categories', [CategoryController::class, 'create']);
                $cms->put('/categories/{id:[0-9]+}', [CategoryController::class, 'update']);
                $cms->delete('/categories/{id:[0-9]+}', [CategoryController::class, 'delete']);
                $cms->get('/page-topics', [CategoryController::class, 'pageTopicsAdmin']);
                $cms->put('/page-topics', [CategoryController::class, 'updatePageTopicsAdmin']);

                $cms->get('/site-pages', [SitePageController::class, 'listAdmin']);
                $cms->get('/site-pages/slug/{slug}', [SitePageController::class, 'getAdminBySlug']);
                $cms->get('/site-pages/{id:[0-9]+}', [SitePageController::class, 'getAdmin']);
                $cms->post('/site-pages', [SitePageController::class, 'create']);
                $cms->put('/site-pages/{id:[0-9]+}', [SitePageController::class, 'update']);
                $cms->delete('/site-pages/{id:[0-9]+}', [SitePageController::class, 'delete']);

                $cms->get('/navigation', [NavigationController::class, 'listAdmin']);
                $cms->post('/navigation', [NavigationController::class, 'create']);
                $cms->put('/navigation/{id:[0-9]+}', [NavigationController::class, 'update']);
                $cms->delete('/navigation/{id:[0-9]+}', [NavigationController::class, 'delete']);

                $cms->get('/carousel', [CarouselController::class, 'listAdmin']);
                $cms->post('/carousel', [CarouselController::class, 'create']);
                $cms->put('/carousel/{id:[0-9]+}', [CarouselController::class, 'update']);
                $cms->delete('/carousel/{id:[0-9]+}', [CarouselController::class, 'delete']);

                $cms->get('/highlights', [HighlightsController::class, 'adminGet']);
                $cms->put('/highlights', [HighlightsController::class, 'updateAdmin']);

                $cms->get('/gallery', [GalleryController::class, 'listAdmin']);
                $cms->post('/gallery', [GalleryController::class, 'create']);
                $cms->put('/gallery/reorder', [GalleryController::class, 'reorder']);
                $cms->put('/gallery/{id:[0-9]+}', [GalleryController::class, 'update']);
                $cms->delete('/gallery/{id:[0-9]+}', [GalleryController::class, 'delete']);

                $cms->get('/events', [EventController::class, 'listAdmin']);
                $cms->get('/events/{id:[0-9]+}', [EventController::class, 'getAdmin']);
                $cms->post('/events', [EventController::class, 'create']);
                $cms->put('/events/{id:[0-9]+}', [EventController::class, 'update']);
                $cms->delete('/events/{id:[0-9]+}', [EventController::class, 'delete']);

                $cms->get('/chrome', [SiteChromeController::class, 'getAdmin']);
                $cms->put('/chrome', [SiteChromeController::class, 'updateAdmin']);

                $cms->get('/home', [SiteHomeController::class, 'getAdmin']);
                $cms->put('/home', [SiteHomeController::class, 'updateAdmin']);

                $cms->post('/uploads', [UploadController::class, 'upload']);
                $cms->post('/uploads/gallery', [UploadController::class, 'uploadGallery']);
                $cms->post('/uploads/pdf', [UploadController::class, 'uploadPdf']);
                })->add($adminOnly);

                $admin->get('/me', [ProfileController::class, 'me']);
                $admin->put('/me', [ProfileController::class, 'updateProfile']);
                $admin->put('/me/password', [ProfileController::class, 'updatePassword']);
            })->add($container->get(AuthMiddleware::class));
        });

        $app->addRoutingMiddleware();
        $displayDetails = filter_var($_ENV['APP_DEBUG'] ?? '0', FILTER_VALIDATE_BOOLEAN);
        $errorMiddleware = $app->addErrorMiddleware($displayDetails, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            new ApiJsonErrorHandler($app->getResponseFactory())
        );
        $app->add(new CorsMiddleware());
    }
}
