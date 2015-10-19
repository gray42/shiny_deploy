<?php
namespace ShinyDeploy\Domain\Database;

use RuntimeException;
use ShinyDeploy\Domain\Repository;

class Repositories extends DatabaseDomain
{
    /** @var array $rules Validation rules */
    protected $rules = [
        'required' => [
            ['name'],
            ['type'],
            ['url'],
        ],
        'in' => [
            ['type', ['git']]
        ],
        'url' => [
            ['url']
        ],
    ];

    /**
     * Get validation rules for insert queries.
     *
     * @return array
     */
    public function getCreateRules()
    {
        return $this->rules;
    }

    /**
     * Get validation rules for update queries.
     *
     * @return array
     */
    public function getUpdateRules()
    {
        $rules = $this->rules;
        $rules['required'][] = ['id'];
        return $this->rules;
    }

    /**
     * Creates and returns a repository object.
     *
     * @param int $repositoryId
     * @return Repository
     * @throws RuntimeException
     */
    public function getRepository($repositoryId)
    {
        $data = $this->getRepositoryData($repositoryId);
        if (empty($data)) {
            throw new RuntimeException('Repository not found in database.');
        }
        $repository = new Repository($this->config, $this->logger);
        $repository->init($data);
        return $repository;
    }


    /**
     * Fetches list of repositories from database.
     *
     * @return array|bool
     */
    public function getRepositories()
    {
        $rows = $this->db->prepare("SELECT * FROM repositories ORDER BY `name`")->getResult(false);
        return $rows;
    }

    /**
     * Stores new repository in database.
     *
     * @param array $repositoryData
     * @return bool|int
     */
    public function addRepository(array $repositoryData)
    {
        if (!isset($repositoryData['username'])) {
            $repositoryData['username'] = '';
        }
        if (!isset($repositoryData['password'])) {
            $repositoryData['password'] = '';
        }
        $result = $this->db->prepare(
            "INSERT INTO repositories
              (`name`, `type`, `url`, `username`, `password`)
              VALUES
                (%s, %s, %s, %s, %s)",
            $repositoryData['name'],
            $repositoryData['type'],
            $repositoryData['url'],
            $repositoryData['username'],
            $repositoryData['password']
        )->execute();
        if ($result === false) {
            return false;
        }
        return $this->db->getInsertId();
    }

    /**
     * Updates repository.
     *
     * @param array $repositoryData
     * @return bool
     */
    public function updateRepository(array $repositoryData)
    {
        if (!isset($repositoryData['id'])) {
            return false;
        }
        if (!isset($repositoryData['username'])) {
            $repositoryData['username'] = '';
        }
        if (!isset($repositoryData['password'])) {
            $repositoryData['password'] = '';
        }
        return $this->db->prepare(
            "UPDATE repositories
            SET `name` = %s,
              `type` = %s,
              `url` = %s,
              `username` = %s,
              `password` = %s
            WHERE id = %d",
            $repositoryData['name'],
            $repositoryData['type'],
            $repositoryData['url'],
            $repositoryData['username'],
            $repositoryData['password'],
            $repositoryData['id']
        )->execute();
    }

    /**
     * Deletes a repository.
     *
     * @param int $repositoryId
     * @return bool
     */
    public function deleteRepository($repositoryId)
    {
        $repositoryId = (int)$repositoryId;
        if ($repositoryId === 0) {
            return false;
        }
        return $this->db->prepare("DELETE FROM repositories WHERE id = %d LIMIT 1", $repositoryId)->execute();
    }

    /**
     * Fetches repository data.
     *
     * @param int $repositoryId
     * @return array
     */
    public function getRepositoryData($repositoryId)
    {
        $repositoryId = (int)$repositoryId;
        if ($repositoryId === 0) {
            return [];
        }
        $repositoryData = $this->db
            ->prepare("SELECT * FROM repositories WHERE id = %d", $repositoryId)
            ->getResult(true);
        if (empty($repositoryData)) {
            return [];
        }
        if (!isset($repositoryData['username'])) {
            $repositoryData['username'] = '';
        }
        if (!isset($repositoryData['password'])) {
            $repositoryData['password'] = '';
        }
        return $repositoryData;
    }

    public function repositoryInUse($repositoryId)
    {
        $repositoryId = (int)$repositoryId;
        if (empty($repositoryId)) {
            throw new RuntimeException('repositoryId can not be empty.');
        }
        $cnt = $this->db
            ->prepare("SELECT COUNT(id) FROM deployments WHERE `repository_id` = %d", $repositoryId)
            ->getValue();
        return ($cnt > 0);
    }
}