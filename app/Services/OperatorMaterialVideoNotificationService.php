<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AikaipinProduct;
use App\Models\Department;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Models\OperationAiProductReferenceVideo;
use Illuminate\Support\Facades\DB;

class OperatorMaterialVideoNotificationService
{
    /**
     * Notify every active manager of the uploader's departments.
     *
     * @return int Number of managers notified.
     */
    public function notifyManagers(
        AikaipinProduct $product,
        OperationAiProductReferenceVideo $video,
        AdminUser $uploader,
    ): int {
        $rolesTable = config('admin.database.roles_table', 'admin_roles');
        $pivotTable = config('admin.database.role_users_table', 'admin_role_users');
        $departmentIds = $uploader->departments()
            ->pluck($rolesTable.'.id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($departmentIds->isEmpty()) {
            return 0;
        }

        $managerIds = AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->where('id', '!=', $uploader->getKey())
            ->whereHas('departments', fn ($query) => $query
                ->whereIn($rolesTable.'.id', $departmentIds->all())
                ->where($pivotTable.'.position', Department::POSITION_MANAGER))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($managerIds->isEmpty()) {
            return 0;
        }

        $typeName = match ($video->type) {
            OperationAiProductReferenceVideo::TYPE_FLOW => 'Flow素材',
            OperationAiProductReferenceVideo::TYPE_TRAFFIC => '扒的素材',
            OperationAiProductReferenceVideo::TYPE_REMIX => '混剪素材',
            default => '素材视频',
        };
        $uploaderName = trim((string) ($uploader->name ?: $uploader->username));
        $productName = trim((string) $product->name);
        $productCode = trim((string) $product->product_code);
        $videoCode = pathinfo((string) $video->file_name, PATHINFO_FILENAME);

        DB::connection($video->getConnectionName())->transaction(function () use (
            $managerIds,
            $uploader,
            $uploaderName,
            $productName,
            $productCode,
            $typeName,
            $videoCode,
        ): void {
            $message = InternalMessage::query()->create([
                'title' => '运营上传新素材：'.$productCode,
                'content' => "运营 {$uploaderName} 为产品【{$productName}】（{$productCode}）上传了{$typeName}，视频编号：{$videoCode}。请进入 AI 产品库查看。",
                'sender_user_id' => $uploader->getKey(),
                'sender_name' => $uploaderName,
                'sender_portal_code' => 'yunying',
                'sender_portal_name' => 'Shopify订阅运营系统',
                'published_at' => now(),
            ]);

            $now = now();
            InternalMessageRecipient::query()->insert($managerIds->map(static fn (int $managerId): array => [
                'internal_message_id' => $message->getKey(),
                'recipient_user_id' => $managerId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        return $managerIds->count();
    }
}
