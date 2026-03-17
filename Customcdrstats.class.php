<?php
namespace FreePBX\modules;

class Customcdrstats implements \BMO {
    public $FreePBX;
    private $db;
    private $configDb;
    private $logPath = '/var/log/asterisk/customcdrstats.log';

    public function __construct($freepbx = null) {
        if ($freepbx == null) $freepbx = \FreePBX::create();
        $this->FreePBX = $freepbx;
        $this->configDb = $freepbx->Database;

        $conf = [];
        if (file_exists('/etc/freepbx.conf')) {
            foreach (file('/etc/freepbx.conf') as $line) {
                if (preg_match('/\$amp_conf\[[\'"](.*?)[\'"]\] = [\'"](.*?)[\'"];/', $line, $m)) {
                    $conf[$m[1]] = $m[2];
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
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " CDR DB connected\n", FILE_APPEND);
        } catch (\PDOException $e) {
            file_put_contents($this->logPath, date('Y-m-d H:i:s') . " CRITICAL: " . $e->getMessage() . "\n", FILE_APPEND);
            throw new \Exception("Не удалось подключиться к CDR DB");
        }
    }

    private function parseDateRange() {
        $daterange = trim($_REQUEST['daterange'] ?? '');
        if (strpos($daterange, ' - ') !== false) {
            [$start, $end] = array_map('trim', explode(' - ', $daterange, 2));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                return [$start, $end];
            }
        }
        return [$_REQUEST['start'] ?? date('Y-m-d'), $_REQUEST['end'] ?? date('Y-m-d')];
    }

    // ====================== ВСПОМОГАТЕЛЬНЫЕ ======================
    private function getDidParserSql() {
        return "TRIM(CASE WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', -1) REGEXP '^[0-9a-fA-F]{5,}$'
                THEN LEFT(SUBSTRING_INDEX(dstchannel, '/', -1), LENGTH(SUBSTRING_INDEX(dstchannel, '/', -1)) 
                - LENGTH(SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', -1)) - 1)
                ELSE SUBSTRING_INDEX(dstchannel, '/', -1) END)";
    }

    private function parseOutboundDid($dstchannel) {
        if (empty($dstchannel)) return '';
        $parts = explode('/', $dstchannel);
        $last = trim(end($parts));
        return preg_match('/^(.+?)-[0-9a-fA-F]{5,}$/', $last, $m) ? trim($m[1]) : $last;
    }

    // ====================== ОСНОВНЫЕ МЕТОДЫ ======================
    public function getUniqueExtensionsForDid($start, $end, $did) {
        $startTime = $start . ' 00:00:00';
        $endTime   = $end . ' 23:59:59';

        $didParser = $this->getDidParserSql();

        $query = "
            SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) as extension
            FROM cdr 
            WHERE calldate BETWEEN :start AND :end 
              AND $didParser = :did
              AND channel NOT LIKE 'Local/%@from-internal%'
              AND channel NOT LIKE '%FMGL-%'
              AND channel NOT LIKE '%followme%'
              AND dstchannel REGEXP '^(PJSIP|SIP)/'
              AND (
                  (LENGTH(TRIM(src)) BETWEEN 3 AND 6 AND channel REGEXP '^(PJSIP|SIP)/[0-9a-zA-Z]{2,6}-')
                  OR (LENGTH(TRIM(src)) >= 10 AND channel REGEXP '^(PJSIP|SIP)/[0-9]{3,}-')
                  OR LENGTH(TRIM(outbound_cnum)) >= 7
              )
            HAVING LENGTH(extension) BETWEEN 3 AND 6 AND extension NOT REGEXP '^7[89]'
            ORDER BY extension
        ";

        $sth = $this->db->prepare($query);
        $sth->execute([':start' => $startTime, ':end' => $endTime, ':did' => $did]);
        $result = $sth->fetchAll(\PDO::FETCH_COLUMN);
        $unique = array_unique(array_filter(array_map('trim', $result)));

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getUniqueExtensionsForDid('$did') → " . count($unique) . " сотрудников\n", FILE_APPEND);
        return $unique;
    }

