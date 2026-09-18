<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/*
 * 【为什么要显式 require】本文件是全局命名空间的旧式 action widget，
 * 由路由直接实例化；而日志实现放在 Plugin.php 的 Plugin 类里。
 * 路由这条路径**不保证** Plugin.php 已经加载 —— 不 require 就会
 * 「Class not found」→ 接口 500（实测踩过）。
 * class_exists(..., false) 不触发自动加载，已加载时零开销。
 */
if (!class_exists('TypechoPlugin\\AttachPlus2\\Plugin', false)) {
    require_once __DIR__ . '/Plugin.php';
}

/**
 * AttachPlus2 后台动作
 *
 * 旧式命名的 action widget（保持类名不变，路由依赖它）。
 * 注意：本文件不再注册任何全局 error / exception / shutdown handler，
 * 也不再自行 ob_start()，一律通过 Typecho 自身的响应机制输出 JSON。
 */
class AttachPlus2_Action extends Typecho_Widget
{
    /** 生成/校验 CSRF token 的后缀（Plugin::render() 与 Plugin::config() 必须一致） */
    public const TOKEN_SUFFIX = 'AttachPlus2';

    /** @deprecated 兼容旧引用：日志实现已统一到 Plugin::log() */
    public const LOG_MAX_BYTES = 524288;

    private $sec;
    private $usr;

    public function execute()
    {
        $this->sec = Typecho_Widget::widget('Widget_Security');
        $this->usr = Typecho_Widget::widget('Widget_User');

        // 致命错误兜底 + 一条入口日志（带 do / 来源 IP / 方法，方便对账）
        \TypechoPlugin\AttachPlus2\Plugin::logRegisterFatal();
        \TypechoPlugin\AttachPlus2\Plugin::log('action', '进入附件接口', array(
            'uid'    => $this->usr->uid ?? 'null',
            'do'     => (string) $this->request->get('do', ''),
            // Typecho 的 Request 没有 getRequestMethod()，只有 isPost()/isGet()
            'method' => $this->request->isPost() ? 'POST' : ($this->request->isGet() ? 'GET' : 'OTHER'),
            'ip'     => (string) $this->request->getIp(),
        ));
    }

    /**
     * 兼容旧调用：转交 Plugin::log()
     *
     * @param string $msg
     * @param array  $kv
     * @return void
     */
    protected static function muLog($msg, array $kv = array())
    {
        \TypechoPlugin\AttachPlus2\Plugin::log('action', $msg, $kv);
    }

    /**
     * 输出成功 JSON（保持 JS 约定的 success=true 结构）
     */
    private function jsonOk(array $data, $status = 200)
    {
        $this->response->setStatus(intval($status))
            ->throwJson(array_merge(['success' => true], $data));
    }

    /**
     * 输出失败 JSON（保持 JS 约定的 success=false / message 结构）
     */
    private function jsonFail($message, $status = 200)
    {
        $this->response->setStatus(intval($status))
            ->throwJson(['success' => false, 'message' => $message]);
    }

