<div class="portal-home-dashboard">
    <section class="portal-home-hero">
        <div class="portal-home-hero-main">
            <span class="portal-home-eyebrow"><i class="fa fa-circle"></i> {{ __('portal.home.platform_online') }}</span>
            <h2>{{ $greeting }}</h2>
            <p class="portal-home-today">{{ __('portal.home.today', ['date' => $today]) }}</p>
            <p class="portal-home-hero-intro">{{ __('portal.home.hero_intro') }}</p>
            <div class="portal-home-user-strip" aria-label="{{ __('portal.home.account_status') }}">
                <span class="portal-home-user-pill">
                    <i class="fa fa-circle"></i>
                    {{ __('portal.home.account_status') }} <strong>{{ __('portal.home.account_normal') }}</strong>
                </span>
            </div>
        </div>
        <div class="portal-home-hero-orbit" aria-hidden="true">
            <span class="portal-home-orbit-ring portal-home-orbit-ring-outer"></span>
            <span class="portal-home-orbit-ring portal-home-orbit-ring-inner"></span>
            <span class="portal-home-orbit-node portal-home-orbit-node-one"></span>
            <span class="portal-home-orbit-node portal-home-orbit-node-two"></span>
            <span class="portal-home-orbit-core"><i class="fa fa-cubes"></i></span>
        </div>
    </section>

    <section class="portal-home-systems">
        <div class="portal-home-section-heading">
            <div><h3>{{ __('portal.home.systems') }}</h3>
            <p>{{ __('portal.home.systems_intro') }}</p>
            </div>
            @if($canManageFolders)<button type="button" class="btn btn-primary btn-sm portal-home-folder-create" data-portal-home-folder-create><i class="fa fa-folder-o"></i> 新增文件夹</button>@endif
        </div>

        @if ($systems->isEmpty() && $folderGroups->isEmpty())
            <div class="portal-home-empty">
                <i class="fa fa-th-large"></i>
                <h4>{{ __('portal.home.empty_title') }}</h4>
                <p>{{ __('portal.home.empty_message') }}</p>
            </div>
        @else
            <div class="portal-home-folders" data-portal-home-folders>
                @foreach($folderGroups as $group)
                    <section class="portal-home-folder" data-portal-home-folder data-folder-id="{{ $group['folder']->id }}">
                        <header class="portal-home-folder-heading"><span><i class="fa fa-folder-open-o"></i><strong>{{ $group['folder']->name }}</strong><small>{{ $group['systems']->count() }} 个系统</small></span>@if($canManageFolders)<span class="portal-home-folder-actions"><button type="button" class="btn btn-default btn-xs" data-portal-home-folder-edit data-folder-id="{{ $group['folder']->id }}" data-folder-name="{{ $group['folder']->name }}"><i class="fa fa-pencil"></i></button><button type="button" class="btn btn-default btn-xs text-danger" data-portal-home-folder-delete data-folder-id="{{ $group['folder']->id }}" data-folder-name="{{ $group['folder']->name }}"><i class="fa fa-trash"></i></button></span>@endif</header>
                        <div class="portal-home-grid portal-home-dropzone" data-portal-home-dropzone data-folder-id="{{ $group['folder']->id }}">@foreach($group['systems'] as $system)@include('admin.portal.partials.home-card', ['system' => $system, 'canManageFolders' => $canManageFolders])@endforeach</div>
                    </section>
                @endforeach
                <section @class(['portal-home-folder', 'portal-home-folder-ungrouped', 'is-empty' => $ungroupedSystems->isEmpty()]) data-portal-home-folder data-folder-id="">
                    <header class="portal-home-folder-heading"><span><i class="fa fa-folder-o"></i><strong>未分组系统</strong><small>{{ $ungroupedSystems->count() }} 个系统</small></span></header>
                    <div class="portal-home-grid portal-home-dropzone" data-portal-home-dropzone data-folder-id="">@foreach($ungroupedSystems as $system)@include('admin.portal.partials.home-card', ['system' => $system, 'canManageFolders' => $canManageFolders])@endforeach</div>
                </section>
            </div>
        @endif
    </section>
</div>

@if($canManageFolders)
<div class="modal fade" id="portal-home-folder-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-sm" role="document"><form class="modal-content" data-portal-home-folder-form><div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">新增文件夹</h4></div><div class="modal-body"><div class="form-group"><label for="portal-home-folder-name">文件夹名称</label><input id="portal-home-folder-name" class="form-control" name="name" maxlength="100" required autocomplete="off"></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="submit" class="btn btn-primary">保存</button></div></form></div></div>
<script>window.PortalHomeFolders={storeUrl:@json(route(config('admin.route.prefix').'.portal.home-folders.store')),layoutUrl:@json(route(config('admin.route.prefix').'.portal.home-folders.layout')),updateUrl:@json(route(config('admin.route.prefix').'.portal.home-folders.update',['folder'=>'__FOLDER__'])),deleteUrl:@json(route(config('admin.route.prefix').'.portal.home-folders.destroy',['folder'=>'__FOLDER__']))};</script>
@endif
