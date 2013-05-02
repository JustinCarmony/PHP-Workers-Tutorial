<?php
/**
 * Created by JetBrains PhpStorm.
 * User: jcarmony
 * Date: 5/2/13
 * Time: 12:10 AM
 * To change this template use File | Settings | File Templates.
 */

require_once '../vendor/autoload.php';

$beanstalk = new Pheanstalk_Pheanstalk('127.0.0.1');

$words = $argv;
unset($words[0]);
foreach($words as $word)
{
    $data = new stdClass();
    $data->type = 'lookup_word';
    $data->word = $word;

    $beanstalk->useTube('queue')->put(json_encode($data));
    echo "Added $word \n";
}

echo "Done!\n";

