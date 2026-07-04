<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class UploadController
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Gallery videos — larger limit */
    private const MAX_GALLERY_VIDEO_BYTES = 50 * 1024 * 1024;

    private const MAX_PDF_BYTES = 20 * 1024 * 1024;

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        if (!isset($files['file'])) {
            return $this->json($response, ['error' => 'Missing file field "file"'], 422);
        }

        /** @var UploadedFileInterface $file */
        $file = $files['file'];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'Upload failed (code ' . $file->getError() . ')'], 400);
        }

        $size = $file->getSize() ?? 0;
        if ($size > self::MAX_BYTES) {
            return $this->json($response, ['error' => 'File too large (max 5 MB)'], 413);
        }

        $mime = (string) $file->getClientMediaType();
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            return $this->json($response, ['error' => 'Only JPEG, PNG, WebP, GIF allowed'], 415);
        }

        $ext = $allowed[$mime];
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $root = dirname(__DIR__, 2);
        $dir = $root . '/public/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->json($response, ['error' => 'Cannot create upload directory'], 500);
        }

        $target = $dir . DIRECTORY_SEPARATOR . $name;
        $file->moveTo($target);

        $url = '/uploads/' . $name;
        $response->getBody()->write(json_encode(['url' => $url], JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /** Image or video for gallery lightbox (JPEG/PNG/WebP/GIF + MP4/WebM). */
    public function uploadGallery(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        if (!isset($files['file'])) {
            return $this->json($response, ['error' => 'Missing file field "file"'], 422);
        }

        /** @var UploadedFileInterface $file */
        $file = $files['file'];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'Upload failed (code ' . $file->getError() . ')'], 400);
        }

        $size = $file->getSize() ?? 0;
        $mime = (string) $file->getClientMediaType();

        $allowed = [
            'image/jpeg' => ['jpg', self::MAX_BYTES],
            'image/png' => ['png', self::MAX_BYTES],
            'image/webp' => ['webp', self::MAX_BYTES],
            'image/gif' => ['gif', self::MAX_BYTES],
            'video/mp4' => ['mp4', self::MAX_GALLERY_VIDEO_BYTES],
            'video/webm' => ['webm', self::MAX_GALLERY_VIDEO_BYTES],
        ];
        if (!isset($allowed[$mime])) {
            return $this->json($response, ['error' => 'Allowed: JPEG, PNG, WebP, GIF, MP4, WebM'], 415);
        }

        [$ext, $maxBytes] = $allowed[$mime];
        if ($size > $maxBytes) {
            return $this->json($response, ['error' => 'File too large'], 413);
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $root = dirname(__DIR__, 2);
        $dir = $root . '/public/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->json($response, ['error' => 'Cannot create upload directory'], 500);
        }

        $target = $dir . DIRECTORY_SEPARATOR . $name;
        $file->moveTo($target);

        $url = '/uploads/' . $name;
        $hint = strncmp($mime, 'video/', 8) === 0 ? 'video' : 'image';
        $response->getBody()->write(json_encode(['url' => $url, 'media_hint' => $hint], JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /** PDF attachment for posts (embedded on public post page). */
    public function uploadPdf(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        if (!isset($files['file'])) {
            return $this->json($response, ['error' => 'Missing file field "file"'], 422);
        }

        /** @var UploadedFileInterface $file */
        $file = $files['file'];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'Upload failed (code ' . $file->getError() . ')'], 400);
        }

        $size = $file->getSize() ?? 0;
        if ($size > self::MAX_PDF_BYTES) {
            return $this->json($response, ['error' => 'PDF too large (max 20 MB)'], 413);
        }

        $mime = (string) $file->getClientMediaType();
        $clientName = (string) $file->getClientFilename();
        $isPdfMime = $mime === 'application/pdf' || $mime === 'application/x-pdf';
        $isPdfName = $clientName !== '' && str_ends_with(strtolower($clientName), '.pdf');
        if (!$isPdfMime && !$isPdfName) {
            return $this->json($response, ['error' => 'Only PDF files allowed'], 415);
        }

        $name = bin2hex(random_bytes(16)) . '.pdf';
        $root = dirname(__DIR__, 2);
        $dir = $root . '/public/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->json($response, ['error' => 'Cannot create upload directory'], 500);
        }

        $target = $dir . DIRECTORY_SEPARATOR . $name;
        $file->moveTo($target);

        $url = '/uploads/' . $name;
        $response->getBody()->write(json_encode(['url' => $url], JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    private function json(ResponseInterface $response, array $data, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
