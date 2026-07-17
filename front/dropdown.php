<?php

/**
 */

include("../../../inc/includes.php");

if (!Plugin::isPluginActive('0GLPIxx')) {
    Html::displayNotFoundError();
}

Session::checkCentralAccess();

$dropdown = new Plugin0GLPIXxDropdown();
include(GLPI_ROOT . "/front/dropdown.common.php");
