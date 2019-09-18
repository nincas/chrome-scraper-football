<?php

namespace Scraper\Build\Controller;

use Scraper\Kernel\Crawler\Crawler;
use Scraper\Build\Models\FlashscoreAlias as FlashscoreAliasModel;
use Scraper\Build\Models\FootballCareers as FootballCareersModel;
use Scraper\Build\Models\Participant as ParticipantModel;
use Scraper\Kernel\Interfaces\Controller;
use Scraper\Kernel\Interfaces\Database;
use Scraper\Build\Pattern\Career as CareerPattern;

class Career implements Controller
{
    public $lastUpdated;
    public $per_page = 250;
    public $active_crawl = null;
    public $crawl_started = null;
    public $params = array();
    public $time_limit = null;
    public $strtotime_limit = null;
    public $crawl_started_strtotime = null;
    public $players_count = 0;
    public $players_total = 0;
    public $log_id;


    private $database;

    public function __construct(Database $database, $param = [])
    {
        $this->database = $database->instance();
        $this->individualCrawl($param);
        dump('Career!');
        exit;
    }

    public function crawl()
    {
        $active_crawl = $this->database->table('career_crawler_logs')
            ->where('is_crawled', 'no')
            ->orderBy('date_created')
            ->first();

        $this->active_crawl = $active_crawl;

        if ($active_crawl) {
            if ($active_crawl->type == 'all') {
                $this->crawlAll();
            } else if ($active_crawl->type == 'season') {
                $this->crawlSeason();
            } else if ($active_crawl->type == 'team') {
                $this->crawlTeam();
            } else if ($active_crawl->type == 'player') {
                $this->crawlPlayer();
            } else if ($active_crawl->type == 'join') {
                $this->crawlJoin();
            } else if ($active_crawl->type == 'flashscore_alias') {
                $this->crawlFlashscoreAlias();
            }
        } else {
            $crawl_count = $this->database->table('career_crawler_logs')->count();
            if (empty($crawl_count)) {
                $this->crawlAll();
            } else {
                $this->crawlFlashscoreAlias();
            }
        }
    }

    public function crawlAll()
    {
        //echo 'crawlAll' . "\n";
        $today = date('Y-m-d H:i:s');

        if (!empty($this->params['function_name']) && $this->params['function_name'] == 'crawlAll') {
            $active_crawl = $this->database->table('career_crawler_logs')
                ->where('is_crawled', 'no')
                ->where('type', 'all')
                ->orderBy('date_created')
                ->first();

            if ($active_crawl) {
                $this->active_crawl = $active_crawl;
            }
        }

        if (empty($this->active_crawl)) {
            $last_crawled_all = $this->database->table('career_crawler_logs')
                ->where('is_crawled', 'yes')
                ->where('type', 'all')
                ->whereDate('date_created', date('Y-m-d', strtotime($today)))
                ->orderBy('date_created', 'desc')
                ->first();
        } else {
            $last_crawled_all = null;
        }

        if (empty($last_crawled_all)) {
            if (!empty($this->active_crawl)) {
                if (!empty($this->active_crawl->crawl_started)) {
                    $this->lastUpdated = $this->active_crawl->crawl_started;
                    $this->crawl_started = $this->active_crawl->crawl_started;
                } else {
                    $this->lastUpdated = $today;
                    $this->crawl_started = $today;
                }

                if (!empty($this->active_crawl->total_crawled)) {
                    $offset = $this->active_crawl->total_crawled;
                } else {
                    $offset = 0;
                }
            } else {
                $offset = 0;
                $this->lastUpdated = $today;
                $this->crawl_started = $today;
            }

            $this->crawl_started_strtotime = strtotime($this->crawl_started);



            $players = FlashscoreAliasModel::select(
                'participantFK as player_id',
                'fs_id',
                'fs_alias'
            )
                ->where('participant_type', 'athlete')
                ->where('fs_id', '!=', null)
                ->orderBy('id')
                ->offset($offset)
                ->limit($this->per_page)
                ->get();

            $this->players_count = 0;

            $this->players_total = FlashscoreAliasModel::select(
                'participantFK as player_id',
                'fs_id',
                'fs_alias'
            )
                ->where('participant_type', 'athlete')
                ->where('fs_id', '!=', null)
                ->count();

            $is_crawled = 'no';

            if (empty($this->active_crawl)) {
                if ($this->players_count == $this->players_total) {
                    $is_crawled = 'yes';
                }

                $this->log_id = $this->database->table('career_crawler_logs')
                    ->insertGetId(
                        array(
                            'type' => 'all',
                            'date_created' => $today,
                            'date_updated' => $today,
                            'is_crawled' => $is_crawled,
                            'crawl_started' => $today,
                            'total_crawled' => $this->players_count,
                        )
                    );

                $this->active_crawl = $this->database->table('career_crawler_logs')
                                        ->where('id', $this->log_id)
                                        ->first();
            }

            $this->foreachPlayers($players);
        }
    }