    public function getCallStats($start, $end, $filter = []) {
        $startTime = $start . ' 00:00:00';
        $endTime   = $end . ' 23:59:59';

        $whereParams = [$startTime, $endTime];
        $where = "calldate BETWEEN ? AND ?";

        if (!empty($filter['queue'])) {
            $linkedSql = "SELECT DISTINCT linkedid FROM cdr WHERE calldate BETWEEN ? AND ? AND dst = ?";
            $sth = $this->db->prepare($linkedSql);
            $sth->execute([$startTime, $endTime, $filter['queue']]);
            $linkedIds = $sth->fetchAll(\PDO::FETCH_COLUMN);
            if (empty($linkedIds)) return ['stats' => ['total_calls'=>0,'answered'=>0,'missed'=>0,'avg_duration'=>0,'internal'=>0,'inbound'=>0,'outbound'=>0], 'by_ext'=>[]];
            $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
            $where = "calldate BETWEEN ? AND ? AND linkedid IN ($placeholders)";
            $whereParams = array_merge([$startTime, $endTime], $linkedIds);
        }

        $sql = "SELECT linkedid, calldate, src, dst, did, disposition, billsec, duration, outbound_cnum, channel, lastapp 
                FROM cdr WHERE $where AND channel NOT LIKE '%FMGL-%' AND channel NOT LIKE '%followme%' 
                ORDER BY linkedid, calldate ASC";

        $sth = $this->db->prepare($sql);
        $sth->execute($whereParams);
        $rawRows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getCallStats → " . count($rawRows) . " строк\n", FILE_APPEND);
        return $this->processCdrRowsInPhp($rawRows);
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

    $sql = "
        SELECT 
            linkedid,
            calldate,
            src,
            dst,
            did,
            disposition,
            billsec,
            duration,
            channel
        FROM cdr 
        WHERE calldate BETWEEN ? AND ? 
          AND did != '' 
          AND (outbound_cnum = '' OR outbound_cnum IS NULL)
          AND channel NOT LIKE '%FMGL-%' 
          AND channel NOT LIKE '%followme%'";

    $params = [$startTime, $endTime];
    if ($did !== '') {
        $sql .= " AND did = ?";
        $params[] = $did;
    }

    try {
        $sth = $this->db->prepare($sql);
        $sth->execute($params);
        $rawRows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDidStats → один запрос вернул " . count($rawRows) . " строк → PHP processing\n", FILE_APPEND);

        return $this->processDidStatsInPhp($rawRows, $did);
    } catch (\PDOException $e) {
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDidStats ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['stats' => [], 'did_summary' => []];
    }
}

    public function getOutboundDidStats($start, $end, $did = '') {
        $startTime = $start . ' 00:00:00';
        $endTime   = $end . ' 23:59:59';

        $sql = "
            SELECT linkedid, calldate, src, dst, dstchannel, billsec, disposition, outbound_cnum, channel
            FROM cdr 
            WHERE calldate BETWEEN ? AND ?
              AND channel NOT LIKE 'Local/%@from-internal%'
              AND channel NOT LIKE '%FMGL-%' 
              AND channel NOT LIKE '%followme%'
              AND dstchannel REGEXP '^(PJSIP|SIP)/'
              AND (
                  (LENGTH(TRIM(src)) BETWEEN 3 AND 6 AND channel REGEXP '^(PJSIP|SIP)/[0-9a-zA-Z]{2,6}-')
                  OR (LENGTH(TRIM(src)) >= 10 AND channel REGEXP '^(PJSIP|SIP)/[0-9]{3,}-')
                  OR LENGTH(TRIM(outbound_cnum)) >= 7
              )
        ";

        $params = [$startTime, $endTime];
        if ($did !== '') {
            $sql .= " AND " . $this->getDidParserSql() . " = ?";
            $params[] = $did;
        }

        $sth = $this->db->prepare($sql);
        $sth->execute($params);
        $rawRows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getOutboundDidStats → " . count($rawRows) . " строк\n", FILE_APPEND);
        return $this->processOutboundDidStatsInPhp($rawRows, $did);
    }

