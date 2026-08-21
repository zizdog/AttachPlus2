/**
 * AttachPlus2 JavaScript
 * 批量附件上传插件前端逻辑 - 支持图片/视频/音频/文档等多格式
 */

(function() {
    var config = window.MultiUploadConfig || {};
    var multiFormat = config.multiFormat === 1;
    
    // ===== 调试系统（仅在 debugMode=1 时启用）=====
    var debugMode = config.debugMode === 1;
    var debugBody, debugPanel, debugBadge, debugClose, debugStatus, debugCount;
    var logs = [];
    var logCount = 0;
    
    function log(level, msg, data) {
        if (!debugMode) return;
        
        var entry = { time: new Date().toLocaleTimeString(), level: level, msg: msg, data: data };
        logs.push(entry);
        logCount++;
        
        var colors = { ok: '#4ec9b0', err: '#f48771', warn: '#dcdcaa', info: '#9cdcfe' };
        var color = colors[level] || '#d4d4d4';
        var line = '<span style="color:' + color + '">[' + entry.time + '][' + level.toUpperCase() + '] ' + escapeHtml(msg) + '</span>';
        if (data) {
            line += '\n  <span style="color:#ce9178">→ ' + escapeHtml(JSON.stringify(data, null, 2)) + '</span>';
        }
        
        if (debugBody) {
            debugBody.innerHTML = debugBody.innerHTML ? debugBody.innerHTML + '\n' + line : line;
            debugBody.scrollTop = debugBody.scrollHeight;
            debugCount.textContent = logCount + ' 条日志';
            debugStatus.textContent = '运行中';
            debugStatus.style.color = level === 'err' ? '#f48771' : level === 'warn' ? '#dcdcaa' : '#4ec9b0';
        }
        
        console[level === 'err' ? 'error' : level === 'warn' ? 'warn' : 'log']('[AttachPlus2]', msg, data);
    }
    
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // ===== 调试面板初始化 =====
    if (debugMode) {
        debugBody = document.getElementById('mu-debug-body');
        debugPanel = document.getElementById('mu-debug-panel');
        debugBadge = document.getElementById('mu-debug-badge');
        debugClose = document.getElementById('mu-debug-close');
        debugStatus = document.getElementById('mu-debug-status');
        debugCount = document.getElementById('mu-debug-count');
        
        if (debugBadge && debugPanel) {
            debugBadge.addEventListener('click', function() {
                debugPanel.classList.toggle('active');
                log('info', '调试面板 ' + (debugPanel.classList.contains('active') ? '打开' : '关闭'));
            });
            
            debugClose.addEventListener('click', function() {
                debugPanel.classList.remove('active');
            });
            
            // 一键复制日志
            var copyBtn = document.getElementById('mu-debug-copy');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var text = debugBody.innerText;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            log('ok', '日志已复制到剪贴板');
                        }).catch(function() {
                            fallbackCopy(text);
                        });
                    } else {
                        fallbackCopy(text);
                    }
                });
            }
            
            function fallbackCopy(text) {
                var textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    log('ok', '日志已复制到剪贴板');
                } catch (e) {
                    log('err', '复制失败', { error: e.message });
                }
                document.body.removeChild(textarea);
            }
        }
        
        log('info', '初始化完成', { cid: config.cid, maxSize: config.maxSize, maxFiles: config.maxFiles, multiFormat: multiFormat });
        
        // 路由连通性测试
        function testRoute() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', config.ajaxUrl + '?ping=1&t=' + Date.now());
            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        log('ok', '路由测试通过', { php: res.php });
                    } catch (e) {
                        log('err', '路由测试解析失败');
                    }
                } else {
                    log('err', '路由测试失败', { status: xhr.status });
                }
            });
            xhr.addEventListener('error', function() {
                log('err', '路由测试网络错误');
            });
            xhr.send();
        }
        testRoute();
    }
    
    // ===== 主功能 =====
    var dropzone = document.getElementById('mu-dropzone');
    var fileInput = document.getElementById('mu-file-input');
    var gallery = document.getElementById('mu-gallery');
    var insertBtn = document.getElementById('mu-insert-btn');
    var selectAll = document.getElementById('mu-select-all');
    var countEl = document.getElementById('mu-count');
    var failNotice = document.getElementById('mu-fail-notice');
    var items = [];
    var selectedItems = [];
    var uploadQueue = [];
    var failedQueue = [];
    var activeUploads = 0;
    var autoRetryDone = false;
    var MAX_CONCURRENT = 3;
    
    function loadExistingFiles() {
        if (!config.listUrl) return;
        log('info', '加载已有附件, cid=' + config.cid);
        
        var xhr = new XMLHttpRequest();
        xhr.open('GET', config.listUrl + '?cid=' + config.cid + '&t=' + Date.now());
        xhr.addEventListener('load', function() {
            if (xhr.status !== 200) {
                log('err', '加载已有附件失败', { status: xhr.status });
                return;
            }
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.files && res.files.length > 0) {
                    gallery.querySelectorAll('.mu-group-items').forEach(function(c) {
                        c.innerHTML = '';
                    });
                    items = [];
                    res.files.forEach(function(file) {
                        addExistingItem(file);
                    });
                    updateGroupVisibility();
                    updateCount();
                    log('ok', '加载已有附件完成', { count: res.files.length });
                } else {
                    log('info', '没有已有附件需要加载');
                    updateGroupVisibility();
                }
            } catch (e) {
                log('err', '解析已有附件失败', { error: e.message });
            }
        });
        xhr.addEventListener('error', function() {
            log('err', '加载已有附件网络错误');
        });
        xhr.send();
    }
    
    // 添加已有附件项到面板
    function addExistingItem(file) {
        var el = document.createElement('div');
        el.className = 'mu-item mu-done' + (file.inserted ? ' mu-inserted' : '');
        el.dataset.url = file.url;
        el.dataset.cid = file.cid;
        el.dataset.fileType = file.type || 'image';
        
        if (file.type === 'image') {
            var img = document.createElement('img');
            img.src = file.url;
            el.appendChild(img);
        } else if (file.type === 'video') {
            var videoIcon = document.createElement('div');
            videoIcon.className = 'mu-file-icon';
            videoIcon.innerHTML = '🎬';
            el.appendChild(videoIcon);
            var videoLabel = document.createElement('div');
            videoLabel.className = 'mu-file-label';
            videoLabel.textContent = file.ext ? file.ext.toUpperCase() : 'VIDEO';
            el.appendChild(videoLabel);
        } else if (file.type === 'audio') {
            var audioIcon = document.createElement('div');
            audioIcon.className = 'mu-file-icon';
            audioIcon.innerHTML = '🎵';
            el.appendChild(audioIcon);
            var audioLabel = document.createElement('div');
            audioLabel.className = 'mu-file-label';
            audioLabel.textContent = file.ext ? file.ext.toUpperCase() : 'AUDIO';
            el.appendChild(audioLabel);
        } else {
            var fileIcon = document.createElement('div');
            fileIcon.className = 'mu-file-icon';
            fileIcon.innerHTML = '📄';
            el.appendChild(fileIcon);
            var fileLabel = document.createElement('div');
            fileLabel.className = 'mu-file-label';
            fileLabel.textContent = file.ext ? file.ext.toUpperCase() : 'FILE';
            el.appendChild(fileLabel);
        }
        
        var check = document.createElement('div');
        check.className = 'mu-check';
        el.appendChild(check);
        
        var nameEl = document.createElement('div');
        nameEl.className = 'mu-name';
        nameEl.textContent = file.name;
        el.appendChild(nameEl);
        
        // 已插入标记
        if (file.inserted) {
            var insertedBadge = document.createElement('div');
            insertedBadge.className = 'mu-inserted-badge';
            insertedBadge.textContent = '已用';
            el.appendChild(insertedBadge);
        }
        
        var remove = document.createElement('button');
        remove.className = 'mu-remove';
        remove.innerHTML = '×';
        remove.addEventListener('click', function(e) {
            e.stopPropagation();
            if (confirm('确定删除此附件？')) {
                deleteAttachment(file.cid, el);
            }
        });
        el.appendChild(remove);
        
        el.addEventListener('click', function(e) {
            if (e.target.classList.contains('mu-remove')) return;
            toggleSelect(el);
        });
        
        var groupItems = getGroupItems(file.type || 'image');
        if (groupItems) {
            groupItems.insertBefore(el, groupItems.firstChild);
        } else {
            gallery.insertBefore(el, gallery.querySelector('.mu-empty'));
        }
        items.push({ el: el, url: file.url, cid: file.cid, name: file.name, type: file.type || 'image' });
    }
    
    loadExistingFiles();
    
    function getGroupItems(type) {
        var group = gallery.querySelector('.mu-group[data-group="' + type + '"]');
        if (group) return group.querySelector('.mu-group-items');
        return null;
    }
    
    function updateGroupVisibility() {
        var groups = gallery.querySelectorAll('.mu-group');
        var anyVisible = false;
        groups.forEach(function(g) {
            var items = g.querySelector('.mu-group-items');
            var hasItems = items && items.children.length > 0;
            g.style.display = hasItems ? '' : 'none';
            if (hasItems) anyVisible = true;
        });
        var empty = gallery.querySelector('.mu-empty');
        if (empty) {
            empty.style.display = anyVisible ? 'none' : '';
        }
    }
    
    dropzone.addEventListener('click', function() {
        fileInput.click();
    });
    
    fileInput.addEventListener('change', function(e) {
        handleFiles(e.target.files);
        this.value = '';
    });
    
    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    
    dropzone.addEventListener('dragleave', function() {
        this.classList.remove('dragover');
    });
    
    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    
    function handleFiles(files) {
        var validFiles = Array.from(files).filter(function(f) {
            if (multiFormat) {
                return true; // 多格式模式接受所有文件
            }
            // 部分浏览器对 avif 等新格式返回空 MIME，用扩展名兜底
            var ext = (f.name.split('.').pop() || '').toLowerCase();
            return f.type.match(/^image\//) || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'].indexOf(ext) > -1;
        });
        log('info', '选择文件', { total: files.length, valid: validFiles.length });
        if (validFiles.length === 0) {
            log('warn', multiFormat ? '无有效文件' : '无图片文件');
            return;
        }
        if (validFiles.length > config.maxFiles) {
            log('warn', '超出数量限制', config.maxFiles);
            validFiles = validFiles.slice(0, config.maxFiles);
        }
        // 重置状态
        failedQueue = [];
        autoRetryDone = false;
        hideFailNotice();
        validFiles.forEach(function(file) {
            if (file.size > config.maxSize) {
                log('err', '文件过大', { name: file.name, size: file.size });
                return;
            }
            uploadQueue.push({ file: file, retries: 0, item: null });
        });
        tryStartUpload();
    }
    
    function tryStartUpload() {
        while (activeUploads < MAX_CONCURRENT && uploadQueue.length > 0) {
            var task = uploadQueue.shift();
            activeUploads++;
            doUpload(task.file, task.retries, task.item);
        }
        
        // 批量上传全部完成后，自动重试一次失败的附件
        if (activeUploads === 0 && uploadQueue.length === 0 && failedQueue.length > 0 && !autoRetryDone) {
            autoRetryDone = true;
            var count = failedQueue.length;
            log('info', '批量上传完成，自动重试 ' + count + ' 个失败附件');
            var toRetry = failedQueue.splice(0, failedQueue.length);
            toRetry.forEach(function(f) {
                f.item.reset();
                uploadQueue.push({ file: f.file, retries: 1, item: f.item });
            });
            tryStartUpload();
        }
        
        // 最终仍有失败的，显示提示
        if (activeUploads === 0 && uploadQueue.length === 0 && failedQueue.length > 0 && autoRetryDone) {
            showFailNotice(failedQueue.length);
        }
    }
    
    function showFailNotice(count) {
        if (!failNotice) return;
        failNotice.textContent = count + '个附件上传失败（已重试），请检查上传类型或服务器配置！';
        failNotice.style.display = 'block';
        log('warn', count + '个附件上传失败（已重试），请检查上传类型或服务器配置！');
    }
    
    function hideFailNotice() {
        if (!failNotice) return;
        failNotice.style.display = 'none';
        failNotice.textContent = '';
    }
    
    function doUpload(file, retryCount, existingItem) {
        retryCount = retryCount || 0;
        var item;
        if (existingItem) {
            item = existingItem;
            item.reset();
            var groupItems = getGroupItems(item.el.dataset.fileType || 'image');
            if (groupItems && item.el.parentNode !== groupItems) {
                groupItems.insertBefore(item.el, groupItems.firstChild);
            }
        } else {
            item = createItem(file);
            var fileType = item.fileType || 'image';
            var groupItems = getGroupItems(fileType);
            if (groupItems) {
                groupItems.insertBefore(item.el, groupItems.firstChild);
            } else {
                gallery.insertBefore(item.el, gallery.querySelector('.mu-empty'));
            }
            updateGroupVisibility();
        }
        
        var formData = new FormData();
        formData.append('file', file);
        if (config.cid > 0) {
            formData.append('cid', config.cid);
        }
        
        var xhr = new XMLHttpRequest();
        var startTime = Date.now();
        
        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                item.setProgress(e.loaded / e.total);
            }
        });
        
        function onFail(msg) {
            item.error(msg);
            failedQueue.push({ file: file, item: item });
            activeUploads--;
            tryStartUpload();
        }
        
        xhr.addEventListener('load', function() {
            var elapsed = Date.now() - startTime;
            log('info', '上传完成', { status: xhr.status, elapsed: elapsed + 'ms', size: xhr.responseText.length });
            
            if (xhr.status !== 200) {
                log('err', 'HTTP错误', { status: xhr.status, response: xhr.responseText.substring(0, 200) });
                onFail('HTTP ' + xhr.status);
                return;
            }
            
            var text = xhr.responseText.trim();
            if (text.charAt(0) !== '{' && text.charAt(0) !== '[') {
                log('err', '非JSON响应', { response: text.substring(0, 300) });
                onFail('服务器返回HTML');
                return;
            }
            
            try {
                var res = JSON.parse(text);
                if (res.success) {
                    item.done(res.url, res.cid, res.name, res.type);
                    items.push({ el: item.el, url: res.url, cid: res.cid, name: res.name, type: res.type || 'image' });
                    updateCount();
                    log('ok', '上传成功', { cid: res.cid });
                } else {
                    log('err', '服务器返回错误', { message: res.message });
                    onFail(res.message || '上传失败');
                    return;
                }
            } catch (e) {
                log('err', 'JSON解析失败', { error: e.message });
                onFail('解析错误');
                return;
            }
            
            activeUploads--;
            tryStartUpload();
        });
        
        xhr.addEventListener('error', function() {
            log('err', '网络错误', { retry: retryCount });
            onFail('网络错误');
        });
        
        xhr.open('POST', config.ajaxUrl);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(formData);
        log('info', '开始上传', { name: file.name, size: file.size, retry: retryCount });
    }
    
    function createItem(file) {
        var el = document.createElement('div');
        el.className = 'mu-item';
        
        // 部分浏览器对 avif 等新格式返回空 MIME，用扩展名兜底
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        var isImage = file.type.match(/^image\//) || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'].indexOf(ext) > -1;
        var isVideo = file.type.match(/^video\//);
        var isAudio = file.type.match(/^audio\//);
        var fileType = isImage ? 'image' : (isVideo ? 'video' : (isAudio ? 'audio' : 'other'));
        el.dataset.fileType = fileType;
        
        if (isImage) {
            var img = document.createElement('img');
            var blobUrl = URL.createObjectURL(file);
            img.src = blobUrl;
            el.appendChild(img);
        } else if (isVideo) {
            var videoIcon = document.createElement('div');
            videoIcon.className = 'mu-file-icon';
            videoIcon.innerHTML = '🎬';
            el.appendChild(videoIcon);
            var videoLabel = document.createElement('div');
            videoLabel.className = 'mu-file-label';
            videoLabel.textContent = 'VIDEO';
            el.appendChild(videoLabel);
        } else if (isAudio) {
            var audioIcon = document.createElement('div');
            audioIcon.className = 'mu-file-icon';
            audioIcon.innerHTML = '🎵';
            el.appendChild(audioIcon);
            var audioLabel = document.createElement('div');
            audioLabel.className = 'mu-file-label';
            audioLabel.textContent = 'AUDIO';
            el.appendChild(audioLabel);
        } else {
            var fileIcon = document.createElement('div');
            fileIcon.className = 'mu-file-icon';
            fileIcon.innerHTML = '📄';
            el.appendChild(fileIcon);
            var fileLabel = document.createElement('div');
            fileLabel.className = 'mu-file-label';
            fileLabel.textContent = 'FILE';
            el.appendChild(fileLabel);
        }
        
        var progress = document.createElement('div');
        progress.className = 'mu-progress';
        progress.style.transform = 'scaleX(0)';
        el.appendChild(progress);
        
        var check = document.createElement('div');
        check.className = 'mu-check';
        el.appendChild(check);
        
        var nameEl = document.createElement('div');
        nameEl.className = 'mu-name';
        nameEl.textContent = file.name;
        el.appendChild(nameEl);
        
        el.addEventListener('click', function(e) {
            if (e.target.classList.contains('mu-remove')) {
                return;
            }
            toggleSelect(el);
        });
        
        return {
            el: el,
            fileType: fileType,
            setProgress: function(p) {
                progress.style.transform = 'scaleX(' + p + ')';
            },
            done: function(url, cid, name, serverType) {
                el.classList.add('mu-done');
                el.classList.remove('mu-failed');
                el.dataset.url = url;
                el.dataset.cid = cid;
                var finalType = serverType || fileType;
                el.dataset.fileType = finalType;
                
                var currentGroup = el.closest('.mu-group-items');
                var targetGroup = getGroupItems(finalType);
                if (targetGroup && currentGroup !== targetGroup) {
                    targetGroup.insertBefore(el, targetGroup.firstChild);
                }
                
                if (isImage) {
                    URL.revokeObjectURL(el.querySelector('img').src);
                    el.querySelector('img').src = url;
                }
                
                var remove = document.createElement('button');
                remove.className = 'mu-remove';
                remove.innerHTML = '×';
                remove.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (confirm('确定删除此附件？')) {
                        deleteAttachment(cid, el);
                    }
                });
                el.appendChild(remove);
            },
            reset: function() {
                el.classList.remove('mu-failed');
                el.classList.remove('mu-done');
                el.style.opacity = '';
                el.title = '';
                progress.style.background = '#467B96';
                progress.style.transform = 'scaleX(0)';
                var removeBtn = el.querySelector('.mu-remove');
                if (removeBtn) removeBtn.remove();
            },
            error: function(msg) {
                el.classList.add('mu-failed');
                el.style.opacity = '0.5';
                el.title = msg;
                progress.style.background = '#c00';
                progress.style.transform = 'scaleX(1)';
                log('err', '上传项错误', { msg: msg });
            }
        };
    }
    
    function toggleSelect(el) {
        var idx = selectedItems.indexOf(el);
        if (idx > -1) {
            selectedItems.splice(idx, 1);
            el.classList.remove('selected');
        } else {
            selectedItems.push(el);
            el.classList.add('selected');
        }
        insertBtn.disabled = selectedItems.length === 0;
        insertBtn.textContent = '插入到编辑器 (' + selectedItems.length + ')';
    }
    
    selectAll.addEventListener('change', function() {
        var done = gallery.querySelectorAll('.mu-item.mu-done');
        selectedItems = [];
        done.forEach(function(el) {
            el.classList.remove('selected');
        });
        if (this.checked) {
            done.forEach(function(el) {
                selectedItems.push(el);
                el.classList.add('selected');
            });
        }
        insertBtn.disabled = selectedItems.length === 0;
        insertBtn.textContent = '插入到编辑器 (' + selectedItems.length + ')';
    });
    
    function updateCount() {
        var done = gallery.querySelectorAll('.mu-item.mu-done').length;
        countEl.textContent = '共 ' + done + ' 个';
    }
    
    function deleteAttachment(cid, el) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', config.attachUrl + '?do=delete');
        var formData = new FormData();
        formData.append('cid', cid);
        xhr.addEventListener('load', function() {
            el.remove();
            updateGroupVisibility();
            var idx = items.findIndex(function(i) {
                return i.cid == cid;
            });
            if (idx > -1) {
                items.splice(idx, 1);
            }
            var sIdx = selectedItems.indexOf(el);
            if (sIdx > -1) {
                selectedItems.splice(sIdx, 1);
            }
            insertBtn.disabled = selectedItems.length === 0;
            updateCount();
            log('ok', '删除附件', { cid: cid });
        });
        xhr.send(formData);
    }
    
    insertBtn.addEventListener('click', function() {
        if (selectedItems.length === 0) {
            return;
        }
        var editor = document.getElementById('text');
        if (!editor) {
            return;
        }
        var md = selectedItems.map(function(el) {
            var fileType = el.dataset.fileType || 'image';
            var name = el.querySelector('.mu-name').textContent;
            var url = el.dataset.url;
            if (fileType === 'image') {
                return '![' + name + '](' + url + ')';
            } else if (fileType === 'video') {
                return '<video src="' + url + '" controls style="max-width:100%"></video>';
            } else if (fileType === 'audio') {
                return '<audio src="' + url + '" controls style="max-width:100%"></audio>';
            } else {
                return '[' + name + '](' + url + ')';
            }
        }).join('\n\n');
        var s = editor.selectionStart;
        var e = editor.selectionEnd;
        var v = editor.value;
        editor.value = v.substring(0, s) + '\n\n' + md + '\n\n' + v.substring(e);
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        
        // 标记已插入：更新UI状态
        selectedItems.forEach(function(el) {
            el.classList.remove('selected');
            if (!el.classList.contains('mu-inserted')) {
                el.classList.add('mu-inserted');
                if (!el.querySelector('.mu-inserted-badge')) {
                    var badge = document.createElement('div');
                    badge.className = 'mu-inserted-badge';
                    badge.textContent = '已用';
                    el.appendChild(badge);
                }
            }
        });
        
        selectedItems = [];
        selectAll.checked = false;
        insertBtn.disabled = true;
        insertBtn.textContent = '插入到编辑器';
        log('ok', '插入编辑器', { count: md.split('\n\n').length });
    });
})();
