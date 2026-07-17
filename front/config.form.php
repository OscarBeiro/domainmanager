<?php

/**
 */

include('../../../inc/includes.php');

if (!Plugin::isPluginActive('0GLPIxx')) {
    Html::displayNotFoundError();
}

Session::checkRight(Plugin0GLPIXxConfig::$rightname, UPDATE);

$config = new Plugin0GLPIXxConfig();

if (isset($_POST["update"])) {
    $config->update($_POST);
    Html::back();
}

/** @var array $CFG_GLPI */
global $CFG_GLPI;

$redirect = $CFG_GLPI["root_doc"] . "/front/config.form.php";
$redirect .= "?forcetab=" . urlencode('Plugin0GLPIXxConfig$1');
Html::redirect($redirect);
