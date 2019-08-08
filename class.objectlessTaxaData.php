<?php

class ObjectlessTaxaData extends BaseClass
{
    const TABLE = 'taxa_no_objects';
    private $lines=[];
    private $imageUrl;

    public function __construct ()
    {
        if (empty(getenv('REAPER_FILE_BASE_PATH')) ||
            empty(getenv('REAPER_FILE_TAXA_NO_OBJECTS'))) {
            $this->log('No path settings for taxa_no_objects csv file!',1, "taxa_no_objects");
            exit();
        }

        $this->csvPath =
            getenv('REAPER_FILE_BASE_PATH') . 
            getenv('REAPER_FILE_TAXA_NO_OBJECTS');

        if (empty(getenv('REAPER_URL_TTIK_TAXON')))
        {
            $this->log('No URL set for TTIk image retrieval!',1, "taxa_no_objects");
            exit();
        }

        $this->imageUrl = getenv('REAPER_URL_TTIK_TAXON');
    }

    public function __destruct ()
    {
        $this->log('Ready! Inserted ' . $this->imported . ' out of ' .
            $this->total . ' taxa', 3, "taxa_no_objects");
    }

    public function readFile()
    {
        $lines = file($this->csvPath,FILE_IGNORE_NEW_LINES);

        foreach ((array)$lines as $row)
        {
            $this->lines[]= [ "taxon" => $row, "main_image" => null ];
        }
    }

    public function getImages()
    {
        foreach ((array)$this->lines as $key=>$taxon)
        {
            $url = sprintf($this->imageUrl, rawurlencode($taxon["taxon"]));
            $json = file_get_contents($url);
            $data = json_decode($json,true);

            if (isset($data["taxon"]) && isset($data["taxon"]["overview_image"]))
            {
                $this->lines[$key]["main_image"] = $data["taxon"]["overview_image"];
            }
            else
            {
                $this->log(sprintf('No overview image for %s',$taxon["taxon"]),1, "taxa_no_objects");
            }
        }
    }

    public function storeData()
    {
        $this->connectDatabase();

        $this->log("truncating table",3, "taxa_no_objects");
        $this->db->query("truncate " . self::TABLE);        

        foreach ((array)$this->lines as $taxon)
        {
            $this->insertData( $taxon );
        }
    }

    private function insertData ($data)
    {
        if (!empty($data['taxon']))
        {
            $this->total++;

            $stmt = $this->db->prepare("insert into ".self::TABLE." (taxon,main_image) values (?,?)");
            $stmt->bind_param('ss', $data["taxon"], $data["main_image"]);

            if ($stmt->execute()) {
                $this->log("Inserted data for '" . $data['taxon'] . "'",3, "taxa_no_objects");
                $this->imported++;
            } else {
                $this->log("Could not insert data for '" . $data['taxon'] . "'",1, "taxa_no_objects");
            }
        }
    }
}
