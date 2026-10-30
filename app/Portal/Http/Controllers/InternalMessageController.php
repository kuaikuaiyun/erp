<?php

namespace App\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\InternalMessage;
use App\Models\InternalMessageImage;
use App\Models\InternalMessageRecipient;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class InternalMessageController extends Controller
{
    private const SENDER_PORTALS = ['zhuli', 'caigou'];

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->senderContext($request);
        if ($context instanceof Response) return $context;
        $recipients = $this->operationUsers();
        $messages = InternalMessage::query()
            ->where('sender_portal_code', $context['portal']->code)
            ->with(['images', 'recipients.recipientUser'])
            ->withCount(['recipients', 'recipients as read_count' => fn ($query) => $query->whereNotNull('read_at')])
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(20);

        return view('portal.internal-messages.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'recipients' => $recipients,
            'messages' => $messages,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->senderContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'content' => ['nullable', 'string', 'max:10000', 'required_without:images'],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer'],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'content.required_without' => '文字内容和图片至少填写一项。',
            'images.max' => '每条消息最多上传 6 张图片。',
            'images.*.max' => '单张图片不能超过 5MB。',
            'recipient_ids.required' => '请至少选择一名运营人员。',
            'recipient_ids.min' => '请至少选择一名运营人员。',
        ]);
        $operationUsers = $this->operationUsers();
        $selectedIds = collect($validated['recipient_ids'])->map(fn ($id) => (int) $id)->unique();
        $recipientIds = $selectedIds->intersect($operationUsers->pluck('id')->map(fn ($id) => (int) $id));
        abort_if($recipientIds->isEmpty(), 422, '没有可接收消息的运营人员。');

        $connection = config('admin.database.connection') ?: config('database.default');
        $message = DB::connection($connection)->transaction(function () use ($validated, $recipientIds, $context, $request): InternalMessage {
            $message = InternalMessage::query()->create([
                'title' => trim($validated['title']),
                'content' => trim((string) ($validated['content'] ?? '')) ?: null,
                'sender_user_id' => $context['user']->getKey(),
                'sender_name' => (string) ($context['user']->name ?: $context['user']->username),
                'sender_portal_code' => $context['portal']->code,
                'sender_portal_name' => $context['portal']->name,
                'published_at' => now(),
            ]);
            $now = now();
            InternalMessageRecipient::query()->insert($recipientIds->map(fn (int $recipientId) => [
                'internal_message_id' => $message->getKey(), 'recipient_user_id' => $recipientId,
                'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
            foreach ($request->file('images', []) as $index => $image) {
                $extension = strtolower($image->getClientOriginalExtension() ?: $image->extension() ?: 'jpg');
                $path = $image->storeAs('internal-messages/'.now()->format('Y/m'), Str::uuid().'.'.$extension, 'public');
                InternalMessageImage::query()->create([
                    'internal_message_id' => $message->getKey(), 'path' => $path,
                    'original_name' => $image->getClientOriginalName(), 'mime_type' => $image->getMimeType(), 'sort' => $index,
                ]);
            }
            return $message;
        });

        return redirect()->route('internal-messages.index')->with('success', '站内消息发布成功，已发送给 '.$recipientIds->count().' 名运营人员。');
    }

    public function unread(Request $request): Response|JsonResponse
    {
        $context = $this->operationContext($request);
        if ($context instanceof Response) return $context;
        $recipientRows = InternalMessageRecipient::query()
            ->where('recipient_user_id', $context['user']->getKey())->whereNull('read_at')
            ->with(['message.images'])->orderBy('id')->limit(30)->get();

        return response()->json(['messages' => $recipientRows->map(function (InternalMessageRecipient $recipient): array {
            $message = $recipient->message;
            return [
                'id' => (int) $message->getKey(), 'title' => $message->title, 'content' => $message->content,
                'sender_name' => $message->sender_name, 'sender_portal_name' => $message->sender_portal_name,
                'published_at' => optional($message->published_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i'),
                'images' => $message->images->map(fn (InternalMessageImage $image) => route('internal-messages.images.show', [$message, $image]))->values()->all(),
                'read_url' => route('internal-messages.read', $message),
            ];
        })->values()->all()]);
    }

    public function markRead(Request $request, InternalMessage $message): JsonResponse
    {
        $context = $this->operationContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $updated = InternalMessageRecipient::query()
            ->where('internal_message_id', $message->getKey())
            ->where('recipient_user_id', $context['user']->getKey())
            ->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        abort_if($updated === 0 && ! InternalMessageRecipient::query()->where('internal_message_id', $message->getKey())->where('recipient_user_id', $context['user']->getKey())->exists(), 404);
        return response()->json(['ok' => true]);
    }

    public function image(Request $request, InternalMessage $message, InternalMessageImage $image): Response
    {
        abort_unless((int) $image->internal_message_id === (int) $message->getKey(), 404);
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $isSender = in_array($context['portal']->code, self::SENDER_PORTALS, true)
            && $message->sender_portal_code === $context['portal']->code;
        $isRecipient = $context['portal']->code === 'yunying' && InternalMessageRecipient::query()
            ->where('internal_message_id', $message->getKey())->where('recipient_user_id', $context['user']->getKey())->exists();
        abort_unless($isSender || $isRecipient, 403);
        abort_unless(Storage::disk('public')->exists($image->path), 404);
        return Storage::disk('public')->response($image->path);
    }

    private function operationUsers()
    {
        $departmentIds = $this->assistantStoreOwners->operationDepartments()->pluck('id')->map(fn ($id) => (int) $id)->all();
        return AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn('admin_roles.id', $departmentIds))
            ->with(['departments' => fn ($query) => $query->whereIn('admin_roles.id', $departmentIds)->orderBy('admin_roles.sort')->orderBy('admin_roles.name')])
            ->orderBy('name')->orderBy('username')->get()->unique('id')->values()
            ->each(function (AdminUser $user): void {
                $department = $user->departments->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false))
                    ?: $user->departments->first();
                $user->setAttribute('operation_department_name', (string) ($department?->name ?: '未分配运营部门'));
            })
            ->sortBy(fn (AdminUser $user) => $user->operation_department_name.'|'.($user->name ?: $user->username), SORT_NATURAL)
            ->values();
    }

    private function senderContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless(in_array($context['portal']->code, self::SENDER_PORTALS, true), 403);
        return $context;
    }

    private function operationContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === 'yunying', 403);
        $operationIds = $this->operationUsers()->pluck('id')->map(fn ($id) => (int) $id);
        abort_unless($context['user']->isAdministrator() || $operationIds->contains((int) $context['user']->getKey()), 403);
        return $context;
    }
}
