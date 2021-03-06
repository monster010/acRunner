<?php
/*~ ScoreKeeper.php
.-----------------------------------------------------------------------------.
|    Software: ScoreKeeper - keeps score for acRunner                         |
|     Version: 1.1                                                            |
|     Contact: via irc on irc.gamesurge.net as YMH|Fiz or just Fiz            |
| IRC Support: #acRunner @ irc.gamesurge.net                                  |
| --------------------------------------------------------------------------- |
|    Author: Marc Seiler (project admininistrator)                            |
| Copyright (c) 20010, Marc Seiler. All Rights Reserved.                      |
| --------------------------------------------------------------------------- |
|   License: Distributed under the Lesser General Public License (LGPL)       |
|            http://www.gnu.org/copyleft/lesser.html                          |
| This program is distributed in the hope that it will be useful - WITHOUT    |
| ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or       |
| FITNESS FOR A PARTICULAR PURPOSE.                                           |
| --------------------------------------------------------------------------- |
| I offer a number of paid services (digital-focus.us):                       |
| - Web Hosting on highly optimized fast and secure servers                   |
| - Technology Consulting                                                     |
| - Oursourcing (highly qualified programmers and graphic designers)          |
'-----------------------------------------------------------------------------'
 */


$this->sk = new ScoreKeeper();

add_hook('init', array($this->sk, 'init'));
add_hook('buffer_loop', array($this->sk, 'process'));





/**
 * This is the ScoreKeeper class that will be responsible for manipulating the database to keep track of scores. Both realtime and long running.
 * @package acRunner
 * @author Marc Seiler
 */
class ScoreKeeper
{
	public $dbprefix;
	public $thismonth;
	/**
	* This is the constructor. Nothing more... nothing less...
	*
	* @access public
	*/
	function __construct()
	{
		// shouldnt really do anything here yet
	}
	
	
	function init()
	{
		

		$this->thismonth = strtotime("1 ".date("F")." ".date("Y"));

		// Check for table and if it doesnt exist make it
		self::checkForDatabase();

		// Now lets empty the logs. Comment this out if you dont want logs cleared everytime the script is started.
		Database::query("delete from `".Database::dbprefix()."logs`");
		Database::query("delete from `".Database::dbprefix()."current_game`");
		
		$this->setOption("last_started", time());
		$this->setOption("current_version", VERSION);
		
		
	}


