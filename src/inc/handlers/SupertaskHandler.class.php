<?php

use DBA\FilePretask;
use DBA\FileTask;
use DBA\JoinFilter;
use DBA\Pretask;
use DBA\QueryFilter;
use DBA\Supertask;
use DBA\SupertaskPretask;
use DBA\SupertaskTask;
use DBA\Task;
use DBA\TaskFile;
use DBA\TaskTask;
use DBA\TaskWrapper;

class SupertaskHandler implements Handler {
  public function __construct($supertaskId = null) {
    //nothing
  }
  
  public function handle($action) {
    switch ($action) {
      case DSupertaskAction::DELETE_SUPERTASK:
        $this->delete();
        break;
      case DSupertaskAction::CREATE_SUPERTASK:
        $this->create();
        break;
      case DSupertaskAction::APPLY_SUPERTASK:
        $this->createTasks();
        break;
      case DSupertaskAction::IMPORT_SUPERTASK:
        $this->importSupertask();
        break;
      default:
        UI::addMessage(UI::ERROR, "Invalid action!");
        break;
    }
  }
  
  private function importSupertask() {
    /** @var $CONFIG DataSet */
    global $FACTORIES, $CONFIG;
    
    $name = htmlentities($_POST['name'], ENT_QUOTES, "UTF-8");
    $masks = $_POST['masks'];
    $isSmall = intval($_POST['isSmall']);
    if (strlen($name) == 0 || strlen($masks) == 0) {
      UI::addMessage(UI::ERROR, "Name or masks is empty!");
      return;
    }
    if ($isSmall != 0 && $isSmall != 1) {
      $isSmall = 0;
    }
    
    $masks = explode("\n", str_replace("\r\n", "\n", $masks));
    for ($i = 0; $i < sizeof($masks); $i++) {
      if (strlen($masks[$i]) == 0) {
        unset($masks[$i]);
        continue;
      }
      $mask = str_replace("\\,", "COMMA_PLACEHOLDER", $masks[$i]);
      $mask = str_replace("\\#", "HASH_PLACEHOLDER", $mask);
      if (strpos($mask, "#") !== false) {
        $mask = substr($mask, 0, strpos($mask, "#"));
      }
      $mask = explode(",", $mask);
      if (sizeof($mask) > 5) {
        unset($masks[$i]);
        continue;
      }
      $masks[$i] = $mask;
    }
    
    if (sizeof($masks) == 0) {
      UI::addMessage(UI::ERROR, "No valid mask lines! Supertask was not created.");
      return;
    }
    
    // create the preconf tasks
    $preTasks = array();
    $priority = sizeof($masks) + 1;
    foreach ($masks as $mask) {
      $pattern = $mask[sizeof($mask) - 1];
      $cmd = "";
      switch (sizeof($mask)) {
        case 5:
          $cmd = " -4 " . $mask[3] . $cmd;
        case 4:
          $cmd = " -3 " . $mask[2] . $cmd;
        case 3:
          $cmd = " -2 " . $mask[1] . $cmd;
        case 2:
          $cmd = " -1 " . $mask[0] . $cmd;
        case 1:
          $cmd .= " $pattern";
      }
      $cmd = str_replace("COMMA_PLACEHOLDER", "\\,", $cmd);
      $cmd = str_replace("HASH_PLACEHOLDER", "\\#", $cmd);
      $preTaskName = implode(",", $mask);
      $preTaskName = str_replace("COMMA_PLACEHOLDER", "\\,", $preTaskName);
      $preTaskName = str_replace("HASH_PLACEHOLDER", "\\#", $preTaskName);
      
      //TODO: make configurable if cpu only task etc.
      $pretask = new Pretask(0, $preTaskName, $CONFIG->getVal(DConfig::HASHLIST_ALIAS) . " -a 3 " . $cmd, $CONFIG->getVal(DConfig::CHUNK_DURATION), $CONFIG->getVal(DConfig::STATUS_TIMER), "", $isSmall, 0, 0, $priority, 1);
      $pretask = $FACTORIES::getPretaskFactory()->save($pretask);
      $preTasks[] = $pretask;
      $priority--;
    }
    
    $supertask = new Supertask(0, $name);
    $supertask = $FACTORIES::getSupertaskFactory()->save($supertask);
    foreach ($preTasks as $preTask) {
      $relation = new SupertaskPretask(0, $supertask->getId(), $preTask->getId());
      $FACTORIES::getSupertaskPretaskFactory()->save($relation);
    }
    header("Location: supertasks.php");
    die();
  }
  
