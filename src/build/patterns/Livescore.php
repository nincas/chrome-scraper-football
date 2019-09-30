<?php
/**
 * Pattern Livescore
 */
namespace Scraper\Build\Pattern;

use Scraper\Kernel\Interfaces\Database as DatabaseInterface;


/** 
* @class Parser
* Parsing of the HTML to data
*/
class Parser {
    
    public $event_id = 0;
    public $away_str11_list = array();
    public $home_str11_list = array();
    public $events_ls = array();
    public $my_file = "";
    public $time_holder=0;
    private $database;
    
    public function __construct($ev, $fle, $db)
    {
        $this->event_id = $ev;
        $this->my_file = $fle;
        $this->database = $db;
    }


    public function check_player_exist($side, $sc, $content)
    {       
        $str_list = $side == "home" ? $this->home_str11_list:$this->away_str11_list;
        
        $content = iconv('UTF-8','ASCII//TRANSLIT',  ($content));
        
        foreach($str_list as $astr => $avl)
        {
            if($content == $avl["fa_alias"] && $avl["fa_alias"] != "")
            {
                //echo $sc == "2" ? "[".$avl["player"]."]" : $avl["player"];
                return $astr;
            }
        }
        
        foreach($str_list as $astr => $avl)
        {
            if($content == $avl["short_name"])
            {
                //echo $sc == "2" ? "[".$avl["player"]."]" : $avl["player"];
                return $astr;
            }
        }
                                
        foreach($str_list as $astr => $avl)
        {
            if(strpos($avl["player"] , $content) === 0 || strpos($avl["player"] , $content) > 0)
            {
                //echo $sc == "2" ? "[".$avl["player"]."]" : $avl["player"];
                return $astr;
            }
        }
        $ct = explode(" ",$content);
        foreach($str_list as $astr => $avl)
        {
            if(strpos($avl["player"] , $ct[0]) === 0 || strpos($avl["player"] , $ct[0]) > 0)
            {
                //echo $sc == "2" ? "[".$avl["player"]."]" : $avl["player"];
                return $astr;
            }
        }
        $chngs = 50;
        $pursued = "";
        foreach($str_list as $astr => $avl)
        {
            $chngs_new = levenshtein($content, $avl["player"]);
            if($chngs_new < $chngs) { $chngs = $chngs_new; $pursued = $astr; }
        }
        if($pursued != "") {return $pursued;}
        return "Not Found";
        //echo $sc == "2" ? "<a style='background:red;' onclick='return tag_alias(".$event_id.", ".$sc.", \"".$content."\");'>[".$content."] </a>" : "<a style='background:red;' onclick='return tag_alias(".$event_id.", ".$sc.", \"".$content."\");'>".$content." </a>";
    }

