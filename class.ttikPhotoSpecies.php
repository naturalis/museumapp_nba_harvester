<?php

class TTIKPhotoSpecies extends BaseClass
{
    const TABLE = 'ttik_photo_species';
    private $lines=[];
    private $imageUrl;

    public function __construct ()
    {
        if (empty(getenv('REAPER_FILE_BASE_PATH')) ||
            empty(getenv('REAPER_FILE_TTIK_PHOTO_SPECIES'))) {
            $this->log('No path settings for ttik_photo_species csv file!',1, "ttik_photo_species");
            exit();
        }

        $this->csvPath =
            getenv('REAPER_FILE_BASE_PATH') . 
            getenv('REAPER_FILE_TTIK_PHOTO_SPECIES');

        if (!file_exists($this->csvPath))
        {
            $this->log(sprintf("csv file %s not found",$this->csvPath),1, "ttik_photo_species");
            exit();
        }

        if (empty(getenv('REAPER_URL_TTIK_TAXON')))
        {
            $this->log('No URL set for TTIK image retrieval!',1, "ttik_photo_species");
            exit();
        }

        $this->imageUrl = getenv('REAPER_URL_TTIK_TAXON');
    }

    public function __destruct ()
    {
        $this->log('Ready! Inserted ' . $this->imported . ' out of ' .
            $this->total . ' taxa', 3, "ttik_photo_species");
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
                $this->log(sprintf('No overview image for %s',$taxon["taxon"]),1, "ttik_photo_species");
            }
        }
    }

    public function storeData()
    {
        $this->connectDatabase();

        $this->log("truncating table",3, "ttik_photo_species");
        $this->db->query("truncate " . self::TABLE);        

        foreach ((array)$this->lines as $taxon)
        {
            $this->insertData( $taxon );
        }
    }

    private function insertData ($data)
    {
        if (!empty($data['taxon']) && !empty($data['main_image']))
        {
            $this->total++;

            $stmt = $this->db->prepare("insert into ".self::TABLE." (taxon,main_image) values (?,?)");
            $stmt->bind_param('ss', $data["taxon"], $data["main_image"]);

            if ($stmt->execute()) {
                $this->log("Inserted data for '" . $data['taxon'] . "'",3, "ttik_photo_species");
                $this->imported++;
            } else {
                $this->log("Could not insert data for '" . $data['taxon'] . "'",1, "ttik_photo_species");
            }
        }
    }
}
