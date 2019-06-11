<?php
/**
 * File containing the eZDFSFileHandlerPostgresqlBackend class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 * @package Cluster
 */

/**
 * This class allows DFS based clustering using PostgresSQL
 * @package Cluster
 */
class eZDFSFileHandlerPostgresqlBackend implements eZClusterEventNotifier
{

    /**
     * Wait for n microseconds until retry if copy fails, to avoid DFS overload.
     */
    const TIME_UNTIL_RETRY = 100;

    /**
     * Max number of times a dfs file is tried to be copied.
     *
     * @var int
     */
    protected $maxCopyTries;

    protected static function writeError($string, $label = "", $backgroundClass = "")
    {
        $logName = 'cluster_error.log';
        //if ( isset( $GLOBALS['eZCurrentAccess']['name'] ) ){
        //    $logName = $GLOBALS['eZCurrentAccess']['name'] . '_cluster_error.log';
        //}

        $instanceName = OpenPABase::getCurrentSiteaccessIdentifier();
        $message = "[$instanceName] ";

        if ($label){
            $message .= "[$label] $string";
        }else{
            $message .= $string;
        }
        eZLog::write($message, $logName);
    }

    public function __construct()
    {
        $this->eventHandler = ezpEvent::getInstance();
        $fileINI = eZINI::instance( 'file.ini' );
        $this->maxCopyTries = (int)$fileINI->variable( 'eZDFSClusteringSettings', 'MaxCopyRetries' );

        if ( defined( 'CLUSTER_METADATA_TABLE_CACHE' ) )
        {
            $this->metaDataTableCache = CLUSTER_METADATA_TABLE_CACHE;
        }
        else if ( $fileINI->hasVariable( 'eZDFSClusteringSettings', 'MetaDataTableNameCache' ) )
        {
            $this->metaDataTableCache = $fileINI->variable( 'eZDFSClusteringSettings', 'MetaDataTableNameCache' );
        }

        $this->cacheDir = eZINI::instance( 'site.ini' )->variable( 'FileSettings', 'CacheDir' );
        $this->storageDir = eZINI::instance( 'site.ini' )->variable( 'FileSettings', 'StorageDir' );
    }

    /**
     * Returns the database table name to use for the specified file.
     *
     * For files detected as cache files the cache table is returned, if not
     * the generic table is returned.
     *
     * @param string $filePath
     * @return string The database table name
     */
    protected function dbTable( $filePath )
    {
        if ( $this->metaDataTableCache == $this->metaDataTable )
            return $this->metaDataTable;

        $isInCacheDir = strpos( $filePath, $this->cacheDir );
        $isInStorageDir = strpos( $filePath, $this->storageDir );

        if ( $isInCacheDir !== false && $isInStorageDir === false )
        {
            return $this->metaDataTableCache;
        }

        // example var/site/cache/my_custom_cache/storage.cache
        if ( $isInCacheDir !== false && $isInStorageDir !== false && ( $isInCacheDir <  $isInStorageDir ) )
        {
            return $this->metaDataTableCache;
        }

        return $this->metaDataTable;
    }

    /**
     * Connects to the database.
     *
     * @return void
     * @throw eZClusterHandlerDBNoConnectionException
     * @throw eZClusterHandlerDBNoDatabaseException
     **/
    public function _connect()
    {
        $siteINI = eZINI::instance( 'site.ini' );
        // DB Connection setup
        // This part is not actually required since _connect will only be called
        // once, but it is useful to run the unit tests. So be it.
        // @todo refactor this using eZINI::setVariable in unit tests
        if ( self::$dbparams === null )
        {
            $fileINI = eZINI::instance( 'file.ini' );

            self::$dbparams = array();
            self::$dbparams['host']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBHost' );
            self::$dbparams['port']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBPort' );
            self::$dbparams['socket']     = $fileINI->variable( 'eZDFSClusteringSettings', 'DBSocket' );
            self::$dbparams['dbname']     = $fileINI->variable( 'eZDFSClusteringSettings', 'DBName' );
            self::$dbparams['user']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBUser' );
            self::$dbparams['pass']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBPassword' );

            self::$dbparams['max_connect_tries'] = $fileINI->variable( 'eZDFSClusteringSettings', 'DBConnectRetries' );
            self::$dbparams['max_execute_tries'] = $fileINI->variable( 'eZDFSClusteringSettings', 'DBExecuteRetries' );

            self::$dbparams['sql_output'] = $siteINI->variable( 'DatabaseSettings', 'SQLOutput' ) == 'enabled';

            self::$dbparams['cache_generation_timeout'] = $siteINI->variable( 'ContentSettings', 'CacheGenerationTimeout' );
        }


        $connectString = sprintf( 'pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s',
            self::$dbparams['host'],
            self::$dbparams['port'],
            self::$dbparams['dbname'],
            self::$dbparams['user'],
            self::$dbparams['pass']
        );
        $tries = 0;
        while ( $tries < self::$dbparams['max_connect_tries'] )
        {
            try {
                $this->db = new PDO( $connectString, self::$dbparams['user'], self::$dbparams['pass'] );
            } catch ( PDOException $e ) {
                self::writeError( $e->getMessage() );
                ++$tries;
                continue;
            }
            break;
        }
        if ( !( $this->db instanceof PDO ) )
        {
            $this->_die( "Unable to connect to storage server" );
            throw new eZClusterHandlerDBNoConnectionException( $connectString, self::$dbparams['user'], self::$dbparams['pass'] );
        }


        if ( !$this->db )
            throw new eZClusterHandlerDBNoConnectionException( $connectString, self::$dbparams['user'], self::$dbparams['pass'] );

        $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->db->exec( "SET NAMES 'utf8'" );


        // DFS setup
        if ( $this->dfsbackend === null )
            $this->dfsbackend = eZDFSFileHandlerBackendFactory::build();
            //$this->dfsbackend = new eZDFSFileHandlerDFSBackend();

    }

    /**
     * Disconnects the handler from the database
     *
     * @see eZDFSFileHandler::disconnect
     * @return void
     */
    public function _disconnect()
    {
        if ( $this->db !== null )
        {
            $this->db = null;
        }
    }

    /**
     * Creates a copy of a file in DB+DFS
     *
     * @see eZDFSFileHandler::fileCopy
     * @see _copyInner
     *
     * @param string $srcFilePath Source file
     * @param string $dstFilePath Destination file
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     *
     */
    public function _copy( $srcFilePath, $dstFilePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_copy($srcFilePath, $dstFilePath)";
        else
            $fname = "_copy($srcFilePath, $dstFilePath)";

        // fetch source file metadata
        $metaData = $this->_fetchMetadata( $srcFilePath, $fname );
        // if source file does not exist then do nothing.
        // @todo Throw an exception here.
        //       Info: $srcFilePath
        if ( !$metaData )
        {
            return false;
        }
        $result = $this->_protect( array( $this, "_copyInner" ), $fname,
            $srcFilePath, $dstFilePath, $fname, $metaData );

        $this->eventHandler->notify( 'cluster/copyFile', array( $dstFilePath ) );

        return $result;
    }

    /**
     * Inner function used by _copy to perform the operation in a transaction
     *
     * @param string $srcFilePath
     * @param string $dstFilePath
     * @param bool   $fname
     * @param array  $metaData Source file's metadata
     * @return bool
     *
     * @see _copy
     */
    protected function _copyInner( $srcFilePath, $dstFilePath, $fname, $metaData )
    {
        $this->_delete( $dstFilePath, true, $fname );

        $datatype        = $metaData['datatype'];
        $filePathHash    = md5( $dstFilePath );
        $scope           = $metaData['scope'];
        $contentLength   = $metaData['size'];
        $fileMTime       = $metaData['mtime'];
        $nameTrunk       = self::nameTrunk( $dstFilePath, $scope );

        // Copy file metadata.
        if ( $this->_insertUpdate( $this->dbTable( $dstFilePath ),
                array( 'datatype'=> $datatype,
                       'name' => $dstFilePath,
                       'name_trunk' => $nameTrunk,
                       'name_hash' => $filePathHash,
                       'scope' => $scope,
                       'size' => $contentLength,
                       'mtime' => $fileMTime,
                       'expired' => ( $fileMTime < 0 ) ? 1 : 0 ),
                array( 'datatype', 'scope', 'size', 'mtime', 'expired' ),
                $fname ) === false )
        {
            $this->_fail( $srcFilePath, "Failed to insert file metadata on copying." );
        }

        // Copy file data.
        if ( !$this->dfsbackend->copyFromDFSToDFS( $srcFilePath, $dstFilePath ) )
        {
            $this->_fail( $srcFilePath, "Failed to copy DFS://$srcFilePath to DFS://$dstFilePath" );
        }
        return true;
    }

