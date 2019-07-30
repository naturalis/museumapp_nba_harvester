<?php

class ObjectlessTaxaData extends BaseClass
{
    const TABLE = 'taxa_no_objects';

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
    }

    public function __destruct ()
    {
        $this->log('Ready! Inserted ' . $this->imported . ' out of ' .
            $this->total . ' taxa', 3, "taxa_no_objects");
    }

    public function import ()
    {
        $this->connectDatabase();

        ini_set("auto_detect_line_endings", true);

        if (!($fh = fopen($this->csvPath, "r"))) {
            $this->log("Cannot read " . $this->csvPath,1);
            exit();
        }

        $this->log("truncating table",3, "taxa_no_objects");

        $this->db->query("truncate " . self::TABLE);

        while ($row = fgetcsv($fh, 1000, ","))
        {
            $this->insertData($row);
        }
        fclose($fh);
    }

    private function extractData ($row)
    {
        return [
            "taxon" => trim($row[0]),
            "main_image" => trim($row[1])
        ];
    }

    private function insertData ($row)
    {
        $data = $this->extractData($row);
        
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
