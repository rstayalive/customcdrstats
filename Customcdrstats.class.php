<?php
namespace FreePBX\modules;

class Customcdrstats implements \BMO {
    public $FreePBX;
    private $db;      
    private $configDb; 
    private $logPath = '/var/log/asterisk/customcdrstats.log';

    public function __construct($freepbx = null) {
        if ($freepbx == null) {
            $freepbx = \FreePBX::create();
        }
        $this->FreePBX = $freepbx;
        $this->configDb = $freepbx->Database; 
             $conf = [];
        if (file_exists('/etc/freepbx.conf')) {
            $lines = file('/etc/freepbx.conf');
            foreach ($lines as $line) {
                if (preg_match('/\$amp_conf\[[\'"](.*?)[\'"]\] = [\'"](.*?)[\'"];/', $line, $matches)) {
                    $conf[$matches[1]] = $matches[2];
                }
            }
        }

        $host = $conf['CDRDBHOST'] ?? $conf['AMPDBHOST'] ?? '127.0.0.1';
        $port = $conf['CDRDBPORT'] ?? $conf['AMPDBPORT'] ?? '3306';
        $user = $conf['CDRDBUSER'] ?? $conf['AMPDBUSER'] ?? 'freepbxuser';
        $pass = $conf['CDRDBPASS'] ?? $conf['AMPDBPASS'] ?? '';
        $dbname = $conf['CDRDBNAME'] ?? 'asteriskcdrdb';

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $this->db = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " CDR DB connected: $dbname @ $host\n", FILE_APPEND);
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " CRITICAL: Cannot connect to CDR DB '$dbname': " . $e->getMessage() . "\n", FILE_APPEND);
            throw new \Exception("Не удалось подключиться к базе CDR (asteriskcdrdb). Проверьте /etc/freepbx.conf и настройки CDR.");
        }
    }

     private function parseDateRange() {
        $daterange = trim($_REQUEST['daterange'] ?? '');

        if (strpos($daterange, ' - ') !== false) {
            [$startDate, $endDate] = array_map('trim', explode(' - ', $daterange, 2));

            // Проверка формата YYYY-MM-DD
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                file_put_contents($this->logPath, date('Y-m-d H:i:s') . " DateRange parsed: $startDate - $endDate\n", FILE_APPEND);
                return [$startDate, $endDate];
            }
        }

        // Fallback
        $startDate = $_REQUEST['start'] ?? date('Y-m-d');
        $endDate   = $_REQUEST['end'] ?? $startDate;
        return [$startDate, $endDate];
    }

