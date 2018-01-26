<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('SPEC_PATH', dirname(__DIR__) . '/spec/');
require_once dirname(__DIR__) . '/config.core.php';
require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx = new modX();
$modx->initialize('web');
$modx->getService('error','error.modError', '', '');
$modx->addPackage('modx.registry');
$modx->addPackage('modx.sources');
$modx->addPackage('modx.transport');

$xmlFiles = [
    MODX_CORE_PATH . 'model/schema/modx.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.registry.db.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.sources.mysql.schema.xml',
    MODX_CORE_PATH . 'model/schema/modx.transport.mysql.schema.xml',
];

require_once __DIR__ . '/Builder.php';

$builder = new Builder($modx, $xmlFiles);
$builder->build();
