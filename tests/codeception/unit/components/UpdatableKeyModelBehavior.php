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

namespace tests\codeception\unit\components;

use Yii;
use yii\base\Behavior;
use yii\db\BaseActiveRecord;

/**
 * ActiveWorkflowBehavior implements the behavior of db model evolving inside a workflow.
 *
 * To use ActiveWorkflowBehavior with the default parameters, simply attach it to the model class.
 * ~~~
 * use fproject\workflow\core\ActiveWorkflowBehavior;
 *
 * public function behaviors()
 * {
 *     return [
 *         'workflow' => [
 *             'class' => ActiveWorkflowBehavior::className()
 *         ],
 *     ];
 * }
 *
 *
 * @property BaseActiveRecord owner
 */
class UpdatableKeyModelBehavior extends Behavior
{

	/**
	 * This method is called when the AR object is created and populated with the query result.
	 * The default implementation will trigger an [[EVENT_AFTER_FIND]] event.
	 * When overriding this method, make sure you call the parent implementation to ensure the
	 * event is triggered.
	 */
	public function afterFindHandler()
	{
		$this->oldKey = $this->owner->getPrimaryKey(true);
	}

	/**
	 * (non-PHPDoc)
	 * @see \yii\base\Behavior::events()
	 */
	public function events()
	{
		return [
			BaseActiveRecord::EVENT_AFTER_FIND => 'afterFindHandler',
		];
	}
}