public function getUniqueExtensionsForDid($start, $end, $did) {
    $startTime = $start . ' 00:00:00';
    $endTime   = $end . ' 23:59:59';

    // === ТОТ ЖЕ УНИВЕРСАЛЬНЫЙ ПАРСЕР DID (как в getOutboundDidStats) ===
    $didParser = "TRIM(
        CASE 
            WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', -1) REGEXP '^[0-9a-fA-F]{5,}$'
            THEN LEFT(
                    SUBSTRING_INDEX(dstchannel, '/', -1),
                    LENGTH(SUBSTRING_INDEX(dstchannel, '/', -1)) 
                  - LENGTH(SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', -1)) 
                  - 1
                 )
            ELSE SUBSTRING_INDEX(dstchannel, '/', -1)
        END
    )";

    $query = "
        SELECT DISTINCT 
            SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) as extension
        FROM cdr 
        WHERE calldate BETWEEN :start AND :end 
          AND $didParser = :did
          AND channel NOT LIKE 'Local/%@from-internal%'
          AND channel NOT LIKE '%FMGL-%'
          AND channel NOT LIKE '%followme%'
          AND dstchannel REGEXP '^(PJSIP|SIP)/'
          AND (
              -- 1. Классический исходящий + короткие городские
              (LENGTH(TRIM(src)) BETWEEN 3 AND 6 
               AND channel REGEXP '^(PJSIP|SIP)/[0-9a-zA-Z]{2,6}-')
              
              OR 
              -- 2. Click-to-Call / WebRTC / браузер
              (LENGTH(TRIM(src)) >= 10 
               AND channel REGEXP '^(PJSIP|SIP)/[0-9]{3,}-')
              
              OR 
              -- 3. Через outbound_cnum
              LENGTH(TRIM(outbound_cnum)) >= 7
          )
        HAVING LENGTH(extension) BETWEEN 3 AND 6 
           AND extension NOT REGEXP '^7[89]'
        ORDER BY extension
    ";

    $params = [':start' => $startTime, ':end' => $endTime, ':did' => $did];

    try {
        $sth = $this->db->prepare($query);
        $sth->execute($params);
        $result = $sth->fetchAll(\PDO::FETCH_COLUMN);
        $unique = array_unique(array_filter(array_map('trim', $result)));

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getUniqueExtensionsForDid('$did') → " . count($unique) . " сотрудников (v2 — полная поддержка Click-to-Call и коротких номеров)\n", FILE_APPEND);
        return $unique;
    } catch (\PDOException $e) {
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getUniqueExtensionsForDid ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        return [];
    }
}

    private function getOutboundTotals($startTime, $endTime) {
        $allowedDids = array_keys($this->getDids());
        $didPlaceholders = !empty($allowedDids) 
            ? implode(',', array_fill(0, count($allowedDids), '?')) 
            : 'NULL';

        $params = [$startTime, $endTime];
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN answered_flag = 1 THEN 1 ELSE 0 END) as answered,
                SUM(max_billsec) as total_duration
            FROM (
                SELECT 
                    linkedid,
                    MAX(CASE WHEN disposition = 'ANSWERED' OR billsec > 0 THEN 1 ELSE 0 END) as answered_flag,
                    MAX(billsec) as max_billsec
                FROM cdr 
                WHERE calldate BETWEEN ? AND ? 
                  AND outbound_cnum != '' 
                  AND LENGTH(outbound_cnum) >= 7
                  AND channel NOT LIKE '%FMGL-%' 
                  AND channel NOT LIKE '%followme%'";

        if (!empty($allowedDids)) {
            $sql .= " AND outbound_cnum IN ($didPlaceholders)";
            $params = array_merge($params, $allowedDids);
        }

        $sql .= "
                GROUP BY linkedid
            ) sub
        ";

        $sth = $this->db->prepare($sql);
        $sth->execute($params);
        $result = $sth->fetch(\PDO::FETCH_ASSOC);

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getOutboundTotals → total: " . ($result['total'] ?? 0) . "\n", FILE_APPEND);

        return $result ?: ['total' => 0, 'answered' => 0, 'total_duration' => 0];
    }

    public function install() { file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Install called\n", FILE_APPEND); }
    public function uninstall() {}
    public function backup() {}
    public function restore($backup) {}
    public function doConfigPageInit($page) {}

    public function getExtensions() {
        $users = $this->FreePBX->Core->getAllUsers();
        $list = [];
        foreach ($users as $user) {
            $list[$user['extension']] = $user['extension'] . ' (' . $user['name'] . ')';
        }
        return $list;
    }

public function getDids() {
    try {
        $list = [];

        // 1. DID из Inbound Routes FreePBX
        $sql = "SELECT DISTINCT extension FROM incoming WHERE extension != '' ORDER BY extension";
        $sth = $this->configDb->query($sql);
        $dids = $sth->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($dids as $did) {
            $did = trim($did);
            if (empty($did)) continue;
            $list[$did] = $did;

            // Для Oniks: добавляем 10-значный вариант (747... → 47...)
            if (strlen($did) === 11 && $did[0] === '7') {
                $did10 = substr($did, 1);
                $list[$did10] = $did10;
            }
        }

        // 2. ВСЕГДА добавляем реальные номера из CDR (главный фикс для Oniks)
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDids: добавляем реальные outbound из CDR (60 дней)\n", FILE_APPEND);

        $fallbackSql = "
            SELECT DISTINCT COALESCE(NULLIF(TRIM(outbound_cnum),''), dst) as did
            FROM cdr 
            WHERE calldate > DATE_SUB(NOW(), INTERVAL 60 DAY)
              AND LENGTH(COALESCE(NULLIF(TRIM(outbound_cnum),''), dst)) BETWEEN 7 AND 15
            GROUP BY did
            HAVING COUNT(DISTINCT linkedid) >= 1
            ORDER BY COUNT(*) DESC
            LIMIT 50
        ";

        $sth = $this->db->query($fallbackSql);
        $fallback = $sth->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($fallback as $d) {
            $d = trim($d);
            if ($d && !isset($list[$d])) {
                $list[$d] = $d;
            }
        }

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDids() → возвращено " . count($list) . " DID (incoming + реальные outbound из CDR)\n", FILE_APPEND);

        return $list;

    } catch (\Exception $e) {
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDids ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        return [];
    }
}

    public function getQueues() {
        try {
            $sql = "SELECT extension FROM queues_config ORDER BY extension";
            $sth = $this->configDb->query($sql);
            $queues = $sth->fetchAll(\PDO::FETCH_COLUMN);
            $list = [];
            foreach ($queues as $q) $list[$q] = $q;
            return $list;
        } catch (\Exception $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getQueues error: " . $e->getMessage() . "\n", FILE_APPEND);
            return [];
        }
    }

    private function exportCsv() {
        $start  = $_REQUEST['start'] ?? date('Y-m-d');
        $end    = $_REQUEST['end'] ?? date('Y-m-d');
        $filter = [
            'extension' => $_REQUEST['ext'] ?? '',
            'ext_range' => $_REQUEST['ext_range'] ?? '',
            'queue'     => $_REQUEST['queue'] ?? ''
        ];

        $data = $this->getCallStats($start, $end, $filter);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cdr_stats_' . $start . '_' . $end . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Дата звонка', 'От', 'Кому', 'Звонков', 'Длительность (сек)', 'Отвечено', 'Пропущено']);

        foreach ($data['by_ext'] as $row) {
            fputcsv($out, [
                $row['call_date'],
                $row['src_ext'],
                $row['dst_ext'],
                $row['calls'],
                $row['total_duration'],
                $row['answered'],
                $row['missed']
            ]);
        }
        fclose($out);
        exit;
    }

