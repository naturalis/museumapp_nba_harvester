<?php

/*

CREATE TABLE `nba` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `unitid` varchar(50) DEFAULT NULL,
    `name` varchar(1024) DEFAULT NULL,
    `gatheringEvent` varchar(1024) DEFAULT NULL,
    `inserted` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


*/

    class MasterlistNbaData {

        private $db;
        private $unitids = [];
        private $queryTpl = '{
            "conditions" : [
                { "field" : "unitID", "operator" : "IN", "value" : [ %s ] }
            ],
            "fields" : [ "unitID", "gatheringEvent", "identifications", "kindOfUnit" , "sex" , "collectionType" , "phaseOrStage" ],
            "size" : 1024
        }';
        private $apiUrl = 'https://api.biodiversitydata.nl/v2/specimen/query/?_querySpec=%s';
        private $data=[];
        private $inserted=0;

        const TABLE_MASTER = 'tentoonstelling';
        const CHUNK_SIZE = 750;
        const MAX_CHUNK_SIZE = 1024;

        public function setDatabaseCredentials( $p )
        {
            $this->db_credentials = $p;
        }

        public function setMasterlistObjects()
        {
            $this->_connectDatabase();
            $this->masterList = $this->_getMySQLSource( self::TABLE_MASTER );
            $this->masterList = array_unique(array_map(function($a)
            {
                return $a["Registratienummer"];
            }, $this->masterList));

            $this->log(sprintf("fecthed %s unitID's from table %s",count($this->masterList),self::TABLE_MASTER));
        }

        public function runNbaQueries()
        {
            foreach (array_chunk($this->masterList, self::CHUNK_SIZE) as $key => $chunk)
            {
                $this->chunk = array_unique(array_merge($chunk,array_map(function($a)
                {
                    return strtoupper($a);
                }, $chunk)));

                if (count($this->chunk)>self::MAX_CHUNK_SIZE)
                {
                    throw new Exception("max. 1024 id's at once", 1);
                }

                $this->_runNbaQuery();

                $this->log(sprintf("fecthed NBA records for chunk %s",$key));
            }
        }

        public function getData()
        {
            return $this->data;
        }


        public function storeData()
        {
            $this->log("truncating table");

            $this->db->query("truncate nba");

            $stmt = $this->db->prepare("insert into nba (unitid,name,document) values (?,?,?)");

            foreach($this->data as $val)
            {
                $stmt->bind_param('sss', $val["unitid"], $val["name"], $val["document"]);                
                $stmt->execute();
                $this->inserted++;
                $this->log(sprintf("inserted data for '%s'",$val["unitid"]));
            }
        }

        public function getInsertedCount()
        {
            return $this->inserted;
        }

        private function _runNbaQuery()
        {
            $query = sprintf($this->queryTpl, '"' . implode('","',$this->chunk) . '"' );
            $url = sprintf($this->apiUrl, rawurlencode($query) );
            $json = file_get_contents($url);
            $data = json_decode($json,true);

            foreach($data["resultSet"] as $item)
            {
                $name=null;
                foreach($item["item"]["identifications"] as $ident)
                {
                    if (is_null($name) || $ident["preferred"]==true)
                    {
                        $name = $ident["scientificName"];
                    }
                }

                $this->data[] = [
                    "unitid" => $item["item"]["unitID"],
                    "name" => json_encode($name),
                    "document" => json_encode($item["item"])
                ];
            }
        }


        private function _connectDatabase()
        {
            $this->db = new mysqli(
                $this->db_credentials["host"],
                $this->db_credentials["user"],
                $this->db_credentials["pass"]
            );

            $this->db->select_db($this->db_credentials["database"]);
            $this->db->set_charset("utf8");
        }

        private function _getMySQLSource( $source )
        {
            $list=[];

            try {
                $sql = $this->db->query("select * from " . $source);
                $list=[];
                while ($row = $sql->fetch_assoc())
                {
                    $list[]=$row;
                }
            } catch (Exception $e) {
                $this->log(sprintf("could not read table %s",$source),self::SYSTEM_ERROR,"collector");
            }

            return $list;
        }

        public function log($message, $level = 3)
        {
            $levels = [
                1 => 'Error',
                2 => 'Warning',
                3 => 'Info',
                4 => 'Debug',
            ];
            echo date('d-M-Y H:i:s') . ' - ' . 'NBA' . ' - ' .
                $levels[$level] . ' - ' . $message . "\n";
        }
    }