	/**
	* Checks the database for needed tables
	*
	* @access public
	*/
	public function checkForDatabase()
	{
		// Creating a function/method for creating a database is probably overkill but
		// I did it for easy of use and extensibility. All your checking for proper database
		// configuration can be done in here now.
		
		outputLog("Checking databases and makign sure they exists...");

		// general log file, just used for now to examine logs
		Database::query("CREATE TABLE IF NOT EXISTS `".Database::dbprefix()."logs` (`id` bigint(11) NOT NULL AUTO_INCREMENT, `sid` int(11) NOT NULL, `log` longtext NOT NULL, `time` int(11) NOT NULL, PRIMARY KEY (`id`)) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;");


		// Realtime game stats
		Database::query("CREATE TABLE IF NOT EXISTS `".Database::dbprefix()."current_game` (`cn` INT NOT NULL, `player` VARCHAR(15) NOT NULL, `team` VARCHAR(4) NOT NULL, `flags` INT NOT NULL, `score` INT NOT NULL, `frags` INT NOT NULL, `deaths` INT NOT NULL, `tks` INT NOT NULL, `ping` INT NOT NULL, `role` VARCHAR(6) NOT NULL, `host` VARCHAR(50) NOT NULL, `active` int(11) NOT NULL DEFAULT '1') ENGINE = MyISAM;");


		Database::query("CREATE TABLE IF NOT EXISTS `".Database::dbprefix()."options` (`id` INT NOT NULL, `name` VARCHAR(20) NOT NULL, `value` VARCHAR(255) NOT NULL) ENGINE = MyISAM;");
		self::setOption("current_map", "");
		self::setOption("current_mode", "");

		// Long run stats table
		Database::query("CREATE TABLE IF NOT EXISTS `".Database::dbprefix()."player_stats` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `player` VARCHAR(15) NOT NULL, `frags` INT NOT NULL, `slashes` INT NOT NULL, `headshots` INT NOT NULL, `splatters` INT NOT NULL, `gibs` INT NOT NULL, `flags` INT NOT NULL, `tks` INT NOT NULL, `suicides` INT NOT NULL, `deaths` INT NOT NULL, `month` INT NOT NULL) ENGINE = MyISAM;");

		// Long run stats table archive
		Database::query("CREATE TABLE IF NOT EXISTS `".Database::dbprefix()."player_stats_archive` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `player` VARCHAR(15) NOT NULL, `frags` INT NOT NULL, `slashes` INT NOT NULL, `headshots` INT NOT NULL, `splatters` INT NOT NULL, `gibs` INT NOT NULL, `flags` INT NOT NULL, `tks` INT NOT NULL, `suicides` INT NOT NULL, `deaths` INT NOT NULL, `month` INT NOT NULL) ENGINE = MyISAM;");

		// event log
		Database::query("CREATE TABLE IF NOT EXISTS `".Database::dbprefix()."event_log` (`id` INT NOT NULL, `type` VARCHAR(20) NOT NULL, `subject` VARCHAR(255) NOT NULL, `action` VARCHAR(255) NOT NULL, `ip` VARCHAR(12) NOT NULL, `time` INT NOT NULL) ENGINE = MyISAM;");


		
		outputLog("Optimizing databases...");
		// lets optimize the tables so the script stays running good!
		Database::query("OPTIMIZE TABLE `".Database::dbprefix()."event_log`, `".Database::dbprefix()."current_game`, `".Database::dbprefix()."logs`, `".Database::dbprefix()."options`, `".Database::dbprefix()."player_stats`");


		
		outputLog("Archiving old player stats...");
		// Now lets more old stats to the archive because we only want to deal with this months for performance reasons
		Database::query("INSERT INTO `".Database::dbprefix()."player_stats_archive` SELECT * from `".Database::dbprefix()."player_stats` where `month` <> '".$this->thismonth."' && `frags` > 100");
		// Then clean it out
		Database::query("delete from `".Database::dbprefix()."player_stats` where `month` <> '".$this->thismonth."'");

	}
	
	function logEvent($type, $subject, $action, $ip = '')
	{
		return Database::logEvent($type, $subject, $action, $ip);
	}


	/**
	* Options function for setting generic functions like current_game and such for later usage
	*
	* @param	string $option			option to set
	* @param	string $value			value to set the option
	*
	* @access public
	*/
	public function setOption($option, $value)
	{
		// lets check if it exists already
		$result = Database::query("select * from `".Database::dbprefix()."options` where `name` = '".$option."'");
		$res = mysql_fetch_assoc($result);

		// if its blank we insert it
		if($res['name'] == '') { Database::query("insert into `".Database::dbprefix()."options` set `name` = '".$option."', `value` = '".$value."'"); }

		// if its not blank we update it
		if($res['name'] != '') { Database::query("update `".Database::dbprefix()."options` set `value` = '".$value."' where `name` = '".$option."'"); }
	}


	/**
	* Function to clean empty entries from an arrya
	*
	* @param	string $array			array to clean up
	*
	* @access public
	*/
	public function cleanArray($array)
	{
		foreach($array as $k=>$a)
		{
			if($a != "") { $new[$k] = $a; }
		}
		return $new;
	}


	/**
	* Function to clean out older chat logs. About an hour is fine no?
	*
	* @access public
	*/
	public function cleanChat()
	{
		// Now lets keep the chat cleaned up. Anything older then an hour is emptied out.
		$time = time() - (60 * 60);
		Database::query("delete from `".Database::dbprefix()."event_log` where `time` < ".$time."");
	}

