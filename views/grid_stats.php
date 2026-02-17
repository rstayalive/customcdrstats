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
        <form action="?display=customcdrstats&view=grid_stats" method="get" class="fpbx-submit" id="grid_search" name="grid_form">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="grid_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-12">
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
                                        <option value=""><?php echo _("All"); ?></option>
                                        <?php foreach ($extensionsList as $extNum => $extDesc): ?>
                                            <option value="<?php echo htmlspecialchars($extNum); ?>" <?php echo ($extension == $extNum) ? 'selected' : ''; ?>><?php echo htmlspecialchars($extDesc); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <label class="control-label" for="ext_range"><?php echo _("Extension Range"); ?></label>
                                    <i class="fa fa-question-circle fpbx-help-icon" data-for="ext_range"></i>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control" id="ext_range" name="ext_range" value="<?php echo $extRange; ?>" placeholder="100-199">
                                </div>
                                <div class="col-md-1">
                                    <label class="control-label" for="queue"><?php echo _("Queue"); ?></label>
                                    <i class="fa fa-question-circle fpbx-help-icon" data-for="queue"></i>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-control" id="queue" name="queue">
                                        <option value=""><?php echo _("All"); ?></option>
                                        <?php foreach ($queuesList as $queueNum => $queueDesc): ?>
                                            <option value="<?php echo htmlspecialchars($queueNum); ?>" <?php echo ($queue == $queueNum) ? 'selected' : ''; ?>><?php echo htmlspecialchars($queueDesc); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <span id="drwrap-help" class="help-block fpbx-help-block"><?php echo _("Укажите временной интервал"); ?></span>
                    </div>
                </div>
            </div>
            <div class="text-right" style="text-align: right; margin-top: 10px;">
                <button type="submit" class="btn btn-default"><?php echo _('Поиск'); ?></button>
                <a href="?display=customcdrstats&view=grid_stats&export=csv&start=<?php echo urlencode($startDate); ?>&end=<?php echo urlencode($endDate); ?>&ext=<?php echo urlencode($extension); ?>&ext_range=<?php echo urlencode($extRange); ?>&queue=<?php echo urlencode($queue); ?>" class="btn btn-default"><?php echo _('Экспорт CSV'); ?></a>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($data['stats']) && !empty($data['stats']['total_calls'])): ?>
