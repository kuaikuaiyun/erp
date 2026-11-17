<?php

namespace App\Portals\ReviewCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Department;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portal\Services\PortalMenuAuthorizationService;
use App\Portals\ReviewCenter\ReviewCenterAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DepartmentBindingController extends Controller
{
    public function __construct(private readonly PortalEntryController $entries, private readonly ReviewCenterAuthorizationService $authorization) {}

    private function context(Request $request): array
    {
        $context = $this->entries->portalContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($context['portal']->code === 'review-center', 404);
        abort_unless($context['user']->isAdministrator(), 403);
        $menu = $context['portal']->menus()->where('code', 'review_center_department_bindings')->where('is_enabled', true)->first();
        abort_unless($menu && app(PortalMenuAuthorizationService::class)->canAccess($context['user'], $menu), 403);
        app()->setLocale('zh-CN');
        return $context;
    }

    public function index(Request $request)
    {
        $context = $this->context($request);
        $users = $this->authorization->authorizedMembers($context['portal'])->orderBy('name')->orderBy('id')->get(['id', 'name', 'username']);
        $data = $request->validate(['user_id' => ['nullable', 'integer', Rule::in($users->pluck('id')->all())]], [
            'user_id.in' => __('review_center.member_not_authorized'),
        ], __('review_center.attributes'));
        $selectedUserId = (int) ($data['user_id'] ?? $users->first()?->id ?? 0);
        $db = $this->authorization->database();
        return view('portals.review-center.department-bindings', [...$context,
            'users' => $users, 'selectedUserId' => $selectedUserId,
            'departments' => Department::business()->with('parent:id,name')->orderBy('sort')->orderBy('name')->get(),
            'selectedIds' => $db->table('review_center_department_bindings')->where('user_id', $selectedUserId)->pluck('department_id')->map(fn ($id) => (int) $id)->all(),
            'history' => $db->table('review_center_department_binding_logs')->where('user_id', $selectedUserId)->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $context = $this->context($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($this->authorization->authorizedMembers($context['portal'])->pluck('id')->all())],
            'department_ids' => ['nullable', 'array', 'max:500'],
            'department_ids.*' => ['required', 'integer', 'distinct', Rule::in(Department::business()->pluck('id')->all())],
        ], ['user_id.in' => __('review_center.member_not_authorized')], __('review_center.attributes'));
        $ids = collect($data['department_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $db = $this->authorization->database();
        $db->transaction(function () use ($db, $data, $ids, $context): void {
            // Serialize changes for the same member, including an initially empty binding.
            $member = AdminUser::query()->lockForUpdate()->findOrFail($data['user_id']);
            if (! $this->authorization->authorizedMembers($context['portal'])->whereKey($member->id)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['user_id' => __('review_center.member_not_authorized')]);
            }
            $before = $db->table('review_center_department_bindings')->where('user_id', $member->id)->orderBy('department_id')->pluck('department_id')->map(fn ($id) => (int) $id)->all();
            $db->table('review_center_department_bindings')->where('user_id', $member->id)->delete();
            $now = now();
            foreach ($ids as $id) $db->table('review_center_department_bindings')->insert([
                'user_id' => $member->id, 'department_id' => $id, 'updated_by' => $context['user']->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('review_center_department_binding_logs')->insert([
                'user_id' => $member->id, 'user_name' => $member->name ?: $member->username,
                'before_ids' => json_encode($before), 'after_ids' => json_encode($ids),
                'actor_id' => $context['user']->id, 'actor_name' => $context['user']->name ?: $context['user']->username,
                'created_at' => $now,
            ]);
        });
        return redirect()->route('review-center.department-bindings.index', ['user_id' => $data['user_id']])->with('success', __('review_center.bindings_saved'));
    }
}
