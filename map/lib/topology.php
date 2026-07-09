<?php
declare(strict_types=1);

function topology_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function topology_write_json_atomic(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Gagal mencipta folder: {$dir}");
    }

    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('Gagal menghasilkan JSON.');
    }

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException("Gagal menulis: {$tmp}");
    }

    if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
        @unlink($path);
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Gagal menggantikan: {$path}");
    }
}

function topology_first(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function topology_normalize_text(mixed $value): string
{
    $text = strtolower(trim((string)$value));
    $text = preg_replace('/[^a-z0-9]+/u', '', $text) ?? '';
    return $text;
}

function topology_normalize_type(mixed $value): string
{
    $type = strtolower(trim((string)$value));
    $type = str_replace([' ', '_'], '-', $type);

    return match (true) {
        str_contains($type, 'core') => 'core',
        str_contains($type, 'firewall'), str_contains($type, 'palo') => 'firewall',
        str_contains($type, 'router'), str_contains($type, 'gateway') => 'router',
        str_contains($type, 'controller') => 'controller',
        str_contains($type, 'access-point'), $type === 'ap', str_contains($type, 'wireless') => 'ap',
        str_contains($type, 'server'), str_contains($type, 'service') => 'server',
        str_contains($type, 'switch') => 'switch',
        default => $type !== '' ? $type : 'switch',
    };
}

function topology_to_bool(mixed $value, bool $default = true): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int)$value !== 0;
    }

    $text = strtolower(trim((string)$value));
    if (in_array($text, ['1', 'true', 'yes', 'ya', 'active', 'aktif', 'enabled'], true)) {
        return true;
    }
    if (in_array($text, ['0', 'false', 'no', 'tidak', 'inactive', 'tidak aktif', 'disabled'], true)) {
        return false;
    }

    return $default;
}

function topology_normalize_noc_device(array $row, int $position): ?array
{
    $sourceId = topology_first($row, ['id', 'device_id', 'noc_id', 'peranti_id', 'inventory_id']);
    $mapKey = topology_first($row, ['map_key', 'map_id', 'device_code', 'code', 'kod', 'slug']);
    $name = topology_first($row, ['name', 'device_name', 'nama', 'nama_peranti', 'hostname']);
    $ip = topology_first($row, ['ip', 'ip_address', 'alamat_ip', 'ipaddr']);
    $type = topology_first($row, ['type', 'category', 'kategori', 'device_type', 'jenis'], 'switch');

    if (($name === null || trim((string)$name) === '') && ($ip === null || trim((string)$ip) === '')) {
        return null;
    }

    $identity = null;
    if ($sourceId !== null && trim((string)$sourceId) !== '') {
        $identity = 'id:' . trim((string)$sourceId);
    } elseif ($mapKey !== null && trim((string)$mapKey) !== '') {
        $identity = 'map:' . trim((string)$mapKey);
    } else {
        $identity = 'row:' . hash('sha256', ($name ?? '') . '|' . ($type ?? '') . '|' . $position);
    }

    return [
        '_identity' => $identity,
        'source_id' => $sourceId !== null ? (string)$sourceId : null,
        'map_key' => $mapKey !== null ? (string)$mapKey : null,
        'name' => trim((string)($name ?? '')),
        'ip' => trim((string)($ip ?? '')),
        'type' => topology_normalize_type($type),
        'location' => trim((string)topology_first($row, ['location', 'lokasi', 'site', 'building', 'bangunan'], '')),
        'model' => trim((string)topology_first($row, ['model', 'device_model', 'model_peranti'], '')),
        'serial' => trim((string)topology_first($row, ['serial', 'serial_number', 'serial_no', 'no_siri'], '')),
        'enabled' => topology_to_bool(topology_first($row, ['enabled', 'is_active', 'active', 'aktif'], true), true),
        '_raw' => $row,
    ];
}

