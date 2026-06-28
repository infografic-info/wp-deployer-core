<?php

namespace Deployer;

// ---------------------------------------------------------------------------
// init:db
// Envia init/data/db.sql ao servidor e inicializa o banco via WP-CLI (reset + import).
// ---------------------------------------------------------------------------
task('init:db', function () {
    $domain   = get('domain');
    $localSql = DEPLOY_ROOT . '/init/data/db.sql';

    if (!file_exists($localSql)) {
        throw new \Exception('Arquivo init/data/db.sql não encontrado.');
    }

    $baseDir   = dirname(get('deploy_path'));
    $remoteSql = "{$baseDir}/init/data/db.sql";

    run("mkdir -p {$baseDir}/init/data");

    writeln('📤 Enviando db.sql para o servidor...');
    upload($localSql, $remoteSql);

    writeln('⚠️  Resetando banco de dados...');
    writeln(ee_shell($domain, 'wp db reset --yes'));

    writeln('⚙️  Importando banco de dados via WP-CLI...');
    writeln(ee_shell($domain, 'wp db import /var/www/init/data/db.sql'));

    run("rm -f {$remoteSql}");
    writeln('✅ Banco inicializado e arquivo removido.');
})->desc('Envia init/data/db.sql e inicializa o banco no servidor via WP-CLI (reset + import)');

// ---------------------------------------------------------------------------
// init:db:replace-urls
// Substitui DDEV_PRIMARY_URL pela URL de produção em todas as tabelas.
// Detecta e atualiza Elementor se o plugin estiver ativo.
// ---------------------------------------------------------------------------
task('init:db:replace-urls', function () {
    $domain    = get('domain');
    $localUrl  = trim(getenv('DDEV_PRIMARY_URL') ?: runLocally('printenv DDEV_PRIMARY_URL'));
    $remoteUrl = 'https://' . $domain;

    if (empty($localUrl)) {
        throw new \Exception('Não foi possível obter DDEV_PRIMARY_URL. O ambiente DDEV está rodando?');
    }

    writeln("🔄 Substituindo URLs: {$localUrl} → {$remoteUrl}");
    writeln(ee_shell($domain, "wp search-replace {$localUrl} {$remoteUrl} --all-tables"));

    writeln('🧹 Limpando cache...');
    writeln(ee_shell($domain, 'wp cache flush'));

    writeln('🔍 Verificando Elementor...');
    $output = ee_shell($domain, 'wp plugin is-active elementor && echo ELEMENTOR_ACTIVE || true');

    if (str_contains($output, 'ELEMENTOR_ACTIVE')) {
        writeln('⚙️  Elementor detectado, atualizando URLs...');
        writeln(ee_shell($domain, "wp elementor replace_urls {$localUrl} {$remoteUrl}"));
        writeln(ee_shell($domain, 'wp elementor flush_css'));
    } else {
        writeln('ℹ️  Elementor não está ativo, pulando.');
    }

    writeln('✅ Substituição de URLs concluída.');
})->desc('Substitui URLs DDEV pela URL de produção em todas as tabelas via WP-CLI (com suporte a Elementor)');

// ---------------------------------------------------------------------------
// init:uploads
// Envia init/data/uploads.tar.gz ao servidor e extrai no diretório shared.
// ---------------------------------------------------------------------------
task('init:uploads', function () {
    $domain   = get('domain');
    $localTar = DEPLOY_ROOT . '/init/data/uploads.tar.gz';

    if (!file_exists($localTar)) {
        throw new \Exception('Arquivo init/data/uploads.tar.gz não encontrado.');
    }

    $baseDir     = dirname(get('deploy_path'));
    $remoteTar   = "{$baseDir}/init/data/uploads.tar.gz";
    $uploadsPath = '/var/www/' . $domain . '/htdocs/shared';

    run("mkdir -p {$baseDir}/init/data");

    writeln('📤 Enviando uploads.tar.gz para o servidor...');
    upload($localTar, $remoteTar);

    writeln('📦 Extraindo uploads...');
    run("mkdir -p {$uploadsPath}");
    run("tar -xzf {$remoteTar} -C {$uploadsPath}");

    run("rm -f {$remoteTar}");
    writeln('✅ Uploads inicializados e arquivo removido.');
})->desc('Envia init/data/uploads.tar.gz e extrai no shared do servidor');

