<?php
$data = isset($data) ? $data : [];
$didSummary = isset($data['did_summary']) ? $data['did_summary'] : [];
$statsByHour = isset($statsByHour) ? $statsByHour : ($data['stats'] ?? []);

$startDate = isset($startDate) ? htmlspecialchars($startDate) : date('Y-m-d');
$endDate   = isset($endDate)   ? htmlspecialchars($endDate)   : date('Y-m-d');
$did       = isset($did)       ? htmlspecialchars($did)       : '';
$didsList  = isset($didsList)  ? $didsList : [];

$maxCalls = 0;
foreach ($statsByHour as $row) {
    if (isset($row['calls']) && $row['calls'] > $maxCalls) {
        $maxCalls = $row['calls'];
    }
}
?>

<div class="display no-border">
    <div id="toolbar-outbound-did">
        <form action="?display=customcdrstats&view=outbound_did_stats" method="get" class="fpbx-submit">
            <input type="hidden" name="display" value="customcdrstats">
            <input type="hidden" name="view" value="outbound_did_stats">
            <div class="element-container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="control-label"><?php echo _("Период"); ?></label>
                                <input type="text" class="form-control" id="daterange" name="daterange" value="<?php echo $startDate . ' - ' . $endDate; ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="control-label"><?php echo _("DID"); ?></label>
                                <select class="form-control" name="did">
                                    <option value="">Все DID</option>
                                    <?php foreach ($didsList as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $did == $k ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($v); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5 text-right" style="padding-top:25px;">
                                <button type="submit" class="btn btn-default"><?php echo _('Поиск'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<h3>Исходящие звонки по DID <?php echo $did ? '— <b>' . htmlspecialchars($did) . '</b>' : '(по всем)'; ?></h3>

<?php if (!empty($didSummary)): ?>

    <canvas id="outboundDidChart" width="900" height="400"></canvas>

    <?php if (!empty($did) && !empty($statsByHour)): ?>
        <h4 style="margin-top: 20px;">По часам (Heatmap)</h4>
        <table class="table table-bordered heatmap-table" style="width:100%; margin-bottom: 30px;">
            <thead>
                <tr>
                    <th>Час</th>
                    <th>Всего</th>
                    <th>Отвечено</th>
                    <th>Недозвонились</th>
                    <th>Длительность</th>
                    <th>Средняя</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                for ($h = 0; $h < 24; $h++) {
                    $found = false;
                    foreach ($statsByHour as $row) {
                        if ((int)$row['hour'] === $h) {
                            $intensity = $maxCalls > 0 ? min(1, $row['calls'] / $maxCalls) : 0;
                            $bgColor = sprintf('rgba(231, 76, 60, %.2f)', $intensity);
                            echo '<tr>';
                            echo '<td>' . sprintf("%02d:00", $h) . '</td>';
                            echo '<td style="background-color: ' . $bgColor . '; color: ' . ($intensity > 0.6 ? '#fff' : '#000') . ';">' . $row['calls'] . '</td>';
                            echo '<td>' . $row['answered'] . '</td>';
                            echo '<td>' . $row['missed'] . '</td>';
                            echo '<td>' . round($row['total_duration']/60, 1) . ' мин</td>';
                            echo '<td>' . $row['avg_duration'] . ' сек</td>';
                            echo '</tr>';
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        echo '<tr><td>' . sprintf("%02d:00", $h) . '</td><td>0</td><td>0</td><td>0</td><td>0 мин</td><td>0 сек</td></tr>';
                    }
                }
                ?>
            </tbody>
        </table>
    <?php endif; ?>

    <table id="outboundDidTable" class="table table-striped table-bordered" style="width:100%; margin-top:20px;">
        <thead>
            <tr>
                <th>DID</th>
                <th>Всего</th>
                <th>Отвечено</th>
                <th>Недозвонились</th>
                <th>Длительность (мин)</th>
                <th>Средняя (сек)</th>
                <th>Уник. сотрудников</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($didSummary as $row): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($row['did']); ?></strong></td>
                <td><?php echo $row['calls']; ?></td>
                <td><?php echo $row['answered']; ?></td>
                <td><?php echo $row['missed']; ?></td>
                <td><?php echo round($row['total_duration']/60, 1); ?></td>
                <td><?php echo $row['avg_duration']; ?></td>
                <td>
                    <?php if ($row['unique_ext'] > 0): ?>
                        <a href="#" class="unique-ext-link" data-did="<?php echo htmlspecialchars($row['did']); ?>">
                            <?php echo $row['unique_ext']; ?>
                        </a>
                    <?php else: ?>
                        <?php echo $row['unique_ext']; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php else: ?>
    <p>Нет исходящих звонков за выбранный период.</p>
<?php endif; ?>

<!-- Bootstrap Modal для списка сотрудников -->
<div class="modal fade" id="extModal" tabindex="-1" role="dialog" aria-labelledby="extModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="extModalLabel">Уникальные сотрудники для DID</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <ul id="extList" class="list-group"></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

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

    var ctx = document.getElementById('outboundDidChart').getContext('2d');

    <?php if (!empty($did)): ?>
        // Конкретный DID — общая статистика
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Всего', 'Отвечено', 'Недозвонились'],
                datasets: [{
                    label: 'Исходящие',
                    data: [
                        <?php echo array_sum(array_column($didSummary, 'calls')) ?: 0; ?>,
                        <?php echo array_sum(array_column($didSummary, 'answered')) ?: 0; ?>,
                        <?php echo array_sum(array_column($didSummary, 'missed')) ?: 0; ?>
                    ],
                    backgroundColor: ['#36a2eb', '#4bc0c0', '#ff6384']
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });
    <?php else: ?>
        // Все DID — по часам
        var hours = [], calls = [], answered = [], missed = [];
        <?php foreach ($statsByHour as $row): ?>
            hours.push('<?php echo sprintf("%02d:00", $row['hour']); ?>');
            calls.push(<?php echo $row['calls']; ?>);
            answered.push(<?php echo $row['answered']; ?>);
            missed.push(<?php echo $row['missed']; ?>);
        <?php endforeach; ?>
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hours,
                datasets: [
                    {label:'Всего', data:calls, backgroundColor:'rgba(54,162,235,0.7)'},
                    {label:'Отвечено', data:answered, backgroundColor:'rgba(75,192,192,0.7)'},
                    {label:'Недозвонились', data:missed, backgroundColor:'rgba(255,99,132,0.7)'}
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });
    <?php endif; ?>

    $('#outboundDidTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10,25,50,100,-1],[10,25,50,100,"Все"]],
        order: [[1, "desc"]],
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rt<"row"<"col-sm-12"i><"col-sm-12 text-center"p>>',
        language: { url: "https://cdn.datatables.net/plug-ins/1.13.7/i18n/ru.json" },
        buttons: ['copy','csv','excel','pdf','print']
    });

    // Клик по уник. сотрудникам
    $(document).on('click', '.unique-ext-link', function(e) {
        e.preventDefault();
        var did = $(this).data('did');
        $.ajax({
            url: '?display=customcdrstats&view=get_unique_exts',
            data: {
                did: did,
                daterange: $('#daterange').val()  // передаём текущий период
            },
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#extList').empty();
                if (response.extensions && response.extensions.length > 0) {
                    response.extensions.forEach(function(ext) {
                        $('#extList').append('<li class="list-group-item">' + ext + '</li>');
                    });
                } else {
                    $('#extList').append('<li class="list-group-item">Нет данных</li>');
                }
                $('#extModalLabel').text('Уникальные сотрудники для DID ' + did);
                $('#extModal').modal('show');
            },
            error: function() {
                alert('Ошибка загрузки списка');
            }
        });
    });
});
</script>

<style>
    .heatmap-table td { text-align: center; font-weight: bold; }
    .heatmap-table th { background-color: #f8f9fa; text-align: center; }
    .heatmap-table tr td:first-child { background-color: #f1f3f5; font-weight: normal; }
    .unique-ext-link { color: #007bff; cursor: pointer; text-decoration: underline; }
</style>