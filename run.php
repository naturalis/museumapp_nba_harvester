<?php

    $db["host"] = isset($_ENV["MYSQL_HOST"]) ? $_ENV["MYSQL_HOST"] : 'mysql';
    $db["user"] = isset($_ENV["MYSQL_USER"]) ? $_ENV["MYSQL_USER"] : 'root';
    $db["pass"] = isset($_ENV["MYSQL_ROOT_PASSWORD"]) ? $_ENV["MYSQL_ROOT_PASSWORD"] : 'root';
    $db["database"] = isset($_ENV["MYSQL_DATABASE"]) ? $_ENV["MYSQL_DATABASE"] : 'reaper';

    include_once('class.masterlistNbaData.php');

    $n = new MasterlistNbaData;

    echo "fetching NBA records\n";

    $n->setDatabaseCredentials( $db );
    $n->setMasterlistObjects();
    $n->runNbaQueries();

    $n->storeData();
    
    echo "inserted ",$n->getInsertedCount(),"\n";