    public function getOutboundDidsForPeriod($start, $end) {
        $data = $this->getOutboundDidStats($start, $end, '');
        $list = [];
        foreach ($data['did_summary'] ?? [] as $row) {
            $d = trim($row['did'] ?? '');
            if ($d) $list[$d] = $d;
        }
        ksort($list);
        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getOutboundDidsForPeriod → " . count($list) . " DID\n", FILE_APPEND);
        return $list;
    }

    public function getNoCallExtensions($start, $end) {
        $allExt = $this->getExtensions();
        $startTime = $start . ' 00:00:00';
        $endTime   = $end . ' 23:59:59';

        $sql = "SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) as ext 
                FROM cdr WHERE calldate BETWEEN ? AND ? AND channel REGEXP '^(PJSIP|SIP)/'";

        $sth = $this->db->prepare($sql);
        $sth->execute([$startTime, $endTime]);
        $used = $sth->fetchAll(\PDO::FETCH_COLUMN);

        $noCall = [];
        foreach ($allExt as $ext => $name) {
            if (!in_array($ext, $used)) $noCall[$ext] = $name;
        }
        return $noCall;
    }

public function getMissedInboundCalls($start, $end) {
    $startTime = $start . ' 00:00:00';
    $endTime   = $end . ' 23:59:59';

    $sql = "
        SELECT linkedid, calldate, clid, src, dst, did, disposition, billsec, duration, outbound_cnum, channel
        FROM cdr 
        WHERE calldate BETWEEN ? AND ?
          AND channel NOT LIKE '%FMGL-%'
          AND channel NOT LIKE '%followme%'
        ORDER BY linkedid, calldate ASC
    ";

    $sth = $this->db->prepare($sql);
    $sth->execute([$startTime, $endTime]);
    $rawRows = $sth->fetchAll(\PDO::FETCH_ASSOC);

    $byLinked = [];
    foreach ($rawRows as $row) {
        $lid = $row['linkedid'];
        if (!isset($byLinked[$lid])) $byLinked[$lid] = [];
        $byLinked[$lid][] = $row;
    }

    $missedDetails = [];

    foreach ($byLinked as $callRows) {
        usort($callRows, fn($a,$b) => strtotime($a['calldate']) <=> strtotime($b['calldate']));
        $first = $callRows[0];

        $src = trim($first['src'] ?? '');
        $dst = trim($first['dst'] ?? '');
        $did = trim($first['did'] ?? '');
        $outboundCnum = trim($first['outbound_cnum'] ?? '');

        $hasExternalCaller = false;
        $realDid = '';
        foreach ($callRows as $r) {
            if (strlen(trim($r['src'])) > 6) $hasExternalCaller = true;
            if (trim($r['did']) !== '') $realDid = trim($r['did']);
        }

        $isInbound  = ($realDid !== '' && $hasExternalCaller);
        $isOutbound = (!empty($outboundCnum) || (strlen($src) >= 3 && strlen($src) <= 6 && strlen($dst) >= 7));

        if (!$isInbound) continue;

        $answered = 0;
        foreach ($callRows as $r) {
            $dlen = strlen(trim($r['dst']));
            if ($dlen >= 3 && $dlen <= 6 && $r['disposition'] === 'ANSWERED') {
                $answered = 1;
                break;
            }
        }
        $missed = $answered ? 0 : 1;

        if ($missed) {
            $maxDuration = max(array_column($callRows, 'duration') ?: [0]);

            
            $to = ($dst === 's' || $dst === '') ? 'Повесил трубку' : $dst;

            $missedDetails[] = [
                'calldate'    => $first['calldate'],
                'clid'        => $first['clid'] ?? $first['src'],
                'src'         => $first['src'],
                'dst'         => $to,                   
                'did'         => $first['did'],
                'wait_time'   => $maxDuration,
                'disposition' => 'NO ANSWER',
                'linkedid'    => $first['linkedid']
            ];
        }
    }

    usort($missedDetails, fn($a,$b) => strtotime($a['calldate']) <=> strtotime($b['calldate']));

    file_put_contents($this->logPath, date('Y-m-d H:i:s') . 
        " getMissedInboundCalls → " . count($missedDetails) . " пропущенных (s заменён на 'Повесил трубку')\n", 
        FILE_APPEND);

    return $missedDetails;
}

