<?php

namespace Deployer;

use function Env\env;

// ---------------------------------------------------------------------------
// provision:configure-deploy-target
// Adiciona volume do site ao docker-compose.yml do deploy-target e reinicia
// o container. Aguarda SSH disponível na porta configurada antes de retornar.
// ---------------------------------------------------------------------------
task('provision:configure-deploy-target', function () {
    assert_easyengine();

    $domain     = get('domain');
    $compose    = '/opt/easyengine/services/deploy-target/docker-compose.yml';
    $siteAppDir = "/opt/easyengine/sites/{$domain}/app/";
    $volumeLine = "      - /opt/easyengine/sites/{$domain}/app/:/var/www/{$domain}";

    writeln("🔧 Configurando volume do deploy-target para: {$domain}");

    $restartCmd = "sudo docker compose -f {$compose} stop && sudo docker compose -f {$compose} up -d";

    $checkCmd = 'grep -qF ' . escapeshellarg($siteAppDir) . " {$compose} && echo EXISTS || echo MISSING";
    if (str_contains(trim(run_on_management_host($checkCmd)), 'EXISTS')) {
        writeln("⏭️  Volume já existe no docker-compose.yml, apenas reiniciando...");
        writeln(run_on_management_host($restartCmd) ?: 'Container reiniciado.');
    } else {
        $awkCmd    = "awk '/## Volume do site EasyEngine/{print; print \"{$volumeLine}\"; next}1' {$compose}";
        $updateCmd = "{$awkCmd} | sudo tee {$compose} > /dev/null && {$restartCmd}";
        writeln(run_on_management_host($updateCmd) ?: 'Container reiniciado.');
    }

    $sshPort = currentHost()->getPort();
    writeln("⏳ Aguardando deploy-target disponível na porta {$sshPort}...");
    run_on_management_host("timeout 60 bash -c 'until nc -z 127.0.0.1 {$sshPort} 2>/dev/null; do sleep 1; done'");

    writeln("✅ Volume adicionado e deploy-target reiniciado para: {$domain}");
})->desc('Adiciona volume do site ao deploy-target e reinicia o container SSH');

