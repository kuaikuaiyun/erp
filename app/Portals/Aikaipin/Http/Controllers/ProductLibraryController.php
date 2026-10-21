<?php

namespace App\Portals\Aikaipin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AikaipinProduct;
use App\Models\AikaipinProductUploadSession;
use App\Models\AikaipinPlacementEvaluation;
use App\Models\AikaipinPlacementEvaluationRecord;
use App\Models\AikaipinSelectionProposal;
use App\Models\AikaipinSelectionProposalImage;
use App\Models\AikaipinSelectionProposalTag;
use App\Models\AdminUser;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Aikaipin\AikaipinPortalAuthorizationService;
use App\Portals\Aikaipin\AikaipinProductLibraryStorageService;
use App\Portals\Aikaipin\AikaipinSelectionProposalStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductLibraryController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AikaipinPortalAuthorizationService $authorization,
        private readonly AikaipinProductLibraryStorageService $storage,
        private readonly AikaipinSelectionProposalStorageService $selectionProposalStorage,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $canManageProductLibrary = $this->authorization->canManageProductLibrary($context['user']);
        $filters = $this->productFilters($request);

        return view('portals.aikaipin.product-library.index', [
            ...$context,
            'products' => $this->productQuery($context['user'], $canManageProductLibrary, $filters)
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
            'categories' => $this->productCategoryOptions(),
            'canManageProductLibrary' => $canManageProductLibrary,
            'operationVisibilitySource' => $this->authorization->operationVisibilitySource(),
            'quickAuthorizationDepartments' => $this->authorization->quickAuthorizationDepartments(),
        ]);
    }

    public function initializePackageUpload(Request $request): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);

        $data = $request->validate([
            'client_fingerprint' => ['required', 'string', 'size:64'],
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:191'],
            'total_size' => ['required', 'integer', 'min:1', 'max:157286400'],
            'total_chunks' => ['required', 'integer', 'min:1'],
        ]);
        $expectedChunks = (int) ceil((int) $data['total_size'] / AikaipinProductLibraryStorageService::PACKAGE_CHUNK_SIZE);

        if ((int) $data['total_chunks'] !== $expectedChunks) {
            throw ValidationException::withMessages([
                'total_chunks' => __('aikaipin.messages.package_upload_invalid_message'),
            ]);
        }

        $session = AikaipinProductUploadSession::query()
            ->where('user_id', $context['user']->getKey())
            ->where('client_fingerprint', $data['client_fingerprint'])
            ->first();

        if ($session !== null && ($session->status === AikaipinProductUploadSession::STATUS_CONSUMED
            || $session->expires_at?->isPast()
            || (int) $session->total_size !== (int) $data['total_size']
            || (int) $session->total_chunks !== (int) $data['total_chunks']
            || $session->original_name !== $data['original_name'])) {
            $this->deleteUploadSession($session);
            $session = null;
        }

        if ($session === null) {
            $uploadToken = (string) Str::uuid();
            $session = AikaipinProductUploadSession::create([
                'upload_token' => $uploadToken,
                'user_id' => (int) $context['user']->getKey(),
                'client_fingerprint' => $data['client_fingerprint'],
                'original_name' => $data['original_name'],
                'mime_type' => $data['mime_type'] ?: null,
                'total_size' => (int) $data['total_size'],
                'total_chunks' => (int) $data['total_chunks'],
                'disk' => AikaipinProductLibraryStorageService::DISK,
                'temporary_directory' => $this->storage->newTemporaryDirectory($uploadToken),
                'status' => AikaipinProductUploadSession::STATUS_UPLOADING,
                'expires_at' => now()->addDay(),
            ]);
        }

        return response()->json($this->packageSessionPayload($session));
    }

    public function storePackageUploadPart(Request $request, string $uploadToken, int $chunkIndex): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);
        $session = $this->uploadSessionForUser($context['user'], $uploadToken);
        $data = $request->validate([
            'chunk' => ['required', 'file'],
        ]);

        if ($session->status !== AikaipinProductUploadSession::STATUS_UPLOADING
            || $session->expires_at?->isPast()
            || $chunkIndex < 0
            || $chunkIndex >= (int) $session->total_chunks) {
            throw ValidationException::withMessages([
                'chunk' => __('aikaipin.messages.package_upload_invalid_message'),
            ]);
        }

        /** @var UploadedFile $chunk */
        $chunk = $data['chunk'];
        $remaining = (int) $session->total_size - ($chunkIndex * AikaipinProductLibraryStorageService::PACKAGE_CHUNK_SIZE);
        $expectedSize = min(AikaipinProductLibraryStorageService::PACKAGE_CHUNK_SIZE, $remaining);

        if ($chunk->getSize() !== $expectedSize) {
            throw ValidationException::withMessages([
                'chunk' => __('aikaipin.messages.package_upload_invalid_message'),
            ]);
        }

        $this->storage->storeUploadPart($session, $chunkIndex, $chunk);
        $session->forceFill([
            'received_size' => (int) $session->parts()->sum('size'),
            'expires_at' => now()->addDay(),
        ])->save();

        return response()->json($this->packageSessionPayload($session->refresh()));
    }

    public function completePackageUpload(Request $request, string $uploadToken): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);
        $session = $this->uploadSessionForUser($context['user'], $uploadToken);

        if ($session->status === AikaipinProductUploadSession::STATUS_COMPLETED && $session->assembled_path) {
            return response()->json($this->packageSessionPayload($session));
        }

        $parts = $session->parts()->orderBy('chunk_index')->get();
        $expectedIndexes = range(0, (int) $session->total_chunks - 1);
        if ($session->status !== AikaipinProductUploadSession::STATUS_UPLOADING
            || $session->expires_at?->isPast()
            || $parts->count() !== (int) $session->total_chunks
            || $parts->pluck('chunk_index')->map(static fn ($index): int => (int) $index)->all() !== $expectedIndexes) {
            throw ValidationException::withMessages([
                'package_upload_token' => __('aikaipin.messages.package_upload_incomplete_message'),
            ]);
        }

        try {
            $assembled = $this->storage->assembleUpload($session, $parts);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('aikaipin.messages.package_upload_assembly_failed_message'),
            ], 422);
        }

        $session->forceFill([
            'assembled_path' => $assembled['path'],
            'received_size' => $assembled['size'],
            'status' => AikaipinProductUploadSession::STATUS_COMPLETED,
            'expires_at' => now()->addDay(),
        ])->save();

        return response()->json($this->packageSessionPayload($session));
    }

    public function storeProduct(Request $request): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'source_platform' => ['required', 'in:shopify,shopline'],
            'product_type' => ['required', 'in:general,xiaohei'],
            'landing_page_url' => ['nullable', 'url', 'max:2048'],
            'skus' => ['nullable', 'array'],
            'skus.*' => ['nullable', 'string', 'max:191', 'distinct'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['nullable', 'string', 'max:80', 'distinct'],
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['file', 'image', 'max:20480'],
            'reference_file' => ['nullable', 'file'],
            'package_upload_token' => ['required', 'uuid'],
            'authorized_department_ids' => ['nullable', 'array'],
            'authorized_department_ids.*' => ['integer', 'distinct'],
        ]);
        $departmentIds = collect($data['authorized_department_ids'] ?? [])->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $skuValues = $this->normalizeProductValues($data['skus'] ?? []);
        $categories = $this->normalizeProductValues($data['categories'] ?? []);

        $availableDepartments = collect($this->authorization->quickAuthorizationDepartments())->keyBy('id');
        if (collect($departmentIds)->diff($availableDepartments->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'authorized_department_ids' => __('aikaipin.messages.product_authorization_invalid_message'),
            ]);
        }

        $createdPaths = [];
        $product = null;

        try {
            $connection = config('admin.database.connection') ?: config('database.default');
            $product = DB::connection($connection)->transaction(function () use ($context, $data, $categories, $departmentIds, $skuValues, &$createdPaths): AikaipinProduct {
                $session = $this->uploadSessionForUser($context['user'], (string) $data['package_upload_token'], true);
                if ($session->status !== AikaipinProductUploadSession::STATUS_COMPLETED
                    || !$session->assembled_path
                    || !Storage::disk(AikaipinProductLibraryStorageService::DISK)->exists($session->assembled_path)) {
                    throw ValidationException::withMessages([
                        'package_upload_token' => __('aikaipin.messages.package_upload_incomplete_message'),
                    ]);
                }

                $product = AikaipinProduct::create([
                    'product_code' => $this->temporaryProductCode(),
                    'name' => trim((string) $data['name']),
                    'sku' => $this->joinProductValues($skuValues),
                    'categories' => $categories === [] ? null : $categories,
                    'source_platform' => $data['source_platform'],
                    'product_type' => $data['product_type'],
                    'landing_page_url' => $data['landing_page_url'] ?: null,
                    'visible_department_ids' => $departmentIds === [] ? null : $departmentIds,
                    'created_by' => (int) $context['user']->getKey(),
                    'created_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                    'updated_by' => (int) $context['user']->getKey(),
                    'updated_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                ]);

                $images = [];

                foreach (array_values($data['images'] ?? []) as $image) {
                    /** @var UploadedFile $image */
                    $imagePayload = $this->storage->storeUploadedFile($image, 'images');
                    $createdPaths[] = $imagePayload['path'];
                    $images[] = $this->productFilePayload($imagePayload);
                }

                $referenceFile = null;
                if (($data['reference_file'] ?? null) instanceof UploadedFile) {
                    /** @var UploadedFile $referenceFile */
                    $referenceFile = $data['reference_file'];
                    $referencePayload = $this->storage->storeUploadedFile($referenceFile, 'references');
                    $createdPaths[] = $referencePayload['path'];
                    $referenceFile = $this->productFilePayload($referencePayload);
                }

                $packagePayload = $this->storage->copyStoredFile($session->assembled_path, 'packages', $session->original_name, $session->mime_type);
                $createdPaths[] = $packagePayload['path'];
                $packageFile = $this->productFilePayload($packagePayload);

                $product->forceFill([
                    'product_code' => $this->productCodeForId((int) $product->getKey()),
                    'images' => $images === [] ? null : $images,
                    'reference_file' => $referenceFile,
                    'package_file' => $packageFile,
                ])->save();

                $session->forceFill([
                    'status' => AikaipinProductUploadSession::STATUS_CONSUMED,
                    'expires_at' => now()->addHour(),
                ])->save();

                return $product;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $path) {
                $this->storage->deleteFile($path);
            }
            report($exception);

            return response()->json([
                'message' => __('aikaipin.messages.product_save_failed_message'),
            ], 500);
        }

        $completedSession = AikaipinProductUploadSession::query()
            ->where('upload_token', $data['package_upload_token'])
            ->first();
        if ($completedSession !== null) {
            try {
                $this->deleteUploadSession($completedSession);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'message' => __('aikaipin.messages.product_created_message'),
            'redirect_url' => route('aikaipin.product-library.index'),
            'product_id' => $product?->getKey(),
        ], 201);
    }

    public function editProduct(Request $request, AikaipinProduct $product): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);

        return response()->json($this->productEditPayload($product));
    }

    public function updateProduct(Request $request, AikaipinProduct $product): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'source_platform' => ['required', 'in:shopify,shopline'],
            'product_type' => ['required', 'in:general,xiaohei'],
            'landing_page_url' => ['nullable', 'url', 'max:2048'],
            'skus' => ['nullable', 'array'],
            'skus.*' => ['nullable', 'string', 'max:191', 'distinct'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['nullable', 'string', 'max:80', 'distinct'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'max:20480'],
            'remove_image_indexes' => ['nullable', 'array'],
            'remove_image_indexes.*' => ['integer', 'min:0', 'distinct'],
            'reference_file' => ['nullable', 'file'],
            'package_upload_token' => ['nullable', 'uuid'],
            'authorized_department_ids' => ['nullable', 'array'],
            'authorized_department_ids.*' => ['integer', 'distinct'],
        ]);
        $departmentIds = collect($data['authorized_department_ids'] ?? [])->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $skuValues = $this->normalizeProductValues($data['skus'] ?? []);
        $categories = $this->normalizeProductValues($data['categories'] ?? []);
        $removedImageIndexes = collect($data['remove_image_indexes'] ?? [])->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        $availableDepartments = collect($this->authorization->quickAuthorizationDepartments())->keyBy('id');
        if (collect($departmentIds)->diff($availableDepartments->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'authorized_department_ids' => __('aikaipin.messages.product_authorization_invalid_message'),
            ]);
        }

        $existingImages = $product->imageFiles();
        if (collect($removedImageIndexes)->contains(static fn (int $index): bool => !array_key_exists($index, $existingImages))) {
            throw ValidationException::withMessages([
                'remove_image_indexes' => __('aikaipin.messages.product_image_invalid_message'),
            ]);
        }

        $remainingImageCount = count($existingImages) - count($removedImageIndexes);
        $newImages = array_values($data['images'] ?? []);
        if ($remainingImageCount + count($newImages) > 3) {
            throw ValidationException::withMessages([
                'images' => __('aikaipin.messages.image_limit_message'),
            ]);
        }

        if (!($data['package_upload_token'] ?? null) && $product->packageFilePayload() === null) {
            throw ValidationException::withMessages([
                'package_upload_token' => __('aikaipin.messages.archive_required_message'),
            ]);
        }

        $createdPaths = [];
        $obsoletePaths = [];
        $completedSession = null;

        try {
            $connection = config('admin.database.connection') ?: config('database.default');
            DB::connection($connection)->transaction(function () use ($context, $product, $data, $categories, $departmentIds, $skuValues, $removedImageIndexes, $newImages, &$createdPaths, &$obsoletePaths, &$completedSession): void {
                $images = $product->imageFiles();
                foreach (array_reverse($removedImageIndexes) as $index) {
                    $removedFile = $images[$index] ?? null;
                    if ($removedFile !== null) {
                        $obsoletePaths[] = $removedFile['path'];
                        unset($images[$index]);
                    }
                }
                $images = array_values($images);

                foreach ($newImages as $image) {
                    /** @var UploadedFile $image */
                    $imagePayload = $this->storage->storeUploadedFile($image, 'images');
                    $createdPaths[] = $imagePayload['path'];
                    $images[] = $this->productFilePayload($imagePayload);
                }

                $referenceFile = $product->referenceFilePayload();
                if (($data['reference_file'] ?? null) instanceof UploadedFile) {
                    /** @var UploadedFile $uploadedReferenceFile */
                    $uploadedReferenceFile = $data['reference_file'];
                    $referencePayload = $this->storage->storeUploadedFile($uploadedReferenceFile, 'references');
                    $createdPaths[] = $referencePayload['path'];
                    if ($referenceFile !== null) {
                        $obsoletePaths[] = $referenceFile['path'];
                    }
                    $referenceFile = $this->productFilePayload($referencePayload);
                }

                $packageFile = $product->packageFilePayload();
                if ($data['package_upload_token'] ?? null) {
                    $session = $this->uploadSessionForUser($context['user'], (string) $data['package_upload_token'], true);
                    if ($session->status !== AikaipinProductUploadSession::STATUS_COMPLETED
                        || !$session->assembled_path
                        || !Storage::disk(AikaipinProductLibraryStorageService::DISK)->exists($session->assembled_path)) {
                        throw ValidationException::withMessages([
                            'package_upload_token' => __('aikaipin.messages.package_upload_incomplete_message'),
                        ]);
                    }

                    $packagePayload = $this->storage->copyStoredFile($session->assembled_path, 'packages', $session->original_name, $session->mime_type);
                    $createdPaths[] = $packagePayload['path'];
                    if ($packageFile !== null) {
                        $obsoletePaths[] = $packageFile['path'];
                    }
                    $packageFile = $this->productFilePayload($packagePayload);
                    $session->forceFill([
                        'status' => AikaipinProductUploadSession::STATUS_CONSUMED,
                        'expires_at' => now()->addHour(),
                    ])->save();
                    $completedSession = $session;
                }

                $product->forceFill([
                    'name' => trim((string) $data['name']),
                    'sku' => $this->joinProductValues($skuValues),
                    'categories' => $categories === [] ? null : $categories,
                    'source_platform' => $data['source_platform'],
                    'product_type' => $data['product_type'],
                    'landing_page_url' => $data['landing_page_url'] ?: null,
                    'images' => $images === [] ? null : $images,
                    'reference_file' => $referenceFile,
                    'package_file' => $packageFile,
                    'visible_department_ids' => $departmentIds === [] ? null : $departmentIds,
                    'updated_by' => (int) $context['user']->getKey(),
                    'updated_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                ])->save();
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $path) {
                $this->storage->deleteFile($path);
            }
            report($exception);

            return response()->json([
                'message' => __('aikaipin.messages.product_save_failed_message'),
            ], 500);
        }

        foreach (array_unique($obsoletePaths) as $path) {
            try {
                $this->storage->deleteFile($path);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        if ($completedSession !== null) {
            try {
                $this->deleteUploadSession($completedSession);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'message' => __('aikaipin.messages.product_updated_message'),
            'redirect_url' => route('aikaipin.product-library.index'),
            'product_id' => $product->getKey(),
        ]);
    }

    public function updateVisibility(Request $request): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);
        $data = $request->validate([
            'action' => ['required', 'in:enable,cancel'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'distinct'],
        ]);
        $productIds = collect($data['product_ids'])->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $products = AikaipinProduct::query()->whereKey($productIds)->get();
        if ($products->count() !== count($productIds)) {
            throw ValidationException::withMessages([
                'product_ids' => __('aikaipin.messages.product_selection_invalid_message'),
            ]);
        }

        $departmentIds = collect($this->authorization->quickAuthorizationDepartments())->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        foreach ($products as $product) {
            $currentDepartmentIds = $product->visibleDepartmentIds();

            if ($data['action'] === 'enable') {
                $updatedDepartmentIds = collect([...$currentDepartmentIds, ...$departmentIds])
                    ->map(static fn ($id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();
            } else {
                $updatedDepartmentIds = collect($currentDepartmentIds)
                    ->reject(static fn (int $id): bool => in_array($id, $departmentIds, true))
                    ->values()
                    ->all();
            }

            $product->forceFill([
                'visible_department_ids' => $updatedDepartmentIds === [] ? null : $updatedDepartmentIds,
            ])->save();
        }

        return response()->json([
            'message' => $data['action'] === 'enable'
                ? __('aikaipin.messages.visibility_enabled_message', ['count' => count($productIds)])
                : __('aikaipin.messages.visibility_cancelled_message', ['count' => count($productIds)]),
        ]);
    }

    public function showProductImage(Request $request, AikaipinProduct $product, int $imageIndex): Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canViewProduct($context['user'], $product), 403);

        $file = $product->imageFiles()[$imageIndex] ?? null;
        abort_unless($file !== null, 404);

        return $this->serveProductFile($file, false);
    }

    public function showProductFile(Request $request, AikaipinProduct $product, string $kind): Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canViewProduct($context['user'], $product), 403);
        abort_unless(in_array($kind, ['reference', 'package'], true), 404);

        $file = $kind === 'reference'
            ? $product->referenceFilePayload()
            : $product->packageFilePayload();
        abort_unless($file !== null, 404);

        return $this->serveProductFile($file, $kind === 'package');
    }

    /** @param array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string} $file */
    private function serveProductFile(array $file, bool $download): Response
    {
        $disk = (string) $file['disk'];
        abort_unless($disk === AikaipinProductLibraryStorageService::DISK && Storage::disk($disk)->exists($file['path']), 404);

        $headers = ['Content-Type' => $file['mime_type'] ?: 'application/octet-stream'];

        return $download
            ? response()->download(Storage::disk($disk)->path($file['path']), $file['original_name'], $headers)
            : response()->file(Storage::disk($disk)->path($file['path']), $headers);
    }

    public function selectionProposal(Request $request): Response|View
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $canReviewAllSelectionProposals = $this->authorization->canManageProductLibrary($context['user']);
        $filters = $this->selectionProposalFilters($request);

        return view('portals.aikaipin.selection-proposal.index', [
            ...$context,
            'proposals' => $this->selectionProposalQuery($context['user'], $canReviewAllSelectionProposals, $filters)
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
            'canReviewAllSelectionProposals' => $canReviewAllSelectionProposals,
        ]);
    }

    public function storeSelectionProposal(Request $request): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:191'],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:80', 'distinct'],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'string', 'max:80', 'distinct'],
            'link_1688' => ['nullable', 'url', 'max:2048'],
            'competitor_url' => ['nullable', 'url', 'max:2048'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'images' => ['required', 'array', 'min:1', 'max:3'],
            'images.*' => ['file', 'image', 'max:20480'],
        ]);
        $specifications = $this->selectionProposalTagValues($data['specifications'] ?? []);
        $attributes = $this->selectionProposalTagValues($data['attributes'] ?? []);
        $createdPaths = [];
        $proposal = null;

        try {
            $connection = config('admin.database.connection') ?: config('database.default');
            $proposal = DB::connection($connection)->transaction(function () use ($context, $data, $specifications, $attributes, &$createdPaths): AikaipinSelectionProposal {
                $proposal = AikaipinSelectionProposal::create([
                    'proposal_code' => $this->nextSelectionProposalCode(),
                    'product_name' => trim((string) $data['product_name']),
                    'link_1688' => ($data['link_1688'] ?? null) ?: null,
                    'competitor_url' => ($data['competitor_url'] ?? null) ?: null,
                    'remark' => ($data['remark'] ?? null) ?: null,
                    'created_by' => (int) $context['user']->getKey(),
                    'created_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                    'updated_by' => (int) $context['user']->getKey(),
                    'updated_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                ]);

                $this->syncSelectionProposalTags($proposal, $specifications, $attributes);
                foreach (array_values($data['images']) as $sort => $image) {
                    /** @var UploadedFile $image */
                    $imagePayload = $this->selectionProposalStorage->storeImage($image);
                    $createdPaths[] = $imagePayload['path'];
                    $proposal->images()->create([...$imagePayload, 'disk' => AikaipinSelectionProposalStorageService::DISK, 'sort' => $sort]);
                }

                return $proposal;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $path) {
                $this->selectionProposalStorage->deleteImage($path);
            }
            report($exception);

            return response()->json([
                'message' => __('aikaipin.selection_proposal.messages.save_failed_message'),
            ], 500);
        }

        return response()->json([
            'message' => __('aikaipin.selection_proposal.messages.created_message'),
            'redirect_url' => route('aikaipin.selection-proposals.index'),
            'proposal_id' => $proposal?->getKey(),
        ], 201);
    }

    public function showSelectionProposal(Request $request, AikaipinSelectionProposal $proposal): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canViewSelectionProposal($context['user'], $proposal), 403);
        $proposal->load(['tags', 'images']);

        return response()->json($this->selectionProposalPayload($context['user'], $proposal));
    }

    public function updateSelectionProposal(Request $request, AikaipinSelectionProposal $proposal): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canEditSelectionProposal($context['user'], $proposal), 403);
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:191'],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:80', 'distinct'],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'string', 'max:80', 'distinct'],
            'link_1688' => ['nullable', 'url', 'max:2048'],
            'competitor_url' => ['nullable', 'url', 'max:2048'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'max:20480'],
            'remove_image_ids' => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer', 'distinct'],
        ]);
        $specifications = $this->selectionProposalTagValues($data['specifications'] ?? []);
        $attributes = $this->selectionProposalTagValues($data['attributes'] ?? []);
        $removedImageIds = collect($data['remove_image_ids'] ?? [])->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $existingImages = $proposal->images()->get();
        $existingImageIds = $existingImages->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if (collect($removedImageIds)->diff($existingImageIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'remove_image_ids' => __('aikaipin.selection_proposal.messages.image_invalid_message'),
            ]);
        }

        $newImages = array_values($data['images'] ?? []);
        $finalImageCount = $existingImages->count() - count($removedImageIds) + count($newImages);
        if ($finalImageCount < 1 || $finalImageCount > 3) {
            throw ValidationException::withMessages([
                'images' => $finalImageCount < 1
                    ? __('aikaipin.selection_proposal.messages.image_required_message')
                    : __('aikaipin.selection_proposal.messages.image_limit_message'),
            ]);
        }

        $createdPaths = [];
        $obsoleteImages = collect();

        try {
            $connection = config('admin.database.connection') ?: config('database.default');
            DB::connection($connection)->transaction(function () use ($context, $proposal, $data, $specifications, $attributes, $removedImageIds, $newImages, &$createdPaths, &$obsoleteImages): void {
                $proposal->forceFill([
                    'product_name' => trim((string) $data['product_name']),
                    'link_1688' => ($data['link_1688'] ?? null) ?: null,
                    'competitor_url' => ($data['competitor_url'] ?? null) ?: null,
                    'remark' => ($data['remark'] ?? null) ?: null,
                    'updated_by' => (int) $context['user']->getKey(),
                    'updated_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                ])->save();
                $this->syncSelectionProposalTags($proposal, $specifications, $attributes);

                $removedImages = $proposal->images()->whereKey($removedImageIds)->get();
                if ($removedImages->isNotEmpty()) {
                    $obsoleteImages = $obsoleteImages->merge($removedImages);
                    $proposal->images()->whereKey($removedImageIds)->delete();
                }

                $sort = (int) ($proposal->images()->max('sort') ?? -1) + 1;
                foreach ($newImages as $image) {
                    /** @var UploadedFile $image */
                    $imagePayload = $this->selectionProposalStorage->storeImage($image);
                    $createdPaths[] = $imagePayload['path'];
                    $proposal->images()->create([...$imagePayload, 'disk' => AikaipinSelectionProposalStorageService::DISK, 'sort' => $sort]);
                    $sort += 1;
                }
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $path) {
                $this->selectionProposalStorage->deleteImage($path);
            }
            report($exception);

            return response()->json([
                'message' => __('aikaipin.selection_proposal.messages.save_failed_message'),
            ], 500);
        }

        foreach ($obsoleteImages->unique('id') as $image) {
            try {
                $this->selectionProposalStorage->deleteImage((string) $image->path);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'message' => __('aikaipin.selection_proposal.messages.updated_message'),
            'redirect_url' => route('aikaipin.selection-proposals.index'),
            'proposal_id' => $proposal->getKey(),
        ]);
    }

    public function withdrawSelectionProposal(Request $request, AikaipinSelectionProposal $proposal): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canEditSelectionProposal($context['user'], $proposal), 403);
        $proposal->forceFill([
            'withdrawn_at' => now(),
            'withdrawn_by' => (int) $context['user']->getKey(),
            'withdrawn_by_name' => (string) ($context['user']->name ?: $context['user']->username),
            'updated_by' => (int) $context['user']->getKey(),
            'updated_by_name' => (string) ($context['user']->name ?: $context['user']->username),
        ])->save();

        return response()->json([
            'message' => __('aikaipin.selection_proposal.messages.withdrawn_message'),
            'redirect_url' => route('aikaipin.selection-proposals.index'),
        ]);
    }

    public function showSelectionProposalImage(Request $request, AikaipinSelectionProposal $proposal, AikaipinSelectionProposalImage $image): Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless((int) $image->proposal_id === (int) $proposal->getKey(), 404);
        abort_unless($this->canViewSelectionProposal($context['user'], $proposal), 403);
        abort_unless($image->disk === AikaipinSelectionProposalStorageService::DISK && Storage::disk($image->disk)->exists($image->path), 404);

        return response()->file(Storage::disk($image->disk)->path($image->path), [
            'Content-Type' => $image->mime_type ?: 'application/octet-stream',
        ]);
    }

    public function placementEvaluation(Request $request): Response|View
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $canReviewAllPlacementEvaluations = $this->authorization->canManageProductLibrary($context['user']);
        $filters = $this->placementEvaluationFilters($request);

        return view('portals.aikaipin.placement-evaluation.index', [
            ...$context,
            'evaluations' => $this->placementEvaluationQuery($context['user'], $canReviewAllPlacementEvaluations, $filters)
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
            'canReviewAllPlacementEvaluations' => $canReviewAllPlacementEvaluations,
        ]);
    }

    public function placementEvaluationProductOptions(Request $request): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $keyword = trim((string) $request->query('keyword', ''));
        $canManageProductLibrary = $this->authorization->canManageProductLibrary($context['user']);
        $products = $this->productQuery($context['user'], $canManageProductLibrary, [
            'keyword' => $keyword,
            'category' => '',
            'source' => '',
            'type' => '',
        ])->limit(12)->get(['id', 'product_code', 'name']);

        return response()->json([
            'products' => $products->map(static fn (AikaipinProduct $product): array => [
                'id' => (int) $product->getKey(),
                'code' => (string) $product->product_code,
                'name' => (string) $product->name,
            ])->values()->all(),
        ]);
    }

    public function storePlacementEvaluation(Request $request): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $data = $this->validatedPlacementEvaluationData($request);
        $product = $this->placementEvaluationProduct($context['user'], (int) $data['product_id']);
        $records = $this->placementEvaluationRecords($data['records']);
        $connection = config('admin.database.connection') ?: config('database.default');

        try {
            $evaluation = DB::connection($connection)->transaction(function () use ($context, $data, $product, $records): AikaipinPlacementEvaluation {
                $evaluation = AikaipinPlacementEvaluation::create([
                    'evaluation_code' => $this->nextPlacementEvaluationCode(),
                    'product_id' => (int) $product->getKey(),
                    'product_code' => (string) $product->product_code,
                    'product_name' => (string) $product->name,
                    'test_started_on' => $data['test_started_on'],
                    'data_ended_on' => $data['data_ended_on'],
                    'remark' => ($data['remark'] ?? null) ?: null,
                    'created_by' => (int) $context['user']->getKey(),
                    'created_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                    'updated_by' => (int) $context['user']->getKey(),
                    'updated_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                ]);
                foreach ($records as $sort => $record) {
                    $evaluation->records()->create([...$record, 'sort' => $sort]);
                }

                return $evaluation;
            });
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('aikaipin.placement_evaluation.messages.save_failed_message'),
            ], 500);
        }

        return response()->json([
            'message' => __('aikaipin.placement_evaluation.messages.created_message'),
            'redirect_url' => route('aikaipin.placement-evaluations.index'),
            'evaluation_id' => $evaluation->getKey(),
        ], 201);
    }

    public function showPlacementEvaluation(Request $request, AikaipinPlacementEvaluation $evaluation): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canViewPlacementEvaluation($context['user'], $evaluation), 403);
        $evaluation->load('records');

        return response()->json($this->placementEvaluationPayload($context['user'], $evaluation));
    }

    public function updatePlacementEvaluation(Request $request, AikaipinPlacementEvaluation $evaluation): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canEditPlacementEvaluation($context['user'], $evaluation), 403);
        $data = $this->validatedPlacementEvaluationData($request);
        $product = $this->placementEvaluationProduct($context['user'], (int) $data['product_id']);
        $records = $this->placementEvaluationRecords($data['records']);
        $connection = config('admin.database.connection') ?: config('database.default');

        try {
            DB::connection($connection)->transaction(function () use ($context, $evaluation, $data, $product, $records): void {
                $evaluation->forceFill([
                    'product_id' => (int) $product->getKey(),
                    'product_code' => (string) $product->product_code,
                    'product_name' => (string) $product->name,
                    'test_started_on' => $data['test_started_on'],
                    'data_ended_on' => $data['data_ended_on'],
                    'remark' => ($data['remark'] ?? null) ?: null,
                    'updated_by' => (int) $context['user']->getKey(),
                    'updated_by_name' => (string) ($context['user']->name ?: $context['user']->username),
                ])->save();
                $evaluation->records()->delete();
                foreach ($records as $sort => $record) {
                    $evaluation->records()->create([...$record, 'sort' => $sort]);
                }
            });
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('aikaipin.placement_evaluation.messages.save_failed_message'),
            ], 500);
        }

        return response()->json([
            'message' => __('aikaipin.placement_evaluation.messages.updated_message'),
            'redirect_url' => route('aikaipin.placement-evaluations.index'),
            'evaluation_id' => $evaluation->getKey(),
        ]);
    }

    public function destroyPlacementEvaluation(Request $request, AikaipinPlacementEvaluation $evaluation): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->canEditPlacementEvaluation($context['user'], $evaluation), 403);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($evaluation): void {
            $evaluation->records()->delete();
            $evaluation->delete();
        });

        return response()->json([
            'message' => __('aikaipin.placement_evaluation.messages.deleted_message'),
            'redirect_url' => route('aikaipin.placement-evaluations.index'),
        ]);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function pageContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->assertPortal($context['portal']);
        $context['portal']->load('menus');

        return $context;
    }

    private function uploadSessionForUser(AdminUser $user, string $uploadToken, bool $lockForUpdate = false): AikaipinProductUploadSession
    {
        $query = AikaipinProductUploadSession::query()
            ->where('upload_token', $uploadToken)
            ->where('user_id', $user->getKey());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $session = $query->first();
        if ($session === null) {
            throw ValidationException::withMessages([
                'package_upload_token' => __('aikaipin.messages.package_upload_invalid_message'),
            ]);
        }

        return $session;
    }

    /** @return array{upload_token:string,status:string,uploaded_chunks:list<int>,received_size:int,total_size:int,chunk_size:int} */
    private function packageSessionPayload(AikaipinProductUploadSession $session): array
    {
        return [
            'upload_token' => (string) $session->upload_token,
            'status' => (string) $session->status,
            'uploaded_chunks' => $session->parts()
                ->orderBy('chunk_index')
                ->pluck('chunk_index')
                ->map(static fn ($index): int => (int) $index)
                ->values()
                ->all(),
            'received_size' => (int) $session->received_size,
            'total_size' => (int) $session->total_size,
            'chunk_size' => AikaipinProductLibraryStorageService::PACKAGE_CHUNK_SIZE,
        ];
    }

    /** @return array<string, mixed> */
    private function productEditPayload(AikaipinProduct $product): array
    {
        $filePayload = function (?array $file, string $kind) use ($product): ?array {
            if ($file === null) {
                return null;
            }

            return [
                'name' => (string) $file['original_name'],
                'url' => route('aikaipin.product-library.files.show', ['product' => $product, 'kind' => $kind]),
            ];
        };

        $imageFiles = $product->imageFiles();

        return [
            'id' => (int) $product->getKey(),
            'name' => (string) $product->name,
            'source_platform' => (string) $product->source_platform,
            'product_type' => (string) $product->product_type,
            'landing_page_url' => $product->landing_page_url,
            'skus' => $product->skuValues(),
            'categories' => $product->categoryValues(),
            'authorized_department_ids' => $product->visibleDepartmentIds(),
            'images' => array_map(function (array $file, int $index) use ($product): array {
                return [
                    'index' => $index,
                    'name' => (string) $file['original_name'],
                    'url' => route('aikaipin.product-library.images.show', ['product' => $product, 'imageIndex' => $index]),
                ];
            }, $imageFiles, array_keys($imageFiles)),
            'reference_file' => $filePayload($product->referenceFilePayload(), 'reference'),
            'package_file' => $filePayload($product->packageFilePayload(), 'package'),
        ];
    }

    private function deleteUploadSession(AikaipinProductUploadSession $session): void
    {
        $this->storage->deleteUploadTemporaryFiles($session);
        $session->parts()->delete();
        $session->delete();
    }

    private function canViewProduct(AdminUser $user, AikaipinProduct $product): bool
    {
        if ($this->authorization->canManageProductLibrary($user)) {
            return true;
        }

        $departmentIds = $this->authorization->operationDepartmentIdsForUser($user);

        return $departmentIds !== []
            && array_intersect($departmentIds, $product->visibleDepartmentIds()) !== [];
    }

    /** @return array{keyword:string} */
    private function placementEvaluationFilters(Request $request): array
    {
        return [
            'keyword' => trim((string) $request->query('keyword', '')),
        ];
    }

    /** @return array{product_id:int,test_started_on:string,data_ended_on:string,remark:?string,records:list<array{platform:string,currency:string,actual_spend:string|int|float,orders:string|int}>} */
    private function validatedPlacementEvaluationData(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'test_started_on' => ['required', 'date'],
            'data_ended_on' => ['required', 'date', 'after_or_equal:test_started_on'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'records' => ['required', 'array', 'min:1', 'max:60'],
            'records.*.platform' => ['required', 'in:facebook,google,tiktok,mediago,newsbreak'],
            'records.*.currency' => ['required', 'in:usd,eur,cny'],
            'records.*.actual_spend' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'records.*.orders' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ]);
    }

    /** @param list<array{platform:string,currency:string,actual_spend:string|int|float,orders:string|int}> $records
     *  @return list<array{platform:string,currency:string,actual_spend:string,orders:int}>
     */
    private function placementEvaluationRecords(array $records): array
    {
        return collect($records)
            ->map(static fn (array $record): array => [
                'platform' => (string) $record['platform'],
                'currency' => (string) $record['currency'],
                'actual_spend' => (string) $record['actual_spend'],
                'orders' => (int) $record['orders'],
            ])
            ->values()
            ->all();
    }

    private function placementEvaluationProduct(AdminUser $user, int $productId): AikaipinProduct
    {
        $product = AikaipinProduct::query()->find($productId);
        if ($product === null || !$this->canViewProduct($user, $product)) {
            throw ValidationException::withMessages([
                'product_id' => __('aikaipin.placement_evaluation.messages.related_product_invalid_message'),
            ]);
        }

        return $product;
    }

    /** @param array{keyword:string} $filters */
    private function placementEvaluationQuery(AdminUser $user, bool $canReviewAll, array $filters): Builder
    {
        $query = AikaipinPlacementEvaluation::query()
            ->with('records')
            ->latest('updated_at')
            ->latest('id');

        if (!$canReviewAll) {
            $query->where('created_by', $user->getKey());
        }
        if ($filters['keyword'] !== '') {
            $like = '%'.$filters['keyword'].'%';
            $query->where(function (Builder $evaluations) use ($like): void {
                $evaluations->where('evaluation_code', 'like', $like)
                    ->orWhere('product_code', 'like', $like)
                    ->orWhere('product_name', 'like', $like);
            });
        }

        return $query;
    }

    private function canViewPlacementEvaluation(AdminUser $user, AikaipinPlacementEvaluation $evaluation): bool
    {
        return $this->authorization->canManageProductLibrary($user)
            || (int) $evaluation->created_by === (int) $user->getKey();
    }

    private function canEditPlacementEvaluation(AdminUser $user, AikaipinPlacementEvaluation $evaluation): bool
    {
        return (int) $evaluation->created_by === (int) $user->getKey();
    }

    /** @return array<string, mixed> */
    private function placementEvaluationPayload(AdminUser $user, AikaipinPlacementEvaluation $evaluation): array
    {
        return [
            'id' => (int) $evaluation->getKey(),
            'evaluation_code' => (string) $evaluation->evaluation_code,
            'product' => [
                'id' => (int) $evaluation->product_id,
                'code' => (string) $evaluation->product_code,
                'name' => (string) $evaluation->product_name,
            ],
            'test_started_on' => $evaluation->test_started_on?->toDateString(),
            'data_ended_on' => $evaluation->data_ended_on?->toDateString(),
            'remark' => $evaluation->remark,
            'created_by_name' => $evaluation->created_by_name,
            'created_at' => $evaluation->created_at?->toAtomString(),
            'updated_at' => $evaluation->updated_at?->toAtomString(),
            'can_edit' => $this->canEditPlacementEvaluation($user, $evaluation),
            'records' => $evaluation->records->map(static fn (AikaipinPlacementEvaluationRecord $record): array => [
                'id' => (int) $record->getKey(),
                'platform' => (string) $record->platform,
                'currency' => (string) $record->currency,
                'actual_spend' => (string) $record->actual_spend,
                'orders' => (int) $record->orders,
            ])->values()->all(),
        ];
    }

    private function nextPlacementEvaluationCode(): string
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $period = now()->format('Ym');
        $sequences = DB::connection($connection)->table('aikaipin_placement_evaluation_code_sequences');
        $sequences->insertOrIgnore([
            'period' => $period,
            'last_number' => 0,
            'updated_at' => now(),
        ]);
        $sequence = $sequences->where('period', $period)->lockForUpdate()->first();
        $nextNumber = (int) ($sequence->last_number ?? 0) + 1;
        $sequences->where('period', $period)->update([
            'last_number' => $nextNumber,
            'updated_at' => now(),
        ]);

        return sprintf('ATE-%s-%06d', $period, $nextNumber);
    }

    private function productCodeForId(int $productId): string
    {
        return 'kky-'.$productId;
    }

    private function temporaryProductCode(): string
    {
        return 'pending-'.strtolower(Str::random(20));
    }

    /** @return array{keyword:string} */
    private function selectionProposalFilters(Request $request): array
    {
        return [
            'keyword' => trim((string) $request->query('keyword', '')),
        ];
    }

    /** @param list<mixed> $values
     *  @return list<string>
     */
    private function selectionProposalTagValues(array $values): array
    {
        return collect($values)
            ->map(static fn ($value): string => trim(preg_replace('/\s+/u', ' ', (string) $value) ?: ''))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $specifications
     *  @param list<string> $attributes
     */
    private function syncSelectionProposalTags(AikaipinSelectionProposal $proposal, array $specifications, array $attributes): void
    {
        $proposal->tags()->delete();
        foreach ($specifications as $sort => $value) {
            $proposal->tags()->create([
                'type' => AikaipinSelectionProposalTag::TYPE_SPECIFICATION,
                'value' => $value,
                'sort' => $sort,
            ]);
        }
        foreach ($attributes as $sort => $value) {
            $proposal->tags()->create([
                'type' => AikaipinSelectionProposalTag::TYPE_ATTRIBUTE,
                'value' => $value,
                'sort' => $sort,
            ]);
        }
    }

    /** @param array{keyword:string} $filters */
    private function selectionProposalQuery(AdminUser $user, bool $canReviewAll, array $filters): Builder
    {
        $query = AikaipinSelectionProposal::query()
            ->with(['tags', 'images'])
            ->latest('updated_at')
            ->latest('id');

        if (!$canReviewAll) {
            $query->where('created_by', $user->getKey());
        }
        if ($filters['keyword'] !== '') {
            $like = '%'.$filters['keyword'].'%';
            $query->where(function (Builder $proposals) use ($like): void {
                $proposals->where('proposal_code', 'like', $like)
                    ->orWhere('product_name', 'like', $like)
                    ->orWhereHas('tags', static fn (Builder $tags) => $tags->where('value', 'like', $like));
            });
        }

        return $query;
    }

    private function canViewSelectionProposal(AdminUser $user, AikaipinSelectionProposal $proposal): bool
    {
        return $this->authorization->canManageProductLibrary($user)
            || (int) $proposal->created_by === (int) $user->getKey();
    }

    private function canEditSelectionProposal(AdminUser $user, AikaipinSelectionProposal $proposal): bool
    {
        return $proposal->withdrawn_at === null
            && (int) $proposal->created_by === (int) $user->getKey();
    }

    /** @return array<string, mixed> */
    private function selectionProposalPayload(AdminUser $user, AikaipinSelectionProposal $proposal): array
    {
        return [
            'id' => (int) $proposal->getKey(),
            'proposal_code' => (string) $proposal->proposal_code,
            'product_name' => (string) $proposal->product_name,
            'specifications' => $proposal->tags
                ->where('type', AikaipinSelectionProposalTag::TYPE_SPECIFICATION)
                ->pluck('value')
                ->values()
                ->all(),
            'attributes' => $proposal->tags
                ->where('type', AikaipinSelectionProposalTag::TYPE_ATTRIBUTE)
                ->pluck('value')
                ->values()
                ->all(),
            'link_1688' => $proposal->link_1688,
            'competitor_url' => $proposal->competitor_url,
            'remark' => $proposal->remark,
            'created_by_name' => $proposal->created_by_name,
            'created_at' => $proposal->created_at?->toAtomString(),
            'updated_at' => $proposal->updated_at?->toAtomString(),
            'withdrawn_at' => $proposal->withdrawn_at?->toAtomString(),
            'can_edit' => $this->canEditSelectionProposal($user, $proposal),
            'images' => $proposal->images->map(function (AikaipinSelectionProposalImage $image) use ($proposal): array {
                return [
                    'id' => (int) $image->getKey(),
                    'name' => (string) $image->original_name,
                    'url' => route('aikaipin.selection-proposals.images.show', ['proposal' => $proposal, 'image' => $image]),
                ];
            })->values()->all(),
        ];
    }

    private function nextSelectionProposalCode(): string
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $period = now()->format('Ym');
        $sequences = DB::connection($connection)->table('aikaipin_selection_proposal_code_sequences');
        $sequences->insertOrIgnore([
            'period' => $period,
            'last_number' => 0,
            'updated_at' => now(),
        ]);
        $sequence = $sequences->where('period', $period)->lockForUpdate()->first();
        $nextNumber = (int) ($sequence->last_number ?? 0) + 1;
        $sequences->where('period', $period)->update([
            'last_number' => $nextNumber,
            'updated_at' => now(),
        ]);

        return sprintf('SPR-%s-%06d', $period, $nextNumber);
    }

    /** @return array{keyword:string,category:string,source:string,type:string} */
    private function productFilters(Request $request): array
    {
        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'category' => trim((string) $request->query('category', '')),
            'source' => trim((string) $request->query('source', '')),
            'type' => trim((string) $request->query('type', '')),
        ];
    }

    /**
     * @param array{keyword:string,category:string,source:string,type:string} $filters
     */
    private function productQuery(AdminUser $user, bool $canManageProductLibrary, array $filters): Builder
    {
        $query = AikaipinProduct::query()
            ->latest('updated_at')
            ->latest('id');

        if (!$canManageProductLibrary) {
            $departmentIds = $this->authorization->operationDepartmentIdsForUser($user);

            if ($departmentIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $products) use ($departmentIds): void {
                    foreach ($departmentIds as $departmentId) {
                        $products->orWhereJsonContains('visible_department_ids', (int) $departmentId);
                    }
                });
            }
        }

        if ($filters['keyword'] !== '') {
            $like = '%'.$filters['keyword'].'%';

            $query->where(function (Builder $products) use ($like): void {
                $products->where('product_code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('sku', 'like', $like);
            });
        }

        if ($filters['category'] !== '') {
            $query->whereJsonContains('categories', $filters['category']);
        }

        if ($filters['source'] !== '') {
            $query->where('source_platform', $filters['source']);
        }

        if ($filters['type'] !== '') {
            $query->where('product_type', $filters['type']);
        }

        return $query;
    }

    /** @return list<array{value:string,label:string}> */
    private function productCategoryOptions(): array
    {
        return AikaipinProduct::query()
            ->whereNotNull('categories')
            ->get(['categories'])
            ->flatMap(static fn (AikaipinProduct $product): array => $product->categoryValues())
            ->map(static fn (string $category): string => trim($category))
            ->filter()
            ->unique(static fn (string $category): string => mb_strtolower($category))
            ->sort(static fn (string $left, string $right): int => strnatcasecmp($left, $right))
            ->values()
            ->map(static fn (string $category): array => ['value' => $category, 'label' => $category])
            ->all();
    }

    /** @param iterable<mixed> $values
     *  @return list<string>
     */
    private function normalizeProductValues(iterable $values): array
    {
        $seen = [];
        $normalized = [];

        foreach ($values as $value) {
            $item = trim(preg_replace('/\s+/u', ' ', (string) $value) ?: '');
            $key = mb_strtolower($item);

            if ($item === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /** @param iterable<mixed> $values */
    private function joinProductValues(iterable $values): ?string
    {
        $items = $this->normalizeProductValues($values);

        return $items === [] ? null : implode(',', $items);
    }

    /** @param array{path:string,original_name:string,mime_type:?string,size:int,sha256:string} $payload
     *  @return array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string}
     */
    private function productFilePayload(array $payload): array
    {
        return [
            'disk' => AikaipinProductLibraryStorageService::DISK,
            'path' => (string) $payload['path'],
            'original_name' => (string) $payload['original_name'],
            'mime_type' => $payload['mime_type'] ?: null,
            'size' => (int) $payload['size'],
            'sha256' => $payload['sha256'] ?: null,
        ];
    }

    /**
     * Temporary frontend-only sample data.
     *
     * @return list<array{
     *     code:string,
     *     name_key:string,
     *     skus:list<string>,
     *     category:string,
     *     source:string,
     *     type:string,
     *     images:list<array{url:?string}>,
     *     landing_url:string,
     *     reference_url:string,
     *     updated_at:string
     * }>
     */
    private function sampleProducts(): array
    {
        return [
            [
                'code' => 'AKP-202610-000001',
                'name_key' => 'samples.products.foldable_organizer',
                'skus' => ['FDO-GRY-01', 'FDO-BLK-01', 'FDO-WHT-01'],
                'category' => 'home_living',
                'source' => 'tiktok',
                'type' => 'trending_product',
                'images' => [['url' => null], ['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000001',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000001',
                'updated_at' => '2026-10-19 09:36',
            ],
            [
                'code' => 'AKP-202610-000002',
                'name_key' => 'samples.products.portable_blender',
                'skus' => ['PBL-PNK-01'],
                'category' => 'kitchen',
                'source' => 'amazon',
                'type' => 'competitive_product',
                'images' => [['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000002',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000002',
                'updated_at' => '2026-10-19 08:52',
            ],
            [
                'code' => 'AKP-202610-000003',
                'name_key' => 'samples.products.pocket_projector',
                'skus' => ['PPR-SLV-01', 'PPR-BLU-01'],
                'category' => 'electronics',
                'source' => 'independent_store',
                'type' => 'seasonal_product',
                'images' => [['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000003',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000003',
                'updated_at' => '2026-10-18 21:14',
            ],
            [
                'code' => 'AKP-202610-000004',
                'name_key' => 'samples.products.pet_grooming_kit',
                'skus' => ['PGK-ORA-01', 'PGK-GRN-01', 'PGK-BLU-01', 'PGK-PNK-01'],
                'category' => 'pet_supplies',
                'source' => 'tiktok',
                'type' => 'trending_product',
                'images' => [['url' => null], ['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000004',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000004',
                'updated_at' => '2026-10-18 16:30',
            ],
            [
                'code' => 'AKP-202610-000005',
                'name_key' => 'samples.products.travel_jewelry_case',
                'skus' => ['TJC-BEI-01'],
                'category' => 'fashion_accessories',
                'source' => 'facebook',
                'type' => 'test_product',
                'images' => [['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000005',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000005',
                'updated_at' => '2026-10-18 11:05',
            ],
            [
                'code' => 'AKP-202610-000006',
                'name_key' => 'samples.products.led_book_light',
                'skus' => ['LBL-AMB-01', 'LBL-WHT-01'],
                'category' => 'home_living',
                'source' => 'amazon',
                'type' => 'competitive_product',
                'images' => [['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000006',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000006',
                'updated_at' => '2026-10-17 18:40',
            ],
        ];
    }

    /**
     * Temporary frontend-only sample data.
     *
     * @return list<array{
     *     code:string,
     *     name_key:string,
     *     specification_keys:list<string>,
     *     attribute_keys:list<string>,
     *     updated_at:string
     * }>
     */
    private function sampleSelectionProposals(): array
    {
        return [
            [
                'code' => 'SPR-202610-000001',
                'name_key' => 'samples.proposals.portable_camping_lamp',
                'specification_keys' => [
                    'samples.proposal_options.specifications.warm_white',
                    'samples.proposal_options.specifications.us_plug',
                ],
                'attribute_keys' => [
                    'samples.proposal_options.attributes.rechargeable',
                    'samples.proposal_options.attributes.waterproof',
                ],
                'updated_at' => '2026-10-19 10:26',
            ],
            [
                'code' => 'SPR-202610-000002',
                'name_key' => 'samples.proposals.travel_pill_organizer',
                'specification_keys' => [
                    'samples.proposal_options.specifications.seven_day',
                ],
                'attribute_keys' => [
                    'samples.proposal_options.attributes.portable',
                    'samples.proposal_options.attributes.large_capacity',
                ],
                'updated_at' => '2026-10-18 15:40',
            ],
        ];
    }

    /**
     * Temporary frontend-only sample data.
     *
     * @return list<array{
     *     code:string,
     *     product_code:string,
     *     product_name_key:string,
     *     start_date:string,
     *     end_date:string,
     *     records:list<array{platform:string,currency:string,spend:string,orders:string}>,
     *     updated_at:string
     * }>
     */
    private function samplePlacementEvaluations(): array
    {
        return [
            [
                'code' => 'ATE-202610-000001',
                'product_code' => 'AKP-202610-000001',
                'product_name_key' => 'samples.products.foldable_organizer',
                'start_date' => '2026-10-10',
                'end_date' => '2026-10-16',
                'records' => [
                    ['platform' => 'facebook', 'currency' => 'usd', 'spend' => '168.20', 'orders' => '12'],
                    ['platform' => 'tiktok', 'currency' => 'usd', 'spend' => '96.50', 'orders' => '8'],
                ],
                'updated_at' => '2026-10-19 09:15',
            ],
            [
                'code' => 'ATE-202610-000002',
                'product_code' => 'AKP-202610-000003',
                'product_name_key' => 'samples.products.pocket_projector',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-12',
                'records' => [
                    ['platform' => 'google', 'currency' => 'eur', 'spend' => '142.00', 'orders' => '6'],
                ],
                'updated_at' => '2026-10-18 18:22',
            ],
        ];
    }
}
