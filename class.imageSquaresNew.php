<?php

	class imageSquares
	{
		private $sqlite_folder = "./";
		private $db_name = "square_images.db";
		private $backup_folder = "./bak/";
		private $db_path;
		private $db;
		private $images=[];
		private $includedUrls=[];
		private $backupThreshold=50;
		private $user;
	    private $mysql_db;
	    private $mysql_db_credentials;
	    private $objectlist=[];

		const TABLE_MASTER = 'tentoonstelling';

		public function initialize()
		{
			$this->_initializeSQLite();
			$this->_initializeMySQL();
		}

		public function setDatabaseCredentials( $p )
		{
		    $this->mysql_db_credentials = $p;
		}

		public function setDatabaseFolder( $folder )
		{
			if (!file_exists($folder))
			{
				throw new Exception("$folder doesn't exist", 1);
			}
			$this->sqlite_folder=rtrim($folder,"/") . "/";
			$this->db_path = $this->sqlite_folder . $this->db_name;			
		}

		public function setDatabaseFullPath( $path )
		{
			if (!file_exists($path))
			{
				throw new Exception("$path doesn't exist", 1);
			}
			$this->db_path = $path;			
		}

		public function setUser( $user )
		{
			$this->user=$user;
		}

		public function setBackupFolder( $folder )
		{
			if (!file_exists($folder))
			{
				mkdir($folder);
			}

			$this->backup_folder=rtrim($folder,"/") . "/";
		}

		public function setDbTransaction( $commit=false )
	  	{
			$this->db->exec( $commit ? 'COMMIT;' : 'BEGIN;' );
		}

		public function saveUnitIDNameAndUrl( $unitid, $url )
		{
			$unitid = trim($unitid);
			$url = trim($url);
			$url = str_ireplace("http://", "https://", $url);

			if (strlen($unitid)==0)
			{
				throw new Exception("empty unit_id");
			}

			if (strlen($url)==0)
			{
				throw new Exception("empty URL");
			}

			if (!filter_var($url, FILTER_VALIDATE_URL))
			{
			    throw new Exception("not a valid URL: $url");
			}

			$parse = parse_url($url);

			if ($parse["host"]!="medialib.naturalis.nl")
			{
			    // throw new Exception("not a medialib URL: $url");
			}

			$ref = $this->_getMasterlistRef($unitid);

			if (empty($ref["unitid"]))
			{
			    throw new Exception("can't find unitid in masterlist: $unitid");
			}

			if (empty($ref["name"]))
			{
			    throw new Exception("can't find a name for unitid in masterlist: $unitid");
			}

			$name = $ref["name"];

			$sql = $this->db->prepare('select count(*) as total from medialib_urls_new where url = ? and unitid = ?');
			$sql->bindValue(1, $url);
			$sql->bindValue(2, $unitid);
			$a = $sql->execute();	
			if ($a->fetchArray()[0]>0 )
			{
				throw new Exception("already exists '$url' for '$unitid'");
			}

			$sql = $this->db->prepare('insert into medialib_urls_new (unitid,scientific_name,url) values (?,?,?)');
			$sql->bindValue(1, $unitid);
			$sql->bindValue(2, $name);
			$sql->bindValue(3, $url);
			$sql->execute();


			$sql = $this->db->prepare('select count(*) as total from skipped_scientific_names_new where scientific_name = ?');
			$sql->bindValue(1, $name);
			$a = $sql->execute();	
			
			if ($a->fetchArray()[0]>0 )
			{
				$sql = $this->db->prepare('delete from skipped_scientific_names_new where scientific_name = ?');
				$sql->bindValue(1, $name);
				$sql->execute();
				return "$unitid: saved '$url' & reset previously skipped images";
			}
			else
			{
				return "$unitid: saved '$url'";
			}
		}

		public function getNextScientificName( $name=null )
		{

			if (!is_null($name))
			{
				$this->_unregisterScientificNameUser();
			}

			$this->images=[];

			$results = $this->db->query("
				SELECT 
					_a.scientific_name,_d.user,count(*) 
				FROM
					medialib_urls_new _a 
				left join 
					squares_new _b 
						on _a.scientific_name = _b.scientific_name
				left join 
					skipped_scientific_names_new _c 
						on _a.scientific_name = _c.scientific_name
				left join 
					user_select_new _d 
						on _a.scientific_name = _d.scientific_name
				where
					_b.scientific_name is null 
				and
					_c.scientific_name is null 
				and
					(_d.user is null or _d.user = '".$this->user."' )
				" . ( !is_null($name) ? "and _a.scientific_name = '".$this->db->escapeString($name)."' " : ""  ) . "
				group by
					_a.scientific_name 
				order by
					_d.user desc, _a.scientific_name asc
				limit 1
			");

			$row = $results->fetchArray();

			if (empty($row))
			{
				return;
			}
			else
			if ($row["user"]!=$this->user)
			{

				$this->_registerScientificNameUser($row[0]);
			}

			$this->images = $this->_getMediaLibUrls($row[0]);

			return $this->images;
		}

		public function saveSquare( $p )
		{
			foreach(["url","unitId","name","isFirefox","x1","y1","x2","y2","h","w"] as $x)
			{
				if (!isset($p[$x]) || $p[$x]=="")
				{
					throw new Exception("missing value: $x", 1);	
				}
			}

			if (!filter_var($p["url"], FILTER_VALIDATE_URL))
			{
			    throw new Exception("not a valid URL: $url");
			}

			$displayW = $p["w"];
			$displayH = $p["h"];

			$dim = getimagesize($p["url"]);
			$originalW = $dim[0];
			$originalH = $dim[1];

			$factor = ((($originalW / $displayW) + ($originalH / $displayH)) / 2);
			
			$square["x1"] = round($p["x1"] * $factor);
			$square["y1"] = round($p["y1"] * $factor);
			// $square["x2"] = round($p["x2"] * $factor);
			// $square["y2"] = round($p["y2"] * $factor);
			$square["width"] = ceil(($p["x2"] - $p["x1"]) * $factor);

			$square["auto_orient"] = ($p["isFirefox"]==="1");

			//http://www.imagemagick.org/script/command-line-options.php?#crop
			// width & height + x1,y1

			// print_r($p);
			// print_r($square);

			$sql = $this->db->prepare('insert into squares_new (scientific_name,url,unitid,square_coordinates) values (?,?,?,?)');
			$sql->bindValue(1, $p["name"]);
			$sql->bindValue(2, $p["url"]);
			$sql->bindValue(3, $p["unitId"]);
			$sql->bindValue(4, json_encode($square));
			$sql->execute();
			$this->_unregisterScientificNameUser($p["name"]);
			$this->_backupDatabase();
		}

		public function saveSkip( $name )
		{
			$sql = $this->db->prepare('insert into skipped_scientific_names_new (scientific_name) values (?)');
			$sql->bindValue(1, $name);
			@$sql->execute();
			$this->_unregisterScientificNameUser($name);
			$this->_backupDatabase();
		}

		public function getSkipped( $batchsize=50,$offset=0)
		{

			$sql = $this->db->prepare('SELECT * FROM skipped_scientific_names_new order by lower(scientific_name) asc limit '.$batchsize.' offset ' . $offset);
			$results = $sql->execute();

			while ($row = $results->fetchArray())
			{
				$images[] = $this->_getSkippedUrls($row["scientific_name"]);
			}
			return $images;
		}

		public function getProcessed( $batchsize=50,$offset=0)
		{
			$sql = $this->db->prepare('
				SELECT
					_a.*,
					"http://145.136.242.65:8080/squared_images/" || _b.filename as url_squared
				FROM 
					squares_new _a
				left join 
					squared_images_new _b 
						on _a.scientific_name = _b.scientific_name
				order by
					lower(_a.scientific_name) asc
				limit ' . 
					$batchsize.' 
				offset ' . 
					$offset);

			$results = $sql->execute();

			while ($row = $results->fetchArray())
			{
				$images[] = $row;
			}
			return $images;
		}

		public function resetSavedScientificName( $name )
		{
			if (empty($name))
			{
				return;
			}

			$sql = $this->db->prepare('delete from squares_new where scientific_name = ?');
			$sql->bindValue(1, $name);
			$sql->execute();

			$sql = $this->db->prepare('delete from skipped_scientific_names_new where scientific_name = ?');
			$sql->bindValue(1, $name);
			$sql->execute();

			$sql = $this->db->prepare('delete from squared_images_new where scientific_name = ?');
			$sql->bindValue(1, $name);
			$sql->execute();

			$this->_unregisterScientificNameUser();
			$this->_backupDatabase();
		}

		public function getRemainingScientificNameCount()
		{
			$results = $this->db->query('
				SELECT 
				    count(distinct _a.scientific_name) as total
				FROM
				    medialib_urls_new _a 
				left join 
				    squares_new _b 
				        on _a.scientific_name = _b.scientific_name
				left join 
				    skipped_scientific_names_new _c 
				        on _a.scientific_name = _c.scientific_name
				where
				    _b.scientific_name is null 
				and
				    _c.scientific_name is null 
			');
			$row = $results->fetchArray();
			return $row["total"];
		}

		public function getProcessedCount()
		{
			$sql = $this->db->prepare('SELECT count(*) as total FROM squares_new');
			$results = $sql->execute();
			$row = $results->fetchArray();
			return $row["total"];
		}

		public function getSkippedCount()
		{
			$sql = $this->db->prepare('SELECT count(*) as total FROM skipped_scientific_names_new');
			$results = $sql->execute();
			$row = $results->fetchArray();
			return $row["total"];
		}




        private function _getMasterlistRef( $unitid )
        {
            $sql = $this->mysql_db->query("select Registratienummer as unitid, `SCname controle` as name 
            	from " . self::TABLE_MASTER . " where Registratienummer = '". $this->mysql_db->real_escape_string($unitid) ."'");
            $row = $sql->fetch_assoc();
			return $row;
        }

		private function _initializeSQLite()
	  	{
	  		// $this->db_path = $this->sqlite_folder . $this->db_name;
			$this->db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
		}

		private function _backupDatabase()
		{
			$results = $this->db->query('SELECT count(*) as total FROM squares_new');
			$row1 = $results->fetchArray();

			$results = $this->db->query('SELECT count(*) as total FROM skipped_scientific_names_new');
			$row2 = $results->fetchArray();

			if (($row1["total"] + $row2["total"]) % $this->backupThreshold == 0)
			{
				$dest = $this->backup_folder . time() . "-" . $this->db_name;
				copy($this->db_path, $dest);
				// passthru("gzip ./" . $dest );
			}
		}

		private function _initializeMySQL()
		{
			if (!isset($this->mysql_db_credentials))
			{
				return;
			}

		    $this->mysql_db = new mysqli(
		        $this->mysql_db_credentials["host"],
		        $this->mysql_db_credentials["user"],
		        $this->mysql_db_credentials["pass"]
		    );

		    $this->mysql_db->select_db($this->mysql_db_credentials["database"]);
		    $this->mysql_db->set_charset("utf8");
		}

		private function _registerScientificNameUser( $scientific_name )
		{
			$sql = $this->db->prepare('insert into user_select_new (scientific_name,user) values (?,?)');
			$sql->bindValue(1, $scientific_name);
			$sql->bindValue(2, $this->user);
			$sql->execute();
		}

		private function _unregisterScientificNameUser( $scientific_name=null )
		{
			if (is_null($scientific_name))
			{
				$sql = $this->db->prepare('delete from user_select_new where user = ?');
				$sql->bindValue(1, $this->user);
			}
			else
			{			
				$sql = $this->db->prepare('delete from user_select_new where scientific_name = ? and user = ?');
				$sql->bindValue(1, $scientific_name);
				$sql->bindValue(2, $this->user);
			}
			$sql->execute();
		}

		private function _getMediaLibUrls( $scientific_name )
		{
			$sql = $this->db->prepare('SELECT * FROM medialib_urls_new where scientific_name = ?');
			$sql->bindValue(1, $scientific_name);
			$results = $sql->execute();	
			$images=[];
			while ($row = $results->fetchArray())
			{
				$images[] = $row;
			}

			return $images;
		}

		private function _getSkippedUrls( $name )
		{
			$d=[];
			foreach ($this->_getMediaLibUrls( $name ) as $val)
			{
				$d[]=$val["url"];
			}

			return [
				"scientific_name" => $name,
				"urls" => $d,
			];

			return $this->images;
		}

	}
