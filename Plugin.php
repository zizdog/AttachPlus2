<?php

namespace TypechoPlugin\AttachPlus2;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Layout;
use Typecho\Common;
use Widget\Options;
use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AttachPlus2
 * Typecho附件批量上传/管理插件。
 * 
 * @package AttachPlus2
 * @author zizdog
 * @version 2.0.3
 * @link https://zizdog.com
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        \Helper::addRoute('attach_plus2_upload', '/action/attach-plus2-upload', 'AttachPlus2_Action', 'upload');
        \Helper::addRoute('attach_plus2_list', '/action/attach-plus2-list', 'AttachPlus2_Action', 'list');
        \Helper::addRoute('attach_plus2_attach', '/action/attach-plus2-attach', 'AttachPlus2_Action', 'attach');
        
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = ['TypechoPlugin\AttachPlus2\Plugin', 'render'];
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = ['TypechoPlugin\AttachPlus2\Plugin', 'render'];
        
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = ['TypechoPlugin\AttachPlus2\Plugin', 'attachToPost'];
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishSave = ['TypechoPlugin\AttachPlus2\Plugin', 'attachToPost'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = ['TypechoPlugin\AttachPlus2\Plugin', 'attachToPost'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishSave = ['TypechoPlugin\AttachPlus2\Plugin', 'attachToPost'];
        
        return _t('AttachPlus2 插件已激活');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        \Helper::removeRoute('attach_plus2_upload');
        \Helper::removeRoute('attach_plus2_list');
        \Helper::removeRoute('attach_plus2_attach');
        return _t('AttachPlus2 插件已禁用');
    }

    /**
     * 插件配置
     */
    public static function config(Form $form)
    {
        $maxSize = new Text('maxSize', null, '10', _t('单文件最大限制(MB)'), _t('服务端同样强制校验，超过即拒绝。'));
        $form->addInput($maxSize);
        
        $maxFiles = new Text('maxFiles', null, '20', _t('单次最大上传数量'), _t('服务端按同一用户短时间窗口强制校验，防止绕过前端限制批量上传。'));
        $form->addInput($maxFiles);
        
        // 多格式附件模式
        $multiFormat = new Radio('multiFormat', [
            '1' => _t('开启'),
            '0' => _t('关闭')
        ], '0', _t('多格式附件模式'), _t('开启后支持图片、视频、音频、文档、压缩包、纯文本/JSON 等附件；关闭时仅支持图片（JPG/JPEG/PNG/GIF/WebP/AVIF）。出于安全考虑，已移除 svg / html / htm / xhtml / xml / js / css / mjs 等可在同源下被浏览器渲染的类型。'));
        $form->addInput($multiFormat);
        
        // 调试模式开关
        $debugMode = new Radio('debugMode', [
            '1' => _t('开启'),
            '0' => _t('关闭')
        ], '0', _t('调试模式'),_t('开启该选项，将在上传界面显示debug面板，方便了解上传情况。'));
        $form->addInput($debugMode);
        
        // 日志管理（通过 addItem 放到表单最底部）
        $logFile = __DIR__ . '/fatal_debug.log';
        $logExists = file_exists($logFile);
        $logSize = $logExists ? round(filesize($logFile) / 1024, 2) : 0;
        
        $title = new Layout('div');
        $title->setAttribute('class', 'typecho-page-title');
        $title->setAttribute('style', 'margin-top:30px');
        $title->html('<h2>' . _t('调试日志管理') . '</h2>');
        $form->addItem($title);
        
        $tableHtml = '<table class="typecho-list-table">';
        $tableHtml .= '<colgroup><col width="50%"/><col width="25%"/><col width="25%"/></colgroup>';
        $tableHtml .= '<thead><tr><th>' . _t('日志文件路径') . '</th><th>' . _t('状态') . '</th><th>' . _t('操作') . '</th></tr></thead>';
        $tableHtml .= '<tbody>';
        $tableHtml .= '<tr>';
        $tableHtml .= '<td><code style="font-size:12px">' . $logFile . '</code></td>';
        $tableHtml .= '<td>' . ($logExists ? _t('存在，%s KB', $logSize) : _t('不存在')) . '</td>';
        $tableHtml .= '<td>';
        if ($logExists) {
            // clearLog 现在要求管理员 + POST + CSRF，这里用 JS 动态提交一个 POST 表单
            $clearLogUrl = Common::url('/action/attach-plus2-attach?do=clearLog', Options::alloc()->index);
            $clearLogToken = \Helper::security()->getToken('AttachPlus2');
            $tableHtml .= '<a href="#" class="operate-delete"'
                . ' data-url="' . htmlspecialchars($clearLogUrl) . '"'
                . ' data-token="' . htmlspecialchars($clearLogToken) . '"'
                . ' onclick="return attachPlus2ClearLog(this)">' . _t('清除') . '</a>';
        } else {
            $tableHtml .= _t('无日志');
        }
        $tableHtml .= '</td>';
        $tableHtml .= '</tr>';
        $tableHtml .= '</tbody>';
        $tableHtml .= '</table>';
        $tableHtml .= '<script>function attachPlus2ClearLog(el){'
            . 'if(!confirm(' . json_encode(_t('确定清除日志？'), JSON_UNESCAPED_UNICODE) . ')){return false;}'
            . 'var f=document.createElement("form");f.method="post";f.action=el.getAttribute("data-url");'
            . 'var i=document.createElement("input");i.type="hidden";i.name="_";i.value=el.getAttribute("data-token");'
            . 'f.appendChild(i);document.body.appendChild(f);f.submit();return false;}</script>';
        
        $table = new Layout('div');
        $table->html($tableHtml);
        $form->addItem($table);
    }

    public static function personalConfig(Form $form) {}

    /**
     * 文章保存后关联附件
     */
    public static function attachToPost($contents, $widget)
    {
        $cid = $widget->cid;
        $user = \Typecho_Widget::widget('Widget_User');
        $uid = $user->uid ?? 0;
        
        /*
         * 【日志策略】保存文章这个钩子每次都会跑，但绝大多数时候「无事发生」。
         * 以前无论有没有附件都固定写 3 行（被调用 / pending 数量 / 找到 0 个），
         * 两天就 4000 行 200KB，真正的错误反而被埋掉。
         * 现在：**只在有附件要绑、或出异常时**才写，且一条汇总行带上全部关键字段。
         */
        $t0 = microtime(true);
        $boundCount = 0;
        $pendingCount = 0;
        $found = 0;
        $skip = '';

        if (empty($cid) || empty($uid)) {
            self::log('hook', 'attachToPost 参数不全，跳过', array('cid' => $cid, 'uid' => $uid));
            return;
        }

        $db = Db::get();
        
        // 优先使用 Session 精确绑定（新文章上传的附件）
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $pending = $_SESSION['mu_pending'] ?? [];
        $pendingCount = count($pending);
        
        if (!empty($pending)) {
            foreach ($pending as $attCid) {
                $attCid = intval($attCid);
                if ($attCid <= 0) continue;
                
                // 验证附件存在且属于当前用户
                $row = $db->fetchRow(
                    $db->select('cid', 'title', 'authorId')
                        ->from('table.contents')
                        ->where('cid = ?', $attCid)
                        ->where('type = ?', 'attachment')
                        ->limit(1)
                );
                
                if (!$row || $row['authorId'] != $uid) {
                    $skip = 'invalid';
                    self::log('hook', '跳过无效附件', array(
                        'att' => $attCid, 'why' => $row ? 'authorId=' . $row['authorId'] : 'not-found', 'uid' => $uid,
                    ));
                    continue;
                }
                
                $db->query(
                    $db->update('table.contents')
                        ->rows(['parent' => $cid])
                        ->where('cid = ?', $attCid)
                );
                self::log('hook', '精确绑定附件', array(
                    'att' => $attCid, 'parent' => $cid, 'name' => $row['title'],
                ));
                $boundCount++;
            }
            
            unset($_SESSION['mu_pending']);

            self::log('hook', 'attachToPost 完成（精确）', array(
                'cid'     => $cid,
                'uid'     => $uid,
                'pending' => $pendingCount,
                'bound'   => $boundCount,
                'ms'      => round((microtime(true) - $t0) * 1000, 1),
            ));

            return;
        }
        
        // 回退：仅当 10 分钟窗口内「恰好一个」未归档附件时才绑定。
        // 原实现会把该用户 30 分钟内所有 parent=0 的附件全部改绑，
        // 多标签页/多设备时会绑到错误的文章，故收紧为唯一候选 + LIMIT。
        $timeWindow = time() - 600;
        $attachments = $db->fetchAll(
            $db->select('cid', 'title', 'created')
                ->from('table.contents')
                ->where('type = ?', 'attachment')
                ->where('parent = ?', 0)
                ->where('authorId = ?', $uid)
                ->where('created > ?', $timeWindow)
                ->order('created', Db::SORT_DESC)
                ->limit(2)
        );
        
        $found = count($attachments);

        if ($found !== 1) {
            // 0 个 = 正常（这篇本来就没传附件）→ 只留一条汇总；
            // >1 个 = 可疑（多标签页）→ 汇总里带 why 标出来
            self::log('hook', 'attachToPost 结束（无绑定）', array(
                'cid'     => $cid,
                'uid'     => $uid,
                'pending' => $pendingCount,
                'found'   => $found,
                'why'     => $found > 1 ? 'candidates-not-unique' : 'no-candidate',
                'ms'      => round((microtime(true) - $t0) * 1000, 1),
            ));

            return;
        }
        
        $att = $attachments[0];
        $db->query(
            $db->update('table.contents')
                ->rows(['parent' => $cid])
                ->where('cid = ?', $att['cid'])
        );

        self::log('hook', 'attachToPost 完成（回退绑定）', array(
            'cid'     => $cid,
            'uid'     => $uid,
            'pending' => $pendingCount,
            'found'   => $found,
            'bound'   => 1,
            'att'     => $att['cid'],
            'name'    => $att['title'],
            'ms'      => round((microtime(true) - $t0) * 1000, 1),
        ));
    }

    /* ================================================================ */
    /* 统一日志（仅 debugMode 开启时写入）                               */
    /* ================================================================ */

    /** 日志文件（相对本目录） */
    public const LOG_FILE = 'fatal_debug.log';

    /** 超过这个大小就轮转成 .1（**不是清空** —— 崩溃前的尾巴最值钱） */
    public const LOG_MAX_BYTES = 524288;

    /** 请求级日志 id，用来把同一次请求的多行串起来 */
    private static $logRid = '';
    private static $logHooked = false;

    /**
     * 调试开关（读一次，进程内缓存）
     *
     * @return bool
     */
    public static function logOn()
    {
        static $on = null;

        if (null !== $on) {
            return $on;
        }

        try {
            $pluginOptions = Options::alloc()->plugin('AttachPlus2');
            $on = intval($pluginOptions->debugMode ?? 0) === 1;
        } catch (\Throwable $e) {
            $on = false;
        }

        return $on;
    }

    /**
     * 本次请求的短 id（同一请求内所有日志行都带它）
     *
     * @return string
     */
    public static function logRid()
    {
        if ('' === self::$logRid) {
            self::$logRid = substr(md5(getmypid() . '|' . microtime(true) . '|' . mt_rand()), 0, 6);
        }

        return self::$logRid;
    }

    /**
     * 写一行日志
     *
     * 格式（一律单行、key=value，方便 grep）：
     *   2026-09-15 12:34:56.123 [pid:983] [req:a1b2c3] [upload] cid=725 ext=jpg size=1234 ok=1 msg=...
     *
     * @param string $tag 来源：upload|attach|hook|delete|render|fatal…
     * @param string $msg 一句话
     * @param array  $kv  附带的键值（值会被压成单行并截断）
     * @return void
     */
    public static function log($tag, $msg, array $kv = array())
    {
        if (!self::logOn()) {
            return;
        }

        try {
            $parts = array();

            foreach ($kv as $k => $v) {
                if (is_bool($v)) {
                    $v = $v ? '1' : '0';
                } elseif (is_array($v) || is_object($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $v = str_replace(array("\r", "\n", "\t"), ' ', (string) $v);
                $v = trim(preg_replace('/\s{2,}/', ' ', $v));

                if (function_exists('mb_substr')) {
                    $v = mb_substr($v, 0, 160, 'UTF-8');
                } else {
                    $v = substr($v, 0, 160);
                }

                $parts[] = $k . '=' . $v;
            }

            $ms = substr(sprintf('%.3f', microtime(true)), -4);   // .123

            /*
             * 时间用**站点时区**，别用 PHP 默认的 UTC。
             * Typecho 会把全局时区留成 UTC，只在 options->timezone 里存偏移（秒），
             * 所以直接 date() 出来的时间和后台界面对不上（差 8 小时），排查时很误导。
             */
            $offset = 0;

            try {
                $offset = intval(Options::alloc()->timezone);
            } catch (\Throwable $e) {
                $offset = 0;
            }

            $line = gmdate('Y-m-d H:i:s', time() + $offset) . $ms
                . ' [pid:' . getmypid() . ']'
                . ' [req:' . self::logRid() . ']'
                . ' [' . $tag . ']'
                . ('' === $msg ? '' : ' ' . $msg)
                . (empty($parts) ? '' : ' ' . implode(' ', $parts))
                . "\n";

            self::logRotate();

            @file_put_contents(__DIR__ . '/' . self::LOG_FILE, $line, FILE_APPEND);
        } catch (\Throwable $e) {
            // 日志本身绝不能影响业务
        }
    }

    /**
     * 记一个异常：带类名、文件行号、插件内的调用栈
     *
     * @param string     $tag
     * @param string     $what
     * @param \Throwable $e
     * @param array      $kv
     * @return void
     */
    public static function logError($tag, $what, $e, array $kv = array())
    {
        if (!self::logOn()) {
            return;
        }

        $kv['err'] = ($e instanceof \Throwable)
            ? get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()
            : (string) $e;

        // 只取插件目录内的帧，去掉 Typecho 那一大串，读起来才像人话
        if ($e instanceof \Throwable) {
            $frames = array();

            foreach (array_slice($e->getTrace(), 0, 12) as $f) {
                if (empty($f['file']) || false === strpos($f['file'], 'AttachPlus2')) {
                    continue;
                }

                $frames[] = basename($f['file']) . ':' . (isset($f['line']) ? $f['line'] : '?')
                    . (isset($f['function']) ? ' ' . $f['function'] . '()' : '');
            }

            if (!empty($frames)) {
                $kv['at'] = implode(' <- ', $frames);
            }
        }

        self::log($tag, $what, $kv);
    }

    /**
     * 大小到了就轮转成 .1（保留上一份，别直接清空）
     *
     * @return void
     */
    private static function logRotate()
    {
        $file = __DIR__ . '/' . self::LOG_FILE;

        if (is_file($file) && filesize($file) > self::LOG_MAX_BYTES) {
            @rename($file, $file . '.1');
        }
    }

    /**
     * 注册「致命错误」兜底：脚本因 E_ERROR 之类挂掉时，把最后一条错误写进日志
     *
     * 【为什么必须要有】以前这个文件叫 fatal_debug.log，却从来不记录 fatal ——
     * 页面白屏时日志里什么都没有，只能靠猜。现在 shutdown 时把
     * error_get_last() 里的致命错误原样落盘（文件:行 + 消息）。
     *
     * @return void
     */
    public static function logRegisterFatal()
    {
        if (self::$logHooked || !self::logOn()) {
            return;
        }

        self::$logHooked = true;

        register_shutdown_function(function () {
            $err = error_get_last();

            if (!is_array($err)) {
                return;
            }

            $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);

            if (!in_array($err['type'], $fatal, true)) {
                return;
            }

            self::log('fatal', 'PHP 致命错误', array(
                'type' => $err['type'],
                'msg'  => $err['message'],
                'at'   => basename($err['file']) . ':' . $err['line'],
            ));
        });
    }

    /**
     * 获取插件资源 URL
     */
    private static function assetUrl($path)
    {
        $options = Options::alloc();
        $pluginDir = $options->pluginUrl . '/AttachPlus2';
        // 用文件修改时间做缓存失效，避免浏览器继续使用不带 CSRF token 的旧版 JS
        $version = @filemtime(__DIR__ . '/assets/' . $path) ?: '2.0.3';
        return Common::url('assets/' . $path . '?v=' . $version, $pluginDir);
    }

    /**
     * 渲染上传界面
     */
    public static function render()
    {
        $options = Options::alloc();
        $plugin = $options->plugin('AttachPlus2');
        $ajaxUrl = Common::url('/action/attach-plus2-upload', $options->index);
        $listUrl = Common::url('/action/attach-plus2-list', $options->index);
        $attachUrl = Common::url('/action/attach-plus2-attach', $options->index);
        $maxSize = intval($plugin->maxSize ?? 10) * 1024 * 1024;
        $maxFiles = intval($plugin->maxFiles ?? 20);
        $cid = isset($_GET['cid']) ? intval($_GET['cid']) : 0;
        $multiFormat = intval($plugin->multiFormat ?? 0);
        $debugMode = intval($plugin->debugMode ?? 0);
        $securityToken = \Helper::security()->getToken('AttachPlus2');

        // 开着 debug 才注册「致命错误兜底」，也才输出调试面板
        self::logRegisterFatal();
        $cssUrl = self::assetUrl('css/multi-upload.css');
        $jsUrl = self::assetUrl('js/multi-upload.js');
        
        $dropHint = $multiFormat 
            ? '支持图片/视频/音频/文档/压缩包等，最多 ' . $maxFiles . ' 个，单张 ≤ ' . intval($maxSize/1024/1024) . 'MB'
            : '支持 JPG/PNG/GIF/WebP/AVIF，最多 ' . $maxFiles . ' 张，单张 ≤ ' . intval($maxSize/1024/1024) . 'MB';
        $accept = $multiFormat ? '*/*' : 'image/*';
        ?>

<link rel="stylesheet" href="<?php echo $cssUrl; ?>">

<div id="multi-upload-panel">
    <h3>
        批量附件上传
        <span style="font-size:12px;color:#999;font-weight:normal">点击选择文件，勾选后插入</span>
    </h3>
    <div class="mu-dropzone" id="mu-dropzone">
        <div class="mu-icon">📁</div>
        <div class="mu-text">点击选择或拖拽文件到此处</div>
        <div class="mu-hint"><?php echo $dropHint; ?></div>
        <input type="file" class="mu-file-input" id="mu-file-input" multiple accept="<?php echo $accept; ?>">
    </div>
    <div class="mu-toolbar">
        <label><input type="checkbox" id="mu-select-all"> 全选</label>
        <button class="mu-insert-btn" id="mu-insert-btn" disabled>插入到编辑器</button>
        <span id="mu-count" style="font-size:12px;color:#999;margin-left:auto"></span>
    </div>
    <div class="mu-fail-notice" id="mu-fail-notice" style="display:none"></div>
    <div class="mu-gallery" id="mu-gallery">
        <div class="mu-group" data-group="image" style="display:none">
            <div class="mu-group-title">📷 图片</div>
            <div class="mu-group-items"></div>
        </div>
        <?php if ($multiFormat): ?>
        <div class="mu-group" data-group="video" style="display:none">
            <div class="mu-group-title">🎬 视频</div>
            <div class="mu-group-items"></div>
        </div>
        <div class="mu-group" data-group="audio" style="display:none">
            <div class="mu-group-title">🎵 音频</div>
            <div class="mu-group-items"></div>
        </div>
        <div class="mu-group" data-group="other" style="display:none">
            <div class="mu-group-title">📄 其它</div>
            <div class="mu-group-items"></div>
        </div>
        <?php endif; ?>
        <div class="mu-empty">暂无附件，请上传或等待加载...</div>
    </div>
</div>

<?php
/*
 * 调试面板只在 debugMode 开启时输出 HTML。
 * JS 侧还有一道保险（见 assets/js/multi-upload.js 开头）：拿到 debugMode != 1
 * 时会把这个节点从 DOM 里删掉，避免旧缓存页面/旧模板把面板漏出来。
 */
?>
<?php if ($debugMode): ?>
<div class="mu-debug-dock" id="mu-debug-dock">
    <div class="mu-debug-badge" id="mu-debug-badge">
        <span class="dot"></span>
        <span>调试</span>
    </div>
    <div class="mu-debug-panel" id="mu-debug-panel">
        <div class="mu-debug-header">
            <h4>🔧 上传调试日志</h4>
            <div style="display:flex;gap:8px;align-items:center">
                <span class="copy" id="mu-debug-copy" title="复制全部日志">📋</span>
                <span class="close" id="mu-debug-close">✕</span>
            </div>
        </div>
        <div class="mu-debug-body" id="mu-debug-body"></div>
        <div class="mu-debug-footer">
            <span id="mu-debug-status">就绪</span>
            <span id="mu-debug-count">0 条日志</span>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
window.MultiUploadConfig = {
    ajaxUrl: '<?php echo $ajaxUrl; ?>',
    listUrl: '<?php echo $listUrl; ?>',
    attachUrl: '<?php echo $attachUrl; ?>',
    securityToken: '<?php echo $securityToken; ?>',
    maxSize: <?php echo $maxSize; ?>,
    maxFiles: <?php echo $maxFiles; ?>,
    cid: <?php echo $cid; ?>,
    multiFormat: <?php echo $multiFormat; ?>,
    debugMode: <?php echo $debugMode; ?>
};
</script>
<script src="<?php echo $jsUrl; ?>"></script>
        
        <?php
    }
}
