<?php

/*
TODO: some (not all) unicode characaters are breaking local console
filter them out
*/

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();


if (file_exists('config.php')) {
	require_once('config.php');
} else {
	require_once('config.example.php');
}

require_once('altaform-core/core/afCli.inc.php');
require_once('pudl/pudl.php');


$pudl = pudl::instance($pudl_config);


// CREATE DATABASE TABLES IF THEY DON'T ALREADY EXIST
$pudl('CREATE TABLE IF NOT EXISTS `users` (`user` INTEGER PRIMARY KEY, `uname` TEXT UNIQUE)');
$pudl('CREATE TABLE IF NOT EXISTS `channels` (`channel` INTEGER PRIMARY KEY, `cname` TEXT UNIQUE)');
$pudl('CREATE TABLE IF NOT EXISTS `chatlog` (`log` INTEGER PRIMARY KEY, `user` INT NOT NULL, `channel` INT NOT NULL, `timestamp` INT NOT NULL, `chat` TEXT)');


// SEND DATA TO TWITCH
function twitch_send($buffer) {
	global $socket;
	$buffer = trim($buffer);
//	echo afCli::fgCyan($buffer) . "\n";
	return socket_write($socket, $buffer."\n", strlen($buffer)+1);
}


// CREATE SOCKET
if (($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
	echo	"socket_create() failed: reason: "
			. socket_strerror(socket_last_error())
			. "\n";
	exit(1);
}


// CONNECT SOCKET TO TWITCH
if (socket_connect($socket, $twitch_config['address'], $twitch_config['port']) === false) {
	echo	  "socket_connect() failed: reason: "
			. socket_strerror(socket_last_error($socket))
			. "\n";
	exit(1);
}


// SEND USERNAME AND PASSWORD INFORMATION
twitch_send('PASS ' . $twitch_config['password']);
twitch_send('NICK ' . strtolower($twitch_config['username']));


// JOIN CHANNELS
foreach ($twitch_channels as $channel) {
	twitch_send('JOIN #' . strtolower($channel));
}


// PROCESS A COMMAND FROM TWITCH
function twitch_command($pudl, $command) {
	echo afCli::fgYellow($command) . "\n";
	$parts = explode(' ', $command, 4);
	if (strtoupper($parts[0]) === 'PING') {
		if (count($parts) > 1) {
			twitch_send('PONG ' . $parts[1]);
		} else {
			twitch_send('PONG');
		}
	}

	if (count($parts) > 3  &&  $parts[1] === 'PRIVMSG') {
		chat($pudl, $parts);
	}
}



// LOG A CHAT MESSAGE INTO THE DATABASE
function chat($pudl, $parts) {
	static $channels	= [];
	static $users		= [];

	$pos		= strpos($parts[0], '!');
	if ($pos === false) return false;

	$user		= substr($parts[0], 1, $pos-1);
	$channel	= substr($parts[2], 1);
	$ret		= NULL;


	// PULL USER ID FROM DATABASE, OR INSERT IT IF IT DOESNT ALREADY EXIST
	if (empty($users[$user])) {
		$users[$user] = $pudl->cellId('users', 'user', 'uname', $user);
		if (empty($users[$user])) {
			$pudl->upsert('users', ['uname' => $user]);
			$users[$user] = $pudl->cellId('users', 'user', 'uname', $user);
		}
	}


	// PULL CHANNEL ID FROM DATABASE, OR INSERT IT IF IT DOESNT ALREADY EXIST
	if (empty($channels[$channel])) {
		$channels[$channel] = $pudl->cellId('channels', 'channel', 'cname', $channel);
		if (empty($channels[$channel])) {
			$pudl->upsert('channels', ['cname' => $channel]);
			$channels[$channel] = $pudl->cellId('channels', 'channel', 'cname', $channel);
		}
	}


	// INSERT CHAT MESSAGE INTO DATABASE
	return $pudl->insert('chatlog', [
		'user'		=> $users[$user],
		'channel'	=> $channels[$channel],
		'timestamp'	=> time(),
		'chat'		=> substr($parts[3], 1),
	]);
}


// ENABLE NON-BLOCKING I/O
stream_set_blocking(STDIN, false);
socket_set_nonblock($socket);


// INITIALIZE STATE
$buffer	= '';
$pudl->begin();


while (true) {

	// READ FROM SERVER, PROCESS DATA
	if (socket_recv($socket, $out, 2048, MSG_DONTWAIT)) {
		$buffer .= $out;
		while (($pos = strpos($buffer, "\n")) !== false) {
			try {
				twitch_command($pudl, trim(substr($buffer, 0, $pos)));
			} catch (pudlException $e) {
				var_dump($e);
			}
			$buffer = substr($buffer, $pos+1);
		}
	}

	// READ FROM LOCAL CONSOLE, SEND TYPED COMMAND TO SERVER
	if ($input = fread(STDIN, 2048)) {
		if (in_array(strtoupper(trim($input)), ['EXIT', 'QUIT'])) {
			break;
		}
		twitch_send($input);
	}


	// COMMIT CHUNKS EITHER EVERY 1000 INSERTS OR 5 SECONDS (WHICH EVER IS FIRST)
	$pudl->chunk(1000, false, 5);

	//TODO: REPLACE THIS WITH THE STREAM WAIT FUNCTION WITH BOTH STDIN AND $SOCKET IN READ[]
	usleep(10000);
}


// CLEANUP
socket_close($socket);
$pudl->commit();
$pudl->disconnect();
