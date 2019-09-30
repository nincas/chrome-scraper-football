<?php

namespace Scraper\Build\Controller;

use Scraper\Kernel\Interfaces\Database;
use Scraper\Kernel\Crawler\Crawler;
use Scraper\Kernel\Interfaces\Controller;
use Scraper\Build\Pattern\Standings as StandingsPattern;

class Standings implements Controller {

    /* Vars */
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
        /**
        * For historical
        */
        $this->start_date = (!empty($param['start_date'])) ? $param['start_date'] : '';

        /**
         * Start standings
         */
        $this->standings();
    }



    /**
     * Livescore starting function for parsing/crawling
     */
    public  function standings() {
        $types = ['#table;overall', '#table;home', '#table;away'];
        
        $stages = $this->getStages();
        echo "> To Scrape: " . count($stages) . NL;
        /**
         * Loop return matches
         */
        foreach ($stages as $key => $stage) {
            /**
             * Loop types
             */
            $ids = [];
            $ids['tournament_template_id'] = $stage->tournament_template_id;
            $ids['tournament_id'] = $stage->tournament_id;
            $ids['tournament_stage_id'] = $stage->tournament_stage_id;
            foreach ($types as $key => $type) {

                $new_url = $stage->flashscore_link;
                $new_url = str_replace($types, '', $new_url);
                $new_url = $new_url . $type;

                $base_name = camel_case(preg_replace('/[0-9]+/', '', str_slug($type)));
                $file = FILE_PATH . $base_name . '.html';

                // Start Crawling
                $crawler = new Crawler($new_url);
                $crawler->standingsCrawl($file);
                // Start parsing, methods below: dynamically called base on type
                $this->glib_stats_box_table($file, $ids, str_replace('#table;', '', $type));
            }
        }
    }


    protected function getStages() {
        if ($this->event_ids) {
            $event_ids = $this->event_ids;
            $sql = "
            SELECT ts.`id` AS tournament_stage_id, ts.`newName` AS tournament_stage_name, sfs.`flashscore_link` AS flashscore_link, e.`tournament_template_id` AS tournament_template_id, e.`tournament_id` AS tournament_id, season.`name` AS season_name
            FROM `event` e
            JOIN tournament_stage ts ON e.`newTournament_stageFK` = ts.`id`
            JOIN tournament season ON e.`tournament_id` = season.`id`
            JOIN tournament_template tt ON e.`tournament_template_id` = tt.`id`
            JOIN standing_flashscore_source sfs ON ts.`id` = sfs.`tournament_stageFK`
            WHERE e.`id` IN ($event_ids) 
            AND sfs.`flashscore_link` != ''
            AND e.`del` = 'no'
            AND e.`status_type` != 'deleted'
            GROUP BY ts.`id`
            UNION
            SELECT ts.`id` AS tournament_stage_id, ts.`newName` AS tournament_stage_name, sfs.`flashscore_link` AS flashscore_link, e.`tournament_template_id` AS tournament_template_id, e.`tournament_id` AS tournament_id, season.`name` AS season_name
            FROM `event` e
            JOIN tournament_stage ts ON e.`tournament_stageFK` = ts.`id`
            JOIN tournament season ON e.`tournament_id` = season.`id`
            JOIN tournament_template tt ON e.`tournament_template_id` = tt.`id`
            JOIN standing_flashscore_source sfs ON ts.`id` = sfs.`tournament_stageFK`
            WHERE e.`id` IN ($event_ids) 
            AND sfs.`flashscore_link` != ''
            AND e.`del` = 'no'
            AND e.`status_type` != 'deleted'
            GROUP BY ts.`id`
            ";
        }else{
            if (!empty($this->start_date)) {
                $yesterday = date('Y-m-d H:i:s', strtotime($this->start_date));
            } else {
                $yesterday = date('Y-m-d H:i:s', strtotime('- 4 hours'));
            }
            
            $sql = "
            SELECT ts.`id` AS tournament_stage_id, ts.`newName` AS tournament_stage_name, sfs.`flashscore_link` AS flashscore_link, e.`tournament_template_id` AS tournament_template_id, e.`tournament_id` AS tournament_id, season.`name` AS season_name
            FROM `event` e
            JOIN tournament_stage ts ON e.`newTournament_stageFK` = ts.`id`
            JOIN tournament season ON e.`tournament_id` = season.`id`
            JOIN tournament_template tt ON e.`tournament_template_id` = tt.`id`
            JOIN standing_flashscore_source sfs ON ts.`id` = sfs.`tournament_stageFK`
            WHERE e.`startdate` >= '$yesterday' 
            AND sfs.`flashscore_link` != ''
            AND e.`status_type` IN ('finished', 'cancelled', 'postponed', 'awarded')
            AND e.`del` = 'no'
            AND e.`status_type` != 'deleted'
            GROUP BY ts.`id`
            UNION
            SELECT ts.`id` AS tournament_stage_id, ts.`newName` AS tournament_stage_name, sfs.`flashscore_link` AS flashscore_link, e.`tournament_template_id` AS tournament_template_id, e.`tournament_id` AS tournament_id, season.`name` AS season_name
            FROM `event` e
            JOIN tournament_stage ts ON e.`tournament_stageFK` = ts.`id`
            JOIN tournament season ON e.`tournament_id` = season.`id`
            JOIN tournament_template tt ON e.`tournament_template_id` = tt.`id`
            JOIN standing_flashscore_source sfs ON ts.`id` = sfs.`tournament_stageFK`
            WHERE e.`startdate` >= '$yesterday' 
            AND sfs.`flashscore_link` != ''
            AND e.`status_type` IN ('finished', 'cancelled', 'postponed', 'awarded')
            AND e.`del` = 'no'
            AND e.`status_type` != 'deleted'
            GROUP BY ts.`id`
            UNION
            SELECT ts.`id` AS tournament_stage_id, ts.`newName` AS tournament_stage_name, sfs.`flashscore_link` AS flashscore_link, tt.`id` AS tournament_template_id, season.`id` AS tournament_id, season.`name` AS season_name
            FROM standing_flashscore_source sfs
            JOIN tournament_stage ts ON sfs.`tournament_stageFK` = ts.`id`
            JOIN tournament season ON ts.`tournamentFK` = season.`id`
            JOIN tournament_template tt ON season.`tournament_templateFK` = tt.`id`
            WHERE sfs.`flashscore_link` != ''
            AND ts.`id` NOT IN (
                SELECT tournament_stageFK FROM standings
                GROUP BY tournament_stageFK
            )
            ";
        }

        $stages = $this->database->query($sql);
        return $stages;
    }



    /**
     * @func call match events parser 
     */
    protected function glib_stats_box_table($file, $ids, $type) {
        /**
         * Parse html file to data
         */
        $parser = new StandingsPattern($ids, $file, $this->database);
        /**
         * Get all events parsed from source
         */
        $standings = $parser->standings($type);

        /**
         * Start inserting data
         */
        if (count($standings['standings']) > 0 || count($standings['adjustments']) > 0) {
            /**
             * Update standings
             */
            $parser->updateStandings($standings);
            /**
             * Update adjustments
             */
            $parser->updateAdjustments($standings);
        }
    }
}