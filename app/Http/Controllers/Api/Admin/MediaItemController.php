<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaSubcategory;
use App\Models\MediaItem;
use App\Models\Speaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MediaItemController extends Controller
{
    private const MAX_AUDIO_FILE_SIZE_KB = 204800;

    public function storageStats(): JsonResponse
    {
        $files = Storage::disk('public')->files('media-audio');
        $totalBytes = 0;

        foreach ($files as $file) {
            $totalBytes += Storage::disk('public')->size($file);
        }

        return response()->json([
            'data' => [
                'audioSizeBytes' => $totalBytes,
                'audioFileCount' => count($files),
            ],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $items = MediaItem::query()
            ->orderByDesc('media_date')
            ->orderByDesc('created_at')
            ->get();
        $speakerImagePaths = $this->speakerImagePathsForItems($items);

        return response()->json([
            'data' => $items->map(
                fn (MediaItem $item): array => $this->serializeItem($item, $speakerImagePaths[$item->speaker ?? ''] ?? null)
            )->values(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, null);
        $validated['is_published'] = (bool) ($validated['is_published'] ?? true);

        if ($request->hasFile('thumbnail_file')) {
            $path = $request->file('thumbnail_file')->store('media-thumbnails', 'public');
            $validated['thumbnail_url'] = $this->publicStoragePath($path);
        }

        if ($request->hasFile('audio_file')) {
            $filename = $this->buildManagedAudioFilename($request->file('audio_file'));
            $path = $request->file('audio_file')->storeAs('media-audio', $filename, 'public');
            $validated['media_url'] = $this->publicStoragePath($path);
            $validated['media_source_type'] = 'file';
        }

        $item = MediaItem::create($validated);

        return response()->json([
            'data' => $this->serializeItem($item, $this->speakerImagePathForName($item->speaker)),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $item = MediaItem::query()->findOrFail($id);

        return response()->json([
            'data' => $this->serializeItem($item, $this->speakerImagePathForName($item->speaker)),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $item = MediaItem::query()->findOrFail($id);
        $previousThumbnailUrl = $item->thumbnail_url;
        $previousMediaUrl = $item->media_url;
        $previousMediaSourceType = $item->media_source_type;

        $validated = $this->validatePayload($request, $item);

        if ($request->hasFile('thumbnail_file')) {
            $this->deleteManagedPublicFile($previousThumbnailUrl);
            $path = $request->file('thumbnail_file')->store('media-thumbnails', 'public');
            $validated['thumbnail_url'] = $this->publicStoragePath($path);
        }

        if ($request->hasFile('audio_file')) {
            $this->deleteManagedPublicFile($previousMediaUrl);
            $filename = $this->buildManagedAudioFilename($request->file('audio_file'));
            $path = $request->file('audio_file')->storeAs('media-audio', $filename, 'public');
            $validated['media_url'] = $this->publicStoragePath($path);
            $validated['media_source_type'] = 'file';
        } elseif (
            ($validated['media_source_type'] ?? null) !== 'file'
            && $previousMediaSourceType === 'file'
        ) {
            $this->deleteManagedPublicFile($previousMediaUrl);
        }

        $item->fill($validated);
        $item->save();

        return response()->json([
            'data' => $this->serializeItem($item, $this->speakerImagePathForName($item->speaker)),
        ]);
    }

    public function updatePublishStatus(Request $request, string $id): JsonResponse
    {
        $item = MediaItem::query()->findOrFail($id);
        $validated = $request->validate([
            'is_published' => ['required', 'boolean'],
        ]);

        $nextPublished = (bool) $validated['is_published'];
        if ($nextPublished && empty($item->media_url)) {
            $nextPublished = false;
        }

        $item->is_published = $nextPublished;
        $item->save();

        return response()->json([
            'data' => $this->serializeItem($item, $this->speakerImagePathForName($item->speaker)),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $item = MediaItem::query()->findOrFail($id);
        $this->deleteManagedPublicFile($item->thumbnail_url);

        if ($item->media_source_type === 'file') {
            $this->deleteManagedPublicFile($item->media_url);
        }

        $item->delete();

        return response()->json([
            'message' => 'Media item deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?MediaItem $existingItem): array
    {
        $audioUpload = $request->file('audio_file');
        if ($audioUpload instanceof UploadedFile && ! $audioUpload->isValid()) {
            $message = $audioUpload->getErrorMessage();

            if (str_contains(strtolower($message), 'exceeds')) {
                $message .= ' Increase PHP upload_max_filesize/post_max_size and restart the backend server.';
            }

            throw ValidationException::withMessages([
                'audio_file' => [$message],
            ]);
        }

        $request->merge([
            'media_date' => $request->input('media_date', $request->input('mediaDate')),
            'thumbnail_url' => $request->input('thumbnail_url', $request->input('thumbnailUrl')),
            'media_url' => $request->input('media_url', $request->input('mediaUrl')),
            'media_source_type' => $request->input('media_source_type', $request->input('mediaSourceType')),
            'is_published' => $request->input('is_published', $request->input('isPublished')),
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:255', Rule::exists('media_categories', 'name')],
            'subcategory' => ['nullable', 'string', 'max:255'],
            'speaker' => ['nullable', 'string', 'max:255', Rule::exists('speakers', 'name')],
            'media_date' => ['nullable', 'date'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'thumbnail_file' => ['nullable', 'image', 'max:5120'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'media_source_type' => ['nullable', Rule::in(['link', 'file'])],
            'is_published' => ['nullable', 'boolean'],
            'audio_file' => [
                'nullable',
                'file',
                'mimes:mp3,wav,m4a,aac,ogg',
                'max:' . self::MAX_AUDIO_FILE_SIZE_KB,
            ],
        ]);

        $validated['title'] = trim((string) $validated['title']);
        $validated['description'] = array_key_exists('description', $validated)
            ? trim((string) ($validated['description'] ?? ''))
            : null;
        $validated['category'] = trim((string) $validated['category']);
        $category = $validated['category'];
        $subcategory = trim((string) ($validated['subcategory'] ?? ''));
        $validated['subcategory'] = $subcategory !== '' ? $subcategory : null;
        $validated['speaker'] = trim((string) ($validated['speaker'] ?? '')) ?: null;
        $sourceType = $validated['media_source_type'] ?? null;

        if ($subcategory !== '') {
            $subcategoryRecord = MediaSubcategory::query()
                ->with('category')
                ->where('name', $subcategory)
                ->first();

            if ($subcategoryRecord && $subcategoryRecord->category?->name !== $category) {
                throw ValidationException::withMessages([
                    'subcategory' => ['Selected subcategory does not belong to the selected category.'],
                ]);
            }
        }

        if ($this->isAudioCategory($category)) {
            if (! $sourceType) {
                $sourceType = $request->hasFile('audio_file') ? 'file' : 'link';
            }

            $existingMediaUrl = $existingItem?->media_url;
            $hasMediaUrl = ! empty($validated['media_url']) || ! empty($existingMediaUrl);
            $hasAudioUpload = $request->hasFile('audio_file');

            if (! $hasMediaUrl && ! $hasAudioUpload) {
                $validated['is_published'] = false;
                $validated['media_source_type'] = null;
            } elseif ($sourceType === 'file' && ! $hasAudioUpload && ! $hasMediaUrl) {
                throw ValidationException::withMessages([
                    'audio_file' => ['Audio file is required when source type is file.'],
                ]);
            } elseif ($sourceType === 'link' && ! $hasMediaUrl) {
                throw ValidationException::withMessages([
                    'media_url' => ['Audio link is required when source type is link.'],
                ]);
            } else {
                $validated['media_source_type'] = $sourceType;
            }
        } else {
            $validated['media_source_type'] = null;
        }

        $this->ensureNoDuplicateMediaItem($validated, $existingItem);

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(MediaItem $item, ?string $speakerImagePath = null): array
    {
        $resolvedThumbnailPath = $item->thumbnail_url ?: $speakerImagePath;

        return [
            'id' => (string) $item->id,
            'title' => $item->title,
            'description' => $item->description ?? '',
            'category' => $item->category,
            'subcategory' => $item->subcategory ?? '',
            'speaker' => $item->speaker ?? '',
            'mediaDate' => $item->media_date?->format('Y-m-d') ?? '',
            'thumbnailUrl' => $this->absoluteUrl($resolvedThumbnailPath),
            'customThumbnailUrl' => $this->absoluteUrl($item->thumbnail_url),
            'speakerImageUrl' => $this->absoluteUrl($speakerImagePath),
            'mediaUrl' => $this->absoluteUrl($item->media_url),
            'downloadCount' => (int) ($item->download_count ?? 0),
            'mediaSourceType' => $item->media_source_type ?? '',
            'isPublished' => (bool) $item->is_published,
            'createdAt' => $item->created_at?->toISOString(),
            'updatedAt' => $item->updated_at?->toISOString(),
        ];
    }

    private function absoluteUrl(?string $path): string
    {
        if (! $path) {
            return '';
        }

        if (str_starts_with($path, '/')) {
            return $this->publicUrl($path);
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            $parsedPath = (string) parse_url($path, PHP_URL_PATH);
            $query = (string) parse_url($path, PHP_URL_QUERY);

            if (str_starts_with($parsedPath, '/storage/')) {
                return $this->publicUrl($parsedPath.($query !== '' ? '?'.$query : ''));
            }

            return $path;
        }

        return $path;
    }

    private function deleteManagedPublicFile(?string $fileUrl): void
    {
        $publicPath = $this->extractPublicStoragePath($fileUrl);

        if (! $publicPath) {
            return;
        }

        if (Storage::disk('public')->exists($publicPath)) {
            Storage::disk('public')->delete($publicPath);
        }
    }

    private function extractPublicStoragePath(?string $fileUrl): ?string
    {
        if (! $fileUrl) {
            return null;
        }

        $prefix = '/storage/';

        if (str_starts_with($fileUrl, $prefix)) {
            return substr($fileUrl, strlen($prefix));
        }

        $parsedPath = (string) parse_url($fileUrl, PHP_URL_PATH);
        if (str_starts_with($parsedPath, $prefix)) {
            return substr($parsedPath, strlen($prefix));
        }

        return null;
    }

    private function publicStoragePath(string $path): string
    {
        return '/storage/'.ltrim($path, '/');
    }

    /**
     * @param iterable<MediaItem> $items
     * @return array<string, string>
     */
    private function speakerImagePathsForItems(iterable $items): array
    {
        $names = [];

        foreach ($items as $item) {
            $name = trim((string) ($item->speaker ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        if ($names === []) {
            return [];
        }

        return Speaker::query()
            ->whereIn('name', $names)
            ->whereNotNull('image_url')
            ->get(['name', 'image_url'])
            ->filter(fn (Speaker $speaker): bool => trim((string) $speaker->image_url) !== '')
            ->mapWithKeys(fn (Speaker $speaker): array => [$speaker->name => (string) $speaker->image_url])
            ->all();
    }

    private function speakerImagePathForName(?string $speakerName): ?string
    {
        $name = trim((string) $speakerName);
        if ($name === '') {
            return null;
        }

        return Speaker::query()
            ->where('name', $name)
            ->value('image_url');
    }

    private function publicUrl(string $path): string
    {
        $request = request();
        $normalizedPath = '/'.ltrim($path, '/');

        if (! $request) {
            return rtrim(config('app.url'), '/').$normalizedPath;
        }

        $forwardedProto = trim(explode(',', (string) $request->headers->get('x-forwarded-proto', ''))[0] ?? '');
        $forwardedHost = trim(explode(',', (string) $request->headers->get('x-forwarded-host', ''))[0] ?? '');
        $forwardedPort = trim(explode(',', (string) $request->headers->get('x-forwarded-port', ''))[0] ?? '');

        $scheme = $forwardedProto !== '' ? $forwardedProto : $request->getScheme();
        $host = $forwardedHost !== '' ? $forwardedHost : $request->getHost();
        $port = $forwardedPort !== '' ? (int) $forwardedPort : $request->getPort();
        $includePort = $port > 0
            && ! in_array([$scheme, $port], [['http', 80], ['https', 443]], true);

        return $scheme.'://'.$host.($includePort ? ':'.$port : '').$normalizedPath;
    }

    private function isAudioCategory(string $category): bool
    {
        return strtolower(trim($category)) === 'audio';
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function ensureNoDuplicateMediaItem(array $validated, ?MediaItem $existingItem): void
    {
        $duplicateQuery = MediaItem::query()
            ->whereRaw('LOWER(title) = ?', [mb_strtolower((string) $validated['title'])])
            ->where('category', $validated['category']);

        $speaker = $validated['speaker'] ?? null;
        if ($speaker === null) {
            $duplicateQuery->whereNull('speaker');
        } else {
            $duplicateQuery->where('speaker', $speaker);
        }

        $subcategory = $validated['subcategory'] ?? null;
        if ($subcategory === null) {
            $duplicateQuery->whereNull('subcategory');
        } else {
            $duplicateQuery->where('subcategory', $subcategory);
        }

        $mediaDate = $validated['media_date'] ?? null;
        if ($mediaDate === null || $mediaDate === '') {
            $duplicateQuery->whereNull('media_date');
        } else {
            $duplicateQuery->whereDate('media_date', $mediaDate);
        }

        if ($existingItem) {
            $duplicateQuery->whereKeyNot($existingItem->getKey());
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'title' => ['A media item with the same title, speaker, service, category, and date already exists.'],
            ]);
        }
    }

    private function safeAudioOriginalFilename(?UploadedFile $file): string
    {
        if (! $file) {
            return '';
        }

        return basename($file->getClientOriginalName());
    }

    private function buildManagedAudioFilename(?UploadedFile $file): string
    {
        $originalName = pathinfo($this->safeAudioOriginalFilename($file), PATHINFO_FILENAME);
        $extension = strtolower((string) ($file?->getClientOriginalExtension() ?: $file?->extension() ?: ''));
        $slug = Str::slug($originalName);

        if ($slug === '') {
            $slug = 'audio';
        }

        $suffix = now()->format('YmdHis').'-'.Str::lower(Str::random(8));

        return $slug.'-'.$suffix.($extension !== '' ? '.'.$extension : '');
    }
}
