<?php

$twitch_config = [
	'address'	=> 'irc.chat.twitch.tv',
	'port'		=> 6667,
	'username'	=> 'TwitchUsername',
	'password'	=> 'oauth:???',
];

$twitch_channels = [
	$twitch_config['username'],
];


$pudl_config = [
    'type'      => 'sqlite',
    'server'    => 'sqlite.db',
];