    // ====================== ПРОЦЕССОРЫ ======================
private function processCdrRowsInPhp(array $rows): array {
    $byLinked = [];
    foreach ($rows as $row) {
        $lid = $row['linkedid'];
        if (!isset($byLinked[$lid])) $byLinked[$lid] = [];
        $byLinked[$lid][] = $row;
    }

    $stats = [
        'total_calls' => 0,
        'answered'    => 0,
        'missed'      => 0,
        'inbound'     => 0,
        'outbound'    => 0,
        'internal'    => 0,
        'total_duration' => 0
    ];

    $byExt = [];

    foreach ($byLinked as $linkedid => $callRows) {
        usort($callRows, fn($a,$b) => strtotime($a['calldate']) <=> strtotime($b['calldate']));

        $first = $callRows[0];
        $src = trim($first['src'] ?? '');
        $dst = trim($first['dst'] ?? '');
        $did = trim($first['did'] ?? '');
        $outboundCnum = trim($first['outbound_cnum'] ?? '');

        $maxBillsec = max(array_column($callRows, 'billsec') ?: [0]);

        $hasExternalCaller = false;
        $realDid = '';
        foreach ($callRows as $r) {
            if (strlen(trim($r['src'] ?? '')) > 6) $hasExternalCaller = true;
            if (trim($r['did'] ?? '') !== '') $realDid = trim($r['did']);
        }

        $isInbound  = ($realDid !== '' && $hasExternalCaller) ||
                      ($realDid !== '' && strlen(trim($first['dst'] ?? '')) <= 6 && empty($outboundCnum));

        $isOutbound = (!empty($outboundCnum) || 
                      (strlen($src) >= 3 && strlen($src) <= 6 && strlen($dst) >= 7));

        $isInternal = !$isInbound && !$isOutbound && strlen($src) < 8 && strlen($dst) < 8;

        // answered ТОЛЬКО ДЛЯ ВХОДЯЩИХ
        $answered = 0;
        if ($isInbound) {
            foreach ($callRows as $r) {
                $dlen = strlen(trim($r['dst']));
                if ($dlen >= 3 && $dlen <= 6 && $r['disposition'] === 'ANSWERED') {
                    $answered = 1;
                    break;
                }
            }
        } elseif ($isOutbound) {
            $answered = ($maxBillsec >= 5) ? 1 : 0;
        } else {
            $answered = ($maxBillsec >= 3) ? 1 : 0;
        }

        $missed = $answered ? 0 : 1;

        if ($isInbound) {
            $stats['inbound']++;
            $stats['answered'] += $answered;
            $stats['missed'] += $missed;
        } elseif ($isOutbound) {
            $stats['outbound']++;
        } else {
            $stats['internal']++;
        }

        $stats['total_calls'] = $stats['inbound'] + $stats['outbound'];   // без внутренних!
        $stats['total_duration'] += $maxBillsec;

        $srcExt = trim($src ?? '');
        $dstExt = trim($dst ?? '');

        $byExt[] = [
            'operator_type'   => $isOutbound ? 'Outbound' : ($isInbound ? 'Inbound' : 'Internal'),
            'src_ext'         => $srcExt,
            'dst_ext'         => $dstExt,
            'calls'           => 1,
            'total_duration'  => $maxBillsec,
            'avg_duration'    => $maxBillsec,
            'answered'        => $answered,
            'missed_inbound'  => ($isInbound && $missed) ? 1 : 0,
            'missed_outbound' => ($isOutbound && $missed) ? 1 : 0,
            'call_date'       => $first['calldate']
        ];
    }

    $stats['avg_duration'] = $stats['total_calls'] > 0 
        ? round($stats['total_duration'] / $stats['total_calls']) 
        : 0;

    usort($byExt, fn($a,$b) => $a['call_date'] <=> $b['call_date']);

    file_put_contents($this->logPath, date('Y-m-d H:i:s') . 
        " [DEBUG FINAL] Total: {$stats['total_calls']} | Answered: {$stats['answered']} | Missed: {$stats['missed']} | Inbound: {$stats['inbound']} | Outbound: {$stats['outbound']} | Internal: {$stats['internal']}\n", 
        FILE_APPEND);

    return ['stats' => $stats, 'by_ext' => $byExt];
}

private function processDidStatsInPhp(array $rows, $specificDid = ''): array {
    $byLinked = [];
    foreach ($rows as $row) {
        $lid = $row['linkedid'];
        if (!isset($byLinked[$lid])) $byLinked[$lid] = [];
        $byLinked[$lid][] = $row;
    }

    $hourly = array_fill(0, 24, [
        'hour' => 0, 'calls' => 0, 'answered' => 0, 'missed' => 0,
        'total_duration' => 0, 'avg_duration' => 0, 'inbound' => 0
    ]);
    $summary = [];

    foreach ($byLinked as $callRows) {
        usort($callRows, fn($a,$b) => strtotime($a['calldate']) <=> strtotime($b['calldate']));
        $first = $callRows[0];

        $hour = (int)substr($first['calldate'], 11, 2);
        $realDid = trim($first['did']);

        // === СТРОГАЯ ЛОГИКА КАК В MISSED_INBOUND И GRID_STATS ===
        $answeredFlag = 0;
        $maxBillsec   = 0;
        foreach ($callRows as $r) {
            $dlen = strlen(trim($r['dst']));
            if ($dlen >= 3 && $dlen <= 6 && $r['disposition'] === 'ANSWERED') {
                $answeredFlag = 1;
                // можно break, но оставляем полный цикл для maxBillsec
            }
            if ($r['billsec'] > $maxBillsec) $maxBillsec = $r['billsec'];
        }

        $missed = $answeredFlag ? 0 : 1;

        $hourly[$hour]['hour'] = $hour;
        $hourly[$hour]['calls']++;
        $hourly[$hour]['answered'] += $answeredFlag;
        $hourly[$hour]['missed'] += $missed;
        $hourly[$hour]['total_duration'] += $maxBillsec;
        $hourly[$hour]['inbound']++;

        // Summary по DID
        if (!isset($summary[$realDid])) {
            $summary[$realDid] = [
                'did' => $realDid,
                'calls' => 0, 'answered' => 0, 'missed' => 0,
                'total_duration' => 0, 'avg_duration' => 0, 'inbound' => 0
            ];
        }
        $summary[$realDid]['calls']++;
        $summary[$realDid]['answered'] += $answeredFlag;
        $summary[$realDid]['missed'] += $missed;
        $summary[$realDid]['total_duration'] += $maxBillsec;
        $summary[$realDid]['inbound']++;
    }

    // Расчёт avg_duration
    foreach ($hourly as &$h) {
        $h['avg_duration'] = $h['calls'] > 0 ? round($h['total_duration'] / $h['calls']) : 0;
    }
    foreach ($summary as &$s) {
        $s['avg_duration'] = $s['calls'] > 0 ? round($s['total_duration'] / $s['calls']) : 0;
    }

    usort($summary, fn($a,$b) => $b['calls'] <=> $a['calls']);

    file_put_contents($this->logPath, date('Y-m-d H:i:s') . " [PHP] processDidStatsInPhp → " . count($byLinked) . " звонков → missed теперь считается строго (как в missed_inbound)\n", FILE_APPEND);

    return [
        'stats'       => array_values($hourly),
        'did_summary' => $summary
    ];
}

private function processOutboundDidStatsInPhp(array $rows, $specificDid = ''): array {
    $byLinked = [];
    foreach ($rows as $row) {
        $lid = $row['linkedid'];
        if (!isset($byLinked[$lid])) $byLinked[$lid] = [];
        $byLinked[$lid][] = $row;
    }

    $hourly = array_fill(0, 24, ['hour' => 0, 'calls' => 0, 'answered' => 0, 'missed' => 0, 'total_duration' => 0, 'avg_duration' => 0]);
    $summary = [];

    foreach ($byLinked as $callRows) {
        usort($callRows, fn($a,$b) => strtotime($a['calldate']) <=> strtotime($b['calldate']));
        $first = $callRows[0];

        $hour = (int)substr($first['calldate'], 11, 2);

        $cleanDid = $this->parseOutboundDid($first['dstchannel']);
        if (empty($cleanDid)) $cleanDid = trim($first['outbound_cnum'] ?? $first['dst']);
        $cleanDid = trim($cleanDid);

        if (empty($cleanDid) || strlen($cleanDid) < 5) continue;

        // === ФИЛЬТР ПРИ ВЫБОРЕ КОНКРЕТНОГО DID ===
        if ($specificDid !== '' && $cleanDid !== $specificDid) continue;

        // answered / missed
        $maxBillsec = 0;
        foreach ($callRows as $r) {
            if ($r['billsec'] > $maxBillsec) $maxBillsec = $r['billsec'];
        }
        $answered = ($maxBillsec > 0) ? 1 : 0;
        $missed   = $answered ? 0 : 1;

        // Hourly + Summary
        $hourly[$hour]['hour'] = $hour;
        $hourly[$hour]['calls']++;
        $hourly[$hour]['answered'] += $answered;
        $hourly[$hour]['missed'] += $missed;
        $hourly[$hour]['total_duration'] += $maxBillsec;

        if (!isset($summary[$cleanDid])) {
            $summary[$cleanDid] = ['did' => $cleanDid, 'calls' => 0, 'answered' => 0, 'missed' => 0, 'total_duration' => 0, 'avg_duration' => 0, 'unique_ext' => 0];
        }
        $summary[$cleanDid]['calls']++;
        $summary[$cleanDid]['answered'] += $answered;
        $summary[$cleanDid]['missed'] += $missed;
        $summary[$cleanDid]['total_duration'] += $maxBillsec;

        $channelPart = explode('/', $first['channel'])[1] ?? '';
        $ext = substr(explode('-', $channelPart)[0] ?? '', 0, 6);
        if (!isset($summary[$cleanDid]['exts'])) $summary[$cleanDid]['exts'] = [];
        $summary[$cleanDid]['exts'][$ext] = true;
    }

    foreach ($hourly as &$h) {
        $h['avg_duration'] = $h['calls'] > 0 ? round($h['total_duration'] / $h['calls']) : 0;
    }
    foreach ($summary as &$s) {
        $s['avg_duration'] = $s['calls'] > 0 ? round($s['total_duration'] / $s['calls']) : 0;
        $s['unique_ext'] = isset($s['exts']) ? count($s['exts']) : 0;
        unset($s['exts']);
    }

    usort($summary, fn($a,$b) => $b['calls'] <=> $a['calls']);

    file_put_contents($this->logPath, date('Y-m-d H:i:s') . " [PHP] processOutboundDidStatsInPhp → " . count($summary) . " DID (SIMPLE — один расчёт + фильтр по ключу)\n", FILE_APPEND);

    return ['stats' => array_values($hourly), 'did_summary' => $summary];
}

