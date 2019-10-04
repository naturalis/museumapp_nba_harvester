<?php

	class imageSelector
	{
		private $sqlite_folder = "./";
		private $db_name = "medialib_url_chooser.db";
		private $backup_folder = "./bak/";
		private $db_path;
		private $db;
		private $images=[];
		private $includedUrls=[];
		private $excludedUrls=[];
		private $backupThreshold=50;
		private $user;
		private $searchTerm;

		public function initialize()
		{
			$this->_initializeSQLite();
		}

		public function setUser( $user )
		{
			$this->user=$user;
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

		public function setBackupFolder( $folder )
		{
			if (!file_exists($folder))
			{
				mkdir($folder);
			}

			$this->backup_folder=rtrim($folder,"/") . "/";
		}

		public function setIncludedUrl( $url )
		{
			$this->includedUrls[]=$url;
		}

		public function setExcludedUrl( $url )
		{
			$this->excludedUrls[]=$url;
		}

		public function saveUrls( $unitid )
		{
			$sql = $this->db->prepare('insert into selected_urls (unitid,urls,excluded_urls) values (?,?,?)');
			$sql->bindValue(1, $unitid);
			$sql->bindValue(2, json_encode($this->includedUrls));
			$sql->bindValue(3, json_encode($this->excludedUrls));
			$sql->execute();
			$this->unregisterUnitIDUser( $unitid );
			$this->_backupDatabase();
		}

		public function saveSkip( $unitid )
		{
			$sql = $this->db->prepare('insert into skipped_unitids (unitid) values (?)');
			$sql->bindValue(1, $unitid);
			@$sql->execute();
			$this->unregisterUnitIDUser( $unitid );
			$this->_backupDatabase();
		}

		public function getSkippedUrls( $unitid )
		{
			$d=[];
			foreach ($this->getMediaLibUrls( $unitid ) as $val)
			{
				$d[]=$val["url"];
			}

			return [
				"unitid" => $unitid,
				"urls" => $d,
				"excluded_urls" => []
			];
		}

		public function resetSavedUnitid( $unitid )
		{
			if (empty($unitid))
			{
				return;
			}
			$sql = $this->db->prepare('delete from selected_urls where unitid = ?');
			$sql->bindValue(1, $unitid);
			$sql->execute();
			$sql = $this->db->prepare('delete from skipped_unitids where unitid = ?');
			$sql->bindValue(1, $unitid);
			$sql->execute();

			$this->unregisterUnitIDUser();
			$this->registerUnitIDUser( $unitid );

			$this->_backupDatabase();
		}

		public function getNextUnitId()
		{
			$this->images=[];

			$results = $this->db->query("
				SELECT 
					_a.unitid,_d.user,count(*) 
				FROM
					medialib_urls _a 
				left join 
					selected_urls _b 
						on _a.unitid = _b.unitid
				left join 
					skipped_unitids _c 
						on _a.unitid = _c.unitid
				left join 
					user_select _d 
						on _a.unitid = _d.unitid
				where
					_b.unitid is null 
				and
					_c.unitid is null 
				and
					(_d.user is null or _d.user = '".$this->user."' )
				group by
					_a.unitid 
				order by
					_d.user desc, _a.unitid asc
				limit 1
			");

				// having
				// 	count(*) > 1

			$row = $results->fetchArray();

			if (empty($row))
			{
				return;
			} 
			else
			if ($row["user"]!=$this->user)
			{
				$this->registerUnitIDUser($row[0]);
			}

			$this->images = $this->getMediaLibUrls($row[0]);

			return $this->images;
		}

		public function getRemainingUnitIdCount()
		{
			$this->images=[];

			$results = $this->db->query('
				SELECT 
				    count(distinct _a.unitid) as total
				FROM
				    medialib_urls _a 
				left join 
				    selected_urls _b 
				        on _a.unitid = _b.unitid
				left join 
				    skipped_unitids _c 
				        on _a.unitid = _c.unitid
				where
				    _b.unitid is null 
				and
				    _c.unitid is null 
			');
			$row = $results->fetchArray();
			return $row["total"];
		}

		public function setSearchTerm( $term ) 
		{
			$this->searchTerm = $term;
		}

		private function getMediaLibUrls( $unitid )
		{
			$sql = $this->db->prepare('SELECT * FROM medialib_urls where unitid = ?');
			$sql->bindValue(1, $unitid);
			$results = $sql->execute();	
			$images=[];
			while ($row = $results->fetchArray())
			{
				$images[] = $row;
			}
			return $images;
		}

		public function getNextScientificName()
		{
			$results = $this->db->query('
				SELECT 
				    _a.scientific_name,count(*) 
				FROM
				    medialib_urls _a 
				left join 
				    selected_urls _b 
				        on _a.scientific_name = _b.scientific_name
				where
				    _b.scientific_name is null 
				group by
				    _a.scientific_name 
				having
				    count(*) > 1
				order by
				    _a.scientific_name
				limit 1
			');

			$row = $results->fetchArray();

			$this->data["scientific_name"]=$row["scientific_name"];


			$sql = $this->db->prepare('SELECT * FROM medialib_urls where scientific_name = ?');
			$sql->bindValue(1, $row["scientific_name"]);
			$results = $sql->execute();	
			while ($row = $results->fetchArray())
			{
				$this->data["images"][] = [ "unitid" => $row["unitid"], "url" => $row["url"]];
			}

			return $this->data;
		}

		public function saveUnitIDAndUrl( $unitid, $url )
		{
			$unitid = strtoupper(trim($unitid));
			$url = trim($url);

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

			$sql = $this->db->prepare('select count(*) as total from medialib_urls where unitid = ? and url = ?');
			$sql->bindValue(1, $unitid);
			$sql->bindValue(2, $url);
			$a = $sql->execute();	
			if ($a->fetchArray()[0]>0 )
			{
				throw new Exception("record already exists: $unitid / $url");
			}

			$sql = $this->db->prepare('insert into medialib_urls (unitid,url) values (?,?)');
			$sql->bindValue(1, $unitid);
			$sql->bindValue(2, $url);
			$sql->execute();


			$sql = $this->db->prepare('select count(*) as total from skipped_unitids where unitid = ?');
			$sql->bindValue(1, $unitid);
			$a = $sql->execute();	
			
			if ($a->fetchArray()[0]>0 )
			{
				$sql = $this->db->prepare('delete from skipped_unitids where unitid = ?');
				$sql->bindValue(1, $unitid);
				$sql->execute();
				return "$unitid: saved urls & reset previously skipped images";
			}
			else
			{
				return "$unitid: saved urls";
			}
		}

		public function addUnitIDNameAndUrl( $unitid, $url, $name )
		{
			$unitid = strtoupper(trim($unitid));
			$url = trim($url);

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

			$sql = $this->db->prepare('select count(*) as total from medialib_urls where unitid = ? and url = ?');
			$sql->bindValue(1, $unitid);
			$sql->bindValue(2, $url);
			$a = $sql->execute();	
			if ($a->fetchArray()[0]>0 )
			{
				throw new Exception("record already exists: $unitid / $url");
			}

			$sql = $this->db->prepare('insert into medialib_urls (unitid,url,scientific_name) values (?,?,?)');
			$sql->bindValue(1, $unitid);
			$sql->bindValue(2, $url);
			$sql->bindValue(3, $name);
			$sql->execute();
		}

		public function getSavedUrls( $unitid )
		{
			$sql = $this->db->prepare('SELECT * FROM selected_urls where unitid = ?');
			$sql->bindValue(1, $unitid);
			$results = $sql->execute();	
			$row = $results->fetchArray();

			return [
				"unitid" => $row["unitid"],
				"urls" => json_decode($row["urls"]),
				"excluded_urls" => json_decode($row["excluded_urls"])
			];
		}

		public function getProcessed( $batchsize=50,$offset=0)
		{

			if (empty($this->searchTerm))
			{
				$sql = $this->db->prepare('SELECT * FROM selected_urls order by unitid asc limit '.$batchsize.' offset ' . $offset);	
			}
			else
			{
				$sql = $this->db->prepare("
					SELECT
						* 
					FROM 
						selected_urls 
					where 
						unitid like '%' || ? || '%'
					order by 
						unitid asc 
					limit 
						".$batchsize." 
					offset 
						" . $offset
				);

				$sql->bindValue(1, $this->searchTerm);

			}
			
			$results = $sql->execute();

			while ($row = $results->fetchArray())
			{
				$images[] = [
					"unitid" => $row["unitid"],
					"urls" => json_decode($row["urls"]),
					"excluded_urls" => json_decode($row["excluded_urls"])
				];
			}
			return $images;
		}

		public function getProcessedCount()
		{
			$sql = $this->db->prepare('SELECT count(*) as total FROM selected_urls');
			$results = $sql->execute();
			$row = $results->fetchArray();
			return $row["total"];
		}

		public function getSkipped( $batchsize=50,$offset=0)
		{

			if (empty($this->searchTerm))
			{
				$sql = $this->db->prepare('SELECT * FROM skipped_unitids order by unitid asc limit '.$batchsize.' offset ' . $offset);
			}
			else
			{
				$sql = $this->db->prepare("
					SELECT
						* 
					FROM 
						skipped_unitids 
					where 
						unitid like '%' || ? || '%'
					order by 
						unitid asc
					limit 
						".$batchsize." 
					offset 
						" . $offset);

				$sql->bindValue(1, $this->searchTerm);

			}

			$results = $sql->execute();

			while ($row = $results->fetchArray())
			{
				$images[] = $this->getSkippedUrls($row["unitid"]);
			}
			return $images;
		}

		public function getSkippedCount()
		{
			$sql = $this->db->prepare('SELECT count(*) as total FROM skipped_unitids');
			$results = $sql->execute();
			$row = $results->fetchArray();
			return $row["total"];
		}

		private function registerUnitIDUser( $unitid )
		{
			$sql = $this->db->prepare('insert into user_select (unitid,user) values (?,?)');
			$sql->bindValue(1, $unitid);
			$sql->bindValue(2, $this->user);
			$sql->execute();
		}

		private function unregisterUnitIDUser( $unitid=null )
		{
			if (is_null($unitid))
			{
				$sql = $this->db->prepare('delete from user_select where user = ?');
				$sql->bindValue(1, $this->user);
			}
			else
			{			
				$sql = $this->db->prepare('delete from user_select where unitid = ? and user = ?');
				$sql->bindValue(1, $unitid);
				$sql->bindValue(2, $this->user);
			}
			$sql->execute();
		}

		public function setDbTransaction( $commit=false )
	  	{
			$this->db->exec( $commit ? 'COMMIT;' : 'BEGIN;' );
		}

		private function _initializeSQLite()
	  	{
			$this->db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
		}

		private function _backupDatabase()
		{
			$results = $this->db->query('SELECT count(*) as total FROM selected_urls');
			$row1 = $results->fetchArray();

			$results = $this->db->query('SELECT count(*) as total FROM skipped_unitids');
			$row2 = $results->fetchArray();

			if (($row1["total"] + $row2["total"]) % $this->backupThreshold == 0)
			{
				$dest = $this->backup_folder . time() . "-" . $this->db_name;
				copy($this->db_path, $dest);
				// passthru("gzip ./" . $dest );
			}
		}
	}
