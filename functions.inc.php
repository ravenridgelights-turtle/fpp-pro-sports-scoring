<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";
$pluginName = basename(dirname(__FILE__)); 
$logFile = $settings['logDirectory']."/".$pluginName.".log";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." . $pluginName;
$leagues = array('nfl', 'ncaa', 'nhl', 'mlb');

if (file_exists($pluginConfigFile)) {
  $pluginSettings = parse_ini_file ($pluginConfigFile);
} else {	
	$pluginSettings="";
	logEntry("No pluginConfigFile");
}


if(isset($_POST['action']) && !empty($_POST['action'])) {
    $action = $_POST['action'];
    switch($action) {
        case 'updateNFLTeam' : updateTeam("football", "nfl");
			break;
		case 'updateNCAATeam' : updateTeam("football", "ncaa");
			break;
		case 'updateNHLTeam' : updateTeam("hockey", "nhl");
			break;
		case 'updateMLBTeam' : updateTeam("baseball", "mlb");
			break;
        case 'blah' : blah();
			break;        
    }
}

function getTeams($sport='football', $league='nfl'){
        if ($sport == 'football' && $league == 'ncaa') {
                return getNCAATeams();
        } else {
                $url = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$league}/teams";

                $options = array(
                        'http' => array(
                                'method'  => 'GET',
                                'timeout' => 10,
                                'header'  => "User-Agent: FPP-Pro-Sports-Scoring\r\n"
                        )
                );

                $context = stream_context_create($options);
                $result = @file_get_contents($url, false, $context);

                $teamNames = array(
                        "No team" => ""
                );

                if ($result === false) {
                        return $teamNames;
                }

                $data = json_decode($result, true);

                if (
                        !is_array($data) ||
                        !isset($data['sports'][0]['leagues'][0]['teams']) ||
                        !is_array($data['sports'][0]['leagues'][0]['teams'])
                ) {
                        return $teamNames;
                }

                foreach ($data['sports'][0]['leagues'][0]['teams'] as $teamEntry) {
                        if (!isset($teamEntry['team'])) {
                                continue;
                        }

                        $team = $teamEntry['team'];

                        if (!isset($team['displayName']) || !isset($team['id'])) {
                                continue;
                        }

                        $teamNames[$team['displayName']] = $team['id'];
                }

                return $teamNames;
        }
}

    foreach ($data['sports'][0]['leagues'][0]['teams'] as $teamEntry) {
        if (!isset($teamEntry['team'])) {
            continue;
        }

        $team = $teamEntry['team'];

        if (!isset($team['displayName']) || !isset($team['id'])) {
            continue;
        }

        $teamNames[$team['displayName']] = $team['id'];
    }

    return $teamNames;
}
}

function getNCAATeams(){
        $url = "https://site.api.espn.com/apis/site/v2/sports/football/college-football/teams?limit=1000";

        $options = array(
                'http' => array(
                        'method'  => 'GET',
                        'timeout' => 10,
                        'header'  => "User-Agent: FPP-Pro-Sports-Scoring\r\n"
                )
        );

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        $teamNames = array(
                "No team" => ""
        );

        if ($result === false) {
                return $teamNames;
        }

        $data = json_decode($result, true);

        if (
                !is_array($data) ||
                !isset($data['sports'][0]['leagues'][0]['teams']) ||
                !is_array($data['sports'][0]['leagues'][0]['teams'])
        ) {
                return $teamNames;
        }

        foreach ($data['sports'][0]['leagues'][0]['teams'] as $teamEntry) {
                if (!isset($teamEntry['team'])) {
                        continue;
                }

                $team = $teamEntry['team'];

                if (!isset($team['displayName']) || !isset($team['id'])) {
                        continue;
                }

                $teamNames[$team['displayName']] = $team['id'];
        }

        ksort($teamNames);

        $noTeam = array("No team" => "");
        unset($teamNames["No team"]);

        return $noTeam + $teamNames;
}

function getSequences(){
	$url = "http://127.0.0.1/api/sequence/";
	$options = array(
		'http' => array(
		'method'  => 'GET',
		)
	);
	$context = stream_context_create( $options );
	$result = file_get_contents( $url, false, $context );
	$sequences = json_decode($result, true);
	$sequenceList["No Sequence"]="";
	foreach ($sequences as $sequence) {		
        $sequenceList[$sequence]=$sequence;		
	}		
	return $sequenceList;
}