    public function crawlFlashscoreAlias()
    {
        //echo 'crawlFlashscoreAlias' . "\n";
        $today = date('Y-m-d H:i:s');
        $last_crawled = $this->database->table('career_crawler_logs')
            ->where('is_crawled', 'yes')
            ->orderBy('date_created', 'desc')
            ->first();

        if (!empty($last_crawled)) {
            if (!empty($last_crawled->crawl_started)) {
                $this->lastUpdated = $last_crawled->crawl_started;
            } else {
                $this->lastUpdated = $today;
            }
        } else {
            $this->lastUpdated = $today;
        }

        if (!empty($this->params['function_name']) && $this->params['function_name'] == 'crawlFlashscoreAlias') {
            $active_crawl = $this->database->table('career_crawler_logs')
                ->where('is_crawled', 'no')
                ->where('type', 'flashscore_alias')
                ->orderBy('date_created')
                ->first();

            if ($active_crawl) {
                $this->active_crawl = $active_crawl;
            }
        }

        if (!empty($this->active_crawl)) {
            if (!empty($this->active_crawl->crawl_started)) {
                $this->crawl_started = $this->active_crawl->crawl_started;
            } else {
                $this->crawl_started = $today;
            }

            if (!empty($this->active_crawl->total_crawled)) {
                $offset = $this->active_crawl->total_crawled;
            } else {
                $offset = 0;
            }
        } else {
            $offset = 0;
            $this->crawl_started = $today;
        }



        $players = FlashscoreAliasModel::select(
            'participantFK as player_id',
            'fs_id',
            'fs_alias'
        )
            ->where('participant_type', 'athlete')
            ->where('fs_id', '!=', null)
            ->where('date_updated', '>=', $this->lastUpdated)
            ->orderBy('id')
            ->offset($offset)
            ->limit($this->per_page)
            ->get();

        $this->foreachPlayers($players);

        $players_count = count($players);

    

        $players_total = FlashscoreAliasModel::select(
            'participantFK as player_id',
            'fs_id',
            'fs_alias'
        )
            ->where('participant_type', 'athlete')
            ->where('fs_id', '!=', null)
            ->where('date_updated', '>=', $this->lastUpdated)
            ->count();

        $is_crawled = 'no';

        if (!empty($this->active_crawl)) {
            $update_data = array();

            if (empty($this->active_crawl->crawl_started)) {
                $update_data['crawl_started'] = $today;
            }

            $total_crawled = $this->active_crawl->total_crawled + $players_count;
            // //echo $total_crawled . '/' . $players_total . "\n";
            if ($total_crawled == $players_total) {
                $is_crawled = 'yes';
                $update_data['is_crawled'] = $is_crawled;
            }
            $update_data['total_crawled'] = $total_crawled;
            $update_data['date_updated'] = date('Y-m-d H:i:s');

            if (!empty($update_data)) {
                $this->database->table('career_crawler_logs')
                    ->where('id', $this->active_crawl->id)
                    ->update($update_data);
            }
        } else {
            if ($players_count == $players_total) {
                $is_crawled = 'yes';
            }

            $this->database->table('career_crawler_logs')
                ->insert(
                    array(
                        'type' => 'flashscore_alias',
                        'date_created' => $today,
                        'date_updated' => $today,
                        'is_crawled' => $is_crawled,
                        'crawl_started' => $today,
                        'total_crawled' => $players_count,
                    )
                );
        }


    }

