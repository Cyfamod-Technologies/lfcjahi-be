<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Speaker;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicMediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = MediaItem::query()
            ->where('is_published', true)
            ->when(
                $request->filled('category'),
                fn ($query) => $query->where('category', (string) $request->query('category')),
            )
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

    public function download(string $id): BinaryFileResponse|RedirectResponse|Response|StreamedResponse
    {
        $item = MediaItem::query()
            ->where('is_published', true)
            ->findOrFail($id);

        $mediaUrl = $item->media_url;
        if (! $mediaUrl) {
            abort(404);
        }

        $publicStoragePath = $this->extractPublicStoragePath($mediaUrl);
        if ($publicStoragePath && Storage::disk('public')->exists($publicStoragePath)) {
            MediaItem::withoutTimestamps(fn () => $item->increment('download_count'));

            return Storage::disk('public')->download($publicStoragePath, $this->downloadFilename($item, $publicStoragePath));
        }

        if (preg_match('/^https?:\/\//i', $mediaUrl) === 1) {
            MediaItem::withoutTimestamps(fn () => $item->increment('download_count'));

            return redirect()->away($mediaUrl);
        }

        abort(404);
    }

    public function share(string $id): View
    {
        $item = MediaItem::query()
            ->where('is_published', true)
            ->findOrFail($id);

        $speakerImagePath = $this->speakerImagePathsForItems([$item])[$item->speaker ?? ''] ?? null;
        $payload = $this->serializeItem($item, $speakerImagePath);
        $title = trim((string) ($payload['title'] ?? 'Audio Message'));
        $speaker = trim((string) ($payload['speaker'] ?? 'LFC Jahi'));
        $service = trim((string) ($payload['subcategory'] ?? 'Service'));
        $date = trim((string) ($payload['mediaDate'] ?? ''));
        $formattedDate = $date !== '' ? Carbon::parse($date)->format('F j, Y') : '';
        $descriptionParts = array_filter([
            $title !== '' ? $title : 'Audio Message',
            $service !== '' ? $service : null,
            $speaker !== '' ? 'by '.$speaker : null,
            $formattedDate !== '' ? 'on '.$formattedDate : null,
        ]);
        $metaTitle = implode(' | ', array_filter([
            $title !== '' ? $title : 'Audio Message',
            $speaker !== '' ? $speaker : null,
        ]));

        return view('message-share', [
            'title' => $metaTitle !== '' ? $metaTitle : 'Audio Message',
            'description' => implode(' ', $descriptionParts).'. Listen and download the message from LFC Jahi.',
            'image' => $payload['thumbnailUrl'] ?: $payload['speakerImageUrl'] ?: $this->frontendAssetUrl('/images/og-image.jpg'),
            'shareUrl' => $this->frontendMessageUrl((string) $item->id),
            'frontendUrl' => $this->frontendMessageUrl((string) $item->id),
            'speaker' => $speaker,
            'service' => $service,
            'date' => $formattedDate,
        ]);
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
            'speakerImageUrl' => $this->absoluteUrl($speakerImagePath),
            'mediaUrl' => $this->absoluteUrl($item->media_url),
            'downloadUrl' => $this->publicUrl('/api/media/'.$item->id.'/download'),
            'shareUrl' => $this->frontendMessageUrl((string) $item->id),
            'downloadCount' => (int) ($item->download_count ?? 0),
            'mediaSourceType' => $item->media_source_type ?? '',
            'isPublished' => (bool) $item->is_published,
            'createdAt' => $item->created_at?->toISOString(),
            'updatedAt' => $item->updated_at?->toISOString(),
        ];
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

    private function frontendMessageUrl(string $id): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', 'https://lfcjahi.com'), '/');

        return $baseUrl.'/messages/'.urlencode($id);
    }

    private function frontendAssetUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url', 'https://lfcjahi.com'), '/').'/'.ltrim($path, '/');
    }

    private function downloadFilename(MediaItem $item, string $publicStoragePath): string
    {
        $extension = pathinfo($publicStoragePath, PATHINFO_EXTENSION);
        $fallbackName = basename($publicStoragePath);
        $date = $item->media_date?->format('Y-m-d')
            ?: $item->created_at?->format('Y-m-d')
            ?: now()->format('Y-m-d');
        $slug = Str::slug(implode(' ', array_filter([
            $item->title ?: 'message',
            $item->speaker ?: 'lfc-jahi',
            $date,
        ])));

        if (! $slug) {
            return $fallbackName;
        }

        return $extension ? $slug.'.'.$extension : $fallbackName;
    }
}