function getTeamInfo($sport, $league, $team){
        if ($league == "ncaa") {
                $league = "college-football";
        }

        $teamInfo = array(
                "logo" => "",
                "abbreviation" => "",
                "name" => "",
                "nextEventID" => "",
                "nextEventDate" => 0,
                "nextEventStatus" => "post"
        );

        if (empty($team)) {
                return $teamInfo;
        }

        $url = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$league}/teams/{$team}";

        $options = array(
                'http' => array(
                        'method'  => 'GET',
                        'timeout' => 10,
                        'header'  => "User-Agent: FPP-Pro-Sports-Scoring\r\n"
                )
        );

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
                return $teamInfo;
        }

        $data = json_decode($result, true);

        if (!is_array($data) || !isset($data['team']) || !is_array($data['team'])) {
                return $teamInfo;
        }

        $teamData = $data['team'];

        if (isset($teamData['logos'][0]['href'])) {
                $teamInfo["logo"] = $teamData['logos'][0]['href'];
        }

        if (isset($teamData['abbreviation'])) {
                $teamInfo["abbreviation"] = $teamData['abbreviation'];
        }

        if (isset($teamData['displayName'])) {
                $teamInfo["name"] = $teamData['displayName'];
        }

        if (isset($teamData['nextEvent'][0]) && is_array($teamData['nextEvent'][0])) {
                $nextEvent = $teamData['nextEvent'][0];

                if (isset($nextEvent['id'])) {
                        $teamInfo["nextEventID"] = $nextEvent['id'];
                }

                if (isset($nextEvent['date'])) {
                        $teamInfo["nextEventDate"] = $nextEvent['date'];
                }

                if (isset($nextEvent['competitions'][0]['status']['type']['state'])) {
                        $teamInfo["nextEventStatus"] =
                                $nextEvent['competitions'][0]['status']['type']['state'];
                }
        }

        return $teamInfo;
}

function updateTeam($sport, $league){
	logEntry("Updating {$league} Team and logo");
	global $pluginName;
	global $pluginSettings;

	//clear old scores
	WriteSettingToFile("{$league}MyScore",0,$pluginName);
	WriteSettingToFile("{$league}OppoScore",0,$pluginName);

	//configure variables
	if (strlen(urldecode($pluginSettings["{$league}TeamID"]))>0){
		$teamID=urldecode($pluginSettings["{$league}TeamID"]);
		$teamInfo = getTeamInfo($sport, $league, $teamID);
		$teamLogo = $teamInfo['logo'];
		$teamAbbreviation = $teamInfo['abbreviation'];
		$teamName = $teamInfo['name'];
		$teamNextEventID = $teamInfo['nextEventID'];
		$teamNextEventDate = $teamInfo['nextEventDate'];
	}else{
		$teamLogo = "";
		$teamAbbreviation = "";
		$teamName = "";
		$teamNextEventID = "";
		$teamNextEventDate = "";
	}
	WriteSettingToFile("{$league}TeamLogo",$teamLogo,$pluginName);
	WriteSettingToFile("{$league}TeamAbbreviation",$teamAbbreviation,$pluginName);
	WriteSettingToFile("{$league}TeamName",$teamName,$pluginName);
	WriteSettingToFile("{$league}TeamNextEventID",$teamNextEventID,$pluginName);
	WriteSettingToFile("{$league}Start",$teamNextEventDate,$pluginName);
	WriteSettingToFile("{$league}GameStatus","",$pluginName);

	logEntry("{$league} Logo updated " . $teamLogo);
	logEntry("{$league} Abbreviation updated " . $teamAbbreviation);
	logEntry("{$league} Name updated " . $teamName);
	logEntry("{$league} Next game updated " . $teamNextEventDate);
	if ($teamNextEventID != '') {
		updateTeamStatus(true);
	}
	return $teamLogo;

}

