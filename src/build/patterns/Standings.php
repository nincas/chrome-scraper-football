<?php
/**
 * Pattern Standings
 */
namespace Scraper\Build\Pattern;

use \DOMDocument;
use \DomXPath;

use Scraper\Kernel\Interfaces\Database as DatabaseInterface;


/** 
* @class Parser
* Parsing of the HTML to data
*/
class Standings {
    
    public $event_id = 0;
    public $away_str11_list = array();
    public $home_str11_list = array();
    public $events_ls = array();
    public $my_file = "";
    public $time_holder=0;
    private $database;
    public $ids = array();
    
    public function __construct($ids, $fle, $db)
    {
        $this->ids = $ids;
        $this->my_file = $fle;
        $this->database = $db;
    }

    public function standings($type){
        $database = $this->database;
        $data = file_get_contents( $this->my_file );
        $return = array(
            'standings' => array(),
            'adjustments' => array()
        );

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($data);
        $dom->encoding = 'UTF-8';
        $finder = new DomXPath($dom);
        $div = $finder->query("//*[contains(@class, 'glib-stats-box-table-" . $type . "')]");
        $standing_col = array(
            'name' => 1,
            'rank' => 0,
            'matchPlayed' => 2,
            'won' => 3,
            'lose' => 5,
            'draw' => 4,
            'goals' => 6,
            'points' => 7
        );
        $homeAway = array(3, 1, 2);
        for ($i = 0; $i < $div->length; $i++) {
            $inner_divs = $div->item($i)->getElementsByTagName('div');
            foreach ($inner_divs as $inner_div) {
                $inner_div_class = $inner_div->getAttribute('class');
                if ($inner_div_class == 'stats-table-container') {
                    $theads = $inner_div->getElementsByTagName('thead');
                    $tbodys = $inner_div->getElementsByTagName('tbody');
                    for ($j = 0; $j < $theads->length; $j++) {
                        $row = array();
                        $thead_trs = $theads->item($j)->getElementsByTagName('tr');
                        for ($jj = 0; $jj < $thead_trs->length; $jj++){
                            $thead_tr = $thead_trs->item($jj);
                            $thead_tr_class = $thead_tr->getAttribute('class');
                            if($thead_tr_class == 'main'){
                                $col_name = $thead_tr->getElementsByTagName('th')->item(1);
                                $col_name_class = explode(' ', $col_name->getAttribute('class'));
                                $groupFK = 0;
                                if(in_array('col_name', $col_name_class)){
                                    $col_name_txt = $col_name->textContent;
                                    if($col_name_txt == 'Team'){
                                        $group_name = 'N/A';
                                    }else{
                                        if(strpos($col_name_txt, 'Group') !== false){
                                            $group_name = trim(str_replace('Group', '', $col_name_txt));
                                        }else{
                                            $group_name = trim($col_name_txt);
                                        }
                                    }
                                    $res_group = $database->query("SELECT * FROM groups WHERE tournament_templateFK = " . $this->ids['tournament_template_id'] . " AND tournamentFK = " . $this->ids['tournament_id'] . " AND tournament_stageFK = " . $this->ids['tournament_stage_id'] . " AND name = '" . $group_name . "'");
                                    if (count($res_group) == 1) {
                                        foreach($res_group as $res_group_row){
                                            $groupFK = $res_group_row->id;
                                        }
                                    }
                                }
                            }
                        }

                        if(!empty($col_name_txt)){
                            $tbody_trs = $tbodys->item($j)->getElementsByTagName('tr');
                            for($k = 0; $k < $tbody_trs->length; $k++){
                                $tbody_tr_tds = $tbody_trs->item($k)->getElementsByTagName('td');
                                $row['tournament_templateFK'] = $this->ids['tournament_template_id'];
                                $row['tournamentFK'] = $this->ids['tournament_id'];
                                $row['tournament_stageFK'] = $this->ids['tournament_stage_id'];
                                $row['groupFK'] = $groupFK;
                                foreach($standing_col as $standing_key => $l){
                                    if($standing_key == 'rank'){
                                        $row[$standing_key] = trim($tbody_tr_tds->item($l)->textContent, '.');
                                    }else if($standing_key == 'name'){
                                        $anchor_tag = $tbody_tr_tds->item($l)->getElementsByTagName('a')->item(0);
                                        $anchor_onclick = $anchor_tag->getAttribute('onclick');
                                        $substr_start = strpos($anchor_onclick, '/team/');
                                        $anchor_onclick = substr($anchor_onclick, $substr_start + 6);
                                        $explode_onclick = explode('/', $anchor_onclick);
                                        $team_name = $anchor_tag->textContent;
                                        $team_params = array(
                                            'name' => $team_name,
                                            'url' => $explode_onclick[0],
                                            'id' => $explode_onclick[1]
                                        );
                                        $teamFinder = $this->teamFinder($team_params);
                                        $this->flashscore_alias[$teamFinder['flashscore_alias_id']] = $team_name;
                                        $this->flashscore_params[$teamFinder['flashscore_alias_id']] = $team_params;
                                        $row['teamFK'] = $teamFinder['participant_id'];
                                        $row['flashscore_alias_id'] = $teamFinder['flashscore_alias_id'];
                                    }else if($standing_key == 'goals'){
                                        $goals = explode(':', $tbody_tr_tds->item($l)->textContent);
                                        
                                        $goalsFor = $goals[0];
                                        $goalsAgainst = $goals[1];
                                        $goalDifference = $goals[0] - $goals[1];
                                        $row['goalFor'] = $goalsFor;
                                        $row['goalAgainst'] = $goalsAgainst;
                                        $row['goalDifference'] = $goalDifference;
                                    } else {
                                        $row[$standing_key] = $tbody_tr_tds->item($l)->textContent;
                                    }
                                }
                                $row['homeAway'] = $homeAway[$i];
                                $return['standings'][] = $row;
                            }
                        }
                    }
                }else if($inner_div_class == 'cms table-incidents'){
                    $lis = $inner_div->getElementsByTagName('li');
                    for ($j = 0; $j < $lis->length; $j++) {
                        $li_content = $lis->item($j)->textContent;
                        $explode_team_name = explode(':', $lis->item($j)->textContent);
                        $row = array();
                        $row['tournament_templateFK'] = $this->ids['tournament_template_id'];
                        $row['tournamentFK'] = $this->ids['tournament_id'];
                        $row['tournament_stageFK'] = $this->ids['tournament_stage_id'];
                        $team_name = $explode_team_name[0];
                        $flashscore_alias_id = array_search($team_name, $this->flashscore_alias);
                        $team_params = $this->flashscore_params[$flashscore_alias_id];
                        $teamFinder = $this->teamFinder($team_params);
                        $row['teamFK'] = $teamFinder['participant_id'];
                        $row['flashscore_alias_id'] = $teamFinder['flashscore_alias_id'];
                        // $row['team_name'] = $teamFinder['participant_name'];
                        $li_content = str_replace($team_name, '', $li_content);
                        $li_content = trim($li_content, ': ');
                        $explode_description = explode('(', $li_content);
                        if(strpos($explode_description[0], '+') !== false){
                            $row['adjustment_type'] = 'addition';
                        }
                        if(strpos($explode_description[0], '-') !== false){
                            $row['adjustment_type'] = 'deduction';
                        }
                        $row['points'] = trim($explode_description[0], '- + points point');
                        $row['description'] = trim($explode_description[1], '()');
                        $return['adjustments'][] = $row;
                    }
                }
            }
        }
        
        return $return;
    }

