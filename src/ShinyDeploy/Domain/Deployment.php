<?php
namespace ShinyDeploy\Domain;

use Apix\Log\Logger;
use Noodlehaus\Config;
use RuntimeException;
use ShinyDeploy\Core\Domain;
use ShinyDeploy\Core\EventManager;
use ShinyDeploy\Core\Responder;
use ShinyDeploy\Domain\Database\Repositories;
use ShinyDeploy\Domain\Database\Servers;
use ShinyDeploy\Domain\Server\Server;
use ShinyDeploy\Exceptions\ConnectionException;
use ShinyDeploy\Exceptions\GitException;

class Deployment extends Domain
{
    /**
     * @var EventManager $eventManager
     */
    protected $eventManager;

    /**
     * @var \ShinyDeploy\Domain\Server\SftpServer|\ShinyDeploy\Domain\Server\SshServer $server
     */
    protected $server;

    /**
     * @var Repository $repository
     */
    protected $repository;

    /**
     * @var \ShinyDeploy\Responder\WsLogResponder $logResponder
     */
    protected $logResponder;

    /**
     * @var bool $listMode Defines if deployment only lists changed files (and skips actual deployment)
     */
    protected $listMode = false;

    /**
     * @var string $initiator Can be "api" or "gui".
     */
    protected $initiator = '';

    /**
     * @var array $changedFiles
     */
    protected $changedFiles = [];

    /**
     * @var string $encryptionKey
     */
    protected $encryptionKey;

    /**
     * @var array $selectedTasks
     */
    protected $selectedTasks = [];

    public function __construct(Config $config, Logger $logger, EventManager $eventManager)
    {
        parent::__construct($config, $logger);
        $this->eventManager = $eventManager;
    }

