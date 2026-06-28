<?php

namespace Deployer;

use function Env\env;

// ---------------------------------------------------------------------------
// is_first_release
// Verifica se o deploy atual é o primeiro (sem .dep/latest_release no servidor).
// ---------------------------------------------------------------------------
function is_first_release(): bool {
    $latestReleasePath = get('deploy_path') . '/.dep/latest_release';
    return !test("[ -f {$latestReleasePath} ]");
}

// ---------------------------------------------------------------------------
// get_project_type / assert_native_wp
// Lê PROJECT_TYPE ('bedrock' | 'native'). assert_native_wp() lança exceção fora do Native.
// ---------------------------------------------------------------------------
function get_project_type(): string {
    $type = env('PROJECT_TYPE') ?: getenv('PROJECT_TYPE') ?: 'bedrock';
    if (!in_array($type, ['native', 'bedrock'])) {
        throw new \Exception("PROJECT_TYPE inválido: '{$type}'. Use 'native' ou 'bedrock'.");
    }
    return $type;
}

function assert_native_wp(): void {
    if (get_project_type() !== 'native') {
        throw new \Exception('Esta task é exclusiva para projetos WordPress Native. No Bedrock use o .env.');
    }
}

// ---------------------------------------------------------------------------
// get_prod_stack / assert_easyengine
// Lê PROD_STACK; assert_easyengine() confirma stack=easyengine e ee acessível no mgmt host.
// ---------------------------------------------------------------------------
function get_prod_stack(): string {
    $stack = env('PROD_STACK') ?: getenv('PROD_STACK') ?: 'easyengine';
    $supported = ['easyengine'];
    if (!in_array($stack, $supported)) {
        throw new \Exception("PROD_STACK inválido: '{$stack}'. Suportados: " . implode(', ', $supported));
    }
    return $stack;
}

function assert_easyengine(): void {
    if (get_prod_stack() !== 'easyengine') {
        throw new \Exception('Esta operação requer PROD_STACK=easyengine.');
    }
    $result = run_on_management_host('which ee 2>/dev/null || echo NOT_FOUND');
    if (str_contains($result, 'NOT_FOUND')) {
        throw new \Exception('EasyEngine (ee) não encontrado no host de gerenciamento.');
    }
}

// ---------------------------------------------------------------------------
// assert_within_domain
// Impede operações fora do diretório /var/www/{domain} no servidor.
// ---------------------------------------------------------------------------
function assert_within_domain(string ...$paths): void {
    $allowed = rtrim(dirname(get('deploy_path')), '/');
    foreach ($paths as $path) {
        if (!str_starts_with(rtrim($path, '/'), $allowed)) {
            throw new \Exception(
                "Segurança: operação fora do escopo do domínio.\n"
                . "  Permitido: {$allowed}\n"
                . "  Tentativa: {$path}"
            );
        }
    }
}

// ---------------------------------------------------------------------------
// deploy_vendor_dirs / deploy_vendor_files_from_web_root
// Retorna diretórios e arquivos PHP do web root a empacotar, separado por PROJECT_TYPE.
// ---------------------------------------------------------------------------
function deploy_vendor_dirs(): array {
    if (get_project_type() === 'native') {
        return [
            'vendor',
            'web/wp-admin',
            'web/wp-includes',
            'web/wp-content/languages',
            'web/wp-content/plugins',
            'web/wp-content/themes',
        ];
    }

    return [
        'vendor',
        'web/wp',
        'web/app/languages',
        'web/app/plugins',
        'web/app/themes',
        'web/app/mu-plugins',
    ];
}

function deploy_vendor_files_from_web_root(string $root): array {
    if (get_project_type() !== 'native') {
        return [];
    }

    $excludeFromWeb = ['wp-config.php', 'wp-config-ddev.php', 'wp-config-sample.php'];
    $webRootFiles = array_filter(
        glob("{$root}/web/*.php") ?: [],
        fn($f) => !in_array(basename($f), $excludeFromWeb)
    );

    return array_map(fn($f) => 'web/' . basename($f), $webRootFiles);
}

// ---------------------------------------------------------------------------
// redis_object_cache_source / redis_object_cache_target
// Caminhos do plugin wp-redis e do symlink object-cache.php por PROJECT_TYPE.
// ---------------------------------------------------------------------------
function redis_object_cache_source(): string {
    if (get_project_type() === 'native') {
        return '{{current_path}}/web/wp-content/plugins/wp-redis/object-cache.php';
    }
    return '{{current_path}}/web/app/plugins/wp-redis/object-cache.php';
}

function redis_object_cache_target(): string {
    if (get_project_type() === 'native') {
        return '{{current_path}}/web/wp-content/object-cache.php';
    }
    return '{{current_path}}/web/app/object-cache.php';
}

