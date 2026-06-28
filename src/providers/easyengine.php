<?php

namespace Deployer;

use function Env\env;
use Symfony\Component\Console\Input\InputOption;

// ---------------------------------------------------------------------------
// Opções CLI globais
// ---------------------------------------------------------------------------
option('ee-command',       null, InputOption::VALUE_OPTIONAL, 'Comando a executar via ee shell (ex: "wp cache flush", "nginx -t")', '');
option('ee-service',       null, InputOption::VALUE_OPTIONAL, 'Container de destino: php (padrão), nginx, db', '');
option('ee-cron-command',  null, InputOption::VALUE_OPTIONAL, 'Caminho completo do script ou comando a registrar no cron (ex: /var/www/site/scripts/backup-db.sh)', '');
option('ee-cron-schedule', null, InputOption::VALUE_OPTIONAL, 'Expressão de agendamento cron ou alias: @daily, @weekly, "0 3 * * *"', '@daily');

// ---------------------------------------------------------------------------
// ee:site:create
// Provisiona um novo site WordPress via CLI do EasyEngine no host de gerenciamento.
// ---------------------------------------------------------------------------
task('ee:site:create', function () {
    assert_easyengine();

    $domain     = get('domain');
    $siteTitle  = env('WP_TITLE')          ?: getenv('WP_TITLE')          ?: $domain;
    $adminEmail = env('WP_ADMIN_EMAIL')    ?: getenv('WP_ADMIN_EMAIL');
    $adminUser  = env('WP_ADMIN_USER')     ?: getenv('WP_ADMIN_USER')     ?: 'admin';
    $adminPass  = env('WP_ADMIN_PASSWORD') ?: getenv('WP_ADMIN_PASSWORD');
    $phpVersion = env('EE_PHP_VERSION')    ?: getenv('EE_PHP_VERSION')
               ?: getenv('DDEV_PHP_VERSION') ?: '8.3';
    $dbPrefix   = ee_db_prefix();
    $externalDb = ee_external_db();
    $dbManaged  = empty($externalDb);

    $required = ['WP_ADMIN_PASSWORD' => $adminPass, 'WP_ADMIN_EMAIL' => $adminEmail];
    if (!$dbManaged) {
        $required += [
            'PROD_DB_NAME'     => $externalDb['DB_NAME'],
            'PROD_DB_USER'     => $externalDb['DB_USER'],
            'PROD_DB_PASSWORD' => $externalDb['DB_PASSWORD'],
            'PROD_DB_HOST'     => $externalDb['DB_HOST'],
        ];
    }
    foreach ($required as $var => $val) {
        if (empty($val)) {
            throw new \Exception("{$var} deve estar definido para criação do site.");
        }
    }

    $cmd = 'sudo ee site create ' . escapeshellarg($domain)
         . ' --type=wp'
         . ' --title='       . escapeshellarg($siteTitle)
         . ' --admin-email=' . escapeshellarg($adminEmail)
         . ' --admin-user='  . escapeshellarg($adminUser)
         . ' --admin-pass='  . escapeshellarg($adminPass)
         . ' --php='         . escapeshellarg($phpVersion)
         . ' --public-dir=current/web';

    if (!$dbManaged) {
        $cmd .= ' --dbname=' . escapeshellarg($externalDb['DB_NAME'])
              . ' --dbuser=' . escapeshellarg($externalDb['DB_USER'])
              . ' --dbpass=' . escapeshellarg($externalDb['DB_PASSWORD'])
              . ' --dbhost=' . escapeshellarg($externalDb['DB_HOST']);
        $dbMode = "externo ({$externalDb['DB_HOST']})";
    } elseif (ee_local_db_enabled()) {
        $cmd .= ' --local-db';
        $dbMode = 'gerenciado pelo EasyEngine (container local --local-db)';
    } else {
        $dbMode = 'gerenciado pelo EasyEngine (global db)';
    }

    $cmd .= ' --dbprefix=' . escapeshellarg($dbPrefix)
          . ' --ssl=le'
          . ' --cache';

    writeln("🗄️  Banco de dados: {$dbMode}");
    writeln("🌐 Criando site EasyEngine: {$domain} (PHP {$phpVersion})...");
    $output = run_on_management_host($cmd);
    writeln($output);
    writeln("✅ Site criado: {$domain}");
})->desc('Cria site WordPress via `ee site create` — SSL, cache e banco gerenciado ou externo');

// ---------------------------------------------------------------------------
// ee:site:restart
// Reinicia containers do site no EasyEngine.
// ---------------------------------------------------------------------------
task('ee:site:restart', function () {
    assert_easyengine();

    $domain  = get('domain');
    $service = get('ee_site_restart_service', '');
    set('ee_site_restart_service', '');

    $flag  = $service ? ' --' . $service : '';
    $label = $service ? "serviço {$service}" : 'todos os serviços';

    writeln("🔄 Reiniciando {$label} do site: {$domain}");
    writeln(run_on_management_host('sudo ee site restart ' . escapeshellarg($domain) . $flag));
})->desc('Reinicia containers do site via `ee site restart` [--nginx|--php|--db] — padrão: todos');

// ---------------------------------------------------------------------------
// ee:site:clean
// Limpa caches do site (Redis, OPcache, etc.) via `ee site clean`.
// ---------------------------------------------------------------------------
task('ee:site:clean', function () {
    assert_easyengine();

    $domain = get('domain');
    writeln('🧹 Limpando caches do site via ee site clean: ' . $domain);
    $output = run_on_management_host_pty('sudo ee site clean ' . $domain);
    writeln($output);
})->desc('Limpa caches do site (Redis, OPcache) via `ee site clean`');

// ---------------------------------------------------------------------------
// ee:shell
// Executa um comando arbitrário dentro de um container do site via ee shell.
// ---------------------------------------------------------------------------
task('ee:shell', function () {
    assert_easyengine();

    $domain  = get('domain');
    $command = input()->getOption('ee-command') ?: get('ee_shell_command', '');
    $service = input()->getOption('ee-service') ?: get('ee_shell_service', '');
    set('ee_shell_service', '');

    if (empty($command)) {
        throw new \Exception("Informe o comando via --ee-command=... ou set('ee_shell_command').");
    }

    writeln(ee_shell($domain, $command, $service));
})->desc('Executa comando em container do site via `ee shell` [--ee-service=nginx|php|db] [--ee-command="..."]');

// ---------------------------------------------------------------------------
// ee:cron:create
// Registra um cron job no EasyEngine para o domínio configurado.
// ---------------------------------------------------------------------------
task('ee:cron:create', function () {
    assert_easyengine();

    $domain   = get('domain');
    $command  = input()->getOption('ee-cron-command') ?: get('ee_cron_command', '');
    $schedule = input()->getOption('ee-cron-schedule') ?: get('ee_cron_schedule', '@daily');

    if (empty($command)) {
        throw new \Exception("Informe o comando via --ee-cron-command=... ou set('ee_cron_command').");
    }

    $cmd = 'sudo ee cron create ' . escapeshellarg($domain)
         . ' --command='  . escapeshellarg($command)
         . ' --schedule=' . escapeshellarg($schedule);

    writeln("⏰ Registrando cron: {$command} ({$schedule}) para {$domain}");
    writeln(run_on_management_host($cmd));
})->desc('Registra cron job via `ee cron create` [--ee-cron-command="..."] [--ee-cron-schedule="@daily"]');
