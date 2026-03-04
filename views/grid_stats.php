<?php
$data = isset($data) ? $data : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
$extension = isset($extension) ? htmlspecialchars($extension) : '';
$extRange = isset($extRange) ? htmlspecialchars($extRange) : '';
$queue = isset($queue) ? htmlspecialchars($queue) : '';
$extensionsList = isset($extensionsList) ? $extensionsList : [];
$queuesList = isset($queuesList) ? $queuesList : [];
?>

<div class="display no-border">
    <div id="toolbar-all">
        <form action="?display=customcdrstats&view=grid_stats" method="get" class="fpbx-submit">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="grid_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="row">
                            <!-- Период -->
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Период"); ?></label>
                                <input type="text" class="form-control" id="daterange" name="daterange" value="<?php echo $startDate . ' - ' . $endDate; ?>" />
                            </div>
                            <!-- Extension -->
                            <div class="col-md-2">
                                <label class="control-label"><?php echo _("Extension"); ?></label>
                                <select class="form-control" name="ext">
                                    <option value="">Все</option>
                                    <?php foreach ($extensionsList as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $extension==$k?'selected':''; ?>><?php echo htmlspecialchars($v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Range -->
                            <div class="col-md-2">
                                <label class="control-label"><?php echo _("Range"); ?></label>
                                <input type="text" class="form-control" name="ext_range" value="<?php echo $extRange; ?>" placeholder="100-199">
                            </div>
                            <!-- Queue -->
                            <div class="col-md-2">
                                <label class="control-label"><?php echo _("Queue"); ?></label>
                                <select class="form-control" name="queue">
                                    <option value="">Все</option>
                                    <?php foreach ($queuesList as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $queue==$k?'selected':''; ?>><?php echo htmlspecialchars($v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Кнопки -->
                            <div class="col-md-3 text-right" style="padding-top:25px;">
                                <button type="submit" class="btn btn-default"><?php echo _('Поиск'); ?></button>
                                <a href="?display=customcdrstats&view=grid_stats&export=csv&start=<?php echo urlencode($startDate); ?>&end=<?php echo urlencode($endDate); ?>&ext=<?php echo urlencode($extension); ?>&ext_range=<?php echo urlencode($extRange); ?>&queue=<?php echo urlencode($queue); ?>" class="btn btn-default"><?php echo _('Экспорт CSV'); ?></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($data['stats']) && $data['stats']['total_calls'] > 0): ?>
    <h3>Общая статистика</h3>
    <canvas id="statsChart" width="800" height="300"></canvas>

    <table class="table table-striped">
        <thead><tr><th>Всего звонков</th><th>Отвечено</th><th>Пропущено</th><th>Средняя длительность</th><th>Входящих с транков</th><th>Исходящих</th><th>Внутренних</th></tr></thead>
        <tbody>
            <tr>
                <td><?php echo $data['stats']['total_calls']; ?></td>
                <td><?php echo $data['stats']['answered']; ?></td>
                <td><?php echo $data['stats']['missed']; ?></td>
                <td><?php echo round($data['stats']['avg_duration']); ?> сек</td>
                <td><?php echo $data['stats']['inbound']; ?></td>
                <td><?php echo $data['stats']['outbound']; ?></td>
                <td><?php echo $data['stats']['internal']; ?></td>
            </tr>
        </tbody>
    </table>

    <h3>По операторам</h3>
    <table id="operatorsTable" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr><th>Тип</th><th>От</th><th>Кому</th><th>Звонков</th><th>Длительность</th><th>Средняя</th><th>Отвечено</th><th>Пропущено</th><th>Дата</th></tr>
        </thead>
        <tbody>
            <?php foreach ($data['by_ext'] as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['operator_type']); ?></td>
                <td><?php echo htmlspecialchars($row['src_ext']); ?></td>
                <td><?php echo htmlspecialchars($row['dst_ext']); ?></td>
                <td><?php echo $row['calls']; ?></td>
                <td><?php echo $row['total_duration']; ?></td>
                <td><?php echo round($row['avg_duration']); ?></td>
                <td><?php echo $row['answered']; ?></td>
                <td><?php echo $row['missed']; ?></td>
                <td><?php echo htmlspecialchars($row['call_date']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>Нет данных за выбранный период.</p>
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

    // График
    var ctx = document.getElementById('statsChart').getContext('2d');
    var stats = {
        total_calls: <?php echo $data['stats']['total_calls'] ?? 0; ?>,
        answered: <?php echo $data['stats']['answered'] ?? 0; ?>,
        missed: <?php echo $data['stats']['missed'] ?? 0; ?>,
        inbound: <?php echo $data['stats']['inbound'] ?? 0; ?>,
        outbound: <?php echo $data['stats']['outbound'] ?? 0; ?>,
        internal: <?php echo $data['stats']['internal'] ?? 0; ?>
    };
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Всего', 'Отвечено', 'Пропущено', 'Входящих', 'Исходящих', 'Внутренних'],
            datasets: [{ label: 'Статистика', data: [stats.total_calls, stats.answered, stats.missed, stats.inbound, stats.outbound, stats.internal],
                backgroundColor: ['#36a2eb','#4bc0c0','#ff6384','#36a2eb','#ff9f40','#9966ff'] }]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });

<<<<<<< HEAD
    // DataTable
=======
    // DataTable — правильный порядок элементов
>>>>>>> 9507c3babd468d34509803aaf7d64dd8a68eb292
    $('#operatorsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Все"]],
        order: [[8, "desc"]],
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rt<"row"<"col-sm-12"i><"col-sm-12 text-center"p>>',
        language: { url: "https://cdn.datatables.net/plug-ins/1.13.7/i18n/ru.json" },
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print']
    });
});
</script>