    public function match_events($status_type)
    {
        $database = $this->database;
        $res = $database->query("SELECT ep.`number`, ep.`participantFK` FROM event_participants ep WHERE ep.`eventFK` = '".$this->event_id."';");
        
        if (count($res) > 0) {
            foreach ($res as $val) {
                if ($val->number == 1) $home = $val->participantFK;
                if ($val->number == 2) $away = $val->participantFK;
            }
        } else {
            error('Match Participants not available.', 1);
        }

        setlocale(LC_CTYPE, 'nl_BE.utf8');

        $ev_l = array(
            "substitution-in"     => 1,
            "soccer-ball"         => 3,
            "soccer-ball-penalty" => 4,
            "penalty-missed"      => 5,
            "soccer-ball-own"     => 6,
            "y-card"              => 8,
            "yr-card"             => 9,
            "r-card"              => 10,
            "(Penalty)"           => 11,
            "(Penalty missed)"    => 12
        );

        $dom_document = domDocument($this->my_file);
        $dom = $dom_document->dom;
        $finder = $dom_document->finder;

        $div = $finder->query("//*[contains(@class, 'detailMS__incidentRow')]");
        $match_status = $finder->query("//*[contains(@class, 'info-status mstat')]");
        $done_statuses = array('Finished', 'After Penalties');
        $attendance = $finder->query("//*[contains(@class, 'parts match-information')]");


        global $time_holder;

        foreach ($attendance as $detail) {

            $td = $detail->getElementsByTagName('div');
            
            foreach($td as $value) {
                if(strpos($value->nodeValue, "Attendance") !== false) {
                    $data = explode(",", trim($value->nodeValue));

                    if (isset($data[0]) && strpos($data[0], "Attendance") !== false) {
                        $dtl = explode(":", $data[0]);

                        if (isset($dtl[0]) && $dtl[0] == 'Attendance' && isset($dtl[1])) {
                            $spectator = str_replace(" ", "", $dtl[1]);
                            
                            if ($spectator > 0) {
                                $database->query("UPDATE event SET spectator = '{$spectator}' WHERE id = '".$this->event_id."'");
                            }
                        }
                    }
                }
            }
        }

        foreach ($match_status as $status) {
            if (in_array($status->nodeValue, $done_statuses)) {
                $database->query("UPDATE event SET status_type = 'finished' WHERE id = '".$this->event_id."'");
            }

            if ($status->nodeValue == 'delayed') {
                $database->query("UPDATE event SET status_type = 'delayed' WHERE id = '".$this->event_id."'");
            }

            if ($status->nodeValue == 'Awarded') {
                $database->query("UPDATE event SET status_type = 'awarded' WHERE id = '".$this->event_id."'");
            }

            $spans = $status->getElementsByTagName('span');

            if ($spans->length > 0) {
                $match_clock = $status->nodeValue;
                $runtime = explode(" - ", $match_clock);

                if(count($runtime) > 1) {
                    $res_run = $database->query("SELECT id FROM event_runtime WHERE eventFK = '".$this->event_id."'");
                    if (count($res_run) == 1) {
                        $database->query("UPDATE event_runtime SET running_time = '".$runtime[1]."', current_half ='".$runtime[0]."' WHERE eventFK = '".$this->event_id."'");
                    } else {
                        $database->query("INSERT INTO event_runtime VALUES('', '".$this->event_id."', '".$runtime[1]."' , '".$runtime[0]."')");
                    }
                } else {
                    $res_run = $database->query("SELECT id FROM event_runtime WHERE eventFK = '".$this->event_id."'");
                    if (count($res_run) == 1) {
                        $database->query("UPDATE event_runtime SET current_half ='".$runtime[0]."' WHERE eventFK = '".$this->event_id."'");
                    } else {
                        $database->query("INSERT INTO event_runtime VALUES('', '".$this->event_id."', '00:00' , '".$runtime[0]."')");
                    }
                }

                if ($status_type == 'delayed') {
                    $database->query("UPDATE event SET status_type = 'inprogress' WHERE id = '".$this->event_id."'");
                }
            }
        }

        $player1_class = array('substitution-out-name', 'participant-name');
        $player2_class = array('substitution-in-name', 'assist note-name');
        $penalty_class = array('(Penalty missed)', '(Penalty)');

        for ($i = 0; $i < $div->length; $i++) {
            $div_class = $div->item($i)->getAttribute('class');
            $class_divs = $div->item($i)->getElementsByTagName('div');

            if(strpos($div_class, "home") !== false) {
                $side = "home";
                $team_id = $home;
            } else if(strpos($div_class, "away") !== false) {
                $side = "away";
                $team_id = $away;
            }

            $before_time = isset($time) ? $time: 0;
            $time = 0;
            $player1 = array();
            $player2 = array();

            foreach ($class_divs as $class_div) {
                $div2 = $class_div->getAttribute('class');

                if ($div2 == 'time-box' || $div2 == 'time-box-wide') {
                    $time = abs(str_replace("'", "", $class_div->textContent));
                } else if (strpos($div2, "icon-box") !== false) {
                    $match_incident = explode(" ", $div2);
                    $incident_id = @$ev_l[$match_incident[1]];
                    $spans = $div->item($i)->getElementsByTagName('span');

                    foreach ($spans as $span) {
                        $span_class = $span->getAttribute('class');
                        $a_player_name = $span->getElementsByTagName('a');
                        $player_shortname = @$a_player_name->item(0)->textContent;

                        if (in_array($span_class, $player1_class)) {
                            $fs_onclick = str_replace("window.open('/player/", '', str_replace("/'); return false;", '', $a_player_name->item(0)->getAttribute('onclick')));
                            $player_onclick = explode('/', $fs_onclick);

                            if ($incident_id == 6) {
                                $team_id = ($side == 'home') ? $away : $home;
                                $side = ($side == "home") ? "away" : "home";
                                //echo "<pre>";
                                //print_r($team_id . ' - ' .  $side . ' away:' . $away . ' home:' . $home);
                            }

                            $player_params = array(
                              'player_fullname' => $player_onclick[0],
                              'player_shortname' => $player_shortname,
                              'team_id' => $team_id,
                              'shirt_number' => null,
                              'fs_id' => $player_onclick[1]
                            );

                            // print_r($player_params);
                            $player1 = $this->playerFinder($player_params, $this->event_id);
                            // print_r($player1);
                            // if ($incident_id != '6') {
                            //     $player1 = $this->check_player_exist($side, 2, $player_name);
                            // } else {
                            //     $player1 = $this->check_player_exist(($side == 'home' ? 'away' : 'home'), 2, $player_name);
                            //     $side = ($side == 'home') ? 'away' : 'home';
                            // }
                        } else if (in_array($span_class, $player2_class)) {
                            $fs_onclick = str_replace("window.open('/player/", '', str_replace("/'); return false;", '', $a_player_name->item(0)->getAttribute('onclick')));
                            $player_onclick = explode('/', $fs_onclick);
                            $player_params = array(
                              'player_fullname' => $player_onclick[0],
                              'player_shortname' => $player_shortname,
                              'team_id' => $team_id,
                              'shirt_number' => null,
                              'fs_id' => $player_onclick[1]
                            );

                            // print_r($player_params);
                            $player2 = $this->playerFinder($player_params, $this->event_id);
                            // print_r($player2);
                            // $player2 = $this->check_player_exist($side, 2, $player_name);
                        }

                        if (trim($span_class) == 'subincident-name') {
                            if ($span->nodeValue == '(Penalty)') {
                                $incident_id = 4;
                            }
                        }

                        if (trim($span_class) == 'note-name' && ($time < $before_time || (string)$before_time == 'PS')) {
                            if (in_array($span->nodeValue, $penalty_class)) {
                                $time = "PS";
                                $incident_id = $ev_l[$span->nodeValue];
                            }
                        }
                    }
                }
            }

            if ($time) {
                $this->events_ls[$time][] = array(
                                                "minutes" => $time,
                                                "event"   => $incident_id,
                                                "player1" => (isset($player1['participant_id'])) ? $player1['participant_id'] : '',
                                                "player2" => (isset($player2['participant_id'])) ? $player2['participant_id'] : '',
                                                "club_id" => ${$side},
                                                "fle_row" => $i,
                                                "fs_player1" => @$player1['flashscore_alias_id'],
                                                "fs_player2" => @$player2['flashscore_alias_id'],
                                            );
            }
        }

        return ($this->events_ls);
    }