  private function createTasks() {
    /** @var DataSet $CONFIG */
    global $FACTORIES, $CONFIG;
    
    $supertask = $FACTORIES::getSupertaskFactory()->get($_POST['supertask']);
    $hashlist = $FACTORIES::getHashlistFactory()->get($_POST['hashlist']);
    if ($supertask == null) {
      UI::printError("ERROR", "Invalid supertask ID!");
    }
    else if ($hashlist == null) {
      UI::printError("ERROR", "Invalid hashlist ID!");
    }
    
    //TODO: read binaryId and accessGroupId
    $crackerBinaryId = 1;
    $accessGroupId = 1;
    
    $FACTORIES::getAgentFactory()->getDB()->query("START TRANSACTION");
    
    $subTasks = array();
    $isCpuTask = 0;
    
    $qF = new QueryFilter(SupertaskPretask::SUPERTASK_ID, $supertask->getId(), "=", $FACTORIES::getSupertaskPretaskFactory());
    $jF = new JoinFilter($FACTORIES::getSupertaskPretaskFactory(), SupertaskPretask::PRETASK_ID, Pretask::PRETASK_ID);
    $joinedTasks = $FACTORIES::getPretaskFactory()->filter(array($FACTORIES::FILTER => $qF, $FACTORIES::JOIN => $jF));
    $tasks = $joinedTasks[$FACTORIES::getPretaskFactory()->getModelName()];
    $wrapperPriority = 0;
    foreach ($tasks as $pretask) {
      /** @var $pretask Pretask */
      if (strpos($pretask->getAttackCmd(), $CONFIG->getVal(DConfig::HASHLIST_ALIAS)) === false) {
        UI::addMessage(UI::WARN, "Task must contain the hashlist alias for cracking!");
        continue;
      }
      $task = new Task(0, $pretask->getTaskName(), $pretask->getAttackCmd(), $pretask->getChunkTime(), $pretask->getStatusTimer(), 0, 0, $pretask->getPriority(), $pretask->getColor(), $pretask->getIsSmall(), $pretask->getIsCpuTask(), $pretask->getUseNewBench(), 0, $crackerBinaryId, 0);
      if ($hashlist->getHexSalt() == 1 && strpos($task->getAttackCmd(), "--hex-salt") === false) {
        $task->setAttackCmd("--hex-salt " . $task->getAttackCmd());
      }
      if ($wrapperPriority == 0 || $wrapperPriority > $task->getPriority()) {
        $wrapperPriority = $task->getPriority();
      }
      $task = $FACTORIES::getTaskFactory()->save($task);
      if ($task->getIsCpuTask() == 1) {
        $isCpuTask = 1;
      }
      
      $qF = new QueryFilter(FilePretask::PRETASK_ID, $pretask->getId(), "=");
      $pretaskFiles = $FACTORIES::getFilePretaskFactory()->filter(array($FACTORIES::FILTER => $qF));
      $subTasks[] = $task;
      foreach ($pretaskFiles as $pretaskFile) {
        $fileTask = new FileTask(0, $pretaskFile->getFileId(), $task->getId());
        $FACTORIES::getFileTaskFactory()->save($fileTask);
      }
    }
    $wrapper = new TaskWrapper(0, $wrapperPriority, DTaskTypes::SUPERTASK, $hashlist->getId(), $accessGroupId, $supertask->getSupertaskName());
    $wrapper = $FACTORIES::getTaskWrapperFactory()->save($wrapper);
    foreach ($subTasks as $task) {
      $task->setIsCpuTask($isCpuTask); // we need to enforce that all tasks have either cpu task or not cpu task setting
      $task->setTaskWrapperId($wrapper->getId());
      $FACTORIES::getTaskFactory()->update($task);
    }
    
    $FACTORIES::getAgentFactory()->getDB()->query("COMMIT");
    UI::addMessage(UI::SUCCESS, "New supertask applied successfully!");
  }
  
  private function create() {
    global $FACTORIES;
    
    $name = htmlentities($_POST['name'], ENT_QUOTES, "UTF-8");
    $tasks = $_POST['task'];
    $FACTORIES::getAgentFactory()->getDB()->query("START TRANSACTION");
    $supertask = new Supertask(0, $name);
    $supertask = $FACTORIES::getSupertaskFactory()->save($supertask);
    foreach ($tasks as $t) {
      $pretask = $FACTORIES::getPretaskFactory()->get($t);
      if ($pretask === null) {
        continue;
      }
      $supertaskPretask = new SupertaskPretask(0, $supertask->getId(), $pretask->getId());
      $FACTORIES::getSupertaskPretaskFactory()->save($supertaskPretask);
    }
    $FACTORIES::getAgentFactory()->getDB()->query("COMMIT");
    UI::addMessage(UI::SUCCESS, "New supertask created successfully!");
  }
  
  private function delete() {
    global $FACTORIES;
    
    $supertask = $FACTORIES::getSupertaskFactory()->get($_POST['supertask']);
    if ($supertask == null) {
      UI::printError("ERROR", "Invalid supertask ID!");
    }
    $FACTORIES::getAgentFactory()->getDB()->query("START TRANSACTION");
    $qF = new QueryFilter(SupertaskPretask::SUPERTASK_ID, $supertask->getId(), "=", $FACTORIES::getSupertaskPretaskFactory());
    $jF = new JoinFilter($FACTORIES::getSupertaskPretaskFactory(), Pretask::PRETASK_ID, SupertaskPretask::PRETASK_ID);
    $joinedTasks = $FACTORIES::getPretaskFactory()->filter(array($FACTORIES::FILTER => $qF, $FACTORIES::JOIN => $jF));
    
    $FACTORIES::getSupertaskPretaskFactory()->massDeletion(array($FACTORIES::FILTER => $qF));
    
    for ($i = 0; $i < sizeof($joinedTasks[$FACTORIES::getPretaskFactory()->getModelName()]); $i++) {
      /** @var $task Pretask */
      $task = $joinedTasks[$FACTORIES::getPretaskFactory()->getModelName()][$i];
      if ($task->getIsMaskImport() == 1) {
        $FACTORIES::getPretaskFactory()->delete($task);
      }
    }
    
    $FACTORIES::getSupertaskFactory()->delete($supertask);
    $FACTORIES::getAgentFactory()->getDB()->query("COMMIT");
    UI::addMessage(UI::SUCCESS, "Supertask deleted successfully!");
  }
}