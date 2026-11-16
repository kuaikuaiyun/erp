<?php
namespace App\Http\Middleware;
use App\Services\DingTalk\BindingAccessService;
use Closure;
use Illuminate\Http\Request;
class RequireDingTalkBindingOwner
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(BindingAccessService::class)->isOwner(), 403, __('dingtalk.manage.owner_only'));
        return $next($request);
    }
}
