<?php

namespace App\Http\Middleware;

use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSystemAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Admin::user();

        abort_unless($user && $user->isAdministrator(), 403, __('organization.authorization.super_admin_only'));

        return $next($request);
    }
}


