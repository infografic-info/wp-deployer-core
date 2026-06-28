<?php

declare(strict_types=1);

namespace Deployer;

if (!defined('DEPLOY_ROOT')) {
    define('DEPLOY_ROOT', getcwd());
}

require __DIR__ . '/src/helpers.php';
require __DIR__ . '/src/providers/easyengine.php';
require __DIR__ . '/src/tasks/wordpress.php';
require __DIR__ . '/src/tasks/backup.php';
require __DIR__ . '/src/tasks/maintenance.php';
require __DIR__ . '/src/tasks/provisioning.php';
require __DIR__ . '/src/tasks/deploy.php';
