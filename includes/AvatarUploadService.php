<?php

declare(strict_types=1);

final class AvatarUploadService
{
    private const MAX_BYTES = 3 * 1024 * 1024;
    private const ALLOWED_MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    private const PUBLIC_PATH_PREFIX = 'assets/uploads/avatars/';

    private function uploadDir(): string
    {
        return __DIR__ . '/../' . self::PUBLIC_PATH_PREFIX;
    }

    public function saveUpload(array $file): string
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Invalid upload.');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The photo failed to upload. Please try again.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Photo must be 3MB or smaller.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid upload.');
        }

        $info = getimagesize($tmpName);
        if ($info === false) {
            throw new RuntimeException('The uploaded file is not a valid image.');
        }
        $mime = (string) ($info['mime'] ?? '');
        if (!isset(self::ALLOWED_MIME_EXT[$mime])) {
            throw new RuntimeException('Photos must be JPEG, PNG, or WebP.');
        }

        $uploadDir = $this->uploadDir();
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not save the photo. Please try again.');
        }

        $filename    = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME_EXT[$mime];
        $destination = $uploadDir . $filename;
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Could not save the photo. Please try again.');
        }

        return self::PUBLIC_PATH_PREFIX . $filename;
    }

    public function deleteIfLocal(?string $path): void
    {
        if ($path === null || $path === '' || !str_starts_with($path, self::PUBLIC_PATH_PREFIX)) {
            return;
        }

        $filename = basename($path);
        $fullPath = $this->uploadDir() . $filename;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
