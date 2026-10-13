<div class="organization-form-section organization-split-panel">
    <div class="organization-form-section-divider"><span>{{ __('organization.split.title') }}</span></div>
    <p class="help-block organization-form-section-help">{{ __('organization.split.intro') }}</p>
    @if($affectedMembers->isNotEmpty())
        <p>{{ __('organization.split.affected_people_help') }}</p>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th>{{ __('organization.accounts.name') }}</th>
                    <th>{{ __('organization.accounts.department') }}</th>
                    <th>{{ __('organization.split.transfer_to') }}</th>
                    <th>{{ __('organization.split.new_position') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($affectedMembers as $member)
                    <tr>
                        <td><strong>{{ $member->name }}</strong><br><small class="text-muted">{{ $member->username }}</small></td>
                        <td>{{ $parent->name }} · {{ $positions[$member->position] ?? $member->position }}</td>
                        <td>
                            <select class="form-control" name="reassignments[{{ $member->user_id }}][department_id]" required>
                                <option value="new">{{ __('organization.split.new_department') }}</option>
                                @foreach($departmentOptions as $option)
                                    @if($option['id'] !== $parent->getKey())<option value="{{ $option['id'] }}" {{ old('reassignments.'.$member->user_id.'.department_id') == $option['id'] ? 'selected' : '' }}>{{ $option['label'] }}</option>@endif
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <select class="form-control" name="reassignments[{{ $member->user_id }}][position]" required>
                                @foreach($positions as $position => $label)<option value="{{ $position }}" {{ old('reassignments.'.$member->user_id.'.position', $member->position) === $position ? 'selected' : '' }}>{{ $label }}</option>@endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-muted organization-form-section-help">{{ __('organization.split.no_people_to_move') }}</p>
    @endif
    @error('reassignments')<span class="help-block text-danger">{{ $message }}</span>@enderror
</div>


