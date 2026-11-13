<style id="erp-reference-video-style">
.erp-reference-videos{display:flex!important;flex-wrap:wrap!important;gap:16px!important;align-items:flex-start!important;margin:4px 0!important}.erp-reference-video{width:300px!important;max-width:100%!important;margin:0!important}.erp-reference-video video{display:block!important;width:300px!important;max-width:100%!important;height:auto!important;max-height:400px!important;border-radius:4px!important;background:#111!important;object-fit:contain!important}.erp-reference-video__foot{display:flex!important;align-items:center!important;gap:7px!important;width:300px!important;max-width:100%!important;margin-top:5px!important;font:12px/1.4 Arial,sans-serif!important}.erp-reference-video__name{min-width:0!important;overflow:hidden!important;color:#334155!important;text-overflow:ellipsis!important;white-space:nowrap!important}.erp-reference-video__download,.erp-history-video-download{display:inline-block!important;flex:0 0 auto!important;border-radius:4px!important;padding:3px 7px!important;background:#1686c4!important;color:#fff!important;font:11px/1.4 Arial,sans-serif!important;text-decoration:none!important}.erp-reference-video__download:hover,.erp-history-video-download:hover{background:#0f6e9f!important}.erp-reference-video__size{color:#94a3b8!important;font-size:10px!important}.erp-history-video-download{margin:5px 0 12px!important}.erp-history-video-name{display:block!important;width:100%!important;margin-top:5px!important;color:#334155!important;font:12px/1.4 Arial,sans-serif!important}
</style>
<script>
(function(){
    var videos = @json($videos);
    var historicalProductCode = @json($historicalProductCode);
    var historicalFallbackDate = @json($historicalFallbackDate);
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
    function card(video){
        var article=document.createElement('div');article.className='erp-reference-video';
        var player=document.createElement('video');player.controls=true;player.preload='metadata';player.src=video.preview_url;
        var foot=document.createElement('div');foot.className='erp-reference-video__foot';
        var info=document.createElement('span');info.className='erp-reference-video__name';info.title=video.name;info.textContent=video.name;
        var size=document.createElement('span');size.className='erp-reference-video__size';size.textContent=video.size;
        var download=document.createElement('a');download.className='erp-reference-video__download';download.href=video.download_url;download.textContent='下载';
        foot.appendChild(info);foot.appendChild(size);foot.appendChild(download);article.appendChild(player);article.appendChild(foot);return article;
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
        Object.keys(labels).forEach(function(type){
            var label=labelForType(type);if(!label)return;
            var target=targetBox(label),players=target.querySelectorAll('video');
            Array.prototype.forEach.call(players,function(player){
                if(player.closest&&player.closest('.erp-reference-video'))return;
                sequence++;
                var figure=player.closest&&player.closest('figure'),caption=figure&&figure.querySelector('figcaption');
                var oldName=caption?caption.textContent:'';
                var match=String(oldName).match(/\.([A-Za-z0-9]{2,5})\s*$/),extension=match?match[1].toLowerCase():'mp4';
                var fileName=prefix+'-'+String(sequence).padStart(3,'0')+'.'+extension;
                if(caption){caption.textContent=fileName;caption.classList.add('erp-history-video-name');}
                var link=document.createElement('a');link.className='erp-history-video-download';link.href=player.currentSrc||player.src;link.download=fileName;link.textContent='下载';
                if(caption)caption.insertAdjacentElement('afterend',link);else if(figure)figure.appendChild(link);
            });
        });
    }
    function render(){
        normalizeHistoricalVideos();
        Object.keys(labels).forEach(function(type){
            var items=videos.filter(function(video){return video.type===type;});if(!items.length)return;
            var label=labelForType(type);if(!label)return;
            var target=targetBox(label),box=target.querySelector('.erp-reference-videos[data-type="'+type+'"]');
            if(!box){box=document.createElement('div');box.className='erp-reference-videos';box.setAttribute('data-type',type);target.appendChild(box);}
            items.forEach(function(video){box.appendChild(card(video));});
        });
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render);else render();
})();
</script>
