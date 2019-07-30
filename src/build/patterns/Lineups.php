<?php
/**
 * Pattern Lineups
 */
namespace Scraper\Build\Pattern;

use \DOMDocument;
use \DomXPath;

use Scraper\Kernel\Interfaces\Database as DatabaseInterface;


/** 
* @class Parser
* Parsing of the HTML to data
*/
class Lineups {
    
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

    public function lineups(){
        $database = $this->database;
        $data = file_get_contents( $this->my_file );
        $return = array(
            'home' => array(
                'starting' => array(),
                'substitutes' => array(),
                'missing' => array()
            ),
            'away' => array(
                'starting' => array(),
                'substitutes' => array(),
                'missing' => array()
            )
        );

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($data);
        $dom->encoding = 'UTF-8';
        $finder = new DomXPath($dom);
        $wrappers = array(
            'lineups-wrapper',
            'missing-players-wrapper'
        );
        foreach($wrappers as $wrapper){
            $divs = $finder->query("//*[contains(@class, '" . $wrapper . "')]");
            if($divs->length > 0){
                dump($wrapper);
                $div = $divs->item(0);
                $trs = $div->getElementsByTagName('tr');
                foreach($trs as $tr){
                    $tds = $tr->getElementsByTagName('td');
                    foreach($tds as $td){
                        $td_class = explode(' ', $td->getAttribute('class'));
                        $homeAway = '';
                        if(in_array('fl', $td_class)){
                            $homeAway = 'home';
                        }else if(in_array('fr', $td_class)){
                            $homeAway = 'away';
                        }

                        if(!empty($homeAway)){
                            $a_tags = $td->getElementsByTagName('a');
                            foreach($a_tags as $a_tag){
                                $fs_onclick = $a_tag->getAttribute('onclick');
                                if(!empty($fs_onclick)){
                                    $fs_onclick = str_replace("window.open('/player/", '', str_replace("/'); return false;", '', $fs_onclick));
                                    $player_onclick = explode('/', $fs_onclick);

                                    $team_id = $this->ids[$homeAway . '_team_id'];

                                    $player_shortname = $a_tag->textContent;
                                    $spans = $a_tag->getElementsByTagName('span');
                                    foreach($spans as $span){
                                        $span_text = $span->textContent;
                                        $player_shortname = str_replace($span_text, '', $player_shortname);
                                    }

                                    $player_params = array(
                                      'player_fullname' => $player_onclick[0],
                                      'player_shortname' => $player_shortname,
                                      'team_id' => $team_id,
                                      'shirt_number' => null,
                                      'fs_id' => $player_onclick[1]
                                    );

                                    $player = $this->playerFinder($player_params, $this->ids['event_id']);
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $return;
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
          $laravel_str_slug = str_slug($get_surname, '-');
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

}