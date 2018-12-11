<?php

namespace XCTools\Tasks;

use PEAR2\Exception;
use XCTools\Utils\Config;
use XCTools\Utils\Utility;
use XCTools\Utils\CSV;

trait CheckVersion{

    public function taskCheckVersion($base_dir, $mode){
        return new CheckVersionTask($base_dir, $mode);
    }
}

class CheckVersionTask implements \Robo\Contract\TaskInterface
{
    use \Robo\Common\TaskIO;
    use \Robo\Task\Base\loadTasks;
    use \Robo\Task\File\loadTasks;
    use \Robo\Task\FileSystem\loadTasks;

    const YOUTRACK_URL = 'https://xcn.myjetbrains.com/youtrack';
    const SRC_PATH = '../../src/';

    const ULTIMATE_EDITION = 'ultimate';
    const PERSONAL_DEMO = 'personal';

    protected $name_distr;

    protected $base_dir_path;
    protected $distrs;

    protected $csv_diff;
    protected $csv_checkpoint;
    protected $csv_result;

    public function __construct($base_dir, $mode)
    {
        $this->base_dir_path = $base_dir;
        $this->output_dir_path = $this->base_dir_path . "output";
        $this->ext_repo_path = $this->base_dir_path . "ext_repo";

        $this->csv_diff       = $csv_diff       = $this->ext_repo_path."/diff.csv";
        $this->csv_checkpoint = $csv_checkpoint = $this->ext_repo_path."/checkpoint.csv";
        $this->csv_result     = $csv_result     = $this->ext_repo_path."/resultmodul.csv";

        CSV::createFile($this->csv_diff);
        CSV::createFile($this->csv_checkpoint);
        CSV::createFile($this->csv_result);

        if ($mode == 'debug'){
            //Для отладки
            $this->distrs = [
                ["lng" => "en", "type" => "default",     "isPersonalDemo" => false, "local" => true, "name_distr" => "distr --lng=en --local"],
                ["lng" => "ru", "type" => "default",     "isPersonalDemo" => false, "local" => true, "name_distr" => "distr --lng=ru --local"],
            ];
        }else{
            $this->distrs = [
                ["lng" => "en", "type" => "default",     "isPersonalDemo" => false, "local" => false, "name_distr" => "distr --lng=en"],
                ["lng" => "ru", "type" => "default",     "isPersonalDemo" => false, "local" => false, "name_distr" => "distr --lng=ru"],
                ["lng" => "gb", "type" => "default",     "isPersonalDemo" => false, "local" => false, "name_distr" => "distr --lng=gb"],
                ["lng" => "zh", "type" => "default",     "isPersonalDemo" => false, "local" => false, "name_distr" => "distr --lng=zh"],
                ["lng" => "en", "type" => "default",     "isPersonalDemo" => true,  "local" => false, "name_distr" => "distr --lng=en --pd"],
                ["lng" => "en", "type" => "multivendor", "isPersonalDemo" => true,  "local" => false, "name_distr" => "distr --lng=en --type=multivendor --pd"],
                ["lng" => "en", "type" => "ultimate",    "isPersonalDemo" => true,  "local" => false, "name_distr" => "distr --lng=en --type=ultimate --pd"],
                ["lng" => "ru", "type" => "default",     "isPersonalDemo" => true,  "local" => false, "name_distr" => "distr --lng=ru --pd"],
                ["lng" => "ru", "type" => "multivendor", "isPersonalDemo" => true,  "local" => false, "name_distr" => "distr --lng=ru --type=multivendor --pd"],
                ["lng" => "ru", "type" => "ultimate"   , "isPersonalDemo" => true,  "local" => false, "name_distr" => "distr --lng=ru --type=ultimate --pd"],
            ];
        }
    }

