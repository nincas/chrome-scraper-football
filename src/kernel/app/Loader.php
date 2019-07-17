<?php


namespace Scraper\Kernel\App;

use Scraper\Kernel\Database\Database;
/**
 * Class Loader
 */
class Loader {

    private $db;
    private $manager;
    private $event_ids;

    public function __construct () {
        $this->db = new Database;
        $this->db->add(db_conf(), 'default');
        $this->db = $this->db->load();
        // Get Manager:class instance
        $this->manager = $this->db->instance(); 

    }


    /**
     * Start initializing.
     * All Parameter is related to class you created.
     * Ex: php scrape livescore - parameter livescore is created as a Class on the \Scraper\Components\Controller\Livescore:class
     */
    public function boot() {
        $params = params(PARAM_LIMIT);
        
        if (!isset($params[0])) error("No inputted parameter.", 0);
        $class = BASE_NS . studly_case($params[0]);
        /**
         * Check if class exists
         */
        if (!class_exists($class)) {
            error("Class '$class::class' does not exists", 2);
        }

        echo "> Scraping.." . PHP_EOL;
        /**
         * Call $class::class
         */
        (new $class($this->db, $params));
    }
}