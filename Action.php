<?php

// 检查是否开启调试模式
$options = \Typecho_Widget::widget('Widget_Options');
$pluginOptions = $options->plugin('AttachPlus2');
$debugMode = intval($pluginOptions->debugMode ?? 0);

// 日志函数
define('MU_LOG_FILE', __DIR__ . '/fatal_debug.log');
function muLog($msg) {
    global $debugMode;
    if (!$debugMode) return;
    $logDir = dirname(MU_LOG_FILE);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(MU_LOG_FILE, "[" . getmypid() . "] " . date('H:i:s') . " $msg\n", FILE_APPEND);
}

muLog("文件被加载, 方法=" . ($_SERVER['REQUEST_METHOD'] ?? 'none'));

// 强制 JSON 输出
ob_start();

function muDie($msg, $extra = null) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['success' => false, 'message' => $msg];
    if ($extra) $resp['extra'] = $extra;
    echo json_encode($resp);
    muLog("muDie: $msg");
    exit;
}

function muOk($data) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $data));
    muLog("muOk");
    exit;
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    muLog("Error: [$errno] $errstr at $errfile:$errline");
    muDie("PHP Error [$errno]: $errstr", ['file' => $errfile, 'line' => $errline]);
}, E_ALL);

set_exception_handler(function($e) {
    muLog("Exception: " . $e->getMessage());
    muDie('Exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
});

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR))) {
        muLog("Fatal: " . $err['message'] . " at " . $err['file'] . ":" . $err['line']);
        muDie('Fatal: ' . $err['message'], ['file' => $err['file'], 'line' => $err['line']]);
    }
});

// ping 测试
if (isset($_GET['ping'])) {
    muOk(['ping' => 'ok', 'php' => PHP_VERSION]);
}

if (!defined('__TYPECHO_ROOT_DIR__')) {
    muDie('未定义 __TYPECHO_ROOT_DIR__');
}

// 定义 Action 类
class AttachPlus2_Action extends Typecho_Widget
{
    private $sec;
    private $usr;
    
    public function execute()
    {
        muLog("execute() 开始");
        $this->sec = Typecho_Widget::widget('Widget_Security');
        $this->usr = Typecho_Widget::widget('Widget_User');
        muLog("execute() 完成, uid=" . ($this->usr->uid ?? 'null'));
    }
    
    public function upload()
    {
        muLog("upload() 开始");
        
        if (!$this->usr->pass('contributor', true)) {
            muDie('权限不足');
        }
        
        if (!isset($_FILES['file'])) {
            muDie('未收到文件');
        }
        
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            muDie('上传错误码: ' . $file['error']);
        }
        
        // 根据多格式模式决定 MIME 白名单
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('AttachPlus2');
        $multiFormat = intval($pluginOptions->multiFormat ?? 0);
        
        if ($multiFormat) {
            // 多格式模式：接受所有常见类型
            $allowed = [
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml',
                'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
                'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/webm',
                'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/x-7z-compressed',
                'text/plain', 'text/markdown', 'text/html', 'text/css', 'text/javascript',
                'application/json', 'application/xml'
            ];
        } else {
            // 图片模式
            $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        }
        
        if (!in_array($file['type'], $allowed)) {
            muDie('不支持的类型: ' . $file['type']);
        }
        
