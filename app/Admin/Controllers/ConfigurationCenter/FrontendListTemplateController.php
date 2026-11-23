<?php

namespace App\Admin\Controllers\ConfigurationCenter;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;

class FrontendListTemplateController extends Controller
{
    public function index(Content $content): Content
    {
        $user = Admin::user();
        abort_unless($user && (int) $user->id === 1 && (int) $user->status === 1 && $user->isAdministrator(), 403, __('frontend_list_template.boss_only'));

        return $content
            ->title(__('frontend_list_template.title'))
            ->description(__('frontend_list_template.description'))
            ->body(view('admin.configuration.frontend-list-template'));
    }
}
