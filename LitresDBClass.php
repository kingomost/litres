<?php
class LitresDBClass {

    private static  $instance       = null;
    public          $db_connect     = null;
    public static   $univers_tabs   = [
        'title_info',
        'document_info',
        'publish_info',
        'annotation',
        'authors',
        'genres',
    ];
    
    private function __construct (array $conf) {
        $this->db_connect = new \PDO('mysql:host='.$conf[0].';dbname='.$conf[1], $conf[2], $conf[3], [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'']);
        $this->db_connect->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->checkTables();
        $this->db_connect->beginTransaction();
    }

    private function __clone () {}

    public function __destruct() {$this->db_connect->commit();}

    public static function getInstance (array $conf) {
        if (self::$instance == null) {
            self::$instance = new self ($conf);
        }
        return self::$instance;
    }

    public function checkTables() {
        $sql_last_update = "CREATE TABLE IF NOT EXISTS last_update (
            time_int            INTEGER NOT NULL UNIQUE PRIMARY KEY,
            t_stamp             TIMESTAMP NOT NULL,
            count_available     INT(11),
            count_removed       INT(11),
            added               INT(11),
            rewrited            INT(11),
            deleted             INT(11),
            url                 VARCHAR(255),
            file_xml            VARCHAR(255)
        );";
        $sql_base_attributes = "CREATE TABLE IF NOT EXISTS base_attributes (
            external_id         VARCHAR(255) NOT NULL UNIQUE PRIMARY KEY,
            title               VARCHAR(255) DEFAULT NULL,
            removed_id          VARCHAR(255) DEFAULT NULL,
            removed_uid         VARCHAR(255) DEFAULT NULL,
            removed_moved_to    VARCHAR(255) DEFAULT NULL,
            removed_uid_new     VARCHAR(255) DEFAULT NULL,
            removed_removed     VARCHAR(255) DEFAULT NULL
        );";
        $sql_files = "CREATE TABLE IF NOT EXISTS files (
            external_id         VARCHAR(255) NOT NULL,
            type                VARCHAR(32) NOT NULL,
            size                INT(11),
            PRIMARY KEY (external_id, type),
            UNIQUE (external_id, type)
        );";

        $this->db_connect->exec($sql_last_update);
        $this->db_connect->exec($sql_base_attributes);
        $this->db_connect->exec($sql_files);

        foreach (self::$univers_tabs as $tab) {
            $sql = "CREATE TABLE IF NOT EXISTS " . $tab . " (
                external_id         VARCHAR(255) NOT NULL,
                el_0                VARCHAR(32) NOT NULL,
                el_1                VARCHAR(32) DEFAULT NULL,
                el_2                VARCHAR(32) DEFAULT NULL,
                el_3                VARCHAR(32) DEFAULT NULL,
                el_4                VARCHAR(32) DEFAULT NULL,
                el_5                VARCHAR(32) DEFAULT NULL,
                el_6                VARCHAR(32) DEFAULT NULL,
                value               TEXT DEFAULT NULL,
                UNIQUE (external_id, el_0, el_1, el_2, el_3, el_4, el_5, el_6)
            );";

            $this->db_connect->exec($sql);
        }

        return true;
    }

    public function insertAvailableBook (array $book) {
        $unic_external_id   = $book['attributes']['external_id'];
        unset($book['attributes']['external_id']);

        $result = !$this->checkAndDeleteIfIssetThisId($unic_external_id);
        $this->insertBaseAttributes($unic_external_id, $book['title'], $book['attributes']);
        $this->insertFiles($unic_external_id, $book['files']);
        foreach (self::$univers_tabs as $tab) {
            $this->insertIntoUniversalTable($tab, $unic_external_id, $book[$tab]);
        }
        return $result;
    }

    private function insertBaseAttributes ($pr_key, $title, array $base) {
        //standart id !!!!!! in $sql
        if (isset($base['id'])) {
            $base['rename_original_id'] = $base['id'];
            unset($base['id']);
        }

        //check meta columns
        $clear_meta = $this->getMetaAboutTable('base_attributes');

        foreach ($base as $name_atr=>$value_atr) {
            if (!isset($clear_meta[$name_atr])) {
                $sql = "ALTER TABLE base_attributes ADD " . $name_atr . " VARCHAR(255) DEFAULT NULL;";
                $this->db_connect->exec($sql);
            }
            if (mb_strlen((string)$value_atr) > 255 && substr_count($clear_meta[$name_atr]['Type'], 'varchar') > 0) {
                $sql = "ALTER TABLE base_attributes CHANGE " . $name_atr . " " . $name_atr . " TEXT DEFAULT NULL;";
                $this->db_connect->exec($sql);
            }
        }
        
        //INSERT
        $str_list_atr       = 'external_id, title, ';
        $str_questions      = ':external_id, :title, ';
        $arr_insert         = [':external_id' => $pr_key, ':title' => $title];
        foreach ($base as $name_atr=>$value_atr) {
            //if (mb_strlen((string)$value_atr) < 1) continue;
            $str_list_atr               .= $name_atr.', ';
            $str_questions              .= ':'.$name_atr.', ';
            $arr_insert[':'.$name_atr]  = (string)$value_atr;
        }
        $str_list_atr   = trim(trim($str_list_atr), ',');
        $str_questions  = trim(trim($str_questions), ',');
        $sql            = "INSERT INTO base_attributes (".$str_list_atr.") VALUES (".$str_questions.");";
        $sql_prep       = $this->db_connect->prepare($sql);
        $sql_prep->execute($arr_insert);
    }

    private function insertFiles ($pr_key, array $base) {
        foreach ($base as $file) {
            if (!isset($file['type'], $file['size'])) continue;
            //INSERT
            $sql = "INSERT INTO files (external_id, type, size) VALUES (" . $this->db_connect->quote($pr_key) . ", " . $this->db_connect->quote($file['type']) . ", " . (int)$file['size'] . ");";
            $this->db_connect->exec($sql);
        }
    }

    private function insertIntoUniversalTable ($table, $pr_key, array $base, $chain = []) {
        foreach ($base as $element => $value) {
            $chain[] = (string)$element;
            if (is_array($value) && count($value)>0) {
                $this->insertIntoUniversalTable($table, $pr_key, $value, $chain);
            } else {
                //INSERT
                if (is_array($value)) {
                    $value = null;
                }
                $str_list_atr       = 'external_id, value, ';
                $str_questions      = ':external_id, :value, ';
                $arr_insert         = [':external_id' => $pr_key, ':value' => (string)$value];
                for ($i=0, $i_max=count($chain); $i<$i_max && $i<6; $i++) {
                    $str_list_atr               .= 'el_'.$i.', ';
                    $str_questions              .= ':el_'.$i.', ';
                    $arr_insert[':el_'.$i]      = (string)$chain[$i];
                }
                $str_list_atr   = trim(trim($str_list_atr), ',');
                $str_questions  = trim(trim($str_questions), ',');
                $sql            = "INSERT INTO " . $table . " (".$str_list_atr.") VALUES (".$str_questions.");";
                $sql_prep       = $this->db_connect->prepare($sql);
                $sql_prep->execute($arr_insert);
            }
            unset($chain[count($chain)-1]);
            $chain = array_values($chain);
        }
        return true;
    }

    public function insertRemovedBook (array $book) {
        if (!isset($book['uid'])) return false;
        $unic_external_id = $book['uid'];
        if ($this->checkAndDeleteIfIssetThisId($unic_external_id)) {
            //check meta columns
            $clear_meta = $this->getMetaAboutTable('base_attributes');
            //INSERT
            $str_list_atr       = 'external_id, ';
            $str_questions      = ':external_id, ';
            $arr_insert         = [':external_id' => $unic_external_id, ];
            foreach ($book as $name_atr=>$value_atr) {
                if (isset($clear_meta['removed_'.$name_atr])) {
                    $str_list_atr                       .= 'removed_'.$name_atr.', ';
                    $str_questions                      .= ':removed_'.$name_atr.', ';
                    $arr_insert[':removed_'.$name_atr]  = (string)$value_atr;
                }
            }
            $str_list_atr   = trim(trim($str_list_atr), ',');
            $str_questions  = trim(trim($str_questions), ',');
            $sql            = "INSERT INTO base_attributes (".$str_list_atr.") VALUES (".$str_questions.");";
            $sql_prep       = $this->db_connect->prepare($sql);
            $sql_prep->execute($arr_insert);
            return true;
        }
        return false;
    }

    public function getLastUpdate () {
        $sql = "SELECT MAX(time_int) AS time_int FROM last_update;";
        $res = $this->db_connect->query($sql);
        $ttm = $res->fetch(\PDO::FETCH_ASSOC);
        if (is_array($ttm) && isset($ttm['time_int']) && is_numeric($ttm['time_int']) && $ttm['time_int']>0) {
            return (int)$ttm['time_int'];
        }
        return false;
    }

    public function insertLastUpdate (array $last_update_data) {
        //check meta columns
        $clear_meta = $this->getMetaAboutTable('last_update');

        //INSERT
        $str_list_atr       = '';
        $str_questions      = '';
        $arr_insert         = [];
        foreach ($last_update_data as $name_atr=>$value_atr) {
            if (isset($clear_meta[$name_atr])) {
                $str_list_atr                       .= $name_atr.', ';
                $str_questions                      .= ':'.$name_atr.', ';
                $arr_insert[':'.$name_atr]          = $value_atr;
            }
        }
        $str_list_atr   = trim(trim($str_list_atr), ',');
        $str_questions  = trim(trim($str_questions), ',');
        $sql            = "INSERT INTO last_update (".$str_list_atr.") VALUES (".$str_questions.");";
        $sql_prep       = $this->db_connect->prepare($sql);
        $sql_prep->execute($arr_insert);
    }

    private function getMetaAboutTable ($table_name) {
        //check meta columns
        $pr = $this->db_connect->query("SHOW COLUMNS FROM ".$table_name.";");
        $dark_meta = $pr->fetchAll(PDO::FETCH_ASSOC);
        $clear_meta = [];
        foreach ($dark_meta as $column) {
            $field = $column['Field'];
            unset($column['Field']);
            $clear_meta[$field] = $column;
        }
        return $clear_meta;
    }

    private function checkAndDeleteIfIssetThisId ($unic_external_id) {
        $sql = "SELECT external_id FROM base_attributes WHERE external_id = ".$this->db_connect->quote($unic_external_id).";";
        $obj_res = $this->db_connect->query($sql);
        $arr_res = $obj_res->fetchAll(\PDO::FETCH_ASSOC);
        if (is_array($arr_res) && count($arr_res)>0) {
            //DELETE ALL
            $all_tables     = self::$univers_tabs;
            $all_tables[]   = 'base_attributes';
            $all_tables[]   = 'files';
            foreach ($all_tables as $table) {
                $sql = "DELETE FROM ".$table." WHERE external_id = ".$this->db_connect->quote($unic_external_id).";";
                $this->db_connect->exec($sql);
            }
            return true;
        }
        return false;
    }

}
?>