public function getCallStats($start, $end, $filter = []) {
    $startTime = $start . ' 00:00:00';
    $endTime   = $end . ' 23:59:59';

    $baseParams = [$startTime, $endTime];
    $where = "calldate BETWEEN ? AND ?";

    // === Очередь (если выбрана) ===
    if (!empty($filter['queue'])) {
        $linkedSql = "SELECT DISTINCT linkedid FROM cdr WHERE calldate BETWEEN ? AND ? AND dst = ?";
        $sth = $this->db->prepare($linkedSql);
        $sth->execute([$startTime, $endTime, $filter['queue']]);
        $linkedIds = $sth->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($linkedIds)) {
            return ['stats' => ['total_calls'=>0,'answered'=>0,'missed'=>0,'avg_duration'=>0,'internal'=>0,'inbound'=>0,'outbound'=>0], 'by_ext'=>[]];
        }
        $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
        $where = "calldate BETWEEN ? AND ? AND linkedid IN ($placeholders)";
        $baseParams = array_merge([$startTime, $endTime], $linkedIds);
    }

    // === 1. ВХОДЯЩИЕ ===
    $inSql = <<<SQL
        SELECT 
            COUNT(*) as total_inbound,
            SUM(CASE WHEN answered_flag = 1 THEN 1 ELSE 0 END) as answered_inbound,
            SUM(CASE WHEN answered_flag = 0 THEN 1 ELSE 0 END) as missed
        FROM (
            SELECT 
                linkedid,
                MAX(CASE 
                    WHEN EXISTS (
                        SELECT 1 FROM cdr a 
                        WHERE a.linkedid = cdr.linkedid 
                          AND LENGTH(a.dst) BETWEEN 3 AND 6 
                          AND a.disposition = 'ANSWERED'
                    ) THEN 1 ELSE 0 
                END) as answered_flag
            FROM cdr 
            WHERE $where 
              AND did != '' 
              AND LENGTH(dst) < 8 
              AND (outbound_cnum = '' OR outbound_cnum IS NULL)
              AND channel NOT LIKE '%FMGL-%' 
              AND channel NOT LIKE '%followme%'
            GROUP BY linkedid
        ) sub