    /**
     * 校验 CSRF token
     *
     * token 由 Plugin::render() / Plugin::config() 用 getToken(TOKEN_SUFFIX) 生成；
     * 这里同时接受针对当前请求 URL 或 Referer 生成的 token（与主题 ajax_comment.php 同思路），
     * 只要其中之一 hash_equals 通过即视为合法。
     */
    private function checkToken()
    {
        $token = (string) $this->request->get('_', '');
        if ($token === '') {
            return false;
        }

        $suffixes = [self::TOKEN_SUFFIX];
        $referer = $this->request->getReferer();
        if (!empty($referer)) {
            $suffixes[] = $referer;
        }
        $suffixes[] = $this->request->getRequestUrl();

        foreach ($suffixes as $suffix) {
            if (hash_equals($this->sec->getToken($suffix), $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 要求已登录且具备 contributor 权限
     */
    private function requireContributor()
    {
        if (!$this->usr->hasLogin()) {
            $this->jsonFail('请先登录', 401);
        }
        if (!$this->usr->pass('contributor', true)) {
            $this->jsonFail('权限不足', 403);
        }
    }

    /**
     * 解析附件 text 字段（JSON 或旧式 serialize），失败返回空数组
     */
    private static function parseMeta($text)
    {
        if (!is_string($text) || $text === '') {
            return [];
        }

        if ($text[0] === '{') {
            $meta = json_decode($text, true);
        } else {
            $meta = @unserialize($text, ['allowed_classes' => false]);
        }

        return is_array($meta) ? $meta : [];
    }

    public function upload()
    {
        $this->requireContributor();
        if (!$this->checkToken()) {
            $this->jsonFail('非法请求', 403);
        }

        // 调试连通性测试：不再泄露 PHP 版本
        $this->request->get('ping', null, $pingExists);
        if ($pingExists) {
            $this->jsonOk(['ping' => 'ok']);
        }

        if (!$this->request->isPost()) {
            $this->jsonFail('请求方法不被允许', 405);
        }

        self::muLog('upload() 开始', array(
            'name' => isset($_FILES['file']['name']) ? $_FILES['file']['name'] : '',
            'size' => isset($_FILES['file']['size']) ? intval($_FILES['file']['size']) : 0,
            'cid'  => intval($this->request->get('cid', 0)),
        ));

        if (!isset($_FILES['file'])) {
            $this->jsonFail('未收到文件', 400);
        }

        $file = $_FILES['file'];

        // 本端点只处理单个文件
        if (is_array($file['name'])) {
            $this->jsonFail('一次只能上传一个文件', 400);
        }

        if (intval($file['error']) !== UPLOAD_ERR_OK) {
            $this->jsonFail('上传错误码: ' . intval($file['error']), 400);
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->jsonFail('非法上传', 400);
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('AttachPlus2');
        $multiFormat = intval($pluginOptions->multiFormat ?? 0);

        // 服务端大小限制（MB -> bytes）
        $maxSize = intval($pluginOptions->maxSize ?? 10);
        if ($maxSize <= 0) {
            $maxSize = 10;
        }
        $maxBytes = $maxSize * 1024 * 1024;

        $size = intval($file['size'] ?? 0);
        if ($size <= 0) {
            $size = intval(@filesize($file['tmp_name']));
        }
        if ($size > $maxBytes) {
            $this->jsonFail('文件超过大小限制（最大 ' . $maxSize . 'MB）', 400);
        }

        // 服务端数量限制
        $maxFiles = intval($pluginOptions->maxFiles ?? 20);
        if ($maxFiles <= 0) {
            $maxFiles = 20;
        }

        $cid = intval($this->request->get('cid', 0));
        $db = Typecho_Db::get();

        // 关联到已有内容时，要求对该内容有编辑权限（与核心 Widget\Upload 语义一致）
        if ($cid > 0) {
            $owner = $db->fetchRow(
                $db->select('authorId')->from('table.contents')
                    ->where('cid = ?', $cid)->limit(1)
            );
            if (!$owner) {
                $this->jsonFail('目标内容不存在', 404);
            }
            if (!$this->usr->pass('editor', true) && $owner['authorId'] != $this->usr->uid) {
                $this->jsonFail('无权向该内容上传附件', 403);
            }
        }

        // 扩展名白名单
        $allowedExts = self::allowedExtensions((bool) $multiFormat);
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            $this->jsonFail('不支持的扩展名: ' . ($ext === '' ? '(无)' : $ext), 400);
        }

        // 真实 MIME 复核（不信任客户端 $file['type']）
        $realMime = Typecho_Common::mimeContentType($file['tmp_name']);
        if (!self::mimeAllowed($ext, $realMime)) {
            $this->jsonFail('文件内容与扩展名不匹配: ' . ($realMime ?: 'unknown'), 400);
        }

        // 同一用户短时间内的上传数量上限
        if ($cid > 0) {
            $window = time() - 600;
            $condition = $db->select(['COUNT(cid)' => 'num'])->from('table.contents')
                ->where('type = ?', 'attachment')
                ->where('parent = ?', $cid)
                ->where('authorId = ?', $this->usr->uid)
                ->where('created > ?', $window);
        } else {
            $window = time() - 1800;
            $condition = $db->select(['COUNT(cid)' => 'num'])->from('table.contents')
                ->where('type = ?', 'attachment')
                ->where('parent = ?', 0)
                ->where('authorId = ?', $this->usr->uid)
                ->where('created > ?', $window);
        }
        $countRow = $db->fetchObject($condition);
        $currentCount = $countRow ? intval($countRow->num) : 0;
        if ($currentCount >= $maxFiles) {
            $this->jsonFail('短时间内上传数量已达上限（' . $maxFiles . ' 个）', 400);
        }

        try {
            $result = $this->handleUpload($file, $ext, $realMime, $size, $cid);
            self::muLog('上传成功', array(
                'att'  => $result['cid'],
                'name' => isset($result['name']) ? $result['name'] : '',
                'type' => isset($result['type']) ? $result['type'] : '',
            ));
            $this->jsonOk([
                'url' => $result['url'],
                'cid' => $result['cid'],
                'name' => $result['name'],
                'type' => $result['type']
            ]);
        } catch (\Throwable $e) {
            // 关键：异常要记「哪一行」，只记 message 根本没法定位
            \TypechoPlugin\AttachPlus2\Plugin::logError('action', '上传失败', $e, array(
                'ext'  => isset($ext) ? $ext : '',
                'size' => isset($size) ? $size : 0,
            ));
            $this->jsonFail($e->getMessage(), 500);
        }
    }

    /**
     * 当前模式下允许的扩展名（危险的可同源渲染类型已全部移除）
     */
    private static function allowedExtensions($multiFormat)
    {
        if (!$multiFormat) {
            return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
        }

        return [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif',
            'mp4', 'webm', 'ogv', 'mov',
            'mp3', 'wav', 'ogg',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip', 'rar', '7z',
            'txt', 'md', 'json'
        ];
    }

    /**
     * 扩展名 -> 允许的真实 MIME 列表
     */
    private static function mimeAllowed($ext, $mime)
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime === '') {
            return false;
        }

        $map = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'avif' => ['image/avif'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
            'ogv' => ['video/ogg'],
            'mov' => ['video/quicktime'],
            'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
            'wav' => ['audio/x-wav', 'audio/wav', 'audio/wave'],
            'ogg' => ['audio/ogg', 'application/ogg', 'video/ogg'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/octet-stream'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
            'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed', 'application/octet-stream'],
            '7z' => ['application/x-7z-compressed', 'application/octet-stream'],
            'txt' => ['text/plain'],
            'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
            'json' => ['application/json', 'text/plain']
        ];

        return isset($map[$ext]) && in_array($mime, $map[$ext], true);
    }

    private function handleUpload($file, $ext, $mime, $size, $cid)
    {
        $t0 = microtime(true);
        self::muLog('开始落盘', array('ext' => $ext, 'mime' => $mime, 'size' => $size, 'cid' => $cid));

        $options = Typecho_Widget::widget('Widget_Options');

        // 遵循核心上传目录常量，默认 /usr/uploads
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
        $uploadRoot = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $uploadUrl = defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : $options->siteUrl;

        $date = new \Typecho\Date();
        $relativeDir = rtrim($uploadDir, '/\\') . '/' . $date->year . '/' . $date->month;
        $realPath = Typecho_Common::url($relativeDir, $uploadRoot);

        // 目标目录只在出错时才值得记（下面 mkdir 失败会带上它）

        if (!is_dir($realPath)) {
            if (!@mkdir($realPath, 0755, true) && !is_dir($realPath)) {
                throw new \Exception('创建目录失败: ' . $realPath);
            }
        }

        if (!is_writable($realPath)) {
            throw new \Exception('目录不可写: ' . $realPath);
        }

        $filename = uniqid() . '.' . $ext;
        $filepath = rtrim($realPath, '/\\') . '/' . $filename;



        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new \Exception('保存失败');
        }

        $fileRelativePath = $relativeDir . '/' . $filename;
        $url = Typecho_Common::url($fileRelativePath, $uploadUrl);

        if (empty($mime)) {
            $mime = 'application/octet-stream';
        }

        self::muLog('文件已落盘', array(
            'path' => $fileRelativePath,
            'mime' => $mime,
            'size' => $size,
            'ms'   => round((microtime(true) - $t0) * 1000, 1),
        ));

        // 判断文件类型分类
        $uploadedType = 'other';
        if (strpos($mime, 'image/') === 0) {
            $uploadedType = 'image';
        } elseif (strpos($mime, 'video/') === 0) {
            $uploadedType = 'video';
        } elseif (strpos($mime, 'audio/') === 0) {
            $uploadedType = 'audio';
        }

        // 与核心 Upload::uploadHandle() 一致的 JSON 元数据结构
        $attachmentMeta = [
            'name' => $file['name'],
            'path' => $fileRelativePath,
            'size' => $size,
            'type' => $ext,
            'mime' => $mime
        ];

        $text = json_encode($attachmentMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $db = Typecho_Db::get();

        $contents = [
            'title' => htmlspecialchars($file['name']),
            'slug' => $filename,
            'created' => $options->gmtTime,
            'modified' => $options->gmtTime,
            'text' => $text,
            'authorId' => $this->usr->uid,
            'type' => 'attachment',
            'status' => 'publish',
            'commentsNum' => 0,
            'allowComment' => 1,
            'allowPing' => 0,
            'allowFeed' => 1
        ];

        if ($cid > 0) {
            $contents['parent'] = $cid;
            self::muLog('关联到已有文章', array('parent' => $cid));
        } else {
            $contents['parent'] = 0;
            self::muLog('新文章：先 parent=0，保存后由钩子关联');
        }

        $insertId = $db->query($db->insert('table.contents')->rows($contents));
        self::muLog('附件入库完成', array('att' => $insertId, 'parent' => $cid, 'type' => $uploadedType));

        // 新文章：Session 精确记账，保存文章时由钩子绑定
        if ($cid == 0 && $insertId > 0) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (!isset($_SESSION['mu_pending'])) {
                $_SESSION['mu_pending'] = [];
            }
            $_SESSION['mu_pending'][] = intval($insertId);
            session_write_close();
            self::muLog('记入 session pending', array('att' => $insertId));
        }

        return [
            'url' => $url,
            'cid' => $insertId,
            'name' => $file['name'],
            'type' => $uploadedType
        ];
    }

    public function list()
    {
        $this->requireContributor();
        if (!$this->checkToken()) {
            $this->jsonFail('非法请求', 403);
        }

        $cid = intval($this->request->get('cid', 0));

        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('AttachPlus2');
        $multiFormat = intval($pluginOptions->multiFormat ?? 0);

        // 新文章页面加载时，清空旧 session 防止污染
        if ($cid == 0) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (isset($_SESSION['mu_pending'])) {
                unset($_SESSION['mu_pending']);
                session_write_close();
                self::muLog('新文章页面：清空 session pending');
            }
        }

        $db = Typecho_Db::get();

        // 获取当前文章内容（用于检测附件是否已插入正文）
        $content = '';
        if ($cid > 0) {
            $post = $db->fetchRow(
                $db->select('text', 'authorId')->from('table.contents')->where('cid = ?', $cid)->limit(1)
            );

            if (!$post) {
                $this->jsonFail('内容不存在', 404);
            }

            // 仅允许查看自己有权编辑的内容的附件
            if (!$this->usr->pass('editor', true) && $post['authorId'] != $this->usr->uid) {
                $this->jsonFail('无权查看该内容的附件', 403);
            }

            $content = $post['text'] ?? '';
        }

        if ($cid > 0) {
            // 已有文章：获取关联到该文章的附件（带 LIMIT）
            $rows = $db->fetchAll(
                $db->select()->from('table.contents')
                    ->where('type = ?', 'attachment')
                    ->where('parent = ?', $cid)
                    ->order('created', Typecho_Db::SORT_DESC)
                    ->limit(200)
            );
        } else {
            // 新文章：获取当前用户最近30分钟内的未关联附件
            $timeWindow = time() - 1800;
            $rows = $db->fetchAll(
                $db->select()->from('table.contents')
                    ->where('type = ?', 'attachment')
                    ->where('parent = ?', 0)
                    ->where('authorId = ?', $this->usr->uid)
                    ->where('created > ?', $timeWindow)
                    ->order('created', Typecho_Db::SORT_DESC)
                    ->limit(50)
            );
        }

        $files = [];
        foreach ($rows as $row) {
            $meta = self::parseMeta($row['text']);

            if (isset($meta['path'])) {
                $url = Typecho_Common::url($meta['path'], $options->siteUrl);
                $inserted = false;
                if (!empty($content)) {
                    // 检查附件URL或相对路径是否出现在文章正文中
                    $inserted = (strpos($content, $url) !== false) || (strpos($content, $meta['path']) !== false);
                }

                // 判断文件类型
                $ext = strtolower($meta['type'] ?? '');
                $mime = strtolower($meta['mime'] ?? '');
                $fileType = 'other';
                if (strpos($mime, 'image/') === 0 || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
                    $fileType = 'image';
                } elseif (strpos($mime, 'video/') === 0 || in_array($ext, ['mp4', 'webm', 'ogv', 'mov'])) {
                    $fileType = 'video';
                } elseif (strpos($mime, 'audio/') === 0 || in_array($ext, ['mp3', 'wav', 'ogg'])) {
                    $fileType = 'audio';
                }

                // 多格式关闭时，只显示图片
                if (!$multiFormat && $fileType !== 'image') {
                    continue;
                }

                $files[] = [
                    'cid' => $row['cid'],
                    'name' => $meta['name'] ?? $row['title'],
                    'url' => $url,
                    'size' => $meta['size'] ?? 0,
                    'type' => $fileType,
                    'ext' => $ext,
                    'inserted' => $inserted
                ];
            }
        }

        $this->jsonOk(['files' => $files]);
    }

