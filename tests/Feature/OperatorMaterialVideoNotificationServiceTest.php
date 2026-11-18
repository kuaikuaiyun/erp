<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AikaipinProduct;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Models\OperationAiProductReferenceVideo;
use App\Services\OperatorMaterialVideoNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperatorMaterialVideoNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('password')->default('');
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });
        $schema->create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
        });
        $schema->create('admin_role_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->string('position')->default('member');
            $table->unsignedInteger('position_slot')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
        $schema->create('aikaipin_products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_code');
            $table->string('name');
            $table->timestamps();
        });
        $schema->create('operation_ai_product_reference_videos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('aikaipin_product_id');
            $table->string('type');
            $table->string('file_name');
            $table->timestamps();
        });
        $schema->create('internal_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->unsignedBigInteger('sender_user_id');
            $table->string('sender_name');
            $table->string('sender_portal_code');
            $table->string('sender_portal_name');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        $schema->create('internal_message_recipients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('internal_message_id');
            $table->unsignedBigInteger('recipient_user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_notifies_each_department_manager_once_after_an_operator_uploads_a_video(): void
    {
        DB::table('admin_users')->insert([
            ['id' => 1, 'username' => 'operator', 'name' => '运营甲', 'status' => 1],
            ['id' => 2, 'username' => 'manager', 'name' => '主管乙', 'status' => 1],
        ]);
        DB::table('admin_roles')->insert([
            ['id' => 10, 'name' => '运营一组', 'slug' => 'operation-team-1'],
            ['id' => 11, 'name' => '运营二组', 'slug' => 'operation-team-2'],
        ]);
        DB::table('admin_role_users')->insert([
            ['role_id' => 10, 'user_id' => 1, 'position' => 'member'],
            ['role_id' => 11, 'user_id' => 1, 'position' => 'member'],
            ['role_id' => 10, 'user_id' => 2, 'position' => 'manager'],
            ['role_id' => 11, 'user_id' => 2, 'position' => 'manager'],
        ]);
        $product = AikaipinProduct::query()->create(['product_code' => 'kky-200', 'name' => '测试产品']);
        $video = OperationAiProductReferenceVideo::query()->create([
            'aikaipin_product_id' => $product->getKey(),
            'type' => OperationAiProductReferenceVideo::TYPE_FLOW,
            'file_name' => 'kky-200-op1-min-261118-001.mp4',
        ]);
        $uploader = AdminUser::query()->findOrFail(1);

        $count = app(OperatorMaterialVideoNotificationService::class)
            ->notifyManagers($product, $video, $uploader);

        self::assertSame(1, $count);
        self::assertSame(1, InternalMessage::query()->count());
        self::assertSame(1, InternalMessageRecipient::query()->count());
        self::assertSame(2, (int) InternalMessageRecipient::query()->value('recipient_user_id'));
        self::assertStringContainsString('kky-200-op1-min-261118-001', (string) InternalMessage::query()->value('content'));
    }
}