// ---------------------------------------------------------------------------
// init:webp
// Envia init/data/webp-express.tar.gz e extrai no shared. Opcional — ignorado
// se o arquivo não existir localmente.
// ---------------------------------------------------------------------------
task('init:webp', function () {
    $localWebpTar = DEPLOY_ROOT . '/init/data/webp-express.tar.gz';

    if (!file_exists($localWebpTar)) {
        writeln('ℹ️  webp-express.tar.gz não encontrado. Pulando importação de webp-express.');
        return;
    }

    $domain        = get('domain');
    $baseDir       = dirname(get('deploy_path'));
    $remoteWebpTar = "{$baseDir}/init/data/webp-express.tar.gz";
    $uploadsPath   = '/var/www/' . $domain . '/htdocs/shared';

    run("mkdir -p {$baseDir}/init/data");
    run("mkdir -p {$uploadsPath}");

    writeln('📤 Enviando webp-express.tar.gz para o servidor...');
    upload($localWebpTar, $remoteWebpTar);

    writeln('📦 Extraindo webp-express...');
    run("tar -xzf {$remoteWebpTar} -C {$uploadsPath}");
    run("rm -f {$remoteWebpTar}");
    writeln('✅ webp-express inicializado e arquivo removido.');
})->desc('Envia init/data/webp-express.tar.gz e extrai no shared do servidor (opcional)');

// ---------------------------------------------------------------------------
// init:data
// Orquestrador: inicializa banco, uploads e webp-express em sequência.
// ---------------------------------------------------------------------------
task('init:data', [
    'init:db',
    'init:db:replace-urls',
    'init:uploads',
    'init:webp',
])->desc('Inicializa banco, uploads e webp-express no servidor a partir de init/data/');

// ---------------------------------------------------------------------------
// nginx:custom-config
// Garante o include de shared/nginx.conf no user.conf do container nginx.
// Repara entradas corrompidas e reinicia apenas o nginx ao final.
// ---------------------------------------------------------------------------
task('nginx:custom-config', function () {
    assert_easyengine();

    $domain      = get('domain');
    $userConf    = '/usr/local/openresty/nginx/conf/custom/user.conf';
    $commentLine = '# Regras de segurança geradas pelo plugin Better WP Security';
    $includeLine = 'include /var/www/htdocs/shared/nginx.conf;';
    $brokenBlock = 'n# Regras de segurança geradas pelo plugin Better WP Securityninclude /var/www/htdocs/shared/nginx.conf;n';

    $repairBrokenCmd = 'perl -i -pe '
        . escapeshellarg('s/' . preg_quote($brokenBlock, '/') . '/\n' . $commentLine . '\n' . str_replace('/', '\/', $includeLine) . '\n/g')
        . ' ' . escapeshellarg($userConf);

    $appendIfMissingCmd = 'grep -qxF ' . escapeshellarg($includeLine)
        . ' ' . escapeshellarg($userConf)
        . ' || { echo ""; echo ' . escapeshellarg($commentLine)
        . '; echo ' . escapeshellarg($includeLine)
        . '; } >> ' . escapeshellarg($userConf);

    writeln('🔧 Garantindo include de regras de segurança no user.conf para: ' . $domain);
    set('ee_shell_service', 'nginx');
    set('ee_shell_command', $repairBrokenCmd . ' && ' . $appendIfMissingCmd);
    invoke('ee:shell');

    writeln('🔄 Reiniciando nginx do site para aplicar configuração: ' . $domain);
    set('ee_site_restart_service', 'nginx');
    invoke('ee:site:restart');

    writeln('✅ Include de /var/www/htdocs/shared/nginx.conf garantido em user.conf');
})->desc('Garante include de shared/nginx.conf no user.conf e reinicia nginx');
