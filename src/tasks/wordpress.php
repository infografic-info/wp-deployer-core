<?php

namespace Deployer;

// ---------------------------------------------------------------------------
// wp:core:update-db
// Executa `wp core update-db` no container do site via ee shell.
// ---------------------------------------------------------------------------
task('wp:core:update-db', function () {
    $domain = get('domain');
    writeln('Executando update-db via ee shell para o domínio: ' . $domain);
    $output = ee_shell($domain, 'wp core update-db');
    writeln($output);
})->desc('Atualiza o esquema do banco WordPress via WP-CLI (`wp core update-db`)');

// ---------------------------------------------------------------------------
// wp:cache:flush
// Limpa o cache WordPress e recria o symlink do object-cache Redis.
// ---------------------------------------------------------------------------
task('wp:cache:flush', function () {
    $domain = get('domain');

    writeln('🧹 Limpando cache...');
    writeln(ee_shell($domain, 'wp cache flush'));

    writeln('🔴 Ativando Redis (object-cache.php)...');
    run('ln -sf ' . redis_object_cache_source() . ' ' . redis_object_cache_target());
    writeln('✅ object-cache.php symlink criado.');
})->desc('Limpa cache WordPress e recria symlink object-cache Redis (`wp cache flush`)');

// ---------------------------------------------------------------------------
// wp:config:lock / wp:config:unlock
// Define/remove constantes de segurança no wp-config.php via WP-CLI.
// Exclusivo para Native WP — no Bedrock as constantes ficam em config/application.php.
// ---------------------------------------------------------------------------
task('wp:config:lock', function () {
    if (get_project_type() !== 'native') {
        writeln('ℹ️  Bedrock: constantes de segurança definidas em config/application.php, pulando wp:config:lock.');
        return;
    }

    $domain    = get('domain');
    $constants = [
        'AUTOMATIC_UPDATER_DISABLED' => 'true',
        'DISALLOW_FILE_EDIT'         => 'true',
        'DISALLOW_FILE_MODS'         => 'true',
    ];

    foreach ($constants as $name => $value) {
        writeln("🔒 Definindo {$name}...");
        writeln(ee_shell($domain, "wp config set {$name} {$value} --raw --type=constant"));
    }

    writeln('✅ wp-config.php bloqueado para produção.');
})->desc('Define constantes de segurança no wp-config.php via WP-CLI (Native WP)');

task('wp:config:unlock', function () {
    if (get_project_type() !== 'native') {
        writeln('ℹ️  Bedrock: constantes de segurança definidas em config/application.php, pulando wp:config:unlock.');
        return;
    }

    $domain    = get('domain');
    $constants = [
        'AUTOMATIC_UPDATER_DISABLED',
        'DISALLOW_FILE_EDIT',
        'DISALLOW_FILE_MODS',
    ];

    foreach ($constants as $name) {
        writeln("🔓 Removendo {$name}...");
        writeln(ee_shell($domain, "wp config delete {$name} --type=constant 2>/dev/null || true"));
    }

    writeln('✅ wp-config.php desbloqueado para manutenção.');
})->desc('Remove constantes de segurança do wp-config.php via WP-CLI (Native WP)');
