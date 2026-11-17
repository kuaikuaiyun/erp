<?php

namespace App\Portals\Aikaipin;

use App\Models\AikaipinProduct;
use App\Models\OperationAiProductReferenceVideo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AikaipinHistoricalVideoImporter
{
    /** @return array{found:int,imported:int,skipped:int,failed:int,errors:list<string>} */
    public function import(AikaipinProduct $product, bool $dryRun = false): array
    {
        $result = ['found' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $reference = $product->referenceFilePayload();
        if ($reference === null || ! Storage::disk($reference['disk'])->exists($reference['path'])) return $result;

        $total = $this->countVideos((string) $reference['disk'], (string) $reference['path']);
        if ($total === 0) return $result;
        if (! $dryRun) $this->normalizeUploadedSequences($product, $total);

        $existing = OperationAiProductReferenceVideo::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->where('source_kind', OperationAiProductReferenceVideo::SOURCE_HISTORICAL_HTML)
            ->whereNotNull('source_reference_index')
            ->pluck('source_reference_index')->map(fn ($value): int => (int) $value)->all();

        $hidden = [];
        $this->scan((string) $reference['disk'], (string) $reference['path'], function (array $source) use ($product, $reference, $dryRun, $existing, &$hidden, &$result): void {
            $result['found']++;
            $index = (int) $source['index'];
            if (in_array($index, $existing, true)) {
                $this->deleteTemporarySource($source);
                $result['skipped']++;
                $hidden[] = $index;
                return;
            }

            if ($dryRun) {
                try {
                    if ($this->recoverable($source, $reference)) $result['imported']++;
                    else $result['failed']++;
                } finally {
                    $this->deleteTemporarySource($source);
                }
                return;
            }

            try {
                $stored = $this->storeSource($product, $source, $reference);
                OperationAiProductReferenceVideo::query()->create([
                    'aikaipin_product_id' => $product->getKey(),
                    'type' => $source['type'],
                    'sequence' => $index + 1,
                    'file_date' => $product->created_at?->copy()->timezone('Asia/Shanghai')->toDateString() ?: now('Asia/Shanghai')->toDateString(),
                    'disk' => 'local',
                    'path' => $stored['path'],
                    'file_name' => $stored['file_name'],
                    'original_name' => $stored['original_name'],
                    'mime_type' => $stored['mime_type'],
                    'size' => $stored['size'],
                    'department_code' => 'AI',
                    'source_kind' => OperationAiProductReferenceVideo::SOURCE_HISTORICAL_HTML,
                    'source_reference_index' => $index,
                    'source_url' => $source['source_label'],
                    'uploaded_by' => null,
                    'uploaded_by_name' => '历史 HTML 导入',
                ]);
                $hidden[] = $index;
                $result['imported']++;
            } catch (\Throwable $exception) {
                if (isset($stored['path'])) Storage::disk('local')->delete($stored['path']);
                $this->deleteTemporarySource($source);
                $result['failed']++;
                $result['errors'][] = '第 '.($index + 1).' 个视频：'.$exception->getMessage();
            }
        });

        if (! $dryRun && $hidden !== []) $this->hideImportedVideos($reference, $hidden);

        return $result;
    }

    private function normalizeUploadedSequences(AikaipinProduct $product, int $historicalCount): void
    {
        DB::connection($product->getConnectionName())->transaction(function () use ($product, $historicalCount): void {
            $videos = OperationAiProductReferenceVideo::query()
                ->where('aikaipin_product_id', $product->getKey())
                ->where(function ($query): void {
                    $query->whereNull('source_kind')->orWhere('source_kind', '!=', OperationAiProductReferenceVideo::SOURCE_HISTORICAL_HTML);
                })
                ->orderBy('sequence')->orderBy('id')->lockForUpdate()->get();
            if ($videos->isEmpty()) return;

            $temporaryBase = (int) OperationAiProductReferenceVideo::query()->where('aikaipin_product_id', $product->getKey())->max('sequence') + $videos->count() + $historicalCount + 1000;
            foreach ($videos as $offset => $video) $video->forceFill(['sequence' => $temporaryBase + $offset])->saveQuietly();
            foreach ($videos->values() as $offset => $video) $video->forceFill(['sequence' => $historicalCount + $offset + 1])->saveQuietly();
        });
    }

    private function countVideos(string $disk, string $path): int
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) return 0;
        $count = 0; $tail = '';
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false || $chunk === '') continue;
                $buffer = $tail.$chunk;
                $count += (preg_match_all('/<video\b/i', $buffer) ?: 0)
                    - (preg_match_all('/<video\b/i', $tail) ?: 0);
                $tail = substr($buffer, -16);
            }
        } finally { fclose($stream); }

        return $count;
    }

    /** @param callable(array{index:int,type:string,value:string,is_data:bool,mime:?string,temp_path:?string,source_label:string}):void $consumer */
    private function scan(string $disk, string $path, callable $consumer): void
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) throw new RuntimeException('无法读取参考页文件。');

        $type = OperationAiProductReferenceVideo::TYPE_FLOW;
        $insideVideo = false; $sourceFound = false; $videoIndex = -1; $window = ''; $capture = null;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 262144);
                if ($chunk === false || $chunk === '') continue;
                $offset = 0; $length = strlen($chunk);
                while ($offset < $length) {
                    if ($capture !== null) {
                        $end = strpos($chunk, $capture['quote'], $offset);
                        $part = substr($chunk, $offset, $end === false ? $length - $offset : $end - $offset);
                        $this->consumeCapturedValue($capture, $part);
                        if ($end === false) { $offset = $length; continue; }
                        $this->finishCapture($capture);
                        $consumer($capture);
                        $sourceFound = true; $capture = null; $window = ''; $offset = $end + 1;
                        continue;
                    }

                    $window .= $chunk[$offset++];
                    if (strlen($window) > 512) $window = substr($window, -512);
                    $lower = strtolower($window);
                    if (str_ends_with($window, '混剪素材')) $type = OperationAiProductReferenceVideo::TYPE_REMIX;
                    elseif (str_ends_with($window, '扒的素材') || str_ends_with($window, '引流素材')) $type = OperationAiProductReferenceVideo::TYPE_TRAFFIC;
                    elseif (preg_match('/Flow\s*素材$/i', $window) === 1) $type = OperationAiProductReferenceVideo::TYPE_FLOW;

                    if (str_ends_with($lower, '<video')) {
                        $insideVideo = true; $sourceFound = false; $videoIndex++;
                    } elseif ($insideVideo && str_ends_with($lower, '</video')) {
                        $insideVideo = false;
                    } elseif ($insideVideo && ! $sourceFound && preg_match('/\bsrc\s*=\s*([\'\"])$/i', $window, $match) === 1) {
                        $capture = $this->newCapture($videoIndex, $type, $match[1]);
                        $window = '';
                    }
                }
            }
        } finally {
            if (is_resource($capture['handle'] ?? null)) fclose($capture['handle']);
            if (is_array($capture)) $this->deleteTemporarySource($capture);
            fclose($stream);
        }
    }

    /** @return array<string,mixed> */
    private function newCapture(int $index, string $type, string $quote): array
    {
        $relative = 'aikaipin/reference-videos/.historical-import/'.uniqid('video-', true).'.part';
        Storage::disk('local')->makeDirectory(dirname($relative));

        return ['index' => $index, 'type' => $type, 'quote' => $quote, 'mode' => null, 'prefix' => '', 'value' => '',
            'mime' => null, 'base64_carry' => '', 'temp_path' => $relative, 'handle' => null, 'is_data' => false, 'source_label' => ''];
    }

    /** @param array<string,mixed> $capture */
    private function consumeCapturedValue(array &$capture, string $part): void
    {
        if ($capture['mode'] === null) {
            $capture['prefix'] .= $part;
            if (strlen($capture['prefix']) < 5) return;
            if (! str_starts_with(strtolower($capture['prefix']), 'data:')) {
                $capture['mode'] = 'url'; $capture['value'] = $capture['prefix']; $capture['prefix'] = '';
                return;
            }
            $comma = strpos($capture['prefix'], ',');
            if ($comma === false) {
                if (strlen($capture['prefix']) > 1024) throw new RuntimeException('视频 data URI 头部无效。');
                return;
            }
            $header = substr($capture['prefix'], 0, $comma);
            if (! str_contains(strtolower($header), ';base64')) throw new RuntimeException('仅支持 base64 格式的内嵌视频。');
            $capture['mode'] = 'base64'; $capture['is_data'] = true;
            $capture['mime'] = preg_match('/^data:([^;,]+)/i', $header, $match) === 1 ? strtolower($match[1]) : 'video/mp4';
            $capture['source_label'] = $capture['mime'].';base64';
            $capture['handle'] = fopen(Storage::disk('local')->path($capture['temp_path']), 'wb');
            if (! is_resource($capture['handle'])) throw new RuntimeException('无法创建视频临时文件。');
            $part = substr($capture['prefix'], $comma + 1); $capture['prefix'] = '';
        }

        if ($capture['mode'] === 'url') { $capture['value'] .= $part; return; }
        $encoded = $capture['base64_carry'].preg_replace('/\s+/', '', $part);
        $length = strlen($encoded) - (strlen($encoded) % 4);
        if ($length <= 0) { $capture['base64_carry'] = $encoded; return; }
        $decoded = base64_decode(substr($encoded, 0, $length), true);
        if ($decoded === false) throw new RuntimeException('视频 base64 数据损坏。');
        fwrite($capture['handle'], $decoded); $capture['base64_carry'] = substr($encoded, $length);
    }

    /** @param array<string,mixed> $capture */
    private function finishCapture(array &$capture): void
    {
        if ($capture['mode'] === null) {
            $capture['mode'] = 'url'; $capture['value'] = $capture['prefix']; $capture['prefix'] = '';
        }
        if ($capture['mode'] === 'base64') {
            if ($capture['base64_carry'] !== '') {
                $decoded = base64_decode($capture['base64_carry'], true);
                if ($decoded === false) throw new RuntimeException('视频 base64 尾部数据损坏。');
                fwrite($capture['handle'], $decoded);
            }
            fclose($capture['handle']); $capture['handle'] = null;
        } else {
            $capture['value'] = html_entity_decode(trim($capture['value']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $capture['source_label'] = mb_substr($capture['value'], 0, 1000);
        }
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $reference */
    private function recoverable(array $source, array $reference): bool
    {
        if ($source['is_data']) return Storage::disk('local')->size($source['temp_path']) > 0;
        $value = (string) $source['value'];
        if (preg_match('#^https?://#i', $value) === 1 || str_starts_with($value, '//')) return true;
        if (str_starts_with(strtolower($value), 'blob:') || $value === '') return false;

        return Storage::disk($reference['disk'])->exists($this->relativeSourcePath($reference['path'], $value));
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $reference @return array{path:string,file_name:string,original_name:string,mime_type:string,size:int} */
    private function storeSource(AikaipinProduct $product, array $source, array $reference): array
    {
        $temp = (string) $source['temp_path'];
        if (! $source['is_data']) {
            $value = (string) $source['value'];
            if (str_starts_with($value, '//')) $value = 'https:'.$value;
            if (preg_match('#^https?://#i', $value) === 1) {
                $response = Http::withOptions(['sink' => Storage::disk('local')->path($temp), 'allow_redirects' => ['max' => 5]])->timeout(180)->get($value);
                if (! $response->successful()) throw new RuntimeException('远程视频下载失败（HTTP '.$response->status().'）。');
            } elseif (! str_starts_with(strtolower($value), 'blob:') && $value !== '') {
                $sourcePath = $this->relativeSourcePath($reference['path'], $value);
                if (! Storage::disk($reference['disk'])->exists($sourcePath)) throw new RuntimeException('相对路径视频文件不存在。');
                $input = Storage::disk($reference['disk'])->readStream($sourcePath);
                $output = fopen(Storage::disk('local')->path($temp), 'wb');
                if (! is_resource($input) || ! is_resource($output)) throw new RuntimeException('视频文件读取失败。');
                stream_copy_to_stream($input, $output); fclose($input); fclose($output);
            } else throw new RuntimeException('blob 视频地址无法在页面关闭后恢复。');
        }

        $absolute = Storage::disk('local')->path($temp);
        $size = is_file($absolute) ? (int) filesize($absolute) : 0;
        if ($size <= 0) throw new RuntimeException('提取到的视频文件为空。');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: ($source['mime'] ?: 'video/mp4');
        $extension = $this->extension($mime, (string) ($source['value'] ?? ''));
        $date = $product->created_at?->copy()->timezone('Asia/Shanghai')->format('ymd') ?: now('Asia/Shanghai')->format('ymd');
        $fileName = $this->productCode($product).'-'.OperationAiProductReferenceVideo::fileTypeCode($source['type']).'-'.$date.'-'.str_pad((string) ($source['index'] + 1), 3, '0', STR_PAD_LEFT).'.'.$extension;
        $final = 'aikaipin/reference-videos/'.$product->getKey().'/'.$fileName;
        Storage::disk('local')->makeDirectory(dirname($final));
        if (Storage::disk('local')->exists($final)) Storage::disk('local')->delete($final);
        if (! Storage::disk('local')->move($temp, $final)) throw new RuntimeException('视频文件保存失败。');

        return ['path' => $final, 'file_name' => $fileName, 'original_name' => basename(parse_url((string) ($source['value'] ?? ''), PHP_URL_PATH) ?: $fileName), 'mime_type' => $mime, 'size' => $size];
    }

    private function relativeSourcePath(string $htmlPath, string $value): string
    {
        $path = rawurldecode((string) parse_url($value, PHP_URL_PATH));
        $path = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', dirname($htmlPath).'/'.$path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') { array_pop($segments); continue; }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function extension(string $mime, string $source): string
    {
        return match (strtolower($mime)) {
            'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv', 'video/x-m4v' => 'm4v',
            default => in_array(strtolower(pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION)), ['mp4','mov','avi','webm','mkv','m4v'], true)
                ? strtolower(pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION)) : 'mp4',
        };
    }

    private function productCode(AikaipinProduct $product): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey();
    }

    /** @param array<string,mixed> $source */
    private function deleteTemporarySource(array $source): void
    {
        $path = (string) ($source['temp_path'] ?? '');
        if ($path !== '') Storage::disk('local')->delete($path);
    }

    /** @param array<string,mixed> $reference @param list<int> $indexes */
    private function hideImportedVideos(array $reference, array $indexes): void
    {
        $disk = Storage::disk($reference['disk']);
        $path = $reference['path'].'.erp-hidden-videos.json';
        $existing = $disk->exists($path) ? (array) (json_decode($disk->get($path), true) ?: []) : [];
        $hidden = collect([...$existing, ...$indexes])->map(fn ($value): int => (int) $value)->filter(fn (int $value): bool => $value >= 0)->unique()->sort()->values()->all();
        $disk->put($path, json_encode($hidden, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
