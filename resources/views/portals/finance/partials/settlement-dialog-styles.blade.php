@pushOnce('styles')
<style>
.settlement-dialog{width:780px;max-width:calc(100vw - 32px);max-height:85vh;border:0;border-radius:8px;padding:0;color:#3b5870}
.settlement-dialog::backdrop{background:#102c4866}
.settlement-dialog header{padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2eaf2}
.settlement-dialog h3{font-size:17px;margin:0}
.settlement-dialog header button{border:0;background:none;font-size:24px}
.settlement-dialog footer{padding:14px 20px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #e2eaf2}
.settlement-import-drop{position:relative;display:block;background:#f2faff;border:1px dashed #3498db;padding:40px 20px;text-align:center;margin:20px;cursor:pointer}
.settlement-import-drop input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer}
.settlement-import-drop .fa{display:block;font-size:30px;margin-bottom:12px;color:#2885b1}
</style>
@endPushOnce
