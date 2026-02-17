<?php
$subhead = isset($subhead) ? $subhead : _('Статистика звонков');
$content = isset($content) ? $content : '';
$serverName = isset($serverName) ? htmlspecialchars($serverName) : gethostname();
?>

<div class="container-fluid">
    <h1><?php echo $serverName . ' | ' . _("Custom CDR Stats"); ?></h1>
    <h2><?php echo $subhead; ?></h2>
    <div class="display full-border">
        <div class="row">
            <div class="col-sm-11">
                <div class="fpbx-container">
                    <div class="display full-border">
                        <?php echo $content; ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-1 hidden-xs bootnav">
                <div class="list-group">
                    <?php
                    if (file_exists(__DIR__ . '/bootnav.php')) {
                        echo load_view(__DIR__ . '/bootnav.php', []);
                    } else {
                        echo '<p>' . _('Меню недоступно') . '</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>
