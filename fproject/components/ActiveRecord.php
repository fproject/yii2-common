<?php
///////////////////////////////////////////////////////////////////////////////
//
// � Copyright f-project.net 2010-present. All Rights Reserved.
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

/**
 *
 * The base class for all ActiveRecord model classes of F-Project framework
 *
 * */
class ActiveRecord extends \yii\db\ActiveRecord
{
    /**
     * Save a list of models, each model may be inserted or updated depend on its existence.
     * This method could be used to achieve better performance during insertion/update of the large
     * amount of data to the database table.
     * @param \yii\db\ActiveRecord[] $models list of models to be saved.
     * If a key is not a valid column name, the corresponding value will be ignored.
     * @param array $attributeNames name list of attributes that need to be update. Defaults to empty,
     * meaning all fields of corresponding active record will be saved.
     * This parameter is ignored in the case of insertion
     * @param int $mode the save mode flag.
     * If this flag value is set to 0, any model that have a PK value is NULL will be inserted, otherwise it will be update.
     * If this flag value is set to 1, all models will be inserted regardless to PK values.
     * If this flag value is set to 2, all models will be updated regardless to PK values
     * @return array an array of two elements: the first is the last model ID (auto-incremental primary key)
     * inserted, the second is the number of rows inserted.
     * If there's no row inserted, the return value is null.
     */
    public static function batchSave($models, $attributeNames=[], $mode=DbHelper::SAVE_MODE_AUTO)
    {
        $a = DbHelper::batchSave($models, $attributeNames, $mode);
        if(isset($a))
            static::afterBatchSave($models, $attributeNames, $mode);
        return $a;
    }

    public static function afterBatchSave($models, $attributeNames, $mode)
    {
        //Abstract method
    }
}