// ---------------------------------------------------------------------------
// provision:generate-shared-env
// Gera shared/.env para Bedrock com credenciais de banco + salts criptográficos.
// Credenciais: PROD_DB_* (banco externo) ou extraídas do wp-config.php nativo
// gerado pelo EasyEngine (banco gerenciado). Remove current/ ao final para que
// o Deployer crie o symlink do primeiro release.
// ---------------------------------------------------------------------------
task('provision:generate-shared-env', function () {
    $deployPath = get('deploy_path');
    $domain     = get('domain');
    $sharedEnv  = "{$deployPath}/shared/.env";
    assert_within_domain($deployPath, $sharedEnv);

    $externalDb = ee_external_db();
    $dbName   = $externalDb['DB_NAME']     ?? '';
    $dbUser   = $externalDb['DB_USER']     ?? '';
    $dbPass   = $externalDb['DB_PASSWORD'] ?? '';
    $dbHost   = $externalDb['DB_HOST']     ?? '';
    $dbPrefix = ee_db_prefix();
    $locale   = env('WP_LANG') ?: getenv('WP_LANG') ?: 'en_US';

    // Banco gerenciado: credenciais criadas pelo EE ficam no wp-config.php nativo.
    $foundConfig = trim(run("find {$deployPath}/current -maxdepth 4 -name wp-config.php -print -quit 2>/dev/null || true"));
    if ((empty($dbName) || empty($dbUser) || empty($dbPass) || empty($dbHost)) && $foundConfig !== '') {
        writeln("🔎 Extraindo credenciais de banco do wp-config.php gerado pelo EasyEngine...");
        $parsed = ee_parse_wpconfig_db($foundConfig);
        $dbName = $dbName ?: ($parsed['DB_NAME']     ?? '');
        $dbUser = $dbUser ?: ($parsed['DB_USER']     ?? '');
        $dbPass = $dbPass ?: ($parsed['DB_PASSWORD'] ?? '');
        $dbHost = $dbHost ?: ($parsed['DB_HOST']     ?? '');
    }

    foreach (['DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASSWORD' => $dbPass, 'DB_HOST' => $dbHost] as $var => $val) {
        if (empty($val)) {
            throw new \Exception("{$var} deve estar definido (no ambiente ou no wp-config.php do EasyEngine) para gerar o .env compartilhado.");
        }
    }

    $home    = 'https://' . $domain;
    $siteurl = $home . '/wp';

    // Salts em base64 sem '=' final — charset seguro dentro de aspas no .env.
    $salt = fn(int $bytes = 48): string => rtrim(base64_encode(random_bytes($bytes)), '=');

    writeln('🔐 Gerando salts e montando .env compartilhado...');
    $lines = [
        'WP_ENV=production',
        'WP_HOME=' . $home,
        'WP_SITEURL=' . $siteurl,
        'WP_LANG=' . $locale,
        '',
        'DB_NAME=' . $dbName,
        'DB_USER=' . $dbUser,
        'DB_PASSWORD="' . $dbPass . '"',
        'DB_HOST=' . $dbHost,
        'DB_PREFIX=' . $dbPrefix,
        '',
        'AUTH_KEY="'         . $salt() . '"',
        'SECURE_AUTH_KEY="'  . $salt() . '"',
        'LOGGED_IN_KEY="'    . $salt() . '"',
        'NONCE_KEY="'        . $salt() . '"',
        'AUTH_SALT="'        . $salt() . '"',
        'SECURE_AUTH_SALT="' . $salt() . '"',
        'LOGGED_IN_SALT="'   . $salt() . '"',
        'NONCE_SALT="'       . $salt() . '"',
        '',
        ...array_map(fn($key) => "{$key}=\"" . $salt(64) . '"', get('wp_extra_salt_keys', [])),
        '',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'wpenv');
    file_put_contents($tmp, implode("\n", $lines));

    run("mkdir -p {$deployPath}/shared");
    upload($tmp, $sharedEnv);
    run('chmod 600 ' . escapeshellarg($sharedEnv));
    @unlink($tmp);

    // Preserva o wp-config.php nativo do EE para referência futura em config/environments/*.php.
    $eeConfigRef = "{$deployPath}/shared/ee-wp-config.reference.php";
    if ($foundConfig !== '') {
        run('cp ' . escapeshellarg($foundConfig) . ' ' . escapeshellarg($eeConfigRef));
        writeln("🧷 wp-config.php nativo preservado em {$eeConfigRef}");

        $localRefDir = DEPLOY_ROOT . '/.ee-reference';
        if (!is_dir($localRefDir)) {
            @mkdir($localRefDir, 0775, true);
        }
        try {
            download($eeConfigRef, $localRefDir . "/wp-config.{$domain}.php");
            writeln("⬇️  Cópia local salva em .ee-reference/wp-config.{$domain}.php");
        } catch (\Throwable $e) {
            writeln("⚠️  Não foi possível baixar a cópia local (mantida apenas em shared/): " . $e->getMessage());
        }
    } else {
        writeln("ℹ️  Nenhum wp-config.php encontrado em current/ — nada a preservar.");
    }

    writeln("🗑️  Removendo current/ gerado pelo EasyEngine para o Deployer criar o symlink...");
    run("rm -rf {$deployPath}/current");

    writeln("✅ shared/.env gerado em {$sharedEnv}.");
})->desc('Gera shared/.env Bedrock com credenciais de banco e salts; remove current/ para o Deployer');

