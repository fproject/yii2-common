<?php

namespace tests\unit\rest;

use tests\unit\models\User;
use Yii;
use yii\codeception\TestCase;
use fproject\components\DbHelper;

class DbHelperTest extends TestCase
{
	use \Codeception\Specify;

    public function testCreateMultipleUpdateCommand001()
    {
        $cmd = DbHelper::createMultipleUpdateCommand('user',[],'id');
        $this->assertInstanceOf('yii\db\Command', $cmd);
    }

    public function testBatchSaveCommand001()
    {
        $models = [];
        for($i=0;$i<10;$i++)
        {
            $m = $models[] = new User();
            $m->username = "New User $i-".rand(1000,9999);
            $m->password = $m->username;
        }
        $return = DbHelper::batchSave($models);
        $this->assertObjectNotHasAttribute('updateCount', $return);
        $this->assertObjectHasAttribute('insertCount', $return);
        $this->assertObjectHasAttribute('lastId', $return);
    }
}
