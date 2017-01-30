<?php
class LitresParserClass {
    private static  $max_period     = 1296000/15;//1296000 - 15 days
    private         $db_obj         = null;
    private         $api_conf       = null;
    private         $xml            = null;
    private         $request_url    = null;
    private         $file_xml       = null;
    private         $data_books     = [];
    public          $last_point     = null;
    public          $end_point      = null;

    public function __construct (\LitresDBClass $db_obj, array $api_conf) {
        $this->db_obj       = $db_obj;
        $this->api_conf     = $api_conf;
        $this->checkLastUpdate();
    }

    private function checkLastUpdate () {
        $last_point = $this->db_obj->getLastUpdate();
        if ($last_point == false) {
            $this->last_point = $this->api_conf[4];
        } else {
            $this->last_point = $last_point;
        }
        return $this->last_point;
    }

    public function getUpdate () {
        $this->xml          = $this->curlBase($this->getRequestUrl());
        $this->file_xml     = time() . '.xml';
        file_put_contents('xml' . DIRECTORY_SEPARATOR . $this->file_xml, $this->xml);
        //$this->getRequestUrl();
        //$this->xml = file_get_contents('tmp-mini.xml');
        $this->parseXML();
        $this->saveInDB();
    }

    private function getRequestUrl () {
        $this->request_url = 'http://'.$this->api_conf[0].'/get_fresh_book/'.
                                $this->getCheckpoint().
                                    $this->getEndpoint().
                                        '&place='.$this->api_conf[1].
                                            '&type='.$this->api_conf[3].
                                                '&timestamp='.time().
                                                    '&sha='.$this->getSHA256();
        return $this->request_url;
    }

    private function getCheckpoint () {
        return '?checkpoint='.urlencode(date("Y-m-d H:i:s", $this->last_point));
    }

    private function getEndpoint () {
        if (time()-$this->last_point > self::$max_period) {
            $this->end_point = $this->last_point+self::$max_period;
            return '&endpoint='.urlencode(date("Y-m-d H:i:s", $this->end_point));
        }
        return '';
    }

    private function getSHA256 () {
        return hash('sha256', time().':'.$this->api_conf[2].':'.date("Y-m-d H:i:s", $this->last_point), false);
    }

    private function parseXML () {
        $s_xml = new \SimpleXMLElement($this->xml);

        $timestamp = $this->strDateToIntTime((string)$s_xml['timestamp']);

        $arr_available_books = [];
        foreach ($s_xml->{'updated-book'} as $book) {

            $arr_attributes = [];
            foreach ($book->attributes() as $a => $b) {
                $arr_attributes[$a] = (string)$b;
            }

            $arr_files = [];
            foreach ($book->files->file as $file) {
                $buf = [];
                foreach ($file->attributes() as $a => $b) {
                    $buf[$a] = (string)$b;
                }
                $arr_files[] = $buf;
            }

            $arr_available_books[] = [
                'title'         => (string)$book->{'book-title'}[0]['title'],
                'attributes'    => $arr_attributes,
                'files'         => $arr_files,
                'title_info'    => $this->xmlToArray($book->{'title-info'}),
                'document_info' => $this->xmlToArray($book->{'document-info'}),
                'publish_info'  => $this->xmlToArray($book->{'publish-info'}),
                'annotation'    => $this->xmlToArray($book->{'annotation'}),
                'authors'       => $this->xmlToArray($book->{'authors'}),
                'genres'        => $this->xmlToArray($book->{'genres'}),
            ];

        }

        $arr_removed_books = [];
        foreach ($s_xml->{'removed-book'} as $book) {

            $arr_attributes = [];
            foreach ($book->attributes() as $a => $b) {
                $arr_attributes[$a] = (string)$b;
            }
            $arr_removed_books[] = $arr_attributes;
        }

        $this->data_books = [
            'timestamp' => $timestamp, 
            'available' => $arr_available_books, 
            'removed' => $arr_removed_books,
        ];

        unset($s_xml);
        unset($this->xml);
    }

    private function xmlToArray ($xml_obj) {
        //$xml = simplexml_load_string($xml_string);
        $json = json_encode($xml_obj);
        $array = json_decode($json,TRUE);
        return $array;
    }

    private function strDateToIntTime ($str_date, $format_time = 'Y-m-d H:i:s') {
        $arr = date_parse_from_format($format_time, $str_date);
        if (isset($arr['hour'], $arr['minute'], $arr['second'], $arr['month'], $arr['day'], $arr['year'])) {
            return mktime($arr['hour'], $arr['minute'], $arr['second'], $arr['month'], $arr['day'], $arr['year']);
        }
        return false;
    }

    private function saveInDB () {
        $count_added        = 0; 
        $count_rewrited     = 0; 
        $count_deleted      = 0;
        $count_available    = count($this->data_books['available']);
        $count_removed      = count($this->data_books['removed']);
        //available
        foreach ($this->data_books['available'] as $available) {
            if ($this->db_obj->insertAvailableBook($available)) {
                $count_added++;
            } else {
                $count_rewrited++;
            }
            //unset($available);
        }
        //removed
        foreach ($this->data_books['removed'] as $removed) {
            if ($this->db_obj->insertRemovedBook($removed)) {
                $count_deleted++;
            }
            //unset($removed);
        }
        //timestamp
        if (is_null($this->end_point)) {
            $this->end_point = (int)$this->data_books['timestamp'];
        }
        $this->db_obj->insertLastUpdate([
            'time_int'              => $this->end_point,
            't_stamp'               => date("Y-m-d H:i:s", $this->end_point),
            'count_available'       => $count_available,
            'count_removed'         => $count_removed,
            'added'                 => $count_added,
            'rewrited'              => $count_rewrited,
            'deleted'               => $count_deleted,
            'url'                   => $this->request_url,
            'file_xml'              => $this->file_xml,
        ]);

    }

    private function curlBase ($url, $referer=null, $data=null, $proxy=null, $options=null) {
        $process = curl_init ($url);
        if(!is_null($data)) {
            curl_setopt($process, CURLOPT_POST, 1);
            curl_setopt($process, CURLOPT_POSTFIELDS, $data);
        }
        if(!is_null($options)) {
            curl_setopt_array($process,$options);
        }
        if(!is_null($proxy)) {
            curl_setopt($process, CURLOPT_PROXY, $proxy);
        }
        if(mb_substr_count($url,'https://','utf-8')>0) {
            curl_setopt($process, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($process, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($process, CURLOPT_HEADER, 0);
        curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($process, CURLOPT_COOKIEFILE, 'cookies.txt');
        curl_setopt($process, CURLOPT_COOKIEJAR, 'cookies.txt');
        curl_setopt($process, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.132 Safari/537.36');
        if ($referer !== null) {
            curl_setopt ($process , CURLOPT_REFERER , $referer);
        }
        curl_setopt($process, CURLOPT_CONNECTTIMEOUT, 60*15);
        curl_setopt($process, CURLOPT_TIMEOUT, 60*15);
        @curl_setopt($process, CURLOPT_FOLLOWLOCATION, 1);
        $resalt = curl_exec($process);
        curl_close($process);
        return $resalt;
    }

}
?>