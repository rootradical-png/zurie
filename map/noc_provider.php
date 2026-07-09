<?php
declare(strict_types=1);

/**
 * Provider jambatan NOC.
 *
 * Keutamaan sumber:
 * 1. /zurie/noc_map_provider.php
 * 2. /zurie/includes/noc_map_provider.php
 * 3. /zurie/device_manager/noc_map_provider.php
 * 4. /zurie/map/data/noc_devices.json (snapshot pilihan)
 *
 * Fail provider luar boleh return:
 * - senarai row peranti; atau
 * - ['source' => 'nama sumber', 'devices' => [...]]; atau
 * - callable yang menerima array context dan memulangkan salah satu format di atas.
 */
return static function (array $context = []): array {
    $mapDir = __DIR__;
    $zurieDir = dirname(__DIR__);

    $candidates = [
        $zurieDir . '/noc_map_provider.php',
        $zurieDir . '/includes/noc_map_provider.php',
        $zurieDir . '/device_manager/noc_map_provider.php',
    ];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }

        $result = require $candidate;
        if (is_callable($result)) {
            $result = $result($context);
        }

        if (is_array($result)) {
            if (array_is_list($result)) {
                return [
                    'source' => str_replace('\\', '/', $candidate),
                    'devices' => $result,
                ];
            }

            if (isset($result['devices']) && is_array($result['devices'])) {
                $result['source'] ??= str_replace('\\', '/', $candidate);
                return $result;
            }
        }
    }

    $snapshot = $mapDir . '/data/noc_devices.json';
    if (is_file($snapshot)) {
        $raw = file_get_contents($snapshot);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (is_array($decoded)) {
            $devices = isset($decoded['devices']) && is_array($decoded['devices'])
                ? $decoded['devices']
                : (array_is_list($decoded) ? $decoded : []);

            if ($devices !== []) {
                return [
                    'source' => 'data/noc_devices.json',
                    'devices' => $devices,
                ];
            }
        }
    }

    return [
        'source' => 'local-layout-fallback',
        'devices' => [],
    ];
};
