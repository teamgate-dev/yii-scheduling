<?php

namespace Anonymous\Scheduling;

use Yii;
use Yiisoft\Mutex\Mutex;
use Yiisoft\Mutex\File\FileMutex;

/**
 * Class Schedule
 * @package Anonymous\Scheduling
 */
class Schedule extends \CComponent
{
    /**
     * All of the events on the schedule.
     *
     * @var Event[]
     */
    protected $_events = [];

    /**
     * The mutex implementation.
     *
     * @var Mutex
     */
    protected $_mutex;

    /**
     * @var string The name of cli script
     */
    public $cliScriptName = 'yiic';


    /**
     * Schedule constructor.
     * @throws
     */
    public function __construct()
    {
        $this->_mutex = Yii::app()->hasComponent('mutex')
            ? Yii::app()->getComponent('mutex')
            : new FileMutex('schedule', env('MUTEX_RUNTIME_PATH', Yii::app()->runtimePath));
    }

    /**
     * Add a new callback event to the schedule.
     *
     * @param string $callback
     * @param array $parameters
     * @return Event
     * @throws
     */
    public function call($callback, array $parameters = [])
    {
        $this->_events[] = $event = new CallbackEvent($this->_mutex, $callback, $parameters);
        return $event;
    }

    /**
     * Add a new cli command event to the schedule.
     *
     * @param string $command
     * @return Event
     */
    public function command($command)
    {
        return $this->exec(PHP_BINARY . ' ' . $this->cliScriptName . ' ' . $command);
    }

    /**
     * Add a new command event to the schedule.
     *
     * @param string $command
     * @return Event
     */
    public function exec($command)
    {
        $this->_events[] = $event = new Event($this->_mutex, $command);
        return $event;
    }

    /**
     * Get events
     *
     * @return Event[]
     */
    public function getEvents()
    {
        return $this->_events;
    }

    /**
     * Get all of the events on the schedule that are due.
     *
     * @param \CConsoleApplication $app
     * @return Event[]
     */
    public function dueEvents(\CConsoleApplication $app)
    {
        return array_filter($this->_events, function (Event $event) use ($app) {
            return $event->isDue($app);
        });
    }

    /**
     * Creates scheduled events based on a list of command => cron pairs, eg.
     * [
     *     'ls' => '* * * * *',
     * ]
     *
     * @param array $commands
     * @param array $afterRunHandler
     * @param bool $inForeground
     * @return void
     */
    public function fromCommandsAndCronsList(array $commands, array $afterRunHandler, bool $inForeground = true)
    {
        $timestamp = date('Ymd-Hi');
        $cnt = 0;

        foreach ($commands as $command => $cronDefinition) {
            $cnt++;

            if (empty($cronDefinition)) {
                continue;
            }

            $filename = preg_split('/\s/', $command, 2);
            $filename = preg_replace('/\//', '_', $filename[0]);

            $event = $this->command($command)
                ->sendOutputTo(\Yii::getPathOfAlias('application.runtime.schedule') . "/{$filename}_{$timestamp}_{$cnt}.out")
                ->processOutput(function ($text, $app) { fwrite(\STDOUT, $text); })
                ->cron($cronDefinition)
                ->setInForeground($inForeground);

            if (!empty($afterRunHandler)) {
                $event->attachEventHandler('onAfterRun', $afterRunHandler);
            }
        }
    }
}
