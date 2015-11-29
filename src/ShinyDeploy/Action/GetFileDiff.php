<?php
namespace ShinyDeploy\Action;

use RuntimeException;
use ShinyDeploy\Core\Action;
use ShinyDeploy\Domain\Database\Auth;
use ShinyDeploy\Domain\Database\Repositories;
use ShinyDeploy\Responder\WsFileDiffResponder;

class GetFileDiff extends Action
{
    public function __invoke($params)
    {
        try {
            if (empty($params['file']) || empty($params['remoteRevision']) || empty($params['repositoryId'])) {
                throw new RuntimeException('Required parameter missing.');
            }
            if (preg_match('#[0-9a-f]{40}#', $params['remoteRevision']) !== 1) {
                throw new RuntimeException('Invalid remote revision');
            }
            if (preg_match('#[0-9a-f]{40}#', $params['localRevision']) !== 1) {
                throw new RuntimeException('Invalid local revision');
            }

            // get users encryption key:
            $auth = new Auth($this->config, $this->logger);
            $encryptionKey = $auth->getEncryptionKeyFromToken($this->token);
            if (empty($encryptionKey)) {
                $this->responder->setError('Could not get encryption key.');
                return false;
            }

            $repositories = new Repositories($this->config, $this->logger);
            $repositories->setEnryptionKey($encryptionKey);
            $repository = $repositories->getRepository($params['repositoryId']);
            $diff = $repository->getFileDiff($params['file'], $params['localRevision'], $params['remoteRevision']);
            $responder = new WsFileDiffResponder($this->config, $this->logger);
            $responder->setClientId($params['clientId']);
            $responder->respond($diff);
            return true;

        } catch (RuntimeException $e) {
            $this->logger->alert(
                'Runtime Exception: ' . $e->getMessage() . ' (' . $e->getFile() . ': ' . $e->getLine() . ')'
            );
            return false;
        }
    }
}