SQL;

    $sth = $this->db->prepare($inSql);
    $sth->execute($baseParams);
    $in = $sth->fetch(\PDO::FETCH_ASSOC) ?: ['total_inbound'=>0, 'answered_inbound'=>0, 'missed'=>0];

    // === 2. ИСХОДЯЩИЕ ===
    $outboundData = $this->getOutboundDidStats($start, $end, '');
    $summary = $outboundData['did_summary'] ?? [];
    $out = [
        'total'    => (int) array_sum(array_column($summary, 'calls')),
        'answered' => (int) array_sum(array_column($summary, 'answered'))
    ];

    // === 3. ВНУТРЕННИЕ ===
    $internalSql = "
        SELECT COUNT(DISTINCT linkedid) as internal
        FROM cdr 
        WHERE $where 
          AND LENGTH(src) < 8 
          AND LENGTH(dst) < 8
          AND (outbound_cnum = '' OR outbound_cnum IS NULL)
    ";
    $sth = $this->db->prepare($internalSql);
    $sth->execute($baseParams);
    $internal = (int)$sth->fetchColumn();

    // === ФИНАЛЬНЫЕ ЦИФРЫ ===
    $total_calls = $in['total_inbound'] + $out['total'];
    $answered    = $in['answered_inbound']; 
    $avg_duration = $total_calls > 0 
        ? round(($in['total_inbound'] * 60 + $out['total'] * 60) / $total_calls, 0) 
        : 0;

    $stats = [
        'total_calls' => $total_calls,
        'answered'    => $answered, 
        'missed'      => (int)$in['missed'],
        'avg_duration'=> (int)$avg_duration,
        'inbound'     => (int)$in['total_inbound'],
        'outbound'    => $out['total'],
        'internal'    => $internal
    ];

    // === Таблица состав статистики
    $byExt = [];
    $extParams = $baseParams;
    $allowedDids = array_keys($this->getDids());
    $didPlaceholders = !empty($allowedDids) ? implode(',', array_fill(0, count($allowedDids), '?')) : 'NULL';

    $extSql = "
        SELECT 
            linkedid,
            MIN(call_date) as call_date,
            MIN(src_ext) as src_ext,
            MAX(dst_ext) as dst_ext,
            MAX(max_billsec) as max_billsec,
            MAX(answered_flag) as answered_flag,
            MAX(outbound_cnum_flag) as outbound_cnum_flag
        FROM (
            SELECT 
                linkedid,
                calldate as call_date,
                src as src_ext,
                dst as dst_ext,
                billsec as max_billsec,
                outbound_cnum as outbound_cnum_flag,
                CASE 
                    -- === ВХОДЯЩИЙ (внешний → внутренний) ===
                    WHEN LENGTH(src) > 7 AND LENGTH(dst) < 8 THEN
                        IF(EXISTS(
                            SELECT 1 FROM cdr a 
                            WHERE a.linkedid = cdr.linkedid 
                              AND LENGTH(a.dst) BETWEEN 3 AND 6 
                              AND a.disposition = 'ANSWERED'
                        ), 1, 0)
                    
                    -- === ИСХОДЯЩИЙ (внутренний → внешний) ===
                    WHEN LENGTH(src) BETWEEN 3 AND 6 AND LENGTH(dst) >= 7 THEN
                        IF(EXISTS(
                            SELECT 1 FROM cdr a 
                            WHERE a.linkedid = cdr.linkedid 
                              AND LENGTH(a.src) BETWEEN 3 AND 6 
                              AND (a.disposition = 'ANSWERED' OR a.billsec > 0)
                        ), 1, 0)
                    
                    ELSE 0
                END as answered_flag
            FROM cdr 
            WHERE $where 
              AND (
                  (LENGTH(src) > 7 AND LENGTH(dst) < 8)                                 
                  OR LENGTH(TRIM(outbound_cnum)) >= 7                                   
                  OR (LENGTH(TRIM(src)) BETWEEN 3 AND 6 
                      AND LENGTH(TRIM(dst)) >= 7 
                      AND dst NOT REGEXP '^[0-9]{1,4}$')                                
              )
        ) sub
        GROUP BY linkedid 
        ORDER BY MIN(call_date)
    ";

    $sth = $this->db->prepare($extSql);
    $sth->execute($extParams);
    $extRaw = $sth->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($extRaw as $row) {
        $src_ext = trim($row['src_ext'] ?? '');
        $dst_ext = trim($row['dst_ext'] ?? '');
        $maxBillsec = (int)$row['max_billsec'];
        $answered_flag = (int)$row['answered_flag'];
        $hasOutboundCnum = !empty(trim($row['outbound_cnum_flag']));

        $is_inbound  = (strlen($src_ext) > 7 && strlen($dst_ext) < 8);
        $is_outbound = $hasOutboundCnum 
    || (strlen($src_ext) >= 3 && strlen($src_ext) <= 6 && strlen($dst_ext) >= 7);

        $missed_inbound  = ($is_inbound  && $answered_flag == 0) ? 1 : 0;
        $missed_outbound = ($is_outbound && $answered_flag == 0) ? 1 : 0;

        $byExt[] = [
            'operator_type'   => $is_outbound ? 'Outbound' : 'Inbound',
            'src_ext'         => $src_ext,
            'dst_ext'         => $dst_ext,
            'calls'           => 1,
            'total_duration'  => $maxBillsec,
            'avg_duration'    => $maxBillsec,
            'answered'        => $answered_flag,
            'missed_inbound'  => $missed_inbound,
            'missed_outbound' => $missed_outbound,
            'call_date'       => $row['call_date']
        ];
    }

    return ['stats' => $stats, 'by_ext' => $byExt];
}
 
    public function getPerExtStats($start, $end, $ext) {
        $startTime = $start . ' 00:00:00';
        $endTime = $end . ' 23:59:59';
        $where = "calldate BETWEEN :start AND :end AND (src = :ext OR dst = :ext)";
        $params = [':start' => $startTime, ':end' => $endTime, ':ext' => $ext];
        $sql = "SELECT * FROM cdr WHERE $where ORDER BY calldate";
        try {
            $sth = $this->db->prepare($sql);
            $sth->execute($params);
            $raw = $sth->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error in getPerExtStats for ext $ext: " . $e->getMessage() . "\n", FILE_APPEND);
            return [];
        }

        $grouped = [];
        foreach ($raw as $row) {
            $linkedid = $row['linkedid'];
            if (!isset($grouped[$linkedid])) $grouped[$linkedid] = [];
            $grouped[$linkedid][] = $row;
        }

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = ['hour' => $h, 'calls' => 0, 'inbound_external' => 0, 'outbound_external' => 0, 'inbound_internal' => 0, 'outbound_internal' => 0, 'answered' => 0, 'missed' => 0, 'total_duration' => 0];
        }

        foreach ($grouped as $rows) {
            usort($rows, fn($a,$b) => strtotime($a['calldate']) - strtotime($b['calldate']));
            $orig_row = $rows[0];
            $hour = (int)substr($orig_row['calldate'],11,2);
            $orig_src = $orig_row['src'];
            $orig_dst = $orig_row['dst'];

            $call_type = ($orig_src == $ext) ? 'outbound' : (($orig_dst == $ext) ? 'inbound' : null);
            if (!$call_type) continue;
            $other = ($call_type == 'outbound') ? $orig_dst : $orig_src;
            $is_external = strlen((string)$other) > 4;
            $type_key = $call_type == 'outbound'
                ? ($is_external ? 'outbound_external' : 'outbound_internal')
                : ($is_external ? 'inbound_external' : 'inbound_internal');

            $hasAgent = false; $max_billsec = 0;
            foreach ($rows as $row) {
                if ($row['lastapp'] == 'Dial' && $row['billsec'] > 0) $hasAgent = true;
                if ($row['billsec'] > $max_billsec) $max_billsec = $row['billsec'];
            }

            $answered = $hasAgent ? 1 : 0;
            $missed = $hasAgent ? 0 : 1;
            if ($max_billsec > 0 && $max_billsec < 5 && !$hasAgent) { $missed = 1; $answered = 0; }

            $hourly[$hour][$type_key] += 1;
            $hourly[$hour]['calls'] += 1;
            $hourly[$hour]['answered'] += $answered;
            $hourly[$hour]['missed'] += $missed;
            $hourly[$hour]['total_duration'] += $max_billsec;
        }

        foreach ($hourly as $h => &$row) {
            $row['avg_duration'] = $row['calls'] > 0 ? round($row['total_duration'] / $row['calls'], 2) : 0;
        }
        return array_values($hourly);
    }