    /**
     * Purges meta-data and file-data for a file entry
     * Will only expire a single file. Use _purgeByLike to purge multiple files
     *
     * @see eZDFSFileHandler::purge
     * @see _purgeByLike
     *
     * @param string $filePath Path of the file to purge
     * @param bool $onlyExpired Only purges expired files
     * @param bool|int $expiry
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    public function _purge( $filePath, $onlyExpired = false, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purge($filePath)";
        else
            $fname = "_purge($filePath)";
        $sql = "DELETE FROM " . $this->dbTable( $filePath ) . " WHERE name_hash=" . $this->_md5( $filePath );
        if ( $expiry !== false )
        {
            $sql .= " AND mtime<" . (int)$expiry;
        }
        elseif ( $onlyExpired )
        {
            $sql .= " AND expired=1";
        }
        if ( !$stmt = $this->_query( $sql, $fname ) )
        {
            $this->_fail( "Purging file metadata for $filePath failed" );
        }
        if ( $stmt->rowCount() == 1 )
        {
            $this->dfsbackend->delete( $filePath );
        }

        $this->eventHandler->notify( 'cluster/deleteFile', array( $filePath ) );

        return true;
    }

    /**
     * Purges meta-data and file-data for files matching a pattern using a SQL
     * LIKE syntax.
     * This method should also remove the files from disk
     *
     * @see eZDFSFileHandler::purge
     * @see _purge
     *
     * @param string $like
     *        SQL LIKE string applied to ezdfsfile.name to look for files to
     *        purge
     * @param bool $onlyExpired
     *        Only purge expired files (ezdfsfile.expired = 1)
     * @param integer $limit Maximum number of items to purge in one call
     * @param integer|bool $expiry
     *        Timestamp used to limit deleted files: only files older than this
     *        date will be deleted
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool|int false if it fails, number of affected rows otherwise
     */
    public function _purgeByLike( $like, $onlyExpired = false, $limit = 50, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purgeByLike($like, $onlyExpired)";
        else
            $fname = "_purgeByLike($like, $onlyExpired)";

        // common query part used for both DELETE and SELECT
        $where = " WHERE name LIKE " . $this->_quote( $like );

        if ( $expiry !== false )
            $where .= " AND mtime < " . (int)$expiry;
        elseif ( $onlyExpired )
            $where .= " AND expired = 1";

        if ( $limit )
            $sqlLimit = " LIMIT $limit";
        else
            $sqlLimit = "";

        $this->_begin( $fname );

        // select query, in FOR UPDATE mode
        $selectSQL = "SELECT name FROM " . $this->dbTable( $like ) .
                     "{$where} {$sqlLimit} FOR UPDATE";
        if ( !$stmt = $this->_query( $selectSQL, $fname ) )
        {
            $this->_rollback( $fname );
            $this->_fail( "Selecting file metadata by like statement $like failed" );
        }

        $files = array();
        // if there are no results, we can just return 0 and stop right here
        if ( $stmt->rowCount() == 0 )
        {
            $this->_rollback( $fname );
            return 0;
        }
        // the candidate for purge are indexed in an array
        else
        {
            while( $row = $stmt->fetch( PDO::FETCH_ASSOC ) )
            {
                $files[] = $row['name'];
            }
        }

        // delete query
        $deleteSQL = "DELETE FROM " . $this->dbTable( $like ) . " WHERE name_hash IN " .
                     "(SELECT name_hash FROM ". $this->dbTable( $like ) . " $where $sqlLimit)";
        if ( !$stmt = $this->_query( $deleteSQL, $fname ) )
        {
            $this->_rollback( $fname );
            $this->_fail( "Purging file metadata by like statement $like failed" );
        }
        $deletedDBFiles = $stmt->rowCount();
        $this->dfsbackend->delete( $files );

        $this->_commit( $fname );

        return $deletedDBFiles;
    }

    /**
     * Deletes a file from DB
     * The file won't be removed from disk, _purge has to be used for this.
     * Only single files will be deleted, to delete multiple files,
     * _deleteByLike has to be used.
     *
     * @see eZDFSFileHandler::fileDelete
     * @see eZDFSFileHandler::delete
     * @see _deleteInner
     * @see _deleteByLike
     *
     * @param string $filePath Path of the file to delete
     * @param bool $insideOfTransaction
     *        Wether or not a transaction is already started
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    public function _delete( $filePath, $insideOfTransaction = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_delete($filePath)";
        else
            $fname = "_delete($filePath)";
        // @todo Check if this is required: _protect will already take care of
        //       checking if a transaction is running. But leave it like this
        //       for now.
        if ( $insideOfTransaction )
        {
            $res = $this->_deleteInner( $filePath, $fname );

        }
        else
        {
            $res = $this->_protect( array( $this, '_deleteInner' ), $fname,
                $filePath, $insideOfTransaction, $fname );
        }

        $this->eventHandler->notify( 'cluster/deleteFile', array( $filePath ) );

        return $res;
    }

    /**
     * Callback method used by by _delete to delete a single file
     *
     * @param string $filePath Path of the file to delete
     * @param string $fname Optional caller name for debugging
     * @return bool
     **/
    protected function _deleteInner( $filePath, $fname )
    {
        if ( !$this->_query( "UPDATE " . $this->dbTable( $filePath ) . " SET mtime=-ABS(mtime), expired=1 WHERE name_hash=" . $this->_md5( $filePath ), $fname ) )
            $this->_fail( "Deleting file $filePath failed" );
        return true;
    }

    /**
     * Deletes multiple files using a SQL LIKE statement
     * Use _delete if you need to delete single files
     *
     * @see eZDFSFileHandler::fileDelete
     * @see _deleteByLikeInner
     * @see _delete
     *
     * @param string $like
     *        SQL LIKE condition applied to ezdfsfile.name to look for files
     *        to delete. Will use name_trunk if the LIKE string matches a
     *        filetype that supports name_trunk.
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    public function _deleteByLike( $like, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByLike($like)";
        else
            $fname = "_deleteByLike($like)";
        $return = $this->_protect( array( $this, '_deleteByLikeInner' ), $fname,
            $like, $fname );

        if ( $return )
            $this->eventHandler->notify( 'cluster/deleteByLike', array( $like ) );

        return $return;
    }

    /**
     * @see _deleteByLike
     *
     * @param string $like
     *        SQL LIKE condition applied to ezdfsfile.name to look for files
     *        to delete. Will use name_trunk if the LIKE string matches a
     *        filetype that supports name_trunk.
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool|void
     * @throws Exception
     */
    protected function _deleteByLikeInner( $like, $fname )
    {
        $sql = "UPDATE " . $this->dbTable( $like ) . " SET mtime=-ABS(mtime), expired=1\nWHERE name like ". $this->_quote( $like );
        if ( !$res = $this->_query( $sql, $fname ) )
        {
            $this->_fail( "Failed to delete files by like: '$like'" );
        }
        return true;
    }

    /**
     * Deletes DB files by using a SQL regular expression applied to file names
     *
     * @param string $regex
     * @param mixed $fname
     * @return bool
     * @deprecated Has severe performance issues
     */
    public function _deleteByRegex( $regex, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByRegex($regex)";
        else
            $fname = "_deleteByRegex($regex)";
        return $this->_protect( array( $this, '_deleteByRegexInner' ), $fname,
            $regex, $fname );
    }

    /**
     * Deletes DB files by using a SQL regular expression applied to file names
     *
     * @param string $regex
     * @param mixed $fname
     * @return bool
     * @deprecated Has severe performance issues
     */
    protected function _deleteByRegexInner( $regex, $fname )
    {
        $sql = "UPDATE " . $this->dbTable( $regex ) . " SET mtime=-ABS(mtime), expired=1\nWHERE name REGEXP " . $this->_quote( $regex );
        if ( !$res = $this->_query( $sql, $fname ) )
        {
            $this->_fail( "Failed to delete files by regex: '$regex'" );
        }
        return true;
    }

