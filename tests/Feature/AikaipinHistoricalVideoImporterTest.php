<?php

namespace Tests\Feature;

use App\Models\AikaipinProduct;
use App\Models\OperationAiProductReferenceVideo;
use App\Portals\Aikaipin\AikaipinHistoricalVideoImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AikaipinHistoricalVideoImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->create('aikaipin_products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_code')->unique();
            $table->string('name');
            $table->string('source_platform')->default('Shopify');
            $table->string('product_type')->default('normal');
            $table->json('reference_file')->nullable();
            $table->unsignedBigInteger('created_by')->default(1);
            $table->timestamps();
        });
        $schema->create('operation_ai_product_reference_videos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('aikaipin_product_id');
            $table->string('type', 20);
            $table->unsignedInteger('sequence');
            $table->date('file_date');
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('file_name');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('department_code')->nullable();
            $table->string('source_kind', 30)->default('upload');
            $table->unsignedInteger('source_reference_index')->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->timestamps();
            $table->unique(['aikaipin_product_id', 'file_date', 'sequence']);
            $table->unique(['aikaipin_product_id', 'source_kind', 'source_reference_index']);
        });
    }

    public function test_it_moves_historical_html_videos_into_the_video_table_once(): void
    {
        $embedded = base64_encode('embedded-video-content');
        Storage::disk('local')->put('references/product.html', <<<HTML
            <h2>Flow素材</h2>
            <video controls src="data:video/mp4;base64,{$embedded}"></video>
            <h2>扒的素材</h2>
            <video controls><source src="traffic.mp4" type="video/mp4"></video>
            HTML);
        Storage::disk('local')->put('references/traffic.mp4', 'relative-video-content');

        $product = AikaipinProduct::query()->create([
            'product_code' => 'kky-test',
            'name' => '测试产品',
            'reference_file' => [
                'disk' => 'local',
                'path' => 'references/product.html',
                'original_name' => 'product.html',
                'mime_type' => 'text/html',
                'size' => Storage::disk('local')->size('references/product.html'),
            ],
        ]);
        OperationAiProductReferenceVideo::query()->create([
            'aikaipin_product_id' => $product->getKey(),
            'type' => OperationAiProductReferenceVideo::TYPE_REMIX,
            'sequence' => 1,
            'file_date' => $product->created_at->toDateString(),
            'disk' => 'local',
            'path' => 'existing/upload.mp4',
            'file_name' => 'kky-test-mix-001.mp4',
            'original_name' => 'upload.mp4',
            'source_kind' => OperationAiProductReferenceVideo::SOURCE_UPLOAD,
        ]);

        $result = app(AikaipinHistoricalVideoImporter::class)->import($product);

        self::assertSame(['found' => 2, 'imported' => 2, 'skipped' => 0, 'failed' => 0, 'errors' => []], $result);
        $videos = OperationAiProductReferenceVideo::query()->where('aikaipin_product_id', $product->getKey())->orderBy('sequence')->get();
        self::assertSame(['flow', 'traffic', 'remix'], $videos->pluck('type')->all());
        self::assertSame([1, 2, 3], $videos->pluck('sequence')->all());
        self::assertSame([0, 1], $videos->take(2)->pluck('source_reference_index')->all());
        self::assertTrue(Storage::disk('local')->exists($videos[0]->path));
        self::assertTrue(Storage::disk('local')->exists($videos[1]->path));
        self::assertSame([0, 1], json_decode(Storage::disk('local')->get('references/product.html.erp-hidden-videos.json'), true));

        $again = app(AikaipinHistoricalVideoImporter::class)->import($product->fresh());
        self::assertSame(2, $again['skipped']);
        self::assertSame(3, OperationAiProductReferenceVideo::query()->where('aikaipin_product_id', $product->getKey())->count());
        self::assertSame([], Storage::disk('local')->files('aikaipin/reference-videos/.historical-import'));
    }
}
