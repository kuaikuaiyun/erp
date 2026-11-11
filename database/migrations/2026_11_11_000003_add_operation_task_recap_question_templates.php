<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        if (! $schema->hasTable('operation_task_recap_questions')) {
            $schema->create('operation_task_recap_questions', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->unique();
                $table->string('title', 500);
                $table->text('description')->nullable();
                $table->boolean('is_required')->default(true);
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if ($schema->hasTable('operation_task_recaps') && ! $schema->hasColumn('operation_task_recaps', 'question_answers')) {
            $schema->table('operation_task_recaps', function (Blueprint $table): void {
                $table->json('question_answers')->nullable()->after('support_needed');
            });
        }

        foreach ([
            ['finding', '昨天最重要的发现是什么？你据此做出什么判断？', '具体到单品、类目、素材或投放：哪些值得继续，哪些需要调整？写出关键数据或观察依据。', true, 10],
            ['action', '今天重点推进什么？相比昨天具体改变什么，为什么？', '写清对象、原来的做法、准备采取的动作及依据。', true, 20],
            ['advertising', '今天广告准备怎么跑，什么情况下继续、调整或暂停？', '写明媒体、重点单品或类目、素材安排、预算、检查时间和判断条件。', true, 30],
            ['support', '昨天计划但没完成的是什么？卡在哪里，需要什么支持？', '选填；不要求上传截图或报表。', false, 40],
        ] as [$code, $title, $description, $required, $sort]) {
            $database->table('operation_task_recap_questions')->updateOrInsert(
                ['code' => $code],
                ['title' => $title, 'description' => $description, 'is_required' => $required, 'is_enabled' => true, 'sort' => $sort, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Historical recap answers must remain recoverable; no destructive rollback.
    }
};
