<?php

namespace Scraper\Kernel\Database;

use Scraper\Kernel\Interfaces\Database as DatabaseInterface;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

class Database implements DatabaseInterface {
    const DEFAULT_LIVESCORE_QRY = "SELECT fs.`eventFK`, fs.`flashscore_link`, e.status_type
                                    FROM `event` e
                                    LEFT JOIN flashscore_source fs ON fs.`eventFK` = e.`id`
                                    WHERE e.`status_type` IN ('inprogress', 'delayed') AND fs.`flashscore_link` != ''
                                    UNION ALL
                                    SELECT fs.`eventFK`, fs.`flashscore_link`, e.status_type
                                    FROM `event` e
                                    LEFT JOIN flashscore_source fs ON fs.`eventFK` = e.`id`
                                    LEFT JOIN event_runtime er ON e.id = er.eventFK
                                    WHERE  fs.`flashscore_link` != ''
                                    AND e.status_type = 'finished'
                                    AND NOW() BETWEEN DATE_ADD(e.startdate, INTERVAL er.running_time MINUTE)
                                        AND DATE_ADD(e.startdate, INTERVAL (er.running_time+60) MINUTE)";
                                        
    public $db;

    /**
     * Set Manager::class
     */
    public function __construct() {
        $this->db = new Manager;
    }


    /**
     * Set config
     */
    public function add(array $config, $conection = 'default') {
        $this->db->addConnection( $config, $conection );
    }




    /**
     * Initiate eloquent ORM
     * @return $this
     */
    public function load() {
        $this->db->setEventDispatcher(new Dispatcher(new Container));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();

        return $this;
    }



    /**
     * $sql String
     * Used for raw SQL
     * @return Collection
     */
    public function query($sql = '') {
        $result = $this->db::select($this->db::raw($sql));
        return $result;
    }




    /**
     * @return instanceOf Illuminate\Database\Capsule\Manager::class
     */
    public function instance() {
        return $this->db;
    }


    public function table($table_name = '') {
        return $this->db::table($table_name);
    }
}