	/**
	* Processes the log line given
	*
	* @param	string $log			the log line to process
	*
	* @access public
	*/
	public function cleanString($string)
	{
		return Database::cleanString($string);
	}


	
	/**
	* Processes the log line given
	*
	* @param	string $log			the log line to process
	*
	* @access public
	*/
	public function process($log)
	{

		// Catch Connections
		if(preg_match("/logged in \(/i", $log) || preg_match("/logged in using/i", $log))
		{
			$e = explode("]", substr($log, 1), 2);
			$ip = $e[0];
			$e = explode("logged in", $e[1], 2);
			$name = trim($e[0]);



			// Lets check if they've already been playing this game
			$result = Database::query("select * from `".Database::dbprefix()."current_game` where `player` = '".self::cleanString($name)."'");
			$res = mysql_fetch_assoc($result);

			// If blank then new player
			if($res['player'] == '')
			{
				Database::query("insert into `".Database::dbprefix()."current_game` set `player` = '".self::cleanString($name)."', `host` = '".self::cleanString($ip)."', `role` = 'normal'");
			}
			// if not blank its a returning player
			if($res['player'] != '')
			{
				Database::query("update `".Database::dbprefix()."current_game` set `active` = '1', `host` = '".self::cleanString($ip)."' where `player` = '".self::cleanString($name)."'");
			}

			// Now lets do the same for the player stats table
			$result = Database::query("select * from `".Database::dbprefix()."player_stats` where `player` = '".self::cleanString($name)."'");
			$res = mysql_fetch_assoc($result);
			if($res['player'] == '') { Database::query("insert into `".Database::dbprefix()."player_stats` set `player` = '".$name."', `month` = '".$this->thismonth."'"); }
			self::logEvent('CON', $name, 'connecting', $ip);

		}
		// Catch Disconnects
		if(preg_match("/disconnected client/i", $log))
		{
			$e = explode("] disconnected client", substr($log, 1), 2);
			$ip = $e[0];
			$e = explode(" cn ", $e[1], 2);
			$name = trim($e[0]);
			
			Database::query("update `".Database::dbprefix()."current_game` set `active` = '0' where `player` = '".self::cleanString($name)."'");
			self::logEvent('DIS', $name, 'disconnected', $ip);
		}

		// Catch disconnecting cuz they where kicked for some reason
		if(preg_match("/disconnecting client/i", $log))
		{
			$e = explode("] disconnecting client", substr($log, 1), 2);
			$ip = $e[0];
			$e = explode(" ", trim($e[1]), 2);
			$name = $e[0];
			$reason_junk = explode(") cn", substr(trim($e[1]), 1));
			$reason = $reason_junk[0];

			
			Database::query("update `".Database::dbprefix()."current_game` set `active` = '0' where `player` = '".self::cleanString($name)."'");
			self::logEvent('DISS', $name, $reason, $ip);
		}


		// Catch Name changes
		if(preg_match("/^\[(.*?)\] (.*?) changed name to (.*?)$/i", $log, $m))
		{
			$ip = $m[1];
			$from = $m[2];
			$to = $m[3];

			Database::query("update `".Database::dbprefix()."current_game` set `player` = '".self::cleanString($to)."' where `player` = '".self::cleanString($from)."' && `host` = '".self::cleanString($ip)."'");
			
			// Insert name change in the log
			self::logEvent('RN', $from, $to, $ip);
		}

		// Catch admins
		if(preg_match("/set role of player (.*?) to admin/i", $log, $m))
		{
			Database::query("update `".Database::dbprefix()."current_game` set `role` = 'normal'");
			Database::query("update `".Database::dbprefix()."current_game` set `role` = 'admin' where `player` = '".self::cleanString($m[1])."'");
			
			// Insert admin claim in the log
			self::logEvent('CA', $m[1], 'admin');
		}
		if(preg_match("/set role of player (.*?) to normal player/i", $log, $m))
		{
			Database::query("update `".Database::dbprefix()."current_game` set `role` = 'normal' where `player` = '".self::cleanString($m[1])."'");
		}
		
		// Catch Votes
		if(preg_match("/^\[(.*?)\] client (.*?) called a vote: (.*?)$/", $log, $m))
		{
			$ip = $m[1];
			$player = $m[2];
			$vote = $m[3];
			
			// Insert called vote in the log
			self::logEvent('VOTE', $player, $vote, $ip);
		}


		// Catch New Games
		if(preg_match("/^Game start: (.*?)$/", $log, $m))
		{
			// Get Mode
			list($mode, $rest) = explode(" on ", $m[1], 2);
			self::setOption("current_mode", $mode);
			
			$e = explode(",", $rest);
			
			// Set Map
			$map = trim($e[0]);
			self::setOption("current_map", $map);
			
			// Since its a new game we clear scores
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = 0");
			Database::query("update `".Database::dbprefix()."current_game` set `score` = 0");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = 0");
			Database::query("update `".Database::dbprefix()."current_game` set `tks` = 0");

			// Insert new game in the log
			self::logEvent('NG', $mode, $map);
			
			// Also for cleanliness lets clear out inactive players.
			Database::query("delete from `".Database::dbprefix()."current_game` where `active` = 0");


			// We do this to make sure that it gets set. had some instances where if a game started over 
			//right at the new round part it would stay in that mode
			$this->newround = FALSE;
		}


		// Catch New Round
		if(preg_match("/^Game status: (.*?)$/", $log, $m)) { $this->newround = TRUE; }
		// unset new round crap
		if(preg_match("/Team  CLA:/", $log, $m) && $this->newround == TRUE) { $this->newround = FALSE; }


		if($this->newround == TRUE)
		{
			$ex = explode(" ", $log);
			// Now lets clean the empty spaces, very important!
			$ex = self::cleanArray($ex);

			$number = array_shift($ex);
			$name = array_shift($ex);
			$ping = $ex[(count($ex) - 3)];

			// This was added to gurentee we dont get weird numbers.
			switch(array_shift($ex))
			{
				case('SPEC'): $team = "SPEC"; break;
				case('RVSF'): $team = "RVSF"; break;
				case('CLA'): $team = "CLA"; break;
				default: $team = "PLAYER"; break;
			}

			// if this is a number it must be their cn
			if(is_numeric($number)) { Database::query("update `".Database::dbprefix()."current_game` set `cn` = '".self::cleanString($number)."', `ping` = '".self::cleanString($ping)."', `team` = '".self::cleanString($team)."' where `player` = '".self::cleanString($name)."'"); }



		}


		//********************************************************************************//
		//********************************************************************************//
		//
		//					Scoring Below
		//
		//********************************************************************************//
		//********************************************************************************//


		// Catch suicides
		if(preg_match("/^\[(.*?)\] (.*?) suicided$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`-1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `suicides` = `suicides`+1 where `player` = '".self::cleanString($m[2])."'");
		}


		//
		// None Teammate kills
		//

		// Catch Frags
		if(preg_match("/^\[(.*?)\] (.*?) fragged (.*?)$/", $log, $m) && !preg_match("/fragged teammate/", $log))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `frags` = `frags`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."player_stats` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
		}
		// Catch slashes
		if(preg_match("/^\[(.*?)\] (.*?) slashed (.*?)$/", $log, $m) && !preg_match("/slashed teammate/", $log))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`+2 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `slashes` = `slashes`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."player_stats` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
		}
		// Catch headshots
		if(preg_match("/^\[(.*?)\] (.*?) headshot (.*?)$/", $log, $m) && !preg_match("/headshot teammate/", $log))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`+2 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `headshots` = `headshots`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."player_stats` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
		}
		// Catch splatters
		if(preg_match("/^\[(.*?)\] (.*?) splattered (.*?)$/", $log, $m) && !preg_match("/splattered teammate/", $log))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `splatters` = `splatters`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."player_stats` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
		}
		// Catch gibs
		if(preg_match("/^\[(.*?)\] (.*?) gibbed (.*?)$/", $log, $m) && !preg_match("/gibbed teammate/", $log))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `gibs` = `gibs`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."player_stats` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
		}



		//
		// Teammate kills
		//

		// Catch Frags
		if(preg_match("/^\[(.*?)\] (.*?) fragged his teammate (.*?)$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`-1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");


			// Insert tk into log
			self::logEvent('TK_FRAG', $m[2], $m[3], $m[1]);
		}
		// Catch slashes
		if(preg_match("/^\[(.*?)\] (.*?) slashed his teammate (.*?)$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`-1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");


			// Insert tk into log
			self::logEvent('TK_SLASH', $m[2], $m[3], $m[1]);
		}
		// Catch headshots
		if(preg_match("/^\[(.*?)\] (.*?) headshot his teammate (.*?)$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`-1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");


			// Insert tk into log
			self::logEvent('TK_HS', $m[2], $m[3], $m[1]);
		}
		// Catch splatters
		if(preg_match("/^\[(.*?)\] (.*?) splattered his teammate (.*?)$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");


			// Insert tk into log
			self::logEvent('TK_SPLAT', $m[2], $m[3], $m[1]);
		}
		// Catch gibs
		if(preg_match("/^\[(.*?)\] (.*?) gibbed his teammate (.*?)$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `frags` = `frags`+1 where `player` = '".self::cleanString($m[2])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `deaths` = `deaths`+1 where `player` = '".self::cleanString($m[3])."'");
			Database::query("update `".Database::dbprefix()."current_game` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `tks` = `tks`+1 where `player` = '".self::cleanString($m[2])."'");


			// Insert tk into log
			self::logEvent('TK_GIB', $m[2], $m[3], $m[1]);
		}

		
		//************************ FLAGS **********************************//

		// Catch KTF Scores
		if(preg_match("/^\[(.*?)\] (.*?) scored, carrying for (.*?) seconds, new score (.*?)$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `flags` = `flags`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `flags` = `flags`+1 where `player` = '".self::cleanString($m[2])."'");
		}

		// Catch CTF Scores
		if(preg_match("/^\[(.*?)\] (.*?) scored with the flag for (.*?), new score(.*?)$/", $log, $m))
		{
			// Realtime stuff
			Database::query("update `".Database::dbprefix()."current_game` set `flags` = `flags`+1 where `player` = '".self::cleanString($m[2])."'");

			// Long Running Stats stuff
			Database::query("update `".Database::dbprefix()."player_stats` set `flags` = `flags`+1 where `player` = '".self::cleanString($m[2])."'");
		}


		
		//************************ SPEECH **********************************//

		// Catch speech to all
		if(preg_match("/^\[(.*?)\] (.*?) says\: \'(.*?)\'$/", $log, $m))
		{
			self::logEvent('SPEAK', $m[2], $m[3], $m[1]);
			self::cleanChat();
		}
		// Catch speech to RVSF
		if(preg_match("/^\[(.*?)\] (.*?) says to team RVSF\: \'(.*?)\'$/", $log, $m))
		{
			self::logEvent('SPEAK_RVSF', $m[2], $m[3], $m[1]);
			self::cleanChat();
		}
		// Catch speech to CLA
		if(preg_match("/^\[(.*?)\] (.*?) says to team CLA\: \'(.*?)\'$/", $log, $m))
		{
			self::logEvent('SPEAK_CLA', $m[2], $m[3], $m[1]);
			self::cleanChat();
		}



	}
}

?>