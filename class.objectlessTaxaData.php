<?php

class ObjectlessTaxaData extends BaseClass
{
    const TABLE = 'taxa_no_objects';
    private $lines=[];

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

        if (!file_exists($this->csvPath))
        {
            $this->log(sprintf("csv file %s not found",$this->csvPath),1, "taxa_no_objects");
            exit();
        }
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
            $this->lines[]= [ "taxon" => $row ];
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

            $stmt = $this->db->prepare("insert into ".self::TABLE." (taxon) values (?)");
            $stmt->bind_param('s', $data["taxon"]);

            if ($stmt->execute()) {
                $this->log("Inserted data for '" . $data['taxon'] . "'",3, "taxa_no_objects");
                $this->imported++;
            } else {
                $this->log("Could not insert data for '" . $data['taxon'] . "'",1, "taxa_no_objects");
            }
        }
    }
}
