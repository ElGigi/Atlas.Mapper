<?php
/**
 *
 * This file is part of Atlas for PHP.
 *
 * @license http://opensource.org/licenses/MIT MIT
 *
 */
declare(strict_types=1);

namespace Atlas\Mapper;

use Atlas\Mapper\Identity;
use Atlas\Mapper\Relationships;
use Atlas\Table\Row;
use Atlas\Table\Table;
use SplObjectStorage;

abstract class Mapper
{
    protected $table;

    protected $relationships;

    protected $mapperEvents;

    protected $recordSetClass;

    public function __construct(
        Table $table,
        MapperRelationships $relationships,
        MapperEvents $mapperEvents
    ) {
        $this->table = $table;
        $this->relationships = $relationships;
        $this->mapperEvents = $mapperEvents;

        $this->recordSetClass = substr(static::class, 0, -6) . 'RecordSet';

        $primaryKey = $this->table::PRIMARY_KEY;
        if (count($primaryKey) == 1) {
            $this->identityMap = new Identity\IdentitySimple($primaryKey[0]);
        } else {
            $this->identityMap = new Identity\IdentityComposite($primaryKey);
        }
    }

    public function getTable() : Table
    {
        return $this->table;
    }

    public function getRelationships() : MapperRelationships
    {
        return $this->relationships;
    }

    public function fetchRecord($primaryVal, array $with = []) : ?Record
    {
        $serial = $this->identityMap->getSerial($primaryVal);
        $row = $this->identityMap->getRow($serial)
            ?? $this->table->fetchRow($primaryVal);

        if ($row === null) {
            return null;
        }

        return $this->turnRowIntoRecord($row, $with);
    }

    public function fetchRecordBy(
        array $whereEquals,
        array $with = []
    ) : ?Record
    {
        $row = $this->table->select($whereEquals)->fetchRow();
        if ($row === null) {
            return null;
        }

        return $this->turnRowIntoRecord($row, $with);
    }

    public function fetchRecords(array $primaryVals, array $with = []) : array
    {
        $rows = [];
        $missing = [];

        // find identity-mapped rows
        foreach ($primaryVals as $primaryVal) {
            $serial = $this->identityMap->getSerial($primaryVal);
            $memory = $this->identityMap->getRow($serial);
            if ($memory === null) {
                $rows[$serial] = null;
                $missing[$serial] = $primaryVal;
            } else {
                $rows[$serial] = $memory;
            }
        }

        // fetch rows missing from identity map
        foreach ($this->table->fetchRows($missing) as $row) {
            $serial = $this->identityMap->getSerial($row);
            $rows[$serial] = $row;
            unset($missing[$serial]);
        }

        // remove placeholders for unfetched rows
        foreach ($missing as $serial => $primaryVal) {
            unset($rows[$serial]);
        }

        return $this->turnRowsIntoRecords($rows, $with);
    }

    public function fetchRecordsBy(array $whereEquals, array $with = []) : array
    {
        $rows = $this->table->select($whereEquals)->fetchRows();
        return $this->turnRowsIntoRecords($rows, $with);
    }

    public function fetchRecordSet(
        array $primaryVals,
        array $with = []
    ) : RecordSet
    {
        $records = $this->fetchRecords($primaryVals, $with);
        return $this->newRecordSet($records);
    }

    public function fetchRecordSetBy(
        array $whereEquals,
        array $with = []
    ) : RecordSet
    {
        $records = $this->fetchRecordsBy($whereEquals, $with);
        return $this->newRecordSet($records);
    }

    public function select(array $whereEquals = []) : MapperSelect
    {
        $select = $this->table->select($whereEquals);
        $select->setMapper($this);
        $this->mapperEvents->modifySelect($this, $select);
        return $select;
    }

