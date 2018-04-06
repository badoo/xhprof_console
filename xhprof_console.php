<?php
/**
 * A tool for grabbing profiles from XHProf database and collecting aggregates from them.
 *
 * Requirements:
 * PHP with XHProf installed and set up.
 * This script works directly with xhprof database, profiles should be taken as usual.
 *
 * Why to use it, instead of native XHProf interface:
 * It allows to collect and see stats for several XHProf profiles. Stats includes:
 * 1) average method exec time
 * 2) 90 percentile of method exec time
 * 3) min, max of the exec time
 *
 * For usage see readme.
 *
 * @maintainer Grigory Kuzovnikov <g.kuzovnikov@corp.badoo.com>
 * @copyright (c) 2018 Badoo Tech
 */

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// CONFIG

// which percentile to collect
define ('PERCENTILE', 0.90);

$config = isset($argv[1]) ? $argv[1] : '';
if (!$config || (strpos($config, '.php') === false) || !file_exists($config)) {
    echo "You should specify path to config file (*.php):\n";
    echo "<?php\nreturn ";
    var_export(
        [
            'host' => '127.0.0.1',
            'db' => 'Profiler',
            'login' => 'mysql_login',
            'password' => 'mysql_password',
            'sql' => 'select perfdata from Profiler.details where label != \'PLACE YOUR LABEL HERE AND REPLACE != WITH =\' limit 300;',
        ]
    );
    echo ";\n";
    die();
}

$config = require_once(function_exists('mdk_resolve_with_include_path') ? mdk_resolve_with_include_path(__DIR__, $config) : $config);

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// SQL

extension_loaded('mysqli') || dl('mysqli.so');

if (!isset($config['login'], $config['password'], $config['sql'], $config['host'], $config['db'])) {
    echo "Wrong config\n";
    die();
}

echo "Fetching data...\n";

$mysqli = new mysqli($config['host'], $config['login'], $config['password'], $config['db']);
if ($mysqli->connect_errno) {
    echo "mysql connect error {$mysqli->connect_errno}\n";
    die();
}

echo "SQL request start\n";
if (!$result = $mysqli->query($config['sql'])) {
    echo "mysql error {$mysqli->errno}\n";
    die;
}
echo "SQL done\n";

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// FETCHING + AGGREGATING

$perf_count = $result->num_rows;

if ($perf_count == 0) {
    echo "nothing fetched, maybe wrong SQL conditions?\n";
    die;
}

$call_map = [];
$reverse_call_map = [];
$method_data = [];

function collectStats(&$stat, $row) {
    if (!$stat) {
        $stat = [
            'ct' => 0,
            'min_ct' => PHP_INT_MAX,
            'max_ct' => 0,
            'wt' => 0,
            'min_wt' => PHP_INT_MAX,
            'max_wt' => 0,
            'wts' => [],
        ];
    }
    $stat['ct'] =  $stat['ct'] + $row['ct'];
    $stat['min_ct'] = min($stat['min_ct'], $row['ct']);
    $stat['max_ct'] = max($stat['max_ct'], $row['ct']);
    $stat['wt'] = $stat['wt'] + $row['wt'];
    $stat['min_wt'] = min($stat['min_wt'], $row['wt']);
    $stat['max_wt'] = max($stat['max_wt'], $row['wt']);
    $stat['wts'][] = $row['wt'];
}

$i = 0;
while ($data = $result->fetch_assoc()) {
    echo "\rFetched " . ++$i . " rows; " . floor(memory_get_usage() / 1024 / 1024) . "M memory used";;
    $data = json_decode(gzuncompress($data['perfdata']), true); // <--- ACTUAL DATA DECOMPRESSION
    foreach ($data as $key => $stats) {
        if (strpos($key, '==>') === false) {
            $caller = false;
            $callee = 'main()';
        } else {
            list($caller, $callee) = explode('==>', $key);
        }

        if ($caller && $callee) {
            if (!isset($call_map[$caller])) $call_map[$caller] = [];
            if (!isset($call_map[$caller][$callee])) $call_map[$caller][$callee] = [];
            collectStats($call_map[$caller][$callee], $stats);

            if (!isset($reverse_call_map[$callee])) $reverse_call_map[$callee] = [];
            if (!isset($reverse_call_map[$callee][$caller])) $reverse_call_map[$callee][$caller] = [];
            collectStats($reverse_call_map[$callee][$caller], $stats);
        }

        if (!isset($method_data[$callee])) $method_data[$callee] = [];
        collectStats($method_data[$callee], $stats);
    }
}

