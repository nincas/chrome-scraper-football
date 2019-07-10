<?php

namespace Scraper\Kernel\Database;

use Scraper\Kernel\Interfaces\Database as DatabaseInterface;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

class Database implements DatabaseInterface {
    
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
}