<?php
class YiiConsole extends Cronjob\RequestHandler\Console
{
    /**
     * YiiConsole constructor.
     *
     * @param ServiceBase_IDebugLogger $debugLogger
     */
    public function __construct(\ServiceBase_IDebugLogger $debugLogger)
    {
        // an: Инициализируем ядро Yii
        YiiBridge::init($debugLogger);

        parent::__construct($debugLogger);
    }

    protected function handleRequestTool()
    {
        try {
            return parent::handleRequestTool(); // TODO: Change the autogenerated stub
        } catch (app\modules\Wtflow\components\ExceptionJiraNotAvailable $e) {
            // an: С 6 до 8 часов jira бекапится, и потому недоступна. Ошибки в это время можно не сливать в sentry
            Yii::error("Jira not available at maintenance time, ignore error");

            return 0;
        }
    }
}
