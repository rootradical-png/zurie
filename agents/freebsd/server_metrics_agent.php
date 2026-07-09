#!/usr/local/bin/php
<?php
declare(strict_types=1);
// Personal NOC Server Metrics Agent - FreeBSD / Unix
// Letak config di /usr/local/etc/zurie-server-metrics.ini (chmod 600).
const AGENT_VERSION = '1.0.0-freebsd';
$configFile = getenv('ZURIE_METRICS_CONFIG') ?: '/usr/local/etc/zurie-server-metrics.ini';
if (!is_file($configFile)) fwrite(STDERR, "Config tidak ditemui: {$configFile}\n") && exit(1);
$c = parse_ini_file($configFile, false, INI_SCANNER_TYPED);
foreach (['device_id','token','push_url'] as $key) if (empty($c[$key])) fwrite(STDERR, "Config {$key} wajib diisi.\n") && exit(1);

function cmd(string $command): string { return trim((string)shell_exec($command . ' 2>/dev/null')); }
function n(string $value): float { return is_numeric(trim($value)) ? (float)trim($value) : 0.0; }
function cpuPercent(): ?float {
    $a = preg_split('/\s+/', cmd('sysctl -n kern.cp_time')) ?: [];
    usleep(350000);
    $b = preg_split('/\s+/', cmd('sysctl -n kern.cp_time')) ?: [];
    if (count($a) < 5 || count($b) < 5) return null;
    $delta=[]; for($i=0;$i<5;$i++) $delta[$i]=max(0,(int)$b[$i]-(int)$a[$i]);
    $total=array_sum($delta); if($total<=0)return null;
    return round((1-($delta[4]/$total))*100,2);
}
function memoryInfo(): array {
    $total=(int)n(cmd('sysctl -n hw.physmem'));
    $page=(int)n(cmd('sysctl -n hw.pagesize')) ?: 4096;
    $free=(int)n(cmd('sysctl -n vm.stats.vm.v_free_count'));
    $inactive=(int)n(cmd('sysctl -n vm.stats.vm.v_inactive_count'));
    $cache=(int)n(cmd('sysctl -n vm.stats.vm.v_cache_count'));
    $avail=max(0,($free+$inactive+$cache)*$page); $used=max(0,$total-$avail);
    $toMb=fn(int $v)=>(int)round($v/1048576);
    return ['total_mb'=>$toMb($total),'used_mb'=>$toMb($used),'free_mb'=>$toMb($avail),'percent'=>$total>0?round($used/$total*100,2):null];
}
function disks(): array {
    $out=[]; $lines=preg_split('/\R/', cmd('df -kP')) ?: [];
    foreach(array_slice($lines,1) as $line){
        $p=preg_split('/\s+/',trim($line)); if(count($p)<6)continue;
        [$fs,$blocks,$used,$avail,$pct]=$p; $mount=implode(' ',array_slice($p,5));
        if(preg_match('#^(devfs|procfs|fdescfs|tmpfs|linprocfs|linsysfs)$#',$fs))continue;
        $percent=(float)rtrim($pct,'%');
        $out[]=['name'=>$mount,'mount'=>$mount,'filesystem'=>$fs,'total_gb'=>round((int)$blocks/1048576,2),'used_gb'=>round((int)$used/1048576,2),'free_gb'=>round((int)$avail/1048576,2),'percent'=>$percent];
    }
    return $out;
}
function uptimeSeconds(): int {
    $raw=cmd('sysctl -n kern.boottime');
    if(preg_match('/sec\s*=\s*(\d+)/',$raw,$m))return max(0,time()-(int)$m[1]);
    return 0;
}
function services(string $csv): array {
    $out=[]; foreach(array_filter(array_map('trim',explode(',',$csv))) as $name){
        exec('service '.escapeshellarg($name).' onestatus >/dev/null 2>&1',$dummy,$code);
        $out[]=['name'=>$name,'status'=>$code===0?'RUNNING':'STOPPED'];
    } return $out;
}
$loadRaw=cmd('sysctl -n vm.loadavg'); preg_match_all('/[0-9]+(?:\.[0-9]+)?/',$loadRaw,$lm);
$payload=[
    'device_id'=>(string)$c['device_id'],'hostname'=>gethostname()?:php_uname('n'),'os_name'=>php_uname(),'agent_version'=>AGENT_VERSION,'collected_at'=>date(DATE_ATOM),
    'cpu_percent'=>cpuPercent(),'memory'=>memoryInfo(),'disks'=>disks(),'uptime_seconds'=>uptimeSeconds(),
    'load'=>['1m'=>(float)($lm[0][0]??0),'5m'=>(float)($lm[0][1]??0),'15m'=>(float)($lm[0][2]??0)],
    'services'=>services((string)($c['services']??'')),
];
$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$ch=curl_init((string)$c['push_url']);
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.(string)$c['token'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$json,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>(int)($c['timeout_seconds']??20)]);
$response=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);
if($response===false||$status<200||$status>=300){fwrite(STDERR,"Push gagal HTTP {$status}: {$error} {$response}\n");exit(2);} echo "OK {$response}\n";
