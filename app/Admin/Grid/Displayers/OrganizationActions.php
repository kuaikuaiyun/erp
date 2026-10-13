<?php

namespace App\Admin\Grid\Displayers;

use App\Models\AdminUser;
use Encore\Admin\Admin;
use Encore\Admin\Grid\Displayers\Actions;

/**
 * Keeps laravel-admin's row-action behaviour while making the organization
 * module's high-frequency actions visible without opening a dropdown menu.
 */
class OrganizationActions extends Actions
{
    protected function renderEdit()
    {
        return '<a href="'.e($this->getResource().'/'.$this->getRouteKey().'/edit').'" class="'
            .$this->grid->getGridRowName().'-edit btn btn-xs btn-primary organization-grid-action"'
            .' title="'.e(__('organization.common.edit')).'"><i class="fa fa-edit"></i> '
            .e(__('organization.common.edit')).'</a>';
    }

    protected function renderDelete()
    {
        if ($this->row instanceof AdminUser) {
            return $this->renderAccountDelete();
        }

        $this->setupDeleteScript();

        return '<a href="javascript:void(0);" data-id="'.e($this->getKey()).'" class="'
            .$this->grid->getGridRowName().'-delete btn btn-xs btn-danger organization-grid-action"'
            .' title="'.e(__('organization.common.delete')).'"><i class="fa fa-trash"></i> '
            .e(__('organization.common.delete')).'</a>';
    }

    public function editButton(): string
    {
        return $this->renderEdit();
    }

    public function deleteButton(): string
    {
        return $this->renderDelete();
    }

    /**
     * The package's default deletion handler only resolves successful HTTP
     * responses. Organization deletion rules intentionally return 422 with a
     * business message (for example, a root department still has children),
     * so resolve the response from both paths and let SweetAlert close its
     * loading state before showing that message.
     */
    protected function setupDeleteScript()
    {
        $translations = array_merge([
            'delete_confirm' => trans('admin.delete_confirm'),
            'confirm' => trans('admin.confirm'),
            'cancel' => trans('admin.cancel'),
            'failed' => __('organization.departments.delete_failed'),
        ], $this->trans);
        $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rowName = $this->grid->getGridRowName();
        $resource = $this->getResource();

        $script = <<<SCRIPT
$('.{$rowName}-delete').unbind('click').click(function() {
    var id = $(this).data('id');
    var translations = {$json};

    swal({
        title: translations.delete_confirm,
        type: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DD6B55',
        confirmButtonText: translations.confirm,
        cancelButtonText: translations.cancel,
        showLoaderOnConfirm: true,
        preConfirm: function() {
            return new Promise(function(resolve) {
                $.ajax({
                    method: 'post',
                    url: '{$resource}/' + id,
                    data: {_method: 'delete', _token: LA.token},
                    success: function(data) { resolve(data); },
                    error: function(xhr) {
                        resolve(xhr.responseJSON || {status: false, message: translations.failed});
                    }
                });
            });
        }
    }).then(function(result) {
        var data = result.value;
        if (typeof data === 'object') {
            if (data.status) {
                $.pjax.reload('#pjax-container');
                swal(data.message, '', 'success');
            } else {
                swal(data.message || translations.failed, '', 'error');
            }
        }
    });
});
SCRIPT;

        Admin::script($script);
    }

    private function renderAccountDelete(): string
    {
        $this->setupAccountDeleteScript();
        $departmentNames = $this->row->relationLoaded('departments')
            ? $this->row->departments->pluck('name')->values()->all()
            : [];
        $warning = $departmentNames === []
            ? __('organization.accounts.delete_account_warning_empty')
            : __('organization.accounts.delete_account_warning', ['departments' => implode('、', $departmentNames)]);

        return '<a href="javascript:void(0);" data-id="'.e($this->getKey()).'" data-warning="'.e($warning).'" class="'
            .$this->grid->getGridRowName().'-delete btn btn-xs btn-danger organization-grid-action"'
            .' title="'.e(__('organization.common.delete')).'"><i class="fa fa-trash"></i> '
            .e(__('organization.common.delete')).'</a>';
    }

    private function setupAccountDeleteScript(): void
    {
        $translations = [
            'title' => __('organization.accounts.delete_account_confirm'),
            'confirm' => __('organization.common.continue_delete'),
            'cancel' => __('organization.common.cancel'),
            'failed' => __('organization.accounts.delete_failed'),
        ];
        $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rowName = $this->grid->getGridRowName();
        $resource = $this->getResource();

        $script = <<<SCRIPT
$('.{$rowName}-delete').unbind('click').click(function() {
    var id = $(this).data('id');
    var warning = $(this).data('warning');
    var translations = {$json};

    swal({
        title: translations.title,
        text: warning,
        type: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DD6B55',
        confirmButtonText: translations.confirm,
        cancelButtonText: translations.cancel,
        showLoaderOnConfirm: true,
        preConfirm: function() {
            return new Promise(function(resolve) {
                $.ajax({
                    method: 'post',
                    url: '{$resource}/' + id,
                    data: {_method: 'delete', _token: LA.token},
                    success: function(data) { resolve(data); },
                    error: function(xhr) {
                        resolve(xhr.responseJSON || {status: false, message: translations.failed});
                    }
                });
            });
        }
    }).then(function(result) {
        var data = result.value;
        if (typeof data === 'object') {
            if (data.status) {
                $.pjax.reload('#pjax-container');
                swal(data.message, '', 'success');
            } else {
                swal(data.message || translations.failed, '', 'error');
            }
        }
    });
});
SCRIPT;

        Admin::script($script);
    }
}


