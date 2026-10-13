<div class="box box-info organization-dissolution-box">
    <div class="box-header with-border">
        <h3 class="box-title">{{ __('organization.dissolution.title') }}</h3>
        <div class="box-tools">
            <a href="{{ admin_url('organization/departments') }}" class="btn btn-sm btn-default"><i class="fa fa-list"></i><span class="hidden-xs">&nbsp;{{ __('organization.common.back') }}</span></a>
        </div>
    </div>
    <form action="{{ admin_url('organization/departments/'.$department->getKey().'/dissolution') }}" method="post" pjax-container>
        @csrf
        <span data-organization-dissolution-form hidden></span>
        <div class="box-body">
            <div class="alert alert-info organization-dissolution-intro">
                <i class="fa fa-info-circle"></i> {{ __('organization.dissolution.intro') }}
            </div>
            <div class="organization-dissolution-summary">
                <div><span>{{ __('organization.dissolution.source_department') }}：</span><strong>{{ $department->name }}</strong></div>
                @if($parent)
                    <div><span>{{ __('organization.dissolution.parent_department') }}：</span><strong>{{ $parent->name }}</strong></div>
                    <p class="help-block">
                        <i class="fa fa-{{ $parentBecomesLeaf ? 'unlock-alt' : 'sitemap' }}"></i>
                        {{ $parentBecomesLeaf ? __('organization.dissolution.parent_becomes_leaf') : __('organization.dissolution.parent_remains_branch') }}
                    </p>
                @endif
            </div>

            @if($members->isNotEmpty())
                <div class="organization-form-section organization-dissolution-section">
                    <div class="organization-form-section-divider"><span>{{ __('organization.dissolution.affected_people') }}</span></div>
                    @if($targetDepartments->isEmpty())
                        <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> {{ __('organization.dissolution.no_target_available') }}</div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover organization-dissolution-table">
                            <thead>
                            <tr>
                                <th>{{ __('organization.accounts.name') }}</th>
                                <th>{{ __('organization.dissolution.current_position') }}</th>
                                <th>{{ __('organization.dissolution.other_memberships') }}</th>
                                <th>{{ __('organization.dissolution.handling') }}</th>
                                <th>{{ __('organization.dissolution.target_position') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($members as $member)
                                @php
                                    $userId = $member['user_id'];
                                    $otherDepartmentIds = $member['existing_department_ids'];
                                    $canRemove = $member['other_departments'] !== [];
                                    $defaultTarget = $canRemove ? 'remove' : ($parent && $parentBecomesLeaf ? (string) $parent->getKey() : '');
                                    $selectedTarget = (string) old('reassignments.'.$userId.'.target_id', $defaultTarget);
                                    $selectedPosition = old('reassignments.'.$userId.'.position', $member['position']);
                                @endphp
                                <tr data-dissolution-row data-no-available-position-message="{{ __('organization.dissolution.no_available_position') }}">
                                    <td><strong>{{ $member['name'] }}</strong><br><small class="text-muted">{{ $member['username'] }}</small></td>
                                    <td>{{ $positions[$member['position']] ?? $member['position'] }}</td>
                                    <td>
                                        @if($canRemove)
                                            @foreach($member['other_departments'] as $otherDepartment)
                                                <span class="label label-default organization-dissolution-membership">{{ $otherDepartment['name'] }}（{{ $positions[$otherDepartment['position']] ?? $otherDepartment['position'] }}）</span>
                                            @endforeach
                                        @else
                                            <span class="text-muted">{{ __('organization.dissolution.no_other_membership') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <select class="form-control" name="reassignments[{{ $userId }}][target_id]" data-dissolution-target required>
                                            <option value="">{{ __('organization.dissolution.select_target') }}</option>
                                            @if($canRemove)
                                                <option value="remove" {{ $selectedTarget === 'remove' ? 'selected' : '' }}>{{ __('organization.dissolution.remove_relation') }}</option>
                                            @endif
                                            @foreach($targetDepartments as $targetDepartment)
                                                @continue(in_array($targetDepartment['id'], $otherDepartmentIds, true))
                                                @php($targetLabel = $targetDepartment['is_parent'] ? __('organization.dissolution.transfer_to_parent') : $targetDepartment['label'])
                                                <option value="{{ $targetDepartment['id'] }}"
                                                        data-position-availability="{{ json_encode($targetDepartment['position_availability'] ?? []) }}"
                                                        data-position-notice="{{ $targetDepartment['position_notice'] ?? '' }}"
                                                    {{ $selectedTarget === (string) $targetDepartment['id'] ? 'selected' : '' }}>{{ $targetLabel }}</option>
                                            @endforeach
                                        </select>
                                        @if($canRemove)<small class="text-muted">{{ __('organization.dissolution.remove_relation_help') }}</small>@endif
                                    </td>
                                    <td>
                                        <select class="form-control" name="reassignments[{{ $userId }}][position]" data-dissolution-position required>
                                            @foreach($positions as $position => $label)
                                                <option value="{{ $position }}" {{ $selectedPosition === $position ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <small class="organization-dissolution-position-notice" data-dissolution-position-notice aria-live="polite"></small>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <p class="text-muted organization-form-section-help">{{ __('organization.dissolution.no_people') }}</p>
            @endif
            @error('reassignments')<span class="help-block text-danger">{{ $message }}</span>@enderror
            @error('department')<span class="help-block text-danger">{{ $message }}</span>@enderror
        </div>
        <div class="box-footer organization-dissolution-footer">
            <a href="{{ admin_url('organization/departments') }}" class="btn btn-default">{{ __('organization.common.cancel') }}</a>
            <button type="submit" class="btn btn-warning"><i class="fa fa-random"></i> {{ __('organization.dissolution.submit') }}</button>
        </div>
    </form>
</div>


