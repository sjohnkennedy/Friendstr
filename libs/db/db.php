<?php

function openConnection($HOST, $DB, $USERNAME, $PASSWORD){
    !$pdo = new PDO("mysql:host=$HOST;dbname=$DB", $USERNAME, $PASSWORD);
    if (!$pdo) {
        echo 'Could not connect to mysql';
        exit;
    }
    return $pdo;
}

?>