    public function crawlSeason()
    {
        //echo 'crawlSeason' . "\n";
    }

    public function crawlTeam()
    {
        //echo 'crawlTeam' . "\n";
    }

    public function crawlPlayer()
    {
        //echo 'crawlPlayer' . "\n";
        $today = date('Y-m-d H:i:s');
        $this->lastUpdated = $today;
        $this->crawl_started = $today;

        $player_ids = array();
        if (!empty($this->params['player_ids'])) {
            $player_ids = explode(',', $this->params['player_ids']);
        }

        if (!empty($player_ids)) {
            $players = TournamentTemplateModel::select(
                            'tournament_stage.id as tournament_stage_id',
                            'player_op.participantFK as player_id',
                            'flashscore_alias.fs_id',
                            'flashscore_alias.fs_alias'
                        )
                            ->join('tournament', 'tournament.tournament_templateFK', '=', 'tournament_template.id')
                            ->join('tournament_stage', 'tournament_stage.tournamentFK', '=', 'tournament.id')
                            ->join('object_participants as team_op', 'team_op.objectFK', '=', 'tournament_stage.id')
                            ->join('object_participants as player_op', 'player_op.objectFK', '=', 'team_op.participantFK')
                            ->join('flashscore_alias', 'flashscore_alias.participantFK', '=', 'player_op.participantFK')
                            ->where('tournament_template.del', 'no')
                        // ->where('tournament_template.active', 'Active')
                            ->where('tournament.del', 'no')
                        // ->where('tournament.active', 'yes')
                            ->where('tournament_stage.del', 'no')
                            ->where('team_op.object', 'tournament_stage')
                            ->where('team_op.participant_type', 'team')
                            ->where('team_op.del', 'no')
                        // ->where('team_op.active', 'yes')
                            ->where('player_op.object', 'participant')
                            ->where('player_op.participant_type', 'athlete')
                            ->where('player_op.del', 'no')
                            ->whereIn('player_op.participantFK', $player_ids)
                        // ->where('player_op.active', 'yes')
                            ->where('flashscore_alias.participant_type', 'athlete')
                            ->where('flashscore_alias.fs_id', '!=', null)
                            ->groupBy('flashscore_alias.id')
                            ->orderBy('flashscore_alias.id')
                            ->get();

            $this->foreachPlayers($players);
        }
    }

