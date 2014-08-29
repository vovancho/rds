<?php
class WebApplication extends CWebApplication
{
    /** @var \ServiceBase_IDebugLogger */
    public $debugLogger;

    public function end($status=0,$exit=true)
    {
        if ($exit) {
            CoreLight::getInstance()->getFatalWatcher()->stop();
        }
        return parent::end();
    }

    public function setRuntimePath($path)
    {
        //an: Создаем папку для временных файлов, если её ещё нету
        if((false !== $runtimePath=realpath($path)) && !is_dir($runtimePath)) {
            mkdir($runtimePath, 0777);
        }

        return parent::setRuntimePath($path);
    }
}
