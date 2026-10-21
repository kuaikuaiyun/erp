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
        .aikaipin-reference-preview__header { display: flex; min-width: 0; align-items: center; gap: 16px; padding: 12px 18px; border-bottom: 1px solid #26354d; background: #0f172a; }
        .aikaipin-reference-preview__heading { min-width: 0; flex: 1; }
        .aikaipin-reference-preview__title { overflow: hidden; margin: 0; color: #f8fafc; font-size: 15px; font-weight: 750; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
        .aikaipin-reference-preview__notice { margin: 3px 0 0; color: #94a3b8; font-size: 12px; line-height: 1.4; }
        .aikaipin-reference-preview__back { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; min-height: 34px; border: 1px solid #3a4a64; border-radius: 7px; padding: 0 12px; background: #162136; color: #f8fafc; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .aikaipin-reference-preview__back:hover { border-color: #64748b; background: #1e293b; }
        .aikaipin-reference-preview__frame { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
        @media (max-width: 640px) {
            .aikaipin-reference-preview__header { gap: 10px; padding: 10px 12px; }
            .aikaipin-reference-preview__notice { display: none; }
            .aikaipin-reference-preview__title { font-size: 14px; }
        }
    </style>
</head>
<body>
    <main class="aikaipin-reference-preview">
        <header class="aikaipin-reference-preview__header">
            <div class="aikaipin-reference-preview__heading">
                <h1 class="aikaipin-reference-preview__title">{{ $product->name }}</h1>
                <p class="aikaipin-reference-preview__notice">{{ __('aikaipin.reference_preview.notice') }}</p>
            </div>
            <a class="aikaipin-reference-preview__back" href="{{ $libraryUrl }}">{{ __('aikaipin.reference_preview.back_to_library') }}</a>
        </header>
        <iframe
            class="aikaipin-reference-preview__frame"
            src="{{ $contentUrl }}"
            title="{{ __('aikaipin.reference_preview.frame_title', ['name' => $product->name]) }}"
            sandbox="allow-scripts allow-modals"
            referrerpolicy="no-referrer"
        ></iframe>
    </main>
</body>
</html>