    public function crawlJoin()
    {
        //echo 'crawlJoin' . "\n";
        $today = date('Y-m-d H:i:s');

        $last_crawled = $this->database->table('career_crawler_logs')
            ->where('is_crawled', 'yes')
            ->orderBy('date_created', 'desc')
            ->first();

        if (!empty($last_crawled)) {
            if (!empty($last_crawled->crawl_started)) {
                $this->lastUpdated = $last_crawled->crawl_started;
            } else {
                $this->lastUpdated = $today;
            }
        } else {
            $this->lastUpdated = $today;
        }

        if (!empty($this->active_crawl)) {
            if (!empty($this->active_crawl->crawl_started)) {
                $this->crawl_started = $this->active_crawl->crawl_started;
            } else {
                $this->crawl_started = $today;
            }

            if (!empty($this->active_crawl->total_crawled)) {
                $offset = $this->active_crawl->total_crawled;
            } else {
                $offset = 0;
            }
        } else {
            $offset = 0;
            $this->crawl_started = $today;
        }

        $seasons = TournamentTemplateModel::select(
            'tournament_stage.id as tournament_stage_id',
            'player_op.participantFK as player_id',
            'flashscore_alias.id as flashscore_alias_id',
            'flashscore_alias.fs_id',
            'flashscore_alias.fs_alias'
        )
            ->join('tournament', 'tournament.tournament_templateFK', '=', 'tournament_template.id')
            ->join('tournament_stage', 'tournament_stage.tournamentFK', '=', 'tournament.id')
            ->join('object_participants as team_op', 'team_op.objectFK', '=', 'tournament_stage.id')
            ->join('object_participants as player_op', 'player_op.objectFK', '=', 'team_op.participantFK')
            ->join('flashscore_alias', 'flashscore_alias.participantFK', '=', 'player_op.participantFK')
            ->where('tournament_template.del', 'no')
        // ->where('tournament_template.active', 'Active')
            ->where('tournament.del', 'no')
            ->where('tournament.active', 'yes')
            ->where('tournament.ut', '>=', $this->lastUpdated)
            ->where('tournament_stage.del', 'no')
            ->where('team_op.object', 'tournament_stage')
            ->where('team_op.participant_type', 'team')
            ->where('team_op.del', 'no')
        // ->where('team_op.active', 'yes')
            ->where('player_op.object', 'participant')
            ->where('player_op.participant_type', 'athlete')
            ->where('player_op.del', 'no')
        // ->where('player_op.active', 'yes')
            ->where('flashscore_alias.participant_type', 'athlete')
            ->where('flashscore_alias.fs_id', '!=', null)
            ->groupBy('flashscore_alias.id');

        $teams = TournamentTemplateModel::select(
            'tournament_stage.id as tournament_stage_id',
            'player_op.participantFK as player_id',
            'flashscore_alias.id as flashscore_alias_id',
            'flashscore_alias.fs_id',
            'flashscore_alias.fs_alias'
        )
            ->join('tournament', 'tournament.tournament_templateFK', '=', 'tournament_template.id')
            ->join('tournament_stage', 'tournament_stage.tournamentFK', '=', 'tournament.id')
            ->join('object_participants as team_op', 'team_op.objectFK', '=', 'tournament_stage.id')
            ->join('object_participants as player_op', 'player_op.objectFK', '=', 'team_op.participantFK')
            ->join('flashscore_alias', 'flashscore_alias.participantFK', '=', 'player_op.participantFK')
            ->where('tournament_template.del', 'no')
        // ->where('tournament_template.active', 'Active')
            ->where('tournament.del', 'no')
        // ->where('tournament.active', 'yes')
            ->where('tournament_stage.del', 'no')
            ->where('team_op.object', 'tournament_stage')
            ->where('team_op.participant_type', 'team')
            ->where('team_op.del', 'no')
            ->where('team_op.ut', '>=', $this->lastUpdated)
        // ->where('team_op.active', 'yes')
            ->where('player_op.object', 'participant')
            ->where('player_op.participant_type', 'athlete')
            ->where('player_op.del', 'no')
        // ->where('player_op.active', 'yes')
            ->where('flashscore_alias.participant_type', 'athlete')
            ->where('flashscore_alias.fs_id', '!=', null)
            ->groupBy('flashscore_alias.id');

        $players = TournamentTemplateModel::select(
            'tournament_stage.id as tournament_stage_id',
            'player_op.participantFK as player_id',
            'flashscore_alias.id as flashscore_alias_id',
            'flashscore_alias.fs_id',
            'flashscore_alias.fs_alias'
        )
            ->join('tournament', 'tournament.tournament_templateFK', '=', 'tournament_template.id')
            ->join('tournament_stage', 'tournament_stage.tournamentFK', '=', 'tournament.id')
            ->join('object_participants as team_op', 'team_op.objectFK', '=', 'tournament_stage.id')
            ->join('object_participants as player_op', 'player_op.objectFK', '=', 'team_op.participantFK')
            ->join('flashscore_alias', 'flashscore_alias.participantFK', '=', 'player_op.participantFK')
            ->where('tournament_template.del', 'no')
        // ->where('tournament_template.active', 'Active')
            ->where('tournament.del', 'no')
        // ->where('tournament.active', 'yes')
            ->where('tournament_stage.del', 'no')
            ->where('team_op.object', 'tournament_stage')
            ->where('team_op.participant_type', 'team')
            ->where('team_op.del', 'no')
        // ->where('team_op.active', 'yes')
            ->where('player_op.object', 'participant')
            ->where('player_op.participant_type', 'athlete')
            ->where('player_op.del', 'no')
            ->where('player_op.ut', '>=', $this->lastUpdated)
        // ->where('player_op.active', 'yes')
            ->where('flashscore_alias.participant_type', 'athlete')
            ->where('flashscore_alias.fs_id', '!=', null)
            ->groupBy('flashscore_alias.id');

        $joins = $seasons
            ->union($teams)
            ->union($players)
            ->orderBy('flashscore_alias_id')
            ->offset($offset)
            ->limit($this->per_page)
            ->get();

        $this->foreachPlayers($joins);

        $joins_count = count($joins);

        if (!empty($joins_count)) {
            $joins_total = $seasons
                ->union($teams)
                ->union($players)
                ->count();

            $is_crawled = 'no';

            if (!empty($this->active_crawl)) {
                $update_data = array();

                if (empty($this->active_crawl->crawl_started)) {
                    $update_data['crawl_started'] = $today;
                }

                $total_crawled = $this->active_crawl->total_crawled + $joins_count;
                // //echo $total_crawled . '/' . $joins_total . "\n";
                if ($total_crawled == $joins_total) {
                    $is_crawled = 'yes';
                    $update_data['is_crawled'] = $is_crawled;
                }
                $update_data['total_crawled'] = $total_crawled;
                $update_data['date_updated'] = $today;

                if (!empty($update_data)) {
                    $this->database->table('career_crawler_logs')
                        ->where('id', $this->active_crawl->id)
                        ->update($update_data);
                }
            } else {
                if ($joins_count == $joins_total) {
                    $is_crawled = 'yes';
                }

                $this->database->table('career_crawler_logs')
                    ->insert(
                        array(
                            'type' => 'join',
                            'date_created' => $today,
                            'date_updated' => $today,
                            'is_crawled' => $is_crawled,
                            'crawl_started' => $today,
                            'total_crawled' => $joins_count,
                        )
                    );
            }
        }
    }

