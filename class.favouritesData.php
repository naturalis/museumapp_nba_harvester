<?php

class FavouritesData extends BaseClass
{
    const TABLE = 'favourites';

    public function __construct ()
    {
        if (empty(getenv('REAPER_FILE_BASE_PATH')) ||
            empty(getenv('REAPER_FILE_FAVOURITES_TXT'))) {
            $this->log('No path settings for favourites txt file!',1, "favourites");
            exit();
        }

        $this->csvPath =
            getenv('REAPER_FILE_BASE_PATH') . 
            getenv('REAPER_FILE_FAVOURITES_TXT');
    }

    public function __destruct ()
    {
        $this->log('Ready! Inserted ' . $this->imported . ' out of ' .
            $this->total . ' taxa', 3, "favourites");
    }

    public function import ()
    {
        $this->connectDatabase();

        ini_set("auto_detect_line_endings", true);

        $this->log("truncating table",3, "favourites");

        $this->db->query("truncate " . self::TABLE);

        $lines = file($this->csvPath,FILE_IGNORE_NEW_LINES);

        foreach ((array)$lines as $row)
        {
            $this->insertData($row);
        }
    }

    private function insertData ($data)
    {
        if (!empty($data))
        {
            $this->total++;

            $stmt = $this->db->prepare("insert into ".self::TABLE." (taxon,rank) values (?,?)");
            $stmt->bind_param('ss', $data, $this->total);

            if ($stmt->execute()) {
                $this->log("Inserted rank ".$this->total." for '" . $data . "'",3, "favourites");
                $this->imported++;
            } else {
                $this->log("Could not insert data for '" . $data . "'",1, "favourites");
            }
        }
    }
}
