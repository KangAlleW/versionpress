<?php

class CommentSynchronizer implements Synchronizer {

    private $prefix = 'wp_';
    private $entityName = 'comments';
    private $idColumnName = 'comment_ID';

    /**
     * @var EntityStorage
     */
    private $storage;

    /**
     * @var wpdb
     */
    private $database;

    /**
     * @var DbSchemaInfo
     */
    private $dbSchema;

    function __construct(EntityStorage $storage, wpdb $database, DbSchemaInfo $dbSchema) {
        $this->storage = $storage;
        $this->database = $database;
        $this->dbSchema = $dbSchema;
    }

    function synchronize() {
        $entities = $this->storage->loadAll();
        $this->updateDatabase($entities);
        $this->fixReferences($entities);
        $this->mirrorDatabaseToStorage();
    }

    private function updateDatabase($entities) {
        $entitiesWithoutIds = array_map(function ($entity) {
            $entityClone = $entity;
            unset($entityClone[$this->idColumnName]);
            return $entityClone;
        }, $entities);

        foreach ($entitiesWithoutIds as $entity) {
            $vpId = $entity['vp_id'];
            $isExistingEntity = $this->isExistingEntity($vpId);

            if ($isExistingEntity) {
                $this->updateEntityInDatabase($entity);
            } else {
                $this->createEntityInDatabase($entity);
            }
        }

        $this->deleteEntitiesWhichAreNotInStorage($entities);
    }

    private function fixReferences($entities) {
        $hasReferences = $this->dbSchema->hasReferences($this->entityName);
        if (!$hasReferences)
            return;

        $usedReferences = array();
        foreach ($entities as $entity) {
            $referenceDetails = $this->fixReferencesOfOneEntity($entity);
            $usedReferences = array_merge($usedReferences, $referenceDetails);
        }

        if (count($usedReferences) > 0) {
            $referenceTableName = $this->getPrefixedTableName('vp_references');

            $insertQuery = "INSERT INTO {$referenceTableName} (" . join(array_keys($usedReferences[0]), ', ') . ")
            VALUES (" . join(array_map(function ($values) {
                    return join($values, ', ');
                }, $usedReferences), '), (') . ")
            ON DUPLICATE KEY UPDATE reference_vp_id = VALUES(reference_vp_id)";
            $this->executeQuery($insertQuery);

