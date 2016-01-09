<?php
///////////////////////////////////////////////////////////////////////////////
//
// © Copyright f-project.net 2010-present. All Rights Reserved.
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
///////////////////////////////////////////////////////////////////////////////

namespace fproject\components;
use fproject\common\IUpdatableKeyModel;
use Yii;
use yii\db\Connection;
use stdClass;
use yii\db\Exception;
use yii\db\ActiveRecord;

/**
 * The Database Helper class
 *
 * @author Bui Sy Nguyen <nguyenbs@projectkit.net>
 */
class DbHelper
{
    /**
     * @return Connection
     */
    private static function db()
    {
        return Yii::$app->db;
    }

    const SAVE_MODE_AUTO = 0;
    const SAVE_MODE_INSERT_ALL = 1;
    const SAVE_MODE_UPDATE_ALL = 2;

    /**
     * Save a list of data to a table, each row data may be inserted or updated depend on its existence.
     * This method could be used to achieve better performance during insertion/update of the large
     * amount of data to the database table.
     * @param ActiveRecord[] $models list of models to be saved.
     * If a key is not a valid column name, the corresponding value will be ignored.
     * @param array $attributeNames name list of attributes that need to be update. Defaults to empty,
     * meaning all fields of corresponding active record will be saved.
     * This parameter is ignored in the case of insertion
     * @param int $mode the save mode flag.
     * If this flag value is set to 0, any model that have a PK value is NULL will be inserted, otherwise it will be update.
     * If this flag value is set to 1, all models will be inserted regardless to PK values.
     * If this flag value is set to 2, all models will be updated regardless to PK values.
     * @param array $returnModels An associative array contains two element:
     * ```php
     *      [
     *      'inserted' => [<array of inserted models with ID populated>],
     *      'updated' => [<array of updated models>]
     *      ]
     * ```
     * @return stdClass An instance of stdClass that may have one of the following fields:
     * - The 'lastId' field is the last model ID (auto-incremental primary key) inserted.
     * - The 'insertCount' is the number of rows inserted.
     * - The 'updateCount' is the number of rows updated.
     */
    public static function batchSave($models, $attributeNames=[], $mode=self::SAVE_MODE_AUTO, &$returnModels=null)
    {
        Yii::trace('batchSave()','application.DbHelper');
        if(is_null($models) || empty($models))
            return null;

        $model = reset($models);
        if(method_exists($model, 'beforeBatchSave'))
        {
            call_user_func([$model, 'beforeBatchSave'], $models);
        }

        $insertData = [];
        $insertModels = [];
        $updateData=[];
        $updateModels = [];
        $updateKeyValues = [];
        $updateCnt = 0;
        foreach ($models as $model)
        {
            if(!isset($tableSchema))
                $tableSchema = $model->getTableSchema();

            $pks = $model->getPrimaryKey(true);
            $oldKey = null;

            if($mode==self::SAVE_MODE_INSERT_ALL)
            {
                $inserting = true;
            }
            elseif($mode==self::SAVE_MODE_UPDATE_ALL)
            {
                $inserting = false;
            }
            else // $mode == SAVE_MODE_AUTO
            {
                if($model instanceof IUpdatableKeyModel)
                {
                    $oldKey = $model->getOldKey();
                    if(is_array($oldKey) && !empty($oldKey))
                    {
                        $inserting = false;
                        foreach($oldKey as $k=>$v)
                        {
                            if(empty($v))
                            {
                                $inserting = true;
                                $oldKey = null;
                                break;
                            }
                        }
                    }
                    else
                    {
                        $inserting = empty($oldKey);
                    }
                }
                else
                {
                    $inserting = false;

                    foreach($pks as $pkName=>$pkValue)
                    {
                        if(empty($pkValue) || !is_numeric($pkValue))
                        {
                            $inserting = true;
                            break;
                        }
                    }
                }
            }

            if($inserting)
            {
                $data = $model->toArray($attributeNames);

                $insertData[] = $data;
                $insertModels[] = $model;
            }
            else
            {
                $updateData[] = $model->toArray($attributeNames);
                $updateModels[] = $model;
                if(!empty($oldKey))
                {
                    $updateKeyValues[$updateCnt] = $oldKey;
                }
                $updateCnt++;
            }
        }

        $retObj = new stdClass();

        if($updateCnt > 0 && isset($tableSchema))
        {
            self::updateMultiple($tableSchema->fullName, $updateData, array_keys($pks), count($updateKeyValues) > 0 ? $updateKeyValues : null);
            $retObj->updateCount = count($updateData);
            if(isset($returnModels))
                $returnModels['updated'] = $updateModels;
        }
        if(count($insertModels) > 0 && isset($tableSchema))
        {
            $retObj->insertCount = self::insertMultiple($tableSchema->fullName, $insertData);
            $id = self::db()->getLastInsertID($tableSchema->sequenceName);
            if(is_numeric($id))
                $id = $retObj->insertCount + intval($id) - 1;
            $retObj->lastId = $id;
            if(isset($returnModels))
            {
                self::populateIds($insertModels, array_keys($pks), $id, $retObj->insertCount);
                $returnModels['inserted'] = $insertModels;
            }
        }

        return $retObj;
    }

