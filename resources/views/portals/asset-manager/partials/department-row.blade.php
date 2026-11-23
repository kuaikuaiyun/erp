<tr @if($isChild) data-department-child="{{ $groupId }}" @if(!filled($filters['search']??null)) hidden @endif @endif>
    <td><div class="hd-name {{ $isChild?'is-child':'' }}">
        @if($hasChildren)<button type="button" class="hd-tree-toggle" data-department-toggle="{{ $groupId }}" aria-expanded="{{ filled($filters['search']??null)?'true':'false' }}" aria-label="{{ __('asset_accounts.department_expand') }}"><i class="fa fa-chevron-right" aria-hidden="true"></i></button>@elseif($isChild)<i class="fa fa-level-up hd-branch" aria-hidden="true"></i>@endif
        <span><i class="fa {{ $isChild?'fa-building-o':'fa-sitemap' }} text-aqua" aria-hidden="true"></i> <button type="button" class="hd-name-button" data-department-view="{{ $row->id }}">{{ $row->name }}</button> <span class="hd-level {{ $isChild?'is-child':'' }}">{{ __('asset_accounts.'.($isChild?'department_level_child':'department_level_root')) }}</span><small class="hd-code">{{ $row->slug }}</small></span>
    </div></td>
    <td>{{ $row->short_name ?: __('asset_accounts.department_not_set') }}</td>
    <td><button type="button" class="hd-structure" data-department-members="{{ $row->id }}">
        <span class="hd-capacity {{ $row->manager_count>=1?'is-full':'' }}"><span>{{ __('asset_accounts.manager') }}</span><strong>{{ $row->manager_count }}/1</strong></span>
        @if(!$row->children_count)<span class="hd-capacity {{ $row->deputy_count>=2?'is-full':'' }}"><span>{{ __('asset_accounts.deputy') }}</span><strong>{{ $row->deputy_count }}/2</strong></span><span class="hd-capacity"><span>{{ __('asset_accounts.member') }}</span><strong>{{ $row->member_count }}</strong></span>@endif
    </button></td>
    <td class="hd-time">{{ $row->updated_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</td>
    <td class="hd-actions">@if(in_array((int)$row->id,$writeIds,true))<button type="button" class="btn btn-primary btn-xs" data-department-edit="{{ $row->id }}"><i class="fa fa-edit" aria-hidden="true"></i> {{ __('asset_accounts.edit') }}</button>@endif <button type="button" class="btn btn-default btn-xs" data-department-members="{{ $row->id }}">{{ __('asset_accounts.department_members') }}</button></td>
</tr>
