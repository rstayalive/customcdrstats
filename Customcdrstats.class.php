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

        $query = "
            SELECT DISTINCT 
                TRIM(
                    CASE 
                        WHEN channel LIKE 'Local/%@%' 
                            THEN SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '@', 1), '/', -1)
                        WHEN channel LIKE 'PJSIP/%' OR channel LIKE 'SIP/%' 
                            THEN SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1)
                        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1)
                    END
                ) as extension
            FROM cdr 
            WHERE calldate BETWEEN :start AND :end 
              AND outbound_cnum = :did 
              AND LENGTH(outbound_cnum) >= 7
              AND channel NOT LIKE '%FMGL-%'           -- ← вот главное исправление
              AND channel NOT LIKE '%followme%'        -- дополнительная защита
            ORDER BY extension
        ";

        $params = [':start' => $startTime, ':end' => $endTime, ':did' => $did];

        try {
            $sth = $this->db->prepare($query);
            $sth->execute($params);
            $result = $sth->fetchAll(\PDO::FETCH_COLUMN, 0);

            
            $filtered = [];
            foreach ($result as $ext) {
                $ext = trim($ext);
                // Оставляем только короткие внутренние номера (3-5 цифр) или PJSIP/SIP без длинных 89...
                if (strlen($ext) <= 5 || (strlen($ext) > 5 && !preg_match('/^89|^79/', $ext))) {
                    $filtered[] = $ext;
                }
            }

            return array_unique($filtered);   // убираем возможные дубли
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getUniqueExtensionsForDid error: " . $e->getMessage() . "\n", FILE_APPEND);
            return [];
        }
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
            $sql = "SELECT DISTINCT extension FROM incoming WHERE extension != '' ORDER BY extension";
            $sth = $this->configDb->query($sql);
            $dids = $sth->fetchAll(\PDO::FETCH_COLUMN);
            $list = [];
            foreach ($dids as $did) $list[$did] = $did;
            return $list;
        } catch (\Exception $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDids error: " . $e->getMessage() . "\n", FILE_APPEND);
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

        $where = "calldate BETWEEN :start AND :end";
        $params = [':start' => $startTime, ':end' => $endTime];

        if (!empty($filter['extension'])) {
            $where .= " AND (src = :ext OR dst = :ext)";
            $params[':ext'] = $filter['extension'];
        }
        if (!empty($filter['ext_range'])) {
            $where .= " AND (src LIKE :range OR dst LIKE :range)";
            $params[':range'] = $filter['ext_range'] . '%';
        }
        if (!empty($filter['queue'])) {
            $linkedSql = "SELECT DISTINCT linkedid FROM cdr WHERE calldate BETWEEN :start AND :end AND dst = :queue";
            $sth = $this->db->prepare($linkedSql);
            $sth->execute([':start' => $startTime, ':end' => $endTime, ':queue' => $filter['queue']]);
            $linkedIds = $sth->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($linkedIds)) {
                return ['stats' => ['total_calls'=>0,'answered'=>0,'missed'=>0,'avg_duration'=>0,'internal'=>0,'inbound'=>0,'outbound'=>0], 'by_ext'=>[]];
            }
            $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
            $where = "calldate BETWEEN ? AND ? AND linkedid IN ($placeholders)";
            $params = array_merge([$startTime, $endTime], $linkedIds);
        }

        $sql = "SELECT * FROM cdr WHERE $where ORDER BY calldate";
        $sth = $this->db->prepare($sql);
        $sth->execute($params);
        $raw = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($raw as $row) {
            $grouped[$row['linkedid']][] = $row;
        }

        $byExt = [];
        $stats = ['total_calls'=>0,'answered'=>0,'missed'=>0,'avg_duration'=>0,'internal'=>0,'inbound'=>0,'outbound'=>0];
        $sumDuration = 0;

        foreach ($grouped as $rows) {
            usort($rows, fn($a,$b) => strtotime($a['calldate']) - strtotime($b['calldate']));
            $first = $rows[0];

            $srcs = array_filter(array_column($rows, 'src'));
            $dsts = array_filter(array_column($rows, 'dst'));
            $src_ext = $srcs ? min($srcs) : '';
            $dst_ext = $dsts ? max($dsts) : '';

            $maxBillsec = max(array_column($rows, 'billsec') ?: [0]);
            $answered = 0;
            foreach ($rows as $r) {
                if ($r['disposition'] === 'ANSWERED' || $r['billsec'] > 0) {
                    $answered = 1;
                    break;
                }
            }
            $missed = $answered ? 0 : 1;
            if ($maxBillsec > 0 && $maxBillsec < 5 && !$answered) {
                $missed = 1; $answered = 0;
            }

            $internal = (strlen($src_ext) < 8 && strlen($dst_ext) < 8) ? 1 : 0;
            $inbound  = (strlen($src_ext) > 7) ? 1 : 0;
            $outbound = (strlen($dst_ext) > 7 && strlen($src_ext) < 8) ? 1 : 0;

            $byExt[] = [
                'operator_type' => 'Dial',
                'src_ext'       => $src_ext,
                'dst_ext'       => $dst_ext,
                'calls'         => 1,
                'total_duration'=> $maxBillsec,
                'avg_duration'  => $maxBillsec,
                'answered'      => $answered,
                'missed'        => $missed,
                'call_date'     => $first['calldate']
            ];

            $stats['total_calls']++;
            $stats['answered'] += $answered;
            $stats['missed']   += $missed;
            $sumDuration       += $maxBillsec;
            $stats['internal'] += $internal;
            $stats['inbound']  += $inbound;
            $stats['outbound'] += $outbound;
        }

        if ($stats['total_calls'] > 0) {
            $stats['avg_duration'] = round($sumDuration / $stats['total_calls'], 2);
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
        $endTime = $end . ' 23:59:59';
        $where = "calldate BETWEEN :start AND :end";
        $params = [':start' => $startTime, ':end' => $endTime];
        if ($did) {
            $where .= " AND did = :did";
            $params[':did'] = $did;
        }
        $statsQuery = "SELECT HOUR(calldate) as hour, COUNT(*) as calls, SUM(duration) as total_duration, AVG(duration) as avg_duration, SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered, SUM(CASE WHEN disposition = 'NO ANSWER' OR disposition = 'BUSY' THEN 1 ELSE 0 END) as missed, COUNT(*) as inbound FROM cdr WHERE $where AND did != '' GROUP BY HOUR(calldate)";
        $summaryQuery = "SELECT did, COUNT(*) as calls, SUM(duration) as total_duration, AVG(duration) as avg_duration, SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered, SUM(CASE WHEN disposition = 'NO ANSWER' OR disposition = 'BUSY' THEN 1 ELSE 0 END) as missed, COUNT(*) as inbound FROM cdr WHERE $where AND did != '' GROUP BY did";
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

        $where  = "calldate BETWEEN :start AND :end 
                   AND outbound_cnum != '' 
                   AND LENGTH(outbound_cnum) >= 7
                   AND channel NOT LIKE '%FMGL-%'           -- исключаем follow-me
                   AND channel NOT LIKE '%followme%'";

        $params = [':start' => $startTime, ':end' => $endTime];

        if ($did !== '') {
            $where .= " AND outbound_cnum = :did";
            $params[':did'] = $did;
        }

        $statsQuery = "
            SELECT 
                HOUR(calldate) as hour,
                COUNT(*) as calls,
                SUM(billsec) as total_duration,
                ROUND(AVG(NULLIF(billsec,0)), 0) as avg_duration,
                SUM(CASE WHEN disposition = 'ANSWERED' OR billsec > 0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN disposition != 'ANSWERED' AND billsec = 0 THEN 1 ELSE 0 END) as missed
            FROM cdr 
            WHERE $where 
            GROUP BY HOUR(calldate)
            ORDER BY hour
        ";

        $summaryQuery = "
            SELECT 
                outbound_cnum as did,
                COUNT(*) as calls,
                SUM(billsec) as total_duration,
                ROUND(AVG(NULLIF(billsec,0)), 0) as avg_duration,
                SUM(CASE WHEN disposition = 'ANSWERED' OR billsec > 0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN disposition != 'ANSWERED' AND billsec = 0 THEN 1 ELSE 0 END) as missed,
                COUNT(DISTINCT 
                    TRIM(
                        CASE 
                            WHEN channel LIKE 'Local/%@%' 
                                THEN SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '@', 1), '/', -1)
                            WHEN channel LIKE 'PJSIP/%' OR channel LIKE 'SIP/%' 
                                THEN SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1)
                            ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1)
                        END
                    )
                ) as unique_ext
            FROM cdr 
            WHERE $where 
            GROUP BY outbound_cnum 
            ORDER BY calls DESC
        ";

