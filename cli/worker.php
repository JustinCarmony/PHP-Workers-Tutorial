<?php

// Include Autoloader
require_once '../vendor/autoload.php';

require_once '../config.php';

class DemoWorker
{
    public $worker_id;
    public $worker_hash;
    public $worker_version;

    public $start_time;
    public $end_time;
    public $time_limit;
    public $run = true;

    /**
     * @var Pheanstalk_Job
     */
    public $job;
    public $job_details;

    /**
     * @var \Predis\Client
     */
    public $redis;

    /**
     * @var Pheanstalk_Pheanstalk
     */
    public $beanstalk;


    public function __construct($worker_id)
    {
        if(!is_numeric($worker_id) && $worker_id > 0)
        {
            throw new Exception("Invalid Worker ID: $worker_id");
        }

        $this->worker_id    = $worker_id;

        $this->Log("Constructing Worker [$worker_id] ... ");

        $this->worker_hash  = md5(rand(0,99999999999));     // Assign random MD5 Hash
        $this->start_time   = time();
        $this->time_limit   = 60 * 60 * 1;                  // Minimum of 1 hour
        $this->time_limit   += rand(0, 60 * 30);            // Adding additional time between 0 to 30 minutes
        $this->end_time = $this->start_time + $this->time_limit;

        $this->Log("Worker Hash: {$this->worker_hash}");
    }

    /**
     * Method for logging out to the console
     *
     * @param $txt
     */
    public function Log($txt)
    {
        echo "[".date("H:i:s")."] ".$txt."\n";
    }

    public function Setup()
    {
        // Set Timeout Limit to 0 so we won't get killed for running too long
        $this->Log("Timeout Set to 0");
        set_time_limit(0);

        // Setup Predis Connection
        $this->Log("Connecting to Redis Server... ");
        $this->redis = new \Predis\Client('tcp://127.0.0.1:6379');

        // Setup Beanstalkd connection
        $this->Log("Connecting to Beanstalkd Server... ");
        $this->beanstalk = new Pheanstalk_Pheanstalk('127.0.0.1');

        // Get the current worker version
        $this->worker_version = $this->redis->get('worker.version');

        $this->Log("Setup Complete");
    }

    public function Run()
    {
        $this->Log("Beginning to Run");
        try
        {
            while($this->run)
            {
                $job = $this->beanstalk
                    ->watch('system')
                    ->watch('queue')
                    ->ignore('default')
                    ->reserve(10);

                if($job)
                {
                    $this->ProcessJob($job);
                }
            }
        }
        catch (Exception $ex)
        {
            $this->Log("Exception Caught: ".$ex->getMessage());
            var_dump($ex->getTrace());
        }

        $this->Cleanup();
    }

    /**
     * @param $job Pheanstalk_Job
     */
    public function ProcessJob($job)
    {
        $this->job = $job;

        $this->job_details = $details = json_decode($this->job->getData());

        switch($details->type)
        {
            case 'restart':
                $this->Job_Restart();
                break;
            case 'lookup_word':
                $this->Job_Lookup_Word();
                break;
            default:
                $this->JobError();
                break;
        }
    }

    public function JobError()
    {
        $this->Log("Error! Uknown Job! Job ID: ".$this->job->getId());
        $this->Log("Job Data: ".$this->job->getData());
        $this->Log("Burying Job!");

        $this->beanstalk->bury($this->job);
    }

    public function Job_Restart()
    {
        $this->Log("Job Restart Recevied, running stopped.");
        $this->run = false;
        $this->beanstalk->delete($this->job);
    }

    public function Job_Lookup_Word()
    {
        $word = $this->job_details->word;

        $url = 'http://www.dictionaryapi.com/api/v1/references/collegiate/xml/'
                .urlencode($word).'?key='.DICTIONARY_API_KEY;

        $contents = @file_get_contents($url);

        $xml = new SimpleXMLElement($contents);
        $results = $xml->xpath('/entry_list/entry[1]//dt');
        $return = '';
        foreach($results as $node)
        {
            /* @var $node SimpleXMLElement */
            $string = $node->asXML();
            $string = str_replace('<dt>:', '', $string);
            $string = str_replace(':</dt>', '', $string);
            $def = trim(strip_tags($string));
            if(strlen($def) > strlen($return))
            {
                $return = $def;
            }
        }

        echo "$word: $return \n";

        $this->beanstalk->delete($this->job);

        usleep(2000000); // Wait 2 seconds so we don't overload the API
    }

    public function CheckStatus()
    {
        // Checking to see if we've been running too long
        if(time() < $this->end_time)
        {
            $this->Log("Worker has passed end time. Running Stopped.");
            $this->run = false;
        }

        // Checking to see if the worker version doesn't match what is in Redis
        $current_version = $this->redis->get('worker.version');
        if($this->worker_version != $current_version)
        {
            $this->Log("Worker Version has change from {$this->worker_version} to {$current_version}. Running Stopped.");
            $this->run = false;
        }
    }

    public function Cleanup()
    {

    }
}

$worker = new DemoWorker($argv[1]);

$worker->Setup();
$worker->Run();