            $constraintOfAllExistingReferences = 'NOT((' . join(array_map(function ($a) {
                    return join(Arrays::parametrize($a), ' AND ');
                }, $usedReferences), ') OR (') . '))';
            $deleteQuery = "DELETE FROM {$referenceTableName} WHERE {$constraintOfAllExistingReferences} AND `table` = \"{$this->entityName}\"";
            $this->executeQuery($deleteQuery);
        }

        $references = $this->dbSchema->getReferences($this->entityName);
        foreach($references as $referenceName => $_){
            $updateQuery = "UPDATE wp_comments SET `{$referenceName}` =
            (SELECT reference_id FROM {$this->getPrefixedTableName('vp_reference_details')}
            WHERE id={$this->idColumnName} AND `table` = \"{$this->entityName}\" and reference = \"{$referenceName}\")";
            $this->executeQuery($updateQuery);
        }
    }

    private function mirrorDatabaseToStorage() {
        return;
        $entitiesInDatabase = $this->getIdsInDatabase();
        $entitiesInStorage = $this->storage->loadAll();

        $getEntityId = function ($entity) {
            return $entity[$this->idColumnName];
        };

        $dbEntityIds = array_map($getEntityId, $entitiesInDatabase);
        $storageEntityIds = array_map($getEntityId, $entitiesInStorage);

        $entitiesToDelete = array_diff($storageEntityIds, $dbEntityIds);
        $entitiesToSave = array_diff($dbEntityIds, $storageEntityIds);

        foreach ($entitiesToDelete as $entityId) {
            $this->storage->delete(array($this->idColumnName => $entityId));
        }

        foreach ($entitiesToSave as $key => $entityId) {
            $this->storage->save($entitiesInDatabase[$key]); // TODO: replace foreign keys with vp references
        }
    }

    private function updateEntityInDatabase($entity) {
        $updateQuery = $this->buildUpdateQuery($entity);
        $this->executeQuery($updateQuery);
    }

    private function createEntityInDatabase($entity) {
        $createQuery = $this->buildCreateQuery($entity);
        $this->executeQuery($createQuery);
        $id = $this->database->insert_id;
        $this->createIdentifierRecord($entity['vp_id'], $id);
    }

    private function getId($vpId) {
        $vpIdTableName = $this->getPrefixedTableName('vp_id');
        return $this->database->get_var("SELECT id FROM $vpIdTableName WHERE `table` = \"$this->entityName\" AND vp_id = $vpId");
    }

    private function getPrefixedTableName($tableName) {
        return $this->prefix . $tableName;
    }

    private function buildUpdateQuery($updateData) {
        $id = $this->getId($updateData['vp_id']);
        $query = "UPDATE {$this->getPrefixedTableName($this->entityName)} SET";
        foreach ($updateData as $key => $value) {
            if (!Strings::startsWith($key, 'vp_'))
                $query .= " `$key` = " . (is_numeric($value) ? $value : '"' . mysql_real_escape_string($value) . '"') . ',';
        }
        $query[strlen($query) - 1] = ' '; // strip the last comma
        $query .= " WHERE $this->idColumnName = $id";
        return $query;
    }

    private function isExistingEntity($vpId) {
        return (bool)$this->getId($vpId);
    }

    private function buildCreateQuery($entity) {
        $columns = array_keys($entity);
        $columns = array_filter($columns, function ($column) {
            return !Strings::startsWith($column, 'vp_');
        });
        $columnsString = join(', ', array_map(function ($column) {
            return "`$column`";
        }, $columns));

        $query = "INSERT INTO {$this->getPrefixedTableName($this->entityName)} ({$columnsString}) VALUES (";

        foreach ($columns as $column) {
            $query .= (is_numeric($entity[$column]) ? $entity[$column] : '"' . mysql_real_escape_string($entity[$column]) . '"') . ", ";
        }

        $query[strlen($query) - 2] = ' '; // strip the last comma
        $query .= ");";
        return $query;
    }

    /** used for debugging */
    private function executeQuery($query) {
        $result = $this->database->query($query);
        return $result;
    }

    private function createIdentifierRecord($vp_id, $id) {
        $query = "INSERT INTO {$this->getPrefixedTableName('vp_id')} (`table`, vp_id, id)
            VALUES (\"{$this->entityName}\", $vp_id, $id)";
        $this->executeQuery($query);
    }

    private function deleteEntitiesWhichAreNotInStorage($entities) {
        $vpIds = array_map(function ($entity) {
            return $entity['vp_id'];
        }, $entities);

        $ids = $this->database->get_col("SELECT id FROM {$this->getPrefixedTableName('vp_id')} " .
        "WHERE `table` = \"{$this->entityName}\" AND vp_id NOT IN (" . join(",", $vpIds) . ")");

        if (count($ids) == 0)
            return;

        $idsString = join(',', $ids);

        $this->executeQuery("DELETE FROM {$this->getPrefixedTableName($this->entityName)} WHERE {$this->idColumnName} IN ({$idsString})");
        $this->executeQuery("DELETE FROM {$this->getPrefixedTableName('vp_id')} WHERE `table` = \"{$this->entityName}\" AND id IN ({$idsString})"); // using cascade delete in mysql
    }

    private function getIdsInDatabase() {
        return $this->database->get_results("SELECT * FROM {$this->getPrefixedTableName($this->entityName)}", ARRAY_A);
    }

    private function fixReferencesOfOneEntity($entity) {
        $references = $this->getAllReferences($entity);

        $referencesDetails = array();
        foreach ($references as $referenceName => $reference) {
            if ($reference == 0)
                continue;

            $referencesDetails[] = array(
                '`table`' => "\"" . $this->entityName . "\"",
                'reference' => "\"" . $referenceName . "\"",
                'vp_id' => $entity['vp_id'],
                'reference_vp_id' => $reference
            );
        }

        return $referencesDetails;
    }

    private function getAllReferences($entity) {
        $references = array();
        $referencesInfo = $this->dbSchema->getReferences($this->entityName);

        foreach ($entity as $key => $value) {
            if (Strings::startsWith($key, 'vp_')) {
                $key = Strings::substring($key, 3);
                if (isset($referencesInfo[$key]))
                    $references[$key] = $value;
            }
        }

        return $references;
    }
}