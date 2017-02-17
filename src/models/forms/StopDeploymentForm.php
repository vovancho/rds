<?php
namespace app\models\forms;

class StopDeploymentForm extends \yii\base\Model
{
    public $reason;
    public $status;

    /**
     * Returns the list of attribute names of the model.
     * @return array list of attribute names.
     */
    public function attributeLabels()
    {
        return [
            'reason' => 'Причина отключения',
            'status' => 'Статус',
        ];
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            ['reason', 'check'],
        ];
    }

    /**
     * @return void
     */
    public function check()
    {
        if (!$this->status && !$this->reason) {
            $this->addError('reason', 'Укажите причину и время отключения сервиса');
        }
    }

    /**
     * Returns the list of attribute names of the model.
     * @return array list of attribute names.
     */
    public function attributeNames()
    {
        return array_keys($this->attributeLabels());
    }
}