<?php

namespace App\Http\Controllers;

use App\Services\SecureStorageService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SecureFileController extends Controller
{
    public function __construct(
        protected SecureStorageService $storageService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Download or stream an authorized private tenant document.
     */
    public function download(Request $request): BinaryFileResponse
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            abort(403, 'Unauthorized. Tenant context is missing.');
        }

        $user = $request->user();
        if (!$user || (!$user->isSuperAdmin() && !$user->belongsToTeam($tenant))) {
            abort(403, 'Unauthorized. You do not have access to this tenant repository.');
        }

        $path = (string) $request->input('path', '');
        if (empty($path)) {
            abort(400, 'File path parameter is required.');
        }

        $disk = (string) $request->input('disk', 'private');
        if (!in_array($disk, ['private', 'public'], true)) {
            $disk = 'private';
        }

        return $this->storageService->downloadTenantFile(
            teamId: $tenant->id,
            relativePath: $path,
            downloadName: $request->input('name'),
            disk: $disk
        );
    }

    /**
     * Upload a private document to the active tenant's secure repository.
     */
    public function upload(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            abort(403, 'Unauthorized. Tenant context is missing.');
        }

        $user = $request->user();
        if (!$user || (!$user->isSuperAdmin() && !$user->belongsToTeam($tenant))) {
            abort(403, 'Unauthorized. You do not have access to this tenant repository.');
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB
                'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp,zip',
            ],
            'directory' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_\-]+$/'],
        ]);

        $directory = $request->input('directory', 'documents');
        $storedPath = $this->storageService->storeTenantFile(
            teamId: $tenant->id,
            file: $request->file('file'),
            directory: $directory
        );

        return response()->json([
            'status' => 'success',
            'path' => $storedPath,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'size' => $request->file('file')->getSize(),
            'mime_type' => $request->file('file')->getMimeType(),
        ], 201);
    }
}
