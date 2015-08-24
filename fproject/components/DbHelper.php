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
use Codeception\Util\Debug;
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
     * @return stdClass An instance of stdClass that has of two fields:
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
        foreach ($models as $model)
        {
            if(!isset($tableSchema))
                $tableSchema = $model->getTableSchema();

            $pks = $model->getPrimaryKey(true);

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
                if(property_exists($model,'_isInserting'))
                {
                    $inserting = (bool)$model->{'_isInserting'};
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

                foreach($pks as $pkName=>$pkValue)
                {
                    unset($data[$pkName]);
                }
                $insertData[] = $data;
                $insertModels[] = $model;
            }
            else
            {
                $updateData[] = $model->toArray($attributeNames);
                $updateModels = $model;
            }
        }

        $retObj = new stdClass();

        if(count($updateData) > 0 && isset($tableSchema))
        {
            self::updateMultiple($tableSchema->fullName, $updateData, array_keys($pks));
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
     * @return integer number of rows affected by the execution.
     */
    public static function updateMultiple($table, $data, $pkNames)
    {
        $command = self::createMultipleUpdateCommand($table, $data, $pkNames);
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
     * @param array $templates templates for the SQL parts.
     * @throws \yii\db\Exception
     * @return \yii\db\Command multiple insert command
     */
    public static function createMultipleUpdateCommand($table, $data, $pkNames, $templates=null)
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
            $columnNameValuePairs=[];
            foreach($rowData as $columnName=>$columnValue)
            {
                if(is_array($pkNames))
                {
                    foreach($pkNames as $pk)
                    {
                        if (strcasecmp($columnName, $pk) == 0)
                        {
                            $params[':'.$columnName.'_'.$rowKey] = $columnValue;
                            $pkToColumnName[$pk]=$columnName;
                            continue;
                        }
                    }
                }
                else if (strcasecmp($columnName, $pkNames) == 0)
                {
                    $params[':'.$columnName.'_'.$rowKey] = $columnValue;
                    $pkToColumnName[$pkNames]=$columnName;
                    continue;
                }
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

            //Skip all rows that don't have primary key value;
            if(is_array($pkNames))
            {
                $rowUpdateCondition = '';
                foreach($pkNames as $pk)
                {
                    if(!isset($pkToColumnName[$pk]))
                        continue;
                    if($rowUpdateCondition != '')
                        $rowUpdateCondition = $rowUpdateCondition.$templates['rowUpdateConditionJoin'];
                    $rowUpdateCondition = $rowUpdateCondition.strtr($templates['rowUpdateConditionExpression'], array(
                        '{{pkName}}'=>$pk,
                        '{{pkValue}}'=>':'.$pkToColumnName[$pk].'_'.$rowKey,
                    ));
                }
            }
            else
            {
                if(!isset($pkToColumnName[$pkNames]))
                    continue;
                $rowUpdateCondition = strtr($templates['rowUpdateConditionExpression'], array(
                    '{{pkName}}'=>$pkNames,
                    '{{pkValue}}'=>':'.$pkToColumnName[$pkNames].'_'.$rowKey,
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
}