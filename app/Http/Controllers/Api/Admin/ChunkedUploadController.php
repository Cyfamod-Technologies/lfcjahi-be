<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChunkedUploadController extends Controller
{
    private const CHUNK_DISK = 'local';
    private const CHUNK_DIR = 'upload-chunks';
    private const MAX_CHUNK_SIZE_KB = 3072;
    private const MAX_TOTAL_CHUNKS = 500;

    public function uploadChunk(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id'    => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'chunk_index'  => ['required', 'integer', 'min:0', 'max:' . (self::MAX_TOTAL_CHUNKS - 1)],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:' . self::MAX_TOTAL_CHUNKS],
            'chunk'        => ['required', 'file', 'max:' . self::MAX_CHUNK_SIZE_KB],
        ]);

        $uploadId   = $request->input('upload_id');
        $chunkIndex = (int) $request->input('chunk_index');

        Storage::disk(self::CHUNK_DISK)->put(
            self::CHUNK_DIR . '/' . $uploadId . '/' . $chunkIndex,
            $request->file('chunk')->getContent(),
        );

        return response()->json(['status' => 'ok', 'chunk' => $chunkIndex]);
    }

    public function finalizeUpload(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id'     => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'total_chunks'  => ['required', 'integer', 'min:1', 'max:' . self::MAX_TOTAL_CHUNKS],
            'original_name' => ['nullable', 'string', 'max:255'],
            'extension'     => ['required', 'string', 'in:mp3,wav,m4a,aac,ogg'],
        ]);

        $uploadId    = $request->input('upload_id');
        $totalChunks = (int) $request->input('total_chunks');
        $extension   = strtolower((string) $request->input('extension'));
        $originalName = (string) ($request->input('original_name') ?? 'audio');

        for ($i = 0; $i < $totalChunks; $i++) {
            if (! Storage::disk(self::CHUNK_DISK)->exists(self::CHUNK_DIR . '/' . $uploadId . '/' . $i)) {
                return response()->json(['message' => "Chunk {$i} is missing. Please retry the upload."], 422);
            }
        }

        $slug      = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'audio';
        $suffix    = now()->format('YmdHis') . '-' . Str::lower(Str::random(8));
        $filename  = $slug . '-' . $suffix . '.' . $extension;
        $finalPath = 'media-audio/' . $filename;

        $tmpFile = tempnam(sys_get_temp_dir(), 'lfc_chunk_');
        $dest    = fopen($tmpFile, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $src = Storage::disk(self::CHUNK_DISK)->readStream(self::CHUNK_DIR . '/' . $uploadId . '/' . $i);
            stream_copy_to_stream($src, $dest);
            fclose($src);
        }

        fclose($dest);

        $handle = fopen($tmpFile, 'rb');
        Storage::disk('public')->put($finalPath, $handle);
        fclose($handle);
        @unlink($tmpFile);

        Storage::disk(self::CHUNK_DISK)->deleteDirectory(self::CHUNK_DIR . '/' . $uploadId);

        return response()->json(['data' => ['url' => '/storage/' . $finalPath]]);
    }
}