    public function insert(Record $record) : bool
    {
        $this->mapperEvents->beforeInsert($this, $record);
        $this->relationships->fixNativeRecordKeys($record);
        $insert = $this->table->insertRowPrepare($record->getRow());
        $this->mapperEvents->modifyInsert($this, $record, $insert);

        $row = $record->getRow();
        $pdoStatement = $this->table->insertRowPerform($row, $insert);

        $this->identityMap->setRow($row);

        $this->relationships->fixForeignRecordKeys($record);
        $this->mapperEvents->afterInsert(
            $this,
            $record,
            $insert,
            $pdoStatement
        );
        return true;
    }

    public function update(Record $record) : bool
    {
        $this->mapperEvents->beforeUpdate($this, $record);
        $this->relationships->fixNativeRecordKeys($record);
        $update = $this->table->updateRowPrepare($record->getRow());
        $this->mapperEvents->modifyUpdate($this, $record, $update);
        $pdoStatement = $this->table->updateRowPerform(
            $record->getRow(),
            $update
        );
        $this->relationships->fixForeignRecordKeys($record);
        if ($pdoStatement === null) {
            return false;
        }
        $this->mapperEvents->afterUpdate(
            $this,
            $record,
            $update,
            $pdoStatement
        );
        return true;
    }

    public function delete(Record $record) : bool
    {
        $this->mapperEvents->beforeDelete($this, $record);
        $delete = $this->table->deleteRowPrepare($record->getRow());
        $this->mapperEvents->modifyDelete($this, $record, $delete);
        $pdoStatement = $this->table->deleteRowPerform(
            $record->getRow(),
            $delete
        );
        $this->mapperEvents->afterDelete(
            $this,
            $record,
            $delete,
            $pdoStatement
        );
        return true;
    }

    public function persist(
        Record $record,
        SplObjectStorage $tracker = null
    ) : bool
    {
        if ($tracker === null) {
            $tracker = new SplObjectStorage();
        }

        if ($tracker->contains($record)) {
            return false;
        }

        $tracker->attach($record);

        $this->relationships->persistBeforeNative($record, $tracker);
        $this->relationships->fixNativeRecordKeys($record);

        $method = $record->getAction();
        if ($method !== '') {
            $this->$method($record);
        }

        $this->relationships->fixForeignRecordKeys($record);
        $this->relationships->persistAfterNative($record, $tracker);

        return true;
    }

    public function newRecord(array $fields = []) : Record
    {
        $row = $this->table->newRow($fields);
        $record = $this->newRecordFromRow($row);
        $record->getRelated()->set($fields);
        return $record;
    }

    public function newRecords(array $fieldSets) : array
    {
        $records = [];
        foreach ($fieldSets as $fields) {
            $records[] = $this->newRecord($fields);
        }
        return $records;
    }

    public function newRecordSet(array $records = []) : RecordSet
    {
        $recordSetClass = $this->recordSetClass;
        return new $recordSetClass(
            $records,
            [$this, 'newRecord']
        );
    }

    public function turnRowIntoRecord(Row $row, array $with = []) : Record
    {
        $record = $this->newRecordFromSelectedRow($row);
        $this->relationships->stitchIntoRecords([$record], $with);
        return $record;
    }

    public function turnRowsIntoRecords(array $rows, array $with = []) : array
    {
        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->newRecordFromSelectedRow($row);
        }
        $this->relationships->stitchIntoRecords($records, $with);
        return $records;
    }

    protected function newRecordFromSelectedRow(Row $row) : Record
    {
        $row = $this->identityMap->setOrGetRow($row);
        return $this->newRecordFromRow($row);
    }

    protected function newRecordFromRow(Row $row) : Record
    {
        $recordClass = $this->getRecordClass($row);
        return new $recordClass(
            $row,
            $this->relationships->newRelated()
        );
    }

    /**
     * Override to enable single table inheritance.
     * Record class must be in same namespace as mapper.
     */
    protected function getRecordClass(Row $row) : string
    {
        return substr(static::class, 0, -6) . 'Record';
    }
}
