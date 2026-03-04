<?php
$data = isset($data) ? $data : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
?>

<div class="display no-border">
    <div id="toolbar-missed">
        <form action="?display=customcdrstats&view=missed_inbound" method="get" class="fpbx-submit">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="missed_inbound">
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

<h3>Пропущенные входящие звонки</h3>

<?php if (!empty($data)): ?>
    <table id="missedTable" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>Дата и время</th>
                <th>От кого</th>
                <th>Кому</th>
                <th>На номер</th>
                <th>Ожидание (сек)</th>
                <th>Причина</th>
                <th>LinkedID</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $row): 
                $clid = htmlspecialchars($row['clid'] ?? '');
                $src  = htmlspecialchars($row['src'] ?? '');
                $from = $clid ? "$clid ($src)" : $src;
            ?>
            <tr>
                <td><?php echo htmlspecialchars($row['calldate']); ?></td>
                <td><?php echo $from; ?></td>
                <td><?php echo htmlspecialchars($row['dst']); ?></td>
                <td><?php echo htmlspecialchars($row['did'] ?? '—'); ?></td>
                <td><?php echo (int)$row['wait_time']; ?></td>
                <td><?php echo htmlspecialchars($row['disposition']); ?></td>
                <td><?php echo htmlspecialchars($row['linkedid'] ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>Пропущенных входящих звонков за выбранный период нет.</p>
<?php endif; ?>

<script>
$(function() {
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

    $('#missedTable').DataTable({
        pageLength: 50,
        lengthMenu: [[25,50,100,250,-1],[25,50,100,250,"Все"]],
        order: [[0, "desc"]],
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rt<"row"<"col-sm-12"i><"col-sm-12 text-center"p>>',
        language: { url: "https://cdn.datatables.net/plug-ins/1.13.7/i18n/ru.json" },
        buttons: ['copy','csv','excel','pdf','print']
    });
});
</script>