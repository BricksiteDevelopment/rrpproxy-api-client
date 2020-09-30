<?php

require_once(dirname(__DIR__) . '/vendor/autoload.php');

use Bricksite\RRPProxy\RRPClient;

$rrp = new RRPClient('USERNAME', 'PASSWORD', false);

$result = $rrp->request('StatusDomain', ['domain' => 'domain.com']);

print_r($result);
