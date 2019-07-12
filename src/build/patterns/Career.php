<?php
/**
 * Pattern Career
 */
namespace Scraper\Build\Pattern;

use Scraper\Kernel\Interfaces\Database as DatabaseInterface;

use Scraper\Build\Models\FlashscoreAlias as FlashscoreAliasModel;
use Scraper\Build\Models\FootballCareers as FootballCareersModel;
use Scraper\Build\Models\Participant as ParticipantModel;
use Scraper\Build\Models\TournamentTemplate as TournamentTemplateModel;
use \DOMDocument;
use \DomXPath;

class Career {
    private $file;
    private $crawl_path;
    private $data;
    private $careers = [];
    private $html;

    public function __construct(string $file, $data) {
        
        $this->file = $file;
        $this->crawl_path = FILE_PATH;
        $this->data = $data;
        $try = file_get_contents(FILE_PATH . $file);
        if (!$try) {
            die('Error loading html.');
        } 

        $this->html = $try;

        libxml_use_internal_errors(true);
    }

    public function patternCareer() {
        $dom = new DOMDocument();
        $dom->loadHTML($this->html);
        $dom->encoding = 'UTF-8';

        $xpath = new DomXPath($dom);

        $league_tables = $xpath->query("//*[contains(@id, 'league-table')]");

        foreach ($league_tables as $league_table) {
            $tbodys = $league_table->getElementsByTagName('tbody');
            if (!empty($tbodys->length)) {
                //echo 'table' . "\n";
                $tbody = $tbodys->item(0);
                $tbody_trs = $tbody->getElementsByTagName('tr');
                if (!empty($tbody_trs)) {
                    foreach ($tbody_trs as $tbody_tr) {
                        $tbody_tds = $tbody_tr->getElementsByTagName('td');
                        $season_name = $tbody_tds->item(0)->textContent;
                        if ($season_name != 'Total') {
                            $anchor_tag = $tbody_tds->item(1)->getElementsByTagName('a')->item(0);
                            $anchor_onclick = $anchor_tag->getAttribute('href');
                            $substr_start = strpos($anchor_onclick, '/team/');
                            $anchor_onclick = substr($anchor_onclick, $substr_start + 6);
                            $explode_onclick = explode('/', $anchor_onclick);
                            $team_name = $anchor_tag->textContent;
                            $team_params = array(
                                'name' => $team_name,
                                'url' => $explode_onclick[0],
                                'id' => $explode_onclick[1],
                                'tournament_stage_id' => !empty($this->data['tournament_stage_id']) ? $this->data['tournament_stage_id'] : 0,
                            );
                            $teamFinder = $this->teamFinder($team_params);

                            $row = [
                                'season_name' => $season_name,
                                'playerid' => $this->data['player_id'],
                                'club_id' => $teamFinder['participant_id'],
                                'flashscore_alias_id' => $teamFinder['flashscore_alias_id'],
                            ];
                            array_push($this->careers, $row);
                        }
                    }
                }
            } else {
                $league_table_class = $league_table->getAttribute('class');
                $league_table_class_array = explode(' ', $league_table_class);
                if (in_array('career-table-soccer', $league_table_class_array)) {
                    //echo 'div' . "\n";
                    $league_table_rows = $xpath->query("descendant::*[contains(@class, 'profileTable__row')]", $league_table);
                    foreach ($league_table_rows as $league_table_row) {
                        $league_table_row_class = $league_table_row->getAttribute('class');
                        $league_table_date = $xpath->query("descendant::*[contains(@class, 'playerTable__date')]", $league_table_row)->item(0)->textContent;
                        if (!in_array($league_table_date, array('Season', 'Total'))) {
                            $league_table_team = $xpath->query("descendant::*[contains(@class, 'playerTable__team--teamName')]", $league_table_row)->item(0);
                            $league_table_team_a = $league_table_team->getElementsByTagName('a')->item(0);
                            $anchor_onclick = $league_table_team_a->getAttribute('href');
                            $substr_start = strpos($anchor_onclick, '/team/');
                            $anchor_onclick = substr($anchor_onclick, $substr_start + 6);
                            $explode_onclick = explode('/', $anchor_onclick);
                            $team_name = $league_table_team_a->textContent;
                            $team_params = array(
                                'name' => $team_name,
                                'url' => $explode_onclick[0],
                                'id' => $explode_onclick[1],
                                'tournament_stage_id' => !empty($this->data['tournament_stage_id']) ? $this->data['tournament_stage_id'] : 0,
                            );
                            $teamFinder = $this->teamFinder($team_params);

                            $row = [
                                'season_name' => $league_table_date,
                                'playerid' => $this->data['player_id'],
                                'club_id' => $teamFinder['participant_id'],
                                'flashscore_alias_id' => $teamFinder['flashscore_alias_id'],
                            ];
                            array_push($this->careers, $row);
                        }
                    }
                }
            }
        }
    }