    /**
     * Deletes multiple DB files by wildcard
     *
     * @param string $wildcard
     * @param mixed $fname
     * @return bool
     * @deprecated Has severe performance issues
     */
    public function _deleteByWildcard( $wildcard, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByWildcard($wildcard)";
        else
            $fname = "_deleteByWildcard($wildcard)";
        return $this->_protect( array( $this, '_deleteByWildcardInner' ), $fname,
            $wildcard, $fname );
    }

    /**
     * Callback used by _deleteByWildcard to perform the deletion
     *
     * @param mixed $wildcard
     * @param mixed $fname
     * @return bool
     * @deprecated Has severe performance issues
     */
    protected function _deleteByWildcardInner( $wildcard, $fname )
    {
        // Convert wildcard to regexp.
        $regex = '^' . pg_escape_string( $this->db, $wildcard ) . '$';

        $regex = str_replace( array( '.'  ),
            array( '\.' ),
            $regex );

        $regex = str_replace( array( '?', '*',  '{', '}', ',' ),
            array( '.', '.*', '(', ')', '|' ),
            $regex );

        $sql = "UPDATE " . $this->dbTable( $wildcard ) . " SET mtime=-ABS(mtime), expired=1\nWHERE name REGEXP '$regex'";
        if ( !$res = $this->_query( $sql, $fname ) )
        {
            $this->_fail( "Failed to delete files by wildcard: '$wildcard'" );
        }
        return true;
    }

    /**
     * Deletes a list of files based on directory / filename components
     *
     * @see eZDFSFileHandler::fileDeleteByDirList
     *
     * @param array $dirList Array of directory that will be prefixed with
     *                        $commonPath when looking for files
     * @param string $commonPath Starting path common to every delete request
     * @param string $commonSuffix Suffix appended to every delete request
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    public function _deleteByDirList( $dirList, $commonPath, $commonSuffix, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByDirList(" . implode( ' ',$dirList ) . ", $commonPath, $commonSuffix)";
        else
            $fname = "_deleteByDirList(" . implode( ' ',$dirList ) . ", $commonPath, $commonSuffix)";
        return $this->_protect( array( $this, '_deleteByDirListInner' ), $fname,
            $dirList, $commonPath, $commonSuffix, $fname );
    }

    protected function _deleteByDirListInner( $dirList, $commonPath, $commonSuffix, $fname )
    {
        foreach ( $dirList as $dirItem )
        {
            if ( strstr( $commonPath, '/cache/content' ) !== false or strstr( $commonPath, '/cache/template-block' ) !== false )
            {
                $event = 'cluster/deleteByNametrunk';
                $nametrunk = "$commonPath/$dirItem/$commonSuffix";
                $eventParameters = array( $nametrunk );
                $where = "WHERE name_trunk = '$commonPath/$dirItem/$commonSuffix'";
            }
            else
            {
                $event = 'cluster/deleteByDirList';
                $eventParameters = array( $commonPath, $dirItem, $commonSuffix );
                $where = "WHERE name LIKE '$commonPath/$dirItem/$commonSuffix%'";
            }
            $sql = "UPDATE " . $this->dbTable( $commonPath ) . " SET mtime=-ABS(mtime), expired=1\n$where";
            if ( !$stmt = $this->_query( $sql, $fname ) )
            {
                self::writeError( "Failed to delete files in dir: '$commonPath/$dirItem/$commonSuffix%'", __METHOD__ );
            }

            if ( $event )
            {
                $this->eventHandler->notify( $event, $eventParameters );
                unset( $event );
            }
        }
        return true;
    }

    /**
     * Check if given file/dir exists.
     *
     * @see eZDFSFileHandler::fileExists
     * @see eZDFSFileHandler::exists
     *
     * @param $filePath
     * @param bool|string $fname Optional caller name for debugging
     * @param bool $ignoreExpiredFiles ignore ezdfsfile.mtime
     * @param bool $checkOnDFS Checks if a file exists on the DFS
     *
     * @return bool
     */
    public function _exists( $filePath, $fname = false, $ignoreExpiredFiles = true, $checkOnDFS = false )
    {
        if ( $fname )
            $fname .= "::_exists($filePath)";
        else
            $fname = "_exists($filePath)";

        $row = $this->eventHandler->filter( 'cluster/fileExists', $filePath );

        if ( !is_array( $row ) ) {
            $row = $this->_selectOneRow("SELECT name, mtime FROM " . $this->dbTable($filePath) . " WHERE name_hash=" . $this->_md5($filePath),
                $fname, "Failed to check file '$filePath' existence: ", true);
        }

        if ( $row === false )
            return false;

        if ( $ignoreExpiredFiles )
            $rc = $row[1] >= 0;
        else
            $rc = true;

        if ( $checkOnDFS && $rc )
        {
            $rc = $this->dfsbackend->existsOnDFS( $filePath );
        }

        return $rc;
    }

    protected function __mkdir_p( $dir )
    {
        // create parent directories
        $dirElements = explode( '/', $dir );
        if ( count( $dirElements ) == 0 )
            return true;

        $result = true;
        $currentDir = $dirElements[0];

        if ( $currentDir != '' && !file_exists( $currentDir ) && !eZDir::mkdir( $currentDir, false ) )
            return false;

        for ( $i = 1; $i < count( $dirElements ); ++$i )
        {
            $dirElement = $dirElements[$i];
            if ( strlen( $dirElement ) == 0 )
                continue;

            $currentDir .= '/' . $dirElement;

            if ( !file_exists( $currentDir ) && !eZDir::mkdir( $currentDir, false ) )
                return false;

            $result = true;
        }

        return $result;
    }

    /**
     * Fetches the file $filePath from the database to its own name
     * Saving $filePath locally with its original name, or $uniqueName if given
     *
     * @see eZDFSFileHandler::fileFetch
     * @see eZDFSFileHandler::fetchUnique
     *
     * @param string $filePath
     * @param bool|string $uniqueName Alternative name to save the file to
     *
     * @return string|bool the file physical path, or false if fetch failed
     */
    public function _fetch( $filePath, $uniqueName = false )
    {
        $metaData = $this->_fetchMetadata( $filePath );
        if ( !$metaData )
        {
            // @todo Throw an exception
            self::writeError( "File '$filePath' does not exist while trying to fetch.", __METHOD__ );
            return false;
        }

        $dfsFileSize = $this->dfsbackend->getDfsFileSize( $filePath );
        if ( !$dfsFileSize )
        {
            // @todo Throw an exception
            self::writeError( "Error getting filesize of file '$filePath'.", __METHOD__ );
            return false;
        }
        $loopCount = 0;
        $localFileSize = 0;

        do
        {
            // create temporary file
            $tmpid = getmypid() . '-' . mt_rand() .'tmp';
            if ( strrpos( $filePath, '.' ) > 0 )
                $tmpFilePath = substr_replace( $filePath, $tmpid, strrpos( $filePath, '.' ), 0  );
            else
                $tmpFilePath = $filePath . '.' . $tmpid;
            $this->__mkdir_p( dirname( $tmpFilePath ) );
            eZDebugSetting::writeDebug( 'kernel-clustering', "copying DFS://$filePath to FS://$tmpFilePath on try: $loopCount " );

            // copy DFS file to temporary FS path
            // @todo Throw an exception
            if ( !$this->dfsbackend->copyFromDFS( $filePath, $tmpFilePath ) )
            {
                self::writeError("Failed copying DFS://$filePath to FS://$tmpFilePath ");
                usleep( self::TIME_UNTIL_RETRY );
                ++$loopCount;
                continue;
            }

            if ( $uniqueName !== true )
            {
                if( !eZFile::rename( $tmpFilePath, $filePath, false, eZFile::CLEAN_ON_FAILURE | eZFile::APPEND_DEBUG_ON_FAILURE ) )
                {
                    usleep( self::TIME_UNTIL_RETRY );
                    ++$loopCount;
                    continue;
                }
            }
            $filePath = ($uniqueName) ? $tmpFilePath : $filePath ;

            // If all data has been written correctly, return the filepath.
            // Otherwise let the loop continue
            clearstatcache( true, $filePath );
            $localFileSize = filesize( $filePath );
            if ( $dfsFileSize == $localFileSize )
            {
                return $filePath;
            }
            // Sizes might have been corrupted by FS problems. Enforcing temp file removal.
            else if ( file_exists( $tmpFilePath ) )
            {
                unlink( $tmpFilePath );
            }

            usleep( self::TIME_UNTIL_RETRY );
            ++$loopCount;
        }
        while ( $dfsFileSize > $localFileSize && $loopCount < $this->maxCopyTries );

        // Copy from DFS has failed :-(
        self::writeError( "Size ({$localFileSize}) of written data for file '{$filePath}' does not match expected size {$metaData['size']}", __METHOD__ );
        return false;
    }

