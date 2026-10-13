@extends('portal.layout')

@section('page_title', __('portal.menu.configuration'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/vendor/laravel-admin/nestable/nestable.css">
@endpush

@section('content')
    <section class="content-header">
        <h1>
            {{ __('portal.menu.configuration') }}
            <small>{{ __('portal.menu_configuration.description', ['portal' => $portal->name]) }}</small>
        </h1>
        <ol class="breadcrumb" style="margin-right: 30px;">
            <li><a href="/"><i class="fa fa-home"></i> {{ __('portal.menu.home') }}</a></li>
            <li>{{ __('portal.menu.backend_management') }}</li>
            <li class="active">{{ __('portal.menu.configuration') }}</li>
        </ol>
    </section>

    <section class="content portal-menu-configuration">
        <div class="box portal-menu-tree-panel">
            <div class="box-header">
                <div class="btn-group">
                    <button type="button" class="btn btn-primary btn-sm portal-menu-tree-tool" data-action="expand" title="{{ __('portal.menu_configuration.expand') }}">
                        <i class="fa fa-plus-square-o"></i><span class="hidden-xs"> {{ __('portal.menu_configuration.expand') }}</span>
                    </button>
                    <button type="button" class="btn btn-primary btn-sm portal-menu-tree-tool" data-action="collapse" title="{{ __('portal.menu_configuration.collapse') }}">
                        <i class="fa fa-minus-square-o"></i><span class="hidden-xs"> {{ __('portal.menu_configuration.collapse') }}</span>
                    </button>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-info btn-sm" id="portal-menu-tree-save" title="{{ __('portal.menu_configuration.save_order') }}">
                        <i class="fa fa-save"></i><span class="hidden-xs"> {{ __('portal.menu_configuration.save_order') }}</span>
                    </button>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-warning btn-sm" id="portal-menu-tree-refresh" title="{{ __('portal.menu_configuration.refresh') }}">
                        <i class="fa fa-refresh"></i><span class="hidden-xs"> {{ __('portal.menu_configuration.refresh') }}</span>
                    </button>
                </div>
            </div>
            <div class="box-body table-responsive no-padding">
                <div class="dd" id="portal-menu-tree">
                    <ol class="dd-list">
                        @foreach ($treeMenus as $menu)
                            @include('portal.menu-configuration.branch', [
                                'menu' => $menu,
                                'menus' => $menus,
                                'portalOrigin' => $portalOrigin,
                            ])
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ $portalOrigin }}/vendor/laravel-admin/nestable/jquery.nestable.js"></script>
    <script>
        (function ($) {
            var tree = $('#portal-menu-tree');
            var systemParents = @json($systemParents);

            if (!tree.length || !$.fn.nestable) {
                return;
            }

            tree.nestable({ maxDepth: 2 });

            function notify(payload) {
                if (window.ErpAdminNotifier) {
                    window.ErpAdminNotifier.notify(payload);
                }
            }

            function systemHierarchyIsValid(nodes, parentId) {
                for (var index = 0; index < nodes.length; index += 1) {
                    var node = nodes[index];
                    var id = String(node.id);

                    if (Object.prototype.hasOwnProperty.call(systemParents, id) && Number(systemParents[id]) !== Number(parentId)) {
                        return false;
                    }

                    if (node.children && !systemHierarchyIsValid(node.children, node.id)) {
                        return false;
                    }
                }

                return true;
            }

            tree.on('change', function () {
                if (systemHierarchyIsValid(tree.nestable('serialize'), 0)) {
                    return;
                }

                notify({
                    type: 'error',
                    title: @json(__('portal.menu_configuration.title')),
                    message: @json(__('portal.menu_configuration.order_hierarchy_locked'))
                });
                window.setTimeout(function () {
                    window.location.reload();
                }, 500);
            });

            $('.portal-menu-tree-tool').on('click', function () {
                if ($(this).data('action') === 'expand') {
                    tree.nestable('expandAll');
                    return;
                }

                tree.nestable('collapseAll');
            });

            $('#portal-menu-tree-save').on('click', function () {
                var button = $(this);

                if (button.prop('disabled')) {
                    return;
                }

                button.prop('disabled', true);
                $.ajax({
                    method: 'POST',
                    url: '/management/menus/order',
                    data: {
                        _token: $('meta[name="csrf-token"]').attr('content'),
                        _order: JSON.stringify(tree.nestable('serialize'))
                    }
                }).done(function (response) {
                    notify({
                        type: response.status ? 'success' : 'error',
                        title: @json(__('portal.menu_configuration.title')),
                        message: response.message || @json(__('portal.menu_configuration.order_saved'))
                    });
                }).fail(function (xhr) {
                    notify({
                        type: 'error',
                        title: @json(__('portal.menu_configuration.title')),
                        message: (xhr.responseJSON && xhr.responseJSON.message) || @json(__('portal.menu_configuration.order_invalid'))
                    });
                }).always(function () {
                    button.prop('disabled', false);
                });
            });

            $('#portal-menu-tree-refresh').on('click', function () {
                window.location.reload();
            });
        })(jQuery);
    </script>
@endpush