function topology_load_noc_inventory(array $config): array
{
    $sync = $config['noc_sync'] ?? [];
    if (!(bool)($sync['enabled'] ?? false)) {
        return [
            'available' => false,
            'source' => 'sync-disabled',
            'devices' => [],
            'error' => null,
        ];
    }

    $providerFile = (string)($sync['provider_file'] ?? '');
    if ($providerFile === '' || !is_file($providerFile)) {
        return [
            'available' => false,
            'source' => 'provider-not-found',
            'devices' => [],
            'error' => $providerFile !== '' ? "Provider tidak ditemui: {$providerFile}" : 'Provider belum ditetapkan.',
        ];
    }

    try {
        $provider = require $providerFile;
        $result = is_callable($provider)
            ? $provider(['config' => $config, 'map_dir' => dirname(__DIR__)])
            : $provider;

        if (!is_array($result)) {
            throw new RuntimeException('Provider NOC mesti memulangkan array.');
        }

        if (array_is_list($result)) {
            $source = basename($providerFile);
            $rows = $result;
        } else {
            $source = (string)($result['source'] ?? basename($providerFile));
            $rows = is_array($result['devices'] ?? null) ? $result['devices'] : [];
        }

        $devices = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = topology_normalize_noc_device($row, $index);
            if ($normalized !== null) {
                $devices[] = $normalized;
            }
        }

        return [
            'available' => $devices !== [],
            'source' => $source,
            'devices' => $devices,
            'error' => null,
        ];
    } catch (Throwable $e) {
        return [
            'available' => false,
            'source' => basename($providerFile),
            'devices' => [],
            'error' => $e->getMessage(),
        ];
    }
}

function topology_unique_index(array $devices, callable $keyFn): array
{
    $buckets = [];
    foreach ($devices as $device) {
        $key = (string)$keyFn($device);
        if ($key === '') {
            continue;
        }
        $buckets[$key][] = $device;
    }

    $index = [];
    foreach ($buckets as $key => $bucket) {
        if (count($bucket) === 1) {
            $index[$key] = $bucket[0];
        }
    }
    return $index;
}