    public function flashscore_score() {
        $first_half = 0;
        $final = 0;
        $return_value = array(
            'home' => array('halftime' => 0, 'finalresult' => 0),
            'away' => array('halftime' => 0, 'finalresult' => 0)
        );

        $dom_document = domDocument($this->my_file);
        $dom = $dom_document->dom;
        $finder = $dom_document->finder;

        $match_status = $finder->query("//*[contains(@class, 'info-status mstat')]");

        $status_type = "";

        foreach ($match_status as $status) {
            $status_type = $status->nodeValue;
        }

        $div = $finder->query("//*[contains(@class, 'detailMS__incidentsHeader')]");

        for ($i = 0; $i < $div->length; $i++) {
            // $div_class = $div->item($i)->getAttribute('class');
            $what_half = '';
            $class_divs = $div->item($i)->getElementsByTagName('div');

            foreach ($class_divs as $class_div) {
            // $header_text_class = $class_divs[0]->getAttribute('class');
            // $header_text_name = $class_divs[0]->item(0)->textContent;

            // if($header_text_class == 'detailMS__headerText' && $header_text_name == '1st Half'){
                
            // }

                $header_text_class = $class_div->getAttribute('class');
                $header_text_name = $class_div->textContent;
                if($header_text_class == 'detailMS__headerText' && $header_text_name == '1st Half'){
                    $what_half = 'halftime';
                }else if($header_text_class == 'detailMS__headerText' && $header_text_name != '1st Half'){
                    $what_half = '';
                }

                if($header_text_class == 'detailMS__headerScore'){
                    $score_spans = $class_div->getElementsByTagName('span');
                    foreach ($score_spans as $score_span) {
                        $header_score_class = $score_span->getAttribute('class');
                        $header_score = $score_span->textContent;
                        $explode_header_score_class = explode('_', $header_score_class);
                        // echo $header_score_class . '->' . $ . "\n";
                        if($what_half == 'halftime'){
                            // $row = array(
                            //     'side' => $explode_header_score_class[1],
                            //     'result_code' => $what_half,
                            //     'value' => $header_score
                            // );
                            // array_push($return_value, $row);
                            $return_value[$explode_header_score_class[1]][$what_half] = $header_score;
                        }
                        
                    }
                }
            }
        }

        $div = $finder->query("//*[contains(@class, 'current-result')]");
        for ($i = 0; $i < $div->length; $i++) {
            $j = 0;
            $current_result_spans = $div->item($i)->getElementsByTagName('span');
            foreach($current_result_spans as $current_result_span_key => $current_result_span){
                $current_result_span_class = $current_result_span->getAttribute('class');
                $current_result_span_score = $current_result_span->textContent;

                if($current_result_span_class == 'scoreboard'){
                    if($j == 0){
                        $side = 'home';
                    }else if($j == 1){
                        $side = 'away';
                    }
                    // $row = array(
                    //     'side' => $side,
                    //     'result_code' => 'finalresult',
                    //     'value' => $current_result_span_score
                    // );
                    // array_push($return_value, $row);
                    if($status_type == 'Awarded'){
                        $return_value[$side]['halftime'] = $current_result_span_score;
                    }

                    $return_value[$side]['finalresult'] = $current_result_span_score;
                    $j++;
                }
            }
            // $score = $div->item($i)->textContent;
            // if($score_class == 'scoreboard'){
            //     $row = array(
            //         'side' => ,
            //         'result_code' => 'finalresult',
            //         'value' => $score
            //     );
            //     array_push($return_value, $row);
            // }
        }

        return $return_value;
    }