    /**
     * Returns file contents.
     *
     * @see eZDFSFileHandler::fileFetchContents
     * @see eZDFSFileHandler::fetchContents
     *
     * @param string $filePath
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return string|bool contents string, or false in case of an error.
     */
    public function _fetchContents( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchContents($filePath)";
        else
            $fname = "_fetchContents($filePath)";
        $metaData = $this->_fetchMetadata( $filePath, $fname );
        // @todo Throw an exception
        if ( !$metaData )
        {
            self::writeError( "File '$filePath' does not exist while trying to fetch its contents.", __METHOD__ );
            return false;
        }

        // @todo Catch an exception
        if ( !$contents = $this->dfsbackend->getContents( $filePath ) )
        {
            self::writeError("An error occurred while reading contents of DFS://$filePath", __METHOD__ );
            return false;
        }
        return $contents;
    }

    /**
     * Fetches and returns metadata for $filePath
     *
     * @see eZDFSFileHandler::loadMetaData
     * @param string $filePath
     * @param bool|string $fname Optional caller name for debugging
     * @return array|false file metadata, or false if the file does not exist in database.
     */
    function _fetchMetadata( $filePath, $fname = false )
    {
        $metadata = $this->eventHandler->filter( 'cluster/loadMetadata', $filePath );
        if ( is_array( $metadata ) )
            return $metadata;

        if ( $fname )
            $fname .= "::_fetchMetadata($filePath)";
        else
            $fname = "_fetchMetadata($filePath)";
        $sql = "SELECT * FROM " . $this->dbTable( $filePath ) . " WHERE name_hash=" . $this->_md5( $filePath );
        $metadata = $this->_selectOneAssoc( $sql, $fname,
            "Failed to retrieve file metadata: $filePath",
            true );

        if ( is_array( $metadata ) )
            $this->eventHandler->notify( 'cluster/storeMetadata', array( $metadata ) );

        return $metadata;
    }

    /**
     * Create symbolic or hard link to file. Alias of copy
     *
     * @see eZDFSFileHandler::fileLinkCopy
     *
     * @param string $srcPath Source file
     * @param string $dstPath Destination file
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return mixed
     */
    public function _linkCopy( $srcPath, $dstPath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_linkCopy($srcPath,$dstPath)";
        else
            $fname = "_linkCopy($srcPath,$dstPath)";
        return $this->_copy( $srcPath, $dstPath, $fname );
    }

    /**
     * Passes $filePath content through
     *
     * @param string $filePath
     * @param int $startOffset Byte offset to start download from
     * @param int|bool $length Byte length to be sent
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    public function _passThrough( $filePath, $startOffset = 0, $length = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_passThrough($filePath)";
        else
            $fname = "_passThrough($filePath)";

        $metaData = $this->_fetchMetadata( $filePath, $fname );
        // @todo Throw an exception
        if ( !$metaData )
            return false;

        // @todo Catch an exception
        $this->dfsbackend->passthrough( $filePath, $startOffset, $length );

        return true;
    }

    /**
     * Renames $srcFilePath to $dstFilePath
     *
     * @see eZDFSFileHandler::fileMove
     * @see eZDFSFileHandler::move
     *
     * @param string $srcFilePath
     * @param string $dstFilePath
     *
     * @return bool
     */
    public function _rename( $srcFilePath, $dstFilePath )
    {
        if ( strcmp( $srcFilePath, $dstFilePath ) == 0 )
            return false;

        // fetch source file metadata
        $metaData = $this->_fetchMetadata( $srcFilePath );
        // if source file does not exist then do nothing.
        // @todo Throw an exception
        if ( !$metaData )
            return false;

        $this->_begin( __METHOD__ );

        $dstFilePathStr  = $this->_quote( $dstFilePath );
        $dstNameTrunkStr = $this->_quote( self::nameTrunk( $dstFilePath, $metaData['scope'] ) );

        // Mark entry for update to lock it
        $sql = "SELECT * FROM " . $this->dbTable( $srcFilePath ) . " WHERE name_hash=" . $this->_md5( $srcFilePath ) . " FOR UPDATE";
        if ( !$this->_query( $sql, "_rename($srcFilePath, $dstFilePath)" ) )
        {
            // @todo Throw an exception
            self::writeError( "Failed locking file '$srcFilePath'", __METHOD__ );
            $this->_rollback( __METHOD__ );
            return false;
        }

        if ( $this->_exists( $dstFilePath, false, false ) )
            $this->_purge( $dstFilePath, false );

        // Create a new meta-data entry for the new file to make foreign keys happy.
        $sql = "INSERT INTO " . $this->dbTable( $srcFilePath ) . " ".
               "(name, name_trunk, name_hash, datatype, scope, size, mtime, expired) " .
               "SELECT $dstFilePathStr AS name, $dstNameTrunkStr as name_trunk, " . $this->_md5( $dstFilePath ) . " AS name_hash, " .
               "datatype, scope, size, mtime, expired FROM " . $this->dbTable( $srcFilePath ) . " " .
               "WHERE name_hash=" . $this->_md5( $srcFilePath );
        if ( !$this->_query( $sql, "_rename($srcFilePath, $dstFilePath)" ) )
        {
            self::writeError( "Failed making new file entry '$dstFilePath'", __METHOD__ );
            $this->_rollback( __METHOD__ );
            // @todo Throw an exception
            return false;
        }

        if ( !$this->dfsbackend->copyFromDFSToDFS( $srcFilePath, $dstFilePath ) )
        {
            $this->_fail( "Failed to copy DFS://$srcFilePath to DFS://$dstFilePath" );
        }

        // Remove old entry
        $sql = "DELETE FROM " . $this->dbTable( $srcFilePath ) . " WHERE name_hash=" . $this->_md5( $srcFilePath );
        if ( !$this->_query( $sql, "_rename($srcFilePath, $dstFilePath)" ) )
        {
            self::writeError( "Failed removing old file '$srcFilePath'", __METHOD__ );
            $this->_rollback( __METHOD__ );
            // @todo Throw an exception
            return false;
        }

        // delete original DFS file
        // @todo Catch an exception
        $this->dfsbackend->delete( $srcFilePath );

        $this->_commit( __METHOD__ );

        $this->eventHandler->notify( 'cluster/deleteFile', array( $srcFilePath ) );

        return true;
    }

    /**
     * Stores $filePath to cluster
     *
     * @see eZDFSFileHandler::fileStore
     *
     * @param string $filePath
     * @param string $datatype
     * @param string $scope
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    function _store( $filePath, $datatype, $scope, $fname = false )
    {
        if ( !is_readable( $filePath ) )
        {
            self::writeError( "Unable to store file '$filePath' since it is not readable.", __METHOD__ );
            return false;
        }
        if ( $fname )
            $fname .= "::_store($filePath, $datatype, $scope)";
        else
            $fname = "_store($filePath, $datatype, $scope)";

        $return = $this->_protect( array( $this, '_storeInner' ), $fname,
            $filePath, $datatype, $scope, $fname );

        $this->eventHandler->notify( 'cluster/storeFile', array( $filePath ) );

        return $return;
    }

    /**
     * Callback function used to perform the actual file store operation
     * @param string $filePath
     * @param string $datatype
     * @param string $scope
     * @param string $fname
     * @see eZDFSFileHandlerMySQLBackend::_store()
     * @return bool
     **/
    function _storeInner( $filePath, $datatype, $scope, $fname )
    {
        // Insert file metadata.
        clearstatcache();
        $fileMTime = filemtime( $filePath );
        $contentLength = filesize( $filePath );
        $filePathHash = md5( $filePath );
        $nameTrunk = self::nameTrunk( $filePath, $scope );

        if ( $this->_insertUpdate( $this->dbTable( $filePath ),
                array( 'datatype' => $datatype,
                       'name' => $filePath,
                       'name_trunk' => $nameTrunk,
                       'name_hash' => $filePathHash,
                       'scope' => $scope,
                       'size' => $contentLength,
                       'mtime' => $fileMTime,
                       'expired' => ( $fileMTime < 0 ) ? 1 : 0 ),
                array( 'datatype', 'scope', 'size', 'mtime', 'expired' ),
                $fname ) === false )
        {
            $this->_fail( "Failed to insert file metadata while storing. Possible race condition" );
        }

        // copy given $filePath to DFS
        if ( !$this->dfsbackend->copyToDFS( $filePath ) )
        {
            $this->_fail( "Failed to copy FS://$filePath to DFS://$filePath" );
        }

        return true;
    }

