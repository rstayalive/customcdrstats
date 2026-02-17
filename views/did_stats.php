<?php
$data = isset($data) ? $data : [];
$didSummary = isset($didSummary) ? $didSummary : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
$did = isset($did) ? htmlspecialchars($did) : '';
$didsList = isset($didsList) ? $didsList : [];

// Calculate max calls for heatmap scaling
$maxCalls = 0;
foreach ($data as $row) {
    if (isset($row['calls']) && $row['calls'] > $maxCalls) {
        $maxCalls = $row['calls'];
    }
}

// Aggregate for graph when specific DID is selected
$graphData = ['inbound' => 0, 'answered' => 0, 'missed' => 0];
if (!empty($did)) {
    foreach ($data as $row) {
        $graphData['inbound'] += $row['inbound'];
        $graphData['answered'] += $row['answered'];
        $graphData['missed'] += $row['missed'];
    }
}
?>

<div class="display no-border">
    <div id="toolbar-did-stats">
        <form action="?display=customcdrstats&view=did_stats" method="get" class="fpbx-submit" id="did_stats_search" name="did_stats_form">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="did_stats">
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
                                <div class="col-md-1">
                                    <label class="control-label" for="did"><?php echo _("DID"); ?></label>
                                    <i class="fa fa-question-circle fpbx-help-icon" data-for="did"></i>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-control" id="did" name="did">
                                        <option value="" <?php echo ($did == '') ? 'selected' : ''; ?>><?php echo _("Все DID"); ?></option>
                                        <?php foreach ($didsList as $didNum => $didDesc): ?>
                                            <option value="<?php echo htmlspecialchars($didNum); ?>" <?php echo ($did == $didNum) ? 'selected' : ''; ?>><?php echo htmlspecialchars($didDesc); ?></option>
                                        <?php endforeach; ?>
                                    </select>
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

<?php if (!empty($data)): ?>
<?php if (empty($did)): ?>
<h3>Сводная статистика по DID</h3>
<table class="table table-striped">
    <thead><tr><th>DID</th><th>Количество звонков</th></tr></thead>
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

<h3>Статистика по <?php echo $did ? 'DID ' . htmlspecialchars($did) : 'всем DID'; ?></h3>
<canvas id="callsChart" width="533" height="267"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    var ctx = document.getElementById('callsChart').getContext('2d');
    <?php if (!empty($did)): ?>
        var data = [
            {
                inbound: <?php echo $graphData['inbound'] ?: 0; ?>,
                answered: <?php echo $graphData['answered'] ?: 0; ?>,
                missed: <?php echo $graphData['missed'] ?: 0; ?>
            }
        ];
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Входящие', 'Отвеченные', 'Пропущенные'],
                datasets: [
                    {
                        label: 'Статистика',
                        data: [data[0].inbound, data[0].answered, data[0].missed],
                        backgroundColor: [
                            'rgba(54, 162, 235, 0.5)',
                            'rgba(75, 192, 192, 0.5)',
                            'rgba(255, 0, 0, 0.5)'
                        ],
                        borderColor: [
                            'rgba(54, 162, 235, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(255, 0, 0, 1)'
                        ],
                        borderWidth: 1
                    }
                ]
            },
            options: {
                scales: {
                    y: { beginAtZero: true },
                    x: { title: { display: true, text: 'Категория' } }
                }
            }
        });
    <?php else: ?>
        var data = [
            <?php foreach ($data as $row): ?>
                { hour: '<?php echo sprintf("%02d:00", $row['hour']); ?>', inbound: <?php echo $row['inbound']; ?>, answered: <?php echo $row['answered']; ?>, missed: <?php echo $row['missed']; ?> },
            <?php endforeach; ?>
        ];
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(d => d.hour),
                datasets: [
                    {
                        label: 'Входящие',
                        data: data.map(d => d.inbound),
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Отвеченные',
                        data: data.map(d => d.answered),
                        backgroundColor: 'rgba(75, 192, 192, 0.5)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Пропущенные',
                        data: data.map(d => d.missed),
                        backgroundColor: 'rgba(255, 0, 0, 0.5)',
                        borderColor: 'rgba(255, 0, 0, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                scales: {
                    y: { beginAtZero: true },
                    x: { title: { display: true, text: 'Час' } }
                }
            }
        });
    <?php endif; ?>
</script>

<h3>По часам (Heatmap)</h3>
<style>
    .heatmap-cell {
        transition: background-color 0.3s;
    }
</style>
<table class="table table-striped">
    <thead><tr><th>Час</th><th>Звонков</th><th>Общая длительность (сек)</th><th>Средняя длительность</th><th>Ответов</th></tr></thead>
    <tbody>
        <?php for ($h = 0; $h < 24; $h++): ?>
            <?php
                $row = array_filter($data, function($r) use ($h) { return $r['hour'] == $h; });
                $row = reset($row) ?: ['calls' => 0, 'total_duration' => 0, 'avg_duration' => 0, 'answered' => 0];
                $intensity = $maxCalls ? min($row['calls'] / $maxCalls, 1) : 0;
                $r = round(255 * $intensity);
                $bgColor = "rgb(255, " . (255 - $r) . ", " . (255 - $r) . ")";
            ?>
            <tr>
                <td class="heatmap-cell" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#FFF' : '#000'; ?>;">
                    <?php echo sprintf("%02d:00 - %02d:59", $h, $h); ?>
                </td>
                <td class="heatmap-cell" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#FFF' : '#000'; ?>;">
                    <?php echo htmlspecialchars($row['calls']); ?>
                </td>
                <td class="heatmap-cell" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#FFF' : '#000'; ?>;">
                    <?php echo htmlspecialchars($row['total_duration']); ?>
                </td>
                <td class="heatmap-cell" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#FFF' : '#000'; ?>;">
                    <?php echo round($row['avg_duration']); ?>
                </td>
                <td class="heatmap-cell" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $intensity > 0.5 ? '#FFF' : '#000'; ?>;">
                    <?php echo htmlspecialchars($row['answered']); ?>
                </td>
            </tr>
        <?php endfor; ?>
    </tbody>
</table>
<?php elseif (!empty($did)): ?>
<p>Нет данных для DID <?php echo htmlspecialchars($did); ?> в выбранном периоде.</p>
<?php else: ?>
<p>Нет данных для всех DID в выбранном периоде.</p>
<?php endif; ?>

<script type="text/javascript">
    $(function () {
        $('#datetimepickerStart').datetimepicker({locale: 'ru'});
        $('#datetimepickerStop').datetimepicker({locale: 'ru'});
    });
</script>