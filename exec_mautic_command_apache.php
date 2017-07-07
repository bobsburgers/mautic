<?php

$link         = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$allowedTasks = [
    'cache:clear',
    'mautic:segments:update',
    'mautic:campaigns:update',
    'mautic:campaigns:trigger',
    'mautic:email:process',
    'mautic:fetch:email',
    'doctrine:migrations:migrate',
    'doctrine:schema:update --dump-sql',
    'doctrine:schema:update --force',
];
if (!isset($_GET['task'])) {
    echo 'Specify what task to run. You can run these:';
    foreach ($allowedTasks as $task) {
        $href = $link.'?task='.urlencode($task);
        echo '<br><a href="'.$href.'">'.$href.'</a>';
    }
    echo '<br><a href="https://www.mautic.org/docs/setup/index.html">Read more</a>';
    echo '<br><b style="color:red">Please, backup your database before executing the doctrine commands!</b>';
    die;
}
$task = urldecode($_GET['task']);

$fullCommand = explode(' ', $task);
$command     = $fullCommand[0];
$argsCount   = count($fullCommand) - 1;
$args        = ['console', $command];
if ($argsCount) {
    for ($i = 1; $i <= $argsCount; ++$i) {
        $args[] = $fullCommand[$i];
    }
}
echo '<h3>Executing '.implode(' ', $args).'</h3>';
$loader = require_once __DIR__.'/app/autoload.php';
require_once __DIR__.'/app/bootstrap.php.cache';
require_once __DIR__.'/app/AppKernel.php';
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

defined('IN_MAUTIC_CONSOLE') or define('IN_MAUTIC_CONSOLE', 1);
try {
    $input  = new ArgvInput($args);
    $output = new BufferedOutput();
    $kernel = new AppKernel('dev', false);
    $app    = new Application($kernel);
    $app->setAutoExit(false);
    $result = $app->run($input, $output);
    echo "<pre>\n".$output->fetch().'</pre>';
} catch (\Exception $exception) {
    echo $exception->getMessage();
}
