<?php

class HardMigrationController extends Controller
{
    public $pageTitle = 'Тяжелые миграции';
    /**
     * @return array action filters
     */
    public function filters()
    {
        return array(
            'accessControl'
        );
    }

    /**
     * Specifies the access control rules.
     * This method is used by the 'accessControl' filter.
     * @return array access control rules
     */
    public function accessRules()
    {
        return array(
            array('allow', // allow authenticated user to perform 'create' and 'update' actions
                'users'=>array('@'),
            ),
            array('deny',  // deny all users
                'users'=>array('*'),
            ),
        );
    }

    public function actionIndex()
    {
        $model=new HardMigration('search');
        $model->unsetAttributes();  // clear any default values
        if(isset($_GET['HardMigration']))
            $model->attributes=$_GET['HardMigration'];

        $this->render('index',array(
            'model'=>$model,
        ));
    }

    public function actionStart($id)
    {
        $migration = $this->loadModel($id);

        if (!$migration->canBeStarted() && !$migration->canBeRestarted()) {
            throw new Exception("Invalid migration status");
        }

        $migration->migration_status = HardMigration::MIGRATION_STATUS_STARTED;
        $migration->save(false);

        (new RdsSystem\Factory(Yii::app()->debugLogger))->getMessagingRdsMsModel()->sendHardMigrationTask(new \RdsSystem\Message\HardMigrationTask(
           $migration->migration_name, $migration->releaseRequest->project->project_name, $migration->releaseRequest->project->project_current_version
        ));

        $this->redirect('/hardMigration/index');
    }

    public function actionRestart($id)
    {
        $this->actionStart($id);
    }

    public function actionPause($id)
    {
        $this->sendUnixSignalAndRedirect($id, 10, HardMigration::MIGRATION_STATUS_PAUSED);
    }

    public function actionResume($id)
    {
        $this->sendUnixSignalAndRedirect($id, 12, HardMigration::MIGRATION_STATUS_IN_PROGRESS);
    }

    public function actionStop($id)
    {
        $this->sendUnixSignalAndRedirect($id, 20, HardMigration::MIGRATION_STATUS_STOPPED);
    }

    private function sendUnixSignalAndRedirect($id, $signal, $newStatus = null)
    {
        $migration = $this->loadModel($id);

        (new RdsSystem\Factory(Yii::app()->debugLogger))->getMessagingRdsMsModel()->sendUnixSignal(new \RdsSystem\Message\UnixSignal(
            $migration->migration_pid, $signal
        ));

        $migration->migration_status = $newStatus;
        $migration->save(false);

        $this->redirect('/hardMigration/index');
    }

    public function actionLog($id)
    {
        $migration = $this->loadModel($id);

        $this->render('log', ['migration' => $migration]);
    }

    /**
     * @param $id
     * @return HardMigration
     */
    public function loadModel($id)
    {
        $model=HardMigration::model()->findByPk($id);
        if($model===null)
            throw new CHttpException(404,'The requested page does not exist.');
        return $model;
    }

    /**
     * Performs the AJAX validation.
     * @param HardMigration $model the model to be validated
     */
    protected function performAjaxValidation($model)
    {
        if(isset($_POST['ajax']) && $_POST['ajax']==='hard-migration-form')
        {
            echo CActiveForm::validate($model);
            Yii::app()->end();
        }
    }
}