// ---------------------------------------------------------------------------
// ee_wp_path
// Caminho absoluto do core WP dentro do container EasyEngine por PROJECT_TYPE.
// Necessário porque `ee shell --command` roda em /var/www/htdocs e o wp-cli.yml
// não é ancestral do cwd — `--path` explícito tem maior precedência no WP-CLI.
// ---------------------------------------------------------------------------
function ee_wp_path(): string {
    if (get_project_type() === 'native') {
        return get('ee_wp_path', '/var/www/htdocs/current/web');
    }
    return get('ee_wp_path', '/var/www/htdocs/current/web/wp');
}

// ---------------------------------------------------------------------------
// ee_shell
// Executa um comando no container do site via `sudo ee shell`. Para comandos
// `wp ...` no container php padrão, injeta --path apontando para o core Bedrock.
// ---------------------------------------------------------------------------
function ee_shell(string $domain, string $command, string $service = ''): string {
    if (empty($service) && preg_match('/^wp(\s|$)/', $command)) {
        $command = preg_replace('/^wp\b/', 'wp --path=' . ee_wp_path(), $command, 1);
    }
    $serviceFlag = $service ? ' --service=' . escapeshellarg($service) : '';
    return run_on_management_host('sudo ee shell ' . escapeshellarg($domain) . $serviceFlag . ' --command=' . escapeshellarg($command));
}

// ---------------------------------------------------------------------------
// ee_local_db_enabled / ee_external_db / ee_db_prefix
// Helpers de configuração de banco para o EasyEngine.
// ee_external_db() retorna [] quando o EE deve gerenciar o banco (via PROD_DB_HOST).
// ee_db_prefix() resolve DB_PREFIX com prioridade para PROD_DB_PREFIX.
// ---------------------------------------------------------------------------
function ee_local_db_enabled(): bool {
    $value = env('EE_LOCAL_DB') ?: getenv('EE_LOCAL_DB');
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function ee_external_db(): array {
    // Banco externo SOMENTE via PROD_DB_HOST — DB_* do .env local é do DDEV e não deve ativar modo externo.
    $host = env('PROD_DB_HOST') ?: getenv('PROD_DB_HOST');
    if (empty($host)) {
        return [];
    }
    return [
        'DB_NAME'     => env('PROD_DB_NAME')     ?: getenv('PROD_DB_NAME'),
        'DB_USER'     => env('PROD_DB_USER')     ?: getenv('PROD_DB_USER'),
        'DB_PASSWORD' => env('PROD_DB_PASSWORD') ?: getenv('PROD_DB_PASSWORD'),
        'DB_HOST'     => $host,
    ];
}

function ee_db_prefix(): string {
    return env('PROD_DB_PREFIX') ?: getenv('PROD_DB_PREFIX')
        ?: env('DB_PREFIX')      ?: getenv('DB_PREFIX')
        ?: env('WP_DB_PREFIX')   ?: getenv('WP_DB_PREFIX') ?: 'wp_';
}

// ---------------------------------------------------------------------------
// ee_parse_wpconfig_db
// Extrai credenciais DB do wp-config.php nativo gerado pelo EasyEngine.
// Roda no host de deploy (o volume está montado). Retorna apenas as chaves encontradas.
// ---------------------------------------------------------------------------
function ee_parse_wpconfig_db(string $remotePath): array {
    $content = run('cat ' . escapeshellarg($remotePath));
    $out = [];
    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $const) {
        if (preg_match('/define\(\s*[\'"]' . $const . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/', $content, $m)) {
            $out[$const] = $m[1];
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// run_on_management_host / run_on_management_host_pty
// Executa comandos no host de gerenciamento via SSH.
// A variante PTY usa `script -q -c` para comandos que chamam `docker exec` internamente
// (ex: ee site clean), que requerem TTY para propagar a operação no container.
// ---------------------------------------------------------------------------
function run_on_management_host(string $cmd): string {
    $mgmtHost = get('mgmt_host');
    $mgmtPort = get('mgmt_port', 22);
    $mgmtUser = get('mgmt_user');
    return runLocally(sprintf(
        'ssh -o StrictHostKeyChecking=no -p %d %s@%s %s',
        $mgmtPort, $mgmtUser, $mgmtHost, escapeshellarg($cmd)
    ));
}

function run_on_management_host_pty(string $cmd): string {
    $mgmtHost = get('mgmt_host');
    $mgmtPort = get('mgmt_port', 22);
    $mgmtUser = get('mgmt_user');
    $ptyCmd = 'bash -l -c ' . escapeshellarg('script -q -c ' . escapeshellarg($cmd) . ' /dev/null');
    return runLocally(sprintf(
        'ssh -o StrictHostKeyChecking=no -p %d %s@%s %s',
        $mgmtPort, $mgmtUser, $mgmtHost, escapeshellarg($ptyCmd)
    ));
}