<h3>Общая статистика</h3>
<canvas id="statsChart" width="533" height="267"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    var ctx = document.getElementById('statsChart').getContext('2d');
    var stats = {
        total_calls: <?php echo $data['stats']['total_calls'] ?: 0; ?>,
        answered: <?php echo $data['stats']['answered'] ?: 0; ?>,
        missed: <?php echo $data['stats']['missed'] ?: 0; ?>,
        inbound: <?php echo $data['stats']['inbound'] ?: 0; ?>,
        outbound: <?php echo $data['stats']['outbound'] ?: 0; ?>,
        internal: <?php echo $data['stats']['internal'] ?: 0; ?>
    };
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Всего звонков', 'Отвечено', 'Пропущено', 'Входящих с транков', 'Исходящих через транки', 'Внутренних'],
            datasets: [
                {
                    label: 'Статистика',
                    data: [stats.total_calls, stats.answered, stats.missed, stats.inbound, stats.outbound, stats.internal],
                    backgroundColor: [
                        'rgba(54, 162, 235, 0.5)',
                        'rgba(75, 192, 192, 0.5)',
                        'rgba(255, 0, 0, 0.5)',
                        'rgba(54, 162, 235, 0.5)',
                        'rgba(255, 99, 132, 0.5)',
                        'rgba(153, 102, 255, 0.5)'
                    ],
                    borderColor: [
                        'rgba(54, 162, 235, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(255, 0, 0, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(153, 102, 255, 1)'
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
</script>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Всего звонков</th>
            <th>Отвечено</th>
            <th>Пропущено</th>
            <th>Средняя длительность (сек)</th>
            <th>Входящих с транков</th>
            <th>Исходящих через транки</th>
            <th>Внутренних звонков</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><?php echo htmlspecialchars($data['stats']['total_calls'] ?: 0); ?></td>
            <td><?php echo htmlspecialchars($data['stats']['answered'] ?: 0); ?></td>
            <td><?php echo htmlspecialchars($data['stats']['missed'] ?: 0); ?></td>
            <td><?php echo htmlspecialchars(round($data['stats']['avg_duration'] ?: 0)); ?></td>
            <td><?php echo htmlspecialchars($data['stats']['inbound'] ?: 0); ?></td>
            <td><?php echo htmlspecialchars($data['stats']['outbound'] ?: 0); ?></td>
            <td><?php echo htmlspecialchars($data['stats']['internal'] ?: 0); ?></td>
        </tr>
    </tbody>
</table>

<?php if (!empty($data['by_ext'])): ?>
<h3>По операторам</h3>
<table class="table table-striped" id="operatorsTable">
    <thead>
        <tr>
            <th>Тип оператора</th>
            <th>От</th>
            <th>Кому</th>
            <th>Звонков</th>
            <th>Общая длительность (сек)</th>
            <th>Средняя длительность (сек)</th>
            <th>Отвечено</th>
            <th>Пропущено</th>
            <th style="cursor: pointer;" onclick="sortTableByDate()">Дата звонка</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data['by_ext'] as $row): ?>
        <tr>
            <td><?php echo htmlspecialchars($row['operator_type']); ?></td>
            <td><?php echo htmlspecialchars($row['src_ext']); ?></td>
            <td><?php echo htmlspecialchars($row['dst_ext']); ?></td>
            <td><?php echo htmlspecialchars($row['calls']); ?></td>
            <td><?php echo htmlspecialchars($row['total_duration']); ?></td>
            <td><?php echo htmlspecialchars(round($row['avg_duration'])); ?></td>
            <td><?php echo htmlspecialchars($row['answered']); ?></td>
            <td><?php echo htmlspecialchars($row['missed']); ?></td>
            <td><?php echo htmlspecialchars($row['call_date']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php else: ?>
<p>Нет данных за выбранный период.</p>
<?php if (!empty($data['debug']['error'])): ?>
<p><strong>Ошибка базы данных:</strong> <?php echo htmlspecialchars($data['debug']['error']); ?></p>
<?php endif; ?>
<?php if (!empty($data['debug']['sql'])): ?>
<div>
    <button type="button" class="btn btn-info" data-toggle="collapse" data-target="#debugInfo"><?php echo _('Техническая информация'); ?></button>
    <div id="debugInfo" class="collapse">
        <p><strong>SQL-запрос:</strong> <?php echo htmlspecialchars($data['debug']['sql']); ?></p>
        <p><strong>Параметры:</strong> <?php echo htmlspecialchars(print_r($data['debug']['params'], true)); ?></p>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script type="text/javascript">
    $(function () {
        $('#datetimepickerStart').datetimepicker({locale: 'ru'});
        $('#datetimepickerStop').datetimepicker({locale: 'ru'});
    });

    // Сортировка таблицы по дате звонка
    let sortDir = 1;

    function sortTableByDate() {
        const table = document.getElementById('operatorsTable');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const dateHeader = table.querySelector('th:last-of-type');

        rows.sort((a, b) => {
            const dateA = new Date(a.cells[8].textContent.trim()); // 8 - индекс колонки даты (0-based)
            const dateB = new Date(b.cells[8].textContent.trim());
            return sortDir * (dateB - dateA); // desc по умолчанию (новые сверху)
        });

        rows.forEach(row => tbody.appendChild(row));
        sortDir *= -1; // Переключаем направление
        dateHeader.textContent = sortDir === 1 ? 'Дата звонка (Старые)' : 'Дата звонка (Новые)';
    }
</script>