public function getDidStats($start, $end, $did = '') {
    $startTime = $start . ' 00:00:00';
    $endTime   = $end . ' 23:59:59';

    $where = "calldate BETWEEN :start AND :end";
    $params = [':start' => $startTime, ':end' => $endTime];

    if ($did) {
        $where .= " AND did = :did";
        $params[':did'] = $did;
    }

    // Фильтр только входящих + отсекаем followme-ноги (как в grid_stats)
    $inWhere = $where . "
        AND did != '' 
        AND LENGTH(dst) < 8 
        AND (outbound_cnum = '' OR outbound_cnum IS NULL)
        AND channel NOT LIKE '%FMGL-%' 
        AND channel NOT LIKE '%followme%'
    ";

    // 1. По часам (Heatmap + график)
    $statsQuery = "
        SELECT 
            HOUR(calldate) as hour,
            COUNT(*) as calls,
            SUM(CASE WHEN answered_flag = 1 THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN answered_flag = 0 THEN 1 ELSE 0 END) as missed,
            SUM(max_billsec) as total_duration,
            ROUND(AVG(NULLIF(max_billsec, 0)), 0) as avg_duration,
            COUNT(*) as inbound
        FROM (
            SELECT 
                linkedid,
                MIN(calldate) as calldate,
                MAX(CASE WHEN disposition = 'ANSWERED' OR billsec > 0 THEN 1 ELSE 0 END) as answered_flag,
                MAX(billsec) as max_billsec
            FROM cdr 
            WHERE $inWhere
            GROUP BY linkedid
        ) sub
        GROUP BY HOUR(calldate)
        ORDER BY hour
    ";

    // 2. Сводка по DID (таблица "Сводка по DID")
    $summaryQuery = "
        SELECT 
            did,
            COUNT(*) as calls,
            SUM(CASE WHEN answered_flag = 1 THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN answered_flag = 0 THEN 1 ELSE 0 END) as missed,
            SUM(max_billsec) as total_duration,
            ROUND(AVG(NULLIF(max_billsec, 0)), 0) as avg_duration,
            COUNT(*) as inbound
        FROM (
            SELECT 
                did,
                linkedid,
                MAX(CASE WHEN disposition = 'ANSWERED' OR billsec > 0 THEN 1 ELSE 0 END) as answered_flag,
                MAX(billsec) as max_billsec
            FROM cdr 
            WHERE $inWhere
            GROUP BY did, linkedid
        ) sub
        GROUP BY did
        ORDER BY calls DESC
    ";

    try {
        $sth = $this->db->prepare($statsQuery);
        $sth->execute($params);
        $stats = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $sth = $this->db->prepare($summaryQuery);
        $sth->execute($params);
        $summary = $sth->fetchAll(\PDO::FETCH_ASSOC);

        return ['stats' => $stats, 'did_summary' => $summary];
    } catch (\PDOException $e) {
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error in getDidStats: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['stats' => [], 'did_summary' => []];
    }
}
    
