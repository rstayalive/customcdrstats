<?php
$data = isset($data) ? $data : [];
$didSummary = isset($didSummary) ? $didSummary : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
$did = isset($did) ? htmlspecialchars($did) : '';
$didsList = isset($didsList) ? $didsList : [];

// Расчёт максимума для цвета heatmap
$maxCalls = 0;
foreach ($data as $row) {
    if (isset($row['calls']) && $row['calls'] > $maxCalls) $maxCalls = $row['calls'];
}
?>

<div class="display no-border">
    <div id="toolbar-did-stats">
        <form action="?display=customcdrstats&view=did_stats" method="get" class="fpbx-submit">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="did_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Период"); ?></label>
                                <input type="text" class="form-control" id="daterange" name="daterange" value="<?php echo $startDate . ' - ' . $endDate; ?>" />
                            </div>
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("DID"); ?></label>
                                <select class="form-control" name="did">
                                    <option value="">Все DID</option>
                                    <?php foreach ($didsList as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $did==$k?'selected':''; ?>><?php echo htmlspecialchars($v); ?></option>
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

<?php if (!empty($data)): ?>
    <h3>Статистика по <?php echo $did ? 'DID ' . htmlspecialchars($did) : 'всем DID'; ?></h3>
    <canvas id="didChart" width="800" height="300"></canvas>

    <?php if (empty($did)): ?>
    <h4>Сводка по DID</h4>
    <table class="table table-striped">
        <thead><tr><th>DID</th><th>Звонков</th></tr></thead>
        <tbody>
            <?php foreach ($didSummary as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['did']); ?></td>
                <td><?php echo htmlspecialchars($row['calls']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h4>По часам (Heatmap)</h4>
    <table class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr><th>Час</th><th>Звонков</th><th>Общая длительность (сек)</th><th>Средняя длительность</th><th>Ответов</th></tr>
        </thead>
        <tbody>
            <?php for ($h = 0; $h < 24; $h++): 
                $row = array_filter($data, function($r) use ($h) { return isset($r['hour']) && $r['hour'] == $h; });
                $row = reset($row) ?: ['calls' => 0, 'total_duration' => 0, 'avg_duration' => 0, 'answered' => 0];
                $intensity = $maxCalls ? min($row['calls'] / $maxCalls, 1) : 0;
                $r = round(255 * $intensity);
                $bgColor = "rgb(255, " . (255 - $r) . ", " . (255 - $r) . ")";
            ?>
            <tr>
                <td style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#fff' : '#000'; ?>;">
                    <?php echo sprintf("%02d:00", $h); ?>
                </td>
                <td style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#fff' : '#000'; ?>;">
                    <?php echo htmlspecialchars($row['calls']); ?>
                </td>
                <td style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#fff' : '#000'; ?>;">
                    <?php echo htmlspecialchars($row['total_duration']); ?>
                </td>
                <td style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#fff' : '#000'; ?>;">
                    <?php echo round($row['avg_duration']); ?>
                </td>
                <td style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#fff' : '#000'; ?>;">
                    <?php echo htmlspecialchars($row['answered']); ?>
                </td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>Нет данных за выбранный период.</p>
<?php endif; ?>

<script>
$(function() {
    // Date Range Picker
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
    var ctx = document.getElementById('didChart').getContext('2d');
    <?php if (!empty($did)): ?>
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Входящие', 'Отвеченные', 'Пропущенные'],
                datasets: [{
                    label: 'Статистика',
                    data: [<?php echo array_sum(array_column($data, 'inbound')) ?: 0; ?>, <?php echo array_sum(array_column($data, 'answered')) ?: 0; ?>, <?php echo array_sum(array_column($data, 'missed')) ?: 0; ?>],
                    backgroundColor: ['#36a2eb', '#4bc0c0', '#ff6384']
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    <?php else: ?>
        var hours = [], inbound = [], answered = [], missed = [];
        <?php foreach ($data as $row): ?>
            hours.push('<?php echo sprintf("%02d:00", $row['hour']); ?>');
            inbound.push(<?php echo $row['inbound']; ?>);
            answered.push(<?php echo $row['answered']; ?>);
            missed.push(<?php echo $row['missed']; ?>);
        <?php endforeach; ?>
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hours,
                datasets: [
                    {label:'Входящие', data:inbound, backgroundColor:'rgba(54,162,235,0.6)'},
                    {label:'Отвеченные', data:answered, backgroundColor:'rgba(75,192,192,0.6)'},
                    {label:'Пропущенные', data:missed, backgroundColor:'rgba(255,99,132,0.6)'}
                ]
            },
            options: { responsive:true, scales:{ y:{beginAtZero:true} } }
        });
    <?php endif; ?>
});
</script>