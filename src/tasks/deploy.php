<?php

namespace Deployer;

use function Env\env;

// ---------------------------------------------------------------------------
// deploy:validate:env
// Valida variáveis de ambiente obrigatórias listadas em `env_required`.
// ---------------------------------------------------------------------------
task('deploy:validate:env', function () {
    $required = get('env_required', []);

    foreach ($required as $key) {
        $value = env($key) ?: getenv($key);
        if (empty($value)) {
            throw new \Exception($key . ' environment variable is required');
        }
    }
});

// ---------------------------------------------------------------------------
// deploy:version:report
// Exibe versão do pacote core e dos templates no início do deploy.
// ---------------------------------------------------------------------------
task('deploy:version:report', function () {
    $name             = get('deploy_package_name', '');
    $version          = get('deploy_package_version', '0.0.0-local');
    $templatesVersion = get('deploy_templates_version', '0.0.0-local');

    writeln("📦 Deploy package: {$name}@{$version}");
    writeln("🧩 Templates version: {$templatesVersion}");
});

// ---------------------------------------------------------------------------
// deploy:update_code
// Empacota o HEAD via `git archive` e extrai no release_path do servidor.
// Usa COMMIT_SHA do ambiente (CI) ou lê do git local como fallback.
// ---------------------------------------------------------------------------
task('deploy:update_code', function () {
    run('mkdir -p {{release_path}}');

    $commitSha = getenv('COMMIT_SHA');
    if (empty($commitSha) && file_exists(DEPLOY_ROOT . '/.git')) {
        $commitSha = trim(runLocally('git rev-parse HEAD'));
    }
    $revision = $commitSha ? substr($commitSha, 0, 8) : 'unknown';

    $tmpDir      = sys_get_temp_dir();
    $archiveName = 'deploy-' . ($revision ?: 'unknown') . '.tar.gz';
    $archivePath = $tmpDir . DIRECTORY_SEPARATOR . $archiveName;

    if (file_exists($archivePath)) {
        @unlink($archivePath);
    }

    runLocally('git archive --format=tar --worktree-attributes HEAD | gzip > ' . escapeshellarg($archivePath));

    upload($archivePath, '{{release_path}}/' . $archiveName);
    run('cd {{release_path}} && tar -xzf ' . $archiveName . ' && rm -f ' . $archiveName);
    run('echo ' . escapeshellarg($revision) . ' > {{release_path}}/REVISION');
});

// ---------------------------------------------------------------------------
// deploy:update_releases_log
// Adiciona o autor do commit ao log de releases do Deployer (.dep/releases_log).
// ---------------------------------------------------------------------------
task('deploy:update_releases_log', function () {
    $commitAuthor = getenv('COMMIT_AUTHOR');

    if (empty($commitAuthor) && file_exists(DEPLOY_ROOT . '/.git')) {
        $commitAuthor = trim(shell_exec('git log -1 --pretty=format:"%an" 2>/dev/null') ?: '');
    }

    if (!empty($commitAuthor)) {
        $content = run('cat {{deploy_path}}/.dep/releases_log');
        $lines   = explode("\n", trim($content));

        if (!empty($lines)) {
            $lastLine = array_pop($lines);
            $data     = json_decode($lastLine, true);

            if ($data) {
                $data['user'] = $commitAuthor;
                $lines[]      = json_encode($data);

                $keepReleases = (int) get('keep_releases', 5);
                if (count($lines) > $keepReleases) {
                    $lines = array_slice($lines, -$keepReleases);
                }

                run('echo ' . escapeshellarg(implode("\n", $lines)) . ' > {{deploy_path}}/.dep/releases_log');
            }
        }
    }
});