    public function playerFinder($player_data, $event_id)
    {
        $database = $this->database;

        $result = 0;
        $alias_result = 0;
        $result_name = '';
        $result_source = '';

        $res = $database->query(
            "SELECT * FROM flashscore_alias " .
            "WHERE fs_alias = '" . $player_data['player_fullname'] . "' " .
            "AND participant_type = 'athlete' " .
            "AND fs_id = '" . $player_data['fs_id'] . "'"
        );


        if (count($res) == 1) {
            $flashscore_alias_exists = $res[0];
        }

        if(!empty($flashscore_alias_exists) && $flashscore_alias_exists->participantFK > 0){
            $result = $flashscore_alias_exists->participantFK;
            $result_source = 'flashscore_alias';
        }

        if(empty($result)){
          $player_name_clean = html_entity_decode(str_replace("&nbsp;", "", htmlentities($player_data['player_shortname'])));
          $player_name_clean = trim(preg_replace("/\([^)]+\)/", '', $player_name_clean));

          $player_name_clean = str_replace(' (C)', '', $player_name_clean);

          $player_name_clean = str_replace(' (G)', '', $player_name_clean);

          $explode = explode(' ', $player_name_clean);
          $explode_length = count($explode);

          $player_where = array();

          $surname = array();
          $initial = array();
          for ($i = 0; $i < $explode_length; $i++) {
            $if_initial = strpos($explode[$i], '.');
            $each_length = strlen($explode[$i]);
            if($if_initial !== false && $if_initial == ($each_length - 1)){
              $initial[] = str_replace(".", "", $explode[$i]);

              if ($i < ($explode_length - 1)) {
                $if_initial = strpos($explode[$explode_length - 1], '.');
                $each_length = strlen($explode[$explode_length - 1]);
                if($if_initial !== false && $if_initial == ($each_length - 1)){
                  $initial[] = str_replace(".", "", $explode[$explode_length - 1]);
                }
              }
              break;
            }elseif($if_initial === false){
              $surname[] = $explode[$i];
            }
          }

          $get_surname = implode(' ', $surname);
          $laravel_str_slug = $this->str_slug($get_surname, '-');
          $explode_fullname = explode('-', $player_data['player_fullname']);

          if (!empty($initial)) {
            for ($i = 0; $i < count($initial); $i++) {
              $get_firstname = $player_data['player_fullname'];
              $get_firstname = str_replace($laravel_str_slug, '', $get_firstname);
              $get_firstname = trim($get_firstname, '-');
              $get_firstname_arr = explode('-', $get_firstname);
              $get_firstname = '';
              for($k = 0; $k < count($get_firstname_arr); $k++){
                $this_firstname = substr($get_firstname_arr[$k], 0, strlen($initial[$i]));
                if($this_firstname == strtolower($initial[$i])){
                  $get_firstname = $get_firstname_arr[$k];
                  break;
                }
              }
              if(!empty($get_firstname)){
                $row = array();
                $row[] = array('name', 'like', '%' . $get_surname);
                $row[] = array('name', 'like', $get_firstname. '%');
                $player_where[] = $row;

                $row = array();
                $row[] = array('name', 'like', $get_surname . '%');
                $row[] = array('name', 'like', '%' . $get_firstname);
                $player_where[] = $row;
              }
            }
          }else{
            if(count($surname) == 1 && count($explode_fullname) == 2){
              $row = array();
              $row[] = array('name', 'like', '%' . $explode_fullname[0]);
              $row[] = array('name', 'like', $explode_fullname[1] . '%');
              $player_where[] = $row;

              $row = array();
              $row[] = array('name', 'like', $explode_fullname[0] . '%');
              $row[] = array('name', 'like', '%' . $explode_fullname[1]);
              $player_where[] = $row;
            }elseif(count($surname) == 2){
              $row = array();
              $row[] = array('name', 'like', '%' . $surname[0]);
              $row[] = array('name', 'like', $surname[1] . '%');
              $player_where[] = $row;

              $row = array();
              $row[] = array('name', 'like', $surname[0] . '%');
              $row[] = array('name', 'like', '%' . $surname[1]);
              $player_where[] = $row;
            }elseif(count($surname) > 2){
              $row = array();
              $row[] = array('name', '=', '%' . $get_surname);
              $player_where[] = $row;
            }
          }
        }

        if(empty($result) && empty($initial) && count($surname) == 1){
            $surname = current($surname);
            $res = $database->query(
                "SELECT participant.`id`, participant.`name` " . 
                "FROM object_participants " .
                "JOIN participant ON participantFK = participant.`id` " . 
                "WHERE objectFK = " . $player_data['team_id'] . " " .
                "AND participant_type = 'athlete' " .
                "AND object_participants.active = 'yes' " .
                "AND participant.name = \"" . $surname . "\" " .
                "GROUP BY participant.id"
            );

            $query_count = count($res);

            if ($query_count == 1) {
                foreach ($res as $rw) {
                    $result = $rw->id;
                    $result_name = $rw->name;
                }
                
                $result_source = 'object_participants (2.1)';
            }
        }

        if(empty($result) && !empty($player_where)){
            $res_string = "SELECT participant.`id`, participant.`name` " . 
            "FROM object_participants " . 
            "JOIN participant ON participantFK = participant.`id` " . 
            "WHERE objectFK = " . $player_data['team_id'] . " " .
            "AND participant_type = 'athlete' " .
            "AND object_participants.active = 'yes' " . 
            "AND (";
            for ($j = 0; $j < count($player_where); $j++) {
                if($j != 0){
                    $res_string .= " OR ";
                }
                $res_string .= "(";
                $res_string .= $player_where[$j][0][0] . " " . $player_where[$j][0][1] . " \"" . $player_where[$j][0][2] . "\"";

                if (!empty($player_where[$j][1])) {
                    $res_string .= " AND ";
                    $res_string .= $player_where[$j][1][0] . " " . $player_where[$j][1][1] . " \"" .$player_where[$j][1][2] . "\"";
                }

                $res_string .= ")";
            }
            $res_string .= ") " .
            "GROUP BY participant.id";

            $res = $database->query($res_string);

            $query_count = count($res);

            if ($query_count == 1) {
                foreach ($res as $rw) {
                    $result = $rw->id;
                    $result_name = $rw->name;
                }
               
                $result_source = 'object_participants (2.2)';
            }
        }

        if (empty($result) && !empty($player_where)) {
            $res_string = "SELECT id, name " . 
            "FROM participant " . 
            "WHERE type = 'athlete' " .
            "AND (";
            for ($j = 0; $j < count($player_where); $j++) {
                if($j != 0){
                    $res_string .= " OR ";
                }
                $res_string .= "(";
                $res_string .= $player_where[$j][0][0] . " " . $player_where[$j][0][1] . " \"" . $player_where[$j][0][2] . "\"";

                if (!empty($player_where[$j][1])) {
                    $res_string .= " AND ";
                    $res_string .= $player_where[$j][1][0] . " " . $player_where[$j][1][1] . " \"" .$player_where[$j][1][2] . "\"";
                }

                $res_string .= ")";
            }
            $res_string .= ")";

            $res = $database->query($res_string);

            $query_count = count($res);

            if ($query_count == 1) {
                foreach ($res as $rw) {
                    $result = $rw->id;
                    $result_name = $rw->name;
                }
                
                $result_source = 'participant';
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
                'event_id' => $event_id,
                'team_id' => $player_data['team_id'],
                'fs_alias' => $player_data['player_fullname'],
                'participant_type' => 'athlete',
                'fs_id' => $player_data['fs_id']
            ]);
        }

