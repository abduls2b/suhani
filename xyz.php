<?php
include('master.php');
include('chktokenexpiry.php');
//========================================================================//
$cacheFile = $DARK_SIDE."/"."cookie.txt";
$tt = "1373";
$ck =  cookie_fetecher($CHRISTINE['result'],  $tt);
file_put_contents($cacheFile, $ck);
?>


