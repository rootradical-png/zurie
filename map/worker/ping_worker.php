<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/topology.php';

// Elak dua proses ping_worker berjalan serentak.
$lockPath = dirname(__DIR__) . '/data/ping_worker.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . '] Worker lain masih berjalan. Skip.\n';
    exit(0);
}
register_shutdown_function(static function () use ($lockHandle): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
});

function read_json(string $path): array {
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("JSON tidak sah: {$path}");
    return $data;
}
function ping_command(string $ip, int $timeoutMs): string {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new InvalidArgumentException("IP tidak sah: {$ip}");
    if (PHP_OS_FAMILY === 'Windows') return sprintf('ping -n 1 -w %d %s', $timeoutMs, escapeshellarg($ip));
    return sprintf('ping -c 1 -W %d %s', max(1, (int)ceil($timeoutMs / 1000)), escapeshellarg($ip));
}
function latency(string $text): ?float {
    foreach (['/time[=<]?\s*([0-9.]+)\s*ms/i','/masa[=<]?\s*([0-9.]+)\s*ms/i','/average\s*=\s*([0-9.]+)\s*ms/i'] as $p) {
        if (preg_match($p, $text, $m) === 1) return (float)$m[1];
    }
    return preg_match('/TTL[=\s]/i', $text) === 1 ? 1.0 : null;
}
function run_batch(array $batch, int $timeoutMs): array {
    $jobs = []; $out = [];
    foreach ($batch as $d) {
        $id=(string)($d['id']??''); $ip=(string)($d['ip']??'');
        if ($id==='' || !filter_var($ip,FILTER_VALIDATE_IP)) { $out[$id?:uniqid('bad_')]=['status'=>'unknown','latency_ms'=>null,'checked_at'=>date(DATE_ATOM)]; continue; }
        $pipes=[]; $proc=proc_open(ping_command($ip,$timeoutMs),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($proc)) { $out[$id]=['status'=>'unknown','latency_ms'=>null,'checked_at'=>date(DATE_ATOM)]; continue; }
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $jobs[$id]=['p'=>$proc,'io'=>$pipes,'start'=>microtime(true),'txt'=>''];
    }
    $limit=max(3.0,$timeoutMs/1000+2.0);
    while ($jobs) {
        foreach ($jobs as $id=>&$j) {
            $j['txt'].=(stream_get_contents($j['io'][1])?:'').(stream_get_contents($j['io'][2])?:'');
            $s=proc_get_status($j['p']); $expired=(microtime(true)-$j['start'])>$limit;
            if (!$s['running'] || $expired) {
                if ($expired && $s['running']) proc_terminate($j['p']);
                $j['txt'].=(stream_get_contents($j['io'][1])?:'').(stream_get_contents($j['io'][2])?:'');
                fclose($j['io'][1]); fclose($j['io'][2]); proc_close($j['p']);
                $ms=latency($j['txt']); $online=$ms!==null;
                $out[$id]=['status'=>$online?($ms>50?'warning':'online'):'offline','latency_ms'=>$ms,'checked_at'=>date(DATE_ATOM)];
                unset($jobs[$id]);
            }
        }
        unset($j); if ($jobs) usleep(50000);
    }
    return $out;
}
try {
    $map=topology_load_live($config);
    $devices=array_values(array_filter($map['devices']??[],fn($d)=>($d['enabled']??true)&&!empty($d['ip'])));
    $result=[];
    foreach (array_chunk($devices,max(1,(int)$config['ping_batch_size'])) as $batch) $result=array_replace($result,run_batch($batch,(int)$config['ping_timeout_ms']));
    $payload=[
        'generated_at'=>date(DATE_ATOM),
        'sync'=>$map['_sync']??[],
        'devices'=>$result,
    ];
    $json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false) throw new RuntimeException('Gagal encode status');
    $target=(string)$config['status_file']; $tmp=$target.'.tmp';
    if (file_put_contents($tmp,$json,LOCK_EX)===false) throw new RuntimeException('Gagal tulis status sementara');
    if (PHP_OS_FAMILY==='Windows' && is_file($target)) @unlink($target);
    if (!rename($tmp,$target)) throw new RuntimeException('Gagal gantikan status.json');
    $source=(string)(($map['_sync']['source']??'local-layout'));
    echo '['.date('Y-m-d H:i:s').'] '.count($devices).' peranti diperiksa. Sumber: '.$source."\n";
} catch (Throwable $e) { fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL); exit(1); }