    public function run()
    {
        $csv_diff = new CSV($this->csv_diff);

        $externalModulesAll = [];

        foreach ($this->distrs as $distr) {
            $packedModules = $this->packedModules($distr['lng'], $distr['type'], $distr['isPersonalDemo']);

            $externalModulesAll = array_merge($externalModulesAll,$this->getExternalModules($packedModules));
        }

        $externalModules = array_unique($externalModulesAll,$sort_flags = SORT_REGULAR);

        $modulesFiles = $this->getModulesList($externalModules);

        $csv_diff->setCSV($modulesFiles);

        CSV::csv_compare($this->csv_diff,$this->csv_checkpoint, $this->csv_result);

        if (!file_get_contents($this->csv_result)) {
            echo "Модули идентичны! The modules are identical";
        }elseif (!file_get_contents($this->csv_checkpoint)){

            $result = copy($this->csv_diff,$this->csv_checkpoint); //1. то надо сразу перезаписывать checkpoint.csv
            if ($result){
                echo "Файл $this->csv_checkpoint пустой, поэтому Файл $this->csv_diff скопирован  в $this->csv_checkpoint";
            }else{
                echo "EROOR Файл $this->csv_diff НЕ был скопирован в $this->csv_checkpoint";
            }

        }else {
            echo "Модули Не идентичны! The modules are not identical. Формируем Дистр";

            //$this->generateDistr();

            $result = copy($this->csv_diff,$this->csv_checkpoint); //1. то надо сразу перезаписывать checkpoint.csv
            if ($result){
                echo "Файл $this->csv_diff скопирован  в $this->csv_checkpoint";
            }else{
                echo "EROOR Файл $this->csv_diff НЕ был скопирован в $this->csv_checkpoint";
            }

            $this->createIssue();
        }
    }

    protected function generateDistr(){

        foreach ($this->distrs as $val){
            chdir($this->base_dir_path . ".dev/build");
            $this->taskExec('./vendor/bin/robo')
                ->args(array($val["name_distr"]))
                ->run();
        }
    }

    protected function getExternalModules($packed_modules)
    {
        $externalModules = array();
        $internalModules = Config::getInstance()->getAllModules($this->base_dir_path . "/src/classes/XLite/Module/");

        foreach ($packed_modules as $module) {
            list($author, $name) = explode("/", $module);
            if (!in_array($module, $internalModules)) {
                $externalModules[] = array('author' => $author, 'name' => $name);
            }
        }

        return $externalModules;
    }

    public function getModulesList($modules)
    {
        $modulesInfo = Utility::getModulesInfoFromMarketplace($modules);
        $files = array();
        foreach($modulesInfo as $module) {
            $files[] = $this->getFullModuleName($module);
        }
        return $files;
    }

    public function getFullModuleName($module)
    {
        return $module['author'] . "-" . $module['name'] . "-" . $module['version']['major'] . "." . $module['version']['minor']. ((isset($module['version']['build']) && $module['version']['build'] > 0) ? "." . $module['version']['build'] : "") . ".tgz";

    }

    protected function packedModules($lng, $type, $pd)
    {
        return $pd
            ? array_merge(
                Config::getInstance()->getDistrAllModules($this::ULTIMATE_EDITION, $lng),
                Config::getInstance()->getDistrAllModules($this::PERSONAL_DEMO, $lng))
            : Config::getInstance()->getDistrAllModules($type, $lng);
    }

    protected function login()
    {
        try {
            $this->ytClient = new \YouTrack\Connection(
                $this::YOUTRACK_URL,
                Config::getInstance()->getYoutrackKey(),
                null
            );
        } catch (\YouTrack\IncorrectLoginException $e) {
            $this->printTaskError('Unable to login to YouTrack. Check key in build_config file.');
            return \Robo\Result::error($this, "Failed to create changelog");
        }
    }

    protected function createIssue()
    {
        $this->login();

        $project = "BUG";
        $assignee = "Ruslan Iskhakov";
        $summary = "Обновление дистров";

        $changedModules = file_get_contents($this->csv_result);
        $description = <<<DESC
*Changed module(s):*
$changedModules
DESC;

        $type = "Task";
        $subsystem = "x-cart";
        $params = array(
            'project' => $project,
            'assignee' => $assignee,
            'summary' => $summary,
            'type' => $type,
            'description' => $description,
            'subsystem' => $subsystem,
        );

        //отправить нотификацию russoz/skiv (или создать тикет в youtrack на заливку дистров)
        $issue = $this->ytClient->createIssue($project, $summary, $params);
        $this->ytClient->executeCommand($issue->getId(), "Assignee russoz");
    }
}
