<?php
// Senarai peranti NOC Zurie Portal.
// Data utama disimpan dalam data/noc_devices.json supaya boleh diedit melalui Device Manager.

$jsonFile = __DIR__ . '/noc_devices.json';
$devices = [];

if (file_exists($jsonFile)) {
    $json = file_get_contents($jsonFile);
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $devices = $decoded;
    }
}

// Buang duplicate berdasarkan type + ip. Untuk Service, url juga diambil kira sebab 1 IP boleh ada banyak port/service.
$unique = [];
foreach ($devices as $device) {
    // Gunakan nama pemboleh ubah khusus supaya tidak menindih $type pada halaman pemanggil.
    $deviceType = isset($device['type']) ? $device['type'] : 'Other';
    $deviceIp = isset($device['ip']) ? $device['ip'] : '';
    $deviceUrl = isset($device['url']) ? $device['url'] : '';
    $key = strtolower($deviceType . '|' . $deviceIp . (($deviceType === 'Service') ? ('|' . $deviceUrl) : ''));
    $unique[$key] = [
        'id' => isset($device['id']) ? $device['id'] : '',
        'type' => $deviceType,
        'name' => isset($device['name']) ? $device['name'] : '',
        'model' => isset($device['model']) ? $device['model'] : '',
        'serial' => isset($device['serial']) ? $device['serial'] : '',
        'ip' => $deviceIp,
        'url' => $deviceUrl !== '' ? $deviceUrl : ($deviceIp !== '' ? 'http://' . $deviceIp : ''),
        'monitoring_status' => isset($device['monitoring_status']) ? $device['monitoring_status'] : 'active',
        'monitoring_note' => isset($device['monitoring_note']) ? $device['monitoring_note'] : '',
    ];
}

return array_values($unique);
