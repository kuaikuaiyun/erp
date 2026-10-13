<?php

namespace App\Admin\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Portal;
use App\Portal\Services\PortalDomainService;
use App\Portal\Services\PortalService;
use Encore\Admin\Admin;
use Encore\Admin\Facades\Admin as AdminFacade;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Tools;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly PortalDomainService $domains,
    ) {
    }

    public function index(Content $content): Content
    {
        $this->authorizeSystemAdministrator();

        return $content
            ->title(__('portal.management.title'))
            ->description(__('portal.management.description'))
            ->body($this->grid());
    }

    public function create(Content $content): Content
    {
        $this->authorizeSystemAdministrator();

        return $content
            ->title(__('portal.management.create'))
            ->description(__('portal.management.title'))
            ->body($this->formView());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSystemAdministrator();
        $payload = $request->validate($this->creationRules(), $this->validationMessages());
        $this->portals->create(
            $this->normaliseCreationPayload($payload),
            (array) ($payload['department_ids'] ?? []),
            $this->administrator(),
        );

        admin_success(__('portal.management.title'), __('portal.management.created'));

        return redirect(admin_url('portal/portals'));
    }

    public function edit(Portal $portal, Content $content): Content
    {
        $this->authorizeSystemAdministrator();

        return $content
            ->title(__('portal.management.edit'))
            ->description($portal->name)
            ->body($this->formView($portal));
    }

    public function update(Request $request, Portal $portal): RedirectResponse
    {
        $this->authorizeSystemAdministrator();
        $payload = $request->validate($this->updateRules(), $this->validationMessages());
        $this->portals->update(
            $portal,
            [
                'name' => $payload['name'],
                'remark' => $payload['remark'] ?? null,
                'status' => $payload['status'],
            ],
            (array) ($payload['department_ids'] ?? []),
            $this->administrator(),
        );

        admin_success(__('portal.management.title'), __('portal.management.updated'));

        return redirect(admin_url('portal/portals'));
    }

    public function authorizationHistory(Portal $portal): JsonResponse
    {
        $this->authorizeSystemAdministrator();

        return response()->json([
            'title' => __('portal.history.modal_title', ['name' => $portal->name]),
            'html' => view('admin.portal.partials.authorization-history-table', [
                'history' => $this->portals->authorizationHistory($portal),
            ])->render(),
        ]);
    }

    public function updateStatus(Request $request, Portal $portal): JsonResponse
    {
        $this->authorizeSystemAdministrator();
        $payload = $request->validate([
            'status' => ['required', Rule::in(array_keys($this->statusOptions()))],
        ], $this->validationMessages());
        $portal = $this->portals->updateStatus($portal, (string) $payload['status'], $this->administrator());

        return response()->json([
            'success' => true,
            'title' => __('portal.management.status_updated_title'),
            'message' => __('portal.management.status_updated_message', [
                'name' => $portal->name,
                'status' => __('portal.status.'.$portal->status),
            ]),
            'portal' => [
                'status' => $portal->status,
                'label' => __('portal.status.'.$portal->status),
                'button_class' => $this->statusButtonClass($portal->status),
            ],
        ]);
    }

    private function grid(): Grid
    {
        Admin::script('window.ErpPortalWorkspace = '.json_encode([
            'close' => __('portal.common.close'),
            'cancel' => __('portal.common.cancel'),
            'historyLoadFailed' => __('portal.history.load_failed'),
            'statusChangeTitle' => __('portal.management.status_change_title'),
            'statusChangeMessage' => __('portal.management.status_change_message'),
            'statusChangeConfirm' => __('portal.management.status_change_confirm'),
            'statusUpdateFailed' => __('portal.management.status_update_failed'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';');
        $grid = new Grid(new Portal());
        $controller = $this;

        $grid->setName('portal-management');
        $grid->model()
            ->with(['departmentGrants.department'])
            ->orderByDesc('id');
        $grid->disableExport();
        $grid->disableFilter();
        $grid->disableActions();
        $grid->column('id', __('portal.common.id'))->sortable();
        $grid->column('name', __('portal.management.name'))->display(function (string $name): string {
            return '<strong>'.e($name).'</strong>';
        });
        $grid->column('code', __('portal.management.code'))->display(function (string $code): string {
            return '<code>'.e($code).'</code>';
        });
        $domains = $this->domains;
        $grid->column('domain', __('portal.management.domain'))->display(function () use ($domains): string {
            $url = $domains->portalUrl($this);

            if ($url === null) {
                return '<span class="text-muted">'.e(__('portal.management.domain_unconfigured')).'</span>';
            }

            return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="portal-domain-link">'
                .e(parse_url($url, PHP_URL_HOST) ?: $url)
                .' <i class="fa fa-external-link"></i></a>';
        });
        $grid->column('departments', __('portal.management.authorized_departments'))->display(function (): string {
            $names = $this->departmentGrants
                ->map(fn ($grant) => $grant->department?->name)
                ->filter()
                ->values();

            if ($names->isEmpty()) {
                return '<span class="text-muted">'.e(__('portal.management.no_authorized_departments')).'</span>';
            }

            return $names
                ->map(fn (string $name): string => '<span class="label label-default portal-department-label">'
                    .e($name.' '.__('portal.management.includes_descendants')).'</span>')
                ->implode(' ');
        });
        $grid->column('status', __('portal.common.status'))->display(function () use ($controller): string {
            return $controller->statusSwitcher($this);
        });
        $grid->column('created_at', __('portal.common.created_at'))->display(function ($createdAt) use ($controller): string {
            return e($controller->formattedDate($createdAt));
        });
        $grid->column('updated_at', __('portal.common.updated_at'))->display(function ($updatedAt) use ($controller): string {
            return e($controller->formattedDate($updatedAt));
        });
        $grid->column('portal_actions', trans('admin.action'))->display(function () use ($controller): string {
            /** @var Portal $portal */
            $portal = $this;

            return $controller->portalGridActions($portal);
        });
        $grid->tools(function (Tools $tools): void {
            $tools->batch(function (Tools\BatchActions $actions): void {
                $actions->disableDelete();
            });
        });

        return $grid;
    }

    private function portalGridActions(Portal $portal): string
    {
        $editUrl = admin_url('portal/portals/'.$portal->getKey().'/edit');
        $historyUrl = admin_url('portal/portals/'.$portal->getKey().'/authorization-history');

        return '<div class="portal-grid-actions">'
            .'<a href="'.e($editUrl).'" class="btn btn-xs btn-primary portal-grid-action">'
            .'<i class="fa fa-edit"></i> '.e(__('portal.common.edit')).'</a>'
            .'<button type="button" class="btn btn-xs btn-default portal-grid-action"'
            .' data-portal-history-url="'.e($historyUrl).'"><i class="fa fa-history"></i> '
            .e(__('portal.history.view')).'</button>'
            .'</div>';
    }

    private function formView(?Portal $portal = null): string
    {
        $grantedDepartmentIds = $portal
            ? $portal->departmentGrants()->pluck('department_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return view('admin.portal.form', [
            'portal' => $portal,
            'rootDepartments' => $this->portals->rootDepartmentOptions(),
            'grantedDepartmentIds' => $grantedDepartmentIds,
            'history' => $portal ? $this->portals->authorizationHistory($portal) : collect(),
            'domain' => $portal ? $this->domains->portalDomain($portal) : null,
            'rootDomain' => (string) config('portals.root_domain', ''),
            'domainConfigured' => $this->domains->isConfigured(),
            'statuses' => $this->statusOptions(),
        ])->render();
    }

    /** @return array<string, array<int, mixed>> */
    private function creationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('portals', 'code'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->domains->isReservedCode((string) $value)) {
                        $fail(__('portal.validation.code_reserved'));
                    }
                },
            ],
            'remark' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(array_keys($this->statusOptions()))],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function updateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(array_keys($this->statusOptions()))],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer'],
        ];
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'name.required' => __('portal.validation.name_required'),
            'name.max' => __('portal.validation.name_max'),
            'code.required' => __('portal.validation.code_required'),
            'code.max' => __('portal.validation.code_max'),
            'code.regex' => __('portal.validation.code_format'),
            'code.unique' => __('portal.validation.code_unique'),
            'remark.max' => __('portal.validation.remark_max'),
            'status.required' => __('portal.validation.status_required'),
            'status.in' => __('portal.validation.status_invalid'),
            'department_ids.array' => __('portal.validation.department_array'),
            'department_ids.*.integer' => __('portal.validation.invalid_department'),
        ];
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            Portal::STATUS_ENABLED => __('portal.status.enabled'),
            Portal::STATUS_MAINTENANCE => __('portal.status.maintenance'),
            Portal::STATUS_DISABLED => __('portal.status.disabled'),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function normaliseCreationPayload(array $payload): array
    {
        $payload['code'] = strtolower((string) $payload['code']);

        return $payload;
    }

    private function statusLabel(string $status): string
    {
        $class = match ($status) {
            Portal::STATUS_ENABLED => 'label-success',
            Portal::STATUS_MAINTENANCE => 'label-warning',
            default => 'label-default',
        };

        return '<span class="label '.$class.'">'.e(__('portal.status.'.$status)).'</span>';
    }

    private function statusSwitcher(Portal $portal): string
    {
        $status = $portal->status;
        $updateUrl = admin_url('portal/portals/'.$portal->getKey().'/status');
        $choices = collect($this->statusOptions())
            ->map(function (string $label, string $value) use ($status): string {
                $isCurrent = $value === $status;

                return '<li class="'.($isCurrent ? 'disabled' : '').'">'
                    .'<a href="#" data-portal-status-choice data-portal-status="'.e($value).'"'
                    .' data-portal-status-label="'.e($label).'"'
                    .($isCurrent ? ' aria-disabled="true" tabindex="-1"' : '').'>'
                    .'<i class="fa '.($isCurrent ? 'fa-check' : 'fa-fw').'" data-portal-status-choice-icon aria-hidden="true"></i> '
                    .e($label).'</a></li>';
            })
            ->implode('');

        return '<div class="btn-group portal-status-switcher" data-portal-status-switcher'
            .' data-portal-status-url="'.e($updateUrl).'" data-portal-current-status="'.e($status).'"'
            .' data-portal-name="'.e($portal->name).'">'
            .'<button type="button" class="btn btn-xs '.$this->statusButtonClass($status).' dropdown-toggle"'
            .' data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"'
            .' title="'.e(__('portal.management.quick_status')).'" data-portal-status-toggle>'
            .'<span data-portal-status-label>'.e(__('portal.status.'.$status)).'</span> <span class="caret"></span></button>'
            .'<ul class="dropdown-menu">'.$choices.'</ul></div>';
    }

    private function statusButtonClass(string $status): string
    {
        return match ($status) {
            Portal::STATUS_ENABLED => 'btn-success',
            Portal::STATUS_MAINTENANCE => 'btn-warning',
            default => 'btn-default',
        };
    }

    private function formattedDate(mixed $value): string
    {
        if (!$value) {
            return '';
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);

            return $date
                ->setTimezone('Asia/Shanghai')
                ->translatedFormat(__('portal.common.datetime_format'));
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function authorizeSystemAdministrator(): void
    {
        abort_unless(
            $this->administrator()->isAdministrator(),
            403,
            __('portal.authorization.super_admin_only'),
        );
    }

    private function administrator(): AdminUser
    {
        /** @var AdminUser $administrator */
        $administrator = AdminFacade::user();

        return $administrator;
    }
}
