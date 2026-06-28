<?php

namespace Deployer;

use function Env\env;

// ---------------------------------------------------------------------------
// backup:files
// Executa backup-files.sh no servidor. Ignorado no primeiro release.
// ---------------------------------------------------------------------------
task('backup:files', function () {
    if (is_first_release()) {
        writeln('ℹ️  Primeiro release detectado, backup de arquivos não será executado.');
        return;
    }

    $baseDir      = dirname(get('deploy_path'));
    $backupScript = "{$baseDir}/scripts/backup-files.sh";

    if (test("[ -f {$backupScript} ]")) {
        writeln("📦 Running backup script: {$backupScript}");
        run("cd {$baseDir} && ./scripts/backup-files.sh --with-shared-files");
        writeln('✅ Backup completed successfully');
    } else {
        writeln("⚠️  Warning: Backup script not found at {$backupScript}");
        writeln('⏭️  Skipping backup...');
    }
})->desc('Executa backup-files.sh no servidor (ignorado no primeiro release)');

// ---------------------------------------------------------------------------
// backup:db
// Executa backup-db.sh no servidor. Ignorado no primeiro release.
// ---------------------------------------------------------------------------
task('backup:db', function () {
    if (is_first_release()) {
        writeln('ℹ️  Primeiro release detectado, backup do banco não será executado.');
        return;
    }

    $baseDir        = dirname(get('deploy_path'));
    $backupDbScript = "{$baseDir}/scripts/backup-db.sh";

    if (test("[ -f {$backupDbScript} ]")) {
        writeln("📦 Running DB backup script: {$backupDbScript}");
        run("cd {$baseDir} && ./scripts/backup-db.sh");
        writeln('✅ Database backup completed successfully');
    } else {
        writeln("⚠️  Warning: DB backup script not found at {$backupDbScript}");
        writeln('⏭️  Skipping database backup...');
    }
})->desc('Executa backup-db.sh no servidor (ignorado no primeiro release)');

// ---------------------------------------------------------------------------
// backup:scripts
// Baixa scripts de backup/restore do repositório remoto para o servidor.
// URL base configurada em `scripts_base_url`; sufixo definido por stack/tipo.
// ---------------------------------------------------------------------------
task('backup:scripts', function () {
    $baseDir          = dirname(get('deploy_path'));
    $remoteScriptsDir = "{$baseDir}/scripts";
    assert_within_domain($baseDir, $remoteScriptsDir);
    $stack            = get_prod_stack();
    $type             = get_project_type();
    $scriptsUrl       = get('scripts_base_url') . "/{$stack}/{$type}";
    $scripts          = ['backup-db.sh', 'backup-files.sh', 'restore.sh'];

    run("mkdir -p {$remoteScriptsDir}/lib");

    writeln("📥 Baixando lib/common.sh...");
    run("curl -fsSL {$scriptsUrl}/lib/common.sh -o {$remoteScriptsDir}/lib/common.sh");

    foreach ($scripts as $script) {
        writeln("📥 Baixando {$script}...");
        run("curl -fsSL {$scriptsUrl}/{$script} -o {$remoteScriptsDir}/{$script}");
    }

    run("chmod +x {$remoteScriptsDir}/*.sh");
    writeln("✅ Scripts instalados em {$remoteScriptsDir} ({$stack}/{$type})");
})->desc('Baixa scripts de backup e restore do repositório remoto para o servidor');

// ---------------------------------------------------------------------------
// restore:latest
// Executa scripts/restore.sh no servidor. Ignorado se o script não existir.
// ---------------------------------------------------------------------------
task('restore:latest', function () {
    writeln('♻️  Restaurando arquivos compartilhados e banco de dados...');
    $baseDir       = dirname(get('deploy_path'));
    $restoreScript = "{$baseDir}/scripts/restore.sh";

    if (test("[ -f {$restoreScript} ]")) {
        writeln("🔄 Executando restore: {$restoreScript}");
        run("cd {$baseDir} && ./scripts/restore.sh");
        writeln('✅ Restore concluído com sucesso');
    } else {
        writeln("⚠️  Warning: Restore script not found at {$restoreScript}");
        writeln('⏭️  Skipping restore...');
    }
})->desc('Executa scripts/restore.sh no servidor para restaurar DB e arquivos');

