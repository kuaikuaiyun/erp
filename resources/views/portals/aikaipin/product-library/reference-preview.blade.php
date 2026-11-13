<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('aikaipin.reference_preview.title', ['name' => $product->name]) }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        html, body { width: 100%; min-height: 100%; margin: 0; }
        body { background: #020617; color: #e2e8f0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .aikaipin-reference-preview { display: grid; grid-template-rows: auto 1fr; width: 100vw; height: 100vh; overflow: hidden; }
        .aikaipin-reference-preview.has-assets { grid-template-rows: auto auto 1fr; }
        .aikaipin-reference-preview__header { display: flex; min-width: 0; align-items: center; gap: 16px; padding: 12px 18px; border-bottom: 1px solid #26354d; background: #0f172a; }
        .aikaipin-reference-preview__heading { min-width: 0; flex: 1; }
        .aikaipin-reference-preview__title { overflow: hidden; margin: 0; color: #f8fafc; font-size: 15px; font-weight: 750; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
        .aikaipin-reference-preview__notice { margin: 3px 0 0; color: #94a3b8; font-size: 12px; line-height: 1.4; }
        .aikaipin-reference-preview__back { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; min-height: 34px; border: 1px solid #3a4a64; border-radius: 7px; padding: 0 12px; background: #162136; color: #f8fafc; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .aikaipin-reference-preview__back:hover { border-color: #64748b; background: #1e293b; }
        .reference-edit-actions { display:flex; gap:7px; }
        .reference-edit-actions [hidden] { display:none!important; }
        .reference-edit-button { display:inline-flex; align-items:center; min-height:34px; border:1px solid #3977a7; border-radius:7px; padding:0 12px; background:#176b9d; color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
        .reference-edit-button.is-save { border-color:#16865c; background:#0f9f69; }
        .reference-edit-button.is-cancel { border-color:#64748b; background:#334155; }
        .reference-assets { border-bottom: 1px solid #26354d; padding: 10px 18px 12px; background: #111c30; }
        .reference-assets__top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .reference-assets__title { margin-right: 4px; color: #e2e8f0; font-size: 13px; font-weight: 800; }
        .reference-upload { display: inline-flex; margin: 0; }
        .reference-upload input { display: none; }
        .reference-upload__button { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; border: 1px solid #3977a7; border-radius: 7px; padding: 0 11px; background: #176b9d; color: #fff; font-size: 12px; font-weight: 750; cursor: pointer; }
        .reference-upload__button:hover { background: #2385bd; }
        .reference-assets__hint { color: #8294ad; font-size: 11px; }
        .reference-assets__message { margin: 8px 0 0; border-radius: 6px; padding: 7px 10px; font-size: 12px; }
        .reference-assets__message.success { background: rgba(34,197,94,.14); color: #86efac; }
        .reference-assets__message.error { background: rgba(239,68,68,.14); color: #fca5a5; }
        .aikaipin-reference-preview__frame { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
        @media (max-width: 640px) {
            .aikaipin-reference-preview__header { gap: 10px; padding: 10px 12px; }
            .aikaipin-reference-preview__notice { display: none; }
            .aikaipin-reference-preview__title { font-size: 14px; }
            .reference-assets { padding: 9px 12px; }
        }
    </style>
</head>
<body>
    <main class="aikaipin-reference-preview @isset($referenceVideos) has-assets @endisset">
        <header class="aikaipin-reference-preview__header">
            <div class="aikaipin-reference-preview__heading">
                <h1 class="aikaipin-reference-preview__title">{{ $product->name }}</h1>
                <p class="aikaipin-reference-preview__notice">{{ __('aikaipin.reference_preview.notice') }}</p>
            </div>
            @if(!empty($referenceEditable))
            <div class="reference-edit-actions">
                <button class="reference-edit-button" type="button" data-reference-edit-start>编辑内容</button>
                <button class="reference-edit-button is-save" type="button" data-reference-edit-save hidden>保存</button>
                <button class="reference-edit-button is-cancel" type="button" data-reference-edit-cancel hidden>取消</button>
            </div>
            @endif
            <a class="aikaipin-reference-preview__back" href="{{ $libraryUrl }}">{{ __('aikaipin.reference_preview.back_to_library') }}</a>
        </header>
        @isset($referenceVideos)
        <section class="reference-assets" aria-label="参考页视频素材">
            <div class="reference-assets__top">
                <span class="reference-assets__title">视频素材</span>
                @foreach(['flow' => 'Flow素材', 'traffic' => '扒的素材', 'remix' => '混剪素材'] as $type => $label)
                <form class="reference-upload" method="post" enctype="multipart/form-data" action="{{ $referenceVideoUploadUrl }}">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <label class="reference-upload__button"><span>＋</span> 上传{{ $label }}<input type="file" name="video" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm,video/x-matroska,video/x-m4v" required onchange="this.form.submit()"></label>
                </form>
                @endforeach
                <span class="reference-assets__hint">{{ $referenceVideoNamingHint }}</span>
            </div>
            @if(session('success'))<div class="reference-assets__message success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="reference-assets__message error">{{ $errors->first() }}</div>@endif
        </section>
        @endisset
        <iframe
            id="aikaipin-reference-frame"
            class="aikaipin-reference-preview__frame"
            src="{{ $contentUrl }}"
            title="{{ __('aikaipin.reference_preview.frame_title', ['name' => $product->name]) }}"
            sandbox="allow-scripts allow-modals allow-downloads"
            referrerpolicy="no-referrer"
        ></iframe>
    </main>
    @if(!empty($referenceEditable))
    <script>
    (function(){
        var frame=document.getElementById('aikaipin-reference-frame'),start=document.querySelector('[data-reference-edit-start]'),save=document.querySelector('[data-reference-edit-save]'),cancel=document.querySelector('[data-reference-edit-cancel]');
        if(!frame||!start)return;
        function state(editing,busy){start.hidden=editing;save.hidden=!editing;cancel.hidden=!editing;save.disabled=!!busy;cancel.disabled=!!busy;save.textContent=busy?'保存中...':'保存';}
        function send(action){if(frame.contentWindow)frame.contentWindow.postMessage({type:'erp-reference-edit',action:action},'*');}
        start.addEventListener('click',function(){state(true,false);send('start');window.setTimeout(function(){send('start');},150);});
        cancel.addEventListener('click',function(){frame.src=frame.src;state(false,false);});
        save.addEventListener('click',function(){state(true,true);send('serialize');});
        window.addEventListener('message',function(event){
            if(event.source!==frame.contentWindow||!event.data)return;
            if(event.data.type==='erp-reference-edit-ready'){start.textContent='编辑内容';return;}
            if(event.data.type==='erp-reference-edit-started'){save.textContent='保存';return;}
            if(event.data.type!=='erp-reference-edit-content')return;
            fetch(@json($referenceContentUpdateUrl),{method:'PUT',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':@json(csrf_token())},body:JSON.stringify({patches:event.data.patches||[],deleted_video_ids:event.data.deleted_video_ids||[],deleted_history_video_indexes:event.data.deleted_history_video_indexes||[]})})
                .then(function(response){return response.json().then(function(data){if(!response.ok)throw new Error(data.message||'保存失败');return data;});})
                .then(function(){frame.src=frame.src;state(false,false);})
                .catch(function(error){alert(error.message||'保存失败');state(true,false);});
        });
    })();
    </script>
    @endif
</body>
</html>
