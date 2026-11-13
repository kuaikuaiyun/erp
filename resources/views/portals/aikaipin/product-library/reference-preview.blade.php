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
        .reference-upload.is-uploading .reference-upload__button { opacity: .55; cursor: wait; pointer-events: none; }
        .reference-upload-progress { display:none; width:min(620px,100%); margin-top:9px; }
        .reference-upload-progress.is-active { display:block; }
        .reference-upload-progress__meta { display:flex; justify-content:space-between; gap:10px; margin-bottom:5px; color:#cbd5e1; font-size:11px; }
        .reference-upload-progress__track { overflow:hidden; height:8px; border-radius:999px; background:#26354d; }
        .reference-upload-progress__bar { width:0; height:100%; border-radius:inherit; background:linear-gradient(90deg,#1686c4,#22c55e); transition:width .15s ease; }
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
                    <label class="reference-upload__button"><span>＋</span> 上传{{ $label }}<input type="file" name="video" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm,video/x-matroska,video/x-m4v" required></label>
                </form>
                @endforeach
                <span class="reference-assets__hint">{{ $referenceVideoNamingHint }}</span>
            </div>
            <div class="reference-upload-progress" data-upload-progress><div class="reference-upload-progress__meta"><span data-upload-status>等待选择视频</span><b data-upload-percent>0%</b></div><div class="reference-upload-progress__track"><div class="reference-upload-progress__bar" data-upload-bar></div></div></div>
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
            fetch(@json($referenceContentUpdateUrl),{method:'PUT',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':@json(csrf_token())},body:JSON.stringify({patches:event.data.patches||[],video_layout:event.data.video_layout||[],element_layout:event.data.element_layout||[],deleted_video_ids:event.data.deleted_video_ids||[],deleted_history_video_indexes:event.data.deleted_history_video_indexes||[]})})
                .then(function(response){return response.json().then(function(data){if(!response.ok)throw new Error(data.message||'保存失败');return data;});})
                .then(function(){frame.src=frame.src;state(false,false);})
                .catch(function(error){alert(error.message||'保存失败');state(true,false);});
        });
    })();
    </script>
    @endif
    <script>
    (function(){
        var forms=document.querySelectorAll('.reference-upload'),progress=document.querySelector('[data-upload-progress]'),status=document.querySelector('[data-upload-status]'),percent=document.querySelector('[data-upload-percent]'),bar=document.querySelector('[data-upload-bar]'),frame=document.getElementById('aikaipin-reference-frame');
        if(!forms.length||!progress)return;
        function setProgress(value,text){value=Math.max(0,Math.min(100,Math.round(value)));progress.classList.add('is-active');bar.style.width=value+'%';percent.textContent=value+'%';status.textContent=text||'正在上传…';}
        function resetForms(){forms.forEach(function(form){form.classList.remove('is-uploading');var input=form.querySelector('input[type="file"]');if(input)input.disabled=false;});}
        forms.forEach(function(form){var input=form.querySelector('input[type="file"]');if(!input)return;input.addEventListener('change',function(){var file=input.files&&input.files[0];if(!file)return;forms.forEach(function(item){item.classList.add('is-uploading');var field=item.querySelector('input[type="file"]');if(field&&field!==input)field.disabled=true;});setProgress(0,'准备上传：'+file.name);var xhr=new XMLHttpRequest();xhr.open('POST',form.action,true);xhr.setRequestHeader('Accept','application/json');xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');xhr.upload.addEventListener('progress',function(event){if(event.lengthComputable)setProgress(event.loaded/event.total*100,'正在上传：'+file.name);});xhr.addEventListener('load',function(){var data={};try{data=JSON.parse(xhr.responseText||'{}');}catch(error){}if(xhr.status>=200&&xhr.status<300){setProgress(100,'上传完成：'+(data.file_name||file.name));input.value='';resetForms();if(frame)frame.src=frame.src;}else{var message=data.message||(data.errors&&data.errors.video&&data.errors.video[0])||'上传失败，请重试。';setProgress(0,message);resetForms();}});xhr.addEventListener('error',function(){setProgress(0,'网络异常，上传失败，请重试。');resetForms();});xhr.addEventListener('abort',function(){setProgress(0,'上传已取消。');resetForms();});xhr.send(new FormData(form));});});
    })();
    </script>
</body>
</html>