// ---------------------------------------------------------------------------
// backup:run
// Executa o pipeline completo de backup: gera no servidor e envia ao bucket.
// ---------------------------------------------------------------------------
task('backup:run', [
    'backup:db',
    'backup:files',
    'duplicati:backup:run',
])->desc('Pipeline completo de backup: banco + arquivos → envio ao Duplicati');

// ---------------------------------------------------------------------------
// duplicati:backup:register
// Registra tarefa de backup no Duplicati via B2 e cria cron diário via EE.
// Requer B2_APPLICATION_KEY e B2_APPLICATION_KEY_ID no ambiente.
// ---------------------------------------------------------------------------
task('duplicati:backup:register', function () {
    assert_easyengine();

    $domain  = get('domain');
    $baseDir = dirname(get('deploy_path'));

    $b2Key   = env('B2_APPLICATION_KEY')    ?: getenv('B2_APPLICATION_KEY');
    $b2KeyId = env('B2_APPLICATION_KEY_ID') ?: getenv('B2_APPLICATION_KEY_ID');

    if (empty($b2Key) || empty($b2KeyId)) {
        throw new \Exception('B2_APPLICATION_KEY e B2_APPLICATION_KEY_ID devem estar definidos no .env');
    }

    $script = '/opt/easyengine/services/duplicati/create-duplicati-backup.sh';
    $cmd    = 'B2_APPLICATION_KEY=' . escapeshellarg($b2Key)
            . ' B2_APPLICATION_KEY_ID=' . escapeshellarg($b2KeyId)
            . ' ' . $script . ' ' . escapeshellarg($domain);

    writeln('📦 Registrando tarefa de backup no Duplicati para: ' . $domain);
    writeln(run_on_management_host($cmd));

    set('ee_cron_command', "{$baseDir}/scripts/backup-db.sh");
    set('ee_cron_schedule', '@daily');
    invoke('ee:cron:create');

    writeln('✅ Duplicati e cron configurados para: ' . $domain);
})->desc('Registra backup no Duplicati (B2) e cron diário de banco via EasyEngine');

// ---------------------------------------------------------------------------
// duplicati:backup:register
// Registra tarefa de backup no Duplicati via B2 e cria cron diário via EE.
// Requer B2_APPLICATION_KEY e B2_APPLICATION_KEY_ID no ambiente.
// ---------------------------------------------------------------------------
task('duplicati:backup:run', function () {
    assert_easyengine();

    $domain  = get('domain');
    $baseDir = dirname(get('deploy_path'));

    $b2Key   = env('B2_APPLICATION_KEY')    ?: getenv('B2_APPLICATION_KEY');
    $b2KeyId = env('B2_APPLICATION_KEY_ID') ?: getenv('B2_APPLICATION_KEY_ID');

    if (empty($b2Key) || empty($b2KeyId)) {
        throw new \Exception('B2_APPLICATION_KEY e B2_APPLICATION_KEY_ID devem estar definidos no .env');
    }

    $script = '/opt/easyengine/services/duplicati/run-duplicati-backup.sh';
    $cmd    = 'B2_APPLICATION_KEY=' . escapeshellarg($b2Key)
            . ' B2_APPLICATION_KEY_ID=' . escapeshellarg($b2KeyId)
            . ' ' . $script . ' ' . escapeshellarg($domain);

    writeln('📦 Executando backup no Duplicati para: ' . $domain);
    writeln(run_on_management_host($cmd));

    writeln('✅ Backup concluído no Duplicati para: ' . $domain);
})->desc('Executa backup no Duplicati (B2) via EasyEngine');
