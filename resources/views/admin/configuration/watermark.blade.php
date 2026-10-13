@php($mode = old('content_mode', $settings['content_mode']))

<div class="erp-config-workspace watermark-settings row">
    <div class="col-md-7 erp-config-primary">
        <form method="POST" action="{{ $saveUrl }}" class="box box-primary erp-config-card">
            @csrf
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-tint"></i> {{ __('configuration.watermark.form.title') }}</h3>
                <div class="erp-config-card__intro">{{ __('configuration.watermark.form.intro') }}</div>
            </div>
            <div class="box-body">
                <div class="setting-row">
                    <div class="setting-label">{{ __('configuration.watermark.fields.enabled') }}</div>
                    <input type="hidden" name="enabled" value="0">
                    <label style="font-weight:400;margin:0;cursor:pointer;">
                        <input type="checkbox" name="enabled" value="1" {{ old('enabled', $settings['enabled']) ? 'checked' : '' }}>
                        {{ __('configuration.watermark.enabled.checkbox') }}
                    </label>
                    <div class="setting-help">{{ __('configuration.watermark.enabled.help') }}</div>
                </div>

                <div class="setting-row">
                    <div class="setting-label">{{ __('configuration.watermark.fields.content_mode') }}</div>
                    <label class="mode-option"><input type="radio" name="content_mode" value="template_1" {{ $mode === 'template_1' ? 'checked' : '' }}> {{ __('configuration.watermark.modes.template_1') }}</label>
                    <label class="mode-option"><input type="radio" name="content_mode" value="template_2" {{ $mode === 'template_2' ? 'checked' : '' }}> {{ __('configuration.watermark.modes.template_2') }}</label>
                    <label class="mode-option"><input type="radio" name="content_mode" value="custom" {{ $mode === 'custom' ? 'checked' : '' }}> {{ __('configuration.watermark.modes.custom') }}</label>
                    <div id="custom-content-wrapper" style="margin-top:10px;{{ $mode === 'custom' ? '' : 'display:none;' }}">
                        <input type="text" name="custom_content" id="custom-content" class="form-control" maxlength="120" value="{{ old('custom_content', $settings['custom_content']) }}" placeholder="{{ __('configuration.watermark.custom_content.placeholder') }}">
                        <div class="setting-help">{{ __('configuration.watermark.custom_content.help') }}</div>
                    </div>
                    @error('content_mode')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                    @error('custom_content')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>

                <div class="setting-row row">
                    <div class="col-sm-6">
                        <div class="setting-label">{{ __('configuration.watermark.fields.opacity') }}</div>
                        <div class="range-row">
                            <input type="range" min="5" max="70" name="opacity" data-linked-number="opacity-number" value="{{ old('opacity', $settings['opacity']) }}">
                            <input type="number" min="5" max="70" id="opacity-number" value="{{ old('opacity', $settings['opacity']) }}" class="form-control">
                        </div>
                        <div class="setting-help">{{ __('configuration.watermark.help.opacity') }}</div>
                        @error('opacity')<div class="text-danger">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <div class="setting-label">{{ __('configuration.watermark.fields.rotation') }}</div>
                        <div class="range-row">
                            <input type="range" min="-90" max="90" name="rotation" data-linked-number="rotation-number" value="{{ old('rotation', $settings['rotation']) }}">
                            <input type="number" min="-90" max="90" id="rotation-number" value="{{ old('rotation', $settings['rotation']) }}" class="form-control">
                        </div>
                        <div class="setting-help">{{ __('configuration.watermark.help.rotation') }}</div>
                        @error('rotation')<div class="text-danger">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="setting-row row">
                    <div class="col-sm-6">
                        <div class="setting-label">{{ __('configuration.watermark.fields.spacing_x') }}</div>
                        <div class="range-row">
                            <input type="range" min="80" max="640" step="10" name="spacing_x" data-linked-number="spacing-x-number" value="{{ old('spacing_x', $settings['spacing_x']) }}">
                            <input type="number" min="80" max="640" id="spacing-x-number" value="{{ old('spacing_x', $settings['spacing_x']) }}" class="form-control">
                        </div>
                        <div class="setting-help">{{ __('configuration.watermark.help.spacing_x') }}</div>
                        @error('spacing_x')<div class="text-danger">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <div class="setting-label">{{ __('configuration.watermark.fields.spacing_y') }}</div>
                        <div class="range-row">
                            <input type="range" min="80" max="640" step="10" name="spacing_y" data-linked-number="spacing-y-number" value="{{ old('spacing_y', $settings['spacing_y']) }}">
                            <input type="number" min="80" max="640" id="spacing-y-number" value="{{ old('spacing_y', $settings['spacing_y']) }}" class="form-control">
                        </div>
                        <div class="setting-help">{{ __('configuration.watermark.help.spacing_y') }}</div>
                        @error('spacing_y')<div class="text-danger">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="setting-row row">
                    <div class="col-sm-6">
                        <div class="setting-label">{{ __('configuration.watermark.fields.font_size') }}</div>
                        <div class="range-row">
                            <input type="range" min="10" max="36" name="font_size" data-linked-number="font-size-number" value="{{ old('font_size', $settings['font_size']) }}">
                            <input type="number" min="10" max="36" id="font-size-number" value="{{ old('font_size', $settings['font_size']) }}" class="form-control">
                        </div>
                        <div class="setting-help">{{ __('configuration.watermark.help.font_size') }}</div>
                        @error('font_size')<div class="text-danger">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <div class="setting-label">{{ __('configuration.watermark.fields.color') }}</div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <input type="color" name="color" id="watermark-color" value="{{ old('color', $settings['color']) }}" style="width:48px;height:34px;padding:2px;">
                            <input type="text" id="watermark-color-text" value="{{ old('color', $settings['color']) }}" class="form-control" maxlength="7" style="max-width:130px;">
                        </div>
                        <div class="setting-help">{{ __('configuration.watermark.help.color') }}</div>
                        @error('color')<div class="text-danger">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
            <div class="box-footer">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> {{ __('configuration.watermark.form.save') }}</button>
            </div>
        </form>
    </div>

    <div class="col-md-5 erp-config-secondary">
        <div class="box box-default erp-config-card">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-eye"></i> {{ __('configuration.watermark.preview.title') }}</h3></div>
            <div class="box-body"><div id="watermark-preview" class="watermark-preview" data-preview-label="{{ __('configuration.watermark.preview.realtime') }}"></div></div>
        </div>
        <div class="box box-default erp-config-card erp-config-audit">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-history"></i> {{ __('configuration.watermark.changes.title') }}</h3></div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover change-table">
                    <thead><tr><th>{{ __('configuration.watermark.changes.time') }}</th><th>{{ __('configuration.watermark.changes.operator') }}</th><th>{{ __('configuration.watermark.changes.item') }}</th><th>{{ __('configuration.watermark.changes.new_value') }}</th></tr></thead>
                    <tbody>
                    @forelse($changes as $change)
                        <tr>
                            <td style="white-space:nowrap;">{{ optional($change->created_at)->format('m-d H:i') }}</td>
                            <td>{{ $change->admin_username ?: $translations['emptyValue'] }}</td>
                            <td>{{ $labels[$change->key] ?? $change->key }}</td>
                            <td>{{ $change->key === 'admin.watermark.enabled' ? ($change->new_value === '1' ? $translations['enabled'] : $translations['disabled']) : \Illuminate\Support\Str::limit($change->new_value ?: $translations['emptyValue'], 28) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">{{ __('configuration.watermark.changes.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var preview = document.getElementById('watermark-preview');
        var customWrapper = document.getElementById('custom-content-wrapper');
        var customContent = document.getElementById('custom-content');
        var previewText = @json($previewText);
        var translations = @json($translations);

        function inputValue(name) {
            var field = document.querySelector('[name="' + name + '"]');
            return field ? field.value : '';
        }

        function selectedMode() {
            var selected = document.querySelector('[name="content_mode"]:checked');
            return selected ? selected.value : 'template_2';
        }

        function syncRange(range) {
            var number = document.getElementById(range.getAttribute('data-linked-number'));
            if (number) { number.value = range.value; }
        }

        function syncNumber(number) {
            var range = document.querySelector('[data-linked-number="' + number.id + '"]');
            if (range) { range.value = number.value; }
        }

        function textForPreview() {
            if (selectedMode() === 'custom') {
                return customContent.value || translations.customPreviewFallback;
            }

            return previewText;
        }

        function drawPreview() {
            var spacingX = Math.max(80, Number(inputValue('spacing_x')) || 240);
            var spacingY = Math.max(80, Number(inputValue('spacing_y')) || 160);
            var scale = window.devicePixelRatio || 1;
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(spacingX * scale);
            canvas.height = Math.round(spacingY * scale);
            var context = canvas.getContext('2d');
            context.scale(scale, scale);
            context.globalAlpha = Math.max(.05, Math.min(.7, (Number(inputValue('opacity')) || 18) / 100));
            context.fillStyle = document.getElementById('watermark-color').value || '#64748B';
            context.font = Math.max(10, Number(inputValue('font_size')) || 15) + 'px sans-serif';
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            context.translate(spacingX / 2, spacingY / 2);
            context.rotate((Number(inputValue('rotation')) || 0) * Math.PI / 180);
            context.fillText(String(textForPreview()).slice(0, 120), 0, 0);
            preview.style.backgroundImage = 'url(' + canvas.toDataURL('image/png') + ')';
            preview.style.backgroundSize = spacingX + 'px ' + spacingY + 'px';
        }

        document.querySelectorAll('[name="content_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                customWrapper.style.display = selectedMode() === 'custom' ? '' : 'none';
                drawPreview();
            });
        });

        document.querySelectorAll('input[type="range"][data-linked-number]').forEach(function (range) {
            range.addEventListener('input', function () { syncRange(range); drawPreview(); });
        });

        document.querySelectorAll('.range-row input[type="number"]').forEach(function (number) {
            number.addEventListener('input', function () { syncNumber(number); drawPreview(); });
        });

        customContent.addEventListener('input', drawPreview);
        document.getElementById('watermark-color').addEventListener('input', function () {
            document.getElementById('watermark-color-text').value = this.value.toUpperCase();
            drawPreview();
        });
        document.getElementById('watermark-color-text').addEventListener('input', function () {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('watermark-color').value = this.value;
                drawPreview();
            }
        });

        drawPreview();
    })();
</script>