try {
            $sth = $this->db->prepare($statsQuery);
            $sth->execute($params);
            $stats = $sth->fetchAll(\PDO::FETCH_ASSOC);

            $sth = $this->db->prepare($summaryQuery);
            $sth->execute($params);
            $summary = $sth->fetchAll(\PDO::FETCH_ASSOC);

           
            foreach ($summary as &$row) {
                $uniqueExts = $this->getUniqueExtensionsForDid($start, $end, $row['did']);
                $row['unique_ext'] = count($uniqueExts);
            }
            unset($row);

            return ['stats' => $stats, 'did_summary' => $summary];
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getOutboundDidStats error: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['stats' => [], 'did_summary' => []];
        }
    }

    public function getNoCallExtensions($start, $end) {
        $startTime = $start . ' 00:00:00';
        $endTime = $end . ' 23:59:59';
        $extensions = $this->getExtensions();
        $sql = "SELECT DISTINCT src FROM cdr WHERE calldate BETWEEN :start AND :end AND LENGTH(src) < 8 UNION SELECT DISTINCT dst FROM cdr WHERE calldate BETWEEN :start AND :end AND LENGTH(dst) < 8";
        $params = [':start' => $startTime, ':end' => $endTime];
        try {
            $sth = $this->db->prepare($sql);
            $sth->execute($params);
            $active = $sth->fetchAll(\PDO::FETCH_COLUMN);
            $noCall = array_diff(array_keys($extensions), $active);
            return array_intersect_key($extensions, array_flip($noCall));
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error in getNoCallExtensions: " . $e->getMessage() . "\n", FILE_APPEND);
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
                    $allowedDids = array_keys($didsList);
                    $data['did_summary'] = array_filter($data['did_summary'], function($row) use ($allowedDids) {
                        $cleanDid = ltrim($row['did'], '+');
                        return in_array($row['did'], $allowedDids) || in_array($cleanDid, $allowedDids);
                    });
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
                if (!empty($did)) {
                    $exts = $this->getUniqueExtensionsForDid($startDate, $endDate, $did);
                    header('Content-Type: application/json');
                    echo json_encode(['extensions' => $exts]);
                    exit;
                }
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