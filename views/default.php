<?php
$subhead = isset($subhead) ? $subhead : _('Статистика звонков');
$content = isset($content) ? $content : '';
$serverName = isset($serverName) ? htmlspecialchars($serverName) : gethostname();
?>

<div class="container-fluid">
    <h1><?php echo $serverName . ' | ' . _("Custom CDR Stats"); ?></h1>
    <h2><?php echo $subhead; ?></h2>
    <div class="display full-border">
        <div class="row">
            <div class="col-sm-11">
                <div class="fpbx-container">
                    <div class="display full-border">
                        <?php echo $content; ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-1 hidden-xs bootnav">
                <div class="list-group">
                    <?php
                    if (file_exists(__DIR__ . '/bootnav.php')) {
                        echo load_view(__DIR__ . '/bootnav.php', []);
                    } else {
                        echo '<p>' . _('Меню недоступно') . '</p>';
                    }
                    ?>
                    <!-- DataTables + DateRangePicker -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
<script src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- ГЛОБАЛЬНЫЙ СТИЛЬ ДЛЯ ВСЕХ ТАБЛИЦ МОДУЛЯ -->
<style>
    .dataTables_wrapper {
        margin-bottom: 380px !important;
    }
    
    /* Показать N записей + Поиск справа */
    .dataTables_length { float: left !important; padding: 18px 0 12px 15px !important; }
    .dataTables_filter  { float: right !important; text-align: right !important; padding: 18px 15px 12px 0 !important; }
    
    /* Строка "Записи с 1 до ..." */
    .dataTables_info {
        padding: 25px 15px 15px 15px !important;
        font-size: 14px;
        color: #555;
        clear: both;
        float: left;
    }
    
    /* Кнопки пагинации — ниже, без серого фона */
    .dataTables_paginate {
        padding: 18px 0 55px 0 !important;
        text-align: center;
        background: transparent !important;
        border: none !important;
    }
    
    .paginate_button {
        margin: 0 6px !important;
        padding: 9px 16px !important;
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }
    
    .paginate_button:hover {
        background: #f0f4ff !important;
    }
    
    .paginate_button.current {
        background: #007bff !important;
        color: white !important;
        border-color: #007bff;
    }
</style>
                </div>
            </div>
        </div>
    </div>
</div>