function topology_merge_with_noc(array $layout, array $inventory, array $config): array
{
    $nocDevices = $inventory['devices'] ?? [];
    $syncConfig = $config['noc_sync'] ?? [];
    $bindingsFile = (string)($syncConfig['bindings_file'] ?? '');
    $bindings = $bindingsFile !== '' ? topology_read_json($bindingsFile, []) : [];
    $bindingsChanged = false;

    $byIdentity = [];
    foreach ($nocDevices as $device) {
        $byIdentity[$device['_identity']] = $device;
    }

    $byMapKey = topology_unique_index($nocDevices, static fn(array $d): string => topology_normalize_text($d['map_key'] ?? ''));
    $bySourceId = topology_unique_index($nocDevices, static fn(array $d): string => trim((string)($d['source_id'] ?? '')));
    $byIp = topology_unique_index($nocDevices, static fn(array $d): string => trim((string)($d['ip'] ?? '')));
    $byNameType = topology_unique_index($nocDevices, static fn(array $d): string => topology_normalize_text($d['name'] ?? '') . '|' . topology_normalize_type($d['type'] ?? ''));
    $byName = topology_unique_index($nocDevices, static fn(array $d): string => topology_normalize_text($d['name'] ?? ''));

    $overrideFields = $syncConfig['override_fields'] ?? ['name', 'ip', 'type', 'location', 'model', 'serial', 'enabled'];
    $matched = 0;
    $changedIp = [];
    $unmatched = [];

    foreach (($layout['devices'] ?? []) as $index => $mapDevice) {
        if (!is_array($mapDevice)) {
            continue;
        }

        $mapId = (string)($mapDevice['id'] ?? '');
        if ($mapId === '') {
            continue;
        }

        $match = null;
        $matchMethod = null;

        $boundIdentity = $bindings[$mapId] ?? null;
        if (is_string($boundIdentity) && isset($byIdentity[$boundIdentity])) {
            $match = $byIdentity[$boundIdentity];
            $matchMethod = 'saved-binding';
        }

        if ($match === null && isset($mapDevice['noc_id'])) {
            $key = trim((string)$mapDevice['noc_id']);
            if ($key !== '' && isset($bySourceId[$key])) {
                $match = $bySourceId[$key];
                $matchMethod = 'noc-id';
            }
        }

        if ($match === null && isset($mapDevice['noc_ref'])) {
            $key = topology_normalize_text($mapDevice['noc_ref']);
            if ($key !== '' && isset($byMapKey[$key])) {
                $match = $byMapKey[$key];
                $matchMethod = 'noc-ref';
            }
        }

        if ($match === null) {
            $key = topology_normalize_text($mapId);
            if ($key !== '' && isset($byMapKey[$key])) {
                $match = $byMapKey[$key];
                $matchMethod = 'map-key';
            }
        }

        if ($match === null) {
            $key = trim((string)($mapDevice['ip'] ?? ''));
            if ($key !== '' && isset($byIp[$key])) {
                $match = $byIp[$key];
                $matchMethod = 'initial-ip';
            }
        }

        if ($match === null) {
            $key = topology_normalize_text($mapDevice['name'] ?? '') . '|' . topology_normalize_type($mapDevice['type'] ?? '');
            if ($key !== '|' && isset($byNameType[$key])) {
                $match = $byNameType[$key];
                $matchMethod = 'name-type';
            }
        }

        if ($match === null) {
            $key = topology_normalize_text($mapDevice['name'] ?? '');
            if ($key !== '' && isset($byName[$key])) {
                $match = $byName[$key];
                $matchMethod = 'name';
            }
        }

        if ($match === null) {
            $layout['devices'][$index]['_noc'] = [
                'matched' => false,
                'source' => $inventory['source'] ?? 'unknown',
            ];
            $unmatched[] = $mapId;
            continue;
        }

        $matched++;
        $oldIp = trim((string)($mapDevice['ip'] ?? ''));
        $newIp = trim((string)($match['ip'] ?? ''));

        foreach ($overrideFields as $field) {
            if (!array_key_exists($field, $match)) {
                continue;
            }
            $value = $match[$field];
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            $layout['devices'][$index][$field] = $value;
        }

        if ($oldIp !== '' && $newIp !== '' && $oldIp !== $newIp) {
            $changedIp[] = [
                'id' => $mapId,
                'name' => (string)($layout['devices'][$index]['name'] ?? $mapId),
                'old_ip' => $oldIp,
                'new_ip' => $newIp,
            ];
        }

        $layout['devices'][$index]['_noc'] = [
            'matched' => true,
            'source' => $inventory['source'] ?? 'unknown',
            'identity' => $match['_identity'],
            'source_id' => $match['source_id'],
            'map_key' => $match['map_key'],
            'match_method' => $matchMethod,
            'previous_ip' => $oldIp !== $newIp ? $oldIp : null,
        ];

        if (($bindings[$mapId] ?? null) !== $match['_identity']) {
            $bindings[$mapId] = $match['_identity'];
            $bindingsChanged = true;
        }
    }

    if ($bindingsChanged && $bindingsFile !== '') {
        try {
            topology_write_json_atomic($bindingsFile, $bindings);
        } catch (Throwable) {
            // Map masih boleh berfungsi walaupun fail binding tidak boleh ditulis.
        }
    }

    $layout['_sync'] = [
        'enabled' => (bool)($syncConfig['enabled'] ?? false),
        'available' => (bool)($inventory['available'] ?? false),
        'source' => (string)($inventory['source'] ?? 'unknown'),
        'provider_count' => count($nocDevices),
        'map_count' => count($layout['devices'] ?? []),
        'matched_count' => $matched,
        'unmatched_count' => count($unmatched),
        'unmatched_ids' => $unmatched,
        'changed_ip' => $changedIp,
        'error' => $inventory['error'] ?? null,
        'generated_at' => date(DATE_ATOM),
    ];

    return $layout;
}

function topology_load_live(array $config): array
{
    $layoutFile = (string)($config['devices_file'] ?? '');
    $layout = topology_read_json($layoutFile, []);

    if (!isset($layout['areas'], $layout['devices'], $layout['links'])) {
        throw new RuntimeException('Struktur data/devices.json tidak lengkap.');
    }

    $inventory = topology_load_noc_inventory($config);
    if (($inventory['devices'] ?? []) === []) {
        $layout['_sync'] = [
            'enabled' => (bool)(($config['noc_sync']['enabled'] ?? false)),
            'available' => false,
            'source' => (string)($inventory['source'] ?? 'local-layout-fallback'),
            'provider_count' => 0,
            'map_count' => count($layout['devices']),
            'matched_count' => 0,
            'unmatched_count' => count($layout['devices']),
            'unmatched_ids' => array_values(array_filter(array_map(
                static fn(array $d): string => (string)($d['id'] ?? ''),
                $layout['devices']
            ))),
            'changed_ip' => [],
            'error' => $inventory['error'] ?? null,
            'generated_at' => date(DATE_ATOM),
        ];
        return $layout;
    }

    return topology_merge_with_noc($layout, $inventory, $config);
}
