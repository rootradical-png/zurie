<?php
/**
 * Credential Vault database configuration.
 * Edit these values before opening pages/credential_vault.php.
 * Prefer environment variables on production servers.
 */
return [
    'dsn' => getenv('ZURIE_VAULT_DSN') ?: 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4',
    'username' => getenv('ZURIE_VAULT_DB_USER') ?: 'root',
    'password' => getenv('ZURIE_VAULT_DB_PASS') ?: 'kmp@987',
    'unlock_minutes' => 10,
    'portal_user' => 'ZURIE',
];
