<?php
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
require_once 'autoload.php';
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
drupal_flush_all_caches();
echo "Cache cleared!\n";