    /**
     * Sets the encryption key.
     *
     * @param string $encryptionKey
     */
    public function setEncryptionKey(string $encryptionKey) : void
    {
        if (empty($encryptionKey)) {
            throw new \InvalidArgumentException('Encryption key can not be empty.');
        }
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * Setter for the websocket log responder.
     *
     * @param Responder $logResponder
     * @return void
     */
    public function setLogResponder(Responder $logResponder) : void
    {
        $this->logResponder = $logResponder;
    }

    /**
     * Returns log responder.
     *
     * @return Responder
     */
    public function getLogResponder(): Responder
    {
        return $this->logResponder;
    }

    /**
     * Sets tasks selected by user via GUI.
     *
     * @param array $selectedTasks
     * @return void
     */
    public function setSelectedTasks(array $selectedTasks) : void
    {
        $this->selectedTasks = $selectedTasks;
    }

    /**
     * Returns tasks selected by user via GUI.
     *
     * @return array
     */
    public function getSelectedTasks(): array
    {
        return $this->selectedTasks;
    }

    /**
     * Fetches deployments task configuration.
     *
     * @return array
     */
    public function getTaskData(): array
    {
        return empty($this->data['tasks']) ? [] : $this->data['tasks'];
    }

    /**
     * Returns list of changed files.
     *
     * @return array
     */
    public function getChangedFiles() : array
    {
        return $this->changedFiles;
    }

    /**
     * Sets list of changed files.
     *
     * @param array $changedFiles
     * @return void
     */
    public function setChangedFiles(array $changedFiles): void
    {
        $this->changedFiles = $changedFiles;
    }

    /**
     * Checks if deployment is in list mode.
     *
     * @return bool
     */
    public function inListMode(): bool
    {
        return $this->listMode;
    }

    /**
     * Returns initiator of deployment (api or gui)
     *
     * @return string
     */
    public function getInitiator(): string
    {
        return $this->initiator;
    }

    /**
     * Returns server object of deployment.
     *
     * @return Server
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    /**
     * Returns repository object of deployment.
     *
     * @return Repository
     */
    public function getRepository(): Repository
    {
        return $this->repository;
    }

    /**
     * @param array $data
     * @throws \ShinyDeploy\Exceptions\CryptographyException
     * @throws \ShinyDeploy\Exceptions\DatabaseException
     * @throws \ShinyDeploy\Exceptions\ShinyDeployException
     */
    public function init(array $data) : void
    {
        $this->data = $data;

        // load server:
        $servers = new Servers($this->config, $this->logger);
        $servers->setEnryptionKey($this->encryptionKey);
        $this->server = $servers->getServer($data['server_id']);

        // load repository:
        $repositories = new Repositories($this->config, $this->logger);
        $repositories->setEnryptionKey($this->encryptionKey);
        $this->repository = $repositories->getRepository($data['repository_id']);
    }

    /**
     * Executes an actual deployment.
     *
     * @param bool $listMode
     * @param string $initiator
     * @return bool
     * @throws \ZMQException
     * @throws \ShinyDeploy\Exceptions\MissingDataException
     * @throws \ShinyDeploy\Exceptions\ShinyDeployException
     */
    public function deploy(bool $listMode, string $initiator) : bool
    {
        if (empty($this->data)) {
            throw new RuntimeException('Deployment data not found. Initialization missing?');
        }
        if (empty($this->server)) {
            throw new RuntimeException('Server object not found.');
        }
        if (empty($this->repository)) {
            throw new RuntimeException('Repository object not found');
        }
        if ($initiator !== 'api' && $initiator !== 'gui') {
            throw new \InvalidArgumentException('Invalid initiator');
        }

        $this->listMode = $listMode;
        $this->initiator = $initiator;

        $this->eventManager->emit('deploymentStarted', ['deployment' => $this]);

        $this->logResponder->log('Checking prerequisites...');
        if ($this->checkPrerequisites() === false) {
            $this->logResponder->error('Prerequisites check failed. Aborting job.');
            return false;
        }

        $this->logResponder->log('Switching branch...');
        if ($this->switchBranch() === false) {
            $this->logResponder->error('Could not switch to selected branch. Aborting job.');
            return false;
        }

        $this->logResponder->log('Preparing local repository...');
        if ($this->prepareRepository() === false) {
            $this->logResponder->error('Preparation of local repository failed. Aborting job.');
            return false;
        }

        $this->logResponder->log('Estimating remote revision...');
        $remoteRevision = $this->getRemoteRevision();
        if ($remoteRevision === '') {
            $this->logResponder->error('Could not estimate remote revision. Aborting job.');
            return false;
        }

        $this->logResponder->log('Estimating local revision...');
        $localRevision = $this->getLocalRevision();
        if ($localRevision === '') {
            $this->logResponder->error('Could not estimate local revision. Aborting job.');
            return false;
        }

        $this->eventManager->emit('deploymentPreparationCompleted', ['deployment' => $this]);

        // If remote server is up to date we can stop right here:
        if ($localRevision === $remoteRevision) {
            if ($listMode === false) {
                $this->logResponder->info('Remote server is aleady up to date.');
            }
            return true;
        }

        $this->logResponder->log('Collecting changed files...');
        $this->changedFiles = $this->getChangedFilesList($localRevision, $remoteRevision);

        $this->eventManager->emit('deploymentChangedFilesCollected', ['deployment' => $this]);

        // If we are in list mode we can now respond with the list of changed files:
        if ($listMode === true) {
            return true;
        }

        $this->logResponder->log('Sorting changed files...');
        $this->changedFiles = $this->sortFilesByOperation($this->changedFiles);

        $this->eventManager->emit('deploymentChangedFilesSorted', ['deployment' => $this]);

        $this->logResponder->log('Processing changed files...');
        if ($this->processChangedFiles($this->changedFiles) === false) {
            $this->logResponder->error('Could not process files. Aborting job.');
            return false;
        }

        $this->eventManager->emit('deploymentChangedFilesProcessed', ['deployment' => $this]);

        $this->logResponder->log('Updating revision file...');
        if ($this->updateRemoteRevisionFile($localRevision) === false) {
            $this->logResponder->error('Could not update remove revision file. Aborting job.');
            return false;
        }

        $this->eventManager->emit('deploymentCompleted', ['deployment' => $this]);

        return true;
    }


    /**
     * Checks various requirements to be fulfilled before stating a deployment.
     *
     * @return bool
     * @throws \ZMQException
     */
    protected function checkPrerequisites() : bool
    {
        $this->logResponder->log('Checking git binary...');
        if ($this->repository->checkGit() === false) {
            $this->logResponder->danger('Git executable not found.');
            return false;
        }
        $this->logResponder->log('Checking connection to repository...');
        if ($this->repository->checkConnectivity() === false) {
            $this->logResponder->danger('Connection to repository failed.');
            return false;
        }
        $this->logResponder->log('Checking connection target server...');
        if ($this->server->checkConnectivity() === false) {
            $this->logResponder->danger('Connection to remote server failed.');
            return false;
        }
        return true;
    }


    /**
     * If local repository does not exist it will be pulled from git. It it exists it will be updated.
     *
     * @return bool
     * @throws \ZMQException
     * @throws \ShinyDeploy\Exceptions\MissingDataException
     */
    protected function prepareRepository() : bool
    {
        if ($this->repository->exists() === true) {
            $result = $this->repository->doPull();
            if ($result === false) {
                $this->logResponder->danger('Error while updating repository.');
            }
            $pruneResult = $this->repository->doPrune();
            if ($pruneResult === false) {
                $this->logResponder->info('Possible error during git remote prune.');
            }
        } else {
            $result = $this->repository->doClone();
            if ($result === false) {
                $this->logResponder->danger('Error while cloning repository.');
            }
        }
        return $result;
    }

    /**
     * Get the deployment path on target server.
     *
     * @return string
     */
    protected function getRemotePath() : string
    {
        $serverRoot = $this->server->getRootPath();
        $serverRoot = rtrim($serverRoot, '/');
        $targetPath = trim($this->data['target_path']);
        $targetPath = trim($targetPath, '/');
        $remotePath = $serverRoot . '/' . $targetPath . '/';
        $remotePath = str_replace('//', '/', $remotePath);

        return $remotePath;
    }

    /**
     * Fetches remote revision from REVISION file in project root.
     *
     * @return string
     * @throws \ZMQException
     */
    public function getRemoteRevision() : string
    {
        if ($this->server->checkConnectivity() === false) {
            return '';
        }

        $targetPath = $this->getRemotePath();
        $targetPath .= 'REVISION';
        try {
            $revision = $this->server->getFileContent($targetPath);
        } catch (ConnectionException $e) {
            $revision = '';
        }
        $revision = trim($revision);
        if (!empty($revision) && preg_match('#[0-9a-f]{40}#', $revision) === 1) {
            $this->logResponder->info('Remote server is at revision: ' . $revision);
            return $revision;
        }
        $targetDir = dirname($targetPath);
        try {
            $targetDirContent = $this->server->listDir($targetDir);
        } catch (ConnectionException $e) {
            $this->logResponder->danger('Target path on remote server not found or not accessible.');
            return '';
        }
        if (is_array($targetDirContent) && empty($targetDirContent)) {
             $this->logResponder->info('Target path is empty. No revision yet.');
            return '-1';
        }
        return '';
    }

    /**
     * Fetches revision of local repository.
     *
     * @return string
     * @throws \ZMQException
     */
    public function getLocalRevision() : string
    {
        if ($this->repository->checkConnectivity() === false) {
            $this->logResponder->danger('Could not connect to remote repository.');
            return '';
        }
        $revision = $this->repository->getRemoteRevision($this->data['branch']);
        if ($revision !== false) {
            $this->logResponder->info('Local repository is at revision: ' . $revision);
        } else {
            $this->logResponder->danger('Local revision not found.');
        }
        return $revision;
    }

    /**
     * Switch repository to deployment branch.
     *
     * @return bool
     */
    protected function switchBranch() : bool
    {
        return $this->repository->switchBranch($this->data['branch']);
    }

    /**
     * Generates list with changed,added,deleted files.
     *
     * @param string $localRevision
     * @param string $remoteRevision
     * @return array
     */
    protected function getChangedFilesList(string $localRevision, string $remoteRevision) : array
    {
        try {
            if ($remoteRevision === '-1') {
                $changedFiles = $this->repository->listFiles();
            } else {
                $changedFiles = $this->repository->getDiff($localRevision, $remoteRevision);
            }
        } catch (GitException $e) {
            $this->logger->error('Git diff/list-files failed: ' .$e->getMessage());
        }

        if (empty($changedFiles)) {
            return [];
        }

        $files = [];
        if ($remoteRevision === '-1') {
            foreach ($changedFiles as $file) {
                $item = [
                    'type' => 'A',
                    'file' => $file,
                    'diff' => '',
                ];
                array_push($files, $item);
            }
        } else {
            $files = $changedFiles;
        }

        return $files;
    }

    /**
     * Sort files by operation to do (e.g. upload, delete, ...)
     * @param array $files
     * @return array
     */
    protected function sortFilesByOperation(array $files) : array
    {
        $sortedFiles = [
            'upload' => [],
            'delete' => [],
        ];
        foreach ($files as $item) {
            if (in_array($item['type'], ['A', 'C', 'M', 'R'])) {
                $sortedFiles['upload'][] = $item['file'];
            } elseif ($item['type'] === 'D') {
                $sortedFiles['delete'][] = $item['file'];
            }
        }
        return $sortedFiles;
    }

    /**
     * Deploys changes to target server by uploading/deleting files.
     *
     * @param array $changedFiles
     * @return bool
     * @throws \ZMQException
     */
    protected function processChangedFiles(array $changedFiles) : bool
    {
        $repoPath = $this->repository->getLocalPath();
        $repoPath = rtrim($repoPath, '/') . '/';
        $remotePath = $this->getRemotePath();
        $uploadCount = count($changedFiles['upload']);
        $deleteCount = count($changedFiles['delete']);
        if ($uploadCount === 0 && $deleteCount === 0) {
            $this->logResponder->info('Noting to upload or delete.');
            return true;
        }
        $this->logResponder->info(
            'Files to upload: '.$uploadCount.' - Files to delete: ' . $deleteCount . ' - processing...'
        );

        if ($uploadCount > 0) {
            foreach ($changedFiles['upload'] as $file) {
                $uploadStart = microtime(true);
                $result = $this->server->upload($repoPath.$file, $remotePath.$file);
                $uploadEnd = microtime(true);
                $uploadDuration = round($uploadEnd - $uploadStart, 2);
                if ($result === true) {
                    $this->logResponder->info('Uploading ' . $file . ': success ('.$uploadDuration.'s)');
                } else {
                    $this->logResponder->danger('Uploading ' . $file . ': failed');
                }
            }
        }
        if ($deleteCount > 0) {
            $this->logResponder->log('Removing files...');
            foreach ($changedFiles['delete'] as $file) {
                $result = $this->server->delete($remotePath.$file);
                if ($result === true) {
                    $this->logResponder->info('Deleting ' . $file . ': success');
                } else {
                    $this->logResponder->danger('Deleting ' . $file . ': failed');
                }
            }
        }

        $this->logResponder->info('Processing files completed.');

        return true;
    }

    /**
     * Updates revision file on remote server.
     *
     * @param string $revision Revision hash
     * @return boolean
     * @throws \ZMQException
     */
    protected function updateRemoteRevisionFile(string $revision) : bool
    {
        $remotePath = $this->getRemotePath();
        if ($this->server->putContent($revision, $remotePath.'REVISION') === false) {
            $this->logResponder->error('Could not update remote revision file.');
            return false;
        }
        return true;
    }

    /**
     * Checks if deployments branch matches the passed one.
     *
     * @param string $checkBranch
     * @return bool
     */
    public function isBranch(string $checkBranch) : bool
    {
        if (empty($this->data)) {
            throw new RuntimeException('Deployment data not found. Initialization missing?');
        }
        $brachParts = explode('/', $this->data['branch']);
        $branch = array_pop($brachParts);
        return ($branch === $checkBranch);
    }
}
