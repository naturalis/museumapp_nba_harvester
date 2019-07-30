<?php

    $db["host"] = isset($_ENV["MYSQL_HOST"]) ? $_ENV["MYSQL_HOST"] : 'mysql';
    $db["user"] = isset($_ENV["MYSQL_USER"]) ? $_ENV["MYSQL_USER"] : 'root';
    $db["pass"] = isset($_ENV["MYSQL_ROOT_PASSWORD"]) ? $_ENV["MYSQL_ROOT_PASSWORD"] : 'root';
    $db["database"] = isset($_ENV["MYSQL_DATABASE"]) ? $_ENV["MYSQL_DATABASE"] : 'reaper';

    $opt = getopt("",["source:"]);
    $limit = getopt("",["limit:"]) ?? 0;

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
    include_once("class.ObjectlessTaxaData.php");

    switch ($opt["source"])
    {
        case "nba":

            $n = new MasterlistNbaData;

            echo "fetching NBA records\n";

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
            $n->setTaxonLimit( $limit );
            $n->setIucnUrl( "regions", $urlRegions );
            $n->setIucnUrl( "species", $urlSpecies );
            $n->setIucnUrl( "citation", $urlCitation );

            $n->getTaxonList();
            $n->getRegions();
            $n->getIUCNStatuses();
            $n->storeData();
            
            break;


        case "taxa_no_objects":

            $n = new ObjectlessTaxaData;

            $n->setDatabaseCredentials( $db );
            $n->import();
            
            break;


    }





