<?php

    class IUCNData extends BaseClass
    {
        private $URLs = [ "regions" => null, "species" => null, "citation" => null ];
        private $token;
        private $taxonList;
        private $taxonListExisting;
        private $taxonFilter=[];
        private $regions;
        private $statuses;
        private $sleepInterval = 2; // sec.
        private $mode = "add";
        private $taxonLimit = 0;
        private $collectionsToIgnore = [
            "Mineralogy",
            "Mineralogy and Petrology",
            "Paleontology",
            "Paleontology Invertebrates",
            "Paleontology Vertebrates",
            "Petrology"
        ];

        const TABLE_TAXONLIST = 'taxonlist';
        const TABLE_IUCN = 'iucn';

        public function __construct ()
        {
            $this->ctx = stream_context_create(
                [ "http" =>  [ "timeout" => 5 ] ],
                [ "notification" => [ $this, "stream_notification_callback" ] ] 
            );
        }

        public function setSleepInterval( $sleepInterval )
        {
            $this->sleepInterval = $sleepInterval;
        }

        public function setIucnToken( $token )
        {
            $this->token = $token;
        }

        public function addIndividualTaxon( $taxon )
        {
            $this->taxonList[] = [ "taxon" => $taxon ];
        }

        public function setTaxonFilter( $taxon )
        {
            if (!is_null(json_decode($taxon)))
            {
                $this->taxonFilter = json_decode($taxon);
            }
            else
            {
                $this->taxonFilter[] = $taxon;
            }
        }

        public function setInsertMode( $mode )
        {
            if (in_array($mode, ["add","replace"]))
            {
                $this->mode = $mode;
            }
        }

        public function setTaxonLimit( $limit )
        {
            // for debugging!
            $this->taxonLimit = $limit;
        }

        public function setIucnUrl( $type, $url )
        {
            if (array_key_exists($type, $this->URLs))
            {
                if (filter_var($url, FILTER_VALIDATE_URL))
                {
                    $this->URLs[$type] = $url;
                }
                else
                {
                    throw new Exception(sprintf("invalid URL: %s",$url), 1);                    
                }
            }
            else
            {
                throw new Exception(sprintf("URL type: %s",$type), 1);                    
            }
        }

        public function getTaxonList()
        {
            $this->log(sprintf("ignoring collections: %s",implode("; ",$this->collectionsToIgnore)),3, "IUCN");

            if ($this->mode=="replace")
            {
                $sql = $this->db->query("select * from " . self::TABLE_TAXONLIST . " where collection not in ('".implode("','", $this->collectionsToIgnore)."')");
            }
            else
            {
                $sql = $this->db->query("select _a.* from ".self::TABLE_TAXONLIST." _a left join ".self::TABLE_IUCN." _b on _a.taxon = _b.scientific_name where _b.id is null and _a.collection not in ('".implode("','", $this->collectionsToIgnore)."')");
            }

            $list=[];

            while ($row = $sql->fetch_assoc())
            {
                if (empty($this->taxonFilter) || in_array($row["taxon"], $this->taxonFilter))
                {
                    $this->taxonList[]=$row;
                }
            }

            $this->log(sprintf("got %s taxa (mode: %s)",count((array)$this->taxonList),$this->mode),3, "IUCN");
        }

        public function getRegions()
        {
            $url = sprintf($this->URLs["regions"], $this->token);
            $json = file_get_contents($url,false,$this->ctx);
            $data = json_decode($json,true);

            $this->regions = (array)$data["results"];

            usort($this->regions, function($a,$b)
            {
                if ($a["identifier"]=="global") return -1;
                if ($b["identifier"]=="global") return 1;
                return $a>$b;
            });

            $this->log(sprintf("retrieved %s regions",count($this->regions)),3, "IUCN");
        }

        public function getIUCNStatuses()
        {

            $this->log(sprintf("set sleep interval %ss.",$this->sleepInterval),3, "IUCN");

            $found=[];

            foreach ((array)$this->taxonList as $taxon)
            {
                foreach ($this->regions as $rKey => $region)
                {
                    $data = $this->_fetchRegionTaxonData($taxon["taxon"],$region["identifier"]);

                    if (isset($data["result"]) && !empty($data["result"]))
                    {
                        $this->statuses[]=[ "taxon" => $taxon["taxon"], "region" => $region["name"], "data" => $data["result"][0] ];
                        $this->log(sprintf("found status for '%s' in '%s'",$taxon["taxon"],$region["identifier"]),3, "IUCN");
                        $found[$taxon["taxon"]]=true;

                        if ($region["identifier"]=="global")
                        {
                            break;
                        }
                    }
                }

                if ((!isset($found[$taxon["taxon"]]) || !$found[$taxon["taxon"]]) && !empty($taxon["synonyms"]))
                {
                    foreach ($this->regions as $rKey => $region)
                    {
                        foreach (json_decode($taxon["synonyms"]) as $synonym)
                        {
                            $data = $this->_fetchRegionTaxonData($synonym,$region["identifier"]);

                            if (isset($data["result"]) && !empty($data["result"]))
                            {
                                $this->statuses[]=[ "taxon" => $taxon["taxon"], "region" => $region["name"], "data" => $data["result"][0] ];
                                $this->log(sprintf("found status for '%s' in '%s' via synonym '%s'",$taxon["taxon"],$region["identifier"],$synonym),3, "IUCN");
                                $found[$taxon["taxon"]]=true;

                                if ($region["identifier"]=="global")
                                {
                                    break;
                                }
                            }
                        }

                        if ($found[$taxon["taxon"]]==true)
                        {
                            break;
                        }

                    }
                }

                if(!isset($found[$taxon["taxon"]]) || !$found[$taxon["taxon"]])
                {
                    $this->log(sprintf("found no status for '%s'",$taxon["taxon"]),3, "IUCN");
                }

                flush();
                ob_flush();                    

                if ($this->taxonLimit>0 && count($found)>=$this->taxonLimit)
                {
                    $this->log(sprintf("halted retrieval on taxon limit %s (used for debugging)",$this->taxonLimit),3, "IUCN");
                    break;
                }

                sleep($this->sleepInterval);
            }
        }

        public function storeData()
        {
            if ($this->mode=="replace")
            {
                $this->log("truncating table", 4, "IUCN");
                $this->db->query("truncate " . self::TABLE_IUCN);
            }

            // $stmt = $this->db->prepare("insert into ".self::TABLE_IUCN." (scientific_name,region,category,criteria,population_trend,assessment_date) values (?,?,?,?,?,?)");
            $stmt = $this->db->prepare(
                "insert into ".self::TABLE_IUCN." 
                    (scientific_name,region,category,criteria,population_trend,assessment_date,taxonid) 
                values
                    (?,?,?,?,?,?,?)");

            foreach((array)$this->statuses as $status)
            {
                $stmt->bind_param('ssssss',
                    $status["taxon"],
                    $status["region"],
                    $status["data"]["category"],
                    $status["data"]["criteria"],
                    $status["data"]["population_trend"],
                    $status["data"]["assessment_date"],
                    $status["data"]["taxonid"]
                );

                $stmt->execute();
                $this->inserted++;
                $this->log(sprintf("inserted data for '%s'",$status["taxon"]),3, "IUCN");
            }

            $this->log("done",4, "IUCN");

        }

        private function _fetchRegionTaxonData($taxon, $region_identifier)
        {
            $url = sprintf($this->URLs["species"], rawurlencode($taxon), $region_identifier, $this->token);
            $json = file_get_contents($url,false,$this->ctx);
            $data = json_decode($json,true);
            return $data;
        }

        private function stream_notification_callback($notification_code, $severity, $message, $message_code, $bytes_transferred, $bytes_max)
        {
            switch($notification_code)
            {
                case STREAM_NOTIFY_RESOLVE:
                case STREAM_NOTIFY_AUTH_REQUIRED:
                case STREAM_NOTIFY_COMPLETED:
                case STREAM_NOTIFY_FAILURE:
                case STREAM_NOTIFY_AUTH_RESULT:
                    $serious=true;
                    $out = print_r($notification_code, $severity, $message, $message_code, $bytes_transferred, $bytes_max,true);
                    /* Ignore */
                    break;

                case STREAM_NOTIFY_REDIRECTED:
                    $out = "Being redirected to: " . $message;
                    break;

                case STREAM_NOTIFY_CONNECT:
                    $out = "Connected...";
                    break;

                case STREAM_NOTIFY_FILE_SIZE_IS:
                    $out = "Got the filesize: " . $bytes_max;
                    break;

                case STREAM_NOTIFY_MIME_TYPE_IS:
                    $out = "Found the mime-type: " . $message;
                    break;

                case STREAM_NOTIFY_PROGRESS:
                    $out = "Made some progress, downloaded " . $bytes_transferred . " so far";
                    break;
            }

            if ($serious==true)
            {
                $this->log($out,4, "IUCN");
            }
        }

    }