    public function attach()
    {
        $do = (string) $this->request->get('do', '');

        $this->requireContributor();
        if (!$this->checkToken()) {
            $this->jsonFail('非法请求', 403);
        }
        if (!$this->request->isPost()) {
            $this->jsonFail('请求方法不被允许', 405);
        }

        if ($do === 'delete') {
            $cid = intval($this->request->get('cid', 0));
            if ($cid <= 0) {
                $this->jsonFail('参数错误', 400);
            }

            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('cid', 'type', 'text', 'authorId')
                    ->from('table.contents')
                    ->where('cid = ?', $cid)
                    ->limit(1)
            );

            // 目标必须真的是附件
            if (!$row || $row['type'] !== 'attachment') {
                $this->jsonFail('附件不存在', 404);
            }

            // 归属校验，镜像 Widget\Base\Contents::isWriteable()
            if (!$this->usr->pass('editor', true) && $row['authorId'] != $this->usr->uid) {
                $this->jsonFail('无权删除该附件', 403);
            }

            $meta = self::parseMeta($row['text']);
            self::deleteUploadFile($meta['path'] ?? '');

            $db->query($db->delete('table.contents')->where('cid = ?', $cid));
            self::muLog('删除附件', array('att' => $cid));

            $this->jsonOk(['cid' => $cid]);
        }

        // 清除日志（仅管理员 + POST + CSRF）
        if ($do === 'clearLog') {
            if (!$this->usr->pass('administrator', true)) {
                $this->jsonFail('需要管理员权限', 403);
            }

            $logFile = __DIR__ . '/fatal_debug.log';
            if (is_file($logFile)) {
                @unlink($logFile);
            }

            $this->response->redirect(
                Typecho_Common::url('/options-plugin.php?config=AttachPlus2', Widget_Options::alloc()->adminUrl)
            );
        }

        $this->jsonOk([]);
    }

    /**
     * 删除物理文件：realpath 解析后必须位于上传目录内，否则拒绝
     */
    private static function deleteUploadFile($path)
    {
        if (!is_string($path) || $path === '') {
            return;
        }

        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
        $uploadRoot = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;

        $base = realpath(rtrim($uploadRoot, '/\\') . '/' . ltrim($uploadDir, '/\\'));
        $target = realpath(rtrim($uploadRoot, '/\\') . '/' . ltrim($path, '/\\'));

        // 无法解析的一律不删除
        if (false === $base || false === $target || !is_file($target)) {
            return;
        }

        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($target, $base) !== 0) {
            return;
        }

        @unlink($target);
    }
}
