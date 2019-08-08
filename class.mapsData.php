<?php

class MapsData extends BaseClass
{
    const TABLE = 'maps';
    private $lines=[];
    private $imageUrl;

    public function __construct ()
    {
        if (empty(getenv('REAPER_FILE_BASE_PATH')) ||
            empty(getenv('REAPER_FILE_MAPS'))) {
            $this->log('No path settings for maps csv file!',1, "maps");
            exit();
        }

        $this->csvPath =
            getenv('REAPER_FILE_BASE_PATH') . 
            getenv('REAPER_FILE_MAPS');
    }

    public function __destruct ()
    {
        $this->log('Ready! Inserted ' . $this->imported . ' out of ' .
            $this->total . ' taxa', 3, "maps");
    }

    public function import()
    {
        $this->connectDatabase();

        ini_set("auto_detect_line_endings", true);

        if (!($fh = fopen($this->csvPath, "r"))) {
            $this->log("Cannot read " . $this->csvPath,1);
            exit();
        }

        $this->log("truncating table");

        $this->db->query("truncate " . self::TABLE);

        while ($row = fgetcsv($fh, 1000, ","))
        {
            $this->insertData($row);
        }
        fclose($fh);
    }

    private function insertData ($data)
    {
        if (!empty($data[0]) && !empty($data[1]))
        {
            $this->total++;

            $stmt = $this->db->prepare("insert into ".self::TABLE." (taxon,url,text_dutch,text_english) values (?,?,?,?)");
            $stmt->bind_param('ssss', $data[0], $data[1], $data[2], $data[3]);

            if ($stmt->execute()) {
                $this->log("Inserted data for '" . $data[0] . "'",3, "maps");
                $this->imported++;
            } else {
                $this->log("Could not insert data for '" . $data[0] . "'",1, "maps");
            }
        }
    }
}