// ---------------------------------------------------------------------------
// deploy:upload_vendors
// Empacota vendor/ + core WP localmente e faz upload para o servidor.
// Exclui pacotes dev (lidos do composer.lock) e caminhos em `deploy_tar_excludes`.
// Regenera o autoloader sem dev antes de empacotar e restaura no finally.
// ---------------------------------------------------------------------------
task('deploy:upload_vendors', function () {
    $root = DEPLOY_ROOT;

    if (!is_dir("{$root}/vendor")) {
        throw new \Exception(
            'vendor/ não encontrado. Execute `composer install --no-dev --prefer-dist --optimize-autoloader` antes do deploy.'
        );
    }

    $dirs = array_filter(deploy_vendor_dirs(), fn($d) => file_exists("{$root}/{$d}"));
    $files = deploy_vendor_files_from_web_root($root);
    $all = array_merge(array_values($dirs), array_values($files));

    if (empty($all)) {
        throw new \Exception('Nenhum artefato de vendor/core foi encontrado para upload.');
    }

    $fileList = implode(' ', array_map('escapeshellarg', array_values($all)));
    $archive  = sys_get_temp_dir() . '/composer-artifacts-' . time() . '.tar.gz';

    $devExcludes = [];
    $lockPath    = "{$root}/composer.lock";
    if (file_exists($lockPath)) {
        $lockData = json_decode((string) file_get_contents($lockPath), true);
        foreach (($lockData['packages-dev'] ?? []) as $pkg) {
            if (!empty($pkg['name'])) {
                $devExcludes[] = 'vendor/' . $pkg['name'];
            }
        }
    }

    $extraExcludes = get('deploy_tar_excludes', []);
    $excludes      = array_unique(array_merge($devExcludes, $extraExcludes));
    $excludeFlags  = implode(' ', array_map(fn($p) => '--exclude=' . escapeshellarg($p), $excludes));

    if (!empty($excludes)) {
        writeln('🧹 Excluindo do artefato: ' . implode(', ', $excludes));
        runLocally('cd ' . escapeshellarg($root) . ' && composer dump-autoload --no-dev --optimize --quiet');
    }

    try {
        runLocally("tar -czf {$archive} -C " . escapeshellarg($root) . " {$excludeFlags} {$fileList}");
        upload($archive, '{{release_path}}/composer-artifacts.tar.gz');
        run('cd {{release_path}} && tar -xzf composer-artifacts.tar.gz && rm -f composer-artifacts.tar.gz && find vendor -maxdepth 2 -type d -empty -delete 2>/dev/null || true');
        runLocally("rm -f {$archive}");
    } finally {
        if (!empty($excludes)) {
            runLocally('cd ' . escapeshellarg($root) . ' && composer dump-autoload --optimize --quiet');
        }
    }

    writeln('✅ Artefatos Composer enviados ao servidor.');
})->desc('Empacota e envia vendor/ para o servidor excluindo dependências dev');

// ---------------------------------------------------------------------------
// Hooks do pipeline de deploy
// ---------------------------------------------------------------------------
before('deploy:prepare', 'deploy:validate:env');
before('deploy:prepare', 'deploy:version:report');
before('deploy:prepare', 'backup:files');
before('deploy:prepare', 'backup:db');
after('deploy:shared', 'deploy:upload_vendors');
after('deploy:update_code', 'deploy:update_releases_log');
after('deploy:symlink', 'wp:core:update-db');
after('wp:core:update-db', 'wp:cache:flush');
after('wp:cache:flush', 'ee:site:restart');
after('ee:site:restart', 'ee:site:clean');
after('ee:site:clean', 'wp:config:lock');
after('deploy:failed', 'deploy:unlock');

after('rollback', function () {
    $restoreOnRollback = getenv('RESTORE_ON_ROLLBACK');
    if ($restoreOnRollback === '1' || $restoreOnRollback === 'true') {
        invoke('restore:latest');
    } else {
        writeln('ℹ️  Restore não executado automaticamente após rollback. Defina RESTORE_ON_ROLLBACK=1 para ativar.');
    }
});

after('rollback', 'ee:site:restart');

desc('Deploy WordPress via CI upload');