    // ====================== СЛУЖЕБНЫЕ ======================
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

        // DID из Inbound Routes FreePBX
        $sql = "SELECT DISTINCT extension FROM incoming WHERE extension != '' ORDER BY extension";
        $sth = $this->configDb->query($sql);
        $dids = $sth->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($dids as $did) {
            $did = trim($did);
            if (empty($did)) continue;
            $list[$did] = $did;
        }

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDids: добавляем реальные DID из CDR\n", FILE_APPEND);

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

        $cleanList = [];
        foreach ($list as $key => $value) {
            if (strlen($key) === 10) {
                $full = '7' . $key;
                if (isset($list[$full])) {
                    continue; 
                }
            }
            $cleanList[$key] = $value;
        }

        // Красивая сортировка: 11-значные номера сверху
        uksort($cleanList, function($a, $b) {
            $la = strlen($a); $lb = strlen($b);
            if ($la !== $lb) return $lb - $la;
            return strcmp($a, $b);
        });

        file_put_contents($this->logPath, date('Y-m-d H:i:s') . " getDids() → " . count($cleanList) . " уникальных DID (дубли 491... удалены)\n", FILE_APPEND);

        return $cleanList;

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

    public function install() { file_put_contents($this->logPath, date('Y-m-d H:i:s') . " Install called\n", FILE_APPEND); }
    public function uninstall() {}
    public function backup() {}
    public function restore($backup) {}
    public function doConfigPageInit($page) {}

