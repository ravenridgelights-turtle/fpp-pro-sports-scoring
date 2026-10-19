<?php
$skipJSsettings = true;
$root = getenv('FPPDIR') ?: '/opt/fpp';
require_once $root . '/www/common.php';
require_once dirname(__DIR__) . '/compatibility.inc.php';
$major = pss_fppMajorVersion();
if ($major < 8 || $major > 10) {
    fwrite(STDERR, 'Pro Sports Scoring supports FPP 8, 9 and 10; detected ' . pss_fppVersion() . ".\n");
    exit(1);
}
echo 'Pro Sports Scoring ' . PSS_VERSION . ': FPP ' . pss_fppVersion() . " supported.\n";
