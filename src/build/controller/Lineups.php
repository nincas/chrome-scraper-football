<?php

namespace Scraper\Build\Controller;

use Scraper\Kernel\Interfaces\Database;
use Scraper\Kernel\Crawler\Crawler;
use Scraper\Kernel\Interfaces\Controller;
use Scraper\Build\Pattern\Lineups as LineupsPattern;

class Lineups implements Controller {

    
    private $database;
    private $event_ids;
    private $event_id;
    private $status;
    private $start_date;


    public function __construct(Database $database, $param = []) {
        $this->database = $database;
        /**
         * Check if the param event_id exists on params
         */
        if (@array_key_exists('event_id', $param)) {
            $this->event_ids = $param['event_id'];
        }
        $this->start_date = (!empty($param['start_date'])) ? $param['start_date'] : '';

        /**
         * Start standings
         */
        $this->lineups();
    }



    /**
     * Livescore starting function for parsing/crawling
     */
    public  function lineups() {
        $types = [
            '#match-summary',
            '#match-statistics;0',
            '#lineups;1'
        ];
        $type = '#lineups;1';
        
        $matches = $this->getMatches();
        echo "> To Scrape: " . count($matches) . NL;
        /**
         * Loop return matches
         */
        foreach ($matches as $key => $match) {
            /**
             * Pre-defined vars
             */

            /**
             * Loop types
             */
            $ids = array();
            $ids['event_id'] = $match->event_id;
            $ids['home_team_id'] = $match->home_team_id;
            $ids['away_team_id'] = $match->away_team_id;
            $new_url = $match->flashscore_link;
            $new_url = str_replace($types, '', $new_url);
            $new_url = $new_url . $type;
            $base_name = camel_case(preg_replace('/[0-9]+/', '', str_slug($type)));
            $file = FILE_PATH . $base_name . '.html';

            // Start Crawling
            $crawler = new Crawler($new_url);
            $crawler->standingsCrawl($file);
            // Start parsing, methods below: dynamically called base on type
            $this->lineups_content($file, $ids);
        }
    }


    protected function getMatches() {
        if ($this->event_ids) {
            $event_ids = $this->event_ids;
            $sql = "
            SELECT 
            e.`id` AS event_id,
            home_ep.`participantFK` AS home_team_id,
            away_ep.`participantFK` AS away_team_id,
            fs.`flashscore_link` AS flashscore_link
            FROM `event` e
            JOIN flashscore_source fs ON e.`id` = fs.`eventFK`
            JOIN event_participants home_ep ON e.`id` = home_ep.`eventFK` AND home_ep.`del` = 'no' AND home_ep.`number` = 1
            JOIN event_participants away_ep ON e.`id` = away_ep.`eventFK` AND away_ep.`del` = 'no' AND away_ep.`number` = 2
            WHERE fs.`flashscore_link` != ''
            AND fs.`flashscore_link` LIKE '%flashscore.com%'
            AND e.`del` = 'no'
            AND e.`status_type` NOT IN ('deleted', 'notstarted', 'inprogress')
            AND e.`id` IN ($event_ids)
            GROUP BY e.`id`
            ORDER BY e.`startdate`
            ";
        }else{
            if(!empty($this->start_date)){
                $yesterday = date('Y-m-d H:i:s', strtotime($this->start_date));
            }else{
                $yesterday = date('Y-m-d H:i:s', strtotime('- 4 hours'));
            }
            
            $sql = "
            SELECT 
            e.`id` AS event_id,
            home_ep.`participantFK` AS home_team_id,
            away_ep.`participantFK` AS away_team_id,
            fs.`flashscore_link` AS flashscore_link
            FROM `event` e
            JOIN flashscore_source fs ON e.`id` = fs.`eventFK`
            JOIN event_participants home_ep ON e.`id` = home_ep.`eventFK` AND home_ep.`del` = 'no' AND home_ep.`number` = 1
            JOIN event_participants away_ep ON e.`id` = away_ep.`eventFK` AND away_ep.`del` = 'no' AND away_ep.`number` = 2
            WHERE fs.`flashscore_link` != ''
            AND fs.`flashscore_link` LIKE '%flashscore.com%'
            AND e.`del` = 'no'
            AND e.`status_type` NOT IN ('deleted', 'notstarted', 'inprogress')
            GROUP BY e.`id`
            ORDER BY e.`start_date`
            ";
        }
        $matches = $this->database->query($sql);
        return $matches;
    }



    /**
     * @func call match events parser 
     */
    protected function lineups_content($file, $ids) {
        /**
         * Parse html file to data
         */
        $parser = new LineupsPattern($ids, $file, $this->database);
        /**
         * Get all events parsed from source
         */
        $lineups = $parser->lineups();
        // dump($lineups);

        /**
         * Start inserting data
         */
        // if (count($standings) > 0) {
        //     /**
        //      * Update standings
        //      */
        //     $parser->updateStandings($standings);
        //     /**
        //      * Update adjustments
        //      */
        //     $parser->updateAdjustments($standings);
        // }
    }
}