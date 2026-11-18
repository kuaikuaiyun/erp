<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Admin\Controllers\DingTalkBindingController;
use App\Admin\Controllers\DingTalkCompanyGrantController;
use App\Http\Controllers\Controller;
use App\Portals\AssetManager\AccountAuthorizationService;
use App\Portals\AssetManager\AccountDingTalkAccessService;
use App\Services\DingTalk\BindingAccessService;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\DirectoryService;
use Illuminate\Http\Request;

class AccountDingTalkController extends Controller
{
    public function __construct(private AccountController $accounts, private AccountAuthorizationService $authorization) {}

    private function run(Request $request, callable $operation)
    {
        $c = $this->accounts->context($request, true);
        $access = new AccountDingTalkAccessService($c['user'], $this->authorization);
        abort_unless($access->canAccess(), 403, __('dingtalk.manage.forbidden'));
        $original = app(BindingAccessService::class);
        // The original binding service rechecks account/company access inside its transaction.
        // Adapt just this operation, and always restore the normal service afterwards.
        app()->instance(BindingAccessService::class, $access);
        try {
            return $operation($access);
        } finally {
            app()->instance(BindingAccessService::class, $original);
        }
    }

    public function options(Request $request, int $account)
    {
        return $this->run($request, fn ($access) => app(DingTalkBindingController::class)->options($account, $access));
    }

    public function store(Request $request)
    {
        return $this->run($request, fn ($access) => app(DingTalkBindingController::class)->store($request, $access, app(BindingService::class)));
    }

    public function destroy(Request $request, int $binding)
    {
        return $this->run($request, fn ($access) => app(DingTalkBindingController::class)->destroy($binding, $access, app(BindingService::class)));
    }

    public function directory(Request $request)
    {
        return $this->run($request, fn ($access) => app(DingTalkBindingController::class)->directory($request, $access, app(DirectoryService::class)));
    }

    public function audits(Request $request)
    {
        return $this->run($request, fn ($access) => app(DingTalkBindingController::class)->audits($request, $access));
    }

    public function grants(Request $request)
    {
        return $this->run($request, fn () => app(DingTalkCompanyGrantController::class)->index());
    }

    public function saveGrants(Request $request)
    {
        return $this->run($request, fn () => app(DingTalkCompanyGrantController::class)->store($request));
    }
}