function getGameStatus($sport, $league, $gameID, $teamID) {
        if ($league == "ncaa") {
                $league = "college-football";
        }

        $gameStatus = array(
                "valid" => false,
                "start" => 0,
                "state" => "pre",
                "oppoID" => "",
                "oppoAbbreviation" => "",
                "oppoName" => "",
                "myScore" => 0,
                "oppoScore" => 0
        );

        if (empty($gameID) || empty($teamID)) {
                return $gameStatus;
        }

        $url = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$league}/summary?event={$gameID}";

        $options = array(
                'http' => array(
                        'method'  => 'GET',
                        'timeout' => 10,
                        'header'  => "User-Agent: FPP-Pro-Sports-Scoring\r\n"
                )
        );

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
                return $gameStatus;
        }

        $data = json_decode($result, true);

        if (!is_array($data)) {
                return $gameStatus;
        }

        if (
                !isset($data['header']['competitions'][0]) ||
                !is_array($data['header']['competitions'][0])
        ) {
                return $gameStatus;
        }

        $competition = $data['header']['competitions'][0];

        if (isset($competition['date'])) {
                $gameStatus['start'] = $competition['date'];
        }

        if (isset($competition['status']['type']['state'])) {
                $gameStatus['state'] = $competition['status']['type']['state'];
        }

        if (
                !isset($competition['competitors']) ||
                !is_array($competition['competitors'])
        ) {
                return $gameStatus;
        }

        $myTeam = null;
        $opponent = null;

        foreach ($competition['competitors'] as $competitor) {
                if (
                        !isset($competitor['team']['id']) ||
                        !isset($competitor['team'])
                ) {
                        continue;
                }

                if ((string)$competitor['team']['id'] === (string)$teamID) {
                        $myTeam = $competitor;
                } else {
                        $opponent = $competitor;
                }
        }

        if ($myTeam === null || $opponent === null) {
                return $gameStatus;
        }

        if (isset($opponent['team']['id'])) {
                $gameStatus['oppoID'] = $opponent['team']['id'];
        }

        if (isset($opponent['team']['abbreviation'])) {
                $gameStatus['oppoAbbreviation'] = $opponent['team']['abbreviation'];
        }

        if (isset($opponent['team']['displayName'])) {
                $gameStatus['oppoName'] = $opponent['team']['displayName'];
        }

        if (isset($myTeam['score'])) {
                $gameStatus['myScore'] = (int)$myTeam['score'];
        }

        if (isset($opponent['score'])) {
                $gameStatus['oppoScore'] = (int)$opponent['score'];
        }
        $gameStatus['valid'] = true;
        return $gameStatus;
}

