<?php

    class IUCNData extends BaseClass
    {
        private $URLs = [ "regions" => null, "species" => null, "citation" => null ];
        private $token;
        private $taxonList;
        private $taxonListExisting;
        private $regions;
        private $statuses;
        private $sleepInterval = 2; // sec.

        const TABLE_TAXONLIST = 'taxonlist';
        const TABLE_IUCN = 'iucn';

        public function setSleepInterval( $sleepInterval )
        {
            $this->sleepInterval = $sleepInterval;
        }

        public function setIucnToken( $token )
        {
            $this->token = $token;
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
            $sql = $this->db->query("select * from " . self::TABLE_TAXONLIST);
            $list=[];

            while ($row = $sql->fetch_assoc())
            {
                $this->taxonList[]=$row;
            }
        }

        public function getRegions()
        {
            $url = sprintf($this->URLs["regions"], $this->token);
            $json = file_get_contents($url);
            $data = json_decode($json,true);

            $this->regions = (array)$data["results"];

            usort($this->regions, function($a,$b)
            {
                if ($a["identifier"]=="global") return -1;
                if ($b["identifier"]=="global") return 1;
                return $a>$b;
            });
        }

        public function filterTaxonList()
        {
            $taxonListExisting=[];

            foreach ($this->taxonList as $taxon)
            {
                $url = sprintf($this->URLs["citation"], rawurlencode($taxon["taxon"]), $this->token);
                $json = file_get_contents($url);
                $data = json_decode($json,true);

                if(isset($data["name"]) && isset($data["result"]))
                {
                    $taxonListExisting[]=$taxon;
                    $this->log(sprintf("existing taxon: '%s'",$taxon["taxon"]),3, "IUCN");
                }
                else
                {
                    $this->log(sprintf("non existing taxon: '%s'",$taxon["taxon"]),3, "IUCN");
                }

                sleep($this->sleepInterval);
            }

            $this->taxonList = $taxonListExisting;
        }

        public function getIUCNStatuses()
        {
            foreach ($this->taxonList as $taxon)
            {
                foreach ($this->regions as $rKey => $region)
                {
                    $url = sprintf($this->URLs["species"], rawurlencode($taxon["taxon"]), $region["identifier"], $this->token);
                    $json = file_get_contents($url);
                    $data = json_decode($json,true);

                    if (isset($data["result"]) && !empty($data["result"]))
                    {
                        $this->statuses[]=[ "taxon" => $taxon["taxon"], "region" => $region["name"], "data" => $data["result"][0] ];
                        $this->log(sprintf("found status for '%s' in '%s'",$taxon["taxon"],$region["identifier"]),3, "IUCN");

                        if ($rKey==0) // global
                        {
                            break;
                        }
                    }
                }

                sleep($this->sleepInterval);
            }
        }

        public function storeData()
        {
            $this->log("truncating table", 4, "IUCN");

            $this->db->query("truncate " . self::TABLE_IUCN);

            $stmt = $this->db->prepare("insert into ".self::TABLE_IUCN." (scientific_name,region,category,criteria,population_trend,assessment_date) values (?,?,?,?,?,?)");

            foreach((array)$this->statuses as $status)
            {
                $stmt->bind_param('ssssss',
                    $status["taxon"],
                    $status["region"],
                    $status["data"]["category"],
                    $status["data"]["criteria"],
                    $status["data"]["population_trend"],
                    $status["data"]["assessment_date"]
                );

                $stmt->execute();
                $this->inserted++;
                $this->log(sprintf("inserted data for '%s'",$status["taxon"]),3, "IUCN");
            }

            $this->log("done",4, "IUCN");

        }

    }


