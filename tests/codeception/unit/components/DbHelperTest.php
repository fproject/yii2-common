<?php

namespace tests\unit\rest;

use Yii;
use yii\codeception\TestCase;
use fproject\components\DbHelper;

class DbHelperTest extends TestCase
{
	use \Codeception\Specify;

    public function testActions001()
    {
        $cmd = DbHelper::createMultipleUpdateCommand('user',[],'id');
        $this->assertInstanceOf('yii\db\Command', $cmd);
    }
}