// ---------------------------------------------------------------------------
// provision:setup-shared-wpconfig
// Para projetos Native: copia o wp-config.php gerado pelo EasyEngine para
// shared/wp-config.php (arquivo compartilhado real, linkado pelo Deployer).
// Remove current/ ao final para que o Deployer crie o symlink do primeiro release.
// ---------------------------------------------------------------------------
task('provision:setup-shared-wpconfig', function () {
    assert_native_wp();

    $deployPath     = get('deploy_path');
    $domain         = get('domain');
    $sharedWpConfig = "{$deployPath}/shared/wp-config.php";
    assert_within_domain($deployPath, $sharedWpConfig);

    $foundConfig = trim(run("find {$deployPath}/current -maxdepth 4 -name wp-config.php -print -quit 2>/dev/null || true"));
    if ($foundConfig === '') {
        throw new \Exception("wp-config.php não encontrado em {$deployPath}/current — o EasyEngine criou o site corretamente?");
    }

    writeln("📋 Copiando wp-config.php do EasyEngine para shared/...");
    run("mkdir -p {$deployPath}/shared");
    run('cp ' . escapeshellarg($foundConfig) . ' ' . escapeshellarg($sharedWpConfig));
    run('chmod 600 ' . escapeshellarg($sharedWpConfig));
    writeln("✅ wp-config.php disponível em shared/wp-config.php");

    $localRefDir = DEPLOY_ROOT . '/.ee-reference';
    if (!is_dir($localRefDir)) {
        @mkdir($localRefDir, 0775, true);
    }
    try {
        download($sharedWpConfig, $localRefDir . "/wp-config.{$domain}.php");
        writeln("⬇️  Cópia local salva em .ee-reference/wp-config.{$domain}.php");
    } catch (\Throwable $e) {
        writeln("⚠️  Não foi possível baixar a cópia local (mantida apenas em shared/): " . $e->getMessage());
    }

    writeln("🗑️  Removendo current/ gerado pelo EasyEngine para o Deployer criar o symlink...");
    run("rm -rf {$deployPath}/current");
})->desc('Copia wp-config.php do EasyEngine para shared/ e remove current/ (Native WP)');

// ---------------------------------------------------------------------------
// ddev:generate-init-data
// Executa o comando DDEV `generate-init-data` localmente para exportar
// db.sql.gz e uploads.tar.gz para init/data/.
// ---------------------------------------------------------------------------
task('ddev:generate-init-data', function () {
    writeln("🔄 Gerando dados de inicialização via ddev generate-init-data...");
    runLocally('.ddev/commands/host/generate-init-data');
    writeln("✅ Dados gerados em init/data/");
})->desc('Exporta DB e uploads localmente via DDEV para init/data/');

// ---------------------------------------------------------------------------
// ee:provision:prepare — Etapa 1
// Cria o site no EasyEngine e prepara o ambiente para o primeiro deploy.
// ---------------------------------------------------------------------------
task('ee:provision:prepare', function () {
    invoke('ee:site:create');
    invoke('provision:configure-deploy-target');
    if (get_project_type() === 'native') {
        invoke('provision:setup-shared-wpconfig');
    } else {
        invoke('provision:generate-shared-env');
    }
})->desc('Provisionamento etapa 1: cria site EasyEngine e prepara shared/ (env ou wp-config)');

// ---------------------------------------------------------------------------
// ee:provision:deploy — Etapa 2
// Executa o primeiro deploy via `dep deploy`. Output transmitido em tempo real.
// ---------------------------------------------------------------------------
task('ee:provision:deploy', function () {
    $stage = currentHost()->getAlias();
    writeln("🚀 Executando primeiro deploy em {$stage}...");
    passthru("dep deploy {$stage}", $exitCode);
    if ($exitCode !== 0) {
        throw new \RuntimeException("dep deploy {$stage} falhou com código {$exitCode}");
    }
})->desc('Provisionamento etapa 2: executa o primeiro deploy');

// ---------------------------------------------------------------------------
// ee:provision:finalize — Etapa 3
// Importa dados iniciais, configura scripts de backup e ajusta URLs.
// ---------------------------------------------------------------------------
task('ee:provision:finalize', function () {
    invoke('backup:scripts');
    invoke('ddev:generate-init-data');
    invoke('init:data');
    invoke('ee:site:clean');
})->desc('Provisionamento etapa 3: importa dados, scripts de backup e corrige URLs');

// ---------------------------------------------------------------------------
// ee:provision
// Orquestrador completo: executa as três etapas de provisionamento em sequência.
// Cada etapa pode ser reexecutada individualmente se necessário.
// ---------------------------------------------------------------------------
task('ee:provision', function () {
    invoke('ee:provision:prepare');
    invoke('ee:provision:deploy');
    invoke('ee:provision:finalize');
})->desc('Provisiona site EasyEngine completo: ambiente → deploy → dados');