    /**
     * Stores $contents as the contents of $filePath to the cluster
     *
     * @see eZDFSFileHandler::fileStore
     * @see eZDFSFileHandler::storeContents
     *
     * @param string $filePath
     * @param string $contents
     * @param string $scope
     * @param string $datatype
     * @param bool|int $mtime
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    function _storeContents( $filePath, $contents, $scope, $datatype, $mtime = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_storeContents($filePath, ..., $scope, $datatype)";
        else
            $fname = "_storeContents($filePath, ..., $scope, $datatype)";

        return $this->_protect( array( $this, '_storeContentsInner' ), $fname,
            $filePath, $contents, $scope, $datatype, $mtime, $fname );
    }

    function _storeContentsInner( $filePath, $contents, $scope, $datatype, $mtime, $fname )
    {
        // File metadata.
        $contentLength = strlen( $contents );
        $filePathHash = md5( $filePath );
        $nameTrunk = self::nameTrunk( $filePath, $scope );
        if ( $mtime === false )
            $mtime = time();

        // Copy file metadata.
        $result = $this->_insertUpdate(
            $this->dbTable( $filePath ),
            array( 'datatype'   => $datatype,
                   'name'       => $filePath,
                   'name_trunk' => $nameTrunk,
                   'name_hash'  => $filePathHash,
                   'scope'      => $scope,
                   'size'       => $contentLength,
                   'mtime'      => $mtime,
                   'expired'    => ( $mtime < 0 ) ? 1 : 0 ),
            array( 'datatype', 'scope', 'size', 'mtime', 'expired' ),
            $fname
        );
        if ( $result === false )
        {
            $this->_fail( "Failed to insert file metadata while storing contents. Possible race condition", $result );
        }

        if ( !$this->dfsbackend->createFileOnDFS( $filePath, $contents ) )
        {
            $this->_fail( "Failed to open DFS://$filePath for writing" );
        }

        $this->eventHandler->notify( 'cluster/storeFile', array( $filePath ) );

        return true;
    }

    /**
     * Gets the list of cluster files, filtered by the optional params
     *
     * @see eZDFSFileHandler::getFileList
     *
     * @param array|bool $scopes filter by array of scopes to include in the list
     * @param bool $excludeScopes if true, $scopes param acts as an exclude filter
     * @param array|bool $limit limits the search to offset limit[0], limit limit[1]
     * @param string|bool $path filter to include entries only including $path
     *
     * @return array|false the db list of entries of false if none found
     */
    public function _getFileList(
        $scopes = false,
        $excludeScopes = false,
        $limit = false,
        $path = false
    )
    {
        $filePathList = array();
        $tables = array_unique( array( $this->metaDataTable, $this->metaDataTableCache ) );

        foreach ( $tables as $table )
        {
            $query = 'SELECT name FROM ' . $table;

            if ( is_array( $scopes ) && count( $scopes ) > 0 )
            {
                $query .= ' WHERE scope ';
                if ( $excludeScopes )
                    $query .= 'NOT ';
                $query .= "IN ('" . implode( "', '", $scopes ) . "')";
            }
            if ( $path != false && $scopes == false)
            {
                $query .= " WHERE name LIKE '" . $path . "%'";
            }
            else if ( $path != false)
            {
                $query .= " AND name LIKE '" . $path . "%'";
            }
            if ( $limit && array_sum($limit) )
            {
                $query .= " LIMIT {$limit[0]}, {$limit[1]}";
            }

            $stmt = $this->_query( $query, "_getFileList( array( " . implode( ', ', $scopes ) . " ), $excludeScopes )" );
            if ( !$stmt )
            {
                eZDebug::writeDebug( 'Unable to get file list', __METHOD__ );
                // @todo Throw an exception
                return false;
            }

            $filePathList = array();
            foreach ($stmt->fetch( PDO::FETCH_NUM ) as $row)
                $filePathList[] = $row;

            unset( $stmt );
        }
        return $filePathList;
    }

    /**
     * Handles a DB error, displaying it as an eZDebug error
     * @see self::writeError
     * @param string $msg Message to display
     * @param string $sql SQL query to display error for
     * @return void
     **/
    protected function _die( $msg, $sql = null )
    {
        if ( $this->db )
        {
            $error = $this->db->errorInfo();
            self::writeError( $sql, "$msg: {$error[2]}" );
        }
        else
        {
            self::writeError( $sql, $msg );
        }
    }

    /**
     * Performs an insert of the given items in $array.
     *
     * @param string $table Name of table to execute insert on.
     * @param array $array Associative array with data to insert, the keys are
     *                     the field names and the values will be quoted
     *                     according to type.
     * @param string $fname Name of caller function
     *
     * @return bool
     */
    function _insert( $table, $array, $fname )
    {
        $keys = array_keys( $array );
        $query = "INSERT INTO $table (" . join( ", ", $keys ) . ") VALUES (" . $this->_sqlList( $array ) . ")";
        $res = $this->_query( $query, $fname );
        if ( !$res )
        {
            return false;
        }
        return true;
    }

    /**
     * Performs an insert of the given items in $insert.
     *
     * If entry specified already exists, fields in $update are updated with the values from $insert
     *
     * @param string $table Name of table to execute insert on.
     * @param array $insert Associative array with data to insert, the keys
     *                       are the field names and the values are the quoted field values
     * @param array $update Array of fields that must be updated if an entry exists
     * @param string $fname Name of caller function
     * @param bool $reportError
     *
     * @throws InvalidArgumentException when either name or name_hash aren't provided in $insert
     */
    protected function _insertUpdate( $table, $insert, $update, $fname, $reportError = true )
    {
        if ( !isset( $insert['name'] ) || !isset( $insert['name_hash'] ) )
        {
            $this->_fail( "Insert array must contain both name and name_hash" );
        }

        if ( $row = $this->_fetchMetadata( $insert['name'] ) )
        {
            $sql  = "UPDATE $table SET ";
            $setEntries = array();
            foreach( $update as $field )
            {
                $setEntries[] = "$field=" . $this->_quote( $insert[$field] );
            }
            $sql .= implode( ', ', $setEntries ) .
                    " WHERE name_hash=" . $this->_quote( $insert['name_hash'] );
        }
        else
        {
            // create file in db
            $quotedValues = array();
            foreach( $insert as $value )
            {
                $quotedValues[] = $this->_quote( $value );
            }
            $sql  = "INSERT INTO $table " .
                    "(" . implode( ', ', array_keys( $insert ) ) . ") " .
                    "VALUES( " . implode( ', ', $quotedValues ) . ")";
        }

        try
        {
            $this->_query( $sql, $fname, $reportError );
        }
        catch ( PDOException $e )
        {
            $this->_fail( "Failed insert/updating: " . $e->getMessage() );
        }
        return true;
    }

    /**
     * Formats a list of entries as an SQL list which is separated by commas.
     * Each entry in the list is quoted using _quote().
     *
     * @param array $array
     * @return array
     **/
    protected function _sqlList( $array )
    {
        $text = "";
        $sep = "";
        foreach ( $array as $e )
        {
            $text .= $sep;
            $text .= $this->_quote( $e );
            $sep = ", ";
        }
        return $text;
    }