public function getOutboundDidStats($start, $end, $did = '') {
    $startTime = $start . ' 00:00:00';
    $endTime   = $end . ' 23:59:59';

    // === ИДЕАЛЬНЫЙ УНИВЕРСАЛЬНЫЙ ПАРСЕР DID ===
    $didParser = "TRIM(
        CASE 
            WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', -1) REGEXP '^[0-9a-fA-F]{5,}$'
            THEN LEFT(
                    SUBSTRING_INDEX(dstchannel, '/', -1),
                    LENGTH(SUBSTRING_INDEX(dstchannel, '/', -1)) 
                  - LENGTH(SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', -1)) 
                  - 1
                 )
            ELSE SUBSTRING_INDEX(dstchannel, '/', -1)
        END
    )";

    $params = [$startTime, $endTime];

    // === УНИВЕРСАЛЬНЫЙ ФИЛЬТР ИСХОДЯЩИХ ===
    $outboundWhere = "
        channel NOT LIKE 'Local/%@from-internal%'               -- исключаем follow-me
        AND channel NOT LIKE '%FMGL-%' 
        AND channel NOT LIKE '%followme%'
        AND dstchannel REGEXP '^(PJSIP|SIP)/'                   -- только реальные транки
        AND (
            -- 1. Классический + короткие городские номера
            (LENGTH(TRIM(src)) BETWEEN 3 AND 6 
             AND channel REGEXP '^(PJSIP|SIP)/[0-9a-zA-Z]{2,6}-')
            
            OR 
            -- 2. Click-to-Call / WebRTC / браузер
            (LENGTH(TRIM(src)) >= 10 
             AND channel REGEXP '^(PJSIP|SIP)/[0-9]{3,}-')
            
            OR 
            -- 3. Через outbound_cnum
            LENGTH(TRIM(outbound_cnum)) >= 7
        )
    ";

    $didFilter = '';
    if ($did !== '') {
        $didFilter = " AND $didParser = ?";
        $params[] = $did;
    }

    $summaryQuery = "
        SELECT 
            did,
            COUNT(DISTINCT linkedid) as calls,
            SUM(CASE WHEN billsec > 0 THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN billsec = 0 THEN 1 ELSE 0 END) as missed,
            SUM(billsec) as total_duration,
            ROUND(AVG(NULLIF(billsec, 0)), 0) as avg_duration,
            COUNT(DISTINCT ext) as unique_ext
        FROM (
            SELECT 
                linkedid,
                $didParser as did,
                SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) as ext,
                billsec
            FROM cdr 
            WHERE calldate BETWEEN ? AND ?
              AND $outboundWhere
              $didFilter
            GROUP BY linkedid
        ) sub
        WHERE did != '' AND CHAR_LENGTH(did) >= 5          -- отсекаем внутренние
        GROUP BY did
        ORDER BY calls DESC
    ";

    $statsQuery = "
        SELECT 
            HOUR(calldate) as hour, 
            COUNT(DISTINCT linkedid) as calls,
            SUM(CASE WHEN billsec > 0 THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN billsec = 0 THEN 1 ELSE 0 END) as missed,
            SUM(billsec) as total_duration,
            ROUND(AVG(NULLIF(billsec, 0)), 0) as avg_duration
        FROM cdr 
        WHERE calldate BETWEEN ? AND ?
          AND $outboundWhere
          $didFilter
        GROUP BY HOUR(calldate) 
        ORDER BY hour
    ";

    try {
        $sth = $this->db->prepare($summaryQuery);
        $sth->execute($params);
        $summary = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $sth = $this->db->prepare($statsQuery);
        $sth->execute($params);
        $stats = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $count = count($summary);
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getOutboundDidStats() → найдено $count исходящих DID (v8 — универсальный парсер + отсечка внутренних ног)\n", FILE_APPEND);

        return ['stats' => $stats, 'did_summary' => $summary];

    } catch (\Exception $e) {
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getOutboundDidStats ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['stats' => [], 'did_summary' => []];
    }
}