        return array(
          'participant_id' => $result,
          'flashscore_alias_id' => $alias_result
        );
    }

    public function str_slug($title, $separator = '-', $language = 'en')
    {
        // $title = $language ? static::ascii($title, $language) : $title;

        // Convert all dashes/underscores into separator
        $flip = $separator === '-' ? '_' : '-';

        $title = preg_replace('!['.preg_quote($flip).']+!u', $separator, $title);

        // Replace @ with the word 'at'
        $title = str_replace('@', $separator.'at'.$separator, $title);

        // Remove all characters that are not the separator, letters, numbers, or whitespace.
        $title = preg_replace('![^'.preg_quote($separator).'\pL\pN\s]+!u', '', mb_strtolower($title, 'UTF-8'));

        // Replace all separator characters and whitespace by a single separator
        $title = preg_replace('!['.preg_quote($separator).'\s]+!u', $separator, $title);

        return trim($title, $separator);
    }


    public function match_stats()
    {
        $database = $this->database;
        $res = $database->query("SELECT ep.`number`, ep.`participantFK` FROM event_participants ep WHERE ep.`eventFK` = '".$this->event_id."';");

        if (count($res) > 0) {
            foreach ($res as $val) {
                if ($val->number == 1) $home = $val->participantFK;
                if ($val->number == 2) $away = $val->participantFK;
            }
        }
        
        $stats_field = array();
        $stype = array(
                "Ball Possession"  => "Ball Poss",
                "Goal Attempts"    => "Goal Attempts",
                "Shots on Goal"    => "Shots On",
                "Shots off Goal"   => "Shots Off",
                "Blocked Shots"    => "Blocked Shots",
                "Free Kicks"       => "Free Kicks",
                "Corner Kicks"     => "Corner Kicks",
                "Offsides"         => "Offsides",
                "Throw-in"         => "Throw-in",
                "Goalkeeper Saves" => "Goalkeeper Saves",
                "Fouls"            => "Fouls",
                "Red Cards"        => "Red Cards",
                "Yellow Cards"     => "Yellow Cards"
            );

        $dom_document = domDocument($this->my_file);
        $dom = $dom_document->dom;
        $finder = $dom_document->finder;

        $div = $finder->query("//*[contains(@id, 'tab-statistics-0-statistic')]");

        for ($i = 0; $i < $div->length; $i++) {
            $stat_rows = $div->item($i)->getElementsByTagName('div');

            foreach ($stat_rows as $stat_row) {
                $stat_row_divs = $stat_row->getElementsByTagName('div');
                $class = $stat_row->getAttribute('class');

                if ($class == 'statRow') {
                    foreach ($stat_row_divs as $stat_row_div) {
                        $stat_groups = $stat_row_div->getElementsByTagName('div');
                        $stat_row_div_class = $stat_row_div->getAttribute('class');

                        if ($stat_row_div_class == 'statTextGroup') {
                            $home_value = $stats_type = $away_value = "";
                            foreach ($stat_groups as $stat_group) {
                                $stat_group_class = $stat_group->getAttribute('class');

                                if ($stat_group_class == 'statText statText--homeValue') {
                                    $home_value = trim(str_replace('%', '', $stat_group->nodeValue));
                                } else if ($stat_group_class == 'statText statText--titleValue') {
                                    $stats = trim($stat_group->nodeValue);
                                    $stats_type = isset($stype[$stats]) ? $stype[$stats] : "";
                                } else if ($stat_group_class == 'statText statText--awayValue') {
                                    $away_value = trim(str_replace('%', '', $stat_group->nodeValue));
                                }
                            }

                            if ($home_value != "" && $stats_type != "" && $away_value != "") {
                                    $stats_field[$stats_type] = array("home" => $home_value, "away" => $away_value);
                            }
                        }
                    }
                }
            }
        }

        return $stats_field;
    }
}



