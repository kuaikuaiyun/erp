(function (window) {
    'use strict';

    function wrapText(context, value, maxWidth) {
        var text = String(value == null ? '' : value);
        if (!text) return ['—'];
        var lines = [];
        text.split('\n').forEach(function (paragraph) {
            var line = '';
            Array.from(paragraph).forEach(function (character) {
                var candidate = line + character;
                if (line && context.measureText(candidate).width > maxWidth) {
                    lines.push(line);
                    line = character;
                } else {
                    line = candidate;
                }
            });
            lines.push(line || ' ');
        });
        return lines;
    }

    function download(options) {
        var button = options.button;
        if (!button || button.disabled) return;
        var label = button.querySelector('span');
        var original = label ? label.textContent : button.textContent;
        button.disabled = true;
        if (label) label.textContent = '正在生成...';

        window.setTimeout(function () {
            try {
                var headers = options.headers || [];
                var rows = options.rows && options.rows.length ? options.rows : [headers.map(function (_, index) { return index === 0 ? '暂无数据' : ''; })];
                var widths = options.widths || headers.map(function () { return 140; });
                var padding = 12, lineHeight = 24, fontSize = 16;
                var tableWidth = widths.reduce(function (sum, width) { return sum + width; }, 0);
                var canvas = document.createElement('canvas');
                var context = canvas.getContext('2d');
                context.font = fontSize + 'px "Microsoft YaHei", Arial, sans-serif';
                var wrappedRows = rows.map(function (row) {
                    return row.map(function (cell, index) { return wrapText(context, cell, widths[index] - padding * 2); });
                });
                var rowHeights = wrappedRows.map(function (row) {
                    return Math.max(42, Math.max.apply(null, row.map(function (lines) { return lines.length; })) * lineHeight + padding * 2);
                });
                var titleHeight = 116;
                var tableHeight = 48 + rowHeights.reduce(function (sum, height) { return sum + height; }, 0);
                var scale = Math.min(2, 30000 / Math.max(tableWidth, titleHeight + tableHeight));
                canvas.width = Math.ceil(tableWidth * scale);
                canvas.height = Math.ceil((titleHeight + tableHeight) * scale);
                context.scale(scale, scale);
                context.fillStyle = '#f5f9fc'; context.fillRect(0, 0, tableWidth, titleHeight + tableHeight);
                context.fillStyle = '#173f5d'; context.font = 'bold 27px "Microsoft YaHei", Arial'; context.fillText(options.title || '报表', 20, 36);
                context.fillStyle = '#58758a'; context.font = '14px "Microsoft YaHei", Arial'; context.fillText(options.subtitle || '', 20, 65);
                context.fillStyle = '#245a78'; context.font = 'bold 15px "Microsoft YaHei", Arial'; context.fillText(options.summary || '', 20, 94);
                var y = titleHeight, x = 0;
                headers.forEach(function (header, index) {
                    context.fillStyle = '#d7eaf5'; context.fillRect(x, y, widths[index], 48);
                    context.strokeStyle = '#b7cfdd'; context.strokeRect(x, y, widths[index], 48);
                    context.fillStyle = '#214f6d'; context.font = 'bold 15px "Microsoft YaHei", Arial';
                    context.fillText(header, x + padding, y + 30);
                    x += widths[index];
                });
                y += 48;
                wrappedRows.forEach(function (row, rowIndex) {
                    x = 0;
                    row.forEach(function (lines, columnIndex) {
                        context.fillStyle = rowIndex % 2 ? '#fff8ef' : '#f3f9fc'; context.fillRect(x, y, widths[columnIndex], rowHeights[rowIndex]);
                        context.strokeStyle = '#c6d9e4'; context.strokeRect(x, y, widths[columnIndex], rowHeights[rowIndex]);
                        context.fillStyle = '#263f50'; context.font = '14px "Microsoft YaHei", Arial';
                        lines.forEach(function (line, lineIndex) { context.fillText(line, x + padding, y + padding + 17 + lineIndex * lineHeight); });
                        x += widths[columnIndex];
                    });
                    y += rowHeights[rowIndex];
                });
                var link = document.createElement('a');
                link.download = options.filename || 'report.png';
                link.href = canvas.toDataURL('image/png', 1);
                document.body.appendChild(link); link.click(); link.remove();
            } catch (error) {
                window.alert('生成图片失败，请稍后重试');
            } finally {
                button.disabled = false;
                if (label) label.textContent = original;
            }
        }, 20);
    }

    window.ReportTableImageExport = { download: download };
})(window);
