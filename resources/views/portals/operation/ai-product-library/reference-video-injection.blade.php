<style id="erp-reference-video-style">
.erp-reference-videos{display:flex!important;flex-wrap:wrap!important;gap:16px!important;align-items:flex-start!important;margin:4px 0!important}.erp-reference-generated-sections{display:block!important;width:100%!important;margin:16px 0!important}.erp-reference-generated-section{display:grid!important;grid-template-columns:140px minmax(0,1fr)!important;gap:16px!important;width:100%!important;margin:0!important;padding:12px 0!important;border-top:1px solid #e5e7eb!important}.erp-reference-generated-title{color:#475569!important;font:13px/1.5 Arial,sans-serif!important}.erp-reference-video{width:300px!important;max-width:100%!important;margin:0!important}.erp-reference-video video{display:block!important;width:300px!important;max-width:100%!important;height:auto!important;max-height:400px!important;border-radius:4px!important;background:#111!important;object-fit:contain!important}.erp-reference-video__foot{display:flex!important;align-items:center!important;gap:7px!important;width:300px!important;max-width:100%!important;margin-top:5px!important;font:12px/1.4 Arial,sans-serif!important}.erp-reference-video__name{min-width:0!important;overflow:hidden!important;color:#334155!important;text-overflow:ellipsis!important;white-space:nowrap!important}.erp-reference-video__download,.erp-history-video-download{display:inline-block!important;flex:0 0 auto!important;border-radius:4px!important;padding:3px 7px!important;background:#1686c4!important;color:#fff!important;font:11px/1.4 Arial,sans-serif!important;text-decoration:none!important}.erp-reference-video__download:hover,.erp-history-video-download:hover{background:#0f6e9f!important}.erp-reference-video__size{color:#94a3b8!important;font-size:10px!important}.erp-reference-video__delete{display:none!important;flex:0 0 auto!important;border:0!important;border-radius:4px!important;padding:3px 7px!important;background:#dc2626!important;color:#fff!important;font:11px/1.4 Arial,sans-serif!important;cursor:pointer!important}.erp-reference-editing .erp-reference-video__delete{display:inline-block!important}.erp-history-video-download{margin:5px 0 12px!important}.erp-history-video-name{display:block!important;width:100%!important;margin-top:5px!important;color:#334155!important;font:12px/1.4 Arial,sans-serif!important}.erp-reference-editing{outline:4px solid #22c55e!important;outline-offset:-4px!important}.erp-reference-editing:before{content:'编辑模式：点击页面文字即可修改；上传视频可点击红色按钮删除；完成后点击上方保存';position:fixed!important;z-index:2147483647!important;right:18px!important;bottom:18px!important;border-radius:7px!important;padding:10px 14px!important;background:#15803d!important;color:#fff!important;font:13px/1.4 Arial,sans-serif!important;box-shadow:0 4px 16px rgba(0,0,0,.25)!important}
</style>
<script id="erp-reference-video-script">
(function(){
    var videos = @json($videos);
    var historicalProductCode = @json($historicalProductCode);
    var historicalFallbackDate = @json($historicalFallbackDate);
    var savedEdits = @json($referenceEdits ?? []);
    var hiddenHistoryVideoIndexes = (@json($referenceHiddenVideos ?? [])||[]).map(Number);
    var editSnapshot=[];
    var initialVideoIds=[];
    var initialHistoryVideoIndexes=[];
    var labels = {flow:['Flow素材'],traffic:['扒的素材','引流素材'],remix:['混剪素材']};
    function normalized(value){return String(value||'').replace(/\s+/g,'').replace(/[：:]+$/,'');}
    function labelNode(label){
        var walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
        var node,target=normalized(label);
        while((node=walker.nextNode())){
            if(normalized(node.nodeValue)===target)return node.parentElement;
        }
        return null;
    }
    function labelForType(type){
        var candidates=labels[type]||[];
        for(var i=0;i<candidates.length;i++){var label=labelNode(candidates[i]);if(label)return label;}
        return null;
    }
    function targetBox(labelElement){
        var row=labelElement.closest&&labelElement.closest('tr');
        if(row){
            var cells=Array.prototype.slice.call(row.children).filter(function(item){return /^(TD|TH)$/.test(item.tagName);});
            var current=labelElement.closest('td,th');
            var index=cells.indexOf(current);
            if(index>=0&&cells[index+1])return cells[index+1];
            if(cells.length>1)return cells[cells.length-1];
        }
        var parent=labelElement.parentElement;
        if(parent){
            var children=Array.prototype.slice.call(parent.children),position=children.indexOf(labelElement);
            if(position>=0&&children[position+1])return children[position+1];
            if(parent.nextElementSibling)return parent.nextElementSibling;
        }
        return labelElement;
    }
    function generatedTarget(type){
        var existing=document.querySelector('.erp-reference-videos[data-type="'+type+'"]');if(existing)return existing;
        var root=document.querySelector('.erp-reference-generated-sections');
        if(!root){
            root=document.createElement('div');root.className='erp-reference-generated-sections';
            var anchor=labelNode('视频附件')||labelNode('1688产品链接')||labelNode('采购链接（1688产品链接）');
            var host=anchor?targetBox(anchor):document.body;
            host.appendChild(root);
        }
        var section=document.createElement('div');section.className='erp-reference-generated-section';section.setAttribute('data-generated-type',type);
        var title=document.createElement('div');title.className='erp-reference-generated-title';title.textContent=(labels[type]||[type])[0];
        var box=document.createElement('div');box.className='erp-reference-videos';box.setAttribute('data-type',type);
        section.appendChild(title);section.appendChild(box);root.appendChild(section);return box;
    }
    function card(video){
        var article=document.createElement('div');article.className='erp-reference-video';
        article.setAttribute('data-video-id',video.id||'');
        var player=document.createElement('video');player.controls=true;player.preload='metadata';player.src=video.preview_url;
        var foot=document.createElement('div');foot.className='erp-reference-video__foot';
        var info=document.createElement('span');info.className='erp-reference-video__name';info.title=video.name;info.textContent=video.name;
        var size=document.createElement('span');size.className='erp-reference-video__size';size.textContent=video.size;
        var download=document.createElement('a');download.className='erp-reference-video__download';download.href=video.download_url;download.textContent='下载';
        var remove=document.createElement('button');remove.type='button';remove.className='erp-reference-video__delete';remove.textContent='删除视频';
        foot.appendChild(info);foot.appendChild(size);foot.appendChild(download);foot.appendChild(remove);article.appendChild(player);article.appendChild(foot);return article;
    }
    function historicalPrefix(){
        var label=labelNode('登记日期'),date=historicalFallbackDate;
        if(label){
            var text=targetBox(label).textContent||'',match=text.match(/(20\d{2})\D?(\d{1,2})\D?(\d{1,2})/);
            if(match)date=match[1].slice(-2)+String(match[2]).padStart(2,'0')+String(match[3]).padStart(2,'0');
        }
        return historicalProductCode+'-AI-'+date;
    }
    function normalizeHistoricalVideos(){
        var sequence=0,prefix=historicalPrefix();
        var players=Array.prototype.slice.call(document.querySelectorAll('video')).filter(function(player){return !(player.closest&&player.closest('.erp-reference-video'));});
        players.forEach(function(player,index){
                var historyIndex=index;
                var figure=player.closest&&player.closest('figure');
                var removable=figure||player;
                removable.setAttribute('data-history-video-index',historyIndex);
                if(hiddenHistoryVideoIndexes.indexOf(historyIndex)!==-1){removable.remove();return;}
                sequence++;
                var caption=figure&&figure.querySelector('figcaption');
                var oldName=caption?caption.textContent:'';
                var match=String(oldName).match(/\.([A-Za-z0-9]{2,5})\s*$/),extension=match?match[1].toLowerCase():'mp4';
                var fileName=prefix+'-'+String(sequence).padStart(3,'0')+'.'+extension;
                if(caption){caption.textContent=fileName;caption.classList.add('erp-history-video-name');}
                var link=document.createElement('a');link.className='erp-history-video-download';link.href=player.currentSrc||player.src;link.download=fileName;link.textContent='下载';
                var remove=document.createElement('button');remove.type='button';remove.className='erp-reference-video__delete';remove.setAttribute('data-history-video-index',historyIndex);remove.textContent='删除视频';
                if(caption){caption.insertAdjacentElement('afterend',link);link.insertAdjacentElement('afterend',remove);}
                else if(figure){figure.appendChild(link);figure.appendChild(remove);}
                else {player.insertAdjacentElement('afterend',remove);player.insertAdjacentElement('afterend',link);}
        });
    }
    function render(){
        normalizeHistoricalVideos();
        Object.keys(labels).forEach(function(type){
            var items=videos.filter(function(video){return video.type===type;});if(!items.length)return;
            var label=labelForType(type),box;
            if(label){
                var target=targetBox(label);box=target.querySelector('.erp-reference-videos[data-type="'+type+'"]');
                if(!box){box=document.createElement('div');box.className='erp-reference-videos';box.setAttribute('data-type',type);target.appendChild(box);}
            }else box=generatedTarget(type);
            items.forEach(function(video){box.appendChild(card(video));});
        });
    }
    function editableTextNodes(){
        var nodes=[],walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,{acceptNode:function(node){
            var parent=node.parentElement;if(!parent||/^(SCRIPT|STYLE|NOSCRIPT)$/.test(parent.tagName))return NodeFilter.FILTER_REJECT;
            if(parent.closest('#erp-reference-video-script,.erp-reference-generated-sections,.erp-reference-videos,.erp-history-video-download'))return NodeFilter.FILTER_REJECT;
            return NodeFilter.FILTER_ACCEPT;
        }});while(walker.nextNode())nodes.push(walker.currentNode);return nodes;
    }
    function applySavedEdits(){
        var nodes=editableTextNodes();(savedEdits||[]).forEach(function(patch){var node=nodes[Number(patch.index)];if(node)node.nodeValue=patch.value==null?'':String(patch.value);});
    }
    function setEditMode(enabled){
        if(enabled){editSnapshot=editableTextNodes().map(function(node){return node.nodeValue;});initialVideoIds=Array.prototype.map.call(document.querySelectorAll('.erp-reference-video[data-video-id]'),function(card){return Number(card.getAttribute('data-video-id'));}).filter(Boolean);initialHistoryVideoIndexes=Array.prototype.map.call(document.querySelectorAll('[data-history-video-index]'),function(item){return Number(item.getAttribute('data-history-video-index'));}).filter(function(value,index,items){return items.indexOf(value)===index;});}
        document.body.contentEditable=enabled?'true':'false';
        document.designMode=enabled?'on':'off';
        document.body.classList.toggle('erp-reference-editing',enabled);
        if(enabled)document.body.focus();
        window.parent.postMessage({type:enabled?'erp-reference-edit-started':'erp-reference-edit-stopped'},'*');
    }
    function editablePatches(){
        var merged={};(savedEdits||[]).forEach(function(patch){merged[Number(patch.index)]=patch.value;});
        editableTextNodes().forEach(function(node,index){if(editSnapshot[index]!==node.nodeValue)merged[index]=node.nodeValue;});
        return Object.keys(merged).map(function(index){return {index:Number(index),value:merged[index]};});
    }
    window.addEventListener('message',function(event){
        var message=event.data||{};if(message.type!=='erp-reference-edit')return;
        if(message.action==='start')setEditMode(true);
        if(message.action==='stop')setEditMode(false);
        if(message.action==='serialize'){
            var remaining=Array.prototype.map.call(document.querySelectorAll('.erp-reference-video[data-video-id]'),function(card){return Number(card.getAttribute('data-video-id'));});
            var remainingHistory=Array.prototype.map.call(document.querySelectorAll('[data-history-video-index]'),function(item){return Number(item.getAttribute('data-history-video-index'));});
            window.parent.postMessage({type:'erp-reference-edit-content',patches:editablePatches(),deleted_video_ids:initialVideoIds.filter(function(id){return remaining.indexOf(id)===-1;}),deleted_history_video_indexes:hiddenHistoryVideoIndexes.concat(initialHistoryVideoIndexes.filter(function(index){return remainingHistory.indexOf(index)===-1;})).filter(function(value,index,items){return items.indexOf(value)===index;})},'*');
        }
    });
    function initialize(){applySavedEdits();render();}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);else initialize();
    window.parent.postMessage({type:'erp-reference-edit-ready'},'*');
    document.addEventListener('click',function(event){var button=event.target.closest&&event.target.closest('.erp-reference-video__delete');if(button&&document.body.classList.contains('erp-reference-editing')){event.preventDefault();event.stopPropagation();var card=button.closest('.erp-reference-video');if(card){card.remove();return;}var historyIndex=button.getAttribute('data-history-video-index'),historyItem=document.querySelector('[data-history-video-index="'+historyIndex+'"]');if(historyItem)historyItem.remove();button.remove();}});
})();
</script>
