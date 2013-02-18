<?php

include('mcquery.class.php');
$q = new MCQuery('121.45.193.22');
$q->connect();
print_r($q->basic_status());