    /**
     * Delete a list of data from a table, each row data may be inserted or updated depend on its existence.
     * This method could be used to achieve better performance during insertion/update of the large
     * amount of data to the database table.
     * @param string $table the table that has rows will be deleted.
     * @param array $data list of row-criteria for the rows to be deleted.
     * Each element should be an array in form ['column1'=>'value1', 'column2'=>'value2',...]
     *
     * @return int the number of row deleted
     */
    public static function batchDelete($table, $data)
    {
        $cnt = count($data);

        if($cnt == 0)
            return 0;

        $command = self::createMultipleDeleteCommand($table, $data);
        $n = $command->execute();
        if($n > 0)
        {
            if($n > 1)
                return $n;
            else
                return $cnt;
        }
        return 0;
    }

    /**
     * Populate auto-increment IDs back to models after batch-inserting
     * @param ActiveRecord[] $insertModels
     * @param array $pks
     * @param $lastPk
     * @param $insertedCount
     */
    private static function populateIds(&$insertModels, $pks, $lastPk, $insertedCount)
    {
        while($insertedCount > 0 && $insertedCount <= count($insertModels))
        {
            $insertedCount--;
            $model = $insertModels[$insertedCount];
            $model->{$pks[0]} = $lastPk;
            $lastPk = intval($lastPk) - 1;
        }
    }

    /**
     * Batch-insert a list of data to a table.
     * This method could be used to achieve better performance during insertion of the large
     * amount of data into the database table.
     *
     * For example,
     *
     * ~~~
     * DbHelper::batchInsert('user', [
     *     ['name' => 'Tom', 'age' => 30],
     *     ['age' => 20, 'name' => 'Jane'],
     *     ['name' => 'Linda', 'age' => 25],
     * ]);
     * ~~~
     *
     * Note that the values in each row must match the corresponding column names.
     *
     * @param string $table the table that new rows will be inserted into.
     * @param array $data list data to be inserted, each value should be an array in format (column name=>column value).
     * If a key is not a valid column name, the corresponding value will be ignored.
     * @return integer number of rows affected by the execution.
     */
    public static function insertMultiple($table, $data)
    {
        $columns = [];
        $i = 0;

        foreach($data as $dataRow)
        {
            foreach($dataRow as $key=>$value)
            {
                if(!array_key_exists($key, $columns))
                {
                    $columns[$key] = $i;
                    $i++;
                }
            }
        }

        $rows = [];

        foreach($data as $dataRow)
        {
            $row = [];
            foreach($columns as $key=>$i)
            {
                $row[$i] = array_key_exists($key, $dataRow) ? $dataRow[$key] : null;
            }
            $rows[] = $row;
        }
        return self::db()->createCommand()->batchInsert($table, array_keys($columns), $rows)->execute();
    }

    /**
     * Batch-update a list of data to a table.
     * This method could be used to achieve better performance during insertion of the large
     * amount of data into the database table.
     *
     * For example,
     *
     * ~~~
     * DbHelper::updateMultiple('user', [
     *     ['id' => 1, 'name' => 'Tom', 'age' => 30],
     *     ['id' => 2, 'age' => 20, 'name' => 'Jane'],
     *     ['id' => 3, 'name' => 'Linda', 'age' => 25],
     * ],
     * 'id');
     * ~~~
     *
     * Note that the values in each row must match the corresponding column names.
     *
     * @param string $table the table that has new rows will be updated.
     * @param array $data list data to be inserted, each value should be an array in format (column name=>column value).
     * If a key is not a valid column name, the corresponding value will be ignored.
     * @param mixed $pkNames Name or an array of names of primary key(s)
     * @param array $pkValues The primary key-values array. If this parameter is null, the primary keys will be get from
     * the corresponding field in records of $data array.
     * @return integer number of rows affected by the execution.
     */
    public static function updateMultiple($table, $data, $pkNames, $pkValues=null)
    {
        $command = self::createMultipleUpdateCommand($table, $data, $pkNames, $pkValues);
        return $command->execute();
    }

