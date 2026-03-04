<?php
$noCallExtensions = isset($noCallExtensions) ? $noCallExtensions : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
?>

<div class="display no-border">
    <div id="toolbar-no-call-stats">
        <form action="?display=customcdrstats&view=no_call_stats" method="get" class="fpbx-submit">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="no_call_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Период"); ?></label>
                                <input type="text" class="form-control" id="daterange" name="daterange" value="<?php echo $startDate . ' - ' . $endDate; ?>" />
                            </div>
                            <div class="col-md-9 text-right" style="padding-top:25px;">
                                <button type="submit" class="btn btn-default"><?php echo _('Поиск'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<h3>Номера без звонков</h3>
<?php if (!empty($noCallExtensions)): ?>
    <table id="noCallTable" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr><th>Номер</th></tr>
        </thead>
        <tbody>
            <?php foreach ($noCallExtensions as $ext => $name): ?>
            <tr>
                <td><?php echo htmlspecialchars($name ?: $ext); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>Все номера имеют звонки в выбранном периоде.</p>
<?php endif; ?>

<script>
$(function() {
    // === Date Range Picker ===
    $('#daterange').daterangepicker({
        locale: {
            format: 'YYYY-MM-DD',
            applyLabel: 'Применить',
            cancelLabel: 'Отмена',
            daysOfWeek: ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'],
            monthNames: ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь']
        },
        startDate: '<?php echo $startDate; ?>',
        endDate: '<?php echo $endDate; ?>',
        ranges: {
           'Сегодня': [moment(), moment()],
           'Вчера': [moment().subtract(1,'days'), moment().subtract(1,'days')],
           '7 дней': [moment().subtract(6,'days'), moment()],
           '30 дней': [moment().subtract(29,'days'), moment()],
           'Этот месяц': [moment().startOf('month'), moment().endOf('month')],
           'Прошлый месяц': [moment().subtract(1,'month').startOf('month'), moment().subtract(1,'month').endOf('month')]
        }
    });

    // === DataTable ===
    $('#noCallTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10,25,50,100,-1],[10,25,50,100,"Все"]],
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rt<"row"<"col-sm-12"i><"col-sm-12 text-center"p>>',
        language: { url: "https://cdn.datatables.net/plug-ins/1.13.7/i18n/ru.json" },
        buttons: ['copy','csv','excel','pdf','print']
    });
});
</script>