    // ====================== ГЛАВНЫЙ МЕТОД ======================
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
                $data = $this->getCallStats($startDate, $endDate, [
                    'extension' => $_REQUEST['ext'] ?? '',
                    'ext_range' => $_REQUEST['ext_range'] ?? '',
                    'queue'     => $_REQUEST['queue'] ?? ''
                ]);
                $content = load_view(__DIR__ . '/views/grid_stats.php', [
                    'data' => $data, 'startDate' => $startDate, 'endDate' => $endDate,
                    'extensionsList' => $this->getExtensions(), 'queuesList' => $this->getQueues()
                ]);
                break;

            case 'outbound_did_stats':
                list($startDate, $endDate) = $this->parseDateRange();
                $did = $_REQUEST['did'] ?? '';
                $data = $this->getOutboundDidStats($startDate, $endDate, $did);
                $didsList = $this->getOutboundDidsForPeriod($startDate, $endDate);

                if (empty($did) && !empty($data['did_summary'])) {
                    usort($data['did_summary'], fn($a, $b) => $b['calls'] <=> $a['calls']);
                }

                $content = load_view(__DIR__ . '/views/outbound_did_stats.php', [
                    'data' => $data, 'statsByHour' => $data['stats'] ?? [],
                    'startDate' => $startDate, 'endDate' => $endDate, 'did' => $did, 'didsList' => $didsList
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
                $logDid = $did !== '' ? $did : '—';
                $logRange = $_REQUEST['daterange'] ?? '—';
                file_put_contents($this->logPath, date('Y-m-d H:i:s') . " AJAX get_unique_exts called | did=$logDid | daterange=$logRange\n", FILE_APPEND);
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
        }

        $serverName = gethostname();
        return load_view(__DIR__ . '/views/default.php', [
            'subhead' => $subhead, 'content' => $content, 'serverName' => $serverName
        ]);
    }

private function exportCsv() {
    while (ob_get_level()) {
        ob_end_clean();
    }

    $start  = $_REQUEST['start'] ?? date('Y-m-d');
    $end    = $_REQUEST['end'] ?? date('Y-m-d');
    $filter = [
        'extension' => $_REQUEST['ext'] ?? '',
        'ext_range' => $_REQUEST['ext_range'] ?? '',
        'queue'     => $_REQUEST['queue'] ?? ''
    ];

    $data = $this->getCallStats($start, $end, $filter);

    // === Заголовки ===
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cdr_stats_' . $start . '_' . $end . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');

    // UTF-8 BOM — чтобы Excel видел русский текст
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // Заголовки (точно как в таблице на странице)
    fputcsv($out, [
        'Дата звонка',
        'Тип',
        'От',
        'Кому',
        'Звонков',
        'Длительность (сек)',
        'Средняя (сек)',
        'Отвечено',
        'Пропущено вх.',
        'Недозвонились исх.'
    ], ';');

    // Данные
    foreach ($data['by_ext'] as $row) {
        fputcsv($out, [
            $row['call_date'] ?? '',
            $row['operator_type'] ?? 'Unknown',
            $row['src_ext'] ?? '',
            $row['dst_ext'] ?? '',
            $row['calls'] ?? 0,
            $row['total_duration'] ?? 0,
            round($row['avg_duration'] ?? 0),
            $row['answered'] ?? 0,
            $row['missed_inbound'] ?? 0,
            $row['missed_outbound'] ?? 0
        ], ';');
    }

    fclose($out);
    exit;
}
}