    /**
     * Creates a multiple UPDATE command.
     * This method compose the SQL expression via given part templates, providing ability to adjust
     * command for different SQL syntax.
     * @param string $table the table that has new rows will be updated.
     * @param array $data list data to be saved, each value should be an array in format (column name=>column value).
     * If a key is not a valid column name, the corresponding value will be ignored.
     * @param mixed $pkNames Name or an array of names of primary key(s)
     * @param array $pkValues The primary key-values array. If this parameter is null, the primary keys will be get from
     * the corresponding field in records of $data array.
     * @param array $templates Templates for the SQL parts.
     * @throws \yii\db\Exception
     * @return \yii\db\Command Multiple update command
     */
    public static function createMultipleUpdateCommand($table, $data, $pkNames, $pkValues=null, $templates=null)
    {
        if(is_null($templates))
        {
            $templates = [
                'rowUpdateStatement'=>'UPDATE {{tableName}} SET {{columnNameValuePairs}} WHERE {{rowUpdateCondition}}',
                'columnAssignValue'=>'{{column}}={{value}}',
                'columnValueGlue'=>',',
                'rowUpdateConditionExpression'=>'{{pkName}}={{pkValue}}',
                'rowUpdateConditionJoin'=>' AND ',
                'rowUpdateStatementGlue'=>';',
            ];
        }

        $tableSchema=self::db()->schema->getTableSchema($tableName=$table);

        if($tableSchema===null)
            throw new Exception(Yii::t('yii','Table "{table}" does not exist.',
                ['{table}'=>$tableName]));
        $tableName=self::db()->quoteTableName($tableSchema->name);
        $params=[];
        $quoteColumnNames=[];

        $columns=[];

        foreach($data as $rowData)
        {
            foreach($rowData as $columnName=>$columnValue)
            {
                if(!in_array($columnName,$columns,true))
                    if($tableSchema->getColumn($columnName)!==null)
                        $columns[]=$columnName;
            }
        }

        foreach($columns as $name)
            $quoteColumnNames[$name]=self::db()->schema->quoteColumnName($name);

        $rowUpdateStatements=[];
        $pkToColumnName=[];

        foreach($data as $rowKey=>$rowData)
        {
            $hasPKValues = !empty($pkValues) && !empty($pkValues[$rowKey]);

            if($hasPKValues)
            {
                $pkValue = $pkValues[$rowKey];
                if(is_array($pkValue))
                {
                    foreach($pkValue as $n=>$v)
                    {
                        foreach($pkNames as $pk)
                        {
                            if (strcasecmp($n, $pk) == 0)
                            {
                                $params[':k_'.$n.'_'.$rowKey] = $v;
                                $pkToColumnName[$pk]=$n;
                                continue;
                            }
                        }
                    }
                }
            }

            $columnNameValuePairs=[];
            foreach($rowData as $columnName=>$columnValue)
            {
                $isPK = false;
                if(is_array($pkNames))
                {
                    foreach($pkNames as $pk)
                    {
                        if (strcasecmp($columnName, $pk) == 0)
                        {
                            $params[':'.$columnName.'_'.$rowKey] = $columnValue;
                            $pkToColumnName[$pk]=$columnName;
                            $isPK = true;
                            break;
                        }
                    }
                }
                else if (strcasecmp($columnName, $pkNames) == 0)
                {
                    $params[':'.$columnName.'_'.$rowKey] = $columnValue;
                    $pkToColumnName[$pkNames]=$columnName;
                    $isPK = true;
                }

                if(!$isPK || $hasPKValues)
                {
                    /** @var \yii\db\ColumnSchema $column */
                    $column=$tableSchema->getColumn($columnName);
                    $paramValuePlaceHolder=':'.$columnName.'_'.$rowKey;
                    $params[$paramValuePlaceHolder]=$column->dbTypecast($columnValue);

                    $columnNameValuePairs[]=strtr($templates['columnAssignValue'],
                        [
                            '{{column}}'=>$quoteColumnNames[$columnName],
                            '{{value}}'=>$paramValuePlaceHolder,
                        ]);
                }
            }

            //Skip all rows that don't have primary key value;
            if(is_array($pkNames))
            {
                $rowUpdateCondition = '';
                foreach($pkNames as $pk)
                {
                    if(!isset($pkToColumnName[$pk]))
                        continue;

                    $pkValuePlaceHolder = $hasPKValues ? ':k_'.$pkToColumnName[$pk].'_'.$rowKey : ':'.$pkToColumnName[$pk].'_'.$rowKey;

                    if($rowUpdateCondition != '')
                        $rowUpdateCondition = $rowUpdateCondition.$templates['rowUpdateConditionJoin'];
                    $rowUpdateCondition = $rowUpdateCondition.strtr($templates['rowUpdateConditionExpression'], array(
                        '{{pkName}}'=>$pk,
                        '{{pkValue}}'=>$pkValuePlaceHolder,
                    ));
                }
            }
            else
            {
                if(!isset($pkToColumnName[$pkNames]))
                    continue;

                $pkValuePlaceHolder = $hasPKValues ? ':k_'.$pkToColumnName[$pkNames].'_'.$rowKey : ':'.$pkToColumnName[$pkNames].'_'.$rowKey;

                $rowUpdateCondition = strtr($templates['rowUpdateConditionExpression'], array(
                    '{{pkName}}'=>$pkNames,
                    '{{pkValue}}'=>$pkValuePlaceHolder,
                ));
            }

            $rowUpdateStatements[]=strtr($templates['rowUpdateStatement'],array(
                '{{tableName}}'=>$tableName,
                '{{columnNameValuePairs}}'=>implode($templates['columnValueGlue'],$columnNameValuePairs),
                '{{rowUpdateCondition}}'=>$rowUpdateCondition,
            ));
        }

        $sql=implode($templates['rowUpdateStatementGlue'], $rowUpdateStatements);

        //Must ensure Yii::$app->db->emulatePrepare is set to TRUE;
        $command=self::db()->createCommand($sql);

        foreach($params as $name=>$value)
            $command->bindValue($name,$value);

        return $command;
    }