public function getMissedInboundCalls($start, $end) {
    $startTime = $start . ' 00:00:00';
    $endTime   = $end . ' 23:59:59';

    $sql = "
        SELECT 
            linkedid,
            MIN(calldate) as calldate,
            MAX(clid) as clid,
            MIN(src) as src,
            MAX(dst) as dst,
            COALESCE(MAX(did), 
                (SELECT did FROM cdr sub 
                 WHERE sub.linkedid = c.linkedid 
                   AND did != '' 
                 LIMIT 1)
            ) as did,
            MAX(duration) as wait_time,
            'NO ANSWER' as disposition
        FROM (
            SELECT 
                linkedid,
                calldate,
                clid,
                src,
                dst,
                did,
                duration
            FROM cdr c
            WHERE calldate BETWEEN :start AND :end 
              AND did != '' 
              AND LENGTH(dst) < 8
              AND LENGTH(src) > 6
              AND (outbound_cnum = '' OR outbound_cnum IS NULL)
              AND channel NOT LIKE '%FMGL-%'
              AND channel NOT LIKE '%followme%'
              AND NOT EXISTS (
                  SELECT 1 FROM cdr a 
                  WHERE a.linkedid = c.linkedid 
                    AND LENGTH(a.dst) BETWEEN 3 AND 6
                    AND a.disposition = 'ANSWERED'
              )
        ) c
        GROUP BY linkedid
        ORDER BY MIN(calldate) DESC
    ";

    try {
        $sth = $this->db->prepare($sql);
        $sth->execute([':start' => $startTime, ':end' => $endTime]);
        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $count = count($rows);
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " [MISSED] найдено " . $count . " пропущенных входящих (логика как в таблице Состав статистики)\n", FILE_APPEND);
        return $rows;

    } catch (\PDOException $e) {
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " [MISSED] ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        return [];
    }
}

    public function showPage() {
        if (isset($_REQUEST['export']) && $_REQUEST['export'] === 'csv' && isset($_REQUEST['view']) && $_REQUEST['view'] === 'grid_stats') {
            $this->exportCsv();
        }

        $view = $_REQUEST['view'] ?? 'grid_stats';
        $subhead = _("Custom CDR Stats");
        $content = '';

        switch ($view) {
            case 'grid_stats':
                list($startDate, $endDate) = $this->parseDateRange();
                $extension = $_REQUEST['ext'] ?? '';
                $extRange = $_REQUEST['ext_range'] ?? '';
                $queue = $_REQUEST['queue'] ?? '';

                $filter = [];
                if ($extension) $filter['extension'] = $extension;
                if ($extRange) $filter['ext_range'] = $extRange;
                if ($queue) $filter['queue'] = $queue;

                $data = $this->getCallStats($startDate, $endDate, $filter);
                $extensionsList = $this->getExtensions();
                $queuesList = $this->getQueues();
                $content = load_view(__DIR__ . '/views/grid_stats.php', [
                    'data' => $data,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'ext' => $extension,
                    'extRange' => $extRange,
                    'queue' => $queue,
                    'extensionsList' => $extensionsList,
                    'queuesList' => $queuesList
                ]);
                break;

            case 'outbound_did_stats':
                list($startDate, $endDate) = $this->parseDateRange();
                $did = $_REQUEST['did'] ?? '';

                $data = $this->getOutboundDidStats($startDate, $endDate, $did);
                $didsList = $this->getDids();

                
                if (empty($did) && !empty($data['did_summary'])) {
                    usort($data['did_summary'], fn($a, $b) => $b['calls'] <=> $a['calls']);
                }

                $content = load_view(__DIR__ . '/views/outbound_did_stats.php', [
                    'data'       => $data,
                    'statsByHour'=> $data['stats'] ?? [],          
                    'startDate'  => $startDate,
                    'endDate'    => $endDate,
                    'did'        => $did,
                    'didsList'   => $didsList
                ]);
                break;


            case 'per_ext_stats':
                list($startDate, $endDate) = $this->parseDateRange();
                $extension = $_REQUEST['ext'] ?? '';
                $data = $extension ? $this->getPerExtStats($startDate, $endDate, $extension) : [];
                $extensionsList = $this->getExtensions();
                $content = load_view(__DIR__ . '/views/per_ext_stats.php', [
                    'data' => $data,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'extension' => $extension,
                    'extensionsList' => $extensionsList
                ]);
                break;

            case 'did_stats':
                list($startDate, $endDate) = $this->parseDateRange();
                $did = $_REQUEST['did'] ?? '';
                $data = $this->getDidStats($startDate, $endDate, $did);
                $didsList = $this->getDids();
                $content = load_view(__DIR__ . '/views/did_stats.php', [
                    'data' => $data['stats'],
                    'didSummary' => $data['did_summary'],
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'did' => $did,
                    'didsList' => $didsList
                ]);
                break;

            case 'queue_stats':
                list($startDate, $endDate) = $this->parseDateRange();
                $queue = $_REQUEST['queue'] ?? '';
                $data = $queue ? $this->getCallStats($startDate, $endDate, ['queue' => $queue]) : [];
                $queuesList = $this->getQueues();
                $content = load_view(__DIR__ . '/views/queue_stats.php', [
                    'data' => $data,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'queue' => $queue,
                    'queuesList' => $queuesList
                ]);
                break;

            case 'no_call_stats':
                list($startDate, $endDate) = $this->parseDateRange();
                $noCallExtensions = $this->getNoCallExtensions($startDate, $endDate);
                $content = load_view(__DIR__ . '/views/no_call_stats.php', [
                    'noCallExtensions' => $noCallExtensions,
                    'startDate' => $startDate,
                    'endDate' => $endDate
                ]);
                break;

            case 'get_unique_exts':
               list($startDate, $endDate) = $this->parseDateRange();
                $did = $_REQUEST['did'] ?? '';
            
            // логирование
            $logDid = isset($did) && $did !== '' ? $did : '—';
            $logRange = $_REQUEST['daterange'] ?? '—';
            
            file_put_contents(
                $this->logPath,
                date('Y-m-d H:i:s') . " AJAX get_unique_exts called | did=$logDid | daterange=$logRange\n",
                FILE_APPEND
            );

            if ($did !== '') {
                $exts = $this->getUniqueExtensionsForDid($startDate, $endDate, $did);
                header('Content-Type: application/json');
                echo json_encode(['extensions' => $exts]);
                exit;
            }
            break;

            case 'missed_inbound':
                list($startDate, $endDate) = $this->parseDateRange();
                $data = $this->getMissedInboundCalls($startDate, $endDate);
                $content = load_view(__DIR__ . '/views/missed_inbound.php', [
                    'data'      => $data,
                    'startDate' => $startDate,
                    'endDate'   => $endDate
                ]);
                break;                

            default:
                $content = load_view(__DIR__ . '/views/grid_stats.php', []);
                break;
        }

        $serverName = gethostname();
        return load_view(__DIR__ . '/views/default.php', [
            'subhead' => $subhead,
            'content' => $content,
            'serverName' => $serverName
        ]);
    }
}