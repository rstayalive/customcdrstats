<?php
$data = isset($data) ? $data : [];
$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate = isset($endDate) ? htmlspecialchars($endDate) : date('Y-m-d');
$extension = isset($extension) ? htmlspecialchars($extension) : '';
$extensionsList = isset($extensionsList) ? $extensionsList : [];
?>

<div class="display no-border">
    <div id="toolbar-ext-stats">
        <form action="?display=customcdrstats&view=per_ext_stats" method="get" class="fpbx-submit" id="ext_stats_search" name="ext_stats_form">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="per_ext_stats">
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
                                    <label class="control-label" for="ext"><?php echo _("Extension"); ?></label>
                                    <i class="fa fa-question-circle fpbx-help-icon" data-for="ext"></i>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-control" id="ext" name="ext">
                                        <option value=""><?php echo _("Select Extension"); ?></option>
                                        <?php foreach ($extensionsList as $extNum => $extDesc): ?>
                                            <option value="<?php echo htmlspecialchars($extNum); ?>" <?php echo ($extension == $extNum) ? 'selected' : ''; ?>><?php echo htmlspecialchars($extDesc); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-11">
                        <span id="drwrap-help" class="help-block fpbx-help-block"><?php echo _("Укажите временной интервал и номер"); ?></span>
                    </div>
                </div>
            </div>
            <div class="text-right" style="text-align: right; margin-top: 10px;">
                <button type="submit" class="btn btn-default"><?php echo _('Поиск'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($data) && !empty($extension)): ?>
<h3>Статистика по номеру <?php echo htmlspecialchars($extension); ?></h3>
<canvas id="extChart" width="533" height="267"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    var ctx = document.getElementById('extChart').getContext('2d');
    var hours = [];
    var calls = [];
    var inboundExternal = [];
    var outboundExternal = [];
    var inboundInternal = [];
    var outboundInternal = [];
    var answered = [];
    var missed = [];
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
                {
                    label: 'Всего звонков',
                    data: calls,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Входящие внешние',
                    data: inboundExternal,
                    backgroundColor: 'rgba(75, 192, 192, 0.5)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Исходящие внешние',
                    data: outboundExternal,
                    backgroundColor: 'rgba(255, 99, 132, 0.5)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Входящие внутренние',
                    data: inboundInternal,
                    backgroundColor: 'rgba(153, 102, 255, 0.5)',
                    borderColor: 'rgba(153, 102, 255, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Исходящие внутренние',
                    data: outboundInternal,
                    backgroundColor: 'rgba(255, 159, 64, 0.5)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Отвечено',
                    data: answered,
                    backgroundColor: 'rgba(75, 192, 192, 0.5)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Пропущено',
                    data: missed,
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
</script>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Час</th>
            <th>Всего звонков</th>
            <th>Входящие внешние</th>
            <th>Исходящие внешние</th>
            <th>Входящие внутренние</th>
            <th>Исходящие внутренние</th>
            <th>Отвечено</th>
            <th>Пропущено</th>
            <th>Общая длительность (сек)</th>
            <th>Средняя длительность (сек)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data as $row): ?>
        <tr>
            <td><?php echo htmlspecialchars($row['hour']); ?>:00</td>
            <td><?php echo htmlspecialchars($row['calls']); ?></td>
            <td><?php echo htmlspecialchars($row['inbound_external']); ?></td>
            <td><?php echo htmlspecialchars($row['outbound_external']); ?></td>
            <td><?php echo htmlspecialchars($row['inbound_internal']); ?></td>
            <td><?php echo htmlspecialchars($row['outbound_internal']); ?></td>
            <td><?php echo htmlspecialchars($row['answered']); ?></td>
            <td><?php echo htmlspecialchars($row['missed']); ?></td>
            <td><?php echo htmlspecialchars($row['total_duration']); ?></td>
            <td><?php echo htmlspecialchars(round($row['avg_duration'])); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php else: ?>
<p>Выберите номер и период для отображения статистики.</p>
<?php if (!empty($debug['error'])): ?>
<p><strong>Ошибка базы данных:</strong> <?php echo htmlspecialchars($debug['error']); ?></p>
<?php endif; ?>
<?php if (!empty($debug['sql'])): ?>
<div>
    <button type="button" class="btn btn-info" data-toggle="collapse" data-target="#debugInfo"><?php echo _('Техническая информация'); ?></button>
    <div id="debugInfo" class="collapse">
        <p><strong>SQL-запрос:</strong> <?php echo htmlspecialchars($debug['sql']); ?></p>
        <p><strong>Параметры:</strong> <?php echo htmlspecialchars(print_r($debug['params'], true)); ?></p>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script type="text/javascript">
    $(function () {
        $('#datetimepickerStart').datetimepicker({locale: 'ru'});
        $('#datetimepickerStop').datetimepicker({locale: 'ru'});
    });
</script>
