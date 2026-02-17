<?php
$noCallExtensions = isset($noCallExtensions) ? $noCallExtensions : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
?>

<div class="display no-border">
    <div id="toolbar-no-call-stats">
        <form action="?display=customcdrstats&view=no_call_stats" method="get" class="fpbx-submit" id="no_call_stats_search" name="no_call_stats_form">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="no_call_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-11">
                        <div class="row">
                            <div class="form-group">
                                <div class="col-md-1">
                                    <label class="control-label" for="drwrap"><?php echo _("Date Range"); ?></label>
                                    <i class="fa fa-question-circle fpbx-help-icon" data-for="drwrap"></i>
                                </div>
                                <div class="col-md-2">
                                    <?php echo _("From"); ?>:
                                    <div class='input-group date' id='datetimepickerStart'>
                                        <input type='text' class="form-control" name="start" value="<?php echo $startDate; ?>" />
                                        <span class="input-group-addon">
                                            <span class="glyphicon glyphicon-calendar"></span>
                                        </span>
                                    </div>
                                    <?php echo _("To"); ?>:
                                    <div class='input-group date' id='datetimepickerStop'>
                                        <input type='text' class="form-control" name="end" value="<?php echo $endDate; ?>" />
                                        <span class="input-group-addon">
                                            <span class="glyphicon glyphicon-calendar"></span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-11">
                        <span id="drwrap-help" class="help-block fpbx-help-block"><?php echo _("Укажите временной интервал"); ?></span>
                    </div>
                </div>
            </div>
            <div class="text-right" style="text-align: right; margin-top: 10px;">
                <button type="submit" class="btn btn-default"><?php echo _('Поиск'); ?></button>
            </div>
        </form>
    </div>
</div>

<h3>Номера без звонков</h3>
<?php if (!empty($noCallExtensions)): ?>
<table class="table table-striped">
    <thead>
        <tr>
            <th>Номер</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($noCallExtensions as $ext): ?>
        <tr>
            <td><?php echo htmlspecialchars($ext); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>Все номера имеют звонки в выбранном периоде.</p>
<?php endif; ?>

<script type="text/javascript">
    $(function () {
        $('#datetimepickerStart').datetimepicker({locale: 'ru'});
        $('#datetimepickerStop').datetimepicker({locale: 'ru'});
    });
</script>