    public function teamFinder($params) {
        $result = 0;
        $alias_result = 0;
        $result_name = '';

        if (!empty($params['url']) && !empty($params['id']) && !empty($params['name'])) {

            $flashscore_alias_exists = FlashscoreAliasModel::where('fs_alias', $params['url'])
                ->where('participant_type', 'team')
                ->where('fs_id', $params['id'])
                ->first();

            if ($flashscore_alias_exists && $flashscore_alias_exists->participantFK > 0) {
                $result = $flashscore_alias_exists->participantFK;
                $result_name = $flashscore_alias_exists->fs_alias;
            }

            if (empty($result) && !empty($params['tournament_stage_id'])) {
                $team = $this->database->table('object_participants as op')
                    ->select('p.id', 'p.name')
                    ->join('participant as p', 'op.participantFK', '=', 'p.id')
                    ->whereIn('op.participant_type', ['team', 'national club'])
                    ->where('op.active', 'yes')
                    ->where('op.object', 'tournament_stage')
                    ->where('op.objectFK', $params['tournament_stage_id'])
                    ->where('p.name', 'LIKE', '%' . $params['name'] . '%')
                    ->groupBy('p.id');

                $query_result = $team->get();
                $query_count = count($query_result);

                if ($query_count == 1) {
                    $result = $query_result[0]->id;
                    $result_name = $query_result[0]->name;
                }
            }

            if (empty($result)) {
                $team = ParticipantModel::select('id', 'name')
                    ->where('type', 'team')
                    ->where('del', 'no')
                    ->where('name', 'LIKE', '%' . $params['name'] . '%');

                $query_result = $team->get();
                $query_count = count($query_result);

                if ($query_count == 1) {
                    $result = $query_result[0]->id;
                    $result_name = $query_result[0]->name;
                }
            }

            if ($flashscore_alias_exists) {
                $alias_result = $flashscore_alias_exists->id;
                if (!empty($result) && $flashscore_alias_exists->participantFK == 0) {
                    $this->database->table('flashscore_alias')
                        ->where('id', $alias_result)
                        ->update(
                            array(
                                'participantFK' => $result,
                            )
                        );
                }
            } else {
                $alias_result = $this->database->table('flashscore_alias')->insertGetId([
                    'participantFK' => $result,
                    'fs_alias' => $params['url'],
                    'participant_type' => 'team',
                    'fs_id' => $params['id'],
                ]);
            }
        }

        return array(
            'participant_id' => $result,
            'participant_name' => $result_name,
            'flashscore_alias_id' => $alias_result,
        );
    }

    public function updateCareer() {
        foreach ($this->careers as $data) {
            if (empty($data['club_id'])) {
                FootballCareersModel::where('season_name', $data['season_name'])
                    ->where('playerid', $data['playerid'])
                    ->where('updatedBy', '!=', 100)
                    ->update([
                        'deleted' => 'yes',
                    ]);
            }

            if (!empty($data['club_id'])) {
                $career_exists = FootballCareersModel::where('season_name', $data['season_name'])
                    ->where('playerid', $data['playerid'])
                    ->where('club_id', $data['club_id'])
                    ->where('deleted', 'no')
                    ->first();
            } else {
                $career_exists = FootballCareersModel::join('flashscore_alias_auto_tag', 'flashscore_alias_auto_tag.relation_id', '=', 'football_careers.id')
                    ->where('football_careers.season_name', $data['season_name'])
                    ->where('football_careers.playerid', $data['playerid'])
                    ->where('football_careers.club_id', $data['club_id'])
                    ->where('football_careers.deleted', 'no')
                    ->where('flashscore_alias_auto_tag.fsRef_id', $data['flashscore_alias_id'])
                    ->where('flashscore_alias_auto_tag.table_name', 'football_careers')
                    ->where('flashscore_alias_auto_tag.column_name', 'club_id')
                    ->first();
            }

            if (!$career_exists) {
                $career_id = FootballCareersModel::insertGetId([
                    'season_name' => $data['season_name'],
                    'playerid' => $data['playerid'],
                    'club_id' => $data['club_id'],
                    'lastUpdated' => date('Y-m-d H:i:s'),
                    'updatedBy' => 100,
                    'deleted' => 'no',
                ]);

                if (empty($data['club_id'])) {
                    $this->database->table('flashscore_alias_auto_tag')->insert([
                        'table_name' => 'football_careers',
                        'column_name' => 'club_id',
                        'relation_id' => $career_id,
                        'fsRef_id' => $data['flashscore_alias_id'],
                        'lastUpdated' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }
    }

}