    /**
     * Runs a select query and returns one numeric indexed row from the result
     * If there are more than one row it will fail and exit, if 0 it returns
     * false.
     *
     * @param string $query
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param bool|string $error Sent to _error() in case of errors
     * @param bool   $debug If true it will display the fetched row in addition
     *                      to the SQL.
     * @return array|false
     **/
    protected function _selectOneRow( $query, $fname, $error = false, $debug = false )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, PDO::FETCH_NUM );
    }

    /**
     * Runs a select query and returns one associative row from the result.
     *
     * If there are more than one row it will fail and exit, if 0 it returns
     * false.
     *
     * @param string $query
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param bool|string $error Sent to _error() in case of errors
     * @param bool   $debug If true it will display the fetched row in addition
     *                      to the SQL.
     * @return array|false
     **/
    protected function _selectOneAssoc( $query, $fname, $error = false, $debug = false )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, PDO::FETCH_ASSOC );
    }

    /**
     * Runs a select query, applying the $fetchCall callback to one result
     * If there are more than one row it will fail and exit, if 0 it returns false.
     *
     * @param $query
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param bool|string $error Sent to _error() in case of errors
     * @param bool $debug If true it will display the fetched row in addition to the SQL.
     * @param int $fetchCall The callback to fetch the row.
     *
     * @return mixed
     **/
    protected function _selectOne( $query, $fname, $error = false, $debug = false, $fetchCall )
    {
        eZDebug::accumulatorStart( 'postgresql_cluster_query', 'PostgreSQL Cluster', 'DB queries' );
        $time = microtime( true );

        $stmt = $this->db->query( $query );
        if ( !$stmt )
        {
            $this->_error( $query, $stmt, $fname, $error );
            eZDebug::accumulatorStop( 'postgresql_cluster_query' );
            // @todo Throw an exception
            return false;
        }

        $numRows = $stmt->rowCount();
        if ( $numRows > 1 )
        {
            self::writeError( 'Duplicate entries found', $fname );
            eZDebug::accumulatorStop( 'postgresql_cluster_query' );
            // @todo throw an exception instead. Should NOT happen.
        }
        elseif ( $numRows === 0 )
        {
            eZDebug::accumulatorStop( 'postgresql_cluster_query' );
            return false;
        }

        $row = $stmt->fetch( $fetchCall );
        unset( $stmt );
        if ( $debug )
            $query = "SQL for _selectOneAssoc:\n" . $query . "\n\nRESULT:\n" . var_export( $row, true );

        $time = microtime( true ) - $time;
        eZDebug::accumulatorStop( 'postgresql_cluster_query' );

        $this->_report( $query, $fname, $time );
        return $row;
    }

    /**
     * Starts a new transaction by executing a BEGIN call.
     * If a transaction is already started nothing is executed.
     **/
    protected function _begin()
    {
        $this->transactionCount++;
        if ( $this->transactionCount == 1 )
            $this->db->beginTransaction();
    }

    /**
     * Stops a current transaction and commits the changes by executing a COMMIT call.
     * If the current transaction is a sub-transaction nothing is executed.
     **/
    protected function _commit()
    {
        $this->transactionCount--;
        if ( $this->transactionCount == 0 )
            $this->db->commit();
    }

    /**
     * Stops a current transaction and discards all changes by executing a
     * ROLLBACK call.
     * If the current transaction is a sub-transaction nothing is executed.
     **/
    protected function _rollback()
    {
        $this->transactionCount--;
        if ( $this->transactionCount == 0 )
            $this->db->rollBack();
    }

    /**
     * Protects a custom function with SQL queries in a database transaction.
     * If the function reports an error the transaction is ROLLBACKed.
     *
     * The first argument to the _protect() is the callback and the second is the
     * name of the function (for query reporting). The remainder of arguments are
     * sent to the callback.
     *
     * A return value of false from the callback is considered a failure, any
     * other value is returned from _protect(). For extended error handling call
     * _fail() and return the value.
     **/
    protected function _protect()
    {
        $result = false;
        $args = func_get_args();
        $callback = array_shift( $args );
        $fname    = array_shift( $args );

        $maxTries = self::$dbparams['max_execute_tries'];
        $tries = 0;
        while ( $tries < $maxTries )
        {
            $this->_begin( $fname );

            try {
                $result = call_user_func_array( $callback, $args );
            }
            catch( PDOException $e )
            {
                self::writeError( $e->getMessage(), __METHOD__ );
                return false;
            }
            catch( Exception $e )
            {
                self::writeError( $e->getMessage(), __METHOD__ );
                return false;
            }
            break; // All is good, so break out of loop
        }

        $this->_commit( $fname );
        return $result;
    }

    /**
     * Creates an error object which can be read by some backend functions.
     *
     * @param mixed $message The value which is sent to the debug system.
     * @param PDOStatement|bool $result The failed SQL result
     * @throws Exception
     **/
    protected function _fail( $message, $result = false)
    {
        // @todo Investigate the right function
        if ( $result !== false )
        {
            $message .= "\n" . pg_result_error( $result, PGSQL_DIAG_SQLSTATE ) . ": " . pg_result_error( $result, PGSQL_DIAG_MESSAGE_PRIMARY );
        }
        else
        {
            $errorInfo = $this->db->errorInfo();
            $message .= "\n$errorInfo[2]";
        }
        throw new Exception( $message );
    }

    /**
     * Performs mysql query and returns mysql result.
     * Times the sql execution, adds accumulator timings and reports SQL to
     * debug.
     *
     * @param string $query
     * @param bool|string $fname Optional caller name for debugging
     * @param bool $reportError
     *
     * @return PDOStatement The resulting PDOStatement object, or false if an error occurred
     **/
    protected function _query( $query, $fname = false, $reportError = true )
    {
        eZDebug::accumulatorStart( 'postgresql_cluster_query', 'PostgreSQL Cluster', 'DB queries' );
        $time = microtime( true );

        $stmt = $this->db->query( $query );
        if ( $stmt == false )
        {
            if ( $reportError )
                $this->_error( $query, $stmt, $fname );
            return $stmt;
        }

        $numRows = $stmt->rowCount();

        $time = microtime( true ) - $time;
        eZDebug::accumulatorStop( 'postgresql_cluster_query' );

        $this->_report( $query, $fname, $time, $numRows );

        return $stmt;
    }

    /**
     * Make sure that $value is escaped and quoted according to type and returned
     * as a string.
     *
     * @param string $value a SQL parameter to escape
     * @return string a string that can safely be used in SQL queries
     **/
    protected function _quote( $value )
    {
        if ( $value === null )
            return 'NULL';
        elseif ( is_integer( $value ) )
        {
            return $this->db->quote( $value, PDO::PARAM_INT );
        }
        else
        {
            return $this->db->quote( $value, PDO::PARAM_STR );
        }
    }

    /**
     * Provides the SQL calls to convert $value to MD5
     * The returned value can directly be put into SQLs.
     * @param $value
     *
     * @return string
     */
    protected function _md5( $value )
    {
        return  $this->_quote( md5( $value ) );
    }

    /**
     * Prints error message $error to debug system.
     * @param string $query The query that was attempted, will be printed if
     *                      $error is \c false
     * @param PDOStatement|resource $res The result resource the error occurred on
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param string $error The error message, if this is an array the first
     *                      element is the value to dump and the second the error
     *                      header (for eZDebug::writeNotice). If this is \c
     *                      false a generic message is shown.
     */
    protected function _error( $query, $res, $fname, $error = "Failed to execute SQL for function:" )
    {
        if ( $error === false )
        {
            $error = "Failed to execute SQL for function:";
        }
        else if ( is_array( $error ) )
        {
            $fname = $error[1];
            $error = $error[0];
        }

        // @todo Investigate error methods
        self::writeError( "$error\n" . pg_result_error_field( $res, PGSQL_DIAG_SQLSTATE ) . ': ' . pg_result_error_field( $res, PGSQL_DIAG_MESSAGE_PRIMARY ) . ' ' .$query, $fname );
    }

    /**
     * Report SQL $query to debug system.
     *
     * @param string $query The query that was attempted, will be printed if
     *                      $error is \c false
     * @param string $fname The function name that started the query, should contain relevant arguments in the text.
     * @param int $timeTaken Number of seconds the query + related operations took (as float).
     * @param int|bool $numRows Number of affected rows.
     **/
    function _report( $query, $fname, $timeTaken, $numRows = false )
    {
        if ( !self::$dbparams['sql_output'] )
            return;

        $rowText = '';
        if ( $numRows !== false )
            $rowText = "$numRows rows, ";
        static $numQueries = 0;
        if ( strlen( $fname ) == 0 )
            $fname = "_query";
        $backgroundClass = ($this->transactionCount > 0  ? "debugtransaction transactionlevel-$this->transactionCount" : "");
        eZDebug::writeNotice( "$query", "cluster::posgresql::{$fname}[{$rowText}" . number_format( $timeTaken, 3 ) . " ms] query number per page:" . $numQueries++, $backgroundClass );
    }

    /**
     * Attempts to begin cache generation by creating a new file named as the
     * given filepath, suffixed with .generating. If the file already exists,
     * insertion is not performed and false is returned (means that the file
     * is already being generated)
     *
     * @see eZDFSFileHandler::startCacheGeneration
     *
     * @param string $filePath
     * @param string $generatingFilePath
     *
     * @return array array with 2 indexes: 'result', containing either ok or ko,
     *         and another index that depends on the result:
     *         - if result == 'ok', the 'mtime' index contains the generating
     *           file's mtime
     *         - if result == 'ko', the 'remaining' index contains the remaining
     *           generation time (time until timeout) in seconds
     */
    public function _startCacheGeneration( $filePath, $generatingFilePath )
    {
        $fname = "_startCacheGeneration( {$filePath} )";

        $nameHash = $this->_md5( $generatingFilePath );
        $mtime = time();

        $insertData = array( 'name' => $this->_quote( $generatingFilePath ),
                             'name_trunk' => $this->_quote( $generatingFilePath ),
                             'name_hash' => $nameHash,
                             'scope' => "''",
                             'datatype' => "''",
                             'mtime' => $this->_quote( $mtime ),
                             'expired' => 0 );
        $query = 'INSERT INTO ' . $this->dbTable( $filePath ) . ' ( '. implode(', ', array_keys( $insertData ) ) . ' ) ' .
                 "VALUES(" . implode( ', ', $insertData ) . ")";

        //per testare scommenta la riga 1503 e sposta righe 1516-1548 fuori dal catch @todo
        //$query .= "  WHERE NOT EXISTS ( SELECT name_hash FROM ' . $this->dbTable( $filePath ) . ' WHERE name_hash = {$nameHash} );";

        try
        {
            $stmt = $this->_query( $query, "_startCacheGeneration( $filePath )", false );
        }
        catch( PDOException $e )
        {
            $errno = $e->getCode();
            if ( $errno != self::ERROR_UNIQUE_VIOLATION )
            {
                throw new RuntimeException( "Unexpected error #$errno when trying to start cache generation on $filePath (" . $e->getMessage() . ')' );
            }
            // error self::ERROR_UNIQUE_VIOLATION is expected, since it means duplicate key (file is being generated)
            else
            {
                // generation timeout check
                $query = "SELECT mtime FROM " . $this->dbTable( $filePath ) . " WHERE name_hash = {$nameHash}";
                $row = $this->_selectOneRow( $query, $fname, false, false );

                // file has been renamed, i.e it is no longer a .generating file
                if( $row and !isset( $row[0] ) )
                    return array( 'result' => 'ok', 'mtime' => $mtime );

                $remainingGenerationTime = $this->remainingCacheGenerationTime( $row );
                if ( $remainingGenerationTime < 0 )
                {
                    $previousMTime = $row[0];

                    eZDebugSetting::writeDebug( 'kernel-clustering', "$filePath generation has timed out, taking over", __METHOD__ );
                    $updateQuery = "UPDATE " . $this->dbTable( $filePath ) . " SET mtime = {$mtime} WHERE name_hash = {$nameHash} AND mtime = {$previousMTime}";

                    // we run the query manually since the default _query won't
                    // report affected rows
                    $stmt = $this->db->query( $updateQuery );
                    if ( ( $stmt !== false ) && $stmt->rowCount() == 1 )
                    {
                        return array( 'result' => 'ok', 'mtime' => $mtime );
                    }
                    else
                    {
                        throw new RuntimeException( "An error occurred taking over timed out generating cache file $generatingFilePath" );
                        //return array( 'result' => 'error' );
                    }
                }
                else
                {
                    return array( 'result' => 'ko', 'remaining' => $remainingGenerationTime );
                }
            }
        }

        return array( 'result' => 'ok', 'mtime' => $mtime );
    }

    /**
     * Ends the cache generation for the current file: moves the (meta)data for
     * the .generating file to the actual file, and removed the .generating
     *
     * @see eZDFSFileHandler::endCacheGeneration
     *
     * @param string $filePath
     * @param string $generatingFilePath
     * @param bool $rename if false the .generating entry is just deleted
     *
     * @return bool true
     *
     * @throw RuntimeException
     */
    public function _endCacheGeneration( $filePath, $generatingFilePath, $rename )
    {
        $fname = "_endCacheGeneration( $filePath )";

        // no rename: the .generating entry is just deleted
        if ( $rename === false )
        {
            $this->_query( "DELETE FROM " . $this->dbTable( $filePath ) . " WHERE name_hash=" . $this->_md5( $generatingFilePath ), $fname, true );
            $this->dfsbackend->delete( $generatingFilePath );
            return true;
        }
        // rename mode: the generating file and its contents are renamed to the
        // final name
        else
        {
            $this->_begin( $fname );

            // both files are locked for update
            if ( !$stmt = $this->_query( "SELECT * FROM " . $this->dbTable( $filePath ) . " WHERE name_hash=" . $this->_md5( $generatingFilePath ) . " FOR UPDATE", $fname, true ) )
            {
                $this->_rollback( $fname );
                throw new RuntimeException( "An error occurred getting a lock on $generatingFilePath" );
            }
            $generatingMetaData = $stmt->fetch( PDO::FETCH_ASSOC );

            // the original file does not exist: we move the generating file
            $stmt = $this->_query( "SELECT * FROM " . $this->dbTable( $filePath ) . " WHERE name_hash=" . $this->_md5( $filePath ) . "  FOR UPDATE", $fname, false );
            if ( $stmt->rowCount() == 0 )
            {
                $metaData = $generatingMetaData;
                $metaData['name'] = $filePath;
                $metaData['name_hash'] = md5( $filePath );
                $metaData['name_trunk'] = $this->nameTrunk( $filePath, $metaData['scope'] );
                $insertSQL = "INSERT INTO " . $this->dbTable( $filePath ) . " ( " . implode( ', ', array_keys( $metaData ) ) . " ) " .
                             "VALUES( " . $this->_sqlList( $metaData ) . ")";
                if ( !$this->_query( $insertSQL, $fname, true ) )
                {
                    $this->_rollback( $fname );
                    throw new RuntimeException( "An error occurred creating the metadata entry for $filePath" );
                }
                // here we rename the actual FILE. The .generating file has been
                // created on DFS, and should be renamed
                if ( !$this->dfsbackend->renameOnDFS( $generatingFilePath, $filePath ) )
                {
                    $this->_rollback( $fname );
                    throw new RuntimeException("An error occurred renaming DFS://$generatingFilePath to DFS://$filePath" );
                }
                $this->_query( "DELETE FROM " . $this->dbTable( $filePath ) . " WHERE name_hash=" . $this->_md5( $generatingFilePath ), $fname, true );
            }
            // the original file exists: we move the generating data to this file
            // and update it
            else
            {
                if ( !$this->dfsbackend->renameOnDFS( $generatingFilePath, $filePath ) )
                {
                    $this->_rollback( $fname );
                    throw new RuntimeException( "An error occurred renaming DFS://$generatingFilePath to DFS://$filePath" );
                }

                $mtime = $generatingMetaData['mtime'];
                $filesize = $generatingMetaData['size'];
                if ( !$this->_query( "UPDATE " . $this->dbTable( $filePath ) . " SET mtime = '{$mtime}', expired = 0, size = '{$filesize}' WHERE name_hash=" . $this->_md5( $filePath ), $fname, true ) )
                {
                    $this->_rollback( $fname );
                    throw new RuntimeException( "An error marking '$filePath' as not expired in the database" );
                }
                $this->_query( "DELETE FROM " . $this->dbTable( $filePath ) . " WHERE name_hash=" . $this->_md5( $generatingFilePath ), $fname, true );
            }

            $this->_commit( $fname );
        }

        return true;
    }

    /**
     * Checks if generation has timed out by looking for the .generating file
     * and comparing its timestamp to the one assigned when the file was created
     *
     * @param string $generatingFilePath
     * @param int    $generatingFileMtime
     *
     * @return bool true if the file didn't timeout, false otherwise
     **/
    public function _checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )
    {
        $generatingFileMtime = intval($generatingFileMtime);
        $fname = "_checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )";

        // reporting
        eZDebug::accumulatorStart( 'postgresql_cluster_query', 'PostgreSQL Cluster', 'DB queries' );
        $time = microtime( true );

        $nameHash = $this->_md5( $generatingFilePath );
        $newMtime = time();

        // The update query will only succeed if the mtime wasn't changed in between
        $query = "UPDATE " . $this->dbTable( $generatingFilePath ) . " SET mtime = $newMtime WHERE name_hash = {$nameHash} AND mtime = $generatingFileMtime";
        $stmt = $this->db->query( $query );
        if ( !$stmt )
        {
            // @todo Throw an exception
            $this->_error( $query, $stmt, $fname );
            return false;
        }
        $numRows = $stmt->rowCount();

        // reporting. Manual here since we don't use _query
        $time = microtime( true ) - $time;
        $this->_report( $query, $fname, $time, $numRows );

        // no rows affected or row updated with the same value
        // f.e a cache-block which takes less than 1 sec to get generated
        // if a line has been updated by the same  values, mysqli_affected_rows
        // returns 0, and updates nothing, we need to extra check this,
        if( $numRows == 0 )
        {
            $query = "SELECT mtime FROM " . $this->dbTable( $generatingFilePath ) . " WHERE name_hash = {$nameHash}";
            $stmt = $this->db->query( $query );
            $row = $stmt->fetch( PDO::FETCH_NUM );
            if ( isset( $row[0] ) && $row[0] == $generatingFileMtime )
            {
                return true;
            }
            // @todo Check if an exception makes sense here
            return false;
        }
        // rows affected: mtime has changed, or row has been removed
        if ( $numRows == 1 )
        {
            return true;
        }
        else
        {
            eZDebugSetting::writeDebug( 'kernel-clustering', "No rows affected by query '$query', record has been modified", __METHOD__ );
            return false;
        }
    }

    /**
     * Aborts the cache generation process by removing the .generating file
     *
     * @see eZDFSFileHandler::abortCacheGeneration
     *
     * @param string $generatingFilePath .generating cache file path
     *
     * @return void
     */
    public function _abortCacheGeneration( $generatingFilePath )
    {
        $fname = "_abortCacheGeneration( $generatingFilePath )";

        $this->_begin( $fname );

        $sql = "DELETE FROM " . $this->dbTable( $generatingFilePath ) . " WHERE name_hash = " . $this->_md5( $generatingFilePath );
        $this->_query( $sql, "_abortCacheGeneration( '$generatingFilePath' )" );
        $this->dfsbackend->delete( $generatingFilePath );

        $this->_commit( $fname );
    }

    /**
     * Returns the name_trunk for a file path
     * @param string $filePath
     * @param string $scope
     * @return string
     **/
    static protected function nameTrunk( $filePath, $scope )
    {
        switch ( $scope )
        {
            case 'viewcache':
            {
                $nameTrunk = substr( $filePath, 0, strrpos( $filePath, '-' ) + 1 );
            } break;

            case 'template-block':
            {
                $templateBlockCacheDir = eZTemplateCacheBlock::templateBlockCacheDir();
                $templateBlockPath = str_replace( $templateBlockCacheDir, '', $filePath );
                if ( strstr( $templateBlockPath, 'subtree/' ) !== false )
                {
                    // 6 = strlen( 'cache/' );
                    $len = strlen( $templateBlockCacheDir ) + strpos( $templateBlockPath, 'cache/' ) + 6;
                    $nameTrunk = substr( $filePath, 0, $len  );
                }
                else
                {
                    $nameTrunk = $filePath;
                }
            } break;

            default:
            {
                $nameTrunk = $filePath;
            }
        }
        return $nameTrunk;
    }

    /**
     * Returns the remaining time, in seconds, before the generating file times
     * out
     *
     * @param array $row
     *
     * @return int Remaining generation seconds. A negative value indicates a timeout.
     **/
    protected function remainingCacheGenerationTime( $row )
    {
        if( !isset( $row[0] ) )
            return -1;

        return ( $row[0] + self::$dbparams['cache_generation_timeout'] ) - time();
    }

    /**
     * Returns the list of expired files
     *
     * @see eZDFSFileHandler::fetchExpiredItems
     *
     * @param array $scopes Array of scopes to consider. At least one.
     * @param array|bool $limit Max number of items. Set to false for unlimited.
     * @param int|bool $expiry Number of seconds, only items older than this will be returned.
     *
     * @return array(filepath)
     *
     * @since 4.3
     */
    public function expiredFilesList( $scopes, $limit = array( 0, 100 ), $expiry = false )
    {
        $tables = array( $this->metaDataTable, $this->metaDataTableCache );

        if ( count( $scopes ) == 0 || $scopes == false )
            throw new ezcBaseValueException( 'scopes', $scopes, "array of scopes", "parameter" );

        $scopeString = $this->_sqlList( $scopes );

        $filePathList = array();

        foreach ( $tables as $table)
        {
            $query = "SELECT name FROM " . $table . " WHERE expired = 1 AND scope IN( $scopeString )";
            if ( $limit !== false )
            {
                $query .= " LIMIT {$limit[1]} OFFSET {$limit[0]}";
            }
            $stmt = $this->_query( $query, __METHOD__ );
            $filePathList = array();
            while ( $row = $stmt->fetch( PDO::FETCH_NUM ) )
                $filePathList[] = $row[0];
            unset( $stmt );
        }

        return $filePathList;
    }

    /**
     * Transforms $filePath so that it contains a valid href to the file, wherever it is stored.
     *
     * @see eZDFSFileHandler::applyServerUri
     *
     * @param string $filePath
     *
     * @return string
     */
    public function applyServerUri( $filePath )
    {
        return $this->dfsbackend->applyServerUri( $filePath );
    }

    /**
     * Deletes a batch of cache files from the storage table.
     *
     * @param int $limit
     *
     * @return int The number of moved rows
     *
     * @throws RuntimeException if a MySQL query occurs
     * @throws InvalidArgumentException if the split table feature is disabled
     */
    public function deleteCacheFiles( $limit )
    {
        if ( $this->metaDataTable === $this->metaDataTableCache )
        {
            throw new InvalidArgumentException( "The split table features is disabled: cache and storage table are identical" );
        }

        $like = addcslashes( eZSys::cacheDirectory(), '_' ) . DIRECTORY_SEPARATOR . '%';

        $query = "DELETE FROM {$this->metaDataTable} WHERE name LIKE '$like' LIMIT $limit";
        if ( !$stmt = $this->_query( $query ) )
        {
            throw new RuntimeException( "Error in $query" );
        }

        return $stmt->rowCount();
    }

    /**
     * Registers $listener as the cluster event listener.
     *
     * @param eZClusterEventListener $listener
     * @return void
     */
    public function registerListener( eZClusterEventListener $listener )
    {
        $suppliedEvents = array(
            'cluster/storeMetadata',
            'cluster/loadMetadata',
            'cluster/fileExists',
            'cluster/deleteFile',
            'cluster/deleteByLike',
            'cluster/deleteByDirList',
            'cluster/deleteByNametrunk',

            'cluster/copyFile',
            'cluster/storeFile',
        );

        foreach ( $suppliedEvents as $eventName )
        {
            list( $domain, $method ) = explode( '/', $eventName );
            $this->eventHandler->attach( $eventName, array( $listener, $method ) );
        }
    }


    /**
     * DB connexion handle
     * @var PDO|resource
     */
    public $db = null;

    /**
     * DB connexion parameters
     * @var array
     */
    protected static $dbparams = null;

    /**
     * Amount of executed queries, for debugging purpose
     * @var int
     */
    protected $numQueries = 0;

    /**
     * Current transaction level.
     * Will be used to decide wether we can BEGIN (if it's the first BEGIN call)
     * or COMMIT (if we're committing the last running transaction
     * @var int
     */
    protected $transactionCount = 0;

    /**
     * Distributed filesystem backend
     * @var eZDFSFileHandlerDFSBackendInterface
     */
    protected $dfsbackend = null;

    /**
     * Event handler
     * @var ezpEvent
     */
    protected $eventHandler;

    /**
     * custom dfs table name support
     * @var string
     */
    protected $metaDataTable = 'ezdfsfile';

    /**
     * Custom DFS table for cache storage.
     * Defaults to the "normal" storage table, meaning that only one table is used.
     * @var string
     */
    protected $metaDataTableCache = 'ezdfsfile_cache';

    /**
     * Cache files directory, including leading & trailing slashes.
     * Will be filled in using FileSettings.CacheDir from site.ini
     * @var string
     */
    protected $cacheDir;

    /**
     * Storage directory, including leading & trailing slashes.
     * Will be filled in using FileSettings.StorageDir from site.ini
     * @var string
     */
    protected $storageDir;

    /**
     * Unique constraint violation error, used for stale cache management
     * @var int
     */
    const ERROR_UNIQUE_VIOLATION = 23505;
}
