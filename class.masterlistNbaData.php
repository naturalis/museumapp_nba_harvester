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

    class MasterlistNbaData extends BaseClass
    {
        private $unitids = [];
        private $queryTpl = '{
            "conditions" : [
                { "field" : "unitID", "operator" : "IN", "value" : [ %s ] }
            ],
            "fields" : [ "unitID", "gatheringEvent", "identifications", "kindOfUnit" , "recordBasis", "sex" , "collectionType" , "phaseOrStage", "preparationType", "numberOfSpecimen" ],
            "size" : 1024
        }';
        private $apiUrl = 'https://api.biodiversitydata.nl/v2/specimen/query/?_querySpec=%s';
        private $data=[];
        private $inserted=0;

        const TABLE = 'nba';
        const TABLE_MASTER = 'tentoonstelling';
        const CHUNK_SIZE = 250;
        const MAX_CHUNK_SIZE = 1024;

        public function setMasterlistObjects()
        {
            $this->connectDatabase();
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

            $this->db->query("truncate " . self::TABLE);

            $stmt = $this->db->prepare("insert into ".self::TABLE." (unitid,name,collection,document) values (?,?,?,?)");

            foreach($this->data as $val)
            {
                $stmt->bind_param('ssss', $val["unitid"], $val["name"], $val["collection"], $val["document"]);
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
                $collection=$item["item"]["collectionType"];
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
                    "collection" => $collection,
                    "document" => json_encode($item["item"])
                ];
            }
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
    }
