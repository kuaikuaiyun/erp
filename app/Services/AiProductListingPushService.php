<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AiProductListingTask;
use App\Models\AiProductShopifyPublication;
use App\Models\AikaipinProduct;
use App\Models\Department;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiProductListingPushService
{
    public function __construct(private readonly AssistantStoreOwnerService $departments) {}

    public function notifyDepartmentManagers(AikaipinProduct $product, AdminUser $sender): int
    {
        if (! $product->is_published) return 0;

        $departmentIds = $this->departments->operationDepartments()
            ->filter(fn (Department $department): bool => $department->parent_id !== null)
            ->pluck('id')->map(fn ($id): int => (int) $id)->values();
        if ($departmentIds->isEmpty()) return 0;

        $pivot = config('admin.database.role_users_table', 'admin_role_users');
        $managers = AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query
                ->whereIn('admin_roles.id', $departmentIds->all())
                ->where($pivot.'.position', Department::POSITION_MANAGER))
            ->with(['departments' => fn ($query) => $query
                ->whereIn('admin_roles.id', $departmentIds->all())
                ->where($pivot.'.position', Department::POSITION_MANAGER)])
            ->get();

        $created = 0;
        foreach ($managers as $manager) {
            foreach ($manager->departments as $department) {
                $created += DB::connection($product->getConnectionName())->transaction(function () use ($product, $sender, $manager, $department): int {
                    $task = AiProductListingTask::query()->firstOrCreate(
                        ['aikaipin_product_id' => $product->getKey(), 'department_id' => $department->getKey()],
                        ['manager_user_id' => $manager->getKey(), 'status' => AiProductListingTask::STATUS_PENDING_ASSIGNMENT],
                    );
                    if (! $task->wasRecentlyCreated) return 0;

                    $message = $this->createMessage(
                        'AI开品待分配上架：'.$product->product_code,
                        "新产品【{$product->name}】（{$product->product_code}）已上传到产品资料库。请为 {$department->name} 选择具体运营人员，分配必须上架任务。",
                        $sender,
                        'aikaipin',
                        'AI开品系统',
                    );
                    $recipient = $this->createRecipient($message, (int) $manager->getKey());
                    $task->update(['manager_recipient_id' => $recipient->getKey()]);

                    return 1;
                });
            }
        }

        return $created;
    }

    /** @return Collection<int, AdminUser> */
    public function eligibleAssignees(AiProductListingTask $task): Collection
    {
        $pivot = config('admin.database.role_users_table', 'admin_role_users');

        return AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)
            ->where('id', '!=', $task->manager_user_id)
            ->whereHas('departments', fn ($query) => $query
                ->where('admin_roles.id', $task->department_id)
                ->whereIn($pivot.'.position', [Department::POSITION_MEMBER, Department::POSITION_DEPUTY]))
            ->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);
    }

    public function canPushFromLibrary(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->managedDepartmentIds($user)->isNotEmpty();
    }

    /** @return Collection<int, array{id:int,name:string,username:string,department_id:int,department_name:string,is_published:bool,has_pending_task:bool}> */
    public function libraryPushMembers(AikaipinProduct $product, AdminUser $actor): Collection
    {
        $departmentIds = $this->managedDepartmentIds($actor);
        if ($departmentIds->isEmpty()) return collect();

        $rolesTable = config('admin.database.roles_table', 'admin_roles');
        $pivot = config('admin.database.role_users_table', 'admin_role_users');
        $publishedUserIds = AiProductShopifyPublication::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->where('status', 'success')
            ->pluck('published_by')->map(fn ($id): int => (int) $id)->unique();
        $pendingUserIds = AiProductListingTask::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->where('status', AiProductListingTask::STATUS_ASSIGNED)
            ->pluck('assignee_user_id')->map(fn ($id): int => (int) $id)->unique();

        return AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query
                ->whereIn($rolesTable.'.id', $departmentIds->all())
                ->whereIn($pivot.'.position', [Department::POSITION_MEMBER, Department::POSITION_DEPUTY]))
            ->with(['departments' => fn ($query) => $query
                ->whereIn($rolesTable.'.id', $departmentIds->all())
                ->whereIn($pivot.'.position', [Department::POSITION_MEMBER, Department::POSITION_DEPUTY])])
            ->orderBy('name')->orderBy('username')->get()
            ->map(function (AdminUser $member) use ($publishedUserIds, $pendingUserIds): array {
                $department = $member->departments->first();

                return [
                    'id' => (int) $member->getKey(),
                    'name' => trim((string) ($member->name ?: $member->username)),
                    'username' => (string) $member->username,
                    'department_id' => (int) $department->getKey(),
                    'department_name' => (string) $department->name,
                    'is_published' => $publishedUserIds->contains((int) $member->getKey()),
                    'has_pending_task' => $pendingUserIds->contains((int) $member->getKey()),
                ];
            })->values();
    }

    /** @param array<int, int|string> $memberIds
     *  @return array{pushed:int,skipped:int}
     */
    public function pushFromLibrary(AikaipinProduct $product, AdminUser $actor, array $memberIds): array
    {
        abort_unless($this->canPushFromLibrary($actor), 403);
        $selectedIds = collect($memberIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $members = $this->libraryPushMembers($product, $actor)->keyBy('id');
        if ($selectedIds->isEmpty() || $selectedIds->contains(fn (int $id): bool => ! $members->has($id))) {
            throw ValidationException::withMessages(['member_ids' => '请选择当前管理组内的有效成员。']);
        }

        $pushed = 0;
        $skipped = 0;
        foreach ($selectedIds as $memberId) {
            $memberData = $members->get($memberId);
            if ($memberData['has_pending_task']) {
                $skipped++;
                continue;
            }
            $assignee = AdminUser::query()->findOrFail($memberId);
            DB::connection($product->getConnectionName())->transaction(function () use ($product, $actor, $assignee, $memberData): void {
                $message = $this->createMessage(
                    '必须上架商品：'.$product->product_code,
                    "主管 {$actor->name} 已将商品【{$product->name}】（{$product->product_code}）定点推送给你。请进入 AI 产品库完成 Shopify 上架；成功上架后本消息会自动标记为已处理。",
                    $actor,
                    'yunying',
                    'Shopify订阅运营系统',
                );
                $recipient = $this->createRecipient($message, (int) $assignee->getKey());
                AiProductListingTask::query()->create([
                    'aikaipin_product_id' => $product->getKey(),
                    'department_id' => $memberData['department_id'],
                    'manager_user_id' => $actor->getKey(),
                    'assignee_user_id' => $assignee->getKey(),
                    'assignee_recipient_id' => $recipient->getKey(),
                    'status' => AiProductListingTask::STATUS_ASSIGNED,
                    'assigned_at' => now(),
                ]);
            });
            $pushed++;
        }

        return compact('pushed', 'skipped');
    }

    public function assign(AiProductListingTask $task, AdminUser $assignee, AdminUser $actor): void
    {
        if (! $actor->isAdministrator() && (int) $task->manager_user_id !== (int) $actor->getKey()) abort(403);
        if ($task->status !== AiProductListingTask::STATUS_PENDING_ASSIGNMENT) {
            throw ValidationException::withMessages(['assignee_id' => '该商品已经分配，不能重复推送。']);
        }
        if (! $this->eligibleAssignees($task)->contains(fn (AdminUser $user): bool => (int) $user->getKey() === (int) $assignee->getKey())) {
            throw ValidationException::withMessages(['assignee_id' => '请选择当前部门内的有效运营人员。']);
        }

        $task->loadMissing(['product', 'department']);
        DB::connection($task->getConnectionName())->transaction(function () use ($task, $assignee, $actor): void {
            $message = $this->createMessage(
                '必须上架商品：'.$task->product->product_code,
                "负责人 {$actor->name} 已将商品【{$task->product->name}】（{$task->product->product_code}）分配给你。请进入 AI 产品库完成 Shopify 上架；成功上架后本消息会自动标记为已处理。",
                $actor,
                'yunying',
                'Shopify订阅运营系统',
            );
            $recipient = $this->createRecipient($message, (int) $assignee->getKey());
            $task->update([
                'assignee_user_id' => $assignee->getKey(),
                'assignee_recipient_id' => $recipient->getKey(),
                'status' => AiProductListingTask::STATUS_ASSIGNED,
                'assigned_at' => now(),
            ]);
            if ($task->manager_recipient_id) {
                InternalMessageRecipient::query()->whereKey($task->manager_recipient_id)->update([
                    'read_at' => now(), 'processed_at' => now(), 'processed_by' => $actor->getKey(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function assignAll(AiProductListingTask $task, AdminUser $actor): int
    {
        if (! $actor->isAdministrator() && (int) $task->manager_user_id !== (int) $actor->getKey()) abort(403);
        if ($task->status !== AiProductListingTask::STATUS_PENDING_ASSIGNMENT) {
            throw ValidationException::withMessages(['assignee_id' => '该商品已经分配，不能重复推送。']);
        }

        $assignees = $this->eligibleAssignees($task);
        if ($assignees->isEmpty()) {
            throw ValidationException::withMessages(['assignee_id' => '当前部门没有可以接收任务的运营人员。']);
        }

        $task->loadMissing(['product', 'department']);
        DB::connection($task->getConnectionName())->transaction(function () use ($task, $assignees, $actor): void {
            foreach ($assignees->values() as $index => $assignee) {
                $target = $index === 0 ? $task : AiProductListingTask::query()->create([
                    'aikaipin_product_id' => $task->aikaipin_product_id,
                    'department_id' => $task->department_id,
                    'manager_user_id' => $task->manager_user_id,
                    'status' => AiProductListingTask::STATUS_PENDING_ASSIGNMENT,
                ]);
                $message = $this->createMessage(
                    '必须上架商品：'.$task->product->product_code,
                    "负责人 {$actor->name} 已将商品【{$task->product->name}】（{$task->product->product_code}）分配给本部门全部运营。请进入 AI 产品库完成 Shopify 上架；每人成功上架后仅自动处理自己的消息。",
                    $actor,
                    'yunying',
                    'Shopify订阅运营系统',
                );
                $recipient = $this->createRecipient($message, (int) $assignee->getKey());
                $target->update([
                    'assignee_user_id' => $assignee->getKey(),
                    'assignee_recipient_id' => $recipient->getKey(),
                    'status' => AiProductListingTask::STATUS_ASSIGNED,
                    'assigned_at' => now(),
                ]);
            }

            if ($task->manager_recipient_id) {
                InternalMessageRecipient::query()->whereKey($task->manager_recipient_id)->update([
                    'read_at' => now(), 'processed_at' => now(), 'processed_by' => $actor->getKey(), 'updated_at' => now(),
                ]);
            }
        });

        return $assignees->count();
    }

    public function completeForPublication(AikaipinProduct $product, AdminUser $operator, AiProductShopifyPublication $publication): int
    {
        $tasks = AiProductListingTask::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->where('assignee_user_id', $operator->getKey())
            ->where('status', AiProductListingTask::STATUS_ASSIGNED)
            ->get();

        foreach ($tasks as $task) {
            DB::connection($task->getConnectionName())->transaction(function () use ($task, $publication, $operator): void {
                $task->update([
                    'status' => AiProductListingTask::STATUS_PROCESSED,
                    'publication_id' => $publication->getKey(),
                    'processed_at' => now(),
                ]);
                if ($task->assignee_recipient_id) {
                    InternalMessageRecipient::query()->whereKey($task->assignee_recipient_id)->update([
                        'read_at' => now(), 'processed_at' => now(), 'processed_by' => $operator->getKey(), 'updated_at' => now(),
                    ]);
                }
            });
        }

        return $tasks->count();
    }

    private function createMessage(string $title, string $content, AdminUser $sender, string $portalCode, string $portalName): InternalMessage
    {
        return InternalMessage::query()->create([
            'title' => $title,
            'content' => $content,
            'sender_user_id' => $sender->getKey(),
            'sender_name' => $sender->name ?: $sender->username,
            'sender_portal_code' => $portalCode,
            'sender_portal_name' => $portalName,
            'published_at' => now(),
        ]);
    }

    private function createRecipient(InternalMessage $message, int $userId): InternalMessageRecipient
    {
        return InternalMessageRecipient::query()->create([
            'internal_message_id' => $message->getKey(),
            'recipient_user_id' => $userId,
        ]);
    }

    /** @return Collection<int, int> */
    private function managedDepartmentIds(AdminUser $user): Collection
    {
        if ($user->isAdministrator()) {
            return $this->departments->operationDepartments()
                ->filter(fn (Department $department): bool => $department->parent_id !== null)
                ->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values();
        }

        $pivot = config('admin.database.role_users_table', 'admin_role_users');

        return $user->departments()
            ->where($pivot.'.position', Department::POSITION_MANAGER)
            ->pluck('admin_roles.id')->map(fn ($id): int => (int) $id)->unique()->values();
    }
}
