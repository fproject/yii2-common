<?php

namespace tests\codeception\unit\models\base;

use fproject\common\IUpdatableKeyModel;
use fproject\components\ActiveRecord;
use tests\codeception\unit\components\UpdatableKeyModelBehavior;
use Yii;
use yii\db\BaseActiveRecord;

/**
 * This is the model class for table "user_department_assignment".
 *
 * @property integer $userId
 * @property integer $departmentId
 *
 * @property User $user
 * @property Department $department
 */
class UserDepartmentAssignment extends ActiveRecord implements IUpdatableKeyModel
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'user_department_assignment';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'userId' => 'User ID',
            'departmentId' => 'Department Id',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::className(), ['id' => 'userId']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getDepartment()
    {
        return $this->hasOne(User::className(), ['id' => 'departmentId']);
    }

    /**
     * Returns the old primary key value.
     * This refers to the primary key value that is populated from the active record
     * after executing a find method (e.g. find(), findOne()).
     * The value remains unchanged even if the primary key attribute is manually assigned with a different value.
     * @return mixed the old primary key value. An array (column name=>column value) is returned if the primary key is composite.
     * If primary key is not defined, null will be returned.
     */
    public function getOldKey()
    {
        return $this->getOldPrimaryKey(true);
    }
}
