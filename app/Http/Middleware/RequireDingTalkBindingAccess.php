<?php
namespace App\Http\Middleware;
use App\Services\DingTalk\BindingAccessService;
use Closure;
use Illuminate\Http\Request;
class RequireDingTalkBindingAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(BindingAccessService::class)->canAccess(), 403, __('dingtalk.manage.forbidden'));
        return $next($request);
    }
}
