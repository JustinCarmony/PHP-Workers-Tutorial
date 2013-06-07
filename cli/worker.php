<?php

// Make sure our curent working directory is where the worker is being executed
chdir(__DIR__);

// Include Autoloader
require_once '../vendor/autoload.php';

// Include the Config to get the API Key
require_once '../config.php';

/**
 * Our DemoWorker class will handle all the functionality of our worker.
 * At the end of this file it will create an instance of this class
 * and run Setup() and Run()
 */
class DemoWorker
{
    // Simple Worker ID
    public $worker_id;

    // Unique, randomly generated Hash for each worker instance
    public $worker_hash;

    // The worker version supplied from Redis, if changed the worker will exit.
    public $worker_version;

    // When the worker starts
    public $start_time;

    // When the worker should end
    public $end_time;

    // How long of a time limit the worker will use
    public $time_limit;

    // As long as true, worker will continue to work. If false, the worker will
    // stop it's loop and run Cleanup() and exit.
    public $run = true;

    // A current status of the worker for reporting info.
    public $status = 'init';

    // Other Details for Reporting
    public $last_word;
    public $last_def;

    // Variables for Pheanstalk Jobs
    /**
     * @var Pheanstalk_Job
     */
    public $job;
    public $job_details;

    // Redis client
    /**
     * @var \Predis\Client
     */
    public $redis;

    // Beanstalk Client
    /**
     * @var Pheanstalk_Pheanstalk
     */
    public $beanstalk;


    // Construct our worker, only basic settings.
    public function __construct($worker_id)
    {
        if(!is_numeric($worker_id) || $worker_id <= 0)
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

    // Setup the worker's connections & data from Redis
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

    // Our main run loop
    public function Run()
    {
        $this->Log("Beginning to Run");

        // We want to catch any errors in case something goes really wrong and cleanly exit
        try
        {
            /************* MAGIC IS HERE **************
             * This is where the magic happens
             * The worker will loop forever while $this->run
             * is true.
             ******************************************/
            while($this->run)
            {
                // Report our current status
                $this->status = 'waiting';
                $this->Report();

                // Listen to a beanstalk tube
                $job = $this->beanstalk
                    // Watch the system queue for anything important
                    ->watch('system')
                    // Watch main queue for jobs
                    ->watch('queue')
                    // Ignore the "default" queue, jobs coming from
                    // here are a bug.
                    ->ignore('default')
                    // Listen up to 10 seconds for a job.
                    ->reserve(10);

                // When a job exists, process the job.
                if($job)
                {
                    $this->ProcessJob($job);
                }

                // Echo a "." so we know the worker isn't frozen in the logs.
                echo ".";

                // Check the status of the worker to see if we need to stop running.
                $this->CheckStatus();
            }

        }
        catch (Exception $ex)
        {
            $this->Log("Exception Caught: ".$ex->getMessage());
            var_dump($ex->getTrace());
        }

        // Cleanup any status & connections
        $this->Cleanup();

        // Stop the worker from working
        return;
    }

    /**
     * Process a job.
     *
     * Each individual job will run through here. We could use some fun reflection
     * to auto detect which function to use, but we'll be explicit for the sake
     * of the demo.
     *
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
        $this->status = 'working';


        $this->Report();

        try
        {
            $url = 'http://www.dictionaryapi.com/api/v1/references/collegiate/xml/'
                    .urlencode($word).'?key='.DICTIONARY_API_KEY;

            $contents = @file_get_contents($url);

            /*
             * I really don't know how to manipulate very complex XML easily in PHP,
             * so please ignore the hideous hacks that you are about to see.
             */
            $xml = new SimpleXMLElement($contents);
            $results = $xml->xpath('/entry_list/entry[1]//dt');
            $return = '';
            foreach($results as $node)
            {
                /* @var $node SimpleXMLElement */
                $string = $node->asXML();

                // Please ignore the developer behind the curtain
                $string = str_replace('<dt>:', '', $string);
                $string = str_replace(':</dt>', '', $string);
                $def = trim(strip_tags($string));

                // Return the longest definition
                if(strlen($def) > strlen($return))
                {
                    $return = $def;
                }
            }

        } catch(Exception $ex)
        {
            $def = "$word failed: ".$ex->getMessage();
        }

        // Okay, you can start paying attention again now.

        $json = json_encode(array(
            'word' => $word
            ,'def' => $def
        ));


        $this->last_word = $word;
        $this->last_def = $def;

        echo "\n $word: $return \n";

        $this->redis->lpush('word.defs', $json);
        $this->redis->ltrim('word.defs', 0, 20);

        $this->beanstalk->delete($this->job);

        // Wait 2 seconds so we don't overload the API
        usleep(2000000);
    }

    public function CheckStatus()
    {
        // Checking to see if we've been running too long
        if(time() > $this->end_time)
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

        $this->Report();
    }

    public function Report()
    {
        $json = json_encode(array(
           "worker_id" => $this->worker_id
            ,"worker_hash" => $this->worker_hash
            ,"worker_version" => $this->worker_version
            ,"time_limit" => $this->time_limit
            ,"end_time" => $this->end_time
            ,"last_word" => $this->last_word
            ,"last_def" => $this->last_def
            ,"status" => $this->status
        ));

        $this->redis->hset('worker.status', $this->worker_id, $json);
    }

    public function Cleanup()
    {
        $this->status = 'offline';
        $this->Report();

        $this->redis->disconnect();

        unset($this->beanstalk);
        unset($this->redis);
    }
}

$worker = new DemoWorker($argv[1]);

$worker->Setup();
$worker->Run();

