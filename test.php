<?php

require_once('./BookGluttonEpub.php');

$file = './H. G. Wells - The War of the Worlds.epub';

$epub = new BookGluttonEpub();
$epub->open($file);

print_r($epub->getMetaPairs());


?>