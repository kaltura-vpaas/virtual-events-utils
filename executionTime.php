<?php
class ExecutionTime
{
    //credit: https://stackoverflow.com/a/22885011
    private $startTime;
    private $endTime;

    private $time_start     =   0;
    private $time_end       =   0;
    private $time           =   0;

    public function start()
    {
        $this->startTime = getrusage();
        $this->time_start = microtime(true);
    }

    public function end()
    {
        $this->endTime = getrusage();
        $this->time_end = microtime(true);
    }

    public function totalRunTime()
    {
        $this->time = round($this->time_end - $this->time_start);
        $minutes = floor($this->time / 60); //only minutes
        $seconds = $this->time % 60; //remaining seconds, using modulo operator
        return "Total script execution time: minutes:$minutes, seconds:$seconds";
    }

    private function runTime($ru, $rus, $index)
    {
        return ($ru["ru_$index.tv_sec"] * 1000 + intval($ru["ru_$index.tv_usec"] / 1000))
            -  ($rus["ru_$index.tv_sec"] * 1000 + intval($rus["ru_$index.tv_usec"] / 1000));
    }

    public function __toString()
    {
        return $this->totalRunTime() . PHP_EOL . "This process used " . $this->runTime($this->endTime, $this->startTime, "utime") .
            " ms for its computations\nIt spent " . $this->runTime($this->endTime, $this->startTime, "stime") .
            " ms in system calls\n";
    }
}