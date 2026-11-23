<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SecureStorageService
{
    /**
     * Strict allowlist of permitted business document extensions and their corresponding MIME types (SEC-G3-REV-05).
     */
    protected const ALLOWED_EXTENSIONS = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'csv'  => ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'text/x-csv'],
        'txt'  => ['text/plain'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * Store a file securely in the tenant's private directory with strict extension and MIME verification.
     */
    public function storeTenantFile(int $teamId, UploadedFile $file, string $directory = 'documents'): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // 1. Strict Extension Allowlist
        if (!array_key_exists($extension, self::ALLOWED_EXTENSIONS)) {
            throw new BadRequestHttpException("Upload of '{$extension}' file types is not permitted. Allowed types: " . implode(', ', array_keys(self::ALLOWED_EXTENSIONS)));
        }

        // 2. MIME Type Verification Against Allowlist
        $mimeType = $file->getMimeType();
        $allowedMimes = self::ALLOWED_EXTENSIONS[$extension];

        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new BadRequestHttpException("MIME type '{$mimeType}' does not match the expected MIME types for extension '{$extension}'.");
        }

        // 3. Sanitize directory to prevent traversal
        $safeDir = preg_replace('/[^a-zA-Z0-9_\-]/', '', $directory);
        if (empty($safeDir)) {
            $safeDir = 'documents';
        }

        $randomName = Str::random(40) . ($extension ? '.' . $extension : '');
        $path = "tenants/{$teamId}/{$safeDir}/{$randomName}";

        Storage::disk('private')->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Resolve and validate a tenant file path, strictly blocking directory traversal.
     */
    public function resolveTenantFilePath(int $teamId, string $relativePath, string $disk = 'private'): string
    {
        // 1. Block directory traversal sequences and null bytes immediately
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            throw new BadRequestHttpException('Directory traversal sequences are forbidden.');
        }

        // 2. Normalize relative path and remove leading slashes
        $normalized = ltrim($relativePath, '/');

        // 3. Ensure path begins with the authorized tenant prefix
        $expectedPrefix = "tenants/{$teamId}/";
        if (!str_starts_with($normalized, $expectedPrefix)) {
            throw new AccessDeniedHttpException('Access denied. File path does not belong to the active tenant.');
        }

        // 4. Resolve absolute path and verify directory containment via realpath
        $diskRoot = Storage::disk($disk)->path('');
        $fullPath = Storage::disk($disk)->path($normalized);

        $realDiskRoot = realpath($diskRoot);
        $realPath = realpath($fullPath);

        $tenantRoot = Storage::disk($disk)->path("tenants/{$teamId}");
        $realTenantRoot = realpath($tenantRoot);

        if (!$realPath || !$realTenantRoot || !str_starts_with($realPath, $realTenantRoot) || !is_file($realPath)) {
            throw new NotFoundHttpException('Requested file not found in tenant storage.');
        }

        return $realPath;
    }

    /**
     * Download or stream a tenant file with strict isolation and secure headers.
     */
    public function downloadTenantFile(int $teamId, string $relativePath, ?string $downloadName = null, string $disk = 'private'): BinaryFileResponse
    {
        $realPath = $this->resolveTenantFilePath($teamId, $relativePath, $disk);

        $filename = $downloadName ?: basename($realPath);
        // Sanitize filename for Content-Disposition header
        $cleanFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        return response()->download($realPath, $cleanFilename, [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Safely delete a file from tenant storage.
     */
    public function deleteTenantFile(int $teamId, string $relativePath, string $disk = 'private'): bool
    {
        try {
            $realPath = $this->resolveTenantFilePath($teamId, $relativePath, $disk);
            $normalized = ltrim($relativePath, '/');
            return Storage::disk($disk)->delete($normalized);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
