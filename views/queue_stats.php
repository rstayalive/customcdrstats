<?php
$data = isset($data) ? $data : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate   = isset($endDate)   ? htmlspecialchars($endDate)   : date('Y-m-d');
$queue     = isset($queue)     ? htmlspecialchars($queue)     : '';
$queuesList = isset($queuesList) ? $queuesList : [];
?>

<div class="display no-border">
    <div id="toolbar-queue-stats">
        <form action="?display=customcdrstats&view=queue_stats" method="get" class="fpbx-submit">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="queue_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Период"); ?></label>
                                <input type="text" class="form-control" id="daterange" name="daterange" value="<?php echo $startDate . ' - ' . $endDate; ?>" />
                            </div>
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Очередь"); ?></label>
                                <select class="form-control" name="queue">
                                    <option value="">Выберите очередь</option>
                                    <?php foreach ($queuesList as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $queue==$k?'selected':''; ?>><?php echo htmlspecialchars($v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 text-right" style="padding-top:25px;">
                                <button type="submit" class="btn btn-default"><?php echo _('Поиск'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($data) && !empty($queue) && !empty($data['stats'])): ?>
    <h3>Статистика по очереди <?php echo htmlspecialchars($queue); ?></h3>
    <canvas id="queueChart" width="800" height="300"></canvas>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Всего входящих</th>
                <th>Отвечено</th>
                <th>Пропущено</th>
                <th>Средняя длительность</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo $data['stats']['inbound'] ?? 0; ?></td>
                <td><?php echo $data['stats']['answered'] ?? 0; ?></td>
                <td><?php echo $data['stats']['missed'] ?? 0; ?></td>
                <td><?php echo round($data['stats']['avg_duration'] ?? 0); ?> сек</td>
            </tr>
        </tbody>
    </table>

    <?php if (!empty($data['by_ext'])): ?>
    <h3>По операторам</h3>
    <table id="queueOperatorsTable" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr><th>Тип</th><th>От</th><th>Кому</th><th>Звонков</th><th>Длительность</th><th>Средняя</th><th>Отвечено</th><th>Пропущено</th><th>Дата</th></tr>
        </thead>
        <tbody>
            <?php foreach ($data['by_ext'] as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['operator_type'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($row['src_ext'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($row['dst_ext'] ?? ''); ?></td>
                <td><?php echo $row['calls'] ?? 0; ?></td>
                <td><?php echo $row['total_duration'] ?? 0; ?></td>
                <td><?php echo round($row['avg_duration'] ?? 0); ?></td>
                <td><?php echo $row['answered'] ?? 0; ?></td>
                <td><?php echo $row['missed'] ?? 0; ?></td>
                <td><?php echo htmlspecialchars($row['call_date'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php else: ?>
    <p>Выберите очередь и период.</p>
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

    // === ГРАФИК — порядок как ты просил: Входящие → Отвечено → Пропущено ===
    var stats = {
        inbound:  <?php echo $data['stats']['inbound'] ?? 0; ?>,
        answered: <?php echo $data['stats']['answered'] ?? 0; ?>,
        missed:   <?php echo $data['stats']['missed'] ?? 0; ?>
    };

    new Chart(document.getElementById('queueChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['Всего входящих', 'Отвечено', 'Пропущено'],
            datasets: [{
                label: 'Статистика очереди',
                data: [stats.inbound, stats.answered, stats.missed],
                backgroundColor: ['#36a2eb', '#4bc0c0', '#ff6384']
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } }
        }
    });

    $('#queueOperatorsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10,25,50,100,-1],[10,25,50,100,"Все"]],
        order: [[8, "desc"]],
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rt<"row"<"col-sm-12"i><"col-sm-12 text-center"p>>',
        language: { url: "https://cdn.datatables.net/plug-ins/1.13.7/i18n/ru.json" },
        buttons: ['copy','csv','excel','pdf','print']
    });
});
</script>