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
 * 批量附件上传插件 - 支持图片/视频/文档等多格式，多选拖拽、实时进度、附件管理
 * 
 * @package AttachPlus2
 * @author zizdog
 * @version 2.0.0
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
        $maxSize = new Text('maxSize', null, '10', _t('单文件最大限制(MB)'));
        $form->addInput($maxSize);
        
        $maxFiles = new Text('maxFiles', null, '20', _t('单次最大上传数量'));
        $form->addInput($maxFiles);
        
        // 多格式附件模式
        $multiFormat = new Radio('multiFormat', [
            '1' => _t('开启'),
            '0' => _t('关闭')
        ], '0', _t('多格式附件模式'), _t('开启后支持上传图片、视频、音频、文档、压缩包等所有类型附件；关闭时仅支持图片。'));
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
            $tableHtml .= '<a href="' . \Helper::security()->getIndex('/action/attach-plus2-attach?do=clearLog') . '" class="operate-delete" onclick="return confirm(\'' . _t('确定清除日志？') . '\')">' . _t('清除') . '</a>';
        } else {
            $tableHtml .= _t('无日志');
        }
        $tableHtml .= '</td>';
        $tableHtml .= '</tr>';
        $tableHtml .= '</tbody>';
        $tableHtml .= '</table>';
        
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
        
        $logFile = __DIR__ . '/fatal_debug.log';
        $log = function($msg) use ($logFile) {
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            @file_put_contents($logFile, "[hook] " . date('H:i:s') . " $msg\n", FILE_APPEND);
        };
        
        $log("attachToPost 被调用, cid=$cid, uid=$uid");
        
        if (empty($cid) || empty($uid)) {
            return;
        }
        
        $db = Db::get();
        
        // 优先使用 Session 精确绑定（新文章上传的附件）
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $pending = $_SESSION['mu_pending'] ?? [];
        $log("Session pending 数量: " . count($pending));
        
        if (!empty($pending)) {
            $boundCount = 0;
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
                    $log("跳过无效附件 cid=$attCid");
                    continue;
                }
                
                $db->query(
                    $db->update('table.contents')
                        ->rows(['parent' => $cid])
                        ->where('cid = ?', $attCid)
                );
                $log("精确绑定附件 cid=$attCid ({$row['title']}) -> parent=$cid");
                $boundCount++;
            }
            
            unset($_SESSION['mu_pending']);
            $log("精确绑定完成，共 $boundCount 个附件，session 已清空");
            return;
        }
        
        // 回退：已有文章编辑时上传（upload 已直接设 parent，这里兜底）
        $timeWindow = time() - 1800;
        $attachments = $db->fetchAll(
            $db->select('cid', 'title', 'created')
                ->from('table.contents')
                ->where('type = ?', 'attachment')
                ->where('parent = ?', 0)
                ->where('authorId = ?', $uid)
                ->where('created > ?', $timeWindow)
                ->order('created', Db::SORT_DESC)
        );
        
        $log("回退机制找到 " . count($attachments) . " 个未归档附件");
        
        if (empty($attachments)) {
            return;
        }
        
        foreach ($attachments as $att) {
            $db->query(
                $db->update('table.contents')
                    ->rows(['parent' => $cid])
                    ->where('cid = ?', $att['cid'])
            );
            $log("回退绑定附件 cid={$att['cid']} ({$att['title']}) -> parent=$cid");
        }
        
        $log("回退完成，共更新 " . count($attachments) . " 个附件");
    }

    /**
     * 获取插件资源 URL
     */
    private static function assetUrl($path)
    {
        $options = Options::alloc();
        $pluginDir = $options->pluginUrl . '/AttachPlus2';
        return Common::url('assets/' . $path, $pluginDir);
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
        $cssUrl = self::assetUrl('css/multi-upload.css');
        $jsUrl = self::assetUrl('js/multi-upload.js');
        
        $dropHint = $multiFormat 
            ? '支持图片/视频/音频/文档/压缩包等，最多 ' . $maxFiles . ' 个，单张 ≤ ' . intval($maxSize/1024/1024) . 'MB'
            : '支持 JPG/PNG/GIF/WebP，最多 ' . $maxFiles . ' 张，单张 ≤ ' . intval($maxSize/1024/1024) . 'MB';
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
