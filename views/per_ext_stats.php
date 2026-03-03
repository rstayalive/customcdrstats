<?php
$data = isset($data) ? $data : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
$extension = isset($extension) ? htmlspecialchars($extension) : '';
$extensionsList = isset($extensionsList) ? $extensionsList : [];

// Максимум звонков для расчёта цвета
$maxCalls = 0;
foreach ($data as $row) {
    if (isset($row['calls']) && $row['calls'] > $maxCalls) $maxCalls = $row['calls'];
}
?>

<div class="display no-border">
    <div id="toolbar-ext-stats">
        <form action="?display=customcdrstats&view=per_ext_stats" method="get" class="fpbx-submit">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="per_ext_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Период"); ?></label>
                                <input type="text" class="form-control" id="daterange" name="daterange" value="<?php echo $startDate . ' - ' . $endDate; ?>" />
                            </div>
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Номер"); ?></label>
                                <select class="form-control" name="ext">
                                    <option value="">Выберите номер</option>
                                    <?php foreach ($extensionsList as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $extension==$k?'selected':''; ?>><?php echo htmlspecialchars($v); ?></option>
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

<?php if (!empty($data) && !empty($extension)): ?>
    <h3>Статистика по номеру <?php echo htmlspecialchars($extension); ?></h3>
    <canvas id="extChart" width="800" height="300"></canvas>

    <h4>По часам (Heatmap)</h4>
    <table class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>Час</th>
                <th>Всего</th>
                <th>Вх.внеш</th>
                <th>Исх.внеш</th>
                <th>Вх.внутр</th>
                <th>Исх.внутр</th>
                <th>Отвечено</th>
                <th>Пропущено</th>
                <th>Длительность</th>
                <th>Средняя</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($h = 0; $h < 24; $h++): 
                $row = array_filter($data, function($r) use ($h) { return $r['hour'] == $h; });
                $row = reset($row) ?: ['calls'=>0,'inbound_external'=>0,'outbound_external'=>0,'inbound_internal'=>0,'outbound_internal'=>0,'answered'=>0,'missed'=>0,'total_duration'=>0,'avg_duration'=>0];
                $intensity = $maxCalls ? min($row['calls'] / $maxCalls, 1) : 0;
                $r = round(255 * $intensity);
                $bgColor = "rgb(255, " . (255 - $r) . ", " . (255 - $r) . ")";
            ?>
            <tr>
                <td><?php echo sprintf("%02d:00", $h); ?></td>
                <td style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.6 ? '#fff' : '#000'; ?>; font-weight: bold;">
                    <?php echo $row['calls']; ?>
                </td>
                <td><?php echo $row['inbound_external']; ?></td>
                <td><?php echo $row['outbound_external']; ?></td>
                <td><?php echo $row['inbound_internal']; ?></td>
                <td><?php echo $row['outbound_internal']; ?></td>
                <td><?php echo $row['answered']; ?></td>
                <td><?php echo $row['missed']; ?></td>
                <td><?php echo $row['total_duration']; ?></td>
                <td><?php echo round($row['avg_duration']); ?></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>Выберите номер и период для отображения статистики.</p>
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
    var ctx = document.getElementById('extChart').getContext('2d');
    var hours = [], calls = [], inboundExternal = [], outboundExternal = [], inboundInternal = [], outboundInternal = [], answered = [], missed = [];
    <?php foreach ($data as $row): ?>
        hours.push('<?php echo $row['hour']; ?>:00');
        calls.push(<?php echo $row['calls']; ?>);
        inboundExternal.push(<?php echo $row['inbound_external']; ?>);
        outboundExternal.push(<?php echo $row['outbound_external']; ?>);
        inboundInternal.push(<?php echo $row['inbound_internal']; ?>);
        outboundInternal.push(<?php echo $row['outbound_internal']; ?>);
        answered.push(<?php echo $row['answered']; ?>);
        missed.push(<?php echo $row['missed']; ?>);
    <?php endforeach; ?>
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: hours,
            datasets: [
                {label:'Всего', data:calls, backgroundColor:'rgba(54,162,235,0.6)'},
                {label:'Вх.внеш', data:inboundExternal, backgroundColor:'rgba(75,192,192,0.6)'},
                {label:'Исх.внеш', data:outboundExternal, backgroundColor:'rgba(255,99,132,0.6)'},
                {label:'Вх.внутр', data:inboundInternal, backgroundColor:'rgba(153,102,255,0.6)'},
                {label:'Исх.внутр', data:outboundInternal, backgroundColor:'rgba(255,159,64,0.6)'},
                {label:'Отвечено', data:answered, backgroundColor:'rgba(75,192,192,0.6)'},
                {label:'Пропущено', data:missed, backgroundColor:'rgba(255,0,0,0.6)'}
            ]
        },
        options: { responsive:true, scales:{ y:{beginAtZero:true} } }
    });
});
</script>