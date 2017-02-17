<?php


interface eZDFSFileHandlerDBBackendInterface
{
    /**
     * Connects to the database.
     *
     * @see eZDFSFileHandler::__construct
     * @return void
     * @throw eZClusterHandlerDBNoConnectionException
     * @throws eZClusterHandlerDBNoConnectionException
     */
    public function _connect();

    /**
     * Disconnects the handler from the database
     *
     * @see eZDFSFileHandler::disconnect
     * @return void
     */
    public function _disconnect();

    /**
     * Fetches and returns metadata for $filePath
     *
     * @see eZDFSFileHandler::loadMetaData
     * @param string $filePath
     * @param bool|string $fname Optional caller name for debugging
     * @return array|false file metadata, or false if the file does not exist in database.
     */
    function _fetchMetadata( $filePath, $fname = false );

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
     * @return void
     */
    function _store( $filePath, $datatype, $scope, $fname = false );

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
     * @return bool|eZMySQLBackendError
     */
    function _storeContents(
        $filePath,
        $contents,
        $scope,
        $datatype,
        $mtime = false,
        $fname = false
    );

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
    public function _fetch( $filePath, $uniqueName = false );

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
    public function _fetchContents( $filePath, $fname = false );

    /**
     * @deprecated Has severe performance issues
     * Deletes multiple DB files by wildcard
     * @see @deprecated eZDFSFileHandler::fileDeleteByWildcard
     *
     * @param string $wildcard
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    public function _deleteByWildcard( $wildcard, $fname = false );


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
     * @return void
     */
    public function _deleteByDirList( $dirList, $commonPath, $commonSuffix, $fname = false );

    /**
     * Deletes a file from DB
     * The file won't be removed from disk, _purge has to be used for this.
     * Only single files will be deleted, to delete multiple files,
     * _deleteByLike has to be used.
     *
     * @see eZDFSFileHandler::fileDelete
     * @see eZDFSFileHandler::delete
     * @see _deleteByLike
     *
     * @param string $filePath Path of the file to delete
     * @param bool $insideOfTransaction
     *        Wether or not a transaction is already started
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     */
    public function _delete( $filePath, $insideOfTransaction = false, $fname = false );

    /**
     * Deletes multiple files using a SQL LIKE statement
     * Use _delete if you need to delete single files
     *
     * @see eZDFSFileHandler::fileDelete
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
    public function _deleteByLike( $like, $fname = false );

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
    public function _purge( $filePath, $onlyExpired = false, $expiry = false, $fname = false );

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
    public function _purgeByLike(
        $like,
        $onlyExpired = false,
        $limit = 50,
        $expiry = false,
        $fname = false
    );

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
    public function _exists(
        $filePath,
        $fname = false,
        $ignoreExpiredFiles = true,
        $checkOnDFS = false
    );

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
    public function _passThrough( $filePath, $startOffset = 0, $length = false, $fname = false );

    /**
     * Creates a copy of a file in DB+DFS
     *
     * @see eZDFSFileHandler::fileCopy
     *
     * @param string $srcFilePath Source file
     * @param string $dstFilePath Destination file
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return bool
     *
     */
    public function _copy( $srcFilePath, $dstFilePath, $fname = false );

    /**
     * Create symbolic or hard link to file. Alias of copy
     *
     * @see eZDFSFileHandler::fileLinkCopy
     *
     * @param string $srcFilePath Source file
     * @param string $dstFilePath Destination file
     * @param bool|string $fname Optional caller name for debugging
     *
     * @return mixed
     */
    public function _linkCopy( $srcFilePath, $dstFilePath, $fname = false );

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
    public function _rename( $srcFilePath, $dstFilePath );


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
    );

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
    public function _startCacheGeneration( $filePath, $generatingFilePath );

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
    public function _endCacheGeneration( $filePath, $generatingFilePath, $rename );

    /**
     * Aborts the cache generation process by removing the .generating file
     *
     * @see eZDFSFileHandler::abortCacheGeneration
     *
     * @param string $generatingFilePath .generating cache file path
     *
     * @return void
     */
    public function _abortCacheGeneration( $generatingFilePath );

    /**
     * Checks if generation has timed out by looking for the .generating file
     * and comparing its timestamp to the one assigned when the file was created
     *
     * @see eZDFSFileHandler::checkCacheGenerationTimeout
     *
     * @param string $generatingFilePath
     * @param int $generatingFileMtime
     *
     * @return bool true if the file didn't timeout, false otherwise
     */
    public function _checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime );

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
    public function expiredFilesList( $scopes, $limit = array( 0, 100 ), $expiry = false );

    /**
     * Transforms $filePath so that it contains a valid href to the file, wherever it is stored.
     *
     * @see eZDFSFileHandler::applyServerUri
     *
     * @param string $filePath
     *
     * @return string
     */
    public function applyServerUri( $filePath );

}
