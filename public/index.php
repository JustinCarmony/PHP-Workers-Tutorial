<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '../vendor/autoload.php';



$app = new Silex\Application();
$app['debug'] = true;

$app->redis = new \Predis\Client('tcp://127.0.0.1:6379');
$app->beanstalk = new Pheanstalk_Pheanstalk('127.0.0.1');

// Twig
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => realpath(__DIR__.'/../views'),
));



$app->get('/', function() use($app) {
    return $app['twig']->render('index.twig', array());
});

$app->get('/workers', function () use ($app){
    $arr = $app->redis->hgetall('worker.status');

    ksort($arr);

    $return = array();

    foreach($arr as $k => $json_val)
    {
        $data = json_decode($json_val);
        $data->worker_hash = substr($data->worker_hash, 0, 12)."...";
        switch($data->status)
        {
            case 'working':
                $data->status_label = 'label-success';
                break;
            case 'offline':
                $data->status_label = 'label-important';
                break;
            case 'waiting':
                $data->status_label = 'label-info';
                break;
            default:
                $data->status_label = '';
                break;
        }

        $return[] = $data;
    }

    $obj = new stdClass();
    $obj->workers = $return;


    return json_encode($obj);
});

$app->post('/restart', function() use ($app){
    $app->redis->incr('worker.version');
    return 1;
});

$app->post('/add-words', function() use($app){
    $words = $_POST['words'];

    $words  = preg_replace("/(?![.=$'€%-])\p{P}/u", "", $words);

    $words = explode(' ', $words);
    foreach($words as $word)
    {
        $word = trim($word);
        $data = new stdClass();
        $data->type = 'lookup_word';
        $data->word = $word;
        $app->beanstalk->useTube('queue')->put(json_encode($data));

    }
    return '1';
});

$app->get('/words', function() use ($app){
    $list = $app->redis->lrange('word.defs', 0, -1);
    $words = array();
    foreach($list as $item_json)
    {
        $item = json_decode($item_json);
        $words[] = $item;
    }

    return json_encode($words);
});


$app->run();