    public function teamFinder($params){
        $database = $this->database;

        $result = 0;
        $alias_result = 0;
        $result_name = '';

        if(!empty($params['url']) && !empty($params['id']) && !empty($params['name'])){

            $res = $database->query(
                "SELECT * FROM flashscore_alias " .
                "WHERE fs_alias = '" . $params['url'] . "' " .
                "AND participant_type = 'team' " .
                "AND fs_id = '" . $params['id'] . "'"
            );

            if(count($res) == 1){
                $flashscore_alias_exists = $res[0];
            }

            if(!empty($flashscore_alias_exists) && $flashscore_alias_exists->participantFK > 0){
                $result = $flashscore_alias_exists->participantFK;
                $result_name = $flashscore_alias_exists->fs_alias;
            }

            if(empty($result)){

                $sql = "
                SELECT
                    p.`id`, p.`name`
                FROM object_participants op
                JOIN participant p ON op.`participantFK` = p.`id`
                WHERE op.`participant_type` IN ( 'team', 'national club')
                AND op.`active` = 'yes'
                AND op.`object` = 'tournament_stage'
                AND op.`objectFK` = " . $this->ids['tournament_stage_id'] . 
                " AND p.`name` LIKE \"%" . $params['name'] . "%\""
                ;

                $res = $database->query($sql);

                if(count($res) == 1){
                    $result = $res[0]->id;
                    $result_name = $res[0]->name;
                }
            }

            if(empty($result)){
                $res = $database->query("
                SELECT id, name
                FROM participant
                WHERE type = 'team'
                AND del = 'no'
                AND name LIKE \"%" . $params['name'] . "%\"
                ");

                if(count($res) == 1){
                    $result = $res[0]->id;
                    $result_name = $res[0]->name;
                }
            }

            if (!empty($flashscore_alias_exists)) {
              $alias_result = $flashscore_alias_exists->id;
              if(!empty($result) && $flashscore_alias_exists->participantFK == 0){
                $database->query("UPDATE flashscore_alias SET participantFK = $result WHERE id = $alias_result");
              }
            }else{

                $alias_result = $database->instance()->table('flashscore_alias')->insertGetId([
                    'participantFK' => $result,
                    'fs_alias' => $params['url'],
                    'participant_type' => 'team',
                    'fs_id' => $params['id']
                ]);
            }
        }

        return array(
            'participant_id' => $result,
            'participant_name' => $result_name,
            'flashscore_alias_id' => $alias_result
        );
    }

    public function updateStandings($data){
        $database = $this->database;
        $standings_ids = array();
        foreach($data['standings'] as $standing){
            if(!empty($standing['groupFK'])){

                $existing_standings = array();

                if(!empty($standing['teamFK'])){
                    $res = $database->query(
                        "SELECT *
                        FROM standings 
                        WHERE tournament_templateFK = " . $this->ids['tournament_template_id'] . 
                        " AND tournamentFK = " . $this->ids['tournament_id'] . 
                        " AND tournament_stageFK = " . $this->ids['tournament_stage_id'] . 
                        " AND groupFK = " . $standing['groupFK'] .
                        " AND teamFK = " . $standing['teamFK'] .
                        " AND homeAway = " . $standing['homeAway']
                    );
                }else{
                    $res = $database->query(
                        "SELECT standings.*
                        FROM standings 
                        JOIN flashscore_alias_auto_tag ON flashscore_alias_auto_tag.`relation_id` = standings.`id`
                        WHERE standings.`tournament_templateFK` = " . $this->ids['tournament_template_id'] . 
                        " AND standings.`tournamentFK` = " . $this->ids['tournament_id'] . 
                        " AND standings.`tournament_stageFK` = " . $this->ids['tournament_stage_id'] . 
                        " AND standings.`groupFK` = " . $standing['groupFK'] .
                        " AND standings.`teamFK` = " . $standing['teamFK'] .
                        " AND standings.`homeAway` = " . $standing['homeAway'] .
                        " AND flashscore_alias_auto_tag.`fsRef_id` = " . $standing['flashscore_alias_id'] .
                        " AND flashscore_alias_auto_tag.`table_name` = 'standings'" .
                        " AND flashscore_alias_auto_tag.`column_name` = 'teamFK'"
                    );
                }
                // if ($database->num_rows($res) > 0) {
                //     while($rw = $database->fetch_assoc($res)){    
                //         $existing_standings[] = $rw['id'];
                //     }
                // }

                if(count($res) > 0){
                    foreach($res as $rw){
                        $existing_standings[] = $rw->id;
                    }
                }

                if(!empty($existing_standings)){
                    if(count($existing_standings) > 1){
                        $database->query("DELETE FROM standings WHERE id IN (" . implode(', ', $existing_standings) . ")");

                        // $sql = "INSERT INTO standings
                        // (
                        //  tournament_templateFK, 
                        //     tournamentFK, 
                        //     tournament_stageFK, 
                        //     groupFK, 
                        //     teamFK, 
                        //     rank, 
                        //     matchPlayed, 
                        //     won, 
                        //     lose, 
                        //     draw, 
                        //     goalFor, 
                        //     goalAgainst, 
                        //     goalDifference, 
                        //     points, 
                        //     homeAway,
                        //     lastUpdated
                        // )
                        // VALUES
                        // (" .
                        //  $standing['tournament_templateFK'] . ", " .
                        //  $standing['tournamentFK'] . ", " .
                        //  $standing['tournament_stageFK'] . ", " .
                        //  $standing['groupFK'] . ", " .
                        //  $standing['teamFK'] . ", " .
                        //  $standing['rank'] . ", " .
                        //  $standing['matchPlayed'] . ", " .
                        //  $standing['won'] . ", " .
                        //  $standing['lose'] . ", " .
                        //  $standing['draw'] . ", " .
                        //  $standing['goalFor'] . ", " .
                        //  $standing['goalAgainst'] . ", " .
                        //  $standing['goalDifference'] . ", " .
                        //  $standing['points'] . ", " .
                        //  $standing['homeAway'] . ", \"" .
                        //  date('Y-m-d H:i:s') . "\"
                        // )
                        // ";

                        // $database->query($sql);

                        // $standings_id = $database->insert_id();

                        $standings_id = $database->instance()->table('standings')->insertGetId([
                            'tournament_templateFK' => $standing['tournament_templateFK'], 
                            'tournamentFK' => $standing['tournamentFK'], 
                            'tournament_stageFK' => $standing['tournament_stageFK'], 
                            'groupFK' => $standing['groupFK'], 
                            'teamFK' => $standing['teamFK'], 
                            'rank' => $standing['rank'], 
                            'matchPlayed' => $standing['matchPlayed'], 
                            'won' => $standing['won'], 
                            'lose' => $standing['lose'], 
                            'draw' => $standing['draw'], 
                            'goalFor' => $standing['goalFor'], 
                            'goalAgainst' => $standing['goalAgainst'], 
                            'goalDifference' => $standing['goalDifference'], 
                            'points' => $standing['points'], 
                            'homeAway' => $standing['homeAway'],
                            'lastUpdated' => date('Y-m-d H:i:s')
                        ]);
                        $standings_ids[] = $standings_id;

                        if(empty($standing['teamFK'])){
                            $sql = "INSERT INTO flashscore_alias_auto_tag
                            (
                                table_name,
                                column_name,
                                relation_id,
                                fsRef_id,
                                lastUpdated
                            )
                            VALUES
                            (" .
                                "\"standings\", " .
                                "\"teamFK\", " .
                                $standings_id . ", " .
                                $standing['flashscore_alias_id'] . ", " .
                                "\"" . date('Y-m-d H:i:s') . "\"
                            )
                            ";
                            $database->query($sql);
                        }
                    }else{
                        $sql = "UPDATE standings SET
                        rank = " . $standing['rank'] . ", 
                        matchPlayed = " . $standing['matchPlayed'] . ", 
                        won = " . $standing['won'] . ", 
                        lose = " . $standing['lose'] . ", 
                        draw = " . $standing['draw'] . ", 
                        goalFor = " . $standing['goalFor'] . ", 
                        goalAgainst = " . $standing['goalAgainst'] . ", 
                        goalDifference = " . $standing['goalDifference'] . ", 
                        points = " . $standing['points'] . ", 
                        lastUpdated = \"" . date('Y-m-d H:i:s') . "\"" . 
                        " WHERE id = " . $existing_standings[0];

                        $database->query($sql);

                        $standings_ids[] = $existing_standings[0];
                    }
                }else{
                    // $sql = "INSERT INTO standings
                    // (
                    //  tournament_templateFK, 
                    //     tournamentFK, 
                    //     tournament_stageFK, 
                    //     groupFK, 
                    //     teamFK, 
                    //     rank, 
                    //     matchPlayed, 
                    //     won, 
                    //     lose, 
                    //     draw, 
                    //     goalFor, 
                    //     goalAgainst, 
                    //     goalDifference, 
                    //     points, 
                    //     homeAway,
                    //     lastUpdated
                    // )
                    // VALUES
                    // (" .
                    //  $standing['tournament_templateFK'] . ", " .
                    //  $standing['tournamentFK'] . ", " .
                    //  $standing['tournament_stageFK'] . ", " .
                    //  $standing['groupFK'] . ", " .
                    //  $standing['teamFK'] . ", " .
                    //  $standing['rank'] . ", " .
                    //  $standing['matchPlayed'] . ", " .
                    //  $standing['won'] . ", " .
                    //  $standing['lose'] . ", " .
                    //  $standing['draw'] . ", " .
                    //  $standing['goalFor'] . ", " .
                    //  $standing['goalAgainst'] . ", " .
                    //  $standing['goalDifference'] . ", " .
                    //  $standing['points'] . ", " .
                    //  $standing['homeAway'] . ", \"" .
                    //  date('Y-m-d H:i:s') . "\"
                    // )
                    // ";

                    // $database->query($sql);

                    // $standings_id = $database->insert_id();
                    $standings_id = $database->instance()->table('standings')->insertGetId([
                        'tournament_templateFK' => $standing['tournament_templateFK'], 
                        'tournamentFK' => $standing['tournamentFK'], 
                        'tournament_stageFK' => $standing['tournament_stageFK'], 
                        'groupFK' => $standing['groupFK'], 
                        'teamFK' => $standing['teamFK'], 
                        'rank' => $standing['rank'], 
                        'matchPlayed' => $standing['matchPlayed'], 
                        'won' => $standing['won'], 
                        'lose' => $standing['lose'], 
                        'draw' => $standing['draw'], 
                        'goalFor' => $standing['goalFor'], 
                        'goalAgainst' => $standing['goalAgainst'], 
                        'goalDifference' => $standing['goalDifference'], 
                        'points' => $standing['points'], 
                        'homeAway' => $standing['homeAway'],
                        'lastUpdated' => date('Y-m-d H:i:s')
                    ]);
                    $standings_ids[] = $standings_id;

                    if(empty($standing['teamFK'])){
                        $sql = "INSERT INTO flashscore_alias_auto_tag
                        (
                            table_name,
                            column_name,
                            relation_id,
                            fsRef_id,
                            lastUpdated
                        )
                        VALUES
                        (" .
                            "\"standings\", " .
                            "\"teamFK\", " .
                            $standings_id . ", " .
                            $standing['flashscore_alias_id'] . ", " .
                            "\"" . date('Y-m-d H:i:s') . "\"
                        )
                        ";
                        $database->query($sql);
                    }
                }
                // echo $sql . "\n";

            }
        }

        if(!empty($standings_ids)){
            $database->query("
            DELETE FROM standings 
            WHERE tournament_templateFK = " . $this->ids['tournament_template_id'] . 
            " AND tournamentFK = " . $this->ids['tournament_id'] . 
            " AND tournament_stageFK = " . $this->ids['tournament_stage_id'] . 
            " AND id NOT IN (" . implode(', ', $standings_ids) . ")");
        }
        // print_r($data['standings']);
    }
    public function updateAdjustments($data){
        $database = $this->database;
        foreach($data['adjustments'] as $adjustment){
            $existing_adjustments = array();
            if(!empty($adjustment['teamFK'])){
                $res = $database->query(
                    "SELECT *
                    FROM standing_adjustments 
                    WHERE tournamentFK = " . $this->ids['tournament_id'] . 
                    " AND tournament_stageFK = " . $this->ids['tournament_stage_id'] . 
                    " AND club_id = " . $adjustment['teamFK'] .
                    " AND adjustment_reason = \"" . $adjustment['description'] . "\"" . 
                    " AND history = 'no'"
                );
            }else{
                $res = $database->query(
                    "SELECT standing_adjustments.*
                    FROM standing_adjustments 
                    JOIN flashscore_alias_auto_tag ON flashscore_alias_auto_tag.`relation_id` = standing_adjustments.`id`
                    WHERE standing_adjustments.`tournamentFK` = " . $this->ids['tournament_id'] . 
                    " AND standing_adjustments.`tournament_stageFK` = " . $this->ids['tournament_stage_id'] . 
                    " AND standing_adjustments.`club_id` = " . $adjustment['teamFK'] .
                    " AND standing_adjustments.`adjustment_reason` = \"" . $adjustment['description'] . "\"" . 
                    " AND standing_adjustments.`history` = 'no'" .
                    " AND flashscore_alias_auto_tag.`fsRef_id` = " . $adjustment['flashscore_alias_id'] .
                    " AND flashscore_alias_auto_tag.`table_name` = 'standing_adjustments'" .
                    " AND flashscore_alias_auto_tag.`column_name` = 'club_id'"
                );
            }
            
            // if ($database->num_rows($res) > 0) {
            //     while($rw = $database->fetch_assoc($res)){    
            //         $existing_adjustments[] = $rw['id'];
            //     }
            // }
            if(count($res) > 0){
                foreach($res as $rw){
                    $existing_adjustments[] = $rw->id;
                }
            }

            if(!empty($existing_adjustments)){
                if(count($existing_adjustments) > 1){
                    $database->query("UPDATE standing_adjustments SET history = 'yes' WHERE id IN (" . implode(', ', $existing_adjustments) . ")");

                    // $sql = "INSERT INTO standing_adjustments
                    // (
                    //     tournamentFK, 
                    //     tournament_stageFK, 
                    //     club_id, 
                    //     adjustment_type, 
                    //     adjustment_value, 
                    //     adjustment_reason, 
                    //     user_id, 
                    //     history
                    // )
                    // VALUES
                    // (" .
                    //  $adjustment['tournamentFK'] . ", " .
                    //  $adjustment['tournament_stageFK'] . ", " .
                    //  $adjustment['teamFK'] . ", " .
                    //  "\"" .$adjustment['adjustment_type'] . "\", " .
                    //  $adjustment['points'] . ", " .
                    //  "\"" . $adjustment['description'] . "\", " .
                    //  "100,
                    //  'no'
                    // )
                    // ";

                    // $database->query($sql);
                    // $adjustment_id = $database->insert_id();

                    $adjustment_id = $database->instance()->table('standing_adjustments')->insertGetId([
                        'tournamentFK' => $adjustment['tournamentFK'], 
                        'tournament_stageFK' => $adjustment['tournament_stageFK'], 
                        'club_id' => $adjustment['teamFK'], 
                        'adjustment_type' => $adjustment['adjustment_type'], 
                        'adjustment_value' => $adjustment['points'], 
                        'adjustment_reason' => $adjustment['description'], 
                        'user_id' => 100, 
                        'history' => 'no'
                    ]);

                    if(empty($adjustment['teamFK'])){
                        $sql = "INSERT INTO flashscore_alias_auto_tag
                        (
                            table_name,
                            column_name,
                            relation_id,
                            fsRef_id,
                            lastUpdated
                        )
                        VALUES
                        (" .
                            "\"standing_adjustments\", " .
                            "\"club_id\", " .
                            $adjustment_id . ", " .
                            $adjustment['flashscore_alias_id'] . ", " .
                            "\"" . date('Y-m-d H:i:s') . "\"
                        )
                        ";
                        $database->query($sql);
                    }
                }else{
                    $sql = "UPDATE standing_adjustments SET
                    adjustment_type = \"" . $adjustment['adjustment_type'] . "\", 
                    adjustment_value = " . $adjustment['points'] .
                    " WHERE id = " . $existing_adjustments[0];

                    $database->query($sql);
                }
            }else{
                // $sql = "INSERT INTO standing_adjustments
                // (
                //     tournamentFK, 
                //     tournament_stageFK, 
                //     club_id, 
                //     adjustment_type, 
                //     adjustment_value, 
                //     adjustment_reason, 
                //     user_id, 
                //     history
                // )
                // VALUES
                // (" .
                //  $adjustment['tournamentFK'] . ", " .
                //  $adjustment['tournament_stageFK'] . ", " .
                //  $adjustment['teamFK'] . ", " .
                //  "\"" .$adjustment['adjustment_type'] . "\", " .
                //  $adjustment['points'] . ", " .
                //  "\"" . $adjustment['description'] . "\", " .
                //  "100,
                //  'no'
                // )
                // ";

                // $database->query($sql);
                // $adjustment_id = $database->insert_id();
                $adjustment_id = $database->instance()->table('standing_adjustments')->insertGetId([
                    'tournamentFK' => $adjustment['tournamentFK'], 
                    'tournament_stageFK' => $adjustment['tournament_stageFK'], 
                    'club_id' => $adjustment['teamFK'], 
                    'adjustment_type' => $adjustment['adjustment_type'], 
                    'adjustment_value' => $adjustment['points'], 
                    'adjustment_reason' => $adjustment['description'], 
                    'user_id' => 100, 
                    'history' => 'no'
                ]);
                if(empty($adjustment['teamFK'])){
                    $sql = "INSERT INTO flashscore_alias_auto_tag
                    (
                        table_name,
                        column_name,
                        relation_id,
                        fsRef_id,
                        lastUpdated
                    )
                    VALUES
                    (" .
                        "\"standing_adjustments\", " .
                        "\"club_id\", " .
                        $adjustment_id . ", " .
                        $adjustment['flashscore_alias_id'] . ", " .
                        "\"" . date('Y-m-d H:i:s') . "\"
                    )
                    ";
                    $database->query($sql);
                }
            }
        }
        // print_r($data['adjustments']);
    }

}