// free mem
unset($data);
$mysqli->close();

echo "\nFetched all rows\n";
echo "Aggregating...\n";

foreach ($method_data as $method => &$map) {
    $percentile = getPercentile($map['wts']);
    unset($map['wts']);
    $map['ct'] = $map['ct'] / $perf_count;
    $map['wt'] = $map['wt'] / $perf_count;
    $map['percent_wt'] = $percentile;
}

foreach ([&$call_map, &$reverse_call_map] as &$collected_map) {
    foreach ($collected_map as $caller => &$map) {
        uasort($map, function ($a, $b) {
            $av = $a['wt'];
            $bv = $b['wt'];
            if ($av == $bv) return 0;
            return $av > $bv ? -1 : 1;
        });
        foreach ($map as &$stat) {
            $stat['ct'] = $stat['ct'] / $perf_count;
            $stat['wt'] = $stat['wt'] / $perf_count;
            $stat['percent_wt'] = getPercentile($stat['wts']);
            unset($stat['wts']);
        }
    }
}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// DISPLAYING RESULT

$path = 'main()';
$f = fopen("php://stdin","r");
$stack = [];
$marked = [];
while (true) {

    echo 'PATH: ';
    foreach ($stack as $method) {
        echo " -> " . $method;
    }
    echo "-> " . $path . "\n";

    $i = 0;
    $roots = [];
    foreach (["Self Stat" => [$path => [$path => $method_data[$path]]], "Children" => $call_map, "Parents" => $reverse_call_map, ] as $name => $map) {

        echo "==== $name ====\n";
        printf("%3s %10s %10s %10s %10s %10s %10s %10s %4s %s\n", '#', 'AVG_CT', 'min_ct', 'max_ct', 'min_wt', 'AVG_WT', 'PERCENT_WT', 'max_wt', 'mark', 'method');
        foreach ($map[$path] as $method => $stat) {
            $code = '';
            if ($method == $stack[count($stack) - 1]) {
                $code = 0;
            } elseif ($method != $path && isset($call_map[$method])) {
                $i++;
                $roots[$i] = $method;
                $code = $i;
            }

            foreach (['wt', 'min_wt', 'max_wt', 'percent_wt'] as $param) {
                if (is_null($stat[$param])) {
                    $stat[$param] = '-';
                } else {
                    $stat[$param] = number_format(floor($stat[$param]));
                }
            }

            printf("%3s %10.2f %10.2f %10.2f %10s %10s %10s %10s %4s %s\n", $code, $stat['ct'], $stat['min_ct'], $stat['max_ct'],
                $stat['min_wt'], $stat['wt'], $stat['percent_wt'], $stat['max_wt'], isset($marked[$method]) ? '*' : '', $method);
        }
    }

    echo "command (q|0 - go back|1|2|3|...|m1|m2|m3|...) > ";
    $line = fgets($f);
    $line = trim(str_replace("\n", "", $line));
    if ($line == 'q') {
        break;
    }

    if ($line == '0') {
        if ($stack) {
            $path = array_pop($stack);
        }
        continue;
    }

    if (strpos($line, 'm') === 0) {
        $line = str_replace('m','',$line);
        $marking = $roots[$line];
        if (isset($marked[$marking])) {
            unset($marked[$marking]);
        } else {
            $marked[$marking] = 1;
        }
        continue;
    }

    if (is_numeric($line)) {
        array_push($stack, $path);
        $path = $roots[$line];
    }
}

function getPercentile($arr) {
    sort($arr);
    $id = count($arr) * PERCENTILE;
    if (count($arr) < 50) {
        $percent = null;
    } else {
        $id_left = (int) floor($id);
        $id_right = $id_left + 1;
        if (!isset($arr[$id_right])) {
            $percent = $arr[$id_left];
        } else {
            $percent = ($arr[$id_right] - $arr[$id_left]) * ($id - $id_left);
            $percent = $arr[$id_left] + $percent;
        }
    }
    return $percent;
}
