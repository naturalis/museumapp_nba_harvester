<?php

class BrahmsData extends BaseClass
{
    const TABLE = 'brahms';
    private $lines=[];
    private $imageUrl;
    public $imported_x;

    public function __construct ()
    {
        if (empty(getenv('REAPER_FILE_BASE_PATH')) ||
            empty(getenv('REAPER_FILE_BRAHMS'))) {
            $this->log('No path settings for brahms csv file!',1, "brahms");
            exit();
        }

        $this->csvPath =
            getenv('REAPER_FILE_BASE_PATH') . 
            getenv('REAPER_FILE_BRAHMS');

        if (!file_exists($this->csvPath))
        {
            $this->log(sprintf("csv file %s not found",$this->csvPath),1, "brahms");
            exit();
        }

        $this->imported_x = 0;            
    }

    public function __destruct ()
    {
        $this->log('Ready! Inserted ' . $this->imported . ' out of ' .
            $this->total . ' taxa', 3, "brahms");
    }

    public function import()
    {
        $this->connectDatabase();

        ini_set("auto_detect_line_endings", true);

        if (!($fh = fopen($this->csvPath, "r"))) {
            $this->log("Cannot read " . $this->csvPath,1);
            exit();
        }

        $this->emptyTable();

        while ($row = fgetcsv($fh, 1000, ","))
        {
            $this->insertData($row);
        }
        fclose($fh);
    }

    public function emptyTable()
    {
        $this->log("truncating table");
        $this->db->query("truncate " . self::TABLE);
    }

    public function insertData ($data)
    {
        if (!empty($data[0]) && !empty($data[1]))
        {
            $this->total++;

            $url = sprintf("https://medialib.naturalis.nl/file/id/%s/format/large",$data[1]);

            $stmt = $this->db->prepare("insert into ".self::TABLE." (unitid,URL) values (?,?)");
            $stmt->bind_param('ss', $data[0], $url);

            if ($stmt->execute()) {
                $this->log("Inserted data for '" . $data[0] . "'",3, "brahms");
                $this->imported++;
                $this->imported_x += $this->db->affected_rows;
            } else {
                $this->log("Could not insert data for '" . $data[0] . "'",1, "brahms");
            }
        }
    }
}