    public function foreachPlayers($players)
    {
        foreach ($players as $idx => $data) {
            $this->crawl_started_strtotime = strtotime('now');
            if (!empty($this->strtotime_limit) && $this->crawl_started_strtotime >= $this->strtotime_limit) {
                break;
            }
            //echo 'https://www.flashscore.com/player/' . $data['fs_alias'] . '/' . $data['fs_id'] . "\n";
            ini_set('max_execution_time', 300);
            $indicator = true;
            $return_value = 'No Error';
            // $path = getcwd();
            $path = dirname(dirname(__DIR__));
            $path = str_replace("\\", "/", $path);
            $path = $path . '/Crawl/';
            $date_folder = date('Y-m-d', strtotime($this->crawl_started));
            $link = 'https://www.flashscore.com/player/' . $data['fs_alias'] . '/' . $data['fs_id'];

            $site = 'flashscore';


            $file_name = $data['player_id'] . '_' . $site;
            $return_value = $this->crawl_page($path, $file_name, $link);
            if ($return_value == 'Error') {
                break;
            }

            if ($return_value == 'Error') {
                $indicator = false;

                continue;
            }

           
            if ($indicator) {
                $file = 'career.html';
                $cpath = $path . 'text_file/' . $date_folder;

                if (file_exists(FILE_PATH . $file)) {
                    $careerPattern = new CareerPattern($file, $data, $this->database);
                    $careers = $careerPattern->patternCareer();
                    $careerPattern->updateCareer();
                }
            }
            // sleep(60);
            $this->players_count = $this->players_count + 1;

            $is_crawled = 'no';

            if (!empty($this->active_crawl)) {
                $update_data = array();

                if (empty($this->active_crawl->crawl_started)) {
                    $update_data['crawl_started'] = $this->crawl_started;
                }

                $total_crawled = $this->active_crawl->total_crawled + $this->players_count;
                // //echo $total_crawled . '/' . $players_total . "\n";
                if ($total_crawled == $this->players_total) {
                    $is_crawled = 'yes';
                    $update_data['is_crawled'] = $is_crawled;
                }
                $update_data['total_crawled'] = $total_crawled;
                $update_data['date_updated'] = date('Y-m-d H:i:s');

                dump(($idx + 1) . '# ' . $data['fs_alias']);
                if (!empty($update_data)) {
                    $this->database->table('career_crawler_logs')
                        ->where('id', $this->active_crawl->id)
                        ->update($update_data);
                }
            }
        }
    }

