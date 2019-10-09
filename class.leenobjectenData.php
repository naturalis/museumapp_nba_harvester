<?php

class LeenobjectenData extends BaseClass
{
    private $inserted_data=[];
    const TABLE = 'leenobjecten';

    public function __construct ()
    {
        if (empty(getenv('REAPER_FILE_BASE_PATH')) ||
            empty(getenv('REAPER_FILE_LEENOBJECTEN_CSV'))) {
            $this->log('No path settings for leenobjecten csv file!',1, "leenobjecten");
            exit();
        }

        $this->csvPath =
            getenv('REAPER_FILE_BASE_PATH') . 
            getenv('REAPER_FILE_LEENOBJECTEN_CSV');

        if (!file_exists($this->csvPath))
        {
            $this->log(sprintf("csv file %s not found",$this->csvPath),1, "leenobjecten");
            exit();
        }
    }

    public function __destruct ()
    {
        $this->log('Ready! Inserted ' . $this->imported . ' out of ' .
            $this->total . ' registration numbers', 3, "leenobjecten");
    }

    public function import ()
    {
        $this->connectDatabase();

        ini_set("auto_detect_line_endings", true);

        if (!($fh = fopen($this->csvPath, "r"))) {
            $this->log("Cannot read " . $this->csvPath,1);
            exit();
        }

        $tmp = file_get_contents($this->csvPath);

        if (substr_count($tmp, "\t") > substr_count($tmp, "\n"))
        {
            $sep = "\t";
            $this->log("detected csv-field seperator TAB");
        }
        else
        {
            $sep = ",";
            $this->log("detected csv-field seperator ,");
        }
        
        $this->log("truncating table");

        $this->db->query("truncate " . self::TABLE);

        while ($row = fgetcsv($fh, 1000, $sep))
        {
            $this->insertData($row);
            $this->inserted_data[] = $this->extractData($row);
        }
        fclose($fh);
    }

    public function getInsertedData ()
    {
        return $this->inserted_data;
    }

    private function extractData ($row)
    {
        if (!empty(trim($row[2])))
        {
            $row[2]=json_encode(explode(";",$row[2]));
        }

        return [
            "registratienummer" => trim($row[0]),
            "geleend_van" => trim($row[1]),
            "afbeeldingen" => trim($row[2])
        ];
    }

    private function insertData ($row)
    {
        $data = $this->extractData($row);
        if (!empty($data['registratienummer'])) {
            $this->total++;

            $stmt = $this->db->prepare("insert into ".self::TABLE." (registratienummer,geleend_van,afbeeldingen) values (?,?,?)");
            $stmt->bind_param('sss', $data["registratienummer"], $data["geleend_van"], $data["afbeeldingen"]);

            if ($stmt->execute()) {
                $this->log("Inserted data for '" . $data['registratienummer'] . "'",3, "leenobjecten");
                $this->imported++;
            } else {
                $this->log("Could not insert data for '" . $data['registratienummer'] . "'",1, "leenobjecten");
            }
        }
    }
}