function updateTeamStatus($reparseSettings=true){
	//initialize globals
	global $logFile;	
	global $pluginConfigFile;
	global $pluginName;
	global $pluginSettings; 
	global $leagues;

	//reparse settings file - needs to reread team group id on change
	if ($reparseSettings) {
		logEntry("Reparsing config file");
		if (file_exists($pluginConfigFile)) {
			$pluginSettings = parse_ini_file ($pluginConfigFile);
		  } else {	
			  $pluginSettings="";
			  logEntry("No pluginConfigFile");
		  }
	}

	//setup log level
	if (strlen(urldecode($pluginSettings['logLevel']))>0){
		$logLevel=urldecode($pluginSettings['logLevel']);
	}else{
		$logLevel=0;
	}
	
	//get active leagues
	foreach ($leagues as $league) {

		if (strlen(urldecode($pluginSettings["{$league}TeamID"]))>0){
			${$league . "TeamID"}=urldecode($pluginSettings["{$league}TeamID"]);
		} else {
			${$league . "TeamID"}="";
		}

		//initialize sleep times
		${$league . "SleepTime"} = 600;

	}
	$activeLeagues = array();
	if ($nflTeamID != '') {
		array_push($activeLeagues, 'nfl');
	}
	if ($ncaaTeamID != '') {
		array_push($activeLeagues, 'ncaa');
	}
	if ($nhlTeamID != '') {
		array_push($activeLeagues, 'nhl');
	}
	if ($mlbTeamID != '') {
		array_push($activeLeagues, 'mlb');
	}

	//cycle through each league
	foreach ($activeLeagues as $league) {
		if ($logLevel >= 5) {
			logEntry("Parsing league {$league}");	
		}
 
		if (strlen(urldecode($pluginSettings["{$league}GameStatus"]))>1){
			${$league . "GameStatus"}=urldecode($pluginSettings["{$league}GameStatus"]);
		} else {
			${$league . "GameStatus"}="";
		}
		if (strlen(urldecode($pluginSettings["{$league}TeamNextEventID"]))>1){
			${$league . "TeamNextEventID"}=urldecode($pluginSettings["{$league}TeamNextEventID"]);
		} else {
			${$league . "TeamNextEventID"}="";
		}
		if (strlen(urldecode($pluginSettings["{$league}Start"]))>1){
			${$league . "Start"}=urldecode($pluginSettings["{$league}Start"]);
		} else {
			${$league . "Start"}="";
		}
		if (strlen(urldecode($pluginSettings["{$league}MyScore"]))>0){
			${$league . "MyScore"}=urldecode($pluginSettings["{$league}MyScore"]);
		} else {
			${$league . "MyScore"}="0";
		}
		if (strlen(urldecode($pluginSettings["{$league}OppoScore"]))>0){
			${$league . "OppoScore"}=urldecode($pluginSettings["{$league}OppoScore"]);
		} else {
			${$league . "OppoScore"}="0";
		}
		if (strlen(urldecode($pluginSettings["{$league}OppoID"]))>0){
			${$league . "OppoID"}=urldecode($pluginSettings["{$league}OppoID"]);
		} else {
			${$league . "OppoID"}="";
		}
		if (strlen(urldecode($pluginSettings["{$league}WinSequence"]))>1){
			${$league . "WinSequence"}=urldecode($pluginSettings["{$league}WinSequence"]);
		} else {
			${$league . "WinSequence"}="";
		}
	
		if ($league == "nfl" || $league == "ncaa") {
	
			if (strlen(urldecode($pluginSettings["{$league}TouchdownSequence"]))>1){
				${$league . "TouchdownSequence"}=urldecode($pluginSettings["{$league}TouchdownSequence"]);
			} else {
				${$league . "TouchdownSequence"}="";
			}
			if (strlen(urldecode($pluginSettings["{$league}FieldgoalSequence"]))>1){
				${$league . "FieldgoalSequence"}=urldecode($pluginSettings["{$league}FieldgoalSequence"]);
			} else {
				${$league . "FieldgoalSequence"}="";
			}

			$sport = "football";
	
		} elseif ($league == "nhl" || $league == "mlb") {
	
			if (strlen(urldecode($pluginSettings["{$league}ScoreSequence"]))>1){
				${$league . "ScoreSequence"}=urldecode($pluginSettings["{$league}ScoreSequence"]);
			} else {
				${$league . "ScoreSequence"}="";
			}

			switch ($league) {
				case 'nhl' : $sport = "hockey";
					break; 
				case 'mlb' : $sport = "baseball";
			}
	
		}

		//run game checks based on prior game status
		switch (${$league . "GameStatus"}) {
			case "pre":
				if ($logLevel >= 5) {
					logEntry("{$league} Game Status is Pre");	
				}
				$now = new DateTime();
				$gameDate = new DateTime(${$league . "Start"});
				$timeToGame = $gameDate->getTimestamp() - $now->getTimestamp();
				if ($timeToGame < 1200) {
					if ($logLevel >= 5) {
						logEntry("{$league} Game is 20 min or less from gametime");	
					}
					${$league . "SleepTime"} = 30;
					//check game status
					$status = getGameStatus($sport, $league, ${$league . "TeamNextEventID"}, ${$league . "TeamID"});
					if ($status['state'] == "in") {
						logEntry("{$league} game is now playing.");
						WriteSettingToFile("{$league}GameStatus",$status['state'],$pluginName);
					}
				}

				break;

			case "post":
				if ($logLevel >= 5) {
					logEntry("{$league} Game Status is Post");	
				}
				//check for next game
				$newInfo = getTeamInfo($sport, $league, ${$league . "TeamID"});
				if ($newInfo['nextEventID'] != ${$league . "TeamNextEventID"}) {
					WriteSettingToFile("{$league}TeamNextEventID",$newInfo['nextEventID'],$pluginName);
					WriteSettingToFile("{$league}Start",$newInfo['nextEventDate'],$pluginName);
					WriteSettingToFile("{$league}GameStatus","",$pluginName);
					logEntry("{$league} Next game updated " . $newInfo['nextEventDate']);
					//clear old scores
					WriteSettingToFile("{$league}MyScore",0,$pluginName);
					WriteSettingToFile("{$league}OppoScore",0,$pluginName);
					updateTeamStatus(true);
				}

				${$league . "SleepTime"} = 600;

				break;

			default:

				//log polling
				if ($logLevel >= 5) {
					logEntry("{$league} Game Status is In or Not Set. Polling ESPN API");	
				}

				//get game status
				$status = getGameStatus($sport, $league, ${$league . "TeamNextEventID"}, ${$league . "TeamID"});

				if (!$status['valid']) {
					logEntry("{$league} ESPN game status request failed. Keeping existing game data.");
					${$league . "SleepTime"} = 30;
					continue 2;
				}

				// set opponent ID
				if (${$league . "OppoID"} != $status['oppoID']) {
					if ($logLevel >= 5) {
						logEntry("{$league} Opponent Updated to " . ${$league . "OppoID"} . " from {$status['oppoID']}");	
					}
					WriteSettingToFile("{$league}OppoID",$status['oppoID'],$pluginName);
					WriteSettingToFile("{$league}OppoName",$status['oppoName'],$pluginName);
					WriteSettingToFile("{$league}OppoAbbreviation",$status['oppoAbbreviation'],$pluginName);
				}

				//check score changes
				if ($sport == "football") {

					if (${$league . "MyScore"} + 6 == $status['myScore']) {
						//play touchdown sequence if set
						if (${$league . "TouchdownSequence"} != '') {
							insertPlaylistImmediate(${$league . "TouchdownSequence"});
							logEntry("{$league} Touchdown! Playing sequence.");					
						} else {
							logEntry("{$league} Touchdown Triggered but no sequence selected");
						}
					} elseif (${$league . "MyScore"} + 3 == $status['myScore']) {
						//play fieldgoal sequence if set
						if (${$league . "FieldgoalSequence"} != '') {
							insertPlaylistImmediate(${$league . "FieldgoalSequence"});
							logEntry("{$league} Fieldgoal! Playing sequence.");					
						} else {
							logEntry("{$league} Fieldgoal Triggered but no sequence selected");
						}
					}

				} elseif ($sport == "hockey" || $sport == "baseball") {
					if (${$league . "MyScore"} < $status['myScore']) {
						//play score sequence if set
						if (${$league . "ScoreSequence"} != '') {
							insertPlaylistImmediate(${$league . "ScoreSequence"});
							logEntry("{$league} Score! Playing sequence.");					
						} else {
							logEntry("{$league} Score Triggered but no sequence selected");
						}
					}	
				}

				//update stored scores
				if (${$league . "MyScore"} != $status['myScore']) {
					WriteSettingToFile("{$league}MyScore",$status['myScore'],$pluginName);
					logEntry("Updating {$league} MyScore to " . $status['myScore']);
				}
				if (${$league . "OppoScore"} != $status['oppoScore']) {
					WriteSettingToFile("{$league}OppoScore",$status['oppoScore'],$pluginName);
					logEntry("Updating {$league} OppoScore to " . $status['oppoScore']);
				}

				//update sleep timer
				switch ($status['state']){
					case "in":
										
						${$league . "SleepTime"} = 5;
						if (${$league . "GameStatus"} != "in") {
							WriteSettingToFile("{$league}GameStatus",$status['state'],$pluginName);
							if ($logLevel >= 5) {
								logEntry("{$league} Game Status updating to in");	
							}	
						}
						break;
					case "post":
						if ($logLevel >= 5) {
							logEntry("{$league} Game Status updating to post");	
						}
						if ($status['myScore'] > $status['oppoScore']) {
							if (${$league . "WinSequence"} != '') {
								insertPlaylistImmediate(${$league . "WinSequence"});
								logEntry("Your {$league} team won! Playing sequence.");								
							} else {
								logEntry("Your {$league} team won but no sequence selected");
							}
						}
						WriteSettingToFile("{$league}GameStatus",$status['state'],$pluginName);
						${$league . "SleepTime"} = 600;
						break;
					default:
						if ($logLevel >= 5) {
							logEntry("{$league} Game Status updating to {$status['state']}");	
						}
						WriteSettingToFile("{$league}GameStatus",$status['state'],$pluginName);
						${$league . "SleepTime"} = 600;					
				}

		}
	
	}

	return min($nflSleepTime, $ncaaSleepTime, $nhlSleepTime, $mlbSleepTime);
}
	
function insertPlaylistImmediate($playlist) {
  $playlist .= '.fseq';
  $playlist = rawurlencode($playlist);
  $url = "http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/" . $playlist . "/0/0";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
}

function logEntry($data) {

	global $logFile,$myPid;

	$data = $_SERVER['PHP_SELF']." : [".$myPid."] ".$data;
	
	$logWrite= fopen($logFile, "a") or die("Unable to open file!");
	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}
	
?>