    /**
     * Creates a multiple DELETE command.
     * This method compose the SQL expression via given part templates, providing ability to adjust
     * command for different SQL syntax.
     * @param string $table the table that has rows will be deleted.
     * @param array $data list data to be delete, each value should be an array in format (column name=>column value).
     * If a key is not a valid column name, the corresponding value will be ignored.
     * @param array $templates templates for the SQL parts.
     * @throws \yii\db\Exception
     * @return \yii\db\Command multiple delete command
     */
    public static function createMultipleDeleteCommand($table, $data, $templates=null)
    {
        if(is_null($templates))
        {
            $templates = [
                'rowDeleteStatement'=>'DELETE FROM {{tableName}} WHERE {{rowDeleteCondition}}',
                'columnValueGlue'=>',',
                'rowDeleteConditionExpression'=>'{{colName}}={{colValue}}',
                'rowDeleteConditionJoin'=>' AND ',
                'rowDeleteStatementGlue'=>';',
            ];
        }

        $rowDeleteStatementGlue = $templates['rowDeleteStatementGlue'];

        $tableSchema=self::db()->schema->getTableSchema($tableName=$table);

        if($tableSchema===null)
            throw new Exception(Yii::t('yii','Table "{table}" does not exist.',
                ['{table}'=>$tableName]));
        $tableName=self::db()->quoteTableName($tableSchema->name);
        $params=[];
        $quoteColumnNames=[];

        $columns=[];

        foreach($data as $rowData)
        {
            foreach($rowData as $columnName=>$columnValue)
            {
                if(!in_array($columnName,$columns,true))
                    if($tableSchema->getColumn($columnName)!==null)
                        $columns[]=$columnName;
            }
        }

        foreach($columns as $name)
            $quoteColumnNames[$name]=self::db()->schema->quoteColumnName($name);

        $rowDeleteStatements=[];

        foreach($data as $rowKey=>$rowData)
        {
            $rowDeleteCondition = '';
            foreach($rowData as $columnName=>$columnValue)
            {
                /** @var \yii\db\ColumnSchema $column */
                $column=$tableSchema->getColumn($columnName);
                $paramValuePlaceHolder=':'.$columnName.'_'.$rowKey;
                $params[$paramValuePlaceHolder]=$column->dbTypecast($columnValue);

                if($rowDeleteCondition != '')
                    $rowDeleteCondition = $rowDeleteCondition.$templates['rowDeleteConditionJoin'];
                $rowDeleteCondition = $rowDeleteCondition.strtr($templates['rowDeleteConditionExpression'], array(
                        '{{colName}}'=>$columnName,
                        '{{colValue}}'=>$paramValuePlaceHolder,
                    ));
            }

            $rowDeleteStatements[]=strtr($templates['rowDeleteStatement'],array(
                '{{tableName}}'=>$tableName,
                '{{rowDeleteCondition}}'=>$rowDeleteCondition,
            ));
        }

        $sql=implode($rowDeleteStatementGlue, $rowDeleteStatements);

        if(self::db()->driverName =='mysql')
        {
            $rowCountStatement = 'SELECT ROW_COUNT()';
        }
        elseif(self::db()->driverName =='mssql')
        {
            $rowCountStatement = 'SELECT @@ROWCOUNT';
        }

        if(isset($rowCountStatement))
            $sql = $sql.$rowDeleteStatementGlue.$rowCountStatement;

        //Must ensure Yii::$app->db->emulatePrepare is set to TRUE;
        $command=self::db()->createCommand($sql);

        foreach($params as $name=>$value)
            $command->bindValue($name,$value);

        return $command;
    }
}