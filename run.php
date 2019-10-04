<?php

    $db["host"] = getEnv("MYSQL_HOST");
    $db["user"] = getEnv("MYSQL_USER");
    $db["pass"] = getEnv("MYSQL_PASSWORD");
    $db["database"] = getEnv("MYSQL_DATABASE");

    $imgSelectorDbPath = getEnv("IMAGE_SELECTOR_DB_PATH");
    $imgSquaresDbPath = getEnv("IMAGE_SQUARES_DB_PATH");

    $urlLeenImageRoot = getEnv("URL_LEENOBJECTEN_IMAGE_ROOT");


    $opt = getopt("",["source:","taxon_filter:","limit:","taxon:","mode:"]);

    if (!isset($opt["source"]))
    {
        echo "no source specified\n";
        exit(0);
    }

    include_once("class.baseClass.php");
    include_once("class.masterlistNbaData.php");
    include_once("class.leenobjectenData.php");
    include_once("class.favouritesData.php");
    include_once("class.iucnData.php");
    include_once("class.objectlessTaxaData.php");
    include_once("class.mapsData.php");
    include_once("class.brahmsData.php");
    include_once('class.imageSquaresNew.php');
    include_once('class.imageSelector.php');

    switch ($opt["source"])
    {
        case "nba":

            $n = new MasterlistNbaData;

            $n->setDatabaseCredentials( $db );
            $n->setMasterlistObjects();
            $n->runNbaQueries();

            $n->storeData();
            
            echo "inserted ",$n->getInsertedCount(),"\n";

            break;

        case "leenobjecten":

            $n = new LeenobjectenData;

            $n->setDatabaseCredentials( $db );
            $n->import();
            
            $m = new imageSquares;
            $m->setDatabaseCredentials( $db );
            $m->setDatabaseFullPath( $imgSquaresDbPath );
            $m->initialize();

            $s = new imageSelector;
            $s->setDatabaseFullPath( $imgSelectorDbPath );
            $s->initialize();

            foreach ($n->getInsertedData() as $data)
            {
                foreach((array)json_decode($data["afbeeldingen"]) as $afbeelding)
                {
                    if (empty(trim($afbeelding)))
                    {
                        continue;
                    }

                    $url = trim($urlLeenImageRoot) . trim($afbeelding);

                    try
                    {
                        echo $m->saveUnitIDNameAndUrl($data["registratienummer"],$url), "\n";
                        echo $s->saveUnitIDAndUrl($data["registratienummer"],$url),"\n";
                    }
                    catch(Exception $e)
                    {
                        echo $e->getMessage(), "\n";
                    }  
                }
            }

            break;

        case "favourites":

            $n = new FavouritesData;

            $n->setDatabaseCredentials( $db );
            $n->import();
            
            break;

        case "iucn":

            $iucnToken = isset($_ENV["REAPER_KEY_IUCN"]) ? $_ENV["REAPER_KEY_IUCN"] : null;

            $urlRegions = isset($_ENV["REAPER_URL_IUCN_REGIONS"]) ? $_ENV["REAPER_URL_IUCN_REGIONS"] : "https://apiv3.iucnredlist.org/api/v3/region/list?token=%s";
            $urlSpecies = isset($_ENV["REAPER_URL_IUCN_SPECIES"]) ? $_ENV["REAPER_URL_IUCN_SPECIES"] : "https://apiv3.iucnredlist.org/api/v3/species/%s/region/%s?token=%s";
            $urlCitation = isset($_ENV["REAPER_URL_IUCN_CITATION"]) ? $_ENV["REAPER_URL_IUCN_CITATION"] : "https://apiv3.iucnredlist.org/api/v3/species/citation/%s?token=%s";

            $sleepInterval = isset($_ENV["REAPER_RATE_LIMIT_IUCN"]) ? $_ENV["REAPER_RATE_LIMIT_IUCN"] : 0;

            $n = new IUCNData;

            $n->setDatabaseCredentials( $db );
            $n->connectDatabase();

            $n->setSleepInterval( $sleepInterval );

            $n->setIucnToken( $iucnToken );
            $n->setIucnUrl( "regions", $urlRegions );
            $n->setIucnUrl( "species", $urlSpecies );
            $n->setIucnUrl( "citation", $urlCitation );

            $n->setInsertMode( $opt["mode"] ?? "add" ); // add, replace

            if (!empty($opt["taxon"]))
            {
                $n->addIndividualTaxon( $opt["taxon"] );
            }
            else
            {
                if (!empty($opt["taxon_filter"]))
                {
                    $n->setTaxonFilter( $opt["taxon_filter"] );
                }

                $n->setTaxonLimit( isset($opt["limit"]) ?? 0 );
                $n->getTaxonList();
            }

            $n->getRegions();
            $n->getIUCNStatuses();
            $n->storeData();
            
            break;

        case "taxa_no_objects":

            $n = new ObjectlessTaxaData;

            $n->setDatabaseCredentials( $db );
            $n->readFile();
            $n->getImages();
            $n->storeData();
            
            break;

        case "maps":

            $n = new MapsData;

            $n->setDatabaseCredentials( $db );
            $n->import();
            
            break;

        // case "brahms":

        //     $n = new BrahmsData;

        //     $n->setDatabaseCredentials( $db );
        //     $n->import();
            
        //     break;

    }


