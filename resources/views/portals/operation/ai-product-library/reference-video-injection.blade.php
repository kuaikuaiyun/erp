<style id="erp-reference-video-style">
.erp-reference-videos{display:flex!important;flex-wrap:wrap!important;gap:16px!important;align-items:flex-start!important;margin:4px 0!important;min-height:18px!important}.erp-reference-generated-sections{display:block!important;width:100%!important;margin:16px 0!important}.erp-reference-generated-section{display:grid!important;grid-template-columns:140px minmax(0,1fr)!important;gap:16px!important;width:100%!important;margin:0!important;padding:12px 0!important;border-top:1px solid #e5e7eb!important}.erp-reference-generated-title{color:#475569!important;font:13px/1.5 Arial,sans-serif!important}.erp-reference-video{width:300px!important;max-width:100%!important;margin:0!important}.erp-reference-video video{display:block!important;width:300px!important;max-width:100%!important;height:auto!important;max-height:400px!important;border-radius:4px!important;background:#111!important;object-fit:contain!important}.erp-history-video-item{display:inline-block!important;max-width:100%!important;vertical-align:top!important}.erp-reference-video__foot{display:flex!important;align-items:center!important;gap:7px!important;width:300px!important;max-width:100%!important;margin-top:5px!important;font:12px/1.4 Arial,sans-serif!important}.erp-reference-video__name{min-width:0!important;overflow:hidden!important;color:#334155!important;text-overflow:ellipsis!important;white-space:nowrap!important}.erp-reference-video__download,.erp-history-video-download{display:inline-block!important;flex:0 0 auto!important;border-radius:4px!important;padding:3px 7px!important;background:#1686c4!important;color:#fff!important;font:11px/1.4 Arial,sans-serif!important;text-decoration:none!important}.erp-reference-video__download:hover,.erp-history-video-download:hover{background:#0f6e9f!important}.erp-reference-video__size{color:#94a3b8!important;font-size:10px!important}.erp-reference-video__delete{display:none!important;flex:0 0 auto!important;border:0!important;border-radius:4px!important;padding:3px 7px!important;background:#dc2626!important;color:#fff!important;font:11px/1.4 Arial,sans-serif!important;cursor:pointer!important}.erp-reference-editing .erp-reference-video__delete{display:inline-block!important}.erp-reference-editing .erp-reference-video[draggable="true"]{cursor:grab!important}.erp-reference-editing .erp-reference-video[draggable="true"]:before{content:'拖动调整位置 / 素材分组';display:block!important;margin-bottom:5px!important;border-radius:4px!important;padding:4px 7px!important;background:#e0f2fe!important;color:#0369a1!important;font:11px/1.4 Arial,sans-serif!important;text-align:center!important}.erp-reference-video.is-dragging{opacity:.35!important}.erp-reference-videos.is-drag-over{outline:2px dashed #0ea5e9!important;outline-offset:5px!important;background:rgba(14,165,233,.05)!important}.erp-history-video-download{margin:5px 0 12px!important}.erp-history-video-name{display:block!important;width:100%!important;margin-top:5px!important;color:#334155!important;font:12px/1.4 Arial,sans-serif!important}.erp-reference-editing{outline:4px solid #22c55e!important;outline-offset:-4px!important}.erp-reference-editing:before{content:'编辑模式：文字可直接修改并通过蓝色手柄拖动；图片可直接拖动；上传视频可排序、切换分组或删除；完成后点击上方保存';position:fixed!important;z-index:2147483647!important;right:18px!important;bottom:18px!important;border-radius:7px!important;padding:10px 14px!important;background:#15803d!important;color:#fff!important;font:13px/1.4 Arial,sans-serif!important;box-shadow:0 4px 16px rgba(0,0,0,.25)!important}
.erp-layout-target{position:relative!important;transform:translate(var(--erp-layout-x,0px),var(--erp-layout-y,0px))!important}.erp-layout-handle{display:none!important;position:absolute!important;z-index:2147483000!important;top:-11px!important;left:-11px!important;width:22px!important;height:22px!important;border:2px solid #fff!important;border-radius:50%!important;background:#0ea5e9!important;color:#fff!important;font:700 13px/18px Arial,sans-serif!important;text-align:center!important;box-shadow:0 2px 7px rgba(0,0,0,.28)!important;cursor:move!important;user-select:none!important}.erp-reference-editing .erp-layout-handle{display:block!important}.erp-reference-editing img.erp-layout-target{outline:2px dashed #0ea5e9!important;outline-offset:3px!important;cursor:move!important}.erp-layout-target.erp-layout-moving{z-index:2147482000!important;outline:2px solid #f59e0b!important;cursor:grabbing!important}
</style>
<style id="erp-reference-pointer-drag-style">
.erp-reference-video__drag{display:none!important;width:100%!important;margin:0 0 5px!important;border:0!important;border-radius:4px!important;padding:5px 8px!important;background:#e0f2fe!important;color:#0369a1!important;font:11px/1.4 Arial,sans-serif!important;text-align:center!important;cursor:grab!important;touch-action:none!important;user-select:none!important}
.erp-reference-editing .erp-reference-video__drag{display:block!important}.erp-reference-video__drag:active{cursor:grabbing!important}
.erp-reference-video__free-drag{display:none!important;width:100%!important;margin:0 0 5px!important;border:0!important;border-radius:4px!important;padding:5px 8px!important;background:#dcfce7!important;color:#166534!important;font:11px/1.4 Arial,sans-serif!important;text-align:center!important;cursor:move!important;touch-action:none!important;user-select:none!important}.erp-reference-editing .erp-reference-video__free-drag{display:block!important}.erp-reference-video__free-drag:active{cursor:grabbing!important}
</style>
<script id="erp-reference-video-script">
(function(){
    var videos = @json($videos);
    var historicalProductCode = @json($historicalProductCode);
    var historicalFallbackDate = @json($historicalFallbackDate);
    var savedEdits = @json($referenceEdits ?? []);
    var savedLayouts = @json($referenceLayouts ?? []);
    var hiddenHistoryVideoIndexes = (@json($referenceHiddenVideos ?? [])||[]).map(Number);
    var editSnapshot=[];
    var initialVideoIds=[];
    var initialHistoryVideoIndexes=[];
    var draggedVideo=null;
    var movingElement=null;
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
        article.classList.add('erp-layout-target');article.setAttribute('data-erp-layout-key','video-'+(video.id||''));
        var freeDrag=document.createElement('button');freeDrag.type='button';freeDrag.className='erp-reference-video__free-drag';freeDrag.setAttribute('contenteditable','false');freeDrag.textContent='自由拖动到页面任意位置';
        var drag=document.createElement('button');drag.type='button';drag.className='erp-reference-video__drag';drag.setAttribute('contenteditable','false');drag.textContent='拖动调整位置 / 素材分组';
        var player=document.createElement('video');player.controls=true;player.preload='none';player.setAttribute('data-lazy-video-src',video.preview_url);
        var foot=document.createElement('div');foot.className='erp-reference-video__foot';
        var info=document.createElement('span');info.className='erp-reference-video__name';info.title=video.name;info.textContent=video.name;
        var size=document.createElement('span');size.className='erp-reference-video__size';size.textContent=video.size;
        var download=document.createElement('a');download.className='erp-reference-video__download';download.href=video.download_url;download.textContent='下载';
        var remove=document.createElement('button');remove.type='button';remove.className='erp-reference-video__delete';remove.textContent='删除视频';
        foot.appendChild(info);foot.appendChild(size);foot.appendChild(download);foot.appendChild(remove);article.appendChild(freeDrag);article.appendChild(drag);article.appendChild(player);article.appendChild(foot);return article;
    }
    function historicalPrefix(){
        var label=labelNode('登记日期'),date=historicalFallbackDate;
        if(label){
            var text=targetBox(label).textContent||'',match=text.match(/(20\d{2})\D?(\d{1,2})\D?(\d{1,2})/);
            if(match)date=match[1].slice(-2)+String(match[2]).padStart(2,'0')+String(match[3]).padStart(2,'0');
        }
        return historicalProductCode+'-AI-'+date;
    }
    function legacyVideoNameNode(player,figure){
        var caption=figure&&figure.querySelector('figcaption');if(caption)return caption;
        var candidates=[],node=player.nextSibling,steps=0;
        while(node&&steps++<4){candidates.push(node);node=node.nextSibling;}
        node=player.previousSibling;steps=0;
        while(node&&steps++<3){candidates.push(node);node=node.previousSibling;}
        return candidates.find(function(item){
            if(item.nodeType===3)return /^\s*[^\r\n<>]+\.(mp4|mov|avi|webm|mkv|m4v)\s*$/i.test(item.nodeValue||'');
            if(item.nodeType!==1||/^(VIDEO|SOURCE|A|BUTTON)$/i.test(item.tagName))return false;
            return /^\s*[^\r\n<>]+\.(mp4|mov|avi|webm|mkv|m4v)\s*$/i.test(item.textContent||'');
        })||null;
    }
    function normalizeHistoricalVideos(){
        var sequence=0,prefix=historicalPrefix();
        var players=Array.prototype.slice.call(document.querySelectorAll('video')).filter(function(player){return !(player.closest&&player.closest('.erp-reference-video'));});
        players.forEach(function(player,index){
                var historyIndex=index;
                var figure=player.closest&&player.closest('figure');
                var legacyName=legacyVideoNameNode(player,figure);
                var removable=figure;
                if(!removable){
                    removable=document.createElement('span');
                    removable.className='erp-history-video-item';
                    player.parentNode.insertBefore(removable,player);
                    removable.appendChild(player);
                }else removable.classList.add('erp-history-video-item');
                removable.setAttribute('data-history-video-index',historyIndex);
                if(hiddenHistoryVideoIndexes.indexOf(historyIndex)!==-1){removable.remove();return;}
                sequence++;
                var oldName=legacyName?(legacyName.nodeValue||legacyName.textContent||''):'';
                var match=String(oldName).match(/\.([A-Za-z0-9]{2,5})\s*$/),extension=match?match[1].toLowerCase():'mp4';
                var fileName=prefix+'-'+String(sequence).padStart(3,'0')+'.'+extension;
                var caption=figure&&figure.querySelector('figcaption');
                if(!caption){caption=document.createElement('span');caption.className='erp-history-video-name';if(figure)figure.appendChild(caption);else removable.appendChild(caption);}
                caption.textContent=fileName;caption.classList.add('erp-history-video-name');
                if(legacyName&&legacyName!==caption){if(legacyName.nodeType===3)legacyName.nodeValue='';else legacyName.style.display='none';}
                var link=document.createElement('a');link.className='erp-history-video-download';link.href=player.currentSrc||player.src;link.download=fileName;link.textContent='下载';
                var remove=document.createElement('button');remove.type='button';remove.className='erp-reference-video__delete';remove.setAttribute('data-history-video-index',historyIndex);remove.textContent='删除视频';
                caption.insertAdjacentElement('afterend',link);link.insertAdjacentElement('afterend',remove);
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
        var lazyPlayers=document.querySelectorAll('video[data-lazy-video-src]');
        function loadPlayer(player){if(!player||player.hasAttribute('src'))return;player.src=player.getAttribute('data-lazy-video-src');player.removeAttribute('data-lazy-video-src');}
        if('IntersectionObserver' in window){var observer=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting){loadPlayer(entry.target);observer.unobserve(entry.target);}});},{rootMargin:'500px 0px'});lazyPlayers.forEach(function(player){observer.observe(player);player.addEventListener('play',function(){loadPlayer(player);},{once:true});});}
        else lazyPlayers.forEach(loadPlayer);
    }
    function editableTextNodes(){
        var nodes=[],walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,{acceptNode:function(node){
            var parent=node.parentElement;if(!parent||/^(SCRIPT|STYLE|NOSCRIPT)$/.test(parent.tagName))return NodeFilter.FILTER_REJECT;
            if(parent.closest('#erp-reference-video-script,.erp-reference-generated-sections,.erp-reference-videos,.erp-history-video-download,.erp-layout-handle'))return NodeFilter.FILTER_REJECT;
            return NodeFilter.FILTER_ACCEPT;
        }});while(walker.nextNode())nodes.push(walker.currentNode);return nodes;
    }
    function applySavedEdits(){
        var nodes=editableTextNodes();(savedEdits||[]).forEach(function(patch){var node=nodes[Number(patch.index)];if(node)node.nodeValue=patch.value==null?'':String(patch.value);});
    }
    function registerLayoutTarget(element,key,title){
        if(!element)return;
        element.classList.add('erp-layout-target');
        if(!element.hasAttribute('data-erp-layout-key'))element.setAttribute('data-erp-layout-key',key);
        if(element.querySelector(':scope > .erp-layout-handle'))return;
        var handle=document.createElement('span');handle.className='erp-layout-handle';handle.setAttribute('contenteditable','false');handle.setAttribute('title',title||'拖动位置');handle.textContent='↕';element.appendChild(handle);
    }
    function prepareGroupLayoutTargets(){
        Object.keys(labels).forEach(function(type){
            var label=labelForType(type);
            if(label)registerLayoutTarget(label,'material-label-'+type,'拖动'+(labels[type][0]||'素材分组')+'标题位置');
            document.querySelectorAll('.erp-reference-generated-section[data-generated-type="'+type+'"] > .erp-reference-generated-title').forEach(function(title){registerLayoutTarget(title,'material-label-'+type,'拖动素材分组标题位置');});
        });
    }
    function prepareLayoutTargets(){
        var seen=[];
        editableTextNodes().forEach(function(node,index){
            if(!String(node.nodeValue||'').trim())return;
            var element=node.parentElement;
            if(!element||seen.indexOf(element)!==-1||/^(HTML|BODY|TABLE|TBODY|THEAD|TR|OPTION|SCRIPT|STYLE)$/.test(element.tagName))return;
            seen.push(element);registerLayoutTarget(element,'text-'+index,'拖动文字位置');
        });
        Array.prototype.forEach.call(document.querySelectorAll('img'),function(image,index){
            if(image.closest('.erp-reference-generated-sections,.erp-reference-videos'))return;
            image.classList.add('erp-layout-target');image.setAttribute('data-erp-layout-key','image-'+index);
        });
        prepareGroupLayoutTargets();
    }
    function applySavedLayouts(){
        var map={};(savedLayouts||[]).forEach(function(item){map[item.key]=item;});
        document.querySelectorAll('[data-erp-layout-key]').forEach(function(element){var item=map[element.getAttribute('data-erp-layout-key')]||{};var x=Number(item.x)||0,y=Number(item.y)||0;element.dataset.erpLayoutX=x;element.dataset.erpLayoutY=y;element.style.setProperty('--erp-layout-x',x+'px');element.style.setProperty('--erp-layout-y',y+'px');});
    }
    function elementLayout(){return Array.prototype.map.call(document.querySelectorAll('[data-erp-layout-key]'),function(element){return {key:element.getAttribute('data-erp-layout-key'),x:Number(element.dataset.erpLayoutX)||0,y:Number(element.dataset.erpLayoutY)||0};}).filter(function(item){return item.x!==0||item.y!==0;});}
    function setEditMode(enabled){
        if(enabled){editSnapshot=editableTextNodes().map(function(node){return node.nodeValue;});initialVideoIds=Array.prototype.map.call(document.querySelectorAll('.erp-reference-video[data-video-id]'),function(card){return Number(card.getAttribute('data-video-id'));}).filter(Boolean);initialHistoryVideoIndexes=Array.prototype.map.call(document.querySelectorAll('[data-history-video-index]'),function(item){return Number(item.getAttribute('data-history-video-index'));}).filter(function(value,index,items){return items.indexOf(value)===index;});}
        if(enabled)Object.keys(labels).forEach(function(type){
            if(document.querySelector('.erp-reference-videos[data-type="'+type+'"]'))return;
            var label=labelForType(type),target=label?targetBox(label):null;
            if(target){var box=document.createElement('div');box.className='erp-reference-videos';box.setAttribute('data-type',type);target.appendChild(box);}else generatedTarget(type);
        });
        if(enabled){prepareGroupLayoutTargets();applySavedLayouts();}
        document.body.contentEditable=enabled?'true':'false';
        document.designMode=enabled?'on':'off';
        document.body.classList.toggle('erp-reference-editing',enabled);
        document.querySelectorAll('.erp-reference-video[data-video-id]').forEach(function(card){card.draggable=false;});
        if(enabled)document.body.focus();
        window.parent.postMessage({type:enabled?'erp-reference-edit-started':'erp-reference-edit-stopped'},'*');
    }
    function editablePatches(){
        var merged={};(savedEdits||[]).forEach(function(patch){merged[Number(patch.index)]=patch.value;});
        editableTextNodes().forEach(function(node,index){if(editSnapshot[index]!==node.nodeValue)merged[index]=node.nodeValue;});
        return Object.keys(merged).map(function(index){return {index:Number(index),value:merged[index]};});
    }
    function videoLayout(){
        var result=[];
        ['flow','traffic','remix'].forEach(function(type){
            document.querySelectorAll('.erp-reference-videos[data-type="'+type+'"]').forEach(function(box){
                box.querySelectorAll(':scope > .erp-reference-video[data-video-id]').forEach(function(card){result.push({id:Number(card.getAttribute('data-video-id')),type:type});});
            });
        });
        return result.filter(function(item){return item.id>0;});
    }
    window.addEventListener('message',function(event){
        var message=event.data||{};if(message.type!=='erp-reference-edit')return;
        if(message.action==='start')setEditMode(true);
        if(message.action==='stop')setEditMode(false);
        if(message.action==='serialize'){
            var remaining=Array.prototype.map.call(document.querySelectorAll('.erp-reference-video[data-video-id]'),function(card){return Number(card.getAttribute('data-video-id'));});
            var remainingHistory=Array.prototype.map.call(document.querySelectorAll('[data-history-video-index]'),function(item){return Number(item.getAttribute('data-history-video-index'));});
            window.parent.postMessage({type:'erp-reference-edit-content',patches:editablePatches(),video_layout:videoLayout(),element_layout:elementLayout(),deleted_video_ids:initialVideoIds.filter(function(id){return remaining.indexOf(id)===-1;}),deleted_history_video_indexes:hiddenHistoryVideoIndexes.concat(initialHistoryVideoIndexes.filter(function(index){return remainingHistory.indexOf(index)===-1;})).filter(function(value,index,items){return items.indexOf(value)===index;})},'*');
        }
    });
    function initialize(){applySavedEdits();render();prepareLayoutTargets();applySavedLayouts();}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);else initialize();
    window.parent.postMessage({type:'erp-reference-edit-ready'},'*');
    document.addEventListener('click',function(event){var button=event.target.closest&&event.target.closest('.erp-reference-video__delete');if(button&&document.body.classList.contains('erp-reference-editing')){event.preventDefault();event.stopPropagation();var item=button.closest('.erp-reference-video,.erp-history-video-item');if(item){item.remove();return;}var historyIndex=button.getAttribute('data-history-video-index'),historyItem=document.querySelector('[data-history-video-index="'+historyIndex+'"]');if(historyItem)historyItem.remove();button.remove();}});
    document.addEventListener('dragstart',function(event){var card=event.target.closest&&event.target.closest('.erp-reference-video[data-video-id]');if(!card||!document.body.classList.contains('erp-reference-editing'))return;draggedVideo=card;card.classList.add('is-dragging');event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',card.getAttribute('data-video-id'));});
    document.addEventListener('dragover',function(event){var box=event.target.closest&&event.target.closest('.erp-reference-videos[data-type]');if(!box||!draggedVideo)return;event.preventDefault();event.dataTransfer.dropEffect='move';document.querySelectorAll('.erp-reference-videos.is-drag-over').forEach(function(item){item.classList.remove('is-drag-over');});box.classList.add('is-drag-over');var cards=Array.prototype.slice.call(box.querySelectorAll(':scope > .erp-reference-video:not(.is-dragging)'));var before=cards.find(function(card){var rect=card.getBoundingClientRect();return event.clientY<rect.top+rect.height/2||(event.clientY<=rect.bottom&&event.clientX<rect.left+rect.width/2);});box.insertBefore(draggedVideo,before||null);});
    document.addEventListener('drop',function(event){if(draggedVideo)event.preventDefault();document.querySelectorAll('.erp-reference-videos.is-drag-over').forEach(function(item){item.classList.remove('is-drag-over');});});
    document.addEventListener('dragend',function(){if(draggedVideo)draggedVideo.classList.remove('is-dragging');draggedVideo=null;document.querySelectorAll('.erp-reference-videos.is-drag-over').forEach(function(item){item.classList.remove('is-drag-over');});});
    function pointerVideoDrag(event,handle){
        var card=handle.closest('.erp-reference-video[data-video-id]');if(!card)return;
        event.preventDefault();event.stopPropagation();draggedVideo=card;card.classList.add('is-dragging');
        if(handle.setPointerCapture)try{handle.setPointerCapture(event.pointerId);}catch(ignore){}
        function clearBoxes(){document.querySelectorAll('.erp-reference-videos.is-drag-over').forEach(function(item){item.classList.remove('is-drag-over');});}
        function move(moveEvent){
            if(!draggedVideo)return;moveEvent.preventDefault();clearBoxes();
            var hit=document.elementFromPoint(moveEvent.clientX,moveEvent.clientY),box=hit&&hit.closest?hit.closest('.erp-reference-videos[data-type]'):null;
            if(!box){box=Array.prototype.slice.call(document.querySelectorAll('.erp-reference-videos[data-type]')).find(function(item){var rect=item.getBoundingClientRect();return moveEvent.clientX>=rect.left-15&&moveEvent.clientX<=rect.right+15&&moveEvent.clientY>=rect.top-25&&moveEvent.clientY<=rect.bottom+25;});}
            if(!box)return;box.classList.add('is-drag-over');
            var cards=Array.prototype.slice.call(box.querySelectorAll(':scope > .erp-reference-video:not(.is-dragging)'));
            var before=cards.find(function(item){var rect=item.getBoundingClientRect();return moveEvent.clientY<rect.top+rect.height/2||(moveEvent.clientY<=rect.bottom&&moveEvent.clientX<rect.left+rect.width/2);});
            box.insertBefore(draggedVideo,before||null);
        }
        function stop(){if(draggedVideo)draggedVideo.classList.remove('is-dragging');draggedVideo=null;clearBoxes();document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',stop);document.removeEventListener('pointercancel',stop);}
        document.addEventListener('pointermove',move,{passive:false});document.addEventListener('pointerup',stop);document.addEventListener('pointercancel',stop);
    }
    document.addEventListener('pointerdown',function(event){
        if(!document.body.classList.contains('erp-reference-editing'))return;
        var videoHandle=event.target.closest&&event.target.closest('.erp-reference-video__drag');if(videoHandle){pointerVideoDrag(event,videoHandle);return;}
        var handle=event.target.closest&&event.target.closest('.erp-layout-handle,.erp-reference-video__free-drag'),target=handle?handle.parentElement:(event.target.matches&&event.target.matches('img.erp-layout-target')?event.target:null);
        if(!target)return;event.preventDefault();event.stopPropagation();
        var startX=event.clientX,startY=event.clientY,baseX=Number(target.dataset.erpLayoutX)||0,baseY=Number(target.dataset.erpLayoutY)||0;movingElement=target;target.classList.add('erp-layout-moving');
        function move(moveEvent){if(!movingElement)return;var x=Math.round(baseX+moveEvent.clientX-startX),y=Math.round(baseY+moveEvent.clientY-startY);movingElement.dataset.erpLayoutX=x;movingElement.dataset.erpLayoutY=y;movingElement.style.setProperty('--erp-layout-x',x+'px');movingElement.style.setProperty('--erp-layout-y',y+'px');}
        function stop(){if(movingElement)movingElement.classList.remove('erp-layout-moving');movingElement=null;document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',stop);document.removeEventListener('pointercancel',stop);}
        document.addEventListener('pointermove',move);document.addEventListener('pointerup',stop);document.addEventListener('pointercancel',stop);
    });
})();
</script>
