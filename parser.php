<?php
error_reporting(E_ALL);
set_time_limit(60*180);
ini_set('memory_limit', '1024M');

require_once 'litresConfig.php';
require_once 'LitresDBClass.php';
require_once 'LitresParserClass.php';

try {
    $db         = \LitresDBClass::getInstance([DB_HOST, DB_NAME, DB_USER, DB_PASS]);
    $parser     = new \LitresParserClass($db, [LR_DOMAIN, LR_ID, LR_SECRET, LR_TYPE, LR_START_TIME]);
    if (time() - $parser->last_point > UP_TIME) {
        echo "<hr>";
        $parser->getUpdate();
        echo "<hr>";
    }
} catch (\PDOException $e) {
    $mess = date("d.m.Y H:i:s")."\r\n".$e->getMessage()."\r\n".$e->getFile()."\r\n".$e->getLine();
    file_put_contents('DB_Exception.txt', $mess);
    $db->db_connect->rollBack();
} catch (\Exception $e) {
    $mess = date("d.m.Y H:i:s")."\r\n".$e->getMessage()."\r\n".$e->getFile()."\r\n".$e->getLine();
    file_put_contents('ALL_Exception.txt', $mess);
}
?>