    public function crawlTimestamp()
    {
    }

    public function crawlDelete()
    {
        $number_of_days = 7;
        if (isset($this->params['number_of_days']) && is_numeric($this->params['number_of_days'])) {
            $number_of_days = $this->params['number_of_days'];
        }

        $today = date('Y-m-d', strtotime('- ' . $number_of_days . ' days'));
        $path = dirname(dirname(__DIR__));
        $path = str_replace("\\", "/", $path);
        $path = $path . '/Crawl/';
        $js_file = $path . 'js_file/' . $today . '/';
        if (file_exists($js_file)) {
            $this->deleteAll($js_file);
        }

        $text_file = $path . 'text_file/' . $today . '/';
        if (file_exists($text_file)) {
            $this->deleteAll($text_file);
        }
        //echo 'deleted';
    }

    public function deleteAll($str)
    {
        //It it's a file.
        if (is_file($str)) {
            //Attempt to delete it.
            return unlink($str);
        }
        //If it's a directory.
        elseif (is_dir($str)) {
            //Get a list of the files in this directory.
            $scan = glob(rtrim($str, '/') . '/*');
            //Loop through the list of files.
            foreach ($scan as $index => $path) {
                //Call our recursive function.
                $this->deleteAll($path);
            }
            //Remove the directory itself.
            return @rmdir($str);
        }
    }

    public function testVariable()
    {
        // $this->database->table('career_crawler_logs')->where('id', 1)->update('total_crawled', $this->per_page);
        sleep(5);
    }

    public function setParams($params)
    {
        $this->params = $params;
        return $this;
    }

    public function setPerPage($limit)
    {
        $this->per_page = $limit;
        return $this;
    }

    public function setTimeLimit($limit)
    {
        $this->time_limit = $limit;
        return $this;
    }

    public function setStrtotimeLimit($limit)
    {
        $this->strtotime_limit = $limit;
        return $this;
    }

    public function individualCrawl($params)
    {

        if (!empty($params['limit']) && is_numeric($params['limit'])) {
            $this->per_page = $params['limit'];
            // $this->setPerPage($params['limit']);
        }

        if (!empty($params['time_limit']) && is_numeric($params['time_limit'])) {
            $this->time_limit = $params['time_limit'];
            $this->strtotime_limit = strtotime('+ ' . $this->time_limit . ' minutes');
        }

        if (!empty($params['function_name'])) {
            if (method_exists($this, $params['function_name'])) {
                $this->{$params['function_name']}();
            } else {
                $this->crawl();
            }
        } else {
            $this->crawl();
        }
    }

    public function crawl_page($path, $file_name, $link)
    {
        $file_name = FILE_PATH . '/career.html';
        $crawler = new Crawler($link);
        $html = $crawler->crawl($file_name);
        return $html;
    }
}