/** 
* @class Livescore
* Parsing of the HTML to data
*/
class LiveScore
{
	public $event_id;
	public $eventArr = array();
    private $database;
	
	public function __construct($event_id, DatabaseInterface $database)
	{
		$this->event_id = $event_id;
		if ($this->event_id != "")
        $this->database = $database;
	}

	public function updateLiveScore($events, $updater)
	{
		$database = $this->database;

		$saved_live = $database->query("SELECT id ,
											event_id ,
											minutes ,
											match_incident ,
											player1 ,
											player2 ,
											club_id 
										FROM football_livescore 
										WHERE history = 'no' 
										AND event_id = '".$this->event_id."'");
				
		$existing_events = array();	
		$to_historical = array();

		if(count($saved_live) > 0)
		{
			foreach ($saved_live as $rw) {
                if(!isset($existing_events[$rw->minutes]))
				{
					$existing_events[$rw->minutes] = array();
				}
				array_push($existing_events[$rw->minutes] , array("id"=>$rw->id, "event_id"=>$rw->event_id, 
															"minutes"=>$rw->minutes, "mi"=>$rw->match_incident, 
															"p1"=>$rw->player1, "p2"=>$rw->player2, "clb"=>$rw->club_id ));
				if (isset($events[$rw->minutes])) {
					$notfound = true;
					foreach($events[$rw->minutes] as $kt => $eve) {
						if($eve["event"] == $rw->match_incident && $eve["player1"] == $rw->player1 && ($eve["player2"] == $rw->player2 || ($eve["player2"] == "" && $rw->player2 == 0)) && $eve["club_id"] == $rw->club_id) {
							unset($events[$rw->minutes][$kt]);
							$notfound = false;
						}
					}
					if ($notfound) {
						array_push($to_historical, $rw->id); 
					}
				} else {
					array_push($to_historical, $rw->id); 
				}
            }
		}

		if (count($to_historical) > 0) {
			$result = $database->query("UPDATE football_livescore SET history = 'yes', last_update = NOW() WHERE event_id = '".$this->event_id."' AND id IN (".implode(",", $to_historical).");");
		}
		
		$ins_in = "";
		foreach ($events as $key => $ev) {
			foreach($ev as $e)
			{
				 $ins_in .= "('".$this->event_id."', '".$key."', '".$e["event"]."', '".$e["player1"]."', '".$e["player2"]."', '".$e["club_id"]."', NOW(),'no', '$updater', '" . (empty($e["player1"]) && !empty($e["fs_player1"]) ? $e["fs_player1"] : '') . "', '" . (empty($e["player2"]) && !empty($e["fs_player2"]) ? $e["fs_player2"] : '') . "'),";
			}
		}

		if ($ins_in != "") {
		 	$database->query("INSERT INTO football_livescore (event_id, minutes, match_incident, player1, player2, club_id, last_update, history, updater, fsRef_player1, fsRef_player2) VALUES ".substr($ins_in, 0, -1)); 
		}
    }
    
	public function updatePostMatch($events)
    {
		$database = $this->database;

		$saved_live = $database->query("SELECT id ,
											event_id ,
											minutes ,
											match_incident ,
											player1 ,
											player2 ,
											club_id 
										FROM post_match_details 
										WHERE history = 'no' 
										AND event_id = '".$this->event_id."'");
				
		$existing_events = array();	
		$to_historical = array();					
		if(count($saved_live) > 0) {
            foreach ($saved_live as $rw) {
                if (!isset($existing_events[$rw->minutes])) {
					$existing_events[$rw->minutes] = array();
				}

				array_push($existing_events[$rw->minutes] , array("id"=>$rw->id, "event_id"=>$rw->event_id, 
															"minutes"=>$rw->minutes, "mi"=>$rw->match_incident, 
															"p1"=>$rw->player1, "p2"=>$rw->player2, "clb"=>$rw->club_id ));
				if(isset($events[$rw->minutes])) {
					$notfound = true;
					foreach($events[$rw->minutes] as $kt=>$eve) {
						if($eve["event"] == $rw->match_incident && $eve["player1"] == $rw->player1 && ($eve["player2"] == $rw->player2 || ($eve["player2"] == "" && $rw->player2 == 0)) && $eve["club_id"] == $rw->club_id) {
							unset($events[$rw->minutes][$kt]);
							$notfound = false;
						}
					}

					if($notfound) {
						array_push($to_historical, $rw->id); 
					}
				} else {
					array_push($to_historical, $rw->id);
				}
            }
		}

		if(count($to_historical) > 0) {
			$result = $database->query("UPDATE post_match_details SET history = 'yes', last_update = NOW() WHERE event_id = '".$this->event_id."' AND id IN (".implode(",", $to_historical).");");
		}
		
		$ins_in = "";
		foreach($events as $key => $ev) {
			foreach($ev as $e) {
				 $ins_in .= "('".$this->event_id."', '".$key."', '".$e["event"]."', '".$e["player1"]."', '".$e["player2"]."', '".$e["club_id"]."', NOW(),'no', '" . (empty($e["player1"]) && !empty($e["fs_player1"]) ? $e["fs_player1"] : '') . "', '" . (empty($e["player2"]) && !empty($e["fs_player2"]) ? $e["fs_player2"] : '') . "'),";
			}
		}

		if($ins_in != "") {
			$database->query("INSERT INTO post_match_details (event_id, minutes, match_incident, player1, player2, club_id, last_update, history, fsRef_player1, fsRef_player2) VALUES ".substr($ins_in, 0, -1)); 
		}
    }
    
	public function updateMatchResult2($flashscore_score){
		$database = $this->database;
		$sides = array('home' => 1, 'away' => 2);
		$result_type = array(5 => 'halftime', 4 => 'finalresult');

		$data = $database->query("SELECT ep.id home_id,
									ep.participantFK home_team,
									ep2.id away_id,
									ep2.participantFK away_team
									FROM `event` e
									JOIN event_participants ep ON e.id = ep.eventFK
									JOIN event_participants ep2 ON e.id = ep2.eventFK
									WHERE e.id = '".$this->event_id."'
									AND ep.number = 1 AND ep2.number = 2"
								);
        
		foreach ($data as $rows) {
            
            $home_scores = $flashscore_score['home'];
			$away_scores = $flashscore_score['away'];
			$home_id = $rows->home_id;
			$away_id = $rows->away_id;

			foreach ($sides as $side => $number) {
				foreach ($result_type as $type => $code) {
					$update_id = $delete_id = '';
					$result = $database->query("SELECT * FROM result
												WHERE event_participantsFK = ".${ $side.'_id' }."
													AND result_typeFK = {$type} AND result_code = '{$code}'
												ORDER BY id ASC"
											);
                    
                    foreach ($result as $rows2) {
                        if (!$update_id) {
							$update_id = $rows2->id;
						} else {
							if (!$delete_id) {
								$delete_id = $rows2->id;
							} else {
								$delete_id .= ", ".$rows2->id;
							}
						}
                    }

					if ($delete_id) {
						$res = $database->query("DELETE FROM result WHERE id IN ({$delete_id})");
					}

					if ($update_id) {
						$res = $database->query("UPDATE result SET del = 'no',
											`value` = ".${ $side.'_scores' }[$code].",
											n = {$number}
											WHERE id = {$update_id}"
										);
					} else {
						$res = $database->query("INSERT INTO result (event_participantsFK, result_typeFK, result_code, `value`, n)
											VALUES (".${ $side.'_id' }.", {$type}, '{$code}', ".${ $side.'_scores' }[$code].", '{$number}')"
										);
                    }
				}
			}
        }
	}

	public function updateCKicks($ckicks)
	{
		$database = $this->database;
		
		$kicks = explode("-", $ckicks);
		$sel_t = $database->query("SELECT id FROM match_corner_kicks WHERE history = 'no' AND event_id = '".$this->event_id."' AND h_kick = '".$kicks[0]."' AND a_kick = '".$kicks[1]."'");
		
		if(count($sel_t) == 0)
		{ 
			$result = $database->query("UPDATE match_corner_kicks SET history = 'yes', last_update = NOW() WHERE event_id = '".$this->event_id."'");
			$database->query("INSERT INTO match_corner_kicks VALUES('', '".$this->event_id."', '".$kicks[0]."', '".$kicks[1]."', 'no', NOW())");
		}

	}


	public function updateStats($stats)
	{
		$database = $this->database;
		foreach($stats as $s_t => $val)
		{
			$kicks = explode("-",$val);
			$sel_t = $database->query("SELECT id FROM match_stats WHERE history = 'no' AND eventFK = '".$this->event_id."' AND stats_type ='$s_t' AND h_value = '".$kicks[0]."' AND a_value = '".$kicks[1]."'");
			
			if(count($sel_t) == 0)
			{ 
				$result = $database->query("UPDATE match_stats SET history = 'yes', last_update = NOW() WHERE eventFK = '".$this->event_id."' AND stats_type ='$s_t' ");		
				$database->query("INSERT INTO match_stats VALUES('', '$s_t', '".$this->event_id."', '".$kicks[0]."', '".$kicks[1]."', 'no', NOW())");
			}
		}
	}
}