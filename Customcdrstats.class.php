<?php
namespace FreePBX\modules;

class Customcdrstats implements \BMO {
    public $FreePBX;
    private $db;
    private $logPath = '/var/log/asterisk/customcdrstats.log';

    public function __construct($freepbx = null) {
        if ($freepbx == null) {
            throw new \Exception("Not given a FreePBX Object");
        }
        $this->FreePBX = $freepbx;
        $conf = [];
        if (file_exists('/etc/freepbx.conf')) {
            $lines = file('/etc/freepbx.conf');
            foreach ($lines as $line) {
                if (preg_match('/\$amp_conf\[[\'"](.*?)[\'"]\] = [\'"](.*?)[\'"];/', $line, $matches)) {
                    $conf[$matches[1]] = $matches[2];
                }
            }
        }
        $user = isset($conf['AMPDBUSER']) ? $conf['AMPDBUSER'] : 'freepbxuser';
        $pass = isset($conf['AMPDBPASS']) ? $conf['AMPDBPASS'] : '';
        $host = isset($conf['AMPDBHOST']) ? $conf['AMPDBHOST'] : '127.0.0.1';
        $port = isset($conf['AMPDBPORT']) ? $conf['AMPDBPORT'] : '3306';
        $dbname = 'asteriskcdrdb';
        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname";
            $this->db = new \PDO($dsn, $user, $pass);
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Database initialized: $dsn with user $user\n", FILE_APPEND);
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Failed to connect to $dbname: " . $e->getMessage() . "\nUsing FreePBX default DB\n", FILE_APPEND);
            $this->db = $freepbx->Database;
        }
    }

    public function install() {
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Install method called\n", FILE_APPEND);
    }
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
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getExtensions result: " . count($list) . " extensions\n", FILE_APPEND);
        return $list;
    }

    public function getDids() {
        try {
            $sql = "SELECT DISTINCT extension FROM asterisk.incoming WHERE extension != '' ORDER BY extension";
            $sth = $this->db->query($sql);
            $dids = $sth->fetchAll(\PDO::FETCH_COLUMN);
            $list = [];
            foreach ($dids as $did) {
                $list[$did] = $did;
            }
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDids result: " . print_r($list, true) . "\n", FILE_APPEND);
            return $list;
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error in getDids: " . $e->getMessage() . "\n", FILE_APPEND);
            return [];
        }
    }

    public function getQueues() {
        try {
            $sql = "SELECT extension FROM asterisk.queues_config ORDER BY extension";
            $sth = $this->db->query($sql);
            $queues = $sth->fetchAll(\PDO::FETCH_COLUMN);
            $list = [];
            foreach ($queues as $queue) {
                $list[$queue] = $queue;
            }
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getQueues result: " . print_r($list, true) . "\n", FILE_APPEND);
            return $list;
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error in getQueues: " . $e->getMessage() . "\n", FILE_APPEND);
            return [];
        }
    }

    public function getCallStats($start, $end, $filter = []) {
        $startTime = $start . ' 00:00:00';
        $endTime = $end . ' 23:59:59';
        $where = "calldate BETWEEN :start AND :end";
        $params = [':start' => $startTime, ':end' => $endTime];
        $useLinkedIdFilter = false;
        $linkedIds = [];
        if (isset($filter['extension']) && $filter['extension']) {
            $where .= " AND (src = :ext OR dst = :ext)";
            $params[':ext'] = $filter['extension'];
        }
        if (isset($filter['ext_range']) && $filter['ext_range']) {
            $where .= " AND (src LIKE :ext_range OR dst LIKE :ext_range)";
            $params[':ext_range'] = $filter['ext_range'] . '%';
        }
        if (isset($filter['queue']) && $filter['queue']) {
            // For queue filter, first get linkedids where dst = queue
            $linkedWhere = "calldate BETWEEN :start AND :end AND dst = :queue";
            $linkedParams = [':start' => $startTime, ':end' => $endTime, ':queue' => $filter['queue']];
            $linkedSql = "SELECT DISTINCT linkedid FROM cdr WHERE $linkedWhere";
            try {
                $sth = $this->db->prepare($linkedSql);
                $sth->execute($linkedParams);
                $linkedIds = $sth->fetchAll(\PDO::FETCH_COLUMN);
                if (empty($linkedIds)) {
                    return ['stats' => ['total_calls' => 0, 'answered' => 0, 'missed' => 0, 'avg_duration' => 0.0000, 'internal' => 0, 'inbound' => 0, 'outbound' => 0], 'by_ext' => []];
                }
                $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
                $where = "calldate BETWEEN ? AND ? AND linkedid IN ($placeholders)";
                $params = [$startTime, $endTime];
                $params = array_merge($params, $linkedIds);
                $useLinkedIdFilter = true;
            } catch (\PDOException $e) {
                file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error fetching linkedids for queue: " . $e->getMessage() . "\n", FILE_APPEND);
                return ['stats' => ['total_calls' => 0, 'answered' => 0, 'missed' => 0, 'avg_duration' => 0.0000, 'internal' => 0, 'inbound' => 0, 'outbound' => 0], 'by_ext' => []];
            }
        }
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getCallStats called with params: start=$start, end=$end, filter=" . print_r($filter, true) . "\n", FILE_APPEND);
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " SQL where: $where\nParams: " . print_r($params, true) . (isset($filter['queue']) ? "\nLinkedIds count: " . count($linkedIds) : "") . "\n", FILE_APPEND);

        $sql = "SELECT * FROM cdr WHERE $where ORDER BY calldate";
        try {
            $sth = $this->db->prepare($sql);
            $sth->execute($params);
            $raw = $sth->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error in getCallStats: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['stats' => [], 'by_ext' => []];
        }

        $grouped = [];
        foreach ($raw as $row) {
            $linkedid = $row['linkedid'];
            if (!isset($grouped[$linkedid])) {
                $grouped[$linkedid] = [];
            }
            $grouped[$linkedid][] = $row;
        }

        $byExt = [];
        $stats = ['total_calls' => 0, 'answered' => 0, 'missed' => 0, 'avg_duration' => 0.0000, 'internal' => 0, 'inbound' => 0, 'outbound' => 0];
        $sumDuration = 0;

        foreach ($grouped as $linkedid => $rows) {
            // Сортируем rows по calldate, чтобы первая была самой ранней
            usort($rows, function($a, $b) {
                return strtotime($a['calldate']) - strtotime($b['calldate']);
            });
            $firstRow = $rows[0]; // Первая запись в группе - начало звонка

            $srcs = array_column($rows, 'src');
            $dsts = array_column($rows, 'dst');
            $src_ext = min($srcs);
            $dst_ext = max(array_column($rows, 'billsec')) > 0 ? max(array_filter($dsts)) : min($dsts);
            $maxBillsec = max(array_column($rows, 'billsec'));
            $answered = 0;
            $missed = 0;
            $hasAgent = false;
            foreach ($rows as $row) {
                if ($row['lastapp'] == 'Dial' && $row['billsec'] > 0) {
                    $hasAgent = true;
                    break;
                }
            }
            if ($hasAgent) {
                $answered = 1;
            } else {
                $missed = 1;
            }
            if ($maxBillsec > 0 && $maxBillsec < 5 && !$hasAgent) {
                $missed = 1;
                $answered = 0;
            }
            $internal = (strlen($src_ext) < 8 && strlen($dst_ext) < 8) ? 1 : 0;
            $inbound = (strlen($src_ext) > 8) ? 1 : 0;
            $outbound = (strlen($dst_ext) > 8 && strlen($src_ext) < 8) ? 1 : 0;

            $byExt[] = [
                'operator_type' => 'Dial',
                'src_ext' => $src_ext,
                'dst_ext' => $dst_ext,
                'calls' => 1,
                'total_duration' => $maxBillsec,
                'avg_duration' => $maxBillsec,
                'answered' => $answered,
                'missed' => $missed,
                'call_date' => $firstRow['calldate'] // Добавляем дату звонка (начало)
            ];

            $stats['total_calls'] += 1;
            $stats['answered'] += $answered;
            $stats['missed'] += $missed;
            $sumDuration += $maxBillsec;
            $stats['internal'] += $internal;
            $stats['inbound'] += $inbound;
            $stats['outbound'] += $outbound;
        }

        if ($stats['total_calls'] > 0) {
            $stats['avg_duration'] = $sumDuration / $stats['total_calls'];
        }

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Total stats result: " . print_r($stats, true) . "\n", FILE_APPEND);
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " ByExt result count: " . count($byExt) . " | Raw: " . print_r($byExt, true) . "\n", FILE_APPEND);

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
            if (!isset($grouped[$linkedid])) {
                $grouped[$linkedid] = [];
            }
            $grouped[$linkedid][] = $row;
        }

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = [
                'hour' => $h,
                'calls' => 0,
                'inbound_external' => 0,
                'outbound_external' => 0,
                'inbound_internal' => 0,
                'outbound_internal' => 0,
                'answered' => 0,
                'missed' => 0,
                'total_duration' => 0
            ];
        }

        foreach ($grouped as $linkedid => $rows) {
            // Сортируем rows по calldate для определения первой записи
            usort($rows, function($a, $b) {
                return strtotime($a['calldate']) - strtotime($b['calldate']);
            });
            $orig_row = $rows[0]; // Первая запись в группе

            $hour = (int) substr($orig_row['calldate'], 11, 2);
            $orig_src = $orig_row['src'];
            $orig_dst = $orig_row['dst'];

            $call_type = '';
            $other = '';
            if ($orig_src == $ext) {
                $call_type = 'outbound';
                $other = $orig_dst;
            } elseif ($orig_dst == $ext) {
                $call_type = 'inbound';
                $other = $orig_src;
            } else {
                continue;
            }

            $is_external = strlen((string) $other) > 4;
            $type_key = '';
            if ($call_type == 'outbound') {
                $type_key = $is_external ? 'outbound_external' : 'outbound_internal';
            } else {
                $type_key = $is_external ? 'inbound_external' : 'inbound_internal';
            }

            $hasAgent = false;
            $max_billsec = 0;
            foreach ($rows as $row) {
                if ($row['lastapp'] == 'Dial' && $row['billsec'] > 0) {
                    $hasAgent = true;
                }
                if ($row['billsec'] > $max_billsec) {
                    $max_billsec = $row['billsec'];
                }
            }

            $answered = $hasAgent ? 1 : 0;
            $missed = $hasAgent ? 0 : 1;
            if ($max_billsec > 0 && $max_billsec < 5 && !$hasAgent) {
                $missed = 1;
                $answered = 0;
            }

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
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " didStats for " . ($did ? $did : 'all') . ": " . print_r($stats, true) . "\n", FILE_APPEND);
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " didSummary: " . print_r($summary, true) . "\n", FILE_APPEND);
            return ['stats' => $stats, 'did_summary' => $summary];
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Error in getDidStats: " . $e->getMessage() . "\n", FILE_APPEND);
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
        $view = isset($_REQUEST['view']) ? $_REQUEST['view'] : '';
        $subhead = _("Custom CDR Stats");
        switch ($view) {
            case 'grid_stats':
                $startDate = isset($_REQUEST['start']) ? $_REQUEST['start'] : date('Y-m-d');
                $endDate = isset($_REQUEST['end']) ? $_REQUEST['end'] : date('Y-m-d');
                $extension = isset($_REQUEST['ext']) ? $_REQUEST['ext'] : '';
                $extRange = isset($_REQUEST['ext_range']) ? $_REQUEST['ext_range'] : '';
                $queue = isset($_REQUEST['queue']) ? $_REQUEST['queue'] : '';

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
            case 'per_ext_stats':
                $startDate = isset($_REQUEST['start']) ? $_REQUEST['start'] : date('Y-m-d');
                $endDate = isset($_REQUEST['end']) ? $_REQUEST['end'] : date('Y-m-d');
                $extension = isset($_REQUEST['ext']) ? $_REQUEST['ext'] : '';

                $data = $extension ? $this->getPerExtStats($startDate, $endDate, $extension) : [];
                $extensionsList = $this->getExtensions();
                $debug = [];
                if (!$extension) {
                    $debug = ['sql' => 'SELECT * FROM cdr WHERE calldate BETWEEN :start AND :end AND (src = :ext OR dst = :ext) ORDER BY calldate', 'params' => ['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59', 'ext' => $extension]];
                }
                $content = load_view(__DIR__ . '/views/per_ext_stats.php', [
                    'data' => $data,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'extension' => $extension,
                    'extensionsList' => $extensionsList,
                    'debug' => $debug
                ]);
                break;
            case 'did_stats':
                $startDate = isset($_REQUEST['start']) ? $_REQUEST['start'] : date('Y-m-d');
                $endDate = isset($_REQUEST['end']) ? $_REQUEST['end'] : date('Y-m-d');
                $did = isset($_REQUEST['did']) ? $_REQUEST['did'] : '';

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
                $startDate = isset($_REQUEST['start']) ? $_REQUEST['start'] : date('Y-m-d');
                $endDate = isset($_REQUEST['end']) ? $_REQUEST['end'] : date('Y-m-d');
                $queue = isset($_REQUEST['queue']) ? $_REQUEST['queue'] : '';

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
                $startDate = isset($_REQUEST['start']) ? $_REQUEST['start'] : date('Y-m-d');
                $endDate = isset($_REQUEST['end']) ? $_REQUEST['end'] : date('Y-m-d');
                $noCallExtensions = $this->getNoCallExtensions($startDate, $endDate);
                $content = load_view(__DIR__ . '/views/no_call_stats.php', [
                    'noCallExtensions' => $noCallExtensions,
                    'startDate' => $startDate,
                    'endDate' => $endDate
                ]);
                break;
            default:
                $content = load_view(__DIR__ . '/views/grid_stats.php', []);
                break;
        }

        $serverName = gethostname();
        return load_view(__DIR__ . '/views/default.php', ['subhead' => $subhead, 'content' => $content, 'serverName' => $serverName]);
    }
}