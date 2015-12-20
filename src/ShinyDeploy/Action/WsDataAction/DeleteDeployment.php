<?php
namespace ShinyDeploy\Action\WsDataAction;

use ShinyDeploy\Domain\Database\Deployments;
use ShinyDeploy\Exceptions\InvalidPayloadException;

class DeleteDeployment extends WsDataAction
{
    /**
     * Removes a deployment from database.
     *
     * @param array $actionPayload
     * @return boolean
     * @throws InvalidPayloadException
     */
    public function __invoke(array $actionPayload)
    {
        $this->authorize($this->clientId);

        if (!isset($actionPayload['deploymentId'])) {
            throw new InvalidPayloadException('Invalid deleteDeployment request received.');
        }
        $deploymentId = (int)$actionPayload['deploymentId'];
        $deployments = new Deployments($this->config, $this->logger);

        // remove server:
        $addResult = $deployments->deleteDeployment($deploymentId);
        if ($addResult === false) {
            $this->responder->setError('Could not remove deployment from database.');
            return false;
        }
        return true;
    }
}
