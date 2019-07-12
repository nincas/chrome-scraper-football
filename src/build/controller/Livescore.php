<?php

namespace Scraper\Build\Controller;

use Scraper\Kernel\Interfaces\Database;
use Scraper\Kernel\Crawler\Crawler;
use Scraper\Kernel\Interfaces\Controller;
use Scraper\Build\Pattern\LiveScore as Lvs;
use Scraper\Build\Pattern\Parser;

class Livescore implements Controller {

    
    private $database;
    private $event_ids;
    private $event_id;
    private $status;


    public function __construct(Database $database, $param = []) {
        $this->database = $database;
        $event_ids = $param;

        /**
         * Check if the param event_id exists on params
         */
        if (@array_key_exists('event_id', $param)) {
            $this->event_ids = $param['event_id'];
        }

        /**
         * Start livescore
         */
        $this->livescore();
    }



    /**
     * Livescore starting function for parsing/crawling
     */
    public  function livescore() {
        $types = [
            'match-summary',
            'match-statistics;0'
        ];

        
        $matches = $this->getMatches();
        echo "> To Scrape: " . count($matches) . NL;
        /**
         * Loop return matches
         */
        foreach ($matches as $key => $match) {
            /**
             * Pre-defined vars
             */
            $this->event_id = $match->eventFK;
            $this->status = $match->status_type;
            /**
             * Loop types matchsummary or statistics
             */
            foreach ($types as $key => $type) {
                $new_url = str_replace($types[($key == 0 ? 1 : 0)], $type, $match->flashscore_link);
                $base_name = camel_case(preg_replace('/[0-9]+/', '', str_slug($type)));
                $file = FILE_PATH . $base_name . '.html';

                // Start Crawling
                $crawler = new Crawler($new_url);
                $html = $crawler->crawl($file);

                // Start parsing, methods below: dynamically called base on type
                $this->{$base_name}(file_get_contents($file), $file);
            }
        }
    }


    protected function getMatches() {
        if ($this->event_ids) {
            // If there is parameter event_id
            $matches = $this->database->instance()
                                ->table('event as e')
                                ->select('fs.eventFK', 'fs.flashscore_link', 'e.status_type')
                                ->leftJoin('flashscore_source as fs', 'fs.eventFK', 'e.id')
                                ->whereIn('e.id', explode(",", $this->event_ids))
                                ->get();
        } else {
            $sql = $this->database::DEFAULT_LIVESCORE_QRY; // Get default livescore query
            $matches = $this->database->query($sql);
        }

        return $matches;
    }



    /**
     * @func call match events parser 
     */
    protected function matchSummary($html, $file) {
        /**
         * Parse html file to data
         */
        $parser = new Parser($this->event_id, $file, $this->database);
        /**
         * Get all events parsed from source
         */
        $events = $parser->match_events($this->status);
        
        /**
         * Update match results
         */
        $flashscore_score = $parser->flashscore_score();
        $livescore = new Lvs($this->event_id, $this->database);
        /**
         * Update scores from source
         */
        $livescore->updateMatchResult2($flashscore_score);

        /**
         * Start inserting data
         */
        if (count($events) > 0) {
            /**
             * Update football_livescore always
             */
            $livescore->updateLiveScore($events, 100);
            if ($this->status == 'finished') {
                /**
                 * Update postmatch if match is finished
                 */
                $livescore->updatePostMatch($events);
            }
        }
    }





    /**
     * @func call match statistics func
     */
    public function matchStatistics($html, $file) {
        /**
         * Parse html file to data
         */
        $parser = new Parser($this->event_id, $file, $this->database);
        $livescore = new Lvs($this->event_id, $this->database);
        /**
         * Get all parsed stats
         */
        $stats = $parser->match_stats();
        
        if (count($stats) > 0) {
            $array_sts = array();
            foreach ($stats as $ky => $vl) {
                if ($ky == "Corner Kicks") {
                    /**
                     * Update corner kicks
                     */
                    $livescore->updateCKicks($vl["home"]."-".$vl["away"]);
                } else {
                    $array_sts[$ky] = $vl["home"]."-".$vl["away"];
                }
            }
            /**
             * Update stats
             */
            $livescore->updateStats($array_sts);
        }
    }
}