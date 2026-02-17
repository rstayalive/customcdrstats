<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$request = $_REQUEST;
$cdrstats = FreePBX::Customcdrstats();
echo $cdrstats->showPage();
