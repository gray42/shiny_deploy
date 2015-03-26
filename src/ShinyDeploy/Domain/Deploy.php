<?php
namespace ShinyDeploy\Domain;

use ShinyDeploy\Core\Domain;
use ShinyDeploy\Core\Server\Server;
use ShinyDeploy\Core\Server\SftpServer;

class Deploy extends Domain
{
    /** @var array $supportedServerTypes */
    protected $supportedServerTypes = ['sftp'];

    /**
     * Creates server object.
     *
     * @param string $type
     * @return mixed
     */
    public function getServer($type)
    {
        if (!in_array($type, $this->supportedServerTypes)) {
            throw new \RuntimeException('Unknown server-type.');
        }
        switch ($type) {
            case 'sftp':
                return new SftpServer;
                break;
        }
        return false;
    }

    /**
     * Checks if connection to server is possible.
     *
     * @param Server $server
     * @param array $credentials
     * @return bool
     */
    public function checkConnectivity(Server $server, array $credentials)
    {
        $connectionResult = $server->connect(
            $credentials['host'],
            $credentials['user'],
            $credentials['pass'],
            $credentials['port']
        );
        return $connectionResult;
    }

    /**
     * Fetches remote revision from REVISION file in project root.
     *
     * @param Server $server
     * @param string $targetPath
     * @return string|bool
     */
    public function getRemoteRevision(Server $server, $targetPath)
    {
        if (empty($targetPath)) {
            throw new \RuntimeException('No target path for remote server provided');
        }
        $revision = $server->getFileContent($targetPath);
        if (preg_match('#[0-9a-f]{40}#', $revision) !== 1) {
            return false;
        }
        return $revision;
    }

    /**
     * Fetches revision of local repository.
     *
     * @param string $repoPath
     * @param Git $gitDomain
     * @return bool|string
     */
    public function getLocalRevision($repoPath, Git $gitDomain)
    {
        $revision = $gitDomain->getLocalRepositoryRevision($repoPath);
        return $revision;
    }

    /**
     * Generates list with changed,added,deleted files.
     *
     * @param string $repoPath
     * @param string $localRevision
     * @param string $remoteRevision
     * @param Git $gitDomain
     * @return bool|array
     */
    public function getChangedFiles($repoPath, $localRevision, $remoteRevision, Git $gitDomain)
    {
        $changedFiles = $gitDomain->diff($repoPath, $localRevision, $remoteRevision);
        if (empty($changedFiles)) {
            return false;
        }

        // parse diff response:
        $fileList = [
            'upload' => [],
            'delete' => [],
        ];
        $diffLines = explode("\n", $changedFiles);
        if (empty($diffLines)) {
            return false;
        }
        foreach ($diffLines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            $status = $line[0];
            $file = trim(substr($line, 1));
            if (in_array($status, ['A', 'C', 'M', 'R'])) {
                $fileList['upload'][] = $file;
            } elseif ($status === 'D') {
                $fileList['delete'][] = $file;
            }
        }
        return $fileList;
    }
}