        try {
            $result = $this->handleUpload($file, $multiFormat);
            muLog("上传成功, cid=" . $result['cid']);
            muOk([
                'url' => $result['url'],
                'cid' => $result['cid'],
                'name' => $result['name'],
                'type' => $result['type']
            ]);
        } catch (\Exception $e) {
            muLog("上传异常: " . $e->getMessage());
            muDie($e->getMessage());
        }
    }
    
    private function handleUpload($file, $multiFormat)
    {
        muLog("handleUpload() 开始");
        
        $options = Typecho_Widget::widget('Widget_Options');
        $uploadDir = 'usr/uploads';
        $uploadRoot = __TYPECHO_ROOT_DIR__;
        
        muLog("uploadDir=$uploadDir, uploadRoot=$uploadRoot");
        
        $date = date('Y/m');
        $relativeDir = $uploadDir . '/' . $date . '/';
        $realPath = $uploadRoot . '/' . $relativeDir;
        
        muLog("目标路径: $realPath");
        
        if (!is_dir($realPath)) {
            $mk = @mkdir($realPath, 0755, true);
            muLog("mkdir: " . ($mk ? '成功' : '失败') . ", 存在=" . (is_dir($realPath) ? '是' : '否'));
            if (!$mk && !is_dir($realPath)) {
                throw new \Exception('创建目录失败: ' . $realPath);
            }
        }
        
        if (!is_writable($realPath)) {
            throw new \Exception('目录不可写: ' . $realPath);
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($multiFormat) {
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'mp4', 'webm', 'ogv', 'mov', 'mp3', 'wav', 'ogg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z', 'txt', 'md', 'html', 'css', 'js', 'json', 'xml'];
        } else {
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
        }
        
        if (!in_array($ext, $allowedExts)) {
            throw new \Exception('非法扩展名: ' . $ext);
        }
        
        $filename = uniqid() . '.' . $ext;
        $filepath = $realPath . $filename;
        
        muLog("保存文件: " . $file['tmp_name'] . " -> $filepath");
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            $err = error_get_last();
            throw new \Exception('保存失败: ' . ($err['message'] ?? 'unknown'));
        }
        
        $fileRelativePath = '/' . $relativeDir . $filename;
        $url = Typecho_Common::url($fileRelativePath, $options->siteUrl);
        
        $mime = Typecho_Common::mimeContentType($filepath);
        if (empty($mime)) {
            $mime = $file['type'] ?: 'application/octet-stream';
        }
        
        muLog("文件已保存, URL=$url, path=$fileRelativePath, mime=$mime");
        
        // 判断文件类型分类
        $uploadedType = 'other';
        if (strpos($mime, 'image/') === 0 || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'])) {
            $uploadedType = 'image';
        } elseif (strpos($mime, 'video/') === 0 || in_array($ext, ['mp4', 'webm', 'ogv', 'mov'])) {
            $uploadedType = 'video';
        } elseif (strpos($mime, 'audio/') === 0 || in_array($ext, ['mp3', 'wav', 'ogg'])) {
            $uploadedType = 'audio';
        }
        
        $attachmentMeta = [
            'name' => $file['name'],
            'path' => $fileRelativePath,
            'size' => intval($file['size']),
            'type' => $ext,
            'mime' => $mime
        ];
        
        $text = json_encode($attachmentMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        muLog("text字段内容: " . $text);
        
        $db = Typecho_Db::get();
        
        $cid = $this->request->get('cid', 0);
        muLog("请求中的 cid=$cid");
        
        $contents = [
            'title' => $file['name'],
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
            muLog("关联到已有文章 cid=$cid");
        } else {
            $contents['parent'] = 0;
            muLog("新文章，parent=0，等待保存后由钩子关联");
        }
        
        muLog("数据库插入开始");
        $insertId = $db->query($db->insert('table.contents')->rows($contents));
        muLog("数据库插入完成, cid=$insertId");
        
        // 新文章：Session 精确记账，避免并发竞争和 limit 限制
        if ($cid == 0 && $insertId > 0) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (!isset($_SESSION['mu_pending'])) {
                $_SESSION['mu_pending'] = [];
            }
            $_SESSION['mu_pending'][] = intval($insertId);
            session_write_close(); // 立即释放 session 锁
            muLog("Session 记录 pending cid=$insertId");
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
        $cid = $this->request->get('cid', 0);
        
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
                session_write_close(); // 立即释放 session 锁
                muLog("新文章页面，清空 session pending");
            }
        }
        
        $db = Typecho_Db::get();
        
        // 获取当前文章内容（用于检测附件是否已插入正文）
        $content = '';
        if ($cid > 0) {
            $post = $db->fetchRow(
                $db->select('text')->from('table.contents')->where('cid = ?', $cid)
            );
            $content = $post['text'] ?? '';
        }
        
        if ($cid > 0) {
            // 已有文章：获取关联到该文章的附件
            $rows = $db->fetchAll(
                $db->select()->from('table.contents')
                    ->where('type = ?', 'attachment')
                    ->where('parent = ?', $cid)
                    ->order('created', Typecho_Db::SORT_DESC)
            );
        } else {
            // 新文章：获取当前用户最近30分钟内的未关联附件
            $user = Typecho_Widget::widget('Widget_User');
            $timeWindow = time() - 1800;
            $rows = $db->fetchAll(
                $db->select()->from('table.contents')
                    ->where('type = ?', 'attachment')
                    ->where('parent = ?', 0)
                    ->where('authorId = ?', $user->uid)
                    ->where('created > ?', $timeWindow)
                    ->order('created', Typecho_Db::SORT_DESC)
                    ->limit(50)
            );
        }
        
        $files = [];
        foreach ($rows as $row) {
            $text = $row['text'];
            if ($text[0] === '{') {
                $meta = json_decode($text, true);
            } else {
                $meta = unserialize($text);
            }
            
            if (isset($meta['path'])) {
                $url = Typecho_Common::url($meta['path'], Typecho_Widget::widget('Widget_Options')->siteUrl);
                $inserted = false;
                if (!empty($content)) {
                    // 检查附件URL或相对路径是否出现在文章正文中
                    $inserted = (strpos($content, $url) !== false) || (strpos($content, $meta['path']) !== false);
                }
                
                // 判断文件类型
                $ext = strtolower($meta['type'] ?? '');
                $mime = strtolower($meta['mime'] ?? '');
                $fileType = 'other';
                if (strpos($mime, 'image/') === 0 || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'])) {
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
                    'name' => $row['title'],
                    'url' => $url,
                    'size' => $meta['size'] ?? 0,
                    'type' => $fileType,
                    'ext' => $ext,
                    'inserted' => $inserted
                ];
            }
        }
        
        muOk(['files' => $files]);
    }
    
    public function attach()
    {
        $do = $this->request->get('do', '');
        $cid = $this->request->get('cid', 0);
        
        if ($do === 'delete' && $cid > 0) {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select()->from('table.contents')->where('cid = ?', $cid)
            );
            
            if ($row && $row['type'] === 'attachment') {
                $text = $row['text'];
                if ($text[0] === '{') {
                    $meta = json_decode($text, true);
                } else {
                    $meta = unserialize($text);
                }
                
                if (isset($meta['path'])) {
                    $file = __TYPECHO_ROOT_DIR__ . $meta['path'];
                    if (file_exists($file)) @unlink($file);
                }
                $db->query($db->delete('table.contents')->where('cid = ?', $cid));
            }
        }
        
        // 清除日志
        if ($do === 'clearLog') {
            $logFile = __DIR__ . '/fatal_debug.log';
            if (file_exists($logFile)) {
                @unlink($logFile);
            }
            header('Location: ' . \Typecho\Common::url('/options-plugin.php?config=AttachPlus2', \Widget\Options::alloc()->adminUrl));
            exit;
        }
        
        muOk([]);
    }
}

muLog("类定义完成");
